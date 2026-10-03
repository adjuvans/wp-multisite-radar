# Multisite Radar — Jalon M5 (Intégrations) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la 2.0.0-beta.5 de Multisite Radar. Elle ouvre l'audit aux outils :
- WP-CLI complet : `alerts`, `plugins list`, `themes list`, `export`, `settings get` et `settings set`, en plus de `scan`, `probe` et `sites list` (M1) ;
- Abilities API de WordPress : cinq abilities en lecture seule (`network-summary`, `list-sites`, `get-site`, `find-extension-usage`, `list-alerts`), exposées en REST ;
- le réglage « MCP » (`integrations.mcp_public`, désactivé par défaut) qui les ouvre aux assistants IA connectés par l'extension MCP Adapter, dans une nouvelle section « Intégrations » des réglages ;
- les schémas d'élément des routes REST, demandés par le document des points reportés avant ce jalon.

**Architecture :**
- **Une seule source de vérité (spec §2.2) :** les commandes WP-CLI et les abilities appellent les services `Query/` existants, comme les routes REST. Deux morceaux de logique qui vivaient dans des contrôleurs en sortent pour être partagés : l'état de l'analyse (`Query\ScanStatusQuery`) et la validation des réglages (`Settings\SettingsUpdater`).
- **Formes partagées :** `Query\Schemas` décrit en JSON Schema ce que renvoient les services Query. Les routes REST publient ces schémas (`get_item_schema()`), les abilities les déclarent comme `output_schema`, que le cœur valide à chaque exécution.
- **WP-CLI :** une classe par commande dans `includes/Cli/`, minces ; les conversions testables (lignes à plat, pages, valeurs saisies) vivent dans de petites classes testées par PHPUnit. Les commandes elles-mêmes sont testées de bout en bout par `bin/e2e.sh`, sur une vraie installation, avec le vrai WP-CLI (job CI `e2e`).
- **Abilities :** une classe par ability (`includes/Abilities/*Ability.php`, base `Ability`) et un `Registrar` qui ajoute la catégorie, la permission (`msradar_view`) et les métadonnées communes : `readonly`, `show_in_rest`, `mcp.public` selon le réglage.
- **Interface :** une carte « Intégrations » dans la page Réglages, avec l'interrupteur MCP.

**Tech stack :** inchangée depuis M4.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite (Abilities API du cœur), PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6 (stubs WordPress 6.9.4, qui déclarent l'Abilities API ; stubs WP-CLI).
- WP-CLI 2.12 (`WP_CLI\Utils\format_items`, `WP_CLI::print_value`).
- Node 24, `@wordpress/scripts` 36.0.0 (Vitest), React 18.3, `@wordpress/dataviews` 19.1.0 (épinglé).
- Playwright + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M5, les sections utiles sont :
- §2.2 (une seule source de vérité, capacités) ;
- §5.1 (routes REST et leurs schémas), §5.2 (exports), §5.3 (WP-CLI), §5.4 (Abilities API) ;
- §6.2 (Réglages) ; §8 (`integrations.mcp_public`) ; §9 (sécurité, aucune adresse e-mail) ; §11.2 et §11.3 (tests, CI).

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`. Les points repris ici sont listés plus bas.

**Référence externe vérifiée (2026-10-03) :**
- **Cœur, WordPress 6.9 et 7.1 :**
  - `wp_register_ability()` n'est accepté que pendant l'action `wp_abilities_api_init`, et `wp_register_ability_category()` que pendant `wp_abilities_api_categories_init` ;
  - le registre est créé au premier appel après `init`, une seule fois par processus ;
  - le cœur valide l'entrée (`input_schema`) puis la permission avant l'exécution, et valide la sortie (`output_schema`) après ;
  - la route `GET /wp-abilities/v1/abilities/<nom>/run` exécute une ability `readonly` ; une autre méthode renvoie 405 ; un refus de permission renvoie 401 (visiteur) ou 403 ;
  - en GET, l'entrée arrive par le paramètre `input`, en chaînes ; WordPress 6.9 ne les convertit pas.
- **WordPress 7.1 :** ajoute `meta.public`, qui ouvre une ability à tous les canaux (REST compris). Ce plan ne l'utilise pas.
- **MCP Adapter (`WordPress/mcp-adapter`) :**
  - une ability est exposée si `meta.public` ou `meta.mcp.public` vaut `true` ;
  - un `meta.mcp.public` explicite l'emporte sur `meta.public` ;
  - `meta.mcp.type` vaut `tool` par défaut ;
  - les `properties` vides d'un schéma sont retirées avant l'envoi au client.

## Global Constraints

- **PHP ≥ 7.4.** Interdits : `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains`, arguments nommés, opérateur nullsafe, ternaire court `?:`, déstructuration courte `[ $a, $b ] = …`. Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :**
  - text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, transients, groupes de cache, handles) ;
  - commande racine `wp multisite-radar` ; abilities `multisite-radar/<nom>` dans la catégorie `multisite-radar` ;
  - store `msradar/core` ; page des réglages `multisite-radar-settings`.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ; en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder ;
  - jamais de `%` littéral (`%%`) dans une chaîne traduisible ;
  - **les messages des commandes WP-CLI ne sont pas traduits**, comme ceux de M1 (`scan`, `probe`, `sites list`) ; les libellés et descriptions des abilities le sont.
- **SQL :** aucune requête nouvelle dans ce jalon. Les commandes et les abilities passent par les services Query.
- **Une seule source de vérité :** aucune logique métier dans une commande, une ability ou un contrôleur. Ils lisent leurs paramètres, appellent un service, mettent en forme le résultat.
- **Erreurs :**
  - les dépôts (`Storage/*`) lèvent `\RuntimeException` quand une lecture échoue ;
  - les contrôleurs REST passent par `Controller::guard()` (erreur 500) ;
  - une ability rend alors un `WP_Error` `msradar_storage_error` (statut 500) ;
  - une commande WP-CLI appelle `WP_CLI::error()` (code de sortie 1) ;
  - tous trois déclenchent d'abord `do_action( 'msradar_error', <contexte>, $error )`.
- **WP-CLI :**
  - sorties de données par `WP_CLI\Utils\format_items()` (formats `table`, `csv`, `json`, `yaml`, `count`) ;
  - une commande dont la sortie *est* la donnée (export sans `--output`) n'écrit rien d'autre sur la sortie standard ;
  - les drapeaux se lisent par `WP_CLI\Utils\get_flag_value()` (qui gère `--no-<drapeau>`) ;
  - après `WP_CLI::error()`, un `return;` explicite (lisibilité, et PHPStan).
- **Abilities :**
  - toutes en lecture seule (`meta.annotations.readonly = true`, `destructive = false`, `idempotent = true`), `meta.show_in_rest = true`, permission `msradar_view` ;
  - jamais `meta.public` ; `meta.mcp.public` toujours présent, égal au réglage `integrations.mcp_public` ;
  - enregistrées seulement si `function_exists( 'wp_register_ability' )` et `is_main_site()` (spec §5.4) ;
  - l'entrée est toujours convertie (`(int)`, `(string)`) avant usage : en GET, WordPress 6.9 la passe en chaînes ;
  - schémas d'entrée : objet, `additionalProperties: false`, une `description` par propriété, `enum` plutôt que des motifs ;
  - schémas de sortie : ceux de `Query\Schemas`, jamais plus stricts que les données (mesures nulles, dates nulles, adresse vide).
- **Schémas JSON publiés** (REST, abilities) : aucun motif `\z` (il n'existe pas dans les expressions régulières JavaScript que lisent les clients) ; pas de `format` qui rejetterait une valeur réelle.
- **Données personnelles :** aucune adresse e-mail dans une réponse REST, une ability, une commande ou un export. Identifiants, logins et compteurs seulement (spec §5.4).
- **Motifs PHP :** tout `preg_match` qui valide une entrée se termine par `\z`, jamais par `$`.
- **Style PHP :**
  - WPCS ; un tableau associatif de plus d'un élément s'écrit sur plusieurs lignes ;
  - `defined( 'ABSPATH' ) || exit;` en tête de chaque fichier sous `includes/` ;
  - tableaux courts autorisés ;
  - commentaires en français, comme le reste du code ; docblocks des commandes WP-CLI en anglais (c'est l'aide affichée par `wp help`).
- **JS :** mêmes règles que M4 (`.jsx` pour le JSX, imports sans extension, composants publics de `@wordpress/components` présents dans WordPress 6.9, dont `Card`, `CardBody`, `CardHeader`, `ToggleControl` ; DataViews importé seulement par `src/components/data-views/index.js`).
- **Versions :** `MSRADAR_VERSION` est la seule source de vérité ; `make version VERSION=x` met tout à jour ; `npm run version:check` vérifie.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `ci:`, `docs:`, `chore:`, `refactor:`) ;
  - **jamais de ligne `Co-authored-by`**, que le hook du dépôt refuse ;
  - jamais `--no-verify`.
- **Tests PHP :**
  - ne jamais lever d'exception dans `set_up()` ou `tear_down()` après `parent::set_up()` : la transaction ne serait pas annulée ;
  - le registre des abilities est créé une fois par processus de test : un test ne réenregistre pas les abilities, il lit `wp_get_ability()` ou appelle `Registrar::definitions()`.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur `multisite-radar` : le dossier du plugin est un lien symbolique vers le dépôt ;
  - ne jamais afficher le mot de passe de la base : `bin/test.sh` le lit à la volée ; pour `bin/e2e.sh`, le passer par `E2E_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)"` sans l'afficher ;
  - ne pas lancer `settings set` sur le WordPress local de développement (`/home/dev/wp`) : les tests qui modifient des réglages passent par `bin/e2e.sh`, qui installe son propre WordPress ;
  - le port 8888 est occupé dans la VM : lancer wp-env et les tests E2E avec `WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890`.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M5 | Raison |
|---|---|---|---|
| E1 | §5.4 : `multisite-radar/recent-changes` (« disponible avec M6 ») | **Pas livrée en M5** : elle lira les événements de M6 | Aucune donnée d'événements n'existe avant M6. |
| E2 | §5.4 : `meta.mcp.public` selon le réglage | `meta.mcp.public` est **toujours écrit** (`true` ou `false`) et `meta.public` **jamais** ; `meta.mcp.type = tool` | Sous WordPress 7.1, `meta.public` ouvrirait l'ability à tous les canaux ; un `mcp.public` explicite est respecté par MCP Adapter quelle que soit sa version. |
| E3 | §5.4 : abilities | Les listes renvoient une **enveloppe** `{ items, total, page, per_page, total_pages }` ; `per_page` vaut au plus 100 | Une ability n'a pas d'en-têtes HTTP : la pagination REST (`X-WP-Total`) n'a pas d'équivalent. |
| E4 | §5.4 : `find-extension-usage` | Entrée `{ type: plugin | theme, id }` ; pour un plugin, `id` est son fichier, avec ou sans `.php` ; la sortie donne l'extension et une page de ses sites | Un seul outil pour les deux inventaires de M3, avec les identifiants que montrent les pages Plugins et Thèmes. |
| E5 | §5.3 : `settings get [<key>]`, `settings set <key> <value>` | `<key>` est un **chemin pointé** (`scan.full_rescan_days`, `alerts.rules.inactive`) ; `<value>` est lue comme du JSON si c'en est, sinon comme du texte ; `settings get` sort du JSON (ou du YAML avec `--format=yaml`) ; la validation est celle de la page Réglages | Les réglages sont imbriqués (§8) et typés : `true`, `14` et `["post","page"]` doivent arriver avec leur type. |
| E6 | §5.3 : `export --resource --format [--output=<file>]` | Accepte aussi `--fields` et **les filtres de la route REST** de la ressource (`--alert_level=error,warning`, `--status=unused`…) ; sans `--output`, écrit sur la sortie standard sans message de succès | Mêmes exports que les boutons des pages (§5.2), et une sortie utilisable dans un tube. |
| E7 | §5.3 : `alerts [--severity] [--rule] [--format]` ; `plugins list [--unused]`, `themes list [--unused]` | Ajout de `--search` (alertes) et de `--fields` (les trois) ; une règle inconnue est une erreur | Mêmes options que `sites list` ; une faute de frappe dans `--rule` ne doit pas passer pour « aucune alerte ». |
| E8 | §2.2 : « aucune logique métier dans les contrôleurs ou commandes » | La validation des règles d'alertes sort de `SettingsController` vers `Settings\SettingsUpdater` ; l'état de l'analyse sort de `ScanController` vers `Query\ScanStatusQuery` | WP-CLI (`settings set`) et l'ability `network-summary` en ont besoin. |

## Points reportés traités dans ce plan

| Point (document des suites de M2) | Tâche |
|---|---|
| Schémas d'élément (`get_item_schema()`) pour `/plugins`, `/themes`, `/users`, `/inventory/summary` et `AlertsController`, avant M5 | 1 |

Les autres points restent pour plus tard. La tâche 10 met le document à jour.

## Review Focus

Les cinq situations que la spec implique sans que ses exemples les montrent, et qui gêneraient le plus un utilisateur. Chacune a son test dans la tâche indiquée.

1. **Un assistant IA lit un site jamais analysé, ou dont une mesure manque :**
   - le comportement attendu : la réponse arrive, avec des mesures et des dates `null` et `pending: true` ; le cœur ne la rejette pas comme « sortie invalide » ;
   - tests : tâche 7 (`list-sites` avec un site jamais analysé), tâche 8 (`get-site` du même site).
2. **Un assistant IA envoie une entrée fantaisiste :** clé inconnue, `per_page` à 500, gravité inexistante, nombres en chaînes (GET) :
   - le comportement attendu : les trois premières sont refusées par une erreur de validation, avant toute lecture ; les nombres en chaînes sont acceptés et convertis ;
   - tests : tâche 7.
3. **Un compte sans la capacité `msradar_view` (administrateur d'un seul site, visiteur) appelle une ability par REST ou MCP :**
   - le comportement attendu : 403 ou 401, aucune donnée ; une ability en lecture seule refuse aussi tout autre verbe que GET (405) ;
   - tests : tâche 7 (route REST du cœur), tâche 8 (exécution directe).
4. **`settings set` avec une valeur hors bornes, une clé inconnue ou une règle inconnue :**
   - le comportement attendu : code de sortie non nul, message clair, réglages inchangés ;
   - tests : tâche 2 (`SettingsUpdater`), tâche 6 (`bin/e2e.sh`).
5. **Le réglage MCP :**
   - le comportement attendu : désactivé à l'installation (aucune ability exposée à MCP) ; activé, toutes le sont au chargement suivant ; désactivé, plus aucune ;
   - tests : tâche 7 (`Registrar::definitions()`), tâche 9 (interrupteur et enregistrement).

Autres pièges couverts par des tests :
- chaque clé d'un élément renvoyé par une route REST est décrite par le schéma de la route, et réciproquement (tâche 1) ;
- `export` vers un dossier qui n'existe pas : erreur, aucun fichier (tâche 5) ;
- une liste paginée dont un élément disparaît entre deux lectures ne boucle pas (tâche 3, `Pages::collect()`) ;
- `--unused` ne liste jamais un plugin activé sur le réseau ni le thème actif (tâche 4) ;
- les abilities ne sont pas proposées sur un site secondaire (tâche 7).

## Structure des fichiers

**PHP, créés :**
- `includes/Query/Schemas.php` — formes JSON des résultats des services Query.
- `includes/Query/ScanStatusQuery.php` — état de l'analyse du réseau courant.
- `includes/Settings/SettingsUpdater.php` — validation (règles d'alertes) et enregistrement d'une modification des réglages ; chemin pointé → modification imbriquée.
- `includes/Cli/Command.php` — base des commandes (service `Plugin`, échec de lecture).
- `includes/Cli/Rows.php` — lignes à plat pour `format_items()`.
- `includes/Cli/Pages.php` — lecture de toutes les pages d'une liste.
- `includes/Cli/Values.php` — valeur saisie (JSON ou texte).
- `includes/Cli/AlertsCommand.php`, `PluginsCommand.php`, `ThemesCommand.php`, `ExportCommand.php`, `SettingsCommand.php`.
- `includes/Abilities/Ability.php` — base d'une ability (exécution protégée, permission, aides de schéma et de pagination).
- `includes/Abilities/Registrar.php` — catégorie, définitions, métadonnées communes.
- `includes/Abilities/NetworkSummaryAbility.php`, `ListSitesAbility.php`, `GetSiteAbility.php`, `FindExtensionUsageAbility.php`, `ListAlertsAbility.php`.

**PHP, modifiés :**
- `includes/Plugin.php` (services, hooks des abilities, constructeurs des contrôleurs) ;
- `includes/Rest/SitesController.php`, `PluginsController.php`, `ThemesController.php`, `UsersController.php`, `AlertsController.php`, `InventoryController.php`, `ScanController.php`, `SettingsController.php` ;
- `includes/Export/ExportHandler.php` (`check()` public) ;
- `includes/Cli/RadarCommand.php` (enregistrement des commandes), `includes/Cli/SitesCommand.php` ;
- `CHANGELOG.md`, `readme.txt`, `README.md`, `multisite-radar.php`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php`.

**JS :** `src/views/settings/fields.js`, `src/views/settings/index.jsx`.

**Tests :**
- PHP : `tests/php/Rest/ItemSchemasTest.php`, `tests/php/Settings/SettingsUpdaterTest.php`, `tests/php/Cli/RowsTest.php`, `tests/php/Cli/PagesTest.php`, `tests/php/Cli/ValuesTest.php`, `tests/php/Export/ExportHandlerTest.php`, `tests/php/Abilities/*Test.php` (créés, sauf `ExportHandlerTest`) ;
- JS : `src/views/settings/test/settings-view.test.jsx` ;
- bout en bout : `bin/e2e.sh` (commandes WP-CLI et route REST d'une ability), `tests/e2e/specs/integrations.spec.js` (créé).

## Commandes

| Rôle | Commande |
|---|---|
| Tests PHP (tout, ou un filtre) | `bin/test.sh` ; `bin/test.sh --filter SettingsUpdaterTest` |
| Normes PHP / analyse statique | `composer lint` ; `composer analyse` |
| Syntaxe PHP 7.4 | `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null \|\| exit 1; done'` |
| Test d'acceptation et commandes WP-CLI (installe un WordPress jetable, tables `msre2e_*`) | `E2E_DB_NAME=wordpress_test E2E_DB_USER=wordpress E2E_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)" E2E_DB_HOST=127.0.0.1:3306 E2E_WP_VERSION=7.1 bin/e2e.sh` |
| Tests JS | `npm run test:unit` (ou `npx vitest run src/views/settings`) |
| Lint JS / CSS | `npm run lint:js` ; `npm run lint:css` |
| Build | `npm run build` |
| Traductions | `make i18n` (après un build) |
| Versions synchronisées | `npm run version:check` |
| E2E | `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` puis `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e` |

Chaque tâche se termine par les tests de son périmètre, puis `composer lint` et `composer analyse` (pour le PHP), ou `npm run lint:js` et `npm run test:unit` (pour le JS), avant le commit. Le travail se fait sur une branche `m5` créée depuis `main`.

**Base de test :** la base `wordpress_test` peut contenir une ligne parasite (site 101). Tant qu'elle y est, 11 tests échouent d'une unité :
- `AlertsQueryTest::test_summary_counts_sites_by_severity_and_rule` ;
- 7 tests de `QueueTest` ;
- 3 tests de `SitesRepositoryTest`.

Ces 11 échecs sont connus : un exécutant ne les corrige pas et ne les compte pas comme une régression, mais signale tout autre échec. Ils peuvent masquer un vrai échec dans ces trois classes. Avant le commit d'une tâche qui touche à la file d'analyse ou aux alertes, il faut donc relancer la suite sur des tables neuves : copier `tests/php/wp-tests-config.php` dans le dossier de travail en remplaçant `$table_prefix = 'wptests_'` par `'wpci_'`, puis lancer `WP_PHPUNIT__TESTS_CONFIG=<copie> bin/test.sh`. C'est ce que voit la CI.

**`bin/e2e.sh` :**
- il demande `jq`, présent dans la VM et sur les machines de GitHub Actions ;
- il télécharge WordPress à chaque exécution, environ une minute ;
- ses tables `msre2e_*` sont supprimées à la fin, même en cas d'échec.

---

## Partie A — Fondations partagées

### Task 1: Schémas d'élément partagés et état de l'analyse

**Files:**
- Create: `includes/Query/Schemas.php`, `includes/Query/ScanStatusQuery.php`
- Modify: `includes/Rest/SitesController.php`, `includes/Rest/PluginsController.php`, `includes/Rest/ThemesController.php`, `includes/Rest/UsersController.php`, `includes/Rest/AlertsController.php`, `includes/Rest/InventoryController.php`, `includes/Rest/ScanController.php`, `includes/Plugin.php`
- Test: `tests/php/Rest/ItemSchemasTest.php` (créé)

**Interfaces:**
- Consumes : `SitesQuery::summary()`, `SitesQuery::get()`, `PluginsQuery::all()`, `ThemesQuery::all()`, `UsersQuery::list()`, `AlertsQuery::list()` et `summary()`, `InventoryQuery::summary()` (formes inchangées) ; `Queue::LAST_FULL_SCAN`, `Queue::next_run()`, `Lock::is_locked()`.
- Produces :
  - `Query\Schemas::site()`, `site_detail()`, `plugin()`, `theme()`, `user()`, `alert()`, `alerts_summary()`, `inventory_summary()`, `scan_status()` : `array` (JSON Schema d'un objet) ;
  - `Query\Schemas::page_of( array $item ): array` (enveloppe `items`, `total`, `page`, `per_page`, `total_pages`) ;
  - `Query\Schemas::for_rest( string $title, array $schema ): array` (ajoute `$schema` et `title`) ;
  - `Query\ScanStatusQuery::__construct( SitesRepository $sites, Lock $lock )` et `status(): array{total: int, remaining: int, pending: int, locked: bool, last_full_scan_gmt: ?string, next_run_gmt: ?string}` ;
  - `Plugin::scan_status_query(): ScanStatusQuery` ;
  - `ScanController::__construct( SitesRepository $sites, BatchRunner $runner, Queue $queue, Lock $lock, ScanStatusQuery $status )`.

- [ ] **Step 1: Write the failing test**

Créer `tests/php/Rest/ItemSchemasTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;
use WP_REST_Request;

/**
 * Chaque route de lecture publie le schéma de ce qu'elle renvoie : chaque clé d'un élément y est décrite, et le schéma
 * ne décrit rien qui ne soit renvoyé (les abilities valident leur sortie avec ces mêmes schémas).
 */
final class ItemSchemasTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as_super_admin();
		$this->make_record(
			961,
			[
				'name'        => 'Schema site',
				'url'         => 'example.org/schema/',
				'scanned_at'  => '2026-09-01 00:00:00',
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
			]
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}> Route, et vrai si elle renvoie une liste.
	 */
	public function routes(): array {
		return [
			'sites'             => [ '/sites', true ],
			'plugins'           => [ '/plugins', true ],
			'themes'            => [ '/themes', true ],
			'users'             => [ '/users', true ],
			'alerts'            => [ '/alerts', true ],
			'alerts summary'    => [ '/alerts/summary', false ],
			'inventory summary' => [ '/inventory/summary', false ],
			'scan status'       => [ '/scan/status', false ],
		];
	}

	/**
	 * @dataProvider routes
	 */
	public function test_the_schema_of_a_route_describes_exactly_what_it_returns( string $route, bool $is_list ): void {
		$response = $this->request( 'GET', $route );
		$this->assertSame( 200, $response->get_status(), $route );
		$data = $response->get_data();
		$item = $is_list ? ( $data[0] ?? null ) : $data;
		$this->assertIsArray( $item, "$route returned nothing to compare." );

		$options = $this->server->dispatch( new WP_REST_Request( 'OPTIONS', '/multisite-radar/v1' . $route ) )->get_data();
		$this->assertArrayHasKey( 'schema', $options, "$route publishes no schema." );
		$this->assertSame( 'object', $options['schema']['type'] );
		$this->assertEqualsCanonicalizing( array_keys( $item ), array_keys( $options['schema']['properties'] ), $route );
	}

	public function test_the_site_schema_accepts_a_site_that_was_never_analysed(): void {
		$this->make_record( 962, [ 'name' => 'Never analysed' ] );
		$site = $this->plugin()->sites_query()->get( 962 );

		$this->assertNotNull( $site );
		$this->assertNull( $site['disk_bytes'] );
		$this->assertNull( $site['scanned_at_gmt'] );
		$this->assertTrue( rest_validate_value_from_schema( $site, \MultisiteRadar\Query\Schemas::site_detail(), 'site' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/test.sh --filter ItemSchemasTest`
Expected: FAIL. Les routes `plugins`, `themes`, `users`, `alerts`, `alerts summary`, `inventory summary` et `scan status` ne publient pas de schéma (« publishes no schema ») ; `site_detail()` n'existe pas (`Class "MultisiteRadar\Query\Schemas" not found`).

- [ ] **Step 3: Create `includes/Query/Schemas.php`**

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Formes JSON des résultats des services Query, partagées par les routes REST (get_item_schema) et les abilities
 * (output_schema). Le cœur valide la sortie d'une ability avec ce schéma : chaque clé renvoyée y est décrite, et rien
 * n'y est plus strict que les données (mesures et dates nulles, adresse d'administration vide).
 */
final class Schemas {

	/**
	 * Un site dans une liste (SitesQuery::summary()).
	 */
	public static function site(): array {
		return self::object(
			array_merge(
				self::identity(),
				[
					'status'            => self::object(
						[
							'public'   => self::type( 'boolean' ),
							'archived' => self::type( 'boolean' ),
							'spam'     => self::type( 'boolean' ),
							'deleted'  => self::type( 'boolean' ),
						]
					),
					'theme'             => self::object(
						[
							'stylesheet' => self::type( 'string' ),
							'template'   => self::type( 'string' ),
						]
					),
					'users_count'       => self::type( 'integer' ),
					'admins_count'      => self::type( 'integer' ),
					'content_count'     => self::type( 'integer' ),
					'media_count'       => self::type( 'integer' ),
					'disk_bytes'        => self::type( [ 'integer', 'null' ] ),
					'disk_is_estimate'  => self::type( 'boolean' ),
					'db_bytes'          => self::type( [ 'integer', 'null' ] ),
					'autoload_bytes'    => self::type( [ 'integer', 'null' ] ),
					'last_activity_gmt' => self::date(),
					'alert_level'       => self::enum( Severity::names() ),
					'alerts_count'      => self::type( 'integer' ),
					'alert_rules'       => self::list_of( self::type( 'string' ) ),
					'registry_status'   => self::enum( SitesQuery::REGISTRY_STATUSES ),
					'pending'           => self::type( 'boolean' ),
					'dirty'             => self::type( 'boolean' ),
					'scanned_at_gmt'    => self::date(),
				]
			)
		);
	}

	/**
	 * La fiche d'un site (SitesQuery::get()). Les objets imbriqués venus de la collecte restent ouverts.
	 */
	public static function site_detail(): array {
		$schema                = self::site();
		$schema['properties'] += [
			'post_types'   => self::list_of( self::type( 'object' ) ),
			'taxonomies'   => self::list_of( self::type( 'object' ) ),
			'users'        => self::type( 'object' ),
			'last_content' => self::type( [ 'object', 'null' ] ),
			'options'      => self::type( 'object' ),
			'cron'         => self::type( [ 'object', 'null' ] ),
			'alerts'       => self::list_of(
				self::object(
					[
						'rule'     => self::type( 'string' ),
						'severity' => self::type( 'string' ),
						'label'    => self::type( 'string' ),
						'message'  => self::type( 'string' ),
					]
				)
			),
			'extensions'   => self::type( 'object' ),
			'scan_error'   => self::type( [ 'object', 'null' ] ),
		];
		return $schema;
	}

	/**
	 * Un plugin de l'inventaire (PluginsQuery::all()).
	 */
	public static function plugin(): array {
		return self::object(
			[
				'id'             => self::type( 'string' ),
				'file'           => self::type( 'string' ),
				'name'           => self::type( 'string' ),
				'version'        => self::type( 'string' ),
				'installed'      => self::type( 'boolean' ),
				'network_active' => self::type( 'boolean' ),
				'sites_count'    => self::type( 'integer' ),
				'status'         => self::enum( PluginsQuery::STATUSES ),
				'update_version' => self::type( [ 'string', 'null' ] ),
			]
		);
	}

	/**
	 * Un thème de l'inventaire (ThemesQuery::all()).
	 */
	public static function theme(): array {
		return self::object(
			[
				'id'                 => self::type( 'string' ),
				'stylesheet'         => self::type( 'string' ),
				'name'               => self::type( 'string' ),
				'version'            => self::type( 'string' ),
				'installed'          => self::type( 'boolean' ),
				'parent'             => self::type( [ 'string', 'null' ] ),
				'allowed_on_network' => self::type( 'boolean' ),
				'active_count'       => self::type( 'integer' ),
				'parent_count'       => self::type( 'integer' ),
				'sites_count'        => self::type( 'integer' ),
				'status'             => self::enum( ThemesQuery::STATUSES ),
				'update_version'     => self::type( [ 'string', 'null' ] ),
			]
		);
	}

	/**
	 * Un compte du réseau (UsersQuery::list()). Jamais d'adresse e-mail.
	 */
	public static function user(): array {
		return self::object(
			[
				'id'             => self::type( 'integer' ),
				'login'          => self::type( 'string' ),
				'display_name'   => self::type( 'string' ),
				'super_admin'    => self::type( 'boolean' ),
				'sites_count'    => self::type( 'integer' ),
				'registered_gmt' => self::date(),
				'edit_url'       => self::type( 'string' ),
			]
		);
	}

	/**
	 * Une paire site × règle (AlertsQuery::list()).
	 */
	public static function alert(): array {
		return self::object(
			[
				'id'       => self::type( 'string' ),
				'site'     => self::object( self::identity() ),
				'rule'     => self::type( 'string' ),
				'label'    => self::type( 'string' ),
				'severity' => self::enum( [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ),
				'message'  => self::type( 'string' ),
			]
		);
	}

	/**
	 * Synthèse des alertes (AlertsQuery::summary()).
	 */
	public static function alerts_summary(): array {
		return self::object(
			[
				'total_sites'       => self::type( 'integer' ),
				'scanned_sites'     => self::type( 'integer' ),
				'pending_sites'     => self::type( 'integer' ),
				'sites_with_alerts' => self::type( 'integer' ),
				'by_severity'       => self::counts( [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ),
				'by_rule'           => self::list_of(
					self::object(
						[
							'rule'     => self::type( 'string' ),
							'label'    => self::type( 'string' ),
							'severity' => self::type( 'string' ),
							'enabled'  => self::type( 'boolean' ),
							'count'    => self::type( 'integer' ),
						]
					)
				),
			]
		);
	}

	/**
	 * Synthèse de l'inventaire (InventoryQuery::summary()).
	 */
	public static function inventory_summary(): array {
		return self::object(
			[
				'pending_sites' => self::type( 'integer' ),
				'networks'      => self::type( 'integer' ),
				'plugins'       => self::counts( [ 'installed', 'network', 'unused', 'missing', 'updates' ] ),
				'themes'        => self::counts( [ 'installed', 'unused', 'missing', 'updates' ] ),
			]
		);
	}

	/**
	 * État de l'analyse (ScanStatusQuery::status()).
	 */
	public static function scan_status(): array {
		return self::object(
			[
				'total'              => self::type( 'integer' ),
				'remaining'          => self::type( 'integer' ),
				'pending'            => self::type( 'integer' ),
				'locked'             => self::type( 'boolean' ),
				'last_full_scan_gmt' => self::date(),
				'next_run_gmt'       => self::date(),
			]
		);
	}

	/**
	 * Une page d'une liste, telle que la renvoient les abilities (en REST, la pagination passe par les en-têtes).
	 */
	public static function page_of( array $item ): array {
		return self::object(
			[
				'items'       => self::list_of( $item ),
				'total'       => self::type( 'integer' ),
				'page'        => self::type( 'integer' ),
				'per_page'    => self::type( 'integer' ),
				'total_pages' => self::type( 'integer' ),
			]
		);
	}

	/**
	 * Schéma d'élément d'une route REST : la version de JSON Schema et un titre en plus.
	 */
	public static function for_rest( string $title, array $schema ): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => $title,
			],
			$schema
		);
	}

	/**
	 * Identité d'un site (SitesQuery::identity()). Une adresse d'administration peut être vide : pas de format « uri ».
	 */
	private static function identity(): array {
		return [
			'id'        => self::type( 'integer' ),
			'name'      => self::type( 'string' ),
			'url'       => self::type( 'string' ),
			'admin_url' => self::type( 'string' ),
		];
	}

	/**
	 * @param string[] $keys Compteurs entiers.
	 */
	private static function counts( array $keys ): array {
		return self::object( array_fill_keys( $keys, self::type( 'integer' ) ) );
	}

	private static function object( array $properties ): array {
		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	private static function list_of( array $items ): array {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}

	/**
	 * @param string|string[] $type Type JSON, ou liste de types (dont « null »).
	 */
	private static function type( $type ): array {
		return [ 'type' => $type ];
	}

	/**
	 * @param string[] $values Valeurs possibles.
	 */
	private static function enum( array $values ): array {
		return [
			'type' => 'string',
			'enum' => array_values( $values ),
		];
	}

	/**
	 * Date UTC sans décalage (Y-m-d\TH:i:s), ou null.
	 */
	private static function date(): array {
		return [
			'type'   => [ 'string', 'null' ],
			'format' => 'date-time',
		];
	}
}
```

- [ ] **Step 4: Create `includes/Query/ScanStatusQuery.php`**

Le corps est celui de `ScanController::status()`, déplacé tel quel :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * État de l'analyse du réseau courant : sites, file, verrou, dernière analyse complète, prochain passage du cron.
 * Partagé par la route GET /scan/status et l'ability multisite-radar/network-summary.
 */
final class ScanStatusQuery {

	private SitesRepository $sites;
	private Lock $lock;

	public function __construct( SitesRepository $sites, Lock $lock ) {
		$this->sites = $sites;
		$this->lock  = $lock;
	}

	/**
	 * @return array{total: int, remaining: int, pending: int, locked: bool, last_full_scan_gmt: string|null, next_run_gmt: string|null}
	 */
	public function status(): array {
		$network_id = get_current_network_id();
		$last       = (int) get_site_option( Queue::LAST_FULL_SCAN, 0 );
		$next       = Queue::next_run();
		return [
			'total'              => $this->sites->count_all( $network_id ),
			'remaining'          => $this->sites->count_dirty( $network_id ),
			'pending'            => $this->sites->count_pending( $network_id ),
			'locked'             => $this->lock->is_locked(),
			'last_full_scan_gmt' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s', $last ) : null,
			'next_run_gmt'       => null !== $next ? gmdate( 'Y-m-d\TH:i:s', $next ) : null,
		];
	}
}
```

- [ ] **Step 5: Use the shared schemas in the REST controllers**

`includes/Rest/SitesController.php` :
- ajouter `use MultisiteRadar\Query\Schemas;` (ordre alphabétique des `use`) ;
- remplacer tout le corps de `get_item_schema()` par :

```php
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = Schemas::for_rest( 'msradar-site', Schemas::site() );
		}
		return $this->add_additional_fields_schema( $this->schema );
	}
```

Les `use` de `Severity` et de `SitesQuery` restent : `get_collection_params()` s'en sert.

`includes/Rest/PluginsController.php` :
- ajouter `use MultisiteRadar\Query\Schemas;` ;
- dans le premier `register_rest_route()` (`/plugins`), ajouter après le tableau du point d'entrée, au même niveau : `'schema' => [ $this, 'get_public_item_schema' ],` ;
- ajouter la méthode :

```php
	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-plugin', Schemas::plugin() );
	}
```

`includes/Rest/ThemesController.php` : comme `PluginsController`, sur la route `/themes`, avec :

```php
	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-theme', Schemas::theme() );
	}
```

`includes/Rest/UsersController.php` : comme `PluginsController`, sur la route `/users`, avec :

```php
	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-user', Schemas::user() );
	}
```

`includes/Rest/AlertsController.php` :
- ajouter `use MultisiteRadar\Query\Schemas;` ;
- route `/alerts` : `'schema' => [ $this, 'get_public_item_schema' ],` ;
- route `/alerts/summary` : `'schema' => [ $this, 'get_summary_schema' ],` ;
- ajouter :

```php
	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-alert', Schemas::alert() );
	}

	public function get_summary_schema(): array {
		return Schemas::for_rest( 'msradar-alerts-summary', Schemas::alerts_summary() );
	}
```

`includes/Rest/InventoryController.php` :
- ajouter `use MultisiteRadar\Query\Schemas;` ;
- route `/inventory/summary` : `'schema' => [ $this, 'get_public_item_schema' ],` ;
- ajouter :

```php
	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-inventory-summary', Schemas::inventory_summary() );
	}
```

- [ ] **Step 6: Move the scan status out of `ScanController`**

`includes/Rest/ScanController.php` :
- ajouter `use MultisiteRadar\Query\Schemas;` et `use MultisiteRadar\Query\ScanStatusQuery;` ;
- ajouter la propriété `private ScanStatusQuery $status;`, le paramètre `ScanStatusQuery $status` en dernier dans le constructeur, et `$this->status = $status;` ;
- remplacer le corps de la méthode privée `status()` par `return $this->status->status();` (ses trois appels restent) ;
- sur la route `/scan/status`, ajouter `'schema' => [ $this, 'get_status_schema' ],` ;
- ajouter :

```php
	public function get_status_schema(): array {
		return Schemas::for_rest( 'msradar-scan-status', Schemas::scan_status() );
	}
```

Si `use MultisiteRadar\Scan\Queue;` n'est plus utilisé dans `ScanController` après ce déplacement, le retirer ; s'il l'est encore (`$this->queue`), le garder.

`includes/Plugin.php` :
- ajouter `use MultisiteRadar\Query\ScanStatusQuery;` (ordre alphabétique, après `use MultisiteRadar\Query\PluginsQuery;`) ;
- ajouter la propriété `private ?ScanStatusQuery $scan_status_query = null;` après `$sites_query` ;
- ajouter le service, après `sites_query()` :

```php
	public function scan_status_query(): ScanStatusQuery {
		return $this->scan_status_query ??= new ScanStatusQuery( $this->sites(), $this->lock() );
	}
```

- dans `register_rest_routes()`, remplacer la ligne de `ScanController` par :

```php
			new ScanController( $this->sites(), $this->runner(), $this->queue(), $this->lock(), $this->scan_status_query() ),
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `bin/test.sh --filter 'ItemSchemasTest|ScanControllerTest|SitesControllerTest|PluginsControllerTest|ThemesControllerTest|UsersControllerTest|AlertsControllerTest|InventoryControllerTest'`
Expected: PASS.

Si un élément renvoyé a une clé absente de son schéma (ou l'inverse), corriger **le schéma** pour qu'il décrive les données réelles, jamais les données.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus de la base de test).

- [ ] **Step 8: Commit**

```bash
git add includes/Query/Schemas.php includes/Query/ScanStatusQuery.php includes/Rest/ includes/Plugin.php tests/php/Rest/ItemSchemasTest.php
git commit -m "feat: publish the item schema of every read route and share the scan status"
```

### Task 2: Validation partagée des réglages — `SettingsUpdater`

**Files:**
- Create: `includes/Settings/SettingsUpdater.php`
- Modify: `includes/Rest/SettingsController.php`, `includes/Plugin.php`
- Test: `tests/php/Settings/SettingsUpdaterTest.php` (créé), `tests/php/Rest/SettingsControllerTest.php` (inchangé, doit rester vert)

**Interfaces:**
- Consumes : `Settings::update( array $patch ): array|WP_Error`, `RuleRegistry::get( string $id ): ?RuleInterface`, `RuleInterface::params_schema(): array`.
- Produces :
  - `Settings\SettingsUpdater::__construct( Settings $settings, RuleRegistry $rules )` ;
  - `SettingsUpdater::apply( array $patch ): array|WP_Error` : erreurs `msradar_unknown_rule` et `msradar_invalid_settings`, statut 400, réglages inchangés ;
  - `SettingsUpdater::patch_for( string $path, mixed $value ): ?array` (statique) ;
  - `Plugin::settings_updater(): SettingsUpdater` ;
  - `SettingsController::__construct( Settings $settings, SettingsUpdater $updater )`.

- [ ] **Step 1: Write the failing test**

Créer `tests/php/Settings/SettingsUpdaterTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Settings\SettingsUpdater;
use MultisiteRadar\Tests\TestCase;

final class SettingsUpdaterTest extends TestCase {

	private function updater(): SettingsUpdater {
		return new SettingsUpdater( $this->plugin()->settings(), $this->plugin()->rules() );
	}

	public function test_a_valid_change_is_saved_and_the_full_settings_are_returned(): void {
		$result = $this->updater()->apply( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 14, $result['scan']['full_rescan_days'] );
		$this->assertSame( [ 'post', 'page' ], $result['scan']['activity_post_types'], 'The other settings are kept.' );
		$this->assertSame( 14, $this->plugin()->settings()->get( 'scan.full_rescan_days' ) );
	}

	public function test_an_unknown_rule_is_refused_and_nothing_is_saved(): void {
		$before = get_site_option( Settings::OPTION, [] );

		$result = $this->updater()->apply(
			[
				'scan'   => [ 'full_rescan_days' => 14 ],
				'alerts' => [ 'rules' => [ 'acme_missing' => [ 'enabled' => false ] ] ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_unknown_rule', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, get_site_option( Settings::OPTION, [] ) );
	}

	public function test_rule_parameters_must_follow_the_schema_of_the_rule(): void {
		$result = $this->updater()->apply( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'params' => [ 'months' => 0 ] ] ] ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_invalid_settings', $result->get_error_code() );
	}

	public function test_a_value_outside_the_settings_schema_is_refused(): void {
		$this->assertWPError( $this->updater()->apply( [ 'scan' => [ 'full_rescan_days' => 0 ] ] ) );
		$this->assertWPError( $this->updater()->apply( [ 'scan' => [ 'unknown_key' => 1 ] ] ) );
		$this->assertSame( 7, $this->plugin()->settings()->get( 'scan.full_rescan_days' ) );
	}

	public function test_a_dotted_path_becomes_a_nested_change(): void {
		$this->assertSame( [ 'scan' => [ 'full_rescan_days' => 14 ] ], SettingsUpdater::patch_for( 'scan.full_rescan_days', 14 ) );
		$this->assertSame( [ 'integrations' => [ 'mcp_public' => true ] ], SettingsUpdater::patch_for( 'integrations.mcp_public', true ) );
		$this->assertSame(
			[ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ],
			SettingsUpdater::patch_for( 'alerts.rules.inactive', [ 'enabled' => false ] )
		);
		$this->assertNull( SettingsUpdater::patch_for( '', 1 ) );
		$this->assertNull( SettingsUpdater::patch_for( 'scan..full_rescan_days', 1 ) );
		$this->assertNull( SettingsUpdater::patch_for( 'scan.', 1 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/test.sh --filter SettingsUpdaterTest`
Expected: FAIL with `Class "MultisiteRadar\Settings\SettingsUpdater" not found`.

- [ ] **Step 3: Create `includes/Settings/SettingsUpdater.php`**

La boucle sur les règles est celle de `SettingsController::update_settings()`, déplacée telle quelle :

```php
<?php
namespace MultisiteRadar\Settings;

use MultisiteRadar\Alerts\RuleRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applique une modification des réglages venue de l'extérieur (route REST, WP-CLI) : chaque règle d'alertes nommée doit
 * exister et ses paramètres suivre le schéma de la règle ; Settings::update() valide ensuite le reste et enregistre.
 * Rien n'est enregistré si une partie est refusée.
 */
final class SettingsUpdater {

	private Settings $settings;
	private RuleRegistry $rules;

	public function __construct( Settings $settings, RuleRegistry $rules ) {
		$this->settings = $settings;
		$this->rules    = $rules;
	}

	/**
	 * @return array|WP_Error Réglages complets après mise à jour, ou erreur (statut 400).
	 */
	public function apply( array $patch ) {
		$rules = $patch['alerts']['rules'] ?? [];
		foreach ( is_array( $rules ) ? $rules : [] as $rule_id => $config ) {
			$rule = $this->rules->get( (string) $rule_id );
			if ( null === $rule ) {
				return new WP_Error(
					'msradar_unknown_rule',
					/* translators: %s: alert rule identifier. */
					sprintf( __( 'Unknown alert rule: %s', 'multisite-radar' ), (string) $rule_id ),
					[ 'status' => 400 ]
				);
			}
			if ( is_array( $config ) && array_key_exists( 'params', $config ) ) {
				$valid = rest_validate_value_from_schema( $config['params'], $rule->params_schema(), 'params' );
				if ( is_wp_error( $valid ) ) {
					return new WP_Error( 'msradar_invalid_settings', $valid->get_error_message(), [ 'status' => 400 ] );
				}
			}
		}

		return $this->settings->update( $patch );
	}

	/**
	 * Modification qui porte $value au chemin pointé $path, ex. « scan.full_rescan_days » → [ 'scan' => [ … ] ].
	 *
	 * @param string $path  Chemin pointé.
	 * @param mixed  $value Nouvelle valeur.
	 * @return array|null Null si le chemin est vide ou contient un segment vide.
	 */
	public static function patch_for( string $path, $value ): ?array {
		$keys = explode( '.', $path );
		if ( in_array( '', $keys, true ) ) {
			return null;
		}
		$patch = $value;
		foreach ( array_reverse( $keys ) as $key ) {
			$patch = [ $key => $patch ];
		}
		return $patch;
	}
}
```

- [ ] **Step 4: Use it in `SettingsController`**

Dans `includes/Rest/SettingsController.php` :
- remplacer `use MultisiteRadar\Alerts\RuleRegistry;` par `use MultisiteRadar\Settings\SettingsUpdater;` (garder l'ordre alphabétique : `Settings\Settings`, puis `Settings\SettingsUpdater`) ;
- remplacer la propriété `private RuleRegistry $rules;` par `private SettingsUpdater $updater;` ;
- remplacer le constructeur et `update_settings()` par :

```php
	public function __construct( Settings $settings, SettingsUpdater $updater ) {
		$this->settings = $settings;
		$this->updater  = $updater;
	}
```

```php
	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$patch = (array) $request->get_json_params();
		if ( [] === $patch ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$result = $this->updater->apply( $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
```

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Settings\SettingsUpdater;` après `use MultisiteRadar\Settings\Settings;` ;
- ajouter la propriété `private ?SettingsUpdater $settings_updater = null;` après `$settings` ;
- ajouter le service, après `settings()` :

```php
	public function settings_updater(): SettingsUpdater {
		return $this->settings_updater ??= new SettingsUpdater( $this->settings(), $this->rules() );
	}
```

- dans `register_rest_routes()`, remplacer `new SettingsController( $this->settings(), $this->rules() ),` par `new SettingsController( $this->settings(), $this->settings_updater() ),`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'SettingsUpdaterTest|SettingsControllerTest|SettingsTest'`
Expected: PASS. `SettingsControllerTest` passe sans modification : la route garde ses codes d'erreur et ses messages.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Settings/SettingsUpdater.php includes/Rest/SettingsController.php includes/Plugin.php tests/php/Settings/SettingsUpdaterTest.php
git commit -m "refactor: share the settings validation between the REST route and future commands"
```

## Partie B — WP-CLI

### Task 3: Base des commandes, `sites list` sur les nouvelles aides, commande `alerts`

**Files:**
- Create: `includes/Cli/Command.php`, `includes/Cli/Rows.php`, `includes/Cli/Pages.php`, `includes/Cli/AlertsCommand.php`
- Modify: `includes/Cli/SitesCommand.php`, `includes/Cli/RadarCommand.php`, `bin/e2e.sh`
- Test: `tests/php/Cli/RowsTest.php`, `tests/php/Cli/PagesTest.php` (créés) ; `bin/e2e.sh`

**Interfaces:**
- Consumes : `SitesQuery::list()`, `AlertsQuery::list()` (forme `array{items: array[], total: int}`, au plus 100 par page), `SitesQuery::MAX_PAGE`, `Plugin::rules()->get()`, `Plugin::alerts_query()`.
- Produces :
  - `Cli\Command` (abstraite) : `__construct( Plugin $plugin )`, `protected Plugin $plugin`, `protected static read_failed( string $context, \RuntimeException $error ): void` ;
  - `Cli\Rows::site( array $item ): array`, `Cli\Rows::alert( array $item ): array{site_id: int, site: string, url: string, rule: string, label: string, severity: string, message: string}` ;
  - `Cli\Pages::PER_PAGE = 100`, `Cli\Pages::collect( callable $fetch ): array` (`$fetch( int $page, int $per_page ): array{items: array[], total: int}`) ;
  - commande `wp multisite-radar alerts` ;
  - dans `bin/e2e.sh` : la fonction `expect <description> <obtenu> <attendu>`, le site « Discret » (`$HIDDEN_ID`, masqué aux moteurs de recherche) et la section « WP-CLI commands » que les tâches 4 à 8 complètent.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Cli/RowsTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Rows;
use MultisiteRadar\Tests\TestCase;

final class RowsTest extends TestCase {

	public function test_a_site_row_has_one_scalar_per_column(): void {
		$row = Rows::site(
			[
				'id'          => 5,
				'name'        => 'Blog',
				'theme'       => [
					'stylesheet' => 'child',
					'template'   => 'parent',
				],
				'status'      => [
					'public'   => true,
					'archived' => false,
					'spam'     => true,
					'deleted'  => false,
				],
				'alert_rules' => [ 'inactive', 'no_admin' ],
			]
		);

		$this->assertSame(
			[
				'id'          => 5,
				'name'        => 'Blog',
				'theme'       => 'child',
				'status'      => 'public,spam',
				'alert_rules' => 'inactive,no_admin',
			],
			$row
		);
	}

	public function test_an_alert_row_names_its_site(): void {
		$row = Rows::alert(
			[
				'id'       => '5:inactive',
				'site'     => [
					'id'        => 5,
					'name'      => 'Blog',
					'url'       => 'http://example.org/blog/',
					'admin_url' => 'http://example.org/blog/wp-admin/',
				],
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => 'No activity for 7 months.',
			]
		);

		$this->assertSame(
			[
				'site_id'  => 5,
				'site'     => 'Blog',
				'url'      => 'http://example.org/blog/',
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => 'No activity for 7 months.',
			],
			$row
		);
	}
}
```

Créer `tests/php/Cli/PagesTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Pages;
use MultisiteRadar\Tests\TestCase;

final class PagesTest extends TestCase {

	public function test_reads_every_page_until_the_total_is_reached(): void {
		$all   = range( 1, 250 );
		$calls = [];
		$items = Pages::collect(
			static function ( int $page, int $per_page ) use ( $all, &$calls ): array {
				$calls[] = [ $page, $per_page ];
				return [
					'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
					'total' => count( $all ),
				];
			}
		);

		$this->assertSame( $all, $items );
		$this->assertSame( [ [ 1, 100 ], [ 2, 100 ], [ 3, 100 ] ], $calls );
	}

	public function test_stops_on_an_empty_page_when_items_disappeared_between_reads(): void {
		$calls = 0;
		$items = Pages::collect(
			static function ( int $page ) use ( &$calls ): array {
				++$calls;
				return [
					'items' => 1 === $page ? [ 'a' ] : [],
					'total' => 5,
				];
			}
		);

		$this->assertSame( [ 'a' ], $items );
		$this->assertSame( 2, $calls );
	}

	public function test_a_failed_read_is_passed_on(): void {
		$this->expectException( \RuntimeException::class );
		Pages::collect(
			static function (): array {
				throw new \RuntimeException( 'read failed' );
			}
		);
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'RowsTest|PagesTest'`
Expected: FAIL with `Class "MultisiteRadar\Cli\Rows" not found` (et `Pages`).

- [ ] **Step 3: Create the helpers**

`includes/Cli/Command.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Base des commandes : accès aux services. WP-CLI fait une sous-commande de chaque méthode publique : les aides
 * restent donc protégées.
 */
abstract class Command {

	protected Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Une lecture a échoué : l'erreur est signalée à msradar_error, puis la commande s'arrête (code de sortie 1).
	 */
	protected static function read_failed( string $context, \RuntimeException $error ): void {
		do_action( 'msradar_error', $context, $error );
		WP_CLI::error( 'Multisite Radar could not read its data. Try again in a moment.' );
	}
}
```

`includes/Cli/Rows.php` (la conversion d'un site est celle de `SitesCommand::flatten()`, déplacée) :

```php
<?php
namespace MultisiteRadar\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Lignes à plat pour WP_CLI\Utils\format_items() : une valeur scalaire par colonne, les listes jointes par des virgules.
 */
final class Rows {

	/**
	 * Un site de SitesQuery::summary().
	 */
	public static function site( array $item ): array {
		$item['theme']       = (string) ( $item['theme']['stylesheet'] ?? '' );
		$item['status']      = implode( ',', array_keys( array_filter( (array) $item['status'] ) ) );
		$item['alert_rules'] = implode( ',', (array) $item['alert_rules'] );
		return $item;
	}

	/**
	 * Une paire site × règle de AlertsQuery::list().
	 *
	 * @return array{site_id: int, site: string, url: string, rule: string, label: string, severity: string, message: string}
	 */
	public static function alert( array $item ): array {
		return [
			'site_id'  => (int) $item['site']['id'],
			'site'     => (string) $item['site']['name'],
			'url'      => (string) $item['site']['url'],
			'rule'     => (string) $item['rule'],
			'label'    => (string) $item['label'],
			'severity' => (string) $item['severity'],
			'message'  => (string) $item['message'],
		];
	}
}
```

`includes/Cli/Pages.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\SitesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Lit toutes les pages d'une liste paginée des services Query (au plus 100 éléments par page).
 */
final class Pages {

	public const PER_PAGE = 100;

	/**
	 * S'arrête au total annoncé, sur une page vide (des éléments ont disparu entre deux lectures) ou à la dernière page
	 * que les services acceptent.
	 *
	 * @param callable $fetch Reçoit ( int $page, int $per_page ), renvoie array{items: array[], total: int}.
	 * @return array[] Tous les éléments, dans l'ordre des pages.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public static function collect( callable $fetch ): array {
		$items = [];
		$page  = 1;
		do {
			$result = $fetch( $page, self::PER_PAGE );
			foreach ( $result['items'] as $item ) {
				$items[] = $item;
			}
			++$page;
		} while ( [] !== $result['items'] && count( $items ) < $result['total'] && $page <= SitesQuery::MAX_PAGE );
		return $items;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter 'RowsTest|PagesTest'`
Expected: PASS.

- [ ] **Step 5: Move `sites list` to the helpers**

Remplacer le contenu de `includes/Cli/SitesCommand.php` par (docblock de la commande inchangé) :

```php
<?php
namespace MultisiteRadar\Cli;

use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sites of the network as last scanned.
 */
final class SitesCommand extends Command {

	private const DEFAULT_FIELDS = 'id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count';

	/**
	 * Lists the sites of the network as last scanned.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filter on name or URL.
	 *
	 * [--alert=<level>]
	 * : Only sites whose highest alert has this level.
	 * ---
	 * options:
	 *   - none
	 *   - info
	 *   - warning
	 *   - error
	 * ---
	 *
	 * [--theme=<stylesheet>]
	 * : Only sites using this theme (active or parent).
	 *
	 * [--plugin=<file>]
	 * : Only sites where this plugin is active, e.g. akismet/akismet.php.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields.
	 * ---
	 * default: id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp multisite-radar sites list --alert=error --format=csv
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$query  = $this->plugin->sites_query();
		$filter = [
			'orderby'     => 'id',
			'search'      => (string) ( $assoc_args['search'] ?? '' ),
			'alert_level' => isset( $assoc_args['alert'] ) ? [ (string) $assoc_args['alert'] ] : [],
			'theme'       => (string) ( $assoc_args['theme'] ?? '' ),
			'plugin'      => (string) ( $assoc_args['plugin'] ?? '' ),
		];
		try {
			$items = Pages::collect(
				static function ( int $page, int $per_page ) use ( $query, $filter ): array {
					return $query->list(
						array_merge(
							$filter,
							[
								'page'     => $page,
								'per_page' => $per_page,
							]
						)
					);
				}
			);
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), array_map( [ Rows::class, 'site' ], $items ), (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
```

- [ ] **Step 6: Create the `alerts` command**

`includes/Cli/AlertsCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use WP_CLI;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the alerts of the network, one line per site and rule.
 */
final class AlertsCommand extends Command {

	private const DEFAULT_FIELDS = 'site_id,site,rule,severity,message';

	/**
	 * Lists the alerts of the network, one line per site and rule.
	 *
	 * Only enabled rules are listed, with the severity of the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<severity>]
	 * : Only alerts of this severity.
	 * ---
	 * options:
	 *   - error
	 *   - warning
	 *   - info
	 * ---
	 *
	 * [--rule=<rule>]
	 * : Only alerts of this rule, e.g. inactive.
	 *
	 * [--search=<text>]
	 * : Filter on the site name or URL.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: site_id, site, url, rule, label, severity, message.
	 * ---
	 * default: site_id,site,rule,severity,message
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Errors only, as CSV.
	 *     $ wp multisite-radar alerts --severity=error --format=csv
	 *
	 *     # Sites hidden from search engines.
	 *     $ wp multisite-radar alerts --rule=search_hidden
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$rule = (string) ( $assoc_args['rule'] ?? '' );
		if ( '' !== $rule && null === $this->plugin->rules()->get( $rule ) ) {
			WP_CLI::error( sprintf( 'Unknown alert rule: %s', $rule ) );
			return;
		}
		$query  = $this->plugin->alerts_query();
		$filter = [
			'severity' => isset( $assoc_args['severity'] ) ? [ (string) $assoc_args['severity'] ] : [],
			'rule'     => '' !== $rule ? [ $rule ] : [],
			'search'   => (string) ( $assoc_args['search'] ?? '' ),
			'orderby'  => 'rule',
		];
		try {
			$items = Pages::collect(
				static function ( int $page, int $per_page ) use ( $query, $filter ): array {
					return $query->list(
						array_merge(
							$filter,
							[
								'page'     => $page,
								'per_page' => $per_page,
							]
						)
					);
				}
			);
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), array_map( [ Rows::class, 'alert' ], $items ), (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
```

Dans `includes/Cli/RadarCommand.php`, `register()` devient :

```php
	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'multisite-radar', new self( $plugin ) );
		WP_CLI::add_command( 'multisite-radar sites', new SitesCommand( $plugin ) );
		WP_CLI::add_command( 'multisite-radar alerts', new AlertsCommand( $plugin ) );
	}
```

- [ ] **Step 7: Check the commands end to end in `bin/e2e.sh`**

Dans `bin/e2e.sh` :

1. En tête, remplacer les deux premières lignes de commentaire du test par :

```bash
# Test d'acceptation de la spec §1.4 n° 1, sur un multisite neuf installé avec WP-CLI :
# un CPT enregistré par un plugin actif uniquement sur /rh/ apparaît sur /rh/ avec son libellé
# et son origine plugin, et n'apparaît pas sur le site principal.
# Puis les commandes WP-CLI de Multisite Radar, sur ce même réseau.
```

   et la ligne `# Requis : …` par :

```bash
# Requis : E2E_DB_NAME, E2E_DB_USER, E2E_DB_PASSWORD, E2E_DB_HOST (base existante, sans tables « msre2e_* ») ; jq.
```

2. Après la fonction `fail()`, ajouter :

```bash
# expect <description> <valeur obtenue> <valeur attendue>
expect() {
	if [ "$2" != "$3" ]; then
		fail "$1: got '$2', expected '$3'."
	fi
	echo "ok - $1"
}

command -v jq >/dev/null 2>&1 || fail "jq is required."
```

3. Remplacer la dernière ligne (`echo "E2E OK: the demo CPT is reported on /rh/ only, with its label and plugin origin."`) par :

```bash
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

echo "E2E OK"
```

Les tâches suivantes ajoutent leurs vérifications **juste avant** la ligne finale `echo "E2E OK"`.

- [ ] **Step 8: Run the end-to-end script**

Run: `shellcheck bin/e2e.sh`
Expected: aucune remarque.

Run: `E2E_DB_NAME=wordpress_test E2E_DB_USER=wordpress E2E_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)" E2E_DB_HOST=127.0.0.1:3306 E2E_WP_VERSION=7.1 bin/e2e.sh`
Expected: les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 9: Commit**

```bash
git add includes/Cli/ tests/php/Cli/ bin/e2e.sh
git commit -m "feat: add the wp multisite-radar alerts command"
```

### Task 4: Commandes `plugins list` et `themes list`

**Files:**
- Create: `includes/Cli/PluginsCommand.php`, `includes/Cli/ThemesCommand.php`
- Modify: `includes/Cli/RadarCommand.php`, `bin/e2e.sh`
- Test: `bin/e2e.sh`

**Interfaces:**
- Consumes : `Cli\Command`, `PluginsQuery::filtered( array $args ): array[]`, `ThemesQuery::filtered( array $args ): array[]`, `PluginsQuery::STATUS_UNUSED`, `ThemesQuery::STATUS_UNUSED`, `Plugin::plugins_query()`, `Plugin::themes_query()`.
- Produces : commandes `wp multisite-radar plugins list [--unused]` et `wp multisite-radar themes list [--unused]`.

Les éléments de l'inventaire sont déjà plats (aucun tableau imbriqué) : ils passent tels quels à `format_items()`.

- [ ] **Step 1: Write the failing end-to-end checks**

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
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
```

- [ ] **Step 2: Run the script to verify it fails**

Run: la commande `bin/e2e.sh` de la section « Commandes ».
Expected: FAIL, `'plugins list' is not a registered subcommand of 'multisite-radar'` puis `E2E FAILED: plugins list: the demo plugin is used by one site: got '', expected 'local 1'.`

- [ ] **Step 3: Create the commands**

`includes/Cli/PluginsCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\PluginsQuery;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the plugins of the network.
 */
final class PluginsCommand extends Command {

	private const DEFAULT_FIELDS = 'file,name,version,status,sites_count,update_version';

	/**
	 * Lists the plugins installed on the network, or still active on a site after their removal.
	 *
	 * Status: network (network-activated), local (active on some sites), unused, or missing (active on a site but no
	 * longer installed). Sites waiting for their first analysis are not counted, except for network-activated plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--unused]
	 * : Only installed plugins that no site uses.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: id, file, name, version, installed, network_active, sites_count, status, update_version.
	 * ---
	 * default: file,name,version,status,sites_count,update_version
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Plugins that could be deleted.
	 *     $ wp multisite-radar plugins list --unused
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$filter = get_flag_value( $assoc_args, 'unused', false ) ? [ 'status' => [ PluginsQuery::STATUS_UNUSED ] ] : [];
		try {
			$items = $this->plugin->plugins_query()->filtered( $filter );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
```

`includes/Cli/ThemesCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\ThemesQuery;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the themes of the network.
 */
final class ThemesCommand extends Command {

	private const DEFAULT_FIELDS = 'stylesheet,name,version,status,active_count,parent_count,update_version';

	/**
	 * Lists the themes installed on the network, or still active on a site after their removal.
	 *
	 * Status: used (active or parent theme of some sites), unused, or missing (used by a site but no longer installed).
	 * Sites waiting for their first analysis are not counted.
	 *
	 * ## OPTIONS
	 *
	 * [--unused]
	 * : Only installed themes that no site uses, as active or parent theme.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: id, stylesheet, name, version, installed, parent, allowed_on_network, active_count, parent_count, sites_count, status, update_version.
	 * ---
	 * default: stylesheet,name,version,status,active_count,parent_count,update_version
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Themes that could be deleted.
	 *     $ wp multisite-radar themes list --unused
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$filter = get_flag_value( $assoc_args, 'unused', false ) ? [ 'status' => [ ThemesQuery::STATUS_UNUSED ] ] : [];
		try {
			$items = $this->plugin->themes_query()->filtered( $filter );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
```

Dans `RadarCommand::register()`, ajouter après la ligne de `alerts` :

```php
		WP_CLI::add_command( 'multisite-radar plugins', new PluginsCommand( $plugin ) );
		WP_CLI::add_command( 'multisite-radar themes', new ThemesCommand( $plugin ) );
```

- [ ] **Step 4: Run the script to verify it passes**

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `composer lint && composer analyse`
Expected: vert.

- [ ] **Step 5: Commit**

```bash
git add includes/Cli/ bin/e2e.sh
git commit -m "feat: add the plugins list and themes list commands"
```

### Task 5: Commande `export`

**Files:**
- Create: `includes/Cli/ExportCommand.php`
- Modify: `includes/Export/ExportHandler.php`, `includes/Cli/RadarCommand.php`, `bin/e2e.sh`
- Test: `tests/php/Export/ExportHandlerTest.php`, `bin/e2e.sh`

**Interfaces:**
- Consumes : `ExportHandler::params( array $input ): array|WP_Error`, `ExportHandler::write( array $params, resource $stream, int $chunk = 500 ): int` (lève `\RuntimeException` après avoir écrit le marqueur d'interruption), `Plugin::export()`.
- Produces :
  - `ExportHandler::check( array $params ): void` (public ; lève `\RuntimeException`), utilisé par `handle()` et par la commande ;
  - commande `wp multisite-radar export --resource=<sites|plugins|themes> [--format=<csv|json>] [--fields=<…>] [--output=<file>] [--<filtre REST>=<valeur>]`.

- [ ] **Step 1: Write the failing test**

Dans `tests/php/Export/ExportHandlerTest.php`, ajouter (la classe a déjà l'aide `break_sites_reads()`) :

```php
	public function test_check_reads_the_resource_once_and_passes_a_failure_on(): void {
		global $wpdb;
		$params = $this->handler->params(
			[
				'resource' => 'sites',
				'format'   => 'json',
			]
		);
		$this->assertIsArray( $params );
		$this->handler->check( $params );

		$guard    = $this->break_sites_reads();
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $guard );
		try {
			$this->handler->check( $params );
			$this->fail( 'A failed read was expected.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		} finally {
			remove_filter( 'query', $guard );
			$wpdb->suppress_errors( $previous );
		}
	}
```

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
EXPORT="$WORK/sites.json"
wpe multisite-radar export --resource=sites --format=json --output="$EXPORT" >/dev/null
expect "export --output writes the sites as JSON" "$(jq -r '"\(.meta.resource) \(.items | length)"' "$EXPORT")" "sites 3"
expect "export applies the filters of the REST route" \
	"$(wpe multisite-radar export --resource=sites --format=json --alert_level=info | jq -r '.items | map(.id | tostring) | join(",")')" "$HIDDEN_ID"
CSV="$(wpe multisite-radar export --resource=plugins --fields=file,status)"
expect "export without --output writes CSV on the standard output" \
	"$(grep -c '^msradar-demo-cpt/msradar-demo-cpt.php,local' <<<"$CSV")" "1"
if wpe multisite-radar export --resource=sites --output="$WORK/missing/sites.csv" >/dev/null 2>&1; then
	fail "export to a missing folder should fail."
fi
[ ! -e "$WORK/missing" ] || fail "export to a missing folder created it."
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter ExportHandlerTest`
Expected: FAIL with `Call to undefined method MultisiteRadar\Export\ExportHandler::check()`.

- [ ] **Step 3: Make the first read public**

Dans `includes/Export/ExportHandler.php`, ajouter après `stream()` :

```php
	/**
	 * Première lecture de la ressource, avant toute écriture : une base illisible donne encore une vraie erreur.
	 *
	 * @param array $params Résultat de params().
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function check( array $params ): void {
		$this->sources[ $params['resource'] ]->check( (array) $params['filters'] );
	}
```

et, dans `handle()`, remplacer `$this->sources[ $params['resource'] ]->check( $params['filters'] );` par `$this->check( $params );`.

Run: `bin/test.sh --filter ExportHandlerTest`
Expected: PASS.

- [ ] **Step 4: Create the command**

`includes/Cli/ExportCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Exports the sites, plugins or themes of the network as CSV or JSON.
 */
final class ExportCommand extends Command {

	/**
	 * Exports the sites, plugins or themes of the network, like the export buttons of the admin pages.
	 *
	 * Any filter of the matching REST route can be given as an option, e.g. --alert_level=error,warning or
	 * --search=blog for sites, --status=unused for plugins and themes. Lists are comma-separated.
	 * Without --output, the file is written to the standard output and nothing else is printed.
	 *
	 * ## OPTIONS
	 *
	 * --resource=<resource>
	 * : Data to export.
	 * ---
	 * options:
	 *   - sites
	 *   - plugins
	 *   - themes
	 * ---
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of columns. Default: every column.
	 *
	 * [--output=<file>]
	 * : Write to this file instead of the standard output. An existing file is replaced.
	 *
	 * [--<filter>=<value>]
	 * : A filter of the REST route of the resource.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sites with errors or warnings, as JSON.
	 *     $ wp multisite-radar export --resource=sites --format=json --alert_level=error,warning --output=sites.json
	 *     Success: 12 item(s) exported to sites.json.
	 *
	 *     # Unused plugins, as CSV on the standard output.
	 *     $ wp multisite-radar export --resource=plugins --status=unused > unused-plugins.csv
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$handler = $this->plugin->export();
		$params  = $handler->params( $assoc_args );
		if ( is_wp_error( $params ) ) {
			WP_CLI::error( $params->get_error_message() );
			return;
		}

		$output = (string) ( $assoc_args['output'] ?? '' );
		if ( '' !== $output && ! is_dir( dirname( $output ) ) ) {
			WP_CLI::error( sprintf( 'The folder of %s does not exist.', $output ) );
			return;
		}

		try {
			$handler->check( $params );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		$stream = fopen( '' !== $output ? $output : 'php://stdout', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed export.
		if ( false === $stream ) {
			WP_CLI::error( sprintf( 'Could not write to %s.', $output ) );
			return;
		}
		try {
			$count = $handler->write( $params, $stream );
		} catch ( \RuntimeException $error ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
			do_action( 'msradar_error', __METHOD__, $error );
			WP_CLI::error( 'The export is incomplete: the data could not be read to the end. Run the export again.' );
			return;
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.

		if ( '' !== $output ) {
			WP_CLI::success( sprintf( '%d item(s) exported to %s.', $count, $output ) );
		}
	}
}
```

Notes :
- `params()` ignore les options qu'il ne connaît pas (`output`, et les options globales de WP-CLI ne figurent pas dans `$assoc_args`) et ne garde que les filtres de la ressource ;
- un fichier non inscriptible donne `false` à `fopen()` avec un avertissement PHP de WP-CLI, puis l'erreur ci-dessus : c'est acceptable.

Dans `RadarCommand::register()`, ajouter après la ligne de `themes` :

```php
		WP_CLI::add_command( 'multisite-radar export', new ExportCommand( $plugin ) );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Cli/ includes/Export/ExportHandler.php tests/php/Export/ExportHandlerTest.php bin/e2e.sh
git commit -m "feat: add the wp multisite-radar export command"
```

### Task 6: Commandes `settings get` et `settings set`

**Files:**
- Create: `includes/Cli/Values.php`, `includes/Cli/SettingsCommand.php`
- Modify: `includes/Cli/RadarCommand.php`, `bin/e2e.sh`
- Test: `tests/php/Cli/ValuesTest.php` (créé), `bin/e2e.sh`

**Interfaces:**
- Consumes : `Settings::all()`, `Settings::get( string $path, $fallback )`, `SettingsUpdater::patch_for()`, `Plugin::settings_updater()->apply()` (tâche 2).
- Produces :
  - `Cli\Values::parse( string $raw ): mixed` ;
  - commandes `wp multisite-radar settings get [<key>] [--format=<json|yaml>]` et `wp multisite-radar settings set <key> <value>`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Cli/ValuesTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Values;
use MultisiteRadar\Tests\TestCase;

final class ValuesTest extends TestCase {

	public function test_json_values_keep_their_type(): void {
		$this->assertSame( 14, Values::parse( '14' ) );
		$this->assertTrue( Values::parse( 'true' ) );
		$this->assertFalse( Values::parse( 'false' ) );
		$this->assertNull( Values::parse( 'null' ) );
		$this->assertSame( [ 'post', 'page' ], Values::parse( '["post","page"]' ) );
		$this->assertSame( [ 'enabled' => false ], Values::parse( '{"enabled":false}' ) );
		$this->assertSame( '7', Values::parse( '"7"' ) );
	}

	public function test_anything_else_is_kept_as_text(): void {
		$this->assertSame( 'post', Values::parse( 'post' ) );
		$this->assertSame( '', Values::parse( '' ) );
		$this->assertSame( '[post', Values::parse( '[post' ) );
	}
}
```

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter ValuesTest`
Expected: FAIL with `Class "MultisiteRadar\Cli\Values" not found`.

- [ ] **Step 3: Create `Values` and the command**

`includes/Cli/Values.php` :

```php
<?php
namespace MultisiteRadar\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Valeur saisie en ligne de commande.
 */
final class Values {

	/**
	 * Du JSON si c'en est (true, 14, ["post","page"], {"enabled":false}, null), le texte tel quel sinon.
	 *
	 * @return mixed
	 */
	public static function parse( string $raw ) {
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && 'null' !== trim( $raw ) ) {
			return $raw;
		}
		return $decoded;
	}
}
```

`includes/Cli/SettingsCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Settings\SettingsUpdater;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the settings of Multisite Radar.
 */
final class SettingsCommand extends Command {

	/**
	 * Prints the settings, or one setting by its dotted path.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Dotted path of a setting, e.g. scan.full_rescan_days or alerts.rules.inactive.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp multisite-radar settings get scan.full_rescan_days
	 *     7
	 *
	 *     $ wp multisite-radar settings get --format=yaml
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function get( array $args, array $assoc_args ): void {
		$settings = $this->plugin->settings();
		$value    = $settings->all();
		if ( isset( $args[0] ) ) {
			$missing = new \stdClass();
			$value   = $settings->get( (string) $args[0], $missing );
			if ( $missing === $value ) {
				WP_CLI::error( sprintf( 'Unknown setting: %s', (string) $args[0] ) );
				return;
			}
		}

		if ( 'yaml' === ( $assoc_args['format'] ?? 'json' ) ) {
			WP_CLI::print_value( $value, [ 'format' => 'yaml' ] );
			return;
		}
		WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Changes one setting, with the same validation as the settings page.
	 *
	 * The value is read as JSON when it is valid JSON (true, 14, ["post","page"], {"enabled":false}), as text
	 * otherwise. Like the settings page, changing an analysis setting or an alert rule schedules the matching background
	 * work (new analysis, alerts computed again).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Dotted path of the setting, e.g. scan.full_rescan_days.
	 *
	 * <value>
	 * : New value.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp multisite-radar settings set scan.full_rescan_days 14
	 *     Success: Updated scan.full_rescan_days.
	 *
	 *     $ wp multisite-radar settings set alerts.rules.inactive '{"params":{"months":12}}'
	 *     Success: Updated alerts.rules.inactive.
	 *
	 *     $ wp multisite-radar settings set integrations.mcp_public true
	 *     Success: Updated integrations.mcp_public.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function set( array $args, array $assoc_args ): void {
		$key   = (string) $args[0];
		$patch = SettingsUpdater::patch_for( $key, Values::parse( (string) $args[1] ) );
		if ( null === $patch ) {
			WP_CLI::error( sprintf( 'Invalid setting path: %s', $key ) );
			return;
		}

		$result = $this->plugin->settings_updater()->apply( $patch );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Updated %s.', $key ) );
	}
}
```

Dans `RadarCommand::register()`, ajouter après la ligne de `export` :

```php
		WP_CLI::add_command( 'multisite-radar settings', new SettingsCommand( $plugin ) );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter ValuesTest`
Expected: PASS.

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 5: Commit**

```bash
git add includes/Cli/ tests/php/Cli/ValuesTest.php bin/e2e.sh
git commit -m "feat: add the settings get and settings set commands"
```

## Partie C — Abilities et MCP

### Task 7: Infrastructure des abilities, `network-summary` et `list-sites`

**Files:**
- Create: `includes/Abilities/Ability.php`, `includes/Abilities/Registrar.php`, `includes/Abilities/NetworkSummaryAbility.php`, `includes/Abilities/ListSitesAbility.php`
- Modify: `includes/Plugin.php`, `bin/e2e.sh`
- Test: `tests/php/Abilities/RegistrarTest.php`, `tests/php/Abilities/NetworkSummaryAbilityTest.php`, `tests/php/Abilities/ListSitesAbilityTest.php` (créés) ; `bin/e2e.sh`

**Interfaces:**
- Consumes : `Query\Schemas` et `Plugin::scan_status_query()` (tâche 1), `SitesQuery::list()`, `AlertsQuery::summary( int $network_id )`, `InventoryQuery::summary()`, `Settings::get( 'integrations.mcp_public', false )`, `Capabilities::VIEW`, `SitesRepository::ORDERBY`, `SitesQuery::STATUSES`, `SitesQuery::MAX_PAGE`, `Severity::names()`.
- Produces :
  - `Abilities\Ability` (abstraite) :
    - à implémenter : `slug(): string`, `label(): string`, `description(): string`, `input_schema(): array`, `output_schema(): array`, `protected run( array $input ): array|WP_Error` ;
    - publiques : `execute( $input = null ): array|WP_Error`, `can_run(): bool` ;
    - aides protégées et statiques : `input( array $properties, string[] $required = [] ): array`, `paging(): array`, `page_number( array $input ): int`, `page_size( array $input ): int`, `page( array $result, int $page, int $per_page ): array`, `strings( $value ): string[]` ;
  - `Abilities\Registrar` :
    - `CATEGORY = 'multisite-radar'` ;
    - `__construct( Settings $settings, Ability[] $abilities )` ;
    - `static available(): bool`, `register_category(): void`, `register_abilities(): void` ;
    - `definitions(): array<string, array>` (nom complet → arguments de `wp_register_ability()`) ;
  - `Plugin::abilities(): Registrar`, `Plugin::register_ability_category(): void`, `Plugin::register_abilities(): void`, branchés sur `wp_abilities_api_categories_init` et `wp_abilities_api_init` ;
  - abilities `multisite-radar/network-summary` (sortie `{ scan, alerts, inventory }`) et `multisite-radar/list-sites` (sortie `Schemas::page_of( Schemas::site() )`).

Les services sont construits **pendant** `wp_abilities_api_init` (après `init`), jamais au démarrage du plugin : les libellés des règles sont traduits et le filtre `msradar_alert_rules` a vu toutes les règles tierces.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Abilities/RegistrarTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Abilities\Registrar;
use MultisiteRadar\Tests\RestTestCase;
use WP_REST_Request;

final class RegistrarTest extends RestTestCase {

	private const NAMES = [
		'multisite-radar/network-summary',
		'multisite-radar/list-sites',
	];

	public function test_the_abilities_are_registered_read_only_in_their_category(): void {
		$this->assertNotNull( wp_get_ability_category( Registrar::CATEGORY ) );
		foreach ( self::NAMES as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertSame( Registrar::CATEGORY, $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $name );
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertTrue( $annotations['readonly'], $name );
			$this->assertFalse( $annotations['destructive'], $name );
			$this->assertTrue( $annotations['idempotent'], $name );
		}
	}

	public function test_mcp_exposure_follows_the_setting_and_is_off_by_default(): void {
		$registrar = $this->plugin()->abilities();
		$this->assertSame( self::NAMES, array_keys( $registrar->definitions() ) );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertFalse( $args['meta']['mcp']['public'], $name );
			$this->assertSame( 'tool', $args['meta']['mcp']['type'], $name );
			$this->assertArrayNotHasKey( 'public', $args['meta'], 'meta.public would open every other channel too.' );
		}

		$this->plugin()->settings()->update( [ 'integrations' => [ 'mcp_public' => true ] ] );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertTrue( $args['meta']['mcp']['public'], $name );
		}

		$this->plugin()->settings()->update( [ 'integrations' => [ 'mcp_public' => false ] ] );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertFalse( $args['meta']['mcp']['public'], $name );
		}
	}

	public function test_the_abilities_are_offered_on_the_main_site_only(): void {
		$this->assertTrue( Registrar::available() );
		switch_to_blog( self::factory()->blog->create() );
		try {
			$this->assertFalse( Registrar::available() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_an_ability_runs_over_rest_with_get_and_the_view_capability_only(): void {
		$route = '/wp-abilities/v1/abilities/multisite-radar/network-summary/run';
		$this->assertSame( 401, $this->server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );

		$this->login_as_super_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'scan', 'alerts', 'inventory' ], array_keys( $response->get_data() ) );
		$this->assertSame( 405, $this->server->dispatch( new WP_REST_Request( 'POST', $route ) )->get_status(), 'A read-only ability only runs with GET.' );
	}
}
```

Créer `tests/php/Abilities/NetworkSummaryAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class NetworkSummaryAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function summary() {
		$ability = wp_get_ability( 'multisite-radar/network-summary' );
		$this->assertNotNull( $ability );
		return $ability->execute();
	}

	public function test_sums_up_the_analysis_the_alerts_and_the_inventory(): void {
		$before = $this->summary();
		$this->assertIsArray( $before );
		$this->make_record(
			3901,
			[
				'name'         => 'Broken',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$this->plugin()->reset_caches();

		$after = $this->summary();

		$this->assertIsArray( $after );
		$this->assertSame( [ 'scan', 'alerts', 'inventory' ], array_keys( $after ) );
		$this->assertSame( $before['scan']['total'] + 1, $after['scan']['total'] );
		$this->assertSame( $before['alerts']['by_severity']['error'] + 1, $after['alerts']['by_severity']['error'] );
		$this->assertArrayHasKey( 'unused', $after['inventory']['plugins'] );
	}

	public function test_a_failed_read_is_an_error_not_an_empty_summary(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$result = $this->summary();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_storage_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
```

Créer `tests/php/Abilities/ListSitesAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class ListSitesAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->make_record(
			3801,
			[
				'name'         => 'Alpha',
				'url'          => 'example.org/radar-alpha/',
				'scanned_at'   => '2026-09-01 00:00:00',
				'disk_bytes'   => 2048,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		// Jamais analysé : mesures et dates nulles.
		$this->make_record(
			3802,
			[
				'name' => 'Beta',
				'url'  => 'example.org/radar-beta/',
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function list_sites( $input ) {
		$ability = wp_get_ability( 'multisite-radar/list-sites' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_sites_with_their_measures_even_before_their_first_analysis(): void {
		$result = $this->list_sites(
			[
				'search'  => 'radar-',
				'orderby' => 'name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'Alpha', 'Beta' ], array_column( $result['items'], 'name' ) );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 20, $result['per_page'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertSame( 2048, $result['items'][0]['disk_bytes'] );
		$this->assertTrue( $result['items'][1]['pending'] );
		$this->assertNull( $result['items'][1]['disk_bytes'] );
		$this->assertNull( $result['items'][1]['scanned_at_gmt'] );
	}

	public function test_filters_by_alert_level_and_paginates(): void {
		$errors = $this->list_sites(
			[
				'search'      => 'radar-',
				'alert_level' => [ 'error' ],
			]
		);
		$this->assertSame( [ 'Alpha' ], array_column( $errors['items'], 'name' ) );

		$second = $this->list_sites(
			[
				'search'   => 'radar-',
				'orderby'  => 'name',
				'per_page' => 1,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'Beta' ], array_column( $second['items'], 'name' ) );
		$this->assertSame( 2, $second['total_pages'] );
	}

	public function test_numbers_sent_as_text_are_accepted(): void {
		$result = $this->list_sites(
			[
				'search'   => 'radar-',
				'per_page' => '1',
				'page'     => '1',
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 1, $result['per_page'] );
	}

	public function test_unknown_or_out_of_range_input_is_refused(): void {
		$inputs = [
			[ 'per_page' => 500 ],
			[ 'colour' => 'red' ],
			[ 'alert_level' => [ 'fatal' ] ],
			[ 'orderby' => 'password' ],
		];
		foreach ( $inputs as $input ) {
			$result = $this->list_sites( $input );
			$this->assertWPError( $result, (string) wp_json_encode( $input ) );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}
	}

	public function test_a_user_without_the_view_capability_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->list_sites( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
```

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
SUMMARY="$(wpe --user=admin eval '$response = rest_do_request( new WP_REST_Request( "GET", "/wp-abilities/v1/abilities/multisite-radar/network-summary/run" ) ); echo wp_json_encode( [ "status" => $response->get_status(), "data" => $response->get_data() ] );')"
expect "the network-summary ability runs over REST" "$(jq -r '"\(.status) \(.data.scan.total)"' <<<"$SUMMARY")" "200 3"
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'RegistrarTest|NetworkSummaryAbilityTest|ListSitesAbilityTest'`
Expected: FAIL. `Registrar` n'existe pas, et `wp_get_ability( 'multisite-radar/…' )` renvoie `null`.

- [ ] **Step 3: Create the base class**

`includes/Abilities/Ability.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Une ability en lecture seule de Multisite Radar (spec §5.4) : nom, textes, schémas et exécution. Registrar y ajoute
 * la catégorie, la permission et les métadonnées communes.
 */
abstract class Ability {

	/**
	 * Nom sans l'espace de noms, ex. « list-sites ».
	 */
	abstract public function slug(): string;

	abstract public function label(): string;

	/**
	 * Ce que fait l'ability et quand s'en servir : c'est ce qu'un assistant IA lit pour choisir ses outils.
	 */
	abstract public function description(): string;

	abstract public function input_schema(): array;

	abstract public function output_schema(): array;

	/**
	 * @param array $input Entrée validée par le cœur. En GET, WordPress 6.9 laisse les nombres en chaînes : convertir.
	 * @return array|WP_Error
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	abstract protected function run( array $input );

	/**
	 * execute_callback. Une lecture qui échoue devient une erreur 500, jamais une liste vide.
	 *
	 * @param mixed $input Entrée de l'ability : tableau, objet vide par défaut, ou null.
	 * @return array|WP_Error
	 */
	public function execute( $input = null ) {
		try {
			return $this->run( (array) $input );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', static::class, $error );
			return new WP_Error( 'msradar_storage_error', __( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * permission_callback : la capacité des pages et des routes REST de lecture.
	 */
	public function can_run(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	/**
	 * Schéma d'entrée : un objet, sans clé inconnue. Sans propriété obligatoire, l'entrée peut être omise (objet vide
	 * par défaut). Un objet sans propriété n'a pas de clé « properties » : vide, elle serait encodée [] en JSON.
	 *
	 * @param array    $properties Propriétés, chacune avec sa description.
	 * @param string[] $required   Propriétés obligatoires.
	 */
	protected static function input( array $properties, array $required = [] ): array {
		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
		];
		if ( [] !== $properties ) {
			$schema['properties'] = $properties;
		}
		if ( [] === $required ) {
			$schema['default'] = (object) [];
		} else {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Page et taille de page, comme les routes REST (au plus 100 par page).
	 */
	protected static function paging(): array {
		return [
			'page'     => [
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page of results, from 1.', 'multisite-radar' ),
			],
			'per_page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
				'description' => __( 'Results per page, 100 at most.', 'multisite-radar' ),
			],
		];
	}

	protected static function page_number( array $input ): int {
		return min( SitesQuery::MAX_PAGE, max( 1, (int) ( $input['page'] ?? 1 ) ) );
	}

	protected static function page_size( array $input ): int {
		return min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	}

	/**
	 * Enveloppe d'une page (écart E3) : une ability n'a pas d'en-têtes de pagination.
	 *
	 * @param array{items: array[], total: int} $result Résultat d'un service Query.
	 */
	protected static function page( array $result, int $page, int $per_page ): array {
		return [
			'items'       => $result['items'],
			'total'       => (int) $result['total'],
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
		];
	}

	/**
	 * @param mixed $value Liste de l'entrée.
	 * @return string[]
	 */
	protected static function strings( $value ): array {
		return array_values( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) );
	}
}
```

- [ ] **Step 4: Create the registrar**

`includes/Abilities/Registrar.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre la catégorie et les abilities de Multisite Radar (spec §5.4), sur le site principal seulement et si
 * l'Abilities API est là. Toutes sont en lecture seule et visibles en REST ; l'exposition MCP suit le réglage
 * integrations.mcp_public, désactivé par défaut (écart E2).
 */
final class Registrar {

	public const CATEGORY = 'multisite-radar';

	private Settings $settings;

	/**
	 * @var Ability[]
	 */
	private array $abilities;

	/**
	 * @param Ability[] $abilities Abilities, dans l'ordre d'enregistrement.
	 */
	public function __construct( Settings $settings, array $abilities ) {
		$this->settings  = $settings;
		$this->abilities = $abilities;
	}

	public static function available(): bool {
		return function_exists( 'wp_register_ability' ) && is_main_site();
	}

	/**
	 * Sur wp_abilities_api_categories_init.
	 */
	public function register_category(): void {
		if ( ! self::available() ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Multisite Radar', 'multisite-radar' ),
				'description' => __( 'Read-only audit of the sites, plugins, themes and alerts of this multisite network.', 'multisite-radar' ),
			]
		);
	}

	/**
	 * Sur wp_abilities_api_init.
	 */
	public function register_abilities(): void {
		if ( ! self::available() ) {
			return;
		}
		foreach ( $this->definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * @return array<string, array> Nom complet => arguments de wp_register_ability().
	 */
	public function definitions(): array {
		$mcp         = (bool) $this->settings->get( 'integrations.mcp_public', false );
		$definitions = [];
		foreach ( $this->abilities as $ability ) {
			$definitions[ self::CATEGORY . '/' . $ability->slug() ] = [
				'label'               => $ability->label(),
				'description'         => $ability->description(),
				'category'            => self::CATEGORY,
				'input_schema'        => $ability->input_schema(),
				'output_schema'       => $ability->output_schema(),
				'execute_callback'    => [ $ability, 'execute' ],
				'permission_callback' => [ $ability, 'can_run' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
					'mcp'          => [
						'public' => $mcp,
						'type'   => 'tool',
					],
				],
			];
		}
		return $definitions;
	}
}
```

- [ ] **Step 5: Create the first two abilities**

`includes/Abilities/NetworkSummaryAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryQuery;
use MultisiteRadar\Query\ScanStatusQuery;
use MultisiteRadar\Query\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/network-summary : vue d'ensemble du réseau, sans paramètre.
 */
final class NetworkSummaryAbility extends Ability {

	private ScanStatusQuery $scan;
	private AlertsQuery $alerts;
	private InventoryQuery $inventory;

	public function __construct( ScanStatusQuery $scan, AlertsQuery $alerts, InventoryQuery $inventory ) {
		$this->scan      = $scan;
		$this->alerts    = $alerts;
		$this->inventory = $inventory;
	}

	public function slug(): string {
		return 'network-summary';
	}

	public function label(): string {
		return __( 'Network summary', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Overview of this WordPress multisite network as last analysed by Multisite Radar: number of sites and analysis progress, sites with alerts by severity and by rule, and counts of installed, unused, missing and outdated plugins and themes. Start here, then use the other Multisite Radar abilities for details.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input( [] );
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'scan'      => Schemas::scan_status(),
				'alerts'    => Schemas::alerts_summary(),
				'inventory' => Schemas::inventory_summary(),
			],
		];
	}

	protected function run( array $input ) {
		return [
			'scan'      => $this->scan->status(),
			'alerts'    => $this->alerts->summary( get_current_network_id() ),
			'inventory' => $this->inventory->summary(),
		];
	}
}
```

`includes/Abilities/ListSitesAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/list-sites : les sites du réseau tels que la dernière analyse les a vus, filtrés, triés, paginés.
 */
final class ListSitesAbility extends Ability {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function slug(): string {
		return 'list-sites';
	}

	public function label(): string {
		return __( 'List sites', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the sites of the network as last analysed, with their theme, user, content and media counts, disk, database and autoloaded options sizes in bytes, last activity and highest alert. Filter by text, alert severity, status, theme or plugin; sort and paginate. A null size or date means it was not measured yet; "pending" means the site waits for its first analysis.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'search'      => [
						'type'        => 'string',
						'description' => __( 'Text to find in the site name or address.', 'multisite-radar' ),
					],
					'alert_level' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => Severity::names(),
						],
						'description' => __( 'Only sites whose highest alert has one of these severities ("none": no alert).', 'multisite-radar' ),
					],
					'status'      => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => SitesQuery::STATUSES,
						],
						'description' => __( 'Only sites with one of these statuses.', 'multisite-radar' ),
					],
					'theme'       => [
						'type'        => 'string',
						'description' => __( 'Only sites using this theme folder, as active or parent theme.', 'multisite-radar' ),
					],
					'plugin'      => [
						'type'        => 'string',
						'description' => __( 'Only sites where this plugin file is active, e.g. akismet/akismet.php. A network-activated plugin matches every site.', 'multisite-radar' ),
					],
					'orderby'     => [
						'type'        => 'string',
						'enum'        => array_keys( SitesRepository::ORDERBY ),
						'default'     => 'name',
						'description' => __( 'Sort key.', 'multisite-radar' ),
					],
					'order'       => [
						'type'        => 'string',
						'enum'        => [ 'asc', 'desc' ],
						'default'     => 'asc',
						'description' => __( 'Sort direction.', 'multisite-radar' ),
					],
				],
				self::paging()
			)
		);
	}

	public function output_schema(): array {
		return Schemas::page_of( Schemas::site() );
	}

	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->sites->list(
			[
				'page'        => $page,
				'per_page'    => $per_page,
				'search'      => (string) ( $input['search'] ?? '' ),
				'alert_level' => self::strings( $input['alert_level'] ?? [] ),
				'status'      => self::strings( $input['status'] ?? [] ),
				'theme'       => (string) ( $input['theme'] ?? '' ),
				'plugin'      => (string) ( $input['plugin'] ?? '' ),
				'orderby'     => (string) ( $input['orderby'] ?? 'name' ),
				'order'       => (string) ( $input['order'] ?? 'asc' ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
```

- [ ] **Step 6: Wire the abilities in `Plugin`**

Dans `includes/Plugin.php` :
- ajouter, en tête des `use` (ordre alphabétique) :

```php
use MultisiteRadar\Abilities\ListSitesAbility;
use MultisiteRadar\Abilities\NetworkSummaryAbility;
use MultisiteRadar\Abilities\Registrar;
```

- ajouter la propriété `private ?Registrar $abilities = null;` ;
- dans `boot()`, après `add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );` :

```php
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
```

- ajouter, après `register_rest_routes()` :

```php
	public function register_ability_category(): void {
		$this->abilities()->register_category();
	}

	public function register_abilities(): void {
		$this->abilities()->register_abilities();
	}
```

- ajouter le service, après `scan_status_query()` :

```php
	/**
	 * Construit pendant wp_abilities_api_init, après init : jamais au démarrage du plugin.
	 */
	public function abilities(): Registrar {
		return $this->abilities ??= new Registrar(
			$this->settings(),
			[
				new NetworkSummaryAbility( $this->scan_status_query(), $this->alerts_query(), $this->inventory_query() ),
				new ListSitesAbility( $this->sites_query() ),
			]
		);
	}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `bin/test.sh --filter 'RegistrarTest|NetworkSummaryAbilityTest|ListSitesAbilityTest'`
Expected: PASS. Si le cœur rejette une sortie (`ability_invalid_output`), corriger `Query\Schemas` (tâche 1) pour qu'il décrive les données réelles, jamais les données.

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 8: Commit**

```bash
git add includes/Abilities/ includes/Plugin.php tests/php/Abilities/ bin/e2e.sh
git commit -m "feat: register the network-summary and list-sites abilities"
```

### Task 8: Abilities `get-site`, `find-extension-usage` et `list-alerts`

**Files:**
- Create: `includes/Abilities/GetSiteAbility.php`, `includes/Abilities/FindExtensionUsageAbility.php`, `includes/Abilities/ListAlertsAbility.php`
- Modify: `includes/Plugin.php`, `tests/php/Abilities/RegistrarTest.php`, `bin/e2e.sh`
- Test: `tests/php/Abilities/GetSiteAbilityTest.php`, `tests/php/Abilities/FindExtensionUsageAbilityTest.php`, `tests/php/Abilities/ListAlertsAbilityTest.php` (créés) ; `bin/e2e.sh`

**Interfaces:**
- Consumes : `Abilities\Ability` et `Registrar` (tâche 7), `Query\Schemas` (tâche 1), `SitesQuery::get( int $site_id ): ?array`, `SitesQuery::list()`, `PluginsQuery::find( string $id ): ?array`, `PluginsQuery::id( string $file ): string`, `ThemesQuery::find( string $stylesheet ): ?array`, `AlertsQuery::list()`, `AlertsQuery::ORDERBY`, `RuleRegistry::all()`.
- Produces :
  - abilities `multisite-radar/get-site` (entrée `{ id }`, sortie `Schemas::site_detail()`) ;
  - `multisite-radar/find-extension-usage` (entrée `{ type, id, page, per_page }`, sortie `{ extension, sites }`) ;
  - `multisite-radar/list-alerts` (entrée `{ severity, rule, search, orderby, order, page, per_page }`, sortie `Schemas::page_of( Schemas::alert() )`) ;
  - erreurs `msradar_site_not_found` et `msradar_extension_not_found`, statut 404.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Abilities/RegistrarTest.php`, `NAMES` devient :

```php
	private const NAMES = [
		'multisite-radar/network-summary',
		'multisite-radar/list-sites',
		'multisite-radar/get-site',
		'multisite-radar/find-extension-usage',
		'multisite-radar/list-alerts',
	];
```

Créer `tests/php/Abilities/GetSiteAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class GetSiteAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function get_site( $input ) {
		$ability = wp_get_ability( 'multisite-radar/get-site' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_returns_the_sheet_of_a_site_even_before_its_first_analysis(): void {
		$this->make_record( 4001, [ 'name' => 'Never analysed' ] );

		$site = $this->get_site( [ 'id' => 4001 ] );

		$this->assertIsArray( $site );
		$this->assertSame( 'Never analysed', $site['name'] );
		$this->assertTrue( $site['pending'] );
		$this->assertNull( $site['db_bytes'] );
		$this->assertNull( $site['cron'] );
		$this->assertInstanceOf( \stdClass::class, $site['options'], 'Encoded {} like the REST route.' );
	}

	public function test_privileged_accounts_are_named_by_login_without_email_addresses(): void {
		$this->make_record(
			4002,
			[
				'name'       => 'Team',
				'url'        => 'example.org/team/',
				'scanned_at' => '2026-09-01 00:00:00',
				'data'       => [
					'users' => [
						'total'      => 1,
						'by_role'    => [ 'administrator' => 1 ],
						'privileged' => [
							[
								'id'    => 7,
								'login' => 'chief',
								'roles' => [ 'administrator' ],
							],
						],
					],
				],
			]
		);

		$site = $this->get_site( [ 'id' => '4002' ] );

		$this->assertIsArray( $site );
		$this->assertSame( 'chief', $site['users']['privileged'][0]['login'] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $site ) );
	}

	public function test_an_unknown_site_or_a_site_of_another_network_is_not_found(): void {
		$this->make_record(
			4003,
			[
				'name'       => 'Elsewhere',
				'network_id' => 2,
			]
		);

		foreach ( [ 999999, 4003 ] as $id ) {
			$result = $this->get_site( [ 'id' => $id ] );
			$this->assertWPError( $result );
			$this->assertSame( 'msradar_site_not_found', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	public function test_the_site_id_is_required(): void {
		$this->assertSame( 'ability_invalid_input', $this->get_site( [] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->get_site( null )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->get_site( [ 'id' => 0 ] )->get_error_code() );
	}

	public function test_a_user_without_the_view_capability_is_refused(): void {
		$this->make_record( 4004, [ 'name' => 'Private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 'ability_invalid_permissions', $this->get_site( [ 'id' => 4004 ] )->get_error_code() );
	}
}
```

Créer `tests/php/Abilities/FindExtensionUsageAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class FindExtensionUsageAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );

		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'gamma/gamma.php' => [
						'Name'    => 'Gamma',
						'Version' => '3.0',
					],
				],
			],
			'plugins'
		);
		update_site_option( 'active_sitewide_plugins', [ 'gamma/gamma.php' => time() ] );
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			4101,
			[
				'name'             => 'Usage one',
				'scanned_at'       => $scanned,
				'theme_stylesheet' => 'msradar-child',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			4102,
			[
				'name'             => 'Usage two',
				'scanned_at'       => $scanned,
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->plugin()->extensions()->replace_for_site( 4101, [ 'alpha/alpha.php' ], '', '' );
		$this->plugin()->extensions()->replace_for_site( 4102, [ 'alpha/alpha.php' ], '', '' );
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function find( $input ) {
		$ability = wp_get_ability( 'multisite-radar/find-extension-usage' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_finds_the_sites_of_a_plugin_by_its_file_with_or_without_php(): void {
		foreach ( [ 'alpha/alpha.php', 'alpha/alpha' ] as $id ) {
			$result = $this->find(
				[
					'type' => 'plugin',
					'id'   => $id,
				]
			);

			$this->assertIsArray( $result, $id );
			$this->assertSame( 'alpha/alpha.php', $result['extension']['file'] );
			$this->assertSame( 'local', $result['extension']['status'] );
			$this->assertSame( [ 4101, 4102 ], array_column( $result['sites']['items'], 'id' ), 'Sorted by name.' );
			$this->assertSame( 2, $result['sites']['total'] );
		}
	}

	public function test_a_network_activated_plugin_is_used_by_every_site(): void {
		$result = $this->find(
			[
				'type'     => 'plugin',
				'id'       => 'gamma/gamma.php',
				'per_page' => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'network', $result['extension']['status'] );
		$this->assertSame( $this->plugin()->sites()->count_all( get_current_network_id() ), $result['sites']['total'] );
		$this->assertCount( 1, $result['sites']['items'] );
	}

	public function test_finds_the_sites_of_a_theme_used_as_active_or_parent_theme(): void {
		$result = $this->find(
			[
				'type' => 'theme',
				'id'   => 'msradar-parent',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'msradar-parent', $result['extension']['stylesheet'] );
		$this->assertSame( [ 4101, 4102 ], array_column( $result['sites']['items'], 'id' ) );
	}

	public function test_an_unknown_extension_is_not_found(): void {
		$result = $this->find(
			[
				'type' => 'plugin',
				'id'   => 'nope/nope.php',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_extension_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_the_kind_and_the_identifier_are_required(): void {
		$inputs = [
			[ 'type' => 'plugin' ],
			[ 'id' => 'alpha/alpha.php' ],
			[
				'type' => 'widget',
				'id'   => 'alpha/alpha.php',
			],
			[
				'type' => 'plugin',
				'id'   => '',
			],
		];
		foreach ( $inputs as $input ) {
			$this->assertSame( 'ability_invalid_input', $this->find( $input )->get_error_code(), (string) wp_json_encode( $input ) );
		}
	}
}
```

Créer `tests/php/Abilities/ListAlertsAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class ListAlertsAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			4201,
			[
				'name'         => 'Radar alert one',
				'scanned_at'   => $scanned,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$this->make_record(
			4202,
			[
				'name'         => 'Radar alert two',
				'scanned_at'   => $scanned,
				'alert_level'  => 2,
				'alerts_count' => 1,
				'alert_rules'  => ',inactive,',
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function list_alerts( $input ) {
		$ability = wp_get_ability( 'multisite-radar/list-alerts' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_one_entry_per_site_and_rule(): void {
		$result = $this->list_alerts(
			[
				'search'  => 'Radar alert',
				'orderby' => 'name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'no_users', 'inactive' ], array_column( $result['items'], 'rule' ) );
		$this->assertSame( 4201, $result['items'][0]['site']['id'] );
		$this->assertSame( 'error', $result['items'][0]['severity'] );
		$this->assertNotSame( '', $result['items'][0]['message'] );
	}

	public function test_filters_by_severity_and_by_rule(): void {
		$errors = $this->list_alerts(
			[
				'search'   => 'Radar alert',
				'severity' => [ 'error' ],
			]
		);
		$this->assertSame( [ 4201 ], array_column( array_column( $errors['items'], 'site' ), 'id' ) );

		$inactive = $this->list_alerts(
			[
				'search' => 'Radar alert',
				'rule'   => [ 'inactive' ],
			]
		);
		$this->assertSame( [ 4202 ], array_column( array_column( $inactive['items'], 'site' ), 'id' ) );
	}

	public function test_a_disabled_rule_is_left_out(): void {
		$this->plugin()->settings()->update( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ] );

		$result = $this->list_alerts( [ 'search' => 'Radar alert' ] );

		$this->assertSame( [ 'no_users' ], array_column( $result['items'], 'rule' ) );
	}

	public function test_an_unknown_rule_or_severity_is_refused(): void {
		$this->assertSame( 'ability_invalid_input', $this->list_alerts( [ 'rule' => [ 'acme_missing' ] ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->list_alerts( [ 'severity' => [ 'none' ] ] )->get_error_code() );
	}
}
```

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
SITE="$(MSRADAR_E2E_SITE="$HIDDEN_ID" wpe --user=admin eval '$request = new WP_REST_Request( "GET", "/wp-abilities/v1/abilities/multisite-radar/get-site/run" ); $request->set_query_params( [ "input" => [ "id" => getenv( "MSRADAR_E2E_SITE" ) ] ] ); $response = rest_do_request( $request ); echo wp_json_encode( [ "status" => $response->get_status(), "data" => $response->get_data() ] );')"
expect "the get-site ability reads a site whose ID arrives as text" "$(jq -r '"\(.status) \(.data.name)"' <<<"$SITE")" "200 Discret"
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'Abilities'`
Expected: FAIL, `wp_get_ability( 'multisite-radar/get-site' )` (et les deux autres) renvoie `null` ; `RegistrarTest` attend cinq noms.

- [ ] **Step 3: Create the three abilities**

`includes/Abilities/GetSiteAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/get-site : la fiche complète d'un site du réseau courant.
 */
final class GetSiteAbility extends Ability {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function slug(): string {
		return 'get-site';
	}

	public function label(): string {
		return __( 'Get a site', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Full audit sheet of one site of the network, by its ID: measures, content types and taxonomies with the plugin or theme that registers them, users by role and privileged accounts (logins only), last content, overdue scheduled tasks, alerts with their message, active plugins and theme, and the last analysis error if any.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			[
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Site ID, as returned by multisite-radar/list-sites.', 'multisite-radar' ),
				],
			],
			[ 'id' ]
		);
	}

	public function output_schema(): array {
		return Schemas::site_detail();
	}

	protected function run( array $input ) {
		$site = $this->sites->get( (int) ( $input['id'] ?? 0 ) );
		if ( null === $site ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		// Des tableaux associatifs vides seraient encodés [] : le client attend des objets, comme avec la route REST.
		$site['options']          = (object) $site['options'];
		$site['users']['by_role'] = (object) ( $site['users']['by_role'] ?? [] );
		return $site;
	}
}
```

`includes/Abilities/FindExtensionUsageAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/find-extension-usage : un plugin ou un thème, et une page des sites qui l'utilisent (écart E4).
 */
final class FindExtensionUsageAbility extends Ability {

	private PluginsQuery $plugins;
	private ThemesQuery $themes;
	private SitesQuery $sites;

	public function __construct( PluginsQuery $plugins, ThemesQuery $themes, SitesQuery $sites ) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
		$this->sites   = $sites;
	}

	public function slug(): string {
		return 'find-extension-usage';
	}

	public function label(): string {
		return __( 'Find where a plugin or theme is used', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Finds the sites of the network that use a plugin (active on the site, or network-activated) or a theme (as active or parent theme), with the plugin or theme details: version, status and available update. Identify a plugin by its file, e.g. akismet/akismet.php, and a theme by its folder, e.g. twentytwentyfive.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'type' => [
						'type'        => 'string',
						'enum'        => [ 'plugin', 'theme' ],
						'description' => __( 'Kind of extension.', 'multisite-radar' ),
					],
					'id'   => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Plugin file, with or without ".php", or theme folder.', 'multisite-radar' ),
					],
				],
				self::paging()
			),
			[ 'type', 'id' ]
		);
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'extension' => [ 'anyOf' => [ Schemas::plugin(), Schemas::theme() ] ],
				'sites'     => Schemas::page_of( Schemas::site() ),
			],
		];
	}

	protected function run( array $input ) {
		$id = (string) ( $input['id'] ?? '' );
		if ( 'theme' === ( $input['type'] ?? '' ) ) {
			$extension = $this->themes->find( $id );
			$filter    = [ 'theme' => $id ];
		} else {
			$extension = $this->plugins->find( PluginsQuery::id( $id ) );
			$filter    = [ 'plugin' => null !== $extension ? $extension['file'] : '' ];
		}
		if ( null === $extension ) {
			return new WP_Error( 'msradar_extension_not_found', __( 'This plugin or theme is neither installed nor used on any site of the network.', 'multisite-radar' ), [ 'status' => 404 ] );
		}

		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->sites->list(
			array_merge(
				$filter,
				[
					'page'     => $page,
					'per_page' => $per_page,
					'orderby'  => 'name',
					'order'    => 'asc',
				]
			)
		);
		return [
			'extension' => $extension,
			'sites'     => self::page( $result, $page, $per_page ),
		];
	}
}
```

`includes/Abilities/ListAlertsAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/list-alerts : les alertes du réseau, une entrée par site et par règle.
 */
final class ListAlertsAbility extends Ability {

	private AlertsQuery $alerts;
	private RuleRegistry $rules;

	public function __construct( AlertsQuery $alerts, RuleRegistry $rules ) {
		$this->alerts = $alerts;
		$this->rules  = $rules;
	}

	public function slug(): string {
		return 'list-alerts';
	}

	public function label(): string {
		return __( 'List alerts', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the health alerts of the network, one entry per site and rule, with the severity of the current settings and a readable message: sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines, overdue scheduled tasks, and rules added by other plugins. Rules switched off in the settings are left out.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'severity' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ Severity::ERROR, Severity::WARNING, Severity::INFO ],
						],
						'description' => __( 'Only alerts of these severities.', 'multisite-radar' ),
					],
					'rule'     => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => array_keys( $this->rules->all() ),
						],
						'description' => __( 'Only alerts of these rules.', 'multisite-radar' ),
					],
					'search'   => [
						'type'        => 'string',
						'description' => __( 'Text to find in the site name or address.', 'multisite-radar' ),
					],
					'orderby'  => [
						'type'        => 'string',
						'enum'        => AlertsQuery::ORDERBY,
						'default'     => 'rule',
						'description' => __( 'Sort key.', 'multisite-radar' ),
					],
					'order'    => [
						'type'        => 'string',
						'enum'        => [ 'asc', 'desc' ],
						'default'     => 'asc',
						'description' => __( 'Sort direction.', 'multisite-radar' ),
					],
				],
				self::paging()
			)
		);
	}

	public function output_schema(): array {
		return Schemas::page_of( Schemas::alert() );
	}

	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->alerts->list(
			[
				'page'     => $page,
				'per_page' => $per_page,
				'search'   => (string) ( $input['search'] ?? '' ),
				'severity' => self::strings( $input['severity'] ?? [] ),
				'rule'     => self::strings( $input['rule'] ?? [] ),
				'orderby'  => (string) ( $input['orderby'] ?? 'rule' ),
				'order'    => (string) ( $input['order'] ?? 'asc' ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
```

- [ ] **Step 4: Register them**

Dans `includes/Plugin.php` :
- ajouter les `use` (ordre alphabétique, avec ceux de la tâche 7) :

```php
use MultisiteRadar\Abilities\FindExtensionUsageAbility;
use MultisiteRadar\Abilities\GetSiteAbility;
use MultisiteRadar\Abilities\ListAlertsAbility;
```

- dans `abilities()`, la liste devient :

```php
			[
				new NetworkSummaryAbility( $this->scan_status_query(), $this->alerts_query(), $this->inventory_query() ),
				new ListSitesAbility( $this->sites_query() ),
				new GetSiteAbility( $this->sites_query() ),
				new FindExtensionUsageAbility( $this->plugins_query(), $this->themes_query(), $this->sites_query() ),
				new ListAlertsAbility( $this->alerts_query(), $this->rules() ),
			]
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'Abilities'`
Expected: PASS.

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Abilities/ includes/Plugin.php tests/php/Abilities/ bin/e2e.sh
git commit -m "feat: register the get-site, find-extension-usage and list-alerts abilities"
```

### Task 9: Réglages — section « Intégrations » et interrupteur MCP

**Files:**
- Modify: `src/views/settings/fields.js`, `src/views/settings/index.jsx`
- Create: `tests/e2e/specs/integrations.spec.js`
- Test: `src/views/settings/test/settings-view.test.jsx`

**Interfaces:**
- Consumes : `GET/POST /settings` (`integrations.mcp_public`, booléen, déjà dans le schéma des réglages depuis M1), `changes()`, `allForm()`, `getSettingsFields()`.
- Produces :
  - champ DataForm `integrations.mcp_public` (interrupteur) ;
  - `INTEGRATIONS_FORM` exporté par `fields.js` ;
  - `changes()` envoie `{ integrations: { mcp_public } }` quand l'interrupteur change ;
  - carte « Integrations » après « Network sites menu ».

- [ ] **Step 1: Write the failing tests**

Dans `src/views/settings/test/settings-view.test.jsx`, ajouter :

```jsx
test( 'switching the MCP exposure is a change, and switching it back is not', () => {
	const on = mergeDeep( SETTINGS, { integrations: { mcp_public: true } } );

	expect( changes( SETTINGS, on, RULES ) ).toEqual( {
		integrations: { mcp_public: true },
	} );
	expect( changes( on, SETTINGS, RULES ) ).toEqual( {
		integrations: { mcp_public: false },
	} );
	expect( changes( SETTINGS, mergeDeep( on, SETTINGS ), RULES ) ).toEqual(
		{}
	);
} );

test( 'MCP exposure is off by default and can be switched on', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		integrations: { mcp_public: true },
	} );
	setup();
	const toggle = screen.getByRole( 'checkbox', {
		name: /Let AI assistants read the audit through MCP/,
	} );
	expect( toggle ).not.toBeChecked();

	fireEvent.click( toggle );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		integrations: { mcp_public: true },
	} );
} );
```

Créer `tests/e2e/specs/integrations.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Integrations settings', () => {
	test( 'the MCP exposure is saved and stays on after a reload', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const app = page.locator( '#msradar-app' );
		const toggle = () =>
			app.getByRole( 'checkbox', {
				name: /Let AI assistants read the audit through MCP/,
			} );
		const open = () =>
			admin.visitAdminPage(
				'network/admin.php',
				'page=multisite-radar-settings'
			);

		try {
			await open();
			await expect( toggle() ).not.toBeChecked();
			await toggle().check();
			await app.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect(
				page
					.locator( '.components-snackbar' )
					.getByText( 'Settings saved.' )
			).toBeVisible();

			await open();
			await expect( toggle() ).toBeChecked();
		} finally {
			// Remet la valeur par défaut, même si une assertion a échoué : le test reste rejouable.
			await requestUtils.rest( {
				method: 'POST',
				path: '/multisite-radar/v1/settings',
				data: { integrations: { mcp_public: false } },
			} );
		}
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/views/settings`
Expected: FAIL. `changes()` renvoie `{}` pour l'interrupteur, et la case « Let AI assistants read the audit through MCP » n'existe pas.

- [ ] **Step 3: Add the field, the form and the change**

Dans `src/views/settings/fields.js` :
- dans `getSettingsFields()`, ajouter après le champ `sites_menu.enabled` :

```js
		{
			id: 'integrations.mcp_public',
			type: 'boolean',
			label: __(
				'Let AI assistants read the audit through MCP',
				'multisite-radar'
			),
			description: __(
				'Offers the read-only abilities of Multisite Radar (network summary, sites, plugin and theme usage, alerts) to AI assistants connected with the MCP Adapter plugin. They act as the connected user, who needs the same rights as for these pages.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
```

- après `MENU_FORM`, ajouter :

```js
export const INTEGRATIONS_FORM = {
	layout: { type: 'regular' },
	fields: [ 'integrations.mcp_public' ],
};
```

- dans `allForm()`, ajouter `...INTEGRATIONS_FORM.fields,` après `...MENU_FORM.fields,` ;
- dans `changes()`, après le bloc de `sites_menu` :

```js
	if (
		!! saved.integrations?.mcp_public !==
		!! current.integrations?.mcp_public
	) {
		patch.integrations = {
			mcp_public: !! current.integrations?.mcp_public,
		};
	}
```

Dans `src/views/settings/index.jsx` :
- importer `INTEGRATIONS_FORM` depuis `./fields` (ordre alphabétique de l'import) ;
- après la carte « Network sites menu », ajouter :

```jsx
						<Card>
							<CardHeader>
								<h2>
									{ __( 'Integrations', 'multisite-radar' ) }
								</h2>
							</CardHeader>
							<CardBody>
								<DataForm
									data={ data }
									fields={ fields }
									form={ INTEGRATIONS_FORM }
									validity={ validity }
									onChange={ onChange }
								/>
							</CardBody>
						</Card>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/views/settings && npm run lint:js && npm run test:unit`
Expected: PASS.

Run (environnement E2E) : `npm run build && npm run test:e2e -- tests/e2e/specs/integrations.spec.js tests/e2e/specs/a11y.spec.js`
Expected: PASS. L'audit d'accessibilité couvre déjà la page Réglages, donc la nouvelle carte.

- [ ] **Step 5: Commit**

```bash
git add src/views/settings/ tests/e2e/specs/integrations.spec.js
git commit -m "feat: add the integrations settings with the MCP exposure switch"
```

## Partie D — Livraison

### Task 10: Version 2.0.0-beta.5, traductions, documentation et recette du jalon

**Files:**
- Modify: `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php` (par `make version`)
- Modify: `languages/multisite-radar.pot`, `languages/multisite-radar-fr_FR.po` (et les fichiers générés par `make i18n`)
- Modify: `CHANGELOG.md`, `readme.txt`, `README.md`
- Modify: `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`

**Interfaces:**
- Consumes : tout le jalon.
- Produces : la version 2.0.0-beta.5, cohérente partout (`npm run version:check`), traduite en français, avec une recette verte.

- [ ] **Step 1: Set the version**

Run: `make version VERSION=2.0.0-beta.5`
Expected : en-tête, `MSRADAR_VERSION`, `readme.txt` (`Stable tag`), `package.json`, `package-lock.json` et `tests/phpstan-bootstrap.php` passent en 2.0.0-beta.5 ; `npm run version:check` le confirme.

- [ ] **Step 2: Translate the new strings**

Run: `npm run build && make i18n`
Expected : la commande s'arrête et liste les chaînes non traduites du jalon.

Les traduire dans `languages/multisite-radar-fr_FR.po`, puis relancer `make i18n` jusqu'à zéro chaîne manquante. Règles :
- typographie de WordPress en français : apostrophe typographique `’`, espace insécable (U+00A0) avant `:`, `;`, `!` et `?`, guillemets « » avec espaces insécables à l'intérieur, points de suspension `…` en un seul caractère ;
- garder chaque marqueur (`%s`, `%d`, `%1$s`…) : `tests/php/TranslationsTest.php` le vérifie ;
- « plugin » se traduit « extension », comme dans WordPress en français et dans la traduction déjà livrée ;
- les noms techniques restent tels quels : `multisite-radar/list-sites`, `akismet/akismet.php`, `.php`, « none », MCP, MCP Adapter ;
- le glossaire ci-dessous.

| Anglais | Français |
|---|---|
| Integrations | Intégrations |
| Let AI assistants read the audit through MCP | Permettre aux assistants IA de lire l’audit par MCP |
| Offers the read-only abilities of Multisite Radar (…) to AI assistants connected with the MCP Adapter plugin. They act as the connected user, who needs the same rights as for these pages. | Propose les outils en lecture seule de Multisite Radar (…) aux assistants IA connectés par l’extension MCP Adapter. Ils agissent au nom de l’utilisateur connecté, qui a besoin des mêmes droits que pour ces pages. |
| Read-only audit of the sites, plugins, themes and alerts of this multisite network. | Audit en lecture seule des sites, extensions, thèmes et alertes de ce réseau multisite. |
| Network summary | Synthèse du réseau |
| List sites | Lister les sites |
| Get a site | Fiche d’un site |
| Find where a plugin or theme is used | Trouver où une extension ou un thème est utilisé |
| List alerts | Lister les alertes |
| Page of results, from 1. | Page de résultats, à partir de 1. |
| Results per page, 100 at most. | Résultats par page, 100 au plus. |
| Text to find in the site name or address. | Texte à chercher dans le nom ou l’adresse du site. |
| Sort key. / Sort direction. | Clé de tri. / Sens du tri. |
| Kind of extension. | Extension ou thème. |
| Site ID, as returned by multisite-radar/list-sites. | Identifiant du site, tel que le renvoie multisite-radar/list-sites. |
| This plugin or theme is neither installed nor used on any site of the network. | Cette extension ou ce thème n’est ni installé ni utilisé sur aucun site du réseau. |
| Only sites … / Only alerts … | Seulement les sites … / Seulement les alertes … |

Les descriptions longues des abilities se traduisent dans le même registre, phrase à phrase, sans rien retirer : un assistant IA francophone s'en sert pour choisir ses outils. Ensuite :

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 3: Update the documentation**

`CHANGELOG.md`, nouvelle section en tête (après le titre) :

```markdown
## [2.0.0-beta.5] - <date du jour, AAAA-MM-JJ>

### Jalon M5 — Intégrations
- WP-CLI : nouvelles commandes `wp multisite-radar alerts`, `plugins list`, `themes list`, `export` et `settings get|set`, avec les formats habituels de WP-CLI. `export` accepte les filtres de la route REST de la ressource et écrit dans un fichier ou sur la sortie standard ; `settings set` applique la validation de la page Réglages.
- Abilities API : cinq abilities en lecture seule, `multisite-radar/network-summary`, `list-sites`, `get-site`, `find-extension-usage` et `list-alerts`, sur le site principal, réservées aux comptes qui ont le droit `msradar_view`. Aucune adresse e-mail n'est renvoyée.
- Réglages : section « Intégrations ». L'interrupteur « Permettre aux assistants IA de lire l'audit par MCP », désactivé par défaut, les propose aux clients MCP de l'extension MCP Adapter.
- REST : chaque route de lecture publie le schéma de ses éléments.
```

`readme.txt` :
- dans `== Description ==`, avant la ligne `Source code: …`, ajouter :

```
Everything is also available from the command line: `wp multisite-radar` lists sites, alerts, plugins and themes, exports them as CSV or JSON, and reads or changes the settings. Five read-only abilities of the WordPress Abilities API describe the network, its sites, the use of each plugin and theme, and its alerts; a setting, off by default, offers them to AI assistants through the MCP Adapter plugin.
```

- dans `== Changelog ==`, avant `= 2.0.0-beta.4 =` :

```
= 2.0.0-beta.5 =
* WP-CLI: alerts, plugins and themes lists, exports and settings from the command line.
* Five read-only abilities for the WordPress Abilities API, optionally offered to AI assistants through MCP.
```

`README.md`, après le paragraphe « Règles d'alertes livrées : … » :

```markdown
Ligne de commande (`wp help multisite-radar` pour le détail) :

| Commande | Rôle |
|---|---|
| `wp multisite-radar scan --all\|--dirty\|--site=<id> [--probe]` | Analyse |
| `wp multisite-radar sites list` | Sites, filtrables par alerte, thème, plugin |
| `wp multisite-radar alerts [--severity] [--rule]` | Alertes, une ligne par site et par règle |
| `wp multisite-radar plugins list [--unused]`, `themes list [--unused]` | Inventaire |
| `wp multisite-radar export --resource=sites\|plugins\|themes [--format=csv\|json] [--output=<fichier>]` | Export, avec les filtres de la route REST |
| `wp multisite-radar settings get [<clé>]`, `settings set <clé> <valeur>` | Réglages (clé en chemin pointé, valeur JSON) |

Abilities (Abilities API de WordPress, lecture seule, droit `msradar_view`) : `multisite-radar/network-summary`, `list-sites`, `get-site`, `find-extension-usage`, `list-alerts`. Elles sont exposées en REST (`/wp-abilities/v1/abilities/<nom>/run`, en GET) et, si le réglage « Intégrations » l'autorise, aux clients MCP de l'extension MCP Adapter.
```

- [ ] **Step 4: Update the follow-up document**

Dans `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md` :
- dans « Déjà traité depuis M2 », ajouter : « schémas d'élément des routes `/plugins`, `/themes`, `/users`, `/alerts`, `/alerts/summary`, `/inventory/summary` et `/scan/status` (2.0.0-beta.5, plan M5) » ;
- retirer ce point de la section 3 ;
- ajouter en fin de document une section `## 5. Reportés par le plan M5`, avec les points relevés pendant l'exécution de ce plan et laissés pour plus tard (registre de l'exécution, revue finale). Y reporter au moins l'ability `multisite-radar/recent-changes`, prévue avec M6 (écart E1).

- [ ] **Step 5: Run the full check**

Run: `make check && npm run version:check && make dist && unzip -l dist/multisite-radar-2.0.0-beta.5.zip | grep -E 'includes/(Abilities/Registrar|Cli/SettingsCommand|Query/Schemas|Settings/SettingsUpdater)\.php|languages/.*\.json'`
Expected :
- lint et tests verts (hors les 11 échecs connus de la base de test, s'ils sont encore là) ;
- versions cohérentes ;
- le zip contient les quatre classes citées et les fichiers JSON de traduction.

Run : la commande `bin/e2e.sh` de la section « Commandes ».
Expected : `E2E OK`.

Run (environnement E2E) : `npm run test:e2e`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add multisite-radar.php readme.txt package.json package-lock.json tests/phpstan-bootstrap.php languages/ CHANGELOG.md README.md docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md
git commit -m "chore: release 2.0.0-beta.5"
```
