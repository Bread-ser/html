<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

$user = current_user();
$action = (string)($_POST['action'] ?? '');
$requestId = (int)($_POST['request_id'] ?? 0);

if (!csrf_ok()) {
    http_response_code(400);
    exit('Сессия устарела. Обновите страницу и попробуйте снова.');
}

function load_request(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM requests WHERE request_id = ?');
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    if (!$request) {
        http_response_code(404);
        exit('Заявка не найдена');
    }
    return $request;
}

switch ($action) {
    case 'read_notifications':
        [$condition, $params] = notification_scope((int)$user['user_id']);
        $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND $condition");
        $stmt->execute($params);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;

    case 'add_comment':
        $request = load_request($requestId);
        if (!can_view_request($request)) {
            http_response_code(403);
            exit('Нет доступа');
        }
        $message = input('message');
        if ($message === '') {
            flash('error', 'Комментарий пустой.');
        } else {
            $stmt = db()->prepare('INSERT INTO comments (message, master_id, request_id) VALUES (?, ?, ?)');
            $stmt->execute([$message, $user['user_id'], $requestId]);
            flash('ok', 'Комментарий добавлен.');
        }
        redirect('request.php?id=' . $requestId);

    case 'update_status':
        $request = load_request($requestId);
        if (!can_change_status($request)) {
            http_response_code(403);
            exit('Нет доступа');
        }
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, statuses(), true)) {
            flash('error', 'Неизвестный статус.');
            redirect('request.php?id=' . $requestId);
        }
        $parts = array_key_exists('repair_parts', $_POST) ? input('repair_parts') : $request['repair_parts'];
        $completion = $status === 'ready'
            ? ($request['completion_date'] ?: date('Y-m-d'))
            : null;

        $stmt = db()->prepare(
            'UPDATE requests SET status = ?, completion_date = ?, repair_parts = ? WHERE request_id = ?'
        );
        $stmt->execute([$status, $completion, $parts ?: null, $requestId]);
        flash('ok', 'Статус изменён: ' . status_label($status) . '.');
        redirect('request.php?id=' . $requestId);

    case 'assign_master':
        if (!in_array($user['role'], ['manager', 'operator'], true)) {
            http_response_code(403);
            exit('Нет доступа');
        }
        load_request($requestId);
        $masterId = (int)($_POST['master_id'] ?? 0);
        if ($masterId > 0) {
            $check = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'technician'");
            $check->execute([$masterId]);
            if (!$check->fetchColumn()) {
                flash('error', 'Выбранный пользователь не является техником.');
                redirect('request.php?id=' . $requestId);
            }
        }
        $stmt = db()->prepare('UPDATE requests SET master_id = ? WHERE request_id = ?');
        $stmt->execute([$masterId ?: null, $requestId]);
        if ($masterId) {
            $note = db()->prepare('INSERT INTO notifications (user_id, request_id, message) VALUES (?, ?, ?)');
            $note->execute([$masterId, $requestId, 'Вам назначена заявка №' . $requestId]);
        }
        flash('ok', $masterId ? 'Мастер назначен.' : 'Назначение снято.');
        redirect('request.php?id=' . $requestId);

    case 'delete_request':
        if ($user['role'] !== 'manager') {
            http_response_code(403);
            exit('Удалять заявки может только менеджер');
        }
        load_request($requestId);
        $stmt = db()->prepare('DELETE FROM requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        flash('ok', 'Заявка №' . $requestId . ' удалена.');
        redirect('requests.php');

    default:
        http_response_code(400);
        exit('Неизвестное действие');
}
