<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$config_path = __DIR__ . '/data/config.php';
$msg   = '';
$error = '';

// ── config.php書き込みヘルパー ────────────────────────────────
function write_config(string $path, string $app_hash, string $enroll_hash, string $token, int $active): bool {
    $content = "<?php\n// このファイルはGitignore済みです。直接編集しないでください。\n"
        . "define('APP_PASSWORD_HASH', "        . var_export($app_hash,    true) . ");\n"
        . "define('ENROLLMENT_PASSWORD_HASH', " . var_export($enroll_hash, true) . ");\n"
        . "define('ENROLLMENT_TOKEN', "         . var_export($token,       true) . ");\n"
        . "define('ENROLLMENT_ACTIVE', "        . $active                        . ");\n";
    return file_put_contents($path, $content) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── 入部届けパスワード変更 ──────────────────────────────
    if ($action === 'save_password') {
        $pw  = $_POST['enrollment_password'] ?? '';
        $pw2 = $_POST['enrollment_password2'] ?? '';

        if (strlen($pw) < 8) {
            $error = 'パスワードは8文字以上で設定してください。';
        } elseif ($pw !== $pw2) {
            $error = 'パスワードが一致しません。';
        } else {
            $new_hash   = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            $new_token  = bin2hex(random_bytes(32));
            $cur_active = defined('ENROLLMENT_ACTIVE') ? (int)ENROLLMENT_ACTIVE : 1;
            if (write_config($config_path, APP_PASSWORD_HASH, $new_hash, $new_token, $cur_active)) {
                header('Location: /settings.php?saved=1');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }

    // ── トークンのみ再生成 ──────────────────────────────────
    if ($action === 'regenerate_token') {
        if (!defined('ENROLLMENT_PASSWORD_HASH')) {
            $error = '先に入部届けパスワードを設定してください。';
        } else {
            $new_token  = bin2hex(random_bytes(32));
            $cur_active = defined('ENROLLMENT_ACTIVE') ? (int)ENROLLMENT_ACTIVE : 1;
            if (write_config($config_path, APP_PASSWORD_HASH, ENROLLMENT_PASSWORD_HASH, $new_token, $cur_active)) {
                header('Location: /settings.php?regenerated=1');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }

    // ── 受付状態の切り替え ──────────────────────────────────
    if ($action === 'toggle_active') {
        if (!defined('ENROLLMENT_PASSWORD_HASH')) {
            $error = '先に入部届けパスワードを設定してください。';
        } else {
            $cur_active = defined('ENROLLMENT_ACTIVE') ? (int)ENROLLMENT_ACTIVE : 1;
            $new_active = $cur_active === 1 ? 0 : 1;
            $cur_token  = defined('ENROLLMENT_TOKEN') ? ENROLLMENT_TOKEN : bin2hex(random_bytes(32));
            if (write_config($config_path, APP_PASSWORD_HASH, ENROLLMENT_PASSWORD_HASH, $cur_token, $new_active)) {
                header('Location: /settings.php?toggled=' . $new_active);
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }
}

if (!empty($_GET['saved']))       $msg = '入部届けパスワードとQRコードを更新しました。';
if (!empty($_GET['regenerated'])) $msg = 'QRコード（トークン）を再生成しました。古いQRコードは無効になりました。';
if (isset($_GET['toggled']))      $msg = $_GET['toggled'] === '1' ? '入部届けの受付を開始しました。' : '入部届けの受付を停止しました。';

$enrollment_set    = defined('ENROLLMENT_PASSWORD_HASH') && ENROLLMENT_PASSWORD_HASH !== '';
$token_set         = defined('ENROLLMENT_TOKEN') && ENROLLMENT_TOKEN !== '';
$enrollment_active = defined('ENROLLMENT_ACTIVE') ? (int)ENROLLMENT_ACTIVE === 1 : true;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];
$qr_url = ($token_set && $enrollment_active)
    ? $scheme . '://' . $host . '/enrollment.php?token=' . urlencode(ENROLLMENT_TOKEN)
    : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>設定 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: bold;
        }
        .status-badge.active   { background: #dcfce7; color: #16a34a; }
        .status-badge.inactive { background: #fee2e2; color: #dc2626; }
        .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
        }
        .status-badge.active   .status-dot { background: #16a34a; }
        .status-badge.inactive .status-dot { background: #dc2626; }
        #qr-canvas { display: block; margin: 12px auto 0; }
        .qr-url-box {
            font-size: 11px;
            word-break: break-all;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            margin-top: 10px;
            color: #475569;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <div class="flex items-center justify-between mb-16">
        <h1 class="page-title" style="margin-bottom:0">設定</h1>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <!-- 受付状態カード（全幅） -->
    <div class="card" style="max-width:800px;margin-bottom:24px;">
        <div class="card-title">入部届け 受付状態</div>
        <?php if ($enrollment_set): ?>
        <div class="flex items-center gap-8" style="flex-wrap:wrap;">
            <span class="status-badge <?= $enrollment_active ? 'active' : 'inactive' ?>">
                <span class="status-dot"></span>
                <?= $enrollment_active ? '受付中' : '停止中' ?>
            </span>
            <form method="post" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_active">
                <?php if ($enrollment_active): ?>
                    <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('入部届けの受付を停止します。保護者はフォームにアクセスできなくなります。よろしいですか？')">
                        受付を停止する
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-sm">
                        受付を開始する
                    </button>
                <?php endif; ?>
            </form>
        </div>
        <?php else: ?>
        <p style="font-size:13px;color:#dc2626;margin:0;">パスワードが未設定のため受付停止中です。下のフォームからパスワードを設定してください。</p>
        <?php endif; ?>
    </div>

    <!-- パスワード・QRコード（横並び） -->
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;max-width:800px;">

        <!-- パスワード設定フォーム -->
        <div class="card" style="flex:1;min-width:260px;">
            <div class="card-title">入部届けパスワード</div>
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                保護者が入部届けフォームにパスワードを手入力する際に使用します。<br>
                現在の状態：<?= $enrollment_set
                    ? '<span style="color:#16a34a;font-weight:bold;">設定済み</span>'
                    : '<span style="color:#dc2626;font-weight:bold;">未設定</span>' ?>
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_password">
                <div class="form-group">
                    <label>新しいパスワード（8文字以上）</label>
                    <input type="password" name="enrollment_password" class="form-control" required minlength="8">
                </div>
                <div class="form-group">
                    <label>パスワード（確認）</label>
                    <input type="password" name="enrollment_password2" class="form-control" required minlength="8">
                </div>
                <div class="flex gap-8 mt-8">
                    <button type="submit" class="btn btn-primary">保存する</button>
                </div>
            </form>

            <?php if ($enrollment_set): ?>
            <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0;">
            <div style="font-size:13px;color:#64748b;margin-bottom:10px;">
                QRコードを無効化して新しいQRコードを発行します。パスワードは変わりません。
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="regenerate_token">
                <button type="submit" class="btn btn-outline btn-sm"
                    onclick="return confirm('現在のQRコードが無効になります。よろしいですか？')">
                    QRコードを再生成する
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- QRコード -->
        <?php if ($qr_url): ?>
        <div class="card" style="flex:0 0 220px;text-align:center;">
            <div class="card-title">入部届けQRコード</div>
            <p style="font-size:12px;color:#64748b;margin-bottom:8px;">
                このQRコードを保護者に共有してください。<br>
                読み取ると自動でフォームが開きます。
            </p>
            <div id="qr-canvas"></div>
            <div class="qr-url-box"><?= h($qr_url) ?></div>
            <button onclick="window.print()" class="btn btn-outline btn-sm" style="margin-top:12px;">印刷する</button>
        </div>
        <?php elseif ($token_set): ?>
        <div class="card" style="flex:0 0 220px;text-align:center;">
            <div class="card-title">入部届けQRコード</div>
            <p style="font-size:13px;color:#94a3b8;padding:24px 0;">
                受付停止中のため<br>QRコードは無効です。
            </p>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($qr_url): ?>
<script src="/js/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr-canvas'), {
    text: <?= json_encode($qr_url) ?>,
    width: 180,
    height: 180,
    colorDark: '#1e3a5f',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
});
</script>
<?php endif; ?>
</body>
</html>
