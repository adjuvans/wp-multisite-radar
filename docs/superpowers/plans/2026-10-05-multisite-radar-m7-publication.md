# Multisite Radar — Jalon M7 (Publication) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la **2.0.0-rc.1** de Multisite Radar, prête pour WordPress.org :
- **banc de performance** `bin/bench.sh` (spec §11.3) et mesure du critère §1.4 n° 2 sur 1 000 et 5 000 sites ;
- **Plugin Check sans erreur ni avertissement**, vérifié en CI (mode strict) et en local (`make plugin-check`) ;
- les points **« à traiter avant la 2.0 finale »** du document des suites, plus les points de la revue finale de M6 qui touchent l'utilisateur ;
- **identité visuelle** (icône, bannière, icône du menu) et **captures** générées par script ;
- **`readme.txt` complet** et vérifié par script ;
- **chaîne de publication** : workflow de déploiement vers WordPress.org (manuel), procédure écrite ;
- version **2.0.0-rc.1**, traduction fr_FR complète.

Après la recette de la rc.1 en production par l'utilisateur, la section « Après la recette » (fin du plan) passe en 2.0.0 et prépare le zip de la soumission. **La soumission, les push, le déploiement en production et tout commit SVN restent des actions de l'utilisateur, ou du contrôleur avec son accord.**

**Architecture :** aucune nouvelle brique fonctionnelle.
- **Outils de publication**, hors du paquet (`.distignore`) :
  - `bin/bench.sh` et `bin/bench/` : banc de performance ;
  - `bin/plugin-check-report.mjs` : lecture du rapport de Plugin Check ;
  - `bin/readme.mjs` : contrôle du readme ;
  - `bin/wporg-assets/` : sources SVG et rendu des images ;
  - `tests/e2e/screenshots/` : réseau de démonstration et captures ;
  - `.wordpress-org/` : fichiers publiés dans le dossier `assets` du SVN.
- **Corrections** dans le code existant : file d'analyse côté interface (`useScan`), styles DataViews, bloc « Network sites » (liste des sites chargée à la demande par une nouvelle route), graphiques et journal (M6), récapitulatif, rétention et historique.

**Tech stack :** inchangée depuis M6.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6.
- Node 24, `@wordpress/scripts` 36.0.0 (Vitest), React 18.3, `@wordpress/dataviews` 19.1.0 (épinglé).
- Playwright 1.63 + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).
- Nouveaux, sans dépendance npm ni Composer :
  - Plugin Check (extension WordPress `plugin-check`), installée dans l'environnement de test de wp-env ;
  - l'action GitHub `10up/action-wordpress-plugin-deploy` **2.3.0**.

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M7, les sections utiles sont :
- §1.4 (critères de succès : performance, conformité, accessibilité) ;
- §6.4 (qualité d'expérience) ; §9 (sécurité et confidentialité) ;
- §11 (outillage, CI, banc, livraison) ; §12 (jalon M7) ; §14 (risques).

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`. Les points repris ici sont listés plus bas.

## Global Constraints

- **PHP ≥ 7.4.** Interdits :
  - `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains` ;
  - arguments nommés, opérateur nullsafe, ternaire court `?:`, déstructuration courte `[ $a, $b ] = …`.

  Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :**
  - text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, transients, groupes de cache, handles) ;
  - routes REST sous `multisite-radar/v1` ; store `msradar/core`.
- **Publication (WordPress.org) :**
  - slug `multisite-radar`, nom « Multisite Radar » ;
  - `Contributors: adjuvans, cyrilledegourcy` (décision de l'utilisateur, 2026-10-05 : `adjuvans` est le compte principal) ;
  - `Tested up to: 7.1` (dernière version publiée au 2026-10-05 : 7.1.2) ;
  - description courte **identique** dans l'en-tête du plugin et dans `readme.txt`, 150 caractères au plus ;
  - 5 tags au plus ;
  - les fichiers de `.wordpress-org/` ne vont jamais dans le zip.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ; en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder ;
  - jamais de `%` littéral (`%%`) dans une chaîne traduisible ;
  - **traductions** : une tâche qui ajoute ou change une chaîne ne touche ni au `.pot` ni aux `.po` ; la tâche 12 régénère le catalogue (`make i18n`) et traduit tout.
- **SQL :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants ;
  - requêtes directes uniquement dans `includes/Storage/`, `includes/Install/`, `includes/Collector/`, `includes/Scan/Lock.php`, `includes/SitesMenu/SitesListCache.php` et `uninstall.php` ;
  - une lecture qui échoue lève `\RuntimeException` (`check_read()`).
- **Annotations phpcs :**
  - toujours avec un code précis et une justification après ` -- ` ;
  - **justification en anglais**, car l'équipe de revue de WordPress.org la lit ;
  - les justifications existantes, en français, restent telles quelles ;
  - jamais `phpcs:ignoreFile` dans un fichier livré.
- **Erreurs :** le journal, les relevés, le récapitulatif et le widget ne font jamais échouer une analyse, une tâche cron ni le tableau de bord : `try`/`catch` + `do_action( 'msradar_error', <contexte>, $error )`.
- **Données personnelles :** aucune adresse e-mail dans une réponse REST (hors `GET /settings`, réservé à `msradar_manage`), une ability, un export, un événement ou un widget.
- **Aucun appel HTTP externe** dans le plugin. Les outils de développement peuvent en faire : téléchargement de WordPress par le banc, de Plugin Check par `make plugin-check`.
- **Garde d'accès direct :** `defined( 'ABSPATH' ) || exit;` dans les 50 premières lignes de chaque fichier PHP livré, avant les `use` s'il y en a beaucoup.
- **Style PHP :**
  - WPCS ; un tableau associatif de plus d'un élément s'écrit sur plusieurs lignes ;
  - tableaux courts autorisés ;
  - commentaires en français.
- **JS :**
  - `.jsx` pour tout fichier qui contient du JSX, imports sans extension ;
  - feuilles de style importées seulement par les points d'entrée (`src/admin/<vue>.js`, `src/blocks/sites-list/index.js`) ;
  - composants : uniquement des exports publics de `@wordpress/components` présents dans WordPress 6.9, jamais `__experimental*` ;
  - DataViews et DataForm importés seulement par `src/components/data-views/index.js` ;
  - jamais de `dangerouslySetInnerHTML`, pas d'emojis ;
  - couleurs d'accent via `var(--wp-admin-theme-color)` ;
  - animations limitées par `prefers-reduced-motion`.
  - Un `SelectControl` de `@wordpress/components` sur une page qui affiche DataViews ou DataForm reçoit un `id` explicite : le chunk `build/admin/dataviews.js` embarque sa propre copie du compteur d'identifiants.
- **Scripts Node de `bin/`** : modules ES (`.mjs`), sans dépendance hors de `package.json`, `/* eslint-disable no-console */` en tête comme `bin/version.mjs`.
- **Versions :** `MSRADAR_VERSION` est la seule source de vérité ; `make version VERSION=x` ; `npm run version:check`.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `refactor:`, `build:`, `ci:`) ;
  - **jamais de ligne `Co-authored-by`**, même en premier essai : le hook du dépôt la refuse ;
  - jamais `--no-verify`, jamais de push.
- **Tests PHP :**
  - ne jamais lever d'exception dans `set_up()`/`tear_down()` après `parent::set_up()` ;
  - les tables d'un site créé par `self::factory()->blog->create()` sont temporaires ;
  - la base de test locale garde une ligne `site_id` 101 dans `wptests_msradar_sites`, qui fait échouer 11 tests connus (AlertsQueryTest summary, 7 QueueTest, 3 SitesRepositoryTest). Pour une suite propre, lancer `WP_PHPUNIT__TESTS_CONFIG=<copie de tests/php/wp-tests-config.php avec $table_prefix = 'wpci_'> bin/test.sh`.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur le WordPress de développement (`/home/dev/wp`) : le dossier du plugin est un lien symbolique vers le dépôt ;
  - aucune commande d'écriture sur `/home/dev/wp` ;
  - ne jamais afficher le mot de passe de la base : le passer par substitution, `"$(wp config get DB_PASSWORD --path=/home/dev/wp)"` ;
  - ne jamais déplacer ni modifier le dossier `.claude/` ;
  - wp-env avec `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` (le port 8888 est pris) ; les tests E2E visent l'environnement de développement de wp-env (8890), les captures et Plugin Check l'environnement de test (8891, `tests-cli`) ;
  - jamais de `prettier --write` nu : `npx eslint --fix <fichiers>`.
- **Aucune action externe** : ni soumission à WordPress.org, ni push, ni commit SVN, ni déploiement FTP.

## Review Focus

Cas que la spec implique sans qu'un test existant les couvre. Chacun a son test dans la tâche indiquée.

1. **Un éditeur d'un site secondaire** (rôle `editor`, pas super-admin) règle le bloc « Network sites » :
   - la liste des sites se charge à la demande ;
   - un abonné reçoit 403 ;
   - un site non public n'apparaît jamais ;
   - les sites déjà choisis gardent leur nom.

   Tests : tâche 4.
2. **Récapitulatif jamais envoyé, activé un autre jour** que le jour réglé : rien ne part avant le jour réglé. **WP-Cron qui glisse après minuit** : le récapitulatif part le lendemain, une seule fois. Tests : tâche 6.
3. **Rétention des changements réglée à 3 jours avec le récapitulatif actif** : 7 jours sont gardés ; sans récapitulatif, 3 jours. Test : tâche 6.
4. **Base qui contient déjà des tables `msrbench_*`** (banc interrompu) : le banc refuse au lieu d'écraser. Contrôle : tâche 1.
5. **Requête SQL directe ajoutée plus tard sans justification** : la CI échoue (Plugin Check strict) et `make plugin-check` aussi. Contrôle : tâche 2.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M7 | Raison |
|---|---|---|---|
| E1 | §1.4 n° 2, §11.3 : « `GET /sites` répond en moins de 300 ms » | Mesure **dans le processus** (`rest_do_request`) : cache objet vidé avant chaque appel, sans le chargement de WordPress. On retient le p95 sur 20 appels. Un lot d'analyse de l'interface (`POST /scan/batch`) est chronométré à part (limite : 20 s) | Le chargement de WordPress ne dépend pas du plugin, et le banc n'a pas besoin d'un serveur HTTP. |
| E2 | §12 : « soumission de la 2.0.0 » | M7 livre la **2.0.0-rc.1**. La 2.0.0 suit la recette en production (section « Après la recette ») | Décision de l'utilisateur, 2026-10-05. |
| E3 | §1.4 n° 3 : « Plugin Check sans erreur » | **Sans erreur ni avertissement**, avec `strict: true` en CI | L'équipe de revue lit aussi les avertissements, en particulier les paramètres SQL non échappés. |
| E4 | §7.4 : bloc « Network sites » | Les sites proposés dans les réglages du bloc viennent de **`GET /sites-menu/sites`** (droit `edit_posts`, sites publics seulement, 20 par réponse, recherche par nom), et non plus d'une liste complète injectée dans l'éditeur | Point « avant la 2.0 » : 200 Ko par chargement de l'éditeur sur 5 000 sites. |
| E5 | §12 : « captures » | Captures **en anglais**, prises sur un réseau de démonstration de l'environnement de test de wp-env, régénérées par `npm run screenshots` | Reproductibles ; jamais les données d'un vrai réseau. |
| E6 | §6.3 : DataViews épinglé, sans retouche | Les cellules numériques (alignées à droite) perdent la largeur minimale de 15ch imposée par DataViews 19.1 | Point « avant la 2.0 » : la colonne « Analysed » passe sous la colonne fixe « Actions » à 1440 px. |
| E7 | §11.4 : livraison | Déploiement vers le SVN de WordPress.org par un workflow **manuel** (`workflow_dispatch`, simulation par défaut), jamais sur un push de tag | Pousser un tag ne doit pas publier : la publication reste une décision explicite. |
| E8 | Écart E7 du plan M6 : récapitulatif « le jour réglé » | Un récapitulatif manqué (WP-Cron en retard) part dans les jours qui suivent, une fois. Un récapitulatif jamais envoyé attend le jour réglé | Revue finale de M6 : un retard de quelques minutes après minuit sautait la semaine. |
| E9 | §6.4 : couleurs de l'admin | Tons « information » et « avertissement » des graphiques remplacés par des couleurs de la palette WordPress à 3:1 au moins sur blanc. Chaque ton a son motif de trait (plein, tirets, pointillés) | WCAG 1.4.11 (contraste non textuel) et 1.4.1 (la couleur ne doit pas être le seul moyen de distinguer). |

## Points reportés traités dans ce plan

| Point (document des suites de M2) | Section | Tâche |
|---|---|---|
| Action groupée « Réanalyser » pendant une analyse | 1 | 3 |
| Colonne « Analysé le » tronquée | 1 | 3 |
| L'éditeur de blocs intègre la liste de tous les sites | 1 | 4 |
| Cache du menu des sites au-delà de 100 sites (test) | 1 | 4 |
| Accessibilité : lien vers le ticket amont de DataViews, audit de tous les onglets de la fiche | 1 | 7 |
| Confirmer « Tested up to: 7.1 » | 1 | 10 |
| Graphique : point isolé en ellipse, tons sous 3:1, message d'une liste vide filtrée | 6 | 5 |
| Page non remise à zéro quand la prop `site` change | 6 | 5 |
| Carte des alertes sur 30 jours sans squelette | 6 | 5 |
| Série entièrement nulle tracée vide | 6 (revue finale) | 5 |
| Récapitulatif sauté si la tâche glisse après minuit | 6 (revue finale) | 6 |
| Rétention des changements inférieure à 7 jours | 6 (revue finale) | 6 |
| Adresses des destinataires visibles dans les réglages : texte de confidentialité | 6 (revue finale) | 6 |
| `History` : un seul `try` pour la capture et les deux purges | 6 | 6 |

Les autres points restent dans le document des suites. La tâche 12 le met à jour.

## Fichiers

| Fichier | Rôle | Tâche |
|---|---|---|
| `bin/bench.sh`, `bin/bench/create-sites.php`, `bin/bench/routes.php` | Banc de performance | 1 |
| `docs/benchmarks.md` | Résultats du banc | 1 |
| `bin/plugin-check-report.mjs` | Lecture du rapport CSV de Plugin Check | 2 |
| `includes/Rest/SitesMenuController.php` | `GET /sites-menu/sites` | 4 |
| `src/blocks/sites-list/tokens.js`, `edit.jsx` | Bloc : sites chargés à la demande | 4 |
| `bin/wporg-assets/icon.svg`, `banner.svg`, `menu-icon.svg`, `render.mjs` | Identité visuelle | 8 |
| `.wordpress-org/` | Icône, bannière, captures (SVN `assets`) | 8, 9 |
| `tests/e2e/screenshots/seed.sh`, `history.php`, `wporg.spec.js`, `playwright.screenshots.config.js` | Captures | 9 |
| `bin/readme.mjs`, `bin/test/readme.test.mjs` | Contrôle du readme | 10 |
| `.github/workflows/wporg-deploy.yml`, `docs/release.md` | Publication | 11 |

---

### Task 1: Banc de performance

Spec §11.3 : « Banc de performance manuel (`bin/bench.sh`) : création de 1 000 sites, mesure de `GET /sites` et du débit d'analyse ; exécuté avant chaque version. » Le banc n'existe pas encore. Critère §1.4 n° 2 : `GET /sites` en moins de 300 ms sur 5 000 sites ; aucune requête HTTP au-delà de 20 s d'analyse.

**Files:**
- Create: `bin/bench.sh`, `bin/bench/create-sites.php`, `bin/bench/routes.php`, `docs/benchmarks.md`
- Modify: `Makefile` (cible `bench`), `README.md` (section « Développement »)

**Interfaces:**
- Consumes :
  - `MultisiteRadar\Plugin::instance()->queue()->request_full_scan( int $network_id )` ;
  - les routes `GET /sites`, `GET /alerts/summary`, `GET /inventory/summary`, `GET /scan/status` et `POST /scan/batch` ;
  - la commande `wp multisite-radar scan --all`.
- Produces : `make bench` ; `docs/benchmarks.md`, lu par la tâche 12 et par `docs/release.md` (tâche 11).

- [ ] **Step 1: Créer `bin/bench/create-sites.php`**

```php
<?php
/**
 * Banc de performance, appelé par bin/bench.sh (wp eval-file) : crée BENCH_SITES sites /bench-1/, /bench-2/…
 * avec le contenu par défaut de WordPress et le compte admin comme administrateur.
 *
 * @package MultisiteRadar
 */

( static function (): void {
	$count   = max( 1, (int) getenv( 'BENCH_SITES' ) );
	$network = get_network();
	for ( $i = 1; $i <= $count; $i++ ) {
		$site = wp_insert_site(
			[
				'domain'  => $network->domain,
				'path'    => $network->path . 'bench-' . $i . '/',
				'title'   => 'Bench site ' . $i,
				'user_id' => 1,
			]
		);
		if ( is_wp_error( $site ) ) {
			WP_CLI::error( $site->get_error_message() );
		}
		if ( 0 === $i % 250 ) {
			WP_CLI::log( sprintf( '%d sites created.', $i ) );
			// Le cache objet de WP-CLI grossit à chaque site : on le vide de temps en temps.
			\WP_CLI\Utils\wp_clear_object_cache();
		}
	}
} )();
```

- [ ] **Step 2: Créer `bin/bench/routes.php`**

```php
<?php
/**
 * Banc de performance, appelé par bin/bench.sh (wp --user=admin eval-file). Mesure les routes de lecture de
 * l'interface dans le processus, cache objet vidé avant chaque appel comme pour une requête neuve sans cache
 * persistant (écart E1 du plan M7), puis un lot d'analyse de l'interface (POST /scan/batch).
 * Écrit des lignes Markdown ; code de sortie 1 si une variante de GET /sites dépasse BENCH_MAX_MS au p95, si le lot
 * dépasse 20 secondes ou si une route échoue.
 *
 * @package MultisiteRadar
 */

( static function (): void {
	$runs   = max( 1, (int) getenv( 'BENCH_RUNS' ) );
	$max_ms = (float) getenv( 'BENCH_MAX_MS' );
	$theme  = (string) get_option( 'stylesheet' );
	$routes = [
		[ 'GET /sites', '/sites', [] ],
		[ 'GET /sites?search=Bench site 42', '/sites', [ 'search' => 'Bench site 42' ] ],
		[
			'GET /sites?orderby=content_count&order=desc',
			'/sites',
			[
				'orderby' => 'content_count',
				'order'   => 'desc',
			],
		],
		[
			'GET /sites?theme=' . $theme . '&page=10',
			'/sites',
			[
				'theme' => $theme,
				'page'  => 10,
			],
		],
		[ 'GET /alerts/summary', '/alerts/summary', [] ],
		[ 'GET /inventory/summary', '/inventory/summary', [] ],
		[ 'GET /scan/status', '/scan/status', [] ],
	];

	$failed = false;
	WP_CLI::log( '| Route | Median (ms) | p95 (ms) | Max (ms) |' );
	WP_CLI::log( '|---|---:|---:|---:|' );
	foreach ( $routes as $route ) {
		$times = [];
		// Le premier appel, qui charge les classes, n'est pas compté.
		for ( $run = 0; $run <= $runs; $run++ ) {
			wp_cache_flush();
			$request = new WP_REST_Request( 'GET', '/multisite-radar/v1' . $route[1] );
			$request->set_query_params( $route[2] );
			$start    = microtime( true );
			$response = rest_do_request( $request );
			$elapsed  = ( microtime( true ) - $start ) * 1000;
			if ( 200 !== $response->get_status() ) {
				WP_CLI::error( sprintf( '%s answered HTTP %d.', $route[0], $response->get_status() ) );
			}
			if ( $run > 0 ) {
				$times[] = $elapsed;
			}
		}
		sort( $times );
		$count  = count( $times );
		$median = $times[ intdiv( $count - 1, 2 ) ];
		$p95    = $times[ (int) ceil( 0.95 * $count ) - 1 ];
		WP_CLI::log( sprintf( '| %s | %.1f | %.1f | %.1f |', $route[0], $median, $p95, $times[ $count - 1 ] ) );
		if ( 0 === strpos( $route[0], 'GET /sites' ) && $p95 > $max_ms ) {
			$failed = true;
		}
	}

	\MultisiteRadar\Plugin::instance()->queue()->request_full_scan( get_current_network_id() );
	$start    = microtime( true );
	$response = rest_do_request( new WP_REST_Request( 'POST', '/multisite-radar/v1/scan/batch' ) );
	$seconds  = microtime( true ) - $start;
	$data     = (array) $response->get_data();
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '- One interface batch (POST /scan/batch): %d site(s) in %.1f s (limit: 20 s).', (int) ( $data['processed'] ?? 0 ), $seconds ) );
	if ( 200 !== $response->get_status() || $seconds > 20 ) {
		$failed = true;
	}

	if ( $failed ) {
		WP_CLI::halt( 1 );
	}
} )();
```

- [ ] **Step 3: Créer `bin/bench.sh`** (exécutable : `chmod +x bin/bench.sh`)

```bash
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
if wpb core is-installed >/dev/null 2>&1; then
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
```

- [ ] **Step 4: Ajouter la cible `bench` au `Makefile`**

Ajouter `bench` à la liste `.PHONY`, puis, après la cible `e2e-stop` :

```make
bench: build ## Banc de performance sur un multisite jetable (BENCH_DB_* requis ; BENCH_SITES=1000 par défaut)
	bin/bench.sh
```

- [ ] **Step 5: Contrôles statiques**

Run: `shellcheck bin/bench.sh && vendor/bin/phpcs -q bin/bench/`
Expected: aucune sortie. Corriger tout signalement de phpcs ; ne jamais ajouter `phpcs:ignoreFile`.

- [ ] **Step 6: Essai rapide (20 sites) et refus d'une base déjà occupée (Review Focus 4)**

```bash
export BENCH_DB_NAME=wordpress_test BENCH_DB_USER=wordpress BENCH_DB_HOST=127.0.0.1:3306
export BENCH_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)"
npm run build && BENCH_SITES=20 BENCH_RUNS=3 bin/bench.sh
```

Expected: un tableau Markdown de 7 lignes, la ligne « One interface batch », puis `BENCH OK`. Ensuite, aucune table `msrbench_` ne doit rester :

```bash
php -r '$db = new mysqli( "127.0.0.1", getenv( "BENCH_DB_USER" ), getenv( "BENCH_DB_PASSWORD" ), getenv( "BENCH_DB_NAME" ), 3306 ); echo $db->query( "SHOW TABLES LIKE \"msrbench\\_%\"" )->num_rows, "\n";'
```

Expected: `0`.

Vérifier ensuite le refus d'une base occupée :
1. créer une table `msrbench_options`, avec la même connexion PHP (`CREATE TABLE msrbench_options (option_id int)`) ;
2. lancer `BENCH_SITES=1 bin/bench.sh` : il doit afficher `BENCH FAILED: database wordpress_test already holds msrbench_* tables` et ne rien supprimer ;
3. supprimer la table créée en 1 (`DROP TABLE msrbench_options`).

- [ ] **Step 7: Mesures sur 1 000 puis 5 000 sites**

Lancer les deux mesures **en tâche de fond** : 5 000 sites prennent plusieurs dizaines de minutes.

```bash
BENCH_SITES=1000 BENCH_REPORT=/tmp/claude-1000/bench-1000.md bin/bench.sh
BENCH_SITES=5000 BENCH_REPORT=/tmp/claude-1000/bench-5000.md bin/bench.sh
```

Expected : `BENCH OK` pour les deux.
- **Si une variante de `GET /sites` dépasse 300 ms au p95 à 5 000 sites**, ne rien optimiser dans cette tâche : terminer les étapes 8 et 9 avec les chiffres réels, puis rendre le statut `DONE_WITH_CONCERNS` avec le tableau. Le contrôleur décidera.
- **Si le banc échoue pour une autre raison**, rendre `BLOCKED` avec la sortie. Exemples : une route en erreur, ou un lot au-delà de 20 s.

- [ ] **Step 8: Écrire `docs/benchmarks.md`**

Le fichier reprend les deux rapports **tels que le banc les a écrits**, sans arrondir ni retoucher :

````markdown
# Multisite Radar — banc de performance

Mesures de `bin/bench.sh` (`make bench`, spec §11.3), à refaire avant chaque version.

- Critère de la spec §1.4 n° 2 : `GET /sites` en moins de 300 ms au p95 sur 5 000 sites, et aucune requête d'analyse de plus de 20 s.
- Les routes sont mesurées dans le processus (`rest_do_request`) : cache objet vidé avant chaque appel, sans le chargement de WordPress (écart E1 du plan M7). Chaque route est appelée 20 fois, après un premier appel non compté.

Lancement :

```bash
BENCH_DB_NAME=… BENCH_DB_USER=… BENCH_DB_PASSWORD=… BENCH_DB_HOST=… BENCH_SITES=5000 make bench
```

## 2026-10-05 — 2.0.0-beta.6

### 1 000 sites

<contenu de /tmp/claude-1000/bench-1000.md>

### 5 000 sites

<contenu de /tmp/claude-1000/bench-5000.md>
````

Remplacer la date du titre par celle du jour de la mesure.

- [ ] **Step 9: README**

Dans `README.md`, section « Développement », ajouter au bloc des commandes `make`, après la ligne `make e2e` :

```bash
make bench            # banc de performance (BENCH_DB_*, BENCH_SITES=1000) ; résultats dans docs/benchmarks.md
```

- [ ] **Step 10: Commit**

```bash
git add bin/bench.sh bin/bench/create-sites.php bin/bench/routes.php docs/benchmarks.md Makefile README.md
git commit -m "build: add the performance bench and record the 1,000 and 5,000 site runs"
```

---

### Task 2: Plugin Check sans erreur ni avertissement

Au 2026-10-05, Plugin Check sur les fichiers du paquet donne **0 erreur et 179 avertissements**, tous sur les requêtes SQL directes :
- 84 `WordPress.DB.DirectDatabaseQuery.DirectQuery` ;
- 80 `WordPress.DB.DirectDatabaseQuery.NoCaching` ;
- 13 `PluginCheck.Security.DirectDB.UnescapedDBParameter` ;
- 2 `WordPress.DB.DirectDatabaseQuery.SchemaChange`.

Fichiers concernés : `includes/Storage/SitesRepository.php`, `UsersRepository.php`, `EventsRepository.php`, `SnapshotsRepository.php`, `ExtensionsRepository.php`, `includes/Collector/SiteCollector.php`, `includes/Scan/Lock.php`, `includes/SitesMenu/SitesListCache.php`, `includes/Install/LegacyMigration.php`, `includes/Install/Schema.php`, `uninstall.php`.

`phpcs.xml.dist` exclut aujourd'hui `WordPress.DB.DirectDatabaseQuery` pour ces dossiers. Plugin Check ignore cette exclusion. Les avertissements de phpcs ne font pas échouer `composer lint` (`ignore_warnings_on_exit`).

**Files:**
- Modify : les 11 fichiers ci-dessus, `phpcs.xml.dist`, `.github/workflows/ci.yml` (job `plugin-check`), `Makefile` (cible `plugin-check`), `README.md`
- Create : `bin/plugin-check-report.mjs`

**Interfaces:**
- Produces : `make plugin-check`, qui échoue sur toute erreur ou tout avertissement de Plugin Check. Les tâches 8 et 12 s'en servent.

- [ ] **Step 1: Créer `bin/plugin-check-report.mjs`**

Le format CSV de `wp plugin check --format=csv` donne, pour chaque fichier :
- une ligne `FILE: <chemin>` ;
- une ligne d'en-tête `file,line,type,code,message` ;
- puis une ligne par signalement.

Sans signalement, la commande écrit une ligne `Success: …`.

```js
#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Lit le rapport de « wp plugin check … --format=csv --fields=file,line,type,code,message » (make plugin-check) et
 * échoue sur la moindre erreur ou le moindre avertissement, comme le mode strict de la CI.
 * Usage : node bin/plugin-check-report.mjs <rapport.csv>
 */
import { readFileSync } from 'node:fs';

/**
 * Lignes CSV (guillemets doublés, retours à la ligne dans un champ guillemeté).
 *
 * @param {string} text Contenu du rapport.
 * @return {string[][]} Lignes.
 */
export function parseCsv( text ) {
	const rows = [];
	let row = [];
	let field = '';
	let quoted = false;
	for ( let i = 0; i < text.length; i++ ) {
		const char = text[ i ];
		if ( quoted ) {
			if ( char === '"' && text[ i + 1 ] === '"' ) {
				field += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				field += char;
			}
		} else if ( char === '"' ) {
			quoted = true;
		} else if ( char === ',' ) {
			row.push( field );
			field = '';
		} else if ( char === '\n' ) {
			row.push( field );
			rows.push( row );
			row = [];
			field = '';
		} else if ( char !== '\r' ) {
			field += char;
		}
	}
	if ( field !== '' || row.length ) {
		row.push( field );
		rows.push( row );
	}
	return rows;
}

const file = process.argv[ 2 ];
if ( ! file ) {
	console.error( 'Usage: node bin/plugin-check-report.mjs <report.csv>' );
	process.exit( 2 );
}
const rows = parseCsv( readFileSync( file, 'utf8' ) );
const ran = rows.some(
	( row ) =>
		row[ 0 ].startsWith( 'Success:' ) ||
		row.join( ',' ) === 'file,line,type,code,message'
);
if ( ! ran ) {
	console.error(
		`Unexpected Plugin Check output in ${ file }: is wp-env started?`
	);
	process.exit( 2 );
}
const issues = rows.filter(
	( row ) => row.length >= 5 && [ 'ERROR', 'WARNING' ].includes( row[ 2 ] )
);
for ( const [ path, line, type, code, message ] of issues ) {
	console.log(
		`${ path.replace( /^.*?\/(includes|build|languages|uninstall\.php|multisite-radar\.php|readme\.txt)/, '$1' ) }:${ line } ${ type } ${ code } ${ message }`
	);
}
const errors = issues.filter( ( row ) => row[ 2 ] === 'ERROR' ).length;
console.log(
	`Plugin Check: ${ errors } error(s), ${ issues.length - errors } warning(s).`
);
process.exit( issues.length ? 1 : 0 );
```

- [ ] **Step 2: Ajouter la cible `plugin-check` au `Makefile`**

Ajouter `plugin-check` à `.PHONY`, puis, après la cible `dist` :

```make
plugin-check: build ## Plugin Check sur les fichiers du paquet, dans wp-env (démarré) ; échoue sur toute erreur ou tout avertissement
	rm -rf $(DIST_DIR)/plugin-check
	mkdir -p $(DIST_DIR)/plugin-check/$(SLUG)
	rsync -a --exclude-from=.distignore ./ $(DIST_DIR)/plugin-check/$(SLUG)/
	npm run --silent wp-env -- run tests-cli wp plugin install plugin-check --activate
	npm run --silent wp-env -- run tests-cli wp plugin check wp-content/plugins/$(SLUG)/$(DIST_DIR)/plugin-check/$(SLUG) \
		--format=csv --fields=file,line,type,code,message > $(DIST_DIR)/plugin-check.csv || true
	node bin/plugin-check-report.mjs $(DIST_DIR)/plugin-check.csv
```

Le `|| true` est voulu. `wp plugin check` sort en erreur dès qu'il signale quelque chose, et c'est le script de rapport qui conclut. Une sortie inattendue (wp-env arrêté) le fait aussi échouer.

- [ ] **Step 3: Constater l'état de départ**

Run: `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 && npm run wp-env -- start && make plugin-check`
Expected: FAIL avec `Plugin Check: 0 error(s), 179 warning(s).`

- [ ] **Step 4: Retirer l'exclusion de `phpcs.xml.dist`**

Supprimer le bloc suivant, commentaire compris. phpcs signalera alors en local les mêmes avertissements que Plugin Check.

```xml
	<!-- Nos tables personnalisées et celles des sites sont lues directement : c'est le cœur du plugin. -->
	<rule ref="WordPress.DB.DirectDatabaseQuery">
		<exclude-pattern>/includes/(Storage|Collector|Scan|Install)/*</exclude-pattern>
		<exclude-pattern>/uninstall\.php</exclude-pattern>
		<exclude-pattern>/includes/SitesMenu/SitesListCache\.php</exclude-pattern>
	</rule>
```

- [ ] **Step 5: Justifier les requêtes directes, fichier par fichier**

Dans chacun des 11 fichiers, juste après la ligne `defined( 'ABSPATH' ) || exit;`, ajouter une ligne vide puis **une seule** directive `phpcs:disable`. Elle porte les codes que Plugin Check signale dans ce fichier, et la justification ci-dessous :

| Fichier | Codes | Justification (texte après ` -- `) |
|---|---|---|
| `includes/Storage/SitesRepository.php`, `UsersRepository.php`, `EventsRepository.php`, `SnapshotsRepository.php`, `ExtensionsRepository.php` | `DirectQuery`, `NoCaching` | `Multisite Radar's own network tables: no WordPress API reads or writes them, and the query services cache what they need.` |
| `includes/Collector/SiteCollector.php` | `DirectQuery`, `NoCaching` | `Aggregated reads of the core tables of each site, one query per measure; the results are stored in the msradar_sites table, which is the cache.` |
| `includes/Scan/Lock.php` | `DirectQuery`, `NoCaching` | `Atomic lock row in the options table of the main site: the options API cannot compare and swap, and a cached value would defeat the lock.` |
| `includes/SitesMenu/SitesListCache.php` | `DirectQuery`, `NoCaching` | `Bulk read of wp_blogs and of the name and address of every public site, without switch_to_blog(); the result is cached in a site transient.` |
| `includes/Install/LegacyMigration.php` | `DirectQuery`, `NoCaching` | `One-off migration of the 1.x options and menu items, by batches.` |
| `includes/Install/Schema.php` | `DirectQuery`, `NoCaching`, `SchemaChange` | `Creates, upgrades and checks the plugin's own network tables.` |
| `uninstall.php` | `DirectQuery`, `NoCaching`, `SchemaChange` | `Drops the plugin's own tables and deletes its data on uninstall.` |

Forme exacte, par exemple pour `SitesRepository.php` :

```php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite Radar's own network tables: no WordPress API reads or writes them, and the query services cache what they need.
```

Ne mettre dans la directive que les codes réellement signalés dans le fichier. Le rapport de l'étape 3 le dit ; par exemple, `SchemaChange` n'est signalé que dans `Schema.php` et `uninstall.php`. Ne garder aucun `phpcs:enable` de ces trois codes : la directive vaut pour tout le fichier.

**Avant d'écrire chaque justification, vérifier qu'elle est vraie.** Exemple : `Lock.php` doit bien écrire sous condition (`INSERT IGNORE`, `UPDATE … WHERE`) dans la table des options du site principal. Si un fichier fait autre chose, écrire la justification exacte de ce qu'il fait et le signaler dans le rapport.

- [ ] **Step 6: Justifier les 13 `UnescapedDBParameter`**

Chaque signalement vise un fragment SQL construit dans une variable :
- `$union` et `$where` dans `SitesRepository` (vers les lignes 355 et 461) ;
- `$where` dans `EventsRepository` (vers la ligne 67) ;
- `$join`, `$condition` et `$from` dans `UsersRepository` (vers les lignes 36, 68 et 77) ;
- `$parts` et `$select` dans `SitesListCache` (vers les lignes 126 et 131).

Pour chacun :
1. **Relire la construction de la variable.** Elle ne doit contenir que des fragments SQL fixes, des placeholders (`%d`, `%s`, `%i`) remplis par `prepare()`, et des valeurs prises dans une liste blanche (`ASC`/`DESC`, colonnes de `ORDERBY`). Si une valeur venue de l'appelant y entre sans placeholder, **corriger le code** pour la passer par un placeholder, et ajouter un test PHPUnit qui l'exerce.
2. **Ajouter le code `PluginCheck.Security.DirectDB.UnescapedDBParameter`** à la directive phpcs déjà posée au-dessus de la requête :
   - à la liste d'un `phpcs:disable` existant, par exemple dans `SitesRepository::query()` :
     `// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where ne contient que des fragments fixes et des placeholders ; $order vaut ASC ou DESC.` ;
   - ou à un `phpcs:ignore` existant (`SitesListCache::read_options()`). S'il n'y a pas de directive, en ajouter une ligne, avec une justification en anglais qui dit ce que contient la variable.

- [ ] **Step 7: Vérifier en local**

Run: `vendor/bin/phpcs -q --sniffs=WordPress.DB.DirectDatabaseQuery includes uninstall.php`
Expected: aucune sortie.

Run: `make plugin-check`
Expected: `Plugin Check: 0 error(s), 0 warning(s).`

Run: `composer lint && composer analyse`
Expected: succès.

Run: `bin/test.sh`
Expected: seuls les 11 échecs connus de la ligne 101 (voir les contraintes globales).

- [ ] **Step 8: Rendre la CI stricte**

Dans `.github/workflows/ci.yml`, job `plugin-check`, l'étape devient :

```yaml
      - uses: wordpress/plugin-check-action@v1
        with:
          build-dir: /tmp/build/multisite-radar
          # Tout avertissement fait échouer le job (écart E3 du plan M7). Une nouvelle version majeure de WordPress
          # peut faire signaler « Tested up to » : mettre le readme à jour (docs/release.md).
          strict: true
```

- [ ] **Step 9: README**

Dans `README.md`, section « Développement », ajouter au bloc des commandes `make`, après `make dist` :

```bash
make plugin-check     # Plugin Check (wp-env démarré) sur les fichiers du paquet : zéro erreur et zéro avertissement
```

- [ ] **Step 10: Commit**

```bash
git add bin/plugin-check-report.mjs Makefile phpcs.xml.dist .github/workflows/ci.yml README.md includes uninstall.php
git commit -m "chore: justify every direct database query and make Plugin Check strict"
```

Si l'étape 6 a corrigé du code, le commit de cette correction part d'abord, avec son test : `fix: pass <valeur> through a placeholder`.

---

### Task 3: Page Sites — « Analyse again » pendant une analyse, colonnes à 1440 px

Deux points « avant la 2.0 » du document des suites (section 1).
- **« Analyse again » pendant une analyse.** DataViews 19.1 ignore `disabled` sur les actions groupées : le bouton reste actif. `useScan().start()` renvoie alors l'analyse en cours sans rien dire ; la sélection n'est ni marquée ni analysée, et « Analysis complete. » s'affiche quand même.
- **Colonnes tronquées.** À 1440 px, le tableau mesure 1291 px pour 1238 px de conteneur (mesure du 2026-10-05 sur wp-env). La colonne « Analysed » (1188–1320 px) passe sous la colonne fixe « Actions » (1268–1420 px). En cause, la règle de DataViews 19.1 : `.dataviews-view-table__cell-content-wrapper:not(.dataviews-column-primary__media) { min-width: 15ch }`. Chaque colonne fait ainsi au moins 132 px, même pour un nombre.

**Files:**
- Modify: `src/hooks/use-scan.js`, `src/hooks/test/use-scan.test.jsx`, `src/views/sites/actions.js` (docblock), `src/admin/style.scss`
- Create: `tests/e2e/specs/layout.spec.js`

**Interfaces:**
- Consumes : `useScan()` (inchangé côté appelants : `start( request )` renvoie toujours la promesse de l'analyse en cours).
- Produces : rien de nouveau.

- [ ] **Step 1: Test qui échoue — un second `start()` le dit**

Dans `src/hooks/test/use-scan.test.jsx`, le test `a second start while an analysis runs returns the running one instead of starting another` récupère aussi `registry` et vérifie l'avis. Remplacer :

```js
	const { hook } = setup();

	let first;
	let second;
	await act( async () => {
		first = hook.result.current.start( { scope: 'ids', ids: [ 1 ] } );
		second = hook.result.current.start( { scope: 'ids', ids: [ 2 ] } );
	} );
	expect( second ).toBe( first );
	expect( marks ).toEqual( [ { scope: 'ids', ids: [ 1 ] } ] );
```

par :

```js
	const { registry, hook } = setup();

	let first;
	let second;
	await act( async () => {
		first = hook.result.current.start( { scope: 'ids', ids: [ 1 ] } );
		second = hook.result.current.start( { scope: 'ids', ids: [ 2 ] } );
	} );
	expect( second ).toBe( first );
	expect( marks ).toEqual( [ { scope: 'ids', ids: [ 1 ] } ] );
	// DataViews 19.1 laisse « Analyse again » cliquable : l'utilisateur apprend que sa sélection n'a pas été analysée.
	expect( messages( registry ) ).toContain(
		'An analysis is already running. Select the sites again once it is complete.'
	);
```

Run: `npx wp-scripts test-unit-js src/hooks/test/use-scan.test.jsx`
Expected: FAIL sur `toContain`.

- [ ] **Step 2: Implémenter**

Dans `src/hooks/use-scan.js` :
1. Dans le docblock de `useScan`, remplacer la dernière phrase (« Une seule analyse à la fois : start() pendant qu'elle tourne renvoie sa promesse. ») par :

```js
 * l'arriéré du réseau reste au cron. Une seule analyse à la fois : start() pendant qu'elle tourne renvoie sa promesse
 * et l'annonce par un avis, car DataViews 19.1 laisse « Analyse again » cliquable pendant une analyse.
```

2. Remplacer le `useCallback` de `start` par :

```js
	const start = useCallback(
		( request ) => {
			if ( active.current ) {
				createInfoNotice(
					__(
						'An analysis is already running. Select the sites again once it is complete.',
						'multisite-radar'
					),
					{ type: 'snackbar' }
				);
				return active.current;
			}
			active.current = run( request ).finally( () => {
				active.current = null;
			} );
			return active.current;
		},
		[ run, createInfoNotice ]
	);
```

Dans `src/views/sites/actions.js`, la ligne du docblock `@param {boolean} options.isScanning …` devient :

```js
 * @param {boolean}                   options.isScanning Une analyse est en cours : « Analyse again » est désactivée (DataViews 19.1 l'ignore pour les actions groupées ; useScan l'annonce).
```

Run: `npx wp-scripts test-unit-js src/hooks/test/use-scan.test.jsx`
Expected: PASS (8 tests).

- [ ] **Step 3: Test E2E qui échoue — le tableau tient à 1440 px**

Créer `tests/e2e/specs/layout.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.use( { viewport: { width: 1440, height: 900 } } );

test( 'the Sites table fits a 1440 px wide screen: no column hides under the fixed Actions column', async ( {
	admin,
	page,
} ) => {
	await admin.visitAdminPage(
		'network/admin.php',
		'page=multisite-radar-sites'
	);
	const table = page.locator( '#msradar-app .dataviews-view-table' );
	await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();

	const overflow = await page.evaluate( () => {
		const container = document.querySelector(
			'#msradar-app .dataviews-layout__container'
		);
		return container.scrollWidth - container.clientWidth;
	} );
	expect( overflow ).toBeLessThanOrEqual( 0 );

	const headers = table.locator( 'thead th' );
	const count = await headers.count();
	const actions = await headers.nth( count - 1 ).boundingBox();
	const last = await headers.nth( count - 2 ).boundingBox();
	expect( last.x + last.width ).toBeLessThanOrEqual( actions.x + 1 );
} );
```

Run :

```bash
export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890
npm run build && npm run wp-env -- start && npm run e2e:setup && npm run test:e2e -- tests/e2e/specs/layout.spec.js
```

Expected: FAIL (`overflow` ≈ 53).

- [ ] **Step 4: Implémenter**

Dans `src/admin/style.scss`, après le bloc `.msradar-table { … }` :

```scss
// DataViews 19.1 donne au moins 15ch à chaque cellule : avec huit colonnes, la page Sites déborde dès 1440 px et la
// colonne fixe « Actions » recouvre la dernière (écart E6 du plan M7). Les nombres, alignés à droite, n'en ont pas
// besoin : leur colonne se règle sur son en-tête.
.msradar-app .dataviews-view-table__cell-content-wrapper.dataviews-view-table__cell-align-end {
	min-width: auto;
}
```

Run: `npm run build && npm run test:e2e -- tests/e2e/specs/layout.spec.js`
Expected: PASS.

- [ ] **Step 5: Non-régression**

Run: `npm run lint:js && npm run lint:css && npm run test:unit && npm run test:e2e`
Expected: tout passe (Playwright : 28 tests).

- [ ] **Step 6: Commit**

```bash
git add src/hooks/use-scan.js src/hooks/test/use-scan.test.jsx src/views/sites/actions.js src/admin/style.scss tests/e2e/specs/layout.spec.js
git commit -m "fix: say when an analysis is already running and fit the Sites table on a 1440 px screen"
```

---

### Task 4: Bloc « Network sites » — sites chargés à la demande

Point « avant la 2.0 » (section 1). `Block::editor_data()` injecte dans l'éditeur de blocs la liste de tous les sites publics. Cela fait environ 40 octets par site, à chaque chargement de l'éditeur, sur chaque site : 200 Ko pour 5 000 sites.

Correctif (écart E4) :
- la route `GET /multisite-radar/v1/sites-menu/sites` propose les sites à la demande ;
- le bloc les cherche au fil de la saisie, et lit le nom des sites déjà choisis par leurs identifiants.

Le chemin de `SitesListCache` qui lit les sites par tranches de 100 n'a pas de test. La taille de tranche devient un paramètre du constructeur, pour le tester avec quelques sites.

**Files:**
- Create: `includes/Rest/SitesMenuController.php`, `tests/php/Rest/SitesMenuControllerTest.php`
- Modify: `includes/Plugin.php` (`register_rest_routes()`), `includes/SitesMenu/Block.php`, `includes/SitesMenu/SitesListCache.php`, `tests/php/SitesMenu/SitesListCacheTest.php`, `src/blocks/sites-list/tokens.js`, `src/blocks/sites-list/edit.jsx`, `src/blocks/sites-list/test/tokens.test.js`, `tests/e2e/specs/sites-menu.spec.js`

**Interfaces:**
- Consumes :
  - `SitesListCache::get(): array` (sites `{ id, name, url, registered }` du réseau courant) ;
  - `Renderer::select( array $sites, array $only, array $exclude, string $order_by, string $order ): array` et `Renderer::label( array $site ): string` ;
  - `Module::enabled(): bool` ; `Plugin::sites_menu(): Module` et `Plugin::sites_list_cache(): SitesListCache`.
- Produces :
  - `GET /multisite-radar/v1/sites-menu/sites?search=&include=&per_page=` → `[ { id: int, name: string } ]` ;
  - `new SitesListCache( int $chunk = 100 )` ;
  - côté JS, dans `tokens.js` : `SITES_PATH`, `sitesPath( { search, include } )` et `mergeSites( current, incoming )`.

- [ ] **Step 1: Tests PHP qui échouent — la route**

Créer `tests/php/Rest/SitesMenuControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class SitesMenuControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
	}

	private function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	public function test_an_editor_finds_the_public_sites_by_name(): void {
		// Pas « Blog » : le site principal de la suite de tests s'appelle « Test Blog ».
		$rh        = self::factory()->blog->create( [ 'title' => 'Radar RH' ] );
		$marketing = self::factory()->blog->create( [ 'title' => 'Radar Équipe marketing' ] );
		self::factory()->blog->create(
			[
				'title'  => 'Radar privé',
				'public' => 0,
			]
		);
		self::factory()->blog->create( [ 'title' => 'Intranet' ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites', [ 'search' => 'radar' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				[
					'id'   => $marketing,
					'name' => 'Radar Équipe marketing',
				],
				[
					'id'   => $rh,
					'name' => 'Radar RH',
				],
			],
			$response->get_data()
		);
	}

	public function test_the_search_ignores_case_and_accents_and_matches_an_id(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Équipe' ] );
		$this->login_as( 'editor' );

		$this->assertSame( [ $site ], wp_list_pluck( $this->request( 'GET', '/sites-menu/sites', [ 'search' => 'equipe' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $site ], wp_list_pluck( $this->request( 'GET', '/sites-menu/sites', [ 'search' => '#' . $site ] )->get_data(), 'id' ) );
	}

	public function test_chosen_sites_are_read_back_by_id_with_their_name(): void {
		$first  = self::factory()->blog->create( [ 'title' => 'Zeta' ] );
		$second = self::factory()->blog->create( [ 'title' => 'Alpha' ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites', [ 'include' => [ $first, $second, 999999 ] ] );

		$this->assertSame( [ 'Alpha', 'Zeta' ], wp_list_pluck( $response->get_data(), 'name' ) );
	}

	public function test_results_are_capped_by_per_page(): void {
		self::factory()->blog->create_many( 3 );
		$this->login_as( 'editor' );

		$this->assertCount( 2, $this->request( 'GET', '/sites-menu/sites', [ 'per_page' => 2 ] )->get_data() );
		$this->assertSame( 400, $this->request( 'GET', '/sites-menu/sites', [ 'per_page' => 51 ] )->get_status() );
	}

	public function test_a_subscriber_cannot_list_the_sites(): void {
		$this->login_as( 'subscriber' );

		$this->assertSame( 403, $this->request( 'GET', '/sites-menu/sites' )->get_status() );
	}

	public function test_the_route_answers_404_while_the_module_is_disabled(): void {
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => false ] ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'msradar_sites_menu_disabled', $response->as_error()->get_error_code() );
	}
}
```

Run: `bin/test.sh --filter SitesMenuControllerTest`
Expected: FAIL (route absente : 404 `rest_no_route` partout).

- [ ] **Step 2: Créer `includes/Rest/SitesMenuController.php`**

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\SitesMenu\Module;
use MultisiteRadar\SitesMenu\Renderer;
use MultisiteRadar\SitesMenu\SitesListCache;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /sites-menu/sites : sites proposés dans les réglages du bloc « Network sites » (écart E4 du plan M7).
 * Lu par l'éditeur de blocs de n'importe quel site du réseau : droit edit_posts sur ce site. Seulement les sites
 * publics, déjà visibles de tous dans le menu, avec leur identifiant et leur nom.
 */
final class SitesMenuController extends Controller {

	public const MAX_PER_PAGE = 50;

	/**
	 * @var string
	 */
	protected $rest_base = 'sites-menu/sites';

	private Module $module;
	private SitesListCache $cache;

	public function __construct( Module $module, SitesListCache $cache ) {
		$this->module = $module;
		$this->cache  = $cache;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_edit_posts' ],
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function get_collection_params(): array {
		return [
			'search'   => [
				'type'    => 'string',
				'default' => '',
			],
			'include'  => [
				'type'     => 'array',
				'default'  => [],
				'maxItems' => 100,
				'items'    => [ 'type' => 'integer' ],
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => self::MAX_PER_PAGE,
			],
		];
	}

	/**
	 * Avec include : ces sites (ceux qui sont encore publics), pour nommer les jetons déjà choisis. Sinon : les sites
	 * dont le nom contient la recherche (sans casse ni accents) ou dont l'identifiant vaut la recherche (« 12 », « #12 »).
	 * Triés par nom, per_page au plus.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		if ( ! $this->module->enabled() ) {
			return new WP_Error( 'msradar_sites_menu_disabled', __( 'The network sites menu is disabled.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		$include = array_map( 'intval', (array) $request['include'] );
		$sites   = Renderer::select( $this->cache->get(), $include, [], 'name', 'asc' );
		$search  = trim( (string) $request['search'] );
		if ( [] === $include && '' !== $search ) {
			$needle = remove_accents( $search );
			$id     = ltrim( $search, '#' );
			$sites  = array_filter(
				$sites,
				static fn ( array $site ): bool => false !== stripos( remove_accents( Renderer::label( $site ) ), $needle ) || (string) $site['id'] === $id
			);
		}
		$limit = [] !== $include ? count( $include ) : (int) $request['per_page'];
		return new WP_REST_Response(
			array_map(
				static fn ( array $site ): array => [
					'id'   => (int) $site['id'],
					'name' => Renderer::label( $site ),
				],
				array_slice( array_values( $sites ), 0, $limit )
			)
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest(
			'msradar-menu-site',
			[
				'type'       => 'object',
				'properties' => [
					'id'   => [
						'type'     => 'integer',
						'readonly' => true,
					],
					'name' => [
						'type'     => 'string',
						'readonly' => true,
					],
				],
			]
		);
	}
}
```

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Rest\SitesMenuController;` dans l'ordre alphabétique des `use` de `Rest\` ;
- ajouter à la liste `$controllers` de `register_rest_routes()`, après `new SitesController( … )` :

```php
			new SitesMenuController( $this->sites_menu(), $this->sites_list_cache() ),
```

La route est enregistrée même quand le module est désactivé. Elle répond alors 404 : le réglage peut changer pendant la requête, et cela garde un seul chemin d'enregistrement.

Run: `bin/test.sh --filter SitesMenuControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 3: Test PHP qui échoue — lecture par tranches**

Ajouter à `tests/php/SitesMenu/SitesListCacheTest.php` :

```php
	public function test_sites_are_read_in_chunks_of_the_given_size(): void {
		$titles = [ 'Chunk A', 'Chunk B', 'Chunk C', 'Chunk D', 'Chunk E' ];
		foreach ( $titles as $title ) {
			self::factory()->blog->create( [ 'title' => $title ] );
		}
		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, "option_name IN ('blogname', 'home')" ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			$sites = ( new SitesListCache( 2 ) )->build( get_current_network_id() );
		} finally {
			remove_filter( 'query', $count );
		}

		$this->assertSame( (int) ceil( count( $sites ) / 2 ), $queries, 'One query per chunk of 2 sites.' );
		$this->assertSame( ( new SitesListCache() )->build( get_current_network_id() ), $sites );
		foreach ( $titles as $title ) {
			$this->assertContains( $title, wp_list_pluck( $sites, 'name' ) );
		}
	}
```

Run: `bin/test.sh --filter test_sites_are_read_in_chunks_of_the_given_size`
Expected: FAIL. `$queries` vaut 1 : le constructeur ignore l'argument, et la tranche reste de 100.

- [ ] **Step 4: Rendre la taille de tranche réglable**

Dans `includes/SitesMenu/SitesListCache.php` :

```php
	private const CHUNK = 100;

	private int $chunk;

	/**
	 * @param int $chunk Sites lus par requête (100 ; les tests en passent moins).
	 */
	public function __construct( int $chunk = self::CHUNK ) {
		$this->chunk = max( 1, $chunk );
	}
```

Dans `build()`, `array_chunk( $blogs, self::CHUNK )` devient `array_chunk( $blogs, $this->chunk )`.

Run: `bin/test.sh --filter SitesListCacheTest`
Expected: PASS.

- [ ] **Step 5: Retirer la liste injectée dans l'éditeur**

Dans `includes/SitesMenu/Block.php` :
- supprimer la ligne `add_action( 'enqueue_block_editor_assets', [ $this, 'editor_data' ] );` de `register()` ;
- supprimer la méthode `editor_data()` et son docblock.

Run: `grep -rn "editor_data\|msradarSitesList" includes src tests`
Expected: plus aucune occurrence après l'étape 7.

- [ ] **Step 6: Tests JS qui échouent — chemins et fusion**

Remplacer le contenu de `src/blocks/sites-list/test/tokens.test.js` par :

```js
import { expect, test } from 'vitest';
import {
	idsToTokens,
	mergeSites,
	sitesPath,
	tokenLabel,
	tokensToIds,
} from '../tokens';

const SITES = [
	{ id: 1, name: 'Main' },
	{ id: 12, name: 'Blog RH' },
	{ id: 13, name: '' },
];

test( 'sites are shown as "name (#id)" tokens and read back as IDs', () => {
	expect( tokenLabel( SITES[ 1 ] ) ).toBe( 'Blog RH (#12)' );
	expect( tokenLabel( SITES[ 2 ] ) ).toBe( '#13 (#13)' );
	expect( idsToTokens( [ 12, 99 ], SITES ) ).toEqual( [
		'Blog RH (#12)',
		'#99',
	] );
	expect(
		tokensToIds(
			[ 'Blog RH (#12)', 'Main', '#99', { value: 'Main' }, 'Unknown' ],
			SITES
		)
	).toEqual( [ 12, 1, 99 ] );
} );

test( 'an exact site name wins over the #id pattern', () => {
	const sites = [ ...SITES, { id: 7, name: 'Team #5' } ];
	expect( tokensToIds( [ 'Team #5' ], sites ) ).toEqual( [ 7 ] );
	expect( tokensToIds( [ '#5' ], sites ) ).toEqual( [ 5 ] );
} );

test( 'the sites are searched by name, or read back by ID', () => {
	expect( sitesPath() ).toBe( '/multisite-radar/v1/sites-menu/sites' );
	expect( sitesPath( { search: 'Blog RH' } ) ).toBe(
		'/multisite-radar/v1/sites-menu/sites?search=Blog%20RH'
	);
	expect( sitesPath( { include: [ 12, 3 ] } ) ).toBe(
		'/multisite-radar/v1/sites-menu/sites?include=12%2C3'
	);
} );

test( 'known sites are merged by ID, the latest name winning', () => {
	expect(
		mergeSites(
			[
				{ id: 1, name: 'Main' },
				{ id: 12, name: 'Old name' },
			],
			[
				{ id: 12, name: 'Blog RH' },
				{ id: 20, name: 'Events' },
			]
		)
	).toEqual( [
		{ id: 1, name: 'Main' },
		{ id: 12, name: 'Blog RH' },
		{ id: 20, name: 'Events' },
	] );
} );
```

Run: `npx wp-scripts test-unit-js src/blocks/sites-list/test/tokens.test.js`
Expected: FAIL (`sitesPath` et `mergeSites` n'existent pas).

- [ ] **Step 7: Implémenter `tokens.js` et `edit.jsx`**

Dans `src/blocks/sites-list/tokens.js`, remplacer `sitesFromWindow()` et son docblock par :

```js
import { addQueryArgs } from '@wordpress/url';

/**
 * Sites proposés par GET /sites-menu/sites (écart E4 du plan M7) : recherche par nom, ou noms des sites déjà choisis.
 */
export const SITES_PATH = '/multisite-radar/v1/sites-menu/sites';

/**
 * @param {Object}   query
 * @param {string}   query.search  Texte saisi.
 * @param {number[]} query.include Identifiants dont on veut le nom.
 * @return {string} Chemin pour apiFetch.
 */
export function sitesPath( { search = '', include = [] } = {} ) {
	return addQueryArgs( SITES_PATH, {
		...( search ? { search } : {} ),
		...( include.length ? { include: include.join( ',' ) } : {} ),
	} );
}

/**
 * Sites connus, sans doublon : un site déjà connu prend le nom le plus récent et garde sa place.
 *
 * @param {Array} current  Sites connus.
 * @param {Array} incoming Sites reçus.
 * @return {Array} Sites connus mis à jour.
 */
export function mergeSites( current, incoming ) {
	const merged = current.map(
		( site ) => incoming.find( ( item ) => item.id === site.id ) || site
	);
	incoming.forEach( ( site ) => {
		if ( ! merged.some( ( item ) => item.id === site.id ) ) {
			merged.push( site );
		}
	} );
	return merged;
}
```

L'import `addQueryArgs` va en tête du fichier. `tokenLabel`, `idsToTokens` et `tokensToIds` ne changent pas.

Remplacer `src/blocks/sites-list/edit.jsx` par :

```jsx
import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	FormTokenField,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import {
	idsToTokens,
	mergeSites,
	sitesPath,
	tokenLabel,
	tokensToIds,
} from './tokens';

/**
 * Sites connus du bloc : les suggestions de la dernière recherche, et le nom des sites déjà choisis, lus une fois.
 * Un échec de lecture laisse les jetons sous la forme « #12 ».
 *
 * @param {number[]} chosen Identifiants déjà choisis (inclus et exclus).
 */
function useSites( chosen ) {
	const [ known, setKnown ] = useState( [] );
	const [ found, setFound ] = useState( [] );
	const remember = useCallback(
		( sites ) => setKnown( ( current ) => mergeSites( current, sites ) ),
		[]
	);

	const missingKey = chosen
		.filter( ( id ) => ! known.some( ( site ) => site.id === id ) )
		.join( ',' );
	useEffect( () => {
		if ( ! missingKey ) {
			return;
		}
		apiFetch( {
			path: sitesPath( { include: missingKey.split( ',' ).map( Number ) } ),
		} )
			.then( remember )
			.catch( () => {} );
	}, [ missingKey, remember ] );

	// Fonction stable : useDebounce en recrée une à chaque changement de son argument.
	const fetchSites = useCallback(
		( value ) => {
			apiFetch( { path: sitesPath( { search: value.trim() } ) } )
				.then( ( sites ) => {
					remember( sites );
					setFound( sites );
				} )
				.catch( () => setFound( [] ) );
		},
		[ remember ]
	);
	const search = useDebounce( fetchSites, 300 );
	useEffect( () => {
		search( '' );
		return () => search.cancel();
	}, [ search ] );

	return { known, suggestions: found.map( tokenLabel ), search };
}

export default function Edit( { attributes, setAttributes } ) {
	const include = attributes.include || [];
	const exclude = attributes.exclude || [];
	const { known, suggestions, search } = useSites( [
		...include,
		...exclude,
	] );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Sites', 'multisite-radar' ) }>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Only these sites', 'multisite-radar' ) }
						value={ idsToTokens( include, known ) }
						suggestions={ suggestions }
						onInputChange={ search }
						onChange={ ( tokens ) =>
							setAttributes( {
								include: tokensToIds( tokens, known ),
							} )
						}
					/>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Hide these sites', 'multisite-radar' ) }
						value={ idsToTokens( exclude, known ) }
						suggestions={ suggestions }
						onInputChange={ search }
						onChange={ ( tokens ) =>
							setAttributes( {
								exclude: tokensToIds( tokens, known ),
							} )
						}
					/>
				</PanelBody>
```

La suite de `edit.jsx` reste identique : le panneau « Display » et le rendu `ServerSideRender`.

Run: `npx wp-scripts test-unit-js src/blocks/sites-list/test/tokens.test.js && npm run lint:js && npm run build`
Expected: PASS, lint sans erreur, build sans erreur. L'étape 8 vérifie dans le navigateur qu'aucune boucle de requêtes n'a lieu.

- [ ] **Step 8: E2E — choisir un site dans l'éditeur**

Dans `tests/e2e/specs/sites-menu.spec.js` :
- dans la déstructuration des fixtures, ajouter `admin` et `editor` aux tests qui en ont besoin ;
- ajouter ce test à la fin du `describe` :

```js
	test( 'the block settings find a site by name and keep its name once chosen', async ( {
		admin,
		editor,
		page,
	} ) => {
		const requests = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'sites-menu' ) ) {
				requests.push( request.url() );
			}
		} );
		await admin.createNewPost();
		await editor.insertBlock( { name: 'multisite-radar/sites-list' } );
		await editor.openDocumentSettingsSidebar();

		const only = page.getByRole( 'combobox', { name: 'Only these sites' } );
		await only.fill( 'Blog' );
		const option = page.getByRole( 'option', { name: /^Blog RH \(#\d+\)$/ } );
		await expect( option ).toBeVisible();
		await option.click();

		await expect(
			page.locator( '.components-form-token-field__token-text', { hasText: /^Blog RH \(#\d+\)$/ } )
		).toBeVisible();
		await expect(
			editor.canvas.locator( '.wp-block-multisite-radar-sites-list' ).getByRole( 'link', { name: 'Blog RH' } )
		).toBeVisible();
		// Une recherche par saisie, pas une par rendu : le nombre de requêtes reste petit.
		expect( requests.length ).toBeLessThan( 10 );
	} );
```

Run: `npm run test:e2e -- tests/e2e/specs/sites-menu.spec.js`
Expected: PASS (2 tests). Si le rendu du bloc dans le canevas ne contient pas de lien (aperçu serveur), vérifier seulement le jeton.

- [ ] **Step 9: Non-régression**

Run: `bin/test.sh && npm run test:unit && npm run lint:js && composer lint && composer analyse`
Expected: seuls les 11 échecs PHP connus ; le reste passe.

- [ ] **Step 10: Commit**

```bash
git add includes/Rest/SitesMenuController.php includes/Plugin.php includes/SitesMenu/Block.php includes/SitesMenu/SitesListCache.php tests/php/Rest/SitesMenuControllerTest.php tests/php/SitesMenu/SitesListCacheTest.php src/blocks/sites-list tests/e2e/specs/sites-menu.spec.js
git commit -m "perf: load the sites of the network sites block on demand"
```

---

### Task 5: Graphiques et journal — contraste, point isolé, séries vides, page, squelette

Points de M6 (section 6 du document des suites) qui touchent l'utilisateur :
- **Contraste.** Les tons « information » (`#72aee6`, 2,4:1) et « avertissement » (`#dba617`, 2,0:1) sont sous 3:1 sur blanc (WCAG 1.4.11). Les nouveaux tons viennent de la palette WordPress :

  | Ton | Couleur | Contraste sur blanc | Trait |
  |---|---|---|---|
  | `info` | `#3582c4` | 4,1:1 | tirets |
  | `warning` | `#996800` | 4,8:1 | pointillés |
  | `error` | `#d63638` | inchangé, 4,7:1 | plein |
  | `accent` | couleur de l'admin | inchangé | plein |

  Le motif de trait distingue les séries sans passer par la seule couleur (WCAG 1.4.1, écart E9). La légende montre le trait de chaque série.
- **Point isolé.** Il est dessiné par un `<circle>` dans un SVG `preserveAspectRatio="none"`, et devient donc une ellipse. À la place : un chemin de longueur nulle à bouts ronds, avec `vector-effect: non-scaling-stroke`, qui reste rond.
- **Séries entièrement nulles.** Quand toutes les valeurs de toutes les séries sont nulles, le graphique est vide (exemple : la mesure du disque est désactivée). Il laisse alors place à un message.
- **Journal.**
  - Une liste vide avec un filtre dit « No change recorded yet. », comme si rien n'avait été noté : elle dira « No change of this kind recorded yet. ».
  - La page n'est pas remise à 1 quand la prop `site` change.
- **Vue d'ensemble.** La carte « Alerts, last 30 days » n'affiche rien pendant le chargement : elle affichera un squelette.

**Files:**
- Modify: `src/components/trend-chart.jsx`, `src/components/events-list.jsx`, `src/views/overview/history.jsx`, `src/admin/style.scss`
- Test: `src/components/test/trend-chart.test.jsx`, `src/components/test/events-list.test.jsx`, `src/views/overview/test/overview.test.jsx`, `src/components/test/trend-tones.test.js` (nouveau)

**Interfaces:**
- Consumes : `Skeleton( { lines, label } )` (`src/components/skeleton.jsx`) ; la chaîne existante `Loading the trends…`.
- Produces : classes CSS `msradar-trend__point`, `msradar-trend__point--<ton>`, `msradar-trend__swatch` (un `<svg>`) ; `msradar-trend__dot--*` disparaît.

- [ ] **Step 1: Test qui échoue — contraste des tons**

Créer `src/components/test/trend-tones.test.js` :

```js
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const scss = readFileSync(
	fileURLToPath( new URL( '../../admin/style.scss', import.meta.url ) ),
	'utf8'
);
const start = scss.indexOf( '$msradar-tones:' );
const tones = scss.slice( start, scss.indexOf( ');', start ) );

function channel( value ) {
	const c = value / 255;
	return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
}

function luminance( hex ) {
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( index ) =>
		channel( parseInt( hex.slice( index, index + 2 ), 16 ) )
	);
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

test.each( [ 'info', 'warning', 'error' ] )(
	'the %s tone of the charts has a contrast of at least 3:1 on white (WCAG 1.4.11)',
	( tone ) => {
		const match = new RegExp( `${ tone }: (#[0-9a-f]{6})`, 'i' ).exec(
			tones
		);
		expect( match ).not.toBeNull();
		expect( 1.05 / ( luminance( match[ 1 ] ) + 0.05 ) ).toBeGreaterThanOrEqual(
			3
		);
	}
);
```

Run: `npx wp-scripts test-unit-js src/components/test/trend-tones.test.js`
Expected: FAIL pour `info` et `warning`.

- [ ] **Step 2: Tests qui échouent — graphique**

Dans `src/components/test/trend-chart.test.jsx` :
1. Dans le test `a line is broken where a day or a value is missing`, les deux sélecteurs `circle.msradar-trend__dot--accent` et `circle.msradar-trend__dot--info` deviennent `path.msradar-trend__point--accent` et `path.msradar-trend__point--info`. Les nombres attendus ne changent pas.
2. Ajouter :

```jsx
test( 'an isolated point stays round: a zero-length path with round caps, not a circle', () => {
	const { container } = render(
		<TrendChart title="Content" points={ POINTS } series={ SERIES } />
	);

	const point = container.querySelector( 'path.msradar-trend__point--accent' );
	expect( point.getAttribute( 'd' ) ).toMatch( /^M[\d.]+ [\d.]+h0$/ );
	expect( point ).toHaveAttribute( 'vector-effect', 'non-scaling-stroke' );
	expect( container.querySelector( 'circle' ) ).toBeNull();
} );

test( 'the legend shows the stroke of each series', () => {
	const { container } = render(
		<TrendChart title="Content" points={ POINTS } series={ SERIES } />
	);

	const swatches = container.querySelectorAll(
		'.msradar-trend__legend svg.msradar-trend__swatch line'
	);
	expect( [ ...swatches ].map( ( line ) => line.getAttribute( 'class' ) ) ).toEqual( [
		'msradar-trend__line msradar-trend__line--accent',
		'msradar-trend__line msradar-trend__line--info',
	] );
} );

test( 'when every value is missing, a message replaces the empty chart', () => {
	render(
		<TrendChart
			title="Disk"
			points={ [
				{ day: '2026-09-01', disk_bytes: null },
				{ day: '2026-09-02', disk_bytes: null },
			] }
			series={ [ { key: 'disk_bytes', label: 'Disk' } ] }
		/>
	);

	expect( screen.getByText( 'No figures for this period.' ) ).toBeInTheDocument();
	expect( screen.queryByRole( 'img' ) ).toBeNull();
} );
```

Run: `npx wp-scripts test-unit-js src/components/test/trend-chart.test.jsx`
Expected: FAIL (4 tests).

- [ ] **Step 3: Implémenter le graphique**

Dans `src/components/trend-chart.jsx` :

1. Ajouter, avant `export default function TrendChart` :

```jsx
function EmptyChart( { title, message } ) {
	return (
		<figure className="msradar-trend">
			<figcaption className="msradar-trend__title">{ title }</figcaption>
			<p className="msradar-trend__empty">{ message }</p>
		</figure>
	);
}
```

2. Remplacer le premier `if ( points.length < 2 ) { return ( <figure …> … </figure> ); }` par :

```jsx
	if ( points.length < 2 ) {
		return (
			<EmptyChart
				title={ title }
				message={ __(
					'Not enough history yet: the chart appears after two daily snapshots.',
					'multisite-radar'
				) }
			/>
		);
	}
	// Mesure désactivée (disque) ou jamais relevée : pas de courbe à tracer.
	const hasValue = points.some( ( point ) =>
		series.some(
			( item ) =>
				point[ item.key ] !== null && point[ item.key ] !== undefined
		)
	);
	if ( ! hasValue ) {
		return (
			<EmptyChart
				title={ title }
				message={ __( 'No figures for this period.', 'multisite-radar' ) }
			/>
		);
	}
```

3. Dans le rendu des morceaux, remplacer le `<circle … r="3" />` par :

```jsx
									<path
										key={ `${ item.key }-${ index }` }
										className={ `msradar-trend__point msradar-trend__point--${ tone }` }
										d={ `M${ x( part[ 0 ].t ) } ${ y(
											part[ 0 ].value
										) }h0` }
										vectorEffect="non-scaling-stroke"
									/>
```

4. Dans la légende, remplacer le `<span className={ \`msradar-trend__swatch …\` } aria-hidden="true" />` par :

```jsx
						<svg
							className="msradar-trend__swatch"
							width="24"
							height="8"
							viewBox="0 0 24 8"
							aria-hidden="true"
							focusable="false"
						>
							<line
								className={ `msradar-trend__line msradar-trend__line--${
									item.tone || 'accent'
								}` }
								x1="0"
								y1="4"
								x2="24"
								y2="4"
							/>
						</svg>
```

Dans `src/admin/style.scss`, remplacer tout ce qui va de `.msradar-trend__line {` jusqu'à la fin de la boucle `@each $tone, $color in $msradar-tones { … }` par :

```scss
.msradar-trend__line {
	fill: none;
	stroke-width: 2;
}

// Un point isolé : chemin de longueur nulle à bouts ronds, qui reste rond malgré preserveAspectRatio="none".
.msradar-trend__point {
	stroke-width: 6;
	stroke-linecap: round;
}

.msradar-trend__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 8px 0;
}

.msradar-trend__swatch {
	display: inline-block;
	margin-right: 4px;
	vertical-align: middle;
}

// Tons des séries : contraste d'au moins 3:1 sur blanc (WCAG 1.4.11, vérifié par src/components/test/trend-tones.test.js)
// et un motif de trait par ton, pour que la couleur ne soit pas le seul moyen de distinguer les séries (WCAG 1.4.1).
$msradar-tones: (
	accent: var(--wp-admin-theme-color),
	info: #3582c4,
	warning: #996800,
	error: #d63638,
);

$msradar-dashes: (
	info: 6 4,
	warning: 2 3,
);

@each $tone, $color in $msradar-tones {
	.msradar-trend__line--#{$tone},
	.msradar-trend__point--#{$tone} {
		stroke: $color;
	}
}

@each $tone, $dash in $msradar-dashes {
	.msradar-trend__line--#{$tone} {
		stroke-dasharray: $dash;
	}
}
```

Run: `npx wp-scripts test-unit-js src/components/test/trend-chart.test.jsx src/components/test/trend-tones.test.js && npm run lint:css`
Expected: PASS.

- [ ] **Step 4: Tests qui échouent — journal et Vue d'ensemble**

Ajouter à `src/components/test/events-list.test.jsx` :

```jsx
test( 'with a filter and no change, the list says that none of this kind was recorded', async () => {
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/events?page=1&per_page=20': {
				body: EVENTS,
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
			'/multisite-radar/v1/events?page=1&per_page=20&type=site_deleted': {
				body: [],
				headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
			},
		} )
	);
	render(
		<RegistryProvider value={ registry }>
			<EventsList />
		</RegistryProvider>
	);

	fireEvent.change(
		screen.getByRole( 'combobox', { name: 'Kind of change' } ),
		{ target: { value: 'site_deleted' } }
	);

	expect(
		await screen.findByText( 'No change of this kind recorded yet.' )
	).toBeInTheDocument();
} );

test( 'the list of another site starts again from the first page', async () => {
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/events?page=1&per_page=20&site=5': {
				body: EVENTS.slice( 1 ),
				headers: { 'X-WP-Total': '30', 'X-WP-TotalPages': '2' },
			},
		} )
	);
	const view = ( site ) => (
		<RegistryProvider value={ registry }>
			<EventsList site={ site } />
		</RegistryProvider>
	);
	const { rerender } = render( view( 5 ) );

	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	await waitFor( () => {
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/multisite-radar/v1/events?page=2&per_page=20&site=5',
			} )
		);
	} );

	rerender( view( 6 ) );
	await waitFor( () => {
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				path: '/multisite-radar/v1/events?page=1&per_page=20&site=6',
			} )
		);
	} );
} );
```

Le chemin attendu suit l'ordre des paramètres de `buildPath()` (`page`, `per_page`, `site`, `type`). Si `buildPath` les trie autrement, aligner les chemins du test sur sa sortie réelle, et non l'inverse.

Dans `src/views/overview/test/overview.test.jsx` :
- `renderView` reçoit une option `preloadTrends = true` ; avec `false`, la clé `'/multisite-radar/v1/reports/trends?days=30'` est retirée du préchargement, comme `preloadInventory` ;
- ajouter :

```jsx
test( 'the alerts of the last 30 days show a skeleton while they load', () => {
	renderView( { preloadTrends: false } );

	expect( screen.getByText( 'Loading the trends…' ) ).toBeInTheDocument();
} );
```

Run: `npx wp-scripts test-unit-js src/components/test/events-list.test.jsx src/views/overview/test/overview.test.jsx`
Expected: FAIL (3 tests).

- [ ] **Step 5: Implémenter le journal et le squelette**

Dans `src/components/events-list.jsx` :

1. Remplacer `const [ page, setPage ] = useState( 1 );` par :

```jsx
	// La page suit le site affiché : un autre site repart de la première page, sans lire la page de l'ancien.
	const [ paging, setPaging ] = useState( { site, page: 1 } );
	const page = paging.site === site ? paging.page : 1;
	const setPage = ( next ) => setPaging( { site, page: next } );
```

2. Remplacer la branche des données vides par :

```jsx
	} else if ( events.data.length === 0 ) {
		body = (
			<p>
				{ type
					? __( 'No change of this kind recorded yet.', 'multisite-radar' )
					: __( 'No change recorded yet.', 'multisite-radar' ) }
			</p>
		);
```

Dans `src/views/overview/history.jsx` :
- importer `Skeleton from '../../components/skeleton'` ;
- dans `AlertsTrend`, après `<ErrorNotice … />`, ajouter :

```jsx
				{ ! trends.data && ! trends.error && (
					<Skeleton
						lines={ 4 }
						label={ __( 'Loading the trends…', 'multisite-radar' ) }
					/>
				) }
```

Run: `npx wp-scripts test-unit-js src/components/test src/views/overview/test`
Expected: PASS.

- [ ] **Step 6: Non-régression et contrôle visuel**

Run: `npm run lint:js && npm run lint:css && npm run test:unit && npm run build && npm run test:e2e -- tests/e2e/specs/history.spec.js tests/e2e/specs/a11y.spec.js`
Expected: tout passe.

Contrôle visuel :
1. ouvrir la page Rapports dans wp-env (`http://localhost:8890/wp-admin/network/admin.php?page=multisite-radar-reports`) ;
2. faire une capture avec le Chromium de Playwright ;
3. la lire.

Les traits en tirets et en pointillés doivent se voir, dans le graphique comme dans la légende. S'il manque des relevés pour tracer une courbe, le dire dans le rapport.

- [ ] **Step 7: Commit**

```bash
git add src/components src/views/overview src/admin/style.scss
git commit -m "fix: readable chart tones and strokes, round isolated points and clearer empty lists"
```

---

### Task 6: Récapitulatif, rétention, confidentialité et historique

Points de la revue finale de M6 :
- **Jour sauté.** `wp_date( 'w', $now ) === digest_day` saute la semaine si la tâche quotidienne glisse après minuit (WP-Cron en retard). Écart E8 : le récapitulatif manqué part dans les jours qui suivent, une fois. Un récapitulatif jamais envoyé attend le jour réglé.
- **Rétention courte.** Avec une rétention des changements inférieure à 7 jours, la purge (priorité 20) ampute le récapitulatif (priorité 30), qui annonce 7 jours. Tant que le récapitulatif est actif, la purge garde au moins 7 jours, et le réglage le dit.
- **Confidentialité.** Les adresses des destinataires sont lisibles dans `GET /settings` et `wp multisite-radar settings get` par les administrateurs qui gèrent les réglages : le texte de confidentialité le dit.
- **Un seul `try`.** `History::daily()` met la capture et les deux purges dans un seul `try` : une table de relevés abîmée saute la purge du journal. Chaque étape aura le sien.

**Files:**
- Modify: `includes/Reports/Digest.php`, `includes/Scan/History.php`, `includes/Admin/Privacy.php`, `src/views/settings/fields.js`
- Test: `tests/php/Reports/DigestTest.php`, `tests/php/Scan/HistoryTest.php`

**Interfaces:**
- Produces :
  - `Digest::DAYS` devient **public** (7) ;
  - `Digest::due_day( int $now ): ?string` ;
  - l'option `msradar_digest_sent` garde le **jour réglé** dont le récapitulatif est parti (Y-m-d, fuseau du site principal). C'était déjà le cas quand il partait le jour même.

- [ ] **Step 1: Tests qui échouent — récapitulatif**

Ajouter à `tests/php/Reports/DigestTest.php`, après `test_each_recipient_gets_its_own_e_mail_once_on_the_chosen_day` :

```php
	public function test_a_digest_missed_on_its_day_goes_out_once_on_the_next_days(): void {
		$this->enable();
		update_site_option( Digest::SENT_OPTION, '2026-09-09' ); // Le mercredi précédent.

		// La tâche du mercredi 16 a glissé au jeudi 17, 00:30 ; elle repasse le vendredi.
		$this->plugin()->digest()->maybe_send( self::NOW + 12 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
		$this->plugin()->digest()->maybe_send( self::NOW + 2 * DAY_IN_SECONDS );

		$this->assertCount( 2, $this->sent(), 'One e-mail per recipient, once.' );
		$this->assertSame( '2026-09-16', get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_a_digest_never_sent_waits_for_its_day(): void {
		$this->enable();

		$this->plugin()->digest()->maybe_send( self::NOW + DAY_IN_SECONDS ); // Jeudi.
		$this->plugin()->digest()->maybe_send( self::NOW + 6 * DAY_IN_SECONDS ); // Mardi.
		$this->assertSame( [], $this->sent() );

		$this->plugin()->digest()->maybe_send( self::NOW + 7 * DAY_IN_SECONDS ); // Mercredi suivant.
		$this->assertCount( 2, $this->sent() );
		$this->assertSame( '2026-09-23', get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_due_day_follows_the_chosen_day(): void {
		$this->enable();

		$this->assertSame( '2026-09-16', $this->plugin()->digest()->due_day( self::NOW ) );
		$this->assertNull( $this->plugin()->digest()->due_day( self::NOW + DAY_IN_SECONDS ), 'Never sent: only on the chosen day.' );

		update_site_option( Digest::SENT_OPTION, '2026-09-16' );
		$this->assertNull( $this->plugin()->digest()->due_day( self::NOW + 3 * DAY_IN_SECONDS ) );
		$this->assertSame( '2026-09-23', $this->plugin()->digest()->due_day( self::NOW + 7 * DAY_IN_SECONDS ) );
	}
```

Run: `bin/test.sh --filter DigestTest`
Expected: FAIL. Le jeudi n'envoie rien ; `due_day` n'existe pas.

- [ ] **Step 2: Implémenter le récapitulatif**

Dans `includes/Reports/Digest.php` :

1. Remplacer `private const DAYS       = 7;` par :

```php
	/**
	 * Jours couverts par le récapitulatif. History::daily() garde au moins ces jours de changements tant que le
	 * récapitulatif est actif.
	 */
	public const DAYS = 7;

	private const MAX_ALERTS = 20;
```

et supprimer l'ancienne ligne `private const MAX_ALERTS = 20;`.

2. Remplacer `maybe_send()` et son docblock par :

```php
	/**
	 * Sur la tâche quotidienne : le récapitulatif du jour réglé, une seule fois (écart E8 du plan M7). Non typé :
	 * WordPress appelle les hooks avec un argument vide.
	 *
	 * @param mixed $now Horodatage Unix (tests) ; maintenant sinon.
	 */
	public function maybe_send( $now = null ): void {
		$now = is_int( $now ) ? $now : time();
		if ( ! (bool) $this->settings->get( 'reports.digest_enabled', false ) ) {
			return;
		}
		$due = $this->due_day( $now );
		if ( null === $due ) {
			return;
		}
		$recipients = $this->recipients();
		if ( [] === $recipients ) {
			return;
		}
		try {
			$sent = $this->send( $recipients, $now );
		} catch ( \Throwable $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return;
		}
		if ( $sent ) {
			update_site_option( self::SENT_OPTION, $due );
		}
	}

	/**
	 * Jour réglé (Y-m-d, fuseau du site principal) dont le récapitulatif reste à envoyer, ou null : le dernier jour réglé,
	 * aujourd'hui compris, si rien n'est parti pour lui. Une tâche quotidienne qui glisse après minuit (WP-Cron en
	 * retard) l'envoie donc le lendemain. Un récapitulatif jamais envoyé n'attend que le jour réglé : activer le réglage
	 * un autre jour n'envoie rien tout de suite.
	 *
	 * @param int $now Horodatage Unix.
	 */
	public function due_day( int $now ): ?string {
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		$late  = ( (int) $today->format( 'w' ) - (int) $this->settings->get( 'reports.digest_day', 1 ) + 7 ) % 7;
		$due   = $today->sub( new \DateInterval( 'P' . $late . 'D' ) )->format( 'Y-m-d' );
		$sent  = (string) get_site_option( self::SENT_OPTION, '' );
		if ( '' === $sent ) {
			return 0 === $late ? $due : null;
		}
		return $sent < $due ? $due : null;
	}
```

`sub()` compte en jours du calendrier dans le fuseau du site : un changement d'heure ne décale pas la date. `modify()` est écarté parce qu'il peut renvoyer `false`, que PHPStan refuserait.

Run: `bin/test.sh --filter DigestTest`
Expected: PASS.

- [ ] **Step 3: Tests qui échouent — historique**

Dans `tests/php/Scan/HistoryTest.php` :

1. Ajouter `use MultisiteRadar\Reports\Digest;` aux `use`, puis cette méthode d'aide :

```php
	/**
	 * @return string[] Sujets des changements du site, du plus récent au plus ancien.
	 */
	private function subjects( int $site_id ): array {
		return array_column(
			$this->plugin()->events()->query(
				[
					'network_id' => get_current_network_id(),
					'since'      => null,
					'types'      => [],
					'site_id'    => $site_id,
					'page'       => 1,
					'per_page'   => 20,
				]
			)['items'],
			'subject'
		);
	}

	private function insert_event( int $site_id, string $subject, int $age_in_days ): void {
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => get_current_network_id(),
					'site_id'    => $site_id,
					'type'       => 'theme_switched',
					'subject'    => $subject,
					'meta'       => [],
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - $age_in_days * DAY_IN_SECONDS ),
				],
			]
		);
	}
```

2. Ajouter :

```php
	public function test_while_the_digest_is_on_the_changes_of_its_days_are_kept(): void {
		$this->insert_event( 4504, 'five-days-ago', 5 );
		$this->plugin()->settings()->update(
			[
				'retention' => [ 'events_days' => 3 ],
				'reports'   => [ 'digest_enabled' => true ],
			]
		);

		$this->plugin()->history()->daily( self::NOW );
		$this->assertSame( [ 'five-days-ago' ], $this->subjects( 4504 ), 'Kept: the digest covers ' . Digest::DAYS . ' days.' );

		$this->plugin()->settings()->update( [ 'reports' => [ 'digest_enabled' => false ] ] );
		$this->plugin()->history()->daily( self::NOW );
		$this->assertSame( [], $this->subjects( 4504 ) );
	}

	public function test_a_broken_snapshots_table_does_not_stop_the_purge_of_the_changes(): void {
		global $wpdb;
		$this->insert_event( 4505, 'too-old', 100 );
		$ignore   = static function (): void {};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_snapshots' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $ignore );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->history()->daily( self::NOW );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $ignore );
		}

		$this->assertSame( [], $this->subjects( 4505 ) );
	}
```

3. Dans `test_a_storage_failure_is_reported_and_never_thrown`, chaque étape peut maintenant échouer et être signalée à part. L'assertion finale devient :

```php
		$this->assertNotSame( [], $reported );
		$this->assertSame( [ History::class . '::daily' ], array_values( array_unique( $reported ) ) );
```

Run: `bin/test.sh --filter HistoryTest`
Expected: FAIL (`test_while_the_digest…` et `test_a_broken_snapshots_table…`).

- [ ] **Step 4: Implémenter l'historique**

Dans `includes/Scan/History.php` :
- ajouter `use MultisiteRadar\Reports\Digest;` ;
- remplacer le corps de `daily()`, de la ligne `$events = …` jusqu'à la fin du `try`/`catch`, par :

```php
		$events     = max( 1, (int) $this->settings->get( 'retention.events_days', 90 ) );
		if ( (bool) $this->settings->get( 'reports.digest_enabled', false ) ) {
			// Le récapitulatif (priorité 30) lit les changements de ses derniers jours : la purge (priorité 20) les garde.
			$events = max( Digest::DAYS, $events );
		}
		// Une étape par try : une table de relevés abîmée n'empêche pas la purge du journal.
		self::attempt( fn () => $this->snapshots->capture( $network_id, gmdate( 'Y-m-d', $now ) ) );
		self::attempt( fn () => $this->snapshots->purge( $network_id, gmdate( 'Y-m-d', $now - $snapshots * DAY_IN_SECONDS ) ) );
		self::attempt( fn () => $this->events->purge( $network_id, gmdate( 'Y-m-d H:i:s', $now - $events * DAY_IN_SECONDS ) ) );
	}

	/**
	 * @param callable $step Étape de la tâche quotidienne ; une erreur est signalée, jamais levée.
	 */
	private static function attempt( callable $step ): void {
		try {
			$step();
		} catch ( \Throwable $error ) {
			do_action( 'msradar_error', __CLASS__ . '::daily', $error );
		}
	}
```

Mettre à jour le docblock de la classe : « … puis purge des relevés et des événements au-delà de la rétention réglée (au moins les jours du récapitulatif tant qu'il est actif). Chaque étape est indépendante ; aucune ne fait échouer la tâche. »

Run: `bin/test.sh --filter "HistoryTest|DigestTest"`
Expected: PASS.

- [ ] **Step 5: Textes — réglage de rétention et confidentialité**

Dans `src/views/settings/fields.js`, la description de `retention.events_days` devient :

```js
			description: __(
				'Older changes are deleted every day. While the weekly e-mail summary is on, the changes of its last 7 days are always kept.',
				'multisite-radar'
			),
```

Dans `includes/Admin/Privacy.php`, la dernière phrase de `text()` :

> If the weekly e-mail summary is enabled, the addresses of its recipients are kept in the plugin settings and used only to send it.

devient :

> If the weekly e-mail summary is enabled, the addresses of its recipients are kept in the plugin settings and used only to send it; the network administrators who can change these settings can read them.

Run: `bin/test.sh --filter PrivacyTest && npx wp-scripts test-unit-js src/views/settings`
Expected: PASS.

- [ ] **Step 6: Non-régression**

Run: `bin/test.sh && composer lint && composer analyse && npm run lint:js`
Expected: seuls les 11 échecs PHP connus.

- [ ] **Step 7: Commit**

```bash
git add includes/Reports/Digest.php includes/Scan/History.php includes/Admin/Privacy.php src/views/settings/fields.js tests/php/Reports/DigestTest.php tests/php/Scan/HistoryTest.php
git commit -m "fix: send a late weekly summary once, keep its week of changes and isolate the daily history steps"
```

---

### Task 7: Accessibilité — onglets de la fiche, widget, ticket amont

Point « avant la 2.0 » (section 1).
- **Onglet par défaut seulement.** L'audit de la fiche d'un site ne couvre que l'onglet par défaut. Il couvrira les six : Summary, Content, Users, Extensions, Alerts, History.
- **Widget non audité.** Le widget du tableau de bord réseau, ajouté en M6, n'est pas audité.
- **Exclusion sans référence.** L'exclusion du champ caché de DataViews n'a pas de référence amont. Le défaut est suivi par [WordPress/gutenberg#76741](https://github.com/WordPress/gutenberg/issues/76741), avec un correctif proposé : [WordPress/gutenberg#81305](https://github.com/WordPress/gutenberg/pull/81305).

**Files:**
- Modify: `tests/e2e/specs/a11y.spec.js`

**Interfaces:** aucune.

- [ ] **Step 1: Paramétrer la zone auditée et citer le ticket**

Dans `tests/e2e/specs/a11y.spec.js`, `seriousViolations` prend la zone à auditer. Le commentaire de l'exclusion cite le ticket :

```js
async function seriousViolations( page, area = '.msradar-wrap' ) {
	const results = await new AxeBuilder( { page } )
		.include( area )
		// @wordpress/dataviews 19.1 ajoute à un FormTokenField validé (page Réglages) un champ texte invisible
		// (opacity 0, tabindex -1) sans libellé, que la règle « label » signale. Ce nœud n'est pas dans notre code :
		// on n'exclut que lui, sur toutes les pages auditées, jamais la règle. Défaut amont :
		// https://github.com/WordPress/gutenberg/issues/76741 (correctif proposé :
		// https://github.com/WordPress/gutenberg/pull/81305). Retirer l'exclusion dès qu'une version de DataViews
		// corrigée est épinglée (spec §14).
		.exclude( '.dataviews-validated-control__error-delegate' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
```

La suite de la fonction ne change pas.

- [ ] **Step 2: Auditer les six onglets de la fiche**

Remplacer le test `no serious or critical violation with the site panel open` par :

```js
	test( 'no serious or critical violation on any tab of the site panel', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Blog RH', { exact: true } )
			.click();
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toBeVisible();

		for ( const name of [
			'Summary',
			'Content',
			'Users',
			'Extensions',
			'Alerts',
			'History',
		] ) {
			const tab = dialog.getByRole( 'tab', { name } );
			await tab.click();
			await expect( tab ).toHaveAttribute( 'aria-selected', 'true' );
			await expect( dialog.locator( '.msradar-skeleton' ) ).toHaveCount(
				0
			);
			expect( await seriousViolations( page ), `tab ${ name }` ).toEqual(
				[]
			);
		}
	} );
```

- [ ] **Step 3: Auditer le widget du tableau de bord réseau**

Ajouter à la fin du `describe` :

```js
	test( 'no serious or critical violation in the network dashboard widget', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/index.php' );
		await expect( page.locator( '#msradar_summary' ) ).toBeVisible();

		expect(
			await seriousViolations( page, '#msradar_summary' )
		).toEqual( [] );
	} );
```

- [ ] **Step 4: Lancer l'audit**

Run: `npm run test:e2e -- tests/e2e/specs/a11y.spec.js`
Expected: PASS. Si un onglet ou le widget a une violation « serious » ou « critical » **dans notre code** :
1. la corriger dans cette tâche (composant ou PHP du widget) ;
2. ajouter un test unitaire qui la couvre quand c'est possible ;
3. la décrire dans le rapport.

N'exclure aucun nœud de plus.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/specs/a11y.spec.js
git commit -m "test: audit every tab of the site panel and the dashboard widget, and cite the DataViews ticket"
```

Si l'étape 4 a corrigé du code, ajouter ces fichiers au même commit, avec le message `fix: <violation> in <zone>, found by the accessibility audit`.

---

### Task 8: Identité visuelle — icône, bannière, icône du menu

Décision de l'utilisateur (2026-10-05) : un radar sobre aux couleurs de l'admin WordPress.
- Couleurs : `#3858e9` (bleu), `#1e1e1e` (fond), blanc ; un point d'alerte en `#f0b849`.
- Formats WordPress.org, dans le dossier `assets` du SVN :
  - `icon.svg`, `icon-128x128.png`, `icon-256x256.png` ;
  - `banner-772x250.png`, `banner-1544x500.png`.
- Les sources SVG et le script de rendu vivent dans `bin/wporg-assets/`. Les fichiers produits vont dans `.wordpress-org/`, jamais dans le zip.
- L'icône du menu d'administration passe de `dashicons-chart-area` au même radar, en une couleur que l'admin recolore.

**Files:**
- Create: `bin/wporg-assets/icon.svg`, `bin/wporg-assets/banner.svg`, `bin/wporg-assets/menu-icon.svg`, `bin/wporg-assets/render.mjs`, `.wordpress-org/icon.svg`, `.wordpress-org/icon-128x128.png`, `.wordpress-org/icon-256x256.png`, `.wordpress-org/banner-772x250.png`, `.wordpress-org/banner-1544x500.png`
- Modify: `package.json` (script `wporg:assets`), `.distignore`, `includes/Admin/Menu.php`, `tests/php/Admin/MenuTest.php`

**Interfaces:**
- Produces :
  - `npm run wporg:assets` ;
  - le dossier `.wordpress-org/`, que les tâches 9 (captures), 10 (contrôle du readme) et 11 (déploiement) utilisent ;
  - `Menu::ICON`, une URI `data:image/svg+xml;base64,…`.

- [ ] **Step 1: Créer `bin/wporg-assets/icon.svg`**

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256">
	<title>Multisite Radar</title>
	<defs>
		<linearGradient id="msradar-sweep" gradientUnits="userSpaceOnUse" x1="128" y1="28" x2="214.6" y2="78">
			<stop offset="0" stop-color="#3858e9" stop-opacity="0"/>
			<stop offset="1" stop-color="#3858e9" stop-opacity="0.6"/>
		</linearGradient>
	</defs>
	<rect width="256" height="256" fill="#1e1e1e"/>
	<g fill="none" stroke="#3858e9">
		<circle cx="128" cy="128" r="100" stroke-width="6"/>
		<circle cx="128" cy="128" r="66" stroke-width="4" stroke-opacity="0.7"/>
		<circle cx="128" cy="128" r="32" stroke-width="4" stroke-opacity="0.5"/>
		<path d="M128 28v200M28 128h200" stroke-width="2" stroke-opacity="0.35"/>
	</g>
	<path d="M128 128V28a100 100 0 0 1 86.6 50z" fill="url(#msradar-sweep)"/>
	<path d="M128 128l86.6-50" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
	<circle cx="176" cy="92" r="10" fill="#ffffff"/>
	<circle cx="86" cy="86" r="8" fill="#f0b849"/>
	<circle cx="92" cy="166" r="8" fill="#3858e9"/>
	<circle cx="168" cy="176" r="8" fill="#3858e9"/>
	<circle cx="128" cy="128" r="8" fill="#ffffff"/>
</svg>
```

- [ ] **Step 2: Créer `bin/wporg-assets/banner.svg`**

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1544 500" width="1544" height="500">
	<title>Multisite Radar</title>
	<defs>
		<linearGradient id="msradar-background" x1="0" y1="0" x2="1" y2="1">
			<stop offset="0" stop-color="#1e1e1e"/>
			<stop offset="1" stop-color="#101517"/>
		</linearGradient>
		<linearGradient id="msradar-sweep" gradientUnits="userSpaceOnUse" x1="1240" y1="-50" x2="1499.8" y2="100">
			<stop offset="0" stop-color="#3858e9" stop-opacity="0"/>
			<stop offset="1" stop-color="#3858e9" stop-opacity="0.55"/>
		</linearGradient>
	</defs>
	<rect width="1544" height="500" fill="url(#msradar-background)"/>
	<g fill="none" stroke="#3858e9">
		<circle cx="1240" cy="250" r="300" stroke-width="8"/>
		<circle cx="1240" cy="250" r="200" stroke-width="6" stroke-opacity="0.7"/>
		<circle cx="1240" cy="250" r="100" stroke-width="6" stroke-opacity="0.5"/>
		<path d="M1240 -50v600M940 250h604" stroke-width="3" stroke-opacity="0.35"/>
	</g>
	<path d="M1240 250V-50a300 300 0 0 1 259.8 150z" fill="url(#msradar-sweep)"/>
	<path d="M1240 250l259.8-150" stroke="#ffffff" stroke-width="8" stroke-linecap="round"/>
	<circle cx="1384" cy="142" r="16" fill="#ffffff"/>
	<circle cx="1112" cy="124" r="12" fill="#f0b849"/>
	<circle cx="1130" cy="364" r="12" fill="#3858e9"/>
	<circle cx="1360" cy="382" r="12" fill="#3858e9"/>
	<circle cx="1240" cy="250" r="14" fill="#ffffff"/>
	<g font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif">
		<text x="96" y="232" fill="#ffffff" font-size="96" font-weight="600">Multisite Radar</text>
		<text x="98" y="300" fill="#c3c4c7" font-size="36">Audit every site of your WordPress network</text>
	</g>
</svg>
```

- [ ] **Step 3: Créer `bin/wporg-assets/menu-icon.svg`**

Une seule ligne, **sans retour à la ligne final**. Écrire le fichier avec `printf '%s' '<svg …>' > bin/wporg-assets/menu-icon.svg`. Le test de l'étape 6 compare son contenu au contenu décodé de `Menu::ICON`.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 1a9 9 0 1 1 0 18 9 9 0 0 1 0-18zm0 1.75a7.25 7.25 0 1 0 0 14.5 7.25 7.25 0 0 0 0-14.5z"/><path fill="black" d="M10 10V2.75a7.25 7.25 0 0 1 6.28 3.63z"/><circle fill="black" cx="10" cy="10" r="1.75"/><circle fill="black" cx="6" cy="13" r="1.25"/><circle fill="black" cx="13.5" cy="13.5" r="1.25"/></svg>
```

Chaque forme porte son propre `fill` : le script `svg-painter` de l'administration remplace ces valeurs par la couleur du jeu de couleurs de l'utilisateur.

- [ ] **Step 4: Créer `bin/wporg-assets/render.mjs`**

```js
#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Fichiers de WordPress.org (dossier assets du SVN) produits depuis les sources SVG de ce dossier, avec le Chromium de
 * Playwright : icon.svg tel quel, icônes 128 et 256 px, bannières 772×250 et 1544×500, dans .wordpress-org/.
 * Usage : npm run wporg:assets
 */
import { copyFileSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const OUT = join( HERE, '../../.wordpress-org' );
const OUTPUTS = [
	[ 'icon.svg', 'icon-128x128.png', 128, 128 ],
	[ 'icon.svg', 'icon-256x256.png', 256, 256 ],
	[ 'banner.svg', 'banner-772x250.png', 772, 250 ],
	[ 'banner.svg', 'banner-1544x500.png', 1544, 500 ],
];

mkdirSync( OUT, { recursive: true } );
copyFileSync( join( HERE, 'icon.svg' ), join( OUT, 'icon.svg' ) );

const browser = await chromium.launch();
try {
	const page = await browser.newPage();
	for ( const [ source, target, width, height ] of OUTPUTS ) {
		const svg = readFileSync( join( HERE, source ) ).toString( 'base64' );
		await page.setViewportSize( { width, height } );
		await page.setContent(
			`<!doctype html><html><body style="margin:0"><img alt="" width="${ width }" height="${ height }" src="data:image/svg+xml;base64,${ svg }"></body></html>`
		);
		await page.locator( 'img' ).evaluate( ( img ) => img.decode() );
		await page.screenshot( {
			path: join( OUT, target ),
			clip: { x: 0, y: 0, width, height },
		} );
		console.log( `.wordpress-org/${ target }` );
	}
} finally {
	await browser.close();
}
```

Dans `package.json`, ajouter aux `scripts`, après `plugin-zip` :

```json
		"wporg:assets": "node bin/wporg-assets/render.mjs"
```

N'oublier la virgule de la ligne précédente.

Dans `.distignore`, ajouter la ligne `/.wordpress-org` après `/.claude`.

- [ ] **Step 5: Produire les images et les regarder**

Run: `npx playwright install chromium && npm run wporg:assets && ls -l .wordpress-org`
Expected: les 5 fichiers. `file .wordpress-org/*.png` indique `128 x 128`, `256 x 256`, `772 x 250` et `1544 x 500`.

Ouvrir chaque PNG avec l'outil Read (il affiche les images) et vérifier :
- l'icône est lisible à 128 px ;
- dans la bannière, le titre ne touche pas le radar et le texte est entier ;
- aucune police de repli disgracieuse (le texte doit être une sans-serif).

Si le texte de la bannière déborde avec la police disponible, réduire `font-size` du titre par pas de 8 px (minimum 72) et relancer. Décrire en deux phrases le rendu final dans le rapport.

- [ ] **Step 6: Test qui échoue — icône du menu**

Ajouter à `tests/php/Admin/MenuTest.php` :

```php
	public function test_the_menu_icon_is_the_radar_of_the_plugin_icon(): void {
		( new Menu() )->add_pages();

		$entries = array_values(
			array_filter(
				$GLOBALS['menu'],
				static fn ( array $item ): bool => 'multisite-radar' === $item[2]
			)
		);
		$this->assertSame( Menu::ICON, $entries[0][6] );
		$prefix = 'data:image/svg+xml;base64,';
		$this->assertStringStartsWith( $prefix, Menu::ICON );
		$this->assertSame(
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/bin/wporg-assets/menu-icon.svg' ),
			base64_decode( substr( Menu::ICON, strlen( $prefix ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test only.
		);
	}
```

Run: `bin/test.sh --filter test_the_menu_icon_is_the_radar_of_the_plugin_icon`
Expected: FAIL (`Menu::ICON` n'existe pas).

- [ ] **Step 7: Implémenter l'icône du menu**

Calculer la valeur :

```bash
printf 'data:image/svg+xml;base64,%s\n' "$(base64 -w0 bin/wporg-assets/menu-icon.svg)"
```

Dans `includes/Admin/Menu.php`, ajouter aux constantes de la classe (la valeur est une chaîne littérale : un appel à `base64_encode()` ferait signaler le code par Plugin Check) :

```php
	/**
	 * Icône du menu : le radar de l'icône du plugin (bin/wporg-assets/menu-icon.svg), en une couleur que l'administration
	 * remplace par celle du jeu de couleurs de l'utilisateur.
	 */
	public const ICON = 'data:image/svg+xml;base64,<valeur calculée ci-dessus>';
```

Dans `add_pages()`, `'dashicons-chart-area'` devient `self::ICON`.

Run: `bin/test.sh --filter MenuTest && composer lint && composer analyse`
Expected: PASS.

- [ ] **Step 8: Vérifier le paquet et Plugin Check**

Run: `make plugin-check`
Expected: `Plugin Check: 0 error(s), 0 warning(s).` La copie faite par `make plugin-check` (rsync avec `.distignore`) ne contient pas `.wordpress-org/` : `ls -A dist/plugin-check/multisite-radar` ne le montre pas.

- [ ] **Step 9: Commit**

```bash
git add bin/wporg-assets .wordpress-org package.json .distignore includes/Admin/Menu.php tests/php/Admin/MenuTest.php
git commit -m "feat: radar icon, banner and admin menu icon"
```

---

### Task 9: Captures de WordPress.org

Écart E5. Les captures sont prises sur un **réseau de démonstration** de l'environnement de test de wp-env (`tests-cli`, port `WP_ENV_TESTS_PORT`), jamais sur celui des tests E2E. Elles sont régénérées par deux commandes :
- `npm run screenshots:seed` (rejouable) ;
- `npm run screenshots`.

Sept captures, en anglais, à 1280×800. Leurs légendes sont celles de la tâche 10 :

| N° | Écran |
|---|---|
| 1 | Vue d'ensemble |
| 2 | Sites |
| 3 | Fiche d'un site, onglet Content |
| 4 | Alertes |
| 5 | Plugins |
| 6 | Rapports |
| 7 | Réglages, une règle d'alerte ouverte |

**Files:**
- Create: `tests/e2e/screenshots/seed.sh`, `tests/e2e/screenshots/history.php`, `tests/e2e/screenshots/wporg.spec.js`, `playwright.screenshots.config.js`, `.wordpress-org/screenshot-1.png` … `screenshot-7.png`
- Modify: `package.json` (scripts `screenshots:seed`, `screenshots`), `.distignore`

**Interfaces:**
- Consumes :
  - `MultisiteRadar\Plugin::instance()->snapshots()->capture( int $network_id, string $day )` ;
  - `->events()->insert( array $rows )`, avec des lignes `{ network_id, site_id, type, subject, meta, created_at }` ;
  - `Schema::snapshots_table()` et `Schema::events_table()` ;
  - le plugin de démonstration `msradar-demo-cpt` (type `demo_event`, libellé « Demo events »).
- Produces : `.wordpress-org/screenshot-<n>.png`, n = 1 à 7.

- [ ] **Step 1: Créer `tests/e2e/screenshots/seed.sh`**

```sh
#!/bin/sh
# Réseau de démonstration des captures de WordPress.org (npm run screenshots:seed), sur l'environnement de test de
# wp-env, jamais sur celui des tests E2E. Rejouable : un site existant n'est pas recréé, l'historique est réécrit.
set -eu

URL="$(wp option get siteurl)"
URL="${URL%/}"
DIR="$(dirname "$0")"

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

wp multisite-radar scan --all --probe
wp eval-file "$DIR/history.php"
```

- [ ] **Step 2: Créer `tests/e2e/screenshots/history.php`**

Les codes de règle utilisés sont `no_users`, `search_hidden` et `no_admin`. Vérifier qu'ils existent dans `includes/Alerts/Rules/` avant de lancer. Si un code a un autre nom, prendre le vrai.

```php
<?php
/**
 * Historique de démonstration des captures (npm run screenshots:seed) : 31 jours de relevés, dérivés des mesures du
 * jour avec une croissance régulière, et une dizaine de changements datés. Rejouable : réécrit l'historique du réseau.
 *
 * @package MultisiteRadar
 */

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Plugin;

( static function (): void {
	global $wpdb;
	$plugin  = Plugin::instance();
	$network = get_current_network_id();
	$now     = time();
	$today   = gmdate( 'Y-m-d', $now );
	$site    = static function ( string $slug ): int {
		return (int) get_id_from_blogname( $slug );
	};

	for ( $days = 30; $days >= 0; $days-- ) {
		$plugin->snapshots()->capture( $network, gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS ) );
	}
	// Les jours passés ont un peu moins de contenus, de médias et de comptes qu'aujourd'hui.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET content_count = FLOOR( content_count * ( 1 - DATEDIFF( %s, day ) / 60 ) ), media_count = FLOOR( media_count * ( 1 - DATEDIFF( %s, day ) / 45 ) ), users_count = GREATEST( 1, users_count - FLOOR( DATEDIFF( %s, day ) / 12 ) ) WHERE network_id = %d AND day < %s',
			Schema::snapshots_table(),
			$today,
			$today,
			$today,
			$network,
			$today
		)
	);
	// Les alertes de l'Innovation Lab et de l'Intranet sont apparues il y a dix jours.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET alert_level = 0, alerts_count = 0 WHERE network_id = %d AND day < %s AND site_id IN (%d, %d)',
			Schema::snapshots_table(),
			$network,
			gmdate( 'Y-m-d', $now - 10 * DAY_IN_SECONDS ),
			$site( 'lab' ),
			$site( 'intranet' )
		)
	);

	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d', Schema::events_table(), $network ) );
	$changes = [
		[ 'careers', 'site_created', '', [ 'name' => 'Careers' ], 26 ],
		[ 'press', 'theme_switched', 'twentytwentythree', [ 'from' => 'twentytwentyfive' ], 21 ],
		[ 'lab', 'plugin_activated', 'hello.php', [], 12 ],
		[ 'lab', 'alert_raised', 'no_users', [], 10 ],
		[ 'intranet', 'alert_raised', 'search_hidden', [], 10 ],
		[ 'events', 'plugin_activated', 'msradar-demo-cpt/msradar-demo-cpt.php', [], 6 ],
		[ 'hr', 'plugin_activated', 'msradar-demo-cpt/msradar-demo-cpt.php', [], 4 ],
		[ 'engineering', 'theme_switched', 'twentytwentyfive', [ 'from' => 'twentytwentyfour' ], 2 ],
		[ 'support', 'plugin_deactivated', 'hello.php', [], 1 ],
		[ 'marketing', 'alert_resolved', 'no_admin', [], 1 ],
	];
	$rows    = [];
	foreach ( $changes as $change ) {
		$id      = $site( $change[0] );
		$details = get_site( $id );
		$rows[]  = [
			'network_id' => $network,
			'site_id'    => $id,
			'type'       => $change[1],
			'subject'    => 'site_created' === $change[1] && $details ? $details->domain . $details->path : $change[2],
			'meta'       => $change[3],
			'created_at' => gmdate( 'Y-m-d H:i:s', $now - $change[4] * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS ),
		];
	}
	$plugin->events()->insert( $rows );
	WP_CLI::success( sprintf( '31 days of figures and %d changes written.', count( $rows ) ) );
} )();
```

- [ ] **Step 3: Créer la configuration et la spec**

`playwright.screenshots.config.js` :

```js
/**
 * Captures de WordPress.org (npm run screenshots), sur l'environnement de test de wp-env :
 * voir tests/e2e/screenshots/wporg.spec.js.
 */
const path = require( 'path' );
const config = require( './playwright.config.js' );

module.exports = {
	...config,
	testDir: path.join( __dirname, 'tests/e2e/screenshots' ),
	retries: 0,
};
```

`tests/e2e/screenshots/wporg.spec.js` :

```js
/**
 * Captures de WordPress.org (readme.txt, section Screenshots), sur le réseau de démonstration de seed.sh :
 * npm run screenshots:seed, puis npm run screenshots. Écrit .wordpress-org/screenshot-<n>.png.
 */
import path from 'path';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const OUT = path.join( process.cwd(), '.wordpress-org' );

test.use( { viewport: { width: 1280, height: 800 } } );
test.describe.configure( { mode: 'serial' } );

async function shoot( page, number ) {
	await page.mouse.move( 0, 0 );
	await page.screenshot( {
		path: path.join( OUT, `screenshot-${ number }.png` ),
		animations: 'disabled',
		caret: 'hide',
	} );
}

async function open( admin, page, query ) {
	await admin.visitAdminPage( 'network/admin.php', query );
	await expect( page.locator( '#msradar-app' ) ).not.toBeEmpty();
	await expect( page.locator( '#msradar-app .msradar-skeleton' ) ).toHaveCount(
		0
	);
}

test( '1. Overview', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar' );
	await expect(
		page.locator( '.msradar-alerts-trend svg.msradar-trend__chart' )
	).toBeVisible();
	await shoot( page, 1 );
} );

test( '2. Sites', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-sites' );
	await expect(
		page.locator( '#msradar-app .dataviews-view-table tbody tr' )
	).toHaveCount( 10 );
	await shoot( page, 2 );
} );

test( '3. Side panel of a site, Content tab', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-sites' );
	await page
		.locator( '#msradar-app' )
		.getByText( 'Events 2026', { exact: true } )
		.click();
	const dialog = page.getByRole( 'dialog' );
	await dialog.getByRole( 'tab', { name: 'Content' } ).click();
	await expect( dialog.getByText( 'Demo events' ) ).toBeVisible();
	await shoot( page, 3 );
} );

test( '4. Alerts', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-alerts' );
	await expect(
		page.locator( '#msradar-app .dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	await shoot( page, 4 );
} );

test( '5. Plugins', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-plugins' );
	await expect(
		page.locator( '#msradar-app' ).getByText( 'Multisite Radar demo CPT' )
	).toBeVisible();
	await shoot( page, 5 );
} );

test( '6. Reports', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-reports' );
	await expect(
		page.locator( '.msradar-reports__trends svg.msradar-trend__chart' )
	).toHaveCount( 3 );
	await shoot( page, 6 );
} );

test( '7. Settings, an alert rule', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-settings' );
	await page
		.locator( '#msradar-app' )
		.getByRole( 'button', { name: 'Inactive site' } )
		.click();
	const months = page.getByRole( 'spinbutton', {
		name: /Months without activity/,
	} );
	await expect( months ).toBeVisible();
	await months.scrollIntoViewIfNeeded();
	await shoot( page, 7 );
} );
```

Les nombres attendus correspondent au réseau de seed.sh :
- 10 lignes : 9 sites plus le site principal ;
- 3 graphiques de tendance sur la page Rapports.

Si l'interface en compte autrement, vérifier d'abord le réseau (`wp site list` dans `tests-cli`) avant de toucher à l'attente.

Dans `package.json`, ajouter aux `scripts` :

```json
		"screenshots:seed": "wp-env run tests-cli sh wp-content/plugins/multisite-radar/tests/e2e/screenshots/seed.sh",
		"screenshots": "WP_BASE_URL=http://localhost:${WP_ENV_TESTS_PORT:-8889} wp-scripts test-playwright --config playwright.screenshots.config.js"
```

Dans `.distignore`, ajouter `/playwright.screenshots.config.js` après `/playwright.config.js`.

- [ ] **Step 4: Produire les captures**

```bash
export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890
npm run build && npm run wp-env -- start
npm run screenshots:seed && npm run screenshots:seed
npm run screenshots
```

La graine est lancée deux fois pour vérifier qu'elle est rejouable.

Expected :
- la seconde graine ne crée aucun site ;
- elle se termine par `Success: 31 days of figures and 10 changes written.` ;
- la commande `npm run screenshots` réussit : 7 tests ;
- `.wordpress-org/screenshot-1.png` à `screenshot-7.png` existent, en 1280×800.

- [ ] **Step 5: Regarder chaque capture**

Ouvrir les 7 PNG avec l'outil Read et vérifier, pour chacune :
- l'écran attendu, sans squelette, sans avis d'erreur, sans texte tronqué ;
- des données plausibles (graphiques tracés sur 30 jours, changements datés) ;
- **aucune adresse e-mail réelle, aucun nom de personne réelle**. Les comptes sont `alice`…`farid` en `@example.test`.

Si une capture est vide ou trompeuse, corriger l'attente ou la graine, puis relancer. Décrire chaque capture en une ligne dans le rapport.

- [ ] **Step 6: Les tests E2E restent sur leur environnement**

Run: `npm run test:e2e`
Expected: tout passe. Les captures ne touchent pas l'environnement de développement de wp-env.

- [ ] **Step 7: Commit**

```bash
git add tests/e2e/screenshots playwright.screenshots.config.js package.json .distignore .wordpress-org/screenshot-*.png
git commit -m "build: generate the WordPress.org screenshots from a demo network"
```

---

### Task 10: `readme.txt` complet et vérifié

Le readme actuel n'a ni `Contributors`, ni sections « Installation », « Frequently Asked Questions », « Screenshots » ou « Upgrade Notice ». Spec §11.4 :
- description courte de 150 caractères au plus ;
- 5 tags au plus ;
- `Stable tag` égal à la version ;
- `Tested up to` en version majeure.

`bin/readme.mjs` vérifie ces règles et quelques autres :
- une légende par capture de `.wordpress-org/` ;
- une description courte identique à celle de l'en-tête du plugin ;
- une entrée du journal pour la version en cours.

Le script tourne en CI (job `js`) et dans `make lint`.

**Files:**
- Create: `bin/readme.mjs`, `bin/test/readme.test.mjs`
- Modify: `readme.txt`, `vitest.config.mjs`, `package.json` (script `readme:check`), `Makefile` (`lint`), `.github/workflows/ci.yml` (job `js`), `README.md`

**Interfaces:**
- Consumes : les captures de la tâche 9 (`.wordpress-org/screenshot-1.png` à `-7.png`).
- Produces : `npm run readme:check` ; `checkReadme( readme, plugin, files ): string[]`, exporté par `bin/readme.mjs`. La tâche 11 (workflow de déploiement) et la tâche 12 s'en servent.

- [ ] **Step 1: Tests qui échouent**

Dans `vitest.config.mjs`, `include` devient :

```js
		include: [ 'src/**/test/*.test.{js,jsx}', 'bin/test/*.test.mjs' ],
```

Créer `bin/test/readme.test.mjs` :

```js
import { expect, test } from 'vitest';
import { checkReadme } from '../readme.mjs';

const PLUGIN = `<?php
/**
 * Plugin Name:       Multisite Radar
 * Description:       Network-wide audit for WordPress Multisite.
 * Version:           2.0.0-rc.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 */`;

const README = `=== Multisite Radar ===
Contributors: adjuvans, cyrilledegourcy
Tags: multisite, network, audit
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-rc.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite.

== Description ==

Text.

== Installation ==

Text.

== Frequently Asked Questions ==

= Question? =

Answer.

== Screenshots ==

1. First.
2. Second.

== Changelog ==

= 2.0.0-rc.1 =
* Change.

== Upgrade Notice ==

= 2.0.0 =
Notice.
`;

const FILES = [ 'icon.svg', 'screenshot-1.png', 'screenshot-2.png' ];

test( 'a complete readme passes', () => {
	expect( checkReadme( README, PLUGIN, FILES ) ).toEqual( [] );
} );

test.each( [
	[
		'the contributors',
		README.replace( 'adjuvans, cyrilledegourcy', 'adjuvans' ),
		/Contributors/,
	],
	[
		'more than 5 tags',
		README.replace( 'audit', 'audit, a, b, c' ),
		/Tags/,
	],
	[
		'a minor version in Tested up to',
		README.replace( 'Tested up to: 7.1', 'Tested up to: 7.1.2' ),
		/Tested up to/,
	],
	[
		'a stable tag that is not the version',
		README.replace( 'Stable tag: 2.0.0-rc.1', 'Stable tag: 2.0.0' ),
		/Stable tag/,
	],
	[
		'a short description longer than 150 characters',
		README.replace(
			'Network-wide audit for WordPress Multisite.\n',
			`${ 'x'.repeat( 151 ) }\n`
		),
		/150/,
	],
	[
		'a missing section',
		README.replace( '== Installation ==', '== Setup ==' ),
		/Installation/,
	],
	[
		'a screenshot without a caption',
		README.replace( '2. Second.\n', '' ),
		/caption/,
	],
	[
		'no changelog entry for the version',
		README.replace( '= 2.0.0-rc.1 =', '= 2.0.0-beta.6 =' ),
		/changelog/,
	],
] )( 'it reports %s', ( _, readme, message ) => {
	const errors = checkReadme( readme, PLUGIN, FILES );
	expect( errors ).toHaveLength( 1 );
	expect( errors[ 0 ] ).toMatch( message );
} );

test( 'the short description must be the one of the plugin header', () => {
	expect(
		checkReadme(
			README,
			PLUGIN.replace( 'audit for', 'audit of' ),
			FILES
		)
	).toEqual( [
		'The short description must be the Description of the plugin header.',
	] );
} );

test( 'screenshots are numbered from 1 without gaps', () => {
	expect(
		checkReadme( README, PLUGIN, [ 'screenshot-1.png', 'screenshot-3.png' ] )
	).toContainEqual( expect.stringMatching( /without gaps/ ) );
} );
```

Run: `npx wp-scripts test-unit-js bin/test/readme.test.mjs`
Expected: FAIL (module `../readme.mjs` introuvable).

- [ ] **Step 2: Créer `bin/readme.mjs`**

```js
#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Vérifie readme.txt avant une publication sur WordPress.org (spec §11.4) : en-têtes, description courte (celle de
 * l'en-tête du plugin, 150 caractères au plus), sections, une légende par capture de .wordpress-org/, entrée du
 * journal pour la version en cours. Usage : node bin/readme.mjs (npm run readme:check).
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const CONTRIBUTORS = 'adjuvans, cyrilledegourcy';
const SECTIONS = [
	'Description',
	'Installation',
	'Frequently Asked Questions',
	'Screenshots',
	'Changelog',
	'Upgrade Notice',
];

function field( text, name ) {
	const match = new RegExp( `^${ name }:[ \\t]*(.*)$`, 'm' ).exec( text );
	return match ? match[ 1 ].trim() : null;
}

function pluginField( plugin, name ) {
	const match = new RegExp( `^ \\* ${ name }:\\s+(.+)$`, 'm' ).exec( plugin );
	return match ? match[ 1 ].trim() : null;
}

function section( readme, name ) {
	const marker = `== ${ name } ==`;
	const start = readme.indexOf( marker );
	if ( start < 0 ) {
		return null;
	}
	const body = readme.slice( start + marker.length );
	const end = body.search( /^== /m );
	return end < 0 ? body : body.slice( 0, end );
}

function numbers( list ) {
	return list.join( ', ' ) || 'none';
}

/**
 * @param {string}   readme Contenu de readme.txt.
 * @param {string}   plugin Contenu de multisite-radar.php.
 * @param {string[]} files  Fichiers de .wordpress-org/.
 * @return {string[]} Erreurs ; vide si le readme est prêt.
 */
export function checkReadme( readme, plugin, files ) {
	const errors = [];
	const lines = readme.split( '\n' );
	if ( lines[ 0 ] !== '=== Multisite Radar ===' ) {
		errors.push( 'The first line must be "=== Multisite Radar ===".' );
	}
	if ( field( readme, 'Contributors' ) !== CONTRIBUTORS ) {
		errors.push( `Contributors must be "${ CONTRIBUTORS }".` );
	}
	const tags = ( field( readme, 'Tags' ) || '' )
		.split( ',' )
		.map( ( tag ) => tag.trim() )
		.filter( Boolean );
	if ( tags.length < 1 || tags.length > 5 ) {
		errors.push( `Tags: 1 to 5 are allowed, found ${ tags.length }.` );
	}
	for ( const name of [ 'Requires at least', 'Requires PHP' ] ) {
		if ( field( readme, name ) !== pluginField( plugin, name ) ) {
			errors.push(
				`${ name } is "${ field( readme, name ) }", the plugin header says "${ pluginField( plugin, name ) }".`
			);
		}
	}
	const tested = field( readme, 'Tested up to' ) || '';
	if ( ! /^\d+\.\d+$/.test( tested ) ) {
		errors.push(
			`Tested up to must be a major version such as 7.1, found "${ tested }".`
		);
	}
	const version = pluginField( plugin, 'Version' );
	if ( field( readme, 'Stable tag' ) !== version ) {
		errors.push( `Stable tag must be the version of the plugin, ${ version }.` );
	}
	if ( ! field( readme, 'License' ) || ! field( readme, 'License URI' ) ) {
		errors.push( 'License and License URI are required.' );
	}

	// La description courte : première ligne non vide après le bloc des en-têtes.
	const blank = lines.indexOf( '', 1 );
	const short =
		( blank < 0 ? [] : lines.slice( blank ) ).find( ( line ) =>
			line.trim()
		) || '';
	if ( short.length > 150 ) {
		errors.push(
			`The short description has ${ short.length } characters, 150 at most.`
		);
	} else if ( short !== pluginField( plugin, 'Description' ) ) {
		errors.push(
			'The short description must be the Description of the plugin header.'
		);
	}

	for ( const name of SECTIONS ) {
		if ( section( readme, name ) === null ) {
			errors.push( `The section "== ${ name } ==" is missing.` );
		}
	}

	const shots = files
		.map( ( file ) => /^screenshot-(\d+)\.png$/.exec( file ) )
		.filter( Boolean )
		.map( ( match ) => Number( match[ 1 ] ) )
		.sort( ( a, b ) => a - b );
	const expected = shots.map( ( _, index ) => index + 1 );
	if ( shots.join() !== expected.join() ) {
		errors.push(
			`Screenshots must be numbered from 1 without gaps, found ${ numbers( shots ) }.`
		);
	}
	const captions = ( section( readme, 'Screenshots' ) || '' )
		.split( '\n' )
		.filter( ( line ) => /^\d+\. \S/.test( line ) )
		.map( ( line ) => Number( line.split( '.' )[ 0 ] ) );
	if ( captions.join() !== shots.join() ) {
		errors.push(
			`One caption per screenshot: captions ${ numbers( captions ) }, files ${ numbers( shots ) }.`
		);
	}

	if ( ! ( section( readme, 'Changelog' ) || '' ).includes( `= ${ version } =` ) ) {
		errors.push( `The changelog has no entry for ${ version }.` );
	}
	return errors;
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
	const assets = join( root, '.wordpress-org' );
	const errors = checkReadme(
		readFileSync( join( root, 'readme.txt' ), 'utf8' ),
		readFileSync( join( root, 'multisite-radar.php' ), 'utf8' ),
		existsSync( assets ) ? readdirSync( assets ) : []
	);
	errors.forEach( ( error ) => console.error( error ) );
	if ( errors.length ) {
		process.exit( 1 );
	}
	console.log( 'readme.txt is ready for WordPress.org.' );
}
```

Run: `npx wp-scripts test-unit-js bin/test/readme.test.mjs`
Expected: PASS (11 tests). Un cas peut produire deux erreurs au lieu d'une : la légende manquante peut aussi décaler une autre vérification. Dans ce cas, corriger le **script** pour que chaque défaut ne donne qu'une erreur ; ne pas affaiblir le test.

- [ ] **Step 3: Constater l'état du readme**

Dans `package.json`, ajouter aux `scripts`, après `version:set` :

```json
		"readme:check": "node bin/readme.mjs",
```

Run: `npm run readme:check`
Expected: FAIL. Les erreurs doivent porter sur `Contributors`, les sections manquantes et les captures sans légende.

- [ ] **Step 4: Écrire le readme**

Remplacer, dans `readme.txt`, tout ce qui précède `== Changelog ==` par le texte ci-dessous. Garder `Stable tag: 2.0.0-beta.6` : la tâche 12 change la version. Le journal (`== Changelog ==` et ses entrées) ne change pas.

```text
=== Multisite Radar ===
Contributors: adjuvans, cyrilledegourcy
Tags: multisite, network, audit, inventory, admin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-beta.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.

== Description ==

Multisite Radar gives network administrators a single, always up-to-date view of every site of a WordPress Multisite network: content types and the plugin or theme that registers them, users, plugins, themes, activity and health alerts.

It is a read-only audit tool: it never changes a site. Data is collected in the background with lightweight SQL queries, so the network admin screens open instantly, even on networks with thousands of sites. Nothing is sent to external services.

= Sites =

* One table for every site of the network: theme, users, published content, media, last activity, disk and database size, alerts. Search, filters, sorting, and CSV or JSON export of the current view.
* A side panel for each site: content types with their origin, users by role, plugins and theme, alerts and history.
* Content types and taxonomies are read in the context of each site: a post type registered by a plugin that is active on one site only appears on that site, with its label and its plugin.

= Inventory =

* Plugins and themes: which sites use each one, network-activated plugins, unused plugins and themes, available updates.
* Users: accounts and their roles on each site, super admins, accounts attached to no site.

= Health alerts =

Eleven rules flag sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines and overdue scheduled tasks. Each rule can be switched off, given another severity and tuned in the settings. Other plugins can add their own rules.

= History and reports =

* A journal of changes: sites created or deleted, plugins activated or deactivated, themes switched, alerts raised or resolved.
* Daily figures of each site, with trend charts for the network and for each site.
* An optional weekly e-mail summary, and a widget on the network dashboard.

= Command line and AI assistants =

* `wp multisite-radar` scans the network, lists sites, alerts, plugins and themes, exports them as CSV or JSON, and reads or changes the settings.
* Six read-only abilities of the WordPress Abilities API describe the network, its sites, the use of each plugin and theme, its alerts and its recent changes. A setting, off by default, offers them to AI assistants through the MCP Adapter plugin.

= Network sites menu =

An optional "Network sites" block, shortcode and navigation menu items list the public sites of the network. They replace the menu of Network Plugin Utilities 1.x.

= Privacy =

Multisite Radar makes no external request and collects nothing from visitors. To build its inventory, it keeps in the network database a copy of data that WordPress already holds, such as the user IDs and logins of administrators and editors, and a history of the changes of the network for the retention period set in its settings. A text for your privacy policy is suggested in the privacy policy guide. Deleting the plugin deletes all its data.

= Source code =

Multisite Radar is developed on GitHub, with its uncompiled JavaScript and its build tools: https://github.com/adjuvans/wp-multisite-radar

== Installation ==

1. In the network admin, go to Plugins > Add New Plugin, search for "Multisite Radar" and install it. You can also upload the `multisite-radar` folder to `/wp-content/plugins/`.
2. Network-activate it in Network Admin > Plugins. Multisite Radar requires a multisite network.
3. Open Network Admin > Multisite Radar. The first analysis of the network starts from the Overview screen. On a large network, it continues in the background with WP-Cron; `wp multisite-radar scan --all` runs it in one go.

= Coming from Network Plugin Utilities 1.x =

Remove the Network Plugin Utilities must-use plugin (its folder and its loader file in `wp-content/mu-plugins`), then network-activate Multisite Radar. The 1.x settings and the "network site" menu items are converted, and the `[network_sites_menu]` shortcode and the `rdc_network_sites_menu()` function keep working. While the must-use plugin is still loaded, a notice asks you to remove it.

== Frequently Asked Questions ==

= Does it work on a single site? =

No. Multisite Radar audits multisite networks; on a single site, it only shows a notice.

= Who can see the Multisite Radar screens? =

Super admins. Viewing requires the `msradar_view` capability (`manage_network` by default); changing the settings or starting an analysis requires `msradar_manage` (`manage_network_options` by default). The `msradar_capability_map` filter maps them to other capabilities.

= Will it slow down my network? =

No. The screens read figures stored in the plugin's own tables. The analysis runs in the background, in small batches with a time limit, and only for the sites that changed.

= Why is a site marked "Not verified"? =

Content types and taxonomies are read in each site's own context, with its plugins and theme loaded: a scheduled task of the site does it after its next visit. A site that nobody visits stays "Not verified" until then; `wp multisite-radar scan --all --probe` reads every site at once.

= Does it send data anywhere? =

No. Multisite Radar makes no external request. If you enable the weekly summary, it is sent with `wp_mail()` to the addresses you choose.

= Can it change my sites? =

No. Multisite Radar only reads.

= How do I add my own alert rule? =

Use the `msradar_alert_rules` filter to add an instance of a class that implements `MultisiteRadar\Alerts\RuleInterface`. Your rule then appears in the settings, where it can be switched off or given another severity, like the built-in rules.

= What does uninstalling remove? =

Deleting the plugin in Network Admin > Plugins removes its tables, its network options, the display preferences of each user, the data it stored on each site and its scheduled tasks.

== Screenshots ==

1. Overview: network figures, sites to review, recent changes and alerts of the last 30 days.
2. Sites: every site of the network with its theme, users, content, activity and alerts, with filters and CSV or JSON export.
3. Side panel of a site: content types with the plugin or theme that registers them.
4. Alerts: one line per site and rule, by severity.
5. Plugins: the sites that use each plugin, unused plugins and available updates.
6. Reports: trends of the network and journal of changes.
7. Settings: switch an alert rule off, change its severity or its threshold.

```

Ajouter **à la fin** du fichier, après la dernière entrée du journal :

```text

== Upgrade Notice ==

= 2.0.0 =
Complete rewrite of Network Plugin Utilities 1.x. Remove the 1.x must-use plugin, then network-activate Multisite Radar: its settings and menu items are converted.
```

Avant de valider, **vérifier chaque affirmation du readme dans le code**, et corriger le readme, jamais le code, s'il se trompe :
- le filtre `msradar_alert_rules` reçoit-il bien des instances de `RuleInterface` (voir `includes/Alerts/RuleRegistry.php`) ?
- le relevé se fait-il bien par une tâche planifiée du site, après une visite (`includes/Collector/RegistryProbe.php::register()`) ?
- `uninstall.php` supprime-t-il bien tout ce qui est listé ?
- le menu « Plugins > Add New Plugin » porte-t-il bien ce nom dans WordPress 7.1 ?

Run: `npm run readme:check`
Expected: `readme.txt is ready for WordPress.org.`

- [ ] **Step 5: Brancher le contrôle**

Dans `Makefile`, cible `lint`, ajouter la ligne `npm run readme:check` après `npm run version:check`.

Dans `.github/workflows/ci.yml`, job `js`, ajouter `- run: npm run readme:check` après `- run: npm run version:check`.

Dans `README.md` (français), ajouter avant `## Développement` :

```markdown
## Publication sur WordPress.org

- `readme.txt` est la page du plugin sur WordPress.org ; `npm run readme:check` le vérifie (CI et `make lint`).
- `.wordpress-org/` contient l'icône, la bannière et les captures, publiées dans le dossier `assets` du SVN et jamais dans le zip. `npm run wporg:assets` régénère l'icône et la bannière depuis `bin/wporg-assets/`. `npm run screenshots:seed` puis `npm run screenshots` (wp-env démarré) régénèrent les captures.
- Procédure complète : `docs/release.md`.
```

- [ ] **Step 6: Contrôles**

Run: `npm run lint:js && npm run test:unit && npm run readme:check && make plugin-check`
Expected: tout passe ; Plugin Check reste à 0 erreur, 0 avertissement. La vérification du readme de Plugin Check a sa propre liste de règles.

- [ ] **Step 7: Commit**

```bash
git add bin/readme.mjs bin/test/readme.test.mjs readme.txt vitest.config.mjs package.json Makefile .github/workflows/ci.yml README.md
git commit -m "docs: complete the WordPress.org readme and check it in CI"
```

`README.md` pointe vers `docs/release.md`, écrit par la tâche 11 : le lien est provisoirement mort entre les deux commits.

---

### Task 11: Chaîne de publication

Écart E7. Trois éléments :
- **Workflow « Deploy to WordPress.org ».** Déclenché à la main (`workflow_dispatch`), il refuse une pré-version et un tag qui n'est pas la version du plugin. Il publie dans le SVN, avec les fichiers du paquet dans `trunk` et `tags/<version>` et `.wordpress-org/` dans `assets`. La simulation est cochée par défaut.
- **Procédure écrite** `docs/release.md` : avant chaque version, première soumission, publication, traductions.
- **Actions GitHub à jour.** Les annotations de la CI signalent que `actions/checkout@v4` et `actions/setup-node@v4` visent Node 20, qui est déprécié. Au 2026-10-05, les dernières versions sont :
  - `actions/checkout` v7.0.1 ;
  - `actions/setup-node` v7.0.0 ;
  - `actions/upload-artifact` v7.0.1 ;
  - `10up/action-wordpress-plugin-deploy` 2.3.0.

**Files:**
- Create: `.github/workflows/wporg-deploy.yml`, `docs/release.md`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes :
  - `npm run version:check` et `npm run readme:check` (tâche 10) ;
  - `.distignore` ;
  - `.wordpress-org/` (tâches 8 et 9) ;
  - `docs/benchmarks.md` (tâche 1) ;
  - `make plugin-check` (tâche 2).
- Produces : le workflow `wporg-deploy.yml`, qui demande les secrets `SVN_USERNAME` et `SVN_PASSWORD`, et l'environnement GitHub `wordpress.org`.

- [ ] **Step 1: Créer `.github/workflows/wporg-deploy.yml`**

```yaml
name: Deploy to WordPress.org

# Publication manuelle dans le SVN de WordPress.org (écart E7 du plan M7) : jamais sur un push de tag.
# Simulation par défaut ; secrets SVN_USERNAME et SVN_PASSWORD ; procédure dans docs/release.md.
on:
  workflow_dispatch:
    inputs:
      tag:
        description: Release tag to publish (vX.Y.Z, not a pre-release)
        required: true
        type: string
      dry_run:
        description: Simulate only (nothing is committed to the SVN)
        required: true
        type: boolean
        default: true

permissions:
  contents: read

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: wordpress.org
    env:
      TAG: ${{ inputs.tag }}
    steps:
      - name: Check the tag
        run: |
          if ! printf '%s\n' "$TAG" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'; then
            echo "::error::$TAG is not a release tag (vX.Y.Z, no pre-release)."
            exit 1
          fi
          echo "VERSION=${TAG#v}" >> "$GITHUB_ENV"
      - uses: actions/checkout@v7
        with:
          ref: ${{ inputs.tag }}
      - uses: actions/setup-node@v7
        with:
          node-version-file: .nvmrc
          cache: npm
      - run: npm ci
      - run: npm run version:check
      - run: npm run readme:check
      - name: Check that the tag is the version of the plugin
        run: test "$(node -p "require('./package.json').version")" = "$VERSION"
      - run: npm run build
      - name: Build the distributable directory
        run: |
          mkdir -p /tmp/build/multisite-radar
          rsync -a --exclude-from=.distignore ./ /tmp/build/multisite-radar/
      - uses: 10up/action-wordpress-plugin-deploy@2.3.0
        with:
          dry-run: ${{ inputs.dry_run }}
        env:
          SVN_USERNAME: ${{ secrets.SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}
          SLUG: multisite-radar
          VERSION: ${{ env.VERSION }}
          BUILD_DIR: /tmp/build/multisite-radar
          ASSETS_DIR: .wordpress-org
```

Vérifier dans le `README.md` de l'action, à la version 2.3.0, le nom exact des entrées : `dry-run`, `BUILD_DIR` et `ASSETS_DIR`. Lire `https://raw.githubusercontent.com/10up/action-wordpress-plugin-deploy/2.3.0/README.md` et `…/action.yml`, puis aligner le workflow sur ces fichiers si un nom diffère. Ne pas lancer le workflow : il ne s'exécute que sur GitHub, à la demande de l'utilisateur.

- [ ] **Step 2: Mettre à jour les actions de la CI**

Dans `.github/workflows/ci.yml`, sans rien changer d'autre :
- `actions/checkout@v4` → `actions/checkout@v7` ;
- `actions/setup-node@v4` → `actions/setup-node@v7` ;
- `actions/upload-artifact@v4` → `actions/upload-artifact@v7`.

Avant de changer, lire les notes de version des majeures franchies (v5, v6, v7) de chacune :

```bash
curl -s https://api.github.com/repos/actions/setup-node/releases?per_page=30 | python3 -c "import json,sys; [print(r['tag_name'], (r['body'] or '')[:400].replace('\n',' ')) for r in json.load(sys.stdin) if r['tag_name'].endswith('.0.0')]"
```

Faire de même pour `actions/checkout` et `actions/upload-artifact`. Si une majeure change un comportement utilisé ici (`cache: npm`, `node-version-file`, `retention-days`), adapter l'appel et le dire dans le rapport.

- [ ] **Step 3: Valider la syntaxe des workflows**

Run: `python3 -c "import sys, yaml; [yaml.safe_load(open(f)) for f in sys.argv[1:]]; print('YAML OK')" .github/workflows/ci.yml .github/workflows/wporg-deploy.yml`
Expected: `YAML OK`.

Run: `grep -n "uses:" .github/workflows/*.yml`
Expected : plus aucune référence `@v4`, sauf `wordpress/plugin-check-action@v1` et `shivammathur/setup-php@v2`, qui restent.

- [ ] **Step 4: Écrire `docs/release.md`**

````markdown
# Publier une version de Multisite Radar

Procédure de publication sur WordPress.org (slug `multisite-radar`, compte principal `adjuvans`, contributeur `cyrilledegourcy`).

## Avant chaque version

1. Tests et contrôles, wp-env démarré (`export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` si le port 8888 est pris) :

   ```bash
   make check            # lint (dont le readme) + PHPUnit + Vitest
   make e2e              # Playwright
   make plugin-check     # zéro erreur, zéro avertissement
   ```

   Et `bin/e2e.sh` (WP-CLI de bout en bout ; variables `E2E_DB_*`).
2. Banc de performance : `make bench` (1 000 sites) ; avant une version majeure, aussi `BENCH_SITES=5000`. Ajouter les résultats à `docs/benchmarks.md`.
3. « Tested up to » : la dernière version majeure publiée de WordPress (`https://api.wordpress.org/core/version-check/1.7/`). La CI (Plugin Check en mode strict) échoue quand une nouvelle majeure sort et que le readme n'a pas suivi.
4. Traductions : `make i18n`, puis traduire les nouvelles chaînes dans `languages/multisite-radar-fr_FR.po` et relancer `make i18n`.
5. Captures, si l'interface a changé : `npm run screenshots:seed && npm run screenshots`. Si l'identité visuelle a changé : `npm run wporg:assets`.
6. Version : `make version VERSION=x.y.z`. Puis `CHANGELOG.md` (en français), et dans `readme.txt` l'entrée du journal et, si la mise à jour demande une action, l'« Upgrade Notice ».
7. Commit, tag annoté `vx.y.z`, push de `main` puis du tag. Attendre la CI verte.
8. Paquet : `make dist` produit `dist/multisite-radar-x.y.z.zip`.

## Première soumission (une seule fois)

1. Connecté à WordPress.org avec le compte `adjuvans`, envoyer `dist/multisite-radar-2.0.0.zip` sur <https://wordpress.org/plugins/developers/add/>. Vérifier que le slug proposé est bien `multisite-radar`.
2. L'équipe de revue répond par e-mail (plugins@wordpress.org), en général sous quelques semaines. Corriger ce qu'elle demande, puis renvoyer un zip depuis la même page.
3. Une fois le plugin accepté :
   - le dépôt SVN `https://plugins.svn.wordpress.org/multisite-radar/` est ouvert au compte `adjuvans` ;
   - ajouter `cyrilledegourcy` comme committer (page du plugin, onglet « Advanced », section « Committers »).

## Publier une version dans le SVN

1. Une fois : dans GitHub, Settings > Secrets and variables > Actions, créer `SVN_USERNAME` (`adjuvans`) et `SVN_PASSWORD`.
   - Le mot de passe est celui du SVN, à créer dans le profil WordPress.org, onglet « Account & Security ».
   - L'environnement `wordpress.org`, créé au premier lancement, peut exiger une approbation (Settings > Environments).
2. Actions > « Deploy to WordPress.org » > Run workflow : le tag `vx.y.z`.
3. Lancer d'abord avec « Simulate only » coché, et lire le journal du job.
4. Relancer avec la case décochée.
5. Vérifier <https://wordpress.org/plugins/multisite-radar/> : version, bannière, icône, captures. La page peut mettre quelques minutes à se mettre à jour.

Le workflow refuse une pré-version (`v2.0.0-rc.1`) et un tag qui n'est pas la version du plugin.

## Traductions

Les traductions de WordPress.org se font sur <https://translate.wordpress.org/projects/wp-plugins/multisite-radar/>. Le fichier `languages/multisite-radar-fr_FR.po` peut y être importé par un éditeur de traduction (PTE) du projet. Tant qu'aucun paquet de langue n'existe, WordPress charge les traductions livrées dans `languages/`.

## Production

Déploiement FTP : `make deploy-prod` (paramètres dans `.env`). La 1.x installée en MU-plugin se retire à la main, après l'activation réseau de Multisite Radar.
````

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/wporg-deploy.yml .github/workflows/ci.yml docs/release.md
git commit -m "ci: add the manual WordPress.org deploy workflow and move the actions to Node 24"
```

---

### Task 12: Version 2.0.0-rc.1

Dernière tâche du plan :
- traductions ;
- version, journaux ;
- document des suites ;
- contrôle final de tout ce qui précède, sur le paquet réel.

**Files:**
- Modify :
  - `languages/*` (via `make i18n`) ;
  - `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json` (via `make version`) ;
  - `CHANGELOG.md` ;
  - `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`.

**Interfaces:**
- Consumes : tout le plan ; la liste des points reportés par les revues du plan M7, que le contrôleur fournit avec le brief (registre d'exécution).

- [ ] **Step 1: Traductions**

Run: `npm run build && make i18n`
Expected: le script s'arrête et liste les chaînes nouvelles ou modifiées par les tâches 3 à 8.

Traduire chacune dans `languages/multisite-radar-fr_FR.po`, dans le style du fichier :
- guillemets « » ;
- espace insécable avant `:` `;` `?` `!` et à l'intérieur des guillemets ;
- vocabulaire existant : « analyse », « site », « fiche », « récapitulatif », « relevé ».

Relancer `make i18n` jusqu'à ce qu'il passe.

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 2: Version**

Run: `make version VERSION=2.0.0-rc.1`
Expected: `Version 2.0.0-rc.1 is consistent.`

- [ ] **Step 3: Journaux**

En tête de `CHANGELOG.md`, sous le titre :

```markdown
## [2.0.0-rc.1] - <date du jour, AAAA-MM-JJ>

### Jalon M7 — Publication
- Candidate à la version 2.0.0, prête pour WordPress.org : readme complet et vérifié en CI, icône, bannière et sept captures générées par script, nouvelle icône du menu.
- Plugin Check sans erreur ni avertissement, vérifié en mode strict en CI et par `make plugin-check`. Chaque requête SQL directe porte sa justification.
- Banc de performance `make bench` ; résultats sur 1 000 et 5 000 sites dans `docs/benchmarks.md`.
- Bloc « Network sites » : les sites proposés se chargent à la demande (`GET /sites-menu/sites`), au lieu d'une liste complète injectée dans l'éditeur.
- Sites : « Analyse again » pendant une analyse le signale ; le tableau tient sur un écran de 1440 px.
- Graphiques : couleurs contrastées et un motif de trait par série, points isolés ronds, message quand aucune mesure n'existe. Journal : message propre à un filtre vide, retour à la première page quand le site change.
- Récapitulatif : un envoi manqué (WP-Cron en retard) part les jours suivants, une fois ; ses 7 jours de changements sont gardés même avec une rétention plus courte. Chaque étape de la tâche quotidienne de l'historique est indépendante.
- Accessibilité : audit de tous les onglets de la fiche et du widget du tableau de bord.
- Publication : workflow manuel « Deploy to WordPress.org », procédure `docs/release.md`, actions GitHub sur Node 24.
```

Dans `readme.txt`, ajouter juste sous `== Changelog ==` :

```text
= 2.0.0-rc.1 =
* Release candidate of 2.0.0, ready for WordPress.org.
* The "Network sites" block loads the sites of the network on demand instead of the whole list.
* Analysing the selected sites again during an analysis now says so, and the Sites table fits a 1440 px screen.
* Charts: higher contrast, a stroke pattern for each series, round isolated points and a message when no figures exist.
* Weekly summary: a summary missed by a late WP-Cron goes out on the next days, once, and its week of changes is always kept.
```

Remplacer la date de l'en-tête du CHANGELOG par la date du jour.

Run: `npm run readme:check && npm run version:check`
Expected: succès.

- [ ] **Step 4: Document des suites**

Dans `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md` :
1. **Section 1** : retirer chaque point traité (voir le tableau « Points reportés traités » du plan M7). Le point « Publication » devient : « Confirmer "Tested up to" au moment de la soumission (7.1 au 2026-10-05). » La section garde son titre même si elle n'a plus que ce point.
2. **Sections 2 à 6** : retirer les points traités par M7 (tableau du plan) ; ne pas toucher aux autres.
3. **Liste « Déjà traité depuis M2 »** : ajouter une ligne par point traité, avec « (2.0.0-rc.1, plan M7) ».
4. **Nouvelle section** `## 7. Reportés par le plan M7`, sur le modèle de la section 6 :
   - les points que les revues de tâche et la revue finale du plan M7 ont laissés pour plus tard, tels que le contrôleur les fournit dans le brief ;
   - si un point ne figure que dans un rapport de tâche, l'y mettre aussi.

- [ ] **Step 5: Contrôle final sur des tables neuves**

```bash
cp tests/php/wp-tests-config.php /tmp/claude-1000/wp-tests-config-m7.php
sed -i "s/^\$table_prefix *= *'[^']*';/\$table_prefix = 'wpm7_';/" /tmp/claude-1000/wp-tests-config-m7.php
grep -n "table_prefix" /tmp/claude-1000/wp-tests-config-m7.php
WP_PHPUNIT__TESTS_CONFIG=/tmp/claude-1000/wp-tests-config-m7.php bin/test.sh
```

Expected: `OK` (aucun échec). Le `grep` doit montrer `wpm7_`.

```bash
composer lint && composer analyse && npm run lint:js && npm run lint:css && npm run test:unit && npm run readme:check
export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890
npm run build && npm run wp-env -- start && npm run e2e:setup && npm run test:e2e
make plugin-check
E2E_DB_NAME=wordpress_test E2E_DB_USER=wordpress E2E_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)" E2E_DB_HOST=127.0.0.1:3306 E2E_WP_VERSION=7.1 bin/e2e.sh
make dist
unzip -l dist/multisite-radar-2.0.0-rc.1.zip | awk '{print $4}' | cut -d/ -f2 | sort -u
```

Expected :
- tout passe ; `bin/e2e.sh` finit par `E2E OK` ; Plugin Check donne `0 error(s), 0 warning(s)` ;
- le zip ne contient que `LICENSE`, `build`, `includes`, `languages`, `multisite-radar.php`, `readme.txt` et `uninstall.php` (plus la ligne vide du dossier racine). Il ne contient en particulier ni `.wordpress-org`, ni `bin`, ni `tests`.

Reporter les nombres de tests (PHPUnit, Vitest, Playwright, `bin/e2e.sh`) dans le rapport.

- [ ] **Step 6: Commit**

```bash
git add languages multisite-radar.php readme.txt package.json package-lock.json CHANGELOG.md docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md
git commit -m "chore: release 2.0.0-rc.1"
```

---

## Après la recette (hors exécution du plan)

Cette section n'est pas une tâche du plan. Le contrôleur l'exécute sur demande de l'utilisateur, une fois la 2.0.0-rc.1 validée en production :
- l'utilisateur la déploie lui-même, ou donne son accord à `make deploy-prod` ;
- il l'active sur le réseau, puis retire le MU-plugin 1.x.

1. Corriger ce que la recette a relevé (un plan court si besoin).
2. `make version VERSION=2.0.0`.
3. `readme.txt` : remplacer toutes les entrées du journal, de `2.0.0-rc.1` à `2.0.0-alpha.1`, par une seule entrée `= 2.0.0 =`, qui résume le plugin pour un nouveau lecteur de WordPress.org :
   - première version publique ;
   - réécriture complète de Network Plugin Utilities 1.x ;
   - principales fonctions.

   `CHANGELOG.md` garde, lui, tout l'historique.
4. Vérifier « Tested up to » (dernière majeure publiée) ; `npm run readme:check` ; `make check`, `make e2e`, `make plugin-check`, `make dist`.
5. Commit `chore: release 2.0.0`. Puis, après accord de l'utilisateur : tag annoté `v2.0.0`, push de `main`, push du tag, suivi de la CI.
6. L'utilisateur soumet `dist/multisite-radar-2.0.0.zip` sur WordPress.org (`docs/release.md`, « Première soumission »).
