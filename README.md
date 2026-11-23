# Учёт заявок на ремонт техники

Учебный проект: небольшой сайт для сервисного центра — заявки, статусы, мастера,
комментарии, уведомления и отчёт.

## Стек

- PHP 8.3 + MySQL 8 (PDO, подготовленные запросы)
- Apache 2.4 (Ubuntu Server 24.04)
- Vanilla JS + SSE для уведомлений
- Без внешних библиотек и CDN

## Адрес

http://192.168.0.15/

## Тестовые аккаунты

| Роль | Логин | Пароль |
|------|-------|--------|
| Менеджер | `login1` | `pass1` |
| Техник | `login2` | `pass2` |
| Оператор | `login4` | `pass4` |
| Заказчик | `login6` | `pass6` |

## Структура

```
index.php          редирект на вход/обзор
login.php          вход
dashboard.php      KPI, последние заявки, графики
requests.php       список с поиском и фильтром
request.php        карточка заявки, статус, мастер, комментарии
request_form.php   создание и редактирование
report.php         отчёт и печать (менеджер)
export.php         выгрузка CSV (менеджер)
actions.php        обработка POST-действий
sse.php            поток уведомлений (Server-Sent Events)
inc/               подключение к БД, авторизация, шапка/подвал
sql/schema.sql     таблицы, триггеры, хранимые процедуры
tools/import.php   импорт CSV в БД
data/*.csv         подготовленные исходные данные (UTF-8, `;`)
```

## Развёртывание

```bash
# 1. Схема БД
mysql -u bread -pbread < /var/www/html/sql/schema.sql

# 2. Импорт данных
cd /var/www/html
php tools/import.php

# 3. Права
sudo chown -R www-data:www-data /var/www/html
```

Файлы сайта лежат в Samba-папке `\\192.168.0.15\serv\site`
(на сервере — `/home/win/shared/site`).
