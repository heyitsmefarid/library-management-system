<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$loanDays  = max(1, (int) setting('loan_period_days', 7));
$maxLoans  = max(1, (int) setting('max_active_loans', 3));

$data = [
    'user_id'     => (string) (int) ($_GET['user_id'] ?? 0),
    'book_id'     => (string) (int) ($_GET['book_id'] ?? 0),
    'borrow_date' => today(),
    'due_date'    => date('Y-m-d', strtotime("+{$loanDays} days")),
    'remarks'     => '',
];
$errors = [];

if (is_post()) {
    foreach (array_keys($data) as $f) {
        $data[$f] = post($f);
    }
    $userId = (int) $data['user_id'];
    $bookId = (int) $data['book_id'];

    if ($userId <= 0) {
        $errors['user_id'] = 'Select a borrower.';
    }
    if ($bookId <= 0) {
        $errors['book_id'] = 'Select a book.';
    }
    if (!valid_date($data['borrow_date'])) {
        $errors['borrow_date'] = 'Enter a valid borrow date.';
    } elseif ($data['borrow_date'] > today()) {
        $errors['borrow_date'] = 'Borrow date cannot be in the future.';
    }
    if (!valid_date($data['due_date'])) {
        $errors['due_date'] = 'Enter a valid due date.';
    } elseif (!isset($errors['borrow_date']) && $data['due_date'] < $data['borrow_date']) {
        $errors['due_date'] = 'Due date cannot be earlier than the borrow date.';
    }
    if (mb_strlen($data['remarks']) > 255) {
        $errors['remarks'] = 'Remarks must be at most 255 characters.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the borrower and the book so simultaneous requests cannot over-lend.
            $user = db_one('SELECT id, first_name, last_name, status FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $book = db_one('SELECT id, title, available_copies FROM books WHERE id = ? FOR UPDATE', [$bookId]);

            if (!$user) {
                $errors['user_id'] = 'The selected user no longer exists.';
            } elseif ($user['status'] !== 'Active') {
                $errors['user_id'] = "This user's account is {$user['status']}.";
            } else {
                $loans = db_one(
                    "SELECT COUNT(*) AS active, SUM(due_date < ?) AS overdue,
                            SUM(book_id = ?) AS same_book
                       FROM borrowings WHERE user_id = ? AND status = 'Borrowed'",
                    [today(), $bookId, $userId]
                );
                if ((int) $loans['overdue'] > 0) {
                    $errors['user_id'] = "This user has {$loans['overdue']} overdue book(s). They must be returned first.";
                } elseif ((int) $loans['active'] >= $maxLoans) {
                    $errors['user_id'] = "This user already has {$loans['active']} borrowed book(s); the limit is {$maxLoans}.";
                } elseif ((int) $loans['same_book'] > 0) {
                    $errors['book_id'] = 'This user is already borrowing a copy of this book.';
                }
            }

            if (!$book) {
                $errors['book_id'] = 'The selected book no longer exists.';
            } elseif ((int) $book['available_copies'] < 1) {
                $errors['book_id'] = 'No copies of this book are available.';
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
                db_exec(
                    'INSERT INTO borrowings (transaction_no, user_id, book_id, borrow_date, due_date, remarks, processed_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    ['TMP-' . bin2hex(random_bytes(8)), $userId, $bookId, $data['borrow_date'], $data['due_date'],
                     $data['remarks'] !== '' ? $data['remarks'] : null, current_admin_id()]
                );
                $borrowingId   = (int) $pdo->lastInsertId();
                $transactionNo = reference_no('BRW', $borrowingId);
                db_exec('UPDATE borrowings SET transaction_no = ? WHERE id = ?', [$transactionNo, $borrowingId]);

                $updated = db_exec(
                    'UPDATE books SET available_copies = available_copies - 1 WHERE id = ? AND available_copies > 0',
                    [$bookId]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Book copy could not be reserved.');
                }

                $pdo->commit();
                flash('success', "Transaction {$transactionNo}: \"{$book['title']}\" lent to {$user['first_name']} {$user['last_name']}, due " . fmt_date($data['due_date']) . '.');
                redirect('borrowing/view.php', ['id' => $borrowingId]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$users = db_all(
    "SELECT u.id, u.id_number, u.first_name, u.last_name, u.user_type,
            (SELECT COUNT(*) FROM borrowings br WHERE br.user_id = u.id AND br.status = 'Borrowed') AS loans
       FROM users u WHERE u.status = 'Active'
      ORDER BY u.last_name, u.first_name"
);
$books = db_all(
    'SELECT id, title, author, isbn, available_copies, total_copies FROM books WHERE available_copies > 0 ORDER BY title'
);

// Details of the currently selected borrower (shown as guidance)
$selectedUser = null;
if ((int) $data['user_id'] > 0) {
    $selectedUser = db_one(
        "SELECT u.id, u.first_name, u.last_name, u.status,
                (SELECT COUNT(*) FROM borrowings WHERE user_id = u.id AND status = 'Borrowed') AS loans,
                (SELECT COUNT(*) FROM borrowings WHERE user_id = u.id AND status = 'Borrowed' AND due_date < ?) AS overdue,
                (SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE user_id = u.id AND status = 'Unpaid') AS unpaid
           FROM users u WHERE u.id = ?",
        [today(), (int) $data['user_id']]
    );
}

$pageTitle = 'New Borrowing';
$activeNav = 'borrowing';
require __DIR__ . '/../includes/header.php';

$err = fn(string $f): string => isset($errors[$f]) ? '<span class="error-text">' . e($errors[$f]) . '</span>' : '';
$cls = fn(string $f, string $extra = ''): string => trim('field ' . (isset($errors[$f]) ? 'has-error ' : '') . $extra);
?>

<div class="page-head">
    <div>
        <h1>New borrowing transaction</h1>
        <p>Loan period: <?= $loanDays ?> day(s) · Limit: <?= $maxLoans ?> book(s) per user · Penalty: <?= money(penalty_rate()) ?> per overdue day</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('borrowing/index.php')) ?>"><?= icon('arrow-left') ?> Transactions</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><span><?= e(count($errors) === 1 ? reset($errors) : 'Please correct the highlighted fields.') ?></span></div>
<?php endif; ?>

<?php if ($selectedUser && !$errors): ?>
    <?php if ($selectedUser['status'] !== 'Active'): ?>
        <div class="alert alert-danger"><span><?= e($selectedUser['first_name'] . ' ' . $selectedUser['last_name']) ?>'s account is <?= e($selectedUser['status']) ?> and cannot borrow books.</span></div>
    <?php elseif ($selectedUser['overdue'] > 0): ?>
        <div class="alert alert-danger"><span><?= e($selectedUser['first_name']) ?> has <?= (int) $selectedUser['overdue'] ?> overdue book(s) that must be returned first.</span></div>
    <?php elseif ($selectedUser['unpaid'] > 0): ?>
        <div class="alert alert-warning"><span><?= e($selectedUser['first_name']) ?> has unpaid penalties of <?= money($selectedUser['unpaid']) ?>.</span></div>
    <?php endif; ?>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="<?= $cls('user_id') ?>">
                    <label for="user_id">Borrower <span class="req">*</span></label>
                    <input type="search" class="select-filter" data-filter-select="user_id" placeholder="Type to filter by name or ID…" aria-label="Filter borrowers">
                    <select id="user_id" name="user_id" size="8" required>
                        <option value="">— Select a registered user —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $data['user_id'] === (string) $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['id_number'] . ' — ' . $u['last_name'] . ', ' . $u['first_name'] . ' (' . $u['user_type'] . ', ' . $u['loans'] . ' borrowed)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('user_id') ?>
                    <span class="hint">Only active users are listed.</span>
                </div>
                <div class="<?= $cls('book_id') ?>">
                    <label for="book_id">Book <span class="req">*</span></label>
                    <input type="search" class="select-filter" data-filter-select="book_id" placeholder="Type to filter by title, author or ISBN…" aria-label="Filter books">
                    <select id="book_id" name="book_id" size="8" required>
                        <option value="">— Select an available book —</option>
                        <?php foreach ($books as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= $data['book_id'] === (string) $b['id'] ? 'selected' : '' ?>>
                                <?= e($b['title'] . ' — ' . $b['author'] . ($b['isbn'] ? ' [' . $b['isbn'] . ']' : '') . ' (' . $b['available_copies'] . '/' . $b['total_copies'] . ' available)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('book_id') ?>
                    <span class="hint">Only books with available copies are listed.</span>
                </div>
                <div class="<?= $cls('borrow_date') ?>">
                    <label for="borrow_date">Borrow date <span class="req">*</span></label>
                    <input type="date" id="borrow_date" name="borrow_date" value="<?= e($data['borrow_date']) ?>" max="<?= e(today()) ?>" required>
                    <?= $err('borrow_date') ?>
                </div>
                <div class="<?= $cls('due_date') ?>">
                    <label for="due_date">Due date <span class="req">*</span></label>
                    <input type="date" id="due_date" name="due_date" value="<?= e($data['due_date']) ?>" required>
                    <?= $err('due_date') ?>
                </div>
                <div class="<?= $cls('remarks', 'span-2') ?>">
                    <label for="remarks">Remarks</label>
                    <input type="text" id="remarks" name="remarks" value="<?= e($data['remarks']) ?>" maxlength="255" placeholder="Optional notes (e.g. condition of the book)">
                    <?= $err('remarks') ?>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit" <?= !$books ? 'disabled' : '' ?>><?= icon('check') ?> Confirm borrowing</button>
                <a class="btn btn-secondary" href="<?= e(url('borrowing/index.php')) ?>">Cancel</a>
            </div>
            <?php if (!$books): ?>
                <p class="text-danger">There are no books with available copies right now.</p>
            <?php endif; ?>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
