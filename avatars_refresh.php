<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/avatars.php';
require_once __DIR__ . '/includes/bot.php';

enlil_require_login();
enlil_start_session();

$stats = enlil_avatar_refresh_all();
$botInfo = enlil_bot_save(enlil_bot_token());
// prevent misleading refresh when duplicated Telegram IDs exist
$people = enlil_people_all();
$seen = [];
$dupes = [];
foreach ($people as $p) {
    $uid = (string)($p['telegram_user_id'] ?? '');
    if ($uid === '') {
        continue;
    }
    if (isset($seen[$uid])) {
        $dupes[$uid][] = $p['name'];
    } else {
        $seen[$uid] = [$p['name']];
        $dupes[$uid] = [$p['name']];
    }
}
foreach ($dupes as $uid => $names) {
    if (count($names) < 2) {
        unset($dupes[$uid]);
    }
}

if ($dupes) {
    $_SESSION['flash_error'] = 'No se actualizaron avatares porque hay IDs de Telegram duplicados. Corrige primero: ' .
        implode(' | ', array_map(function ($uid) use ($dupes) {
            return $uid . ' → ' . implode(', ', $dupes[$uid]);
        }, array_keys($dupes)));
} else {
    $_SESSION['flash_success'] = 'Avatares actualizados. OK: ' . $stats['ok'] . ', Fallos: ' . $stats['fail'] . '.';
    if (!empty($stats['errors'])) {
        $_SESSION['flash_error'] = implode(' | ', $stats['errors']);
    }
}
header('Location: /personas_list.php');
exit;
