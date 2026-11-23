<?php
// Настройки приложения и подключения к БД
const DB_HOST = '127.0.0.1';
const DB_NAME = 'repair_db';
const DB_USER = 'bread';
const DB_PASS = 'bread';

const APP_NAME = 'Учёт заявок на ремонт';

// Настройки JWT для REST API (Web SPA, Desktop, Android)
const JWT_SECRET = 'repair_up04_super_secret_key_2026';
const JWT_TTL    = 86400; // 24 часа

date_default_timezone_set('Asia/Novokuznetsk');