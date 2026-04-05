<?php
require_once __DIR__ . '/includes/db.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: /index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($password === APP_PASSWORD) {
        $_SESSION['logged_in'] = true;
        header('Location: /index.php');
        exit;
    } else {
        $error = 'パスワードが正しくありません。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-title"><img src="/images/sugaomax-logo.svg" height="100" alt="菅生マックス"></div>
            <div class="login-subtitle">チーム管理システム</div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="password">パスワード</label>
                    <input type="password" id="password" name="password" class="form-control" autofocus required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">ログイン</button>
            </form>
        </div>
    </div>
</body>

</html>