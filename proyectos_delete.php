<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/projects.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /proyectos_list.php');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($projectId <= 0) {
    $_SESSION['flash_error'] = 'ID de proyecto inválido.';
    header('Location: /proyectos_list.php');
    exit;
}

try {
    $deleted = enlil_projects_delete($projectId);
    if ($deleted) {
        $_SESSION['flash_success'] = 'Proyecto borrado.';
    } else {
        $_SESSION['flash_error'] = 'No se pudo borrar el proyecto.';
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'No se pudo borrar el proyecto.';
}

header('Location: /proyectos_list.php');
exit;
