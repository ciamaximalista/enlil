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
    $_SESSION['flash_success'] = 'Webhook activado.';
} else {
    $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
    $_SESSION['flash_error'] = 'No se pudo activar el webhook (' . $code . ').';
}

header('Location: /equipos_personas.php');
exit;
