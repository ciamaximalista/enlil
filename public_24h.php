<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/task_updates.php';

$token = trim($_GET['token'] ?? '');
$entry = $token !== '' ? enlil_token_get($token) : null;
if (!$entry || ($entry['type'] ?? '') !== 'tareas_24h') {
    http_response_code(403);
    enlil_page_header('Acceso caducado', false);
    ?>
    <main class="container">
        <h1>Acceso caducado</h1>
        <p>Este enlace ha expirado. Solicita el comando de nuevo para generar uno válido.</p>
    </main>
    <?php
    enlil_page_footer();
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$updates24h = enlil_task_updates_last_24h((int)($entry['person_id'] ?? 0));
enlil_page_header('Tareas 24h', false);
?>
<main class="container">
    <h1>Tareas cumplidas en las últimas 24 horas</h1>
    <?php if (!$updates24h): ?>
        <p class="muted">No hay tareas cumplidas en las últimas 24 horas.</p>
    <?php else: ?>
        <?php foreach ($updates24h as $group): ?>
            <h2><?php echo htmlspecialchars($group['project']['name'] ?? 'Proyecto'); ?></h2>
            <?php if (!empty($group['project']['team_names'])): ?>
                <p class="project-meta"><?php echo htmlspecialchars(implode(', ', $group['project']['team_names'])); ?></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="fixed-table">
                    <thead>
                        <tr>
                            <th>Objetivo</th>
                            <th>Tarea</th>
                            <th class="center">Persona</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['rows'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['objective']); ?></td>
                                <td><?php echo htmlspecialchars($row['task']); ?></td>
                                <td class="center">
                                    <?php if ($row['avatar']): ?>
                                        <img class="avatar small" src="<?php echo htmlspecialchars($row['avatar']); ?>" alt="" title="<?php echo htmlspecialchars($row['person']); ?>">
                                    <?php else: ?>
                                        <?php
                                        $initial = $row['person'] !== '' ? (function_exists('mb_substr') ? mb_substr($row['person'], 0, 1) : substr($row['person'], 0, 1)) : 'P';
                                        ?>
                                        <span class="avatar small placeholder" title="<?php echo htmlspecialchars($row['person']); ?>"><?php echo htmlspecialchars($initial); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php enlil_page_footer(); ?>
