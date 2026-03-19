<?php
declare(strict_types=1);

/**
 * Database configuration.
 * Uses environment variables (Docker) with fallback to local defaults (XAMPP).
 */

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
define('APP_BASE_PATH', '/lockbits/client');
