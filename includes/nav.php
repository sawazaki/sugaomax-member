<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar">
    <div class="navbar-brand"><img src="/images/sugaomax-logo-wht.svg" height="40" alt="菅生マックス"></div>
    <ul class="navbar-menu">
        <li><a href="/index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">ダッシュボード</a></li>
        <li><a href="/members.php" class="<?= $current === 'members.php' ? 'active' : '' ?>">部員管理</a></li>
        <li><a href="/matches.php" class="<?= in_array($current, ['matches.php', 'match_new.php', 'match_sheet.php']) ? 'active' : '' ?>">試合管理</a></li>
        <li><a href="/logout.php" class="logout">ログアウト</a></li>
    </ul>
</nav>