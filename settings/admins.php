<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$me     = current_admin_id();
$errors = [];
$form   = ['username' => '', 'full_name' => '', 'email' => ''];

if (is_post()) {
    $action = post('action');

    if ($action === 'create') {
        $form     = ['username' => strtolower(post('username')), 'full_name' => post('full_name'), 'email' => post('email')];
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9_.]{4,50}$/', $form['username'])) {
            $errors['username'] = 'Use 4–50 lowercase letters, numbers, dots or underscores.';
        }
        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 120) {
            $errors['full_name'] = 'Enter the full name (up to 120 characters).';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'At least 8 characters with letters and numbers.';
        }
        if (!$errors) {
            try {
                db_exec(
                    'INSERT INTO admins (username, password_hash, full_name, email) VALUES (?, ?, ?, ?)',
                    [$form['username'], password_hash($password, PASSWORD_DEFAULT), $form['full_name'],
                     $form['email'] !== '' ? $form['email'] : null]
                );
                flash('success', "Administrator \"{$form['username']}\" was created.");
                redirect('settings/admins.php');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                $errors['username'] = 'This username is already taken.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int) post('id');
        if ($id === $me) {
            flash('danger', 'You cannot deactivate your own account.');
        } else {
            $target = db_one('SELECT username, status FROM admins WHERE id = ?', [$id]);
            if ($target) {
                $new = $target['status'] === 'Active' ? 'Inactive' : 'Active';
                db_exec('UPDATE admins SET status = ? WHERE id = ?', [$new, $id]);
                flash('success', "Administrator \"{$target['username']}\" is now {$new}.");
            }
        }
        redirect('settings/admins.php');
    } elseif ($action === 'reset') {
        $id       = (int) post('id');
        $password = (string) ($_POST['new_password'] ?? '');
        $target   = db_one('SELECT username FROM admins WHERE id = ?', [$id]);
        if (!$target) {
            flash('danger', 'Administrator not found.');
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            flash('danger', 'The new password must have at least 8 characters with letters and numbers.');
        } else {
            db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('success', "Password for \"{$target['username']}\" was reset.");
        }
        redirect('settings/admins.php');
    }
}

$admins = db_all('SELECT id, username, full_name, email, status, last_login, created_at FROM admins ORDER BY username');

$pageTitle   = 'Administrators';
$activeNav   = 'settings';
$settingsTab = 'admins';
require __DIR__ . '/../includes/header.php';

$err = fn(string $f): string => isset($errors[$f]) ? '<span class="error-text">' . e($errors[$f]) . '</span>' : '';
$cls = fn(string $f): string => isset($errors[$f]) ? 'field has-error' : 'field';
?>

<div class="page-head">
    <div><h1>Settings</h1><p>Only administrators can create other administrator accounts. There is no public sign-up.</p></div>
</div>

<section class="card">
    <?php require __DIR__ . '/tabs.php'; ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Username</th><th>Name</th><th>Last sign-in</th><th>Status</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a): ?>
                <tr>
                    <td class="cell-title"><?= e($a['username']) ?><?= (int) $a['id'] === $me ? ' <span class="muted">(you)</span>' : '' ?></td>
                    <td><?= e($a['full_name']) ?><span class="cell-sub"><?= e($a['email'] ?: '') ?></span></td>
                    <td class="nowrap"><?= fmt_datetime($a['last_login']) ?></td>
                    <td><?= badge($a['status']) ?></td>
                    <td class="actions">
                        <?php if ((int) $a['id'] !== $me): ?>
                            <form method="post" class="quick-entry" style="display:inline-flex;gap:6px"
                                  data-confirm-title="Reset this password?" data-confirm-ok="Reset password"
                                  data-confirm="<?= e($a['username']) ?> will need the new password the next time they sign in.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="password" name="new_password" placeholder="New password" aria-label="New password" style="width:150px;padding:6px 10px" required>
                                <button class="btn btn-secondary btn-sm" type="submit">Reset</button>
                            </form>
                            <form method="post"
                                  <?php if ($a['status'] === 'Active'): ?>
                                      data-confirm-title="Deactivate this administrator?" data-confirm-ok="Deactivate" data-confirm-variant="danger"
                                      data-confirm="<?= e($a['username']) ?> will be signed out and can no longer sign in until reactivated."
                                  <?php else: ?>
                                      data-confirm-title="Activate this administrator?" data-confirm-ok="Activate"
                                      data-confirm="<?= e($a['username']) ?> will be able to sign in again."
                                  <?php endif; ?>>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn btn-sm <?= $a['status'] === 'Active' ? 'btn-danger' : '' ?>" type="submit">
                                    <?= $a['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('settings/account.php')) ?>">Edit my account</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>Add administrator</h2></div>
    <div class="card-body">
        <form method="post" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="<?= $cls('username') ?>">
                    <label for="username">Username <span class="req">*</span></label>
                    <input type="text" id="username" name="username" value="<?= e($form['username']) ?>" maxlength="50" required>
                    <?= $err('username') ?>
                </div>
                <div class="<?= $cls('full_name') ?>">
                    <label for="full_name">Full name <span class="req">*</span></label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="120" required>
                    <?= $err('full_name') ?>
                </div>
                <div class="<?= $cls('email') ?>">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="120">
                    <?= $err('email') ?>
                </div>
                <div class="<?= $cls('password') ?>">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" minlength="8" required>
                    <span class="hint">At least 8 characters with letters and numbers.</span>
                    <?= $err('password') ?>
                </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit"><?= icon('plus') ?> Create administrator</button></div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
