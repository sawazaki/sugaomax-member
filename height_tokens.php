<?php
require_once __DIR__ . '/includes/db.php';
require_editor();

$db  = get_db();
$msg = '';

// トークン一括生成（未発行の部員のみ）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verify_csrf();
    $members = $db->query("SELECT id FROM members WHERE active=1 AND (height_token IS NULL OR height_token='') ORDER BY grade DESC, number")->fetchAll();
    $stmt = $db->prepare("UPDATE members SET height_token=? WHERE id=?");
    foreach ($members as $m) {
        $stmt->execute([bin2hex(random_bytes(20)), $m['id']]);
    }
    $msg = count($members) . '名分のトークンを発行しました。';
    header('Location: /height_tokens.php?generated=1');
    exit;
}

// 全員再生成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_all') {
    verify_csrf();
    require_admin();
    $members = $db->query("SELECT id FROM members WHERE active=1")->fetchAll();
    $stmt = $db->prepare("UPDATE members SET height_token=? WHERE id=?");
    foreach ($members as $m) {
        $stmt->execute([bin2hex(random_bytes(20)), $m['id']]);
    }
    header('Location: /height_tokens.php?regenerated=1');
    exit;
}

// 個人再生成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_one') {
    verify_csrf();
    $id = (int)$_POST['id'];
    $db->prepare("UPDATE members SET height_token=? WHERE id=?")->execute([bin2hex(random_bytes(20)), $id]);
    header('Location: /height_tokens.php?regenerated_one=1');
    exit;
}

// 締切日の設定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_expires') {
    verify_csrf();
    $expires = $_POST['expires_at'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
        $db->prepare("INSERT INTO app_settings (key, value) VALUES ('height_token_expires_at', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$expires]);
    }
    header('Location: /height_tokens.php?expires_saved=1');
    exit;
}

// 締切日のクリア
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_expires') {
    verify_csrf();
    $db->prepare("DELETE FROM app_settings WHERE key='height_token_expires_at'")->execute();
    header('Location: /height_tokens.php?expires_cleared=1');
    exit;
}

if (isset($_GET['generated']))       $msg = 'トークンを発行しました。';
if (isset($_GET['regenerated']))     $msg = '全員のトークンを再生成しました。古いURLは無効になりました。';
if (isset($_GET['regenerated_one'])) $msg = 'トークンを再生成しました。';
if (isset($_GET['expires_saved']))   $msg = '受付締切日を設定しました。';
if (isset($_GET['expires_cleared'])) $msg = '受付締切日をクリアしました（無期限）。';

$members = $db->query("
    SELECT id, last_name, first_name, grade, number, height, height_token
    FROM members
    WHERE active=1
    ORDER BY grade DESC, gender DESC, number, last_name, first_name
")->fetchAll();

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $scheme . '://' . $_SERVER['HTTP_HOST'];

$no_token = array_filter($members, fn($m) => empty($m['height_token']));

$expires_row = $db->prepare("SELECT value FROM app_settings WHERE key='height_token_expires_at'")->execute([]) ? $db->query("SELECT value FROM app_settings WHERE key='height_token_expires_at'")->fetch() : null;
$expires_at  = $expires_row ? $expires_row['value'] : null;
$today       = date('Y-m-d');
$is_expired  = $expires_at && $today > $expires_at;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>身長更新トークン管理 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .token-table td { font-size: 13px; vertical-align: middle; }
        .token-url { font-size: 11px; color: #64748b; word-break: break-all; }
        .copy-btn { font-size: 11px; padding: 2px 8px; min-width: 60px; }
        @media print {
            .no-print { display: none !important; }
            .card { break-inside: avoid; }
            body { font-size: 12px; }
            .qr-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
            .qr-item { border: 1px solid #e2e8f0; padding: 12px; text-align: center; break-inside: avoid; }
        }
        .qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-top: 16px; }
        .qr-item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; text-align: center; }
        .qr-item .member-name { font-size: 14px; font-weight: bold; margin-bottom: 4px; }
        .qr-item .member-info { font-size: 11px; color: #64748b; margin-bottom: 8px; }
        .qr-item .qr-url-small { font-size: 9px; color: #94a3b8; word-break: break-all; margin-top: 6px; }
        .tab-btn { padding: 8px 20px; border: 1px solid #e2e8f0; background: #f8fafc; cursor: pointer; font-size: 13px; }
        .tab-btn.active { background: #1e3a5f; color: #fff; border-color: #1e3a5f; }
        .tab-btn:first-child { border-radius: 6px 0 0 6px; }
        .tab-btn:last-child { border-radius: 0 6px 6px 0; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        #band-text { width: 100%; height: 200px; font-size: 12px; font-family: monospace; resize: vertical; }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <div class="flex items-center justify-between mb-16 no-print">
        <h1 class="page-title" style="margin-bottom:0">身長更新 トークン管理</h1>
        <div class="flex gap-8">
            <button onclick="window.print()" class="btn btn-outline">印刷（QRコード）</button>
            <?php if (count($no_token) > 0): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="generate">
                    <button type="submit" class="btn btn-primary">トークン一括発行（<?= count($no_token) ?>名）</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success no-print"><?= h($msg) ?></div><?php endif; ?>

    <!-- 受付締切日設定 -->
    <div class="card no-print" style="margin-bottom: 20px; padding: 16px 20px;">
        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <span style="font-size:13px; font-weight:bold; white-space:nowrap;">受付締切日:</span>
            <?php if ($expires_at): ?>
                <span style="font-size:14px;">
                    <?= h(date('Y年n月j日', strtotime($expires_at))) ?>
                    <?php if ($is_expired): ?>
                        <span class="badge" style="background:#fee2e2;color:#dc2626;margin-left:6px;">期限切れ</span>
                    <?php else: ?>
                        <span class="badge badge-blue" style="margin-left:6px;">受付中</span>
                    <?php endif; ?>
                </span>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="clear_expires">
                    <button type="submit" class="btn btn-outline btn-sm">クリア（無期限）</button>
                </form>
            <?php else: ?>
                <span style="font-size:13px; color:#94a3b8;">未設定（無期限）</span>
            <?php endif; ?>
            <form method="post" style="display:inline; margin-left:auto;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_expires">
                <div class="flex gap-8 items-center">
                    <input type="date" name="expires_at" value="<?= h($expires_at ?? '') ?>"
                           style="font-size:13px; padding:4px 8px; border:1px solid #e2e8f0; border-radius:6px;" required>
                    <button type="submit" class="btn btn-primary btn-sm">設定</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (count($no_token) === 0 && count($members) > 0): ?>
    <!-- タブ切り替え -->
    <div class="no-print" style="margin-bottom: 20px;">
        <button class="tab-btn active" onclick="switchTab('list')">URL一覧</button>
        <button class="tab-btn" onclick="switchTab('band')">BAND投稿文</button>
    </div>

    <!-- URL一覧タブ -->
    <div id="tab-list" class="tab-panel active no-print">
        <div class="card">
            <table class="token-table">
                <thead>
                    <tr>
                        <th>学年</th>
                        <th>番号</th>
                        <th>氏名</th>
                        <th>現在の身長</th>
                        <th>更新URL</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <?php if (empty($m['height_token'])) continue; ?>
                    <?php $url = $base_url . '/height_update.php?token=' . urlencode($m['height_token']); ?>
                    <tr>
                        <td><?= h($m['grade']) ?>年</td>
                        <td><?= h($m['number'] ?? '—') ?></td>
                        <td><?= h($m['last_name']) ?>　<?= h($m['first_name']) ?></td>
                        <td><?= $m['height'] ? h($m['height']) . ' cm' : '—' ?></td>
                        <td class="token-url"><?= h($url) ?></td>
                        <td>
                            <div class="flex gap-4">
                                <button class="btn btn-outline copy-btn" onclick="copyUrl(this, <?= h(json_encode($url)) ?>)">コピー</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('このURLが無効になります。再生成しますか？')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="regenerate_one">
                                    <input type="hidden" name="id" value="<?= h($m['id']) ?>">
                                    <button type="submit" class="btn btn-outline copy-btn">再生成</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (is_admin()): ?>
        <div style="margin-top: 16px; text-align: right;">
            <form method="post" onsubmit="return confirm('全員のURLが変わります。本当に再生成しますか？')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="regenerate_all">
                <button type="submit" class="btn btn-outline btn-sm">全員のトークンを再生成する</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- BAND投稿文タブ -->
    <div id="tab-band" class="tab-panel no-print">
        <div class="card">
            <div class="card-title">BAND投稿文</div>
            <p style="font-size:12px;color:#64748b;margin-bottom:12px;">以下のテキストをコピーしてBANDに投稿してください。</p>
            <textarea id="band-text" readonly><?php
$lines = ['【身長更新のお願い】', '以下の各自のリンクから身長をご入力ください。', ''];
foreach ($members as $m) {
    if (empty($m['height_token'])) continue;
    $url = $base_url . '/height_update.php?token=' . urlencode($m['height_token']);
    $lines[] = h($m['last_name']) . ' ' . h($m['first_name']) . '（' . h($m['grade']) . '年）';
    $lines[] = $url;
    $lines[] = '';
}
echo implode("\n", $lines);
?></textarea>
            <div style="margin-top: 10px;">
                <button class="btn btn-primary" onclick="copyBandText()">投稿文をコピー</button>
                <span id="band-copy-msg" style="font-size:12px;color:#16a34a;margin-left:10px;display:none;">コピーしました！</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- QRコード一覧（印刷用・常時表示） -->
    <div id="qr-section">
        <div class="card-title no-print" style="margin-top: 24px; margin-bottom: 8px;">QRコード一覧（印刷用）</div>
        <div class="qr-grid" id="qr-grid">
            <?php foreach ($members as $m): ?>
                <?php if (empty($m['height_token'])) continue; ?>
                <div class="qr-item">
                    <div class="member-name"><?= h($m['last_name']) ?>　<?= h($m['first_name']) ?></div>
                    <div class="member-info"><?= h($m['grade']) ?>年
                        <?php if ($m['number']): ?> / <?= h($m['number']) ?>番<?php endif; ?>
                        <?php if ($m['height']): ?> / 現在 <?= h($m['height']) ?>cm<?php endif; ?>
                    </div>
                    <div class="qr-canvas" id="qr-<?= h($m['id']) ?>"></div>
                    <div class="qr-url-small"><?= h($base_url . '/height_update.php?token=' . urlencode($m['height_token'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="/js/qrcode.min.js"></script>
<script>
<?php foreach ($members as $m): ?>
<?php if (empty($m['height_token'])) continue; ?>
new QRCode(document.getElementById('qr-<?= h($m['id']) ?>'), {
    text: <?= json_encode($base_url . '/height_update.php?token=' . urlencode($m['height_token'])) ?>,
    width: 140, height: 140,
    colorDark: '#1e3a5f', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
});
<?php endforeach; ?>

function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');
}

function copyToClipboard(text, onSuccess) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(onSuccess);
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        onSuccess();
    }
}

function copyUrl(btn, url) {
    copyToClipboard(url, () => {
        const orig = btn.textContent;
        btn.textContent = 'コピー済';
        btn.style.background = '#94a3b8';
        btn.style.borderColor = '#94a3b8';
        btn.style.color = '#fff';
        setTimeout(() => {
            btn.textContent = orig;
            btn.style.background = '';
            btn.style.borderColor = '';
            btn.style.color = '';
        }, 2000);
    });
}

function copyBandText() {
    const text = document.getElementById('band-text').value;
    copyToClipboard(text, () => {
        const msg = document.getElementById('band-copy-msg');
        msg.style.display = 'inline';
        setTimeout(() => msg.style.display = 'none', 2500);
    });
}
</script>
</body>
</html>
