<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/people.php';

enlil_require_login();

$query = trim((string)($_GET['q'] ?? ''));

$peopleById = [];
foreach (enlil_people_all() as $person) {
    $peopleById[(int)$person['id']] = (string)($person['name'] ?? '');
}

function enlil_format_date_es_short(string $date): string {
    if ($date === '') {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
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
    $month = $months[(int)date('n', $ts)] ?? date('m', $ts);
    return $day . ' de ' . $month;
}

function enlil_task_title_matches(string $title, string $query): bool {
    if ($query === '') {
        return false;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($title, $query) !== false;
    }
    return stripos($title, $query) !== false;
}

$results = [];
if ($query !== '') {
    $projects = enlil_projects_all();
    foreach ($projects as $projectRow) {
        $project = enlil_projects_get((int)$projectRow['id']);
        if (!$project) {
            continue;
        }
        foreach ($project['objectives'] as $objective) {
            foreach ($objective['tasks'] as $task) {
                $taskName = (string)($task['name'] ?? '');
                if (!enlil_task_title_matches($taskName, $query)) {
                    continue;
                }
                $responsibles = [];
                foreach (($task['responsible_ids'] ?? []) as $personId) {
                    $personId = (int)$personId;
                    if ($personId > 0 && isset($peopleById[$personId]) && $peopleById[$personId] !== '') {
                        $responsibles[] = $peopleById[$personId];
                    }
                }
                $results[] = [
                    'task' => $taskName,
                    'due_date' => (string)($task['due_date'] ?? ''),
                    'responsibles' => $responsibles ? implode(', ', $responsibles) : 'Sin responsables',
                    'objective' => (string)($objective['name'] ?? ''),
                    'project' => (string)($project['name'] ?? ''),
                    'status' => ((string)($task['status'] ?? '') === 'done') ? 'done' : 'pending',
                ];
            }
        }
    }
}

enlil_page_header('Buscador de tareas');
?>
<main class="container">
    <div class="page-header">
        <h1>Buscador de tareas</h1>
        <a class="btn secondary" href="/dashboard.php">Volver</a>
    </div>

    <form method="get" class="form-card" autocomplete="off">
        <label>Título de tarea
            <input type="text" name="q" value="<?php echo htmlspecialchars($query); ?>" required>
        </label>
        <button type="submit">Buscar</button>
    </form>

    <?php if ($query === ''): ?>
        <p class="muted">Introduce un texto para buscar tareas.</p>
    <?php elseif (!$results): ?>
        <p class="muted">No hay coincidencias para “<?php echo htmlspecialchars($query); ?>”.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="fixed-table cols-5">
                <thead>
                    <tr>
                        <th>Tarea</th>
                        <th>Fecha</th>
                        <th>Encargados</th>
                        <th>Objetivo</th>
                        <th>Proyecto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr class="<?php echo $row['status'] === 'done' ? 'task-row-done' : 'task-row-pending'; ?>">
                            <td><?php echo htmlspecialchars($row['task']); ?></td>
                            <td><?php echo htmlspecialchars(enlil_format_date_es_short((string)$row['due_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['responsibles']); ?></td>
                            <td><?php echo htmlspecialchars($row['objective']); ?></td>
                            <td><?php echo htmlspecialchars($row['project']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php enlil_page_footer(); ?>
