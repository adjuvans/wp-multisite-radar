# Multisite Radar — Jalon M2 (Interface) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la première bêta de Multisite Radar (2.0.0-beta.1). Elle comprend :
- l'interface d'administration réseau en React (Vue d'ensemble, Sites avec fiche latérale, Alertes, Réglages) ;
- les préférences d'affichage par utilisateur ;
- les exports CSV et JSON ;
- le module « menu des sites » (cache, shortcode, alias 1.x, metabox des menus, bloc) ;
- les corrections reportées de M1 qui touchent ces surfaces.

**Architecture :**
- **PHP :** de nouvelles routes REST (`/alerts`, `/sites/{id}/users`, `/preferences`), un gestionnaire d'export `admin-post`, un module `SitesMenu` et une couche `Admin` (menu réseau, scripts, préchargement des données).
- **JavaScript :**
  - une application React par vue (un point d'entrée webpack par vue) ;
  - un store `@wordpress/data` (`msradar/core`) qui met en cache les réponses REST, indexées par chemin canonique ;
  - le store est hydraté de façon synchrone avec les données préchargées par PHP : la première vue s'affiche sans indicateur de chargement ;
  - DataViews/DataForm sont bundlés, épinglés et accessibles par un seul adaptateur.
- **Correspondance PHP/JS :** l'état de la vue vit dans l'URL de la page d'admin. PHP (`Admin\ViewQuery`) et JS (`src/views/*/query.js`) le traduisent de la même façon en arguments REST, ce qu'un fichier de cas partagé (`tests/fixtures/view-queries.json`) vérifie des deux côtés.

**Tech stack :**
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6 (inchangés depuis M1).
- Node 24, `@wordpress/scripts` 36.0.0 (webpack, ESLint 10, Stylelint, Vitest 5).
- React 18.3 (celui de WordPress 6.9), `@wordpress/dataviews` 19.1.0 (version épinglée).
- Vitest 5 + jsdom 26 + Testing Library.
- Playwright 1.63 + `@wordpress/e2e-test-utils-playwright` 2.1 + `@axe-core/playwright` 4.13 sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M2, les sections utiles sont :
- §1.4 (critères 2 et 5) ;
- §2.2 ;
- §5.1 (routes M2) et §5.2 ;
- §6 ;
- §7.4 ;
- §8 ;
- §9 ;
- §11.

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-01-multisite-radar-m1-followups.md`. Les points de M1 repris ici sont listés plus bas.

## Global Constraints

- **PHP ≥ 7.4.** Interdits : `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains`, arguments nommés, opérateur nullsafe. Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :**
  - text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, transients, handles) ;
  - handles de scripts `msradar-<vue>` ;
  - store `msradar/core` ;
  - pages d'admin `multisite-radar`, `multisite-radar-sites`, `multisite-radar-alerts`, `multisite-radar-settings`.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ;
  - en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder.
- **SQL (inchangé depuis M1) :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants ;
  - listes `IN` via `implode( ',', array_fill( 0, count( $x ), '%d' ) )` ;
  - aucune fonction JSON côté SQL ;
  - `// phpcs:ignore <code exact> -- <raison>` sur une ligne signalée qui suit ces motifs ; ne jamais désactiver une règle dans `phpcs.xml.dist`.
- **Style PHP :**
  - WPCS ;
  - `defined( 'ABSPATH' ) || exit;` en tête de chaque fichier sous `includes/` ;
  - tableaux courts autorisés.
- **Erreurs :**
  - les dépôts (`Storage/*`) lèvent `\RuntimeException` quand une requête échoue ;
  - les gestionnaires de hooks et les contrôleurs REST l'attrapent ;
  - ils déclenchent `do_action( 'msradar_error', <contexte>, $error )` ;
  - aucune exception ne doit remonter dans un hook du cœur.
- **Dates :**
  - stockées en GMT (`Y-m-d H:i:s`) ;
  - exposées en REST dans les champs `*_gmt` au format `Y-m-d\TH:i:s`, en UTC et sans décalage (convention du cœur) ;
  - côté JS, toujours lues par `parseGmt()` (`src/utils/format.js`), qui ajoute le `Z`.
- **JS, fichiers :**
  - sources dans `src/` en modules ES ;
  - extension `.jsx` pour tout fichier qui contient du JSX, `.js` sinon ;
  - imports sans extension.
- **JS, styles :** les feuilles de style ne sont importées que par les points d'entrée (`src/admin/<vue>.js`, `src/blocks/*/index.js`), jamais par un composant, car Vitest ne les traite pas.
- **DataViews :**
  - `@wordpress/dataviews` est épinglé à `19.1.0` ;
  - ses composants sont importés uniquement dans `src/components/data-views/index.js`, depuis `@wordpress/dataviews/wp` ;
  - le reste du code importe cet adaptateur ;
  - les fonctions pures sur les filtres vivent dans `src/components/data-views/filters.js`, sans dépendance, pour tester la logique des vues sans charger DataViews ;
  - seule exception : sa feuille de style `@wordpress/dataviews/build-style/style.css`, importée par les points d'entrée qui affichent DataViews (`sites`, `alerts`, `settings`).
- **Composants :**
  - uniquement des exports publics de `@wordpress/components` présents dans WordPress 6.9 : `Button`, `Card`, `CardBody`, `CardHeader`, `CheckboxControl`, `DropdownMenu`, `ExternalLink`, `Flex`, `FlexItem`, `FlexBlock`, `FormTokenField`, `Notice`, `PanelBody`, `SelectControl`, `SnackbarList`, `Spinner`, `TabPanel`, `TextControl`, `ToggleControl` ;
  - interdits : `__experimental*`, `privateApis`, `Tabs`, `Badge`, `ProgressBar` (utiliser `<progress>`) ;
  - icônes de `@wordpress/icons`.
- **Interface :**
  - jamais de `dangerouslySetInnerHTML` ;
  - pas d'emojis ;
  - couleurs d'accent via `var(--wp-admin-theme-color)`.
- **Sécurité :** toute donnée injectée dans un script en ligne passe par `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP )`.
- **Versions :**
  - `MSRADAR_VERSION` est la seule source de vérité ;
  - `npm run version:check` (tâche 11) vérifie l'en-tête, `readme.txt`, `package.json` et `tests/phpstan-bootstrap.php`.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `ci:`, `docs:`, `chore:`) ;
  - **jamais de ligne `Co-authored-by`**, que le hook du dépôt refuse.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur `multisite-radar` : le dossier du plugin est un lien symbolique vers le dépôt ;
  - ne jamais afficher le mot de passe de la base : `bin/test.sh` le lit à la volée.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M2 | Raison |
|---|---|---|---|
| E1 | Tests JS « Jest + Testing Library » (§11.1) | **Vitest 5** + Testing Library | `@wordpress/scripts` 36 a basculé `test-unit-js` sur Vitest ; Jest n'y est plus qu'en maintenance. |
| E2 | « Découpage du code par vue (imports dynamiques) » (§6.4) | **Un point d'entrée webpack par vue** (`admin/overview`, `admin/sites`, `admin/alerts`, `admin/settings`), chargé seul par sa page | Même objectif (ne charger que la vue affichée), sans `Suspense` : un import dynamique afficherait un état de chargement avant la première vue (critère §1.4 n° 2). |
| E3 | « `rest_preload_api_request` + middleware de préchargement d'`apiFetch` » (§6.4) | PHP précharge avec `rest_preload_api_request` ; **le store est hydraté de façon synchrone** avec ces réponses (`window.msradarAdmin.preload`) | Le middleware ne répond qu'à travers une promesse, donc après un premier rendu « en chargement ». L'hydratation garantit une première vue sans indicateur. |
| E4 | Recherche avec debounce de 300 ms (§6.4) | Le debounce **intégré à DataViews (250 ms)** | Le champ de recherche de DataViews applique déjà `useDebouncedInput` ; en ajouter un second retarderait la saisie. |
| E5 | `blocks/sites-list/` à la racine (§2.1) | Sources du bloc dans **`src/blocks/sites-list/`**, compilées dans `build/blocks/sites-list/` ; rendu PHP par `render_callback` | C'est la convention de `@wordpress/scripts`, qui découvre `block.json`, compile le script éditeur et copie le fichier. |
| E6 | Tables `events`/`snapshots` en « version de schéma 2 » (§3.1) | M2 prend la **version 2** (colonne `siteurl`) ; M6 passera en **version 3** | Les listes ne lisent plus la colonne `data` (point M6 de M1) et le lien d'administration a besoin de `siteurl`. |
| E7 | Filtre `inactive_since` | **Exclut les sites sans aucune date d'activité**, comme la règle `inactive` | Point M2 de M1 : le compteur de l'alerte et le filtre doivent dire la même chose. |
| E8 | Invalidation du cache du menu « à la création/suppression d'un site et sur `update_option_blogname` » (§7.4) | Ajout de `wp_update_site` (archivage, spam, visibilité, chemin) et de `update_option_home` ; transient **par réseau** (`msradar_sites_list_<id>`) | Un site archivé doit disparaître du menu tout de suite. Avec un cache objet persistant, une clé de transient réseau est partagée entre réseaux. |
| E9 | Shortcode `wrapper="ul"` | `wrapper` limité à **`ul` ou `ol`** | `tag_escape()` laisserait passer `script` ou `iframe`, alors qu'un contributeur peut écrire des shortcodes. |
| E10 | Tuiles « plugins inutilisés » et « mises à jour en attente » ; colonne « disque » (§6.2) | Tuiles reportées à **M3** ; colonnes « disque » et « base de données » disponibles mais **masquées par défaut** | Ces tuiles n'ont de données qu'avec l'inventaire croisé (M3) ; les tailles restent vides jusqu'aux mesures de M4. |
| E11 | Réglages : sections du §8 | M2 couvre **`scan`** et **`sites_menu`** | `reports`, `integrations` et `retention` arrivent avec M5/M6 ; les règles d'alertes avec M4 (`/alert-rules`). |
| E12 | `GET /sites/{id}/users` | **Aucune adresse e-mail** : identifiant, login, nom affiché, rôles, super-admin, date d'inscription | Même principe que §5.4 (données personnelles minimales). |
| E13 | « première bêta » (§12) | Version **2.0.0-beta.1** en fin de jalon | Suite logique de 2.0.0-alpha.1. |

## Points reportés de M1 traités dans ce plan

| Point (document des suites) | Tâche |
|---|---|
| Résidu (a) : recalcul et passe de file dans la même requête cron | 1 |
| Résidu (b) : réglages modifiés pendant un recalcul | 1 |
| Désactivation : `msradar_legacy_menu_batch` reste planifié | 1 |
| M3 : changement de `scan.activity_post_types` | 1 |
| M8 : `__()` dans `cron_schedules` avant `init` | 1 |
| M1 : exceptions dans les gestionnaires de hooks | 2 |
| M9 : `_n()` dans `HighMediaRule` | 2 |
| M11 : titre de `CHANGELOG.md` | 2 |
| M12 : identifiants de règles tierces | 2 |
| M13 : `RegistryProbe::run()` sans garde de schéma | 2 |
| M4 : nom de repli « Site #ID » stocké en base | 3 |
| M5 : `SitesQuery::ALERT_LEVELS` | 3 |
| M6 : listes qui lisent `data` | 3 |
| T10 : `msradar_upgraded` ne déclenche rien | 3 |
| M2 : « inactif », règle contre filtre | 4 |
| T11 : `page` énorme | 4 |
| T11 : erreurs SQL avalées | 4 |
| T11 : `{}` sérialisé en `[]` | 4 |
| T12 : `scope=ids` hors réseau, liste vide | 4 |
| T12 : `rest_parse_date` sans décalage | 4 |
| M10 : `wp_add_privacy_policy_content` | 12 |

Les autres points du document des suites restent pour plus tard ; la tâche 21 met le document à jour.

## Review Focus

Les cinq situations que la spec implique sans que ses exemples les montrent, et qui gêneraient le plus un utilisateur. Chacune a son test dans la tâche indiquée.

1. **Nom de site commençant par `=`, `+`, `-`, `@` dans un export CSV ouvert dans un tableur :**
   - le comportement attendu : la valeur est neutralisée par une apostrophe initiale ;
   - pourquoi c'est un risque : un administrateur de site choisit le nom, et le super-admin ouvre le fichier ;
   - test : tâche 8.
2. **Site présent dans `wp_blogs` dont les tables manquent (réseau abîmé) :**
   - le comportement attendu : le menu des sites ignore ce site sans casser la liste des autres ;
   - test : tâche 9.
3. **Lien profond vers un site inexistant ou d'un autre réseau (`&site=999999`) :**
   - le comportement attendu : la liste s'affiche, le panneau montre « Site not found » avec « Retry », et rien ne casse au préchargement ;
   - tests : tâches 12 et 15.
4. **Recherche contenant des apostrophes, `+`, `%`, des accents ou une espace insécable, conservée dans l'URL :**
   - le comportement attendu : la liste préchargée correspond exactement à la requête du navigateur (aucun nouvel appel, aucun scintillement) ;
   - tests : cas partagés des tâches 12 et 14, encodage aux tâches 11 et 12.
5. **« Analyser » cliqué pendant que le cron détient le verrou :**
   - le comportement attendu : l'interface attend et reprend ;
   - après une limite d'attente, elle s'arrête en annonçant que l'analyse continue en arrière-plan ;
   - elle ne tourne jamais à l'infini ;
   - test : tâche 13 (`useScan`), avec le bouton en tâche 16.

Autres pièges couverts par des tests :
- un nom de site contenant `</script>` dans les données préchargées (tâche 12) ;
- un utilisateur `msradar_view` sans `msradar_manage` : pas de bouton d'analyse ni de page Réglages, et les routes de gestion refusées (tâches 12, 14 et 16) ;
- des préférences enregistrées corrompues (tâche 7) ;
- un élément de menu 1.x pointant vers un site supprimé (tâche 10).

## Structure des fichiers

**PHP, créés :**
- `includes/Admin/Menu.php` — menu réseau, sous-pages, conteneur de l'application.
- `includes/Admin/Assets.php` — chargement du point d'entrée de la vue, configuration en ligne.
- `includes/Admin/ViewQuery.php` — traduction URL de la page → arguments REST (miroir de `src/views/*/query.js`).
- `includes/Admin/Preload.php` — chemins à précharger par vue, appel de `rest_preload_api_request`.
- `includes/Admin/Privacy.php` — texte proposé pour la politique de confidentialité.
- `includes/Settings/Preferences.php` — préférences d'affichage (`msradar_view_prefs`).
- `includes/Rest/PreferencesController.php` — `GET/POST /preferences`.
- `includes/Query/SiteUsersQuery.php` — utilisateurs d'un site.
- `includes/Export/ExportHandler.php`, `includes/Export/CsvWriter.php`, `includes/Export/JsonWriter.php`, `includes/Export/SitesColumns.php` — exports.
- `includes/SitesMenu/Module.php`, `includes/SitesMenu/SitesListCache.php`, `includes/SitesMenu/Renderer.php`, `includes/SitesMenu/Shortcode.php`, `includes/SitesMenu/NavMenu.php`, `includes/SitesMenu/Block.php`, `includes/SitesMenu/legacy-functions.php` — module menu des sites.

**PHP, modifiés :**
- `includes/Plugin.php` ;
- `includes/Scan/Queue.php`, `includes/Scan/Invalidation.php`, `includes/Scan/BatchRunner.php` ;
- `includes/Install/LegacyMigration.php`, `includes/Install/Schema.php` ;
- `includes/Collector/RegistryProbe.php`, `includes/Collector/SiteCollector.php` ;
- `includes/Alerts/RuleRegistry.php`, `includes/Alerts/Severity.php`, `includes/Alerts/Rules/HighMediaRule.php` ;
- `includes/Storage/SiteRecord.php`, `includes/Storage/SitesRepository.php` ;
- `includes/Query/SitesQuery.php`, `includes/Query/AlertsQuery.php` ;
- `includes/Rest/Controller.php`, `includes/Rest/SitesController.php`, `includes/Rest/ScanController.php`, `includes/Rest/AlertsController.php` ;
- `uninstall.php`, `phpcs.xml.dist`, `CHANGELOG.md`, `readme.txt`, `multisite-radar.php`, `tests/phpstan-bootstrap.php`.

**JS et outillage, créés :**
- **Configuration :** `package.json`, `package-lock.json`, `.nvmrc`, `webpack.config.js`, `vitest.config.mjs`, `eslint.config.cjs`, `playwright.config.js`, `.wp-env.json`.
- **Scripts :** `bin/version.mjs`.
- **Tests :**
  - `tests/js/setup.mjs` ;
  - `tests/fixtures/view-queries.json` (partagé PHP/JS) ;
  - `tests/e2e/setup.sh`, `tests/e2e/specs/*.spec.js`.
- **`src/admin/`** : `config.js`, `mount.jsx`, `app.jsx`, `style.scss`, `overview.js`, `sites.js`, `alerts.js`, `settings.js`.
- **`src/store/`** : `index.js`, `paths.js`.
- **`src/hooks/`** : `use-resource.js`, `use-url-state.js`, `use-preferences.js`, `use-scan.js`.
- **`src/components/`** : `data-views/index.js`, `data-views/filters.js`, `error-notice.jsx`, `snackbars.jsx`, `badges.jsx`, `error-boundary.jsx`.
- **`src/utils/`** : `format.js`.
- **`src/views/sites/`** : `index.jsx`, `fields.jsx`, `query.js`, `actions.js`, `export-menu.jsx`, `export.js`.
- **`src/views/site-panel/`** : `index.jsx`, `summary-tab.jsx`, `content-tab.jsx`, `users-tab.jsx`, `extensions-tab.jsx`, `alerts-tab.jsx`.
- **`src/views/overview/`** : `index.jsx`, `tiles.jsx`, `scan-panel.jsx`.
- **`src/views/alerts/`** : `index.jsx`, `fields.jsx`, `query.js`.
- **`src/views/settings/`** : `index.jsx`, `fields.js`.
- **`src/blocks/sites-list/`** : `block.json`, `index.js`, `edit.jsx`, `tokens.js`, `style.scss`.
- **Tests unitaires JS :** dans un dossier `test/` à côté du code testé (`src/**/test/*.test.js(x)`).

## Commandes

| Rôle | Commande |
|---|---|
| Tests PHP (tout, ou un filtre) | `bin/test.sh` ; `bin/test.sh --filter QueueTest` |
| Normes PHP / analyse statique | `composer lint` ; `composer analyse` |
| Syntaxe PHP 7.4 | `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'` |
| Tests JS | `npm run test:unit` (ou `npx vitest run src/store`) |
| Lint JS / CSS | `npm run lint:js` ; `npm run lint:css` |
| Build | `npm run build` |
| Versions synchronisées | `npm run version:check` |
| E2E (tâche 20) | `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e` |

Chaque tâche se termine par les tests de son périmètre, puis `composer lint` et `composer analyse` (pour le PHP), ou `npm run lint:js` et `npm run test:unit` (pour le JS), avant le commit.

---

## Partie A — Socle PHP

### Task 1: File d'analyse — un budget par requête, curseur lié aux réglages, types d'activité, désactivation

**Files:**
- Modify: `includes/Scan/Queue.php`
- Modify: `includes/Install/LegacyMigration.php`
- Test: `tests/php/Scan/QueueTest.php`, `tests/php/Install/LegacyMigrationTest.php`

**Interfaces:**
- Consumes (M1) :
  - `Settings::get( string $path, $fallback )` ;
  - `SitesRepository::mark_all_dirty( int $network_id ): int`, `count_all( int )`, `count_dirty( ?int )` ;
  - `MainSite::run( callable )`, `MainSite::schedule_once( string $hook, int $delay = 0 )` ;
  - `BatchRunner::locked( callable ): bool`.
- Produces :
  - `Queue::RECOMPUTE_CURSOR` contient désormais `array{after: int, config: string}`, et non plus un entier ;
  - `Queue::on_settings_updated( $new = [], $old = [] ): void`, branché avec 2 arguments ;
  - `LegacyMigration::unschedule(): void`, branché sur `msradar_deactivated`.

- [ ] **Step 1: Écrire les tests qui échouent (QueueTest)**

Dans `tests/php/Scan/QueueTest.php` :

a) Dans `test_recompute_stops_when_its_budget_is_spent_and_resumes_after_its_cursor`, remplacer
`$this->assertSame( 3201, (int) get_site_option( self::CURSOR ) );` par :

```php
		$this->assertSame( 3201, get_site_option( self::CURSOR )['after'] );
```

b) Dans `test_daily_schedules_the_recompute_instead_of_running_it`, remplacer le message `'Not in the same request as a queue pass.'` par `'daily() only schedules the recompute; it never runs it.'`.

c) Ajouter à la fin de la classe :

```php
	public function test_recompute_waits_when_a_queue_pass_already_ran_in_this_request(): void {
		$this->make_record( 3501, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
		$this->queue->process();

		$before = time();
		$this->queue->run_recompute();

		$this->assertSame( 0, $this->plugin()->sites()->find( 3501 )->alert_level, 'A single time budget per cron request.' );
		$next = wp_next_scheduled( Queue::HOOK_RECOMPUTE );
		$this->assertNotFalse( $next );
		$this->assertGreaterThanOrEqual( $before + MINUTE_IN_SECONDS, $next );
	}

	public function test_a_queue_pass_waits_when_the_recompute_already_ran_in_this_request(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->plugin()->sites()->mark_all_dirty( $network );
		$dirty = $this->plugin()->sites()->count_dirty();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->run_recompute();
		$this->queue->process();

		$this->assertSame( $dirty, $this->plugin()->sites()->count_dirty() );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_the_cursor_records_the_settings_it_was_computed_with(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->queue->recompute_alerts( null, 0.0 );

		$this->assertSame(
			[
				'after'  => 3601,
				'config' => md5( (string) wp_json_encode( $this->plugin()->settings()->get( 'alerts' ) ) ),
			],
			get_site_option( self::CURSOR )
		);
	}

	public function test_a_cursor_written_with_other_settings_restarts_from_the_first_site(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		update_site_option(
			self::CURSOR,
			[
				'after'  => 3601,
				'config' => 'settings-of-an-older-run',
			]
		);

		$this->assertSame( 2, $this->queue->recompute_alerts() );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3601 )->alert_level, 'Sites before the old cursor are evaluated again.' );
	}

	public function test_an_integer_cursor_from_2_0_0_alpha_1_restarts_from_the_first_site(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		update_site_option( self::CURSOR, 3601 );

		$this->assertSame( 2, $this->queue->recompute_alerts() );
	}

	public function test_changing_the_activity_types_marks_every_site_for_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'post' ] ] ] );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_other_setting_changes_do_not_mark_sites(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();

		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'page', 'post' ] ] ] );
		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 30 ] ] );

		$this->assertSame( 0, $this->plugin()->sites()->count_dirty( $network ), 'The same types in another order are not a change.' );
	}
```

- [ ] **Step 2: Écrire le test qui échoue (LegacyMigrationTest)**

Ajouter à `tests/php/Install/LegacyMigrationTest.php`. Ajouter `use MultisiteRadar\Support\MainSite;` en tête si l'import manque.

```php
	public function test_deactivation_unschedules_the_menu_batch_and_reactivation_resumes_it(): void {
		delete_site_option( LegacyMigration::DONE );
		update_site_option(
			LegacyMigration::CURSOR,
			[
				'after'    => 0,
				'attempts' => 0,
			]
		);
		MainSite::schedule_once( LegacyMigration::HOOK );

		do_action( 'msradar_deactivated' );
		$this->assertFalse( wp_next_scheduled( LegacyMigration::HOOK ) );

		do_action( 'msradar_activated', true );
		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ), 'The interrupted migration resumes.' );
	}
```

- [ ] **Step 3: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'QueueTest|LegacyMigrationTest'`
Expected: FAIL. Les échecs attendus :
- `test_recompute_stops…` : `Cannot access offset…` / `null` ;
- `test_recompute_waits…` : `alert_level` vaut 3 ;
- `test_a_queue_pass_waits…` : le nombre de sites à rafraîchir a baissé ;
- les trois tests de curseur ;
- `test_changing_the_activity_types…` ;
- `test_deactivation…` : l'événement est toujours planifié.

- [ ] **Step 4: Implémenter dans `includes/Scan/Queue.php`**

1. Dans `register()`, remplacer `add_action( 'msradar_settings_updated', [ $this, 'on_settings_updated' ] );` par :

```php
		add_action( 'msradar_settings_updated', [ $this, 'on_settings_updated' ], 10, 2 );
```

2. Remplacer le contenu de `add_schedule()` par :

```php
	public function add_schedule( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : [];
		$schedules[ self::SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			// Un plugin tiers peut planifier un événement avant init : ne pas charger les traductions trop tôt.
			'display'  => did_action( 'init' ) ? __( 'Every five minutes (Multisite Radar)', 'multisite-radar' ) : 'Every five minutes (Multisite Radar)',
		];
		return $schedules;
	}
```

3. Remplacer le docblock et le corps de `process()` par :

```php
	/**
	 * Une seule passe de travail (file ou recalcul) par requête : wp-cron exécute ensemble tous les événements échus.
	 */
	public function process(): void {
		if ( $this->processed ) {
			$this->continue_soon();
			return;
		}
		$this->processed = true;
		$result          = $this->runner->run( BatchRunner::default_budget() );
		if ( ! $result['locked'] && $result['remaining'] > 0 ) {
			$this->continue_soon();
		}
	}
```

4. Dans `daily()`, remplacer la ligne de commentaire au-dessus de `MainSite::schedule_once( self::HOOK_RECOMPUTE );` par :

```php
		// Planifié plutôt qu'exécuté ici. Si wp-cron le lance dans la même requête qu'un passage de file, run_recompute() se reporte.
```

5. Remplacer `recompute_alerts()`, `pause_recompute()` et `run_recompute()` par :

```php
	/**
	 * Recalcule les alertes des sites déjà analysés du réseau courant (avec ses réglages), à partir des données stockées.
	 *
	 * Le parcours reprend après le curseur msradar_recompute_cursor si celui-ci a été écrit avec les mêmes réglages
	 * d'alertes. Sinon (réglages modifiés pendant un recalcul, ou curseur entier de la 2.0.0-alpha.1), il repart du
	 * premier site. Dès que le budget est écoulé (au moins un site est évalué par appel) ou que le verrou est perdu,
	 * la position est enregistrée et la suite est planifiée ; à la fin du parcours, le curseur est supprimé.
	 * Seuls les sites dont les alertes changent sont réécrits.
	 *
	 * @param Lock|null  $lock   Verrou détenu à rafraîchir entre deux lots.
	 * @param float|null $budget Secondes disponibles ; BatchRunner::default_budget() par défaut.
	 * @return int Nombre de sites évalués pendant cet appel, que leurs alertes aient changé (et été réécrites) ou non.
	 */
	public function recompute_alerts( ?Lock $lock = null, ?float $budget = null ): int {
		$network_id = get_current_network_id();
		$budget     = $budget ?? BatchRunner::default_budget();
		$start      = microtime( true );
		$now        = time();
		$config     = $this->alerts_config_hash();
		$cursor     = get_site_option( self::RECOMPUTE_CURSOR, false );
		$after      = is_array( $cursor ) && $config === ( $cursor['config'] ?? null ) ? max( 0, (int) ( $cursor['after'] ?? 0 ) ) : 0;
		$count      = 0;
		while ( true ) {
			$ids = $this->sites->ids_after( $after, self::RECOMPUTE_CHUNK, $network_id );
			foreach ( $this->sites->find_many( $ids ) as $site_id => $record ) {
				if ( null !== $record->scanned_at ) {
					if ( $count > 0 && microtime( true ) - $start >= $budget ) {
						$this->pause_recompute( $after, $config );
						return $count;
					}
					$this->recompute_site( $record, $now );
					++$count;
				}
				$after = $site_id;
			}
			if ( count( $ids ) < self::RECOMPUTE_CHUNK ) {
				delete_site_option( self::RECOMPUTE_CURSOR );
				return $count;
			}
			$after = (int) end( $ids );
			if ( null !== $lock && ! $lock->refresh() ) {
				$this->pause_recompute( $after, $config );
				return $count;
			}
		}
	}

	/**
	 * Empreinte des réglages d'alertes avec lesquels un parcours a été calculé.
	 */
	private function alerts_config_hash(): string {
		return md5( (string) wp_json_encode( $this->settings->get( 'alerts', [] ) ) );
	}

	private function pause_recompute( int $after, string $config ): void {
		update_site_option(
			self::RECOMPUTE_CURSOR,
			[
				'after'  => $after,
				'config' => $config,
			]
		);
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
	}

	public function run_recompute(): void {
		if ( $this->processed ) {
			// Un passage de file a déjà consommé le budget de cette requête cron.
			MainSite::schedule_once( self::HOOK_RECOMPUTE, MINUTE_IN_SECONDS );
			return;
		}
		$this->processed = true;
		$done            = $this->runner->locked(
			function ( Lock $lock ): void {
				$this->recompute_alerts( $lock );
			}
		);
		if ( ! $done ) {
			MainSite::schedule_once( self::HOOK_RECOMPUTE, MINUTE_IN_SECONDS );
		}
	}
```

6. Remplacer `on_settings_updated()` par :

```php
	/**
	 * Les nouveaux réglages s'appliquent à tout le réseau : le recalcul repart du premier site.
	 * La date de dernière activité dépend des types d'activité : s'ils changent, tous les sites sont réanalysés.
	 *
	 * @param mixed $new Réglages complets après la mise à jour.
	 * @param mixed $old Réglages complets avant la mise à jour.
	 */
	public function on_settings_updated( $new = [], $old = [] ): void {
		$this->evaluator->reset();
		delete_site_option( self::RECOMPUTE_CURSOR );
		MainSite::schedule_once( self::HOOK_RECOMPUTE );

		if ( self::activity_types( $new ) !== self::activity_types( $old ) ) {
			$this->sites->mark_all_dirty( get_current_network_id() );
			$this->continue_soon();
		}
	}

	/**
	 * @param mixed $settings Réglages complets.
	 * @return string[] Types d'activité triés (l'ordre de saisie ne compte pas).
	 */
	private static function activity_types( $settings ): array {
		$types = is_array( $settings ) ? (array) ( $settings['scan']['activity_post_types'] ?? [] ) : [];
		$types = array_values( array_unique( array_map( 'strval', $types ) ) );
		sort( $types );
		return $types;
	}
```

- [ ] **Step 5: Implémenter dans `includes/Install/LegacyMigration.php`**

Dans `register()`, ajouter après la ligne `add_action( 'msradar_activated', [ $this, 'start' ] );` :

```php
		add_action( 'msradar_deactivated', [ $this, 'unschedule' ] );
```

Ajouter la méthode publique après `start()` :

```php
	/**
	 * À la désactivation : plus d'événement en attente. Le curseur reste, et start() reprend la migration à la réactivation.
	 */
	public function unschedule(): void {
		MainSite::run(
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
			}
		);
	}
```

Si la classe n'importe pas encore `MultisiteRadar\Support\MainSite`, ajouter `use MultisiteRadar\Support\MainSite;`.

- [ ] **Step 6: Lancer les tests**

Run: `bin/test.sh --filter 'QueueTest|LegacyMigrationTest'`
Expected: PASS.

Run: `bin/test.sh`
Expected: PASS (aucune régression).

- [ ] **Step 7: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes/Scan/Queue.php includes/Install/LegacyMigration.php tests/php/Scan/QueueTest.php tests/php/Install/LegacyMigrationTest.php
git commit -m "fix: one work pass per cron request and a recompute cursor tied to the alert settings"
```

---

### Task 2: Politique d'erreurs des gestionnaires et petites corrections du socle

**Files:**
- Modify: `includes/Scan/Invalidation.php`, `includes/Scan/BatchRunner.php`, `includes/Collector/RegistryProbe.php`, `includes/Alerts/RuleRegistry.php`, `includes/Alerts/Rules/HighMediaRule.php`, `CHANGELOG.md`
- Test: `tests/php/Scan/InvalidationTest.php`, `tests/php/Scan/BatchRunnerTest.php`, `tests/php/Collector/RegistryProbeTest.php`, `tests/php/Alerts/RulesTest.php`

**Interfaces:**
- Consumes :
  - `ExtensionsRepository::delete_for_site( int )` et `SitesRepository::save( SiteRecord )`, qui lèvent `\RuntimeException` (M1) ;
  - `Schema::is_current(): bool`.
- Produces :
  - l'action **`msradar_error`**, qui reçoit `( string $context, \Throwable $error )` ; le contexte est `__METHOD__` du gestionnaire ;
  - `RuleRegistry::ID_PATTERN = '/^[a-z0-9_]{1,40}$/'`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Scan/InvalidationTest.php` — ajouter `use MultisiteRadar\Install\Schema;` et `use MultisiteRadar\Scan\Invalidation;` s'ils manquent, puis :

```php
	public function test_a_storage_failure_during_site_deletion_does_not_break_the_core_hook(): void {
		global $wpdb;
		$site_id  = self::factory()->blog->create();
		$errors   = [];
		$on_error = static function ( $context ) use ( &$errors ): void {
			$errors[] = $context;
		};
		$later    = false;
		$probe    = static function () use ( &$later ): void {
			$later = true;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, Schema::extensions_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $on_error );
		add_action( 'wp_delete_site', $probe, 99 );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			wp_delete_site( $site_id );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'wp_delete_site', $probe, 99 );
			remove_action( 'msradar_error', $on_error );
		}

		$this->assertTrue( $later, 'Later subscribers of wp_delete_site still run.' );
		$this->assertSame( [ Invalidation::class . '::on_site_deleted' ], $errors );
	}
```

`tests/php/Scan/BatchRunnerTest.php` — ajouter `use MultisiteRadar\Install\Schema;` s'il manque, puis :

```php
	public function test_a_storage_failure_while_recording_a_scan_error_does_not_escape(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$errors   = [];
		$on_error = static function ( $context ) use ( &$errors ): void {
			$errors[] = $context;
		};
		$options  = $wpdb->get_blog_prefix( $site_id ) . 'options';
		$break    = static function ( string $query ) use ( $options ): string {
			$write = 1 === preg_match( '/^\s*(INSERT|UPDATE|REPLACE)\b/i', $query );
			if ( false !== strpos( $query, $options ) || ( $write && false !== strpos( $query, Schema::sites_table() ) ) ) {
				return 'SELECT * FROM msradar_missing_table';
			}
			return $query;
		};
		add_action( 'msradar_error', $on_error );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$scanned = $this->plugin()->runner()->scan_site( $site_id );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $on_error );
		}

		$this->assertFalse( $scanned );
		$this->assertSame( [ 'MultisiteRadar\Scan\BatchRunner::scan_site' ], $errors );
	}
```

`tests/php/Collector/RegistryProbeTest.php` — ajouter `use MultisiteRadar\Install\Schema;` s'il manque, puis :

```php
	public function test_run_does_not_touch_the_sites_table_when_the_schema_is_not_installed(): void {
		$queries = [];
		$spy     = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, Schema::sites_table() ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		$version = get_site_option( Schema::OPTION );
		update_site_option( Schema::OPTION, 0 );
		$probe = new RegistryProbe( $this->plugin()->sites() );
		$probe->start_tracking();
		add_filter( 'query', $spy );
		try {
			$probe->run();
		} finally {
			remove_filter( 'query', $spy );
			update_site_option( Schema::OPTION, $version );
		}

		$this->assertSame( [], $queries );
		$this->assertIsArray( get_option( RegistryProbe::OPTION ), 'The registry is still written.' );
	}
```

`tests/php/Alerts/RulesTest.php` — ajouter les imports `use MultisiteRadar\Alerts\Alert;`, `use MultisiteRadar\Alerts\RuleInterface;`, `use MultisiteRadar\Alerts\RuleRegistry;`, `use MultisiteRadar\Alerts\Rules\HighMediaRule;` et `use MultisiteRadar\Storage\SiteRecord;` s'ils manquent, puis :

```php
	public function test_rules_with_an_invalid_identifier_are_ignored(): void {
		$bad    = new class() implements RuleInterface {
			public function id(): string {
				return 'bad,id';
			}
			public function label(): string {
				return 'Bad';
			}
			public function description(): string {
				return '';
			}
			public function default_severity(): string {
				return 'info';
			}
			public function params_schema(): array {
				return [ 'type' => 'object' ];
			}
			public function default_params(): array {
				return [];
			}
			public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
				return null;
			}
			public function message( array $args ): string {
				return '';
			}
		};
		$add    = static function ( array $rules ) use ( $bad ): array {
			$rules[] = $bad;
			return $rules;
		};
		$this->setExpectedIncorrectUsage( RuleRegistry::class . '::all' );
		add_filter( 'msradar_alert_rules', $add );
		try {
			$ids = array_keys( ( new RuleRegistry( [ new HighMediaRule() ] ) )->all() );
		} finally {
			remove_filter( 'msradar_alert_rules', $add );
		}

		$this->assertSame( [ 'high_media' ], $ids );
	}

	public function test_the_media_message_is_pluralised_and_localised(): void {
		$rule = new HighMediaRule();

		$this->assertSame( '1 media file (threshold: 1)', $rule->message( [ 'count' => 1, 'threshold' => 1 ] ) );
		$this->assertSame( '1,500 media files (threshold: 1,000)', $rule->message( [ 'count' => 1500, 'threshold' => 1000 ] ) );
	}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'InvalidationTest|BatchRunnerTest|RegistryProbeTest|RulesTest'`
Expected: FAIL. Les échecs attendus :
- les deux tests de stockage : exception `RuntimeException` non attrapée ;
- `RegistryProbe` : une requête vise la table des sites ;
- `RuleRegistry` : `bad,id` est présent ;
- le message : `1 media files…` / `1500`.

- [ ] **Step 3: Implémenter**

**`includes/Scan/Invalidation.php`**

1. Ajouter une méthode privée en fin de classe :

```php
	/**
	 * Les dépôts lèvent une exception quand une écriture échoue. Un gestionnaire de hook ne doit jamais la laisser
	 * remonter dans le hook du cœur : l'opération de WordPress réussirait, mais les abonnés suivants ne seraient pas appelés.
	 */
	private function safely( string $context, callable $callback ): void {
		try {
			$callback();
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', $context, $error );
		}
	}
```

2. Remplacer `on_site_initialized()` et `on_site_deleted()` par :

```php
	public function on_site_initialized( WP_Site $site ): void {
		if ( ! $this->ready() ) {
			return;
		}
		$this->safely(
			__METHOD__,
			function () use ( $site ): void {
				// WP_Site::$site_id contient l'ID du réseau.
				$this->sites->insert_pending( (int) $site->blog_id, (int) $site->site_id, $site->domain . $site->path );
			}
		);
	}

	public function on_site_deleted( WP_Site $site ): void {
		if ( ! $this->ready() ) {
			return;
		}
		$this->safely(
			__METHOD__,
			function () use ( $site ): void {
				$this->sites->delete( (int) $site->blog_id );
				$this->extensions->delete_for_site( (int) $site->blog_id );
			}
		);
	}
```

**`includes/Scan/BatchRunner.php`** — remplacer le bloc `catch ( Throwable $error ) { … }` de `scan_site()` par :

```php
		} catch ( Throwable $error ) {
			try {
				if ( null === get_site( $site_id ) ) {
					$this->sites->delete( $site_id );
					$this->extensions->delete_for_site( $site_id );
					return false;
				}
				$this->record_failure( $site_id, $error->getMessage() );
			} catch ( \RuntimeException $storage ) {
				// Le stockage lui-même est en échec : rien n'est enregistré, le site sera repris au prochain passage complet.
				do_action( 'msradar_error', __METHOD__, $storage );
			}
			return false;
		}
```

**`includes/Collector/RegistryProbe.php`** :
- ajouter `use MultisiteRadar\Install\Schema;` ;
- dans `run()`, remplacer `$this->sites->mark_dirty( [ get_current_blog_id() ] );` par :

```php
		if ( Schema::is_current() ) {
			$this->sites->mark_dirty( [ get_current_blog_id() ] );
		}
```

**`includes/Alerts/RuleRegistry.php`** — ajouter la constante publique et remplacer la boucle de `all()` :

```php
	public const ID_PATTERN = '/^[a-z0-9_]{1,40}$/';
```

```php
		foreach ( (array) apply_filters( 'msradar_alert_rules', $this->defaults ) as $rule ) {
			if ( ! $rule instanceof RuleInterface ) {
				continue;
			}
			$id = $rule->id();
			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				// Une virgule ou un « % » casserait l'encodage « ,id, » de la colonne alert_rules.
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s: alert rule identifier. */
							__( 'The alert rule "%s" was ignored: identifiers may only contain lowercase letters, digits and underscores (40 characters at most).', 'multisite-radar' ),
							$id
						)
					),
					'2.0.0'
				);
				continue;
			}
			$rules[ $id ] = $rule;
		}
```

**`includes/Alerts/Rules/HighMediaRule.php`** — remplacer `message()` par :

```php
	public function message( array $args ): string {
		$count = (int) ( $args['count'] ?? 0 );
		return sprintf(
			/* translators: 1: number of media files, 2: alert threshold. */
			_n( '%1$s media file (threshold: %2$s)', '%1$s media files (threshold: %2$s)', $count, 'multisite-radar' ),
			number_format_i18n( $count ),
			number_format_i18n( (int) ( $args['threshold'] ?? 0 ) )
		);
	}
```

**`CHANGELOG.md`** — remplacer la première ligne par `# Changelog — Multisite Radar`.

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

Un test M1 de `RulesTest` ou d'`AlertsQueryTest` vérifie peut-être l'ancien texte `1500 media files (threshold: 1000)`. Si c'est le cas, mettre à jour l'attente avec le nouveau texte localisé, `1,500 media files (threshold: 1,000)`.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes/Scan/Invalidation.php includes/Scan/BatchRunner.php includes/Collector/RegistryProbe.php includes/Alerts/RuleRegistry.php includes/Alerts/Rules/HighMediaRule.php CHANGELOG.md tests/php
git commit -m "fix: hook handlers never let storage exceptions escape into core hooks"
```

---
### Task 3: Listes allégées — schéma v2 (`siteurl`), colonnes explicites, nom brut, gravités nommées

**Files:**
- Modify: `includes/Install/Schema.php`, `includes/Storage/SiteRecord.php`, `includes/Storage/SitesRepository.php`, `includes/Collector/SiteCollector.php`, `includes/Alerts/Severity.php`, `includes/Query/SitesQuery.php`, `includes/Rest/SitesController.php`, `includes/Scan/Queue.php`
- Test: `tests/php/Install/SchemaTest.php`, `tests/php/Storage/SitesRepositoryTest.php`, `tests/php/Query/SitesQueryTest.php`, `tests/php/Collector/SiteCollectorTest.php`, `tests/php/Scan/QueueTest.php`

**Interfaces:**
- Consumes : `Severity::level( string ): int` et `Severity::name( int ): string` (M1).
- Produces :
  - `Schema::VERSION = 2` et la colonne `siteurl varchar(255)` ;
  - `SiteRecord::$siteurl` (string) et `SiteRecord::$partial` (bool : vrai pour une ligne lue par une liste, sans `data`) ;
  - `SitesRepository::save()` et `save_alerts()` lèvent `\LogicException` sur un enregistrement partiel ;
  - `Severity::NONE = 'none'` et `Severity::names(): string[]`, qui renvoie `[ 'none', 'info', 'warning', 'error' ]` ;
  - `SitesQuery::identity( SiteRecord ): array{id: int, name: string, url: string, admin_url: string}` (statique) ;
  - la constante `SitesQuery::ALERT_LEVELS` est **supprimée** ;
  - `Queue::on_upgraded( $version = 0 ): void`, branché sur `msradar_upgraded`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Install/SchemaTest.php` — ajouter :

```php
	public function test_version_2_adds_the_siteurl_column(): void {
		global $wpdb;

		$this->assertTrue( Schema::install() );

		$this->assertSame( 2, Schema::VERSION );
		$this->assertSame( 2, (int) get_site_option( Schema::OPTION ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Schema::sites_table(), 'siteurl' ) ) );
	}
```

`tests/php/Storage/SitesRepositoryTest.php` — ajouter :

```php
	public function test_list_rows_do_not_read_the_detailed_data_and_cannot_be_saved(): void {
		$this->make_record(
			501,
			[
				'name'       => 'Listed',
				'scanned_at' => '2026-09-01 00:00:00',
				'data'       => [ 'post_types' => [ [ 'name' => 'post' ] ] ],
			]
		);
		$selects = [];
		$spy     = static function ( string $query ) use ( &$selects ): string {
			if ( 0 === strpos( ltrim( $query ), 'SELECT site_id' ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		$args    = [
			'network_id'      => get_current_network_id(),
			'page'            => 1,
			'per_page'        => 20,
			'search'          => 'Listed',
			'orderby'         => 'name',
			'order'           => 'asc',
			'alert_level'     => [],
			'status'          => [],
			'theme'           => '',
			'plugin'          => '',
			'has_users'       => null,
			'inactive_since'  => null,
			'registry_status' => [],
			'rule'            => '',
		];
		add_filter( 'query', $spy );
		try {
			$result = $this->plugin()->sites()->query( $args );
		} finally {
			remove_filter( 'query', $spy );
		}

		$this->assertCount( 1, $selects );
		$this->assertDoesNotMatchRegularExpression( '/\bdata\b/', $selects[0] );
		$record = $result['items'][0];
		$this->assertTrue( $record->partial );
		$this->assertSame( [], $record->data );
		$this->assertFalse( $this->plugin()->sites()->find( 501 )->partial );

		$this->expectException( \LogicException::class );
		$this->plugin()->sites()->save( $record );
	}
```

`tests/php/Query/SitesQueryTest.php` — ajouter :

```php
	public function test_admin_links_use_the_wordpress_address_when_it_differs_from_home(): void {
		$this->make_record(
			206,
			[
				'name'       => 'Sub',
				'url'        => 'https://home.test/',
				'siteurl'    => 'https://home.test/wp/',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$items = $this->query->list( [ 'search' => 'home.test' ] )['items'];

		$this->assertSame( [ 206 ], wp_list_pluck( $items, 'id' ) );
		$this->assertSame( 'https://home.test/', $items[0]['url'] );
		$this->assertSame( 'https://home.test/wp/wp-admin/', $items[0]['admin_url'] );
	}

	public function test_alert_levels_are_reported_by_name(): void {
		$this->assertSame( 'error', $this->query->get( 102 )['alert_level'] );
		$this->assertSame( 'none', $this->query->get( 101 )['alert_level'] );
		$this->assertSame( [ 104, 101 ], $this->ids( [ 'alert_level' => [ 'none', 'bogus' ] ] ) );
	}
```

`tests/php/Collector/SiteCollectorTest.php` :

a) Dans `test_corrupted_or_empty_options_do_not_break_the_scan`, remplacer
`$this->assertSame( sprintf( 'Site #%d', $site_id ), $record->name );` par :

```php
		$this->assertSame( '', $record->name, 'The fallback name is applied when reading, in the reader’s language.' );
```

b) Ajouter :

```php
	public function test_the_wordpress_address_is_stored_in_its_own_column(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option( $site_id, 'siteurl', 'https://example.test/wp' );

		$this->assertSame( 'https://example.test/wp', $this->collect( $site_id )->siteurl );
	}
```

`tests/php/Scan/QueueTest.php` — ajouter :

```php
	public function test_a_schema_upgrade_requests_a_full_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();

		do_action( 'msradar_upgraded', 2 );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'SchemaTest|SitesRepositoryTest|SitesQueryTest|SiteCollectorTest|QueueTest'`
Expected: FAIL. Les échecs attendus :
- `Schema::VERSION` vaut 1 ;
- `Undefined property … siteurl` ;
- le `SELECT` contient `data` ;
- le nom vaut `Site #N` ;
- l'analyse n'est pas demandée après la mise à niveau.

- [ ] **Step 3: Implémenter**

**`includes/Install/Schema.php`** :
- `public const VERSION = 2;` ;
- dans le `CREATE TABLE {$sites}`, ajouter la ligne suivante juste après `url varchar(255) NOT NULL DEFAULT '',` :

```
siteurl varchar(255) NOT NULL DEFAULT '',
```

Ajouter ce commentaire au-dessus de `VERSION` :

```php
	/**
	 * 1 : tables de M1 ; 2 : colonne siteurl (M2). Les tables events/snapshots de M6 prendront la version 3.
	 */
```

**`includes/Storage/SiteRecord.php`** :
- ajouter `'siteurl'` à `STRING_FIELDS` (après `'url'`) ;
- ajouter `'siteurl' => 255,` à `STRING_WIDTHS` ;
- ajouter les propriétés suivantes après `$url` et après `$scanned_at` :

```php
	public string $siteurl            = '';
```

```php
	/**
	 * Vrai pour une ligne lue par une liste (sans la colonne data) : elle ne doit jamais être réécrite.
	 */
	public bool $partial = false;
```

Dans `from_row()`, ajouter avant `return $record;` :

```php
		$record->partial = ! array_key_exists( 'data', $row );
```

**`includes/Storage/SitesRepository.php`** :

1. Ajouter la constante après `STATUS_CLAUSES` :

```php
	/**
	 * Colonnes lues par les listes : tout sauf data, le JSON détaillé, lu seulement par find() et find_many().
	 */
	private const LIST_COLUMNS = 'site_id, network_id, name, url, siteurl, is_public, is_archived, is_spam, is_deleted, theme_stylesheet, theme_template, users_count, admins_count, content_count, media_count, disk_bytes, disk_is_estimate, db_bytes, autoload_bytes, last_activity_gmt, alert_level, alerts_count, alert_rules, registry_status, dirty, dirty_since, scanned_at';
```

2. Au début de `save()` et de `save_alerts()`, ajouter :

```php
		if ( $record->partial ) {
			throw new \LogicException( 'A partial site record (read from a list) cannot be written back.' );
		}
```

Compléter le docblock de chacune des deux méthodes par `@throws \LogicException Si l'enregistrement vient d'une liste.`

3. Dans `query()`, remplacer `"SELECT * FROM %i WHERE {$where} ORDER BY %i {$order}, site_id ASC LIMIT %d OFFSET %d"` par :

```php
				'SELECT ' . self::LIST_COLUMNS . " FROM %i WHERE {$where} ORDER BY %i {$order}, site_id ASC LIMIT %d OFFSET %d",
```

Cette ligne se trouve déjà dans le bloc `phpcs:disable`/`phpcs:enable` existant.

**`includes/Collector/SiteCollector.php`** :
- après `$record->url = …;`, ajouter `$record->siteurl = $siteurl;` ;
- supprimer le bloc `if ( '' === trim( $record->name ) ) { … sprintf( __( 'Site #%d' … ) ) … }` situé avant `return $record;`, car le repli se fait à la lecture.

**`includes/Alerts/Severity.php`** — ajouter :

```php
	public const NONE = 'none';

	/**
	 * Noms publics des niveaux, du plus faible au plus fort (filtres REST et interface).
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_merge( [ self::NONE ], array_keys( self::LEVELS ) );
	}
```

Remplacer `return $names[ $level ] ?? 'none';` par `return $names[ $level ] ?? self::NONE;`.

**`includes/Query/SitesQuery.php`** :

1. Supprimer la constante `ALERT_LEVELS`. Ajouter `use MultisiteRadar\Alerts\Severity;`.

2. Dans `list()`, remplacer la ligne `'alert_level' => …` par :

```php
			'alert_level'     => array_values( array_unique( array_map( [ Severity::class, 'level' ], array_intersect( array_map( 'strval', (array) $args['alert_level'] ), Severity::names() ) ) ) ),
```

3. Remplacer `summary()` par la version suivante et ajouter `identity()` juste avant :

```php
	/**
	 * Identité d'un site, commune aux listes, à la fiche et aux alertes.
	 * Le nom de repli est construit ici, dans la langue du lecteur : le collecteur stocke le nom brut, même vide.
	 *
	 * @return array{id: int, name: string, url: string, admin_url: string}
	 */
	public static function identity( SiteRecord $record ): array {
		$base = self::absolute( '' !== $record->siteurl ? $record->siteurl : $record->url );
		$name = $record->name;
		if ( '' === trim( $name ) ) {
			/* translators: %d: site ID. */
			$name = sprintf( __( 'Site #%d', 'multisite-radar' ), $record->site_id );
		}
		return [
			'id'        => $record->site_id,
			'name'      => $name,
			'url'       => self::absolute( $record->url ),
			'admin_url' => '' !== $base ? trailingslashit( $base ) . 'wp-admin/' : '',
		];
	}

	public function summary( SiteRecord $record ): array {
		return array_merge(
			self::identity( $record ),
			[
				'status'            => [
					'public'   => $record->is_public,
					'archived' => $record->is_archived,
					'spam'     => $record->is_spam,
					'deleted'  => $record->is_deleted,
				],
				'theme'             => [
					'stylesheet' => $record->theme_stylesheet,
					'template'   => $record->theme_template,
				],
				'users_count'       => $record->users_count,
				'admins_count'      => $record->admins_count,
				'content_count'     => $record->content_count,
				'media_count'       => $record->media_count,
				'disk_bytes'        => $record->disk_bytes,
				'db_bytes'          => $record->db_bytes,
				'autoload_bytes'    => $record->autoload_bytes,
				'last_activity_gmt' => self::date( (string) $record->last_activity_gmt ),
				'alert_level'       => Severity::name( $record->alert_level ),
				'alerts_count'      => $record->alerts_count,
				'alert_rules'       => $record->alert_rule_ids(),
				'registry_status'   => $record->registry_status,
				'pending'           => null === $record->scanned_at,
				'dirty'             => $record->dirty,
				'scanned_at_gmt'    => self::date( (string) $record->scanned_at ),
			]
		);
	}
```

**`includes/Rest/SitesController.php`** :
- ajouter `use MultisiteRadar\Alerts\Severity;` ;
- remplacer les deux `array_keys( SitesQuery::ALERT_LEVELS )` par `Severity::names()`.

**`includes/Scan/Queue.php`** :
- dans `register()`, ajouter `add_action( 'msradar_upgraded', [ $this, 'on_upgraded' ] );` ;
- ajouter la méthode :

```php
	/**
	 * Après une mise à niveau du schéma, les nouvelles colonnes ne se remplissent qu'à l'analyse :
	 * tout le réseau courant est marqué (la version 2 ajoute siteurl).
	 *
	 * @param mixed $version Version de schéma installée.
	 */
	public function on_upgraded( $version = 0 ): void {
		$this->schedule();
		$this->request_full_scan( get_current_network_id() );
	}
```

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

Si un test M1 lit `SitesQuery::ALERT_LEVELS`, le remplacer par `Severity::names()`.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "perf: list queries skip the data column; store siteurl and the raw site name (schema v2)"
```

---

### Task 4: Contrat de lecture — sélection, inactivité, pages bornées, erreurs 500, parcours par tranches

**Files:**
- Modify: `includes/Query/SitesQuery.php`, `includes/Storage/SitesRepository.php`, `includes/Rest/Controller.php`, `includes/Rest/SitesController.php`, `includes/Rest/ScanController.php`, `includes/Rest/AlertsController.php`
- Test: `tests/php/Query/SitesQueryTest.php`, `tests/php/Rest/SitesControllerTest.php`, `tests/php/Rest/ScanControllerTest.php`, `tests/php/Rest/AlertsControllerTest.php`

**Interfaces:**
- Consumes : `SitesRepository::query( array $args )` (tâche 3).
- Produces :
  - `SitesQuery::MAX_PAGE = 100000` et `SitesQuery::MAX_INCLUDE = 500` ;
  - `SitesQuery::normalize( array $args, int $max_per_page = 100 ): array`, qui renvoie les arguments du dépôt, avec la nouvelle clé `include` (`int[]`) ;
  - `SitesQuery::each( array $args, callable $consumer, int $chunk = 500 ): int` : `$consumer( array $summary_item )` est appelé pour chaque site ;
  - la clé `include` (`int[]`) dans `SitesQuery::defaults()` ;
  - `SitesRepository::query()` et `alert_counts()` lèvent `\RuntimeException` si une lecture échoue ;
  - `SitesRepository::ids_in_network( array $site_ids, int $network_id ): int[]` ;
  - `Rest\Controller::guard( callable $callback )` (protégée) : elle renvoie la réponse du callback, ou `WP_Error( 'msradar_storage_error', …, 500 )` sur `\RuntimeException` ;
  - les nouveaux paramètres REST :
    - `GET /sites?include[]=…` (ou `include=1,2`), au plus 500 ;
    - `POST /scan` avec `scope=ids` répond 400 `msradar_no_sites` si aucun site n'appartient au réseau.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Query/SitesQueryTest.php` :

a) Dans `test_filters`, remplacer l'assertion `inactive_since` par :

```php
		$this->assertSame( [ 102 ], $this->ids( [ 'inactive_since' => '2025-01-01 00:00:00' ] ), 'Sites without any activity date are not inactive, as for the inactive rule.' );
```

b) Ajouter :

```php
	public function test_include_restricts_to_the_given_sites_of_the_network(): void {
		$this->make_record( 105, [ 'name' => 'Elsewhere', 'network_id' => 2 ] );

		$this->assertSame( [ 101, 103 ], $this->ids( [ 'include' => [ 103, 101, 105, -1, 0 ] ] ) );
		$this->assertSame( [ 104, 101, 102, 103 ], $this->ids( [ 'include' => [] ] ) );
	}

	public function test_huge_pages_are_capped_instead_of_breaking_the_query(): void {
		$result = $this->query->list( [ 'page' => PHP_INT_MAX ] );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( 4, $result['total'] );
	}

	public function test_each_walks_every_matching_site_in_chunks(): void {
		$seen  = [];
		$count = $this->query->each(
			[ 'orderby' => 'id' ],
			static function ( array $item ) use ( &$seen ): void {
				$seen[] = $item['id'];
			},
			3
		);

		$this->assertSame( 4, $count );
		$this->assertSame( [ 101, 102, 103, 104 ], $seen );
	}

	public function test_read_failures_are_exceptions_not_empty_results(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT COUNT(*)' ) && false !== strpos( $query, Schema::sites_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->expectException( \RuntimeException::class );
			$this->query->list( [] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}
	}
```

Ajouter `use MultisiteRadar\Install\Schema;` en tête du fichier.

`tests/php/Rest/SitesControllerTest.php` — ajouter `use MultisiteRadar\Install\Schema;`, puis :

```php
	public function test_include_selects_sites(): void {
		$this->login_as_super_admin();

		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'include' => [ 102, 999 ] ] )->get_data(), 'id' ) );
		$this->assertSame( [ 101, 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'include' => '102,101' ] )->get_data(), 'id' ) );
	}

	public function test_inactive_since_honours_the_utc_offset(): void {
		$this->login_as_super_admin();
		$this->make_record(
			103,
			[
				'name'              => 'Gamma',
				'users_count'       => 1,
				'last_activity_gmt' => '2024-12-31 23:30:00',
				'scanned_at'        => '2026-09-01 00:00:00',
			]
		);

		$ids = wp_list_pluck( $this->request( 'GET', '/sites', [ 'inactive_since' => '2025-01-01T01:00:00+02:00' ] )->get_data(), 'id' );

		$this->assertSame( [ 102 ], $ids, '01:00+02:00 is 23:00 UTC: Gamma was still active at 23:30 UTC.' );
	}

	public function test_empty_maps_are_json_objects(): void {
		$this->login_as_super_admin();

		$json = (string) wp_json_encode( $this->request( 'GET', '/sites/101' )->get_data() );

		$this->assertStringContainsString( '"options":{}', $json );
		$this->assertStringContainsString( '"by_role":{}', $json );
	}

	public function test_a_failed_read_is_a_500_not_an_empty_list(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT COUNT(*)' ) && false !== strpos( $query, Schema::sites_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/sites' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'msradar_storage_error', $response->get_data()['code'] );
	}
```

`tests/php/Rest/ScanControllerTest.php` — ajouter :

```php
	public function test_scope_ids_only_marks_sites_of_the_current_network(): void {
		$this->login_as_super_admin();
		$this->make_record( 701, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 702, [ 'scanned_at' => '2026-09-01 00:00:00', 'network_id' => 2 ] );

		$response = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => [ 701, 702 ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->plugin()->sites()->find( 701 )->dirty );
		$this->assertFalse( $this->plugin()->sites()->find( 702 )->dirty );
	}

	public function test_scope_ids_without_any_site_of_the_network_is_rejected(): void {
		$this->login_as_super_admin();
		$this->make_record( 702, [ 'network_id' => 2 ] );

		foreach ( [ [], [ 702 ], [ 999999 ] ] as $ids ) {
			$response = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => $ids ] );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'msradar_no_sites', $response->get_data()['code'] );
		}
	}
```

`tests/php/Rest/AlertsControllerTest.php` — ajouter `use MultisiteRadar\Install\Schema;`, puis :

```php
	public function test_a_failed_summary_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/alerts/summary' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'SitesQueryTest|SitesControllerTest|ScanControllerTest|AlertsControllerTest'`
Expected: FAIL. Les échecs attendus :
- `inactive_since` renvoie `[102, 103]` ;
- `include` est ignoré ;
- `each()` est indéfinie ;
- pas d'exception, réponse 200 au lieu de 500 ;
- `options` vaut `[]` ;
- le site 702 est marqué, réponse 200 au lieu de 400.

- [ ] **Step 3: Implémenter**

**`includes/Storage/SitesRepository.php`** :

1. Dans `query()` :
- remplacer la clause d'inactivité par :

```php
		if ( null !== $args['inactive_since'] ) {
			// Comme la règle « inactive » : un site sans aucune date d'activité n'est pas inactif.
			$clauses[] = '(scanned_at IS NOT NULL AND last_activity_gmt IS NOT NULL AND last_activity_gmt < %s)';
			$params[]  = $args['inactive_since'];
		}
```

- après la clause `rule`, ajouter :

```php
		$include = array_map( 'intval', (array) ( $args['include'] ?? [] ) );
		if ( [] !== $include ) {
			$clauses[] = 'site_id IN (' . implode( ',', array_fill( 0, count( $include ), '%d' ) ) . ')';
			$params    = array_merge( $params, $include );
		}
```

- appeler `self::check_read();` juste après le `get_var()` du total et juste après le `get_results()` des lignes.

2. Dans `alert_counts()`, appeler `self::check_read();` après le `get_row()` et après chaque `get_var()` de la boucle des règles.

3. Ajouter :

```php
	/**
	 * @param int[] $site_ids
	 * @return int[] Ceux qui appartiennent au réseau, par ordre croissant.
	 */
	public function ids_in_network( array $site_ids, int $network_id ): array {
		global $wpdb;
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $site_ids ) {
			return [];
		}
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM %i WHERE network_id = %d AND site_id IN (' . implode( ',', array_fill( 0, count( $site_ids ), '%d' ) ) . ') ORDER BY site_id ASC',
				array_merge( [ Schema::sites_table(), $network_id ], $site_ids )
			)
		);
		self::check_read();
		return array_map( 'intval', (array) $found );
	}

	/**
	 * Une lecture en échec ne doit pas passer pour une liste vide.
	 *
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
```

Compléter les docblocks de `query()` et `alert_counts()` par `@throws \RuntimeException Si une lecture échoue.`

**`includes/Query/SitesQuery.php`** :

1. Ajouter les constantes `public const MAX_PAGE = 100000;` et `public const MAX_INCLUDE = 500;`. Ajouter `'include' => [],` à la fin du tableau de `defaults()`.

2. Remplacer `list()` par `normalize()`, `list()` et `each()` :

```php
	/**
	 * Arguments publics (REST, WP-CLI, exports) → arguments du dépôt, bornés et validés.
	 */
	public function normalize( array $args, int $max_per_page = 100 ): array {
		$args    = array_merge( self::defaults(), $args );
		$plugin  = (string) $args['plugin'];
		$orderby = (string) $args['orderby'];
		$include = array_values( array_unique( array_filter( array_map( 'intval', (array) $args['include'] ), static fn ( int $id ): bool => $id > 0 ) ) );

		return [
			'network_id'      => get_current_network_id(),
			'page'            => min( self::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'        => min( $max_per_page, max( 1, (int) $args['per_page'] ) ),
			'search'          => trim( (string) $args['search'] ),
			'orderby'         => isset( SitesRepository::ORDERBY[ $orderby ] ) ? $orderby : 'name',
			'order'           => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'alert_level'     => array_values( array_unique( array_map( [ Severity::class, 'level' ], array_intersect( array_map( 'strval', (array) $args['alert_level'] ), Severity::names() ) ) ) ),
			'status'          => array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) ),
			'theme'           => (string) $args['theme'],
			'plugin'          => $this->is_network_active( $plugin ) ? '' : $plugin,
			'has_users'       => null === $args['has_users'] ? null : (bool) $args['has_users'],
			'inactive_since'  => null === $args['inactive_since'] ? null : (string) $args['inactive_since'],
			'registry_status' => array_values( array_intersect( array_map( 'strval', (array) $args['registry_status'] ), self::REGISTRY_STATUSES ) ),
			'rule'            => (string) $args['rule'],
			'include'         => array_slice( $include, 0, self::MAX_INCLUDE ),
		];
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$result = $this->sites->query( $this->normalize( $args ) );
		return [
			'items' => array_map( [ $this, 'summary' ], $result['items'] ),
			'total' => $result['total'],
		];
	}

	/**
	 * Parcourt tous les sites qui correspondent aux filtres, par tranches et sans le plafond de 100 par page (exports).
	 *
	 * @param callable $consumer Appelé avec chaque site mis en forme par summary().
	 * @return int Nombre de sites parcourus.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function each( array $args, callable $consumer, int $chunk = 500 ): int {
		$chunk = max( 1, $chunk );
		$query = $this->normalize( $args, $chunk );
		$count = 0;
		$page  = 1;
		while ( true ) {
			$query['page']     = $page;
			$query['per_page'] = $chunk;
			$result            = $this->sites->query( $query );
			foreach ( $result['items'] as $record ) {
				$consumer( $this->summary( $record ) );
				++$count;
			}
			if ( count( $result['items'] ) < $chunk || $count >= $result['total'] ) {
				return $count;
			}
			++$page;
		}
	}
```

**`includes/Rest/Controller.php`** — ajouter `use WP_Error;` et `use WP_REST_Response;`, puis :

```php
	/**
	 * Les dépôts lèvent une RuntimeException quand une lecture échoue. La réponse devient alors une erreur 500,
	 * plutôt qu'une liste vide que l'interface prendrait pour un réseau sans site.
	 *
	 * @param callable $callback Renvoie la réponse de la route.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function guard( callable $callback ) {
		try {
			return $callback();
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', static::class, $error );
			return new WP_Error( 'msradar_storage_error', __( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ), [ 'status' => 500 ] );
		}
	}
```

**`includes/Rest/SitesController.php`** :

1. Dans `get_collection_params()`, ajouter :

```php
			'include'         => [
				'type'     => 'array',
				'default'  => [],
				'maxItems' => SitesQuery::MAX_INCLUDE,
				'items'    => [
					'type'    => 'integer',
					'minimum' => 1,
				],
			],
```

Sur `inactive_since`, ajouter `'description' => __( 'Sites analysed, with an activity date, and no activity since this date.', 'multisite-radar' ),`.

2. Remplacer `get_items()` par :

```php
	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				// Sans forcer l'UTC : un décalage explicite (+02:00) est converti, et non remplacé.
				$since  = isset( $request['inactive_since'] ) ? rest_parse_date( (string) $request['inactive_since'] ) : false;
				$result = $this->query->list(
					[
						'page'            => (int) $request['page'],
						'per_page'        => $per_page,
						'search'          => (string) $request['search'],
						'orderby'         => (string) $request['orderby'],
						'order'           => (string) $request['order'],
						'alert_level'     => (array) $request['alert_level'],
						'status'          => (array) $request['status'],
						'theme'           => (string) $request['theme'],
						'plugin'          => (string) $request['plugin'],
						'has_users'       => isset( $request['has_users'] ) ? (bool) $request['has_users'] : null,
						'inactive_since'  => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
						'registry_status' => (array) $request['registry_status'],
						'rule'            => (string) $request['rule'],
						'include'         => (array) $request['include'],
					]
				);

				$response = new WP_REST_Response( $result['items'] );
				$response->header( 'X-WP-Total', (string) $result['total'] );
				$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
				return $response;
			}
		);
	}
```

3. Remplacer `get_item()` par :

```php
	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$item = $this->query->get( (int) $request['id'] );
		if ( null === $item ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		// Des tableaux associatifs vides seraient encodés [] : le client attend des objets.
		$item['options']          = (object) $item['options'];
		$item['users']['by_role'] = (object) $item['users']['by_role'];
		return new WP_REST_Response( $item );
	}
```

**`includes/Rest/ScanController.php`** :
- ajouter `use WP_Error;` ;
- passer le type de retour de `request_scan()` en `@return WP_REST_Response|WP_Error` (supprimer le type de retour natif `: WP_REST_Response`) ;
- remplacer la branche `ids` par :

```php
		} elseif ( 'ids' === $scope ) {
			$ids = $this->sites->ids_in_network( (array) $request['ids'], $network_id );
			if ( [] === $ids ) {
				return new WP_Error( 'msradar_no_sites', __( 'None of the requested sites belongs to this network.', 'multisite-radar' ), [ 'status' => 400 ] );
			}
			$this->sites->mark_dirty( $ids );
			$this->queue->continue_soon();
		} else {
```

**`includes/Rest/AlertsController.php`** — remplacer `get_summary()` par :

```php
	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary() {
		return $this->guard(
			function (): WP_REST_Response {
				return new WP_REST_Response( $this->query->summary( get_current_network_id() ) );
			}
		);
	}
```

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: site selection, aligned inactivity filter and 500 errors on failed reads in the REST API"
```

---

### Task 5: `GET /alerts` — liste site × règle, paginée et filtrable

**Files:**
- Modify: `includes/Storage/SitesRepository.php`, `includes/Query/AlertsQuery.php`, `includes/Rest/AlertsController.php`, `includes/Plugin.php`
- Test: `tests/php/Query/AlertsQueryTest.php`, `tests/php/Rest/AlertsControllerTest.php`

**Interfaces:**
- Consumes :
  - `AlertEvaluator::config( RuleInterface ): array{enabled: bool, severity: string, params: array}` (M1) ;
  - `AlertFormatter::format( array $stored ): array` (M1) ;
  - `SitesQuery::identity( SiteRecord )` (tâche 3) ;
  - `SitesQuery::MAX_PAGE` (tâche 4).
- Produces :
  - `SitesRepository::alert_pairs( array $args ): array{items: array<int, array{site_id: int, rule: string}>, total: int}`. Les clés de `$args` :
    - `network_id` ;
    - `rules` (`array<string, int>`, identifiant de règle → niveau de gravité) ;
    - `search`, `orderby` (`rule`|`name`|`severity`), `order`, `page`, `per_page`.
  - Le nouveau constructeur `AlertsQuery( SitesRepository, RuleRegistry, AlertEvaluator, AlertFormatter )`.
  - `AlertsQuery::ORDERBY = [ 'rule', 'name', 'severity' ]` et `AlertsQuery::defaults(): array`.
  - `AlertsQuery::list( array $args ): array{items: array[], total: int}`. Chaque élément vaut :

    ```
    { id: "<site_id>:<rule>", site: identity, rule, label, severity, message }
    ```

  - `AlertsQuery::summary()` : chaque entrée de `by_rule` gagne `severity` (gravité réglée) et `enabled` (bool).
  - La route `GET /multisite-radar/v1/alerts` :
    - paramètres `page`, `per_page` (≤ 100), `search`, `severity[]`, `rule[]`, `orderby`, `order` ;
    - en-têtes `X-WP-Total` et `X-WP-TotalPages`.

- [ ] **Step 1: Écrire les tests qui échouent**

Dans `tests/php/Query/AlertsQueryTest.php`, le test M1 `test_summary_counts_sites_by_severity_and_rule` attend les entrées exactes de `by_rule`. Remplacer son assertion `by_rule` par :

```php
		$this->assertSame(
			[
				[ 'rule' => 'no_users', 'label' => 'Site without users', 'severity' => 'error', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'inactive', 'label' => 'Inactive site', 'severity' => 'warning', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'high_media', 'label' => 'Many media files', 'severity' => 'info', 'enabled' => true, 'count' => 1 ],
			],
			$summary['by_rule']
		);
```

Puis ajouter dans la classe (ses enregistrements utilisent les ID libres 601 à 604) :

```php
	private function seed_alerts(): void {
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			601,
			[
				'name'         => 'Alpha',
				'url'          => 'https://alpha.test/',
				'scanned_at'   => $scanned,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
				'data'         => [ 'alerts' => [ [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ] ] ],
			]
		);
		$this->make_record(
			602,
			[
				'name'         => 'Beta',
				'url'          => 'https://beta.test/',
				'scanned_at'   => $scanned,
				'alert_level'  => 2,
				'alerts_count' => 2,
				'alert_rules'  => ',inactive,high_media,',
				'data'         => [
					'alerts' => [
						[ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ],
						[ 'rule' => 'high_media', 'severity' => 'info', 'args' => [ 'count' => 1500, 'threshold' => 1000 ] ],
					],
				],
			]
		);
		$this->make_record(
			603,
			[
				'name'        => 'Elsewhere',
				'network_id'  => 2,
				'scanned_at'  => $scanned,
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
			]
		);
		$this->make_record( 604, [ 'name' => 'Clean', 'scanned_at' => $scanned ] );
	}

	private function ids( array $args ): array {
		return wp_list_pluck( $this->plugin()->alerts_query()->list( $args )['items'], 'id' );
	}

	public function test_lists_one_row_per_site_and_rule_of_the_network_sorted_by_rule(): void {
		$this->seed_alerts();

		$result = $this->plugin()->alerts_query()->list( [] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ '602:high_media', '602:inactive', '601:no_users' ], wp_list_pluck( $result['items'], 'id' ) );
		$this->assertSame(
			[
				'id'       => '602:inactive',
				'site'     => [
					'id'        => 602,
					'name'      => 'Beta',
					'url'       => 'https://beta.test/',
					'admin_url' => 'https://beta.test/wp-admin/',
				],
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => $this->plugin()->rules()->get( 'inactive' )->message( [ 'months' => 8 ] ),
			],
			$result['items'][1]
		);
	}

	public function test_filters_sorting_search_and_pagination(): void {
		$this->seed_alerts();

		$this->assertSame( [ '601:no_users' ], $this->ids( [ 'severity' => [ 'error' ] ] ) );
		$this->assertSame( [ '602:inactive' ], $this->ids( [ 'rule' => [ 'inactive', 'unknown' ] ] ) );
		$this->assertSame( [ '601:no_users', '602:inactive', '602:high_media' ], $this->ids( [ 'orderby' => 'severity', 'order' => 'desc' ] ) );
		$this->assertSame( [ '601:no_users', '602:high_media', '602:inactive' ], $this->ids( [ 'orderby' => 'name' ] ) );
		$this->assertSame( [ '602:high_media', '602:inactive' ], $this->ids( [ 'search' => 'beta' ] ) );

		$page = $this->plugin()->alerts_query()->list( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( [ '601:no_users' ], wp_list_pluck( $page['items'], 'id' ) );
		$this->assertSame( 3, $page['total'] );
	}

	public function test_disabled_rules_and_overridden_severities_follow_the_settings(): void {
		$this->seed_alerts();
		$this->plugin()->settings()->update(
			[
				'alerts' => [
					'rules' => [
						'high_media' => [ 'enabled' => false ],
						'inactive'   => [ 'severity' => 'error' ],
					],
				],
			]
		);

		$this->assertSame( [ '602:inactive', '601:no_users' ], $this->ids( [ 'severity' => [ 'error' ] ] ) );

		$summary = $this->plugin()->alerts_query()->summary( get_current_network_id() );
		$by_rule = array_column( $summary['by_rule'], null, 'rule' );
		$this->assertFalse( $by_rule['high_media']['enabled'] );
		$this->assertSame( 'error', $by_rule['inactive']['severity'] );
	}

	public function test_an_empty_rule_selection_returns_nothing(): void {
		$this->seed_alerts();

		$this->assertSame( [], $this->ids( [ 'severity' => [ 'info' ], 'rule' => [ 'no_users' ] ] ) );
	}
```

`tests/php/Rest/AlertsControllerTest.php` — ajouter :

```php
	public function test_lists_alerts_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alerts' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/alerts' )->get_status() );

		$this->login_as_super_admin();
		$this->make_record(
			611,
			[
				'name'        => 'Alpha',
				'scanned_at'  => '2026-09-01 00:00:00',
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
				'data'        => [ 'alerts' => [ [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ] ] ],
			]
		);

		$response = $this->request( 'GET', '/alerts', [ 'per_page' => 1, 'severity' => 'error,warning' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( '611:no_users', $response->get_data()[0]['id'] );

		foreach ( [ [ 'severity' => [ 'fatal' ] ], [ 'rule' => [ 'Bad,Rule' ] ], [ 'orderby' => 'site_id' ], [ 'per_page' => 101 ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/alerts', $params )->get_status() );
		}
	}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'AlertsQueryTest|AlertsControllerTest'`
Expected: FAIL (`Call to undefined method …list()`, route `/alerts` inconnue : 404).

- [ ] **Step 3: Implémenter le dépôt**

Dans `includes/Storage/SitesRepository.php`, ajouter :

```php
	/**
	 * Couples (site, règle) des sites en alerte du réseau, paginés en SQL.
	 *
	 * Une branche UNION ALL par règle demandée, chacune avec la gravité réglée de la règle en constante :
	 * le tri par gravité et le comptage restent en SQL, sans décoder le JSON des sites.
	 *
	 * @param array $args network_id, rules (identifiant => niveau de gravité), search, orderby, order, page, per_page.
	 * @return array{items: array<int, array{site_id: int, rule: string}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function alert_pairs( array $args ): array {
		global $wpdb;
		$rules = (array) $args['rules'];
		if ( [] === $rules ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$table    = Schema::sites_table();
		$search   = (string) $args['search'];
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$branches = [];
		$params   = [];
		foreach ( $rules as $rule => $level ) {
			$branch = 'SELECT site_id, name, %s AS rule, %d AS severity FROM %i WHERE network_id = %d AND alert_rules LIKE %s';
			array_push( $params, (string) $rule, (int) $level, $table, (int) $args['network_id'], '%' . $wpdb->esc_like( ',' . $rule . ',' ) . '%' );
			if ( '' !== $search ) {
				$branch .= ' AND (name LIKE %s OR url LIKE %s)';
				array_push( $params, $like, $like );
			}
			$branches[] = $branch;
		}
		$union     = implode( ' UNION ALL ', $branches );
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$orders    = [
			'rule'     => "rule {$direction}, name ASC, site_id ASC",
			'name'     => "name {$direction}, site_id ASC, rule ASC",
			'severity' => "severity {$direction}, rule ASC, name ASC, site_id ASC",
		];
		$order_by  = $orders[ $args['orderby'] ] ?? $orders['rule'];
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $union ne contient que des fragments fixes et des placeholders ; $order_by vient d'une liste blanche.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ({$union}) AS pairs", $params ) );
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT site_id, rule FROM ({$union}) AS pairs ORDER BY {$order_by} LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, $offset ] ) ),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

		return [
			'items' => array_map(
				static fn ( array $row ): array => [
					'site_id' => (int) $row['site_id'],
					'rule'    => (string) $row['rule'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}
```

- [ ] **Step 4: Implémenter la requête et la route**

**`includes/Query/AlertsQuery.php`** — remplacer la classe par :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Alertes du réseau : synthèse (sites par gravité et par règle) et liste site × règle.
 */
final class AlertsQuery {

	public const ORDERBY = [ 'rule', 'name', 'severity' ];

	private SitesRepository $sites;
	private RuleRegistry $rules;
	private AlertEvaluator $evaluator;
	private AlertFormatter $formatter;

	public function __construct( SitesRepository $sites, RuleRegistry $rules, AlertEvaluator $evaluator, AlertFormatter $formatter ) {
		$this->sites     = $sites;
		$this->rules     = $rules;
		$this->evaluator = $evaluator;
		$this->formatter = $formatter;
	}

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'severity' => [],
			'rule'     => [],
			'orderby'  => 'rule',
			'order'    => 'asc',
		];
	}

	/**
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary( int $network_id ): array {
		$rules   = $this->rules->all();
		$counts  = $this->sites->alert_counts( $network_id, array_keys( $rules ) );
		$by_rule = [];
		foreach ( $rules as $id => $rule ) {
			$config    = $this->evaluator->config( $rule );
			$by_rule[] = [
				'rule'     => $id,
				'label'    => $rule->label(),
				'severity' => $config['severity'],
				'enabled'  => $config['enabled'],
				'count'    => $counts['rules'][ $id ] ?? 0,
			];
		}

		return [
			'total_sites'       => $counts['total'],
			'scanned_sites'     => $counts['total'] - $counts['pending'],
			'pending_sites'     => $counts['pending'],
			'sites_with_alerts' => $counts['with_alerts'],
			'by_severity'       => [
				'error'   => $counts['error'],
				'warning' => $counts['warning'],
				'info'    => $counts['info'],
			],
			'by_rule'           => $by_rule,
		];
	}

	/**
	 * Liste site × règle du réseau courant. La gravité est celle des réglages actuels de la règle :
	 * elle sert au filtre, au tri et à l'affichage. Les règles désactivées n'apparaissent pas.
	 *
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args       = array_merge( self::defaults(), $args );
		$severities = array_values( array_intersect( array_map( 'strval', (array) $args['severity'] ), [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ) );
		$wanted     = array_map( 'strval', (array) $args['rule'] );
		$levels     = [];
		$configs    = [];
		foreach ( $this->rules->all() as $id => $rule ) {
			$config = $this->evaluator->config( $rule );
			if ( ! $config['enabled'] ) {
				continue;
			}
			if ( [] !== $wanted && ! in_array( $id, $wanted, true ) ) {
				continue;
			}
			if ( [] !== $severities && ! in_array( $config['severity'], $severities, true ) ) {
				continue;
			}
			$levels[ $id ]  = Severity::level( $config['severity'] );
			$configs[ $id ] = $config;
		}

		$orderby = (string) $args['orderby'];
		$result  = $this->sites->alert_pairs(
			[
				'network_id' => get_current_network_id(),
				'rules'      => $levels,
				'search'     => trim( (string) $args['search'] ),
				'orderby'    => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'rule',
				'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
				'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
				'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
			]
		);

		$records = $this->sites->find_many( array_values( array_unique( array_column( $result['items'], 'site_id' ) ) ) );
		$items   = [];
		foreach ( $result['items'] as $pair ) {
			$record = $records[ $pair['site_id'] ] ?? null;
			$rule   = $this->rules->get( $pair['rule'] );
			if ( null === $record || null === $rule ) {
				continue; // Site supprimé entre les deux lectures.
			}
			$items[] = [
				'id'       => $pair['site_id'] . ':' . $pair['rule'],
				'site'     => SitesQuery::identity( $record ),
				'rule'     => $pair['rule'],
				'label'    => $rule->label(),
				'severity' => $configs[ $pair['rule'] ]['severity'],
				'message'  => $this->message( $record, $rule ),
			];
		}

		return [
			'items' => $items,
			'total' => $result['total'],
		];
	}

	/**
	 * Message traduit de l'alerte stockée, ou le libellé de la règle si les arguments manquent.
	 */
	private function message( SiteRecord $record, RuleInterface $rule ): string {
		foreach ( (array) ( $record->data['alerts'] ?? [] ) as $stored ) {
			if ( is_array( $stored ) && $rule->id() === ( $stored['rule'] ?? null ) ) {
				$formatted = $this->formatter->format( [ $stored ] );
				return [] !== $formatted ? $formatted[0]['message'] : $rule->label();
			}
		}
		return $rule->label();
	}
}
```

**`includes/Plugin.php`** — remplacer `alerts_query()` par :

```php
	public function alerts_query(): AlertsQuery {
		return $this->alerts_query ??= new AlertsQuery( $this->sites(), $this->rules(), $this->evaluator(), $this->formatter() );
	}
```

**`includes/Rest/AlertsController.php`** :
- ajouter `use MultisiteRadar\Alerts\RuleRegistry;`, `use WP_Error;` et `use WP_REST_Request;` ;
- dans `register_routes()`, ajouter avant la route `/summary` :

```php
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
```

Ajouter les méthodes :

```php
	public function get_collection_params(): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'search'   => [
				'type'    => 'string',
				'default' => '',
			],
			'severity' => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => [ 'error', 'warning', 'info' ],
				],
			],
			'rule'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type'    => 'string',
					'pattern' => trim( RuleRegistry::ID_PATTERN, '/' ),
				],
			],
			'orderby'  => [
				'type'    => 'string',
				'default' => 'rule',
				'enum'    => AlertsQuery::ORDERBY,
			],
			'order'    => [
				'type'    => 'string',
				'default' => 'asc',
				'enum'    => [ 'asc', 'desc' ],
			],
		];
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				$result   = $this->query->list(
					[
						'page'     => (int) $request['page'],
						'per_page' => $per_page,
						'search'   => (string) $request['search'],
						'severity' => (array) $request['severity'],
						'rule'     => (array) $request['rule'],
						'orderby'  => (string) $request['orderby'],
						'order'    => (string) $request['order'],
					]
				);
				$response = new WP_REST_Response( $result['items'] );
				$response->header( 'X-WP-Total', (string) $result['total'] );
				$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
				return $response;
			}
		);
	}
```

`trim( RuleRegistry::ID_PATTERN, '/' )` donne `^[a-z0-9_]{1,40}$` : le schéma REST attend un motif sans délimiteurs.

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 6: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: GET /alerts lists site and rule pairs with severity filters and SQL pagination"
```

---
### Task 6: `GET /sites/{id}/users` — utilisateurs d'un site, chargés à la demande

**Files:**
- Create: `includes/Query/SiteUsersQuery.php`
- Modify: `includes/Rest/SitesController.php`, `includes/Plugin.php`
- Test: `tests/php/Query/SiteUsersQueryTest.php`, `tests/php/Rest/SitesControllerTest.php`

**Interfaces:**
- Consumes : `SitesQuery::MAX_PAGE` (tâche 4).
- Produces :
  - `SiteUsersQuery::ORDERBY = [ 'login', 'display_name', 'registered' ]` et `SiteUsersQuery::defaults(): array` ;
  - `SiteUsersQuery::list( int $site_id, array $args ): ?array`, qui renvoie `array{items, total}` ou `null` si le site n'appartient pas au réseau courant. Chaque élément vaut :

    ```
    { id: int, login: string, display_name: string, roles: string[], super_admin: bool, registered_gmt: string|null }
    ```

  - le nouveau constructeur `SitesController( SitesQuery $query, SiteUsersQuery $users )` et `Plugin::site_users_query(): SiteUsersQuery` ;
  - la route `GET /multisite-radar/v1/sites/{id}/users` :
    - paramètres `page`, `per_page` (≤ 100), `search`, `role`, `orderby`, `order` ;
    - en-têtes `X-WP-Total` et `X-WP-TotalPages` ;
    - 404 `msradar_site_not_found`.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/Query/SiteUsersQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\SiteUsersQuery;
use MultisiteRadar\Tests\TestCase;

final class SiteUsersQueryTest extends TestCase {

	private int $site_id;

	public function set_up(): void {
		parent::set_up();
		// Le créateur du site (l'utilisateur 1, « admin », super-admin) en est administrateur.
		$this->site_id = self::factory()->blog->create();
		$zoe           = self::factory()->user->create(
			[
				'user_login'   => 'zoe',
				'display_name' => 'Zoé Martin',
			]
		);
		$adam          = self::factory()->user->create(
			[
				'user_login'   => 'adam',
				'display_name' => 'Adam',
			]
		);
		self::factory()->user->create( [ 'user_login' => 'outsider' ] );
		add_user_to_blog( $this->site_id, $zoe, 'editor' );
		add_user_to_blog( $this->site_id, $adam, 'author' );
	}

	private function query(): SiteUsersQuery {
		return $this->plugin()->site_users_query();
	}

	public function test_lists_only_the_users_of_the_site_without_email(): void {
		$result = $this->query()->list( $this->site_id, [] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ 'adam', 'admin', 'zoe' ], wp_list_pluck( $result['items'], 'login' ) );
		$zoe = $result['items'][2];
		$this->assertSame( [ 'id', 'login', 'display_name', 'roles', 'super_admin', 'registered_gmt' ], array_keys( $zoe ) );
		$this->assertSame( 'Zoé Martin', $zoe['display_name'] );
		$this->assertSame( [ 'editor' ], $zoe['roles'] );
		$this->assertFalse( $zoe['super_admin'] );
		$this->assertTrue( $result['items'][1]['super_admin'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $zoe['registered_gmt'] );
	}

	public function test_search_role_sorting_and_pagination(): void {
		$this->assertSame( [ 'zoe' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'search' => 'Zo' ] )['items'], 'login' ) );
		$this->assertSame( [ 'zoe' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'role' => 'editor' ] )['items'], 'login' ) );
		$this->assertSame( [ 'zoe', 'admin', 'adam' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'order' => 'desc' ] )['items'], 'login' ) );

		$page = $this->query()->list(
			$this->site_id,
			[
				'per_page' => 1,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'admin' ], wp_list_pluck( $page['items'], 'login' ) );
		$this->assertSame( 3, $page['total'] );
	}

	public function test_unknown_sites_and_sites_of_another_network_are_null(): void {
		$other      = self::factory()->network->create();
		$other_site = self::factory()->blog->create( [ 'network_id' => $other ] );

		$this->assertNull( $this->query()->list( 999999, [] ) );
		$this->assertNull( $this->query()->list( $other_site, [] ) );
	}
}
```

Dans `tests/php/Rest/SitesControllerTest.php`, ajouter :

```php
	public function test_site_users_route(): void {
		$site_id = self::factory()->blog->create();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', "/sites/{$site_id}/users" )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request( 'GET', "/sites/{$site_id}/users", [ 'per_page' => 1 ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'admin', $response->get_data()[0]['login'] );

		$this->assertSame( 404, $this->request( 'GET', '/sites/999999/users' )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', "/sites/{$site_id}/users", [ 'orderby' => 'email' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', "/sites/{$site_id}/users", [ 'role' => 'Bad Role' ] )->get_status() );
	}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'SiteUsersQueryTest|SitesControllerTest'`
Expected: FAIL (`Call to undefined method …site_users_query()`, route inconnue : 404 au lieu de 403/200).

- [ ] **Step 3: Implémenter**

Créer `includes/Query/SiteUsersQuery.php` :

```php
<?php
namespace MultisiteRadar\Query;

use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Utilisateurs d'un site, lus à la demande (onglet Utilisateurs de la fiche).
 * Aucune adresse e-mail n'est exposée : identifiant, login, nom affiché, rôles, super-admin, inscription.
 */
final class SiteUsersQuery {

	public const ORDERBY = [ 'login', 'display_name', 'registered' ];

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'role'     => '',
			'orderby'  => 'login',
			'order'    => 'asc',
		];
	}

	/**
	 * @return array{items: array[], total: int}|null Null si le site n'existe pas dans le réseau courant.
	 */
	public function list( int $site_id, array $args ): ?array {
		global $wpdb;
		$site = get_site( $site_id );
		if ( null === $site || (int) $site->network_id !== get_current_network_id() ) {
			return null;
		}

		$args       = array_merge( self::defaults(), $args );
		$orderby    = (string) $args['orderby'];
		$query_args = [
			'blog_id'     => $site_id,
			'number'      => min( 100, max( 1, (int) $args['per_page'] ) ),
			'paged'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'orderby'     => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'login',
			'order'       => 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC',
			'fields'      => [ 'ID', 'user_login', 'display_name', 'user_registered' ],
			'count_total' => true,
		];
		$search     = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = [ 'user_login', 'display_name' ];
		}
		$role = sanitize_key( (string) $args['role'] );
		if ( '' !== $role ) {
			$query_args['role'] = $role;
		}

		$query  = new WP_User_Query( $query_args );
		$users  = (array) $query->get_results();
		$prefix = $wpdb->get_blog_prefix( $site_id );
		$roles  = array_keys( (array) get_blog_option( $site_id, $prefix . 'user_roles', [] ) );
		update_meta_cache( 'user', array_map( static fn ( $user ): int => (int) $user->ID, $users ) );

		$items = [];
		foreach ( $users as $user ) {
			$caps    = get_user_meta( (int) $user->ID, $prefix . 'capabilities', true );
			$granted = array_keys( array_filter( is_array( $caps ) ? $caps : [] ) );
			$items[] = [
				'id'             => (int) $user->ID,
				'login'          => (string) $user->user_login,
				'display_name'   => (string) $user->display_name,
				// Les capacités accordées individuellement ne sont pas des rôles.
				'roles'          => [] !== $roles ? array_values( array_intersect( $granted, $roles ) ) : $granted,
				'super_admin'    => is_super_admin( (int) $user->ID ),
				'registered_gmt' => '' !== (string) $user->user_registered ? mysql_to_rfc3339( (string) $user->user_registered ) : null,
			];
		}

		return [
			'items' => $items,
			'total' => (int) $query->get_total(),
		];
	}
}
```

**`includes/Rest/SitesController.php`** :
- ajouter `use MultisiteRadar\Query\SiteUsersQuery;` et `use WP_REST_Request;` ;
- ajouter la propriété `private SiteUsersQuery $users;` ;
- remplacer le constructeur par :

```php
	public function __construct( SitesQuery $query, SiteUsersQuery $users ) {
		$this->query = $query;
		$this->users = $users;
	}
```

Dans `register_routes()`, ajouter :

```php
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/users',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_users' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'id'       => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
						'search'   => [
							'type'    => 'string',
							'default' => '',
						],
						'role'     => [
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[a-z0-9_-]{0,60}$',
						],
						'orderby'  => [
							'type'    => 'string',
							'default' => 'login',
							'enum'    => SiteUsersQuery::ORDERBY,
						],
						'order'    => [
							'type'    => 'string',
							'default' => 'asc',
							'enum'    => [ 'asc', 'desc' ],
						],
					],
				],
			]
		);
```

Ajouter la méthode :

```php
	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_users( WP_REST_Request $request ) {
		$per_page = (int) $request['per_page'];
		$result   = $this->users->list(
			(int) $request['id'],
			[
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
				'search'   => (string) $request['search'],
				'role'     => (string) $request['role'],
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
			]
		);
		if ( null === $result ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}
```

**`includes/Plugin.php`** :
- ajouter `use MultisiteRadar\Query\SiteUsersQuery;` et la propriété `private ?SiteUsersQuery $site_users_query = null;` ;
- ajouter la méthode :

```php
	public function site_users_query(): SiteUsersQuery {
		return $this->site_users_query ??= new SiteUsersQuery();
	}
```

Dans `register_rest_routes()`, remplacer `new SitesController( $this->sites_query() )` par `new SitesController( $this->sites_query(), $this->site_users_query() )`.

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: GET /sites/{id}/users lists a site's users without email addresses"
```

---

### Task 7: Préférences d'affichage — `GET/POST /preferences`

**Files:**
- Create: `includes/Settings/Preferences.php`, `includes/Rest/PreferencesController.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Settings/PreferencesTest.php`, `tests/php/Rest/PreferencesControllerTest.php`

**Interfaces:**
- Produces :
  - `Preferences::META = 'msradar_view_prefs'` et `Preferences::PER_PAGE = [ 10, 20, 50, 100 ]` ;
  - `Preferences::defaults()` renvoie :

    ```
    { sites: { fields: [], layout: 'table', per_page: 20 }, alerts: { fields: [], per_page: 20 } }
    ```

  - `Preferences::schema(): array`, `Preferences::get( int $user_id ): array` (toujours complètes) ;
  - `Preferences::update( int $user_id, array $patch ): array|WP_Error`. La fusion se fait vue par vue, clé par clé, et une liste `fields` est remplacée ;
  - `Plugin::preferences(): Preferences` ;
  - `GET /multisite-radar/v1/preferences` et `POST /multisite-radar/v1/preferences`, tous deux sur `msradar_view` ;
  - une liste `fields` vide signifie « colonnes par défaut de l'interface ».

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/Settings/PreferencesTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\TestCase;

final class PreferencesTest extends TestCase {

	public function test_defaults_until_something_is_saved(): void {
		$user = self::factory()->user->create();

		$this->assertSame( Preferences::defaults(), $this->plugin()->preferences()->get( $user ) );
	}

	public function test_update_merges_per_view_and_replaces_lists(): void {
		$user  = self::factory()->user->create();
		$prefs = $this->plugin()->preferences();

		$prefs->update( $user, [ 'sites' => [ 'fields' => [ 'theme', 'users_count' ], 'per_page' => 50 ] ] );
		$result = $prefs->update( $user, [ 'sites' => [ 'fields' => [ 'media_count' ] ] ] );

		$this->assertSame(
			[
				'fields'   => [ 'media_count' ],
				'layout'   => 'table',
				'per_page' => 50,
			],
			$result['sites']
		);
		$this->assertSame( Preferences::defaults()['alerts'], $result['alerts'] );
		$this->assertSame( $result, $prefs->get( $user ) );
	}

	public function test_invalid_patches_are_rejected(): void {
		$user = self::factory()->user->create();

		foreach ( [
			[ 'sites' => [ 'per_page' => 7 ] ],
			[ 'sites' => [ 'layout' => 'list' ] ],
			[ 'sites' => [ 'fields' => [ 'Bad Field' ] ] ],
			[ 'alerts' => [ 'layout' => 'grid' ] ],
			[ 'other' => [] ],
		] as $patch ) {
			$result = $this->plugin()->preferences()->update( $user, $patch );
			$this->assertWPError( $result );
			$this->assertSame( 'msradar_invalid_preferences', $result->get_error_code() );
		}
		$this->assertSame( Preferences::defaults(), $this->plugin()->preferences()->get( $user ) );
	}

	public function test_a_corrupted_view_falls_back_to_its_defaults_without_losing_the_others(): void {
		$user = self::factory()->user->create();
		update_user_meta(
			$user,
			Preferences::META,
			[
				'sites'  => [ 'per_page' => 'lots' ],
				'alerts' => [ 'per_page' => 100 ],
			]
		);

		$prefs = $this->plugin()->preferences()->get( $user );

		$this->assertSame( Preferences::defaults()['sites'], $prefs['sites'] );
		$this->assertSame( 100, $prefs['alerts']['per_page'] );
	}
}
```

Créer `tests/php/Rest/PreferencesControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\RestTestCase;

final class PreferencesControllerTest extends RestTestCase {

	public function test_requires_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/preferences' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/preferences' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/preferences', [ 'sites' => [ 'per_page' => 50 ] ] )->get_status() );
	}

	public function test_reads_and_updates_the_current_users_preferences_only(): void {
		$this->login_as_super_admin();
		$this->assertSame( Preferences::defaults(), $this->request( 'GET', '/preferences' )->get_data() );

		$response = $this->request( 'POST', '/preferences', [ 'sites' => [ 'layout' => 'grid' ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'grid', $response->get_data()['sites']['layout'] );

		$this->login_as_super_admin();
		$this->assertSame( 'table', $this->request( 'GET', '/preferences' )->get_data()['sites']['layout'], 'Each user has their own preferences.' );
	}

	public function test_rejects_invalid_or_empty_bodies(): void {
		$this->login_as_super_admin();

		$this->assertSame( 400, $this->request( 'POST', '/preferences', [] )->get_status() );
		$response = $this->request( 'POST', '/preferences', [ 'sites' => [ 'per_page' => 7 ] ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'msradar_invalid_preferences', $response->get_data()['code'] );
	}
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'PreferencesTest|PreferencesControllerTest'`
Expected: FAIL (`Class "MultisiteRadar\Settings\Preferences" not found`).

- [ ] **Step 3: Implémenter**

Créer `includes/Settings/Preferences.php` :

```php
<?php
namespace MultisiteRadar\Settings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Préférences d'affichage de chaque utilisateur, par vue : colonnes visibles, présentation, lignes par page.
 * Stockées dans la méta utilisateur msradar_view_prefs, commune à tout le réseau (les métas utilisateur sont globales).
 */
final class Preferences {

	public const META     = 'msradar_view_prefs';
	public const PER_PAGE = [ 10, 20, 50, 100 ];

	public static function defaults(): array {
		return [
			'sites'  => [
				'fields'   => [],
				'layout'   => 'table',
				'per_page' => 20,
			],
			'alerts' => [
				'fields'   => [],
				'per_page' => 20,
			],
		];
	}

	public static function schema(): array {
		$fields   = [
			'type'        => 'array',
			'maxItems'    => 40,
			'uniqueItems' => true,
			'items'       => [
				'type'    => 'string',
				'pattern' => '^[a-z0-9_]{1,40}$',
			],
		];
		$per_page = [
			'type' => 'integer',
			'enum' => self::PER_PAGE,
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'sites'  => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'fields'   => $fields,
						'layout'   => [
							'type' => 'string',
							'enum' => [ 'table', 'grid' ],
						],
						'per_page' => $per_page,
					],
				],
				'alerts' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'fields'   => $fields,
						'per_page' => $per_page,
					],
				],
			],
		];
	}

	public function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return self::merge( self::defaults(), self::valid_views( is_array( $stored ) ? $stored : [] ) );
	}

	/**
	 * @return array|WP_Error Préférences complètes après mise à jour, ou erreur de validation (statut 400).
	 */
	public function update( int $user_id, array $patch ) {
		$schema = self::schema();
		$valid  = rest_validate_value_from_schema( $patch, $schema, 'preferences' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'msradar_invalid_preferences', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		$clean  = rest_sanitize_value_from_schema( $patch, $schema, 'preferences' );
		$stored = get_user_meta( $user_id, self::META, true );
		update_user_meta(
			$user_id,
			self::META,
			self::merge( self::valid_views( is_array( $stored ) ? $stored : [] ), is_array( $clean ) ? $clean : [] )
		);
		return $this->get( $user_id );
	}

	/**
	 * Vues valides d'une valeur enregistrée : une vue corrompue retombe sur ses valeurs par défaut, sans toucher aux autres.
	 */
	private static function valid_views( array $stored ): array {
		$views = [];
		foreach ( self::schema()['properties'] as $view => $view_schema ) {
			if ( isset( $stored[ $view ] ) && is_array( $stored[ $view ] ) && true === rest_validate_value_from_schema( $stored[ $view ], $view_schema, $view ) ) {
				$views[ $view ] = $stored[ $view ];
			}
		}
		return $views;
	}

	/**
	 * Fusion à deux niveaux : chaque vue fusionne ses clés ; une liste (fields) est remplacée, pas fusionnée.
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $view => $values ) {
			$base[ $view ] = array_merge( (array) ( $base[ $view ] ?? [] ), (array) $values );
		}
		return $base;
	}
}
```

Créer `includes/Rest/PreferencesController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Settings\Preferences;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Préférences d'affichage de l'utilisateur courant.
 */
final class PreferencesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'preferences';

	private Preferences $preferences;

	public function __construct( Preferences $preferences ) {
		$this->preferences = $preferences;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_preferences' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_preferences' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_item_schema(): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'msradar-preferences',
			],
			Preferences::schema()
		);
	}

	public function get_preferences(): WP_REST_Response {
		return new WP_REST_Response( $this->preferences->get( get_current_user_id() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_preferences( WP_REST_Request $request ) {
		$patch = (array) $request->get_json_params();
		if ( [] === $patch ) {
			return new WP_Error( 'msradar_invalid_preferences', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$result = $this->preferences->update( get_current_user_id(), $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
}
```

**`includes/Plugin.php`** :
- ajouter les imports `use MultisiteRadar\Rest\PreferencesController;` et `use MultisiteRadar\Settings\Preferences;` ;
- ajouter la propriété `private ?Preferences $preferences = null;` ;
- ajouter la méthode :

```php
	public function preferences(): Preferences {
		return $this->preferences ??= new Preferences();
	}
```

Dans `register_rest_routes()`, ajouter `new PreferencesController( $this->preferences() ),` à la liste des contrôleurs.

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: per-user display preferences with GET/POST /preferences"
```

---

### Task 8: Exports CSV et JSON (`admin-post.php?action=msradar_export`)

**Files:**
- Create: `includes/Export/SitesColumns.php`, `includes/Export/CsvWriter.php`, `includes/Export/JsonWriter.php`, `includes/Export/ExportHandler.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Export/CsvWriterTest.php`, `tests/php/Export/JsonWriterTest.php`, `tests/php/Export/ExportHandlerTest.php`

**Interfaces:**
- Consumes : `SitesQuery::each( array $args, callable $consumer, int $chunk = 500 ): int` (tâche 4) et `Capabilities::VIEW`.
- Produces :
  - `ExportHandler::ACTION = 'msradar_export'` : c'est aussi l'action du nonce `_wpnonce`, qu'utilisera l'interface (tâche 12) ;
  - `ExportHandler::params( array $input ): array|WP_Error`, qui renvoie `array{resource: string, format: string, fields: string[], filters: array}` ;
  - `ExportHandler::write( array $params, resource $stream ): int` et `ExportHandler::filename( string $resource, string $format ): string` ;
  - `SitesColumns::all(): array<string, string>` (clé → en-tête traduit), `SitesColumns::select( string[] ): string[]` et `SitesColumns::row( array $item, string[] $keys ): array` ;
  - `CsvWriter::cell( $value ): string` (neutralise les formules).
  - Les paramètres GET de l'export :
    - `action=msradar_export`, `_wpnonce`, `resource=sites`, `format=csv|json` ;
    - `fields` : liste séparée par des virgules ou tableau ; vide = toutes les colonnes ;
    - les filtres de `GET /sites` : `search`, `orderby`, `order`, `alert_level`, `status`, `registry_status`, `rule`, `theme`, `plugin`, `include` (listes séparées par des virgules ou tableaux).

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/Export/CsvWriterTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\CsvWriter;
use MultisiteRadar\Tests\TestCase;

final class CsvWriterTest extends TestCase {

	public function test_writes_a_bom_a_header_and_rfc_4180_rows(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$csv    = new CsvWriter( $stream );

		$csv->header( [ 'ID', 'Name' ] );
		$csv->row(
			[
				'id'   => 12,
				'name' => 'Blog "RH", Paris',
			]
		);
		$csv->row(
			[
				'id'   => null,
				'name' => true,
			]
		);
		rewind( $stream );

		$this->assertSame( "\xEF\xBB\xBFID,Name\n12,\"Blog \"\"RH\"\", Paris\"\n,1\n", stream_get_contents( $stream ) );
	}

	/**
	 * @dataProvider formulas
	 */
	public function test_cells_that_a_spreadsheet_would_run_as_formulas_are_neutralised( string $value, string $expected ): void {
		$this->assertSame( $expected, CsvWriter::cell( $value ) );
	}

	public static function formulas(): array {
		return [
			'equals'       => [ '=HYPERLINK("http://evil.test","x")', '\'=HYPERLINK("http://evil.test","x")' ],
			'plus'         => [ '+33 blog', "'+33 blog" ],
			'minus'        => [ '-cmd', "'-cmd" ],
			'at'           => [ '@SUM(A1)', "'@SUM(A1)" ],
			'tab'          => [ "\tdata", "'\tdata" ],
			'negative int' => [ '-12', '-12' ],
			'plain'        => [ 'Blog RH', 'Blog RH' ],
			'empty'        => [ '', '' ],
		];
	}
}
```

Créer `tests/php/Export/JsonWriterTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\JsonWriter;
use MultisiteRadar\Tests\TestCase;

final class JsonWriterTest extends TestCase {

	public function test_streams_a_single_valid_document(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$json   = new JsonWriter( $stream );

		$json->begin( [ 'filters' => (object) [] ] );
		$json->item( [ 'id' => 1, 'name' => 'Été / <b>' ] );
		$json->item( [ 'id' => 2, 'name' => null ] );
		$json->end();
		rewind( $stream );
		$raw = (string) stream_get_contents( $stream );

		$this->assertStringContainsString( '"filters":{}', $raw );
		$this->assertSame(
			[
				'meta'  => [ 'filters' => [] ],
				'items' => [
					[ 'id' => 1, 'name' => 'Été / <b>' ],
					[ 'id' => 2, 'name' => null ],
				],
			],
			json_decode( $raw, true )
		);
	}

	public function test_an_empty_export_is_still_valid_json(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$json   = new JsonWriter( $stream );

		$json->begin( [] );
		$json->end();
		rewind( $stream );

		$this->assertSame( [ 'meta' => [], 'items' => [] ], json_decode( (string) stream_get_contents( $stream ), true ) );
	}
}
```

Créer `tests/php/Export/ExportHandlerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Export\SitesColumns;
use MultisiteRadar\Tests\TestCase;
use WPDieException;

final class ExportHandlerTest extends TestCase {

	private ExportHandler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->handler = $this->plugin()->export();
		$scanned       = '2026-09-01 00:00:00';
		$this->make_record( 801, [ 'name' => 'Alpha', 'url' => 'https://alpha.test/', 'users_count' => 3, 'scanned_at' => $scanned, 'theme_stylesheet' => 'astra' ] );
		$this->make_record( 802, [ 'name' => '=HYPERLINK("http://evil.test")', 'url' => 'https://beta.test/', 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'scanned_at' => $scanned, 'is_archived' => true ] );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		parent::tear_down();
	}

	private function export( array $input ): string {
		$params = $this->handler->params( $input );
		$this->assertIsArray( $params );
		$stream = fopen( 'php://memory', 'w+b' );
		$this->handler->write( $params, $stream );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	public function test_params_validate_the_resource_format_fields_and_filters(): void {
		$this->assertSame( 'msradar_unknown_resource', $this->handler->params( [ 'resource' => 'users' ] )->get_error_code() );
		$this->assertSame( 'msradar_unknown_format', $this->handler->params( [ 'format' => 'xlsx' ] )->get_error_code() );

		$params = $this->handler->params(
			[
				'format'      => 'json',
				'fields'      => 'name,bogus,id',
				'alert_level' => 'error,warning',
				'include'     => [ '802', '0', 'x' ],
				'search'      => '',
			]
		);
		$this->assertSame( 'sites', $params['resource'] );
		$this->assertSame( 'json', $params['format'] );
		$this->assertSame( [ 'id', 'name' ], $params['fields'], 'Known fields only, in canonical order.' );
		$this->assertSame(
			[
				'alert_level' => [ 'error', 'warning' ],
				'include'     => [ 802, 0, 0 ],
			],
			$params['filters']
		);
		$this->assertSame( array_keys( SitesColumns::all() ), $this->handler->params( [] )['fields'], 'No fields means every column.' );
	}

	public function test_csv_export_applies_filters_and_neutralises_formulas(): void {
		$csv = $this->export(
			[
				'format' => 'csv',
				'fields' => 'id,name,status,alert_rules',
				'status' => 'archived',
			]
		);

		$this->assertSame( "\xEF\xBB\xBFID,Name,Status,\"Alert rules\"\n802,\"'=HYPERLINK(\"\"http://evil.test\"\")\",\"public,archived\",no_users\n", $csv );
	}

	public function test_json_export_has_a_meta_block_and_typed_values(): void {
		$data = json_decode( $this->export( [ 'format' => 'json', 'fields' => 'id,users_count,disk_bytes', 'include' => '801' ] ), true );

		$this->assertSame( 'sites', $data['meta']['resource'] );
		$this->assertSame( MSRADAR_VERSION, $data['meta']['version'] );
		$this->assertSame( [ 'include' => [ 801 ] ], $data['meta']['filters'] );
		$this->assertSame( [ 'id', 'users_count', 'disk_bytes' ], $data['meta']['fields'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['meta']['generated_gmt'] );
		$this->assertSame( [ [ 'id' => 801, 'users_count' => 3, 'disk_bytes' => null ] ], $data['items'] );
	}

	public function test_the_file_name_is_timestamped_in_utc(): void {
		$this->assertMatchesRegularExpression( '/^multisite-radar-sites-\d{8}-\d{6}\.csv$/', $this->handler->filename( 'sites', 'csv' ) );
	}

	public function test_handle_requires_a_valid_nonce(): void {
		$this->login_as( true );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		$this->expectException( WPDieException::class );
		$this->handler->handle();
	}

	public function test_handle_requires_the_view_capability(): void {
		$this->login_as( false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ExportHandler::ACTION );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'not allowed' );
		$this->handler->handle();
	}

	private function login_as( bool $super_admin ): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( $super_admin ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
	}
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'CsvWriterTest|JsonWriterTest|ExportHandlerTest'`
Expected: FAIL (`Class "MultisiteRadar\Export\CsvWriter" not found`).

- [ ] **Step 3: Implémenter**

Créer `includes/Export/SitesColumns.php` :

```php
<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Colonnes exportables des sites : clés du résumé REST, aplaties en valeurs scalaires.
 */
final class SitesColumns {

	/**
	 * @return array<string, string> Clé => en-tête traduit, dans l'ordre d'export.
	 */
	public static function all(): array {
		return [
			'id'                => __( 'ID', 'multisite-radar' ),
			'name'              => __( 'Name', 'multisite-radar' ),
			'url'               => __( 'URL', 'multisite-radar' ),
			'admin_url'         => __( 'Admin URL', 'multisite-radar' ),
			'status'            => __( 'Status', 'multisite-radar' ),
			'theme'             => __( 'Theme', 'multisite-radar' ),
			'users_count'       => __( 'Users', 'multisite-radar' ),
			'admins_count'      => __( 'Administrators', 'multisite-radar' ),
			'content_count'     => __( 'Published content', 'multisite-radar' ),
			'media_count'       => __( 'Media', 'multisite-radar' ),
			'disk_bytes'        => __( 'Disk usage (bytes)', 'multisite-radar' ),
			'db_bytes'          => __( 'Database size (bytes)', 'multisite-radar' ),
			'autoload_bytes'    => __( 'Autoloaded options (bytes)', 'multisite-radar' ),
			'last_activity_gmt' => __( 'Last activity (UTC)', 'multisite-radar' ),
			'alert_level'       => __( 'Alert level', 'multisite-radar' ),
			'alerts_count'      => __( 'Alerts', 'multisite-radar' ),
			'alert_rules'       => __( 'Alert rules', 'multisite-radar' ),
			'registry_status'   => __( 'Content types status', 'multisite-radar' ),
			'scanned_at_gmt'    => __( 'Analysed (UTC)', 'multisite-radar' ),
		];
	}

	/**
	 * @param string[] $requested Clés demandées.
	 * @return string[] Clés connues, dans l'ordre d'export ; toutes si aucune n'est reconnue.
	 */
	public static function select( array $requested ): array {
		$known = array_keys( self::all() );
		$keys  = array_values( array_intersect( $known, array_map( 'strval', $requested ) ) );
		return [] === $keys ? $known : $keys;
	}

	/**
	 * @param array    $item Site mis en forme par SitesQuery::summary().
	 * @param string[] $keys Colonnes à produire.
	 * @return array<string, mixed> Valeurs scalaires ou null, dans l'ordre de $keys.
	 */
	public static function row( array $item, array $keys ): array {
		$flat = [
			'status'      => self::status( (array) ( $item['status'] ?? [] ) ),
			'theme'       => (string) ( $item['theme']['stylesheet'] ?? '' ),
			'alert_rules' => implode( ',', (array) ( $item['alert_rules'] ?? [] ) ),
		];
		$row  = [];
		foreach ( $keys as $key ) {
			$row[ $key ] = array_key_exists( $key, $flat ) ? $flat[ $key ] : ( $item[ $key ] ?? null );
		}
		return $row;
	}

	private static function status( array $status ): string {
		$flags = [ ! empty( $status['public'] ) ? 'public' : 'private' ];
		foreach ( [ 'archived', 'spam', 'deleted' ] as $flag ) {
			if ( ! empty( $status[ $flag ] ) ) {
				$flags[] = $flag;
			}
		}
		return implode( ',', $flags );
	}
}
```

Créer `includes/Export/CsvWriter.php` :

```php
<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * CSV en UTF-8 avec BOM, conforme RFC 4180, écrit au fil de l'eau.
 */
final class CsvWriter {

	/**
	 * @var resource
	 */
	private $stream;

	/**
	 * @param resource $stream Flux ouvert en écriture.
	 */
	public function __construct( $stream ) {
		$this->stream = $stream;
	}

	/**
	 * @param string[] $labels En-têtes de colonnes.
	 */
	public function header( array $labels ): void {
		// BOM : les tableurs reconnaissent l'UTF-8.
		fwrite( $this->stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed export.
		$this->write( array_map( 'strval', $labels ) );
	}

	/**
	 * @param array<string, mixed> $row Valeurs scalaires ou null.
	 */
	public function row( array $row ): void {
		$this->write( array_map( [ self::class, 'cell' ], array_values( $row ) ) );
	}

	/**
	 * Un tableur exécuterait une cellule commençant par =, +, -, @, une tabulation ou un retour chariot :
	 * une apostrophe initiale la neutralise. Les nombres restent intacts.
	 *
	 * @param mixed $value Valeur scalaire ou null.
	 */
	public static function cell( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param string[] $fields Cellules déjà converties.
	 */
	private function write( array $fields ): void {
		// Échappement vide : RFC 4180 (guillemets doublés), et pas de valeur par défaut implicite, dépréciée en PHP 8.4.
		fputcsv( $this->stream, $fields, ',', '"', '' );
	}
}
```

Créer `includes/Export/JsonWriter.php` :

```php
<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Document JSON { "meta": …, "items": [ … ] } écrit au fil de l'eau, élément par élément.
 */
final class JsonWriter {

	/**
	 * @var resource
	 */
	private $stream;

	private bool $first = true;

	/**
	 * @param resource $stream Flux ouvert en écriture.
	 */
	public function __construct( $stream ) {
		$this->stream = $stream;
	}

	public function begin( array $meta ): void {
		$this->put( '{"meta":' . self::encode( $meta ) . ',"items":[' );
	}

	public function item( array $row ): void {
		$this->put( ( $this->first ? '' : ',' ) . self::encode( $row ) );
		$this->first = false;
	}

	public function end(): void {
		$this->put( ']}' );
	}

	private static function encode( array $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function put( string $chunk ): void {
		fwrite( $this->stream, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed export.
	}
}
```

Créer `includes/Export/ExportHandler.php` :

```php
<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Export des sites en CSV ou JSON par admin-post.php, avec nonce et capacité msradar_view.
 * Les mêmes filtres que GET /sites ; les lignes sont lues et écrites par tranches de 500.
 */
final class ExportHandler {

	public const ACTION    = 'msradar_export';
	public const RESOURCES = [ 'sites' ];
	public const FORMATS   = [ 'csv', 'json' ];

	private const FILTERS      = [ 'search', 'orderby', 'order', 'alert_level', 'status', 'registry_status', 'rule', 'theme', 'plugin', 'include' ];
	private const LIST_FILTERS = [ 'alert_level', 'status', 'registry_status', 'include' ];

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export this data.', 'multisite-radar' ), '', [ 'response' => 403 ] );
		}
		$params = $this->params( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) );
		if ( is_wp_error( $params ) ) {
			wp_die( esc_html( $params->get_error_message() ), '', [ 'response' => 400 ] );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $params['format'] ? 'text/csv' : 'application/json' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->filename( $params['resource'], $params['format'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$stream = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed export.
		if ( false === $stream ) {
			wp_die( esc_html__( 'The export could not be started.', 'multisite-radar' ), '', [ 'response' => 500 ] );
		}
		$this->write( $params, $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
		exit;
	}

	/**
	 * @param array $input Paramètres de la requête, déjà désinfectés.
	 * @return array{resource: string, format: string, fields: string[], filters: array}|WP_Error
	 */
	public function params( array $input ) {
		$resource = (string) ( $input['resource'] ?? 'sites' );
		if ( ! in_array( $resource, self::RESOURCES, true ) ) {
			return new WP_Error( 'msradar_unknown_resource', __( 'This data cannot be exported.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$format = (string) ( $input['format'] ?? 'csv' );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			return new WP_Error( 'msradar_unknown_format', __( 'Unknown export format.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$filters = [];
		foreach ( self::FILTERS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$value = in_array( $key, self::LIST_FILTERS, true ) ? self::to_list( $input[ $key ] ) : trim( (string) $input[ $key ] );
			if ( '' === $value || [] === $value ) {
				continue;
			}
			$filters[ $key ] = 'include' === $key ? array_map( 'intval', (array) $value ) : $value;
		}

		return [
			'resource' => $resource,
			'format'   => $format,
			'fields'   => SitesColumns::select( self::to_list( $input['fields'] ?? [] ) ),
			'filters'  => $filters,
		];
	}

	/**
	 * @param array    $params Résultat de params().
	 * @param resource $stream Flux de sortie.
	 * @return int Nombre de sites exportés.
	 */
	public function write( array $params, $stream ): int {
		$keys    = (array) $params['fields'];
		$columns = SitesColumns::all();

		if ( 'csv' === $params['format'] ) {
			$csv = new CsvWriter( $stream );
			$csv->header( array_map( static fn ( string $key ): string => $columns[ $key ], $keys ) );
			return $this->sites->each(
				(array) $params['filters'],
				static function ( array $item ) use ( $csv, $keys ): void {
					$csv->row( SitesColumns::row( $item, $keys ) );
				}
			);
		}

		$json = new JsonWriter( $stream );
		$json->begin(
			[
				'generated_gmt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'network'       => network_home_url( '/' ),
				'version'       => MSRADAR_VERSION,
				'resource'      => $params['resource'],
				'filters'       => (object) $params['filters'],
				'fields'        => $keys,
			]
		);
		$count = $this->sites->each(
			(array) $params['filters'],
			static function ( array $item ) use ( $json, $keys ): void {
				$json->item( SitesColumns::row( $item, $keys ) );
			}
		);
		$json->end();
		return $count;
	}

	public function filename( string $resource, string $format ): string {
		return 'multisite-radar-' . $resource . '-' . gmdate( 'Ymd-His' ) . '.' . $format;
	}

	/**
	 * @param mixed $value Liste séparée par des virgules ou tableau.
	 * @return string[]
	 */
	private static function to_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $items ) ), 'strlen' ) );
	}
}
```

Si PHPCS signale `$_GET` dans `handle()` (`WordPress.Security.ValidatedSanitizedInput` ou `NonceVerification`) alors que `check_admin_referer()` et `map_deep( …, 'sanitize_text_field' )` sont en place, ajouter sur la ligne :

```php
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by map_deep() with sanitize_text_field; nonce checked above.
```

N'utiliser que les codes réellement signalés.

**`includes/Plugin.php`** :
- ajouter `use MultisiteRadar\Export\ExportHandler;` et la propriété `private ?ExportHandler $export = null;` ;
- ajouter la méthode :

```php
	public function export(): ExportHandler {
		return $this->export ??= new ExportHandler( $this->sites_query() );
	}
```

Dans `boot()`, après `$this->legacy()->register();`, ajouter :

```php
		if ( is_admin() ) {
			$this->export()->register();
		}
```

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

Le test CSV fixe la sortie exacte :
- un site archivé reste public, d'où `"public,archived"`, entre guillemets parce que la valeur contient une virgule ;
- `fputcsv` met aussi entre guillemets un en-tête qui contient une espace (`"Alert rules"`) ;
- le nom neutralisé double ses guillemets internes.

Si la sortie diffère, corriger le code, jamais l'attente : seule l'apostrophe initiale est ajoutée.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: streamed CSV and JSON exports of the sites list with formula neutralisation"
```

---
## Partie B — Module « menu des sites »

### Task 9: Cache de la liste des sites, shortcode et alias 1.x

**Files:**
- Create: `includes/SitesMenu/SitesListCache.php`, `includes/SitesMenu/Renderer.php`, `includes/SitesMenu/Shortcode.php`, `includes/SitesMenu/Module.php`, `includes/SitesMenu/legacy-functions.php`
- Modify: `includes/Plugin.php`, `uninstall.php`, `phpcs.xml.dist`
- Test: `tests/php/SitesMenu/SitesListCacheTest.php`, `tests/php/SitesMenu/RendererTest.php`, `tests/php/SitesMenu/ShortcodeTest.php`

**Interfaces:**
- Consumes :
  - `Settings::get( 'sites_menu.enabled' )` ;
  - `LegacyMigration::ALIASES` (option réseau posée par la migration 1.x de M1).
- Produces :
  - `SitesListCache::PREFIX = 'msradar_sites_list_'`, `SitesListCache::name( int $network_id ): string` ;
  - `SitesListCache::get(): array` (réseau courant, transient d'un jour) et `SitesListCache::build( int $network_id ): array`. Chaque site vaut `array{id: int, name: string, url: string, registered: string}` : ce sont les sites publics, non archivés, non supprimés et non spam, par ID croissant ;
  - `SitesListCache::flush( int $network_id ): void`, `SitesListCache::register(): void` ;
  - `Renderer::WRAPPERS = [ 'ul', 'ol' ]`, `Renderer::ORDER_BY = [ 'name', 'id', 'registered' ]` ;
  - `Renderer::select( array $sites, int[] $include, int[] $exclude, string $order_by, string $order ): array` ;
  - `Renderer::label( array $site ): string` et `Renderer::render( array $sites, string $wrapper, string $attributes ): string` ;
  - `Shortcode::TAG = 'msradar_sites'`, `Shortcode::LEGACY_TAG = 'network_sites_menu'` ;
  - `Shortcode::register( bool $legacy )` et `Shortcode::markup( string $wrapper, string $class, string $order_by = 'name' ): string` ;
  - `Module::register()`, `Module::init()`, `Module::enabled()`, `Module::legacy()`, `Module::shortcode()` ;
  - la fonction globale `rdc_network_sites_menu( $wrapper = 'ul', $class = 'network-sites-menu' )`, qui affiche la liste ;
  - `Plugin::sites_list_cache()` et `Plugin::sites_menu()`.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/SitesMenu/SitesListCacheTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\SitesListCache;
use MultisiteRadar\Tests\TestCase;

final class SitesListCacheTest extends TestCase {

	public function test_builds_the_public_sites_of_the_network_with_their_name_and_home_url(): void {
		$public   = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$archived = self::factory()->blog->create();
		update_blog_status( $archived, 'archived', '1' );
		$private = self::factory()->blog->create( [ 'public' => 0 ] );
		$other   = self::factory()->blog->create( [ 'network_id' => self::factory()->network->create() ] );

		$sites = ( new SitesListCache() )->build( get_current_network_id() );
		$ids   = wp_list_pluck( $sites, 'id' );

		$this->assertContains( $public, $ids );
		$this->assertNotContains( $archived, $ids );
		$this->assertNotContains( $private, $ids );
		$this->assertNotContains( $other, $ids );
		$entry = $sites[ array_search( $public, $ids, true ) ];
		$this->assertSame( 'Blog RH', $entry['name'] );
		$this->assertSame( get_blog_option( $public, 'home' ), $entry['url'] );
		$this->assertSame( [ 'id', 'name', 'url', 'registered' ], array_keys( $entry ) );
	}

	public function test_a_site_whose_tables_are_missing_is_skipped(): void {
		global $wpdb;
		$healthy = self::factory()->blog->create( [ 'title' => 'Healthy' ] );
		$wpdb->insert(
			$wpdb->blogs,
			[
				'blog_id'      => 99999,
				'site_id'      => get_current_network_id(),
				'domain'       => 'ghost.test',
				'path'         => '/',
				'registered'   => '2026-01-01 00:00:00',
				'last_updated' => '2026-01-01 00:00:00',
				'public'       => 1,
				'archived'     => 0,
				'mature'       => 0,
				'spam'         => 0,
				'deleted'      => 0,
				'lang_id'      => 0,
			]
		);

		$ids = wp_list_pluck( ( new SitesListCache() )->build( get_current_network_id() ), 'id' );

		$this->assertContains( $healthy, $ids );
		$this->assertNotContains( 99999, $ids );
	}

	public function test_get_is_cached_per_network_and_site_changes_flush_it(): void {
		$cache   = $this->plugin()->sites_list_cache();
		$network = get_current_network_id();
		$site    = self::factory()->blog->create( [ 'title' => 'Before' ] );
		$cache->flush( $network );

		$this->assertContains( 'Before', wp_list_pluck( $cache->get(), 'name' ) );
		$this->assertIsArray( get_site_transient( SitesListCache::name( $network ) ) );

		update_blog_option( $site, 'blogname', 'After' );
		$this->assertFalse( get_site_transient( SitesListCache::name( $network ) ), 'Renaming a site flushes the list.' );
		$this->assertContains( 'After', wp_list_pluck( $cache->get(), 'name' ) );

		update_blog_status( $site, 'archived', '1' );
		$this->assertNotContains( $site, wp_list_pluck( $cache->get(), 'id' ), 'Archiving a site removes it at once.' );

		$new = self::factory()->blog->create( [ 'title' => 'Newcomer' ] );
		$this->assertContains( $new, wp_list_pluck( $cache->get(), 'id' ) );
	}
}
```

Créer `tests/php/SitesMenu/RendererTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\Renderer;
use MultisiteRadar\Tests\TestCase;

final class RendererTest extends TestCase {

	private const SITES = [
		[
			'id'         => 3,
			'name'       => 'Émile',
			'url'        => 'https://c.test/',
			'registered' => '2026-01-03 00:00:00',
		],
		[
			'id'         => 1,
			'name'       => 'alpha',
			'url'        => 'https://a.test/',
			'registered' => '2026-01-02 00:00:00',
		],
		[
			'id'         => 2,
			'name'       => '',
			'url'        => 'https://b.test/',
			'registered' => '2026-01-01 00:00:00',
		],
	];

	private function ids( array $sites ): array {
		return wp_list_pluck( $sites, 'id' );
	}

	public function test_select_filters_and_sorts_ignoring_case_and_accents(): void {
		$this->assertSame( [ 1, 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [], 'name', 'asc' ) ), 'alpha, Émile, then "Site #2".' );
		$this->assertSame( [ 3, 2, 1 ], $this->ids( Renderer::select( self::SITES, [], [], 'id', 'desc' ) ) );
		$this->assertSame( [ 2, 1, 3 ], $this->ids( Renderer::select( self::SITES, [], [], 'registered', 'asc' ) ) );
		$this->assertSame( [ 1, 3 ], $this->ids( Renderer::select( self::SITES, [ 3, 1 ], [], 'name', 'asc' ) ) );
		$this->assertSame( [ 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [ 1 ], 'name', 'asc' ) ) );
		$this->assertSame( [ 1, 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [], 'bogus', 'asc' ) ), 'Unknown order falls back to name.' );
	}

	public function test_render_escapes_marks_the_current_site_and_restricts_the_wrapper(): void {
		$sites = [
			[
				'id'         => get_current_blog_id(),
				'name'       => '<b>Main</b>',
				'url'        => 'javascript:alert(1)',
				'registered' => '',
			],
			[
				'id'         => 9,
				'name'       => 'Other',
				'url'        => 'https://o.test/',
				'registered' => '',
			],
		];

		$html = Renderer::render( $sites, 'script', 'class="x"' );

		$this->assertStringStartsWith( '<ul class="x">', $html );
		$this->assertStringEndsWith( '</ul>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Main&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( '<a href="https://o.test/">Other</a>', $html );
		$this->assertStringStartsWith( '<ol class="x">', Renderer::render( $sites, 'ol', 'class="x"' ) );
	}
}
```

Créer `tests/php/SitesMenu/ShortcodeTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\SitesMenu\Shortcode;
use MultisiteRadar\Tests\TestCase;

final class ShortcodeTest extends TestCase {

	public function tear_down(): void {
		remove_shortcode( Shortcode::TAG );
		remove_shortcode( Shortcode::LEGACY_TAG );
		parent::tear_down();
	}

	private function enable(): void {
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
	}

	public function test_nothing_is_registered_while_the_module_is_disabled(): void {
		remove_shortcode( Shortcode::TAG );

		$this->plugin()->sites_menu()->init();

		$this->assertFalse( shortcode_exists( Shortcode::TAG ) );
	}

	public function test_the_shortcode_lists_public_sites_with_a_safe_wrapper(): void {
		self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->enable();

		$this->plugin()->sites_menu()->init();
		$html = do_shortcode( '[msradar_sites class="menu-a" wrapper="script"]' );

		$this->assertStringStartsWith( '<ul class="menu-a">', $html );
		$this->assertStringContainsString( '>Blog RH</a>', $html );
		$this->assertFalse( shortcode_exists( Shortcode::LEGACY_TAG ), 'No 1.x alias without a migrated 1.x install.' );
	}

	public function test_the_1x_aliases_exist_after_a_migration(): void {
		self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->enable();
		update_site_option( LegacyMigration::ALIASES, 1 );

		$this->plugin()->sites_menu()->init();

		$this->assertStringStartsWith( '<ul class="network-sites-menu">', do_shortcode( '[network_sites_menu]' ) );
		$this->assertTrue( function_exists( 'rdc_network_sites_menu' ) );
		ob_start();
		rdc_network_sites_menu( 'ol', 'legacy' );
		$this->assertStringStartsWith( '<ol class="legacy">', (string) ob_get_clean() );
	}

	public function test_a_1x_shortcode_still_registered_by_the_old_plugin_is_left_alone(): void {
		add_shortcode(
			Shortcode::LEGACY_TAG,
			static function (): string {
				return 'from 1.x';
			}
		);
		$this->enable();
		update_site_option( LegacyMigration::ALIASES, 1 );

		$this->plugin()->sites_menu()->init();

		$this->assertSame( 'from 1.x', do_shortcode( '[network_sites_menu]' ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'SitesListCacheTest|RendererTest|ShortcodeTest'`
Expected: FAIL (`Class "MultisiteRadar\SitesMenu\SitesListCache" not found`).

- [ ] **Step 3: Implémenter**

Créer `includes/SitesMenu/SitesListCache.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Liste des sites publics du réseau (identifiant, nom, adresse, inscription), sans limite de nombre.
 *
 * Stockée dans un transient réseau propre à chaque réseau. Elle est vidée quand un site est créé, supprimé ou modifié
 * (archivage, spam, visibilité, chemin), renommé, ou change d'adresse. La reconstruction lit wp_blogs puis le nom
 * et l'adresse de chaque site, par tranches de 100 sites en une requête UNION ALL, sans switch_to_blog().
 */
final class SitesListCache {

	public const PREFIX = 'msradar_sites_list_';

	private const CHUNK = 100;

	public static function name( int $network_id ): string {
		return self::PREFIX . $network_id;
	}

	public function register(): void {
		add_action( 'wp_initialize_site', [ $this, 'on_site_changed' ], 20 );
		add_action( 'wp_delete_site', [ $this, 'on_site_changed' ] );
		add_action( 'wp_update_site', [ $this, 'on_site_changed' ] );
		add_action( 'update_option_blogname', [ $this, 'flush_current' ] );
		add_action( 'update_option_home', [ $this, 'flush_current' ] );
	}

	/**
	 * @return array<int, array{id: int, name: string, url: string, registered: string}>
	 */
	public function get(): array {
		$network_id = get_current_network_id();
		$cached     = get_site_transient( self::name( $network_id ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$sites = $this->build( $network_id );
		set_site_transient( self::name( $network_id ), $sites, DAY_IN_SECONDS );
		return $sites;
	}

	/**
	 * @return array<int, array{id: int, name: string, url: string, registered: string}>
	 */
	public function build( int $network_id ): array {
		global $wpdb;
		$blogs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blog_id, domain, path, registered FROM %i WHERE site_id = %d AND public = 1 AND archived = '0' AND spam = 0 AND deleted = 0 ORDER BY blog_id ASC",
				$wpdb->blogs,
				$network_id
			),
			ARRAY_A
		);

		$sites = [];
		foreach ( array_chunk( $blogs, self::CHUNK ) as $chunk ) {
			$options = $this->read_options( $chunk );
			foreach ( $chunk as $blog ) {
				$id = (int) $blog['blog_id'];
				if ( ! isset( $options[ $id ] ) ) {
					continue; // Tables du site absentes : réseau abîmé, le site est ignoré.
				}
				$home    = (string) ( $options[ $id ]['home'] ?? '' );
				$sites[] = [
					'id'         => $id,
					'name'       => (string) ( $options[ $id ]['blogname'] ?? '' ),
					'url'        => '' !== $home ? $home : set_url_scheme( 'http://' . $blog['domain'] . $blog['path'] ),
					'registered' => (string) $blog['registered'],
				];
			}
		}
		return $sites;
	}

	public function flush( int $network_id ): void {
		$name = self::name( $network_id );
		if ( get_current_network_id() === $network_id ) {
			delete_site_transient( $name );
			return;
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, 'site-transient' );
			return;
		}
		delete_network_option( $network_id, '_site_transient_' . $name );
		delete_network_option( $network_id, '_site_transient_timeout_' . $name );
	}

	public function on_site_changed( WP_Site $site ): void {
		$this->flush( (int) $site->network_id );
	}

	public function flush_current(): void {
		$this->flush( get_current_network_id() );
	}

	/**
	 * Nom et adresse de chaque site, en une requête par tranche.
	 * Si une table manque, la requête groupée échoue : la tranche est relue site par site, pour ne perdre que le site abîmé.
	 *
	 * @param array[] $blogs Lignes de wp_blogs.
	 * @return array<int, array<string, string>> blog_id => nom d'option => valeur.
	 */
	private function read_options( array $blogs ): array {
		global $wpdb;
		$select = 'SELECT %d AS blog_id, option_name, option_value FROM %i WHERE option_name IN (%s, %s)';
		$parts  = [];
		$params = [];
		foreach ( $blogs as $blog ) {
			$id      = (int) $blog['blog_id'];
			$parts[] = $select;
			array_push( $params, $id, $wpdb->get_blog_prefix( $id ) . 'options', 'blogname', 'home' );
		}

		$suppress = $wpdb->suppress_errors( true );
		try {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $parts only holds the fixed SELECT above, with placeholders.
			if ( '' !== $wpdb->last_error ) {
				$rows = [];
				foreach ( $blogs as $blog ) {
					$id     = (int) $blog['blog_id'];
					$single = $wpdb->get_results( $wpdb->prepare( $select, $id, $wpdb->get_blog_prefix( $id ) . 'options', 'blogname', 'home' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SELECT with placeholders.
					if ( '' === $wpdb->last_error ) {
						$rows = array_merge( $rows, (array) $single );
					}
				}
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$options = [];
		foreach ( $rows as $row ) {
			$options[ (int) $row['blog_id'] ][ (string) $row['option_name'] ] = (string) $row['option_value'];
		}
		return $options;
	}
}
```

Si PHPCS signale d'autres codes sur les deux lignes `phpcs:ignore` (par exemple `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber`), les ajouter à la liste du commentaire.

Créer `includes/SitesMenu/Renderer.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Choix, tri et rendu HTML de la liste des sites (shortcode, alias 1.x, bloc).
 */
final class Renderer {

	public const WRAPPERS = [ 'ul', 'ol' ];
	public const ORDER_BY = [ 'name', 'id', 'registered' ];

	/**
	 * @param array[] $sites    Liste de SitesListCache::get().
	 * @param int[]   $include  Si non vide, seulement ces sites.
	 * @param int[]   $exclude  Sites à retirer.
	 * @param string  $order_by name (sans casse ni accents), id ou registered.
	 * @param string  $order    asc ou desc.
	 * @return array[]
	 */
	public static function select( array $sites, array $include, array $exclude, string $order_by, string $order ): array {
		$include  = array_map( 'intval', $include );
		$exclude  = array_map( 'intval', $exclude );
		$order_by = in_array( $order_by, self::ORDER_BY, true ) ? $order_by : 'name';
		$sites    = array_values(
			array_filter(
				$sites,
				static function ( array $site ) use ( $include, $exclude ): bool {
					$id = (int) $site['id'];
					return ( [] === $include || in_array( $id, $include, true ) ) && ! in_array( $id, $exclude, true );
				}
			)
		);
		usort(
			$sites,
			static function ( array $a, array $b ) use ( $order_by ): int {
				if ( 'id' === $order_by ) {
					return (int) $a['id'] <=> (int) $b['id'];
				}
				if ( 'registered' === $order_by ) {
					return [ (string) $a['registered'], (int) $a['id'] ] <=> [ (string) $b['registered'], (int) $b['id'] ];
				}
				return [ self::sort_key( self::label( $a ) ), (int) $a['id'] ] <=> [ self::sort_key( self::label( $b ) ), (int) $b['id'] ];
			}
		);
		return 'desc' === $order ? array_reverse( $sites ) : $sites;
	}

	public static function label( array $site ): string {
		$name = trim( (string) $site['name'] );
		if ( '' !== $name ) {
			return $name;
		}
		/* translators: %d: site ID. */
		return sprintf( __( 'Site #%d', 'multisite-radar' ), (int) $site['id'] );
	}

	/**
	 * @param array[] $sites      Sites déjà choisis et triés.
	 * @param string  $wrapper    ul ou ol ; toute autre valeur donne ul.
	 * @param string  $attributes Attributs HTML du conteneur, déjà échappés.
	 */
	public static function render( array $sites, string $wrapper, string $attributes ): string {
		$tag     = in_array( $wrapper, self::WRAPPERS, true ) ? $wrapper : 'ul';
		$current = get_current_blog_id();
		$items   = '';
		foreach ( $sites as $site ) {
			$is_current = $current === (int) $site['id'];
			$items     .= sprintf(
				'<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				esc_attr( 'msradar-sites__item' . ( $is_current ? ' is-current' : '' ) ),
				esc_url( (string) $site['url'] ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( self::label( $site ) )
			);
		}
		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, $attributes, $items );
	}

	private static function sort_key( string $label ): string {
		return remove_accents( function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label ) );
	}
}
```

Créer `includes/SitesMenu/Shortcode.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

defined( 'ABSPATH' ) || exit;

/**
 * [msradar_sites wrapper="ul" class="…"] et l'alias 1.x [network_sites_menu].
 */
final class Shortcode {

	public const TAG        = 'msradar_sites';
	public const LEGACY_TAG = 'network_sites_menu';

	private SitesListCache $cache;

	public function __construct( SitesListCache $cache ) {
		$this->cache = $cache;
	}

	public function register( bool $legacy ): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		// Le MU-plugin 1.x encore chargé garde la main sur son shortcode.
		if ( $legacy && ! shortcode_exists( self::LEGACY_TAG ) ) {
			add_shortcode( self::LEGACY_TAG, [ $this, 'render_legacy' ] );
		}
	}

	/**
	 * @param mixed $atts Attributs du shortcode.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'wrapper' => 'ul',
				'class'   => 'msradar-sites',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);
		return $this->markup( (string) $atts['wrapper'], (string) $atts['class'] );
	}

	/**
	 * Alias 1.x : mêmes attributs, classe et ordre (par identifiant) de la 1.x.
	 *
	 * @param mixed $atts Attributs du shortcode.
	 */
	public function render_legacy( $atts ): string {
		$atts = shortcode_atts(
			[
				'wrapper' => 'ul',
				'class'   => 'network-sites-menu',
			],
			is_array( $atts ) ? $atts : [],
			self::LEGACY_TAG
		);
		return $this->markup( (string) $atts['wrapper'], (string) $atts['class'], 'id' );
	}

	public function markup( string $wrapper, string $class, string $order_by = 'name' ): string {
		$sites = Renderer::select( $this->cache->get(), [], [], $order_by, 'asc' );
		if ( [] === $sites ) {
			return '';
		}
		return Renderer::render( $sites, $wrapper, 'class="' . esc_attr( $class ) . '"' );
	}
}
```

Créer `includes/SitesMenu/legacy-functions.php` :

```php
<?php
/**
 * Fonction publique de la 1.x, chargée seulement si une installation 1.x a été migrée (thèmes qui l'appellent).
 *
 * @package MultisiteRadar
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rdc_network_sites_menu' ) ) {
	/**
	 * Affiche la liste des sites du réseau (API 1.x).
	 *
	 * @param string $wrapper ul ou ol.
	 * @param string $class   Classe CSS de la liste.
	 */
	function rdc_network_sites_menu( $wrapper = 'ul', $class = 'network-sites-menu' ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API kept for migrated sites.
		echo MultisiteRadar\Plugin::instance()->sites_menu()->shortcode()->markup( (string) $wrapper, (string) $class, 'id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Renderer::render().
	}
}
```

Créer `includes/SitesMenu/Module.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Module optionnel « menu des sites » (réglage sites_menu.enabled).
 * L'invalidation du cache est toujours branchée (elle ne fait que supprimer un transient) ; le reste ne l'est que si
 * le module est actif. Les alias 1.x ne le sont que si une installation 1.x a été migrée.
 */
final class Module {

	private Settings $settings;
	private SitesListCache $cache;
	private ?Shortcode $shortcode = null;

	public function __construct( Settings $settings, SitesListCache $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	public function register(): void {
		$this->cache->register();
		add_action( 'init', [ $this, 'init' ] );
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'sites_menu.enabled', false );
	}

	public function legacy(): bool {
		return false !== get_site_option( LegacyMigration::ALIASES, false );
	}

	public function init(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$legacy = $this->legacy();
		$this->shortcode()->register( $legacy );
		if ( $legacy ) {
			require_once __DIR__ . '/legacy-functions.php';
		}
	}

	public function shortcode(): Shortcode {
		return $this->shortcode ??= new Shortcode( $this->cache );
	}
}
```

**`includes/Plugin.php`** :
- ajouter `use MultisiteRadar\SitesMenu\Module as SitesMenuModule;` et `use MultisiteRadar\SitesMenu\SitesListCache;` ;
- ajouter les propriétés `private ?SitesListCache $sites_list_cache = null;` et `private ?SitesMenuModule $sites_menu = null;` ;
- ajouter les méthodes :

```php
	public function sites_list_cache(): SitesListCache {
		return $this->sites_list_cache ??= new SitesListCache();
	}

	public function sites_menu(): SitesMenuModule {
		return $this->sites_menu ??= new SitesMenuModule( $this->settings(), $this->sites_list_cache() );
	}
```

Dans `boot()`, ajouter `$this->sites_menu()->register();` après `$this->invalidation()->register();`.

**`uninstall.php`** — dans la boucle `foreach ( $msradar_network_ids as $msradar_network_id )`, ajouter après la boucle des options :

```php
	// Cache du module « menu des sites », propre à chaque réseau.
	delete_network_option( (int) $msradar_network_id, '_site_transient_msradar_sites_list_' . $msradar_network_id );
	delete_network_option( (int) $msradar_network_id, '_site_transient_timeout_msradar_sites_list_' . $msradar_network_id );
	wp_cache_delete( 'msradar_sites_list_' . $msradar_network_id, 'site-transient' );
```

**`phpcs.xml.dist`** — dans la règle `WordPress.DB.DirectDatabaseQuery`, ajouter :

```xml
		<exclude-pattern>/includes/SitesMenu/SitesListCache\.php</exclude-pattern>
```

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes uninstall.php phpcs.xml.dist tests/php
git commit -m "feat: cached network sites list with the msradar_sites shortcode and 1.x aliases"
```

---

### Task 10: Metabox « Network sites » dans Apparence › Menus

**Files:**
- Create: `includes/SitesMenu/NavMenu.php`
- Modify: `includes/SitesMenu/Module.php`
- Test: `tests/php/SitesMenu/NavMenuTest.php`, `tests/php/SitesMenu/NavMenuAjaxTest.php`

**Interfaces:**
- Consumes :
  - `SitesListCache::get()` et `Renderer::select()` / `Renderer::label()` (tâche 9) ;
  - `LegacyMigration::MENU_TYPE = 'msradar_site'` (M1).
- Produces :
  - `NavMenu::TYPE = 'msradar_site'` ;
  - `NavMenu::register_items()` : hydratation des éléments et note dans l'éditeur ; toujours active ;
  - `NavMenu::register_editor()` : metabox et ajout AJAX ; active seulement avec le module ;
  - `NavMenu::hydrate( $item )`, `NavMenu::render_metabox()`, `NavMenu::ajax_add_menu_item()` ;
  - `Module::nav_menu(): NavMenu`.
- **Format des éléments :** `_menu_item_type` = `_menu_item_object` = `msradar_site` et `_menu_item_object_id` = ID du site. C'est le format des éléments 1.x convertis par M1. L'URL n'est pas stockée : elle est calculée au rendu à partir du site. Le titre est personnalisable ; vide, il prend le nom du site.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/SitesMenu/NavMenuTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\NavMenu;
use MultisiteRadar\Tests\TestCase;

final class NavMenuTest extends TestCase {

	private function add_item( int $menu, int $site_id, string $title, int $item = 0 ): int {
		return (int) wp_update_nav_menu_item(
			$menu,
			$item,
			[
				'menu-item-type'      => NavMenu::TYPE,
				'menu-item-object'    => NavMenu::TYPE,
				'menu-item-object-id' => $site_id,
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
			]
		);
	}

	public function test_items_follow_their_site_keep_a_custom_title_and_are_invalid_once_the_site_is_not_public(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$menu = wp_create_nav_menu( 'Main' );
		$item = $this->add_item( $menu, $site, '' );

		$setup = wp_setup_nav_menu_item( get_post( $item ) );
		$this->assertSame( get_blog_option( $site, 'home' ), $setup->url );
		$this->assertSame( 'Blog RH', $setup->title );
		$this->assertSame( 'Network site', $setup->type_label );
		$this->assertEmpty( $setup->_invalid ?? false );

		$this->add_item( $menu, $site, 'Human resources', $item );
		$this->assertSame( 'Human resources', wp_setup_nav_menu_item( get_post( $item ) )->title );

		update_blog_status( $site, 'archived', '1' );
		$this->assertTrue( wp_setup_nav_menu_item( get_post( $item ) )->_invalid );
	}

	public function test_a_migrated_1x_item_pointing_to_a_deleted_site_is_invalid(): void {
		$menu = wp_create_nav_menu( 'Legacy' );
		$item = $this->add_item( $menu, 999999, 'Gone' );

		$this->assertTrue( wp_setup_nav_menu_item( get_post( $item ) )->_invalid );
	}

	public function test_the_metabox_lists_public_sites_as_menu_item_checkboxes(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog <RH>' ] );

		ob_start();
		$this->plugin()->sites_menu()->nav_menu()->render_metabox();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="submit-posttype-msradar-sites"', $html );
		$this->assertStringContainsString( 'class="tabs-panel tabs-panel-active"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" class="menu-item-checkbox" name="menu-item\[-\d+\]\[menu-item-object-id\]" value="' . $site . '" \/> Blog &lt;RH&gt;/', $html );
		$this->assertStringContainsString( 'value="msradar_site"', $html );
	}
}
```

Créer `tests/php/SitesMenu/NavMenuAjaxTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\Plugin;
use MultisiteRadar\SitesMenu\NavMenu;
use WPAjaxDieContinueException;

/**
 * Ajout d'un élément depuis la metabox (admin-ajax.php?action=add-menu-item).
 */
final class NavMenuAjaxTest extends \WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->sites_menu()->nav_menu()->register_editor();
	}

	public function tear_down(): void {
		remove_action( 'wp_ajax_add-menu-item', [ Plugin::instance()->sites_menu()->nav_menu(), 'ajax_add_menu_item' ], 0 );
		parent::tear_down();
	}

	public function test_adds_network_site_items_without_reaching_the_core_handler(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->_setRole( 'administrator' );
		$_POST['menu-settings-column-nonce'] = wp_create_nonce( 'add-menu_item' );
		$_POST['menu']                       = 0;
		$_POST['menu-item']                  = [
			-1 => [
				'menu-item-object-id' => (string) $site,
				'menu-item-object'    => NavMenu::TYPE,
				'menu-item-type'      => NavMenu::TYPE,
				'menu-item-title'     => 'Blog RH',
				'menu-item-url'       => 'https://ignored.test/',
			],
		];

		try {
			$this->_handleAjax( 'add-menu-item' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		// Le gestionnaire du cœur aurait émis des avertissements PHP (variable non définie), que PHPUnit transforme en échec.
		$this->assertStringContainsString( 'Blog RH', $this->_last_response );
		$this->assertStringContainsString( 'Network site', $this->_last_response );
		$items = get_posts(
			[
				'post_type'   => 'nav_menu_item',
				'post_status' => 'draft',
				'numberposts' => -1,
			]
		);
		$this->assertCount( 1, $items );
		$this->assertSame( NavMenu::TYPE, get_post_meta( $items[0]->ID, '_menu_item_type', true ) );
		$this->assertSame( (string) $site, (string) get_post_meta( $items[0]->ID, '_menu_item_object_id', true ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'NavMenuTest|NavMenuAjaxTest'`
Expected: FAIL (`Call to undefined method …nav_menu()`).

- [ ] **Step 3: Implémenter**

Créer `includes/SitesMenu/NavMenu.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Éléments de menu « Network site » (type msradar_site) : metabox dans Apparence › Menus, URL calculée à partir du site
 * (verrouillée), titre personnalisable, élément invalide (masqué aux visiteurs) quand le site n'est plus public.
 */
final class NavMenu {

	public const TYPE = LegacyMigration::MENU_TYPE;

	private SitesListCache $cache;

	public function __construct( SitesListCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Toujours actif : les éléments existants restent justes même si le module est désactivé ensuite.
	 */
	public function register_items(): void {
		add_filter( 'wp_setup_nav_menu_item', [ $this, 'hydrate' ] );
		add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'render_item_note' ], 10, 2 );
	}

	/**
	 * Seulement quand le module est actif.
	 */
	public function register_editor(): void {
		add_action( 'load-nav-menus.php', [ $this, 'add_metabox' ] );
		// Avant le gestionnaire du cœur, branché en priorité 1.
		add_action( 'wp_ajax_add-menu-item', [ $this, 'ajax_add_menu_item' ], 0 );
	}

	public function add_metabox(): void {
		add_meta_box( 'msradar-sites', __( 'Network sites', 'multisite-radar' ), [ $this, 'render_metabox' ], 'nav-menus', 'side', 'default' );
	}

	/**
	 * Même balisage que les metabox du cœur : nav-menu.js ajoute les éléments cochés de « .tabs-panel-active .categorychecklist »
	 * avec le bouton dont l'identifiant vaut « submit-<id du conteneur> ».
	 */
	public function render_metabox(): void {
		$sites = Renderer::select( $this->cache->get(), [], [], 'name', 'asc' );
		echo '<div id="posttype-msradar-sites" class="posttypediv"><div id="tabs-panel-msradar-sites" class="tabs-panel tabs-panel-active">';
		if ( [] === $sites ) {
			echo '<p>' . esc_html__( 'No public site in this network.', 'multisite-radar' ) . '</p>';
		} else {
			echo '<ul id="msradar-sites-checklist" class="categorychecklist form-no-clear">';
			$index = 0;
			foreach ( $sites as $site ) {
				--$index;
				$label = Renderer::label( $site );
				printf(
					'<li><label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="%1$s[menu-item-object-id]" value="%2$d" /> %3$s</label>'
					. '<input type="hidden" class="menu-item-type" name="%1$s[menu-item-type]" value="%4$s" />'
					. '<input type="hidden" class="menu-item-object" name="%1$s[menu-item-object]" value="%4$s" />'
					. '<input type="hidden" class="menu-item-title" name="%1$s[menu-item-title]" value="%5$s" />'
					. '<input type="hidden" class="menu-item-url" name="%1$s[menu-item-url]" value="%6$s" /></li>',
					esc_attr( 'menu-item[' . $index . ']' ),
					(int) $site['id'],
					esc_html( $label ),
					esc_attr( self::TYPE ),
					esc_attr( $label ),
					esc_url( (string) $site['url'] )
				);
			}
			echo '</ul>';
		}
		echo '</div>';
		printf(
			'<p class="button-controls wp-clearfix"><span class="add-to-menu"><input type="submit"%1$s class="button submit-add-to-menu right" value="%2$s" name="add-msradar-sites-menu-item" id="submit-posttype-msradar-sites" /><span class="spinner"></span></span></p>',
			disabled( [] === $sites, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
			esc_attr__( 'Add to Menu', 'multisite-radar' )
		);
		echo '</div>';
	}

	/**
	 * @param mixed $item Élément préparé par wp_setup_nav_menu_item().
	 * @return mixed
	 */
	public function hydrate( $item ) {
		if ( ! is_object( $item ) || self::TYPE !== ( $item->type ?? '' ) ) {
			return $item;
		}
		$item->type_label = __( 'Network site', 'multisite-radar' );
		$site             = $this->site( (int) ( $item->object_id ?? 0 ) );
		if ( null === $site ) {
			$item->_invalid = true;
			return $item;
		}
		$item->url = (string) $site['url'];
		if ( '' === trim( (string) ( $item->title ?? '' ) ) ) {
			$item->title = Renderer::label( $site );
		}
		return $item;
	}

	/**
	 * @param mixed $item_id Identifiant de l'élément.
	 * @param mixed $item    Élément de menu.
	 */
	public function render_item_note( $item_id, $item ): void {
		if ( ! is_object( $item ) || self::TYPE !== ( $item->type ?? '' ) ) {
			return;
		}
		$text = ! empty( $item->_invalid )
			? __( 'This network site is no longer public: the item is hidden from visitors.', 'multisite-radar' )
			: __( 'Links to a network site: the address follows the site and cannot be edited; the label can.', 'multisite-radar' );
		printf( '<p class="description description-wide">%s</p>', esc_html( $text ) );
	}

	/**
	 * Ajout depuis la metabox. Le gestionnaire du cœur (wp_ajax_add_menu_item) ne connaît que les types post_type,
	 * post_type_archive et taxonomy : avec un autre type, il lit une variable non définie et émet des avertissements PHP.
	 * Les requêtes qui ne contiennent que nos éléments sont traitées ici, avec la même réponse ; les autres lui sont laissées.
	 */
	public function ajax_add_menu_item(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked below, once the request is known to be ours.
		$items = isset( $_POST['menu-item'] ) && is_array( $_POST['menu-item'] ) ? wp_unslash( $_POST['menu-item'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$menu  = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		// phpcs:enable
		if ( [] === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || self::TYPE !== ( $item['menu-item-type'] ?? '' ) ) {
				return;
			}
		}

		check_ajax_referer( 'add-menu_item', 'menu-settings-column-nonce' );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( -1 );
		}
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$data = [];
		foreach ( $items as $item ) {
			$site = $this->site( absint( $item['menu-item-object-id'] ?? 0 ) );
			if ( null === $site ) {
				continue;
			}
			$data[] = [
				'menu-item-object-id' => (int) $site['id'],
				'menu-item-object'    => self::TYPE,
				'menu-item-type'      => self::TYPE,
				'menu-item-title'     => sanitize_text_field( (string) ( $item['menu-item-title'] ?? Renderer::label( $site ) ) ),
				'menu-item-url'       => esc_url_raw( (string) $site['url'] ),
			];
		}

		$item_ids = wp_save_nav_menu_items( 0, $data );
		if ( is_wp_error( $item_ids ) ) {
			wp_die( 0 );
		}
		$menu_items = [];
		foreach ( (array) $item_ids as $menu_item_id ) {
			$menu_object = get_post( $menu_item_id );
			if ( ! empty( $menu_object->ID ) ) {
				$menu_object        = wp_setup_nav_menu_item( $menu_object );
				$menu_object->label = $menu_object->title;
				$menu_items[]       = $menu_object;
			}
		}

		$walker_class_name = apply_filters( 'wp_edit_nav_menu_walker', 'Walker_Nav_Menu_Edit', $menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as core does.
		if ( ! class_exists( $walker_class_name ) ) {
			wp_die( 0 );
		}
		if ( [] !== $menu_items ) {
			echo walk_nav_menu_tree( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core walker output.
				$menu_items,
				0,
				(object) [
					'after'       => '',
					'before'      => '',
					'link_after'  => '',
					'link_before' => '',
					'walker'      => new $walker_class_name(),
				]
			);
		}
		wp_die();
	}

	private function site( int $site_id ): ?array {
		foreach ( $this->cache->get() as $site ) {
			if ( (int) $site['id'] === $site_id ) {
				return $site;
			}
		}
		return null;
	}
}
```

**`includes/SitesMenu/Module.php`** :
- ajouter la propriété `private ?NavMenu $nav_menu = null;` et la méthode :

```php
	public function nav_menu(): NavMenu {
		return $this->nav_menu ??= new NavMenu( $this->cache );
	}
```

- dans `register()`, ajouter `$this->nav_menu()->register_items();` ;
- dans `init()`, ajouter `$this->nav_menu()->register_editor();` juste après `$this->shortcode()->register( $legacy );`.

- [ ] **Step 4: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php
git commit -m "feat: Network sites metabox for navigation menus with locked URLs"
```

---
## Partie C — Outillage JS et coquille d'administration

### Task 11: Outillage JavaScript, synchronisation des versions et CI

**Files:**
- Create: `package.json`, `package-lock.json` (généré), `.nvmrc`, `webpack.config.js`, `vitest.config.mjs`, `eslint.config.cjs`, `tests/js/setup.mjs`, `bin/version.mjs`, `src/store/paths.js`, `src/store/test/paths.test.js`, `src/admin/mount.jsx`, `src/admin/style.scss`, `src/admin/overview.js`, `src/admin/sites.js`, `src/admin/alerts.js`, `src/admin/settings.js`
- Modify: `.gitignore`, `.distignore`, `.github/workflows/ci.yml`, `tests/php/PluginTest.php`

**Interfaces:**
- Produces :
  - `src/store/paths.js` :
    - `NAMESPACE = '/multisite-radar/v1'` ;
    - `buildPath( route, args = {} )` : clés triées, valeurs vides omises, `encodeURIComponent` ;
    - `normalizePath( path )` : décode puis réencode chaque paire et trie les clés ;
    - `normalizePath( buildPath( … ) ) === buildPath( … )` ;
  - `src/admin/mount.jsx` : `mount( View )` monte `<View />` dans `#msradar-app` (la tâche 13 l'enrichit) ;
  - les points d'entrée webpack `admin/overview`, `admin/sites`, `admin/alerts` et `admin/settings`, qui produisent `build/admin/<vue>.js`, `.asset.php`, `.css` et `-rtl.css` ;
  - les scripts npm `build`, `start`, `lint:js`, `lint:css`, `test:unit`, `version:check`, `version:set`, `plugin-zip` ;
  - le job CI `js` ; le job `plugin-check` construit `build/` avant l'analyse.

- [ ] **Step 1: Créer la configuration**

`.nvmrc` :

```
24
```

`package.json` (indentation par tabulations ; **versions exactes**, sans `^`) :

```json
{
	"name": "multisite-radar",
	"version": "2.0.0-alpha.1",
	"private": true,
	"description": "Network-wide audit for WordPress Multisite.",
	"license": "GPL-3.0-or-later",
	"engines": {
		"node": ">=22.22.2"
	},
	"files": [
		"multisite-radar.php",
		"uninstall.php",
		"readme.txt",
		"LICENSE",
		"includes",
		"build",
		"languages"
	],
	"scripts": {
		"build": "wp-scripts build",
		"start": "wp-scripts start",
		"lint:js": "wp-scripts lint-js src tests/js bin",
		"lint:css": "wp-scripts lint-style \"src/**/*.scss\"",
		"test:unit": "wp-scripts test-unit-js",
		"version:check": "node bin/version.mjs check",
		"version:set": "node bin/version.mjs set",
		"plugin-zip": "wp-scripts plugin-zip"
	},
	"dependencies": {
		"@wordpress/a11y": "4.56.0",
		"@wordpress/api-fetch": "7.56.0",
		"@wordpress/block-editor": "18.0.0",
		"@wordpress/blocks": "16.1.0",
		"@wordpress/components": "41.0.0",
		"@wordpress/compose": "8.9.0",
		"@wordpress/data": "10.56.0",
		"@wordpress/dataviews": "19.1.0",
		"@wordpress/date": "5.56.0",
		"@wordpress/element": "8.8.0",
		"@wordpress/i18n": "6.29.0",
		"@wordpress/icons": "17.0.0",
		"@wordpress/notices": "5.56.0",
		"@wordpress/server-side-render": "6.32.0",
		"@wordpress/url": "4.56.0"
	},
	"devDependencies": {
		"@testing-library/dom": "10.4.1",
		"@testing-library/jest-dom": "7.0.1",
		"@testing-library/react": "16.3.3",
		"@vitejs/plugin-react-swc": "4.3.3",
		"@wordpress/scripts": "36.0.0",
		"jsdom": "26.1.0",
		"react": "18.3.1",
		"react-dom": "18.3.1",
		"vite": "8.3.2",
		"vitest": "5.0.3"
	}
}
```

Les paquets `@wordpress/*` de `dependencies` sont externalisés vers les scripts du cœur au build. Seuls `@wordpress/dataviews/wp` et `@wordpress/icons` sont réellement inclus dans le bundle. Ces paquets sont installés pour les tests et l'analyse statique. React 18.3.1 est celui de WordPress 6.9.

`webpack.config.js` :

```js
/**
 * Configuration webpack de @wordpress/scripts, plus un point d'entrée par vue d'administration :
 * chaque page ne charge que le code de sa vue (écart E2 du plan M2). Les blocs de src/blocks/* sont
 * découverts par @wordpress/scripts à partir de leur block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const VIEWS = [ 'overview', 'sites', 'alerts', 'settings' ];

module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		...Object.fromEntries(
			VIEWS.map( ( view ) => [
				`admin/${ view }`,
				path.resolve( __dirname, `src/admin/${ view }.js` ),
			] )
		),
	} ),
};
```

`vitest.config.mjs` :

```js
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

export default defineConfig( {
	plugins: [ react() ],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		include: [ 'src/**/test/*.test.{js,jsx}' ],
		setupFiles: [ './tests/js/setup.mjs' ],
	},
} );
```

`tests/js/setup.mjs` :

```js
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach( cleanup );

// API du navigateur absentes de jsdom, utilisées par @wordpress/components et DataViews.
if ( ! window.matchMedia ) {
	window.matchMedia = ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener() {},
		removeListener() {},
		addEventListener() {},
		removeEventListener() {},
		dispatchEvent() {
			return false;
		},
	} );
}

class NoopObserver {
	observe() {}
	unobserve() {}
	disconnect() {}
	takeRecords() {
		return [];
	}
}
window.ResizeObserver = window.ResizeObserver || NoopObserver;
window.IntersectionObserver = window.IntersectionObserver || NoopObserver;
if ( ! window.Element.prototype.scrollIntoView ) {
	window.Element.prototype.scrollIntoView = () => {};
}
```

`eslint.config.cjs` :

```js
/**
 * Configuration ESLint de @wordpress/scripts, plus les dossiers à ignorer.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'artifacts/**', 'docs/**', 'vendor/**' ],
	},
];
```

`.gitignore` :
- supprimer la ligne `package-lock.json` : le verrou est versionné, car DataViews est épinglé ;
- ajouter à la fin :

```
/artifacts/
.wp-env.override.json
```

`.distignore` — ajouter :

```
/src
/package.json
/package-lock.json
/.nvmrc
/webpack.config.js
/vitest.config.mjs
/eslint.config.cjs
/playwright.config.js
/.wp-env.json
/artifacts
```

Ne pas ajouter `/build`, qui est livré.

- [ ] **Step 2: Écrire le script de version et adapter le test PHP**

`bin/version.mjs` :

```js
#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Version du plugin : MSRADAR_VERSION (multisite-radar.php) est la seule source de vérité (spec §2.2).
 *
 *     node bin/version.mjs check       vérifie que toutes les copies sont identiques (CI) ;
 *     node bin/version.mjs set 2.0.0   écrit la version partout.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SOURCE = {
	file: 'multisite-radar.php',
	pattern: /(define\( 'MSRADAR_VERSION', ')([^']+)(' \);)/,
};
const COPIES = [
	{ file: 'multisite-radar.php', pattern: /^( \* Version:\s+)(\S+)()$/m },
	{ file: 'readme.txt', pattern: /^(Stable tag:\s+)(\S+)()$/m },
	{ file: 'package.json', pattern: /^(\s*"version": ")([^"]+)(",?)$/m },
	{
		file: 'tests/phpstan-bootstrap.php',
		pattern: /(define\( 'MSRADAR_VERSION', ')([^']+)(' \);)/,
	},
];
const VERSION_PATTERN = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/;

function read( target ) {
	const content = readFileSync( join( ROOT, target.file ), 'utf8' );
	const match = content.match( target.pattern );
	if ( ! match ) {
		throw new Error( `${ target.file }: version not found` );
	}
	return { content, version: match[ 2 ] };
}

function check() {
	const expected = read( SOURCE ).version;
	const wrong = COPIES.map( ( target ) => ( {
		file: target.file,
		version: read( target ).version,
	} ) ).filter( ( copy ) => copy.version !== expected );
	wrong.forEach( ( copy ) =>
		console.error( `${ copy.file }: ${ copy.version } (expected ${ expected })` )
	);
	if ( wrong.length > 0 ) {
		process.exitCode = 1;
		return;
	}
	console.log( `Version ${ expected } is consistent.` );
}

function set( version ) {
	if ( ! VERSION_PATTERN.test( version || '' ) ) {
		console.error( 'Usage: node bin/version.mjs set <x.y.z[-pre]>' );
		process.exitCode = 1;
		return;
	}
	for ( const target of [ SOURCE, ...COPIES ] ) {
		const { content } = read( target );
		writeFileSync(
			join( ROOT, target.file ),
			content.replace(
				target.pattern,
				( match, before, previous, after ) => `${ before }${ version }${ after }`
			)
		);
	}
	console.log( `Version set to ${ version }.` );
}

const [ command, value ] = process.argv.slice( 2 );
if ( command === 'check' ) {
	check();
} else if ( command === 'set' ) {
	set( value );
} else {
	console.error( 'Usage: node bin/version.mjs check | set <version>' );
	process.exitCode = 1;
}
```

Dans `tests/php/PluginTest.php`, remplacer `$this->assertSame( '2.0.0-alpha.1', MSRADAR_VERSION );` par :

```php
		$this->assertSame( get_file_data( MSRADAR_FILE, [ 'Version' => 'Version' ] )['Version'], MSRADAR_VERSION, 'The header and the constant agree; bin/version.mjs keeps the other copies in sync.' );
```

- [ ] **Step 3: Écrire le test qui échoue (chemins canoniques)**

`src/store/test/paths.test.js` :

```js
import { describe, expect, test } from 'vitest';
import { buildPath, normalizePath, NAMESPACE } from '../paths';

describe( 'buildPath', () => {
	test( 'sorts keys, drops empty values and encodes the rest', () => {
		expect( buildPath( '/preferences' ) ).toBe( `${ NAMESPACE }/preferences` );
		expect(
			buildPath( '/sites', {
				search: "O'Brien + 100% été",
				page: 1,
				rule: '',
				status: null,
				theme: undefined,
			} )
		).toBe(
			"/multisite-radar/v1/sites?page=1&search=O'Brien%20%2B%20100%25%20%C3%A9t%C3%A9"
		);
	} );
} );

describe( 'normalizePath', () => {
	test( 'gives the same key for the PHP-encoded preload path and the client path', () => {
		const php =
			'/multisite-radar/v1/sites?search=O%27Brien%20%2B%20100%25%20%C3%A9t%C3%A9&page=1';
		expect( normalizePath( php ) ).toBe(
			buildPath( '/sites', { search: "O'Brien + 100% été", page: 1 } )
		);
	} );

	test( 'is idempotent on built paths and keeps paths without a query', () => {
		const built = buildPath( '/sites', { alert_level: 'warning,error', page: 2 } );
		expect( normalizePath( built ) ).toBe( built );
		expect( normalizePath( `${ NAMESPACE }/settings` ) ).toBe( `${ NAMESPACE }/settings` );
	} );

	test( 'reads plus signs as spaces and survives malformed escapes', () => {
		expect( normalizePath( '/x?b=a+b&a=%E0%A4%A' ) ).toBe( '/x?a=%25E0%25A4%25A&b=a%20b' );
	} );
} );
```

Le dernier cas fixe le comportement sur une séquence invalide : elle est gardée telle quelle, puis réencodée.

- [ ] **Step 4: Installer et vérifier que le test échoue**

Run: `npm install`
Expected: `package-lock.json` est créé, sans erreur `ERESOLVE`.

En cas de conflit de dépendances paires, aligner la version en conflit sur celle demandée par le paquet pair. Ne jamais utiliser `--force` ni `--legacy-peer-deps`, et signaler l'ajustement dans le rapport.

Run: `npx vitest run src/store`
Expected: FAIL (`Failed to resolve import "../paths"`).

- [ ] **Step 5: Implémenter les chemins**

`src/store/paths.js` :

```js
/**
 * Chemins REST canoniques : clés triées, valeurs vides omises, valeurs encodées avec encodeURIComponent.
 *
 * Ces chemins servent de clés au cache du store. PHP précharge les mêmes requêtes (Admin\Preload), mais les encode
 * avec rawurlencode : normalizePath() ramène les deux écritures à la même clé.
 */
export const NAMESPACE = '/multisite-radar/v1';

function isEmpty( value ) {
	return value === undefined || value === null || value === '';
}

export function buildPath( route, args = {} ) {
	const keys = Object.keys( args )
		.filter( ( key ) => ! isEmpty( args[ key ] ) )
		.sort();
	if ( keys.length === 0 ) {
		return `${ NAMESPACE }${ route }`;
	}
	const query = keys
		.map(
			( key ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					String( args[ key ] )
				) }`
		)
		.join( '&' );
	return `${ NAMESPACE }${ route }?${ query }`;
}

function decode( part ) {
	try {
		return decodeURIComponent( part.replace( /\+/g, ' ' ) );
	} catch {
		return part;
	}
}

export function normalizePath( path ) {
	const index = path.indexOf( '?' );
	if ( index === -1 ) {
		return path;
	}
	const pairs = path
		.slice( index + 1 )
		.split( '&' )
		.filter( Boolean )
		.map( ( pair ) => {
			const eq = pair.indexOf( '=' );
			return eq === -1
				? [ decode( pair ), '' ]
				: [ decode( pair.slice( 0, eq ) ), decode( pair.slice( eq + 1 ) ) ];
		} );
	if ( pairs.length === 0 ) {
		return path.slice( 0, index );
	}
	pairs.sort( ( a, b ) => {
		if ( a[ 0 ] === b[ 0 ] ) {
			return 0;
		}
		return a[ 0 ] < b[ 0 ] ? -1 : 1;
	} );
	return `${ path.slice( 0, index ) }?${ pairs
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent( value ) }`
		)
		.join( '&' ) }`;
}
```

Pour `%E0%A4%A`, `decodeURIComponent` lève une erreur. La valeur reste donc telle quelle, et son `%` est réencodé en `%25` : le test l'attend.

- [ ] **Step 6: Points d'entrée provisoires**

`src/admin/mount.jsx` :

```jsx
import { createRoot } from '@wordpress/element';

/**
 * Monte une vue dans le conteneur rendu par PHP (Admin\Menu::render()).
 *
 * @param {Function} View Composant de la vue.
 */
export function mount( View ) {
	const container = document.getElementById( 'msradar-app' );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <View /> );
}
```

`src/admin/style.scss` :

```scss
.msradar-app {
	margin-top: 16px;
}
```

Créer `src/admin/overview.js`, `src/admin/sites.js`, `src/admin/alerts.js` et `src/admin/settings.js` avec ce contenu identique : chaque vue le remplacera (tâches 14 à 18).

```js
import { mount } from './mount';
import './style.scss';

mount( () => null );
```

- [ ] **Step 7: Lancer les tests, le lint et le build**

Run: `npm run test:unit`
Expected: PASS (4 tests).

Run: `npm run lint:js && npm run lint:css`
Expected: aucune erreur.

Si `import/no-unresolved` ou `import/no-extraneous-dependencies` signalent un import valide, ajouter une règle ciblée dans `eslint.config.cjs`. Par exemple :

```js
{ rules: { 'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/dataviews/wp$' ] } ] } }
```

Ne jamais désactiver une règle globalement.

Run: `npm run build && ls build/admin`
Expected: `alerts.asset.php alerts.css alerts-rtl.css alerts.js overview.… settings.… sites.…`.

Run: `npm run version:check`
Expected: `Version 2.0.0-alpha.1 is consistent.`

Run: `bin/test.sh --filter PluginTest`
Expected: PASS.

- [ ] **Step 8: CI**

Dans `.github/workflows/ci.yml` :

a) Ajouter le job :

```yaml
  js:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: .nvmrc
          cache: npm
      - run: npm ci
      - run: npm run version:check
      - run: npm run lint:js
      - run: npm run lint:css
      - run: npm run test:unit
      - run: npm run build
```

b) Dans le job `plugin-check`, insérer avant l'étape `Build the distributable directory` :

```yaml
      - uses: actions/setup-node@v4
        with:
          node-version-file: .nvmrc
          cache: npm
      - run: npm ci
      - run: npm run build
```

Run: `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color`
Expected: aucune sortie (le workflow est valide).

- [ ] **Step 9: Commit**

```bash
git add package.json package-lock.json .nvmrc webpack.config.js vitest.config.mjs eslint.config.cjs tests/js bin/version.mjs src .gitignore .distignore .github/workflows/ci.yml tests/php/PluginTest.php
git commit -m "build: JavaScript toolchain with wp-scripts, Vitest, version sync and a CI job"
```

---

### Task 12: Coquille d'administration — menu réseau, scripts, préchargement, confidentialité

**Files:**
- Create: `includes/Admin/Menu.php`, `includes/Admin/Assets.php`, `includes/Admin/ViewQuery.php`, `includes/Admin/Preload.php`, `includes/Admin/Privacy.php`, `tests/fixtures/view-queries.json`, `tests/php/fixtures/build/admin/sites.asset.php`, `tests/php/fixtures/build/admin/sites.css`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Admin/MenuTest.php`, `tests/php/Admin/ViewQueryTest.php`, `tests/php/Admin/PreloadTest.php`, `tests/php/Admin/AssetsTest.php`, `tests/php/Admin/PrivacyTest.php`

**Interfaces:**
- Consumes :
  - `Preferences::get()` et `Preferences::PER_PAGE` (tâche 7) ;
  - `ExportHandler::ACTION` (tâche 8) ;
  - `SitesRepository::ORDERBY`, `SitesQuery::STATUSES`, `SitesQuery::REGISTRY_STATUSES`, `Severity::names()`, `RuleRegistry::ID_PATTERN`, `AlertsQuery::ORDERBY`.
- Produces :
  - `Menu::PAGES` (vue → slug), `Menu::titles(): array`, `Menu::url( string $view, array $args = [] ): string` ;
  - `Menu::current_view(): string`, `Menu::view_for_hook( string $hook_suffix ): ?string`, `Menu::add_pages()`, `Menu::render()` ;
  - le conteneur `<div id="msradar-app" class="msradar-app" data-view="<vue>">` ;
  - `Assets::enqueue_view( string $view ): bool` et `Assets::config( string $view ): array`, injecté avant le script sous la forme `window.msradarAdmin = {…};`. Les clés de la configuration :
    - `view`, `pages` (vue → URL), `canManage` (bool) ;
    - `exportUrl`, `exportNonce` ;
    - `preload` (chemin → `{ body, headers }`) ;
    - pour la vue `settings` seulement, `postTypes` et `plugins` (chacun une liste de `{ value, label }`) ;
  - `ViewQuery::sites( array $query, array $prefs ): array` et `ViewQuery::alerts( array $query, array $prefs ): array`, qui renvoient les arguments REST, que le cas partagé `tests/fixtures/view-queries.json` vérifie aussi côté JS ;
  - `ViewQuery::site_id( array $query ): int` et `ViewQuery::path( string $route, array $args = [] ): string` ;
  - `Preload::paths( string $view, array $query, array $prefs ): string[]` et `Preload::run( string[] $paths ): array` ;
  - `Privacy::text(): string` et `Privacy::add_policy_content()`.
- **Paramètres d'URL des vues :**
  - **Sites :**
    - `s` ;
    - `orderby` : une clé de `SitesRepository::ORDERBY` ;
    - `order`, `paged` ;
    - `alert_level`, `status`, `registry_status` : listes séparées par des virgules ;
    - `rule`, `layout` (`table`|`grid`), `site` (ID).
  - **Alertes :**
    - `s` ;
    - `severity` et `rule` : listes ;
    - `orderby` (`rule`|`name`|`severity`), `order`, `paged`.
- **Arguments REST produits :** `page`, `per_page` (préférence de la vue), `orderby` et `order` sont toujours présents ; les autres le sont seulement s'ils ne sont pas vides, les listes en chaînes séparées par des virgules.

- [ ] **Step 1: Créer les cas partagés**

`tests/fixtures/view-queries.json` :

```json
[
	{
		"name": "sites: defaults",
		"view": "sites",
		"query": {},
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "sites: every parameter",
		"view": "sites",
		"query": { "s": "  blog  ", "orderby": "users_count", "order": "desc", "paged": "3", "alert_level": "error,bogus,warning", "status": "archived", "registry_status": "missing , stale", "rule": "inactive", "layout": "grid", "site": "12" },
		"prefs": { "sites": { "per_page": 50 } },
		"args": { "page": 3, "per_page": 50, "orderby": "users_count", "order": "desc", "search": "blog", "alert_level": "warning,error", "status": "archived", "registry_status": "stale,missing", "rule": "inactive" }
	},
	{
		"name": "sites: invalid values fall back to the defaults",
		"view": "sites",
		"query": { "orderby": "data", "order": "sideways", "paged": "-4", "rule": "Bad Rule", "alert_level": ",,", "status": "Public" },
		"prefs": { "sites": { "per_page": 7 } },
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "sites: pages are digits only and per_page must be an integer",
		"view": "sites",
		"query": { "paged": "1e3" },
		"prefs": { "sites": { "per_page": "50" } },
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "sites: a huge page is capped",
		"view": "sites",
		"query": { "paged": "99999999999999999999" },
		"prefs": {},
		"args": { "page": 100000, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "sites: search keeps quotes, plus and percent signs, accents and non-breaking spaces",
		"view": "sites",
		"query": { "s": " O'Brien + 100% été " },
		"prefs": { "sites": { "per_page": 100 } },
		"args": { "page": 1, "per_page": 100, "orderby": "name", "order": "asc", "search": "O'Brien + 100% été " }
	},
	{
		"name": "alerts: defaults",
		"view": "alerts",
		"query": {},
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "rule", "order": "asc" }
	},
	{
		"name": "alerts: every parameter",
		"view": "alerts",
		"query": { "s": "rh", "severity": "info,error", "rule": "no_users,inactive,no_users,Bad Rule", "orderby": "severity", "order": "desc", "paged": "2" },
		"prefs": { "alerts": { "per_page": 100 } },
		"args": { "page": 2, "per_page": 100, "orderby": "severity", "order": "desc", "search": "rh", "severity": "error,info", "rule": "inactive,no_users" }
	},
	{
		"name": "alerts: invalid values fall back to the defaults",
		"view": "alerts",
		"query": { "orderby": "site_id", "severity": "fatal", "rule": ",,," },
		"prefs": { "sites": { "per_page": 50 } },
		"args": { "page": 1, "per_page": 20, "orderby": "rule", "order": "asc" }
	}
]
```

Les clés de `args` suivent l'ordre d'insertion de PHP (`assertSame` le vérifie) : `page`, `per_page`, `orderby`, `order`, `search`, puis les listes et `rule`. Côté JS, `toEqual` ignore l'ordre.

Créer aussi les faux fichiers de build :

`tests/php/fixtures/build/admin/sites.asset.php` :

```php
<?php return [ 'dependencies' => [ 'react', 'wp-api-fetch' ], 'version' => 'test' ];
```

`tests/php/fixtures/build/admin/sites.css` :

```css
/* Feuille de style factice pour AssetsTest. */
```

- [ ] **Step 2: Écrire les tests qui échouent**

`tests/php/Admin/ViewQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\ViewQuery;
use MultisiteRadar\Tests\TestCase;

final class ViewQueryTest extends TestCase {

	public static function cases(): array {
		$cases = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/view-queries.json' ), true );
		$out   = [];
		foreach ( $cases as $case ) {
			$out[ $case['name'] ] = [ $case['view'], $case['query'], $case['prefs'], $case['args'] ];
		}
		return $out;
	}

	/**
	 * @dataProvider cases
	 */
	public function test_matches_the_cases_shared_with_the_client( string $view, array $query, array $prefs, array $args ): void {
		$this->assertSame( $args, 'sites' === $view ? ViewQuery::sites( $query, $prefs ) : ViewQuery::alerts( $query, $prefs ) );
	}

	public function test_paths_sort_keys_and_encode_values(): void {
		$this->assertSame( '/multisite-radar/v1/preferences', ViewQuery::path( '/preferences' ) );
		$this->assertSame(
			'/multisite-radar/v1/sites?page=1&search=O%27Brien%20%2B%20100%25%20%C3%A9t%C3%A9',
			ViewQuery::path(
				'/sites',
				[
					'search' => "O'Brien + 100% été",
					'page'   => 1,
				]
			)
		);
	}

	public function test_site_id(): void {
		$this->assertSame( 12, ViewQuery::site_id( [ 'site' => '12' ] ) );
		$this->assertSame( 0, ViewQuery::site_id( [ 'site' => '12abc' ] ) );
		$this->assertSame( 0, ViewQuery::site_id( [] ) );
	}
}
```

`tests/php/Admin/PreloadTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Preload;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\RestTestCase;

final class PreloadTest extends RestTestCase {

	public function test_paths_per_view(): void {
		$prefs = Preferences::defaults();

		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/scan/status' ], Preload::paths( 'overview', [], $prefs ) );
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20', '/multisite-radar/v1/sites/12' ],
			Preload::paths( 'sites', [ 'site' => '12' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20' ],
			Preload::paths( 'alerts', [], $prefs )
		);
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings' ], Preload::paths( 'settings', [], $prefs ) );
	}

	public function test_run_preloads_successful_responses_only(): void {
		$this->login_as_super_admin();
		$this->make_record(
			101,
			[
				'name'       => 'Alpha',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$data = Preload::run( Preload::paths( 'sites', [ 'site' => '999999' ], Preferences::defaults() ) );

		$list = $data['/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20'];
		$this->assertContains( 'Alpha', wp_list_pluck( $list['body'], 'name' ) );
		$this->assertArrayHasKey( 'X-WP-Total', $list['headers'] );
		$this->assertArrayNotHasKey( '/multisite-radar/v1/sites/999999', $data, 'A 404 is not preloaded: the client shows its own error.' );
	}
}
```

`tests/php/Admin/MenuTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Capabilities;
use MultisiteRadar\Tests\TestCase;

final class MenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		set_current_screen( 'dashboard-network' );
		$GLOBALS['menu']                = [];
		$GLOBALS['submenu']             = [];
		$GLOBALS['_wp_submenu_nopriv']  = [];
		$GLOBALS['_registered_pages']   = [];
		$GLOBALS['_parent_pages']       = [];
	}

	private function slugs(): array {
		return array_column( $GLOBALS['submenu']['multisite-radar'] ?? [], 2 );
	}

	public function test_registers_one_page_per_view_for_super_admins(): void {
		$menu = new Menu();
		$menu->add_pages();

		$this->assertSame( [ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-alerts', 'multisite-radar-settings' ], $this->slugs() );
		$this->assertSame( 'sites', $menu->view_for_hook( (string) get_plugin_page_hookname( 'multisite-radar-sites', 'multisite-radar' ) ) );
		$this->assertNull( $menu->view_for_hook( 'index.php' ) );
	}

	public function test_settings_require_the_manage_capability(): void {
		$map = static function (): array {
			return [
				Capabilities::VIEW   => 'manage_network',
				Capabilities::MANAGE => 'do_not_allow',
			];
		};
		add_filter( 'msradar_capability_map', $map );
		try {
			( new Menu() )->add_pages();
		} finally {
			remove_filter( 'msradar_capability_map', $map );
		}

		$this->assertSame( [ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-alerts' ], $this->slugs() );
	}

	public function test_render_outputs_the_application_container_for_the_current_page(): void {
		$_GET['page'] = 'multisite-radar-alerts';
		ob_start();
		( new Menu() )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['page'] );

		$this->assertStringContainsString( '<div id="msradar-app" class="msradar-app" data-view="alerts"></div>', $html );
		$this->assertStringContainsString( '<h1>Alerts</h1>', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_urls_point_to_the_network_admin(): void {
		$this->assertSame( network_admin_url( 'admin.php?page=multisite-radar-sites&rule=no_users' ), Menu::url( 'sites', [ 'rule' => 'no_users' ] ) );
	}
}
```

`tests/php/Admin/AssetsTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Assets;
use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Tests\RestTestCase;

final class AssetsTest extends RestTestCase {

	private function assets(): Assets {
		return new Assets( new Menu(), $this->plugin()->preferences(), dirname( __DIR__ ) . '/fixtures/build/', 'https://example.test/build/' );
	}

	public function tear_down(): void {
		foreach ( [ 'msradar-sites', 'msradar-alerts', 'msradar-settings' ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		parent::tear_down();
	}

	private function config_of( string $handle ): array {
		$inline = implode( "\n", (array) wp_scripts()->get_data( $handle, 'before' ) );
		$this->assertStringStartsWith( 'window.msradarAdmin = ', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );
		return json_decode( substr( $inline, strlen( 'window.msradarAdmin = ' ), -1 ), true );
	}

	public function test_enqueues_the_view_bundle_with_its_safe_inline_configuration(): void {
		$this->login_as_super_admin();
		$this->make_record(
			101,
			[
				'name'       => '</script><script>alert(1)</script>',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$this->assertTrue( $this->assets()->enqueue_view( 'sites' ) );

		$this->assertTrue( wp_script_is( 'msradar-sites', 'enqueued' ) );
		$this->assertSame( [ 'react', 'wp-api-fetch' ], wp_scripts()->registered['msradar-sites']->deps );
		$this->assertSame( 'https://example.test/build/admin/sites.js', wp_scripts()->registered['msradar-sites']->src );
		$this->assertTrue( wp_style_is( 'msradar-sites', 'enqueued' ) );
		$this->assertSame( [ 'wp-components' ], wp_styles()->registered['msradar-sites']->deps );
		$this->assertSame( 'replace', wp_styles()->get_data( 'msradar-sites', 'rtl' ) );

		$config = $this->config_of( 'msradar-sites' );
		$this->assertSame( 'sites', $config['view'] );
		$this->assertTrue( $config['canManage'] );
		$this->assertSame( admin_url( 'admin-post.php' ), $config['exportUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $config['exportNonce'], ExportHandler::ACTION ) );
		$this->assertSame( Menu::url( 'sites' ), $config['pages']['sites'] );
		$list = $config['preload']['/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20'];
		$this->assertContains( '</script><script>alert(1)</script>', wp_list_pluck( $list['body'], 'name' ), 'The name survives intact once decoded.' );
	}

	public function test_a_missing_build_shows_a_notice_instead_of_a_broken_page(): void {
		$assets = $this->assets();

		$this->assertFalse( $assets->enqueue_view( 'alerts' ) );
		$this->assertFalse( wp_script_is( 'msradar-alerts', 'enqueued' ) );
		ob_start();
		$assets->render_missing_build_notice();
		$this->assertStringContainsString( 'npm run build', (string) ob_get_clean() );
	}

	public function test_the_settings_view_receives_post_types_and_plugins(): void {
		$this->login_as_super_admin();

		$config = $this->assets()->config( 'settings' );

		$this->assertContains(
			[
				'value' => 'post',
				'label' => 'Posts',
			],
			$config['postTypes']
		);
		$this->assertNotContains( 'attachment', array_column( $config['postTypes'], 'value' ) );
		$this->assertIsArray( $config['plugins'] );
		$this->assertArrayNotHasKey( 'postTypes', $this->assets()->config( 'sites' ) );
	}
}
```

`tests/php/Admin/PrivacyTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Privacy;
use MultisiteRadar\Tests\TestCase;

final class PrivacyTest extends TestCase {

	public function test_suggests_a_privacy_policy_text_on_admin_init(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$privacy = new Privacy();

		add_action( 'admin_init', [ $privacy, 'add_policy_content' ] );
		do_action( 'admin_init' );
		remove_action( 'admin_init', [ $privacy, 'add_policy_content' ] );

		$texts = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertArrayHasKey( 'Multisite Radar', $texts );
		$this->assertStringContainsString( 'logins', $texts['Multisite Radar'] );
	}
}
```

- [ ] **Step 3: Lancer les tests pour vérifier qu'ils échouent**

Run: `bin/test.sh --filter 'Admin'`
Expected: FAIL (`Class "MultisiteRadar\Admin\ViewQuery" not found`).

- [ ] **Step 4: Implémenter**

`includes/Admin/ViewQuery.php` :

```php
<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Traduit les paramètres d'URL d'une page d'administration en arguments REST, exactement comme le client
 * (src/views/sites/query.js et src/views/alerts/query.js) : le préchargement doit viser la même requête.
 * tests/fixtures/view-queries.json vérifie la parité des deux côtés.
 */
final class ViewQuery {

	public const NAMESPACE   = '/multisite-radar/v1';
	public const MAX_PAGE    = 100000;
	public const SEVERITIES  = [ 'error', 'warning', 'info' ];
	private const PER_PAGE   = 20;

	public static function sites( array $query, array $prefs ): array {
		$orderby = self::text( $query, 'orderby' );
		$args    = [
			'page'     => self::page( $query ),
			'per_page' => self::per_page( $prefs['sites']['per_page'] ?? null ),
			'orderby'  => isset( SitesRepository::ORDERBY[ $orderby ] ) ? $orderby : 'name',
			'order'    => 'desc' === self::text( $query, 'order' ) ? 'desc' : 'asc',
		];
		$search  = trim( self::text( $query, 's' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		$lists = [
			'alert_level'     => Severity::names(),
			'status'          => SitesQuery::STATUSES,
			'registry_status' => SitesQuery::REGISTRY_STATUSES,
		];
		foreach ( $lists as $key => $allowed ) {
			$values = self::subset( $query, $key, $allowed );
			if ( [] !== $values ) {
				$args[ $key ] = implode( ',', $values );
			}
		}
		$rule = self::text( $query, 'rule' );
		if ( 1 === preg_match( RuleRegistry::ID_PATTERN, $rule ) ) {
			$args['rule'] = $rule;
		}
		return $args;
	}

	public static function alerts( array $query, array $prefs ): array {
		$orderby = self::text( $query, 'orderby' );
		$args    = [
			'page'     => self::page( $query ),
			'per_page' => self::per_page( $prefs['alerts']['per_page'] ?? null ),
			'orderby'  => in_array( $orderby, AlertsQuery::ORDERBY, true ) ? $orderby : 'rule',
			'order'    => 'desc' === self::text( $query, 'order' ) ? 'desc' : 'asc',
		];
		$search  = trim( self::text( $query, 's' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		$severity = self::subset( $query, 'severity', self::SEVERITIES );
		if ( [] !== $severity ) {
			$args['severity'] = implode( ',', $severity );
		}
		$rules = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', explode( ',', self::text( $query, 'rule' ) ) ),
					static fn ( string $rule ): bool => 1 === preg_match( RuleRegistry::ID_PATTERN, $rule )
				)
			)
		);
		sort( $rules, SORT_STRING );
		if ( [] !== $rules ) {
			$args['rule'] = implode( ',', $rules );
		}
		return $args;
	}

	public static function site_id( array $query ): int {
		$value = self::text( $query, 'site' );
		return 1 === preg_match( '/^\d+$/', $value ) && (int) $value > 0 ? (int) $value : 0;
	}

	/**
	 * Chemin REST avec clés triées et valeurs encodées par rawurlencode (le client normalise les deux écritures).
	 */
	public static function path( string $route, array $args = [] ): string {
		ksort( $args, SORT_STRING );
		$pairs = [];
		foreach ( $args as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return self::NAMESPACE . $route . ( [] === $pairs ? '' : '?' . implode( '&', $pairs ) );
	}

	private static function text( array $query, string $key ): string {
		$value = $query[ $key ] ?? '';
		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}

	/**
	 * Chiffres seulement (« 1e3 » n'est pas une page), bornés à MAX_PAGE.
	 */
	private static function page( array $query ): int {
		$value = self::text( $query, 'paged' );
		if ( 1 !== preg_match( '/^\d+$/', $value ) ) {
			return 1;
		}
		return max( 1, min( self::MAX_PAGE, (int) $value ) );
	}

	/**
	 * @param mixed $value Préférence enregistrée.
	 */
	private static function per_page( $value ): int {
		return in_array( $value, Preferences::PER_PAGE, true ) ? (int) $value : self::PER_PAGE;
	}

	/**
	 * Valeurs autorisées présentes dans une liste séparée par des virgules, dans l'ordre de $allowed.
	 *
	 * @param string[] $allowed
	 * @return string[]
	 */
	private static function subset( array $query, string $key, array $allowed ): array {
		$given = array_map( 'trim', explode( ',', self::text( $query, $key ) ) );
		return array_values( array_intersect( $allowed, $given ) );
	}
}
```

`includes/Admin/Preload.php` :

```php
<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Requêtes REST préchargées dans la page : le client affiche la première vue sans attendre (spec §1.4 n° 2).
 */
final class Preload {

	/**
	 * @param string $view  overview, sites, alerts ou settings.
	 * @param array  $query Paramètres d'URL de la page.
	 * @param array  $prefs Préférences complètes de l'utilisateur.
	 * @return string[]
	 */
	public static function paths( string $view, array $query, array $prefs ): array {
		$paths = [ ViewQuery::path( '/preferences' ) ];
		switch ( $view ) {
			case 'overview':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/scan/status' );
				break;
			case 'sites':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/sites', ViewQuery::sites( $query, $prefs ) );
				$site_id = ViewQuery::site_id( $query );
				if ( $site_id > 0 ) {
					$paths[] = ViewQuery::path( '/sites/' . $site_id );
				}
				break;
			case 'alerts':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/alerts', ViewQuery::alerts( $query, $prefs ) );
				break;
			case 'settings':
				$paths[] = ViewQuery::path( '/settings' );
				break;
		}
		return $paths;
	}

	/**
	 * Seules les réponses 200 sont gardées (comportement de rest_preload_api_request()) : une erreur est
	 * redemandée par le client, qui l'affiche avec « Retry ».
	 *
	 * @param string[] $paths
	 * @return array<string, array{body: mixed, headers: array}>
	 */
	public static function run( array $paths ): array {
		return array_reduce( $paths, 'rest_preload_api_request', [] );
	}
}
```

`includes/Admin/Menu.php` :

```php
<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Menu « Multisite Radar » de l'administration réseau : une page par vue, chacune avec le conteneur de l'application.
 */
final class Menu {

	public const PAGES = [
		'overview' => 'multisite-radar',
		'sites'    => 'multisite-radar-sites',
		'alerts'   => 'multisite-radar-alerts',
		'settings' => 'multisite-radar-settings',
	];

	/**
	 * @var array<string, string> Suffixe de hook de page => vue.
	 */
	private array $hooks = [];

	public function register(): void {
		add_action( 'network_admin_menu', [ $this, 'add_pages' ] );
	}

	/**
	 * @return array<string, string>
	 */
	public static function titles(): array {
		return [
			'overview' => __( 'Overview', 'multisite-radar' ),
			'sites'    => __( 'Sites', 'multisite-radar' ),
			'alerts'   => __( 'Alerts', 'multisite-radar' ),
			'settings' => __( 'Settings', 'multisite-radar' ),
		];
	}

	public function add_pages(): void {
		$parent = self::PAGES['overview'];
		add_menu_page( __( 'Multisite Radar', 'multisite-radar' ), __( 'Multisite Radar', 'multisite-radar' ), Capabilities::VIEW, $parent, [ $this, 'render' ], 'dashicons-chart-area', 30 );
		foreach ( self::titles() as $view => $title ) {
			$hook = add_submenu_page( $parent, $title, $title, 'settings' === $view ? Capabilities::MANAGE : Capabilities::VIEW, self::PAGES[ $view ], [ $this, 'render' ] );
			if ( false !== $hook ) {
				$this->hooks[ (string) $hook ] = $view;
			}
		}
	}

	public function view_for_hook( string $hook_suffix ): ?string {
		return $this->hooks[ $hook_suffix ] ?? null;
	}

	public static function url( string $view, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGES[ $view ] ?? self::PAGES['overview'] ], $args ), network_admin_url( 'admin.php' ) );
	}

	public static function current_view(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$view = array_search( $page, self::PAGES, true );
		return false === $view ? 'overview' : (string) $view;
	}

	public function render(): void {
		$view  = self::current_view();
		$title = 'overview' === $view ? __( 'Multisite Radar', 'multisite-radar' ) : self::titles()[ $view ];
		printf(
			'<div class="wrap msradar-wrap"><h1>%1$s</h1><div id="msradar-app" class="msradar-app" data-view="%2$s"></div><noscript><div class="notice notice-error"><p>%3$s</p></div></noscript></div>',
			esc_html( $title ),
			esc_attr( $view ),
			esc_html__( 'Multisite Radar needs JavaScript to display this page.', 'multisite-radar' )
		);
	}
}
```

`includes/Admin/Assets.php` :

```php
<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Settings\Preferences;

defined( 'ABSPATH' ) || exit;

/**
 * Charge le point d'entrée compilé de la vue affichée et lui passe sa configuration, y compris les données préchargées.
 */
final class Assets {

	private Menu $menu;
	private Preferences $preferences;
	private string $build_dir;
	private string $build_url;

	public function __construct( Menu $menu, Preferences $preferences, string $build_dir, string $build_url ) {
		$this->menu        = $menu;
		$this->preferences = $preferences;
		$this->build_dir   = trailingslashit( $build_dir );
		$this->build_url   = trailingslashit( $build_url );
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param mixed $hook_suffix Suffixe de la page d'administration.
	 */
	public function enqueue( $hook_suffix ): void {
		$view = $this->menu->view_for_hook( (string) $hook_suffix );
		if ( null !== $view ) {
			$this->enqueue_view( $view );
		}
	}

	public function enqueue_view( string $view ): bool {
		$asset_file = $this->build_dir . 'admin/' . $view . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action( 'network_admin_notices', [ $this, 'render_missing_build_notice' ] );
			return false;
		}
		$asset   = (array) require $asset_file;
		$handle  = 'msradar-' . $view;
		$version = (string) ( $asset['version'] ?? MSRADAR_VERSION );

		wp_enqueue_script( $handle, $this->build_url . 'admin/' . $view . '.js', (array) ( $asset['dependencies'] ?? [] ), $version, true );
		wp_set_script_translations( $handle, 'multisite-radar', MSRADAR_DIR . 'languages' );
		wp_add_inline_script( $handle, 'window.msradarAdmin = ' . wp_json_encode( $this->config( $view ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );

		if ( is_readable( $this->build_dir . 'admin/' . $view . '.css' ) ) {
			wp_enqueue_style( $handle, $this->build_url . 'admin/' . $view . '.css', [ 'wp-components' ], $version );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
		return true;
	}

	public function config( string $view ): array {
		$pages = [];
		foreach ( array_keys( Menu::PAGES ) as $page ) {
			$pages[ $page ] = Menu::url( $page );
		}
		// Pas de sanitize_text_field() : il regrouperait les espaces et retirerait les « %xx » de la recherche, et la
		// requête préchargée ne serait plus celle du client. ViewQuery valide chaque paramètre (listes blanches, chiffres)
		// et la recherche ne sert qu'à une requête préparée.
		$query  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view state, validated by ViewQuery.
		$config = [
			'view'        => $view,
			'pages'       => $pages,
			'canManage'   => current_user_can( Capabilities::MANAGE ),
			'exportUrl'   => admin_url( 'admin-post.php' ),
			'exportNonce' => wp_create_nonce( ExportHandler::ACTION ),
			'preload'     => Preload::run( Preload::paths( $view, is_array( $query ) ? $query : [], $this->preferences->get( get_current_user_id() ) ) ),
		];
		if ( 'settings' === $view ) {
			$config['postTypes'] = self::post_types();
			$config['plugins']   = self::plugins();
		}
		return $config;
	}

	public function render_missing_build_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Multisite Radar: the interface files are missing. Run "npm install && npm run build" in the plugin folder, or install a release package.', 'multisite-radar' )
		);
	}

	/**
	 * Types publics du site principal, suggérés pour « types d'activité ».
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function post_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $name => $object ) {
			if ( 'attachment' !== $name ) {
				$types[] = [
					'value' => (string) $name,
					'label' => (string) $object->labels->name,
				];
			}
		}
		return $types;
	}

	/**
	 * Plugins installés, identifiés comme dans l'origine des types (dossier du plugin, ou nom du fichier seul).
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$slug             = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$plugins[ $slug ] = [
				'value' => $slug,
				'label' => (string) $data['Name'],
			];
		}
		return array_values( $plugins );
	}
}
```

`includes/Admin/Privacy.php` :

```php
<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Texte proposé pour la politique de confidentialité (spec §9).
 */
final class Privacy {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	public function add_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Multisite Radar', wp_kses_post( wpautop( self::text(), false ) ) );
		}
	}

	public static function text(): string {
		return __( 'Multisite Radar is an audit tool for network administrators. It collects no data from visitors and makes no external requests. To build its inventory of the network, it keeps in the network database a copy of data that WordPress already holds: for each site, the user IDs and logins of up to 50 administrators and editors, and the number of users per role. This copy is refreshed in the background and deleted when the plugin is uninstalled. When a user account is deleted, the sites it belonged to are analysed again.', 'multisite-radar' );
	}
}
```

**`includes/Plugin.php`** :
- ajouter `use MultisiteRadar\Admin\Assets;`, `use MultisiteRadar\Admin\Menu;` et `use MultisiteRadar\Admin\Privacy;` ;
- ajouter les propriétés `private ?Menu $admin_menu = null;`, `private ?Assets $assets = null;` et `private ?Privacy $privacy = null;` ;
- ajouter les méthodes :

```php
	public function admin_menu(): Menu {
		return $this->admin_menu ??= new Menu();
	}

	public function assets(): Assets {
		return $this->assets ??= new Assets( $this->admin_menu(), $this->preferences(), MSRADAR_DIR . 'build/', MSRADAR_URL . 'build/' );
	}

	public function privacy(): Privacy {
		return $this->privacy ??= new Privacy();
	}
```

Dans `boot()`, compléter le bloc `if ( is_admin() )` (tâche 8) :

```php
		if ( is_admin() ) {
			$this->export()->register();
			$this->admin_menu()->register();
			$this->assets()->register();
			$this->privacy()->register();
		}
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 6: Vérifier dans le WordPress local**

Run: `npm run build`

Ouvrir `http://wp-network-plugin-utilities.test:8080/wp-admin/network/admin.php?page=multisite-radar-sites` en super-admin. Le menu « Multisite Radar » affiche quatre sous-pages ; chaque page affiche son titre et un conteneur vide (les vues arrivent aux tâches 14 à 18). Dans la console du navigateur :

```js
Object.keys( window.msradarAdmin.preload )
```

Le résultat liste `/multisite-radar/v1/preferences`, `/multisite-radar/v1/alerts/summary` et la liste des sites.

- [ ] **Step 7: Normes et commit**

Run: `composer lint && composer analyse`
Expected: aucune erreur.

```bash
git add includes tests/php tests/fixtures
git commit -m "feat: network admin menu, per-view bundles with preloaded data and privacy policy text"
```

---
### Task 13: Socle de l'application — store, hooks, adaptateur DataViews, composants communs

**Files:**
- Create:
  - `src/admin/config.js`, `src/admin/app.jsx` ;
  - `src/store/index.js` ;
  - `src/hooks/use-resource.js`, `src/hooks/use-url-state.js`, `src/hooks/use-preferences.js`, `src/hooks/use-scan.js` ;
  - `src/components/data-views/index.js`, `src/components/data-views/filters.js`, `src/components/error-notice.jsx`, `src/components/snackbars.jsx`, `src/components/badges.jsx`, `src/components/error-boundary.jsx` ;
  - `src/utils/format.js`.
- Modify: `src/admin/mount.jsx`, `src/admin/style.scss`
- Test:
  - `src/store/test/store.test.js` ;
  - `src/hooks/test/use-resource.test.jsx`, `src/hooks/test/use-url-state.test.jsx`, `src/hooks/test/use-scan.test.jsx` ;
  - `src/utils/test/format.test.js` ;
  - `src/components/test/components.test.jsx`, `src/components/data-views/test/data-views.test.js`.

**Interfaces:**
- Consumes :
  - `buildPath`, `normalizePath` et `NAMESPACE` (tâche 11) ;
  - `window.msradarAdmin` (tâche 12) ;
  - REST `/preferences` (tâche 7), `/scan` et `/scan/batch` (M1).
- Produces :
  - **`src/admin/config.js`** :
    - `getConfig()`, qui renvoie `{ view, pages, canManage, exportUrl, exportNonce, preload, postTypes, plugins }` ;
    - `pageUrl( view, args = {} )`.
  - **`src/store/index.js`** :
    - `STORE_NAME = 'msradar/core'`, `createCoreStore( preload )`, `registerCoreStore( preload )` ;
    - `toResponse( body, headers )`, qui renvoie `{ data, total, totalPages }`, et `toError( error )`, qui renvoie `{ code, message, status }` ;
    - les sélecteurs `getResponse( path )` (avec résolveur) et `getError( path )` ;
    - les actions `receiveResponse( path, response )`, `receiveError( path, error )`, `invalidate( prefix )` et `retry( path )`.
  - **Hooks :**
    - `useResource( path )`, qui renvoie `{ data, total, totalPages, error, isLoading, isFresh, retry }`. Pendant le chargement d'un nouveau chemin, `data` garde la réponse précédente et `isFresh` vaut `false` ;
    - `useUrlState( parse, serialize )`, qui renvoie `[ state, setState ]` ;
    - `usePreferences()`, qui renvoie `{ prefs, save( view, patch ) }`, avec `PREFERENCES_PATH`, `DEFAULT_PREFERENCES` et `mergePreferences()`. Le hook `useDebouncedSave( save, view, delay = 500 )` accompagne `usePreferences()` ;
    - `useScan( { waitMs = 3000, maxWaits = 20 } = {} )`, qui renvoie `{ running, processed, total, remaining, deferred, start( request ) }`.
  - **Composants :**
    - `src/components/data-views/index.js` : `DataViews`, `DataForm`, `useFormValidity`, et la réexportation de `filters.js` ;
    - `src/components/data-views/filters.js` : `filterValue( filters, field, fallback )`, `toFilters( definitions )`, sans dépendance ;
    - `ErrorNotice` (`error`, `onRetry`), `Snackbars`, `ErrorBoundary` ;
    - `SeverityBadge` (`level`, `count`), `RegistryBadge` (`status`), `severityLabels()`.
  - **`src/utils/format.js`** : `parseGmt`, `formatDateTime`, `formatRelative`, `formatNumber`, `formatBytes`, `displayUrl`.
  - **`mount( View )`** enregistre le store hydraté par `getConfig().preload` et rend `<App><View /></App>` en `StrictMode`.

- [ ] **Step 1: Écrire les tests qui échouent**

`src/store/test/store.test.js` :

```js
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { createRegistry } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore, STORE_NAME } from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

function setup( preload = {} ) {
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	return registry;
}

function ok( body, headers = {} ) {
	return { json: async () => body, headers: new Map( Object.entries( headers ) ) };
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'msradar/core', () => {
	test( 'serves preloaded responses synchronously, whatever the encoding of the same path', () => {
		const registry = setup( {
			'/multisite-radar/v1/sites?search=O%27Brien&page=1': {
				body: [ { id: 1 } ],
				headers: { 'X-WP-Total': 1, 'X-WP-TotalPages': 1 },
			},
		} );

		expect(
			registry.select( STORE_NAME ).getResponse( "/multisite-radar/v1/sites?page=1&search=O'Brien" )
		).toEqual( { data: [ { id: 1 } ], total: 1, totalPages: 1 } );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'fetches a missing response once and keeps its pagination headers', async () => {
		apiFetch.mockResolvedValue( ok( [ { id: 2 } ], { 'X-WP-Total': '5', 'X-WP-TotalPages': '3' } ) );
		const registry = setup();
		const path = '/multisite-radar/v1/sites?page=2';

		expect( registry.select( STORE_NAME ).getResponse( path ) ).toBeNull();
		await registry.resolveSelect( STORE_NAME ).getResponse( path );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( { path, parse: false } );
		expect( registry.select( STORE_NAME ).getResponse( path ) ).toEqual( {
			data: [ { id: 2 } ],
			total: 5,
			totalPages: 3,
		} );
	} );

	test( 'stores REST errors and retries on demand', async () => {
		apiFetch.mockRejectedValueOnce( {
			json: async () => ( { code: 'msradar_storage_error', message: 'Boom', data: { status: 500 } } ),
		} );
		const registry = setup();
		const path = '/multisite-radar/v1/sites?page=1';

		await registry.resolveSelect( STORE_NAME ).getResponse( path );
		expect( registry.select( STORE_NAME ).getError( path ) ).toEqual( {
			code: 'msradar_storage_error',
			message: 'Boom',
			status: 500,
		} );

		apiFetch.mockResolvedValueOnce( ok( [] ) );
		registry.dispatch( STORE_NAME ).retry( path );
		await registry.resolveSelect( STORE_NAME ).getResponse( path );
		expect( registry.select( STORE_NAME ).getError( path ) ).toBeNull();
		expect( registry.select( STORE_NAME ).getResponse( path ).data ).toEqual( [] );
	} );

	test( 'network failures become readable errors', async () => {
		apiFetch.mockRejectedValueOnce( new TypeError( 'Failed to fetch' ) );
		const registry = setup();

		await registry.resolveSelect( STORE_NAME ).getResponse( '/multisite-radar/v1/settings' );

		expect( registry.select( STORE_NAME ).getError( '/multisite-radar/v1/settings' ).message ).toBe( 'Failed to fetch' );
	} );

	test( 'invalidate forgets a family of paths and refetches them on next read', async () => {
		apiFetch.mockResolvedValue( ok( [ { id: 9 } ] ) );
		const registry = setup( {
			'/multisite-radar/v1/sites?page=1': { body: [ { id: 1 } ], headers: {} },
			'/multisite-radar/v1/sites/1': { body: { id: 1 }, headers: {} },
			'/multisite-radar/v1/preferences': { body: { sites: {} }, headers: {} },
		} );

		registry.dispatch( STORE_NAME ).invalidate( '/multisite-radar/v1/sites' );

		expect( registry.select( STORE_NAME ).getResponse( '/multisite-radar/v1/preferences' ) ).not.toBeNull();
		await registry.resolveSelect( STORE_NAME ).getResponse( '/multisite-radar/v1/sites?page=1' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( registry.select( STORE_NAME ).getResponse( '/multisite-radar/v1/sites?page=1' ).data ).toEqual( [ { id: 9 } ] );
	} );
} );
```

`src/hooks/test/use-resource.test.jsx` :

```jsx
import { expect, test, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../store';
import { useResource } from '../use-resource';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

test( 'keeps the previous data while the next page loads', () => {
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/sites?page=1': { body: [ { id: 1 } ], headers: { 'X-WP-Total': '1' } },
		} )
	);
	const wrapper = ( { children } ) => (
		<RegistryProvider value={ registry }>{ children }</RegistryProvider>
	);

	const { result, rerender } = renderHook( ( { path } ) => useResource( path ), {
		initialProps: { path: '/multisite-radar/v1/sites?page=1' },
		wrapper,
	} );
	expect( result.current ).toMatchObject( { data: [ { id: 1 } ], total: 1, isLoading: false, isFresh: true } );

	rerender( { path: '/multisite-radar/v1/sites?page=2' } );
	expect( result.current ).toMatchObject( { data: [ { id: 1 } ], isLoading: true, isFresh: false } );

	rerender( { path: null } );
	expect( result.current.isLoading ).toBe( false );
} );
```

`src/hooks/test/use-url-state.test.jsx` :

```jsx
import { expect, test } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useUrlState } from '../use-url-state';

const parse = ( query ) => ( { search: query.s || '', page: Number( query.paged || 1 ) } );
const serialize = ( state ) => ( {
	...( state.search ? { s: state.search } : {} ),
	...( state.page > 1 ? { paged: String( state.page ) } : {} ),
} );

test( 'reads the address and writes changes back, keeping the WordPress page parameter', () => {
	window.history.replaceState( null, '', '/wp-admin/network/admin.php?page=multisite-radar-sites&s=blog%20rh&paged=2' );

	const { result } = renderHook( () => useUrlState( parse, serialize ) );
	expect( result.current[ 0 ] ).toEqual( { search: 'blog rh', page: 2 } );

	act( () => result.current[ 1 ]( { search: '', page: 1 } ) );
	expect( window.location.search ).toBe( '?page=multisite-radar-sites' );

	act( () => result.current[ 1 ]( { search: "O'Brien", page: 3 } ) );
	const query = new URLSearchParams( window.location.search );
	expect( query.get( 'page' ) ).toBe( 'multisite-radar-sites' );
	expect( query.get( 's' ) ).toBe( "O'Brien" );
	expect( query.get( 'paged' ) ).toBe( '3' );
} );
```

`src/hooks/test/use-scan.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { createCoreStore } from '../../store';
import { useScan } from '../use-scan';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

function setup() {
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( {} ) );
	const wrapper = ( { children } ) => (
		<RegistryProvider value={ registry }>{ children }</RegistryProvider>
	);
	const hook = renderHook( () => useScan( { waitMs: 0, maxWaits: 2 } ), { wrapper } );
	return { registry, hook };
}

function messages( registry ) {
	return registry.select( noticesStore ).getNotices().map( ( notice ) => notice.content );
}

function route( handlers ) {
	apiFetch.mockImplementation( ( { path } ) => {
		const handler = handlers[ path.replace( '/multisite-radar/v1', '' ) ];
		return handler();
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	speak.mockReset();
} );

test( 'marks the sites, then runs batches until none remains', async () => {
	const batches = [
		{ processed: 2, remaining: 1, locked: false },
		{ processed: 1, remaining: 0, locked: false },
	];
	route( {
		'/scan': async () => ( { remaining: 3, total: 3 } ),
		'/scan/batch': async () => batches.shift(),
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( { path: '/multisite-radar/v1/scan', method: 'POST', data: { scope: 'all' } } );
	expect( hook.result.current ).toMatchObject( { running: false, processed: 3, total: 3, remaining: 0, deferred: false } );
	expect( messages( registry ) ).toContain( 'Analysis complete.' );
	expect( speak ).toHaveBeenCalledWith( 'Analysis complete.' );
} );

test( 'gives up after repeated batches without progress (the cron holds the lock) and says so', async () => {
	route( {
		'/scan': async () => ( { remaining: 5, total: 5 } ),
		'/scan/batch': async () => ( { processed: 0, remaining: 5, locked: true } ),
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'dirty' } );
	} );

	expect( apiFetch ).toHaveBeenCalledTimes( 4 ); // POST /scan, puis maxWaits + 1 lots.
	expect( hook.result.current ).toMatchObject( { running: false, deferred: true, remaining: 5 } );
	expect( messages( registry ) ).toContain( 'The analysis continues in the background.' );
} );

test( 'reports a refused request', async () => {
	route( {
		'/scan': async () => {
			throw { code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.' };
		},
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( hook.result.current.running ).toBe( false );
	expect( messages( registry ) ).toContain( 'Sorry, you are not allowed to do that.' );
} );
```

`src/utils/test/format.test.js` :

```js
import { beforeAll, expect, test } from 'vitest';
import { displayUrl, formatBytes, formatNumber, formatRelative, parseGmt } from '../format';

beforeAll( () => {
	document.documentElement.lang = 'en-US';
} );

test( 'REST dates are read as UTC, with or without an offset', () => {
	expect( parseGmt( '2026-09-01T10:00:00' ).toISOString() ).toBe( '2026-09-01T10:00:00.000Z' );
	expect( parseGmt( '2026-09-01T12:00:00+02:00' ).toISOString() ).toBe( '2026-09-01T10:00:00.000Z' );
	expect( parseGmt( null ) ).toBeNull();
	expect( parseGmt( 'not a date' ) ).toBeNull();
} );

test( 'numbers and sizes', () => {
	expect( formatNumber( 12345 ) ).toBe( '12,345' );
	expect( formatNumber( null ) ).toBe( '—' );
	expect( formatBytes( 512 ) ).toBe( '512 B' );
	expect( formatBytes( 1536 ) ).toBe( '1.5 KB' );
	expect( formatBytes( 5 * 1024 ** 3 ) ).toBe( '5 GB' );
	expect( formatBytes( null ) ).toBe( '—' );
} );

test( 'relative dates and display URLs', () => {
	expect( formatRelative( '2026-09-01T10:00:00', new Date( '2026-09-04T10:00:00Z' ) ) ).toBe( '3 days ago' );
	expect( formatRelative( null ) ).toBe( '—' );
	expect( displayUrl( 'https://example.test/rh/' ) ).toBe( 'example.test/rh' );
} );
```

`src/components/test/components.test.jsx` :

```jsx
import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import ErrorNotice from '../error-notice';
import { RegistryBadge, SeverityBadge } from '../badges';

test( 'ErrorNotice shows the message and a Retry button', () => {
	const onRetry = vi.fn();
	render( <ErrorNotice error={ { message: 'Multisite Radar could not read its data.' } } onRetry={ onRetry } /> );

	expect( screen.getByText( 'Multisite Radar could not read its data.' ) ).toBeInTheDocument();
	fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
	expect( onRetry ).toHaveBeenCalledTimes( 1 );
} );

test( 'ErrorNotice renders nothing without an error', () => {
	const { container } = render( <ErrorNotice error={ null } /> );
	expect( container ).toBeEmptyDOMElement();
} );

test( 'badges', () => {
	render(
		<>
			<SeverityBadge level="error" count={ 2 } />
			<SeverityBadge level="none" count={ 0 } />
			<RegistryBadge status="stale" />
			<RegistryBadge status="fresh" />
		</>
	);

	expect( screen.getByText( 'Error · 2 alerts' ) ).toHaveClass( 'msradar-badge--error' );
	expect( screen.getByText( 'No alert' ) ).toHaveClass( 'msradar-badge--none' );
	expect( screen.getAllByText( 'Not verified' ) ).toHaveLength( 1 );
} );
```

`src/components/data-views/test/data-views.test.js` :

```js
import { expect, test } from 'vitest';
import { filterValue, toFilters } from '../filters';

test( 'toFilters drops empty values and filterValue reads them back', () => {
	const filters = toFilters( [
		{ field: 'alert_level', operator: 'isAny', value: [ 'error' ] },
		{ field: 'status', operator: 'isAny', value: [] },
		{ field: 'rule', operator: 'is', value: '' },
		{ field: 'registry_status', operator: 'isAny', value: null },
	] );

	expect( filters ).toEqual( [ { field: 'alert_level', operator: 'isAny', value: [ 'error' ] } ] );
	expect( filterValue( filters, 'alert_level', [] ) ).toEqual( [ 'error' ] );
	expect( filterValue( filters, 'rule', '' ) ).toBe( '' );
	expect( filterValue( undefined, 'rule', 'x' ) ).toBe( 'x' );
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npm run test:unit`
Expected: FAIL (modules introuvables : `../use-resource`, `..` du store, etc.).

- [ ] **Step 3: Implémenter**

`src/admin/config.js` :

```js
import { addQueryArgs } from '@wordpress/url';

const DEFAULTS = {
	view: 'overview',
	pages: {},
	canManage: false,
	exportUrl: '',
	exportNonce: '',
	preload: {},
	postTypes: [],
	plugins: [],
};

/**
 * Configuration injectée par PHP (Admin\Assets::config()) dans window.msradarAdmin.
 */
export function getConfig() {
	return { ...DEFAULTS, ...( window.msradarAdmin || {} ) };
}

/**
 * URL d'une page de l'application, avec ses paramètres de vue.
 *
 * @param {string} view overview, sites, alerts ou settings.
 * @param {Object} args Paramètres d'URL de la vue.
 */
export function pageUrl( view, args = {} ) {
	const base = getConfig().pages[ view ];
	return base ? addQueryArgs( base, args ) : '';
}
```

`src/store/index.js` :

```js
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { normalizePath } from './paths';

/**
 * Cache des réponses REST de l'application, indexé par chemin canonique (normalizePath).
 * L'état initial vient des données préchargées par PHP : la première vue s'affiche sans requête.
 */
export const STORE_NAME = 'msradar/core';

function headerValue( headers, name ) {
	const wanted = name.toLowerCase();
	const key = Object.keys( headers || {} ).find( ( k ) => k.toLowerCase() === wanted );
	if ( key === undefined ) {
		return null;
	}
	const value = parseInt( headers[ key ], 10 );
	return Number.isNaN( value ) ? null : value;
}

export function toResponse( body, headers = {} ) {
	return {
		data: body,
		total: headerValue( headers, 'X-WP-Total' ),
		totalPages: headerValue( headers, 'X-WP-TotalPages' ),
	};
}

export function toError( error ) {
	return {
		code: error?.code || 'msradar_request_failed',
		message:
			error?.message ||
			__( 'The request failed. Check your connection and try again.', 'multisite-radar' ),
		status: error?.data?.status ?? null,
	};
}

export function initialState( preload = {} ) {
	const responses = {};
	Object.entries( preload || {} ).forEach( ( [ path, entry ] ) => {
		if ( entry && typeof entry === 'object' && 'body' in entry ) {
			responses[ normalizePath( path ) ] = toResponse( entry.body, entry.headers );
		}
	} );
	return { responses, errors: {} };
}

function without( map, test ) {
	return Object.fromEntries( Object.entries( map ).filter( ( [ key ] ) => ! test( key ) ) );
}

function reducer( state, action ) {
	switch ( action.type ) {
		case 'RECEIVE_RESPONSE':
			return {
				responses: { ...state.responses, [ action.key ]: action.response },
				errors: without( state.errors, ( key ) => key === action.key ),
			};
		case 'RECEIVE_ERROR':
			return { ...state, errors: { ...state.errors, [ action.key ]: action.error } };
		case 'FORGET_PREFIX':
			return {
				responses: without( state.responses, ( key ) => key.startsWith( action.prefix ) ),
				errors: without( state.errors, ( key ) => key.startsWith( action.prefix ) ),
			};
		case 'FORGET_KEY':
			return {
				responses: without( state.responses, ( key ) => key === action.key ),
				errors: without( state.errors, ( key ) => key === action.key ),
			};
		default:
			return state;
	}
}

async function readError( error ) {
	// Avec parse: false, apiFetch rejette la réponse HTTP elle-même : son corps porte { code, message, data }.
	if ( error && typeof error.json === 'function' ) {
		try {
			return await error.json();
		} catch {
			return {};
		}
	}
	return error;
}

const actions = {
	receiveResponse: ( path, response ) => ( {
		type: 'RECEIVE_RESPONSE',
		key: normalizePath( path ),
		response,
	} ),
	receiveError: ( path, error ) => ( {
		type: 'RECEIVE_ERROR',
		key: normalizePath( path ),
		error,
	} ),
	invalidate:
		( prefix ) =>
		( { dispatch } ) => {
			dispatch( { type: 'FORGET_PREFIX', prefix } );
			dispatch.invalidateResolutionForStoreSelector( 'getResponse' );
		},
	retry:
		( path ) =>
		( { dispatch } ) => {
			dispatch( { type: 'FORGET_KEY', key: normalizePath( path ) } );
			dispatch.invalidateResolution( 'getResponse', [ path ] );
		},
};

const selectors = {
	getResponse: ( state, path ) =>
		path ? state.responses[ normalizePath( path ) ] ?? null : null,
	getError: ( state, path ) => ( path ? state.errors[ normalizePath( path ) ] ?? null : null ),
};

const resolvers = {
	getResponse: {
		isFulfilled: ( state, path ) =>
			! path || state.responses[ normalizePath( path ) ] !== undefined,
		fulfill:
			( path ) =>
			async ( { dispatch } ) => {
				try {
					const response = await apiFetch( { path, parse: false } );
					const body = await response.json();
					dispatch.receiveResponse(
						path,
						toResponse( body, Object.fromEntries( response.headers.entries() ) )
					);
				} catch ( error ) {
					dispatch.receiveError( path, toError( await readError( error ) ) );
				}
			},
	},
};

export function createCoreStore( preload = {} ) {
	const defaultState = initialState( preload );
	return createReduxStore( STORE_NAME, {
		reducer: ( state = defaultState, action ) => reducer( state, action ),
		actions,
		selectors,
		resolvers,
	} );
}

export function registerCoreStore( preload = {} ) {
	const store = createCoreStore( preload );
	register( store );
	return store;
}
```

`src/hooks/use-resource.js` :

```js
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useRef } from '@wordpress/element';
import { STORE_NAME } from '../store';

/**
 * Réponse d'une route REST, servie par le cache du store (préchargée par PHP, ou demandée une seule fois).
 * Pendant le chargement d'un nouveau chemin, la réponse précédente reste affichée (pas de scintillement) :
 * isFresh indique si data correspond bien au chemin demandé.
 *
 * @param {?string} path Chemin construit par buildPath(), ou null tant qu'il n'est pas connu.
 */
export function useResource( path ) {
	const { response, error } = useSelect(
		( select ) => ( {
			response: select( STORE_NAME ).getResponse( path ),
			error: select( STORE_NAME ).getError( path ),
		} ),
		[ path ]
	);
	const last = useRef( null );
	if ( response ) {
		last.current = response;
	}
	const { retry } = useDispatch( STORE_NAME );
	const onRetry = useCallback( () => {
		if ( path ) {
			retry( path );
		}
	}, [ path, retry ] );

	const shown = response || ( error ? null : last.current );
	return {
		data: shown ? shown.data : null,
		total: shown ? shown.total : null,
		totalPages: shown ? shown.totalPages : null,
		error,
		isLoading: !! path && ! response && ! error,
		isFresh: !! response,
		retry: onRetry,
	};
}
```

`src/hooks/use-url-state.js` :

```js
import { useEffect, useRef, useState } from '@wordpress/element';
import { addQueryArgs, getQueryArgs } from '@wordpress/url';

export function readQuery() {
	return getQueryArgs( window.location.href );
}

/**
 * État d'une vue synchronisé avec l'adresse de la page (recherche, filtres, tri, page, présentation, site ouvert) :
 * un lien copié ou un rechargement rouvre la même vue. Le paramètre « page » de WordPress est conservé.
 *
 * @param {Function} parse     Paramètres d'URL → état.
 * @param {Function} serialize État → paramètres d'URL, sans les valeurs par défaut.
 */
export function useUrlState( parse, serialize ) {
	const [ state, setState ] = useState( () => parse( readQuery() ) );
	const serializer = useRef( serialize );
	serializer.current = serialize;

	useEffect( () => {
		const { page } = readQuery();
		const next = addQueryArgs( window.location.pathname, {
			...( page ? { page } : {} ),
			...serializer.current( state ),
		} );
		if ( next !== window.location.pathname + window.location.search ) {
			window.history.replaceState( window.history.state, '', next );
		}
	}, [ state ] );

	return [ state, setState ];
}
```

`src/hooks/use-preferences.js` :

```js
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { STORE_NAME, toResponse } from '../store';
import { buildPath } from '../store/paths';
import { useResource } from './use-resource';

export const PREFERENCES_PATH = buildPath( '/preferences' );

export const DEFAULT_PREFERENCES = {
	sites: { fields: [], layout: 'table', per_page: 20 },
	alerts: { fields: [], per_page: 20 },
};

export function mergePreferences( stored ) {
	const value = stored && typeof stored === 'object' ? stored : {};
	return {
		sites: { ...DEFAULT_PREFERENCES.sites, ...( value.sites || {} ) },
		alerts: { ...DEFAULT_PREFERENCES.alerts, ...( value.alerts || {} ) },
	};
}

/**
 * Préférences d'affichage de l'utilisateur. Une lecture en échec donne les valeurs par défaut ; un enregistrement
 * en échec est silencieux : la vue affichée reste telle que l'utilisateur l'a réglée.
 */
export function usePreferences() {
	const { data, error } = useResource( PREFERENCES_PATH );
	const { receiveResponse } = useDispatch( STORE_NAME );

	const save = useCallback(
		async ( view, patch ) => {
			try {
				const saved = await apiFetch( {
					path: PREFERENCES_PATH,
					method: 'POST',
					data: { [ view ]: patch },
				} );
				receiveResponse( PREFERENCES_PATH, toResponse( saved ) );
			} catch {
				// Sans conséquence pour la vue affichée.
			}
		},
		[ receiveResponse ]
	);

	const prefs = useMemo( () => {
		if ( data ) {
			return mergePreferences( data );
		}
		return error ? mergePreferences( {} ) : null;
	}, [ data, error ] );

	return { prefs, save };
}

/**
 * Enregistre les préférences d'une vue après un temps de repos (redimensionnement de colonnes, clics répétés).
 *
 * @param {Function} save  save() de usePreferences().
 * @param {string}   view  sites ou alerts.
 * @param {number}   delay Délai en millisecondes.
 */
export function useDebouncedSave( save, view, delay = 500 ) {
	const timer = useRef();
	useEffect( () => () => clearTimeout( timer.current ), [] );
	return useCallback(
		( patch ) => {
			clearTimeout( timer.current );
			timer.current = setTimeout( () => save( view, patch ), delay );
		},
		[ save, view, delay ]
	);
}
```

`src/hooks/use-scan.js` :

```js
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import { buildPath, NAMESPACE } from '../store/paths';

const IDLE = { running: false, processed: 0, total: 0, remaining: 0, deferred: false };
const SPEAK_EVERY_MS = 5000;

function wait( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * Analyse pilotée par l'interface : POST /scan marque les sites, puis POST /scan/batch traite des lots bornés
 * jusqu'à ce qu'il n'en reste plus. Un lot sans progrès (le cron détient le verrou) fait attendre puis réessayer ;
 * après maxWaits essais sans progrès, l'interface s'arrête et annonce que l'analyse continue en arrière-plan.
 *
 * @param {Object} options          Réglages (raccourcis par les tests).
 * @param {number} options.waitMs   Attente entre deux lots sans progrès.
 * @param {number} options.maxWaits Lots consécutifs sans progrès avant d'abandonner.
 */
export function useScan( { waitMs = 3000, maxWaits = 20 } = {} ) {
	const [ progress, setProgress ] = useState( IDLE );
	const mounted = useRef( true );
	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );
	const { invalidate } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createInfoNotice, createErrorNotice } = useDispatch( noticesStore );

	const update = useCallback( ( patch ) => {
		if ( mounted.current ) {
			setProgress( ( current ) => ( { ...current, ...patch } ) );
		}
	}, [] );

	const start = useCallback(
		async ( request ) => {
			update( { ...IDLE, running: true } );
			speak( __( 'Analysis started.', 'multisite-radar' ) );
			try {
				let status = await apiFetch( {
					path: buildPath( '/scan' ),
					method: 'POST',
					data: request,
				} );
				let processed = 0;
				let total = status.remaining;
				let waits = 0;
				let spokenAt = Date.now();
				update( { total, remaining: status.remaining } );

				while ( mounted.current && status.remaining > 0 && waits <= maxWaits ) {
					const batch = await apiFetch( { path: buildPath( '/scan/batch' ), method: 'POST' } );
					processed += batch.processed;
					total = Math.max( total, processed + batch.remaining );
					status = batch;
					update( { processed, total, remaining: batch.remaining } );
					if ( batch.processed > 0 ) {
						waits = 0;
					} else {
						waits += 1;
						await wait( waitMs );
					}
					if ( Date.now() - spokenAt >= SPEAK_EVERY_MS ) {
						speak(
							sprintf(
								/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
								__( '%1$d of %2$d sites analysed.', 'multisite-radar' ),
								processed,
								total
							)
						);
						spokenAt = Date.now();
					}
				}

				invalidate( NAMESPACE );
				const deferred = status.remaining > 0;
				const message = deferred
					? __( 'The analysis continues in the background.', 'multisite-radar' )
					: __( 'Analysis complete.', 'multisite-radar' );
				( deferred ? createInfoNotice : createSuccessNotice )( message, { type: 'snackbar' } );
				speak( message );
				update( { running: false, deferred } );
			} catch ( error ) {
				createErrorNotice(
					error?.message || __( 'The analysis could not be run.', 'multisite-radar' ),
					{ type: 'snackbar' }
				);
				update( { running: false } );
			}
		},
		[ update, waitMs, maxWaits, invalidate, createSuccessNotice, createInfoNotice, createErrorNotice ]
	);

	return { ...progress, start };
}
```

`src/components/data-views/index.js` :

```js
/**
 * Seul point d'accès à @wordpress/dataviews (version épinglée, spec §6.3) :
 * une montée de version ne touche que ce dossier.
 */
export { DataViews, DataForm, useFormValidity } from '@wordpress/dataviews/wp';
export { filterValue, toFilters } from './filters';
```

`src/components/data-views/filters.js` :

```js
/**
 * Filtres DataViews ↔ valeurs simples. Sans dépendance : la logique des vues se teste sans charger DataViews.
 */
function isEmpty( value ) {
	return value === undefined || value === null || value === '' || ( Array.isArray( value ) && value.length === 0 );
}

/**
 * Valeur d'un filtre de la vue DataViews, ou la valeur de repli s'il est absent ou vide.
 *
 * @param {Array}  filters  view.filters.
 * @param {string} field    Identifiant du champ.
 * @param {*}      fallback Valeur de repli.
 */
export function filterValue( filters, field, fallback ) {
	const filter = ( filters || [] ).find( ( item ) => item.field === field );
	return ! filter || isEmpty( filter.value ) ? fallback : filter.value;
}

/**
 * Filtres DataViews à partir de valeurs simples ; les valeurs vides sont omises.
 *
 * @param {Array} definitions Liste de { field, operator, value }.
 */
export function toFilters( definitions ) {
	return definitions
		.filter( ( definition ) => ! isEmpty( definition.value ) )
		.map( ( { field, operator, value } ) => ( { field, operator, value } ) );
}
```

`src/components/error-notice.jsx` :

```jsx
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Erreur REST avec un bouton « Retry ».
 *
 * @param {Object}    props
 * @param {?Object}   props.error   { message } renvoyé par le store.
 * @param {?Function} props.onRetry Relance de la requête.
 */
export default function ErrorNotice( { error, onRetry } ) {
	if ( ! error ) {
		return null;
	}
	const actions = onRetry ? [ { label: __( 'Retry', 'multisite-radar' ), onClick: onRetry } ] : [];
	return (
		<Notice status="error" isDismissible={ false } actions={ actions } className="msradar-error">
			{ error.message }
		</Notice>
	);
}
```

`src/components/snackbars.jsx` :

```jsx
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

export default function Snackbars() {
	const all = useSelect( ( select ) => select( noticesStore ).getNotices(), [] );
	const notices = useMemo( () => all.filter( ( notice ) => notice.type === 'snackbar' ), [ all ] );
	const { removeNotice } = useDispatch( noticesStore );
	return <SnackbarList notices={ notices } className="msradar-snackbars" onRemove={ removeNotice } />;
}
```

`src/components/error-boundary.jsx` :

```jsx
import { Component } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	render() {
		if ( this.state.error ) {
			return (
				<Notice status="error" isDismissible={ false }>
					{ __( 'This page could not be displayed. Reload it; if the problem persists, check the browser console.', 'multisite-radar' ) }
				</Notice>
			);
		}
		return this.props.children;
	}
}
```

`src/components/badges.jsx` :

```jsx
import { __, _n, sprintf } from '@wordpress/i18n';

export function severityLabels() {
	return {
		error: __( 'Error', 'multisite-radar' ),
		warning: __( 'Warning', 'multisite-radar' ),
		info: __( 'Info', 'multisite-radar' ),
		none: __( 'No alert', 'multisite-radar' ),
	};
}

/**
 * Gravité maximale d'un site (ou d'une alerte), avec le nombre d'alertes.
 *
 * @param {Object} props
 * @param {string} props.level error, warning, info ou none.
 * @param {number} props.count Nombre d'alertes (0 : non affiché).
 */
export function SeverityBadge( { level, count = 0 } ) {
	const labels = severityLabels();
	const known = labels[ level ] ? level : 'none';
	const text =
		count > 0
			? sprintf(
					/* translators: 1: severity label, 2: number of alerts. */
					_n( '%1$s · %2$d alert', '%1$s · %2$d alerts', count, 'multisite-radar' ),
					labels[ known ],
					count
			  )
			: labels[ known ];
	return <span className={ `msradar-badge msradar-badge--${ known }` }>{ text }</span>;
}

/**
 * Badge « Not verified » quand les types de contenu n'ont pas été relevés dans le contexte du site (spec §3.4).
 *
 * @param {Object} props
 * @param {string} props.status fresh, stale ou missing.
 */
export function RegistryBadge( { status } ) {
	if ( status !== 'stale' && status !== 'missing' ) {
		return null;
	}
	return (
		<span className="msradar-badge msradar-badge--unverified">
			{ __( 'Not verified', 'multisite-radar' ) }
		</span>
	);
}
```

`src/utils/format.js` :

```js
import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

const EMPTY = '—';

/**
 * Les champs REST *_gmt n'ont pas de décalage (convention du cœur) : ils sont en UTC.
 *
 * @param {?string} value Date REST.
 */
export function parseGmt( value ) {
	if ( ! value ) {
		return null;
	}
	const iso = /(Z|[+-]\d{2}:\d{2})$/.test( value ) ? value : `${ value }Z`;
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? null : date;
}

export function formatDateTime( value ) {
	const date = parseGmt( value );
	return date ? dateI18n( getSettings().formats.datetime, date ) : EMPTY;
}

export function formatRelative( value, now = new Date() ) {
	const date = parseGmt( value );
	return date ? humanTimeDiff( date, now ) : EMPTY;
}

function locale() {
	return document.documentElement.lang || undefined;
}

export function formatNumber( value ) {
	return value === null || value === undefined ? EMPTY : new Intl.NumberFormat( locale() ).format( value );
}

export function formatBytes( bytes ) {
	if ( bytes === null || bytes === undefined ) {
		return EMPTY;
	}
	const units = [
		/* translators: %s: size in bytes. */
		__( '%s B', 'multisite-radar' ),
		/* translators: %s: size in kilobytes. */
		__( '%s KB', 'multisite-radar' ),
		/* translators: %s: size in megabytes. */
		__( '%s MB', 'multisite-radar' ),
		/* translators: %s: size in gigabytes. */
		__( '%s GB', 'multisite-radar' ),
		/* translators: %s: size in terabytes. */
		__( '%s TB', 'multisite-radar' ),
	];
	let value = Number( bytes );
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit += 1;
	}
	return sprintf(
		units[ unit ],
		new Intl.NumberFormat( locale(), { maximumFractionDigits: unit === 0 ? 0 : 1 } ).format( value )
	);
}

export function displayUrl( url ) {
	return ( url || '' ).replace( /^https?:\/\//, '' ).replace( /\/$/, '' );
}
```

`src/admin/app.jsx` :

```jsx
import ErrorBoundary from '../components/error-boundary';
import Snackbars from '../components/snackbars';

export default function App( { children } ) {
	return (
		<ErrorBoundary>
			<div className="msradar-app__view">{ children }</div>
			<Snackbars />
		</ErrorBoundary>
	);
}
```

Remplacer `src/admin/mount.jsx` par :

```jsx
import { createRoot, StrictMode } from '@wordpress/element';
import { registerCoreStore } from '../store';
import App from './app';
import { getConfig } from './config';

/**
 * Monte une vue dans le conteneur rendu par PHP (Admin\Menu::render()), avec le store hydraté par les données
 * préchargées : la première vue s'affiche sans requête.
 *
 * @param {Function} View Composant de la vue.
 */
export function mount( View ) {
	const container = document.getElementById( 'msradar-app' );
	if ( ! container ) {
		return;
	}
	registerCoreStore( getConfig().preload );
	createRoot( container ).render(
		<StrictMode>
			<App>
				<View />
			</App>
		</StrictMode>
	);
}
```

Remplacer `src/admin/style.scss` par :

```scss
.msradar-app {
	margin-top: 16px;
}

.msradar-error {
	margin: 0 0 16px;
}

.msradar-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 2px;
	background: #f0f0f0;
	color: #1e1e1e;
	font-size: 12px;
	line-height: 1.5;
	white-space: nowrap;

	&--error {
		background: #fcf0f1;
		color: #8a2424;
	}

	&--warning {
		background: #fcf9e8;
		color: #6d4c00;
	}

	&--info {
		background: #f0f6fc;
		color: #0a4b78;
	}

	&--unverified {
		border: 1px dashed #8c8f94;
		background: #f6f7f7;
		color: #50575e;
	}
}

// En bas à gauche, à droite du menu d'administration, comme les notices de l'éditeur.
.msradar-snackbars {
	position: fixed;
	bottom: 24px;
	left: 184px;
	z-index: 100000;

	.folded & {
		left: 60px;
	}

	@media (max-width: 782px) {
		right: 16px;
		left: 16px;
	}
}
```

Les couleurs des badges atteignent un contraste supérieur à 4,5:1 (WCAG 2.2 AA).

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS.

Si un test de composant échoue parce qu'une API du navigateur manque dans jsdom, ajouter le bouchon minimal dans `tests/js/setup.mjs` et le signaler dans le rapport. Ne jamais affaiblir l'assertion.

- [ ] **Step 5: Lint, build et commit**

Run: `npm run lint:js && npm run lint:css && npm run build`
Expected: aucune erreur.

```bash
git add src
git commit -m "feat: application foundations (REST cache store hydrated by preloading, URL state, preferences, scan runner)"
```

---
## Partie D — Vues

### Task 14: Vue Sites — DataViews, état dans l'URL, préférences, actions, exports

**Files:**
- Create: `src/utils/view-query.js`, `src/views/sites/query.js`, `src/views/sites/labels.js`, `src/views/sites/fields.jsx`, `src/views/sites/actions.js`, `src/views/sites/export.js`, `src/views/sites/export-menu.jsx`, `src/views/sites/index.jsx`
- Modify: `src/admin/sites.js`, `src/admin/style.scss`
- Test: `src/views/sites/test/query.test.js`, `src/views/sites/test/export.test.js`, `src/views/sites/test/sites-view.test.jsx`

**Interfaces:**
- Consumes :
  - le socle de la tâche 13 ;
  - REST `GET /sites` (tâches 3 et 4), `GET /alerts/summary` (`by_rule[].label`) et `POST /scan` (`scope=ids`) ;
  - l'export de la tâche 8 ;
  - `tests/fixtures/view-queries.json` (tâche 12).
- Produces :
  - **`src/utils/view-query.js`** (même règle que `Admin\ViewQuery`) :
    - les constantes `PER_PAGE`, `MAX_PAGE` et `RULE_PATTERN` ;
    - les fonctions `text( query, key )`, `trimAscii( value )`, `page( query )`, `perPage( value )` et `subset( query, key, allowed )`.
  - **`src/views/sites/query.js`** :
    - les constantes `ORDERBY`, `ALERT_LEVELS`, `STATUSES`, `REGISTRY_STATUSES`, `LAYOUTS`, `FIELD_TO_ORDERBY`, `ORDERBY_TO_FIELD` et `DEFAULT_FIELDS` ;
    - les fonctions `parseSitesQuery( query )`, `sitesRestArgs( state, prefs )`, `sitesPath( state, prefs )`, `serializeSitesState( state )`, `toSitesView( state, sitesPrefs )`, `fromSitesView( view, current )` et `sitesPrefsFromView( view )`.
  - **Libellés et champs :**
    - `labels.js` : `statusLabels()`, `registryLabels()`, `siteStatuses( site )` et `originLabel( origin )`, réutilisés par la fiche ;
    - `fields.jsx` : `getSitesFields( rules )`, `SiteTitle` et `DateCell`.
  - **Actions et export :**
    - `actions.js` : `getSitesActions( { canManage, onOpen, onRescan, onExport } )` ;
    - `export.js` : `exportColumns( fields )` et `exportUrl( format, state, fields, include = [] )`.
  - **`index.jsx`** : le composant par défaut `SitesView`. `state.site` vaut l'ID du site ouvert, sinon 0 ; la tâche 15 y branche le panneau.

- [ ] **Step 1: Écrire les tests qui échouent**

`src/views/sites/test/query.test.js` :

```js
import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	DEFAULT_FIELDS,
	fromSitesView,
	parseSitesQuery,
	serializeSitesState,
	sitesPath,
	sitesPrefsFromView,
	sitesRestArgs,
	toSitesView,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'sites' ) )( '$name', ( { query, prefs, args } ) => {
	test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
		expect( sitesRestArgs( parseSitesQuery( query ), prefs ) ).toEqual( args );
	} );
} );

describe( 'sites view state', () => {
	const state = parseSitesQuery( {
		s: 'blog',
		orderby: 'last_activity',
		order: 'desc',
		paged: '2',
		alert_level: 'error',
		status: 'archived,spam',
		rule: 'no_users',
		layout: 'grid',
		site: '7',
	} );

	test( 'the path is canonical', () => {
		expect( sitesPath( state, { sites: { per_page: 50 } } ) ).toBe(
			'/multisite-radar/v1/sites?alert_level=error&order=desc&orderby=last_activity&page=2&per_page=50&rule=no_users&search=blog&status=archived%2Cspam'
		);
	} );

	test( 'the DataViews view reflects the state and the preferences', () => {
		const view = toSitesView( state, { fields: [ 'theme' ], layout: 'table', per_page: 50 } );

		expect( view ).toMatchObject( {
			type: 'grid',
			search: 'blog',
			page: 2,
			perPage: 50,
			sort: { field: 'last_activity_gmt', direction: 'desc' },
			titleField: 'name',
			fields: [ 'theme' ],
		} );
		expect( view.filters ).toEqual( [
			{ field: 'alert_level', operator: 'isAny', value: [ 'error' ] },
			{ field: 'status', operator: 'isAny', value: [ 'archived', 'spam' ] },
			{ field: 'rule', operator: 'is', value: 'no_users' },
		] );
		expect( toSitesView( parseSitesQuery( {} ), null ).fields ).toEqual( DEFAULT_FIELDS );
	} );

	test( 'a DataViews change maps back to the state, keeping the open site', () => {
		const next = fromSitesView(
			{
				type: 'table',
				search: '  rh ',
				page: 1,
				perPage: 20,
				sort: { field: 'users_count', direction: 'asc' },
				filters: [
					{ field: 'registry_status', operator: 'isAny', value: [ 'missing', 'fresh' ] },
					{ field: 'rule', operator: 'is', value: 'Bad Rule' },
				],
				fields: [ 'theme', 'users_count' ],
			},
			state
		);

		expect( next ).toEqual( {
			search: 'rh',
			page: 1,
			orderby: 'users_count',
			order: 'asc',
			alert_level: [],
			status: [],
			registry_status: [ 'fresh', 'missing' ],
			rule: '',
			layout: 'table',
			site: 7,
		} );
	} );

	test( 'the address only keeps what differs from the defaults', () => {
		expect( serializeSitesState( parseSitesQuery( {} ) ) ).toEqual( {} );
		expect( serializeSitesState( state ) ).toEqual( {
			s: 'blog',
			paged: '2',
			orderby: 'last_activity',
			order: 'desc',
			alert_level: 'error',
			status: 'archived,spam',
			rule: 'no_users',
			layout: 'grid',
			site: '7',
		} );
	} );

	test( 'preferences come from the view', () => {
		expect( sitesPrefsFromView( { type: 'grid', perPage: 50, fields: [ 'theme' ] } ) ).toEqual( {
			fields: [ 'theme' ],
			layout: 'grid',
			per_page: 50,
		} );
		expect( sitesPrefsFromView( { type: 'list', perPage: 7 } ) ).toEqual( {
			fields: [],
			layout: 'table',
			per_page: 20,
		} );
	} );
} );
```

`src/views/sites/test/export.test.js` :

```js
import { beforeEach, expect, test } from 'vitest';
import { exportColumns, exportUrl } from '../export';
import { parseSitesQuery } from '../query';

beforeEach( () => {
	window.msradarAdmin = {
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'nonce123',
	};
} );

test( 'export columns follow the visible fields', () => {
	expect( exportColumns( [ 'users_count', 'alert_level', 'rule' ] ) ).toEqual( [
		'id',
		'name',
		'url',
		'users_count',
		'admins_count',
		'alert_level',
		'alerts_count',
		'alert_rules',
	] );
} );

test( 'the export URL carries the nonce, the filters, the columns and the selection', () => {
	const state = parseSitesQuery( { s: "O'Brien", alert_level: 'error', orderby: 'users_count', order: 'desc', paged: '3' } );

	const url = new URL( exportUrl( 'csv', state, [ 'theme' ], [ 3, 1 ] ) );

	expect( url.origin + url.pathname ).toBe( 'https://example.test/wp-admin/admin-post.php' );
	expect( Object.fromEntries( url.searchParams ) ).toEqual( {
		action: 'msradar_export',
		_wpnonce: 'nonce123',
		resource: 'sites',
		format: 'csv',
		fields: 'id,name,url,theme',
		orderby: 'users_count',
		order: 'desc',
		search: "O'Brien",
		alert_level: 'error',
		include: '3,1',
	} );
} );
```

`src/views/sites/test/sites-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import { getSitesActions } from '../actions';
import SitesView from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn( () => new Promise( () => {} ) ) } ) );

const PREFS = { sites: { fields: [], layout: 'table', per_page: 20 }, alerts: { fields: [], per_page: 20 } };

function site( id, name, level = 'none' ) {
	return {
		id,
		name,
		url: `https://example.test/${ id }/`,
		admin_url: `https://example.test/${ id }/wp-admin/`,
		status: { public: true, archived: false, spam: false, deleted: false },
		theme: { stylesheet: 'twentytwentyfive', template: 'twentytwentyfive' },
		users_count: level === 'none' ? 1 : 0,
		admins_count: level === 'none' ? 1 : 0,
		content_count: 3,
		media_count: 0,
		disk_bytes: null,
		db_bytes: null,
		autoload_bytes: null,
		last_activity_gmt: '2026-09-01T10:00:00',
		alert_level: level,
		alerts_count: level === 'none' ? 0 : 1,
		alert_rules: level === 'none' ? [] : [ 'no_users' ],
		registry_status: 'fresh',
		pending: false,
		dirty: false,
		scanned_at_gmt: '2026-09-02T10:00:00',
	};
}

function renderView() {
	const preload = {
		'/multisite-radar/v1/preferences': { body: PREFS, headers: {} },
		'/multisite-radar/v1/alerts/summary': {
			body: {
				total_sites: 2,
				scanned_sites: 2,
				pending_sites: 0,
				sites_with_alerts: 1,
				by_severity: { error: 1, warning: 0, info: 0 },
				by_rule: [ { rule: 'no_users', label: 'Site without users', severity: 'error', enabled: true, count: 1 } ],
			},
			headers: {},
		},
		'/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20': {
			body: [ site( 1, 'Blog RH' ), site( 2, 'Site vide', 'error' ) ],
			headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
		},
	};
	window.msradarAdmin = {
		view: 'sites',
		canManage: true,
		pages: {},
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'n',
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	return render(
		<RegistryProvider value={ registry }>
			<SitesView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState( null, '', '/wp-admin/network/admin.php?page=multisite-radar-sites' );
} );

test( 'renders the preloaded list without any request (spec 1.4, criterion 2)', () => {
	renderView();

	expect( screen.getByText( 'Blog RH' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Site vide' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Error · 1 alert' ) ).toBeInTheDocument();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'opening a site writes it to the address', () => {
	renderView();

	fireEvent.click( screen.getByText( 'Blog RH' ) );

	expect( new URLSearchParams( window.location.search ).get( 'site' ) ).toBe( '1' );
} );

test( 'only managers can start an analysis', () => {
	const ids = ( canManage ) =>
		getSitesActions( { canManage, onOpen() {}, onRescan() {}, onExport() {} } ).map( ( action ) => action.id );

	expect( ids( true ) ).toEqual( [ 'open', 'admin', 'visit', 'export', 'rescan' ] );
	expect( ids( false ) ).toEqual( [ 'open', 'admin', 'visit', 'export' ] );
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/views/sites`
Expected: FAIL (`Failed to resolve import "../query"`).

- [ ] **Step 3: Implémenter**

`src/utils/view-query.js` :

```js
/**
 * Lecture des paramètres d'URL des vues, avec exactement les règles de PHP (Admin\ViewQuery) :
 * la requête préchargée par PHP doit être celle que le client construit. tests/fixtures/view-queries.json
 * vérifie la parité des deux côtés.
 */
export const PER_PAGE = [ 10, 20, 50, 100 ];
export const MAX_PAGE = 100000;
export const RULE_PATTERN = /^[a-z0-9_]{1,40}$/;

export function text( query, key ) {
	const value = query?.[ key ];
	return typeof value === 'string' || Number.isInteger( value ) ? String( value ) : '';
}

/**
 * Comme trim() de PHP : espaces, tabulations, retours, NUL et tabulation verticale seulement
 * (une espace insécable est conservée, contrairement à String.prototype.trim()).
 *
 * @param {string} value Texte.
 */
export function trimAscii( value ) {
	// eslint-disable-next-line no-control-regex -- the same characters as PHP's trim().
	return value.replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );
}

/**
 * Chiffres seulement (« 1e3 » n'est pas une page), bornés à MAX_PAGE.
 *
 * @param {Object} query Paramètres d'URL.
 */
export function page( query ) {
	const value = text( query, 'paged' );
	if ( ! /^\d+$/.test( value ) ) {
		return 1;
	}
	return Math.max( 1, Math.min( MAX_PAGE, Number( value ) ) );
}

export function perPage( value ) {
	return PER_PAGE.includes( value ) ? value : 20;
}

/**
 * Valeurs autorisées présentes dans une liste séparée par des virgules, dans l'ordre de allowed.
 *
 * @param {Object}   query   Paramètres d'URL.
 * @param {string}   key     Paramètre.
 * @param {string[]} allowed Valeurs autorisées, dans l'ordre canonique.
 */
export function subset( query, key, allowed ) {
	const given = text( query, key ).split( ',' ).map( trimAscii );
	return allowed.filter( ( value ) => given.includes( value ) );
}
```

`src/views/sites/query.js` :

```js
import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath } from '../../store/paths';
import { MAX_PAGE, page, perPage, RULE_PATTERN, subset, text, trimAscii } from '../../utils/view-query';

export const ORDERBY = [
	'id',
	'name',
	'last_activity',
	'users_count',
	'content_count',
	'media_count',
	'disk_bytes',
	'db_bytes',
	'alert_level',
	'scanned_at',
];
export const ALERT_LEVELS = [ 'none', 'info', 'warning', 'error' ];
export const STATUSES = [ 'public', 'private', 'archived', 'spam', 'deleted' ];
export const REGISTRY_STATUSES = [ 'fresh', 'stale', 'missing' ];
export const LAYOUTS = [ 'table', 'grid' ];

export const FIELD_TO_ORDERBY = {
	id: 'id',
	name: 'name',
	last_activity_gmt: 'last_activity',
	users_count: 'users_count',
	content_count: 'content_count',
	media_count: 'media_count',
	disk_bytes: 'disk_bytes',
	db_bytes: 'db_bytes',
	alert_level: 'alert_level',
	scanned_at_gmt: 'scanned_at',
};
export const ORDERBY_TO_FIELD = Object.fromEntries(
	Object.entries( FIELD_TO_ORDERBY ).map( ( [ field, key ] ) => [ key, field ] )
);
export const DEFAULT_FIELDS = [
	'theme',
	'users_count',
	'content_count',
	'media_count',
	'last_activity_gmt',
	'alert_level',
	'scanned_at_gmt',
];

const LISTS = [ 'alert_level', 'status', 'registry_status' ];

export function parseSitesQuery( query ) {
	const orderby = text( query, 'orderby' );
	const rule = text( query, 'rule' );
	const layout = text( query, 'layout' );
	const site = text( query, 'site' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: ORDERBY.includes( orderby ) ? orderby : 'name',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		alert_level: subset( query, 'alert_level', ALERT_LEVELS ),
		status: subset( query, 'status', STATUSES ),
		registry_status: subset( query, 'registry_status', REGISTRY_STATUSES ),
		rule: RULE_PATTERN.test( rule ) ? rule : '',
		layout: LAYOUTS.includes( layout ) ? layout : '',
		site: /^\d+$/.test( site ) && Number( site ) > 0 ? Number( site ) : 0,
	};
}

export function sitesRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.sites?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	LISTS.forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			args[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		args.rule = state.rule;
	}
	return args;
}

export function sitesPath( state, prefs ) {
	return buildPath( '/sites', sitesRestArgs( state, prefs ) );
}

export function serializeSitesState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'name' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	LISTS.forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			out[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		out.rule = state.rule;
	}
	if ( state.layout ) {
		out.layout = state.layout;
	}
	if ( state.site ) {
		out.site = String( state.site );
	}
	return out;
}

export function toSitesView( state, sitesPrefs ) {
	return {
		type: state.layout || sitesPrefs?.layout || 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( sitesPrefs?.per_page ),
		sort: { field: ORDERBY_TO_FIELD[ state.orderby ] || 'name', direction: state.order },
		filters: toFilters( [
			{ field: 'alert_level', operator: 'isAny', value: state.alert_level },
			{ field: 'status', operator: 'isAny', value: state.status },
			{ field: 'registry_status', operator: 'isAny', value: state.registry_status },
			{ field: 'rule', operator: 'is', value: state.rule },
		] ),
		titleField: 'name',
		fields: sitesPrefs?.fields?.length ? sitesPrefs.fields : DEFAULT_FIELDS,
		layout: {},
	};
}

function listFilter( filters, field, allowed ) {
	const value = filterValue( filters, field, [] );
	const given = Array.isArray( value ) ? value : [ value ];
	return allowed.filter( ( item ) => given.includes( item ) );
}

export function fromSitesView( view, current ) {
	const rule = String( filterValue( view.filters, 'rule', '' ) );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'name',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		alert_level: listFilter( view.filters, 'alert_level', ALERT_LEVELS ),
		status: listFilter( view.filters, 'status', STATUSES ),
		registry_status: listFilter( view.filters, 'registry_status', REGISTRY_STATUSES ),
		rule: RULE_PATTERN.test( rule ) ? rule : '',
		layout: LAYOUTS.includes( view.type ) ? view.type : current.layout,
	};
}

export function sitesPrefsFromView( view ) {
	return {
		fields: view.fields || [],
		layout: LAYOUTS.includes( view.type ) ? view.type : 'table',
		per_page: perPage( view.perPage ),
	};
}
```

`src/views/sites/labels.js` :

```js
import { __, sprintf } from '@wordpress/i18n';

export function statusLabels() {
	return {
		public: __( 'Public', 'multisite-radar' ),
		private: __( 'Private', 'multisite-radar' ),
		archived: __( 'Archived', 'multisite-radar' ),
		spam: __( 'Spam', 'multisite-radar' ),
		deleted: __( 'Deleted', 'multisite-radar' ),
	};
}

export function registryLabels() {
	return {
		fresh: __( 'Verified', 'multisite-radar' ),
		stale: __( 'Outdated', 'multisite-radar' ),
		missing: __( 'Never read', 'multisite-radar' ),
	};
}

/**
 * États d'un site, dans l'ordre des filtres : public ou privé, puis archivé, spam, supprimé.
 *
 * @param {Object} site Site REST.
 */
export function siteStatuses( site ) {
	const status = site.status || {};
	return [
		status.public ? 'public' : 'private',
		...[ 'archived', 'spam', 'deleted' ].filter( ( key ) => status[ key ] ),
	];
}

/**
 * Origine d'un type de contenu ou d'une taxonomie (spec §3.2 : core, plugin, mu-plugin, theme, unknown).
 *
 * @param {Object} origin { kind, slug }.
 */
export function originLabel( origin ) {
	const slug = origin?.slug || '';
	switch ( origin?.kind ) {
		case 'core':
			return __( 'WordPress', 'multisite-radar' );
		case 'plugin':
			/* translators: %s: plugin folder name. */
			return sprintf( __( 'Plugin: %s', 'multisite-radar' ), slug );
		case 'mu-plugin':
			/* translators: %s: must-use plugin name. */
			return sprintf( __( 'Must-use plugin: %s', 'multisite-radar' ), slug );
		case 'theme':
			/* translators: %s: theme folder name. */
			return sprintf( __( 'Theme: %s', 'multisite-radar' ), slug );
		default:
			return __( 'Unknown', 'multisite-radar' );
	}
}
```

`src/views/sites/fields.jsx` :

```jsx
import { __ } from '@wordpress/i18n';
import { RegistryBadge, SeverityBadge, severityLabels } from '../../components/badges';
import { displayUrl, formatBytes, formatDateTime, formatNumber, formatRelative } from '../../utils/format';
import { registryLabels, siteStatuses, statusLabels } from './labels';
import { REGISTRY_STATUSES, STATUSES } from './query';

export function SiteTitle( { item } ) {
	return (
		<span className="msradar-site-title">
			<span className="msradar-site-title__name">{ item.name }</span>
			<span className="msradar-site-title__url">{ displayUrl( item.url ) }</span>
		</span>
	);
}

export function DateCell( { value } ) {
	if ( ! value ) {
		return <span>—</span>;
	}
	return (
		<time dateTime={ `${ value }Z` } title={ formatDateTime( value ) }>
			{ formatRelative( value ) }
		</time>
	);
}

/**
 * Champs DataViews de la liste des sites. Les identifiants sont les clés REST (et celles de l'export).
 *
 * @param {Array} rules by_rule de /alerts/summary (libellés des règles).
 */
export function getSitesFields( rules = [] ) {
	const statuses = statusLabels();
	const severities = severityLabels();
	const registry = registryLabels();
	const count = ( id, label ) => ( {
		id,
		type: 'integer',
		label,
		filterBy: false,
		render: ( { item } ) => formatNumber( item[ id ] ),
	} );
	const ruleLabel = ( id ) => rules.find( ( rule ) => rule.rule === id )?.label || id;

	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Site', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => <SiteTitle item={ item } />,
		},
		{
			id: 'theme',
			type: 'text',
			label: __( 'Theme', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.theme?.stylesheet || '',
		},
		count( 'users_count', __( 'Users', 'multisite-radar' ) ),
		count( 'content_count', __( 'Published content', 'multisite-radar' ) ),
		count( 'media_count', __( 'Media', 'multisite-radar' ) ),
		{
			id: 'disk_bytes',
			type: 'integer',
			label: __( 'Disk', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => formatBytes( item.disk_bytes ),
		},
		{
			id: 'db_bytes',
			type: 'integer',
			label: __( 'Database', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => formatBytes( item.db_bytes ),
		},
		{
			id: 'last_activity_gmt',
			type: 'datetime',
			label: __( 'Last activity', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => <DateCell value={ item.last_activity_gmt } />,
		},
		{
			id: 'alert_level',
			type: 'text',
			label: __( 'Alerts', 'multisite-radar' ),
			elements: [ 'error', 'warning', 'info', 'none' ].map( ( value ) => ( {
				value,
				label: severities[ value ],
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			render: ( { item } ) => <SeverityBadge level={ item.alert_level } count={ item.alerts_count } />,
		},
		{
			id: 'rule',
			type: 'text',
			label: __( 'Alert rule', 'multisite-radar' ),
			elements: rules.map( ( rule ) => ( { value: rule.rule, label: rule.label } ) ),
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
			getValue: ( { item } ) => ( item.alert_rules || [] ).join( ',' ),
			render: ( { item } ) => ( item.alert_rules || [] ).map( ruleLabel ).join( ', ' ),
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'multisite-radar' ),
			elements: STATUSES.map( ( value ) => ( { value, label: statuses[ value ] } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) => siteStatuses( item ).join( ',' ),
			render: ( { item } ) =>
				siteStatuses( item )
					.map( ( value ) => statuses[ value ] )
					.join( ', ' ),
		},
		{
			id: 'registry_status',
			type: 'text',
			label: __( 'Content types', 'multisite-radar' ),
			elements: REGISTRY_STATUSES.map( ( value ) => ( { value, label: registry[ value ] } ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			render: ( { item } ) =>
				item.registry_status === 'fresh' ? (
					registry.fresh
				) : (
					<RegistryBadge status={ item.registry_status } />
				),
		},
		{
			id: 'scanned_at_gmt',
			type: 'datetime',
			label: __( 'Analysed', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) =>
				item.pending ? (
					<span className="msradar-pending">{ __( 'Pending', 'multisite-radar' ) }</span>
				) : (
					<DateCell value={ item.scanned_at_gmt } />
				),
		},
	];
}
```

`src/views/sites/actions.js` :

```js
import { __ } from '@wordpress/i18n';
import { download, external, info, update, wordpress } from '@wordpress/icons';

/**
 * Actions de ligne et actions groupées de la liste des sites. « Analyse again » n'existe que pour msradar_manage.
 *
 * @param {Object}   options
 * @param {boolean}  options.canManage Droit de lancer une analyse.
 * @param {Function} options.onOpen    Ouvre la fiche d'un site.
 * @param {Function} options.onRescan  Reçoit les identifiants à réanalyser.
 * @param {Function} options.onExport  Reçoit les identifiants à exporter.
 */
export function getSitesActions( { canManage, onOpen, onRescan, onExport } ) {
	const actions = [
		{
			id: 'open',
			label: __( 'View details', 'multisite-radar' ),
			icon: info,
			isPrimary: true,
			callback: ( [ item ] ) => onOpen( item ),
		},
		{
			id: 'admin',
			label: __( 'Site dashboard', 'multisite-radar' ),
			icon: wordpress,
			isEligible: ( item ) => !! item.admin_url,
			callback: ( [ item ] ) => window.location.assign( item.admin_url ),
		},
		{
			id: 'visit',
			label: __( 'Visit site', 'multisite-radar' ),
			icon: external,
			isEligible: ( item ) => !! item.url,
			callback: ( [ item ] ) => window.open( item.url, '_blank', 'noopener,noreferrer' ),
		},
		{
			id: 'export',
			label: __( 'Export as CSV', 'multisite-radar' ),
			icon: download,
			supportsBulk: true,
			callback: ( items ) => onExport( items.map( ( item ) => item.id ) ),
		},
	];
	if ( canManage ) {
		actions.push( {
			id: 'rescan',
			label: __( 'Analyse again', 'multisite-radar' ),
			icon: update,
			supportsBulk: true,
			callback: ( items ) => onRescan( items.map( ( item ) => item.id ) ),
		} );
	}
	return actions;
}
```

`src/views/sites/export.js` :

```js
import { addQueryArgs } from '@wordpress/url';
import { getConfig } from '../../admin/config';

/**
 * Colonnes de l'export (clés de Export\SitesColumns) pour chaque champ visible de la liste.
 */
const FIELD_COLUMNS = {
	theme: [ 'theme' ],
	users_count: [ 'users_count', 'admins_count' ],
	content_count: [ 'content_count' ],
	media_count: [ 'media_count' ],
	disk_bytes: [ 'disk_bytes' ],
	db_bytes: [ 'db_bytes' ],
	last_activity_gmt: [ 'last_activity_gmt' ],
	alert_level: [ 'alert_level', 'alerts_count', 'alert_rules' ],
	rule: [ 'alert_rules' ],
	status: [ 'status' ],
	registry_status: [ 'registry_status' ],
	scanned_at_gmt: [ 'scanned_at_gmt' ],
};

export function exportColumns( fields ) {
	const columns = [ 'id', 'name', 'url' ];
	( fields || [] ).forEach( ( field ) => {
		( FIELD_COLUMNS[ field ] || [] ).forEach( ( column ) => {
			if ( ! columns.includes( column ) ) {
				columns.push( column );
			}
		} );
	} );
	return columns;
}

/**
 * Lien de téléchargement (admin-post.php, nonce msradar_export) avec les filtres de la vue.
 *
 * @param {string}   format  csv ou json.
 * @param {Object}   state   État de la vue Sites.
 * @param {string[]} fields  Champs visibles.
 * @param {number[]} include Sélection (vide : toute la liste filtrée).
 */
export function exportUrl( format, state, fields, include = [] ) {
	const { exportUrl: base, exportNonce } = getConfig();
	const args = {
		action: 'msradar_export',
		_wpnonce: exportNonce,
		resource: 'sites',
		format,
		fields: exportColumns( fields ).join( ',' ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	[ 'alert_level', 'status', 'registry_status' ].forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			args[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		args.rule = state.rule;
	}
	if ( include.length > 0 ) {
		args.include = include.join( ',' );
	}
	return addQueryArgs( base, args );
}
```

`src/views/sites/export-menu.jsx` :

```jsx
import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';
import { exportUrl } from './export';

export default function ExportMenu( { state, fields } ) {
	return (
		<DropdownMenu
			icon={ download }
			label={ __( 'Export', 'multisite-radar' ) }
			controls={ [
				{
					title: __( 'Export as CSV', 'multisite-radar' ),
					onClick: () => window.location.assign( exportUrl( 'csv', state, fields ) ),
				},
				{
					title: __( 'Export as JSON', 'multisite-radar' ),
					onClick: () => window.location.assign( exportUrl( 'json', state, fields ) ),
				},
			] }
		/>
	);
}
```

`src/views/sites/index.jsx` :

```jsx
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useScan } from '../../hooks/use-scan';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { getSitesActions } from './actions';
import { exportUrl } from './export';
import ExportMenu from './export-menu';
import { getSitesFields } from './fields';
import {
	fromSitesView,
	parseSitesQuery,
	serializeSitesState,
	sitesPath,
	sitesPrefsFromView,
	toSitesView,
} from './query';

const DEFAULT_LAYOUTS = {
	table: {},
	grid: { layout: { badgeFields: [ 'alert_level' ] } },
};

function samePrefs( a, b ) {
	return (
		!! a &&
		!! b &&
		a.layout === b.layout &&
		a.per_page === b.per_page &&
		JSON.stringify( a.fields ) === JSON.stringify( b.fields )
	);
}

export default function SitesView() {
	const { canManage } = getConfig();
	const [ state, setState ] = useUrlState( parseSitesQuery, serializeSitesState );
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'sites' );
	// Les préférences changées s'appliquent tout de suite ; l'enregistrement suit en arrière-plan.
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const sitesPrefs = localPrefs || prefs?.sites || null;

	const summary = useResource( buildPath( '/alerts/summary' ) );
	const list = useResource( sitesPrefs ? sitesPath( state, { sites: sitesPrefs } ) : null );
	const scan = useScan();
	const startScan = scan.start;

	const rules = summary.data?.by_rule;
	const fields = useMemo( () => getSitesFields( rules || [] ), [ rules ] );
	const view = useMemo( () => toSitesView( state, sitesPrefs ), [ state, sitesPrefs ] );

	const openSite = useCallback(
		( item ) => setState( ( current ) => ( { ...current, site: item.id } ) ),
		[ setState ]
	);
	const actions = useMemo(
		() =>
			getSitesActions( {
				canManage,
				onOpen: openSite,
				onRescan: ( ids ) => startScan( { scope: 'ids', ids } ),
				onExport: ( ids ) => window.location.assign( exportUrl( 'csv', state, view.fields, ids ) ),
			} ),
		[ canManage, openSite, startScan, state, view.fields ]
	);

	const onChangeView = ( next ) => {
		setState( ( current ) => fromSitesView( next, current ) );
		const nextPrefs = sitesPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, sitesPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-sites">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				defaultLayouts={ DEFAULT_LAYOUTS }
				paginationInfo={ { totalItems: list.total || 0, totalPages: list.totalPages || 0 } }
				isLoading={ list.isLoading && ! list.data }
				getItemId={ ( item ) => String( item.id ) }
				isItemClickable={ () => true }
				onClickItem={ openSite }
				searchLabel={ __( 'Search sites', 'multisite-radar' ) }
				header={ <ExportMenu state={ state } fields={ view.fields } /> }
				empty={ <p className="msradar-empty">{ __( 'No site matches this view.', 'multisite-radar' ) }</p> }
			/>
			{ scan.running && (
				<p className="msradar-inline-status" role="status">
					{ sprintf(
						/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
						__( 'Analysing: %1$d of %2$d sites…', 'multisite-radar' ),
						scan.processed,
						scan.total
					) }
				</p>
			) }
		</div>
	);
}
```

Remplacer `src/admin/sites.js` par :

```js
import { mount } from './mount';
import SitesView from '../views/sites';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( SitesView );
```

Ajouter à la fin de `src/admin/style.scss` :

```scss
.msradar-site-title {
	display: flex;
	flex-direction: column;
	min-width: 0;

	&__name {
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	&__url {
		color: #50575e;
		font-size: 12px;
		overflow-wrap: anywhere;
	}
}

.msradar-pending {
	color: #50575e;
	font-style: italic;
}

.msradar-empty {
	padding: 24px;
	text-align: center;
}

.msradar-inline-status {
	margin: 12px 0 0;
}
```

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS.

DataViews doit se rendre sous jsdom avec les bouchons de `tests/js/setup.mjs`. Si une API manque, ajouter le bouchon minimal correspondant dans `tests/js/setup.mjs`, sans modifier les assertions.

- [ ] **Step 5: Vérifier dans le WordPress local**

Run: `npm run build`

Ouvrir `…/wp-admin/network/admin.php?page=multisite-radar-sites` et vérifier :
- la liste s'affiche aussitôt, sans requête `/multisite-radar/v1/sites` dans l'onglet Réseau au chargement ;
- la recherche, le tri, les filtres et la pagination mettent à jour l'URL ;
- un rechargement rouvre la même vue ;
- masquer une colonne puis recharger la garde masquée (préférence enregistrée) ;
- « Export › Export as CSV » télécharge `multisite-radar-sites-AAAAMMJJ-HHMMSS.csv`.

- [ ] **Step 6: Lint, build et commit**

Run: `npm run lint:js && npm run lint:css && npm run build`
Expected: aucune erreur.

```bash
git add src
git commit -m "feat: Sites view with DataViews, URL state, saved preferences, row and bulk actions and exports"
```

---

### Task 15: Fiche site — panneau latéral avec lien profond

**Files:**
- Create: `src/views/site-panel/index.jsx`, `src/views/site-panel/summary-tab.jsx`, `src/views/site-panel/content-tab.jsx`, `src/views/site-panel/users-tab.jsx`, `src/views/site-panel/extensions-tab.jsx`, `src/views/site-panel/alerts-tab.jsx`
- Modify: `src/views/sites/index.jsx`, `src/admin/style.scss`
- Test: `src/views/site-panel/test/site-panel.test.jsx`

**Interfaces:**
- Consumes :
  - REST `GET /sites/{id}` : le résumé de la tâche 3, plus `post_types`, `taxonomies`, `users`, `last_content`, `options`, `alerts` (`{ rule, severity, label, message }`), `extensions` (`{ plugins_local[], network_plugins_count, theme }`) et `scan_error` ;
  - REST `GET /sites/{id}/users` (tâche 6) ;
  - `labels.js` et `fields.jsx` (tâche 14).
- Produces :
  - `SitePanel` (export par défaut), qui reçoit `siteId`, `items` (la page courante, pour ←/→), `onNavigate( id )` et `onClose()` ;
  - un dialogue non modal `aria-labelledby="msradar-panel-title"`. Le focus va au titre à l'ouverture et à chaque changement de site, et revient à l'élément d'origine à la fermeture. Échap ferme le panneau ;
  - les onglets `summary`, `content`, `users`, `extensions` et `alerts` (l'onglet Historique viendra avec M6).

- [ ] **Step 1: Écrire les tests qui échouent**

`src/views/site-panel/test/site-panel.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import SitePanel from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const DETAIL = {
	id: 12,
	name: 'Blog RH',
	url: 'https://example.test/rh/',
	admin_url: 'https://example.test/rh/wp-admin/',
	status: { public: true, archived: false, spam: false, deleted: false },
	theme: { stylesheet: 'tt5', template: 'tt5' },
	users_count: 3,
	admins_count: 1,
	content_count: 5,
	media_count: 2,
	disk_bytes: null,
	db_bytes: null,
	autoload_bytes: null,
	last_activity_gmt: '2026-09-01T10:00:00',
	alert_level: 'warning',
	alerts_count: 1,
	alert_rules: [ 'inactive' ],
	registry_status: 'fresh',
	pending: false,
	dirty: false,
	scanned_at_gmt: '2026-09-02T10:00:00',
	post_types: [
		{ name: 'post', label: 'Posts', publish: 4, total: 5, origin: { kind: 'core', slug: '' }, builtin: true, verified: true },
		{ name: 'demo_event', label: 'Demo events', publish: 1, total: 1, origin: { kind: 'plugin', slug: 'msradar-demo-cpt' }, builtin: false, verified: false },
	],
	taxonomies: [ { name: 'category', label: 'Categories', count: 2, origin: { kind: 'core', slug: '' }, builtin: true, verified: true } ],
	users: { by_role: { administrator: 1 }, privileged: [] },
	last_content: { id: 5, type: 'post', title: 'Hello', date_gmt: '2026-09-01T10:00:00' },
	options: {},
	alerts: [ { rule: 'inactive', severity: 'warning', label: 'Inactive site', message: 'Inactive for 8 months' } ],
	extensions: {
		plugins_local: [ { file: 'msradar-demo-cpt/msradar-demo-cpt.php', name: 'Demo CPT', version: '1.0', installed: true } ],
		network_plugins_count: 1,
		theme: { stylesheet: 'tt5', template: 'tt5', name: 'Twenty Twenty-Five', version: '1.2', installed: true },
	},
	scan_error: null,
};

const ITEMS = [ { id: 11, name: 'Alpha' }, { id: 12, name: 'Blog RH' }, { id: 13, name: 'Gamma' } ];

function setup( { siteId = 12, preload = { '/multisite-radar/v1/sites/12': { body: DETAIL, headers: {} } } } = {} ) {
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	const props = { onNavigate: vi.fn(), onClose: vi.fn() };
	render(
		<RegistryProvider value={ registry }>
			<SitePanel siteId={ siteId } items={ ITEMS } { ...props } />
		</RegistryProvider>
	);
	return props;
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

test( 'shows the preloaded site with focus on its title', () => {
	setup();

	const heading = screen.getByRole( 'heading', { name: 'Blog RH' } );
	expect( heading ).toHaveFocus();
	expect( screen.getByRole( 'dialog', { name: 'Blog RH' } ) ).toBeInTheDocument();
	expect( screen.getByRole( 'tab', { name: 'Summary' } ) ).toHaveAttribute( 'aria-selected', 'true' );
	expect( screen.getByText( 'Twenty Twenty-Five' ) ).toBeInTheDocument();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'the content tab shows origins and flags unverified types', () => {
	setup();

	fireEvent.click( screen.getByRole( 'tab', { name: 'Content' } ) );

	expect( screen.getByText( 'Plugin: msradar-demo-cpt' ) ).toBeInTheDocument();
	expect( screen.getAllByText( 'Not verified' ) ).toHaveLength( 1 );
} );

test( 'previous, next and Escape', () => {
	const { onNavigate, onClose } = setup();

	fireEvent.click( screen.getByRole( 'button', { name: 'Next site' } ) );
	expect( onNavigate ).toHaveBeenCalledWith( 13 );
	fireEvent.click( screen.getByRole( 'button', { name: 'Previous site' } ) );
	expect( onNavigate ).toHaveBeenCalledWith( 11 );
	fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
	expect( onClose ).toHaveBeenCalledTimes( 1 );
} );

test( 'an unknown site shows an error with Retry, not a broken panel', async () => {
	apiFetch.mockRejectedValue( {
		json: async () => ( { code: 'msradar_site_not_found', message: 'Site not found.', data: { status: 404 } } ),
	} );

	setup( { siteId: 999999, preload: {} } );

	expect( screen.getByRole( 'heading', { name: 'Site #999999' } ) ).toBeInTheDocument();
	expect( await screen.findByText( 'Site not found.' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument();
} );

test( 'users are loaded on demand, page by page', async () => {
	apiFetch.mockImplementation( async ( { path } ) => ( {
		json: async () => [ { id: 1, login: 'admin', display_name: 'Admin', roles: [ 'administrator' ], super_admin: true, registered_gmt: null } ],
		headers: new Map( [ [ 'X-WP-Total', '1' ], [ 'X-WP-TotalPages', '1' ] ] ),
		path,
	} ) );
	setup();

	fireEvent.click( screen.getByRole( 'tab', { name: 'Users' } ) );

	expect( await screen.findByText( 'admin' ) ).toBeInTheDocument();
	expect( apiFetch ).toHaveBeenCalledWith( { path: '/multisite-radar/v1/sites/12/users?page=1&per_page=20', parse: false } );
	await waitFor( () => expect( screen.getByText( 'Page 1 of 1' ) ).toBeInTheDocument() );
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/views/site-panel`
Expected: FAIL (`Failed to resolve import ".."`).

- [ ] **Step 3: Implémenter**

`src/views/site-panel/index.jsx` :

```jsx
import { Button, TabPanel } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronLeft, chevronRight, closeSmall } from '@wordpress/icons';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import AlertsTab from './alerts-tab';
import ContentTab from './content-tab';
import ExtensionsTab from './extensions-tab';
import SummaryTab from './summary-tab';
import UsersTab from './users-tab';

function tabs() {
	return [
		{ name: 'summary', title: __( 'Summary', 'multisite-radar' ) },
		{ name: 'content', title: __( 'Content', 'multisite-radar' ) },
		{ name: 'users', title: __( 'Users', 'multisite-radar' ) },
		{ name: 'extensions', title: __( 'Extensions', 'multisite-radar' ) },
		{ name: 'alerts', title: __( 'Alerts', 'multisite-radar' ) },
	];
}

function Tab( { name, site } ) {
	switch ( name ) {
		case 'content':
			return <ContentTab site={ site } />;
		case 'users':
			return <UsersTab siteId={ site.id } />;
		case 'extensions':
			return <ExtensionsTab site={ site } />;
		case 'alerts':
			return <AlertsTab site={ site } />;
		default:
			return <SummaryTab site={ site } />;
	}
}

/**
 * Fiche d'un site, en panneau latéral au-dessus de la liste (lien profond &site=<id>).
 *
 * @param {Object}   props
 * @param {number}   props.siteId     Site affiché.
 * @param {Array}    props.items      Sites de la page courante, pour « précédent » et « suivant ».
 * @param {Function} props.onNavigate Reçoit l'identifiant du site à afficher.
 * @param {Function} props.onClose    Ferme le panneau.
 */
export default function SitePanel( { siteId, items, onNavigate, onClose } ) {
	const site = useResource( buildPath( `/sites/${ siteId }` ) );
	const data = site.isFresh ? site.data : null;
	const heading = useRef();
	const opener = useRef( document.activeElement );

	useEffect( () => {
		heading.current?.focus();
	}, [ siteId ] );
	useEffect(
		() => () => {
			if ( opener.current?.isConnected ) {
				opener.current.focus();
			}
		},
		[]
	);

	const index = items.findIndex( ( item ) => item.id === siteId );
	const previous = index > 0 ? items[ index - 1 ] : null;
	const next = index >= 0 && index < items.length - 1 ? items[ index + 1 ] : null;
	const title =
		data?.name ||
		( index >= 0 ? items[ index ].name : '' ) ||
		/* translators: %d: site ID. */
		sprintf( __( 'Site #%d', 'multisite-radar' ), siteId );

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- Escape closes the dialog.
		<aside
			className="msradar-panel"
			role="dialog"
			aria-modal="false"
			aria-labelledby="msradar-panel-title"
			onKeyDown={ onKeyDown }
		>
			<div className="msradar-panel__header">
				<h2 id="msradar-panel-title" className="msradar-panel__title" tabIndex={ -1 } ref={ heading }>
					{ title }
				</h2>
				<Button
					icon={ chevronLeft }
					label={ __( 'Previous site', 'multisite-radar' ) }
					disabled={ ! previous }
					onClick={ () => previous && onNavigate( previous.id ) }
				/>
				<Button
					icon={ chevronRight }
					label={ __( 'Next site', 'multisite-radar' ) }
					disabled={ ! next }
					onClick={ () => next && onNavigate( next.id ) }
				/>
				<Button icon={ closeSmall } label={ __( 'Close', 'multisite-radar' ) } onClick={ onClose } />
			</div>
			<div className="msradar-panel__body">
				<ErrorNotice error={ site.error } onRetry={ site.retry } />
				{ ! data && ! site.error && (
					<div className="msradar-panel__placeholder" aria-busy="true">
						{ __( 'Loading the site…', 'multisite-radar' ) }
					</div>
				) }
				{ data && (
					<TabPanel className="msradar-panel__tabs" tabs={ tabs() }>
						{ ( tab ) => <Tab name={ tab.name } site={ data } /> }
					</TabPanel>
				) }
			</div>
		</aside>
	);
}
```

`src/views/site-panel/summary-tab.jsx` :

```jsx
import { ExternalLink, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { displayUrl, formatDateTime, formatNumber } from '../../utils/format';
import { siteStatuses, statusLabels } from '../sites/labels';

export default function SummaryTab( { site } ) {
	const statuses = statusLabels();
	const theme = site.extensions?.theme;
	return (
		<>
			{ site.scan_error && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: date of the failed analysis, 2: error message. */
						__( 'The last analysis failed on %1$s: %2$s', 'multisite-radar' ),
						formatDateTime( site.scan_error.at_gmt ),
						site.scan_error.message
					) }
				</Notice>
			) }
			{ site.registry_status !== 'fresh' && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'The content types of this site have not yet been read in its own context, so their labels and origins are not verified. Run "wp multisite-radar scan --probe" to read them now.', 'multisite-radar' ) }
				</Notice>
			) }
			<dl className="msradar-facts">
				<dt>{ __( 'Address', 'multisite-radar' ) }</dt>
				<dd>
					<ExternalLink href={ site.url }>{ displayUrl( site.url ) }</ExternalLink>
				</dd>
				<dt>{ __( 'Administration', 'multisite-radar' ) }</dt>
				<dd>
					<a href={ site.admin_url }>{ __( 'Site dashboard', 'multisite-radar' ) }</a>
				</dd>
				<dt>{ __( 'Status', 'multisite-radar' ) }</dt>
				<dd>{ siteStatuses( site ).map( ( key ) => statuses[ key ] ).join( ', ' ) }</dd>
				<dt>{ __( 'Theme', 'multisite-radar' ) }</dt>
				<dd>{ theme?.name || site.theme?.stylesheet || '—' }</dd>
				<dt>{ __( 'Users', 'multisite-radar' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: 1: number of users, 2: number of administrators. */
						__( '%1$s, including %2$s administrators', 'multisite-radar' ),
						formatNumber( site.users_count ),
						formatNumber( site.admins_count )
					) }
				</dd>
				<dt>{ __( 'Published content', 'multisite-radar' ) }</dt>
				<dd>{ formatNumber( site.content_count ) }</dd>
				<dt>{ __( 'Media', 'multisite-radar' ) }</dt>
				<dd>{ formatNumber( site.media_count ) }</dd>
				<dt>{ __( 'Last activity', 'multisite-radar' ) }</dt>
				<dd>
					{ formatDateTime( site.last_activity_gmt ) }
					{ site.last_content?.title ? ` — ${ site.last_content.title }` : '' }
				</dd>
				<dt>{ __( 'Analysed', 'multisite-radar' ) }</dt>
				<dd>{ site.pending ? __( 'Pending', 'multisite-radar' ) : formatDateTime( site.scanned_at_gmt ) }</dd>
			</dl>
		</>
	);
}
```

`src/views/site-panel/content-tab.jsx` :

```jsx
import { __, sprintf } from '@wordpress/i18n';
import { RegistryBadge } from '../../components/badges';
import { formatNumber } from '../../utils/format';
import { originLabel } from '../sites/labels';

function TypesTable( { caption, items, countLabel, count, total } ) {
	if ( ! items || items.length === 0 ) {
		/* translators: %s: "Content types" or "Taxonomies". */
		return <p>{ sprintf( __( '%s: none.', 'multisite-radar' ), caption ) }</p>;
	}
	return (
		<table className="widefat striped msradar-table">
			<caption>{ caption }</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Name', 'multisite-radar' ) }</th>
					<th scope="col">{ __( 'Origin', 'multisite-radar' ) }</th>
					<th scope="col" className="num">
						{ countLabel }
					</th>
					{ total && (
						<th scope="col" className="num">
							{ __( 'Total', 'multisite-radar' ) }
						</th>
					) }
				</tr>
			</thead>
			<tbody>
				{ items.map( ( item ) => (
					<tr key={ item.name }>
						<th scope="row">
							{ item.label || item.name } <code>{ item.name }</code>{ ' ' }
							{ item.verified === false && <RegistryBadge status="stale" /> }
						</th>
						<td>{ originLabel( item.origin ) }</td>
						<td className="num">{ formatNumber( count( item ) ?? 0 ) }</td>
						{ total && <td className="num">{ formatNumber( total( item ) ?? 0 ) }</td> }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function ContentTab( { site } ) {
	return (
		<>
			<TypesTable
				caption={ __( 'Content types', 'multisite-radar' ) }
				items={ site.post_types }
				countLabel={ __( 'Published', 'multisite-radar' ) }
				count={ ( item ) => item.publish }
				total={ ( item ) => item.total }
			/>
			<TypesTable
				caption={ __( 'Taxonomies', 'multisite-radar' ) }
				items={ site.taxonomies }
				countLabel={ __( 'Terms', 'multisite-radar' ) }
				count={ ( item ) => item.count }
			/>
		</>
	);
}
```

`src/views/site-panel/users-tab.jsx` :

```jsx
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';

export default function UsersTab( { siteId } ) {
	const [ page, setPage ] = useState( 1 );
	useEffect( () => setPage( 1 ), [ siteId ] );
	const users = useResource( buildPath( `/sites/${ siteId }/users`, { page, per_page: 20 } ) );
	const pages = users.totalPages || 1;

	if ( users.error ) {
		return <ErrorNotice error={ users.error } onRetry={ users.retry } />;
	}
	if ( ! users.data ) {
		return <p aria-busy="true">{ __( 'Loading users…', 'multisite-radar' ) }</p>;
	}
	if ( users.data.length === 0 ) {
		return <p>{ __( 'No user is attached to this site.', 'multisite-radar' ) }</p>;
	}
	return (
		<>
			<table className="widefat striped msradar-table" aria-busy={ users.isLoading }>
				<thead>
					<tr>
						<th scope="col">{ __( 'Login', 'multisite-radar' ) }</th>
						<th scope="col">{ __( 'Name', 'multisite-radar' ) }</th>
						<th scope="col">{ __( 'Roles', 'multisite-radar' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ users.data.map( ( user ) => (
						<tr key={ user.id }>
							<td>
								{ user.login }
								{ user.super_admin && (
									<>
										{ ' ' }
										<span className="msradar-badge">{ __( 'Super admin', 'multisite-radar' ) }</span>
									</>
								) }
							</td>
							<td>{ user.display_name }</td>
							<td>{ user.roles.join( ', ' ) || '—' }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="msradar-pager">
				<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
					{ __( 'Previous', 'multisite-radar' ) }
				</Button>
				<span>
					{ sprintf(
						/* translators: 1: current page, 2: number of pages. */
						__( 'Page %1$d of %2$d', 'multisite-radar' ),
						page,
						pages
					) }
				</span>
				<Button variant="secondary" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>
					{ __( 'Next', 'multisite-radar' ) }
				</Button>
			</div>
		</>
	);
}
```

`src/views/site-panel/extensions-tab.jsx` :

```jsx
import { __, _n, sprintf } from '@wordpress/i18n';

function Missing() {
	return <span className="msradar-badge msradar-badge--error">{ __( 'Not installed', 'multisite-radar' ) }</span>;
}

export default function ExtensionsTab( { site } ) {
	const extensions = site.extensions || {};
	const theme = extensions.theme;
	const plugins = extensions.plugins_local || [];
	const network = extensions.network_plugins_count || 0;
	return (
		<>
			<h3>{ __( 'Theme', 'multisite-radar' ) }</h3>
			{ theme ? (
				<p>
					{ theme.name } { theme.version } { ! theme.installed && <Missing /> }
					{ theme.template && theme.template !== theme.stylesheet && (
						<>
							{ ' — ' }
							{ sprintf(
								/* translators: %s: parent theme folder name. */
								__( 'child theme of %s', 'multisite-radar' ),
								theme.template
							) }
						</>
					) }
				</p>
			) : (
				<p>—</p>
			) }
			<h3>{ __( 'Plugins active on this site only', 'multisite-radar' ) }</h3>
			{ plugins.length === 0 ? (
				<p>{ __( 'None.', 'multisite-radar' ) }</p>
			) : (
				<ul className="msradar-list">
					{ plugins.map( ( plugin ) => (
						<li key={ plugin.file }>
							{ plugin.name } { plugin.version } { ! plugin.installed && <Missing /> }
						</li>
					) ) }
				</ul>
			) }
			<p>
				{ sprintf(
					/* translators: %d: number of network-activated plugins. */
					_n(
						'%d plugin is active on the whole network.',
						'%d plugins are active on the whole network.',
						network,
						'multisite-radar'
					),
					network
				) }
			</p>
		</>
	);
}
```

`src/views/site-panel/alerts-tab.jsx` :

```jsx
import { __ } from '@wordpress/i18n';
import { SeverityBadge } from '../../components/badges';

export default function AlertsTab( { site } ) {
	if ( ! site.alerts || site.alerts.length === 0 ) {
		return <p>{ __( 'No alert for this site.', 'multisite-radar' ) }</p>;
	}
	return (
		<ul className="msradar-alert-list">
			{ site.alerts.map( ( alert ) => (
				<li key={ alert.rule }>
					<SeverityBadge level={ alert.severity } /> <strong>{ alert.label }</strong> — { alert.message }
				</li>
			) ) }
		</ul>
	);
}
```

Dans `src/views/sites/index.jsx` :
- ajouter `import SitePanel from '../site-panel';` ;
- juste avant la fermeture `</div>` du composant, ajouter :

```jsx
			{ state.site > 0 && (
				<SitePanel
					siteId={ state.site }
					items={ list.data || [] }
					onNavigate={ ( id ) => setState( ( current ) => ( { ...current, site: id } ) ) }
					onClose={ () => setState( ( current ) => ( { ...current, site: 0 } ) ) }
				/>
			) }
```

Ajouter à la fin de `src/admin/style.scss` :

```scss
// Panneau latéral : au-dessus de la liste, sous la barre d'administration.
.msradar-panel {
	position: fixed;
	top: var(--wp-admin--admin-bar--height, 32px);
	right: 0;
	bottom: 0;
	z-index: 9990;
	display: flex;
	flex-direction: column;
	width: min(560px, 100%);
	border-left: 1px solid #dcdcde;
	background: #fff;
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.08);

	&__header {
		display: flex;
		align-items: center;
		gap: 4px;
		padding: 12px 16px;
		border-bottom: 1px solid #dcdcde;
	}

	&__title {
		flex: 1;
		margin: 0;
		font-size: 18px;
		overflow-wrap: anywhere;

		&:focus {
			outline: 2px solid var(--wp-admin-theme-color, #3858e9);
			outline-offset: 2px;
		}
	}

	&__body {
		flex: 1;
		overflow-y: auto;
		padding: 16px;
	}

	&__placeholder {
		min-height: 120px;
		border-radius: 2px;
		background: #f6f7f7;
		color: #50575e;
		padding: 16px;
	}
}

.msradar-facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	margin: 16px 0;

	dt {
		font-weight: 600;
	}

	dd {
		margin: 0;
		overflow-wrap: anywhere;
	}
}

.msradar-table {
	margin: 16px 0;

	caption {
		padding: 8px 0;
		font-weight: 600;
		text-align: start;
	}

	.num {
		text-align: end;
	}
}

.msradar-pager {
	display: flex;
	align-items: center;
	gap: 12px;
}

.msradar-alert-list li,
.msradar-list li {
	margin-bottom: 8px;
}
```

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS, y compris `sites-view.test.jsx` (tâche 14) : l'ouverture d'un site affiche maintenant le panneau, dont la requête reste en attente, sans effet sur les assertions.

- [ ] **Step 5: Vérifier dans le WordPress local**

Run: `npm run build`

Dans la liste des Sites, cliquer « Blog RH » et vérifier :
- l'URL gagne `&site=<id>` et le panneau s'ouvre, focus sur le titre ;
- l'onglet Content affiche `demo_event` avec l'origine « Plugin: msradar-demo-cpt » ;
- Échap ferme le panneau et le focus revient sur le lien ;
- recharger la page avec `&site=<id>` rouvre la fiche immédiatement (préchargée).

- [ ] **Step 6: Lint, build et commit**

Run: `npm run lint:js && npm run lint:css && npm run build`
Expected: aucune erreur.

```bash
git add src
git commit -m "feat: site side panel with deep link, keyboard navigation and on-demand users"
```

---
### Task 16: Vue d'ensemble — tuiles, « À traiter », analyse pilotée par l'interface

**Files:**
- Create: `src/views/overview/index.jsx`, `src/views/overview/tiles.jsx`, `src/views/overview/scan-panel.jsx`
- Modify: `src/admin/overview.js`, `src/admin/style.scss`
- Test: `src/views/overview/test/overview.test.jsx`

**Interfaces:**
- Consumes :
  - REST `GET /alerts/summary` (tâche 5 : `by_rule[].severity` et `enabled`) ;
  - REST `GET /scan/status` (M1 : `total`, `remaining`, `pending`, `locked`, `last_full_scan_gmt`, `next_run_gmt`) ;
  - `useScan()` et `pageUrl()` (tâche 13).
- Produces : `OverviewView`, `Tiles`, `ToReview`, `ScanPanel` et `ScanProgress`.
- **Comportement :**
  - quatre tuiles : sites, sites en erreur, sites en avertissement, sites en information. Chaque tuile mène à la vue Sites filtrée (`alert_level`) ;
  - « À traiter » liste les règles actives avec au moins un site, triées par gravité puis par nombre de sites, avec un lien vers Sites filtrés par `rule` ;
  - un encart de premier lancement s'affiche si aucun site n'est analysé ;
  - le bouton « Analyse all sites » n'existe que pour `canManage`.

- [ ] **Step 1: Écrire les tests qui échouent**

`src/views/overview/test/overview.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import OverviewView from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn( () => new Promise( () => {} ) ) } ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const SITES_URL = 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites';

function summary( overrides = {} ) {
	return {
		total_sites: 12,
		scanned_sites: 10,
		pending_sites: 2,
		sites_with_alerts: 4,
		by_severity: { error: 1, warning: 2, info: 1 },
		by_rule: [
			{ rule: 'high_media', label: 'Many media files', severity: 'info', enabled: true, count: 1 },
			{ rule: 'inactive', label: 'Inactive site', severity: 'warning', enabled: true, count: 3 },
			{ rule: 'no_users', label: 'Site without users', severity: 'error', enabled: true, count: 1 },
			{ rule: 'disabled', label: 'Disabled rule', severity: 'error', enabled: false, count: 5 },
		],
		...overrides,
	};
}

function renderView( { canManage = true, data = summary() } = {} ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/alerts/summary': { body: data, headers: {} },
		'/multisite-radar/v1/scan/status': {
			body: { total: 12, remaining: 2, pending: 2, locked: false, last_full_scan_gmt: null, next_run_gmt: null },
			headers: {},
		},
	};
	window.msradarAdmin = { view: 'overview', canManage, pages: { sites: SITES_URL }, preload };
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<OverviewView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
} );

test( 'tiles link to the sites filtered by severity', () => {
	renderView();

	const errors = screen.getByRole( 'link', { name: /Sites with errors/ } );
	expect( errors ).toHaveAttribute( 'href', `${ SITES_URL }&alert_level=error` );
	expect( screen.getByRole( 'link', { name: /12\s*Sites/ } ) ).toHaveAttribute( 'href', SITES_URL );
	expect( screen.getByText( '2 awaiting analysis' ) ).toBeInTheDocument();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'to review lists enabled rules with sites, the most severe first', () => {
	renderView();

	const links = screen.getAllByRole( 'link', { name: /Site without users|Inactive site|Many media files|Disabled rule/ } );
	expect( links.map( ( link ) => link.textContent ) ).toEqual( [ 'Site without users', 'Inactive site', 'Many media files' ] );
	expect( links[ 0 ] ).toHaveAttribute( 'href', `${ SITES_URL }&rule=no_users` );
	expect( screen.getByText( '3 sites' ) ).toBeInTheDocument();
} );

test( 'the first launch explains the initial analysis', () => {
	renderView( { data: summary( { scanned_sites: 0, pending_sites: 12, by_severity: { error: 0, warning: 0, info: 0 }, by_rule: [] } ) } );

	expect( screen.getByText( /has not analysed your 12 sites yet/ ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Start the analysis' } ) ).toBeInTheDocument();
} );

test( 'starting an analysis is reserved to managers', () => {
	renderView( { canManage: false } );
	expect( screen.queryByRole( 'button', { name: 'Analyse all sites' } ) ).toBeNull();
} );

test( 'Analyse all sites starts a full analysis', () => {
	renderView();

	fireEvent.click( screen.getByRole( 'button', { name: 'Analyse all sites' } ) );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/scan',
		method: 'POST',
		data: { scope: 'all' },
	} );
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/views/overview`
Expected: FAIL (`Failed to resolve import ".."`).

- [ ] **Step 3: Implémenter**

`src/views/overview/tiles.jsx` :

```jsx
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pageUrl } from '../../admin/config';
import { SeverityBadge } from '../../components/badges';
import { formatNumber } from '../../utils/format';

const LEVELS = { error: 3, warning: 2, info: 1 };

export function Tiles( { summary } ) {
	const tiles = [
		{
			key: 'sites',
			label: __( 'Sites', 'multisite-radar' ),
			value: summary.total_sites,
			detail:
				summary.pending_sites > 0
					? sprintf(
							/* translators: %d: number of sites not analysed yet. */
							_n( '%d awaiting analysis', '%d awaiting analysis', summary.pending_sites, 'multisite-radar' ),
							summary.pending_sites
					  )
					: __( 'All analysed', 'multisite-radar' ),
			href: pageUrl( 'sites' ),
		},
		{
			key: 'error',
			label: __( 'Sites with errors', 'multisite-radar' ),
			value: summary.by_severity.error,
			href: pageUrl( 'sites', { alert_level: 'error' } ),
		},
		{
			key: 'warning',
			label: __( 'Sites with warnings', 'multisite-radar' ),
			value: summary.by_severity.warning,
			href: pageUrl( 'sites', { alert_level: 'warning' } ),
		},
		{
			key: 'info',
			label: __( 'Sites with information', 'multisite-radar' ),
			value: summary.by_severity.info,
			href: pageUrl( 'sites', { alert_level: 'info' } ),
		},
	];
	return (
		<ul className="msradar-tiles">
			{ tiles.map( ( tile ) => (
				<li key={ tile.key } className={ `msradar-tile msradar-tile--${ tile.key }` }>
					<a className="msradar-tile__link" href={ tile.href }>
						<span className="msradar-tile__value">{ formatNumber( tile.value ) }</span>
						<span className="msradar-tile__label">{ tile.label }</span>
						{ tile.detail && <span className="msradar-tile__detail">{ tile.detail }</span> }
					</a>
				</li>
			) ) }
		</ul>
	);
}

export function ToReview( { rules } ) {
	const items = ( rules || [] )
		.filter( ( rule ) => rule.enabled !== false && rule.count > 0 )
		.sort(
			( a, b ) => ( LEVELS[ b.severity ] || 0 ) - ( LEVELS[ a.severity ] || 0 ) || b.count - a.count
		);
	return (
		<Card className="msradar-to-review">
			<CardHeader>
				<h2>{ __( 'To review', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				{ items.length === 0 ? (
					<p>{ __( 'No alert on the network.', 'multisite-radar' ) }</p>
				) : (
					<ul className="msradar-to-review__list">
						{ items.map( ( rule ) => (
							<li key={ rule.rule }>
								<SeverityBadge level={ rule.severity } />{ ' ' }
								<a href={ pageUrl( 'sites', { rule: rule.rule } ) }>{ rule.label }</a>{ ' ' }
								<span className="msradar-to-review__count">
									{ sprintf(
										/* translators: %d: number of sites. */
										_n( '%d site', '%d sites', rule.count, 'multisite-radar' ),
										rule.count
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}
```

`src/views/overview/scan-panel.jsx` :

```jsx
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, formatRelative } from '../../utils/format';

export function ScanProgress( { scan } ) {
	return (
		<div className="msradar-progress">
			<progress
				max={ Math.max( 1, scan.total ) }
				value={ scan.processed }
				aria-label={ __( 'Analysis progress', 'multisite-radar' ) }
			/>
			<span>
				{ sprintf(
					/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
					__( '%1$d of %2$d sites analysed', 'multisite-radar' ),
					scan.processed,
					scan.total
				) }
			</span>
		</div>
	);
}

export function ScanPanel( { status, scan, canManage } ) {
	return (
		<Card className="msradar-scan">
			<CardHeader>
				<h2>{ __( 'Analysis', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<dl className="msradar-facts">
					<dt>{ __( 'Last full analysis', 'multisite-radar' ) }</dt>
					<dd>
						{ status?.last_full_scan_gmt
							? formatRelative( status.last_full_scan_gmt )
							: __( 'Never', 'multisite-radar' ) }
					</dd>
					<dt>{ __( 'Sites to refresh', 'multisite-radar' ) }</dt>
					<dd>{ formatNumber( status?.remaining ?? 0 ) }</dd>
					<dt>{ __( 'Next background run', 'multisite-radar' ) }</dt>
					<dd>
						{ status?.next_run_gmt
							? formatRelative( status.next_run_gmt )
							: __( 'Not scheduled', 'multisite-radar' ) }
					</dd>
				</dl>
				{ scan.running && <ScanProgress scan={ scan } /> }
				{ canManage && (
					<Button
						variant="primary"
						isBusy={ scan.running }
						disabled={ scan.running }
						onClick={ () => scan.start( { scope: 'all' } ) }
					>
						{ __( 'Analyse all sites', 'multisite-radar' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
```

`src/views/overview/index.jsx` :

```jsx
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { useScan } from '../../hooks/use-scan';
import { buildPath } from '../../store/paths';
import { ScanPanel, ScanProgress } from './scan-panel';
import { Tiles, ToReview } from './tiles';

function FirstRun( { total, scan, canManage } ) {
	return (
		<Notice status="info" isDismissible={ false } className="msradar-first-run">
			<p>
				{ sprintf(
					/* translators: %d: number of sites in the network. */
					_n(
						'Multisite Radar has not analysed your %d site yet. The analysis runs in the background; you can also start it now.',
						'Multisite Radar has not analysed your %d sites yet. The analysis runs in the background; you can also start it now.',
						total,
						'multisite-radar'
					),
					total
				) }
			</p>
			{ scan.running && <ScanProgress scan={ scan } /> }
			{ canManage && ! scan.running && (
				<Button variant="primary" onClick={ () => scan.start( { scope: 'all' } ) }>
					{ __( 'Start the analysis', 'multisite-radar' ) }
				</Button>
			) }
		</Notice>
	);
}

export default function OverviewView() {
	const { canManage } = getConfig();
	const summary = useResource( buildPath( '/alerts/summary' ) );
	const status = useResource( buildPath( '/scan/status' ) );
	const scan = useScan();
	const data = summary.data;
	const firstRun = !! data && data.total_sites > 0 && data.scanned_sites === 0;

	return (
		<div className="msradar-overview">
			<ErrorNotice error={ summary.error } onRetry={ summary.retry } />
			<ErrorNotice error={ status.error } onRetry={ status.retry } />
			{ firstRun && <FirstRun total={ data.total_sites } scan={ scan } canManage={ canManage } /> }
			{ data && <Tiles summary={ data } /> }
			<div className="msradar-overview__columns">
				{ data && <ToReview rules={ data.by_rule } /> }
				<ScanPanel status={ status.data } scan={ scan } canManage={ canManage } />
			</div>
		</div>
	);
}
```

Remplacer `src/admin/overview.js` par :

```js
import { mount } from './mount';
import OverviewView from '../views/overview';
import './style.scss';

mount( OverviewView );
```

Ajouter à la fin de `src/admin/style.scss` :

```scss
.msradar-tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px;
	margin: 0 0 24px;
	padding: 0;
	list-style: none;
}

.msradar-tile {
	margin: 0;
	border: 1px solid #dcdcde;
	border-left-width: 4px;
	border-radius: 2px;
	background: #fff;

	&--error {
		border-left-color: #d63638;
	}

	&--warning {
		border-left-color: #dba617;
	}

	&--info {
		border-left-color: #2271b1;
	}

	&__link {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 16px;
		color: inherit;
		text-decoration: none;

		&:focus {
			box-shadow: 0 0 0 2px var(--wp-admin-theme-color, #3858e9);
			outline: none;
		}
	}

	&__value {
		font-size: 28px;
		font-weight: 600;
		line-height: 1.2;
	}

	&__detail {
		color: #50575e;
		font-size: 12px;
	}
}

.msradar-overview__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 16px;
	align-items: start;
}

.msradar-to-review__list li {
	margin-bottom: 8px;
}

.msradar-first-run {
	margin: 0 0 24px;
}

.msradar-progress {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 12px 0;

	progress {
		flex: 1;
		max-width: 320px;
	}
}
```

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS.

- [ ] **Step 5: Vérifier dans le WordPress local**

Run: `npm run build`

Ouvrir `…/wp-admin/network/admin.php?page=multisite-radar` et vérifier :
- les tuiles et « To review » s'affichent sans requête au chargement ;
- « Analyse all sites » montre la progression, puis le message « Analysis complete. » ;
- les tuiles se mettent à jour après l'analyse.

- [ ] **Step 6: Lint, build et commit**

Run: `npm run lint:js && npm run lint:css && npm run build`
Expected: aucune erreur.

```bash
git add src
git commit -m "feat: overview with severity tiles, a to-review list and UI-driven analysis"
```

---

### Task 17: Vue Alertes — liste site × règle, regroupée par règle

**Files:**
- Create: `src/views/alerts/query.js`, `src/views/alerts/fields.jsx`, `src/views/alerts/index.jsx`
- Modify: `src/admin/alerts.js`
- Test: `src/views/alerts/test/query.test.js`, `src/views/alerts/test/alerts-view.test.jsx`

**Interfaces:**
- Consumes :
  - REST `GET /alerts` (tâche 5) et `GET /alerts/summary` ;
  - `src/utils/view-query.js` (tâche 14) et le socle de la tâche 13 ;
  - `tests/fixtures/view-queries.json`.
- Produces :
  - **`src/views/alerts/query.js`** :
    - les constantes `ALERT_ORDERBY`, `SEVERITIES`, `FIELD_TO_ORDERBY`, `ORDERBY_TO_FIELD` et `DEFAULT_FIELDS` ;
    - les fonctions `parseAlertsQuery`, `alertsRestArgs`, `alertsPath`, `serializeAlertsState`, `toAlertsView`, `fromAlertsView` et `alertsPrefsFromView`.
  - **`src/views/alerts/fields.jsx`** : `getAlertsFields( rules )`.
  - **`src/views/alerts/index.jsx`** : `AlertsView`. Les éléments sont regroupés par règle (`view.groupBy`) quand le tri est par règle. Deux actions : ouvrir la fiche dans la vue Sites, et le tableau de bord du site.

- [ ] **Step 1: Écrire les tests qui échouent**

`src/views/alerts/test/query.test.js` :

```js
import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	alertsPath,
	alertsPrefsFromView,
	alertsRestArgs,
	fromAlertsView,
	parseAlertsQuery,
	serializeAlertsState,
	toAlertsView,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'alerts' ) )( '$name', ( { query, prefs, args } ) => {
	test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
		expect( alertsRestArgs( parseAlertsQuery( query ), prefs ) ).toEqual( args );
	} );
} );

describe( 'alerts view state', () => {
	const state = parseAlertsQuery( { severity: 'warning,error', rule: 'no_users', orderby: 'rule' } );

	test( 'path, grouping and filters', () => {
		expect( alertsPath( state, {} ) ).toBe(
			'/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20&rule=no_users&severity=error%2Cwarning'
		);
		const view = toAlertsView( state, { fields: [], per_page: 20 } );
		expect( view.groupBy ).toEqual( { field: 'rule' } );
		expect( view.sort ).toEqual( { field: 'rule', direction: 'asc' } );
		expect( view.filters ).toEqual( [
			{ field: 'severity', operator: 'isAny', value: [ 'error', 'warning' ] },
			{ field: 'rule', operator: 'isAny', value: [ 'no_users' ] },
		] );
		expect( toAlertsView( { ...state, orderby: 'name' }, null ).groupBy ).toBeUndefined();
	} );

	test( 'a DataViews change maps back to the state and to the preferences', () => {
		const view = {
			search: ' rh ',
			page: 2,
			perPage: 50,
			sort: { field: 'site', direction: 'desc' },
			filters: [ { field: 'rule', operator: 'isAny', value: [ 'inactive', 'Bad Rule', 'inactive' ] } ],
			fields: [ 'site', 'message' ],
		};

		expect( fromAlertsView( view, state ) ).toEqual( {
			search: 'rh',
			page: 2,
			orderby: 'name',
			order: 'desc',
			severity: [],
			rule: [ 'inactive' ],
		} );
		expect( alertsPrefsFromView( view ) ).toEqual( { fields: [ 'site', 'message' ], per_page: 50 } );
		expect( serializeAlertsState( parseAlertsQuery( {} ) ) ).toEqual( {} );
	} );
} );
```

`src/views/alerts/test/alerts-view.test.jsx` :

```jsx
import { expect, test, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import AlertsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn( () => new Promise( () => {} ) ) } ) );

test( 'renders the preloaded alerts without any request', () => {
	window.history.replaceState( null, '', '/wp-admin/network/admin.php?page=multisite-radar-alerts' );
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/alerts/summary': {
			body: { by_rule: [ { rule: 'no_users', label: 'Site without users', severity: 'error', enabled: true, count: 1 } ] },
			headers: {},
		},
		'/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20': {
			body: [
				{
					id: '2:no_users',
					site: { id: 2, name: 'Site vide', url: 'https://example.test/vide/', admin_url: 'https://example.test/vide/wp-admin/' },
					rule: 'no_users',
					label: 'Site without users',
					severity: 'error',
					message: 'No user is attached to this site.',
				},
			],
			headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' },
		},
	};
	window.msradarAdmin = { view: 'alerts', canManage: true, pages: {}, preload };
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );

	render(
		<RegistryProvider value={ registry }>
			<AlertsView />
		</RegistryProvider>
	);

	expect( screen.getByText( 'Site vide' ) ).toBeInTheDocument();
	expect( screen.getByText( 'No user is attached to this site.' ) ).toBeInTheDocument();
	expect( apiFetch ).not.toHaveBeenCalled();
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/views/alerts`
Expected: FAIL (`Failed to resolve import "../query"`).

- [ ] **Step 3: Implémenter**

`src/views/alerts/query.js` :

```js
import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath } from '../../store/paths';
import { MAX_PAGE, page, perPage, RULE_PATTERN, subset, text, trimAscii } from '../../utils/view-query';

export const ALERT_ORDERBY = [ 'rule', 'name', 'severity' ];
export const SEVERITIES = [ 'error', 'warning', 'info' ];
export const FIELD_TO_ORDERBY = { rule: 'rule', site: 'name', severity: 'severity' };
export const ORDERBY_TO_FIELD = { rule: 'rule', name: 'site', severity: 'severity' };
export const DEFAULT_FIELDS = [ 'site', 'severity', 'message' ];

/**
 * Identifiants de règles valides, sans doublon, triés : même résultat que Admin\ViewQuery::alerts().
 *
 * @param {string[]} values Identifiants donnés.
 */
function ruleList( values ) {
	return [ ...new Set( values.map( trimAscii ).filter( ( rule ) => RULE_PATTERN.test( rule ) ) ) ].sort();
}

export function parseAlertsQuery( query ) {
	const orderby = text( query, 'orderby' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: ALERT_ORDERBY.includes( orderby ) ? orderby : 'rule',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		severity: subset( query, 'severity', SEVERITIES ),
		rule: ruleList( text( query, 'rule' ).split( ',' ) ),
	};
}

export function alertsRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.alerts?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.severity.length > 0 ) {
		args.severity = state.severity.join( ',' );
	}
	if ( state.rule.length > 0 ) {
		args.rule = state.rule.join( ',' );
	}
	return args;
}

export function alertsPath( state, prefs ) {
	return buildPath( '/alerts', alertsRestArgs( state, prefs ) );
}

export function serializeAlertsState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'rule' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	if ( state.severity.length > 0 ) {
		out.severity = state.severity.join( ',' );
	}
	if ( state.rule.length > 0 ) {
		out.rule = state.rule.join( ',' );
	}
	return out;
}

export function toAlertsView( state, alertsPrefs ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( alertsPrefs?.per_page ),
		sort: { field: ORDERBY_TO_FIELD[ state.orderby ], direction: state.order },
		filters: toFilters( [
			{ field: 'severity', operator: 'isAny', value: state.severity },
			{ field: 'rule', operator: 'isAny', value: state.rule },
		] ),
		titleField: 'site',
		fields: alertsPrefs?.fields?.length ? alertsPrefs.fields : DEFAULT_FIELDS,
		// DataViews regroupe les éléments de la page : cela n'a de sens que trié par règle.
		groupBy: state.orderby === 'rule' ? { field: 'rule' } : undefined,
		layout: {},
	};
}

export function fromAlertsView( view, current ) {
	const severity = filterValue( view.filters, 'severity', [] );
	const rules = filterValue( view.filters, 'rule', [] );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'rule',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		severity: SEVERITIES.filter( ( value ) => ( Array.isArray( severity ) ? severity : [ severity ] ).includes( value ) ),
		rule: ruleList( ( Array.isArray( rules ) ? rules : [ rules ] ).map( String ) ),
	};
}

export function alertsPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
```

`src/views/alerts/fields.jsx` :

```jsx
import { __ } from '@wordpress/i18n';
import { SeverityBadge, severityLabels } from '../../components/badges';
import { displayUrl } from '../../utils/format';
import { SEVERITIES } from './query';

export function getAlertsFields( rules = [] ) {
	const severities = severityLabels();
	return [
		{
			id: 'site',
			type: 'text',
			label: __( 'Site', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => item.site.name,
			render: ( { item } ) => (
				<span className="msradar-site-title">
					<span className="msradar-site-title__name">{ item.site.name }</span>
					<span className="msradar-site-title__url">{ displayUrl( item.site.url ) }</span>
				</span>
			),
		},
		{
			id: 'rule',
			type: 'text',
			label: __( 'Rule', 'multisite-radar' ),
			elements: rules.map( ( rule ) => ( { value: rule.rule, label: rule.label } ) ),
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item.rule,
			render: ( { item } ) => item.label,
		},
		{
			id: 'severity',
			type: 'text',
			label: __( 'Severity', 'multisite-radar' ),
			elements: SEVERITIES.map( ( value ) => ( { value, label: severities[ value ] } ) ),
			filterBy: { operators: [ 'isAny' ] },
			render: ( { item } ) => <SeverityBadge level={ item.severity } />,
		},
		{
			id: 'message',
			type: 'text',
			label: __( 'Details', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
		},
	];
}
```

`src/views/alerts/index.jsx` :

```jsx
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { info, wordpress } from '@wordpress/icons';
import { pageUrl } from '../../admin/config';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { getAlertsFields } from './fields';
import {
	alertsPath,
	alertsPrefsFromView,
	fromAlertsView,
	parseAlertsQuery,
	serializeAlertsState,
	toAlertsView,
} from './query';

function actions() {
	return [
		{
			id: 'open',
			label: __( 'View the site', 'multisite-radar' ),
			icon: info,
			isPrimary: true,
			callback: ( [ item ] ) => window.location.assign( pageUrl( 'sites', { site: item.site.id } ) ),
		},
		{
			id: 'admin',
			label: __( 'Site dashboard', 'multisite-radar' ),
			icon: wordpress,
			isEligible: ( item ) => !! item.site.admin_url,
			callback: ( [ item ] ) => window.location.assign( item.site.admin_url ),
		},
	];
}

export default function AlertsView() {
	const [ state, setState ] = useUrlState( parseAlertsQuery, serializeAlertsState );
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'alerts' );
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const alertsPrefs = localPrefs || prefs?.alerts || null;

	const summary = useResource( buildPath( '/alerts/summary' ) );
	const list = useResource( alertsPrefs ? alertsPath( state, { alerts: alertsPrefs } ) : null );
	const rules = summary.data?.by_rule;
	const fields = useMemo( () => getAlertsFields( rules || [] ), [ rules ] );
	const view = useMemo( () => toAlertsView( state, alertsPrefs ), [ state, alertsPrefs ] );
	const rowActions = useMemo( () => actions(), [] );

	const onChangeView = ( next ) => {
		setState( ( current ) => fromAlertsView( next, current ) );
		const nextPrefs = alertsPrefsFromView( next );
		if (
			! alertsPrefs ||
			nextPrefs.per_page !== alertsPrefs.per_page ||
			JSON.stringify( nextPrefs.fields ) !== JSON.stringify( alertsPrefs.fields )
		) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-alerts">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ rowActions }
				defaultLayouts={ { table: {} } }
				paginationInfo={ { totalItems: list.total || 0, totalPages: list.totalPages || 0 } }
				isLoading={ list.isLoading && ! list.data }
				getItemId={ ( item ) => item.id }
				searchLabel={ __( 'Search sites', 'multisite-radar' ) }
				empty={ <p className="msradar-empty">{ __( 'No alert matches this view.', 'multisite-radar' ) }</p> }
			/>
		</div>
	);
}
```

Remplacer `src/admin/alerts.js` par :

```js
import { mount } from './mount';
import AlertsView from '../views/alerts';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( AlertsView );
```

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS.

- [ ] **Step 5: Lint, build, vérification et commit**

Run: `npm run lint:js && npm run build`
Expected: aucune erreur.

Ouvrir `…/wp-admin/network/admin.php?page=multisite-radar-alerts` et vérifier :
- les alertes sont regroupées par règle ;
- filtrer par gravité met l'URL à jour ;
- « View the site » ouvre la fiche dans la vue Sites.

```bash
git add src
git commit -m "feat: Alerts view listing site and rule pairs grouped by rule"
```

---

### Task 18: Vue Réglages — DataForm (analyse et menu des sites)

**Files:**
- Create: `src/views/settings/fields.js`, `src/views/settings/index.jsx`
- Modify: `src/admin/settings.js`
- Test: `src/views/settings/test/settings-view.test.jsx`

**Interfaces:**
- Consumes :
  - REST `GET/POST /settings` (M1 ; validation du schéma `Settings::schema()`) ;
  - `getConfig().postTypes` et `getConfig().plugins` (tâche 12) ;
  - `DataForm` et `useFormValidity` (adaptateur de la tâche 13).
- Produces :
  - `src/views/settings/fields.js` : `getSettingsFields( { postTypes, plugins } )`, `SCAN_FORM`, `MENU_FORM`, `mergeDeep( base, patch )` et `toPayload( settings )` ;
  - `src/views/settings/index.jsx` : `SettingsView`. L'enregistrement envoie `{ scan: { activity_post_types, analysis_plugins, full_rescan_days }, sites_menu: { enabled } }` ;
  - après un enregistrement réussi :
    - le cache `/settings` est mis à jour ;
    - les listes `/sites` sont invalidées (le filtre « limiter aux plugins » change la fiche) ;
    - un message « Settings saved. » s'affiche en snackbar.
- **Hors périmètre :** `scan.measure_disk` (la mesure arrive avec M4), `reports`, `integrations`, `retention` et les règles d'alertes (écart E11).

- [ ] **Step 1: Écrire les tests qui échouent**

`src/views/settings/test/settings-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import { mergeDeep, toPayload } from '../fields';
import SettingsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const SETTINGS = {
	scan: { activity_post_types: [ 'post', 'page' ], analysis_plugins: [], measure_disk: true, full_rescan_days: 7 },
	alerts: { rules: {} },
	reports: { digest_enabled: false, digest_day: 1, digest_recipients: { mode: 'super_admins', emails: [] } },
	integrations: { mcp_public: false },
	sites_menu: { enabled: false },
	retention: { events_days: 90, snapshots_days: 365 },
};

function setup() {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/settings': { body: SETTINGS, headers: {} },
	};
	window.msradarAdmin = {
		view: 'settings',
		canManage: true,
		preload,
		postTypes: [ { value: 'post', label: 'Posts' }, { value: 'page', label: 'Pages' } ],
		plugins: [ { value: 'msradar-demo-cpt', label: 'Demo CPT' } ],
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<SettingsView />
		</RegistryProvider>
	);
	return registry;
}

function notices( registry ) {
	return registry.select( noticesStore ).getNotices().map( ( notice ) => notice.content );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

test( 'helpers merge nested edits and send only the sections of this screen', () => {
	const merged = mergeDeep( SETTINGS, { scan: { full_rescan_days: 14 }, sites_menu: { enabled: true } } );

	expect( merged.scan ).toEqual( { ...SETTINGS.scan, full_rescan_days: 14 } );
	expect( toPayload( merged ) ).toEqual( {
		scan: { activity_post_types: [ 'post', 'page' ], analysis_plugins: [], full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );
} );

test( 'shows the preloaded settings and saves a change', async () => {
	apiFetch.mockResolvedValue( { ...SETTINGS, sites_menu: { enabled: true } } );
	const registry = setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	expect( save ).toBeDisabled();

	fireEvent.click( screen.getByRole( 'checkbox', { name: /network sites menu/ } ) );
	await act( async () => {
		fireEvent.click( save );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/settings',
		method: 'POST',
		data: {
			scan: { activity_post_types: [ 'post', 'page' ], analysis_plugins: [], full_rescan_days: 7 },
			sites_menu: { enabled: true },
		},
	} );
	await waitFor( () => expect( notices( registry ) ).toContain( 'Settings saved.' ) );
} );

test( 'a rejected save is reported', async () => {
	apiFetch.mockRejectedValue( { code: 'msradar_invalid_settings', message: 'settings[scan][full_rescan_days] must be between 1 (inclusive) and 90 (inclusive)' } );
	const registry = setup();

	fireEvent.click( screen.getByRole( 'checkbox', { name: /network sites menu/ } ) );
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
	} );

	await waitFor( () =>
		expect( notices( registry ) ).toContain( 'settings[scan][full_rescan_days] must be between 1 (inclusive) and 90 (inclusive)' )
	);
} );
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/views/settings`
Expected: FAIL (`Failed to resolve import "../fields"`).

- [ ] **Step 3: Implémenter**

`src/views/settings/fields.js` :

```js
import { __ } from '@wordpress/i18n';

const POST_TYPE_KEY = /^[a-z0-9_-]{1,20}$/;

/**
 * Champs DataForm des réglages, adressés par chemin pointé dans l'objet des réglages (msradar_settings).
 *
 * @param {Object} options
 * @param {Array}  options.postTypes Suggestions { value, label } (types publics du site principal).
 * @param {Array}  options.plugins   Plugins installés { value, label }.
 */
export function getSettingsFields( { postTypes = [], plugins = [] } ) {
	return [
		{
			id: 'scan.activity_post_types',
			type: 'array',
			label: __( 'Content types that count as activity', 'multisite-radar' ),
			description: __( 'Publishing or updating one of these types counts as activity (last activity, inactivity alert). Other keys can be typed in.', 'multisite-radar' ),
			elements: postTypes,
			isValid: {
				required: true,
				elements: false,
				custom: ( item ) =>
					( item.scan?.activity_post_types || [] ).every( ( type ) => POST_TYPE_KEY.test( type ) )
						? null
						: __( 'Use content type keys: lowercase letters, digits, "-" and "_", 20 characters at most.', 'multisite-radar' ),
			},
		},
		{
			id: 'scan.analysis_plugins',
			type: 'array',
			label: __( 'Only list the content types of these plugins', 'multisite-radar' ),
			description: __( 'Leave empty to list the content types of every plugin.', 'multisite-radar' ),
			elements: plugins,
		},
		{
			id: 'scan.full_rescan_days',
			type: 'integer',
			label: __( 'Full analysis every (days)', 'multisite-radar' ),
			description: __( 'Every site is analysed again at this interval, to catch changes made directly in the database.', 'multisite-radar' ),
			isValid: { required: true, min: 1, max: 90 },
		},
		{
			id: 'sites_menu.enabled',
			type: 'boolean',
			label: __( 'Enable the network sites menu (block, shortcode and menu items)', 'multisite-radar' ),
			Edit: 'toggle',
		},
	];
}

export const SCAN_FORM = {
	layout: { type: 'regular' },
	fields: [ 'scan.activity_post_types', 'scan.analysis_plugins', 'scan.full_rescan_days' ],
};

export const MENU_FORM = {
	layout: { type: 'regular' },
	fields: [ 'sites_menu.enabled' ],
};

export const ALL_FORM = { layout: { type: 'regular' }, fields: [ ...SCAN_FORM.fields, ...MENU_FORM.fields ] };

function isObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

/**
 * Fusion récursive des objets ; une liste est remplacée (comme Settings::merge() en PHP).
 *
 * @param {Object} base  Valeurs de départ.
 * @param {Object} patch Modifications.
 */
export function mergeDeep( base, patch ) {
	const out = { ...base };
	Object.entries( patch || {} ).forEach( ( [ key, value ] ) => {
		out[ key ] = isObject( value ) && isObject( base?.[ key ] ) ? mergeDeep( base[ key ], value ) : value;
	} );
	return out;
}

/**
 * Seules les sections de cet écran sont envoyées : les autres restent telles qu'enregistrées.
 *
 * @param {Object} settings Réglages complets modifiés.
 */
export function toPayload( settings ) {
	return {
		scan: {
			activity_post_types: settings.scan.activity_post_types,
			analysis_plugins: settings.scan.analysis_plugins,
			full_rescan_days: settings.scan.full_rescan_days,
		},
		sites_menu: { enabled: !! settings.sites_menu.enabled },
	};
}
```

`src/views/settings/index.jsx` :

```jsx
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { getConfig } from '../../admin/config';
import { DataForm, useFormValidity } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { STORE_NAME, toResponse } from '../../store';
import { buildPath } from '../../store/paths';
import { ALL_FORM, getSettingsFields, MENU_FORM, mergeDeep, SCAN_FORM, toPayload } from './fields';

const SETTINGS_PATH = buildPath( '/settings' );

export default function SettingsView() {
	const settings = useResource( SETTINGS_PATH );
	const [ edits, setEdits ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const { receiveResponse, invalidate } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const fields = useMemo( () => getSettingsFields( getConfig() ), [] );
	const data = useMemo( () => mergeDeep( settings.data || {}, edits ), [ settings.data, edits ] );
	const { validity, isValid } = useFormValidity( data, fields, ALL_FORM );
	const dirty = Object.keys( edits ).length > 0;

	const onChange = ( patch ) => setEdits( ( current ) => mergeDeep( current, patch ) );

	const save = async () => {
		setSaving( true );
		try {
			const saved = await apiFetch( { path: SETTINGS_PATH, method: 'POST', data: toPayload( data ) } );
			receiveResponse( SETTINGS_PATH, toResponse( saved ) );
			setEdits( {} );
			invalidate( buildPath( '/sites' ) );
			createSuccessNotice( __( 'Settings saved.', 'multisite-radar' ), { type: 'snackbar' } );
		} catch ( error ) {
			createErrorNotice( error?.message || __( 'The settings could not be saved.', 'multisite-radar' ), {
				type: 'snackbar',
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<form
			className="msradar-settings"
			onSubmit={ ( event ) => {
				event.preventDefault();
				save();
			} }
		>
			<ErrorNotice error={ settings.error } onRetry={ settings.retry } />
			{ settings.data && (
				<>
					<Card>
						<CardHeader>
							<h2>{ __( 'Analysis', 'multisite-radar' ) }</h2>
						</CardHeader>
						<CardBody>
							<DataForm data={ data } fields={ fields } form={ SCAN_FORM } validity={ validity } onChange={ onChange } />
						</CardBody>
					</Card>
					<Card>
						<CardHeader>
							<h2>{ __( 'Network sites menu', 'multisite-radar' ) }</h2>
						</CardHeader>
						<CardBody>
							<DataForm data={ data } fields={ fields } form={ MENU_FORM } validity={ validity } onChange={ onChange } />
						</CardBody>
					</Card>
					<p>
						<Button variant="primary" type="submit" isBusy={ saving } disabled={ saving || ! dirty || ! isValid }>
							{ __( 'Save settings', 'multisite-radar' ) }
						</Button>
					</p>
				</>
			) }
		</form>
	);
}
```

Remplacer `src/admin/settings.js` par :

```js
import { mount } from './mount';
import SettingsView from '../views/settings';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( SettingsView );
```

Ajouter à la fin de `src/admin/style.scss` :

```scss
.msradar-settings {
	max-width: 760px;

	.components-card {
		margin-bottom: 16px;
	}
}
```

- [ ] **Step 4: Lancer les tests**

Run: `npm run test:unit`
Expected: PASS.

Si `getByRole( 'checkbox', { name: /network sites menu/ } )` ne trouve pas le contrôle `toggle` de DataForm (son rôle peut être `switch` selon la version), utiliser le rôle effectivement rendu (`switch`). Garder l'assertion sur le nom accessible.

- [ ] **Step 5: Lint, build, vérification et commit**

Run: `npm run lint:js && npm run build`
Expected: aucune erreur.

Dans `…/wp-admin/network/admin.php?page=multisite-radar-settings`, vérifier :
- activer le menu des sites puis enregistrer affiche « Settings saved. » ;
- après rechargement, le réglage est conservé ;
- une valeur de 0 jour rend le bouton inactif et le message de validation s'affiche.

```bash
git add src
git commit -m "feat: Settings view with DataForm for analysis and the sites menu"
```

---
### Task 19: Bloc « Network sites » (`multisite-radar/sites-list`)

**Files:**
- Create: `src/blocks/sites-list/block.json`, `src/blocks/sites-list/index.js`, `src/blocks/sites-list/edit.jsx`, `src/blocks/sites-list/tokens.js`, `src/blocks/sites-list/style.scss`, `includes/SitesMenu/Block.php`, `tests/php/fixtures/build/blocks/sites-list/block.json`
- Modify: `includes/SitesMenu/Module.php`
- Test: `src/blocks/sites-list/test/tokens.test.js`, `tests/php/SitesMenu/BlockTest.php`

**Interfaces:**
- Consumes : `SitesListCache::get()`, `Renderer::select()`, `Renderer::render()` et `Renderer::label()` (tâche 9).
- Produces :
  - `Block::NAME = 'multisite-radar/sites-list'` ;
  - `Block::register(): bool` : faux si le build est absent ou si le bloc est déjà enregistré ;
  - `Block::render( $attributes ): string` et `Block::editor_data()` ;
  - `Module::block(): Block`, enregistré dans `Module::init()` quand le module est actif ;
  - **attributs** :
    - `include` et `exclude` : `int[]`, défaut `[]` ;
    - `orderBy` : `name` (défaut), `id` ou `registered` ;
    - `order` : `asc` (défaut) ou `desc` ;
    - `layout` : `list` (défaut) ou `inline` ;
  - le rendu `<ul class="wp-block-multisite-radar-sites-list msradar-sites msradar-sites--<layout>">…</ul>` (avec les classes de supports), ou une chaîne vide s'il n'y a aucun site ;
  - côté éditeur, `window.msradarSitesList`, une liste de `{ id, name }`, alimente les champs de sélection.

- [ ] **Step 1: Écrire les tests qui échouent**

`src/blocks/sites-list/test/tokens.test.js` :

```js
import { expect, test } from 'vitest';
import { idsToTokens, tokenLabel, tokensToIds } from '../tokens';

const SITES = [
	{ id: 1, name: 'Main' },
	{ id: 12, name: 'Blog RH' },
	{ id: 13, name: '' },
];

test( 'sites are shown as "name (#id)" tokens and read back as IDs', () => {
	expect( tokenLabel( SITES[ 1 ] ) ).toBe( 'Blog RH (#12)' );
	expect( tokenLabel( SITES[ 2 ] ) ).toBe( '#13 (#13)' );
	expect( idsToTokens( [ 12, 99 ], SITES ) ).toEqual( [ 'Blog RH (#12)', '#99' ] );
	expect( tokensToIds( [ 'Blog RH (#12)', 'Main', '#99', { value: 'Main' }, 'Unknown' ], SITES ) ).toEqual( [ 12, 1, 99 ] );
} );
```

`tests/php/fixtures/build/blocks/sites-list/block.json` — même déclaration que la source, sans scripts ni styles (les tests n'ont pas de build) :

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "multisite-radar/sites-list",
	"title": "Network sites",
	"category": "widgets",
	"textdomain": "multisite-radar",
	"attributes": {
		"include": { "type": "array", "items": { "type": "integer" }, "default": [] },
		"exclude": { "type": "array", "items": { "type": "integer" }, "default": [] },
		"orderBy": { "type": "string", "enum": [ "name", "id", "registered" ], "default": "name" },
		"order": { "type": "string", "enum": [ "asc", "desc" ], "default": "asc" },
		"layout": { "type": "string", "enum": [ "list", "inline" ], "default": "list" }
	},
	"supports": { "html": false }
}
```

`tests/php/SitesMenu/BlockTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\Block;
use MultisiteRadar\Tests\TestCase;
use WP_Block_Type_Registry;

final class BlockTest extends TestCase {

	private Block $block;

	public function set_up(): void {
		parent::set_up();
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			unregister_block_type( Block::NAME );
		}
		$this->block = new Block( $this->plugin()->sites_list_cache(), dirname( __DIR__ ) . '/fixtures/build/blocks/sites-list/' );
		$this->assertTrue( $this->block->register() );
	}

	public function tear_down(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			unregister_block_type( Block::NAME );
		}
		parent::tear_down();
	}

	private function render( array $attrs ): string {
		return render_block(
			[
				'blockName'    => Block::NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	private function classes( string $html ): array {
		$this->assertSame( 1, preg_match( '/^<ul class="([^"]*)">/', $html, $match ) );
		return explode( ' ', $match[1] );
	}

	public function test_renders_the_chosen_sites_by_name_with_the_block_wrapper(): void {
		$bravo = self::factory()->blog->create( [ 'title' => 'Bravo' ] );
		$alpha = self::factory()->blog->create( [ 'title' => 'Alpha' ] );

		$html = $this->render( [ 'include' => [ $bravo, $alpha ] ] );

		$classes = $this->classes( $html );
		$this->assertContains( 'wp-block-multisite-radar-sites-list', $classes );
		$this->assertContains( 'msradar-sites--list', $classes );
		$this->assertLessThan( strpos( $html, 'Bravo' ), strpos( $html, 'Alpha' ), 'Sorted by name by default.' );
	}

	public function test_attributes_exclude_order_and_lay_out_the_list(): void {
		$bravo = self::factory()->blog->create( [ 'title' => 'Bravo' ] );
		$alpha = self::factory()->blog->create( [ 'title' => 'Alpha' ] );
		$third = self::factory()->blog->create( [ 'title' => 'Charlie' ] );

		$html = $this->render(
			[
				'include' => [ $bravo, $alpha, $third ],
				'exclude' => [ $alpha ],
				'orderBy' => 'id',
				'order'   => 'desc',
				'layout'  => 'inline',
			]
		);

		$this->assertContains( 'msradar-sites--inline', $this->classes( $html ) );
		$this->assertStringNotContainsString( 'Alpha', $html );
		$this->assertLessThan( strpos( $html, 'Bravo' ), strpos( $html, 'Charlie' ), 'Highest ID first.' );
	}

	public function test_nothing_is_rendered_without_matching_sites(): void {
		$this->assertSame( '', $this->render( [ 'include' => [ 999999 ] ] ) );
	}

	public function test_registration_needs_the_build_and_happens_once(): void {
		$this->assertFalse( $this->block->register(), 'Already registered.' );

		unregister_block_type( Block::NAME );
		$this->assertFalse( ( new Block( $this->plugin()->sites_list_cache(), '/nonexistent/' ) )->register() );
		$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `npx vitest run src/blocks && bin/test.sh --filter BlockTest`
Expected: FAIL (`Failed to resolve import "../tokens"` ; `Class "MultisiteRadar\SitesMenu\Block" not found`).

- [ ] **Step 3: Implémenter**

`src/blocks/sites-list/block.json` :

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "multisite-radar/sites-list",
	"title": "Network sites",
	"category": "widgets",
	"icon": "networking",
	"description": "The public sites of the network, as a list of links.",
	"keywords": [ "multisite", "network", "sites" ],
	"textdomain": "multisite-radar",
	"attributes": {
		"include": { "type": "array", "items": { "type": "integer" }, "default": [] },
		"exclude": { "type": "array", "items": { "type": "integer" }, "default": [] },
		"orderBy": { "type": "string", "enum": [ "name", "id", "registered" ], "default": "name" },
		"order": { "type": "string", "enum": [ "asc", "desc" ], "default": "asc" },
		"layout": { "type": "string", "enum": [ "list", "inline" ], "default": "list" }
	},
	"supports": {
		"html": false,
		"spacing": { "margin": true, "padding": true },
		"typography": { "fontSize": true }
	},
	"editorScript": "file:./index.js",
	"style": "file:./style-index.css"
}
```

`src/blocks/sites-list/tokens.js` :

```js
/**
 * Sites proposés par PHP (SitesMenu\Block::editor_data()).
 */
export function sitesFromWindow() {
	return Array.isArray( window.msradarSitesList ) ? window.msradarSitesList : [];
}

export function tokenLabel( site ) {
	return `${ site.name || `#${ site.id }` } (#${ site.id })`;
}

export function idsToTokens( ids, sites ) {
	return ( ids || [] ).map( ( id ) => {
		const site = sites.find( ( item ) => item.id === id );
		return site ? tokenLabel( site ) : `#${ id }`;
	} );
}

/**
 * Jetons saisis → identifiants : « Nom (#12) », « #12 » ou un nom exact ; les jetons inconnus sont ignorés.
 *
 * @param {Array} tokens Jetons de FormTokenField (chaînes ou { value }).
 * @param {Array} sites  Sites connus.
 */
export function tokensToIds( tokens, sites ) {
	const ids = [];
	( tokens || [] ).forEach( ( token ) => {
		const value = String( typeof token === 'string' ? token : token?.value ?? '' ).trim();
		const match = /#(\d+)\)?$/.exec( value );
		const id = match ? Number( match[ 1 ] ) : sites.find( ( site ) => site.name === value )?.id;
		if ( id && ! ids.includes( id ) ) {
			ids.push( id );
		}
	} );
	return ids;
}
```

`src/blocks/sites-list/edit.jsx` :

```jsx
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { FormTokenField, PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import { idsToTokens, sitesFromWindow, tokenLabel, tokensToIds } from './tokens';

export default function Edit( { attributes, setAttributes } ) {
	const sites = sitesFromWindow();
	const suggestions = sites.map( tokenLabel );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Sites', 'multisite-radar' ) }>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Only these sites', 'multisite-radar' ) }
						value={ idsToTokens( attributes.include, sites ) }
						suggestions={ suggestions }
						onChange={ ( tokens ) => setAttributes( { include: tokensToIds( tokens, sites ) } ) }
					/>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Hide these sites', 'multisite-radar' ) }
						value={ idsToTokens( attributes.exclude, sites ) }
						suggestions={ suggestions }
						onChange={ ( tokens ) => setAttributes( { exclude: tokensToIds( tokens, sites ) } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'multisite-radar' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'multisite-radar' ) }
						value={ attributes.orderBy }
						options={ [
							{ value: 'name', label: __( 'Name', 'multisite-radar' ) },
							{ value: 'id', label: __( 'Site ID', 'multisite-radar' ) },
							{ value: 'registered', label: __( 'Creation date', 'multisite-radar' ) },
						] }
						onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'multisite-radar' ) }
						value={ attributes.order }
						options={ [
							{ value: 'asc', label: __( 'Ascending', 'multisite-radar' ) },
							{ value: 'desc', label: __( 'Descending', 'multisite-radar' ) },
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'multisite-radar' ) }
						value={ attributes.layout }
						options={ [
							{ value: 'list', label: __( 'List', 'multisite-radar' ) },
							{ value: 'inline', label: __( 'Inline', 'multisite-radar' ) },
						] }
						onChange={ ( layout ) => setAttributes( { layout } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<ServerSideRender block="multisite-radar/sites-list" attributes={ attributes } />
			</div>
		</>
	);
}
```

`src/blocks/sites-list/index.js` :

```js
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Bloc dynamique : le rendu est fait en PHP (SitesMenu\Block::render()).
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
```

`src/blocks/sites-list/style.scss` :

```scss
.msradar-sites--inline {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5em 1.5em;
	padding-left: 0;
	list-style: none;
}
```

`includes/SitesMenu/Block.php` :

```php
<?php
namespace MultisiteRadar\SitesMenu;

use WP_Block_Type;
use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bloc dynamique multisite-radar/sites-list : liste des sites publics du réseau, rendue en PHP.
 * Sources dans src/blocks/sites-list, compilées par @wordpress/scripts dans build/blocks/sites-list.
 */
final class Block {

	public const NAME = 'multisite-radar/sites-list';

	private SitesListCache $cache;
	private string $dir;
	private ?WP_Block_Type $type = null;

	public function __construct( SitesListCache $cache, string $dir ) {
		$this->cache = $cache;
		$this->dir   = trailingslashit( $dir );
	}

	/**
	 * À appeler sur init. Rien n'est fait si le build manque ou si le bloc est déjà enregistré.
	 */
	public function register(): bool {
		if ( ! is_readable( $this->dir . 'block.json' ) || WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return false;
		}
		$type = register_block_type( $this->dir, [ 'render_callback' => [ $this, 'render' ] ] );
		if ( ! $type instanceof WP_Block_Type ) {
			return false;
		}
		$this->type = $type;
		foreach ( (array) $type->editor_script_handles as $handle ) {
			wp_set_script_translations( $handle, 'multisite-radar', MSRADAR_DIR . 'languages' );
		}
		add_action( 'enqueue_block_editor_assets', [ $this, 'editor_data' ] );
		return true;
	}

	/**
	 * Sites proposés dans les réglages du bloc : identifiant et nom des sites publics, déjà visibles de tous.
	 */
	public function editor_data(): void {
		if ( null === $this->type ) {
			return;
		}
		$sites = array_map(
			static fn ( array $site ): array => [
				'id'   => (int) $site['id'],
				'name' => Renderer::label( $site ),
			],
			$this->cache->get()
		);
		foreach ( (array) $this->type->editor_script_handles as $handle ) {
			wp_add_inline_script( $handle, 'window.msradarSitesList = ' . wp_json_encode( $sites, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}
	}

	/**
	 * @param mixed $attributes Attributs du bloc (validés par block.json).
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : [];
		$sites      = Renderer::select(
			$this->cache->get(),
			array_map( 'intval', (array) ( $attributes['include'] ?? [] ) ),
			array_map( 'intval', (array) ( $attributes['exclude'] ?? [] ) ),
			(string) ( $attributes['orderBy'] ?? 'name' ),
			(string) ( $attributes['order'] ?? 'asc' )
		);
		if ( [] === $sites ) {
			return '';
		}
		$layout = 'inline' === ( $attributes['layout'] ?? 'list' ) ? 'inline' : 'list';
		return Renderer::render( $sites, 'ul', get_block_wrapper_attributes( [ 'class' => 'msradar-sites msradar-sites--' . $layout ] ) );
	}
}
```

**`includes/SitesMenu/Module.php`** :
- ajouter la propriété `private ?Block $block = null;` et la méthode :

```php
	public function block(): Block {
		return $this->block ??= new Block( $this->cache, MSRADAR_DIR . 'build/blocks/sites-list/' );
	}
```

- dans `init()`, ajouter `$this->block()->register();` après `$this->nav_menu()->register_editor();`.

- [ ] **Step 4: Lancer les tests et le build**

Run: `npm run test:unit && npm run build && ls build/blocks/sites-list`
Expected: PASS, et `block.json index.asset.php index.js style-index.css style-index-rtl.css`.

Run: `bin/test.sh`
Expected: PASS.

- [ ] **Step 5: Vérifier dans le WordPress local**

Activer le module dans Réglages, puis vérifier :
- dans l'éditeur d'une page du site principal, le bloc « Network sites » est disponible ;
- son aperçu liste les sites ;
- les réglages « Only these sites », « Hide these sites », l'ordre et la présentation agissent sur l'aperçu ;
- sur la page publiée, la liste s'affiche, et en présentation « Inline », sur une ligne.

- [ ] **Step 6: Normes et commit**

Run: `composer lint && composer analyse && npm run lint:js && npm run lint:css`
Expected: aucune erreur.

```bash
git add src includes tests/php
git commit -m "feat: Network sites block rendered on the server"
```

---

## Partie E — Recette et livraison

### Task 20: Tests de bout en bout (Playwright, wp-env multisite) et audit d'accessibilité

**Files:**
- Create: `.wp-env.json`, `playwright.config.js`, `tests/e2e/setup.sh`, `tests/e2e/specs/journey.spec.js`, `tests/e2e/specs/preload.spec.js`, `tests/e2e/specs/a11y.spec.js`, `tests/e2e/specs/sites-menu.spec.js`
- Modify: `package.json`, `package-lock.json`, `eslint.config.cjs`, `.github/workflows/ci.yml`
- Test: les specs ci-dessus.

**Interfaces:**
- Consumes : toute l'interface (tâches 12 à 19) et le plugin de démonstration `tests/e2e/fixtures/msradar-demo-cpt` (M1).
- Produces :
  - les scripts npm `wp-env`, `e2e:setup` et `test:e2e` ;
  - le job CI `e2e-ui` (PHP 8.4, WordPress 7.1, spec §11.3 n° 5) ;
  - **données de recette :**
    - « Blog RH » (`/rh/`) a le plugin de démonstration actif ;
    - « Site vide » (`/vide/`) n'a aucun utilisateur, ce qui déclenche l'alerte « Site without users ».

- [ ] **Step 1: Outillage**

`package.json` :
- ajouter dans `devDependencies` (versions exactes) :

```json
		"@axe-core/playwright": "4.13.0",
		"@playwright/test": "1.63.0",
		"@wordpress/e2e-test-utils-playwright": "2.1.0",
		"@wordpress/env": "11.16.0",
```

- dans `scripts`, remplacer `lint:js` et ajouter trois scripts :

```json
		"lint:js": "wp-scripts lint-js src tests/js tests/e2e bin",
		"wp-env": "wp-env",
		"e2e:setup": "wp-env run cli sh wp-content/plugins/multisite-radar/tests/e2e/setup.sh",
		"test:e2e": "WP_BASE_URL=http://localhost:8888 wp-scripts test-playwright --config playwright.config.js",
```

Run: `npm install`
Expected: `package-lock.json` mis à jour, sans `ERESOLVE`.

`.wp-env.json` :

```json
{
	"core": "https://wordpress.org/wordpress-7.1.zip",
	"phpVersion": "8.4",
	"multisite": true,
	"plugins": [],
	"mappings": {
		"wp-content/plugins/multisite-radar": ".",
		"wp-content/plugins/msradar-demo-cpt": "./tests/e2e/fixtures/msradar-demo-cpt"
	}
}
```

Les plugins sont montés par `mappings`, sans activation automatique : `tests/e2e/setup.sh` active Multisite Radar sur le réseau et le plugin de démonstration sur `/rh/` seulement.

`playwright.config.js` :

```js
/**
 * Tests E2E sur le multisite wp-env (.wp-env.json), avec la configuration Playwright de @wordpress/scripts.
 * L'environnement est démarré à part (npm run wp-env -- start, puis npm run e2e:setup).
 */
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: path.join( __dirname, 'tests/e2e/specs' ),
	webServer: undefined,
};
```

`tests/e2e/setup.sh` (exécuté dans le conteneur `cli` de wp-env, sous `sh`) :

```sh
#!/bin/sh
# Prépare le réseau wp-env pour les tests E2E. Idempotent.
set -eu

URL=http://localhost:8888

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

wp multisite-radar scan --all --probe
```

`eslint.config.cjs` — ajouter, après `...defaultConfig,`, les règles Playwright pour `tests/e2e` :

```js
	...( require( '@wordpress/eslint-plugin' ).configs[ 'test-playwright' ] || [] ).map( ( config ) => ( {
		...config,
		files: [ 'tests/e2e/**/*.js' ],
	} ) ),
```

Si des règles `vitest/*` du profil `test-unit` signalent les specs Playwright, les désactiver dans un bloc `{ files: [ 'tests/e2e/**/*.js' ], rules: { … } }`, en ne citant que les règles réellement signalées.

- [ ] **Step 2: Écrire les specs**

`tests/e2e/specs/journey.spec.js` :

```js
import { readFile } from 'fs/promises';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Overview → filtered sites → site panel → export (spec 11.2)', () => {
	test( 'follows an alert from the overview to a CSV export', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/admin.php', 'page=multisite-radar' );

		await page.getByRole( 'link', { name: 'Site without users' } ).click();
		await expect( page ).toHaveURL( /page=multisite-radar-sites.*rule=no_users/ );
		await expect( page.getByText( 'Site vide', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'Blog RH', { exact: true } ) ).toHaveCount( 0 );

		await page.getByText( 'Site vide', { exact: true } ).click();
		const panel = page.getByRole( 'dialog', { name: 'Site vide' } );
		await expect( panel ).toBeVisible();
		await expect( page ).toHaveURL( /site=\d+/ );
		await panel.getByRole( 'tab', { name: 'Alerts' } ).click();
		await expect( panel.getByText( 'No user is attached to this site.' ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( panel ).toBeHidden();

		const downloading = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Export' } ).click();
		await page.getByRole( 'menuitem', { name: 'Export as CSV' } ).click();
		const download = await downloading;
		expect( download.suggestedFilename() ).toMatch( /^multisite-radar-sites-\d{8}-\d{6}\.csv$/ );
		const csv = ( await readFile( await download.path() ) ).toString( 'utf8' );
		expect( csv.charCodeAt( 0 ) ).toBe( 0xfeff );
		expect( csv ).toContain( 'Site vide' );
		expect( csv ).not.toContain( 'Blog RH' );
	} );

	test( 'the content types of a site are read in the context of that site (spec 1.4, criterion 1)', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/admin.php', 'page=multisite-radar-sites&s=Blog%20RH' );

		await page.getByText( 'Blog RH', { exact: true } ).click();
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		await panel.getByRole( 'tab', { name: 'Content' } ).click();

		await expect( panel.getByText( 'Demo events' ) ).toBeVisible();
		await expect( panel.getByText( 'Plugin: msradar-demo-cpt' ) ).toBeVisible();
	} );
} );
```

`tests/e2e/specs/preload.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [
	[ 'multisite-radar', 'To review' ],
	[ 'multisite-radar-sites', 'Blog RH' ],
	[ 'multisite-radar-alerts', 'Site vide' ],
	[ 'multisite-radar-settings', 'Save settings' ],
];

test( 'the first view of each page is built from preloaded data (spec 1.4, criterion 2)', async ( { admin, page } ) => {
	for ( const [ slug, text ] of PAGES ) {
		const requests = [];
		const listener = ( request ) => {
			const url = decodeURIComponent( request.url() );
			if ( url.includes( '/multisite-radar/v1/' ) ) {
				requests.push( url );
			}
		};
		page.on( 'request', listener );
		await admin.visitAdminPage( 'network/admin.php', `page=${ slug }` );
		await expect( page.getByText( text, { exact: true } ).first() ).toBeVisible();
		await page.waitForLoadState( 'networkidle' );
		page.off( 'request', listener );

		expect( requests, slug ).toEqual( [] );
	}
} );
```

`tests/e2e/specs/a11y.spec.js` :

```js
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [
	'page=multisite-radar',
	'page=multisite-radar-sites',
	'page=multisite-radar-alerts',
	'page=multisite-radar-settings',
];

async function seriousViolations( page ) {
	const results = await new AxeBuilder( { page } )
		.include( '.msradar-wrap' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	return results.violations
		.filter( ( violation ) => [ 'serious', 'critical' ].includes( violation.impact ) )
		.map( ( violation ) => `${ violation.id }: ${ violation.nodes.map( ( node ) => node.target.join( ' ' ) ).join( ', ' ) }` );
}

test.describe( 'Accessibility, WCAG 2.2 AA (spec 1.4, criterion 5)', () => {
	for ( const query of PAGES ) {
		test( `no serious or critical violation on ${ query }`, async ( { admin, page } ) => {
			await admin.visitAdminPage( 'network/admin.php', query );
			await expect( page.locator( '#msradar-app' ) ).not.toBeEmpty();

			expect( await seriousViolations( page ) ).toEqual( [] );
		} );
	}

	test( 'no serious or critical violation with the site panel open', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/admin.php', 'page=multisite-radar-sites' );
		await page.getByText( 'Blog RH', { exact: true } ).click();
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );
} );
```

`tests/e2e/specs/sites-menu.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Network sites menu module', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/settings',
			data: { sites_menu: { enabled: true } },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/settings',
			data: { sites_menu: { enabled: false } },
		} );
	} );

	test( 'the block and the shortcode list the public sites', async ( { requestUtils, page } ) => {
		const post = await requestUtils.createPost( {
			title: 'Our sites',
			status: 'publish',
			content:
				'<!-- wp:multisite-radar/sites-list {"layout":"inline"} /-->\n<!-- wp:shortcode -->[msradar_sites class="shortcode-list"]<!-- /wp:shortcode -->',
		} );

		await page.goto( post.link );

		const block = page.locator( '.wp-block-multisite-radar-sites-list' );
		await expect( block.getByRole( 'link', { name: 'Blog RH' } ) ).toBeVisible();
		await expect( block ).toHaveClass( /msradar-sites--inline/ );
		await expect( page.locator( 'ul.shortcode-list' ).getByRole( 'link', { name: 'Site vide' } ) ).toBeVisible();
	} );
} );
```

- [ ] **Step 3: Lancer les E2E en local**

Run :

```bash
npm run build
npx playwright install --with-deps chromium
npm run wp-env -- start
npm run e2e:setup
npm run test:e2e
```

Expected: tous les tests passent.

En cas de violation d'accessibilité « serious » ou « critical » :
- dans notre code : la corriger ;
- dans DataViews ou `@wordpress/components` : relever la règle axe et le nœud dans le rapport de tâche.

Ne jamais exclure une règle axe globalement.

Run: `npm run wp-env -- stop`

- [ ] **Step 4: CI**

Dans `.github/workflows/ci.yml`, ajouter le job :

```yaml
  e2e-ui:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: .nvmrc
          cache: npm
      - run: npm ci
      - run: npm run build
      - run: npx playwright install --with-deps chromium
      - run: npm run wp-env -- start
      - run: npm run e2e:setup
      - run: npm run test:e2e
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: e2e-artifacts
          path: artifacts
          retention-days: 7
```

Run: `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color`
Expected: aucune sortie.

Run: `npm run lint:js`
Expected: aucune erreur.

- [ ] **Step 5: Commit**

```bash
git add .wp-env.json playwright.config.js tests/e2e package.json package-lock.json eslint.config.cjs .github/workflows/ci.yml
git commit -m "test: end-to-end journeys, preloading and WCAG checks on a wp-env multisite"
```

---

### Task 21: Version 2.0.0-beta.1, documentation et recette du jalon

**Files:**
- Modify: `multisite-radar.php`, `readme.txt`, `package.json`, `tests/phpstan-bootstrap.php` (par le script), `CHANGELOG.md`, `README.md`, `docs/superpowers/plans/2026-10-01-multisite-radar-m1-followups.md`

**Interfaces:**
- Consumes : `npm run version:set` (tâche 11) et tout le jalon.
- Produces : la version 2.0.0-beta.1, cohérente partout ; un document des suites à jour.

- [ ] **Step 1: Passer en 2.0.0-beta.1**

Run: `npm run version:set 2.0.0-beta.1 && npm run version:check`
Expected: `Version set to 2.0.0-beta.1.` puis `Version 2.0.0-beta.1 is consistent.`

- [ ] **Step 2: readme.txt**

Remplacer le deuxième paragraphe de `== Description ==` par :

```
Data is collected in the background with lightweight SQL queries, so the network admin screens stay fast on networks with thousands of sites. The Overview, Sites, Alerts and Settings screens open instantly, with CSV and JSON exports, and an optional "network sites" block, shortcode and menu items replace the 1.x menu. Nothing is sent to external services.
```

Ajouter en tête de `== Changelog ==` :

```
= 2.0.0-beta.1 =
* First beta: network admin screens (Overview, Sites with a side panel, Alerts, Settings), per-user display preferences, CSV and JSON exports.
* Optional network sites menu: block, shortcode and navigation menu items, with the 1.x aliases kept for migrated sites.
* REST: site users, alert list and preferences routes; failed reads now return an error instead of an empty list.
```

- [ ] **Step 3: CHANGELOG.md**

Insérer, juste après le titre `# Changelog — Multisite Radar`, la section suivante (date du jour, `date +%F`) :

```markdown
## [2.0.0-beta.1] - AAAA-MM-JJ

### Jalon M2 — Interface
- Interface d'administration réseau en React : Vue d'ensemble (tuiles, « À traiter », analyse pilotée par l'interface), Sites (DataViews, filtres, tri, recherche, état dans l'URL, fiche latérale avec lien profond), Alertes (site × règle, regroupées par règle), Réglages (DataForm).
- Première vue sans indicateur de chargement : données préchargées par PHP et cache `msradar/core` hydraté au démarrage ; un point d'entrée par vue.
- Préférences d'affichage par utilisateur (`GET/POST /preferences`), exports CSV (UTF-8 avec BOM, formules neutralisées) et JSON en flux.
- REST : `GET /alerts`, `GET /sites/{id}/users` (sans e-mail), sélection `include`, erreurs 500 sur lecture en échec, filtre `inactive_since` aligné sur la règle « inactive ».
- Module « menu des sites » : cache par réseau, shortcode `[msradar_sites]`, metabox des menus, bloc `multisite-radar/sites-list`, alias 1.x pour les installations migrées.
- Socle : un seul budget de travail par requête cron, curseur de recalcul lié aux réglages d'alertes, schéma v2 (colonne `siteurl`, listes sans la colonne `data`), action `msradar_error` pour les erreurs de stockage attrapées dans les hooks.
- Outillage : `@wordpress/scripts` 36, Vitest, Playwright sur wp-env multisite avec audit axe, synchronisation des versions (`npm run version:check`).
```

Remplacer `AAAA-MM-JJ` par la date du jour.

- [ ] **Step 4: README.md**

Ajouter à la fin de la section `## Développement` :

````markdown
Interface (Node 24, voir `.nvmrc`) :

```bash
npm install
npm run build         # build/ (livré, ignoré par git)
npm run start         # build en continu
npm run test:unit     # Vitest
npm run lint:js && npm run lint:css
npm run version:check # MSRADAR_VERSION = en-tête = readme.txt = package.json
```

Tests de bout en bout (Docker) :

```bash
npm run wp-env -- start && npm run e2e:setup && npm run test:e2e
npm run wp-env -- stop
```
````

- [ ] **Step 5: Mettre à jour le document des suites de M1**

Dans `docs/superpowers/plans/2026-10-01-multisite-radar-m1-followups.md`, ajouter en tête, sous le titre, cette section :

```markdown
> **Mise à jour M2 :** les points suivants sont traités par le plan `2026-10-01-multisite-radar-m2-interface.md` :
> - les résidus (a), (b) et la désactivation ;
> - les points M1, M2, M3, M4, M5, M6, M8, M9, M10, M11, M12 et M13 ;
> - les points T10 (`msradar_upgraded`), T11 (page énorme, erreurs SQL avalées, `{}`) et T12 (`scope=ids`, `rest_parse_date`).
>
> Les autres restent ouverts, notamment :
> - M7 (registre autoloadé), prévu pour M4 avec la règle `heavy_autoload` ;
> - les points de WP-CLI, prévus pour M5.
```

- [ ] **Step 6: Recette complète**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS, aucune erreur.

Run: `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'`
Expected: aucune sortie, code 0.

Run: `npm run version:check && npm run lint:js && npm run lint:css && npm run test:unit && npm run build`
Expected: tout passe.

Run: `npm run plugin-zip && unzip -l multisite-radar.zip | grep -E 'build/admin/sites.js|build/blocks/sites-list/block.json|includes/Admin/Assets.php' && rm multisite-radar.zip`
Expected : les trois fichiers sont dans l'archive. Celle-ci ne contient ni `src/`, ni `node_modules/`, ni `tests/`.

Run: `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e; npm run wp-env -- stop`
Expected: PASS.

Vérifier dans le WordPress local (`http://wp-network-plugin-utilities.test:8080/wp-admin/network/`) :
- les quatre pages s'affichent ;
- le parcours Vue d'ensemble → Sites filtrés → fiche → export fonctionne ;
- le site « Blog RH » (`/rh/`) montre `demo_event` avec son origine.

- [ ] **Step 7: Commit**

```bash
git add multisite-radar.php readme.txt package.json package-lock.json tests/phpstan-bootstrap.php CHANGELOG.md README.md docs/superpowers/plans/2026-10-01-multisite-radar-m1-followups.md
git commit -m "chore: release 2.0.0-beta.1"
```

Ne pas pousser : la fusion, la publication et le tag `v2.0.0-beta.1` sont décidés par le responsable du dépôt.

---

## Annexe — Couverture de la spec pour M2

| Exigence | Tâches |
|---|---|
| §6.1 Navigation : menu réseau et sous-pages, état dans l'URL, préférences par utilisateur | 12, 13, 14, 17, 7 |
| §6.2 Vue d'ensemble : tuiles, « À traiter », progression, bouton « Analyser » | 16 (tuiles M3 reportées, E10) |
| §6.2 Sites : DataViews tableau et grille, colonnes, actions de ligne et groupées | 14 |
| §6.2 Fiche site : panneau, lien profond, ←/→, onglets Résumé, Contenus, Utilisateurs, Extensions, Alertes | 15 |
| §6.2 Alertes : site × règle, regroupées par règle, filtres gravité et règle | 5, 17 |
| §6.2 Réglages : DataForm | 18 (sections, E11) |
| §6.3 Pile : `@wordpress/scripts`, composants, DataViews épinglé et adaptateur, store `msradar/core` | 11, 13 |
| §6.4 Rapide : préchargement, découpage par vue, debounce | 12, 13, 11 (E2, E3, E4) |
| §6.4 États : premier lancement, erreur REST avec « Retry », badge « non vérifié », snackbars | 16, 13, 14, 15 |
| §6.4 Accessibilité : focus du panneau, `speak()`, clavier, RTL, axe | 15, 13, 11, 20 |
| §6.4 i18n : `wp_set_script_translations` | 12, 19 |
| §5.1 Routes M2 : `/sites/{id}/users`, `/alerts`, `/preferences` | 6, 5, 7 |
| §5.2 Exports : admin-post, nonce, filtres, `fields[]`, tranches de 500, BOM, `meta` JSON, nom horodaté | 8, 14 |
| §7.4 Module menu : cache sans limite, shortcode, metabox, bloc, alias 1.x | 9, 10, 19 |
| §9 Sécurité et confidentialité : capacités, nonces, échappement, politique de confidentialité | 8, 12, 9, 10 |
| §11 Outillage JS, Jest (remplacé par Vitest, E1), E2E Playwright + axe, CI | 11, 20 |
| §11.4 Désinstallation : `msradar_view_prefs` (déjà en M1) et transient du menu | 9 |
| §1.4 n° 2 : première vue sans indicateur de chargement | 12, 13, 14, 20 |
| §1.4 n° 5 : WCAG 2.2 AA, zéro violation « serious » ou « critical » | 20 |
