<?php

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/telegram.php';

function enlil_tasks_delivery_groups(array $tasks): array {
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

function enlil_tasks_delivery_compare(array $a, array $b): int {
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

function enlil_tasks_delivery_target_dates(): array {
    $today = new DateTimeImmutable('today');
    $dates = [
        $today->format('Y-m-d'),
        $today->modify('+1 day')->format('Y-m-d'),
    ];
    if ((int)$today->format('N') === 5) {
        $dates[] = $today->modify('+3 day')->format('Y-m-d');
    }
    return array_values(array_unique($dates));
}

function enlil_tasks_delivery_include_due_date(string $dueDate, array $targetDates, string $today, bool $includeOverdue = false): bool {
    if ($dueDate === '') {
        return false;
    }
    if (in_array($dueDate, $targetDates, true)) {
        return true;
    }
    if (!$includeOverdue) {
        return false;
    }
    $dueTs = strtotime($dueDate);
    $todayTs = strtotime($today);
    if ($dueTs === false || $todayTs === false) {
        return false;
    }
    return $dueTs < $todayTs;
}

function enlil_build_person_checklists(int $personId, int $limitDays = 15, bool $includeOverdue = true): array {
    $projects = enlil_projects_all();
    $projectsFull = [];
    foreach ($projects as $proj) {
        $full = enlil_projects_get((int)$proj['id']);
        if ($full) {
            $projectsFull[] = $full;
        }
    }
    $todayDate = date('Y-m-d');
    $targetDates = enlil_tasks_delivery_target_dates();
    $todayTs = strtotime($todayDate);
    $limitTs = strtotime('+' . max(1, $limitDays) . ' days', $todayTs);

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
                $due = (string)($task['due_date'] ?? '');
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
            $groups = enlil_tasks_delivery_groups($pending);
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
            foreach ($pending as $task) {
                $tid = (int)($task['id'] ?? 0);
                if ($tid > 0 && !isset($mentioned[$tid])) {
                    $mentioned[$tid] = $task;
                }
            }
            foreach ($mentioned as $task) {
                if (!in_array($personId, $task['responsible_ids'] ?? [], true)) {
                    continue;
                }
                $objectiveId = (int)$objective['id'];
                if (!isset($objectiveNames[$objectiveId])) {
                    $objectiveNames[$objectiveId] = (string)$objective['name'];
                }
                if (!isset($tasksByProject[$projectId])) {
                    $tasksByProject[$projectId] = [
                        'name' => (string)$proj['name'],
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

    $checklists = [];
    foreach ($tasksByProject as $projectId => $projectData) {
        $projectName = (string)($projectData['name'] ?? '');
        $tasks = $projectData['tasks'] ?? [];
        usort($tasks, function ($a, $b) {
            return enlil_tasks_delivery_compare($a['task'] ?? [], $b['task'] ?? []);
        });
        $checkTasks = [];
        $taskMeta = [];
        foreach ($tasks as $entry) {
            $task = $entry['task'] ?? [];
            $dueDate = (string)($task['due_date'] ?? '');
            if (!enlil_tasks_delivery_include_due_date($dueDate, $targetDates, $todayDate, $includeOverdue)) {
                continue;
            }
            $dueText = '';
            if ($dueDate !== '') {
                $ts = strtotime($dueDate);
                if ($ts !== false) {
                    $dueText = date('d/m', $ts);
                }
            }
            $suffix = $dueText !== '' ? ' (' . $dueText . ')' : '';
            $taskText = (string)($task['name'] ?? '') . $suffix;
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
        if ($projectName === '' || !$checkTasks) {
            continue;
        }
        $checklists[] = [
            'project_id' => (int)$projectId,
            'title' => $projectName,
            'tasks' => $checkTasks,
            'task_meta' => $taskMeta,
        ];
    }
    return $checklists;
}
