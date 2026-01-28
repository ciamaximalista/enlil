<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';

enlil_require_login();

$personId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$person = enlil_people_get($personId);

if (!$person) {
    header('Location: /personas_list.php');
    exit;
}

$teams = enlil_teams_all();
$teamIds = array_map(function ($team) {
    return $team['id'];
}, $teams);
$people = enlil_people_all();

$errors = [];
$name = $person['name'];
$telegramUser = $person['telegram_user'];
$telegramUserId = $person['telegram_user_id'] ?? '';
$selectedTeams = $person['team_ids'];
$lookupToken = enlil_bot_token();
$lookupSuccess = '';
$lookupError = '';
$lookupDebug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_user_id'])) {
    $name = trim($_POST['name'] ?? $name);
    $telegramUser = trim($_POST['telegram_user'] ?? $telegramUser);
    $telegramUserId = trim($_POST['telegram_user_id'] ?? $telegramUserId);
    $selectedTeams = $_POST['team_ids'] ?? $selectedTeams;
    if (!is_array($selectedTeams)) {
        $selectedTeams = [];
    }
    $selectedTeams = array_values(array_map('intval', $selectedTeams));
    $lookupToken = enlil_bot_token();

    if ($telegramUser !== '' && $telegramUser[0] !== '@') {
        $telegramUser = '@' . $telegramUser;
    }

    $username = ltrim($telegramUser, '@');
    $notFoundMsg = 'No hemos podido encontrar la ID de esta persona. La búsqueda sólo funciona si el usuario ha enviado un mensaje reciente en un grupo donde esté el bot.';
    $debug = [];
    $debug[] = 'POST keys: ' . implode(', ', array_keys($_POST));
    if ($lookupToken === '' || $username === '') {
        $lookupError = $notFoundMsg;
        $debug[] = 'Token length: ' . strlen($lookupToken);
        $debug[] = 'Usuario: ' . ($username !== '' ? $username : '(vacío)');
        $lookupDebug = implode("\n", $debug);
    } else {
        $result = enlil_telegram_get($lookupToken, 'getUpdates');
        if (!$result['ok']) {
            $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
            if ($code === 'HTTP 409') {
                $lookupError = 'El webhook está activo y Telegram no permite usar getUpdates. Desactiva el webhook temporalmente o pide al usuario que escriba al bot para registrar su ID.';
            } else {
                $lookupError = 'No se pudo consultar Telegram (' . $code . ').';
            }
            $debug[] = 'HTTP: ' . $code;
            $lookupDebug = implode("\n", $debug);
        } else {
            $foundId = '';
            $body = is_string($result['body']) ? $result['body'] : '';
            $debug[] = 'HTTP: ' . ($result['http_code'] ?: 'sin respuesta');
            $debug[] = 'Body length: ' . strlen($body);
            $debug[] = 'Username: ' . $username;
            if ($body !== '') {
                $bodyLower = strtolower($body);
                $usernameLower = strtolower($username);
                $patternUserFirst = '/\"username\"\\s*:\\s*\"' . preg_quote($usernameLower, '/') . '\".*?\"id\"\\s*:\\s*(\\d+)/is';
                $patternIdFirst = '/\"id\"\\s*:\\s*(\\d+).*?\"username\"\\s*:\\s*\"' . preg_quote($usernameLower, '/') . '\"/is';
                if (preg_match($patternUserFirst, $bodyLower, $m) || preg_match($patternIdFirst, $bodyLower, $m)) {
                    $foundId = (string)$m[1];
                    $debug[] = 'Regex match: ' . $foundId;
                } else {
                    $debug[] = 'Regex match: no';
                }
            }

            $data = json_decode($result['body'], true);
            if ($data === null && $result['body'] !== '') {
                $debug[] = 'JSON error: ' . json_last_error_msg();
            }
            if ($foundId === '' && is_array($data) && isset($data['result']) && is_array($data['result'])) {
                $stack = $data['result'];
                while ($stack && $foundId === '') {
                    $current = array_pop($stack);
                    if (!is_array($current)) {
                        continue;
                    }
                    if (isset($current['username']) && isset($current['id'])) {
                        if (strcasecmp((string)$current['username'], $username) === 0) {
                            $foundId = (string)$current['id'];
                            break;
                        }
                    }
                    foreach ($current as $value) {
                        if (is_array($value)) {
                            $stack[] = $value;
                        }
                    }
                }
                $debug[] = $foundId !== '' ? 'JSON match: ' . $foundId : 'JSON match: no';
            }

            if ($foundId !== '') {
                $telegramUserId = $foundId;
                $lookupSuccess = 'ID encontrado y cargado en el campo.';
            } else {
                $lookupError = $notFoundMsg;
            }

            if ($lookupError !== '') {
                $lookupDebug = implode("\n", $debug);
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $telegramUser = trim($_POST['telegram_user'] ?? '');
    $telegramUserId = trim($_POST['telegram_user_id'] ?? '');
    $selectedTeams = $_POST['team_ids'] ?? [];
    if (!is_array($selectedTeams)) {
        $selectedTeams = [];
    }

    if ($name === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($telegramUser === '') {
        $errors[] = 'El usuario de Telegram es obligatorio.';
    }

    if ($telegramUser !== '' && $telegramUser[0] !== '@') {
        $telegramUser = '@' . $telegramUser;
    }

    if ($telegramUserId !== '' && !ctype_digit($telegramUserId)) {
        $errors[] = 'El ID de usuario de Telegram debe ser numérico.';
    }
    if ($telegramUserId !== '') {
        foreach ($people as $p) {
            if ((int)$p['id'] !== $personId && (string)$p['telegram_user_id'] === $telegramUserId) {
                $errors[] = 'Ese ID de Telegram ya está asignado a otra persona.';
                break;
            }
        }
    }

    $selectedTeams = array_values(array_filter(array_map('intval', $selectedTeams), function ($id) use ($teamIds) {
        return in_array($id, $teamIds, true);
    }));

    if (!$errors) {
        enlil_people_update($personId, $name, $telegramUser, $telegramUserId, $selectedTeams);
        header('Location: /personas_list.php');
        exit;
    }
}

enlil_page_header('Editar persona');
?>
    <main class="container">
        <div class="page-header">
            <h1>Editar persona</h1>
            <a class="btn secondary" href="/personas_list.php">Volver</a>
        </div>

        <div class="tabs">
            <a class="tab" href="/equipos_list.php">Equipos</a>
            <a class="tab active" href="/personas_list.php">Personas</a>
        </div>

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
            <label>Nombre
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <label>Usuario de Telegram
                <input type="text" name="telegram_user" required value="<?php echo htmlspecialchars($telegramUser); ?>" placeholder="@usuario">
            </label>
            <label>ID de usuario de Telegram (para reorganizar los grupos de telegram al reorganizar los equipos)
                <input type="text" name="telegram_user_id" value="<?php echo htmlspecialchars($telegramUserId); ?>" placeholder="Ej: 123456789">
            </label>
            <?php if ($telegramUserId === ''): ?>
                <div class="alert">
                    Este usuario no tiene ID de Telegram guardado. Usa “Buscar ID en Telegram”.
                </div>
            <?php endif; ?>
            <div class="form-card">
                <h3>Buscar ID desde Telegram</h3>
                <p>Usaremos el token del bot de la instalación.</p>
                <?php if ($lookupSuccess): ?>
                    <div class="alert success"><?php echo htmlspecialchars($lookupSuccess); ?></div>
                <?php endif; ?>
                <?php if ($lookupError): ?>
                    <div class="alert"><?php echo htmlspecialchars($lookupError); ?></div>
                <?php endif; ?>
                <?php if ($lookupDebug): ?>
                    <details open>
                        <summary>Detalles técnicos</summary>
                        <pre><?php echo htmlspecialchars($lookupDebug); ?></pre>
                    </details>
                <?php endif; ?>
                <button class="btn secondary" type="submit" name="lookup_user_id" value="1">Buscar ID en Telegram</button>
            </div>

            <fieldset>
                <legend>Equipos</legend>
                <?php if (!$teams): ?>
                    <p class="empty">No hay equipos aún. Crea un equipo primero.</p>
                <?php else: ?>
                    <div class="checkbox-grid">
                        <?php foreach ($teams as $team): ?>
                            <?php $checked = in_array($team['id'], $selectedTeams, true); ?>
                            <label class="checkbox">
                                <input type="checkbox" name="team_ids[]" value="<?php echo $team['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($team['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <button type="submit">Guardar cambios</button>
        </form>
    </main>
<?php enlil_page_footer(); ?>
