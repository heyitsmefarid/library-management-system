<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$fields = [
    'library_name'     => ['Library name', 'Shown on the interface and on printed reports'],
    'penalty_rate'     => ['Penalty rate per overdue day (₱)', 'Applied to books returned after this change. Existing penalties keep their rate.'],
    'loan_period_days' => ['Default loan period (days)', 'Used to pre-fill the due date of new borrowings'],
    'max_active_loans' => ['Maximum books per user', 'How many books one user may borrow at the same time'],
    'due_soon_days'    => ['"Due soon" reminder window (days)', 'Notifications are generated this many days before the due date'],
];

$data = [];
foreach ($fields as $key => $_) {
    $data[$key] = (string) setting($key, '');
}
$errors = [];

if (is_post()) {
    foreach ($fields as $key => $_) {
        $data[$key] = post($key);
    }

    if ($data['library_name'] === '' || mb_strlen($data['library_name']) > 120) {
        $errors['library_name'] = 'Enter a library name (up to 120 characters).';
    }
    if (!is_numeric($data['penalty_rate']) || (float) $data['penalty_rate'] < 0 || (float) $data['penalty_rate'] > 10000) {
        $errors['penalty_rate'] = 'Enter an amount from 0.00 to 10,000.00.';
    }
    $ranges = ['loan_period_days' => [1, 365], 'max_active_loans' => [1, 50], 'due_soon_days' => [1, 30]];
    foreach ($ranges as $key => [$min, $max]) {
        if (!ctype_digit($data[$key]) || (int) $data[$key] < $min || (int) $data[$key] > $max) {
            $errors[$key] = "Enter a whole number from {$min} to {$max}.";
        }
    }

    if (!$errors) {
        $data['penalty_rate'] = number_format((float) $data['penalty_rate'], 2, '.', '');
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($fields as $key => [$label, $hint]) {
            save_setting($key, $data[$key], $hint);
        }
        $pdo->commit();
        flash('success', 'Settings saved. Penalty rate is now ' . money($data['penalty_rate']) . ' per overdue day.');
        redirect('settings/index.php');
    }
}

$pageTitle   = 'Settings';
$activeNav   = 'settings';
$settingsTab = 'general';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div><h1>Settings</h1><p>Configure library rules and penalties.</p></div>
</div>

<section class="card">
    <?php require __DIR__ . '/tabs.php'; ?>
    <div class="card-body">
        <?php if ($errors): ?><div class="alert alert-danger"><span>Please correct the highlighted fields.</span></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <?php foreach ($fields as $key => [$label, $hint]):
                    $isNum = $key !== 'library_name'; ?>
                    <div class="field<?= isset($errors[$key]) ? ' has-error' : '' ?><?= $key === 'library_name' ? ' span-2' : '' ?>">
                        <label for="<?= e($key) ?>"><?= e($label) ?></label>
                        <input type="<?= $isNum ? 'number' : 'text' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($data[$key]) ?>"
                            <?= $key === 'penalty_rate' ? 'step="0.01" min="0"' : ($isNum ? 'step="1" min="1"' : 'maxlength="120"') ?> required>
                        <span class="hint"><?= e($hint) ?></span>
                        <?php if (isset($errors[$key])): ?><span class="error-text"><?= e($errors[$key]) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= icon('check') ?> Save settings</button>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
