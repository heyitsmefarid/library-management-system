<?php
/**
 * Sidebar navigation, grouped by the kind of work. Included by header.php.
 * Uses $activeNav and $unreadCount from the header.
 */
$navGroups = [
    'At the desk' => [
        'dashboard'     => ['Dashboard',      'dashboard.php',             'grid'],
        'attendance'    => ['Attendance',     'attendance/index.php',      'clock'],
        'borrowing'     => ['Borrowing',      'borrowing/index.php',       'borrow'],
        'returning'     => ['Returning',      'returning/index.php',       'return'],
    ],
    'Collection' => [
        'books'         => ['Books',          'books/index.php',           'book'],
        'users'         => ['Library users',  'users/index.php',           'users'],
    ],
    'Follow-up' => [
        'notifications' => ['Notifications',  'notifications/index.php',   'bell'],
        'penalties'     => ['Penalties',      'penalties/index.php',       'card'],
        'reports'       => ['Reports',        'reports/index.php',         'file'],
    ],
    'Administration' => [
        'settings'      => ['Settings',       'settings/index.php',        'sliders'],
    ],
];
?>
<aside class="sidebar no-print" id="sidebar">
    <div class="brand">
        <span class="brand-mark"><?= icon('book-open', 22) ?></span>
        <span class="brand-text">
            <strong><?= e(setting('library_name', 'School Library')) ?></strong>
            <small>Attendance and borrowing</small>
        </span>
        <button type="button" class="icon-btn sidebar-close" data-sidebar-close aria-label="Close navigation">
            <?= icon('x', 18) ?>
        </button>
    </div>

    <nav class="nav" aria-label="Main">
        <?php foreach ($navGroups as $group => $items): ?>
            <div class="nav-section"><?= e($group) ?></div>
            <?php foreach ($items as $key => [$label, $href, $ico]): ?>
                <a href="<?= e(url($href)) ?>" class="nav-link<?= $activeNav === $key ? ' active' : '' ?>"
                   <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
                    <?= icon($ico) ?>
                    <span><?= e($label) ?></span>
                    <?php if ($key === 'notifications' && ($unreadCount ?? 0) > 0): ?>
                        <span class="nav-count" aria-label="<?= (int) $unreadCount ?> unread"><?= (int) $unreadCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a class="nav-link" href="<?= e(url('logout.php', ['token' => csrf_token()])) ?>" data-confirm-title="Sign out?" data-confirm-ok="Sign out"
           data-confirm="You will need your username and password to sign in again.">
            <?= icon('log-out') ?><span>Sign out</span>
        </a>
    </div>
</aside>
