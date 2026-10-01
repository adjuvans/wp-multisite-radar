#!/usr/bin/env bash
# Crée la base de test dans le conteneur MariaDB de la VM et la donne à l'utilisateur « wordpress ».
set -euo pipefail
CONTAINER="${DB_CONTAINER:-wp-network-plugin-utilities-db}"
DB="${WP_TESTS_DB_NAME:-wordpress_test}"
docker exec -i "$CONTAINER" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB}\`;
GRANT ALL PRIVILEGES ON \`${DB}\`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
echo "Base ${DB} prête."
