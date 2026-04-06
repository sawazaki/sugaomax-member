<?php
define('DB_PATH', __DIR__ . '/../data/minibasket.db');

$session_secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $session_secure,
    'samesite' => 'Lax',
]);
session_start();

// パスワード設定ファイル（data/ はGitignore済み）
$_config_path = __DIR__ . '/../data/config.php';
if (file_exists($_config_path)) {
    require_once $_config_path;
} elseif (basename($_SERVER['SCRIPT_FILENAME']) !== 'setup.php') {
    header('Location: /setup.php');
    exit;
}
unset($_config_path);

function require_login()
{
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login.php');
        exit;
    }
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
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            grade      INTEGER NOT NULL,
            number     INTEGER,
            school     TEXT,
            height     INTEGER,
            active     INTEGER NOT NULL DEFAULT 1,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
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

    return $pdo;
}
