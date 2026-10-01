# Multisite Radar v2 — Spécification de conception

- **Date** : 2026-10-01
- **Statut** : validée en conversation, en attente de relecture écrite
- **Remplace** : Network Plugin Utilities (MU) 1.7.0
- **Branche** : `v2`

---

## 1. Contexte et objectifs

### 1.1 Point de départ

Network Plugin Utilities 1.7.0 est un MU-plugin qui affiche aux super-admins un tableau (`WP_List_Table`) de tous les sites d'un réseau : utilisateurs, CPT/taxonomies et leur origine, plugins locaux, thème, médias, dernière activité, alertes, exports CSV/JSON, et un menu front « Sites du réseau ».

L'audit du code (1.7.0) a relevé :

| Gravité | Constat |
|---|---|
| Bloquant | Les CPT/taxonomies affichés sont faux pour tous les sites sauf le principal : `switch_to_blog()` ne charge ni les plugins ni le thème du site cible, donc `get_post_types()` / `get_taxonomies()` renvoient les enregistrements de la requête courante. Le traçage d'origine par backtrace a la même limite. |
| Bloquant | La pagination est neutralisée : le panneau d'alertes et les filtres chargent les données de tous les sites à chaque affichage ; un cache froid provoque un timeout sur un grand réseau. |
| Bloquant | Deux scripts inexistants sont chargés (`npu-popovers.js`, `npu-theme-switcher.js`). |
| Majeur | Requêtes coûteuses (`get_terms(hide_empty=false)` pour compter, `get_users()` complet par site, `get_blog_option` par site pour la recherche). |
| Majeur | Dates relatives décalées du fuseau horaire (`current_time('timestamp')` comparé à des timestamps UTC). |
| Majeur | Hooks dépréciés (`wpmu_new_blog`, `delete_blog`) ; cache jamais invalidé lors d'une activation de plugin, d'un changement de thème ou d'une publication. |
| Majeur | Détection d'origine liée au chemin codé en dur `wp-content/mu-plugins/network-plugin-utilities`. |
| Majeur | Menu front : `get_sites()` limité à 100 sites, `get_blog_option` par site à chaque rendu, pas de cache. |
| Dette | Chaînes source en français, text domain `npu-core`, en-tête incomplet, versions codées en dur et incohérentes, `_e()` non échappés, préfixe de 3 caractères, fonction globale `rdc_…`, pas de namespace, pas de tests ni de normes, configuration éclatée entre `config.php` et des options réseau. |

### 1.2 Intention

Faire du plugin un **outil d'audit de réseau multisite publiable sur WordPress.org**, fiable sur des réseaux de toute taille, avec une interface moderne cohérente avec l'admin WordPress, et des fonctionnalités qui le différencient d'un marché sans leader (aucun concurrent au-delà de 100 installations ; la plupart abandonnés ou limités aux plugins).

### 1.3 Décisions prises

| Sujet | Décision |
|---|---|
| Contexte | Distribution publique sur WordPress.org, réseaux de toute taille |
| Positionnement | Audit en lecture seule en v2 ; architecture prête pour des actions groupées dans un lot ultérieur |
| Nom | **Multisite Radar** — slug et text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_`, REST `multisite-radar/v1` |
| Menu front | Conservé comme module optionnel modernisé (bloc + shortcode + metabox menus) |
| Périmètre | Socle (refonte de l'existant) + lots 1 (inventaire croisé), 2 (santé avancée), 3 (historique & rapports), 4 (intégrations) |
| Collecte | Hybride : SQL agrégé centralisé + relevé du registre dans le contexte de chaque site |
| Interface | React via `@wordpress/scripts`, `@wordpress/components`, DataViews/DataForm bundlés (version épinglée) |
| Fiche site | Panneau latéral au-dessus de la liste, lien profond |
| Versions minimales | WordPress 6.9, PHP 7.4 |

### 1.4 Critères de succès

1. **Exactitude** : un CPT enregistré par un plugin actif uniquement sur le site B apparaît sur B avec son libellé et son origine, et n'apparaît pas sur A (test automatisé).
2. **Performance** : `GET /sites` répond en moins de 300 ms sur un réseau de 5 000 sites (banc) ; aucune requête HTTP ne dépasse 20 s d'analyse ; la première vue s'affiche sans indicateur de chargement (données préchargées).
3. **Conformité** : Plugin Check sans erreur, WPCS sans erreur, PHPStan niveau 6 sans erreur.
4. **Migration** : options 1.x et éléments de menu `network_site` conservés après passage en v2 (tests).
5. **Accessibilité** : WCAG 2.2 AA sur les vues principales (axe : zéro violation « serious » ou « critical »).

---

## 2. Architecture

### 2.1 Structure du dépôt

La racine du dépôt devient la racine du plugin.

```
multisite-radar/
├── multisite-radar.php        # en-tête, constantes, autoload, démarrage
├── readme.txt
├── uninstall.php
├── composer.json              # outils de dev + autoloader PSR-4 (aucune dépendance runtime)
├── package.json               # @wordpress/scripts
├── phpcs.xml.dist · phpstan.neon.dist · phpunit.xml.dist · .wp-env.json · .distignore
├── includes/                  # PSR-4 MultisiteRadar\
│   ├── Plugin.php             # assemblage des modules
│   ├── Capabilities.php       # map_meta_cap
│   ├── Install/               # Schema (dbDelta), Upgrader (versions), LegacyMigration (1.x)
│   ├── Collector/             # SiteCollector, RegistryProbe, Fingerprint, OriginResolver
│   ├── Scan/                  # Queue, BatchRunner, Lock, Invalidation
│   ├── Storage/               # SitesRepository, ExtensionsRepository, EventsRepository, SnapshotsRepository
│   ├── Query/                 # SitesQuery, InventoryQuery, UsersQuery, AlertsQuery, ReportsQuery
│   ├── Alerts/                # RuleInterface, RuleRegistry, AlertEvaluator, Rules/*
│   ├── Settings/              # Settings (lecture/écriture/validation), Defaults
│   ├── Rest/                  # un contrôleur par ressource
│   ├── Export/                # ExportHandler (admin-post), CsvWriter, JsonWriter
│   ├── Cli/                   # commandes WP-CLI
│   ├── Abilities/             # enregistrement Abilities API
│   ├── Admin/                 # Menu, Assets, Preload
│   ├── Reports/               # Digest (e-mail), DashboardWidget
│   └── SitesMenu/             # module optionnel : Block, Shortcode, NavMenu, SitesListCache
├── src/                       # sources React
├── build/                     # compilé (livré, ignoré par git)
├── blocks/sites-list/         # block.json + rendu PHP
├── languages/
└── tests/
    ├── php/                   # PHPUnit (suite WP, multisite)
    ├── js/                    # Jest
    └── e2e/                   # Playwright
```

### 2.2 Principes

- **Une seule source de vérité** : les services `Query/` sont utilisés à l'identique par REST, WP-CLI, Abilities et exports. Aucune logique métier dans les contrôleurs ou commandes.
- **Capacités dédiées**, résolues via `map_meta_cap` et filtrables (`msradar_capability_map`) :
  - `msradar_view` → `manage_network` par défaut ;
  - `msradar_manage` → `manage_network_options` par défaut.
- **Garde multisite** : hors multisite, une notice d'admin et aucun autre chargement. En-tête `Network: true`.
- **Points d'extension** : filtres `msradar_alert_rules`, `msradar_default_settings`, `msradar_capability_map` ; action `msradar_site_scanned( $site_id, $record )`.
- **Constantes** : `MSRADAR_VERSION`, `MSRADAR_FILE`, `MSRADAR_DIR`, `MSRADAR_URL`, définies dans `multisite-radar.php`.
- **Version** : `MSRADAR_VERSION` est la seule source de vérité, synchronisée par script avec l'en-tête, `readme.txt` et `package.json`.
- `config.php` est supprimé ; ses valeurs deviennent des réglages.

---

## 3. Données

### 3.1 Tables réseau

Préfixe `{$wpdb->base_prefix}msradar_`. Créées et mises à jour par `dbDelta` (version de schéma stockée dans l'option réseau `msradar_db_version`).

**`msradar_sites`** — une ligne par site

| Colonne | Type | Notes |
|---|---|---|
| `site_id` | BIGINT UNSIGNED PK | |
| `network_id` | BIGINT UNSIGNED | indexé |
| `name`, `url` | VARCHAR(255) | recherche `LIKE` |
| `is_public`, `is_archived`, `is_spam`, `is_deleted` | TINYINT(1) | copie de `wp_blogs` |
| `theme_stylesheet`, `theme_template` | VARCHAR(191) | `theme_stylesheet` indexé |
| `users_count`, `admins_count` | INT UNSIGNED | `admins_count` = comptes ayant le rôle `administrator` sur le site |
| `content_count` | INT UNSIGNED | somme des contenus `publish` des types non exclus, hors `attachment` |
| `media_count` | INT UNSIGNED | pièces jointes en statut `inherit` ou `publish` (« valides » en 1.x) ; le détail par statut reste dans `data.post_types` |
| `disk_bytes` | BIGINT UNSIGNED NULL | + `disk_is_estimate` TINYINT(1) |
| `db_bytes`, `autoload_bytes` | BIGINT UNSIGNED NULL | |
| `last_activity_gmt` | DATETIME NULL | indexé |
| `alert_level` | TINYINT UNSIGNED | 0 aucune, 1 info, 2 avertissement, 3 erreur ; indexé |
| `alerts_count` | SMALLINT UNSIGNED | |
| `alert_rules` | VARCHAR(255) | identifiants des règles déclenchées, encadrés de virgules (`,no_users,inactive,`) : comptage et filtrage par règle en SQL portable (MySQL 5.5+), sans décoder le JSON |
| `registry_status` | VARCHAR(20) | `fresh` / `stale` / `missing` |
| `data` | LONGTEXT | JSON détaillé (§3.2) |
| `dirty` | TINYINT(1) | indexé ; + `dirty_since` DATETIME NULL |
| `scanned_at` | DATETIME NULL | indexé |

**`msradar_site_extensions`** — inventaire croisé

| Colonne | Type | Notes |
|---|---|---|
| `site_id` | BIGINT UNSIGNED | |
| `type` | VARCHAR(10) | `plugin` / `theme` |
| `slug` | VARCHAR(191) | fichier du plugin ou stylesheet |
| `role` | VARCHAR(10) | `local` (plugin), `active` / `parent` (thème) |

Clé primaire `(site_id, type, slug)`, index `(type, slug)`. Les plugins activés sur le réseau ne sont pas répétés par site : la couche Query les connaît via `active_sitewide_plugins`.

Les tables `msradar_events` et `msradar_snapshots` ne sont créées qu'au jalon M6 (version de schéma 2), pour ne pas livrer de tables vides.

**`msradar_events`** — lot 3

`id` (PK auto), `site_id`, `type` VARCHAR(40), `subject` VARCHAR(191), `meta` LONGTEXT NULL (JSON), `created_at` DATETIME. Index `(site_id, created_at)` et `(type, created_at)`.

Types : `site_created`, `site_deleted`, `plugin_activated`, `plugin_deactivated`, `theme_switched`, `alert_raised`, `alert_resolved`.

**`msradar_snapshots`** — lot 3

`site_id`, `day` DATE, `users_count`, `content_count`, `media_count`, `disk_bytes`, `db_bytes`, `alert_level`, `alerts_count`. Clé primaire `(site_id, day)`, index `day`.

### 3.2 Contenu de `data` (JSON)

```json
{
  "post_types": [
    { "name": "event", "label": "Events", "publish": 42, "total": 57,
      "origin": { "kind": "plugin", "slug": "the-events-calendar" },
      "builtin": false, "verified": true }
  ],
  "taxonomies": [
    { "name": "event_cat", "label": "Event categories", "count": 8,
      "origin": { "kind": "plugin", "slug": "the-events-calendar" },
      "builtin": false, "verified": true }
  ],
  "users": {
    "by_role": { "administrator": 2, "editor": 5 },
    "privileged": [ { "id": 3, "login": "jdoe", "roles": ["administrator"] } ]
  },
  "last_content": { "id": 812, "type": "post", "title": "…", "date_gmt": "2026-09-12 08:41:00" },
  "options": { "blog_public": 1, "siteurl": "https://…", "home": "https://…", "locale": "fr_FR" },
  "cron": { "overdue_count": 0, "oldest_overdue_gmt": null },
  "alerts": [ { "rule": "inactive", "severity": "warning", "args": { "months": 8 } } ]
}
```

- `privileged` est limité aux 50 premiers comptes ayant un rôle `administrator` ou `editor` ; la liste complète passe par `GET /sites/{id}/users`.
- Les alertes sont stockées sous forme `rule` + `args` ; le message est construit et traduit à la lecture, dans la langue de l'utilisateur.
- `origin.kind` ∈ `core`, `plugin`, `mu-plugin`, `theme`, `unknown`.

### 3.3 Collecteur SQL (`SiteCollector`)

Exécuté de façon centralisée, sans `switch_to_blog()` sauf pour résoudre le dossier d'uploads du site. Pour un site donné (préfixe `$wpdb->get_blog_prefix( $id )`) :

1. Contenus : `SELECT post_type, post_status, COUNT(*) FROM {p}posts GROUP BY post_type, post_status`.
2. Dernière activité : dernier contenu `publish` parmi les types d'activité configurés, trié par `post_modified_gmt`.
3. Taxonomies : `SELECT taxonomy, COUNT(*) FROM {p}term_taxonomy GROUP BY taxonomy`.
4. Options ciblées en une requête : `blogname`, `siteurl`, `home`, `stylesheet`, `template`, `active_plugins`, `blog_public`, `WPLANG`, `cron`, `msradar_registry`.
5. Poids de l'autoload : `SUM(LENGTH(option_value))` sur les valeurs d'autoload actives (`yes`, `on`, `auto-on`, `auto`).
6. Utilisateurs : lecture de `{base}usermeta` pour la clé `{p}capabilities` ; comptage par rôle.
7. Taille des tables : `information_schema.TABLES` avec une **liste explicite** de tables. Pour le site principal, le préfixe de base est partagé avec tous les sites : on exclut les tables correspondant à `{base}\d+_`. Repli sur `SHOW TABLE STATUS` si `information_schema` est inaccessible ; `NULL` si les deux échouent.
8. Espace disque : taille du dossier d'uploads avec un budget de temps (`recurse_dirsize` avec limite) ; si le budget est dépassé, valeur partielle et `disk_is_estimate = 1`. Désactivable dans les réglages.

Fusion avec le registre : chaque type présent en base mais absent du registre reçoit le slug comme libellé et `verified = false` ; chaque type du registre sans ligne en base reçoit un compteur 0.

Les types exclus (révisions, éléments de menu, modèles de blocs, etc.) reprennent la liste actuelle de `config.php`, filtrable via `msradar_excluded_post_types` et `msradar_excluded_taxonomies`.

### 3.4 Relevé en contexte (`RegistryProbe`)

But : obtenir libellés, visibilité et origine des CPT/taxonomies tels que le site lui-même les enregistre.

- **Empreinte** : `md5` de `active_plugins` du site + clés de `active_sitewide_plugins` + `stylesheet` + `template` + version de WordPress + `MSRADAR_VERSION`. Calculée au chargement du plugin à partir d'options autoloadées (aucune requête supplémentaire).
- **Relevé dû** si l'empreinte diffère de celle stockée dans l'option du site `msradar_registry` ou si le dernier relevé date de plus de 7 jours.
- **Déclenchement** : si le relevé est dû, un événement cron unique `msradar_probe` est planifié **sur ce site** ; il s'exécute dans le contexte complet du site. Repli sur `admin_init` du site si `DISABLE_WP_CRON` est défini. **Jamais pendant le rendu d'une page publique.**
- **Traçage d'origine** : les filtres `register_post_type_args` et `register_taxonomy_args` ne sont ajoutés que lorsqu'un relevé est dû et que le contexte s'y prête. Le plugin étant activé sur le réseau, il se charge avant les plugins du site. L'`OriginResolver` compare les fichiers de la pile d'appels à `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR` et `get_theme_root()` (plus de chemin codé en dur, compatible avec un `WP_CONTENT_DIR` personnalisé).
- **Résultat** : l'option `msradar_registry` (`fingerprint`, `built_at`, `post_types[]`, `taxonomies[]`) est enregistrée et le site est marqué à rafraîchir.
- **Statut** : `registry_status` vaut `fresh` si l'empreinte correspond, `stale` si un relevé est dû, `missing` si aucun relevé n'existe. L'interface affiche un badge « non vérifié » pour `stale` et `missing`, avec l'indication de lancer `wp multisite-radar scan --probe`.

### 3.5 File d'analyse et invalidation

**Marquage « à rafraîchir »** (`dirty = 1`) par les hooks suivants :

| Événement | Effet |
|---|---|
| `activated_plugin`, `deactivated_plugin` | site courant ; tous les sites si activation réseau |
| `switch_theme` | site courant |
| `add_user_to_blog`, `remove_user_from_blog`, `set_user_role`, `wpmu_delete_user`, `deleted_user` | site(s) concerné(s) |
| `update_option_blogname`, `update_option_blog_public`, `update_option_siteurl`, `update_option_home` | site courant |
| `make_spam_blog`, `make_ham_blog`, `archive_blog`, `unarchive_blog`, `make_delete_blog`, `make_undelete_blog` | site concerné |
| `wp_initialize_site` | insertion d'une ligne `dirty` |
| `wp_delete_site` | suppression des lignes du site (sites, extensions) + événement `site_deleted` |
| `transition_post_status` vers `publish`, pour un type d'activité configuré | mise à jour directe de `last_activity_gmt`, sans analyse |

**Traitement** :

- Tâche cron récurrente `msradar_process_queue` (intervalle personnalisé de 5 minutes), planifiée sur le site principal.
- `BatchRunner` traite les sites `dirty` par ordre de `dirty_since`, avec un budget de temps de `min( 20 s, max_execution_time / 2 )` et un garde-fou mémoire (arrêt à 80 % de `memory_limit`). S'il reste des sites, il replanifie un passage immédiat.
- `Lock` : verrou par option réseau avec expiration, pour empêcher deux traitements simultanés (cron, interface, CLI).
- Analyse complète périodique : tous les sites sont marqués tous les N jours (réglage, 7 par défaut) pour rattraper les modifications faites directement en base.
- Recalcul quotidien des alertes à partir des données stockées (`msradar_daily`), car l'inactivité évolue avec le temps. Un changement de réglage des règles déclenche le même recalcul.
- Première installation : tous les sites sont insérés `dirty`. Les sites non encore analysés apparaissent avec les informations de `wp_blogs` et un état « en attente ».

### 3.6 Objectifs de performance

- `GET /sites` paginé : < 300 ms à 5 000 sites.
- Analyse : 10 à 30 ms par site hors mesure disque.
- Aucun appel HTTP externe.
- Aucun travail d'analyse pendant le rendu public d'une page.

---

## 4. Alertes

### 4.1 Moteur

```php
interface RuleInterface {
    public function id(): string;
    public function label(): string;
    public function description(): string;
    public function default_severity(): string;        // error|warning|info
    public function params_schema(): array;            // JSON Schema → DataForm
    public function default_params(): array;
    public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert;
    public function message( array $args ): string;    // message traduit, construit à la lecture
}
```

- `$now` (timestamp Unix) est injecté par l'évaluateur, ce qui rend les règles temporelles testables.
- Une règle n'exécute aucune requête : elle lit le `SiteRecord` stocké (et, pour les mises à jour, l'état des transients de mise à jour fourni par le contexte de l'évaluateur).
- `RuleRegistry` réunit les règles internes et celles ajoutées via `msradar_alert_rules`.
- `AlertEvaluator` applique les réglages (activée, gravité éventuellement surchargée, paramètres), puis calcule `alert_level`, `alerts_count` et `data.alerts`.

### 4.2 Règles livrées

| ID | Règle | Gravité | Paramètres (défaut) | Jalon |
|---|---|---|---|---|
| `no_users` | Site sans utilisateurs | erreur | — | M1 |
| `inactive` | Inactif | avertissement | `months` (6) | M1 |
| `high_media` | Beaucoup de médias (sur `media_count` ; la 1.x comptait toutes les pièces jointes, tous statuts confondus) | info | `threshold` (1000) | M1 |
| `no_admin` | Site sans administrateur | avertissement | — | M4 |
| `missing_theme` | Thème actif manquant | erreur | — | M4 |
| `updates_pending` | Mises à jour en attente (plugins locaux ou thème) | avertissement | — | M4 |
| `insecure_url` | URL en http alors que le réseau est en https | avertissement | — | M4 |
| `disk_quota` | Quota disque presque atteint | avertissement | `percent` (90) ; seulement si les quotas sont actifs | M4 |
| `heavy_autoload` | Autoload trop lourd | avertissement | `kilobytes` (800) | M4 |
| `search_hidden` | Masqué aux moteurs de recherche | info | — | M4 |
| `cron_overdue` | Tâches cron en retard | info | `hours` (24) | M4 |

Les types de contenu pris en compte pour l'activité sont un réglage global d'analyse (§8), pas un paramètre de règle.

---

## 5. Interfaces programmatiques

### 5.1 API REST `multisite-radar/v1`

Règles communes :
- `permission_callback` sur `msradar_view` (lecture) ou `msradar_manage` (réglages, analyse).
- Schéma d'arguments et d'items déclaré ; `orderby` limité à une liste blanche ; tout le SQL via `$wpdb->prepare`.
- Pagination standard (`page`, `per_page` ≤ 100, en-têtes `X-WP-Total` et `X-WP-TotalPages`), support de `_fields`.

| Méthode et route | Description | Jalon |
|---|---|---|
| `GET /sites` | Recherche (`search` sur nom et URL), tri (`name`, `last_activity`, `users_count`, `content_count`, `media_count`, `disk_bytes`, `db_bytes`, `alert_level`, `scanned_at`), filtres (`alert_level[]`, `status[]`, `theme`, `plugin`, `has_users`, `inactive_since`, `registry_status[]`) | M1 |
| `GET /sites/{id}` | Fiche complète : `data`, alertes formatées, extensions | M1 |
| `GET /sites/{id}/users` | Utilisateurs du site, paginés, chargés à la demande | M2 |
| `POST /scan` | `{ scope: "all" \| "dirty" \| "ids", ids?: [] }` → marque les sites | M1 |
| `POST /scan/batch` | Traite un lot ; renvoie `{ processed, remaining, total, done }` | M1 |
| `GET /scan/status` | État de la file, verrou, dernière analyse complète | M1 |
| `GET /settings`, `POST /settings` | Réglages validés par schéma | M1 |
| `GET /alerts/summary` | Compteurs par règle et par gravité | M1 |
| `GET /alerts` | Liste site × règle, paginée et filtrable | M2 |
| `GET /alert-rules` | Définitions des règles et schémas de paramètres | M4 |
| `GET /plugins`, `GET /plugins/{file}/sites` | Inventaire des plugins ; sites utilisant un plugin | M3 |
| `GET /themes`, `GET /themes/{stylesheet}/sites` | Inventaire des thèmes ; sites utilisant un thème | M3 |
| `GET /users` | Utilisateurs réseau : nombre de sites, super-admin, rattachés à aucun site (SQL paginé, cache objet 10 min) | M3 |
| `GET /events` | Changements récents (filtres `since`, `type`, `site`) | M6 |
| `GET /reports/trends` | Séries temporelles réseau ou site | M6 |
| `GET /preferences`, `POST /preferences` | Préférences d'affichage de l'utilisateur courant (user meta `msradar_view_prefs`) | M2 |

### 5.2 Exports

- Point d'entrée : `admin-post.php?action=msradar_export`, avec nonce et vérification de `msradar_view`.
- Paramètres : `resource` (`sites`, `plugins`, `themes`), `format` (`csv`, `json`), mêmes filtres que la route REST correspondante, `fields[]` (colonnes visibles).
- Écriture en flux par tranches de 500 lignes. CSV en UTF-8 avec BOM. JSON avec un bloc `meta` (date, URL du réseau, version, filtres appliqués).
- Nom de fichier horodaté via `gmdate()`.

### 5.3 WP-CLI

Commande racine `wp multisite-radar` :

| Sous-commande | Rôle | Jalon |
|---|---|---|
| `scan [--all\|--dirty\|--site=<id>] [--probe]` | Analyse avec barre de progression. `--probe` relance le relevé dans le contexte de chaque site via `WP_CLI::runcommand( 'multisite-radar probe', [ 'url' => … ] )` | M1 |
| `probe` | Relevé du site courant (usage interne, avec `--url`) | M1 |
| `sites list [--fields] [--format] [--alert] [--plugin] [--theme]` | Liste des sites | M1 |
| `alerts [--severity] [--rule] [--format]` | Alertes | M5 |
| `plugins list [--unused] [--format]`, `themes list [--unused] [--format]` | Inventaire | M5 |
| `export --resource --format [--output=<file>]` | Export | M5 |
| `settings get [<key>]`, `settings set <key> <value>` | Réglages | M5 |

Formats standard via `WP_CLI\Utils\format_items` (`table`, `csv`, `json`, `yaml`).

### 5.4 Abilities API (M5)

- Enregistrement sur `wp_abilities_api_init`, uniquement sur le site principal et si `wp_register_ability` existe. Catégorie `multisite-radar` sur `wp_abilities_api_categories_init`.
- Abilities, toutes avec `meta.annotations.readonly = true`, `permission_callback` sur `msradar_view`, `input_schema` et `output_schema` :
  - `multisite-radar/network-summary`
  - `multisite-radar/list-sites`
  - `multisite-radar/get-site`
  - `multisite-radar/find-extension-usage`
  - `multisite-radar/list-alerts`
  - `multisite-radar/recent-changes` (disponible avec M6)
- `meta.show_in_rest = true`. L'exposition MCP publique (`meta.mcp.public`) dépend du réglage `integrations.mcp_public`, **désactivé par défaut**.
- Aucune adresse e-mail n'est renvoyée ; uniquement des identifiants, logins et compteurs.

---

## 6. Interface d'administration

### 6.1 Navigation

- Menu réseau **Multisite Radar** (icône dashicons) avec les sous-pages : Vue d'ensemble, Sites, Plugins, Thèmes, Utilisateurs, Alertes, Rapports, Réglages. Les sous-pages apparaissent au fil des jalons.
- Chaque sous-page est une page WordPress qui charge le même bundle et monte la vue correspondante (`<div id="msradar-app" data-view="sites">`).
- L'état de la vue (recherche, filtres, tri, page, layout, site ouvert) est synchronisé avec l'URL.
- Les préférences (colonnes visibles, layout, lignes par page) sont persistées par utilisateur via `/preferences`.

### 6.2 Vues

| Vue | Contenu | Jalon |
|---|---|---|
| Vue d'ensemble | Tuiles : sites, alertes par gravité, plugins inutilisés, mises à jour en attente, dernière analyse + bouton « Analyser ». Bloc « À traiter » : alertes groupées par règle, lien vers Sites filtrés. Progression d'analyse. Avec M6 : tendances et derniers changements | M2 (M3, M6 enrichissent) |
| Sites | DataViews (tableau, grille). Colonnes : site (nom, URL, badge d'alerte), thème, utilisateurs, contenus, médias, disque, dernière activité, alertes, analysé le. Actions de ligne : détail, admin du site, voir le site, réanalyser. Actions groupées : réanalyser, exporter la sélection | M2 |
| Fiche site | Panneau latéral, lien profond `&site=<id>`, navigation ←/→. Onglets : Résumé, Contenus (CPT et taxonomies avec origine et badge « non vérifié »), Utilisateurs (paginé), Extensions, Alertes, Historique (M6) | M2 |
| Plugins | Nom, version, réseau ou local, nombre de sites (clic → panneau des sites), mise à jour disponible, inutilisé | M3 |
| Thèmes | Idem + relation parent/enfant, autorisé sur le réseau | M3 |
| Utilisateurs | Login, nom affiché, super-admin, nombre de sites ; filtres « aucun site », « plusieurs sites », « super-admins » | M3 |
| Alertes | Liste site × règle, regroupée par règle ; filtres gravité, règle | M2 |
| Rapports | Tendances, « Quoi de neuf », réglage du récapitulatif | M6 |
| Réglages | DataForm, sections du §8 ; règles d'alertes générées depuis `/alert-rules` | M2 (M4 ajoute les règles) |

### 6.3 Pile technique

- `@wordpress/scripts` (build, lint, tests), `@wordpress/element`, `@wordpress/components`, `@wordpress/icons`, `@wordpress/data`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/url`, `@wordpress/notices`.
- **DataViews / DataForm** : `@wordpress/dataviews` bundlé (non exposé par le core), importé depuis `@wordpress/dataviews/wp`, **version épinglée ≥ 18.1.0** (retrait de la dépendance aux API privées). Tout usage passe par un adaptateur interne `src/components/data-views/` pour que les montées de version ne touchent qu'un endroit.
- Store `@wordpress/data` dédié (`msradar/core`) : cache des requêtes par clé de paramètres.
- Graphiques (M6) : composants SVG internes (courbes et aires simples), sans bibliothèque externe.

### 6.4 Qualité d'expérience

- **Natif** : uniquement `@wordpress/components` et `@wordpress/icons` ; respect du jeu de couleurs de l'admin (`--wp-admin-theme-color`) ; pas d'emojis.
- **Rapide** :
  - données de la première vue préchargées dans la page (`rest_preload_api_request` + middleware de préchargement d'`apiFetch`) ;
  - découpage du code par vue (imports dynamiques) ;
  - recherche avec debounce (300 ms) ;
  - squelettes de chargement plutôt que spinners.
- **États** : premier lancement (analyse initiale avec progression), erreur REST (notice avec « Réessayer »), données anciennes ou non vérifiées (badge explicatif), confirmations (snackbars `core/notices`).
- **Accessibilité** : WCAG 2.2 AA ; gestion du focus dans le panneau ; `speak()` pour la progression ; navigation clavier complète ; styles RTL générés.
- **i18n** : `@wordpress/i18n` + `wp_set_script_translations( …, 'multisite-radar', MSRADAR_DIR . 'languages' )`.

---

## 7. Fonctionnalités par lot

### 7.1 Lot 1 — Inventaire croisé (M3)

- **Plugin inutilisé** : installé, non activé sur le réseau, actif sur aucun site. MU-plugins et drop-ins exclus.
- **Thème inutilisé** : actif sur aucun site et parent d'aucun thème actif ; indication « autorisé sur le réseau » (`allowedthemes`).
- **Mise à jour disponible** : lue dans les transients `update_plugins` et `update_themes` du réseau, sans appel externe.
- **Utilisateur rattaché à aucun site** : aucune clé `*_capabilities` dans `usermeta`.

### 7.2 Lot 2 — Santé avancée (M4)

Règles du §4.2 marquées M4, mesures `disk_bytes`, `db_bytes`, `autoload_bytes`, `cron`, et section « Règles d'alertes » dans les réglages.

### 7.3 Lot 3 — Historique et rapports (M6)

- **Instantanés** : tâche quotidienne `msradar_daily` qui écrit une ligne par site dans `msradar_snapshots` ; purge au-delà de la rétention.
- **Événements** : le collecteur compare l'enregistrement précédent et le nouveau et écrit les événements (§3.1) ; purge au-delà de la rétention.
- **Tendances** : séries réseau (sites, médias, contenus, alertes par gravité) et par site (onglet Historique).
- **Récapitulatif e-mail** (opt-in) : hebdomadaire, jour configurable ; destinataires = super-admins ou liste saisie ; contenu = alertes nouvelles et résolues, résumé des changements, lien vers la Vue d'ensemble ; `wp_mail` en HTML avec alternative texte.
- **Widget** du tableau de bord réseau (`wp_network_dashboard_setup`) rendu en PHP : chiffres clés et 5 alertes principales.

### 7.4 Module menu des sites (M2)

- Désactivé par défaut sur une installation neuve ; activé automatiquement si une configuration 1.x est migrée avec le menu actif.
- **Bloc dynamique** `multisite-radar/sites-list` (`block.json`, apiVersion 3, rendu PHP) : attributs `include[]`, `exclude[]`, `orderBy` (`name`, `id`, `registered`), `order`, `layout` (`list`, `inline`).
- **Shortcode** `[msradar_sites wrapper="ul" class="…"]`.
- **Metabox** « Sites du réseau » dans Apparence › Menus, type d'élément `msradar_site` ; URL verrouillée, titre personnalisable.
- **Source** : `SitesListCache`, transient réseau (`id`, `name`, `url`) des sites publics, non archivés, non supprimés, sans limite de nombre ; invalidé à la création/suppression d'un site et sur `update_option_blogname`.

---

## 8. Réglages

Un seul `site_option` : `msradar_settings`. Valeurs par défaut filtrables via `msradar_default_settings`. Lecture et écriture via la classe `Settings`, validation par le schéma REST.

```json
{
  "scan": {
    "activity_post_types": ["post", "page"],
    "analysis_plugins": [],
    "measure_disk": true,
    "full_rescan_days": 7
  },
  "alerts": {
    "rules": {
      "<rule_id>": { "enabled": true, "severity": null, "params": {} }
    }
  },
  "reports": {
    "digest_enabled": false,
    "digest_day": 1,
    "digest_recipients": { "mode": "super_admins", "emails": [] }
  },
  "integrations": { "mcp_public": false },
  "sites_menu": { "enabled": false },
  "retention": { "events_days": 90, "snapshots_days": 365 }
}
```

- `severity: null` signifie « gravité par défaut de la règle ».
- `analysis_plugins` reprend l'option 1.x « Limiter l'analyse aux plugins » : appliqué au moment de la lecture (Query), pas de la collecte.

---

## 9. Sécurité et confidentialité

- Capacités vérifiées sur chaque route REST, export, commande d'admin et ability.
- Cookies + nonce `wp_rest` (middleware `apiFetch`) pour l'interface ; nonce dédié pour les exports.
- `$wpdb->prepare` partout ; noms de tables dérivés de `$wpdb->base_prefix` / `get_blog_prefix()` uniquement ; `orderby` en liste blanche.
- Rendu React sans `dangerouslySetInnerHTML` ; échappement systématique en PHP (`esc_html__`, `esc_attr`, `esc_url`, `wp_kses_post` si nécessaire).
- Données personnelles stockées : identifiants et logins des comptes à privilèges (copie de données WordPress existantes). Suppression d'un utilisateur → sites concernés marqués à rafraîchir. Texte proposé pour la politique de confidentialité via `wp_add_privacy_policy_content`.
- Aucun appel externe, aucun tracking.

---

## 10. Migration depuis 1.x

**Détection.** À l'activation, `LegacyMigration` vérifie la présence d'options `npu_*`, puis lance un passage par lots (cron) sur tous les sites à la recherche d'éléments de menu `network_site` (`SELECT … FROM {p}postmeta WHERE meta_key = '_menu_item_type' AND meta_value = 'network_site'`). Une installation 1.x est reconnue si l'un des deux est trouvé. En 1.x, le menu était actif par défaut tant que l'option n'avait pas été enregistrée : en l'absence de `npu_enable_network_menu`, il est donc considéré comme actif.

**Conversion.** Exécutée une seule fois (marqueur `msradar_legacy_migrated`) si une installation 1.x est reconnue :

| 1.x | v2 |
|---|---|
| `npu_enable_network_menu` | `sites_menu.enabled` |
| `npu_activity_post_types` | `scan.activity_post_types` |
| `npu_analysis_plugins` | `scan.analysis_plugins` |
| `config.php` (valeurs modifiées) | non migré : valeurs par défaut v2 (identiques aux défauts 1.x) |
| transients `npu_site_data_*`, `npu_last_cache_refresh` | supprimés |
| éléments de menu `_menu_item_type` / `_menu_item_object` = `network_site` | réécrits en `msradar_site` sur tous les sites, par lots (cron) |
| shortcode `[network_sites_menu]`, fonction `rdc_network_sites_menu()` | alias conservés, enregistrés **uniquement** si la migration a eu lieu |

Les options `npu_*` sont supprimées après migration réussie.

**Cohabitation.** Si la classe `NPU_Core` est chargée (MU-plugin 1.x encore présent), une notice réseau demande de le retirer. Les alias ne sont enregistrés que si `shortcode_exists( 'network_sites_menu' )` et `function_exists( 'rdc_network_sites_menu' )` sont faux.

**Documentation** : retirer le MU-plugin 1.x, installer Multisite Radar et l'activer sur le réseau.

---

## 11. Qualité, outillage et livraison

### 11.1 Outillage

| Domaine | Outils |
|---|---|
| Normes PHP | WPCS 3, PHPCompatibilityWP (7.4+), préfixes `msradar` / `MultisiteRadar` / `multisite-radar` déclarés |
| Analyse statique | PHPStan niveau 6 + `szepeviktor/phpstan-wordpress` |
| Tests PHP | PHPUnit sur la suite de tests WordPress, `WP_TESTS_MULTISITE=1` |
| Tests JS | Jest + Testing Library via `@wordpress/scripts` |
| E2E | Playwright + `@wordpress/e2e-test-utils-playwright` sur `wp-env` multisite ; audit axe |
| Conformité | Plugin Check (`wp plugin check multisite-radar`) |
| i18n | `wp i18n make-pot`, `make-json` ; fr_FR livré en 2.0 |

### 11.2 Couverture de tests attendue

- Collecteur : comptages par type/statut, fusion registre ↔ base, tables du site principal, autoload.
- `RegistryProbe` et `OriginResolver` : empreinte, déclenchement, origines plugin / mu-plugin / thème / inconnue, `WP_CONTENT_DIR` personnalisé.
- Critère d'exactitude du §1.4 (CPT actif sur un seul site).
- File d'analyse : hooks de marquage, budget de temps, verrou, reprise.
- Règles : chaque règle, surcharges de gravité et paramètres.
- REST : permissions (utilisateur sans droit → 403), validation, pagination, filtres, liste blanche `orderby`.
- Migration 1.x : options, éléments de menu, alias.
- Interface : adaptateur DataViews, synchronisation URL, store ; E2E des parcours Vue d'ensemble → Sites filtrés → fiche → export.

### 11.3 Intégration continue (GitHub Actions)

1. PHPCS + PHPStan.
2. PHPUnit multisite : matrice PHP 7.4 / 8.1 / 8.4 × WordPress 6.9 / 7.1 / trunk (combinaisons exclues si la version de WordPress ne supporte pas la version de PHP).
3. Lint JS/CSS + Jest + build.
4. Plugin Check sur le zip construit (bloquant).
5. E2E sur une combinaison (PHP 8.4, WordPress 7.1).

Banc de performance manuel (`bin/bench.sh`) : création de 1 000 sites, mesure de `GET /sites` et du débit d'analyse ; exécuté avant chaque version.

### 11.4 Livraison

- `.distignore` : exclut `.well-known/`, `.vscode/`, `tests/`, `src/`, `docs/`, fichiers de configuration de dev. Le readme pointe vers le dépôt public des sources.
- `npm run plugin-zip` produit l'archive.
- `readme.txt` conforme au validateur : description courte ≤ 150 caractères, 5 tags maximum, `Stable tag` = version, `Tested up to` en version majeure.
- `uninstall.php` : suppression des tables réseau, des options réseau, de `msradar_view_prefs` et, par lots, de l'option `msradar_registry` de chaque site ; suppression des événements cron.

---

## 12. Jalons

Chaque jalon fait l'objet de son propre plan d'implémentation.

| # | Jalon | Contenu | Dépend de |
|---|---|---|---|
| M1 | Fondations | Restructuration du dépôt, bootstrap, capacités, schéma et upgrader, `SiteCollector`, `RegistryProbe`, file d'analyse et invalidation, moteur d'alertes + règles `no_users` / `inactive` / `high_media`, REST `sites` / `scan` / `settings` / `alerts/summary`, migration 1.x, WP-CLI `scan` / `probe` / `sites list`, outillage et CI | — |
| M2 | Interface | Application React, Vue d'ensemble, Sites + fiche latérale, Alertes, Réglages (DataForm), préférences, exports, module menu (bloc, shortcode, metabox). Parité 1.7 avec corrections : **première bêta** | M1 |
| M3 | Inventaire croisé | REST et vues Plugins, Thèmes, Utilisateurs | M2 |
| M4 | Santé avancée | 8 nouvelles règles, mesures disque/DB/autoload/cron, `/alert-rules`, réglages des règles | M2 |
| M5 | Intégrations | WP-CLI complet, Abilities API, réglage MCP | M1 (M3 pour `find-extension-usage`) |
| M6 | Historique et rapports | Snapshots, événements, tendances, onglet Historique, Rapports, récapitulatif e-mail, widget | M2 |
| M7 | Publication | readme.txt, bannière, icône, captures, Plugin Check sans erreur, traduction fr_FR, soumission de la 2.0.0 | M1–M6 |

---

## 13. Hors périmètre de la v2

- Actions groupées qui modifient les sites (activer/désactiver un plugin, archiver, changer de thème) : lot ultérieur ; les actions groupées DataViews sont prêtes à les accueillir.
- Interface multi-réseaux : `network_id` est stocké, mais l'interface se limite au réseau courant.
- Analyse par requêtes HTTP vers chaque site (loopback).
- Bibliothèque de graphiques, export XLSX.
- Suivi de la dernière connexion des utilisateurs.
- Notifications externes (Slack, webhooks).

---

## 14. Risques et parades

| Risque | Parade |
|---|---|
| API DataViews instable (8 versions majeures en 2026) | Version épinglée, adaptateur unique, tests E2E, procédure de montée de version documentée |
| `information_schema` lent ou interdit chez certains hébergeurs | Repli `SHOW TABLE STATUS`, puis `NULL` ; budget de temps |
| Mesure disque très lente sur de gros dossiers d'uploads | Budget de temps, valeur marquée estimée, réglage pour désactiver |
| WP-Cron désactivé ou trafic faible | Analyse pilotée par l'interface (lots), repli `admin_init` pour le relevé, WP-CLI |
| Sites jamais visités : relevé jamais exécuté | Badge « non vérifié », `wp multisite-radar scan --probe` |
| Très grands réseaux (10 000 sites et plus) | Tables indexées, agrégats SQL, lots bornés, pagination stricte |
| CPT enregistrés par un MU-plugin avant le chargement de Multisite Radar | Origine marquée `unknown` ; libellé et compteurs restent exacts |

---

## 15. Questions ouvertes

- Renommer le dépôt GitHub `adjuvans/wp-network-plugin-utilities` en `multisite-radar` (non bloquant).
