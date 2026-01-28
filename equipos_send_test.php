<?php
require_once __DIR__ . '/includes/auth.php';
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
$teams = enlil_teams_all();
$team = null;
foreach ($teams as $t) {
    if ((int)$t['id'] === $teamId) {
        $team = $t;
        break;
    }
}

if (!$team) {
    $_SESSION['flash_error'] = 'Equipo no encontrado.';
    header('Location: /equipos_list.php');
    exit;
}

$token = enlil_bot_token();
if ($token === '') {
    $_SESSION['flash_error'] = 'Bot no configurado.';
    header('Location: /equipos_list.php');
    exit;
}

$groupId = trim((string)($team['telegram_group'] ?? ''));
if ($groupId === '') {
    $_SESSION['flash_error'] = 'Este equipo no tiene ID de grupo de Telegram.';
    header('Location: /equipos_list.php');
    exit;
}

$payload = [
    'chat_id' => $groupId,
    'text' => '⛈⛈⛈⛈¡Enlil es el dios de las tormentas! ¡Vuelve el día del vendaval!⛈⛈⛈⛈ ',
];

$result = enlil_telegram_post_json($token, 'sendMessage', $payload);
if ($result['ok']) {
    $_SESSION['flash_success'] = 'Mensaje enviado al grupo.';
} else {
    $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
    $detail = '';
    if (is_string($result['body']) && $result['body'] !== '') {
        $data = json_decode($result['body'], true);
        if (is_array($data) && isset($data['description'])) {
            $detail = $data['description'];
        }
    }
    $_SESSION['flash_error'] = $detail !== ''
        ? 'No se pudo enviar el mensaje (' . $code . '): ' . $detail . '.'
        : 'No se pudo enviar el mensaje (' . $code . ').';
}

header('Location: /equipos_list.php');
exit;
