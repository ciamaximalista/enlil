<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/tokens.php';

$token = trim($_GET['token'] ?? '');
$entry = $token !== '' ? enlil_token_get($token) : null;
if (!$entry || $entry['type'] !== 'calendario_proyectos') {
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

$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['month', 'week'], true)) {
    $view = 'month';
}
$dateStr = $_GET['date'] ?? date('Y-m-d');
$baseDate = strtotime($dateStr);
if ($baseDate === false) {
    $baseDate = time();
}

$projects = enlil_projects_all();
$tasks = [];
$personTeams = $person['team_ids'] ?? [];
foreach ($projects as $proj) {
    $project = enlil_projects_get((int)$proj['id']);
    if (!$project) {
        continue;
    }
    if (!array_intersect($project['team_ids'], $personTeams)) {
        continue;
    }
    foreach ($project['objectives'] as $objective) {
        foreach ($objective['tasks'] as $task) {
            if (($task['status'] ?? '') === 'done') {
                continue;
            }
            if (empty($task['due_date'])) {
                continue;
            }
            $tasks[] = [
                'date' => $task['due_date'],
                'name' => $task['name'],
                'project' => $project['name'],
                'objective' => $objective['name'],
            ];
        }
    }
}

$tasksByDate = [];
foreach ($tasks as $task) {
    $tasksByDate[$task['date']][] = $task;
}

$minDate = null;
$maxDate = null;
foreach ($tasks as $task) {
    $ts = strtotime($task['date']);
    if ($ts === false) {
        continue;
    }
    if ($minDate === null || $ts < $minDate) {
        $minDate = $ts;
    }
    if ($maxDate === null || $ts > $maxDate) {
        $maxDate = $ts;
    }
}

function enlil_month_matrix_public_proj(int $baseDate): array {
    $firstDay = strtotime(date('Y-m-01', $baseDate));
    $startDow = (int)date('N', $firstDay);
    $start = strtotime('-' . ($startDow - 1) . ' days', $firstDay);
    $matrix = [];
    for ($week = 0; $week < 6; $week++) {
        $row = [];
        for ($day = 0; $day < 7; $day++) {
            $row[] = strtotime('+' . ($week * 7 + $day) . ' days', $start);
        }
        $matrix[] = $row;
    }
    return $matrix;
}

function enlil_week_days_public_proj(int $baseDate): array {
    $dow = (int)date('N', $baseDate);
    $start = strtotime('-' . ($dow - 1) . ' days', $baseDate);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = strtotime('+' . $i . ' days', $start);
    }
    return $days;
}

function enlil_format_month_es_public_proj(int $ts): string {
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    $month = (int)date('n', $ts);
    $year = date('Y', $ts);
    return ($months[$month] ?? date('F', $ts)) . ' ' . $year;
}

$currentMonthStart = strtotime(date('Y-m-01', $baseDate));
$minMonthStart = $minDate !== null ? strtotime(date('Y-m-01', $minDate)) : null;
$maxMonthStart = $maxDate !== null ? strtotime(date('Y-m-01', $maxDate)) : null;
$canPrevMonth = $minMonthStart !== null && $currentMonthStart > $minMonthStart;
$canNextMonth = $maxMonthStart !== null && $currentMonthStart < $maxMonthStart;

$currentWeekStart = strtotime('-' . ((int)date('N', $baseDate) - 1) . ' days', $baseDate);
$minWeekStart = $minDate !== null ? strtotime('-' . ((int)date('N', $minDate) - 1) . ' days', $minDate) : null;
$maxWeekStart = $maxDate !== null ? strtotime('-' . ((int)date('N', $maxDate) - 1) . ' days', $maxDate) : null;
$canPrevWeek = $minWeekStart !== null && $currentWeekStart > $minWeekStart;
$canNextWeek = $maxWeekStart !== null && $currentWeekStart < $maxWeekStart;

$tokenParam = 'token=' . rawurlencode($token);
enlil_page_header('Calendario de proyectos', false);
?>
    <main class="container">
        <div class="page-header">
            <div>
                <h1>Calendario de proyectos</h1>
                <p class="muted"><?php echo htmlspecialchars($person['name']); ?></p>
            </div>
            <div class="actions">
                <a class="btn small <?php echo $view === 'week' ? 'secondary' : ''; ?>" href="?<?php echo $tokenParam; ?>&view=month&date=<?php echo date('Y-m-d', $baseDate); ?>">Mensual</a>
                <a class="btn small <?php echo $view === 'month' ? 'secondary' : ''; ?>" href="?<?php echo $tokenParam; ?>&view=week&date=<?php echo date('Y-m-d', $baseDate); ?>">Semanal</a>
            </div>
        </div>

        <div class="section-card calendar-card">
            <div class="calendar-header">
                <strong><?php echo htmlspecialchars(enlil_format_month_es_public_proj($baseDate)); ?></strong>
                <div class="actions">
                    <?php if ($view === 'month'): ?>
                        <?php if ($canPrevMonth): ?>
                            <a class="btn small secondary" href="?<?php echo $tokenParam; ?>&view=month&date=<?php echo date('Y-m-d', strtotime('-1 month', $baseDate)); ?>">‹</a>
                        <?php endif; ?>
                        <?php if ($canNextMonth): ?>
                            <a class="btn small secondary" href="?<?php echo $tokenParam; ?>&view=month&date=<?php echo date('Y-m-d', strtotime('+1 month', $baseDate)); ?>">›</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($canPrevWeek): ?>
                            <a class="btn small secondary" href="?<?php echo $tokenParam; ?>&view=week&date=<?php echo date('Y-m-d', strtotime('-7 days', $baseDate)); ?>">‹</a>
                        <?php endif; ?>
                        <?php if ($canNextWeek): ?>
                            <a class="btn small secondary" href="?<?php echo $tokenParam; ?>&view=week&date=<?php echo date('Y-m-d', strtotime('+7 days', $baseDate)); ?>">›</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($view === 'month'): ?>
                <div class="calendar-grid">
                    <div class="calendar-row calendar-head">
                        <div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
                    </div>
                    <?php foreach (enlil_month_matrix_public_proj($baseDate) as $row): ?>
                        <div class="calendar-row">
                            <?php foreach ($row as $dayTs): ?>
                                <?php $dayKey = date('Y-m-d', $dayTs); ?>
                                <div class="calendar-cell <?php echo date('m', $dayTs) === date('m', $baseDate) ? '' : 'muted-cell'; ?>">
                                    <div class="calendar-date"><?php echo (int)date('j', $dayTs); ?></div>
                                    <?php if (!empty($tasksByDate[$dayKey])): ?>
                                        <?php foreach ($tasksByDate[$dayKey] as $task): ?>
                                            <div class="calendar-task">
                                                <span class="accent"><?php echo htmlspecialchars($task['project']); ?></span> · <?php echo htmlspecialchars($task['name']); ?>
                                                <span class="muted">· <?php echo htmlspecialchars($task['objective']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="calendar-grid week">
                    <?php foreach (enlil_week_days_public_proj($baseDate) as $dayTs): ?>
                        <?php $dayKey = date('Y-m-d', $dayTs); ?>
                        <div class="calendar-cell">
                            <div class="calendar-date"><?php echo (int)date('j', $dayTs); ?> <?php echo htmlspecialchars(date('D', $dayTs)); ?></div>
                            <?php if (!empty($tasksByDate[$dayKey])): ?>
                                <?php foreach ($tasksByDate[$dayKey] as $task): ?>
                                    <div class="calendar-task">
                                        <span class="accent"><?php echo htmlspecialchars($task['project']); ?></span> · <?php echo htmlspecialchars($task['name']); ?>
                                        <span class="muted">· <?php echo htmlspecialchars($task['objective']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="muted">Sin tareas</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
<?php enlil_page_footer(); ?>
