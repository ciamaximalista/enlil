<?php
require_once __DIR__ . '/db.php';

function enlil_start_session(): void {
    $config = require __DIR__ . '/config.php';
    if (session_status() === PHP_SESSION_NONE) {
        if (!empty($config['session_path'])) {
            if (!is_dir($config['session_path'])) {
                mkdir($config['session_path'], 0770, true);
            }
            session_save_path($config['session_path']);
        }
        session_name($config['session_name']);
        session_start();
    }
}

function enlil_is_logged_in(): bool {
    enlil_start_session();
    return isset($_SESSION['admin_id']);
}

function enlil_require_login(): void {
    if (!enlil_is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function enlil_login(string $username, string $password): bool {
    $admin = enlil_admin_get();
    if (!$admin || strcasecmp($admin['username'], $username) !== 0) {
        return false;
    }
    if (!password_verify($password, $admin['password_hash'])) {
        return false;
    }

    enlil_start_session();
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = $admin['username'];
    return true;
}

function enlil_logout(): void {
    enlil_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
