<?php

function enlil_business_xml_path(): string {
    return __DIR__ . '/../data/business_connections.xml';
}

function enlil_business_save(string $telegramUserId, string $connectionId, string $userChatId): void {
    $path = enlil_business_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            $xml = new SimpleXMLElement('<connections></connections>');
        }
    } else {
        $xml = new SimpleXMLElement('<connections></connections>');
    }

    $updated = false;
    foreach ($xml->connection as $conn) {
        if ((string)$conn->telegram_user_id === $telegramUserId) {
            $conn->connection_id = $connectionId;
            $conn->user_chat_id = $userChatId;
            $conn->updated_at = date('c');
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $node = $xml->addChild('connection');
        $node->addChild('telegram_user_id', $telegramUserId);
        $node->addChild('connection_id', $connectionId);
        $node->addChild('user_chat_id', $userChatId);
        $node->addChild('updated_at', date('c'));
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de business connections.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de business connections.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_business_get(string $telegramUserId): ?array {
    $path = enlil_business_xml_path();
    if (!file_exists($path)) {
        return null;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }

    foreach ($xml->connection as $conn) {
        if ((string)$conn->telegram_user_id === $telegramUserId) {
            return [
                'telegram_user_id' => (string)$conn->telegram_user_id,
                'connection_id' => (string)$conn->connection_id,
                'user_chat_id' => (string)$conn->user_chat_id,
                'updated_at' => (string)$conn->updated_at,
            ];
        }
    }

    return null;
}
