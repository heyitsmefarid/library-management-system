<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$purposes = ['Study / Reading', 'Research', 'Borrow / Return books', 'Computer use', 'Group work', 'Other'];

/** Records a time-in. Returns [type, message]. */
function attendance_time_in(int $userId, ?string $purpose): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $user = db_one('SELECT id, first_name, last_name, status FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if (!$user) {
            $pdo->rollBack();
            return ['danger', 'User not found.'];
        }
        $name = $user['first_name'] . ' ' . $user['last_name'];
        if ($user['status'] !== 'Active') {
            $pdo->rollBack();
            return ['danger', "{$name}'s account is {$user['status']}. Only active users can time in."];
        }
        $open = db_one('SELECT time_in FROM attendance WHERE user_id = ? AND time_out IS NULL', [$userId]);
        if ($open) {
            $pdo->rollBack();
            return ['warning', "{$name} is already timed in (since " . fmt_datetime($open['time_in']) . ').'];
        }
        $now = date('Y-m-d H:i:s');
        db_exec(
            'INSERT INTO attendance (user_id, attendance_date, time_in, purpose, recorded_by) VALUES (?, ?, ?, ?, ?)',
            [$userId, date('Y-m-d'), $now, $purpose, current_admin_id()]
        );
        $pdo->commit();
        return ['success', "{$name} timed in at " . fmt_time($now) . '.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_duplicate_error($e)) {   // unique active-entry index
            return ['warning', 'This user already has an active attendance entry.'];
        }
        throw $e;
    }
}

/** Records a time-out for the user's open entry. Returns [type, message]. */
function attendance_time_out(int $userId): array
{
    $open = db_one(
        'SELECT a.id, a.time_in, u.first_name, u.last_name
           FROM attendance a JOIN users u ON u.id = a.user_id
          WHERE a.user_id = ? AND a.time_out IS NULL',
        [$userId]
    );
    if (!$open) {
        return ['warning', 'This user is not currently timed in.'];
    }
    $now = date('Y-m-d H:i:s');
    // The "time_out IS NULL" condition makes a double submission harmless.
    $updated = db_exec('UPDATE attendance SET time_out = ? WHERE id = ? AND time_out IS NULL', [$now, $open['id']]);
    if ($updated === 0) {
        return ['warning', 'This attendance entry was already closed.'];
    }
    return ['success', "{$open['first_name']} {$open['last_name']} timed out at " . fmt_time($now)
        . ' (stayed ' . fmt_duration($open['time_in'], $now) . ').'];
}

if (is_post()) {
    $action  = post('action');
    $purpose = post('purpose');
    $purpose = in_array($purpose, $purposes, true) ? $purpose : null;

    if ($action === 'toggle') {
        $idNumber = strtoupper(post('id_number'));
        $user     = $idNumber !== '' ? db_one('SELECT id FROM users WHERE id_number = ?', [$idNumber]) : null;
        if (!$user) {
            flash('danger', $idNumber === '' ? 'Enter a student or employee ID.' : "No user found with ID \"{$idNumber}\".");
        } else {
            $isInside = db_value('SELECT 1 FROM attendance WHERE user_id = ? AND time_out IS NULL', [$user['id']]);
            [$type, $msg] = $isInside ? attendance_time_out((int) $user['id']) : attendance_time_in((int) $user['id'], $purpose);
            flash($type, $msg);
        }
    } elseif ($action === 'time_in') {
        [$type, $msg] = attendance_time_in((int) post('user_id'), $purpose);
        flash($type, $msg);
    } elseif ($action === 'time_out') {
        [$type, $msg] = attendance_time_out((int) post('user_id'));
        flash($type, $msg);
    } elseif ($action === 'time_out_all') {
        $n = db_exec('UPDATE attendance SET time_out = NOW() WHERE time_out IS NULL');
        flash('success', "{$n} user(s) were timed out.");
    }

    $back = get('q') !== '' ? ['q' => get('q')] : [];
    redirect('attendance/index.php', $back);
}

// Search users for manual time-in / time-out
$q       = get('q');
$results = [];
if ($q !== '') {
    $like    = '%' . $q . '%';
    $results = db_all(
        "SELECT u.id, u.id_number, u.first_name, u.last_name, u.user_type, u.department, u.status,
                a.time_in AS open_since
           FROM users u
           LEFT JOIN attendance a ON a.user_id = u.id AND a.time_out IS NULL
          WHERE u.id_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
                OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
          ORDER BY u.last_name, u.first_name
          LIMIT 25",
        [$like, $like, $like, $like]
    );
}

$inside = db_all(
    "SELECT a.time_in, a.purpose, a.attendance_date, u.id, u.id_number, u.first_name, u.last_name, u.user_type
       FROM attendance a JOIN users u ON u.id = a.user_id
      WHERE a.time_out IS NULL
      ORDER BY a.time_in DESC"
);

$todayStats = db_one(
    'SELECT COUNT(*) AS visits, COUNT(DISTINCT user_id) AS people, SUM(time_out IS NOT NULL) AS completed
       FROM attendance WHERE attendance_date = ?',
    [today()]
);

$pageTitle = 'Attendance';
$activeNav = 'attendance';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p>Record library users' time-in and time-out. Date and time are captured automatically.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('attendance/history.php')) ?>"><?= icon('calendar') ?> Attendance history</a>
    </div>
</div>

<section class="stats">
    <div class="stat"><span class="stat-icon info"><?= icon('user-check', 22) ?></span>
        <span><span class="stat-value"><?= count($inside) ?></span><span class="stat-label">Inside right now</span></span></div>
    <div class="stat"><span class="stat-icon"><?= icon('clock', 22) ?></span>
        <span><span class="stat-value"><?= (int) $todayStats['visits'] ?></span><span class="stat-label">Visits today</span></span></div>
    <div class="stat"><span class="stat-icon accent"><?= icon('users', 22) ?></span>
        <span><span class="stat-value"><?= (int) $todayStats['people'] ?></span><span class="stat-label">Unique visitors today</span></span></div>
    <div class="stat"><span class="stat-icon"><?= icon('log-out', 22) ?></span>
        <span><span class="stat-value"><?= (int) $todayStats['completed'] ?></span><span class="stat-label">Timed out today</span></span></div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <div><h2>Quick entry</h2><p>Enter or scan an ID. The system times the user in, or out if they are already inside.</p></div>
        </div>
        <div class="card-body">
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <div class="quick-entry">
                    <input class="grow" type="text" name="id_number" placeholder="Student / Employee ID" aria-label="Student or employee ID" required autofocus>
                    <button class="btn" type="submit"><?= icon('check') ?> Record</button>
                </div>
                <div class="field" style="margin-top:12px">
                    <label for="purpose">Purpose of visit (for time-in)</label>
                    <select id="purpose" name="purpose">
                        <option value="">— Not specified —</option>
                        <?php foreach ($purposes as $pp): ?><option><?= e($pp) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Find a user</h2><p>Search by name or student ID</p></div></div>
        <form class="toolbar" method="get" style="border-bottom:0">
            <div class="field grow">
                <div class="search-input">
                    <?= icon('search', 16) ?>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="e.g. Dela Cruz or 2024-00001" aria-label="Search users">
                </div>
            </div>
            <button class="btn" type="submit">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-secondary" href="<?= e(url('attendance/index.php')) ?>">Clear</a><?php endif; ?>
        </form>
        <?php if ($q !== ''): ?>
            <div class="table-wrap">
                <table>
                    <tbody>
                    <?php if (!$results): ?><?= empty_row(2, 'No users match your search.') ?><?php endif; ?>
                    <?php foreach ($results as $u): ?>
                        <tr>
                            <td>
                                <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $u['id']])) ?>"><?= e($u['last_name'] . ', ' . $u['first_name']) ?></a>
                                <span class="cell-sub"><?= e($u['id_number']) ?> · <?= e($u['user_type']) ?>
                                    <?= $u['open_since'] ? ' · inside since ' . e(fmt_time($u['open_since'])) : '' ?></span>
                            </td>
                            <td class="actions">
                                <?php if ($u['status'] !== 'Active'): ?>
                                    <?= badge($u['status']) ?>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('attendance/index.php', ['q' => $q])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <?php if ($u['open_since']): ?>
                                            <input type="hidden" name="action" value="time_out">
                                            <button class="btn btn-sm btn-warning" type="submit"><?= icon('log-out', 15) ?> Time out</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="time_in">
                                            <button class="btn btn-sm" type="submit"><?= icon('log-in', 15) ?> Time in</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="card" style="margin-top:22px">
    <div class="card-head">
        <div><h2>Currently inside the library</h2><p><?= count($inside) ?> active attendance entr<?= count($inside) === 1 ? 'y' : 'ies' ?></p></div>
        <?php if ($inside): ?>
            <form method="post" data-confirm-title="Time out everyone?" data-confirm-ok="Time out everyone"
                  data-confirm="All <?= count($inside) ?> user(s) still inside will be timed out now. Use this when closing the library.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="time_out_all">
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon('log-out', 15) ?> Time out everyone</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>User</th><th>Type</th><th>Purpose</th><th>Time in</th><th>Duration</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php if (!$inside): ?><?= empty_row(6, 'No one is currently timed in.') ?><?php endif; ?>
            <?php foreach ($inside as $a): ?>
                <tr>
                    <td>
                        <a class="cell-title" href="<?= e(url('users/view.php', ['id' => $a['id']])) ?>"><?= e($a['last_name'] . ', ' . $a['first_name']) ?></a>
                        <span class="cell-sub"><?= e($a['id_number']) ?></span>
                    </td>
                    <td><?= e($a['user_type']) ?></td>
                    <td><?= e($a['purpose'] ?: '—') ?></td>
                    <td class="nowrap"><?= $a['attendance_date'] === today() ? fmt_time($a['time_in']) : fmt_datetime($a['time_in']) ?></td>
                    <td><?= fmt_duration($a['time_in']) ?></td>
                    <td class="actions">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="time_out">
                            <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                            <button class="btn btn-sm btn-warning" type="submit"><?= icon('log-out', 15) ?> Time out</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
