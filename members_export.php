<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$all_fields = [
    'grade'           => '学年',
    'number'          => '背番号',
    'last_name'       => '姓',
    'first_name'      => '名',
    'romaji'          => 'ローマ字',
    'gender'          => '性別',
    'school'          => '所属校',
    'height'          => '身長',
    'reversible_bibs' => 'リバビブ',
    'blue_bibs'       => '青ビブ',
    'practice_duty'   => '練習当番',
    'match_duty'      => '試合当番',
];
$required_fields = ['grade', 'last_name', 'first_name'];

// CSVダウンロード処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    verify_csrf();
    $selected = [];
    foreach (array_keys($all_fields) as $key) {
        if (in_array($key, $required_fields) || !empty($_POST['fields'][$key])) {
            $selected[] = $key;
        }
    }

    $db = get_db();
    $members = $db->query("SELECT * FROM members WHERE active=1 ORDER BY grade DESC, number, last_name, first_name")->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sugaomax_member_' . date('YmdHi') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM（Excelで文字化けしないように）
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // ヘッダー行
    $header = array_map(fn($k) => $all_fields[$k], $selected);
    fputcsv($out, $header);

    // データ行
    foreach ($members as $m) {
        $row = [];
        foreach ($selected as $key) {
            if ($key === 'grade') {
                $row[] = $m['grade'] . '年';
            } else {
                $row[] = csv_safe($m[$key] ?? '');
            }
        }
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSVエクスポート - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <h1 class="page-title">部員CSVエクスポート</h1>

    <div class="card" style="max-width:480px">
        <div class="card-title">エクスポートする項目を選択</div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="export">
            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                <?php foreach ($all_fields as $key => $label):
                    $required = in_array($key, $required_fields);
                ?>
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; cursor:<?= $required ? 'default' : 'pointer' ?>;">
                    <input type="checkbox"
                        name="fields[<?= h($key) ?>]"
                        value="1"
                        <?= $required ? 'checked disabled' : 'checked' ?>
                        style="width:16px; height:16px;">
                    <?= h($label) ?>
                    <?php if ($required): ?>
                        <span style="font-size:11px; color:#94a3b8;">（必須）</span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="flex gap-8">
                <button type="submit" class="btn btn-primary">CSVダウンロード</button>
                <a href="/members.php" class="btn btn-outline">キャンセル</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
