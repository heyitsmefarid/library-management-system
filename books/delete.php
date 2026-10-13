<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!is_post()) {
    redirect('books/index.php');
}

$id   = (int) post('id');
$book = db_one('SELECT id, title FROM books WHERE id = ?', [$id]);

if (!$book) {
    flash('danger', 'Book not found.');
    redirect('books/index.php');
}

$active = (int) db_value("SELECT COUNT(*) FROM borrowings WHERE book_id = ? AND status = 'Borrowed'", [$id]);
if ($active > 0) {
    flash('danger', "\"{$book['title']}\" cannot be deleted while {$active} copy/copies are still borrowed.");
    redirect('books/index.php');
}

try {
    db_exec('DELETE FROM books WHERE id = ?', [$id]);
    flash('success', "\"{$book['title']}\" was deleted.");
} catch (PDOException $e) {
    if (!is_fk_error($e)) {
        throw $e;
    }
    // Borrowing history references this book; keep it for record integrity.
    flash('danger', "\"{$book['title']}\" has borrowing history and cannot be deleted, because its transaction records must be kept.");
}

redirect('books/index.php');
