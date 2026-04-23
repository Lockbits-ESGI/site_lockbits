<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/glpi_api.php';

require_login();
$user = current_user();

$tickets = [];
$setupWarning = '';

try {
    $stmt = db()->prepare('SELECT id, subject, status, created_at, last_synced_at FROM tickets WHERE user_id = :uid ORDER BY created_at DESC');
    $stmt->execute(['uid' => (int) ($user['id'] ?? 0)]);
    $tickets = $stmt->fetchAll() ?: [];
} catch (Throwable) {
    $setupWarning = 'Database setup incomplete: please import client/database.sql and apply migrations.';
}

// Best-effort: if staff deleted tickets in GLPI, remove them locally.
if ($setupWarning === '' && glpi_is_configured()) {
    $pdo = db();
    foreach ($tickets as $row) {
        $localId = (int) ($row['id'] ?? 0);
        if ($localId <= 0) {
            continue;
        }
        try {
            $stmt = $pdo->prepare('SELECT glpi_ticket_id FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmt->execute(['id' => $localId, 'uid' => (int) ($user['id'] ?? 0)]);
            $t = $stmt->fetch();
            $glpiTicketId = $t ? (int) ($t['glpi_ticket_id'] ?? 0) : 0;
            if ($glpiTicketId <= 0) {
                continue;
            }
            glpi_get_ticket($glpiTicketId);
        } catch (Throwable $e) {
            if (glpi_is_not_found_error($e)) {
                $pdo->prepare('DELETE FROM tickets WHERE id = :id AND user_id = :uid')->execute([
                    'id' => $localId,
                    'uid' => (int) ($user['id'] ?? 0),
                ]);
            }
        }
    }

    $stmt = $pdo->prepare('SELECT id, subject, status, created_at, last_synced_at FROM tickets WHERE user_id = :uid ORDER BY created_at DESC');
    $stmt->execute(['uid' => (int) ($user['id'] ?? 0)]);
    $tickets = $stmt->fetchAll() ?: [];
}

start_secure_session();
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes tickets - Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-black/30 backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <div>
                <p class="text-sm text-slate-400">Support</p>
                <h1 class="text-xl font-semibold text-emerald-300">Mes tickets</h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Dashboard</a>
                <a href="<?= APP_BASE_PATH ?>/create_ticket.php" class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-300">Nouveau ticket</a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-8">
        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($setupWarning !== ''): ?>
            <div class="mb-6 rounded-xl border border-amber-400/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <?= htmlspecialchars($setupWarning, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="rounded-2xl border border-white/10 bg-slate-900/60">
            <div class="border-b border-white/10 px-6 py-4">
                <p class="text-sm text-slate-300">Retrouve tes échanges avec le support, sans quitter le site.</p>
            </div>
            <div class="divide-y divide-white/10">
                <?php if (count($tickets) === 0): ?>
                    <div class="px-6 py-6 text-sm text-slate-400">Aucun ticket pour l’instant.</div>
                <?php else: ?>
                    <?php foreach ($tickets as $t): ?>
                        <a href="<?= APP_BASE_PATH ?>/ticket.php?id=<?= (int) $t['id'] ?>" class="block px-6 py-4 hover:bg-white/5">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-100"><?= htmlspecialchars((string) $t['subject'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        Créé le <?= htmlspecialchars((string) $t['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ((string) ($t['last_synced_at'] ?? '') !== ''): ?>
                                            · Sync <?= htmlspecialchars((string) $t['last_synced_at'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-slate-200">
                                    <?= htmlspecialchars((string) $t['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>

