<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar">
    <div class="navbar-brand"><img src="/images/sugaomax-logo-wht.svg" height="40" alt="菅生マックス"></div>
    <ul class="navbar-menu">
        <li><a href="/index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">ダッシュボード</a></li>
        <li><a href="/members.php" class="<?= $current === 'members.php' ? 'active' : '' ?>">部員管理</a></li>
        <li><a href="/matches.php" class="<?= in_array($current, ['matches.php', 'match_new.php', 'match_sheet.php']) ? 'active' : '' ?>">試合管理</a></li>
        <li><a href="/duty.php" class="<?= $current === 'duty.php' ? 'active' : '' ?>">当番</a></li>
        <li class="navbar-menu-spacer"></li>
        <li><a href="/settings.php" class="<?= $current === 'settings.php' ? 'active' : '' ?>">入部</a></li>
        <li>
            <form method="post" action="/logout.php" class="navbar-logout-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="navbar-link logout">ログアウト</button>
            </form>
        </li>
    </ul>
</nav>