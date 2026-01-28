<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/avatars.php';

enlil_require_login();
$teams = enlil_teams_all();
$people = enlil_people_all();
$peopleByTeam = [];
foreach ($people as $person) {
    foreach ($person['team_ids'] as $teamId) {
        if (!isset($peopleByTeam[$teamId])) {
            $peopleByTeam[$teamId] = [];
        }
        $peopleByTeam[$teamId][] = $person;
    }
}

enlil_page_header('Equipos');
?>
    <main class="container">
        <div class="page-header">
            <h1>Equipos</h1>
            <a class="btn" href="/equipos_create.php">Crear equipo</a>
        </div>

        <div class="tabs">
            <a class="tab active" href="/equipos_list.php">Equipos</a>
            <a class="tab" href="/personas_list.php">Personas</a>
        </div>

        <?php if (!$teams): ?>
            <p class="empty">Aún no hay equipos. Crea el primero.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Personas</th>
                            <th>Editar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($team['name']); ?></td>
                                <td>
                                    <div class="avatar-group">
                                        <?php
                                        $members = $peopleByTeam[$team['id']] ?? [];
                                        foreach ($members as $person):
                                            $username = ltrim($person['telegram_user'], '@');
                                            $avatarLocal = '';
                                            if (!empty($person['telegram_user_id'])) {
                                                $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                                                if (file_exists($path)) {
                                                    $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                                                }
                                            }
                                            $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg' : '');
                                            $initial = function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1);
                                        ?>
                                            <a class="avatar-link" href="/personas_edit.php?id=<?php echo (int)$person['id']; ?>" title="<?php echo htmlspecialchars($person['name']); ?>">
                                                <span class="avatar-wrap small">
                                                    <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                                    <?php if ($avatarUrl): ?>
                                                        <img class="avatar small avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                                    <?php endif; ?>
                                                </span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <a class="btn small" href="/equipos_edit.php?id=<?php echo (int)$team['id']; ?>">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
<?php enlil_page_footer(); ?>
