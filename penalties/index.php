<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$status = get('status');
$q      = get('q');
$from   = get('from');
$to     = get('to');

$where  = [];
$params = [];
if (in_array($status, ['Unpaid', 'Paid'], true)) {
    $where[]  = 'pn.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = "(u.id_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                 OR br.transaction_no LIKE ? OR pn.receipt_no LIKE ? OR bk.title LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if (valid_date($from)) {
    $where[]  = 'DATE(pn.created_at) >= ?';
    $params[] = $from;
}
if (valid_date($to)) {
    $where[]  = 'DATE(pn.created_at) <= ?';
    $params[] = $to;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base     = 'FROM penalties pn
             JOIN borrowings br ON br.id = pn.borrowing_id
             JOIN books bk ON bk.id = br.book_id
             JOIN users u ON u.id = pn.user_id
             LEFT JOIN returns r ON r.id = pn.return_id';

$filtered = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(pn.amount), 0) AS amount $base $whereSql", $params);
$p        = paginate((int) $filtered['c'], 15);
$rows     = db_all(
    "SELECT pn.*, br.transaction_no, br.due_date, bk.title, u.id_number, u.first_name, u.last_name, r.return_date
     $base $whereSql
     ORDER BY pn.status = 'Paid', pn.created_at DESC
     LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$summary = db_one(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN amount END), 0) AS unpaid_amount,
        SUM(status = 'Unpaid')                                         AS unpaid_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' THEN amount END), 0)   AS paid_amount,
        SUM(status = 'Paid')                                           AS paid_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' AND DATE(paid_at) = ? THEN amount END), 0) AS paid_today,
        COALESCE(SUM(CASE WHEN status = 'Paid' AND paid_at >= ? THEN amount END), 0)      AS paid_month,
        COALESCE(SUM(overdue_days), 0)                                 AS total_days
       FROM penalties",
    [today(), date('Y-m-01')]
);

$debtors = db_all(
    "SELECT u.id, u.id_number, u.first_name, u.last_name, COUNT(*) AS items, SUM(pn.amount) AS balance
       FROM penalties pn JOIN users u ON u.id = pn.user_id
      WHERE pn.status = 'Unpaid'
      GROUP BY u.id, u.id_number, u.first_name, u.last_name
      ORDER BY balance DESC
      LIMIT 8"
);

$pageTitle = 'Penalties';
$activeNav = 'penalties';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Penalty management</h1>
        <p>Late-return penalties are computed automatically at <?= money(penalty_rate()) ?> per overdue day.
            <a href="<?= e(url('settings/index.php')) ?>">Change rate</a></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('reports/index.php', ['type' => 'outstanding'])) ?>"><?= icon('printer') ?> Outstanding report</a>
        <a class="btn btn-secondary" href="<?= e(url('reports/index.php', ['type' => 'payments'])) ?>"><?= icon('printer') ?> Payments report</a>
    </div>
</div>

<section class="stats">
    <a class="stat" href="<?= e(url('penalties/index.php', ['status' => 'Unpaid'])) ?>">
        <span class="stat-icon danger"><?= icon('alert', 22) ?></span>
        <span><span class="stat-value"><?= money($summary['unpaid_amount']) ?></span><span class="stat-label">Unpaid · <?= (int) $summary['unpaid_count'] ?> record(s)</span></span>
    </a>
    <a class="stat" href="<?= e(url('penalties/index.php', ['status' => 'Paid'])) ?>">
        <span class="stat-icon"><?= icon('check', 22) ?></span>
        <span><span class="stat-value"><?= money($summary['paid_amount']) ?></span><span class="stat-label">Collected · <?= (int) $summary['paid_count'] ?> record(s)</span></span>
    </a>
    <div class="stat">
        <span class="stat-icon accent"><?= icon('card', 22) ?></span>
        <span><span class="stat-value"><?= money($summary['paid_today']) ?></span><span class="stat-label">Collected today</span></span>
    </div>
    <div class="stat">
        <span class="stat-icon info"><?= icon('calendar', 22) ?></span>
        <span><span class="stat-value"><?= money($summary['paid_month']) ?></span><span class="stat-label">Collected this month</span></span>
    </div>
</section>

<div class="grid-3-1">
    <section class="card">
        <nav class="tabs">
            <?php foreach (['' => 'All', 'Unpaid' => 'Unpaid', 'Paid' => 'Paid'] as $key => $label): ?>
                <a class="tab<?= $status === $key ? ' active' : '' ?>" href="<?= e(url('penalties/index.php', array_filter(['status' => $key, 'q' => $q, 'from' => $from, 'to' => $to]))) ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="toolbar" method="get">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <div class="field grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Borrower, ID, transaction, receipt or title">
            </div>
            <div class="field">
                <label for="from">Assessed from</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label for="to">to</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
            <button class="btn" type="submit"><?= icon('search', 16) ?> Filter</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Borrower</th><th>Book / Transaction</th><th>Returned</th><th class="num">Days</th><th class="num">Amount</th><th>Status</th><th class="actions"></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><?= empty_row(7, 'No penalties found.') ?><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $r['user_id'], 'tab' => 'penalties'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
                            <span class="cell-sub"><?= e($r['id_number']) ?></span>
                        </td>
                        <td><?= e($r['title']) ?>
                            <span class="cell-sub"><a href="<?= e(url('borrowing/view.php', ['id' => $r['borrowing_id']])) ?>"><?= e($r['transaction_no']) ?></a></span></td>
                        <td class="nowrap"><?= fmt_date($r['return_date']) ?><span class="cell-sub">Due <?= fmt_date($r['due_date']) ?></span></td>
                        <td class="num"><?= (int) $r['overdue_days'] ?><span class="cell-sub">× <?= money($r['rate_per_day']) ?></span></td>
                        <td class="num"><strong><?= money($r['amount']) ?></strong></td>
                        <td><?= badge($r['status']) ?>
                            <?php if ($r['status'] === 'Paid'): ?><span class="cell-sub">Receipt <?= e($r['receipt_no']) ?> · <?= fmt_date($r['paid_at']) ?></span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($r['status'] === 'Unpaid'): ?>
                                <a class="btn btn-sm" href="<?= e(url('penalties/pay.php', ['id' => $r['id']])) ?>"><?= icon('card', 15) ?> Record payment</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot><tr><td colspan="4">Total for current filter (<?= (int) $filtered['c'] ?> record(s))</td><td class="num"><?= money($filtered['amount']) ?></td><td colspan="2"></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?= pagination_links($p) ?>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Outstanding balances</h2><p>Borrowers with the highest unpaid penalties</p></div></div>
        <ul class="list">
            <?php if (!$debtors): ?><li class="muted">No outstanding penalties.</li><?php endif; ?>
            <?php foreach ($debtors as $d): ?>
                <li>
                    <div class="grow">
                        <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $d['id'], 'tab' => 'penalties'])) ?>"><?= e($d['first_name'] . ' ' . $d['last_name']) ?></a>
                        <span class="cell-sub"><?= e($d['id_number']) ?> · <?= (int) $d['items'] ?> item(s)</span>
                    </div>
                    <strong class="text-danger nowrap"><?= money($d['balance']) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
