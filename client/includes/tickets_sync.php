<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../glpi_api.php';
require_once __DIR__ . '/../auth.php';

/** Cache TTL between full GLPI ticket scans (seconds). */
const TICKETS_SYNC_TTL = 1800;

/**
 * @return array{imported:int, cached:bool, total_local:int}
 */
function tickets_sync_from_glpi(int $localUserId, bool $force = false): array
{
    $result = ['imported' => 0, 'cached' => false, 'total_local' => 0];

    if ($localUserId <= 0 || !glpi_is_configured()) {
        return $result;
    }

    $countStmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE user_id = :uid');
    $countStmt->execute(['uid' => $localUserId]);
    $result['total_local'] = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare('SELECT glpi_user_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $localUserId]);
    $row = $stmt->fetch();
    $glpiUserId = $row ? (int) ($row['glpi_user_id'] ?? 0) : 0;
    if ($glpiUserId <= 0) {
        return $result;
    }

    $remoteTickets = null;
    if (!$force) {
        $remoteTickets = tickets_sync_read_cache($localUserId, $glpiUserId);
        if ($remoteTickets !== null) {
            $result['cached'] = true;
        }
    }

    if ($remoteTickets === null) {
        start_secure_session();
        $cacheKey = 'glpi_tickets_sync_' . $localUserId;
        $cached = $_SESSION[$cacheKey] ?? null;
        if (
            !$force
            && is_array($cached)
            && (int) ($cached['glpi_user_id'] ?? 0) === $glpiUserId
            && (int) ($cached['expires_at'] ?? 0) > time()
            && is_array($cached['tickets'] ?? null)
        ) {
            $remoteTickets = $cached['tickets'];
            $result['cached'] = true;
        }
    }

    if ($remoteTickets === null) {
        $knownGlpiIds = [];
        $knownStmt = db()->prepare('SELECT glpi_ticket_id FROM tickets WHERE user_id = :uid AND glpi_ticket_id IS NOT NULL');
        $knownStmt->execute(['uid' => $localUserId]);
        foreach ($knownStmt->fetchAll() ?: [] as $knownRow) {
            $gid = (int) ($knownRow['glpi_ticket_id'] ?? 0);
            if ($gid > 0) {
                $knownGlpiIds[$gid] = $gid;
            }
        }

        try {
            $remoteTickets = glpi_list_user_tickets($glpiUserId, array_values($knownGlpiIds));
        } catch (Throwable $e) {
            error_log('[tickets_sync] user=' . $localUserId . ' ' . $e::class . ': ' . $e->getMessage());
            return $result;
        }

        tickets_sync_write_cache($localUserId, $glpiUserId, $remoteTickets);

        start_secure_session();
        $_SESSION['glpi_tickets_sync_' . $localUserId] = [
            'glpi_user_id' => $glpiUserId,
            'expires_at' => time() + TICKETS_SYNC_TTL,
            'tickets' => $remoteTickets,
        ];
    }

    if ($remoteTickets === []) {
        return $result;
    }

    $pdo = db();
    $upsert = $pdo->prepare(
        'INSERT INTO tickets (user_id, glpi_ticket_id, subject, status, created_at, last_synced_at)
         VALUES (:user_id, :glpi_ticket_id, :subject, :status, :created_at, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            subject = VALUES(subject),
            status = VALUES(status),
            last_synced_at = UTC_TIMESTAMP()'
    );

    foreach ($remoteTickets as $ticket) {
        $glpiTicketId = (int) ($ticket['glpi_ticket_id'] ?? 0);
        if ($glpiTicketId <= 0) {
            continue;
        }

        $upsert->execute([
            'user_id' => $localUserId,
            'glpi_ticket_id' => $glpiTicketId,
            'subject' => (string) ($ticket['subject'] ?? 'Ticket'),
            'status' => (string) ($ticket['status'] ?? 'open'),
            'created_at' => (string) ($ticket['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ]);
        $result['imported'] += (int) $upsert->rowCount();
    }

    $countStmt->execute(['uid' => $localUserId]);
    $result['total_local'] = (int) $countStmt->fetchColumn();

    return $result;
}

function tickets_sync_cache_dir(): string
{
    $dir = __DIR__ . '/../cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function tickets_sync_cache_path(int $localUserId): string
{
    return tickets_sync_cache_dir() . '/glpi_tickets_' . $localUserId . '.json';
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function tickets_sync_read_cache(int $localUserId, int $glpiUserId): ?array
{
    $path = tickets_sync_cache_path($localUserId);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    if ((int) ($data['glpi_user_id'] ?? 0) !== $glpiUserId) {
        return null;
    }

    if ((int) ($data['expires_at'] ?? 0) <= time()) {
        return null;
    }

    $tickets = $data['tickets'] ?? null;
    return is_array($tickets) ? $tickets : null;
}

/**
 * @param array<int, array<string, mixed>> $tickets
 */
function tickets_sync_write_cache(int $localUserId, int $glpiUserId, array $tickets): void
{
    $payload = json_encode([
        'glpi_user_id' => $glpiUserId,
        'expires_at' => time() + TICKETS_SYNC_TTL,
        'tickets' => $tickets,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return;
    }

    file_put_contents(tickets_sync_cache_path($localUserId), $payload, LOCK_EX);
}