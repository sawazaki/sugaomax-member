<?php
require_once __DIR__ . '/includes/db.php';

$db   = get_db();
$code = trim($_GET['c'] ?? '');
$done  = false;
$error = '';

if ($code === '') {
    http_response_code(404);
    exit('無効なURLです。');
}

$member = $db->prepare("SELECT id, last_name, first_name, grade, height FROM members WHERE active=1 AND height_short_code=? LIMIT 1");
$member->execute([$code]);
$member = $member->fetch();

if (!$member) {
    http_response_code(404);
    exit('URLが無効です。チームの管理者にご連絡ください。');
}

// 有効期限チェック
$expires_row = $db->query("SELECT value FROM app_settings WHERE key='height_token_expires_at'")->fetch();
$expires_at  = $expires_row ? $expires_row['value'] : null;
$is_expired  = $expires_at && date('Y-m-d') > $expires_at;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_expired) {
    $height = (int)($_POST['height'] ?? 0);
    if ($height < 80 || $height > 220) {
        $error = '身長は80〜220cmの範囲で入力してください。';
    } else {
        $db->prepare("UPDATE members SET height=? WHERE id=?")->execute([$height, $member['id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>身長の更新 - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f1f5f9; }
        .update-card { max-width: 420px; margin: 60px auto; padding: 32px 28px; }
        .team-logo { text-align: center; margin-bottom: 24px; }
        .team-logo img { height: 48px; }
        .member-display {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-bottom: 24px;
        }
        .member-display .grade { font-size: 13px; color: #64748b; }
        .member-display .name  { font-size: 22px; font-weight: bold; margin-top: 4px; }
        .height-input { display: flex; align-items: center; gap: 8px; }
        .height-input input {
            width: 120px; font-size: 28px; text-align: center;
            padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px;
        }
        .height-input .unit { font-size: 20px; color: #64748b; }
        .done-icon { font-size: 48px; text-align: center; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="card update-card">
    <div class="team-logo">
        <img src="/images/sugaomax-logo.svg" alt="菅生マックス" onerror="this.style.display='none'">
        <div style="font-size:13px;color:#64748b;margin-top:6px;">菅生マックス チーム管理</div>
    </div>

    <?php if ($done): ?>
        <div class="done-icon">✅</div>
        <h2 style="text-align:center;margin-bottom:8px;">更新しました！</h2>
        <p style="text-align:center;color:#64748b;font-size:14px;">ありがとうございました。</p>
    <?php elseif ($is_expired): ?>
        <div style="text-align:center; padding: 16px 0;">
            <div style="font-size:40px; margin-bottom:12px;">🔒</div>
            <h2 style="margin-bottom:8px; font-size:18px;">受付期間が終了しました</h2>
            <p style="color:#64748b; font-size:14px; line-height:1.7;">
                身長の受付は <?= h(date('Y年n月j日', strtotime($expires_at))) ?> に終了しました。<br>
                入力が必要な場合は管理者にご連絡ください。
            </p>
        </div>
    <?php else: ?>
        <h2 style="margin-bottom:16px;font-size:17px;">身長を入力してください</h2>

        <div class="member-display">
            <div class="grade"><?= h($member['grade']) ?>年生</div>
            <div class="name"><?= h($member['last_name']) ?>　<?= h($member['first_name']) ?></div>
            <?php if ($member['height']): ?>
                <div style="font-size:12px;color:#94a3b8;margin-top:6px;">現在の登録: <?= h($member['height']) ?> cm</div>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:16px;"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div style="margin-bottom: 20px;">
                <label style="display:block;font-size:13px;color:#64748b;margin-bottom:8px;">身長（cm）</label>
                <div class="height-input">
                    <input type="number" name="height" min="80" max="220"
                           value="<?= h($member['height'] ?? '') ?>"
                           placeholder="例: 145" required autofocus>
                    <span class="unit">cm</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-size:15px;">更新する</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
