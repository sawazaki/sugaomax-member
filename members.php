<?php
require_once __DIR__ . '/includes/db.php';
require_login();

$db  = get_db();
$msg = '';

// 削除（論理削除）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!is_editor()) { http_response_code(403); exit('forbidden'); }
    $id = (int)$_POST['id'];
    $db->prepare("UPDATE members SET active=0 WHERE id=?")->execute([$id]);
    $msg = '部員を削除しました。';
}

// タブ（性別フィルター）
$tab = in_array($_GET['tab'] ?? '', ['男子', '女子']) ? $_GET['tab'] : 'ALL';

// タブ（所属校フィルター）
$known_schools = ['犬蔵小学校', '菅生小学校', '稗原小学校'];
$school_tab = in_array($_GET['school'] ?? '', array_merge($known_schools, ['その他'])) ? $_GET['school'] : 'ALL';

// タブ（学年フィルター）
$known_grades = [1, 2, 3, 4, 5, 6];
$grade_tab = in_array((int)($_GET['grade'] ?? 0), $known_grades) ? (int)$_GET['grade'] : 'ALL';

// ソート
$sort_cols = ['grade' => 'grade', 'number' => 'number', 'last_name' => 'last_name', 'gender' => 'gender', 'school' => 'school', 'reversible_bibs' => 'reversible_bibs', 'blue_bibs' => 'blue_bibs'];
$sort = isset($sort_cols[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'grade';
$dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : ($sort === 'grade' ? 'desc' : 'asc'));
$col  = $sort_cols[$sort];

// WHERE句の構築
$where_parts = ['active=1'];
$params = [];

if ($tab !== 'ALL') {
    $where_parts[] = 'gender=?';
    $params[] = $tab;
}

if ($school_tab === 'その他') {
    $where_parts[] = '(school NOT IN (?, ?, ?) OR school IS NULL OR school = \'\')';
    $params = array_merge($params, $known_schools);
} elseif ($school_tab !== 'ALL') {
    $where_parts[] = 'school=?';
    $params[] = $school_tab;
}

if ($grade_tab !== 'ALL') {
    $where_parts[] = 'grade=?';
    $params[] = $grade_tab;
}

$where = implode(' AND ', $where_parts);
$sql = "SELECT * FROM members WHERE {$where} ORDER BY {$col} {$dir}, last_name asc, first_name asc";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// 共通フィルター条件（性別・所属校・学年）を動的に構築するヘルパー
function build_filter_where($tab, $school_tab, $grade_tab, $known_schools, $exclude_gender = false, $exclude_school = false, $exclude_grade = false) {
    $parts = ['active=1'];
    $p = [];
    if (!$exclude_gender && $tab !== 'ALL') {
        $parts[] = 'gender=?'; $p[] = $tab;
    }
    if (!$exclude_school) {
        if ($school_tab === 'その他') {
            $parts[] = '(school NOT IN (?, ?, ?) OR school IS NULL OR school = \'\')';
            $p = array_merge($p, $known_schools);
        } elseif ($school_tab !== 'ALL') {
            $parts[] = 'school=?'; $p[] = $school_tab;
        }
    }
    if (!$exclude_grade && $grade_tab !== 'ALL') {
        $parts[] = 'grade=?'; $p[] = $grade_tab;
    }
    return [implode(' AND ', $parts), $p];
}

// 性別タブごとの人数（所属校・学年フィルター考慮）
$counts = ['ALL' => 0, '男子' => 0, '女子' => 0];
[$cw, $cp] = build_filter_where($tab, $school_tab, $grade_tab, $known_schools, true, false, false);
$count_stmt = $db->prepare("SELECT gender, COUNT(*) as cnt FROM members WHERE {$cw} GROUP BY gender");
$count_stmt->execute($cp);
foreach ($count_stmt->fetchAll() as $row) {
    $counts['ALL'] += $row['cnt'];
    if (isset($counts[$row['gender']])) $counts[$row['gender']] = $row['cnt'];
}

// 所属校タブごとの人数（性別・学年フィルター考慮）
$school_counts = ['ALL' => 0, '犬蔵小学校' => 0, '菅生小学校' => 0, '稗原小学校' => 0, 'その他' => 0];
foreach ($known_schools as $sch) {
    [$cw, $cp] = build_filter_where($tab, $sch, $grade_tab, $known_schools, false, false, false);
    $s = $db->prepare("SELECT COUNT(*) FROM members WHERE {$cw}");
    $s->execute($cp);
    $school_counts[$sch] = (int)$s->fetchColumn();
    $school_counts['ALL'] += $school_counts[$sch];
}
// その他のカウント
[$cw, $cp] = build_filter_where($tab, 'その他', $grade_tab, $known_schools, false, false, false);
$s = $db->prepare("SELECT COUNT(*) FROM members WHERE {$cw}");
$s->execute($cp);
$school_counts['その他'] = (int)$s->fetchColumn();
$school_counts['ALL'] += $school_counts['その他'];

// 学年タブごとの人数（性別・所属校フィルター考慮）
$grade_counts = ['ALL' => 0];
foreach ($known_grades as $g) $grade_counts[$g] = 0;
[$cw, $cp] = build_filter_where($tab, $school_tab, $grade_tab, $known_schools, false, false, true);
$g_stmt = $db->prepare("SELECT grade, COUNT(*) as cnt FROM members WHERE {$cw} GROUP BY grade");
$g_stmt->execute($cp);
foreach ($g_stmt->fetchAll() as $row) {
    $grade_counts['ALL'] += $row['cnt'];
    if (isset($grade_counts[(int)$row['grade']])) $grade_counts[(int)$row['grade']] = $row['cnt'];
}

// クエリ文字列生成ヘルパー
function tab_qs($tab, $school_tab, $grade_tab) {
    $parts = [];
    if ($tab !== 'ALL')        $parts[] = 'tab=' . urlencode($tab);
    if ($school_tab !== 'ALL') $parts[] = 'school=' . urlencode($school_tab);
    if ($grade_tab !== 'ALL')  $parts[] = 'grade=' . urlencode($grade_tab);
    return $parts ? '?' . implode('&', $parts) : '?';
}

function sort_link($label, $key, $cur_sort, $cur_dir, $tab, $school_tab, $grade_tab) {
    $next_dir = ($cur_sort === $key && $cur_dir === 'asc') ? 'desc' : 'asc';
    $arrow    = $cur_sort === $key ? ($cur_dir === 'asc' ? ' ▲' : ' ▼') : '';
    $qs = tab_qs($tab, $school_tab, $grade_tab);
    $sep = $qs === '?' ? '' : '&';
    return '<a href="' . $qs . $sep . 'sort=' . h($key) . '&dir=' . h($next_dir) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . h($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>部員管理 - 菅生マックス チーム管理</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .tab-bar { display: flex; gap: 4px; margin-bottom: 0; border-bottom: 2px solid #e2e8f0; flex-wrap: wrap; }
        .tab-bar a {
            padding: 8px 20px; border-radius: 6px 6px 0 0; font-size: 14px; font-weight: bold;
            color: #64748b; text-decoration: none; border: 1px solid transparent; border-bottom: none;
            margin-bottom: -2px; transition: background .15s, color .15s;
        }
        .tab-bar a:hover { background: #f1f5f9; color: #1e3a5f; }
        .tab-bar a.active { background: #fff; color: #2563eb; border-color: #e2e8f0; border-bottom-color: #fff; }
        .tab-count { font-size: 11px; color: #94a3b8; margin-left: 4px; }
        .tab-bar a.active .tab-count { color: #93c5fd; }
        .tab-bar-sub { margin-top: 4px; margin-bottom: 0; }
        .tab-bar-sub a { padding: 6px 14px; font-size: 13px; }
        .tab-bar-last { margin-bottom: 16px; }
        .action-btns { flex-wrap: wrap; }
        @media (max-width: 640px) {
            .action-btns { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<div class="container">
    <div class="flex items-center justify-between mb-16">
        <h1 class="page-title" style="margin-bottom:0">部員管理</h1>
        <div class="flex gap-8">
            <a href="/member_add.php" class="btn btn-primary btn-sm">＋ 部員を追加</a>
            <a href="/members_import.php" class="btn btn-outline btn-sm">CSVインポート</a>
            <a href="/members_export.php" class="btn btn-outline btn-sm">CSVエクスポート</a>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if (!empty($_GET['added'])): ?><div class="alert alert-success">部員を追加しました。</div><?php endif; ?>

    <!-- 性別タブ -->
    <div class="tab-bar">
        <?php foreach (['ALL' => 'ALL', '男子' => '男子', '女子' => '女子'] as $key => $label):
            $url = tab_qs($key === 'ALL' ? 'ALL' : $key, $school_tab, $grade_tab);
        ?>
            <a href="<?= $url ?>" class="<?= $tab === $key ? 'active' : '' ?>">
                <?= h($label) ?><span class="tab-count"><?= $counts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- 所属校タブ -->
    <div class="tab-bar tab-bar-sub">
        <?php
        $school_tabs = ['ALL' => 'ALL', '犬蔵小学校' => '犬蔵', '菅生小学校' => '菅生', '稗原小学校' => '稗原', 'その他' => 'その他'];
        foreach ($school_tabs as $key => $label):
            $url = tab_qs($tab, $key === 'ALL' ? 'ALL' : $key, $grade_tab);
        ?>
            <a href="<?= $url ?>" class="<?= $school_tab === $key ? 'active' : '' ?>">
                <?= h($label) ?><span class="tab-count"><?= $school_counts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- 学年タブ -->
    <div class="tab-bar tab-bar-sub tab-bar-last">
        <?php
        $grade_tabs = ['ALL' => 'ALL', 6 => '6年', 5 => '5年', 4 => '4年', 3 => '3年', 2 => '2年', 1 => '1年'];
        foreach ($grade_tabs as $key => $label):
            $url = tab_qs($tab, $school_tab, $key === 'ALL' ? 'ALL' : $key);
        ?>
            <a href="<?= $url ?>" class="<?= (string)$grade_tab === (string)$key ? 'active' : '' ?>">
                <?= h($label) ?><span class="tab-count"><?= $grade_counts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- 部員一覧 -->
    <div class="card">
        <div class="card-title">
            <?= $tab === 'ALL' ? '全部員' : h($tab) ?><?= $school_tab !== 'ALL' ? '／' . h($school_tab) : '' ?><?= $grade_tab !== 'ALL' ? '／' . h($grade_tab) . '年' : '' ?>（<?= count($members) ?>名）
        </div>
        <?php if (empty($members)): ?>
            <p class="text-muted text-center" style="padding:24px 0;">部員がまだ登録されていません。</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= sort_link('学年', 'grade', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('#', 'number', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('氏名', 'last_name', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('性別', 'gender', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('所属校', 'school', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('リバビブ', 'reversible_bibs', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th><?= sort_link('青ビブ', 'blue_bibs', $sort, $dir, $tab, $school_tab, $grade_tab) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= h($m['grade']) ?>年</td>
                    <td><?= $m['number'] !== null ? h($m['number']) : '—' ?></td>
                    <td style="white-space:nowrap"><a href="/member_edit.php?id=<?= h($m['id']) ?>"><?= h(member_name($m)) ?></a></td>
                    <td><?= h($m['gender'] ?? '—') ?></td>
                    <td style="white-space:nowrap"><?= h($m['school'] ?? '—') ?></td>
                    <td style="text-align:center"><?= ($m['reversible_bibs'] ?? 0) ?: '' ?></td>
                    <td style="text-align:center"><?= ($m['blue_bibs'] ?? 0) ?: '' ?></td>
                    <td>
                        <div class="flex gap-8 action-btns">
                            <a href="/member_edit.php?id=<?= h($m['id']) ?>" class="btn btn-outline btn-sm">編集</a>
                            <form method="post" onsubmit="return confirm('<?= h(member_name($m)) ?>を削除しますか？')">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h($m['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">削除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
