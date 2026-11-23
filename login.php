<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Сессия устарела, попробуйте ещё раз.';
    } else {
        $login = input('login');
        $password = (string)($_POST['password'] ?? '');
        try {
            if ($login === '' || $password === '') {
                $error = 'Введите логин и пароль.';
            } elseif (login_user($login, $password)) {
                redirect('dashboard.php');
            } else {
                $error = 'Неверный логин или пароль.';
            }
        } catch (Throwable $ex) {
            $error = 'Нет связи с базой данных. Проверьте, выполнен ли импорт.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
<main class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">Р</span>
        <div>
            <h1>Ремонт<small>заявки</small></h1>
            <p>Учёт заявок на ремонт техники</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="stack">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <label class="field">
            <span>Логин</span>
            <input type="text" name="login" value="<?= e($_POST['login'] ?? '') ?>" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span>Пароль</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Войти</button>
    </form>

    <details class="demo">
        <summary>Тестовые аккаунты</summary>
        <table>
            <tr><td>Менеджер</td><td><code>login1 / pass1</code></td></tr>
            <tr><td>Техник</td><td><code>login2 / pass2</code></td></tr>
            <tr><td>Оператор</td><td><code>login4 / pass4</code></td></tr>
            <tr><td>Заказчик</td><td><code>login6 / pass6</code></td></tr>
        </table>
    </details>
</main>
</body>
</html>
