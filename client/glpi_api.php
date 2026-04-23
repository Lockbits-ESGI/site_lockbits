<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class GlpiApiException extends RuntimeException
{
}

function glpi_is_not_found_error(Throwable $e): bool
{
    $msg = $e->getMessage();
    return str_contains($msg, ' HTTP 404 ') || str_contains($msg, 'HTTP 404');
}

/**
 * Normalize GLPI list responses to a plain array of items.
 *
 * GLPI may return either a plain array or an object wrapper (e.g. {data:[...]}).
 *
 * @return array<int, array<string, mixed>>
 */
function glpi_normalize_list(mixed $data): array
{
    if (!is_array($data)) {
        return [];
    }

    $candidates = [
        $data['data'] ?? null,
        $data['items'] ?? null,
        $data['results'] ?? null,
        $data['hydra:member'] ?? null,
        $data['member'] ?? null,
    ];

    foreach ($candidates as $cand) {
        if (is_array($cand)) {
            return $cand;
        }
    }

    // If it's already a sequential array, keep as-is.
    $keys = array_keys($data);
    $isSequential = ($keys === array_keys($keys));
    if ($isSequential) {
        return $data;
    }

    // Last resort: if it contains a single nested array, return it.
    foreach ($data as $v) {
        if (is_array($v)) {
            return $v;
        }
    }

    return [];
}

function glpi_is_configured(): bool
{
    if (GLPI_API_URL === '') {
        return false;
    }

    // Legacy REST API (apirest.php)
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        return GLPI_APP_TOKEN !== '' && GLPI_USER_TOKEN !== '';
    }

    // RESTful API v2 (api.php/v2.x) with OAuth2
    if (str_contains(GLPI_API_URL, 'api.php')) {
        return GLPI_OAUTH_CLIENT_ID !== '' && GLPI_OAUTH_CLIENT_SECRET !== '' && GLPI_API_USERNAME !== '' && GLPI_API_PASSWORD !== '';
    }

    return false;
}

/**
 * @return array{status:int, data:mixed, raw:string}
 */
function glpi_http(string $method, string $url, array $headers, ?array $jsonBody = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new GlpiApiException('Unable to initialize HTTP client.');
    }

    $finalHeaders = array_merge(['Accept: application/json'], $headers);
    if ($jsonBody !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_TIMEOUT => 15,
    ];

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new GlpiApiException('Unable to encode JSON payload.');
        }
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new GlpiApiException('HTTP error: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = null;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    return ['status' => $status, 'data' => $data, 'raw' => (string) $raw];
}

/**
 * @return array{status:int, data:mixed, raw:string}
 */
function glpi_http_form(string $url, array $headers, array $formBody): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new GlpiApiException('Unable to initialize HTTP client.');
    }

    $finalHeaders = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);
    $payload = http_build_query($formBody, '', '&');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new GlpiApiException('HTTP error: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = null;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    return ['status' => $status, 'data' => $data, 'raw' => (string) $raw];
}

function glpi_oauth_token_url(): string
{
    // If GLPI_API_URL is https://host/api.php/v2.2, token endpoint is https://host/api.php/token
    $url = GLPI_API_URL;
    $pos = strpos($url, '/api.php');
    if ($pos === false) {
        throw new GlpiApiException('GLPI_API_URL does not look like a valid api.php URL.');
    }

    $base = substr($url, 0, $pos);
    return rtrim($base, '/') . '/api.php/token';
}

function glpi_v2_access_token(): string
{
    static $token = null;
    static $expiresAt = 0;

    $now = time();
    if (is_string($token) && $token !== '' && $expiresAt > ($now + 30)) {
        return $token;
    }

    if (!glpi_is_configured()) {
        throw new GlpiApiException('GLPI is not configured (missing GLPI_* env vars).');
    }

    $res = glpi_http_form(glpi_oauth_token_url(), [], [
        'grant_type' => 'password',
        'client_id' => GLPI_OAUTH_CLIENT_ID,
        'client_secret' => GLPI_OAUTH_CLIENT_SECRET,
        'username' => GLPI_API_USERNAME,
        'password' => GLPI_API_PASSWORD,
        'scope' => (defined('GLPI_OAUTH_SCOPE') && GLPI_OAUTH_SCOPE !== '') ? GLPI_OAUTH_SCOPE : 'api',
    ]);

    if ($res['status'] !== 200 || !is_array($res['data']) || !is_string($res['data']['access_token'] ?? null)) {
        throw new GlpiApiException('GLPI OAuth token request failed: HTTP ' . $res['status'] . ' ' . $res['raw']);
    }

    $token = $res['data']['access_token'];
    $expiresIn = (int) ($res['data']['expires_in'] ?? 3600);
    $expiresAt = $now + max(60, $expiresIn);

    return $token;
}

function glpi_init_session(): string
{
    static $sessionToken = null;
    if (is_string($sessionToken) && $sessionToken !== '') {
        return $sessionToken;
    }

    if (!glpi_is_configured()) {
        throw new GlpiApiException('GLPI is not configured (missing GLPI_* env vars).');
    }

    if (!str_contains(GLPI_API_URL, 'apirest.php')) {
        throw new GlpiApiException('initSession is only available on the legacy REST API (apirest.php).');
    }

    $res = glpi_http('GET', GLPI_API_URL . '/initSession', [
        'App-Token: ' . GLPI_APP_TOKEN,
        'Authorization: user_token ' . GLPI_USER_TOKEN,
    ]);

    if ($res['status'] !== 200 || !is_array($res['data']) || !is_string($res['data']['session_token'] ?? null)) {
        throw new GlpiApiException('GLPI initSession failed: HTTP ' . $res['status'] . ' ' . $res['raw']);
    }

    $sessionToken = $res['data']['session_token'];
    return $sessionToken;
}

/**
 * Generic GLPI REST call.
 *
 * @return mixed decoded JSON (array) or null if response isn’t JSON.
 */
function glpi_call(string $method, string $path, ?array $jsonBody = null, bool $sessionWrite = false)
{
    // Legacy REST API
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $sessionToken = glpi_init_session();

        $url = GLPI_API_URL . '/' . ltrim($path, '/');
        if ($sessionWrite) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'session_write=true';
        }

        $res = glpi_http($method, $url, [
            'App-Token: ' . GLPI_APP_TOKEN,
            'Session-Token: ' . $sessionToken,
        ], $jsonBody);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new GlpiApiException('GLPI call failed: ' . $method . ' ' . $path . ' HTTP ' . $res['status'] . ' ' . $res['raw']);
        }

        return $res['data'];
    }

    // RESTful API v2 (OAuth2 Bearer token)
    if (str_contains(GLPI_API_URL, 'api.php')) {
        $token = glpi_v2_access_token();
        $url = GLPI_API_URL . '/' . ltrim($path, '/');

        $res = glpi_http($method, $url, [
            'Authorization: Bearer ' . $token,
        ], $jsonBody);

        // v2 endpoints may return 206 Partial Content for paginated results.
        if ($res['status'] < 200 || $res['status'] >= 300) {
            if ($res['status'] === 206 && is_array($res['data'])) {
                return $res['data'];
            }
            throw new GlpiApiException('GLPI call failed: ' . $method . ' ' . $path . ' HTTP ' . $res['status'] . ' ' . $res['raw']);
        }

        return $res['data'];
    }

    throw new GlpiApiException('Unsupported GLPI_API_URL format.');
}

/**
 * Create a GLPI user and return its GLPI id.
 *
 * Note: Field requirements depend on your GLPI configuration/profiles.
 */
function glpi_create_user(string $fullName, string $email, string $password): int
{
    // Legacy REST API (v1)
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $login = $email;
        $firstName = '';
        $lastName = $fullName;

        $payload = [
            'input' => [
                'name' => $login,
                'firstname' => $firstName,
                'realname' => $lastName,
                'email' => $email,
                'password' => $password,
            ],
        ];

        $data = glpi_call('POST', '/User', $payload, true);
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (!is_int($id)) {
            if (is_string($id) && ctype_digit($id)) {
                return (int) $id;
            }
            throw new GlpiApiException('GLPI user creation returned an unexpected payload.');
        }
        return $id;
    }

    // RESTful API v2 (OAuth2)
    $payload = [
        'username' => $email,
        'realname' => $fullName,
        'firstname' => '',
        'is_active' => true,
        'password' => $password,
        'password2' => $password,
        'emails' => [
            [
                'email' => $email,
                'is_default' => true,
            ],
        ],
    ];

    $data = glpi_call('POST', '/Administration/User', $payload);
    if (!is_array($data) || !isset($data['id'])) {
        throw new GlpiApiException('GLPI user creation returned an unexpected payload.');
    }
    return (int) $data['id'];
}

/**
 * Create a GLPI ticket and return its id.
 */
function glpi_create_ticket(string $title, string $content, int $requesterUserId): int
{
    // Legacy REST API (v1)
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $payload = [
            'input' => [
                'name' => $title,
                'content' => $content,
                '_users_id_requester' => $requesterUserId,
            ],
        ];

        $data = glpi_call('POST', '/Ticket', $payload, true);
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (!is_int($id)) {
            if (is_string($id) && ctype_digit($id)) {
                return (int) $id;
            }
            throw new GlpiApiException('GLPI ticket creation returned an unexpected payload.');
        }
        return $id;
    }

    // RESTful API v2 (OAuth2)
    $payload = [
        'name' => $title,
        'content' => $content,
        'urgency' => 3,
        'impact' => 3,
        'priority' => 3,
        'user_recipient' => [
            'id' => $requesterUserId,
        ],
    ];

    $data = glpi_call('POST', '/Assistance/Ticket', $payload);
    if (!is_array($data) || !isset($data['id'])) {
        throw new GlpiApiException('GLPI ticket creation returned an unexpected payload.');
    }
    return (int) $data['id'];
}

function glpi_ticket_url(int $ticketId): string
{
    if (GLPI_WEB_URL === '') {
        throw new GlpiApiException('GLPI_WEB_URL is not configured (needed for redirect).');
    }

    return GLPI_WEB_URL . '/front/ticket.form.php?id=' . $ticketId;
}

/**
 * Fetch followups/messages of a ticket.
 *
 * Tries a few known high-level API paths to stay compatible across GLPI versions/config.
 *
 * @return array<int, array<string, mixed>>
 */
function glpi_get_ticket_followups(int $ticketId): array
{
    $paths = [];

    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $paths = [
            '/Ticket/' . $ticketId . '/ITILFollowup',
            '/Ticket/' . $ticketId . '/TicketFollowup',
        ];
    } else {
        // High-level API v2.x (paths vary slightly across versions)
        $paths = [
            '/Assistance/Ticket/' . $ticketId . '/Timeline/Followup',
        ];
    }

    $lastError = null;
    foreach ($paths as $path) {
        try {
            $data = glpi_call('GET', $path);
            return glpi_normalize_list($data);
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    throw new GlpiApiException('Unable to fetch ticket followups from GLPI.' . ($lastError ? (' ' . $lastError->getMessage()) : ''));
}

/**
 * Fetch solutions from the ticket timeline.
 *
 * @return array<int, array<string, mixed>>
 */
function glpi_get_ticket_solutions(int $ticketId): array
{
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        // Legacy API uses ITILSolution sub-item endpoint
        try {
            $data = glpi_call('GET', '/Ticket/' . $ticketId . '/ITILSolution');
            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            throw new GlpiApiException('Unable to fetch ticket solutions from GLPI. ' . $e->getMessage());
        }
    }

    $data = glpi_call('GET', '/Assistance/Ticket/' . $ticketId . '/Timeline/Solution');
    return glpi_normalize_list($data);
}

/**
 * Fetch tasks from the ticket timeline.
 *
 * @return array<int, array<string, mixed>>
 */
function glpi_get_ticket_tasks(int $ticketId): array
{
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        try {
            $data = glpi_call('GET', '/Ticket/' . $ticketId . '/TicketTask');
            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            throw new GlpiApiException('Unable to fetch ticket tasks from GLPI. ' . $e->getMessage());
        }
    }

    $data = glpi_call('GET', '/Assistance/Ticket/' . $ticketId . '/Timeline/Task');
    return glpi_normalize_list($data);
}

/**
 * Fetch a ticket and return decoded JSON.
 *
 * @return array<string, mixed>
 */
function glpi_get_ticket(int $ticketId): array
{
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $data = glpi_call('GET', '/Ticket/' . $ticketId);
        return is_array($data) ? $data : [];
    }

    $data = glpi_call('GET', '/Assistance/Ticket/' . $ticketId);
    return is_array($data) ? $data : [];
}

/**
 * Map GLPI ticket status to local enum.
 */
function glpi_map_ticket_status_to_local($glpiStatus): string
{
    // v2: status is an object {id, name}
    $id = null;
    if (is_array($glpiStatus) && isset($glpiStatus['id'])) {
        $id = (int) $glpiStatus['id'];
    } elseif (is_int($glpiStatus) || (is_string($glpiStatus) && ctype_digit($glpiStatus))) {
        $id = (int) $glpiStatus;
    }

    // Default: open
    if (!is_int($id) || $id <= 0) {
        return 'open';
    }

    // GLPI statuses:
    // 1 New, 10 Approval, 2/3 Processing, 4 Pending, 5 Solved, 6 Closed
    return match ($id) {
        5, 6 => 'closed',
        2, 3, 4 => 'in_progress',
        default => 'open',
    };
}

/**
 * Add a followup/message to a ticket.
 */
function glpi_add_ticket_followup(int $ticketId, string $content, bool $isPrivate = false): int
{
    $payloads = [];

    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $payloads = [
            [
                'path' => '/Ticket/' . $ticketId . '/ITILFollowup',
                'body' => [
                    'input' => [
                        'itemtype' => 'Ticket',
                        'items_id' => (string) $ticketId,
                        'is_private' => $isPrivate ? '1' : '0',
                        'requesttypes_id' => '6',
                        'content' => $content,
                    ],
                ],
            ],
            [
                'path' => '/Ticket/' . $ticketId . '/TicketFollowup',
                'body' => [
                    'input' => [
                        'tickets_id' => (string) $ticketId,
                        'is_private' => $isPrivate ? '1' : '0',
                        'requesttypes_id' => '6',
                        'content' => $content,
                    ],
                ],
            ],
        ];
    } else {
        $payloads = [
            [
                'path' => '/Assistance/Ticket/' . $ticketId . '/Timeline/Followup',
                'body' => [
                    'itemtype' => 'Ticket',
                    'items_id' => $ticketId,
                    'content' => $content,
                    'is_private' => $isPrivate,
                ],
            ],
        ];
    }

    $lastError = null;
    foreach ($payloads as $attempt) {
        try {
            $data = glpi_call('POST', (string) $attempt['path'], (array) $attempt['body']);
            if (is_array($data) && isset($data['id'])) {
                return (int) $data['id'];
            }
            // Legacy API often returns array with id in [0]['id'] or similar; keep best-effort.
            if (is_array($data) && isset($data[0]['id'])) {
                return (int) $data[0]['id'];
            }
            return 0;
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    throw new GlpiApiException('Unable to add followup to ticket in GLPI.' . ($lastError ? (' ' . $lastError->getMessage()) : ''));
}

/**
 * Fetch one timeline followup item (v2) and return the inner item array.
 *
 * @return array<string, mixed>
 */
function glpi_get_ticket_followup_item(int $ticketId, int $followupId): array
{
    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        // Best-effort: legacy endpoints vary; keep empty for now.
        return [];
    }

    $data = glpi_call('GET', '/Assistance/Ticket/' . $ticketId . '/Timeline/Followup/' . $followupId);
    if (!is_array($data)) {
        return [];
    }

    // Can be {type:"Followup", item:{...}} or directly the item.
    if (is_array($data['item'] ?? null)) {
        return $data['item'];
    }
    return $data;
}

