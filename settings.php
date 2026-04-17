<?php
require_once __DIR__ . '/includes/db.php';
require_admin();

$db = get_db();
$config_path = DATA_DIR . '/config.php';
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

// ── カラーパレット ──────────────────────────────────────────────
const WEBCAL_COLORS = [
    ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'label' => '青'],
    ['bg' => '#dcfce7', 'text' => '#166534', 'label' => '緑'],
    ['bg' => '#fed7aa', 'text' => '#9a3412', 'label' => 'オレンジ'],
    ['bg' => '#ede9fe', 'text' => '#5b21b6', 'label' => '紫'],
    ['bg' => '#fce7f3', 'text' => '#9d174d', 'label' => 'ピンク'],
    ['bg' => '#fef3c7', 'text' => '#78350f', 'label' => '黄'],
    ['bg' => '#ccfbf1', 'text' => '#065f46', 'label' => 'ティール'],
    ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => '赤'],
];

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

    // ── 予定テキスト保存 ────────────────────────────────────
    if ($action === 'save_schedule_text') {
        $raw  = $_POST['schedule_text'] ?? '';
        // 管理者専用: 許可タグのみ残してサニタイズ
        $text = strip_tags($raw, '<b><strong><span><br><div><p><font>');
        // style属性にjavascript等の危険な値があれば除去
        $text = preg_replace('/style\s*=\s*["\'][^"\']*(?:expression|javascript|behavior)[^"\']*["\']/i', '', $text);
        $db->prepare("INSERT INTO app_settings (key, value) VALUES ('schedule_text', ?)
                      ON CONFLICT(key) DO UPDATE SET value=excluded.value")
           ->execute([$text]);
        header('Location: /settings.php?schedule_text_saved=1#schedule_text');
        exit;
    }

    // ── webcal追加 ──────────────────────────────────────────
    if ($action === 'add_webcal') {
        $cat   = trim($_POST['webcal_category'] ?? '');
        $url   = trim($_POST['webcal_url']      ?? '');
        $color = (int)($_POST['webcal_color']   ?? 0);
        $colors = WEBCAL_COLORS;
        $color = isset($colors[$color]) ? $color : 0;
        if ($cat === '' || $url === '') {
            $error = 'カテゴリ名とURLを入力してください。';
        } elseif (!preg_match('/^(https?|webcals?):\/\/.+/i', $url)) {
            $error = 'URLはhttps://またはwebcal://で始まる形式で入力してください。';
        } else {
            $max_order = (int)($db->query("SELECT MAX(sort_order) FROM webcal_sources")->fetchColumn() ?: 0);
            $db->prepare("INSERT INTO webcal_sources (category, url, color_bg, color_text, sort_order) VALUES (?,?,?,?,?)")
               ->execute([$cat, $url, $colors[$color]['bg'], $colors[$color]['text'], $max_order + 1]);
            header('Location: /settings.php?webcal_saved=1#webcal');
            exit;
        }
    }

    // ── webcal削除 ──────────────────────────────────────────
    if ($action === 'delete_webcal') {
        $wid = (int)($_POST['webcal_id'] ?? 0);
        if ($wid > 0) {
            $db->prepare("DELETE FROM webcal_sources WHERE id=?")->execute([$wid]);
        }
        header('Location: /settings.php?webcal_saved=deleted#webcal');
        exit;
    }

    // ── webcal並び順変更 ────────────────────────────────────
    if ($action === 'webcal_reorder') {
        $wid = (int)($_POST['webcal_id'] ?? 0);
        $dir = $_POST['direction'] ?? '';
        if ($wid > 0 && in_array($dir, ['up', 'down'])) {
            $sources = $db->query("SELECT id, sort_order FROM webcal_sources ORDER BY sort_order, id")->fetchAll();
            $ids = array_column($sources, 'id');
            $idx = array_search($wid, $ids);
            if ($idx !== false) {
                $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
                if ($swap >= 0 && $swap < count($ids)) {
                    $stmt = $db->prepare("UPDATE webcal_sources SET sort_order=? WHERE id=?");
                    $stmt->execute([$sources[$swap]['sort_order'], $ids[$idx]]);
                    $stmt->execute([$sources[$idx]['sort_order'], $ids[$swap]]);
                }
            }
        }
        header('Location: /settings.php#webcal');
        exit;
    }
}

if (!empty($_GET['admin_saved']))   $msg = '管理者パスワードを更新しました。';
if (!empty($_GET['editor_saved']))  $msg = '編集者パスワードを更新しました。';
if (isset($_GET['viewer_saved']))   $msg = $_GET['viewer_saved'] === 'deleted' ? '閲覧パスワードを削除しました。' : '閲覧パスワードを更新しました。';
if (!empty($_GET['webcal_saved']))        $msg = $_GET['webcal_saved'] === 'deleted' ? 'webcal URLを削除しました。' : 'webcal URLを追加しました。';
if (!empty($_GET['schedule_text_saved'])) $msg = '予定テキストを保存しました。';

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

        <!-- ─── 予定テキスト ─── -->
        <?php
        $schedule_text = $db->query("SELECT value FROM app_settings WHERE key='schedule_text'")->fetchColumn() ?: '';
        ?>
        <div class="card" id="schedule_text" style="margin-top:24px;">
            <div class="card-title">予定ページ テキスト</div>
            <p style="font-size:13px;color:#64748b;margin-bottom:12px;">
                活動予定PDFの左下に表示するテキストを入力してください。
            </p>
            <form method="post" id="scheduleTextForm">
                <input type="hidden" name="csrf_token"     value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"         value="save_schedule_text">
                <input type="hidden" name="schedule_text"  id="scheduleTextInput">
                <div class="form-group">
                    <!-- ツールバー -->
                    <div style="display:flex;align-items:center;gap:6px;padding:5px 8px;background:#f8fafc;border:1px solid #cbd5e1;border-bottom:none;border-radius:5px 5px 0 0;">
                        <button type="button" id="wysiwygBold"
                            onclick="document.execCommand('bold');document.getElementById('scheduleTextEditor').focus();updateWysiwygState();"
                            title="太字 (Ctrl+B)"
                            style="font-weight:bold;width:28px;height:28px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;cursor:pointer;font-size:14px;color:#1e3a5f;">B</button>
                        <label title="文字色" style="display:flex;align-items:center;gap:3px;cursor:pointer;margin:0;">
                            <span style="font-size:12px;color:#475569;">色</span>
                            <input type="color" id="wysiwygColor" value="#000000"
                                onchange="applyWysiwygColor(this.value);"
                                style="width:28px;height:28px;padding:2px;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;">
                        </label>
                        <button type="button"
                            onclick="document.execCommand('removeFormat');document.getElementById('scheduleTextEditor').focus();"
                            title="書式をクリア"
                            style="padding:0 8px;height:28px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;cursor:pointer;font-size:11px;color:#64748b;">クリア</button>
                    </div>
                    <!-- エディタ本体 -->
                    <div id="scheduleTextEditor"
                        contenteditable="true"
                        style="min-height:120px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:0 0 5px 5px;font-size:13px;font-family:inherit;line-height:1.7;outline:none;word-break:break-all;"
                    ><?php echo $schedule_text; ?></div>
                </div>
                <button type="submit" class="btn btn-primary">保存する</button>
            </form>
            <script>
            // カラーピッカー用: エディタを離れる前の選択範囲を保存
            var savedRange = null;

            function saveSelection() {
                var sel = window.getSelection();
                if (sel && sel.rangeCount > 0) savedRange = sel.getRangeAt(0).cloneRange();
            }

            function restoreSelection() {
                if (!savedRange) return false;
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(savedRange);
                return true;
            }

            function applyWysiwygColor(color) {
                var ed = document.getElementById('scheduleTextEditor');
                ed.focus();
                restoreSelection();
                document.execCommand('foreColor', false, color);
            }

            (function() {
                var ed   = document.getElementById('scheduleTextEditor');
                var form = document.getElementById('scheduleTextForm');
                if (!ed) return;

                // フォーカス/ブラー時のborderスタイル＆選択範囲保存
                ed.addEventListener('focus', function() {
                    this.style.borderColor = '#2563eb';
                    this.style.boxShadow   = '0 0 0 3px rgba(37,99,235,.15)';
                });
                ed.addEventListener('blur', function() {
                    this.style.borderColor = '#cbd5e1';
                    this.style.boxShadow   = '';
                    saveSelection(); // フォーカスが外れる直前に選択範囲を保存
                });

                // submit時にinnerHTMLをhidden inputへコピー
                form.addEventListener('submit', function() {
                    document.getElementById('scheduleTextInput').value = ed.innerHTML;
                });

                // ツールバーBボタンのアクティブ状態を更新
                ed.addEventListener('mouseup', updateWysiwygState);
                ed.addEventListener('keyup',   updateWysiwygState);
            })();

            function updateWysiwygState() {
                var bold = document.getElementById('wysiwygBold');
                if (!bold) return;
                var active = document.queryCommandState('bold');
                bold.style.background = active ? '#1e3a5f' : '#fff';
                bold.style.color      = active ? '#fff'    : '#1e3a5f';
            }
            </script>
        </div>

        <!-- ─── webcal URL 管理 ─── -->
        <div class="card" id="webcal" style="margin-top:24px;">
            <div class="card-title">活動予定 webcal URL</div>
            <p style="font-size:13px;color:#64748b;margin-bottom:12px;">
                BANDなどから取得したwebcal URLをカテゴリ名とセットで登録します。<br>
                <code style="font-size:12px;">webcal://...</code> または <code style="font-size:12px;">https://...</code> 形式で入力してください。
            </p>

            <?php
            $webcal_sources = $db->query("SELECT * FROM webcal_sources ORDER BY sort_order, id")->fetchAll();
            $colors = WEBCAL_COLORS;
            ?>

            <?php if ($webcal_sources): ?>
            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">カテゴリ</th>
                        <th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">色</th>
                        <th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">URL</th>
                        <th style="padding:6px 10px;text-align:center;border:1px solid #e2e8f0;width:120px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($webcal_sources as $ws): ?>
                    <tr>
                        <td style="padding:6px 10px;border:1px solid #e2e8f0;white-space:nowrap;">
                            <span style="display:inline-block;background:<?= h($ws['color_bg']) ?>;color:<?= h($ws['color_text']) ?>;padding:2px 8px;border-radius:4px;font-weight:bold;">
                                <?= h($ws['category']) ?>
                            </span>
                        </td>
                        <td style="padding:6px 10px;border:1px solid #e2e8f0;white-space:nowrap;">
                            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= h($ws['color_bg']) ?>;border:1px solid <?= h($ws['color_text']) ?>;vertical-align:middle;"></span>
                        </td>
                        <td style="padding:6px 10px;border:1px solid #e2e8f0;word-break:break-all;font-size:12px;color:#475569;">
                            <?= h($ws['url']) ?>
                        </td>
                        <td style="padding:4px 6px;border:1px solid #e2e8f0;text-align:center;white-space:nowrap;">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action"    value="webcal_reorder">
                                <input type="hidden" name="webcal_id" value="<?= h($ws['id']) ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn btn-secondary btn-sm" style="padding:2px 7px;" title="上へ">↑</button>
                            </form>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action"    value="webcal_reorder">
                                <input type="hidden" name="webcal_id" value="<?= h($ws['id']) ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn btn-secondary btn-sm" style="padding:2px 7px;" title="下へ">↓</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('「<?= h(addslashes($ws['category'])) ?>」を削除しますか？')">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action"    value="delete_webcal">
                                <input type="hidden" name="webcal_id" value="<?= h($ws['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 7px;">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="font-size:13px;color:#94a3b8;margin-bottom:16px;">まだ登録されていません。</p>
            <?php endif; ?>

            <!-- 追加フォーム -->
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"     value="add_webcal">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="margin:0;">
                        <label style="font-size:12px;">カテゴリ名</label>
                        <input type="text" name="webcal_category" class="form-control" style="width:120px;" placeholder="例：練習" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label style="font-size:12px;">色</label>
                        <select name="webcal_color" class="form-control" style="width:110px;">
                            <?php foreach ($colors as $ci => $col): ?>
                                <option value="<?= $ci ?>"
                                    style="background:<?= h($col['bg']) ?>;color:<?= h($col['text']) ?>;">
                                    <?= h($col['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                        <label style="font-size:12px;">webcal URL</label>
                        <input type="text" name="webcal_url" class="form-control" placeholder="webcal://... または https://..." required>
                    </div>
                    <div style="margin-bottom:1px;">
                        <button type="submit" class="btn btn-primary">追加</button>
                    </div>
                </div>
            </form>
        </div>

        </div>
    </div>
</body>

</html>
