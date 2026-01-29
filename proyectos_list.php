<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();
enlil_start_session();
$monthsEs = [
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
$projects = enlil_projects_all();
$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

enlil_page_header('Proyectos');
?>
    <main class="container">
        <div class="page-header">
            <h1>Proyectos</h1>
            <a class="btn" href="/proyectos_create.php">Crear proyecto</a>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="alert success">
                <?php echo htmlspecialchars($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="alert">
                <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>

        <?php if (!$projects): ?>
            <p class="empty">Aún no hay proyectos. Crea el primero.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Objetivos</th>
                            <th>Equipos</th>
                            <th>Creado</th>
                            <th>Tareas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><a class="link" href="/proyectos_view.php?id=<?php echo (int)$project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></a></td>
                                <td><?php echo htmlspecialchars($project['description']); ?></td>
                                <td>—</td>
                                <td>
                                    <?php
                                    $names = [];
                                    foreach ($project['team_ids'] as $teamId) {
                                        if (isset($teamsById[$teamId])) {
                                            $names[] = $teamsById[$teamId]['name'];
                                        }
                                    }
                                    echo htmlspecialchars(implode(', ', $names));
                                    ?>
                                </td>
                                <td class="mono">
                                    <?php
                                    $createdAt = $project['created_at'] ?? '';
                                    $ts = $createdAt !== '' ? strtotime($createdAt) : false;
                                    if ($ts !== false) {
                                        $day = (int)date('j', $ts);
                                        $month = (int)date('n', $ts);
                                        $year = date('Y', $ts);
                                        $monthName = $monthsEs[$month] ?? '';
                                        if ($monthName !== '') {
                                            echo htmlspecialchars($day . ' de ' . $monthName . ' de ' . $year);
                                        } else {
                                            echo htmlspecialchars($createdAt);
                                        }
                                    } else {
                                        echo htmlspecialchars($createdAt);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form class="inline-form" method="post" action="/proyectos_send_tasks.php">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                        <button class="btn small" type="submit">Enviar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
<?php enlil_page_footer(); ?>
