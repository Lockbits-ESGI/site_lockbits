<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/glpi_api.php';

require_login();
$user = current_user();

$error = '';
$title = '';
$content = '';
$successUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));

    if ($title === '' || $content === '') {
        $error = 'Please fill in all fields.';
    } elseif (!glpi_is_configured() || GLPI_WEB_URL === '') {
        $error = 'Support system is not configured (GLPI). Please contact an administrator.';
    } else {
        try {
            $stmt = db()->prepare('SELECT glpi_user_id FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) ($user['id'] ?? 0)]);
            $row = $stmt->fetch();

            $glpiUserId = $row ? (int) ($row['glpi_user_id'] ?? 0) : 0;
            if ($glpiUserId <= 0) {
                throw new GlpiApiException('User not linked to GLPI.');
            }

            $glpiTicketId = glpi_create_ticket($title, $content, $glpiUserId);

            $pdo = db();
            $pdo->beginTransaction();

            $insertTicket = $pdo->prepare(
                'INSERT INTO tickets (user_id, glpi_ticket_id, subject, status, created_at) VALUES (:user_id, :glpi_ticket_id, :subject, :status, UTC_TIMESTAMP())'
            );
            $insertTicket->execute([
                'user_id' => (int) ($user['id'] ?? 0),
                'glpi_ticket_id' => $glpiTicketId,
                'subject' => $title,
                'status' => 'open',
            ]);

            $localTicketId = (int) $pdo->lastInsertId();

            $insertMsg = $pdo->prepare(
                'INSERT INTO ticket_messages (ticket_id, glpi_item_type, glpi_item_id, author_type, author_label, body, created_at) VALUES (:ticket_id, NULL, NULL, :author_type, :author_label, :body, UTC_TIMESTAMP())'
            );
            $insertMsg->execute([
                'ticket_id' => $localTicketId,
                'author_type' => 'client',
                'author_label' => (string) ($user['name'] ?? 'Client'),
                'body' => $content,
            ]);

            $pdo->commit();

            start_secure_session();
            $_SESSION['flash_success'] = 'Ticket created successfully.';
            redirect('/ticket.php?id=' . $localTicketId);
        } catch (Throwable $e) {
            $pdo = null;
            try {
                $pdo = db();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // ignore rollback failures
            }
            error_log('[create_ticket] ' . $e::class . ': ' . $e->getMessage());
            $error = (defined('APP_ENV') && APP_ENV !== 'production')
                ? ('Unable to create ticket: ' . $e->getMessage())
                : 'Unable to create ticket right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ticket - LockBits Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-black/30 backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-5">
            <div>
                <p class="text-sm text-slate-400">Support</p>
                <h1 class="text-xl font-semibold text-emerald-300">Create a ticket</h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= APP_BASE_PATH ?>/dashboard.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Back</a>
                <?php if (GLPI_WEB_URL !== ''): ?>
                    <a href="<?= htmlspecialchars(GLPI_WEB_URL, ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15">Open GLPI</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-6 py-8">
        <?php if ($error !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="rounded-2xl border border-white/10 bg-slate-900/60 p-6">
            <form method="post" class="grid gap-4">
                <div>
                    <label for="title" class="mb-2 block text-sm text-slate-300">Title</label>
                    <input id="title" name="title" type="text" required value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                </div>

                <div>
                    <label for="content" class="mb-2 block text-sm text-slate-300">Description</label>
                    <textarea id="content" name="content" required rows="7"
                              class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <button type="submit" class="w-full rounded-lg bg-emerald-400 px-4 py-3 font-semibold text-black hover:bg-emerald-300">
                    Create ticket in GLPI
                </button>

                <p class="text-xs text-slate-400">
                    After creation, you will be redirected to GLPI to view and follow the ticket.
                </p>
            </form>
        </section>
    </main>
</body>
</html>

