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
    $_SESSION['flash_error'] = 'Proyecto no válido.';
    header('Location: /proyectos_list.php');
    exit;
}

$project = enlil_projects_get($projectId);
if (!$project) {
    $_SESSION['flash_error'] = 'Proyecto no encontrado.';
    header('Location: /proyectos_list.php');
    exit;
}

$cutoffTs = time() - (30 * 24 * 60 * 60);
$removed = 0;
$objectives = $project['objectives'] ?? [];

foreach ($objectives as &$objective) {
    $tasks = is_array($objective['tasks'] ?? null) ? $objective['tasks'] : [];
    if (!$tasks) {
        continue;
    }

    $kept = [];
    $keptIds = [];
    foreach ($tasks as $task) {
        $status = (string)($task['status'] ?? '');
        $completedAt = trim((string)($task['completed_at'] ?? ''));
        $deleteTask = false;
        if ($status === 'done' && $completedAt !== '') {
            $completedTs = strtotime($completedAt);
            if ($completedTs !== false && $completedTs < $cutoffTs) {
                $deleteTask = true;
            }
        }
        if ($deleteTask) {
            $removed++;
            continue;
        }
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId > 0) {
            $keptIds[$taskId] = true;
        }
        $kept[] = $task;
    }

    foreach ($kept as &$task) {
        $depends = array_map('intval', (array)($task['depends_on'] ?? []));
        if (!$depends) {
            $task['depends_on'] = [];
            continue;
        }
        $filtered = [];
        foreach ($depends as $depId) {
            if ($depId > 0 && isset($keptIds[$depId])) {
                $filtered[] = $depId;
            }
        }
        $task['depends_on'] = array_values(array_unique($filtered));
    }
    unset($task);

    $objective['tasks'] = $kept;
}
unset($objective);

if ($removed > 0) {
    enlil_projects_update(
        $projectId,
        (string)($project['name'] ?? ''),
        (string)($project['description'] ?? ''),
        (array)($project['team_ids'] ?? []),
        $objectives
    );
    $_SESSION['flash_success'] = 'Se borraron ' . $removed . ' tareas cumplidas de más de 30 días y se ajustaron las dependencias.';
} else {
    $_SESSION['flash_error'] = 'No había tareas cumplidas de más de 30 días para borrar.';
}

header('Location: /proyectos_view.php?id=' . $projectId);
exit;
