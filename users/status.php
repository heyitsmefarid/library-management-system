<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!is_post()) {
    redirect('users/index.php');
}

$id     = (int) post('id');
$status = post('status');

if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
    flash('danger', 'Invalid status.');
    redirect('users/view.php', ['id' => $id]);
}

$user = db_one('SELECT id, first_name, last_name, status FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash('danger', 'User not found.');
    redirect('users/index.php');
}

db_exec('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
flash('success', "{$user['first_name']} {$user['last_name']}'s account is now {$status}.");
redirect('users/view.php', ['id' => $id]);
