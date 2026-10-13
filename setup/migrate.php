<?php
/**
 * Brings an existing database up to date with the current database.sql
 * without losing data. Safe to run more than once (it only changes what is out of date).
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

$pdo     = db();
$changes = 0;

$columnExists = function (string $table, string $column) use ($pdo): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $column]);
    return (int) $q->fetchColumn() > 0;
};

// 1. Columns that newer versions added to existing tables: table => [column => definition].
$add = [
    'admins' => [
        'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = default/temporary password; blocked until changed' AFTER status",
        'session_version'      => "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'bumped on password change to sign out other sessions' AFTER must_change_password",
    ],
];
foreach ($add as $table => $defs) {
    foreach ($defs as $column => $definition) {
        if (!$columnExists($table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            echo "Added {$table}.{$column}\n";
            $changes++;
        }
    }
}

// 2. Columns from an earlier version that are no longer used (per-account lockout,
//    replaced by per-computer throttling in login_attempts).
foreach (['admins' => ['failed_attempts', 'locked_until']] as $table => $columns) {
    foreach ($columns as $column) {
        if ($columnExists($table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            echo "Removed unused {$table}.{$column}\n";
            $changes++;
        }
    }
}

// 3. Tables added in newer versions.
$tableExists = $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'"
)->fetchColumn();
if ((int) $tableExists === 0) {
    $pdo->exec(
        'CREATE TABLE login_attempts (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username        VARCHAR(50)  NOT NULL,
            ip_address      VARCHAR(45)  NOT NULL,
            attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_login_attempts_pair (username, ip_address, attempted_at),
            KEY idx_login_attempts_ip (ip_address, attempted_at),
            KEY idx_login_attempts_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    echo "Created table login_attempts\n";
    $changes++;
}

// 4. Anyone still on the published default password must change it at next sign-in.
foreach ($pdo->query('SELECT id, username, password_hash FROM admins WHERE must_change_password = 0')->fetchAll(PDO::FETCH_ASSOC) as $admin) {
    if (password_verify('admin123', $admin['password_hash'])) {
        $pdo->prepare('UPDATE admins SET must_change_password = 1 WHERE id = ?')->execute([$admin['id']]);
        echo "Administrator '{$admin['username']}' still uses the default password; it must be changed at next sign-in.\n";
        $changes++;
    }
}

echo $changes === 0 ? "Database is already up to date.\n" : "Migration finished.\n";
