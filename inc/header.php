<?php
$user = current_user();
$unread = unread_notifications((int)$user['user_id']);
$notes = latest_notifications((int)$user['user_id']);
$active = $active ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? APP_NAME) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark">Р</span>
            <span>Ремонт<small>заявки</small></span>
        </a>

        <nav class="nav">
            <a href="dashboard.php" class="<?= $active === 'dashboard' ? 'is-active' : '' ?>">Обзор</a>
            <a href="requests.php" class="<?= $active === 'requests' ? 'is-active' : '' ?>">Заявки</a>
            <?php if (in_array($user['role'], ['manager', 'operator', 'customer'], true)): ?>
                <a href="request_form.php" class="<?= $active === 'new' ? 'is-active' : '' ?>">+ Заявка</a>
            <?php endif; ?>
            <?php if ($user['role'] === 'manager'): ?>
                <a href="report.php" class="<?= $active === 'report' ? 'is-active' : '' ?>">Отчёт</a>
            <?php endif; ?>
        </nav>

        <div class="topbar-right">
            <div class="notif" id="notif">
                <button type="button" class="icon-btn" id="notifBtn" aria-label="Уведомления">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                        <path d="M10 21h4"/>
                    </svg>
                    <span class="notif-badge<?= $unread ? '' : ' is-hidden' ?>" id="notifCount"><?= $unread ?></span>
                </button>
                <div class="notif-pop" id="notifPop" hidden>
                    <div class="notif-head">
                        <strong>Уведомления</strong>
                        <button type="button" class="link-btn" id="notifReadAll">прочитать</button>
                    </div>
                    <ul class="notif-list" id="notifList">
                        <?php if (!$notes): ?>
                            <li class="notif-empty">Пока пусто</li>
                        <?php endif; ?>
                        <?php foreach ($notes as $note): ?>
                            <li class="notif-item<?= $note['is_read'] ? '' : ' is-unread' ?>">
                                <?php if ($note['request_id']): ?>
                                    <a href="request.php?id=<?= (int)$note['request_id'] ?>"><?= e($note['message']) ?></a>
                                <?php else: ?>
                                    <span><?= e($note['message']) ?></span>
                                <?php endif; ?>
                                <time><?= e(date('d.m H:i', strtotime($note['created_at']))) ?></time>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr(short_name($user['fio']), 0, 1)) ?></span>
                <span class="user-meta">
                    <strong><?= e(short_name($user['fio'])) ?></strong>
                    <small><?= e(role_label($user['role'])) ?></small>
                </span>
            </div>
            <a class="icon-btn" href="logout.php" title="Выйти" aria-label="Выйти">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="M16 17l5-5-5-5M21 12H9"/>
                </svg>
            </a>
        </div>
    </div>
</header>

<main class="page">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
