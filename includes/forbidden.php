<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>権限エラー - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php if (!empty($_SESSION['logged_in'])) require __DIR__ . '/nav.php'; ?>
    <div class="container" style="text-align:center;padding-top:60px;">
        <p style="font-size:48px;margin-bottom:16px;">🔒</p>
        <h2 style="color:#1e3a5f;margin-bottom:8px;">権限がありません</h2>
        <p style="color:#64748b;margin-bottom:24px;">この操作を行う権限がありません。</p>
        <a href="/index.php" class="btn btn-primary">ダッシュボードへ戻る</a>
    </div>
</body>
</html>
