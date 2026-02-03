<?php
require_once __DIR__ . '/checklists.php';
require_once __DIR__ . '/checklist_map.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/avatars.php';
require_once __DIR__ . '/telegram.php';

function enlil_task_updates_avatar_url(array $person, string $telegramUser = ''): string {
    $telegramUserId = (string)($person['telegram_user_id'] ?? '');
    if ($telegramUserId !== '') {
        $path = __DIR__ . '/../data/avatars/' . $telegramUserId . '.jpg';
        if (file_exists($path)) {
            return enlil_avatar_url($telegramUserId);
        }
    }
    $username = $telegramUser !== '' ? $telegramUser : (string)($person['telegram_user'] ?? '');
    $username = ltrim($username, '@');
    if ($username !== '') {
        return 'https://t.me/i/userpic/320/' . rawurlencode($username) . '.jpg';
    }
    return '';
}

function enlil_task_updates_parse_ids(string $csv): array {
    $parts = array_filter(array_map('trim', explode(',', $csv)));
    $ids = [];
    foreach ($parts as $part) {
        if (ctype_digit($part)) {
            $ids[] = (int)$part;
        }
    }
    return $ids;
}

function enlil_task_updates_last_24h(): array {
    $events = enlil_checklist_events_all();
    if (!$events) {
        return [];
    }

    $projectsIndex = [];
    $taskIndex = [];
    $objectiveIndex = [];
    foreach (enlil_projects_all() as $project) {
        $full = enlil_projects_get((int)$project['id']);
        if (!$full) {
            continue;
        }
        $projectsIndex[(int)$project['id']] = $full;
        foreach ($full['objectives'] as $objective) {
            $objectiveId = (int)$objective['id'];
            $objectiveIndex[(int)$full['id']][$objectiveId] = $objective['name'] ?? '';
            foreach ($objective['tasks'] as $task) {
                $taskIndex[(int)$full['id']][(int)$task['id']] = [
                    'objective_id' => $objectiveId,
                    'objective_name' => $objective['name'] ?? '',
                    'task_name' => $task['name'] ?? '',
                    'status' => $task['status'] ?? 'pending',
                ];
            }
        }
    }

    $people = enlil_people_all();
    $peopleById = [];
    $peopleByTelegramId = [];
    foreach ($people as $person) {
        $peopleById[(int)$person['id']] = $person;
        $tgId = (string)($person['telegram_user_id'] ?? '');
        if ($tgId !== '') {
            $peopleByTelegramId[$tgId] = $person;
        }
    }

    $cutoff = time() - 24 * 3600;
    $latest = [];
    foreach ($events as $event) {
        $createdAt = strtotime($event['created_at'] ?? '');
        if (!$createdAt || $createdAt < $cutoff) {
            continue;
        }
        $doneIds = enlil_task_updates_parse_ids((string)($event['done_ids'] ?? ''));
        if (!$doneIds) {
            continue;
        }

        $chatId = (string)($event['chat_id'] ?? '');
        foreach ($doneIds as $doneId) {
            $projectId = 0;
            $taskId = 0;
            $objectiveId = 0;
            $taskName = '';

            [$decodedProjectId, $decodedTaskId] = enlil_checklist_decode_task_id($doneId);
            if ($decodedProjectId > 0 && $decodedTaskId > 0) {
                $projectId = $decodedProjectId;
                $taskId = $decodedTaskId;
            } else {
                $meta = $chatId !== '' ? enlil_checklist_map_find_task_meta($chatId, $doneId) : null;
                if ($meta) {
                    $projectId = (int)($meta['project_id'] ?? 0);
                    $taskId = (int)($meta['task_id'] ?? 0);
                    $objectiveId = (int)($meta['objective_id'] ?? 0);
                    $taskName = (string)($meta['name'] ?? '');
                }
            }

            if ($projectId <= 0 || $taskId <= 0) {
                continue;
            }
            if (!isset($taskIndex[$projectId][$taskId])) {
                continue;
            }

            $taskInfo = $taskIndex[$projectId][$taskId];
            if (($taskInfo['status'] ?? 'pending') !== 'done') {
                continue;
            }

            $objectiveName = $taskInfo['objective_name'] ?? '';
            $taskName = $taskInfo['task_name'] !== '' ? $taskInfo['task_name'] : $taskName;

            $personId = (int)($event['person_id'] ?? 0);
            $telegramUser = (string)($event['telegram_user'] ?? '');
            $telegramUserId = (string)($event['telegram_user_id'] ?? '');
            $person = $personId > 0 && isset($peopleById[$personId]) ? $peopleById[$personId] : null;
            if (!$person && $telegramUserId !== '' && isset($peopleByTelegramId[$telegramUserId])) {
                $person = $peopleByTelegramId[$telegramUserId];
            }

            $displayName = $person['name'] ?? '';
            if ($displayName === '') {
                $displayName = $telegramUser !== '' ? ('@' . ltrim($telegramUser, '@')) : '—';
            }
            $avatarUrl = $person ? enlil_task_updates_avatar_url($person, $telegramUser) : ($telegramUser !== '' ? 'https://t.me/i/userpic/320/' . rawurlencode(ltrim($telegramUser, '@')) . '.jpg' : '');

            $whoKey = $person ? ('person:' . (int)$person['id']) : ('tg:' . ($telegramUserId !== '' ? $telegramUserId : $telegramUser));
            $key = $projectId . ':' . $taskId . ':' . $whoKey;
            if (!isset($latest[$key]) || $createdAt > $latest[$key]['created_at']) {
                $latest[$key] = [
                    'created_at' => $createdAt,
                    'project_id' => $projectId,
                    'objective' => $objectiveName,
                    'task' => $taskName,
                    'person' => $displayName,
                    'avatar' => $avatarUrl,
                ];
            }
        }
    }

    if (!$latest) {
        return [];
    }

    $byProject = [];
    foreach ($latest as $entry) {
        $projectId = $entry['project_id'];
        if (!isset($projectsIndex[$projectId])) {
            continue;
        }
        if (!isset($byProject[$projectId])) {
            $byProject[$projectId] = [
                'project' => $projectsIndex[$projectId],
                'rows' => [],
            ];
        }
        $byProject[$projectId]['rows'][] = [
            'objective' => $entry['objective'],
            'task' => $entry['task'],
            'person' => $entry['person'],
            'avatar' => $entry['avatar'],
        ];
    }

    foreach ($byProject as &$group) {
        usort($group['rows'], function ($a, $b) {
            $cmp = strcasecmp($a['objective'], $b['objective']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcasecmp($a['task'], $b['task']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp($a['person'], $b['person']);
        });
    }
    unset($group);

    $projects = array_values($byProject);
    usort($projects, function ($a, $b) {
        return strcasecmp($a['project']['name'] ?? '', $b['project']['name'] ?? '');
    });

    return $projects;
}
