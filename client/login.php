<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            login_user($user);
            redirect('/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LockBits Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="mx-auto flex min-h-screen max-w-6xl items-center px-6 py-10">
        <div class="grid w-full gap-8 md:grid-cols-2">
            <section class="rounded-2xl border border-white/10 bg-white/5 p-8">
                <a href="/site_lockbits/index.html" class="text-emerald-300 hover:text-emerald-200">← Back to website</a>
                <h1 class="mt-6 text-3xl font-bold">Client Login</h1>
                <p class="mt-2 text-slate-400">Access your private dashboard and support tools.</p>
                <ul class="mt-8 space-y-3 text-sm text-slate-300">
                    <li>• Security monitoring and alerts</li>
                    <li>• Hosting and infrastructure overview</li>
                    <li>• Billing and support follow-up</li>
                </ul>
            </section>

            <section class="rounded-2xl border border-emerald-400/20 bg-slate-900/70 p-8">
                <h2 class="text-2xl font-semibold">Sign in</h2>
                <?php if ($error !== ''): ?>
                    <div class="mt-4 rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="mt-6 space-y-4">
                    <div>
                        <label for="email" class="mb-2 block text-sm text-slate-300">Email</label>
                        <input id="email" name="email" type="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                               class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm text-slate-300">Password</label>
                        <input id="password" name="password" type="password" required
                               class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                    </div>
                    <button type="submit" class="w-full rounded-lg bg-emerald-400 px-4 py-3 font-semibold text-black hover:bg-emerald-300">
                        Login
                    </button>
                </form>
                <p class="mt-5 text-sm text-slate-400">
                    No account yet?
                    <a href="<?= APP_BASE_PATH ?>/register.php" class="font-semibold text-emerald-300 hover:text-emerald-200">Create one</a>
                </p>
            </section>
        </div>
    </div>
</body>
</html>
