# Multisite Radar — Jalon M1 (Fondations) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** remplacer le code 1.x par le socle de Multisite Radar v2. Ce socle comprend :
- une collecte des données de chaque site, exacte et rapide ;
- le stockage dans des tables réseau ;
- une file d'analyse en arrière-plan ;
- un moteur d'alertes ;
- une API REST ;
- la migration depuis la 1.x ;
- des commandes WP-CLI.

Il n'y a pas encore d'interface graphique : elle arrive au jalon M2.

**Architecture :**
- **Plugin :** plugin réseau en PHP 7.4+, namespace `MultisiteRadar\`. Un conteneur léger (`Plugin`) construit les services à la demande et branche les hooks WordPress.
- **Collecte :**
  - les chiffres sont lus en SQL agrégé directement dans les tables de chaque site (`SiteCollector`) ;
  - les libellés et origines des CPT/taxonomies sont relevés dans le contexte du site lui-même (`RegistryProbe`) et stockés dans une option du site.
- **Stockage :** les résultats vont dans deux tables réseau (`msradar_sites`, `msradar_site_extensions`).
- **Traitement :** une file WP-Cron à lots bornés dans le temps, protégée par un verrou.
- **Accès aux données :** REST, WP-CLI et (plus tard) l'interface lisent tous les mêmes services `Query`.

**Tech stack :**
- PHP 7.4 à 8.4 ; WordPress ≥ 6.9 en multisite ; MySQL ≥ 5.5.5 ou MariaDB.
- Composer (dev uniquement).
- Tests : PHPUnit 9.6 + `wp-phpunit/wp-phpunit` 7.1 + `yoast/phpunit-polyfills` 2.
- Qualité : WPCS 3.4 + PHPCompatibilityWP 2.1, PHPStan 2 + `szepeviktor/phpstan-wordpress`.
- WP-CLI 2.12, GitHub Actions.

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md` (sections 2 à 5, 8 à 12 pour M1). L'exécutant lit la spec et ce plan.

## Global Constraints

- **PHP ≥ 7.4.** Interdits : `enum`, `readonly`, `match`, types union, promotion de propriétés dans le constructeur, type `mixed`, `str_contains`. Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** En-tête `Network: true`. Hors multisite, le plugin affiche une notice et ne fait rien d'autre.
- **Noms :**
  - slug et text domain `multisite-radar` ;
  - namespace `MultisiteRadar\` ;
  - préfixe `msradar_` pour options, hooks, événements cron, user meta et tables ;
  - constantes `MSRADAR_VERSION`, `MSRADAR_FILE`, `MSRADAR_DIR`, `MSRADAR_URL`.
- **Chaînes :** en anglais, via `__()` / `esc_html__()` / `_n()` avec le text domain `multisite-radar`. Commentaire `/* translators: … */` dès qu'il y a un placeholder. Les messages de WP-CLI restent en anglais non traduit.
- **SQL :**
  - toujours `$wpdb->prepare()` ;
  - noms de tables et de colonnes via `%i` ;
  - listes `IN` via `implode( ',', array_fill( 0, count( $x ), '%d' ) )` écrit directement dans la chaîne ;
  - aucune fonction JSON côté SQL (compatibilité MySQL 5.5).
- **Commentaires `phpcs:ignore` :** si PHPCS signale une requête préparée qui suit le motif de liste `IN` ci-dessus, ou une ligne déjà annotée par ce plan, ajouter ou corriger un commentaire `// phpcs:ignore <code exact signalé> -- <raison>` sur cette ligne. Ne jamais désactiver la règle dans `phpcs.xml.dist`.
- **Style WordPress (WPCS) :**
  - tabulations, espaces à l'intérieur des parenthèses, conditions Yoda, méthodes en `snake_case` ;
  - tableaux courts `[]` autorisés ;
  - `defined( 'ABSPATH' ) || exit;` en tête de chaque fichier PHP sous `includes/`.
- **Dates :** stockées en GMT au format `Y-m-d H:i:s` (`current_time( 'mysql', true )`), exposées en REST via `mysql_to_rfc3339()`. Les durées se calculent avec `time()`, jamais `current_time( 'timestamp' )`.
- **Comportement :** aucun appel HTTP externe, et aucun travail d'analyse pendant le rendu public d'une page.
- **Autoloader :** PSR-4 maison (`includes/Autoloader.php`) plutôt que celui de Composer, pour que le plugin n'ait besoin d'aucun dossier `vendor/` à l'exécution. Composer ne sert qu'aux outils de dev et à l'autoload des tests. C'est un écart volontaire par rapport au §2.1 de la spec.
- **Hors périmètre M1 :**
  - interface React, exports, vues Plugins/Thèmes/Utilisateurs ;
  - règles d'alertes de M4, mesures disque/DB/autoload/cron (colonnes présentes mais laissées à `NULL`) ;
  - tables `events`/`snapshots`, Abilities, module menu et ses alias.
- **Commits :** aucune ligne `Co-authored-by` (un hook du dépôt la refuse).
- **Tests :**
  - toujours lancés par `bin/test.sh`, qui lit le mot de passe de la base dans `/home/dev/wp/wp-config.php` ;
  - suite WordPress en mode multisite ;
  - chaque test tourne dans une transaction annulée à la fin ;
  - les tables des sites créés pendant un test sont des tables temporaires.

## Review Focus

Les cinq situations que la spec implique sans les décrire, et qui risquent le plus de gêner un utilisateur. Chacune est couverte par un test dans la tâche qui possède le code :

1. **Site aux tables manquantes ou cassées** (supprimées à la main, création échouée) → l'analyse de ce site échoue proprement (`data.scan_error`), les autres sites continuent, et la file ne boucle pas sur lui. Tests : tâche 7 (exception) et tâche 9 (file).
2. **Options corrompues ou vides** (`active_plugins` qui n'est pas un tableau, `blogname` vide, registre illisible) → pas d'erreur fatale, nom de repli « Site #ID », statut de registre `missing`. Test : tâche 7.
3. **Site supprimé entre son marquage et son analyse** → sa ligne disparaît, sans être recréée. Test : tâche 9.
4. **Analyses concurrentes** (cron + bouton REST + CLI), ou verrou laissé par un processus planté → un seul traitement à la fois, et un verrou expiré est repris. Un marquage posé pendant une analyse n'est pas perdu. Tests : tâches 3 et 9.
5. **Entrées REST limites** :
   - recherche contenant `%` ou `_` → traités comme des caractères littéraux ;
   - `per_page` au-delà de 100 ou `orderby` inconnu → erreur 400 ;
   - page au-delà de la dernière → liste vide avec le bon total.

   Tests : tâches 11 et 12.

## Structure des fichiers

```
multisite-radar.php                    # en-tête, constantes, autoload, hooks d'activation, boot
uninstall.php                          # nettoyage complet (tâche 15)
readme.txt · README.md · CHANGELOG.md · LICENSE
composer.json · phpcs.xml.dist · phpstan.neon.dist · phpunit.xml.dist · .distignore · .gitignore
bin/test-db.sh · bin/test.sh           # base de test locale, lancement de PHPUnit
.github/workflows/ci.yml               # CI (tâche 15)
includes/
  Autoloader.php                       # PSR-4 MultisiteRadar\ → includes/
  Plugin.php                           # conteneur de services + boot
  Capabilities.php                     # msradar_view / msradar_manage via map_meta_cap
  Settings/Settings.php                # option réseau msradar_settings, schéma, validation
  Install/Schema.php                   # tables (dbDelta), version de schéma
  Install/Installer.php                # activation, désactivation, mise à niveau
  Install/LegacyMigration.php          # migration 1.x + notice de cohabitation
  Storage/SiteRecord.php               # objet valeur d'une ligne msradar_sites
  Storage/SitesRepository.php          # tout le SQL de msradar_sites
  Storage/ExtensionsRepository.php     # tout le SQL de msradar_site_extensions
  Collector/Fingerprint.php            # empreinte plugins/thème/versions
  Collector/OriginResolver.php         # fichier → origine (plugin, mu-plugin, thème)
  Collector/RegistryProbe.php          # relevé du registre dans le contexte du site
  Collector/SiteCollector.php          # SQL agrégé → SiteRecord
  Alerts/Severity.php · Alert.php · RuleInterface.php · RuleRegistry.php
  Alerts/AlertEvaluator.php · AlertFormatter.php
  Alerts/Rules/NoUsersRule.php · InactiveRule.php · HighMediaRule.php
  Support/MainSite.php                 # exécuter un callback sur le site principal
  Scan/Lock.php · BatchRunner.php · Queue.php · Invalidation.php
  Query/SitesQuery.php · AlertsQuery.php
  Rest/Controller.php · SitesController.php · ScanController.php · SettingsController.php · AlertsController.php
  Cli/RadarCommand.php · SitesCommand.php
tests/
  phpstan-bootstrap.php
  php/bootstrap.php · wp-tests-config.php · TestCase.php · RestTestCase.php
  php/fixtures/plugins/fixture-events/fixture-events.php
  php/**/…Test.php
```

Responsabilités :
- **Storage** contient tout le SQL de nos tables.
- **Collector** lit les tables des sites.
- **Query** met en forme les données pour l'extérieur.
- **Rest** et **Cli** ne contiennent aucune logique métier.

---
### Task 1: Dépôt v2, outillage et démarrage du plugin

**Files:**
- Delete: `network-plugin-utilities.php`, `network-plugin-utilities/` (sauf `LICENSE` et `CHANGELOG.md`, déplacés)
- Create: `multisite-radar.php`, `includes/Autoloader.php`, `includes/Plugin.php`
- Create: `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.distignore`, `readme.txt`, `README.md`
- Create: `bin/test-db.sh`, `bin/test.sh`, `tests/phpstan-bootstrap.php`, `tests/php/bootstrap.php`, `tests/php/wp-tests-config.php`, `tests/php/TestCase.php`
- Modify: `.gitignore`, `CHANGELOG.md`
- Test: `tests/php/PluginTest.php`

**Interfaces:**
- Consumes: rien.
- Produces :
  - constantes `MSRADAR_VERSION` (`'2.0.0-dev'`), `MSRADAR_FILE`, `MSRADAR_DIR` (avec `/` final), `MSRADAR_URL` ;
  - `MultisiteRadar\Autoloader::register( string $base_dir ): void` ;
  - `MultisiteRadar\Plugin::instance(): Plugin` et `Plugin::boot(): void` ;
  - `MultisiteRadar\Tests\TestCase` (étend `WP_UnitTestCase`) avec `plugin(): Plugin` ;
  - commandes `bin/test.sh [args phpunit]`, `composer lint`, `composer lint:fix`, `composer analyse`.

- [ ] **Step 1: Étiqueter la 1.x et retirer son code**

```bash
cd /home/dev/wp-network-plugin-utilities
git tag -a v1.7.0 main -m "Network Plugin Utilities 1.7.0 (dernière version 1.x)"
git mv network-plugin-utilities/LICENSE LICENSE
git mv network-plugin-utilities/CHANGELOG.md CHANGELOG.md
git rm -r -q network-plugin-utilities.php network-plugin-utilities
ls
```

Attendu : il reste `CHANGELOG.md`, `LICENSE`, `docs/` (et les dossiers cachés `.well-known/`, `.vscode/`).

- [ ] **Step 2: Installer Composer si absent**

```bash
command -v composer || {
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  php /tmp/composer-setup.php --install-dir="$HOME/bin" --filename=composer
  rm /tmp/composer-setup.php
}
composer --version
```

Attendu : `Composer version 2.x`.

- [ ] **Step 3: Créer `composer.json`**

```json
{
	"name": "adjuvans/multisite-radar",
	"description": "Network-wide audit for WordPress Multisite.",
	"type": "wordpress-plugin",
	"license": "GPL-3.0-or-later",
	"require": {
		"php": ">=7.4"
	},
	"require-dev": {
		"dealerdirect/phpcodesniffer-composer-installer": "^1.2",
		"php-stubs/wp-cli-stubs": "^2.12",
		"phpcompatibility/phpcompatibility-wp": "^2.1",
		"phpstan/extension-installer": "^1.4",
		"phpstan/phpstan": "^2.2",
		"phpunit/phpunit": "^9.6",
		"szepeviktor/phpstan-wordpress": "^2.0",
		"wp-coding-standards/wpcs": "^3.4",
		"wp-phpunit/wp-phpunit": "7.1.*",
		"yoast/phpunit-polyfills": "^2.0"
	},
	"autoload-dev": {
		"psr-4": {
			"MultisiteRadar\\Tests\\": "tests/php/"
		}
	},
	"config": {
		"allow-plugins": {
			"dealerdirect/phpcodesniffer-composer-installer": true,
			"phpstan/extension-installer": true
		},
		"platform": {
			"php": "7.4.33"
		},
		"sort-packages": true
	},
	"scripts": {
		"lint": "phpcs",
		"lint:fix": "phpcbf",
		"analyse": "phpstan analyse --memory-limit=1G"
	}
}
```

- [ ] **Step 4: Créer `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="Multisite Radar">
	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>
	<exclude-pattern>/build/*</exclude-pattern>
	<exclude-pattern>/tests/*</exclude-pattern>
	<exclude-pattern>/docs/*</exclude-pattern>

	<arg name="extensions" value="php"/>
	<arg name="colors"/>
	<arg value="sp"/>

	<config name="testVersion" value="7.4-"/>
	<config name="minimum_wp_version" value="6.9"/>
	<!-- Les avertissements restent affichés ; seules les erreurs font échouer `composer lint` (critère « WPCS sans erreur »). -->
	<config name="ignore_warnings_on_exit" value="1"/>

	<rule ref="PHPCompatibilityWP"/>

	<rule ref="WordPress-Extra">
		<exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
		<exclude name="WordPress.Files.FileName"/>
		<!-- Les callbacks de hooks et les implémentations d'interface ont légitimement des paramètres inutilisés. -->
		<exclude name="Generic.CodeAnalysis.UnusedFunctionParameter"/>
	</rule>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="multisite-radar"/>
			</property>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="msradar"/>
				<element value="MultisiteRadar"/>
			</property>
		</properties>
	</rule>

	<!-- Nos tables personnalisées et celles des sites sont lues directement : c'est le cœur du plugin. -->
	<rule ref="WordPress.DB.DirectDatabaseQuery">
		<exclude-pattern>/includes/(Storage|Collector|Scan|Install)/*</exclude-pattern>
		<exclude-pattern>/uninstall\.php</exclude-pattern>
	</rule>
</ruleset>
```

- [ ] **Step 5: Créer `phpstan.neon.dist` et `tests/phpstan-bootstrap.php`**

`phpstan.neon.dist` :

```neon
parameters:
	level: 6
	paths:
		- multisite-radar.php
		- includes
	bootstrapFiles:
		- tests/phpstan-bootstrap.php
	scanFiles:
		- vendor/php-stubs/wp-cli-stubs/wp-cli-stubs.php
	ignoreErrors:
		-
			identifier: missingType.iterableValue
```

`tests/phpstan-bootstrap.php` :

```php
<?php
// Constantes du plugin pour l'analyse statique (le fichier principal n'est pas exécuté par PHPStan).
define( 'MSRADAR_VERSION', '2.0.0-dev' );
define( 'MSRADAR_FILE', dirname( __DIR__ ) . '/multisite-radar.php' );
define( 'MSRADAR_DIR', dirname( __DIR__ ) . '/' );
define( 'MSRADAR_URL', 'https://example.org/wp-content/plugins/multisite-radar/' );
```

Les constantes WordPress (`ARRAY_A`, `DAY_IN_SECONDS`, `WP_PLUGIN_DIR`…) sont définies par l'amorçage de `szepeviktor/phpstan-wordpress`. Si PHPStan signale malgré tout une constante WordPress inconnue, l'ajouter ici sous la forme `defined( 'X' ) || define( 'X', … );`.

- [ ] **Step 6: Créer `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
	bootstrap="tests/php/bootstrap.php"
	backupGlobals="false"
	colors="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
>
	<php>
		<const name="WP_TESTS_MULTISITE" value="1"/>
	</php>
	<testsuites>
		<testsuite name="multisite-radar">
			<directory suffix="Test.php">tests/php</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 7: Mettre à jour `.gitignore` et créer `.distignore`**

Ajouter à la fin de `.gitignore` :

```
# Multisite Radar
/vendor/
.phpunit.result.cache
```

`.distignore` (fichiers exclus du zip publié) :

```
/.git
/.github
/.gitignore
/.distignore
/.vscode
/.well-known
/bin
/docs
/tests
/vendor
/node_modules
/composer.json
/composer.lock
/phpcs.xml.dist
/phpstan.neon.dist
/phpunit.xml.dist
/.phpunit.result.cache
/README.md
```

- [ ] **Step 8: Installer les dépendances de dev**

```bash
composer install --no-progress
ls vendor/bin | grep -E "^(phpunit|phpcs|phpcbf|phpstan)$"
```

Attendu : les quatre binaires sont listés.

- [ ] **Step 9: Créer la base de test locale**

`bin/test-db.sh` :

```bash
#!/usr/bin/env bash
# Crée la base de test dans le conteneur MariaDB de la VM et la donne à l'utilisateur « wordpress ».
set -euo pipefail
CONTAINER="${DB_CONTAINER:-wp-network-plugin-utilities-db}"
DB="${WP_TESTS_DB_NAME:-wordpress_test}"
docker exec -i "$CONTAINER" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB}\`;
GRANT ALL PRIVILEGES ON \`${DB}\`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
echo "Base ${DB} prête."
```

`bin/test.sh` :

```bash
#!/usr/bin/env bash
# Lance PHPUnit ; lit le mot de passe de la base dans le wp-config de la VM si besoin.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -z "${WP_TESTS_DB_PASSWORD:-}" ]; then
	WP_TESTS_DB_PASSWORD="$(wp config get DB_PASSWORD --path="${WP_CORE_DIR:-/home/dev/wp}")"
	export WP_TESTS_DB_PASSWORD
fi
exec vendor/bin/phpunit "$@"
```

```bash
chmod +x bin/test-db.sh bin/test.sh
bin/test-db.sh
```

Attendu : `Base wordpress_test prête.`

- [ ] **Step 10: Créer l'amorçage des tests**

`tests/php/wp-tests-config.php` :

```php
<?php
// Configuration de la suite de tests WordPress (lue par wp-phpunit).
define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: '/home/dev/wp', '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wordpress' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
```

`tests/php/bootstrap.php` :

```php
<?php
// Amorçage PHPUnit : suite de tests WordPress en multisite, plugin chargé comme un MU-plugin.
$msradar_root = dirname( __DIR__, 2 );
require_once $msradar_root . '/vendor/autoload.php';

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$msradar_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
require_once $msradar_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $msradar_root ): void {
		require $msradar_root . '/multisite-radar.php';
	}
);

require $msradar_tests_dir . '/includes/bootstrap.php';
```

`tests/php/TestCase.php` :

```php
<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Plugin;

/**
 * Base de tous les tests du plugin.
 */
abstract class TestCase extends \WP_UnitTestCase {

	protected function plugin(): Plugin {
		return Plugin::instance();
	}
}
```

- [ ] **Step 11: Écrire le test qui échoue**

`tests/php/PluginTest.php` :

```php
<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Plugin;

final class PluginTest extends TestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( '2.0.0-dev', MSRADAR_VERSION );
		$this->assertFileExists( MSRADAR_FILE );
		$this->assertStringEndsWith( '/', MSRADAR_DIR );
	}

	public function test_suite_runs_in_multisite(): void {
		$this->assertTrue( is_multisite() );
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}
}
```

- [ ] **Step 12: Lancer le test pour le voir échouer**

Run: `bin/test.sh --filter PluginTest`
Expected: échec. PHP signale que `multisite-radar.php` est introuvable (`Failed opening required`).

- [ ] **Step 13: Créer le fichier principal, l'autoloader et le conteneur**

`multisite-radar.php` :

```php
<?php
/**
 * Plugin Name:       Multisite Radar
 * Plugin URI:        https://github.com/adjuvans/wp-network-plugin-utilities
 * Description:       Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.
 * Version:           2.0.0-dev
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Cyrille de Gourcy
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       multisite-radar
 * Network:           true
 *
 * @package MultisiteRadar
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'MSRADAR_VERSION' ) ) {
	return;
}

define( 'MSRADAR_VERSION', '2.0.0-dev' );
define( 'MSRADAR_FILE', __FILE__ );
define( 'MSRADAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSRADAR_URL', plugin_dir_url( __FILE__ ) );

require_once MSRADAR_DIR . 'includes/Autoloader.php';
MultisiteRadar\Autoloader::register( MSRADAR_DIR . 'includes/' );

MultisiteRadar\Plugin::instance()->boot();
```

`includes/Autoloader.php` :

```php
<?php
namespace MultisiteRadar;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 : MultisiteRadar\Foo\Bar → includes/Foo/Bar.php.
 */
final class Autoloader {

	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}
				$file = $base_dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
```

`includes/Plugin.php` :

```php
<?php
namespace MultisiteRadar;

defined( 'ABSPATH' ) || exit;

/**
 * Conteneur : construit les services à la demande et branche les hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'render_multisite_notice' ] );
			return;
		}
	}

	public function render_multisite_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Multisite Radar requires a WordPress Multisite network. It does nothing on a single site.', 'multisite-radar' )
		);
	}
}
```

- [ ] **Step 14: Lancer les tests**

Run: `bin/test.sh --filter PluginTest`
Expected: `OK (3 tests, 5 assertions)`. La première exécution installe WordPress dans `wordpress_test` en mode multisite (« Installing network… »).

- [ ] **Step 15: Créer `readme.txt`, `README.md` et compléter `CHANGELOG.md`**

`readme.txt` : version minimale qui passe le validateur. La fiche complète (et la ligne `Contributors` avec l'identifiant WordPress.org) sera ajoutée en M7.

```
=== Multisite Radar ===
Tags: multisite, network, audit, inventory, admin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-dev
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.

== Description ==

Multisite Radar gives network administrators a single, always up-to-date view of every site in a WordPress Multisite network: content types and their origin, users, active plugins and theme, activity and health alerts.

Data is collected in the background with lightweight SQL queries, so the dashboard stays fast on networks with thousands of sites. Nothing is sent to external services.

Source code: https://github.com/adjuvans/wp-network-plugin-utilities

== Changelog ==

= 2.0.0-dev =
* Complete rewrite (in progress).
```

`README.md` :

````markdown
# Multisite Radar

Audit réseau pour WordPress Multisite (successeur de Network Plugin Utilities 1.x).

- Spec : `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`
- Plans : `docs/superpowers/plans/`

## Développement

```bash
composer install
bin/test-db.sh        # une fois : crée la base wordpress_test
bin/test.sh           # tests PHPUnit (multisite)
composer lint         # WPCS
composer analyse      # PHPStan niveau 6
```
````

Ajouter en tête de `CHANGELOG.md`, juste après le titre :

```markdown
## [2.0.0] - en cours

Réécriture complète sous le nom **Multisite Radar** (slug `multisite-radar`). Voir la spec dans `docs/superpowers/specs/`.

---
```

- [ ] **Step 16: Vérifier normes et analyse statique**

```bash
composer lint:fix; composer lint
composer analyse
```

Expected :
- `composer lint` ne signale aucune erreur ;
- PHPStan affiche `[OK] No errors`.

Si PHPCS signale une règle qui contredit les Global Constraints, corriger le code et non la configuration.

- [ ] **Step 17: Brancher le plugin dans le WordPress local**

Le dossier du dépôt est déjà lié par symlink en `wp-content/plugins/wp-network-plugin-utilities`. On le remplace par un lien `multisite-radar` et on retire l'ancienne entrée réseau, dont le fichier n'existe plus.

```bash
cd /home/dev/wp
ln -sfn /home/dev/wp-network-plugin-utilities wp-content/plugins/multisite-radar
wp eval '$p = get_site_option( "active_sitewide_plugins", array() ); unset( $p["wp-network-plugin-utilities/network-plugin-utilities.php"] ); update_site_option( "active_sitewide_plugins", $p );'
rm wp-content/plugins/wp-network-plugin-utilities
wp plugin activate multisite-radar --network
wp plugin list --fields=name,status | grep multisite-radar
cd /home/dev/wp-network-plugin-utilities
```

Expected : `multisite-radar	active-network`.

- [ ] **Step 18: Commit**

```bash
git add -A
git commit -m "chore: bootstrap Multisite Radar v2 (tooling, autoloader, plugin container)"
```

---

### Task 2: Capacités et réglages

**Files:**
- Create: `includes/Capabilities.php`, `includes/Settings/Settings.php`
- Modify: `includes/Plugin.php`, `tests/php/TestCase.php`
- Test: `tests/php/CapabilitiesTest.php`, `tests/php/Settings/SettingsTest.php`

**Interfaces:**
- Consumes: `Plugin` (tâche 1).
- Produces :
  - `Capabilities::VIEW = 'msradar_view'`, `Capabilities::MANAGE = 'msradar_manage'`, `Capabilities::register(): void`, filtre `msradar_capability_map` ;
  - `Settings` avec :
    - `OPTION = 'msradar_settings'` ;
    - `defaults(): array` (filtre `msradar_default_settings`) et `schema(): array` ;
    - `all(): array` ;
    - `get( string $path, $fallback = null )` avec un chemin pointé, ex. `'scan.activity_post_types'` ;
    - `update( array $patch ): array|WP_Error` (erreur `msradar_invalid_settings`, statut 400) ;
    - `rule_config( string $rule_id ): array` ;
    - `reset_cache(): void` ;
  - action `msradar_settings_updated( array $new, array $old )` ;
  - `Plugin::settings(): Settings` et `Plugin::reset_caches(): void`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/CapabilitiesTest.php` :

```php
<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Capabilities;

final class CapabilitiesTest extends TestCase {

	public function test_super_admin_can_view_and_manage(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );

		$this->assertTrue( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertTrue( user_can( $user_id, Capabilities::MANAGE ) );
	}

	public function test_site_administrator_cannot_view_or_manage(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertFalse( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE ) );
	}

	public function test_capability_map_is_filterable(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		add_filter(
			'msradar_capability_map',
			static function ( array $map ): array {
				$map[ Capabilities::VIEW ] = 'manage_options';
				return $map;
			}
		);

		$this->assertTrue( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE ) );
	}
}
```

`tests/php/Settings/SettingsTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Tests\TestCase;

final class SettingsTest extends TestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( Settings::OPTION );
		$this->settings = new Settings();
	}

	public function test_defaults_apply_when_nothing_is_stored(): void {
		$this->assertSame( [ 'post', 'page' ], $this->settings->get( 'scan.activity_post_types' ) );
		$this->assertSame( 7, $this->settings->get( 'scan.full_rescan_days' ) );
		$this->assertFalse( $this->settings->get( 'integrations.mcp_public' ) );
		$this->assertFalse( $this->settings->get( 'sites_menu.enabled' ) );
		$this->assertSame( 'fallback', $this->settings->get( 'scan.unknown', 'fallback' ) );
	}

	public function test_update_merges_a_partial_patch_and_persists_it(): void {
		$result = $this->settings->update( [ 'scan' => [ 'full_rescan_days' => 3 ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $this->settings->get( 'scan.full_rescan_days' ) );
		$this->assertSame( [ 'post', 'page' ], $this->settings->get( 'scan.activity_post_types' ), 'Untouched keys keep their value.' );
		$this->assertSame( 3, ( new Settings() )->get( 'scan.full_rescan_days' ), 'Value is persisted.' );
		$this->assertSame( [ 'scan' => [ 'full_rescan_days' => 3 ] ], get_site_option( Settings::OPTION ), 'Only overrides are stored.' );
	}

	public function test_lists_are_replaced_not_merged(): void {
		$this->settings->update( [ 'scan' => [ 'activity_post_types' => [ 'event' ] ] ] );

		$this->assertSame( [ 'event' ], $this->settings->get( 'scan.activity_post_types' ) );
	}

	public function test_rule_overrides_are_kept_per_rule(): void {
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ] );
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'high_media' => [ 'severity' => 'warning' ] ] ] ] );
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'no_users' => [ 'severity' => null ] ] ] ] );

		$this->assertSame( [ 'enabled' => false ], $this->settings->rule_config( 'inactive' ) );
		$this->assertSame( [ 'severity' => 'warning' ], $this->settings->rule_config( 'high_media' ) );
		$this->assertSame( [ 'severity' => null ], $this->settings->rule_config( 'no_users' ) );
		$this->assertSame( [], $this->settings->rule_config( 'unknown_rule' ) );
	}

	/**
	 * @dataProvider invalid_patches
	 */
	public function test_invalid_patch_is_rejected_and_nothing_is_saved( array $patch ): void {
		$result = $this->settings->update( $patch );

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_invalid_settings', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFalse( get_site_option( Settings::OPTION ) );
	}

	public static function invalid_patches(): array {
		return [
			'unknown section'      => [ [ 'nope' => true ] ],
			'days out of range'    => [ [ 'scan' => [ 'full_rescan_days' => 0 ] ] ],
			'empty activity types' => [ [ 'scan' => [ 'activity_post_types' => [] ] ] ],
			'invalid post type'    => [ [ 'scan' => [ 'activity_post_types' => [ 'Bad Type!' ] ] ] ],
			'invalid severity'     => [ [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'severity' => 'fatal' ] ] ] ] ],
			'invalid email'        => [ [ 'reports' => [ 'digest_recipients' => [ 'emails' => [ 'not-an-email' ] ] ] ] ],
		];
	}

	public function test_update_fires_an_action_with_new_and_old_values(): void {
		$seen = [];
		add_action(
			'msradar_settings_updated',
			static function ( array $new_settings, array $old_settings ) use ( &$seen ): void {
				$seen = [ $new_settings['scan']['full_rescan_days'], $old_settings['scan']['full_rescan_days'] ];
			},
			10,
			2
		);

		$this->settings->update( [ 'scan' => [ 'full_rescan_days' => 30 ] ] );

		$this->assertSame( [ 30, 7 ], $seen );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'CapabilitiesTest|SettingsTest'`
Expected: échec.
- `SettingsTest` : `Class "MultisiteRadar\Settings\Settings" not found`.
- `CapabilitiesTest::test_capability_map_is_filterable` échoue, car le filtre n'est pas encore branché.
- Les deux autres tests de capacités passent déjà : un super-admin a toutes les capacités, et un administrateur de site n'a pas `msradar_view`. Ils protègent contre une régression.

- [ ] **Step 3: Implémenter `Capabilities`**

`includes/Capabilities.php` :

```php
<?php
namespace MultisiteRadar;

defined( 'ABSPATH' ) || exit;

/**
 * Capacités du plugin, résolues vers des capacités réseau existantes.
 */
final class Capabilities {

	public const VIEW   = 'msradar_view';
	public const MANAGE = 'msradar_manage';

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map' ], 10, 2 );
	}

	/**
	 * @param string[] $caps Capacités primitives requises.
	 * @param string   $cap  Capacité demandée.
	 * @return string[]
	 */
	public static function map( array $caps, string $cap ): array {
		if ( self::VIEW !== $cap && self::MANAGE !== $cap ) {
			return $caps;
		}

		$map = (array) apply_filters(
			'msradar_capability_map',
			[
				self::VIEW   => 'manage_network',
				self::MANAGE => 'manage_network_options',
			]
		);

		return [ isset( $map[ $cap ] ) ? (string) $map[ $cap ] : 'do_not_allow' ];
	}
}
```

- [ ] **Step 4: Implémenter `Settings`**

`includes/Settings/Settings.php` :

```php
<?php
namespace MultisiteRadar\Settings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Réglages réseau. Seules les valeurs modifiées sont stockées ; les défauts sont fusionnés à la lecture.
 */
final class Settings {

	public const OPTION = 'msradar_settings';

	private ?array $cache = null;

	public static function defaults(): array {
		$defaults = [
			'scan'         => [
				'activity_post_types' => [ 'post', 'page' ],
				'analysis_plugins'    => [],
				'measure_disk'        => true,
				'full_rescan_days'    => 7,
			],
			'alerts'       => [ 'rules' => [] ],
			'reports'      => [
				'digest_enabled'    => false,
				'digest_day'        => 1,
				'digest_recipients' => [
					'mode'   => 'super_admins',
					'emails' => [],
				],
			],
			'integrations' => [ 'mcp_public' => false ],
			'sites_menu'   => [ 'enabled' => false ],
			'retention'    => [
				'events_days'    => 90,
				'snapshots_days' => 365,
			],
		];

		return (array) apply_filters( 'msradar_default_settings', $defaults );
	}

	public static function schema(): array {
		$section = static function ( array $properties ): array {
			return [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => $properties,
			];
		};
		$days    = static function ( int $maximum ): array {
			return [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => $maximum,
			];
		};

		return $section(
			[
				'scan'         => $section(
					[
						'activity_post_types' => [
							'type'        => 'array',
							'minItems'    => 1,
							'uniqueItems' => true,
							'items'       => [
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,20}$',
							],
						],
						'analysis_plugins'    => [
							'type'        => 'array',
							'uniqueItems' => true,
							'items'       => [
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 191,
							],
						],
						'measure_disk'        => [ 'type' => 'boolean' ],
						'full_rescan_days'    => $days( 90 ),
					]
				),
				'alerts'       => $section(
					[
						'rules' => [
							'type'                 => 'object',
							'additionalProperties' => $section(
								[
									'enabled'  => [ 'type' => 'boolean' ],
									'severity' => [
										'type' => [ 'string', 'null' ],
										'enum' => [ 'error', 'warning', 'info', null ],
									],
									'params'   => [ 'type' => 'object' ],
								]
							),
						],
					]
				),
				'reports'      => $section(
					[
						'digest_enabled'    => [ 'type' => 'boolean' ],
						'digest_day'        => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 6,
						],
						'digest_recipients' => $section(
							[
								'mode'   => [
									'type' => 'string',
									'enum' => [ 'super_admins', 'custom' ],
								],
								'emails' => [
									'type'  => 'array',
									'items' => [
										'type'   => 'string',
										'format' => 'email',
									],
								],
							]
						),
					]
				),
				'integrations' => $section( [ 'mcp_public' => [ 'type' => 'boolean' ] ] ),
				'sites_menu'   => $section( [ 'enabled' => [ 'type' => 'boolean' ] ] ),
				'retention'    => $section(
					[
						'events_days'    => $days( 3650 ),
						'snapshots_days' => $days( 3650 ),
					]
				),
			]
		);
	}

	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = self::merge( self::defaults(), $this->stored() );
		}
		return $this->cache;
	}

	/**
	 * @param string $path     Chemin pointé, ex. « scan.activity_post_types ».
	 * @param mixed  $fallback Valeur renvoyée si le chemin n'existe pas.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $fallback;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * @return array|WP_Error Réglages complets après mise à jour, ou erreur de validation (statut 400).
	 */
	public function update( array $patch ) {
		$schema = self::schema();
		$valid  = rest_validate_value_from_schema( $patch, $schema, 'settings' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'msradar_invalid_settings', $valid->get_error_message(), [ 'status' => 400 ] );
		}

		$clean = rest_sanitize_value_from_schema( $patch, $schema, 'settings' );
		if ( ! is_array( $clean ) ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Invalid settings.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$old = $this->all();
		update_site_option( self::OPTION, self::merge( $this->stored(), $clean ) );
		$this->cache = null;
		$new         = $this->all();

		do_action( 'msradar_settings_updated', $new, $old );

		return $new;
	}

	public function rule_config( string $rule_id ): array {
		$rules = $this->get( 'alerts.rules', [] );
		return is_array( $rules ) && isset( $rules[ $rule_id ] ) && is_array( $rules[ $rule_id ] ) ? $rules[ $rule_id ] : [];
	}

	public function reset_cache(): void {
		$this->cache = null;
	}

	/**
	 * Fusion récursive : les tableaux associatifs sont fusionnés, les listes remplacées.
	 */
	public static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] )
				&& ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}
			$base[ $key ] = $value;
		}
		return $base;
	}

	private function stored(): array {
		$stored = get_site_option( self::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
```

- [ ] **Step 5: Brancher dans le conteneur et réinitialiser les caches entre les tests**

Dans `includes/Plugin.php` :

1. Ajouter, sous `namespace MultisiteRadar;` :

```php
use MultisiteRadar\Settings\Settings;
```

2. Ajouter la propriété, sous `private static ?Plugin $instance = null;` :

```php
	private ?Settings $settings = null;
```

3. Remplacer le corps de `boot()` par :

```php
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'render_multisite_notice' ] );
			return;
		}

		Capabilities::register();
```

4. Ajouter ces méthodes à la fin de la classe :

```php
	public function settings(): Settings {
		return $this->settings ??= new Settings();
	}

	/**
	 * Vide les caches en mémoire des services (utile après une annulation de transaction en test).
	 */
	public function reset_caches(): void {
		if ( null !== $this->settings ) {
			$this->settings->reset_cache();
		}
	}
```

Dans `tests/php/TestCase.php`, ajouter dans la classe :

```php
	public function set_up(): void {
		parent::set_up();
		$this->plugin()->reset_caches();
	}
```

- [ ] **Step 6: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK (17 tests, …)`. `PluginTest` (3), `CapabilitiesTest` (3) et `SettingsTest` (11, dont les 6 cas du data provider) passent.

- [ ] **Step 7: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add capabilities and validated network settings"
```

---
### Task 3: Stockage (schéma, SiteRecord, dépôts)

**Files:**
- Create: `includes/Install/Schema.php`, `includes/Storage/SiteRecord.php`, `includes/Storage/SitesRepository.php`, `includes/Storage/ExtensionsRepository.php`
- Modify: `includes/Plugin.php`, `tests/php/bootstrap.php`, `tests/php/TestCase.php`
- Test: `tests/php/Install/SchemaTest.php`, `tests/php/Storage/SitesRepositoryTest.php`, `tests/php/Storage/ExtensionsRepositoryTest.php`

**Interfaces:**
- Consumes: `Plugin` (tâche 1).
- Produces :
  - **`Schema`** :
    - constantes `VERSION = 1` et `OPTION = 'msradar_db_version'` ;
    - `sites_table(): string`, `extensions_table(): string`, `tables(): string[]` ;
    - `install(): void`, `is_current(): bool`, `drop(): void`.
  - **`SiteRecord`** : propriétés publiques typées, une par colonne (voir code), plus `from_row( array ): self`, `to_row(): array` et `alert_rule_ids(): string[]`.
  - **`SitesRepository`** :
    - lecture : `find( int ): ?SiteRecord`, `find_many( int[] ): array<int, SiteRecord>`, `exists( int ): bool` ;
    - écriture : `save( SiteRecord ): void` (n'écrase jamais `dirty`/`dirty_since` d'une ligne existante), `save_alerts( SiteRecord ): void`, `delete( int ): void` ;
    - insertion : `insert_pending( int $site_id, int $network_id, string $url ): void`, `seed_from_blogs( int $network_id ): int`, `delete_orphans(): int` ;
    - file d'analyse : `mark_dirty( int[] ): int`, `mark_all_dirty( int $network_id ): int`, `clear_dirty( int ): void`, `next_dirty( int $limit ): int[]`, `dirty_ids(): int[]` ;
    - comptages : `count_dirty(): int`, `count_all( int $network_id ): int`, `count_pending( int $network_id ): int` ;
    - divers : `update_last_activity( int $site_id, string $gmt ): void`, `ids_after( int $after_id, int $limit ): int[]`.
  - **`ExtensionsRepository`** :
    - constantes `TYPE_PLUGIN = 'plugin'` et `TYPE_THEME = 'theme'` ;
    - `replace_for_site( int $site_id, string[] $local_plugins, string $stylesheet, string $template ): void` ;
    - `delete_for_site( int ): void` ;
    - `for_site( int ): array<int, array{type: string, slug: string, role: string}>` (tri par type puis slug).
  - **`Plugin`** : `sites(): SitesRepository`, `extensions(): ExtensionsRepository`.
  - **`TestCase`** : `make_record( int $site_id, array $props = [] ): SiteRecord`, `mark_all_clean(): void`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Install/SchemaTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class SchemaTest extends TestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::sites_table() ) );
		foreach ( [ 'site_id', 'network_id', 'alert_level', 'alert_rules', 'registry_status', 'data', 'dirty', 'dirty_since', 'scanned_at' ] as $column ) {
			$this->assertContains( $column, $columns );
		}

		$this->assertSame(
			[ 'site_id', 'type', 'slug', 'role' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::extensions_table() ) )
		);
		$this->assertTrue( Schema::is_current() );
	}

	public function test_install_is_idempotent(): void {
		Schema::install();
		Schema::install();

		$this->assertTrue( Schema::is_current() );
	}
}
```

`tests/php/Storage/SitesRepositoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Tests\TestCase;

final class SitesRepositoryTest extends TestCase {

	private SitesRepository $sites;

	public function set_up(): void {
		parent::set_up();
		$this->sites = $this->plugin()->sites();
	}

	public function test_save_and_find_round_trip(): void {
		$record                    = new SiteRecord();
		$record->site_id           = 501;
		$record->name              = 'Blog RH';
		$record->url               = 'https://example.org/rh/';
		$record->users_count       = 3;
		$record->last_activity_gmt = '2026-09-12 08:41:00';
		$record->alert_rules       = ',inactive,';
		$record->data              = [ 'post_types' => [ [ 'name' => 'post' ] ] ];
		$record->dirty             = false;

		$this->sites->save( $record );
		$found = $this->sites->find( 501 );

		$this->assertNotNull( $found );
		$this->assertSame( 'Blog RH', $found->name );
		$this->assertSame( 3, $found->users_count );
		$this->assertNull( $found->disk_bytes );
		$this->assertSame( '2026-09-12 08:41:00', $found->last_activity_gmt );
		$this->assertSame( [ 'inactive' ], $found->alert_rule_ids() );
		$this->assertSame( [ 'post_types' => [ [ 'name' => 'post' ] ] ], $found->data );
		$this->assertFalse( $found->dirty );
		$this->assertNull( $this->sites->find( 999999 ) );
	}

	public function test_seed_inserts_one_pending_row_per_site_and_is_idempotent(): void {
		$site_id = self::factory()->blog->create();

		$this->sites->seed_from_blogs( get_current_network_id() );
		$record = $this->sites->find( $site_id );

		$this->assertNotNull( $record );
		$this->assertTrue( $record->dirty );
		$this->assertNull( $record->scanned_at );
		$this->assertNotNull( $this->sites->find( get_main_site_id() ), 'The main site is seeded too.' );
		$this->assertSame( 0, $this->sites->seed_from_blogs( get_current_network_id() ) );
	}

	public function test_mark_dirty_keeps_the_earliest_mark_and_orders_the_queue(): void {
		global $wpdb;
		$this->sites->insert_pending( 601, 1, 'a.test/' );
		$this->sites->insert_pending( 602, 1, 'b.test/' );
		$this->mark_all_clean();

		$this->sites->mark_dirty( [ 602 ] );
		$wpdb->update( Schema::sites_table(), [ 'dirty_since' => '2020-01-01 00:00:00' ], [ 'site_id' => 602 ] );
		$this->sites->mark_dirty( [ 601, 602, 0, -3 ] );

		$this->assertSame( '2020-01-01 00:00:00', $this->sites->find( 602 )->dirty_since, 'An existing mark is kept.' );
		$this->assertSame( [ 602, 601 ], $this->sites->next_dirty( 10 ) );
		$this->assertSame( [ 601, 602 ], $this->sites->dirty_ids() );
		$this->assertSame( 2, $this->sites->count_dirty() );
	}

	public function test_save_never_overwrites_a_mark_set_during_the_scan(): void {
		$this->sites->insert_pending( 701, 1, 'c.test/' );

		$record          = new SiteRecord();
		$record->site_id = 701;
		$record->name    = 'Scanned';
		$record->dirty   = false;
		$this->sites->save( $record );
		$found = $this->sites->find( 701 );

		$this->assertSame( 'Scanned', $found->name );
		$this->assertTrue( $found->dirty, 'The pending mark survives.' );
	}

	public function test_last_activity_only_moves_forward(): void {
		$this->sites->insert_pending( 801, 1, 'd.test/' );

		$this->sites->update_last_activity( 801, '2026-01-01 00:00:00' );
		$this->sites->update_last_activity( 801, '2025-01-01 00:00:00' );

		$this->assertSame( '2026-01-01 00:00:00', $this->sites->find( 801 )->last_activity_gmt );
	}

	public function test_delete_orphans_removes_rows_without_a_site(): void {
		$this->sites->insert_pending( 901, 1, 'gone.test/' );
		$this->sites->seed_from_blogs( get_current_network_id() );

		$this->assertSame( 1, $this->sites->delete_orphans() );
		$this->assertNull( $this->sites->find( 901 ) );
		$this->assertNotNull( $this->sites->find( get_main_site_id() ) );
	}

	public function test_find_many_counts_and_pagination_helpers(): void {
		$this->make_record( 1001, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 1002 );
		$this->make_record( 1003 );

		$this->assertSame( [ 1001, 1003 ], array_keys( $this->sites->find_many( [ 1003, 1001, 4242 ] ) ) );
		$this->assertSame( [ 1002, 1003 ], $this->sites->ids_after( 1001, 10 ) );
		$this->assertSame( 3, $this->sites->count_all( 1 ) );
		$this->assertSame( 2, $this->sites->count_pending( 1 ) );
	}

	public function test_save_alerts_updates_only_alert_columns(): void {
		$record               = $this->make_record( 1101, [ 'name' => 'Keep me' ] );
		$record->alert_level  = 3;
		$record->alerts_count = 1;
		$record->alert_rules  = ',no_users,';
		$record->data         = [ 'alerts' => [ [ 'rule' => 'no_users' ] ] ];
		$record->name         = 'Ignored';

		$this->sites->save_alerts( $record );
		$found = $this->sites->find( 1101 );

		$this->assertSame( 3, $found->alert_level );
		$this->assertSame( [ 'no_users' ], $found->alert_rule_ids() );
		$this->assertSame( 'Keep me', $found->name );
	}
}
```

`tests/php/Storage/ExtensionsRepositoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Tests\TestCase;

final class ExtensionsRepositoryTest extends TestCase {

	public function test_replace_for_site_stores_local_plugins_and_theme_roles(): void {
		$extensions = $this->plugin()->extensions();

		$extensions->replace_for_site( 5, [ 'hello.php', 'acme/acme.php', 'acme/acme.php' ], 'child', 'parent' );

		$this->assertSame(
			[
				[ 'type' => 'plugin', 'slug' => 'acme/acme.php', 'role' => 'local' ],
				[ 'type' => 'plugin', 'slug' => 'hello.php', 'role' => 'local' ],
				[ 'type' => 'theme', 'slug' => 'child', 'role' => 'active' ],
				[ 'type' => 'theme', 'slug' => 'parent', 'role' => 'parent' ],
			],
			$extensions->for_site( 5 )
		);

		$extensions->replace_for_site( 5, [], 'twentytwentyfive', 'twentytwentyfive' );
		$this->assertSame( [ [ 'type' => 'theme', 'slug' => 'twentytwentyfive', 'role' => 'active' ] ], $extensions->for_site( 5 ) );

		$extensions->delete_for_site( 5 );
		$this->assertSame( [], $extensions->for_site( 5 ) );
	}
}
```

Ajouter à `tests/php/TestCase.php` :
- les imports `use MultisiteRadar\Install\Schema;` et `use MultisiteRadar\Storage\SiteRecord;` ;
- les deux méthodes suivantes :

```php
	/**
	 * Enregistre une ligne avec les propriétés données (par défaut : réseau courant, non marquée).
	 */
	protected function make_record( int $site_id, array $props = [] ): SiteRecord {
		$record             = new SiteRecord();
		$record->site_id    = $site_id;
		$record->network_id = get_current_network_id();
		$record->dirty      = false;
		foreach ( $props as $property => $value ) {
			$record->$property = $value;
		}
		$this->plugin()->sites()->save( $record );
		if ( ! $record->dirty ) {
			$this->plugin()->sites()->clear_dirty( $site_id );
		}
		return $record;
	}

	protected function mark_all_clean(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty = 0, dirty_since = NULL', Schema::sites_table() ) );
	}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'SchemaTest|SitesRepositoryTest|ExtensionsRepositoryTest'`
Expected: échec avec `Class "MultisiteRadar\Install\Schema" not found`.

- [ ] **Step 3: Implémenter `Schema`**

`includes/Install/Schema.php` :

```php
<?php
namespace MultisiteRadar\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Tables réseau du plugin (créées et mises à jour par dbDelta).
 */
final class Schema {

	public const VERSION = 1;
	public const OPTION  = 'msradar_db_version';

	public static function sites_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_sites';
	}

	public static function extensions_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_site_extensions';
	}

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return [ self::sites_table(), self::extensions_table() ];
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sites           = self::sites_table();
		$extensions      = self::extensions_table();

		dbDelta(
			"CREATE TABLE {$sites} (
site_id bigint(20) unsigned NOT NULL,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
name varchar(255) NOT NULL DEFAULT '',
url varchar(255) NOT NULL DEFAULT '',
is_public tinyint(1) NOT NULL DEFAULT 1,
is_archived tinyint(1) NOT NULL DEFAULT 0,
is_spam tinyint(1) NOT NULL DEFAULT 0,
is_deleted tinyint(1) NOT NULL DEFAULT 0,
theme_stylesheet varchar(191) NOT NULL DEFAULT '',
theme_template varchar(191) NOT NULL DEFAULT '',
users_count int(10) unsigned NOT NULL DEFAULT 0,
admins_count int(10) unsigned NOT NULL DEFAULT 0,
content_count int(10) unsigned NOT NULL DEFAULT 0,
media_count int(10) unsigned NOT NULL DEFAULT 0,
disk_bytes bigint(20) unsigned DEFAULT NULL,
disk_is_estimate tinyint(1) NOT NULL DEFAULT 0,
db_bytes bigint(20) unsigned DEFAULT NULL,
autoload_bytes bigint(20) unsigned DEFAULT NULL,
last_activity_gmt datetime DEFAULT NULL,
alert_level tinyint(3) unsigned NOT NULL DEFAULT 0,
alerts_count smallint(5) unsigned NOT NULL DEFAULT 0,
alert_rules varchar(255) NOT NULL DEFAULT '',
registry_status varchar(20) NOT NULL DEFAULT 'missing',
data longtext NULL,
dirty tinyint(1) NOT NULL DEFAULT 1,
dirty_since datetime DEFAULT NULL,
scanned_at datetime DEFAULT NULL,
PRIMARY KEY  (site_id),
KEY network_id (network_id),
KEY alert_level (alert_level),
KEY last_activity_gmt (last_activity_gmt),
KEY dirty (dirty,dirty_since),
KEY theme_stylesheet (theme_stylesheet),
KEY scanned_at (scanned_at)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$extensions} (
site_id bigint(20) unsigned NOT NULL,
type varchar(10) NOT NULL,
slug varchar(191) NOT NULL,
role varchar(10) NOT NULL DEFAULT '',
PRIMARY KEY  (site_id,type,slug),
KEY type_slug (type,slug)
) {$charset_collate};"
		);

		update_site_option( self::OPTION, self::VERSION );
	}

	public static function is_current(): bool {
		return (int) get_site_option( self::OPTION, 0 ) >= self::VERSION;
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_site_option( self::OPTION );
	}
}
```

Le format exigé par `dbDelta` impose : une colonne par ligne, deux espaces après `PRIMARY KEY`, pas d'espace dans les listes de colonnes des index. Ne pas reformater ces chaînes.

- [ ] **Step 4: Implémenter `SiteRecord`**

`includes/Storage/SiteRecord.php` :

```php
<?php
namespace MultisiteRadar\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Une ligne de la table msradar_sites.
 */
final class SiteRecord {

	private const INT_FIELDS             = [ 'site_id', 'network_id', 'users_count', 'admins_count', 'content_count', 'media_count', 'alert_level', 'alerts_count' ];
	private const NULLABLE_INT_FIELDS    = [ 'disk_bytes', 'db_bytes', 'autoload_bytes' ];
	private const BOOL_FIELDS            = [ 'is_public', 'is_archived', 'is_spam', 'is_deleted', 'disk_is_estimate', 'dirty' ];
	private const STRING_FIELDS          = [ 'name', 'url', 'theme_stylesheet', 'theme_template', 'alert_rules', 'registry_status' ];
	private const NULLABLE_STRING_FIELDS = [ 'last_activity_gmt', 'dirty_since', 'scanned_at' ];

	public int $site_id               = 0;
	public int $network_id            = 1;
	public string $name               = '';
	public string $url                = '';
	public bool $is_public            = true;
	public bool $is_archived          = false;
	public bool $is_spam              = false;
	public bool $is_deleted           = false;
	public string $theme_stylesheet   = '';
	public string $theme_template     = '';
	public int $users_count           = 0;
	public int $admins_count          = 0;
	public int $content_count         = 0;
	public int $media_count           = 0;
	public ?int $disk_bytes           = null;
	public bool $disk_is_estimate     = false;
	public ?int $db_bytes             = null;
	public ?int $autoload_bytes       = null;
	public ?string $last_activity_gmt = null;
	public int $alert_level           = 0;
	public int $alerts_count          = 0;
	public string $alert_rules        = '';
	public string $registry_status    = 'missing';
	public array $data                = [];
	public bool $dirty                = true;
	public ?string $dirty_since       = null;
	public ?string $scanned_at        = null;

	public static function from_row( array $row ): self {
		$record = new self();
		foreach ( self::INT_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (int) $row[ $field ];
			}
		}
		foreach ( self::NULLABLE_INT_FIELDS as $field ) {
			$record->$field = isset( $row[ $field ] ) ? (int) $row[ $field ] : null;
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (bool) (int) $row[ $field ];
			}
		}
		foreach ( self::STRING_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (string) $row[ $field ];
			}
		}
		foreach ( self::NULLABLE_STRING_FIELDS as $field ) {
			$record->$field = isset( $row[ $field ] ) ? (string) $row[ $field ] : null;
		}
		$data         = isset( $row['data'] ) ? json_decode( (string) $row['data'], true ) : null;
		$record->data = is_array( $data ) ? $data : [];
		return $record;
	}

	public function to_row(): array {
		$row = [];
		foreach ( array_merge( self::INT_FIELDS, self::NULLABLE_INT_FIELDS, self::STRING_FIELDS, self::NULLABLE_STRING_FIELDS ) as $field ) {
			$row[ $field ] = $this->$field;
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			$row[ $field ] = $this->$field ? 1 : 0;
		}
		$row['data'] = wp_json_encode( $this->data );
		return $row;
	}

	/**
	 * @return string[]
	 */
	public function alert_rule_ids(): array {
		return array_values( array_filter( explode( ',', $this->alert_rules ) ) );
	}
}
```

- [ ] **Step 5: Implémenter `SitesRepository`**

`includes/Storage/SitesRepository.php` :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le SQL de la table msradar_sites.
 */
final class SitesRepository {

	public function find( int $site_id ): ?SiteRecord {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d', Schema::sites_table(), $site_id ), ARRAY_A );
		return is_array( $row ) ? SiteRecord::from_row( $row ) : null;
	}

	/**
	 * @param int[] $site_ids
	 * @return array<int, SiteRecord> Indexés par site_id, dans l'ordre croissant.
	 */
	public function find_many( array $site_ids ): array {
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return [];
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY site_id ASC',
				array_merge( [ Schema::sites_table() ], $ids )
			),
			ARRAY_A
		);
		$records = [];
		foreach ( (array) $rows as $row ) {
			$record                      = SiteRecord::from_row( $row );
			$records[ $record->site_id ] = $record;
		}
		return $records;
	}

	public function exists( int $site_id ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id = %d', Schema::sites_table(), $site_id ) );
	}

	/**
	 * Insère ou met à jour une ligne. Sur une ligne existante, dirty et dirty_since ne sont jamais écrasés :
	 * un marquage posé pendant l'analyse du site survit à l'enregistrement du résultat.
	 */
	public function save( SiteRecord $record ): void {
		global $wpdb;
		$row = $record->to_row();
		if ( $this->exists( $record->site_id ) ) {
			unset( $row['site_id'], $row['dirty'], $row['dirty_since'] );
			$wpdb->update( Schema::sites_table(), $row, [ 'site_id' => $record->site_id ] );
			return;
		}
		$wpdb->insert( Schema::sites_table(), $row );
	}

	public function save_alerts( SiteRecord $record ): void {
		global $wpdb;
		$wpdb->update(
			Schema::sites_table(),
			[
				'alert_level'  => $record->alert_level,
				'alerts_count' => $record->alerts_count,
				'alert_rules'  => $record->alert_rules,
				'data'         => wp_json_encode( $record->data ),
			],
			[ 'site_id' => $record->site_id ]
		);
	}

	public function delete( int $site_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::sites_table(), [ 'site_id' => $site_id ], [ '%d' ] );
	}

	public function insert_pending( int $site_id, int $network_id, string $url ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (site_id, network_id, url, dirty, dirty_since) VALUES (%d, %d, %s, 1, %s)',
				Schema::sites_table(),
				$site_id,
				$network_id,
				$url,
				self::now()
			)
		);
	}

	/**
	 * Insère une ligne « en attente » pour chaque site du réseau qui n'en a pas encore.
	 *
	 * @return int Nombre de lignes insérées.
	 */
	public function seed_from_blogs( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (site_id, network_id, url, dirty, dirty_since) SELECT blog_id, site_id, CONCAT(domain, path), 1, %s FROM %i WHERE site_id = %d',
				Schema::sites_table(),
				self::now(),
				$wpdb->blogs,
				$network_id
			)
		);
	}

	public function delete_orphans(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE s FROM %i s LEFT JOIN %i b ON b.blog_id = s.site_id WHERE b.blog_id IS NULL', Schema::sites_table(), $wpdb->blogs )
		);
	}

	/**
	 * @param int[] $site_ids
	 */
	public function mark_dirty( array $site_ids ): int {
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET dirty = 1, dirty_since = COALESCE(dirty_since, %s) WHERE site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ Schema::sites_table(), self::now() ], $ids )
			)
		);
	}

	public function mark_all_dirty( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET dirty = 1, dirty_since = COALESCE(dirty_since, %s) WHERE network_id = %d', Schema::sites_table(), self::now(), $network_id )
		);
	}

	public function clear_dirty( int $site_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty = 0, dirty_since = NULL WHERE site_id = %d', Schema::sites_table(), $site_id ) );
	}

	/**
	 * @return int[] Sites à analyser, les plus anciennement marqués d'abord.
	 */
	public function next_dirty( int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY dirty_since ASC, site_id ASC LIMIT %d', Schema::sites_table(), $limit ) )
		);
	}

	/**
	 * @return int[]
	 */
	public function dirty_ids(): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY site_id ASC', Schema::sites_table() ) )
		);
	}

	public function count_dirty(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE dirty = 1', Schema::sites_table() ) );
	}

	public function count_all( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE network_id = %d', Schema::sites_table(), $network_id ) );
	}

	public function count_pending( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE network_id = %d AND scanned_at IS NULL', Schema::sites_table(), $network_id ) );
	}

	public function update_last_activity( int $site_id, string $gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_activity_gmt = %s WHERE site_id = %d AND (last_activity_gmt IS NULL OR last_activity_gmt < %s)',
				Schema::sites_table(),
				$gmt,
				$site_id,
				$gmt
			)
		);
	}

	/**
	 * @return int[] Identifiants strictement supérieurs à $after_id, croissants.
	 */
	public function ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id > %d ORDER BY site_id ASC LIMIT %d', Schema::sites_table(), $after_id, $limit ) )
		);
	}

	/**
	 * @param array<int|string> $values
	 * @return int[] Identifiants positifs uniques.
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $values ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}
}
```

- [ ] **Step 6: Implémenter `ExtensionsRepository`**

`includes/Storage/ExtensionsRepository.php` :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins activés localement et thèmes utilisés, site par site.
 */
final class ExtensionsRepository {

	public const TYPE_PLUGIN = 'plugin';
	public const TYPE_THEME  = 'theme';

	/**
	 * @param string[] $local_plugins Fichiers des plugins activés sur ce site seulement.
	 */
	public function replace_for_site( int $site_id, array $local_plugins, string $stylesheet, string $template ): void {
		global $wpdb;
		$table = Schema::extensions_table();
		$wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%d' ] );

		$rows = [];
		foreach ( array_unique( array_filter( $local_plugins, 'is_string' ) ) as $file ) {
			$rows[] = [ self::TYPE_PLUGIN, $file, 'local' ];
		}
		if ( '' !== $stylesheet ) {
			$rows[] = [ self::TYPE_THEME, $stylesheet, 'active' ];
		}
		if ( '' !== $template && $template !== $stylesheet ) {
			$rows[] = [ self::TYPE_THEME, $template, 'parent' ];
		}

		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				[
					'site_id' => $site_id,
					'type'    => $row[0],
					'slug'    => substr( $row[1], 0, 191 ),
					'role'    => $row[2],
				],
				[ '%d', '%s', '%s', '%s' ]
			);
		}
	}

	public function delete_for_site( int $site_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::extensions_table(), [ 'site_id' => $site_id ], [ '%d' ] );
	}

	/**
	 * @return array<int, array{type: string, slug: string, role: string}>
	 */
	public function for_site( int $site_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT type, slug, role FROM %i WHERE site_id = %d ORDER BY type ASC, slug ASC', Schema::extensions_table(), $site_id ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => [
				'type' => (string) $row['type'],
				'slug' => (string) $row['slug'],
				'role' => (string) $row['role'],
			],
			(array) $rows
		);
	}
}
```

- [ ] **Step 7: Brancher dans le conteneur et créer les tables pour les tests**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Storage\ExtensionsRepository;` et `use MultisiteRadar\Storage\SitesRepository;` ;
- ajouter les propriétés `private ?SitesRepository $sites = null;` et `private ?ExtensionsRepository $extensions = null;` ;
- ajouter les méthodes :

```php
	public function sites(): SitesRepository {
		return $this->sites ??= new SitesRepository();
	}

	public function extensions(): ExtensionsRepository {
		return $this->extensions ??= new ExtensionsRepository();
	}
```

À la fin de `tests/php/bootstrap.php`, ajouter ceci. Les tables sont créées une fois, hors transaction, donc ce sont de vraies tables InnoDB : chaque test annule ses écritures.

```php
MultisiteRadar\Install\Schema::install();
```

- [ ] **Step 8: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`, toutes les suites passent.

- [ ] **Step 9: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add network tables and storage repositories"
```

---

### Task 4: Installation, activation et mise à niveau

**Files:**
- Create: `includes/Install/Installer.php`
- Modify: `multisite-radar.php`, `includes/Plugin.php`
- Test: `tests/php/Install/InstallerTest.php`

**Interfaces:**
- Consumes: `Schema::install()`, `Schema::is_current()`, `Schema::VERSION`, `Schema::OPTION`, `Plugin::sites()`, `SitesRepository::seed_from_blogs()`.
- Produces :
  - `Installer::activate( $network_wide = false ): void` ;
  - `Installer::deactivate(): void` ;
  - `Installer::maybe_upgrade(): void` ;
  - actions `msradar_activated( bool $network_wide )`, `msradar_deactivated()`, `msradar_upgraded( int $version )`. La file d'analyse (tâche 10) et la migration 1.x (tâche 13) s'y abonnent.

- [ ] **Step 1: Écrire le test qui échoue**

`tests/php/Install/InstallerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class InstallerTest extends TestCase {

	public function test_activate_installs_seeds_and_announces(): void {
		$site_id = self::factory()->blog->create();
		delete_site_option( Schema::OPTION );
		$calls = [];
		add_action(
			'msradar_activated',
			static function ( bool $network_wide ) use ( &$calls ): void {
				$calls[] = $network_wide;
			}
		);

		Installer::activate( true );

		$this->assertTrue( Schema::is_current() );
		$this->assertNotNull( $this->plugin()->sites()->find( $site_id ) );
		$this->assertSame( [ true ], $calls );
	}

	public function test_maybe_upgrade_runs_only_when_the_schema_is_outdated(): void {
		$runs = 0;
		add_action(
			'msradar_upgraded',
			static function () use ( &$runs ): void {
				++$runs;
			}
		);

		update_site_option( Schema::OPTION, Schema::VERSION );
		Installer::maybe_upgrade();
		$this->assertSame( 0, $runs );

		update_site_option( Schema::OPTION, 0 );
		Installer::maybe_upgrade();
		$this->assertSame( 1, $runs );
		$this->assertTrue( Schema::is_current() );
	}

	public function test_deactivate_announces(): void {
		$before = did_action( 'msradar_deactivated' );

		Installer::deactivate();

		$this->assertSame( $before + 1, did_action( 'msradar_deactivated' ) );
	}

	public function test_upgrade_is_hooked_on_admin_init(): void {
		$this->assertNotFalse( has_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] ) );
	}
}
```

- [ ] **Step 2: Lancer le test pour le voir échouer**

Run: `bin/test.sh --filter InstallerTest`
Expected: échec avec `Class "MultisiteRadar\Install\Installer" not found`.

- [ ] **Step 3: Implémenter `Installer`**

`includes/Install/Installer.php` :

```php
<?php
namespace MultisiteRadar\Install;

use MultisiteRadar\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Activation, désactivation et mise à niveau du schéma.
 */
final class Installer {

	/**
	 * @param bool $network_wide Activation sur tout le réseau (passé par WordPress).
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! is_multisite() ) {
			return;
		}
		self::install();
		do_action( 'msradar_activated', (bool) $network_wide );
	}

	public static function deactivate(): void {
		do_action( 'msradar_deactivated' );
	}

	public static function maybe_upgrade(): void {
		if ( Schema::is_current() ) {
			return;
		}
		self::install();
		do_action( 'msradar_upgraded', Schema::VERSION );
	}

	private static function install(): void {
		Schema::install();
		Plugin::instance()->sites()->seed_from_blogs( get_current_network_id() );
	}
}
```

- [ ] **Step 4: Brancher les hooks**

Dans `multisite-radar.php`, insérer juste avant `MultisiteRadar\Plugin::instance()->boot();` :

```php
register_activation_hook( __FILE__, [ MultisiteRadar\Install\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ MultisiteRadar\Install\Installer::class, 'deactivate' ] );
```

Dans `includes/Plugin.php` :
- ajouter l'import `use MultisiteRadar\Install\Installer;` ;
- dans `boot()`, à la suite de `Capabilities::register();`, ajouter :

```php
		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 6: Vérifier dans le WordPress local**

```bash
cd /home/dev/wp
wp plugin deactivate multisite-radar --network && wp plugin activate multisite-radar --network
wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}msradar_sites" ), " ", get_site_option( "msradar_db_version" ), PHP_EOL;'
cd /home/dev/wp-network-plugin-utilities
```

Expected : `1 1`, soit une ligne pour le site principal et la version de schéma 1.

- [ ] **Step 7: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: install schema on activation and upgrade on admin_init"
```

---

### Task 5: Empreinte et origine des enregistrements

**Files:**
- Create: `includes/Collector/Fingerprint.php`, `includes/Collector/OriginResolver.php`
- Test: `tests/php/Collector/FingerprintTest.php`, `tests/php/Collector/OriginResolverTest.php`

**Interfaces:**
- Consumes: constantes `MSRADAR_VERSION`, `MSRADAR_DIR`.
- Produces :
  - **`Fingerprint`** :
    - `compute( string[] $active_plugins, string[] $network_plugins, string $stylesheet, string $template, string $wp_version, string $plugin_version ): string` (md5, ne dépend pas de l'ordre des plugins) ;
    - `current(): string` (site courant).
  - **`OriginResolver`** :
    - `__construct( string $plugin_dir, string $mu_plugin_dir, string[] $theme_dirs, string $self_dir, array<string,string> $path_aliases = [] )` ;
    - `from_environment(): self` ;
    - `resolve_file( string $file ): ?array{kind: string, slug: string}` ;
    - `from_backtrace( array $frames ): ?array{kind: string, slug: string}`.
    - `kind` vaut `plugin`, `mu-plugin` ou `theme`. `null` signifie qu'aucun fichier tiers n'a été trouvé.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Collector/FingerprintTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Tests\TestCase;

final class FingerprintTest extends TestCase {

	public function test_plugin_order_does_not_matter(): void {
		$a = Fingerprint::compute( [ 'b/b.php', 'a/a.php' ], [ 'n/n.php', 'm/m.php' ], 'child', 'parent', '7.1.2', '2.0.0' );
		$b = Fingerprint::compute( [ 'a/a.php', 'b/b.php' ], [ 'm/m.php', 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' );

		$this->assertSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $a );
	}

	public function test_any_input_changes_the_fingerprint(): void {
		$base     = [ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' ];
		$variants = [
			[ [ 'a/a.php', 'b/b.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [], 'child', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'other', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'other', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.2.0', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.1' ],
		];
		foreach ( $variants as $variant ) {
			$this->assertNotSame( Fingerprint::compute( ...$base ), Fingerprint::compute( ...$variant ) );
		}
	}

	public function test_current_reads_the_current_site(): void {
		global $wp_version;
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		update_option( 'active_plugins', [ 'acme/acme.php' ] );

		$expected = Fingerprint::compute(
			[ 'acme/acme.php' ],
			array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ),
			(string) get_option( 'stylesheet' ),
			(string) get_option( 'template' ),
			$wp_version,
			MSRADAR_VERSION
		);
		$actual   = Fingerprint::current();
		restore_current_blog();

		$this->assertSame( $expected, $actual );
	}
}
```

`tests/php/Collector/OriginResolverTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\OriginResolver;
use MultisiteRadar\Tests\TestCase;

final class OriginResolverTest extends TestCase {

	private OriginResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new OriginResolver(
			'/srv/wp/wp-content/plugins',
			'/srv/wp/wp-content/mu-plugins',
			[ '/srv/wp/wp-content/themes', '/srv/extra-themes/' ],
			'/srv/wp/wp-content/plugins/multisite-radar',
			[ '/srv/real/acme' => '/srv/wp/wp-content/plugins/acme' ]
		);
	}

	/**
	 * @dataProvider files
	 */
	public function test_resolve_file( string $file, ?array $expected ): void {
		$this->assertSame( $expected, $this->resolver->resolve_file( $file ) );
	}

	public static function files(): array {
		return [
			'plugin in a folder'       => [ '/srv/wp/wp-content/plugins/events/inc/cpt.php', [ 'kind' => 'plugin', 'slug' => 'events' ] ],
			'single-file plugin'       => [ '/srv/wp/wp-content/plugins/hello.php', [ 'kind' => 'plugin', 'slug' => 'hello' ] ],
			'single-file mu-plugin'    => [ '/srv/wp/wp-content/mu-plugins/loader.php', [ 'kind' => 'mu-plugin', 'slug' => 'loader' ] ],
			'mu-plugin in a folder'    => [ '/srv/wp/wp-content/mu-plugins/acme-core/x.php', [ 'kind' => 'mu-plugin', 'slug' => 'acme-core' ] ],
			'theme in a second root'   => [ '/srv/extra-themes/child/functions.php', [ 'kind' => 'theme', 'slug' => 'child' ] ],
			'symlinked plugin'         => [ '/srv/real/acme/acme.php', [ 'kind' => 'plugin', 'slug' => 'acme' ] ],
			'this plugin is ignored'   => [ '/srv/wp/wp-content/plugins/multisite-radar/includes/Plugin.php', null ],
			'core file'                => [ '/srv/wp/wp-includes/post.php', null ],
		];
	}

	public function test_from_backtrace_returns_the_first_third_party_frame(): void {
		$frames = [
			[ 'file' => '/srv/wp/wp-includes/post.php' ],
			[ 'function' => 'closure' ],
			[ 'file' => '/srv/wp/wp-content/plugins/multisite-radar/includes/Collector/RegistryProbe.php' ],
			[ 'file' => '/srv/wp/wp-content/plugins/events/events.php' ],
			[ 'file' => '/srv/wp/wp-content/themes/child/functions.php' ],
		];

		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'events' ], $this->resolver->from_backtrace( $frames ) );
		$this->assertNull( $this->resolver->from_backtrace( [ [ 'file' => '/srv/wp/wp-settings.php' ] ] ) );
	}

	public function test_from_environment_uses_wordpress_directories(): void {
		$resolver = OriginResolver::from_environment();

		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'akismet' ], $resolver->resolve_file( WP_PLUGIN_DIR . '/akismet/akismet.php' ) );
		$this->assertSame( [ 'kind' => 'theme', 'slug' => 'twentytwentyfive' ], $resolver->resolve_file( get_theme_root() . '/twentytwentyfive/functions.php' ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'FingerprintTest|OriginResolverTest'`
Expected: échec avec `Class "MultisiteRadar\Collector\Fingerprint" not found`.

- [ ] **Step 3: Implémenter `Fingerprint`**

`includes/Collector/Fingerprint.php` :

```php
<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Empreinte de ce qui détermine les types enregistrés d'un site : plugins, thème, versions.
 */
final class Fingerprint {

	/**
	 * @param string[] $active_plugins  Plugins actifs du site.
	 * @param string[] $network_plugins Plugins activés sur le réseau.
	 */
	public static function compute( array $active_plugins, array $network_plugins, string $stylesheet, string $template, string $wp_version, string $plugin_version ): string {
		$active_plugins  = array_values( array_map( 'strval', $active_plugins ) );
		$network_plugins = array_values( array_map( 'strval', $network_plugins ) );
		sort( $active_plugins );
		sort( $network_plugins );

		return md5( (string) wp_json_encode( [ $active_plugins, $network_plugins, $stylesheet, $template, $wp_version, $plugin_version ] ) );
	}

	public static function current(): string {
		global $wp_version;
		$active  = get_option( 'active_plugins', [] );
		$network = get_site_option( 'active_sitewide_plugins', [] );

		return self::compute(
			is_array( $active ) ? array_filter( $active, 'is_string' ) : [],
			is_array( $network ) ? array_keys( $network ) : [],
			(string) get_option( 'stylesheet', '' ),
			(string) get_option( 'template', '' ),
			(string) $wp_version,
			MSRADAR_VERSION
		);
	}
}
```

- [ ] **Step 4: Implémenter `OriginResolver`**

`includes/Collector/OriginResolver.php` :

```php
<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Déduit l'origine (plugin, mu-plugin, thème) d'un fichier PHP ou d'une pile d'appels.
 */
final class OriginResolver {

	private string $plugin_dir;
	private string $mu_plugin_dir;
	/** @var string[] */
	private array $theme_dirs;
	private string $self_dir;
	/** @var array<string, string> Chemin réel => chemin vu par WordPress (plugins en symlink). */
	private array $path_aliases = [];

	/**
	 * @param string[]              $theme_dirs   Racines de thèmes.
	 * @param array<string, string> $path_aliases Chemin réel => chemin sous WP_PLUGIN_DIR.
	 */
	public function __construct( string $plugin_dir, string $mu_plugin_dir, array $theme_dirs, string $self_dir, array $path_aliases = [] ) {
		$this->plugin_dir    = self::dir( $plugin_dir );
		$this->mu_plugin_dir = self::dir( $mu_plugin_dir );
		$this->theme_dirs    = array_map( [ self::class, 'dir' ], array_values( $theme_dirs ) );
		$this->self_dir      = self::dir( $self_dir );
		foreach ( $path_aliases as $real => $alias ) {
			$this->path_aliases[ self::dir( (string) $real ) ] = self::dir( (string) $alias );
		}
	}

	public static function from_environment(): self {
		global $wp_theme_directories, $wp_plugin_paths;
		$theme_dirs = is_array( $wp_theme_directories ) && [] !== $wp_theme_directories ? $wp_theme_directories : [ get_theme_root() ];
		$aliases    = [];
		foreach ( (array) $wp_plugin_paths as $dir => $real_dir ) {
			$aliases[ (string) $real_dir ] = (string) $dir;
		}
		return new self( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, $theme_dirs, MSRADAR_DIR, $aliases );
	}

	/**
	 * @return array{kind: string, slug: string}|null
	 */
	public function resolve_file( string $file ): ?array {
		$file = wp_normalize_path( $file );
		if ( 0 === strpos( $file, $this->self_dir ) ) {
			return null;
		}
		foreach ( $this->path_aliases as $real => $alias ) {
			if ( 0 === strpos( $file, $real ) ) {
				$file = $alias . substr( $file, strlen( $real ) );
				break;
			}
		}
		if ( 0 === strpos( $file, $this->self_dir ) ) {
			return null;
		}
		if ( 0 === strpos( $file, $this->mu_plugin_dir ) ) {
			return self::origin( 'mu-plugin', substr( $file, strlen( $this->mu_plugin_dir ) ) );
		}
		if ( 0 === strpos( $file, $this->plugin_dir ) ) {
			return self::origin( 'plugin', substr( $file, strlen( $this->plugin_dir ) ) );
		}
		foreach ( $this->theme_dirs as $theme_dir ) {
			if ( 0 === strpos( $file, $theme_dir ) ) {
				return self::origin( 'theme', substr( $file, strlen( $theme_dir ) ) );
			}
		}
		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $frames Résultat de debug_backtrace().
	 * @return array{kind: string, slug: string}|null
	 */
	public function from_backtrace( array $frames ): ?array {
		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}
			$origin = $this->resolve_file( $frame['file'] );
			if ( null !== $origin ) {
				return $origin;
			}
		}
		return null;
	}

	/**
	 * @return array{kind: string, slug: string}
	 */
	private static function origin( string $kind, string $relative ): array {
		$parts = explode( '/', ltrim( $relative, '/' ) );
		$slug  = 1 === count( $parts ) ? (string) preg_replace( '/\.php$/', '', $parts[0] ) : $parts[0];
		return [
			'kind' => $kind,
			'slug' => $slug,
		];
	}

	private static function dir( string $dir ): string {
		return trailingslashit( wp_normalize_path( $dir ) );
	}
}
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 6: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add registry fingerprint and origin resolver"
```

---
### Task 6: Relevé du registre dans le contexte du site (RegistryProbe)

**Files:**
- Create: `includes/Collector/RegistryProbe.php`, `tests/php/fixtures/plugins/fixture-events/fixture-events.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Collector/RegistryProbeTest.php`

**Interfaces:**
- Consumes: `Fingerprint::current()`, `OriginResolver` (tâche 5), `SitesRepository::mark_dirty()` (tâche 3).
- Produces :
  - **Constantes de `RegistryProbe`** : `OPTION = 'msradar_registry'`, `CRON_HOOK = 'msradar_probe'`, `MAX_AGE = WEEK_IN_SECONDS`, `STATUS_FRESH = 'fresh'`, `STATUS_STALE = 'stale'`, `STATUS_MISSING = 'missing'`.
  - **Méthodes de `RegistryProbe`** :
    - `__construct( SitesRepository $sites, ?OriginResolver $resolver = null )` ;
    - `register(): void` ;
    - `start_tracking(): void` ;
    - `schedule(): void` ;
    - `run(): void` ;
    - `build(): array` ;
    - `static status( $registry, string $fingerprint, int $now ): string` ;
    - `static is_due( $registry, string $fingerprint, int $now ): bool`.
  - **Format de l'option `msradar_registry`** :
    ```
    {
      fingerprint: string,
      built_at: int,
      post_types: { <name>: { label, public, show_ui, builtin, origin: { kind, slug } } },
      taxonomies: { <name>: { …mêmes clés… } }
    }
    ```
    `origin.kind` ∈ `core`, `plugin`, `mu-plugin`, `theme`, `unknown`.
  - **`Plugin`** : `probe(): RegistryProbe`, appelé dans `boot()`.

- [ ] **Step 1: Créer la fixture et écrire les tests qui échouent**

`tests/php/fixtures/plugins/fixture-events/fixture-events.php` :

```php
<?php
// Plugin fictif pour RegistryProbeTest : enregistre un type et une taxonomie depuis un fichier situé dans un dossier « plugins ».
function msradar_fixture_register_types(): void {
	register_post_type(
		'fixture_event',
		[
			'label'  => 'Fixture events',
			'public' => true,
		]
	);
	register_taxonomy( 'fixture_genre', 'fixture_event', [ 'label' => 'Fixture genres' ] );
}
```

`tests/php/Collector/RegistryProbeTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Collector\OriginResolver;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Tests\TestCase;

final class RegistryProbeTest extends TestCase {

	public function tear_down(): void {
		foreach ( [ 'fixture_event', 'test_untracked' ] as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		if ( taxonomy_exists( 'fixture_genre' ) ) {
			unregister_taxonomy( 'fixture_genre' );
		}
		parent::tear_down();
	}

	private static function fixture_dir(): string {
		return dirname( __DIR__ ) . '/fixtures/plugins';
	}

	private function probe(): RegistryProbe {
		return new RegistryProbe(
			$this->plugin()->sites(),
			new OriginResolver( self::fixture_dir(), '/nonexistent/mu-plugins', [ '/nonexistent/themes' ], '/nonexistent/self' )
		);
	}

	public function test_status_and_due(): void {
		$now   = 1800000000;
		$fresh = [
			'fingerprint' => 'abc',
			'built_at'    => $now - 10,
		];

		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( false, 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( 'corrupt', 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( [ 'fingerprint' => 'abc' ], 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_FRESH, RegistryProbe::status( $fresh, 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_STALE, RegistryProbe::status( $fresh, 'other', $now ) );
		$this->assertSame(
			RegistryProbe::STATUS_STALE,
			RegistryProbe::status(
				[
					'fingerprint' => 'abc',
					'built_at'    => $now - WEEK_IN_SECONDS - 1,
				],
				'abc',
				$now
			)
		);
		$this->assertTrue( RegistryProbe::is_due( false, 'abc', $now ) );
		$this->assertFalse( RegistryProbe::is_due( $fresh, 'abc', $now ) );
	}

	public function test_build_records_labels_and_origins_of_tracked_registrations(): void {
		$probe = $this->probe();
		$probe->start_tracking();
		require_once self::fixture_dir() . '/fixture-events/fixture-events.php';
		msradar_fixture_register_types();
		register_post_type( 'test_untracked', [ 'label' => 'Untracked' ] );

		$registry = $probe->build();

		$this->assertSame( Fingerprint::current(), $registry['fingerprint'] );
		$this->assertEqualsWithDelta( time(), $registry['built_at'], 5 );
		$this->assertSame( 'Fixture events', $registry['post_types']['fixture_event']['label'] );
		$this->assertTrue( $registry['post_types']['fixture_event']['public'] );
		$this->assertFalse( $registry['post_types']['fixture_event']['builtin'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $registry['post_types']['fixture_event']['origin'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $registry['taxonomies']['fixture_genre']['origin'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $registry['post_types']['post']['origin'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $registry['taxonomies']['category']['origin'] );
		$this->assertSame( [ 'kind' => 'unknown', 'slug' => '' ], $registry['post_types']['test_untracked']['origin'] );
	}

	public function test_run_saves_an_autoloaded_option_and_marks_the_site(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$sites   = $this->plugin()->sites();
		$sites->insert_pending( $site_id, get_current_network_id(), 'probe.test/' );
		$sites->clear_dirty( $site_id );

		switch_to_blog( $site_id );
		$this->probe()->run();
		$registry = get_option( RegistryProbe::OPTION );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, RegistryProbe::OPTION ) );
		restore_current_blog();

		$this->assertIsArray( $registry );
		$this->assertArrayHasKey( 'post', $registry['post_types'] );
		$this->assertContains( $autoload, wp_autoload_values_to_autoload() );
		$this->assertTrue( $sites->find( $site_id )->dirty );
	}

	public function test_register_hooks_the_schedule_only_when_due(): void {
		delete_option( RegistryProbe::OPTION );
		$due = $this->probe();
		$due->register();
		$this->assertSame( 100, has_action( 'init', [ $due, 'schedule' ] ) );
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $due, 'run' ] ) );

		update_option(
			RegistryProbe::OPTION,
			[
				'fingerprint' => Fingerprint::current(),
				'built_at'    => time(),
			]
		);
		$fresh = $this->probe();
		$fresh->register();
		$this->assertFalse( has_action( 'init', [ $fresh, 'schedule' ] ) );
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $fresh, 'run' ] ) );
	}

	public function test_schedule_adds_a_single_event(): void {
		wp_clear_scheduled_hook( RegistryProbe::CRON_HOOK );
		$probe = $this->probe();

		$probe->schedule();
		$probe->schedule();

		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ RegistryProbe::CRON_HOOK ] ) ? count( $hooks[ RegistryProbe::CRON_HOOK ] ) : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_plugin_boot_registers_the_probe(): void {
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $this->plugin()->probe(), 'run' ] ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter RegistryProbeTest`
Expected: échec avec `Class "MultisiteRadar\Collector\RegistryProbe" not found`.

- [ ] **Step 3: Implémenter `RegistryProbe`**

`includes/Collector/RegistryProbe.php` :

```php
<?php
namespace MultisiteRadar\Collector;

use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Relève, dans le contexte du site lui-même, les types de contenu et taxonomies enregistrés
 * (libellé, visibilité, origine) et les stocke dans l'option autoloadée msradar_registry.
 * Ne s'exécute jamais pendant le rendu d'une page publique : uniquement en cron, en admin ou en WP-CLI.
 */
final class RegistryProbe {

	public const OPTION         = 'msradar_registry';
	public const CRON_HOOK      = 'msradar_probe';
	public const MAX_AGE        = WEEK_IN_SECONDS;
	public const STATUS_FRESH   = 'fresh';
	public const STATUS_STALE   = 'stale';
	public const STATUS_MISSING = 'missing';

	private SitesRepository $sites;
	private ?OriginResolver $resolver;
	/** @var array<string, array<string, array{kind: string, slug: string}|null>> */
	private array $origins  = [
		'post_type' => [],
		'taxonomy'  => [],
	];
	private bool $tracking = false;

	public function __construct( SitesRepository $sites, ?OriginResolver $resolver = null ) {
		$this->sites    = $sites;
		$this->resolver = $resolver;
	}

	/**
	 * Appelé au chargement du plugin, donc avant les plugins du site.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );

		$cli = defined( 'WP_CLI' ) && WP_CLI;
		$due = self::is_due( get_option( self::OPTION ), Fingerprint::current(), time() );

		if ( $cli || ( $due && ( wp_doing_cron() || is_admin() ) ) ) {
			$this->start_tracking();
		}
		if ( ! $due ) {
			return;
		}
		add_action( 'init', [ $this, 'schedule' ], 100 );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			add_action( 'admin_init', [ $this, 'run' ] );
		}
	}

	public function start_tracking(): void {
		if ( $this->tracking ) {
			return;
		}
		$this->tracking = true;
		add_filter( 'register_post_type_args', [ $this, 'track_post_type' ], 10, 2 );
		add_filter( 'register_taxonomy_args', [ $this, 'track_taxonomy' ], 10, 2 );
	}

	/**
	 * @param mixed $args Arguments d'enregistrement, renvoyés tels quels.
	 * @param mixed $name Nom du type de contenu.
	 * @return mixed
	 */
	public function track_post_type( $args, $name ) {
		$this->origins['post_type'][ (string) $name ] = $this->resolver()->from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- origin detection.
		return $args;
	}

	/**
	 * @param mixed $args Arguments d'enregistrement, renvoyés tels quels.
	 * @param mixed $name Nom de la taxonomie.
	 * @return mixed
	 */
	public function track_taxonomy( $args, $name ) {
		$this->origins['taxonomy'][ (string) $name ] = $this->resolver()->from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- origin detection.
		return $args;
	}

	public function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	public function run(): void {
		update_option( self::OPTION, $this->build(), true );
		$this->sites->mark_dirty( [ get_current_blog_id() ] );
	}

	public function build(): array {
		$post_types = [];
		foreach ( get_post_types( [], 'objects' ) as $name => $object ) {
			$post_types[ (string) $name ] = $this->describe( 'post_type', (string) $name, $object );
		}
		$taxonomies = [];
		foreach ( get_taxonomies( [], 'objects' ) as $name => $object ) {
			$taxonomies[ (string) $name ] = $this->describe( 'taxonomy', (string) $name, $object );
		}

		return [
			'fingerprint' => Fingerprint::current(),
			'built_at'    => time(),
			'post_types'  => $post_types,
			'taxonomies'  => $taxonomies,
		];
	}

	/**
	 * @param mixed $registry Valeur brute de l'option msradar_registry.
	 */
	public static function status( $registry, string $fingerprint, int $now ): string {
		if ( ! is_array( $registry ) || ! isset( $registry['fingerprint'], $registry['built_at'] ) ) {
			return self::STATUS_MISSING;
		}
		if ( $fingerprint !== $registry['fingerprint'] || (int) $registry['built_at'] < $now - self::MAX_AGE ) {
			return self::STATUS_STALE;
		}
		return self::STATUS_FRESH;
	}

	/**
	 * @param mixed $registry Valeur brute de l'option msradar_registry.
	 */
	public static function is_due( $registry, string $fingerprint, int $now ): bool {
		return self::STATUS_FRESH !== self::status( $registry, $fingerprint, $now );
	}

	/**
	 * @param \WP_Post_Type|\WP_Taxonomy $object Objet enregistré.
	 */
	private function describe( string $kind, string $name, $object ): array {
		$builtin = (bool) $object->_builtin;
		if ( $builtin ) {
			$origin = [
				'kind' => 'core',
				'slug' => '',
			];
		} else {
			$origin = $this->origins[ $kind ][ $name ] ?? [
				'kind' => 'unknown',
				'slug' => '',
			];
		}

		return [
			'label'   => (string) $object->label,
			'public'  => (bool) $object->public,
			'show_ui' => (bool) $object->show_ui,
			'builtin' => $builtin,
			'origin'  => $origin,
		];
	}

	private function resolver(): OriginResolver {
		return $this->resolver ??= OriginResolver::from_environment();
	}
}
```

Le résolveur est construit à la première utilisation. À ce moment, les racines de thèmes et les chemins des plugins liés par symlink sont connus de WordPress.

- [ ] **Step 4: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter l'import `use MultisiteRadar\Collector\RegistryProbe;` ;
- ajouter la propriété `private ?RegistryProbe $probe = null;` ;
- dans `boot()`, après la ligne `add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );`, ajouter :

```php
		$this->probe()->register();
```

- ajouter la méthode :

```php
	public function probe(): RegistryProbe {
		return $this->probe ??= new RegistryProbe( $this->sites() );
	}
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 6: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: probe each site's registered types in its own context"
```

---

### Task 7: Collecteur SQL (SiteCollector)

**Files:**
- Create: `includes/Collector/SiteCollector.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Collector/SiteCollectorTest.php`

**Interfaces:**
- Consumes :
  - `Settings::get( 'scan.activity_post_types' )` ;
  - `Fingerprint::compute()` ;
  - `RegistryProbe::status()`, `RegistryProbe::OPTION`, `RegistryProbe::STATUS_FRESH` ;
  - `SiteRecord`.
- Produces :
  - **`SiteCollector`** :
    - `__construct( Settings $settings )` ;
    - `collect( int $site_id ): ?SiteRecord`. Renvoie `null` si le site n'existe pas et lève `RuntimeException` si une requête échoue. L'enregistrement renvoyé a `dirty = false` et `scanned_at` = maintenant (GMT) ; les champs d'alerte restent vides (calculés à la tâche 8).
    - constantes `EXCLUDED_POST_TYPES`, `EXCLUDED_TAXONOMIES`.
  - **Filtres** : `msradar_excluded_post_types`, `msradar_excluded_taxonomies`.
  - **Contenu de `data`** :
    - `post_types[]` : `{ name, label, origin, builtin, verified, publish, total }` ;
    - `taxonomies[]` : `{ name, label, origin, builtin, verified, count }` ;
    - `users` : `{ by_role: {role: n}, privileged: [{id, login, roles[]}] }` ;
    - `plugins_local` : `string[]` ;
    - `last_content` : `{ id, type, title, date_gmt }` ou `null` ;
    - `options` : `{ blog_public, siteurl, home, locale }`.
  - **`Plugin`** : `collector(): SiteCollector`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Collector/SiteCollectorTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;
use RuntimeException;

final class SiteCollectorTest extends TestCase {

	public function tear_down(): void {
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
	}

	private function collect( int $site_id ): SiteRecord {
		$record = ( new SiteCollector( $this->plugin()->settings() ) )->collect( $site_id );
		$this->assertNotNull( $record );
		return $record;
	}

	private function find( array $items, string $name ): ?array {
		foreach ( $items as $item ) {
			if ( $name === $item['name'] ) {
				return $item;
			}
		}
		return null;
	}

	public function test_counts_content_media_and_terms(): void {
		$site_id = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$before  = $this->collect( $site_id );

		switch_to_blog( $site_id );
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create_many( 2, [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		self::factory()->post->create_many( 2, [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] );
		self::factory()->category->create_many( 2 );
		restore_current_blog();

		$after = $this->collect( $site_id );

		$this->assertSame( 'Blog RH', $after->name );
		$this->assertSame( $site_id, $after->site_id );
		$this->assertSame( 5, $after->content_count - $before->content_count );
		$this->assertSame( 2, $after->media_count - $before->media_count );
		$this->assertSame( 4, $this->find( $after->data['post_types'], 'post' )['total'] - $this->find( $before->data['post_types'], 'post' )['total'] );
		$this->assertSame( 2, $this->find( $after->data['taxonomies'], 'category' )['count'] - $this->find( $before->data['taxonomies'], 'category' )['count'] );
		$this->assertNull( $this->find( $after->data['post_types'], 'revision' ), 'Excluded types are not listed.' );
		$this->assertFalse( $after->dirty );
		$this->assertNotNull( $after->scanned_at );
	}

	public function test_counts_users_by_role_and_lists_privileged_accounts(): void {
		$site_id = self::factory()->blog->create();
		$before  = $this->collect( $site_id );
		$editor  = self::factory()->user->create( [ 'user_login' => 'radar-editor' ] );
		$reader  = self::factory()->user->create( [ 'user_login' => 'radar-reader' ] );
		add_user_to_blog( $site_id, $editor, 'editor' );
		add_user_to_blog( $site_id, $reader, 'subscriber' );

		$after  = $this->collect( $site_id );
		$logins = wp_list_pluck( $after->data['users']['privileged'], 'login' );

		$this->assertSame( 2, $after->users_count - $before->users_count );
		$this->assertSame( 1, ( $after->data['users']['by_role']['editor'] ?? 0 ) - ( $before->data['users']['by_role']['editor'] ?? 0 ) );
		$this->assertContains( 'radar-editor', $logins );
		$this->assertNotContains( 'radar-reader', $logins );
	}

	public function test_fresh_registry_provides_labels_origins_and_verification(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		self::factory()->post->create_many( 2, [ 'post_type' => 'fixture_event' ] );
		update_option(
			RegistryProbe::OPTION,
			[
				'fingerprint' => Fingerprint::current(),
				'built_at'    => time(),
				'post_types'  => [
					'fixture_event' => [
						'label'   => 'Fixture events',
						'public'  => true,
						'show_ui' => true,
						'builtin' => false,
						'origin'  => [
							'kind' => 'plugin',
							'slug' => 'fixture-events',
						],
					],
				],
				'taxonomies'  => [],
			]
		);
		restore_current_blog();

		$record = $this->collect( $site_id );
		$event  = $this->find( $record->data['post_types'], 'fixture_event' );

		$this->assertSame( RegistryProbe::STATUS_FRESH, $record->registry_status );
		$this->assertSame( 'Fixture events', $event['label'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $event['origin'] );
		$this->assertSame( 2, $event['publish'] );
		$this->assertTrue( $event['verified'] );
		$this->assertFalse( $event['builtin'] );
	}

	public function test_types_found_only_in_the_database_are_unverified(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$wpdb->insert(
			$wpdb->posts,
			[
				'post_type'             => 'legacy_thing',
				'post_status'           => 'publish',
				'post_title'            => 'Old',
				'post_content'          => '',
				'post_excerpt'          => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			]
		);
		restore_current_blog();

		$record = $this->collect( $site_id );
		$legacy = $this->find( $record->data['post_types'], 'legacy_thing' );
		$post   = $this->find( $record->data['post_types'], 'post' );

		$this->assertSame( RegistryProbe::STATUS_MISSING, $record->registry_status );
		$this->assertSame( 'legacy_thing', $legacy['label'] );
		$this->assertSame( [ 'kind' => 'unknown', 'slug' => '' ], $legacy['origin'] );
		$this->assertFalse( $legacy['verified'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $post['origin'] );
		$this->assertTrue( $post['builtin'] );
	}

	public function test_stale_registry_keeps_labels_but_is_unverified(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option(
			$site_id,
			RegistryProbe::OPTION,
			[
				'fingerprint' => 'outdated',
				'built_at'    => time(),
				'post_types'  => [
					'post' => [
						'label'   => 'Articles',
						'public'  => true,
						'show_ui' => true,
						'builtin' => true,
						'origin'  => [
							'kind' => 'core',
							'slug' => '',
						],
					],
				],
				'taxonomies'  => [],
			]
		);

		$record = $this->collect( $site_id );
		$post   = $this->find( $record->data['post_types'], 'post' );

		$this->assertSame( RegistryProbe::STATUS_STALE, $record->registry_status );
		$this->assertSame( 'Articles', $post['label'] );
		$this->assertFalse( $post['verified'] );
	}

	public function test_reads_theme_local_plugins_and_locale(): void {
		$site_id = self::factory()->blog->create();
		update_site_option( 'active_sitewide_plugins', [ 'netwide/netwide.php' => time() ] );
		update_blog_option( $site_id, 'active_plugins', [ 'acme/acme.php', 'netwide/netwide.php' ] );
		update_blog_option( $site_id, 'stylesheet', 'child-theme' );
		update_blog_option( $site_id, 'template', 'parent-theme' );
		update_blog_option( $site_id, 'WPLANG', 'fr_FR' );

		$record = $this->collect( $site_id );

		$this->assertSame( [ 'acme/acme.php' ], $record->data['plugins_local'] );
		$this->assertSame( 'child-theme', $record->theme_stylesheet );
		$this->assertSame( 'parent-theme', $record->theme_template );
		$this->assertSame( 'fr_FR', $record->data['options']['locale'] );
	}

	public function test_last_activity_follows_the_configured_types(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$event_id = self::factory()->post->create(
			[
				'post_type'     => 'fixture_event',
				'post_date'     => '2025-06-01 10:00:00',
				'post_date_gmt' => '2025-06-01 08:00:00',
			]
		);
		restore_current_blog();
		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'fixture_event' ] ] ] );

		$record = $this->collect( $site_id );

		$this->assertSame( '2025-06-01 08:00:00', $record->last_activity_gmt );
		$this->assertSame( $event_id, $record->data['last_content']['id'] );
		$this->assertSame( 'fixture_event', $record->data['last_content']['type'] );
	}

	public function test_corrupted_or_empty_options_do_not_break_the_scan(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option( $site_id, 'active_plugins', 'not-an-array' );
		update_blog_option( $site_id, 'blogname', '' );
		update_blog_option( $site_id, RegistryProbe::OPTION, 'corrupt' );

		$record = $this->collect( $site_id );

		$this->assertSame( sprintf( 'Site #%d', $site_id ), $record->name );
		$this->assertSame( [], $record->data['plugins_local'] );
		$this->assertSame( RegistryProbe::STATUS_MISSING, $record->registry_status );
	}

	public function test_throws_when_site_tables_are_missing(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->get_blog_prefix( $site_id ) . 'posts' ) );

		$this->expectException( RuntimeException::class );
		( new SiteCollector( $this->plugin()->settings() ) )->collect( $site_id );
	}

	public function test_unknown_site_returns_null(): void {
		$this->assertNull( ( new SiteCollector( $this->plugin()->settings() ) )->collect( 999999 ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter SiteCollectorTest`
Expected: échec avec `Class "MultisiteRadar\Collector\SiteCollector" not found`.

- [ ] **Step 3: Implémenter `SiteCollector`**

`includes/Collector/SiteCollector.php` :

```php
<?php
namespace MultisiteRadar\Collector;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Construit un SiteRecord par requêtes SQL agrégées sur les tables d'un site, sans charger ses plugins.
 */
final class SiteCollector {

	public const EXCLUDED_POST_TYPES = [ 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ];
	public const EXCLUDED_TAXONOMIES = [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];

	private const CORE_POST_TYPES  = [ 'post', 'page', 'attachment' ];
	private const CORE_TAXONOMIES  = [ 'category', 'post_tag' ];
	private const DEFAULT_ROLES    = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
	private const PRIVILEGED_ROLES = [ 'administrator', 'editor' ];
	private const PRIVILEGED_LIMIT = 50;
	private const ZERO_DATE        = '0000-00-00 00:00:00';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @throws RuntimeException Si une requête sur les tables du site échoue.
	 */
	public function collect( int $site_id ): ?SiteRecord {
		global $wpdb;
		$site = get_site( $site_id );
		if ( null === $site ) {
			return null;
		}

		$prefix   = $wpdb->get_blog_prefix( $site_id );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$options = $this->read_options( $prefix );
			$counts  = $this->read_post_counts( $prefix );
			$terms   = $this->read_term_counts( $prefix );
			$last    = $this->read_last_content( $prefix );
			$users   = $this->read_users( $prefix, $this->role_names( $options[ $prefix . 'user_roles' ] ?? null ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
		$active_plugins  = isset( $options['active_plugins'] ) && is_array( $options['active_plugins'] )
			? array_values( array_filter( $options['active_plugins'], 'is_string' ) )
			: [];
		$stylesheet      = self::string_option( $options, 'stylesheet' );
		$template        = self::string_option( $options, 'template' );
		$raw_registry    = $options[ RegistryProbe::OPTION ] ?? null;
		$status          = RegistryProbe::status(
			$raw_registry,
			Fingerprint::compute( $active_plugins, $network_plugins, $stylesheet, $template, self::wp_version(), MSRADAR_VERSION ),
			time()
		);
		$registry        = is_array( $raw_registry ) ? $raw_registry : [];
		$post_types      = $this->merge_post_types( $counts, (array) ( $registry['post_types'] ?? [] ), $status );
		$taxonomies      = $this->merge_taxonomies( $terms, (array) ( $registry['taxonomies'] ?? [] ), $status );
		$home            = self::string_option( $options, 'home' );
		$siteurl         = self::string_option( $options, 'siteurl' );
		$attachments     = $counts['attachment'] ?? [];

		$record                    = new SiteRecord();
		$record->site_id           = $site_id;
		$record->network_id        = (int) $site->site_id; // WP_Site::$site_id contient l'ID du réseau.
		$record->name              = self::string_option( $options, 'blogname' );
		$record->url               = '' !== $home ? $home : ( '' !== $siteurl ? $siteurl : 'http://' . $site->domain . $site->path );
		$record->is_public         = '1' === (string) $site->public;
		$record->is_archived       = '1' === (string) $site->archived;
		$record->is_spam           = '1' === (string) $site->spam;
		$record->is_deleted        = '1' === (string) $site->deleted;
		$record->theme_stylesheet  = $stylesheet;
		$record->theme_template    = $template;
		$record->users_count       = $users['total'];
		$record->admins_count      = (int) ( $users['by_role']['administrator'] ?? 0 );
		$record->content_count     = (int) array_sum( array_map( static fn ( array $type ): int => 'attachment' === $type['name'] ? 0 : $type['publish'], $post_types ) );
		$record->media_count       = (int) ( $attachments['inherit'] ?? 0 ) + (int) ( $attachments['publish'] ?? 0 );
		$record->last_activity_gmt = null !== $last ? $last['date_gmt'] : null;
		$record->registry_status   = $status;
		$record->data              = [
			'post_types'    => $post_types,
			'taxonomies'    => $taxonomies,
			'users'         => [
				'by_role'    => $users['by_role'],
				'privileged' => $users['privileged'],
			],
			'plugins_local' => array_values( array_diff( $active_plugins, $network_plugins ) ),
			'last_content'  => $last,
			'options'       => [
				'blog_public' => (int) ( $options['blog_public'] ?? 1 ),
				'siteurl'     => $siteurl,
				'home'        => $home,
				'locale'      => $this->locale( $options ),
			],
		];
		$record->dirty             = false;
		$record->dirty_since       = null;
		$record->scanned_at        = current_time( 'mysql', true );

		if ( '' === trim( $record->name ) ) {
			/* translators: %d: site ID. */
			$record->name = sprintf( __( 'Site #%d', 'multisite-radar' ), $site_id );
		}

		return $record;
	}

	private function read_options( string $prefix ): array {
		global $wpdb;
		$names = [ 'blogname', 'siteurl', 'home', 'stylesheet', 'template', 'active_plugins', 'blog_public', 'WPLANG', RegistryProbe::OPTION, $prefix . 'user_roles' ];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( [ $prefix . 'options' ], $names )
			),
			ARRAY_A
		);
		$this->guard();

		$options = [];
		foreach ( (array) $rows as $row ) {
			$options[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}
		return $options;
	}

	/**
	 * @return array<string, array<string, int>> type => statut => nombre.
	 */
	private function read_post_counts( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT post_type, post_status, COUNT(*) AS total FROM %i GROUP BY post_type, post_status', $prefix . 'posts' ),
			ARRAY_A
		);
		$this->guard();

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['post_type'] ][ (string) $row['post_status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * @return array<string, int> taxonomie => nombre de termes.
	 */
	private function read_term_counts( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT taxonomy, COUNT(*) AS total FROM %i GROUP BY taxonomy', $prefix . 'term_taxonomy' ),
			ARRAY_A
		);
		$this->guard();

		$terms = [];
		foreach ( (array) $rows as $row ) {
			$terms[ (string) $row['taxonomy'] ] = (int) $row['total'];
		}
		return $terms;
	}

	/**
	 * @return array{id: int, type: string, title: string, date_gmt: string}|null
	 */
	private function read_last_content( string $prefix ): ?array {
		global $wpdb;
		$types = array_values( array_filter( (array) $this->settings->get( 'scan.activity_post_types', [ 'post', 'page' ] ), 'is_string' ) );
		if ( [] === $types ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_type, post_title, post_modified_gmt, post_date_gmt FROM %i WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY post_modified_gmt DESC, ID DESC LIMIT 1',
				array_merge( [ $prefix . 'posts' ], $types )
			),
			ARRAY_A
		);
		$this->guard();

		if ( ! is_array( $row ) ) {
			return null;
		}
		$date = self::ZERO_DATE !== $row['post_modified_gmt'] ? (string) $row['post_modified_gmt'] : (string) $row['post_date_gmt'];
		if ( self::ZERO_DATE === $date ) {
			return null;
		}
		return [
			'id'       => (int) $row['ID'],
			'type'     => (string) $row['post_type'],
			'title'    => (string) $row['post_title'],
			'date_gmt' => $date,
		];
	}

	/**
	 * Les valeurs de capacités sont très répétitives : un GROUP BY ramène quelques lignes même pour des milliers d'utilisateurs.
	 *
	 * @param string[] $roles Rôles connus du site.
	 * @return array{total: int, by_role: array<string, int>, privileged: array<int, array{id: int, login: string, roles: string[]}>}
	 */
	private function read_users( string $prefix, array $roles ): array {
		global $wpdb;
		$key  = $prefix . 'capabilities';
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT meta_value, COUNT(*) AS total FROM %i WHERE meta_key = %s GROUP BY meta_value', $wpdb->usermeta, $key ),
			ARRAY_A
		);
		$this->guard();

		$total   = 0;
		$by_role = [];
		foreach ( (array) $rows as $row ) {
			$user_roles = $this->roles_from_caps( $row['meta_value'], $roles );
			if ( [] === $user_roles ) {
				continue;
			}
			$count  = (int) $row['total'];
			$total += $count;
			foreach ( $user_roles as $role ) {
				$by_role[ $role ] = ( $by_role[ $role ] ?? 0 ) + $count;
			}
		}
		ksort( $by_role );

		$patterns = array_map( static fn ( string $role ): string => '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%', self::PRIVILEGED_ROLES );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.ID, u.user_login, um.meta_value FROM %i um INNER JOIN %i u ON u.ID = um.user_id WHERE um.meta_key = %s AND (um.meta_value LIKE %s OR um.meta_value LIKE %s) ORDER BY u.ID ASC LIMIT %d',
				$wpdb->usermeta,
				$wpdb->users,
				$key,
				$patterns[0],
				$patterns[1],
				self::PRIVILEGED_LIMIT
			),
			ARRAY_A
		);
		$this->guard();

		$privileged = [];
		foreach ( (array) $rows as $row ) {
			$user_roles = $this->roles_from_caps( $row['meta_value'], $roles );
			if ( [] === array_intersect( $user_roles, self::PRIVILEGED_ROLES ) ) {
				continue;
			}
			$privileged[] = [
				'id'    => (int) $row['ID'],
				'login' => (string) $row['user_login'],
				'roles' => $user_roles,
			];
		}

		return [
			'total'      => $total,
			'by_role'    => $by_role,
			'privileged' => $privileged,
		];
	}

	/**
	 * @param mixed    $raw   Valeur sérialisée de wp_X_capabilities.
	 * @param string[] $roles Rôles connus.
	 * @return string[]
	 */
	private function roles_from_caps( $raw, array $roles ): array {
		$caps = maybe_unserialize( $raw );
		if ( ! is_array( $caps ) ) {
			return [];
		}
		$found = [];
		foreach ( $caps as $cap => $granted ) {
			if ( $granted && in_array( (string) $cap, $roles, true ) ) {
				$found[] = (string) $cap;
			}
		}
		return $found;
	}

	/**
	 * @param mixed $user_roles Valeur de l'option wp_X_user_roles.
	 * @return string[]
	 */
	private function role_names( $user_roles ): array {
		if ( ! is_array( $user_roles ) || [] === $user_roles ) {
			return self::DEFAULT_ROLES;
		}
		return array_map( 'strval', array_keys( $user_roles ) );
	}

	private function merge_post_types( array $counts, array $registered, string $status ): array {
		$excluded = (array) apply_filters( 'msradar_excluded_post_types', self::EXCLUDED_POST_TYPES );
		$items    = [];
		foreach ( self::names( $counts, $registered ) as $name ) {
			if ( in_array( $name, $excluded, true ) ) {
				continue;
			}
			$statuses = $counts[ $name ] ?? [];
			$items[]  = array_merge(
				$this->describe( $name, $registered[ $name ] ?? null, $status, self::CORE_POST_TYPES ),
				[
					'publish' => (int) ( $statuses['publish'] ?? 0 ),
					'total'   => (int) array_sum( $statuses ),
				]
			);
		}
		return $items;
	}

	private function merge_taxonomies( array $terms, array $registered, string $status ): array {
		$excluded = (array) apply_filters( 'msradar_excluded_taxonomies', self::EXCLUDED_TAXONOMIES );
		$items    = [];
		foreach ( self::names( $terms, $registered ) as $name ) {
			if ( in_array( $name, $excluded, true ) ) {
				continue;
			}
			$items[] = array_merge(
				$this->describe( $name, $registered[ $name ] ?? null, $status, self::CORE_TAXONOMIES ),
				[ 'count' => (int) ( $terms[ $name ] ?? 0 ) ]
			);
		}
		return $items;
	}

	/**
	 * @param mixed    $info   Entrée du registre pour ce nom, ou null.
	 * @param string[] $core   Noms natifs de WordPress.
	 */
	private function describe( string $name, $info, string $status, array $core ): array {
		$known = is_array( $info );
		if ( $known && is_array( $info['origin'] ?? null ) ) {
			$origin = [
				'kind' => (string) ( $info['origin']['kind'] ?? 'unknown' ),
				'slug' => (string) ( $info['origin']['slug'] ?? '' ),
			];
		} elseif ( in_array( $name, $core, true ) ) {
			$origin = [
				'kind' => 'core',
				'slug' => '',
			];
		} else {
			$origin = [
				'kind' => 'unknown',
				'slug' => '',
			];
		}

		return [
			'name'     => $name,
			'label'    => $known && is_string( $info['label'] ?? null ) && '' !== $info['label'] ? $info['label'] : $name,
			'origin'   => $origin,
			'builtin'  => $known ? (bool) ( $info['builtin'] ?? false ) : in_array( $name, $core, true ),
			'verified' => $known && RegistryProbe::STATUS_FRESH === $status,
		];
	}

	/**
	 * @return string[] Noms présents en base ou dans le registre, triés.
	 */
	private static function names( array $counts, array $registered ): array {
		$names = array_unique( array_merge( array_map( 'strval', array_keys( $counts ) ), array_map( 'strval', array_keys( $registered ) ) ) );
		sort( $names );
		return $names;
	}

	private function locale( array $options ): string {
		$locale = self::string_option( $options, 'WPLANG' );
		if ( '' === $locale ) {
			$locale = (string) get_site_option( 'WPLANG', '' );
		}
		return '' !== $locale ? $locale : 'en_US';
	}

	private static function string_option( array $options, string $name ): string {
		return isset( $options[ $name ] ) && is_scalar( $options[ $name ] ) ? (string) $options[ $name ] : '';
	}

	private static function wp_version(): string {
		global $wp_version;
		return (string) $wp_version;
	}

	private function guard(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( esc_html( $wpdb->last_error ) );
		}
	}
}
```

- [ ] **Step 4: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter l'import `use MultisiteRadar\Collector\SiteCollector;` ;
- ajouter la propriété `private ?SiteCollector $collector = null;` ;
- ajouter la méthode :

```php
	public function collector(): SiteCollector {
		return $this->collector ??= new SiteCollector( $this->settings() );
	}
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 6: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: collect site data with aggregate SQL queries"
```

---

### Task 8: Moteur d'alertes et trois premières règles

**Files:**
- Create: `includes/Alerts/Severity.php`, `includes/Alerts/Alert.php`, `includes/Alerts/RuleInterface.php`, `includes/Alerts/RuleRegistry.php`, `includes/Alerts/AlertEvaluator.php`, `includes/Alerts/AlertFormatter.php`
- Create: `includes/Alerts/Rules/NoUsersRule.php`, `includes/Alerts/Rules/InactiveRule.php`, `includes/Alerts/Rules/HighMediaRule.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Alerts/RulesTest.php`, `tests/php/Alerts/AlertEvaluatorTest.php`

**Interfaces:**
- Consumes: `SiteRecord`, `Settings::rule_config()`.
- Produces :
  - **`Severity`** : `ERROR = 'error'`, `WARNING = 'warning'`, `INFO = 'info'` ; `level( string ): int` (info 1, warning 2, error 3, sinon 0) ; `name( int ): string` (`none` pour 0) ; `is_valid( string ): bool`.
  - **`Alert`** : propriétés `string $rule`, `string $severity`, `array $args` ; `to_array(): array{rule, severity, args}` ; `static from_array( array ): ?Alert`.
  - **`RuleInterface`** : interface du §4.1 de la spec, avec `evaluate( SiteRecord $site, array $params, int $now ): ?Alert` et `message( array $args ): string`.
  - **Règles** : identifiants `no_users`, `inactive` (params `months`, défaut 6) et `high_media` (params `threshold`, défaut 1000).
  - **`RuleRegistry`** :
    - `__construct( RuleInterface[] $defaults )` ;
    - `static create_default(): self` ;
    - `all(): array<string, RuleInterface>` ;
    - `get( string ): ?RuleInterface`.

    Le filtre `msradar_alert_rules` est appliqué paresseusement, et pas avant `plugins_loaded`.
  - **`AlertEvaluator`** :
    - `__construct( RuleRegistry, Settings )` ;
    - `config( RuleInterface ): array{enabled: bool, severity: string, params: array}` ;
    - `evaluate( SiteRecord, int $now ): Alert[]` ;
    - `apply( SiteRecord, int $now ): SiteRecord`, qui remplit `alert_level`, `alerts_count`, `alert_rules` et `data['alerts']` ;
    - `reset(): void`.
  - **`AlertFormatter`** : `__construct( RuleRegistry )` ; `format( array $stored ): array<int, array{rule, severity, label, message}>`.
  - **`Plugin`** : `rules()`, `evaluator()`, `formatter()`. `reset_caches()` vide aussi l'évaluateur.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Alerts/RulesTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class RulesTest extends TestCase {

	private function record( array $props ): SiteRecord {
		$record             = new SiteRecord();
		$record->scanned_at = '2026-09-01 00:00:00';
		foreach ( $props as $property => $value ) {
			$record->$property = $value;
		}
		return $record;
	}

	public function test_no_users(): void {
		$rule = new NoUsersRule();

		$this->assertInstanceOf( Alert::class, $rule->evaluate( $this->record( [ 'users_count' => 0 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->record( [ 'users_count' => 2 ] ), [], time() ) );
		$this->assertNull(
			$rule->evaluate( $this->record( [ 'users_count' => 0, 'scanned_at' => null ] ), [], time() ),
			'A site that was never scanned is never flagged.'
		);
	}

	public function test_inactive_uses_whole_months_of_thirty_days(): void {
		$now  = (int) strtotime( '2026-10-01 00:00:00 UTC' );
		$at   = static fn ( int $days ): string => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$rule = new InactiveRule();

		$alert = $rule->evaluate( $this->record( [ 'last_activity_gmt' => $at( 180 ) ] ), [ 'months' => 6 ], $now );
		$this->assertNotNull( $alert );
		$this->assertSame( [ 'months' => 6 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->record( [ 'last_activity_gmt' => $at( 179 ) ] ), [ 'months' => 6 ], $now ) );
		$this->assertNull( $rule->evaluate( $this->record( [ 'last_activity_gmt' => null ] ), [ 'months' => 6 ], $now ) );
		$this->assertSame( 'Inactive for 6 months', $rule->message( [ 'months' => 6 ] ) );
		$this->assertSame( 'Inactive for 1 month', $rule->message( [ 'months' => 1 ] ) );
	}

	public function test_high_media(): void {
		$rule = new HighMediaRule();

		$alert = $rule->evaluate( $this->record( [ 'media_count' => 1000 ] ), [ 'threshold' => 1000 ], time() );
		$this->assertSame( [ 'count' => 1000, 'threshold' => 1000 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->record( [ 'media_count' => 999 ] ), [ 'threshold' => 1000 ], time() ) );
		$this->assertSame( '1000 media files (threshold: 1000)', $rule->message( $alert->args ) );
	}

	public function test_default_params_satisfy_their_schema(): void {
		foreach ( [ new NoUsersRule(), new InactiveRule(), new HighMediaRule() ] as $rule ) {
			$this->assertTrue( rest_validate_value_from_schema( $rule->default_params(), $rule->params_schema(), 'params' ) );
		}
	}
}
```

`tests/php/Alerts/AlertEvaluatorTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class AlertEvaluatorTest extends TestCase {

	private function record( array $props ): SiteRecord {
		$record             = new SiteRecord();
		$record->scanned_at = '2026-09-01 00:00:00';
		foreach ( $props as $property => $value ) {
			$record->$property = $value;
		}
		return $record;
	}

	private function fresh_evaluator(): AlertEvaluator {
		return new AlertEvaluator( $this->plugin()->rules(), $this->plugin()->settings() );
	}

	public function test_apply_sets_level_count_rule_ids_and_data(): void {
		$record = $this->record( [ 'users_count' => 0, 'media_count' => 5000 ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 3, $record->alert_level );
		$this->assertSame( 2, $record->alerts_count );
		$this->assertSame( ',no_users,high_media,', $record->alert_rules );
		$this->assertSame( [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ], $record->data['alerts'][0] );
	}

	public function test_no_alert_clears_previous_values(): void {
		$record = $this->record( [ 'users_count' => 4, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,' ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 0, $record->alert_level );
		$this->assertSame( '', $record->alert_rules );
		$this->assertSame( [], $record->data['alerts'] );
	}

	public function test_settings_disable_rules_and_override_severity_and_params(): void {
		$this->plugin()->settings()->update(
			[
				'alerts' => [
					'rules' => [
						'no_users'   => [ 'enabled' => false ],
						'high_media' => [
							'severity' => 'warning',
							'params'   => [ 'threshold' => 10 ],
						],
					],
				],
			]
		);
		$record = $this->record( [ 'users_count' => 0, 'media_count' => 50 ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 2, $record->alert_level );
		$this->assertSame( ',high_media,', $record->alert_rules );
		$this->assertSame( [ 'count' => 50, 'threshold' => 10 ], $record->data['alerts'][0]['args'] );
	}

	public function test_invalid_stored_params_fall_back_to_defaults(): void {
		update_site_option( Settings::OPTION, [ 'alerts' => [ 'rules' => [ 'high_media' => [ 'params' => [ 'threshold' => 'lots' ] ] ] ] ] );
		$this->plugin()->settings()->reset_cache();

		$config = $this->fresh_evaluator()->config( $this->plugin()->rules()->get( 'high_media' ) );

		$this->assertSame( [ 'threshold' => 1000 ], $config['params'] );
		$this->assertSame( 'info', $config['severity'] );
		$this->assertTrue( $config['enabled'] );
	}

	public function test_third_party_rules_can_be_added_and_invalid_entries_are_ignored(): void {
		add_filter(
			'msradar_alert_rules',
			static function ( array $rules ): array {
				$rules[] = new class() implements RuleInterface {
					public function id(): string {
						return 'always';
					}
					public function label(): string {
						return 'Always';
					}
					public function description(): string {
						return 'Always raised.';
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
						return new Alert( 'always', 'info' );
					}
					public function message( array $args ): string {
						return 'Always';
					}
				};
				$rules[] = 'not a rule';
				return $rules;
			}
		);

		$registry = RuleRegistry::create_default();

		$this->assertSame( [ 'no_users', 'inactive', 'high_media', 'always' ], array_keys( $registry->all() ) );
	}

	public function test_formatter_builds_labels_and_messages(): void {
		$formatted = $this->plugin()->formatter()->format(
			[
				[ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ],
				[ 'rule' => 'gone', 'severity' => 'info', 'args' => [] ],
				[ 'rule' => 'inactive', 'severity' => 'bogus' ],
				'garbage',
			]
		);

		$this->assertSame(
			[
				[ 'rule' => 'inactive', 'severity' => 'warning', 'label' => 'Inactive site', 'message' => 'Inactive for 8 months' ],
				[ 'rule' => 'gone', 'severity' => 'info', 'label' => 'gone', 'message' => 'gone' ],
			],
			$formatted
		);
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'RulesTest|AlertEvaluatorTest'`
Expected: échec avec `Class "MultisiteRadar\Alerts\…" not found`.

- [ ] **Step 3: Implémenter les objets de base**

`includes/Alerts/Severity.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Niveaux de gravité et leur valeur numérique (stockée dans alert_level).
 */
final class Severity {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	private const LEVELS = [
		self::INFO    => 1,
		self::WARNING => 2,
		self::ERROR   => 3,
	];

	public static function level( string $severity ): int {
		return self::LEVELS[ $severity ] ?? 0;
	}

	public static function name( int $level ): string {
		$names = array_flip( self::LEVELS );
		return $names[ $level ] ?? 'none';
	}

	public static function is_valid( string $severity ): bool {
		return isset( self::LEVELS[ $severity ] );
	}
}
```

`includes/Alerts/Alert.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Une alerte levée par une règle. Le message est construit à la lecture, dans la langue de l'utilisateur.
 */
final class Alert {

	public string $rule;
	public string $severity;
	public array $args;

	public function __construct( string $rule, string $severity, array $args = [] ) {
		$this->rule     = $rule;
		$this->severity = $severity;
		$this->args     = $args;
	}

	/**
	 * @return array{rule: string, severity: string, args: array}
	 */
	public function to_array(): array {
		return [
			'rule'     => $this->rule,
			'severity' => $this->severity,
			'args'     => $this->args,
		];
	}

	public static function from_array( array $data ): ?self {
		if ( ! isset( $data['rule'], $data['severity'] ) || ! is_string( $data['rule'] ) || ! Severity::is_valid( (string) $data['severity'] ) ) {
			return null;
		}
		return new self( $data['rule'], (string) $data['severity'], is_array( $data['args'] ?? null ) ? $data['args'] : [] );
	}
}
```

`includes/Alerts/RuleInterface.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Une règle d'alerte. Elle ne lit que les données stockées (aucune requête) : tout recalculer reste instantané.
 */
interface RuleInterface {

	public function id(): string;

	public function label(): string;

	public function description(): string;

	public function default_severity(): string;

	/**
	 * Schéma JSON des paramètres (objet), utilisé pour la validation et le formulaire de réglages.
	 */
	public function params_schema(): array;

	public function default_params(): array;

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert;

	public function message( array $args ): string;
}
```

- [ ] **Step 4: Implémenter les trois règles**

`includes/Alerts/Rules/NoUsersRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class NoUsersRule implements RuleInterface {

	public function id(): string {
		return 'no_users';
	}

	public function label(): string {
		return __( 'Site without users', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'No user account is attached to the site.', 'multisite-radar' );
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
		if ( null === $site->scanned_at || $site->users_count > 0 ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'No user is attached to this site.', 'multisite-radar' );
	}
}
```

`includes/Alerts/Rules/InactiveRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class InactiveRule implements RuleInterface {

	public function id(): string {
		return 'inactive';
	}

	public function label(): string {
		return __( 'Inactive site', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'No content of the tracked types has been published or updated for a while.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'months' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 120,
					'description' => __( 'Months without activity before the alert is raised.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'months' => 6 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || null === $site->last_activity_gmt ) {
			return null;
		}
		$last = strtotime( $site->last_activity_gmt . ' UTC' );
		if ( false === $last ) {
			return null;
		}
		$months = (int) floor( ( $now - $last ) / ( 30 * DAY_IN_SECONDS ) );
		if ( $months < (int) $params['months'] ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity(), [ 'months' => $months ] );
	}

	public function message( array $args ): string {
		$months = (int) ( $args['months'] ?? 0 );
		/* translators: %d: number of months without activity. */
		return sprintf( _n( 'Inactive for %d month', 'Inactive for %d months', $months, 'multisite-radar' ), $months );
	}
}
```

`includes/Alerts/Rules/HighMediaRule.php` :

```php
<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class HighMediaRule implements RuleInterface {

	public function id(): string {
		return 'high_media';
	}

	public function label(): string {
		return __( 'Many media files', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The media library holds more files than the threshold.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'threshold' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10000000,
					'description' => __( 'Number of media files that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'threshold' => 1000 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		$threshold = (int) $params['threshold'];
		if ( null === $site->scanned_at || $site->media_count < $threshold ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'count'     => $site->media_count,
				'threshold' => $threshold,
			]
		);
	}

	public function message( array $args ): string {
		/* translators: 1: number of media files, 2: alert threshold. */
		return sprintf( __( '%1$d media files (threshold: %2$d)', 'multisite-radar' ), (int) ( $args['count'] ?? 0 ), (int) ( $args['threshold'] ?? 0 ) );
	}
}
```

- [ ] **Step 5: Implémenter le registre, l'évaluateur et le formateur**

`includes/Alerts/RuleRegistry.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;

defined( 'ABSPATH' ) || exit;

/**
 * Règles disponibles. Le filtre msradar_alert_rules est appliqué à la première lecture,
 * et pas avant plugins_loaded, pour que les plugins tiers aient pu s'y abonner.
 */
final class RuleRegistry {

	/** @var RuleInterface[] */
	private array $defaults;
	/** @var array<string, RuleInterface>|null */
	private ?array $rules = null;

	/**
	 * @param RuleInterface[] $defaults
	 */
	public function __construct( array $defaults ) {
		$this->defaults = $defaults;
	}

	public static function create_default(): self {
		return new self( [ new NoUsersRule(), new InactiveRule(), new HighMediaRule() ] );
	}

	/**
	 * @return array<string, RuleInterface>
	 */
	public function all(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}
		$rules = [];
		foreach ( (array) apply_filters( 'msradar_alert_rules', $this->defaults ) as $rule ) {
			if ( $rule instanceof RuleInterface ) {
				$rules[ $rule->id() ] = $rule;
			}
		}
		if ( did_action( 'plugins_loaded' ) ) {
			$this->rules = $rules;
		}
		return $rules;
	}

	public function get( string $id ): ?RuleInterface {
		return $this->all()[ $id ] ?? null;
	}
}
```

`includes/Alerts/AlertEvaluator.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Applique les règles actives, avec leurs réglages, à un enregistrement de site.
 */
final class AlertEvaluator {

	private RuleRegistry $rules;
	private Settings $settings;
	/** @var array<string, array{enabled: bool, severity: string, params: array}> */
	private array $configs = [];

	public function __construct( RuleRegistry $rules, Settings $settings ) {
		$this->rules    = $rules;
		$this->settings = $settings;
	}

	/**
	 * @return array{enabled: bool, severity: string, params: array}
	 */
	public function config( RuleInterface $rule ): array {
		$id = $rule->id();
		if ( isset( $this->configs[ $id ] ) ) {
			return $this->configs[ $id ];
		}

		$stored = $this->settings->rule_config( $id );
		$params = array_merge( $rule->default_params(), is_array( $stored['params'] ?? null ) ? $stored['params'] : [] );
		if ( true !== rest_validate_value_from_schema( $params, $rule->params_schema(), 'params' ) ) {
			$params = $rule->default_params();
		}
		$severity = isset( $stored['severity'] ) && is_string( $stored['severity'] ) && Severity::is_valid( $stored['severity'] )
			? $stored['severity']
			: $rule->default_severity();

		$this->configs[ $id ] = [
			'enabled'  => (bool) ( $stored['enabled'] ?? true ),
			'severity' => $severity,
			'params'   => $params,
		];
		return $this->configs[ $id ];
	}

	/**
	 * @return Alert[]
	 */
	public function evaluate( SiteRecord $site, int $now ): array {
		$alerts = [];
		foreach ( $this->rules->all() as $rule ) {
			$config = $this->config( $rule );
			if ( ! $config['enabled'] ) {
				continue;
			}
			$alert = $rule->evaluate( $site, $config['params'], $now );
			if ( null === $alert ) {
				continue;
			}
			$alert->severity = $config['severity'];
			$alerts[]        = $alert;
		}
		return $alerts;
	}

	public function apply( SiteRecord $site, int $now ): SiteRecord {
		$alerts = $this->evaluate( $site, $now );
		$level  = 0;
		$ids    = [];
		foreach ( $alerts as $alert ) {
			$level = max( $level, Severity::level( $alert->severity ) );
			$ids[] = $alert->rule;
		}

		$rules = [] === $ids ? '' : ',' . implode( ',', $ids ) . ',';
		if ( strlen( $rules ) > 255 ) {
			$rules = substr( $rules, 0, (int) strrpos( substr( $rules, 0, 255 ), ',' ) + 1 );
		}

		$site->alert_level    = $level;
		$site->alerts_count   = count( $alerts );
		$site->alert_rules    = $rules;
		$site->data['alerts'] = array_map( static fn ( Alert $alert ): array => $alert->to_array(), $alerts );
		return $site;
	}

	public function reset(): void {
		$this->configs = [];
	}
}
```

`includes/Alerts/AlertFormatter.php` :

```php
<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Transforme les alertes stockées (règle + arguments) en libellés et messages traduits.
 */
final class AlertFormatter {

	private RuleRegistry $rules;

	public function __construct( RuleRegistry $rules ) {
		$this->rules = $rules;
	}

	/**
	 * @param array $stored Contenu de data['alerts'].
	 * @return array<int, array{rule: string, severity: string, label: string, message: string}>
	 */
	public function format( array $stored ): array {
		$formatted = [];
		foreach ( $stored as $item ) {
			$alert = is_array( $item ) ? Alert::from_array( $item ) : null;
			if ( null === $alert ) {
				continue;
			}
			$rule        = $this->rules->get( $alert->rule );
			$formatted[] = [
				'rule'     => $alert->rule,
				'severity' => $alert->severity,
				'label'    => null !== $rule ? $rule->label() : $alert->rule,
				'message'  => null !== $rule ? $rule->message( $alert->args ) : $alert->rule,
			];
		}
		return $formatted;
	}
}
```

- [ ] **Step 6: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Alerts\AlertEvaluator;`, `use MultisiteRadar\Alerts\AlertFormatter;` et `use MultisiteRadar\Alerts\RuleRegistry;` ;
- ajouter les propriétés `private ?RuleRegistry $rules = null;`, `private ?AlertEvaluator $evaluator = null;` et `private ?AlertFormatter $formatter = null;` ;
- ajouter les méthodes :

```php
	public function rules(): RuleRegistry {
		return $this->rules ??= RuleRegistry::create_default();
	}

	public function evaluator(): AlertEvaluator {
		return $this->evaluator ??= new AlertEvaluator( $this->rules(), $this->settings() );
	}

	public function formatter(): AlertFormatter {
		return $this->formatter ??= new AlertFormatter( $this->rules() );
	}
```

- dans `reset_caches()`, ajouter :

```php
		if ( null !== $this->evaluator ) {
			$this->evaluator->reset();
		}
```

- [ ] **Step 7: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 8: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add alert rules engine with no_users, inactive and high_media rules"
```

---
### Task 9: Verrou et traitement par lots (Lock, BatchRunner)

**Files:**
- Create: `includes/Scan/Lock.php`, `includes/Scan/BatchRunner.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Scan/LockTest.php`, `tests/php/Scan/BatchRunnerTest.php`

**Interfaces:**
- Consumes :
  - `SitesRepository` : `next_dirty`, `clear_dirty`, `save`, `find`, `delete`, `count_dirty` ;
  - `ExtensionsRepository` : `replace_for_site`, `delete_for_site` ;
  - `SiteCollector::collect()` ;
  - `AlertEvaluator::apply()`.
- Produces :
  - **`Lock`** :
    - constante `NAME = 'msradar_scan_lock'` ;
    - `__construct( int $ttl = 120 )` ;
    - `acquire(): bool`, `refresh(): void`, `release(): void`, `is_locked(): bool`.

    Le verrou est une ligne de la table `options` du site principal, posée de façon atomique par `INSERT IGNORE`. Une fois expiré, il peut être repris.
  - **`BatchRunner`** :
    - constante `MAX_BUDGET = 20.0` ;
    - `static default_budget(): float` ;
    - `run( float $budget, ?callable $on_site = null ): array{processed: int, remaining: int, locked: bool}`. Traite au moins un site par appel ; `$on_site( int $site_id, bool $ok )` est appelé après chaque site.
    - `scan_site( int $site_id ): bool`. En cas d'échec, l'erreur est stockée dans `data.scan_error` (`{message, at_gmt}`) sans toucher `scanned_at`. Si le site n'existe plus, sa ligne est supprimée et la méthode renvoie `false`.
  - **Action** `msradar_site_scanned( int $site_id, SiteRecord $record )`.
  - **`Plugin`** : `lock(): Lock`, `runner(): BatchRunner`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Scan/LockTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Tests\TestCase;

final class LockTest extends TestCase {

	private function expire_now(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->get_blog_prefix( get_main_site_id() ) . 'options',
			[ 'option_value' => (string) ( time() - 1 ) ],
			[ 'option_name' => Lock::NAME ]
		);
	}

	public function test_acquire_is_exclusive_until_released(): void {
		$first  = new Lock();
		$second = new Lock();

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );
		$this->assertTrue( $second->is_locked() );

		$first->release();
		$this->assertFalse( $first->is_locked() );
		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_an_expired_lock_is_taken_over(): void {
		( new Lock() )->acquire();
		$this->expire_now();

		$this->assertFalse( ( new Lock() )->is_locked() );
		$this->assertTrue( ( new Lock() )->acquire() );
	}

	public function test_refresh_extends_the_lock(): void {
		$lock = new Lock( 60 );
		$lock->acquire();
		$this->expire_now();

		$lock->refresh();

		$this->assertTrue( $lock->is_locked() );
	}
}
```

`tests/php/Scan/BatchRunnerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Tests\TestCase;

final class BatchRunnerTest extends TestCase {

	private function seed(): void {
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
	}

	public function test_scans_every_dirty_site_and_stores_the_results(): void {
		$site_id = self::factory()->blog->create( [ 'title' => 'Scanned site' ] );
		update_blog_option( $site_id, 'active_plugins', [ 'acme/acme.php' ] );
		$this->seed();
		$scanned = [];
		add_action(
			'msradar_site_scanned',
			static function ( int $id ) use ( &$scanned ): void {
				$scanned[] = $id;
			}
		);

		$result = $this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $site_id );

		$this->assertFalse( $result['locked'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertContains( $site_id, $scanned );
		$this->assertSame( 'Scanned site', $record->name );
		$this->assertNotNull( $record->scanned_at );
		$this->assertFalse( $record->dirty );
		$this->assertContains(
			[ 'type' => 'plugin', 'slug' => 'acme/acme.php', 'role' => 'local' ],
			$this->plugin()->extensions()->for_site( $site_id )
		);
		$this->assertFalse( $this->plugin()->lock()->is_locked(), 'The lock is released.' );
	}

	public function test_alerts_are_evaluated_during_the_scan(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $wpdb->get_blog_prefix( $site_id ) . 'capabilities' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->seed();

		$this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $site_id );

		$this->assertSame( 3, $record->alert_level );
		$this->assertSame( [ 'no_users' ], $record->alert_rule_ids() );
	}

	public function test_processes_at_least_one_site_even_without_budget(): void {
		self::factory()->blog->create_many( 2 );
		$this->seed();
		$total = $this->plugin()->sites()->count_dirty();

		$result = $this->plugin()->runner()->run( 0.0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( $total - 1, $result['remaining'] );
	}

	public function test_reports_a_lock_held_by_another_process(): void {
		$this->seed();
		$this->assertTrue( $this->plugin()->lock()->acquire() );

		$result = $this->plugin()->runner()->run( 60.0 );
		$this->plugin()->lock()->release();

		$this->assertTrue( $result['locked'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertGreaterThan( 0, $result['remaining'] );
	}

	public function test_a_broken_site_is_recorded_and_does_not_block_the_queue(): void {
		global $wpdb;
		$broken  = self::factory()->blog->create();
		$healthy = self::factory()->blog->create();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->get_blog_prefix( $broken ) . 'posts' ) );
		$this->seed();

		$result = $this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $broken );

		$this->assertSame( 0, $result['remaining'] );
		$this->assertArrayHasKey( 'scan_error', $record->data );
		$this->assertNotSame( '', $record->data['scan_error']['message'] );
		$this->assertFalse( $record->dirty );
		$this->assertNull( $record->scanned_at );
		$this->assertNotNull( $this->plugin()->sites()->find( $healthy )->scanned_at );
	}

	public function test_a_site_deleted_while_queued_loses_its_row(): void {
		$this->plugin()->sites()->insert_pending( 999999, get_current_network_id(), 'gone.test/' );

		$this->plugin()->runner()->run( 60.0 );

		$this->assertNull( $this->plugin()->sites()->find( 999999 ) );
	}

	public function test_a_mark_set_during_the_scan_survives(): void {
		$site_id = self::factory()->blog->create();
		$this->seed();
		add_filter(
			'msradar_excluded_post_types',
			function ( array $types ) use ( $site_id ): array {
				$this->plugin()->sites()->mark_dirty( [ $site_id ] );
				return $types;
			}
		);

		$this->plugin()->runner()->scan_site( $site_id );

		$this->assertTrue( $this->plugin()->sites()->find( $site_id )->dirty );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'LockTest|BatchRunnerTest'`
Expected: échec avec `Class "MultisiteRadar\Scan\Lock" not found`.

- [ ] **Step 3: Implémenter `Lock`**

`includes/Scan/Lock.php` :

```php
<?php
namespace MultisiteRadar\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Verrou réseau pour qu'une seule analyse tourne à la fois (cron, REST, WP-CLI).
 * Stocké dans la table options du site principal, dont l'index unique rend la prise atomique.
 */
final class Lock {

	public const NAME = 'msradar_scan_lock';

	private int $ttl;

	public function __construct( int $ttl = 120 ) {
		$this->ttl = $ttl;
	}

	public function acquire(): bool {
		global $wpdb;
		$now      = time();
		$expires  = (string) ( $now + $this->ttl );
		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::table(), self::NAME, $expires )
		);
		if ( 1 === (int) $inserted ) {
			return true;
		}
		$taken = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', self::table(), $expires, self::NAME, $now )
		);
		return 1 === (int) $taken;
	}

	public function refresh(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', self::table(), (string) ( time() + $this->ttl ), self::NAME )
		);
	}

	public function release(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
	}

	public function is_locked(): bool {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
		return null !== $value && (int) $value >= time();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( get_main_site_id() ) . 'options';
	}
}
```

- [ ] **Step 4: Implémenter `BatchRunner`**

`includes/Scan/BatchRunner.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Analyse les sites marqués, par lots bornés dans le temps et en mémoire, sous verrou.
 */
final class BatchRunner {

	public const MAX_BUDGET = 20.0;
	private const CHUNK     = 10;

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private SiteCollector $collector;
	private AlertEvaluator $evaluator;
	private Lock $lock;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, SiteCollector $collector, AlertEvaluator $evaluator, Lock $lock ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->collector  = $collector;
		$this->evaluator  = $evaluator;
		$this->lock       = $lock;
	}

	/**
	 * La moitié de max_execution_time, plafonnée à 20 s (20 s si illimité).
	 */
	public static function default_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? min( self::MAX_BUDGET, $max / 2 ) : self::MAX_BUDGET;
	}

	/**
	 * @param float         $budget  Secondes disponibles ; au moins un site est toujours traité.
	 * @param callable|null $on_site Appelé après chaque site avec ( int $site_id, bool $ok ).
	 * @return array{processed: int, remaining: int, locked: bool}
	 */
	public function run( float $budget, ?callable $on_site = null ): array {
		if ( ! $this->lock->acquire() ) {
			return [
				'processed' => 0,
				'remaining' => $this->sites->count_dirty(),
				'locked'    => true,
			];
		}

		$start     = microtime( true );
		$processed = 0;
		$seen      = [];
		try {
			while ( true ) {
				$ids = array_values( array_diff( $this->sites->next_dirty( self::CHUNK ), $seen ) );
				if ( [] === $ids ) {
					break;
				}
				foreach ( $ids as $site_id ) {
					if ( $processed > 0 && ( microtime( true ) - $start >= $budget || $this->memory_exhausted() ) ) {
						break 2;
					}
					$seen[] = $site_id;
					$ok     = $this->scan_site( $site_id );
					++$processed;
					$this->lock->refresh();
					if ( null !== $on_site ) {
						$on_site( $site_id, $ok );
					}
				}
			}
		} finally {
			$this->lock->release();
		}

		return [
			'processed' => $processed,
			'remaining' => $this->sites->count_dirty(),
			'locked'    => false,
		];
	}

	/**
	 * Le marquage est retiré avant la collecte : un nouveau marquage posé pendant l'analyse
	 * est conservé, car save() n'écrase jamais dirty sur une ligne existante.
	 */
	public function scan_site( int $site_id ): bool {
		$this->sites->clear_dirty( $site_id );

		try {
			$record = $this->collector->collect( $site_id );
		} catch ( Throwable $error ) {
			$this->record_failure( $site_id, $error->getMessage() );
			return false;
		}

		if ( null === $record ) {
			$this->sites->delete( $site_id );
			$this->extensions->delete_for_site( $site_id );
			return false;
		}

		$this->evaluator->apply( $record, time() );
		$this->sites->save( $record );
		$this->extensions->replace_for_site(
			$site_id,
			(array) ( $record->data['plugins_local'] ?? [] ),
			$record->theme_stylesheet,
			$record->theme_template
		);

		do_action( 'msradar_site_scanned', $site_id, $record );
		return true;
	}

	private function record_failure( int $site_id, string $message ): void {
		$record = $this->sites->find( $site_id );
		if ( null === $record ) {
			$record             = new SiteRecord();
			$record->site_id    = $site_id;
			$record->network_id = get_current_network_id();
		}
		$record->data['scan_error'] = [
			'message' => $message,
			'at_gmt'  => current_time( 'mysql', true ),
		];
		$record->dirty              = false;
		$record->dirty_since        = null;
		$this->sites->save( $record );
	}

	private function memory_exhausted(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && memory_get_usage( true ) >= 0.8 * $limit;
	}
}
```

- [ ] **Step 5: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Scan\BatchRunner;` et `use MultisiteRadar\Scan\Lock;` ;
- ajouter les propriétés `private ?Lock $lock = null;` et `private ?BatchRunner $runner = null;` ;
- ajouter les méthodes :

```php
	public function lock(): Lock {
		return $this->lock ??= new Lock();
	}

	public function runner(): BatchRunner {
		return $this->runner ??= new BatchRunner( $this->sites(), $this->extensions(), $this->collector(), $this->evaluator(), $this->lock() );
	}
```

- [ ] **Step 6: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 7: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: scan dirty sites in time-boxed batches under a network lock"
```

---

### Task 10: File cron et invalidation (Queue, Invalidation)

**Files:**
- Create: `includes/Support/MainSite.php`, `includes/Scan/Queue.php`, `includes/Scan/Invalidation.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Scan/QueueTest.php`, `tests/php/Scan/InvalidationTest.php`

**Interfaces:**
- Consumes :
  - `BatchRunner::run()` et `BatchRunner::default_budget()` ;
  - `SitesRepository` : `seed_from_blogs`, `delete_orphans`, `mark_all_dirty`, `mark_dirty`, `count_dirty`, `ids_after`, `find_many`, `save_alerts`, `insert_pending`, `delete`, `update_last_activity` ;
  - `ExtensionsRepository::delete_for_site()` ;
  - `AlertEvaluator::apply()` et `AlertEvaluator::reset()` ;
  - `Settings::get()` ;
  - `Schema::is_current()` ;
  - actions `msradar_activated`, `msradar_deactivated`, `msradar_settings_updated`.
- Produces :
  - **`MainSite::run( callable $callback )`**, qui renvoie le résultat du callback.
  - **Constantes de `Queue`** : `HOOK_PROCESS = 'msradar_process_queue'`, `HOOK_CONTINUE = 'msradar_process_queue_continue'`, `HOOK_DAILY = 'msradar_daily'`, `HOOK_RECOMPUTE = 'msradar_recompute_alerts'`, `SCHEDULE = 'msradar_five_minutes'` (300 s), `LAST_FULL_SCAN = 'msradar_last_full_scan'` (site option, timestamp).
  - **Méthodes de `Queue`** :
    - `register()`, `add_schedule( $schedules )` ;
    - `schedule()`, `ensure_scheduled()`, `unschedule()` ;
    - `process()`, `continue_soon()`, `request_full_scan( int $network_id )` ;
    - `daily()`, `recompute_alerts(): int` ;
    - `on_settings_updated()` ;
    - `static next_run(): ?int`.
  - **`Invalidation`** : `register()`, plus un gestionnaire public par hook (voir le code).
  - **`Plugin`** : `queue(): Queue`, `invalidation(): Invalidation`, tous deux enregistrés dans `boot()`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Scan/QueueTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class QueueTest extends TestCase {

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		$this->queue = $this->plugin()->queue();
	}

	private function count_events( string $hook ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ $hook ] ) ? count( $hooks[ $hook ] ) : 0;
		}
		return $count;
	}

	public function test_schedule_and_unschedule_recurring_events(): void {
		$this->queue->unschedule();

		$this->queue->schedule();
		$this->queue->schedule();

		$this->assertSame( 1, $this->count_events( Queue::HOOK_PROCESS ) );
		$this->assertSame( Queue::SCHEDULE, wp_get_schedule( Queue::HOOK_PROCESS ) );
		$this->assertSame( 'daily', wp_get_schedule( Queue::HOOK_DAILY ) );
		$this->assertSame( 300, wp_get_schedules()[ Queue::SCHEDULE ]['interval'] );
		$this->assertIsInt( Queue::next_run() );

		$this->queue->unschedule();
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_DAILY ) );
		$this->assertNull( Queue::next_run() );
	}

	public function test_activation_and_deactivation_drive_the_schedule(): void {
		$this->queue->unschedule();

		do_action( 'msradar_activated', true );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );

		do_action( 'msradar_deactivated' );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
	}

	public function test_schedule_from_a_sub_site_targets_the_main_site(): void {
		$this->queue->unschedule();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$this->queue->schedule();
		$on_sub_site = wp_next_scheduled( Queue::HOOK_PROCESS );
		restore_current_blog();

		$this->assertFalse( $on_sub_site );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
	}

	public function test_process_scans_dirty_sites_and_stops_when_done(): void {
		self::factory()->blog->create_many( 2 );
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->process();

		$this->assertSame( 0, $this->plugin()->sites()->count_dirty() );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_continue_soon_schedules_a_single_event(): void {
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->continue_soon();
		$this->queue->continue_soon();

		$this->assertSame( 1, $this->count_events( Queue::HOOK_CONTINUE ) );
	}

	public function test_daily_requests_a_full_scan_when_due(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();
		update_site_option( Queue::LAST_FULL_SCAN, time() - 8 * DAY_IN_SECONDS );

		$this->queue->daily();

		$this->assertTrue( $this->plugin()->sites()->find( $site_id )->dirty );
		$this->assertEqualsWithDelta( time(), (int) get_site_option( Queue::LAST_FULL_SCAN ), 5 );
	}

	public function test_daily_skips_the_full_scan_when_recent_and_removes_orphans(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->plugin()->sites()->insert_pending( 424242, get_current_network_id(), 'gone.test/' );
		$this->mark_all_clean();
		update_site_option( Queue::LAST_FULL_SCAN, time() - DAY_IN_SECONDS );

		$this->queue->daily();

		$this->assertFalse( $this->plugin()->sites()->find( $site_id )->dirty );
		$this->assertNull( $this->plugin()->sites()->find( 424242 ) );
	}

	public function test_recompute_alerts_uses_stored_data_and_skips_unscanned_sites(): void {
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3002, [ 'users_count' => 0 ] );

		$this->assertSame( 1, $this->queue->recompute_alerts() );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3001 )->alert_level );
		$this->assertSame( 0, $this->plugin()->sites()->find( 3002 )->alert_level );
	}

	public function test_settings_update_schedules_a_recompute(): void {
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}
}
```

`tests/php/Scan/InvalidationTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class InvalidationTest extends TestCase {

	private int $site_id;

	public function set_up(): void {
		parent::set_up();
		$this->site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();
	}

	public function tear_down(): void {
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
	}

	private function dirty( int $site_id ): bool {
		return $this->plugin()->sites()->find( $site_id )->dirty;
	}

	public function test_a_new_site_gets_a_pending_row(): void {
		$site_id = self::factory()->blog->create();
		$record  = $this->plugin()->sites()->find( $site_id );

		$this->assertNotNull( $record );
		$this->assertTrue( $record->dirty );
		$this->assertNull( $record->scanned_at );
	}

	public function test_a_deleted_site_loses_its_rows(): void {
		$this->plugin()->extensions()->replace_for_site( $this->site_id, [ 'acme/acme.php' ], 'theme', 'theme' );

		wp_delete_site( $this->site_id );

		$this->assertNull( $this->plugin()->sites()->find( $this->site_id ) );
		$this->assertSame( [], $this->plugin()->extensions()->for_site( $this->site_id ) );
	}

	public function test_a_local_plugin_change_marks_only_the_current_site(): void {
		switch_to_blog( $this->site_id );
		do_action( 'activated_plugin', 'acme/acme.php', false );
		restore_current_blog();

		$this->assertTrue( $this->dirty( $this->site_id ) );
		$this->assertFalse( $this->dirty( get_main_site_id() ) );
	}

	public function test_a_network_plugin_change_marks_every_site(): void {
		do_action( 'deactivated_plugin', 'acme/acme.php', true );

		$this->assertTrue( $this->dirty( $this->site_id ) );
		$this->assertTrue( $this->dirty( get_main_site_id() ) );
	}

	public function test_theme_switch_marks_the_site(): void {
		switch_to_blog( $this->site_id );
		do_action( 'switch_theme', 'Other', null, null );
		restore_current_blog();

		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_membership_changes_mark_the_site(): void {
		$user_id = self::factory()->user->create();

		add_user_to_blog( $this->site_id, $user_id, 'editor' );
		$this->assertTrue( $this->dirty( $this->site_id ) );

		$this->mark_all_clean();
		remove_user_from_blog( $user_id, $this->site_id );
		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_deleting_a_network_user_marks_all_their_sites(): void {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->site_id, $user_id, 'editor' );
		$this->mark_all_clean();

		do_action( 'wpmu_delete_user', $user_id, get_userdata( $user_id ) );

		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_option_and_status_changes_mark_the_site(): void {
		update_blog_option( $this->site_id, 'blogname', 'Renamed' );
		$this->assertTrue( $this->dirty( $this->site_id ) );

		$this->mark_all_clean();
		update_blog_status( $this->site_id, 'archived', '1' );
		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_publishing_updates_last_activity_without_marking(): void {
		switch_to_blog( $this->site_id );
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		restore_current_blog();

		$record = $this->plugin()->sites()->find( $this->site_id );
		$this->assertNotNull( $record->last_activity_gmt );
		$this->assertFalse( $record->dirty );
	}

	public function test_drafts_and_untracked_types_leave_last_activity_alone(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );

		switch_to_blog( $this->site_id );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create( [ 'post_type' => 'fixture_event' ] );
		restore_current_blog();

		$this->assertNull( $this->plugin()->sites()->find( $this->site_id )->last_activity_gmt );
	}

	public function test_handlers_do_nothing_before_the_schema_is_installed(): void {
		delete_site_option( Schema::OPTION );

		switch_to_blog( $this->site_id );
		do_action( 'switch_theme', 'Other', null, null );
		restore_current_blog();

		$this->assertFalse( $this->dirty( $this->site_id ) );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'QueueTest|InvalidationTest'`
Expected: échec. `QueueTest` signale `Class "MultisiteRadar\Scan\Queue" not found`, et plusieurs tests d'`InvalidationTest` échouent : les lignes ne sont ni marquées ni créées.

- [ ] **Step 3: Implémenter `MainSite`**

`includes/Support/MainSite.php` :

```php
<?php
namespace MultisiteRadar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Exécute du code dans le contexte du site principal (où vivent nos événements cron).
 */
final class MainSite {

	/**
	 * @return mixed Valeur renvoyée par le callback.
	 */
	public static function run( callable $callback ) {
		$main   = get_main_site_id();
		$switch = get_current_blog_id() !== $main;
		if ( $switch ) {
			switch_to_blog( $main );
		}
		try {
			return $callback();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}
}
```

- [ ] **Step 4: Implémenter `Queue`**

`includes/Scan/Queue.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Planification sur le site principal :
 * - traitement de la file toutes les 5 minutes, avec relance immédiate tant qu'il reste des sites ;
 * - passage quotidien : sites manquants, lignes orphelines, analyse complète périodique, recalcul des alertes.
 */
final class Queue {

	public const HOOK_PROCESS     = 'msradar_process_queue';
	public const HOOK_CONTINUE    = 'msradar_process_queue_continue';
	public const HOOK_DAILY       = 'msradar_daily';
	public const HOOK_RECOMPUTE   = 'msradar_recompute_alerts';
	public const SCHEDULE         = 'msradar_five_minutes';
	public const LAST_FULL_SCAN   = 'msradar_last_full_scan';
	private const RECOMPUTE_CHUNK = 200;

	private BatchRunner $runner;
	private SitesRepository $sites;
	private AlertEvaluator $evaluator;
	private Settings $settings;

	public function __construct( BatchRunner $runner, SitesRepository $sites, AlertEvaluator $evaluator, Settings $settings ) {
		$this->runner    = $runner;
		$this->sites     = $sites;
		$this->evaluator = $evaluator;
		$this->settings  = $settings;
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
		add_action( self::HOOK_PROCESS, [ $this, 'process' ] );
		add_action( self::HOOK_CONTINUE, [ $this, 'process' ] );
		add_action( self::HOOK_DAILY, [ $this, 'daily' ] );
		add_action( self::HOOK_RECOMPUTE, [ $this, 'recompute_alerts' ] );
		add_action( 'msradar_activated', [ $this, 'schedule' ] );
		add_action( 'msradar_deactivated', [ $this, 'unschedule' ] );
		add_action( 'msradar_settings_updated', [ $this, 'on_settings_updated' ] );
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * @param mixed $schedules Intervalles cron existants.
	 * @return array
	 */
	public function add_schedule( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : [];
		$schedules[ self::SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Multisite Radar)', 'multisite-radar' ),
		];
		return $schedules;
	}

	public function schedule(): void {
		MainSite::run(
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK_PROCESS ) ) {
					wp_schedule_event( time(), self::SCHEDULE, self::HOOK_PROCESS );
				}
				if ( false === wp_next_scheduled( self::HOOK_DAILY ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
				}
			}
		);
	}

	public function ensure_scheduled(): void {
		if ( is_main_site() ) {
			$this->schedule();
		}
	}

	public function unschedule(): void {
		MainSite::run(
			static function (): void {
				foreach ( [ self::HOOK_PROCESS, self::HOOK_CONTINUE, self::HOOK_DAILY, self::HOOK_RECOMPUTE ] as $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		);
	}

	public function process(): void {
		$result = $this->runner->run( BatchRunner::default_budget() );
		if ( ! $result['locked'] && $result['remaining'] > 0 ) {
			$this->continue_soon();
		}
	}

	public function continue_soon(): void {
		MainSite::run(
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK_CONTINUE ) ) {
					wp_schedule_single_event( time(), self::HOOK_CONTINUE );
				}
			}
		);
	}

	public function request_full_scan( int $network_id ): void {
		$this->sites->seed_from_blogs( $network_id );
		$this->sites->mark_all_dirty( $network_id );
		update_site_option( self::LAST_FULL_SCAN, time() );
		$this->continue_soon();
	}

	public function daily(): void {
		$network_id = get_current_network_id();
		$this->sites->seed_from_blogs( $network_id );
		$this->sites->delete_orphans();

		$days = max( 1, (int) $this->settings->get( 'scan.full_rescan_days', 7 ) );
		if ( (int) get_site_option( self::LAST_FULL_SCAN, 0 ) < time() - $days * DAY_IN_SECONDS ) {
			$this->request_full_scan( $network_id );
		} elseif ( $this->sites->count_dirty() > 0 ) {
			$this->continue_soon();
		}

		$this->recompute_alerts();
	}

	/**
	 * Recalcule les alertes de tous les sites déjà analysés, à partir des données stockées.
	 *
	 * @return int Nombre de sites recalculés.
	 */
	public function recompute_alerts(): int {
		$now   = time();
		$after = 0;
		$count = 0;
		do {
			$ids = $this->sites->ids_after( $after, self::RECOMPUTE_CHUNK );
			foreach ( $this->sites->find_many( $ids ) as $record ) {
				if ( null === $record->scanned_at ) {
					continue;
				}
				$this->evaluator->apply( $record, $now );
				$this->sites->save_alerts( $record );
				++$count;
			}
			if ( [] !== $ids ) {
				$after = (int) end( $ids );
			}
		} while ( self::RECOMPUTE_CHUNK === count( $ids ) );

		return $count;
	}

	public function on_settings_updated(): void {
		$this->evaluator->reset();
		MainSite::run(
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK_RECOMPUTE ) ) {
					wp_schedule_single_event( time(), self::HOOK_RECOMPUTE );
				}
			}
		);
	}

	public static function next_run(): ?int {
		return MainSite::run(
			static function (): ?int {
				$next = wp_next_scheduled( self::HOOK_PROCESS );
				return false === $next ? null : (int) $next;
			}
		);
	}
}
```

Si PHPCS ne connaît pas le code d'erreur indiqué dans le commentaire `phpcs:ignore` de `cron_schedules`, reprendre le code exact qu'il signale sur cette ligne. Même consigne pour tous les `phpcs:ignore` du plan.

- [ ] **Step 5: Implémenter `Invalidation`**

`includes/Scan/Invalidation.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;
use WP_Post;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Marque « à rafraîchir » les sites touchés par un événement WordPress. Rien n'est analysé ici :
 * chaque gestionnaire coûte une requête UPDATE au plus.
 */
final class Invalidation {

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private Settings $settings;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, Settings $settings ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->settings   = $settings;
	}

	public function register(): void {
		add_action( 'activated_plugin', [ $this, 'on_plugin_change' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_plugin_change' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'mark_current_site' ] );
		add_action( 'set_user_role', [ $this, 'mark_current_site' ] );
		add_action( 'deleted_user', [ $this, 'mark_current_site' ] );
		add_action( 'add_user_to_blog', [ $this, 'on_user_added' ], 10, 3 );
		add_action( 'remove_user_from_blog', [ $this, 'on_user_removed' ], 10, 2 );
		add_action( 'wpmu_delete_user', [ $this, 'on_network_user_deleted' ] );
		foreach ( [ 'blogname', 'blog_public', 'siteurl', 'home' ] as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'mark_current_site' ] );
		}
		foreach ( [ 'make_spam_blog', 'make_ham_blog', 'archive_blog', 'unarchive_blog', 'make_delete_blog', 'make_undelete_blog' ] as $hook ) {
			add_action( $hook, [ $this, 'mark_site' ] );
		}
		add_action( 'wp_initialize_site', [ $this, 'on_site_initialized' ], 100 );
		add_action( 'wp_delete_site', [ $this, 'on_site_deleted' ] );
		add_action( 'transition_post_status', [ $this, 'on_post_status' ], 10, 3 );
	}

	public function mark_current_site(): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( [ get_current_blog_id() ] );
		}
	}

	/**
	 * @param int|string $site_id
	 */
	public function mark_site( $site_id ): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( [ (int) $site_id ] );
		}
	}

	/**
	 * @param string $plugin       Fichier du plugin.
	 * @param bool   $network_wide Activation ou désactivation sur tout le réseau.
	 */
	public function on_plugin_change( $plugin, $network_wide = false ): void {
		if ( ! $this->ready() ) {
			return;
		}
		if ( $network_wide ) {
			$this->sites->mark_all_dirty( get_current_network_id() );
			return;
		}
		$this->sites->mark_dirty( [ get_current_blog_id() ] );
	}

	/**
	 * @param int    $user_id
	 * @param string $role
	 * @param int    $blog_id
	 */
	public function on_user_added( $user_id, $role, $blog_id ): void {
		$this->mark_site( $blog_id );
	}

	/**
	 * @param int $user_id
	 * @param int $blog_id
	 */
	public function on_user_removed( $user_id, $blog_id ): void {
		$this->mark_site( $blog_id );
	}

	/**
	 * Appelé avant la suppression : on connaît encore les sites de l'utilisateur.
	 *
	 * @param int $user_id
	 */
	public function on_network_user_deleted( $user_id ): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( array_map( 'intval', array_keys( get_blogs_of_user( (int) $user_id, true ) ) ) );
		}
	}

	public function on_site_initialized( WP_Site $site ): void {
		if ( $this->ready() ) {
			// WP_Site::$site_id contient l'ID du réseau.
			$this->sites->insert_pending( (int) $site->blog_id, (int) $site->site_id, $site->domain . $site->path );
		}
	}

	public function on_site_deleted( WP_Site $site ): void {
		if ( $this->ready() ) {
			$this->sites->delete( (int) $site->blog_id );
			$this->extensions->delete_for_site( (int) $site->blog_id );
		}
	}

	/**
	 * Une publication ne relance pas d'analyse : elle avance seulement la date de dernière activité.
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param mixed  $post
	 */
	public function on_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof WP_Post || ! $this->ready() ) {
			return;
		}
		$types = (array) $this->settings->get( 'scan.activity_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return;
		}
		$gmt = '' !== $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt
			? $post->post_modified_gmt
			: current_time( 'mysql', true );
		$this->sites->update_last_activity( get_current_blog_id(), $gmt );
	}

	private function ready(): bool {
		return Schema::is_current();
	}
}
```

- [ ] **Step 6: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Scan\Invalidation;` et `use MultisiteRadar\Scan\Queue;` ;
- ajouter les propriétés `private ?Queue $queue = null;` et `private ?Invalidation $invalidation = null;` ;
- dans `boot()`, après `$this->probe()->register();`, ajouter :

```php
		$this->queue()->register();
		$this->invalidation()->register();
```

- ajouter les méthodes :

```php
	public function queue(): Queue {
		return $this->queue ??= new Queue( $this->runner(), $this->sites(), $this->evaluator(), $this->settings() );
	}

	public function invalidation(): Invalidation {
		return $this->invalidation ??= new Invalidation( $this->sites(), $this->extensions(), $this->settings() );
	}
```

- [ ] **Step 7: Lancer toute la suite**

Run: `bin/test.sh`
Expected: `OK`. Les tests des tâches précédentes créent des sites : l'invalidation leur ajoute désormais une ligne « en attente ». Ils restent verts, car `seed_from_blogs()` et `insert_pending()` utilisent `INSERT IGNORE`.

- [ ] **Step 8: Vérifier le planning dans le WordPress local**

```bash
cd /home/dev/wp
wp plugin deactivate multisite-radar --network && wp plugin activate multisite-radar --network
wp cron event list --fields=hook,recurrence --format=csv | grep msradar
wp cron event run msradar_process_queue
wp eval 'global $wpdb; print_r( $wpdb->get_row( "SELECT site_id, name, users_count, content_count, registry_status, scanned_at FROM {$wpdb->base_prefix}msradar_sites WHERE site_id = 1", ARRAY_A ) );'
cd /home/dev/wp-network-plugin-utilities
```

Expected :
- la liste des événements contient `msradar_process_queue,5 minutes` et `msradar_daily,1 day` ;
- après l'exécution, la ligne du site 1 a un `name`, des compteurs et une date `scanned_at`.

- [ ] **Step 9: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: schedule queue processing and invalidate sites on WordPress events"
```

---
### Task 11: Couche de lecture (SitesQuery, AlertsQuery)

**Files:**
- Create: `includes/Query/SitesQuery.php`, `includes/Query/AlertsQuery.php`
- Modify: `includes/Storage/SitesRepository.php`, `includes/Plugin.php`
- Test: `tests/php/Query/SitesQueryTest.php`, `tests/php/Query/AlertsQueryTest.php`

**Interfaces:**
- Consumes : `SitesRepository`, `ExtensionsRepository::for_site()`, `AlertFormatter::format()`, `RuleRegistry::all()`, `Settings::get( 'scan.analysis_plugins' )`, `RegistryProbe::STATUS_*`.
- Produces :
  - **`SitesRepository`** :
    - `ORDERBY` : clés publiques `id`, `name`, `last_activity`, `users_count`, `content_count`, `media_count`, `disk_bytes`, `db_bytes`, `alert_level`, `scanned_at`, associées à des colonnes ;
    - `query( array $args ): array{items: SiteRecord[], total: int}` ;
    - `alert_counts( int $network_id, string[] $rule_ids ): array{total, pending, with_alerts, error, warning, info, rules: array<string,int>}`.
  - **Constantes de `SitesQuery`** :
    - `ALERT_LEVELS` (`none` 0, `info` 1, `warning` 2, `error` 3) ;
    - `STATUSES` (`public`, `private`, `archived`, `spam`, `deleted`) ;
    - `REGISTRY_STATUSES`.
  - **Méthodes de `SitesQuery`** :
    - `static defaults(): array` ;
    - `list( array $args ): array{items: array[], total: int}`. Arguments : `page`, `per_page` (1 à 100), `search`, `orderby`, `order`, `alert_level` (noms), `status`, `theme`, `plugin`, `has_users` (`?bool`), `inactive_since` (`?string`, GMT `Y-m-d H:i:s`), `registry_status`, `rule`.
    - `get( int $site_id ): ?array`, qui renvoie `null` si le site n'est pas dans le réseau courant ;
    - `summary( SiteRecord ): array`.
  - **Champs de `summary()`** :
    - identité et liens : `id`, `name`, `url`, `admin_url` ;
    - état : `status{public, archived, spam, deleted}`, `theme{stylesheet, template}` ;
    - compteurs : `users_count`, `admins_count`, `content_count`, `media_count`, `disk_bytes`, `db_bytes`, `autoload_bytes` ;
    - alertes : `alert_level` (nom), `alerts_count`, `alert_rules[]` ;
    - analyse : `last_activity_gmt`, `registry_status`, `pending`, `dirty`, `scanned_at_gmt`.

    Les dates sont au format RFC 3339, ou `null`.
  - **Champs ajoutés par `get()`** : `post_types`, `taxonomies` (filtrés par `scan.analysis_plugins`), `users`, `last_content`, `options`, `alerts[{rule, severity, label, message}]`, `extensions{plugins_local[{file, name, version, installed}], network_plugins_count, theme{stylesheet, template, name, version, installed}}`, `scan_error`.
  - **`AlertsQuery`** : `summary( int $network_id ): array{total_sites, scanned_sites, pending_sites, sites_with_alerts, by_severity{error, warning, info}, by_rule[{rule, label, count}]}`.
  - **`Plugin`** : `sites_query(): SitesQuery`, `alerts_query(): AlertsQuery`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/Query/SitesQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Tests\TestCase;

final class SitesQueryTest extends TestCase {

	private SitesQuery $query;

	public function set_up(): void {
		parent::set_up();
		$this->query = $this->plugin()->sites_query();
		$scanned     = '2026-09-01 00:00:00';

		$this->make_record( 101, [ 'name' => 'Alpha', 'url' => 'https://alpha.test/', 'users_count' => 3, 'theme_stylesheet' => 'astra', 'theme_template' => 'astra', 'last_activity_gmt' => '2026-08-30 10:00:00', 'scanned_at' => $scanned, 'registry_status' => 'fresh' ] );
		$this->make_record( 102, [ 'name' => 'Beta 100%', 'url' => 'https://beta.test/', 'users_count' => 0, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'theme_stylesheet' => 'child', 'theme_template' => 'astra', 'last_activity_gmt' => '2024-01-01 00:00:00', 'scanned_at' => $scanned, 'registry_status' => 'stale', 'is_archived' => true ] );
		$this->make_record( 103, [ 'name' => 'Gamma_x', 'url' => 'https://gamma.test/', 'users_count' => 12, 'alert_level' => 2, 'alerts_count' => 1, 'alert_rules' => ',inactive,', 'theme_stylesheet' => 'tt5', 'theme_template' => 'tt5', 'scanned_at' => $scanned, 'is_public' => false ] );
		$this->make_record( 104, [ 'url' => 'https://pending.test/' ] );
		$this->plugin()->extensions()->replace_for_site( 101, [ 'acme/acme.php' ], 'astra', 'astra' );
	}

	private function ids( array $args ): array {
		return wp_list_pluck( $this->query->list( $args )['items'], 'id' );
	}

	public function test_lists_the_current_network_sorted_by_name(): void {
		$this->make_record( 105, [ 'name' => 'Other network', 'network_id' => 2 ] );

		$result = $this->query->list( [] );

		$this->assertSame( 4, $result['total'] );
		$this->assertSame( [ 104, 101, 102, 103 ], wp_list_pluck( $result['items'], 'id' ) );
	}

	public function test_search_treats_sql_wildcards_literally(): void {
		$this->assertSame( [ 102 ], $this->ids( [ 'search' => '%' ] ) );
		$this->assertSame( [ 103 ], $this->ids( [ 'search' => '_' ] ) );
		$this->assertSame( [ 101 ], $this->ids( [ 'search' => 'alpha.test' ] ) );
	}

	public function test_filters(): void {
		update_site_option( 'active_sitewide_plugins', [ 'net/net.php' => time() ] );

		$this->assertSame( [ 102 ], $this->ids( [ 'alert_level' => [ 'error' ] ] ) );
		$this->assertSame( [ 102, 103 ], $this->ids( [ 'alert_level' => [ 'warning', 'error' ] ] ) );
		$this->assertSame( [ 102 ], $this->ids( [ 'status' => [ 'archived' ] ] ) );
		$this->assertSame( [ 103 ], $this->ids( [ 'status' => [ 'private' ] ] ) );
		$this->assertSame( [ 104, 101 ], $this->ids( [ 'status' => [ 'public' ] ] ) );
		$this->assertSame( [ 101, 102 ], $this->ids( [ 'theme' => 'astra' ] ) );
		$this->assertSame( [ 101 ], $this->ids( [ 'plugin' => 'acme/acme.php' ] ) );
		$this->assertSame( [ 104, 101, 102, 103 ], $this->ids( [ 'plugin' => 'net/net.php' ] ), 'A network-active plugin is used everywhere.' );
		$this->assertSame( [ 102 ], $this->ids( [ 'has_users' => false ] ), 'Unscanned sites are not counted as empty.' );
		$this->assertSame( [ 101, 103 ], $this->ids( [ 'has_users' => true ] ) );
		$this->assertSame( [ 102, 103 ], $this->ids( [ 'inactive_since' => '2025-01-01 00:00:00' ] ) );
		$this->assertSame( [ 104, 102, 103 ], $this->ids( [ 'registry_status' => [ 'stale', 'missing' ] ] ) );
		$this->assertSame( [ 102 ], $this->ids( [ 'rule' => 'no_users' ] ) );
	}

	public function test_sorting_and_pagination(): void {
		$this->assertSame( [ 103, 101, 102, 104 ], $this->ids( [ 'orderby' => 'users_count', 'order' => 'desc' ] ) );
		$this->assertSame( [ 104, 101, 102, 103 ], $this->ids( [ 'orderby' => 'bogus' ] ), 'Unknown orderby falls back to name.' );

		$page = $this->query->list( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( [ 102, 103 ], wp_list_pluck( $page['items'], 'id' ) );
		$this->assertSame( 4, $page['total'] );

		$beyond = $this->query->list( [ 'page' => 99 ] );
		$this->assertSame( [], $beyond['items'] );
		$this->assertSame( 4, $beyond['total'] );

		$this->assertCount( 4, $this->query->list( [ 'per_page' => 500 ] )['items'], 'per_page is clamped to 100, not rejected, at this layer.' );
	}

	public function test_summary_shape(): void {
		$items = $this->query->list( [ 'search' => 'Beta' ] )['items'];
		$beta  = $items[0];

		$this->assertSame( 'error', $beta['alert_level'] );
		$this->assertSame( [ 'no_users' ], $beta['alert_rules'] );
		$this->assertTrue( $beta['status']['archived'] );
		$this->assertSame( [ 'stylesheet' => 'child', 'template' => 'astra' ], $beta['theme'] );
		$this->assertSame( '2024-01-01T00:00:00', $beta['last_activity_gmt'] );
		$this->assertSame( '2026-09-01T00:00:00', $beta['scanned_at_gmt'] );
		$this->assertSame( 'https://beta.test/wp-admin/', $beta['admin_url'] );
		$this->assertFalse( $beta['pending'] );
		$this->assertTrue( $this->query->list( [ 'search' => 'pending' ] )['items'][0]['pending'] );
	}

	public function test_get_returns_details_and_applies_the_plugin_filter(): void {
		$this->make_record(
			201,
			[
				'name'             => 'Detail',
				'url'              => 'https://detail.test/',
				'scanned_at'       => '2026-09-01 00:00:00',
				'theme_stylesheet' => 'missing-theme',
				'theme_template'   => 'missing-theme',
				'data'             => [
					'post_types'   => [
						[ 'name' => 'post', 'builtin' => true, 'origin' => [ 'kind' => 'core', 'slug' => '' ] ],
						[ 'name' => 'event', 'builtin' => false, 'origin' => [ 'kind' => 'plugin', 'slug' => 'acme' ] ],
						[ 'name' => 'book', 'builtin' => false, 'origin' => [ 'kind' => 'plugin', 'slug' => 'other' ] ],
					],
					'taxonomies'   => [],
					'alerts'       => [ [ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ] ],
					'last_content' => [ 'id' => 5, 'type' => 'post', 'title' => 'Hi', 'date_gmt' => '2026-01-02 03:04:05' ],
					'options'      => [ 'siteurl' => 'https://detail.test' ],
				],
			]
		);
		$this->plugin()->extensions()->replace_for_site( 201, [ 'acme/acme.php' ], 'missing-theme', 'missing-theme' );

		$detail = $this->query->get( 201 );

		$this->assertSame( 'Inactive for 8 months', $detail['alerts'][0]['message'] );
		$this->assertSame( '2026-01-02T03:04:05', $detail['last_content']['date_gmt'] );
		$this->assertSame( 'https://detail.test/wp-admin/', $detail['admin_url'] );
		$this->assertSame( [ 'file' => 'acme/acme.php', 'name' => 'acme/acme.php', 'version' => '', 'installed' => false ], $detail['extensions']['plugins_local'][0] );
		$this->assertFalse( $detail['extensions']['theme']['installed'] );
		$this->assertCount( 3, $detail['post_types'] );

		$this->plugin()->settings()->update( [ 'scan' => [ 'analysis_plugins' => [ 'acme' ] ] ] );
		$this->assertSame( [ 'post', 'event' ], wp_list_pluck( $this->query->get( 201 )['post_types'], 'name' ) );

		$this->assertNull( $this->query->get( 999999 ) );
		$this->make_record( 202, [ 'network_id' => 2 ] );
		$this->assertNull( $this->query->get( 202 ), 'Sites of another network are hidden.' );
	}
}
```

`tests/php/Query/AlertsQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class AlertsQueryTest extends TestCase {

	public function test_summary_counts_sites_by_severity_and_rule(): void {
		$scanned = '2026-09-01 00:00:00';
		$this->make_record( 301, [ 'scanned_at' => $scanned, 'alert_level' => 3, 'alerts_count' => 2, 'alert_rules' => ',no_users,high_media,' ] );
		$this->make_record( 302, [ 'scanned_at' => $scanned, 'alert_level' => 2, 'alerts_count' => 1, 'alert_rules' => ',inactive,' ] );
		$this->make_record( 303, [ 'scanned_at' => $scanned ] );
		$this->make_record( 304 );

		$summary = $this->plugin()->alerts_query()->summary( get_current_network_id() );

		$this->assertSame( 4, $summary['total_sites'] );
		$this->assertSame( 3, $summary['scanned_sites'] );
		$this->assertSame( 1, $summary['pending_sites'] );
		$this->assertSame( 2, $summary['sites_with_alerts'] );
		$this->assertSame( [ 'error' => 1, 'warning' => 1, 'info' => 0 ], $summary['by_severity'] );
		$this->assertSame(
			[
				[ 'rule' => 'no_users', 'label' => 'Site without users', 'count' => 1 ],
				[ 'rule' => 'inactive', 'label' => 'Inactive site', 'count' => 1 ],
				[ 'rule' => 'high_media', 'label' => 'Many media files', 'count' => 1 ],
			],
			$summary['by_rule']
		);
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'SitesQueryTest|AlertsQueryTest'`
Expected: échec avec `Call to undefined method MultisiteRadar\Plugin::sites_query()`.

- [ ] **Step 3: Ajouter `query()` et `alert_counts()` à `SitesRepository`**

Dans `includes/Storage/SitesRepository.php`, ajouter en tête de classe :

```php
	/**
	 * Tri autorisé : clé publique => colonne.
	 */
	public const ORDERBY = [
		'id'            => 'site_id',
		'name'          => 'name',
		'last_activity' => 'last_activity_gmt',
		'users_count'   => 'users_count',
		'content_count' => 'content_count',
		'media_count'   => 'media_count',
		'disk_bytes'    => 'disk_bytes',
		'db_bytes'      => 'db_bytes',
		'alert_level'   => 'alert_level',
		'scanned_at'    => 'scanned_at',
	];

	private const STATUS_CLAUSES = [
		'public'   => '(is_public = 1 AND is_archived = 0 AND is_spam = 0 AND is_deleted = 0)',
		'private'  => 'is_public = 0',
		'archived' => 'is_archived = 1',
		'spam'     => 'is_spam = 1',
		'deleted'  => 'is_deleted = 1',
	];
```

puis ces méthodes :

```php
	/**
	 * @param array $args Arguments déjà normalisés par SitesQuery::list().
	 * @return array{items: SiteRecord[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$clauses = [ 'network_id = %d' ];
		$params  = [ (int) $args['network_id'] ];

		if ( '' !== $args['search'] ) {
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '(name LIKE %s OR url LIKE %s)';
			array_push( $params, $like, $like );
		}
		if ( [] !== $args['alert_level'] ) {
			$clauses[] = 'alert_level IN (' . implode( ',', array_fill( 0, count( $args['alert_level'] ), '%d' ) ) . ')';
			$params    = array_merge( $params, array_map( 'intval', $args['alert_level'] ) );
		}
		$status_parts = array_values( array_intersect_key( self::STATUS_CLAUSES, array_flip( $args['status'] ) ) );
		if ( [] !== $status_parts ) {
			$clauses[] = '(' . implode( ' OR ', $status_parts ) . ')';
		}
		if ( '' !== $args['theme'] ) {
			$clauses[] = '(theme_stylesheet = %s OR theme_template = %s)';
			array_push( $params, $args['theme'], $args['theme'] );
		}
		if ( '' !== $args['plugin'] ) {
			$clauses[] = "site_id IN (SELECT site_id FROM %i WHERE type = 'plugin' AND slug = %s)";
			array_push( $params, Schema::extensions_table(), $args['plugin'] );
		}
		if ( true === $args['has_users'] ) {
			$clauses[] = 'users_count > 0';
		} elseif ( false === $args['has_users'] ) {
			$clauses[] = '(users_count = 0 AND scanned_at IS NOT NULL)';
		}
		if ( null !== $args['inactive_since'] ) {
			$clauses[] = '(scanned_at IS NOT NULL AND (last_activity_gmt IS NULL OR last_activity_gmt < %s))';
			$params[]  = $args['inactive_since'];
		}
		if ( [] !== $args['registry_status'] ) {
			$clauses[] = 'registry_status IN (' . implode( ',', array_fill( 0, count( $args['registry_status'] ), '%s' ) ) . ')';
			$params    = array_merge( $params, $args['registry_status'] );
		}
		if ( '' !== $args['rule'] ) {
			$clauses[] = 'alert_rules LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( ',' . $args['rule'] . ',' ) . '%';
		}

		$table    = Schema::sites_table();
		$where    = implode( ' AND ', $clauses );
		$column   = self::ORDERBY[ $args['orderby'] ] ?? 'name';
		$order    = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page = (int) $args['per_page'];
		$offset   = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ne contient que des fragments fixes et des placeholders ; $order vaut ASC ou DESC.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $params ) )
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where} ORDER BY %i {$order}, site_id ASC LIMIT %d OFFSET %d",
				array_merge( [ $table ], $params, [ $column, $per_page, $offset ] )
			),
			ARRAY_A
		);
		// phpcs:enable

		return [
			'items' => array_map( [ SiteRecord::class, 'from_row' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * @param string[] $rule_ids
	 * @return array{total: int, pending: int, with_alerts: int, error: int, warning: int, info: int, rules: array<string, int>}
	 */
	public function alert_counts( int $network_id, array $rule_ids ): array {
		global $wpdb;
		$table = Schema::sites_table();
		$row   = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(scanned_at IS NULL) AS pending, SUM(alert_level > 0) AS with_alerts, SUM(alert_level = 3) AS error, SUM(alert_level = 2) AS warning, SUM(alert_level = 1) AS info FROM %i WHERE network_id = %d',
				$table,
				$network_id
			),
			ARRAY_A
		);

		$rules = [];
		foreach ( $rule_ids as $rule_id ) {
			$rules[ $rule_id ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE network_id = %d AND alert_rules LIKE %s',
					$table,
					$network_id,
					'%' . $wpdb->esc_like( ',' . $rule_id . ',' ) . '%'
				)
			);
		}

		return [
			'total'       => (int) ( $row['total'] ?? 0 ),
			'pending'     => (int) ( $row['pending'] ?? 0 ),
			'with_alerts' => (int) ( $row['with_alerts'] ?? 0 ),
			'error'       => (int) ( $row['error'] ?? 0 ),
			'warning'     => (int) ( $row['warning'] ?? 0 ),
			'info'        => (int) ( $row['info'] ?? 0 ),
			'rules'       => $rules,
		];
	}
```

- [ ] **Step 4: Implémenter `SitesQuery`**

`includes/Query/SitesQuery.php` :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture des sites pour l'extérieur (REST, WP-CLI, plus tard l'interface) : normalise les arguments et met en forme.
 */
final class SitesQuery {

	public const ALERT_LEVELS      = [
		'none'    => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	];
	public const STATUSES          = [ 'public', 'private', 'archived', 'spam', 'deleted' ];
	public const REGISTRY_STATUSES = [ RegistryProbe::STATUS_FRESH, RegistryProbe::STATUS_STALE, RegistryProbe::STATUS_MISSING ];

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private AlertFormatter $formatter;
	private Settings $settings;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, AlertFormatter $formatter, Settings $settings ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->formatter  = $formatter;
		$this->settings   = $settings;
	}

	public static function defaults(): array {
		return [
			'page'            => 1,
			'per_page'        => 20,
			'search'          => '',
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
	}

	/**
	 * @return array{items: array[], total: int}
	 */
	public function list( array $args ): array {
		$args   = array_merge( self::defaults(), $args );
		$plugin = (string) $args['plugin'];
		$query  = [
			'network_id'      => get_current_network_id(),
			'page'            => max( 1, (int) $args['page'] ),
			'per_page'        => min( 100, max( 1, (int) $args['per_page'] ) ),
			'search'          => trim( (string) $args['search'] ),
			'orderby'         => isset( SitesRepository::ORDERBY[ $args['orderby'] ] ) ? (string) $args['orderby'] : 'name',
			'order'           => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'alert_level'     => array_values( array_intersect_key( self::ALERT_LEVELS, array_flip( array_map( 'strval', (array) $args['alert_level'] ) ) ) ),
			'status'          => array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) ),
			'theme'           => (string) $args['theme'],
			'plugin'          => $this->is_network_active( $plugin ) ? '' : $plugin,
			'has_users'       => null === $args['has_users'] ? null : (bool) $args['has_users'],
			'inactive_since'  => null === $args['inactive_since'] ? null : (string) $args['inactive_since'],
			'registry_status' => array_values( array_intersect( array_map( 'strval', (array) $args['registry_status'] ), self::REGISTRY_STATUSES ) ),
			'rule'            => (string) $args['rule'],
		];

		$result = $this->sites->query( $query );
		return [
			'items' => array_map( [ $this, 'summary' ], $result['items'] ),
			'total' => $result['total'],
		];
	}

	public function get( int $site_id ): ?array {
		$record = $this->sites->find( $site_id );
		if ( null === $record || get_current_network_id() !== $record->network_id ) {
			return null;
		}

		$data = $record->data;
		$last = is_array( $data['last_content'] ?? null ) ? $data['last_content'] : null;
		if ( null !== $last ) {
			$last['date_gmt'] = self::date( (string) ( $last['date_gmt'] ?? '' ) );
		}

		return array_merge(
			$this->summary( $record ),
			[
				'post_types'   => $this->filter_custom( (array) ( $data['post_types'] ?? [] ) ),
				'taxonomies'   => $this->filter_custom( (array) ( $data['taxonomies'] ?? [] ) ),
				'users'        => is_array( $data['users'] ?? null ) ? $data['users'] : [
					'by_role'    => [],
					'privileged' => [],
				],
				'last_content' => $last,
				'options'      => is_array( $data['options'] ?? null ) ? $data['options'] : [],
				'alerts'       => $this->formatter->format( (array) ( $data['alerts'] ?? [] ) ),
				'extensions'   => $this->extensions_for( $record ),
				'scan_error'   => is_array( $data['scan_error'] ?? null ) ? $data['scan_error'] : null,
			]
		);
	}

	public function summary( SiteRecord $record ): array {
		$siteurl = (string) ( $record->data['options']['siteurl'] ?? '' );
		$base    = '' !== $siteurl ? $siteurl : $record->url;

		return [
			'id'                => $record->site_id,
			'name'              => $record->name,
			'url'               => $record->url,
			'admin_url'         => '' !== $base ? trailingslashit( $base ) . 'wp-admin/' : '',
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
			'alert_level'       => (string) ( array_search( $record->alert_level, self::ALERT_LEVELS, true ) ?: 'none' ),
			'alerts_count'      => $record->alerts_count,
			'alert_rules'       => $record->alert_rule_ids(),
			'registry_status'   => $record->registry_status,
			'pending'           => null === $record->scanned_at,
			'dirty'             => $record->dirty,
			'scanned_at_gmt'    => self::date( (string) $record->scanned_at ),
		];
	}

	private static function date( string $gmt ): ?string {
		return '' === $gmt || '0000-00-00 00:00:00' === $gmt ? null : mysql_to_rfc3339( $gmt );
	}

	private function is_network_active( string $plugin ): bool {
		return '' !== $plugin && array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) );
	}

	/**
	 * Réglage « limiter l'analyse aux plugins » : garde les éléments natifs et ceux des plugins choisis.
	 */
	private function filter_custom( array $items ): array {
		$allowed = array_values( array_filter( (array) $this->settings->get( 'scan.analysis_plugins', [] ), 'is_string' ) );
		if ( [] === $allowed ) {
			return array_values( $items );
		}
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $allowed ): bool {
					if ( ! is_array( $item ) ) {
						return false;
					}
					if ( ! empty( $item['builtin'] ) ) {
						return true;
					}
					return 'plugin' === ( $item['origin']['kind'] ?? '' ) && in_array( $item['origin']['slug'] ?? '', $allowed, true );
				}
			)
		);
	}

	private function extensions_for( SiteRecord $record ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$plugins   = [];
		foreach ( $this->extensions->for_site( $record->site_id ) as $row ) {
			if ( ExtensionsRepository::TYPE_PLUGIN !== $row['type'] ) {
				continue;
			}
			$plugin    = $installed[ $row['slug'] ] ?? null;
			$plugins[] = [
				'file'      => $row['slug'],
				'name'      => null !== $plugin ? (string) $plugin['Name'] : $row['slug'],
				'version'   => null !== $plugin ? (string) $plugin['Version'] : '',
				'installed' => null !== $plugin,
			];
		}

		return [
			'plugins_local'         => $plugins,
			'network_plugins_count' => count( (array) get_site_option( 'active_sitewide_plugins', [] ) ),
			'theme'                 => array_merge(
				[
					'stylesheet' => $record->theme_stylesheet,
					'template'   => $record->theme_template,
				],
				$this->theme( $record->theme_stylesheet )
			),
		];
	}

	private function theme( string $stylesheet ): array {
		$theme = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;
		if ( null === $theme || ! $theme->exists() ) {
			return [
				'name'      => $stylesheet,
				'version'   => '',
				'installed' => false,
			];
		}
		return [
			'name'      => (string) $theme->get( 'Name' ),
			'version'   => (string) $theme->get( 'Version' ),
			'installed' => true,
		];
	}
}
```

- [ ] **Step 5: Implémenter `AlertsQuery`**

`includes/Query/AlertsQuery.php` :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Synthèse des alertes du réseau : sites par gravité maximale et par règle.
 */
final class AlertsQuery {

	private SitesRepository $sites;
	private RuleRegistry $rules;

	public function __construct( SitesRepository $sites, RuleRegistry $rules ) {
		$this->sites = $sites;
		$this->rules = $rules;
	}

	public function summary( int $network_id ): array {
		$rules   = $this->rules->all();
		$counts  = $this->sites->alert_counts( $network_id, array_keys( $rules ) );
		$by_rule = [];
		foreach ( $rules as $id => $rule ) {
			$by_rule[] = [
				'rule'  => $id,
				'label' => $rule->label(),
				'count' => $counts['rules'][ $id ] ?? 0,
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
}
```

- [ ] **Step 6: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Query\AlertsQuery;` et `use MultisiteRadar\Query\SitesQuery;` ;
- ajouter les propriétés `private ?SitesQuery $sites_query = null;` et `private ?AlertsQuery $alerts_query = null;` ;
- ajouter les méthodes :

```php
	public function sites_query(): SitesQuery {
		return $this->sites_query ??= new SitesQuery( $this->sites(), $this->extensions(), $this->formatter(), $this->settings() );
	}

	public function alerts_query(): AlertsQuery {
		return $this->alerts_query ??= new AlertsQuery( $this->sites(), $this->rules() );
	}
```

- [ ] **Step 7: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 8: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add sites and alerts query services"
```

---

### Task 12: API REST `multisite-radar/v1`

**Files:**
- Create: `includes/Rest/Controller.php`, `includes/Rest/SitesController.php`, `includes/Rest/ScanController.php`, `includes/Rest/SettingsController.php`, `includes/Rest/AlertsController.php`
- Create: `tests/php/RestTestCase.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Rest/SitesControllerTest.php`, `tests/php/Rest/ScanControllerTest.php`, `tests/php/Rest/SettingsControllerTest.php`, `tests/php/Rest/AlertsControllerTest.php`

**Interfaces:**
- Consumes :
  - `SitesQuery::list()` et `SitesQuery::get()` ;
  - `AlertsQuery::summary()` ;
  - `SitesRepository` : `mark_dirty`, `count_all`, `count_dirty`, `count_pending` ;
  - `BatchRunner::run()` et `BatchRunner::default_budget()` ;
  - `Queue` : `request_full_scan()`, `continue_soon()`, `next_run()`, `LAST_FULL_SCAN` ;
  - `Lock::is_locked()` ;
  - `Settings` : `all()`, `update()`, `schema()` ;
  - `RuleRegistry::get()` ;
  - `Capabilities` ;
  - `Installer::maybe_upgrade()`.
- Produces :
  - **Routes `multisite-radar/v1`** :

    | Route | Capacité | Réponse |
    |---|---|---|
    | `GET /sites` | `msradar_view` | Liste de résumés, en-têtes `X-WP-Total` et `X-WP-TotalPages` |
    | `GET /sites/{id}` | `msradar_view` | Détail, ou 404 `msradar_site_not_found` |
    | `POST /scan` | `msradar_manage` | `{scope: all\|dirty\|ids, ids?: int[]}` → état |
    | `POST /scan/batch` | `msradar_manage` | État + `{processed, locked, done}` ; budget de 8 s au plus |
    | `GET /scan/status` | `msradar_view` | `{total, remaining, pending, locked, last_full_scan_gmt, next_run_gmt}` |
    | `GET /settings` | `msradar_manage` | Réglages complets |
    | `POST /settings` | `msradar_manage` | Corps JSON partiel ; 400 `msradar_invalid_settings` ou `msradar_unknown_rule` |
    | `GET /alerts/summary` | `msradar_view` | Synthèse des alertes |

  - **`MultisiteRadar\Tests\RestTestCase`** : `request( string $method, string $route, array $params = [] ): WP_REST_Response`, `login_as_super_admin(): void`.

- [ ] **Step 1: Créer la base des tests REST et écrire les tests qui échouent**

`tests/php/RestTestCase.php` :

```php
<?php
namespace MultisiteRadar\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base des tests REST : serveur espion neuf à chaque test, requêtes JSON.
 */
abstract class RestTestCase extends TestCase {

	protected WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	protected function request( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/multisite-radar/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return $this->server->dispatch( $request );
	}

	protected function login_as_super_admin(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
	}
}
```

`tests/php/Rest/SitesControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class SitesControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$scanned = '2026-09-01 00:00:00';
		$this->make_record( 101, [ 'name' => 'Alpha', 'users_count' => 3, 'last_activity_gmt' => '2026-08-30 10:00:00', 'scanned_at' => $scanned ] );
		$this->make_record( 102, [ 'name' => 'Beta', 'users_count' => 0, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'last_activity_gmt' => '2024-01-01 00:00:00', 'scanned_at' => $scanned ] );
	}

	public function test_requires_authentication_and_the_network_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/sites' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/sites' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/sites/101' )->get_status() );
	}

	public function test_lists_sites_with_pagination_headers(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', [ 'per_page' => 1 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 'Alpha', $response->get_data()[0]['name'] );
	}

	public function test_filters_are_passed_through(): void {
		$this->login_as_super_admin();

		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'alert_level' => [ 'error' ] ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'has_users' => 'false' ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'inactive_since' => '2025-01-01T00:00:00' ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102, 101 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'orderby' => 'last_activity', 'order' => 'asc' ] )->get_data(), 'id' ) );
	}

	/**
	 * @dataProvider invalid_params
	 */
	public function test_rejects_invalid_parameters( array $params ): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public static function invalid_params(): array {
		return [
			'per_page above 100'  => [ [ 'per_page' => 500 ] ],
			'unknown orderby'     => [ [ 'orderby' => 'bogus' ] ],
			'unknown alert level' => [ [ 'alert_level' => [ 'fatal' ] ] ],
			'invalid date'        => [ [ 'inactive_since' => 'yesterday' ] ],
		];
	}

	public function test_a_page_beyond_the_last_is_empty_with_the_right_total(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', [ 'page' => 9 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
	}

	public function test_get_item(): void {
		$this->login_as_super_admin();

		$this->assertSame( 'Beta', $this->request( 'GET', '/sites/102' )->get_data()['name'] );

		$missing = $this->request( 'GET', '/sites/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'msradar_site_not_found', $missing->get_data()['code'] );
	}
}
```

`tests/php/Rest/ScanControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ScanControllerTest extends RestTestCase {

	public function test_scan_endpoints_require_the_manage_capability(): void {
		$this->assertSame( 401, $this->request( 'POST', '/scan/batch' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/scan', [ 'scope' => 'all' ] )->get_status() );
	}

	public function test_marking_then_processing_a_site(): void {
		$this->login_as_super_admin();
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$status = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => [ $site_id ] ] )->get_data();
		$this->assertSame( 1, $status['remaining'] );

		$batch = $this->request( 'POST', '/scan/batch' )->get_data();
		$this->assertSame( 1, $batch['processed'] );
		$this->assertTrue( $batch['done'] );
		$this->assertFalse( $batch['locked'] );
		$this->assertNotNull( $this->plugin()->sites()->find( $site_id )->scanned_at );
	}

	public function test_full_scan_marks_every_site(): void {
		$this->login_as_super_admin();
		self::factory()->blog->create_many( 2 );
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$status = $this->request( 'POST', '/scan', [ 'scope' => 'all' ] )->get_data();

		$this->assertSame( $status['total'], $status['remaining'] );
		$this->assertNotNull( $status['last_full_scan_gmt'] );
	}

	public function test_status_shape(): void {
		$this->login_as_super_admin();

		$status = $this->request( 'GET', '/scan/status' )->get_data();

		$this->assertSame( [ 'total', 'remaining', 'pending', 'locked', 'last_full_scan_gmt', 'next_run_gmt' ], array_keys( $status ) );
	}

	public function test_invalid_scope_is_rejected(): void {
		$this->login_as_super_admin();

		$this->assertSame( 400, $this->request( 'POST', '/scan', [ 'scope' => 'everything' ] )->get_status() );
	}
}
```

`tests/php/Rest/SettingsControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class SettingsControllerTest extends RestTestCase {

	public function test_requires_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 403, $this->request( 'GET', '/settings' )->get_status() );
	}

	public function test_get_and_update(): void {
		$this->login_as_super_admin();

		$this->assertSame( 7, $this->request( 'GET', '/settings' )->get_data()['scan']['full_rescan_days'] );

		$response = $this->request( 'POST', '/settings', [ 'scan' => [ 'full_rescan_days' => 3 ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['scan']['full_rescan_days'] );
	}

	/**
	 * @dataProvider invalid_bodies
	 */
	public function test_rejects_invalid_bodies( array $body, string $code ): void {
		$this->login_as_super_admin();

		$response = $this->request( 'POST', '/settings', $body );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	public static function invalid_bodies(): array {
		return [
			'out of range'        => [ [ 'scan' => [ 'full_rescan_days' => 0 ] ], 'msradar_invalid_settings' ],
			'unknown rule'        => [ [ 'alerts' => [ 'rules' => [ 'nope' => [ 'enabled' => false ] ] ] ], 'msradar_unknown_rule' ],
			'invalid rule params' => [ [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'params' => [ 'months' => 0 ] ] ] ] ], 'msradar_invalid_settings' ],
		];
	}
}
```

`tests/php/Rest/AlertsControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class AlertsControllerTest extends RestTestCase {

	public function test_summary(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alerts/summary' )->get_status() );

		$this->login_as_super_admin();
		$this->make_record( 401, [ 'scanned_at' => '2026-09-01 00:00:00', 'alert_level' => 3, 'alert_rules' => ',no_users,' ] );
		$summary = $this->request( 'GET', '/alerts/summary' )->get_data();

		$this->assertSame( 1, $summary['by_severity']['error'] );
		$this->assertSame( 1, $summary['sites_with_alerts'] );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter 'ControllerTest'`
Expected: échec. Les routes n'existent pas : les réponses sont des 404 `rest_no_route` au lieu des statuts attendus.

- [ ] **Step 3: Implémenter la base et `SitesController`**

`includes/Rest/Controller.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Capabilities;
use WP_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Base des contrôleurs : espace de noms et contrôles de capacités.
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'multisite-radar/v1';

	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}
}
```

`includes/Rest/SitesController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Storage\SitesRepository;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SitesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'sites';

	private SitesQuery $query;

	public function __construct( SitesQuery $query ) {
		$this->query = $query;
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
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'id' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_collection_params(): array {
		return [
			'page'            => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'        => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'search'          => [
				'type'    => 'string',
				'default' => '',
			],
			'orderby'         => [
				'type'    => 'string',
				'default' => 'name',
				'enum'    => array_keys( SitesRepository::ORDERBY ),
			],
			'order'           => [
				'type'    => 'string',
				'default' => 'asc',
				'enum'    => [ 'asc', 'desc' ],
			],
			'alert_level'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => array_keys( SitesQuery::ALERT_LEVELS ),
				],
			],
			'status'          => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => SitesQuery::STATUSES,
				],
			],
			'theme'           => [
				'type'    => 'string',
				'default' => '',
			],
			'plugin'          => [
				'type'    => 'string',
				'default' => '',
			],
			'has_users'       => [ 'type' => 'boolean' ],
			'inactive_since'  => [
				'type'   => 'string',
				'format' => 'date-time',
			],
			'registry_status' => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => SitesQuery::REGISTRY_STATUSES,
				],
			],
			'rule'            => [
				'type'    => 'string',
				'default' => '',
			],
		];
	}

	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$since    = isset( $request['inactive_since'] ) ? rest_parse_date( (string) $request['inactive_since'], true ) : false;
		$result   = $this->query->list(
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
			]
		);

		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$item = $this->query->get( (int) $request['id'] );
		if ( null === $item ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $item );
	}

	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$int          = [
			'type'     => 'integer',
			'readonly' => true,
		];
		$nullable_int = [
			'type'     => [ 'integer', 'null' ],
			'readonly' => true,
		];
		$date         = [
			'type'     => [ 'string', 'null' ],
			'format'   => 'date-time',
			'readonly' => true,
		];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'msradar-site',
			'type'       => 'object',
			'properties' => [
				'id'                => $int,
				'name'              => [ 'type' => 'string' ],
				'url'               => [
					'type'   => 'string',
					'format' => 'uri',
				],
				'admin_url'         => [
					'type'   => 'string',
					'format' => 'uri',
				],
				'status'            => [ 'type' => 'object' ],
				'theme'             => [ 'type' => 'object' ],
				'users_count'       => $int,
				'admins_count'      => $int,
				'content_count'     => $int,
				'media_count'       => $int,
				'disk_bytes'        => $nullable_int,
				'db_bytes'          => $nullable_int,
				'autoload_bytes'    => $nullable_int,
				'last_activity_gmt' => $date,
				'alert_level'       => [
					'type' => 'string',
					'enum' => array_keys( SitesQuery::ALERT_LEVELS ),
				],
				'alerts_count'      => $int,
				'alert_rules'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'registry_status'   => [
					'type' => 'string',
					'enum' => SitesQuery::REGISTRY_STATUSES,
				],
				'pending'           => [ 'type' => 'boolean' ],
				'dirty'             => [ 'type' => 'boolean' ],
				'scanned_at_gmt'    => $date,
			],
		];
		return $this->add_additional_fields_schema( $this->schema );
	}
}
```

- [ ] **Step 4: Implémenter `ScanController`, `SettingsController` et `AlertsController`**

`includes/Rest/ScanController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Scan\BatchRunner;
use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Storage\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ScanController extends Controller {

	private const BATCH_BUDGET = 8.0;

	/**
	 * @var string
	 */
	protected $rest_base = 'scan';

	private SitesRepository $sites;
	private BatchRunner $runner;
	private Queue $queue;
	private Lock $lock;

	public function __construct( SitesRepository $sites, BatchRunner $runner, Queue $queue, Lock $lock ) {
		$this->sites  = $sites;
		$this->runner = $runner;
		$this->queue  = $queue;
		$this->lock   = $lock;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/scan',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'request_scan' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'scope' => [
							'type'    => 'string',
							'enum'    => [ 'all', 'dirty', 'ids' ],
							'default' => 'dirty',
						],
						'ids'   => [
							'type'    => 'array',
							'default' => [],
							'items'   => [
								'type'    => 'integer',
								'minimum' => 1,
							],
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/scan/batch',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_batch' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/scan/status',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
			]
		);
	}

	public function request_scan( WP_REST_Request $request ): WP_REST_Response {
		$network_id = get_current_network_id();
		$scope      = (string) $request['scope'];
		if ( 'all' === $scope ) {
			$this->queue->request_full_scan( $network_id );
		} elseif ( 'ids' === $scope ) {
			$this->sites->mark_dirty( (array) $request['ids'] );
			$this->queue->continue_soon();
		} else {
			$this->queue->continue_soon();
		}
		return new WP_REST_Response( $this->status() );
	}

	public function run_batch(): WP_REST_Response {
		$result = $this->runner->run( min( self::BATCH_BUDGET, BatchRunner::default_budget() ) );
		return new WP_REST_Response(
			array_merge(
				$this->status(),
				[
					'processed' => $result['processed'],
					'locked'    => $result['locked'],
					'done'      => 0 === $result['remaining'],
				]
			)
		);
	}

	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( $this->status() );
	}

	private function status(): array {
		$network_id = get_current_network_id();
		$last       = (int) get_site_option( Queue::LAST_FULL_SCAN, 0 );
		$next       = Queue::next_run();
		return [
			'total'              => $this->sites->count_all( $network_id ),
			'remaining'          => $this->sites->count_dirty(),
			'pending'            => $this->sites->count_pending( $network_id ),
			'locked'             => $this->lock->is_locked(),
			'last_full_scan_gmt' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s', $last ) : null,
			'next_run_gmt'       => null !== $next ? gmdate( 'Y-m-d\TH:i:s', $next ) : null,
		];
	}
}
```

`includes/Rest/SettingsController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings';

	private Settings $settings;
	private RuleRegistry $rules;

	public function __construct( Settings $settings, RuleRegistry $rules ) {
		$this->settings = $settings;
		$this->rules    = $rules;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_item_schema(): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'msradar-settings',
			],
			Settings::schema()
		);
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$patch = $request->get_json_params();
		if ( ! is_array( $patch ) ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

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

		$result = $this->settings->update( $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
}
```

`includes/Rest/AlertsController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\AlertsQuery;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class AlertsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'alerts';

	private AlertsQuery $query;

	public function __construct( AlertsQuery $query ) {
		$this->query = $query;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_summary' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
			]
		);
	}

	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->query->summary( get_current_network_id() ) );
	}
}
```

- [ ] **Step 5: Enregistrer les routes**

Dans `includes/Plugin.php` :
- ajouter les imports `use MultisiteRadar\Rest\AlertsController;`, `use MultisiteRadar\Rest\ScanController;`, `use MultisiteRadar\Rest\SettingsController;` et `use MultisiteRadar\Rest\SitesController;` ;
- dans `boot()`, après `$this->invalidation()->register();`, ajouter :

```php
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
```

- ajouter la méthode :

```php
	public function register_rest_routes(): void {
		Installer::maybe_upgrade();
		$controllers = [
			new SitesController( $this->sites_query() ),
			new ScanController( $this->sites(), $this->runner(), $this->queue(), $this->lock() ),
			new SettingsController( $this->settings(), $this->rules() ),
			new AlertsController( $this->alerts_query() ),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
```

- [ ] **Step 6: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 7: Vérifier dans le WordPress local**

```bash
cd /home/dev/wp
wp eval 'wp_set_current_user( 1 ); $r = rest_do_request( new WP_REST_Request( "GET", "/multisite-radar/v1/sites" ) ); echo $r->get_status(), " ", $r->get_headers()["X-WP-Total"], PHP_EOL; echo wp_json_encode( $r->get_data()[0] ), PHP_EOL;'
wp eval '$r = rest_do_request( new WP_REST_Request( "GET", "/multisite-radar/v1/sites" ) ); echo $r->get_status(), PHP_EOL;'
cd /home/dev/wp-network-plugin-utilities
```

Expected :
- en tant que super-admin : `200 1`, suivi du JSON du site 1 (`name`, `alert_level`, `registry_status`…) ;
- sans utilisateur connecté : `401`.

- [ ] **Step 8: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: expose sites, scan, settings and alerts through the REST API"
```

---
### Task 13: Migration depuis Network Plugin Utilities 1.x

**Files:**
- Create: `includes/Install/LegacyMigration.php`, `tests/php/fixtures/legacy/npu-core-stub.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Install/LegacyMigrationTest.php`

**Interfaces:**
- Consumes : `Settings::update()`, `MainSite::run()`, `Capabilities::MANAGE`, action `msradar_activated`.
- Produces :
  - **Constantes de `LegacyMigration`** :
    - `DONE = 'msradar_legacy_migrated'` (site option, timestamp) ;
    - `CURSOR = 'msradar_legacy_menu_cursor'` ;
    - `ALIASES = 'msradar_legacy_aliases'` (site option, 1 si une installation 1.x a été reconnue ; le module menu de M2 enregistrera les alias seulement dans ce cas) ;
    - `HOOK = 'msradar_legacy_menu_batch'` ;
    - `MENU_TYPE = 'msradar_site'`.
  - **Méthodes de `LegacyMigration`** :
    - `register()`, `maybe_start()`, `start()` ;
    - `migrate_options(): array{found: bool, menu_enabled: bool}` ;
    - `run_menu_batch()`, `convert_menu_items( int $site_id ): int` ;
    - `render_coexistence_notice()`.
  - **`Plugin`** : `legacy(): LegacyMigration`, enregistré dans `boot()`.

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/php/fixtures/legacy/npu-core-stub.php` :

```php
<?php
// Simule le chargement du MU-plugin 1.x pour tester la notice de cohabitation.
if ( ! class_exists( 'NPU_Core', false ) ) {
	class NPU_Core {} // phpcs:ignore
}
```

`tests/php/Install/LegacyMigrationTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Tests\TestCase;

final class LegacyMigrationTest extends TestCase {

	private LegacyMigration $migration;

	public function set_up(): void {
		parent::set_up();
		$this->migration = $this->plugin()->legacy();
		foreach ( [ LegacyMigration::DONE, LegacyMigration::CURSOR, LegacyMigration::ALIASES ] as $option ) {
			delete_site_option( $option );
		}
	}

	private function migrate(): void {
		$this->migration->start();
		$guard = 0;
		while ( false !== get_site_option( LegacyMigration::CURSOR, false ) && $guard++ < 100 ) {
			$this->migration->run_menu_batch();
		}
	}

	public function test_migrates_legacy_options_and_clears_legacy_caches(): void {
		update_site_option( 'npu_activity_post_types', [ 'post', 'event' ] );
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );
		update_site_option( 'npu_enable_network_menu', 0 );
		set_site_transient( 'npu_site_data_1', [ 'cached' ], HOUR_IN_SECONDS );
		set_site_transient( 'npu_last_cache_refresh', time(), DAY_IN_SECONDS );

		$this->migrate();
		$settings = $this->plugin()->settings();

		$this->assertSame( [ 'post', 'event' ], $settings->get( 'scan.activity_post_types' ) );
		$this->assertSame( [ 'acme' ], $settings->get( 'scan.analysis_plugins' ) );
		$this->assertFalse( $settings->get( 'sites_menu.enabled' ), 'The menu was disabled in 1.x.' );
		$this->assertFalse( get_site_option( 'npu_activity_post_types' ) );
		$this->assertFalse( get_site_option( 'npu_enable_network_menu' ) );
		$this->assertFalse( get_site_transient( 'npu_site_data_1' ) );
		$this->assertFalse( get_site_transient( 'npu_last_cache_refresh' ) );
		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
		$this->assertNotFalse( get_site_option( LegacyMigration::DONE ) );
		$this->assertFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_converts_legacy_menu_items_and_enables_the_menu_module(): void {
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$item_id = self::factory()->post->create( [ 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ] );
		update_post_meta( $item_id, '_menu_item_type', 'network_site' );
		update_post_meta( $item_id, '_menu_item_object', 'network_site' );
		get_post_meta( $item_id );
		restore_current_blog();

		$this->migrate();

		switch_to_blog( $site_id );
		$type   = get_post_meta( $item_id, '_menu_item_type', true );
		$object = get_post_meta( $item_id, '_menu_item_object', true );
		restore_current_blog();

		$this->assertSame( LegacyMigration::MENU_TYPE, $type, 'The cached meta was cleared.' );
		$this->assertSame( LegacyMigration::MENU_TYPE, $object );
		$this->assertTrue( $this->plugin()->settings()->get( 'sites_menu.enabled' ), 'Menu items imply the menu was in use.' );
		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
	}

	public function test_a_fresh_install_has_nothing_to_migrate(): void {
		$this->migrate();

		$this->assertNotFalse( get_site_option( LegacyMigration::DONE ) );
		$this->assertFalse( get_site_option( LegacyMigration::ALIASES ) );
		$this->assertFalse( $this->plugin()->settings()->get( 'sites_menu.enabled' ) );
	}

	public function test_runs_only_once(): void {
		update_site_option( LegacyMigration::DONE, time() );
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );

		$this->migration->start();

		$this->assertSame( [ 'acme' ], get_site_option( 'npu_analysis_plugins' ), 'Nothing is touched after the first run.' );
		$this->assertFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_menu_batches_are_chained_through_cron(): void {
		wp_clear_scheduled_hook( LegacyMigration::HOOK );

		$this->migration->start();

		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ) );
	}

	public function test_activation_starts_the_migration(): void {
		do_action( 'msradar_activated', true );

		$this->assertNotFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_coexistence_notice_appears_only_when_1x_is_loaded(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		ob_start();
		$this->migration->render_coexistence_notice();
		$this->assertSame( '', ob_get_clean() );

		require_once dirname( __DIR__ ) . '/fixtures/legacy/npu-core-stub.php';
		ob_start();
		$this->migration->render_coexistence_notice();
		$this->assertStringContainsString( 'Network Plugin Utilities 1.x is still loaded', (string) ob_get_clean() );
	}
}
```

- [ ] **Step 2: Lancer les tests pour les voir échouer**

Run: `bin/test.sh --filter LegacyMigrationTest`
Expected: échec avec `Call to undefined method MultisiteRadar\Plugin::legacy()`.

- [ ] **Step 3: Implémenter `LegacyMigration`**

`includes/Install/LegacyMigration.php` :

```php
<?php
namespace MultisiteRadar\Install;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Reprise d'une installation Network Plugin Utilities 1.x : réglages, caches, éléments de menu.
 * S'exécute une seule fois. Les éléments de menu sont convertis par lots de sites, via des événements cron enchaînés.
 */
final class LegacyMigration {

	public const DONE      = 'msradar_legacy_migrated';
	public const CURSOR    = 'msradar_legacy_menu_cursor';
	public const ALIASES   = 'msradar_legacy_aliases';
	public const HOOK      = 'msradar_legacy_menu_batch';
	public const MENU_TYPE = 'msradar_site';

	private const BATCH          = 50;
	private const LEGACY_TYPE    = 'network_site';
	private const LEGACY_OPTIONS = [ 'npu_enable_network_menu', 'npu_activity_post_types', 'npu_analysis_plugins' ];

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'msradar_activated', [ $this, 'start' ] );
		add_action( 'admin_init', [ $this, 'maybe_start' ] );
		add_action( self::HOOK, [ $this, 'run_menu_batch' ] );
		add_action( 'network_admin_notices', [ $this, 'render_coexistence_notice' ] );
	}

	public function maybe_start(): void {
		if ( is_main_site() ) {
			$this->start();
		}
	}

	public function start(): void {
		if ( false !== get_site_option( self::DONE, false ) || false !== get_site_option( self::CURSOR, false ) ) {
			return;
		}
		$found = $this->migrate_options();
		update_site_option(
			self::CURSOR,
			[
				'after'         => 0,
				'menu_items'    => 0,
				'options_found' => $found['found'],
				'menu_enabled'  => $found['menu_enabled'],
			]
		);
		$this->schedule_next();
	}

	/**
	 * @return array{found: bool, menu_enabled: bool}
	 */
	public function migrate_options(): array {
		$values = [];
		foreach ( self::LEGACY_OPTIONS as $name ) {
			$value = get_site_option( $name, null );
			if ( null !== $value ) {
				$values[ $name ] = $value;
			}
		}
		$this->delete_legacy_transients();

		if ( [] === $values ) {
			return [
				'found'        => false,
				'menu_enabled' => true,
			];
		}

		$scan = [];
		if ( isset( $values['npu_activity_post_types'] ) && is_array( $values['npu_activity_post_types'] ) ) {
			$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $values['npu_activity_post_types'] ) ) ) );
			if ( [] !== $types ) {
				$scan['activity_post_types'] = $types;
			}
		}
		if ( isset( $values['npu_analysis_plugins'] ) && is_array( $values['npu_analysis_plugins'] ) ) {
			$scan['analysis_plugins'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values['npu_analysis_plugins'] ) ) ) );
		}
		if ( [] !== $scan ) {
			// Une valeur 1.x invalide (ex. type de plus de 20 caractères) est ignorée : les défauts v2 s'appliquent.
			$this->settings->update( [ 'scan' => $scan ] );
		}

		foreach ( self::LEGACY_OPTIONS as $name ) {
			delete_site_option( $name );
		}

		return [
			'found'        => true,
			// En 1.x, le menu était actif tant que l'option n'avait pas été enregistrée.
			'menu_enabled' => ! isset( $values['npu_enable_network_menu'] ) || (bool) $values['npu_enable_network_menu'],
		];
	}

	public function run_menu_batch(): void {
		$cursor = get_site_option( self::CURSOR, false );
		if ( ! is_array( $cursor ) ) {
			return;
		}

		global $wpdb;
		$site_ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d', $wpdb->blogs, (int) $cursor['after'], self::BATCH ) )
		);
		foreach ( $site_ids as $site_id ) {
			$cursor['menu_items'] = (int) $cursor['menu_items'] + $this->convert_menu_items( $site_id );
			$cursor['after']      = $site_id;
		}

		if ( self::BATCH === count( $site_ids ) ) {
			update_site_option( self::CURSOR, $cursor );
			$this->schedule_next();
			return;
		}
		$this->finish( $cursor );
	}

	/**
	 * @return int Nombre d'éléments de menu convertis sur ce site.
	 */
	public function convert_menu_items( int $site_id ): int {
		global $wpdb;
		$table    = $wpdb->get_blog_prefix( $site_id ) . 'postmeta';
		$suppress = $wpdb->suppress_errors( true );
		$post_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM %i WHERE meta_key IN ('_menu_item_type', '_menu_item_object') AND meta_value = %s",
					$table,
					self::LEGACY_TYPE
				)
			)
		);
		if ( [] !== $post_ids ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET meta_value = %s WHERE meta_key IN ('_menu_item_type', '_menu_item_object') AND meta_value = %s",
					$table,
					self::MENU_TYPE,
					self::LEGACY_TYPE
				)
			);
		}
		$wpdb->suppress_errors( $suppress );

		if ( [] === $post_ids ) {
			return 0;
		}
		switch_to_blog( $site_id );
		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}
		restore_current_blog();
		return count( $post_ids );
	}

	public function render_coexistence_notice(): void {
		if ( ! class_exists( 'NPU_Core', false ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Network Plugin Utilities 1.x is still loaded. Remove it: Multisite Radar replaces it and has taken over its settings.', 'multisite-radar' )
		);
	}

	private function finish( array $cursor ): void {
		$menu_items = (int) $cursor['menu_items'];
		if ( ! empty( $cursor['options_found'] ) || $menu_items > 0 ) {
			update_site_option( self::ALIASES, 1 );
			if ( $menu_items > 0 || ! empty( $cursor['menu_enabled'] ) ) {
				$this->settings->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
			}
		}
		update_site_option( self::DONE, time() );
		delete_site_option( self::CURSOR );
	}

	private function delete_legacy_transients(): void {
		global $wpdb;
		$keys = $wpdb->get_col(
			$wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $wpdb->esc_like( '_site_transient_npu_' ) . '%' )
		);
		foreach ( $keys as $key ) {
			delete_site_transient( substr( (string) $key, strlen( '_site_transient_' ) ) );
		}
	}

	private function schedule_next(): void {
		MainSite::run(
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time(), self::HOOK );
				}
			}
		);
	}
}
```

- [ ] **Step 4: Brancher dans le conteneur**

Dans `includes/Plugin.php` :
- ajouter l'import `use MultisiteRadar\Install\LegacyMigration;` ;
- ajouter la propriété `private ?LegacyMigration $legacy = null;` ;
- dans `boot()`, après `add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );`, ajouter :

```php
		$this->legacy()->register();
```

- ajouter la méthode :

```php
	public function legacy(): LegacyMigration {
		return $this->legacy ??= new LegacyMigration( $this->settings() );
	}
```

- [ ] **Step 5: Lancer les tests**

Run: `bin/test.sh`
Expected: `OK`.

- [ ] **Step 6: Normes, analyse, commit**

```bash
composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: migrate Network Plugin Utilities 1.x settings and menu items"
```

---

### Task 14: Commandes WP-CLI

**Files:**
- Create: `includes/Cli/RadarCommand.php`, `includes/Cli/SitesCommand.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- Consumes :
  - `Installer::maybe_upgrade()` ;
  - `Plugin` : `sites()`, `queue()`, `runner()`, `probe()`, `sites_query()` ;
  - `SitesRepository` : `seed_from_blogs`, `mark_dirty`, `dirty_ids`, `count_dirty` ;
  - `Queue::request_full_scan()` ;
  - `BatchRunner::run( float, callable )` ;
  - `RegistryProbe::run()` et `RegistryProbe::OPTION` ;
  - `SitesQuery::list()`.
- Produces :
  - `wp multisite-radar scan [--all] [--site=<id>] [--probe]` ;
  - `wp multisite-radar probe` (avec `--url`) ;
  - `wp multisite-radar sites list [--search] [--alert] [--theme] [--plugin] [--fields] [--format]`.

Ces commandes ne contiennent aucune logique métier : elles appellent des services déjà testés. Elles sont donc vérifiées en conditions réelles dans le WordPress local, sans test PHPUnit.

- [ ] **Step 1: Implémenter `RadarCommand`**

`includes/Cli/RadarCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Install\Installer;
use MultisiteRadar\Plugin;
use WP_CLI;
use function WP_CLI\Utils\make_progress_bar;

defined( 'ABSPATH' ) || exit;

/**
 * Scans and inspects the network with Multisite Radar.
 */
final class RadarCommand {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'multisite-radar', new self( $plugin ) );
		WP_CLI::add_command( 'multisite-radar sites', new SitesCommand( $plugin ) );
	}

	/**
	 * Scans sites and stores their data.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Rescan every site of the network.
	 *
	 * [--site=<id>]
	 * : Rescan a single site.
	 *
	 * [--probe]
	 * : First rebuild each site's registry in its own context (one WP-CLI process per site).
	 *
	 * ## EXAMPLES
	 *
	 *     wp multisite-radar scan --all --probe
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function scan( array $args, array $assoc_args ): void {
		Installer::maybe_upgrade();
		$network_id = get_current_network_id();
		$sites      = $this->plugin->sites();

		if ( isset( $assoc_args['site'] ) ) {
			$site_id = (int) $assoc_args['site'];
			if ( null === get_site( $site_id ) ) {
				WP_CLI::error( sprintf( 'Site %d does not exist.', $site_id ) );
			}
			$sites->seed_from_blogs( $network_id );
			$sites->mark_dirty( [ $site_id ] );
		} elseif ( isset( $assoc_args['all'] ) ) {
			$this->plugin->queue()->request_full_scan( $network_id );
		}

		if ( isset( $assoc_args['probe'] ) ) {
			$this->probe_sites( $sites->dirty_ids() );
		}

		$total = $sites->count_dirty();
		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to scan.' );
			return;
		}

		$progress = make_progress_bar( 'Scanning sites', $total );
		$failed   = 0;
		$result   = $this->plugin->runner()->run(
			(float) PHP_INT_MAX,
			static function ( int $site_id, bool $ok ) use ( $progress, &$failed ): void {
				if ( ! $ok ) {
					++$failed;
				}
				$progress->tick();
			}
		);
		$progress->finish();

		if ( $result['locked'] ) {
			WP_CLI::error( 'Another scan is running. Try again in a minute.' );
		}
		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d site(s) failed or no longer exist.', $failed ) );
		}
		WP_CLI::success( sprintf( '%d site(s) scanned.', $result['processed'] ) );
	}

	/**
	 * Rebuilds the registry of the current site. Run it with --url=<site>.
	 *
	 * ## EXAMPLES
	 *
	 *     wp multisite-radar probe --url=example.org/blog/
	 */
	public function probe(): void {
		$this->plugin->probe()->run();
		$registry = get_option( RegistryProbe::OPTION );
		$registry = is_array( $registry ) ? $registry : [];
		WP_CLI::success(
			sprintf(
				'Registry rebuilt for site %d: %d post types, %d taxonomies.',
				get_current_blog_id(),
				count( (array) ( $registry['post_types'] ?? [] ) ),
				count( (array) ( $registry['taxonomies'] ?? [] ) )
			)
		);
	}

	/**
	 * @param int[] $site_ids
	 */
	private function probe_sites( array $site_ids ): void {
		$progress = make_progress_bar( 'Probing registries', count( $site_ids ) );
		foreach ( $site_ids as $site_id ) {
			$site = get_site( $site_id );
			if ( null !== $site ) {
				$result = WP_CLI::runcommand(
					'multisite-radar probe',
					[
						'launch'       => true,
						'return'       => 'all',
						'exit_error'   => false,
						'command_args' => [ '--url=' . $site->domain . $site->path ],
					]
				);
				if ( 0 !== (int) $result->return_code ) {
					WP_CLI::warning( sprintf( 'Probe failed for site %d: %s', $site_id, trim( (string) $result->stderr ) ) );
				}
			}
			$progress->tick();
		}
		$progress->finish();
	}
}
```

- [ ] **Step 2: Implémenter `SitesCommand`**

`includes/Cli/SitesCommand.php` :

```php
<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Plugin;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sites of the network as last scanned.
 */
final class SitesCommand {

	private const DEFAULT_FIELDS = 'id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

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
		$query = $this->plugin->sites_query();
		$items = [];
		$page  = 1;
		do {
			$result = $query->list(
				[
					'page'        => $page,
					'per_page'    => 100,
					'orderby'     => 'id',
					'search'      => (string) ( $assoc_args['search'] ?? '' ),
					'alert_level' => isset( $assoc_args['alert'] ) ? [ (string) $assoc_args['alert'] ] : [],
					'theme'       => (string) ( $assoc_args['theme'] ?? '' ),
					'plugin'      => (string) ( $assoc_args['plugin'] ?? '' ),
				]
			);
			foreach ( $result['items'] as $item ) {
				$items[] = self::flatten( $item );
			}
			++$page;
		} while ( [] !== $result['items'] && count( $items ) < $result['total'] );

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}

	private static function flatten( array $item ): array {
		$item['theme']       = $item['theme']['stylesheet'];
		$item['status']      = implode( ',', array_keys( array_filter( $item['status'] ) ) );
		$item['alert_rules'] = implode( ',', $item['alert_rules'] );
		return $item;
	}
}
```

- [ ] **Step 3: Enregistrer les commandes**

Dans `includes/Plugin.php` :
- ajouter l'import `use MultisiteRadar\Cli\RadarCommand;` ;
- à la fin de `boot()`, ajouter :

```php
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			RadarCommand::register( $this );
		}
```

- [ ] **Step 4: Vérifier dans le WordPress local**

```bash
cd /home/dev/wp
wp help multisite-radar scan | head -20
wp multisite-radar scan --all --probe
wp multisite-radar sites list
wp multisite-radar sites list --format=json --fields=id,name,registry_status,alert_level
wp multisite-radar scan --site=999999; echo "exit=$?"
cd /home/dev/wp-network-plugin-utilities
```

Expected :
- l'aide affiche les options `--all`, `--site`, `--probe` ;
- `scan` affiche `Probing registries` puis `Scanning sites`, et termine par `Success: 1 site(s) scanned.` ;
- `sites list` montre le site 1 avec son nom et son thème ;
- le JSON indique `"registry_status":"fresh"` ;
- la dernière commande renvoie `Error: Site 999999 does not exist.` et `exit=1`.

- [ ] **Step 5: Suite complète, normes, analyse, commit**

```bash
bin/test.sh && composer lint:fix; composer lint && composer analyse
git add -A
git commit -m "feat: add wp multisite-radar scan, probe and sites list commands"
```

---

### Task 15: Désinstallation, intégration continue et recette du jalon

**Files:**
- Create: `uninstall.php`, `.github/workflows/ci.yml`
- Modify: `phpstan.neon.dist`, `CHANGELOG.md`

**Interfaces:**
- Consumes : noms de tables, d'options et de hooks des tâches 3 à 13. Ils sont recopiés en dur, car `uninstall.php` ne charge pas le code du plugin.
- Produces :
  - un nettoyage complet à la désinstallation ;
  - une CI GitHub Actions avec quatre jobs : `lint`, `phpunit` (matrice), `plugin-check` et `actionlint` local ;
  - la preuve de bout en bout du critère d'exactitude du §1.4 de la spec.

- [ ] **Step 1: Écrire `uninstall.php`**

```php
<?php
/**
 * Supprime toutes les données de Multisite Radar du réseau.
 *
 * @package MultisiteRadar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_multisite() ) {
	return;
}

global $wpdb;

foreach ( [ 'msradar_sites', 'msradar_site_extensions' ] as $msradar_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $msradar_table ) );
}

foreach ( [ 'msradar_settings', 'msradar_db_version', 'msradar_last_full_scan', 'msradar_legacy_migrated', 'msradar_legacy_aliases', 'msradar_legacy_menu_cursor' ] as $msradar_option ) {
	delete_site_option( $msradar_option );
}

delete_metadata( 'user', 0, 'msradar_view_prefs', '', true );

$msradar_site_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );
foreach ( $msradar_site_ids as $msradar_site_id ) {
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name IN (%s, %s)',
			$wpdb->get_blog_prefix( (int) $msradar_site_id ) . 'options',
			'msradar_registry',
			'msradar_scan_lock'
		)
	);
}

switch_to_blog( get_main_site_id() );
foreach ( [ 'msradar_process_queue', 'msradar_process_queue_continue', 'msradar_daily', 'msradar_recompute_alerts', 'msradar_legacy_menu_batch' ] as $msradar_hook ) {
	wp_clear_scheduled_hook( $msradar_hook );
}
restore_current_blog();
```

Dans `phpstan.neon.dist`, ajouter `- uninstall.php` à la liste `paths`.

- [ ] **Step 2: Vérifier la désinstallation sans supprimer les fichiers**

Ne **jamais** utiliser `wp plugin uninstall` ou `wp plugin delete` ici : le dossier du plugin est un lien vers le dépôt, ces commandes supprimeraient les sources.

```bash
cd /home/dev/wp
wp plugin deactivate multisite-radar --network
wp eval 'define( "WP_UNINSTALL_PLUGIN", "multisite-radar/multisite-radar.php" ); include WP_PLUGIN_DIR . "/multisite-radar/uninstall.php";'
wp eval 'global $wpdb; var_dump( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->base_prefix . "msradar_sites" ) ), get_site_option( "msradar_db_version" ), get_option( "msradar_registry" ) );'
wp plugin activate multisite-radar --network
wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}msradar_sites" ), PHP_EOL;'
cd /home/dev/wp-network-plugin-utilities
```

Expected :
- après désinstallation : `NULL`, `bool(false)`, `bool(false)` ;
- après réactivation : le nombre de sites du réseau. Les tables sont recréées et les sites ajoutés « en attente ».

- [ ] **Step 3: Écrire le workflow CI**

`.github/workflows/ci.yml` :

```yaml
name: CI

on:
  push:
    branches: [main, v2]
  pull_request:

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
          tools: composer:v2
      - run: composer install --no-progress --prefer-dist
      - run: composer lint
      - run: composer analyse

  phpunit:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.1', '8.4']
        wp: ['6.9', '7.1', 'trunk']
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -proot"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10
    env:
      WP_CORE_DIR: /tmp/wordpress
      WP_TESTS_DB_NAME: wordpress_test
      WP_TESTS_DB_USER: root
      WP_TESTS_DB_PASSWORD: root
      WP_TESTS_DB_HOST: 127.0.0.1:3306
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mysqli
          coverage: none
          tools: composer:v2, wp-cli
      - name: Download WordPress ${{ matrix.wp }}
        run: |
          if [ "${{ matrix.wp }}" = "trunk" ]; then VERSION=nightly; else VERSION="${{ matrix.wp }}"; fi
          wp core download --path="$WP_CORE_DIR" --version="$VERSION" --force
      - name: Install dependencies matching WordPress ${{ matrix.wp }}
        run: |
          if [ "${{ matrix.wp }}" = "trunk" ]; then TESTS="dev-master"; else TESTS="${{ matrix.wp }}.*"; fi
          composer require --dev --no-update "wp-phpunit/wp-phpunit:$TESTS"
          composer update --no-progress --prefer-dist -W
      - run: vendor/bin/phpunit

  plugin-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build the distributable directory
        run: |
          mkdir -p /tmp/build/multisite-radar
          rsync -a --exclude-from=.distignore ./ /tmp/build/multisite-radar/
      - uses: wordpress/plugin-check-action@v1
        with:
          build-dir: /tmp/build/multisite-radar
```

- [ ] **Step 4: Valider le workflow localement**

```bash
docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color
```

Expected : aucune sortie, code de retour 0. Les jobs eux-mêmes tourneront au premier push de la branche `v2` (ce plan ne pousse rien).

- [ ] **Step 5: Recette — un type de contenu présent sur un seul site**

Ce test vérifie le critère n°1 de la spec en conditions réelles. Un plugin de démonstration, installé **uniquement dans le WordPress local** (pas dans le dépôt), enregistre un type de contenu sur le site 2 seulement.

```bash
cd /home/dev/wp
mkdir -p wp-content/plugins/msradar-demo-cpt
cat > wp-content/plugins/msradar-demo-cpt/msradar-demo-cpt.php <<'PHP'
<?php
/**
 * Plugin Name: Multisite Radar demo CPT
 * Description: Local fixture for Multisite Radar: registers a "Demo events" post type.
 */
add_action(
	'init',
	static function () {
		register_post_type( 'demo_event', [ 'label' => 'Demo events', 'public' => true ] );
	}
);
PHP
wp site create --slug=rh --title="Blog RH"
RH="wp-network-plugin-utilities.test/rh/"
wp plugin activate msradar-demo-cpt --url="$RH"
for i in 1 2 3; do wp post create --post_type=demo_event --post_status=publish --post_title="Event $i" --url="$RH" --porcelain; done
wp multisite-radar scan --all --probe
wp multisite-radar sites list --fields=id,name,registry_status,content_count,alert_level
wp eval 'wp_set_current_user( 1 ); foreach ( [ 1, 2 ] as $id ) { $d = rest_do_request( new WP_REST_Request( "GET", "/multisite-radar/v1/sites/$id" ) )->get_data(); echo $id, " ", wp_json_encode( array_values( wp_list_filter( $d["post_types"], [ "name" => "demo_event" ] ) ) ), PHP_EOL; }'
cd /home/dev/wp-network-plugin-utilities
```

Expected :
- `sites list` affiche deux lignes (`1` et `2 Blog RH`), toutes deux en `fresh` ;
- la ligne REST du site 1 est vide : `1 []` ;
- la ligne REST du site 2 contient :

  ```
  2 [{"name":"demo_event","label":"Demo events","origin":{"kind":"plugin","slug":"msradar-demo-cpt"},"builtin":false,"verified":true,"publish":3,"total":3}]
  ```

Le site « Blog RH » et le plugin de démonstration restent en place : ils serviront au développement de l'interface en M2.

- [ ] **Step 6: Vérification finale**

```bash
bin/test.sh
composer lint
composer analyse
git status --short
```

Expected :
- PHPUnit `OK` ;
- PHPCS sans erreur ;
- PHPStan `[OK] No errors` ;
- `git status` ne liste que les fichiers de cette tâche.

- [ ] **Step 7: Mettre à jour le CHANGELOG et commiter**

Sous `## [2.0.0] - en cours` dans `CHANGELOG.md`, ajouter :

```markdown
### Jalon M1 — Fondations
- Collecte hybride : SQL agrégé sur les tables de chaque site + relevé des types enregistrés dans le contexte du site (libellés et origines exacts).
- Tables réseau `msradar_sites` et `msradar_site_extensions`, file d'analyse WP-Cron par lots avec verrou.
- Moteur d'alertes réglable (règles `no_users`, `inactive`, `high_media`).
- API REST `multisite-radar/v1` (sites, analyse, réglages, synthèse des alertes).
- Commandes `wp multisite-radar scan|probe|sites list`.
- Migration automatique des réglages et des éléments de menu de la 1.x.
- Outillage : PHPUnit multisite, WPCS, PHPStan niveau 6, CI GitHub Actions, Plugin Check.
```

```bash
git add -A
git commit -m "chore: add uninstall routine, CI workflow and M1 changelog"
```

---

## Couverture de la spec par ce plan

| Spec | Tâches |
|---|---|
| §1.4 critères 1 (exactitude), 3 (normes), 4 (migration) | 7, 15 · 1, 15 · 13 |
| §1.4 critère 2 (performance) | 3, 11 (requêtes indexées) ; le banc de 5 000 sites relève du jalon M7 (§11.3) |
| §2 architecture, capacités, garde multisite, points d'extension | 1, 2, 7, 8, 9 |
| §3.1 à 3.3 tables, `data`, collecteur | 3, 7 |
| §3.4 relevé en contexte | 5, 6 |
| §3.5 file d'analyse et invalidation | 9, 10 |
| §4 alertes (règles M1) | 8 |
| §5.1 REST (routes M1) | 11, 12 |
| §5.3 WP-CLI (commandes M1) | 14 |
| §8 réglages | 2 |
| §9 sécurité | 2, 12 (capacités), toutes les tâches (SQL préparé) |
| §10 migration 1.x | 13 |
| §11 outillage, CI, `uninstall.php` | 1, 15 |

Le reste de la spec relève des jalons M2 à M7, chacun avec son propre plan : exports, interface, inventaire, règles M4, Abilities, historique, module menu et alias, readme complet, traductions, banc de performance.
