<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar">
    <div class="navbar-brand">
        <span><img src="/images/sugaomax-logo-wht.svg" height="40" alt="菅生マックス"></span>
        <?php if (is_viewer()): ?>
            <span style="font-size:11px;background:#f59e0b;color:#fff;padding:2px 7px;border-radius:4px;margin-left:8px;font-weight:bold;vertical-align:middle;">閲覧のみ</span>
        <?php endif; ?>
    </div>
    <button class="navbar-toggle" id="navbar-toggle" aria-label="メニューを開く" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <ul class="navbar-menu" id="navbar-menu">
        <li><a href="/index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">ダッシュボード</a></li>
        <li><a href="/members.php" class="<?= $current === 'members.php' ? 'active' : '' ?>">部員管理</a></li>
        <li><a href="/matches.php" class="<?= in_array($current, ['matches.php', 'match_new.php', 'match_sheet.php']) ? 'active' : '' ?>">試合管理</a></li>
        <li><a href="/duty.php" class="<?= $current === 'duty.php' ? 'active' : '' ?>">当番</a></li>
        <li class="navbar-menu-spacer"></li>
        <li><a href="/nyubu.php" class="<?= $current === 'nyubu.php' ? 'active' : '' ?>">入部</a></li>
        <?php if (is_admin()): ?>
            <li><a href="/settings.php" class="<?= $current === 'settings.php' ? 'active' : '' ?>">設定</a></li>
        <?php endif; ?>
        <li>
            <form method="post" action="/logout.php" class="navbar-logout-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="navbar-link logout">ログアウト</button>
            </form>
        </li>
    </ul>
</nav>
<script>
    (function() {
        var btn = document.getElementById('navbar-toggle');
        var menu = document.getElementById('navbar-menu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function() {
            var open = menu.classList.toggle('open');
            btn.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        // メニュー外タップで閉じる
        document.addEventListener('click', function(e) {
            if (!btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('open');
                btn.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>