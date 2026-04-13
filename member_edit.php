<?php
require_once __DIR__ . '/includes/db.php';
require_editor();

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

$msg          = '';
$error        = '';
$unlock_error = '';

// ── 機密情報アンロック確認（30分有効） ───────────────────────
$sensitive_unlocked = !empty($_SESSION['sensitive_unlocked_at'])
    && (time() - $_SESSION['sensitive_unlocked_at']) < 180;

// ── 機密情報アンロック処理 ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_sensitive') {
    verify_csrf();
    $pw = $_POST['password'] ?? '';
    if (password_verify($pw, APP_PASSWORD_HASH)) {
        $_SESSION['sensitive_unlocked_at'] = time();
        header('Location: /member_edit.php?id=' . $id);
        exit;
    }
    $unlock_error = 'パスワードが正しくありません。';
}

// ── 部員基本情報更新 ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_member') {
    verify_csrf();
    $last_name       = trim($_POST['last_name'] ?? '');
    $first_name      = trim($_POST['first_name'] ?? '');
    $grade           = (int)($_POST['grade'] ?? 0);
    $gender          = in_array($_POST['gender'] ?? '', ['男子', '女子']) ? $_POST['gender'] : null;
    $romaji          = trim($_POST['romaji'] ?? '') ?: null;
    $number          = ($_POST['number'] ?? '') !== '' ? (int)$_POST['number'] : null;
    $school          = trim($_POST['school'] ?? '') ?: null;
    $height          = ($_POST['height'] ?? '') !== '' ? (int)$_POST['height'] : null;
    $reversible_bibs = ($_POST['reversible_bibs'] ?? '') !== '' ? (int)$_POST['reversible_bibs'] : 0;
    $blue_bibs       = ($_POST['blue_bibs'] ?? '') !== '' ? (int)$_POST['blue_bibs'] : 0;
    $practice_duty   = in_array($_POST['practice_duty'] ?? '', ['A','B','C','D','E','F','G','H','I','J','K']) ? $_POST['practice_duty'] : null;
    $match_duty      = in_array($_POST['match_duty'] ?? '', ['1','2','3','4']) ? $_POST['match_duty'] : null;
    $has_sibling     = !empty($_POST['has_sibling']) ? 1 : 0;

    if ($last_name === '' || $grade < 1 || $grade > 6) {
        $error = '姓と学年は必須です。';
    } else {
        $stmt = $db->prepare("UPDATE members SET last_name=?, first_name=?, grade=?, gender=?, romaji=?, number=?, school=?, height=?, reversible_bibs=?, blue_bibs=?, practice_duty=?, match_duty=?, has_sibling=? WHERE id=?");
        $stmt->execute([$last_name, $first_name, $grade, $gender, $romaji, $number, $school, $height, $reversible_bibs, $blue_bibs, $practice_duty, $match_duty, $has_sibling, $id]);
        $msg = '更新しました。';
        $stmt = $db->prepare("SELECT * FROM members WHERE id=?");
        $stmt->execute([$id]);
        $m = $stmt->fetch();
    }
}

// ── 保護者・緊急連絡先更新（アンロック済みのみ） ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_sensitive') {
    verify_csrf();
    if (!$sensitive_unlocked) {
        header('Location: /member_edit.php?id=' . $id);
        exit;
    }
    $parent_name            = trim($_POST['parent_name'] ?? '') ?: null;
    $parent_relationship    = trim($_POST['parent_relationship'] ?? '') ?: null;
    $phone                  = trim($_POST['phone'] ?? '') ?: null;
    $emergency_name         = trim($_POST['emergency_name'] ?? '') ?: null;
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '') ?: null;
    $emergency_phone        = trim($_POST['emergency_phone'] ?? '') ?: null;
    $stmt = $db->prepare("UPDATE members SET parent_name=?, parent_relationship=?, phone=?, emergency_name=?, emergency_relationship=?, emergency_phone=? WHERE id=?");
    $stmt->execute([$parent_name, $parent_relationship, $phone, $emergency_name, $emergency_relationship, $emergency_phone, $id]);
    $msg = '保護者情報を更新しました。';
    $stmt = $db->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(member_name($m)) ?> - 部員編集 - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .sensitive-lock {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .sensitive-lock .lock-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .sensitive-lock p {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 14px;
        }
        .sensitive-lock .unlock-form {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .sensitive-lock .unlock-form input {
            width: 200px;
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0"><?= h(member_name($m)) ?></h1>
            <a href="/members.php" class="btn btn-secondary btn-sm">← 部員一覧へ</a>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="card" style="max-width:640px">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_member">
                <div class="form-row">
                    <div class="form-group">
                        <label>姓 <span style="color:red">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                            value="<?= h($m['last_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>名</label>
                        <input type="text" name="first_name" class="form-control"
                            value="<?= h($m['first_name'] ?? '') ?>">
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
                        <label>練習当番</label>
                        <select name="practice_duty" class="form-control">
                            <option value="">未設定</option>
                            <?php foreach (range('A', 'K') as $v): ?>
                                <option value="<?= $v ?>" <?= ($m['practice_duty'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>試合当番</label>
                        <select name="match_duty" class="form-control">
                            <option value="">未設定</option>
                            <?php foreach (['1','2','3','4'] as $v): ?>
                                <option value="<?= $v ?>" <?= ($m['match_duty'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
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
                <div style="margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="has_sibling" value="1" <?= !empty($m['has_sibling']) ? 'checked' : '' ?> style="width:16px;height:16px;">
                        兄弟あり（当番表から除外）
                    </label>
                </div>

                <div class="flex gap-8 mt-8">
                    <button type="submit" class="btn btn-primary">更新する</button>
                    <a href="/members.php" class="btn btn-secondary">キャンセル</a>
                </div>
            </form>

            <!-- 保護者情報・緊急連絡先（パスワード保護） -->
            <hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0;">

            <?php if ($sensitive_unlocked): ?>
                <?php
                // アンロック残り時間（分）
                $remaining = max(1, (int)ceil((180 - (time() - $_SESSION['sensitive_unlocked_at'])) / 60));
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                    <span style="font-size:13px;color:#16a34a;font-weight:bold;">
                        &#128275; 機密情報を表示中（あと約<?= $remaining ?>分）
                    </span>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_sensitive">
                    <div style="font-size:13px;font-weight:bold;color:#1e3a5f;margin-bottom:12px;">保護者情報</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>保護者氏名</label>
                            <input type="text" name="parent_name" class="form-control"
                                value="<?= h($m['parent_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>続柄</label>
                            <input type="text" name="parent_relationship" class="form-control" placeholder="例: 父・母"
                                value="<?= h($m['parent_relationship'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>携帯電話番号</label>
                            <input type="tel" name="phone" class="form-control"
                                value="<?= h($m['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="font-size:13px;font-weight:bold;color:#1e3a5f;margin-bottom:12px;">緊急連絡先</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>氏名</label>
                            <input type="text" name="emergency_name" class="form-control"
                                value="<?= h($m['emergency_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>続柄</label>
                            <input type="text" name="emergency_relationship" class="form-control"
                                value="<?= h($m['emergency_relationship'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>携帯番号</label>
                            <input type="tel" name="emergency_phone" class="form-control"
                                value="<?= h($m['emergency_phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="flex gap-8 mt-8">
                        <button type="submit" class="btn btn-primary">保護者情報を更新する</button>
                    </div>
                </form>

            <?php else: ?>
                <div class="sensitive-lock">
                    <div class="lock-icon">&#128274;</div>
                    <p>保護者情報・緊急連絡先を表示するには<br>管理者パスワードを入力してください。</p>
                    <?php if ($unlock_error): ?>
                        <p style="color:#dc2626;font-size:13px;margin-bottom:10px;"><?= h($unlock_error) ?></p>
                    <?php endif; ?>
                    <form method="post" class="unlock-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="unlock_sensitive">
                        <input type="password" name="password" class="form-control" placeholder="パスワード" required autofocus>
                        <button type="submit" class="btn btn-outline">確認</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>

</html>
