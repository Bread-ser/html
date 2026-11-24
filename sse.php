<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/api/jwt.php';

// Сначала проверяем JWT (для Vue SPA), и только потом старую PHP-сессию
$user = api_user_from_token() ?: current_user();
if (!$user) {
    http_response_code(401);
    exit('Unauthorized');
}
$userId = (int)$user['user_id'];
$role = $user['role'];

session_write_close();

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

ignore_user_abort(true);
set_time_limit(0);

$lastId = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last'] ?? 0);
if ($lastId <= 0) {
    $lastId = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
}

$getReqSig = static function (): string {
    $row = db()->query('SELECT COUNT(*), COALESCE(SUM(request_id), 0) FROM requests')->fetch(PDO::FETCH_NUM);
    return ($row[0] ?? '0') . ':' . ($row[1] ?? '0');
};
$reqSig = $getReqSig();

$notifCondition = $role === 'customer' ? 'user_id = ?' : '(user_id = ? OR user_id IS NULL)';
$notifParams = [$userId];

$started = time();
$lastBeat = time();

echo ": connected\n\n";
@flush();

while (!connection_aborted() && (time() - $started) < 25) {
    $stmt = db()->prepare(
        "SELECT * FROM notifications
         WHERE id > ? AND $notifCondition
         ORDER BY id LIMIT 20"
    );
    $stmt->execute(array_merge([$lastId], $notifParams));

    foreach ($stmt->fetchAll() as $note) {
        $lastId = (int)$note['id'];
        $payload = [
            'id'         => (int)$note['id'],
            'message'    => $note['message'],
            'request_id' => $note['request_id'] ? (int)$note['request_id'] : null,
            'created_at' => date('d.m H:i', strtotime($note['created_at'])),
        ];
        echo 'id: ' . $lastId . "\n";
        echo "event: notification\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    $newSig = $getReqSig();
    if ($newSig !== $reqSig) {
        $reqSig = $newSig;
        echo "event: requests_changed\n";
        echo "data: {}\n\n";
    }

    if (time() - $lastBeat >= 10) {
        echo ": ping\n\n";
        $lastBeat = time();
    }

    @flush();
    sleep(2);
}

