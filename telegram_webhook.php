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
require_once __DIR__ . '/includes/tasks_prompts.php';
require_once __DIR__ . '/includes/tasks_delivery.php';

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
            ['/tareas'],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'selective' => true,
    ];
}

function enlil_normalize_user_text(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    $text = str_replace(
        ['á', 'à', 'ä', 'â', 'ã', 'é', 'è', 'ë', 'ê', 'í', 'ì', 'ï', 'î', 'ó', 'ò', 'ö', 'ô', 'õ', 'ú', 'ù', 'ü', 'û'],
        ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'],
        $text
    );
    return trim($text);
}

function enlil_is_affirmative_reply(string $text): bool {
    $norm = enlil_normalize_user_text($text);
    if ($norm === '') {
        return false;
    }
    return in_array($norm, ['s', 'si'], true);
}

function enlil_send_current_tasks_to_person(string $token, array $person, string $chatId, string $businessConnectionId = ''): array {
    $personId = (int)($person['id'] ?? 0);
    if ($personId <= 0 || $chatId === '') {
        return [
            'ok' => false,
            'message' => 'No te encuentro en Enlil o falta chat privado.',
            'sent' => 0,
            'failed' => 1,
        ];
    }
    $checklists = enlil_build_person_checklists($personId, 15, true);
    if (!$checklists) {
        $cached = enlil_tasks_last_sent_get_today($chatId);
        if (is_array($cached) && $cached) {
            $bundle = [
                'chat_id' => $chatId,
                'business_connection_id' => $businessConnectionId,
                'checklists' => $cached,
            ];
            $result = enlil_send_checklists_bundle($token, $bundle);
            $ok = (int)($result['ok'] ?? 0);
            $failed = (int)($result['failed'] ?? 0);
            if ($ok > 0 && $failed === 0) {
                return [
                    'ok' => true,
                    'message' => '',
                    'sent' => $ok,
                    'failed' => 0,
                ];
            }
            $parts = [];
            foreach (($result['errors'] ?? []) as $err) {
                $desc = trim((string)($err['description'] ?? ''));
                if ($desc !== '') {
                    $parts[] = $desc;
                }
            }
            $msg = 'No se pudieron reenviar todas las listas' . ($parts ? (': ' . implode(' | ', $parts)) : '.');
            enlil_send_text_optional_business($token, $chatId, $msg, $businessConnectionId);
            return [
                'ok' => false,
                'message' => $msg,
                'sent' => $ok,
                'failed' => max(1, $failed),
            ];
        }
        return [
            'ok' => true,
            'message' => '',
            'sent' => 0,
            'failed' => 0,
        ];
    }
    $bundle = [
        'chat_id' => $chatId,
        'business_connection_id' => $businessConnectionId,
        'checklists' => $checklists,
    ];
    $result = enlil_send_checklists_bundle($token, $bundle);
    $ok = (int)($result['ok'] ?? 0);
    $failed = (int)($result['failed'] ?? 0);
    if ($ok > 0 && $failed === 0) {
        return [
            'ok' => true,
            'message' => '',
            'sent' => $ok,
            'failed' => 0,
        ];
    }
    $parts = [];
    foreach (($result['errors'] ?? []) as $err) {
        $desc = trim((string)($err['description'] ?? ''));
        if ($desc !== '') {
            $parts[] = $desc;
        }
    }
    $msg = 'No se pudieron enviar todas las listas' . ($parts ? (': ' . implode(' | ', $parts)) : '.');
    enlil_send_text_optional_business($token, $chatId, $msg, $businessConnectionId);
    return [
        'ok' => false,
        'message' => $msg,
        'sent' => $ok,
        'failed' => max(1, $failed),
    ];
}

function enlil_send_stored_prompt_checklists(string $token, array $prompt, string $chatId, string $businessConnectionId = ''): array {
    $checklists = $prompt['checklists'] ?? [];
    if (!is_array($checklists) || !$checklists || $chatId === '') {
        return [
            'ok' => true,
            'message' => '',
            'sent' => 0,
            'failed' => 0,
        ];
    }
    $bundle = [
        'chat_id' => $chatId,
        'business_connection_id' => $businessConnectionId,
        'checklists' => $checklists,
    ];
    $result = enlil_send_checklists_bundle($token, $bundle);
    $ok = (int)($result['ok'] ?? 0);
    $failed = (int)($result['failed'] ?? 0);
    if ($ok > 0 && $failed === 0) {
        return [
            'ok' => true,
            'message' => '',
            'sent' => $ok,
            'failed' => 0,
        ];
    }
    return [
        'ok' => false,
        'message' => 'No se pudieron enviar las listas guardadas.',
        'sent' => $ok,
        'failed' => max(1, $failed),
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

function enlil_person_label(array $person): string {
    $user = trim((string)($person['telegram_user'] ?? ''));
    if ($user !== '') {
        return '@' . ltrim($user, '@');
    }
    return (string)($person['name'] ?? 'Alguien');
}

function enlil_actor_label(array $actorPerson, string $fallbackUsername): string {
    if (!empty($actorPerson)) {
        return enlil_person_label($actorPerson);
    }
    $u = trim($fallbackUsername);
    if ($u !== '') {
        return '@' . ltrim($u, '@');
    }
    return 'Alguien';
}

function enlil_task_due_ts(array $task): int {
    $due = (string)($task['due_date'] ?? '');
    if ($due === '') {
        return PHP_INT_MAX;
    }
    $ts = strtotime($due);
    return $ts === false ? PHP_INT_MAX : $ts;
}

function enlil_find_next_dependent_task(int $projectId, int $taskId): ?array {
    if ($projectId <= 0 || $taskId <= 0) {
        return null;
    }
    $project = enlil_projects_get($projectId);
    if (!$project || empty($project['objectives'])) {
        return null;
    }
    foreach ($project['objectives'] as $objective) {
        $tasks = $objective['tasks'] ?? [];
        if (!$tasks) {
            continue;
        }
        $hasCurrent = false;
        foreach ($tasks as $task) {
            if ((int)($task['id'] ?? 0) === $taskId) {
                $hasCurrent = true;
                break;
            }
        }
        if (!$hasCurrent) {
            continue;
        }
        $candidates = [];
        foreach ($tasks as $task) {
            $depends = $task['depends_on'] ?? [];
            if (!is_array($depends) || !in_array($taskId, array_map('intval', $depends), true)) {
                continue;
            }
            if (($task['status'] ?? '') === 'done') {
                continue;
            }
            $candidates[] = $task;
        }
        if (!$candidates) {
            return null;
        }
        usort($candidates, function ($a, $b) {
            $ta = enlil_task_due_ts($a);
            $tb = enlil_task_due_ts($b);
            if ($ta === $tb) {
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });
        return $candidates[0];
    }
    return null;
}

function enlil_find_task_name_in_project(int $projectId, int $taskId): string {
    if ($projectId <= 0 || $taskId <= 0) {
        return '';
    }
    $project = enlil_projects_get($projectId);
    if (!$project || empty($project['objectives'])) {
        return '';
    }
    foreach ($project['objectives'] as $objective) {
        $tasks = $objective['tasks'] ?? [];
        foreach ($tasks as $task) {
            if ((int)($task['id'] ?? 0) === $taskId) {
                return trim((string)($task['name'] ?? ''));
            }
        }
    }
    return '';
}

function enlil_notify_dependency_log(array $data): void {
    $logDir = __DIR__ . '/data';
    if (!is_dir($logDir)) {
        return;
    }
    $line = [
        'ts' => date('c'),
        'event' => 'dependency_notify',
    ];
    foreach ($data as $k => $v) {
        $line[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
    }
    @file_put_contents($logDir . '/dependency_notify.log', json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function enlil_notify_next_responsible(
    string $token,
    int $projectId,
    int $doneTaskId,
    string $doneTaskName,
    array $actorPerson,
    int $actorPersonId,
    string $fallbackUsername
): void {
    if ($projectId <= 0 || $doneTaskId <= 0) {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'invalid_ids',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
        ]);
        return;
    }
    $doneTaskName = trim($doneTaskName);
    if ($doneTaskName === '') {
        $doneTaskName = enlil_find_task_name_in_project($projectId, $doneTaskId);
    }
    if ($doneTaskName === '') {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'done_task_name_missing',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
        ]);
        return;
    }
    $nextTask = enlil_find_next_dependent_task($projectId, $doneTaskId);
    if (!$nextTask) {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'no_next_task',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
            'done_task' => $doneTaskName,
        ]);
        return;
    }
    $nextTaskName = trim((string)($nextTask['name'] ?? ''));
    if ($nextTaskName === '') {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'next_task_name_missing',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
            'done_task' => $doneTaskName,
        ]);
        return;
    }
    $responsibleIds = array_map('intval', (array)($nextTask['responsible_ids'] ?? []));
    if (!$responsibleIds) {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'next_task_without_responsibles',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
            'next_task_id' => (int)($nextTask['id'] ?? 0),
            'next_task' => $nextTaskName,
        ]);
        return;
    }
    $people = enlil_people_all();
    $peopleById = [];
    foreach ($people as $p) {
        $peopleById[(int)$p['id']] = $p;
    }

    $actorLabel = enlil_actor_label($actorPerson, $fallbackUsername);
    $botBusinessId = trim((string)enlil_bot_business_connection_id());
    if ($botBusinessId === '') {
        enlil_notify_dependency_log([
            'status' => 'skip',
            'reason' => 'missing_bot_business_connection',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
            'done_task' => $doneTaskName,
            'next_task' => $nextTaskName,
        ]);
        return;
    }

    foreach ($responsibleIds as $rid) {
        if ($rid <= 0 || $rid === $actorPersonId || !isset($peopleById[$rid])) {
            continue;
        }
        $targetPerson = $peopleById[$rid];
        $targetTgId = trim((string)($targetPerson['telegram_user_id'] ?? ''));
        if ($targetTgId === '') {
            enlil_notify_dependency_log([
                'status' => 'skip_recipient',
                'reason' => 'recipient_without_telegram_user_id',
                'project_id' => $projectId,
                'done_task_id' => $doneTaskId,
                'recipient_person_id' => $rid,
            ]);
            continue;
        }
        $chatId = '';
        $customer = enlil_customer_get($targetTgId);
        if ($customer && !empty($customer['chat_id'])) {
            $chatId = (string)$customer['chat_id'];
        }
        if ($chatId === '') {
            $biz = enlil_business_get($targetTgId);
            if ($biz && !empty($biz['user_chat_id'])) {
                $chatId = (string)$biz['user_chat_id'];
            }
        }
        if ($chatId === '') {
            enlil_notify_dependency_log([
                'status' => 'skip_recipient',
                'reason' => 'recipient_without_private_chat',
                'project_id' => $projectId,
                'done_task_id' => $doneTaskId,
                'recipient_person_id' => $rid,
                'recipient_telegram_user_id' => $targetTgId,
            ]);
            continue;
        }
        $payload = [
            'business_connection_id' => $botBusinessId,
            'chat_id' => $chatId,
            'text' => $actorLabel . ' acaba de ' . $doneTaskName . ' como tu necesitabas para poder ' . $nextTaskName . '.',
        ];
        $result = enlil_telegram_post_json($token, 'sendMessage', $payload);
        $errorDescription = '';
        if (!$result['ok'] && is_string($result['body']) && $result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            if (is_array($decoded) && isset($decoded['description'])) {
                $errorDescription = (string)$decoded['description'];
            }
        }
        enlil_notify_dependency_log([
            'status' => $result['ok'] ? 'sent' : 'failed',
            'project_id' => $projectId,
            'done_task_id' => $doneTaskId,
            'done_task' => $doneTaskName,
            'next_task_id' => (int)($nextTask['id'] ?? 0),
            'next_task' => $nextTaskName,
            'actor' => $actorLabel,
            'recipient_person_id' => $rid,
            'recipient' => enlil_person_label($targetPerson),
            'chat_id' => $chatId,
            'http_code' => (int)($result['http_code'] ?? 0),
            'error' => $errorDescription,
        ]);
    }
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
    $actorPerson = [];
    foreach ($people as $person) {
        if ($tgUserId !== '' && (string)$person['telegram_user_id'] === $tgUserId) {
            $personId = (string)$person['id'];
            $actorPerson = $person;
            $tgUsername = ltrim((string)$person['telegram_user'], '@');
            break;
        }
        if ($personId === '' && $tgUsername !== '' && strcasecmp(ltrim((string)$person['telegram_user'], '@'), $tgUsername) === 0) {
            $personId = (string)$person['id'];
            $actorPerson = $person;
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
            enlil_notify_next_responsible(
                $token,
                $decodedProjectId,
                $decodedTaskId,
                (string)($map['task_meta'][$doneId]['name'] ?? ''),
                $actorPerson,
                (int)$personId,
                (string)$tgUsername
            );
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
                    enlil_notify_next_responsible(
                        $token,
                        (int)$map['project_id'],
                        $realTaskId,
                        (string)($meta['name'] ?? ''),
                        $actorPerson,
                        (int)$personId,
                        (string)$tgUsername
                    );
                } elseif ($mapObjectiveId > 0) {
                    enlil_projects_mark_task_done((int)$map['project_id'], $mapObjectiveId, $doneId, date('c'));
                    enlil_notify_next_responsible(
                        $token,
                        (int)$map['project_id'],
                        $doneId,
                        '',
                        $actorPerson,
                        (int)$personId,
                        (string)$tgUsername
                    );
                } else {
                    enlil_projects_mark_task_by_id_in_project((int)$map['project_id'], $doneId, 'done', date('c'));
                    enlil_notify_next_responsible(
                        $token,
                        (int)$map['project_id'],
                        $doneId,
                        '',
                        $actorPerson,
                        (int)$personId,
                        (string)$tgUsername
                    );
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

// Bot commands and prompt replies in private chats (normal and business updates)
$inboundTextMessage = null;
if (isset($update['message']) && is_array($update['message'])) {
    $candidate = $update['message'];
    if (($candidate['chat']['type'] ?? '') === 'private' && trim((string)($candidate['text'] ?? '')) !== '') {
        $inboundTextMessage = $candidate;
    }
}
if ($inboundTextMessage === null) {
    $candidate = $update['business_message'] ?? ($update['edited_business_message'] ?? null);
    if (is_array($candidate) && ($candidate['chat']['type'] ?? '') === 'private' && trim((string)($candidate['text'] ?? '')) !== '') {
        $inboundTextMessage = $candidate;
    }
}

if (is_array($inboundTextMessage)) {
    $text = trim((string)($inboundTextMessage['text'] ?? ''));
    $chat = $inboundTextMessage['chat'] ?? [];
    $from = $inboundTextMessage['from'] ?? [];
    $chatId = (string)($chat['id'] ?? '');
    if ($chatId !== '' && $text !== '') {
        $cmd = strtolower(strtok($text, " \n\r\t"));
        if ($cmd === '/start' || $cmd === '/menu' || $cmd === '/help') {
            $person = enlil_find_person_from_message($from);
            if ($person) {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => "Hola, aquí tienes los comandos disponibles:\n/objetivos\n/mi_calendario\n/calendario_proyectos\n/24h\n/tareas",
                    'reply_markup' => enlil_bot_command_keyboard(),
                ];
                enlil_telegram_post_json($token, 'sendMessage', $payload);
            }
            http_response_code(200);
            echo 'OK';
            exit;
        }

        if (in_array($cmd, ['/objetivos', '/mi_calendario', '/calendario_proyectos', '/24h', '/tareas'], true)) {
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
            $prompt = enlil_tasks_prompt_get($chatId);
            $promptBusinessId = is_array($prompt) ? (string)($prompt['business_connection_id'] ?? '') : '';
            if ($promptBusinessId === '') {
                $promptBusinessId = trim((string)enlil_bot_business_connection_id());
            }

            $baseHost = $_SERVER['HTTP_HOST'] ?? 'maximalista.org';
            $baseUrl = 'https://' . $baseHost;
            if ($cmd === '/tareas') {
                $sendResult = enlil_send_current_tasks_to_person($token, $person, $chatId, $promptBusinessId);
                if ((int)($sendResult['sent'] ?? 0) === 0 && (int)($sendResult['failed'] ?? 0) === 0 && is_array($prompt)) {
                    enlil_send_stored_prompt_checklists($token, $prompt, $chatId, $promptBusinessId);
                }
                enlil_tasks_prompt_clear($chatId);
                http_response_code(200);
                echo 'OK';
                exit;
            } elseif ($cmd === '/objetivos') {
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

        // Reply to queued daily/manual prompt.
        $prompt = enlil_tasks_prompt_get($chatId);
        if (is_array($prompt)) {
            $person = enlil_find_person_from_message($from);
            $promptBusinessId = (string)($prompt['business_connection_id'] ?? '');
            if ($promptBusinessId === '') {
                $promptBusinessId = trim((string)enlil_bot_business_connection_id());
            }
            if (enlil_is_affirmative_reply($text)) {
                if ($person) {
                    $sendResult = enlil_send_current_tasks_to_person($token, $person, $chatId, $promptBusinessId);
                    if ((int)($sendResult['sent'] ?? 0) === 0 && (int)($sendResult['failed'] ?? 0) === 0) {
                        enlil_send_stored_prompt_checklists($token, $prompt, $chatId, $promptBusinessId);
                    }
                } else {
                    $personId = (int)($prompt['person_id'] ?? 0);
                    $fallbackPerson = null;
                    if ($personId > 0) {
                        foreach (enlil_people_all() as $p) {
                            if ((int)($p['id'] ?? 0) === $personId) {
                                $fallbackPerson = $p;
                                break;
                            }
                        }
                    }
                    if (is_array($fallbackPerson)) {
                        $sendResult = enlil_send_current_tasks_to_person($token, $fallbackPerson, $chatId, $promptBusinessId);
                        if ((int)($sendResult['sent'] ?? 0) === 0 && (int)($sendResult['failed'] ?? 0) === 0) {
                            enlil_send_stored_prompt_checklists($token, $prompt, $chatId, $promptBusinessId);
                        }
                    } else {
                        enlil_send_stored_prompt_checklists($token, $prompt, $chatId, $promptBusinessId);
                    }
                }
            } else {
                enlil_send_text_optional_business(
                    $token,
                    $chatId,
                    'Cuando quieras recibir las tareas de hoy basta con que me envíes el comando /tareas',
                    $promptBusinessId
                );
            }
            enlil_tasks_prompt_clear($chatId);
            http_response_code(200);
            echo 'OK';
            exit;
        }
    }
}

http_response_code(200);
echo 'OK';
