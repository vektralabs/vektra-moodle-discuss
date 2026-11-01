#!/bin/bash
# Installs Moodle on first start (CLI), then runs Apache. Spike only.
set -e
until php -r "exit(@new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME')) ? 0 : 1);" 2>/dev/null; do
  echo "waiting for db"; sleep 2; done
if [ ! -f /var/www/html/config.php ]; then
  php admin/cli/install.php --wwwroot="$MOODLE_URL" --dataroot=/var/moodledata --dbtype=mariadb \
    --dbhost="$DB_HOST" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
    --fullname="OQ-025 spike $MOODLE_LABEL" --shortname="spike$MOODLE_LABEL" \
    --adminuser=admin --adminpass='Admin123!' --adminemail=admin@example.com \
    --non-interactive --agree-license
  chown www-data:www-data config.php
fi
exec "$@"
