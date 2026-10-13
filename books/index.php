<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$q            = get('q');
$categoryId   = (int) get('category');
$availability = get('availability');

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR c.name LIKE ? OR b.publisher LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($categoryId > 0) {
    $where[]  = 'b.category_id = ?';
    $params[] = $categoryId;
}
if ($availability === 'available') {
    $where[] = 'b.available_copies > 0';
} elseif ($availability === 'unavailable') {
    $where[] = 'b.available_copies = 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM books b LEFT JOIN categories c ON c.id = b.category_id $whereSql", $params);
$p     = paginate($total, 15);
$books = db_all(
    "SELECT b.*, c.name AS category_name
       FROM books b LEFT JOIN categories c ON c.id = b.category_id
       $whereSql
      ORDER BY b.title ASC
      LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$categories = db_all('SELECT id, name FROM categories ORDER BY name');
$totals     = db_one('SELECT COUNT(*) AS titles, COALESCE(SUM(total_copies),0) AS copies, COALESCE(SUM(available_copies),0) AS available FROM books');

$pageTitle = 'Books';
$activeNav = 'books';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Book Management</h1>
        <p><?= number_format($totals['titles']) ?> titles · <?= number_format($totals['copies']) ?> copies · <?= number_format($totals['available']) ?> available</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('books/categories.php')) ?>"><?= icon('tag') ?> Categories</a>
        <a class="btn" href="<?= e(url('books/form.php')) ?>"><?= icon('plus') ?> Add book</a>
    </div>
</div>

<section class="card">
    <form class="toolbar" method="get">
        <div class="field grow">
            <label for="q">Search</label>
            <div class="search-input">
                <?= icon('search', 16) ?>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Title, author, ISBN, category or publisher">
            </div>
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="availability">Availability</label>
            <select id="availability" name="availability">
                <option value="">All</option>
                <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>No copies left</option>
            </select>
        </div>
        <button class="btn" type="submit"><?= icon('search', 16) ?> Search</button>
        <?php if ($q !== '' || $categoryId || $availability !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(url('books/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Title / Author</th>
                <th>ISBN</th>
                <th>Category</th>
                <th>Publisher</th>
                <th class="num">Year</th>
                <th class="num">Copies</th>
                <th>Status</th>
                <th class="actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$books): ?>
                <?= empty_row(8, $q !== '' ? 'No books match your search.' : 'No books yet. Click "Add book" to create one.') ?>
            <?php endif; ?>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td>
                        <a class="cell-title" href="<?= e(url('books/view.php', ['id' => $b['id']])) ?>"><?= e($b['title']) ?></a>
                        <span class="cell-sub"><?= e($b['author']) ?></span>
                    </td>
                    <td class="nowrap"><?= e($b['isbn'] ?: '—') ?></td>
                    <td><?= e($b['category_name'] ?? 'Uncategorized') ?></td>
                    <td><?= e($b['publisher'] ?: '—') ?></td>
                    <td class="num"><?= e($b['publication_year'] ?: '—') ?></td>
                    <td class="num nowrap"><strong><?= (int) $b['available_copies'] ?></strong> / <?= (int) $b['total_copies'] ?></td>
                    <td><?= badge($b['available_copies'] > 0 ? 'Available' : 'Unavailable') ?></td>
                    <td class="actions">
                        <?php if ($b['available_copies'] > 0): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('borrowing/create.php', ['book_id' => $b['id']])) ?>" title="Lend this book"><?= icon('borrow', 15) ?></a>
                        <?php endif; ?>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('books/view.php', ['id' => $b['id']])) ?>" title="View"><?= icon('eye', 15) ?></a>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('books/form.php', ['id' => $b['id']])) ?>" title="Edit"><?= icon('edit', 15) ?></a>
                        <form method="post" action="<?= e(url('books/delete.php')) ?>" data-confirm-title="Delete this book?" data-confirm-ok="Delete book" data-confirm-variant="danger"
                              data-confirm="&quot;<?= e($b['title']) ?>&quot; will be removed from the catalogue. This cannot be undone.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button class="btn btn-ghost btn-sm text-danger" type="submit" title="Delete"><?= icon('trash', 15) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
