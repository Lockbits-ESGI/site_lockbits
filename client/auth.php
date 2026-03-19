<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false,
    ]);

    session_start();
}

function redirect(string $path): void
{
    header('Location: ' . APP_BASE_PATH . $path);
    exit;
}

function is_logged_in(): bool
{
    start_secure_session();
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function login_user(array $user): void
{
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
    ];
}

function logout_user(): void
{
    start_secure_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], false, true);
    }

    session_destroy();
}

function current_user(): ?array
{
    start_secure_session();
    return $_SESSION['user'] ?? null;
}
