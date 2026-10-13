<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$q     = get('q');
$today = today();

$where  = ["br.status = 'Borrowed'"];
$params = [];
if ($q !== '') {
    $where[] = "(br.transaction_no LIKE ? OR bk.title LIKE ? OR bk.isbn LIKE ? OR u.id_number LIKE ?
                 OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$base     = 'FROM borrowings br JOIN books bk ON bk.id = br.book_id JOIN users u ON u.id = br.user_id';

$total = (int) db_value("SELECT COUNT(*) $base $whereSql", $params);
$p     = paginate($total, 15);
$loans = db_all(
    "SELECT br.id, br.transaction_no, br.borrow_date, br.due_date, br.user_id, bk.title, bk.isbn,
            u.id_number, u.first_name, u.last_name
     $base $whereSql
     ORDER BY br.due_date ASC, br.id ASC
     LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$recent = db_all(
    "SELECT r.return_no, r.return_date, r.overdue_days, r.penalty_amount, r.book_condition,
            br.id AS borrowing_id, br.transaction_no, bk.title, u.first_name, u.last_name
       FROM returns r
       JOIN borrowings br ON br.id = r.borrowing_id
       JOIN books bk ON bk.id = br.book_id
       JOIN users u ON u.id = br.user_id
      ORDER BY r.return_date DESC, r.id DESC
      LIMIT 10"
);

$rate = penalty_rate();

$pageTitle = 'Returning';
$activeNav = 'returning';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Returning</h1>
        <p>Find a borrowed book and process its return. Penalty rate: <?= money($rate) ?> per overdue day.</p>
    </div>
</div>

<section class="card">
    <div class="card-head"><div><h2>Borrowed books</h2><p><?= $total ?> book(s) awaiting return</p></div></div>
    <form class="toolbar" method="get">
        <div class="field grow">
            <label for="q">Search borrowed books</label>
            <div class="search-input">
                <?= icon('search', 16) ?>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Transaction no., book title, ISBN, borrower name or ID" autofocus>
            </div>
        </div>
        <button class="btn" type="submit"><?= icon('search', 16) ?> Search</button>
        <?php if ($q !== ''): ?><a class="btn btn-secondary" href="<?= e(url('returning/index.php')) ?>">Clear</a><?php endif; ?>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Transaction</th><th>Book</th><th>Borrower</th><th>Borrowed</th><th>Due</th><th class="num">Days late</th><th class="num">Penalty if returned today</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php if (!$loans): ?><?= empty_row(8, $q !== '' ? 'No borrowed books match your search.' : 'No books are currently borrowed.') ?><?php endif; ?>
            <?php foreach ($loans as $l):
                $late = overdue_days($l['due_date']); ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('borrowing/view.php', ['id' => $l['id']])) ?>"><?= e($l['transaction_no']) ?></a></td>
                    <td><span class="cell-title"><?= e($l['title']) ?></span><span class="cell-sub"><?= e($l['isbn'] ?: '') ?></span></td>
                    <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?><span class="cell-sub"><?= e($l['id_number']) ?></span></td>
                    <td class="nowrap"><?= fmt_date($l['borrow_date']) ?></td>
                    <td class="nowrap"><?= fmt_date($l['due_date']) ?> <?= $l['due_date'] === $today ? badge('due_today') : '' ?></td>
                    <td class="num <?= $late > 0 ? 'text-danger' : '' ?>"><?= $late ?></td>
                    <td class="num <?= $late > 0 ? 'text-danger' : '' ?>"><?= money(compute_penalty($late, $rate)) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="<?= e(url('returning/process.php', ['id' => $l['id']])) ?>"><?= icon('return', 15) ?> Process return</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
</section>

<section class="card">
    <div class="card-head">
        <div><h2>Recently returned</h2></div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('borrowing/index.php', ['status' => 'returned'])) ?>">All completed</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Return no.</th><th>Book</th><th>Borrower</th><th>Returned</th><th>Condition</th><th class="num">Days late</th><th class="num">Penalty</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?><?= empty_row(7, 'No returns recorded yet.') ?><?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('borrowing/view.php', ['id' => $r['borrowing_id']])) ?>"><?= e($r['return_no']) ?></a></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td class="nowrap"><?= fmt_datetime($r['return_date']) ?></td>
                    <td><?= badge($r['book_condition']) ?></td>
                    <td class="num"><?= (int) $r['overdue_days'] ?></td>
                    <td class="num"><?= money($r['penalty_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
