<?php
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/checklists.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/checklist_map.php';
require_once __DIR__ . '/includes/projects.php';

function enlil_checklist_extract_ids($items): array {
    $ids = [];
    if (!is_array($items)) {
        return $ids;
    }
    foreach ($items as $item) {
        if (is_int($item) || ctype_digit((string)$item)) {
            $ids[] = (string)$item;
            continue;
        }
        if (is_array($item)) {
            if (isset($item['id']) && ctype_digit((string)$item['id'])) {
                $ids[] = (string)$item['id'];
                continue;
            }
            if (isset($item['task_id']) && ctype_digit((string)$item['task_id'])) {
                $ids[] = (string)$item['task_id'];
                continue;
            }
        }
    }
    return array_values(array_unique($ids));
}

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

    $doneList = $done;
    if (is_array($done) && isset($done['marked_as_done_task_ids'])) {
        $doneList = $done['marked_as_done_task_ids'];
    } elseif (is_array($done) && isset($done['task_ids'])) {
        $doneList = $done['task_ids'];
    }
    $notDoneList = $notDone;
    if (is_array($notDone) && isset($notDone['marked_as_not_done_task_ids'])) {
        $notDoneList = $notDone['marked_as_not_done_task_ids'];
    } elseif (is_array($notDone) && isset($notDone['task_ids'])) {
        $notDoneList = $notDone['task_ids'];
    }

    $doneIds = enlil_checklist_extract_ids($doneList);
    $notDoneIds = enlil_checklist_extract_ids($notDoneList);
    $event = [
        'created_at' => date('c'),
        'person_id' => $personId,
        'telegram_user' => $tgUsername,
        'telegram_user_id' => $tgUserId,
        'team_id' => $teamId,
        'chat_id' => (string)$chatId,
        'message_id' => $msgId,
        'done_ids' => $doneIds ? implode(',', $doneIds) : '',
        'not_done_ids' => $notDoneIds ? implode(',', $notDoneIds) : '',
    ];
    enlil_checklist_add($event);

    $map = enlil_checklist_map_get((string)$chatId, $msgId);
    if ($map && !empty($map['task_ids'])) {
        foreach ($doneIds as $doneId) {
            $doneId = (int)$doneId;
            if ($doneId === 0) {
                continue;
            }
            if (!in_array($doneId, $map['task_ids'], true)) {
                continue;
            }
            enlil_projects_mark_task_done((int)$map['project_id'], (int)$map['objective_id'], $doneId, date('c'));
        }
    } else {
        foreach ($doneIds as $doneId) {
            $doneId = (int)$doneId;
            if ($doneId === 0) {
                continue;
            }
            enlil_projects_mark_task_done(0, 0, $doneId, date('c'));
        }
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(200);
echo 'OK';
