<?php
// XML-based storage for teams

function enlil_teams_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['teams_xml'];
}

function enlil_teams_all(): array {
    $path = enlil_teams_xml_path();
    if (!file_exists($path)) {
        return [];
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }

    $teams = [];
    foreach ($xml->team as $team) {
        $teams[] = [
            'id' => (int)$team['id'],
            'name' => (string)$team->name,
            'telegram_group' => (string)$team->telegram_group,
            'telegram_bot_token' => (string)$team->telegram_bot_token,
            'business_connection_id' => (string)($team->business_connection_id ?? ''),
        ];
    }

    return $teams;
}

function enlil_teams_add(string $name, string $telegramGroup, string $telegramBotToken): void {
    $path = enlil_teams_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            throw new RuntimeException('No se pudo leer el XML de equipos.');
        }
    } else {
        $xml = new SimpleXMLElement('<teams></teams>');
    }

    $maxId = 0;
    foreach ($xml->team as $team) {
        $id = (int)$team['id'];
        if ($id > $maxId) {
            $maxId = $id;
        }
    }

    $newId = $maxId + 1;
    $node = $xml->addChild('team');
    $node->addAttribute('id', (string)$newId);
    $node->addChild('name', $name);
    $node->addChild('telegram_group', $telegramGroup);
    $node->addChild('telegram_bot_token', $telegramBotToken);
    $node->addChild('business_connection_id', '');

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de equipos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de equipos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_teams_get(int $id): ?array {
    $teams = enlil_teams_all();
    foreach ($teams as $team) {
        if ($team['id'] === $id) {
            return $team;
        }
    }
    return null;
}

function enlil_teams_update(int $id, string $name, string $telegramGroup, string $telegramBotToken): bool {
    $path = enlil_teams_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de equipos.');
    }

    $updated = false;
    foreach ($xml->team as $team) {
        if ((int)$team['id'] === $id) {
            $team->name = $name;
            $team->telegram_group = $telegramGroup;
            $team->telegram_bot_token = $telegramBotToken;
            if (!isset($team->business_connection_id)) {
                $team->addChild('business_connection_id', '');
            }
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de equipos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de equipos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_teams_update_business_connection(int $id, string $connectionId): bool {
    $path = enlil_teams_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de equipos.');
    }

    $updated = false;
    foreach ($xml->team as $team) {
        if ((int)$team['id'] === $id) {
            if (!isset($team->business_connection_id)) {
                $team->addChild('business_connection_id', $connectionId);
            } else {
                $team->business_connection_id = $connectionId;
            }
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de equipos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de equipos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_teams_update_group_id(int $id, string $telegramGroup): bool {
    $path = enlil_teams_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de equipos.');
    }

    $updated = false;
    foreach ($xml->team as $team) {
        if ((int)$team['id'] === $id) {
            $team->telegram_group = $telegramGroup;
            if (!isset($team->telegram_bot_token)) {
                $team->addChild('telegram_bot_token', '');
            }
            if (!isset($team->business_connection_id)) {
                $team->addChild('business_connection_id', '');
            }
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de equipos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de equipos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}
