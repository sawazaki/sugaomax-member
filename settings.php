<?php
require_once __DIR__ . '/includes/db.php';
require_admin();

$config_path = __DIR__ . '/data/config.php';
$msg   = '';
$error = '';

// ── config.php書き込みヘルパー ────────────────────────────────
function write_config(
    string $path,
    string $admin_hash,
    string $editor_hash,
    string $viewer_hash
): bool {
    $content = "<?php\n// このファイルはGitignore済みです。直接編集しないでください。\n"
        . "define('ADMIN_PASSWORD_HASH', "      . var_export($admin_hash,  true) . ");\n"
        . "define('APP_PASSWORD_HASH', "        . var_export($editor_hash, true) . ");\n"
        . "define('VIEWER_PASSWORD_HASH', "     . var_export($viewer_hash, true) . ");\n"
        . "define('ENROLLMENT_PASSWORD_HASH', " . var_export(defined('ENROLLMENT_PASSWORD_HASH') ? ENROLLMENT_PASSWORD_HASH : '', true) . ");\n"
        . "define('ENROLLMENT_TOKEN', "         . var_export(defined('ENROLLMENT_TOKEN')         ? ENROLLMENT_TOKEN         : '', true) . ");\n"
        . "define('ENROLLMENT_ACTIVE', "        . (defined('ENROLLMENT_ACTIVE') ? (int)ENROLLMENT_ACTIVE : 1) . ");\n";
    return file_put_contents($path, $content) !== false;
}

function cur(string $const, string $default = ''): string {
    return defined($const) ? constant($const) : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── 管理者パスワード変更 ────────────────────────────────
    if ($action === 'save_admin_password') {
        $pw  = $_POST['admin_password']  ?? '';
        $pw2 = $_POST['admin_password2'] ?? '';

        if (strlen($pw) < 8) {
            $error = 'パスワードは8文字以上で設定してください。';
        } elseif ($pw !== $pw2) {
            $error = 'パスワードが一致しません。';
        } else {
            $new_hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            if (write_config($config_path, $new_hash, cur('APP_PASSWORD_HASH'), cur('VIEWER_PASSWORD_HASH'))) {
                header('Location: /settings.php?admin_saved=1');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }

    // ── 編集者パスワード変更 ────────────────────────────────
    if ($action === 'save_editor_password') {
        $pw  = $_POST['editor_password']  ?? '';
        $pw2 = $_POST['editor_password2'] ?? '';

        if (strlen($pw) < 8) {
            $error = 'パスワードは8文字以上で設定してください。';
        } elseif ($pw !== $pw2) {
            $error = 'パスワードが一致しません。';
        } else {
            $new_hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            if (write_config($config_path, cur('ADMIN_PASSWORD_HASH'), $new_hash, cur('VIEWER_PASSWORD_HASH'))) {
                header('Location: /settings.php?editor_saved=1');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }

    // ── 閲覧パスワード変更 ──────────────────────────────────
    if ($action === 'save_viewer_password') {
        $pw  = $_POST['viewer_password']  ?? '';
        $pw2 = $_POST['viewer_password2'] ?? '';

        if ($pw === '' && $pw2 === '') {
            // 空欄 → 削除
            if (write_config($config_path, cur('ADMIN_PASSWORD_HASH'), cur('APP_PASSWORD_HASH'), '')) {
                header('Location: /settings.php?viewer_saved=deleted');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        } elseif (strlen($pw) < 8) {
            $error = '閲覧パスワードは8文字以上で設定してください。';
        } elseif ($pw !== $pw2) {
            $error = '閲覧パスワードが一致しません。';
        } else {
            $new_hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            if (write_config($config_path, cur('ADMIN_PASSWORD_HASH'), cur('APP_PASSWORD_HASH'), $new_hash)) {
                header('Location: /settings.php?viewer_saved=1');
                exit;
            }
            $error = 'ファイルの書き込みに失敗しました。';
        }
    }
}

if (!empty($_GET['admin_saved']))  $msg = '管理者パスワードを更新しました。';
if (!empty($_GET['editor_saved'])) $msg = '編集者パスワードを更新しました。';
if (isset($_GET['viewer_saved']))  $msg = $_GET['viewer_saved'] === 'deleted' ? '閲覧パスワードを削除しました。' : '閲覧パスワードを更新しました。';

$admin_set  = defined('ADMIN_PASSWORD_HASH')  && ADMIN_PASSWORD_HASH  !== '';
$editor_set = defined('APP_PASSWORD_HASH')    && APP_PASSWORD_HASH    !== '';
$viewer_set = defined('VIEWER_PASSWORD_HASH') && VIEWER_PASSWORD_HASH !== '';

function status_badge(bool $set, string $label_on = '設定済み', string $label_off = '未設定'): string {
    if ($set) return '<span style="color:#16a34a;font-weight:bold;">' . $label_on . '</span>';
    return '<span style="color:#94a3b8;font-weight:bold;">' . $label_off . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>設定 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0">設定</h1>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div style="max-width:560px;">

            <!-- 管理者パスワード -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-title">管理者パスワード</div>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                    設定画面へのアクセスを含む全操作が可能なアカウントです。<br>
                    現在の状態：<?= status_badge($admin_set) ?>
                </p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_admin_password">
                    <div class="form-group">
                        <label>新しいパスワード（8文字以上）</label>
                        <input type="password" name="admin_password" class="form-control" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label>パスワード（確認）</label>
                        <input type="password" name="admin_password2" class="form-control" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">保存する</button>
                </form>
            </div>

            <!-- 編集者パスワード -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-title">編集者パスワード</div>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                    部員・試合・当番・入部届けの編集が可能なアカウントです（設定画面を除く）。<br>
                    現在の状態：<?= status_badge($editor_set) ?>
                </p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_editor_password">
                    <div class="form-group">
                        <label>新しいパスワード（8文字以上）</label>
                        <input type="password" name="editor_password" class="form-control" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label>パスワード（確認）</label>
                        <input type="password" name="editor_password2" class="form-control" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">保存する</button>
                </form>
            </div>

            <!-- 閲覧者パスワード -->
            <div class="card">
                <div class="card-title">閲覧者パスワード</div>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                    データの閲覧のみ可能なアカウントです（編集・追加・削除不可）。<br>
                    現在の状態：<?= status_badge($viewer_set, '設定済み', '未設定（閲覧専用ユーザーなし）') ?>
                </p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_viewer_password">
                    <div class="form-group">
                        <label>閲覧パスワード（8文字以上）<?= $viewer_set ? '— 空欄で削除' : '' ?></label>
                        <input type="password" name="viewer_password" class="form-control" minlength="8"
                            placeholder="<?= $viewer_set ? '新しいパスワードを入力（空欄で削除）' : '8文字以上' ?>">
                    </div>
                    <div class="form-group">
                        <label>閲覧パスワード（確認）</label>
                        <input type="password" name="viewer_password2" class="form-control" minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">保存する</button>
                    <?php if ($viewer_set): ?>
                        <span style="font-size:12px;color:#94a3b8;margin-left:12px;">両欄を空欄のまま送信すると削除されます</span>
                    <?php endif; ?>
                </form>
            </div>

        </div>
    </div>
</body>

</html>
