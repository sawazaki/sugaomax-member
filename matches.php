<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();
$msg = '';

// 削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!is_editor()) { http_response_code(403); exit('forbidden'); }
    $id = (int)$_POST['id'];
    $db->prepare("DELETE FROM match_members WHERE match_id=?")->execute([$id]);
    $db->prepare("DELETE FROM matches WHERE id=?")->execute([$id]);
    $msg = '試合を削除しました。';
}

$matches = $db->query("
    SELECT m.*, COUNT(mm.id) AS member_count
    FROM matches m
    LEFT JOIN match_members mm ON mm.match_id = m.id
    GROUP BY m.id
    ORDER BY m.match_date DESC, m.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>試合管理 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <div class="flex items-center justify-between mb-16">
        <h1 class="page-title" style="margin-bottom:0">試合管理</h1>
        <a href="/match_new.php" class="btn btn-primary">＋ 試合を追加</a>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

    <div class="card">
        <?php if (empty($matches)): ?>
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
                    <th>メンバー</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($matches as $m): ?>
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
                    <td><?= h($m['member_count']) ?>名</td>
                    <td>
                        <div class="flex gap-8">
                            <a href="/match_sheet.php?id=<?= h($m['id']) ?>" class="btn btn-outline btn-sm">メンバー表</a>
                            <a href="/match_new.php?id=<?= h($m['id']) ?>" class="btn btn-secondary btn-sm">編集</a>
                            <form method="post" onsubmit="return confirm('この試合を削除しますか？')">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h($m['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">削除</button>
                            </form>
                        </div>
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
