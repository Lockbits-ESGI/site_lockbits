<?php
declare(strict_types=1);

require_once __DIR__ . '/client/config.php';
require_once __DIR__ . '/client/db.php';

header('Content-Type: text/plain; version=0.0.4');

$dbHealth        = 0;
$usersTotal      = 0;
$ticketsByStatus = [];

try {
    $pdo  = db();
    $pdo->query('SELECT 1');
    $dbHealth = 1;

    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $usersTotal = (int) $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT status, COUNT(*) AS cnt FROM tickets GROUP BY status');
    foreach ($stmt->fetchAll() as $row) {
        $ticketsByStatus[$row['status']] = (int) $row['cnt'];
    }
} catch (PDOException $e) {
    $dbHealth        = 0;
    $usersTotal      = 0;
    $ticketsByStatus = [];
}

foreach (['open', 'in_progress', 'closed'] as $s) {
    if (!isset($ticketsByStatus[$s])) {
        $ticketsByStatus[$s] = 0;
    }
}

$phpMemoryBytes = memory_get_usage(true);

$uptimeFile = __DIR__ . '/.lockbits_start';
$startTime  = 0;

$uptimeRaw = @file_get_contents($uptimeFile);
if ($uptimeRaw !== false) {
    $startTime = (int) trim($uptimeRaw);
}
if ($startTime <= 0) {
    $startTime = (int) ($_SERVER['REQUEST_TIME'] ?? 0);
}
if ($startTime <= 0) {
    $startTime = time();
}

$phpUptimeSeconds = max(0, time() - $startTime);

$counterFile   = __DIR__ . '/metrics_counter.txt';
$requestsTotal = 0;

$fh = @fopen($counterFile, 'c+');
if ($fh !== false) {
    if (flock($fh, LOCK_SH)) {
        $raw = stream_get_contents($fh);
        if ($raw !== false && $raw !== '') {
            $requestsTotal = (int) trim($raw);
        }
        flock($fh, LOCK_UN);
    }

    if (flock($fh, LOCK_EX)) {
        $requestsTotal++;
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, (string) $requestsTotal);
        fflush($fh);
        flock($fh, LOCK_UN);
    }

    fclose($fh);
}

echo "# HELP lockbits_db_health Database health status (1=healthy, 0=down)\n";
echo "# TYPE lockbits_db_health gauge\n";
echo "lockbits_db_health {$dbHealth}\n";

echo "# HELP lockbits_users_total Total number of registered users\n";
echo "# TYPE lockbits_users_total gauge\n";
echo "lockbits_users_total {$usersTotal}\n";

echo "# HELP lockbits_tickets_total Number of tickets grouped by status\n";
echo "# TYPE lockbits_tickets_total gauge\n";
foreach ($ticketsByStatus as $status => $count) {
    echo sprintf("lockbits_tickets_total{status=\"%s\"} %d\n", $status, $count);
}

echo "# HELP lockbits_php_memory_bytes Current PHP memory usage in bytes\n";
echo "# TYPE lockbits_php_memory_bytes gauge\n";
echo "lockbits_php_memory_bytes {$phpMemoryBytes}\n";

echo "# HELP lockbits_php_uptime_seconds PHP uptime in seconds since container start\n";
echo "# TYPE lockbits_php_uptime_seconds gauge\n";
echo "lockbits_php_uptime_seconds {$phpUptimeSeconds}\n";

echo "# HELP lockbits_http_requests_total Total number of HTTP requests served\n";
echo "# TYPE lockbits_http_requests_total counter\n";
echo "lockbits_http_requests_total {$requestsTotal}\n";
