<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$admin  = current_admin();
$errors = [];
$form   = ['full_name' => $admin['full_name'], 'email' => (string) $admin['email']];

if (is_post()) {
    $action = post('action');

    if ($action === 'profile') {
        $form = ['full_name' => post('full_name'), 'email' => post('email')];
        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 120) {
            $errors['full_name'] = 'Enter your full name (up to 120 characters).';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!$errors) {
            db_exec('UPDATE admins SET full_name = ?, email = ? WHERE id = ?',
                [$form['full_name'], $form['email'] !== '' ? $form['email'] : null, $admin['id']]);
            flash('success', 'Your profile was updated.');
            redirect('settings/account.php');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $hash    = db_value('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]);

        if (!password_verify($current, (string) $hash)) {
            $errors['current_password'] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'The new password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors['new_password'] = 'Use both letters and numbers.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'The passwords do not match.';
        }
        if (!$errors) {
            db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            session_regenerate_id(true);
            flash('success', 'Your password was changed.');
            redirect('settings/account.php');
        }
    }
}

$pageTitle   = 'My Account';
$activeNav   = 'settings';
$settingsTab = 'account';
require __DIR__ . '/../includes/header.php';

$err = fn(string $f): string => isset($errors[$f]) ? '<span class="error-text">' . e($errors[$f]) . '</span>' : '';
$cls = fn(string $f): string => isset($errors[$f]) ? 'field has-error' : 'field';
?>

<div class="page-head">
    <div><h1>Settings</h1><p>Signed in as <strong><?= e($admin['username']) ?></strong></p></div>
</div>

<section class="card">
    <?php require __DIR__ . '/tabs.php'; ?>
    <div class="card-body">
        <div class="grid-2">
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <h3 style="margin-bottom:14px">Profile</h3>
                <div class="<?= $cls('full_name') ?>" style="margin-bottom:14px">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="120" required>
                    <?= $err('full_name') ?>
                </div>
                <div class="<?= $cls('email') ?>">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="120">
                    <?= $err('email') ?>
                </div>
                <div class="form-actions"><button class="btn" type="submit"><?= icon('check') ?> Save profile</button></div>
            </form>

            <form method="post" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <h3 style="margin-bottom:14px">Change password</h3>
                <div class="<?= $cls('current_password') ?>" style="margin-bottom:14px">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required>
                    <?= $err('current_password') ?>
                </div>
                <div class="<?= $cls('new_password') ?>" style="margin-bottom:14px">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required>
                    <span class="hint">At least 8 characters with letters and numbers.</span>
                    <?= $err('new_password') ?>
                </div>
                <div class="<?= $cls('confirm_password') ?>">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <?= $err('confirm_password') ?>
                </div>
                <div class="form-actions"><button class="btn" type="submit"><?= icon('shield') ?> Change password</button></div>
            </form>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
