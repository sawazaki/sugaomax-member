<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();

// ── AJAX: 当番更新 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_duty') {
    verify_csrf();
    header('Content-Type: application/json');

    $type      = $_POST['type']      ?? '';
    $member_id = (int)($_POST['member_id'] ?? 0);
    $duty_key  = $_POST['duty_key']  ?? '';

    if ($type === 'practice') {
        $valid_keys = array_merge([''], range('A', 'K'));
        $col = 'practice_duty';
    } elseif ($type === 'match') {
        $valid_keys = ['', '1', '2', '3', '4'];
        $col = 'match_duty';
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
    $db->prepare("UPDATE members SET {$col}=? WHERE id=? AND active=1")->execute([$val, $member_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── 表示データ取得 ────────────────────────────────────────
$practice_groups = [];
foreach (range('A', 'K') as $key) { $practice_groups[$key] = []; }
foreach ($db->query("SELECT * FROM members WHERE active=1 AND practice_duty IS NOT NULL AND practice_duty != '' AND has_sibling = 0 ORDER BY grade DESC, last_name, first_name")->fetchAll() as $m) {
    if (isset($practice_groups[$m['practice_duty']])) $practice_groups[$m['practice_duty']][] = $m;
}

$match_groups = [];
foreach (['1','2','3','4'] as $key) { $match_groups[$key] = []; }
foreach ($db->query("SELECT * FROM members WHERE active=1 AND match_duty IS NOT NULL AND match_duty != '' AND has_sibling = 0 ORDER BY grade DESC, last_name, first_name")->fetchAll() as $m) {
    if (isset($match_groups[$m['match_duty']])) $match_groups[$m['match_duty']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>当番一覧 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .duty-section { margin-bottom: 40px; }
        .duty-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
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
        .duty-table tr:nth-child(even) td { background: #f8fafc; }
        .duty-table tr:nth-child(even) td.drop-zone { background: #f8fafc; }

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
        .duty-member:active { cursor: grabbing; }
        .duty-member.dragging { opacity: 0.35; box-shadow: none; }
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
        #save-status.saving  { color: #2563eb; }
        #save-status.saved   { color: #16a34a; }
        #save-status.error   { color: #dc2626; }
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
    function render_duty_table(array $groups, string $type): void {
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
                                    <?= h($m['last_name']) ?>
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
        </tbody>
    </table>
    </div>
    <?php } ?>

    <!-- 練習当番 -->
    <div class="duty-section">
        <div class="card">
            <div class="card-title">練習当番</div>
            <?php render_duty_table($practice_groups, 'practice'); ?>
        </div>
    </div>

    <!-- 試合当番 -->
    <div class="duty-section">
        <div class="card">
            <div class="card-title">試合当番</div>
            <?php render_duty_table($match_groups, 'match'); ?>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const statusEl   = document.getElementById('save-status');
let dragEl = null;

// ── ステータス表示 ───────────────────────────────────────
let statusTimer = null;
function showStatus(msg, cls) {
    clearTimeout(statusTimer);
    statusEl.textContent = msg;
    statusEl.className = cls;
    if (cls === 'saved') {
        statusTimer = setTimeout(() => { statusEl.textContent = ''; statusEl.className = ''; }, 2000);
    }
}

// ── カウント更新 ─────────────────────────────────────────
function updateCount(type, key) {
    const zone  = document.querySelector(`.duty-members[data-type="${type}"][data-key="${key}"]`);
    const count = zone.querySelectorAll('.duty-member').length;
    const cell  = document.querySelector(`.duty-count[data-type="${type}"][data-key="${key}"]`);
    cell.textContent = count > 0 ? count + '名' : '—';
}

// ── 空表示の制御 ─────────────────────────────────────────
function syncEmpty(zone) {
    const placeholder = zone.querySelector('.duty-empty');
    const hasMembers  = zone.querySelectorAll('.duty-member').length > 0;
    if (placeholder) {
        placeholder.style.display = hasMembers ? 'none' : '';
    } else if (!hasMembers) {
        const span = document.createElement('span');
        span.className = 'duty-empty';
        span.textContent = '未設定';
        zone.appendChild(span);
    }
}

// ── ドラッグイベント ─────────────────────────────────────
document.addEventListener('dragstart', e => {
    const el = e.target.closest('.duty-member');
    if (!el) return;
    dragEl = el;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
});

document.addEventListener('dragend', e => {
    if (dragEl) { dragEl.classList.remove('dragging'); dragEl = null; }
    document.querySelectorAll('.duty-members.drag-over').forEach(z => z.classList.remove('drag-over'));
});

document.addEventListener('dragover', e => {
    const zone = e.target.closest('.duty-members');
    if (!zone || !dragEl) return;
    // 同じタイプのみ受け付ける
    if (zone.dataset.type !== dragEl.dataset.type) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    document.querySelectorAll('.duty-members.drag-over').forEach(z => z !== zone && z.classList.remove('drag-over'));
    zone.classList.add('drag-over');
});

document.addEventListener('dragleave', e => {
    const zone = e.target.closest('.duty-members');
    if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('drag-over');
});

document.addEventListener('drop', e => {
    const zone = e.target.closest('.duty-members');
    if (!zone || !dragEl) return;
    if (zone.dataset.type !== dragEl.dataset.type) return;
    e.preventDefault();
    zone.classList.remove('drag-over');

    const srcKey  = dragEl.closest('.duty-members').dataset.key;
    const destKey = zone.dataset.key;
    if (srcKey === destKey) return; // 同じグループは何もしない

    const srcZone = dragEl.closest('.duty-members');

    // DOM移動（空プレースホルダーの前に挿入）
    const placeholder = zone.querySelector('.duty-empty');
    zone.insertBefore(dragEl, placeholder || null);

    syncEmpty(srcZone);
    syncEmpty(zone);
    updateCount(dragEl.dataset.type, srcKey);
    updateCount(dragEl.dataset.type, destKey);

    // AJAX保存
    const memberId = dragEl.dataset.memberId;
    const type     = dragEl.dataset.type;
    showStatus('保存中…', 'saving');

    fetch('/duty.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action:      'update_duty',
            type:        type,
            member_id:   memberId,
            duty_key:    destKey,
            csrf_token:  CSRF_TOKEN
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
});
</script>
</body>
</html>
