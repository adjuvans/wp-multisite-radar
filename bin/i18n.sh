#!/usr/bin/env bash
# Régénère languages/multisite-radar.pot, fusionne les .po, vérifie qu'aucune chaîne n'est sans traduction,
# puis compile les fichiers chargés par WordPress (.mo, .l10n.php, .json). Requiert WP-CLI et un build à jour.
#
# Le JS est lu dans build/ : les traductions JS sont associées au fichier compilé que WordPress charge, et
# DataViews, embarqué dans les bundles, y apporte ses propres textes (domaine « default »). Ils sont extraits
# avec ceux du plugin ; Admin\Assets les recopie dans le domaine « default » au chargement de la page.
set -euo pipefail
cd "$(dirname "$0")/.."

fail() {
	echo "$*" >&2
	exit 1
}

WP_BIN="$(command -v wp)" || fail "WP-CLI est requis (https://wp-cli.org)."
[ -f build/admin/sites.js ] || fail "build/ absent : lancer « npm run build » d'abord."

# Les bundles font environ 2 Mo : le parseur JS de WP-CLI dépasse la limite de mémoire par défaut.
wp_cli() { php -d memory_limit=4G "$WP_BIN" "$@"; }

POT=languages/multisite-radar.pot
mkdir -p languages
JS_POT="$(mktemp)"
trap 'rm -f "$JS_POT"' EXIT

# Textes du JS compilé, quel que soit leur domaine, puis PHP et block.json du plugin, fusionnés.
wp_cli i18n make-pot . "$JS_POT" --include=build --exclude=dist,node_modules --ignore-domain --skip-php --skip-block-json --skip-theme-json --skip-audit --quiet
wp_cli i18n make-pot . "$POT" --slug=multisite-radar --domain=multisite-radar --skip-js --merge="$JS_POT" \
	--exclude=src,node_modules,vendor,tests,dist,docs,bin,artifacts,languages --skip-audit --quiet

wp_cli i18n update-po "$POT" languages/ --quiet

# Une chaîne sans traduction a un msgstr vide qui n'est pas suivi d'une ligne de continuation.
missing=0
for po in languages/multisite-radar-*.po; do
	[ -e "$po" ] || continue
	count="$(awk '
		pending && !/^"/ { missing++ }
		{ pending = 0 }
		/^msgstr(\[[0-9]+\])? ""$/ { pending = 1 }
		END { if (pending) missing++; print missing + 0 }
	' "$po")"
	fuzzy="$(grep -c '^#, .*fuzzy' "$po" || true)"
	if [ "$count" != 0 ] || [ "$fuzzy" != 0 ]; then
		echo "$po : $count chaîne(s) sans traduction, $fuzzy à revoir (fuzzy)." >&2
		missing=1
	fi
done
[ "$missing" = 0 ] || fail "Traduire ces chaînes dans le .po, puis relancer « make i18n »."

rm -f languages/*.mo languages/*.l10n.php languages/*.json
wp_cli i18n make-mo languages/ --quiet
wp_cli i18n make-php languages/ --quiet
wp_cli i18n make-json languages/ --no-purge --quiet
echo "Traductions compilées dans languages/ : $(find languages -name '*.json' | wc -l | tr -d ' ') fichiers JSON, $(find languages -name '*.mo' | wc -l | tr -d ' ') .mo."
