#!/usr/bin/env bash
# Test d'acceptation de la spec §1.4 n° 1, sur un multisite neuf installé avec WP-CLI :
# un CPT enregistré par un plugin actif uniquement sur /rh/ apparaît sur /rh/ avec son libellé
# et son origine plugin, et n'apparaît pas sur le site principal.
#
# Requis : E2E_DB_NAME, E2E_DB_USER, E2E_DB_PASSWORD, E2E_DB_HOST (base existante, sans tables « msre2e_* »).
# Facultatifs : WP_CLI (commande WP-CLI, « wp » par défaut), E2E_WP_VERSION (« latest » par défaut).
# shellcheck disable=SC2016 # Le code PHP est entre apostrophes : aucune expansion shell n'y est voulue.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
: "${E2E_DB_NAME:?E2E_DB_NAME is required}"
: "${E2E_DB_USER:?E2E_DB_USER is required}"
: "${E2E_DB_PASSWORD:?E2E_DB_PASSWORD is required}"
: "${E2E_DB_HOST:?E2E_DB_HOST is required}"
WP_CLI="${WP_CLI:-wp}"
URL="http://msradar-e2e.test"
WORK="$(mktemp -d)"
WP_DIR="$WORK/wordpress"
OWNS_TABLES=0

wpe() {
	# shellcheck disable=SC2086 # WP_CLI peut valoir « php /chemin/wp-cli.phar ».
	$WP_CLI --path="$WP_DIR" "$@"
}

fail() {
	echo "E2E FAILED: $*" >&2
	exit 1
}

cleanup() {
	if [ "$OWNS_TABLES" = 1 ]; then
		wpe --skip-plugins --skip-themes eval 'global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->base_prefix ) . "%" ) ) as $table ) { $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) ); }' >/dev/null 2>&1 || true
	fi
	rm -rf "$WORK"
}
trap cleanup EXIT

echo "==> WordPress ${E2E_WP_VERSION:-latest} (subdirectory multisite) in $WP_DIR"
wpe core download --version="${E2E_WP_VERSION:-latest}" --quiet
wpe config create --dbname="$E2E_DB_NAME" --dbuser="$E2E_DB_USER" --dbpass="$E2E_DB_PASSWORD" --dbhost="$E2E_DB_HOST" --dbprefix=msre2e_ --skip-check --quiet
if wpe core is-installed >/dev/null 2>&1; then
	fail "database $E2E_DB_NAME already holds msre2e_* tables; drop them first."
fi
OWNS_TABLES=1
wpe core multisite-install --url="$URL" --title="Multisite Radar E2E" --admin_user=admin --admin_password="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" --admin_email=admin@example.org --skip-email --quiet
echo "WordPress $(wpe core version)"

echo "==> Plugins: multisite-radar (distributable files) and the demo CPT fixture"
mkdir -p "$WP_DIR/wp-content/plugins/multisite-radar"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$WP_DIR/wp-content/plugins/multisite-radar/"
cp -R "$ROOT/tests/e2e/fixtures/msradar-demo-cpt" "$WP_DIR/wp-content/plugins/"

RH_ID="$(wpe site create --slug=rh --title=RH --porcelain)"
MAIN_ID="$(wpe eval 'echo get_main_site_id();')"
wpe plugin activate msradar-demo-cpt --url="$URL/rh/"
wpe plugin activate multisite-radar --network

echo "==> wp multisite-radar scan --all --probe"
wpe multisite-radar scan --all --probe

echo "==> GET /multisite-radar/v1/sites/{id} for /rh/ (site $RH_ID) and the main site (site $MAIN_ID)"
site_json() {
	MSRADAR_E2E_SITE="$1" wpe --user=admin eval '$response = rest_do_request( new WP_REST_Request( "GET", "/multisite-radar/v1/sites/" . (int) getenv( "MSRADAR_E2E_SITE" ) ) ); echo wp_json_encode( [ "status" => $response->get_status(), "data" => $response->get_data() ] );'
}
RH_JSON="$(site_json "$RH_ID")"
MAIN_JSON="$(site_json "$MAIN_ID")"

php -r '
$check = static function ( string $label, string $json ): array {
	$response = json_decode( $json, true );
	if ( ! is_array( $response ) || 200 !== ( $response["status"] ?? null ) || ! is_array( $response["data"]["post_types"] ?? null ) ) {
		fwrite( STDERR, "E2E FAILED: unexpected REST response for $label: $json\n" );
		exit( 1 );
	}
	foreach ( $response["data"]["post_types"] as $type ) {
		if ( "demo_event" === ( $type["name"] ?? null ) ) {
			return $type;
		}
	}
	return [];
};
$rh   = $check( "/rh/", $argv[1] );
$main = $check( "the main site", $argv[2] );
$errors = [];
if ( [] === $rh ) {
	$errors[] = "demo_event is missing on /rh/.";
} else {
	if ( "Demo events" !== ( $rh["label"] ?? null ) ) {
		$errors[] = "demo_event label on /rh/ is " . var_export( $rh["label"] ?? null, true ) . ", expected \"Demo events\".";
	}
	if ( [ "kind" => "plugin", "slug" => "msradar-demo-cpt" ] !== ( $rh["origin"] ?? null ) ) {
		$errors[] = "demo_event origin on /rh/ is " . json_encode( $rh["origin"] ?? null ) . ", expected {\"kind\":\"plugin\",\"slug\":\"msradar-demo-cpt\"}.";
	}
	if ( true !== ( $rh["verified"] ?? null ) ) {
		$errors[] = "demo_event on /rh/ is not verified (registry not fresh).";
	}
}
if ( [] !== $main ) {
	$errors[] = "demo_event appears on the main site: " . json_encode( $main );
}
if ( [] !== $errors ) {
	fwrite( STDERR, "E2E FAILED:\n- " . implode( "\n- ", $errors ) . "\n" );
	exit( 1 );
}
echo "/rh/: " . json_encode( $rh ) . "\n";
echo "main site: no demo_event\n";
' -- "$RH_JSON" "$MAIN_JSON"

echo "E2E OK: the demo CPT is reported on /rh/ only, with its label and plugin origin."
