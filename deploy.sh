#!/bin/bash
set -e

echo "=== 1. Копирование файлов и выдача прав ==="
rsync -av --delete --exclude='deploy.sh' /home/win/shared/site/ /var/www/html/
chown -R www-data:www-data /var/www/html

echo "=== 2. Развёртывание БД и импорт данных ==="
mysql -u bread -pbread < /var/www/html/sql/schema.sql
cd /var/www/html && php tools/import.php

echo "=== 3. Настройка Apache (mod_rewrite и .htaccess) ==="
a2enmod rewrite
cat <<'EOF' > /etc/apache2/conf-available/repair-api.conf
<Directory /var/www/html>
    AllowOverride All
</Directory>
EOF
a2enconf repair-api
systemctl restart apache2

echo "=== 4. Проверка API ==="
curl -s http://127.0.0.1/api/auth/me
echo ""
echo "=== Готово! ==="