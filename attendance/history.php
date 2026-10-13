<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$from = get('from', today());
$to   = get('to', today());
$q    = get('q');
$type = get('type');

if (!valid_date($from)) {
    $from = today();
}
if (!valid_date($to)) {
    $to = today();
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = ['a.attendance_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($q !== '') {
    $where[] = "(u.id_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($type, ['Student', 'Teacher', 'Staff', 'Other'], true)) {
    $where[]  = 'u.user_type = ?';
    $params[] = $type;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$summary = db_one(
    "SELECT COUNT(*) AS visits, COUNT(DISTINCT a.user_id) AS people, SUM(a.time_out IS NULL) AS open_entries
       FROM attendance a JOIN users u ON u.id = a.user_id $whereSql",
    $params
);
$p    = paginate((int) $summary['visits'], 20);
$rows = db_all(
    "SELECT a.*, u.id_number, u.first_name, u.last_name, u.user_type, u.department
       FROM attendance a JOIN users u ON u.id = a.user_id
       $whereSql
      ORDER BY a.time_in DESC
      LIMIT {$p['per_page']} OFFSET {$p['offset']}",
    $params
);

$pageTitle = 'Attendance History';
$activeNav = 'attendance';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance history</h1>
        <p><?= fmt_date($from) ?><?= $from !== $to ? ' – ' . fmt_date($to) : '' ?> ·
            <?= (int) $summary['visits'] ?> visit(s) by <?= (int) $summary['people'] ?> user(s)</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('attendance/index.php')) ?>"><?= icon('arrow-left') ?> Attendance</a>
        <a class="btn btn-secondary" href="<?= e(url('reports/index.php', ['type' => 'attendance', 'from' => $from, 'to' => $to])) ?>"><?= icon('printer') ?> Printable report</a>
    </div>
</div>

<section class="card">
    <form class="toolbar" method="get">
        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="field grow">
            <label for="q">Name or ID</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search user">
        </div>
        <div class="field">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All</option>
                <?php foreach (['Student', 'Teacher', 'Staff', 'Other'] as $t): ?>
                    <option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit"><?= icon('search', 16) ?> Filter</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>User</th><th>Type</th><th>Purpose</th><th>Time in</th><th>Time out</th><th>Duration</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><?= empty_row(7, 'No attendance records for the selected filters.') ?><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><?= fmt_date($r['attendance_date']) ?></td>
                    <td>
                        <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $r['user_id'], 'tab' => 'attendance'])) ?>"><?= e($r['last_name'] . ', ' . $r['first_name']) ?></a>
                        <span class="cell-sub"><?= e($r['id_number']) ?><?= $r['department'] ? ' · ' . e($r['department']) : '' ?></span>
                    </td>
                    <td><?= e($r['user_type']) ?></td>
                    <td><?= e($r['purpose'] ?: '—') ?></td>
                    <td class="nowrap"><?= fmt_time($r['time_in']) ?></td>
                    <td class="nowrap"><?= $r['time_out'] ? fmt_time($r['time_out']) : badge('Inside') ?></td>
                    <td><?= fmt_duration($r['time_in'], $r['time_out']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
