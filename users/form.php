<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$user   = null;

if ($isEdit) {
    $user = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user) {
        flash('danger', 'User not found.');
        redirect('users/index.php');
    }
}

$types    = ['Student', 'Teacher', 'Staff', 'Other'];
$statuses = ['Active', 'Inactive', 'Suspended'];
$fields   = ['id_number', 'first_name', 'middle_name', 'last_name', 'user_type', 'department',
             'year_level', 'email', 'phone', 'address', 'status'];

$data = [];
foreach ($fields as $f) {
    $data[$f] = (string) ($user[$f] ?? '');
}
$data['user_type'] = $data['user_type'] ?: 'Student';
$data['status']    = $data['status'] ?: 'Active';
$errors = [];

if (is_post()) {
    foreach ($fields as $f) {
        $data[$f] = post($f);
    }
    $data['id_number'] = strtoupper($data['id_number']);

    if ($data['id_number'] === '') {
        $errors['id_number'] = 'ID number is required.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9\-]{1,29}$/', $data['id_number'])) {
        $errors['id_number'] = 'Use letters, numbers and hyphens only (2–30 characters).';
    }
    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $f => $label) {
        if ($data[$f] === '') {
            $errors[$f] = "$label is required.";
        } elseif (mb_strlen($data[$f]) > 60) {
            $errors[$f] = "$label must be at most 60 characters.";
        }
    }
    if (mb_strlen($data['middle_name']) > 60) {
        $errors['middle_name'] = 'Middle name must be at most 60 characters.';
    }
    if (!in_array($data['user_type'], $types, true)) {
        $errors['user_type'] = 'Select a valid user type.';
    }
    if (!in_array($data['status'], $statuses, true)) {
        $errors['status'] = 'Select a valid status.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($data['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $data['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }
    if (mb_strlen($data['department']) > 120) {
        $errors['department'] = 'Too long (max 120 characters).';
    }
    if (mb_strlen($data['year_level']) > 30) {
        $errors['year_level'] = 'Too long (max 30 characters).';
    }
    if (mb_strlen($data['address']) > 255) {
        $errors['address'] = 'Too long (max 255 characters).';
    }

    if (!$errors) {
        $values = [];
        foreach ($fields as $f) {
            $values[] = $data[$f] !== '' ? $data[$f] : null;
        }
        try {
            if ($isEdit) {
                $set = implode(', ', array_map(fn($f) => "$f = ?", $fields));
                db_exec("UPDATE users SET $set WHERE id = ?", array_merge($values, [$id]));
                flash('success', 'User record updated.');
                redirect('users/view.php', ['id' => $id]);
            } else {
                $cols = implode(', ', $fields);
                $qs   = implode(', ', array_fill(0, count($fields), '?'));
                db_exec("INSERT INTO users ($cols) VALUES ($qs)", $values);
                $newId = (int) db()->lastInsertId();
                flash('success', full_name($data) . ' was registered.');
                redirect('users/view.php', ['id' => $newId]);
            }
        } catch (PDOException $e) {
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $errors['id_number'] = 'This ID number is already registered.';
        }
    }
}

$pageTitle = $isEdit ? 'Edit User' : 'Register User';
$activeNav = 'users';
require __DIR__ . '/../includes/header.php';

$err = fn(string $f): string => isset($errors[$f]) ? '<span class="error-text">' . e($errors[$f]) . '</span>' : '';
$cls = fn(string $f, string $extra = ''): string => trim('field ' . (isset($errors[$f]) ? 'has-error ' : '') . $extra);
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit user' : 'Register a library user' ?></h1>
        <p><?= $isEdit ? e(full_name($user)) . ' · ' . e($user['id_number']) : 'Fields marked * are required.' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e($isEdit ? url('users/view.php', ['id' => $id]) : url('users/index.php')) ?>"><?= icon('arrow-left') ?> Back</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><span>Please correct the highlighted fields.</span></div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="<?= $cls('id_number') ?>">
                    <label for="id_number">Student / Employee ID <span class="req">*</span></label>
                    <input type="text" id="id_number" name="id_number" value="<?= e($data['id_number']) ?>" maxlength="30" required placeholder="e.g. 2024-00001">
                    <?= $err('id_number') ?>
                </div>
                <div class="<?= $cls('user_type') ?>">
                    <label for="user_type">User type <span class="req">*</span></label>
                    <select id="user_type" name="user_type">
                        <?php foreach ($types as $t): ?>
                            <option <?= $data['user_type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('user_type') ?>
                </div>
                <div class="<?= $cls('first_name') ?>">
                    <label for="first_name">First name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($data['first_name']) ?>" maxlength="60" required>
                    <?= $err('first_name') ?>
                </div>
                <div class="<?= $cls('middle_name') ?>">
                    <label for="middle_name">Middle name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= e($data['middle_name']) ?>" maxlength="60">
                    <?= $err('middle_name') ?>
                </div>
                <div class="<?= $cls('last_name') ?>">
                    <label for="last_name">Last name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($data['last_name']) ?>" maxlength="60" required>
                    <?= $err('last_name') ?>
                </div>
                <div class="<?= $cls('department') ?>">
                    <label for="department">Course / Department / Office</label>
                    <input type="text" id="department" name="department" value="<?= e($data['department']) ?>" maxlength="120">
                    <?= $err('department') ?>
                </div>
                <div class="<?= $cls('year_level') ?>">
                    <label for="year_level">Year &amp; section</label>
                    <input type="text" id="year_level" name="year_level" value="<?= e($data['year_level']) ?>" maxlength="30" placeholder="Students only, e.g. 2nd Year - B">
                    <?= $err('year_level') ?>
                </div>
                <div class="<?= $cls('email') ?>">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($data['email']) ?>" maxlength="120">
                    <?= $err('email') ?>
                </div>
                <div class="<?= $cls('phone') ?>">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($data['phone']) ?>" maxlength="20">
                    <?= $err('phone') ?>
                </div>
                <div class="<?= $cls('status') ?>">
                    <label for="status">Account status</label>
                    <select id="status" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option <?= $data['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Only active users can time in and borrow books.</span>
                    <?= $err('status') ?>
                </div>
                <div class="<?= $cls('address', 'span-2') ?>">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($data['address']) ?>" maxlength="255">
                    <?= $err('address') ?>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Register user' ?></button>
                <a class="btn btn-secondary" href="<?= e(url('users/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
