<?php
function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function login_user(string $login, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

/**
 * Ограничение выборки заявок по роли.
 * Возвращает [sql-условие, параметры].
 */
function request_scope(string $alias = 'r'): array
{
    $user = current_user();
    return match ($user['role'] ?? '') {
        'customer'   => ["$alias.client_id = ?", [$user['user_id']]],
        'technician' => ["$alias.master_id = ?", [$user['user_id']]],
        default      => ['1 = 1', []],
    };
}

function can_view_request(array $request): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    return match ($user['role']) {
        'customer'   => (int)$request['client_id'] === (int)$user['user_id'],
        'technician' => (int)$request['master_id'] === (int)$user['user_id'],
        default      => true,
    };
}

function can_edit_request(array $request): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (in_array($user['role'], ['manager', 'operator'], true)) {
        return true;
    }
    if ($user['role'] === 'customer') {
        return (int)$request['client_id'] === (int)$user['user_id'] && $request['status'] === 'new';
    }
    return false;
}

function can_change_status(array $request): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['role'], ['manager', 'operator'], true)
        || ($user['role'] === 'technician' && (int)$request['master_id'] === (int)$user['user_id']);
}

function notification_scope(int $userId): array
{
    $role = current_user()['role'] ?? '';
    if ($role === 'customer') {
        return ['user_id = ?', [$userId]];
    }
    return ['(user_id = ? OR user_id IS NULL)', [$userId]];
}

function unread_notifications(int $userId): int
{
    [$condition, $params] = notification_scope($userId);
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND $condition");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function latest_notifications(int $userId, int $limit = 8): array
{
    [$condition, $params] = notification_scope($userId);
    $stmt = db()->prepare(
        "SELECT * FROM notifications
         WHERE $condition
         ORDER BY id DESC LIMIT " . (int)$limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}
