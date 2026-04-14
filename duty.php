<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();

// ── DBマイグレーション: 並び順カラム追加 ─────────────────────
$cols = array_column($db->query("PRAGMA table_info(members)")->fetchAll(), 'name');
if (!in_array('practice_duty_order', $cols)) {
    $db->exec("ALTER TABLE members ADD COLUMN practice_duty_order INTEGER NOT NULL DEFAULT 0");
    $rows = $db->query("SELECT id, practice_duty FROM members WHERE active=1 AND practice_duty IS NOT NULL AND practice_duty != '' ORDER BY practice_duty, grade DESC, last_name, first_name")->fetchAll();
    $pos = [];
    $stmt = $db->prepare("UPDATE members SET practice_duty_order=? WHERE id=?");
    foreach ($rows as $r) {
        $k = $r['practice_duty'];
        if (!isset($pos[$k])) $pos[$k] = 1;
        $stmt->execute([$pos[$k]++, $r['id']]);
    }
}
if (!in_array('match_duty_order', $cols)) {
    $db->exec("ALTER TABLE members ADD COLUMN match_duty_order INTEGER NOT NULL DEFAULT 0");
    $rows = $db->query("SELECT id, match_duty FROM members WHERE active=1 AND match_duty IS NOT NULL AND match_duty != '' ORDER BY match_duty, grade DESC, last_name, first_name")->fetchAll();
    $pos = [];
    $stmt = $db->prepare("UPDATE members SET match_duty_order=? WHERE id=?");
    foreach ($rows as $r) {
        $k = $r['match_duty'];
        if (!isset($pos[$k])) $pos[$k] = 1;
        $stmt->execute([$pos[$k]++, $r['id']]);
    }
}

// ── CSVエクスポート ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    verify_csrf();
    $type = $_POST['export_type'] ?? '';

    if ($type === 'practice') {
        $keys     = range('A', 'J');
        $col      = 'practice_duty';
        $order    = 'practice_duty_order';
        $where    = "active=1 AND has_sibling=0";
        $filename = '練習当番_' . date('YmdHi') . '.xlsx';
        $header   = ['練習当番', 'メンバー'];
    } elseif ($type === 'match') {
        $keys     = ['1', '2', '3', '4'];
        $col      = 'match_duty';
        $order    = 'match_duty_order';
        $where    = "active=1 AND has_sibling=0 AND grade > 3";
        $filename = '試合当番_' . date('YmdHi') . '.xlsx';
        $header   = ['試合当番', 'メンバー'];
    } else {
        http_response_code(400);
        exit('invalid type');
    }

    $grade_circle = ['1'=>'①','2'=>'②','3'=>'③','4'=>'④','5'=>'⑤','6'=>'⑥'];

    // グループ別に振り分け
    $groups = array_fill_keys($keys, []);
    foreach ($db->query("
        SELECT last_name, grade, {$col}
        FROM members
        WHERE {$where} AND {$col} IS NOT NULL AND {$col} != ''
        ORDER BY {$col}, {$order}, grade DESC, last_name
    ")->fetchAll() as $r) {
        if (isset($groups[$r[$col]])) {
            $g = $grade_circle[(string)$r['grade']] ?? $r['grade'] . '年';
            $groups[$r[$col]][] = $g . $r['last_name'];
        }
    }

    // ── XLSX（正規 Office Open XML）で出力 ───────────────────
    $xc = fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

    // sheet1.xml のセルデータ構築
    $sheet_rows = '';
    $rn = 1;
    // ヘッダー行（スタイル1: 紺背景・白太字）
    $sheet_rows .= '<row r="' . $rn . '">'
        . '<c r="A' . $rn . '" t="inlineStr" s="1"><is><t>' . $xc($header[0]) . '</t></is></c>'
        . '<c r="B' . $rn . '" t="inlineStr" s="1"><is><t>' . $xc($header[1]) . '</t></is></c>'
        . '</row>' . "\n";
    $rn++;
    // データ行（当番列: スタイル2: グレー背景・紺太字）
    foreach ($groups as $key => $members) {
        $sheet_rows .= '<row r="' . $rn . '">'
            . '<c r="A' . $rn . '" t="inlineStr" s="2"><is><t>' . $xc($key) . '</t></is></c>'
            . '<c r="B' . $rn . '" t="inlineStr"><is><t>' . $xc(implode(' ', $members)) . '</t></is></c>'
            . '</row>' . "\n";
        $rn++;
    }

    $sheet_name = $xc($header[0]);
    $files = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheet_name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/worksheets/sheet1.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="2" width="42" customWidth="1"/></cols>'
            . '<sheetData>' . $sheet_rows . '</sheetData>'
            . '</worksheet>',

        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1E3A5F"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>',
    ];

    // 純粋なPHPでZIPバイナリを構築（ZipArchive不要）
    $local  = '';
    $cendir = '';
    $offset = 0;
    foreach ($files as $zpath => $zcontent) {
        $zpath_b  = $zpath;
        $zpath_l  = strlen($zpath_b);
        $data_l   = strlen($zcontent);
        $crc      = crc32($zcontent);
        $local   .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $data_l, $data_l, $zpath_l, 0)
                  . $zpath_b . $zcontent;
        $cendir  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $data_l, $data_l, $zpath_l, 0, 0, 0, 0, 0, $offset)
                  . $zpath_b;
        $offset  += 30 + $zpath_l + $data_l;
    }
    $cd_size = strlen($cendir);
    $zip_bin = $local . $cendir
             . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), $cd_size, $offset, 0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($zip_bin));
    echo $zip_bin;
    exit;
}

// ── AJAX: 並び順更新 ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder_duty') {
    verify_csrf();
    if (!is_editor()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
    header('Content-Type: application/json');
    $type = $_POST['type'] ?? '';
    $ids  = json_decode($_POST['ids'] ?? '[]', true);
    if ($type === 'practice') {
        $col = 'practice_duty_order';
    } elseif ($type === 'match') {
        $col = 'match_duty_order';
    } else {
        http_response_code(400); echo json_encode(['error' => 'invalid type']); exit;
    }
    if (!is_array($ids) || empty($ids)) {
        http_response_code(400); echo json_encode(['error' => 'invalid ids']); exit;
    }
    $stmt = $db->prepare("UPDATE members SET {$col}=? WHERE id=? AND active=1");
    foreach ($ids as $i => $id) {
        $stmt->execute([$i + 1, (int)$id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: 当番更新 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_duty') {
    verify_csrf();
    if (!is_editor()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
    header('Content-Type: application/json');

    $type      = $_POST['type']      ?? '';
    $member_id = (int)($_POST['member_id'] ?? 0);
    $duty_key  = $_POST['duty_key']  ?? '';

    if ($type === 'practice') {
        $valid_keys = array_merge([''], range('A', 'J'));
        $col = 'practice_duty';
        $order_col = 'practice_duty_order';
    } elseif ($type === 'match') {
        $valid_keys = ['', '1', '2', '3', '4'];
        $col = 'match_duty';
        $order_col = 'match_duty_order';
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'invalid type']);
        exit;
    }

    if (!in_array($duty_key, $valid_keys, true) || $member_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid params']);
        exit;
    }

    $val = $duty_key === '' ? null : $duty_key;
    // グループ変更時は順序を999（末尾）にセット
    $db->prepare("UPDATE members SET {$col}=?, {$order_col}=999 WHERE id=? AND active=1")->execute([$val, $member_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── 表示データ取得 ────────────────────────────────────────
// has_sibling=0 の中で姓が重複している部員の姓一覧
$dup_names = array_column(
    $db->query("SELECT last_name FROM members WHERE active=1 AND has_sibling=0 GROUP BY last_name HAVING COUNT(*) > 1")->fetchAll(),
    'last_name'
);

$practice_groups = [];
foreach (range('A', 'J') as $key) {
    $practice_groups[$key] = [];
}
foreach ($db->query("SELECT * FROM members WHERE active=1 AND practice_duty IS NOT NULL AND practice_duty != '' AND has_sibling = 0 ORDER BY practice_duty, practice_duty_order ASC")->fetchAll() as $m) {
    if (isset($practice_groups[$m['practice_duty']])) $practice_groups[$m['practice_duty']][] = $m;
}
$practice_unassigned = $db->query("SELECT * FROM members WHERE active=1 AND (practice_duty IS NULL OR practice_duty = '') AND has_sibling = 0 ORDER BY grade DESC, last_name, first_name")->fetchAll();

$match_groups = [];
foreach (['1', '2', '3', '4'] as $key) {
    $match_groups[$key] = [];
}
foreach ($db->query("SELECT * FROM members WHERE active=1 AND match_duty IS NOT NULL AND match_duty != '' AND has_sibling = 0 AND grade > 3 ORDER BY match_duty, match_duty_order ASC")->fetchAll() as $m) {
    if (isset($match_groups[$m['match_duty']])) $match_groups[$m['match_duty']][] = $m;
}
$match_unassigned = $db->query("SELECT * FROM members WHERE active=1 AND (match_duty IS NULL OR match_duty = '') AND has_sibling = 0 AND grade > 3 ORDER BY grade DESC, last_name, first_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>当番一覧 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .duty-section {
            margin-bottom: 40px;
        }

        .duty-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .duty-table th {
            background: #1e3a5f;
            color: #fff;
            padding: 8px 12px;
            text-align: center;
            font-size: 14px;
            white-space: nowrap;
            width: 56px;
        }

        .duty-table td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            vertical-align: middle;
        }

        .duty-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .duty-table tr:nth-child(even) td.drop-zone {
            background: #f8fafc;
        }

        .duty-members {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-height: 36px;
            padding: 4px;
            border-radius: 4px;
            transition: background .15s;
        }

        .duty-members.drag-over {
            background: #dbeafe;
            outline: 2px dashed #3b82f6;
        }

        .duty-member {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 13px;
            white-space: nowrap;
            cursor: grab;
            user-select: none;
            transition: opacity .15s, box-shadow .15s;
        }

        .duty-member:active {
            cursor: grabbing;
        }

        .duty-member.dragging {
            opacity: 0.35;
            box-shadow: none;
        }

        .duty-member .grade-badge {
            font-size: 11px;
            color: #2563eb;
            font-weight: bold;
        }

        .duty-empty {
            color: #94a3b8;
            font-size: 13px;
            padding: 4px 0;
        }

        .duty-count {
            font-size: 13px;
            color: #475569;
            text-align: center;
            white-space: nowrap;
            width: 48px;
        }

        #save-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 4px;
            transition: opacity .3s;
        }

        #save-status.saving {
            color: #2563eb;
        }

        #save-status.saved {
            color: #16a34a;
        }

        #save-status.error {
            color: #dc2626;
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0">当番一覧</h1>
            <span id="save-status"></span>
        </div>

        <?php
        // テーブル描画を関数化して練習・試合で共用
        function render_duty_table(array $groups, string $type, array $unassigned, array $dup_names): void
        {
            // 姓の表示名を返すヘルパー
            $member_label = fn(array $m): string =>
                in_array($m['last_name'], $dup_names, true)
                    ? $m['last_name'] . '（' . $m['first_name'] . '）'
                    : $m['last_name'];
        ?>
            <div class="table-wrap">
                <table class="duty-table">
                    <thead>
                        <tr>
                            <th>当番</th>
                            <th style="width:auto; text-align:left; padding-left:12px;">メンバー（ドラッグで移動）</th>
                            <th style="width:48px;">人数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $key => $members): ?>
                            <tr>
                                <td style="text-align:center; font-weight:bold; font-size:16px; color:#1e3a5f; background:#f1f5f9;">
                                    <?= h($key) ?>
                                </td>
                                <td class="drop-zone" data-type="<?= h($type) ?>" data-key="<?= h($key) ?>">
                                    <div class="duty-members" data-type="<?= h($type) ?>" data-key="<?= h($key) ?>">
                                        <?php if (empty($members)): ?>
                                            <span class="duty-empty">未設定</span>
                                        <?php else: ?>
                                            <?php foreach ($members as $m): ?>
                                                <span class="duty-member" draggable="true"
                                                    data-member-id="<?= h($m['id']) ?>"
                                                    data-type="<?= h($type) ?>">
                                                    <span class="grade-badge"><?= h($m['grade']) ?>年</span>
                                                    <?= h($member_label($m)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="duty-count" data-type="<?= h($type) ?>" data-key="<?= h($key) ?>">
                                    <?= count($members) > 0 ? count($members) . '名' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- 未割り当て行 -->
                        <tr>
                            <td style="text-align:center; font-weight:bold; font-size:16px; color:#94a3b8; background:#f8fafc;">
                                未
                            </td>
                            <td class="drop-zone" data-type="<?= h($type) ?>" data-key="">
                                <div class="duty-members" data-type="<?= h($type) ?>" data-key="">
                                    <?php if (empty($unassigned)): ?>
                                        <span class="duty-empty">なし</span>
                                    <?php else: ?>
                                        <?php foreach ($unassigned as $m): ?>
                                            <span class="duty-member" draggable="true"
                                                data-member-id="<?= h($m['id']) ?>"
                                                data-type="<?= h($type) ?>">
                                                <span class="grade-badge"><?= h($m['grade']) ?>年</span>
                                                <?= h($member_label($m)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="duty-count" data-type="<?= h($type) ?>" data-key="">
                                <?= count($unassigned) > 0 ? count($unassigned) . '名' : '—' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <!-- 練習当番 -->
        <div class="duty-section">
            <div class="card">
                <div class="flex items-center justify-between" style="margin-bottom:12px;">
                    <div class="card-title" style="margin-bottom:0">練習当番</div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="export_csv">
                        <input type="hidden" name="export_type" value="practice">
                        <button type="submit" class="btn btn-outline btn-sm">Excelダウンロード</button>
                    </form>
                </div>
                <?php render_duty_table($practice_groups, 'practice', $practice_unassigned, $dup_names); ?>
            </div>
        </div>

        <!-- 試合当番 -->
        <div class="duty-section">
            <div class="card">
                <div class="flex items-center justify-between" style="margin-bottom:12px;">
                    <div class="card-title" style="margin-bottom:0">試合当番</div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="export_csv">
                        <input type="hidden" name="export_type" value="match">
                        <button type="submit" class="btn btn-outline btn-sm">Excelダウンロード</button>
                    </form>
                </div>
                <?php render_duty_table($match_groups, 'match', $match_unassigned, $dup_names); ?>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        const statusEl = document.getElementById('save-status');
        let dragEl = null;

        // ── ステータス表示 ───────────────────────────────────────
        let statusTimer = null;

        function showStatus(msg, cls) {
            clearTimeout(statusTimer);
            statusEl.textContent = msg;
            statusEl.className = cls;
            if (cls === 'saved') {
                statusTimer = setTimeout(() => {
                    statusEl.textContent = '';
                    statusEl.className = '';
                }, 2000);
            }
        }

        // ── カウント更新 ─────────────────────────────────────────
        function updateCount(type, key) {
            const zone = document.querySelector(`.duty-members[data-type="${type}"][data-key="${key}"]`);
            const count = zone.querySelectorAll('.duty-member').length;
            const cell = document.querySelector(`.duty-count[data-type="${type}"][data-key="${key}"]`);
            cell.textContent = count > 0 ? count + '名' : '—';
        }

        // ── 空表示の制御 ─────────────────────────────────────────
        function syncEmpty(zone) {
            const placeholder = zone.querySelector('.duty-empty');
            const hasMembers = zone.querySelectorAll('.duty-member').length > 0;
            if (placeholder) {
                placeholder.style.display = hasMembers ? 'none' : '';
            } else if (!hasMembers) {
                const span = document.createElement('span');
                span.className = 'duty-empty';
                span.textContent = '未設定';
                zone.appendChild(span);
            }
        }

        // ── AJAX保存共通処理 ─────────────────────────────────────
        function saveDuty(memberId, type, destKey) {
            showStatus('保存中…', 'saving');
            fetch('/duty.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'update_duty',
                        type: type,
                        member_id: memberId,
                        duty_key: destKey,
                        csrf_token: CSRF_TOKEN
                    })
                })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        showStatus('保存しました', 'saved');
                    } else {
                        throw new Error(d.error || 'error');
                    }
                })
                .catch(() => {
                    showStatus('保存に失敗しました', 'error');
                });
        }

        // ── 並び順保存 ──────────────────────────────────────────
        function saveOrder(type, zone) {
            const ids = Array.from(zone.querySelectorAll('.duty-member')).map(el => el.dataset.memberId);
            if (ids.length === 0) return;
            fetch('/duty.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'reorder_duty',
                    type: type,
                    ids: JSON.stringify(ids),
                    csrf_token: CSRF_TOKEN
                })
            }).then(r => r.json()).then(d => {
                if (d.ok) showStatus('保存しました', 'saved');
                else throw new Error(d.error);
            }).catch(() => showStatus('保存に失敗しました', 'error'));
        }

        // ── ドロップ共通処理 ─────────────────────────────────────
        function dropToZone(zone, beforeEl) {
            if (!zone || !dragEl) return;
            if (zone.dataset.type !== dragEl.dataset.type) return;
            zone.classList.remove('drag-over');

            const srcZone = dragEl.closest('.duty-members');
            const srcKey = srcZone.dataset.key;
            const destKey = zone.dataset.key;

            if (srcKey === destKey) {
                // 同一ゾーン: dragover で既に移動済み → 順序保存のみ
                saveOrder(dragEl.dataset.type, zone);
                return;
            }

            // 別ゾーンへ移動
            if (beforeEl) {
                zone.insertBefore(dragEl, beforeEl);
            } else {
                const placeholder = zone.querySelector('.duty-empty');
                zone.insertBefore(dragEl, placeholder || null);
            }

            syncEmpty(srcZone);
            syncEmpty(zone);
            updateCount(dragEl.dataset.type, srcKey);
            updateCount(dragEl.dataset.type, destKey);
            saveDuty(dragEl.dataset.memberId, dragEl.dataset.type, destKey);
            saveOrder(dragEl.dataset.type, zone);
        }

        // ── マウス ドラッグイベント ───────────────────────────────
        document.addEventListener('dragstart', e => {
            const el = e.target.closest('.duty-member');
            if (!el) return;
            dragEl = el;
            el.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        document.addEventListener('dragend', e => {
            if (dragEl) {
                dragEl.classList.remove('dragging');
                dragEl = null;
            }
            document.querySelectorAll('.duty-members.drag-over').forEach(z => z.classList.remove('drag-over'));
        });

        document.addEventListener('dragover', e => {
            if (!dragEl) return;
            const memberEl = e.target.closest('.duty-member');
            const zone     = e.target.closest('.duty-members');
            const targetZone = memberEl ? memberEl.closest('.duty-members') : zone;
            if (!targetZone || targetZone.dataset.type !== dragEl.dataset.type) return;

            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            // 同一ゾーン内でメンバーの上にホバーしたらリアルタイムで並び替え
            if (memberEl && memberEl !== dragEl) {
                const srcZone = dragEl.closest('.duty-members');
                if (srcZone === targetZone) {
                    targetZone.insertBefore(dragEl, memberEl);
                }
            }

            document.querySelectorAll('.duty-members.drag-over').forEach(z => z !== targetZone && z.classList.remove('drag-over'));
            targetZone.classList.add('drag-over');
        });

        document.addEventListener('dragleave', e => {
            const zone = e.target.closest('.duty-members');
            if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('drag-over');
        });

        document.addEventListener('drop', e => {
            if (!dragEl) return;
            e.preventDefault();
            const memberEl = e.target.closest('.duty-member');
            const zone     = e.target.closest('.duty-members');
            const targetZone = memberEl ? memberEl.closest('.duty-members') : zone;
            if (!targetZone) return;

            if (memberEl && memberEl !== dragEl) {
                const srcZone = dragEl.closest('.duty-members');
                if (srcZone === targetZone) {
                    // 同一ゾーン: dragover で移動済み → 保存
                    targetZone.classList.remove('drag-over');
                    saveOrder(dragEl.dataset.type, targetZone);
                    return;
                }
                // 別ゾーンのメンバーの上にドロップ
                dropToZone(targetZone, memberEl);
            } else {
                dropToZone(targetZone, null);
            }
        });

        // ── タッチ ドラッグイベント（iPad / スマホ対応） ─────────
        let touchClone = null;
        let touchOffsetX = 0;
        let touchOffsetY = 0;

        document.addEventListener('touchstart', e => {
            const el = e.target.closest('.duty-member');
            if (!el) return;
            dragEl = el;
            el.classList.add('dragging');

            const touch = e.touches[0];
            const rect = el.getBoundingClientRect();
            touchOffsetX = touch.clientX - rect.left;
            touchOffsetY = touch.clientY - rect.top;

            // 視覚的なクローンを作成
            touchClone = el.cloneNode(true);
            touchClone.style.cssText = `
                position: fixed;
                pointer-events: none;
                z-index: 9999;
                opacity: 0.8;
                width: ${rect.width}px;
                left: ${touch.clientX - touchOffsetX}px;
                top:  ${touch.clientY - touchOffsetY}px;
                margin: 0;
            `;
            document.body.appendChild(touchClone);
        }, {
            passive: true
        });

        document.addEventListener('touchmove', e => {
            if (!dragEl || !touchClone) return;
            e.preventDefault();

            const touch = e.touches[0];
            touchClone.style.left = (touch.clientX - touchOffsetX) + 'px';
            touchClone.style.top = (touch.clientY - touchOffsetY) + 'px';

            // クローンを一時非表示にして下の要素を取得
            touchClone.style.display = 'none';
            const el = document.elementFromPoint(touch.clientX, touch.clientY);
            touchClone.style.display = '';

            const zone = el ? el.closest('.duty-members') : null;
            document.querySelectorAll('.duty-members.drag-over').forEach(z => z !== zone && z.classList.remove('drag-over'));
            if (zone && zone.dataset.type === dragEl.dataset.type) {
                zone.classList.add('drag-over');
            }
        }, {
            passive: false
        });

        document.addEventListener('touchend', e => {
            if (!dragEl) return;

            const touch = e.changedTouches[0];
            if (touchClone) { touchClone.remove(); touchClone = null; }

            dragEl.classList.remove('dragging');
            document.querySelectorAll('.duty-members.drag-over').forEach(z => z.classList.remove('drag-over'));

            const el       = document.elementFromPoint(touch.clientX, touch.clientY);
            const memberEl = el ? el.closest('.duty-member') : null;
            const zone     = el ? el.closest('.duty-members') : null;

            if (memberEl && memberEl !== dragEl) {
                const targetZone = memberEl.closest('.duty-members');
                const srcZone    = dragEl.closest('.duty-members');
                if (targetZone && targetZone.dataset.type === dragEl.dataset.type) {
                    if (srcZone === targetZone) {
                        // 同一ゾーン: 指定メンバーの前に挿入して保存
                        targetZone.insertBefore(dragEl, memberEl);
                        saveOrder(dragEl.dataset.type, targetZone);
                    } else {
                        // 別ゾーンのメンバー上にドロップ
                        dropToZone(targetZone, memberEl);
                    }
                }
            } else if (zone) {
                dropToZone(zone, null);
            }

            dragEl = null;
        });
    </script>
</body>

</html>