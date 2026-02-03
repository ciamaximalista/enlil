<?php
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/checklists.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/checklist_map.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/tokens.php';

function enlil_find_person_from_message(array $from): ?array {
    $people = enlil_people_all();
    $tgUserId = (string)($from['id'] ?? '');
    $tgUsername = (string)($from['username'] ?? '');
    foreach ($people as $p) {
        if ($tgUserId !== '' && (string)$p['telegram_user_id'] === $tgUserId) {
            return $p;
        }
    }
    if ($tgUsername !== '') {
        $needle = ltrim($tgUsername, '@');
        foreach ($people as $p) {
            if (strcasecmp(ltrim((string)$p['telegram_user'], '@'), $needle) === 0) {
                return $p;
            }
        }
    }
    return null;
}

function enlil_bot_command_keyboard(): array {
    return [
        'keyboard' => [
            ['/objetivos', '/mi_calendario'],
            ['/calendario_proyectos', '/24h'],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'selective' => true,
    ];
}

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
    $maxAge = 2 * 24 * 60 * 60;
    if (file_exists($logPath) && (time() - filemtime($logPath)) > $maxAge) {
        @file_put_contents($logPath, '');
    }
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
    $doneListIsState = false;
    if (is_array($done) && isset($done['marked_as_done_task_ids'])) {
        $doneList = $done['marked_as_done_task_ids'];
    } elseif (is_array($done) && isset($done['task_ids'])) {
        $doneList = $done['task_ids'];
        $doneListIsState = true;
    }
    $notDoneList = $notDone;
    if (is_array($notDone) && isset($notDone['marked_as_not_done_task_ids'])) {
        $notDoneList = $notDone['marked_as_not_done_task_ids'];
    } elseif (is_array($notDone) && isset($notDone['task_ids'])) {
        $notDoneList = $notDone['task_ids'];
    }
    if (is_array($done) && isset($done['marked_as_not_done_task_ids'])) {
        $notDoneList = $done['marked_as_not_done_task_ids'];
    }

    $doneIds = enlil_checklist_extract_ids($doneList);
    $notDoneIds = enlil_checklist_extract_ids($notDoneList);
    $doneStateIds = [];
    if ($doneListIsState) {
        $doneStateIds = $doneIds;
    }
    if (empty($doneStateIds) && isset($checkMessage['checklist']['tasks']) && is_array($checkMessage['checklist']['tasks'])) {
        foreach ($checkMessage['checklist']['tasks'] as $task) {
            if (!is_array($task)) {
                continue;
            }
            $tid = $task['id'] ?? ($task['task_id'] ?? ($task['taskId'] ?? ''));
            if (!ctype_digit((string)$tid)) {
                continue;
            }
            $isDone = $task['is_done'] ?? ($task['is_completed'] ?? ($task['completed'] ?? ($task['done'] ?? null)));
            if ($isDone === true || $isDone === 1 || $isDone === 'true') {
                $doneStateIds[] = (int)$tid;
            }
        }
        $doneStateIds = array_values(array_unique($doneStateIds));
    }
    if (!$notDoneIds && $doneStateIds && $chatId !== '' && $msgId !== '') {
        $prevDone = enlil_checklist_last_done_state((string)$chatId, (string)$msgId);
        if ($prevDone) {
            $diff = array_values(array_diff($prevDone, $doneStateIds));
            if ($diff) {
                $notDoneIds = $diff;
            }
        }
    }
    $eventCreatedAt = date('c');
    if (isset($checkMessage['date']) && is_numeric($checkMessage['date'])) {
        $eventCreatedAt = date('c', (int)$checkMessage['date']);
    }
    $event = [
        'created_at' => $eventCreatedAt,
        'person_id' => $personId,
        'telegram_user' => $tgUsername,
        'telegram_user_id' => $tgUserId,
        'team_id' => $teamId,
        'chat_id' => (string)$chatId,
        'message_id' => $msgId,
        'done_ids' => $doneIds ? implode(',', $doneIds) : '',
        'not_done_ids' => $notDoneIds ? implode(',', $notDoneIds) : '',
        'done_state_ids' => $doneStateIds ? implode(',', $doneStateIds) : '',
    ];

    $map = enlil_checklist_map_get((string)$chatId, $msgId);
    $mapMissing = !($map && !empty($map['task_ids']));

    $decodedUsed = false;
    foreach ($doneIds as $doneId) {
        $doneId = (int)$doneId;
        if ($doneId === 0) {
            continue;
        }
        [$decodedProjectId, $decodedTaskId] = enlil_checklist_decode_task_id($doneId);
        if ($decodedProjectId > 0 && $decodedTaskId > 0) {
            $decodedUsed = true;
            $updated = enlil_projects_mark_task_by_id_in_project($decodedProjectId, $decodedTaskId, 'done', date('c'));
            if ($updated === 0) {
                enlil_projects_mark_task_by_id_for_person($decodedTaskId, (int)$personId, 'done', date('c'));
            }
        }
    }
    foreach ($notDoneIds as $notDoneId) {
        $notDoneId = (int)$notDoneId;
        if ($notDoneId === 0) {
            continue;
        }
        [$decodedProjectId, $decodedTaskId] = enlil_checklist_decode_task_id($notDoneId);
        if ($decodedProjectId > 0 && $decodedTaskId > 0) {
            $decodedUsed = true;
            $updated = enlil_projects_mark_task_by_id_in_project($decodedProjectId, $decodedTaskId, 'pending', '');
            if ($updated === 0) {
                enlil_projects_mark_task_by_id_for_person($decodedTaskId, (int)$personId, 'pending', '');
            }
        }
    }

    if (!$decodedUsed) {
        if ($mapMissing) {
            $event['map_missing'] = '1';
            enlil_checklist_add($event);
            http_response_code(200);
            echo 'OK';
            exit;
        }

        enlil_checklist_add($event);

        if ($map && !empty($map['task_ids'])) {
            $mapObjectiveId = (int)($map['objective_id'] ?? 0);
            $taskMeta = isset($map['task_meta']) && is_array($map['task_meta']) ? $map['task_meta'] : [];
            foreach ($doneIds as $doneId) {
                $doneId = (int)$doneId;
                if ($doneId === 0) {
                    continue;
                }
                if (!in_array($doneId, $map['task_ids'], true)) {
                    continue;
                }
                $meta = $taskMeta[$doneId] ?? null;
                if ($meta && (int)($meta['task_id'] ?? 0) > 0) {
                    $realTaskId = (int)$meta['task_id'];
                    $realObjectiveId = (int)($meta['objective_id'] ?? 0);
                    if ($realObjectiveId > 0) {
                        enlil_projects_mark_task_done((int)$map['project_id'], $realObjectiveId, $realTaskId, date('c'));
                    } else {
                        enlil_projects_mark_task_by_id_in_project((int)$map['project_id'], $realTaskId, 'done', date('c'));
                    }
                } elseif ($mapObjectiveId > 0) {
                    enlil_projects_mark_task_done((int)$map['project_id'], $mapObjectiveId, $doneId, date('c'));
                } else {
                    enlil_projects_mark_task_by_id_in_project((int)$map['project_id'], $doneId, 'done', date('c'));
                }
            }
            foreach ($notDoneIds as $notDoneId) {
                $notDoneId = (int)$notDoneId;
                if ($notDoneId === 0) {
                    continue;
                }
                if (!in_array($notDoneId, $map['task_ids'], true)) {
                    continue;
                }
                $meta = $taskMeta[$notDoneId] ?? null;
                if ($meta && (int)($meta['task_id'] ?? 0) > 0) {
                    $realTaskId = (int)$meta['task_id'];
                    $realObjectiveId = (int)($meta['objective_id'] ?? 0);
                    if ($realObjectiveId > 0) {
                        enlil_projects_mark_task_pending((int)$map['project_id'], $realObjectiveId, $realTaskId);
                    } else {
                        enlil_projects_mark_task_by_id_in_project((int)$map['project_id'], $realTaskId, 'pending', '');
                    }
                } elseif ($mapObjectiveId > 0) {
                    enlil_projects_mark_task_pending((int)$map['project_id'], $mapObjectiveId, $notDoneId);
                } else {
                    enlil_projects_mark_task_by_id_in_project((int)$map['project_id'], $notDoneId, 'pending', '');
                }
            }
        }
    } else {
        enlil_checklist_add($event);
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

// Bot commands in private chats
$message = $update['message'] ?? null;
if (is_array($message)) {
    $text = trim((string)($message['text'] ?? ''));
    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];
    $chatId = (string)($chat['id'] ?? '');
    $tgUserId = (string)($from['id'] ?? '');
    if ($chatId !== '' && isset($chat['type']) && $chat['type'] === 'private' && $text !== '') {
        $cmd = strtolower(strtok($text, " \n\r\t"));
        if ($cmd === '/start' || $cmd === '/menu' || $cmd === '/help') {
            $person = enlil_find_person_from_message($from);
            if ($person) {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => "Hola, aquí tienes los comandos disponibles:\n/objetivos\n/mi_calendario\n/calendario_proyectos\n/24h",
                    'reply_markup' => enlil_bot_command_keyboard(),
                ];
                enlil_telegram_post_json($token, 'sendMessage', $payload);
            }
            http_response_code(200);
            echo 'OK';
            exit;
        }

        if (in_array($cmd, ['/objetivos', '/mi_calendario', '/calendario_proyectos', '/24h'], true)) {
            $person = enlil_find_person_from_message($from);
            if (!$person) {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => "No te encuentro en Enlil. Pide al administrador que te añada primero.",
                ];
                enlil_telegram_post_json($token, 'sendMessage', $payload);
                http_response_code(200);
                echo 'OK';
                exit;
            }
            $baseHost = $_SERVER['HTTP_HOST'] ?? 'maximalista.org';
            $baseUrl = 'https://' . $baseHost;
            if ($cmd === '/objetivos') {
                $tokenValue = enlil_token_create((int)$person['id'], 'objetivos');
                $url = $baseUrl . '/public_objetivos.php?token=' . rawurlencode($tokenValue);
                $textReply = "Aquí tienes los mapas de objetivos:\n" . $url . "\n\nEl enlace dura 10 minutos.";
            } elseif ($cmd === '/mi_calendario') {
                $tokenValue = enlil_token_create((int)$person['id'], 'mi_calendario');
                $url = $baseUrl . '/public_mi_calendario.php?token=' . rawurlencode($tokenValue);
                $textReply = "Aquí tienes tu calendario:\n" . $url . "\n\nEl enlace dura 10 minutos.";
            } elseif ($cmd === '/calendario_proyectos') {
                $tokenValue = enlil_token_create((int)$person['id'], 'calendario_proyectos');
                $url = $baseUrl . '/public_calendario_proyectos.php?token=' . rawurlencode($tokenValue);
                $textReply = "Aquí tienes los calendarios de tus proyectos:\n" . $url . "\n\nEl enlace dura 10 minutos.";
            } else {
                $tokenValue = enlil_token_create((int)$person['id'], 'tareas_24h');
                $url = $baseUrl . '/public_24h.php?token=' . rawurlencode($tokenValue);
                $textReply = "Aquí tienes las tareas cumplidas en las últimas 24 horas:\n" . $url . "\n\nEl enlace dura 10 minutos.";
            }
            $payload = [
                'chat_id' => $chatId,
                'text' => $textReply,
            ];
            enlil_telegram_post_json($token, 'sendMessage', $payload);
            http_response_code(200);
            echo 'OK';
            exit;
        }
    }
}

http_response_code(200);
echo 'OK';
