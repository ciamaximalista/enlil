<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();
$projects = enlil_projects_all();
$people = enlil_people_all();
$peopleSorted = $people;
usort($peopleSorted, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
$projectsSorted = $projects;
usort($projectsSorted, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
$compareError = isset($_GET['compare_error']) ? (int)$_GET['compare_error'] : 0;

enlil_page_header('Calendarios');
?>
    <main class="container">
        <div class="page-header">
            <h1>Calendarios</h1>
        </div>

        <div class="calendar-list-grid">
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
                            <?php foreach ($projectsSorted as $project): ?>
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
        <div class="section-card">
            <h2>Por persona</h2>
            <?php if ($compareError === 1): ?>
                <div class="notice error">Selecciona al menos dos personas para comparar.</div>
            <?php endif; ?>
            <?php if (!$people): ?>
                <p class="empty">Aún no hay personas.</p>
            <?php else: ?>
                <form class="table-wrap" method="get" action="/calendarios_comparar.php" onsubmit="return validateCompareSelection();">
                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>Avatar</th>
                                <th>Persona</th>
                                <th>Ver calendario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($peopleSorted as $person): ?>
                                <?php
                                $username = trim((string)($person['telegram_user'] ?? ''));
                                $initial = $person['name'] !== '' ? strtoupper(mb_substr($person['name'], 0, 1)) : '?';
                                $avatarLocal = '';
                                if (!empty($person['telegram_user_id'])) {
                                    $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                                    if (file_exists($path)) {
                                        $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                                    }
                                }
                                $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg' : '');
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="people[]" value="<?php echo (int)$person['id']; ?>">
                                    </td>
                                    <td>
                                        <span class="avatar-wrap small">
                                            <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                            <?php if ($avatarUrl): ?>
                                                <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($person['name']); ?></td>
                                    <td>
                                        <a class="btn small" href="/calendarios_usuario.php?id=<?php echo (int)$person['id']; ?>">Abrir</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="actions" style="margin-top:16px;">
                        <button class="btn" type="submit">Comparar</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        </div>
    </main>
<?php enlil_page_footer(); ?>
<script>
function validateCompareSelection() {
    const checked = document.querySelectorAll('input[name="people[]"]:checked');
    if (checked.length < 2) {
        alert('Selecciona al menos dos personas para comparar.');
        return false;
    }
    return true;
}
</script>
