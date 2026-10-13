<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$status = get('status', 'active');
if (!in_array($status, ['active', 'overdue', 'returned', 'all'], true)) {
    $status = 'active';
}
$q     = get('q');
$from  = get('from');
$to    = get('to');
$today = today();

$where  = [];
$params = [];
switch ($status) {
    case 'active':
        $where[] = "br.status = 'Borrowed'";
        break;
    case 'overdue':
        $where[]  = "br.status = 'Borrowed' AND br.due_date < ?";
        $params[] = $today;
        break;
    case 'returned':
        $where[] = "br.status = 'Returned'";
        break;
}
if ($q !== '') {
    $where[] = "(br.transaction_no LIKE ? OR bk.title LIKE ? OR bk.isbn LIKE ? OR u.id_number LIKE ?
                 OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if (valid_date($from)) {
    $where[]  = 'br.borrow_date >= ?';
    $params[] = $from;
}
if (valid_date($to)) {
    $where[]  = 'br.borrow_date <= ?';
    $params[] = $to;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base  = 'FROM borrowings br JOIN books bk ON bk.id = br.book_id JOIN users u ON u.id = br.user_id LEFT JOIN returns r ON r.borrowing_id = br.id';
$total = (int) db_value("SELECT COUNT(*) $base $whereSql", $params);
$p     = paginate($total, 15);
$rows  = db_all(
    "SELECT br.*, bk.title, bk.author, u.id_number, u.first_name, u.last_name, r.return_date, r.penalty_amount
     $base $whereSql
     ORDER BY " . ($status === 'returned' ? 'r.return_date DESC' : ($status === 'all' ? 'br.id DESC' : 'br.due_date ASC, br.id ASC')) . "
     LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$counts = db_one(
    "SELECT SUM(status = 'Borrowed') AS active,
            SUM(status = 'Borrowed' AND due_date < ?) AS overdue,
            SUM(status = 'Returned') AS returned,
            COUNT(*) AS total
       FROM borrowings",
    [$today]
);

$pageTitle = 'Borrowing';
$activeNav = 'borrowing';
require __DIR__ . '/../includes/header.php';

$tabUrl = fn(string $s): string => url('borrowing/index.php', array_filter(['status' => $s, 'q' => $q, 'from' => $from, 'to' => $to]));
?>

<div class="page-head">
    <div>
        <h1>Borrowing transactions</h1>
        <p>Active and completed loans with their due dates and status.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('borrowing/create.php')) ?>"><?= icon('plus') ?> New borrowing</a>
    </div>
</div>

<section class="card">
    <nav class="tabs">
        <a class="tab<?= $status === 'active' ? ' active' : '' ?>" href="<?= e($tabUrl('active')) ?>">Active <?= badge((string) (int) $counts['active'], 'info') ?></a>
        <a class="tab<?= $status === 'overdue' ? ' active' : '' ?>" href="<?= e($tabUrl('overdue')) ?>">Overdue <?= badge((string) (int) $counts['overdue'], 'danger') ?></a>
        <a class="tab<?= $status === 'returned' ? ' active' : '' ?>" href="<?= e($tabUrl('returned')) ?>">Completed <?= badge((string) (int) $counts['returned'], 'success') ?></a>
        <a class="tab<?= $status === 'all' ? ' active' : '' ?>" href="<?= e($tabUrl('all')) ?>">All <?= badge((string) (int) $counts['total'], 'muted') ?></a>
    </nav>
    <form class="toolbar" method="get">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <div class="field grow">
            <label for="q">Search</label>
            <div class="search-input">
                <?= icon('search', 16) ?>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Transaction no., book title, ISBN, borrower name or ID">
            </div>
        </div>
        <div class="field">
            <label for="from">Borrowed from</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">Borrowed to</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <button class="btn" type="submit"><?= icon('search', 16) ?> Search</button>
        <?php if ($q !== '' || $from !== '' || $to !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(url('borrowing/index.php', ['status' => $status])) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Transaction</th><th>Book</th><th>Borrower</th><th>Borrowed</th><th>Due</th>
                <th><?= $status === 'returned' ? 'Returned' : 'Days left' ?></th><th>Status</th><th class="actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><?= empty_row(8, 'No transactions found.') ?><?php endif; ?>
            <?php foreach ($rows as $r):
                $isOverdue = $r['status'] === 'Borrowed' && $r['due_date'] < $today;
                $daysLeft  = (int) floor((strtotime($r['due_date']) - strtotime($today)) / 86400); ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('borrowing/view.php', ['id' => $r['id']])) ?>"><?= e($r['transaction_no']) ?></a></td>
                    <td><span class="cell-title"><?= e($r['title']) ?></span><span class="cell-sub"><?= e($r['author']) ?></span></td>
                    <td>
                        <a href="<?= e(url('users/view.php', ['id' => $r['user_id']])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
                        <span class="cell-sub"><?= e($r['id_number']) ?></span>
                    </td>
                    <td class="nowrap"><?= fmt_date($r['borrow_date']) ?></td>
                    <td class="nowrap"><?= fmt_date($r['due_date']) ?></td>
                    <td class="nowrap">
                        <?php if ($r['status'] === 'Returned'): ?>
                            <?= fmt_date($r['return_date']) ?>
                            <?php if ($r['penalty_amount'] > 0): ?><span class="cell-sub text-danger">Penalty <?= money($r['penalty_amount']) ?></span><?php endif; ?>
                        <?php elseif ($isOverdue): ?>
                            <span class="text-danger"><?= abs($daysLeft) ?> day(s) late</span>
                            <span class="cell-sub">Accrued <?= money(compute_penalty(abs($daysLeft))) ?></span>
                        <?php elseif ($daysLeft === 0): ?>
                            Due today
                        <?php else: ?>
                            <?= $daysLeft ?> day(s)
                        <?php endif; ?>
                    </td>
                    <td><?= badge($isOverdue ? 'Overdue' : $r['status']) ?></td>
                    <td class="actions">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('borrowing/view.php', ['id' => $r['id']])) ?>" title="View"><?= icon('eye', 15) ?></a>
                        <?php if ($r['status'] === 'Borrowed'): ?>
                            <a class="btn btn-sm" href="<?= e(url('returning/process.php', ['id' => $r['id']])) ?>"><?= icon('return', 15) ?> Return</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
