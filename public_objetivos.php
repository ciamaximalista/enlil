<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/tokens.php';

$token = trim($_GET['token'] ?? '');
$entry = $token !== '' ? enlil_token_get($token) : null;
if (!$entry || $entry['type'] !== 'objetivos') {
enlil_page_header('Enlace caducado', false);
    ?>
    <main class="container">
        <div class="section-card">
            <h1>Enlace caducado</h1>
            <p>Este enlace ya no es válido. Pide al bot que te lo envíe de nuevo.</p>
        </div>
    </main>
    <?php
    enlil_page_footer();
    exit;
}

$people = enlil_people_all();
$person = null;
foreach ($people as $p) {
    if ((int)$p['id'] === (int)$entry['person_id']) {
        $person = $p;
        break;
    }
}
if (!$person) {
    enlil_page_header('No encontrado', false);
    ?>
    <main class="container">
        <div class="section-card">
            <h1>Persona no encontrada</h1>
        </div>
    </main>
    <?php
    enlil_page_footer();
    exit;
}

function enlil_format_date_es_view_public(string $date): string {
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

function enlil_objective_completion_date_public(array $tasks): string {
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

function enlil_pending_depends_public(array $task, array $tasksById, array $pendingIds, array &$memo = []): array {
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

function enlil_task_levels_public(array $tasks): array {
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
    foreach ($byId as $id => $task) {
        $level = $levelOf($id);
        $levels[$level][] = $task;
    }
    ksort($levels);
    return ['levels' => $levels, 'deps' => $deps];
}

$projects = enlil_projects_all();
$projectsFiltered = [];
$personTeams = $person['team_ids'] ?? [];
foreach ($projects as $proj) {
    $full = enlil_projects_get((int)$proj['id']);
    if (!$full) {
        continue;
    }
    if (!array_intersect($full['team_ids'], $personTeams)) {
        continue;
    }
    $projectsFiltered[] = $full;
}

enlil_page_header('Objetivos', false);
?>
    <main class="container">
        <div class="page-header">
            <div>
                <h1>Objetivos</h1>
                <p class="muted"><?php echo htmlspecialchars($person['name']); ?></p>
            </div>
        </div>

        <?php if (!$projectsFiltered): ?>
            <p class="empty">No hay proyectos vinculados a tus equipos.</p>
        <?php endif; ?>

        <?php foreach ($projectsFiltered as $project): ?>
            <?php
            $objectives = $project['objectives'] ?? [];
            $objectiveById = [];
            $depsMap = [];
            foreach ($objectives as $obj) {
                $objectiveById[(int)$obj['id']] = $obj;
                $depsMap[(int)$obj['id']] = $obj['depends_on'] ?? [];
            }
            $children = [];
            foreach ($depsMap as $oid => $depsList) {
                foreach ($depsList as $depId) {
                    $children[(int)$depId][] = (int)$oid;
                }
            }
            $roots = [];
            foreach (array_keys($objectiveById) as $oid) {
                if (empty($depsMap[$oid])) {
                    $roots[] = (int)$oid;
                }
            }
            if (!$roots) {
                $roots = array_keys($objectiveById);
            }
            $paths = [];
            $visiting = [];
            $walk = function (int $id, array $path) use (&$walk, &$children, &$paths, &$visiting) {
                if (isset($visiting[$id])) {
                    return;
                }
                $visiting[$id] = true;
                $path[] = $id;
                $next = $children[$id] ?? [];
                if (!$next) {
                    $paths[] = $path;
                } else {
                    foreach ($next as $childId) {
                        $walk((int)$childId, $path);
                    }
                }
                unset($visiting[$id]);
            };
            foreach ($roots as $rootId) {
                $walk((int)$rootId, []);
            }
            if (!$paths) {
                $paths[] = array_keys($objectiveById);
            }
            $maxDepth = 0;
            foreach ($paths as $path) {
                $maxDepth = max($maxDepth, count($path) - 1);
            }
            $colCount = max(1, count($paths));
            $slots = [];
            foreach ($paths as $col => $path) {
                $depth = count($path) - 1;
                $offset = max(0, $maxDepth - $depth);
                foreach ($path as $idx => $oid) {
                    $row = $offset + $idx;
                    $slots[$row][$col] = $oid;
                }
            }
            ?>
            <div class="section-card">
                <div class="page-header">
                    <h2><?php echo htmlspecialchars($project['name']); ?></h2>
                </div>
                <?php if (!$objectives): ?>
                    <p class="empty">No hay objetivos.</p>
                <?php else: ?>
                    <div class="objective-matrix" style="--obj-cols: <?php echo (int)$colCount; ?>;">
                        <?php for ($row = 0; $row <= $maxDepth; $row++): ?>
                            <div class="objective-row">
                                <?php for ($col = 0; $col < $colCount; $col++): ?>
                                    <?php if (isset($slots[$row][$col]) && isset($objectiveById[$slots[$row][$col]])): ?>
                                        <?php $obj = $objectiveById[$slots[$row][$col]]; ?>
                                        <div class="objective-cell">
                                            <div class="graph-node">
                                                <div class="graph-node-title">
                                                    <?php echo htmlspecialchars($obj['name']); ?>
                                                </div>
                                                <div class="graph-node-meta">
                                                    <?php echo $obj['due_date'] !== '' ? htmlspecialchars(enlil_format_date_es_view_public($obj['due_date'])) : 'Sin fecha límite'; ?>
                                                </div>
                                                <div class="graph-node-tasks">
                                                    <?php
                                                    $allTasks = $obj['tasks'] ?? [];
                                                    $tasksById = [];
                                                    foreach ($allTasks as $t) {
                                                        $tasksById[(int)$t['id']] = $t;
                                                    }
                                                    $pendingTasks = array_values(array_filter($allTasks, function ($task) {
                                                        return ($task['status'] ?? '') !== 'done';
                                                    }));
                                                    $pendingIds = [];
                                                    foreach ($pendingTasks as $t) {
                                                        $pendingIds[(int)$t['id']] = true;
                                                    }
                                                    $memo = [];
                                                    foreach ($pendingTasks as &$pt) {
                                                        $pt['depends_on'] = enlil_pending_depends_public($pt, $tasksById, $pendingIds, $memo);
                                                    }
                                                    unset($pt);
                                                    ?>
                                                    <?php if (empty($pendingTasks)): ?>
                                                        <?php if (!empty($obj['tasks'])): ?>
                                                            <?php
                                                            $completedAt = enlil_objective_completion_date_public($obj['tasks']);
                                                            $completedText = $completedAt !== '' ? enlil_format_date_es_view_public(substr($completedAt, 0, 10)) : '';
                                                            ?>
                                                            <span class="muted">Objetivo alcanzado<?php echo $completedText !== '' ? ' el ' . htmlspecialchars($completedText) : ''; ?></span>
                                                        <?php else: ?>
                                                            <span class="muted">Sin tareas</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php
                                                        $pendingDependsById = [];
                                                        $memo = [];
                                                        foreach ($pendingTasks as $pt) {
                                                            $pendingDependsById[(int)$pt['id']] = enlil_pending_depends_public($pt, $tasksById, $pendingIds, $memo);
                                                        }
                                                        $pendingTasksRemap = [];
                                                        foreach ($pendingTasks as $pt) {
                                                            $pt['depends_on'] = $pendingDependsById[(int)$pt['id']] ?? $pt['depends_on'];
                                                            $pendingTasksRemap[] = $pt;
                                                        }
                                                        $taskLevelsData = enlil_task_levels_public($pendingTasksRemap);
                                                        $taskDeps = $taskLevelsData['deps'];
                                                        $taskById = [];
                                                        foreach ($pendingTasksRemap as $t) {
                                                            $taskById[(int)$t['id']] = $t;
                                                        }
                                                        $childrenTasks = [];
                                                        foreach ($taskDeps as $id => $depsList) {
                                                            foreach ($depsList as $depId) {
                                                                $childrenTasks[$depId][] = $id;
                                                            }
                                                        }
                                                        $rootsTasks = [];
                                                        foreach ($taskById as $id => $task) {
                                                            if (empty($taskDeps[$id])) {
                                                                $rootsTasks[] = $id;
                                                            }
                                                        }
                                                        if (!$rootsTasks) {
                                                            $rootsTasks = array_keys($taskById);
                                                        }
                                                        $pathsTasks = [];
                                                        $visitingTasks = [];
                                                        $walkTask = function (int $id, array $path) use (&$walkTask, &$childrenTasks, &$pathsTasks, &$visitingTasks) {
                                                            if (isset($visitingTasks[$id])) {
                                                                return;
                                                            }
                                                            $visitingTasks[$id] = true;
                                                            $path[] = $id;
                                                            $next = $childrenTasks[$id] ?? [];
                                                            if (!$next) {
                                                                $pathsTasks[] = $path;
                                                            } else {
                                                                foreach ($next as $childId) {
                                                                    $walkTask((int)$childId, $path);
                                                                }
                                                            }
                                                            unset($visitingTasks[$id]);
                                                        };
                                                        foreach ($rootsTasks as $rootId) {
                                                            $walkTask((int)$rootId, []);
                                                        }
                                                        if (!$pathsTasks) {
                                                            $pathsTasks[] = array_keys($taskById);
                                                        }
                                                        $maxDepthTask = 0;
                                                        foreach ($pathsTasks as $path) {
                                                            $maxDepthTask = max($maxDepthTask, count($path) - 1);
                                                        }
                                                        $taskColCount = max(1, min(12, $maxDepthTask + 1));
                                                        ?>
                                                        <div class="task-matrix" style="--task-cols: <?php echo (int)$taskColCount; ?>;">
                                                            <?php foreach ($pathsTasks as $path): ?>
                                                                <?php
                                                                $depth = count($path) - 1;
                                                                $offset = max(0, $maxDepthTask - $depth);
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
                                                                                <div class="task-pill">
                                                                                    <?php echo htmlspecialchars($task['name']); ?>
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
        <?php endforeach; ?>
    </main>
<?php enlil_page_footer(); ?>
