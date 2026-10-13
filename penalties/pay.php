<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pn = db_one(
    "SELECT pn.*, br.transaction_no, br.borrow_date, br.due_date, bk.title, u.id_number, u.first_name, u.middle_name, u.last_name,
            r.return_date, r.return_no
       FROM penalties pn
       JOIN borrowings br ON br.id = pn.borrowing_id
       JOIN books bk ON bk.id = br.book_id
       JOIN users u ON u.id = pn.user_id
       JOIN returns r ON r.id = pn.return_id
      WHERE pn.id = ?",
    [$id]
);
if (!$pn) {
    flash('danger', 'Penalty record not found.');
    redirect('penalties/index.php');
}
if ($pn['status'] === 'Paid') {
    flash('warning', 'This penalty has already been paid (receipt ' . $pn['receipt_no'] . ').');
    redirect('penalties/index.php');
}

$receiptNo = 'OR-' . date('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
$remarks   = '';
$errors    = [];

if (is_post()) {
    $receiptNo = strtoupper(post('receipt_no'));
    $remarks   = post('payment_remarks');
    $amountIn  = post('amount_received');

    if ($receiptNo === '' || !preg_match('/^[A-Z0-9\-]{3,30}$/', $receiptNo)) {
        $errors['receipt_no'] = 'Enter a receipt / OR number (letters, numbers and hyphens, 3–30 characters).';
    }
    if (!is_numeric($amountIn) || round((float) $amountIn, 2) !== round((float) $pn['amount'], 2)) {
        $errors['amount_received'] = 'The amount received must equal the penalty amount of ' . money($pn['amount']) . '.';
    }
    if (mb_strlen($remarks) > 255) {
        $errors['payment_remarks'] = 'Remarks must be at most 255 characters.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $locked = db_one('SELECT status FROM penalties WHERE id = ? FOR UPDATE', [$id]);
            if ($locked['status'] !== 'Unpaid') {
                $pdo->rollBack();
                flash('warning', 'This penalty has already been paid.');
                redirect('penalties/index.php');
            }
            db_exec(
                "UPDATE penalties SET status = 'Paid', receipt_no = ?, paid_at = NOW(), payment_remarks = ?, received_by = ?
                  WHERE id = ?",
                [$receiptNo, $remarks !== '' ? $remarks : null, current_admin_id(), $id]
            );
            $pdo->commit();
            flash('success', 'Payment of ' . money($pn['amount']) . " recorded for {$pn['first_name']} {$pn['last_name']} (receipt {$receiptNo}).");
            redirect('penalties/index.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $errors['receipt_no'] = 'This receipt number has already been used.';
        }
    }
}

$pageTitle = 'Record Penalty Payment';
$activeNav = 'penalties';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Record penalty payment</h1>
        <p><?= e(full_name($pn)) ?> · <?= e($pn['id_number']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('penalties/index.php')) ?>"><?= icon('arrow-left') ?> Penalties</a>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Penalty details</h2><?= badge($pn['status']) ?></div>
        <div class="card-body">
            <dl class="details">
                <dt>Book</dt><dd><?= e($pn['title']) ?></dd>
                <dt>Transaction</dt><dd><a href="<?= e(url('borrowing/view.php', ['id' => $pn['borrowing_id']])) ?>"><?= e($pn['transaction_no']) ?></a></dd>
                <dt>Borrowed</dt><dd><?= fmt_date($pn['borrow_date']) ?></dd>
                <dt>Due</dt><dd><?= fmt_date($pn['due_date']) ?></dd>
                <dt>Returned</dt><dd><?= fmt_datetime($pn['return_date']) ?> (<?= e($pn['return_no']) ?>)</dd>
                <dt>Overdue days</dt><dd><?= (int) $pn['overdue_days'] ?></dd>
                <dt>Rate per day</dt><dd><?= money($pn['rate_per_day']) ?></dd>
                <dt>Amount due</dt><dd><strong class="text-danger"><?= money($pn['amount']) ?></strong></dd>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Payment</h2></div>
        <div class="card-body">
            <?php if ($errors): ?><div class="alert alert-danger"><span>Please correct the highlighted fields.</span></div><?php endif; ?>
            <form method="post" novalidate data-confirm-title="Record this payment?" data-confirm-ok="Record payment"
                  data-confirm="<?= e(money($pn['amount'])) ?> from <?= e(full_name($pn)) ?> will be marked as paid.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="field<?= isset($errors['amount_received']) ? ' has-error' : '' ?>" style="margin-bottom:14px">
                    <label for="amount_received">Amount received (₱) <span class="req">*</span></label>
                    <input type="number" id="amount_received" name="amount_received" step="0.01" min="0"
                           value="<?= e(is_post() ? post('amount_received') : number_format((float) $pn['amount'], 2, '.', '')) ?>" required>
                    <span class="hint">Penalties are settled in full.</span>
                    <?php if (isset($errors['amount_received'])): ?><span class="error-text"><?= e($errors['amount_received']) ?></span><?php endif; ?>
                </div>
                <div class="field<?= isset($errors['receipt_no']) ? ' has-error' : '' ?>" style="margin-bottom:14px">
                    <label for="receipt_no">Official receipt no. <span class="req">*</span></label>
                    <input type="text" id="receipt_no" name="receipt_no" value="<?= e($receiptNo) ?>" maxlength="30" required>
                    <?php if (isset($errors['receipt_no'])): ?><span class="error-text"><?= e($errors['receipt_no']) ?></span><?php endif; ?>
                </div>
                <div class="field<?= isset($errors['payment_remarks']) ? ' has-error' : '' ?>">
                    <label for="payment_remarks">Remarks</label>
                    <input type="text" id="payment_remarks" name="payment_remarks" value="<?= e($remarks) ?>" maxlength="255" placeholder="Optional">
                    <?php if (isset($errors['payment_remarks'])): ?><span class="error-text"><?= e($errors['payment_remarks']) ?></span><?php endif; ?>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit"><?= icon('check') ?> Record payment</button>
                    <a class="btn btn-secondary" href="<?= e(url('penalties/index.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
