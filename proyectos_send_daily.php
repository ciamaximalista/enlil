<?php
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/business_connections.php';
require_once __DIR__ . '/includes/checklist_map.php';

function enlil_daily_status_path(): string {
    return __DIR__ . '/data/daily_send_status.json';
}

function enlil_daily_status_save(array $status): void {
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents(enlil_daily_status_path(), $json);
}

function enlil_format_date_es(string $date, array $monthsEs): string {
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    $day = (int)date('j', $ts);
    $month = (int)date('n', $ts);
    $year = date('Y', $ts);
    $monthName = $monthsEs[$month] ?? '';
    if ($monthName === '') {
        return $date;
    }
    return $day . ' de ' . $monthName . ' de ' . $year;
}

function enlil_escape_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function enlil_responsibles_text(array $ids, array $peopleById): string {
    $names = [];
    foreach ($ids as $rid) {
        $name = trim((string)($peopleById[(int)$rid] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[] = $name;
    }
    $names = array_values(array_unique($names));
    return $names ? implode(', ', $names) : 'Alguien';
}

function enlil_task_groups(array $tasks): array {
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
    $today = date('Y-m-d');
    $dates = [
        $today,
        date('Y-m-d', strtotime('+1 day')),
    ];
    // Si hoy es viernes, incluir también el lunes siguiente.
    if ((int)date('N') === 5) {
        $dates[] = date('Y-m-d', strtotime('+3 days'));
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

$monthsEs = [
    1 => 'enero',
    2 => 'febrero',
    3 => 'marzo',
    4 => 'abril',
    5 => 'mayo',
    6 => 'junio',
    7 => 'julio',
    8 => 'agosto',
    9 => 'septiembre',
    10 => 'octubre',
    11 => 'noviembre',
    12 => 'diciembre',
];

$token = enlil_bot_token();
if ($token === '') {
    enlil_daily_status_save([
        'ran_at' => gmdate('c'),
        'warnings' => [
            ['message' => 'Bot no configurado.'],
        ],
    ]);
    exit(1);
}

$projects = enlil_projects_all();
$teams = enlil_teams_all();
$teamsById = [];
foreach ($teams as $team) {
    $teamsById[$team['id']] = $team;
}
$people = enlil_people_all();
$peopleById = [];
$peopleInfoById = [];
foreach ($people as $person) {
    $user = (string)($person['telegram_user'] ?? '');
    if ($user !== '' && $user[0] !== '@') {
        $user = '@' . $user;
    }
    $peopleById[$person['id']] = $user !== '' ? $user : $person['name'];
    $peopleInfoById[$person['id']] = [
        'telegram_user' => $user !== '' ? $user : $person['name'],
        'telegram_user_id' => (string)($person['telegram_user_id'] ?? ''),
    ];
}

function enlil_objective_order(array $objectives): array {
    $byId = [];
    $deps = [];
    foreach ($objectives as $obj) {
        $id = (int)($obj['id'] ?? 0);
        if (!$id) {
            continue;
        }
        $byId[$id] = $obj;
        $deps[$id] = array_values(array_filter(array_map('intval', $obj['depends_on'] ?? [])));
    }
    $memo = [];
    $visiting = [];
    $levelOf = function (int $id) use (&$levelOf, &$deps, &$memo, &$visiting): int {
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        if (isset($visiting[$id])) {
            return 0;
        }
        $visiting[$id] = true;
        $level = 0;
        foreach ($deps[$id] ?? [] as $depId) {
            $level = max($level, $levelOf((int)$depId) + 1);
        }
        unset($visiting[$id]);
        $memo[$id] = $level;
        return $level;
    };
    $items = [];
    foreach ($byId as $id => $obj) {
        $items[] = [
            'level' => $levelOf($id),
            'objective' => $obj,
        ];
    }
    usort($items, function ($a, $b) {
        if ($a['level'] === $b['level']) {
            return ($a['objective']['id'] ?? 0) <=> ($b['objective']['id'] ?? 0);
        }
        return $a['level'] <=> $b['level'];
    });
    return array_map(function ($item) {
        return $item['objective'];
    }, $items);
}

$todayDate = date('Y-m-d');
$targetDates = enlil_checklist_target_dates();
$todayText = enlil_format_date_es(date('Y-m-d'), $monthsEs);

foreach ($projects as $project) {
    $projectFull = enlil_projects_get((int)$project['id']);
    if (!$projectFull) {
        continue;
    }

    $lines = [];
    $mentionedTasks = [];
    $lines[] = 'Hoy ' . enlil_escape_html($todayText) . ' en el proyecto <u><b>' . enlil_escape_html($projectFull['name']) . '</b></u>:';
    $hasGroupContent = false;

    $orderedObjectives = enlil_objective_order($projectFull['objectives'] ?? []);
    foreach ($orderedObjectives as $objective) {
        $tasks = $objective['tasks'] ?? [];
        if (!$tasks) {
            continue;
        }
        $open = [];
        $pending = [];
        $pendingById = [];
        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'done') {
                continue;
            }
            $due = (string)($task['due_date'] ?? '');
            if ($due === '') {
                continue;
            }
            $open[] = $task;
            if (!enlil_checklist_include_due_date($due, $targetDates, $todayDate)) {
                continue;
            }
            $pending[] = $task;
            $pendingById[(int)($task['id'] ?? 0)] = true;
        }
        if (!$pending) {
            continue;
        }

        $lines[] = '';
        $lines[] = '<b>' . enlil_escape_html($objective['name']) . '</b>:';
        $hasGroupContent = true;
        $objectiveId = (int)$objective['id'];

        $groups = enlil_task_groups($open);
        $children = $groups['children'];
        $chainRoots = [];
        foreach ($groups['columns'] as $rootId => $tasksColumn) {
            foreach ($tasksColumn as $task) {
                if (empty($task['depends_on']) && isset($pendingById[(int)($task['id'] ?? 0)])) {
                    $chainRoots[$rootId] = $task;
                    break;
                }
            }
        }
        $chainMeta = [];
        foreach ($chainRoots as $rootId => $rootTask) {
            $firstDue = '9999-12-31';
            foreach (($groups['columns'][$rootId] ?? []) as $colTask) {
                $due = (string)($colTask['due_date'] ?? '');
                if ($due !== '' && $due < $firstDue) {
                    $firstDue = $due;
                }
            }
            $chainMeta[] = [
                'root_id' => (int)$rootId,
                'root_task' => $rootTask,
                'first_due' => $firstDue,
            ];
        }
        usort($chainMeta, function ($a, $b) {
            if ($a['first_due'] === $b['first_due']) {
                return ((int)$a['root_id']) <=> ((int)$b['root_id']);
            }
            return strcmp((string)$a['first_due'], (string)$b['first_due']);
        });

        $hasChainLines = false;
        foreach ($chainMeta as $chain) {
            $rootTask = $chain['root_task'];
            $responsibles = $rootTask['responsible_ids'] ?? [];
            $responsiblesText = enlil_escape_html(enlil_responsibles_text($responsibles, $peopleById));
            $taskName = enlil_escape_html($rootTask['name'] ?? '');
            $taskDue = enlil_escape_html(enlil_format_date_es($rootTask['due_date'] ?? '', $monthsEs));
            $dependents = $children[(int)$rootTask['id']] ?? [];
            $dependentTask = null;
            if ($dependents) {
                foreach ($open as $t) {
                    if (in_array((int)$t['id'], $dependents, true)) {
                        if (!$dependentTask || ($t['due_date'] ?? '') < ($dependentTask['due_date'] ?? '9999-12-31')) {
                            $dependentTask = $t;
                        }
                    }
                }
            }
            if ($dependentTask) {
                $depDue = enlil_escape_html(enlil_format_date_es($dependentTask['due_date'] ?? '', $monthsEs));
                $depNamesText = enlil_escape_html(enlil_responsibles_text($dependentTask['responsible_ids'] ?? [], $peopleById));
                $lines[] = '- ' . $responsiblesText . ' tiene que ' . $taskName . ' antes del ' . $taskDue . ' para que antes del ' . $depDue . ', ' . $depNamesText . ' pueda ' . enlil_escape_html($dependentTask['name'] ?? '') . '.';
                $hasChainLines = true;
                $mentionedTasks[$objectiveId][$rootTask['id']] = $rootTask;
                $mentionedTasks[$objectiveId][$dependentTask['id']] = $dependentTask;
            } else {
                $lines[] = '- ' . $responsiblesText . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $hasChainLines = true;
                $mentionedTasks[$objectiveId][$rootTask['id']] = $rootTask;
            }
        }

        $independent = array_values(array_filter($groups['independent'], function ($task) use ($pendingById) {
            return isset($pendingById[(int)($task['id'] ?? 0)]);
        }));
        $addedExtrasHeading = false;
        if ($independent) {
            if ($hasChainLines) {
                $lines[] = 'Además:';
                $addedExtrasHeading = true;
            }
            foreach ($independent as $task) {
                $responsibles = $task['responsible_ids'] ?? [];
                $responsiblesText = enlil_escape_html(enlil_responsibles_text($responsibles, $peopleById));
                $taskName = enlil_escape_html($task['name'] ?? '');
                $taskDue = enlil_escape_html(enlil_format_date_es($task['due_date'] ?? '', $monthsEs));
                $lines[] = '- ' . $responsiblesText . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $mentionedTasks[$objectiveId][$task['id']] = $task;
            }
        }

        // Fallback: include any pending task that is not part of chain roots/independent.
        $remaining = [];
        foreach ($pending as $task) {
            $tid = (int)($task['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (!isset($mentionedTasks[$objectiveId][$tid])) {
                $remaining[] = $task;
            }
        }
        if ($remaining) {
            usort($remaining, 'enlil_compare_tasks_chrono');
            if (($hasChainLines || $independent) && !$addedExtrasHeading) {
                $lines[] = 'Además:';
            }
            foreach ($remaining as $task) {
                $responsibles = $task['responsible_ids'] ?? [];
                $responsiblesText = enlil_escape_html(enlil_responsibles_text($responsibles, $peopleById));
                $taskName = enlil_escape_html($task['name'] ?? '');
                $taskDue = enlil_escape_html(enlil_format_date_es($task['due_date'] ?? '', $monthsEs));
                $lines[] = '- ' . $responsiblesText . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $mentionedTasks[$objectiveId][$task['id']] = $task;
            }
        }
    }

    $lines[] = '';
    $lines[] = '¡Buen trabajo y tened cuidado ahí fuera!';

    if (!$hasGroupContent) {
        continue;
    }

    $message = implode("\n", $lines);

    $targets = [];
    foreach ($projectFull['team_ids'] as $teamId) {
        if (!isset($teamsById[$teamId])) {
            continue;
        }
        $groupId = trim((string)($teamsById[$teamId]['telegram_group'] ?? ''));
        if ($groupId !== '') {
            $targets[] = [
                'id' => (int)$teamId,
                'name' => $teamsById[$teamId]['name'],
                'group' => $groupId,
            ];
        }
    }

    foreach ($targets as $target) {
        $payload = [
            'chat_id' => $target['group'],
            'text' => $message,
            'parse_mode' => 'HTML',
        ];
        $result = enlil_telegram_post_json($token, 'sendMessage', $payload);
        if (!$result['ok']) {
            $migratedId = enlil_telegram_extract_migrate_chat_id($result);
            if ($migratedId !== '') {
                enlil_teams_update_group_id((int)$target['id'], $migratedId);
                $payload['chat_id'] = $migratedId;
                enlil_telegram_post_json($token, 'sendMessage', $payload);
            }
        }
    }

    {
        $botBusinessId = trim((string)enlil_bot_business_connection_id());
        $botOwnerId = trim((string)enlil_bot_business_owner_user_id());
        if ($botBusinessId === '') {
            continue;
        }
        $tasksByUser = [];
        $objectiveNames = [];
        foreach ($orderedObjectives as $obj) {
            $objectiveNames[(int)$obj['id']] = $obj['name'];
        }
        foreach ($mentionedTasks as $objectiveId => $tasks) {
            $objectiveLabel = $objectiveNames[(int)$objectiveId] ?? '';
            foreach ($tasks as $task) {
                foreach ($task['responsible_ids'] ?? [] as $personId) {
                    if (!isset($tasksByUser[$personId])) {
                        $tasksByUser[$personId] = [];
                    }
                    $taskWithObjective = $task;
                    $taskWithObjective['objective_id'] = (int)$objectiveId;
                    $tasksByUser[$personId][] = [
                        'task' => $taskWithObjective,
                        'objective' => $objectiveLabel,
                    ];
                }
            }
        }

        foreach ($tasksByUser as $personId => $userTasks) {
            $info = $peopleInfoById[$personId] ?? null;
            if (!$info || $info['telegram_user_id'] === '') {
                continue;
            }
            if ($botOwnerId !== '' && $info['telegram_user_id'] !== '' && $info['telegram_user_id'] === $botOwnerId) {
                continue;
            }
            $customer = enlil_customer_get($info['telegram_user_id']);
            $chatId = $customer['chat_id'] ?? '';
            if ($chatId === '') {
                continue;
            }
            $existingMessageIds = enlil_checklist_map_list((string)$chatId, (int)$projectFull['id']);
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
            usort($userTasks, function ($a, $b) {
                return enlil_compare_tasks_chrono($a['task'] ?? [], $b['task'] ?? []);
            });
            foreach ($userTasks as $entry) {
                $task = $entry['task'];
                $dueDate = (string)($task['due_date'] ?? '');
                if (!enlil_checklist_include_due_date($dueDate, $targetDates, $todayDate)) {
                    continue;
                }
                $objectiveLabel = $entry['objective'];
                $dueText = '';
                if (!empty($task['due_date'])) {
                    $ts = strtotime($task['due_date']);
                    if ($ts !== false) {
                        $dueText = date('d/m', $ts);
                    }
                }
                $suffix = $dueText !== '' ? ' (' . $dueText . ')' : '';
                $taskText = $task['name'] . $suffix;
                $checklistId = enlil_checklist_encode_task_id((int)$projectFull['id'], (int)($task['id'] ?? 0));
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
            if (!$checkTasks) {
                continue;
            }
            $payload = [
                'business_connection_id' => $botBusinessId,
                'chat_id' => $chatId,
                'checklist' => [
                    'title' => $projectFull['name'],
                    'others_can_mark_tasks_as_done' => true,
                    'others_can_add_tasks' => false,
                    'tasks' => $checkTasks,
                ],
            ];
            $result = enlil_telegram_post_json($token, 'sendChecklist', $payload);
            if (is_array($result) && !empty($result['ok'])) {
                $data = is_string($result['body']) ? json_decode($result['body'], true) : null;
                $messageId = '';
                if (is_array($data) && isset($data['result']['message_id'])) {
                    $messageId = (string)$data['result']['message_id'];
                }
                if ($messageId !== '') {
                    $taskIds = array_map(function ($t) {
                        return (int)$t['id'];
                    }, $checkTasks);
                    enlil_checklist_map_add((string)$chatId, $messageId, (int)$projectFull['id'], 0, $taskIds, $taskMeta);
                }
            } else {
                $detail = '';
                if (is_array($result) && is_string($result['body'] ?? '')) {
                    $err = json_decode((string)$result['body'], true);
                    if (is_array($err) && isset($err['description'])) {
                        $detail = (string)$err['description'];
                    }
                }
                if (stripos($detail, 'BUSINESS_PEER_USAGE_MISSING') !== false) {
                    $dailyWarnings[] = [
                        'type' => 'business_peer_usage_missing',
                        'project' => (string)($projectFull['name'] ?? ''),
                        'person' => (string)($info['name'] ?? $info['telegram_user'] ?? 'Usuario'),
                        'telegram_user' => (string)($info['telegram_user'] ?? ''),
                        'chat_id' => (string)$chatId,
                        'message' => 'No se pudo enviar checklist por Business. El usuario debe abrir chat privado con la cuenta Business que conecta el bot, activar conexión Business y enviar un mensaje de prueba.',
                    ];
                }
            }
        }
    }
}

enlil_daily_status_save([
    'ran_at' => gmdate('c'),
    'warnings' => $dailyWarnings,
]);

$dailyWarnings = [];
