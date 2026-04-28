<?php
require_once __DIR__ . '/includes/db.php';
require_editor();

$db    = get_db();
$msg   = '';
$error = '';

// base64url (URL安全, パディングなし, 16文字/96bit) で一意な短縮コードを生成
function generate_short_code(PDO $db): string {
    for ($i = 0; $i < 10; $i++) {
        $code = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $stmt = $db->prepare("SELECT 1 FROM members WHERE height_short_code=?");
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) return $code;
    }
    throw new RuntimeException('短縮コードの生成に失敗しました。再試行してください。');
}

// トークン一括生成（未発行の部員のみ）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verify_csrf();
    $members = $db->query("SELECT id FROM members WHERE active=1 AND (height_token IS NULL OR height_token='') ORDER BY grade DESC, number")->fetchAll();
    $stmt = $db->prepare("UPDATE members SET height_token=?, height_short_code=? WHERE id=?");
    foreach ($members as $m) {
        $stmt->execute([bin2hex(random_bytes(20)), generate_short_code($db), $m['id']]);
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
    $stmt = $db->prepare("UPDATE members SET height_token=?, height_short_code=? WHERE id=?");
    foreach ($members as $m) {
        $stmt->execute([bin2hex(random_bytes(20)), generate_short_code($db), $m['id']]);
    }
    header('Location: /height_tokens.php?regenerated=1');
    exit;
}

// 個人再生成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_one') {
    verify_csrf();
    $id = (int)$_POST['id'];
    $db->prepare("UPDATE members SET height_token=?, height_short_code=? WHERE id=?")->execute([bin2hex(random_bytes(20)), generate_short_code($db), $id]);
    header('Location: /height_tokens.php?regenerated_one=1');
    exit;
}

// 締切日の設定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_expires') {
    verify_csrf();
    $expires = $_POST['expires_at'] ?? '';
    $dt = DateTime::createFromFormat('Y-m-d', $expires);
    if ($dt && $dt->format('Y-m-d') === $expires) {
        $db->prepare("INSERT INTO app_settings (key, value) VALUES ('height_token_expires_at', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$expires]);
        header('Location: /height_tokens.php?expires_saved=1');
        exit;
    }
    $error = '有効な締切日を入力してください。';
}

// 締切日のクリア
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_expires') {
    verify_csrf();
    $db->prepare("DELETE FROM app_settings WHERE key='height_token_expires_at'")->execute();
    header('Location: /height_tokens.php?expires_cleared=1');
    exit;
}

// height_token はあるが height_short_code がない部員に短縮コードを自動付与
$need_short = $db->query("SELECT id FROM members WHERE active=1 AND height_token IS NOT NULL AND height_token!='' AND (height_short_code IS NULL OR height_short_code='')")->fetchAll();
if ($need_short) {
    $stmt = $db->prepare("UPDATE members SET height_short_code=? WHERE id=?");
    foreach ($need_short as $m) {
        $stmt->execute([generate_short_code($db), $m['id']]);
    }
}

if (isset($_GET['generated']))       $msg = 'トークンを発行しました。';
if (isset($_GET['regenerated']))     $msg = '全員のトークンを再生成しました。古いURLは無効になりました。';
if (isset($_GET['regenerated_one'])) $msg = 'トークンを再生成しました。';
if (isset($_GET['expires_saved']))   $msg = '受付締切日を設定しました。';
if (isset($_GET['expires_cleared'])) $msg = '受付締切日をクリアしました（無期限）。';

$members = $db->query("
    SELECT id, last_name, first_name, grade, number, height, height_token, height_short_code
    FROM members
    WHERE active=1
    ORDER BY grade DESC, gender DESC, number, last_name, first_name
")->fetchAll();

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $scheme . '://' . $_SERVER['HTTP_HOST'];

$no_token = array_filter($members, fn($m) => empty($m['height_token']) || empty($m['height_short_code']));
$has_url  = array_filter($members, fn($m) => !empty($m['height_short_code']));

function member_url(array $m, string $base): string {
    if (!empty($m['height_short_code'])) {
        return $base . '/h.php?c=' . urlencode($m['height_short_code']);
    }
    return '';
}

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
        .token-table td {
            font-size: 13px;
            vertical-align: middle;
        }

        .token-url {
            font-size: 11px;
            color: #64748b;
            word-break: break-all;
        }

        .copy-btn {
            font-size: 11px;
            padding: 2px 8px;
            min-width: 60px;
        }

        #band-text {
            width: 100%;
            height: 200px;
            font-size: 12px;
            font-family: monospace;
            resize: vertical;
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16 no-print">
            <h1 class="page-title" style="margin-bottom:0">身長更新 トークン管理</h1>
            <div class="flex gap-8">
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
        <?php if ($error): ?><div class="alert alert-error no-print"><?= h($error) ?></div><?php endif; ?>

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

        <?php if (count($has_url) > 0): ?>
            <!-- BAND投稿文 -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-title">BAND投稿文</div>
                <p style="font-size:12px;color:#64748b;margin-bottom:12px;">以下のテキストをコピーしてBANDに投稿してください。</p>
                <textarea id="band-text" readonly><?php
                    $deadline_line = $expires_at ? '※期限は' . date('Y年n月j日', strtotime($expires_at)) . 'とさせていただきます。' : '';
                    $lines = array_filter([
                        '【お子さまの身長入力のお願い】',
                        '毎年度作成している川崎市ミニバスケット連盟の冊子に掲載する名簿用に、お子さまの身長を教えてください。',
                        '以下の各自のリンクから身長をご入力ください。',
                        $deadline_line,
                    ], fn($l) => $l !== '');
                    $current_grade = null;
                    foreach ($members as $m) {
                        if (member_url($m, $base_url) === '') continue;
                        if ($m['grade'] !== $current_grade) {
                            $lines[] = '';
                            $lines[] = '■ ' . h($m['grade']) . '年生';
                            $current_grade = $m['grade'];
                        }
                        $lines[] = h($m['last_name']) . ' ' . h($m['first_name']);
                        $lines[] = member_url($m, $base_url);
                        $lines[] = '';
                    }
                    echo implode("\n", $lines);
                ?></textarea>
                <div style="margin-top: 10px;">
                    <button class="btn btn-primary" onclick="copyBandText()">投稿文をコピー</button>
                    <span id="band-copy-msg" style="font-size:12px;color:#16a34a;margin-left:10px;display:none;">コピーしました！</span>
                </div>
            </div>

            <!-- URL一覧 -->
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
                            <?php if (member_url($m, $base_url) === '') continue; ?>
                            <?php $url = member_url($m, $base_url); ?>
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
        <?php endif; ?>
    <script>
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