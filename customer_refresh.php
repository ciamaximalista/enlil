<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /personas_list.php');
    exit;
}

$personId = isset($_POST['person_id']) ? (int)$_POST['person_id'] : 0;
if ($personId <= 0) {
    $_SESSION['flash_error'] = 'Persona inválida.';
    header('Location: /personas_list.php');
    exit;
}

$people = enlil_people_all();
$person = null;
foreach ($people as $p) {
    if ($p['id'] === $personId) {
        $person = $p;
        break;
    }
}

if (!$person) {
    $_SESSION['flash_error'] = 'Persona no encontrada.';
    header('Location: /personas_list.php');
    exit;
}

$token = enlil_bot_token();
$tgUserId = (string)($person['telegram_user_id'] ?? '');
if ($token === '' || $tgUserId === '') {
    $_SESSION['flash_error'] = 'Falta bot o telegram_user_id.';
    header('Location: /personas_list.php');
    exit;
}

$result = enlil_telegram_get($token, 'getChat', ['chat_id' => $tgUserId]);
if ($result['ok']) {
    $data = json_decode($result['body'], true);
    $chat = $data['result'] ?? [];
    $username = (string)($chat['username'] ?? ltrim((string)$person['telegram_user'], '@'));
    $chatId = (string)($chat['id'] ?? $tgUserId);
    enlil_customer_save($tgUserId, $username, $chatId);
    $_SESSION['flash_success'] = 'Cliente actualizado.';
} else {
    enlil_customer_delete($tgUserId);
    $_SESSION['flash_error'] = 'No se pudo verificar el chat privado. Pide al usuario que escriba al bot.';
}

header('Location: /personas_list.php');
exit;
