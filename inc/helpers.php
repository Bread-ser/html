<?php
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return isset($_POST['_token'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
}

function status_label(string $status): string
{
    return [
        'new'           => 'Новая',
        'in_progress'   => 'В ремонте',
        'waiting_parts' => 'Ждём запчасти',
        'ready'         => 'Готова к выдаче',
    ][$status] ?? $status;
}

function status_class(string $status): string
{
    return 'status status-' . preg_replace('/[^a-z_]/', '', $status);
}

function role_label(string $role): string
{
    return [
        'manager'    => 'Менеджер',
        'technician' => 'Техник',
        'operator'   => 'Оператор',
        'customer'   => 'Заказчик',
    ][$role] ?? $role;
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '—';
}

function short_name(string $fio): string
{
    $parts = preg_split('/\s+/u', trim($fio));
    return $parts[1] ?? $parts[0] ?? $fio;
}

function is_staff(): bool
{
    return in_array(current_user()['role'] ?? '', ['manager', 'operator', 'technician'], true);
}

function input(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function statuses(): array
{
    return ['new', 'in_progress', 'waiting_parts', 'ready'];
}
