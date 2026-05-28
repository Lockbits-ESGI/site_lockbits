<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_login();
$user = current_user();

$ticketCount = 0;
$setupWarning = '';

start_secure_session();
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashTicketUrl = (string) ($_SESSION['flash_ticket_url'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_ticket_url']);

try {
    $ticketCount = (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
} catch (PDOException $e) {
    // Keep dashboard accessible even if SQL import is incomplete.
    $setupWarning = 'Database setup incomplete: please import client/database.sql.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - LockBits Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-black/30 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <p class="text-sm text-slate-400">Welcome back</p>
                <h1 class="text-xl font-semibold text-emerald-300"><?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="../" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Website</a>
                <a href="<?= APP_BASE_PATH ?>/logout" class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-300">Logout</a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($flashTicketUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($flashTicketUrl, ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-semibold text-black hover:bg-emerald-300">
                            Open in GLPI
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($setupWarning !== ''): ?>
            <div class="mb-6 rounded-xl border border-amber-400/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <?= htmlspecialchars($setupWarning, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="grid gap-4 md:grid-cols-4">
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Service Status</p>
                <p class="mt-2 text-2xl font-bold text-emerald-300">Operational</p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Uptime (30d)</p>
                <p class="mt-2 text-2xl font-bold text-emerald-300">99.99%</p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Open Tickets</p>
                <p class="mt-2 text-2xl font-bold text-emerald-300"><?= $ticketCount ?></p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Security Score</p>
                <p class="mt-2 text-2xl font-bold text-emerald-300">A-</p>
            </article>
        </section>

        <section class="mt-8 grid gap-6 lg:grid-cols-2">
            <article class="rounded-2xl border border-white/10 bg-slate-900/60 p-6">
                <h2 class="text-lg font-semibold text-white">Recent alerts</h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-300">
                    <li class="rounded-lg bg-white/5 px-4 py-3">No critical incidents in the last 7 days.</li>
                    <li class="rounded-lg bg-white/5 px-4 py-3">Weekly vulnerability scan completed successfully.</li>
                    <li class="rounded-lg bg-white/5 px-4 py-3">Backup integrity check passed for all environments.</li>
                </ul>
            </article>

            <article class="rounded-2xl border border-white/10 bg-slate-900/60 p-6">
                <h2 class="text-lg font-semibold text-white">Quick actions</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <a href="<?= APP_BASE_PATH ?>/create_ticket" class="rounded-lg border border-emerald-400/40 bg-emerald-400/10 px-4 py-3 text-center text-sm font-semibold text-emerald-300 hover:bg-emerald-400/20">
                        Create support ticket
                    </a>
                    <a href="<?= APP_BASE_PATH ?>/tickets" class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-center text-sm font-semibold text-slate-100 hover:bg-white/10">
                        View my tickets
                    </a>
                    <button class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100 hover:bg-white/10">
                        Download invoice
                    </button>
                    <button class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100 hover:bg-white/10">
                        Manage team access
                    </button>
                    <button class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100 hover:bg-white/10">
                        Book security review
                    </button>
                </div>
            </article>
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
        const LB_PAGE = 'dashboard';

        // Capture les données affichées sur le dashboard
        function lbGetContext() {
            const cards = document.querySelectorAll('section.grid article');
            let ctx = '';
            cards.forEach(c => {
                const label = c.querySelector('p.text-sm')?.textContent?.trim() ?? '';
                const value = c.querySelector('p.text-2xl')?.textContent?.trim() ?? '';
                if (label && value) ctx += `- ${label} : ${value}\n`;
            });
            const alerts = document.querySelectorAll('ul.space-y-3 li');
            if (alerts.length) {
                ctx += '\nAlertes récentes :\n';
                alerts.forEach(a => ctx += `- ${a.textContent.trim()}\n`);
            }
            return ctx || 'Aucune donnée extraite.';
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
                const res  = await fetch('chat_client.php', {
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
