<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$q      = get('q');
$type   = get('type');
$status = get('status');

$types    = ['Student', 'Teacher', 'Staff', 'Other'];
$statuses = ['Active', 'Inactive', 'Suspended'];

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = "(u.id_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
                 OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR CONCAT(u.last_name, ', ', u.first_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($type, $types, true)) {
    $where[]  = 'u.user_type = ?';
    $params[] = $type;
}
if (in_array($status, $statuses, true)) {
    $where[]  = 'u.status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM users u $whereSql", $params);
$p     = paginate($total, 15);
$users = db_all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM borrowings br WHERE br.user_id = u.id AND br.status = 'Borrowed') AS active_loans,
            (SELECT COALESCE(SUM(pn.amount), 0) FROM penalties pn WHERE pn.user_id = u.id AND pn.status = 'Unpaid') AS unpaid,
            (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.id AND a.time_out IS NULL) AS inside
       FROM users u
       $whereSql
      ORDER BY u.last_name, u.first_name
      LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$pageTitle = 'Library Users';
$activeNav = 'users';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Library users</h1>
        <p>Students, teachers and staff registered to use the library.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('users/form.php')) ?>"><?= icon('plus') ?> Register user</a>
    </div>
</div>

<section class="card">
    <form class="toolbar" method="get">
        <div class="field grow">
            <label for="q">Search</label>
            <div class="search-input">
                <?= icon('search', 16) ?>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or student / employee ID">
            </div>
        </div>
        <div class="field">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All types</option>
                <?php foreach ($types as $t): ?>
                    <option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $s): ?>
                    <option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit"><?= icon('search', 16) ?> Search</button>
        <?php if ($q !== '' || $type !== '' || $status !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(url('users/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>ID number</th>
                <th>Type</th>
                <th>Course / Department</th>
                <th class="num">Borrowed</th>
                <th class="num">Unpaid</th>
                <th>Status</th>
                <th class="actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <?= empty_row(8, $q !== '' ? 'No users match your search.' : 'No users registered yet.') ?>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $u['id']])) ?>"><?= e($u['last_name'] . ', ' . $u['first_name']) ?></a>
                        <span class="cell-sub"><?= e($u['email'] ?: '') ?><?= $u['inside'] ? ' · In the library now' : '' ?></span>
                    </td>
                    <td class="nowrap"><?= e($u['id_number']) ?></td>
                    <td><?= e($u['user_type']) ?></td>
                    <td><?= e($u['department'] ?: '—') ?><?= $u['year_level'] ? '<span class="cell-sub">' . e($u['year_level']) . '</span>' : '' ?></td>
                    <td class="num"><?= (int) $u['active_loans'] ?></td>
                    <td class="num <?= $u['unpaid'] > 0 ? 'text-danger' : '' ?>"><?= $u['unpaid'] > 0 ? money($u['unpaid']) : '—' ?></td>
                    <td><?= badge($u['status']) ?></td>
                    <td class="actions">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('users/view.php', ['id' => $u['id']])) ?>" title="View"><?= icon('eye', 15) ?></a>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('users/form.php', ['id' => $u['id']])) ?>" title="Edit"><?= icon('edit', 15) ?></a>
                        <?php if ($u['status'] === 'Active'): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('borrowing/create.php', ['user_id' => $u['id']])) ?>" title="Lend a book"><?= icon('borrow', 15) ?></a>
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
