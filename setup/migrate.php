<?php
/**
 * Brings an existing database up to date with the current database.sql
 * without losing data. Safe to run more than once (it only adds what is missing).
 * It refuses to run from a browser.
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe setup\migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = db();

/** Columns that newer versions added to existing tables: table => [column => definition]. */
$columns = [
    'admins' => [
        'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = default/temporary password; blocked until changed' AFTER status",
        'failed_attempts'      => "TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consecutive failed sign-ins' AFTER must_change_password",
        'locked_until'         => "DATETIME NULL COMMENT 'sign-in blocked until this time after too many failures' AFTER failed_attempts",
        'session_version'      => "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'bumped on password change to sign out other sessions' AFTER locked_until",
    ],
];

$exists = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);

$added = 0;
foreach ($columns as $table => $defs) {
    foreach ($defs as $column => $definition) {
        $exists->execute([$table, $column]);
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            echo "Added {$table}.{$column}\n";
            $added++;
        }
    }
}

// Anyone still on the published default password must change it at next sign-in.
$flagged = 0;
foreach ($pdo->query('SELECT id, username, password_hash FROM admins WHERE must_change_password = 0')->fetchAll(PDO::FETCH_ASSOC) as $admin) {
    if (password_verify('admin123', $admin['password_hash'])) {
        $pdo->prepare('UPDATE admins SET must_change_password = 1 WHERE id = ?')->execute([$admin['id']]);
        echo "Administrator '{$admin['username']}' still uses the default password; it must be changed at next sign-in.\n";
        $flagged++;
    }
}

echo $added === 0 && $flagged === 0 ? "Database is already up to date.\n" : "Migration finished.\n";
