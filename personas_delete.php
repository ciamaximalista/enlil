<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /personas_list.php');
    exit;
}

$personId = isset($_POST['person_id']) ? (int)$_POST['person_id'] : 0;

if ($personId <= 0) {
    $_SESSION['flash_error'] = 'ID de persona inválido.';
    header('Location: /personas_list.php');
    exit;
}

$deleted = enlil_people_delete($personId);
if ($deleted) {
    $_SESSION['flash_success'] = 'Persona borrada.';
} else {
    $_SESSION['flash_error'] = 'No se pudo borrar la persona.';
}

header('Location: /personas_list.php');
exit;
