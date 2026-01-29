<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();

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

$levels = [];
foreach (array_keys($objectiveById) as $oid) {
    $level = enlil_objective_level($oid, $depsMap, $levelMemo, $visiting);
    if (!isset($levels[$level])) {
        $levels[$level] = [];
    }
    $levels[$level][] = $objectiveById[$oid];
}
ksort($levels);

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
                <div class="graph" id="objective-graph">
                    <svg class="graph-lines"></svg>
                    <div class="graph-columns">
                        <?php foreach ($levels as $level => $items): ?>
                            <div class="graph-column">
                                <?php foreach ($items as $obj): ?>
                                    <div class="graph-node" data-oid="<?php echo (int)$obj['id']; ?>" data-depends="<?php echo htmlspecialchars(implode(',', $obj['depends_on'])); ?>">
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
                                            $pendingTasks = array_values(array_filter($obj['tasks'], function ($task) {
                                                return ($task['status'] ?? '') !== 'done';
                                            }));
                                            ?>
                                            <?php if (empty($pendingTasks)): ?>
                                                <?php if (!empty($obj['tasks'])): ?>
                                                    <?php
                                                    $completedAt = enlil_objective_completion_date($obj['tasks']);
                                                    $completedText = $completedAt !== '' ? enlil_format_date_es_view(substr($completedAt, 0, 10)) : '';
                                                    ?>
                                                    <span class="muted">Objetivo alcanzado<?php echo $completedText !== '' ? ' el ' . htmlspecialchars($completedText) : ''; ?></span>
                                                <?php else: ?>
                                                    <span class="muted">Sin tareas</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php $taskGroups = enlil_task_groups_view($pendingTasks); ?>
                                                <div class="tasks-list tasks-grid">
                                                    <?php foreach ($taskGroups['columns'] as $tasks): ?>
                                                        <div class="tasks-column">
                                                            <?php foreach ($tasks as $task): ?>
                                                                <div class="task-pill <?php echo $task['status'] === 'done' ? 'done' : ''; ?>">
                                                                    <?php echo htmlspecialchars($task['name']); ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <div class="tasks-column independent">
                                                        <?php foreach ($taskGroups['independent'] as $task): ?>
                                                            <div class="task-pill <?php echo $task['status'] === 'done' ? 'done' : ''; ?>">
                                                                <?php echo htmlspecialchars($task['name']); ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
<?php enlil_page_footer(); ?>
<script>
function drawObjectiveLines() {
    const graph = document.getElementById('objective-graph');
    if (!graph) return;
    const svg = graph.querySelector('.graph-lines');
    const columns = graph.querySelector('.graph-columns');
    const nodes = Array.from(graph.querySelectorAll('.graph-node'));
    const nodeById = {};
    nodes.forEach(node => {
        nodeById[node.dataset.oid] = node;
    });
    while (svg.firstChild) {
        svg.removeChild(svg.firstChild);
    }
    const rect = graph.getBoundingClientRect();
    const colRect = columns ? columns.getBoundingClientRect() : rect;
    const width = colRect.width;
    const height = colRect.height;
    svg.setAttribute('width', width);
    svg.setAttribute('height', height);
    nodes.forEach(node => {
        const deps = (node.dataset.depends || '').split(',').filter(Boolean);
        deps.forEach(depId => {
            const depNode = nodeById[depId];
            if (!depNode) return;
            const from = depNode.getBoundingClientRect();
            const to = node.getBoundingClientRect();
            const x1 = from.left + from.width / 2 - rect.left;
            const y1 = from.bottom - rect.top;
            const x2 = to.left + to.width / 2 - rect.left;
            const y2 = to.top - rect.top;
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const midY = y1 + (y2 - y1) / 2;
            const d = `M ${x1} ${y1} C ${x1} ${midY}, ${x2} ${midY}, ${x2} ${y2}`;
            line.setAttribute('d', d);
            line.setAttribute('fill', 'none');
            line.setAttribute('stroke', '#1b8eed');
            line.setAttribute('stroke-width', '2');
            svg.appendChild(line);
        });
    });
}

window.addEventListener('load', drawObjectiveLines);
window.addEventListener('resize', () => {
    window.requestAnimationFrame(drawObjectiveLines);
});
</script>
