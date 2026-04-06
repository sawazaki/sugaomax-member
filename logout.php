<?php
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

verify_csrf();
$_SESSION = [];
session_destroy();
header('Location: /login.php');
exit;
