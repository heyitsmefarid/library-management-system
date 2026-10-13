<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int) get('id');
$tx = db_one(
    "SELECT br.*, bk.title, bk.author, bk.isbn, bk.shelf_location,
            u.id_number, u.first_name, u.middle_name, u.last_name, u.user_type, u.department,
            a.full_name AS lent_by,
            r.id AS return_id, r.return_no, r.return_date, r.overdue_days, r.penalty_amount, r.book_condition,
            r.remarks AS return_remarks, ra.full_name AS received_by,
            pn.id AS penalty_id, pn.status AS penalty_status, pn.receipt_no, pn.paid_at
       FROM borrowings br
       JOIN books bk ON bk.id = br.book_id
       JOIN users u ON u.id = br.user_id
       LEFT JOIN admins a ON a.id = br.processed_by
       LEFT JOIN returns r ON r.borrowing_id = br.id
       LEFT JOIN admins ra ON ra.id = r.processed_by
       LEFT JOIN penalties pn ON pn.return_id = r.id
      WHERE br.id = ?",
    [$id]
);
if (!$tx) {
    flash('danger', 'Transaction not found.');
    redirect('borrowing/index.php');
}

$today     = today();
$isActive  = $tx['status'] === 'Borrowed';
$lateDays  = $isActive ? overdue_days($tx['due_date']) : (int) $tx['overdue_days'];
$statusTxt = $isActive && $lateDays > 0 ? 'Overdue' : $tx['status'];

$pageTitle = 'Transaction ' . $tx['transaction_no'];
$activeNav = 'borrowing';
require __DIR__ . '/../includes/header.php';
?>

<div class="print-only print-header">
    <h2><?= e(setting('library_name', 'School Library')) ?></h2>
    <p>Borrowing Slip · Printed <?= e(fmt_datetime(date('Y-m-d H:i:s'))) ?></p>
</div>

<div class="page-head">
    <div>
        <h1><?= e($tx['transaction_no']) ?></h1>
        <p>Borrowing transaction · <?= badge($statusTxt) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('borrowing/index.php')) ?>"><?= icon('arrow-left') ?> Transactions</a>
        <button class="btn btn-secondary" type="button" data-print><?= icon('printer') ?> Print slip</button>
        <?php if ($isActive): ?>
            <a class="btn" href="<?= e(url('returning/process.php', ['id' => $id])) ?>"><?= icon('return') ?> Process return</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Loan details</h2></div>
        <div class="card-body">
            <dl class="details">
                <dt>Transaction no.</dt><dd><?= e($tx['transaction_no']) ?></dd>
                <dt>Book</dt><dd><a href="<?= e(url('books/view.php', ['id' => $tx['book_id']])) ?>"><?= e($tx['title']) ?></a></dd>
                <dt>Author</dt><dd><?= e($tx['author']) ?></dd>
                <dt>ISBN</dt><dd><?= e($tx['isbn'] ?: '—') ?></dd>
                <dt>Shelf</dt><dd><?= e($tx['shelf_location'] ?: '—') ?></dd>
                <dt>Borrow date</dt><dd><?= fmt_date($tx['borrow_date']) ?></dd>
                <dt>Due date</dt><dd><?= fmt_date($tx['due_date']) ?></dd>
                <dt>Processed by</dt><dd><?= e($tx['lent_by'] ?? '—') ?></dd>
                <dt>Remarks</dt><dd><?= e($tx['remarks'] ?: '—') ?></dd>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Borrower</h2></div>
        <div class="card-body">
            <dl class="details">
                <dt>Name</dt><dd><a href="<?= e(url('users/view.php', ['id' => $tx['user_id']])) ?>"><?= e(full_name($tx)) ?></a></dd>
                <dt>ID number</dt><dd><?= e($tx['id_number']) ?></dd>
                <dt>Type</dt><dd><?= e($tx['user_type']) ?></dd>
                <dt>Department</dt><dd><?= e($tx['department'] ?: '—') ?></dd>
            </dl>
        </div>
    </section>
</div>

<section class="card" style="margin-top:22px">
    <div class="card-head"><h2>Return &amp; penalty</h2></div>
    <div class="card-body">
        <?php if ($isActive): ?>
            <?php if ($lateDays > 0): ?>
                <p class="text-danger mt-0"><strong><?= $lateDays ?> day(s) overdue.</strong>
                    Penalty if returned today: <strong><?= money(compute_penalty($lateDays)) ?></strong>
                    (<?= money(penalty_rate()) ?> × <?= $lateDays ?> day(s)).</p>
            <?php else: ?>
                <p class="mt-0">Not yet returned. <?= $tx['due_date'] === $today ? 'Due <strong>today</strong>.' : 'Due in ' . overdue_days($today, $tx['due_date']) . ' day(s).' ?></p>
            <?php endif; ?>
        <?php else: ?>
            <dl class="details">
                <dt>Return no.</dt><dd><?= e($tx['return_no']) ?></dd>
                <dt>Returned on</dt><dd><?= fmt_datetime($tx['return_date']) ?></dd>
                <dt>Received by</dt><dd><?= e($tx['received_by'] ?? '—') ?></dd>
                <dt>Condition</dt><dd><?= badge($tx['book_condition']) ?></dd>
                <dt>Overdue days</dt><dd><?= (int) $tx['overdue_days'] ?></dd>
                <dt>Penalty</dt>
                <dd>
                    <?= money($tx['penalty_amount']) ?>
                    <?php if ($tx['penalty_id']): ?>
                        <?= badge($tx['penalty_status']) ?>
                        <?php if ($tx['penalty_status'] === 'Paid'): ?>
                            <span class="muted"> · receipt <?= e($tx['receipt_no']) ?> on <?= fmt_date($tx['paid_at']) ?></span>
                        <?php else: ?>
                            <a class="btn btn-sm no-print" href="<?= e(url('penalties/pay.php', ['id' => $tx['penalty_id']])) ?>">Record payment</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </dd>
                <?php if ($tx['return_remarks']): ?><dt>Remarks</dt><dd><?= e($tx['return_remarks']) ?></dd><?php endif; ?>
            </dl>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
