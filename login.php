<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error    = null;
$username = '';

if (is_post()) {
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $error = attempt_login($username, $password);
        if ($error === null) {
            redirect('dashboard.php');
        }
    }
}

$libraryName = setting('library_name', 'School Library');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e($libraryName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="login-page">
    <form class="login-card" method="post" action="<?= e(url('login.php')) ?>" autocomplete="off" novalidate>
        <span class="brand-mark" style="display:grid"><?= icon('book-open', 26) ?></span>
        <h1><?= e($libraryName) ?></h1>
        <p class="sub">Administrator sign in</p>

        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><span class="alert-icon"><?= icon($f['type'] === 'success' ? 'check' : 'alert', 18) ?></span><span><?= e($f['message']) ?></span></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><span class="alert-icon"><?= icon('alert', 18) ?></span><span><?= e($error) ?></span></div>
        <?php endif; ?>

        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($username) ?>" maxlength="50" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-block"><?= icon('log-in') ?> Sign in</button>

        <p class="foot">Authorized library personnel only. Accounts are created by an administrator.</p>
    </form>
</div>
</body>
</html>
