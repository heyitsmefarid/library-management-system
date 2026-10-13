<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$today    = today();
$soonDays = max(1, (int) setting('due_soon_days', 3));
$soonEnd  = date('Y-m-d', strtotime("+{$soonDays} days"));

$stats = db_one(
    "SELECT
        (SELECT COUNT(*) FROM users)                                                       AS total_users,
        (SELECT COUNT(*) FROM users WHERE status = 'Active')                               AS active_users,
        (SELECT COUNT(*) FROM books)                                                       AS total_titles,
        (SELECT COALESCE(SUM(total_copies), 0) FROM books)                                 AS total_copies,
        (SELECT COALESCE(SUM(available_copies), 0) FROM books)                             AS available_copies,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'Borrowed')                        AS borrowed,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = :t1)                      AS attendance_today,
        (SELECT COUNT(*) FROM attendance WHERE time_out IS NULL)                           AS inside_now,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'Borrowed' AND due_date < :t2)     AS overdue,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'Borrowed' AND due_date = :t3)     AS due_today,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'Borrowed' AND due_date > :t4 AND due_date <= :soon) AS due_soon,
        (SELECT COUNT(*) FROM penalties WHERE status = 'Unpaid')                           AS unpaid_count,
        (SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE status = 'Unpaid')           AS unpaid_total",
    ['t1' => $today, 't2' => $today, 't3' => $today, 't4' => $today, 'soon' => $soonEnd]
);

$attention = db_all(
    "SELECT br.id, br.transaction_no, br.due_date, bk.title, u.first_name, u.last_name, u.id_number
       FROM borrowings br
       JOIN books bk ON bk.id = br.book_id
       JOIN users u  ON u.id  = br.user_id
      WHERE br.status = 'Borrowed' AND br.due_date <= ?
      ORDER BY br.due_date ASC
      LIMIT 8",
    [$soonEnd]
);

$inside = db_all(
    "SELECT a.time_in, u.id, u.first_name, u.last_name, u.id_number, u.user_type
       FROM attendance a JOIN users u ON u.id = a.user_id
      WHERE a.time_out IS NULL
      ORDER BY a.time_in DESC
      LIMIT 8"
);

$recent = db_all(
    "SELECT br.id, br.transaction_no, br.borrow_date, br.due_date, br.status, bk.title, u.first_name, u.last_name
       FROM borrowings br
       JOIN books bk ON bk.id = br.book_id
       JOIN users u  ON u.id  = br.user_id
      ORDER BY br.id DESC
      LIMIT 6"
);

/* ---------- Greeting and the one-line situation summary ---------- */

$admin     = current_admin();
$firstName = explode(' ', trim($admin['full_name']))[0];
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$plural  = fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$summary = [];
if ($stats['inside_now'] > 0) {
    $summary[] = $plural((int) $stats['inside_now'], 'person is', 'people are') . ' in the library';
}
if ($stats['overdue'] > 0) {
    $summary[] = $plural((int) $stats['overdue'], 'book is', 'books are') . ' overdue';
}
if ($stats['due_today'] > 0) {
    $summary[] = $plural((int) $stats['due_today'], 'book is', 'books are') . ' due back today';
}
if (count($summary) > 1) {
    $last    = array_pop($summary);
    $summary = implode(', ', $summary) . ' and ' . $last . '.';
} elseif ($summary) {
    $summary = $summary[0] . '.';
} else {
    $summary = 'The desk is quiet: nobody is timed in and nothing is overdue.';
}
$summary = ucfirst($summary);

/* ---------- Chart data (all from the database) ---------- */

$range = (int) get('range', '14');
if (!in_array($range, [7, 14, 30], true)) {
    $range = 14;
}
$rangeStart = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));

// One zero-filled bucket per day so days without activity still appear on the axis.
$days = [];
for ($d = strtotime($rangeStart); $d <= strtotime($today); $d = strtotime('+1 day', $d)) {
    $days[date('Y-m-d', $d)] = 0;
}
$fillDays = function (array $rows) use ($days): array {
    foreach ($rows as $r) {
        if (isset($days[$r['d']])) {
            $days[$r['d']] = (int) $r['c'];
        }
    }
    return array_values($days);
};

// Visits by hour of time-in; always covers 7 AM–6 PM, widened if visits fall outside.
$hourRows = db_all(
    'SELECT HOUR(time_in) AS h, COUNT(*) AS c FROM attendance WHERE attendance_date >= ? GROUP BY HOUR(time_in)',
    [$rangeStart]
);
$hourCounts = array_column($hourRows, 'c', 'h');
$firstHour  = min(7, $hourCounts ? min(array_keys($hourCounts)) : 7);
$lastHour   = max(18, $hourCounts ? max(array_keys($hourCounts)) : 18);
$hours      = [];
for ($h = $firstHour; $h <= $lastHour; $h++) {
    $hours[date('g A', mktime($h, 0, 0))] = (int) ($hourCounts[$h] ?? 0);
}

// Penalties assessed vs collected for the last six calendar months.
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -{$i} month"))] = ['assessed' => 0.0, 'collected' => 0.0];
}
$sixMonthsAgo = array_key_first($months) . '-01';
foreach (db_all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(amount) AS s FROM penalties WHERE created_at >= ? GROUP BY m", [$sixMonthsAgo]) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']]['assessed'] = (float) $r['s'];
    }
}
foreach (db_all("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS m, SUM(amount) AS s FROM penalties WHERE status = 'Paid' AND paid_at >= ? GROUP BY m", [$sixMonthsAgo]) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']]['collected'] = (float) $r['s'];
    }
}

$chartData = [
    'labels'     => array_map(fn($d) => date('M j', strtotime($d)), array_keys($days)),
    'attendance' => $fillDays(db_all(
        'SELECT attendance_date AS d, COUNT(*) AS c FROM attendance WHERE attendance_date >= ? GROUP BY attendance_date',
        [$rangeStart]
    )),
    'borrowed'   => $fillDays(db_all(
        'SELECT borrow_date AS d, COUNT(*) AS c FROM borrowings WHERE borrow_date >= ? GROUP BY borrow_date',
        [$rangeStart]
    )),
    'returned'   => $fillDays(db_all(
        'SELECT DATE(return_date) AS d, COUNT(*) AS c FROM returns WHERE return_date >= ? GROUP BY DATE(return_date)',
        [$rangeStart]
    )),
    'hourLabels' => array_keys($hours),
    'hours'      => array_values($hours),
    'monthLabels'=> array_map(fn($m) => date('M Y', strtotime($m . '-01')), array_keys($months)),
    'assessed'   => array_column(array_values($months), 'assessed'),
    'collected'  => array_column(array_values($months), 'collected'),
    'categories' => db_all(
        "SELECT COALESCE(c.name, 'Uncategorized') AS name,
                SUM(b.available_copies) AS available,
                SUM(b.total_copies - b.available_copies) AS on_loan
           FROM books b LEFT JOIN categories c ON c.id = b.category_id
          GROUP BY COALESCE(c.name, 'Uncategorized')
          ORDER BY SUM(b.total_copies) DESC, name"
    ),
    'topBooks'   => db_all(
        'SELECT bk.title, COUNT(*) AS times
           FROM borrowings br JOIN books bk ON bk.id = br.book_id
          GROUP BY bk.id, bk.title
          ORDER BY times DESC, bk.title
          LIMIT 5'
    ),
];

// Headline figures shown under each chart title
$visitTotal   = array_sum($chartData['attendance']);
$busiestDay   = $visitTotal ? array_search(max($chartData['attendance']), $chartData['attendance'], true) : null;
$hourTotal    = array_sum($chartData['hours']);
$busiestHour  = $hourTotal ? array_search(max($chartData['hours']), $chartData['hours'], true) : null;
$assessedSum  = array_sum($chartData['assessed']);
$collectedSum = array_sum($chartData['collected']);

$pageTitle   = 'Dashboard';
$activeNav   = 'dashboard';
$pageScripts = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    url('assets/js/dashboard-charts.js'),
];
require __DIR__ . '/includes/header.php';

/** Renders a chart card with a "View data" table so values never depend on the canvas alone. */
$chartCard = function (string $id, string $title, string $subtitle, string $aria, array $headers, array $rows,
                       int $height = 260, string $empty = '') {
    ?>
    <section class="card chart-card">
        <div class="card-head">
            <div><h2><?= e($title) ?></h2><p><?= e($subtitle) ?></p></div>
        </div>
        <div class="card-body">
            <?php if ($empty !== ''): ?>
                <div class="empty-state"><?= icon('book-open', 28) ?><p><?= e($empty) ?></p></div>
            <?php else: ?>
                <div class="chart-box" style="height:<?= $height ?>px"><canvas id="<?= e($id) ?>" role="img" aria-label="<?= e($aria) ?>"></canvas></div>
            <?php endif; ?>
            <?php if ($rows): ?>
                <details class="chart-table">
                    <summary>View data</summary>
                    <div class="table-wrap"><table>
                        <thead><tr><?php foreach ($headers as $i => $h): ?><th class="<?= $i ? 'num' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr><?php foreach ($row as $i => $cell): ?><td class="<?= $i ? 'num' : '' ?>"><?= e($cell) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </details>
            <?php endif; ?>
        </div>
    </section>
    <?php
};
?>

<section class="hero">
    <div class="hero-text">
        <h1><?= e($greeting) ?>, <?= e($firstName) ?>.</h1>
        <p class="hero-summary"><?= e($summary) ?></p>
        <div class="hero-actions">
            <a class="btn" href="<?= e(url('attendance/index.php')) ?>"><?= icon('clock') ?> Record attendance</a>
            <a class="btn btn-secondary" href="<?= e(url('borrowing/create.php')) ?>"><?= icon('borrow') ?> Lend a book</a>
            <a class="btn btn-secondary" href="<?= e(url('returning/index.php')) ?>"><?= icon('return') ?> Return a book</a>
        </div>
    </div>
    <div class="stamp" aria-label="Today is <?= e(date('l, F j, Y')) ?>">
        <span class="stamp-head">Today</span>
        <span class="stamp-date"><?= e(strtoupper(date('d M Y'))) ?></span>
        <span class="stamp-foot"><?= e(date('l')) ?>, <time data-clock><?= e(date('g:i A')) ?></time></span>
    </div>
</section>

<section class="ledger" aria-label="Library totals">
    <a class="ledger-cell" href="<?= e(url('users/index.php')) ?>">
        <span class="ledger-value"><?= number_format($stats['total_users']) ?></span>
        <span class="ledger-label">Registered users</span>
        <span class="ledger-note"><?= number_format($stats['active_users']) ?> active</span>
    </a>
    <a class="ledger-cell" href="<?= e(url('books/index.php')) ?>">
        <span class="ledger-value"><?= number_format($stats['total_titles']) ?></span>
        <span class="ledger-label">Book titles</span>
        <span class="ledger-note"><?= number_format($stats['total_copies']) ?> copies in all</span>
    </a>
    <a class="ledger-cell" href="<?= e(url('books/index.php', ['availability' => 'available'])) ?>">
        <span class="ledger-value"><?= number_format($stats['available_copies']) ?></span>
        <span class="ledger-label">Copies on the shelf</span>
        <span class="ledger-note">ready to lend</span>
    </a>
    <a class="ledger-cell" href="<?= e(url('borrowing/index.php')) ?>">
        <span class="ledger-value"><?= number_format($stats['borrowed']) ?></span>
        <span class="ledger-label">Books on loan</span>
        <span class="ledger-note"><?= number_format($stats['overdue']) ?> overdue</span>
    </a>
    <a class="ledger-cell" href="<?= e(url('attendance/index.php')) ?>">
        <span class="ledger-value"><?= number_format($stats['attendance_today']) ?></span>
        <span class="ledger-label">Visits today</span>
        <span class="ledger-note"><?= number_format($stats['inside_now']) ?> inside now</span>
    </a>
</section>

<div class="grid-3-1 dash-top">
    <section class="card">
        <div class="card-head">
            <div><h2>Due back soon</h2><p>Loans due within <?= $soonDays ?> day<?= $soonDays === 1 ? '' : 's' ?>, and overdue loans</p></div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('notifications/index.php')) ?>">All notifications</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Book and borrower</th><th>Due</th><th>Status</th><th class="actions"></th></tr></thead>
                <tbody>
                <?php if (!$attention): ?>
                    <?= empty_row(4, 'Nothing is due in the next few days.') ?>
                <?php endif; ?>
                <?php foreach ($attention as $row):
                    $state = $row['due_date'] < $today ? 'overdue' : ($row['due_date'] === $today ? 'due_today' : 'due_soon'); ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('borrowing/view.php', ['id' => $row['id']])) ?>"><?= e($row['title']) ?></a>
                            <span class="cell-sub"><?= e($row['first_name'] . ' ' . $row['last_name']) ?>, <?= e($row['id_number']) ?></span>
                        </td>
                        <td class="nowrap"><?= fmt_date($row['due_date']) ?></td>
                        <td><?= badge($state) ?></td>
                        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(url('returning/process.php', ['id' => $row['id']])) ?>">Return</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Needs attention</h2><p>Each item links to where you fix it</p></div></div>
        <ul class="attention">
            <?php
            $items = [
                ['critical', 'alert',    (int) $stats['overdue'],   'Overdue books',      'Process returns',    url('borrowing/index.php', ['status' => 'overdue'])],
                ['warning',  'calendar', (int) $stats['due_today'], 'Due back today',     'View loans',         url('notifications/index.php', ['type' => 'due_today'])],
                ['info',     'clock',    (int) $stats['due_soon'],  "Due within {$soonDays} days", 'View loans', url('notifications/index.php', ['type' => 'due_soon'])],
                ['serious',  'card',     (int) $stats['unpaid_count'], 'Unpaid penalties', 'Collect payments', url('penalties/index.php', ['status' => 'Unpaid'])],
            ];
            foreach ($items as [$level, $ico, $count, $label, $action, $href]):
                $clear = $count === 0; ?>
                <li class="attention-item <?= $clear ? 'is-clear' : 'is-' . $level ?>">
                    <span class="attention-icon"><?= icon($clear ? 'check' : $ico, 18) ?></span>
                    <span class="grow">
                        <span class="attention-label"><?= e($label) ?></span>
                        <span class="attention-count">
                            <?php if ($clear): ?>None
                            <?php elseif ($label === 'Unpaid penalties'): ?><?= money($stats['unpaid_total']) ?> from <?= $plural($count, 'record', 'records') ?>
                            <?php else: ?><?= $plural($count, 'book', 'books') ?><?php endif; ?>
                        </span>
                    </span>
                    <?php if (!$clear): ?><a class="btn btn-sm btn-secondary" href="<?= e($href) ?>"><?= e($action) ?></a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<div class="section-head">
    <div>
        <h2>Library activity</h2>
        <p>Daily charts cover the period you pick; inventory and top titles are always current.</p>
    </div>
    <nav class="segmented" aria-label="Activity period">
        <?php foreach ([7, 14, 30] as $r): ?>
            <a class="<?= $range === $r ? 'active' : '' ?>" href="<?= e(url('dashboard.php', ['range' => $r])) ?>"
               <?= $range === $r ? 'aria-current="true"' : '' ?>>Last <?= $r ?> days</a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="grid-2 charts">
    <?php
    $chartCard(
        'chart-attendance', 'Daily attendance',
        $visitTotal ? $plural($visitTotal, 'visit', 'visits') . ", busiest on {$chartData['labels'][$busiestDay]}" : 'No visits recorded in this period yet',
        "Column chart of library visits per day for the last {$range} days",
        ['Date', 'Visits'],
        array_map(null, $chartData['labels'], $chartData['attendance'])
    );
    $chartCard(
        'chart-activity', 'Borrowing activity',
        $plural(array_sum($chartData['borrowed']), 'book', 'books') . ' borrowed and ' . array_sum($chartData['returned']) . ' returned',
        "Line chart of books borrowed and returned per day for the last {$range} days",
        ['Date', 'Borrowed', 'Returned'],
        array_map(null, $chartData['labels'], $chartData['borrowed'], $chartData['returned'])
    );
    $chartCard(
        'chart-hours', 'Busiest hours',
        $hourTotal ? "Most visitors arrive around {$chartData['hourLabels'][$busiestHour]}" : 'Shows when visitors time in, once visits are recorded',
        "Column chart of library visits by hour of arrival for the last {$range} days",
        ['Hour', 'Visits'],
        array_map(null, $chartData['hourLabels'], $chartData['hours'])
    );
    $chartCard(
        'chart-penalties', 'Penalties by month',
        money($assessedSum) . ' assessed and ' . money($collectedSum) . ' collected in 6 months',
        'Grouped column chart of penalties assessed and collected per month for the last six months',
        ['Month', 'Assessed', 'Collected'],
        array_map(fn($l, $a, $c) => [$l, money($a), money($c)], $chartData['monthLabels'], $chartData['assessed'], $chartData['collected'])
    );
    $chartCard(
        'chart-categories', 'Inventory by category',
        'Copies on the shelf and on loan right now',
        'Stacked bar chart of available and borrowed copies per book category',
        ['Category', 'On the shelf', 'On loan'],
        array_map(fn($c) => [$c['name'], (int) $c['available'], (int) $c['on_loan']], $chartData['categories']),
        max(180, 44 * count($chartData['categories']) + 60),
        $chartData['categories'] ? '' : 'Add books to see how the collection is spread across categories.'
    );
    $chartCard(
        'chart-top-books', 'Most borrowed books',
        'Top 5 titles by number of loans, all time',
        'Bar chart of the most borrowed book titles',
        ['Title', 'Loans'],
        array_map(fn($b) => [$b['title'], (int) $b['times']], $chartData['topBooks']),
        max(180, 44 * count($chartData['topBooks']) + 60),
        $chartData['topBooks'] ? '' : 'Lend a book and the most popular titles will show up here.'
    );
    ?>
</div>

<script type="application/json" id="dashboard-chart-data"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <div><h2>In the library now</h2><p>Timed in and not yet timed out</p></div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('attendance/index.php')) ?>">Attendance</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th>Type</th><th>Time in</th></tr></thead>
                <tbody>
                <?php if (!$inside): ?>
                    <?= empty_row(3, 'Nobody is timed in. Use Record attendance when someone arrives.') ?>
                <?php endif; ?>
                <?php foreach ($inside as $row): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $row['id']])) ?>"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></a>
                            <span class="cell-sub"><?= e($row['id_number']) ?></span>
                        </td>
                        <td><?= e($row['user_type']) ?></td>
                        <td class="nowrap"><?= fmt_time($row['time_in']) ?> <span class="cell-sub">for <?= fmt_duration($row['time_in']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div><h2>Latest loans</h2><p>The six most recent borrowing transactions</p></div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('borrowing/index.php', ['status' => 'all'])) ?>">All transactions</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Book and borrower</th><th>Due</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?>
                    <?= empty_row(3, 'No loans yet. Use Lend a book to record the first one.') ?>
                <?php endif; ?>
                <?php foreach ($recent as $row):
                    $status = $row['status'] === 'Borrowed' && $row['due_date'] < $today ? 'Overdue' : $row['status']; ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('borrowing/view.php', ['id' => $row['id']])) ?>"><?= e($row['title']) ?></a>
                            <span class="cell-sub"><?= e($row['first_name'] . ' ' . $row['last_name']) ?>, <?= e($row['transaction_no']) ?></span>
                        </td>
                        <td class="nowrap"><?= fmt_date($row['due_date']) ?></td>
                        <td><?= badge($status) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
