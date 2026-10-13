<?php
/**
 * Shared helper functions used by every module.
 */

/* ---------------------------------------------------------------------
 * Output & request helpers
 * ------------------------------------------------------------------- */

/** Escapes a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Web path of the application root (e.g. "/library_management_system").
 * Works no matter which folder the project is placed in.
 */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script   = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root     = str_replace('\\', '/', (string) realpath(APP_ROOT));
        $relative = substr($script, strlen($root));                 // e.g. "/books/index.php"
        $name     = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base     = ($relative !== '' && substr($name, -strlen($relative)) === $relative)
            ? substr($name, 0, -strlen($relative))
            : rtrim(dirname($name), '/');
    }
    return $base;
}

/** Builds an absolute URL path inside the application. */
function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed POST value. */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Trimmed GET value. */
function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Re-applies the current query string with some keys replaced. */
function query_with(array $replace): string
{
    $q = array_merge($_GET, $replace);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        }
    }
    return $q ? '?' . http_build_query($q) : '?';
}

/* ---------------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ---------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* ---------------------------------------------------------------------
 * Database shortcuts (all use prepared statements)
 * ------------------------------------------------------------------- */

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** True when a PDOException is a duplicate-key violation. */
function is_duplicate_error(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) == 1062;
}

/** True when a PDOException is a foreign-key restriction. */
function is_fk_error(PDOException $e): bool
{
    return in_array($e->errorInfo[1] ?? null, [1451, 1452], false);
}

/* ---------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------- */

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reset__') {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
        if ($key === '__reset__') {
            return null;
        }
    }
    return $cache[$key] ?? $default;
}

function save_setting(string $key, string $value, ?string $description = null): void
{
    db_exec(
        'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value, $description]
    );
    setting('__reset__');
}

function penalty_rate(): float
{
    return (float) setting('penalty_rate', '5.00');
}

/* ---------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------- */

function money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function today(): string
{
    return date('Y-m-d');
}

function fmt_date(?string $value): string
{
    return $value ? date('M d, Y', strtotime($value)) : '—';
}

function fmt_datetime(?string $value): string
{
    return $value ? date('M d, Y h:i A', strtotime($value)) : '—';
}

function fmt_time(?string $value): string
{
    return $value ? date('h:i A', strtotime($value)) : '—';
}

/** Human readable duration between two datetimes. */
function fmt_duration(string $from, ?string $to = null): string
{
    $seconds = max(0, strtotime($to ?? date('Y-m-d H:i:s')) - strtotime($from));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

/** Validates a Y-m-d date string. */
function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

/** Full name from a row having first_name / middle_name / last_name. */
function full_name(array $row, string $prefix = ''): string
{
    $first  = $row[$prefix . 'first_name'] ?? '';
    $middle = $row[$prefix . 'middle_name'] ?? '';
    $last   = $row[$prefix . 'last_name'] ?? '';
    $mi     = $middle !== '' && $middle !== null ? ' ' . mb_substr($middle, 0, 1) . '.' : '';
    return trim($first . $mi . ' ' . $last);
}

/* ---------------------------------------------------------------------
 * Borrowing rules
 * ------------------------------------------------------------------- */

/** Whole days a book is late; 0 when returned on or before the due date. */
function overdue_days(string $dueDate, ?string $returnDate = null): int
{
    $due    = new DateTime(substr($dueDate, 0, 10));
    $return = new DateTime(substr($returnDate ?? today(), 0, 10));
    if ($return <= $due) {
        return 0;
    }
    return (int) $due->diff($return)->days;
}

function compute_penalty(int $overdueDays, ?float $rate = null): float
{
    return round($overdueDays * ($rate ?? penalty_rate()), 2);
}

/** Generates a readable reference such as BRW-20261014-00012. */
function reference_no(string $prefix, int $id): string
{
    return sprintf('%s-%s-%05d', $prefix, date('Ymd'), $id);
}

/* ---------------------------------------------------------------------
 * Notifications
 * ------------------------------------------------------------------- */

/**
 * Creates/updates notifications from the live borrowing records.
 * Each active borrowing has at most one notification whose type follows
 * its state: due within N days -> due_soon, due today -> due_today,
 * past due -> overdue. When the type changes the notification becomes
 * unread again. Notifications of returned books are removed.
 */
function sync_notifications(): void
{
    $today     = today();
    $soonDays  = max(1, (int) setting('due_soon_days', 3));
    $windowEnd = date('Y-m-d', strtotime("+{$soonDays} days"));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = [];
        foreach (db_all('SELECT borrowing_id, type FROM notifications') as $n) {
            $existing[(int) $n['borrowing_id']] = $n['type'];
        }

        $loans = db_all(
            "SELECT br.id, br.due_date, br.transaction_no, bk.title, u.first_name, u.last_name
               FROM borrowings br
               JOIN books bk ON bk.id = br.book_id
               JOIN users u  ON u.id  = br.user_id
              WHERE br.status = 'Borrowed' AND br.due_date <= ?",
            [$windowEnd]
        );

        $upsert = $pdo->prepare(
            'INSERT INTO notifications (borrowing_id, type, message) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_read    = IF(type = VALUES(type), is_read, 0),
                read_at    = IF(type = VALUES(type), read_at, NULL),
                created_at = IF(type = VALUES(type), created_at, NOW()),
                message    = VALUES(message),
                type       = VALUES(type)'
        );

        $keep = [];
        foreach ($loans as $loan) {
            $id = (int) $loan['id'];
            $keep[$id] = true;
            if ($loan['due_date'] < $today) {
                $type = 'overdue';
                $msg  = sprintf('"%s" borrowed by %s %s is overdue (due %s).',
                    $loan['title'], $loan['first_name'], $loan['last_name'], fmt_date($loan['due_date']));
            } elseif ($loan['due_date'] === $today) {
                $type = 'due_today';
                $msg  = sprintf('"%s" borrowed by %s %s is due today.',
                    $loan['title'], $loan['first_name'], $loan['last_name']);
            } else {
                $type = 'due_soon';
                $msg  = sprintf('"%s" borrowed by %s %s is due on %s.',
                    $loan['title'], $loan['first_name'], $loan['last_name'], fmt_date($loan['due_date']));
            }
            if (($existing[$id] ?? null) !== $type) {
                $upsert->execute([$id, $type, $msg]);
            }
        }

        $stale = array_diff_key($existing, $keep);
        if ($stale) {
            $ids = array_keys($stale);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            db_exec("DELETE FROM notifications WHERE borrowing_id IN ($in)", $ids);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Notification sync failed: ' . $e->getMessage());
    }
}

function unread_notification_count(): int
{
    return (int) db_value('SELECT COUNT(*) FROM notifications WHERE is_read = 0');
}

/* ---------------------------------------------------------------------
 * Pagination
 * ------------------------------------------------------------------- */

function paginate(int $total, int $perPage = 15, string $param = 'page'): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, (int) ($_GET[$param] ?? 1)), $pages);
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
        'param'    => $param,
    ];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $key   = $p['param'];
    $html  = '<nav class="pagination" aria-label="Pagination">';
    $html .= '<span class="pagination-info">Page ' . $p['page'] . ' of ' . $p['pages'] . ' · ' . $p['total'] . ' records</span>';
    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $p['page'] + 2);
    if ($p['page'] > 1) {
        $html .= '<a href="' . e(query_with([$key => $p['page'] - 1])) . '">&laquo; Prev</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $p['page']
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . e(query_with([$key => $i])) . '">' . $i . '</a>';
    }
    if ($p['page'] < $p['pages']) {
        $html .= '<a href="' . e(query_with([$key => $p['page'] + 1])) . '">Next &raquo;</a>';
    }
    return $html . '</nav>';
}

/* ---------------------------------------------------------------------
 * UI helpers
 * ------------------------------------------------------------------- */

function badge(string $text, ?string $variant = null): string
{
    $map = [
        'Active' => 'success', 'Available' => 'success', 'Returned' => 'success', 'Paid' => 'success', 'Good' => 'success',
        'Borrowed' => 'info', 'Inside' => 'info', 'due_soon' => 'info',
        'Inactive' => 'muted', 'Out' => 'muted',
        'Suspended' => 'danger', 'Overdue' => 'danger', 'overdue' => 'danger', 'Unpaid' => 'danger', 'Lost' => 'danger',
        'due_today' => 'warning', 'Damaged' => 'warning', 'Unavailable' => 'warning',
    ];
    $labels = ['due_soon' => 'Due soon', 'due_today' => 'Due today', 'overdue' => 'Overdue'];
    $variant = $variant ?? ($map[$text] ?? 'muted');
    return '<span class="badge badge-' . e($variant) . '">' . e($labels[$text] ?? $text) . '</span>';
}

/** Feather-style inline SVG icons (no external files required). */
function icon(string $name, int $size = 18): string
{
    static $icons = [
        'grid'      => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'book'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'book-open' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'log-in'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'log-out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'borrow'    => '<line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/>',
        'return'    => '<polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/>',
        'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'card'      => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'sliders'   => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'printer'   => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'menu'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'alert'     => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'user-check'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        'tag'       => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'arrow-left'=> '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'mail'      => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    ];
    $paths = $icons[$name] ?? $icons['grid'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

/** Renders an empty-state row for tables. */
function empty_row(int $colspan, string $message = 'No records found.'): string
{
    return '<tr><td colspan="' . $colspan . '" class="empty">' . e($message) . '</td></tr>';
}
