<?php

function enlil_checklist_map_path(): string {
    return __DIR__ . '/../data/checklist_map.xml';
}

function enlil_checklist_map_add(string $chatId, string $messageId, int $projectId, int $objectiveId, array $taskIds): void {
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
            return [
                'project_id' => (int)$node->project_id,
                'objective_id' => (int)$node->objective_id,
                'task_ids' => $taskIds,
            ];
        }
    }

    return null;
}
