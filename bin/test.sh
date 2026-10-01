#!/usr/bin/env bash
# Lance PHPUnit ; lit le mot de passe de la base dans le wp-config de la VM si besoin.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -z "${WP_TESTS_DB_PASSWORD:-}" ]; then
	WP_TESTS_DB_PASSWORD="$(wp config get DB_PASSWORD --path="${WP_CORE_DIR:-/home/dev/wp}")"
	export WP_TESTS_DB_PASSWORD
fi
exec vendor/bin/phpunit "$@"
