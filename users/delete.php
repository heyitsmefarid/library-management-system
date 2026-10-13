<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!is_post()) {
    redirect('users/index.php');
}

$id   = (int) post('id');
$user = db_one('SELECT id, first_name, last_name FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash('danger', 'User not found.');
    redirect('users/index.php');
}

$name = $user['first_name'] . ' ' . $user['last_name'];

try {
    db_exec('DELETE FROM users WHERE id = ?', [$id]);
    flash('success', "{$name} was removed.");
    redirect('users/index.php');
} catch (PDOException $e) {
    if (!is_fk_error($e)) {
        throw $e;
    }
    // Attendance / borrowing records reference this user; they must be preserved.
    flash('danger', "{$name} has attendance or borrowing records and cannot be deleted. Set the account to Inactive instead.");
    redirect('users/view.php', ['id' => $id]);
}
