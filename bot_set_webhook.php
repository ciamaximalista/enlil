<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();
enlil_start_session();

$token = enlil_bot_token();
$url = enlil_bot_webhook_url();

if ($token === '' || $url === '') {
    $_SESSION['flash_error'] = 'No se pudo activar el webhook. Revisa el bot o el dominio.';
    header('Location: /equipos_personas.php');
    exit;
}

$result = enlil_telegram_post($token, 'setWebhook', ['url' => $url]);
if ($result['ok']) {
    $commandsResult = enlil_telegram_post_json($token, 'setMyCommands', [
        'commands' => enlil_bot_commands(),
    ]);
    if (!empty($commandsResult['ok'])) {
        $_SESSION['flash_success'] = 'Webhook activado y menú de comandos actualizado.';
    } else {
        $code = $commandsResult['http_code'] ? 'HTTP ' . $commandsResult['http_code'] : 'sin respuesta';
        $_SESSION['flash_success'] = 'Webhook activado. No se pudo actualizar el menú de comandos (' . $code . ').';
    }
} else {
    $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
    $_SESSION['flash_error'] = 'No se pudo activar el webhook (' . $code . ').';
}

header('Location: /equipos_personas.php');
exit;
