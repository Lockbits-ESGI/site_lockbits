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
 * Parse GLPI legacy search API response rows.
 *
 * @return array<int, array<string, mixed>>
 */
function glpi_parse_search_rows(mixed $data): array
{
    if (!is_array($data)) {
        return [];
    }

    $rows = $data['data'] ?? $data;
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Run a legacy search (apirest.php) and return raw rows.
 *
 * @param array<int, array<string, mixed>> $criteria
 * @return array<int, array<string, mixed>>
 */
function glpi_legacy_search(string $itemtype, array $criteria, array $forcedisplay = []): array
{
    $query = [];
    foreach ($criteria as $i => $c) {
        foreach ($c as $key => $value) {
            $query['criteria[' . $i . '][' . $key . ']'] = $value;
        }
    }
    foreach ($forcedisplay as $i => $fieldId) {
        $query['forcedisplay[' . $i . ']'] = $fieldId;
    }

    $path = '/search/' . $itemtype;
    if ($query !== []) {
        $path .= '?' . http_build_query($query);
    }

    $data = glpi_call('GET', $path);
    return glpi_parse_search_rows($data);
}

/**
 * Extract ticket IDs from Ticket_User search rows (field keys vary by GLPI version).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, int>
 */
function glpi_extract_ticket_ids_from_ticket_user_rows(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (isset($row['tickets_id']) && is_numeric($row['tickets_id'])) {
            $ids[(int) $row['tickets_id']] = (int) $row['tickets_id'];
            continue;
        }
        foreach ($row as $key => $value) {
            if (is_string($key) && str_contains(strtolower($key), 'ticket') && is_numeric($value)) {
                $ids[(int) $value] = (int) $value;
            }
        }
        // Legacy search often returns numeric field ids (e.g. "2" => tickets_id).
        if (isset($row['2']) && is_numeric($row['2'])) {
            $ids[(int) $row['2']] = (int) $row['2'];
        }
    }

    return array_values($ids);
}

/**
 * Normalize a GLPI ticket payload for local storage.
 *
 * @param array<string, mixed> $ticket
 * @return array{glpi_ticket_id:int, subject:string, status:string, created_at:string}|null
 */
function glpi_normalize_ticket_summary(array $ticket): ?array
{
    $id = (int) ($ticket['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $subject = trim((string) ($ticket['name'] ?? $ticket['title'] ?? ''));
    if ($subject === '') {
        $subject = 'Ticket #' . $id;
    }

    $rawDate = (string) ($ticket['date_creation'] ?? $ticket['date'] ?? $ticket['created_at'] ?? '');
    $createdAt = gmdate('Y-m-d H:i:s');
    if ($rawDate !== '') {
        try {
            $dt = new DateTimeImmutable($rawDate);
            $createdAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            // keep fallback
        }
    }

    return [
        'glpi_ticket_id' => $id,
        'subject' => $subject,
        'status' => glpi_map_ticket_status_to_local($ticket['status'] ?? null),
        'created_at' => $createdAt,
    ];
}

/**
 * List tickets linked to a GLPI user (requester, observer, or assigned).
 *
 * @return array<int, array{glpi_ticket_id:int, subject:string, status:string, created_at:string}>
 */
function glpi_list_user_tickets(int $glpiUserId, array $knownGlpiTicketIds = []): array
{
    if ($glpiUserId <= 0) {
        return [];
    }

    if (str_contains(GLPI_API_URL, 'apirest.php')) {
        $ticketIds = glpi_list_user_ticket_ids_legacy($glpiUserId);
        $ticketIds = array_values(array_unique(array_filter($ticketIds, static fn(int $id): bool => $id > 0)));
        if ($ticketIds === []) {
            return [];
        }

        $summaries = [];
        foreach ($ticketIds as $ticketId) {
            try {
                $ticket = glpi_get_ticket($ticketId);
                $summary = glpi_normalize_ticket_summary($ticket);
                if ($summary !== null) {
                    $summaries[$summary['glpi_ticket_id']] = $summary;
                }
            } catch (Throwable $e) {
                error_log('[glpi_list_user_tickets] ticket=' . $ticketId . ' ' . $e->getMessage());
            }
        }

        return array_values($summaries);
    }

    return glpi_list_user_tickets_v2($glpiUserId, $knownGlpiTicketIds);
}

/**
 * @return array<int, int>
 */
function glpi_list_user_ticket_ids_legacy(int $glpiUserId): array
{
    $ids = [];

    // Ticket_User.users_id field id varies (2 or 5) depending on GLPI version.
    foreach ([5, 2, 3] as $usersFieldId) {
        try {
            $rows = glpi_legacy_search('Ticket_User', [
                [
                    'field' => $usersFieldId,
                    'searchtype' => 'equals',
                    'value' => $glpiUserId,
                ],
            ], [2]);
            $found = glpi_extract_ticket_ids_from_ticket_user_rows($rows);
            foreach ($found as $id) {
                $ids[$id] = $id;
            }
            if ($found !== []) {
                break;
            }
        } catch (Throwable) {
            // try next field id
        }
    }

    // Also search tickets where user is requester (field 4 = requester in many GLPI versions).
    foreach ([4, 5] as $requesterFieldId) {
        try {
            $rows = glpi_legacy_search('Ticket', [
                [
                    'field' => $requesterFieldId,
                    'searchtype' => 'equals',
                    'value' => $glpiUserId,
                ],
            ], [2]);
            foreach ($rows as $row) {
                if (isset($row['2']) && is_numeric($row['2'])) {
                    $ids[(int) $row['2']] = (int) $row['2'];
                }
                if (isset($row['id']) && is_numeric($row['id'])) {
                    $ids[(int) $row['id']] = (int) $row['id'];
                }
            }
        } catch (Throwable) {
            // try next field id
        }
    }

    return array_values($ids);
}

/**
 * GLPI v2: list tickets for a user. EDR/staff often add clients via `team`, not `user_recipient`.
 *
 * @return array<int, array{glpi_ticket_id:int, subject:string, status:string, created_at:string}>
 */
function glpi_list_user_tickets_v2(int $glpiUserId, array $knownGlpiTicketIds = []): array
{
    $summaries = [];
    $knownSet = [];
    $maxKnownId = 0;
    foreach ($knownGlpiTicketIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $knownSet[$id] = true;
            $maxKnownId = max($maxKnownId, $id);
        }
    }

    try {
        $path = '/Assistance/Ticket?filter=' . rawurlencode('user_recipient.id==' . $glpiUserId) . '&limit=50';
        $data = glpi_call('GET', $path);
        foreach (glpi_normalize_list($data) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $summary = glpi_normalize_ticket_summary($item);
            if ($summary !== null) {
                $summaries[$summary['glpi_ticket_id']] = $summary;
            }
        }
    } catch (Throwable) {
        // ignore
    }

    // One list request, then full detail only for recent tickets (team is not in list payload).
    $allIds = [];
    try {
        $data = glpi_call('GET', '/Assistance/Ticket?limit=100');
        foreach (glpi_normalize_list($data) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ticketId = (int) ($item['id'] ?? 0);
            if ($ticketId > 0) {
                $allIds[$ticketId] = $item;
            }
        }
    } catch (Throwable $e) {
        error_log('[glpi_list_user_tickets_v2] list ' . $e->getMessage());
        return array_values($summaries);
    }

    krsort($allIds, SORT_NUMERIC);

    // New tickets since last sync + 8 most recent (team assignments on recent tickets).
    $candidates = [];
    $recentCount = 0;
    foreach ($allIds as $ticketId => $listItem) {
        if ($ticketId > $maxKnownId) {
            $candidates[$ticketId] = $listItem;
        }
        if ($recentCount < 8) {
            $candidates[$ticketId] = $listItem;
            $recentCount++;
        }
    }

    $needFullFetch = [];
    foreach ($candidates as $ticketId => $listItem) {
        if (isset($summaries[$ticketId]) || isset($knownSet[$ticketId])) {
            continue;
        }
        $ticket = is_array($listItem) ? $listItem : [];
        if (glpi_ticket_involves_user($ticket, $glpiUserId)) {
            $summary = glpi_normalize_ticket_summary($ticket);
            if ($summary !== null) {
                $summaries[$summary['glpi_ticket_id']] = $summary;
            }
            continue;
        }
        $needFullFetch[] = $ticketId;
    }

    if ($needFullFetch !== []) {
        foreach (glpi_get_tickets_parallel($needFullFetch) as $ticketId => $ticket) {
            if (!glpi_ticket_involves_user($ticket, $glpiUserId)) {
                continue;
            }
            $summary = glpi_normalize_ticket_summary($ticket);
            if ($summary !== null) {
                $summaries[$summary['glpi_ticket_id']] = $summary;
            }
        }
    }

    return array_values($summaries);
}

/**
 * Fetch multiple tickets in parallel (GLPI v2 only).
 *
 * @param array<int, int> $ticketIds
 * @return array<int, array<string, mixed>>
 */
function glpi_get_tickets_parallel(array $ticketIds): array
{
    if ($ticketIds === [] || !str_contains(GLPI_API_URL, 'api.php')) {
        return [];
    }

    $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn(int $id): bool => $id > 0)));
    if ($ticketIds === []) {
        return [];
    }

    $token = glpi_v2_access_token();
    $baseUrl = rtrim(GLPI_API_URL, '/') . '/Assistance/Ticket/';
    $mh = curl_multi_init();
    if ($mh === false) {
        return [];
    }

    $handles = [];
    foreach ($ticketIds as $id) {
        $ch = curl_init($baseUrl . $id);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.4);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $id => $ch) {
        $raw = curl_multi_getcontent($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($httpStatus < 200 || $httpStatus >= 300 || $raw === false || $raw === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $results[$id] = $decoded;
        }
    }

    curl_multi_close($mh);

    return $results;
}

/**
 * Check if a GLPI v2 ticket involves the given user (requester, observer, assignee).
 *
 * @param array<string, mixed> $ticket
 */
function glpi_ticket_involves_user(array $ticket, int $glpiUserId): bool
{
    if ($glpiUserId <= 0) {
        return false;
    }

    $matchesId = static function (mixed $node) use ($glpiUserId): bool {
        if (!is_array($node)) {
            return is_numeric($node) && (int) $node === $glpiUserId;
        }
        if (isset($node['id']) && (int) $node['id'] === $glpiUserId) {
            return true;
        }
        if (isset($node['users_id']) && (int) $node['users_id'] === $glpiUserId) {
            return true;
        }
        return false;
    };

    if ($matchesId($ticket['user_recipient'] ?? null)) {
        return true;
    }

    $actors = $ticket['actors'] ?? null;
    if (is_array($actors)) {
        foreach (['requesters', 'assignees', 'observers', 'requester', 'assignee', 'observer'] as $key) {
            if (!isset($actors[$key])) {
                continue;
            }
            $group = $actors[$key];
            if ($matchesId($group)) {
                return true;
            }
            if (is_array($group)) {
                foreach ($group as $entry) {
                    if ($matchesId($entry)) {
                        return true;
                    }
                }
            }
        }
    }

    foreach (['users_id_recipient', '_users_id_requester', 'users_id_requester'] as $key) {
        if (isset($ticket[$key]) && (int) $ticket[$key] === $glpiUserId) {
            return true;
        }
    }

    // GLPI v2 full ticket: actors assigned via UI/EDR appear in `team`.
    $team = $ticket['team'] ?? null;
    if (is_array($team)) {
        foreach ($team as $member) {
            if (!is_array($member)) {
                continue;
            }
            if ((int) ($member['id'] ?? 0) === $glpiUserId) {
                return true;
            }
        }
    }

    return false;
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

