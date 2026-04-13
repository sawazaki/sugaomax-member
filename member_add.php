<?php
require_once __DIR__ . '/includes/db.php';
require_editor();

$db    = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $last_name       = trim($_POST['last_name'] ?? '');
    $first_name      = trim($_POST['first_name'] ?? '');
    $grade           = (int)($_POST['grade'] ?? 0);
    $gender          = in_array($_POST['gender'] ?? '', ['男子', '女子']) ? $_POST['gender'] : null;
    $romaji          = trim($_POST['romaji'] ?? '') ?: null;
    $number          = $_POST['number'] !== '' ? (int)$_POST['number'] : null;
    $school          = trim($_POST['school'] ?? '') ?: null;
    $height          = $_POST['height'] !== '' ? (int)$_POST['height'] : null;
    $reversible_bibs = $_POST['reversible_bibs'] !== '' ? (int)$_POST['reversible_bibs'] : 0;
    $blue_bibs       = $_POST['blue_bibs'] !== '' ? (int)$_POST['blue_bibs'] : 0;
    $practice_duty   = in_array($_POST['practice_duty'] ?? '', ['A','B','C','D','E','F','G','H','I','J','K']) ? $_POST['practice_duty'] : null;
    $match_duty      = in_array($_POST['match_duty'] ?? '', ['1','2','3','4']) ? $_POST['match_duty'] : null;
    $has_sibling     = !empty($_POST['has_sibling']) ? 1 : 0;

    if ($last_name === '' || $grade < 1 || $grade > 6) {
        $error = '姓と学年は必須です。';
    } else {
        $stmt = $db->prepare("INSERT INTO members (last_name, first_name, grade, gender, romaji, number, school, height, reversible_bibs, blue_bibs, practice_duty, match_duty, has_sibling) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$last_name, $first_name, $grade, $gender, $romaji, $number, $school, $height, $reversible_bibs, $blue_bibs, $practice_duty, $match_duty, $has_sibling]);
        header('Location: /members.php?added=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>部員を追加 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0">部員を追加</h1>
            <a href="/members.php" class="btn btn-secondary btn-sm">← 部員一覧へ</a>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="card" style="max-width:640px">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>姓 <span style="color:red">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                            value="<?= h($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>名</label>
                        <input type="text" name="first_name" class="form-control"
                            value="<?= h($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>ローマ字表記</label>
                        <input type="text" name="romaji" class="form-control" placeholder="名前のみ 例: TARO"
                            value="<?= h($_POST['romaji'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>学年 <span style="color:red">*</span></label>
                        <select name="grade" class="form-control" required>
                            <option value="">選択</option>
                            <?php for ($g = 1; $g <= 6; $g++): ?>
                                <option value="<?= $g ?>" <?= ($_POST['grade'] ?? '') == $g ? 'selected' : '' ?>><?= $g ?>年</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>性別</label>
                        <select name="gender" class="form-control">
                            <option value="">選択</option>
                            <option value="男子" <?= ($_POST['gender'] ?? '') === '男子' ? 'selected' : '' ?>>男子</option>
                            <option value="女子" <?= ($_POST['gender'] ?? '') === '女子' ? 'selected' : '' ?>>女子</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>背番号</label>
                        <input type="number" name="number" class="form-control" min="0" max="99"
                            value="<?= h($_POST['number'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>所属小学校</label>
                        <input type="text" name="school" class="form-control"
                            value="<?= h($_POST['school'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>身長（cm）</label>
                        <input type="number" name="height" class="form-control" min="80" max="220"
                            value="<?= h($_POST['height'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>練習当番</label>
                        <select name="practice_duty" class="form-control">
                            <option value="">未設定</option>
                            <?php foreach (range('A', 'K') as $v): ?>
                                <option value="<?= $v ?>" <?= ($_POST['practice_duty'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>試合当番</label>
                        <select name="match_duty" class="form-control">
                            <option value="">未設定</option>
                            <?php foreach (['1','2','3','4'] as $v): ?>
                                <option value="<?= $v ?>" <?= ($_POST['match_duty'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>リバーシブルビブス番号</label>
                        <input type="number" name="reversible_bibs" class="form-control" min="0" max="99"
                            value="<?= h($_POST['reversible_bibs'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>青ビブス番号</label>
                        <input type="number" name="blue_bibs" class="form-control" min="0" max="99"
                            value="<?= h($_POST['blue_bibs'] ?? '') ?>">
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="has_sibling" value="1" <?= !empty($_POST['has_sibling']) ? 'checked' : '' ?> style="width:16px;height:16px;">
                        兄弟あり（当番表から除外）
                    </label>
                </div>
                <div class="flex gap-8 mt-8">
                    <button type="submit" class="btn btn-primary">追加する</button>
                    <a href="/members.php" class="btn btn-secondary">キャンセル</a>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
