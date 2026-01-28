<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();
enlil_start_session();

$stats = enlil_avatar_refresh_all();
$_SESSION['flash_success'] = 'Avatares actualizados. OK: ' . $stats['ok'] . ', Fallos: ' . $stats['fail'] . '.';
if (!empty($stats['errors'])) {
    $_SESSION['flash_error'] = implode(' | ', $stats['errors']);
}
header('Location: /personas_list.php');
exit;
