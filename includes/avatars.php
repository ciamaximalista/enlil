<?php
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/teams.php';
require_once __DIR__ . '/bot.php';

function enlil_avatar_dir(): string {
    return __DIR__ . '/../data/avatars';
}

function enlil_avatar_path(string $userId): string {
    return enlil_avatar_dir() . '/' . $userId . '.jpg';
}

function enlil_avatar_url(string $userId): string {
    return '/data/avatars/' . rawurlencode($userId) . '.jpg';
}

function enlil_avatar_refresh_for_person(array $person, array $teamsById, ?string &$error = null): bool {
    $userId = trim((string)($person['telegram_user_id'] ?? ''));
    if ($userId === '' || !ctype_digit($userId)) {
        $error = 'sin telegram_user_id';
        return false;
    }

    $token = enlil_bot_token();

    if ($token === '') {
        $error = 'sin token de bot';
        return false;
    }

    $photos = enlil_telegram_get($token, 'getUserProfilePhotos', [
        'user_id' => $userId,
        'limit' => 1,
    ]);
    if (!$photos['ok']) {
        $error = 'getUserProfilePhotos HTTP ' . ($photos['http_code'] ?: 'sin respuesta');
        if (is_string($photos['body'])) {
            $data = json_decode($photos['body'], true);
            if (is_array($data) && isset($data['description'])) {
                $error .= ' - ' . $data['description'];
            }
        }
        return false;
    }

    $data = json_decode($photos['body'], true);
    if (!is_array($data) || !isset($data['result']['photos'][0][0]['file_id'])) {
        $error = 'sin fotos disponibles';
        return false;
    }

    $fileId = $data['result']['photos'][0][0]['file_id'];
    $fileInfo = enlil_telegram_get($token, 'getFile', [
        'file_id' => $fileId,
    ]);
    if (!$fileInfo['ok']) {
        $error = 'getFile HTTP ' . ($fileInfo['http_code'] ?: 'sin respuesta');
        if (is_string($fileInfo['body'])) {
            $data = json_decode($fileInfo['body'], true);
            if (is_array($data) && isset($data['description'])) {
                $error .= ' - ' . $data['description'];
            }
        }
        return false;
    }

    $fileData = json_decode($fileInfo['body'], true);
    if (!is_array($fileData) || !isset($fileData['result']['file_path'])) {
        $error = 'file_path no disponible';
        return false;
    }

    $filePath = $fileData['result']['file_path'];
    $fileUrl = 'https://api.telegram.org/file/bot' . $token . '/' . $filePath;
    $image = @file_get_contents($fileUrl);
    if ($image === false) {
        $error = 'descarga fallida del archivo';
        return false;
    }

    $dir = enlil_avatar_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    $dest = enlil_avatar_path($userId);
    file_put_contents($dest, $image);
    chmod($dest, 0660);
    return true;
}

function enlil_avatar_refresh_all(): array {
    $people = enlil_people_all();
    $teams = enlil_teams_all();
    $teamsById = [];
    foreach ($teams as $team) {
        $teamsById[$team['id']] = $team;
    }

    $ok = 0;
    $fail = 0;
    $errors = [];
    foreach ($people as $person) {
        $err = null;
        if (enlil_avatar_refresh_for_person($person, $teamsById, $err)) {
            $ok++;
        } else {
            $fail++;
            $errors[] = $person['name'] . ' (' . ($person['telegram_user'] ?? '') . '): ' . ($err ?? 'error desconocido');
        }
    }

    return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
}
