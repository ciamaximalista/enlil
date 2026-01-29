<?php
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/checklist_map.php';

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

$todayTs = strtotime(date('Y-m-d'));
$limitTs = strtotime('+15 days', $todayTs);
$todayText = enlil_format_date_es(date('Y-m-d'), $monthsEs);

foreach ($projects as $project) {
    $projectFull = enlil_projects_get((int)$project['id']);
    if (!$projectFull) {
        continue;
    }

    $lines = [];
    $mentionedTasks = [];
    $lines[] = 'Hoy ' . enlil_escape_html($todayText) . ' en el proyecto <u><b>' . enlil_escape_html($projectFull['name']) . '</b></u>:';
    $overdueLines = [];

    foreach ($projectFull['objectives'] as $objective) {
        $tasks = $objective['tasks'] ?? [];
        if (!$tasks) {
            continue;
        }
        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'done') {
                continue;
            }
            $due = $task['due_date'] ?? '';
            if ($due === '') {
                continue;
            }
            $dueTs = strtotime($due);
            if ($dueTs !== false && $dueTs < $todayTs) {
                $responsibles = $task['responsible_ids'] ?? [];
                $mainResponsible = $responsibles ? ($peopleById[$responsibles[0]] ?? 'Alguien') : 'Alguien';
                $taskName = enlil_escape_html($task['name'] ?? '');
                $taskDue = enlil_escape_html(enlil_format_date_es($due, $monthsEs));
                $overdueLines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' (venció el ' . $taskDue . ').';
            }
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

        $lines[] = '';
        $lines[] = '<b>' . enlil_escape_html($objective['name']) . '</b>:';
        $objectiveId = (int)$objective['id'];

        $groups = enlil_task_groups($pending);
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

        foreach ($chainRoots as $rootTask) {
            $responsibles = $rootTask['responsible_ids'] ?? [];
            $mainResponsible = $responsibles ? ($peopleById[$responsibles[0]] ?? 'Alguien') : 'Alguien';
            $taskName = enlil_escape_html($rootTask['name'] ?? '');
            $taskDue = enlil_escape_html(enlil_format_date_es($rootTask['due_date'] ?? '', $monthsEs));
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
                $depDue = enlil_escape_html(enlil_format_date_es($dependentTask['due_date'] ?? '', $monthsEs));
                $depNames = [];
                foreach ($dependentTask['responsible_ids'] ?? [] as $rid) {
                    $depNames[] = enlil_escape_html($peopleById[$rid] ?? 'Alguien');
                }
                $depNamesText = $depNames ? implode(', ', $depNames) : 'Alguien';
                $lines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' antes del ' . $taskDue . ' para que antes del ' . $depDue . ', ' . $depNamesText . ' pueda ' . enlil_escape_html($dependentTask['name'] ?? '') . '.';
                $mentionedTasks[$objectiveId][$rootTask['id']] = $rootTask;
                $mentionedTasks[$objectiveId][$dependentTask['id']] = $dependentTask;
            } else {
                $lines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $mentionedTasks[$objectiveId][$rootTask['id']] = $rootTask;
            }
        }

        $independent = $groups['independent'];
        if ($independent) {
            $lines[] = 'Además:';
            foreach ($independent as $task) {
                $responsibles = $task['responsible_ids'] ?? [];
                $mainResponsible = $responsibles ? ($peopleById[$responsibles[0]] ?? 'Alguien') : 'Alguien';
                $taskName = enlil_escape_html($task['name'] ?? '');
                $taskDue = enlil_escape_html(enlil_format_date_es($task['due_date'] ?? '', $monthsEs));
                $lines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $mentionedTasks[$objectiveId][$task['id']] = $task;
            }
        }
    }

    $lines[] = '';
    $lines[] = '<b><span style="color:#ea2f28;">Tareas retrasadas</span></b>';
    if ($overdueLines) {
        $lines = array_merge($lines, $overdueLines);
    } else {
        $lines[] = 'Sin tareas retrasadas.';
    }

    $lines[] = '';
    $lines[] = '¡Buen trabajo y tened cuidado ahí fuera!';

    $message = implode("\n", $lines);

    $targets = [];
    foreach ($projectFull['team_ids'] as $teamId) {
        if (!isset($teamsById[$teamId])) {
            continue;
        }
        $groupId = trim((string)($teamsById[$teamId]['telegram_group'] ?? ''));
        if ($groupId !== '') {
            $targets[] = ['name' => $teamsById[$teamId]['name'], 'group' => $groupId];
        }
    }

    foreach ($targets as $target) {
        $payload = [
            'chat_id' => $target['group'],
            'text' => $message,
            'parse_mode' => 'HTML',
        ];
        enlil_telegram_post_json($token, 'sendMessage', $payload);
    }

    $businessConnectionId = trim((string)enlil_bot_business_connection_id());
    if ($businessConnectionId !== '') {
        $tasksByUser = [];
        foreach ($mentionedTasks as $objectiveId => $tasks) {
            foreach ($tasks as $task) {
                foreach ($task['responsible_ids'] ?? [] as $personId) {
                    if (!isset($tasksByUser[$personId])) {
                        $tasksByUser[$personId] = [];
                    }
                    if (!isset($tasksByUser[$personId][$objectiveId])) {
                        $tasksByUser[$personId][$objectiveId] = [];
                    }
                    $tasksByUser[$personId][$objectiveId][] = $task;
                }
            }
        }

        foreach ($tasksByUser as $personId => $objectivesTasks) {
            $info = $peopleInfoById[$personId] ?? null;
            if (!$info || $info['telegram_user_id'] === '') {
                continue;
            }
            $customer = enlil_customer_get($info['telegram_user_id']);
            if (!$customer || $customer['chat_id'] === '') {
                continue;
            }
            foreach ($objectivesTasks as $objectiveId => $tasks) {
                $objectiveName = '';
                foreach ($projectFull['objectives'] as $obj) {
                    if ((int)$obj['id'] === (int)$objectiveId) {
                        $objectiveName = $obj['name'];
                        break;
                    }
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
                if (!$checkTasks || $objectiveName === '') {
                    continue;
                }
                $payload = [
                    'business_connection_id' => $businessConnectionId,
                    'chat_id' => $customer['chat_id'],
                    'checklist' => [
                        'title' => $objectiveName,
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
                        enlil_checklist_map_add((string)$customer['chat_id'], $messageId, (int)$projectFull['id'], (int)$objectiveId, $taskIds);
                    }
                }
            }
        }
    }
}
