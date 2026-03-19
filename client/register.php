<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute(['email' => $email]);

        if ($check->fetch()) {
            $error = 'This email is already used.';
        } else {
            $insert = db()->prepare(
                'INSERT INTO users (name, email, password_hash, created_at) VALUES (:name, :email, :password_hash, NOW())'
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $id = (int) db()->lastInsertId();
            login_user(['id' => $id, 'name' => $name, 'email' => $email]);
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
    <title>Register - LockBits Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-10">
        <section class="w-full rounded-2xl border border-emerald-400/20 bg-slate-900/70 p-8">
            <a href="<?= APP_BASE_PATH ?>/login.php" class="text-emerald-300 hover:text-emerald-200">← Back to login</a>
            <h1 class="mt-5 text-3xl font-bold">Create your client account</h1>
            <p class="mt-2 text-slate-400">This account gives access to your dashboard and support.</p>

            <?php if ($error !== ''): ?>
                <div class="mt-4 rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-6 grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="name" class="mb-2 block text-sm text-slate-300">Full name</label>
                    <input id="name" name="name" type="text" required value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                </div>
                <div class="md:col-span-2">
                    <label for="email" class="mb-2 block text-sm text-slate-300">Email</label>
                    <input id="email" name="email" type="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                </div>
                <div>
                    <label for="password" class="mb-2 block text-sm text-slate-300">Password</label>
                    <input id="password" name="password" type="password" required
                           class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                </div>
                <div>
                    <label for="confirm_password" class="mb-2 block text-sm text-slate-300">Confirm password</label>
                    <input id="confirm_password" name="confirm_password" type="password" required
                           class="w-full rounded-lg border border-white/15 bg-black/30 px-4 py-3 outline-none ring-emerald-400/40 focus:ring">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full rounded-lg bg-emerald-400 px-4 py-3 font-semibold text-black hover:bg-emerald-300">
                        Create account
                    </button>
                </div>
            </form>
        </section>
    </div>
</body>
</html>
