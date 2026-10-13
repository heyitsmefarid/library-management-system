<?php
/**
 * Database connection and core application configuration.
 *
 * Edit the DB_* constants to match your MySQL server.
 * XAMPP defaults: host "localhost", user "root", empty password.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'library_management_system');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Library Attendance & Borrowing System');
define('APP_TIMEZONE', 'Asia/Manila');
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set(APP_TIMEZONE);

/**
 * Returns a shared PDO connection.
 * Prepared statements are real (not emulated) and errors throw exceptions.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Keep MySQL date functions in the same time zone as PHP.
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Database connection failed: ' . $e->getMessage());
            exit('<h2 style="font-family:sans-serif">Database connection failed.</h2>'
                . '<p style="font-family:sans-serif">Check that MySQL is running and that the settings in '
                . '<code>config/database.php</code> are correct, and that <code>database.sql</code> has been imported.</p>');
        }
    }

    return $pdo;
}
