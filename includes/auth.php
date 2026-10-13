<?php
/**
 * Session-based authentication for administrators.
 *
 * Every protected page starts with:
 *     require_once __DIR__ . '/../includes/auth.php';
 *     require_login();
 *
 * require_login() also validates the CSRF token of every POST request,
 * so all forms must include <?= csrf_field() ?>.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

const SESSION_IDLE_LIMIT = 3600;   // seconds of inactivity before automatic logout
// Sign-in throttling is per computer (IP address), never per account, so a stranger
// cannot lock the librarian out and the lock looks the same for real and made-up usernames.
const LOGIN_MAX_ATTEMPTS    = 5;    // failures for one username from one computer...
const LOGIN_WINDOW_SECONDS  = 300;  // ...within this window block that pair
const LOGIN_MAX_IP_ATTEMPTS = 20;   // failures across all usernames from one computer...
const LOGIN_IP_WINDOW       = 900;  // ...within this window block that computer
const DEFAULT_ADMIN_PASSWORD = 'admin123'; // the published seed password; never allowed to stay in use

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('LIBMS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

start_secure_session();

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

/** The signed-in administrator row (cached per request). */
function current_admin(): ?array
{
    static $admin = false;
    if ($admin === false) {
        $admin = is_logged_in()
            ? db_one('SELECT id, username, full_name, email, status, session_version, must_change_password
                          FROM admins WHERE id = ?', [$_SESSION['admin_id']])
            : null;
    }
    return $admin;
}

function current_admin_id(): ?int
{
    return is_logged_in() ? (int) $_SESSION['admin_id'] : null;
}

/**
 * Blocks access unless an active administrator is signed in.
 * Also enforces the idle timeout and CSRF validation for POST requests.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }

    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_LIMIT) {
        logout_admin();
        start_secure_session();
        flash('warning', 'Your session expired. Please sign in again.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();

    $admin = current_admin();
    if (!$admin || $admin['status'] !== 'Active') {
        logout_admin();
        start_secure_session();
        flash('danger', 'Your administrator account is not active.');
        redirect('login.php');
    }

    // A password change or reset bumps session_version, which signs out every other browser.
    if ((int) $admin['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
        logout_admin();
        start_secure_session();
        flash('warning', 'Your password was changed. Please sign in again with the new password.');
        redirect('login.php');
    }

    // Accounts on a default or temporary password may only use the change-password page.
    if ((int) $admin['must_change_password'] === 1) {
        $script  = (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $allowed = (string) realpath(APP_ROOT . '/settings/account.php');
        if ($script === '' || $script !== $allowed) {
            flash('warning', 'Please set a new password before using the system.');
            redirect('settings/account.php');
        }
    }

    if (is_post() && !csrf_valid($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Your form has expired. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        header('Location: ' . ($back !== '' ? $back : url('dashboard.php')));
        exit;
    }
}

/** Client address used for throttling. Only REMOTE_ADDR: forwarded headers can be forged. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/** Usernames are compared case-insensitively, like the admins table collation. */
function login_key(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 50);
}

/**
 * Seconds this computer must wait before trying this username again, or 0.
 * Applies to every username typed, real or not, so it reveals nothing about accounts.
 */
function login_wait_seconds(string $username): int
{
    $ip   = client_ip();
    $pair = db_one(
        'SELECT COUNT(*) AS n, MIN(attempted_at) AS oldest FROM login_attempts
          WHERE username = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [login_key($username), $ip, LOGIN_WINDOW_SECONDS]
    );
    $host = db_one(
        'SELECT COUNT(*) AS n, MIN(attempted_at) AS oldest FROM login_attempts
          WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [$ip, LOGIN_IP_WINDOW]
    );

    $wait = 0;
    if ((int) $pair['n'] >= LOGIN_MAX_ATTEMPTS) {
        $wait = max($wait, strtotime($pair['oldest']) + LOGIN_WINDOW_SECONDS - time());
    }
    if ((int) $host['n'] >= LOGIN_MAX_IP_ATTEMPTS) {
        $wait = max($wait, strtotime($host['oldest']) + LOGIN_IP_WINDOW - time());
    }
    return max(0, $wait);
}

function record_failed_login(string $username): void
{
    db_exec('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)', [login_key($username), client_ip()]);
    // Keep the table small; old rows no longer affect any window.
    db_exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

/** Lets a username sign in again right away (after a password reset, or a successful sign-in). */
function clear_failed_logins(string $username, bool $allComputers = false): void
{
    if ($allComputers) {
        db_exec('DELETE FROM login_attempts WHERE username = ?', [login_key($username)]);
    } else {
        db_exec('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?', [login_key($username), client_ip()]);
    }
}

/**
 * Checks credentials and starts the admin session.
 * Returns null on success or an error message.
 */
function attempt_login(string $username, string $password): ?string
{
    $wait = login_wait_seconds($username);
    if ($wait > 0) {
        $minutes = max(1, (int) ceil($wait / 60));
        return "Too many failed sign-in attempts from this computer. Try again in {$minutes} minute(s).";
    }

    $admin = db_one(
        'SELECT id, username, password_hash, status, session_version, must_change_password FROM admins WHERE username = ?',
        [$username]
    );

    // Unknown usernames still cost one bcrypt check, so response times do not reveal valid usernames.
    $valid = password_verify($password, $admin['password_hash'] ?? '$2y$10$DTFs5fzvRBihv7gz6.v1qeK6JZluTADuNwhZPbwb6cDZhAvdXECAG');
    if (!$admin || !$valid) {
        record_failed_login($username);
        return 'Invalid username or password.';
    }

    if ($admin['status'] !== 'Active') {
        return 'This administrator account is inactive.';
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    // The published default password must always be replaced, whatever the flag says.
    $mustChange = (int) $admin['must_change_password'] === 1 || hash_equals(DEFAULT_ADMIN_PASSWORD, $password);

    db_exec('UPDATE admins SET last_login = NOW(), must_change_password = ? WHERE id = ?', [$mustChange ? 1 : 0, $admin['id']]);
    clear_failed_logins($username);

    session_regenerate_id(true);
    $_SESSION['admin_id']        = (int) $admin['id'];
    $_SESSION['session_version'] = (int) $admin['session_version'];
    $_SESSION['last_activity']   = time();
    $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

    return null;
}

/**
 * Sets a new password, signs the account out of every other browser and lifts any
 * sign-in throttle on that username. $temporary = true forces the owner to choose
 * their own password at next sign-in (used when another administrator sets it).
 * Returns the new session version.
 */
function set_admin_password(int $adminId, string $password, bool $temporary): int
{
    db_exec(
        'UPDATE admins SET password_hash = ?, must_change_password = ?, session_version = session_version + 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $temporary ? 1 : 0, $adminId]
    );
    $row = db_one('SELECT username, session_version FROM admins WHERE id = ?', [$adminId]);
    clear_failed_logins($row['username'], true);
    return (int) $row['session_version'];
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
