<?php
/**
 * Command-line tool to create an administrator or reset a password.
 * It refuses to run from a browser.
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe setup\create_admin.php <username> <password> ["Full Name"]
 *
 * If the username exists, its password is reset and the account re-activated.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

[$script, $username, $password, $fullName] = array_pad($argv, 4, null);

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php setup/create_admin.php <username> <password> [\"Full Name\"]\n");
    exit(1);
}
if (!preg_match('/^[a-z0-9_.]{4,50}$/', $username)) {
    fwrite(STDERR, "Username must be 4-50 lowercase letters, numbers, dots or underscores.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo  = db();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
$stmt->execute([$username]);
$id = $stmt->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE admins SET password_hash = ?, status = 'Active' WHERE id = ?")->execute([$hash, $id]);
    echo "Password reset for administrator '{$username}'.\n";
} else {
    $pdo->prepare('INSERT INTO admins (username, password_hash, full_name) VALUES (?, ?, ?)')
        ->execute([$username, $hash, $fullName ?: $username]);
    echo "Administrator '{$username}' created.\n";
}
