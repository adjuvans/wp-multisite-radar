#!/bin/sh
# Prépare le réseau wp-env pour les tests E2E. Idempotent.
# URL du réseau : WP_BASE_URL si définie, sinon l'URL du site principal (donc le port wp-env,
# 8888 par défaut ou WP_ENV_PORT) : « wp-env run » ne transmet pas l'environnement de l'hôte.
set -eu

URL="${WP_BASE_URL:-$(wp option get siteurl)}"
URL="${URL%/}"

wp plugin activate multisite-radar --network

if ! wp site list --field=path | grep -qx '/rh/'; then
	wp site create --slug=rh --title='Blog RH'
fi
wp plugin activate msradar-demo-cpt --url="$URL/rh/"

# Un site sans aucun utilisateur : il déclenche l'alerte « Site without users ».
if ! wp site list --field=path | grep -qx '/vide/'; then
	wp site create --slug=vide --title='Site vide'
	wp eval 'remove_user_from_blog( 1, get_current_blog_id() );' --url="$URL/vide/"
fi

# Apostrophe et esperluette : le cœur enregistre ce titre échappé (L&#039;atelier R&amp;D).
if ! wp site list --field=path | grep -qx '/atelier/'; then
	wp site create --slug=atelier --title="L'atelier R&D"
fi

# Un compte rattaché à aucun site : il apparaît dans le filtre « No site » de la page Utilisateurs.
if ! wp user get radar-orphan >/dev/null 2>&1; then
	# `wp user create` refuse le tiret en multisite (validation des inscriptions) : on passe par wp_insert_user().
	wp eval 'wp_insert_user( array( "user_login" => "radar-orphan", "user_email" => "radar-orphan@example.test", "user_pass" => wp_generate_password(), "role" => "subscriber" ) );'
	wp eval 'remove_user_from_blog( get_user_by( "login", "radar-orphan" )->ID, get_main_site_id() );'
fi

# Un site masqué aux moteurs de recherche : il déclenche l'alerte « Hidden from search engines ».
# Un site à part, pour ne changer aucun site dont dépendent les autres tests (bloc des sites publics, recherche…).
if ! wp site list --field=path | grep -qx '/discret/'; then
	wp site create --slug=discret --title='Site discret'
fi
wp option update blog_public 0 --url="$URL/discret/"
wp multisite-radar scan --all --probe
