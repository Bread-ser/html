<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (current_user()['role'] !== 'manager') {
    http_response_code(403);
    exit('Отчёт доступен менеджеру');
}

$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';

$where = ['1 = 1'];
$params = [];
if ($from !== '') {
    $where[] = 'r.start_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'r.start_date <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare(
    "SELECT status, COUNT(*) AS c FROM requests r WHERE $whereSql GROUP BY status"
);
$stmt->execute($params);
$counts = ['new' => 0, 'in_progress' => 0, 'waiting_parts' => 0, 'ready' => 0];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['c'];
}
$total = array_sum($counts);

$stmt = db()->prepare(
    "SELECT ROUND(AVG(DATEDIFF(completion_date, start_date)), 1)
     FROM requests r
     WHERE $whereSql AND completion_date IS NOT NULL"
);
$stmt->execute($params);
$avgDays = $stmt->fetchColumn();

$stmt = db()->prepare('CALL stats_by_type(?, ?)');
$stmt->execute([$from ?: null, $to ?: null]);
$byType = $stmt->fetchAll();
$stmt->closeCursor();

$stmt = db()->prepare('CALL stats_by_master(?, ?)');
$stmt->execute([$from ?: null, $to ?: null]);
$byMaster = $stmt->fetchAll();
$stmt->closeCursor();

$maxType = 0;
foreach ($byType as $row) {
    $maxType = max($maxType, (int)$row['total']);
}

$title = 'Отчёт';
$active = 'report';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
    <div>
        <h1>Отчёт по заявкам</h1>
        <p class="muted">Сводка за выбранный период. Можно распечатать или выгрузить в CSV.</p>
    </div>
    <div class="head-actions no-print">
        <a class="btn btn-ghost" href="export.php?<?= e(http_build_query(['from' => $from, 'to' => $to])) ?>">Скачать CSV</a>
        <button class="btn" type="button" onclick="window.print()">Печать</button>
    </div>
</div>

<form class="card filters no-print" method="get">
    <label class="field">
        <span>С даты</span>
        <input type="date" name="from" value="<?= e($from) ?>">
    </label>
    <label class="field">
        <span>По дату</span>
        <input type="date" name="to" value="<?= e($to) ?>">
    </label>
    <div class="filters-actions">
        <button class="btn" type="submit">Показать</button>
        <?php if ($from !== '' || $to !== ''): ?>
            <a class="btn btn-ghost" href="report.php">Сбросить</a>
        <?php endif; ?>
    </div>
</form>

<section class="kpi-grid">
    <article class="card kpi">
        <span class="kpi-label">Всего заявок</span>
        <strong class="kpi-value"><?= $total ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">Выполнено</span>
        <strong class="kpi-value"><?= $counts['ready'] ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">В работе</span>
        <strong class="kpi-value"><?= $counts['in_progress'] + $counts['waiting_parts'] ?></strong>
    </article>
    <article class="card kpi">
        <span class="kpi-label">Средний ремонт</span>
        <strong class="kpi-value"><?= $avgDays !== null ? e($avgDays) : '—' ?><small> дн.</small></strong>
    </article>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>По типам техники</h2></div>
        <?php if (!$byType): ?>
            <p class="muted">Нет данных за период.</p>
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
            <div class="table-wrap">
                <table class="table compact">
                    <thead><tr><th>Тип</th><th>Всего</th><th>Готово</th><th>Ср. дней</th></tr></thead>
                    <tbody>
                    <?php foreach ($byType as $row): ?>
                        <tr>
                            <td><?= e($row['tech_type']) ?></td>
                            <td><?= (int)$row['total'] ?></td>
                            <td><?= (int)$row['done'] ?></td>
                            <td><?= $row['avg_days'] !== null ? e($row['avg_days']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>По мастерам</h2></div>
        <?php if (!$byMaster): ?>
            <p class="muted">Нет техников в системе.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Мастер</th><th>Заявок</th><th>Готово</th></tr></thead>
                    <tbody>
                    <?php foreach ($byMaster as $row): ?>
                        <tr>
                            <td><?= e($row['master']) ?></td>
                            <td><?= (int)$row['total'] ?></td>
                            <td><?= (int)$row['done'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="status-grid">
            <?php foreach (statuses() as $status): ?>
                <div class="status-cell <?= e(status_class($status)) ?>">
                    <span><?= e(status_label($status)) ?></span>
                    <strong><?= $counts[$status] ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
