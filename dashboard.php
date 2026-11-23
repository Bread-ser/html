<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$user = current_user();
[$scope, $params] = request_scope('r');

$stmt = db()->prepare("SELECT status, COUNT(*) AS c FROM requests r WHERE $scope GROUP BY status");
$stmt->execute($params);
$counts = ['new' => 0, 'in_progress' => 0, 'waiting_parts' => 0, 'ready' => 0];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['c'];
}
$total = array_sum($counts);

$stmt = db()->prepare(
    "SELECT ROUND(AVG(DATEDIFF(completion_date, start_date)), 1)
     FROM requests r
     WHERE $scope AND completion_date IS NOT NULL"
);
$stmt->execute($params);
$avgDays = $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT r.*, c.fio AS client_fio, m.fio AS master_fio
     FROM requests r
     JOIN users c ON c.user_id = r.client_id
     LEFT JOIN users m ON m.user_id = r.master_id
     WHERE $scope
     ORDER BY r.request_id DESC
     LIMIT 6"
);
$stmt->execute($params);
$recent = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT tech_type, COUNT(*) AS total
     FROM requests r
     WHERE $scope
     GROUP BY tech_type
     ORDER BY total DESC, tech_type
     LIMIT 6"
);
$stmt->execute($params);
$byType = $stmt->fetchAll();
$maxType = 0;
foreach ($byType as $row) {
    $maxType = max($maxType, (int)$row['total']);
}

$title = 'Обзор';
$active = 'dashboard';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
    <div>
        <h1>Привет, <?= e(short_name($user['fio'])) ?></h1>
        <p class="muted">Коротко о том, что происходит с заявками.</p>
    </div>
    <?php if (in_array($user['role'], ['manager', 'operator', 'customer'], true)): ?>
        <a class="btn btn-primary" href="request_form.php">+ Новая заявка</a>
    <?php endif; ?>
</div>

<section class="kpi-grid">
    <article class="card kpi">
        <span class="kpi-label">Всего заявок</span>
        <strong class="kpi-value"><?= $total ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">В работе</span>
        <strong class="kpi-value"><?= $counts['in_progress'] + $counts['waiting_parts'] ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">Готовы к выдаче</span>
        <strong class="kpi-value"><?= $counts['ready'] ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">Средний ремонт</span>
        <strong class="kpi-value"><?= $avgDays !== null ? e($avgDays) : '—' ?><small> дн.</small></strong>
    </article>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Последние заявки</h2>
            <a class="link" href="requests.php">все заявки →</a>
        </div>
        <?php if (!$recent): ?>
            <p class="muted">Заявок пока нет.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>№</th><th>Техника</th><th>Статус</th><th>Мастер</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><a href="request.php?id=<?= (int)$row['request_id'] ?>">#<?= (int)$row['request_id'] ?></a></td>
                            <td>
                                <strong><?= e($row['tech_type']) ?></strong>
                                <small class="muted block"><?= e($row['tech_model']) ?></small>
                            </td>
                            <td><span class="<?= e(status_class($row['status'])) ?>"><?= e(status_label($row['status'])) ?></span></td>
                            <td><?= e($row['master_fio'] ? short_name($row['master_fio']) : 'не назначен') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>По типам техники</h2>
        </div>
        <?php if (!$byType): ?>
            <p class="muted">Пока нечего показать.</p>
        <?php else: ?>
            <div class="bars">
                <?php foreach ($byType as $row): ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= e($row['tech_type']) ?></span>
                        <div class="bar-track">
                            <div class="bar-fill" style="width: <?= $maxType ? round(100 * $row['total'] / $maxType) : 0 ?>%"></div>
                        </div>
                        <span class="bar-value"><?= (int)$row['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="status-grid">
            <?php foreach (statuses() as $status): ?>
                <a class="status-cell <?= e(status_class($status)) ?>" href="requests.php?status=<?= e($status) ?>">
                    <span><?= e(status_label($status)) ?></span>
                    <strong><?= $counts[$status] ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
