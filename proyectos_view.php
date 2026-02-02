<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = enlil_projects_get($projectId);
if (!$project) {
    header('Location: /proyectos_list.php');
    exit;
}

$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}

$people = enlil_people_all();
$peopleById = [];
foreach ($people as $person) {
    $peopleById[(int)$person['id']] = $person;
}

function enlil_person_avatar_url(array $person): string {
    $username = ltrim((string)($person['telegram_user'] ?? ''), '@');
    if (!empty($person['telegram_user_id'])) {
        $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
        if (file_exists($path)) {
            return enlil_avatar_url($person['telegram_user_id']);
        }
    }
    if ($username !== '') {
        return 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg';
    }
    return '';
}

$objectives = $project['objectives'];
$objectiveById = [];
$depsMap = [];
foreach ($objectives as $obj) {
    $objectiveById[(int)$obj['id']] = $obj;
    $depsMap[(int)$obj['id']] = $obj['depends_on'];
}

$taskGroupCache = [];
function enlil_task_groups_view(array $tasks): array {
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

function enlil_format_date_es_view(string $date): string {
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];
    $day = (int)date('j', $ts);
    $month = (int)date('n', $ts);
    $year = date('Y', $ts);
    $monthName = $months[$month] ?? '';
    if ($monthName === '') {
        return $date;
    }
    return $day . ' de ' . $monthName . ' de ' . $year;
}

function enlil_format_day_month_es(string $date): string {
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];
    $day = (int)date('j', $ts);
    $month = (int)date('n', $ts);
    $monthName = $months[$month] ?? '';
    if ($monthName === '') {
        return $date;
    }
    return $day . ' ' . $monthName;
}

function enlil_objective_completion_date(array $tasks): string {
    $latest = '';
    foreach ($tasks as $task) {
        $status = $task['status'] ?? '';
        if ($status !== 'done') {
            return '';
        }
        $completed = $task['completed_at'] ?? '';
        if ($completed === '') {
            continue;
        }
        if ($latest === '' || $completed > $latest) {
            $latest = $completed;
        }
    }
    return $latest;
}

function enlil_pending_depends(array $task, array $tasksById, array $pendingIds, array &$memo = []): array {
    $id = (int)($task['id'] ?? 0);
    if ($id && isset($memo[$id])) {
        return $memo[$id];
    }
    $deps = $task['depends_on'] ?? [];
    $resolved = [];
    foreach ($deps as $depId) {
        $depId = (int)$depId;
        if ($depId === 0) {
            continue;
        }
        if (isset($pendingIds[$depId])) {
            $resolved[] = $depId;
        }
    }
    $resolved = array_values(array_unique($resolved));
    if ($id) {
        $memo[$id] = $resolved;
    }
    return $resolved;
}

$taskLevelMemo = [];
$taskLevelVisiting = [];
function enlil_task_levels(array $tasks): array {
    $byId = [];
    $deps = [];
    foreach ($tasks as $task) {
        $id = (int)($task['id'] ?? 0);
        if (!$id) {
            continue;
        }
        $byId[$id] = $task;
        $deps[$id] = array_values(array_filter(array_map('intval', $task['depends_on'] ?? [])));
    }
    $memo = [];
    $visiting = [];
    $levelOf = function (int $id) use (&$levelOf, &$deps, &$memo, &$visiting): int {
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        if (isset($visiting[$id])) {
            return 0;
        }
        $visiting[$id] = true;
        $level = 0;
        foreach ($deps[$id] ?? [] as $depId) {
            $level = max($level, $levelOf((int)$depId) + 1);
        }
        unset($visiting[$id]);
        $memo[$id] = $level;
        return $level;
    };

    $levels = [];
    $levelById = [];
    foreach ($byId as $id => $task) {
        $level = $levelOf($id);
        $levels[$level][] = $task;
        $levelById[$id] = $level;
    }
    ksort($levels);
    foreach ($levels as &$list) {
        usort($list, function ($a, $b) {
            $da = $a['due_date'] ?? '';
            $db = $b['due_date'] ?? '';
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
    }
    unset($list);
    return ['levels' => $levels, 'level_by_id' => $levelById, 'deps' => $deps];
}

$levelMemo = [];
$visiting = [];
function enlil_objective_level(int $id, array $depsMap, array &$memo, array &$visiting): int {
    if (isset($memo[$id])) {
        return $memo[$id];
    }
    if (isset($visiting[$id])) {
        return 0;
    }
    $visiting[$id] = true;
    $level = 0;
    foreach ($depsMap[$id] ?? [] as $depId) {
        $depId = (int)$depId;
        if ($depId === $id) {
            continue;
        }
        $level = max($level, enlil_objective_level($depId, $depsMap, $memo, $visiting) + 1);
    }
    unset($visiting[$id]);
    $memo[$id] = $level;
    return $level;
}

$objectiveChildren = [];
foreach ($depsMap as $oid => $depsList) {
    foreach ($depsList as $depId) {
        $objectiveChildren[(int)$depId][] = (int)$oid;
    }
}
$objectiveRoots = [];
foreach (array_keys($objectiveById) as $oid) {
    if (empty($depsMap[$oid])) {
        $objectiveRoots[] = (int)$oid;
    }
}
if (empty($objectiveRoots)) {
    $objectiveRoots = array_keys($objectiveById);
}
$objectivePaths = [];
$objectiveVisiting = [];
$walkObjectives = function (int $id, array $path) use (&$walkObjectives, &$objectiveChildren, &$objectivePaths, &$objectiveVisiting) {
    if (isset($objectiveVisiting[$id])) {
        return;
    }
    $objectiveVisiting[$id] = true;
    $path[] = $id;
    $next = $objectiveChildren[$id] ?? [];
    if (empty($next)) {
        $objectivePaths[] = $path;
    } else {
        foreach ($next as $childId) {
            $walkObjectives((int)$childId, $path);
        }
    }
    unset($objectiveVisiting[$id]);
};
foreach ($objectiveRoots as $rootId) {
    $walkObjectives((int)$rootId, []);
}
if (empty($objectivePaths)) {
    $objectivePaths[] = array_keys($objectiveById);
}
$objectiveMaxDepth = 0;
foreach ($objectivePaths as $path) {
    $objectiveMaxDepth = max($objectiveMaxDepth, count($path) - 1);
}
$objectiveColCount = max(1, count($objectivePaths));
$objectiveSlots = [];
foreach ($objectivePaths as $col => $path) {
    $depth = count($path) - 1;
    $offset = max(0, $objectiveMaxDepth - $depth);
    foreach ($path as $idx => $objId) {
        $row = $offset + $idx;
        $objectiveSlots[$row][$col] = $objId;
    }
}

enlil_page_header('Proyecto');
?>
    <main class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($project['name']); ?></h1>
                <p class="muted"><?php echo htmlspecialchars($project['description']); ?></p>
            </div>
            <div class="actions">
                <a class="btn secondary" href="/proyectos_list.php">Volver</a>
                <a class="btn" href="/proyectos_objective_edit.php?project_id=<?php echo (int)$project['id']; ?>">Añadir objetivo</a>
            </div>
        </div>

        <div class="section-card">
            <h2>Objetivos</h2>
            <?php if (!$objectives): ?>
                <p class="empty">Aún no hay objetivos en este proyecto.</p>
            <?php else: ?>
                <div class="objective-matrix" style="--obj-cols: <?php echo (int)$objectiveColCount; ?>;">
                    <?php for ($row = 0; $row <= $objectiveMaxDepth; $row++): ?>
                        <div class="objective-row">
                            <?php for ($col = 0; $col < $objectiveColCount; $col++): ?>
                                <?php if (isset($objectiveSlots[$row][$col]) && isset($objectiveById[$objectiveSlots[$row][$col]])): ?>
                                    <?php $obj = $objectiveById[$objectiveSlots[$row][$col]]; ?>
                                    <div class="objective-cell">
                                        <div class="graph-node">
                                            <div class="graph-node-title">
                                                <a class="link" href="/proyectos_objective_edit.php?project_id=<?php echo (int)$project['id']; ?>&objective_id=<?php echo (int)$obj['id']; ?>">
                                                    <?php echo htmlspecialchars($obj['name']); ?>
                                                </a>
                                            </div>
                                            <div class="graph-node-meta">
                                                <?php echo $obj['due_date'] !== '' ? htmlspecialchars(enlil_format_date_es_view($obj['due_date'])) : 'Sin fecha límite'; ?>
                                            </div>
                                            <div class="graph-node-tasks">
                                                <?php
                                                $allTasks = $obj['tasks'];
                                                $displayTasks = $allTasks;
                                                ?>
                                                <?php if (empty($displayTasks)): ?>
                                                    <span class="muted">Sin tareas</span>
                                                <?php else: ?>
                                                    <?php
                                                    $completedAt = enlil_objective_completion_date($displayTasks);
                                                    $completedText = $completedAt !== '' ? enlil_format_date_es_view(substr($completedAt, 0, 10)) : '';
                                                    ?>
                                                    <?php if ($completedAt !== ''): ?>
                                                        <span class="muted">Objetivo alcanzado<?php echo $completedText !== '' ? ' el ' . htmlspecialchars($completedText) : ''; ?></span>
                                                    <?php endif; ?>
                                                    <?php
                                                    $taskLevelsData = enlil_task_levels($displayTasks);
                                                    $taskLevels = $taskLevelsData['levels'];
                                                    $taskDeps = $taskLevelsData['deps'];

                                                    $taskById = [];
                                                    foreach ($displayTasks as $t) {
                                                        $taskById[(int)$t['id']] = $t;
                                                    }

                                                    $children = [];
                                                    foreach ($taskDeps as $id => $depsList) {
                                                        foreach ($depsList as $depId) {
                                                            $children[$depId][] = $id;
                                                        }
                                                    }

                                                    $roots = [];
                                                    foreach ($taskById as $id => $task) {
                                                        if (empty($taskDeps[$id])) {
                                                            $roots[] = $id;
                                                        }
                                                    }
                                                    if (empty($roots)) {
                                                        $roots = array_keys($taskById);
                                                    }

                                                    $paths = [];
                                                    $pathVisiting = [];
                                                    $walk = function (int $id, array $path) use (&$walk, &$children, &$paths, &$pathVisiting) {
                                                        if (isset($pathVisiting[$id])) {
                                                            return;
                                                        }
                                                        $pathVisiting[$id] = true;
                                                        $path[] = $id;
                                                        $next = $children[$id] ?? [];
                                                        if (empty($next)) {
                                                            $paths[] = $path;
                                                        } else {
                                                            foreach ($next as $childId) {
                                                                $walk((int)$childId, $path);
                                                            }
                                                        }
                                                        unset($pathVisiting[$id]);
                                                    };
                                                    foreach ($roots as $rootId) {
                                                        $walk((int)$rootId, []);
                                                    }
                                                    if (empty($paths)) {
                                                        $paths[] = array_keys($taskById);
                                                    }

                                                    $maxDepth = 0;
                                                    foreach ($paths as $path) {
                                                        $maxDepth = max($maxDepth, count($path) - 1);
                                                    }
                                                    $taskColCount = max(1, min(12, $maxDepth + 1));

                                                    usort($paths, function ($a, $b) use ($taskById) {
                                                        $lenA = count($a);
                                                        $lenB = count($b);
                                                        if ($lenA !== $lenB) {
                                                            return $lenB <=> $lenA;
                                                        }
                                                        $dueA = $taskById[$a[0]]['due_date'] ?? '';
                                                        $dueB = $taskById[$b[0]]['due_date'] ?? '';
                                                        if ($dueA !== $dueB) {
                                                            if ($dueA === '') {
                                                                return 1;
                                                            }
                                                            if ($dueB === '') {
                                                                return -1;
                                                            }
                                                            return strcmp($dueB, $dueA);
                                                        }
                                                        return $a[0] <=> $b[0];
                                                    });
                                                    ?>
                                                    <div class="task-matrix" style="--task-cols: <?php echo (int)$taskColCount; ?>;">
                                                        <?php foreach ($paths as $path): ?>
                                                            <?php
                                                            $depth = count($path) - 1;
                                                            $offset = max(0, $maxDepth - $depth);
                                                            $slot = [];
                                                            foreach ($path as $idx => $taskId) {
                                                                $slot[$offset + $idx] = $taskId;
                                                            }
                                                            ?>
                                                            <div class="task-row-grid">
                                                                <?php for ($lvl = 0; $lvl < $taskColCount; $lvl++): ?>
                                                                    <?php if (isset($slot[$lvl]) && isset($taskById[$slot[$lvl]])): ?>
                                                                        <?php $task = $taskById[$slot[$lvl]]; ?>
                                                                        <div class="task-cell">
                                                                            <div class="task-pill <?php echo $task['status'] === 'done' ? 'done' : ''; ?>">
                                                                                <?php echo htmlspecialchars($task['name']); ?>
                                                                                <?php if (($task['due_date'] ?? '') !== ''): ?>
                                                                                    <span class="task-date"><?php echo htmlspecialchars(enlil_format_day_month_es($task['due_date'])); ?></span>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($task['responsible_ids'])): ?>
                                                                                    <span class="task-avatars">
                                                                                        <?php foreach ($task['responsible_ids'] as $rid): ?>
                                                                                            <?php
                                                                                            $rid = (int)$rid;
                                                                                            if (!isset($peopleById[$rid])) {
                                                                                                continue;
                                                                                            }
                                                                                            $person = $peopleById[$rid];
                                                                                            $avatarUrl = enlil_person_avatar_url($person);
                                                                                            $title = (string)($person['name'] ?? '');
                                                                                            ?>
                                                                                            <?php if ($avatarUrl !== ''): ?>
                                                                                                <img class="task-avatar" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" title="<?php echo htmlspecialchars($title); ?>">
                                                                                            <?php else: ?>
                                                                                                <span class="task-avatar placeholder" title="<?php echo htmlspecialchars($title); ?>">
                                                                                                    <?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($title, 0, 1) : substr($title, 0, 1)); ?>
                                                                                                </span>
                                                                                            <?php endif; ?>
                                                                                        <?php endforeach; ?>
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div class="task-cell empty"></div>
                                                                    <?php endif; ?>
                                                                <?php endfor; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="objective-cell empty"></div>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
<?php enlil_page_footer(); ?>
<script>
window.addEventListener('load', () => setTimeout(() => {
}, 150));
window.addEventListener('resize', () => {
    window.requestAnimationFrame(() => {
    });
});
</script>
