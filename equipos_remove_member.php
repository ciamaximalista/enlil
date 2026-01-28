<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /equipos_list.php');
    exit;
}

$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$personId = isset($_POST['person_id']) ? (int)$_POST['person_id'] : 0;

if ($teamId <= 0 || $personId <= 0) {
    $_SESSION['flash_error'] = 'Datos inválidos.';
    header('Location: /equipos_list.php');
    exit;
}

$team = enlil_teams_get($teamId);
if (!$team) {
    $_SESSION['flash_error'] = 'Equipo no encontrado.';
    header('Location: /equipos_list.php');
    exit;
}

$person = enlil_people_get($personId);
if (!$person) {
    $_SESSION['flash_error'] = 'Persona no encontrada.';
    header('Location: /equipos_edit.php?id=' . $teamId);
    exit;
}

$telegramUserId = trim((string)($person['telegram_user_id'] ?? ''));
if ($telegramUserId === '' || !ctype_digit($telegramUserId)) {
    $_SESSION['flash_error'] = 'No se puede expulsar: falta el ID de usuario de Telegram en la ficha de la persona.';
    header('Location: /equipos_edit.php?id=' . $teamId);
    exit;
}

$token = enlil_bot_token();
$chatId = trim($team['telegram_group']);
if ($token === '' || $chatId === '') {
    $_SESSION['flash_error'] = 'No se puede expulsar: falta el bot de Telegram o el ID de grupo.';
    header('Location: /equipos_edit.php?id=' . $teamId);
    exit;
}

$kick = enlil_telegram_post($token, 'banChatMember', [
    'chat_id' => $chatId,
    'user_id' => $telegramUserId,
]);

if (!$kick['ok']) {
    $code = $kick['http_code'] ? 'HTTP ' . $kick['http_code'] : 'sin respuesta';
    $detail = '';
    if (is_string($kick['body']) && $kick['body'] !== '') {
        $data = json_decode($kick['body'], true);
        if (is_array($data) && isset($data['description'])) {
            $detail = $data['description'];
        }
    }
    if ($detail !== '') {
        if (stripos($detail, 'administrator') !== false) {
            $_SESSION['flash_error'] = 'No se pudo sacar al usuario del grupo de Telegram porque es administrador; revoca sus permisos y prueba de nuevo.';
        } else {
            $_SESSION['flash_error'] = 'No se pudo expulsar en Telegram (' . $code . '): ' . $detail . '.';
        }
    } else {
        $_SESSION['flash_error'] = 'No se pudo expulsar en Telegram (' . $code . ').';
    }
    header('Location: /equipos_edit.php?id=' . $teamId);
    exit;
}

$updated = enlil_people_remove_from_team($personId, $teamId);
if ($updated) {
    $_SESSION['flash_success'] = 'Persona removida del equipo.';
} else {
    $_SESSION['flash_error'] = 'No se pudo remover a la persona del equipo.';
}

header('Location: /equipos_edit.php?id=' . $teamId);
exit;
