#!/usr/bin/env bash
# Test d'acceptation de la spec §1.4 n° 1, sur un multisite neuf installé avec WP-CLI :
# un CPT enregistré par un plugin actif uniquement sur /rh/ apparaît sur /rh/ avec son libellé
# et son origine plugin, et n'apparaît pas sur le site principal.
# Puis les commandes WP-CLI de Multisite Radar, sur ce même réseau.
#
# Requis : E2E_DB_NAME, E2E_DB_USER, E2E_DB_PASSWORD, E2E_DB_HOST (base existante, sans tables « msre2e_* ») ; jq.
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

# expect <description> <valeur obtenue> <valeur attendue>
expect() {
	if [ "$2" != "$3" ]; then
		fail "$1: got '$2', expected '$3'."
	fi
	echo "ok - $1"
}

command -v jq >/dev/null 2>&1 || fail "jq is required."

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

echo "ok - the demo CPT is reported on /rh/ only, with its label and plugin origin."

echo "==> WP-CLI commands"
# Un site masqué aux moteurs de recherche : il déclenche l'alerte « search_hidden ».
HIDDEN_ID="$(wpe site create --slug=discret --title=Discret --porcelain)"
wpe option update blog_public 0 --url="$URL/discret/" >/dev/null
wpe multisite-radar scan --all >/dev/null

expect "sites list counts every site" "$(wpe multisite-radar sites list --format=count)" "3"
expect "alerts --rule=search_hidden lists the hidden site" \
	"$(wpe multisite-radar alerts --rule=search_hidden --format=json | jq -r 'map(.site_id | tostring) | join(",")')" "$HIDDEN_ID"
expect "alerts --severity=info includes the hidden site" \
	"$(wpe multisite-radar alerts --severity=info --format=json | jq -r --arg id "$HIDDEN_ID" 'map(select((.site_id | tostring) == $id and .rule == "search_hidden")) | length')" "1"
if wpe multisite-radar alerts --rule=acme_missing >/dev/null 2>&1; then
	fail "alerts --rule=<unknown rule> should fail."
fi

PLUGINS="$(wpe multisite-radar plugins list --format=json)"
expect "plugins list: the demo plugin is used by one site" \
	"$(jq -r '.[] | select(.file == "msradar-demo-cpt/msradar-demo-cpt.php") | "\(.status) \(.sites_count)"' <<<"$PLUGINS")" "local 1"
expect "plugins list: Multisite Radar is network-activated" \
	"$(jq -r '.[] | select(.file == "multisite-radar/multisite-radar.php") | .status' <<<"$PLUGINS")" "network"
expect "plugins list --unused lists only unused plugins" \
	"$(wpe multisite-radar plugins list --unused --format=json | jq -r 'all(.[]; .status == "unused") and (map(.file) | index("msradar-demo-cpt/msradar-demo-cpt.php") == null)')" "true"
ACTIVE_THEME="$(wpe option get stylesheet)"
THEMES="$(wpe multisite-radar themes list --format=json)"
expect "themes list: the active theme is used by every site" \
	"$(jq -r --arg theme "$ACTIVE_THEME" '.[] | select(.stylesheet == $theme) | "\(.status) \(.active_count)"' <<<"$THEMES")" "used 3"
expect "themes list --unused never lists the active theme" \
	"$(wpe multisite-radar themes list --unused --format=json | jq -r --arg theme "$ACTIVE_THEME" 'all(.[]; .status == "unused") and (map(.stylesheet) | index($theme) == null)')" "true"

EXPORT="$WORK/sites.json"
wpe multisite-radar export --resource=sites --format=json --output="$EXPORT" >/dev/null
expect "export --output writes the sites as JSON" "$(jq -r '"\(.meta.resource) \(.items | length)"' "$EXPORT")" "sites 3"
expect "export applies the filters of the REST route" \
	"$(wpe multisite-radar export --resource=sites --format=json --alert_level=info | jq -r '.items | map(.id | tostring) | join(",")')" "$HIDDEN_ID"
CSV="$(wpe multisite-radar export --resource=plugins --fields=file,status)"
expect "export without --output writes CSV on the standard output" \
	"$(grep -c '^msradar-demo-cpt/msradar-demo-cpt.php,local' <<<"$CSV")" "1"
expect "export accepts any filter of the REST route" \
	"$(wpe multisite-radar export --resource=sites --format=json --theme="$ACTIVE_THEME" | jq '.items | length')" "3"
if wpe multisite-radar export --resource=sites --output="$WORK/missing/sites.csv" >/dev/null 2>&1; then
	fail "export to a missing folder should fail."
fi
[ ! -e "$WORK/missing" ] || fail "export to a missing folder created it."

expect "settings get reads a default" "$(wpe multisite-radar settings get scan.full_rescan_days)" "7"
expect "settings get prints JSON" "$(wpe multisite-radar settings get scan.activity_post_types | jq -c .)" '["post","page"]'
wpe multisite-radar settings set scan.full_rescan_days 14 >/dev/null
expect "settings set changes a setting" "$(wpe multisite-radar settings get scan.full_rescan_days)" "14"
if wpe multisite-radar settings set scan.full_rescan_days 0 >/dev/null 2>&1; then
	fail "settings set accepted 0 days."
fi
if wpe multisite-radar settings set scan.unknown_key 1 >/dev/null 2>&1; then
	fail "settings set accepted an unknown key."
fi
if wpe multisite-radar settings set alerts.rules.acme_missing '{"enabled":false}' >/dev/null 2>&1; then
	fail "settings set accepted an unknown alert rule."
fi
if wpe multisite-radar settings get scan.unknown_key >/dev/null 2>&1; then
	fail "settings get accepted an unknown key."
fi
expect "a refused change leaves the setting as it was" "$(wpe multisite-radar settings get scan.full_rescan_days)" "14"
wpe multisite-radar settings set alerts.rules.search_hidden '{"enabled":false}' >/dev/null
expect "a rule switched off from the command line lists no alert" "$(wpe multisite-radar alerts --rule=search_hidden --format=count)" "0"

echo "E2E OK"
