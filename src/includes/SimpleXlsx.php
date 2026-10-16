<?php
/**
 * .xlsx の最小の読み書きクラス（日程のExcel入出力専用）。
 * zip拡張・外部ライブラリなしで動く（zlib だけを使う）。
 *
 * 書き出し: SimpleXlsx::write($sheets)
 *   $sheets[] = ['name' => 'シート名', 'widths' => [列幅...], 'colStyles' => [列番号(1始まり) => スタイルID],
 *                'rows' => [[セル...], ...], 'validations' => [[...], ...]]
 *   セル: 文字列 / 数値 / null / ['v' => 値, 's' => スタイルID]
 *   スタイルID: 0=標準 1=見出し 2=日付 3=中央 4=折り返し
 * 読み込み: SimpleXlsx::readSheet($binary, '優先するシート名') => [行番号 => [列番号(0始まり) => 値]]
 */
class SimpleXlsx
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 40 * 1024 * 1024;
    private const MAX_ROWS = 5000;

    // ───────────────────────── 書き出し ─────────────────────────

    public static function write(array $sheets): string
    {
        $files = [];
        $contentTypes = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $sheetEls = '';
        $relEls = '';

        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $files["xl/worksheets/sheet$n.xml"] = self::sheetXml($sheet, $i === 0);
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetEls .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relEls .= '<Relationship Id="rId' . $n . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $relEls .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>';

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $files['[Content_Types].xml'] = $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $contentTypes . '</Types>';
        $files['_rels/.rels'] = $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = $head . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<bookViews><workbookView/></bookViews><sheets>' . $sheetEls . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEls . '</Relationships>';
        $files['xl/styles.xml'] = $head . self::stylesXml();

        return self::zip($files);
    }

    private static function stylesXml(): string
    {
        $font = '<sz val="11"/><name val="Yu Gothic"/><family val="2"/>';
        return '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="[$-411]yyyy/m/d(aaa)"/></numFmts>'
            . '<fonts count="2"><font>' . $font . '</font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Yu Gothic"/><family val="2"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF667EEA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheetXml(array $sheet, bool $selected): string
    {
        $rowsXml = '';
        foreach ($sheet['rows'] as $ri => $cells) {
            $r = $ri + 1;
            $cx = '';
            foreach (array_values($cells) as $ci => $cell) {
                $style = 0;
                if (is_array($cell)) {
                    $style = (int)($cell['s'] ?? 0);
                    $cell = $cell['v'] ?? null;
                }
                if ($cell === null || $cell === '') {
                    continue;
                }
                $ref = self::colName($ci) . $r;
                $s = $style ? ' s="' . $style . '"' : '';
                if (is_int($cell) || is_float($cell)) {
                    $cx .= '<c r="' . $ref . '"' . $s . '><v>' . $cell . '</v></c>';
                } else {
                    $cx .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$cell) . '</t></is></c>';
                }
            }
            $height = $r === 1 ? ' ht="22" customHeight="1"' : '';
            $rowsXml .= '<row r="' . $r . '"' . $height . '>' . $cx . '</row>';
        }

        $cols = '';
        foreach ($sheet['widths'] ?? [] as $i => $w) {
            $n = $i + 1;
            $st = isset($sheet['colStyles'][$n]) ? ' style="' . (int)$sheet['colStyles'][$n] . '"' : '';
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"' . $st . '/>';
        }

        $dv = '';
        foreach ($sheet['validations'] ?? [] as $v) {
            $attrs = 'type="' . $v['type'] . '" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="' . $v['sqref'] . '"';
            if (isset($v['operator'])) {
                $attrs .= ' operator="' . $v['operator'] . '"';
            }
            if (isset($v['error'])) {
                $attrs .= ' errorTitle="' . self::esc($v['errorTitle'] ?? '入力エラー') . '" error="' . self::esc($v['error']) . '"';
            }
            if (isset($v['prompt'])) {
                $attrs .= ' promptTitle="' . self::esc($v['promptTitle'] ?? '入力') . '" prompt="' . self::esc($v['prompt']) . '"';
            }
            $dv .= '<dataValidation ' . $attrs . '><formula1>' . self::esc($v['formula1']) . '</formula1>'
                . (isset($v['formula2']) ? '<formula2>' . self::esc($v['formula2']) . '</formula2>' : '')
                . '</dataValidation>';
        }

        $pane = !empty($sheet['freezeHeader'])
            ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="' . self::NS_MAIN . '">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . ($dv !== '' ? '<dataValidations count="' . count($sheet['validations']) . '">' . $dv . '</dataValidations>' : '')
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function zip(array $files): string
    {
        $out = '';
        $cd = '';
        $count = 0;
        $time = 0;
        $date = ((int)date('Y') - 1980) << 9 | ((int)date('n') << 5) | (int)date('j');
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $comp = gzdeflate($data, 6);
            $method = 8;
            if ($comp === false || strlen($comp) >= $size) {
                $comp = $data;
                $method = 0;
            }
            $csize = strlen($comp);
            $nameLen = strlen($name);
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date, $crc, $csize, $size, $nameLen, 0) . $name . $comp;
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $time, $date, $crc, $csize, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }
        return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($cd), strlen($out), 0);
    }

    // ───────────────────────── 読み込み ─────────────────────────

    /** @return array<int, array<int, string|float|null>> 行番号 => [列番号 => 値] */
    public static function readSheet(string $binary, ?string $preferName = null): array
    {
        $files = self::unzip($binary);

        $wb = self::xml($files['xl/workbook.xml'] ?? null);
        $rels = self::xml($files['xl/_rels/workbook.xml.rels'] ?? null);
        if (!$wb || !$rels) {
            throw new RuntimeException('Excelファイル(.xlsx)として読み込めません。');
        }
        $targets = [];
        foreach ($rels->Relationship as $rel) {
            $targets[(string)$rel['Id']] = (string)$rel['Target'];
        }
        $path = null;
        foreach ($wb->sheets->sheet as $sheet) {
            $rid = (string)$sheet->attributes(self::NS_REL)['id'];
            $target = $targets[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            $candidate = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
            if ($path === null) {
                $path = $candidate;
            }
            if ($preferName !== null && (string)$sheet['name'] === $preferName) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null || !isset($files[$path])) {
            throw new RuntimeException('Excelのシートが見つかりません。');
        }

        $strings = [];
        $ss = self::xml($files['xl/sharedStrings.xml'] ?? null);
        if ($ss) {
            foreach ($ss->si as $si) {
                $strings[] = self::richText($si);
            }
        }

        $ws = self::xml($files[$path]);
        if (!$ws) {
            throw new RuntimeException('Excelのシートを読み取れません。');
        }
        $rows = [];
        $autoRow = 0;
        foreach ($ws->sheetData->row as $row) {
            $autoRow++;
            $rn = (int)$row['r'] ?: $autoRow;
            if ($rn > self::MAX_ROWS) {
                break;
            }
            $autoCol = -1;
            foreach ($row->c as $c) {
                $autoCol++;
                $col = $autoCol;
                if (isset($c['r']) && preg_match('/^([A-Za-z]+)\d+$/', (string)$c['r'], $m)) {
                    $col = self::colIndex($m[1]);
                    $autoCol = $col;
                }
                $t = (string)$c['t'];
                if ($t === 's') {
                    $val = $strings[(int)$c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $val = self::richText($c->is);
                } elseif ($t === 'str' || $t === 'e' || $t === 'b' || $t === 'd') {
                    $val = (string)$c->v;
                } else {
                    $raw = trim((string)$c->v);
                    $val = $raw === '' ? null : (is_numeric($raw) ? (float)$raw : $raw);
                }
                $rows[$rn][$col] = $val;
            }
        }
        return $rows;
    }

    private static function richText(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string)$node->t;
        }
        $s = '';
        foreach ($node->r as $r) {
            $s .= (string)$r->t;
        }
        return $s;
    }

    private static function xml(?string $xml): ?SimpleXMLElement
    {
        if ($xml === null) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $el ?: null;
    }

    private static function unzip(string $bin): array
    {
        $fail = fn() => new RuntimeException('Excelファイル(.xlsx)として読み込めません。');
        $pos = strrpos($bin, "PK\x05\x06");
        if ($pos === false || strlen($bin) - $pos < 22) {
            throw $fail();
        }
        $e = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($bin, $pos + 4, 16));
        $files = [];
        $total = 0;
        $p = $e['cdOffset'];
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") {
                throw $fail();
            }
            $h = unpack('vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($bin, $p + 4, 42));
            $name = substr($bin, $p + 46, $h['nameLen']);
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            if ($name === '' || substr($name, -1) === '/') {
                continue;
            }
            if ($h['flags'] & 1) {
                throw new RuntimeException('パスワード付きのExcelファイルは読み込めません。');
            }
            $total += $h['size'];
            if ($h['size'] > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Excelファイルが大きすぎます。');
            }
            $lh = unpack('vnameLen/vextraLen', substr($bin, $h['offset'] + 26, 4));
            $raw = substr($bin, $h['offset'] + 30 + $lh['nameLen'] + $lh['extraLen'], $h['csize']);
            if ($h['method'] === 0) {
                $data = $raw;
            } elseif ($h['method'] === 8) {
                $data = @gzinflate($raw, max(1, $h['size']));
            } else {
                throw $fail();
            }
            if ($data === false) {
                throw $fail();
            }
            $files[$name] = $data;
        }
        return $files;
    }
}
