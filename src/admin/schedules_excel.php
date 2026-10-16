<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SimpleXlsx.php';
requireAdmin();

$db = Database::getInstance();

const SHEET_NAME       = '日程';
const SLOT_STARTS      = ['10:00', '13:00', '14:45', '15:00']; // schedules.php の時間コマと同じ。終了は開始の90分後
const SLOT_MINUTES     = 90;
const TEMPLATE_ROWS    = 500;   // 入力欄（選択式の設定）を用意する最終行
const MAX_DATA_ROWS    = 1500;
const MAX_ERRORS_SHOWN = 30;
const WEEKDAYS         = ['日', '月', '火', '水', '木', '金', '土'];

// ───────────────────────── 共通の部品 ─────────────────────────

function xlSerial(string $ymd): int
{
    $d = new DateTime($ymd . ' 00:00:00', new DateTimeZone('UTC'));
    return intdiv($d->getTimestamp(), 86400) + 25569;
}

function slotLabel(string $start, string $end): string
{
    return substr($start, 0, 5) . '〜' . substr($end, 0, 5);
}

function jpDate(string $ymd): string
{
    $t = strtotime($ymd);
    return date('n/j', $t) . '(' . WEEKDAYS[(int)date('w', $t)] . ')';
}

function cellText($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_float($v)) {
        $v = floor($v) == $v ? (string)(int)$v : (string)$v;
    }
    return trim(mb_convert_kana((string)$v, 's'));
}

function loadExisting(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare('
        SELECT s.id, s.date, s.location_id, s.venue_location_id, s.start_time, s.end_time,
               s.required_staff_count, s.note, l.name AS location_name, l.sort_order,
               (SELECT COUNT(*) FROM shift_assignments sa WHERE sa.schedule_id = s.id) AS assigned,
               (SELECT COUNT(*) FROM shift_requests sr WHERE sr.schedule_id = s.id) AS requests
        FROM schedules s
        JOIN locations l ON s.location_id = l.id
        WHERE s.date >= ? AND s.date < ?
        ORDER BY s.date, l.sort_order, s.start_time, s.id
    ');
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['key'] = $r['date'] . '|' . $r['location_id'] . '|' . substr($r['start_time'], 0, 5);
        $r['venue_location_id'] = $r['venue_location_id'] !== null ? (int)$r['venue_location_id'] : null;
        $r['required_staff_count'] = (int)$r['required_staff_count'];
        $r['assigned'] = (int)$r['assigned'];
        $r['requests'] = (int)$r['requests'];
    }
    unset($r);
    return $rows;
}

// ───────────────────────── Excelの書き出し ─────────────────────────

function listFormula(array $items): string
{
    return '"' . implode(',', $items) . '"';
}

function buildWorkbook(array $existing, DateTime $month, array $activeNames, array $venueNames, array $venueNameById): string
{
    $rows = [['日付', '教室', '時間コマ', '必要人数', '会場（体験のみ）', '備考']];
    foreach ($existing as $e) {
        $rows[] = [
            ['v' => xlSerial($e['date']), 's' => 2],
            $e['location_name'],
            slotLabel($e['start_time'], $e['end_time']),
            ['v' => $e['required_staff_count'], 's' => 3],
            $e['venue_location_id'] ? ($venueNameById[$e['venue_location_id']] ?? '') : '',
            $e['note'] ?? '',
        ];
    }
    $last = max(TEMPLATE_ROWS, count($rows) + 100);
    $first = xlSerial($month->format('Y-m-01'));
    $lastDay = xlSerial($month->format('Y-m-t'));

    $slots = [];
    foreach (SLOT_STARTS as $s) {
        $end = date('H:i', strtotime("2000-01-01 $s") + SLOT_MINUTES * 60);
        $slots[] = $s . '〜' . $end;
    }

    $data = [
        'name' => SHEET_NAME,
        'widths' => [20, 16, 16, 10, 18, 36],
        'colStyles' => [1 => 2, 4 => 3],
        'freezeHeader' => true,
        'rows' => $rows,
        'validations' => [
            ['type' => 'date', 'operator' => 'between', 'sqref' => "A2:A$last", 'formula1' => (string)$first, 'formula2' => (string)$lastDay,
             'errorTitle' => '日付', 'error' => $month->format('Y年n月') . 'の日付を入力してください。',
             'promptTitle' => '日付', 'prompt' => $month->format('Y年n月') . 'の日付（例: ' . $month->format('Y/n/') . '5）'],
            ['type' => 'list', 'sqref' => "B2:B$last", 'formula1' => listFormula($activeNames),
             'errorTitle' => '教室', 'error' => '一覧（▼）から選んでください。'],
            ['type' => 'list', 'sqref' => "C2:C$last", 'formula1' => listFormula($slots),
             'errorTitle' => '時間コマ', 'error' => '一覧（▼）から選んでください。'],
            ['type' => 'whole', 'operator' => 'between', 'sqref' => "D2:D$last", 'formula1' => '1', 'formula2' => '10',
             'errorTitle' => '必要人数', 'error' => '1〜10の数字を入力してください。'],
            ['type' => 'list', 'sqref' => "E2:E$last", 'formula1' => listFormula($venueNames),
             'errorTitle' => '会場', 'error' => '一覧（▼）から選んでください。'],
        ],
    ];

    $help = [
        ['使い方'],
        ['① 「日程」シートが、この月の日程のすべてです。1行が1コマです。'],
        ['② 追加するときは空いている行に入力します。日付・教室・時間コマは▼の一覧から選びます（必須）。'],
        ['③ 不要なコマは、その行の内容を消して空白にします。保存すると、その日程は削除されます。'],
        ['④ 保存（読み込み）すると、この月の日程は、このExcelの内容に置き換わります。読み込み前に確認画面が出ます。'],
        ['⑤ 確定済みの担当講師がいるコマは削除できません。先に「講師シフト割り当て」で担当を外してください。'],
        ['⑥ 「会場」は教室が「体験」の行だけ選べます。必要人数を空にすると1名になります。'],
        ['⑦ 見出しの行（1行目）は変更しないでください。'],
    ];
    $helpRows = [];
    foreach ($help as $i => $h) {
        $helpRows[] = [['v' => $h[0], 's' => $i === 0 ? 1 : 4]];
    }

    return SimpleXlsx::write([
        $data,
        ['name' => '使い方', 'widths' => [100], 'rows' => $helpRows],
    ]);
}

// ───────────────────────── Excelの読み込み ─────────────────────────

function parseDateCell($v, DateTime $month): ?string
{
    if (is_float($v)) {
        if ($v < 61 || $v > 80000) {
            return null;
        }
        return gmdate('Y-m-d', (int)round(($v - 25569) * 86400));
    }
    $s = cellText($v);
    $s = mb_convert_kana($s, 'a');
    if (preg_match('/^(\d{4})[-\/.年](\d{1,2})[-\/.月](\d{1,2})日?(?:\(.*\))?$/u', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/月](\d{1,2})日?(?:\(.*\))?$/u', $s, $m)) {
        [$y, $mo, $d] = [(int)$month->format('Y'), (int)$m[1], (int)$m[2]];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** @return array{0:string,1:string}|null [開始 HH:MM:00, 終了 HH:MM:00] */
function parseSlotCell($v): ?array
{
    $fmt = fn(int $min) => sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
    if (is_float($v)) {
        if ($v <= 0 || $v >= 1) {
            return null;
        }
        $start = (int)round($v * 1440);
        return [$fmt($start), $fmt($start + SLOT_MINUTES)];
    }
    $s = mb_convert_kana(cellText($v), 'a');
    $s = str_replace(['〜', '～', '~', '–', '—', 'ー', '−', '-'], '~', $s);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?:\s*~\s*(\d{1,2}):(\d{2}))?$/', $s, $m)) {
        return null;
    }
    $start = (int)$m[1] * 60 + (int)$m[2];
    $end = isset($m[3]) ? (int)$m[3] * 60 + (int)$m[4] : $start + SLOT_MINUTES;
    if ((int)$m[1] > 23 || (int)$m[2] > 59 || $end <= $start || $end >= 24 * 60) {
        return null;
    }
    return [$fmt($start), $fmt($end)];
}

/** @return array{0:array,1:array} [読み取った行, エラー] */
function parseSheet(array $sheet, DateTime $month, array $locByName, array $venueByName, ?int $trialId): array
{
    $head = $sheet[1] ?? [];
    if (cellText($head[0] ?? null) !== '日付' || cellText($head[1] ?? null) !== '教室' || cellText($head[2] ?? null) !== '時間コマ') {
        return [[], ['ダウンロードしたExcelの表ではありません。「日程」シートの1行目（日付・教室・時間コマ）は変更しないでください。']];
    }

    $from = $month->format('Y-m-01');
    $to = $month->format('Y-m-t');
    $rows = [];
    $errors = [];
    $seen = [];
    $count = 0;

    foreach ($sheet as $rn => $cells) {
        if ($rn === 1) {
            continue;
        }
        $vDate = $cells[0] ?? null;
        $vLoc = cellText($cells[1] ?? null);
        $vSlot = $cells[2] ?? null;
        $blank = [cellText($vDate) === '', $vLoc === '', cellText($vSlot) === ''];
        if ($blank === [true, true, true]) {
            continue;
        }
        if (++$count > MAX_DATA_ROWS) {
            $errors[] = '行数が多すぎます（最大' . MAX_DATA_ROWS . '行）。';
            break;
        }
        if (in_array(true, $blank, true)) {
            $errors[] = "{$rn}行目: 日付・教室・時間コマは3つとも入力してください（コマを削除するときは、行の内容をすべて消します）。";
            continue;
        }

        $date = parseDateCell($vDate, $month);
        if ($date === null) {
            $errors[] = "{$rn}行目: 日付「" . cellText($vDate) . "」を読み取れません。";
            continue;
        }
        if ($date < $from || $date > $to) {
            $errors[] = "{$rn}行目: " . $date . " は" . $month->format('Y年n月') . "の日付ではありません。";
            continue;
        }
        if (!isset($locByName[$vLoc])) {
            $errors[] = "{$rn}行目: 教室「{$vLoc}」が見つかりません（▼の一覧から選んでください）。";
            continue;
        }
        $slot = parseSlotCell($vSlot);
        if ($slot === null) {
            $errors[] = "{$rn}行目: 時間コマ「" . cellText($vSlot) . "」を読み取れません（▼の一覧から選んでください）。";
            continue;
        }

        $reqText = cellText($cells[3] ?? null);
        $required = 1;
        if ($reqText !== '') {
            if (!ctype_digit($reqText) || (int)$reqText < 1 || (int)$reqText > 10) {
                $errors[] = "{$rn}行目: 必要人数は1〜10の数字にしてください。";
                continue;
            }
            $required = (int)$reqText;
        }

        $locId = $locByName[$vLoc];
        $venueId = null;
        $venueText = cellText($cells[4] ?? null);
        if ($venueText !== '' && $locId === $trialId) {
            if (!isset($venueByName[$venueText])) {
                $errors[] = "{$rn}行目: 会場「{$venueText}」が見つかりません（▼の一覧から選んでください）。";
                continue;
            }
            $venueId = $venueByName[$venueText];
        }

        $note = trim(str_replace("\r", '', (string)($cells[5] ?? '')));
        if (mb_strlen($note) > 200) {
            $errors[] = "{$rn}行目: 備考は200文字以内にしてください。";
            continue;
        }

        $key = $date . '|' . $locId . '|' . substr($slot[0], 0, 5);
        if (isset($seen[$key])) {
            $errors[] = "{$rn}行目: 同じ日付・教室・時間コマが{$seen[$key]}行目にもあります。";
            continue;
        }
        $seen[$key] = $rn;

        $rows[] = [
            'row' => $rn, 'key' => $key, 'date' => $date, 'location_id' => $locId, 'start' => $slot[0], 'end' => $slot[1],
            'required' => $required, 'venue_id' => $venueId, 'note' => $note === '' ? null : $note,
        ];
    }
    return [$rows, $errors];
}

// ───────────────────────── 差分の計算と保存 ─────────────────────────

function makePlan(array $existing, array $rows): array
{
    $byKey = [];
    foreach ($existing as $e) {
        $byKey[$e['key']][] = $e;
    }
    $plan = ['insert' => [], 'update' => [], 'delete' => [], 'same' => 0, 'conflicts' => []];
    $kept = [];

    foreach ($rows as $r) {
        if (!isset($byKey[$r['key']])) {
            $plan['insert'][] = $r;
            continue;
        }
        $e = $byKey[$r['key']][0]; // 同じキーが複数ある場合は最初の1件を残す
        $kept[$e['id']] = true;
        $changes = [];
        if (substr($e['end_time'], 0, 5) !== substr($r['end'], 0, 5)) {
            $changes[] = '時間 ' . slotLabel($e['start_time'], $e['end_time']) . ' → ' . slotLabel($r['start'], $r['end']);
        }
        if ($e['required_staff_count'] !== $r['required']) {
            $changes[] = '必要人数 ' . $e['required_staff_count'] . ' → ' . $r['required'];
        }
        if ($e['venue_location_id'] !== $r['venue_id']) {
            $changes[] = '会場を変更';
        }
        if (($e['note'] ?? '') !== ($r['note'] ?? '')) {
            $changes[] = '備考を変更';
        }
        if ($changes) {
            $plan['update'][] = ['id' => $e['id'], 'row' => $r, 'old' => $e, 'changes' => $changes];
        } else {
            $plan['same']++;
        }
    }
    foreach ($existing as $e) {
        if (!isset($kept[$e['id']])) {
            $plan['delete'][] = $e;
            if ($e['assigned'] > 0) {
                $plan['conflicts'][] = $e;
            }
        }
    }
    return $plan;
}

function conflictMessages(array $plan): array
{
    $out = [];
    foreach ($plan['conflicts'] as $e) {
        $out[] = jpDate($e['date']) . ' ' . $e['location_name'] . ' ' . slotLabel($e['start_time'], $e['end_time'])
            . ' は確定済みの担当講師がいるため削除できません。先に「講師シフト割り当て」で担当を外すか、行を残してください。';
    }
    return $out;
}

function jsonReply(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function applyPlan(PDO $db, array $plan, int $userId): void
{
    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM schedules WHERE id = ?');
        foreach ($plan['delete'] as $e) {
            $del->execute([$e['id']]);
        }
        $upd = $db->prepare('UPDATE schedules SET end_time = ?, required_staff_count = ?, venue_location_id = ?, note = ? WHERE id = ?');
        foreach ($plan['update'] as $u) {
            $upd->execute([$u['row']['end'], $u['row']['required'], $u['row']['venue_id'], $u['row']['note'], $u['id']]);
        }
        $ins = $db->prepare('
            INSERT INTO schedules (location_id, venue_location_id, date, start_time, end_time, required_staff_count, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($plan['insert'] as $r) {
            $ins->execute([$r['location_id'], $r['venue_id'], $r['date'], $r['start'], $r['end'], $r['required'], $r['note'], $userId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ───────────────────────── リクエスト処理 ─────────────────────────

$monthParam = (string)($_POST['month'] ?? $_GET['month'] ?? '');
try {
    $month = new DateTime(preg_match('/^\d{4}-\d{2}$/', $monthParam) ? $monthParam . '-01' : date('Y-m-01'));
} catch (Exception $e) {
    $month = new DateTime(date('Y-m-01'));
}
$monthKey = $month->format('Y-m');
$from = $month->format('Y-m-01');
$to = (clone $month)->modify('first day of next month')->format('Y-m-d');
$backUrl = '/admin/schedules.php?month=' . $monthKey;

$allLocations = $db->query('SELECT id, name, is_active FROM locations ORDER BY sort_order')->fetchAll();
$locByName = [];
$activeNames = [];
$venueByName = [];
$venueNameById = [];
foreach ($allLocations as $l) {
    $locByName[$l['name']] = (int)$l['id'];
    if ($l['is_active']) {
        $activeNames[] = $l['name'];
    }
    if ($l['is_active'] && mb_substr($l['name'], -2) === '教室') {
        $venueByName[$l['name']] = (int)$l['id'];
        $venueNameById[(int)$l['id']] = $l['name'];
    }
}
$trialId = $locByName['体験'] ?? null;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'download') {
    $bin = buildWorkbook(loadExisting($db, $from, $to), $month, $activeNames, array_keys($venueByName), $venueNameById);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="schedules_' . $monthKey . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode('日程_' . $monthKey . '.xlsx'));
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

if ($action === 'grid_check' || $action === 'grid_apply') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonReply(['ok' => false, 'errors' => [['index' => null, 'message' => '不正なリクエストです。ページを開き直してください。']]], 400);
    }
    $in = json_decode((string)($_POST['rows'] ?? ''), true);
    if (!is_array($in) || count($in) > MAX_DATA_ROWS + 300) {
        jsonReply(['ok' => false, 'errors' => [['index' => null, 'message' => '表のデータを読み取れません。']]], 400);
    }
    $sheet = [1 => ['日付', '教室', '時間コマ', '必要人数', '会場（体験のみ）', '備考']];
    foreach (array_values($in) as $i => $r) {
        $r = is_array($r) ? $r : [];
        $sheet[$i + 2] = [(string)($r['date'] ?? ''), (string)($r['loc'] ?? ''), (string)($r['slot'] ?? ''),
                          (string)($r['req'] ?? ''), (string)($r['venue'] ?? ''), (string)($r['note'] ?? '')];
    }
    [$rows, $errs] = parseSheet($sheet, $month, $locByName, $venueByName, $trialId);
    if ($errs) {
        $list = [];
        foreach ($errs as $m) {
            if (preg_match('/^(\d+)行目: (.*)$/u', $m, $mm)) {
                // 表では見出し行がないため、文中の行番号（「○行目にもあります」）も1つ減らして表の行番号に合わせる
                $msg = preg_replace_callback('/(\d+)行目にもあります/u', fn($x) => ((int)$x[1] - 1) . '行目にもあります', $mm[2]);
                $list[] = ['index' => (int)$mm[1] - 2, 'message' => $msg];
            } else {
                $list[] = ['index' => null, 'message' => $m];
            }
        }
        jsonReply(['ok' => false, 'errors' => $list]);
    }
    $plan = makePlan(loadExisting($db, $from, $to), $rows);
    if ($plan['conflicts']) {
        jsonReply(['ok' => false, 'errors' => array_map(fn($m) => ['index' => null, 'message' => $m], conflictMessages($plan))]);
    }
    if ($action === 'grid_check') {
        jsonReply([
            'ok' => true,
            'tiles' => ['insert' => count($plan['insert']), 'update' => count($plan['update']),
                        'delete' => count($plan['delete']), 'same' => $plan['same']],
            'deletes' => array_map(fn($e) => ['label' => jpDate($e['date']) . ' ' . $e['location_name'] . ' ' . slotLabel($e['start_time'], $e['end_time']),
                                              'requests' => $e['requests']], array_slice($plan['delete'], 0, 60)),
        ]);
    }
    applyPlan($db, $plan, currentUserId());
    rememberEditedMonth($from);
    setFlash('success', $month->format('Y年n月') . 'の日程を保存しました（追加' . count($plan['insert'])
        . '件・変更' . count($plan['update']) . '件・削除' . count($plan['delete']) . '件）。');
    jsonReply(['ok' => true, 'redirect' => BASE_PATH . $backUrl]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($backUrl);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', '不正なリクエストです。もう一度お試しください。');
    redirect($backUrl);
}

$errors = [];
$plan = null;
$token = '';

if ($action === 'upload') {
    unset($_SESSION['sched_import']);
    $f = $_FILES['xlsx'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $errors[] = $f && $f['error'] === UPLOAD_ERR_INI_SIZE
            ? 'ファイルが大きすぎます。'
            : 'ファイルを選んでからアップロードしてください。';
    } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        $errors[] = 'Excelファイル（.xlsx）を選んでください。';
    } else {
        try {
            $sheet = SimpleXlsx::readSheet((string)file_get_contents($f['tmp_name']), SHEET_NAME);
            [$rows, $errors] = parseSheet($sheet, $month, $locByName, $venueByName, $trialId);
            if (!$errors) {
                $plan = makePlan(loadExisting($db, $from, $to), $rows);
                $token = bin2hex(random_bytes(16));
                $_SESSION['sched_import'] = ['token' => $token, 'month' => $monthKey, 'rows' => $rows];
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
} elseif ($action === 'apply') {
    $saved = $_SESSION['sched_import'] ?? null;
    if (!$saved || $saved['month'] !== $monthKey || !hash_equals($saved['token'], (string)($_POST['token'] ?? ''))) {
        setFlash('error', '確認の有効期限が切れました。もう一度Excelを読み込んでください。');
        redirect($backUrl);
    }
    $plan = makePlan(loadExisting($db, $from, $to), $saved['rows']);
    if (!$plan['conflicts']) {
        applyPlan($db, $plan, currentUserId());
        unset($_SESSION['sched_import']);
        rememberEditedMonth($from);
        setFlash('success', $month->format('Y年n月') . 'の日程をExcelの内容で保存しました（追加' . count($plan['insert'])
            . '件・変更' . count($plan['update']) . '件・削除' . count($plan['delete']) . '件）。');
        redirect($backUrl);
    }
    $token = $saved['token'];
} else {
    redirect($backUrl);
}

if ($plan && $plan['conflicts']) {
    $errors = array_merge($errors, conflictMessages($plan));
}

$locName = fn(int $id) => array_search($id, $locByName, true) ?: '';
$pageTitle = 'Excel読み込みの確認';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
.xl-tiles { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 1rem; }
.xl-tile { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 0.75rem 1.25rem; min-width: 110px; text-align: center; }
.xl-tile b { display: block; font-size: 1.6rem; }
.xl-tile span { font-size: 0.8rem; color: #718096; }
.xl-add b { color: #38a169; } .xl-upd b { color: #3182ce; } .xl-del b { color: #e53e3e; }
.xl-note { color: #718096; font-size: 0.85rem; }
.xl-err li { margin-bottom: 4px; }
.xl-actions { display: flex; gap: 10px; margin-top: 1rem; }
details.card summary { cursor: pointer; font-weight: bold; }
</style>

<h1 class="page-title">📥 Excel読み込みの確認 — <?= h($month->format('Y年n月')) ?></h1>

<?php if ($errors): ?>
    <div class="card">
        <h2 class="card-title" style="color:#c53030;">❌ 保存できません（データはまだ変更されていません）</h2>
        <ul class="xl-err" style="padding-left:1.2rem;">
            <?php foreach (array_slice($errors, 0, MAX_ERRORS_SHOWN) as $msg): ?>
                <li><?= h($msg) ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > MAX_ERRORS_SHOWN): ?>
                <li>…ほか<?= count($errors) - MAX_ERRORS_SHOWN ?>件</li>
            <?php endif; ?>
        </ul>
        <p class="xl-note">Excelを直して、もう一度読み込んでください。<?= h($month->format('Y年n月')) ?>の日付だけ読み込めます。</p>
        <a class="btn btn-secondary" href="<?= BASE_PATH . h($backUrl) ?>">日程管理に戻る</a>
    </div>
<?php else: ?>
    <div class="xl-tiles">
        <div class="xl-tile xl-add"><b><?= count($plan['insert']) ?></b><span>追加</span></div>
        <div class="xl-tile xl-upd"><b><?= count($plan['update']) ?></b><span>変更</span></div>
        <div class="xl-tile xl-del"><b><?= count($plan['delete']) ?></b><span>削除</span></div>
        <div class="xl-tile"><b><?= $plan['same'] ?></b><span>変更なし</span></div>
    </div>

    <?php if (!$plan['insert'] && !$plan['update'] && !$plan['delete']): ?>
        <div class="card">
            <p>Excelの内容は、いまの日程と同じです。変更はありません。</p>
            <a class="btn btn-secondary" href="<?= BASE_PATH . h($backUrl) ?>">日程管理に戻る</a>
        </div>
    <?php else: ?>
        <?php if ($plan['delete']): ?>
            <div class="card" style="border-left:4px solid #e53e3e;">
                <h2 class="card-title">🗑 削除される日程（<?= count($plan['delete']) ?>件）</h2>
                <p class="xl-note">Excelで空白になっている（または無くなった）日程です。希望申請が付いている場合は、申請も一緒に消えます。</p>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>日付</th><th>教室</th><th>時間コマ</th><th>希望申請</th></tr></thead>
                    <tbody>
                    <?php foreach ($plan['delete'] as $e): ?>
                        <tr><td><?= h(jpDate($e['date'])) ?></td><td><?= h($e['location_name']) ?></td>
                            <td><?= h(slotLabel($e['start_time'], $e['end_time'])) ?></td>
                            <td><?= $e['requests'] ? h($e['requests'] . '件') : '—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        <?php endif; ?>

        <?php if ($plan['update']): ?>
            <details class="card"<?= count($plan['update']) <= 10 ? ' open' : '' ?>>
                <summary>✏️ 変更される日程（<?= count($plan['update']) ?>件）</summary>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>日付</th><th>教室</th><th>時間コマ</th><th>変更内容</th></tr></thead>
                    <tbody>
                    <?php foreach ($plan['update'] as $u): ?>
                        <tr><td><?= h(jpDate($u['row']['date'])) ?></td><td><?= h($locName($u['row']['location_id'])) ?></td>
                            <td><?= h(slotLabel($u['old']['start_time'], $u['old']['end_time'])) ?></td>
                            <td><?= h(implode(' / ', $u['changes'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details>
        <?php endif; ?>

        <?php if ($plan['insert']): ?>
            <details class="card"<?= count($plan['insert']) <= 10 ? ' open' : '' ?>>
                <summary>➕ 追加される日程（<?= count($plan['insert']) ?>件）</summary>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>日付</th><th>教室</th><th>時間コマ</th><th>必要人数</th></tr></thead>
                    <tbody>
                    <?php foreach ($plan['insert'] as $r): ?>
                        <tr><td><?= h(jpDate($r['date'])) ?></td><td><?= h($locName($r['location_id'])) ?></td>
                            <td><?= h(slotLabel($r['start'], $r['end'])) ?></td><td><?= h($r['required']) ?>名</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_PATH ?>/admin/schedules_excel.php" class="xl-actions"
              onsubmit="return confirm('<?= h($month->format('Y年n月')) ?>の日程を、Excelの内容で書き換えます。よろしいですか？');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="month" value="<?= h($monthKey) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit" class="btn btn-primary">この内容で保存する</button>
            <a class="btn btn-secondary" href="<?= BASE_PATH . h($backUrl) ?>">キャンセル</a>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
