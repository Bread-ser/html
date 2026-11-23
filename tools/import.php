<?php
/**
 * Импорт исходных CSV в БД.
 * Запуск: php tools/import.php [папка_с_csv]
 * По умолчанию: <проект>/data
 */
if (PHP_SAPI !== 'cli') {
    exit('Скрипт запускается только из консоли');
}

require __DIR__ . '/../inc/bootstrap.php';

$dir = $argv[1] ?? __DIR__ . '/../data';
$files = [
    'users'    => $dir . '/users.csv',
    'requests' => $dir . '/requests.csv',
    'comments' => $dir . '/comments.csv',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Не найден файл: $path\n");
        exit(1);
    }
}

function read_csv(string $path): array
{
    $content = file_get_contents($path);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'CP866');
    }
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = preg_split('/\r\n|\r|\n/', $content);

    $rows = [];
    $header = null;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, ';', '"', '');
        if ($header === null) {
            $header = array_map('trim', $cells);
            continue;
        }
        $rows[] = array_combine($header, array_map('trim', $cells));
    }
    return $rows;
}

function val(array $row, string $key): ?string
{
    $value = $row[$key] ?? null;
    if ($value === null || $value === '' || strcasecmp($value, 'null') === 0) {
        return null;
    }
    return $value;
}

$roleMap = [
    'Менеджер' => 'manager',
    'Техник'   => 'technician',
    'Оператор' => 'operator',
    'Заказчик' => 'customer',
];
$statusMap = [
    'Новая заявка'           => 'new',
    'В процессе ремонта'     => 'in_progress',
    'Ожидание комплектующих' => 'waiting_parts',
    'Готова к выдаче'        => 'ready',
];

$users = read_csv($files['users']);
$requests = read_csv($files['requests']);
$comments = read_csv($files['comments']);

$pdo = db();

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['request_events', 'notifications', 'comments', 'requests', 'users'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->beginTransaction();

try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (user_id, fio, phone, login, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($users as $i => $row) {
        $role = $roleMap[$row['type']] ?? null;
        if (!$role) {
            throw new RuntimeException('Неизвестная роль в users.csv, строка ' . ($i + 2) . ': ' . $row['type']);
        }
        $insertUser->execute([
            (int)$row['userID'],
            $row['fio'],
            $row['phone'],
            $row['login'],
            password_hash($row['password'], PASSWORD_DEFAULT),
            $role,
        ]);
    }

    $insertRequest = $pdo->prepare(
        'INSERT INTO requests
            (request_id, start_date, tech_type, tech_model, problem, status, completion_date, repair_parts, master_id, client_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($requests as $i => $row) {
        $status = $statusMap[$row['requestStatus']] ?? null;
        if (!$status) {
            throw new RuntimeException('Неизвестный статус в requests.csv, строка ' . ($i + 2) . ': ' . $row['requestStatus']);
        }
        $insertRequest->execute([
            (int)$row['requestID'],
            $row['startDate'],
            $row['computerTechType'],
            $row['computerTechModel'],
            $row['problemDescryption'],
            $status,
            val($row, 'completionDate'),
            val($row, 'repairParts'),
            val($row, 'masterID') !== null ? (int)$row['masterID'] : null,
            (int)$row['clientID'],
        ]);
    }

    $insertComment = $pdo->prepare(
        'INSERT INTO comments (comment_id, message, master_id, request_id) VALUES (?, ?, ?, ?)'
    );
    foreach ($comments as $row) {
        $insertComment->execute([
            (int)$row['commentID'],
            $row['message'],
            (int)$row['masterID'],
            (int)$row['requestID'],
        ]);
    }

    // Убираем служебные уведомления, созданные триггером при импорте
    $pdo->exec('DELETE FROM notifications');
    $pdo->exec('DELETE FROM request_events');

    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, 'Ошибка импорта: ' . $ex->getMessage() . "\n");
    exit(1);
}

printf(
    "Импорт завершён: пользователей — %d, заявок — %d, комментариев — %d.\n",
    count($users),
    count($requests),
    count($comments)
);
