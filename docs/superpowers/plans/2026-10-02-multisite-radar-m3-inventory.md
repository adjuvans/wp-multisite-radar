# Multisite Radar — Jalon M3 (Inventaire croisé) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la 2.0.0-beta.3 de Multisite Radar. Elle ajoute l'inventaire croisé du réseau :
- les pages Plugins, Thèmes et Utilisateurs, avec leurs routes REST ;
- pour un plugin ou un thème, le panneau des sites qui l'utilisent ;
- les tuiles « plugins inutilisés », « thèmes inutilisés » et « mises à jour disponibles » de la Vue d'ensemble ;
- les exports CSV et JSON des plugins et des thèmes ;
- les points reportés de M2 qui touchent ces surfaces, dont le chunk DataViews partagé entre les vues.

**Architecture :**
- **PHP :** trois services de lecture, `Query\PluginsQuery`, `Query\ThemesQuery` et `Query\UsersQuery`, plus `Query\InventoryQuery` pour la synthèse. Ce sont eux que REST, les exports et, plus tard, WP-CLI (M5) et les Abilities (M5) utilisent. Les contrôleurs restent minces.
- **Plugins et thèmes :**
  - la liste vient de `get_plugins()` et `wp_get_themes()`, quelques centaines d'éléments au plus ;
  - le nombre de sites vient d'agrégats SQL sur `msradar_site_extensions` et `msradar_sites`, limités au réseau courant ;
  - filtre, tri et pagination se font en mémoire ;
  - les sites d'un plugin ou d'un thème passent par `SitesQuery::list()` avec ses filtres `plugin` et `theme`, déjà en place depuis M1. Les comptes et la liste des sites disent donc la même chose.
- **Utilisateurs :** une requête SQL paginée sur les tables globales `users`, `usermeta` et `blogs`, mise en cache objet 10 minutes. La clé du cache dépend de `last_changed` des groupes `users` et `sites`.
- **JavaScript :**
  - trois nouvelles vues, avec un point d'entrée webpack chacune ;
  - un panneau latéral générique (extrait de la fiche site) pour lister les sites d'un plugin ou d'un thème ;
  - des squelettes de chargement ;
  - DataViews sort dans un chunk webpack partagé, `admin/dataviews`, que le navigateur garde en cache d'une page à l'autre.

**Tech stack :** inchangée depuis M2.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6.
- Node 24, `@wordpress/scripts` 36.0.0 (webpack 5, ESLint, Stylelint, Vitest 5), React 18.3, `@wordpress/dataviews` 19.1.0 (version épinglée).
- Playwright + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`. Pour M3, les sections utiles sont :
- §2.2 ;
- §5.1 (routes M3) et §5.2 ;
- §6.1, §6.2 (Vue d'ensemble, Plugins, Thèmes, Utilisateurs), §6.3 et §6.4 ;
- §7.1 ;
- §9 ;
- §11.2.

**Entrée complémentaire :** `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`. Les points de M2 repris ici sont listés plus bas.

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
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder.
- **SQL :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants, y compris les tables globales (`$wpdb->users`, `$wpdb->usermeta`, `$wpdb->blogs`) ;
  - listes `IN` via `implode( ',', array_fill( 0, count( $x ), '%d' ) )` ;
  - aucune fonction JSON côté SQL ;
  - toute requête directe vit dans `includes/Storage/` (PHPCS y autorise les requêtes directes) ;
  - `// phpcs:ignore <code exact> -- <raison>` sur une ligne signalée qui suit ces motifs ; ne jamais désactiver une règle dans `phpcs.xml.dist`.
- **Motifs PHP :** tout `preg_match` qui valide une entrée se termine par `\z`, jamais par `$`, qui accepte un saut de ligne final.
- **Style PHP :**
  - WPCS ;
  - `defined( 'ABSPATH' ) || exit;` en tête de chaque fichier sous `includes/` ;
  - tableaux courts autorisés.
- **Erreurs :**
  - les dépôts (`Storage/*`) lèvent `\RuntimeException` quand une lecture échoue ;
  - les contrôleurs REST passent par `Controller::guard()`, qui renvoie une erreur 500 ;
  - les gestionnaires de hooks déclenchent `do_action( 'msradar_error', <contexte>, $error )` ;
  - aucune exception ne doit remonter dans un hook du cœur.
- **Dates :**
  - stockées en GMT (`Y-m-d H:i:s`) ;
  - exposées en REST dans les champs `*_gmt` au format `Y-m-d\TH:i:s`, en UTC et sans décalage (convention du cœur) ;
  - côté JS, toujours lues par `parseGmt()` (`src/utils/format.js`).
- **Données personnelles :** aucune adresse e-mail dans une réponse REST, un export ou l'interface. Un compte est identifié par son ID, son login et son nom affiché.
- **Aucun appel HTTP externe :** les mises à jour disponibles sont lues dans les transients réseau `update_plugins` et `update_themes`, tels que WordPress les a laissés.
- **JS, fichiers :**
  - sources dans `src/` en modules ES ;
  - extension `.jsx` pour tout fichier qui contient du JSX, `.js` sinon ;
  - imports sans extension.
- **JS, styles :** les feuilles de style ne sont importées que par les points d'entrée (`src/admin/<vue>.js`, `src/blocks/*/index.js`), jamais par un composant, car Vitest ne les traite pas.
- **DataViews :**
  - `@wordpress/dataviews` est épinglé à `19.1.0` ;
  - ses composants sont importés uniquement dans `src/components/data-views/index.js`, depuis `@wordpress/dataviews/wp` ;
  - le reste du code importe cet adaptateur ;
  - les fonctions pures sur les filtres vivent dans `src/components/data-views/filters.js` ;
  - seule exception : sa feuille de style `@wordpress/dataviews/build-style/style.css`, importée par chaque point d'entrée qui affiche DataViews (`sites`, `alerts`, `settings`, `plugins`, `themes`, `users`). Webpack la place dans le chunk partagé.
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
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur `multisite-radar` : le dossier du plugin est un lien symbolique vers le dépôt ;
  - ne jamais afficher le mot de passe de la base : `bin/test.sh` le lit à la volée ;
  - le port 8888 est occupé dans la VM : lancer wp-env et les tests E2E avec `WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890`.

## Écarts assumés par rapport à la spec

Chaque écart sert l'intention de la spec. Les exécutants les appliquent tels quels.

| # | Spec | Décision M3 | Raison |
|---|---|---|---|
| E1 | `GET /plugins/{file}/sites` | `{file}` est l'**identifiant public** du plugin : son fichier sans `.php` (`akismet/akismet`, `hello`), comme la route `wp/v2/plugins` du cœur | Certains serveurs confient à PHP toute URL qui contient `.php/`. |
| E2 | Routes du §5.1 | Ajout de **`GET /inventory/summary`** : compteurs des plugins et des thèmes, et nombre de sites pas encore analysés | Les tuiles de la Vue d'ensemble et l'avis des pages d'inventaire en ont besoin, en une seule requête préchargée. |
| E3 | `GET /users` : « nombre de sites, rattachés à aucun site » | Les sites sont comptés **sur toute l'installation**, tous réseaux confondus, comme l'écran Utilisateurs du réseau dans WordPress. Seuls comptent les sites qui existent encore dans `blogs` | Avec plusieurs réseaux, un comptage limité au réseau courant ferait passer les comptes d'un autre réseau pour « rattachés à aucun site », ce qui invite à les supprimer. |
| E4 | Utilisateurs : recherche | Recherche sur le **login et le nom affiché** seulement | Aucune adresse e-mail n'est lue ni renvoyée (principe E12 de M2). |
| E5 | Plugins : « MU-plugins et drop-ins exclus » de « inutilisé » | MU-plugins et drop-ins sont **exclus de la liste** | Ils ne s'activent pas site par site : leur nombre de sites n'a pas de sens. |
| E6 | « clic → panneau des sites » (§6.2) | L'état du panneau des sites d'un plugin ou d'un thème **n'est pas dans l'URL** | Le §6.1 ne synchronise que le site ouvert. Le panneau se recharge en un clic et n'a pas besoin de préchargement. |
| E7 | Thème : nombre de sites | `sites_count` = sites où le thème est **actif** + sites où il est le **parent du thème actif**, avec le détail (`active_count`, `parent_count`) | C'est exactement ce que liste `GET /themes/{stylesheet}/sites` (filtre `theme` de `SitesQuery`) : un thème parent d'un thème actif n'est pas inutilisé (§7.1). |
| E8 | Inventaire | Seuls les **sites analysés** comptent. Les pages Plugins et Thèmes et la Vue d'ensemble signalent les sites en attente d'analyse | Avant la première analyse, un plugin actif ne serait vu nulle part et passerait pour inutilisé. |
| E9 | Tuiles « plugins inutilisés, mises à jour en attente » (§6.2, reportées par l'écart E10 de M2) | Trois tuiles : **plugins inutilisés**, **thèmes inutilisés**, **mises à jour disponibles** (plugins + thèmes) | Le §7.1 définit le thème inutilisé ; il a sa place à côté du plugin inutilisé. |
| E10 | Exports (§5.2) | Ressources `plugins` et `themes` ajoutées ; **pas d'export des utilisateurs** | Le §5.2 ne prévoit que `sites`, `plugins` et `themes`. |
| E11 | Écart E2 de M2 (un point d'entrée par vue) | Conservé, mais le code de `node_modules` commun aux vues qui affichent DataViews sort dans **un chunk partagé `admin/dataviews`** | Avec six vues DataViews, chaque page retéléchargerait environ 2 Mo (point du document des suites de M2). |
| E12 | « squelettes de chargement plutôt que spinners » (§6.4) | Squelette dans les listes sans données, dans les panneaux et dans l'onglet Utilisateurs de la fiche. Le spinner de DataViews n'est plus utilisé | Point reporté de M2. |

## Points reportés de M2 traités dans ce plan

| Point (document des suites de M2) | Tâche |
|---|---|
| DataViews dupliqué dans chaque vue | 1 |
| Outillage : `webpack.config.js` hors de `lint:js`, erreur Prettier, remplacement de `DependencyExtractionWebpackPlugin` non vérifié | 1 |
| Rôles affichés en identifiants bruts | 5 |
| `SiteUsersQuery` : `is_super_admin()` par utilisateur, tri sans départage par `ID` | 5 |
| Export interrompu en cours de flux sans marqueur visible | 7 |
| `RuleRegistry::ID_PATTERN` et `^\d+$` acceptent un saut de ligne final | 8 |
| `ViewQuery::path()` garde les valeurs vides | 8 |
| Pas de squelettes de chargement | 9 |
| Accessibilité : commentaire de l'exclusion axe de la page Réglages | 15 |
| `npm run plugin-zip` ajoute `package.json` et `README.md` au zip | 16 |

Les autres points du document des suites restent pour plus tard. La tâche 16 met le document à jour.

## Review Focus

Les cinq situations que la spec implique sans que ses exemples les montrent, et qui gêneraient le plus un utilisateur. Chacune a son test dans la tâche indiquée.

1. **Inventaire ouvert avant la fin de la première analyse :**
   - le comportement attendu : un plugin actif sur des sites pas encore analysés y apparaît comme inutilisé, mais les pages Plugins et Thèmes et la Vue d'ensemble annoncent combien de sites restent à analyser ;
   - pourquoi c'est un risque : un super-admin supprimerait un plugin « inutilisé » qui sert encore ;
   - tests : tâche 6 (`pending_sites`), tâches 11 et 12 (avis affiché).
2. **Plugin dont le dossier ou le fichier contient une espace, un point, un `+`, un accent, ou plugin en un seul fichier (`hello.php`) :**
   - le comportement attendu : le panneau demande et affiche les sites du bon plugin ;
   - tests : tâche 2 (route REST avec `my plugin/my.plugin` et `hello`), tâche 11 (construction du chemin).
3. **Installation à plusieurs réseaux :**
   - le comportement attendu : les comptes de plugins et de thèmes du réseau A n'incluent aucun site du réseau B ; les comptes de sites des utilisateurs couvrent tous les réseaux (E3), car la requête ne filtre que sur l'existence du site dans `blogs` ;
   - tests : tâches 2 et 3 (une ligne d'un autre réseau n'est pas comptée), tâche 4 (la clé d'un site qui n'existe plus n'est pas comptée).
4. **Liste des super-admins vide, ou qui contient un login supprimé :**
   - le comportement attendu : le filtre « Super admins » renvoie une liste vide ou les seuls comptes existants, jamais tous les comptes ;
   - test : tâche 4.
5. **Thème parent et thème enfant :**
   - le comportement attendu : un thème utilisé seulement comme parent n'est pas « inutilisé » ; un thème enfant actif dont le parent a disparu fait apparaître ce parent comme « Not installed » ;
   - test : tâche 3.

Autres pièges couverts par des tests :
- un plugin activé sur le réseau et aussi activé site par site : statut « réseau », nombre de sites = tous les sites du réseau (tâche 2) ;
- un nom de plugin ou de thème avec entités HTML ou balises : texte brut (tâches 2 et 3) ;
- un nom de plugin commençant par `=` dans un export CSV : neutralisé (tâche 7) ;
- une méta `wp_2_foo_capabilities` ou la clé d'un site supprimé : non comptées comme appartenance (tâche 4) ;
- un plugin ni installé ni actif nulle part : 404 sur ses sites (tâche 2) ;
- un chunk partagé absent du build : avis « fichiers manquants » au lieu d'une page blanche (tâche 1).

## Structure des fichiers

**PHP, créés :**
- `includes/Query/InventoryList.php` — filtre texte, tri et pagination en mémoire, communs aux plugins et aux thèmes.
- `includes/Query/PluginsQuery.php` — plugins installés ou encore actifs, statut, nombre de sites, mises à jour.
- `includes/Query/ThemesQuery.php` — thèmes installés ou encore utilisés, parent, autorisation réseau, nombre de sites, mises à jour.
- `includes/Query/UsersQuery.php` — comptes de l'installation avec leur nombre de sites, cache objet.
- `includes/Query/InventoryQuery.php` — synthèse pour la Vue d'ensemble.
- `includes/Storage/UsersRepository.php` — SQL des comptes et de leurs appartenances.
- `includes/Rest/PluginsController.php`, `includes/Rest/ThemesController.php`, `includes/Rest/UsersController.php`, `includes/Rest/InventoryController.php`.
- `includes/Export/ExportSource.php` (interface), `includes/Export/SitesExport.php`, `includes/Export/PluginsExport.php`, `includes/Export/ThemesExport.php`.

**PHP, modifiés :**
- `includes/Plugin.php` ;
- `includes/Storage/ExtensionsRepository.php`, `includes/Storage/SitesRepository.php` ;
- `includes/Query/SiteUsersQuery.php` ;
- `includes/Export/ExportHandler.php`, `includes/Export/JsonWriter.php` ;
- `includes/Admin/Assets.php`, `includes/Admin/Menu.php`, `includes/Admin/Preload.php`, `includes/Admin/ViewQuery.php` ;
- `includes/Settings/Preferences.php` ;
- `includes/Alerts/RuleRegistry.php`, `includes/Rest/AlertsController.php` ;
- `CHANGELOG.md`, `readme.txt`, `README.md`, `multisite-radar.php`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php`.

**JS et outillage :**
- **Modifiés :** `webpack.config.js`, `package.json` (`lint:js`, `plugin-zip`), `src/admin/style.scss`, `src/admin/config.js`, `src/hooks/use-preferences.js`, `src/views/sites/index.jsx`, `src/views/sites/export.js`, `src/views/alerts/index.jsx`, `src/views/site-panel/index.jsx`, `src/views/site-panel/users-tab.jsx`, `src/views/overview/index.jsx`, `src/views/overview/tiles.jsx`.
- **Créés, `src/components/`** : `skeleton.jsx`, `side-panel.jsx`, `extension-sites-panel.jsx`, `export-menu.jsx`.
- **Créés, `src/utils/`** : `export.js`.
- **Créés, points d'entrée :** `src/admin/plugins.js`, `src/admin/themes.js`, `src/admin/users.js`.
- **Créés, vues :**
  - `src/views/plugins/` : `index.jsx`, `fields.jsx`, `query.js` ;
  - `src/views/themes/` : `index.jsx`, `fields.jsx`, `query.js` ;
  - `src/views/users/` : `index.jsx`, `fields.jsx`, `query.js`.
- **Supprimé :** `src/views/sites/export-menu.jsx`, remplacé par `src/components/export-menu.jsx`.
- **Tests :** `tests/fixtures/view-queries.json` (nouveaux cas), `tests/php/**` (voir chaque tâche), `src/**/test/*.test.js(x)`, `tests/e2e/specs/*.spec.js`.

## Commandes

| Rôle | Commande |
|---|---|
| Tests PHP (tout, ou un filtre) | `bin/test.sh` ; `bin/test.sh --filter PluginsQueryTest` |
| Normes PHP / analyse statique | `composer lint` ; `composer analyse` |
| Syntaxe PHP 7.4 | `docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in multisite-radar.php uninstall.php $(find includes -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'` |
| Tests JS | `npm run test:unit` (ou `npx vitest run src/views/plugins`) |
| Lint JS / CSS | `npm run lint:js` ; `npm run lint:css` |
| Build | `npm run build` |
| Traductions | `make i18n` (après un build) |
| Versions synchronisées | `npm run version:check` |
| E2E | `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` puis `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e` |

Chaque tâche se termine par les tests de son périmètre, puis `composer lint` et `composer analyse` (pour le PHP), ou `npm run lint:js` et `npm run test:unit` (pour le JS), avant le commit. Le travail se fait sur une branche `m3` créée depuis `main`.

---

## Partie A — Socle

### Task 1: Chunk DataViews partagé entre les vues

Aujourd'hui, `build/admin/{sites,alerts,settings}.js` pèsent environ 2 Mo chacun et contiennent tous la même copie de DataViews. Cette tâche sort tout le code de `node_modules` commun à ces vues dans `build/admin/dataviews.js` (et `dataviews.css`). PHP enregistre ce fichier comme dépendance de chaque vue qui affiche DataViews.

Un build d'essai a vérifié ce comportement le 2026-10-02 :
- `dataviews.js` ≈ 2,0 Mo, `sites.js` ≈ 32 Ko ;
- `@wordpress/dependency-extraction-webpack-plugin` 6.56 produit `dataviews.asset.php`, dont la liste de dépendances est vide (les modules externes `wp.*` restent dans chaque vue) et dont la version est un hash du contenu ;
- le chunk est un chunk JSONP (`globalThis.webpackChunkmultisite_radar`) : la vue démarre une fois que son script et celui du chunk sont chargés.

**Files:**
- Modify: `webpack.config.js`
- Modify: `includes/Admin/Assets.php`
- Modify: `package.json` (script `lint:js`)
- Create: `tests/php/fixtures/build/admin/dataviews.asset.php`, `tests/php/fixtures/build/admin/dataviews.css`, `tests/php/fixtures/build/admin/overview.asset.php`
- Test: `tests/php/Admin/AssetsTest.php`

**Interfaces:**
- Produces :
  - `Assets::SHARED_CHUNK = 'dataviews'` (fichier `build/admin/dataviews.js`) ;
  - `Assets::SHARED_HANDLE = 'msradar-dataviews'` (script et style) ;
  - `Assets::LIGHT_VIEWS = [ 'overview' ]`, les vues qui ne chargent pas le chunk ;
  - dans `webpack.config.js` : `VIEWS` (les tâches 11 à 13 y ajoutent `plugins`, `themes`, `users`) et `LIGHT_VIEWS`, même liste que `Assets::LIGHT_VIEWS`.

- [ ] **Step 1: Write the failing tests**

Créer les fixtures :

`tests/php/fixtures/build/admin/dataviews.asset.php` :

```php
<?php return [ 'dependencies' => [], 'version' => 'shared' ];
```

`tests/php/fixtures/build/admin/dataviews.css` :

```css
/* Fixture : feuille du chunk partagé. */
```

`tests/php/fixtures/build/admin/overview.asset.php` :

```php
<?php return [ 'dependencies' => [ 'wp-api-fetch' ], 'version' => 'light' ];
```

Dans `tests/php/Admin/AssetsTest.php` :

1. Remplacer `tear_down()` par :

```php
	public function tear_down(): void {
		foreach ( [ 'msradar-overview', 'msradar-sites', 'msradar-alerts', 'msradar-settings', Assets::SHARED_HANDLE ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		parent::tear_down();
	}
```

2. Dans `test_enqueues_the_view_bundle_with_its_safe_inline_configuration()`, remplacer les deux assertions de dépendances par :

```php
		$this->assertSame( [ 'react', 'wp-api-fetch', Assets::SHARED_HANDLE, 'wp-i18n' ], wp_scripts()->registered['msradar-sites']->deps );
```

et

```php
		$this->assertSame( [ 'wp-components', Assets::SHARED_HANDLE ], wp_styles()->registered['msradar-sites']->deps );
```

3. Ajouter :

```php
	public function test_views_with_dataviews_load_the_shared_chunk_first(): void {
		$this->login_as_super_admin();

		$this->assertTrue( $this->assets()->enqueue_view( 'sites' ) );

		$chunk = wp_scripts()->registered[ Assets::SHARED_HANDLE ];
		$this->assertSame( 'https://example.test/build/admin/dataviews.js', $chunk->src );
		$this->assertSame( 'shared', $chunk->ver );
		$this->assertSame( 'multisite-radar', $chunk->textdomain, 'The DataViews strings live in the shared chunk.' );
		$this->assertSame( MSRADAR_DIR . 'languages', $chunk->translations_path );
		$this->assertSame( 'https://example.test/build/admin/dataviews.css', wp_styles()->registered[ Assets::SHARED_HANDLE ]->src );
		$this->assertSame( 'replace', wp_styles()->get_data( Assets::SHARED_HANDLE, 'rtl' ) );
		$this->assertTrue( wp_style_is( Assets::SHARED_HANDLE, 'enqueued' ) );
	}

	public function test_the_overview_does_not_load_the_shared_chunk(): void {
		$this->login_as_super_admin();

		$this->assertTrue( $this->assets()->enqueue_view( 'overview' ) );

		$this->assertSame( [ 'wp-api-fetch', 'wp-i18n' ], wp_scripts()->registered['msradar-overview']->deps );
		$this->assertFalse( wp_script_is( Assets::SHARED_HANDLE, 'registered' ) );
	}

	public function test_a_build_without_the_shared_chunk_shows_the_missing_build_notice(): void {
		$dir = trailingslashit( get_temp_dir() ) . 'msradar-build-' . wp_generate_password( 8, false, false ) . '/';
		wp_mkdir_p( $dir . 'admin' );
		copy( dirname( __DIR__ ) . '/fixtures/build/admin/sites.asset.php', $dir . 'admin/sites.asset.php' );
		try {
			$assets = new Assets( new Menu(), $this->plugin()->preferences(), $dir, 'https://example.test/build/' );

			$this->assertFalse( $assets->enqueue_view( 'sites' ) );
			$this->assertFalse( wp_script_is( 'msradar-sites', 'enqueued' ) );
			$this->assertSame( 10, has_action( 'network_admin_notices', [ $assets, 'render_missing_build_notice' ] ) );
		} finally {
			unlink( $dir . 'admin/sites.asset.php' );
			rmdir( $dir . 'admin' );
			rmdir( $dir );
		}
	}
```

4. Dans `test_the_bundled_dataviews_strings_use_the_plugin_translations()`, ajouter avant la première assertion sur `SHARE_TRANSLATIONS` :

```php
		$this->assertSame( 'multisite-radar', wp_scripts()->registered[ Assets::SHARED_HANDLE ]->textdomain );
```

La recopie `SHARE_TRANSLATIONS` reste sur le script de la vue : WordPress imprime les traductions du chunk (sa dépendance) avant celles de la vue, puis la recopie, puis le script de la vue, qui exécute le code de DataViews. Toutes les chaînes sont donc chargées au moment de la recopie.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter AssetsTest`
Expected: FAIL. `Assets::SHARED_HANDLE` n'existe pas (« Undefined constant »).

- [ ] **Step 3: Implement in `includes/Admin/Assets.php`**

Ajouter les constantes après `SHARE_TRANSLATIONS` :

```php
	/**
	 * Chunk webpack commun aux vues qui affichent DataViews (webpack.config.js) : DataViews et ses dépendances,
	 * environ 2 Mo, téléchargés une fois puis servis par le cache du navigateur sur les autres pages.
	 */
	public const SHARED_CHUNK  = 'dataviews';
	public const SHARED_HANDLE = 'msradar-dataviews';

	/**
	 * Vues sans DataViews, qui ne chargent pas le chunk commun (LIGHT_VIEWS dans webpack.config.js).
	 */
	public const LIGHT_VIEWS = [ 'overview' ];
```

Remplacer `enqueue_view()` par :

```php
	public function enqueue_view( string $view ): bool {
		$asset_file = $this->build_dir . 'admin/' . $view . '.asset.php';
		$shared     = ! in_array( $view, self::LIGHT_VIEWS, true );
		if ( ! is_readable( $asset_file ) || ( $shared && ! $this->register_shared_chunk() ) ) {
			add_action( 'network_admin_notices', [ $this, 'render_missing_build_notice' ] );
			return false;
		}
		$asset        = (array) require $asset_file;
		$handle       = 'msradar-' . $view;
		$version      = (string) ( $asset['version'] ?? MSRADAR_VERSION );
		$dependencies = (array) ( $asset['dependencies'] ?? [] );
		$style_deps   = [ 'wp-components' ];
		if ( $shared ) {
			$dependencies[] = self::SHARED_HANDLE;
			if ( wp_style_is( self::SHARED_HANDLE, 'registered' ) ) {
				$style_deps[] = self::SHARED_HANDLE;
				wp_enqueue_style( self::SHARED_HANDLE );
			}
		}

		wp_enqueue_script( $handle, $this->build_url . 'admin/' . $view . '.js', $dependencies, $version, true );
		wp_set_script_translations( $handle, 'multisite-radar', MSRADAR_DIR . 'languages' );
		wp_add_inline_script( $handle, self::SHARE_TRANSLATIONS, 'before' );
		wp_add_inline_script( $handle, 'window.msradarAdmin = ' . wp_json_encode( $this->config( $view ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );

		if ( is_readable( $this->build_dir . 'admin/' . $view . '.css' ) ) {
			wp_enqueue_style( $handle, $this->build_url . 'admin/' . $view . '.css', $style_deps, $version );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
		return true;
	}

	/**
	 * Enregistre le chunk commun (script, traductions, feuille de style) une seule fois par page.
	 *
	 * @return bool Faux si le build ne contient pas le chunk.
	 */
	private function register_shared_chunk(): bool {
		if ( wp_script_is( self::SHARED_HANDLE, 'registered' ) ) {
			return true;
		}
		$asset_file = $this->build_dir . 'admin/' . self::SHARED_CHUNK . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset   = (array) require $asset_file;
		$version = (string) ( $asset['version'] ?? MSRADAR_VERSION );

		wp_register_script( self::SHARED_HANDLE, $this->build_url . 'admin/' . self::SHARED_CHUNK . '.js', (array) ( $asset['dependencies'] ?? [] ), $version, true );
		// Les chaînes de DataViews sont dans ce fichier : bin/i18n.sh produit leur JSON sous son nom.
		wp_set_script_translations( self::SHARED_HANDLE, 'multisite-radar', MSRADAR_DIR . 'languages' );
		if ( is_readable( $this->build_dir . 'admin/' . self::SHARED_CHUNK . '.css' ) ) {
			wp_register_style( self::SHARED_HANDLE, $this->build_url . 'admin/' . self::SHARED_CHUNK . '.css', [ 'wp-components' ], $version );
			wp_style_add_data( self::SHARED_HANDLE, 'rtl', 'replace' );
		}
		return true;
	}
```

Mettre à jour le docblock de `SHARE_TRANSLATIONS` : remplacer « DataViews, embarqué dans chaque bundle, » par « DataViews, embarqué dans le chunk commun (SHARED_CHUNK), ».

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bin/test.sh --filter AssetsTest`
Expected: PASS.

- [ ] **Step 5: Split the bundle in `webpack.config.js`**

Remplacer le fichier par :

```js
/**
 * Configuration webpack de `@wordpress/scripts`, plus un point d'entrée par vue d'administration : chaque page ne
 * charge que le code de sa vue (écart E2 du plan M2). Le code de node_modules commun aux vues qui affichent
 * DataViews sort dans un chunk partagé, admin/dataviews.js (écart E11 du plan M3). Les blocs de src/blocks/* sont
 * découverts par `@wordpress/scripts` à partir de leur block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const { LicenseWebpackPlugin } = require( 'license-webpack-plugin' );

const VIEWS = [ 'overview', 'sites', 'alerts', 'settings' ];

// Vues sans DataViews : elles ne dépendent pas du chunk partagé (même liste que Admin\Assets::LIGHT_VIEWS).
const LIGHT_VIEWS = [ 'overview' ];

// Chunk partagé (Admin\Assets::SHARED_CHUNK), enregistré par PHP comme dépendance des autres vues.
const SHARED_CHUNK = 'admin/dataviews';

const SHARED_ENTRIES = VIEWS.filter(
	( view ) => ! LIGHT_VIEWS.includes( view )
).map( ( view ) => `admin/${ view }` );

const { splitChunks } = defaultConfig.optimization;

// La feuille de style de DataViews est importée par les points d'entrée : l'extraction de dépendances la
// prendrait pour un script WordPress (« wp-dataviews/build-style/style.css »), qui n'existe pas. `false` la
// laisse dans le bundle, d'où elle sort dans admin/dataviews.css.
// `instanceof` ne reconnaît que l'instance de `@wordpress/scripts` : le paquet est épinglé en devDependency pour
// qu'une seule copie (hissée) existe. Si aucune instance n'est remplacée, le build s'arrête.
let replaced = 0;
const plugins = defaultConfig.plugins.map( ( plugin ) => {
	if ( ! ( plugin instanceof DependencyExtractionWebpackPlugin ) ) {
		return plugin;
	}
	replaced++;
	return new DependencyExtractionWebpackPlugin( {
		requestToExternal: ( request ) =>
			request.endsWith( '/build-style/style.css' ) ? false : undefined,
	} );
} );
if ( replaced !== 1 ) {
	throw new Error(
		`webpack.config.js: expected one DependencyExtractionWebpackPlugin in the default configuration, found ${ replaced }.`
	);
}

// build/third-party-licenses.txt : chaque paquet embarqué (DataViews et ses dépendances) avec le texte de sa
// licence, comme l'exige la licence MIT. Le pied de page des pages du plugin y renvoie (Admin\Footer).
plugins.push(
	new LicenseWebpackPlugin( {
		perChunkOutput: false,
		outputFilename: 'third-party-licenses.txt',
		addBanner: false,
	} )
);

module.exports = {
	...defaultConfig,
	plugins,
	// Le chunk partagé dépasse la taille conseillée : il est mis en cache par le navigateur.
	performance: { hints: false },
	module: {
		...defaultConfig.module,
		rules: [
			// Le paquet dataviews se déclare sans effet de bord (« sideEffects: false ») : sans cette règle, webpack
			// supprime l'import de sa feuille de style en production.
			{
				test: /@wordpress[\\/]dataviews[\\/]build-style[\\/].*\.css$/,
				sideEffects: true,
			},
			...defaultConfig.module.rules,
		],
	},
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...splitChunks,
			cacheGroups: {
				...splitChunks.cacheGroups,
				// Le groupe `style` par défaut regroupe le CSS en un fichier `style-<entrée>` : avec
				// une feuille partagée, il serait nommé d'après la première entrée. Les vues
				// d'administration en sont exclues et émettent chacune leur `admin/<vue>.css`.
				// Les blocs gardent le comportement par défaut (`style-index.css`).
				style: {
					...splitChunks.cacheGroups.style,
					chunks: ( chunk ) =>
						! ( chunk.name || '' ).startsWith( 'admin/' ),
				},
				// Tout le code de node_modules des vues DataViews, JS et CSS, dans un seul fichier.
				dataviews: {
					test: /[\\/]node_modules[\\/]/,
					name: SHARED_CHUNK,
					chunks: ( chunk ) => SHARED_ENTRIES.includes( chunk.name ),
					enforce: true,
				},
			},
		},
	},
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

Dans `package.json`, remplacer le script `lint:js` par :

```json
		"lint:js": "wp-scripts lint-js src tests/js tests/e2e bin webpack.config.js",
```

- [ ] **Step 6: Format, lint and build**

Run: `npx wp-scripts format webpack.config.js && npm run lint:js && npm run build && ls -l build/admin/`
Expected :
- lint sans erreur ;
- `build/admin/` contient `dataviews.js` (environ 2 Mo), `dataviews.css`, `dataviews-rtl.css` et `dataviews.asset.php` ;
- `sites.js`, `alerts.js` et `settings.js` font moins de 100 Ko chacun ;
- `overview.js` est inchangé (environ 12 Ko).

- [ ] **Step 7: Check the translations of the shared chunk**

Run: `make i18n && ls languages/*.json | wc -l && php -r 'echo md5("build/admin/dataviews.js"), PHP_EOL;'`
Expected :
- `make i18n` se termine sans chaîne non traduite (les chaînes de DataViews n'ont pas changé, seul leur fichier a changé) ;
- 6 fichiers JSON (5 vues ou blocs, plus le chunk) ;
- `languages/multisite-radar.pot` et le `.po` ne changent que par leurs références `#:` (les chaînes de DataViews pointent désormais vers `build/admin/dataviews.js`) ;
- l'un d'eux s'appelle `multisite-radar-fr_FR-<md5 affiché>.json`.

- [ ] **Step 8: Check that every view still starts in a browser**

Run (environnement E2E, voir « Commandes ») : `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e`
Expected : PASS.
- `a11y.spec.js` ouvre les quatre pages et attend que `#msradar-app` ne soit pas vide ;
- `journey.spec.js` utilise les pages Sites et Alertes ;
- une vue qui n'attendrait pas son chunk resterait vide et ferait échouer ces tests.

- [ ] **Step 9: Run the PHP checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add webpack.config.js package.json includes/Admin/Assets.php tests/php/Admin/AssetsTest.php tests/php/fixtures/build/admin/ languages/multisite-radar.pot languages/multisite-radar-fr_FR.po
git commit -m "build: share one DataViews chunk between the admin views"
```

---

## Partie B — Lecture de l'inventaire (PHP)

### Task 2: Plugins — `GET /plugins` et `GET /plugins/{plugin}/sites`

**Files:**
- Modify: `includes/Storage/ExtensionsRepository.php`
- Create: `includes/Query/InventoryList.php`
- Create: `includes/Query/PluginsQuery.php`
- Modify: `includes/Rest/Controller.php`
- Create: `includes/Rest/PluginsController.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Storage/ExtensionsRepositoryTest.php`, `tests/php/Query/PluginsQueryTest.php` (créé), `tests/php/Rest/PluginsControllerTest.php` (créé)

**Interfaces:**
- Consumes :
  - `SitesRepository::count_all( int $network_id ): int` ;
  - `SitesQuery::list( array $args ): array{items, total}`, qui applique déjà le filtre `plugin` (fichier) et l'ignore pour un plugin activé sur le réseau ;
  - `Fingerprint::network_plugin_files( $sitewide ): string[]` ;
  - `PlainText::from_html( string ): string`.
- Produces :
  - `ExtensionsRepository::plugin_counts( int $network_id ): array<string, int>` (fichier => nombre de sites analysés du réseau), triés par fichier ;
  - `InventoryList::ORDERBY = [ 'name', 'sites_count' ]`, `InventoryList::matches( string $search, string ...$values ): bool`, `InventoryList::sort( array $items, string $orderby, string $order ): array`, `InventoryList::slice( array $items, int $page, int $per_page ): array{items: array[], total: int}`, `InventoryList::updates( string $transient ): array<string, string>` ;
  - `PluginsQuery::STATUSES = [ 'network', 'local', 'unused', 'missing' ]`, `PluginsQuery::defaults()`, `PluginsQuery::id( string $file ): string`, `PluginsQuery::installed(): array`, `->all(): array<string, array>`, `->filtered( array $args ): array[]`, `->list( array $args ): array{items, total}`, `->find( string $id ): ?array`, `->summary(): array{installed, network, unused, missing, updates}` ;
  - un plugin REST : `{ id, file, name, version, installed, network_active, sites_count, status, update_version }` (`update_version` : chaîne ou `null`) ;
  - `Controller::sites_page( SitesQuery $sites, array $filter, WP_REST_Request $request ): WP_REST_Response` et `Controller::sites_page_params(): array`, repris par la tâche 3 ;
  - `Plugin::plugins_query(): PluginsQuery`.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Storage/ExtensionsRepositoryTest.php`, ajouter :

```php
	public function test_plugin_counts_cover_the_analysed_sites_of_one_network(): void {
		$this->make_record( 931 );
		$this->make_record( 932 );
		$this->make_record( 933, [ 'network_id' => 2 ] );
		$repository = $this->plugin()->extensions();
		$repository->replace_for_site( 931, [ 'alpha/alpha.php', 'beta.php' ], '', '' );
		$repository->replace_for_site( 932, [ 'alpha/alpha.php' ], '', '' );
		$repository->replace_for_site( 933, [ 'alpha/alpha.php' ], '', '' );

		$this->assertSame(
			[
				'alpha/alpha.php' => 2,
				'beta.php'        => 1,
			],
			$repository->plugin_counts( get_current_network_id() )
		);
		$this->assertSame( [ 'alpha/alpha.php' => 1 ], $repository->plugin_counts( 2 ) );
	}

	public function test_a_failed_count_read_throws(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY e.slug' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->expectException( \RuntimeException::class );
			$this->plugin()->extensions()->plugin_counts( 1 );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}
	}
```

Créer `tests/php/Query/PluginsQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Tests\TestCase;

final class PluginsQueryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// get_plugins() lit d'abord ce cache : le test connaît ainsi les plugins « installés ».
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'beta/beta.php'   => [
						'Name'    => 'Beta &amp; <em>Co</em>',
						'Version' => '2.1',
					],
					'gamma/gamma.php' => [
						'Name'    => 'Gamma',
						'Version' => '3.0',
					],
					'hello.php'       => [
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					],
				],
			],
			'plugins'
		);
		update_site_option( 'active_sitewide_plugins', [ 'gamma/gamma.php' => time() ] );
		set_site_transient(
			'update_plugins',
			(object) [
				'response' => [
					'alpha/alpha.php' => (object) [ 'new_version' => '1.1' ],
					'ghost/ghost.php' => (object) [ 'new_version' => '9.0' ],
				],
			]
		);
		$this->make_record( 941 );
		$this->make_record( 942 );
		$this->make_record( 943, [ 'network_id' => 2 ] );
		$this->make_record( 944 );
		$extensions = $this->plugin()->extensions();
		$extensions->replace_for_site( 941, [ 'alpha/alpha.php', 'ghost/ghost.php', 'gamma/gamma.php' ], '', '' );
		$extensions->replace_for_site( 942, [ 'alpha/alpha.php' ], '', '' );
		$extensions->replace_for_site( 943, [ 'beta/beta.php' ], '', '' );
	}

	private function query(): PluginsQuery {
		return $this->plugin()->plugins_query();
	}

	/**
	 * @param array[] $items
	 */
	private static function ids( array $items ): array {
		return array_values( wp_list_pluck( $items, 'id' ) );
	}

	public function test_every_plugin_with_its_status_and_number_of_sites(): void {
		$total   = $this->plugin()->sites()->count_all( get_current_network_id() );
		$summary = [];
		foreach ( $this->query()->all() as $item ) {
			$summary[ $item['id'] ] = [ $item['status'], $item['sites_count'], $item['update_version'] ];
		}

		$this->assertSame(
			[
				'alpha/alpha' => [ 'local', 2, '1.1' ],
				'beta/beta'   => [ 'unused', 0, null ],
				'gamma/gamma' => [ 'network', $total, null ],
				'ghost/ghost' => [ 'missing', 1, null ],
				'hello'       => [ 'unused', 0, null ],
			],
			$summary,
			'Network activated wins over local activations; another network is not counted.'
		);
		$ghost = $this->query()->find( 'ghost/ghost' );
		$this->assertSame( [ 'id', 'file', 'name', 'version', 'installed', 'network_active', 'sites_count', 'status', 'update_version' ], array_keys( $ghost ) );
		$this->assertFalse( $ghost['installed'] );
		$this->assertSame( 'ghost/ghost.php', $ghost['name'] );
		$this->assertSame( 'Beta & Co', $this->query()->find( 'beta/beta' )['name'] );
		$this->assertTrue( $this->query()->find( 'gamma/gamma' )['network_active'] );
	}

	public function test_search_status_updates_sort_and_pages(): void {
		$this->assertSame( [ 'beta/beta' ], self::ids( $this->query()->list( [ 'search' => 'co' ] )['items'] ) );
		$this->assertSame( [ 'hello' ], self::ids( $this->query()->list( [ 'search' => 'HELLO.PHP' ] )['items'] ) );
		$this->assertSame( [ 'beta/beta', 'hello' ], self::ids( $this->query()->list( [ 'status' => [ 'unused', 'bogus' ] ] )['items'] ) );
		$this->assertSame( [ 'alpha/alpha' ], self::ids( $this->query()->list( [ 'has_update' => true ] )['items'] ) );
		$this->assertSame(
			[ 'gamma/gamma', 'alpha/alpha', 'ghost/ghost', 'beta/beta', 'hello' ],
			self::ids(
				$this->query()->list(
					[
						'orderby' => 'sites_count',
						'order'   => 'desc',
					]
				)['items']
			),
			'Equal counts keep the name order.'
		);
		$this->assertSame( [ 'hello', 'ghost/ghost', 'gamma/gamma', 'beta/beta', 'alpha/alpha' ], self::ids( $this->query()->list( [ 'order' => 'desc' ] )['items'] ) );

		$page = $this->query()->list(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'gamma/gamma', 'ghost/ghost' ], self::ids( $page['items'] ) );
		$this->assertSame( 5, $page['total'] );
		$this->assertSame( [], $this->query()->list( [ 'page' => 99 ] )['items'] );
		$this->assertCount( 5, $this->query()->filtered( [] ), 'filtered() has no page limit.' );
	}

	public function test_find_uses_the_public_id(): void {
		$this->assertSame( 'hello.php', $this->query()->find( 'hello' )['file'] );
		$this->assertSame( 'alpha/alpha.php', $this->query()->find( 'alpha/alpha' )['file'] );
		$this->assertNull( $this->query()->find( 'alpha/alpha.php' ) );
		$this->assertNull( $this->query()->find( 'nope' ) );
		$this->assertSame( 'my plugin/my.plugin', PluginsQuery::id( 'my plugin/my.plugin.php' ) );
	}

	public function test_summary(): void {
		$this->assertSame(
			[
				'installed' => 4,
				'network'   => 1,
				'unused'    => 2,
				'missing'   => 1,
				'updates'   => 1,
			],
			$this->query()->summary()
		);
	}
}
```

Créer `tests/php/Rest/PluginsControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class PluginsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php'         => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'my plugin/my.plugin.php' => [
						'Name'    => 'My plugin',
						'Version' => '0.1',
					],
					'hello.php'               => [
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					],
					'gamma/gamma.php'         => [
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
			951,
			[
				'name'       => 'Alpha site',
				'scanned_at' => $scanned,
			]
		);
		$this->make_record(
			952,
			[
				'name'       => 'Beta site',
				'scanned_at' => $scanned,
			]
		);
		$this->make_record(
			953,
			[
				'name'       => 'Elsewhere',
				'network_id' => 2,
			]
		);
		$this->make_record( 954, [ 'name' => 'Quiet site' ] );
		$extensions = $this->plugin()->extensions();
		$extensions->replace_for_site( 951, [ 'alpha/alpha.php', 'my plugin/my.plugin.php' ], '', '' );
		$extensions->replace_for_site( 952, [ 'alpha/alpha.php' ], '', '' );
		$extensions->replace_for_site( 953, [ 'alpha/alpha.php' ], '', '' );
	}

	public function test_lists_plugins_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/plugins' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/plugins' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request(
			'GET',
			'/plugins',
			[
				'per_page' => 2,
				'orderby'  => 'sites_count',
				'order'    => 'desc',
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '4', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( [ 'gamma/gamma', 'alpha/alpha' ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( [ 'hello' ], wp_list_pluck( $this->request( 'GET', '/plugins', [ 'status' => 'unused' ] )->get_data(), 'id' ) );

		foreach ( [ [ 'status' => [ 'gone' ] ], [ 'orderby' => 'file' ], [ 'per_page' => 101 ], [ 'has_update' => 'maybe' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/plugins', $params )->get_status() );
		}
	}

	public function test_lists_the_sites_of_a_plugin_by_its_public_id(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/plugins/alpha/alpha/sites' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 951, 952 ], wp_list_pluck( $response->get_data(), 'id' ), 'Sorted by name, this network only.' );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		$this->assertSame( [ 951 ], wp_list_pluck( $this->request( 'GET', '/plugins/my plugin/my.plugin/sites' )->get_data(), 'id' ) );
		$this->assertSame( [], $this->request( 'GET', '/plugins/hello/sites' )->get_data(), 'Installed but active nowhere.' );
		$this->assertSame( 404, $this->request( 'GET', '/plugins/nope/nope/sites' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/plugins/alpha/alpha.php/sites' )->get_status(), 'The id has no .php.' );

		$network = $this->request( 'GET', '/plugins/gamma/gamma/sites', [ 'per_page' => 1 ] );
		$this->assertSame( (string) $this->plugin()->sites()->count_all( get_current_network_id() ), $network->get_headers()['X-WP-Total'], 'Network activated: every site of the network.' );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY e.slug' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$list  = $this->request( 'GET', '/plugins' );
			$sites = $this->request( 'GET', '/plugins/alpha/alpha/sites' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $list->get_status() );
		$this->assertSame( 500, $sites->get_status() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'ExtensionsRepositoryTest|PluginsQueryTest|PluginsControllerTest'`
Expected: FAIL. `plugin_counts()` et `plugins_query()` n'existent pas.

- [ ] **Step 3: Add the counts to `includes/Storage/ExtensionsRepository.php`**

Ajouter, après `delete_for_site()` :

```php
	/**
	 * Nombre de sites analysés du réseau sur lesquels chaque plugin est activé localement.
	 * Les plugins activés sur le réseau n'ont pas de ligne ici (voir la docblock de la table, spec §3.1).
	 *
	 * @return array<string, int> Fichier du plugin => nombre de sites, triés par fichier.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function plugin_counts( int $network_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.slug, COUNT(*) AS sites FROM %i AS e INNER JOIN %i AS s ON s.site_id = e.site_id WHERE e.type = %s AND s.network_id = %d GROUP BY e.slug ORDER BY e.slug ASC',
				Schema::extensions_table(),
				Schema::sites_table(),
				self::TYPE_PLUGIN,
				$network_id
			),
			ARRAY_A
		);
		self::check_read();

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['slug'] ] = (int) $row['sites'];
		}
		return $counts;
	}

	/**
	 * Une lecture en échec ne doit pas passer pour un inventaire vide.
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

- [ ] **Step 4: Create `includes/Query/InventoryList.php`**

```php
<?php
namespace MultisiteRadar\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Listes d'inventaire (plugins, thèmes) construites en mémoire : quelques centaines d'éléments au plus.
 * Recherche sans casse, tri par nom ou par nombre de sites avec départage stable, pagination bornée comme en REST.
 * Chaque élément a au moins id (string), name (string) et sites_count (int).
 */
final class InventoryList {

	public const ORDERBY = [ 'name', 'sites_count' ];

	/**
	 * Le texte cherché apparaît-il dans l'une des valeurs, sans tenir compte de la casse ?
	 */
	public static function matches( string $search, string ...$values ): bool {
		if ( '' === $search ) {
			return true;
		}
		foreach ( $values as $value ) {
			$found = function_exists( 'mb_stripos' ) ? mb_stripos( $value, $search, 0, 'UTF-8' ) : stripos( $value, $search );
			if ( false !== $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tri par nom (ordre naturel, sans casse) ou par nombre de sites ; à égalité, par nom puis par identifiant.
	 *
	 * @param array[] $items
	 * @return array[]
	 */
	public static function sort( array $items, string $orderby, string $order ): array {
		$by_count = 'sites_count' === $orderby;
		$sign     = 'desc' === strtolower( $order ) ? -1 : 1;
		usort(
			$items,
			static function ( array $a, array $b ) use ( $by_count, $sign ): int {
				$primary = $by_count ? $a['sites_count'] <=> $b['sites_count'] : strnatcasecmp( $a['name'], $b['name'] );
				if ( 0 !== $primary ) {
					return $sign * $primary;
				}
				$name = strnatcasecmp( $a['name'], $b['name'] );
				return 0 !== $name ? $name : strcmp( $a['id'], $b['id'] );
			}
		);
		return $items;
	}

	/**
	 * Une page, avec les bornes des routes REST (100 par page au plus, page ≤ SitesQuery::MAX_PAGE).
	 *
	 * @param array[] $items
	 * @return array{items: array[], total: int}
	 */
	public static function slice( array $items, int $page, int $per_page ): array {
		$per_page = min( 100, max( 1, $per_page ) );
		$page     = min( SitesQuery::MAX_PAGE, max( 1, $page ) );
		return [
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $items ),
		];
	}

	/**
	 * Versions proposées par la dernière vérification de WordPress, lues dans un transient réseau : aucun appel externe.
	 *
	 * @param string $transient update_plugins ou update_themes.
	 * @return array<string, string> Fichier du plugin ou dossier du thème => nouvelle version.
	 */
	public static function updates( string $transient ): array {
		$value    = get_site_transient( $transient );
		$data     = is_object( $value ) ? get_object_vars( $value ) : [];
		$response = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : [];
		$updates  = [];
		foreach ( $response as $key => $update ) {
			$fields  = is_object( $update ) ? get_object_vars( $update ) : ( is_array( $update ) ? $update : [] );
			$version = $fields['new_version'] ?? '';
			if ( is_scalar( $version ) && '' !== (string) $version ) {
				$updates[ (string) $key ] = (string) $version;
			}
		}
		return $updates;
	}
}
```

- [ ] **Step 5: Create `includes/Query/PluginsQuery.php`**

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des plugins du réseau courant : installés (hors MU-plugins et drop-ins), ou encore actifs sur un site
 * alors qu'ils ont disparu du disque. Le nombre de sites ne compte que les sites analysés (écart E8 du plan M3).
 */
final class PluginsQuery {

	public const STATUS_NETWORK = 'network';
	public const STATUS_LOCAL   = 'local';
	public const STATUS_UNUSED  = 'unused';
	public const STATUS_MISSING = 'missing';
	public const STATUSES       = [ self::STATUS_NETWORK, self::STATUS_LOCAL, self::STATUS_UNUSED, self::STATUS_MISSING ];

	private ExtensionsRepository $extensions;
	private SitesRepository $sites;

	public function __construct( ExtensionsRepository $extensions, SitesRepository $sites ) {
		$this->extensions = $extensions;
		$this->sites      = $sites;
	}

	public static function defaults(): array {
		return [
			'page'       => 1,
			'per_page'   => 20,
			'search'     => '',
			'status'     => [],
			'has_update' => false,
			'orderby'    => 'name',
			'order'      => 'asc',
		];
	}

	/**
	 * Identifiant public d'un plugin : son fichier sans « .php », comme la route wp/v2/plugins du cœur.
	 * Aucune URL de l'API ne contient ainsi « .php/ », que certains serveurs confient à PHP (écart E1 du plan M3).
	 */
	public static function id( string $file ): string {
		return '.php' === substr( $file, -4 ) ? substr( $file, 0, -4 ) : $file;
	}

	/**
	 * Plugins installés, nom en texte brut. get_plugins() ignore déjà les MU-plugins et les drop-ins.
	 *
	 * @return array<string, array{name: string, version: string}> Fichier => nom et version.
	 */
	public static function installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ (string) $file ] = [
				'name'    => PlainText::from_html( wp_strip_all_tags( (string) ( $data['Name'] ?? '' ) ) ),
				'version' => (string) ( $data['Version'] ?? '' ),
			];
		}
		return $plugins;
	}

	/**
	 * Tous les plugins du réseau, triés par fichier.
	 *
	 * @return array<string, array> Fichier => plugin (forme REST).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function all(): array {
		$network_id = get_current_network_id();
		$installed  = self::installed();
		$counts     = $this->extensions->plugin_counts( $network_id );
		$network    = Fingerprint::network_plugin_files( get_site_option( 'active_sitewide_plugins', [] ) );
		$updates    = InventoryList::updates( 'update_plugins' );
		$total      = $this->sites->count_all( $network_id );

		$files = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $installed ), array_keys( $counts ), $network ) ) ) );
		sort( $files, SORT_STRING );

		$items = [];
		foreach ( $files as $file ) {
			$plugin         = $installed[ $file ] ?? null;
			$network_active = in_array( $file, $network, true );
			$sites_count    = $network_active ? $total : ( $counts[ $file ] ?? 0 );
			if ( null === $plugin ) {
				$status = self::STATUS_MISSING;
			} elseif ( $network_active ) {
				$status = self::STATUS_NETWORK;
			} elseif ( $sites_count > 0 ) {
				$status = self::STATUS_LOCAL;
			} else {
				$status = self::STATUS_UNUSED;
			}
			$items[ $file ] = [
				'id'             => self::id( $file ),
				'file'           => $file,
				'name'           => null !== $plugin && '' !== $plugin['name'] ? $plugin['name'] : $file,
				'version'        => null !== $plugin ? $plugin['version'] : '',
				'installed'      => null !== $plugin,
				'network_active' => $network_active,
				'sites_count'    => $sites_count,
				'status'         => $status,
				'update_version' => null !== $plugin ? ( $updates[ $file ] ?? null ) : null,
			];
		}
		return $items;
	}

	/**
	 * Plugins qui correspondent aux filtres, triés, sans pagination (exports).
	 *
	 * @return array[]
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function filtered( array $args ): array {
		$args     = array_merge( self::defaults(), $args );
		$search   = trim( (string) $args['search'] );
		$statuses = array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) );
		$updates  = (bool) $args['has_update'];
		$items    = array_filter(
			$this->all(),
			static function ( array $item ) use ( $search, $statuses, $updates ): bool {
				return InventoryList::matches( $search, $item['name'], $item['file'] )
					&& ( [] === $statuses || in_array( $item['status'], $statuses, true ) )
					&& ( ! $updates || null !== $item['update_version'] );
			}
		);
		return InventoryList::sort( array_values( $items ), (string) $args['orderby'], (string) $args['order'] );
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args = array_merge( self::defaults(), $args );
		return InventoryList::slice( $this->filtered( $args ), (int) $args['page'], (int) $args['per_page'] );
	}

	/**
	 * @param string $id Identifiant public (fichier sans « .php »).
	 * @return array|null Null si le plugin n'est ni installé ni actif sur un site du réseau.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @return array{installed: int, network: int, unused: int, missing: int, updates: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary(): array {
		$summary = [
			'installed' => 0,
			'network'   => 0,
			'unused'    => 0,
			'missing'   => 0,
			'updates'   => 0,
		];
		foreach ( $this->all() as $item ) {
			$summary['installed'] += $item['installed'] ? 1 : 0;
			$summary['network']   += self::STATUS_NETWORK === $item['status'] ? 1 : 0;
			$summary['unused']    += self::STATUS_UNUSED === $item['status'] ? 1 : 0;
			$summary['missing']   += self::STATUS_MISSING === $item['status'] ? 1 : 0;
			$summary['updates']   += null !== $item['update_version'] ? 1 : 0;
		}
		return $summary;
	}
}
```

- [ ] **Step 6: Add the shared site listing to `includes/Rest/Controller.php`**

Ajouter `use MultisiteRadar\Query\InventoryList;`, `use MultisiteRadar\Query\SitesQuery;` et `use WP_REST_Request;`, puis, à la fin de la classe :

```php
	/**
	 * Une page des sites qui utilisent un plugin ou un thème, triés par nom.
	 *
	 * @param array $filter [ 'plugin' => fichier ] ou [ 'theme' => dossier ].
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	protected function sites_page( SitesQuery $sites, array $filter, WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = $sites->list(
			array_merge(
				$filter,
				[
					'page'     => (int) $request['page'],
					'per_page' => $per_page,
					'search'   => (string) $request['search'],
					'orderby'  => 'name',
					'order'    => 'asc',
				]
			)
		);
		return $this->paginated( $result['items'], $result['total'], $per_page );
	}

	/**
	 * Arguments de la liste des sites d'un plugin ou d'un thème.
	 */
	protected static function sites_page_params(): array {
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
		];
	}

	/**
	 * Arguments communs des listes d'inventaire (plugins, thèmes).
	 *
	 * @param string[] $statuses Statuts possibles.
	 */
	protected static function inventory_params( array $statuses ): array {
		return array_merge(
			self::sites_page_params(),
			[
				'status'     => [
					'type'    => 'array',
					'default' => [],
					'items'   => [
						'type' => 'string',
						'enum' => $statuses,
					],
				],
				'has_update' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'orderby'    => [
					'type'    => 'string',
					'default' => 'name',
					'enum'    => InventoryList::ORDERBY,
				],
				'order'      => [
					'type'    => 'string',
					'default' => 'asc',
					'enum'    => [ 'asc', 'desc' ],
				],
			]
		);
	}

	/**
	 * Arguments d'une requête d'inventaire, tels que PluginsQuery::list() et ThemesQuery::list() les attendent.
	 */
	protected static function inventory_args( WP_REST_Request $request ): array {
		return [
			'page'       => (int) $request['page'],
			'per_page'   => (int) $request['per_page'],
			'search'     => (string) $request['search'],
			'status'     => (array) $request['status'],
			'has_update' => (bool) $request['has_update'],
			'orderby'    => (string) $request['orderby'],
			'order'      => (string) $request['order'],
		];
	}
```


- [ ] **Step 7: Create `includes/Rest/PluginsController.php`**

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /plugins et GET /plugins/{plugin}/sites, où {plugin} est le fichier sans « .php » (écart E1 du plan M3).
 */
final class PluginsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'plugins';

	private PluginsQuery $plugins;
	private SitesQuery $sites;

	public function __construct( PluginsQuery $plugins, SitesQuery $sites ) {
		$this->plugins = $plugins;
		$this->sites   = $sites;
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
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<plugin>[^/]+(?:/[^/]+)?)/sites',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_sites' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => self::sites_page_params(),
				],
			]
		);
	}

	public function get_collection_params(): array {
		return self::inventory_params( PluginsQuery::STATUSES );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result = $this->plugins->list( self::inventory_args( $request ) );
				return $this->paginated( $result['items'], $result['total'], (int) $request['per_page'] );
			}
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sites( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$plugin = $this->plugins->find( (string) $request['plugin'] );
				if ( null === $plugin ) {
					return new WP_Error( 'msradar_plugin_not_found', __( 'This plugin is neither installed nor active on any site.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return $this->sites_page( $this->sites, [ 'plugin' => $plugin['file'] ], $request );
			}
		);
	}
}
```

- [ ] **Step 8: Wire the services in `includes/Plugin.php`**

- `use MultisiteRadar\Query\PluginsQuery;` et `use MultisiteRadar\Rest\PluginsController;` ;
- propriété `private ?PluginsQuery $plugins_query = null;` à côté de `$alerts_query` ;
- méthode :

```php
	public function plugins_query(): PluginsQuery {
		return $this->plugins_query ??= new PluginsQuery( $this->extensions(), $this->sites() );
	}
```

- dans `register_rest_routes()`, ajouter `new PluginsController( $this->plugins_query(), $this->sites_query() ),` après `SitesController`.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'ExtensionsRepositoryTest|PluginsQueryTest|PluginsControllerTest'`
Expected: PASS.

- [ ] **Step 10: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Storage/ExtensionsRepository.php includes/Query/InventoryList.php includes/Query/PluginsQuery.php includes/Rest/Controller.php includes/Rest/PluginsController.php includes/Plugin.php tests/php/Storage/ExtensionsRepositoryTest.php tests/php/Query/PluginsQueryTest.php tests/php/Rest/PluginsControllerTest.php
git commit -m "feat: list the network plugins and the sites that use each one"
```

---

### Task 3: Thèmes — `GET /themes` et `GET /themes/{stylesheet}/sites`

**Files:**
- Modify: `includes/Storage/SitesRepository.php`
- Create: `includes/Query/ThemesQuery.php`
- Create: `includes/Rest/ThemesController.php`
- Modify: `includes/Plugin.php`
- Create: `tests/php/fixtures/themes/msradar-fixture-theme/index.php`, `tests/php/fixtures/themes/msradar-fixture-child/style.css`, `tests/php/fixtures/themes/msradar-fixture-spare/style.css`, `tests/php/fixtures/themes/msradar-fixture-spare/index.php`
- Test: `tests/php/Storage/SitesRepositoryTest.php`, `tests/php/Query/ThemesQueryTest.php` (créé), `tests/php/Rest/ThemesControllerTest.php` (créé)

**Interfaces:**
- Consumes :
  - `InventoryList` (tâche 2) ;
  - `Controller::sites_page()`, `sites_page_params()`, `inventory_params()`, `inventory_args()` (tâche 2) ;
  - `SitesQuery::list()` avec le filtre `theme`, qui retient `theme_stylesheet = X OR theme_template = X`.
- Produces :
  - `SitesRepository::theme_counts( int $network_id ): array{active: array<string, int>, parent: array<string, int>}` ;
  - `ThemesQuery::STATUSES = [ 'used', 'unused', 'missing' ]`, `ThemesQuery::defaults()`, `ThemesQuery::installed(): array`, `->all()`, `->filtered( array $args )`, `->list( array $args )`, `->find( string $stylesheet ): ?array`, `->summary(): array{installed, unused, missing, updates}` ;
  - un thème REST : `{ id, stylesheet, name, version, installed, parent, allowed_on_network, active_count, parent_count, sites_count, status, update_version }` (`parent` : dossier du thème parent ou `null`) ;
  - `Plugin::themes_query(): ThemesQuery`.

- [ ] **Step 1: Write the failing tests**

Sans `index.php`, WordPress tient un thème pour abîmé (`theme_no_index`). `ThemesQuery` les liste quand même, mais les fixtures doivent représenter des thèmes ordinaires. Créer `tests/php/fixtures/themes/msradar-fixture-theme/index.php` et `tests/php/fixtures/themes/msradar-fixture-spare/index.php`, avec le même contenu :

```php
<?php
// Fixture : modèle minimal pour que WordPress tienne ce thème pour valide.
```

Créer `tests/php/fixtures/themes/msradar-fixture-child/style.css` :

```css
/*
Theme Name: Fixture Child
Template: msradar-fixture-theme
Version: 0.2.0
Description: Fixture child theme for Multisite Radar tests.
*/
```

Créer `tests/php/fixtures/themes/msradar-fixture-spare/style.css` :

```css
/*
Theme Name: Fixture Spare
Version: 1.0.0
Description: Fixture theme that no site uses.
*/
```

Dans `tests/php/Storage/SitesRepositoryTest.php`, ajouter :

```php
	public function test_theme_counts_separate_active_themes_and_parents_per_network(): void {
		$this->make_record(
			981,
			[
				'theme_stylesheet' => 'child',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record(
			982,
			[
				'theme_stylesheet' => 'parent',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record(
			983,
			[
				'network_id'       => 2,
				'theme_stylesheet' => 'parent',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record( 984 );

		$counts = $this->plugin()->sites()->theme_counts( get_current_network_id() );

		$this->assertSame(
			[
				'child'  => 1,
				'parent' => 1,
			],
			$counts['active']
		);
		$this->assertSame( [ 'parent' => 1 ], $counts['parent'] );
	}
```

Créer `tests/php/Query/ThemesQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\ThemesQuery;
use MultisiteRadar\Tests\TestCase;

final class ThemesQueryTest extends TestCase {

	/**
	 * @var string[]
	 */
	private array $directories = [];

	public function set_up(): void {
		global $wp_theme_directories;
		parent::set_up();
		$this->directories = $wp_theme_directories;
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		search_theme_directories( true );

		update_site_option( 'allowedthemes', [ 'msradar-fixture-child' => true ] );
		set_site_transient(
			'update_themes',
			(object) [
				'response' => [
					'msradar-fixture-theme' => [
						'theme'       => 'msradar-fixture-theme',
						'new_version' => '1.1.0',
					],
				],
			]
		);
		$this->make_record(
			961,
			[
				'name'             => 'Child site',
				'theme_stylesheet' => 'msradar-fixture-child',
				'theme_template'   => 'msradar-fixture-theme',
			]
		);
		$this->make_record(
			962,
			[
				'name'             => 'Parent site',
				'theme_stylesheet' => 'msradar-fixture-theme',
				'theme_template'   => 'msradar-fixture-theme',
			]
		);
		$this->make_record(
			963,
			[
				'network_id'       => 2,
				'theme_stylesheet' => 'msradar-fixture-spare',
				'theme_template'   => 'msradar-fixture-spare',
			]
		);
		$this->make_record(
			964,
			[
				'name'             => 'Orphan site',
				'theme_stylesheet' => 'msradar-gone-child',
				'theme_template'   => 'msradar-gone',
			]
		);
		$this->make_record( 965 );
	}

	public function tear_down(): void {
		global $wp_theme_directories;
		$wp_theme_directories = $this->directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the test's own change.
		delete_site_transient( 'theme_roots' );
		search_theme_directories( true );
		parent::tear_down();
	}

	private function query(): ThemesQuery {
		return $this->plugin()->themes_query();
	}

	/**
	 * @return array<string, array>
	 */
	private function themes(): array {
		$themes = [];
		foreach ( $this->query()->all() as $theme ) {
			$themes[ $theme['id'] ] = $theme;
		}
		return $themes;
	}

	public function test_counts_active_themes_and_parents_of_this_network(): void {
		$themes = $this->themes();

		$parent = $themes['msradar-fixture-theme'];
		$this->assertSame( [ 'id', 'stylesheet', 'name', 'version', 'installed', 'parent', 'allowed_on_network', 'active_count', 'parent_count', 'sites_count', 'status', 'update_version' ], array_keys( $parent ) );
		$this->assertSame( 'R&D Studio', $parent['name'] );
		$this->assertSame( [ 'used', 1, 1, 2, '1.1.0', false, null ], [ $parent['status'], $parent['active_count'], $parent['parent_count'], $parent['sites_count'], $parent['update_version'], $parent['allowed_on_network'], $parent['parent'] ] );

		$child = $themes['msradar-fixture-child'];
		$this->assertSame( [ 'used', 1, 0, 1, true, 'msradar-fixture-theme' ], [ $child['status'], $child['active_count'], $child['parent_count'], $child['sites_count'], $child['allowed_on_network'], $child['parent'] ] );

		$spare = $themes['msradar-fixture-spare'];
		$this->assertSame( [ 'unused', 0 ], [ $spare['status'], $spare['sites_count'] ], 'Used on another network only.' );
	}

	public function test_a_missing_parent_is_reported_as_not_installed(): void {
		$themes = $this->themes();

		$this->assertSame( [ 'missing', false, 0, 1, 'msradar-gone' ], [ $themes['msradar-gone']['status'], $themes['msradar-gone']['installed'], $themes['msradar-gone']['active_count'], $themes['msradar-gone']['parent_count'], $themes['msradar-gone']['name'] ] );
		$this->assertSame( [ 'missing', 1 ], [ $themes['msradar-gone-child']['status'], $themes['msradar-gone-child']['active_count'] ] );
	}

	public function test_filters_and_find(): void {
		$ids = static fn ( array $result ): array => array_values( wp_list_pluck( $result['items'], 'id' ) );

		$this->assertSame( [ 'msradar-fixture-child' ], $ids( $this->query()->list( [ 'search' => 'fixture child' ] ) ) );
		$this->assertSame( [ 'msradar-gone', 'msradar-gone-child' ], $ids( $this->query()->list( [ 'status' => [ 'missing' ] ] ) ) );
		$this->assertSame( [ 'msradar-fixture-theme' ], $ids( $this->query()->list( [ 'has_update' => true ] ) ) );
		$this->assertContains( 'msradar-fixture-spare', $ids( $this->query()->list( [ 'status' => [ 'unused' ], 'per_page' => 100 ] ) ) );
		$this->assertSame( 'msradar-fixture-theme', $this->query()->find( 'msradar-fixture-theme' )['stylesheet'] );
		$this->assertNull( $this->query()->find( 'nope' ) );
	}

	public function test_summary_counts_this_network(): void {
		$summary = $this->query()->summary();

		$this->assertSame( [ 'installed', 'unused', 'missing', 'updates' ], array_keys( $summary ) );
		$this->assertSame( 2, $summary['missing'] );
		$this->assertSame( 1, $summary['updates'] );
		$this->assertGreaterThanOrEqual( 3, $summary['installed'] );
	}
}
```

Créer `tests/php/Rest/ThemesControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ThemesControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			971,
			[
				'name'             => 'Child site',
				'theme_stylesheet' => 'msradar-child',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			972,
			[
				'name'             => 'Parent site',
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			973,
			[
				'name'             => 'Elsewhere',
				'network_id'       => 2,
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
	}

	public function test_lists_themes_with_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/themes' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/themes' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request( 'GET', '/themes', [ 'status' => 'missing' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'msradar-child', 'msradar-parent' ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		foreach ( [ [ 'status' => [ 'gone' ] ], [ 'orderby' => 'stylesheet' ], [ 'per_page' => 0 ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/themes', $params )->get_status() );
		}
	}

	public function test_lists_the_sites_of_a_theme_active_or_as_parent(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/themes/msradar-parent/sites' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 971, 972 ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( [ 971 ], wp_list_pluck( $this->request( 'GET', '/themes/msradar-child/sites' )->get_data(), 'id' ) );
		$this->assertSame( 404, $this->request( 'GET', '/themes/nope/sites' )->get_status() );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY theme_stylesheet' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/themes' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'SitesRepositoryTest|ThemesQueryTest|ThemesControllerTest'`
Expected: FAIL. `theme_counts()` et `themes_query()` n'existent pas.

- [ ] **Step 3: Add the counts to `includes/Storage/SitesRepository.php`**

Ajouter, après `alert_counts()` :

```php
	/**
	 * Sites analysés du réseau par thème actif, et par thème parent d'un thème enfant actif. Les deux comptes ne se
	 * recouvrent pas : leur somme est le nombre de lignes que retient le filtre « theme » de query().
	 *
	 * @return array{active: array<string, int>, parent: array<string, int>} Dossier du thème => nombre de sites.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function theme_counts( int $network_id ): array {
		global $wpdb;
		$table  = Schema::sites_table();
		$active = $wpdb->get_results(
			$wpdb->prepare( "SELECT theme_stylesheet AS theme, COUNT(*) AS sites FROM %i WHERE network_id = %d AND theme_stylesheet <> '' GROUP BY theme_stylesheet ORDER BY theme_stylesheet ASC", $table, $network_id ),
			ARRAY_A
		);
		self::check_read();
		$parent = $wpdb->get_results(
			$wpdb->prepare( "SELECT theme_template AS theme, COUNT(*) AS sites FROM %i WHERE network_id = %d AND theme_template <> '' AND theme_template <> theme_stylesheet GROUP BY theme_template ORDER BY theme_template ASC", $table, $network_id ),
			ARRAY_A
		);
		self::check_read();

		return [
			'active' => self::count_map( (array) $active ),
			'parent' => self::count_map( (array) $parent ),
		];
	}

	/**
	 * @param array[] $rows Lignes { theme, sites }.
	 * @return array<string, int>
	 */
	private static function count_map( array $rows ): array {
		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ (string) $row['theme'] ] = (int) $row['sites'];
		}
		return $counts;
	}
```

Un dossier de thème entièrement numérique (`2024`) devient une clé entière dans un tableau PHP : `ThemesQuery` repasse donc toutes les clés par `strval`.

- [ ] **Step 4: Create `includes/Query/ThemesQuery.php`**

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des thèmes du réseau courant : présents sur le disque, ou encore utilisés par un site alors qu'ils ont
 * disparu. Un thème est utilisé s'il est actif sur un site ou parent du thème actif d'un site (spec §7.1).
 */
final class ThemesQuery {

	public const STATUS_USED    = 'used';
	public const STATUS_UNUSED  = 'unused';
	public const STATUS_MISSING = 'missing';
	public const STATUSES       = [ self::STATUS_USED, self::STATUS_UNUSED, self::STATUS_MISSING ];

	private SitesRepository $sites;

	public function __construct( SitesRepository $sites ) {
		$this->sites = $sites;
	}

	public static function defaults(): array {
		return [
			'page'       => 1,
			'per_page'   => 20,
			'search'     => '',
			'status'     => [],
			'has_update' => false,
			'orderby'    => 'name',
			'order'      => 'asc',
		];
	}

	/**
	 * Thèmes présents sur le disque, y compris ceux que WordPress juge abîmés (parent ou modèle manquant) :
	 * un thème présent n'est jamais annoncé « introuvable ».
	 *
	 * @return array<string, array{name: string, version: string, template: string}> Dossier => thème.
	 */
	public static function installed(): array {
		$themes = [];
		foreach ( wp_get_themes( [ 'errors' => null ] ) as $stylesheet => $theme ) {
			$themes[ (string) $stylesheet ] = [
				'name'     => PlainText::from_html( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ),
				'version'  => (string) $theme->get( 'Version' ),
				'template' => (string) $theme->get_template(),
			];
		}
		return $themes;
	}

	/**
	 * Tous les thèmes du réseau, triés par dossier.
	 *
	 * @return array<string, array> Dossier => thème (forme REST).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function all(): array {
		$installed = self::installed();
		$counts    = $this->sites->theme_counts( get_current_network_id() );
		// Lu directement : WP_Theme::get_allowed_on_network() garde la première valeur lue dans une variable statique.
		$allowed = array_map( 'strval', array_keys( array_filter( (array) get_site_option( 'allowedthemes', [] ) ) ) );
		$updates = InventoryList::updates( 'update_themes' );

		$slugs = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $installed ), array_keys( $counts['active'] ), array_keys( $counts['parent'] ) ) ) ) );
		sort( $slugs, SORT_STRING );

		$items = [];
		foreach ( $slugs as $stylesheet ) {
			$theme  = $installed[ $stylesheet ] ?? null;
			$active = $counts['active'][ $stylesheet ] ?? 0;
			$parent = $counts['parent'][ $stylesheet ] ?? 0;
			if ( null === $theme ) {
				$status = self::STATUS_MISSING;
			} elseif ( $active + $parent > 0 ) {
				$status = self::STATUS_USED;
			} else {
				$status = self::STATUS_UNUSED;
			}
			$items[ $stylesheet ] = [
				'id'                 => $stylesheet,
				'stylesheet'         => $stylesheet,
				'name'               => null !== $theme && '' !== $theme['name'] ? $theme['name'] : $stylesheet,
				'version'            => null !== $theme ? $theme['version'] : '',
				'installed'          => null !== $theme,
				'parent'             => null !== $theme && '' !== $theme['template'] && $theme['template'] !== $stylesheet ? $theme['template'] : null,
				'allowed_on_network' => in_array( $stylesheet, $allowed, true ),
				'active_count'       => $active,
				'parent_count'       => $parent,
				'sites_count'        => $active + $parent,
				'status'             => $status,
				'update_version'     => null !== $theme ? ( $updates[ $stylesheet ] ?? null ) : null,
			];
		}
		return $items;
	}

	/**
	 * Thèmes qui correspondent aux filtres, triés, sans pagination (exports).
	 *
	 * @return array[]
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function filtered( array $args ): array {
		$args     = array_merge( self::defaults(), $args );
		$search   = trim( (string) $args['search'] );
		$statuses = array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) );
		$updates  = (bool) $args['has_update'];
		$items    = array_filter(
			$this->all(),
			static function ( array $item ) use ( $search, $statuses, $updates ): bool {
				return InventoryList::matches( $search, $item['name'], $item['stylesheet'] )
					&& ( [] === $statuses || in_array( $item['status'], $statuses, true ) )
					&& ( ! $updates || null !== $item['update_version'] );
			}
		);
		return InventoryList::sort( array_values( $items ), (string) $args['orderby'], (string) $args['order'] );
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args = array_merge( self::defaults(), $args );
		return InventoryList::slice( $this->filtered( $args ), (int) $args['page'], (int) $args['per_page'] );
	}

	/**
	 * @return array|null Null si le thème n'est ni présent ni utilisé par un site du réseau.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( string $stylesheet ): ?array {
		return $this->all()[ $stylesheet ] ?? null;
	}

	/**
	 * @return array{installed: int, unused: int, missing: int, updates: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary(): array {
		$summary = [
			'installed' => 0,
			'unused'    => 0,
			'missing'   => 0,
			'updates'   => 0,
		];
		foreach ( $this->all() as $item ) {
			$summary['installed'] += $item['installed'] ? 1 : 0;
			$summary['unused']    += self::STATUS_UNUSED === $item['status'] ? 1 : 0;
			$summary['missing']   += self::STATUS_MISSING === $item['status'] ? 1 : 0;
			$summary['updates']   += null !== $item['update_version'] ? 1 : 0;
		}
		return $summary;
	}
}
```

- [ ] **Step 5: Create `includes/Rest/ThemesController.php`**

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /themes et GET /themes/{stylesheet}/sites (sites où le thème est actif ou parent du thème actif).
 */
final class ThemesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'themes';

	private ThemesQuery $themes;
	private SitesQuery $sites;

	public function __construct( ThemesQuery $themes, SitesQuery $sites ) {
		$this->themes = $themes;
		$this->sites  = $sites;
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
			]
		);
		// Même motif que la route wp/v2/themes du cœur : un dossier, éventuellement dans un sous-dossier.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)/sites',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_sites' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => self::sites_page_params(),
				],
			]
		);
	}

	public function get_collection_params(): array {
		return self::inventory_params( ThemesQuery::STATUSES );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result = $this->themes->list( self::inventory_args( $request ) );
				return $this->paginated( $result['items'], $result['total'], (int) $request['per_page'] );
			}
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sites( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$theme = $this->themes->find( (string) $request['stylesheet'] );
				if ( null === $theme ) {
					return new WP_Error( 'msradar_theme_not_found', __( 'This theme is neither installed nor used by any site.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return $this->sites_page( $this->sites, [ 'theme' => $theme['stylesheet'] ], $request );
			}
		);
	}
}
```

- [ ] **Step 6: Wire the services in `includes/Plugin.php`**

- `use MultisiteRadar\Query\ThemesQuery;` et `use MultisiteRadar\Rest\ThemesController;` ;
- propriété `private ?ThemesQuery $themes_query = null;` ;
- méthode :

```php
	public function themes_query(): ThemesQuery {
		return $this->themes_query ??= new ThemesQuery( $this->sites() );
	}
```

- dans `register_rest_routes()`, ajouter `new ThemesController( $this->themes_query(), $this->sites_query() ),` après `PluginsController`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'SitesRepositoryTest|ThemesQueryTest|ThemesControllerTest|SitesQueryTest'`
Expected: PASS. `SitesQueryTest` vérifie que les nouvelles fixtures de thèmes ne gênent pas son test des noms de thèmes.

- [ ] **Step 8: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Storage/SitesRepository.php includes/Query/ThemesQuery.php includes/Rest/ThemesController.php includes/Plugin.php tests/php/fixtures/themes/ tests/php/Storage/SitesRepositoryTest.php tests/php/Query/ThemesQueryTest.php tests/php/Rest/ThemesControllerTest.php
git commit -m "feat: list the network themes, their parents and the sites that use them"
```

---

### Task 4: Utilisateurs — `GET /users`

Liste des comptes de l'installation avec leur nombre de sites, en SQL paginé, mise en cache objet 10 minutes (spec §5.1). Un compte appartient à un site quand il a une clé `{base}capabilities` (site 1) ou `{base}{id}_capabilities` pour un site qui existe dans `blogs` : c'est la règle de `get_blogs_of_user()` dans le cœur. Le comptage porte sur tous les réseaux (écart E3).

**Files:**
- Create: `includes/Storage/UsersRepository.php`
- Create: `includes/Query/UsersQuery.php`
- Create: `includes/Rest/UsersController.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Query/UsersQueryTest.php` (créé), `tests/php/Rest/UsersControllerTest.php` (créé)

**Interfaces:**
- Produces :
  - `UsersRepository::ORDERBY` (clé publique => expression SQL), `UsersRepository::query( array $args ): array{items: array<int, array{id: int, login: string, display_name: string, registered: string, sites_count: int}>, total: int}`. `$args` est déjà normalisé : `search`, `membership` (`''`, `none`, `several`), `logins` (`null` ou `string[]`), `orderby`, `order`, `page`, `per_page` ;
  - `UsersQuery::ORDERBY = [ 'login', 'display_name', 'sites_count', 'registered' ]`, `UsersQuery::MEMBERSHIPS = [ 'none', 'several' ]`, `UsersQuery::CACHE_GROUP = 'msradar'`, `UsersQuery::CACHE_TTL = 600`, `UsersQuery::defaults()`, `->list( array $args ): array{items, total}` ;
  - un compte REST : `{ id, login, display_name, super_admin, sites_count, registered_gmt, edit_url }`, sans adresse e-mail ;
  - `Plugin::users_query(): UsersQuery`.

- [ ] **Step 1: Write the failing tests**

Créer `tests/php/Query/UsersQueryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\UsersQuery;
use MultisiteRadar\Tests\TestCase;

final class UsersQueryTest extends TestCase {

	private int $site_a;
	private int $site_b;
	private int $solo;
	private int $multi;
	private int $nobody;

	public function set_up(): void {
		parent::set_up();
		$this->site_a = self::factory()->blog->create();
		$this->site_b = self::factory()->blog->create();
		$this->solo   = self::factory()->user->create(
			[
				'user_login'   => 'radar_solo',
				'display_name' => 'Solo',
			]
		);
		$this->multi  = self::factory()->user->create(
			[
				'user_login'   => 'radar_multi',
				'display_name' => 'Multi',
			]
		);
		$this->nobody = self::factory()->user->create(
			[
				'user_login'   => 'radar_nobody',
				'display_name' => 'Nobody',
			]
		);
		// La fabrique donne le rôle par défaut sur le site courant : on repart d'appartenances connues.
		foreach ( [ $this->solo, $this->multi, $this->nobody ] as $user ) {
			remove_user_from_blog( $user, get_current_blog_id() );
		}
		add_user_to_blog( $this->site_a, $this->solo, 'editor' );
		add_user_to_blog( $this->site_a, $this->multi, 'author' );
		add_user_to_blog( $this->site_b, $this->multi, 'author' );
	}

	private function query(): UsersQuery {
		return $this->plugin()->users_query();
	}

	private function logins( array $args ): array {
		return array_values( wp_list_pluck( $this->query()->list( array_merge( [ 'search' => 'radar_' ], $args ) )['items'], 'login' ) );
	}

	private function find( string $login ): array {
		return $this->query()->list( [ 'search' => $login ] )['items'][0];
	}

	public function test_counts_the_sites_of_every_account_without_email(): void {
		$result = $this->query()->list( [ 'search' => 'radar_' ] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ 'radar_multi', 'radar_nobody', 'radar_solo' ], wp_list_pluck( $result['items'], 'login' ) );
		$this->assertSame( [ 2, 0, 1 ], wp_list_pluck( $result['items'], 'sites_count' ) );
		$multi = $result['items'][0];
		$this->assertSame( [ 'id', 'login', 'display_name', 'super_admin', 'sites_count', 'registered_gmt', 'edit_url' ], array_keys( $multi ) );
		$this->assertSame( $this->multi, $multi['id'] );
		$this->assertSame( network_admin_url( 'user-edit.php?user_id=' . $this->multi ), $multi['edit_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $multi['registered_gmt'] );
	}

	public function test_keys_of_deleted_sites_and_lookalike_keys_are_not_memberships(): void {
		global $wpdb;
		update_user_meta( $this->nobody, $wpdb->base_prefix . '999999_capabilities', [ 'editor' => true ] );
		update_user_meta( $this->nobody, $wpdb->base_prefix . $this->site_a . '_foo_capabilities', [ 'editor' => true ] );

		$this->assertSame( 0, $this->find( 'radar_nobody' )['sites_count'] );
	}

	public function test_filters_sorting_and_pages(): void {
		$this->assertSame( [ 'radar_nobody' ], $this->logins( [ 'membership' => 'none' ] ) );
		$this->assertSame( [ 'radar_multi' ], $this->logins( [ 'membership' => 'several' ] ) );
		$this->assertSame( [ 'radar_multi', 'radar_nobody', 'radar_solo' ], $this->logins( [ 'membership' => 'many' ] ), 'Unknown values are ignored.' );
		$this->assertSame(
			[ 'radar_multi', 'radar_solo', 'radar_nobody' ],
			$this->logins(
				[
					'orderby' => 'sites_count',
					'order'   => 'desc',
				]
			)
		);
		$this->assertSame( [ 'radar_solo' ], $this->logins( [ 'search' => 'Solo' ] ), 'The display name is searched too.' );
		$this->assertSame(
			[ 'radar_nobody' ],
			$this->logins(
				[
					'per_page' => 1,
					'page'     => 2,
				]
			)
		);
	}

	public function test_the_super_admin_filter_never_lists_everyone(): void {
		grant_super_admin( $this->solo );
		$this->assertSame( [ 'radar_solo' ], $this->logins( [ 'super_admin' => true ] ) );
		$this->assertTrue( $this->find( 'radar_solo' )['super_admin'] );

		update_site_option( 'site_admins', [ 'radar_deleted_login' ] );
		$this->assertSame( [], $this->logins( [ 'super_admin' => true ] ) );

		update_site_option( 'site_admins', [] );
		$this->assertSame( [], $this->logins( [ 'super_admin' => true ] ) );
	}

	public function test_results_are_cached_until_a_membership_changes(): void {
		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, 'AS sites_count' ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			$first = $this->query()->list( [ 'search' => 'radar_' ] );
			$again = $this->query()->list( [ 'search' => 'radar_' ] );
			$this->assertSame( $first, $again );
			$this->assertSame( 2, $queries, 'One count and one page, then the cache.' );

			add_user_to_blog( $this->site_b, $this->solo, 'subscriber' );
			$after = $this->query()->list( [ 'search' => 'radar_' ] );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertSame( 4, $queries, 'The same request is read again once a membership changed.' );
		$this->assertSame( [ 2, 0, 2 ], wp_list_pluck( $after['items'], 'sites_count' ) );
	}
}
```

Créer `tests/php/Rest/UsersControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class UsersControllerTest extends RestTestCase {

	public function test_lists_accounts_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/users' )->get_status() );

		$this->login_as_super_admin();
		self::factory()->user->create( [ 'user_login' => 'radar_rest_a' ] );
		self::factory()->user->create( [ 'user_login' => 'radar_rest_b' ] );

		$response = $this->request(
			'GET',
			'/users',
			[
				'search'   => 'radar_rest_',
				'per_page' => 1,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 'radar_rest_a', $response->get_data()[0]['login'] );
		$this->assertArrayNotHasKey( 'email', $response->get_data()[0] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $response->get_data() ) );

		foreach ( [ [ 'membership' => 'many' ], [ 'orderby' => 'email' ], [ 'per_page' => 101 ], [ 'super_admin' => 'maybe' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/users', $params )->get_status() );
		}
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS sites_count' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/users', [ 'search' => 'nobody-matches-this' ] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'UsersQueryTest|UsersControllerTest'`
Expected: FAIL. `users_query()` n'existe pas.

- [ ] **Step 3: Create `includes/Storage/UsersRepository.php`**

```php
<?php
namespace MultisiteRadar\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * SQL des comptes de l'installation (tables globales users, usermeta, blogs) et de leur nombre de sites.
 */
final class UsersRepository {

	/**
	 * Tri autorisé : clé publique => expression SQL.
	 */
	public const ORDERBY = [
		'login'        => 'u.user_login',
		'display_name' => 'u.display_name',
		'sites_count'  => 'COALESCE(c.sites_count, 0)',
		'registered'   => 'u.user_registered',
	];

	/**
	 * Comptes paginés en SQL, avec leur nombre de sites.
	 *
	 * Une appartenance est une clé {base}capabilities (site 1) ou {base}{id}_capabilities, pour un site qui existe
	 * encore dans blogs (la règle de get_blogs_of_user()). Le préfixe ne contient que [A-Za-z0-9_] (WordPress le
	 * vérifie), il peut donc entrer tel quel dans l'expression régulière.
	 *
	 * @param array $args search, membership ('' | none | several), logins (null ou string[]), orderby, order, page, per_page.
	 * @return array{items: array<int, array{id: int, login: string, display_name: string, registered: string, sites_count: int}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$base        = $wpdb->base_prefix;
		$memberships = 'SELECT m.user_id, COUNT(*) AS sites_count FROM %i AS m INNER JOIN %i AS b ON b.blog_id = (CASE WHEN m.meta_key = %s THEN 1 ELSE CAST(SUBSTRING_INDEX(SUBSTRING(m.meta_key, %d), %s, 1) AS UNSIGNED) END) WHERE m.meta_key LIKE %s AND m.meta_key REGEXP %s GROUP BY m.user_id';
		$params      = [
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->blogs,
			$base . 'capabilities',
			strlen( $base ) + 1,
			'_',
			$wpdb->esc_like( $base ) . '%capabilities',
			'^' . $base . '([0-9]+_)?capabilities$',
		];

		$where = [];
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(u.user_login LIKE %s OR u.display_name LIKE %s)';
			array_push( $params, $like, $like );
		}
		if ( 'none' === $args['membership'] ) {
			$where[] = 'c.sites_count IS NULL';
		} elseif ( 'several' === $args['membership'] ) {
			$where[] = 'c.sites_count >= 2';
		}
		if ( null !== $args['logins'] ) {
			$logins = array_values( array_map( 'strval', (array) $args['logins'] ) );
			// Une liste vide ne retient personne : jamais tous les comptes.
			$where[] = [] === $logins ? '1 = 0' : 'u.user_login IN (' . implode( ',', array_fill( 0, count( $logins ), '%s' ) ) . ')';
			$params  = array_merge( $params, $logins );
		}

		$from      = "%i AS u LEFT JOIN ({$memberships}) AS c ON c.user_id = u.ID";
		$condition = [] === $where ? '1 = 1' : implode( ' AND ', $where );
		$sort      = self::ORDERBY[ $args['orderby'] ] ?? self::ORDERBY['login'];
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $from et $condition ne contiennent que des fragments fixes et des placeholders ; $sort vient d'une liste blanche ; $direction vaut ASC ou DESC.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", $params ) );
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.display_name, u.user_registered, COALESCE(c.sites_count, 0) AS sites_count FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
				array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

		return [
			'items' => array_map(
				static fn ( array $row ): array => [
					'id'           => (int) $row['ID'],
					'login'        => (string) $row['user_login'],
					'display_name' => (string) $row['display_name'],
					'registered'   => (string) $row['user_registered'],
					'sites_count'  => (int) $row['sites_count'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}

	/**
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
```

L'ordre des placeholders suit l'ordre du texte SQL : `%i` des comptes (`FROM %i AS u`), puis ceux de la sous-requête, puis ceux du `WHERE`, puis `LIMIT` et `OFFSET`. Le premier élément de `$params` est donc `$wpdb->users`.

- [ ] **Step 4: Create `includes/Query/UsersQuery.php`**

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\UsersRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Comptes de l'installation et nombre de sites de chacun, tous réseaux confondus (écart E3 du plan M3).
 * Aucune adresse e-mail n'est lue ni renvoyée. Les résultats restent 10 minutes en cache objet ; la clé change dès
 * qu'un compte ou une appartenance change (last_changed « users »), qu'un site est créé ou supprimé (« sites »),
 * ou que la liste des super-admins change.
 */
final class UsersQuery {

	public const ORDERBY     = [ 'login', 'display_name', 'sites_count', 'registered' ];
	public const MEMBERSHIPS = [ 'none', 'several' ];
	public const CACHE_GROUP = 'msradar';
	public const CACHE_TTL   = 600;

	private UsersRepository $users;

	public function __construct( UsersRepository $users ) {
		$this->users = $users;
	}

	public static function defaults(): array {
		return [
			'page'        => 1,
			'per_page'    => 20,
			'search'      => '',
			'membership'  => '',
			'super_admin' => false,
			'orderby'     => 'login',
			'order'       => 'asc',
		];
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args    = array_merge( self::defaults(), $args );
		$supers  = array_values( array_filter( array_map( 'strval', (array) get_super_admins() ), 'strlen' ) );
		$orderby = (string) $args['orderby'];
		$query   = [
			'search'     => trim( (string) $args['search'] ),
			'membership' => in_array( $args['membership'], self::MEMBERSHIPS, true ) ? (string) $args['membership'] : '',
			'logins'     => $args['super_admin'] ? $supers : null,
			'orderby'    => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'login',
			'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
		];

		$key    = 'users:' . md5( (string) wp_json_encode( [ $query, $supers ] ) ) . ':' . wp_cache_get_last_changed( 'users' ) . ':' . wp_cache_get_last_changed( 'sites' );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->users->query( $query );
		$items  = [];
		foreach ( $result['items'] as $row ) {
			$items[] = [
				'id'             => $row['id'],
				'login'          => $row['login'],
				'display_name'   => $row['display_name'],
				'super_admin'    => in_array( $row['login'], $supers, true ),
				'sites_count'    => $row['sites_count'],
				'registered_gmt' => '' !== $row['registered'] && '0000-00-00 00:00:00' !== $row['registered'] ? mysql_to_rfc3339( $row['registered'] ) : null,
				'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $row['id'] ),
			];
		}
		$list = [
			'items' => $items,
			'total' => $result['total'],
		];
		wp_cache_set( $key, $list, self::CACHE_GROUP, self::CACHE_TTL );
		return $list;
	}
}
```

- [ ] **Step 5: Create `includes/Rest/UsersController.php`**

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\UsersQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /users : comptes de l'installation, nombre de sites, super-admins, sans adresse e-mail.
 */
final class UsersController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'users';

	private UsersQuery $users;

	public function __construct( UsersQuery $users ) {
		$this->users = $users;
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
			]
		);
	}

	public function get_collection_params(): array {
		return array_merge(
			self::sites_page_params(),
			[
				'membership'  => [
					'type'    => 'string',
					'default' => '',
					'enum'    => array_merge( [ '' ], UsersQuery::MEMBERSHIPS ),
				],
				'super_admin' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'orderby'     => [
					'type'    => 'string',
					'default' => 'login',
					'enum'    => UsersQuery::ORDERBY,
				],
				'order'       => [
					'type'    => 'string',
					'default' => 'asc',
					'enum'    => [ 'asc', 'desc' ],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				$result   = $this->users->list(
					[
						'page'        => (int) $request['page'],
						'per_page'    => $per_page,
						'search'      => (string) $request['search'],
						'membership'  => (string) $request['membership'],
						'super_admin' => (bool) $request['super_admin'],
						'orderby'     => (string) $request['orderby'],
						'order'       => (string) $request['order'],
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}
}
```

- [ ] **Step 6: Wire the services in `includes/Plugin.php`**

- `use MultisiteRadar\Query\UsersQuery;`, `use MultisiteRadar\Rest\UsersController;` et `use MultisiteRadar\Storage\UsersRepository;` ;
- propriétés `private ?UsersRepository $users_repository = null;` et `private ?UsersQuery $users_query = null;` ;
- méthodes :

```php
	public function users_repository(): UsersRepository {
		return $this->users_repository ??= new UsersRepository();
	}

	public function users_query(): UsersQuery {
		return $this->users_query ??= new UsersQuery( $this->users_repository() );
	}
```

- dans `register_rest_routes()`, ajouter `new UsersController( $this->users_query() ),` après `ThemesController`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'UsersQueryTest|UsersControllerTest'`
Expected: PASS.

- [ ] **Step 8: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Storage/UsersRepository.php includes/Query/UsersQuery.php includes/Rest/UsersController.php includes/Plugin.php tests/php/Query/UsersQueryTest.php tests/php/Rest/UsersControllerTest.php
git commit -m "feat: list the network accounts with their number of sites"
```

---

### Task 5: Fiche site — noms de rôles traduits, super-admins lus une fois, tri stable

Points du document des suites de M2 :
- l'onglet Utilisateurs de la fiche affiche `administrator` ou `editor` au lieu du nom du rôle ;
- `SiteUsersQuery` appelle `is_super_admin()` pour chaque compte (une lecture d'utilisateur chaque fois) ;
- le tri par nom ou par date d'inscription n'a pas de départage, donc deux comptes de même nom peuvent changer de page d'une requête à l'autre.

**Files:**
- Modify: `includes/Query/SiteUsersQuery.php`
- Modify: `src/views/site-panel/users-tab.jsx`
- Test: `tests/php/Query/SiteUsersQueryTest.php`, `src/views/site-panel/test/site-panel.test.jsx`

**Interfaces:**
- Produces : chaque élément de `GET /sites/{id}/users` gagne `role_names` (`string[]`), les noms des rôles de `roles` dans le même ordre, traduits comme dans l'écran Utilisateurs du cœur (`translate_user_role()`).

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Query/SiteUsersQueryTest.php` :

1. Dans `test_lists_only_the_users_of_the_site_without_email()`, remplacer l'assertion des clés par :

```php
		$this->assertSame( [ 'id', 'login', 'display_name', 'roles', 'role_names', 'super_admin', 'registered_gmt' ], array_keys( $zoe ) );
```

et ajouter :

```php
		$this->assertSame( [ 'Editor' ], $zoe['role_names'] );
```

2. Ajouter :

```php
	public function test_custom_roles_use_the_name_defined_on_the_site(): void {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $this->site_id );
		$roles  = (array) get_blog_option( $this->site_id, $prefix . 'user_roles', [] );
		$roles['reviewer'] = [
			'name'         => 'Reviewer|User role',
			'capabilities' => [ 'read' => true ],
		];
		update_blog_option( $this->site_id, $prefix . 'user_roles', $roles );
		$adam = get_user_by( 'login', 'adam' );
		update_user_meta( $adam->ID, $prefix . 'capabilities', [ 'reviewer' => true ] );

		$items = $this->query()->list( $this->site_id, [ 'search' => 'adam' ] )['items'];

		$this->assertSame( [ 'reviewer' ], $items[0]['roles'] );
		$this->assertSame( [ 'Reviewer' ], $items[0]['role_names'], 'The context after the bar is not shown.' );
	}

	public function test_equal_names_are_ordered_by_id_on_every_page(): void {
		$twin = self::factory()->user->create(
			[
				'user_login'   => 'zed',
				'display_name' => 'Zoé Martin',
			]
		);
		add_user_to_blog( $this->site_id, $twin, 'editor' );

		$pages = [];
		foreach ( [ 1, 2 ] as $page ) {
			$pages[] = wp_list_pluck(
				$this->query()->list(
					$this->site_id,
					[
						'search'   => 'Zoé',
						'orderby'  => 'display_name',
						'order'    => 'desc',
						'per_page' => 1,
						'page'     => $page,
					]
				)['items'],
				'id'
			)[0];
		}

		$this->assertLessThan( $pages[1], $pages[0], 'Ties are broken by ascending ID, whatever the direction.' );
	}
```

Dans `src/views/site-panel/test/site-panel.test.jsx`, dans le test `users are loaded on demand, page by page` :
- ajouter `role_names: [ 'Administrateur' ],` après `roles: [ 'administrator' ],` dans la réponse simulée ;
- ajouter, après `expect( await screen.findByText( 'admin' ) ).toBeInTheDocument();` :

```js
	expect( screen.getByText( 'Administrateur' ) ).toBeInTheDocument();
	expect( screen.queryByText( 'administrator' ) ).not.toBeInTheDocument();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter SiteUsersQueryTest && npx vitest run src/views/site-panel`
Expected: FAIL. `role_names` manque côté PHP, et le panneau affiche `administrator`.

- [ ] **Step 3: Implement in `includes/Query/SiteUsersQuery.php`**

Dans `list()` :

1. Juste avant `$query_args = [`, ajouter :

```php
		$sort       = in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'login';
		$direction  = 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';
```

puis remplacer, dans `$query_args`, les deux lignes `'orderby'` et `'order'` par :

```php
			// Départage par ID, croissant quel que soit le sens : deux comptes de même nom gardent leur page.
			'orderby'     => [
				$sort => $direction,
				'ID'  => 'ASC',
			],
```

2. Remplacer les lignes qui lisent les rôles et bouclent sur les comptes, depuis `$roles  = array_keys( … );` jusqu'à la fin du `foreach`, par :

```php
		$definitions = (array) get_blog_option( $site_id, $prefix . 'user_roles', [] );
		$roles       = array_map( 'strval', array_keys( $definitions ) );
		$supers      = array_map( 'strval', (array) get_super_admins() );
		update_meta_cache( 'user', array_map( static fn ( $user ): int => (int) $user->ID, $users ) );

		$items = [];
		foreach ( $users as $user ) {
			$caps       = get_user_meta( (int) $user->ID, $prefix . 'capabilities', true );
			$granted    = array_map( 'strval', array_keys( array_filter( is_array( $caps ) ? $caps : [] ) ) );
			// Les capacités accordées individuellement ne sont pas des rôles.
			$user_roles = [] !== $roles ? array_values( array_intersect( $granted, $roles ) ) : $granted;
			$items[]    = [
				'id'             => (int) $user->ID,
				'login'          => (string) $user->user_login,
				'display_name'   => (string) $user->display_name,
				'roles'          => $user_roles,
				'role_names'     => array_map( static fn ( string $role ): string => self::role_name( $role, $definitions ), $user_roles ),
				'super_admin'    => in_array( (string) $user->user_login, $supers, true ),
				'registered_gmt' => '' !== (string) $user->user_registered ? mysql_to_rfc3339( (string) $user->user_registered ) : null,
			];
		}
```

3. Ajouter la méthode :

```php
	/**
	 * Nom d'un rôle dans la langue du lecteur, comme l'écran Utilisateurs du cœur ; son identifiant si le site ne le
	 * définit pas.
	 *
	 * @param array $definitions Option {prefix}user_roles du site.
	 */
	private static function role_name( string $role, array $definitions ): string {
		$name = $definitions[ $role ]['name'] ?? '';
		return is_string( $name ) && '' !== $name ? translate_user_role( $name ) : $role;
	}
```

- [ ] **Step 4: Show the names in `src/views/site-panel/users-tab.jsx`**

Remplacer `<td>{ user.roles.join( ', ' ) || '—' }</td>` par :

```jsx
							<td>
								{ ( user.role_names || user.roles ).join(
									', '
								) || '—' }
							</td>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'SiteUsersQueryTest|SitesControllerTest' && npx vitest run src/views/site-panel`
Expected: PASS.

- [ ] **Step 6: Run the checks and commit**

Run: `composer lint && composer analyse && npm run lint:js`
Expected: PASS.

```bash
git add includes/Query/SiteUsersQuery.php src/views/site-panel/users-tab.jsx tests/php/Query/SiteUsersQueryTest.php src/views/site-panel/test/site-panel.test.jsx
git commit -m "fix: show translated role names and keep a stable order in the site users tab"
```

---

### Task 6: Synthèse de l'inventaire — `GET /inventory/summary`

**Files:**
- Create: `includes/Query/InventoryQuery.php`
- Create: `includes/Rest/InventoryController.php`
- Modify: `includes/Plugin.php`
- Test: `tests/php/Rest/InventoryControllerTest.php` (créé)

**Interfaces:**
- Consumes : `PluginsQuery::summary()` (tâche 2), `ThemesQuery::summary()` (tâche 3), `SitesRepository::count_pending( int $network_id ): int`.
- Produces :
  - `InventoryQuery::summary(): array{pending_sites: int, plugins: array{installed: int, network: int, unused: int, missing: int, updates: int}, themes: array{installed: int, unused: int, missing: int, updates: int}}` ;
  - `GET /inventory/summary` renvoie ce tableau ;
  - `Plugin::inventory_query(): InventoryQuery`.

- [ ] **Step 1: Write the failing test**

Créer `tests/php/Rest/InventoryControllerTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class InventoryControllerTest extends RestTestCase {

	public function test_summarises_plugins_themes_and_sites_still_to_analyse(): void {
		$this->assertSame( 401, $this->request( 'GET', '/inventory/summary' )->get_status() );

		$this->login_as_super_admin();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
				],
			],
			'plugins'
		);
		$this->make_record( 991, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 992 );
		$pending = $this->plugin()->sites()->count_pending( get_current_network_id() );

		$response = $this->request( 'GET', '/inventory/summary' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'pending_sites', 'plugins', 'themes' ], array_keys( $data ) );
		$this->assertSame( $pending, $data['pending_sites'] );
		$this->assertGreaterThanOrEqual( 1, $data['pending_sites'] );
		$this->assertSame(
			[
				'installed' => 1,
				'network'   => 0,
				'unused'    => 1,
				'missing'   => 0,
				'updates'   => 0,
			],
			$data['plugins']
		);
		$this->assertSame( [ 'installed', 'unused', 'missing', 'updates' ], array_keys( $data['themes'] ) );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY e.slug' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/inventory/summary' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
```

Ce test suppose qu'aucun plugin n'est activé sur le réseau de test (`active_sitewide_plugins` vide) : Multisite Radar y est chargé comme MU-plugin par `tests/php/bootstrap.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `bin/test.sh --filter InventoryControllerTest`
Expected: FAIL. La route n'existe pas (le premier appel renvoie 404 au lieu de 401).

- [ ] **Step 3: Create `includes/Query/InventoryQuery.php`**

```php
<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Synthèse de l'inventaire pour la Vue d'ensemble et l'avis des pages Plugins et Thèmes : tant que des sites restent
 * à analyser, les plugins et thèmes qu'ils utilisent ne sont pas comptés (écart E8 du plan M3).
 */
final class InventoryQuery {

	private PluginsQuery $plugins;
	private ThemesQuery $themes;
	private SitesRepository $sites;

	public function __construct( PluginsQuery $plugins, ThemesQuery $themes, SitesRepository $sites ) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
		$this->sites   = $sites;
	}

	/**
	 * @return array{pending_sites: int, plugins: array, themes: array}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function summary(): array {
		return [
			'pending_sites' => $this->sites->count_pending( get_current_network_id() ),
			'plugins'       => $this->plugins->summary(),
			'themes'        => $this->themes->summary(),
		];
	}
}
```

- [ ] **Step 4: Create `includes/Rest/InventoryController.php`**

```php
<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\InventoryQuery;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /inventory/summary : compteurs des plugins et des thèmes, sites encore à analyser (écart E2 du plan M3).
 */
final class InventoryController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'inventory';

	private InventoryQuery $inventory;

	public function __construct( InventoryQuery $inventory ) {
		$this->inventory = $inventory;
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

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary() {
		return $this->guard(
			function (): WP_REST_Response {
				return new WP_REST_Response( $this->inventory->summary() );
			}
		);
	}
}
```

- [ ] **Step 5: Wire the services in `includes/Plugin.php`**

- `use MultisiteRadar\Query\InventoryQuery;` et `use MultisiteRadar\Rest\InventoryController;` ;
- propriété `private ?InventoryQuery $inventory_query = null;` ;
- méthode :

```php
	public function inventory_query(): InventoryQuery {
		return $this->inventory_query ??= new InventoryQuery( $this->plugins_query(), $this->themes_query(), $this->sites() );
	}
```

- dans `register_rest_routes()`, ajouter `new InventoryController( $this->inventory_query() ),` après `UsersController`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `bin/test.sh --filter InventoryControllerTest`
Expected: PASS.

- [ ] **Step 7: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Query/InventoryQuery.php includes/Rest/InventoryController.php includes/Plugin.php tests/php/Rest/InventoryControllerTest.php
git commit -m "feat: summarise the inventory for the overview"
```

---

### Task 7: Exports des plugins et des thèmes, marqueur d'export interrompu

`ExportHandler` ne connaît aujourd'hui que les sites. Cette tâche introduit une interface `ExportSource`, une source par ressource (sites, plugins, thèmes), et termine un fichier interrompu en cours de flux par un marqueur visible (point du document des suites de M2) :
- en CSV, une dernière ligne d'une seule cellule ;
- en JSON, les clés `incomplete` et `error` après `items`, ce qui laisse un document valide.

**Files:**
- Create: `includes/Export/ExportSource.php`, `includes/Export/SitesExport.php`, `includes/Export/InventoryExport.php`, `includes/Export/PluginsExport.php`, `includes/Export/ThemesExport.php`
- Modify: `includes/Export/ExportHandler.php`, `includes/Export/JsonWriter.php`, `includes/Export/SitesColumns.php` (suppression de `select()`, devenue inutile)
- Modify: `includes/Plugin.php`
- Test: `tests/php/Export/ExportHandlerTest.php`, `tests/php/Export/JsonWriterTest.php`

**Interfaces:**
- Consumes : `PluginsQuery::filtered()`, `ThemesQuery::filtered()` (tâches 2 et 3), `SitesQuery::list()` et `::each()`.
- Produces :
  - `ExportSource` : `columns(): array<string, string>`, `filters(): string[]`, `list_filters(): string[]`, `check( array $filters ): void`, `each( array $filters, array $keys, callable $consumer, int $chunk ): int` ;
  - `ExportHandler::RESOURCES = [ 'sites', 'plugins', 'themes' ]`, constructeur `ExportHandler( array $sources )` (ressource => `ExportSource`), `ExportHandler::incomplete_notice(): string` ;
  - colonnes des plugins : `name`, `file`, `version`, `status`, `network_active`, `sites_count`, `update_version` ;
  - colonnes des thèmes : `name`, `stylesheet`, `version`, `parent`, `allowed_on_network`, `status`, `active_count`, `parent_count`, `sites_count`, `update_version` ;
  - filtres des plugins et des thèmes : `search`, `orderby`, `order`, `status` (liste), `has_update` ;
  - `JsonWriter::end( array $extra = [] ): void`.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Export/JsonWriterTest.php`, ajouter :

```php
	public function test_extra_keys_close_an_interrupted_document(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$json   = new JsonWriter( $stream );
		$json->begin( [ 'resource' => 'sites' ] );
		$json->item( [ 'id' => 1 ] );
		$json->end( [ 'incomplete' => true ] );
		rewind( $stream );

		$this->assertSame( '{"meta":{"resource":"sites"},"items":[{"id":1}],"incomplete":true}', stream_get_contents( $stream ) );
	}
```

Dans `tests/php/Export/ExportHandlerTest.php` :

1. Ajouter :

```php
	public function test_plugins_and_themes_are_exported_with_their_own_filters_and_columns(): void {
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => '=Alpha',
						'Version' => '1.0',
					],
					'beta/beta.php'   => [
						'Name'    => 'Beta',
						'Version' => '2.0',
					],
				],
			],
			'plugins'
		);
		$this->plugin()->extensions()->replace_for_site( 801, [ 'alpha/alpha.php' ], '', '' );

		$csv = $this->export(
			[
				'resource' => 'plugins',
				'format'   => 'csv',
				'fields'   => 'name,file,sites_count,network_active',
				'status'   => 'local',
			]
		);
		$this->assertSame( "\xEF\xBB\xBFName,\"Plugin file\",\"Network activated\",Sites\n'=Alpha,alpha/alpha.php,0,1\n", $csv );

		$data = json_decode(
			$this->export(
				[
					'resource' => 'themes',
					'format'   => 'json',
					'fields'   => 'stylesheet,sites_count',
					'search'   => 'astra',
				]
			),
			true
		);
		$this->assertSame( 'themes', $data['meta']['resource'] );
		$this->assertSame( [ 'search' => 'astra' ], $data['meta']['filters'] );
		$this->assertSame(
			[
				[
					'stylesheet'  => 'astra',
					'sites_count' => 1,
				],
			],
			$data['items']
		);
	}

	public function test_each_resource_accepts_only_its_own_filters_and_columns(): void {
		$params = $this->handler->params(
			[
				'resource'    => 'plugins',
				'has_update'  => '1',
				'alert_level' => 'error',
				'fields'      => 'bogus',
			]
		);

		$this->assertSame( [ 'has_update' => '1' ], $params['filters'] );
		$this->assertSame( [ 'name', 'file', 'version', 'status', 'network_active', 'sites_count', 'update_version' ], $params['fields'] );
	}
```

2. Remplacer `test_a_failure_from_the_second_chunk_stops_without_any_markup()` par :

```php
	/**
	 * @dataProvider formats
	 */
	public function test_a_failure_from_the_second_chunk_ends_the_file_with_a_visible_marker( string $format ): void {
		global $wpdb;
		$params = $this->handler->params(
			[
				'format' => $format,
				'fields' => 'id',
			]
		);
		$stream = fopen( 'php://memory', 'w+b' );
		$fired  = [];
		$report = static function ( string $context ) use ( &$fired ): void {
			$fired[] = $context;
		};
		$guard  = $this->break_sites_reads( 1 );
		add_action( 'msradar_error', $report );
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );

		try {
			$completed = $this->handler->stream( $params, $stream, 1 );
		} finally {
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $guard );
			remove_action( 'msradar_error', $report );
		}
		rewind( $stream );
		$output = (string) stream_get_contents( $stream );

		$this->assertFalse( $completed );
		$this->assertSame( [ ExportHandler::class . '::stream' ], $fired );
		$this->assertStringNotContainsString( '<', $output );
		$this->assertStringNotContainsString( 'wp-die', $output );
		if ( 'csv' === $format ) {
			$lines = explode( "\n", trim( $output ) );
			$this->assertGreaterThanOrEqual( 3, count( $lines ), 'Header, first chunk, then the marker.' );
			$this->assertSame( [ ExportHandler::incomplete_notice() ], str_getcsv( (string) end( $lines ), ',', '"', '' ) );
		} else {
			$data = json_decode( $output, true );
			$this->assertIsArray( $data, 'The document stays valid JSON.' );
			$this->assertNotEmpty( $data['items'], 'The first chunk was streamed before the failure.' );
			$this->assertTrue( $data['incomplete'] );
			$this->assertSame( ExportHandler::incomplete_notice(), $data['error'] );
		}
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'ExportHandlerTest|JsonWriterTest'`
Expected: FAIL. La ressource `plugins` est refusée, `end()` n'accepte pas d'argument et `incomplete_notice()` n'existe pas.

- [ ] **Step 3: Create the sources**

`includes/Export/ExportSource.php` :

```php
<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Une ressource exportable : ses colonnes, ses filtres (aux noms de sa route REST) et la lecture de ses éléments.
 */
interface ExportSource {

	/**
	 * @return array<string, string> Clé => en-tête traduit, dans l'ordre d'export.
	 */
	public function columns(): array;

	/**
	 * @return string[] Filtres acceptés.
	 */
	public function filters(): array;

	/**
	 * @return string[] Ceux des filtres qui sont des listes.
	 */
	public function list_filters(): array;

	/**
	 * Première lecture, avant tout envoi : une base illisible donne encore une vraie erreur 500.
	 *
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function check( array $filters ): void;

	/**
	 * Passe chaque élément filtré, réduit aux colonnes $keys dans cet ordre, à $consumer.
	 *
	 * @param string[] $keys Colonnes.
	 * @return int Nombre d'éléments.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int;
}
```

`includes/Export/SitesExport.php` :

```php
<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\SitesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Sites : mêmes filtres que GET /sites, lus par tranches.
 */
final class SitesExport implements ExportSource {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function columns(): array {
		return SitesColumns::all();
	}

	public function filters(): array {
		return [ 'search', 'orderby', 'order', 'alert_level', 'status', 'registry_status', 'rule', 'theme', 'plugin', 'include' ];
	}

	public function list_filters(): array {
		return [ 'alert_level', 'status', 'registry_status', 'include' ];
	}

	public function check( array $filters ): void {
		$this->sites->list( array_merge( $filters, [ 'per_page' => 1 ] ) );
	}

	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int {
		return $this->sites->each(
			$filters,
			static function ( array $item ) use ( $consumer, $keys ): void {
				$consumer( SitesColumns::row( $item, $keys ) );
			},
			$chunk
		);
	}
}
```

`includes/Export/InventoryExport.php` :

```php
<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins et thèmes : mêmes filtres que GET /plugins et GET /themes. Les listes tiennent en mémoire ; les colonnes
 * sont les clés REST, déjà scalaires.
 */
abstract class InventoryExport implements ExportSource {

	public function filters(): array {
		return [ 'search', 'orderby', 'order', 'status', 'has_update' ];
	}

	public function list_filters(): array {
		return [ 'status' ];
	}

	public function check( array $filters ): void {
		$this->items( [] );
	}

	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int {
		$args               = $filters;
		$args['has_update'] = isset( $filters['has_update'] ) && rest_sanitize_boolean( $filters['has_update'] );
		$items              = $this->items( $args );
		foreach ( $items as $item ) {
			$row = [];
			foreach ( $keys as $key ) {
				$row[ $key ] = $item[ $key ] ?? null;
			}
			$consumer( $row );
		}
		return count( $items );
	}

	/**
	 * @return array[] Éléments filtrés et triés, sans pagination.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	abstract protected function items( array $args ): array;
}
```

`includes/Export/PluginsExport.php` :

```php
<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\PluginsQuery;

defined( 'ABSPATH' ) || exit;

final class PluginsExport extends InventoryExport {

	private PluginsQuery $plugins;

	public function __construct( PluginsQuery $plugins ) {
		$this->plugins = $plugins;
	}

	public function columns(): array {
		return [
			'name'           => __( 'Name', 'multisite-radar' ),
			'file'           => __( 'Plugin file', 'multisite-radar' ),
			'version'        => __( 'Version', 'multisite-radar' ),
			'status'         => __( 'Status', 'multisite-radar' ),
			'network_active' => __( 'Network activated', 'multisite-radar' ),
			'sites_count'    => __( 'Sites', 'multisite-radar' ),
			'update_version' => __( 'Update available', 'multisite-radar' ),
		];
	}

	protected function items( array $args ): array {
		return $this->plugins->filtered( $args );
	}
}
```

`includes/Export/ThemesExport.php` :

```php
<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\ThemesQuery;

defined( 'ABSPATH' ) || exit;

final class ThemesExport extends InventoryExport {

	private ThemesQuery $themes;

	public function __construct( ThemesQuery $themes ) {
		$this->themes = $themes;
	}

	public function columns(): array {
		return [
			'name'               => __( 'Name', 'multisite-radar' ),
			'stylesheet'         => __( 'Theme folder', 'multisite-radar' ),
			'version'            => __( 'Version', 'multisite-radar' ),
			'parent'             => __( 'Parent theme', 'multisite-radar' ),
			'allowed_on_network' => __( 'Network enabled', 'multisite-radar' ),
			'status'             => __( 'Status', 'multisite-radar' ),
			'active_count'       => __( 'Active theme of (sites)', 'multisite-radar' ),
			'parent_count'       => __( 'Parent of the active theme of (sites)', 'multisite-radar' ),
			'sites_count'        => __( 'Sites', 'multisite-radar' ),
			'update_version'     => __( 'Update available', 'multisite-radar' ),
		];
	}

	protected function items( array $args ): array {
		return $this->themes->filtered( $args );
	}
}
```

- [ ] **Step 4: Generalise `includes/Export/ExportHandler.php`**

1. Remplacer la docblock de la classe par :

```php
/**
 * Exports CSV et JSON par admin-post.php, avec nonce et capacité msradar_view (spec §5.2) : sites, plugins, thèmes.
 * Les filtres sont ceux de la route REST de la ressource ; les sites sont lus et écrits par tranches de 500.
 */
```

2. Remplacer les constantes, les propriétés et le constructeur par :

```php
	public const ACTION    = 'msradar_export';
	public const RESOURCES = [ 'sites', 'plugins', 'themes' ];
	public const FORMATS   = [ 'csv', 'json' ];

	/**
	 * @var array<string, ExportSource>
	 */
	private array $sources;

	/**
	 * @param array<string, ExportSource> $sources Ressource (RESOURCES) => source.
	 */
	public function __construct( array $sources ) {
		$this->sources = $sources;
	}
```

Supprimer `use MultisiteRadar\Query\SitesQuery;`.

3. Dans `handle()`, remplacer `$this->sites->list( array_merge( $params['filters'], [ 'per_page' => 1 ] ) );` par :

```php
			$this->sources[ $params['resource'] ]->check( $params['filters'] );
```

4. Remplacer `params()` par :

```php
	/**
	 * @param array $input Paramètres de la requête, déjà désinfectés.
	 * @return array{resource: string, format: string, fields: string[], filters: array}|WP_Error
	 */
	public function params( array $input ) {
		$resource = (string) ( $input['resource'] ?? 'sites' );
		if ( ! in_array( $resource, self::RESOURCES, true ) || ! isset( $this->sources[ $resource ] ) ) {
			return new WP_Error( 'msradar_unknown_resource', __( 'This data cannot be exported.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$format = (string) ( $input['format'] ?? 'csv' );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			return new WP_Error( 'msradar_unknown_format', __( 'Unknown export format.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$source  = $this->sources[ $resource ];
		$lists   = $source->list_filters();
		$filters = [];
		foreach ( $source->filters() as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$is_list = in_array( $key, $lists, true );
			if ( ! $is_list && ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$value = $is_list ? self::to_list( $input[ $key ] ) : (string) $input[ $key ];
			if ( '' === $value || [] === $value ) {
				continue;
			}
			$filters[ $key ] = 'include' === $key ? array_map( 'intval', (array) $value ) : $value;
		}

		$known  = array_keys( $source->columns() );
		$fields = array_values( array_intersect( $known, self::to_list( $input['fields'] ?? [] ) ) );
		return [
			'resource' => $resource,
			'format'   => $format,
			'fields'   => [] === $fields ? $known : $fields,
			'filters'  => $filters,
		];
	}
```

5. Remplacer `write()` par :

```php
	/**
	 * Écrit l'export. Si une lecture échoue en cours de route, la sortie est déjà partie : le fichier se termine par
	 * un marqueur visible, puis l'exception remonte à stream(), qui la signale.
	 *
	 * @param array    $params Résultat de params().
	 * @param resource $stream Flux de sortie.
	 * @param int      $chunk  Taille des tranches de lecture.
	 * @return int Nombre d'éléments exportés.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function write( array $params, $stream, int $chunk = 500 ): int {
		$source  = $this->sources[ $params['resource'] ];
		$keys    = (array) $params['fields'];
		$columns = $source->columns();
		$filters = (array) $params['filters'];

		if ( 'csv' === $params['format'] ) {
			$csv = new CsvWriter( $stream );
			$csv->header( array_map( static fn ( string $key ): string => $columns[ $key ], $keys ) );
			try {
				return $source->each( $filters, $keys, [ $csv, 'row' ], $chunk );
			} catch ( \RuntimeException $error ) {
				$csv->row( [ 'incomplete' => self::incomplete_notice() ] );
				throw $error;
			}
		}

		$json = new JsonWriter( $stream );
		$json->begin(
			[
				'generated_gmt' => gmdate( 'Y-m-d\TH:i:s' ),
				'network'       => network_home_url( '/' ),
				'version'       => MSRADAR_VERSION,
				'resource'      => $params['resource'],
				'filters'       => (object) $filters,
				'fields'        => $keys,
			]
		);
		try {
			$count = $source->each( $filters, $keys, [ $json, 'item' ], $chunk );
		} catch ( \RuntimeException $error ) {
			$json->end(
				[
					'incomplete' => true,
					'error'      => self::incomplete_notice(),
				]
			);
			throw $error;
		}
		$json->end();
		return $count;
	}

	/**
	 * Dernière ligne (CSV) ou clé « error » (JSON) d'un export interrompu.
	 */
	public static function incomplete_notice(): string {
		return __( 'Export incomplete: the data could not be read to the end. Run the export again.', 'multisite-radar' );
	}
```

6. Dans `includes/Export/SitesColumns.php`, supprimer la méthode `select()`, qui n'a plus d'appelant.

- [ ] **Step 5: Accept extra keys in `includes/Export/JsonWriter.php`**

Remplacer `end()` par :

```php
	/**
	 * Ferme le document. $extra ajoute des clés après « items », comme le marqueur d'un export interrompu.
	 */
	public function end( array $extra = [] ): void {
		$tail = [] === $extra ? '' : ',' . substr( self::encode( $extra ), 1, -1 );
		$this->put( ']' . $tail . '}' );
	}
```

- [ ] **Step 6: Wire the sources in `includes/Plugin.php`**

Ajouter `use MultisiteRadar\Export\PluginsExport;`, `use MultisiteRadar\Export\SitesExport;` et `use MultisiteRadar\Export\ThemesExport;`, puis remplacer `export()` par :

```php
	public function export(): ExportHandler {
		return $this->export ??= new ExportHandler(
			[
				'sites'   => new SitesExport( $this->sites_query() ),
				'plugins' => new PluginsExport( $this->plugins_query() ),
				'themes'  => new ThemesExport( $this->themes_query() ),
			]
		);
	}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'ExportHandlerTest|JsonWriterTest|CsvWriterTest'`
Expected: PASS.

- [ ] **Step 8: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Export/ includes/Plugin.php tests/php/Export/
git commit -m "feat: export plugins and themes, and mark an export cut short"
```

---

### Task 8: Coquille d'administration — pages, préférences, requêtes préchargées

**Files:**
- Modify: `includes/Admin/Menu.php`, `includes/Admin/ViewQuery.php`, `includes/Admin/Preload.php`
- Modify: `includes/Settings/Preferences.php`
- Modify: `includes/Alerts/RuleRegistry.php`
- Modify: `tests/fixtures/view-queries.json`
- Test: `tests/php/Admin/MenuTest.php`, `tests/php/Admin/ViewQueryTest.php`, `tests/php/Admin/PreloadTest.php`, `tests/php/Admin/AssetsTest.php`, `tests/php/Settings/PreferencesTest.php`

**Interfaces:**
- Consumes :
  - `InventoryList::ORDERBY`, `PluginsQuery::STATUSES` (tâche 2) ;
  - `ThemesQuery::STATUSES` (tâche 3) ;
  - `UsersQuery::ORDERBY`, `UsersQuery::MEMBERSHIPS` (tâche 4) ;
  - la route `/inventory/summary` (tâche 6).
- Produces :
  - `Menu::PAGES` avec `plugins`, `themes`, `users` ;
  - `Preferences::defaults()` et `schema()` avec les vues `plugins`, `themes` et `users` (`fields`, `per_page`) ;
  - `ViewQuery::plugins( array $query, array $prefs ): array`, `ViewQuery::themes(...)`, `ViewQuery::users(...)` ;
  - paramètres d'URL des pages :
    - Plugins et Thèmes : `s`, `paged`, `orderby` (`name`, `sites_count`), `order`, `status` (liste séparée par des virgules), `has_update=1` ;
    - Utilisateurs : `s`, `paged`, `orderby` (`login`, `display_name`, `sites_count`, `registered`), `order`, `membership` (`none`, `several`), `super_admin=1` ;
  - arguments REST correspondants : les mêmes clés, avec `search`, `page`, `per_page`, et `has_update` / `super_admin` à `1` quand ils sont présents ;
  - requêtes préchargées :
    - `overview` : `/preferences`, `/alerts/summary`, `/inventory/summary`, `/scan/status` ;
    - `plugins` : `/preferences`, `/inventory/summary`, `/plugins?…` ;
    - `themes` : `/preferences`, `/inventory/summary`, `/themes?…` ;
    - `users` : `/preferences`, `/users?…`.

- [ ] **Step 1: Write the failing tests**

Dans `tests/fixtures/view-queries.json`, ajouter ces cas à la fin du tableau. L'ordre des clés de `args` compte : c'est celui que produit PHP.

```json
	{
		"name": "sites: a rule followed by a line break is ignored",
		"view": "sites",
		"query": { "rule": "inactive\n" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "plugins: defaults",
		"view": "plugins",
		"query": {},
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "plugins: every parameter",
		"view": "plugins",
		"query": { "s": " akismet ", "orderby": "sites_count", "order": "desc", "paged": "2", "status": "unused,bogus,local", "has_update": "1" },
		"prefs": { "plugins": { "per_page": 50 } },
		"args": { "page": 2, "per_page": 50, "orderby": "sites_count", "order": "desc", "search": "akismet", "status": "local,unused", "has_update": 1 }
	},
	{
		"name": "plugins: invalid values fall back to the defaults",
		"view": "plugins",
		"query": { "orderby": "file", "status": "Unused", "has_update": "yes" },
		"prefs": { "plugins": { "per_page": 7 } },
		"args": { "page": 1, "per_page": 20, "orderby": "name", "order": "asc" }
	},
	{
		"name": "themes: every parameter",
		"view": "themes",
		"query": { "s": "astra", "status": "missing,used", "has_update": "1", "order": "desc" },
		"prefs": { "themes": { "per_page": 100 } },
		"args": { "page": 1, "per_page": 100, "orderby": "name", "order": "desc", "search": "astra", "status": "used,missing", "has_update": 1 }
	},
	{
		"name": "users: defaults",
		"view": "users",
		"query": {},
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "login", "order": "asc" }
	},
	{
		"name": "users: every parameter",
		"view": "users",
		"query": { "s": "jo", "orderby": "sites_count", "order": "desc", "paged": "3", "membership": "several", "super_admin": "1" },
		"prefs": { "users": { "per_page": 10 } },
		"args": { "page": 3, "per_page": 10, "orderby": "sites_count", "order": "desc", "search": "jo", "membership": "several", "super_admin": 1 }
	},
	{
		"name": "users: invalid values are ignored",
		"view": "users",
		"query": { "orderby": "email", "membership": "many", "super_admin": "yes" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "login", "order": "asc" }
	}
```

Dans `tests/php/Admin/ViewQueryTest.php` :

1. Remplacer le corps de `test_matches_the_cases_shared_with_the_client()` par :

```php
		$builders = [
			'sites'   => [ ViewQuery::class, 'sites' ],
			'alerts'  => [ ViewQuery::class, 'alerts' ],
			'plugins' => [ ViewQuery::class, 'plugins' ],
			'themes'  => [ ViewQuery::class, 'themes' ],
			'users'   => [ ViewQuery::class, 'users' ],
		];
		$this->assertSame( $args, call_user_func( $builders[ $view ], $query, $prefs ) );
```

2. Ajouter :

```php
	public function test_paths_skip_empty_values_like_the_client(): void {
		$this->assertSame(
			'/multisite-radar/v1/users?page=1',
			ViewQuery::path(
				'/users',
				[
					'page'       => 1,
					'search'     => '',
					'membership' => null,
				]
			)
		);
	}
```

3. Dans `test_site_id()`, ajouter :

```php
		$this->assertSame( 0, ViewQuery::site_id( [ 'site' => "12\n" ] ) );
```

Dans `tests/php/Admin/MenuTest.php` :
- dans `test_registers_one_page_per_view_for_super_admins()`, la liste attendue devient `[ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-plugins', 'multisite-radar-themes', 'multisite-radar-users', 'multisite-radar-alerts', 'multisite-radar-settings' ]` ;
- dans `test_settings_require_the_manage_capability()`, elle devient `[ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-plugins', 'multisite-radar-themes', 'multisite-radar-users', 'multisite-radar-alerts' ]`.

Dans `tests/php/Admin/PreloadTest.php`, remplacer `test_paths_per_view()` par :

```php
	public function test_paths_per_view(): void {
		$prefs = Preferences::defaults();

		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/scan/status' ], Preload::paths( 'overview', [], $prefs ) );
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20', '/multisite-radar/v1/sites/12' ],
			Preload::paths( 'sites', [ 'site' => '12' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/plugins?has_update=1&order=asc&orderby=name&page=1&per_page=20' ],
			Preload::paths( 'plugins', [ 'has_update' => '1' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/themes?order=asc&orderby=name&page=1&per_page=20&status=unused' ],
			Preload::paths( 'themes', [ 'status' => 'unused' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/users?membership=none&order=asc&orderby=login&page=1&per_page=20' ],
			Preload::paths( 'users', [ 'membership' => 'none' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20' ],
			Preload::paths( 'alerts', [], $prefs )
		);
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings' ], Preload::paths( 'settings', [], $prefs ) );
	}
```

Dans `tests/php/Admin/AssetsTest.php`, `test_a_throwing_preload_leaves_the_page_working_without_that_response()` attend désormais :

```php
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/scan/status' ], array_keys( $config['preload'] ) );
```

Dans `tests/php/Settings/PreferencesTest.php` :
- dans `test_invalid_patches_are_rejected()`, ajouter à la liste des correctifs refusés `[ 'users' => [ 'layout' => 'grid' ] ]` et `[ 'plugins' => [ 'fields' => [ "name\n" ] ] ]` ;
- ajouter :

```php
	public function test_the_inventory_views_have_their_own_preferences(): void {
		$user   = self::factory()->user->create();
		$result = $this->plugin()->preferences()->update(
			$user,
			[
				'plugins' => [ 'per_page' => 50 ],
				'users'   => [ 'fields' => [ 'sites_count' ] ],
			]
		);

		$this->assertSame(
			[
				'fields'   => [],
				'per_page' => 50,
			],
			$result['plugins']
		);
		$this->assertSame(
			[
				'fields'   => [ 'sites_count' ],
				'per_page' => 20,
			],
			$result['users']
		);
		$this->assertSame( Preferences::defaults()['themes'], $result['themes'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/test.sh --filter 'MenuTest|ViewQueryTest|PreloadTest|AssetsTest|PreferencesTest'`
Expected: FAIL. Les nouvelles pages, vues et méthodes n'existent pas ; `ViewQuery::site_id( "12\n" )` vaut 12 ; le cas « a rule followed by a line break » garde la règle.

- [ ] **Step 3: Add the pages to `includes/Admin/Menu.php`**

```php
	public const PAGES = [
		'overview' => 'multisite-radar',
		'sites'    => 'multisite-radar-sites',
		'plugins'  => 'multisite-radar-plugins',
		'themes'   => 'multisite-radar-themes',
		'users'    => 'multisite-radar-users',
		'alerts'   => 'multisite-radar-alerts',
		'settings' => 'multisite-radar-settings',
	];
```

et, dans `titles()` :

```php
		return [
			'overview' => __( 'Overview', 'multisite-radar' ),
			'sites'    => __( 'Sites', 'multisite-radar' ),
			'plugins'  => __( 'Plugins', 'multisite-radar' ),
			'themes'   => __( 'Themes', 'multisite-radar' ),
			'users'    => __( 'Users', 'multisite-radar' ),
			'alerts'   => __( 'Alerts', 'multisite-radar' ),
			'settings' => __( 'Settings', 'multisite-radar' ),
		];
```

Jusqu'aux tâches 11 à 13, ces pages affichent l'avis « interface files are missing » : leur point d'entrée n'existe pas encore.

- [ ] **Step 4: Add the views to `includes/Settings/Preferences.php`**

`defaults()` :

```php
	public static function defaults(): array {
		$list = [
			'fields'   => [],
			'per_page' => 20,
		];
		return [
			'sites'   => [
				'fields'   => [],
				'layout'   => 'table',
				'per_page' => 20,
			],
			'plugins' => $list,
			'themes'  => $list,
			'users'   => $list,
			'alerts'  => $list,
		];
	}
```

Dans `schema()` :
- le motif des champs devient `'pattern' => '^[a-z0-9_]{1,40}\z',` ;
- après la construction de `$per_page`, ajouter :

```php
		$list = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'fields'   => $fields,
				'per_page' => $per_page,
			],
		];
```

- les propriétés deviennent :

```php
			'properties'           => [
				'sites'   => [
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
				'plugins' => $list,
				'themes'  => $list,
				'users'   => $list,
				'alerts'  => $list,
			],
```

- [ ] **Step 5: End the validation patterns with `\z`**

- `includes/Alerts/RuleRegistry.php` : `public const ID_PATTERN = '/^[a-z0-9_]{1,40}\z/';`. `AlertsController` en tire son motif REST par `trim( …, '/' )`, qui donne `^[a-z0-9_]{1,40}\z`, motif PCRE valide pour la validation REST.
- `includes/Admin/ViewQuery.php` : dans `site_id()` et `page()`, `'/^\d+$/'` devient `'/^\d+\z/'`.

- [ ] **Step 6: Add the views to `includes/Admin/ViewQuery.php`**

Ajouter `use MultisiteRadar\Query\InventoryList;`, `use MultisiteRadar\Query\PluginsQuery;`, `use MultisiteRadar\Query\ThemesQuery;` et `use MultisiteRadar\Query\UsersQuery;`. Compléter la docblock de la classe : « … exactement comme le client (src/views/*/query.js et src/views/inventory/query.js) … ».

Ajouter, après `alerts()` :

```php
	public static function plugins( array $query, array $prefs ): array {
		return self::inventory( $query, $prefs['plugins']['per_page'] ?? null, PluginsQuery::STATUSES );
	}

	public static function themes( array $query, array $prefs ): array {
		return self::inventory( $query, $prefs['themes']['per_page'] ?? null, ThemesQuery::STATUSES );
	}

	public static function users( array $query, array $prefs ): array {
		$args       = self::base( $query, $prefs['users']['per_page'] ?? null, UsersQuery::ORDERBY, 'login' );
		$membership = self::text( $query, 'membership' );
		if ( in_array( $membership, UsersQuery::MEMBERSHIPS, true ) ) {
			$args['membership'] = $membership;
		}
		if ( '1' === self::text( $query, 'super_admin' ) ) {
			$args['super_admin'] = 1;
		}
		return $args;
	}

	/**
	 * Plugins et thèmes : arguments communs, statuts dans l'ordre canonique, mises à jour seulement.
	 *
	 * @param mixed    $per_page Préférence enregistrée.
	 * @param string[] $statuses Statuts autorisés.
	 */
	private static function inventory( array $query, $per_page, array $statuses ): array {
		$args   = self::base( $query, $per_page, InventoryList::ORDERBY, 'name' );
		$status = self::subset( $query, 'status', $statuses );
		if ( [] !== $status ) {
			$args['status'] = implode( ',', $status );
		}
		if ( '1' === self::text( $query, 'has_update' ) ) {
			$args['has_update'] = 1;
		}
		return $args;
	}
```

Dans `path()`, ignorer les valeurs vides, comme `buildPath()` côté JS :

```php
	public static function path( string $route, array $args = [] ): string {
		ksort( $args, SORT_STRING );
		$pairs = [];
		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return self::NAMESPACE . $route . ( [] === $pairs ? '' : '?' . implode( '&', $pairs ) );
	}
```

Mettre à jour la docblock de `path()` : « Chemin REST avec clés triées, valeurs vides omises et valeurs encodées par rawurlencode (le client normalise les deux écritures). »

- [ ] **Step 7: Preload the new views in `includes/Admin/Preload.php`**

Remplacer le `switch` de `paths()` par :

```php
		switch ( $view ) {
			case 'overview':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/inventory/summary' );
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
			case 'plugins':
				$paths[] = ViewQuery::path( '/inventory/summary' );
				$paths[] = ViewQuery::path( '/plugins', ViewQuery::plugins( $query, $prefs ) );
				break;
			case 'themes':
				$paths[] = ViewQuery::path( '/inventory/summary' );
				$paths[] = ViewQuery::path( '/themes', ViewQuery::themes( $query, $prefs ) );
				break;
			case 'users':
				$paths[] = ViewQuery::path( '/users', ViewQuery::users( $query, $prefs ) );
				break;
			case 'alerts':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/alerts', ViewQuery::alerts( $query, $prefs ) );
				break;
			case 'settings':
				$paths[] = ViewQuery::path( '/settings' );
				break;
		}
```

La docblock de `paths()` devient `@param string $view  overview, sites, plugins, themes, users, alerts ou settings.`

- [ ] **Step 8: Run the tests to verify they pass**

Run: `bin/test.sh --filter 'MenuTest|ViewQueryTest|PreloadTest|AssetsTest|PreferencesTest|AlertsControllerTest|FooterTest'`
Expected: PASS.

Run: `npx vitest run src/views/sites src/views/alerts`
Expected: PASS. Le cas « a rule followed by a line break » est aussi vérifié côté client, où `RULE_PATTERN` le rejetait déjà.

- [ ] **Step 9: Run the checks and commit**

Run: `bin/test.sh && composer lint && composer analyse`
Expected: PASS.

```bash
git add includes/Admin/Menu.php includes/Admin/ViewQuery.php includes/Admin/Preload.php includes/Settings/Preferences.php includes/Alerts/RuleRegistry.php tests/fixtures/view-queries.json tests/php/Admin/ tests/php/Settings/PreferencesTest.php
git commit -m "feat: add the Plugins, Themes and Users pages with their preloaded queries"
```

---

## Partie C — Interface (JavaScript)

### Task 9: Squelettes de chargement et panneau latéral générique

Le §6.4 de la spec demande des squelettes plutôt que des spinners (point reporté de M2). Cette tâche ajoute un composant `Skeleton`. Il remplace le spinner de DataViews dans les listes sans données, le texte d'attente de la fiche et celui de l'onglet Utilisateurs. Elle extrait aussi de la fiche site la coquille du panneau latéral (`SidePanel`) : la tâche 10 la réutilise pour les sites d'un plugin ou d'un thème.

**Files:**
- Create: `src/components/skeleton.jsx`, `src/components/side-panel.jsx`
- Modify: `src/views/site-panel/index.jsx`, `src/views/site-panel/users-tab.jsx`, `src/views/sites/index.jsx`, `src/views/alerts/index.jsx`, `src/views/overview/index.jsx`, `src/admin/style.scss`
- Test: `src/components/test/components.test.jsx`, `src/views/sites/test/sites-view.test.jsx`, `src/views/alerts/test/alerts-view.test.jsx`

**Interfaces:**
- Produces :
  - `Skeleton( { lines = 3, label } )` (export par défaut de `src/components/skeleton.jsx`) : `role="status"`, `aria-busy="true"`, `label` (par défaut « Loading… ») lu par les lecteurs d'écran ;
  - `SidePanel( { title, focusKey, actions = null, onClose, children } )` (export par défaut de `src/components/side-panel.jsx`) :
    - dialogue non modal nommé par son titre (`#msradar-panel-title`) ;
    - focus sur le titre à l'ouverture et à chaque changement de `focusKey` ;
    - focus rendu à l'élément d'origine à la fermeture ;
    - Échap appelle `onClose` ;
    - `actions` s'affiche avant le bouton « Close ».
  - Les listes DataViews reçoivent `isLoading={ false }` et affichent `<Skeleton />` dans `empty` tant qu'aucune donnée n'est arrivée.

- [ ] **Step 1: Write the failing tests**

Dans `src/components/test/components.test.jsx`, ajouter en tête `import { useState } from '@wordpress/element';`, `import Skeleton from '../skeleton';` et `import SidePanel from '../side-panel';`, puis :

```jsx
test( 'Skeleton announces the loading state and draws lines', () => {
	const { container } = render(
		<Skeleton lines={ 4 } label="Loading plugins…" />
	);

	expect( screen.getByRole( 'status' ) ).toHaveAttribute(
		'aria-busy',
		'true'
	);
	expect( screen.getByText( 'Loading plugins…' ) ).toHaveClass(
		'screen-reader-text'
	);
	expect(
		container.querySelectorAll( '.msradar-skeleton__line' )
	).toHaveLength( 4 );
} );

function PanelHarness( { onClose } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<button onClick={ () => setOpen( true ) }>Open</button>
			{ open && (
				<SidePanel
					title="Akismet"
					focusKey="akismet"
					onClose={ () => {
						onClose();
						setOpen( false );
					} }
				>
					<p>Body</p>
				</SidePanel>
			) }
		</>
	);
}

test( 'SidePanel moves the focus to its title, closes on Escape and gives the focus back', () => {
	const onClose = vi.fn();
	render( <PanelHarness onClose={ onClose } /> );
	const opener = screen.getByRole( 'button', { name: 'Open' } );
	opener.focus();

	fireEvent.click( opener );

	const dialog = screen.getByRole( 'dialog', { name: 'Akismet' } );
	expect( within( dialog ).getByRole( 'heading', { name: 'Akismet' } ) ).toHaveFocus();
	fireEvent.keyDown( dialog, { key: 'Escape' } );
	expect( onClose ).toHaveBeenCalledTimes( 1 );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	expect( opener ).toHaveFocus();
} );
```

Dans `src/views/sites/test/sites-view.test.jsx`, ajouter :

```jsx
test( 'a list that is not preloaded shows a skeleton, not a spinner', () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-sites&s=nothing-preloaded'
	);
	const { container } = renderView();

	expect( screen.getByText( 'Loading sites…' ) ).toBeInTheDocument();
	expect( container.querySelector( '.components-spinner' ) ).toBeNull();
} );
```

Dans `src/views/alerts/test/alerts-view.test.jsx`, ajouter :

```jsx
test( 'a list that is not preloaded shows a skeleton', () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-alerts&s=nothing-preloaded'
	);
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/alerts/summary': {
			body: { by_rule: [] },
			headers: {},
		},
	};
	window.msradarAdmin = {
		view: 'alerts',
		canManage: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );

	render(
		<RegistryProvider value={ registry }>
			<AlertsView />
		</RegistryProvider>
	);

	expect( screen.getByText( 'Loading alerts…' ) ).toBeInTheDocument();
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/components src/views/sites src/views/alerts`
Expected: FAIL. `../skeleton` et `../side-panel` sont introuvables.

- [ ] **Step 3: Create `src/components/skeleton.jsx`**

```jsx
import { __ } from '@wordpress/i18n';

/**
 * Squelette de chargement (spec §6.4) : quelques lignes grises à la place du contenu attendu. Les lecteurs d'écran
 * entendent le libellé.
 *
 * @param {Object} props
 * @param {number} props.lines Nombre de lignes.
 * @param {string} props.label Texte annoncé (par défaut « Loading… »).
 */
export default function Skeleton( { lines = 3, label } ) {
	return (
		<div className="msradar-skeleton" role="status" aria-busy="true">
			<span className="screen-reader-text">
				{ label || __( 'Loading…', 'multisite-radar' ) }
			</span>
			{ Array.from( { length: lines }, ( _, index ) => (
				<span
					key={ index }
					className="msradar-skeleton__line"
					aria-hidden="true"
				/>
			) ) }
		</div>
	);
}
```

- [ ] **Step 4: Create `src/components/side-panel.jsx`**

```jsx
import { Button } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Panneau latéral au-dessus de la liste (fiche d'un site, sites d'un plugin ou d'un thème).
 * Le focus va sur le titre à l'ouverture et à chaque changement de contenu, puis revient à l'élément d'origine à la
 * fermeture ; Échap ferme.
 *
 * @param {Object}        props
 * @param {string}        props.title    Titre, qui nomme le dialogue.
 * @param {string|number} props.focusKey Change avec le contenu (autre site) : le focus revient alors au titre.
 * @param {?Element}      props.actions  Boutons placés avant « Close ».
 * @param {() => void}    props.onClose  Ferme le panneau.
 * @param {Element}       props.children Contenu.
 */
export default function SidePanel( {
	title,
	focusKey,
	actions = null,
	onClose,
	children,
} ) {
	const heading = useRef();
	const opener = useRef( null );

	useEffect( () => {
		const node = heading.current;
		// Capturé avant le premier déplacement du focus, pour le rendre à la fermeture.
		if ( opener.current === null ) {
			opener.current = node.ownerDocument.activeElement;
		}
		node.focus();
	}, [ focusKey ] );
	useEffect(
		() => () => {
			if ( opener.current?.isConnected ) {
				opener.current.focus();
			}
		},
		[]
	);

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- Escape closes the dialog.
		<div
			className="msradar-panel"
			role="dialog"
			aria-modal="false"
			aria-labelledby="msradar-panel-title"
			onKeyDown={ onKeyDown }
		>
			<div className="msradar-panel__header">
				<h2
					id="msradar-panel-title"
					className="msradar-panel__title"
					tabIndex={ -1 }
					ref={ heading }
				>
					{ title }
				</h2>
				{ actions }
				<Button
					icon={ closeSmall }
					label={ __( 'Close', 'multisite-radar' ) }
					onClick={ onClose }
				/>
			</div>
			<div className="msradar-panel__body">{ children }</div>
		</div>
	);
}
```

- [ ] **Step 5: Use them in the site panel**

Remplacer `src/views/site-panel/index.jsx` par :

```jsx
import { Button, TabPanel } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronLeft, chevronRight } from '@wordpress/icons';
import ErrorNotice from '../../components/error-notice';
import SidePanel from '../../components/side-panel';
import Skeleton from '../../components/skeleton';
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
			return <UsersTab key={ site.id } siteId={ site.id } />;
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
 * @param {Object}               props
 * @param {number}               props.siteId     Site affiché.
 * @param {Array}                props.items      Sites de la page courante, pour « précédent » et « suivant ».
 * @param {(id: number) => void} props.onNavigate Reçoit l'identifiant du site à afficher.
 * @param {() => void}           props.onClose    Ferme le panneau.
 */
export default function SitePanel( { siteId, items, onNavigate, onClose } ) {
	const site = useResource( buildPath( `/sites/${ siteId }` ) );
	const data = site.isFresh ? site.data : null;

	const index = items.findIndex( ( item ) => item.id === siteId );
	const previous = index > 0 ? items[ index - 1 ] : null;
	const next =
		index >= 0 && index < items.length - 1 ? items[ index + 1 ] : null;
	const title =
		data?.name ||
		( index >= 0 ? items[ index ].name : '' ) ||
		/* translators: %d: site ID. */
		sprintf( __( 'Site #%d', 'multisite-radar' ), siteId );

	return (
		<SidePanel
			title={ title }
			focusKey={ siteId }
			onClose={ onClose }
			actions={
				<>
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
				</>
			}
		>
			<ErrorNotice error={ site.error } onRetry={ site.retry } />
			{ ! data && ! site.error && (
				<Skeleton
					lines={ 6 }
					label={ __( 'Loading the site…', 'multisite-radar' ) }
				/>
			) }
			{ data && (
				<TabPanel className="msradar-panel__tabs" tabs={ tabs() }>
					{ ( tab ) => <Tab name={ tab.name } site={ data } /> }
				</TabPanel>
			) }
		</SidePanel>
	);
}
```

Dans `src/views/site-panel/users-tab.jsx`, importer `Skeleton from '../../components/skeleton'` et remplacer le bloc `if ( ! users.data ) { … }` par :

```jsx
	if ( ! users.data ) {
		return (
			<Skeleton
				lines={ 4 }
				label={ __( 'Loading users…', 'multisite-radar' ) }
			/>
		);
	}
```

- [ ] **Step 6: Use them in the lists and the overview**

`src/views/sites/index.jsx` : importer `Skeleton from '../../components/skeleton'`, puis, dans `<DataViews … />`, remplacer `isLoading={ list.isLoading && ! list.data }` par `isLoading={ false }` et la prop `empty` par :

```jsx
				empty={
					list.isLoading ? (
						<Skeleton
							lines={ 5 }
							label={ __( 'Loading sites…', 'multisite-radar' ) }
						/>
					) : (
						<p className="msradar-empty">
							{ __(
								'No site matches this view.',
								'multisite-radar'
							) }
						</p>
					)
				}
```

`src/views/alerts/index.jsx` : même changement, avec le libellé `__( 'Loading alerts…', 'multisite-radar' )` et le texte vide existant `'No alert matches this view.'`.

Pendant un changement de page ou de filtre, `useResource` garde la liste précédente affichée : le squelette n'apparaît que tant qu'aucune donnée n'est arrivée.

`src/views/overview/index.jsx` : importer `Skeleton from '../../components/skeleton'` et ajouter, juste avant `{ data && <Tiles summary={ data } /> }` :

```jsx
			{ ! data && ! summary.error && (
				<Skeleton
					lines={ 2 }
					label={ __( 'Loading the overview…', 'multisite-radar' ) }
				/>
			) }
```

- [ ] **Step 7: Style the skeleton in `src/admin/style.scss`**

Supprimer le bloc `&__placeholder { … }` de `.msradar-panel`, qui n'est plus utilisé, et ajouter après `.msradar-panel` :

```scss
// Squelette de chargement : des lignes grises, qui ne pulsent que si l'utilisateur accepte les animations.
.msradar-skeleton {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px 0;

	&__line {
		display: block;
		width: 85%;
		height: 16px;
		border-radius: 2px;
		background: #f0f0f1;

		&:nth-child(3n) {
			width: 60%;
		}
	}
}

@media (prefers-reduced-motion: no-preference) {
	.msradar-skeleton__line {
		animation: msradar-pulse 1.6s ease-in-out infinite;
	}
}

@keyframes msradar-pulse {

	50% {
		opacity: 0.5;
	}
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `npx vitest run src/components src/views`
Expected: PASS, y compris les tests existants de la fiche (focus sur le titre, précédent/suivant, Échap, « Loading the site… » trouvé dans le squelette).

- [ ] **Step 9: Run the checks and commit**

Run: `npm run lint:js && npm run lint:css && npm run test:unit`
Expected: PASS.

```bash
git add src/components/skeleton.jsx src/components/side-panel.jsx src/components/test/components.test.jsx src/views/site-panel/index.jsx src/views/site-panel/users-tab.jsx src/views/sites/index.jsx src/views/sites/test/sites-view.test.jsx src/views/alerts/index.jsx src/views/alerts/test/alerts-view.test.jsx src/views/overview/index.jsx src/admin/style.scss
git commit -m "feat: loading skeletons and a shared side panel"
```

---

### Task 10: Panneau des sites d'une extension, menu d'export générique, préférences des nouvelles vues

**Files:**
- Create: `src/components/pager.jsx`, `src/components/extension-sites-panel.jsx`, `src/components/export-menu.jsx`, `src/utils/export.js`
- Delete: `src/views/sites/export-menu.jsx`
- Modify: `src/views/sites/index.jsx`, `src/views/sites/export.js`, `src/views/site-panel/users-tab.jsx`, `src/hooks/use-preferences.js`, `src/store/paths.js`, `src/admin/config.js` (docblock), `src/admin/style.scss`
- Test: `src/components/test/components.test.jsx`, `src/utils/test/export.test.js` (créé), `src/store/test/paths.test.js`, `src/hooks/test/use-preferences.test.js`, `src/views/sites/test/export.test.js`

**Interfaces:**
- Consumes : `SidePanel`, `Skeleton` (tâche 9) ; `useResource`, `pageUrl`, `displayUrl`, `formatNumber`.
- Produces :
  - `Pager( { page, pages, onChange } )` : « Previous », « Page x of y », « Next » ;
  - `ExtensionSitesPanel( { title, path, note = null, describe = () => null, onClose } )`, où `path( { page, per_page } )` renvoie le chemin REST d'une page de sites. Chaque site est un lien vers sa fiche (`pageUrl( 'sites', { site: id } )`) ;
  - `ExportMenu( { href } )` (`src/components/export-menu.jsx`), où `href( 'csv' | 'json' )` renvoie l'URL de téléchargement ;
  - `exportLink( resource, format, args = {} )` et `columnsFor( fixed, byField, fields )` dans `src/utils/export.js` ;
  - `encodeSegments( id )` dans `src/store/paths.js` ;
  - `DEFAULT_PREFERENCES` avec `sites`, `plugins`, `themes`, `users`, `alerts` ; `mergePreferences()` couvre toutes ces vues.

- [ ] **Step 1: Write the failing tests**

Créer `src/utils/test/export.test.js` :

```js
import { beforeEach, expect, test } from 'vitest';
import { columnsFor, exportLink } from '../export';

beforeEach( () => {
	window.msradarAdmin = {
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'nonce123',
	};
} );

test( 'export links carry the action, the nonce, the resource and the arguments', () => {
	const url = new URL(
		exportLink( 'plugins', 'json', { status: 'unused', has_update: 1 } )
	);

	expect( Object.fromEntries( url.searchParams ) ).toEqual( {
		action: 'msradar_export',
		_wpnonce: 'nonce123',
		resource: 'plugins',
		format: 'json',
		status: 'unused',
		has_update: '1',
	} );
} );

test( 'export columns start with the fixed ones, then follow the visible fields without duplicates', () => {
	expect(
		columnsFor(
			[ 'name', 'file' ],
			{ status: [ 'status', 'network_active' ], version: [ 'version' ] },
			[ 'status', 'unknown', 'version', 'status' ]
		)
	).toEqual( [ 'name', 'file', 'status', 'network_active', 'version' ] );
} );
```

Dans `src/store/test/paths.test.js`, ajouter `encodeSegments` à l'import, puis :

```js
test( 'encodeSegments keeps the slashes and encodes each segment', () => {
	expect( encodeSegments( 'my plugin/my.plugin+été' ) ).toBe(
		'my%20plugin/my.plugin%2B%C3%A9t%C3%A9'
	);
	expect( encodeSegments( 'hello' ) ).toBe( 'hello' );
} );
```

Dans `src/hooks/test/use-preferences.test.js`, remplacer le second test par :

```js
test( 'partial preferences keep their values and default the rest, per view', () => {
	expect(
		mergePreferences( { sites: { per_page: 50 }, users: 'broken' } )
	).toEqual( {
		...DEFAULT_PREFERENCES,
		sites: { ...DEFAULT_PREFERENCES.sites, per_page: 50 },
	} );
	expect( Object.keys( DEFAULT_PREFERENCES ) ).toEqual( [
		'sites',
		'plugins',
		'themes',
		'users',
		'alerts',
	] );
} );
```

Dans `src/components/test/components.test.jsx`, ajouter les imports `import { createRegistry, RegistryProvider } from '@wordpress/data';`, `import { createCoreStore } from '../../store';`, `import ExtensionSitesPanel from '../extension-sites-panel';` et `import ExportMenu from '../export-menu';`, puis :

```jsx
function renderSitesPanel( body, total ) {
	const path = ( args ) =>
		`/multisite-radar/v1/plugins/akismet/akismet/sites?page=${ args.page }&per_page=${ args.per_page }`;
	const preload = {
		[ path( { page: 1, per_page: 20 } ) ]: {
			body,
			headers: {
				'X-WP-Total': String( total ),
				'X-WP-TotalPages': String( Math.ceil( total / 20 ) || 1 ),
			},
		},
	};
	window.msradarAdmin = {
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
	};
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<ExtensionSitesPanel
				title="Akismet"
				path={ path }
				note="Network activated."
				describe={ ( site ) =>
					site.id === 3 ? 'Parent of the active theme' : null
				}
				onClose={ vi.fn() }
			/>
		</RegistryProvider>
	);
}

test( 'ExtensionSitesPanel lists the sites with a link to their panel', () => {
	renderSitesPanel(
		[
			{ id: 2, name: 'Blog RH', url: 'https://example.test/rh/' },
			{ id: 3, name: 'Atelier', url: 'https://example.test/atelier/' },
		],
		2
	);

	const dialog = screen.getByRole( 'dialog', { name: 'Akismet' } );
	expect( within( dialog ).getByText( 'Network activated.' ) ).toBeInTheDocument();
	expect( within( dialog ).getByText( '2 sites' ) ).toBeInTheDocument();
	expect(
		within( dialog ).getByRole( 'link', { name: 'Blog RH' } )
	).toHaveAttribute(
		'href',
		'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites&site=2'
	);
	expect(
		within( dialog ).getByText( 'Parent of the active theme' )
	).toBeInTheDocument();
	expect(
		within( dialog ).queryByRole( 'button', { name: 'Next' } )
	).not.toBeInTheDocument();
} );

test( 'ExtensionSitesPanel says when no analysed site uses the item', () => {
	renderSitesPanel( [], 0 );

	expect(
		screen.getByText( 'No analysed site uses it.' )
	).toBeInTheDocument();
} );

test( 'ExportMenu offers CSV and JSON', () => {
	// Une adresse en fragment : jsdom suit la navigation sans quitter le document.
	const href = vi.fn( ( format ) => `#export-${ format }` );
	render( <ExportMenu href={ href } /> );

	fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );
	fireEvent.click(
		screen.getByRole( 'menuitem', { name: 'Export as JSON' } )
	);

	expect( href ).toHaveBeenCalledWith( 'json' );
	expect( window.location.hash ).toBe( '#export-json' );
} );
```

`src/views/sites/test/export.test.js` doit rester vert sans changement : il fixe le contrat de `exportUrl()` et `exportColumns()` de la vue Sites.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/utils src/store src/hooks src/components`
Expected: FAIL. `../export`, `encodeSegments`, `../extension-sites-panel` et `../export-menu` sont introuvables ; les préférences n'ont pas les nouvelles vues.

- [ ] **Step 3: Create `src/utils/export.js`**

```js
import { addQueryArgs } from '@wordpress/url';
import { getConfig } from '../admin/config';

/**
 * Lien de téléchargement d'un export (admin-post.php, nonce msradar_export, Export\ExportHandler).
 *
 * @param {string} resource sites, plugins ou themes.
 * @param {string} format   csv ou json.
 * @param {Object} args     Filtres, tri et colonnes, aux noms de la route REST de la ressource.
 */
export function exportLink( resource, format, args = {} ) {
	const { exportUrl, exportNonce } = getConfig();
	return addQueryArgs( exportUrl, {
		action: 'msradar_export',
		_wpnonce: exportNonce,
		resource,
		format,
		...args,
	} );
}

/**
 * Colonnes d'un export : les colonnes fixes, puis celles de chaque champ visible, sans doublon.
 *
 * @param {string[]}                 fixed   Colonnes toujours exportées.
 * @param {Object<string, string[]>} byField Champ de la liste => colonnes d'export.
 * @param {string[]}                 fields  Champs visibles.
 */
export function columnsFor( fixed, byField, fields ) {
	const columns = [ ...fixed ];
	( fields || [] ).forEach( ( field ) => {
		( byField[ field ] || [] ).forEach( ( column ) => {
			if ( ! columns.includes( column ) ) {
				columns.push( column );
			}
		} );
	} );
	return columns;
}
```

Dans `src/views/sites/export.js` :
- remplacer les imports par `import { columnsFor, exportLink } from '../../utils/export';` ;
- remplacer le corps de `exportColumns()` par `return columnsFor( [ 'id', 'name', 'url' ], FIELD_COLUMNS, fields );` ;
- dans `exportUrl()`, supprimer la lecture de `getConfig()`, retirer `action`, `_wpnonce`, `resource` et `format` de `args`, et terminer par `return exportLink( 'sites', format, args );`.

- [ ] **Step 4: Create the components**

`src/components/export-menu.jsx` :

```jsx
import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Menu « Export » d'une liste : CSV ou JSON.
 *
 * @param {Object}                     props
 * @param {(format: string) => string} props.href URL de téléchargement pour csv ou json.
 */
export default function ExportMenu( { href } ) {
	return (
		<DropdownMenu
			icon={ download }
			label={ __( 'Export', 'multisite-radar' ) }
			controls={ [
				{
					title: __( 'Export as CSV', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'csv' ) ),
				},
				{
					title: __( 'Export as JSON', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'json' ) ),
				},
			] }
		/>
	);
}
```

Supprimer `src/views/sites/export-menu.jsx`. Dans `src/views/sites/index.jsx`, remplacer `import ExportMenu from './export-menu';` par `import ExportMenu from '../../components/export-menu';` et la prop `header` par :

```jsx
				header={
					<ExportMenu
						href={ ( format ) =>
							exportUrl( format, state, view.fields )
						}
					/>
				}
```

`src/components/pager.jsx` :

```jsx
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Pagination simple : « Previous », « Page x of y », « Next ».
 *
 * @param {Object}                 props
 * @param {number}                 props.page     Page affichée.
 * @param {number}                 props.pages    Nombre de pages.
 * @param {(page: number) => void} props.onChange Reçoit la page demandée.
 */
export default function Pager( { page, pages, onChange } ) {
	return (
		<div className="msradar-pager">
			<Button
				variant="secondary"
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
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
			<Button
				variant="secondary"
				disabled={ page >= pages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'multisite-radar' ) }
			</Button>
		</div>
	);
}
```

Dans `src/views/site-panel/users-tab.jsx`, importer `Pager from '../../components/pager'`, remplacer tout le bloc `<div className="msradar-pager"> … </div>` par `<Pager page={ page } pages={ pages } onChange={ setPage } />`, et retirer les imports devenus inutiles (`Button`, `sprintf`).

`src/components/extension-sites-panel.jsx` :

```jsx
import { Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pageUrl } from '../admin/config';
import { useResource } from '../hooks/use-resource';
import { displayUrl, formatNumber } from '../utils/format';
import ErrorNotice from './error-notice';
import Pager from './pager';
import SidePanel from './side-panel';
import Skeleton from './skeleton';

const PER_PAGE = 20;

/**
 * Sites qui utilisent un plugin ou un thème (GET /plugins/{id}/sites, GET /themes/{stylesheet}/sites), paginés.
 * Chaque site mène à sa fiche dans la page Sites.
 *
 * @param {Object}                    props
 * @param {string}                    props.title    Nom du plugin ou du thème.
 * @param {(args: Object) => string}  props.path     Chemin REST d'une page de sites ({ page, per_page }).
 * @param {?string}                   props.note     Précision affichée au-dessus de la liste.
 * @param {(site: Object) => ?string} props.describe Précision sur un site.
 * @param {() => void}                props.onClose  Ferme le panneau.
 */
export default function ExtensionSitesPanel( {
	title,
	path,
	note = null,
	describe = () => null,
	onClose,
} ) {
	const [ page, setPage ] = useState( 1 );
	const sites = useResource( path( { page, per_page: PER_PAGE } ) );
	const total = sites.total || 0;
	const pages = sites.totalPages || 1;

	return (
		<SidePanel title={ title } focusKey={ title } onClose={ onClose }>
			{ note && (
				<Notice status="info" isDismissible={ false }>
					{ note }
				</Notice>
			) }
			<ErrorNotice error={ sites.error } onRetry={ sites.retry } />
			{ ! sites.data && ! sites.error && (
				<Skeleton
					lines={ 5 }
					label={ __( 'Loading sites…', 'multisite-radar' ) }
				/>
			) }
			{ sites.data && sites.data.length === 0 && (
				<p>{ __( 'No analysed site uses it.', 'multisite-radar' ) }</p>
			) }
			{ sites.data && sites.data.length > 0 && (
				<>
					<p>
						{ sprintf(
							/* translators: %s: number of sites. */
							_n( '%s site', '%s sites', total, 'multisite-radar' ),
							formatNumber( total )
						) }
					</p>
					<ul
						className="msradar-site-list"
						aria-busy={ sites.isLoading }
					>
						{ sites.data.map( ( site ) => {
							const detail = describe( site );
							return (
								<li key={ site.id }>
									<a href={ pageUrl( 'sites', { site: site.id } ) }>
										{ site.name }
									</a>
									<span className="msradar-site-list__url">
										{ displayUrl( site.url ) }
									</span>
									{ detail && (
										<span className="msradar-site-list__detail">
											{ detail }
										</span>
									) }
								</li>
							);
						} ) }
					</ul>
					{ pages > 1 && (
						<Pager page={ page } pages={ pages } onChange={ setPage } />
					) }
				</>
			) }
		</SidePanel>
	);
}
```

- [ ] **Step 5: Add `encodeSegments()` to `src/store/paths.js`**

```js
/**
 * Identifiant à plusieurs segments dans un chemin REST (fichier d'un plugin sans « .php », dossier d'un thème) :
 * chaque segment est encodé, les « / » restent.
 *
 * @param {string} id Identifiant.
 */
export function encodeSegments( id ) {
	return String( id ).split( '/' ).map( encodeURIComponent ).join( '/' );
}
```

- [ ] **Step 6: Add the views to `src/hooks/use-preferences.js`**

```js
export const DEFAULT_PREFERENCES = {
	sites: { fields: [], layout: 'table', per_page: 20 },
	plugins: { fields: [], per_page: 20 },
	themes: { fields: [], per_page: 20 },
	users: { fields: [], per_page: 20 },
	alerts: { fields: [], per_page: 20 },
};

export function mergePreferences( stored ) {
	const value = plain( stored );
	return Object.fromEntries(
		Object.entries( DEFAULT_PREFERENCES ).map( ( [ view, defaults ] ) => [
			view,
			{ ...defaults, ...plain( value[ view ] ) },
		] )
	);
}
```

Dans la docblock de `useDebouncedSave()`, `@param {string} view sites ou alerts.` devient `@param {string} view Vue (clé de DEFAULT_PREFERENCES).`. Dans `src/admin/config.js`, la docblock de `pageUrl()` devient `@param {string} view overview, sites, plugins, themes, users, alerts ou settings.`

- [ ] **Step 7: Style the list in `src/admin/style.scss`**

Ajouter après `.msradar-list li` :

```scss
.msradar-site-list {
	margin: 0 0 16px;

	li {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 8px;
		padding: 8px 0;
		border-bottom: 1px solid #f0f0f1;
	}

	&__url,
	&__detail {
		color: #50575e;
		font-size: 12px;
	}

	&__detail {
		flex-basis: 100%;
	}
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `npx vitest run src`
Expected: PASS, y compris `src/views/sites/test/export.test.js` et les tests du pager de la fiche (`the pager follows X-WP-TotalPages…`).

- [ ] **Step 9: Run the checks and commit**

Run: `npm run lint:js && npm run lint:css && npm run build`
Expected: PASS.

```bash
git add src/components/ src/utils/export.js src/utils/test/export.test.js src/views/sites/ src/views/site-panel/users-tab.jsx src/hooks/ src/store/ src/admin/config.js src/admin/style.scss
git commit -m "feat: side panel of the sites using an extension, shared export menu and inventory preferences"
```

---

### Task 11: Vue Plugins, sur un socle d'inventaire commun aux plugins et aux thèmes

Les pages Plugins et Thèmes ne diffèrent que par leur ressource REST, leurs statuts, leurs champs et la précision du panneau. Cette tâche crée le socle commun `src/views/inventory/` (état dans l'URL, vue DataViews, export, panneau des sites), puis la vue Plugins par-dessus.

**Files:**
- Create: `src/views/inventory/query.js`, `src/views/inventory/fields.jsx`, `src/views/inventory/index.jsx`, `src/views/inventory/test/query.test.js`
- Create: `src/views/plugins/index.jsx`, `src/views/plugins/fields.jsx`, `src/views/plugins/test/plugins-view.test.jsx`
- Create: `src/admin/plugins.js`
- Modify: `webpack.config.js` (`VIEWS`)

**Interfaces:**
- Consumes :
  - `ExtensionSitesPanel`, `ExportMenu`, `exportLink`, `columnsFor`, `encodeSegments`, `DEFAULT_PREFERENCES.plugins` (tâche 10) ;
  - `Skeleton` (tâche 9) ;
  - les routes `GET /plugins`, `GET /plugins/{id}/sites` (tâche 2) et `GET /inventory/summary` (tâche 6) ;
  - les cas `plugins` et `themes` de `tests/fixtures/view-queries.json` (tâche 8).
- Produces :
  - dans `src/views/inventory/query.js` :
    - `INVENTORY_ORDERBY = [ 'name', 'sites_count' ]`, `INVENTORY_STATUSES = { plugins: [ 'network', 'local', 'unused', 'missing' ], themes: [ 'used', 'unused', 'missing' ] }`, `UPDATE_AVAILABLE = 'available'` ;
    - `parseInventoryQuery( query, statuses )` → `{ search, page, orderby, order, status: string[], has_update: boolean }` ;
    - `inventoryRestArgs( state, perPageValue )`, `inventoryPath( resource, state, viewPrefs )`, `extensionSitesPath( resource, id, args )`, `inventoryExportArgs( state, columns )` ;
    - `serializeInventoryState( state )`, `toInventoryView( state, viewPrefs, defaultFields )`, `fromInventoryView( view, current, statuses )`, `inventoryPrefsFromView( view )` ;
  - `ExtensionTitle( { name, detail } )`, `versionField()`, `statusField( labels, tones )`, `sitesCountField( render )`, `updateField()` dans `src/views/inventory/fields.jsx` ;
  - `InventoryView( { resource, fields, defaultFields, exportColumns, labels, panelNote, describeSite } )`, export par défaut de `src/views/inventory/index.jsx` ;
  - `PluginsView`, export par défaut de `src/views/plugins/index.jsx`.

- [ ] **Step 1: Write the failing tests**

Créer `src/views/inventory/test/query.test.js` :

```js
import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	extensionSitesPath,
	fromInventoryView,
	INVENTORY_STATUSES,
	inventoryExportArgs,
	inventoryPath,
	inventoryPrefsFromView,
	inventoryRestArgs,
	parseInventoryQuery,
	serializeInventoryState,
	toInventoryView,
} from '../query';

describe.each(
	cases.filter(
		( item ) => item.view === 'plugins' || item.view === 'themes'
	)
)( '$name', ( { view, query, prefs, args } ) => {
	test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
		expect(
			inventoryRestArgs(
				parseInventoryQuery( query, INVENTORY_STATUSES[ view ] ),
				prefs?.[ view ]?.per_page
			)
		).toEqual( args );
	} );
} );

describe( 'inventory view state', () => {
	const statuses = INVENTORY_STATUSES.plugins;
	const state = parseInventoryQuery(
		{
			s: 'aki',
			status: 'missing,unused',
			has_update: '1',
			orderby: 'sites_count',
			order: 'desc',
			paged: '2',
		},
		statuses
	);

	test( 'round-trips through the address', () => {
		expect(
			parseInventoryQuery( serializeInventoryState( state ), statuses )
		).toEqual( state );
		expect(
			serializeInventoryState( parseInventoryQuery( {}, statuses ) )
		).toEqual( {} );
	} );

	test( 'round-trips through the DataViews view', () => {
		const view = toInventoryView(
			state,
			{ fields: [ 'status' ], per_page: 50 },
			[ 'version' ]
		);

		expect( view.filters ).toEqual( [
			{ field: 'status', operator: 'isAny', value: [ 'unused', 'missing' ] },
			{ field: 'update_version', operator: 'is', value: 'available' },
		] );
		expect( view.fields ).toEqual( [ 'status' ] );
		expect( view.perPage ).toBe( 50 );
		expect( fromInventoryView( view, state, statuses ) ).toEqual( state );
		expect( inventoryPrefsFromView( view ) ).toEqual( {
			fields: [ 'status' ],
			per_page: 50,
		} );
	} );

	test( 'cleared filters and an unknown sort fall back to the defaults', () => {
		expect(
			fromInventoryView(
				{
					filters: [],
					sort: { field: 'version', direction: 'desc' },
					page: 1,
				},
				state,
				statuses
			)
		).toEqual( {
			search: '',
			page: 1,
			orderby: 'name',
			order: 'desc',
			status: [],
			has_update: false,
		} );
	} );

	test( 'paths of the list, the export and the sites of an extension', () => {
		expect( inventoryPath( 'plugins', state, { per_page: 50 } ) ).toBe(
			'/multisite-radar/v1/plugins?has_update=1&order=desc&orderby=sites_count&page=2&per_page=50&search=aki&status=unused%2Cmissing'
		);
		expect( inventoryExportArgs( state, [ 'name', 'file' ] ) ).toEqual( {
			orderby: 'sites_count',
			order: 'desc',
			search: 'aki',
			status: 'unused,missing',
			has_update: 1,
			fields: 'name,file',
		} );
		expect(
			extensionSitesPath( 'plugins', 'my plugin/my.plugin', {
				page: 1,
				per_page: 20,
			} )
		).toBe(
			'/multisite-radar/v1/plugins/my%20plugin/my.plugin/sites?page=1&per_page=20'
		);
	} );
} );
```

Créer `src/views/plugins/test/plugins-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import PluginsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

function plugin( id, name, overrides = {} ) {
	return {
		id,
		file: `${ id }.php`,
		name,
		version: '1.0',
		installed: true,
		network_active: false,
		sites_count: 1,
		status: 'local',
		update_version: null,
		...overrides,
	};
}

async function settle() {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

function renderView( pending = 0 ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/inventory/summary': {
			body: {
				pending_sites: pending,
				plugins: {
					installed: 2,
					network: 1,
					unused: 0,
					missing: 0,
					updates: 1,
				},
				themes: { installed: 1, unused: 0, missing: 0, updates: 0 },
			},
			headers: {},
		},
		'/multisite-radar/v1/plugins?order=asc&orderby=name&page=1&per_page=20':
			{
				body: [
					plugin( 'gamma/gamma', 'Gamma', {
						network_active: true,
						status: 'network',
						sites_count: 12,
					} ),
					plugin( 'my plugin/my.plugin', 'My plugin', {
						update_version: '2.0',
					} ),
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
	};
	window.msradarAdmin = {
		view: 'plugins',
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
			<PluginsView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-plugins'
	);
} );

test( 'renders the preloaded plugins without any request (spec 1.4, criterion 2)', async () => {
	renderView();

	expect( screen.getByText( 'My plugin' ) ).toBeInTheDocument();
	expect( screen.getByText( 'my plugin/my.plugin.php' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Network activated' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Active on some sites' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Version 2.0 available' ) ).toBeInTheDocument();
	expect(
		screen.queryByText( /have not been analysed yet/ )
	).not.toBeInTheDocument();
	await settle();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'warns while some sites have not been analysed', () => {
	renderView( 3 );

	expect(
		screen.getByText(
			'3 sites have not been analysed yet: what they use is not counted below.'
		)
	).toBeInTheDocument();
} );

test( 'a plugin opens the panel of its sites with an encoded path', async () => {
	renderView();

	fireEvent.click( screen.getByText( 'My plugin' ) );

	expect(
		screen.getByRole( 'dialog', { name: 'My plugin' } )
	).toBeInTheDocument();
	await settle();
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/plugins/my%20plugin/my.plugin/sites?page=1&per_page=20',
		parse: false,
	} );
} );

test( 'a network activated plugin says so in its panel', async () => {
	renderView();

	fireEvent.click( screen.getByText( 'Gamma' ) );

	expect(
		within( screen.getByRole( 'dialog', { name: 'Gamma' } ) ).getByText(
			'Network activated: every site of the network loads this plugin.'
		)
	).toBeInTheDocument();
	await settle();
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/views/inventory src/views/plugins`
Expected: FAIL. Les modules `../query` et `..` sont introuvables.

- [ ] **Step 3: Create `src/views/inventory/query.js`**

```js
import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath, encodeSegments } from '../../store/paths';
import {
	MAX_PAGE,
	page,
	perPage,
	subset,
	text,
	trimAscii,
} from '../../utils/view-query';

/**
 * État des pages Plugins et Thèmes (adresse de la page) et arguments REST, avec exactement les règles de PHP
 * (Admin\ViewQuery::plugins() et ::themes()) : tests/fixtures/view-queries.json vérifie la parité.
 */
export const INVENTORY_ORDERBY = [ 'name', 'sites_count' ];
export const INVENTORY_STATUSES = {
	plugins: [ 'network', 'local', 'unused', 'missing' ],
	themes: [ 'used', 'unused', 'missing' ],
};
export const UPDATE_AVAILABLE = 'available';

export function parseInventoryQuery( query, statuses ) {
	const orderby = text( query, 'orderby' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: INVENTORY_ORDERBY.includes( orderby ) ? orderby : 'name',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		status: subset( query, 'status', statuses ),
		has_update: text( query, 'has_update' ) === '1',
	};
}

/**
 * Filtres et tri, communs à la liste et à l'export.
 *
 * @param {Object} state État de la vue.
 */
function filterArgs( state ) {
	const args = { orderby: state.orderby, order: state.order };
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.status.length > 0 ) {
		args.status = state.status.join( ',' );
	}
	if ( state.has_update ) {
		args.has_update = 1;
	}
	return args;
}

export function inventoryRestArgs( state, perPageValue ) {
	return {
		page: state.page,
		per_page: perPage( perPageValue ),
		...filterArgs( state ),
	};
}

export function inventoryPath( resource, state, viewPrefs ) {
	return buildPath(
		`/${ resource }`,
		inventoryRestArgs( state, viewPrefs?.per_page )
	);
}

/**
 * Chemin des sites d'un plugin (identifiant sans « .php ») ou d'un thème (dossier).
 *
 * @param {string} resource plugins ou themes.
 * @param {string} id       Identifiant public.
 * @param {Object} args     Pagination.
 */
export function extensionSitesPath( resource, id, args ) {
	return buildPath(
		`/${ resource }/${ encodeSegments( id ) }/sites`,
		args
	);
}

export function inventoryExportArgs( state, columns ) {
	return { ...filterArgs( state ), fields: columns.join( ',' ) };
}

export function serializeInventoryState( state ) {
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
	if ( state.status.length > 0 ) {
		out.status = state.status.join( ',' );
	}
	if ( state.has_update ) {
		out.has_update = '1';
	}
	return out;
}

export function toInventoryView( state, viewPrefs, defaultFields ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( viewPrefs?.per_page ),
		sort: { field: state.orderby, direction: state.order },
		filters: toFilters( [
			{ field: 'status', operator: 'isAny', value: state.status },
			{
				field: 'update_version',
				operator: 'is',
				value: state.has_update ? UPDATE_AVAILABLE : '',
			},
		] ),
		titleField: 'name',
		fields: viewPrefs?.fields?.length ? viewPrefs.fields : defaultFields,
		layout: {},
	};
}

export function fromInventoryView( view, current, statuses ) {
	const status = filterValue( view.filters, 'status', [] );
	const given = Array.isArray( status ) ? status : [ status ];
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: INVENTORY_ORDERBY.includes( view.sort?.field )
			? view.sort.field
			: 'name',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		status: statuses.filter( ( value ) => given.includes( value ) ),
		has_update:
			filterValue( view.filters, 'update_version', '' ) ===
			UPDATE_AVAILABLE,
	};
}

export function inventoryPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
```

- [ ] **Step 4: Create `src/views/inventory/fields.jsx`**

```jsx
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import { UPDATE_AVAILABLE } from './query';

/**
 * Nom d'un plugin ou d'un thème, avec son fichier ou son dossier en dessous.
 *
 * @param {Object} props
 * @param {string} props.name   Nom.
 * @param {string} props.detail Fichier ou dossier.
 */
export function ExtensionTitle( { name, detail } ) {
	return (
		<span className="msradar-site-title">
			<span className="msradar-site-title__name">{ name }</span>
			<span className="msradar-site-title__url">{ detail }</span>
		</span>
	);
}

export function versionField() {
	return {
		id: 'version',
		type: 'text',
		label: __( 'Version', 'multisite-radar' ),
		enableSorting: false,
		filterBy: false,
		render: ( { item } ) => item.version || '—',
	};
}

/**
 * Statut avec son badge.
 *
 * @param {Object<string, string>} labels Statut => libellé, dans l'ordre des filtres.
 * @param {Object<string, string>} tones  Statut => ton du badge (error, warning, info) ; neutre sinon.
 */
export function statusField( labels, tones ) {
	return {
		id: 'status',
		type: 'text',
		label: __( 'Status', 'multisite-radar' ),
		enableSorting: false,
		elements: Object.entries( labels ).map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) ),
		filterBy: { operators: [ 'isAny' ] },
		render: ( { item } ) => (
			<span
				className={
					tones[ item.status ]
						? `msradar-badge msradar-badge--${ tones[ item.status ] }`
						: 'msradar-badge'
				}
			>
				{ labels[ item.status ] || item.status }
			</span>
		),
	};
}

/**
 * Nombre de sites, triable.
 *
 * @param {?Function} render Rendu particulier ({ item }) => contenu.
 */
export function sitesCountField( render = null ) {
	return {
		id: 'sites_count',
		type: 'integer',
		label: __( 'Sites', 'multisite-radar' ),
		filterBy: false,
		render: render || ( ( { item } ) => formatNumber( item.sites_count ) ),
	};
}

export function updateField() {
	return {
		id: 'update_version',
		type: 'text',
		label: __( 'Update', 'multisite-radar' ),
		enableSorting: false,
		elements: [
			{
				value: UPDATE_AVAILABLE,
				label: __( 'Update available', 'multisite-radar' ),
			},
		],
		filterBy: { operators: [ 'is' ] },
		getValue: ( { item } ) =>
			item.update_version ? UPDATE_AVAILABLE : '',
		render: ( { item } ) =>
			item.update_version ? (
				<span className="msradar-badge msradar-badge--warning">
					{ sprintf(
						/* translators: %s: version number. */
						__( 'Version %s available', 'multisite-radar' ),
						item.update_version
					) }
				</span>
			) : (
				'—'
			),
	};
}
```

- [ ] **Step 5: Create `src/views/inventory/index.jsx`**

```jsx
import { Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { listView } from '@wordpress/icons';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import ExportMenu from '../../components/export-menu';
import ExtensionSitesPanel from '../../components/extension-sites-panel';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { columnsFor, exportLink } from '../../utils/export';
import { samePrefs } from '../../utils/view-query';
import {
	extensionSitesPath,
	fromInventoryView,
	INVENTORY_STATUSES,
	inventoryExportArgs,
	inventoryPath,
	inventoryPrefsFromView,
	parseInventoryQuery,
	serializeInventoryState,
	toInventoryView,
} from './query';

/**
 * Page d'inventaire, commune aux plugins et aux thèmes : liste DataViews dont l'état vit dans l'adresse, préférences
 * de l'utilisateur, export, et panneau des sites qui utilisent l'élément choisi.
 *
 * @param {Object}   props
 * @param {string}   props.resource      plugins ou themes : route REST, préférences, export.
 * @param {Array}    props.fields        Champs DataViews (stables d'un rendu à l'autre).
 * @param {string[]} props.defaultFields Champs visibles par défaut.
 * @param {Object}   props.exportColumns { fixed: string[], byField: Object<string, string[]> }.
 * @param {Object}   props.labels        { search, empty, loading }.
 * @param {Function} props.panelNote     ( item ) => texte au-dessus de la liste des sites, ou null.
 * @param {Function} props.describeSite  ( item, site ) => précision sur un site, ou null.
 */
export default function InventoryView( {
	resource,
	fields,
	defaultFields,
	exportColumns,
	labels,
	panelNote = () => null,
	describeSite = () => null,
} ) {
	const statuses = INVENTORY_STATUSES[ resource ];
	const [ state, setState ] = useUrlState(
		( query ) => parseInventoryQuery( query, statuses ),
		serializeInventoryState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, resource );
	// Les préférences changées s'appliquent tout de suite ; l'enregistrement suit en arrière-plan.
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const viewPrefs = localPrefs || prefs?.[ resource ] || null;
	const [ open, setOpen ] = useState( null );

	const summary = useResource( buildPath( '/inventory/summary' ) );
	const list = useResource(
		viewPrefs ? inventoryPath( resource, state, viewPrefs ) : null
	);
	const view = useMemo(
		() => toInventoryView( state, viewPrefs, defaultFields ),
		[ state, viewPrefs, defaultFields ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'sites',
				label: __( 'Show the sites', 'multisite-radar' ),
				icon: listView,
				isPrimary: true,
				callback: ( [ item ] ) => setOpen( item ),
			},
		],
		[]
	);
	const pending = summary.data?.pending_sites || 0;

	const onChangeView = ( next ) => {
		setState( ( current ) => fromInventoryView( next, current, statuses ) );
		const nextPrefs = inventoryPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, viewPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className={ `msradar-inventory msradar-${ resource }` }>
			{ pending > 0 && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="msradar-inventory__pending"
				>
					{ sprintf(
						/* translators: %d: number of sites not analysed yet. */
						_n(
							'%d site has not been analysed yet: what it uses is not counted below.',
							'%d sites have not been analysed yet: what they use is not counted below.',
							pending,
							'multisite-radar'
						),
						pending
					) }
				</Notice>
			) }
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ false }
				getItemId={ ( item ) => item.id }
				isItemClickable={ () => true }
				onClickItem={ setOpen }
				searchLabel={ labels.search }
				header={
					<ExportMenu
						href={ ( format ) =>
							exportLink(
								resource,
								format,
								inventoryExportArgs(
									state,
									columnsFor(
										exportColumns.fixed,
										exportColumns.byField,
										view.fields
									)
								)
							)
						}
					/>
				}
				empty={
					list.isLoading ? (
						<Skeleton lines={ 5 } label={ labels.loading } />
					) : (
						<p className="msradar-empty">{ labels.empty }</p>
					)
				}
			/>
			{ open && (
				<ExtensionSitesPanel
					key={ open.id }
					title={ open.name }
					path={ ( args ) =>
						extensionSitesPath( resource, open.id, args )
					}
					note={ panelNote( open ) }
					describe={ ( site ) => describeSite( open, site ) }
					onClose={ () => setOpen( null ) }
				/>
			) }
		</div>
	);
}
```

- [ ] **Step 6: Create the Plugins view**

`src/views/plugins/fields.jsx` :

```jsx
import { __, _x } from '@wordpress/i18n';
import {
	ExtensionTitle,
	sitesCountField,
	statusField,
	updateField,
	versionField,
} from '../inventory/fields';

export const DEFAULT_PLUGIN_FIELDS = [
	'version',
	'status',
	'sites_count',
	'update_version',
];

/**
 * Colonnes de l'export (clés de Export\PluginsExport) : le nom et le fichier, puis celles des champs visibles.
 */
export const PLUGIN_EXPORT_COLUMNS = {
	fixed: [ 'name', 'file' ],
	byField: {
		version: [ 'version' ],
		status: [ 'status', 'network_active' ],
		sites_count: [ 'sites_count' ],
		update_version: [ 'update_version' ],
	},
};

export function pluginStatusLabels() {
	return {
		network: __( 'Network activated', 'multisite-radar' ),
		local: __( 'Active on some sites', 'multisite-radar' ),
		// Contexte : l'accord diffère selon la langue (« inutilisée » pour une extension, « inutilisé » pour un thème).
		unused: _x( 'Unused', 'plugin status', 'multisite-radar' ),
		missing: __( 'Not installed', 'multisite-radar' ),
	};
}

export function getPluginsFields() {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Plugin', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => (
				<ExtensionTitle name={ item.name } detail={ item.file } />
			),
		},
		versionField(),
		statusField( pluginStatusLabels(), {
			unused: 'info',
			missing: 'error',
		} ),
		sitesCountField(),
		updateField(),
	];
}
```

`src/views/plugins/index.jsx` :

```jsx
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import InventoryView from '../inventory';
import {
	DEFAULT_PLUGIN_FIELDS,
	getPluginsFields,
	PLUGIN_EXPORT_COLUMNS,
} from './fields';

function panelNote( plugin ) {
	return plugin.network_active
		? __(
				'Network activated: every site of the network loads this plugin.',
				'multisite-radar'
		  )
		: null;
}

export default function PluginsView() {
	const fields = useMemo( () => getPluginsFields(), [] );
	return (
		<InventoryView
			resource="plugins"
			fields={ fields }
			defaultFields={ DEFAULT_PLUGIN_FIELDS }
			exportColumns={ PLUGIN_EXPORT_COLUMNS }
			labels={ {
				search: __( 'Search plugins', 'multisite-radar' ),
				empty: __( 'No plugin matches this view.', 'multisite-radar' ),
				loading: __( 'Loading plugins…', 'multisite-radar' ),
			} }
			panelNote={ panelNote }
		/>
	);
}
```

`src/admin/plugins.js` :

```js
import { mount } from './mount';
import PluginsView from '../views/plugins';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( PluginsView );
```

Dans `webpack.config.js`, `VIEWS` devient `[ 'overview', 'sites', 'plugins', 'alerts', 'settings' ]`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npx vitest run src/views/inventory src/views/plugins`
Expected: PASS.

- [ ] **Step 8: Build and look at the page**

Run: `npm run build && ls -l build/admin/plugins.js build/admin/plugins.asset.php`
Expected : `plugins.js` fait moins de 100 Ko (DataViews est dans le chunk partagé).

Dans le WordPress local (`Réseau › Multisite Radar › Plugins`), vérifier à l'œil :
- la liste, le filtre « Status » et le filtre « Update » ;
- le clic sur un plugin, qui ouvre le panneau de ses sites ;
- l'export CSV, qui télécharge `multisite-radar-plugins-<date>.csv`.

- [ ] **Step 9: Run the checks and commit**

Run: `npm run lint:js && npm run test:unit`
Expected: PASS.

```bash
git add src/views/inventory/ src/views/plugins/ src/admin/plugins.js webpack.config.js
git commit -m "feat: Plugins page with the sites that use each plugin"
```

---

### Task 12: Vue Thèmes

**Files:**
- Create: `src/views/themes/index.jsx`, `src/views/themes/fields.jsx`, `src/views/themes/test/themes-view.test.jsx`
- Create: `src/admin/themes.js`
- Modify: `webpack.config.js` (`VIEWS`)

**Interfaces:**
- Consumes : `InventoryView` et les champs de `src/views/inventory/fields.jsx` (tâche 11) ; `GET /themes` et `GET /themes/{stylesheet}/sites` (tâche 3).
- Produces : `ThemesView`, export par défaut de `src/views/themes/index.jsx`.

- [ ] **Step 1: Write the failing test**

Créer `src/views/themes/test/themes-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import ThemesView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

const THEME = {
	id: 'parent-theme',
	stylesheet: 'parent-theme',
	name: 'Parent Theme',
	version: '1.0',
	installed: true,
	parent: null,
	allowed_on_network: true,
	active_count: 2,
	parent_count: 1,
	sites_count: 3,
	status: 'used',
	update_version: null,
};

function renderView() {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/inventory/summary': {
			body: {
				pending_sites: 0,
				plugins: {
					installed: 0,
					network: 0,
					unused: 0,
					missing: 0,
					updates: 0,
				},
				themes: { installed: 2, unused: 1, missing: 0, updates: 0 },
			},
			headers: {},
		},
		'/multisite-radar/v1/themes?order=asc&orderby=name&page=1&per_page=20':
			{
				body: [
					THEME,
					{
						...THEME,
						id: 'spare',
						stylesheet: 'spare',
						name: 'Spare',
						active_count: 0,
						parent_count: 0,
						sites_count: 0,
						status: 'unused',
					},
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
		'/multisite-radar/v1/themes/parent-theme/sites?page=1&per_page=20': {
			body: [
				{
					id: 2,
					name: 'Blog RH',
					url: 'https://example.test/rh/',
					theme: {
						stylesheet: 'child-theme',
						template: 'parent-theme',
					},
				},
				{
					id: 3,
					name: 'Atelier',
					url: 'https://example.test/atelier/',
					theme: {
						stylesheet: 'parent-theme',
						template: 'parent-theme',
					},
				},
			],
			headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
		},
	};
	window.msradarAdmin = {
		view: 'themes',
		canManage: true,
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	return render(
		<RegistryProvider value={ registry }>
			<ThemesView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-themes'
	);
} );

test( 'renders the preloaded themes with their use as a parent', async () => {
	renderView();

	expect( screen.getByText( 'Parent Theme' ) ).toBeInTheDocument();
	expect( screen.getByText( '3 (1 as parent)' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Unused' ) ).toBeInTheDocument();
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'the panel tells which sites use the theme as a parent', () => {
	renderView();

	fireEvent.click( screen.getByText( 'Parent Theme' ) );

	const dialog = screen.getByRole( 'dialog', { name: 'Parent Theme' } );
	expect(
		within( dialog ).getByText( 'Parent of the active theme child-theme' )
	).toBeInTheDocument();
	expect(
		within( dialog ).getAllByText( /Parent of the active theme/ )
	).toHaveLength( 1 );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/views/themes`
Expected: FAIL. Le module `..` est introuvable.

- [ ] **Step 3: Create the Themes view**

`src/views/themes/fields.jsx` :

```jsx
import { __, _x, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import {
	ExtensionTitle,
	sitesCountField,
	statusField,
	updateField,
	versionField,
} from '../inventory/fields';

export const DEFAULT_THEME_FIELDS = [
	'version',
	'parent',
	'status',
	'sites_count',
	'update_version',
];

/**
 * Colonnes de l'export (clés de Export\ThemesExport) : le nom et le dossier, puis celles des champs visibles.
 */
export const THEME_EXPORT_COLUMNS = {
	fixed: [ 'name', 'stylesheet' ],
	byField: {
		version: [ 'version' ],
		parent: [ 'parent' ],
		allowed_on_network: [ 'allowed_on_network' ],
		status: [ 'status' ],
		sites_count: [ 'sites_count', 'active_count', 'parent_count' ],
		update_version: [ 'update_version' ],
	},
};

export function themeStatusLabels() {
	return {
		used: _x( 'Used', 'theme status', 'multisite-radar' ),
		unused: _x( 'Unused', 'theme status', 'multisite-radar' ),
		missing: __( 'Not installed', 'multisite-radar' ),
	};
}

export function getThemesFields() {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Theme', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => (
				<ExtensionTitle name={ item.name } detail={ item.stylesheet } />
			),
		},
		versionField(),
		{
			id: 'parent',
			type: 'text',
			label: __( 'Parent theme', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) => item.parent || '—',
		},
		{
			id: 'allowed_on_network',
			type: 'text',
			label: __( 'Network enabled', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				item.allowed_on_network
					? __( 'Yes', 'multisite-radar' )
					: __( 'No', 'multisite-radar' ),
		},
		statusField( themeStatusLabels(), {
			unused: 'info',
			missing: 'error',
		} ),
		sitesCountField( ( { item } ) =>
			item.parent_count > 0
				? sprintf(
						/* translators: 1: number of sites, 2: number of those sites where the theme is the parent of the active theme. */
						__( '%1$s (%2$s as parent)', 'multisite-radar' ),
						formatNumber( item.sites_count ),
						formatNumber( item.parent_count )
				  )
				: formatNumber( item.sites_count )
		),
		updateField(),
	];
}
```

`src/views/themes/index.jsx` :

```jsx
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import InventoryView from '../inventory';
import {
	DEFAULT_THEME_FIELDS,
	getThemesFields,
	THEME_EXPORT_COLUMNS,
} from './fields';

/**
 * Sur un site dont le thème actif est un enfant de ce thème, le dit.
 *
 * @param {Object} theme Thème du panneau.
 * @param {Object} site  Site de la liste.
 */
function describeSite( theme, site ) {
	const active = site.theme?.stylesheet;
	return active && active !== theme.stylesheet
		? sprintf(
				/* translators: %s: folder of the active child theme. */
				__( 'Parent of the active theme %s', 'multisite-radar' ),
				active
		  )
		: null;
}

export default function ThemesView() {
	const fields = useMemo( () => getThemesFields(), [] );
	return (
		<InventoryView
			resource="themes"
			fields={ fields }
			defaultFields={ DEFAULT_THEME_FIELDS }
			exportColumns={ THEME_EXPORT_COLUMNS }
			labels={ {
				search: __( 'Search themes', 'multisite-radar' ),
				empty: __( 'No theme matches this view.', 'multisite-radar' ),
				loading: __( 'Loading themes…', 'multisite-radar' ),
			} }
			describeSite={ describeSite }
		/>
	);
}
```

`src/admin/themes.js` :

```js
import { mount } from './mount';
import ThemesView from '../views/themes';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( ThemesView );
```

Dans `webpack.config.js`, `VIEWS` devient `[ 'overview', 'sites', 'plugins', 'themes', 'alerts', 'settings' ]`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run src/views/themes`
Expected: PASS.

- [ ] **Step 5: Build, check and commit**

Run: `npm run build && npm run lint:js && npm run test:unit`
Expected: PASS ; `build/admin/themes.js` fait moins de 100 Ko.

```bash
git add src/views/themes/ src/admin/themes.js webpack.config.js
git commit -m "feat: Themes page with parent themes and the sites that use them"
```

---

### Task 13: Vue Utilisateurs

**Files:**
- Create: `src/views/users/query.js`, `src/views/users/fields.jsx`, `src/views/users/index.jsx`
- Create: `src/views/users/test/query.test.js`, `src/views/users/test/users-view.test.jsx`
- Create: `src/admin/users.js`
- Modify: `webpack.config.js` (`VIEWS`)

**Interfaces:**
- Consumes :
  - `GET /users` (tâche 4) ;
  - les cas `users` de `tests/fixtures/view-queries.json` (tâche 8) ;
  - `DateCell` de `src/views/sites/fields.jsx` ;
  - `Skeleton` (tâche 9).
- Produces :
  - `parseUsersQuery( query )` → `{ search, page, orderby, order, membership: '' | 'none' | 'several', super_admin: boolean }` ;
  - `usersRestArgs( state, prefs )`, `usersPath( state, prefs )`, `serializeUsersState( state )`, `toUsersView( state, usersPrefs )`, `fromUsersView( view, current )`, `usersPrefsFromView( view )` ;
  - `UsersView`, export par défaut de `src/views/users/index.jsx`.

- [ ] **Step 1: Write the failing tests**

Créer `src/views/users/test/query.test.js` :

```js
import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	fromUsersView,
	parseUsersQuery,
	serializeUsersState,
	toUsersView,
	usersPath,
	usersPrefsFromView,
	usersRestArgs,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'users' ) )(
	'$name',
	( { query, prefs, args } ) => {
		test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
			expect( usersRestArgs( parseUsersQuery( query ), prefs ) ).toEqual(
				args
			);
		} );
	}
);

describe( 'users view state', () => {
	const state = parseUsersQuery( {
		s: 'jo',
		membership: 'none',
		super_admin: '1',
		orderby: 'registered',
		order: 'desc',
	} );

	test( 'round-trips through the address and the DataViews view', () => {
		expect( parseUsersQuery( serializeUsersState( state ) ) ).toEqual(
			state
		);
		const view = toUsersView( state, { fields: [], per_page: 50 } );
		expect( view.sort ).toEqual( {
			field: 'registered_gmt',
			direction: 'desc',
		} );
		expect( view.filters ).toEqual( [
			{ field: 'sites_count', operator: 'is', value: 'none' },
			{ field: 'super_admin', operator: 'is', value: 'yes' },
		] );
		expect( fromUsersView( view, state ) ).toEqual( state );
		expect( usersPrefsFromView( view ) ).toEqual( {
			fields: [ 'display_name', 'super_admin', 'sites_count', 'registered_gmt' ],
			per_page: 50,
		} );
	} );

	test( 'the REST path carries the filters', () => {
		expect( usersPath( state, { users: { per_page: 20 } } ) ).toBe(
			'/multisite-radar/v1/users?membership=none&order=desc&orderby=registered&page=1&per_page=20&search=jo&super_admin=1'
		);
	} );
} );
```

Créer `src/views/users/test/users-view.test.jsx` :

```jsx
import { beforeEach, expect, test, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import UsersView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-users'
	);
} );

test( 'renders the preloaded accounts without any request', async () => {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/users?order=asc&orderby=login&page=1&per_page=20':
			{
				body: [
					{
						id: 1,
						login: 'admin',
						display_name: 'Admin',
						super_admin: true,
						sites_count: 4,
						registered_gmt: '2026-01-01T10:00:00',
						edit_url: 'https://example.test/wp-admin/network/user-edit.php?user_id=1',
					},
					{
						id: 7,
						login: 'orphan',
						display_name: 'Orphan',
						super_admin: false,
						sites_count: 0,
						registered_gmt: null,
						edit_url: 'https://example.test/wp-admin/network/user-edit.php?user_id=7',
					},
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
	};
	window.msradarAdmin = {
		view: 'users',
		canManage: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );

	render(
		<RegistryProvider value={ registry }>
			<UsersView />
		</RegistryProvider>
	);

	expect( screen.getByText( 'admin' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Super admin' ) ).toBeInTheDocument();
	expect( screen.getByText( 'No site' ) ).toHaveClass(
		'msradar-badge--warning'
	);
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	expect( apiFetch ).not.toHaveBeenCalled();
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/views/users`
Expected: FAIL. Les modules `../query` et `..` sont introuvables.

- [ ] **Step 3: Create `src/views/users/query.js`**

```js
import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath } from '../../store/paths';
import {
	MAX_PAGE,
	page,
	perPage,
	text,
	trimAscii,
} from '../../utils/view-query';

/**
 * État de la page Utilisateurs (adresse de la page) et arguments REST, avec les règles de Admin\ViewQuery::users() :
 * tests/fixtures/view-queries.json vérifie la parité.
 */
export const USER_ORDERBY = [
	'login',
	'display_name',
	'sites_count',
	'registered',
];
export const MEMBERSHIPS = [ 'none', 'several' ];
export const SUPER_ADMINS = 'yes';
export const DEFAULT_USER_FIELDS = [
	'display_name',
	'super_admin',
	'sites_count',
	'registered_gmt',
];
export const FIELD_TO_ORDERBY = {
	login: 'login',
	display_name: 'display_name',
	sites_count: 'sites_count',
	registered_gmt: 'registered',
};
export const ORDERBY_TO_FIELD = Object.fromEntries(
	Object.entries( FIELD_TO_ORDERBY ).map( ( [ field, key ] ) => [
		key,
		field,
	] )
);

export function parseUsersQuery( query ) {
	const orderby = text( query, 'orderby' );
	const membership = text( query, 'membership' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: USER_ORDERBY.includes( orderby ) ? orderby : 'login',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		membership: MEMBERSHIPS.includes( membership ) ? membership : '',
		super_admin: text( query, 'super_admin' ) === '1',
	};
}

export function usersRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.users?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.membership ) {
		args.membership = state.membership;
	}
	if ( state.super_admin ) {
		args.super_admin = 1;
	}
	return args;
}

export function usersPath( state, prefs ) {
	return buildPath( '/users', usersRestArgs( state, prefs ) );
}

export function serializeUsersState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'login' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	if ( state.membership ) {
		out.membership = state.membership;
	}
	if ( state.super_admin ) {
		out.super_admin = '1';
	}
	return out;
}

export function toUsersView( state, usersPrefs ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( usersPrefs?.per_page ),
		sort: {
			field: ORDERBY_TO_FIELD[ state.orderby ] || 'login',
			direction: state.order,
		},
		filters: toFilters( [
			{ field: 'sites_count', operator: 'is', value: state.membership },
			{
				field: 'super_admin',
				operator: 'is',
				value: state.super_admin ? SUPER_ADMINS : '',
			},
		] ),
		titleField: 'login',
		fields: usersPrefs?.fields?.length
			? usersPrefs.fields
			: DEFAULT_USER_FIELDS,
		layout: {},
	};
}

export function fromUsersView( view, current ) {
	const membership = String( filterValue( view.filters, 'sites_count', '' ) );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'login',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		membership: MEMBERSHIPS.includes( membership ) ? membership : '',
		super_admin:
			filterValue( view.filters, 'super_admin', '' ) === SUPER_ADMINS,
	};
}

export function usersPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
```

- [ ] **Step 4: Create `src/views/users/fields.jsx`**

```jsx
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import { DateCell } from '../sites/fields';
import { SUPER_ADMINS } from './query';

export function membershipLabels() {
	return {
		none: __( 'No site', 'multisite-radar' ),
		several: __( 'Several sites', 'multisite-radar' ),
	};
}

/**
 * Valeur de filtre d'un compte : aucun site, un site, plusieurs.
 *
 * @param {number} count Nombre de sites.
 */
function membership( count ) {
	if ( count === 0 ) {
		return 'none';
	}
	return count > 1 ? 'several' : 'one';
}

/**
 * Champs DataViews de la liste des comptes. Les filtres « Sites » et « Super admin » sont appliqués par la route REST.
 */
export function getUsersFields() {
	const memberships = membershipLabels();
	return [
		{
			id: 'login',
			type: 'text',
			label: __( 'Login', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'display_name',
			type: 'text',
			label: __( 'Name', 'multisite-radar' ),
			filterBy: false,
		},
		{
			id: 'super_admin',
			type: 'text',
			label: __( 'Super admin', 'multisite-radar' ),
			enableSorting: false,
			elements: [
				{
					value: SUPER_ADMINS,
					label: __( 'Super admins only', 'multisite-radar' ),
				},
			],
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => ( item.super_admin ? SUPER_ADMINS : '' ),
			render: ( { item } ) =>
				item.super_admin ? (
					<span className="msradar-badge">
						{ __( 'Super admin', 'multisite-radar' ) }
					</span>
				) : (
					'—'
				),
		},
		{
			id: 'sites_count',
			type: 'text',
			label: __( 'Sites', 'multisite-radar' ),
			elements: Object.entries( memberships ).map(
				( [ value, label ] ) => ( { value, label } )
			),
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => membership( item.sites_count ),
			render: ( { item } ) =>
				item.sites_count === 0 ? (
					<span className="msradar-badge msradar-badge--warning">
						{ memberships.none }
					</span>
				) : (
					formatNumber( item.sites_count )
				),
		},
		{
			id: 'registered_gmt',
			type: 'datetime',
			label: __( 'Registered', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => <DateCell value={ item.registered_gmt } />,
		},
	];
}
```

- [ ] **Step 5: Create `src/views/users/index.jsx` and the entry point**

```jsx
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { samePrefs } from '../../utils/view-query';
import { getUsersFields } from './fields';
import {
	fromUsersView,
	parseUsersQuery,
	serializeUsersState,
	toUsersView,
	usersPath,
	usersPrefsFromView,
} from './query';

export default function UsersView() {
	const [ state, setState ] = useUrlState(
		parseUsersQuery,
		serializeUsersState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'users' );
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const usersPrefs = localPrefs || prefs?.users || null;

	const list = useResource(
		usersPrefs ? usersPath( state, { users: usersPrefs } ) : null
	);
	const fields = useMemo( () => getUsersFields(), [] );
	const view = useMemo(
		() => toUsersView( state, usersPrefs ),
		[ state, usersPrefs ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit the account', 'multisite-radar' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ item ] ) =>
					window.location.assign( item.edit_url ),
			},
		],
		[]
	);

	const onChangeView = ( next ) => {
		setState( ( current ) => fromUsersView( next, current ) );
		const nextPrefs = usersPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, usersPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-users">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ false }
				getItemId={ ( item ) => String( item.id ) }
				searchLabel={ __( 'Search users', 'multisite-radar' ) }
				empty={
					list.isLoading ? (
						<Skeleton
							lines={ 5 }
							label={ __( 'Loading users…', 'multisite-radar' ) }
						/>
					) : (
						<p className="msradar-empty">
							{ __(
								'No user matches this view.',
								'multisite-radar'
							) }
						</p>
					)
				}
			/>
		</div>
	);
}
```

`src/admin/users.js` :

```js
import { mount } from './mount';
import UsersView from '../views/users';
import '@wordpress/dataviews/build-style/style.css';
import './style.scss';

mount( UsersView );
```

Dans `webpack.config.js`, `VIEWS` devient `[ 'overview', 'sites', 'plugins', 'themes', 'users', 'alerts', 'settings' ]`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx vitest run src/views/users`
Expected: PASS.

- [ ] **Step 7: Build, check and commit**

Run: `npm run build && npm run lint:js && npm run test:unit && ls build/admin/*.js`
Expected: PASS. `build/admin/` contient `overview.js`, `sites.js`, `plugins.js`, `themes.js`, `users.js`, `alerts.js`, `settings.js` et `dataviews.js`, et seul `dataviews.js` dépasse 100 Ko.

```bash
git add src/views/users/ src/admin/users.js webpack.config.js
git commit -m "feat: Users page with the number of sites of each account"
```

---

### Task 14: Vue d'ensemble — tuiles de l'inventaire

**Files:**
- Modify: `src/views/overview/tiles.jsx`, `src/views/overview/index.jsx`
- Test: `src/views/overview/test/overview.test.jsx`

**Interfaces:**
- Consumes : `GET /inventory/summary` (tâche 6, préchargé pour la Vue d'ensemble par la tâche 8) ; les pages `plugins` et `themes` (tâche 8).
- Produces : `Tiles( { summary, inventory = null } )`, avec trois tuiles de plus quand `inventory` est connu :
  - « Unused plugins » → `plugins?status=unused` ;
  - « Unused themes » → `themes?status=unused` ;
  - « Updates available » (plugins + thèmes) → `plugins?has_update=1`, ou `themes?has_update=1` si seuls des thèmes en ont.

- [ ] **Step 1: Write the failing test**

Dans `src/views/overview/test/overview.test.jsx` :

1. Ajouter les constantes :

```js
const PLUGINS_URL =
	'https://example.test/wp-admin/network/admin.php?page=multisite-radar-plugins';
const THEMES_URL =
	'https://example.test/wp-admin/network/admin.php?page=multisite-radar-themes';

function inventory( overrides = {} ) {
	return {
		pending_sites: 2,
		plugins: { installed: 9, network: 2, unused: 3, missing: 0, updates: 0 },
		themes: { installed: 4, unused: 2, missing: 0, updates: 1 },
		...overrides,
	};
}
```

2. Dans `renderView()`, ajouter le paramètre `stock = inventory()` à la déstructuration, l'entrée de préchargement :

```js
		'/multisite-radar/v1/inventory/summary': { body: stock, headers: {} },
```

et les pages `plugins: PLUGINS_URL, themes: THEMES_URL` dans `window.msradarAdmin.pages`.

3. Ajouter :

```jsx
test( 'inventory tiles lead to the unused plugins and themes and to the updates', async () => {
	renderView();

	expect(
		screen.getByRole( 'link', { name: /3\s*Unused plugins/ } )
	).toHaveAttribute( 'href', `${ PLUGINS_URL }&status=unused` );
	expect(
		screen.getByRole( 'link', { name: /2\s*Unused themes/ } )
	).toHaveAttribute( 'href', `${ THEMES_URL }&status=unused` );
	const updates = screen.getByRole( 'link', {
		name: /1\s*Updates available/,
	} );
	expect( updates ).toHaveAttribute( 'href', `${ THEMES_URL }&has_update=1` );
	expect(
		within( updates ).getByText( 'Plugins: 0 · Themes: 1' )
	).toBeInTheDocument();
	await act( () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ) );
	expect( apiFetch ).not.toHaveBeenCalled();
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/views/overview`
Expected: FAIL. Les tuiles de l'inventaire n'existent pas.

- [ ] **Step 3: Implement**

Dans `src/views/overview/tiles.jsx`, remplacer la signature et la constante `tiles` de `Tiles` par :

```jsx
export function Tiles( { summary, inventory = null } ) {
	const tiles = [
		/* … les quatre tuiles existantes, inchangées … */
	];
	if ( inventory ) {
		const { plugins, themes } = inventory;
		tiles.push(
			{
				key: 'unused-plugins',
				label: __( 'Unused plugins', 'multisite-radar' ),
				value: plugins.unused,
				href: pageUrl( 'plugins', { status: 'unused' } ),
			},
			{
				key: 'unused-themes',
				label: __( 'Unused themes', 'multisite-radar' ),
				value: themes.unused,
				href: pageUrl( 'themes', { status: 'unused' } ),
			},
			{
				key: 'updates',
				label: __( 'Updates available', 'multisite-radar' ),
				value: plugins.updates + themes.updates,
				detail: sprintf(
					/* translators: 1: number of plugins with an update, 2: number of themes with an update. */
					__( 'Plugins: %1$d · Themes: %2$d', 'multisite-radar' ),
					plugins.updates,
					themes.updates
				),
				href: pageUrl(
					plugins.updates === 0 && themes.updates > 0
						? 'themes'
						: 'plugins',
					{ has_update: '1' }
				),
			}
		);
	}
```

Le commentaire `/* … les quatre tuiles existantes, inchangées … */` désigne le contenu actuel du tableau (`sites`, `error`, `warning`, `info`) : le garder tel quel, `const` compris, puisque `push()` ne réaffecte pas la variable. Le reste de la fonction (le rendu de la liste) ne change pas.

Dans `src/views/overview/index.jsx` :
- ajouter `const inventory = useResource( buildPath( '/inventory/summary' ) );` après la lecture de `status` ;
- remplacer `{ data && <Tiles summary={ data } /> }` par `{ data && <Tiles summary={ data } inventory={ inventory.data } /> }`.

Une erreur de lecture de l'inventaire ne bloque pas la page : les tuiles de l'inventaire n'apparaissent simplement pas.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run src/views/overview`
Expected: PASS, y compris les tests existants (`tiles link to the sites filtered by severity`, etc.).

- [ ] **Step 5: Run the checks and commit**

Run: `npm run lint:js && npm run test:unit && npm run build`
Expected: PASS.

```bash
git add src/views/overview/
git commit -m "feat: unused plugins, unused themes and updates on the overview"
```

---

## Partie D — Recette et livraison

### Task 15: Tests de bout en bout et audit d'accessibilité des nouvelles pages

**Files:**
- Modify: `tests/e2e/setup.sh`
- Create: `tests/e2e/specs/inventory.spec.js`
- Modify: `tests/e2e/specs/a11y.spec.js`

**Interfaces:**
- Consumes : tout le jalon ; le réseau wp-env préparé par `tests/e2e/setup.sh`. Ce réseau contient :
  - le site principal ;
  - `/rh/` (« Blog RH »), seul site où `msradar-demo-cpt` (« Multisite Radar demo CPT ») est actif ;
  - `/vide/` (« Site vide ») et `/atelier/` (« L'atelier R&D ») ;
  - les plugins Akismet et Hello Dolly, livrés avec WordPress et activés nulle part ;
  - le thème par défaut de WordPress, actif sur tous les sites.
- Produces : un compte `radar-orphan` rattaché à aucun site, créé par `setup.sh`.

- [ ] **Step 1: Add an account without a site to `tests/e2e/setup.sh`**

Avant la dernière ligne (`wp multisite-radar scan --all --probe`), ajouter :

```sh
# Un compte rattaché à aucun site : il apparaît dans le filtre « No site » de la page Utilisateurs.
if ! wp user get radar-orphan >/dev/null 2>&1; then
	wp user create radar-orphan radar-orphan@example.test --role=subscriber
	wp eval 'remove_user_from_blog( get_user_by( "login", "radar-orphan" )->ID, get_main_site_id() );'
fi
```

- [ ] **Step 2: Write `tests/e2e/specs/inventory.spec.js`**

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Cross inventory (spec 7.1)', () => {
	test( 'a plugin active on one site leads to that site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-plugins'
		);
		const app = page.locator( '#msradar-app' );

		await app
			.getByText( 'Multisite Radar demo CPT', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', {
			name: 'Multisite Radar demo CPT',
		} );
		await expect( panel ).toBeVisible();
		await expect( panel.getByText( '1 site', { exact: true } ) ).toBeVisible();

		await panel.getByRole( 'link', { name: 'Blog RH' } ).click();
		await expect( page ).toHaveURL(
			/page=multisite-radar-sites.*site=\d+/
		);
		await expect(
			page.getByRole( 'dialog', { name: 'Blog RH' } )
		).toBeVisible();
	} );

	test( 'the unused plugins tile opens the filtered plugin list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/admin.php', 'page=multisite-radar' );

		await page.getByRole( 'link', { name: /Unused plugins/ } ).click();

		await expect( page ).toHaveURL(
			/page=multisite-radar-plugins.*status=unused/
		);
		const app = page.locator( '#msradar-app' );
		await expect(
			app.getByText( 'Hello Dolly', { exact: true } )
		).toBeVisible();
		await expect(
			app.getByText( 'Multisite Radar demo CPT', { exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'the theme used by the network lists its sites', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-themes&status=used'
		);
		const theme = page
			.locator( '#msradar-app .msradar-site-title__name' )
			.first();
		const name = ( await theme.textContent() ).trim();

		await theme.click();

		const panel = page.getByRole( 'dialog', { name } );
		await expect(
			panel.getByRole( 'link', { name: 'Blog RH' } )
		).toBeVisible();
	} );

	test( 'the users page flags super admins and finds accounts without a site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users'
		);
		const app = page.locator( '#msradar-app' );
		await expect(
			app.getByRole( 'row', { name: /admin.*Super admin/ } )
		).toBeVisible();

		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users&membership=none'
		);
		await expect(
			app.getByText( 'radar-orphan', { exact: true } )
		).toBeVisible();
		await expect( app.getByText( 'admin', { exact: true } ) ).toHaveCount(
			0
		);
	} );
} );
```

- [ ] **Step 3: Extend `tests/e2e/specs/a11y.spec.js`**

1. `PAGES` devient :

```js
const PAGES = [
	'page=multisite-radar',
	'page=multisite-radar-sites',
	'page=multisite-radar-plugins',
	'page=multisite-radar-themes',
	'page=multisite-radar-users',
	'page=multisite-radar-alerts',
	'page=multisite-radar-settings',
];
```

2. Remplacer le commentaire de l'exclusion par :

```js
		// Page Réglages : @wordpress/dataviews 19.1 ajoute à un FormTokenField validé un champ texte invisible
		// (opacity 0, tabindex -1) sans libellé, que la règle « label » signale. Ce nœud n'est pas dans notre code :
		// on n'exclut que lui, jamais la règle. À revoir à chaque montée de version de DataViews (spec §14).
```

3. Ajouter, dans le `describe` :

```js
	test( 'no serious or critical violation with the sites of a plugin open', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-plugins'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Multisite Radar demo CPT', { exact: true } )
			.click();
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );
```

- [ ] **Step 4: Run the end-to-end suite**

Run (environnement E2E, voir « Commandes ») : `npm run wp-env -- start && npm run e2e:setup && npm run test:e2e`
Expected: PASS. En cas d'échec, `artifacts/` contient la trace Playwright de chaque test raté.

- [ ] **Step 5: Lint and commit**

Run: `npm run lint:js`
Expected: PASS.

```bash
git add tests/e2e/
git commit -m "test: end-to-end journeys and accessibility audit of the inventory pages"
```

---

### Task 16: Version 2.0.0-beta.3, traductions, documentation et recette du jalon

**Files:**
- Modify: `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json`, `tests/phpstan-bootstrap.php` (par `make version`)
- Modify: `languages/multisite-radar.pot`, `languages/multisite-radar-fr_FR.po`
- Modify: `CHANGELOG.md`, `readme.txt`, `README.md`, `package.json` (script `plugin-zip`)
- Modify: `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md`

**Interfaces:**
- Consumes : tout le jalon.
- Produces : la version 2.0.0-beta.3, cohérente partout (`npm run version:check`), traduite en français, avec une recette verte.

- [ ] **Step 1: Set the version**

Run: `make version VERSION=2.0.0-beta.3`
Expected : en-tête, `MSRADAR_VERSION`, `readme.txt` (`Stable tag`), `package.json`, `package-lock.json` et `tests/phpstan-bootstrap.php` passent en 2.0.0-beta.3 ; `npm run version:check` le confirme.

- [ ] **Step 2: Translate the new strings**

Run: `npm run build && make i18n`
Expected : la commande s'arrête et liste les chaînes non traduites du jalon.

Les traduire dans `languages/multisite-radar-fr_FR.po`, puis relancer `make i18n` jusqu'à zéro chaîne manquante. Règles :
- typographie de WordPress en français : apostrophe typographique `’`, espace insécable (U+00A0) avant `:`, `;`, `!` et `?`, points de suspension `…` en un seul caractère ;
- garder chaque marqueur (`%s`, `%d`, `%1$s`…) : `tests/php/TranslationsTest.php` le vérifie ;
- le glossaire ci-dessous, aligné sur WordPress 7.1 en français et sur la traduction déjà livrée.

| Anglais | Français |
|---|---|
| Plugins / Plugin | Extensions / Extension |
| Themes / Theme | Thèmes / Thème |
| Users (menu, onglet) | Comptes (déjà traduit) |
| Search users / No user matches this view. / Loading users… | Rechercher des comptes / Aucun compte ne correspond à cette vue. / Chargement des comptes… (cette dernière déjà traduite) |
| Super admin / Super admins only | Super-admin (déjà traduit) / Super-admins uniquement |
| Network activated | Activée sur le réseau |
| Network activated: every site of the network loads this plugin. | Activée sur le réseau : tous les sites du réseau chargent cette extension. |
| Active on some sites | Active sur certains sites |
| Unused (contexte `plugin status`) / Unused (contexte `theme status`) / Used | Inutilisée / Inutilisé / Utilisé |
| Not installed | Introuvable (déjà traduit) |
| Update / Update available / Version %s available | Mise à jour / Mise à jour disponible / Version %s disponible |
| Updates available / Plugins: %1$d · Themes: %2$d | Mises à jour disponibles / Extensions : %1$d · Thèmes : %2$d |
| Unused plugins / Unused themes | Extensions inutilisées / Thèmes inutilisés |
| Network enabled / Yes / No | Activé sur le réseau / Oui / Non |
| Parent theme / Parent of the active theme %s / %1$s (%2$s as parent) | Thème parent / Parent du thème actif %s / %1$s (dont %2$s comme parent) |
| Show the sites / No analysed site uses it. / %s site, %s sites | Afficher les sites / Aucun site analysé ne l’utilise. / %s site, %s sites |
| %d site has not been analysed yet: what it uses is not counted below. | %d site n’a pas encore été analysé : ce qu’il utilise n’est pas compté ci-dessous. (pluriel : %d sites n’ont pas encore été analysés : ce qu’ils utilisent n’est pas compté ci-dessous.) |
| No site / Several sites / Registered / Edit the account | Aucun site / Plusieurs sites / Inscription / Modifier le compte |
| Loading… / Loading the overview… / Loading sites… / Loading alerts… / Loading plugins… / Loading themes… | Chargement… / Chargement de la vue d’ensemble… / Chargement des sites… / Chargement des alertes… / Chargement des extensions… / Chargement des thèmes… |
| Plugin file / Theme folder / Active theme of (sites) / Parent of the active theme of (sites) | Fichier de l’extension / Dossier du thème / Thème actif de (sites) / Parent du thème actif de (sites) |
| This plugin is neither installed nor active on any site. | Cette extension n’est ni installée ni active sur aucun site. |
| This theme is neither installed nor used by any site. | Ce thème n’est ni installé ni utilisé par aucun site. |
| Export incomplete: the data could not be read to the end. Run the export again. | Export incomplet : les données n’ont pas pu être lues jusqu’au bout. Relancez l’export. |

Les autres chaînes se traduisent dans le même registre. Ensuite :

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 3: Point `npm run plugin-zip` to `make dist`**

Dans `package.json`, le script devient `"plugin-zip": "make dist",`. Dans `README.md`, remplacer la phrase « Ce n'est pas le cas de `npm run plugin-zip`, qui ajoute `package.json` et `README.md`. » par « `npm run plugin-zip` appelle `make dist`. »

- [ ] **Step 4: Update the documentation**

`CHANGELOG.md`, nouvelle section en tête (après le titre) :

```markdown
## [2.0.0-beta.3] - <date du jour, AAAA-MM-JJ>

### Jalon M3 — Inventaire croisé
- Pages Plugins, Thèmes et Utilisateurs, avec leurs routes REST (`GET /plugins`, `GET /plugins/{plugin}/sites`, `GET /themes`, `GET /themes/{stylesheet}/sites`, `GET /users`, `GET /inventory/summary`).
- Plugins : statut (activé sur le réseau, actif sur certains sites, inutilisé, introuvable), nombre de sites, mise à jour disponible ; un clic ouvre la liste des sites qui l'utilisent. MU-plugins et drop-ins exclus.
- Thèmes : thème parent, autorisation sur le réseau, sites où il est actif ou parent du thème actif ; un thème parent d'un thème actif n'est pas « inutilisé ».
- Utilisateurs : nombre de sites de chaque compte (tous réseaux), super-admins, filtres « aucun site » et « plusieurs sites » ; aucune adresse e-mail lue ni affichée. Résultats en cache objet 10 minutes.
- Vue d'ensemble : tuiles « Extensions inutilisées », « Thèmes inutilisés » et « Mises à jour disponibles ». Tant que des sites restent à analyser, les pages d'inventaire le signalent.
- Exports CSV et JSON des plugins et des thèmes. Un export interrompu en cours de flux se termine par un marqueur visible (dernière ligne CSV, clés `incomplete` et `error` en JSON).
- Fiche site : noms de rôles traduits dans l'onglet Utilisateurs, ordre stable d'une page à l'autre.
- Squelettes de chargement à la place des spinners.
- DataViews n'est plus embarqué dans chaque vue : un fichier partagé (`build/admin/dataviews.js`), gardé en cache par le navigateur d'une page à l'autre.
- `npm run plugin-zip` appelle `make dist`.
```

`readme.txt`, dans `== Changelog ==`, avant `= 2.0.0-beta.2 =` :

```
= 2.0.0-beta.3 =
* New Plugins, Themes and Users pages: what each site uses, unused plugins and themes, available updates, accounts attached to no site.
* The overview shows unused plugins and themes and available updates.
* Plugins and themes can be exported as CSV or JSON.
* Faster pages: the table library is downloaded once for all pages.
```

`README.md` : en tête, après la ligne « Audit réseau pour WordPress Multisite… », ajouter :

```markdown
Pages du menu réseau « Multisite Radar » : Vue d'ensemble, Sites, Plugins, Thèmes, Utilisateurs, Alertes, Réglages.
```

- [ ] **Step 5: Update the follow-up document of M2**

Dans `docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md` :
- dans « Déjà traité depuis M2 », ajouter les points traités par ce plan (voir le tableau « Points reportés de M2 traités dans ce plan »), avec la mention « (2.0.0-beta.3, plan M3) » ;
- retirer ces points des sections 1 et 2 ;
- dans la section « Accessibilité », ne garder que le lien vers le ticket amont de DataViews et la couverture de la fiche limitée à l'onglet par défaut ;
- ajouter en fin de document une section `## 3. Reportés par le plan M3`, avec les points relevés pendant l'exécution de ce plan et laissés pour plus tard (registre de l'exécution, revue finale).

- [ ] **Step 6: Run the full check**

Run: `make check && npm run version:check && make dist && unzip -l dist/multisite-radar-2.0.0-beta.3.zip | grep -E 'build/admin/(dataviews|plugins|themes|users)\.js|languages/.*\.json' `
Expected :
- lint et tests verts ;
- versions cohérentes ;
- le zip contient `build/admin/dataviews.js`, `plugins.js`, `themes.js`, `users.js` et les fichiers JSON de traduction.

Run (environnement E2E) : `npm run test:e2e`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add multisite-radar.php readme.txt package.json package-lock.json tests/phpstan-bootstrap.php languages/ CHANGELOG.md README.md docs/superpowers/plans/2026-10-02-multisite-radar-m2-followups.md
git commit -m "chore: release 2.0.0-beta.3"
```
