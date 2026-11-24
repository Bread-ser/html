#!/bin/bash
set -e

cd /var/www/html
git add .

MSG="${1:-update}"
git commit -m "$MSG"
git push

echo "Изменения отправлены на GitHub!"