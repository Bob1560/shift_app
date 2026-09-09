<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

// ユーザー追加処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['admin', 'instructor']) ? $_POST['role'] : 'instructor';

        if (!$name || !$email || !$password) {
            $errors[] = '必須項目を入力してください。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '正しいメールアドレスを入力してください。';
        } elseif (strlen($password) < 8) {
            $errors[] = 'パスワードは8文字以上にしてください。';
        } else {
            // メール重複チェック
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'このメールアドレスはすでに登録されています。';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                   ->execute([$name, $email, $hash, $role]);
                setFlash('success', 'ユーザー「' . $name . '」を追加しました。');
                header('Location: /admin/users.php');
                exit;
            }
        }
    }
}

// ユーザー有効/無効切替
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $uid    = (int)$_POST['user_id'];
        $active = (int)$_POST['is_active'];
        // 自分自身は変更不可
        if ($uid !== currentUserId()) {
            $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active, $uid]);
            setFlash('success', 'ユーザーの状態を変更しました。');
        }
        header('Location: /admin/users.php');
        exit;
    }
}

// パスワードリセット
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $uid      = (int)$_POST['user_id'];
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) >= 8) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
            setFlash('success', 'パスワードをリセットしました。');
        } else {
            setFlash('error', 'パスワードは8文字以上が必要です。');
        }
        header('Location: /admin/users.php');
        exit;
    }
}

// ユーザー一覧取得
$users = $db->query('SELECT * FROM users ORDER BY role DESC, id')->fetchAll();

$pageTitle = '講師管理';
include __DIR__ . '/../includes/layout_header.php';
?>

<h1 class="page-title">👥 講師・アカウント管理</h1>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<!-- 新規追加フォーム -->
<div class="card">
    <h2 class="card-title">＋ ユーザーを追加</h2>
    <form method="POST" action="/admin/users.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
            <div class="form-group" style="margin:0;">
                <label>氏名 <span style="color:red">*</span></label>
                <input type="text" name="name" class="form-control" required maxlength="100">
            </div>
            <div class="form-group" style="margin:0;">
                <label>メールアドレス <span style="color:red">*</span></label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label>パスワード <span style="color:red">*</span></label>
                <input type="password" name="password" class="form-control" required minlength="8">
                <span class="form-hint">8文字以上</span>
            </div>
            <div class="form-group" style="margin:0;">
                <label>権限</label>
                <select name="role" class="form-control">
                    <option value="instructor">講師</option>
                    <option value="admin">管理者</option>
                </select>
            </div>
        </div>
        <div class="mt-2">
            <button type="submit" class="btn btn-primary">追加する</button>
        </div>
    </form>
</div>

<!-- ユーザー一覧 -->
<div class="card">
    <h2 class="card-title">ユーザー一覧（<?= count($users) ?>名）</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>権限</th>
                    <th>2FA</th>
                    <th>状態</th>
                    <th>登録日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr style="<?= !$u['is_active'] ? 'opacity:0.5;' : '' ?>">
                    <td><?= h($u['id']) ?></td>
                    <td>
                        <strong><?= h($u['name']) ?></strong>
                        <?php if ($u['id'] === currentUserId()): ?>
                            <span style="font-size:0.75rem;color:#3182ce;">（あなた）</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= h($u['role']) ?>">
                            <?= $u['role'] === 'admin' ? '管理者' : '講師' ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?= $u['totp_enabled'] ? '✅' : '-' ?>
                    </td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span style="color:#38a169;font-weight:bold;">有効</span>
                        <?php else: ?>
                            <span style="color:#a0aec0;">無効</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.85rem;"><?= h(date('Y/m/d', strtotime($u['created_at']))) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($u['id'] !== currentUserId()): ?>
                        <!-- 有効/無効切替 -->
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?= h($u['id']) ?>">
                            <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                            <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                    onclick="return confirm('ユーザーの状態を変更しますか？')">
                                <?= $u['is_active'] ? '無効化' : '有効化' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <!-- パスワードリセット -->
                        <button type="button" class="btn btn-secondary btn-sm"
                                onclick="showPasswordReset(<?= h($u['id']) ?>, '<?= h($u['name']) ?>')">
                            PW変更
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- パスワードリセットモーダル -->
<div id="pw-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:2rem;width:100%;max-width:400px;margin:1rem;">
        <h3 style="margin-bottom:1rem;">パスワード変更</h3>
        <form method="POST" action="/admin/users.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="pw-user-id">
            <div class="form-group">
                <label id="pw-user-label">新しいパスワード</label>
                <input type="password" name="new_password" class="form-control"
                       required minlength="8" placeholder="8文字以上">
            </div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closePwModal()">キャンセル</button>
                <button type="submit" class="btn btn-primary">変更する</button>
            </div>
        </form>
    </div>
</div>

<script>
function showPasswordReset(userId, userName) {
    document.getElementById('pw-user-id').value = userId;
    document.getElementById('pw-user-label').textContent = userName + ' の新しいパスワード';
    document.getElementById('pw-modal').style.display = 'flex';
}
function closePwModal() {
    document.getElementById('pw-modal').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
