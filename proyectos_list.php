<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();
$projects = enlil_projects_all();
$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}

enlil_page_header('Proyectos');
?>
    <main class="container">
        <div class="page-header">
            <h1>Proyectos</h1>
            <a class="btn" href="/proyectos_create.php">Crear proyecto</a>
        </div>

        <?php if (!$projects): ?>
            <p class="empty">Aún no hay proyectos. Crea el primero.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Equipos</th>
                            <th>Creado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($project['name']); ?></td>
                                <td><?php echo htmlspecialchars($project['description']); ?></td>
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
                                <td class="mono"><?php echo htmlspecialchars($project['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
<?php enlil_page_footer(); ?>
