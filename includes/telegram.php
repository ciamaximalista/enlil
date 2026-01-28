<?php
// Basic Telegram Bot API helper

function enlil_telegram_post(string $token, string $method, array $payload): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ok = false;
    $httpCode = 0;
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
            $ok = true;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($payload),
                'timeout' => 10,
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            $ok = true;
        }
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => $body,
    ];
}

function enlil_telegram_get(string $token, string $method, array $query = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ok = false;
    $httpCode = 0;
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
            $ok = true;
        }
    } else {
        $body = @file_get_contents($url);
        if ($body !== false) {
            $ok = true;
        }
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => $body,
    ];
}

function enlil_telegram_post_json(string $token, string $method, array $payload): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ok = false;
    $httpCode = 0;
    $body = '';
    $json = json_encode($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
            $ok = true;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json",
                'content' => $json,
                'timeout' => 10,
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            $ok = true;
        }
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => $body,
    ];
}
