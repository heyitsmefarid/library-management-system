<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$book   = null;

if ($isEdit) {
    $book = db_one('SELECT * FROM books WHERE id = ?', [$id]);
    if (!$book) {
        flash('danger', 'Book not found.');
        redirect('books/index.php');
    }
}

$data = [
    'isbn'             => $book['isbn'] ?? '',
    'title'            => $book['title'] ?? '',
    'author'           => $book['author'] ?? '',
    'publisher'        => $book['publisher'] ?? '',
    'category_id'      => (string) ($book['category_id'] ?? ''),
    'publication_year' => (string) ($book['publication_year'] ?? ''),
    'shelf_location'   => $book['shelf_location'] ?? '',
    'total_copies'     => (string) ($book['total_copies'] ?? '1'),
    'description'      => $book['description'] ?? '',
];
$errors = [];

if (is_post()) {
    foreach (array_keys($data) as $field) {
        $data[$field] = post($field);
    }
    $data['isbn'] = strtoupper(str_replace([' ', '-'], '', $data['isbn']));

    if ($data['title'] === '') {
        $errors['title'] = 'Title is required.';
    } elseif (mb_strlen($data['title']) > 255) {
        $errors['title'] = 'Title is too long.';
    }
    if ($data['author'] === '') {
        $errors['author'] = 'Author is required.';
    } elseif (mb_strlen($data['author']) > 255) {
        $errors['author'] = 'Author is too long.';
    }
    if ($data['isbn'] !== '' && !preg_match('/^(\d{9}[\dX]|\d{13})$/', $data['isbn'])) {
        $errors['isbn'] = 'ISBN must have 10 or 13 digits (hyphens are allowed).';
    }
    if (mb_strlen($data['publisher']) > 150) {
        $errors['publisher'] = 'Publisher is too long.';
    }
    if ($data['category_id'] !== '' && !db_value('SELECT 1 FROM categories WHERE id = ?', [(int) $data['category_id']])) {
        $errors['category_id'] = 'Select a valid category.';
    }
    $maxYear = (int) date('Y') + 1;
    if ($data['publication_year'] !== '' && (!ctype_digit($data['publication_year'])
            || (int) $data['publication_year'] < 1000 || (int) $data['publication_year'] > $maxYear)) {
        $errors['publication_year'] = "Enter a year between 1000 and {$maxYear}.";
    }
    if (!ctype_digit($data['total_copies']) || (int) $data['total_copies'] < 1 || (int) $data['total_copies'] > 10000) {
        $errors['total_copies'] = 'Total copies must be a whole number from 1 to 10,000.';
    }
    if (mb_strlen($data['shelf_location']) > 50) {
        $errors['shelf_location'] = 'Shelf location is too long.';
    }

    if (!$errors) {
        $values = [
            $data['isbn'] !== '' ? $data['isbn'] : null,
            $data['title'],
            $data['author'],
            $data['publisher'] !== '' ? $data['publisher'] : null,
            $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            $data['publication_year'] !== '' ? (int) $data['publication_year'] : null,
            $data['shelf_location'] !== '' ? $data['shelf_location'] : null,
            $data['description'] !== '' ? $data['description'] : null,
        ];
        $newTotal = (int) $data['total_copies'];

        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($isEdit) {
                // Lock the row so concurrent borrowings cannot change the counts meanwhile.
                $current   = db_one('SELECT total_copies, available_copies FROM books WHERE id = ? FOR UPDATE', [$id]);
                $borrowed  = (int) $current['total_copies'] - (int) $current['available_copies'];
                $available = $newTotal - $borrowed;
                if ($available < 0) {
                    $pdo->rollBack();
                    $errors['total_copies'] = "{$borrowed} copies are currently borrowed, so total copies cannot be lower than {$borrowed}.";
                } else {
                    db_exec(
                        'UPDATE books SET isbn = ?, title = ?, author = ?, publisher = ?, category_id = ?, publication_year = ?,
                                shelf_location = ?, description = ?, total_copies = ?, available_copies = ?
                          WHERE id = ?',
                        array_merge($values, [$newTotal, $available, $id])
                    );
                    $pdo->commit();
                    flash('success', 'Book details were updated.');
                    redirect('books/view.php', ['id' => $id]);
                }
            } else {
                db_exec(
                    'INSERT INTO books (isbn, title, author, publisher, category_id, publication_year, shelf_location, description, total_copies, available_copies)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($values, [$newTotal, $newTotal])
                );
                $newId = (int) $pdo->lastInsertId();
                $pdo->commit();
                flash('success', '"' . $data['title'] . '" was added to the catalogue.');
                redirect('books/view.php', ['id' => $newId]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_duplicate_error($e)) {
                $errors['isbn'] = 'Another book already uses this ISBN.';
            } else {
                throw $e;
            }
        }
    }
}

$categories = db_all('SELECT id, name FROM categories ORDER BY name');

$pageTitle = $isEdit ? 'Edit Book' : 'Add Book';
$activeNav = 'books';
require __DIR__ . '/../includes/header.php';

$err = function (string $f) use ($errors): string {
    return isset($errors[$f]) ? '<span class="error-text">' . e($errors[$f]) . '</span>' : '';
};
$cls = function (string $f) use ($errors): string {
    return isset($errors[$f]) ? 'field has-error' : 'field';
};
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit book' : 'Add a new book' ?></h1>
        <p><?= $isEdit ? e($book['title']) : 'Fill in the book details below. Fields marked * are required.' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('books/index.php')) ?>"><?= icon('arrow-left') ?> Back to books</a>
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
                <div class="<?= $cls('title') ?> span-2">
                    <label for="title">Title <span class="req">*</span></label>
                    <input type="text" id="title" name="title" value="<?= e($data['title']) ?>" maxlength="255" required>
                    <?= $err('title') ?>
                </div>
                <div class="<?= $cls('author') ?>">
                    <label for="author">Author <span class="req">*</span></label>
                    <input type="text" id="author" name="author" value="<?= e($data['author']) ?>" maxlength="255" required>
                    <?= $err('author') ?>
                </div>
                <div class="<?= $cls('isbn') ?>">
                    <label for="isbn">ISBN</label>
                    <input type="text" id="isbn" name="isbn" value="<?= e($data['isbn']) ?>" maxlength="17" placeholder="e.g. 978-971-27-0086-1">
                    <?= $err('isbn') ?>
                </div>
                <div class="<?= $cls('publisher') ?>">
                    <label for="publisher">Publisher</label>
                    <input type="text" id="publisher" name="publisher" value="<?= e($data['publisher']) ?>" maxlength="150">
                    <?= $err('publisher') ?>
                </div>
                <div class="<?= $cls('category_id') ?>">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="">— Uncategorized —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $data['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('category_id') ?>
                    <span class="hint"><a href="<?= e(url('books/categories.php')) ?>">Manage categories</a></span>
                </div>
                <div class="<?= $cls('publication_year') ?>">
                    <label for="publication_year">Publication year</label>
                    <input type="number" id="publication_year" name="publication_year" value="<?= e($data['publication_year']) ?>" min="1000" max="<?= (int) date('Y') + 1 ?>">
                    <?= $err('publication_year') ?>
                </div>
                <div class="<?= $cls('shelf_location') ?>">
                    <label for="shelf_location">Shelf location</label>
                    <input type="text" id="shelf_location" name="shelf_location" value="<?= e($data['shelf_location']) ?>" maxlength="50" placeholder="e.g. FIC-03">
                    <?= $err('shelf_location') ?>
                </div>
                <div class="<?= $cls('total_copies') ?>">
                    <label for="total_copies">Total copies <span class="req">*</span></label>
                    <input type="number" id="total_copies" name="total_copies" value="<?= e($data['total_copies']) ?>" min="1" max="10000" required>
                    <?= $err('total_copies') ?>
                    <?php if ($isEdit): ?>
                        <span class="hint">Currently <?= (int) $book['available_copies'] ?> of <?= (int) $book['total_copies'] ?> available. Available copies are adjusted automatically.</span>
                    <?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="description">Description / notes</label>
                    <textarea id="description" name="description" rows="3"><?= e($data['description']) ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Add book' ?></button>
                <a class="btn btn-secondary" href="<?= e(url('books/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
