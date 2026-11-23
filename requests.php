<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$user = current_user();
[$scope, $params] = request_scope('r');
$where = [$scope];

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(CAST(r.request_id AS CHAR) LIKE ? OR r.tech_type LIKE ? OR r.tech_model LIKE ?
                 OR r.problem LIKE ? OR c.fio LIKE ? OR m.fio LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (in_array($status, statuses(), true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

$sql = "SELECT r.*, c.fio AS client_fio, c.phone AS client_phone, m.fio AS master_fio
        FROM requests r
        JOIN users c ON c.user_id = r.client_id
        LEFT JOIN users m ON m.user_id = r.master_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.request_id DESC
        LIMIT 100";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$title = 'Заявки';
$active = 'requests';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
    <div>
        <h1>Заявки</h1>
        <p class="muted">Найдено: <?= count($rows) ?><?= count($rows) === 100 ? ' (показаны первые 100)' : '' ?></p>
    </div>
    <?php if (in_array($user['role'], ['manager', 'operator', 'customer'], true)): ?>
        <a class="btn btn-primary" href="request_form.php">+ Новая заявка</a>
    <?php endif; ?>
</div>

<form class="card filters" method="get">
    <label class="field field-grow">
        <span>Поиск</span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="номер, модель, проблема, клиент…">
    </label>
    <label class="field">
        <span>Статус</span>
        <select name="status">
            <option value="">Все</option>
            <?php foreach (statuses() as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filters-actions">
        <button class="btn" type="submit">Найти</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a class="btn btn-ghost" href="requests.php">Сбросить</a>
        <?php endif; ?>
    </div>
</form>

<section class="card">
    <?php if (!$rows): ?>
        <p class="muted">Ничего не найдено.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>№</th>
                    <th>Дата</th>
                    <th>Техника</th>
                    <th>Клиент</th>
                    <th>Мастер</th>
                    <th>Статус</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="request.php?id=<?= (int)$row['request_id'] ?>">#<?= (int)$row['request_id'] ?></a></td>
                        <td class="nowrap"><?= e(format_date($row['start_date'])) ?></td>
                        <td>
                            <strong><?= e($row['tech_type']) ?></strong>
                            <small class="muted block"><?= e($row['tech_model']) ?></small>
                        </td>
                        <td>
                            <?= e($row['client_fio']) ?>
                            <small class="muted block"><?= e($row['client_phone']) ?></small>
                        </td>
                        <td><?= e($row['master_fio'] ? short_name($row['master_fio']) : '—') ?></td>
                        <td><span class="<?= e(status_class($row['status'])) ?>"><?= e(status_label($row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
