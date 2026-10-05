#!/usr/bin/env bash
# Banc de performance de Multisite Radar (spec §11.3), sur un multisite jetable installé avec WP-CLI :
# crée BENCH_SITES sites, les analyse (wp multisite-radar scan --all), puis mesure les routes de lecture de l'interface
# et un lot d'analyse. Critère de la spec §1.4 n° 2 : GET /sites en moins de BENCH_MAX_MS au p95.
# Les routes sont mesurées dans le processus, sans le chargement de WordPress (écart E1 du plan M7).
#
# Requis : BENCH_DB_NAME, BENCH_DB_USER, BENCH_DB_PASSWORD, BENCH_DB_HOST (base existante, sans tables « msrbench_* »).
# Facultatifs : BENCH_SITES (1000), BENCH_RUNS (20), BENCH_MAX_MS (300), BENCH_REPORT (copie Markdown du rapport),
# WP_CLI (« wp » par défaut), BENCH_WP_VERSION (« latest » par défaut).
# shellcheck disable=SC2016 # Le code PHP est entre apostrophes : aucune expansion shell n'y est voulue.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
: "${BENCH_DB_NAME:?BENCH_DB_NAME is required}"
: "${BENCH_DB_USER:?BENCH_DB_USER is required}"
: "${BENCH_DB_PASSWORD:?BENCH_DB_PASSWORD is required}"
: "${BENCH_DB_HOST:?BENCH_DB_HOST is required}"
SITES="${BENCH_SITES:-1000}"
RUNS="${BENCH_RUNS:-20}"
MAX_MS="${BENCH_MAX_MS:-300}"
WP_CLI="${WP_CLI:-wp}"
URL="http://msradar-bench.test"
WORK="$(mktemp -d)"
WP_DIR="$WORK/wordpress"
REPORT="$WORK/report.md"
OWNS_TABLES=0

wpb() {
	# shellcheck disable=SC2086 # WP_CLI peut valoir « php /chemin/wp-cli.phar ».
	$WP_CLI --path="$WP_DIR" "$@"
}

fail() {
	echo "BENCH FAILED: $*" >&2
	exit 1
}

now() {
	php -r 'echo microtime( true );'
}

# seconds <début> <fin> : durée en secondes, une décimale.
seconds() {
	php -r 'printf( "%.1f", $argv[2] - $argv[1] );' -- "$1" "$2"
}

case "$SITES" in
	'' | *[!0-9]* | 0) fail "BENCH_SITES must be a positive integer." ;;
esac

cleanup() {
	if [ "$OWNS_TABLES" = 1 ]; then
		wpb --skip-plugins --skip-themes eval 'global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->base_prefix ) . "%" ) ) as $table ) { $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) ); }' >/dev/null 2>&1 || true
	fi
	rm -rf "$WORK"
}
trap cleanup EXIT

[ -f "$ROOT/build/admin/sites.js" ] || fail "build/ is missing: run npm run build first."

echo "==> WordPress ${BENCH_WP_VERSION:-latest} (subdirectory multisite) in $WP_DIR"
wpb core download --version="${BENCH_WP_VERSION:-latest}" --quiet
wpb config create --dbname="$BENCH_DB_NAME" --dbuser="$BENCH_DB_USER" --dbpass="$BENCH_DB_PASSWORD" --dbhost="$BENCH_DB_HOST" --dbprefix=msrbench_ --skip-check --quiet
# Compte les tables « msrbench_* » avec mysqli (le client mysql n'est pas requis, et « wp core is-installed » ne voit pas une base à moitié remplie).
existing="$(php -r '
	$host = explode( ":", getenv( "BENCH_DB_HOST" ) . ":3306" );
	mysqli_report( MYSQLI_REPORT_OFF );
	$db = new mysqli( $host[0], getenv( "BENCH_DB_USER" ), getenv( "BENCH_DB_PASSWORD" ), getenv( "BENCH_DB_NAME" ), (int) $host[1] );
	$result = $db->connect_errno ? false : $db->query( "SHOW TABLES LIKE \"msrbench\\\\_%\"" );
	echo $result ? $result->num_rows : 1;
')"
if [ "$existing" != 0 ]; then
	fail "database $BENCH_DB_NAME already holds msrbench_* tables; drop them first."
fi
OWNS_TABLES=1
wpb core multisite-install --url="$URL" --title="Multisite Radar bench" --admin_user=admin --admin_password="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" --admin_email=admin@example.org --skip-email --quiet

echo "==> Multisite Radar (distributable files)"
mkdir -p "$WP_DIR/wp-content/plugins/multisite-radar"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$WP_DIR/wp-content/plugins/multisite-radar/"
wpb plugin activate multisite-radar --network

echo "==> Creating $SITES sites"
start="$(now)"
BENCH_SITES="$SITES" wpb eval-file "$ROOT/bin/bench/create-sites.php"
created="$(seconds "$start" "$(now)")"

echo "==> wp multisite-radar scan --all"
start="$(now)"
wpb multisite-radar scan --all >/dev/null
scanned="$(seconds "$start" "$(now)")"

{
	echo "- Sites: $SITES plus the main site, created in ${created} s."
	echo "- Full scan (wp multisite-radar scan --all): ${scanned} s, $(php -r 'printf( "%.1f", $argv[1] / max( 0.1, (float) $argv[2] ) );' -- "$SITES" "$scanned") sites/s."
	echo "- WordPress $(wpb core version), PHP $(php -r 'echo PHP_VERSION;'), database $(wpb eval 'global $wpdb; echo $wpdb->db_server_info();')."
	echo "- Machine: $(uname -m), $(nproc 2>/dev/null || echo '?') CPU."
	echo
} >"$REPORT"

echo "==> Routes ($RUNS runs each)"
status=0
BENCH_RUNS="$RUNS" BENCH_MAX_MS="$MAX_MS" wpb --user=admin eval-file "$ROOT/bin/bench/routes.php" >>"$REPORT" || status=$?
cat "$REPORT"
if [ -n "${BENCH_REPORT:-}" ]; then
	cp "$REPORT" "$BENCH_REPORT"
fi
[ "$status" = 0 ] || fail "GET /sites is slower than ${MAX_MS} ms (p95), the batch took more than 20 s, or a route failed."
echo "BENCH OK"
