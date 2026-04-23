<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/glpi_api.php';

require_login();
$user = current_user();

$ticketId = (int) ($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    redirect('/tickets.php');
}

$pdo = db();
$ticket = null;
$messages = [];
$error = '';
$glpiUserId = 0;

try {
    $stmt = $pdo->prepare('SELECT id, user_id, glpi_ticket_id, subject, status, created_at FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute(['id' => $ticketId, 'uid' => (int) ($user['id'] ?? 0)]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        redirect('/tickets.php');
    }

    $stmt = $pdo->prepare('SELECT glpi_user_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) ($user['id'] ?? 0)]);
    $row = $stmt->fetch();
    $glpiUserId = $row ? (int) ($row['glpi_user_id'] ?? 0) : 0;
} catch (Throwable $e) {
    $error = 'Unable to load ticket.';
}

// Post a reply
if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        $error = 'Message is required.';
    } else {
        try {
            $glpiTicketId = (int) ($ticket['glpi_ticket_id'] ?? 0);
            if ($glpiTicketId <= 0) {
                throw new RuntimeException('Ticket not linked to GLPI.');
            }

            $glpiFollowupId = glpi_add_ticket_followup($glpiTicketId, $body, false);
            $followupItem = ($glpiFollowupId > 0) ? glpi_get_ticket_followup_item($glpiTicketId, $glpiFollowupId) : [];
            $glpiDate = (string) ($followupItem['date_creation'] ?? $followupItem['date'] ?? '');

            $createdAt = gmdate('Y-m-d H:i:s');
            try {
                if ($glpiDate !== '') {
                    $dt = new DateTimeImmutable($glpiDate);
                    $createdAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            } catch (Throwable) {
                // keep fallback
            }

            $ins = $pdo->prepare(
                'INSERT INTO ticket_messages (ticket_id, glpi_item_type, glpi_item_id, author_type, author_label, body, created_at) VALUES (:ticket_id, :glpi_item_type, :glpi_item_id, :author_type, :author_label, :body, :created_at)'
            );
            $ins->execute([
                'ticket_id' => $ticketId,
                'glpi_item_type' => 'followup',
                'glpi_item_id' => $glpiFollowupId > 0 ? $glpiFollowupId : null,
                'author_type' => 'client',
                'author_label' => (string) ($user['name'] ?? 'Client'),
                'body' => $body,
                'created_at' => $createdAt,
            ]);

            start_secure_session();
            $_SESSION['flash_success'] = 'Message sent.';
            redirect('/ticket.php?id=' . $ticketId);
        } catch (Throwable $e) {
            error_log('[ticket_reply] ' . $e::class . ': ' . $e->getMessage());
            $error = (defined('APP_ENV') && APP_ENV !== 'production')
                ? ('Unable to send message: ' . $e->getMessage())
                : 'Unable to send message right now. Please try again later.';
        }
    }
}

// Sync followups from GLPI (best effort)
$syncDebug = [
    'ticket' => ['ok' => false, 'error' => ''],
    'followups' => ['count' => 0, 'error' => ''],
    'solutions' => ['count' => 0, 'error' => ''],
    'tasks' => ['count' => 0, 'error' => ''],
    'preview' => [],
    'raw_sample' => [
        'followups' => [],
        'solutions' => [],
        'tasks' => [],
    ],
    'inserted' => 0,
    'status' => '',
];
if ($ticket) {
    try {
        $glpiTicketId = (int) ($ticket['glpi_ticket_id'] ?? 0);
        if ($glpiTicketId > 0 && glpi_is_configured()) {
            // Sync ticket status
            try {
                $glpiTicket = glpi_get_ticket($glpiTicketId);
            } catch (Throwable $e) {
                // If staff deleted the ticket in GLPI, remove it locally too.
                if (glpi_is_not_found_error($e)) {
                    $pdo->prepare('DELETE FROM tickets WHERE id = :id AND user_id = :uid')->execute([
                        'id' => $ticketId,
                        'uid' => (int) ($user['id'] ?? 0),
                    ]);
                    start_secure_session();
                    $_SESSION['flash_success'] = 'Ce ticket a été supprimé par le support.';
                    redirect('/tickets.php');
                }
                throw $e;
            }
            $syncDebug['ticket']['ok'] = true;
            $localStatus = glpi_map_ticket_status_to_local($glpiTicket['status'] ?? null);
            $syncDebug['status'] = $localStatus;
            $pdo->prepare('UPDATE tickets SET status = :status WHERE id = :id')->execute([
                'status' => $localStatus,
                'id' => $ticketId,
            ]);

            try {
                $followups = glpi_get_ticket_followups($glpiTicketId);
                $syncDebug['followups']['count'] = is_array($followups) ? count($followups) : 0;
                if ((defined('APP_ENV') && APP_ENV !== 'production')) {
                    $syncDebug['raw_sample']['followups'] = array_slice(is_array($followups) ? $followups : [], 0, 2);
                }
            } catch (Throwable $e) {
                $followups = [];
                $syncDebug['followups']['error'] = $e->getMessage();
            }

            try {
                $solutions = glpi_get_ticket_solutions($glpiTicketId);
                $syncDebug['solutions']['count'] = is_array($solutions) ? count($solutions) : 0;
                if ((defined('APP_ENV') && APP_ENV !== 'production')) {
                    $syncDebug['raw_sample']['solutions'] = array_slice(is_array($solutions) ? $solutions : [], 0, 2);
                }
            } catch (Throwable $e) {
                $solutions = [];
                $syncDebug['solutions']['error'] = $e->getMessage();
            }

            try {
                $tasks = glpi_get_ticket_tasks($glpiTicketId);
                $syncDebug['tasks']['count'] = is_array($tasks) ? count($tasks) : 0;
                if ((defined('APP_ENV') && APP_ENV !== 'production')) {
                    $syncDebug['raw_sample']['tasks'] = array_slice(is_array($tasks) ? $tasks : [], 0, 2);
                }
            } catch (Throwable $e) {
                $tasks = [];
                $syncDebug['tasks']['error'] = $e->getMessage();
            }

            foreach ([
                ['type' => 'followup', 'items' => $followups],
                ['type' => 'solution', 'items' => $solutions],
                ['type' => 'task', 'items' => $tasks],
            ] as $batch) {
                $type = (string) $batch['type'];
                $items = is_array($batch['items']) ? $batch['items'] : [];
                foreach ($items as $fu) {
                if (!is_array($fu)) {
                    continue;
                }
                // GLPI timeline endpoints may return wrappers like: {type: "Followup", item: {...}}
                if (is_array($fu['item'] ?? null)) {
                    $fu = $fu['item'];
                }
                $fuId = (int) ($fu['id'] ?? 0);
                $content = (string) ($fu['content'] ?? ($fu['message'] ?? ''));
                $date = (string) ($fu['date'] ?? ($fu['date_creation'] ?? ($fu['created_at'] ?? '')));
                $author = 'Support';
                $authorId = 0;
                $isPrivate = (bool) ($fu['is_private'] ?? false);
                if (is_array($fu['user'] ?? null)) {
                    $author = (string) (($fu['user']['name'] ?? '') ?: $author);
                    $authorId = (int) ($fu['user']['id'] ?? 0);
                } elseif (isset($fu['users_id'])) {
                    $author = (string) $fu['users_id'];
                } elseif (isset($fu['author'])) {
                    $author = (string) $fu['author'];
                }

                if ($fuId <= 0 || $content === '') {
                    continue;
                }

                if ((defined('APP_ENV') && APP_ENV !== 'production') && count($syncDebug['preview']) < 6) {
                    $syncDebug['preview'][] = [
                        'type' => $type,
                        'id' => $fuId,
                        'user_id' => $authorId,
                        'user_name' => $author,
                        'is_private' => $isPrivate,
                        'content_snip' => mb_substr(trim(strip_tags($content)), 0, 120),
                        'date' => $date,
                    ];
                }

                // Private messages are not meant to be shown to the client.
                if ($isPrivate) {
                    continue;
                }

                // Parse GLPI date (ISO8601) and store in UTC to keep ordering consistent.
                $createdAt = gmdate('Y-m-d H:i:s');
                try {
                    if ($date !== '') {
                        $dt = new DateTimeImmutable($date);
                        $createdAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    }
                } catch (Throwable) {
                    // keep fallback
                }

                $authorType = 'staff';
                if ($glpiUserId > 0 && $authorId > 0 && $authorId === $glpiUserId) {
                    $authorType = 'client';
                    $author = (string) ($user['name'] ?? 'Vous');
                }

                $up = $pdo->prepare(
                    'INSERT IGNORE INTO ticket_messages (ticket_id, glpi_item_type, glpi_item_id, author_type, author_label, body, created_at) VALUES (:ticket_id, :glpi_item_type, :glpi_item_id, :author_type, :author_label, :body, :created_at)'
                );
                $up->execute([
                    'ticket_id' => $ticketId,
                    'glpi_item_type' => $type,
                    'glpi_item_id' => $fuId,
                    'author_type' => $authorType,
                    'author_label' => $author,
                    'body' => $content,
                    'created_at' => $createdAt,
                ]);
                $syncDebug['inserted'] += (int) $up->rowCount();
            }
            }

            $pdo->prepare('UPDATE tickets SET last_synced_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $ticketId]);
        }
    } catch (Throwable $e) {
        // Non-blocking: keep the page usable
        $syncDebug['ticket']['error'] = $e->getMessage();
        error_log('[ticket_sync] ' . $e::class . ': ' . $e->getMessage());
    }
}

try {
    $stmt = $pdo->prepare(
        'SELECT author_type, author_label, body, created_at FROM ticket_messages WHERE ticket_id = :ticket_id ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute(['ticket_id' => $ticketId]);
    $messages = $stmt->fetchAll() ?: [];
} catch (Throwable) {
    $messages = [];
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
    <title>Ticket - Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-black/30 backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <div>
                <p class="text-sm text-slate-400">Ticket</p>
                <h1 class="text-xl font-semibold text-emerald-300"><?= htmlspecialchars((string) ($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= APP_BASE_PATH ?>/tickets.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Mes tickets</a>
                <a href="<?= APP_BASE_PATH ?>/create_ticket.php" class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-300">Nouveau</a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-8">
        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="rounded-2xl border border-white/10 bg-slate-900/60">
            <div class="border-b border-white/10 px-6 py-4">
                <p class="text-sm text-slate-300">Conversation avec le support</p>
            </div>

            <div class="space-y-4 px-6 py-6">
                <?php if (count($messages) === 0): ?>
                    <p class="text-sm text-slate-400">Aucun message pour l’instant.</p>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <?php
                            $isClient = ((string) ($m['author_type'] ?? '')) === 'client';
                            $label = (string) ($m['author_label'] ?? ($isClient ? 'Vous' : 'Support'));
                        ?>
                        <div class="flex <?= $isClient ? 'justify-end' : 'justify-start' ?>">
                            <div class="max-w-2xl rounded-2xl border <?= $isClient ? 'border-emerald-400/25 bg-emerald-400/10' : 'border-white/10 bg-white/5' ?> px-4 py-3">
                                <div class="flex items-baseline justify-between gap-4">
                                    <p class="text-xs font-semibold <?= $isClient ? 'text-emerald-200' : 'text-slate-200' ?>">
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        <?= htmlspecialchars((string) ($m['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <div class="mt-2 whitespace-pre-wrap text-sm text-slate-100">
                                    <?php
                                        $rawBody = (string) ($m['body'] ?? '');
                                        $textBody = html_entity_decode(strip_tags($rawBody), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <?= htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-white/10 bg-slate-900/60 p-6">
            <h2 class="text-sm font-semibold text-slate-100">Répondre</h2>
            <form method="post" class="mt-4 grid gap-3">
                <textarea name="body" rows="5" required class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 text-sm outline-none ring-emerald-400/40 focus:ring" placeholder="Écris ta réponse…"></textarea>
                <button type="submit" class="w-full rounded-lg bg-emerald-400 px-4 py-3 text-sm font-semibold text-black hover:bg-emerald-300">
                    Envoyer
                </button>
                <p class="text-xs text-slate-400">
                    Les échanges sont envoyés au support via GLPI, mais tu restes sur le site.
                </p>
            </form>
        </section>
    </main>
</body>
</html>

