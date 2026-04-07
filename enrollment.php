<?php
require_once __DIR__ . '/includes/db.php';
// 保護者向け公開ページ - require_login() は不要

$db = get_db();

$enrollment_enabled = defined('ENROLLMENT_PASSWORD_HASH') && ENROLLMENT_PASSWORD_HASH !== ''
    && (!defined('ENROLLMENT_ACTIVE') || ENROLLMENT_ACTIVE === 1);

$login_error = '';
$form_error  = '';
$submitted   = false;

// ── QRコード自動ログイン（GETパラメータ token） ──────────────
$token_enabled = defined('ENROLLMENT_TOKEN') && ENROLLMENT_TOKEN !== '';
if ($token_enabled && isset($_GET['token'])) {
    if (hash_equals(ENROLLMENT_TOKEN, $_GET['token'])) {
        session_regenerate_id(true);
        $_SESSION['enrollment_access'] = true;
    }
    // tokenパラメータをURLから消してリダイレクト（ブラウザ履歴・ログ対策）
    header('Location: /enrollment.php');
    exit;
}

// ── 入部届けパスワードログイン ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enrollment_login') {
    $pw = $_POST['password'] ?? '';
    if ($enrollment_enabled && password_verify($pw, ENROLLMENT_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['enrollment_access'] = true;
        header('Location: /enrollment.php');
        exit;
    }
    $login_error = 'パスワードが正しくありません。';
}

// ── 入部届け送信 ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (empty($_SESSION['enrollment_access'])) {
        header('Location: /enrollment.php');
        exit;
    }
    verify_csrf();

    $last_name             = trim($_POST['last_name'] ?? '');
    $first_name            = trim($_POST['first_name'] ?? '');
    $grade                 = (int)($_POST['grade'] ?? 0);
    $gender                = in_array($_POST['gender'] ?? '', ['男子', '女子']) ? $_POST['gender'] : null;
    $school                = trim($_POST['school'] ?? '') ?: null;
    $parent_name           = trim($_POST['parent_name'] ?? '') ?: null;
    $parent_relationship   = trim($_POST['parent_relationship'] ?? '') ?: null;
    $phone                 = trim($_POST['phone'] ?? '') ?: null;
    $emergency_name        = trim($_POST['emergency_name'] ?? '') ?: null;
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '') ?: null;
    $emergency_phone       = trim($_POST['emergency_phone'] ?? '') ?: null;

    if ($last_name === '' || $grade < 1 || $grade > 6) {
        $form_error = '姓と学年は必須です。';
    } else {
        $stmt = $db->prepare("
            INSERT INTO members
                (last_name, first_name, grade, gender, school,
                 parent_name, parent_relationship, phone,
                 emergency_name, emergency_relationship, emergency_phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $last_name,
            $first_name,
            $grade,
            $gender,
            $school,
            $parent_name,
            $parent_relationship,
            $phone,
            $emergency_name,
            $emergency_relationship,
            $emergency_phone,
        ]);
        // セッションをクリアして再送信防止
        unset($_SESSION['enrollment_access']);
        $submitted = true;
    }
}

$show_form = !empty($_SESSION['enrollment_access']);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>入部届け - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .enrollment-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 40px 16px 60px;
            background: #f8fafc;
        }

        .enrollment-card {
            width: 100%;
            max-width: 560px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .08);
            padding: 32px 32px 36px;
        }

        .enrollment-logo {
            text-align: center;
            margin-bottom: 8px;
        }

        .enrollment-title {
            font-size: 20px;
            font-weight: bold;
            color: #1e3a5f;
            text-align: center;
            margin-bottom: 24px;
        }

        .section-label {
            font-size: 13px;
            font-weight: bold;
            color: #1e3a5f;
            background: #eff6ff;
            border-left: 3px solid #2563eb;
            padding: 6px 10px;
            margin: 20px 0 12px;
            border-radius: 0 4px 4px 0;
        }

        .complete-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 12px;
        }

        .complete-title {
            font-size: 20px;
            font-weight: bold;
            color: #16a34a;
            text-align: center;
            margin-bottom: 12px;
        }

        .complete-body {
            font-size: 14px;
            color: #475569;
            text-align: center;
            line-height: 1.8;
        }

        @media (max-width: 600px) {
            .enrollment-card {
                padding: 24px 16px 28px;
            }
        }
    </style>
</head>

<body>
    <div class="enrollment-wrap">
        <div class="enrollment-card">
            <div class="enrollment-logo">
                <img src="/images/sugaomax-logo.svg" height="80" alt="菅生マックス">
            </div>
            <div class="enrollment-title">入部届け</div>

            <?php if ($submitted): ?>
                <!-- 完了画面 -->
                <div class="complete-icon">&#10003;</div>
                <div class="complete-title">受付が完了しました</div>
                <p class="complete-body">
                    入部届けの内容を受け付けました。<br>
                    ご入力ありがとうございました。<br><br>
                    ご不明な点は担当保護者までお問い合わせください。
                </p>
                <div style="text-align:center;margin-top:24px;">
                    <a href="https://band.us/n/aba3b730u2J2R"
                        target="_blank" rel="noopener noreferrer"
                        class="btn btn-primary"
                        style="font-size:15px;padding:12px 24px;">
                        BANDアプリに登録
                    </a>
                </div>

            <?php elseif (!$enrollment_enabled): ?>
                <!-- 受付停止中 -->
                <div class="alert alert-danger" style="text-align:center;">
                    現在、入部届けの受付を停止しています。<br>
                    担当者までお問い合わせください。
                </div>

            <?php elseif (!$show_form): ?>
                <!-- パスワード入力画面 -->
                <p style="font-size:14px;color:#64748b;margin-bottom:16px;text-align:center;">
                    入力を開始するにはパスワードを入力してください。
                </p>
                <?php if ($login_error): ?>
                    <div class="alert alert-danger"><?= h($login_error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="enrollment_login">
                    <div class="form-group">
                        <label for="password">パスワード</label>
                        <input type="password" id="password" name="password" class="form-control" autofocus required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">確認する</button>
                </form>

            <?php else: ?>
                <!-- 入部届けフォーム -->
                <?php if ($form_error): ?>
                    <div class="alert alert-danger"><?= h($form_error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="submit">

                    <div class="section-label">お子様の情報</div>
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
                            <label>学年 <span style="color:red">*</span></label>
                            <select name="grade" class="form-control" required>
                                <option value="">選択してください</option>
                                <?php for ($g = 1; $g <= 6; $g++): ?>
                                    <option value="<?= $g ?>" <?= (($_POST['grade'] ?? '') == $g) ? 'selected' : '' ?>><?= $g ?>年</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>性別</label>
                            <select name="gender" class="form-control">
                                <option value="">選択</option>
                                <option value="男子" <?= (($_POST['gender'] ?? '') === '男子') ? 'selected' : '' ?>>男子</option>
                                <option value="女子" <?= (($_POST['gender'] ?? '') === '女子') ? 'selected' : '' ?>>女子</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>所属小学校</label>
                            <input type="text" name="school" class="form-control"
                                value="<?= h($_POST['school'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="section-label">保護者情報</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>保護者氏名</label>
                            <input type="text" name="parent_name" class="form-control"
                                value="<?= h($_POST['parent_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>続柄</label>
                            <input type="text" name="parent_relationship" class="form-control" placeholder="例: 父・母"
                                value="<?= h($_POST['parent_relationship'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>携帯電話番号</label>
                            <input type="tel" name="phone" class="form-control" placeholder="例: 090-0000-0000"
                                value="<?= h($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="section-label">緊急連絡先</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>氏名</label>
                            <input type="text" name="emergency_name" class="form-control"
                                value="<?= h($_POST['emergency_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>続柄</label>
                            <input type="text" name="emergency_relationship" class="form-control"
                                value="<?= h($_POST['emergency_relationship'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>携帯番号</label>
                            <input type="tel" name="emergency_phone" class="form-control" placeholder="例: 090-0000-0000"
                                value="<?= h($_POST['emergency_phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="margin-top:24px;">
                        <button type="submit" class="btn btn-primary" style="width:100%;">入部届けを送信する</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>