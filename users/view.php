<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id   = (int) get('id');
$user = db_one('SELECT * FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash('danger', 'User not found.');
    redirect('users/index.php');
}

$tab = get('tab', 'borrowings');
if (!in_array($tab, ['borrowings', 'attendance', 'penalties'], true)) {
    $tab = 'borrowings';
}
$today = today();

$summary = db_one(
    "SELECT
        (SELECT COUNT(*) FROM attendance WHERE user_id = :u1)                                         AS visits,
        (SELECT COUNT(*) FROM borrowings WHERE user_id = :u2)                                         AS borrowings,
        (SELECT COUNT(*) FROM borrowings WHERE user_id = :u3 AND status = 'Borrowed')                 AS active_loans,
        (SELECT COUNT(*) FROM borrowings WHERE user_id = :u4 AND status = 'Borrowed' AND due_date < :t) AS overdue,
        (SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE user_id = :u5 AND status = 'Unpaid')    AS unpaid,
        (SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE user_id = :u6 AND status = 'Paid')      AS paid",
    ['u1' => $id, 'u2' => $id, 'u3' => $id, 'u4' => $id, 'u5' => $id, 'u6' => $id, 't' => $today]
);
$activeVisit = db_one('SELECT time_in FROM attendance WHERE user_id = ? AND time_out IS NULL', [$id]);

if ($tab === 'attendance') {
    $p    = paginate((int) $summary['visits'], 12);
    $rows = db_all(
        "SELECT * FROM attendance WHERE user_id = ? ORDER BY time_in DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}",
        [$id]
    );
} elseif ($tab === 'penalties') {
    $total = (int) db_value('SELECT COUNT(*) FROM penalties WHERE user_id = ?', [$id]);
    $p     = paginate($total, 12);
    $rows  = db_all(
        "SELECT pn.*, br.transaction_no, bk.title
           FROM penalties pn
           JOIN borrowings br ON br.id = pn.borrowing_id
           JOIN books bk ON bk.id = br.book_id
          WHERE pn.user_id = ?
          ORDER BY pn.id DESC
          LIMIT {$p['per_page']} OFFSET {$p['offset']}",
        [$id]
    );
} else {
    $p    = paginate((int) $summary['borrowings'], 12);
    $rows = db_all(
        "SELECT br.*, bk.title, bk.author, r.return_date, r.penalty_amount
           FROM borrowings br
           JOIN books bk ON bk.id = br.book_id
           LEFT JOIN returns r ON r.borrowing_id = br.id
          WHERE br.user_id = ?
          ORDER BY br.id DESC
          LIMIT {$p['per_page']} OFFSET {$p['offset']}",
        [$id]
    );
}

$pageTitle = full_name($user);
$activeNav = 'users';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e(full_name($user)) ?></h1>
        <p><?= e($user['user_type']) ?> · <?= e($user['id_number']) ?>
            <?= $activeVisit ? ' · <strong class="text-success">In the library since ' . e(fmt_time($activeVisit['time_in'])) . '</strong>' : '' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('users/index.php')) ?>"><?= icon('arrow-left') ?> Users</a>
        <a class="btn btn-secondary" href="<?= e(url('users/form.php', ['id' => $id])) ?>"><?= icon('edit') ?> Edit</a>
        <?php if ($user['status'] === 'Active'): ?>
            <a class="btn" href="<?= e(url('borrowing/create.php', ['user_id' => $id])) ?>"><?= icon('borrow') ?> Lend a book</a>
        <?php endif; ?>
    </div>
</div>

<section class="stats">
    <div class="stat"><span class="stat-icon info"><?= icon('clock', 22) ?></span>
        <span><span class="stat-value"><?= (int) $summary['visits'] ?></span><span class="stat-label">Library visits</span></span></div>
    <div class="stat"><span class="stat-icon"><?= icon('book', 22) ?></span>
        <span><span class="stat-value"><?= (int) $summary['active_loans'] ?></span><span class="stat-label">Currently borrowed · <?= (int) $summary['borrowings'] ?> total</span></span></div>
    <div class="stat"><span class="stat-icon danger"><?= icon('alert', 22) ?></span>
        <span><span class="stat-value"><?= (int) $summary['overdue'] ?></span><span class="stat-label">Overdue books</span></span></div>
    <div class="stat"><span class="stat-icon warning"><?= icon('card', 22) ?></span>
        <span><span class="stat-value"><?= money($summary['unpaid']) ?></span><span class="stat-label">Unpaid · <?= money($summary['paid']) ?> paid</span></span></div>
</section>

<div class="grid-3-1">
    <section class="card">
        <nav class="tabs">
            <a class="tab<?= $tab === 'borrowings' ? ' active' : '' ?>" href="<?= e(url('users/view.php', ['id' => $id, 'tab' => 'borrowings'])) ?>">Borrowing history</a>
            <a class="tab<?= $tab === 'attendance' ? ' active' : '' ?>" href="<?= e(url('users/view.php', ['id' => $id, 'tab' => 'attendance'])) ?>">Attendance history</a>
            <a class="tab<?= $tab === 'penalties' ? ' active' : '' ?>" href="<?= e(url('users/view.php', ['id' => $id, 'tab' => 'penalties'])) ?>">Penalties</a>
        </nav>
        <div class="table-wrap">
            <?php if ($tab === 'attendance'): ?>
                <table>
                    <thead><tr><th>Date</th><th>Time in</th><th>Time out</th><th>Duration</th><th>Purpose</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><?= empty_row(5, 'No attendance records yet.') ?><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="nowrap"><?= fmt_date($r['attendance_date']) ?></td>
                            <td><?= fmt_time($r['time_in']) ?></td>
                            <td><?= $r['time_out'] ? fmt_time($r['time_out']) : badge('Inside') ?></td>
                            <td><?= fmt_duration($r['time_in'], $r['time_out']) ?></td>
                            <td><?= e($r['purpose'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($tab === 'penalties'): ?>
                <table>
                    <thead><tr><th>Transaction</th><th>Book</th><th class="num">Days late</th><th class="num">Amount</th><th>Status</th><th class="actions"></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><?= empty_row(6, 'No penalties recorded.') ?><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><a href="<?= e(url('borrowing/view.php', ['id' => $r['borrowing_id']])) ?>"><?= e($r['transaction_no']) ?></a></td>
                            <td><?= e($r['title']) ?></td>
                            <td class="num"><?= (int) $r['overdue_days'] ?></td>
                            <td class="num"><?= money($r['amount']) ?></td>
                            <td><?= badge($r['status']) ?><?= $r['paid_at'] ? '<span class="cell-sub">' . e(fmt_date($r['paid_at'])) . '</span>' : '' ?></td>
                            <td class="actions">
                                <?php if ($r['status'] === 'Unpaid'): ?>
                                    <a class="btn btn-sm" href="<?= e(url('penalties/pay.php', ['id' => $r['id']])) ?>">Record payment</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table>
                    <thead><tr><th>Transaction</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><?= empty_row(6, 'No borrowing records yet.') ?><?php endif; ?>
                    <?php foreach ($rows as $r):
                        $status = $r['status'] === 'Borrowed' && $r['due_date'] < $today ? 'Overdue' : $r['status']; ?>
                        <tr>
                            <td><a href="<?= e(url('borrowing/view.php', ['id' => $r['id']])) ?>"><?= e($r['transaction_no']) ?></a></td>
                            <td><span class="cell-title"><?= e($r['title']) ?></span><span class="cell-sub"><?= e($r['author']) ?></span></td>
                            <td class="nowrap"><?= fmt_date($r['borrow_date']) ?></td>
                            <td class="nowrap"><?= fmt_date($r['due_date']) ?></td>
                            <td class="nowrap"><?= fmt_date($r['return_date']) ?></td>
                            <td><?= badge($status) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?= pagination_links($p) ?>
    </section>

    <div>
        <section class="card">
            <div class="card-head"><h2>Profile</h2><?= badge($user['status']) ?></div>
            <div class="card-body">
                <dl class="details">
                    <dt>ID number</dt><dd><?= e($user['id_number']) ?></dd>
                    <dt>Type</dt><dd><?= e($user['user_type']) ?></dd>
                    <dt>Department</dt><dd><?= e($user['department'] ?: '—') ?></dd>
                    <?php if ($user['year_level']): ?><dt>Year</dt><dd><?= e($user['year_level']) ?></dd><?php endif; ?>
                    <dt>Email</dt><dd><?= e($user['email'] ?: '—') ?></dd>
                    <dt>Phone</dt><dd><?= e($user['phone'] ?: '—') ?></dd>
                    <dt>Address</dt><dd><?= e($user['address'] ?: '—') ?></dd>
                    <dt>Registered</dt><dd><?= fmt_date($user['created_at']) ?></dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Account status</h2></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('users/status.php')) ?>" class="quick-entry">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <select name="status" class="grow" aria-label="Account status">
                        <?php foreach (['Active', 'Inactive', 'Suspended'] as $s): ?>
                            <option <?= $user['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit">Update</button>
                </form>
                <p class="muted" style="font-size:.82rem;margin:12px 0 0">Inactive and suspended users cannot time in or borrow books.</p>
                <form method="post" action="<?= e(url('users/delete.php')) ?>" style="margin-top:16px"
                      data-confirm-title="Delete this user?" data-confirm-ok="Delete user" data-confirm-variant="danger"
                      data-confirm="<?= e(full_name($user)) ?> will be permanently deleted. Users with attendance or borrowing records cannot be deleted; set them to Inactive instead.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-ghost btn-sm text-danger" type="submit"><?= icon('trash', 15) ?> Delete user</button>
                </form>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
