<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/jwt.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_ok(mixed $data = null, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function require_api_user(): array
{
    $user = api_user_from_token();
    if (!$user) {
        json_err('Требуется авторизация', 401);
    }
    return $user;
}

function api_request_scope(array $user, string $alias = 'r'): array
{
    return match ($user['role']) {
        'customer'   => ["$alias.client_id = ?", [$user['user_id']]],
        'technician' => ["$alias.master_id = ?", [$user['user_id']]],
        default      => ['1 = 1', []],
    };
}

function api_can_view(array $user, array $req): bool
{
    return match ($user['role']) {
        'customer'   => (int)$req['client_id'] === (int)$user['user_id'],
        'technician' => (int)$req['master_id'] === (int)$user['user_id'],
        default      => true,
    };
}

function api_can_edit(array $user, array $req): bool
{
    if (in_array($user['role'], ['manager', 'operator'], true)) {
        return true;
    }
    return $user['role'] === 'customer'
        && (int)$req['client_id'] === (int)$user['user_id']
        && $req['status'] === 'new';
}

function api_can_change_status(array $user, array $req): bool
{
    return in_array($user['role'], ['manager', 'operator'], true)
        || ($user['role'] === 'technician' && (int)$req['master_id'] === (int)$user['user_id']);
}

function api_notif_scope(array $user): array
{
    if ($user['role'] === 'customer') {
        return ['user_id = ?', [$user['user_id']]];
    }
    return ['(user_id = ? OR user_id IS NULL)', [$user['user_id']]];
}

function api_load_request(int $id): array
{
    $stmt = db()->prepare(
        'SELECT r.*, c.fio AS client_fio, c.phone AS client_phone, m.fio AS master_fio
         FROM requests r
         JOIN users c ON c.user_id = r.client_id
         LEFT JOIN users m ON m.user_id = r.master_id
         WHERE r.request_id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_err('Заявка не найдена', 404);
    }
    return $row;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $path = '/' . trim(substr($uriPath, strlen($scriptDir)), '/');

    // --- AUTH ---
    if ($path === '/auth/login' && $method === 'POST') {
        $b = json_body();
        $login = trim((string)($b['login'] ?? ''));
        $password = (string)($b['password'] ?? '');
        if ($login === '' || $password === '') {
            json_err('Введите логин и пароль', 422);
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            json_err('Неверный логин или пароль', 401);
        }
        $token = jwt_encode(['sub' => (int)$u['user_id'], 'role' => $u['role']]);
        unset($u['password_hash']);
        json_ok(['token' => $token, 'user' => $u]);
    }

    if ($path === '/auth/me' && $method === 'GET') {
        json_ok(require_api_user());
    }

    // Все остальные маршруты требуют JWT
    $user = require_api_user();

    // --- USERS ---
    if ($path === '/users' && $method === 'GET') {
        $role = (string)($_GET['role'] ?? '');
        if ($role !== '') {
            $stmt = db()->prepare('SELECT user_id, fio, phone, role FROM users WHERE role = ? ORDER BY fio');
            $stmt->execute([$role]);
        } else {
            if (!in_array($user['role'], ['manager', 'operator'], true)) {
                json_err('Нет доступа', 403);
            }
            $stmt = db()->query('SELECT user_id, fio, phone, login, role FROM users ORDER BY user_id');
        }
        json_ok($stmt->fetchAll());
    }

    // --- REQUESTS LIST / CREATE ---
    if ($path === '/requests' && $method === 'GET') {
        [$scope, $params] = api_request_scope($user, 'r');
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
        $stmt = db()->prepare(
            'SELECT r.*, c.fio AS client_fio, c.phone AS client_phone, m.fio AS master_fio
             FROM requests r
             JOIN users c ON c.user_id = r.client_id
             LEFT JOIN users m ON m.user_id = r.master_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.request_id DESC LIMIT 200'
        );
        $stmt->execute($params);
        json_ok($stmt->fetchAll());
    }

    if ($path === '/requests' && $method === 'POST') {
        if (!in_array($user['role'], ['manager', 'operator', 'customer'], true)) {
            json_err('Техник не создаёт заявки', 403);
        }
        $b = json_body();
        $techType = trim((string)($b['tech_type'] ?? ''));
        $techModel = trim((string)($b['tech_model'] ?? ''));
        $problem = trim((string)($b['problem'] ?? ''));
        $startDate = trim((string)($b['start_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = date('Y-m-d');
        }
        if ($techType === '' || $techModel === '' || $problem === '') {
            json_err('Заполните тип техники, модель и описание проблемы', 422);
        }
        $clientId = $user['role'] === 'customer' ? (int)$user['user_id'] : (int)($b['client_id'] ?? 0);
        $chk = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'customer'");
        $chk->execute([$clientId]);
        if (!$chk->fetchColumn()) {
            json_err('Выберите корректного заказчика', 422);
        }
        $stmt = db()->prepare(
            'INSERT INTO requests (start_date, tech_type, tech_model, problem, status, client_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$startDate, $techType, $techModel, $problem, 'new', $clientId]);
        $newId = (int)db()->lastInsertId();
        json_ok(api_load_request($newId), 201);
    }

    // --- REQUEST BY ID ---
    if (preg_match('#^/requests/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $req = api_load_request($id);

        if ($method === 'GET') {
            if (!api_can_view($user, $req)) {
                json_err('Нет доступа к заявке', 403);
            }
            $cStmt = db()->prepare(
                'SELECT cm.*, u.fio, u.role FROM comments cm
                 JOIN users u ON u.user_id = cm.master_id
                 WHERE cm.request_id = ? ORDER BY cm.comment_id'
            );
            $cStmt->execute([$id]);
            $eStmt = db()->prepare('SELECT * FROM request_events WHERE request_id = ? ORDER BY id DESC');
            $eStmt->execute([$id]);
            $req['comments'] = $cStmt->fetchAll();
            $req['events'] = $eStmt->fetchAll();
            json_ok($req);
        }

        if ($method === 'PUT') {
            if (!api_can_edit($user, $req)) {
                json_err('Нет доступа к редактированию', 403);
            }
            $b = json_body();
            $techType = trim((string)($b['tech_type'] ?? $req['tech_type']));
            $techModel = trim((string)($b['tech_model'] ?? $req['tech_model']));
            $problem = trim((string)($b['problem'] ?? $req['problem']));
            $startDate = trim((string)($b['start_date'] ?? $req['start_date']));
            $parts = array_key_exists('repair_parts', $b) ? trim((string)$b['repair_parts']) : $req['repair_parts'];
            if ($techType === '' || $techModel === '' || $problem === '') {
                json_err('Заполните обязательные поля', 422);
            }
            $stmt = db()->prepare(
                'UPDATE requests SET tech_type = ?, tech_model = ?, problem = ?, start_date = ?, repair_parts = ?
                 WHERE request_id = ?'
            );
            $stmt->execute([$techType, $techModel, $problem, $startDate, $parts ?: null, $id]);
            json_ok(api_load_request($id));
        }

        if ($method === 'DELETE') {
            if ($user['role'] !== 'manager') {
                json_err('Удалять заявки может только менеджер', 403);
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $pdo->prepare('DELETE FROM comments WHERE request_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM notifications WHERE request_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM request_events WHERE request_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM requests WHERE request_id = ?')->execute([$id]);

                $pdo->prepare('UPDATE requests SET request_id = request_id - 1 WHERE request_id > ? ORDER BY request_id ASC')->execute([$id]);
                $pdo->prepare('UPDATE comments SET request_id = request_id - 1 WHERE request_id > ?')->execute([$id]);
                $pdo->prepare('UPDATE notifications SET request_id = request_id - 1 WHERE request_id > ?')->execute([$id]);
                $pdo->prepare('UPDATE request_events SET request_id = request_id - 1 WHERE request_id > ?')->execute([$id]);

                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $pdo->exec('ALTER TABLE requests AUTO_INCREMENT = 1');
            json_ok(['deleted' => $id]);
        }
    }

    // --- STATUS & PARTS ---
    if (preg_match('#^/requests/(\d+)/status$#', $path, $m) && $method === 'PATCH') {
        $id = (int)$m[1];
        $req = api_load_request($id);
        if (!api_can_change_status($user, $req)) {
            json_err('Нет доступа к смене статуса', 403);
        }
        $b = json_body();
        $status = (string)($b['status'] ?? '');
        if (!in_array($status, statuses(), true)) {
            json_err('Неизвестный статус', 422);
        }
        $parts = array_key_exists('repair_parts', $b) ? trim((string)$b['repair_parts']) : $req['repair_parts'];
        $completion = $status === 'ready' ? ($req['completion_date'] ?: date('Y-m-d')) : null;
        $stmt = db()->prepare('UPDATE requests SET status = ?, completion_date = ?, repair_parts = ? WHERE request_id = ?');
        $stmt->execute([$status, $completion, $parts ?: null, $id]);
        json_ok(api_load_request($id));
    }

    // --- ASSIGN MASTER ---
    if (preg_match('#^/requests/(\d+)/master$#', $path, $m) && $method === 'PATCH') {
        if (!in_array($user['role'], ['manager', 'operator'], true)) {
            json_err('Нет доступа', 403);
        }
        $id = (int)$m[1];
        api_load_request($id);
        $b = json_body();
        $masterId = (int)($b['master_id'] ?? 0);
        if ($masterId > 0) {
            $chk = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'technician'");
            $chk->execute([$masterId]);
            if (!$chk->fetchColumn()) {
                json_err('Пользователь не является техником', 422);
            }
        }
        db()->prepare('UPDATE requests SET master_id = ? WHERE request_id = ?')->execute([$masterId ?: null, $id]);
        if ($masterId) {
            db()->prepare('INSERT INTO notifications (user_id, request_id, message) VALUES (?, ?, ?)')
                ->execute([$masterId, $id, 'Вам назначена заявка №' . $id]);
        }
        json_ok(api_load_request($id));
    }

    // --- COMMENTS ---
    if (preg_match('#^/requests/(\d+)/comments$#', $path, $m) && $method === 'POST') {
        $id = (int)$m[1];
        $req = api_load_request($id);
        if (!api_can_view($user, $req)) {
            json_err('Нет доступа', 403);
        }
        $b = json_body();
        $msg = trim((string)($b['message'] ?? ''));
        if ($msg === '') {
            json_err('Комментарий пустой', 422);
        }
        db()->prepare('INSERT INTO comments (message, master_id, request_id) VALUES (?, ?, ?)')
            ->execute([$msg, $user['user_id'], $id]);
        if ($user['role'] === 'customer') {
            db()->prepare('INSERT INTO notifications (user_id, request_id, message) VALUES (NULL, ?, ?)')
                ->execute([$id, 'Комментарий заказчика к заявке №' . $id]);
        }
        json_ok(['comment_id' => (int)db()->lastInsertId()], 201);
    }

    // --- STATS / REPORT ---
    if ($path === '/stats' && $method === 'GET') {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';

        [$scope, $params] = api_request_scope($user, 'r');
        $where = [$scope];
        if ($from !== '') { $where[] = 'r.start_date >= ?'; $params[] = $from; }
        if ($to !== '')   { $where[] = 'r.start_date <= ?'; $params[] = $to; }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare("SELECT status, COUNT(*) AS c FROM requests r WHERE $whereSql GROUP BY status");
        $stmt->execute($params);
        $counts = ['new' => 0, 'in_progress' => 0, 'waiting_parts' => 0, 'ready' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }

        $stmt = db()->prepare(
            "SELECT ROUND(AVG(DATEDIFF(completion_date, start_date)), 1)
             FROM requests r WHERE $whereSql AND completion_date IS NOT NULL"
        );
        $stmt->execute($params);
        $avgDays = $stmt->fetchColumn();

        if ($user['role'] === 'manager') {
            $s1 = db()->prepare('CALL stats_by_type(?, ?)');
            $s1->execute([$from ?: null, $to ?: null]);
            $byType = $s1->fetchAll();
            $s1->closeCursor();

            $s2 = db()->prepare('CALL stats_by_master(?, ?)');
            $s2->execute([$from ?: null, $to ?: null]);
            $byMaster = $s2->fetchAll();
            $s2->closeCursor();
        } else {
            $s1 = db()->prepare(
                "SELECT tech_type, COUNT(*) AS total, SUM(status = 'ready') AS done, NULL AS avg_days
                 FROM requests r WHERE $whereSql GROUP BY tech_type ORDER BY total DESC LIMIT 6"
            );
            $s1->execute($params);
            $byType = $s1->fetchAll();
            $byMaster = [];
        }

        json_ok([
            'total'     => array_sum($counts),
            'counts'    => $counts,
            'avg_days'  => $avgDays !== null ? (float)$avgDays : null,
            'by_type'   => $byType,
            'by_master' => $byMaster,
        ]);
    }

    // --- NOTIFICATIONS ---
    if ($path === '/notifications' && $method === 'GET') {
        [$cond, $params] = api_notif_scope($user);
        $stmt = db()->prepare("SELECT * FROM notifications WHERE $cond ORDER BY id DESC LIMIT 15");
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        $unread = 0;
        foreach ($items as $n) {
            if (!(int)$n['is_read']) $unread++;
        }
        json_ok(['unread' => $unread, 'items' => $items]);
    }

    if ($path === '/notifications/read' && $method === 'POST') {
        [$cond, $params] = api_notif_scope($user);
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND $cond")->execute($params);
        json_ok(['read' => true]);
    }

    // --- CSV EXPORT (MANAGER) ---
    if ($path === '/export' && $method === 'GET') {
        if ($user['role'] !== 'manager') {
            json_err('Экспорт доступен менеджеру', 403);
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
        $where = ['1 = 1'];
        $params = [];
        if ($from !== '') { $where[] = 'r.start_date >= ?'; $params[] = $from; }
        if ($to !== '')   { $where[] = 'r.start_date <= ?'; $params[] = $to; }

        $stmt = db()->prepare(
            'SELECT r.request_id, r.start_date, r.tech_type, r.tech_model, r.problem, r.status,
                    r.completion_date, r.repair_parts, c.fio AS client, c.phone, m.fio AS master
             FROM requests r
             JOIN users c ON c.user_id = r.client_id
             LEFT JOIN users m ON m.user_id = r.master_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY r.request_id'
        );
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="requests_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['№', 'Дата', 'Тип', 'Модель', 'Проблема', 'Статус', 'Завершено', 'Запчасти', 'Клиент', 'Телефон', 'Мастер'], ';', '"', '');
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['request_id'], format_date($row['start_date']), $row['tech_type'],
                $row['tech_model'], $row['problem'], status_label($row['status']),
                $row['completion_date'] ? format_date($row['completion_date']) : '',
                $row['repair_parts'] ?? '', $row['client'], $row['phone'], $row['master'] ?? '',
            ], ';', '"', '');
        }
        fclose($out);
        exit;
    }

    json_err('Маршрут не найден: ' . $path, 404);
} catch (Throwable $ex) {
    json_err('Внутренняя ошибка сервера: ' . $ex->getMessage(), 500);
}