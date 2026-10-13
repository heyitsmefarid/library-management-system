<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$errors = [];
$editId = (int) get('edit');
$form   = ['name' => '', 'description' => ''];

if ($editId) {
    $cat = db_one('SELECT * FROM categories WHERE id = ?', [$editId]);
    if (!$cat) {
        flash('danger', 'Category not found.');
        redirect('books/categories.php');
    }
    $form = ['name' => $cat['name'], 'description' => (string) $cat['description']];
}

if (is_post()) {
    $action = post('action');

    if ($action === 'delete') {
        $id  = (int) post('id');
        $cat = db_one('SELECT name FROM categories WHERE id = ?', [$id]);
        if ($cat) {
            // Books in this category become "Uncategorized" (ON DELETE SET NULL).
            db_exec('DELETE FROM categories WHERE id = ?', [$id]);
            flash('success', "Category \"{$cat['name']}\" was deleted.");
        }
        redirect('books/categories.php');
    }

    $id   = (int) post('id');
    $form = ['name' => post('name'), 'description' => post('description')];

    if ($form['name'] === '') {
        $errors['name'] = 'Category name is required.';
    } elseif (mb_strlen($form['name']) > 80) {
        $errors['name'] = 'Category name must be at most 80 characters.';
    }
    if (mb_strlen($form['description']) > 255) {
        $errors['description'] = 'Description must be at most 255 characters.';
    }

    if (!$errors) {
        try {
            $desc = $form['description'] !== '' ? $form['description'] : null;
            if ($id > 0) {
                db_exec('UPDATE categories SET name = ?, description = ? WHERE id = ?', [$form['name'], $desc, $id]);
                flash('success', 'Category updated.');
            } else {
                db_exec('INSERT INTO categories (name, description) VALUES (?, ?)', [$form['name'], $desc]);
                flash('success', "Category \"{$form['name']}\" added.");
            }
            redirect('books/categories.php');
        } catch (PDOException $e) {
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $errors['name'] = 'A category with this name already exists.';
        }
    }
    $editId = $id;
}

$categories = db_all(
    'SELECT c.*, COUNT(b.id) AS book_count, COALESCE(SUM(b.total_copies), 0) AS copies
       FROM categories c LEFT JOIN books b ON b.category_id = c.id
      GROUP BY c.id
      ORDER BY c.name'
);

$pageTitle = 'Book Categories';
$activeNav = 'books';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Book categories</h1>
        <p>Organize the catalogue into categories.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('books/index.php')) ?>"><?= icon('arrow-left') ?> Back to books</a>
    </div>
</div>

<div class="grid-3-1">
    <section class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th class="num">Titles</th><th class="num">Copies</th><th class="actions"></th></tr></thead>
                <tbody>
                <?php if (!$categories): ?><?= empty_row(4, 'No categories yet.') ?><?php endif; ?>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('books/index.php', ['category' => $c['id']])) ?>"><?= e($c['name']) ?></a>
                            <span class="cell-sub"><?= e($c['description'] ?: '') ?></span>
                        </td>
                        <td class="num"><?= (int) $c['book_count'] ?></td>
                        <td class="num"><?= (int) $c['copies'] ?></td>
                        <td class="actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('books/categories.php', ['edit' => $c['id']])) ?>" title="Edit"><?= icon('edit', 15) ?></a>
                            <form method="post" data-confirm-title="Delete this category?" data-confirm-ok="Delete category" data-confirm-variant="danger"
                                  data-confirm="&quot;<?= e($c['name']) ?>&quot; will be deleted. Its <?= (int) $c['book_count'] ?> book(s) will become uncategorized.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-ghost btn-sm text-danger" type="submit" title="Delete"><?= icon('trash', 15) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2><?= $editId ? 'Edit category' : 'Add category' ?></h2></div>
        <div class="card-body">
            <form method="post" action="<?= e(url('books/categories.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $editId ?>">
                <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>" style="margin-bottom:14px">
                    <label for="name">Name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="80" required>
                    <?php if (isset($errors['name'])): ?><span class="error-text"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="field<?= isset($errors['description']) ? ' has-error' : '' ?>">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" maxlength="255"><?= e($form['description']) ?></textarea>
                    <?php if (isset($errors['description'])): ?><span class="error-text"><?= e($errors['description']) ?></span><?php endif; ?>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit"><?= icon('check') ?> <?= $editId ? 'Save changes' : 'Add category' ?></button>
                    <?php if ($editId): ?>
                        <a class="btn btn-secondary" href="<?= e(url('books/categories.php')) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
