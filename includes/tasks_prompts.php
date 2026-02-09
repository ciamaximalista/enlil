<?php

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/checklist_map.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/people.php';

function enlil_checklist_send_log(array $row): void {
    $path = __DIR__ . '/../data/checklist_send.log';
    $line = json_encode($row, JSON_UNESCAPED_UNICODE);
    if (!is_string($line) || $line === '') {
        return;
    }
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
}

function enlil_tasks_prompt_path(): string {
    return __DIR__ . '/../data/tasks_prompt_queue.json';
}

function enlil_tasks_last_sent_path(): string {
    return __DIR__ . '/../data/tasks_last_sent.json';
}

function enlil_tasks_prompt_load(): array {
    $path = enlil_tasks_prompt_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return [];
    }
    return $decoded['items'];
}

function enlil_tasks_prompt_save(array $items): void {
    $path = enlil_tasks_prompt_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $json = json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($path, $json);
}

function enlil_tasks_prompt_cleanup(array $items, int $maxAgeSeconds = 172800): array {
    $now = time();
    $clean = [];
    foreach ($items as $chatId => $item) {
        $createdAt = (string)($item['created_at'] ?? '');
        $ts = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($ts === false || ($now - $ts) <= $maxAgeSeconds) {
            $clean[(string)$chatId] = $item;
        }
    }
    return $clean;
}

function enlil_tasks_prompt_set(string $chatId, array $payload): void {
    if ($chatId === '') {
        return;
    }
    $items = enlil_tasks_prompt_cleanup(enlil_tasks_prompt_load());
    $payload['created_at'] = date('c');
    $items[$chatId] = $payload;
    enlil_tasks_prompt_save($items);
}

function enlil_tasks_prompt_get(string $chatId): ?array {
    if ($chatId === '') {
        return null;
    }
    $items = enlil_tasks_prompt_cleanup(enlil_tasks_prompt_load());
    enlil_tasks_prompt_save($items);
    return $items[$chatId] ?? null;
}

function enlil_tasks_prompt_clear(string $chatId): void {
    if ($chatId === '') {
        return;
    }
    $items = enlil_tasks_prompt_cleanup(enlil_tasks_prompt_load());
    unset($items[$chatId]);
    enlil_tasks_prompt_save($items);
}

function enlil_tasks_last_sent_load(): array {
    $path = enlil_tasks_last_sent_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return [];
    }
    return $decoded['items'];
}

function enlil_tasks_last_sent_save(array $items): void {
    $path = enlil_tasks_last_sent_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $json = json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($path, $json);
}

function enlil_tasks_last_sent_cleanup(array $items, int $maxAgeSeconds = 172800): array {
    $now = time();
    $clean = [];
    foreach ($items as $chatId => $item) {
        $sentAt = (string)($item['sent_at'] ?? '');
        $ts = $sentAt !== '' ? strtotime($sentAt) : false;
        if ($ts === false || ($now - $ts) <= $maxAgeSeconds) {
            $clean[(string)$chatId] = $item;
        }
    }
    return $clean;
}

function enlil_tasks_last_sent_set(string $chatId, array $checklists): void {
    if ($chatId === '' || !is_array($checklists) || !$checklists) {
        return;
    }
    $items = enlil_tasks_last_sent_cleanup(enlil_tasks_last_sent_load());
    $items[$chatId] = [
        'date' => date('Y-m-d'),
        'sent_at' => date('c'),
        'checklists' => $checklists,
    ];
    enlil_tasks_last_sent_save($items);
}

function enlil_tasks_last_sent_get_today(string $chatId): ?array {
    if ($chatId === '') {
        return null;
    }
    $items = enlil_tasks_last_sent_cleanup(enlil_tasks_last_sent_load());
    enlil_tasks_last_sent_save($items);
    $item = $items[$chatId] ?? null;
    if (!is_array($item)) {
        return null;
    }
    if ((string)($item['date'] ?? '') !== date('Y-m-d')) {
        return null;
    }
    $checklists = $item['checklists'] ?? null;
    if (!is_array($checklists) || !$checklists) {
        return null;
    }
    return $checklists;
}

function enlil_send_text_optional_business(string $token, string $chatId, string $text, string $businessConnectionId = ''): array {
    if ($chatId === '' || $text === '') {
        return ['ok' => false, 'http_code' => 0, 'body' => ''];
    }
    if ($businessConnectionId !== '') {
        $payload = [
            'business_connection_id' => $businessConnectionId,
            'chat_id' => $chatId,
            'text' => $text,
        ];
        $result = enlil_telegram_post_json($token, 'sendMessage', $payload);
        if (!empty($result['ok'])) {
            return $result;
        }
        if (!enlil_telegram_is_business_peer_missing($result) && !enlil_telegram_is_messages_to_self($result)) {
            return $result;
        }
    }
    return enlil_telegram_post_json($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
    ]);
}

function enlil_send_checklists_bundle(string $token, array $bundle): array {
    $chatId = (string)($bundle['chat_id'] ?? '');
    $businessConnectionId = (string)($bundle['business_connection_id'] ?? '');
    $globalBusinessConnectionId = function_exists('enlil_bot_business_connection_id') ? trim((string)enlil_bot_business_connection_id()) : '';
    $botOwnerUserId = function_exists('enlil_bot_business_owner_user_id') ? trim((string)enlil_bot_business_owner_user_id()) : '';
    $canSendToBusiness = ($botOwnerUserId === '' || $chatId !== $botOwnerUserId);
    $checklists = $bundle['checklists'] ?? [];
    $summary = [
        'ok' => 0,
        'failed' => 0,
        'errors' => [],
    ];
    if ($chatId === '' || !is_array($checklists) || !$checklists) {
        return $summary;
    }
    foreach ($checklists as $entry) {
        $projectId = (int)($entry['project_id'] ?? 0);
        $title = (string)($entry['title'] ?? '');
        $tasks = $entry['tasks'] ?? [];
        $taskMeta = is_array($entry['task_meta'] ?? null) ? $entry['task_meta'] : [];
        if ($projectId <= 0 || $title === '' || !is_array($tasks) || !$tasks) {
            continue;
        }
        $payloadChecklist = [
            'checklist' => [
                'title' => $title,
                'others_can_mark_tasks_as_done' => true,
                'others_can_add_tasks' => false,
                'tasks' => $tasks,
            ],
        ];
        $result = ['ok' => false, 'http_code' => 0, 'body' => ''];

        $businessAttempts = [];
        if ($canSendToBusiness) {
            if ($globalBusinessConnectionId !== '') {
                $businessAttempts[] = $globalBusinessConnectionId;
            } elseif ($businessConnectionId !== '') {
                // Fallback only when global business id is not configured.
                $businessAttempts[] = $businessConnectionId;
            }
        }

        $businessFailedWithPeer = false;
        foreach ($businessAttempts as $connId) {
            $payload = $payloadChecklist + [
                'business_connection_id' => $connId,
                'chat_id' => $chatId,
            ];
            $candidate = enlil_telegram_post_json($token, 'sendChecklist', $payload);
            enlil_checklist_send_log([
                'ts' => date('c'),
                'chat_id' => $chatId,
                'project_id' => $projectId,
                'title' => $title,
                'attempt' => 'business',
                'connection_id' => $connId,
                'ok' => !empty($candidate['ok']) ? 1 : 0,
                'http_code' => (int)($candidate['http_code'] ?? 0),
                'error' => enlil_telegram_error_description($candidate),
            ]);
            if (!empty($candidate['ok'])) {
                $result = $candidate;
                break;
            }
            if (enlil_telegram_is_business_peer_missing($candidate) || enlil_telegram_is_messages_to_self($candidate)) {
                $businessFailedWithPeer = true;
                $result = $candidate;
                break;
            }
            $result = $candidate;
        }

        if (empty($result['ok']) && !$businessFailedWithPeer) {
            $candidate = enlil_telegram_post_json($token, 'sendChecklist', $payloadChecklist + ['chat_id' => $chatId]);
            enlil_checklist_send_log([
                'ts' => date('c'),
                'chat_id' => $chatId,
                'project_id' => $projectId,
                'title' => $title,
                'attempt' => 'normal',
                'connection_id' => '',
                'ok' => !empty($candidate['ok']) ? 1 : 0,
                'http_code' => (int)($candidate['http_code'] ?? 0),
                'error' => enlil_telegram_error_description($candidate),
            ]);
            if (!empty($candidate['ok'])) {
                $result = $candidate;
            } else {
                $result = $candidate;
            }
        }

        if (!empty($result['ok'])) {
            $summary['ok']++;
            $data = is_string($result['body'] ?? '') ? json_decode((string)$result['body'], true) : null;
            $messageId = '';
            if (is_array($data) && isset($data['result']['message_id'])) {
                $messageId = (string)$data['result']['message_id'];
            }
            if ($messageId !== '') {
                $taskIds = array_map(function ($t) {
                    return (int)($t['id'] ?? 0);
                }, $tasks);
                enlil_checklist_map_add((string)$chatId, $messageId, $projectId, 0, $taskIds, $taskMeta);
            }
            continue;
        }

        $summary['failed']++;
        $error = [
            'project_id' => $projectId,
            'title' => $title,
            'http_code' => (int)($result['http_code'] ?? 0),
            'description' => enlil_telegram_error_description($result),
        ];
        $summary['errors'][] = $error;
        // Avoid spamming the same hard failure across all project checklists.
        if (
            enlil_telegram_is_business_peer_missing($result) ||
            enlil_telegram_is_messages_to_self($result) ||
            enlil_telegram_is_premium_required($result)
        ) {
            break;
        }
    }
    if ($summary['ok'] > 0) {
        enlil_tasks_last_sent_set($chatId, $checklists);
    }
    return $summary;
}

function enlil_checklist_send_error_message(array $resultSummary): string {
    $errors = $resultSummary['errors'] ?? [];
    if (!is_array($errors) || !$errors) {
        return 'No se pudieron enviar todas las listas.';
    }
    $descriptions = [];
    foreach ($errors as $err) {
        $desc = trim((string)($err['description'] ?? ''));
        if ($desc !== '') {
            $descriptions[$desc] = true;
        }
    }
    $unique = array_keys($descriptions);
    if (!$unique) {
        return 'No se pudieron enviar todas las listas.';
    }

    foreach ($unique as $desc) {
        if (stripos($desc, 'BUSINESS_PEER_USAGE_MISSING') !== false) {
            $selfMention = 'la cuenta self de esta instalación';
            $ownerUserId = function_exists('enlil_bot_business_owner_user_id') ? trim((string)enlil_bot_business_owner_user_id()) : '';
            if ($ownerUserId !== '' && function_exists('enlil_people_all')) {
                foreach (enlil_people_all() as $person) {
                    if ((string)($person['telegram_user_id'] ?? '') === $ownerUserId) {
                        $selfUser = trim((string)($person['telegram_user'] ?? ''));
                        if ($selfUser !== '') {
                            $selfMention = '@' . ltrim($selfUser, '@');
                        }
                        break;
                    }
                }
            }
            $botInfo = function_exists('enlil_bot_get') ? enlil_bot_get() : [];
            $botUsername = trim((string)($botInfo['username'] ?? ''));
            $botMention = $botUsername !== '' ? ('@' . ltrim($botUsername, '@')) : 'el bot de esta instalación';
            return 'No puedo enviarte checklist todavía desde la cuenta business. Escríbeme primero por privado a ' . $selfMention . ' y luego pon /tareas al bot (' . $botMention . '), no al self.';
        }
        if (stripos($desc, 'messages must not be sent to self') !== false) {
            return 'No pude enviarte checklist por el canal business. Inténtalo de nuevo en un momento.';
        }
    }
    foreach ($unique as $desc) {
        if (stripos($desc, 'PREMIUM_ACCOUNT_REQUIRED') !== false) {
            return 'Telegram rechazó el checklist en el canal estándar. Te lo enviaré por el canal business cuando esté disponible.';
        }
    }
    return 'No se pudieron enviar todas las listas: ' . implode(' | ', $unique);
}
