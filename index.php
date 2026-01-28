<?php
require_once __DIR__ . '/includes/db.php';

if (enlil_admin_exists()) {
    header('Location: /login.php');
    exit;
}

header('Location: /register.php');
exit;
