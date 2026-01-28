<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/avatars.php';
require_once __DIR__ . '/includes/checklists.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';

enlil_require_login();
$people = enlil_people_all();
$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}
$events = enlil_checklist_recent(10);
$businessStatus = [];
$businessOwnerId = '';
$botInfo = enlil_bot_get();
$businessOwnerId = (string)($botInfo['business_owner_user_id'] ?? '');
$duplicateIds = [];
foreach ($people as $p) {
    $uid = (string)($p['telegram_user_id'] ?? '');
    if ($uid === '') {
        continue;
    }
    if (!isset($duplicateIds[$uid])) {
        $duplicateIds[$uid] = [];
    }
    $duplicateIds[$uid][] = $p['name'];
}
foreach ($duplicateIds as $uid => $names) {
    if (count($names) < 2) {
        unset($duplicateIds[$uid]);
    }
}
foreach ($people as $p) {
    $uid = (string)($p['telegram_user_id'] ?? '');
    $businessStatus[$p['id']] = $uid !== '' && enlil_customer_get($uid) ? true : false;
}
enlil_start_session();
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

enlil_page_header('Personas');
?>
    <main class="container">
                <div class="page-header">
                    <h1>Personas</h1>
                    <div class="actions">
                        <a class="btn secondary" href="/avatars_refresh.php">Actualizar avatares</a>
                        <a class="btn" href="/personas_create.php">Crear persona</a>
                    </div>
                </div>

        <div class="tabs">
            <a class="tab" href="/equipos_list.php">Equipos</a>
            <a class="tab active" href="/personas_list.php">Personas</a>
        </div>

        <?php if ($events): ?>
            <div class="section-card events-card">
                <h2>Actualizaciones de tareas</h2>
                <ul class="event-list">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $personName = '';
                        foreach ($people as $p) {
                            if ((string)$p['id'] === (string)$event['person_id']) {
                                $personName = $p['name'];
                                break;
                            }
                        }
                        $doneIds = $event['done_ids'] !== '' ? explode(',', $event['done_ids']) : [];
                        $doneNames = [];
                        foreach ($doneIds as $id) {
                            $id = trim($id);
                            if ($id === '1') {
                                $doneNames[] = 'Tarea de prueba 1';
                            } elseif ($id === '2') {
                                $doneNames[] = 'Tarea de Prueba 2';
                            }
                        }
                        $done = $doneNames ? implode(', ', $doneNames) : '—';
                        $teamName = $event['team_id'] !== '' && isset($teamsById[(int)$event['team_id']]) ? $teamsById[(int)$event['team_id']]['name'] : 'chat privado';
                        ?>
                        <li>
                            <strong><?php echo htmlspecialchars($personName !== '' ? $personName : '@' . $event['telegram_user']); ?></strong>
                            marcó como realizado <?php echo htmlspecialchars($done); ?> en <em><?php echo htmlspecialchars($teamName); ?></em>
                            <span class="mono"><?php echo htmlspecialchars($event['created_at']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($duplicateIds): ?>
            <div class="alert">
                <strong>IDs de Telegram duplicados:</strong>
                <ul>
                    <?php foreach ($duplicateIds as $uid => $names): ?>
                        <li><?php echo htmlspecialchars($uid); ?> → <?php echo htmlspecialchars(implode(', ', $names)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

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

        <?php if (!$people): ?>
            <p class="empty">Aún no hay personas. Crea la primera.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario Telegram</th>
                            <th>Equipos</th>
                            <th>Mensaje de prueba</th>
                            <th>Editar</th>
                            <th>Borrar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <tr>
                                <td>
                                    <?php
                                    $username = ltrim($person['telegram_user'], '@');
                                    $avatarLocal = '';
                                    if (!empty($person['telegram_user_id'])) {
                                        $path = __DIR__ . '/data/avatars/' . $person['telegram_user_id'] . '.jpg';
                                        if (file_exists($path)) {
                                            $avatarLocal = enlil_avatar_url($person['telegram_user_id']);
                                        }
                                    }
                                    $avatarUrl = $avatarLocal !== '' ? $avatarLocal : ($username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg' : '');
                                    ?>
                                    <?php
                                    $initial = function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1);
                                    ?>
                                    <div class="name-cell">
                                        <span class="avatar-wrap">
                                            <span class="avatar placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                            <?php if ($avatarUrl): ?>
                                                <img class="avatar avatar-img" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" onload="this.classList.add('loaded');" onerror="this.remove();">
                                            <?php endif; ?>
                                        </span>
                                        <span><?php echo htmlspecialchars($person['name']); ?></span>
                                    </div>
                                </td>
                                <td class="mono"><?php echo htmlspecialchars($person['telegram_user']); ?></td>
                                <td>
                                    <?php
                                    $names = [];
                                    foreach ($person['team_ids'] as $teamId) {
                                        if (isset($teamsById[$teamId])) {
                                            $names[] = $teamsById[$teamId]['name'];
                                        }
                                    }
                                    echo htmlspecialchars(implode(', ', $names));
                                    ?>
                                </td>
                                <td>
                                    <form class="inline-form" method="post" action="/personas_send_test.php">
                                        <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                        <button class="btn small" type="submit">Enviar</button>
                                    </form>
                                </td>
                                <td>
                                    <a class="btn small" href="/personas_edit.php?id=<?php echo (int)$person['id']; ?>">Editar</a>
                                </td>
                                <td>
                                    <form class="inline-form" method="post" action="/personas_delete.php" data-confirm="¿Seguro que quieres borrar a esta persona?">
                                        <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                        <button class="btn small danger" type="submit">Borrar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="section-card">
            <h2>Clientes (chat privado)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Avatar</th>
                            <th>Nombre</th>
                            <th>Estado</th>
                            <th>Tipo</th>
                            <th>Refrescar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <?php
                            $uid = (string)($person['telegram_user_id'] ?? '');
                            $isConnected = !empty($businessStatus[$person['id']]);
                            $isSelf = $businessOwnerId !== '' && $uid !== '' && $uid === $businessOwnerId;
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
                            <tr>
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
                                    <?php if ($isConnected): ?>
                                        <span class="badge success">Conectado</span>
                                    <?php else: ?>
                                        <span class="badge">Desconectado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isSelf): ?>
                                        <span class="badge">Self</span>
                                    <?php else: ?>
                                        <span class="badge success">Cliente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="/customer_refresh.php" class="inline-form">
                                        <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                        <button class="btn small" type="submit">Refrescar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
<?php enlil_page_footer(); ?>
<script>
document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || '¿Confirmas esta acción?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});

// checklist now sent in private chat; no group selection needed
</script>
