<?php
require_once __DIR__ . '/includes/auth.php';

// Clear checklist events file

enlil_require_login();
enlil_start_session();

$path = __DIR__ . '/data/checklist_events.xml';
if (file_exists($path)) {
    @unlink($path);
}

$_SESSION['flash_success'] = 'Actualizaciones de tareas borradas.';
header('Location: /personas_list.php');
exit;
