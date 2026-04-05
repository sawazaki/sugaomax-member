<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();

$member_count = $db->query("SELECT COUNT(*) FROM members WHERE active = 1")->fetchColumn();
$match_count  = $db->query("SELECT COUNT(*) FROM matches")->fetchColumn();
$recent_matches = $db->query("
    SELECT * FROM matches
    ORDER BY match_date DESC, id DESC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ダッシュボード - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <h1 class="page-title">ダッシュボード</h1>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= h($member_count) ?></div>
            <div class="stat-label">在籍部員数</div>
            <a href="/members.php" class="btn btn-secondary btn-sm" style="margin-top:12px;">部員管理へ</a>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= h($match_count) ?></div>
            <div class="stat-label">登録試合数</div>
            <a href="/matches.php" class="btn btn-secondary btn-sm" style="margin-top:12px;">試合一覧へ</a>
        </div>
    </div>

    <div class="card">
        <div class="flex items-center justify-between mb-16">
            <div class="card-title" style="margin-bottom:0">最近の試合</div>
            <a href="/match_new.php" class="btn btn-primary btn-sm">＋ 試合を追加</a>
        </div>
        <?php if (empty($recent_matches)): ?>
            <p class="text-muted text-center" style="padding:24px 0;">試合がまだ登録されていません。</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>日付</th>
                    <th>大会名・タイトル</th>
                    <th>対戦相手</th>
                    <th>会場</th>
                    <th>種別</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_matches as $m): ?>
                <tr>
                    <td style="white-space:nowrap"><?= h($m['match_date']) ?></td>
                    <td><?= h($m['title'] ?? '—') ?></td>
                    <td><?= h($m['opponent']) ?></td>
                    <td style="white-space:nowrap"><?= h($m['venue'] ?? '—') ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($m['match_type']): ?>
                            <span class="badge badge-blue"><?= h($m['match_type']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <a href="/match_sheet.php?id=<?= h($m['id']) ?>" class="btn btn-outline btn-sm">メンバー表</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
