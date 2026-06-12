<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/glpi_api.php';

require_login();
$user = current_user();

$ticketId = (int) ($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    redirect('/tickets');
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
        redirect('/tickets');
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
            $_SESSION['flash_success'] = 'Message envoyé.';
            redirect('/ticket?id=' . $ticketId);
        } catch (Throwable $e) {
            error_log('[ticket_reply] ' . $e::class . ': ' . $e->getMessage());
            $error = (defined('APP_ENV') && APP_ENV !== 'production')
                ? ('Unable to send message: ' . $e->getMessage())
                : 'Impossible d\'envoyer le message pour le moment. Réessayez plus tard.';
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
                    redirect('/tickets');
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
        'SELECT author_type, author_label, body, created_at, glpi_item_type FROM ticket_messages WHERE ticket_id = :ticket_id ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute(['ticket_id' => $ticketId]);
    $messages = $stmt->fetchAll() ?: [];
} catch (Throwable) {
    $messages = [];
}

// Re-read ticket to get updated status after sync
try {
    $stmt = $pdo->prepare('SELECT id, user_id, glpi_ticket_id, subject, status, created_at, last_synced_at FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute(['id' => $ticketId, 'uid' => (int) ($user['id'] ?? 0)]);
    $ticket = $stmt->fetch() ?: $ticket;
} catch (Throwable) {
    // keep previous value
}

start_secure_session();
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

// Status badge helpers
$statusLabel = match ((string) ($ticket['status'] ?? '')) {
    'open'        => 'Ouvert',
    'in_progress' => 'En cours',
    'closed'      => 'Clôturé',
    default       => ucfirst((string) ($ticket['status'] ?? 'Inconnu')),
};
$statusClasses = match ((string) ($ticket['status'] ?? '')) {
    'open'        => 'border-amber-400/40 bg-amber-400/10 text-amber-300',
    'in_progress' => 'border-blue-400/40 bg-blue-400/10 text-blue-300',
    'closed'      => 'border-slate-500/40 bg-slate-500/10 text-slate-400',
    default       => 'border-white/20 bg-white/5 text-slate-300',
};

// Build context string for the AI chatbot
$chatbotContext = 'Ticket #' . htmlspecialchars((string) ($ticket['glpi_ticket_id'] ?? $ticketId), ENT_QUOTES, 'UTF-8')
    . ' — Sujet : ' . htmlspecialchars((string) ($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8')
    . ' — Statut : ' . $statusLabel . "\n"
    . "Conversation :\n";
foreach ($messages as $m) {
    $role = ((string) ($m['author_type'] ?? '')) === 'client' ? 'Client' : 'Support';
    $txt  = mb_substr(trim(strip_tags(html_entity_decode((string) ($m['body'] ?? ''), ENT_QUOTES, 'UTF-8'))), 0, 200);
    $chatbotContext .= $role . ' : ' . $txt . "\n";
}
$chatbotContextJs = json_encode($chatbotContext, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket — <?= htmlspecialchars((string) ($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">

    <!-- LOCKBITS CLIENT CHATBOT -->
    <style>
        #lb-btn {
            position: fixed; bottom: 28px; right: 28px; z-index: 1000;
            width: 58px; height: 58px; border-radius: 50%;
            background: linear-gradient(135deg,#10b981,#059669);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 20px rgba(16,185,129,.5);
            transition: transform .2s, box-shadow .2s;
        }
        #lb-btn:hover { transform: scale(1.08); box-shadow: 0 6px 30px rgba(16,185,129,.65); }
        #lb-btn svg { width: 26px; height: 26px; color: #fff; }
        #lb-badge {
            position: absolute; top: 2px; right: 2px;
            width: 14px; height: 14px; border-radius: 50%;
            background: #f43f5e; border: 2px solid #020617; display: none;
        }
        #lb-win {
            position: fixed; bottom: 100px; right: 28px; z-index: 999;
            width: 360px; max-height: 530px; border-radius: 20px;
            border: 1px solid rgba(16,185,129,.2); background: #0f172a;
            box-shadow: 0 28px 70px rgba(0,0,0,.7);
            display: flex; flex-direction: column; overflow: hidden;
            transform: scale(.93) translateY(14px); opacity: 0; pointer-events: none;
            transition: transform .25s ease, opacity .25s ease;
        }
        #lb-win.open { transform: scale(1) translateY(0); opacity: 1; pointer-events: all; }
        #lb-header {
            display: flex; align-items: center; gap: 10px; padding: 13px 16px;
            background: rgba(16,185,129,.07); border-bottom: 1px solid rgba(16,185,129,.12);
            flex-shrink: 0;
        }
        .lb-av {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg,#10b981,#059669);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .lb-av svg { width: 18px; height: 18px; color: #fff; }
        #lb-header h4 { margin:0; font-size:.84rem; font-weight:600; color:#fff; }
        #lb-header p  { margin:0; font-size:.7rem; color:#6ee7b7; }
        .lb-live {
            margin-left: auto; display: flex; align-items: center; gap: 5px;
            font-size:.65rem; color:#6ee7b7; background:rgba(16,185,129,.12);
            padding: 3px 8px; border-radius: 99px;
        }
        .lb-live span {
            width:6px; height:6px; border-radius:50%; background:#10b981;
            animation: lbpulse 2s infinite;
        }
        @keyframes lbpulse { 0%,100%{opacity:1} 50%{opacity:.35} }
        #lb-msgs {
            flex:1; overflow-y:auto; padding:16px;
            display:flex; flex-direction:column; gap:10px;
            scrollbar-width:thin; scrollbar-color:rgba(16,185,129,.25) transparent;
        }
        .lb-msg {
            max-width:84%; padding:9px 13px; border-radius:14px;
            font-size:.81rem; line-height:1.55; word-break:break-word;
            animation: lbfade .2s ease;
        }
        @keyframes lbfade { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }
        .lb-msg.bot {
            align-self:flex-start; background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.08); color:#e2e8f0;
            border-bottom-left-radius:4px;
        }
        .lb-msg.user {
            align-self:flex-end; background:linear-gradient(135deg,#10b981,#059669);
            color:#fff; border-bottom-right-radius:4px;
        }
        .lb-typing {
            align-self:flex-start; display:flex; gap:4px; align-items:center;
            padding:10px 14px; background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.08);
            border-radius:14px; border-bottom-left-radius:4px;
        }
        .lb-typing span {
            width:6px; height:6px; border-radius:50%; background:#6ee7b7;
            animation:lbbounce .9s infinite;
        }
        .lb-typing span:nth-child(2){animation-delay:.15s}
        .lb-typing span:nth-child(3){animation-delay:.3s}
        @keyframes lbbounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}
        #lb-footer {
            padding:11px 12px; border-top:1px solid rgba(255,255,255,.07);
            display:flex; gap:8px; flex-shrink:0;
        }
        #lb-input {
            flex:1; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
            border-radius:10px; padding:9px 12px; font-size:.81rem; color:#e2e8f0;
            outline:none; resize:none; line-height:1.4;
            transition:border-color .2s; font-family:inherit;
        }
        #lb-input:focus { border-color:rgba(16,185,129,.45); }
        #lb-input::placeholder { color:#475569; }
        #lb-send {
            width:38px; height:38px; border-radius:10px; background:#10b981;
            border:none; cursor:pointer; display:flex; align-items:center; justify-content:center;
            transition:background .2s, transform .15s; flex-shrink:0; align-self:flex-end;
        }
        #lb-send:hover:not(:disabled){background:#059669;transform:scale(1.05);}
        #lb-send:disabled{opacity:.4;cursor:not-allowed;}
        #lb-send svg { width:16px; height:16px; color:#fff; }
        @media(max-width:420px){#lb-win{width:calc(100vw - 32px);right:16px;}}

        /* Conversation scroll area */
        #conv-scroll {
            max-height: 560px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,.1) transparent;
        }
        #conv-scroll::-webkit-scrollbar { width: 4px; }
        #conv-scroll::-webkit-scrollbar-track { background: transparent; }
        #conv-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 2px; }

        /* Message entrance animation */
        @keyframes msgIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
        .msg-bubble { animation: msgIn .18s ease; }

        /* Avatar */
        .msg-avatar {
            width: 30px; height: 30px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .65rem; font-weight: 700; flex-shrink: 0;
            line-height: 1;
        }
    </style>

    <header class="border-b border-white/10 bg-black/30 backdrop-blur sticky top-0 z-10">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <p class="text-xs text-slate-500 shrink-0">Ticket<?php if (!empty($ticket['glpi_ticket_id'])): ?> #<?= htmlspecialchars((string) $ticket['glpi_ticket_id'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium <?= $statusClasses ?>">
                        <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <h1 class="text-lg font-semibold text-emerald-300 truncate"><?= htmlspecialchars((string) ($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="flex items-center gap-3 shrink-0 ml-4">
                <a href="<?= APP_BASE_PATH ?>/tickets" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10 transition-colors">Mes tickets</a>
                <a href="<?= APP_BASE_PATH ?>/create_ticket" class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-300 transition-colors">Nouveau</a>
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

        <!-- Ticket metadata -->
        <div class="mb-6 flex flex-wrap gap-4 rounded-xl border border-white/8 bg-white/3 px-5 py-3 text-xs text-slate-400">
            <span>
                <span class="text-slate-500">Créé le</span>
                <time class="ml-1 text-slate-300 fmt-date" data-ts="<?= htmlspecialchars((string) ($ticket['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) ($ticket['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </time>
            </span>
            <?php if (!empty($ticket['last_synced_at'])): ?>
            <span>
                <span class="text-slate-500">Synchro</span>
                <time class="ml-1 text-slate-300 fmt-date" data-ts="<?= htmlspecialchars((string) $ticket['last_synced_at'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $ticket['last_synced_at'], ENT_QUOTES, 'UTF-8') ?>
                </time>
            </span>
            <?php endif; ?>
            <span>
                <span class="text-slate-500">Messages</span>
                <span class="ml-1 text-slate-300"><?= count($messages) ?></span>
            </span>
        </div>

        <!-- Conversation -->
        <section class="rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden">
            <div class="flex items-center justify-between border-b border-white/10 px-6 py-4">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                    </svg>
                    <p class="text-sm font-medium text-slate-200">Conversation avec le support</p>
                </div>
            </div>

            <div id="conv-scroll" class="px-6 py-6">
                <?php if (count($messages) === 0): ?>
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-white/10 bg-white/5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                            </svg>
                        </div>
                        <p class="text-sm font-medium text-slate-300">Aucun message pour l'instant</p>
                        <p class="text-xs text-slate-500">Utilisez le formulaire ci-dessous pour contacter le support.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-5">
                        <?php foreach ($messages as $m): ?>
                            <?php
                                $isClient  = ((string) ($m['author_type'] ?? '')) === 'client';
                                $label     = (string) ($m['author_label'] ?? ($isClient ? 'Vous' : 'Support'));
                                $itemType  = (string) ($m['glpi_item_type'] ?? 'followup');
                                $rawBody   = (string) ($m['body'] ?? '');
                                $textBody  = html_entity_decode(strip_tags($rawBody), ENT_QUOTES, 'UTF-8');
                                $initials  = mb_strtoupper(mb_substr(trim($label), 0, 1)) ?: '?';
                                $avatarCls = $isClient
                                    ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/20'
                                    : 'bg-slate-700/60 text-slate-300 border border-white/10';
                            ?>
                            <div class="msg-bubble flex items-end gap-2 <?= $isClient ? 'flex-row-reverse' : 'flex-row' ?>">

                                <!-- Avatar -->
                                <div class="msg-avatar <?= $avatarCls ?>" title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <!-- Bubble -->
                                <div class="max-w-[72%] min-w-0">
                                    <!-- Header row -->
                                    <div class="flex items-center gap-2 mb-1 <?= $isClient ? 'flex-row-reverse' : '' ?>">
                                        <span class="text-xs font-semibold <?= $isClient ? 'text-emerald-200' : 'text-slate-300' ?>">
                                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($itemType === 'solution'): ?>
                                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-1.5 py-px text-[10px] font-semibold text-emerald-300">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                Solution
                                            </span>
                                        <?php elseif ($itemType === 'task'): ?>
                                            <span class="inline-flex items-center gap-1 rounded-full border border-blue-400/30 bg-blue-400/10 px-1.5 py-px text-[10px] font-semibold text-blue-300">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                Tâche
                                            </span>
                                        <?php endif; ?>
                                        <time class="text-[10px] text-slate-500 fmt-date" data-ts="<?= htmlspecialchars((string) ($m['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) ($m['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </time>
                                    </div>
                                    <!-- Content -->
                                    <div class="rounded-2xl border px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap break-words
                                        <?= $isClient
                                            ? 'border-emerald-400/20 bg-emerald-400/8 text-slate-100 rounded-br-sm'
                                            : ($itemType === 'solution'
                                                ? 'border-emerald-400/25 bg-emerald-900/20 text-slate-100 rounded-bl-sm'
                                                : 'border-white/10 bg-white/5 text-slate-100 rounded-bl-sm')
                                        ?>">
                                        <?= htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Reply form -->
        <section class="mt-6 rounded-2xl border border-white/10 bg-slate-900/60 p-6">
            <div class="flex items-center gap-2 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                </svg>
                <h2 class="text-sm font-semibold text-slate-100">Répondre</h2>
            </div>
            <form method="post" id="reply-form" class="grid gap-3">
                <div class="relative">
                    <textarea
                        id="reply-body"
                        name="body"
                        rows="4"
                        required
                        maxlength="2000"
                        class="w-full rounded-xl border border-white/15 bg-black/30 px-4 py-3 text-sm text-slate-100 placeholder-slate-500 outline-none ring-emerald-400/40 focus:ring focus:border-emerald-400/40 resize-none transition-colors"
                        placeholder="Écris ta réponse… (Ctrl+Entrée pour envoyer)"
                    ></textarea>
                    <span id="char-count" class="absolute bottom-3 right-3 text-[10px] text-slate-600 pointer-events-none">0/2000</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">
                        Les échanges sont synchronisés avec le support via GLPI.
                    </p>
                    <button
                        id="reply-submit"
                        type="submit"
                        class="flex items-center gap-2 rounded-lg bg-emerald-400 px-5 py-2.5 text-sm font-semibold text-black hover:bg-emerald-300 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shrink-0"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                        Envoyer
                    </button>
                </div>
            </form>
        </section>

    </main>

    <!-- Chatbot button -->
    <button id="lb-btn" aria-label="Ouvrir l'assistant LockBits" onclick="lbToggle()">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
        </svg>
        <div id="lb-badge"></div>
    </button>

    <!-- Chatbot window -->
    <div id="lb-win" role="dialog" aria-label="LockBits Assistant">
        <div id="lb-header">
            <div class="lb-av">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div>
                <h4>LockBits Assistant</h4>
                <p>Aide sur ce ticket</p>
            </div>
            <div class="lb-live"><span></span>En ligne</div>
        </div>
        <div id="lb-msgs">
            <div class="lb-msg bot">Bonjour 👋 Je connais le contenu de ce ticket et peux répondre à vos questions ou vous aider à formuler une réponse.</div>
        </div>
        <div id="lb-footer">
            <textarea id="lb-input" rows="1" placeholder="Votre message…" onkeydown="lbKey(event)"></textarea>
            <button id="lb-send" onclick="lbSend()" aria-label="Envoyer">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
            </button>
        </div>
    </div>

    <script>
        // ── Chatbot ──────────────────────────────────────────────────────────
        const lbHistory = [];
        let lbIsOpen = false;
        const LB_PAGE = 'ticket';

        function lbGetContext() {
            return <?= $chatbotContextJs ?>;
        }
        function lbToggle() {
            lbIsOpen = !lbIsOpen;
            document.getElementById('lb-win').classList.toggle('open', lbIsOpen);
            document.getElementById('lb-badge').style.display = 'none';
            if (lbIsOpen) document.getElementById('lb-input').focus();
        }
        function lbKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); lbSend(); } }
        function lbAppend(text, role) {
            const box = document.getElementById('lb-msgs');
            const div = document.createElement('div');
            div.className = 'lb-msg ' + role;
            div.textContent = text;
            box.appendChild(div);
            box.scrollTop = box.scrollHeight;
        }
        function lbTyping() {
            const box = document.getElementById('lb-msgs');
            const div = document.createElement('div');
            div.className = 'lb-typing';
            div.innerHTML = '<span></span><span></span><span></span>';
            box.appendChild(div);
            box.scrollTop = box.scrollHeight;
            return div;
        }
        async function lbSend() {
            const input = document.getElementById('lb-input');
            const btn   = document.getElementById('lb-send');
            const msg   = input.value.trim();
            if (!msg) return;
            input.value = ''; input.style.height = 'auto'; btn.disabled = true;
            lbAppend(msg, 'user');
            lbHistory.push({ role: 'user', content: msg });
            const dots = lbTyping();
            try {
                const res  = await fetch('chat_client', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: msg,
                        history: lbHistory.slice(0, -1),
                        context: lbGetContext(),
                        page: LB_PAGE
                    })
                });
                const data = await res.json();
                dots.remove();
                const reply = data.reply || 'Désolé, une erreur est survenue.';
                lbAppend(reply, 'bot');
                lbHistory.push({ role: 'assistant', content: reply });
                if (!lbIsOpen) document.getElementById('lb-badge').style.display = 'block';
            } catch {
                dots.remove();
                lbAppend('Erreur de connexion. Veuillez réessayer.', 'bot');
            }
            btn.disabled = false;
            input.focus();
        }
        document.getElementById('lb-input').addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 90) + 'px';
        });

        // ── Timestamp formatter ──────────────────────────────────────────────
        function formatDate(isoStr) {
            if (!isoStr) return '';
            // Treat stored UTC strings (no Z suffix) as UTC
            const normalized = isoStr.includes('T') ? isoStr : isoStr.replace(' ', 'T') + 'Z';
            const d = new Date(normalized);
            if (isNaN(d)) return isoStr;
            const now   = new Date();
            const local = new Date(d.toLocaleString('en-US', { timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone }));
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const dayOf = new Date(local.getFullYear(), local.getMonth(), local.getDate());
            const diff  = today - dayOf;
            const time  = local.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            if (diff === 0)       return 'Aujourd\'hui à ' + time;
            if (diff === 86400000) return 'Hier à ' + time;
            return local.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) + ' à ' + time;
        }
        document.querySelectorAll('.fmt-date').forEach(el => {
            const ts = el.getAttribute('data-ts');
            if (ts) el.textContent = formatDate(ts);
        });

        // ── Auto-scroll conversation to last message ─────────────────────────
        (function () {
            const conv = document.getElementById('conv-scroll');
            if (conv) conv.scrollTop = conv.scrollHeight;
        })();

        // ── Reply textarea: auto-resize + char counter + Ctrl+Enter ─────────
        const replyBody   = document.getElementById('reply-body');
        const charCount   = document.getElementById('char-count');
        const replySubmit = document.getElementById('reply-submit');
        const replyForm   = document.getElementById('reply-form');

        if (replyBody) {
            replyBody.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 220) + 'px';
                const len = this.value.length;
                charCount.textContent = len + '/2000';
                charCount.classList.toggle('text-amber-400', len > 1800);
                charCount.classList.toggle('text-red-400', len >= 2000);
            });
            replyBody.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    replyForm.requestSubmit();
                }
            });
        }
        if (replyForm) {
            replyForm.addEventListener('submit', function () {
                if (replySubmit) {
                    replySubmit.disabled = true;
                    replySubmit.innerHTML = '<svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Envoi…';
                }
            });
        }
    </script>
</body>
</html>
