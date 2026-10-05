#!/bin/sh
# Réseau de démonstration des captures de WordPress.org (npm run screenshots:seed), sur l'environnement de test de
# wp-env, jamais sur celui des tests E2E. Rejouable : un site existant n'est pas recréé, l'historique est réécrit.
set -eu

URL="$(wp option get siteurl)"
URL="${URL%/}"
DIR="$(dirname "$0")"

# Plugin Check, installé ici par make plugin-check, apparaîtrait dans la capture de l'écran Plugins.
if wp plugin is-installed plugin-check; then
	wp plugin uninstall plugin-check --deactivate
fi

wp plugin activate multisite-radar --network
wp plugin activate akismet --network
wp theme enable twentytwentyfour --network
wp theme enable twentytwentythree --network

# site <chemin> <titre> <articles> <médias> <thème>
site() {
	if ! wp site list --field=path | grep -qx "/$1/"; then
		wp site create --slug="$1" --title="$2" --porcelain >/dev/null
		wp post generate --count="$3" --url="$URL/$1/"
		wp post generate --post_type=attachment --post_status=inherit --count="$4" --url="$URL/$1/"
		wp theme activate "$5" --url="$URL/$1/"
	fi
}

site marketing 'Marketing' 42 60 twentytwentyfive
site hr 'Human Resources' 18 12 twentytwentyfour
site engineering 'Engineering Blog' 120 85 twentytwentyfive
site events 'Events 2026' 9 40 twentytwentythree
site support 'Customer Support' 64 20 twentytwentyfour
site careers 'Careers' 12 6 twentytwentyfive
site press 'Press Room' 27 48 twentytwentythree
site lab 'Innovation Lab' 3 2 twentytwentyfive
site intranet 'Intranet' 88 30 twentytwentyfour

wp plugin activate msradar-demo-cpt --url="$URL/events/"
wp plugin activate msradar-demo-cpt --url="$URL/hr/"
wp plugin activate hello --url="$URL/lab/"

# demo_event <chemin> <nombre> : des contenus du type de démonstration, une seule fois par site.
demo_event() {
	if [ "$(wp post list --post_type=demo_event --format=count --url="$URL/$1/")" = 0 ]; then
		wp post generate --post_type=demo_event --count="$2" --url="$URL/$1/"
	fi
}

demo_event events 14
demo_event hr 5

# Noms neutres pour le site principal et le réseau (visibles dans les captures).
wp option update blogname 'Acme Corporate'
wp network meta update 1 site_name 'Acme Network'

# member <identifiant> <rôle> <chemin du site>
member() {
	wp user get "$1" >/dev/null 2>&1 || wp user create "$1" "$1@example.test" --porcelain >/dev/null
	wp eval "add_user_to_blog( get_current_blog_id(), get_user_by( 'login', '$1' )->ID, '$2' );" --url="$URL/$3/"
}

member alice editor marketing
member alice editor press
member bruno author engineering
member chloe editor hr
member chloe editor careers
member david administrator support
member emma author events
member farid editor intranet
member farid editor engineering

# Alertes : un site sans aucun utilisateur, un site masqué aux moteurs de recherche.
wp eval 'remove_user_from_blog( 1, get_current_blog_id() );' --url="$URL/lab/"
wp option update blog_public 0 --url="$URL/intranet/"

# Les tâches planifiées en retard du banc de test ne doivent pas déclencher d'alerte dans les captures.
wp cron event run --due-now
wp multisite-radar scan --all --probe
wp eval-file "$DIR/history.php"
