<?php

function enlil_customers_xml_path(): string {
    return __DIR__ . '/../data/customers.xml';
}

function enlil_customer_save(string $telegramUserId, string $username, string $chatId): void {
    $path = enlil_customers_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            $xml = new SimpleXMLElement('<customers></customers>');
        }
    } else {
        $xml = new SimpleXMLElement('<customers></customers>');
    }

    $updated = false;
    foreach ($xml->customer as $cust) {
        if ((string)$cust->telegram_user_id === $telegramUserId) {
            $cust->username = $username;
            $cust->chat_id = $chatId;
            $cust->updated_at = date('c');
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $node = $xml->addChild('customer');
        $node->addChild('telegram_user_id', $telegramUserId);
        $node->addChild('username', $username);
        $node->addChild('chat_id', $chatId);
        $node->addChild('updated_at', date('c'));
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de clientes.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de clientes.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_customer_get(string $telegramUserId): ?array {
    $path = enlil_customers_xml_path();
    if (!file_exists($path)) {
        return null;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }

    foreach ($xml->customer as $cust) {
        if ((string)$cust->telegram_user_id === $telegramUserId) {
            return [
                'telegram_user_id' => (string)$cust->telegram_user_id,
                'username' => (string)$cust->username,
                'chat_id' => (string)$cust->chat_id,
                'updated_at' => (string)$cust->updated_at,
            ];
        }
    }

    return null;
}

function enlil_customer_delete(string $telegramUserId): bool {
    $path = enlil_customers_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return false;
    }

    $index = 0;
    $deleted = false;
    foreach ($xml->customer as $cust) {
        if ((string)$cust->telegram_user_id === $telegramUserId) {
            unset($xml->customer[$index]);
            $deleted = true;
            break;
        }
        $index++;
    }

    if (!$deleted) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}
