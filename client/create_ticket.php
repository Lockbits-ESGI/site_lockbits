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
            redirect('/ticket?id=' . $localTicketId);
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
                <a href="<?= APP_BASE_PATH ?>/dashboard" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Back</a>
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

</html>


    <!-- ============================================================
         LOCKBITS CLIENT CHATBOT
         Lit les données de la page et les envoie à chat_client.php
    ============================================================ -->
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
    </style>

    <button id="lb-btn" aria-label="Ouvrir l'assistant LockBits" onclick="lbToggle()">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
        </svg>
        <div id="lb-badge"></div>
    </button>

    <div id="lb-win" role="dialog" aria-label="LockBits Assistant">
        <div id="lb-header">
            <div class="lb-av">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div>
                <h4>LockBits Assistant</h4>
                <p>Analyse votre espace client</p>
            </div>
            <div class="lb-live"><span></span>En ligne</div>
        </div>
        <div id="lb-msgs">
            <div class="lb-msg bot">Bonjour 👋 Je peux vous faire un résumé de cette page ou répondre à vos questions.</div>
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
        const lbHistory = [];
        let lbIsOpen = false;
        const LB_PAGE = 'create_ticket';

        function lbGetContext() {
            const title   = document.getElementById('title')?.value?.trim() ?? '';
            const content = document.getElementById('content')?.value?.trim() ?? '';
            let ctx = 'Le client est en train de rédiger un ticket.\n';
            if (title)   ctx += `Titre saisi : "${title}"\n`;
            if (content) ctx += `Description saisie : "${content}"\n`;
            if (!title && !content) ctx += 'Aucune information saisie pour l\'instant.\n';
            return ctx;
        }

        function lbToggle() {
            lbIsOpen = !lbIsOpen;
            document.getElementById('lb-win').classList.toggle('open', lbIsOpen);
            document.getElementById('lb-badge').style.display = 'none';
            if (lbIsOpen) document.getElementById('lb-input').focus();
        }
        function lbKey(e) { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();lbSend();} }
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
            lbHistory.push({role:'user', content:msg});
            const dots = lbTyping();
            try {
                const res  = await fetch('chat_client', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({
                        message: msg,
                        history: lbHistory.slice(0,-1),
                        context: lbGetContext(),
                        page: LB_PAGE
                    })
                });
                const data = await res.json();
                dots.remove();
                const reply = data.reply || 'Désolé, une erreur est survenue.';
                lbAppend(reply, 'bot');
                lbHistory.push({role:'assistant', content:reply});
                if (!lbIsOpen) document.getElementById('lb-badge').style.display = 'block';
            } catch {
                dots.remove();
                lbAppend('Erreur de connexion. Veuillez réessayer.', 'bot');
            }
            btn.disabled = false;
            input.focus();
        }
        document.getElementById('lb-input').addEventListener('input', function(){
            this.style.height='auto';
            this.style.height=Math.min(this.scrollHeight,90)+'px';
        });
    </script>
</body>
</html>
