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


function enlil_task_groups_person(array $tasks): array {
    $byId = [];
    $deps = [];
    $children = [];
    foreach ($tasks as $task) {
        $id = (int)$task['id'];
        $byId[$id] = $task;
        $deps[$id] = array_values(array_filter(array_map('intval', $task['depends_on'] ?? [])));
    }
    foreach ($deps as $id => $list) {
        foreach ($list as $depId) {
            $children[$depId][] = $id;
        }
    }
    $rootMemo = [];
    $visiting = [];
    $rootOf = function ($id) use (&$rootOf, &$deps, &$rootMemo, &$visiting): int {
        if (isset($rootMemo[$id])) {
            return $rootMemo[$id];
        }
        if (isset($visiting[$id])) {
            return $id;
        }
        $visiting[$id] = true;
        $list = $deps[$id] ?? [];
        if (!$list) {
            $rootMemo[$id] = $id;
            unset($visiting[$id]);
            return $id;
        }
        $roots = [];
        foreach ($list as $depId) {
            $roots[] = $rootOf($depId);
        }
        $root = $roots ? min($roots) : $id;
        $rootMemo[$id] = $root;
        unset($visiting[$id]);
        return $root;
    };

    $independent = [];
    $columns = [];
    foreach ($byId as $id => $task) {
        $hasDeps = !empty($deps[$id]);
        $hasChildren = !empty($children[$id]);
        if (!$hasDeps && !$hasChildren) {
            $independent[] = $task;
            continue;
        }
        $root = $rootOf($id);
        if (!isset($columns[$root])) {
            $columns[$root] = [];
        }
        $columns[$root][] = $task;
    }

    return [
        'columns' => $columns,
        'independent' => $independent,
        'children' => $children,
    ];
}

function enlil_compare_tasks_chrono(array $a, array $b): int {
    $da = (string)($a['due_date'] ?? '');
    $db = (string)($b['due_date'] ?? '');
    if ($da === $db) {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }
    if ($da === '') {
        return 1;
    }
    if ($db === '') {
        return -1;
    }
    return strcmp($da, $db);
}

function enlil_checklist_target_dates(): array {
    $today = new DateTimeImmutable('today');
    $dates = [
        $today->format('Y-m-d'),
        $today->modify('+1 day')->format('Y-m-d'),
    ];
    // If sending on Friday, include next Monday as well.
    if ((int)$today->format('N') === 5) {
        $dates[] = $today->modify('+3 day')->format('Y-m-d');
    }
    return array_values(array_unique($dates));
}

function enlil_checklist_include_due_date(string $dueDate, array $targetDates, string $today): bool {
    if ($dueDate === '') {
        return false;
    }
    if (in_array($dueDate, $targetDates, true)) {
        return true;
    }
    $dueTs = strtotime($dueDate);
    $todayTs = strtotime($today);
    if ($dueTs === false || $todayTs === false) {
        return false;
    }
    return $dueTs < $todayTs;
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
        $targetDates = enlil_checklist_target_dates();
        $todayDate = date('Y-m-d');
        $projects = enlil_projects_all();
        $projectsFull = [];
        foreach ($projects as $proj) {
            $full = enlil_projects_get((int)$proj['id']);
            if ($full) {
                $projectsFull[] = $full;
            }
        }
        $todayTs = strtotime(date('Y-m-d'));
        $limitTs = strtotime('+15 days', $todayTs);
        $tasksByProject = [];
        $objectiveNames = [];
        foreach ($projectsFull as $proj) {
            $projectId = (int)$proj['id'];
            foreach ($proj['objectives'] as $objective) {
                $tasks = $objective['tasks'] ?? [];
                if (!$tasks) {
                    continue;
                }
                $pending = [];
                foreach ($tasks as $task) {
                    if (($task['status'] ?? '') === 'done') {
                        continue;
                    }
                    $due = $task['due_date'] ?? '';
                    if ($due === '') {
                        continue;
                    }
                    $dueTs = strtotime($due);
                    if ($dueTs === false || $dueTs > $limitTs) {
                        continue;
                    }
                    $pending[] = $task;
                }
                if (!$pending) {
                    continue;
                }
                $groups = enlil_task_groups_person($pending);
                $children = $groups['children'];
                $chainRoots = [];
                foreach ($groups['columns'] as $rootId => $tasksColumn) {
                    foreach ($tasksColumn as $task) {
                        if (empty($task['depends_on'])) {
                            $chainRoots[$rootId] = $task;
                            break;
                        }
                    }
                }
                $mentioned = [];
                foreach ($chainRoots as $rootTask) {
                    $mentioned[$rootTask['id']] = $rootTask;
                    $dependents = $children[(int)$rootTask['id']] ?? [];
                    $dependentTask = null;
                    if ($dependents) {
                        foreach ($pending as $t) {
                            if (in_array((int)$t['id'], $dependents, true)) {
                                if (!$dependentTask || ($t['due_date'] ?? '') < ($dependentTask['due_date'] ?? '9999-12-31')) {
                                    $dependentTask = $t;
                                }
                            }
                        }
                    }
                    if ($dependentTask) {
                        $mentioned[$dependentTask['id']] = $dependentTask;
                    }
                }
                foreach ($groups['independent'] as $task) {
                    $mentioned[$task['id']] = $task;
                }
                // Fallback: include any pending task not captured by chain/independent logic.
                foreach ($pending as $task) {
                    $tid = (int)($task['id'] ?? 0);
                    if ($tid <= 0) {
                        continue;
                    }
                    if (!isset($mentioned[$tid])) {
                        $mentioned[$tid] = $task;
                    }
                }
                foreach ($mentioned as $task) {
                    if (!in_array($personId, $task['responsible_ids'] ?? [], true)) {
                        continue;
                    }
                    $objectiveId = (int)$objective['id'];
                    if (!isset($objectiveNames[$objectiveId])) {
                        $objectiveNames[$objectiveId] = $objective['name'];
                    }
                    if (!isset($tasksByProject[$projectId])) {
                        $tasksByProject[$projectId] = [
                            'name' => $proj['name'],
                            'tasks' => [],
                        ];
                    }
                    $taskWithObjective = $task;
                    $taskWithObjective['objective_id'] = $objectiveId;
                    $tasksByProject[$projectId]['tasks'][] = [
                        'task' => $taskWithObjective,
                        'objective' => $objectiveNames[$objectiveId] ?? '',
                    ];
                }
            }
        }

        if (!$tasksByProject) {
            $failed++;
            $failDetails[] = 'No hay tareas pendientes para este usuario.';
    } else {
        $userErrorAdded = false;
        foreach ($tasksByProject as $projectId => $projectData) {
            $projectName = $projectData['name'] ?? '';
            $tasks = $projectData['tasks'] ?? [];
            usort($tasks, function ($a, $b) {
                return enlil_compare_tasks_chrono($a['task'] ?? [], $b['task'] ?? []);
            });
            $existingMessageIds = enlil_checklist_map_list((string)$chatId, (int)$projectId);
            if ($existingMessageIds) {
                $deletePayload = [
                    'business_connection_id' => $botBusinessId,
                    'chat_id' => $chatId,
                    'message_ids' => array_values(array_map('intval', $existingMessageIds)),
                ];
                enlil_telegram_post_json($token, 'deleteBusinessMessages', $deletePayload);
                enlil_checklist_map_delete((string)$chatId, $existingMessageIds);
            }
            $checkTasks = [];
            $taskMeta = [];
            foreach ($tasks as $entry) {
                $task = $entry['task'];
                $dueDate = (string)($task['due_date'] ?? '');
                if (!enlil_checklist_include_due_date($dueDate, $targetDates, $todayDate)) {
                    continue;
                }
                $objectiveLabel = $entry['objective'] ?? '';
                    $dueText = '';
                    if (!empty($task['due_date'])) {
                        $ts = strtotime($task['due_date']);
                        if ($ts !== false) {
                            $dueText = date('d/m', $ts);
                        }
                    }
                    $suffix = $dueText !== '' ? ' (' . $dueText . ')' : '';
                    $taskText = $task['name'] . $suffix;
                    $checklistId = enlil_checklist_encode_task_id((int)$projectId, (int)($task['id'] ?? 0));
                    $checkTasks[] = [
                        'id' => $checklistId,
                        'text' => enlil_telegram_clip_checklist_text($taskText, 100),
                    ];
                    $taskMeta[$checklistId] = [
                        'task_id' => (int)($task['id'] ?? 0),
                        'objective_id' => (int)($task['objective_id'] ?? 0),
                        'name' => (string)($task['name'] ?? ''),
                    ];
                }
                if (!$checkTasks || $projectName === '') {
                    continue;
                }
                $payload = [
                    'business_connection_id' => $botBusinessId,
                    'chat_id' => $chatId,
                    'checklist' => [
                        'title' => $projectName,
                        'others_can_mark_tasks_as_done' => true,
                        'others_can_add_tasks' => false,
                        'tasks' => $checkTasks,
                    ],
                ];
                $result = enlil_telegram_post_json($token, 'sendChecklist', $payload);
                if ($result['ok']) {
                    $success++;
                    $data = is_string($result['body']) ? json_decode($result['body'], true) : null;
                    $messageId = '';
                    if (is_array($data) && isset($data['result']['message_id'])) {
                        $messageId = (string)$data['result']['message_id'];
                    }
                    if ($messageId !== '') {
                        $taskIds = array_map(function ($t) {
                            return (int)$t['id'];
                        }, $checkTasks);
                        enlil_checklist_map_add((string)$chatId, $messageId, (int)$projectId, 0, $taskIds, $taskMeta);
                    }
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
                    if (!$userErrorAdded) {
                        $extra = ' (conn=' . $botBusinessId . ', chat=' . $chatId . ')';
                        if ($detail !== '') {
                            if (stripos($detail, 'BUSINESS_PEER_USAGE_MISSING') !== false) {
                                $failDetails[] = 'No se puede enviar checklist por Business a este usuario ahora mismo. Debe abrir el chat privado con la cuenta Business que conecta el bot, activar la conexión Business y enviar un mensaje de prueba.' . $extra;
                            } elseif (stripos($detail, 'messages must not be sent to self') !== false) {
                                $failDetails[] = 'Telegram no permite enviar checklists al mismo usuario que conectó el bot Business. Esta función es solo para clientes, no para la propia cuenta Business.' . $extra;
                            } else {
                                $failDetails[] = 'Error al enviar checklist (' . $code . '): ' . $detail . '.' . $extra;
                            }
                        } else {
                            $failDetails[] = 'Error al enviar checklist (' . $code . ').' . $extra;
                        }
                        $userErrorAdded = true;
                    }
                }
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
