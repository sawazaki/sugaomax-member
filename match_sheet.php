<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db  = get_db();
$id  = (int)($_GET['id'] ?? 0);
$msg = '';

if ($id <= 0) {
    header('Location: /matches.php');
    exit;
}

// sort_order カラムのマイグレーション
$mm_cols = array_column($db->query("PRAGMA table_info(match_members)")->fetchAll(), 'name');
if (!in_array('sort_order', $mm_cols)) {
    $db->exec("ALTER TABLE match_members ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
}

$stmt = $db->prepare("SELECT * FROM matches WHERE id=?");
$stmt->execute([$id]);
$match = $stmt->fetch();
if (!$match) {
    header('Location: /matches.php');
    exit;
}

// 保存（member[] は送信順 = 表示順）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!is_editor()) { http_response_code(403); exit('forbidden'); }
    $db->prepare("DELETE FROM match_members WHERE match_id=?")->execute([$id]);
    $selected  = $_POST['member'] ?? [];
    $positions = $_POST['position'] ?? [];
    $stmt = $db->prepare("INSERT INTO match_members (match_id, member_id, position, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($selected as $i => $mid) {
        $mid = (int)$mid;
        $pos = $positions[$mid] ?? null ?: null;
        $stmt->execute([$id, $mid, $pos, $i]);
    }
    $msg = 'メンバー表を保存しました。';
}

// 全部員（在籍中）
$all_members = $db->query("SELECT * FROM members WHERE active=1 ORDER BY grade DESC, number, last_name, first_name")->fetchAll();

// 登録済みメンバー（sort_order 順）
$saved_rows = $db->prepare("SELECT member_id, position FROM match_members WHERE match_id=? ORDER BY sort_order");
$saved_rows->execute([$id]);
$saved_order = []; // [member_id, ...]
$saved_map   = []; // member_id => position
foreach ($saved_rows->fetchAll() as $row) {
    $saved_order[] = (int)$row['member_id'];
    $saved_map[(int)$row['member_id']] = $row['position'];
}

$positions_list = ['PG', 'SG', 'SF', 'PF', 'C'];

// メンバー表表示用（保存順）
$members_by_id = array_column($all_members, null, 'id');
$sheet_members = [];
foreach ($saved_order as $mid) {
    if (isset($members_by_id[$mid])) {
        $m = $members_by_id[$mid];
        $m['position'] = $saved_map[$mid];
        $sheet_members[] = $m;
    }
}

// 印刷用（保存順のまま20行固定）
$print_rows = array_pad(array_slice($sheet_members, 0, 20), 20, null);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>メンバー表 - <?= h($match['opponent']) ?> - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .drag-handle { cursor: grab; color: #94a3b8; padding: 0 4px; user-select: none; }
        .drag-handle:active { cursor: grabbing; }
        #memberTableBody tr { transition: opacity .15s; }
        #memberTableBody tr.dragging { opacity: .4; }
        #memberTableBody tr.drag-over { border-top: 2px solid #2563eb; }
        #autoSaveStatus { font-size: 12px; padding: 4px 10px; border-radius: 4px; transition: opacity .4s; }
        #autoSaveStatus.saving  { color: #2563eb; }
        #autoSaveStatus.saved   { color: #16a34a; }
        #autoSaveStatus.error   { color: #dc2626; }
        .gender-tabs { display: flex; gap: 4px; margin-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
        .gender-tabs button {
            padding: 5px 14px; border: 1px solid transparent; border-bottom: none;
            border-radius: 5px 5px 0 0; font-size: 13px; font-weight: bold;
            background: none; color: #64748b; cursor: pointer; margin-bottom: -2px;
        }
        .gender-tabs button.active { background: #fff; color: #2563eb; border-color: #e2e8f0; border-bottom-color: #fff; }
        .gender-tabs button:hover:not(.active) { background: #f1f5f9; }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <div class="flex items-center justify-between mb-16 no-print">
        <h1 class="page-title" style="margin-bottom:0">メンバー表作成</h1>
        <div class="flex gap-8">
            <a href="/match_new.php?id=<?= h($id) ?>" class="btn btn-secondary btn-sm">試合情報を編集</a>
            <a href="/matches.php" class="btn btn-secondary btn-sm">試合一覧へ</a>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success no-print"><?= h($msg) ?></div><?php endif; ?>

    <form method="post" id="sheetForm" onsubmit="prepareSubmit()">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div id="memberOrderInputs"></div><!-- JS がここに hidden input を挿入 -->
    <div class="sheet-layout">
        <!-- 左：選手選択パネル -->
        <div class="member-select-panel no-print">
            <h3>選手を選択</h3>
            <?php if (empty($all_members)): ?>
                <p class="text-muted" style="font-size:13px;">部員が登録されていません。</p>
            <?php else: ?>
            <div class="gender-tabs">
                <button type="button" class="active" onclick="filterGender('ALL', this)">ALL</button>
                <button type="button" onclick="filterGender('男子', this)">男子</button>
                <button type="button" onclick="filterGender('女子', this)">女子</button>
            </div>
            <?php foreach ($all_members as $m): ?>
                <?php $checked = isset($saved_map[$m['id']]); ?>
                <div class="member-check-item <?= $checked ? 'checked' : '' ?>" id="item-<?= h($m['id']) ?>" data-gender="<?= h($m['gender'] ?? '') ?>">
                    <input type="checkbox" id="chk-<?= h($m['id']) ?>"
                           value="<?= h($m['id']) ?>"
                           <?= $checked ? 'checked' : '' ?>
                           onchange="toggleMember(<?= h($m['id']) ?>)">
                    <label for="chk-<?= h($m['id']) ?>">
                        <?php if ($m['number'] !== null): ?><strong>#<?= h($m['number']) ?></strong> <?php endif; ?>
                        <?= h(member_name($m)) ?>
                        <span class="text-muted"><?= h($m['grade']) ?>年</span>
                    </label>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top:16px;">
                <button type="submit" class="btn btn-success" style="width:100%">保存する</button>
            </div>
        </div>

        <!-- 右：メンバー表プレビュー -->
        <div class="sheet-preview" id="sheetPreview">
            <div class="sheet-header">
                <h2>菅生マックス メンバー表</h2>
                <div class="sheet-meta">
                    <span>📅 <?= h($match['match_date']) ?></span>
                    <span>vs <?= h($match['opponent']) ?></span>
                    <?php if ($match['venue']): ?><span>📍 <?= h($match['venue']) ?></span><?php endif; ?>
                    <?php if ($match['match_type']): ?><span class="badge badge-blue"><?= h($match['match_type']) ?></span><?php endif; ?>
                </div>
                <?php if ($match['note']): ?>
                    <div class="sheet-note">📌 <?= nl2br(h($match['note'])) ?></div>
                <?php endif; ?>
            </div>

            <div id="memberTableWrap">
                <div class="no-members" id="noMemberMsg" <?= !empty($sheet_members) ? 'style="display:none"' : '' ?>>
                    選手を選択してください
                </div>
                <table class="sheet-table" id="memberTable" <?= empty($sheet_members) ? 'style="display:none"' : '' ?>>
                    <thead>
                        <tr>
                            <th style="width:24px"></th>
                            <th style="width:48px">#</th>
                            <th>氏名</th>
                        </tr>
                    </thead>
                    <tbody id="memberTableBody">
                    <?php foreach ($sheet_members as $m): ?>
                        <tr data-id="<?= h($m['id']) ?>" draggable="true">
                            <td class="drag-handle" title="ドラッグで並べ替え">☰</td>
                            <td><?= $m['number'] !== null ? h($m['number']) : '—' ?></td>
                            <td><?= h(member_name($m)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-16 text-right no-print" style="display:flex;align-items:center;justify-content:flex-end;gap:12px;">
                <span id="autoSaveStatus"></span>
                <button type="button" class="btn btn-primary no-print-sp" onclick="window.print()">印刷</button>
            </div>
        </div>
    </div>
    </form>

    <!-- 印刷専用：公式メンバー表 4枚（Excelテンプレート準拠・A4縦2×2） -->
    <div class="print-only official-sheet-grid">
        <?php for ($copy = 0; $copy < 4; $copy++): ?>
        <div class="official-sheet-cell">
            <table class="official-table">
                <colgroup>
                    <col class="col-no">
                    <col class="col-name">
                    <col class="col-jersey">
                    <col class="col-period"><col class="col-period"><col class="col-period"><col class="col-period">
                    <col class="col-foul"><col class="col-foul"><col class="col-foul"><col class="col-foul"><col class="col-foul">
                </colgroup>
                <thead>
                    <!-- Excelテンプレート行1〜3: 1+3+3+5=12列 -->
                    <tr class="team-header-row">
                        <td class="team-label-cell">チーム：</td>
                        <td rowspan="3" colspan="3" class="team-name-cell">菅生マックス</td>
                        <td rowspan="3" colspan="3" class="team-paren-cell">（　　　）</td>
                        <th colspan="5" class="timeout-header-cell">タイムアウト</th>
                    </tr>
                    <tr class="team-header-row">
                        <td class="team-sub-cell">Team</td>
                        <td class="timeout-mark-cell">①</td>
                        <td class="timeout-mark-cell">②</td>
                        <td class="timeout-mark-cell">③</td>
                        <td class="timeout-mark-cell">④</td>
                        <td class="timeout-mark-cell">OT</td>
                    </tr>
                    <tr class="team-header-row">
                        <td></td>
                        <td></td><td></td><td></td><td></td><td></td>
                    </tr>
                    <tr class="col-header-row">
                        <th rowspan="2" class="h-no">№</th>
                        <th rowspan="2" class="h-name">選手氏名<br><span class="h-name-sub">Players</span></th>
                        <th rowspan="2" class="h-jersey">No.</th>
                        <th colspan="4" class="h-period-group">出 場 時 限</th>
                        <th colspan="5" class="h-foul-group">フ ァ ウ ル</th>
                    </tr>
                    <tr class="col-header-row">
                        <th class="h-sub">①</th>
                        <th class="h-sub">②</th>
                        <th class="h-sub">③</th>
                        <th class="h-sub">④</th>
                        <th class="h-sub">１</th>
                        <th class="h-sub">２</th>
                        <th class="h-sub">３</th>
                        <th class="h-sub">４</th>
                        <th class="h-sub">５</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($print_rows as $i => $m): ?>
                    <tr class="player-row">
                        <td class="cell-no"><?= $i + 1 ?></td>
                        <td class="cell-name"><?= $m ? h(member_name($m)) : '' ?></td>
                        <td class="cell-jersey"><?= ($m && $m['number'] !== null) ? h($m['number']) : '' ?></td>
                        <td></td><td></td><td></td><td></td>
                        <td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="coach-row">
                        <td colspan="7" class="coach-cell">コーチ：　<?= h($match['coach'] ?? '') ?></td>
                        <td></td><td></td><td></td><td></td><td></td>
                    </tr>
                    <tr class="coach-row">
                        <td colspan="7" class="coach-cell">A. コーチ：　<?= h($match['assistant_coach'] ?? '') ?></td>
                        <td></td><td></td><td></td><td></td><td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endfor; ?>
    </div>
</div>

<script>
const membersData = <?= json_encode(array_column($all_members, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

// 表示順を管理する配列（保存済み順で初期化）
let orderedIds = <?= json_encode($saved_order) ?>;

// ─── チェックボックス操作 ───────────────────────────
function toggleMember(id) {
    const chk  = document.getElementById('chk-' + id);
    const item = document.getElementById('item-' + id);
    if (chk.checked) {
        item.classList.add('checked');
        if (!orderedIds.includes(id)) orderedIds.push(id);
    } else {
        item.classList.remove('checked');
        orderedIds = orderedIds.filter(x => x !== id);
    }
    renderPreview();
}

// ─── プレビュー描画 ────────────────────────────────
function renderPreview() {
    const tbody = document.getElementById('memberTableBody');
    const table = document.getElementById('memberTable');
    const noMsg = document.getElementById('noMemberMsg');

    if (orderedIds.length === 0) {
        table.style.display = 'none';
        noMsg.style.display = '';
        return;
    }
    table.style.display = '';
    noMsg.style.display = 'none';

    tbody.innerHTML = orderedIds.map(id => {
        const m = membersData[id];
        if (!m) return '';
        return `<tr data-id="${id}" draggable="true">
            <td class="drag-handle" title="ドラッグで並べ替え">☰</td>
            <td>${m.number !== null ? e(m.number) : '—'}</td>
            <td>${e((m.last_name || '') + (m.first_name ? '\u3000' + m.first_name : ''))}</td>
        </tr>`;
    }).join('');

    initDragDrop();
}

// ─── ドラッグ&ドロップ ─────────────────────────────
let dragSrc = null;

function initDragDrop() {
    document.querySelectorAll('#memberTableBody tr').forEach(row => {
        row.addEventListener('dragstart', onDragStart);
        row.addEventListener('dragover',  onDragOver);
        row.addEventListener('dragleave', onDragLeave);
        row.addEventListener('drop',      onDrop);
        row.addEventListener('dragend',   onDragEnd);
    });
}

function onDragStart(e) {
    dragSrc = this;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    document.querySelectorAll('#memberTableBody tr').forEach(r => r.classList.remove('drag-over'));
    if (this !== dragSrc) this.classList.add('drag-over');
}

function onDragLeave() {
    this.classList.remove('drag-over');
}

function onDrop(e) {
    e.preventDefault();
    if (!dragSrc || this === dragSrc) return;
    const srcId  = parseInt(dragSrc.dataset.id);
    const destId = parseInt(this.dataset.id);
    const si = orderedIds.indexOf(srcId);
    const di = orderedIds.indexOf(destId);
    orderedIds.splice(si, 1);
    orderedIds.splice(di, 0, srcId);
    renderPreview();
    autoSave();
}

function onDragEnd() {
    document.querySelectorAll('#memberTableBody tr').forEach(r => {
        r.classList.remove('dragging', 'drag-over');
    });
    dragSrc = null;
}

// ─── 自動保存 ─────────────────────────────────────
const csrfToken = document.querySelector('input[name="csrf_token"]').value;
let autoSaveTimer = null;

function autoSave() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(async () => {
        const status = document.getElementById('autoSaveStatus');
        status.textContent = '保存中…';
        status.className = 'saving';
        const body = new URLSearchParams();
        body.append('csrf_token', csrfToken);
        orderedIds.forEach(id => body.append('member[]', id));
        try {
            const res = await fetch(location.href, { method: 'POST', body });
            if (!res.ok) throw new Error(res.status);
            status.textContent = '保存しました';
            status.className = 'saved';
            setTimeout(() => { status.textContent = ''; status.className = ''; }, 2000);
        } catch {
            status.textContent = '保存に失敗しました';
            status.className = 'error';
        }
    }, 300);
}

// ─── フォーム送信：順番通りに hidden input を挿入 ──
function prepareSubmit() {
    const wrap = document.getElementById('memberOrderInputs');
    wrap.innerHTML = '';
    orderedIds.forEach(id => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'member[]';
        inp.value = id;
        wrap.appendChild(inp);
    });
}

// ─── 性別タブフィルター ────────────────────────────
function filterGender(gender, btn) {
    document.querySelectorAll('.gender-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.member-check-item').forEach(item => {
        if (gender === 'ALL' || item.dataset.gender === gender) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function e(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 初期描画（保存済みデータがある場合）
if (orderedIds.length > 0) initDragDrop();
</script>
</body>
</html>
