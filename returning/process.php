<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$loadTx = function (int $id): ?array {
    return db_one(
        "SELECT br.*, bk.title, bk.author, bk.isbn, u.id_number, u.first_name, u.middle_name, u.last_name, u.user_type
           FROM borrowings br
           JOIN books bk ON bk.id = br.book_id
           JOIN users u ON u.id = br.user_id
          WHERE br.id = ?",
        [$id]
    );
};

$tx = $loadTx($id);
if (!$tx) {
    flash('danger', 'Transaction not found.');
    redirect('returning/index.php');
}
if ($tx['status'] !== 'Borrowed') {
    flash('warning', "Transaction {$tx['transaction_no']} has already been returned.");
    redirect('borrowing/view.php', ['id' => $id]);
}

$conditions = ['Good', 'Damaged', 'Lost'];
$condition  = 'Good';
$remarks    = '';
$errors     = [];

if (is_post()) {
    $condition = post('book_condition');
    $remarks   = post('remarks');
    if (!in_array($condition, $conditions, true)) {
        $errors['book_condition'] = 'Select the condition of the returned book.';
    }
    if (mb_strlen($remarks) > 255) {
        $errors['remarks'] = 'Remarks must be at most 255 characters.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the borrowing so the same loan cannot be returned twice.
            $loan = db_one('SELECT id, user_id, book_id, due_date, status, transaction_no FROM borrowings WHERE id = ? FOR UPDATE', [$id]);
            if (!$loan || $loan['status'] !== 'Borrowed') {
                $pdo->rollBack();
                flash('warning', 'This book has already been returned.');
                redirect('borrowing/view.php', ['id' => $id]);
            }

            $returnDate  = date('Y-m-d H:i:s');            // recorded automatically
            $rate        = penalty_rate();
            $daysLate    = overdue_days($loan['due_date'], $returnDate);
            $penalty     = compute_penalty($daysLate, $rate);

            db_exec(
                'INSERT INTO returns (return_no, borrowing_id, return_date, overdue_days, penalty_amount, book_condition, remarks, processed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                ['TMP-' . bin2hex(random_bytes(8)), $id, $returnDate, $daysLate, $penalty, $condition,
                 $remarks !== '' ? $remarks : null, current_admin_id()]
            );
            $returnId = (int) $pdo->lastInsertId();
            $returnNo = reference_no('RET', $returnId);
            db_exec('UPDATE returns SET return_no = ? WHERE id = ?', [$returnNo, $returnId]);

            db_exec("UPDATE borrowings SET status = 'Returned' WHERE id = ?", [$id]);

            if ($condition === 'Lost') {
                // The copy did not come back: remove it from the inventory.
                db_exec('UPDATE books SET total_copies = GREATEST(total_copies - 1, available_copies) WHERE id = ?', [$loan['book_id']]);
            } else {
                db_exec('UPDATE books SET available_copies = LEAST(available_copies + 1, total_copies) WHERE id = ?', [$loan['book_id']]);
            }

            if ($penalty > 0) {
                db_exec(
                    'INSERT INTO penalties (borrowing_id, return_id, user_id, overdue_days, rate_per_day, amount)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $returnId, $loan['user_id'], $daysLate, $rate, $penalty]
                );
            }

            db_exec('DELETE FROM notifications WHERE borrowing_id = ?', [$id]);

            $pdo->commit();

            $msg = "Return {$returnNo} recorded for {$loan['transaction_no']}.";
            $msg .= $penalty > 0
                ? " Returned {$daysLate} day(s) late — penalty of " . money($penalty) . ' added as unpaid.'
                : ' Returned on time, no penalty.';
            flash($penalty > 0 ? 'warning' : 'success', $msg);
            redirect('borrowing/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_duplicate_error($e)) {
                flash('warning', 'This book has already been returned.');
                redirect('borrowing/view.php', ['id' => $id]);
            }
            throw $e;
        }
    }
}

$daysLate = overdue_days($tx['due_date']);
$rate     = penalty_rate();
$penalty  = compute_penalty($daysLate, $rate);

$pageTitle = 'Process Return';
$activeNav = 'returning';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Process return</h1>
        <p><?= e($tx['transaction_no']) ?> · <?= e($tx['title']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('returning/index.php')) ?>"><?= icon('arrow-left') ?> Returning</a>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Loan</h2><?= badge($daysLate > 0 ? 'Overdue' : 'Borrowed') ?></div>
        <div class="card-body">
            <dl class="details">
                <dt>Book</dt><dd><?= e($tx['title']) ?></dd>
                <dt>Author</dt><dd><?= e($tx['author']) ?></dd>
                <dt>ISBN</dt><dd><?= e($tx['isbn'] ?: '—') ?></dd>
                <dt>Borrower</dt><dd><?= e(full_name($tx)) ?> (<?= e($tx['id_number']) ?>)</dd>
                <dt>Borrowed</dt><dd><?= fmt_date($tx['borrow_date']) ?></dd>
                <dt>Due date</dt><dd><?= fmt_date($tx['due_date']) ?></dd>
                <dt>Return date</dt><dd><?= fmt_datetime(date('Y-m-d H:i:s')) ?> <span class="muted">(recorded automatically)</span></dd>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Confirm return</h2></div>
        <div class="card-body">
            <div class="penalty-preview">
                <div class="summary-row">
                    <div class="item"><div class="value <?= $daysLate > 0 ? 'text-danger' : '' ?>"><?= $daysLate ?></div><div class="label">Overdue day(s)</div></div>
                    <div class="item"><div class="value"><?= money($rate) ?></div><div class="label">Rate per day</div></div>
                    <div class="item"><div class="value <?= $penalty > 0 ? 'text-danger' : 'text-success' ?>"><?= money($penalty) ?></div><div class="label">Penalty</div></div>
                </div>
            </div>

            <form method="post" style="margin-top:18px" novalidate
                  data-confirm-title="Record this return?" data-confirm-ok="Record return"
                  data-confirm="&quot;<?= e($tx['title']) ?>&quot; will be marked returned by <?= e(full_name($tx)) ?> today.<?= $penalty > 0 ? ' A penalty of ' . e(money($penalty)) . ' will be added as unpaid.' : ' No penalty is due.' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="field<?= isset($errors['book_condition']) ? ' has-error' : '' ?>" style="margin-bottom:14px">
                    <label for="book_condition">Condition of the book</label>
                    <select id="book_condition" name="book_condition">
                        <?php foreach ($conditions as $c): ?>
                            <option <?= $condition === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">"Lost" removes the copy from the inventory instead of restoring it.</span>
                    <?php if (isset($errors['book_condition'])): ?><span class="error-text"><?= e($errors['book_condition']) ?></span><?php endif; ?>
                </div>
                <div class="field<?= isset($errors['remarks']) ? ' has-error' : '' ?>">
                    <label for="remarks">Remarks</label>
                    <input type="text" id="remarks" name="remarks" value="<?= e($remarks) ?>" maxlength="255" placeholder="Optional">
                    <?php if (isset($errors['remarks'])): ?><span class="error-text"><?= e($errors['remarks']) ?></span><?php endif; ?>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit"><?= icon('check') ?> Confirm return</button>
                    <a class="btn btn-secondary" href="<?= e(url('returning/index.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
