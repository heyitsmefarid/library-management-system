<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id   = (int) get('id');
$book = db_one(
    'SELECT b.*, c.name AS category_name FROM books b LEFT JOIN categories c ON c.id = b.category_id WHERE b.id = ?',
    [$id]
);
if (!$book) {
    flash('danger', 'Book not found.');
    redirect('books/index.php');
}

$total = (int) db_value('SELECT COUNT(*) FROM borrowings WHERE book_id = ?', [$id]);
$p     = paginate($total, 10);
$history = db_all(
    "SELECT br.*, u.first_name, u.last_name, u.id_number, r.return_date
       FROM borrowings br
       JOIN users u ON u.id = br.user_id
       LEFT JOIN returns r ON r.borrowing_id = br.id
      WHERE br.book_id = ?
      ORDER BY br.id DESC
      LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    [$id]
);

$pageTitle = $book['title'];
$activeNav = 'books';
require __DIR__ . '/../includes/header.php';
$today = today();
?>

<div class="page-head">
    <div>
        <h1><?= e($book['title']) ?></h1>
        <p>by <?= e($book['author']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('books/index.php')) ?>"><?= icon('arrow-left') ?> Books</a>
        <a class="btn btn-secondary" href="<?= e(url('books/form.php', ['id' => $id])) ?>"><?= icon('edit') ?> Edit</a>
        <?php if ($book['available_copies'] > 0): ?>
            <a class="btn" href="<?= e(url('borrowing/create.php', ['book_id' => $id])) ?>"><?= icon('borrow') ?> Lend this book</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-3-1">
    <section class="card">
        <div class="card-head"><h2>Borrowing history</h2><span class="muted"><?= $total ?> transaction(s)</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Transaction</th><th>Borrower</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$history): ?><?= empty_row(6, 'This book has not been borrowed yet.') ?><?php endif; ?>
                <?php foreach ($history as $h):
                    $status = $h['status'] === 'Borrowed' && $h['due_date'] < $today ? 'Overdue' : $h['status']; ?>
                    <tr>
                        <td><a href="<?= e(url('borrowing/view.php', ['id' => $h['id']])) ?>"><?= e($h['transaction_no']) ?></a></td>
                        <td>
                            <a href="<?= e(url('users/view.php', ['id' => $h['user_id']])) ?>"><?= e($h['first_name'] . ' ' . $h['last_name']) ?></a>
                            <span class="cell-sub"><?= e($h['id_number']) ?></span>
                        </td>
                        <td class="nowrap"><?= fmt_date($h['borrow_date']) ?></td>
                        <td class="nowrap"><?= fmt_date($h['due_date']) ?></td>
                        <td class="nowrap"><?= fmt_date($h['return_date']) ?></td>
                        <td><?= badge($status) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($p) ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Book details</h2><?= badge($book['available_copies'] > 0 ? 'Available' : 'Unavailable') ?></div>
        <div class="card-body">
            <dl class="details">
                <dt>ISBN</dt><dd><?= e($book['isbn'] ?: '—') ?></dd>
                <dt>Author</dt><dd><?= e($book['author']) ?></dd>
                <dt>Publisher</dt><dd><?= e($book['publisher'] ?: '—') ?></dd>
                <dt>Category</dt><dd><?= e($book['category_name'] ?? 'Uncategorized') ?></dd>
                <dt>Year</dt><dd><?= e($book['publication_year'] ?: '—') ?></dd>
                <dt>Shelf</dt><dd><?= e($book['shelf_location'] ?: '—') ?></dd>
                <dt>Total copies</dt><dd><?= (int) $book['total_copies'] ?></dd>
                <dt>Available</dt><dd><?= (int) $book['available_copies'] ?></dd>
                <dt>Borrowed</dt><dd><?= (int) $book['total_copies'] - (int) $book['available_copies'] ?></dd>
                <dt>Added</dt><dd><?= fmt_date($book['created_at']) ?></dd>
            </dl>
            <?php if ($book['description']): ?>
                <p class="muted" style="margin-bottom:0"><?= nl2br(e($book['description'])) ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
