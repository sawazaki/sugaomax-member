<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db = get_db();
$errors  = [];
$results = [];
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $file = $_FILES['csv'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'ファイルのアップロードに失敗しました。';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $errors[] = 'CSVファイル（.csv）を選択してください。';
    } else {
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            $errors[] = 'ファイルを読み込めませんでした。';
        } else {
            // BOM除去
            if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
            }

            // ファイル全体で文字コードを判定して一括変換
            $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
            }

            $handle = fopen('php://memory', 'r+');
            fwrite($handle, $raw);
            rewind($handle);

            $row_num    = 0;
            $imported   = 0;
            $skipped    = 0;
            $row_errors = [];

            $stmt = $db->prepare("INSERT INTO members (name, grade, number, school, height, reversible_bibs, blue_bibs) VALUES (?, ?, ?, ?, ?, ?, ?)");

            while (($cols = fgetcsv($handle)) !== false) {
                $row_num++;

                // ヘッダー行スキップ（1行目が「氏名」で始まる場合）
                if ($row_num === 1 && isset($cols[0]) && mb_strpos($cols[0], '氏名') !== false) {
                    continue;
                }

                // 空行スキップ
                if (count(array_filter($cols, fn($c) => trim($c) !== '')) === 0) {
                    continue;
                }

                $cols = array_map('trim', $cols);

                $name   = $cols[0] ?? '';
                $grade  = isset($cols[1]) && $cols[1] !== '' ? (int)$cols[1] : null;
                $number = isset($cols[2]) && $cols[2] !== '' ? (int)$cols[2] : null;
                $school = $cols[3] !== '' ? $cols[3] : null;
                $height          = isset($cols[4]) && $cols[4] !== '' ? (int)$cols[4] : null;
                $reversible_bibs = isset($cols[5]) && trim($cols[5]) !== '' ? (int)$cols[5] : null;
                $blue_bibs       = isset($cols[6]) && trim($cols[6]) !== '' ? (int)$cols[6] : null;

                // バリデーション
                $row_err = [];
                if ($name === '') $row_err[] = '氏名が空です';
                if ($grade === null || $grade < 1 || $grade > 6) $row_err[] = '学年が不正です（1〜6）';
                if ($number !== null && ($number < 0 || $number > 99)) $row_err[] = '背番号が不正です（0〜99）';
                if ($height !== null && ($height < 80 || $height > 220)) $row_err[] = '身長が不正です（80〜220）';

                if (!empty($row_err)) {
                    $row_errors[] = "{$row_num}行目「" . h($name) . "」: " . implode('、', $row_err);
                    $skipped++;
                    continue;
                }

                $stmt->execute([$name, $grade, $number, $school, $height, $reversible_bibs, $blue_bibs]);
                $results[] = ['name' => $name, 'grade' => $grade, 'number' => $number, 'school' => $school, 'height' => $height, 'reversible_bibs' => $reversible_bibs, 'blue_bibs' => $blue_bibs];
                $imported++;
            }
            fclose($handle);

            $errors = $row_errors;
            $done   = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>部員CSVインポート - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <h1 class="page-title">部員CSVインポート</h1>

    <?php if ($done): ?>
        <div class="alert alert-success">
            <?= count($results) ?>名をインポートしました。
            <?php if ($skipped > 0): ?> <?= $skipped ?>行はスキップされました。<?php endif; ?>
        </div>
        <?php if (!empty($results)): ?>
        <div class="card">
            <div class="card-title">インポート結果</div>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>氏名</th><th>学年</th><th>#</th><th>所属校</th><th>身長</th><th>リバビブ</th><th>青ビブ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= h($r['name']) ?></td>
                        <td><?= h($r['grade']) ?>年</td>
                        <td><?= $r['number'] !== null ? h($r['number']) : '—' ?></td>
                        <td><?= h($r['school'] ?? '—') ?></td>
                        <td><?= $r['height'] ? h($r['height']) . 'cm' : '—' ?></td>
                        <td style="text-align:center"><?= ($r['reversible_bibs'] ?? 0) ?: '' ?></td>
                        <td style="text-align:center"><?= ($r['blue_bibs'] ?? 0) ?: '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>以下の行でエラーが発生しました：</strong>
            <ul style="margin-top:8px;padding-left:18px;">
                <?php foreach ($errors as $e): ?>
                    <li><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width:560px">
        <div class="card-title">CSVファイルを選択</div>

        <div class="alert alert-info" style="margin-bottom:16px;">
            <strong>CSVフォーマット（1行目はヘッダー行として自動スキップ）</strong><br>
            <code style="font-size:12px;">氏名,学年,背番号,所属校,身長(cm),リバーシブルビブス,青ビブス</code><br>
            <span style="font-size:12px;">例: <code>山田太郎,6,5,○○小学校,145,1,0</code></span><br>
            <span style="font-size:12px;">※ 背番号・所属校・身長・ビブス番号は省略可。文字コードはUTF-8またはShift_JIS。</span>
        </div>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="form-group">
                <label>CSVファイル</label>
                <input type="file" name="csv" accept=".csv" class="form-control" required>
            </div>
            <div class="flex gap-8 mt-8">
                <button type="submit" class="btn btn-primary">インポート実行</button>
                <a href="/members.php" class="btn btn-secondary">キャンセル</a>
            </div>
        </form>
    </div>

    <div class="card" style="max-width:560px">
        <div class="card-title">サンプルCSVダウンロード</div>
        <p style="font-size:13px;color:#475569;margin-bottom:12px;">以下のリンクからサンプルファイルをダウンロードして編集できます。</p>
        <a href="/members_sample.csv" class="btn btn-outline btn-sm" download>サンプルCSVをダウンロード</a>
    </div>
</div>
</body>
</html>
