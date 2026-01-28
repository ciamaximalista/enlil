<?php
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/checklists.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';

// Identify which bot sent this update via token parameter.
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$globalToken = enlil_bot_token();
if ($globalToken === '' || !hash_equals($globalToken, $token)) {
    http_response_code(404);
    echo 'Bot token not configured';
    exit;
}

$raw = file_get_contents('php://input');
$logDir = __DIR__ . '/data';
if (is_dir($logDir)) {
    $logPath = $logDir . '/webhook.log';
    $line = date('c') . ' token=' . substr($token, 0, 10) . ' body=' . $raw . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
}
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// Telegram Business connection update
$connection = $update['business_connection'] ?? null;
if (is_array($connection) && isset($connection['id'])) {
    $connectionId = (string)$connection['id'];
    $userChatId = (string)($connection['user_chat_id'] ?? '');
    $user = $connection['user'] ?? [];
    $tgUserId = (string)($user['id'] ?? '');
    enlil_bot_update_business_connection($connectionId, $tgUserId);
    if ($tgUserId !== '' && $userChatId !== '') {
        enlil_business_save($tgUserId, $connectionId, $userChatId);
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

// Business chat updates (customers)
$businessMessage = $update['business_message'] ?? ($update['edited_business_message'] ?? null);
if (is_array($businessMessage)) {
    $chat = $businessMessage['chat'] ?? [];
    if (is_array($chat) && isset($chat['type']) && $chat['type'] === 'private') {
        $tgUserId = (string)($chat['id'] ?? '');
        $tgUsername = (string)($chat['username'] ?? '');
        $chatId = (string)($chat['id'] ?? '');
        if ($tgUserId !== '' && $chatId !== '') {
            enlil_customer_save($tgUserId, $tgUsername, $chatId);
        }
    }
}

// Checklist task completion update (business messages)
$checkMessage = $update['business_message'] ?? ($update['edited_business_message'] ?? $update['message']);
if (is_array($checkMessage) && isset($checkMessage['checklist_tasks_done'])) {
    $done = $checkMessage['checklist_tasks_done'] ?? [];
    $notDone = $checkMessage['checklist_tasks_not_done'] ?? [];
    $chatId = $checkMessage['chat']['id'] ?? '';
    $from = $checkMessage['from'] ?? [];
    $tgUserId = (string)($from['id'] ?? '');
    $tgUsername = (string)($from['username'] ?? '');
    $msgId = (string)($checkMessage['message_id'] ?? '');

    $teams = enlil_teams_all();
    $teamId = '';
    foreach ($teams as $team) {
        if ((string)$team['telegram_group'] === (string)$chatId) {
            $teamId = (string)$team['id'];
            break;
        }
    }

    $people = enlil_people_all();
    $personId = '';
    foreach ($people as $person) {
        if ($tgUserId !== '' && (string)$person['telegram_user_id'] === $tgUserId) {
            $personId = (string)$person['id'];
            $tgUsername = ltrim((string)$person['telegram_user'], '@');
            break;
        }
        if ($personId === '' && $tgUsername !== '' && strcasecmp(ltrim((string)$person['telegram_user'], '@'), $tgUsername) === 0) {
            $personId = (string)$person['id'];
            break;
        }
    }

    $event = [
        'created_at' => date('c'),
        'person_id' => $personId,
        'telegram_user' => $tgUsername,
        'telegram_user_id' => $tgUserId,
        'team_id' => $teamId,
        'chat_id' => (string)$chatId,
        'message_id' => $msgId,
        'done_ids' => is_array($done) ? implode(',', $done) : '',
        'not_done_ids' => is_array($notDone) ? implode(',', $notDone) : '',
    ];
    enlil_checklist_add($event);
    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(200);
echo 'OK';
