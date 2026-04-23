<?php
declare(strict_types=1);

require_once __DIR__ . '/glpi_api.php';

header('Content-Type: text/plain; charset=utf-8');

function jwt_payload(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }

    $b64 = strtr($parts[1], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($b64, true);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

echo "GLPI health check\n";
echo "----------------\n";
echo "APP_ENV=" . (defined('APP_ENV') ? APP_ENV : 'unknown') . "\n";
echo "GLPI_API_URL=" . (defined('GLPI_API_URL') ? GLPI_API_URL : '') . "\n";
echo "GLPI_WEB_URL=" . (defined('GLPI_WEB_URL') ? GLPI_WEB_URL : '') . "\n";
echo "requested_scope=" . (defined('GLPI_OAUTH_SCOPE') ? GLPI_OAUTH_SCOPE : '') . "\n";
echo "configured=" . (glpi_is_configured() ? 'yes' : 'no') . "\n\n";

if (!glpi_is_configured()) {
    echo "Missing config. Fill client/config.local.php (XAMPP) or env vars.\n";
    exit;
}

try {
    // OAuth token request (v2) or initSession (v1)
    if (str_contains(GLPI_API_URL, 'api.php')) {
        $token = glpi_v2_access_token();
        echo "OAuth token OK (len=" . strlen($token) . ")\n";
        $payload = jwt_payload($token);
        if (is_array($payload)) {
            if (isset($payload['scope'])) {
                echo "token_scope=" . (is_string($payload['scope']) ? $payload['scope'] : json_encode($payload['scope'])) . "\n";
            }
            if (isset($payload['scopes'])) {
                echo "token_scopes=" . (is_string($payload['scopes']) ? $payload['scopes'] : json_encode($payload['scopes'])) . "\n";
            }
        } else {
            echo "token_payload=unavailable\n";
        }
    } else {
        $session = glpi_init_session();
        echo "Legacy session OK (len=" . strlen($session) . ")\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e::class . ": " . $e->getMessage() . "\n";
}

