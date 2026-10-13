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
const LOGIN_MAX_ATTEMPTS = 5;      // failed attempts before the account is locked
const LOGIN_LOCK_SECONDS = 300;    // how long a locked account stays locked
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

/**
 * Checks credentials and starts the admin session.
 * Returns null on success or an error message.
 *
 * Failed attempts are counted on the admin row (not in the browser session),
 * so clearing cookies does not reset the count.
 */
function attempt_login(string $username, string $password): ?string
{
    $admin = db_one(
        'SELECT id, password_hash, status, session_version, must_change_password,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
                TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS lock_seconds
           FROM admins WHERE username = ?',
        [$username]
    );

    if (!$admin) {
        // Spend the same time as a real check so response times do not reveal valid usernames.
        password_verify($password, '$2y$10$DTFs5fzvRBihv7gz6.v1qeK6JZluTADuNwhZPbwb6cDZhAvdXECAG');
        return 'Invalid username or password.';
    }

    if ((int) $admin['is_locked'] === 1) {
        $wait = max(1, (int) ceil(((int) $admin['lock_seconds']) / 60));
        return "Too many failed attempts. This account is locked; try again in {$wait} minute(s).";
    }

    if (!password_verify($password, $admin['password_hash'])) {
        // One atomic statement: count the failure and lock the account on the limit.
        db_exec(
            'UPDATE admins
                SET locked_until    = IF(failed_attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), locked_until),
                    failed_attempts = IF(failed_attempts + 1 >= ?, 0, failed_attempts + 1)
              WHERE id = ?',
            [LOGIN_MAX_ATTEMPTS, LOGIN_LOCK_SECONDS, LOGIN_MAX_ATTEMPTS, $admin['id']]
        );
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

    db_exec(
        'UPDATE admins SET last_login = NOW(), failed_attempts = 0, locked_until = NULL, must_change_password = ? WHERE id = ?',
        [$mustChange ? 1 : 0, $admin['id']]
    );

    session_regenerate_id(true);
    $_SESSION['admin_id']        = (int) $admin['id'];
    $_SESSION['session_version'] = (int) $admin['session_version'];
    $_SESSION['last_activity']   = time();
    $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

    return null;
}

/**
 * Sets a new password and signs the account out of every other browser.
 * $temporary = true forces the owner to choose their own password at next sign-in
 * (used when another administrator sets it). Returns the new session version.
 */
function set_admin_password(int $adminId, string $password, bool $temporary): int
{
    db_exec(
        'UPDATE admins
            SET password_hash = ?, must_change_password = ?, session_version = session_version + 1,
                failed_attempts = 0, locked_until = NULL
          WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $temporary ? 1 : 0, $adminId]
    );
    return (int) db_value('SELECT session_version FROM admins WHERE id = ?', [$adminId]);
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
