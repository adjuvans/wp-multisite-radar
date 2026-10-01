# Multisite Radar — Jalon M1 : points reportés

> **Mise à jour M2 :** les points suivants sont traités par le plan `2026-10-01-multisite-radar-m2-interface.md` :
> - les résidus (a), (b) et la désactivation ;
> - les points M1, M2, M3, M4, M5, M6, M8, M9, M10, M11, M12 et M13 ;
> - les points T10 (`msradar_upgraded`), T11 (page énorme, erreurs SQL avalées, `{}`) et T12 (`scope=ids`, `rest_parse_date`).
>
> Les autres restent ouverts, notamment :
> - M7 (registre autoloadé), prévu pour M4 avec la règle `heavy_autoload` ;
> - les points de WP-CLI, prévus pour M5.

Points relevés pendant l'exécution du plan M1 et volontairement laissés pour plus tard. Ils servent d'entrée à la planification de M2. Les points bloquants ont tous été corrigés sur la branche `v2` (revue finale : 7 points importants corrigés, aucun point critique).

## 1. Résidus de la revue finale

- **Recalcul et passage de file dans la même requête cron** : jusqu'à 2 × le budget (30 à 40 s). Correctif prévu : `Queue::run_recompute()` se reporte d'une minute quand `$processed` est posé, plus un test. Corriger aussi le commentaire `Queue.php:138` et le message d'assertion `QueueTest.php:208`.
- **Réglages modifiés pendant un recalcul** : le recalcul en cours peut réécrire son curseur après la remise à zéro. Les sites déjà parcourus gardent les anciennes alertes jusqu'au passage quotidien suivant. Correctif prévu : enregistrer une empreinte de la configuration avec le curseur et repartir de 0 si elle a changé.
- **Désactivation réseau** : `msradar_legacy_menu_batch` reste planifié sur le site principal (la désinstallation le supprime).

## 2. Points mineurs de la revue finale


**M1 — Politique d'exceptions incohérente.**
- `ExtensionsRepository` lève une exception à chaque écriture ratée.
- `Invalidation::on_site_deleted()` (`includes/Scan/Invalidation.php:114-119`) l'appelle sans `try`, à l'intérieur du hook cœur `wp_delete_site`. Une erreur SQL transforme alors une suppression réussie en erreur fatale, et les autres plugins abonnés au hook ne passent pas.
- Même chose pour `record_failure()` → `save()` dans le `catch` de `BatchRunner::scan_site()` (`:135-143`, `:149-165`) : l'exception sort de `run()`.

Correctif : attraper `\RuntimeException` dans les gestionnaires de hooks, et documenter la règle « les dépôts lèvent, les gestionnaires attrapent ».

**M2 — « Inactif » : la règle et le filtre ne disent pas la même chose.**
- `InactiveRule.php:49` ne lève aucune alerte quand `last_activity_gmt` est NULL, comme la 1.x.
- Le filtre REST `inactive_since` inclut les NULL (`SitesRepository.php:292`).

Conséquence : le compteur de l'alerte et le filtre d'inactivité de l'interface M2 divergeront. Choisir une sémantique et la documenter.

**M3 — Changer `scan.activity_post_types` ne fait que relancer le recalcul** (`Queue.php:181-184`).
- `last_activity_gmt` dépend de ces types et n'est recalculé que lors d'une analyse.
- Le réglage ne prend donc effet qu'à la prochaine analyse complète, jusqu'à 7 jours plus tard.

Correctif : écouter les deux arguments de `msradar_settings_updated`, et si les types ont changé, appeler `mark_all_dirty()` puis `continue_soon()`.

**M4 — Le nom de repli traduit « Site #%d » est enregistré en base** par le collecteur (`SiteCollector.php:109-112`), dans la langue du cron.
- La couche de lecture le reconstruit déjà dans la langue de l'utilisateur (`SitesQuery.php:124-127`) : la logique est dupliquée.
- La recherche porte sur la chaîne traduite stockée.

Correctif : stocker `''` et laisser la lecture appliquer le repli.

**M5 — `SitesQuery::ALERT_LEVELS` + `array_search`** (`SitesQuery.php:18-23`, `:129`) duplique `Severity::LEVELS` et `Severity::name()`, qui n'est jamais utilisé. Utiliser `Severity::name()`.

**M6 — Les listes chargent trop de données.**
- `SELECT *` lit la colonne LONGTEXT `data` (`SitesRepository.php:317`).
- `json_decode` est appelé sur chaque ligne uniquement pour lire `data.options.siteurl` (`SitesQuery.php:121`).

C'est un frein pour l'objectif < 300 ms à 5 000 sites. Sélectionner des colonnes explicites et garder `siteurl` dans une colonne. Le banc de M7 le confirmera.

**M7 — Tout le registre `msradar_registry` est autoloadé** (`RegistryProbe.php:97`) : les libellés de tous les types sont chargés à chaque requête de chaque site.
- Seuls `fingerprint` et `built_at` servent à `is_due`.
- Séparer un petit tampon autoloadé et un contenu non autoloadé.
- La règle M4 `heavy_autoload` comptera ce poids.

**M8 — `__()` dans le libellé de `cron_schedules`** (`Queue.php:63`).
- Si un plugin tiers appelle `wp_schedule_event()` avant `init`, WordPress 6.7 et plus signale « translation loading triggered too early » pour `multisite-radar` dès que fr_FR sera livré.
- Correctif : `did_action( 'init' ) ? __( … ) : 'Every five minutes'`.

**M9 — Le message de `HighMediaRule` n'utilise pas `_n()`** (`HighMediaRule.php:65`).

**M10 — La spec §9 prévoit `wp_add_privacy_policy_content`, mais il n'est ni planifié ni implémenté**, alors que M1 stocke déjà les logins des comptes à privilèges. C'est une lacune du plan, qui peut être comblée avec l'admin de M2.

**M11 — `CHANGELOG.md:1` s'intitule encore « Changelog - Network Plugin Utilities ».**

**M12 — `RuleRegistry::all()` ne valide pas les identifiants des règles tierces** (`RuleRegistry.php:35-49`). Une virgule ou un `%` dans un identifiant casse l'encodage `,id,` de `alert_rules` et son comptage. Valider avec `^[a-z0-9_]{1,40}$`.

**M13 — Incohérence entre le relevé et l'invalidation.** `RegistryProbe::run()` appelle `mark_dirty()` sans vérifier `Schema::is_current()` (`RegistryProbe.php:98`), alors que `Invalidation` le vérifie. Si l'installation a échoué, une erreur SQL est journalisée. À regrouper avec le minor T10 l.148.


## 3. Points mineurs reportés pendant les revues de tâches

Relevés par les relecteurs de chaque tâche, classés « peut attendre » par la revue finale, sauf ceux déjà corrigés par la vague finale (empreinte, garde `ms_is_switched()`, désinstallation par lots).

- **T1** — phpstan.neon.dist reportUnmatchedIgnoredErrors:false is global — revisit once iterable types exist (remove it or the ignore)
- **T1** — .gitignore "# Multisite Radar" glued to ".claude" line (no blank line)
- **T1** — Plugin::boot() trailing `return;` in non-multisite branch is a no-op (plan-mandated, filled by later tasks)
- **T1** — no test of the non-multisite notice path (suite always multisite)
- **T2** — Settings::update() ignores update_site_option() result — on a failed write it still returns success and fires msradar_settings_updated (Settings.php:306-312)
- **T2** — associative `params` are merged, so a params key cannot be removed by a patch (Settings.php:329-333) — revisit with M4 rules
- **T2** — Capabilities::map casts filter value with (string) — array value would warn (Capabilities.php:58)
- **T2** — no tests for digest_day/retention/mode bounds, empty patch, Plugin::reset_caches()
- **T3** — Review Focus 4 test checks dirty but not dirty_since preserved by save() (SitesRepositoryTest.php:73-85)
- **T3** — SchemaTest::test_install_is_idempotent proves little (bootstrap already installed) — partly addressed if I2 adds assertTrue(install())
- **T3** — delete_orphans() leaves msradar_site_extensions rows of vanished sites (SitesRepository.php:115-120)
- **T3** — save() exists()+insert not atomic vs concurrent insert_pending; 2 round-trips (SitesRepository.php:53-62) — INSERT … ON DUPLICATE KEY UPDATE excluding dirty columns
- **T3** — ExtensionsRepository keeps '' slugs from corrupted active_plugins; one INSERT per extension (ExtensionsRepository.php:26,36-45)
- **T3** — save_alerts() ignores update() result and doesn't truncate alert_rules (out-of-scope obs. from re-review)
- **T4** — maybe_upgrade on every admin_init (incl. admin-ajax/admin-post, no cap check); a persistently failing install retries on every admin request — consider cap guard or throttle transient (Installer.php:49-57, Plugin.php:102)
- **T4** — failed install is silent (no error_log / notice / flag) (Installer.php:39-41, 53-55)
- **T5** — Fingerprint sort() default flag — SORT_STRING more robust (Fingerprint.php:37-38)
- **T5** — wp_json_encode false → md5('') shared by all sites (Fingerprint.php:40)
- **T5** — mu-plugins not in fingerprint → mu-plugin change doesn't make registry stale before MAX_AGE (Fingerprint.php:43-55) — triage vs spec §3.4
- **T5** — eval()'d-code file names / single-file theme-root files give odd slugs (OriginResolver.php:157-163)
- **T5** — duplicated self_dir check (before/after aliases) deserves a comment (OriginResolver.php:111-122)
- **T5** — tests miss plugins-extra/ boundary, mu-plugin precedence, corrupted active_plugins in current(); test_current_reads_the_current_site mirrors implementation
- **T5** — single-file symlinked mu-plugin not aliased (realpath_aliases skips non-dirs); alias-merge block in from_environment() repetitive
- **T6** — schedule() idempotence test can't fail (core dedups events within 10 min) — pre-schedule at time()+HOUR first (RegistryProbeTest.php:121-128)
- **T6** — no test pins "no tracking during public rendering" (front → no register_post_type_args filter; wp_doing_cron → filter present)
- **T6** — test type `test_untracked` misnamed (it is tracked but unresolved) (RegistryProbeTest.php:66,79)
- **T6** — admin_init runner stores labels in the admin user's locale, cron in the site locale (RegistryProbe.php:52-53)
- **T6** — tracking enabled on every due admin page even without DISABLE_WP_CRON, where no admin runner exists — wasted debug_backtrace (RegistryProbe.php:45)
- **T6** — untracked-run test doesn't assert mark_dirty not called
- **T7** — "Excluded types are not listed" assertion proves nothing (no revision/wp_block rows); msradar_excluded_* filters untested (SiteCollectorTest.php:55)
- **T7** — (array) cast of corrupted registry sub-fields creates phantom type "0"; non-scalar origin.kind → Array to string warning (SiteCollector.php:66-67, 343)
- **T7** — privileged users query: $patterns[0]/[1] tied to PRIVILEGED_ROLES size; LIMIT 50 before PHP filtering (SiteCollector.php:236-245)
- **T7** — GROUP BY meta_value on LONGTEXT limited by max_sort_length (SiteCollector.php:216)
- **T8** — one invalid/extra stored param key discards all valid overrides for that rule (AlertEvaluator.php:106-109)
- **T8** — alert_rules 255-char truncation branch untested; after truncation alerts_count/data.alerts keep all alerts (AlertEvaluator.php:152-154)
- **T8** — RuleRegistry lazy filter (no caching before plugins_loaded) untested
- **T9** — Review Focus 3 race — a site deleted AFTER collect() succeeded but before save() gets its row re-inserted by save() (insert-or-update); delete_orphans() (daily) removes it later
- **T9** — DB error in acquire() reads as "locked" ((int) false = 0) (Lock.php:24-33)
- **T9** — lock expiry uses PHP time(); clock skew across web servers — UNIX_TIMESTAMP() in SQL (Lock.php:22,39,49)
- **T9** — $seen + next_dirty(CHUNK) can end a run early when ≥10 seen sites are re-marked with older dirty_since (BatchRunner.php:62-65)
- **T9** — storage-failure test doesn't assert scanned_at null / no msradar_site_scanned / $on_site false; $on_site, default_budget(), memory branch untested; deleted-site test doesn't check extensions; suppress_errors/remove_filter not in finally (BatchRunnerTest.php:94,133-146)
- **T9** — `current() !== $value &&` redundant in refresh(); a failed refresh UPDATE returns true without extending (detected at next refresh) (Lock.php:60)
- **T9** — no test pins run() stopping when refresh() returns false (BatchRunner.php:77-78)
- **T9** — ExtensionsRepositoryTest data provider non-static (deprecated in PHPUnit 10)
- **T10** — Queue has no Schema::is_current() guard (process/daily/run_recompute/ensure_scheduled) → wpdb errors every 5 min if install failed; new code on old schema until maybe_upgrade (Queue.php:76-97,110-123,153-155)
- **T10** — upgrade path from 1.x may stay unscheduled until a main-site/network admin visit; hook msradar_upgraded to schedule() (Queue.php:47,76-80)
- **T10** — QueueTest misses continuation when remaining>0, no continuation when locked elsewhere, do_action(HOOK_RECOMPUTE) recomputes, continuation after request_full_scan, recompute pagination >200 (QueueTest.php:58-103)
- **T10** — InvalidationTest schema guard only via switch_theme; add_user_to_blog test passes even if on_user_added broken (set_user_role also fires) (InvalidationTest.php:71-80,122-131)
- **T10** — request_full_scan( $network_id ) stamps LAST_FULL_SCAN on the current network (update_network_option( $network_id, … )) (Queue.php:106,116)
- **T10** — seed_from_blogs runs twice when a full scan is due (daily + request_full_scan) (Queue.php:104,112)
- **T11** — huge `page` overflows OFFSET (float→%d) → SQL error or page 1; cap page or short-circuit when offset >= total (SitesQuery.php:65, SitesRepository.php:306)
- **T11** — read SQL errors swallowed → {items:[], total:0} 200 indistinguishable from an empty network (SitesRepository.php:309-353)
- **T11** — empty assoc arrays (options, users.by_role) serialise as [] instead of {} (SitesQuery.php:104-109)
- **T11** — has_users (bool)'false' → true for CLI strings (rest_sanitize_boolean); inactive_since '' still filters (SitesQuery.php:74-75)
- **T11** — coverage — alert_counts() network isolation, get() scan_error/users default/taxonomies filter/network_plugins_count/installed theme, summary key set, backslash search (AlertsQueryTest.php:10-13, SitesQueryTest.php:75-128)
- **T11** — a site whose first scan failed stays pending:true forever with no scan_failed flag in summary() (product question) (SitesQuery.php:146-152, AlertsQuery.php:22-45)
- **T11** — alert_rules varchar(255) truncation may cut a rule id once M4 rules exist; alert_counts() one COUNT per rule (SitesRepository.php:344)
- **T12** — scope=ids marks sites of other networks dirty (mark_dirty has no network filter); empty/unknown ids → 200 no-op instead of 400 (ScanController.php:91-93)
- **T12** — rest_parse_date( …, true ) replaces the offset instead of converting it (inactive_since with +02:00 read as UTC) (SitesController.php:136)
- **T12** — rule params validated but not sanitised ("3" stored as string); validation only in REST layer (SettingsController.php:82-87)
- **T12** — Installer::maybe_upgrade() on every rest_api_init (all namespaces, anonymous) (Plugin.php:83)
- **T12** — coverage — F7 empty-body 400, 403 for site admin on scan/status, scan/batch, POST settings, alerts/summary; REST 404 for other-network site; view-without-manage user denied on manage routes
- **T12** — REST search test 'n_S' doesn't prove `_` is literal (T11's repository test does) (SitesControllerTest.php:79)
- **T12** — SettingsController::get_item_schema() not cached in $this->schema (SettingsController.php:48)
- **T13** — batch boundary (BATCH+1 sites, item on last) untested (LegacyMigrationTest.php:89-95)
- **T13** — negative tests (custom/post_type items untouched, site without tables doesn't break batch); queries not JOINed to nav_menu_item posts (LegacyMigration.php:143,152)
- **T13** — transient purge pattern broader than 1.x's two prefixes, O(sites) deletes in the start() request, no-op with external object cache (LegacyMigration.php:194-202)
- **T13** — deleting npu_enable_network_menu re-enables 1.x's menu metabox if still loaded; notice wording could say 1.x settings are now ignored (LegacyMigration.php:97-99)
- **T13** — finish() $cursor param unused and cursor['menu_items'] accumulated but never read (dead code) (LegacyMigration.php:157,238)
- **T13** — MAX_ATTEMPTS skip path and attempts reset untested (LegacyMigration.php:145-149)
- **T13** — a transient SELECT failure under suppress_errors looks like "no items" → cursor advances without conversion (LegacyMigration.php:183-192)
- **T13** — with a persistent object cache site transients aren't in sitemeta → transient purge and transient-based 1.x detection are no-ops (LegacyMigration.php:79,248-250)
- **T14** — lock lost mid-run → WP_CLI::error discards processed count (RadarCommand.php:107-109)
- **T14** — flatten() accesses item keys without defaults (SitesCommand.php:267-271)
- **T15** — uninstall raw DELETE of per-site options bypasses options cache and queries tables of deleted sites (could use delete_option inside the existing switch_to_blog) (uninstall.php:188-196)
- **T15** — no automated guard that uninstall lists stay in sync with msradar_ data literals in includes/
