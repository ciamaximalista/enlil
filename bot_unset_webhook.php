<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();
enlil_start_session();

$token = enlil_bot_token();
if ($token === '') {
    $_SESSION['flash_error'] = 'No se pudo desactivar el webhook. Revisa el bot.';
    header('Location: /dashboard.php');
    exit;
}

$result = enlil_telegram_post($token, 'setWebhook', ['url' => '']);
if ($result['ok']) {
    $_SESSION['flash_success'] = 'Webhook desactivado.';
} else {
    $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
    $_SESSION['flash_error'] = 'No se pudo desactivar el webhook (' . $code . ').';
}

header('Location: /dashboard.php');
exit;
