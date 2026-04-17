<?php
require_once __DIR__ . '/includes/db.php';
require_login();

// ローカルライブラリファイルの存在確認（あればCDN不要）
$html2canvas_src = file_exists(__DIR__ . '/js/html2canvas.min.js')
    ? '/js/html2canvas.min.js'
    : null;
$jspdf_src = file_exists(__DIR__ . '/js/jspdf.umd.min.js')
    ? '/js/jspdf.umd.min.js'
    : null;

$db = get_db();

// ── 当番データ取得 ─────────────────────────────────────────────
$grade_circ = ['1' => '①', '2' => '②', '3' => '③', '4' => '④', '5' => '⑤', '6' => '⑥'];

$practice_groups = array_fill_keys(range('A', 'J'), []);
foreach (
    $db->query("
    SELECT last_name, grade, practice_duty FROM members
    WHERE active=1 AND practice_duty IS NOT NULL AND practice_duty!='' AND has_sibling=0
    ORDER BY practice_duty, practice_duty_order
")->fetchAll() as $m
) {
    $k = $m['practice_duty'];
    if (isset($practice_groups[$k]))
        $practice_groups[$k][] = ($grade_circ[$m['grade']] ?? $m['grade']) . $m['last_name'];
}

$match_groups = array_fill_keys(['1', '2', '3', '4'], []);
foreach (
    $db->query("
    SELECT last_name, grade, match_duty FROM members
    WHERE active=1 AND match_duty IS NOT NULL AND match_duty!='' AND has_sibling=0 AND grade>3
    ORDER BY match_duty, match_duty_order
")->fetchAll() as $m
) {
    $k = $m['match_duty'];
    if (isset($match_groups[$k]))
        $match_groups[$k][] = ($grade_circ[$m['grade']] ?? $m['grade']) . $m['last_name'];
}

$webcal_count    = (int)$db->query("SELECT COUNT(*) FROM webcal_sources")->fetchColumn();
$schedule_text   = $db->query("SELECT value FROM app_settings WHERE key='schedule_text'")->fetchColumn() ?: '';

// デフォルト月: 今月と来月
$tz  = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);
$default_month1 = $now->format('Y-m');
$now->modify('+1 month');
$default_month2 = $now->format('Y-m');

// プルダウン用: 前月 〜 1年半先
$month_options = [];
$cur = new DateTime('first day of -1 month', $tz);
for ($i = 0; $i < 20; $i++) {
    $month_options[] = $cur->format('Y-m');
    $cur->modify('+1 month');
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>活動予定 - 菅生マックス</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        /* ── コントロールエリア ── */
        .schedule-controls {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .schedule-controls label {
            font-weight: bold;
            font-size: 14px;
            white-space: nowrap;
        }

        .schedule-controls select {
            font-size: 14px;
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }

        #gen-status {
            font-size: 13px;
            color: #64748b;
        }

        #download-area {
            display: flex;
            gap: 8px;
        }

        /* ── PDFシートラッパー（スケーリング用） ── */
        #pdf-sheet-wrapper {
            overflow-x: auto;
            background: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
        }

        /* ── PDFシート本体（A4横 297mm×210mm = 1122px×794px @96dpi） ── */
        #pdf-sheet {
            width: 1122px;
            height: 794px;
            background: #fff;
            box-sizing: border-box;
            padding: 19px;
            font-family: 'Hiragino Kaku Gothic ProN', 'Hiragino Sans', Meiryo, sans-serif;
            display: flex;
            flex-direction: column;
            gap: 0;
            transform-origin: top left;
            position: relative;
        }

        /* ── シートヘッダー ── */
        .sheet-header-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            border-bottom: 1px solid #1e3a5f;
            padding-bottom: 4px;
            margin-bottom: 6px;
            flex-shrink: 0;
        }

        .sheet-header-row h2 {
            font-size: 15px;
            font-weight: bold;
            margin: 0;
        }

        .sheet-header-row .sheet-period {
            font-size: 13px;
            color: #475569;
        }

        /* ── 上下2行レイアウト ── */
        .sheet-layout {
            display: flex;
            flex-direction: column;
            flex: 1;
            gap: 10px;
            min-height: 0;
        }

        /* 上段: カレンダー2列（等高） */
        .sheet-row-top {
            width: 100%;
            display: flex;
            gap: 12px;
            flex: 1;
            min-height: 0;
            align-items: stretch;
        }

        .sheet-cal-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
        }

        /* 下段: 注意事項・練習当番・試合当番を1行に */
        .sheet-row-bottom {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
            align-items: flex-start;
        }

        .sheet-duty-col {
            flex: 0 0 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        #dutyPractice {
            width: 220px;
        }

        #dutyMatch {
            width: 290px;
        }

        /* ── 予定テキスト列（残り幅を全て使う） ── */
        .sheet-text-col {
            flex: 1 1 0;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .sheet-text-body {
            flex: 1;
            border-top: 1px solid #1e293b;
            border-radius: 0 0 3px 3px;
            padding: 5px 7px;
            font-size: 10px;
            line-height: 1.7;
            color: #1e293b;
            word-break: break-all;
            overflow: hidden;
        }

        .sheet-text-body div,
        .sheet-text-body p {
            margin: 0;
        }

        /* ── カレンダー ── */
        .cal-month-title {
            font-size: 12px;
            font-weight: bold;
            padding: 3px 6px;
            border-radius: 3px;
            margin-bottom: 3px;
            flex-shrink: 0;
        }

        .cal-grid {
            flex: 1;
            display: flex;
            flex-direction: column;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }


        .cal-dow-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr) 1.5fr 1.5fr;
            flex-shrink: 0;
        }

        .cal-dow-cell {
            font-size: 10px;
            font-weight: bold;
            text-align: center;
            padding: 2px 0;
            background: #1e3a5f;
            color: #fff;
        }

        .cal-dow-cell.sun {
            color: #fca5a5;
        }

        .cal-dow-cell.sat {
            color: #93c5fd;
        }

        .cal-weeks {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .cal-week-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr) 1.5fr 1.5fr;
            flex: 1;
            border-top: 1px solid #e2e8f0;
        }

        .cal-week-row:first-child {
            border-top: none;
        }

        .cal-cell {
            border-right: 1px solid #e2e8f0;
            padding: 2px 3px;
            overflow: hidden;
            min-height: 0;
        }

        .cal-cell:last-child {
            border-right: none;
        }

        .cal-cell.empty {
            background: #f8fafc;
        }



        .cal-day-num {
            font-size: 10px;
            font-weight: bold;
            line-height: 1.4;
        }

        .cal-day-num.sun {
            color: #ef4444;
        }

        .cal-day-num.sat {
            color: #3b82f6;
        }

        .cal-event {
            font-size: 10px;
            padding: 1px 3px;
            border-radius: 2px;
            margin-top: 2px;
            word-break: break-all;
            overflow-wrap: break-word;
            line-height: 1.3;
        }

        /* ── 当番表 ── */
        .duty-title-bar {
            font-size: 11px;
            font-weight: bold;
            padding: 3px 7px;
            margin-bottom: 2px;
            border-radius: 3px 3px 0 0;
            flex-shrink: 0;
        }

        .duty-compact-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .duty-compact-table th {
            background: #334155;
            color: #fff;
            padding: 2px 5px;
            text-align: center;
            white-space: nowrap;
        }

        .duty-compact-table td {
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            padding: 1px 4px;
            vertical-align: middle;
        }

        .duty-compact-table td.duty-key {
            font-weight: bold;
            text-align: center;
            width: 22px;
            background: #f8fafc;
            white-space: nowrap;
        }

        .duty-compact-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .duty-compact-table tr:nth-child(even) td.duty-key {
            background: #f1f5f9;
        }

        /* ── 凡例 ── */
        .legend-row {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            font-size: 9px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        /* ── ローディング ── */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, .8);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #475569;
            z-index: 10;
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="container">
        <div class="flex items-center justify-between mb-16">
            <h1 class="page-title" style="margin-bottom:0">活動予定</h1>
        </div>

        <?php if ($webcal_count === 0): ?>
            <div class="alert alert-danger" style="margin-bottom:12px;">
                予定の取得先が未設定です。<a href="/settings.php#webcal">設定画面</a>でwebcal URLを登録してください。
            </div>
        <?php endif; ?>

        <!-- コントロール -->
        <div class="schedule-controls">
            <label for="startMonth">表示開始月：</label>
            <select id="startMonth" onchange="onMonthChange()">
                <?php foreach ($month_options as $ym): ?>
                    <?php
                    [$y, $m] = explode('-', $ym);
                    $label = $y . '年' . ltrim($m, '0') . '月';
                    $sel   = ($ym === $default_month1) ? ' selected' : '';
                    ?>
                    <option value="<?= h($ym) ?>" <?= $sel ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button id="btn-refresh" class="btn btn-sm" onclick="loadAndRender(true)">↺ 更新</button>
            <button class="btn btn-primary btn-sm" onclick="generatePDF()">PDFを生成</button>
            <button class="btn btn-secondary btn-sm" onclick="generateJPG()">JPGを生成</button>
            <span id="gen-status"></span>
            <div id="download-area"></div>
        </div>

        <!-- PDFシートプレビュー -->
        <div id="pdf-sheet-wrapper">
            <div id="pdf-sheet">
                <div class="loading-overlay" id="loadingOverlay">読み込み中...</div>

                <!-- ヘッダー行 -->
                <div class="sheet-header-row">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <img src="/images/sugaomax-logo.svg" alt="菅生マックス" style="height:22px;display:block;">
                        <h2 style="margin:0;">菅生マックス &nbsp;活動予定</h2>
                        <span id="legendRow" style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;"></span>
                    </div>
                    <span class="sheet-period" style="white-space:nowrap;">
                        <span id="sheetPeriod"></span>
                        <span id="sheetGenDate" style="font-size:11px;color:#94a3b8;margin-left:14px;"></span>
                    </span>
                </div>

                <!-- 上段: カレンダー2列 -->
                <div class="sheet-layout">
                    <div class="sheet-row-top">
                        <div class="sheet-cal-col" id="calCol1"></div>
                        <div class="sheet-cal-col" id="calCol2"></div>
                    </div>
                    <!-- 下段: 注意事項・練習当番・試合当番 を1行に -->
                    <div class="sheet-row-bottom">
                        <div class="sheet-text-col" id="scheduleText"></div>
                        <div class="sheet-duty-col" id="dutyPractice"></div>
                        <div class="sheet-duty-col" id="dutyMatch"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ライブラリ（ローカル優先。js/html2canvas.min.js / js/jspdf.umd.min.js を配置すればCDN不要） -->
    <?php if ($html2canvas_src): ?>
    <script src="<?= h($html2canvas_src) ?>"></script>
    <?php else: ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"
            integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php endif; ?>
    <?php if ($jspdf_src): ?>
    <script src="<?= h($jspdf_src) ?>"></script>
    <?php else: ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"
            integrity="sha512-qZvrmS2ekKPF2mSznTQsxqPgnpkI4DNTlrdUmTzrDgektczlKNRRhy5X5AAOnx5S09ydFYWWNSfcEqDTTHgtNA=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php endif; ?>

    <script>
        // ─── 埋め込みデータ ───────────────────────────────────────
        const scheduleText = <?= json_encode($schedule_text, JSON_UNESCAPED_UNICODE) ?>;

        const dutyData = {
            practice: <?= json_encode($practice_groups, JSON_UNESCAPED_UNICODE) ?>,
            match: <?= json_encode($match_groups,    JSON_UNESCAPED_UNICODE) ?>
        };
        const JA_MONTHS = ['', '1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
        const DOW_JA = ['月', '火', '水', '木', '金', '土', '日']; // 月曜始まり

        // hex カラーを白に近づけて薄くする（factor: 0=元の色, 1=白）
        function lightenHex(hex, factor) {
            hex = hex.replace(/^#/, '');
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const r = parseInt(hex.slice(0, 2), 16);
            const g = parseInt(hex.slice(2, 4), 16);
            const b = parseInt(hex.slice(4, 6), 16);
            const nr = Math.round(r + (255 - r) * factor);
            const ng = Math.round(g + (255 - g) * factor);
            const nb = Math.round(b + (255 - b) * factor);
            return `rgb(${nr},${ng},${nb})`;
        }


        let currentMonth1 = '<?= h($default_month1) ?>';
        let currentMonth2 = '<?= h($default_month2) ?>';

        // ─── 月変更 ───────────────────────────────────────────────
        function onMonthChange() {
            const sel = document.getElementById('startMonth');
            currentMonth1 = sel.value;
            const [y, m] = currentMonth1.split('-').map(Number);
            const next = new Date(y, m, 1); // m is 1-based, so new Date(y,m,1) = first of next month
            currentMonth2 = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0');
            loadAndRender();
        }

        // ─── メイン: データ取得 → 描画 ───────────────────────────
        async function loadAndRender(force = false) {
            const overlay = document.getElementById('loadingOverlay');
            const btnRefresh = document.getElementById('btn-refresh');
            overlay.textContent = '読み込み中...';
            overlay.style.display = '';
            if (btnRefresh) btnRefresh.disabled = true;
            try {
                const params = new URLSearchParams({ month1: currentMonth1, month2: currentMonth2 });
                if (force) params.set('force', '1');
                const res = await fetch(`/api/ical_fetch.php?${params}`);
                const json = await res.json();
                const events = json.events || [];
                renderAll(events);
            } catch (e) {
                console.error(e);
                overlay.textContent = '予定の取得に失敗しました';
                return;
            } finally {
                if (btnRefresh) btnRefresh.disabled = false;
            }
            overlay.style.display = 'none';
        }

        // ─── 全体描画 ─────────────────────────────────────────────
        function renderAll(events) {
            // イベントを日付でグループ化
            const evMap = {};
            for (const ev of events) {
                if (!evMap[ev.date]) evMap[ev.date] = [];
                evMap[ev.date].push(ev);
            }

            const [y1, m1] = currentMonth1.split('-').map(Number);
            const [y2, m2] = currentMonth2.split('-').map(Number);

            // シート期間ラベル
            document.getElementById('sheetPeriod').textContent =
                `${y1}年${m1}月 ・ ${y2}年${m2}月`;
            const now = new Date();
            document.getElementById('sheetGenDate').textContent =
                `${now.getFullYear()}年${now.getMonth()+1}月${now.getDate()}日作成`;

            // カレンダー描画
            renderCalendarCol(document.getElementById('calCol1'), y1, m1, evMap);
            renderCalendarCol(document.getElementById('calCol2'), y2, m2, evMap);

            // テキスト・当番表描画
            renderScheduleText(document.getElementById('scheduleText'));
            renderPracticeDuty(document.getElementById('dutyPractice'));
            renderMatchDuty(document.getElementById('dutyMatch'));

            // 凡例描画（ヘッダーのタイトル右横に）
            renderLegend(document.getElementById('legendRow'), events);
        }

        // ─── カレンダー1ヶ月描画 ──────────────────────────────────
        function renderCalendarCol(container, year, month, evMap) {
            // 月曜始まり: 月=0, 火=1, ..., 土=5, 日=6
            const firstDow = (new Date(year, month - 1, 1).getDay() + 6) % 7;
            const daysInMonth = new Date(year, month, 0).getDate();
            const prefix = `${year}-${String(month).padStart(2, '0')}`;

            // 月タイトル
            let html = `<div class="cal-month-title">${year}年 ${month}月</div>`;

            // グリッド
            html += `<div class="cal-grid">`;

            // 曜日ヘッダー（月〜日）
            html += `<div class="cal-dow-row">`;
            const dowClasses = ['', '', '', '', '', 'sat', 'sun'];
            for (let d = 0; d < 7; d++) {
                html += `<div class="cal-dow-cell ${dowClasses[d]}">${DOW_JA[d]}</div>`;
            }
            html += `</div>`;

            // 週行（最大6行）
            html += `<div class="cal-weeks">`;
            let day = 1;
            for (let week = 0; week < 6; week++) {
                html += `<div class="cal-week-row">`;
                for (let dow = 0; dow < 7; dow++) {
                    const cellNum = week * 7 + dow;
                    if (cellNum < firstDow || day > daysInMonth) {
                        html += `<div class="cal-cell empty"></div>`;
                    } else {
                        const dateStr = `${prefix}-${String(day).padStart(2, '0')}`;
                        const dowClass = dow === 5 ? 'sat' : dow === 6 ? 'sun' : '';
                        html += `<div class="cal-cell">`;
                        html += `<div class="cal-day-num ${dowClass}">${day}</div>`;
                        const dayEvents = evMap[dateStr] || [];
                        for (const ev of dayEvents) {
                            const timeStr = ev.time_start ?
                                (ev.time_end ? `${ev.time_start}〜${ev.time_end}` : ev.time_start) :
                                '';
                            html += `<div class="cal-event" style="background:${lightenHex(ev.bg, 0.45)};color:${e(ev.text)}" title="${e(ev.summary)}">${e(ev.summary)}${timeStr ? `<br><span style="font-size:8px;">${timeStr}</span>` : ''}</div>`;
                        }
                        html += `</div>`;
                        day++;
                    }
                }
                html += `</div>`;
                if (day > daysInMonth) break;
            }
            html += `</div></div>`;

            container.innerHTML = html;
        }

        // ─── テキスト列描画 ───────────────────────────────────────
        function renderScheduleText(container) {
            let html = `<div class="duty-title-bar">■注意事項</div>`;
            // サニタイズ済みHTMLをそのまま描画。タグなしのプレーンテキストは後方互換で \n→<br> 変換
            let content = scheduleText;
            if (!/<[a-zA-Z]/.test(content)) {
                content = e(content).replace(/\n/g, '<br>');
            }
            html += `<div class="sheet-text-body">${content}</div>`;
            container.innerHTML = html;
        }

        // ─── 練習当番描画 ─────────────────────────────────────────
        function renderPracticeDuty(container) {
            let html = `<div class="duty-title-bar">練習当番</div>`;
            html += `<table class="duty-compact-table">`;
            html += `<tbody>`;
            for (const [key, members] of Object.entries(dutyData.practice)) {
                const names = members.length ? members.join('　') : '—';
                html += `<tr><td class="duty-key">${e(key)}</td><td>${e(names)}</td></tr>`;
            }
            html += `</tbody></table>`;
            container.innerHTML = html;
        }

        // ─── 試合当番描画 ─────────────────────────────────────────
        function renderMatchDuty(container) {
            let html = `<div class="duty-title-bar">試合当番</div>`;
            html += `<table class="duty-compact-table">`;
            html += `<tbody>`;
            for (const [key, members] of Object.entries(dutyData.match)) {
                const names = members.length ? members.join('　') : '—';
                html += `<tr><td class="duty-key">${e(key)}</td><td>${e(names)}</td></tr>`;
            }
            html += `</tbody></table>`;
            html += `<p style="font-size:8.5px;color:#475569;margin-top:1px;line-height:1.5;">※4年生は対象となる試合の時のみ当番を担当します</p>`;
            container.innerHTML = html;
        }

        // ─── 凡例描画 ─────────────────────────────────────────────
        function renderLegend(container, events) {
            const seen = {};
            for (const ev of events) {
                if (!seen[ev.category]) seen[ev.category] = {
                    bg: ev.bg,
                    text: ev.text
                };
            }
            const cats = Object.entries(seen);
            if (!cats.length) return;

            let html = '';
            for (const [cat, col] of cats) {
                html += `<span style="display:inline-flex;align-items:center;gap:3px;">
            <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:${e(col.bg)};border:1px solid ${e(col.text)};flex-shrink:0;"></span>
            <span style="font-size:10px;color:#374151;">${e(cat)}</span>
        </span>`;
            }
            container.innerHTML = html;
        }

        // ─── スケーリング ─────────────────────────────────────────
        function scaleSheet() {
            const wrapper = document.getElementById('pdf-sheet-wrapper');
            const sheet = document.getElementById('pdf-sheet');
            const available = wrapper.clientWidth - 32; // 16px padding × 2
            const scale = Math.min(1, available / 1122);
            sheet.style.transform = `scale(${scale})`;
            wrapper.style.height = Math.ceil(794 * scale + 32) + 'px';
        }

        window.addEventListener('resize', scaleSheet);

        // ─── PDF生成 ──────────────────────────────────────────────
        async function generatePDF() {
            const status = document.getElementById('gen-status');
            status.textContent = '生成中…';
            // 一時的に等倍に戻す
            const sheet = document.getElementById('pdf-sheet');
            const prevTransform = sheet.style.transform;
            sheet.style.transform = 'scale(1)';
            document.getElementById('pdf-sheet-wrapper').style.height = '';

            try {
                const canvas = await html2canvas(sheet, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: false,
                    backgroundColor: '#ffffff',
                    width: 1122,
                    height: 794,
                });
                const imgData = canvas.toDataURL('image/jpeg', 0.95);
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'landscape',
                    unit: 'mm',
                    format: 'a4'
                });
                pdf.addImage(imgData, 'JPEG', 0, 0, 297, 210);
                const blob = pdf.output('blob');
                const url = URL.createObjectURL(blob);
                showDownload(url, `活動予定_${currentMonth1}.pdf`);
                status.textContent = '生成完了';
            } catch (err) {
                console.error(err);
                status.textContent = '生成に失敗しました';
            } finally {
                sheet.style.transform = prevTransform;
                scaleSheet();
            }
        }

        // ─── JPG生成 ──────────────────────────────────────────────
        async function generateJPG() {
            const status = document.getElementById('gen-status');
            status.textContent = '生成中…';
            const sheet = document.getElementById('pdf-sheet');
            const prevTransform = sheet.style.transform;
            sheet.style.transform = 'scale(1)';
            document.getElementById('pdf-sheet-wrapper').style.height = '';

            try {
                const canvas = await html2canvas(sheet, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: false,
                    backgroundColor: '#ffffff',
                    width: 1122,
                    height: 794,
                });
                canvas.toBlob(blob => {
                    const url = URL.createObjectURL(blob);
                    showDownload(url, `活動予定_${currentMonth1}.jpg`);
                    status.textContent = '生成完了';
                }, 'image/jpeg', 0.95);
            } catch (err) {
                console.error(err);
                status.textContent = '生成に失敗しました';
            } finally {
                sheet.style.transform = prevTransform;
                scaleSheet();
            }
        }

        // ─── ダウンロードボタン表示（同名ファイルは置き換え）────────
        function showDownload(url, filename) {
            const area = document.getElementById('download-area');
            const existing = area.querySelector(`a[data-filename="${CSS.escape(filename)}"]`);
            if (existing) {
                URL.revokeObjectURL(existing.href); // 旧オブジェクトURLを解放
                existing.remove();
            }
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.dataset.filename = filename;
            a.className = 'btn btn-success btn-sm';
            a.textContent = filename + ' をダウンロード';
            area.appendChild(a);
        }

        // ─── エスケープ ───────────────────────────────────────────
        function e(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ─── 初期化 ───────────────────────────────────────────────
        scaleSheet();
        loadAndRender();
    </script>
</body>

</html>