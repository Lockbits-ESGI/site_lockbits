<?php
declare(strict_types=1);

/**
 * Database configuration.
 * Uses environment variables (Docker) with fallback to local defaults (XAMPP).
 */

// Optional local overrides (XAMPP-friendly). This file is ignored by git.
// It must return an array like: ['APP_BASE_PATH' => '/site_lockbits/client', 'DB_HOST' => '127.0.0.1', ...]
$localOverridesPath = __DIR__ . '/config.local.php';
if (is_file($localOverridesPath)) {
    $overrides = require $localOverridesPath;
    if (is_array($overrides)) {
        foreach ($overrides as $key => $value) {
            if (is_string($key)) {
                putenv($key . '=' . (string) $value);
                $_ENV[$key] = (string) $value;
            }
        }
    }
}

// Read from environment variables with sensible defaults for local XAMPP setup
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'lockbits_client';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

// Define constants for backward compatibility
define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

// Application settings
define('APP_NAME', getenv('APP_NAME') ?: 'LockBits Client Area');
define('APP_ENV', getenv('APP_ENV') ?: 'development');

/**
 * Normalize APP_BASE_PATH to a path only (never a full http(s) URL).
 */
function app_normalize_base_path(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '/client';
    }

    if (preg_match('#^https?://#i', $raw) === 1) {
        $parts = parse_url($raw);
        $raw = (string) ($parts['path'] ?? '/client');
    }

    $raw = '/' . trim($raw, '/');
    return $raw === '/' ? '' : $raw;
}

define('APP_BASE_PATH', app_normalize_base_path((string) (getenv('APP_BASE_PATH') ?: '/client')));

/** True when the request is served over HTTPS (direct or via reverse proxy). */
function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded === 'https') {
        return true;
    }

    $forwardedSsl = (string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '');
    if ($forwardedSsl === 'on') {
        return true;
    }

    return (string) (getenv('APP_FORCE_HTTPS') ?: '') === '1';
}

// EDR Agent
define('EDR_SERVER_URL', (string) (getenv('EDR_SERVER_URL') ?: ''));
define('EDR_AUTH_TOKEN', (string) (getenv('EDR_AUTH_TOKEN') ?: ''));

// GLPI (REST API + UI redirect)
// Example:
//  - GLPI_API_URL=https://glpi.example.com/apirest.php
//  - GLPI_WEB_URL=https://glpi.example.com
define('GLPI_API_URL', rtrim((string) (getenv('GLPI_API_URL') ?: ''), '/'));
define('GLPI_WEB_URL', rtrim((string) (getenv('GLPI_WEB_URL') ?: ''), '/'));
define('GLPI_APP_TOKEN', (string) (getenv('GLPI_APP_TOKEN') ?: ''));
define('GLPI_USER_TOKEN', (string) (getenv('GLPI_USER_TOKEN') ?: ''));

// GLPI RESTful API v2 (OAuth2 Password Grant)
// Example:
//  - GLPI_API_URL=https://glpi.example.com/api.php/v2.2
//  - GLPI_OAUTH_CLIENT_ID=...
//  - GLPI_OAUTH_CLIENT_SECRET=...
//  - GLPI_API_USERNAME=api-bot
//  - GLPI_API_PASSWORD=...
define('GLPI_OAUTH_CLIENT_ID', (string) (getenv('GLPI_OAUTH_CLIENT_ID') ?: ''));
define('GLPI_OAUTH_CLIENT_SECRET', (string) (getenv('GLPI_OAUTH_CLIENT_SECRET') ?: ''));
define('GLPI_API_USERNAME', (string) (getenv('GLPI_API_USERNAME') ?: ''));
define('GLPI_API_PASSWORD', (string) (getenv('GLPI_API_PASSWORD') ?: ''));
define('GLPI_OAUTH_SCOPE', (string) (getenv('GLPI_OAUTH_SCOPE') ?: 'api user email'));
