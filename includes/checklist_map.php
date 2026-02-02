<?php

function enlil_checklist_map_path(): string {
    return __DIR__ . '/../data/checklist_map.xml';
}

function enlil_checklist_map_add(string $chatId, string $messageId, int $projectId, int $objectiveId, array $taskIds, array $taskMeta = []): void {
    $path = enlil_checklist_map_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            $xml = new SimpleXMLElement('<checklists></checklists>');
        }
    } else {
        $xml = new SimpleXMLElement('<checklists></checklists>');
    }

    $node = $xml->addChild('checklist');
    $node->addChild('chat_id', $chatId);
    $node->addChild('message_id', $messageId);
    $node->addChild('project_id', (string)$projectId);
    $node->addChild('objective_id', (string)$objectiveId);
    if ($taskIds) {
        $tasksNode = $node->addChild('task_ids');
        foreach ($taskIds as $taskId) {
            $tasksNode->addChild('task_id', (string)$taskId);
        }
    }
    if ($taskMeta) {
        $tasksNode = $node->addChild('tasks');
        foreach ($taskMeta as $checklistId => $meta) {
            $taskNode = $tasksNode->addChild('task');
            $taskNode->addChild('checklist_id', (string)$checklistId);
            $taskNode->addChild('task_id', (string)($meta['task_id'] ?? 0));
            $taskNode->addChild('objective_id', (string)($meta['objective_id'] ?? 0));
            $taskNode->addChild('name', (string)($meta['name'] ?? ''));
        }
    }
    $node->addChild('created_at', date('c'));

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de checklists.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de checklists.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_checklist_map_get(string $chatId, string $messageId): ?array {
    $path = enlil_checklist_map_path();
    if (!file_exists($path)) {
        return null;
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }

    foreach ($xml->checklist as $node) {
        if ((string)$node->chat_id === $chatId && (string)$node->message_id === $messageId) {
            $taskIds = [];
            if (isset($node->task_ids)) {
                foreach ($node->task_ids->task_id as $taskId) {
                    $taskIds[] = (int)$taskId;
                }
            }
            $taskMeta = [];
            if (isset($node->tasks)) {
                foreach ($node->tasks->task as $taskNode) {
                    $checklistId = (int)($taskNode->checklist_id ?? 0);
                    if ($checklistId > 0) {
                        $taskMeta[$checklistId] = [
                            'task_id' => (int)($taskNode->task_id ?? 0),
                            'objective_id' => (int)($taskNode->objective_id ?? 0),
                            'name' => (string)($taskNode->name ?? ''),
                        ];
                    }
                }
            }
            return [
                'project_id' => (int)$node->project_id,
                'objective_id' => (int)$node->objective_id,
                'task_ids' => $taskIds,
                'task_meta' => $taskMeta,
            ];
        }
    }

    return null;
}

function enlil_checklist_map_find_task_meta(string $chatId, int $checklistId): ?array {
    if ($checklistId <= 0) {
        return null;
    }
    $path = enlil_checklist_map_path();
    if (!file_exists($path)) {
        return null;
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }
    foreach ($xml->checklist as $node) {
        if ((string)$node->chat_id !== $chatId) {
            continue;
        }
        if (!isset($node->tasks)) {
            continue;
        }
        foreach ($node->tasks->task as $taskNode) {
            $cid = (int)($taskNode->checklist_id ?? 0);
            if ($cid !== $checklistId) {
                continue;
            }
            return [
                'project_id' => (int)($node->project_id ?? 0),
                'objective_id' => (int)($taskNode->objective_id ?? 0),
                'task_id' => (int)($taskNode->task_id ?? 0),
                'name' => (string)($taskNode->name ?? ''),
            ];
        }
    }
    return null;
}

function enlil_checklist_map_list(string $chatId, int $projectId): array {
    $path = enlil_checklist_map_path();
    if (!file_exists($path)) {
        return [];
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }
    $messageIds = [];
    foreach ($xml->checklist as $node) {
        if ((string)$node->chat_id === $chatId && (int)$node->project_id === $projectId) {
            $messageIds[] = (string)$node->message_id;
        }
    }
    return $messageIds;
}

function enlil_checklist_map_delete(string $chatId, array $messageIds): void {
    if (!$messageIds) {
        return;
    }
    $path = enlil_checklist_map_path();
    if (!file_exists($path)) {
        return;
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return;
    }
    $messageIds = array_map('strval', $messageIds);
    $removed = false;
    foreach ($xml->checklist as $idx => $node) {
        if ((string)$node->chat_id === $chatId && in_array((string)$node->message_id, $messageIds, true)) {
            unset($xml->checklist[$idx]);
            $removed = true;
        }
    }
    if (!$removed) {
        return;
    }
    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de checklists.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de checklists.');
    }
    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    rename($tempPath, $path);
    chmod($path, 0660);
}
