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
        $tasksByObjective = [];
        $objectiveNames = [];
        foreach ($projectsFull as $proj) {
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
                foreach ($mentioned as $task) {
                    if (!in_array($personId, $task['responsible_ids'] ?? [], true)) {
                        continue;
                    }
                    $objectiveId = (int)$objective['id'];
                    if (!isset($tasksByObjective[$objectiveId])) {
                        $tasksByObjective[$objectiveId] = [];
                        $objectiveNames[$objectiveId] = $objective['name'];
                    }
                    $tasksByObjective[$objectiveId][$task['id']] = $task;
                }
            }
        }

        if (!$tasksByObjective) {
            $failed++;
            $failDetails[] = 'No hay tareas pendientes para este usuario en los próximos 15 días.';
        } else {
            $userErrorAdded = false;
            foreach ($tasksByObjective as $objectiveId => $tasks) {
                $objectiveName = $objectiveNames[$objectiveId] ?? '';
                if ($objectiveName === '') {
                    continue;
                }
                $checkTasks = [];
                foreach ($tasks as $task) {
                    $dueText = '';
                    if (!empty($task['due_date'])) {
                        $ts = strtotime($task['due_date']);
                        if ($ts !== false) {
                            $dueText = date('d/m', $ts);
                        }
                    }
                    $suffix = $dueText !== '' ? ' (' . $dueText . ')' : '';
                    $checkTasks[] = [
                        'id' => (int)$task['id'],
                        'text' => $task['name'] . $suffix,
                    ];
                }
                if (!$checkTasks) {
                    continue;
                }
                $payload = [
                    'business_connection_id' => $botBusinessId,
                    'chat_id' => $chatId,
                    'checklist' => [
                        'title' => $objectiveName,
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
                        enlil_checklist_map_add((string)$chatId, $messageId, 0, (int)$objectiveId, $taskIds);
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
                            if (stripos($detail, 'messages must not be sent to self') !== false) {
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
