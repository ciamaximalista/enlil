<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';

enlil_require_login();

$teamId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$team = enlil_teams_get($teamId);

if (!$team) {
    header('Location: /equipos_list.php');
    exit;
}

$people = enlil_people_all();
$members = [];
foreach ($people as $person) {
    if (in_array($teamId, $person['team_ids'], true)) {
        $members[] = $person;
    }
}

$errors = [];
$name = $team['name'];
$telegramGroup = $team['telegram_group'];
$telegramBotToken = enlil_bot_token();
$lookupGroupToken = '';
$lookupGroupSuccess = '';
$lookupGroupError = '';
$lookupGroupDebug = '';

enlil_start_session();
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_group_id'])) {
    $name = trim($_POST['name'] ?? $name);
    $telegramGroup = trim($_POST['telegram_group'] ?? $telegramGroup);
    $lookupGroupToken = $telegramBotToken;

    $notFoundMsg = 'No hemos podido encontrar el ID del grupo. La búsqueda sólo funciona si el bot ha recibido mensajes recientes en ese grupo.';
    $debug = [];
    $debug[] = 'POST keys: ' . implode(', ', array_keys($_POST));

    if ($lookupGroupToken === '' || $name === '') {
        $lookupGroupError = $notFoundMsg;
        $debug[] = 'Token length: ' . strlen($lookupGroupToken);
        $debug[] = 'Nombre equipo: ' . ($name !== '' ? $name : '(vacío)');
        $lookupGroupDebug = implode("\n", $debug);
    } else {
        $result = enlil_telegram_get($lookupGroupToken, 'getUpdates');
        if (!$result['ok']) {
            $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
            $lookupGroupError = 'No se pudo consultar Telegram (' . $code . ').';
            $debug[] = 'HTTP: ' . $code;
            $lookupGroupDebug = implode("\n", $debug);
        } else {
            $foundId = '';
            $body = is_string($result['body']) ? $result['body'] : '';
            $debug[] = 'HTTP: ' . ($result['http_code'] ?: 'sin respuesta');
            $debug[] = 'Body length: ' . strlen($body);
            $debug[] = 'Nombre equipo: ' . $name;

            $data = json_decode($result['body'], true);
            if ($data === null && $result['body'] !== '') {
                $debug[] = 'JSON error: ' . json_last_error_msg();
            }
            if (is_array($data) && isset($data['result']) && is_array($data['result'])) {
                foreach ($data['result'] as $update) {
                    $chat = $update['message']['chat'] ?? null;
                    if (!is_array($chat) || !isset($chat['id'])) {
                        continue;
                    }
                    $chatId = (string)$chat['id'];
                    $title = isset($chat['title']) ? (string)$chat['title'] : '';
                    if ($title !== '' && strcasecmp($title, $name) === 0) {
                        $foundId = $chatId;
                        $debug[] = 'Match title: ' . $title . ' -> ' . $chatId;
                        break;
                    }
                    if ($foundId === '' && strpos($chatId, '-') === 0) {
                        $foundId = $chatId;
                    }
                }
            }

            if ($foundId !== '') {
                $telegramGroup = $foundId;
                $lookupGroupSuccess = 'ID del grupo encontrado y cargado en el campo.';
            } else {
                $lookupGroupError = $notFoundMsg;
            }

            if ($lookupGroupError !== '') {
                $lookupGroupDebug = implode("\n", $debug);
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $telegramGroup = trim($_POST['telegram_group'] ?? '');
    $telegramBotToken = $telegramBotToken;

    if ($name === '') {
        $errors[] = 'El nombre del equipo es obligatorio.';
    }
    if ($telegramGroup === '') {
        $errors[] = 'El grupo de Telegram es obligatorio.';
    }
    if ($telegramBotToken === '') {
        $errors[] = 'Configura el bot de Telegram en "Equipos y personas".';
    }

    if (!$errors) {
        enlil_teams_update($teamId, $name, $telegramGroup, $telegramBotToken);
        header('Location: /equipos_list.php');
        exit;
    }
}

enlil_page_header('Editar equipo');
?>
    <main class="container">
        <div class="page-header">
            <h1>Editar equipo</h1>
            <a class="btn secondary" href="/equipos_list.php">Volver</a>
        </div>

        <div class="tabs">
            <a class="tab active" href="/equipos_list.php">Equipos</a>
            <a class="tab" href="/personas_list.php">Personas</a>
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

        <?php if ($errors): ?>
            <div class="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="form-card" autocomplete="off">
            <label>Nombre del equipo
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <label>ID del grupo de Telegram
                <input type="text" name="telegram_group" value="<?php echo htmlspecialchars($telegramGroup); ?>" placeholder="Ej: -1001234567890">
            </label>
            <div class="form-card">
                <h3>Buscar ID del grupo desde Telegram</h3>
                <p>Usaremos el token del bot de la instalación.</p>
                <?php if ($lookupGroupSuccess): ?>
                    <div class="alert success"><?php echo htmlspecialchars($lookupGroupSuccess); ?></div>
                <?php endif; ?>
                <?php if ($lookupGroupError): ?>
                    <div class="alert"><?php echo htmlspecialchars($lookupGroupError); ?></div>
                <?php endif; ?>
                <?php if ($lookupGroupDebug): ?>
                    <details open>
                        <summary>Detalles técnicos</summary>
                        <pre><?php echo htmlspecialchars($lookupGroupDebug); ?></pre>
                    </details>
                <?php endif; ?>
                <button class="btn secondary" type="submit" name="lookup_group_id" value="1">Buscar ID del grupo</button>
            </div>
            <button type="submit">Guardar cambios</button>
        </form>

        <div class="section-card">
            <div class="page-header">
                <h2>Miembros del equipo</h2>
            </div>
            <?php if (!$members): ?>
                <p class="empty">No hay personas en este equipo todavía.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Avatar</th>
                                <th>Nombre</th>
                                <th>Usuario Telegram</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $person): ?>
                                <?php
                                $username = ltrim($person['telegram_user'], '@');
                                $avatarUrl = $username !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg' : '';
                                $initial = function_exists('mb_substr') ? mb_substr($person['name'], 0, 1) : substr($person['name'], 0, 1);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($avatarUrl): ?>
                                            <img class="avatar small" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="">
                                        <?php else: ?>
                                            <span class="avatar small placeholder"><?php echo htmlspecialchars($initial); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($person['name']); ?></td>
                                    <td class="mono"><?php echo htmlspecialchars($person['telegram_user']); ?></td>
                                    <td>
                                        <form class="inline-form" method="post" action="/equipos_remove_member.php" data-confirm="¿Seguro que quieres quitar a esta persona del equipo?

Al hacerlo:
- El bot lo expulsará del grupo de Telegram
- En Enlil lo borrará del equipo pero no del listado de personas vinculadas a la organización.">
                                            <input type="hidden" name="team_id" value="<?php echo (int)$teamId; ?>">
                                            <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                            <button class="btn small danger" type="submit">Quitar del equipo</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
<script>
document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || '¿Confirmas esta acción?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>
<?php enlil_page_footer(); ?>
