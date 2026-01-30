<?php

function enlil_tokens_xml_path(): string {
    return __DIR__ . '/../data/access_tokens.xml';
}

function enlil_token_create(int $personId, string $type): string {
    $path = enlil_tokens_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    $xml = null;
    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
    }
    if (!$xml) {
        $xml = new SimpleXMLElement('<tokens></tokens>');
    }

    $now = time();
    foreach ($xml->token as $index => $node) {
        $expires = (int)($node->expires_at ?? 0);
        if ($expires > 0 && $expires < $now) {
            unset($xml->token[$index]);
        }
    }

    $token = bin2hex(random_bytes(16));
    $expiresAt = $now + 600;
    $node = $xml->addChild('token');
    $node->addChild('value', $token);
    $node->addChild('person_id', (string)$personId);
    $node->addChild('type', $type);
    $node->addChild('created_at', date('c', $now));
    $node->addChild('expires_at', (string)$expiresAt);

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        return $token;
    }
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $xml->asXML());
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    rename($tempPath, $path);
    @chmod($path, 0660);
    return $token;
}

function enlil_token_get(string $token): ?array {
    $path = enlil_tokens_xml_path();
    if (!file_exists($path)) {
        return null;
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }
    $now = time();
    foreach ($xml->token as $node) {
        if ((string)$node->value !== $token) {
            continue;
        }
        $expires = (int)($node->expires_at ?? 0);
        if ($expires !== 0 && $expires < $now) {
            return null;
        }
        return [
            'token' => (string)$node->value,
            'person_id' => (int)($node->person_id ?? 0),
            'type' => (string)($node->type ?? ''),
            'created_at' => (string)($node->created_at ?? ''),
            'expires_at' => (string)($node->expires_at ?? ''),
        ];
    }
    return null;
}
