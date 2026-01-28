<?php
// XML-based storage for people

function enlil_people_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['people_xml'];
}

function enlil_people_all(): array {
    $path = enlil_people_xml_path();
    if (!file_exists($path)) {
        return [];
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }

    $people = [];
    foreach ($xml->person as $person) {
        $teamIds = [];
        if (isset($person->teams)) {
            foreach ($person->teams->team_id as $teamId) {
                $teamIds[] = (int)$teamId;
            }
        }
        $people[] = [
            'id' => (int)$person['id'],
            'name' => (string)$person->name,
            'telegram_user' => (string)$person->telegram_user,
            'telegram_user_id' => isset($person->telegram_user_id) ? (string)$person->telegram_user_id : '',
            'team_ids' => $teamIds,
        ];
    }

    return $people;
}

function enlil_people_add(string $name, string $telegramUser, string $telegramUserId, array $teamIds): void {
    $path = enlil_people_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            // recover from empty/corrupt file by reinitializing
            $xml = new SimpleXMLElement('<people></people>');
        }
    } else {
        $xml = new SimpleXMLElement('<people></people>');
    }

    $maxId = 0;
    foreach ($xml->person as $person) {
        $id = (int)$person['id'];
        if ($id > $maxId) {
            $maxId = $id;
        }
    }

    $newId = $maxId + 1;
    $node = $xml->addChild('person');
    $node->addAttribute('id', (string)$newId);
    $node->addChild('name', $name);
    $node->addChild('telegram_user', $telegramUser);
    $node->addChild('telegram_user_id', $telegramUserId);

    if ($teamIds) {
        $teamsNode = $node->addChild('teams');
        foreach ($teamIds as $teamId) {
            $teamsNode->addChild('team_id', (string)$teamId);
        }
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de personas.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de personas.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_people_get(int $id): ?array {
    $people = enlil_people_all();
    foreach ($people as $person) {
        if ($person['id'] === $id) {
            return $person;
        }
    }
    return null;
}

function enlil_people_update(int $id, string $name, string $telegramUser, string $telegramUserId, array $teamIds): bool {
    $path = enlil_people_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de personas.');
    }

    $updated = false;
    foreach ($xml->person as $person) {
        if ((int)$person['id'] === $id) {
            $person->name = $name;
            $person->telegram_user = $telegramUser;
            $person->telegram_user_id = $telegramUserId;

            if (isset($person->teams)) {
                unset($person->teams);
            }
            if ($teamIds) {
                $teamsNode = $person->addChild('teams');
                foreach ($teamIds as $teamId) {
                    $teamsNode->addChild('team_id', (string)$teamId);
                }
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
        throw new RuntimeException('No se pudo crear el XML temporal de personas.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de personas.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_people_update_telegram_id(int $id, string $telegramUserId): bool {
    $path = enlil_people_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return false;
    }

    $updated = false;
    foreach ($xml->person as $person) {
        if ((int)$person['id'] === $id) {
            $person->telegram_user_id = $telegramUserId;
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

function enlil_people_delete(int $id): bool {
    $path = enlil_people_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de personas.');
    }

    $index = 0;
    $deleted = false;
    foreach ($xml->person as $person) {
        if ((int)$person['id'] === $id) {
            unset($xml->person[$index]);
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
        throw new RuntimeException('No se pudo crear el XML temporal de personas.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de personas.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_people_remove_from_team(int $personId, int $teamId): bool {
    $path = enlil_people_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de personas.');
    }

    $updated = false;
    foreach ($xml->person as $person) {
        if ((int)$person['id'] === $personId) {
            if (isset($person->teams)) {
                $newIds = [];
                foreach ($person->teams->team_id as $teamIdNode) {
                    $id = (int)$teamIdNode;
                    if ($id !== $teamId) {
                        $newIds[] = $id;
                    }
                }

                unset($person->teams);
                if ($newIds) {
                    $teamsNode = $person->addChild('teams');
                    foreach ($newIds as $id) {
                        $teamsNode->addChild('team_id', (string)$id);
                    }
                }
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
        throw new RuntimeException('No se pudo crear el XML temporal de personas.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de personas.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}
