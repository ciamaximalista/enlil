<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = enlil_projects_get($projectId);
if (!$project) {
    header('Location: /proyectos_list.php');
    exit;
}

$teams = enlil_teams_all();
$teamIds = array_map(function ($team) {
    return $team['id'];
}, $teams);
$people = enlil_people_all();
$peopleTeamMap = [];
foreach ($people as $person) {
    $peopleTeamMap[$person['id']] = $person['team_ids'];
}
$peopleDisplay = [];
foreach ($people as $person) {
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
    $peopleDisplay[] = [
        'id' => (int)$person['id'],
        'name' => $person['name'],
        'team_ids' => array_values(array_filter(array_map('intval', $person['team_ids'] ?? []))),
        'avatar_url' => $avatarUrl,
        'initial' => $initial,
    ];
}
$selectedTeams = $project['team_ids'] ?? [];
$selectedTeams = array_values(array_filter(array_map('intval', $selectedTeams)));
$peopleDisplayInProject = array_values(array_filter($peopleDisplay, function ($person) use ($selectedTeams) {
    if (!$selectedTeams) {
        return false;
    }
    return !empty(array_intersect($selectedTeams, $person['team_ids']));
}));

function enlil_task_groups(array $tasks): array {
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

$errors = [];
$name = $project['name'];
$description = $project['description'];
$selectedTeams = $project['team_ids'];
$objectives = $project['objectives'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $selectedTeams = $_POST['team_ids'] ?? [];
    if (!is_array($selectedTeams)) {
        $selectedTeams = [];
    }

    if ($name === '') {
        $errors[] = 'El nombre del proyecto es obligatorio.';
    }

    $selectedTeams = array_values(array_filter(array_map('intval', $selectedTeams), function ($id) use ($teamIds) {
        return in_array($id, $teamIds, true);
    }));

    $inputObjectives = $_POST['objectives'] ?? [];
    if (!is_array($inputObjectives)) {
        $inputObjectives = [];
    }

    $maxObjectiveId = 0;
    $maxTaskId = 0;
    foreach ($project['objectives'] as $obj) {
        $maxObjectiveId = max($maxObjectiveId, (int)$obj['id']);
        foreach ($obj['tasks'] as $task) {
            $maxTaskId = max($maxTaskId, (int)$task['id']);
        }
    }

    $objectiveIdMap = [];
    $objectiveList = [];
    foreach ($inputObjectives as $oid => $odata) {
        $objName = trim((string)($odata['name'] ?? ''));
        if ($objName === '') {
            continue;
        }
        if (ctype_digit((string)$oid)) {
            $objectiveId = (int)$oid;
        } else {
            $maxObjectiveId++;
            $objectiveId = $maxObjectiveId;
        }
        $objectiveIdMap[(string)$oid] = $objectiveId;
        $objectiveList[] = [
            'id' => $objectiveId,
            'name' => $objName,
            'due_date' => trim((string)($odata['due_date'] ?? '')),
            'depends_on' => $odata['depends_on'] ?? [],
            'tasks' => $odata['tasks'] ?? [],
        ];
    }

    $objectives = [];
    foreach ($objectiveList as $objectiveInput) {
        $dependsRaw = $objectiveInput['depends_on'];
        if (!is_array($dependsRaw)) {
            $dependsRaw = [];
        }
        $depends = array_values(array_filter(array_map('intval', $dependsRaw)));

        $tasksInput = $objectiveInput['tasks'];
        if (!is_array($tasksInput)) {
            $tasksInput = [];
        }
        $tasks = [];
        foreach ($tasksInput as $tid => $tdata) {
            $taskName = trim((string)($tdata['name'] ?? ''));
            if ($taskName === '') {
                continue;
            }
            if (ctype_digit((string)$tid)) {
                $taskId = (int)$tid;
            } else {
                $maxTaskId++;
                $taskId = $maxTaskId;
            }
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
                'depends_on' => $taskDepends,
                'responsible_ids' => $responsibleIds,
            ];
        }

        $objectives[] = [
            'id' => $objectiveInput['id'],
            'name' => $objectiveInput['name'],
            'due_date' => $objectiveInput['due_date'],
            'depends_on' => $depends,
            'tasks' => $tasks,
        ];
    }

    if (!$errors) {
        enlil_projects_update($projectId, $name, $description, $selectedTeams, $objectives);
        header('Location: /proyectos_list.php');
        exit;
    }
}

enlil_page_header('Editar proyecto');
?>
    <main class="container">
        <div class="page-header">
            <h1>Editar proyecto</h1>
            <a class="btn secondary" href="/proyectos_list.php">Volver</a>
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
            <label>Nombre del proyecto
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <label>Descripción (opcional)
                <input type="text" name="description" value="<?php echo htmlspecialchars($description); ?>">
            </label>

            <fieldset>
                <legend>Equipos implicados</legend>
                <?php if (!$teams): ?>
                    <p class="empty">No hay equipos aún. Crea un equipo primero.</p>
                <?php else: ?>
                    <div class="checkbox-grid">
                        <?php foreach ($teams as $team): ?>
                            <?php $checked = in_array($team['id'], $selectedTeams, true); ?>
                            <label class="checkbox">
                                <input type="checkbox" name="team_ids[]" value="<?php echo $team['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($team['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="section-card">
                <div class="page-header">
                    <h2>Objetivos</h2>
                    <button type="button" class="btn small secondary" id="add-objective">Añadir objetivo</button>
                </div>
                <p class="muted">Las dependencias entre objetivos y tareas se ajustan desde aquí. Puedes guardar y volver a editar para ver nuevas dependencias.</p>
                <div id="objectives-list">
                    <?php foreach ($objectives as $objective): ?>
                        <?php
                        $objId = (int)$objective['id'];
                        $taskOptions = [];
                        foreach ($objective['tasks'] as $task) {
                            $taskOptions[] = [
                                'id' => (int)$task['id'],
                                'name' => $task['name'],
                            ];
                        }
                        ?>
                        <div class="objective-card" data-oid="<?php echo $objId; ?>">
                            <div class="objective-header">
                                <h3>Objetivo #<?php echo $objId; ?></h3>
                                <button type="button" class="btn small danger js-remove-objective">Eliminar</button>
                            </div>
                            <label>Nombre
                                <input type="text" name="objectives[<?php echo $objId; ?>][name]" value="<?php echo htmlspecialchars($objective['name']); ?>" required>
                            </label>
                            <label>Fecha límite
                                <input type="date" name="objectives[<?php echo $objId; ?>][due_date]" value="<?php echo htmlspecialchars($objective['due_date']); ?>">
                            </label>
                            <label>Depende de
                                <select multiple name="objectives[<?php echo $objId; ?>][depends_on][]" class="multi">
                                    <?php foreach ($objectives as $candidate): ?>
                                        <?php if ((int)$candidate['id'] === $objId) { continue; } ?>
                                        <?php $selected = in_array((int)$candidate['id'], $objective['depends_on'], true); ?>
                                        <option value="<?php echo (int)$candidate['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($candidate['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="tasks-block">
                                <div class="tasks-header">
                                    <h4>Tareas</h4>
                                    <button type="button" class="btn small secondary js-add-task">Añadir tarea</button>
                                </div>
                            <?php $taskGroups = enlil_task_groups($objective['tasks']); ?>
                            <div class="tasks-list tasks-grid">
                                <?php foreach ($taskGroups['columns'] as $rootId => $tasks): ?>
                                    <div class="tasks-column">
                                        <div class="column-label">Cadena</div>
                                        <?php foreach ($tasks as $task): ?>
                                            <?php $taskId = (int)$task['id']; ?>
                                            <div class="task-card" data-tid="<?php echo $taskId; ?>">
                                            <div class="task-header">
                                                <strong>Tarea #<?php echo $taskId; ?></strong>
                                                <button type="button" class="btn small danger js-remove-task">Eliminar</button>
                                            </div>
                                            <label>Nombre
                                                <input type="text" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][name]" value="<?php echo htmlspecialchars($task['name']); ?>" required>
                                            </label>
                                            <label>Fecha límite
                                                <input type="date" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][due_date]" value="<?php echo htmlspecialchars($task['due_date']); ?>">
                                            </label>
                                            <label>Estado
                                                <select name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][status]">
                                                    <option value="pending" <?php echo $task['status'] !== 'done' ? 'selected' : ''; ?>>No realizada</option>
                                                    <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Realizada</option>
                                                </select>
                                            </label>
                                            <label>Depende de
                                                <select multiple name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][depends_on][]" class="multi">
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
                                                    <?php foreach ($peopleDisplayInProject as $person): ?>
                                                        <?php
                                                        $checked = in_array((int)$person['id'], $task['responsible_ids'], true);
                                                        $teamIdsStr = implode(',', $person['team_ids']);
                                                        $inProject = true;
                                                        ?>
                                                        <label class="checkbox checkbox-person" data-team-ids="<?php echo htmlspecialchars($teamIdsStr); ?>">
                                                            <span class="avatar-wrap small">
                                                                <span class="avatar small placeholder"><?php echo htmlspecialchars($person['initial']); ?></span>
                                                                <?php if ($person['avatar_url']): ?>
                                                                    <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($person['avatar_url']); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                                                <?php endif; ?>
                                                            </span>
                                                            <span><?php echo htmlspecialchars($person['name']); ?></span>
                                                            <input type="checkbox" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][responsible_ids][]" value="<?php echo (int)$person['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                                <div class="tasks-column independent">
                                    <div class="column-label">Independientes</div>
                                    <?php foreach ($taskGroups['independent'] as $task): ?>
                                        <?php $taskId = (int)$task['id']; ?>
                                        <div class="task-card" data-tid="<?php echo $taskId; ?>">
                                            <div class="task-header">
                                                <strong>Tarea #<?php echo $taskId; ?></strong>
                                                <button type="button" class="btn small danger js-remove-task">Eliminar</button>
                                            </div>
                                            <label>Nombre
                                                <input type="text" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][name]" value="<?php echo htmlspecialchars($task['name']); ?>" required>
                                            </label>
                                            <label>Fecha límite
                                                <input type="date" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][due_date]" value="<?php echo htmlspecialchars($task['due_date']); ?>">
                                            </label>
                                            <label>Estado
                                                <select name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][status]">
                                                    <option value="pending" <?php echo $task['status'] !== 'done' ? 'selected' : ''; ?>>No realizada</option>
                                                    <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Realizada</option>
                                                </select>
                                            </label>
                                            <label>Depende de
                                                <select multiple name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][depends_on][]" class="multi">
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
                                                    <?php foreach ($peopleDisplayInProject as $person): ?>
                                                        <?php
                                                        $checked = in_array((int)$person['id'], $task['responsible_ids'], true);
                                                        $teamIdsStr = implode(',', $person['team_ids']);
                                                        $inProject = true;
                                                        ?>
                                                        <label class="checkbox checkbox-person" data-team-ids="<?php echo htmlspecialchars($teamIdsStr); ?>">
                                                            <span class="avatar-wrap small">
                                                                <span class="avatar small placeholder"><?php echo htmlspecialchars($person['initial']); ?></span>
                                                                <?php if ($person['avatar_url']): ?>
                                                                    <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($person['avatar_url']); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                                                <?php endif; ?>
                                                            </span>
                                                            <span><?php echo htmlspecialchars($person['name']); ?></span>
                                                            <input type="checkbox" name="objectives[<?php echo $objId; ?>][tasks][<?php echo $taskId; ?>][responsible_ids][]" value="<?php echo (int)$person['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit">Guardar cambios</button>
        </form>
    </main>
<?php enlil_page_footer(); ?>
<script>
const objectivesList = document.getElementById('objectives-list');
const addObjectiveBtn = document.getElementById('add-objective');
let objectiveCounter = 0;
let taskCounter = 0;

function getObjectiveOptions(excludeId) {
    const options = [];
    objectivesList.querySelectorAll('.objective-card').forEach(card => {
        const oid = card.dataset.oid;
        const nameInput = card.querySelector('input[name^="objectives"][name$="[name]"]');
        const name = nameInput ? nameInput.value.trim() : '';
        if (!name || oid === excludeId) {
            return;
        }
        options.push({ id: oid, name });
    });
    return options;
}

function refreshObjectiveDepends() {
    objectivesList.querySelectorAll('.objective-card').forEach(card => {
        const oid = card.dataset.oid;
        const select = card.querySelector('select[name^="objectives"][name$="[depends_on][]"]');
        if (!select) return;
        const selected = Array.from(select.selectedOptions).map(opt => opt.value);
        select.innerHTML = '';
        getObjectiveOptions(oid).forEach(opt => {
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

function getTaskOptions(objectiveCard, excludeId) {
    const options = [];
    objectiveCard.querySelectorAll('.task-card').forEach(card => {
        const tid = card.dataset.tid;
        const nameInput = card.querySelector('input[name*="[tasks]"][name$="[name]"]');
        const name = nameInput ? nameInput.value.trim() : '';
        if (!name || tid === excludeId) {
            return;
        }
        options.push({ id: tid, name });
    });
    return options;
}

function refreshTaskDepends(objectiveCard) {
    objectiveCard.querySelectorAll('.task-card').forEach(card => {
        const tid = card.dataset.tid;
        const select = card.querySelector('select[name*="[tasks]"][name$="[depends_on][]"]');
        if (!select) return;
        const selected = Array.from(select.selectedOptions).map(opt => opt.value);
        select.innerHTML = '';
        getTaskOptions(objectiveCard, tid).forEach(opt => {
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

function createObjectiveCard() {
    objectiveCounter += 1;
    const oid = 'new_' + objectiveCounter;
    const wrapper = document.createElement('div');
    wrapper.className = 'objective-card';
    wrapper.dataset.oid = oid;
    wrapper.innerHTML = `
        <div class="objective-header">
            <h3>Objetivo nuevo</h3>
            <button type="button" class="btn small danger js-remove-objective">Eliminar</button>
        </div>
        <label>Nombre
            <input type="text" name="objectives[${oid}][name]" required>
        </label>
        <label>Fecha límite
            <input type="date" name="objectives[${oid}][due_date]">
        </label>
        <label>Depende de
            <select multiple name="objectives[${oid}][depends_on][]" class="multi"></select>
        </label>
        <div class="tasks-block">
            <div class="tasks-header">
                <h4>Tareas</h4>
                <button type="button" class="btn small secondary js-add-task">Añadir tarea</button>
            </div>
            <div class="tasks-list"></div>
        </div>
    `;
    return wrapper;
}

function createTaskCard(oid) {
    taskCounter += 1;
    const tid = 'new_' + taskCounter;
    const wrapper = document.createElement('div');
    wrapper.className = 'task-card';
    wrapper.dataset.tid = tid;
    wrapper.innerHTML = `
        <div class="task-header">
            <strong>Tarea nueva</strong>
            <button type="button" class="btn small danger js-remove-task">Eliminar</button>
        </div>
        <label>Nombre
            <input type="text" name="objectives[${oid}][tasks][${tid}][name]" required>
        </label>
        <label>Fecha límite
            <input type="date" name="objectives[${oid}][tasks][${tid}][due_date]">
        </label>
        <label>Estado
            <select name="objectives[${oid}][tasks][${tid}][status]">
                <option value="pending">No realizada</option>
                <option value="done">Realizada</option>
            </select>
        </label>
        <label>Depende de
            <select multiple name="objectives[${oid}][tasks][${tid}][depends_on][]" class="multi"></select>
        </label>
        <div class="task-people">
            <span class="label">Responsables</span>
            <div class="checkbox-grid">
                <?php foreach ($peopleDisplayInProject as $person): ?>
                    <?php
                    $teamIdsStr = implode(',', $person['team_ids']);
                    $inProject = true;
                    ?>
                    <label class="checkbox checkbox-person" data-team-ids="<?php echo htmlspecialchars($teamIdsStr); ?>">
                        <span class="avatar-wrap small">
                            <span class="avatar small placeholder"><?php echo htmlspecialchars($person['initial']); ?></span>
                            <?php if ($person['avatar_url']): ?>
                                <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($person['avatar_url']); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                            <?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars($person['name']); ?></span>
                        <input type="checkbox" name="objectives[${oid}][tasks][${tid}][responsible_ids][]" value="<?php echo (int)$person['id']; ?>">
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    `;
    return wrapper;
}

addObjectiveBtn.addEventListener('click', () => {
    const card = createObjectiveCard();
    objectivesList.appendChild(card);
    refreshObjectiveDepends();
});

objectivesList.addEventListener('click', (e) => {
    if (e.target.classList.contains('js-remove-objective')) {
        const card = e.target.closest('.objective-card');
        if (card) {
            card.remove();
            refreshObjectiveDepends();
        }
    }
    if (e.target.classList.contains('js-add-task')) {
        const objectiveCard = e.target.closest('.objective-card');
        const tasksList = objectiveCard.querySelector('.tasks-list');
        const oid = objectiveCard.dataset.oid;
        const independentColumn = tasksList.querySelector('.tasks-column.independent');
        if (independentColumn) {
            independentColumn.appendChild(createTaskCard(oid));
        } else {
            tasksList.appendChild(createTaskCard(oid));
        }
        refreshTaskDepends(objectiveCard);
    }
    if (e.target.classList.contains('js-remove-task')) {
        const taskCard = e.target.closest('.task-card');
        if (taskCard) {
            const objectiveCard = taskCard.closest('.objective-card');
            taskCard.remove();
            if (objectiveCard) {
                refreshTaskDepends(objectiveCard);
            }
        }
    }
});

objectivesList.addEventListener('input', (e) => {
    if (e.target && e.target.name && e.target.name.endsWith('[name]')) {
        if (e.target.name.includes('[tasks]')) {
            const objectiveCard = e.target.closest('.objective-card');
            if (objectiveCard) {
                refreshTaskDepends(objectiveCard);
            }
        } else {
            refreshObjectiveDepends();
        }
    }
});

// initial refresh to ensure selects are populated if empty
refreshObjectiveDepends();
objectivesList.querySelectorAll('.objective-card').forEach(card => refreshTaskDepends(card));
</script>
