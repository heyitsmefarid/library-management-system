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
const LOGIN_MAX_ATTEMPTS = 5;      // failed attempts before a temporary lock
const LOGIN_LOCK_SECONDS = 300;

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
            ? db_one('SELECT id, username, full_name, email, status FROM admins WHERE id = ?', [$_SESSION['admin_id']])
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

    if (is_post() && !csrf_valid($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Your form has expired. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        header('Location: ' . ($back !== '' ? $back : url('dashboard.php')));
        exit;
    }
}

/**
 * Checks credentials and starts the admin session.
 * Returns null on success or an error message.
 */
function attempt_login(string $username, string $password): ?string
{
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $wait = (int) ceil(($lockedUntil - time()) / 60);
        return "Too many failed attempts. Try again in {$wait} minute(s).";
    }

    $admin = db_one('SELECT id, password_hash, status FROM admins WHERE username = ?', [$username]);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        $_SESSION['login_attempts'] = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + LOGIN_LOCK_SECONDS;
            $_SESSION['login_attempts'] = 0;
        }
        return 'Invalid username or password.';
    }

    if ($admin['status'] !== 'Active') {
        return 'This administrator account is inactive.';
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    session_regenerate_id(true);
    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
    $_SESSION['admin_id']      = (int) $admin['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
    db_exec('UPDATE admins SET last_login = NOW() WHERE id = ?', [$admin['id']]);

    return null;
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
