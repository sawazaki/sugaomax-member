<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /members.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM members WHERE id=? AND active=1");
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) {
    header('Location: /members.php');
    exit;
}

$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name            = trim($_POST['name'] ?? '');
    $grade           = (int)($_POST['grade'] ?? 0);
    $gender          = in_array($_POST['gender'] ?? '', ['男子', '女子']) ? $_POST['gender'] : null;
    $romaji          = trim($_POST['romaji'] ?? '') ?: null;
    $number          = $_POST['number'] !== '' ? (int)$_POST['number'] : null;
    $school          = trim($_POST['school'] ?? '') ?: null;
    $height          = $_POST['height'] !== '' ? (int)$_POST['height'] : null;
    $reversible_bibs = $_POST['reversible_bibs'] !== '' ? (int)$_POST['reversible_bibs'] : 0;
    $blue_bibs       = $_POST['blue_bibs'] !== '' ? (int)$_POST['blue_bibs'] : 0;

    if ($name === '' || $grade < 1 || $grade > 6) {
        $error = '氏名と学年は必須です。';
    } else {
        $stmt = $db->prepare("UPDATE members SET name=?, grade=?, gender=?, romaji=?, number=?, school=?, height=?, reversible_bibs=?, blue_bibs=? WHERE id=?");
        $stmt->execute([$name, $grade, $gender, $romaji, $number, $school, $height, $reversible_bibs, $blue_bibs, $id]);
        $msg = '更新しました。';
        // 最新データを再取得
        $stmt = $db->prepare("SELECT * FROM members WHERE id=?");
        $stmt->execute([$id]);
        $m = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($m['name']) ?> - 部員編集 - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0"><?= h($m['name']) ?></h1>
            <a href="/members.php" class="btn btn-secondary btn-sm">← 部員一覧へ</a>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="card" style="max-width:640px">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>氏名 <span style="color:red">*</span></label>
                        <input type="text" name="name" class="form-control" required
                            value="<?= h($m['name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>ローマ字表記</label>
                        <input type="text" name="romaji" class="form-control" placeholder="名前のみ 例: TARO"
                            value="<?= h($m['romaji'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>学年 <span style="color:red">*</span></label>
                        <select name="grade" class="form-control" required>
                            <?php for ($g = 1; $g <= 6; $g++): ?>
                                <option value="<?= $g ?>" <?= $m['grade'] == $g ? 'selected' : '' ?>><?= $g ?>年</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>性別</label>
                        <select name="gender" class="form-control">
                            <option value="">選択</option>
                            <option value="男子" <?= ($m['gender'] ?? '') === '男子' ? 'selected' : '' ?>>男子</option>
                            <option value="女子" <?= ($m['gender'] ?? '') === '女子' ? 'selected' : '' ?>>女子</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>背番号</label>
                        <input type="number" name="number" class="form-control" min="0" max="99"
                            value="<?= h($m['number'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>所属小学校</label>
                        <input type="text" name="school" class="form-control"
                            value="<?= h($m['school'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>身長（cm）</label>
                        <input type="number" name="height" class="form-control" min="80" max="220"
                            value="<?= h($m['height'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>リバーシブルビブス番号</label>
                        <input type="number" name="reversible_bibs" class="form-control" min="0" max="99"
                            value="<?= h(($m['reversible_bibs'] ?? 0) ?: '') ?>">
                    </div>
                    <div class="form-group">
                        <label>青ビブス番号</label>
                        <input type="number" name="blue_bibs" class="form-control" min="0" max="99"
                            value="<?= h(($m['blue_bibs'] ?? 0) ?: '') ?>">
                    </div>
                </div>
                <div class="flex gap-8 mt-8">
                    <button type="submit" class="btn btn-primary">更新する</button>
                    <a href="/members.php" class="btn btn-secondary">キャンセル</a>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
