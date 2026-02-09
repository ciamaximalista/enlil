<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();
enlil_start_session();

$token = enlil_bot_token();
$currentId = enlil_bot_business_connection_id();

if ($token === '' || $currentId === '') {
    $_SESSION['flash_error'] = 'No se puede refrescar. Falta bot o Business ID.';
    header('Location: /dashboard.php');
    exit;
}

$info = enlil_telegram_get($token, 'getBusinessConnection', [
    'business_connection_id' => $currentId,
]);

if ($info['ok']) {
    $data = json_decode($info['body'], true);
    $user = $data['result']['user'] ?? [];
    $ownerId = (string)($user['id'] ?? '');
    enlil_bot_update_business_connection($currentId, $ownerId);
    $commandsResult = enlil_telegram_post_json($token, 'setMyCommands', [
        'commands' => enlil_bot_commands(),
    ]);
    if (!empty($commandsResult['ok'])) {
        $_SESSION['flash_success'] = 'Business ID validado en Telegram y menú de comandos actualizado.';
    } else {
        $code = $commandsResult['http_code'] ? 'HTTP ' . $commandsResult['http_code'] : 'sin respuesta';
        $_SESSION['flash_success'] = 'Business ID validado en Telegram. Menú de comandos no actualizado (' . $code . ').';
    }
} else {
    $code = $info['http_code'] ? 'HTTP ' . $info['http_code'] : 'sin respuesta';
    $_SESSION['flash_error'] = 'No se pudo validar en Telegram (' . $code . '). Reconecta el bot en Telegram Business.';
}

header('Location: /dashboard.php');
exit;
