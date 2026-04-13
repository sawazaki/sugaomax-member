<?php
require_once __DIR__ . '/includes/db.php';
require_editor();

$db = get_db();
$error = '';
$match = null;

// 編集対象を取得
if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM matches WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $match = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $match_date       = trim($_POST['match_date'] ?? '');
    $opponent         = trim($_POST['opponent'] ?? '');
    $title            = trim($_POST['title'] ?? '') ?: null;
    $venue            = trim($_POST['venue'] ?? '') ?: null;
    $match_type       = $_POST['match_type'] ?? '' ?: null;
    $note             = trim($_POST['note'] ?? '') ?: null;
    $coach            = trim($_POST['coach'] ?? '') ?: null;
    $assistant_coach  = trim($_POST['assistant_coach'] ?? '') ?: null;
    $id               = (int)($_POST['id'] ?? 0);

    if ($match_date === '' || $opponent === '') {
        $error = '試合日と対戦相手は必須です。';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE matches SET match_date=?, opponent=?, title=?, venue=?, match_type=?, note=?, coach=?, assistant_coach=? WHERE id=?");
            $stmt->execute([$match_date, $opponent, $title, $venue, $match_type, $note, $coach, $assistant_coach, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO matches (match_date, opponent, title, venue, match_type, note, coach, assistant_coach) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$match_date, $opponent, $title, $venue, $match_type, $note, $coach, $assistant_coach]);
            $id = $db->lastInsertId();
        }
        header("Location: /match_sheet.php?id={$id}");
        exit;
    }
}

$types = ['練習試合', '公式戦', '大会', 'その他'];
$is_edit = $match !== null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $is_edit ? '試合を編集' : '試合を追加' ?> - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <h1 class="page-title"><?= $is_edit ? '試合を編集' : '試合を追加' ?></h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card" style="max-width:600px">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <?php if ($is_edit): ?>
                <input type="hidden" name="id" value="<?= h($match['id']) ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label>試合日 <span style="color:red">*</span></label>
                    <input type="date" name="match_date" class="form-control" required
                           value="<?= h($match['match_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>種別</label>
                    <select name="match_type" class="form-control">
                        <option value="">選択しない</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= h($t) ?>" <?= ($match['match_type'] ?? '') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>大会名・タイトル</label>
                <input type="text" name="title" class="form-control"
                       value="<?= h($match['title'] ?? '') ?>" placeholder="例：○○カップ、春季大会">
            </div>
            <div class="form-group">
                <label>対戦相手 <span style="color:red">*</span></label>
                <input type="text" name="opponent" class="form-control" required
                       value="<?= h($match['opponent'] ?? '') ?>" placeholder="例：○○ミニバス">
            </div>
            <div class="form-group">
                <label>会場</label>
                <input type="text" name="venue" class="form-control"
                       value="<?= h($match['venue'] ?? '') ?>" placeholder="例：○○小学校体育館">
            </div>
            <div class="form-group">
                <label>備考（集合時間・持ち物など）</label>
                <textarea name="note" class="form-control" rows="3"><?= h($match['note'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>コーチ</label>
                    <input type="text" name="coach" class="form-control"
                           value="<?= h($match['coach'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>A.コーチ</label>
                    <input type="text" name="assistant_coach" class="form-control"
                           value="<?= h($match['assistant_coach'] ?? '') ?>">
                </div>
            </div>
            <div class="flex gap-8 mt-8">
                <button type="submit" class="btn btn-primary"><?= $is_edit ? '更新してメンバー表へ' : '追加してメンバー表へ' ?></button>
                <a href="/matches.php" class="btn btn-secondary">キャンセル</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
