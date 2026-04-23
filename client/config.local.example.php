<?php
declare(strict_types=1);

/**
 * Local configuration overrides (XAMPP).
 *
 * Copy this file to `client/config.local.php` and adjust values.
 * This override file is ignored by git.
 */

return [
    // Where the client area is hosted in Apache (XAMPP)
    'APP_BASE_PATH' => '/site_lockbits/client',

    // Local DB (XAMPP MySQL)
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'lockbits_client',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // GLPI UI
    'GLPI_WEB_URL' => 'https://glpi.lockbits.pro',

    // --- Use ONE of the following ---

    // Option A: Legacy REST API (v1)
    // 'GLPI_API_URL' => 'https://glpi.lockbits.pro/apirest.php',
    // 'GLPI_APP_TOKEN' => '...',
    // 'GLPI_USER_TOKEN' => '...',

    // Option B: RESTful API v2 (OAuth2)
    'GLPI_API_URL' => 'https://glpi.lockbits.pro/api.php/v2.2',
    'GLPI_OAUTH_CLIENT_ID' => '...',
    'GLPI_OAUTH_CLIENT_SECRET' => '...',
    'GLPI_API_USERNAME' => 'api-bot',
    'GLPI_API_PASSWORD' => '...',
];

