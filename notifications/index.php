<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post()) {
    $action = post('action');
    $id     = (int) post('id');

    if ($action === 'read') {
        db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?', [$id]);
        flash('success', 'Notification marked as read.');
    } elseif ($action === 'unread') {
        db_exec('UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ?', [$id]);
        flash('success', 'Notification marked as unread.');
    } elseif ($action === 'read_all') {
        $n = db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
        flash('success', "{$n} notification(s) marked as read.");
    }
    redirect('notifications/index.php', array_filter(['type' => get('type'), 'read' => get('read')]));
}

// header.php re-syncs too, but syncing here makes the counts below current.
sync_notifications();

$type = get('type');
$read = get('read');
$types = ['due_today' => 'Due today', 'due_soon' => 'Due within ' . (int) setting('due_soon_days', 3) . ' days', 'overdue' => 'Overdue'];

$where  = [];
$params = [];
if (isset($types[$type])) {
    $where[]  = 'n.type = ?';
    $params[] = $type;
}
if ($read === 'unread') {
    $where[] = 'n.is_read = 0';
} elseif ($read === 'read') {
    $where[] = 'n.is_read = 1';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base  = 'FROM notifications n
          JOIN borrowings br ON br.id = n.borrowing_id
          JOIN books bk ON bk.id = br.book_id
          JOIN users u ON u.id = br.user_id';
$total = (int) db_value("SELECT COUNT(*) $base $whereSql", $params);
$p     = paginate($total, 20);
$rows  = db_all(
    "SELECT n.*, br.transaction_no, br.due_date, br.borrow_date, br.user_id, bk.title,
            u.first_name, u.last_name, u.id_number, u.phone, u.email
     $base $whereSql
     ORDER BY n.is_read ASC, FIELD(n.type, 'overdue', 'due_today', 'due_soon'), br.due_date ASC
     LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$counts = ['due_today' => 0, 'due_soon' => 0, 'overdue' => 0];
foreach (db_all('SELECT type, COUNT(*) AS c FROM notifications GROUP BY type') as $c) {
    $counts[$c['type']] = (int) $c['c'];
}
$unread = unread_notification_count();

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/../includes/header.php';

$filterUrl = fn(array $q): string => url('notifications/index.php', array_filter(array_merge(['type' => $type, 'read' => $read], $q)));
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p>Generated automatically from borrowing records · <?= $unread ?> unread</p>
    </div>
    <div class="page-actions">
        <?php if ($unread > 0): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="read_all">
                <button class="btn btn-secondary" type="submit"><?= icon('check') ?> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="stats">
    <a class="stat" href="<?= e($filterUrl(['type' => 'due_today'])) ?>">
        <span class="stat-icon warning"><?= icon('calendar', 22) ?></span>
        <span><span class="stat-value"><?= $counts['due_today'] ?></span><span class="stat-label">Due today</span></span>
    </a>
    <a class="stat" href="<?= e($filterUrl(['type' => 'due_soon'])) ?>">
        <span class="stat-icon info"><?= icon('clock', 22) ?></span>
        <span><span class="stat-value"><?= $counts['due_soon'] ?></span><span class="stat-label"><?= e($types['due_soon']) ?></span></span>
    </a>
    <a class="stat" href="<?= e($filterUrl(['type' => 'overdue'])) ?>">
        <span class="stat-icon danger"><?= icon('alert', 22) ?></span>
        <span><span class="stat-value"><?= $counts['overdue'] ?></span><span class="stat-label">Overdue</span></span>
    </a>
</section>

<section class="card">
    <nav class="tabs">
        <a class="tab<?= $type === '' ? ' active' : '' ?>" href="<?= e($filterUrl(['type' => ''])) ?>">All</a>
        <?php foreach ($types as $key => $label): ?>
            <a class="tab<?= $type === $key ? ' active' : '' ?>" href="<?= e($filterUrl(['type' => $key])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="toolbar" method="get">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="field">
            <label for="read">Show</label>
            <select id="read" name="read">
                <option value="">Read and unread</option>
                <option value="unread" <?= $read === 'unread' ? 'selected' : '' ?>>Unread only</option>
                <option value="read" <?= $read === 'read' ? 'selected' : '' ?>>Read only</option>
            </select>
        </div>
        <button class="btn" type="submit">Apply</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Notice</th><th>Borrower</th><th>Book</th><th>Due date</th><th>Type</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><?= empty_row(6, 'No notifications. All borrowed books are on schedule.') ?><?php endif; ?>
            <?php foreach ($rows as $n):
                $late = overdue_days($n['due_date']); ?>
                <tr class="<?= $n['is_read'] ? '' : 'is-unread' ?>">
                    <td>
                        <span class="cell-title"><?= e($n['message']) ?></span>
                        <span class="cell-sub"><?= $n['is_read'] ? 'Read ' . e(fmt_datetime($n['read_at'])) : 'Unread' ?> · generated <?= e(fmt_datetime($n['created_at'])) ?></span>
                    </td>
                    <td>
                        <a href="<?= e(url('users/view.php', ['id' => $n['user_id']])) ?>"><?= e($n['first_name'] . ' ' . $n['last_name']) ?></a>
                        <span class="cell-sub"><?= e($n['id_number']) ?><?= $n['phone'] ? ' · ' . e($n['phone']) : '' ?></span>
                    </td>
                    <td><a href="<?= e(url('borrowing/view.php', ['id' => $n['borrowing_id']])) ?>"><?= e($n['title']) ?></a>
                        <span class="cell-sub"><?= e($n['transaction_no']) ?></span></td>
                    <td class="nowrap"><?= fmt_date($n['due_date']) ?>
                        <?php if ($late > 0): ?><span class="cell-sub text-danger"><?= $late ?> day(s) late · <?= money(compute_penalty($late)) ?></span><?php endif; ?></td>
                    <td><?= badge($n['type']) ?></td>
                    <td class="actions">
                        <form method="post" action="<?= e(url('notifications/index.php', array_filter(['type' => $type, 'read' => $read]))) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <?php if ($n['is_read']): ?>
                                <input type="hidden" name="action" value="unread">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('mail', 15) ?> Mark unread</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="read">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check', 15) ?> Mark read</button>
                            <?php endif; ?>
                        </form>
                        <a class="btn btn-sm" href="<?= e(url('returning/process.php', ['id' => $n['borrowing_id']])) ?>">Return</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
