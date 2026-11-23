<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT r.*, c.fio AS client_fio, c.phone AS client_phone, m.fio AS master_fio
     FROM requests r
     JOIN users c ON c.user_id = r.client_id
     LEFT JOIN users m ON m.user_id = r.master_id
     WHERE r.request_id = ?'
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    http_response_code(404);
    exit('Заявка не найдена');
}
if (!can_view_request($request)) {
    http_response_code(403);
    exit('Нет доступа к этой заявке');
}

$stmt = db()->prepare(
    'SELECT cm.*, u.fio, u.role
     FROM comments cm
     JOIN users u ON u.user_id = cm.master_id
     WHERE cm.request_id = ?
     ORDER BY cm.comment_id'
);
$stmt->execute([$id]);
$comments = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM request_events WHERE request_id = ? ORDER BY id DESC');
$stmt->execute([$id]);
$events = $stmt->fetchAll();

$technicians = [];
if (in_array($user['role'], ['manager', 'operator'], true)) {
    $technicians = db()->query("SELECT user_id, fio FROM users WHERE role = 'technician' ORDER BY fio")->fetchAll();
}

$title = 'Заявка №' . $request['request_id'];
$active = 'requests';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
    <div>
        <div class="title-line">
            <h1>Заявка №<?= (int)$request['request_id'] ?></h1>
            <span class="<?= e(status_class($request['status'])) ?>"><?= e(status_label($request['status'])) ?></span>
        </div>
        <p class="muted">Создана <?= e(format_date($request['start_date'])) ?><?= $request['completion_date'] ? ' · закрыта ' . e(format_date($request['completion_date'])) : '' ?></p>
    </div>
    <div class="head-actions">
        <?php if (can_edit_request($request)): ?>
            <a class="btn btn-ghost" href="request_form.php?id=<?= (int)$request['request_id'] ?>">Редактировать</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'manager'): ?>
            <form method="post" action="actions.php" onsubmit="return confirm('Удалить заявку №<?= (int)$request['request_id'] ?>?')">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_request">
                <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                <button class="btn btn-danger" type="submit">Удалить</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Устройство и проблема</h2></div>
        <dl class="info">
            <div><dt>Тип</dt><dd><?= e($request['tech_type']) ?></dd></div>
            <div><dt>Модель</dt><dd><?= e($request['tech_model']) ?></dd></div>
            <div><dt>Заказчик</dt><dd><?= e($request['client_fio']) ?><small class="muted block"><?= e($request['client_phone']) ?></small></dd></div>
            <div><dt>Мастер</dt><dd><?= e($request['master_fio'] ?: 'не назначен') ?></dd></div>
            <?php if ($request['repair_parts']): ?>
                <div><dt>Запчасти</dt><dd><?= e($request['repair_parts']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <div class="problem">
            <h3>Описание</h3>
            <p><?= nl2br(e($request['problem'])) ?></p>
        </div>
    </section>

    <div class="stack">
        <?php if (can_change_status($request)): ?>
            <section class="card">
                <div class="card-head"><h2>Статус</h2></div>
                <form method="post" action="actions.php" class="stack">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                    <label class="field">
                        <span>Новый статус</span>
                        <select name="status">
                            <?php foreach (statuses() as $s): ?>
                                <option value="<?= e($s) ?>" <?= $request['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if (in_array($user['role'], ['manager', 'operator', 'technician'], true)): ?>
                        <label class="field">
                            <span>Запчасти</span>
                            <input type="text" name="repair_parts" value="<?= e($request['repair_parts']) ?>" placeholder="что заказали или заменили">
                        </label>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Сохранить</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if (in_array($user['role'], ['manager', 'operator'], true)): ?>
            <section class="card">
                <div class="card-head"><h2>Мастер</h2></div>
                <form method="post" action="actions.php" class="stack">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="assign_master">
                    <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                    <label class="field">
                        <span>Назначить</span>
                        <select name="master_id">
                            <option value="">— снять назначение —</option>
                            <?php foreach ($technicians as $tech): ?>
                                <option value="<?= (int)$tech['user_id'] ?>" <?= (int)$request['master_id'] === (int)$tech['user_id'] ? 'selected' : '' ?>>
                                    <?= e($tech['fio']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn" type="submit">Назначить</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($events): ?>
            <section class="card">
                <div class="card-head"><h2>История</h2></div>
                <ul class="timeline">
                    <?php foreach ($events as $event): ?>
                        <li>
                            <span><?= e(status_label($event['old_status'])) ?> → <?= e(status_label($event['new_status'])) ?></span>
                            <time><?= e(date('d.m.Y H:i', strtotime($event['created_at']))) ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>Комментарии</h2><span class="muted"><?= count($comments) ?></span></div>

    <?php if (!$comments): ?>
        <p class="muted">Комментариев пока нет.</p>
    <?php else: ?>
        <ul class="comments">
            <?php foreach ($comments as $comment): ?>
                <li>
                    <div class="comment-head">
                        <strong><?= e($comment['fio']) ?></strong>
                        <span class="muted"><?= e(role_label($comment['role'])) ?> · <?= e(date('d.m.Y H:i', strtotime($comment['created_at']))) ?></span>
                    </div>
                    <p><?= nl2br(e($comment['message'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="actions.php" class="comment-form">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
        <label class="field field-grow">
            <span>Новый комментарий</span>
            <input type="text" name="message" placeholder="Напишите что-нибудь по заявке…" required>
        </label>
        <button class="btn btn-primary" type="submit">Отправить</button>
    </form>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
