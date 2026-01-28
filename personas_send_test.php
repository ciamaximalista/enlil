<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /personas_list.php');
    exit;
}

$personId = isset($_POST['person_id']) ? (int)$_POST['person_id'] : 0;
$people = enlil_people_all();
$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}

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

$telegramUser = $person['telegram_user'];
if ($telegramUser !== '' && $telegramUser[0] !== '@') {
    $telegramUser = '@' . $telegramUser;
}


$success = 0;
$failed = 0;
$failDetails = [];

$token = enlil_bot_token();
if ($token === '') {
    $failed++;
    $failDetails[] = 'Bot no configurado.';
} else {
    $tgUserId = (string)($person['telegram_user_id'] ?? '');
    $customer = $tgUserId !== '' ? enlil_customer_get($tgUserId) : null;
    $businessConnectionId = trim((string)enlil_bot_business_connection_id());
    if (!$customer || $customer['chat_id'] === '') {
        $failed++;
        $failDetails[] = 'No hay chat privado registrado para este usuario. Debe escribirle al bot.';
    } elseif ($businessConnectionId === '') {
        $failed++;
        $failDetails[] = 'Bot sin business_connection_id. Conecta el bot en Telegram Business.';
    } else {
        $payload = [
            'business_connection_id' => $businessConnectionId,
            'chat_id' => $customer['chat_id'],
            'checklist' => [
                'title' => 'Tareas de prueba',
                'others_can_mark_tasks_as_done' => true,
                'others_can_add_tasks' => false,
                'tasks' => [
                    ['id' => 1, 'text' => 'Tarea de prueba 1'],
                    ['id' => 2, 'text' => 'Tarea de Prueba 2'],
                ],
            ],
        ];

        $result = enlil_telegram_post_json($token, 'sendChecklist', $payload);
        if ($result['ok']) {
            $success++;
        } else {
            $failed++;
            $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
            $detail = '';
            if (is_string($result['body']) && $result['body'] !== '') {
                $data = json_decode($result['body'], true);
                if (is_array($data) && isset($data['description'])) {
                    $detail = $data['description'];
                }
            }
            if ($detail !== '') {
                if (stripos($detail, 'messages must not be sent to self') !== false) {
                    $failDetails[] = 'Telegram no permite enviar checklists al mismo usuario que conectó el bot Business. Esta función es solo para clientes, no para la propia cuenta Business.';
                } else {
                    $failDetails[] = 'Error al enviar checklist (' . $code . '): ' . $detail . '.';
                }
            } else {
                $failDetails[] = 'Error al enviar checklist (' . $code . ').';
            }
        }
    }
}

if ($success > 0) {
    $_SESSION['flash_success'] = 'Checklist enviado en privado.';
}
if ($failed > 0) {
    $_SESSION['flash_error'] = 'Falló el envío. ' . implode(' ', $failDetails);
}

header('Location: /personas_list.php');
exit;
