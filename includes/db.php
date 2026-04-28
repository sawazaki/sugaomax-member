<?php
// data/ ディレクトリのパスを自動検出
// Xserver: includes/ の2つ上が public_html → その上の data/ を使用
// 開発環境: includes/ の1つ上（ドキュメントルート）内の data/ を使用
$_dr = dirname(__DIR__);
$_p  = dirname($_dr);
define('DATA_DIR',
    basename($_p) === 'public_html'
        ? dirname($_p) . '/data'   // Xserver: sugaomax.com/data/
        : $_dr . '/data'           // 開発環境: /var/www/html/data/
);
unset($_dr, $_p);
define('DB_PATH', DATA_DIR . '/minibasket.db');

$session_secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $session_secure,
    'samesite' => 'Lax',
]);
session_start();

// 検索エンジンインデックス拒否
header('X-Robots-Tag: noindex, nofollow, noarchive');

// パスワード設定ファイル（data/ はGitignore済み）
$_config_path = DATA_DIR . '/config.php';
if (file_exists($_config_path)) {
    require_once $_config_path;
} elseif (basename($_SERVER['SCRIPT_FILENAME']) !== 'setup.php') {
    header('Location: /setup.php');
    exit;
}
unset($_config_path);

function require_login(): void
{
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login.php');
        exit;
    }
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_editor(): bool
{
    return in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true);
}

function is_viewer(): bool
{
    return ($_SESSION['role'] ?? '') === 'viewer';
}

function require_editor(): void
{
    require_login();
    if (!is_editor()) {
        http_response_code(403);
        include __DIR__ . '/forbidden.php';
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        include __DIR__ . '/forbidden.php';
        exit;
    }
}

function member_name($m)
{
    $fn = $m['first_name'] ?? '';
    return ($m['last_name'] ?? '') . ($fn !== '' ? '　' . $fn : '');
}

function h($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($session_token) || $session_token === '' || !hash_equals($session_token, $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

function csv_safe($value)
{
    $value = (string)$value;
    $trimmed = ltrim($value);

    if ($trimmed !== '' && preg_match('/^[=+\-@]/u', $trimmed)) {
        return "'" . $value;
    }

    return $value;
}

function get_db()
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $data_dir = dirname(DB_PATH);
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            last_name      TEXT    NOT NULL DEFAULT '',
            first_name     TEXT    NOT NULL DEFAULT '',
            grade          INTEGER NOT NULL,
            number         INTEGER,
            school         TEXT,
            height         INTEGER,
            practice_duty  TEXT,
            match_duty     TEXT,
            active         INTEGER NOT NULL DEFAULT 1,
            created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS matches (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            match_date TEXT    NOT NULL,
            opponent   TEXT    NOT NULL,
            venue      TEXT,
            match_type TEXT,
            note       TEXT,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS match_members (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id  INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
            member_id INTEGER NOT NULL REFERENCES members(id),
            position  TEXT
        )
    ");

    // カラム追加（既存DBへのマイグレーション）
    $existing_members = array_column($pdo->query("PRAGMA table_info(members)")->fetchAll(), 'name');
    if (!in_array('reversible_bibs', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN reversible_bibs INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('blue_bibs', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN blue_bibs INTEGER NOT NULL DEFAULT 0");
    }
    if (in_array('license_no', $existing_members)) {
        $pdo->exec("ALTER TABLE members DROP COLUMN license_no");
    }
    if (!in_array('gender', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN gender TEXT");
    }
    if (!in_array('romaji', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN romaji TEXT");
    }
    if (!in_array('practice_duty', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN practice_duty TEXT");
    }
    if (!in_array('match_duty', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN match_duty TEXT");
    }
    if (!in_array('has_sibling', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN has_sibling INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('parent_name', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN parent_name TEXT");
    }
    if (!in_array('parent_relationship', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN parent_relationship TEXT");
    }
    if (!in_array('phone', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN phone TEXT");
    }
    if (!in_array('emergency_name', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN emergency_name TEXT");
    }
    if (!in_array('emergency_relationship', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN emergency_relationship TEXT");
    }
    if (!in_array('emergency_phone', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN emergency_phone TEXT");
    }
    if (!in_array('enrollment_date', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN enrollment_date TEXT");
    }
    if (!in_array('height_token', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN height_token TEXT");
    }
    if (!in_array('height_short_code', $existing_members)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN height_short_code TEXT");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_members_height_short_code ON members(height_short_code) WHERE height_short_code IS NOT NULL AND height_short_code != ''");
    // name → last_name / first_name への分割マイグレーション
    if (in_array('name', $existing_members)) {
        if (!in_array('last_name', $existing_members)) {
            $pdo->exec("ALTER TABLE members ADD COLUMN last_name TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('first_name', $existing_members)) {
            $pdo->exec("ALTER TABLE members ADD COLUMN first_name TEXT NOT NULL DEFAULT ''");
        }
        // 全角スペース → 半角スペースの順で分割、スペースなしの場合は全体を姓に
        $pdo->exec("
            UPDATE members SET
                last_name = CASE
                    WHEN INSTR(name, '\u{3000}') > 0 THEN TRIM(SUBSTR(name, 1, INSTR(name, '\u{3000}') - 1))
                    WHEN INSTR(name, ' ')         > 0 THEN TRIM(SUBSTR(name, 1, INSTR(name, ' ') - 1))
                    ELSE TRIM(name)
                END,
                first_name = CASE
                    WHEN INSTR(name, '\u{3000}') > 0 THEN TRIM(SUBSTR(name, INSTR(name, '\u{3000}') + 1))
                    WHEN INSTR(name, ' ')         > 0 THEN TRIM(SUBSTR(name, INSTR(name, ' ') + 1))
                    ELSE ''
                END
            WHERE last_name = '' AND first_name = ''
        ");
        $pdo->exec("ALTER TABLE members DROP COLUMN name");
    }

    $existing_matches = array_column($pdo->query("PRAGMA table_info(matches)")->fetchAll(), 'name');
    if (!in_array('coach', $existing_matches)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN coach TEXT");
    }
    if (!in_array('assistant_coach', $existing_matches)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN assistant_coach TEXT");
    }
    if (!in_array('title', $existing_matches)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN title TEXT");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webcal_sources (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            category   TEXT    NOT NULL,
            url        TEXT    NOT NULL,
            color_bg   TEXT    NOT NULL DEFAULT '#dbeafe',
            color_text TEXT    NOT NULL DEFAULT '#1d4ed8',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    return $pdo;
}
