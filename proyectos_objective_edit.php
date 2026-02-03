<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$objectiveId = isset($_GET['objective_id']) ? (int)$_GET['objective_id'] : 0;

$project = enlil_projects_get($projectId);
if (!$project) {
    header('Location: /proyectos_list.php');
    exit;
}

$teams = enlil_teams_all();
$people = enlil_people_all();
$projectTeamIds = array_values(array_filter(array_map('intval', $project['team_ids'] ?? [])));
$projectPeople = array_values(array_filter($people, function ($person) use ($projectTeamIds) {
    $personTeamIds = array_values(array_filter(array_map('intval', $person['team_ids'] ?? [])));
    return !empty(array_intersect($projectTeamIds, $personTeamIds));
}));
$selectedTeams = $project['team_ids'];

$objective = null;
 $objectiveAllTasks = [];
foreach ($project['objectives'] as $obj) {
    if ((int)$obj['id'] === $objectiveId) {
        $objective = $obj;
        break;
    }
}

$isNew = $objectiveId === 0 || !$objective;
if ($isNew) {
    $objective = [
        'id' => 0,
        'name' => '',
        'due_date' => '',
        'depends_on' => [],
        'tasks' => [],
    ];
}
if (!empty($objective['tasks'])) {
    $objectiveAllTasks = $objective['tasks'];
    $objective['tasks'] = array_values(array_filter($objective['tasks'], function ($task) {
        return empty($task['parent_id']);
    }));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_objective'])) {
        $objectives = array_values(array_filter($project['objectives'], function ($obj) use ($objectiveId) {
            return (int)$obj['id'] !== $objectiveId;
        }));
        enlil_projects_update($projectId, $project['name'], $project['description'], $project['team_ids'], $objectives);
        header('Location: /proyectos_view.php?id=' . $projectId);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');
    $dependsOn = $_POST['depends_on'] ?? [];
    if (!is_array($dependsOn)) {
        $dependsOn = [];
    }
    $dependsOn = array_values(array_filter(array_map('intval', $dependsOn)));

    $tasksInput = $_POST['tasks'] ?? [];
    if (!is_array($tasksInput)) {
        $tasksInput = [];
    }

    if ($name === '') {
        $errors[] = 'El nombre del objetivo es obligatorio.';
    }

    $maxObjectiveId = 0;
    $maxTaskId = 0;
    foreach ($project['objectives'] as $obj) {
        $maxObjectiveId = max($maxObjectiveId, (int)$obj['id']);
        foreach ($obj['tasks'] as $task) {
            $maxTaskId = max($maxTaskId, (int)$task['id']);
        }
    }

    $objectiveId = $isNew ? $maxObjectiveId + 1 : $objectiveId;

    $existingObjective = null;
    foreach ($project['objectives'] as $obj) {
        if ((int)$obj['id'] === $objectiveId) {
            $existingObjective = $obj;
            break;
        }
    }
    $existingTasks = $existingObjective['tasks'] ?? [];
    $existingDerived = array_values(array_filter($existingTasks, function ($task) {
        return !empty($task['parent_id']);
    }));

    $tasks = [];
    foreach ($tasksInput as $tid => $tdata) {
        $taskName = trim((string)($tdata['name'] ?? ''));
        if ($taskName !== '') {
            if (function_exists('mb_substr')) {
                $taskName = mb_substr($taskName, 0, 100);
            } else {
                $taskName = substr($taskName, 0, 100);
            }
        }
        if ($taskName === '') {
            continue;
        }
        $recurrence = (string)($tdata['recurrence'] ?? 'puntual');
        if (!in_array($recurrence, ['puntual', 'semanal', 'mensual', 'mensual_semana'], true)) {
            $recurrence = 'puntual';
        }
        $taskId = ctype_digit((string)$tid) ? (int)$tid : ++$maxTaskId;
        $taskDependsRaw = $tdata['depends_on'] ?? [];
        if (!is_array($taskDependsRaw)) {
            $taskDependsRaw = [];
        }
        $taskDepends = array_values(array_filter(array_map('intval', $taskDependsRaw)));
        $respRaw = $tdata['responsible_ids'] ?? [];
        if (!is_array($respRaw)) {
            $respRaw = [];
        }
        $responsibleIds = array_values(array_filter(array_map('intval', $respRaw)));
        $status = ($tdata['status'] ?? '') === 'done' ? 'done' : 'pending';
        $tasks[] = [
            'id' => $taskId,
            'name' => $taskName,
            'due_date' => trim((string)($tdata['due_date'] ?? '')),
            'status' => $status,
            'recurrence' => $recurrence,
            'parent_id' => 0,
            'depends_on' => $taskDepends,
            'responsible_ids' => $responsibleIds,
        ];
    }

    $recurringTasks = [];
    $cutoff = date('Y-m-d');
    $existingByParentDate = [];
    foreach ($existingDerived as $task) {
        $due = (string)($task['due_date'] ?? '');
        $status = (string)($task['status'] ?? '');
        $parentId = (int)($task['parent_id'] ?? 0);
        if ($parentId <= 0 || $due === '') {
            continue;
        }
        $keep = false;
        if ($status === 'done') {
            $keep = true;
        } elseif ($due < $cutoff) {
            $keep = true;
        }
        if ($keep) {
            $recurringTasks[] = $task;
            if (!isset($existingByParentDate[$parentId])) {
                $existingByParentDate[$parentId] = [];
            }
            $existingByParentDate[$parentId][$due] = $task;
        }
    }

    $objectiveEnd = $dueDate;
    $weekdayOrdinal = function (string $date): array {
        $ts = strtotime($date);
        if ($ts === false) {
            return [0, 0];
        }
        $weekday = (int)date('N', $ts); // 1..7
        $day = (int)date('j', $ts);
        $ordinal = (int)ceil($day / 7);
        return [$ordinal, $weekday];
    };
    $nthWeekdayDate = function (int $year, int $month, int $ordinal, int $weekday): string {
        if ($ordinal <= 0 || $weekday <= 0) {
            return '';
        }
        $firstDayTs = strtotime($year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-01');
        if ($firstDayTs === false) {
            return '';
        }
        $firstWeekday = (int)date('N', $firstDayTs);
        $offset = ($weekday - $firstWeekday + 7) % 7;
        $day = 1 + $offset + 7 * ($ordinal - 1);
        $daysInMonth = (int)date('t', $firstDayTs);
        if ($day > $daysInMonth) {
            $lastDayTs = strtotime($year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)$daysInMonth, 2, '0', STR_PAD_LEFT));
            if ($lastDayTs === false) {
                return '';
            }
            $lastWeekday = (int)date('N', $lastDayTs);
            $backOffset = ($lastWeekday - $weekday + 7) % 7;
            $day = $daysInMonth - $backOffset;
        }
        return $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    };
    if ($objectiveEnd !== '') {
        foreach ($tasks as $baseTask) {
            $recurrence = $baseTask['recurrence'] ?? 'puntual';
            if ($recurrence === 'puntual') {
                continue;
            }
            $startDate = $baseTask['due_date'] ?? '';
            if ($startDate === '') {
                continue;
            }
            $parentId = (int)$baseTask['id'];
            $current = $startDate;
            $baseDay = (int)substr($startDate, 8, 2);
            [$baseOrdinal, $baseWeekday] = $weekdayOrdinal($startDate);
            while (true) {
                if ($recurrence === 'semanal') {
                    $nextTs = strtotime('+7 days', strtotime($current));
                } elseif ($recurrence === 'mensual') {
                    $currTs = strtotime($current);
                    $nextMonth = (int)date('n', strtotime('+1 month', $currTs));
                    $nextYear = (int)date('Y', strtotime('+1 month', $currTs));
                    $daysInMonth = (int)date('t', strtotime($nextYear . '-' . str_pad((string)$nextMonth, 2, '0', STR_PAD_LEFT) . '-01'));
                    $nextDay = min($baseDay, $daysInMonth);
                    $nextTs = strtotime($nextYear . '-' . str_pad((string)$nextMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)$nextDay, 2, '0', STR_PAD_LEFT));
                } else {
                    $currTs = strtotime($current);
                    $nextMonth = (int)date('n', strtotime('+1 month', $currTs));
                    $nextYear = (int)date('Y', strtotime('+1 month', $currTs));
                    $nextDate = $nthWeekdayDate($nextYear, $nextMonth, $baseOrdinal, $baseWeekday);
                    $nextTs = $nextDate !== '' ? strtotime($nextDate) : 0;
                }
                if (!$nextTs) {
                    break;
                }
                $current = date('Y-m-d', $nextTs);
                if ($current > $objectiveEnd) {
                    break;
                }
                if (isset($existingByParentDate[$parentId]) && isset($existingByParentDate[$parentId][$current])) {
                    continue;
                }
                $maxTaskId++;
                $recurringTasks[] = [
                    'id' => $maxTaskId,
                    'name' => $baseTask['name'],
                    'due_date' => $current,
                    'status' => 'pending',
                    'recurrence' => 'derivada',
                    'parent_id' => $parentId,
                    'depends_on' => [],
                    'responsible_ids' => $baseTask['responsible_ids'],
                ];
            }
        }
    }

    $objective = [
        'id' => $objectiveId,
        'name' => $name,
        'due_date' => $dueDate,
        'depends_on' => $dependsOn,
        'tasks' => array_values(array_merge($tasks, $recurringTasks)),
    ];

    if ($errors) {
        $objective['tasks'] = array_values(array_filter($objective['tasks'], function ($task) {
            return empty($task['parent_id']);
        }));
    }

    if (!$errors) {
        $objectives = [];
        foreach ($project['objectives'] as $obj) {
            if ((int)$obj['id'] === $objectiveId) {
                continue;
            }
            $objectives[] = $obj;
        }
        $objectives[] = $objective;
        enlil_projects_update($projectId, $project['name'], $project['description'], $project['team_ids'], $objectives);
        header('Location: /proyectos_view.php?id=' . $projectId);
        exit;
    }
}

$taskOptions = [];
foreach ($objective['tasks'] as $task) {
    $taskOptions[] = [
        'id' => (int)$task['id'],
        'name' => $task['name'],
    ];
}

function enlil_task_groups_objective(array $tasks): array {
    $byId = [];
    $deps = [];
    $children = [];
    foreach ($tasks as $task) {
        $id = (int)$task['id'];
        $byId[$id] = $task;
        $deps[$id] = array_values(array_filter(array_map('intval', $task['depends_on'] ?? [])));
    }
    foreach ($deps as $id => $list) {
        foreach ($list as $depId) {
            $children[$depId][] = $id;
        }
    }
    $rootMemo = [];
    $visiting = [];
    $rootOf = function ($id) use (&$rootOf, &$deps, &$rootMemo, &$visiting): int {
        if (isset($rootMemo[$id])) {
            return $rootMemo[$id];
        }
        if (isset($visiting[$id])) {
            return $id;
        }
        $visiting[$id] = true;
        $list = $deps[$id] ?? [];
        if (!$list) {
            $rootMemo[$id] = $id;
            unset($visiting[$id]);
            return $id;
        }
        $roots = [];
        foreach ($list as $depId) {
            $roots[] = $rootOf($depId);
        }
        $root = $roots ? min($roots) : $id;
        $rootMemo[$id] = $root;
        unset($visiting[$id]);
        return $root;
    };
    $depthMemo = [];
    $depthVisiting = [];
    $depthOf = function ($id) use (&$depthOf, &$deps, &$depthMemo, &$depthVisiting): int {
        if (isset($depthMemo[$id])) {
            return $depthMemo[$id];
        }
        if (isset($depthVisiting[$id])) {
            return 0;
        }
        $depthVisiting[$id] = true;
        $maxDepth = 0;
        foreach ($deps[$id] ?? [] as $depId) {
            $maxDepth = max($maxDepth, $depthOf((int)$depId) + 1);
        }
        unset($depthVisiting[$id]);
        $depthMemo[$id] = $maxDepth;
        return $maxDepth;
    };

    $independent = [];
    $columns = [];
    foreach ($byId as $id => $task) {
        $hasDeps = !empty($deps[$id]);
        $hasChildren = !empty($children[$id]);
        if (!$hasDeps && !$hasChildren) {
            $independent[] = $task;
            continue;
        }
        $root = $rootOf($id);
        if (!isset($columns[$root])) {
            $columns[$root] = [];
        }
        $columns[$root][] = $task;
    }

    $sortByOrder = function (&$list) use (&$depthOf) {
        usort($list, function ($a, $b) use (&$depthOf) {
            $da = $a['due_date'] ?? '';
            $db = $b['due_date'] ?? '';
            $depthA = $depthOf((int)$a['id']);
            $depthB = $depthOf((int)$b['id']);
            if ($depthA !== $depthB) {
                return $depthA <=> $depthB;
            }
            if ($da === $db) {
                return 0;
            }
            if ($da === '') {
                return 1;
            }
            if ($db === '') {
                return -1;
            }
            return strcmp($db, $da);
        });
    };
    foreach ($columns as &$list) {
        $sortByOrder($list);
    }
    unset($list);
    $sortByOrder($independent);

    return [
        'columns' => $columns,
        'independent' => $independent,
    ];
}

enlil_page_header($isNew ? 'Nuevo objetivo' : 'Editar objetivo');
?>
    <main class="container">
        <div class="page-header">
            <div>
                <h1><?php echo $isNew ? 'Nuevo objetivo' : 'Editar objetivo'; ?></h1>
                <p class="muted"><?php echo htmlspecialchars($project['name']); ?></p>
            </div>
            <div class="actions">
                <a class="btn secondary" href="/proyectos_view.php?id=<?php echo (int)$project['id']; ?>">Volver</a>
                <?php if (!$isNew): ?>
                    <form method="post" class="inline-form" data-confirm="¿Seguro que quieres borrar este objetivo?">
                        <button class="btn danger" type="submit" name="delete_objective" value="1">Borrar objetivo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="form-card" autocomplete="off">
            <label>Nombre
                <input type="text" name="name" required value="<?php echo htmlspecialchars($objective['name']); ?>">
            </label>
            <label>Fecha límite
                <input type="date" name="due_date" value="<?php echo htmlspecialchars($objective['due_date']); ?>">
            </label>
            <label>Depende de
                <select multiple name="depends_on[]" class="multi">
                    <?php foreach ($project['objectives'] as $candidate): ?>
                        <?php if (!$isNew && (int)$candidate['id'] === (int)$objectiveId) { continue; } ?>
                        <?php $selected = in_array((int)$candidate['id'], $objective['depends_on'], true); ?>
                        <option value="<?php echo (int)$candidate['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($candidate['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="section-card">
                <div class="page-header">
                    <h2>Tareas</h2>
                    <button type="button" class="btn small secondary" id="add-task">Añadir tarea</button>
                </div>
                <?php $taskGroups = enlil_task_groups_objective($objective['tasks']); ?>
                <div class="tasks-list tasks-grid" id="tasks-list">
                    <?php foreach ($taskGroups['columns'] as $tasks): ?>
                        <div class="tasks-column">
                            <?php foreach ($tasks as $task): ?>
                                <?php $taskId = (int)$task['id']; ?>
                                <div class="task-card" data-tid="<?php echo $taskId; ?>">
                                <div class="task-header">
                                    <strong>Tarea #<?php echo $taskId; ?></strong>
                                    <div class="task-actions">
                                        <button class="btn small" type="submit">Guardar</button>
                                        <button type="button" class="btn small danger js-remove-task">Eliminar</button>
                                    </div>
                                </div>
                                <label>Nombre
                                    <input type="text" name="tasks[<?php echo $taskId; ?>][name]" value="<?php echo htmlspecialchars($task['name']); ?>" maxlength="100" required>
                                </label>
                                <label>Fecha límite
                                    <input type="date" name="tasks[<?php echo $taskId; ?>][due_date]" value="<?php echo htmlspecialchars($task['due_date']); ?>">
                                </label>
                                <label>Periodicidad
                                    <?php $recurrence = in_array(($task['recurrence'] ?? ''), ['puntual', 'semanal', 'mensual', 'mensual_semana'], true) ? $task['recurrence'] : 'puntual'; ?>
                                    <select name="tasks[<?php echo $taskId; ?>][recurrence]">
                                        <option value="puntual" <?php echo $recurrence === 'puntual' ? 'selected' : ''; ?>>Puntual</option>
                                        <option value="semanal" <?php echo $recurrence === 'semanal' ? 'selected' : ''; ?>>Semanal</option>
                                        <option value="mensual" <?php echo $recurrence === 'mensual' ? 'selected' : ''; ?>>Mensual</option>
                                        <option value="mensual_semana" <?php echo $recurrence === 'mensual_semana' ? 'selected' : ''; ?>>Como hoy de cada mes</option>
                                    </select>
                                </label>
                                <label>Estado
                                    <select name="tasks[<?php echo $taskId; ?>][status]">
                                        <option value="pending" <?php echo $task['status'] !== 'done' ? 'selected' : ''; ?>>No realizada</option>
                                        <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Realizada</option>
                                    </select>
                                </label>
                                <label>Depende de
                                    <select multiple name="tasks[<?php echo $taskId; ?>][depends_on][]" class="multi">
                                        <?php foreach ($taskOptions as $opt): ?>
                                            <?php if ((int)$opt['id'] === $taskId) { continue; } ?>
                                            <?php $selected = in_array((int)$opt['id'], $task['depends_on'], true); ?>
                                            <option value="<?php echo (int)$opt['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($opt['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="task-people">
                                    <span class="label">Responsables</span>
                                    <div class="checkbox-grid">
                                        <?php foreach ($projectPeople as $person): ?>
                                            <?php $checked = in_array((int)$person['id'], $task['responsible_ids'], true); ?>
                                            <?php
                                            $username = trim((string)($person['telegram_user'] ?? ''));
                                            $initial = $person['name'] !== '' ? (function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1)) : '?';
                                            $avatarLocal = '';
                                            if (!empty($person['telegram_user_id'])) {
                                                $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                                                if (file_exists($path)) {
                                                    $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                                                }
                                            }
                                            $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode(ltrim($username, '@')) . '.jpg' : '');
                                            ?>
                                            <label class="checkbox checkbox-person">
                                                <span class="avatar-wrap small">
                                                    <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                                    <?php if ($avatarUrl): ?>
                                                        <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                                    <?php endif; ?>
                                                </span>
                                                <span><?php echo htmlspecialchars($person['name']); ?></span>
                                                <input type="checkbox" name="tasks[<?php echo $taskId; ?>][responsible_ids][]" value="<?php echo (int)$person['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="tasks-column independent">
                        <?php foreach ($taskGroups['independent'] as $task): ?>
                            <?php $taskId = (int)$task['id']; ?>
                            <div class="task-card" data-tid="<?php echo $taskId; ?>">
                                <div class="task-header">
                                    <strong>Tarea #<?php echo $taskId; ?></strong>
                                    <div class="task-actions">
                                        <button class="btn small" type="submit">Guardar</button>
                                        <button type="button" class="btn small danger js-remove-task">Eliminar</button>
                                    </div>
                                </div>
                                <label>Nombre
                                    <input type="text" name="tasks[<?php echo $taskId; ?>][name]" value="<?php echo htmlspecialchars($task['name']); ?>" maxlength="100" required>
                                </label>
                                <label>Fecha límite
                                    <input type="date" name="tasks[<?php echo $taskId; ?>][due_date]" value="<?php echo htmlspecialchars($task['due_date']); ?>">
                                </label>
                                <label>Periodicidad
                                    <?php $recurrence = in_array(($task['recurrence'] ?? ''), ['puntual', 'semanal', 'mensual', 'mensual_semana'], true) ? $task['recurrence'] : 'puntual'; ?>
                                    <select name="tasks[<?php echo $taskId; ?>][recurrence]">
                                        <option value="puntual" <?php echo $recurrence === 'puntual' ? 'selected' : ''; ?>>Puntual</option>
                                        <option value="semanal" <?php echo $recurrence === 'semanal' ? 'selected' : ''; ?>>Semanal</option>
                                        <option value="mensual" <?php echo $recurrence === 'mensual' ? 'selected' : ''; ?>>Mensual</option>
                                        <option value="mensual_semana" <?php echo $recurrence === 'mensual_semana' ? 'selected' : ''; ?>>Como hoy de cada mes</option>
                                    </select>
                                </label>
                                <label>Estado
                                    <select name="tasks[<?php echo $taskId; ?>][status]">
                                        <option value="pending" <?php echo $task['status'] !== 'done' ? 'selected' : ''; ?>>No realizada</option>
                                        <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Realizada</option>
                                    </select>
                                </label>
                                <label>Depende de
                                    <select multiple name="tasks[<?php echo $taskId; ?>][depends_on][]" class="multi">
                                        <?php foreach ($taskOptions as $opt): ?>
                                            <?php if ((int)$opt['id'] === $taskId) { continue; } ?>
                                            <?php $selected = in_array((int)$opt['id'], $task['depends_on'], true); ?>
                                            <option value="<?php echo (int)$opt['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($opt['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="task-people">
                                    <span class="label">Responsables</span>
                                    <div class="checkbox-grid">
                                        <?php foreach ($projectPeople as $person): ?>
                                            <?php $checked = in_array((int)$person['id'], $task['responsible_ids'], true); ?>
                                            <?php
                                            $username = trim((string)($person['telegram_user'] ?? ''));
                                            $initial = $person['name'] !== '' ? (function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1)) : '?';
                                            $avatarLocal = '';
                                            if (!empty($person['telegram_user_id'])) {
                                                $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                                                if (file_exists($path)) {
                                                    $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                                                }
                                            }
                                            $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode(ltrim($username, '@')) . '.jpg' : '');
                                            ?>
                                            <label class="checkbox checkbox-person">
                                                <span class="avatar-wrap small">
                                                    <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                                    <?php if ($avatarUrl): ?>
                                                        <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                                    <?php endif; ?>
                                                </span>
                                                <span><?php echo htmlspecialchars($person['name']); ?></span>
                                                <input type="checkbox" name="tasks[<?php echo $taskId; ?>][responsible_ids][]" value="<?php echo (int)$person['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button type="submit">Guardar objetivo</button>
        </form>
    </main>
<?php enlil_page_footer(); ?>
<script>
const tasksList = document.getElementById('tasks-list');
const addTaskBtn = document.getElementById('add-task');
let taskCounter = 0;

function getTaskOptions(excludeId) {
    const options = [];
    tasksList.querySelectorAll('.task-card').forEach(card => {
        const tid = card.dataset.tid;
        const nameInput = card.querySelector('input[name^="tasks"][name$="[name]"]');
        const name = nameInput ? nameInput.value.trim() : '';
        if (!name || tid === excludeId) {
            return;
        }
        options.push({ id: tid, name });
    });
    return options;
}

function refreshTaskDepends() {
    tasksList.querySelectorAll('.task-card').forEach(card => {
        const tid = card.dataset.tid;
        const select = card.querySelector('select[name^="tasks"][name$="[depends_on][]"]');
        if (!select) return;
        const selected = Array.from(select.selectedOptions).map(opt => opt.value);
        select.innerHTML = '';
        getTaskOptions(tid).forEach(opt => {
            const option = document.createElement('option');
            option.value = opt.id;
            option.textContent = opt.name;
            if (selected.includes(opt.id)) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    });
}

function createTaskCard() {
    taskCounter += 1;
    const tid = 'new_' + taskCounter;
    const wrapper = document.createElement('div');
    wrapper.className = 'task-card';
    wrapper.dataset.tid = tid;
    wrapper.innerHTML = `
        <div class="task-header">
            <strong>Tarea nueva</strong>
            <div class="task-actions">
                <button class="btn small" type="submit">Guardar</button>
                <button type="button" class="btn small danger js-remove-task">Eliminar</button>
            </div>
        </div>
        <label>Nombre
            <input type="text" name="tasks[${tid}][name]" maxlength="100" required>
        </label>
        <label>Fecha límite
            <input type="date" name="tasks[${tid}][due_date]">
        </label>
        <label>Periodicidad
            <select name="tasks[${tid}][recurrence]">
                <option value="puntual">Puntual</option>
                <option value="semanal">Semanal</option>
                <option value="mensual">Mensual</option>
                <option value="mensual_semana">Como hoy de cada mes</option>
            </select>
        </label>
        <label>Estado
            <select name="tasks[${tid}][status]">
                <option value="pending">No realizada</option>
                <option value="done">Realizada</option>
            </select>
        </label>
        <label>Depende de
            <select multiple name="tasks[${tid}][depends_on][]" class="multi"></select>
        </label>
        <div class="task-people">
            <span class="label">Responsables</span>
            <div class="checkbox-grid">
                <?php foreach ($projectPeople as $person): ?>
                    <?php
                    $username = trim((string)($person['telegram_user'] ?? ''));
                    $initial = $person['name'] !== '' ? (function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1)) : '?';
                    $avatarLocal = '';
                    if (!empty($person['telegram_user_id'])) {
                        $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                        if (file_exists($path)) {
                            $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                        }
                    }
                    $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode(ltrim($username, '@')) . '.jpg' : '');
                    ?>
                    <label class="checkbox checkbox-person">
                        <span class="avatar-wrap small">
                            <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                            <?php if ($avatarUrl): ?>
                                <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                            <?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars($person['name']); ?></span>
                        <input type="checkbox" name="tasks[${tid}][responsible_ids][]" value="<?php echo (int)$person['id']; ?>">
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    `;
    return wrapper;
}

addTaskBtn.addEventListener('click', () => {
    const independentColumn = tasksList.querySelector('.tasks-column.independent');
    if (independentColumn) {
        independentColumn.appendChild(createTaskCard());
    } else {
        tasksList.appendChild(createTaskCard());
    }
    refreshTaskDepends();
});

tasksList.addEventListener('click', (e) => {
    if (e.target.classList.contains('js-remove-task')) {
        const taskCard = e.target.closest('.task-card');
        if (taskCard) {
            taskCard.remove();
            refreshTaskDepends();
        }
    }
});

tasksList.addEventListener('input', (e) => {
    if (e.target && e.target.name && e.target.name.endsWith('[name]')) {
        refreshTaskDepends();
    }
});

document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || '¿Confirmas esta acción?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});

refreshTaskDepends();
</script>
