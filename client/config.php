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
// Base path of this client area in your web server (examples: "/client", "/site_lockbits/client", "").
define('APP_BASE_PATH', rtrim((string) (getenv('APP_BASE_PATH') ?: '/site_lockbits/client'), '/'));

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
