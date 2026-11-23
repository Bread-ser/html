<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (current_user()['role'] !== 'manager') {
    http_response_code(403);
    exit('Экспорт доступен менеджеру');
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

$stmt = db()->prepare(
    'SELECT r.request_id, r.start_date, r.tech_type, r.tech_model, r.problem, r.status,
            r.completion_date, r.repair_parts, c.fio AS client, c.phone, m.fio AS master
     FROM requests r
     JOIN users c ON c.user_id = r.client_id
     LEFT JOIN users m ON m.user_id = r.master_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY r.request_id'
);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="requests_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['№', 'Дата', 'Тип', 'Модель', 'Проблема', 'Статус', 'Завершено', 'Запчасти', 'Клиент', 'Телефон', 'Мастер'], ';', '"', '');

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['request_id'],
        format_date($row['start_date']),
        $row['tech_type'],
        $row['tech_model'],
        $row['problem'],
        status_label($row['status']),
        $row['completion_date'] ? format_date($row['completion_date']) : '',
        $row['repair_parts'] ?? '',
        $row['client'],
        $row['phone'],
        $row['master'] ?? '',
    ], ';', '"', '');
}
fclose($out);
