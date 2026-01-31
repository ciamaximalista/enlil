<?php
require_once __DIR__ . '/config.php';

function enlil_checklist_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return __DIR__ . '/../data/checklist_events.xml';
}

function enlil_checklist_add(array $event): void {
    $path = enlil_checklist_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            $xml = new SimpleXMLElement('<events></events>');
        }
    } else {
        $xml = new SimpleXMLElement('<events></events>');
    }

    $node = $xml->addChild('event');
    $node->addChild('created_at', $event['created_at'] ?? date('c'));
    $node->addChild('person_id', (string)($event['person_id'] ?? ''));
    $node->addChild('telegram_user', (string)($event['telegram_user'] ?? ''));
    $node->addChild('telegram_user_id', (string)($event['telegram_user_id'] ?? ''));
    $node->addChild('team_id', (string)($event['team_id'] ?? ''));
    $node->addChild('chat_id', (string)($event['chat_id'] ?? ''));
    $node->addChild('message_id', (string)($event['message_id'] ?? ''));
    $node->addChild('done_ids', (string)($event['done_ids'] ?? ''));
    $node->addChild('not_done_ids', (string)($event['not_done_ids'] ?? ''));
    $node->addChild('done_state_ids', (string)($event['done_state_ids'] ?? ''));

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de checklist.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de checklist.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_checklist_recent(int $limit = 10): array {
    $path = enlil_checklist_xml_path();
    if (!file_exists($path)) {
        return [];
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }

    $events = [];
    foreach ($xml->event as $event) {
        $events[] = [
            'created_at' => (string)$event->created_at,
            'person_id' => (string)$event->person_id,
            'telegram_user' => (string)$event->telegram_user,
            'telegram_user_id' => (string)$event->telegram_user_id,
            'team_id' => (string)$event->team_id,
            'chat_id' => (string)$event->chat_id,
            'message_id' => (string)$event->message_id,
            'done_ids' => (string)$event->done_ids,
            'not_done_ids' => (string)$event->not_done_ids,
            'done_state_ids' => (string)($event->done_state_ids ?? ''),
        ];
    }

    $events = array_reverse($events);
    return array_slice($events, 0, $limit);
}

function enlil_checklist_last_done_state(string $chatId, string $messageId): array {
    $path = enlil_checklist_xml_path();
    if (!file_exists($path)) {
        return [];
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }
    $last = '';
    foreach ($xml->event as $event) {
        if ((string)$event->chat_id === $chatId && (string)$event->message_id === $messageId) {
            $last = (string)($event->done_state_ids ?? '');
        }
    }
    if ($last === '') {
        return [];
    }
    $parts = array_filter(array_map('trim', explode(',', $last)));
    $ids = [];
    foreach ($parts as $part) {
        if (ctype_digit($part)) {
            $ids[] = (int)$part;
        }
    }
    return $ids;
}
