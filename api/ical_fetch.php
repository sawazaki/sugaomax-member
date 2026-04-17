<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');

$month1 = $_GET['month1'] ?? '';
$month2 = $_GET['month2'] ?? '';

if (!preg_match('/^\d{4}-\d{2}$/', $month1) || !preg_match('/^\d{4}-\d{2}$/', $month2)) {
    echo json_encode(['error' => 'invalid params']);
    exit;
}

$db      = get_db();
$sources = $db->query("SELECT * FROM webcal_sources ORDER BY sort_order, id")->fetchAll();

// ─── キャッシュ確認（TTL: 5分）──────────────────────────────────
$cache_dir  = DATA_DIR . '/cache/ical';
$cache_key  = md5(implode('|', array_column($sources, 'url')) . '|' . $month1 . '|' . $month2);
$cache_file = $cache_dir . '/' . $cache_key . '.json';
$cache_ttl  = 300;

if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

// force=1 の場合はキャッシュを削除して強制再取得
if (!empty($_GET['force'])) {
    @unlink($cache_file);
}

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    echo file_get_contents($cache_file);
    exit;
}

// ─── webcal 取得・パース ──────────────────────────────────────────
$range_start         = $month1 . '-01';
$range_end           = date('Y-m-t', strtotime($month2 . '-01'));
$events              = [];
$any_fetch_succeeded = false;

foreach ($sources as $source) {
    $url  = preg_replace('/^webcals?:\/\//i', 'https://', $source['url']);
    $data = ical_fetch_url($url);
    if ($data === false) continue;

    $any_fetch_succeeded = true;

    foreach (ical_parse($data) as $ev) {
        $dates = ical_event_dates($ev);
        foreach ($dates as $date) {
            if ($date < $range_start || $date > $range_end) continue;
            $events[] = [
                'date'       => $date,
                'summary'    => ical_unescape($ev['SUMMARY'] ?? ''),
                'time_start' => ical_parse_time($ev['DTSTART'] ?? ''),
                'time_end'   => ical_parse_time($ev['DTEND']   ?? ''),
                'category'   => $source['category'],
                'bg'         => $source['color_bg'],
                'text'       => $source['color_text'],
            ];
        }
    }
}

// 全ソース取得失敗 → stale キャッシュを返す
if (!empty($sources) && !$any_fetch_succeeded && file_exists($cache_file)) {
    echo file_get_contents($cache_file);
    exit;
}

usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

$json = json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);

// キャッシュ書き込み
if (is_writable($cache_dir)) {
    file_put_contents($cache_file, $json, LOCK_EX);
}

echo $json;

// ─── iCal helpers ──────────────────────────────────────────────────

function ical_fetch_url(string $url): string|false
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,  // TLS証明書検証を有効化
            CURLOPT_SSL_VERIFYHOST => 2,      // ホスト名検証を明示
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MinibasketCalendar/1.0)',
        ]);
        $result = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);
        return ($result !== false && $err === '') ? $result : false;
    }
    // file_get_contents フォールバック（デフォルトでTLS検証有効）
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    return @file_get_contents($url, false, $ctx);
}

function ical_parse(string $data): array
{
    // Unfold RFC 5545 folded lines
    $data  = preg_replace('/\r?\n[ \t]/', '', $data);
    $lines = preg_split('/\r?\n/', $data);

    $events   = [];
    $in_event = false;
    $current  = [];

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') {
            $in_event = true;
            $current  = [];
        } elseif ($line === 'END:VEVENT') {
            if ($in_event && isset($current['DTSTART'])) {
                $events[] = $current;
            }
            $in_event = false;
        } elseif ($in_event) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $raw_key = substr($line, 0, $pos);
                $val     = substr($line, $pos + 1);
                // Strip parameters (e.g. DTSTART;TZID=Asia/Tokyo → DTSTART)
                $key = strtoupper(explode(';', $raw_key)[0]);
                $current[$key] = $val;
            }
        }
    }

    return $events;
}

function ical_event_dates(array $ev): array
{
    $start = ical_parse_date($ev['DTSTART'] ?? '');
    if (!$start) return [];

    // For multi-day events, expand DTEND dates (exclusive end for DATE-type)
    $end = ical_parse_date($ev['DTEND'] ?? '');
    if (!$end || $end <= $start) return [$start];

    // DATE-only events: DTEND is exclusive (next day for single-day events)
    // Only expand if the raw DTSTART is a pure DATE (8 digits)
    if (preg_match('/^\d{8}$/', $ev['DTSTART'] ?? '')) {
        // End is exclusive, so last included date is $end - 1 day
        $dates = [];
        $ts    = strtotime($start);
        $ts_end = strtotime($end);
        while ($ts < $ts_end) {
            $dates[] = date('Y-m-d', $ts);
            $ts      = strtotime('+1 day', $ts);
        }
        return $dates;
    }

    return [$start];
}

function ical_parse_time(string $val): ?string
{
    // 純粋な日付のみ（終日イベント）→ 時刻なし
    if (preg_match('/^\d{8}$/', $val)) return null;
    // UTC日時: JST(+9h)に変換
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/', $val, $m)) {
        $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        $ts += 9 * 3600;
        return date('G:i', $ts); // 例: "9:00", "18:30"
    }
    // ローカル日時
    if (preg_match('/^\d{8}T(\d{2})(\d{2})/', $val, $m)) {
        $h = ltrim($m[1], '0') ?: '0';
        return $h . ':' . $m[2]; // 例: "9:00", "18:30"
    }
    return null;
}

function ical_parse_date(string $val): ?string
{
    // Pure DATE: 20260415
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $val, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    // DATETIME with Z suffix (UTC) → convert to JST (+9h)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/', $val, $m)) {
        $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        $ts += 9 * 3600;
        return date('Y-m-d', $ts);
    }
    // DATETIME without Z (local time)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $val, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    return null;
}

function ical_unescape(string $s): string
{
    return str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', "\n", "\n", '\\'], $s);
}
