<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/people.php';

enlil_require_login();
$projects = enlil_projects_all();
$people = enlil_people_all();

enlil_page_header('Calendarios');
?>
    <main class="container">
        <div class="page-header">
            <h1>Calendarios</h1>
        </div>

        <div class="section-card">
            <h2>Por proyecto</h2>
            <?php if (!$projects): ?>
                <p class="empty">Aún no hay proyectos.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Proyecto</th>
                                <th>Ver calendario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($project['name']); ?></td>
                                    <td>
                                        <a class="btn small" href="/calendarios_proyecto.php?id=<?php echo (int)$project['id']; ?>">Abrir</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="section-card" style="margin-top:24px;">
            <h2>Por persona</h2>
            <?php if (!$people): ?>
                <p class="empty">Aún no hay personas.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Persona</th>
                                <th>Ver calendario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($people as $person): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($person['name']); ?></td>
                                    <td>
                                        <a class="btn small" href="/calendarios_usuario.php?id=<?php echo (int)$person['id']; ?>">Abrir</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
<?php enlil_page_footer(); ?>
