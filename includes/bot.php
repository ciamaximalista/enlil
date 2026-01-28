<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

function enlil_bot_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['bot_xml'];
}

function enlil_bot_get(): array {
    $path = enlil_bot_xml_path();
    if (!file_exists($path)) {
        return [
            'token' => '',
            'business_connection_id' => '',
            'business_owner_user_id' => '',
            'name' => '',
            'username' => '',
            'avatar_file' => '',
        ];
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [
            'token' => '',
            'business_connection_id' => '',
            'name' => '',
            'username' => '',
            'avatar_file' => '',
        ];
    }

    return [
        'token' => (string)($xml->token ?? ''),
        'business_connection_id' => (string)($xml->business_connection_id ?? ''),
        'business_owner_user_id' => (string)($xml->business_owner_user_id ?? ''),
        'name' => (string)($xml->name ?? ''),
        'username' => (string)($xml->username ?? ''),
        'avatar_file' => (string)($xml->avatar_file ?? ''),
    ];
}

function enlil_bot_save(string $token): array {
    $token = trim($token);
    $info = enlil_bot_get();
    $info['token'] = $token;

    if ($token !== '') {
        $me = enlil_telegram_get($token, 'getMe');
        if ($me['ok']) {
            $data = json_decode($me['body'], true);
            if (is_array($data) && isset($data['result'])) {
                $info['name'] = (string)($data['result']['first_name'] ?? '');
                $info['username'] = (string)($data['result']['username'] ?? '');
                $botId = (string)($data['result']['id'] ?? '');
                if ($botId !== '') {
                    $photos = enlil_telegram_get($token, 'getUserProfilePhotos', [
                        'user_id' => $botId,
                        'limit' => 1,
                    ]);
                    if ($photos['ok']) {
                        $pdata = json_decode($photos['body'], true);
                        if (is_array($pdata) && isset($pdata['result']['photos'][0][0]['file_id'])) {
                            $fileId = $pdata['result']['photos'][0][0]['file_id'];
                            $fileInfo = enlil_telegram_get($token, 'getFile', [
                                'file_id' => $fileId,
                            ]);
                            if ($fileInfo['ok']) {
                                $fdata = json_decode($fileInfo['body'], true);
                                if (is_array($fdata) && isset($fdata['result']['file_path'])) {
                                    $filePath = $fdata['result']['file_path'];
                                    $fileUrl = 'https://api.telegram.org/file/bot' . $token . '/' . $filePath;
                                    $image = @file_get_contents($fileUrl);
                                    if ($image !== false) {
                                        $dir = __DIR__ . '/../data/avatars';
                                        if (!is_dir($dir)) {
                                            mkdir($dir, 0770, true);
                                        }
                                        $filename = 'bot.jpg';
                                        file_put_contents($dir . '/' . $filename, $image);
                                        chmod($dir . '/' . $filename, 0660);
                                        $info['avatar_file'] = $filename;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $path = enlil_bot_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    $xml = new SimpleXMLElement('<bot></bot>');
    $xml->addChild('token', $info['token']);
    $xml->addChild('business_connection_id', $info['business_connection_id']);
    $xml->addChild('business_owner_user_id', $info['business_owner_user_id'] ?? '');
    $xml->addChild('name', $info['name']);
    $xml->addChild('username', $info['username']);
    $xml->addChild('avatar_file', $info['avatar_file']);

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal del bot.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal del bot.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);

    return $info;
}

function enlil_bot_update_business_connection(string $connectionId, string $ownerUserId = ''): bool {
    $path = enlil_bot_xml_path();
    $info = enlil_bot_get();
    $info['business_connection_id'] = $connectionId;
    if ($ownerUserId !== '') {
        $info['business_owner_user_id'] = $ownerUserId;
    }

    $xml = new SimpleXMLElement('<bot></bot>');
    $xml->addChild('token', $info['token']);
    $xml->addChild('business_connection_id', $info['business_connection_id']);
    $xml->addChild('business_owner_user_id', $info['business_owner_user_id'] ?? '');
    $xml->addChild('name', $info['name']);
    $xml->addChild('username', $info['username']);
    $xml->addChild('avatar_file', $info['avatar_file']);

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal del bot.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal del bot.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_bot_token(): string {
    $info = enlil_bot_get();
    return (string)$info['token'];
}

function enlil_bot_business_connection_id(): string {
    $info = enlil_bot_get();
    return (string)$info['business_connection_id'];
}

function enlil_bot_avatar_url(): string {
    $info = enlil_bot_get();
    if ($info['avatar_file'] === '') {
        return '';
    }
    return '/data/avatars/' . rawurlencode($info['avatar_file']);
}

function enlil_bot_webhook_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $token = enlil_bot_token();
    if ($host === '' || $token === '') {
        return '';
    }
    return $scheme . '://' . $host . '/telegram_webhook.php?token=' . rawurlencode($token);
}
