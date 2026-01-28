<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/customers.php';

enlil_require_login();
enlil_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /personas_list.php');
    exit;
}

$token = enlil_bot_token();
if ($token === '') {
    $_SESSION['flash_error'] = 'Bot no configurado.';
    header('Location: /personas_list.php');
    exit;
}

$webhookInfo = enlil_telegram_get($token, 'getWebhookInfo');
if ($webhookInfo['ok']) {
    $data = json_decode($webhookInfo['body'], true);
    $url = $data['result']['url'] ?? '';
    if ($url !== '') {
        $_SESSION['flash_error'] = 'El webhook está activo. Desactívalo antes de buscar IDs.';
        header('Location: /personas_list.php');
        exit;
    }
}

$updates = enlil_telegram_get($token, 'getUpdates');
if (!$updates['ok']) {
    $code = $updates['http_code'] ? 'HTTP ' . $updates['http_code'] : 'sin respuesta';
    $_SESSION['flash_error'] = 'No se pudo consultar Telegram (' . $code . ').';
    header('Location: /personas_list.php');
    exit;
}

$body = $updates['body'] ?? '';
$data = json_decode($body, true);
$map = [];
if (is_array($data) && isset($data['result']) && is_array($data['result'])) {
    foreach ($data['result'] as $update) {
        $candidates = [];
        if (isset($update['message']['from'])) {
            $candidates[] = $update['message']['from'];
        }
        if (isset($update['message']['new_chat_members']) && is_array($update['message']['new_chat_members'])) {
            foreach ($update['message']['new_chat_members'] as $member) {
                $candidates[] = $member;
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($candidate['username']) && isset($candidate['id'])) {
                $map[strtolower($candidate['username'])] = (string)$candidate['id'];
            }
        }
    }
}

$customersMap = [];
$customersPath = __DIR__ . '/data/customers.xml';
if (file_exists($customersPath)) {
    $custXml = @simplexml_load_file($customersPath);
    if ($custXml) {
        foreach ($custXml->customer as $cust) {
            $username = strtolower((string)$cust->username);
            $userId = (string)$cust->telegram_user_id;
            if ($username !== '' && $userId !== '') {
                $customersMap[$username] = $userId;
            }
        }
    }
}

$people = enlil_people_all();
$assigned = [];
$existingById = [];
$peopleById = [];
foreach ($people as $p) {
    $peopleById[(int)$p['id']] = $p['name'];
    $currentId = (string)($p['telegram_user_id'] ?? '');
    if ($currentId !== '') {
        $existingById[$currentId] = (int)$p['id'];
    }
}
$updated = 0;
$skipped = [];
foreach ($people as $p) {
    $username = ltrim((string)$p['telegram_user'], '@');
    $key = strtolower($username);
    if ($key === '') {
        continue;
    }
    $newId = '';
    if (isset($map[$key])) {
        $newId = $map[$key];
    } elseif (isset($customersMap[$key])) {
        $newId = $customersMap[$key];
    } else {
        $closest = '';
        foreach (array_keys($customersMap) as $candidate) {
            if (function_exists('levenshtein') && levenshtein($key, $candidate) <= 1) {
                $closest = $candidate;
                break;
            }
        }
        if ($closest !== '') {
            $skipped[] = $p['name'] . ' → no coincide el usuario (@' . $username . '). ¿Quizá @' . $closest . '?';
        }
        continue;
    }
    $personId = (int)$p['id'];
    if (isset($existingById[$newId]) && $existingById[$newId] !== $personId) {
        $otherName = $peopleById[$existingById[$newId]] ?? 'otra persona';
        $skipped[] = $p['name'] . ' → ID duplicado ' . $newId . ' (ya en ' . $otherName . ')';
        continue;
    }
    if (in_array($newId, $assigned, true)) {
        $skipped[] = $p['name'] . ' → ID duplicado ' . $newId;
        continue;
    }
    enlil_people_update_telegram_id($personId, $newId);
    $assigned[] = $newId;
    $existingById[$newId] = $personId;
    $updated++;
}

$_SESSION['flash_success'] = 'IDs actualizados: ' . $updated . '.';
if ($skipped) {
    $_SESSION['flash_error'] = 'Omitidos: ' . implode(' | ', $skipped);
}

header('Location: /personas_list.php');
exit;
