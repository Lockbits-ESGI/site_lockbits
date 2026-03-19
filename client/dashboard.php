<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_login();
$user = current_user();

$ticketCount = 0;
$setupWarning = '';

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
                <a href="/lockbits/index.html" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Website</a>
                <a href="<?= APP_BASE_PATH ?>/logout.php" class="rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-300">Logout</a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
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
                    <button class="rounded-lg border border-emerald-400/40 bg-emerald-400/10 px-4 py-3 text-sm font-semibold text-emerald-300 hover:bg-emerald-400/20">
                        Create support ticket
                    </button>
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
</body>
</html>
