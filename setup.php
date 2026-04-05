<?php
define('DB_PATH', __DIR__ . '/data/minibasket.db');
session_start();

$config_path = __DIR__ . '/data/config.php';

// すでに設定済みならトップへ
if (file_exists($config_path)) {
    header('Location: /index.php');
    exit;
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';

    if (strlen($pw) < 8) {
        $error = 'パスワードは8文字以上で設定してください。';
    } elseif ($pw !== $pw2) {
        $error = 'パスワードが一致しません。';
    } else {
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
        $data_dir = __DIR__ . '/data';
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }
        $content = "<?php\n// このファイルはGitignore済みです。直接編集しないでください。\ndefine('APP_PASSWORD_HASH', " . var_export($hash, true) . ");\n";
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
                    <div class="form-group">
                        <label for="password">パスワード（8文字以上）</label>
                        <input type="password" id="password" name="password" class="form-control" autofocus required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="password2">パスワード（確認）</label>
                        <input type="password" id="password2" name="password2" class="form-control" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">設定する</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
