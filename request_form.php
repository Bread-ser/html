<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);
$request = null;

if ($id) {
    $stmt = db()->prepare('SELECT * FROM requests WHERE request_id = ?');
    $stmt->execute([$id]);
    $request = $stmt->fetch() ?: null;
    if (!$request) {
        http_response_code(404);
        exit('Заявка не найдена');
    }
    if (!can_edit_request($request)) {
        http_response_code(403);
        exit('Нет доступа к редактированию этой заявки');
    }
} elseif (!in_array($user['role'], ['manager', 'operator', 'customer'], true)) {
    http_response_code(403);
    exit('Техник не создаёт заявки');
}

$techTypes = ['Компьютер', 'Ноутбук', 'Монитор', 'Клавиатура', 'Мышка', 'Принтер', 'Другое'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Сессия устарела, попробуйте ещё раз.';
    }

    $techType = input('tech_type');
    $techModel = input('tech_model');
    $problem = input('problem');
    $startDate = input('start_date');
    $repairParts = input('repair_parts');

    if ($techType === '') {
        $errors[] = 'Выберите тип техники.';
    }
    if ($techModel === '') {
        $errors[] = 'Укажите модель.';
    }
    if ($problem === '') {
        $errors[] = 'Опишите проблему.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = date('Y-m-d');
    }

    if (!$errors) {
        if ($request) {
            $repairParts = array_key_exists('repair_parts', $_POST)
                ? input('repair_parts')
                : $request['repair_parts'];
            $stmt = db()->prepare(
                'UPDATE requests SET tech_type = ?, tech_model = ?, problem = ?, start_date = ?, repair_parts = ?
                 WHERE request_id = ?'
            );
            $stmt->execute([$techType, $techModel, $problem, $startDate, $repairParts ?: null, $request['request_id']]);
            flash('ok', 'Заявка обновлена.');
            redirect('request.php?id=' . $request['request_id']);
        }

        $clientId = $user['role'] === 'customer'
            ? (int)$user['user_id']
            : (int)($_POST['client_id'] ?? 0);
        $clientCheck = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'customer'");
        $clientCheck->execute([$clientId]);
        if (!$clientCheck->fetchColumn()) {
            $errors[] = 'Выберите заказчика.';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO requests (start_date, tech_type, tech_model, problem, status, client_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$startDate, $techType, $techModel, $problem, 'new', $clientId]);
            $newId = (int)db()->lastInsertId();
            flash('ok', 'Заявка №' . $newId . ' создана.');
            redirect('request.php?id=' . $newId);
        }
    }
}

$customers = [];
if ($user['role'] !== 'customer') {
    $customers = db()->query("SELECT user_id, fio, phone FROM users WHERE role = 'customer' ORDER BY fio")->fetchAll();
}

$values = [
    'tech_type'    => $_POST['tech_type'] ?? $request['tech_type'] ?? '',
    'tech_model'   => $_POST['tech_model'] ?? $request['tech_model'] ?? '',
    'problem'      => $_POST['problem'] ?? $request['problem'] ?? '',
    'start_date'   => $_POST['start_date'] ?? $request['start_date'] ?? date('Y-m-d'),
    'repair_parts' => $_POST['repair_parts'] ?? $request['repair_parts'] ?? '',
];

$title = $request ? 'Заявка №' . $request['request_id'] : 'Новая заявка';
$active = $request ? 'requests' : 'new';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
    <div>
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= $request ? 'Измените нужные поля и сохраните.' : 'Опишите технику и проблему — остальное сделают сотрудники.' ?></p>
    </div>
    <?php if ($request): ?>
        <a class="btn btn-ghost" href="request.php?id=<?= (int)$request['request_id'] ?>">← К заявке</a>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="card form-grid">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

    <?php if (!$request && $user['role'] !== 'customer'): ?>
        <label class="field">
            <span>Заказчик *</span>
            <select name="client_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['user_id'] ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$customer['user_id'] ? 'selected' : '' ?>>
                        <?= e($customer['fio']) ?> · <?= e($customer['phone']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php elseif ($request): ?>
        <?php
        $clientStmt = db()->prepare('SELECT fio, phone FROM users WHERE user_id = ?');
        $clientStmt->execute([$request['client_id']]);
        $client = $clientStmt->fetch();
        ?>
        <div class="field">
            <span>Заказчик</span>
            <div class="readonly"><?= e($client['fio'] ?? '—') ?> · <?= e($client['phone'] ?? '') ?></div>
        </div>
    <?php endif; ?>

    <label class="field">
        <span>Тип техники *</span>
        <select name="tech_type" required>
            <?php foreach ($techTypes as $type): ?>
                <option value="<?= e($type) ?>" <?= $values['tech_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
            <?php if ($values['tech_type'] !== '' && !in_array($values['tech_type'], $techTypes, true)): ?>
                <option value="<?= e($values['tech_type']) ?>" selected><?= e($values['tech_type']) ?></option>
            <?php endif; ?>
        </select>
    </label>

    <label class="field">
        <span>Модель *</span>
        <input type="text" name="tech_model" value="<?= e($values['tech_model']) ?>" placeholder="например, ASUS VivoBook 15" required>
    </label>

    <label class="field">
        <span>Дата обращения</span>
        <input type="date" name="start_date" value="<?= e($values['start_date']) ?>" required>
    </label>

    <?php if ($request && in_array($user['role'], ['manager', 'operator', 'technician'], true)): ?>
        <label class="field">
            <span>Использованные запчасти</span>
            <input type="text" name="repair_parts" value="<?= e($values['repair_parts']) ?>" placeholder="необязательно">
        </label>
    <?php endif; ?>

    <label class="field field-full">
        <span>Описание проблемы *</span>
        <textarea name="problem" rows="5" placeholder="Что случилось с устройством?" required><?= e($values['problem']) ?></textarea>
    </label>

    <div class="form-actions field-full">
        <button class="btn btn-primary" type="submit"><?= $request ? 'Сохранить' : 'Создать заявку' ?></button>
        <a class="btn btn-ghost" href="<?= $request ? 'request.php?id=' . (int)$request['request_id'] : 'requests.php' ?>">Отмена</a>
    </div>
</form>
<?php require __DIR__ . '/inc/footer.php'; ?>
