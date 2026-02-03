<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/people.php';

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
    $rootOf = function (int $id) use (&$rootOf, &$deps, &$rootMemo, &$visiting): int {
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
    $depthMemo = [];
    $depthVisiting = [];
    $depthOf = function (int $id) use (&$depthOf, &$deps, &$depthMemo, &$depthVisiting): int {
        if (isset($depthMemo[$id])) {
            return $depthMemo[$id];
        }
        if (isset($depthVisiting[$id])) {
            return 0;
        }
        $depthVisiting[$id] = true;
        $maxDepth = 0;
        foreach ($deps[$id] ?? [] as $depId) {
            $maxDepth = max($maxDepth, $depthOf((int)$depId) + 1);
        }
        unset($depthVisiting[$id]);
        $depthMemo[$id] = $maxDepth;
        return $maxDepth;
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

    $sortByOrder = function (&$list) use (&$depthOf) {
        usort($list, function ($a, $b) use (&$depthOf) {
            $da = $a['due_date'] ?? '';
            $db = $b['due_date'] ?? '';
            $depthA = $depthOf((int)$a['id']);
            $depthB = $depthOf((int)$b['id']);
            if ($depthA !== $depthB) {
                return $depthA <=> $depthB;
            }
            if ($da === $db) {
                return 0;
            }
            if ($da === '') {
                return 1;
            }
            if ($db === '') {
                return -1;
            }
            return strcmp($db, $da);
        });
    };
    foreach ($columns as &$list) {
        $sortByOrder($list);
    }
    unset($list);
    $sortByOrder($independent);

    return [
        'columns' => $columns,
        'independent' => $independent,
        'children' => $children,
    ];
}

function enlil_objective_order(array $objectives): array {
    $byId = [];
    $deps = [];
    foreach ($objectives as $obj) {
        $id = (int)$obj['id'];
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

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /equipos_list.php');
    exit;
}

$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$teams = enlil_teams_all();
$team = null;
foreach ($teams as $t) {
    if ((int)$t['id'] === $teamId) {
        $team = $t;
        break;
    }
}

if (!$team) {
    $_SESSION['flash_error'] = 'Equipo no encontrado.';
    header('Location: /equipos_list.php');
    exit;
}

$token = enlil_bot_token();
if ($token === '') {
    $_SESSION['flash_error'] = 'Bot no configurado.';
    header('Location: /equipos_list.php');
    exit;
}

$groupId = trim((string)($team['telegram_group'] ?? ''));
if ($groupId === '') {
    $_SESSION['flash_error'] = 'Este equipo no tiene ID de grupo de Telegram.';
    header('Location: /equipos_list.php');
    exit;
}

$monthsEs = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];
$people = enlil_people_all();
$peopleById = [];
foreach ($people as $p) {
    $peopleById[(int)$p['id']] = (string)$p['name'];
}
$projects = enlil_projects_all();
$todayTs = strtotime(date('Y-m-d'));
$limitTs = strtotime('+15 days', $todayTs);
$todayText = enlil_format_date_es(date('Y-m-d'), $monthsEs);

$sentAny = false;
foreach ($projects as $project) {
    if (!in_array((int)$teamId, $project['team_ids'] ?? [], true)) {
        continue;
    }
    $projectFull = enlil_projects_get((int)$project['id']);
    if (!$projectFull) {
        continue;
    }

    $lines = [];
    $lines[] = 'Hoy ' . enlil_escape_html($todayText) . ' en el proyecto <u><b>' . enlil_escape_html($projectFull['name']) . '</b></u>:';
    $overdueLines = [];
    $hasGroupContent = false;

    $orderedObjectives = enlil_objective_order($projectFull['objectives'] ?? []);
    foreach ($orderedObjectives as $objective) {
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
        $hasGroupContent = true;

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

        $hasChainLines = false;
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
                $hasChainLines = true;
            } else {
                $lines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
                $hasChainLines = true;
            }
        }

        $independent = $groups['independent'];
        if ($independent) {
            if ($hasChainLines) {
                $lines[] = 'Además:';
            }
            foreach ($independent as $task) {
                $responsibles = $task['responsible_ids'] ?? [];
                $mainResponsible = $responsibles ? ($peopleById[$responsibles[0]] ?? 'Alguien') : 'Alguien';
                $taskName = enlil_escape_html($task['name'] ?? '');
                $taskDue = enlil_escape_html(enlil_format_date_es($task['due_date'] ?? '', $monthsEs));
                $lines[] = '- ' . enlil_escape_html($mainResponsible) . ' tiene que ' . $taskName . ' antes del ' . $taskDue . '.';
            }
        }
    }

    if ($overdueLines) {
        $lines[] = '';
        $lines[] = '<b>Tareas retrasadas</b>';
        $lines = array_merge($lines, $overdueLines);
        $hasGroupContent = true;
    }

    if (!$hasGroupContent) {
        continue;
    }

    $lines[] = '';
    $lines[] = '¡Buen trabajo y tened cuidado ahí fuera!';

    $payload = [
        'chat_id' => $groupId,
        'text' => implode("\n", $lines),
        'parse_mode' => 'HTML',
    ];
    $result = enlil_telegram_post_json($token, 'sendMessage', $payload);
    if (!$result['ok']) {
        $migratedId = enlil_telegram_extract_migrate_chat_id($result);
        if ($migratedId !== '') {
            enlil_teams_update_group_id($teamId, $migratedId);
            $payload['chat_id'] = $migratedId;
            $result = enlil_telegram_post_json($token, 'sendMessage', $payload);
        }
    }
    if (!$result['ok']) {
        $code = $result['http_code'] ? 'HTTP ' . $result['http_code'] : 'sin respuesta';
        $detail = '';
        if (is_string($result['body']) && $result['body'] !== '') {
            $data = json_decode($result['body'], true);
            if (is_array($data) && isset($data['description'])) {
                $detail = $data['description'];
            }
        }
        $_SESSION['flash_error'] = $detail !== ''
            ? 'No se pudo enviar el mensaje (' . $code . '): ' . $detail . '.'
            : 'No se pudo enviar el mensaje (' . $code . ').';
        header('Location: /equipos_list.php');
        exit;
    }
    $sentAny = true;
}

if ($sentAny) {
    $_SESSION['flash_success'] = 'Mensajes enviados al grupo.';
} else {
    $_SESSION['flash_error'] = 'No hay tareas pendientes o retrasadas para este equipo.';
}

header('Location: /equipos_list.php');
exit;
