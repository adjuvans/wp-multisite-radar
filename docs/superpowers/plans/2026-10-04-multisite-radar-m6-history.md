# Multisite Radar — Jalon M6 (Historique et rapports) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la 2.0.0-beta.6 de Multisite Radar, avec l'historique du réseau :
- **journal des changements** : sites créés ou supprimés, extensions activées ou désactivées, thème changé, alertes apparues ou résolues ;
- **instantanés quotidiens** de chaque site, purgés au-delà de la rétention réglée ;
- **tendances** du réseau et de chaque site ;
- **page « Rapports »** : tendances, « Quoi de neuf », réglage du récapitulatif ;
- **onglet « Historique »** dans la fiche d'un site ;
- **Vue d'ensemble enrichie** : derniers changements, tendance des alertes ;
- **récapitulatif hebdomadaire par e-mail** (opt-in) ;
- **widget** du tableau de bord réseau ;
- l'ability **`multisite-radar/recent-changes`**, reportée de M5 ;
- les routes REST `GET /events` et `GET /reports/trends`.

**Architecture :**
- **Stockage :** deux tables réseau de la spec §3.1, `msradar_events` et `msradar_snapshots` (schéma version 4), chacune avec son dépôt (`Storage\EventsRepository`, `Storage\SnapshotsRepository`). Le SQL reste dans `Storage/`.
- **Écriture de l'historique :**
  - `Scan\ChangeLog` compare l'état d'un site avant et après une analyse (`BatchRunner`) ou un recalcul des alertes (`Queue`), et note les différences ;
  - `Scan\Invalidation` y ajoute la création et la suppression d'un site, et les extensions activées sur tout le réseau ;
  - `Scan\History` prend l'instantané quotidien et purge, sur la tâche `msradar_daily`.
- **Lecture :** une seule source de vérité, comme en M5. `Query\EventsQuery` met en forme les événements (messages traduits construits à la lecture) et `Query\TrendsQuery` les séries ; les routes REST, l'ability et le récapitulatif s'en servent.
- **Rapports :** `Reports\Digest` (récapitulatif, sur `msradar_daily`) et `Reports\DashboardWidget` (rendu PHP).
- **Interface :**
  - deux composants partagés, `TrendChart` (courbes SVG internes, sans bibliothèque, spec §6.3) et `EventsList` ;
  - ils servent à une nouvelle vue « Rapports », à l'onglet « Historique » de la fiche et à la Vue d'ensemble ;
  - les réglages gagnent une carte « Historique » (rétention).

**Tech stack :** inchangée depuis M5.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit (la suite de tests fournit `MockPHPMailer` pour les e-mails), WPCS 3, PHPStan 2 niveau 6.
- Node 24, `@wordpress/scripts` 36.0.0 (Vitest), React 18.3, `@wordpress/dataviews` 19.1.0 (épinglé).
- Playwright + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M6, les sections utiles sont :
- §3.1 (tables `msradar_events` et `msradar_snapshots`, types d'événements) ;
- §3.5 (tâche `msradar_daily`) ;
- §5.1 (`GET /events`, `GET /reports/trends`) et §5.4 (`multisite-radar/recent-changes`) ;
- §6.1 et §6.2 (page Rapports, onglet Historique, Vue d'ensemble « avec M6 ») ; §6.3 (graphiques SVG internes) ; §6.4 ;
- §7.3 (lot 3) ;
- §8 (`reports.*`, `retention.*`) ; §9 (confidentialité) ; §11.4 (`uninstall.php`).

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`. Les points repris ici sont listés plus bas.

## Global Constraints

- **PHP ≥ 7.4.** Interdits :
  - `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains` ;
  - arguments nommés, opérateur nullsafe, ternaire court `?:`, déstructuration courte `[ $a, $b ] = …`.

  Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :**
  - text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, transients, groupes de cache, handles) ;
  - tables `{$wpdb->base_prefix}msradar_events` et `{$wpdb->base_prefix}msradar_snapshots` ;
  - pages d'admin, dans cet ordre : `multisite-radar`, `multisite-radar-sites`, `multisite-radar-plugins`, `multisite-radar-themes`, `multisite-radar-users`, `multisite-radar-alerts`, `multisite-radar-reports`, `multisite-radar-settings` ;
  - store `msradar/core`.
- **Types d'événements** (spec §3.1), exactement : `site_created`, `site_deleted`, `plugin_activated`, `plugin_deactivated`, `theme_switched`, `alert_raised`, `alert_resolved`.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ; en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder ;
  - jamais de `%` littéral (`%%`) dans une chaîne traduisible ;
  - les messages des événements sont construits **à la lecture**, dans la langue du lecteur : la base ne stocke que le type, le sujet et des métadonnées.
- **SQL :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants ;
  - listes `IN` via `implode( ',', array_fill( 0, count( $x ), '%s' ) )` ;
  - aucune fonction JSON côté SQL ;
  - requêtes directes uniquement dans `includes/Storage/` (et `includes/Install/`) ;
  - un fragment `WHERE` construit dynamiquement suit le motif de `SitesRepository::query()` (placeholders seulement, `phpcs:disable`/`enable` avec raison) ;
  - une lecture qui échoue lève `\RuntimeException` (`check_read()`).
- **Dates :**
  - stockées en GMT (`created_at` : `Y-m-d H:i:s` ; `day` : `Y-m-d`, jour UTC) ;
  - exposées en REST au format `Y-m-d\TH:i:s` (UTC, sans décalage) par `mysql_to_rfc3339()` ;
  - jamais de `format: date-time` dans un schéma de **sortie** (écart E2 du plan M5) ;
  - côté JS, toujours lues par `parseGmt()` (`src/utils/format.js`).
- **Erreurs :**
  - un dépôt lève `\RuntimeException` ;
  - les routes passent par `Controller::guard()` (500), les abilities rendent `msradar_storage_error` (500) ;
  - **le journal, les instantanés, le récapitulatif et le widget ne font jamais échouer une analyse, une tâche cron ni le tableau de bord** : `try`/`catch` + `do_action( 'msradar_error', <contexte>, $error )`.
- **Données personnelles :**
  - aucune adresse e-mail dans une réponse REST, une ability, une commande, un export, un événement ou un widget ;
  - les adresses des destinataires du récapitulatif servent seulement à l'envoi ;
  - les destinataires multiples reçoivent chacun leur propre e-mail : aucun ne voit les adresses des autres.
- **Aucun appel HTTP externe.** Les noms des extensions viennent de `get_plugins()` / `wp_get_theme()`.
- **Garde d'accès direct :** `defined( 'ABSPATH' ) || exit;` dans les 50 premières lignes de chaque fichier PHP, **avant les `use`** s'il y en a beaucoup. Plugin Check (CI, bloquant) ne le cherche pas plus loin.
- **Style PHP :**
  - WPCS ; un tableau associatif de plus d'un élément s'écrit sur plusieurs lignes ;
  - tableaux courts autorisés ;
  - commentaires en français, comme le reste du code.
- **JS :**
  - `.jsx` pour tout fichier qui contient du JSX, imports sans extension ;
  - feuilles de style importées seulement par les points d'entrée (`src/admin/<vue>.js`) ;
  - composants : uniquement des exports publics de `@wordpress/components` présents dans WordPress 6.9 (`Button`, `Card`, `CardBody`, `CardHeader`, `Notice`, `SelectControl`, `TabPanel`, `ToggleControl`…), jamais `__experimental*` ;
  - DataViews et DataForm importés seulement par `src/components/data-views/index.js` ;
  - jamais de `dangerouslySetInnerHTML`, pas d'emojis ;
  - couleurs d'accent via `var(--wp-admin-theme-color)` ;
  - animations limitées par `prefers-reduced-motion`.
- **Graphiques :**
  - SVG internes, sans bibliothèque ;
  - chaque graphique a un équivalent textuel (tableau des valeurs) accessible au clavier et aux lecteurs d'écran.
- **Versions :** `MSRADAR_VERSION` est la seule source de vérité ; `make version VERSION=x` ; `npm run version:check`.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `refactor:`, `build:`) ;
  - **jamais de ligne `Co-authored-by`**, même en premier essai : le hook du dépôt la refuse ;
  - jamais `--no-verify`, jamais de push.
- **Tests PHP :**
  - ne jamais lever d'exception dans `set_up()`/`tear_down()` après `parent::set_up()` ;
  - les tables d'un site créé par `self::factory()->blog->create()` sont temporaires ;
  - les tables du plugin sont créées par `tests/php/bootstrap.php`, hors transaction : une table créée **pendant** un test serait temporaire.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur le WordPress de développement : le dossier du plugin est un lien symbolique vers le dépôt ;
  - seul le WordPress jetable de `bin/e2e.sh` (copie du plugin) peut être désinstallé ;
  - ne jamais afficher le mot de passe de la base ;
  - ne pas lancer de commande d'écriture sur `/home/dev/wp` ;
  - ne jamais déplacer ni modifier le dossier `.claude/` ;
  - E2E avec `WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890`.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M6 | Raison |
|---|---|---|---|
| E1 | §3.1 : colonnes de `msradar_events` et `msradar_snapshots` | Les deux tables ont aussi **`network_id`** ; un événement du réseau entier (extension activée sur le réseau) a `site_id = 0` | Lire l'historique d'un réseau sans jointure, et le garder après la suppression d'un site (la ligne de `msradar_sites` disparaît alors). |
| E2 | §3.1 : « version de schéma 2 » | Version **4** (les versions 2 et 3 ont servi en M2 et M4). La mise à niveau de 3 à 4 crée les tables **sans relancer d'analyse** ; `msradar_upgraded` reçoit la version précédente | Rien à remplir dans `msradar_sites` : une analyse complète du réseau serait du travail perdu. |
| E3 | §7.3 : « le collecteur compare l'enregistrement précédent et le nouveau » | La **première** analyse d'un site sert de référence et n'écrit aucun événement d'extension, de thème ni d'alerte | Sinon, la première analyse après la mise à jour noterait chaque extension « activée » et chaque alerte « apparue ». |
| E4 | §3.1 : types d'événements | Sur un site, extensions et thème viennent de la comparaison des analyses : la date est celle de l'analyse qui a vu le changement. Les extensions activées ou désactivées **sur le réseau** viennent des hooks du cœur (`activated_plugin`, `deactivated_plugin` avec `$network_wide`), à leur date exacte, avec `site_id = 0` | Une activation réseau ne change pas `plugins_local` d'un site : sans le hook, elle n'apparaîtrait nulle part. |
| E5 | §7.3 : alertes nouvelles et résolues | `alert_raised` / `alert_resolved` sont aussi notés par le **recalcul** des alertes (quotidien, ou après un changement de réglages) : désactiver une règle résout ses alertes. Un simple changement de gravité ne note rien | L'inactivité apparaît avec le temps, sans nouvelle analyse ; une alerte qui disparaît d'un site est résolue pour l'administrateur, quelle qu'en soit la cause. |
| E6 | §7.3 : instantanés quotidiens | Un instantané par site **analysé** du réseau courant et par jour UTC, en une requête `INSERT … SELECT` ; une seconde prise le même jour remplace la première | Mesures cohérentes avec ce que montre l'interface ; coût constant sur un grand réseau. |
| E7 | §7.3 : récapitulatif « hebdomadaire, jour configurable » | Envoyé par la tâche quotidienne `msradar_daily` le jour réglé (`reports.digest_day`, 0 = dimanche … 6 = samedi, fuseau du site principal), au plus une fois par jour réglé (option `msradar_digest_sent`), sur les 7 derniers jours. Un bouton « Send a test e-mail to me » (`POST /reports/digest/test`, `msradar_manage`) l'envoie tout de suite au seul utilisateur courant | Pas de tâche cron de plus. Un récapitulatif qu'on ne peut pas prévisualiser ne se règle pas. |
| E8 | §6.2 : Rapports « réglage du récapitulatif » ; §8 : `retention` | Le récapitulatif se règle sur la page **Rapports** ; la rétention dans une carte **« Historique »** des Réglages | Chaque réglage à côté de ce qu'il règle. |
| E9 | §5.1 : `GET /reports/trends` | Un objet `{ days, since, site, points }` : un point par jour d'instantané, **sans jours comblés** ; `days` entre 2 et 3650 (90 par défaut) ; `site` pour la série d'un site (404 hors du réseau courant) | Le graphique place les points selon leur date : un jour manquant reste visible comme un trou dans la courbe. |
| E10 | §6.2 : Vue d'ensemble « tendances et derniers changements » | Deux cartes : les 5 derniers changements (lien vers Rapports) et l'évolution des alertes sur 30 jours | Les tuiles existantes donnent déjà les chiffres du jour. |

## Points reportés traités dans ce plan

| Point (document des suites de M2) | Tâche |
|---|---|
| Ability `multisite-radar/recent-changes` (écart E1 du plan M5) | 6 |
| Vue d'ensemble : deux `<progress>` pendant une analyse (FirstRun et ScanPanel) ; FirstRun masque son bouton au lieu de le désactiver | 11 |
| `SchemaTest::test_version_2_adds_the_siteurl_column` vérifie la version 3 : nom trompeur | 1 |
| Chemin sans test : désinstallation | 12 |

Les autres points restent pour plus tard. La tâche 13 met le document à jour.

## Review Focus

Les cinq situations que la spec implique sans que ses exemples les montrent, et qui gêneraient le plus un utilisateur. Chacune a son test dans la tâche indiquée.

1. **Mise à jour depuis la 2.0.0-beta.5 :**
   - le comportement attendu : aucune nouvelle analyse complète, aucun flot d'événements « activée » ou « apparue », des graphiques qui disent qu'il n'y a pas encore assez d'historique au lieu d'une courbe vide ou d'une erreur ;
   - tests : tâche 1 (mise à niveau 3 → 4), tâche 2 (première analyse = référence), tâche 9 (graphique avec 0 ou 1 point).
2. **Site supprimé :**
   - le comportement attendu : son historique reste lisible, avec son nom et son adresse, et un événement « site supprimé » apparaît ; aucune erreur si la ligne de `msradar_sites` n'existe plus ;
   - tests : tâche 2 (événement avec le nom), tâche 4 (événement d'un site disparu mis en forme).
3. **Plusieurs réseaux :**
   - le comportement attendu : événements, instantanés, tendances, purge et récapitulatif ne lisent et n'écrivent que le réseau courant ;
   - tests : tâche 1 (dépôts), tâche 3 (purge), tâche 4 (liste), tâche 5 (série d'un site d'un autre réseau → 404).
4. **Récapitulatif sans destinataire valable, ou échec d'envoi :**
   - le comportement attendu : rien n'est envoyé, aucune erreur ne remonte dans la tâche quotidienne, et rien n'est envoyé deux fois le même jour ;
   - quand plusieurs adresses sont saisies, aucun destinataire ne voit les autres ;
   - test : tâche 7.
5. **Nom de site ou d'extension contenant du HTML (`<script>`, `&amp;`) :**
   - le comportement attendu : texte brut décodé partout (REST, e-mail HTML et texte, widget), jamais interprété ;
   - tests : tâche 4 (message), tâche 7 (e-mail), tâche 8 (widget).

Autres pièges couverts par des tests :
- un changement de gravité seul ne note rien ; désactiver une règle note la résolution de ses alertes (tâche 2) ;
- un journal en échec ne fait pas échouer l'analyse du site (tâche 2) ;
- une ability en lecture seule n'accepte que GET, comme en M5 (tâche 6) ;
- la désinstallation supprime les nouvelles tables et options (tâche 12, sur le WordPress jetable de `bin/e2e.sh`).

## Structure des fichiers

**PHP, créés :**
- `includes/Storage/EventsRepository.php` — SQL du journal (insertion, lecture filtrée et paginée, comptes par type, purge).
- `includes/Storage/SnapshotsRepository.php` — SQL des instantanés (prise, séries du réseau et d'un site, purge).
- `includes/Scan/ChangeLog.php` — comparaison de deux états d'un site et écriture des événements.
- `includes/Scan/History.php` — instantané quotidien et purge selon la rétention.
- `includes/Query/EventsQuery.php` — événements mis en forme (site, libellé du sujet, message traduit).
- `includes/Query/TrendsQuery.php` — séries du réseau et d'un site.
- `includes/Rest/EventsController.php`, `includes/Rest/ReportsController.php`.
- `includes/Abilities/RecentChangesAbility.php`.
- `includes/Reports/Digest.php`, `includes/Reports/DashboardWidget.php`.
- `tests/php/DirectAccessGuardTest.php`.

**PHP, modifiés :**
- `includes/Install/Schema.php`, `includes/Install/Installer.php` ;
- `includes/Scan/Queue.php`, `includes/Scan/BatchRunner.php`, `includes/Scan/Invalidation.php` ;
- `includes/Query/Schemas.php` ;
- `includes/Admin/Menu.php`, `includes/Admin/Preload.php`, `includes/Admin/Privacy.php` ;
- `includes/Plugin.php`, `uninstall.php` ;
- `CHANGELOG.md`, `readme.txt`, `README.md`, version.

**JS :**
- **Créés :**
  - `src/components/trend-chart.jsx`, `src/components/events-list.jsx` ;
  - `src/views/reports/index.jsx`, `src/views/reports/digest-card.jsx`, `src/views/reports/digest-fields.js` ;
  - `src/views/site-panel/history-tab.jsx`, `src/views/overview/history.jsx` ;
  - `src/admin/reports.js`.
- **Modifiés :**
  - `webpack.config.js`, `src/admin/style.scss`, `src/utils/format.js` (si un format manque) ;
  - `src/views/site-panel/index.jsx`, `src/views/overview/index.jsx` ;
  - `src/views/settings/fields.js`, `src/views/settings/index.jsx`.

**Tests :**
- PHP : chaque tâche cite ses fichiers sous `tests/php/` ;
- JS : `src/**/test/*.test.js(x)` ;
- bout en bout : `bin/e2e.sh`, `tests/e2e/specs/history.spec.js` (créé), `tests/e2e/specs/a11y.spec.js`.

## Commandes

| Rôle | Commande |
|---|---|
| Tests PHP (tout, ou un filtre) | `bin/test.sh` ; `bin/test.sh --filter EventsRepositoryTest` |
| Tests PHP sur tables neuves (ce que voit la CI) | copier `tests/php/wp-tests-config.php` dans le dossier de travail en remplaçant `$table_prefix = 'wptests_'` par `'wpci_'`, puis `WP_PHPUNIT__TESTS_CONFIG=<copie> bin/test.sh` |
| Normes PHP / analyse statique | `composer lint` ; `composer analyse` |
| Syntaxe PHP 7.4 | `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null \|\| exit 1; done'` |
| Commandes WP-CLI et ability de bout en bout (WordPress jetable, tables `msre2e_*`) | `E2E_DB_NAME=wordpress_test E2E_DB_USER=wordpress E2E_DB_PASSWORD="$(wp config get DB_PASSWORD --path=/home/dev/wp)" E2E_DB_HOST=127.0.0.1:3306 E2E_WP_VERSION=7.1 bin/e2e.sh` |
| Tests JS | `npm run test:unit` (ou `npx vitest run src/views/reports`) |
| Lint JS / CSS | `npm run lint:js` ; `npm run lint:css` |
| Build | `npm run build` |
| Traductions | `make i18n` (après un build) |
| Versions synchronisées | `npm run version:check` |
| E2E | `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` puis `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e` |

Chaque tâche se termine par les tests de son périmètre, puis `composer lint` et `composer analyse` (PHP), ou `npm run lint:js` et `npm run test:unit` (JS), avant le commit. Le travail se fait sur une branche `m6` créée depuis `main`.

**Base de test :** la base `wordpress_test` peut contenir une ligne parasite (site 101). Tant qu'elle y est, 11 tests échouent d'une unité :
- `AlertsQueryTest::test_summary_counts_sites_by_severity_and_rule` ;
- 7 tests de `QueueTest` ;
- 3 tests de `SitesRepositoryTest`.

Ces 11 échecs sont connus : un exécutant ne les corrige pas et ne les compte pas comme une régression, mais signale tout autre échec.

Ils peuvent masquer un vrai échec dans ces trois classes : toute tâche qui touche à la file ou aux alertes relance donc la suite **sur tables neuves** avant son commit. Les tests de ce plan qui comptent des lignes travaillent sur un réseau fictif (`network_id` 77) ou comparent à une valeur lue avant, pour ne pas dépendre de cette ligne.

---

## Partie A — Données

### Task 1: Tables de l'historique (schéma 4), dépôts, mise à niveau sans analyse

**Files:**
- Create: `includes/Storage/EventsRepository.php`, `includes/Storage/SnapshotsRepository.php`, `tests/php/DirectAccessGuardTest.php`
- Modify: `includes/Install/Schema.php`, `includes/Install/Installer.php`, `includes/Scan/Queue.php`, `includes/Plugin.php`, `uninstall.php`
- Test: `tests/php/Storage/EventsRepositoryTest.php`, `tests/php/Storage/SnapshotsRepositoryTest.php` (créés) ; `tests/php/Install/SchemaTest.php`, `tests/php/Install/InstallerTest.php`, `tests/php/Scan/QueueTest.php`

**Interfaces:**
- Consumes : `Schema::sites_table()`, `SitesRepository` (colonnes `users_count`, `content_count`, `media_count`, `disk_bytes`, `db_bytes`, `alert_level`, `alerts_count`, `scanned_at`, `network_id`).
- Produces :
  - `Schema::VERSION = 4`, `Schema::events_table(): string`, `Schema::snapshots_table(): string` ;
  - `do_action( 'msradar_upgraded', int $version, int $previous )` ; `Queue::on_upgraded( $version = 0, $previous = 0 )` ne relance l'analyse complète que si `$previous < 3` ;
  - `Storage\EventsRepository` :
    - `TYPES` (les 7 types) ;
    - `insert( array $events ): void` (chaque événement : `network_id`, `site_id`, `type`, `subject`, `meta` (array), `created_at` (GMT)) ;
    - `query( array $args ): array{items, total}` (`network_id`, `since` (GMT ou null), `types` (string[]), `site_id` (0 = tous), `page`, `per_page`), plus récents d'abord ; chaque élément : `id`, `network_id`, `site_id`, `type`, `subject`, `meta` (array), `created_at` ;
    - `counts( int $network_id, string $since ): array<string, int>` (type ⇒ nombre) ;
    - `purge( int $network_id, string $before ): int` ;
  - `Storage\SnapshotsRepository` :
    - `capture( int $network_id, string $day ): int` ;
    - `network_series( int $network_id, string $since_day ): array` : points `day`, `sites`, `content_count`, `media_count`, `alerts_error`, `alerts_warning`, `alerts_info`, entiers ;
    - `site_series( int $site_id, string $since_day ): array` : points `day`, `users_count`, `content_count`, `media_count`, `disk_bytes` (?int), `db_bytes` (?int), `alert_level` (int), `alerts_count` ;
    - `purge( int $network_id, string $before_day ): int` ;
  - `Plugin::events(): EventsRepository`, `Plugin::snapshots(): SnapshotsRepository`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/DirectAccessGuardTest.php` (régression de la CI de la 2.0.0-beta.5) :

```php
<?php
namespace MultisiteRadar\Tests;

/**
 * Plugin Check (CI, bloquant pour WordPress.org) ne cherche le garde d'accès direct que dans les 50 premières lignes
 * d'un fichier PHP.
 */
final class DirectAccessGuardTest extends TestCase {

	public function test_every_php_file_guards_direct_access_within_its_first_lines(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge( [ $root . '/multisite-radar.php' ], $this->php_files( $root . '/includes' ) );
		foreach ( $files as $file ) {
			$head = implode( "\n", array_slice( (array) file( $file ), 0, 50 ) );
			$this->assertMatchesRegularExpression( "/defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit;/", $head, $file );
		}
	}

	/**
	 * @return string[]
	 */
	private function php_files( string $dir ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}
```

Dans `tests/php/Install/SchemaTest.php` :
- renommer `test_version_2_adds_the_siteurl_column` en `test_the_current_version_has_the_siteurl_column`, et y remplacer les deux `3` par `4` ;
- ajouter :

```php
	public function test_version_4_adds_the_history_tables(): void {
		global $wpdb;

		$this->assertSame(
			[ 'id', 'network_id', 'site_id', 'type', 'subject', 'meta', 'created_at' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::events_table() ) )
		);
		$this->assertSame(
			[ 'site_id', 'day', 'network_id', 'users_count', 'content_count', 'media_count', 'disk_bytes', 'db_bytes', 'alert_level', 'alerts_count' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::snapshots_table() ) )
		);
		$this->assertContains( Schema::events_table(), Schema::tables() );
		$this->assertContains( Schema::snapshots_table(), Schema::tables() );
	}
```

Dans `tests/php/Install/InstallerTest.php`, ajouter :

```php
	public function test_maybe_upgrade_passes_the_previous_schema_version(): void {
		$seen = [];
		add_action(
			'msradar_upgraded',
			static function ( $version, $previous ) use ( &$seen ): void {
				$seen[] = [ $version, $previous ];
			},
			10,
			2
		);

		update_site_option( Schema::OPTION, 3 );
		Installer::maybe_upgrade();

		$this->assertSame( [ [ Schema::VERSION, 3 ] ], $seen );
	}
```

Dans `tests/php/Scan/QueueTest.php`, ajouter :

```php
	public function test_an_upgrade_from_version_3_adds_the_history_tables_without_a_new_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();

		do_action( 'msradar_upgraded', 4, 3 );

		$this->assertSame( 0, $this->plugin()->sites()->count_dirty( $network ) );
	}
```

Créer `tests/php/Storage/EventsRepositoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Tests\TestCase;

final class EventsRepositoryTest extends TestCase {

	private const NETWORK = 77;

	private function event( string $type, int $site_id, string $created_at, array $overrides = [] ): array {
		return array_merge(
			[
				'network_id' => self::NETWORK,
				'site_id'    => $site_id,
				'type'       => $type,
				'subject'    => 'akismet/akismet.php',
				'meta'       => [],
				'created_at' => $created_at,
			],
			$overrides
		);
	}

	private function repository(): EventsRepository {
		return $this->plugin()->events();
	}

	public function test_events_are_read_newest_first_with_their_meta(): void {
		$this->repository()->insert(
			[
				$this->event( 'plugin_activated', 5, '2026-09-01 10:00:00' ),
				$this->event( 'theme_switched', 5, '2026-09-02 10:00:00', [ 'subject' => 'child', 'meta' => [ 'from' => 'parent' ] ] ),
			]
		);

		$result = $this->repository()->query(
			[
				'network_id' => self::NETWORK,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 20,
			]
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'theme_switched', 'plugin_activated' ], array_column( $result['items'], 'type' ) );
		$this->assertSame( [ 'from' => 'parent' ], $result['items'][0]['meta'] );
		$this->assertSame( [], $result['items'][1]['meta'] );
		$this->assertSame( 5, $result['items'][0]['site_id'] );
		$this->assertSame( '2026-09-02 10:00:00', $result['items'][0]['created_at'] );
	}

	public function test_filters_by_date_type_site_and_network_and_paginates(): void {
		$this->repository()->insert(
			[
				$this->event( 'plugin_activated', 5, '2026-08-01 10:00:00' ),
				$this->event( 'alert_raised', 5, '2026-09-01 10:00:00', [ 'subject' => 'inactive' ] ),
				$this->event( 'alert_raised', 6, '2026-09-02 10:00:00', [ 'subject' => 'inactive' ] ),
				$this->event( 'alert_raised', 7, '2026-09-03 10:00:00', [ 'network_id' => 78 ] ),
			]
		);
		$base = [
			'network_id' => self::NETWORK,
			'since'      => null,
			'types'      => [],
			'site_id'    => 0,
			'page'       => 1,
			'per_page'   => 20,
		];

		$this->assertSame( 2, $this->repository()->query( array_merge( $base, [ 'since' => '2026-08-15 00:00:00' ] ) )['total'] );
		$this->assertSame( 2, $this->repository()->query( array_merge( $base, [ 'types' => [ 'alert_raised', 'not_a_type' ] ] ) )['total'] );
		$this->assertSame( [ 5, 5 ], array_column( $this->repository()->query( array_merge( $base, [ 'site_id' => 5 ] ) )['items'], 'site_id' ) );

		$page = $this->repository()->query( array_merge( $base, [ 'per_page' => 2, 'page' => 2 ] ) );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ 'plugin_activated' ], array_column( $page['items'], 'type' ) );
	}

	public function test_counts_by_type_and_purge_stay_in_their_network(): void {
		$this->repository()->insert(
			[
				$this->event( 'alert_raised', 5, '2026-06-01 10:00:00' ),
				$this->event( 'alert_raised', 5, '2026-09-01 10:00:00' ),
				$this->event( 'alert_resolved', 5, '2026-09-02 10:00:00' ),
				$this->event( 'alert_raised', 9, '2026-06-01 10:00:00', [ 'network_id' => 78 ] ),
			]
		);

		$this->assertSame(
			[
				'alert_raised'   => 1,
				'alert_resolved' => 1,
			],
			$this->repository()->counts( self::NETWORK, '2026-08-01 00:00:00' )
		);

		$this->assertSame( 1, $this->repository()->purge( self::NETWORK, '2026-07-01 00:00:00' ) );
		$this->assertSame(
			[
				'alert_raised'   => 1,
				'alert_resolved' => 1,
			],
			$this->repository()->counts( self::NETWORK, '2026-01-01 00:00:00' ),
			'The event of 2026-06-01 is purged.'
		);
		$this->assertSame( [ 'alert_raised' => 1 ], $this->repository()->counts( 78, '2026-01-01 00:00:00' ), 'The other network keeps its events.' );
	}

	public function test_a_long_subject_is_cut_to_the_column_size(): void {
		$this->repository()->insert( [ $this->event( 'site_created', 5, '2026-09-01 10:00:00', [ 'subject' => str_repeat( 'é', 300 ) ] ) ] );

		$items = $this->repository()->query(
			[
				'network_id' => self::NETWORK,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 1,
			]
		)['items'];

		$this->assertSame( 191, mb_strlen( $items[0]['subject'] ) );
	}
}
```

Créer `tests/php/Storage/SnapshotsRepositoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Storage\SnapshotsRepository;
use MultisiteRadar\Tests\TestCase;

final class SnapshotsRepositoryTest extends TestCase {

	private const NETWORK = 77;

	private function repository(): SnapshotsRepository {
		return $this->plugin()->snapshots();
	}

	public function set_up(): void {
		parent::set_up();
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			7701,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => $scanned,
				'users_count'   => 3,
				'content_count' => 10,
				'media_count'   => 4,
				'disk_bytes'    => 2048,
				'alert_level'   => 3,
				'alerts_count'  => 2,
			]
		);
		$this->make_record(
			7702,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => $scanned,
				'content_count' => 5,
				'media_count'   => 1,
				'alert_level'   => 1,
				'alerts_count'  => 1,
			]
		);
		// Jamais analysé, puis un site d'un autre réseau : ni l'un ni l'autre n'est pris.
		$this->make_record( 7703, [ 'network_id' => self::NETWORK ] );
		$this->make_record(
			7801,
			[
				'network_id' => 78,
				'scanned_at' => $scanned,
			]
		);
	}

	public function test_capture_copies_the_analysed_sites_of_the_network_once_per_day(): void {
		$this->assertSame( 2, $this->repository()->capture( self::NETWORK, '2026-09-10' ) );

		$this->make_record(
			7701,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => '2026-09-10 00:00:00',
				'content_count' => 12,
			]
		);
		$this->assertSame( 2, $this->repository()->capture( self::NETWORK, '2026-09-10' ), 'A second capture the same day replaces the first.' );

		$series = $this->repository()->site_series( 7701, '2026-09-01' );
		$this->assertCount( 1, $series );
		$this->assertSame( '2026-09-10', $series[0]['day'] );
		$this->assertSame( 12, $series[0]['content_count'] );
		$this->assertSame( [], $this->repository()->site_series( 7703, '2026-09-01' ) );
		$this->assertSame( [], $this->repository()->site_series( 7801, '2026-09-01' ) );
	}

	public function test_network_series_sums_the_sites_and_counts_them_by_severity(): void {
		$this->repository()->capture( self::NETWORK, '2026-09-09' );
		$this->repository()->capture( self::NETWORK, '2026-09-10' );

		$series = $this->repository()->network_series( self::NETWORK, '2026-09-10' );

		$this->assertSame(
			[
				[
					'day'            => '2026-09-10',
					'sites'          => 2,
					'content_count'  => 15,
					'media_count'    => 5,
					'alerts_error'   => 1,
					'alerts_warning' => 0,
					'alerts_info'    => 1,
				],
			],
			$series
		);
	}

	public function test_site_series_keeps_null_measures(): void {
		$this->repository()->capture( self::NETWORK, '2026-09-10' );

		$point = $this->repository()->site_series( 7702, '2026-09-01' )[0];

		$this->assertNull( $point['disk_bytes'] );
		$this->assertNull( $point['db_bytes'] );
		$this->assertSame( 1, $point['alert_level'] );
	}

	public function test_purge_removes_old_days_of_its_network_only(): void {
		$this->repository()->capture( self::NETWORK, '2025-01-01' );
		$this->repository()->capture( self::NETWORK, '2026-09-10' );
		$this->repository()->capture( 78, '2025-01-01' );

		$this->assertSame( 2, $this->repository()->purge( self::NETWORK, '2026-01-01' ) );

		$this->assertCount( 1, $this->repository()->site_series( 7701, '2000-01-01' ) );
		$this->assertCount( 1, $this->repository()->site_series( 7801, '2000-01-01' ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'DirectAccessGuardTest|SchemaTest|InstallerTest|EventsRepositoryTest|SnapshotsRepositoryTest|test_an_upgrade_from_version_3'`
Expected: FAIL. `Schema::events_table()` n'existe pas ; `Plugin::events()` n'existe pas ; la mise à niveau de 3 à 4 marque tout le réseau ; `msradar_upgraded` ne reçoit qu'un argument. `DirectAccessGuardTest` passe déjà (correctif `3a9f28d` sur `main`) : c'est un test de non-régression.

- [ ] **Step 3: Extend the schema**

Dans `includes/Install/Schema.php` :
- remplacer le docblock et la valeur de `VERSION` :

```php
	/**
	 * 1 : tables de M1 ; 2 : colonne siteurl (M2) ; 3 : mesures de M4, remplies par une analyse complète ;
	 * 4 : tables msradar_events et msradar_snapshots (M6), sans nouvelle analyse (écart E2 du plan M6).
	 */
	public const VERSION = 4;
```

- ajouter après `extensions_table()` :

```php
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_events';
	}

	public static function snapshots_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_snapshots';
	}
```

- `tables()` renvoie `[ self::sites_table(), self::extensions_table(), self::events_table(), self::snapshots_table() ]` ;
- dans `install()`, déclarer `$events = self::events_table();` et `$snapshots = self::snapshots_table();` à côté des autres, puis ajouter après le `dbDelta` des extensions :

```php
		dbDelta(
			"CREATE TABLE {$events} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
site_id bigint(20) unsigned NOT NULL DEFAULT 0,
type varchar(40) NOT NULL,
subject varchar(191) NOT NULL DEFAULT '',
meta longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY network_created (network_id,created_at),
KEY site_created (site_id,created_at),
KEY type_created (type,created_at)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
site_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
users_count int(10) unsigned NOT NULL DEFAULT 0,
content_count int(10) unsigned NOT NULL DEFAULT 0,
media_count int(10) unsigned NOT NULL DEFAULT 0,
disk_bytes bigint(20) unsigned DEFAULT NULL,
db_bytes bigint(20) unsigned DEFAULT NULL,
alert_level tinyint(3) unsigned NOT NULL DEFAULT 0,
alerts_count smallint(5) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (site_id,day),
KEY network_day (network_id,day)
) {$charset_collate};"
		);
```

Dans `includes/Install/Installer.php`, `maybe_upgrade()` devient :

```php
	public static function maybe_upgrade(): void {
		if ( Schema::is_current() ) {
			return;
		}
		$previous = (int) get_site_option( Schema::OPTION, 0 );
		if ( ! self::install() ) {
			return;
		}
		do_action( 'msradar_upgraded', Schema::VERSION, $previous );
	}
```

Dans `includes/Scan/Queue.php` :
- dans `register()`, la ligne de `msradar_upgraded` devient `add_action( 'msradar_upgraded', [ $this, 'on_upgraded' ], 10, 2 );` ;
- `on_upgraded()` devient :

```php
	/**
	 * Les colonnes ajoutées jusqu'à la version 3 du schéma ne se remplissent qu'à l'analyse : tout le réseau courant
	 * est alors marqué. La version 4 n'ajoute que les tables de l'historique : rien à réanalyser (écart E2 du plan M6).
	 *
	 * @param mixed $version  Version de schéma installée.
	 * @param mixed $previous Version avant la mise à niveau (0 : inconnue ou première installation).
	 */
	public function on_upgraded( $version = 0, $previous = 0 ): void {
		$this->schedule();
		if ( (int) $previous < 3 ) {
			$this->request_full_scan( get_current_network_id() );
		}
	}
```

- [ ] **Step 4: Create the repositories**

`includes/Storage/EventsRepository.php` :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le SQL de la table msradar_events : le journal des changements du réseau (spec §3.1, lot 3).
 */
final class EventsRepository {

	public const TYPES = [ 'site_created', 'site_deleted', 'plugin_activated', 'plugin_deactivated', 'theme_switched', 'alert_raised', 'alert_resolved' ];

	/**
	 * @param array<int, array{network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}> $events
	 * @throws \RuntimeException Si une écriture échoue.
	 */
	public function insert( array $events ): void {
		global $wpdb;
		foreach ( $events as $event ) {
			$result = $wpdb->insert(
				Schema::events_table(),
				[
					'network_id' => (int) $event['network_id'],
					'site_id'    => (int) $event['site_id'],
					'type'       => (string) $event['type'],
					'subject'    => mb_substr( (string) $event['subject'], 0, 191 ),
					'meta'       => [] === $event['meta'] ? null : (string) wp_json_encode( $event['meta'] ),
					'created_at' => (string) $event['created_at'],
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
			if ( false === $result ) {
				throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
			}
		}
	}

	/**
	 * Événements d'un réseau, les plus récents d'abord.
	 *
	 * @param array $args network_id (int), since (date GMT « Y-m-d H:i:s » ou null), types (string[], vide : tous),
	 *                    site_id (int, 0 : tous), page (int ≥ 1), per_page (int ≥ 1).
	 * @return array{items: array<int, array{id: int, network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}>, total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$clauses = [ 'network_id = %d' ];
		$params  = [ (int) $args['network_id'] ];
		if ( null !== $args['since'] ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = (string) $args['since'];
		}
		$types = array_values( array_intersect( array_map( 'strval', (array) $args['types'] ), self::TYPES ) );
		if ( [] !== $types ) {
			$clauses[] = 'type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params    = array_merge( $params, $types );
		}
		if ( (int) $args['site_id'] > 0 ) {
			$clauses[] = 'site_id = %d';
			$params[]  = (int) $args['site_id'];
		}

		$table    = Schema::events_table();
		$where    = implode( ' AND ', $clauses );
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ne contient que des fragments fixes et des placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $params ) )
		);
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Parameters are spread via array_merge ; placeholders match.
				"SELECT id, network_id, site_id, type, subject, meta, created_at FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( [ $table ], $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

		return [
			'items' => array_map( [ self::class, 'row' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * @return array<string, int> Type => nombre d'événements depuis $since (GMT), types absents omis.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function counts( int $network_id, string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT type, COUNT(*) AS total FROM %i WHERE network_id = %d AND created_at >= %s GROUP BY type ORDER BY type ASC', Schema::events_table(), $network_id, $since ),
			ARRAY_A
		);
		self::check_read();
		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * @return int Nombre d'événements supprimés (antérieurs à $before, GMT).
	 * @throws \RuntimeException Si la suppression échoue.
	 */
	public function purge( int $network_id, string $before ): int {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND created_at < %s', Schema::events_table(), $network_id, $before ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
		return (int) $deleted;
	}

	/**
	 * @param array $row Ligne brute.
	 * @return array{id: int, network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}
	 */
	private static function row( array $row ): array {
		$meta = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : null;
		return [
			'id'         => (int) $row['id'],
			'network_id' => (int) $row['network_id'],
			'site_id'    => (int) $row['site_id'],
			'type'       => (string) $row['type'],
			'subject'    => (string) $row['subject'],
			'meta'       => is_array( $meta ) ? $meta : [],
			'created_at' => (string) $row['created_at'],
		];
	}

	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
```

`includes/Storage/SnapshotsRepository.php` :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le SQL de la table msradar_snapshots : une ligne par site analysé et par jour UTC (spec §3.1, lot 3).
 */
final class SnapshotsRepository {

	/**
	 * Instantané des sites analysés du réseau pour un jour : la prise du jour remplace la précédente (écart E6).
	 *
	 * @param string $day Jour UTC « Y-m-d ».
	 * @return int Nombre de sites pris.
	 * @throws \RuntimeException Si une écriture échoue.
	 */
	public function capture( int $network_id, string $day ): int {
		global $wpdb;
		$table = Schema::snapshots_table();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND day = %s', $table, $network_id, $day ) );
		self::check();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (site_id, day, network_id, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count)
				SELECT site_id, %s, network_id, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count
				FROM %i WHERE network_id = %d AND scanned_at IS NOT NULL',
				$table,
				$day,
				Schema::sites_table(),
				$network_id
			)
		);
		self::check();
		return (int) $inserted;
	}

	/**
	 * Une ligne par jour depuis $since_day : sites, contenus, médias, sites par gravité de leur alerte la plus haute.
	 *
	 * @return array<int, array{day: string, sites: int, content_count: int, media_count: int, alerts_error: int, alerts_warning: int, alerts_info: int}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function network_series( int $network_id, string $since_day ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, COUNT(*) AS sites, SUM(content_count) AS content_count, SUM(media_count) AS media_count,
				SUM(alert_level = 3) AS alerts_error, SUM(alert_level = 2) AS alerts_warning, SUM(alert_level = 1) AS alerts_info
				FROM %i WHERE network_id = %d AND day >= %s GROUP BY day ORDER BY day ASC',
				Schema::snapshots_table(),
				$network_id,
				$since_day
			),
			ARRAY_A
		);
		self::check();
		return array_map(
			static function ( array $row ): array {
				return [
					'day'            => (string) $row['day'],
					'sites'          => (int) $row['sites'],
					'content_count'  => (int) $row['content_count'],
					'media_count'    => (int) $row['media_count'],
					'alerts_error'   => (int) $row['alerts_error'],
					'alerts_warning' => (int) $row['alerts_warning'],
					'alerts_info'    => (int) $row['alerts_info'],
				];
			},
			(array) $rows
		);
	}

	/**
	 * @return array<int, array{day: string, users_count: int, content_count: int, media_count: int, disk_bytes: int|null, db_bytes: int|null, alert_level: int, alerts_count: int}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function site_series( int $site_id, string $since_day ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count FROM %i WHERE site_id = %d AND day >= %s ORDER BY day ASC',
				Schema::snapshots_table(),
				$site_id,
				$since_day
			),
			ARRAY_A
		);
		self::check();
		return array_map(
			static function ( array $row ): array {
				return [
					'day'           => (string) $row['day'],
					'users_count'   => (int) $row['users_count'],
					'content_count' => (int) $row['content_count'],
					'media_count'   => (int) $row['media_count'],
					'disk_bytes'    => null === $row['disk_bytes'] ? null : (int) $row['disk_bytes'],
					'db_bytes'      => null === $row['db_bytes'] ? null : (int) $row['db_bytes'],
					'alert_level'   => (int) $row['alert_level'],
					'alerts_count'  => (int) $row['alerts_count'],
				];
			},
			(array) $rows
		);
	}

	/**
	 * @param string $before_day Jour UTC « Y-m-d » : les jours antérieurs sont supprimés.
	 * @return int Nombre de lignes supprimées.
	 * @throws \RuntimeException Si la suppression échoue.
	 */
	public function purge( int $network_id, string $before_day ): int {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND day < %s', Schema::snapshots_table(), $network_id, $before_day ) );
		self::check();
		return (int) $deleted;
	}

	private static function check(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
```

Dans `includes/Plugin.php` :
- ajouter `use MultisiteRadar\Storage\EventsRepository;` et `use MultisiteRadar\Storage\SnapshotsRepository;` (ordre alphabétique) ;
- ajouter les propriétés `private ?EventsRepository $events = null;` et `private ?SnapshotsRepository $snapshots = null;` ;
- ajouter après `extensions()` :

```php
	public function events(): EventsRepository {
		return $this->events ??= new EventsRepository();
	}

	public function snapshots(): SnapshotsRepository {
		return $this->snapshots ??= new SnapshotsRepository();
	}
```

Dans `uninstall.php` :
- la liste des tables devient `[ 'msradar_sites', 'msradar_site_extensions', 'msradar_events', 'msradar_snapshots' ]` ;
- ajouter `'msradar_digest_sent'` à `$msradar_network_options`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'DirectAccessGuardTest|SchemaTest|InstallerTest|EventsRepositoryTest|SnapshotsRepositoryTest|QueueTest'`
Expected: PASS (hors les 7 échecs connus de `QueueTest`) ; puis la suite complète sur tables neuves : verte.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Install/ includes/Storage/EventsRepository.php includes/Storage/SnapshotsRepository.php includes/Scan/Queue.php includes/Plugin.php uninstall.php tests/php/
git commit -m "feat: add the events and snapshots tables (schema 4) without a new analysis"
```

### Task 2: Journal des changements — analyses, recalcul des alertes, cycle de vie des sites

**Files:**
- Create: `includes/Scan/ChangeLog.php`
- Modify: `includes/Scan/BatchRunner.php`, `includes/Scan/Queue.php`, `includes/Scan/Invalidation.php`, `includes/Plugin.php`
- Test: `tests/php/Scan/ChangeLogTest.php` (créé) ; `tests/php/Scan/BatchRunnerTest.php`, `tests/php/Scan/QueueTest.php`, `tests/php/Scan/InvalidationTest.php`

**Interfaces:**
- Consumes : `EventsRepository::insert()`, `::query()` (tâche 1), `SitesRepository::find()`, `SiteRecord` (`data['plugins_local']` : fichiers des plugins activés sur le site ; `data['alerts']` : `{rule, severity, args}`).
- Produces :
  - `Scan\ChangeLog::__construct( EventsRepository $events )` ;
  - `compare( ?SiteRecord $before, SiteRecord $after ): void` ;
  - `record( int $network_id, int $site_id, string $type, string $subject, array $meta = [] ): void` ;
  - `static diff( ?SiteRecord $before, SiteRecord $after, string $now_gmt ): array` (événements au format de `EventsRepository::insert()`) ;
  - `Plugin::change_log(): ChangeLog` ;
  - constructeurs :
    - `BatchRunner( SitesRepository, ExtensionsRepository, SiteCollector, AlertEvaluator, Lock, ChangeLog )` ;
    - `Queue( BatchRunner, SitesRepository, AlertEvaluator, Settings, ChangeLog )` ;
    - `Invalidation( SitesRepository, ExtensionsRepository, Settings, ChangeLog )` ;
  - métadonnées des événements :
    - `theme_switched` : `{from}` ;
    - `alert_raised` / `alert_resolved` : `{severity}` ;
    - `site_created` / `site_deleted` : `{name}` (nom brut, éventuellement échappé par le cœur) ;
    - extension activée ou désactivée sur le réseau : `site_id = 0`, `{network: true}`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Scan/ChangeLogTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\ChangeLog;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class ChangeLogTest extends TestCase {

	private const NOW = '2026-09-10 12:00:00';

	private function state( array $plugins, string $theme, array $alerts, ?string $scanned_at = '2026-09-01 00:00:00' ): SiteRecord {
		$record                       = new SiteRecord();
		$record->site_id              = 4400;
		$record->network_id           = 77;
		$record->scanned_at           = $scanned_at;
		$record->theme_stylesheet     = $theme;
		$record->data['plugins_local'] = $plugins;
		$record->data['alerts']        = array_map(
			static function ( string $rule, string $severity ): array {
				return [
					'rule'     => $rule,
					'severity' => $severity,
					'args'     => [],
				];
			},
			array_keys( $alerts ),
			array_values( $alerts )
		);
		return $record;
	}

	public function test_the_first_analysis_is_the_reference_and_records_nothing(): void {
		$after = $this->state( [ 'a/a.php' ], 'twentytwentyfive', [ 'no_users' => 'error' ] );

		$this->assertSame( [], ChangeLog::diff( null, $after, self::NOW ) );
		$this->assertSame( [], ChangeLog::diff( $this->state( [], '', [], null ), $after, self::NOW ) );
	}

	public function test_plugins_theme_and_alerts_that_changed_become_events(): void {
		$before = $this->state( [ 'a/a.php', 'b/b.php' ], 'parent', [ 'inactive' => 'warning' ] );
		$after  = $this->state( [ 'b/b.php', 'c/c.php' ], 'child', [ 'no_users' => 'error' ] );

		$events = ChangeLog::diff( $before, $after, self::NOW );

		$this->assertSame(
			[
				[ 'plugin_activated', 'c/c.php', [] ],
				[ 'plugin_deactivated', 'a/a.php', [] ],
				[ 'theme_switched', 'child', [ 'from' => 'parent' ] ],
				[ 'alert_raised', 'no_users', [ 'severity' => 'error' ] ],
				[ 'alert_resolved', 'inactive', [ 'severity' => 'warning' ] ],
			],
			array_map(
				static function ( array $event ): array {
					return [ $event['type'], $event['subject'], $event['meta'] ];
				},
				$events
			)
		);
		foreach ( $events as $event ) {
			$this->assertSame( 77, $event['network_id'] );
			$this->assertSame( 4400, $event['site_id'] );
			$this->assertSame( self::NOW, $event['created_at'] );
		}
	}

	public function test_a_severity_change_alone_records_nothing(): void {
		$before = $this->state( [], 'twentytwentyfive', [ 'inactive' => 'warning' ] );
		$after  = $this->state( [], 'twentytwentyfive', [ 'inactive' => 'error' ] );

		$this->assertSame( [], ChangeLog::diff( $before, $after, self::NOW ) );
	}

	public function test_compare_and_record_write_to_the_journal(): void {
		$log = $this->plugin()->change_log();
		$log->compare( $this->state( [], 'twentytwentyfive', [] ), $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ) );
		$log->record( 77, 0, 'plugin_activated', 'n/n.php', [ 'network' => true ] );

		$items = $this->plugin()->events()->query(
			[
				'network_id' => 77,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];

		$this->assertEqualsCanonicalizing( [ 'a/a.php', 'n/n.php' ], array_column( $items, 'subject' ) );
		$this->assertContains( 0, array_column( $items, 'site_id' ) );
	}

	public function test_a_failed_write_is_reported_and_never_thrown(): void {
		global $wpdb;
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'INSERT INTO msradar_missing_table VALUES (1)' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->change_log()->record( 77, 1, 'site_created', 'example.org/' );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ ChangeLog::class ], $reported );
	}
}
```

Dans `tests/php/Scan/BatchRunnerTest.php`, ajouter (lire le fichier pour reprendre son accès au runner) :

```php
	public function test_a_plugin_activated_between_two_analyses_is_recorded(): void {
		$site_id = self::factory()->blog->create();
		$runner  = $this->plugin()->runner();
		$this->assertTrue( $runner->scan_site( $site_id ) );

		update_blog_option( $site_id, 'active_plugins', [ 'akismet/akismet.php' ] );
		$this->assertTrue( $runner->scan_site( $site_id ) );

		$items = $this->plugin()->events()->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null,
				'types'      => [ 'plugin_activated' ],
				'site_id'    => $site_id,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];
		$this->assertSame( [ 'akismet/akismet.php' ], array_column( $items, 'subject' ) );
	}
```

Si le collecteur ne relève pas un plugin absent du disque dans `plugins_local`, lire `includes/Collector/SiteCollector.php` pour voir comment il lit `active_plugins` et choisir un plugin réellement installé dans l'environnement de test (par exemple `hello.php`) ; le test doit rester déterministe en CI (WordPress téléchargé avec ses plugins par défaut) comme en local.

Dans `tests/php/Scan/QueueTest.php`, ajouter :

```php
	public function test_the_alert_recompute_records_raised_and_resolved_alerts(): void {
		$this->make_record(
			3990,
			[
				'name'        => 'Recompute journal',
				'users_count' => 0,
				'scanned_at'  => '2026-09-01 00:00:00',
				'data'        => [ 'alerts' => [] ],
			]
		);

		$this->plugin()->queue()->recompute_alerts();

		$items = $this->plugin()->events()->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null,
				'types'      => [],
				'site_id'    => 3990,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];
		$this->assertContains( [ 'alert_raised', 'no_users' ], array_map( static fn ( array $item ): array => [ $item['type'], $item['subject'] ], $items ) );

		$this->plugin()->settings()->update( [ 'alerts' => [ 'rules' => [ 'no_users' => [ 'enabled' => false ] ] ] ] );
		$this->plugin()->queue()->recompute_alerts();

		$resolved = $this->plugin()->events()->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null,
				'types'      => [ 'alert_resolved' ],
				'site_id'    => 3990,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];
		$this->assertSame( [ 'no_users' ], array_column( $resolved, 'subject' ) );
	}
```

Dans `tests/php/Scan/InvalidationTest.php`, ajouter :

```php
	/**
	 * @return array[]
	 */
	private function events_of( int $site_id ): array {
		return $this->plugin()->events()->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null,
				'types'      => [],
				'site_id'    => $site_id,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];
	}

	public function test_creating_and_deleting_a_site_are_recorded_with_its_name(): void {
		$site_id = self::factory()->blog->create( [ 'title' => 'Journal & Co' ] );

		$created = $this->events_of( $site_id );
		$this->assertSame( [ 'site_created' ], array_column( $created, 'type' ) );
		$this->assertStringContainsString( 'Journal', (string) $created[0]['meta']['name'] );

		wp_delete_site( $site_id );

		$this->assertSame( [ 'site_deleted', 'site_created' ], array_column( $this->events_of( $site_id ), 'type' ) );
		$this->assertNull( $this->plugin()->sites()->find( $site_id ), 'The site row is still removed.' );
	}

	public function test_a_network_activation_is_recorded_for_the_whole_network(): void {
		do_action( 'activated_plugin', 'akismet/akismet.php', true );
		do_action( 'deactivated_plugin', 'akismet/akismet.php', true );

		$items = array_values(
			array_filter(
				$this->events_of( 0 ),
				static fn ( array $item ): bool => 0 === $item['site_id'] && 'akismet/akismet.php' === $item['subject']
			)
		);
		$this->assertSame( [ 'plugin_deactivated', 'plugin_activated' ], array_column( $items, 'type' ) );
		$this->assertSame( [ 'network' => true ], $items[0]['meta'] );
	}
```

`events_of( 0 )` lit tout le réseau (site 0 = tous les sites dans `query()`) : le filtre du test garde les événements du réseau entier.

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'ChangeLogTest|test_a_plugin_activated_between_two_analyses|test_the_alert_recompute_records|test_creating_and_deleting_a_site|test_a_network_activation_is_recorded'`
Expected: FAIL, `Class "MultisiteRadar\Scan\ChangeLog" not found` puis aucun événement écrit.

- [ ] **Step 3: Create `ChangeLog`**

`includes/Scan/ChangeLog.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Journal des changements du réseau (spec §7.3). Compare l'état d'un site avant et après une analyse ou un recalcul
 * des alertes, et note les événements isolés (création ou suppression d'un site, extension activée sur le réseau).
 * Une écriture qui échoue est signalée à msradar_error : le journal ne fait jamais échouer une analyse.
 */
final class ChangeLog {

	private EventsRepository $events;

	public function __construct( EventsRepository $events ) {
		$this->events = $events;
	}

	public function compare( ?SiteRecord $before, SiteRecord $after ): void {
		$this->write( self::diff( $before, $after, current_time( 'mysql', true ) ) );
	}

	/**
	 * @param int    $site_id 0 pour un événement du réseau entier (écart E4).
	 * @param string $type    Un des EventsRepository::TYPES.
	 * @param array  $meta    Métadonnées (JSON).
	 */
	public function record( int $network_id, int $site_id, string $type, string $subject, array $meta = [] ): void {
		$this->write(
			[
				[
					'network_id' => $network_id,
					'site_id'    => $site_id,
					'type'       => $type,
					'subject'    => $subject,
					'meta'       => $meta,
					'created_at' => current_time( 'mysql', true ),
				],
			]
		);
	}

	/**
	 * Événements entre deux états d'un site : plugins activés ou désactivés sur le site, thème changé, alertes
	 * apparues ou résolues. Tant que l'état précédent n'a jamais été analysé, rien n'est noté (écart E3).
	 *
	 * @return array<int, array{network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}>
	 */
	public static function diff( ?SiteRecord $before, SiteRecord $after, string $now_gmt ): array {
		if ( null === $before || null === $before->scanned_at ) {
			return [];
		}
		$events = [];
		$add    = static function ( string $type, string $subject, array $meta = [] ) use ( $after, $now_gmt, &$events ): void {
			$events[] = [
				'network_id' => $after->network_id,
				'site_id'    => $after->site_id,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => $meta,
				'created_at' => $now_gmt,
			];
		};

		$old_plugins = self::plugins( $before );
		$new_plugins = self::plugins( $after );
		foreach ( array_diff( $new_plugins, $old_plugins ) as $file ) {
			$add( 'plugin_activated', $file );
		}
		foreach ( array_diff( $old_plugins, $new_plugins ) as $file ) {
			$add( 'plugin_deactivated', $file );
		}
		if ( '' !== $after->theme_stylesheet && $after->theme_stylesheet !== $before->theme_stylesheet ) {
			$add( 'theme_switched', $after->theme_stylesheet, [ 'from' => $before->theme_stylesheet ] );
		}

		$old_alerts = self::alerts( $before );
		$new_alerts = self::alerts( $after );
		foreach ( array_diff_key( $new_alerts, $old_alerts ) as $rule => $severity ) {
			$add( 'alert_raised', (string) $rule, [ 'severity' => $severity ] );
		}
		foreach ( array_diff_key( $old_alerts, $new_alerts ) as $rule => $severity ) {
			$add( 'alert_resolved', (string) $rule, [ 'severity' => $severity ] );
		}
		return $events;
	}

	/**
	 * @return string[] Fichiers des plugins activés sur le site.
	 */
	private static function plugins( SiteRecord $record ): array {
		return array_values( array_unique( array_filter( array_map( 'strval', (array) ( $record->data['plugins_local'] ?? [] ) ) ) ) );
	}

	/**
	 * @return array<string, string> Règle => gravité.
	 */
	private static function alerts( SiteRecord $record ): array {
		$alerts = [];
		foreach ( (array) ( $record->data['alerts'] ?? [] ) as $alert ) {
			if ( is_array( $alert ) && isset( $alert['rule'] ) ) {
				$alerts[ (string) $alert['rule'] ] = (string) ( $alert['severity'] ?? '' );
			}
		}
		return $alerts;
	}

	/**
	 * @param array[] $events
	 */
	private function write( array $events ): void {
		if ( [] === $events ) {
			return;
		}
		try {
			$this->events->insert( $events );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', self::class, $error );
		}
	}
}
```

- [ ] **Step 4: Wire it into the scan, the recompute and the hooks**

`includes/Scan/BatchRunner.php` :
- nouveau paramètre `ChangeLog $changes` en dernier dans le constructeur, propriété `private ChangeLog $changes;` ;
- dans `scan_site()`, juste après `$this->sites->clear_dirty( $site_id );`, ajouter `$before = $this->previous( $site_id );` ; juste avant `do_action( 'msradar_site_scanned', $site_id, $record );`, ajouter `$this->changes->compare( $before, $record );` ;
- ajouter :

```php
	/**
	 * L'état enregistré avant l'analyse, pour le journal des changements. Une lecture qui échoue ne bloque pas l'analyse.
	 */
	private function previous( int $site_id ): ?SiteRecord {
		try {
			return $this->sites->find( $site_id );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return null;
		}
	}
```

`includes/Scan/Queue.php` :
- nouveau paramètre `ChangeLog $changes` en dernier dans le constructeur, propriété `private ChangeLog $changes;` ;
- `recompute_site()` devient :

```php
	private function recompute_site( SiteRecord $record, int $now ): void {
		$previous = clone $record;
		$before   = [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ];
		$this->evaluator->apply( $record, $now );
		if ( [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ] !== $before ) {
			$this->sites->save_alerts( $record );
			$this->changes->compare( $previous, $record );
		}
	}
```

`includes/Scan/Invalidation.php` :
- nouveau paramètre `ChangeLog $changes` en dernier dans le constructeur, propriété `private ChangeLog $changes;` ;
- dans `on_plugin_change()`, la branche `$network_wide` devient :

```php
		if ( $network_wide ) {
			$network_id = get_current_network_id();
			$this->sites->mark_all_dirty( $network_id );
			$this->changes->record( $network_id, 0, 'activated_plugin' === current_action() ? 'plugin_activated' : 'plugin_deactivated', (string) $plugin, [ 'network' => true ] );
			return;
		}
```

- dans `on_site_initialized()`, la fonction passée à `safely()` devient :

```php
			function () use ( $site ): void {
				// WP_Site::$site_id contient l'ID du réseau.
				$this->sites->insert_pending( (int) $site->blog_id, (int) $site->site_id, $site->domain . $site->path );
				$this->changes->record( (int) $site->site_id, (int) $site->blog_id, 'site_created', $site->domain . $site->path, [ 'name' => (string) get_blog_option( (int) $site->blog_id, 'blogname', '' ) ] );
			}
```

- `on_site_deleted()` devient :

```php
	public function on_site_deleted( WP_Site $site ): void {
		if ( ! $this->ready() ) {
			return;
		}
		// Le nom est lu avant la suppression de la ligne ; une lecture qui échoue n'empêche pas la suppression.
		$name = '';
		$this->safely(
			__METHOD__,
			function () use ( $site, &$name ): void {
				$record = $this->sites->find( (int) $site->blog_id );
				$name   = null !== $record ? $record->name : '';
			}
		);
		$this->safely(
			__METHOD__,
			function () use ( $site ): void {
				$this->sites->delete( (int) $site->blog_id );
				$this->extensions->delete_for_site( (int) $site->blog_id );
			}
		);
		$this->changes->record( (int) $site->site_id, (int) $site->blog_id, 'site_deleted', $site->domain . $site->path, [ 'name' => $name ] );
	}
```

`includes/Plugin.php` :
- `use MultisiteRadar\Scan\ChangeLog;` ; propriété `private ?ChangeLog $change_log = null;` ;
- service :

```php
	public function change_log(): ChangeLog {
		return $this->change_log ??= new ChangeLog( $this->events() );
	}
```

- passer `$this->change_log()` en dernier argument aux constructions de `BatchRunner` (dans `runner()`), de `Queue` (dans `queue()`) et d'`Invalidation` (dans `invalidation()`).

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'ChangeLogTest|BatchRunnerTest|QueueTest|InvalidationTest'`
Expected: PASS (hors les 7 échecs connus de `QueueTest`) ; puis la suite complète **sur tables neuves** : verte.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Scan/ includes/Plugin.php tests/php/Scan/
git commit -m "feat: record the changes of the network in a journal"
```

### Task 3: Instantanés quotidiens et purge selon la rétention

**Files:**
- Create: `includes/Scan/History.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Scan/HistoryTest.php` (créé)

**Interfaces:**
- Consumes : `SnapshotsRepository::capture()`, `::purge()`, `EventsRepository::purge()` (tâche 1), `Settings::get( 'retention.events_days', 90 )`, `Settings::get( 'retention.snapshots_days', 365 )`, `Queue::HOOK_DAILY`, `Schema::is_current()`.
- Produces : `Scan\History::__construct( SnapshotsRepository, EventsRepository, Settings )`, `register(): void` (`msradar_daily`, priorité 20), `daily( ?int $now = null ): void` ; `Plugin::history(): History`, enregistré dans `boot()`.

- [ ] **Step 1: Write the failing test**

Créer `tests/php/Scan/HistoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\History;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class HistoryTest extends TestCase {

	private const NOW = 1789560000; // 2026-09-16 (UTC).

	public function test_the_daily_task_takes_the_snapshot_of_the_day(): void {
		$this->make_record(
			4501,
			[
				'name'          => 'History site',
				'scanned_at'    => '2026-09-01 00:00:00',
				'content_count' => 8,
			]
		);

		$this->plugin()->history()->daily( self::NOW );

		$series = $this->plugin()->snapshots()->site_series( 4501, '2026-09-01' );
		$this->assertSame( [ gmdate( 'Y-m-d', self::NOW ) ], array_column( $series, 'day' ) );
		$this->assertSame( 8, $series[0]['content_count'] );
	}

	public function test_old_snapshots_and_events_are_purged_with_the_retention_of_the_settings(): void {
		$network = get_current_network_id();
		$this->make_record(
			4502,
			[
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->plugin()->snapshots()->capture( $network, gmdate( 'Y-m-d', self::NOW - 400 * DAY_IN_SECONDS ) );
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => $network,
					'site_id'    => 4502,
					'type'       => 'site_created',
					'subject'    => 'old.example/',
					'meta'       => [],
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 100 * DAY_IN_SECONDS ),
				],
				[
					'network_id' => $network,
					'site_id'    => 4502,
					'type'       => 'theme_switched',
					'subject'    => 'recent',
					'meta'       => [],
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 5 * DAY_IN_SECONDS ),
				],
			]
		);

		$this->plugin()->history()->daily( self::NOW );

		$this->assertSame( [ gmdate( 'Y-m-d', self::NOW ) ], array_column( $this->plugin()->snapshots()->site_series( 4502, '2000-01-01' ), 'day' ) );
		$subjects = array_column(
			$this->plugin()->events()->query(
				[
					'network_id' => $network,
					'since'      => null,
					'types'      => [],
					'site_id'    => 4502,
					'page'       => 1,
					'per_page'   => 20,
				]
			)['items'],
			'subject'
		);
		$this->assertSame( [ 'recent' ], $subjects );

		$this->plugin()->settings()->update( [ 'retention' => [ 'events_days' => 2 ] ] );
		$this->plugin()->history()->daily( self::NOW );
		$this->assertSame(
			0,
			$this->plugin()->events()->query(
				[
					'network_id' => $network,
					'since'      => null,
					'types'      => [],
					'site_id'    => 4502,
					'page'       => 1,
					'per_page'   => 20,
				]
			)['total']
		);
	}

	public function test_it_runs_on_the_daily_task_after_the_queue(): void {
		$this->assertSame( 20, has_action( Queue::HOOK_DAILY, [ $this->plugin()->history(), 'daily' ] ) );

		// WordPress passe un argument vide aux hooks : ni erreur de type, ni erreur signalée.
		$reported = 0;
		$count    = static function () use ( &$reported ): void {
			++$reported;
		};
		add_action( 'msradar_error', $count );
		try {
			do_action( Queue::HOOK_DAILY );
		} finally {
			remove_action( 'msradar_error', $count );
		}
		$this->assertSame( 0, $reported );
	}

	public function test_a_storage_failure_is_reported_and_never_thrown(): void {
		global $wpdb;
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_snapshots' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->history()->daily( self::NOW );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ History::class . '::daily' ], $reported );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/test.sh --filter HistoryTest`
Expected: FAIL with `Call to undefined method MultisiteRadar\Plugin::history()`.

- [ ] **Step 3: Create `History`**

`includes/Scan/History.php` :

```php
<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SnapshotsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Historique du réseau courant, sur la tâche quotidienne (spec §7.3) : instantané du jour des sites analysés (écart E6),
 * puis purge des instantanés et des événements au-delà de la rétention réglée. Ne fait jamais échouer la tâche.
 */
final class History {

	private SnapshotsRepository $snapshots;
	private EventsRepository $events;
	private Settings $settings;

	public function __construct( SnapshotsRepository $snapshots, EventsRepository $events, Settings $settings ) {
		$this->snapshots = $snapshots;
		$this->events    = $events;
		$this->settings  = $settings;
	}

	public function register(): void {
		// Après Queue::daily() (priorité 10), qui rattache les sites créés et retire les lignes orphelines.
		add_action( Queue::HOOK_DAILY, [ $this, 'daily' ], 20 );
	}

	/**
	 * Non typé : WordPress appelle les hooks avec un argument vide (« »), qu'un paramètre ?int refuserait en PHP 8.
	 *
	 * @param mixed $now Horodatage Unix (tests) ; maintenant sinon.
	 */
	public function daily( $now = null ): void {
		if ( ! Schema::is_current() ) {
			return;
		}
		$now        = is_int( $now ) ? $now : time();
		$network_id = get_current_network_id();
		$snapshots  = max( 1, (int) $this->settings->get( 'retention.snapshots_days', 365 ) );
		$events     = max( 1, (int) $this->settings->get( 'retention.events_days', 90 ) );
		try {
			$this->snapshots->capture( $network_id, gmdate( 'Y-m-d', $now ) );
			$this->snapshots->purge( $network_id, gmdate( 'Y-m-d', $now - $snapshots * DAY_IN_SECONDS ) );
			$this->events->purge( $network_id, gmdate( 'Y-m-d H:i:s', $now - $events * DAY_IN_SECONDS ) );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
		}
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Scan\History;` ; propriété `private ?History $history = null;` ;
- service :

```php
	public function history(): History {
		return $this->history ??= new History( $this->snapshots(), $this->events(), $this->settings() );
	}
```

- dans `boot()`, après `$this->state_watcher()->register();`, ajouter `$this->history()->register();`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter 'HistoryTest|QueueTest'`
Expected: PASS (hors les 7 échecs connus de `QueueTest`) ; suite complète sur tables neuves : verte.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 5: Commit**

```bash
git add includes/Scan/History.php includes/Plugin.php tests/php/Scan/HistoryTest.php
git commit -m "feat: take a daily snapshot of the sites and purge the history past its retention"
```

## Partie B — Lecture

### Task 4: Événements mis en forme — `EventsQuery` et `GET /events`

**Files:**
- Create: `includes/Query/EventsQuery.php`, `includes/Rest/EventsController.php`
- Modify: `includes/Query/Schemas.php`, `includes/Plugin.php`
- Test: `tests/php/Query/EventsQueryTest.php`, `tests/php/Rest/EventsControllerTest.php` (créés) ; `tests/php/Rest/ItemSchemasTest.php`

**Interfaces:**
- Consumes : `EventsRepository::query()`, `::counts()`, `::TYPES` (tâche 1) ; `SitesRepository::find_many()` ; `SitesQuery::identity()`, `SitesQuery::MAX_PAGE` ; `PluginsQuery::installed()` ; `RuleRegistry::get()` ; `Support\PlainText::from_html()` ; `Controller::guard()`, `::paginated()`, `::can_view()`.
- Produces :
  - `Query\EventsQuery::__construct( EventsRepository, SitesRepository, RuleRegistry )` ;
  - `list( array $args ): array{items, total}` (`page`, `per_page` ≤ 100, `since` (GMT `Y-m-d H:i:s` ou null), `type` (string[]), `site` (int, 0 = tous)) ;
  - `counts( string $since ): array<string, int>` ;
  - `format( array $event, ?SiteRecord $record ): array` ;
  - élément : `id` (int), `type`, `site` (identité `{id, name, url, admin_url}` ou `null` pour le réseau entier), `subject`, `label` (nom lisible du sujet), `message` (phrase traduite), `created_gmt` ;
  - `Query\Schemas::event(): array` ;
  - `GET /multisite-radar/v1/events` (`page`, `per_page` ≤ 100, `since` date-time, `type[]`, `site`), `msradar_view`, en-têtes de pagination, schéma publié ;
  - `Plugin::events_query(): EventsQuery`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Query/EventsQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class EventsQueryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha &amp; Co',
						'Version' => '1.0',
					],
				],
			],
			'plugins'
		);
		$this->make_record(
			4601,
			[
				'name'       => 'Current site',
				'url'        => 'example.org/current/',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
	}

	private function insert( string $type, int $site_id, string $subject, array $meta = [], string $created_at = '2026-09-10 10:00:00' ): void {
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => get_current_network_id(),
					'site_id'    => $site_id,
					'type'       => $type,
					'subject'    => $subject,
					'meta'       => $meta,
					'created_at' => $created_at,
				],
			]
		);
	}

	/**
	 * @return array[]
	 */
	private function items( array $args = [] ): array {
		return $this->plugin()->events_query()->list( array_merge( [ 'site' => 4601 ], $args ) )['items'];
	}

	public function test_a_plugin_event_names_the_plugin_and_its_site(): void {
		$this->insert( 'plugin_activated', 4601, 'alpha/alpha.php' );

		$item = $this->items()[0];

		$this->assertSame( 'plugin_activated', $item['type'] );
		$this->assertSame( 4601, $item['site']['id'] );
		$this->assertSame( 'Current site', $item['site']['name'] );
		$this->assertSame( 'Alpha & Co', $item['label'], 'Plain text, decoded.' );
		$this->assertSame( 'Plugin Alpha & Co activated.', $item['message'] );
		$this->assertSame( '2026-09-10T10:00:00', $item['created_gmt'] );
	}

	public function test_a_network_event_has_no_site(): void {
		$this->insert( 'plugin_deactivated', 0, 'alpha/alpha.php', [ 'network' => true ] );

		$items = $this->plugin()->events_query()->list( [ 'type' => [ 'plugin_deactivated' ] ] )['items'];
		$item  = array_values( array_filter( $items, static fn ( array $item ): bool => null === $item['site'] ) )[0];

		$this->assertSame( 'Plugin Alpha & Co network deactivated.', $item['message'] );
	}

	public function test_a_deleted_site_keeps_the_name_and_address_of_the_event(): void {
		// Le cœur enregistre le titre échappé (esc_html) : le nom est décodé en texte brut, l'affichage l'échappera.
		$this->insert( 'site_deleted', 4699, 'example.org/gone/', [ 'name' => 'Gone &amp; forgotten' ] );

		$item = $this->plugin()->events_query()->list( [ 'site' => 4699 ] )['items'][0];

		$this->assertSame( 4699, $item['site']['id'] );
		$this->assertSame( 'Gone & forgotten', $item['site']['name'] );
		$this->assertSame( 'http://example.org/gone/', $item['site']['url'] );
		$this->assertSame( 'Site deleted.', $item['message'] );
	}

	public function test_theme_and_alert_events_read_as_sentences(): void {
		$this->insert( 'theme_switched', 4601, 'msradar-missing-child', [ 'from' => 'msradar-missing-parent' ], '2026-09-10 10:00:01' );
		$this->insert( 'alert_raised', 4601, 'no_users', [ 'severity' => 'error' ], '2026-09-10 10:00:02' );
		$this->insert( 'alert_resolved', 4601, 'acme_unknown_rule', [], '2026-09-10 10:00:03' );

		$this->assertSame(
			[
				'Alert resolved: acme_unknown_rule.',
				'New alert: Site without users.',
				'Theme switched from msradar-missing-parent to msradar-missing-child.',
			],
			array_column( $this->items(), 'message' )
		);
	}

	public function test_filters_since_and_by_type_and_stays_in_the_network(): void {
		$this->insert( 'site_created', 4601, 'example.org/current/', [], '2026-08-01 00:00:00' );
		$this->insert( 'alert_raised', 4601, 'no_users', [], '2026-09-10 00:00:00' );
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => 2,
					'site_id'    => 4601,
					'type'       => 'alert_raised',
					'subject'    => 'no_users',
					'meta'       => [],
					'created_at' => '2026-09-10 00:00:00',
				],
			]
		);

		$this->assertCount( 1, $this->items( [ 'since' => '2026-09-01 00:00:00' ] ) );
		$this->assertSame( [ 'site_created' ], array_column( $this->items( [ 'type' => [ 'site_created' ] ] ), 'type' ) );
		$this->assertSame( 2, $this->plugin()->events_query()->list( [ 'site' => 4601 ] )['total'], 'The event of network 2 is not read.' );
	}
}
```

Le libellé `Site without users` est celui de la règle `no_users` : le lire dans `includes/Alerts/Rules/NoUsersRule.php` et l'ajuster si besoin, sans changer la règle.

Créer `tests/php/Rest/EventsControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class EventsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$events = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$events[] = [
				'network_id' => get_current_network_id(),
				'site_id'    => 4701,
				'type'       => 'alert_raised',
				'subject'    => 'no_users',
				'meta'       => [],
				'created_at' => '2026-09-1' . $i . ' 00:00:00',
			];
		}
		$this->plugin()->events()->insert( $events );
	}

	public function test_lists_events_with_pagination_headers_for_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/events' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/events' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request(
			'GET',
			'/events',
			[
				'site'     => 4701,
				'per_page' => 2,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( [ '2026-09-13T00:00:00', '2026-09-12T00:00:00' ], array_column( $response->get_data(), 'created_gmt' ) );
		$this->assertCount( 1, $this->request( 'GET', '/events', [ 'site' => 4701, 'since' => '2026-09-13T00:00:00' ] )->get_data() );
	}

	public function test_invalid_parameters_are_refused(): void {
		$this->login_as_super_admin();
		foreach ( [ [ 'type' => [ 'gone' ] ], [ 'per_page' => 101 ], [ 'site' => 0 ], [ 'since' => 'yesterday' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/events', $params )->get_status(), (string) wp_json_encode( $params ) );
		}
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/events' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
```

Dans `tests/php/Rest/ItemSchemasTest.php` :
- dans `set_up()`, après le `make_record` existant, ajouter un événement :

```php
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => get_current_network_id(),
					'site_id'    => 961,
					'type'       => 'alert_raised',
					'subject'    => 'no_users',
					'meta'       => [ 'severity' => 'error' ],
					'created_at' => '2026-09-01 00:00:00',
				],
			]
		);
```

- dans `routes()`, ajouter `'events' => [ '/events', true ],`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'EventsQueryTest|EventsControllerTest|ItemSchemasTest'`
Expected: FAIL, `Call to undefined method MultisiteRadar\Plugin::events_query()` et route `/events` absente (404).

- [ ] **Step 3: Create `EventsQuery`**

`includes/Query/EventsQuery.php` :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Le journal pour l'extérieur (REST, ability, récapitulatif) : site, nom du sujet et message, construits à la lecture
 * dans la langue du lecteur. Un site supprimé garde le nom et l'adresse notés dans l'événement.
 */
final class EventsQuery {

	private EventsRepository $events;
	private SitesRepository $sites;
	private RuleRegistry $rules;

	/**
	 * @var array<string, array{name: string, version: string}>|null Plugins installés, lus une fois.
	 */
	private ?array $plugins = null;

	public function __construct( EventsRepository $events, SitesRepository $sites, RuleRegistry $rules ) {
		$this->events = $events;
		$this->sites  = $sites;
		$this->rules  = $rules;
	}

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'since'    => null,
			'type'     => [],
			'site'     => 0,
		];
	}

	/**
	 * Événements du réseau courant, les plus récents d'abord.
	 *
	 * @param array $args page, per_page (≤ 100), since (date GMT « Y-m-d H:i:s » ou null), type (string[]), site (int, 0 : tous).
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args   = array_merge( self::defaults(), $args );
		$result = $this->events->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null === $args['since'] ? null : (string) $args['since'],
				'types'      => (array) $args['type'],
				'site_id'    => max( 0, (int) $args['site'] ),
				'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
				'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
			]
		);
		$records = $this->sites->find_many( array_values( array_unique( array_filter( array_column( $result['items'], 'site_id' ) ) ) ) );
		return [
			'items' => array_map(
				function ( array $event ) use ( $records ): array {
					return $this->format( $event, $records[ $event['site_id'] ] ?? null );
				},
				$result['items']
			),
			'total' => $result['total'],
		];
	}

	/**
	 * @return array<string, int> Type => nombre d'événements du réseau courant depuis $since (GMT).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function counts( string $since ): array {
		return $this->events->counts( get_current_network_id(), $since );
	}

	/**
	 * @param array $event Élément de EventsRepository::query().
	 * @return array{id: int, type: string, site: array|null, subject: string, label: string, message: string, created_gmt: string}
	 */
	public function format( array $event, ?SiteRecord $record ): array {
		$label = $this->label( $event );
		return [
			'id'          => (int) $event['id'],
			'type'        => (string) $event['type'],
			'site'        => $this->site( $event, $record ),
			'subject'     => (string) $event['subject'],
			'label'       => $label,
			'message'     => $this->message( $event, $label ),
			'created_gmt' => mysql_to_rfc3339( (string) $event['created_at'] ),
		];
	}

	/**
	 * Identité actuelle du site, celle notée dans l'événement s'il n'existe plus, null pour le réseau entier.
	 */
	private function site( array $event, ?SiteRecord $record ): ?array {
		$site_id = (int) $event['site_id'];
		if ( 0 === $site_id ) {
			return null;
		}
		if ( null !== $record ) {
			return SitesQuery::identity( $record );
		}
		$name   = PlainText::from_html( (string) ( $event['meta']['name'] ?? '' ) );
		$is_url = in_array( $event['type'], [ 'site_created', 'site_deleted' ], true ) && '' !== $event['subject'];
		return [
			'id'        => $site_id,
			/* translators: %d: site ID. */
			'name'      => '' !== trim( $name ) ? $name : sprintf( __( 'Site #%d', 'multisite-radar' ), $site_id ),
			'url'       => $is_url ? set_url_scheme( 'http://' . $event['subject'] ) : '',
			'admin_url' => '',
		];
	}

	/**
	 * Nom lisible du sujet : nom du plugin, du thème, libellé de la règle, nom du site.
	 */
	private function label( array $event ): string {
		$subject = (string) $event['subject'];
		switch ( $event['type'] ) {
			case 'plugin_activated':
			case 'plugin_deactivated':
				$this->plugins ??= PluginsQuery::installed();
				$name            = $this->plugins[ $subject ]['name'] ?? '';
				return '' !== $name ? $name : $subject;
			case 'theme_switched':
				return self::theme_name( $subject );
			case 'alert_raised':
			case 'alert_resolved':
				$rule = $this->rules->get( $subject );
				return null !== $rule ? $rule->label() : $subject;
			default:
				$name = PlainText::from_html( (string) ( $event['meta']['name'] ?? '' ) );
				return '' !== trim( $name ) ? $name : $subject;
		}
	}

	private function message( array $event, string $label ): string {
		$network = ! empty( $event['meta']['network'] );
		switch ( $event['type'] ) {
			case 'site_created':
				return __( 'Site created.', 'multisite-radar' );
			case 'site_deleted':
				return __( 'Site deleted.', 'multisite-radar' );
			case 'plugin_activated':
				if ( $network ) {
					/* translators: %s: plugin name. */
					return sprintf( __( 'Plugin %s network activated.', 'multisite-radar' ), $label );
				}
				/* translators: %s: plugin name. */
				return sprintf( __( 'Plugin %s activated.', 'multisite-radar' ), $label );
			case 'plugin_deactivated':
				if ( $network ) {
					/* translators: %s: plugin name. */
					return sprintf( __( 'Plugin %s network deactivated.', 'multisite-radar' ), $label );
				}
				/* translators: %s: plugin name. */
				return sprintf( __( 'Plugin %s deactivated.', 'multisite-radar' ), $label );
			case 'theme_switched':
				$from = self::theme_name( (string) ( $event['meta']['from'] ?? '' ) );
				if ( '' === $from ) {
					/* translators: %s: theme name. */
					return sprintf( __( 'Theme switched to %s.', 'multisite-radar' ), $label );
				}
				/* translators: 1: previous theme name, 2: new theme name. */
				return sprintf( __( 'Theme switched from %1$s to %2$s.', 'multisite-radar' ), $from, $label );
			case 'alert_raised':
				/* translators: %s: alert rule label. */
				return sprintf( __( 'New alert: %s.', 'multisite-radar' ), $label );
			case 'alert_resolved':
				/* translators: %s: alert rule label. */
				return sprintf( __( 'Alert resolved: %s.', 'multisite-radar' ), $label );
		}
		return $label;
	}

	private static function theme_name( string $stylesheet ): string {
		if ( '' === $stylesheet ) {
			return '';
		}
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() ? PlainText::from_html( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ) : $stylesheet;
	}
}
```

Dans `includes/Query/Schemas.php`, ajouter `use MultisiteRadar\Storage\EventsRepository;` et, après `alert()` :

```php
	/**
	 * Un événement du journal (EventsQuery::format()). Le site vaut null pour un événement du réseau entier.
	 */
	public static function event(): array {
		return self::object(
			[
				'id'          => self::type( 'integer' ),
				'type'        => self::enum( EventsRepository::TYPES ),
				'site'        => [
					'type'       => [ 'object', 'null' ],
					'properties' => self::identity(),
				],
				'subject'     => self::type( 'string' ),
				'label'       => self::type( 'string' ),
				'message'     => self::type( 'string' ),
				'created_gmt' => self::date(),
			]
		);
	}
```

- [ ] **Step 4: Create the route**

`includes/Rest/EventsController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Storage\EventsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /events : le journal des changements du réseau courant, les plus récents d'abord (spec §5.1).
 */
final class EventsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'events';

	private EventsQuery $events;

	public function __construct( EventsQuery $events ) {
		$this->events = $events;
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
	}

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
			'since'    => [
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Only changes since this date.', 'multisite-radar' ),
			],
			'type'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => EventsRepository::TYPES,
				],
			],
			'site'     => [
				'type'    => 'integer',
				'minimum' => 1,
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
				// Sans forcer l'UTC : un décalage explicite (+02:00) est converti, et non remplacé.
				$since  = isset( $request['since'] ) ? rest_parse_date( (string) $request['since'] ) : false;
				$result = $this->events->list(
					[
						'page'     => (int) $request['page'],
						'per_page' => $per_page,
						'since'    => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
						'type'     => (array) $request['type'],
						'site'     => isset( $request['site'] ) ? (int) $request['site'] : 0,
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-event', Schemas::event() );
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Query\EventsQuery;` et `use MultisiteRadar\Rest\EventsController;` (ordre alphabétique) ;
- propriété `private ?EventsQuery $events_query = null;` ;
- service :

```php
	public function events_query(): EventsQuery {
		return $this->events_query ??= new EventsQuery( $this->events(), $this->sites(), $this->rules() );
	}
```

- dans `register_rest_routes()`, ajouter `new EventsController( $this->events_query() ),` après la ligne d'`AlertRulesController`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'EventsQueryTest|EventsControllerTest|ItemSchemasTest'`
Expected: PASS.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Query/ includes/Rest/EventsController.php includes/Plugin.php tests/php/
git commit -m "feat: add GET /events with readable messages"
```

### Task 5: Tendances — `TrendsQuery` et `GET /reports/trends`

**Files:**
- Create: `includes/Query/TrendsQuery.php`, `includes/Rest/ReportsController.php`
- Modify: `includes/Query/Schemas.php`, `includes/Plugin.php`
- Test: `tests/php/Query/TrendsQueryTest.php`, `tests/php/Rest/ReportsControllerTest.php` (créés) ; `tests/php/Rest/ItemSchemasTest.php`

**Interfaces:**
- Consumes : `SnapshotsRepository::network_series()`, `::site_series()`, `::capture()` (tâche 1) ; `SitesRepository::find()` ; `Severity::name()`.
- Produces :
  - `Query\TrendsQuery::DEFAULT_DAYS = 90`, `MAX_DAYS = 3650` ; `__construct( SnapshotsRepository, SitesRepository )` ;
  - `network( int $days, ?int $now = null ): array{days, since, site: null, points}` ;
  - `site( int $site_id, int $days, ?int $now = null ): ?array` (null hors du réseau courant ; `alert_level` des points en nom de gravité) ;
  - `Query\Schemas::trends(): array` ;
  - `GET /multisite-radar/v1/reports/trends` (`days` 2–3650, 90 par défaut ; `site`), `msradar_view`, 404 `msradar_site_not_found` ;
  - `Rest\ReportsController::__construct( TrendsQuery $trends )` (la tâche 7 lui ajoute le récapitulatif) ;
  - `Plugin::trends_query(): TrendsQuery`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Query/TrendsQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class TrendsQueryTest extends TestCase {

	private const NOW = 1789560000; // 2026-09-16 12:00 UTC.

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			4801,
			[
				'name'          => 'Trend site',
				'scanned_at'    => '2026-09-01 00:00:00',
				'content_count' => 7,
				'alert_level'   => 2,
				'alerts_count'  => 1,
			]
		);
		$this->make_record(
			4802,
			[
				'network_id' => 2,
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$network = get_current_network_id();
		$this->plugin()->snapshots()->capture( $network, '2026-09-15' );
		$this->plugin()->snapshots()->capture( $network, '2026-09-16' );
		$this->plugin()->snapshots()->capture( $network, '2026-05-01' );
	}

	public function test_the_network_series_starts_days_before_today(): void {
		$trends = $this->plugin()->trends_query()->network( 2, self::NOW );

		$this->assertSame( 2, $trends['days'] );
		$this->assertSame( '2026-09-15', $trends['since'] );
		$this->assertNull( $trends['site'] );
		$this->assertSame( [ '2026-09-15', '2026-09-16' ], array_column( $trends['points'], 'day' ) );
		$this->assertGreaterThanOrEqual( 1, $trends['points'][1]['alerts_warning'] );
	}

	public function test_the_period_is_bounded(): void {
		$this->assertSame( 2, $this->plugin()->trends_query()->network( 1, self::NOW )['days'] );
		$this->assertSame( 3650, $this->plugin()->trends_query()->network( 99999, self::NOW )['days'] );
	}

	public function test_the_series_of_a_site_names_its_alert_level(): void {
		$trends = $this->plugin()->trends_query()->site( 4801, 30, self::NOW );

		$this->assertSame( 4801, $trends['site'] );
		$this->assertSame( [ '2026-09-15', '2026-09-16' ], array_column( $trends['points'], 'day' ) );
		$this->assertSame( 'warning', $trends['points'][0]['alert_level'] );
		$this->assertSame( 7, $trends['points'][0]['content_count'] );
	}

	public function test_a_site_outside_the_network_has_no_series(): void {
		$this->assertNull( $this->plugin()->trends_query()->site( 4802, 30, self::NOW ) );
		$this->assertNull( $this->plugin()->trends_query()->site( 999999, 30, self::NOW ) );
	}
}
```

Créer `tests/php/Rest/ReportsControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ReportsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			4901,
			[
				'name'       => 'Reported',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->make_record(
			4902,
			[
				'network_id' => 2,
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->plugin()->snapshots()->capture( get_current_network_id(), gmdate( 'Y-m-d' ) );
	}

	public function test_trends_of_the_network_and_of_a_site_for_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/reports/trends' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/reports/trends' )->get_status() );

		$this->login_as_super_admin();
		$network = $this->request( 'GET', '/reports/trends' );
		$this->assertSame( 200, $network->get_status() );
		$this->assertSame( 90, $network->get_data()['days'] );
		$this->assertSame( [ gmdate( 'Y-m-d' ) ], array_column( $network->get_data()['points'], 'day' ) );

		$site = $this->request(
			'GET',
			'/reports/trends',
			[
				'site' => 4901,
				'days' => 7,
			]
		);
		$this->assertSame( 200, $site->get_status() );
		$this->assertSame( 4901, $site->get_data()['site'] );

		$this->assertSame( 404, $this->request( 'GET', '/reports/trends', [ 'site' => 4902 ] )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/reports/trends', [ 'days' => 1 ] )->get_status() );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_snapshots' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/reports/trends' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
```

Dans `tests/php/Rest/ItemSchemasTest.php`, dans `routes()`, ajouter `'trends' => [ '/reports/trends', false ],`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'TrendsQueryTest|ReportsControllerTest|ItemSchemasTest'`
Expected: FAIL, `Call to undefined method MultisiteRadar\Plugin::trends_query()` et route absente.

- [ ] **Step 3: Create `TrendsQuery`, the schema and the route**

`includes/Query/TrendsQuery.php` :

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Storage\SnapshotsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Séries temporelles des instantanés (spec §7.3) : réseau courant, ou un site de ce réseau. Un point par jour
 * d'instantané, sans jours comblés (écart E9).
 */
final class TrendsQuery {

	public const DEFAULT_DAYS = 90;
	public const MAX_DAYS     = 3650;

	private SnapshotsRepository $snapshots;
	private SitesRepository $sites;

	public function __construct( SnapshotsRepository $snapshots, SitesRepository $sites ) {
		$this->snapshots = $snapshots;
		$this->sites     = $sites;
	}

	/**
	 * @return array{days: int, since: string, site: null, points: array[]}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function network( int $days, ?int $now = null ): array {
		$days  = self::bounded( $days );
		$since = self::since( $days, $now );
		return [
			'days'   => $days,
			'since'  => $since,
			'site'   => null,
			'points' => $this->snapshots->network_series( get_current_network_id(), $since ),
		];
	}

	/**
	 * @return array{days: int, since: string, site: int, points: array[]}|null Null si le site n'appartient pas au réseau courant.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function site( int $site_id, int $days, ?int $now = null ): ?array {
		$record = $this->sites->find( $site_id );
		if ( null === $record || get_current_network_id() !== $record->network_id ) {
			return null;
		}
		$days  = self::bounded( $days );
		$since = self::since( $days, $now );
		return [
			'days'   => $days,
			'since'  => $since,
			'site'   => $site_id,
			'points' => array_map(
				static function ( array $point ): array {
					$point['alert_level'] = Severity::name( $point['alert_level'] );
					return $point;
				},
				$this->snapshots->site_series( $site_id, $since )
			),
		];
	}

	private static function bounded( int $days ): int {
		return min( self::MAX_DAYS, max( 2, $days ) );
	}

	/**
	 * Premier jour UTC de la période : aujourd'hui compte pour un jour.
	 */
	private static function since( int $days, ?int $now ): string {
		return gmdate( 'Y-m-d', ( $now ?? time() ) - ( $days - 1 ) * DAY_IN_SECONDS );
	}
}
```

Dans `includes/Query/Schemas.php`, ajouter après `scan_status()` :

```php
	/**
	 * Séries de TrendsQuery : points du réseau, ou d'un site si « site » n'est pas null.
	 */
	public static function trends(): array {
		$count = self::type( 'integer' );
		return self::object(
			[
				'days'   => $count,
				'since'  => self::type( 'string' ),
				'site'   => self::type( [ 'integer', 'null' ] ),
				'points' => self::list_of(
					[
						'anyOf' => [
							self::object(
								[
									'day'            => self::type( 'string' ),
									'sites'          => $count,
									'content_count'  => $count,
									'media_count'    => $count,
									'alerts_error'   => $count,
									'alerts_warning' => $count,
									'alerts_info'    => $count,
								]
							),
							self::object(
								[
									'day'           => self::type( 'string' ),
									'users_count'   => $count,
									'content_count' => $count,
									'media_count'   => $count,
									'disk_bytes'    => self::type( [ 'integer', 'null' ] ),
									'db_bytes'      => self::type( [ 'integer', 'null' ] ),
									'alert_level'   => self::enum( Severity::names() ),
									'alerts_count'  => $count,
								]
							),
						],
					]
				),
			]
		);
	}
```

`ItemSchemasTest` compare les clés de l'objet renvoyé (`days`, `since`, `site`, `points`) avec les propriétés du schéma : elles concordent.

`includes/Rest/ReportsController.php` :

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\TrendsQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reports/trends : séries du réseau ou d'un site (spec §5.1, écart E9).
 */
final class ReportsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports';

	private TrendsQuery $trends;

	public function __construct( TrendsQuery $trends ) {
		$this->trends = $trends;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trends',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_trends' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'days' => [
							'type'    => 'integer',
							'default' => TrendsQuery::DEFAULT_DAYS,
							'minimum' => 2,
							'maximum' => TrendsQuery::MAX_DAYS,
						],
						'site' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_trends_schema' ],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_trends( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$days = (int) $request['days'];
				if ( isset( $request['site'] ) ) {
					$trends = $this->trends->site( (int) $request['site'], $days );
					if ( null === $trends ) {
						return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
					}
					return new WP_REST_Response( $trends );
				}
				return new WP_REST_Response( $this->trends->network( $days ) );
			}
		);
	}

	public function get_trends_schema(): array {
		return Schemas::for_rest( 'msradar-trends', Schemas::trends() );
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Query\TrendsQuery;` et `use MultisiteRadar\Rest\ReportsController;` (ordre alphabétique) ;
- propriété `private ?TrendsQuery $trends_query = null;` ;
- service :

```php
	public function trends_query(): TrendsQuery {
		return $this->trends_query ??= new TrendsQuery( $this->snapshots(), $this->sites() );
	}
```

- dans `register_rest_routes()`, ajouter `new ReportsController( $this->trends_query() ),` après `EventsController`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter 'TrendsQueryTest|ReportsControllerTest|ItemSchemasTest'`
Expected: PASS.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 5: Commit**

```bash
git add includes/Query/ includes/Rest/ReportsController.php includes/Plugin.php tests/php/
git commit -m "feat: add GET /reports/trends for the network and for one site"
```

### Task 6: Ability `multisite-radar/recent-changes`

**Files:**
- Create: `includes/Abilities/RecentChangesAbility.php`
- Modify: `includes/Plugin.php`, `tests/php/Abilities/RegistrarTest.php`, `bin/e2e.sh`
- Test: `tests/php/Abilities/RecentChangesAbilityTest.php` (créé) ; `bin/e2e.sh`

**Interfaces:**
- Consumes :
  - `EventsQuery::list()`, `Query\Schemas::event()`, `::page_of()`, `EventsRepository::TYPES` ;
  - helpers protégés de `Abilities\Ability` : `input()`, `paging()`, `page_number()`, `page_size()`, `page()`, `strings()` (qui accepte une liste ou un texte séparé par des virgules) ;
  - `Registrar` et `Plugin::abilities()` (M5).
- Produces :
  - ability `multisite-radar/recent-changes` : entrée `{ since, type, site, page, per_page }`, sortie `Schemas::page_of( Schemas::event() )` ;
  - `RegistrarTest::NAMES` compte 6 abilities.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Abilities/RegistrarTest.php`, ajouter `'multisite-radar/recent-changes',` à la fin de `NAMES`.

Créer `tests/php/Abilities/RecentChangesAbilityTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class RecentChangesAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->make_record(
			5001,
			[
				'name'       => 'Changing site',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$event = static function ( string $type, string $subject, string $created_at ): array {
			return [
				'network_id' => get_current_network_id(),
				'site_id'    => 5001,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => [],
				'created_at' => $created_at,
			];
		};
		$this->plugin()->events()->insert(
			[
				$event( 'site_created', 'example.org/changing/', '2026-08-01 00:00:00' ),
				$event( 'alert_raised', 'no_users', '2026-09-10 00:00:00' ),
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function changes( $input ) {
		$ability = wp_get_ability( 'multisite-radar/recent-changes' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_the_changes_of_a_site_newest_first(): void {
		$result = $this->changes( [ 'site' => 5001 ] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'alert_raised', 'site_created' ], array_column( $result['items'], 'type' ) );
		$this->assertSame( 'Changing site', $result['items'][0]['site']['name'] );
	}

	public function test_filters_by_date_and_kind_sent_as_text(): void {
		$this->assertSame( 1, $this->changes( [ 'site' => '5001', 'since' => '2026-09-01T00:00:00' ] )['total'] );
		$this->assertSame( [ 'site_created' ], array_column( $this->changes( [ 'site' => 5001, 'type' => 'site_created' ] )['items'], 'type' ) );
	}

	public function test_invalid_input_and_missing_capability_are_refused(): void {
		$this->assertSame( 'ability_invalid_input', $this->changes( [ 'type' => [ 'gone' ] ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->changes( [ 'since' => 'yesterday' ] )->get_error_code() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'ability_invalid_permissions', $this->changes( [] )->get_error_code() );
	}
}
```

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"` :

```bash
CHANGES="$(wpe --user=admin eval '$response = rest_do_request( new WP_REST_Request( "GET", "/wp-abilities/v1/abilities/multisite-radar/recent-changes/run" ) ); echo wp_json_encode( [ "status" => $response->get_status(), "data" => $response->get_data() ] );')"
expect "the recent-changes ability lists the creation of the hidden site" \
	"$(jq -r --arg id "$HIDDEN_ID" '"\(.status) \(.data.items | map(select(.type == "site_created" and (.site.id | tostring) == $id)) | length)"' <<<"$CHANGES")" "200 1"
```

Le site « Discret » (`$HIDDEN_ID`) est créé par `bin/e2e.sh` après l'activation de Multisite Radar : sa création est notée par le hook `wp_initialize_site` (tâche 2).

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'RecentChangesAbilityTest|RegistrarTest'`
Expected: FAIL, `wp_get_ability( 'multisite-radar/recent-changes' )` renvoie `null` ; `RegistrarTest` attend six noms.

- [ ] **Step 3: Create the ability**

`includes/Abilities/RecentChangesAbility.php` :

```php
<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Storage\EventsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/recent-changes : le journal des changements du réseau (spec §5.4), les plus récents d'abord.
 */
final class RecentChangesAbility extends Ability {

	private EventsQuery $events;

	public function __construct( EventsQuery $events ) {
		$this->events = $events;
	}

	public function slug(): string {
		return 'recent-changes';
	}

	public function label(): string {
		return __( 'Recent changes', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the recent changes of the network, newest first: sites created or deleted, plugins activated or deactivated (on a site or on the whole network), themes switched, alerts raised or resolved. Each change has its date (UTC), its site (null for the whole network) and a readable message. Filter by date, kind of change or site.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'since' => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Only changes since this date and time, in UTC, e.g. 2026-09-01T00:00:00.', 'multisite-radar' ),
					],
					'type'  => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => EventsRepository::TYPES,
						],
						'description' => __( 'Only these kinds of changes.', 'multisite-radar' ),
					],
					'site'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Only the changes of this site ID.', 'multisite-radar' ),
					],
				],
				self::paging()
			)
		);
	}

	public function output_schema(): array {
		return Schemas::page_of( Schemas::event() );
	}

	/**
	 * @return array
	 */
	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$since    = isset( $input['since'] ) ? rest_parse_date( (string) $input['since'] ) : false;
		$result   = $this->events->list(
			[
				'page'     => $page,
				'per_page' => $per_page,
				'since'    => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
				'type'     => self::strings( $input['type'] ?? [] ),
				'site'     => (int) ( $input['site'] ?? 0 ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Abilities\RecentChangesAbility;` (ordre alphabétique) ;
- dans `abilities()`, ajouter en fin de liste `new RecentChangesAbility( $this->events_query() ),`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter 'Abilities'`
Expected: PASS.

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, puis `E2E OK`.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 5: Commit**

```bash
git add includes/Abilities/RecentChangesAbility.php includes/Plugin.php tests/php/Abilities/ bin/e2e.sh
git commit -m "feat: register the recent-changes ability"
```

## Partie C — Rapports

### Task 7: Récapitulatif hebdomadaire par e-mail et envoi de test

**Files:**
- Create: `includes/Reports/Digest.php`
- Modify: `includes/Rest/ReportsController.php`, `includes/Admin/Privacy.php`, `includes/Plugin.php`
- Test: `tests/php/Reports/DigestTest.php` (créé) ; `tests/php/Rest/ReportsControllerTest.php`, `tests/php/Admin/PrivacyTest.php`

**Interfaces:**
- Consumes :
  - `EventsQuery::list()`, `::counts()` (tâche 4) ;
  - `AlertsQuery::summary( int $network_id )` ;
  - réglages `reports.digest_enabled` (bool), `reports.digest_day` (0–6), `reports.digest_recipients.mode` (`super_admins` | `custom`), `reports.digest_recipients.emails` (string[]) ;
  - `Menu::url( 'overview' )`, `Queue::HOOK_DAILY`, `PlainText::from_html()`.
- Produces :
  - `Reports\Digest` :
    - `SENT_OPTION = 'msradar_digest_sent'` ;
    - `__construct( Settings, EventsQuery, AlertsQuery )` ;
    - `register(): void` (`msradar_daily`, priorité 30) ;
    - `maybe_send( $now = null ): void` ;
    - `recipients(): string[]` ;
    - `compose( int $now ): array{subject, html, text}` ;
    - `send( array $recipients, ?int $now = null ): bool` ;
  - `POST /multisite-radar/v1/reports/digest/test` (`msradar_manage`) : `{ sent: true }`, 400 `msradar_no_email`, 500 `msradar_mail_failed` ;
  - `Rest\ReportsController::__construct( TrendsQuery $trends, Digest $digest )` ;
  - `Plugin::digest(): Digest`, enregistré dans `boot()`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Reports/DigestTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Reports;

use MultisiteRadar\Reports\Digest;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class DigestTest extends TestCase {

	private const NOW = 1789560000; // Mercredi 16 septembre 2026, 12:00 UTC (fuseau des tests : UTC).

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		delete_site_option( Digest::SENT_OPTION );
		$this->make_record(
			5101,
			[
				'name'         => 'R&amp;D <script>alert(1)</script>',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$event = static function ( string $type, string $subject, string $created_at ): array {
			return [
				'network_id' => get_current_network_id(),
				'site_id'    => 5101,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => [],
				'created_at' => $created_at,
			];
		};
		$this->plugin()->events()->insert(
			[
				$event( 'alert_raised', 'no_users', '2026-09-15 08:00:00' ),
				$event( 'alert_resolved', 'inactive', '2026-09-14 08:00:00' ),
				$event( 'plugin_activated', 'akismet/akismet.php', '2026-09-13 08:00:00' ),
				$event( 'alert_raised', 'search_hidden', '2026-08-01 08:00:00' ),
			]
		);
	}

	private function enable( array $recipients = [] ): void {
		$this->plugin()->settings()->update(
			[
				'reports' => [
					'digest_enabled'    => true,
					'digest_day'        => 3,
					'digest_recipients' => [] !== $recipients ? $recipients : [
						'mode'   => 'custom',
						'emails' => [ 'one@example.org', 'two@example.org' ],
					],
				],
			]
		);
	}

	/**
	 * @return array[]
	 */
	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_nothing_is_sent_while_the_digest_is_off_or_on_another_day(): void {
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->assertSame( [], $this->sent() );

		$this->enable();
		$this->plugin()->digest()->maybe_send( self::NOW + DAY_IN_SECONDS );
		$this->assertSame( [], $this->sent() );
	}

	public function test_each_recipient_gets_its_own_e_mail_once_on_the_chosen_day(): void {
		$this->enable();
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->plugin()->digest()->maybe_send( self::NOW + HOUR_IN_SECONDS );

		$sent = $this->sent();
		$this->assertCount( 2, $sent, 'One e-mail per recipient, and not twice the same day.' );
		$this->assertSame( 'one@example.org', $sent[0]['to'][0][0] );
		$this->assertSame( 'two@example.org', $sent[1]['to'][0][0] );
		$this->assertCount( 1, $sent[0]['to'], 'A recipient never sees the other addresses.' );
		$this->assertSame( '2026-09-16', get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_the_content_lists_the_alerts_of_the_week_with_plain_escaped_names(): void {
		$mail = $this->plugin()->digest()->compose( self::NOW );

		$this->assertStringContainsString( 'Multisite Radar', $mail['subject'] );
		$this->assertStringContainsString( 'R&amp;D &lt;script&gt;', $mail['html'] );
		$this->assertStringNotContainsString( '<script>', $mail['html'] );
		$this->assertStringContainsString( 'Site without users', $mail['html'] );
		$this->assertStringContainsString( 'Inactive site', $mail['html'] );
		$this->assertStringNotContainsString( 'Hidden from search engines', $mail['html'], 'Older than seven days.' );
		$this->assertStringContainsString( 'page=multisite-radar', $mail['html'] );
		$this->assertStringContainsString( 'R&D <script>alert(1)</script>', $mail['text'] );
		$this->assertStringContainsString( '1 plugin activated', $mail['text'] );
	}

	public function test_the_text_alternative_is_attached(): void {
		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [ 'one@example.org' ],
			]
		);
		$this->plugin()->digest()->maybe_send( self::NOW );

		$this->assertStringContainsString( 'Open Multisite Radar', tests_retrieve_phpmailer_instance()->AltBody );
		$this->assertStringContainsString( 'multipart/alternative', $this->sent()[0]['header'] );
	}

	public function test_super_admins_by_default_and_no_valid_address_means_no_mail(): void {
		$admin = self::factory()->user->create( [ 'user_email' => 'chief@example.org' ] );
		grant_super_admin( $admin );
		$this->enable(
			[
				'mode'   => 'super_admins',
				'emails' => [],
			]
		);
		$this->assertContains( 'chief@example.org', $this->plugin()->digest()->recipients() );

		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [],
			]
		);
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->assertSame( [], $this->sent() );
		$this->assertFalse( get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_a_failed_send_is_not_recorded_as_sent(): void {
		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [ 'one@example.org' ],
			]
		);
		add_filter( 'pre_wp_mail', '__return_false' );
		try {
			$this->plugin()->digest()->maybe_send( self::NOW );
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
		}

		$this->assertFalse( get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_a_storage_failure_is_reported_and_never_reaches_the_daily_task(): void {
		global $wpdb;
		$this->enable();
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->digest()->maybe_send( self::NOW );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ Digest::class . '::maybe_send' ], $reported );
		$this->assertSame( [], $this->sent() );
	}

	public function test_it_runs_on_the_daily_task_after_the_history(): void {
		$this->assertSame( 30, has_action( Queue::HOOK_DAILY, [ $this->plugin()->digest(), 'maybe_send' ] ) );
	}
}
```

Les libellés `Site without users`, `Inactive site` et `Hidden from search engines` sont ceux des règles `no_users`, `inactive` et `search_hidden` : les vérifier dans `includes/Alerts/Rules/` et ajuster le test si l'un diffère.

Dans `tests/php/Rest/ReportsControllerTest.php`, ajouter :

```php
	public function test_a_test_digest_goes_to_the_current_user_only(): void {
		reset_phpmailer_instance();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/reports/digest/test' )->get_status() );

		$user = self::factory()->user->create( [ 'user_email' => 'tester@example.org' ] );
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$response = $this->request( 'POST', '/reports/digest/test' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'sent' => true ], $response->get_data() );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'tester@example.org', $sent[0]['to'][0][0] );
	}
```

Dans `tests/php/Admin/PrivacyTest.php`, à côté de l'assertion sur `logins`, ajouter `$this->assertStringContainsString( 'recipients', $texts['Multisite Radar'] );`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/test.sh --filter 'DigestTest|ReportsControllerTest|PrivacyTest'`
Expected: FAIL, `Class "MultisiteRadar\Reports\Digest" not found`, route `/reports/digest/test` absente, texte de confidentialité sans « recipients ».

- [ ] **Step 3: Create `Digest`**

`includes/Reports/Digest.php` :

```php
<?php
namespace MultisiteRadar\Reports;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Récapitulatif hebdomadaire par e-mail (spec §7.3, écart E7) : alertes nouvelles et résolues des 7 derniers jours,
 * résumé des changements, lien vers la Vue d'ensemble. HTML avec une alternative texte ; un e-mail par destinataire.
 */
final class Digest {

	public const SENT_OPTION = 'msradar_digest_sent';

	private const DAYS       = 7;
	private const MAX_ALERTS = 20;

	private Settings $settings;
	private EventsQuery $events;
	private AlertsQuery $alerts;

	public function __construct( Settings $settings, EventsQuery $events, AlertsQuery $alerts ) {
		$this->settings = $settings;
		$this->events   = $events;
		$this->alerts   = $alerts;
	}

	public function register(): void {
		// Après History::daily() (priorité 20), qui a purgé les événements trop anciens.
		add_action( Queue::HOOK_DAILY, [ $this, 'maybe_send' ], 30 );
	}

	/**
	 * Sur la tâche quotidienne : le jour réglé (fuseau du site principal), une seule fois ce jour-là. Non typé :
	 * WordPress appelle les hooks avec un argument vide.
	 *
	 * @param mixed $now Horodatage Unix (tests) ; maintenant sinon.
	 */
	public function maybe_send( $now = null ): void {
		$now = is_int( $now ) ? $now : time();
		if ( ! (bool) $this->settings->get( 'reports.digest_enabled', false ) ) {
			return;
		}
		if ( (int) wp_date( 'w', $now ) !== (int) $this->settings->get( 'reports.digest_day', 1 ) ) {
			return;
		}
		$today = (string) wp_date( 'Y-m-d', $now );
		if ( get_site_option( self::SENT_OPTION ) === $today ) {
			return;
		}
		$recipients = $this->recipients();
		if ( [] === $recipients ) {
			return;
		}
		try {
			$sent = $this->send( $recipients, $now );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return;
		}
		if ( $sent ) {
			update_site_option( self::SENT_OPTION, $today );
		}
	}

	/**
	 * @return string[] Adresses valides, sans doublon : celles des super-admins, ou la liste saisie.
	 */
	public function recipients(): array {
		$emails = [];
		if ( 'custom' === $this->settings->get( 'reports.digest_recipients.mode', 'super_admins' ) ) {
			$emails = (array) $this->settings->get( 'reports.digest_recipients.emails', [] );
		} else {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( false !== $user ) {
					$emails[] = $user->user_email;
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $emails ), 'is_email' ) ) );
	}

	/**
	 * Un e-mail par destinataire : aucun ne voit les adresses des autres.
	 *
	 * @param string[] $recipients Adresses.
	 * @return bool Vrai si au moins un e-mail est parti.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function send( array $recipients, ?int $now = null ): bool {
		$mail = $this->compose( $now ?? time() );
		$alt  = static function ( $phpmailer ) use ( $mail ): void {
			$phpmailer->AltBody = $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};
		$sent = false;
		add_action( 'phpmailer_init', $alt );
		try {
			foreach ( $recipients as $recipient ) {
				$sent = wp_mail( $recipient, $mail['subject'], $mail['html'], [ 'Content-Type: text/html; charset=UTF-8' ] ) || $sent;
			}
		} finally {
			remove_action( 'phpmailer_init', $alt );
		}
		return $sent;
	}

	/**
	 * @return array{subject: string, html: string, text: string}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function compose( int $now ): array {
		$since    = gmdate( 'Y-m-d H:i:s', $now - self::DAYS * DAY_IN_SECONDS );
		$summary  = $this->alerts->summary( get_current_network_id() );
		$raised   = $this->events->list(
			[
				'since'    => $since,
				'type'     => [ 'alert_raised' ],
				'per_page' => self::MAX_ALERTS,
			]
		);
		$resolved = $this->events->list(
			[
				'since'    => $since,
				'type'     => [ 'alert_resolved' ],
				'per_page' => self::MAX_ALERTS,
			]
		);
		$network  = get_network();
		$name     = PlainText::from_html( null !== $network ? (string) $network->site_name : '' );

		/* translators: %s: network name. */
		$title    = sprintf( __( 'Multisite Radar summary for %s', 'multisite-radar' ), $name );
		$sections = [
			[
				'title' => __( 'Network', 'multisite-radar' ),
				'lines' => [
					self::figure( __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'] ),
					self::figure( __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'] ),
					self::figure( __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'] ),
				],
			],
			self::alerts_section(
				/* translators: %s: number of new alerts. */
				_n( '%s new alert', '%s new alerts', $raised['total'], 'multisite-radar' ),
				$raised
			),
			self::alerts_section(
				/* translators: %s: number of resolved alerts. */
				_n( '%s resolved alert', '%s resolved alerts', $resolved['total'], 'multisite-radar' ),
				$resolved
			),
			[
				'title' => __( 'Other changes', 'multisite-radar' ),
				'lines' => self::change_lines( $this->events->counts( $since ) ),
			],
		];

		return [
			/* translators: %s: network name. */
			'subject' => sprintf( __( '[%s] Weekly summary from Multisite Radar', 'multisite-radar' ), $name ),
			'html'    => self::html( $title, $sections, Menu::url( 'overview' ) ),
			'text'    => self::text( $title, $sections, Menu::url( 'overview' ) ),
		];
	}

	private static function figure( string $label, int $value ): string {
		/* translators: 1: label, 2: number. */
		return sprintf( __( '%1$s: %2$s', 'multisite-radar' ), $label, number_format_i18n( $value ) );
	}

	/**
	 * @param string                            $heading Titre au singulier ou au pluriel, avec %s pour le nombre.
	 * @param array{items: array[], total: int} $result  Résultat de EventsQuery::list().
	 * @return array{title: string, lines: string[]}
	 */
	private static function alerts_section( string $heading, array $result ): array {
		$lines = [];
		foreach ( $result['items'] as $item ) {
			$site    = null !== $item['site'] ? $item['site']['name'] : __( 'Network', 'multisite-radar' );
			$lines[] = $site . ' — ' . $item['label'];
		}
		$more = $result['total'] - count( $result['items'] );
		if ( $more > 0 ) {
			/* translators: %s: number of further alerts. */
			$lines[] = sprintf( _n( 'and %s more', 'and %s more', $more, 'multisite-radar' ), number_format_i18n( $more ) );
		}
		return [
			'title' => sprintf( $heading, number_format_i18n( $result['total'] ) ),
			'lines' => $lines,
		];
	}

	/**
	 * @param array<string, int> $counts Type => nombre.
	 * @return string[]
	 */
	private static function change_lines( array $counts ): array {
		$lines  = [];
		$labels = [
			/* translators: %s: number of sites. */
			'site_created'       => _n_noop( '%s site created', '%s sites created', 'multisite-radar' ),
			/* translators: %s: number of sites. */
			'site_deleted'       => _n_noop( '%s site deleted', '%s sites deleted', 'multisite-radar' ),
			/* translators: %s: number of plugins. */
			'plugin_activated'   => _n_noop( '%s plugin activated', '%s plugins activated', 'multisite-radar' ),
			/* translators: %s: number of plugins. */
			'plugin_deactivated' => _n_noop( '%s plugin deactivated', '%s plugins deactivated', 'multisite-radar' ),
			/* translators: %s: number of themes. */
			'theme_switched'     => _n_noop( '%s theme switched', '%s themes switched', 'multisite-radar' ),
		];
		foreach ( $labels as $type => $noop ) {
			$count = (int) ( $counts[ $type ] ?? 0 );
			if ( $count > 0 ) {
				$lines[] = sprintf( translate_nooped_plural( $noop, $count, 'multisite-radar' ), number_format_i18n( $count ) );
			}
		}
		return [] !== $lines ? $lines : [ __( 'No other change.', 'multisite-radar' ) ];
	}

	/**
	 * @param array<int, array{title: string, lines: string[]}> $sections
	 */
	private static function html( string $title, array $sections, string $url ): string {
		$html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #1e1e1e; max-width: 640px;">';
		$html .= '<h1 style="font-size: 20px;">' . esc_html( $title ) . '</h1>';
		$html .= '<p>' . esc_html(
			/* translators: %d: number of days. */
			sprintf( _n( 'Changes of the last %d day.', 'Changes of the last %d days.', self::DAYS, 'multisite-radar' ), self::DAYS )
		) . '</p>';
		foreach ( $sections as $section ) {
			$html .= '<h2 style="font-size: 16px; margin-top: 24px;">' . esc_html( $section['title'] ) . '</h2>';
			if ( [] !== $section['lines'] ) {
				$html .= '<ul>';
				foreach ( $section['lines'] as $line ) {
					$html .= '<li>' . esc_html( $line ) . '</li>';
				}
				$html .= '</ul>';
			}
		}
		$html .= '<p style="margin-top: 24px;"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Multisite Radar', 'multisite-radar' ) . '</a></p>';
		return $html . '</div>';
	}

	/**
	 * @param array<int, array{title: string, lines: string[]}> $sections
	 */
	private static function text( string $title, array $sections, string $url ): string {
		/* translators: %d: number of days. */
		$text = $title . "\n\n" . sprintf( _n( 'Changes of the last %d day.', 'Changes of the last %d days.', self::DAYS, 'multisite-radar' ), self::DAYS ) . "\n";
		foreach ( $sections as $section ) {
			$text .= "\n" . $section['title'] . "\n";
			foreach ( $section['lines'] as $line ) {
				$text .= '- ' . $line . "\n";
			}
		}
		/* translators: %s: address of the Multisite Radar overview page. */
		return $text . "\n" . sprintf( __( 'Open Multisite Radar: %s', 'multisite-radar' ), $url ) . "\n";
	}
}
```

WPCS exige un commentaire `translators:` juste avant chaque `_n_noop()` qui contient un marqueur, comme ci-dessus. Si `composer lint` ne le reconnaît pas dans un tableau, sortir chaque `_n_noop()` dans une variable précédée de son commentaire.

- [ ] **Step 4: Add the test route, the privacy text and the wiring**

`includes/Rest/ReportsController.php` :
- `use MultisiteRadar\Reports\Digest;` ; propriété `private Digest $digest;` ; constructeur `__construct( TrendsQuery $trends, Digest $digest )` ;
- dans `register_routes()`, ajouter :

```php
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/digest/test',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send_test_digest' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);
```

- ajouter :

```php
	/**
	 * Envoie tout de suite le récapitulatif au seul utilisateur courant (écart E7). La réponse ne contient pas d'adresse.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_digest() {
		return $this->guard(
			function () {
				$email = (string) wp_get_current_user()->user_email;
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'msradar_no_email', __( 'Your account has no valid e-mail address.', 'multisite-radar' ), [ 'status' => 400 ] );
				}
				if ( ! $this->digest->send( [ $email ] ) ) {
					return new WP_Error( 'msradar_mail_failed', __( 'The e-mail could not be sent. Check the e-mail settings of the server.', 'multisite-radar' ), [ 'status' => 500 ] );
				}
				return new WP_REST_Response( [ 'sent' => true ] );
			}
		);
	}
```

`includes/Admin/Privacy.php`, `text()` : ajouter à la fin de la chaîne existante (même `__()`) :

```
 It also keeps a history of the changes of the network (sites created or deleted, plugins and themes, alerts) and daily figures of each site, for the retention period set in its settings. If the weekly e-mail summary is enabled, the addresses of its recipients are kept in the plugin settings and used only to send it.
```

`includes/Plugin.php` :
- `use MultisiteRadar\Reports\Digest;` ; propriété `private ?Digest $digest = null;` ;
- service :

```php
	public function digest(): Digest {
		return $this->digest ??= new Digest( $this->settings(), $this->events_query(), $this->alerts_query() );
	}
```

- dans `boot()`, après `$this->history()->register();`, ajouter `$this->digest()->register();` ;
- dans `register_rest_routes()`, `new ReportsController( $this->trends_query(), $this->digest() ),`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/test.sh --filter 'DigestTest|ReportsControllerTest|PrivacyTest'`
Expected: PASS.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 6: Commit**

```bash
git add includes/Reports/Digest.php includes/Rest/ReportsController.php includes/Admin/Privacy.php includes/Plugin.php tests/php/
git commit -m "feat: send the weekly e-mail summary and a test e-mail on request"
```

### Task 8: Widget du tableau de bord réseau

**Files:**
- Create: `includes/Reports/DashboardWidget.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Reports/DashboardWidgetTest.php` (créé)

**Interfaces:**
- Consumes : `AlertsQuery::summary()`, `AlertsQuery::list()` (`orderby` `severity`, `order` `desc`, `per_page` 5), `InventoryQuery::summary()` (`plugins.unused`, `plugins.updates`, `themes.updates`), `Menu::url()`, `Capabilities::VIEW`.
- Produces : `Reports\DashboardWidget::ID = 'msradar_summary'`, `__construct( AlertsQuery, InventoryQuery )`, `register(): void` (`wp_network_dashboard_setup`), `add(): void`, `render(): void` ; `Plugin::dashboard_widget(): DashboardWidget`, enregistré dans `boot()` (administration seulement).

- [ ] **Step 1: Write the failing test**

Créer `tests/php/Reports/DashboardWidgetTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Reports;

use MultisiteRadar\Reports\DashboardWidget;
use MultisiteRadar\Tests\TestCase;

final class DashboardWidgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard-network' );
		$this->make_record(
			5201,
			[
				'name'         => 'R&amp;D <script>alert(1)</script>',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
	}

	public function tear_down(): void {
		$GLOBALS['wp_meta_boxes'] = [];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		$this->plugin()->dashboard_widget()->render();
		return (string) ob_get_clean();
	}

	public function test_the_widget_is_offered_to_users_with_the_view_capability_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->plugin()->dashboard_widget()->add();
		$this->assertFalse( isset( $GLOBALS['wp_meta_boxes']['dashboard-network']['normal']['core'][ DashboardWidget::ID ] ) );

		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->plugin()->dashboard_widget()->add();
		$this->assertTrue( isset( $GLOBALS['wp_meta_boxes']['dashboard-network']['normal']['core'][ DashboardWidget::ID ] ) );
	}

	public function test_it_shows_the_key_figures_and_the_main_alerts_escaped(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Sites with an error', $html );
		$this->assertStringContainsString( 'R&amp;D &lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'site=5201', $html );
		$this->assertStringContainsString( 'page=multisite-radar', $html );
	}

	public function test_a_failed_read_shows_a_message_instead_of_breaking_the_dashboard(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$html = $this->render();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertStringContainsString( 'could not read its data', $html );
	}

	public function test_it_is_hooked_on_the_network_dashboard(): void {
		$this->assertSame( 10, has_action( 'wp_network_dashboard_setup', [ $this->plugin()->dashboard_widget(), 'add' ] ) );
	}
}
```

Le test d'enregistrement suppose que `boot()` a branché le widget : en PHPUnit, `is_admin()` est faux au démarrage du plugin. `Plugin::boot()` enregistre donc le widget **hors** du bloc `is_admin()` (le hook `wp_network_dashboard_setup` n'existe que dans l'administration réseau : l'enregistrer partout ne coûte rien).

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/test.sh --filter DashboardWidgetTest`
Expected: FAIL with `Call to undefined method MultisiteRadar\Plugin::dashboard_widget()`.

- [ ] **Step 3: Create the widget**

`includes/Reports/DashboardWidget.php` :

```php
<?php
namespace MultisiteRadar\Reports;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Widget du tableau de bord réseau (spec §7.3), rendu en PHP : chiffres clés et cinq alertes principales.
 */
final class DashboardWidget {

	public const ID = 'msradar_summary';

	private const TOP = 5;

	private AlertsQuery $alerts;
	private InventoryQuery $inventory;

	public function __construct( AlertsQuery $alerts, InventoryQuery $inventory ) {
		$this->alerts    = $alerts;
		$this->inventory = $inventory;
	}

	public function register(): void {
		add_action( 'wp_network_dashboard_setup', [ $this, 'add' ] );
	}

	public function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Multisite Radar', 'multisite-radar' ), [ $this, 'render' ] );
	}

	public function render(): void {
		try {
			$summary   = $this->alerts->summary( get_current_network_id() );
			$inventory = $this->inventory->summary();
			$top       = $this->alerts->list(
				[
					'orderby'  => 'severity',
					'order'    => 'desc',
					'per_page' => self::TOP,
				]
			);
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			echo '<p>' . esc_html__( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ) . '</p>';
			return;
		}

		$figures = [
			[ __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'] ],
			[ __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'] ],
			[ __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'] ],
			[ __( 'Unused plugins', 'multisite-radar' ), (int) $inventory['plugins']['unused'] ],
			[ __( 'Pending updates', 'multisite-radar' ), (int) $inventory['plugins']['updates'] + (int) $inventory['themes']['updates'] ],
		];
		echo '<ul class="msradar-widget__figures">';
		foreach ( $figures as $figure ) {
			printf( '<li><strong>%1$s</strong> %2$s</li>', esc_html( number_format_i18n( $figure[1] ) ), esc_html( $figure[0] ) );
		}
		echo '</ul>';

		if ( [] === $top['items'] ) {
			echo '<p>' . esc_html__( 'No alert on the network.', 'multisite-radar' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Main alerts', 'multisite-radar' ) . '</h3><ul>';
			foreach ( $top['items'] as $alert ) {
				printf(
					'<li><a href="%1$s">%2$s</a> — %3$s</li>',
					esc_url( Menu::url( 'sites', [ 'site' => (int) $alert['site']['id'] ] ) ),
					esc_html( $alert['site']['name'] ),
					esc_html( $alert['message'] )
				);
			}
			echo '</ul>';
		}
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Menu::url( 'overview' ) ), esc_html__( 'Open Multisite Radar', 'multisite-radar' ) );
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Reports\DashboardWidget;` ; propriété `private ?DashboardWidget $dashboard_widget = null;` ;
- service :

```php
	public function dashboard_widget(): DashboardWidget {
		return $this->dashboard_widget ??= new DashboardWidget( $this->alerts_query(), $this->inventory_query() );
	}
```

- dans `boot()`, après `$this->digest()->register();`, ajouter `$this->dashboard_widget()->register();`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/test.sh --filter DashboardWidgetTest`
Expected: PASS.

Run: `bin/test.sh && composer lint && composer analyse`
Expected: vert (hors les 11 échecs connus).

- [ ] **Step 5: Commit**

```bash
git add includes/Reports/DashboardWidget.php includes/Plugin.php tests/php/Reports/DashboardWidgetTest.php
git commit -m "feat: add the Multisite Radar widget to the network dashboard"
```

## Partie D — Interface

### Task 9: Composants partagés — `TrendChart` et `EventsList`

**Files:**
- Create: `src/components/trend-chart.jsx`, `src/components/events-list.jsx`
- Modify: `src/utils/format.js`, `src/admin/style.scss`
- Test: `src/components/test/trend-chart.test.jsx`, `src/components/test/events-list.test.jsx` (créés) ; `src/utils/test/` (si un test de `format.js` existe, y ajouter `formatDay`)

**Interfaces:**
- Consumes :
  - `GET /events` (tâche 4) : éléments `{ id, type, site: {id, name, url, admin_url} | null, subject, label, message, created_gmt }`, en-têtes `X-WP-Total` / `X-WP-TotalPages` ;
  - `useResource()`, `buildPath()`, `pageUrl()`, `formatDateTime()`, `formatNumber()`, `ErrorNotice`, `Pager`, `Skeleton`.
- Produces :
  - `formatDay( day: string ): string` dans `src/utils/format.js` (jour UTC `Y-m-d` au format de date du site, sans décalage de fuseau) ;
  - `TrendChart` (export par défaut) : props `{ title, points, series }`, chaque série `{ key, label, tone?, format? }`, `tone` ∈ `accent` (défaut), `info`, `warning`, `error` ;
  - `EventsList` (export par défaut) : props `{ site = 0, perPage = 20, compact = false }` ; demande `buildPath( '/events', { page, per_page, site, type } )`, valeurs vides omises (première page : `/events?page=1&per_page=20`) ;
  - `eventTypes()` : options du filtre.

- [ ] **Step 1: Write the failing tests**

Créer `src/components/test/trend-chart.test.jsx` :

```jsx
import { expect, test } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import TrendChart from '../trend-chart';

const POINTS = [
	{ day: '2026-09-01', content_count: 10, media_count: 4 },
	{ day: '2026-09-02', content_count: 12, media_count: 5 },
	{ day: '2026-09-04', content_count: 15, media_count: null },
];
const SERIES = [
	{ key: 'content_count', label: 'Content' },
	{ key: 'media_count', label: 'Media', tone: 'info' },
];

test( 'with fewer than two points, a message replaces the chart', () => {
	render(
		<TrendChart
			title="Content"
			points={ POINTS.slice( 0, 1 ) }
			series={ SERIES }
		/>
	);

	expect( screen.getByText( /Not enough history yet/ ) ).toBeInTheDocument();
	expect( screen.queryByRole( 'img' ) ).toBeNull();
} );

test( 'a line is broken where a day or a value is missing', () => {
	const { container } = render(
		<TrendChart title="Content" points={ POINTS } series={ SERIES } />
	);

	// Contenus : le 1er et le 2 reliés, le 4 isolé après un jour manquant (un point) ; médias : le 4 n'a pas de valeur.
	expect(
		container.querySelectorAll( 'polyline.msradar-trend__line--accent' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'circle.msradar-trend__dot--accent' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'polyline.msradar-trend__line--info' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'circle.msradar-trend__dot--info' )
	).toHaveLength( 0 );
} );

test( 'the chart has a text alternative and a table of the values', () => {
	render( <TrendChart title="Content" points={ POINTS } series={ SERIES } /> );

	expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
		/^Content, from .+ to .+\. Latest: Content: 15, Media: —\.$/
	);
	const table = screen.getByRole( 'table', { hidden: true } );
	expect(
		within( table ).getAllByRole( 'row', { hidden: true } )
	).toHaveLength( 4 );
} );

test( 'a series can format its values', () => {
	render(
		<TrendChart
			title="Disk"
			points={ POINTS }
			series={ [
				{
					key: 'content_count',
					label: 'Disk',
					format: ( value ) => `${ value } B`,
				},
			] }
		/>
	);

	expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
		/Latest: Disk: 15 B\.$/
	);
} );
```

Créer `src/components/test/events-list.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../store';
import EventsList from '../events-list';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

const EVENTS = [
	{
		id: 2,
		type: 'plugin_activated',
		site: null,
		subject: 'akismet/akismet.php',
		label: 'Akismet',
		message: 'Plugin Akismet network activated.',
		created_gmt: '2026-09-10T10:00:00',
	},
	{
		id: 1,
		type: 'alert_raised',
		site: {
			id: 5,
			name: 'Blog RH',
			url: 'http://example.test/rh/',
			admin_url: 'http://example.test/rh/wp-admin/',
		},
		subject: 'no_users',
		label: 'Site without users',
		message: 'New alert: Site without users.',
		created_gmt: '2026-09-09T10:00:00',
	},
];

function setup( body = EVENTS, props = {}, path = '/multisite-radar/v1/events?page=1&per_page=20' ) {
	window.msradarAdmin = {
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
	};
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			[ path ]: {
				body,
				headers: { 'X-WP-Total': String( body.length ), 'X-WP-TotalPages': '1' },
			},
		} )
	);
	return render(
		<RegistryProvider value={ registry }>
			<EventsList { ...props } />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
} );

test( 'each change shows its date, its site and its message', () => {
	setup();

	expect(
		screen.getByText( 'Plugin Akismet network activated.' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Whole network' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Blog RH' } ) ).toHaveAttribute(
		'href',
		expect.stringContaining( 'site=5' )
	);
} );

test( 'filtering by kind of change asks for that kind only', () => {
	setup();

	fireEvent.change( screen.getByRole( 'combobox', { name: 'Kind of change' } ), {
		target: { value: 'alert_raised' },
	} );

	expect( apiFetch ).toHaveBeenCalledWith(
		expect.objectContaining( {
			path: expect.stringContaining( 'type=alert_raised' ),
		} )
	);
} );

test( 'without changes, the list says so', () => {
	setup( [] );

	expect( screen.getByText( 'No change recorded yet.' ) ).toBeInTheDocument();
} );

test( 'the compact list of one site has neither filter nor site column', () => {
	setup(
		EVENTS.slice( 1 ),
		{ site: 5, perPage: 5, compact: true },
		'/multisite-radar/v1/events?page=1&per_page=5&site=5'
	);

	expect( screen.queryByRole( 'combobox' ) ).toBeNull();
	expect( screen.queryByRole( 'columnheader', { name: 'Site' } ) ).toBeNull();
	expect( screen.getByText( 'New alert: Site without users.' ) ).toBeInTheDocument();
} );
```

Lire `src/views/settings/test/settings-view.test.jsx` et `src/store/index.js` pour vérifier la forme du préchargement (`{ body, headers }`) et la façon dont le store lit les en-têtes de pagination ; adapter `setup()` à ce qu'ils attendent sans changer les assertions.

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/components`
Expected: FAIL, `Failed to resolve import "../trend-chart"` et `"../events-list"`.

- [ ] **Step 3: Add `formatDay` and the components**

Dans `src/utils/format.js` :
- importer aussi `gmdateI18n` depuis `@wordpress/date` ;
- ajouter :

```js
/**
 * Jour d'un instantané (« Y-m-d », jour UTC) au format de date du site, sans le décaler dans le fuseau du site.
 *
 * @param {?string} day Jour « Y-m-d ».
 */
export function formatDay( day ) {
	return day
		? gmdateI18n( getSettings().formats.date, `${ day }T00:00:00Z` )
		: EMPTY;
}
```

`src/components/trend-chart.jsx` :

```jsx
import { __, sprintf } from '@wordpress/i18n';
import { formatDay, formatNumber } from '../utils/format';

const WIDTH = 600;
const HEIGHT = 160;
const PAD = 8;
const DAY_MS = 86400000;

function time( day ) {
	return Date.parse( `${ day }T00:00:00Z` );
}

/**
 * Morceaux d'une série : des points de jours consécutifs ayant une valeur. Un jour manquant ou une valeur nulle
 * coupe la ligne (écart E9 du plan M6).
 *
 * @param {Array}  points Points triés par jour.
 * @param {string} key    Clé de la série.
 * @return {Array<Array<Object>>} Morceaux.
 */
function segments( points, key ) {
	const parts = [];
	let current = [];
	let previous = null;
	points.forEach( ( point ) => {
		const value = point[ key ];
		const t = time( point.day );
		if ( value === null || value === undefined ) {
			if ( current.length ) {
				parts.push( current );
			}
			current = [];
			previous = null;
			return;
		}
		if ( previous !== null && t - previous > DAY_MS ) {
			parts.push( current );
			current = [];
		}
		current.push( { t, value } );
		previous = t;
	} );
	if ( current.length ) {
		parts.push( current );
	}
	return parts;
}

/**
 * Courbes SVG internes (spec §6.3) : une ligne par série ; l'axe x suit les dates, l'axe y va de 0 au maximum.
 * Un résumé textuel (aria-label) et le tableau des valeurs en sont l'équivalent accessible.
 *
 * @param {Object} props
 * @param {string} props.title  Titre.
 * @param {Array}  props.points Points { day, …valeurs }, triés par jour.
 * @param {Array}  props.series Séries { key, label, tone, format }.
 */
export default function TrendChart( { title, points, series } ) {
	if ( points.length < 2 ) {
		return (
			<figure className="msradar-trend">
				<figcaption className="msradar-trend__title">{ title }</figcaption>
				<p className="msradar-trend__empty">
					{ __(
						'Not enough history yet: the chart appears after two daily snapshots.',
						'multisite-radar'
					) }
				</p>
			</figure>
		);
	}

	const first = time( points[ 0 ].day );
	const last = time( points[ points.length - 1 ].day );
	const max = Math.max(
		1,
		...points.flatMap( ( point ) =>
			series.map( ( item ) => point[ item.key ] || 0 )
		)
	);
	const x = ( t ) =>
		PAD + ( ( t - first ) / Math.max( 1, last - first ) ) * ( WIDTH - 2 * PAD );
	const y = ( value ) => HEIGHT - PAD - ( value / max ) * ( HEIGHT - 2 * PAD );
	const latest = points[ points.length - 1 ];
	const format = ( item, value ) =>
		value === null || value === undefined
			? formatNumber( value )
			: ( item.format || formatNumber )( value );
	const values = series
		.map( ( item ) =>
			sprintf(
				/* translators: 1: series name, 2: value. */
				__( '%1$s: %2$s', 'multisite-radar' ),
				item.label,
				format( item, latest[ item.key ] )
			)
		)
		.join( ', ' );
	const label = sprintf(
		/* translators: 1: chart title, 2: first day, 3: last day, 4: latest values. */
		__( '%1$s, from %2$s to %3$s. Latest: %4$s.', 'multisite-radar' ),
		title,
		formatDay( points[ 0 ].day ),
		formatDay( latest.day ),
		values
	);

	return (
		<figure className="msradar-trend">
			<figcaption className="msradar-trend__title">{ title }</figcaption>
			<div className="msradar-trend__plot">
				<span className="msradar-trend__max" aria-hidden="true">
					{ format( series[ 0 ], max ) }
				</span>
				<svg
					className="msradar-trend__chart"
					viewBox={ `0 0 ${ WIDTH } ${ HEIGHT }` }
					preserveAspectRatio="none"
					role="img"
					aria-label={ label }
				>
					{ series.map( ( item ) => {
						const tone = item.tone || 'accent';
						return segments( points, item.key ).map( ( part, index ) =>
							part.length === 1 ? (
								<circle
									key={ `${ item.key }-${ index }` }
									className={ `msradar-trend__dot msradar-trend__dot--${ tone }` }
									cx={ x( part[ 0 ].t ) }
									cy={ y( part[ 0 ].value ) }
									r="3"
								/>
							) : (
								<polyline
									key={ `${ item.key }-${ index }` }
									className={ `msradar-trend__line msradar-trend__line--${ tone }` }
									fill="none"
									vectorEffect="non-scaling-stroke"
									points={ part
										.map( ( p ) => `${ x( p.t ) },${ y( p.value ) }` )
										.join( ' ' ) }
								/>
							)
						);
					} ) }
				</svg>
				<div className="msradar-trend__axis" aria-hidden="true">
					<span>{ formatDay( points[ 0 ].day ) }</span>
					<span>{ formatDay( latest.day ) }</span>
				</div>
			</div>
			<ul className="msradar-trend__legend">
				{ series.map( ( item ) => (
					<li key={ item.key }>
						<span
							className={ `msradar-trend__swatch msradar-trend__swatch--${
								item.tone || 'accent'
							}` }
							aria-hidden="true"
						/>
						{ item.label }{ ' ' }
						<strong>{ format( item, latest[ item.key ] ) }</strong>
					</li>
				) ) }
			</ul>
			<details className="msradar-trend__data">
				<summary>{ __( 'Show the data', 'multisite-radar' ) }</summary>
				<table className="widefat striped">
					<caption className="screen-reader-text">{ title }</caption>
					<thead>
						<tr>
							<th scope="col">{ __( 'Day', 'multisite-radar' ) }</th>
							{ series.map( ( item ) => (
								<th scope="col" key={ item.key }>
									{ item.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ points.map( ( point ) => (
							<tr key={ point.day }>
								<th scope="row">{ formatDay( point.day ) }</th>
								{ series.map( ( item ) => (
									<td key={ item.key }>
										{ format( item, point[ item.key ] ) }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</details>
		</figure>
	);
}
```

`src/components/events-list.jsx` :

```jsx
import { SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pageUrl } from '../admin/config';
import { useResource } from '../hooks/use-resource';
import { buildPath } from '../store/paths';
import { formatDateTime } from '../utils/format';
import ErrorNotice from './error-notice';
import Pager from './pager';
import Skeleton from './skeleton';

/**
 * Options du filtre par type de changement (types de la spec §3.1).
 */
export function eventTypes() {
	return [
		{ value: '', label: __( 'All changes', 'multisite-radar' ) },
		{ value: 'site_created', label: __( 'Sites created', 'multisite-radar' ) },
		{ value: 'site_deleted', label: __( 'Sites deleted', 'multisite-radar' ) },
		{
			value: 'plugin_activated',
			label: __( 'Plugins activated', 'multisite-radar' ),
		},
		{
			value: 'plugin_deactivated',
			label: __( 'Plugins deactivated', 'multisite-radar' ),
		},
		{
			value: 'theme_switched',
			label: __( 'Themes switched', 'multisite-radar' ),
		},
		{ value: 'alert_raised', label: __( 'New alerts', 'multisite-radar' ) },
		{
			value: 'alert_resolved',
			label: __( 'Resolved alerts', 'multisite-radar' ),
		},
	];
}

function SiteCell( { site } ) {
	if ( ! site ) {
		return __( 'Whole network', 'multisite-radar' );
	}
	// Un site supprimé n'a plus d'administration : son nom reste, sans lien.
	return site.admin_url ? (
		<a href={ pageUrl( 'sites', { site: site.id } ) }>{ site.name }</a>
	) : (
		site.name
	);
}

/**
 * Le journal des changements (GET /events) : date, site et changement, les plus récents d'abord. La version
 * compacte (Vue d'ensemble, fiche d'un site) n'a ni filtre ni pagination ; la liste d'un site n'a pas de colonne Site.
 *
 * @param {Object}  props
 * @param {number}  props.site    ID du site, 0 pour tout le réseau.
 * @param {number}  props.perPage Changements par page.
 * @param {boolean} props.compact Sans filtre ni pagination.
 */
export default function EventsList( { site = 0, perPage = 20, compact = false } ) {
	const [ page, setPage ] = useState( 1 );
	const [ type, setType ] = useState( '' );
	const events = useResource(
		buildPath( '/events', {
			page,
			per_page: perPage,
			site: site || undefined,
			type,
		} )
	);

	const filter = ! compact && (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Kind of change', 'multisite-radar' ) }
			value={ type }
			options={ eventTypes() }
			onChange={ ( value ) => {
				setType( value );
				setPage( 1 );
			} }
		/>
	);

	let body;
	if ( events.error ) {
		body = <ErrorNotice error={ events.error } onRetry={ events.retry } />;
	} else if ( ! events.data ) {
		body = (
			<Skeleton
				lines={ 3 }
				label={ __( 'Loading the changes…', 'multisite-radar' ) }
			/>
		);
	} else if ( events.data.length === 0 ) {
		body = (
			<p>{ __( 'No change recorded yet.', 'multisite-radar' ) }</p>
		);
	} else {
		body = (
			<>
				<table
					className="widefat striped msradar-table msradar-events"
					aria-busy={ events.isLoading }
				>
					<thead>
						<tr>
							<th scope="col">{ __( 'Date', 'multisite-radar' ) }</th>
							{ ! site && (
								<th scope="col">
									{ __( 'Site', 'multisite-radar' ) }
								</th>
							) }
							<th scope="col">
								{ __( 'Change', 'multisite-radar' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ events.data.map( ( event ) => (
							<tr key={ event.id }>
								<td>{ formatDateTime( event.created_gmt ) }</td>
								{ ! site && (
									<td>
										<SiteCell site={ event.site } />
									</td>
								) }
								<td>{ event.message }</td>
							</tr>
						) ) }
					</tbody>
				</table>
				{ ! compact && (
					<Pager
						page={ page }
						pages={ events.totalPages || 1 }
						onChange={ setPage }
					/>
				) }
			</>
		);
	}

	return (
		<div className="msradar-events-list">
			{ filter }
			{ body }
		</div>
	);
}
```

Dans `src/admin/style.scss`, ajouter :

```scss
.msradar-trend {
	margin: 0 0 24px;
}

.msradar-trend__title {
	font-weight: 600;
	margin-bottom: 8px;
}

.msradar-trend__plot {
	position: relative;
}

.msradar-trend__chart {
	display: block;
	width: 100%;
	height: 160px;
	border-bottom: 1px solid #c3c4c7;
	border-left: 1px solid #c3c4c7;
}

.msradar-trend__max {
	position: absolute;
	top: 0;
	left: 4px;
	font-size: 11px;
	color: #50575e;
}

.msradar-trend__axis {
	display: flex;
	justify-content: space-between;
	font-size: 11px;
	color: #50575e;
}

.msradar-trend__line {
	stroke-width: 2;
}

.msradar-trend__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 8px 0;
}

.msradar-trend__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	margin-right: 4px;
	border-radius: 2px;
	vertical-align: middle;
}

$msradar-tones: (
	accent: var(--wp-admin-theme-color),
	info: #72aee6,
	warning: #dba617,
	error: #d63638,
);

@each $tone, $color in $msradar-tones {
	.msradar-trend__line--#{$tone} {
		stroke: $color;
	}

	.msradar-trend__dot--#{$tone},
	.msradar-trend__swatch--#{$tone} {
		fill: $color;
		background: $color;
	}
}

.msradar-trend__data summary {
	cursor: pointer;
	margin: 4px 0 8px;
}

.msradar-events-list .components-base-control {
	max-width: 280px;
	margin-bottom: 12px;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/components src/utils && npm run lint:js && npm run lint:css && npm run test:unit`
Expected: PASS ; lint sans nouvelle erreur (4 avertissements jsdoc connus).

- [ ] **Step 5: Commit**

```bash
git add src/components/ src/utils/format.js src/admin/style.scss
git commit -m "feat: add the trend chart and the list of changes components"
```

### Task 10: Page « Rapports » — tendances, « Quoi de neuf », récapitulatif

**Files:**
- Create: `src/views/reports/index.jsx`, `src/views/reports/digest-card.jsx`, `src/views/reports/digest-fields.js`, `src/admin/reports.js`
- Modify: `includes/Admin/Menu.php`, `includes/Admin/Preload.php`, `webpack.config.js`
- Test: `src/views/reports/test/reports-view.test.jsx` (créé) ; `tests/php/Admin/MenuTest.php`, `tests/php/Admin/PreloadTest.php`

**Interfaces:**
- Consumes :
  - `TrendChart`, `EventsList` (tâche 9) ;
  - `GET /reports/trends?days=…` (tâche 5), `POST /reports/digest/test` (tâche 7), `GET/POST /settings` (`reports.*`) ;
  - `mergeDeep()` de `src/views/settings/fields.js` ; `DataForm`, `useFormValidity` de `src/components/data-views` ;
  - `receiveResponse`, `toResponse`, `STORE_NAME` de `src/store`.
- Produces :
  - page `multisite-radar-reports` (`Menu::PAGES['reports']`), titre « Reports », entre Alerts et Settings ;
  - préchargement :
    - `/reports/trends?days=90` ;
    - `/events?page=1&per_page=20` ;
    - `/settings` (gardé seulement si l'utilisateur peut le lire) ;
  - `digestChanges( saved, current )` : n'envoie que les valeurs de `reports` modifiées.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Admin/MenuTest.php`, la liste attendue des slugs devient `[ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-plugins', 'multisite-radar-themes', 'multisite-radar-users', 'multisite-radar-alerts', 'multisite-radar-reports', 'multisite-radar-settings' ]`.

Dans `tests/php/Admin/PreloadTest.php`, ajouter :

```php
	public function test_the_reports_page_preloads_its_trends_changes_and_settings(): void {
		$prefs = $this->plugin()->preferences()->get( 0 );

		$this->assertSame(
			[
				'/multisite-radar/v1/preferences',
				'/multisite-radar/v1/reports/trends?days=90',
				'/multisite-radar/v1/events?page=1&per_page=20',
				'/multisite-radar/v1/settings',
			],
			Preload::paths( 'reports', [], $prefs )
		);
	}
```

(Reprendre la façon dont les autres tests de `PreloadTest` obtiennent `$prefs`.)

Créer `src/views/reports/test/reports-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import ReportsView from '..';
import { digestChanges } from '../digest-fields';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const SETTINGS = {
	scan: {
		activity_post_types: [ 'post', 'page' ],
		analysis_plugins: [],
		measure_disk: true,
		full_rescan_days: 7,
	},
	alerts: { rules: {} },
	reports: {
		digest_enabled: false,
		digest_day: 1,
		digest_recipients: { mode: 'super_admins', emails: [] },
	},
	integrations: { mcp_public: false },
	sites_menu: { enabled: false },
	retention: { events_days: 90, snapshots_days: 365 },
};

const TRENDS = {
	days: 90,
	since: '2026-06-19',
	site: null,
	points: [
		{
			day: '2026-09-15',
			sites: 3,
			content_count: 10,
			media_count: 4,
			alerts_error: 1,
			alerts_warning: 0,
			alerts_info: 1,
		},
		{
			day: '2026-09-16',
			sites: 4,
			content_count: 12,
			media_count: 4,
			alerts_error: 0,
			alerts_warning: 1,
			alerts_info: 1,
		},
	],
};

function setup( { canManage = true } = {} ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/reports/trends?days=90': {
			body: TRENDS,
			headers: {},
		},
		'/multisite-radar/v1/events?page=1&per_page=20': {
			body: [],
			headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
		},
		...( canManage
			? {
					'/multisite-radar/v1/settings': {
						body: SETTINGS,
						headers: {},
					},
			  }
			: {} ),
	};
	window.msradarAdmin = { view: 'reports', canManage, preload, pages: {} };
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<ReportsView />
		</RegistryProvider>
	);
	return registry;
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

test( 'shows the trends of the network from the preloaded data', () => {
	setup();

	expect(
		screen.getByRole( 'img', { name: /^Sites, from .+ Latest: Sites: 4\.$/ } )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'img', { name: /Sites by highest alert/ } )
	).toBeInTheDocument();
	expect( screen.getByText( 'No change recorded yet.' ) ).toBeInTheDocument();
} );

test( 'another period asks for its trends', () => {
	setup();

	fireEvent.change( screen.getByRole( 'combobox', { name: 'Period' } ), {
		target: { value: '30' },
	} );

	expect( apiFetch ).toHaveBeenCalledWith(
		expect.objectContaining( {
			path: '/multisite-radar/v1/reports/trends?days=30',
		} )
	);
} );

test( 'only the changed digest settings are saved', () => {
	const on = {
		...SETTINGS,
		reports: { ...SETTINGS.reports, digest_enabled: true },
	};

	expect( digestChanges( SETTINGS, on ) ).toEqual( {
		reports: { digest_enabled: true },
	} );
	expect( digestChanges( SETTINGS, SETTINGS ) ).toEqual( {} );
	expect(
		digestChanges( SETTINGS, {
			...SETTINGS,
			reports: {
				...SETTINGS.reports,
				digest_recipients: { mode: 'custom', emails: [ 'a@example.org' ] },
			},
		} )
	).toEqual( {
		reports: {
			digest_recipients: { mode: 'custom', emails: [ 'a@example.org' ] },
		},
	} );
} );

test( 'the digest is switched on and saved', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		reports: { ...SETTINGS.reports, digest_enabled: true },
	} );
	setup();

	fireEvent.click(
		screen.getByRole( 'checkbox', {
			name: /Send a weekly summary by e-mail/,
		} )
	);
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/settings',
		method: 'POST',
		data: { reports: { digest_enabled: true } },
	} );
} );

test( 'a test e-mail is sent on request', async () => {
	apiFetch.mockResolvedValue( { sent: true } );
	const registry = setup();

	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Send a test e-mail to me' } )
		);
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/reports/digest/test',
		method: 'POST',
	} );
	expect(
		registry
			.select( noticesStore )
			.getNotices()
			.map( ( notice ) => notice.content )
	).toContain( 'The test e-mail was sent to your address.' );
} );

test( 'the digest settings are reserved to managers', () => {
	setup( { canManage: false } );

	expect(
		screen.queryByRole( 'button', { name: 'Send a test e-mail to me' } )
	).toBeNull();
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/views/reports && bin/test.sh --filter 'MenuTest|PreloadTest'`
Expected: FAIL, la vue et `digest-fields` n'existent pas ; le menu n'a pas la page Rapports ; `Preload::paths( 'reports' )` ne précharge que les préférences.

- [ ] **Step 3: Add the page on the PHP side**

`includes/Admin/Menu.php` :
- dans `PAGES`, entre `'alerts'` et `'settings'` : `'reports'  => 'multisite-radar-reports',` (aligner les flèches) ;
- dans `titles()`, entre `'alerts'` et `'settings'` : `'reports'  => __( 'Reports', 'multisite-radar' ),`.

`includes/Admin/Preload.php`, dans le `switch` de `paths()`, avant `case 'settings':` :

```php
			case 'reports':
				$paths[] = ViewQuery::path( '/reports/trends', [ 'days' => 90 ] );
				$paths[] = ViewQuery::path(
					'/events',
					[
						'page'     => 1,
						'per_page' => 20,
					]
				);
				// Lu seulement avec msradar_manage : une réponse 403 n'est pas gardée (Preload::run()).
				$paths[] = ViewQuery::path( '/settings' );
				break;
```

Mettre à jour le docblock de `paths()` (`@param string $view` cite les vues) avec `reports`.

`webpack.config.js` : ajouter `'reports'` à `VIEWS`, avant `'settings'`. La vue n'est pas légère (DataForm) : ne pas l'ajouter à `LIGHT_VIEWS`.

`src/admin/reports.js` :

```js
import { mount } from './mount';
import ReportsView from '../views/reports';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( ReportsView );
```

- [ ] **Step 4: Create the view**

`src/views/reports/digest-fields.js` :

```js
import { __ } from '@wordpress/i18n';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function days() {
	return [
		__( 'Sunday', 'multisite-radar' ),
		__( 'Monday', 'multisite-radar' ),
		__( 'Tuesday', 'multisite-radar' ),
		__( 'Wednesday', 'multisite-radar' ),
		__( 'Thursday', 'multisite-radar' ),
		__( 'Friday', 'multisite-radar' ),
		__( 'Saturday', 'multisite-radar' ),
	];
}

/**
 * Champs DataForm du récapitulatif (réglages reports.*, spec §8).
 */
export function getDigestFields() {
	return [
		{
			id: 'reports.digest_enabled',
			type: 'boolean',
			label: __( 'Send a weekly summary by e-mail', 'multisite-radar' ),
			description: __(
				'New and resolved alerts and the other changes of the last seven days, with a link to Multisite Radar.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
		{
			id: 'reports.digest_day',
			type: 'integer',
			label: __( 'Day of the week', 'multisite-radar' ),
			elements: days().map( ( label, value ) => ( { value, label } ) ),
		},
		{
			id: 'reports.digest_recipients.mode',
			type: 'text',
			label: __( 'Recipients', 'multisite-radar' ),
			elements: [
				{
					value: 'super_admins',
					label: __( 'All super admins', 'multisite-radar' ),
				},
				{
					value: 'custom',
					label: __( 'The addresses below', 'multisite-radar' ),
				},
			],
		},
		{
			id: 'reports.digest_recipients.emails',
			type: 'array',
			label: __( 'E-mail addresses', 'multisite-radar' ),
			description: __(
				'Used when the recipients are the addresses below. Each address receives its own e-mail.',
				'multisite-radar'
			),
			isValid: {
				elements: false,
				custom: ( item ) =>
					(
						item.reports?.digest_recipients?.emails || []
					).every( ( email ) => EMAIL.test( email ) )
						? null
						: __( 'Enter valid e-mail addresses.', 'multisite-radar' ),
			},
		},
	];
}

export const DIGEST_FORM = {
	layout: { type: 'regular' },
	fields: [
		'reports.digest_enabled',
		'reports.digest_day',
		'reports.digest_recipients.mode',
		'reports.digest_recipients.emails',
	],
};

function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Corps de POST /settings : seules les valeurs du récapitulatif qui diffèrent des réglages enregistrés. Les
 * destinataires (mode et adresses) sont envoyés ensemble.
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function digestChanges( saved, current ) {
	const patch = {};
	[ 'digest_enabled', 'digest_day', 'digest_recipients' ].forEach( ( key ) => {
		if ( ! same( saved.reports?.[ key ], current.reports?.[ key ] ) ) {
			patch.reports = { ...patch.reports, [ key ]: current.reports?.[ key ] };
		}
	} );
	return patch;
}
```

`src/views/reports/digest-card.jsx` :

```jsx
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { DataForm, useFormValidity } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { STORE_NAME, toResponse } from '../../store';
import { buildPath } from '../../store/paths';
import { mergeDeep } from '../settings/fields';
import { DIGEST_FORM, digestChanges, getDigestFields } from './digest-fields';

const SETTINGS_PATH = buildPath( '/settings' );

/**
 * Réglage du récapitulatif hebdomadaire (écart E8 du plan M6), avec l'envoi d'un e-mail de test à soi-même.
 */
export default function DigestCard() {
	const settings = useResource( SETTINGS_PATH );
	const [ edits, setEdits ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const { receiveResponse } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const fields = useMemo( () => getDigestFields(), [] );
	const data = useMemo(
		() => mergeDeep( settings.data || {}, edits ),
		[ settings.data, edits ]
	);
	const { validity, isValid } = useFormValidity( data, fields, DIGEST_FORM );
	const changed = useMemo(
		() => digestChanges( settings.data || {}, data ),
		[ settings.data, data ]
	);
	const dirty = Object.keys( changed ).length > 0;

	const save = async () => {
		setSaving( true );
		try {
			const saved = await apiFetch( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: changed,
			} );
			receiveResponse( SETTINGS_PATH, toResponse( saved ) );
			setEdits( {} );
			createSuccessNotice( __( 'Settings saved.', 'multisite-radar' ), {
				type: 'snackbar',
			} );
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__( 'The settings could not be saved.', 'multisite-radar' ),
				{ type: 'snackbar' }
			);
		} finally {
			setSaving( false );
		}
	};

	const sendTest = async () => {
		setTesting( true );
		try {
			await apiFetch( {
				path: buildPath( '/reports/digest/test' ),
				method: 'POST',
			} );
			createSuccessNotice(
				__(
					'The test e-mail was sent to your address.',
					'multisite-radar'
				),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'The test e-mail could not be sent.',
						'multisite-radar'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setTesting( false );
		}
	};

	return (
		<Card className="msradar-digest">
			<CardHeader>
				<h2>{ __( 'Weekly summary', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ settings.error } onRetry={ settings.retry } />
				{ ! settings.data && ! settings.error && (
					<Skeleton
						lines={ 3 }
						label={ __( 'Loading the settings…', 'multisite-radar' ) }
					/>
				) }
				{ settings.data && (
					<form
						onSubmit={ ( event ) => {
							event.preventDefault();
							save();
						} }
					>
						<DataForm
							data={ data }
							fields={ fields }
							form={ DIGEST_FORM }
							validity={ validity }
							onChange={ ( patch ) =>
								setEdits( ( current ) => mergeDeep( current, patch ) )
							}
						/>
						<p className="msradar-digest__actions">
							<Button
								variant="primary"
								type="submit"
								isBusy={ saving }
								disabled={ saving || ! dirty || ! isValid }
							>
								{ __( 'Save', 'multisite-radar' ) }
							</Button>{ ' ' }
							<Button
								variant="secondary"
								isBusy={ testing }
								disabled={ testing }
								onClick={ sendTest }
							>
								{ __(
									'Send a test e-mail to me',
									'multisite-radar'
								) }
							</Button>
						</p>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
```

`src/views/reports/index.jsx` :

```jsx
import { Card, CardBody, CardHeader, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import Skeleton from '../../components/skeleton';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import DigestCard from './digest-card';

const PERIODS = [ 30, 90, 365 ];

function periods() {
	return PERIODS.map( ( days ) => ( {
		value: String( days ),
		label: sprintf(
			/* translators: %d: number of days. */
			_n( 'Last %d day', 'Last %d days', days, 'multisite-radar' ),
			days
		),
	} ) );
}

/**
 * Page Rapports (spec §6.2) : tendances du réseau, « Quoi de neuf » et réglage du récapitulatif.
 */
export default function ReportsView() {
	const { canManage } = getConfig();
	const [ days, setDays ] = useState( 90 );
	const trends = useResource( buildPath( '/reports/trends', { days } ) );
	const points = trends.data?.points || [];

	return (
		<div className="msradar-reports">
			<Card>
				<CardHeader>
					<h2>{ __( 'Trends', 'multisite-radar' ) }</h2>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Period', 'multisite-radar' ) }
						value={ String( days ) }
						options={ periods() }
						onChange={ ( value ) => setDays( Number( value ) ) }
					/>
				</CardHeader>
				<CardBody>
					<ErrorNotice error={ trends.error } onRetry={ trends.retry } />
					{ ! trends.data && ! trends.error && (
						<Skeleton
							lines={ 4 }
							label={ __( 'Loading the trends…', 'multisite-radar' ) }
						/>
					) }
					{ trends.data && (
						<div className="msradar-reports__trends">
							<TrendChart
								title={ __( 'Sites', 'multisite-radar' ) }
								points={ points }
								series={ [
									{
										key: 'sites',
										label: __( 'Sites', 'multisite-radar' ),
									},
								] }
							/>
							<TrendChart
								title={ __( 'Content and media', 'multisite-radar' ) }
								points={ points }
								series={ [
									{
										key: 'content_count',
										label: __( 'Content', 'multisite-radar' ),
									},
									{
										key: 'media_count',
										label: __( 'Media', 'multisite-radar' ),
										tone: 'info',
									},
								] }
							/>
							<TrendChart
								title={ __(
									'Sites by highest alert',
									'multisite-radar'
								) }
								points={ points }
								series={ [
									{
										key: 'alerts_error',
										label: __( 'Error', 'multisite-radar' ),
										tone: 'error',
									},
									{
										key: 'alerts_warning',
										label: __( 'Warning', 'multisite-radar' ),
										tone: 'warning',
									},
									{
										key: 'alerts_info',
										label: __( 'Information', 'multisite-radar' ),
										tone: 'info',
									},
								] }
							/>
						</div>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2>{ __( "What's new", 'multisite-radar' ) }</h2>
				</CardHeader>
				<CardBody>
					<EventsList />
				</CardBody>
			</Card>
			{ canManage && <DigestCard /> }
		</div>
	);
}
```

Dans `src/admin/style.scss`, ajouter :

```scss
.msradar-reports {
	display: grid;
	gap: 16px;

	.components-card__header {
		gap: 16px;
		flex-wrap: wrap;
	}
}

.msradar-reports__trends {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 16px;
}
```

Les libellés `Error`, `Warning` et `Information` existent peut-être déjà dans `src/` (gravités) : réutiliser exactement les mêmes chaînes s'ils existent, pour ne pas multiplier les traductions.

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run src/views/reports && npm run lint:js && npm run lint:css && npm run test:unit && npm run build`
Expected: PASS ; build sans erreur, `build/admin/reports.js` présent.

Run: `bin/test.sh --filter 'MenuTest|PreloadTest|AssetsTest|FooterTest' && composer lint && composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Admin/Menu.php includes/Admin/Preload.php webpack.config.js src/admin/reports.js src/admin/style.scss src/views/reports/ tests/php/Admin/
git commit -m "feat: add the Reports page with trends, recent changes and the digest settings"
```

### Task 11: Onglet « Historique », Vue d'ensemble et rétention dans les réglages

**Files:**
- Create: `src/views/site-panel/history-tab.jsx`, `src/views/overview/history.jsx`
- Modify: `src/views/site-panel/index.jsx`, `src/views/overview/index.jsx`, `src/views/settings/fields.js`, `src/views/settings/index.jsx`, `includes/Admin/Preload.php`
- Test: `src/views/site-panel/test/site-panel.test.jsx`, `src/views/overview/test/overview.test.jsx`, `src/views/settings/test/settings-view.test.jsx`, `tests/php/Admin/PreloadTest.php`

**Interfaces:**
- Consumes : `TrendChart`, `EventsList` (tâche 9) ; `GET /reports/trends?days&site` (tâche 5) ; `formatBytes()` ; `pageUrl( 'reports' )` ; réglages `retention.events_days`, `retention.snapshots_days` (entiers, 1 à 3650).
- Produces :
  - onglet `history` de la fiche ;
  - cartes `RecentChanges` et `AlertsTrend` de la Vue d'ensemble ;
  - préchargement de la Vue d'ensemble : en plus, `/events?page=1&per_page=5` et `/reports/trends?days=30` ;
  - `RETENTION_FORM`, champs `retention.*`, `changes()` qui envoie `retention` modifié ;
  - carte « History » des réglages.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Admin/PreloadTest.php`, mettre à jour l'assertion de la Vue d'ensemble pour attendre, après les trois chemins existants : `'/multisite-radar/v1/events?page=1&per_page=5'` puis `'/multisite-radar/v1/reports/trends?days=30'`.

Dans `src/views/site-panel/test/site-panel.test.jsx` (fiche du site 12, `apiFetch` en attente infinie par défaut), ajouter :

```jsx
test( 'the History tab asks for the trends and the changes of the site', () => {
	setup();

	fireEvent.click( screen.getByRole( 'tab', { name: 'History' } ) );

	expect( apiFetch ).toHaveBeenCalledWith(
		expect.objectContaining( {
			path: '/multisite-radar/v1/reports/trends?days=90&site=12',
		} )
	);
	expect( apiFetch ).toHaveBeenCalledWith(
		expect.objectContaining( {
			path: '/multisite-radar/v1/events?page=1&per_page=10&site=12',
		} )
	);
} );
```

Dans `src/views/overview/test/overview.test.jsx` :
- ajouter en tête, à côté des autres URL : `const REPORTS_URL = 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-reports';` ;
- dans `renderView()`, ajouter `reports: REPORTS_URL` aux `pages`, et au préchargement les deux réponses que PHP précharge désormais (sans elles, le test « tiles link to the sites filtered by severity », qui vérifie qu'aucune requête ne part, échouerait) :

```jsx
		'/multisite-radar/v1/events?page=1&per_page=5': {
			body: [],
			headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
		},
		'/multisite-radar/v1/reports/trends?days=30': {
			body: { days: 30, since: '2026-08-18', site: null, points: [] },
			headers: {},
		},
```

- ajouter :

```jsx
test( 'during an analysis, the first-run button stays visible but disabled, and only the analysis panel shows progress', async () => {
	renderView( {
		data: summary( {
			scanned_sites: 0,
			pending_sites: 12,
			by_severity: { error: 0, warning: 0, info: 0 },
			by_rule: [],
		} ),
	} );

	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Start the analysis' } )
		);
	} );

	expect(
		screen.getByRole( 'button', { name: 'Start the analysis' } )
	).toBeDisabled();
	expect( screen.getAllByRole( 'progressbar' ) ).toHaveLength( 1 );
} );

test( 'the overview shows the recent changes and the alerts of the last 30 days', () => {
	renderView();

	expect(
		screen.getByRole( 'heading', { name: 'Recent changes' } )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'link', { name: 'See all changes' } )
	).toHaveAttribute( 'href', REPORTS_URL );
	expect(
		screen.getByRole( 'heading', { name: 'Alerts, last 30 days' } )
	).toBeInTheDocument();
	expect( screen.getByText( 'No change recorded yet.' ) ).toBeInTheDocument();
} );
```

Si `useScan` ne passe pas à `running` tant que la requête `POST /scan` est en attente (`apiFetch` renvoie une promesse qui ne se résout jamais), lire `src/hooks/use-scan.js` et faire résoudre la première requête comme le fait le test « Analyse all sites starts a full analysis » ; les deux assertions restent celles écrites ici.

Dans `src/views/settings/test/settings-view.test.jsx`, ajouter :

```jsx
test( 'the retention of the history is saved', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		retention: { ...SETTINGS.retention, events_days: 30 },
	} );
	setup();

	fireEvent.change(
		screen.getByRole( 'spinbutton', { name: /Keep the changes for \(days\)/ } ),
		{ target: { value: '30' } }
	);
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		retention: { events_days: 30 },
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/views/site-panel src/views/overview src/views/settings && bin/test.sh --filter PreloadTest`
Expected: FAIL : pas d'onglet History, pas de cartes, bouton masqué pendant l'analyse, deux barres de progression, pas de champ de rétention, préchargement de la Vue d'ensemble incomplet.

- [ ] **Step 3: Add the History tab**

`src/views/site-panel/history-tab.jsx` :

```jsx
import { SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import Skeleton from '../../components/skeleton';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import { formatBytes } from '../../utils/format';

const PERIODS = [ 30, 90, 365 ];

/**
 * Onglet Historique de la fiche (spec §6.2) : tendances du site et ses derniers changements, chargés à la demande.
 *
 * @param {Object} props
 * @param {number} props.siteId ID du site.
 */
export default function HistoryTab( { siteId } ) {
	const [ days, setDays ] = useState( 90 );
	const trends = useResource(
		buildPath( '/reports/trends', { days, site: siteId } )
	);
	const points = trends.data?.points || [];

	return (
		<div className="msradar-history">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Period', 'multisite-radar' ) }
				value={ String( days ) }
				options={ PERIODS.map( ( value ) => ( {
					value: String( value ),
					label: sprintf(
						/* translators: %d: number of days. */
						_n( 'Last %d day', 'Last %d days', value, 'multisite-radar' ),
						value
					),
				} ) ) }
				onChange={ ( value ) => setDays( Number( value ) ) }
			/>
			<ErrorNotice error={ trends.error } onRetry={ trends.retry } />
			{ ! trends.data && ! trends.error && (
				<Skeleton
					lines={ 4 }
					label={ __( 'Loading the trends…', 'multisite-radar' ) }
				/>
			) }
			{ trends.data && (
				<>
					<TrendChart
						title={ __( 'Content and media', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'content_count',
								label: __( 'Content', 'multisite-radar' ),
							},
							{
								key: 'media_count',
								label: __( 'Media', 'multisite-radar' ),
								tone: 'info',
							},
						] }
					/>
					<TrendChart
						title={ __( 'Users', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'users_count',
								label: __( 'Users', 'multisite-radar' ),
							},
						] }
					/>
					<TrendChart
						title={ __( 'Disk and database', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'disk_bytes',
								label: __( 'Disk', 'multisite-radar' ),
								format: formatBytes,
							},
							{
								key: 'db_bytes',
								label: __( 'Database', 'multisite-radar' ),
								tone: 'info',
								format: formatBytes,
							},
						] }
					/>
					<TrendChart
						title={ __( 'Alerts', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'alerts_count',
								label: __( 'Alerts', 'multisite-radar' ),
								tone: 'warning',
							},
						] }
					/>
				</>
			) }
			<h3>{ __( 'Changes', 'multisite-radar' ) }</h3>
			<EventsList site={ siteId } perPage={ 10 } compact />
		</div>
	);
}
```

Dans `src/views/site-panel/index.jsx` :
- importer `HistoryTab` depuis `./history-tab` ;
- dans `tabs()`, ajouter en dernier `{ name: 'history', title: __( 'History', 'multisite-radar' ) },` ;
- dans `Tab`, ajouter `case 'history': return <HistoryTab key={ site.id } siteId={ site.id } />;`.

La liste du site est compacte (sans pagination) ; « See all changes » sur la page Rapports n'existe pas encore filtré par site : c'est volontaire, la fiche montre les 10 derniers.

- [ ] **Step 4: Enrich the overview and fix its progress display**

`src/views/overview/history.jsx` :

```jsx
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pageUrl } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';

/**
 * Les cinq derniers changements du réseau (écart E10 du plan M6).
 */
export function RecentChanges() {
	const reports = pageUrl( 'reports' );
	return (
		<Card className="msradar-recent-changes">
			<CardHeader>
				<h2>{ __( 'Recent changes', 'multisite-radar' ) }</h2>
				{ reports && (
					<a href={ reports }>
						{ __( 'See all changes', 'multisite-radar' ) }
					</a>
				) }
			</CardHeader>
			<CardBody>
				<EventsList perPage={ 5 } compact />
			</CardBody>
		</Card>
	);
}

/**
 * Sites par gravité de leur alerte la plus haute, sur 30 jours.
 */
export function AlertsTrend() {
	const trends = useResource( buildPath( '/reports/trends', { days: 30 } ) );
	return (
		<Card className="msradar-alerts-trend">
			<CardHeader>
				<h2>{ __( 'Alerts, last 30 days', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ trends.error } onRetry={ trends.retry } />
				{ trends.data && (
					<TrendChart
						title={ __( 'Sites by highest alert', 'multisite-radar' ) }
						points={ trends.data.points }
						series={ [
							{
								key: 'alerts_error',
								label: __( 'Error', 'multisite-radar' ),
								tone: 'error',
							},
							{
								key: 'alerts_warning',
								label: __( 'Warning', 'multisite-radar' ),
								tone: 'warning',
							},
							{
								key: 'alerts_info',
								label: __( 'Information', 'multisite-radar' ),
								tone: 'info',
							},
						] }
					/>
				) }
			</CardBody>
		</Card>
	);
}
```

Dans `src/views/overview/index.jsx` :
- importer `{ AlertsTrend, RecentChanges }` depuis `./history` ;
- dans `FirstRun`, supprimer `{ scan.running && <ScanProgress scan={ scan } /> }` (la progression reste dans `ScanPanel`), et remplacer le bouton conditionnel par :

```jsx
			{ canManage && (
				<Button
					variant="primary"
					isBusy={ scan.running }
					disabled={ scan.running }
					onClick={ () => scan.start( { scope: 'all' } ) }
				>
					{ __( 'Start the analysis', 'multisite-radar' ) }
				</Button>
			) }
```

- retirer `ScanProgress` de l'import de `./scan-panel` s'il n'est plus utilisé dans ce fichier ;
- dans `<div className="msradar-overview__columns">`, ajouter après `ScanPanel` : `<RecentChanges />` puis `<AlertsTrend />`.

Dans `includes/Admin/Preload.php`, `case 'overview':` ajoute après les trois chemins existants :

```php
				$paths[] = ViewQuery::path(
					'/events',
					[
						'page'     => 1,
						'per_page' => 5,
					]
				);
				$paths[] = ViewQuery::path( '/reports/trends', [ 'days' => 30 ] );
```

- [ ] **Step 5: Add the retention settings**

Dans `src/views/settings/fields.js` :
- dans `getSettingsFields()`, ajouter après le champ `integrations.mcp_public` :

```js
		{
			id: 'retention.events_days',
			type: 'integer',
			label: __( 'Keep the changes for (days)', 'multisite-radar' ),
			description: __(
				'Older changes are deleted every day.',
				'multisite-radar'
			),
			isValid: { required: true, min: 1, max: 3650 },
		},
		{
			id: 'retention.snapshots_days',
			type: 'integer',
			label: __( 'Keep the daily figures for (days)', 'multisite-radar' ),
			description: __(
				'Older daily figures, used by the trends, are deleted every day.',
				'multisite-radar'
			),
			isValid: { required: true, min: 1, max: 3650 },
		},
```

- après `INTEGRATIONS_FORM`, ajouter :

```js
export const RETENTION_FORM = {
	layout: { type: 'regular' },
	fields: [ 'retention.events_days', 'retention.snapshots_days' ],
};
```

- dans `allForm()`, ajouter `...RETENTION_FORM.fields,` après `...INTEGRATIONS_FORM.fields,` ;
- dans `changes()`, après le bloc d'`integrations` :

```js
	[ 'events_days', 'snapshots_days' ].forEach( ( key ) => {
		if ( ! same( saved.retention?.[ key ], current.retention?.[ key ] ) ) {
			patch.retention = {
				...patch.retention,
				[ key ]: current.retention?.[ key ],
			};
		}
	} );
```

Dans `src/views/settings/index.jsx` :
- importer `RETENTION_FORM` depuis `./fields` ;
- après la carte « Integrations », ajouter une carte construite comme elle, titre `__( 'History', 'multisite-radar' )` et `form={ RETENTION_FORM }`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `npx vitest run src/views && npm run lint:js && npm run lint:css && npm run test:unit && npm run build`
Expected: PASS ; build sans erreur.

Run: `bin/test.sh --filter PreloadTest && composer lint && composer analyse`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/views/ includes/Admin/Preload.php tests/php/Admin/PreloadTest.php
git commit -m "feat: add the History tab, the recent changes of the overview and the retention settings"
```

### Task 12: Recette de bout en bout — pages, accessibilité, désinstallation

**Files:**
- Create: `tests/e2e/specs/history.spec.js`
- Modify: `tests/e2e/specs/a11y.spec.js`, `bin/e2e.sh`
- Test: les fichiers ci-dessus

**Interfaces:**
- Consumes : la page Rapports, l'onglet Historique, la Vue d'ensemble (tâches 10, 11) ; `uninstall.php` (tâche 1) ; le WordPress jetable de `bin/e2e.sh` et sa fonction `expect`.
- Produces : la recette du jalon.

- [ ] **Step 1: Write the end-to-end tests**

Créer `tests/e2e/specs/history.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'History and reports', () => {
	test( 'the Reports page shows the trends, the changes and the digest settings', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-reports'
		);
		const app = page.locator( '#msradar-app' );

		await expect(
			app.getByRole( 'heading', { name: 'Trends' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'heading', { name: "What's new" } )
		).toBeVisible();
		await expect(
			app.getByRole( 'combobox', { name: 'Kind of change' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'checkbox', {
				name: /Send a weekly summary by e-mail/,
			} )
		).toBeVisible();
	} );

	test( 'a site sheet has a History tab', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites&s=Blog%20RH'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Blog RH', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		await panel.getByRole( 'tab', { name: 'History' } ).click();

		await expect(
			panel.getByRole( 'heading', { name: 'Changes' } )
		).toBeVisible();
		await expect(
			panel.getByRole( 'combobox', { name: 'Period' } )
		).toBeVisible();
	} );

	test( 'the overview shows the recent changes', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/admin.php', 'page=multisite-radar' );

		await expect(
			page.getByRole( 'heading', { name: 'Recent changes' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'See all changes' } )
		).toHaveAttribute( 'href', /multisite-radar-reports/ );
	} );
} );
```

Le deuxième test ouvre la fiche comme `tests/e2e/specs/journey.spec.js` (recherche, puis clic sur le nom).

Dans `tests/e2e/specs/a11y.spec.js`, ajouter `'page=multisite-radar-reports',` à `PAGES`, avant `'page=multisite-radar-settings'`.

Dans `bin/e2e.sh`, juste avant `echo "E2E OK"`, la désinstallation du plugin **copié** dans le WordPress jetable (jamais celle du WordPress de développement) :

```bash
# Désinstallation (uninstall.php) : les tables et options du plugin disparaissent. --skip-delete garde les fichiers.
wpe plugin deactivate multisite-radar --network >/dev/null
wpe plugin uninstall multisite-radar --skip-delete >/dev/null
expect "uninstalling drops the tables of the plugin" \
	"$(wpe eval 'global $wpdb; echo count( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->base_prefix . "msradar_" ) . "%" ) ) );')" "0"
expect "uninstalling deletes the settings of the plugin" "$(wpe site option get msradar_settings >/dev/null 2>&1 && echo kept || echo deleted)" "deleted"
```

- [ ] **Step 2: Run them**

Run: `shellcheck bin/e2e.sh`, puis la commande `bin/e2e.sh` de la section « Commandes ».
Expected: toutes les lignes `ok - …`, dont les deux de la désinstallation, puis `E2E OK`.

Run (environnement E2E) : `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 && npm run build && npm run wp-env -- start && npm run e2e:setup && npm run test:e2e -- tests/e2e/specs/history.spec.js tests/e2e/specs/a11y.spec.js`
Expected: PASS, aucune violation « serious » ou « critical » sur la page Rapports.

Si l'audit d'accessibilité relève une violation dans `TrendChart` ou `EventsList`, la corriger dans le composant (tâche 9) plutôt que d'exclure la règle.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/specs/ bin/e2e.sh src/
git commit -m "test: cover the reports page, the history tab and the uninstall end to end"
```

## Partie E — Livraison

### Task 13: Version 2.0.0-beta.6, traductions, documentation et recette du jalon

**Files:**
- Modify: `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php` (par `make version`)
- Modify: `languages/multisite-radar.pot`, `languages/multisite-radar-fr_FR.po` (et les fichiers générés par `make i18n`)
- Modify: `CHANGELOG.md`, `readme.txt`, `README.md`
- Modify: `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`

**Interfaces:**
- Consumes : tout le jalon.
- Produces : la version 2.0.0-beta.6, cohérente partout (`npm run version:check`), traduite en français, avec une recette verte.

- [ ] **Step 1: Set the version**

Run: `make version VERSION=2.0.0-beta.6`
Expected : en-tête, `MSRADAR_VERSION`, `readme.txt` (`Stable tag`), `package.json`, `package-lock.json` et `tests/phpstan-bootstrap.php` passent en 2.0.0-beta.6 ; `npm run version:check` le confirme.

- [ ] **Step 2: Translate the new strings**

Run: `npm run build && make i18n`
Expected : la commande s'arrête et liste les chaînes non traduites du jalon.

Les traduire dans `languages/multisite-radar-fr_FR.po`, puis relancer `make i18n` jusqu'à zéro chaîne manquante. Règles :
- typographie de WordPress en français : apostrophe typographique `’`, espace insécable (U+00A0) avant `:`, `;`, `!` et `?`, guillemets « » avec espaces insécables à l'intérieur, points de suspension `…` en un seul caractère ;
- garder chaque marqueur (`%s`, `%d`, `%1$s`…) : `tests/php/TranslationsTest.php` le vérifie ;
- « plugin » se traduit « extension », « snapshot / daily figures » se traduit « relevé quotidien », comme dans la traduction déjà livrée ;
- les noms techniques restent tels quels (`multisite-radar/recent-changes`, MCP, `2026-09-01T00:00:00`) ;
- le glossaire ci-dessous.

| Anglais | Français |
|---|---|
| Reports / History / Trends / Period | Rapports / Historique / Tendances / Période |
| Recent changes / See all changes / What's new | Changements récents / Voir tous les changements / Quoi de neuf |
| Kind of change / All changes / Change / Whole network | Type de changement / Tous les changements / Changement / Tout le réseau |
| Sites created / Sites deleted / Plugins activated / Plugins deactivated / Themes switched / New alerts / Resolved alerts | Sites créés / Sites supprimés / Extensions activées / Extensions désactivées / Thèmes changés / Nouvelles alertes / Alertes résolues |
| Site created. / Site deleted. | Site créé. / Site supprimé. |
| Plugin %s activated. / Plugin %s network activated. | Extension %s activée. / Extension %s activée sur le réseau. |
| Plugin %s deactivated. / Plugin %s network deactivated. | Extension %s désactivée. / Extension %s désactivée sur le réseau. |
| Theme switched to %s. / Theme switched from %1$s to %2$s. | Thème changé pour %s. / Thème %1$s remplacé par %2$s. |
| New alert: %s. / Alert resolved: %s. | Nouvelle alerte : %s. / Alerte résolue : %s. |
| No change recorded yet. | Aucun changement enregistré pour l’instant. |
| Not enough history yet: the chart appears after two daily snapshots. | Pas encore assez d’historique : le graphique apparaît après deux relevés quotidiens. |
| %1$s, from %2$s to %3$s. Latest: %4$s. | %1$s, du %2$s au %3$s. Dernières valeurs : %4$s. |
| Show the data / Day | Afficher les données / Jour |
| Last %d day / Last %d days | %d dernier jour / %d derniers jours |
| Content and media / Sites by highest alert / Disk and database / Alerts, last 30 days | Contenus et médias / Sites par alerte la plus grave / Disque et base de données / Alertes, 30 derniers jours |
| Weekly summary / Send a weekly summary by e-mail / Day of the week | Récapitulatif hebdomadaire / Envoyer un récapitulatif hebdomadaire par e-mail / Jour de la semaine |
| Recipients / All super admins / The addresses below / E-mail addresses | Destinataires / Tous les super-administrateurs / Les adresses ci-dessous / Adresses e-mail |
| Send a test e-mail to me / The test e-mail was sent to your address. | M’envoyer un e-mail de test / L’e-mail de test a été envoyé à votre adresse. |
| Keep the changes for (days) / Keep the daily figures for (days) | Conserver les changements pendant (jours) / Conserver les relevés quotidiens pendant (jours) |
| Multisite Radar summary for %s / [%s] Weekly summary from Multisite Radar | Récapitulatif Multisite Radar pour %s / [%s] Récapitulatif hebdomadaire de Multisite Radar |
| %s new alert / %s resolved alert / Other changes / No other change. | %s nouvelle alerte / %s alerte résolue / Autres changements / Aucun autre changement. |
| Changes of the last %d day. / … days. | Changements du dernier jour (%d). / Changements des %d derniers jours. |
| %1$s: %2$s | %1$s : %2$s |
| Open Multisite Radar / Open Multisite Radar: %s / and %s more | Ouvrir Multisite Radar / Ouvrir Multisite Radar : %s / et %s de plus |
| Main alerts / No alert on the network. / Sites with an error / Sites with a warning | Principales alertes / Aucune alerte sur le réseau. / Sites en erreur / Sites avec un avertissement |

Les autres chaînes (descriptions de l'ability et de ses paramètres, texte de confidentialité, messages d'erreur) se traduisent dans le même registre, phrase à phrase, sans rien retirer. Ensuite :

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 3: Update the documentation**

`CHANGELOG.md`, nouvelle section en tête (après le titre) :

```markdown
## [2.0.0-beta.6] - <date du jour, AAAA-MM-JJ>

### Jalon M6 — Historique et rapports
- Journal des changements du réseau : sites créés ou supprimés, extensions activées ou désactivées (sur un site ou sur le réseau), thème changé, alertes apparues ou résolues. La première analyse d'un site sert de référence.
- Relevé quotidien des mesures de chaque site ; journal et relevés purgés au-delà de la rétention réglée (90 et 365 jours par défaut, carte « Historique » des réglages).
- Nouvelle page « Rapports » : tendances du réseau (sites, contenus et médias, sites par alerte la plus grave), « Quoi de neuf », réglage du récapitulatif hebdomadaire par e-mail et envoi d'un e-mail de test.
- Fiche d'un site : onglet « Historique » (tendances et derniers changements). Vue d'ensemble : derniers changements et alertes des 30 derniers jours.
- Widget « Multisite Radar » sur le tableau de bord réseau.
- REST : `GET /events`, `GET /reports/trends`, `POST /reports/digest/test`. Ability `multisite-radar/recent-changes`.
- Schéma version 4 (tables `msradar_events` et `msradar_snapshots`) ; la mise à jour depuis la 2.0.0-beta.5 ne relance pas d'analyse.
```

`readme.txt` :
- dans `== Description ==`, avant la ligne `Source code: …`, ajouter :

```
Multisite Radar also keeps the history of the network: a journal of changes (sites, plugins, themes, alerts), daily figures of each site with trend charts, an optional weekly e-mail summary and a widget on the network dashboard.
```

- dans `== Changelog ==`, avant `= 2.0.0-beta.5 =` :

```
= 2.0.0-beta.6 =
* History: journal of changes, daily figures and trend charts for the network and for each site.
* New Reports page, weekly e-mail summary and network dashboard widget.
```

`README.md` :
- la ligne « Pages du menu réseau … » cite désormais : Vue d'ensemble, Sites, Plugins, Thèmes, Utilisateurs, Alertes, Rapports, Réglages ;
- dans le paragraphe des abilities, ajouter `recent-changes` à la liste ;
- ajouter, après le tableau des commandes :

```markdown
Historique : le journal des changements (`msradar_events`) et les relevés quotidiens (`msradar_snapshots`) sont écrits par les analyses et par la tâche quotidienne `msradar_daily`, puis purgés selon la rétention des réglages. Le récapitulatif hebdomadaire part le jour réglé, un e-mail par destinataire.
```

- [ ] **Step 4: Update the follow-up document**

Dans `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md` :
- dans « Déjà traité depuis M2 », ajouter les points traités par ce plan (tableau « Points reportés traités dans ce plan »), avec la mention « (2.0.0-beta.6, plan M6) » ;
- les retirer des sections où ils figuraient (dont l'ability différée de la section 5) ;
- ajouter en fin de document une section `## 6. Reportés par le plan M6`, avec les points relevés pendant l'exécution de ce plan et laissés pour plus tard (registre de l'exécution, revue finale).

- [ ] **Step 5: Run the full check**

Run: `make check && npm run version:check && make dist && unzip -l dist/multisite-radar-2.0.0-beta.6.zip | grep -E 'includes/(Storage/EventsRepository|Storage/SnapshotsRepository|Scan/ChangeLog|Reports/Digest|Reports/DashboardWidget)\.php|build/admin/reports\.js|languages/.*\.json'`
Expected :
- lint et tests verts (hors les 11 échecs connus de la base de test, s'ils sont encore là) ;
- versions cohérentes ;
- le zip contient les cinq classes, le bundle `reports` et les fichiers JSON de traduction.

Run : la suite PHP sur tables neuves (section « Commandes »).
Expected : verte.

Run : la commande `bin/e2e.sh` de la section « Commandes ».
Expected : `E2E OK`.

Run (environnement E2E) : `npm run test:e2e`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add multisite-radar.php readme.txt package.json package-lock.json tests/phpstan-bootstrap.php languages/ CHANGELOG.md README.md docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md
git commit -m "chore: release 2.0.0-beta.6"
```
