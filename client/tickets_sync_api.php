<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/tickets_sync.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = current_user();
$localUserId = (int) ($user['id'] ?? 0);
$force = isset($_GET['refresh']);

if (!glpi_is_configured()) {
    echo json_encode(['ok' => true, 'configured' => false, 'reload' => false]);
    exit;
}

$countStmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE user_id = :uid');
$countStmt->execute(['uid' => $localUserId]);
$before = (int) $countStmt->fetchColumn();

$sync = tickets_sync_from_glpi($localUserId, $force);

$reload = !$sync['cached'] && $sync['total_local'] !== $before;

echo json_encode([
    'ok' => true,
    'configured' => true,
    'cached' => $sync['cached'],
    'total' => $sync['total_local'],
    'reload' => $reload,
]);
