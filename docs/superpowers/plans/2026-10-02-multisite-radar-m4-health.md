# Multisite Radar — Jalon M4 (Santé avancée) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la 2.0.0-beta.4 de Multisite Radar. Elle ajoute la santé avancée des sites :
- les mesures `disk_bytes`, `db_bytes`, `autoload_bytes` et les tâches planifiées en retard (`data.cron`), relevées par le collecteur ;
- les huit règles d'alertes du §4.2 marquées M4 : `no_admin`, `missing_theme`, `updates_pending`, `insecure_url`, `disk_quota`, `heavy_autoload`, `search_hidden`, `cron_overdue` ;
- la route `GET /alert-rules` ;
- la section « Règles d'alertes » des réglages, générée depuis cette route, et le réglage « mesurer le disque » ;
- l'affichage des mesures dans la liste des sites et dans la fiche ;
- les points reportés qui touchent ces surfaces.

**Architecture :**
- **Collecte :** `SiteCollector` lit trois nouvelles valeurs en SQL (poids de l'autoload, taille des tables, option `cron`) et mesure le dossier d'envoi du site avec un nouveau service, `Collector\DiskMeter`, borné dans le temps. Aucune colonne nouvelle : le schéma de M1 les prévoyait déjà. La version de schéma passe à 3 pour que la mise à niveau relance une analyse complète et remplisse les mesures.
- **Règles :** huit classes dans `includes/Alerts/Rules/`, sur l'interface `RuleInterface` inchangée. Les quatre règles qui dépendent de l'état du réseau (thèmes installés, mises à jour disponibles, quotas, https) le lisent dans `Alerts\NetworkState`, injecté à leur construction et lu une fois par passe. C'est le « contexte de l'évaluateur » du §4.1.
- **Fraîcheur des alertes :** `Scan\NetworkStateWatcher` relance le recalcul des alertes quand cet état change (une extension mise à jour, un thème supprimé, un quota activé), au lieu d'attendre le recalcul quotidien.
- **REST :** `GET /alert-rules` expose les définitions des règles (libellé, description, gravité et paramètres par défaut, schéma des paramètres). Les réglages effectifs restent dans `GET /settings`.
- **Interface :** la page Réglages construit un panneau par règle (activée, gravité, paramètres) avec DataForm, à partir de `/alert-rules`, préchargée. L'enregistrement n'envoie plus que les valeurs modifiées.

**Tech stack :** inchangée depuis M3.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6.
- Node 24, `@wordpress/scripts` 36.0.0 (webpack 5, ESLint, Stylelint, Vitest 5), React 18.3, `@wordpress/dataviews` 19.1.0 (version épinglée).
- Playwright + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M4, les sections utiles sont :
- §3.1 (colonnes `disk_bytes`, `disk_is_estimate`, `db_bytes`, `autoload_bytes`) et §3.2 (`data.cron`) ;
- §3.3, n° 4, 5, 7 et 8 (options, autoload, taille des tables, espace disque) ;
- §3.5 (recalcul des alertes) et §3.6 (performance) ;
- §4.1 et §4.2 ;
- §5.1 (`GET /alert-rules`) ;
- §6.2 (Réglages, fiche site) et §6.4 ;
- §7.2 ; §8 ; §11.2 ; §14 (`information_schema`, mesure disque).

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`. Les points repris ici sont listés plus bas.

## Global Constraints

- **PHP ≥ 7.4.** Interdits : `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains`, arguments nommés, opérateur nullsafe, ternaire court `?:`. Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :**
  - text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, transients, groupes de cache, handles) ;
  - handles de scripts `msradar-<vue>`, chunk partagé `msradar-dataviews` ;
  - store `msradar/core` ;
  - pages d'admin, dans cet ordre : `multisite-radar`, `multisite-radar-sites`, `multisite-radar-plugins`, `multisite-radar-themes`, `multisite-radar-users`, `multisite-radar-alerts`, `multisite-radar-settings`.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ;
  - en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder ;
  - jamais de `%` littéral (`%%`) dans une chaîne traduisible.
- **SQL :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants, y compris les tables globales ;
  - listes `IN` via `implode( ',', array_fill( 0, count( $x ), '%s' ) )` (ou `%d`) ;
  - aucune fonction JSON côté SQL ;
  - les requêtes directes vivent dans `includes/Storage/`, `includes/Collector/`, `includes/Scan/` ou `includes/Install/` (PHPCS les y autorise) ;
  - `// phpcs:ignore <code exact> -- <raison>` sur une ligne signalée qui suit ces motifs ; ne jamais désactiver une règle dans `phpcs.xml.dist`.
- **Règles d'alertes :**
  - une règle n'exécute aucune requête SQL ; ce qui ne vient pas du `SiteRecord` vient de `Alerts\NetworkState` ;
  - `RuleInterface` ne change pas : les règles tierces (filtre `msradar_alert_rules`) continuent de fonctionner ;
  - toute règle renvoie `null` pour un site jamais analysé (`scanned_at` null) et pour une mesure absente (`null`) ;
  - chaque paramètre du schéma porte un `title` (libellé court) et une `description`.
- **Motifs PHP :** tout `preg_match` qui valide une entrée se termine par `\z`, jamais par `$`.
- **Style PHP :**
  - WPCS ;
  - `defined( 'ABSPATH' ) || exit;` en tête de chaque fichier sous `includes/` ;
  - tableaux courts autorisés.
- **Erreurs :**
  - les dépôts (`Storage/*`) lèvent `\RuntimeException` quand une lecture échoue ;
  - le collecteur lève une exception quand une lecture indispensable échoue, mais **jamais pour la taille des tables** : elle vaut alors `null` ;
  - les contrôleurs REST passent par `Controller::guard()`, qui renvoie une erreur 500 ;
  - les gestionnaires de hooks déclenchent `do_action( 'msradar_error', <contexte>, $error )` ;
  - aucune exception ne doit remonter dans un hook du cœur.
- **Dates :**
  - stockées en GMT (`Y-m-d H:i:s`) ;
  - exposées en REST dans les champs `*_gmt` au format `Y-m-d\TH:i:s`, en UTC et sans décalage ;
  - côté JS, toujours lues par `parseGmt()` (`src/utils/format.js`).
- **Données personnelles :** aucune adresse e-mail dans une réponse REST, un export ou l'interface.
- **Aucun appel HTTP externe :** les mises à jour disponibles sont lues dans les transients réseau `update_plugins` et `update_themes`, tels que WordPress les a laissés.
- **Tailles :** octets partout ; `KB_IN_BYTES` (1024) et `MB_IN_BYTES` (1 048 576) de WordPress pour les seuils en Ko et Mo.
- **JS, fichiers :**
  - sources dans `src/` en modules ES ;
  - extension `.jsx` pour tout fichier qui contient du JSX, `.js` sinon ;
  - imports sans extension.
- **JS, styles :** les feuilles de style ne sont importées que par les points d'entrée (`src/admin/<vue>.js`), jamais par un composant.
- **DataViews :**
  - `@wordpress/dataviews` est épinglé à `19.1.0` ;
  - ses composants sont importés uniquement dans `src/components/data-views/index.js`, depuis `@wordpress/dataviews/wp` ;
  - le reste du code importe cet adaptateur ;
  - dans DataViews 19.1, un champ peut définir `getValue( { item } )` et `setValue( { item, value } )` ; `setValue` renvoie un objet partiel que DataForm passe tel quel à `onChange`.
- **Composants :**
  - uniquement des exports publics de `@wordpress/components` présents dans WordPress 6.9 : `Button`, `Card`, `CardBody`, `CardHeader`, `CheckboxControl`, `DropdownMenu`, `ExternalLink`, `Flex`, `FlexItem`, `FlexBlock`, `FormTokenField`, `Notice`, `PanelBody`, `SelectControl`, `SnackbarList`, `Spinner`, `TabPanel`, `TextControl`, `ToggleControl` ;
  - interdits : `__experimental*`, `privateApis`, `Tabs`, `Badge`, `ProgressBar` ;
  - icônes de `@wordpress/icons`.
- **Interface :**
  - jamais de `dangerouslySetInnerHTML` ;
  - pas d'emojis ;
  - couleurs d'accent via `var(--wp-admin-theme-color)` ;
  - les animations respectent `prefers-reduced-motion`.
- **Sécurité :** toute donnée injectée dans un script en ligne passe par `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP )`.
- **Versions :**
  - `MSRADAR_VERSION` est la seule source de vérité ;
  - `make version VERSION=x` met tout à jour ;
  - `npm run version:check` vérifie l'en-tête, `readme.txt`, `package.json` et `tests/phpstan-bootstrap.php`.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `ci:`, `docs:`, `chore:`, `build:`) ;
  - **jamais de ligne `Co-authored-by`**, que le hook du dépôt refuse.
- **Tests PHP :**
  - ne jamais lever d'exception dans `set_up()` ou `tear_down()` après `parent::set_up()` : la transaction ne serait pas annulée et des lignes resteraient dans la base de test ;
  - les tables d'un site créé par `self::factory()->blog->create()` sont des tables **temporaires** (filtre de la suite de tests de WordPress) : `SHOW TABLES` et `information_schema` ne les voient pas. La taille des tables se teste sur le site principal.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur `multisite-radar` : le dossier du plugin est un lien symbolique vers le dépôt ;
  - ne jamais afficher le mot de passe de la base : `bin/test.sh` le lit à la volée ;
  - le port 8888 est occupé dans la VM : lancer wp-env et les tests E2E avec `WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890`.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M4 | Raison |
|---|---|---|---|
| E1 | `cron_overdue` : « tâches cron en retard », paramètre `hours` | Le retard est mesuré **à la date de l'analyse** (`scanned_at` − échéance de la plus ancienne tâche échue), pas à la date du recalcul | Le recalcul quotidien part des données stockées. Mesuré à la date du jour, le retard d'une tâche échue depuis une minute au moment de l'analyse dépasserait 24 heures le lendemain, alors que WP-Cron l'a peut-être exécutée depuis. |
| E2 | `cron_overdue`, `search_hidden` | Ces deux règles ignorent les sites **archivés, indésirables ou supprimés** (`SiteRecord::is_served()`) | WordPress ne sert pas ces sites : leurs tâches ne peuvent pas tourner et ils ne sont pas indexés. L'alerte ne serait que du bruit. |
| E3 | `no_admin` : « site sans administrateur » | Ne se déclenche que si le site a **au moins un compte** | Un site sans aucun compte est déjà signalé par `no_users`, en erreur. |
| E4 | §4.1 : « l'état des transients de mise à jour fourni par le contexte de l'évaluateur » | Le contexte est un service, `Alerts\NetworkState`, **injecté dans le constructeur** des règles qui en ont besoin ; `RuleInterface::evaluate()` garde sa signature | Changer la signature casserait les règles tierces déclarées depuis la 2.0.0-beta.1. |
| E5 | §3.3 n° 8 : « `recurse_dirsize` avec limite » | Parcours propre au plugin, `Collector\DiskMeter` : sans récursion, sans suivre les liens symboliques, budget de **2 secondes par site** (filtre `msradar_disk_budget`) | `recurse_dirsize()` abandonne (`null`) au lieu de rendre une valeur partielle, mesure le temps depuis le début de la requête et écrit le transient `dirsize_cache` du site qui analyse. |
| E6 | §3.3 n° 7 : pour le site principal, exclure `{base}\d+_` | Le site principal exclut aussi les **tables globales du réseau** (`$wpdb->tables( 'global' )` : comptes, sites, réseau…) et les tables de Multisite Radar | Elles appartiennent au réseau, pas au site principal : les compter le ferait paraître beaucoup plus lourd que les autres. |
| E7 | §3.5 : recalcul quotidien des alertes et après un changement de réglages | Recalcul aussi quand l'état lu par les règles change : liste des mises à jour disponibles, thèmes installés, quotas d'envoi, adresse du site principal | Après la mise à jour d'une extension depuis l'administration réseau, l'alerte « mises à jour en attente » disparaîtrait sinon le lendemain seulement. |
| E8 | Schéma inchangé (colonnes prévues en M1) | `Schema::VERSION` passe à **3** sans modifier les tables ; la mise à niveau déclenche l'analyse complète existante (`msradar_upgraded`). Les tables de M6 prendront la version 4 | Sans nouvelle analyse, les mesures n'apparaîtraient qu'au bout de `full_rescan_days` (7 jours par défaut). |
| E9 | §8 : `scan.measure_disk` | Changer ce réglage **marque tous les sites** à analyser, comme un changement des types d'activité | Les mesures doivent apparaître (ou disparaître) sans attendre l'analyse complète périodique. |
| E10 | `GET /alert-rules` | Accessible avec `msradar_view` ; ne renvoie que les **définitions** (les réglages effectifs restent dans `GET /settings`, réservé à `msradar_manage`) | Les définitions ne sont pas sensibles et pourront servir à la page Alertes. |
| E11 | `disk_quota` : quota du site | Quota propre au site (option `blog_upload_space`, lue à l'analyse et stockée dans `data.options.upload_space_mb`), sinon celui du réseau, sinon 100 Mo, comme `get_space_allowed()`. Le filtre `get_space_allowed` n'est pas appliqué | Le filtre demanderait le contexte de chaque site, donc des requêtes pendant l'évaluation. |
| E12 | `insecure_url` : « le réseau est en https » | Le réseau est en https si l'adresse (`home`) de son **site principal** l'est. La règle vérifie l'adresse du site et son adresse WordPress (`siteurl`) | Le domaine du réseau (`wp_site`) n'a pas de schéma. |
| E13 | `updates_pending` : « plugins locaux ou thème » | Plugins activés **sur le site** (`data.plugins_local`), thème actif et thème parent. Les plugins activés sur le réseau ne comptent pas | Une mise à jour d'un plugin réseau signalerait chaque site du réseau ; la page Plugins la montre déjà. |
| E14 | Réglages (§8) | L'enregistrement n'envoie que les valeurs **modifiées** ; pour une règle modifiée, sa configuration complète (`enabled`, `severity`, `params`) | Point reporté de M2 : deux administrateurs qui changent des réglages différents ne s'écrasent plus, et revenir à la valeur de départ n'est plus une modification. |

## Points reportés traités dans ce plan

| Point (document des suites de M2) | Tâche |
|---|---|
| Réglages : l'état « modifié » reste vrai après un retour à la valeur initiale ; l'enregistrement envoie les trois champs d'analyse | 9 |
| `Plugin.php` : le `use` de `ThemesController` n'est pas dans l'ordre alphabétique | 7 |
| Commentaire de l'audit d'accessibilité qui dit « Page Réglages » alors que l'exclusion vaut pour toutes les pages | 11 |

Les autres points restent pour plus tard. La tâche 12 met le document à jour.

## Review Focus

Les cinq situations que la spec implique sans que ses exemples les montrent, et qui gêneraient le plus un utilisateur. Chacune a son test dans la tâche indiquée.

1. **Mise à jour depuis la 2.0.0-beta.3 :**
   - le comportement attendu : aucune fausse alerte sur les lignes qui n'ont pas encore de mesures ; une analyse complète démarre d'elle-même et remplit les mesures ;
   - tests : tâches 4 et 5 (mesure `null` → pas d'alerte), tâche 3 (schéma 2 → analyse complète).
2. **Dossier d'envoi énorme, lent ou piégé par un lien symbolique qui boucle :**
   - le comportement attendu : la mesure s'arrête dans son budget, la valeur est affichée comme un minimum (« au moins 1,2 Go ») ; un lien n'est jamais suivi ;
   - tests : tâche 1 (budget épuisé, lien en boucle), tâche 8 (affichage « at least »).
3. **Hébergeur qui interdit `information_schema`, ou `SHOW TABLE STATUS` :**
   - le comportement attendu : repli sur la seconde source, puis `null` ; l'analyse du site aboutit quand même, sans erreur d'analyse ;
   - test : tâche 2.
4. **Extension mise à jour depuis l'administration réseau :**
   - le comportement attendu : l'alerte « mises à jour en attente » disparaît au prochain passage du cron, pas le lendemain ; une simple nouvelle vérification des mises à jour, sans nouvelle version, ne relance rien ;
   - test : tâche 6.
5. **Site archivé, indésirable ou supprimé :**
   - le comportement attendu : ni « tâches planifiées en retard » ni « masqué aux moteurs de recherche » ;
   - test : tâche 4.

Autres pièges couverts par des tests :
- le site principal ne compte ni les tables des autres sites, ni les tables du réseau, mais garde une table d'extension nommée `wp_2fa_codes` (tâche 2) ;
- le site principal ne compte pas le dossier `sites/` des autres sites (tâche 1) ;
- une règle tierce dont un paramètre n'a pas de type pris en charge par le formulaire : la règle reste réglable (activée, gravité) et ses paramètres enregistrés sont renvoyés tels quels (tâche 10) ;
- revenir à la valeur de départ d'un réglage ou de la gravité d'une règle : rien à enregistrer (tâches 9 et 10) ;
- « gravité par défaut » : `null` côté serveur, choix « Default (…) » dans le formulaire, dans les deux sens (tâche 10).

## Structure des fichiers

**PHP, créés :**
- `includes/Collector/DiskMeter.php` — taille d'un dossier dans un budget de temps.
- `includes/Alerts/NetworkState.php` — état du réseau lu par les règles (thèmes installés, mises à jour, quotas, https), avec son empreinte.
- `includes/Alerts/Rules/NoAdminRule.php`, `MissingThemeRule.php`, `UpdatesPendingRule.php`, `InsecureUrlRule.php`, `DiskQuotaRule.php`, `HeavyAutoloadRule.php`, `SearchHiddenRule.php`, `CronOverdueRule.php`.
- `includes/Scan/NetworkStateWatcher.php` — relance le recalcul des alertes quand l'état du réseau change.
- `includes/Rest/AlertRulesController.php` — `GET /alert-rules`.

**PHP, modifiés :**
- `includes/Collector/SiteCollector.php` ;
- `includes/Storage/SiteRecord.php` (`is_served()`) ;
- `includes/Alerts/RuleRegistry.php`, `includes/Alerts/Rules/InactiveRule.php`, `includes/Alerts/Rules/HighMediaRule.php` ;
- `includes/Install/Schema.php`, `includes/Scan/Queue.php` ;
- `includes/Plugin.php`, `includes/Admin/Preload.php` ;
- `includes/Query/SitesQuery.php`, `includes/Rest/SitesController.php`, `includes/Export/SitesColumns.php` ;
- `uninstall.php` ;
- `CHANGELOG.md`, `readme.txt`, `README.md`, `multisite-radar.php`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php`.

**JS :**
- **Modifiés :** `src/utils/format.js`, `src/views/sites/fields.jsx`, `src/views/sites/export.js`, `src/views/site-panel/summary-tab.jsx`, `src/views/settings/fields.js`, `src/views/settings/index.jsx`.
- **Créés :** `src/views/settings/rule-fields.js`, `src/views/settings/rules-card.jsx`.
- **Tests :** `tests/php/**` (voir chaque tâche), `src/**/test/*.test.js(x)`, `tests/e2e/setup.sh`, `tests/e2e/specs/health.spec.js` (créé), `tests/e2e/specs/a11y.spec.js`.

## Commandes

| Rôle | Commande |
|---|---|
| Tests PHP (tout, ou un filtre) | `bin/test.sh` ; `bin/test.sh --filter DiskMeterTest` |
| Normes PHP / analyse statique | `composer lint` ; `composer analyse` |
| Syntaxe PHP 7.4 | `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'` |
| Tests JS | `npm run test:unit` (ou `npx vitest run src/views/settings`) |
| Lint JS / CSS | `npm run lint:js` ; `npm run lint:css` |
| Build | `npm run build` |
| Traductions | `make i18n` (après un build) |
| Versions synchronisées | `npm run version:check` |
| E2E | `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` puis `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e` |

Chaque tâche se termine par les tests de son périmètre, puis `composer lint` et `composer analyse` (pour le PHP), ou `npm run lint:js` et `npm run test:unit` (pour le JS), avant le commit. Le travail se fait sur une branche `m4` créée depuis `main`.

**Base de test :** la base `wordpress_test` peut contenir une ligne parasite (site 101) laissée par l'exécution de M3. Tant qu'elle y est, 11 tests échouent d'une unité (`AlertsQueryTest::test_summary_counts_sites_by_severity_and_rule`, 7 tests de `QueueTest`, 3 de `SitesRepositoryTest`). Ces 11 échecs sont connus : un exécutant ne les corrige pas et ne les compte pas comme une régression, mais signale tout autre échec.

---

## Partie A — Mesures

### Task 1: Espace disque du site — `DiskMeter` et mesure du dossier d'envoi

**Files:**
- Create: `includes/Collector/DiskMeter.php`
- Modify: `includes/Collector/SiteCollector.php`
- Test: `tests/php/Collector/DiskMeterTest.php` (créé), `tests/php/Collector/SiteCollectorTest.php`

**Interfaces:**
- Consumes : `Settings::get( 'scan.measure_disk', true )` (réglage existant, `true` par défaut).
- Produces :
  - `DiskMeter::measure( string $directory, string[] $exclude, float $budget ): ?array{bytes: int, complete: bool}` ;
  - `SiteCollector::DISK_BUDGET = 2.0` (secondes), filtre `msradar_disk_budget` (`float $budget, int $site_id`) ;
  - `SiteRecord::$disk_bytes` (`?int`) et `SiteRecord::$disk_is_estimate` (`bool`) remplis par `collect()`. `null` / `false` si la mesure est désactivée ou si le dossier ne peut pas être lu.

- [ ] **Step 1: Write the failing tests of `DiskMeter`**

Créer `tests/php/Collector/DiskMeterTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\DiskMeter;
use MultisiteRadar\Tests\TestCase;

final class DiskMeterTest extends TestCase {

	private string $root = '';

	/**
	 * Arborescence factice : 100 + 2000 octets à la racine et dans 2026/09, 500 octets dans sites/2.
	 * Rien ici ne lève d'exception (voir Global Constraints, tests PHP).
	 */
	public function set_up(): void {
		parent::set_up();
		$this->root = untrailingslashit( get_temp_dir() ) . '/msradar-disk-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->root . '/2026/09' );
		wp_mkdir_p( $this->root . '/sites/2' );
		file_put_contents( $this->root . '/a.txt', str_repeat( 'a', 100 ) );
		file_put_contents( $this->root . '/2026/09/b.jpg', str_repeat( 'b', 2000 ) );
		file_put_contents( $this->root . '/sites/2/c.png', str_repeat( 'c', 500 ) );
	}

	public function tear_down(): void {
		self::remove( $this->root );
		parent::tear_down();
	}

	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	public function test_sums_the_files_of_every_sub_folder(): void {
		$this->assertSame(
			[
				'bytes'    => 2600,
				'complete' => true,
			],
			DiskMeter::measure( $this->root, [], 5.0 )
		);
	}

	public function test_excluded_folders_are_not_counted_whatever_their_trailing_slash(): void {
		$this->assertSame(
			[
				'bytes'    => 2100,
				'complete' => true,
			],
			DiskMeter::measure( $this->root . '/', [ $this->root . '/sites/' ], 5.0 )
		);
	}

	public function test_a_missing_folder_weighs_nothing(): void {
		$this->assertSame(
			[
				'bytes'    => 0,
				'complete' => true,
			],
			DiskMeter::measure( $this->root . '/nothing-here', [], 5.0 )
		);
	}

	public function test_a_file_is_not_a_folder_that_can_be_measured(): void {
		$this->assertNull( DiskMeter::measure( $this->root . '/a.txt', [], 5.0 ) );
	}

	public function test_an_exhausted_budget_returns_a_partial_value(): void {
		$result = DiskMeter::measure( $this->root, [], 0.0 );

		$this->assertFalse( $result['complete'] );
		$this->assertLessThan( 2600, $result['bytes'] );
	}

	public function test_symbolic_links_are_never_followed(): void {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root, $this->root . '/2026/loop' ) ) {
			$this->markTestSkipped( 'Symbolic links are not available here.' );
		}
		@symlink( $this->root . '/a.txt', $this->root . '/copy-of-a.txt' );

		$this->assertSame(
			[
				'bytes'    => 2600,
				'complete' => true,
			],
			DiskMeter::measure( $this->root, [], 5.0 ),
			'A link that loops back to the root, or to a file already counted, adds nothing.'
		);
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter DiskMeterTest`
Expected: FAIL, `Class "MultisiteRadar\Collector\DiskMeter" not found`.

- [ ] **Step 3: Write `DiskMeter`**

Créer `includes/Collector/DiskMeter.php` :

```php
<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Taille d'un dossier et de son contenu, parcouru sans récursion et dans un budget de temps (écart E5 du plan M4).
 *
 * recurse_dirsize() du cœur ne convient pas ici : il abandonne (null) au lieu de rendre une valeur partielle, compte le
 * temps depuis le début de la requête et écrit le transient dirsize_cache du site qui analyse.
 */
final class DiskMeter {

	/**
	 * Le chronomètre est lu avant chaque dossier, puis toutes les CHECK_EVERY entrées d'un même dossier.
	 */
	private const CHECK_EVERY = 64;

	/**
	 * @param string   $directory Dossier mesuré.
	 * @param string[] $exclude   Dossiers ignorés avec tout leur contenu (chemins absolus).
	 * @param float    $budget    Secondes disponibles ; au-delà, la mesure s'arrête et la valeur est partielle.
	 * @return array{bytes: int, complete: bool}|null Null si le chemin existe mais n'est pas un dossier lisible ;
	 *                                                un dossier absent pèse 0 octet.
	 */
	public static function measure( string $directory, array $exclude, float $budget ): ?array {
		$directory = untrailingslashit( $directory );
		if ( ! file_exists( $directory ) ) {
			return self::result( 0, true );
		}
		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return null;
		}

		$skip     = array_map( 'untrailingslashit', $exclude );
		$deadline = microtime( true ) + max( 0.0, $budget );
		$bytes    = 0;
		$pending  = [ $directory ];
		while ( [] !== $pending ) {
			if ( microtime( true ) >= $deadline ) {
				return self::result( $bytes, false );
			}
			$current = (string) array_pop( $pending );
			$entries = @scandir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable sub-folder is skipped, not reported.
			if ( false === $entries ) {
				continue;
			}
			$seen = 0;
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( is_link( $path ) ) {
					continue; // Un lien n'est pas suivi : ni boucle, ni fichier compté deux fois.
				}
				if ( is_dir( $path ) ) {
					if ( ! in_array( $path, $skip, true ) ) {
						$pending[] = $path;
					}
				} elseif ( is_file( $path ) ) {
					$size   = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted meanwhile counts for nothing.
					$bytes += false === $size ? 0 : $size;
				}
				if ( 0 === ++$seen % self::CHECK_EVERY && microtime( true ) >= $deadline ) {
					return self::result( $bytes, false );
				}
			}
		}
		return self::result( $bytes, true );
	}

	/**
	 * @return array{bytes: int, complete: bool}
	 */
	private static function result( int $bytes, bool $complete ): array {
		return [
			'bytes'    => $bytes,
			'complete' => $complete,
		];
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bin/test.sh --filter DiskMeterTest`
Expected: PASS (6 tests ; le test des liens peut être marqué « skipped » si le système ne permet pas `symlink()`).

- [ ] **Step 5: Write the failing collector tests**

Dans `tests/php/Collector/SiteCollectorTest.php`, ajouter une propriété, compléter `tear_down()` et ajouter les tests suivants. Le dossier d'envoi est redirigé vers un dossier temporaire par le filtre `upload_dir` (appliqué par `wp_upload_dir()` après sa mise en cache) : les tests n'écrivent jamais dans l'installation WordPress.

```php
	private string $uploads = '';

	public function tear_down(): void {
		if ( '' !== $this->uploads ) {
			self::remove_tree( $this->uploads );
			$this->uploads = '';
		}
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
	}

	/**
	 * Dossiers d'envoi factices, rangés comme ceux du cœur : la racine pour le site principal, sites/<id> pour les autres.
	 */
	private function fake_uploads(): string {
		$root = untrailingslashit( get_temp_dir() ) . '/msradar-uploads-' . wp_generate_password( 8, false );
		wp_mkdir_p( $root );
		add_filter(
			'upload_dir',
			static function ( array $uploads ) use ( $root ): array {
				$uploads['basedir'] = is_main_site() ? $root : $root . '/sites/' . get_current_blog_id();
				return $uploads;
			}
		);
		$this->uploads = $root;
		return $root;
	}

	private static function put_file( string $path, int $bytes ): void {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_tree( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	public function test_measures_the_upload_folder_of_the_site(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/2026/09/photo.jpg', 1500 );

		$record = $this->collect( $site_id );

		$this->assertSame( 1500, $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}

	public function test_a_site_without_upload_folder_uses_no_disk_space(): void {
		$this->fake_uploads();

		$record = $this->collect( self::factory()->blog->create() );

		$this->assertSame( 0, $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}

	public function test_the_main_site_does_not_count_the_folders_of_the_other_sites(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/logo.png', 100 );
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );

		$this->assertSame( 100, $this->collect( get_main_site_id() )->disk_bytes );
	}

	public function test_a_measure_cut_short_by_its_budget_is_an_estimate(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );
		add_filter( 'msradar_disk_budget', static fn (): float => 0.0 );

		$record = $this->collect( $site_id );

		$this->assertTrue( $record->disk_is_estimate );
		$this->assertLessThan( 1500, (int) $record->disk_bytes );
	}

	public function test_the_disk_measure_can_be_switched_off(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );
		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );

		$record = $this->collect( $site_id );

		$this->assertNull( $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}
```

Le `tear_down()` existant (qui désenregistre `fixture_event`) est **remplacé** par celui ci-dessus : il n'en faut qu'un.

- [ ] **Step 6: Run the tests to verify they fail**

Run: `bin/test.sh --filter SiteCollectorTest`
Expected: FAIL. Les nouveaux tests trouvent `disk_bytes` à `null` (le collecteur ne mesure rien encore). `test_the_disk_measure_can_be_switched_off` passe déjà.

- [ ] **Step 7: Measure the upload folder in the collector**

Dans `includes/Collector/SiteCollector.php` :

1. Ajouter la constante après `ZERO_DATE` :

```php
	/**
	 * Secondes accordées à la mesure du dossier d'envoi d'un site (filtre msradar_disk_budget).
	 */
	public const DISK_BUDGET = 2.0;
```

2. Dans `collect()`, juste après le bloc `try { … } finally { $wpdb->suppress_errors( $suppress ); }`, ajouter :

```php
		$disk = $this->measure_disk( $site_id, (int) $site->site_id );
```

3. Toujours dans `collect()`, après la ligne `$record->media_count = …;`, ajouter (en gardant l'alignement des `=` du bloc) :

```php
		$record->disk_bytes        = $disk['bytes'];
		$record->disk_is_estimate  = $disk['estimate'];
```

4. Ajouter la méthode, après `read_users()` :

```php
	/**
	 * Dossier d'envoi du site, mesuré dans un budget de temps. C'est le seul endroit où le collecteur change de site
	 * (spec §3.3) : wp_upload_dir() dépend des options du site. Le site principal ne compte pas sites/, où vivent les
	 * autres sites, comme get_dirsize() du cœur.
	 *
	 * @return array{bytes: int|null, estimate: bool}
	 */
	private function measure_disk( int $site_id, int $network_id ): array {
		if ( ! $this->settings->get( 'scan.measure_disk', true ) ) {
			return [
				'bytes'    => null,
				'estimate' => false,
			];
		}

		switch_to_blog( $site_id );
		try {
			$uploads = wp_upload_dir( null, false );
		} finally {
			restore_current_blog();
		}
		$base = untrailingslashit( (string) ( $uploads['basedir'] ?? '' ) );
		if ( '' === $base ) {
			return [
				'bytes'    => null,
				'estimate' => false,
			];
		}

		$exclude = is_main_site( $site_id, $network_id ) ? [ $base . '/sites' ] : [];
		$budget  = (float) apply_filters( 'msradar_disk_budget', self::DISK_BUDGET, $site_id );
		$result  = DiskMeter::measure( $base, $exclude, $budget );

		return [
			'bytes'    => null === $result ? null : $result['bytes'],
			'estimate' => null !== $result && ! $result['complete'],
		];
	}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'SiteCollectorTest|DiskMeterTest'`
Expected: PASS.

- [ ] **Step 9: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus de la base de test (voir « Commandes ») ; lint et analyse sans erreur.

- [ ] **Step 10: Commit**

```bash
git add includes/Collector/DiskMeter.php includes/Collector/SiteCollector.php tests/php/Collector/DiskMeterTest.php tests/php/Collector/SiteCollectorTest.php
git commit -m "feat: measure the upload folder of each site within a time budget"
```

### Task 2: Taille des tables, poids de l'autoload, tâches planifiées en retard, quota propre au site

**Files:**
- Modify: `includes/Collector/SiteCollector.php`
- Test: `tests/php/Collector/SiteCollectorTest.php`

**Interfaces:**
- Consumes : `Schema::tables()` (tables du plugin), `wp_autoload_values_to_autoload()` (WordPress 6.6+, valeurs `yes`, `on`, `auto-on`, `auto`).
- Produces :
  - `SiteRecord::$autoload_bytes` (`int`) et `SiteRecord::$db_bytes` (`?int`) remplis par `collect()` ;
  - `data['cron']` : `array{overdue_count: int, oldest_overdue_gmt: string|null}` (date GMT `Y-m-d H:i:s`) ;
  - `data['options']['upload_space_mb']` : `int|null` (quota propre au site en Mo ; `null` s'il suit celui du réseau) ;
  - `scanned_at` vaut exactement la date de référence des tâches en retard ;
  - `SiteCollector::site_tables( string[] $names, string $prefix, bool $main, string[] $network_tables ): string[]` (public, statique, pur) ;
  - `SiteCollector::overdue_tasks( mixed $cron, int $now ): array{overdue_count: int, oldest_overdue_gmt: string|null}` (public, statique, pur).

- [ ] **Step 1: Write the failing tests**

Ajouter à `tests/php/Collector/SiteCollectorTest.php` :

```php
	public function test_weighs_the_autoloaded_options_only(): void {
		$site_id = self::factory()->blog->create();
		$before  = $this->collect( $site_id );
		switch_to_blog( $site_id );
		add_option( 'msradar_test_hot', str_repeat( 'a', 5000 ), '', true );
		add_option( 'msradar_test_cold', str_repeat( 'b', 7000 ), '', false );
		restore_current_blog();

		$this->assertSame( 5000, $this->collect( $site_id )->autoload_bytes - $before->autoload_bytes );
	}

	public function test_the_main_site_weighs_its_own_tables_only(): void {
		global $wpdb;
		$queries = [];
		$spy     = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, 'information_schema' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $spy );

		$this->assertGreaterThan( 0, (int) $record->db_bytes );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "'" . $wpdb->base_prefix . "posts'", $queries[0] );
		$this->assertStringNotContainsString( "'" . $wpdb->base_prefix . "users'", $queries[0] );
		$this->assertStringNotContainsString( "'" . $wpdb->base_prefix . "msradar_sites'", $queries[0] );
		$this->assertSame( 0, preg_match( "/'" . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $queries[0] ), 'No table of another site is listed.' );
	}

	public function test_the_table_status_is_read_when_information_schema_is_refused(): void {
		global $wpdb;
		$statuses = 0;
		$filter   = static function ( string $query ) use ( &$statuses ): string {
			if ( false !== strpos( $query, 'information_schema' ) ) {
				return 'SELECT * FROM msradar_no_such_table';
			}
			if ( 0 === strpos( $query, 'SHOW TABLE STATUS' ) ) {
				++$statuses;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $filter );

		$this->assertSame( 1, $statuses );
		$this->assertGreaterThan( 0, (int) $record->db_bytes );
	}

	public function test_the_database_size_is_unknown_when_no_source_answers(): void {
		$filter = static function ( string $query ): string {
			return false !== strpos( $query, 'information_schema' ) || 0 === strpos( $query, 'SHOW TABLE STATUS' )
				? 'SELECT * FROM msradar_no_such_table'
				: $query;
		};
		add_filter( 'query', $filter );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $filter );

		$this->assertNull( $record->db_bytes );
		$this->assertGreaterThan( 0, $record->users_count, 'The rest of the analysis is unaffected.' );
	}

	public function test_site_tables_of_the_main_site_leave_out_other_sites_and_network_tables(): void {
		$names   = [ 'wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_msradar_sites', 'wp_2_posts', 'wp_12_options', 'wp_wc_orders', 'wp_2fa_codes' ];
		$network = [ 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_msradar_sites' ];

		$this->assertSame( [ 'wp_posts', 'wp_options', 'wp_wc_orders', 'wp_2fa_codes' ], SiteCollector::site_tables( $names, 'wp_', true, $network ) );
		$this->assertSame( [ 'wp_2_posts', 'wp_2_options' ], SiteCollector::site_tables( [ 'wp_2_posts', 'wp_2_options' ], 'wp_2_', false, $network ) );
	}

	public function test_counts_the_scheduled_tasks_already_due(): void {
		$now  = 1790000000;
		$cron = [
			$now - 7200 => [
				'hook_a' => [
					'k1' => [],
					'k2' => [],
				],
			],
			$now - 60   => [ 'hook_b' => [ 'k3' => [] ] ],
			$now + 60   => [ 'hook_c' => [ 'k4' => [] ] ],
			$now - 9000 => [],
			'version'   => 2,
		];

		$this->assertSame(
			[
				'overdue_count'      => 3,
				'oldest_overdue_gmt' => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
			],
			SiteCollector::overdue_tasks( $cron, $now )
		);
		$this->assertSame(
			[
				'overdue_count'      => 0,
				'oldest_overdue_gmt' => null,
			],
			SiteCollector::overdue_tasks( 'corrupted', $now )
		);
	}

	public function test_stores_the_overdue_tasks_and_the_own_upload_quota_of_the_site(): void {
		$site_id = self::factory()->blog->create();
		$due     = time() - 3 * HOUR_IN_SECONDS;
		update_blog_option(
			$site_id,
			'cron',
			[
				$due      => [
					'msradar_test' => [
						'abc' => [
							'schedule' => false,
							'args'     => [],
						],
					],
				],
				'version' => 2,
			]
		);
		update_blog_option( $site_id, 'blog_upload_space', '50' );

		$record = $this->collect( $site_id );

		$this->assertSame(
			[
				'overdue_count'      => 1,
				'oldest_overdue_gmt' => gmdate( 'Y-m-d H:i:s', $due ),
			],
			$record->data['cron']
		);
		$this->assertSame( 50, $record->data['options']['upload_space_mb'] );
		$this->assertNull( $this->collect( self::factory()->blog->create() )->data['options']['upload_space_mb'], 'A site without its own quota follows the network.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter SiteCollectorTest`
Expected: FAIL (`Call to undefined method …::site_tables()`, puis valeurs absentes).

- [ ] **Step 3: Implement the readings**

Dans `includes/Collector/SiteCollector.php` :

1. Ajouter l'import `use MultisiteRadar\Install\Schema;` (ordre alphabétique des `use`).

2. Au début de `collect()`, après le test `null === $site`, calculer la date de référence et le réseau :

```php
		$now        = time();
		$network_id = (int) $site->site_id; // WP_Site::$site_id contient l'ID du réseau.
```

   Dans la suite de `collect()`, remplacer `(int) $site->site_id` par `$network_id` (appels à `get_network_option()`, `measure_disk()`, `$this->locale()` et affectation de `network_id`), et l'appel `time()` passé à `RegistryProbe::status()` par `$now`.

3. Dans le bloc `try`, après la lecture des utilisateurs :

```php
			$autoload = $this->read_autoload_bytes( $prefix );
			$db_bytes = $this->read_db_bytes( $prefix, is_main_site( $site_id, $network_id ) );
```

4. Après les lignes `disk_bytes` / `disk_is_estimate` de l'enregistrement :

```php
		$record->db_bytes          = $db_bytes;
		$record->autoload_bytes    = $autoload;
```

5. Dans `$record->data`, ajouter la clé `upload_space_mb` à `options`, puis la clé `cron` après `options` :

```php
			'options'       => [
				'blog_public'     => (int) ( $options['blog_public'] ?? 1 ),
				'siteurl'         => $siteurl,
				'home'            => $home,
				'locale'          => $this->locale( $options, $network_id ),
				'upload_space_mb' => self::upload_space( $options ),
			],
			'cron'          => self::overdue_tasks( $options['cron'] ?? null, $now ),
```

6. Remplacer `$record->scanned_at = current_time( 'mysql', true );` par :

```php
		$record->scanned_at        = gmdate( 'Y-m-d H:i:s', $now );
```

7. Dans `read_options()`, ajouter `'cron'` et `'blog_upload_space'` à la liste `$names` (avant `RegistryProbe::OPTION`).

8. Ajouter les méthodes, après `read_users()` :

```php
	/**
	 * Poids des options chargées à chaque requête du site, avec les valeurs d'autoload de WordPress (spec §3.3, n° 5).
	 */
	private function read_autoload_bytes( string $prefix ): int {
		global $wpdb;
		$values = array_values( array_map( 'strval', wp_autoload_values_to_autoload() ) );
		$total  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(LENGTH(option_value)) FROM %i WHERE autoload IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')',
				array_merge( [ $prefix . 'options' ], $values )
			)
		);
		$this->guard();
		return (int) $total;
	}

	/**
	 * Taille des tables du site (données et index), lue dans information_schema pour une liste explicite de tables,
	 * avec repli sur SHOW TABLE STATUS. Null si aucune des deux sources ne répond : un hébergeur peut les refuser, et
	 * l'analyse du site continue (spec §3.3, n° 7 et §14).
	 */
	private function read_db_bytes( string $prefix, bool $main ): ?int {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		$tables = self::site_tables( array_map( 'strval', (array) $names ), $prefix, $main, self::network_tables() );
		if ( [] === $tables ) {
			return null;
		}

		$in   = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$size = $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ')', $tables )
		);
		if ( '' === $wpdb->last_error && null !== $size ) {
			return (int) $size;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (' . $in . ')', $tables ), ARRAY_A );
		if ( '' !== $wpdb->last_error || [] === (array) $rows ) {
			return null;
		}
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$total += (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 );
		}
		return $total;
	}

	/**
	 * Tables communes au réseau : elles ne pèsent pas dans la base du site principal (écart E6 du plan M4).
	 *
	 * @return string[]
	 */
	private static function network_tables(): array {
		global $wpdb;
		return array_values( array_merge( array_map( 'strval', $wpdb->tables( 'global' ) ), Schema::tables() ) );
	}

	/**
	 * Tables d'un site parmi celles dont le nom commence par son préfixe. Le site principal partage le préfixe de base
	 * avec les tables des autres sites (« {base}<id>_ ») et celles du réseau : elles sont écartées.
	 *
	 * @param string[] $names          Résultat de SHOW TABLES LIKE '{prefix}%'.
	 * @param string[] $network_tables Tables globales du réseau, préfixe compris.
	 * @return string[]
	 */
	public static function site_tables( array $names, string $prefix, bool $main, array $network_tables ): array {
		$numbered = '/^' . preg_quote( $prefix, '/' ) . '\d+_/';
		return array_values(
			array_filter(
				$names,
				static function ( string $name ) use ( $prefix, $main, $numbered, $network_tables ): bool {
					if ( 0 !== strpos( $name, $prefix ) ) {
						return false;
					}
					return ! $main || ( 1 !== preg_match( $numbered, $name ) && ! in_array( $name, $network_tables, true ) );
				}
			)
		);
	}

	/**
	 * Tâches planifiées du site déjà échues au moment de l'analyse. La règle cron_overdue mesure leur retard par
	 * rapport à cette date (écart E1 du plan M4).
	 *
	 * @param mixed $cron Option cron du site.
	 * @return array{overdue_count: int, oldest_overdue_gmt: string|null}
	 */
	public static function overdue_tasks( $cron, int $now ): array {
		$count  = 0;
		$oldest = null;
		foreach ( is_array( $cron ) ? $cron : [] as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || $timestamp > $now || ! is_array( $hooks ) ) {
				continue;
			}
			$events = 0;
			foreach ( $hooks as $instances ) {
				$events += is_array( $instances ) ? count( $instances ) : 0;
			}
			if ( 0 === $events ) {
				continue;
			}
			$count += $events;
			$oldest = null === $oldest ? $timestamp : min( $oldest, $timestamp );
		}
		return [
			'overdue_count'      => $count,
			'oldest_overdue_gmt' => null === $oldest ? null : gmdate( 'Y-m-d H:i:s', $oldest ),
		];
	}

	/**
	 * Quota d'envoi propre au site (option blog_upload_space), en Mo ; null s'il suit celui du réseau.
	 */
	private static function upload_space( array $options ): ?int {
		$value = $options['blog_upload_space'] ?? null;
		return is_numeric( $value ) ? (int) $value : null;
	}
```

`read_db_bytes()` est appelé dans le bloc où `$wpdb->suppress_errors( true )` est actif : les requêtes refusées n'affichent rien. Il n'appelle jamais `guard()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bin/test.sh --filter SiteCollectorTest`
Expected: PASS.

- [ ] **Step 5: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus ; lint et analyse sans erreur.

- [ ] **Step 6: Commit**

```bash
git add includes/Collector/SiteCollector.php tests/php/Collector/SiteCollectorTest.php
git commit -m "feat: collect table sizes, autoloaded options, overdue scheduled tasks and the site upload quota"
```

### Task 3: Analyse complète après la mise à jour, et après un changement de la mesure du disque

**Files:**
- Modify: `includes/Install/Schema.php`, `includes/Scan/Queue.php`
- Test: `tests/php/Install/SchemaTest.php`, `tests/php/Scan/QueueTest.php`

**Interfaces:**
- Consumes : `Installer::maybe_upgrade()` et l'action `msradar_upgraded` (existants), `Queue::on_upgraded()` qui demande déjà une analyse complète.
- Produces : `Schema::VERSION = 3` ; `Queue::on_settings_updated()` marque tous les sites quand `scan.measure_disk` change.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Install/SchemaTest.php`, la ligne `$this->assertSame( 2, (int) get_site_option( Schema::OPTION ) );` devient :

```php
		$this->assertSame( 3, (int) get_site_option( Schema::OPTION ) );
```

Ajouter à `tests/php/Scan/QueueTest.php` (et l'import `use MultisiteRadar\Install\Installer;`) :

```php
	public function test_an_install_from_beta_3_is_analysed_again_to_fill_the_measures(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );
		update_site_option( Schema::OPTION, 2 );

		Installer::maybe_upgrade();

		$this->assertTrue( Schema::is_current() );
		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_switching_the_disk_measure_marks_every_site_for_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );

		$this->mark_all_clean();
		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );
		$this->assertSame( 0, $this->plugin()->sites()->count_dirty( $network ), 'Saving the same value again is not a change.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'SchemaTest|QueueTest'`
Expected : FAIL sur la version 3 et sur les deux nouveaux tests (en plus des 7 échecs connus de `QueueTest`, s'ils sont encore là).

- [ ] **Step 3: Bump the schema version**

Dans `includes/Install/Schema.php`, le docblock et la constante deviennent :

```php
	/**
	 * 1 : tables de M1 ; 2 : colonne siteurl (M2) ; 3 : aucune colonne nouvelle, mais la mise à niveau relance une
	 * analyse complète qui remplit les mesures disque, base, autoload et tâches planifiées (M4, écart E8).
	 * Les tables events/snapshots de M6 prendront la version 4.
	 */
	public const VERSION = 3;
```

- [ ] **Step 4: Mark every site when the disk measure is switched**

Dans `includes/Scan/Queue.php` :

1. Dans le docblock de `on_upgraded()`, remplacer « (la version 2 ajoute siteurl) » par « (la version 2 ajoute siteurl, la version 3 remplit les mesures de M4) ».

2. Dans `on_settings_updated()`, remplacer le test sur les types d'activité et son docblock par :

```php
	/**
	 * Les nouveaux réglages s'appliquent à tout le réseau : le recalcul repart du premier site.
	 * La date de dernière activité dépend des types d'activité, et les mesures du réglage « mesurer le disque » :
	 * si l'un d'eux change, tous les sites sont réanalysés.
	 *
	 * @param mixed $new_settings Réglages complets après la mise à jour.
	 * @param mixed $old_settings Réglages complets avant la mise à jour.
	 */
	public function on_settings_updated( $new_settings = [], $old_settings = [] ): void {
		$this->evaluator->reset();
		delete_site_option( self::RECOMPUTE_CURSOR );
		MainSite::schedule_once( self::HOOK_RECOMPUTE );

		if ( self::activity_types( $new_settings ) !== self::activity_types( $old_settings )
			|| self::measures_disk( $new_settings ) !== self::measures_disk( $old_settings ) ) {
			$this->sites->mark_all_dirty( get_current_network_id() );
			$this->continue_soon();
		}
	}
```

3. Ajouter, après `activity_types()` :

```php
	/**
	 * @param mixed $settings Réglages complets.
	 */
	private static function measures_disk( $settings ): bool {
		return is_array( $settings ) ? (bool) ( $settings['scan']['measure_disk'] ?? true ) : true;
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'SchemaTest|QueueTest|InstallerTest'`
Expected : PASS (hors échecs connus de la base de test).

- [ ] **Step 6: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus.

- [ ] **Step 7: Commit**

```bash
git add includes/Install/Schema.php includes/Scan/Queue.php tests/php/Install/SchemaTest.php tests/php/Scan/QueueTest.php
git commit -m "feat: analyse every site again after the update and when the disk measure is switched"
```

## Partie B — Règles d'alertes

### Task 4: Règles sur les données du site — `no_admin`, `heavy_autoload`, `search_hidden`, `cron_overdue`

**Files:**
- Create: `includes/Alerts/Rules/NoAdminRule.php`, `includes/Alerts/Rules/HeavyAutoloadRule.php`, `includes/Alerts/Rules/SearchHiddenRule.php`, `includes/Alerts/Rules/CronOverdueRule.php`
- Modify: `includes/Alerts/RuleRegistry.php`, `includes/Storage/SiteRecord.php`
- Test: `tests/php/Alerts/RulesTest.php`, et les fixtures de `tests/php/Alerts/AlertEvaluatorTest.php`, `tests/php/Scan/QueueTest.php`, `tests/php/Query/AlertsQueryTest.php`

**Interfaces:**
- Consumes : `SiteRecord::$users_count`, `$admins_count`, `$autoload_bytes`, `$is_public`, `$scanned_at`, `$data['cron']` (tâche 2).
- Produces :
  - `SiteRecord::is_served(): bool` — ni archivé, ni indésirable, ni supprimé ;
  - quatre règles, dans cet ordre dans `RuleRegistry::create_default()` : `no_users`, `inactive`, `high_media`, `no_admin`, `heavy_autoload`, `search_hidden`, `cron_overdue` (la tâche 5 insère ses quatre règles après `no_admin`) ;
  - arguments stockés : `heavy_autoload` → `{ kilobytes: int, threshold: int }` ; `cron_overdue` → `{ count: int, hours: int }` ; `no_admin` et `search_hidden` → `[]`.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Alerts/RulesTest.php`, ajouter les imports :

```php
use MultisiteRadar\Alerts\Rules\CronOverdueRule;
use MultisiteRadar\Alerts\Rules\HeavyAutoloadRule;
use MultisiteRadar\Alerts\Rules\NoAdminRule;
use MultisiteRadar\Alerts\Rules\SearchHiddenRule;
use MultisiteRadar\Alerts\Severity;
```

Remplacer `test_default_params_satisfy_their_schema()` par :

```php
	public function test_every_default_rule_is_well_formed(): void {
		foreach ( RuleRegistry::create_default()->all() as $id => $rule ) {
			$this->assertSame( 1, preg_match( RuleRegistry::ID_PATTERN, $id ), $id );
			$this->assertNotSame( '', $rule->label(), $id );
			$this->assertNotSame( '', $rule->description(), $id );
			$this->assertTrue( Severity::is_valid( $rule->default_severity() ), $id );
			$this->assertSame( 'object', $rule->params_schema()['type'], $id );
			$this->assertFalse( $rule->params_schema()['additionalProperties'], $id );
			$this->assertTrue( rest_validate_value_from_schema( $rule->default_params(), $rule->params_schema(), 'params' ), $id );
		}
	}

	public function test_the_default_rules_follow_the_order_of_the_spec(): void {
		$this->assertSame(
			[ 'no_users', 'inactive', 'high_media', 'no_admin', 'heavy_autoload', 'search_hidden', 'cron_overdue' ],
			array_keys( RuleRegistry::create_default()->all() )
		);
	}

	public function test_no_admin_leaves_sites_without_accounts_to_no_users(): void {
		$rule = new NoAdminRule();

		$this->assertNotNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'admins_count' => 0 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'admins_count' => 1 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 0, 'admins_count' => 0 ] ), [], time() ), 'no_users already flags a site without accounts.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'scanned_at' => null ] ), [], time() ) );
		$this->assertSame( 'No account has the administrator role on this site.', $rule->message( [] ) );
	}

	public function test_heavy_autoload(): void {
		$rule = new HeavyAutoloadRule();

		$alert = $rule->evaluate( $this->build_record( [ 'autoload_bytes' => 800 * KB_IN_BYTES ] ), [ 'kilobytes' => 800 ], time() );
		$this->assertSame( [ 'kilobytes' => 800, 'threshold' => 800 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'autoload_bytes' => 800 * KB_IN_BYTES - 1 ] ), [ 'kilobytes' => 800 ], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'autoload_bytes' => null ] ), [ 'kilobytes' => 800 ], time() ), 'Not measured yet: no alert.' );
		$this->assertSame( '1,024 KB of autoloaded options (threshold: 800 KB)', $rule->message( [ 'kilobytes' => 1024, 'threshold' => 800 ] ) );
	}

	public function test_search_hidden_ignores_the_sites_that_are_not_served(): void {
		$rule = new SearchHiddenRule();

		$this->assertNotNull( $rule->evaluate( $this->build_record( [ 'is_public' => false ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'is_public' => true ] ), [], time() ) );
		foreach ( [ 'is_archived', 'is_spam', 'is_deleted' ] as $flag ) {
			$this->assertNull( $rule->evaluate( $this->build_record( [ 'is_public' => false, $flag => true ] ), [], time() ), $flag );
		}
		$this->assertSame( 'Search engines are asked not to index this site.', $rule->message( [] ) );
	}

	public function test_cron_overdue_measures_the_delay_at_the_time_of_the_analysis(): void {
		$rule   = new CronOverdueRule();
		$later  = (int) strtotime( '2026-12-01 00:00:00 UTC' );
		$record = fn ( string $oldest, array $props = [] ): SiteRecord => $this->build_record(
			array_merge(
				[
					'scanned_at' => '2026-09-01 12:00:00',
					'data'       => [
						'cron' => [
							'overdue_count'      => 4,
							'oldest_overdue_gmt' => $oldest,
						],
					],
				],
				$props
			)
		);

		$alert = $rule->evaluate( $record( '2026-08-31 06:00:00' ), [ 'hours' => 24 ], $later );
		$this->assertSame( [ 'count' => 4, 'hours' => 30 ], $alert->args );
		$this->assertNull( $rule->evaluate( $record( '2026-08-31 13:00:00' ), [ 'hours' => 24 ], $later ), 'Three months later, the delay is still the one seen at the analysis.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'scanned_at' => '2026-09-01 12:00:00' ] ), [ 'hours' => 24 ], $later ), 'Analysed before 2.0.0-beta.4: no data, no alert.' );
		foreach ( [ 'is_archived', 'is_spam', 'is_deleted' ] as $flag ) {
			$this->assertNull( $rule->evaluate( $record( '2026-08-01 00:00:00', [ $flag => true ] ), [ 'hours' => 24 ], $later ), $flag );
		}
		$this->assertSame( 'At the last analysis, 4 scheduled tasks were overdue; the oldest had been waiting for 1 day.', $rule->message( [ 'count' => 4, 'hours' => 30 ] ) );
		$this->assertSame( 'At the last analysis, 1 scheduled task was overdue; the oldest had been waiting for 2 days.', $rule->message( [ 'count' => 1, 'hours' => 48 ] ) );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter RulesTest`
Expected: FAIL, `Class "MultisiteRadar\Alerts\Rules\NoAdminRule" not found`.

- [ ] **Step 3: Add `SiteRecord::is_served()`**

Dans `includes/Storage/SiteRecord.php`, après `alert_rule_ids()` :

```php
	/**
	 * Vrai si WordPress sert le site : ni archivé, ni indésirable, ni supprimé.
	 */
	public function is_served(): bool {
		return ! $this->is_archived && ! $this->is_spam && ! $this->is_deleted;
	}
```

- [ ] **Step 4: Write the four rules**

`includes/Alerts/Rules/NoAdminRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class NoAdminRule implements RuleInterface {

	public function id(): string {
		return 'no_admin';
	}

	public function label(): string {
		return __( 'Site without administrator', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The site has accounts, but none of them has the administrator role. Super admins are not counted.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	/**
	 * Un site sans aucun compte relève de no_users (écart E3 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || 0 === $site->users_count || $site->admins_count > 0 ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'No account has the administrator role on this site.', 'multisite-radar' );
	}
}
```

`includes/Alerts/Rules/HeavyAutoloadRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class HeavyAutoloadRule implements RuleInterface {

	public function id(): string {
		return 'heavy_autoload';
	}

	public function label(): string {
		return __( 'Heavy autoloaded options', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The options that WordPress loads on every page of the site weigh more than the threshold, which slows the whole site down.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'kilobytes' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1048576,
					'title'       => __( 'Threshold (KB)', 'multisite-radar' ),
					'description' => __( 'Size of the autoloaded options, in kilobytes, that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'kilobytes' => 800 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		$threshold = (int) $params['kilobytes'];
		if ( null === $site->scanned_at || null === $site->autoload_bytes || $site->autoload_bytes < $threshold * KB_IN_BYTES ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'kilobytes' => (int) floor( $site->autoload_bytes / KB_IN_BYTES ),
				'threshold' => $threshold,
			]
		);
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: 1: size of the autoloaded options in kilobytes, 2: alert threshold in kilobytes. */
			__( '%1$s KB of autoloaded options (threshold: %2$s KB)', 'multisite-radar' ),
			number_format_i18n( (int) ( $args['kilobytes'] ?? 0 ) ),
			number_format_i18n( (int) ( $args['threshold'] ?? 0 ) )
		);
	}
}
```

`includes/Alerts/Rules/SearchHiddenRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class SearchHiddenRule implements RuleInterface {

	public function id(): string {
		return 'search_hidden';
	}

	public function label(): string {
		return __( 'Hidden from search engines', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The site asks search engines not to index it (Settings > Reading). Archived, spam and deleted sites are ignored.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	/**
	 * is_public reprend la colonne public de wp_blogs, synchronisée par le cœur avec l'option blog_public.
	 * Un site que WordPress ne sert pas n'est de toute façon pas indexé (écart E2 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || $site->is_public || ! $site->is_served() ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'Search engines are asked not to index this site.', 'multisite-radar' );
	}
}
```

`includes/Alerts/Rules/CronOverdueRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class CronOverdueRule implements RuleInterface {

	public function id(): string {
		return 'cron_overdue';
	}

	public function label(): string {
		return __( 'Overdue scheduled tasks', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'At the last analysis, scheduled tasks (WP-Cron) of the site had been waiting past their due time. Archived, spam and deleted sites are ignored: their tasks cannot run.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'hours' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 720,
					'title'       => __( 'Delay (hours)', 'multisite-radar' ),
					'description' => __( 'Hours the oldest overdue task had been waiting, at the last analysis, before the alert is raised.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'hours' => 24 ];
	}

	/**
	 * Le retard est mesuré à la date de l'analyse, pas à $now : le recalcul quotidien part des données stockées, et la
	 * tâche a pu s'exécuter depuis (écart E1 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || ! $site->is_served() ) {
			return null;
		}
		$cron    = is_array( $site->data['cron'] ?? null ) ? $site->data['cron'] : [];
		$oldest  = is_string( $cron['oldest_overdue_gmt'] ?? null ) ? strtotime( $cron['oldest_overdue_gmt'] . ' UTC' ) : false;
		$scanned = strtotime( $site->scanned_at . ' UTC' );
		if ( false === $oldest || false === $scanned ) {
			return null;
		}
		$hours = (int) floor( ( $scanned - $oldest ) / HOUR_IN_SECONDS );
		if ( $hours < (int) $params['hours'] ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'count' => max( 1, (int) ( $cron['overdue_count'] ?? 1 ) ),
				'hours' => $hours,
			]
		);
	}

	public function message( array $args ): string {
		$count = (int) ( $args['count'] ?? 0 );
		return sprintf(
			/* translators: 1: number of overdue scheduled tasks, 2: how long the oldest one had been waiting, such as "2 days". */
			_n(
				'At the last analysis, %1$s scheduled task was overdue; the oldest had been waiting for %2$s.',
				'At the last analysis, %1$s scheduled tasks were overdue; the oldest had been waiting for %2$s.',
				$count,
				'multisite-radar'
			),
			number_format_i18n( $count ),
			human_time_diff( 0, (int) ( $args['hours'] ?? 0 ) * HOUR_IN_SECONDS )
		);
	}
}
```

- [ ] **Step 5: Register the rules**

Dans `includes/Alerts/RuleRegistry.php`, ajouter les imports des quatre classes (ordre alphabétique) et remplacer `create_default()` par :

```php
	public static function create_default(): self {
		return new self(
			[
				new NoUsersRule(),
				new InactiveRule(),
				new HighMediaRule(),
				new NoAdminRule(),
				new HeavyAutoloadRule(),
				new SearchHiddenRule(),
				new CronOverdueRule(),
			]
		);
	}
```

- [ ] **Step 6: Run the rule tests to verify they pass**

Run: `bin/test.sh --filter RulesTest`
Expected: PASS.

- [ ] **Step 7: Adapt the fixtures that now raise `no_admin`**

Un enregistrement analysé qui a des comptes mais aucun administrateur lève désormais `no_admin`. Les fixtures suivantes décrivent des sites « sains » : on leur donne un administrateur, on ne touche pas à la règle.

- `tests/php/Alerts/AlertEvaluatorTest.php`, `test_no_alert_clears_previous_values()` : ajouter `'admins_count' => 1` aux propriétés du `build_record()`.
- `tests/php/Scan/QueueTest.php`, `test_recompute_alerts_touches_only_the_current_network_with_its_settings()` : ajouter `'admins_count' => 1` à `$props`.
- `tests/php/Scan/QueueTest.php`, `test_recompute_rewrites_only_the_sites_whose_alerts_changed()` : la mise à jour directe devient `$wpdb->update( $table, [ 'users_count' => 4, 'admins_count' => 1 ], [ 'site_id' => 3302 ] );`.
- `tests/php/Query/AlertsQueryTest.php`, `test_summary_counts_sites_by_severity_and_rule()` : la synthèse liste maintenant toutes les règles. Remplacer l'assertion sur `$summary['by_rule']` par :

```php
		$this->assertSame( array_keys( $this->plugin()->rules()->all() ), wp_list_pluck( $summary['by_rule'], 'rule' ) );
		$this->assertSame(
			[
				[ 'rule' => 'no_users', 'label' => 'Site without users', 'severity' => 'error', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'inactive', 'label' => 'Inactive site', 'severity' => 'warning', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'high_media', 'label' => 'Many media files', 'severity' => 'info', 'enabled' => true, 'count' => 1 ],
			],
			array_slice( $summary['by_rule'], 0, 3 )
		);
		$this->assertSame( [ 0 ], array_values( array_unique( wp_list_pluck( array_slice( $summary['by_rule'], 3 ), 'count' ) ) ), 'No site raises the other rules.' );
```

Ensuite, lancer toute la suite : tout autre test qui échoue parce qu'une fixture lève maintenant une des quatre nouvelles règles se corrige de la même façon (fixture complétée, jamais la règle), et le rapport de la tâche les liste.

- [ ] **Step 8: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus de la base de test (dont `AlertsQueryTest::test_summary_counts_sites_by_severity_and_rule`, qui reste décalé d'une unité sur ses totaux tant que la ligne parasite existe) ; lint et analyse sans erreur.

- [ ] **Step 9: Commit**

```bash
git add includes/Alerts/Rules/NoAdminRule.php includes/Alerts/Rules/HeavyAutoloadRule.php includes/Alerts/Rules/SearchHiddenRule.php includes/Alerts/Rules/CronOverdueRule.php includes/Alerts/RuleRegistry.php includes/Storage/SiteRecord.php tests/php/
git commit -m "feat: add the no_admin, heavy_autoload, search_hidden and cron_overdue alert rules"
```

### Task 5: État du réseau et règles qui en dépendent — `missing_theme`, `updates_pending`, `insecure_url`, `disk_quota`

**Files:**
- Create: `includes/Alerts/NetworkState.php`, `includes/Alerts/Rules/MissingThemeRule.php`, `includes/Alerts/Rules/UpdatesPendingRule.php`, `includes/Alerts/Rules/InsecureUrlRule.php`, `includes/Alerts/Rules/DiskQuotaRule.php`
- Modify: `includes/Alerts/RuleRegistry.php`, `includes/Plugin.php`
- Test: `tests/php/Alerts/NetworkStateTest.php` (créé), `tests/php/Alerts/RulesTest.php`, `tests/php/Alerts/AlertEvaluatorTest.php`

**Interfaces:**
- Consumes : `InventoryList::updates( string $transient ): array<string, string>` (M3 : clé → nouvelle version, lue dans `update_plugins` ou `update_themes`) ; `SiteRecord::$data['plugins_local']` (fichiers des plugins activés sur le site) ; `$data['options']['upload_space_mb']` et `$disk_bytes` (tâches 1 et 2).
- Produces :
  - `Alerts\NetworkState` : `installed_themes(): array<string, true>`, `plugin_updates(): array<string, string>`, `theme_updates(): array<string, string>`, `uses_https(): bool`, `quotas_enabled(): bool`, `default_quota_mb(): int`, `signature(): string`, `reset(): void` ;
  - `RuleRegistry::create_default( ?NetworkState $state = null ): self`, ordre final : `no_users`, `inactive`, `high_media`, `no_admin`, `missing_theme`, `updates_pending`, `insecure_url`, `disk_quota`, `heavy_autoload`, `search_hidden`, `cron_overdue` (ordre du §4.2) ;
  - `Plugin::network_state(): NetworkState`, partagé par les règles et vidé par `Plugin::reset_caches()` ;
  - arguments stockés : `missing_theme` → `{ theme: string, missing: 'theme'|'parent' }` ; `updates_pending` → `{ plugins: int, themes: int }` ; `insecure_url` → `{ url: string }` ; `disk_quota` → `{ used_mb: int, quota_mb: int }`.

Les sites créés par la fabrique de tests ont le thème `default` (`WP_DEFAULT_THEME` de `tests/php/wp-tests-config.php`), qui n'est pas installé : une fois analysés, ils lèvent `missing_theme`. Un test existant qui vérifie les alertes d'un site analysé ajuste son attente ou le thème de sa fixture, jamais la règle.

- [ ] **Step 1: Write the failing tests of `NetworkState`**

Créer `tests/php/Alerts/NetworkStateTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Tests\TestCase;

final class NetworkStateTest extends TestCase {

	private static function plugin_update( string $file, string $version, int $checked = 1 ): object {
		return (object) [
			'last_checked' => $checked,
			'response'     => [ $file => (object) [ 'new_version' => $version ] ],
		];
	}

	public function test_reads_the_installed_themes_and_the_pending_updates(): void {
		set_site_transient( 'update_plugins', self::plugin_update( 'akismet/akismet.php', '9.0' ) );
		set_site_transient( 'update_themes', (object) [ 'response' => [ 'twentytwentyfive' => [ 'new_version' => '9.0' ] ] ] );
		$state = new NetworkState();

		$this->assertArrayHasKey( 'twentytwentyfive', $state->installed_themes() );
		$this->assertSame( [ 'akismet/akismet.php' => '9.0' ], $state->plugin_updates() );
		$this->assertSame( [ 'twentytwentyfive' => '9.0' ], $state->theme_updates() );
	}

	public function test_values_are_read_once_per_pass_until_reset(): void {
		delete_site_transient( 'update_plugins' );
		$state = new NetworkState();
		$this->assertSame( [], $state->plugin_updates() );

		set_site_transient( 'update_plugins', self::plugin_update( 'akismet/akismet.php', '9.0' ) );
		$this->assertSame( [], $state->plugin_updates(), 'Read once per pass.' );

		$state->reset();
		$this->assertSame( [ 'akismet/akismet.php' => '9.0' ], $state->plugin_updates() );
	}

	public function test_the_network_uses_https_when_its_main_site_does(): void {
		$state = new NetworkState();
		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->assertFalse( $state->uses_https() );

		update_blog_option( get_main_site_id(), 'home', 'https://example.org' );
		$state->reset();
		$this->assertTrue( $state->uses_https() );
	}

	public function test_upload_quotas_follow_the_network_settings(): void {
		$state = new NetworkState();

		update_site_option( 'upload_space_check_disabled', 1 );
		$this->assertFalse( $state->quotas_enabled() );
		update_site_option( 'upload_space_check_disabled', 0 );
		$this->assertTrue( $state->quotas_enabled() );

		update_site_option( 'blog_upload_space', 250 );
		$this->assertSame( 250, $state->default_quota_mb() );
		delete_site_option( 'blog_upload_space' );
		$this->assertSame( 100, $state->default_quota_mb(), 'The default of WordPress.' );
	}

	public function test_the_signature_ignores_the_date_of_the_update_check(): void {
		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.0', 1 ) );
		$state = new NetworkState();
		$first = $state->signature();

		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.0', 2 ) );
		$state->reset();
		$this->assertSame( $first, $state->signature() );

		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.1', 3 ) );
		$state->reset();
		$this->assertNotSame( $first, $state->signature() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter NetworkStateTest`
Expected: FAIL, `Class "MultisiteRadar\Alerts\NetworkState" not found`.

- [ ] **Step 3: Write `NetworkState`**

Créer `includes/Alerts/NetworkState.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Query\InventoryList;

defined( 'ABSPATH' ) || exit;

/**
 * État du réseau courant dont dépendent certaines règles : thèmes installés, mises à jour disponibles, quotas d'envoi,
 * https du site principal. C'est le « contexte de l'évaluateur » de la spec (§4.1) : lu une fois par passe, jamais
 * site par site, et sans appel externe (écart E4 du plan M4).
 */
final class NetworkState {

	/**
	 * Quota par défaut de get_space_allowed(), en Mo.
	 */
	private const DEFAULT_QUOTA_MB = 100;

	/**
	 * @var array<string, true>|null
	 */
	private ?array $themes = null;

	/**
	 * @var array<string, string>|null
	 */
	private ?array $plugin_updates = null;

	/**
	 * @var array<string, string>|null
	 */
	private ?array $theme_updates = null;

	private ?bool $https = null;

	public function reset(): void {
		$this->themes         = null;
		$this->plugin_updates = null;
		$this->theme_updates  = null;
		$this->https          = null;
	}

	/**
	 * Dossiers des thèmes installés, y compris ceux en erreur (leur dossier existe ; un parent manquant est signalé
	 * à part).
	 *
	 * @return array<string, true>
	 */
	public function installed_themes(): array {
		if ( null === $this->themes ) {
			$this->themes = array_fill_keys( array_map( 'strval', array_keys( wp_get_themes( [ 'errors' => null ] ) ) ), true );
		}
		return $this->themes;
	}

	/**
	 * @return array<string, string> Fichier du plugin => nouvelle version.
	 */
	public function plugin_updates(): array {
		return $this->plugin_updates ??= InventoryList::updates( 'update_plugins' );
	}

	/**
	 * @return array<string, string> Dossier du thème => nouvelle version.
	 */
	public function theme_updates(): array {
		return $this->theme_updates ??= InventoryList::updates( 'update_themes' );
	}

	/**
	 * Le réseau est en https si l'adresse de son site principal l'est (écart E12 du plan M4).
	 */
	public function uses_https(): bool {
		if ( null === $this->https ) {
			$home        = (string) get_blog_option( get_main_site_id(), 'home' );
			$this->https = 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
		}
		return $this->https;
	}

	/**
	 * Comme is_upload_space_available() du cœur : l'option upload_space_check_disabled coupe les quotas.
	 */
	public function quotas_enabled(): bool {
		return ! get_site_option( 'upload_space_check_disabled' );
	}

	/**
	 * Quota du réseau en Mo, comme get_space_allowed() du cœur, sans son filtre (écart E11 du plan M4).
	 */
	public function default_quota_mb(): int {
		$value = get_site_option( 'blog_upload_space' );
		return is_numeric( $value ) ? (int) $value : self::DEFAULT_QUOTA_MB;
	}

	/**
	 * Empreinte de tout ce qui précède. Quand elle change, les alertes du réseau doivent être recalculées.
	 * La date de la dernière vérification des mises à jour n'en fait pas partie.
	 */
	public function signature(): string {
		$plugins    = $this->plugin_updates();
		$themes     = $this->theme_updates();
		$theme_dirs = array_keys( $this->installed_themes() );
		ksort( $plugins );
		ksort( $themes );
		sort( $theme_dirs );
		return md5( (string) wp_json_encode( [ $plugins, $themes, $theme_dirs, $this->uses_https(), $this->quotas_enabled(), $this->default_quota_mb() ] ) );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bin/test.sh --filter NetworkStateTest`
Expected: PASS.

- [ ] **Step 5: Write the failing rule tests**

Dans `tests/php/Alerts/RulesTest.php`, ajouter les imports :

```php
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\Rules\DiskQuotaRule;
use MultisiteRadar\Alerts\Rules\InsecureUrlRule;
use MultisiteRadar\Alerts\Rules\MissingThemeRule;
use MultisiteRadar\Alerts\Rules\UpdatesPendingRule;
```

Dans `test_the_default_rules_follow_the_order_of_the_spec()`, la liste attendue devient :

```php
			[ 'no_users', 'inactive', 'high_media', 'no_admin', 'missing_theme', 'updates_pending', 'insecure_url', 'disk_quota', 'heavy_autoload', 'search_hidden', 'cron_overdue' ],
```

Ajouter :

```php
	public function test_missing_theme_checks_the_active_theme_then_its_parent(): void {
		$rule = new MissingThemeRule( new NetworkState() );

		// twentytwentyfive est livré avec WordPress 6.9, 7.1 et trunk : installé localement comme en CI.
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'twentytwentyfive', 'theme_template' => 'twentytwentyfive' ] ), [], time() ) );
		$gone = $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'msradar-gone', 'theme_template' => 'msradar-gone' ] ), [], time() );
		$this->assertSame( [ 'theme' => 'msradar-gone', 'missing' => 'theme' ], $gone->args );
		$orphan = $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'twentytwentyfive', 'theme_template' => 'msradar-gone-parent' ] ), [], time() );
		$this->assertSame( [ 'theme' => 'msradar-gone-parent', 'missing' => 'parent' ], $orphan->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => '' ] ), [], time() ), 'No theme read: nothing to say.' );
		$this->assertSame( 'The active theme "msradar-gone" is not installed.', $rule->message( $gone->args ) );
		$this->assertSame( 'The parent theme "msradar-gone-parent" of the active theme is not installed.', $rule->message( $orphan->args ) );
	}

	public function test_updates_pending_counts_the_site_plugins_and_its_themes_only(): void {
		set_site_transient(
			'update_plugins',
			(object) [
				'response' => [
					'akismet/akismet.php'  => (object) [ 'new_version' => '9.0' ],
					'netwide/netwide.php'  => (object) [ 'new_version' => '2.0' ],
				],
			]
		);
		set_site_transient( 'update_themes', (object) [ 'response' => [ 'msradar-parent' => [ 'new_version' => '2.0' ] ] ] );
		$rule = new UpdatesPendingRule( new NetworkState() );
		$site = fn ( array $plugins, string $stylesheet, string $template ): SiteRecord => $this->build_record(
			[
				'theme_stylesheet' => $stylesheet,
				'theme_template'   => $template,
				'data'             => [ 'plugins_local' => $plugins ],
			]
		);

		$alert = $rule->evaluate( $site( [ 'akismet/akismet.php', 'hello.php' ], 'msradar-child', 'msradar-parent' ), [], time() );
		$this->assertSame( [ 'plugins' => 1, 'themes' => 1 ], $alert->args );
		$this->assertNull( $rule->evaluate( $site( [ 'hello.php' ], 'msradar-child', 'msradar-child' ), [], time() ), 'netwide/netwide.php is activated on the network, not in plugins_local: it does not count.' );
		$this->assertSame( 'Updates available for 1 plugin and 1 theme', $rule->message( [ 'plugins' => 1, 'themes' => 1 ] ) );
		$this->assertSame( 'Updates available for 3 plugins', $rule->message( [ 'plugins' => 3, 'themes' => 0 ] ) );
		$this->assertSame( 'Updates available for 2 themes', $rule->message( [ 'plugins' => 0, 'themes' => 2 ] ) );
	}

	public function test_insecure_url_only_on_an_https_network(): void {
		$state = new NetworkState();
		$rule  = new InsecureUrlRule( $state );
		$http  = $this->build_record( [ 'url' => 'http://example.org/rh/', 'siteurl' => 'http://example.org/rh' ] );

		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->assertNull( $rule->evaluate( $http, [], time() ), 'The network itself is in http.' );

		update_blog_option( get_main_site_id(), 'home', 'https://example.org' );
		$state->reset();
		$this->assertSame( [ 'url' => 'http://example.org/rh/' ], $rule->evaluate( $http, [], time() )->args );
		$mixed = $this->build_record( [ 'url' => 'https://example.org/rh/', 'siteurl' => 'http://example.org/rh' ] );
		$this->assertSame( [ 'url' => 'http://example.org/rh' ], $rule->evaluate( $mixed, [], time() )->args, 'The WordPress address counts too.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'url' => 'https://example.org/rh/', 'siteurl' => 'https://example.org/rh' ] ), [], time() ) );
		$this->assertSame( 'The address http://example.org/rh/ uses http while the network uses https.', $rule->message( [ 'url' => 'http://example.org/rh/' ] ) );
	}

	public function test_disk_quota_uses_the_site_quota_then_the_network_one(): void {
		update_site_option( 'upload_space_check_disabled', 0 );
		update_site_option( 'blog_upload_space', 10 );
		$rule = new DiskQuotaRule( new NetworkState() );
		$site = fn ( ?int $bytes, array $data = [] ): SiteRecord => $this->build_record(
			[
				'disk_bytes' => $bytes,
				'data'       => $data,
			]
		);

		$alert = $rule->evaluate( $site( 9 * MB_IN_BYTES ), [ 'percent' => 90 ], time() );
		$this->assertSame( [ 'used_mb' => 9, 'quota_mb' => 10 ], $alert->args );
		$this->assertNull( $rule->evaluate( $site( 8 * MB_IN_BYTES ), [ 'percent' => 90 ], time() ) );
		$this->assertNull( $rule->evaluate( $site( 9 * MB_IN_BYTES, [ 'options' => [ 'upload_space_mb' => 100 ] ] ), [ 'percent' => 90 ], time() ), 'The own quota of the site wins.' );
		$this->assertNull( $rule->evaluate( $site( null ), [ 'percent' => 90 ], time() ), 'Disk not measured: no alert.' );
		$this->assertSame( '9 MB used of the 10 MB upload quota', $rule->message( $alert->args ) );

		update_site_option( 'upload_space_check_disabled', 1 );
		$this->assertNull( $rule->evaluate( $site( 50 * MB_IN_BYTES ), [ 'percent' => 90 ], time() ), 'Quotas are disabled on the network.' );
	}
```

Dans `tests/php/Alerts/AlertEvaluatorTest.php`, ajouter le test d'intégration suivant (l'évaluateur du plugin partage l'état du réseau avec les règles) :

```php
	public function test_rules_read_the_network_state_again_after_a_reset(): void {
		set_site_transient( 'update_plugins', (object) [ 'response' => [ 'akismet/akismet.php' => (object) [ 'new_version' => '9.0' ] ] ] );
		$this->plugin()->reset_caches();
		$record = $this->build_record(
			[
				'users_count'  => 1,
				'admins_count' => 1,
				'data'         => [ 'plugins_local' => [ 'akismet/akismet.php' ] ],
			]
		);

		$this->plugin()->evaluator()->apply( $record, time() );
		$this->assertSame( ',updates_pending,', $record->alert_rules );

		delete_site_transient( 'update_plugins' );
		$this->plugin()->reset_caches();
		$this->plugin()->evaluator()->apply( $record, time() );
		$this->assertSame( '', $record->alert_rules );
	}
```

- [ ] **Step 6: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'RulesTest|AlertEvaluatorTest'`
Expected: FAIL, `Class "MultisiteRadar\Alerts\Rules\MissingThemeRule" not found`.

- [ ] **Step 7: Write the four rules**

`includes/Alerts/Rules/MissingThemeRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class MissingThemeRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'missing_theme';
	}

	public function label(): string {
		return __( 'Missing active theme', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The active theme of the site, or its parent theme, is not installed on the network.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::ERROR;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || '' === $site->theme_stylesheet ) {
			return null;
		}
		$installed = $this->state->installed_themes();
		if ( ! isset( $installed[ $site->theme_stylesheet ] ) ) {
			return $this->alert( $site->theme_stylesheet, 'theme' );
		}
		$template = $site->theme_template;
		if ( '' !== $template && $template !== $site->theme_stylesheet && ! isset( $installed[ $template ] ) ) {
			return $this->alert( $template, 'parent' );
		}
		return null;
	}

	private function alert( string $theme, string $missing ): Alert {
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'theme'   => $theme,
				'missing' => $missing,
			]
		);
	}

	public function message( array $args ): string {
		$theme = (string) ( $args['theme'] ?? '' );
		if ( 'parent' === ( $args['missing'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: folder of the missing parent theme. */
				__( 'The parent theme "%s" of the active theme is not installed.', 'multisite-radar' ),
				$theme
			);
		}
		return sprintf(
			/* translators: %s: folder of the missing theme. */
			__( 'The active theme "%s" is not installed.', 'multisite-radar' ),
			$theme
		);
	}
}
```

`includes/Alerts/Rules/UpdatesPendingRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class UpdatesPendingRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'updates_pending';
	}

	public function label(): string {
		return __( 'Pending updates', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'A plugin activated on the site, its theme or its parent theme has an update available. Plugins activated on the whole network are not counted. Read from the update checks of WordPress, without any external request.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	/**
	 * Plugins activés sur le site seulement : une mise à jour d'un plugin réseau signalerait chaque site (écart E13).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at ) {
			return null;
		}
		$plugin_updates = $this->state->plugin_updates();
		$plugins        = 0;
		foreach ( (array) ( $site->data['plugins_local'] ?? [] ) as $file ) {
			if ( is_string( $file ) && isset( $plugin_updates[ $file ] ) ) {
				++$plugins;
			}
		}
		$theme_updates = $this->state->theme_updates();
		$themes        = 0;
		foreach ( array_unique( array_filter( [ $site->theme_stylesheet, $site->theme_template ] ) ) as $theme ) {
			if ( isset( $theme_updates[ $theme ] ) ) {
				++$themes;
			}
		}
		if ( 0 === $plugins + $themes ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'plugins' => $plugins,
				'themes'  => $themes,
			]
		);
	}

	public function message( array $args ): string {
		$parts   = [];
		$plugins = (int) ( $args['plugins'] ?? 0 );
		$themes  = (int) ( $args['themes'] ?? 0 );
		if ( $plugins > 0 ) {
			/* translators: %s: number of plugins. */
			$parts[] = sprintf( _n( '%s plugin', '%s plugins', $plugins, 'multisite-radar' ), number_format_i18n( $plugins ) );
		}
		if ( $themes > 0 ) {
			/* translators: %s: number of themes. */
			$parts[] = sprintf( _n( '%s theme', '%s themes', $themes, 'multisite-radar' ), number_format_i18n( $themes ) );
		}
		if ( 2 === count( $parts ) ) {
			/* translators: 1: number of plugins, such as "2 plugins", 2: number of themes, such as "1 theme". */
			return sprintf( __( 'Updates available for %1$s and %2$s', 'multisite-radar' ), $parts[0], $parts[1] );
		}
		/* translators: %s: number of plugins or themes, such as "2 plugins". */
		return sprintf( __( 'Updates available for %s', 'multisite-radar' ), (string) reset( $parts ) );
	}
}
```

`includes/Alerts/Rules/InsecureUrlRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class InsecureUrlRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'insecure_url';
	}

	public function label(): string {
		return __( 'Address in http on an https network', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The address of the site, or its WordPress address, starts with http:// while the main site of the network uses https://.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || ! $this->state->uses_https() ) {
			return null;
		}
		foreach ( [ $site->url, $site->siteurl ] as $url ) {
			if ( 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
				return new Alert( $this->id(), $this->default_severity(), [ 'url' => $url ] );
			}
		}
		return null;
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: %s: address of the site. */
			__( 'The address %s uses http while the network uses https.', 'multisite-radar' ),
			(string) ( $args['url'] ?? '' )
		);
	}
}
```

`includes/Alerts/Rules/DiskQuotaRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class DiskQuotaRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'disk_quota';
	}

	public function label(): string {
		return __( 'Upload quota almost reached', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The uploads of the site fill most of their space quota. Only when upload quotas are enabled in the network settings and the disk usage is measured.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'percent' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'title'       => __( 'Share of the quota', 'multisite-radar' ),
					'description' => __( 'Percentage of the upload quota that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'percent' => 90 ];
	}

	/**
	 * Quota propre au site s'il en a un, sinon celui du réseau (écart E11 du plan M4). Une mesure arrêtée par son
	 * budget est un minimum : si elle dépasse déjà le seuil, l'alerte est juste.
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || null === $site->disk_bytes || ! $this->state->quotas_enabled() ) {
			return null;
		}
		$own   = $site->data['options']['upload_space_mb'] ?? null;
		$quota = is_int( $own ) ? $own : $this->state->default_quota_mb();
		if ( $quota <= 0 || $site->disk_bytes * 100 < $quota * MB_IN_BYTES * (int) $params['percent'] ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'used_mb'  => (int) round( $site->disk_bytes / MB_IN_BYTES ),
				'quota_mb' => $quota,
			]
		);
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: 1: disk space used in megabytes, 2: upload quota in megabytes. */
			__( '%1$s MB used of the %2$s MB upload quota', 'multisite-radar' ),
			number_format_i18n( (int) ( $args['used_mb'] ?? 0 ) ),
			number_format_i18n( (int) ( $args['quota_mb'] ?? 0 ) )
		);
	}
}
```

- [ ] **Step 8: Register the rules and share the network state**

Dans `includes/Alerts/RuleRegistry.php`, ajouter les imports (`NetworkState` et les quatre règles, ordre alphabétique) et remplacer `create_default()` par :

```php
	/**
	 * @param NetworkState|null $state État du réseau partagé par les règles qui en dépendent (celui du plugin en
	 *                                 production, un état neuf sinon).
	 */
	public static function create_default( ?NetworkState $state = null ): self {
		$state = $state ?? new NetworkState();
		return new self(
			[
				new NoUsersRule(),
				new InactiveRule(),
				new HighMediaRule(),
				new NoAdminRule(),
				new MissingThemeRule( $state ),
				new UpdatesPendingRule( $state ),
				new InsecureUrlRule( $state ),
				new DiskQuotaRule( $state ),
				new HeavyAutoloadRule(),
				new SearchHiddenRule(),
				new CronOverdueRule(),
			]
		);
	}
```

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Alerts\NetworkState;` ;
- ajouter la propriété `private ?NetworkState $network_state = null;` (avant `$rules`) ;
- ajouter l'accesseur, avant `rules()` :

```php
	public function network_state(): NetworkState {
		return $this->network_state ??= new NetworkState();
	}
```

- `rules()` devient :

```php
	public function rules(): RuleRegistry {
		return $this->rules ??= RuleRegistry::create_default( $this->network_state() );
	}
```

- dans `reset_caches()`, ajouter :

```php
		if ( null !== $this->network_state ) {
			$this->network_state->reset();
		}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'RulesTest|AlertEvaluatorTest|NetworkStateTest'`
Expected: PASS.

- [ ] **Step 10: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus. Un autre test qui échoue parce qu'un site analysé lève maintenant `missing_theme` ou `updates_pending` (thème `default` non installé, transient de mise à jour posé par le test) s'adapte comme indiqué en tête de tâche, et le rapport le liste.

- [ ] **Step 11: Commit**

```bash
git add includes/Alerts/ includes/Plugin.php tests/php/
git commit -m "feat: add the missing_theme, updates_pending, insecure_url and disk_quota alert rules"
```

### Task 6: Recalcul des alertes quand l'état du réseau change

**Files:**
- Create: `includes/Scan/NetworkStateWatcher.php`
- Modify: `includes/Plugin.php`, `uninstall.php`
- Test: `tests/php/Scan/NetworkStateWatcherTest.php` (créé)

**Interfaces:**
- Consumes : `NetworkState::reset()` et `NetworkState::signature()` (tâche 5), `Plugin::network_state()`, `Queue::HOOK_RECOMPUTE`, `Queue::RECOMPUTE_CURSOR`, `MainSite::schedule_once()`.
- Produces :
  - `Scan\NetworkStateWatcher` : `register(): void`, `check(): void`, `on_deleted_transient( $transient ): void`, `on_home_changed(): void`, constante `OPTION = 'msradar_network_state'` (option réseau, supprimée par `uninstall.php`) ;
  - `Plugin::state_watcher(): NetworkStateWatcher`, enregistré par `boot()`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Scan/NetworkStateWatcherTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class NetworkStateWatcherTest extends TestCase {

	/**
	 * Le recalcul a déjà vu l'état actuel : plus rien n'est planifié.
	 */
	private function settle(): void {
		$this->plugin()->state_watcher()->check();
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
	}

	private function recompute_scheduled(): bool {
		return false !== wp_next_scheduled( Queue::HOOK_RECOMPUTE );
	}

	private static function updates( string $version, int $checked ): object {
		return (object) [
			'last_checked' => $checked,
			'response'     => [ 'akismet/akismet.php' => (object) [ 'new_version' => $version ] ],
		];
	}

	public function test_an_unchanged_state_schedules_nothing(): void {
		$this->settle();

		$this->plugin()->state_watcher()->check();

		$this->assertFalse( $this->recompute_scheduled() );
	}

	public function test_a_new_version_available_restarts_the_recompute_from_the_first_site(): void {
		$this->settle();
		update_site_option(
			Queue::RECOMPUTE_CURSOR,
			[
				'after'  => 5,
				'config' => 'x',
			]
		);

		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );

		$this->assertTrue( $this->recompute_scheduled() );
		$this->assertFalse( get_site_option( Queue::RECOMPUTE_CURSOR ) );
	}

	public function test_a_new_update_check_without_new_versions_schedules_nothing(): void {
		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );
		$this->settle();

		set_site_transient( 'update_plugins', self::updates( '9.0', 2 ) );

		$this->assertFalse( $this->recompute_scheduled() );
	}

	public function test_an_update_installed_restarts_the_recompute(): void {
		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );
		$this->settle();

		// WordPress efface la liste après une mise à jour (wp_clean_plugins_cache()), puis la reconstruit.
		delete_site_transient( 'update_plugins' );

		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_enabling_upload_quotas_restarts_the_recompute(): void {
		update_site_option( 'upload_space_check_disabled', 1 );
		$this->settle();

		update_site_option( 'upload_space_check_disabled', 0 );

		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_only_the_address_of_the_main_site_matters(): void {
		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->settle();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		update_option( 'home', 'https://example.org/other' );
		restore_current_blog();
		$this->assertFalse( $this->recompute_scheduled(), 'Another site moving to https changes nothing for the network.' );

		update_option( 'home', 'https://example.org' );
		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_nothing_happens_while_the_schema_is_outdated(): void {
		$this->settle();
		update_site_option( Schema::OPTION, 2 );

		set_site_transient( 'update_plugins', self::updates( '9.1', 1 ) );

		$this->assertFalse( $this->recompute_scheduled() );
	}
}
```

Le test « only the address of the main site » tourne sur le site principal (contexte par défaut des tests) : `update_option( 'home', … )` y vise bien le site principal.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter NetworkStateWatcherTest`
Expected: FAIL, `Call to undefined method MultisiteRadar\Plugin::state_watcher()`.

- [ ] **Step 3: Write the watcher**

Créer `includes/Scan/NetworkStateWatcher.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Relance le recalcul des alertes quand l'état du réseau lu par les règles change (écart E7 du plan M4) : mises à
 * jour disponibles, thèmes installés, quotas d'envoi, adresse du site principal. Sans lui, l'alerte « mises à jour
 * en attente » survivrait jusqu'au lendemain à la mise à jour de l'extension.
 *
 * Chaque gestionnaire coûte la lecture de l'état (transients et options du réseau, liste des thèmes en cache) ; le
 * recalcul lui-même tourne en cron, par lots bornés.
 */
final class NetworkStateWatcher {

	/**
	 * Option réseau : empreinte de l'état avec lequel le dernier recalcul a été demandé.
	 */
	public const OPTION = 'msradar_network_state';

	private const UPDATE_TRANSIENTS = [ 'update_plugins', 'update_themes' ];
	private const QUOTA_OPTIONS     = [ 'upload_space_check_disabled', 'blog_upload_space' ];

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function register(): void {
		foreach ( self::UPDATE_TRANSIENTS as $transient ) {
			add_action( 'set_site_transient_' . $transient, [ $this, 'check' ] );
		}
		add_action( 'deleted_site_transient', [ $this, 'on_deleted_transient' ] );
		add_action( 'deleted_theme', [ $this, 'check' ] );
		add_action( 'upgrader_process_complete', [ $this, 'check' ] );
		foreach ( self::QUOTA_OPTIONS as $option ) {
			add_action( 'add_site_option_' . $option, [ $this, 'check' ] );
			add_action( 'update_site_option_' . $option, [ $this, 'check' ] );
			add_action( 'delete_site_option_' . $option, [ $this, 'check' ] );
		}
		add_action( 'update_option_home', [ $this, 'on_home_changed' ] );
	}

	/**
	 * @param string $transient Nom du transient réseau supprimé.
	 */
	public function on_deleted_transient( $transient ): void {
		if ( in_array( $transient, self::UPDATE_TRANSIENTS, true ) ) {
			$this->check();
		}
	}

	/**
	 * Seule l'adresse du site principal dit si le réseau est en https.
	 */
	public function on_home_changed(): void {
		if ( is_main_site() ) {
			$this->check();
		}
	}

	/**
	 * Compare l'état du réseau courant à celui du dernier recalcul demandé ; s'il a changé, le recalcul repart du
	 * premier site.
	 */
	public function check(): void {
		if ( ! Schema::is_current() ) {
			return;
		}
		$this->state->reset();
		$signature = $this->state->signature();
		if ( get_site_option( self::OPTION, '' ) === $signature ) {
			return;
		}
		update_site_option( self::OPTION, $signature );
		delete_site_option( Queue::RECOMPUTE_CURSOR );
		MainSite::schedule_once( Queue::HOOK_RECOMPUTE );
	}
}
```

- [ ] **Step 4: Wire it in the plugin and the uninstaller**

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Scan\NetworkStateWatcher;` ;
- ajouter la propriété `private ?NetworkStateWatcher $state_watcher = null;` (après `$invalidation`) ;
- dans `boot()`, après `$this->invalidation()->register();`, ajouter `$this->state_watcher()->register();` ;
- ajouter l'accesseur, après `invalidation()` :

```php
	public function state_watcher(): NetworkStateWatcher {
		return $this->state_watcher ??= new NetworkStateWatcher( $this->network_state() );
	}
```

Dans `uninstall.php`, ajouter `'msradar_network_state'` à la fin de la liste `$msradar_network_options`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `bin/test.sh --filter NetworkStateWatcherTest`
Expected: PASS.

- [ ] **Step 6: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus. Les tests qui posent un transient de mise à jour (`PluginsQueryTest`, `ThemesQueryTest`…) déclenchent maintenant le gestionnaire : ils planifient un recalcul, sans effet sur leurs assertions.

- [ ] **Step 7: Commit**

```bash
git add includes/Scan/NetworkStateWatcher.php includes/Plugin.php uninstall.php tests/php/Scan/NetworkStateWatcherTest.php
git commit -m "feat: recompute the alerts when updates, themes, quotas or the main site address change"
```

### Task 7: `GET /alert-rules`, préchargé sur la page Réglages

**Files:**
- Create: `includes/Rest/AlertRulesController.php`
- Modify: `includes/Plugin.php`, `includes/Admin/Preload.php`, `includes/Alerts/Rules/InactiveRule.php`, `includes/Alerts/Rules/HighMediaRule.php`
- Test: `tests/php/Rest/AlertRulesControllerTest.php` (créé), `tests/php/Admin/PreloadTest.php`, `tests/php/Alerts/RulesTest.php`

**Interfaces:**
- Consumes : `RuleRegistry::all()`, `Controller::can_view()`.
- Produces :
  - `GET /multisite-radar/v1/alert-rules` → liste, dans l'ordre du registre, d'objets `{ id: string, label: string, description: string, default_severity: string, default_params: object, params_schema: object }`. Un objet vide est encodé `{}`, jamais `[]` ;
  - `Preload::paths( 'settings', … )` → `[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings', '/multisite-radar/v1/alert-rules' ]` ;
  - chaque paramètre des règles livrées a un `title` : `months` → « Months without activity », `threshold` → « Number of media files ».

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Rest/AlertRulesControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class AlertRulesControllerTest extends RestTestCase {

	public function test_requires_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alert-rules' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/alert-rules' )->get_status() );
	}

	public function test_lists_every_rule_with_its_defaults_and_its_parameter_schema(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/alert-rules' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertSame( array_keys( $this->plugin()->rules()->all() ), $ids );
		$inactive = $data[ (int) array_search( 'inactive', $ids, true ) ];
		$this->assertSame( [ 'id', 'label', 'description', 'default_severity', 'default_params', 'params_schema' ], array_keys( $inactive ) );
		$this->assertSame( 'Inactive site', $inactive['label'] );
		$this->assertSame( 'warning', $inactive['default_severity'] );
		$this->assertSame( [ 'months' => 6 ], $inactive['default_params'] );
		$this->assertSame( 120, $inactive['params_schema']['properties']['months']['maximum'] );
		$this->assertSame( 'Months without activity', $inactive['params_schema']['properties']['months']['title'] );
	}

	public function test_rules_without_parameters_are_described_by_empty_objects(): void {
		$this->login_as_super_admin();

		$json = (string) wp_json_encode( $this->request( 'GET', '/alert-rules' )->get_data() );

		$this->assertStringContainsString( '"id":"no_users"', $json );
		$this->assertStringContainsString( '"default_params":{},"params_schema":{"type":"object","additionalProperties":false,"properties":{}}', $json );
		$this->assertStringNotContainsString( '"default_params":[]', $json );
	}
}
```

Dans `tests/php/Admin/PreloadTest.php`, l'attente du cas `settings` devient :

```php
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings', '/multisite-radar/v1/alert-rules' ], Preload::paths( 'settings', [], $prefs ) );
```

Dans `tests/php/Alerts/RulesTest.php`, ajouter :

```php
	public function test_every_parameter_has_a_title_and_a_description(): void {
		foreach ( RuleRegistry::create_default()->all() as $id => $rule ) {
			foreach ( $rule->params_schema()['properties'] as $key => $schema ) {
				$this->assertNotSame( '', $schema['title'] ?? '', "$id.$key" );
				$this->assertNotSame( '', $schema['description'] ?? '', "$id.$key" );
			}
		}
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'AlertRulesControllerTest|PreloadTest|RulesTest'`
Expected: FAIL (route absente : 404 ; chemin non préchargé ; titres manquants pour `inactive` et `high_media`).

- [ ] **Step 3: Add the titles of the M1 parameters**

Dans `includes/Alerts/Rules/InactiveRule.php`, la propriété `months` du schéma gagne, avant `description` :

```php
					'title'       => __( 'Months without activity', 'multisite-radar' ),
```

Dans `includes/Alerts/Rules/HighMediaRule.php`, la propriété `threshold` gagne, avant `description` :

```php
					'title'       => __( 'Number of media files', 'multisite-radar' ),
```

- [ ] **Step 4: Write the controller**

Créer `includes/Rest/AlertRulesController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /alert-rules : définitions des règles d'alertes, dont la page Réglages construit son formulaire (spec §5.1).
 * Les réglages effectifs de chaque règle restent dans GET /settings (écart E10 du plan M4).
 */
final class AlertRulesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'alert-rules';

	private RuleRegistry $rules;

	public function __construct( RuleRegistry $rules ) {
		$this->rules = $rules;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$items = [];
		foreach ( $this->rules->all() as $rule ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $rule, $request ) );
		}
		return new WP_REST_Response( $items );
	}

	/**
	 * @param RuleInterface   $item    Règle.
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$schema = $item->params_schema();
		if ( isset( $schema['properties'] ) && [] === $schema['properties'] ) {
			$schema['properties'] = new \stdClass(); // {} en JSON, comme tout objet du schéma.
		}
		$params = $item->default_params();

		return new WP_REST_Response(
			[
				'id'               => $item->id(),
				'label'            => $item->label(),
				'description'      => $item->description(),
				'default_severity' => $item->default_severity(),
				'default_params'   => [] === $params ? new \stdClass() : $params,
				'params_schema'    => $schema,
			]
		);
	}

	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$readonly     = static fn ( array $schema ): array => array_merge( $schema, [ 'readonly' => true ] );
		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'msradar-alert-rule',
			'type'       => 'object',
			'properties' => [
				'id'               => $readonly( [ 'type' => 'string' ] ),
				'label'            => $readonly( [ 'type' => 'string' ] ),
				'description'      => $readonly( [ 'type' => 'string' ] ),
				'default_severity' => $readonly(
					[
						'type' => 'string',
						'enum' => [ 'error', 'warning', 'info' ],
					]
				),
				'default_params'   => $readonly( [ 'type' => 'object' ] ),
				'params_schema'    => $readonly( [ 'type' => 'object' ] ),
			],
		];
		return $this->add_additional_fields_schema( $this->schema );
	}
}
```

Si `composer analyse` signale une incompatibilité de signature avec `WP_REST_Controller` (paramètres non typés de `get_items()` et `prepare_item_for_response()`), garder ces paramètres non typés, comme dans `AlertsController::get_items()`.

- [ ] **Step 5: Register the route, preload it and tidy the imports**

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Rest\AlertRulesController;` ;
- remettre les `use MultisiteRadar\Rest\…` dans l'ordre alphabétique : `AlertRulesController`, `AlertsController`, `InventoryController`, `PluginsController`, `PreferencesController`, `ScanController`, `SettingsController`, `SitesController`, `ThemesController`, `UsersController` (point reporté de M3 : `ThemesController` était avant `SettingsController`) ;
- dans `register_rest_routes()`, ajouter `new AlertRulesController( $this->rules() ),` juste après `new AlertsController( $this->alerts_query() ),`.

Dans `includes/Admin/Preload.php`, le cas `settings` devient :

```php
			case 'settings':
				$paths[] = ViewQuery::path( '/settings' );
				$paths[] = ViewQuery::path( '/alert-rules' );
				break;
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'AlertRulesControllerTest|PreloadTest|RulesTest'`
Expected: PASS.

- [ ] **Step 7: Run the whole PHP suite, lint and static analysis**

Run: `bin/test.sh && composer lint && composer analyse`
Expected : seuls les 11 échecs connus.

- [ ] **Step 8: Commit**

```bash
git add includes/Rest/AlertRulesController.php includes/Plugin.php includes/Admin/Preload.php includes/Alerts/Rules/InactiveRule.php includes/Alerts/Rules/HighMediaRule.php tests/php/
git commit -m "feat: describe the alert rules over REST and preload them on the settings page"
```

## Partie C — Interface

### Task 8: Mesures dans la liste des sites, la fiche et l'export

**Files:**
- Modify: `includes/Query/SitesQuery.php`, `includes/Rest/SitesController.php`, `includes/Export/SitesColumns.php`
- Modify: `src/utils/format.js`, `src/views/sites/fields.jsx`, `src/views/sites/export.js`, `src/views/site-panel/summary-tab.jsx`
- Test: `tests/php/Query/SitesQueryTest.php`, `src/utils/test/format.test.js`, `src/views/sites/test/export.test.js`, `src/views/site-panel/test/site-panel.test.jsx`

**Interfaces:**
- Consumes : `SiteRecord::$disk_is_estimate`, `$data['cron']` (tâches 1 et 2).
- Produces :
  - résumé REST d'un site (listes, fiche, export) : clé `disk_is_estimate` (`bool`), juste après `disk_bytes` ; déclarée dans le schéma de `SitesController` ;
  - fiche REST (`GET /sites/{id}`) : clé `cron` = `{ overdue_count: int, oldest_overdue_gmt: string|null }` (date au format REST `*_gmt`), ou `null` pour une ligne analysée avant la 2.0.0-beta.4 ;
  - colonne d'export `disk_is_estimate` (« Disk usage is an estimate »), ajoutée à l'export dès que la colonne Disque est visible ;
  - `formatDisk( bytes, estimate = false ): string` dans `src/utils/format.js`.

- [ ] **Step 1: Write the failing PHP test**

Ajouter à `tests/php/Query/SitesQueryTest.php` :

```php
	public function test_exposes_the_disk_estimate_and_the_overdue_tasks(): void {
		$this->make_record(
			191,
			[
				'name'             => 'Measured',
				'disk_bytes'       => 2048,
				'disk_is_estimate' => true,
				'scanned_at'       => '2026-09-01 00:00:00',
				'data'             => [
					'cron' => [
						'overdue_count'      => 2,
						'oldest_overdue_gmt' => '2026-08-31 06:00:00',
					],
				],
			]
		);
		$this->make_record( 192, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$query = $this->plugin()->sites_query();

		$item = $query->list( [ 'include' => [ 191 ] ] )['items'][0];
		$this->assertSame( 2048, $item['disk_bytes'] );
		$this->assertTrue( $item['disk_is_estimate'] );
		$this->assertSame(
			[
				'overdue_count'      => 2,
				'oldest_overdue_gmt' => '2026-08-31T06:00:00',
			],
			$query->get( 191 )['cron']
		);
		$this->assertNull( $query->get( 192 )['cron'], 'Analysed before 2.0.0-beta.4.' );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bin/test.sh --filter test_exposes_the_disk_estimate_and_the_overdue_tasks`
Expected: FAIL, `Undefined array key "disk_is_estimate"`.

- [ ] **Step 3: Expose the values**

Dans `includes/Query/SitesQuery.php` :

1. Dans `summary()`, après `'disk_bytes' => $record->disk_bytes,` :

```php
				'disk_is_estimate'  => $record->disk_is_estimate,
```

2. Dans `get()`, après la clé `'options'` :

```php
				'cron'         => self::cron( $data ),
```

3. Ajouter, après `date()` :

```php
	/**
	 * Tâches planifiées en retard lors de la dernière analyse ; null pour une ligne analysée avant la 2.0.0-beta.4.
	 *
	 * @return array{overdue_count: int, oldest_overdue_gmt: string|null}|null
	 */
	private static function cron( array $data ): ?array {
		$cron = $data['cron'] ?? null;
		if ( ! is_array( $cron ) ) {
			return null;
		}
		return [
			'overdue_count'      => (int) ( $cron['overdue_count'] ?? 0 ),
			'oldest_overdue_gmt' => self::date( (string) ( $cron['oldest_overdue_gmt'] ?? '' ) ),
		];
	}
```

Dans `includes/Rest/SitesController.php`, dans `get_item_schema()`, après `'disk_bytes' => $nullable_int,` :

```php
				'disk_is_estimate'  => [
					'type'     => 'boolean',
					'readonly' => true,
				],
```

Dans `includes/Export/SitesColumns.php`, dans `all()`, après `disk_bytes` :

```php
			'disk_is_estimate'  => __( 'Disk usage is an estimate', 'multisite-radar' ),
```

- [ ] **Step 4: Run the PHP tests to verify they pass**

Run: `bin/test.sh --filter 'SitesQueryTest|SitesControllerTest|ExportHandlerTest'`
Expected : PASS (si un test compare la liste exacte des colonnes d'export ou des clés du résumé, il gagne `disk_is_estimate` à sa place, après `disk_bytes`).

- [ ] **Step 5: Write the failing JS tests**

Dans `src/utils/test/format.test.js`, ajouter `formatDisk` à l'import et le test :

```js
test( 'a disk measure cut short by its time budget is a minimum', () => {
	expect( formatDisk( 2048 ) ).toBe( '2 KB' );
	expect( formatDisk( 2048, false ) ).toBe( '2 KB' );
	expect( formatDisk( 2048, true ) ).toBe( 'at least 2 KB' );
	expect( formatDisk( null, true ) ).toBe( '—' );
} );
```

Dans `src/views/sites/test/export.test.js`, ajouter :

```js
test( 'the disk column brings the estimate flag along', () => {
	expect( exportColumns( [ 'disk_bytes' ] ) ).toEqual( [
		'id',
		'name',
		'url',
		'disk_bytes',
		'disk_is_estimate',
	] );
} );
```

Dans `src/views/site-panel/test/site-panel.test.jsx`, ajouter :

```js
test( 'the summary shows the measures of the last analysis', () => {
	setup( {
		siteId: 15,
		preload: {
			'/multisite-radar/v1/sites/15': {
				body: {
					...DETAIL,
					id: 15,
					disk_bytes: 1048576,
					disk_is_estimate: true,
					db_bytes: 2097152,
					autoload_bytes: 10240,
					cron: {
						overdue_count: 3,
						oldest_overdue_gmt: '2026-08-31T06:00:00',
					},
				},
				headers: {},
			},
		},
	} );

	expect( screen.getByText( 'at least 1 MB' ) ).toBeInTheDocument();
	expect( screen.getByText( '2 MB' ) ).toBeInTheDocument();
	expect( screen.getByText( '10 KB' ) ).toBeInTheDocument();
	expect(
		screen.getByText( /^3 overdue at the last analysis/ )
	).toBeInTheDocument();
} );

test( 'a site analysed before the measures existed shows dashes, and no overdue task says so', () => {
	setup( {
		siteId: 16,
		preload: {
			'/multisite-radar/v1/sites/16': {
				body: {
					...DETAIL,
					id: 16,
					cron: { overdue_count: 0, oldest_overdue_gmt: null },
				},
				headers: {},
			},
		},
	} );

	expect( screen.getByText( 'None overdue' ) ).toBeInTheDocument();
	expect(
		within( screen.getByRole( 'dialog' ) ).getAllByText( '—' ).length
	).toBeGreaterThanOrEqual( 3 );
} );
```

- [ ] **Step 6: Run the JS tests to verify they fail**

Run: `npx vitest run src/utils src/views/sites src/views/site-panel`
Expected: FAIL (`formatDisk` absent, colonne `disk_is_estimate` absente, lignes de la fiche absentes).

- [ ] **Step 7: Write the JS changes**

Dans `src/utils/format.js`, ajouter après `formatBytes()` :

```js
/**
 * Espace disque d'un site. Une mesure arrêtée par son budget de temps est un minimum (écart E5 du plan M4).
 *
 * @param {?number} bytes    Octets mesurés.
 * @param {boolean} estimate Mesure partielle.
 */
export function formatDisk( bytes, estimate = false ) {
	const size = formatBytes( bytes );
	if ( ! estimate || bytes === null || bytes === undefined ) {
		return size;
	}
	/* translators: %s: disk size, such as "1.2 GB". */
	return sprintf( __( 'at least %s', 'multisite-radar' ), size );
}
```

Dans `src/views/sites/fields.jsx`, importer `formatDisk` depuis `../../utils/format` et remplacer le `render` du champ `disk_bytes` par :

```js
			render: ( { item } ) =>
				formatDisk( item.disk_bytes, item.disk_is_estimate ),
```

Dans `src/views/sites/export.js`, la ligne `disk_bytes` de `FIELD_COLUMNS` devient :

```js
	disk_bytes: [ 'disk_bytes', 'disk_is_estimate' ],
```

Dans `src/views/site-panel/summary-tab.jsx` :

1. Les imports de format deviennent :

```js
import {
	displayUrl,
	formatBytes,
	formatDateTime,
	formatDisk,
	formatNumber,
} from '../../utils/format';
```

2. Ajouter, avant le composant :

```js
/**
 * Tâches planifiées en retard lors de la dernière analyse.
 *
 * @param {?Object} cron { overdue_count, oldest_overdue_gmt }, null pour une ligne analysée avant la 2.0.0-beta.4.
 */
function cronSummary( cron ) {
	if ( ! cron ) {
		return '—';
	}
	if ( cron.overdue_count === 0 ) {
		return __( 'None overdue', 'multisite-radar' );
	}
	return sprintf(
		/* translators: 1: number of overdue scheduled tasks, 2: date the oldest one was due. */
		_n(
			'%1$s overdue at the last analysis, due since %2$s',
			'%1$s overdue at the last analysis, the oldest due since %2$s',
			cron.overdue_count,
			'multisite-radar'
		),
		formatNumber( cron.overdue_count ),
		formatDateTime( cron.oldest_overdue_gmt )
	);
}
```

3. Dans la liste `<dl>`, après la ligne Media (`<dd>{ formatNumber( site.media_count ) }</dd>`) :

```jsx
				<dt>{ __( 'Disk', 'multisite-radar' ) }</dt>
				<dd>{ formatDisk( site.disk_bytes, site.disk_is_estimate ) }</dd>
				<dt>{ __( 'Database', 'multisite-radar' ) }</dt>
				<dd>{ formatBytes( site.db_bytes ) }</dd>
				<dt>{ __( 'Autoloaded options', 'multisite-radar' ) }</dt>
				<dd>{ formatBytes( site.autoload_bytes ) }</dd>
				<dt>{ __( 'Scheduled tasks', 'multisite-radar' ) }</dt>
				<dd>{ cronSummary( site.cron ) }</dd>
```

- [ ] **Step 8: Run the JS tests and lint**

Run: `npx vitest run src/utils src/views/sites src/views/site-panel && npm run lint:js`
Expected : PASS ; lint sans nouvelle alerte (les 3 avertissements jsdoc connus restent).

- [ ] **Step 9: Run all the suites**

Run: `bin/test.sh && composer lint && composer analyse && npm run test:unit`
Expected : seuls les 11 échecs PHP connus ; tous les tests JS verts.

- [ ] **Step 10: Commit**

```bash
git add includes/Query/SitesQuery.php includes/Rest/SitesController.php includes/Export/SitesColumns.php src/utils/ src/views/sites/ src/views/site-panel/ tests/php/Query/SitesQueryTest.php
git commit -m "feat: show disk, database, autoload and overdue task measures in the sites list, the site panel and exports"
```

### Task 9: Réglages — mesure du disque et envoi des seules valeurs modifiées

**Files:**
- Modify: `src/views/settings/fields.js`, `src/views/settings/index.jsx`
- Test: `src/views/settings/test/settings-view.test.jsx`

**Interfaces:**
- Consumes : `POST /settings` (fusion récursive côté serveur : une clé absente du corps garde sa valeur enregistrée) ; `Queue::on_settings_updated()` marque tous les sites quand `scan.measure_disk` change (tâche 3).
- Produces :
  - champ `scan.measure_disk` (interrupteur) dans `SCAN_FORM`, entre `scan.analysis_plugins` et `scan.full_rescan_days` ;
  - `changes( saved, current ): Object` remplace `toPayload()` : le corps de `POST /settings` ne contient que les valeurs différentes des réglages enregistrés ; objet vide s'il n'y a rien à enregistrer. La tâche 10 lui ajoute un troisième paramètre, `rules`.

- [ ] **Step 1: Update and write the failing tests**

Dans `src/views/settings/test/settings-view.test.jsx` :

1. L'import `import { mergeDeep, toPayload } from '../fields';` devient `import { changes, mergeDeep } from '../fields';`.

2. Remplacer le test `helpers merge nested edits and send only the sections of this screen` par :

```js
test( 'only the values that differ from the saved settings are sent', () => {
	const current = mergeDeep( SETTINGS, {
		scan: { full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );

	expect( current.scan ).toEqual( { ...SETTINGS.scan, full_rescan_days: 14 } );
	expect( changes( SETTINGS, current ) ).toEqual( {
		scan: { full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );
	expect(
		changes(
			SETTINGS,
			mergeDeep( current, {
				scan: { full_rescan_days: 7 },
				sites_menu: { enabled: false },
			} )
		)
	).toEqual( {} );
} );
```

3. Dans `shows the preloaded settings and saves a change`, le corps attendu devient :

```js
		data: {
			sites_menu: { enabled: true },
		},
```

4. Dans `a stored plugin slug that is no longer installed does not block saving`, remplacer l'assertion finale par :

```js
	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		sites_menu: { enabled: true },
	} );
```

5. Ajouter :

```js
test( 'undoing a change leaves nothing to save', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const toggle = screen.getByRole( 'checkbox', {
		name: /network sites menu/,
	} );

	fireEvent.click( toggle );
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.click( toggle );

	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'the disk measure can be switched off', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		scan: { ...SETTINGS.scan, measure_disk: false },
	} );
	setup();
	const toggle = screen.getByRole( 'checkbox', {
		name: /Measure the disk space used by each site/,
	} );
	expect( toggle ).toBeChecked();

	fireEvent.click( toggle );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		scan: { measure_disk: false },
	} );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/views/settings`
Expected: FAIL (`changes` n'existe pas ; interrupteur absent ; corps complet envoyé).

- [ ] **Step 3: Add the disk field and `changes()`**

Dans `src/views/settings/fields.js` :

1. Après la constante `POST_TYPE_KEY`, ajouter :

```js
const SCAN_KEYS = [
	'activity_post_types',
	'analysis_plugins',
	'measure_disk',
	'full_rescan_days',
];
```

2. Dans `getSettingsFields()`, entre le champ `scan.analysis_plugins` et le champ `scan.full_rescan_days`, ajouter :

```js
		{
			id: 'scan.measure_disk',
			type: 'boolean',
			label: __(
				'Measure the disk space used by each site',
				'multisite-radar'
			),
			description: __(
				'Size of the uploads folder of each site. A measure that takes more than two seconds stops there and is shown as a minimum.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
```

3. `SCAN_FORM` devient :

```js
export const SCAN_FORM = {
	layout: { type: 'regular' },
	fields: [
		'scan.activity_post_types',
		'scan.analysis_plugins',
		'scan.measure_disk',
		'scan.full_rescan_days',
	],
};
```

4. Remplacer `toPayload()` et son docblock par :

```js
function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Corps de POST /settings : seules les valeurs différentes des réglages enregistrés (écart E14 du plan M4).
 * Revenir à la valeur de départ n'est donc plus une modification, et deux administrateurs qui changent des réglages
 * différents ne s'écrasent pas.
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function changes( saved, current ) {
	const patch = {};
	SCAN_KEYS.forEach( ( key ) => {
		if ( ! same( saved.scan?.[ key ], current.scan?.[ key ] ) ) {
			patch.scan = { ...patch.scan, [ key ]: current.scan?.[ key ] };
		}
	} );
	if ( !! saved.sites_menu?.enabled !== !! current.sites_menu?.enabled ) {
		patch.sites_menu = { enabled: !! current.sites_menu?.enabled };
	}
	return patch;
}
```

Dans `src/views/settings/index.jsx` :

1. Dans l'import depuis `./fields`, remplacer `toPayload` par `changes`.

2. Remplacer `const dirty = Object.keys( edits ).length > 0;` par (le nom `changed` évite de masquer le paramètre `patch` de `onChange`) :

```js
	const changed = useMemo(
		() => changes( settings.data || {}, data ),
		[ settings.data, data ]
	);
	const dirty = Object.keys( changed ).length > 0;
```

3. Dans `save()`, le corps envoyé devient `data: changed,` (au lieu de `data: toPayload( data ),`).

- [ ] **Step 4: Run the tests and lint**

Run: `npx vitest run src/views/settings && npm run lint:js`
Expected: PASS.

- [ ] **Step 5: Run all the JS tests**

Run: `npm run test:unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/views/settings/
git commit -m "feat: switch the disk measure from the settings and save only the changed values"
```

### Task 10: Réglages — section « Règles d'alertes » générée depuis `/alert-rules`

**Files:**
- Create: `src/views/settings/rule-fields.js`, `src/views/settings/rules-card.jsx`
- Modify: `src/views/settings/fields.js`, `src/views/settings/index.jsx`
- Test: `src/views/settings/test/rules.test.js` (créé), `src/views/settings/test/settings-view.test.jsx`

**Interfaces:**
- Consumes : `GET /alert-rules` (tâche 7, préchargé sur la page) ; `severityLabels()` de `src/components/badges.jsx` ; `changes( saved, current )` (tâche 9) ; `POST /settings` valide `params` de chaque règle contre son schéma et accepte `severity: null`.
- Produces :
  - `ruleConfig( settings, rule ): { enabled: boolean, severity: ?string, params: Object }` — réglage effectif d'une règle (valeurs enregistrées, sinon celles de la règle) ;
  - `getRuleFields( rule ): Field[]` — interrupteur `alerts.rules.<id>.enabled`, liste `alerts.rules.<id>.severity` (valeur `''` = « Default (…) » = `null` enregistré), puis un champ par paramètre de type `integer`, `number`, `boolean` ou `string` avec `enum` ; les autres types ne sont pas modifiables ;
  - `ruleForm( rule ): Form` ;
  - `changes( saved, current, rules = [] )` : une règle modifiée est envoyée entière, `{ enabled, severity, params }` ;
  - `allForm( rules ): Form` remplace la constante `ALL_FORM` ;
  - `RulesCard` : un panneau repliable par règle, titre « <libellé> (disabled) » quand la règle est désactivée.

- [ ] **Step 1: Write the failing helper tests**

Créer `src/views/settings/test/rules.test.js` :

```js
import { expect, test } from 'vitest';
import { changes } from '../fields';
import { getRuleFields, ruleConfig, ruleForm } from '../rule-fields';

const INACTIVE = {
	id: 'inactive',
	label: 'Inactive site',
	description: 'No content of the tracked types has been published or updated for a while.',
	default_severity: 'warning',
	default_params: { months: 6 },
	params_schema: {
		type: 'object',
		properties: {
			months: {
				type: 'integer',
				minimum: 1,
				maximum: 120,
				title: 'Months without activity',
				description: 'Months without activity before the alert is raised.',
			},
		},
	},
};
const NO_USERS = {
	id: 'no_users',
	label: 'Site without users',
	description: 'No user account is attached to the site.',
	default_severity: 'error',
	default_params: {},
	params_schema: { type: 'object', properties: {} },
};
const THIRD_PARTY = {
	id: 'acme_rule',
	label: 'Acme rule',
	description: 'A rule added by another plugin.',
	default_severity: 'info',
	default_params: { tags: [ 'a' ] },
	params_schema: { type: 'object', properties: { tags: { type: 'array' } } },
};

test( 'the effective configuration falls back on the defaults of the rule', () => {
	expect( ruleConfig( {}, INACTIVE ) ).toEqual( {
		enabled: true,
		severity: null,
		params: { months: 6 },
	} );
	expect(
		ruleConfig(
			{
				alerts: {
					rules: {
						inactive: {
							enabled: false,
							severity: 'error',
							params: { months: 9 },
						},
					},
				},
			},
			INACTIVE
		)
	).toEqual( { enabled: false, severity: 'error', params: { months: 9 } } );
	expect( ruleConfig( { alerts: { rules: [] } }, NO_USERS ) ).toEqual( {
		enabled: true,
		severity: null,
		params: {},
	} );
} );

test( 'fields: switch, severity with its default, then the parameters the form can edit', () => {
	expect( ruleForm( INACTIVE ).fields ).toEqual( [
		'alerts.rules.inactive.enabled',
		'alerts.rules.inactive.severity',
		'alerts.rules.inactive.params.months',
	] );
	expect( ruleForm( THIRD_PARTY ).fields ).toEqual( [
		'alerts.rules.acme_rule.enabled',
		'alerts.rules.acme_rule.severity',
	] );

	const [ enabled, severity, months ] = getRuleFields( INACTIVE );
	expect( enabled.setValue( { item: {}, value: false } ) ).toEqual( {
		alerts: { rules: { inactive: { enabled: false } } },
	} );
	expect( severity.elements[ 0 ] ).toEqual( {
		value: '',
		label: 'Default (Warning)',
	} );
	expect( severity.getValue( { item: {} } ) ).toBe( '' );
	expect( severity.setValue( { item: {}, value: '' } ) ).toEqual( {
		alerts: { rules: { inactive: { severity: null } } },
	} );
	expect( months.label ).toBe( 'Months without activity' );
	expect( months.isValid ).toEqual( { required: true, min: 1, max: 120 } );
	expect( months.getValue( { item: {} } ) ).toBe( 6 );
} );

test( 'a changed rule is sent whole; an unchanged one is not sent', () => {
	const saved = {
		scan: {},
		sites_menu: {},
		alerts: { rules: { acme_rule: { params: { tags: [ 'a', 'b' ] } } } },
	};
	const current = {
		...saved,
		alerts: {
			rules: {
				...saved.alerts.rules,
				inactive: { params: { months: 8 } },
			},
		},
	};

	expect( changes( saved, current, [ INACTIVE, NO_USERS, THIRD_PARTY ] ) ).toEqual( {
		alerts: {
			rules: {
				inactive: { enabled: true, severity: null, params: { months: 8 } },
			},
		},
	} );
	expect(
		changes(
			saved,
			{
				...saved,
				alerts: {
					rules: {
						acme_rule: {
							params: { tags: [ 'a', 'b' ] },
							severity: 'error',
						},
					},
				},
			},
			[ THIRD_PARTY ]
		)
	).toEqual( {
		alerts: {
			rules: {
				acme_rule: {
					enabled: true,
					severity: 'error',
					params: { tags: [ 'a', 'b' ] },
				},
			},
		},
	} );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/views/settings/test/rules.test.js`
Expected: FAIL, le module `../rule-fields` est introuvable.

- [ ] **Step 3: Write `rule-fields.js`**

Créer `src/views/settings/rule-fields.js` :

```js
import { __, sprintf } from '@wordpress/i18n';
import { severityLabels } from '../../components/badges';

const SEVERITIES = [ 'error', 'warning', 'info' ];

function isObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

/**
 * Réglage effectif d'une règle : valeurs enregistrées, sinon celles de la règle (GET /alert-rules). Comme en PHP,
 * severity null signifie « gravité par défaut de la règle ».
 *
 * @param {Object} settings Réglages (msradar_settings).
 * @param {Object} rule     Définition de la règle.
 * @return {{enabled: boolean, severity: ?string, params: Object}} Réglage de la règle.
 */
export function ruleConfig( settings, rule ) {
	const stored = settings?.alerts?.rules?.[ rule.id ];
	const config = isObject( stored ) ? stored : {};
	return {
		enabled: config.enabled ?? true,
		severity: config.severity ?? null,
		params: {
			...( isObject( rule.default_params ) ? rule.default_params : {} ),
			...( isObject( config.params ) ? config.params : {} ),
		},
	};
}

function change( rule, value ) {
	return { alerts: { rules: { [ rule.id ]: value } } };
}

/**
 * Champ d'un paramètre, d'après son schéma JSON. Un type que le formulaire ne sait pas modifier ne donne pas de
 * champ : le paramètre garde sa valeur enregistrée, renvoyée telle quelle avec la règle.
 *
 * @param {Object} rule   Définition de la règle.
 * @param {string} key    Nom du paramètre.
 * @param {Object} schema Schéma du paramètre.
 */
function paramField( rule, key, schema ) {
	const field = {
		id: `alerts.rules.${ rule.id }.params.${ key }`,
		label: schema.title || key,
		description: schema.description,
		getValue: ( { item } ) => ruleConfig( item, rule ).params[ key ],
		setValue: ( { value } ) => change( rule, { params: { [ key ]: value } } ),
	};
	if ( schema.type === 'integer' || schema.type === 'number' ) {
		const isValid = { required: true };
		if ( typeof schema.minimum === 'number' ) {
			isValid.min = schema.minimum;
		}
		if ( typeof schema.maximum === 'number' ) {
			isValid.max = schema.maximum;
		}
		return { ...field, type: schema.type, isValid };
	}
	if ( schema.type === 'boolean' ) {
		return { ...field, type: 'boolean', Edit: 'toggle' };
	}
	if ( schema.type === 'string' && Array.isArray( schema.enum ) ) {
		return {
			...field,
			type: 'text',
			elements: schema.enum.map( ( value ) => ( {
				value,
				label: String( value ),
			} ) ),
		};
	}
	return null;
}

/**
 * Champs DataForm d'une règle : activée, gravité, puis ses paramètres.
 *
 * @param {Object} rule Définition de la règle (GET /alert-rules).
 */
export function getRuleFields( rule ) {
	const severities = severityLabels();
	const properties = isObject( rule.params_schema?.properties )
		? rule.params_schema.properties
		: {};
	return [
		{
			id: `alerts.rules.${ rule.id }.enabled`,
			type: 'boolean',
			label: __( 'Enabled', 'multisite-radar' ),
			Edit: 'toggle',
			getValue: ( { item } ) => ruleConfig( item, rule ).enabled,
			setValue: ( { value } ) => change( rule, { enabled: !! value } ),
		},
		{
			id: `alerts.rules.${ rule.id }.severity`,
			type: 'text',
			label: __( 'Severity', 'multisite-radar' ),
			elements: [
				{
					value: '',
					label: sprintf(
						/* translators: %s: default severity of the rule, such as "Warning". */
						__( 'Default (%s)', 'multisite-radar' ),
						severities[ rule.default_severity ] ||
							rule.default_severity
					),
				},
				...SEVERITIES.map( ( value ) => ( {
					value,
					label: severities[ value ],
				} ) ),
			],
			getValue: ( { item } ) => ruleConfig( item, rule ).severity || '',
			setValue: ( { value } ) =>
				change( rule, { severity: value || null } ),
		},
		...Object.entries( properties )
			.map( ( [ key, schema ] ) =>
				paramField( rule, key, isObject( schema ) ? schema : {} )
			)
			.filter( Boolean ),
	];
}

export function ruleForm( rule ) {
	return {
		layout: { type: 'regular' },
		fields: getRuleFields( rule ).map( ( field ) => field.id ),
	};
}
```

- [ ] **Step 4: Extend `changes()` and the whole form**

Dans `src/views/settings/fields.js` :

1. Ajouter en tête : `import { ruleConfig, ruleForm } from './rule-fields';`

2. Remplacer la constante `ALL_FORM` par :

```js
/**
 * Formulaire complet, pour la validation : analyse, menu des sites, puis les champs de chaque règle.
 *
 * @param {Array} rules Définitions des règles (GET /alert-rules).
 */
export function allForm( rules = [] ) {
	return {
		layout: { type: 'regular' },
		fields: [
			...SCAN_FORM.fields,
			...MENU_FORM.fields,
			...rules.flatMap( ( rule ) => ruleForm( rule ).fields ),
		],
	};
}
```

3. `changes()` prend un troisième paramètre. Sa signature, son docblock et sa fin deviennent :

```js
/**
 * Corps de POST /settings : seules les valeurs différentes des réglages enregistrés (écart E14 du plan M4).
 * Revenir à la valeur de départ n'est donc plus une modification, et deux administrateurs qui changent des réglages
 * différents ne s'écrasent pas. Une règle modifiée est envoyée entière (activée, gravité, paramètres).
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @param {Array}  rules   Définitions des règles (GET /alert-rules).
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function changes( saved, current, rules = [] ) {
	const patch = {};
	SCAN_KEYS.forEach( ( key ) => {
		if ( ! same( saved.scan?.[ key ], current.scan?.[ key ] ) ) {
			patch.scan = { ...patch.scan, [ key ]: current.scan?.[ key ] };
		}
	} );
	if ( !! saved.sites_menu?.enabled !== !! current.sites_menu?.enabled ) {
		patch.sites_menu = { enabled: !! current.sites_menu?.enabled };
	}
	rules.forEach( ( rule ) => {
		const after = ruleConfig( current, rule );
		if ( ! same( ruleConfig( saved, rule ), after ) ) {
			patch.alerts = {
				rules: { ...patch.alerts?.rules, [ rule.id ]: after },
			};
		}
	} );
	return patch;
}
```

- [ ] **Step 5: Run the helper tests to verify they pass**

Run: `npx vitest run src/views/settings/test/rules.test.js`
Expected: PASS.

- [ ] **Step 6: Write the failing view tests**

Dans `src/views/settings/test/settings-view.test.jsx` :

1. Ajouter `within` à l'import de `@testing-library/react`.

2. Après `SETTINGS`, ajouter :

```js
const RULES = [
	{
		id: 'no_users',
		label: 'Site without users',
		description: 'No user account is attached to the site.',
		default_severity: 'error',
		default_params: {},
		params_schema: { type: 'object', properties: {} },
	},
	{
		id: 'inactive',
		label: 'Inactive site',
		description:
			'No content of the tracked types has been published or updated for a while.',
		default_severity: 'warning',
		default_params: { months: 6 },
		params_schema: {
			type: 'object',
			properties: {
				months: {
					type: 'integer',
					minimum: 1,
					maximum: 120,
					title: 'Months without activity',
					description:
						'Months without activity before the alert is raised.',
				},
			},
		},
	},
	{
		id: 'acme_rule',
		label: 'Acme rule',
		description: 'A rule added by another plugin.',
		default_severity: 'info',
		default_params: { tags: [ 'a' ] },
		params_schema: {
			type: 'object',
			properties: { tags: { type: 'array' } },
		},
	},
];

/**
 * Panneau d'une règle, ouvert.
 *
 * @param {string} label Libellé de la règle.
 */
function openRule( label ) {
	const toggle = screen.getByRole( 'button', { name: label } );
	fireEvent.click( toggle );
	return toggle.closest( '.components-panel__body' );
}
```

3. Dans `setup()`, ajouter le préchargement des règles, que les tests peuvent retirer :

```js
function setup( settings = SETTINGS, extraPreload = {}, { rules = true } = {} ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/settings': { body: settings, headers: {} },
		...( rules
			? {
					'/multisite-radar/v1/alert-rules': {
						body: RULES,
						headers: {},
					},
			  }
			: {} ),
		...extraPreload,
	};
```

(le reste de `setup()` est inchangé).

4. Ajouter les tests :

```js
test( 'each rule has a panel, and a changed parameter is saved with its rule', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup();
	expect(
		screen.getByRole( 'button', { name: 'Site without users' } )
	).toBeInTheDocument();
	const panel = openRule( 'Inactive site' );
	const months = within( panel ).getByRole( 'spinbutton', {
		name: /Months without activity/,
	} );
	expect( months ).toHaveValue( 6 );

	fireEvent.change( months, { target: { value: '8' } } );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		alerts: {
			rules: {
				inactive: {
					enabled: true,
					severity: null,
					params: { months: 8 },
				},
			},
		},
	} );
} );

test( 'a disabled rule says so, and choosing the default severity again leaves nothing to save', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const panel = openRule( 'Site without users' );

	fireEvent.click( within( panel ).getByRole( 'checkbox', { name: 'Enabled' } ) );
	expect(
		screen.getByRole( 'button', { name: 'Site without users (disabled)' } )
	).toBeInTheDocument();
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.click( within( panel ).getByRole( 'checkbox', { name: 'Enabled' } ) );
	await waitFor( () => expect( save ).toBeDisabled() );

	const severity = within( panel ).getByRole( 'combobox', {
		name: 'Severity',
	} );
	expect( severity ).toHaveValue( '' );
	expect(
		within( severity ).getByRole( 'option', { name: 'Default (Error)' } )
	).toBeInTheDocument();
	fireEvent.change( severity, { target: { value: 'warning' } } );
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.change( severity, { target: { value: '' } } );
	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'a third-party rule keeps the parameters the form cannot edit', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup();
	const panel = openRule( 'Acme rule' );
	expect( within( panel ).queryByRole( 'spinbutton' ) ).toBeNull();

	fireEvent.change(
		within( panel ).getByRole( 'combobox', { name: 'Severity' } ),
		{ target: { value: 'error' } }
	);
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		alerts: {
			rules: {
				acme_rule: {
					enabled: true,
					severity: 'error',
					params: { tags: [ 'a' ] },
				},
			},
		},
	} );
} );

test( 'an out-of-range parameter disables saving', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const panel = openRule( 'Inactive site' );

	fireEvent.change(
		within( panel ).getByRole( 'spinbutton', {
			name: /Months without activity/,
		} ),
		{ target: { value: '0' } }
	);

	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'rules that are not loaded yet show a skeleton; the rest of the form works', async () => {
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	setup( SETTINGS, {}, { rules: false } );

	expect( screen.getByText( 'Loading alert rules…' ) ).toBeInTheDocument();
	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	await waitFor( () =>
		expect(
			screen.getByRole( 'button', { name: 'Save settings' } )
		).toBeEnabled()
	);
} );
```

Si DataViews 19.1 rend la liste « Severity » autrement qu'avec un `<select>` natif (rôle `combobox` avec des `option`), adapter les sélecteurs de ces tests à son rendu réel, sans changer ce qu'ils vérifient.

- [ ] **Step 7: Run the tests to verify they fail**

Run: `npx vitest run src/views/settings`
Expected: FAIL (aucun panneau de règle n'est affiché).

- [ ] **Step 8: Write the card and wire it in the view**

Créer `src/views/settings/rules-card.jsx` :

```jsx
import { Card, CardBody, CardHeader, PanelBody } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataForm } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { getRuleFields, ruleConfig, ruleForm } from './rule-fields';

function RulePanel( { rule, data, validity, onChange } ) {
	const fields = useMemo( () => getRuleFields( rule ), [ rule ] );
	const form = useMemo( () => ruleForm( rule ), [ rule ] );
	const title = ruleConfig( data, rule ).enabled
		? rule.label
		: sprintf(
				/* translators: %s: name of an alert rule. */
				__( '%s (disabled)', 'multisite-radar' ),
				rule.label
		  );
	return (
		<PanelBody title={ title } initialOpen={ false }>
			{ rule.description && <p>{ rule.description }</p> }
			<DataForm
				data={ data }
				fields={ fields }
				form={ form }
				validity={ validity }
				onChange={ onChange }
			/>
		</PanelBody>
	);
}

/**
 * Section « Règles d'alertes » des réglages, construite depuis GET /alert-rules (spec §6.2) : un panneau par règle.
 *
 * @param {Object}   props
 * @param {Object}   props.resource Réponse de useResource( '/alert-rules' ).
 * @param {Object}   props.data     Réglages affichés, modifications comprises.
 * @param {Object}   props.validity Validité des champs (useFormValidity).
 * @param {Function} props.onChange Reçoit les modifications partielles.
 */
export default function RulesCard( { resource, data, validity, onChange } ) {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Alert rules', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Saving recomputes the alerts of every site in the background, from the data of their last analysis.',
						'multisite-radar'
					) }
				</p>
				<ErrorNotice error={ resource.error } onRetry={ resource.retry } />
				{ ! resource.data && ! resource.error && (
					<Skeleton
						label={ __( 'Loading alert rules…', 'multisite-radar' ) }
					/>
				) }
				{ ( resource.data || [] ).map( ( rule ) => (
					<RulePanel
						key={ rule.id }
						rule={ rule }
						data={ data }
						validity={ validity }
						onChange={ onChange }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}
```

Dans `src/views/settings/index.jsx` :

1. Imports : remplacer `ALL_FORM` par `allForm` dans l'import depuis `./fields`, ajouter `import { getRuleFields } from './rule-fields';` et `import RulesCard from './rules-card';`.

2. Après `const SETTINGS_PATH = buildPath( '/settings' );`, ajouter :

```js
const RULES_PATH = buildPath( '/alert-rules' );
const NO_RULES = [];
```

3. Dans le composant, après `const settings = useResource( SETTINGS_PATH );` :

```js
	const rules = useResource( RULES_PATH );
	const ruleList = rules.data || NO_RULES;
```

4. Remplacer la construction des champs et de la validité par :

```js
	const fields = useMemo(
		() => [
			...getSettingsFields( getConfig() ),
			...ruleList.flatMap( ( rule ) => getRuleFields( rule ) ),
		],
		[ ruleList ]
	);
	const form = useMemo( () => allForm( ruleList ), [ ruleList ] );
```

   puis `useFormValidity( data, fields, form )` (au lieu de `ALL_FORM`), et `changed` devient `changes( settings.data || {}, data, ruleList )`, avec `ruleList` dans ses dépendances.

5. Dans `save()`, après `invalidate( buildPath( '/sites' ) );`, ajouter `invalidate( buildPath( '/alerts' ) );` : la page Alertes et la Vue d'ensemble redemanderont leurs chiffres.

6. Afficher la carte entre la carte « Analysis » et la carte « Network sites menu » (ordre du §8) :

```jsx
					<RulesCard
						resource={ rules }
						data={ data }
						validity={ validity }
						onChange={ onChange }
					/>
```

- [ ] **Step 9: Run the tests and lint**

Run: `npx vitest run src/views/settings && npm run lint:js`
Expected: PASS.

- [ ] **Step 10: Run all the JS tests and the build**

Run: `npm run test:unit && npm run build`
Expected : tests verts, build sans erreur.

- [ ] **Step 11: Commit**

```bash
git add src/views/settings/
git commit -m "feat: configure each alert rule from the settings page"
```

## Partie D — Recette et livraison

### Task 11: Tests de bout en bout et audit d'accessibilité de la santé avancée

**Files:**
- Modify: `tests/e2e/setup.sh`, `tests/e2e/specs/a11y.spec.js`
- Create: `tests/e2e/specs/health.spec.js`

**Interfaces:**
- Consumes : tout le jalon, sur le réseau wp-env préparé par `npm run e2e:setup`.
- Produces : un site `/discret/` (« Site discret ») masqué aux moteurs de recherche, qui lève `search_hidden` ; trois parcours E2E ; un audit axe de la page Réglages avec une règle ouverte.

- [ ] **Step 1: Add the fixture site**

Dans `tests/e2e/setup.sh`, avant la ligne `wp multisite-radar scan --all --probe`, ajouter :

```sh
# Un site masqué aux moteurs de recherche : il déclenche l'alerte « Hidden from search engines ».
# Un site à part, pour ne changer aucun site dont dépendent les autres tests (bloc des sites publics, recherche…).
if ! wp site list --field=path | grep -qx '/discret/'; then
	wp site create --slug=discret --title='Site discret'
fi
wp option update blog_public 0 --url="$URL/discret/"
```

`update_option( 'blog_public' )` recopie la valeur dans la colonne `public` de `wp_blogs` (hook du cœur) : la règle la lit dans `is_public`.

- [ ] **Step 2: Write the E2E scenarios**

Créer `tests/e2e/specs/health.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Advanced health (milestone M4)', () => {
	test( 'a site hidden from search engines raises an info alert', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-alerts'
		);
		const app = page.locator( '#msradar-app' );

		await expect(
			app.getByText( 'Hidden from search engines' ).first()
		).toBeVisible();
		await expect(
			app.getByText( 'Site discret', { exact: true } ).first()
		).toBeVisible();
	} );

	test( 'the site panel shows the measures of the last analysis', async ( {
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
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		const fact = ( label ) =>
			panel.locator( 'dt', { hasText: label } ).locator( 'xpath=following-sibling::dd[1]' );

		await expect( fact( 'Database' ) ).toHaveText( /^\d+(\.\d+)? (KB|MB)$/ );
		await expect( fact( 'Autoloaded options' ) ).toHaveText(
			/^\d+(\.\d+)? (B|KB|MB)$/
		);
		await expect( fact( 'Disk' ) ).not.toHaveText( '—' );
		await expect( fact( 'Scheduled tasks' ) ).not.toHaveText( '—' );
	} );

	test( 'a rule parameter is saved from the settings', async ( {
		admin,
		page,
	} ) => {
		const app = page.locator( '#msradar-app' );
		const saved = page
			.locator( '.components-snackbar' )
			.getByText( 'Settings saved.' );
		const open = async () => {
			await admin.visitAdminPage(
				'network/admin.php',
				'page=multisite-radar-settings'
			);
			await app.getByRole( 'button', { name: 'Inactive site' } ).click();
			return app.getByRole( 'spinbutton', {
				name: /Months without activity/,
			} );
		};

		let months = await open();
		await expect( months ).toHaveValue( '6' );
		await months.fill( '9' );
		await app.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( saved ).toBeVisible();

		months = await open();
		await expect( months ).toHaveValue( '9' );

		// Remet la valeur par défaut : le test reste rejouable.
		await months.fill( '6' );
		await app.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( saved ).toBeVisible();
	} );
} );
```

Les textes annoncés aussi par la région `aria-live` (snackbars) sont cherchés dans `.components-snackbar`, sinon Playwright en trouve deux.

- [ ] **Step 3: Audit the settings page with a rule open**

Dans `tests/e2e/specs/a11y.spec.js` :

1. Le commentaire de l'exclusion commence aujourd'hui par « Page Réglages : » alors qu'elle s'applique à toutes les pages auditées (point reporté de M3). Le remplacer par :

```js
		// @wordpress/dataviews 19.1 ajoute à un FormTokenField validé (page Réglages) un champ texte invisible
		// (opacity 0, tabindex -1) sans libellé, que la règle « label » signale. Ce nœud n'est pas dans notre code :
		// on n'exclut que lui, sur toutes les pages auditées, jamais la règle. À revoir à chaque montée de version de
		// DataViews (spec §14).
```

2. Ajouter, dans le `describe` :

```js
	test( 'no serious or critical violation with an alert rule open in the settings', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-settings'
		);
		await page
			.locator( '#msradar-app' )
			.getByRole( 'button', { name: 'Inactive site' } )
			.click();
		await expect(
			page.getByRole( 'spinbutton', { name: /Months without activity/ } )
		).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );
```

- [ ] **Step 4: Run the E2E suite**

Run :

```bash
export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890
npm run build && npm run wp-env -- start && npm run e2e:setup && npm run test:e2e
```

Expected : PASS, y compris `preload.spec.js` (la page Réglages ne fait aucune requête REST au premier affichage : `/alert-rules` est préchargé par la tâche 7). Si un parcours existant (`journey.spec.js`…) échoue parce qu'un site du réseau de test lève désormais une alerte de plus, adapter son attente à l'alerte réelle et le noter dans le rapport ; ne jamais désactiver une règle pour le faire passer.

- [ ] **Step 5: Lint**

Run: `npm run lint:js`
Expected : sans nouvelle alerte.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/
git commit -m "test: cover the advanced health alerts, the site measures and the rule settings end to end"
```

### Task 12: Version 2.0.0-beta.4, traductions, documentation et recette du jalon

**Files:**
- Modify: `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php` (par `make version`)
- Modify: `languages/multisite-radar.pot`, `languages/multisite-radar-fr_FR.po` (et les fichiers générés par `make i18n`)
- Modify: `CHANGELOG.md`, `readme.txt`, `README.md`
- Modify: `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`

**Interfaces:**
- Consumes : tout le jalon.
- Produces : la version 2.0.0-beta.4, cohérente partout (`npm run version:check`), traduite en français, avec une recette verte.

- [ ] **Step 1: Set the version**

Run: `make version VERSION=2.0.0-beta.4`
Expected : en-tête, `MSRADAR_VERSION`, `readme.txt` (`Stable tag`), `package.json`, `package-lock.json` et `tests/phpstan-bootstrap.php` passent en 2.0.0-beta.4 ; `npm run version:check` le confirme.

- [ ] **Step 2: Translate the new strings**

Run: `npm run build && make i18n`
Expected : la commande s'arrête et liste les chaînes non traduites du jalon.

Les traduire dans `languages/multisite-radar-fr_FR.po`, puis relancer `make i18n` jusqu'à zéro chaîne manquante. Règles :
- typographie de WordPress en français : apostrophe typographique `’`, espace insécable (U+00A0) avant `:`, `;`, `!` et `?`, guillemets « » avec espaces insécables à l'intérieur, points de suspension `…` en un seul caractère ;
- garder chaque marqueur (`%s`, `%d`, `%1$s`…) : `tests/php/TranslationsTest.php` le vérifie ;
- KB → Ko, MB → Mo (comme les chaînes déjà traduites) ;
- le glossaire ci-dessous, aligné sur WordPress 7.1 en français, sur la spec et sur la traduction déjà livrée.

| Anglais | Français |
|---|---|
| Site without administrator | Site sans administrateur |
| No account has the administrator role on this site. | Aucun compte n’a le rôle administrateur sur ce site. |
| Missing active theme | Thème actif manquant |
| The active theme "%s" is not installed. | Le thème actif « %s » n’est pas installé. |
| The parent theme "%s" of the active theme is not installed. | Le thème parent « %s » du thème actif n’est pas installé. |
| Pending updates | Mises à jour en attente |
| Updates available for %s / Updates available for %1$s and %2$s | Mises à jour disponibles pour %s / Mises à jour disponibles pour %1$s et %2$s |
| %s plugin / %s plugins | %s extension / %s extensions |
| %s theme / %s themes | %s thème / %s thèmes |
| Address in http on an https network | Adresse en http sur un réseau en https |
| The address %s uses http while the network uses https. | L’adresse %s utilise http alors que le réseau utilise https. |
| Upload quota almost reached | Quota d’envoi presque atteint |
| %1$s MB used of the %2$s MB upload quota | %1$s Mo utilisés sur un quota d’envoi de %2$s Mo |
| Share of the quota | Part du quota |
| Heavy autoloaded options | Options chargées automatiquement trop lourdes |
| %1$s KB of autoloaded options (threshold: %2$s KB) | %1$s Ko d’options chargées automatiquement (seuil : %2$s Ko) |
| Threshold (KB) | Seuil (Ko) |
| Hidden from search engines | Masqué aux moteurs de recherche |
| Search engines are asked not to index this site. | Les moteurs de recherche sont invités à ne pas indexer ce site. |
| Overdue scheduled tasks | Tâches planifiées en retard |
| At the last analysis, %1$s scheduled task was overdue; the oldest had been waiting for %2$s. | Lors de la dernière analyse, %1$s tâche planifiée était en retard ; la plus ancienne attendait depuis %2$s. (pluriel : … %1$s tâches planifiées étaient en retard ; …) |
| Delay (hours) | Retard (heures) |
| Months without activity / Number of media files | Mois sans activité / Nombre de médias |
| Alert rules | Règles d’alerte (déjà traduit) |
| Enabled / Severity / Default (%s) / %s (disabled) | Activée / Gravité (déjà traduit) / Par défaut (%s) / %s (désactivée) |
| Loading alert rules… | Chargement des règles d’alerte… |
| Saving recomputes the alerts of every site in the background, from the data of their last analysis. | L’enregistrement recalcule en arrière-plan les alertes de chaque site, à partir des données de sa dernière analyse. |
| Measure the disk space used by each site | Mesurer l’espace disque utilisé par chaque site |
| Disk usage is an estimate | Mesure du disque partielle |
| at least %s | au moins %s |
| Autoloaded options / Scheduled tasks / None overdue | Options chargées automatiquement / Tâches planifiées / Aucune en retard |
| %1$s overdue at the last analysis, due since %2$s | %1$s en retard lors de la dernière analyse, échue depuis le %2$s (pluriel : … la plus ancienne échue depuis le %2$s) |

Les autres chaînes (descriptions des règles et des paramètres) se traduisent dans le même registre. Ensuite :

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 3: Update the documentation**

`CHANGELOG.md`, nouvelle section en tête (après le titre) :

```markdown
## [2.0.0-beta.4] - <date du jour, AAAA-MM-JJ>

### Jalon M4 — Santé avancée
- Nouvelles mesures de chaque site : espace disque du dossier d'envoi (mesure bornée à 2 secondes, affichée « au moins … » si elle s'arrête avant la fin), taille des tables, poids des options chargées automatiquement, tâches planifiées en retard. Repli sur `SHOW TABLE STATUS` quand `information_schema` est refusé.
- Huit nouvelles règles d'alertes : site sans administrateur, thème actif manquant, mises à jour en attente, adresse en http sur un réseau en https, quota d'envoi presque atteint, options chargées automatiquement trop lourdes, site masqué aux moteurs de recherche, tâches planifiées en retard.
- Réglages : section « Règles d'alertes » (activer, gravité, paramètres de chaque règle, y compris les règles ajoutées par d'autres extensions) et interrupteur « Mesurer l'espace disque ». Seules les valeurs modifiées sont enregistrées.
- REST : `GET /alert-rules` ; la fiche d'un site expose `cron`, les listes `disk_is_estimate`.
- Les alertes sont recalculées dès que la liste des mises à jour disponibles, les thèmes installés, les quotas d'envoi ou l'adresse du site principal changent.
- La mise à jour relance une analyse complète du réseau pour remplir les nouvelles mesures (version de schéma 3).
```

`readme.txt` :
- dans `== Description ==`, après le paragraphe qui commence par « Data is collected in the background… », ajouter :

```
Health alerts flag sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines and overdue scheduled tasks. Each rule can be switched off, given another severity and tuned in the settings.
```

- dans `== Changelog ==`, avant `= 2.0.0-beta.3 =` :

```
= 2.0.0-beta.4 =
* Advanced health: disk, database and autoload sizes of each site, overdue scheduled tasks.
* Eight new alert rules, from missing themes to pending updates and nearly full upload quotas.
* Every alert rule can be switched off, given another severity and tuned in the settings.
```

`README.md` : sous la ligne « Pages du menu réseau « Multisite Radar » : … », ajouter :

```markdown
Règles d'alertes livrées : site sans utilisateurs, site sans administrateur, site inactif, beaucoup de médias, thème actif manquant, mises à jour en attente, adresse en http sur un réseau en https, quota d'envoi presque atteint, autoload trop lourd, masqué aux moteurs de recherche, tâches planifiées en retard. D'autres extensions peuvent en ajouter avec le filtre `msradar_alert_rules`.
```

- [ ] **Step 4: Update the follow-up document**

Dans `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md` :
- dans « Déjà traité depuis M2 », ajouter les points traités par ce plan (tableau « Points reportés traités dans ce plan »), avec la mention « (2.0.0-beta.4, plan M4) » ;
- les retirer des sections 2 et 3 ;
- ajouter en fin de document une section `## 4. Reportés par le plan M4`, avec les points relevés pendant l'exécution de ce plan et laissés pour plus tard (registre de l'exécution, revue finale).

- [ ] **Step 5: Run the full check**

Run: `make check && npm run version:check && make dist && unzip -l dist/multisite-radar-2.0.0-beta.4.zip | grep -E 'includes/(Collector/DiskMeter|Alerts/NetworkState|Scan/NetworkStateWatcher|Rest/AlertRulesController)\.php|languages/.*\.json'`
Expected :
- lint et tests verts (hors les 11 échecs connus de la base de test, s'ils sont encore là) ;
- versions cohérentes ;
- le zip contient les quatre nouvelles classes et les fichiers JSON de traduction.

Run (environnement E2E) : `npm run test:e2e`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add multisite-radar.php readme.txt package.json package-lock.json tests/phpstan-bootstrap.php languages/ CHANGELOG.md README.md docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md
git commit -m "chore: release 2.0.0-beta.4"
```
