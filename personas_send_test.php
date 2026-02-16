<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/checklist_map.php';
require_once __DIR__ . '/includes/tasks_prompts.php';
require_once __DIR__ . '/includes/tasks_delivery.php';

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
    $botBusinessId = trim((string)enlil_bot_business_connection_id());
    $botOwnerId = trim((string)enlil_bot_business_owner_user_id());
    $chatId = $customer['chat_id'] ?? '';
    if ($botBusinessId === '') {
        $failed++;
        $failDetails[] = 'Bot sin business_connection_id.';
    } elseif ($botOwnerId !== '' && $tgUserId !== '' && $tgUserId === $botOwnerId) {
        $failed++;
        $failDetails[] = 'Telegram no permite enviar checklists al mismo usuario que conectó el bot Business. Esta función es solo para clientes.';
    } elseif ($chatId === '') {
        $failed++;
        $failDetails[] = 'No hay chat privado registrado para este usuario. Debe escribirle al bot.';
    } else {
        $checklists = enlil_build_person_checklists((int)$personId, 15, true);
        if (!$checklists) {
            $failed++;
            $failDetails[] = 'No hay tareas pendientes para este usuario.';
        } else {
            $targetBusinessId = (string)$botBusinessId;
            enlil_tasks_prompt_set((string)$chatId, [
                'person_id' => (int)$personId,
                'person_name' => (string)($person['name'] ?? ''),
                'chat_id' => (string)$chatId,
                'business_connection_id' => $targetBusinessId,
                'checklists' => $checklists,
            ]);
            $question = "¡Hola " . trim((string)($person['name'] ?? 'Usuario')) . "!\n¿Puedo enviarte ya las tareas de hoy? (Sí/No)";
            $promptResult = enlil_send_text_optional_business(
                $token,
                (string)$chatId,
                $question,
                $targetBusinessId,
                [
                    'keyboard' => [
                        ['/objetivos', '/mi_calendario'],
                        ['/calendario_proyectos', '/24h'],
                        ['/tareas', '/libera_el_dia'],
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false,
                    'selective' => true,
                ]
            );
            if (!empty($promptResult['ok'])) {
                $success++;
            } else {
                $failed++;
                $code = $promptResult['http_code'] ? 'HTTP ' . $promptResult['http_code'] : 'sin respuesta';
                $detail = enlil_telegram_error_description($promptResult);
                $extra = ' (conn=' . $botBusinessId . ', chat=' . $chatId . ')';
                $failDetails[] = 'Error al enviar pregunta previa (' . $code . ')' . ($detail !== '' ? (': ' . $detail) : '') . '.' . $extra;
            }
        }
    }
}

if ($success > 0) {
    $_SESSION['flash_success'] = 'Pregunta enviada en privado. El checklist se enviará cuando responda Sí.';
}
if ($failed > 0) {
    $_SESSION['flash_error'] = 'Falló el envío. ' . implode(' ', $failDetails);
}

header('Location: /personas_list.php');
exit;
