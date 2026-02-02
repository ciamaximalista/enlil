<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/avatars.php';
require_once __DIR__ . '/includes/checklists.php';
require_once __DIR__ . '/includes/checklist_map.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';

enlil_require_login();
$people = enlil_people_all();
usort($people, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
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
    $business = $uid !== '' ? enlil_business_get($uid) : null;
    $businessStatus[$p['id']] = $business && $business['connection_id'] !== '' ? true : false;
}
enlil_start_session();
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

enlil_page_header('Personas');

function enlil_find_task_name_by_id(int $projectId, int $objectiveId, int $taskId): string {
    if ($taskId <= 0) {
        return '';
    }
    $projects = [];
    if ($projectId > 0) {
        $proj = enlil_projects_get($projectId);
        if ($proj) {
            $projects[] = $proj;
        }
    } else {
        $projects = enlil_projects_all();
        $projects = array_filter(array_map(function ($p) {
            return enlil_projects_get((int)$p['id']);
        }, $projects));
    }
    foreach ($projects as $proj) {
        foreach ($proj['objectives'] ?? [] as $objective) {
            if ($objectiveId > 0 && (int)$objective['id'] !== $objectiveId) {
                continue;
            }
            foreach ($objective['tasks'] ?? [] as $task) {
                if ((int)$task['id'] === $taskId) {
                    return (string)($task['name'] ?? '');
                }
            }
        }
    }
    return '';
}

function enlil_find_task_name_by_id_for_person(int $personId, int $taskId): string {
    if ($personId <= 0 || $taskId <= 0) {
        return '';
    }
    $projects = enlil_projects_all();
    foreach ($projects as $proj) {
        $full = enlil_projects_get((int)$proj['id']);
        if (!$full) {
            continue;
        }
        foreach ($full['objectives'] ?? [] as $objective) {
            foreach ($objective['tasks'] ?? [] as $task) {
                if ((int)$task['id'] !== $taskId) {
                    continue;
                }
                if (!in_array($personId, $task['responsible_ids'] ?? [], true)) {
                    continue;
                }
                return (string)($task['name'] ?? '');
            }
        }
    }
    return '';
}

function enlil_format_datetime_es(string $iso): string {
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTime($iso);
        $tz = new DateTimeZone('Europe/Madrid');
        $dt->setTimezone($tz);
    } catch (Exception $e) {
        return $iso;
    }
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    $day = (int)$dt->format('j');
    $monthNum = (int)$dt->format('n');
    $monthName = $months[$monthNum] ?? $dt->format('m');
    $time = $dt->format('H:i');
    return 'el ' . $day . ' de ' . $monthName . ' a las ' . $time . ' horas';
}
?>
    <main class="container">
                <div class="page-header">
                    <h1>Personas</h1>
                    <div class="actions">
                        <form method="post" action="/people_bulk_lookup.php" class="inline-form">
                            <button class="btn secondary" type="submit">Buscar IDs en Telegram</button>
                        </form>
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
                <div class="page-header">
                    <h2>Actualizaciones de tareas</h2>
                    <form method="post" action="/checklist_clear.php" class="inline-form">
                        <button class="btn small danger" type="submit">Borrar</button>
                    </form>
                </div>
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
                        $notDoneIds = $event['not_done_ids'] !== '' ? explode(',', $event['not_done_ids']) : [];
                        $map = null;
                        if (!empty($event['chat_id']) && !empty($event['message_id'])) {
                            $map = enlil_checklist_map_get((string)$event['chat_id'], (string)$event['message_id']);
                        }
                        $mapMissing = ($event['map_missing'] ?? '') === '1';
                        $mapProjectId = $map ? (int)$map['project_id'] : 0;
                        $mapObjectiveId = $map ? (int)$map['objective_id'] : 0;
                        $mapTaskMeta = $map && isset($map['task_meta']) && is_array($map['task_meta']) ? $map['task_meta'] : [];
                        $personIdEvent = (int)($event['person_id'] ?? 0);
                        $doneNames = [];
                        foreach ($doneIds as $id) {
                            $id = (int)trim($id);
                            if ($id <= 0) {
                                continue;
                            }
                            [$decodedProjectId, $decodedTaskId] = enlil_checklist_decode_task_id($id);
                            if ($decodedProjectId > 0 && $decodedTaskId > 0) {
                                $name = enlil_find_task_name_by_id($decodedProjectId, 0, $decodedTaskId);
                                $doneNames[] = $name !== '' ? $name : ('Tarea #' . $decodedTaskId);
                                continue;
                            }
                            $meta = !empty($event['chat_id']) ? enlil_checklist_map_find_task_meta((string)$event['chat_id'], $id) : null;
                            if ($meta && ($meta['name'] ?? '') !== '') {
                                $doneNames[] = (string)$meta['name'];
                                continue;
                            }
                            $name = $mapTaskMeta[$id]['name'] ?? '';
                            if ($name === '') {
                                $name = enlil_find_task_name_by_id($mapProjectId, $mapObjectiveId, $id);
                            }
                            if ($name === '') {
                                $doneNames[] = 'Tarea #' . $id;
                                continue;
                            }
                            $doneNames[] = $name;
                        }
                        $done = $doneNames ? implode(', ', $doneNames) : '—';
                        $notDoneNames = [];
                        foreach ($notDoneIds as $id) {
                            $id = (int)trim($id);
                            if ($id <= 0) {
                                continue;
                            }
                            [$decodedProjectId, $decodedTaskId] = enlil_checklist_decode_task_id($id);
                            if ($decodedProjectId > 0 && $decodedTaskId > 0) {
                                $name = enlil_find_task_name_by_id($decodedProjectId, 0, $decodedTaskId);
                                $notDoneNames[] = $name !== '' ? $name : ('Tarea #' . $decodedTaskId);
                                continue;
                            }
                            $meta = !empty($event['chat_id']) ? enlil_checklist_map_find_task_meta((string)$event['chat_id'], $id) : null;
                            if ($meta && ($meta['name'] ?? '') !== '') {
                                $notDoneNames[] = (string)$meta['name'];
                                continue;
                            }
                            $name = $mapTaskMeta[$id]['name'] ?? '';
                            if ($name === '') {
                                $name = enlil_find_task_name_by_id($mapProjectId, $mapObjectiveId, $id);
                            }
                            if ($name === '') {
                                $notDoneNames[] = 'Tarea #' . $id;
                                continue;
                            }
                            $notDoneNames[] = $name;
                        }
                        $notDone = $notDoneNames ? implode(', ', $notDoneNames) : '—';
                        $actionText = $doneNames ? 'marcó como realizado' : ($notDoneNames ? 'marcó como pendiente' : 'actualizó');
                        $taskText = $doneNames ? $done : ($notDoneNames ? $notDone : '—');
                        if ($mapMissing) {
                            $actionText = 'actualizó una tarea antigua';
                            $taskText = '—';
                        }
                        $whenText = enlil_format_datetime_es((string)$event['created_at']);
                        ?>
                        <li>
                            <strong><?php echo htmlspecialchars($personName !== '' ? $personName : '@' . $event['telegram_user']); ?></strong>
                            <?php echo htmlspecialchars($actionText); ?> <?php echo htmlspecialchars($taskText); ?>
                            <span class="mono"><?php echo htmlspecialchars($whenText !== '' ? $whenText : $event['created_at']); ?></span>
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
                            <th>ID</th>
                            <th>Equipos</th>
                            <th>Refrescar</th>
                            <th>Estado</th>
                            <th>Tipo</th>
                            <th>Tareas de hoy</th>
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
                                    <?php if (!empty($person['telegram_user_id'])): ?>
                                        <span class="badge success">✓</span>
                                    <?php else: ?>
                                        <span class="badge">✗</span>
                                    <?php endif; ?>
                                </td>
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
                                    <form method="post" action="/customer_refresh.php" class="inline-form">
                                        <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                        <button class="btn small" type="submit">Refrescar</button>
                                    </form>
                                </td>
                                <td>
                                    <?php $isConnected = !empty($businessStatus[$person['id']]); ?>
                                    <?php if ($isConnected): ?>
                                        <span class="badge success">Conectado</span>
                                    <?php else: ?>
                                        <span class="badge">Desconectado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $uid = (string)($person['telegram_user_id'] ?? '');
                                    $isSelf = $businessOwnerId !== '' && $uid !== '' && $uid === $businessOwnerId;
                                    ?>
                                    <?php if ($isSelf): ?>
                                        <span class="badge">Self</span>
                                    <?php else: ?>
                                        <span class="badge success">Cliente</span>
                                    <?php endif; ?>
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
