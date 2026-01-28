<?php
// XML-based storage helpers for the single admin user.

function enlil_admin_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['admin_xml'];
}

function enlil_admin_exists(): bool {
    return file_exists(enlil_admin_xml_path());
}

function enlil_admin_get(): ?array {
    $path = enlil_admin_xml_path();
    if (!file_exists($path)) {
        return null;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }

    return [
        'username' => (string)$xml->username,
        'password_hash' => (string)$xml->password_hash,
        'created_at' => (string)$xml->created_at,
    ];
}

function enlil_admin_save(string $username, string $passwordHash, string $createdAt): void {
    $path = enlil_admin_xml_path();
    $dataDir = dirname($path);

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    $xml = new SimpleXMLElement('<admin></admin>');
    $xml->addChild('username', $username);
    $xml->addChild('password_hash', $passwordHash);
    $xml->addChild('created_at', $createdAt);

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el archivo XML temporal.');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el archivo XML temporal.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}
