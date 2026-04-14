<?php
// data/ ディレクトリのパスを自動検出（db.php と同じロジック）
$_p = dirname(__DIR__);
define('DATA_DIR',
    basename($_p) === 'public_html'
        ? dirname($_p) . '/data'   // Xserver: sugaomax.com/data/
        : __DIR__ . '/data'        // 開発環境: /var/www/html/data/
);
unset($_p);
define('DB_PATH', DATA_DIR . '/minibasket.db');
$session_secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $session_secure,
    'samesite' => 'Lax',
]);
session_start();
header('X-Robots-Tag: noindex, nofollow, noarchive');

$config_path = DATA_DIR . '/config.php';

// すでに設定済みならトップへ
if (file_exists($config_path)) {
    header('Location: /index.php');
    exit;
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($session_token) || $session_token === '' || !hash_equals($session_token, $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pw  = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';
    $epw = $_POST['enrollment_password'] ?? '';

    if (strlen($pw) < 8) {
        $error = 'パスワードは8文字以上で設定してください。';
    } elseif ($pw !== $pw2) {
        $error = 'パスワードが一致しません。';
    } elseif ($epw !== '' && strlen($epw) < 8) {
        $error = '入部届けパスワードは8文字以上で設定してください。';
    } else {
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
        $data_dir = DATA_DIR;
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }
        $content = "<?php\n// このファイルはGitignore済みです。直接編集しないでください。\ndefine('APP_PASSWORD_HASH', " . var_export($hash, true) . ");\n";
        if ($epw !== '') {
            $ehash = password_hash($epw, PASSWORD_BCRYPT, ['cost' => 12]);
            $content .= "define('ENROLLMENT_PASSWORD_HASH', " . var_export($ehash, true) . ");\n";
        }
        if (file_put_contents($config_path, $content) !== false) {
            $success = true;
        } else {
            $error = 'ファイルの書き込みに失敗しました。data/ ディレクトリの書き込み権限を確認してください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>初期セットアップ - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-title"><img src="/images/sugaomax-logo.svg" height="100" alt="菅生マックス"></div>
            <div class="login-subtitle">初期セットアップ</div>

            <?php if ($success): ?>
                <div class="alert alert-success">パスワードを設定しました。</div>
                <a href="/login.php" class="btn btn-primary" style="width:100%;margin-top:8px;">ログインへ</a>
            <?php else: ?>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                    ログイン用パスワードを設定してください。<br>
                    パスワードはハッシュ化されて保存されます。
                </p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="form-group">
                        <label for="password">パスワード（8文字以上）</label>
                        <input type="password" id="password" name="password" class="form-control" autofocus required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="password2">パスワード（確認）</label>
                        <input type="password" id="password2" name="password2" class="form-control" required minlength="8">
                    </div>
                    <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0;">
                    <p style="font-size:12px;color:#94a3b8;margin-bottom:12px;">入部届けパスワード（任意）— 後から設定画面でも変更できます</p>
                    <div class="form-group">
                        <label for="enrollment_password">入部届けパスワード（8文字以上）</label>
                        <input type="password" id="enrollment_password" name="enrollment_password" class="form-control" minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">設定する</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
