<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$reports = [
    'attendance'  => ['Library Attendance Report',       true],
    'inventory'   => ['Book Inventory Report',           false],
    'borrowed'    => ['Borrowed Books Report',           true],
    'returned'    => ['Returned Books Report',           true],
    'overdue'     => ['Overdue Books Report',            false],
    'history'     => ['Borrowing History Report',        true],
    'payments'    => ['Penalty Payments Report',         true],
    'outstanding' => ['Outstanding Penalties Report',    false],
];

$type = get('type', 'attendance');
if (!isset($reports[$type])) {
    $type = 'attendance';
}
[$title, $usesDates] = $reports[$type];

$from = get('from', date('Y-m-01'));
$to   = get('to', today());
if (!valid_date($from)) {
    $from = date('Y-m-01');
}
if (!valid_date($to)) {
    $to = today();
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$today = today();
$rate  = penalty_rate();

/*
 * Each report defines:
 *   $columns : [header, row key or callable, css class]
 *   $rows    : result set
 *   $summary : [label => value] shown above the table
 */
$columns = [];
$rows    = [];
$summary = [];
$name    = fn(array $r): string => $r['last_name'] . ', ' . $r['first_name'];

switch ($type) {
    case 'attendance':
        $rows = db_all(
            "SELECT a.*, u.id_number, u.first_name, u.last_name, u.user_type, u.department
               FROM attendance a JOIN users u ON u.id = a.user_id
              WHERE a.attendance_date BETWEEN ? AND ?
              ORDER BY a.time_in ASC",
            [$from, $to]
        );
        $columns = [
            ['Date', fn($r) => fmt_date($r['attendance_date']), 'nowrap'],
            ['ID number', 'id_number', 'nowrap'],
            ['Name', $name, ''],
            ['Type', 'user_type', ''],
            ['Department', fn($r) => $r['department'] ?: '—', ''],
            ['Purpose', fn($r) => $r['purpose'] ?: '—', ''],
            ['Time in', fn($r) => fmt_time($r['time_in']), 'nowrap'],
            ['Time out', fn($r) => $r['time_out'] ? fmt_time($r['time_out']) : 'Still inside', 'nowrap'],
            ['Duration', fn($r) => fmt_duration($r['time_in'], $r['time_out']), 'nowrap'],
        ];
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['user_type']] = ($byType[$r['user_type']] ?? 0) + 1;
        }
        $summary = ['Total visits' => count($rows), 'Unique visitors' => count(array_unique(array_column($rows, 'user_id')))];
        foreach ($byType as $t => $c) {
            $summary[$t . ' visits'] = $c;
        }
        break;

    case 'inventory':
        $rows = db_all(
            "SELECT b.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM borrowings br WHERE br.book_id = b.id) AS times_borrowed
               FROM books b LEFT JOIN categories c ON c.id = b.category_id
              ORDER BY c.name IS NULL, c.name, b.title"
        );
        $columns = [
            ['Title', 'title', 'cell-title'],
            ['Author', 'author', ''],
            ['ISBN', fn($r) => $r['isbn'] ?: '—', 'nowrap'],
            ['Category', fn($r) => $r['category_name'] ?? 'Uncategorized', ''],
            ['Publisher', fn($r) => $r['publisher'] ?: '—', ''],
            ['Year', fn($r) => $r['publication_year'] ?: '—', 'num'],
            ['Shelf', fn($r) => $r['shelf_location'] ?: '—', ''],
            ['Total', 'total_copies', 'num'],
            ['Available', 'available_copies', 'num'],
            ['On loan', fn($r) => $r['total_copies'] - $r['available_copies'], 'num'],
            ['Times borrowed', 'times_borrowed', 'num'],
        ];
        $summary = [
            'Titles'           => count($rows),
            'Total copies'     => array_sum(array_column($rows, 'total_copies')),
            'Available copies' => array_sum(array_column($rows, 'available_copies')),
            'Copies on loan'   => array_sum(array_column($rows, 'total_copies')) - array_sum(array_column($rows, 'available_copies')),
        ];
        break;

    case 'borrowed':
    case 'history':
        $sql = "SELECT br.*, bk.title, bk.isbn, u.id_number, u.first_name, u.last_name,
                       r.return_date, r.overdue_days, r.penalty_amount
                  FROM borrowings br
                  JOIN books bk ON bk.id = br.book_id
                  JOIN users u ON u.id = br.user_id
                  LEFT JOIN returns r ON r.borrowing_id = br.id
                 WHERE br.borrow_date BETWEEN ? AND ?" .
               ($type === 'borrowed' ? " AND br.status = 'Borrowed'" : '') .
               ' ORDER BY br.borrow_date ASC, br.id ASC';
        $rows = db_all($sql, [$from, $to]);
        $statusOf = fn($r) => $r['status'] === 'Borrowed' && $r['due_date'] < $today ? 'Overdue' : $r['status'];
        $columns = [
            ['Transaction', 'transaction_no', 'nowrap'],
            ['Borrowed', fn($r) => fmt_date($r['borrow_date']), 'nowrap'],
            ['ID number', 'id_number', 'nowrap'],
            ['Borrower', $name, ''],
            ['Book', 'title', ''],
            ['Due', fn($r) => fmt_date($r['due_date']), 'nowrap'],
        ];
        if ($type === 'history') {
            $columns[] = ['Returned', fn($r) => fmt_date($r['return_date']), 'nowrap'];
            $columns[] = ['Penalty', fn($r) => $r['penalty_amount'] !== null ? money($r['penalty_amount']) : '—', 'num'];
        }
        $columns[] = ['Status', fn($r) => ['html' => badge($statusOf($r))], ''];
        $statuses = array_map($statusOf, $rows);
        $summary  = ['Transactions' => count($rows)];
        if ($type === 'history') {
            $summary['Returned'] = count(array_keys($statuses, 'Returned', true));
        }
        $summary['Still borrowed'] = count(array_keys($statuses, 'Borrowed', true));
        $summary['Overdue']        = count(array_keys($statuses, 'Overdue', true));
        break;

    case 'returned':
        $rows = db_all(
            "SELECT r.*, br.transaction_no, br.borrow_date, br.due_date, bk.title, u.id_number, u.first_name, u.last_name
               FROM returns r
               JOIN borrowings br ON br.id = r.borrowing_id
               JOIN books bk ON bk.id = br.book_id
               JOIN users u ON u.id = br.user_id
              WHERE DATE(r.return_date) BETWEEN ? AND ?
              ORDER BY r.return_date ASC",
            [$from, $to]
        );
        $columns = [
            ['Return no.', 'return_no', 'nowrap'],
            ['Transaction', 'transaction_no', 'nowrap'],
            ['Borrower', $name, ''],
            ['Book', 'title', ''],
            ['Borrowed', fn($r) => fmt_date($r['borrow_date']), 'nowrap'],
            ['Due', fn($r) => fmt_date($r['due_date']), 'nowrap'],
            ['Returned', fn($r) => fmt_datetime($r['return_date']), 'nowrap'],
            ['Condition', 'book_condition', ''],
            ['Days late', 'overdue_days', 'num'],
            ['Penalty', fn($r) => money($r['penalty_amount']), 'num'],
        ];
        $late    = array_filter($rows, fn($r) => $r['overdue_days'] > 0);
        $summary = [
            'Books returned'  => count($rows),
            'Returned on time' => count($rows) - count($late),
            'Returned late'   => count($late),
            'Penalties assessed' => money(array_sum(array_column($rows, 'penalty_amount'))),
        ];
        break;

    case 'overdue':
        $rows = db_all(
            "SELECT br.*, bk.title, bk.isbn, u.id_number, u.first_name, u.last_name, u.phone, u.email
               FROM borrowings br
               JOIN books bk ON bk.id = br.book_id
               JOIN users u ON u.id = br.user_id
              WHERE br.status = 'Borrowed' AND br.due_date < ?
              ORDER BY br.due_date ASC",
            [$today]
        );
        $columns = [
            ['Transaction', 'transaction_no', 'nowrap'],
            ['ID number', 'id_number', 'nowrap'],
            ['Borrower', $name, ''],
            ['Contact', fn($r) => $r['phone'] ?: ($r['email'] ?: '—'), ''],
            ['Book', 'title', ''],
            ['Borrowed', fn($r) => fmt_date($r['borrow_date']), 'nowrap'],
            ['Due', fn($r) => fmt_date($r['due_date']), 'nowrap'],
            ['Days overdue', fn($r) => overdue_days($r['due_date']), 'num'],
            ['Accrued penalty', fn($r) => money(compute_penalty(overdue_days($r['due_date']), $rate)), 'num'],
        ];
        $accrued = 0;
        foreach ($rows as $r) {
            $accrued += compute_penalty(overdue_days($r['due_date']), $rate);
        }
        $summary = [
            'Overdue books'      => count($rows),
            'Borrowers involved' => count(array_unique(array_column($rows, 'user_id'))),
            'Accrued penalties (as of today)' => money($accrued),
        ];
        break;

    case 'payments':
        $rows = db_all(
            "SELECT pn.*, br.transaction_no, bk.title, u.id_number, u.first_name, u.last_name, a.full_name AS received_by_name
               FROM penalties pn
               JOIN borrowings br ON br.id = pn.borrowing_id
               JOIN books bk ON bk.id = br.book_id
               JOIN users u ON u.id = pn.user_id
               LEFT JOIN admins a ON a.id = pn.received_by
              WHERE pn.status = 'Paid' AND DATE(pn.paid_at) BETWEEN ? AND ?
              ORDER BY pn.paid_at ASC",
            [$from, $to]
        );
        $columns = [
            ['Date paid', fn($r) => fmt_datetime($r['paid_at']), 'nowrap'],
            ['Receipt no.', 'receipt_no', 'nowrap'],
            ['ID number', 'id_number', 'nowrap'],
            ['Borrower', $name, ''],
            ['Book', 'title', ''],
            ['Transaction', 'transaction_no', 'nowrap'],
            ['Days late', 'overdue_days', 'num'],
            ['Amount', fn($r) => money($r['amount']), 'num'],
            ['Received by', fn($r) => $r['received_by_name'] ?? '—', ''],
        ];
        $summary = ['Payments' => count($rows), 'Total collected' => money(array_sum(array_column($rows, 'amount')))];
        break;

    case 'outstanding':
        $rows = db_all(
            "SELECT pn.*, br.transaction_no, br.due_date, bk.title, u.id_number, u.first_name, u.last_name, u.phone, r.return_date
               FROM penalties pn
               JOIN borrowings br ON br.id = pn.borrowing_id
               JOIN books bk ON bk.id = br.book_id
               JOIN users u ON u.id = pn.user_id
               JOIN returns r ON r.id = pn.return_id
              WHERE pn.status = 'Unpaid'
              ORDER BY u.last_name, u.first_name, pn.created_at"
        );
        $columns = [
            ['ID number', 'id_number', 'nowrap'],
            ['Borrower', $name, ''],
            ['Contact', fn($r) => $r['phone'] ?: '—', ''],
            ['Book', 'title', ''],
            ['Transaction', 'transaction_no', 'nowrap'],
            ['Due', fn($r) => fmt_date($r['due_date']), 'nowrap'],
            ['Returned', fn($r) => fmt_date($r['return_date']), 'nowrap'],
            ['Days late', 'overdue_days', 'num'],
            ['Amount', fn($r) => money($r['amount']), 'num'],
        ];
        $summary = [
            'Unpaid records'    => count($rows),
            'Borrowers'         => count(array_unique(array_column($rows, 'user_id'))),
            'Total outstanding' => money(array_sum(array_column($rows, 'amount'))),
        ];
        break;
}

// Totals row for money reports
$totalAmount = null;
if (in_array($type, ['payments', 'outstanding'], true)) {
    $totalAmount = array_sum(array_column($rows, 'amount'));
} elseif ($type === 'returned') {
    $totalAmount = array_sum(array_column($rows, 'penalty_amount'));
}

$pageTitle = $title;
$activeNav = 'reports';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <h1>Reports</h1>
        <p>Choose a report, set the period and print it.</p>
    </div>
    <div class="page-actions">
        <button class="btn" type="button" data-print><?= icon('printer') ?> Print report</button>
    </div>
</div>

<section class="card no-print">
    <form class="toolbar" method="get" style="border-bottom:0">
        <div class="field grow">
            <label for="type">Report</label>
            <select id="type" name="type">
                <?php foreach ($reports as $key => [$label]): ?>
                    <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <button class="btn" type="submit"><?= icon('file', 16) ?> Generate</button>
    </form>
    <?php if (!$usesDates): ?>
        <p class="muted" style="padding:0 20px 14px;margin:0;font-size:.85rem">This report shows the current state as of today; the date filter does not apply.</p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="print-header">
        <h2><?= e(setting('library_name', 'School Library')) ?></h2>
        <p><strong><?= e($title) ?></strong></p>
        <p><?= $usesDates ? 'Period: ' . e(fmt_date($from)) . ' – ' . e(fmt_date($to)) : 'As of ' . e(fmt_date($today)) ?></p>
        <p class="muted">Generated <?= e(fmt_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(current_admin()['full_name']) ?></p>
    </div>

    <div class="card-body" style="border-bottom:1px solid var(--border)">
        <div class="summary-row">
            <?php foreach ($summary as $label => $value): ?>
                <div class="item"><div class="value"><?= e(is_int($value) ? number_format($value) : $value) ?></div><div class="label"><?= e($label) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="num">#</th>
                <?php foreach ($columns as [$label, , $class]): ?>
                    <th class="<?= strpos($class, 'num') !== false ? 'num' : '' ?>"><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><?= empty_row(count($columns) + 1, 'No records for this report and period.') ?><?php endif; ?>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td class="num muted"><?= $i + 1 ?></td>
                    <?php foreach ($columns as [, $value, $class]):
                        $v = is_callable($value) ? $value($r) : ($r[$value] ?? ''); ?>
                        <td class="<?= e($class) ?>"><?= is_array($v) ? $v['html'] : e($v) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows && $totalAmount !== null): ?>
                <tfoot>
                <tr>
                    <td colspan="<?= count($columns) - ($type === 'payments' ? 1 : 0) ?>">Total</td>
                    <td class="num"><?= money($totalAmount) ?></td>
                    <?php if ($type === 'payments'): ?><td></td><?php endif; ?>
                </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
