<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/people.php';

enlil_require_login();

$personId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$person = enlil_people_get($personId);
if (!$person) {
    header('Location: /calendarios_list.php');
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
foreach ($projects as $proj) {
    $project = enlil_projects_get((int)$proj['id']);
    if (!$project) {
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
            if (!in_array($personId, $task['responsible_ids'], true)) {
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

function enlil_month_matrix(int $baseDate): array {
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

function enlil_week_days(int $baseDate): array {
    $dow = (int)date('N', $baseDate);
    $start = strtotime('-' . ($dow - 1) . ' days', $baseDate);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = strtotime('+' . $i . ' days', $start);
    }
    return $days;
}

function enlil_format_month_es(int $ts): string {
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

enlil_page_header('Calendario personal');
?>
    <main class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($person['name']); ?></h1>
                <p class="muted">Calendario personal</p>
            </div>
            <div class="actions">
                <a class="btn secondary" href="/calendarios_list.php">Volver</a>
                <a class="btn small <?php echo $view === 'week' ? 'secondary' : ''; ?>" href="?id=<?php echo (int)$personId; ?>&view=month&date=<?php echo date('Y-m-d', $baseDate); ?>">Mensual</a>
                <a class="btn small <?php echo $view === 'month' ? 'secondary' : ''; ?>" href="?id=<?php echo (int)$personId; ?>&view=week&date=<?php echo date('Y-m-d', $baseDate); ?>">Semanal</a>
            </div>
        </div>

        <div class="section-card calendar-card">
            <div class="calendar-header">
                <strong><?php echo htmlspecialchars(enlil_format_month_es($baseDate)); ?></strong>
                <div class="actions">
                    <?php if ($view === 'month'): ?>
                        <?php if ($canPrevMonth): ?>
                            <a class="btn small secondary" href="?id=<?php echo (int)$personId; ?>&view=month&date=<?php echo date('Y-m-d', strtotime('-1 month', $baseDate)); ?>">‹</a>
                        <?php endif; ?>
                        <?php if ($canNextMonth): ?>
                            <a class="btn small secondary" href="?id=<?php echo (int)$personId; ?>&view=month&date=<?php echo date('Y-m-d', strtotime('+1 month', $baseDate)); ?>">›</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($canPrevWeek): ?>
                            <a class="btn small secondary" href="?id=<?php echo (int)$personId; ?>&view=week&date=<?php echo date('Y-m-d', strtotime('-7 days', $baseDate)); ?>">‹</a>
                        <?php endif; ?>
                        <?php if ($canNextWeek): ?>
                            <a class="btn small secondary" href="?id=<?php echo (int)$personId; ?>&view=week&date=<?php echo date('Y-m-d', strtotime('+7 days', $baseDate)); ?>">›</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($view === 'month'): ?>
                <div class="calendar-grid">
                    <div class="calendar-row calendar-head">
                        <div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
                    </div>
                    <?php foreach (enlil_month_matrix($baseDate) as $row): ?>
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
                    <?php foreach (enlil_week_days($baseDate) as $dayTs): ?>
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
