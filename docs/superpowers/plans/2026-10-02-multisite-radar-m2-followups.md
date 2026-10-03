# Multisite Radar — Jalon M2 : points reportés

Points relevés pendant l'exécution du plan M2 (`2026-10-01-multisite-radar-m2-interface.md`), par la revue finale, la contre-relecture et la recette du 2026-10-02, et volontairement laissés pour plus tard. Ils servent d'entrée à la planification des jalons suivants. Aucun point critique ni important ne reste ouvert : la vague de corrections de la revue finale les a tous traités (C1, I1, I2).

**Déjà traité depuis M2 :**
- `.distignore` exclut `CHANGELOG.md` et `.superpowers` ; `make dist` refuse toute entrée imprévue dans le paquet ;
- délai maximal du job CI `e2e-ui` ;
- traduction fr_FR (prévue en M7), DataViews compris ;
- DataViews n'est plus dupliqué dans chaque vue : chunk partagé `admin/dataviews` (2.0.0-beta.3, plan M3) ;
- noms de rôles traduits dans la fiche, `SiteUsersQuery` lit les super-admins une fois et départage les tris par `ID` (2.0.0-beta.3, plan M3) ;
- squelettes de chargement à la place des spinners (2.0.0-beta.3, plan M3) ;
- export interrompu en cours de flux : marqueur visible en fin de fichier (2.0.0-beta.3, plan M3) ;
- motifs `ID_PATTERN` et `^\d+$` terminés par `\z`, `ViewQuery::path()` aligné sur `buildPath()` (2.0.0-beta.3, plan M3) ;
- `webpack.config.js` couvert par `lint:js`, remplacement de `DependencyExtractionWebpackPlugin` vérifié (2.0.0-beta.3, plan M3) ;
- `npm run plugin-zip` appelle `make dist` (2.0.0-beta.3, plan M3) ;
- commentaire de l'exclusion axe précisé (2.0.0-beta.3, plan M3) ;
- Réglages : l'état « modifié » redevient faux au retour à la valeur initiale, et l'enregistrement n'envoie que les valeurs modifiées (2.0.0-beta.4, plan M4) ;
- `Plugin.php` : le `use` de `ThemesController` est dans l'ordre alphabétique (2.0.0-beta.4, plan M4) ;
- commentaire de l'audit d'accessibilité : l'exclusion vaut pour toutes les pages auditées (2.0.0-beta.4, plan M4) ;
- schémas d'élément des routes `/plugins`, `/themes`, `/users`, `/alerts`, `/alerts/summary`, `/inventory/summary` et `/scan/status` (2.0.0-beta.5, plan M5).

## 1. À traiter avant la 2.0 finale

**Action groupée « Réanalyser » pendant une analyse** (`src/hooks/use-scan.js`, `src/views/sites/actions.js`).
- DataViews 19.1 ignore `disabled` sur les actions groupées. Pendant une analyse, le bouton reste actif ; la garde de réentrance renvoie l'analyse en cours, donc la nouvelle sélection n'est ni marquée ni analysée, et « Analyse terminée. » s'affiche.
- Correctif : un avis « une analyse est déjà en cours », ou mettre la sélection en file d'attente.

**Colonne « Analysé le » tronquée.** Sur la page Sites, à 1440 px de large, la colonne passe sous la colonne fixe « Actions », en anglais comme en français. Pistes : masquer une colonne par défaut, raccourcir certaines colonnes, ou laisser les en-têtes passer à la ligne.

**L'éditeur de blocs intègre la liste de tous les sites** (`includes/SitesMenu/Block.php`, `editor_data()`). Environ 40 octets par site public, à chaque chargement de l'éditeur sur chaque site, soit 200 Ko pour 5 000 sites. Correctif : charger la liste à la demande.

**Cache du menu des sites au-delà de 100 sites.** Le chemin qui lit les sites par lots de 100 n'a pas de test : c'est le test le plus utile à ajouter avant la 2.0.

**Accessibilité.** L'audit axe exclut un champ caché de DataViews 19.1, invisible et sans libellé en amont : ajouter le lien vers le ticket amont et retirer l'exclusion quand DataViews sera corrigé. L'audit de la fiche ne couvre que l'onglet par défaut.

**Publication.** Confirmer « Tested up to: 7.1 » au moment de la soumission à WordPress.org.

## 2. Points mineurs

Tous jugés « peuvent attendre » par la revue finale.

**PHP.**
- `Preferences::update()` ignore un échec de `update_user_meta()` (réponse 200 avec les anciennes valeurs).
- `AlertsQuery` : `per_page` plafonné à 100 en dur ; une paire dont le site disparaît entre deux lectures est perdue et le total compte un élément de trop.
- Les gestionnaires `mark_dirty` / `mark_all_dirty` / `update_last_activity` d'`Invalidation` et les rappels d'invalidation du cache du menu ne passent pas par `safely()` : ils ne lèvent pas aujourd'hui ; les envelopper si un dépôt gagne une vérification de lecture.
- `SitesListCache` : un site sans ligne `blogname`/`home` disparaît ; une URL rejetée donne `<a href="">` ; le tri décroissant inverse les égalités d'ID ; `uninstall.php` code en dur le préfixe du transient.
- Export CSV : une formule précédée d'espaces n'est pas neutralisée ; `include=0,x` apparaît dans les métadonnées.
- `Module::register_public_api()` : un chargeur personnalisé qui démarrerait le plugin pendant `plugins_loaded` à une priorité inférieure à 10, avec la 1.x encore chargée, déclarerait `rdc_network_sites_menu()` avant elle (« Cannot redeclare »). Très improbable ; correctif : une branche `doing_action( 'plugins_loaded' )` qui s'accroche quand même à la priorité 20.
- Un transient de la liste des sites créé avant le passage en texte brut garde les noms échappés dans les jetons de l'éditeur jusqu'à un jour.
- `POST /scan` : `ids` sans `maxItems` ; le total d'une analyse ciblée reste `ids.length` quand des identifiants sont écartés.
- Le docblock de `SitesQuery::identity()` parle de lignes écrites « before 2.0.0-beta.2 » alors que le correctif est livré en 2.0.0-beta.1.

**JS.**
- `useScan` : `speak()` double l'annonce du snackbar ; une attente de trop après le dernier lot sans progrès et pas de plafond absolu de boucle.
- `useResource` : une réponse arrivée après `invalidate()` est mise en cache comme fraîche (un compteur de génération corrigerait) ; écriture d'une ref pendant le rendu.
- `useUrlState` perd les paramètres inconnus et le `#hash`, et n'écoute pas `popstate`.
- Sites : `layout=table` est écrit dans l'URL après tout changement de vue ; en grille, `badgeFields` est perdu au premier chargement avec `?layout=grid`.
- Vue d'ensemble : deux `<progress>` pendant une analyse (FirstRun et ScanPanel) ; FirstRun masque son bouton au lieu de le désactiver.
- Fiche : le focus revient sur `body` si la ligne d'origine a été remplacée ; pas de pagination au-delà de la dernière page des comptes.

**Outillage et tests.**
- `bin/version.mjs` : erreur nue dans `read()`, format de version non validé par `check()`.
- `tests/e2e/setup.sh` : le retrait de l'utilisateur du site « vide » n'a lieu qu'à sa création ; avertissement de dépréciation de wp-env (`testsEnvironment: false`).
- Chemins sans test : erreur 500 de `/alerts`, repli du message sur le libellé, échappement des jokers LIKE, `X-WP-TotalPages` > 1, branches de repli des rôles et des tris de `SiteUsersQuery`, 401 et méta non tableau des préférences, chemin d'erreur de `wp multisite-radar sites list`, cas `status` / `registry_status` / `rule` d'`export.test`, `&site=` inconnu, onglet Contenu et `scan_error`, vidage du cache sur suppression de site et entre réseaux, désinstallation.

## 3. Reportés par le plan M3

Points relevés pendant l'exécution du plan M3 (`2026-10-02-multisite-radar-m3-inventory.md`), par les revues de tâche et la revue finale, et laissés pour plus tard. La revue finale de M3 n'a trouvé aucun point bloquant une fois la vague de corrections appliquée.

**PHP.**
- Inventaire des plugins : le nombre de sites d'un plugin activé sur le réseau compte tous les sites, y compris ceux qui attendent leur première analyse, alors que l'écart E8 et le docblock disent que seuls les sites analysés comptent. C'est cohérent avec `GET /plugins/{id}/sites`, mais ce n'est écrit nulle part. Le tri des noms par `strnatcasecmp` compare des octets (un nom accentué passe après l'ASCII), et `all()` est recalculé à chaque appel de `find()` et de `summary()`.
- `/users` : un compteur de cache propre au plugin (`last_changed` du plugin, incrémenté sur `add_user_to_blog`, `remove_user_from_blog`, la création et la suppression d'un compte, `profile_update`, `granted_super_admin` et `revoked_super_admin`, la création et la suppression d'un site). WordPress 6.3 et suivants incrémente `last_changed('users')` à chaque écriture de métadonnée d'un compte, connexions comprises, si bien que le cache de dix minutes sert rarement. Les filtres d'appartenance et le tri par nombre de sites agrègent encore toutes les appartenances.
- Installations à plusieurs réseaux : compter « inutilisé » sur toute l'installation (`active_sitewide_plugins` des autres réseaux et lignes analysées) au lieu de seulement avertir, comme le fait aujourd'hui l'avis des pages Plugins et Thèmes.
- Mémoïsation par requête de `PluginsQuery::all()` et de `ThemesQuery::all()` : la synthèse de l'inventaire et la liste les recalculent pendant le même chargement de page.
- L'avis « sites pas encore analysés » dit que rien n'est compté pour ces sites, alors qu'un plugin activé sur le réseau les compte.
- Comptage des comptes : les sites archivés, indésirables ou supprimés comptent comme des rattachements alors que le docblock cite `get_blogs_of_user()`, qui les exclut. Le drapeau super-admin est comparé avec `in_array` sensible à la casse, alors que le `IN` de SQL ignore la casse.
- Le motif du schéma REST terminé par `\z` n'est pas une expression régulière JavaScript valide si un client lit un jour le schéma.
- `InventoryExport::check()` lit sans filtre, et l'écriture du marqueur d'interruption n'est pas protégée dans le `catch`.
- `LIGHT_VIEWS` est écrit deux fois, dans `Assets` (PHP) et dans `webpack.config.js`.

**JS.**
- Le câblage des préférences de vue (`localPrefs`, `onChangeView`, `samePrefs`, `savePrefs`, une douzaine de lignes) est recopié dans les vues Sites, Alertes, Inventaire et Utilisateurs. Un hook `useViewPreferences( view )` le centraliserait ; aujourd'hui, changer ce comportement demande quatre modifications.
- `SidePanel` code en dur l'identifiant `msradar-panel-title` (un seul panneau à la fois). La condition « vide ou squelette » est répétée dans Sites et Alertes.
- `ExtensionSitesPanel` ne remet pas sa page à zéro sans remontage (la vue Plugins et la vue Thèmes le montent avec `key={ open.id }`, donc rien ne casse aujourd'hui).
- `ExtensionTitle` réutilise les classes `msradar-site-title*`. `isLoading={ false }` n'est pas commenté, et trois avertissements jsdoc `Function` subsistent dans le lint.

**Tests et outillage.**
- Cas non couverts côté PHP : un dossier de thème numérique, un rôle accordé sans définition (repli sur l'identifiant), un test REST d'une règle terminée par un saut de ligne, un export interrompu des plugins et des thèmes. Les tests 500 des plugins, des thèmes et de l'inventaire ne coupent qu'une des requêtes. Le test de départage des tris est faible (MySQL peut rendre l'ordre de la clé primaire de toute façon).
- Cas non couverts côté JS : thème utilisé seulement comme parent dans la liste, `describeSite` sans `site.theme`, pas de page jamais vide pendant le chargement d'une nouvelle page, panneau sur plusieurs pages, `columnsFor` sans champs, branchement du lien d'export, branche « plugins seulement » et « les deux » de la tuile des mises à jour (seule « thèmes seulement » est testée). Le test du badge super-admin n'est pas limité à la ligne de l'administrateur, et la vue Utilisateurs n'est testée que dans le cas heureux préchargé.
- `tests/e2e/setup.sh` ne se répare pas si le retrait de l'utilisateur échoue après son insertion.

## 4. Reportés par le plan M4

Points mineurs relevés pendant l'exécution du plan M4 (`2026-10-02-multisite-radar-m4-health.md`), par les revues de tâche, et laissés pour plus tard.

**PHP.**
- `DiskMeter` : un lien symbolique saute le compteur `++$seen`, donc un dossier qui n'en contient que n'atteint jamais le contrôle du budget en cours de dossier. `scandir()` charge un dossier d'un coup : un seul dossier énorme peut dépasser le budget (le noter dans le docblock, ou passer à `DirectoryIterator`). Un sous-dossier illisible est ignoré et le résultat reste « complet ».
- Taille des tables : `is_main_site()` vaut aussi pour le site principal d'un réseau secondaire (préfixe numéroté) ; sans conséquence, mais `$prefix === $wpdb->base_prefix` serait exact.
- `disk_quota` : un quota de 0 Mo propre au site ne déclenche jamais l'alerte (WordPress le lit comme « aucun envoi autorisé ») ; ni testé, ni documenté.
- `NetworkStateWatcher::check()` n'a ni `try/catch` ni `msradar_error` (rien ne lève aujourd'hui dans `signature()`).
- Pas de budget de temps sur la requête `information_schema` (parade du §14 de la spec) : l'indication diffère entre MySQL (`MAX_EXECUTION_TIME`) et MariaDB (`max_statement_time`) ; la requête est déjà limitée à une liste explicite de tables.
- Une liste de mises à jour absente (en cours de reconstruction) compte comme vide : si le recalcul quotidien tombe dans cet intervalle, les alertes « mises à jour en attente » disparaissent jusqu'au recalcul suivant.

**JS.**
- Fiche d'un site : avec `overdue_count` > 0 et `oldest_overdue_gmt` nul, `cronSummary` affiche « échue depuis — ».
- Réglages : `changes()` compare les tableaux avec `JSON.stringify` (changer l'ordre des types d'activité compte comme une modification, sans que ce soit écrit) et `SCAN_KEYS` est une liste blanche manuelle.
- `rules-card.jsx` : `@param {Function} props.onChange` ajoute un quatrième avertissement jsdoc au lint ; `ruleForm()` n'a pas de docblock.

**Tests et outillage.**
- Non couverts côté PHP : le contrôle de budget en cours de dossier (les tests n'utilisent que 0.0), le repli quand `information_schema` renvoie `NULL` (sans privilège), `scanned_at` nul pour `search_hidden`, `heavy_autoload` et `cron_overdue` (seule `no_admin` l'est), `deleted_theme`, `upgrader_process_complete` et le transient `update_themes` (même chemin de code), `_fields` sur `/alert-rules` et l'encodage `{}` sur la réponse envoyée ou préchargée (testé seulement sur `get_data()`).
- `SchemaTest::test_version_2_adds_the_siteurl_column` vérifie maintenant la version 3 : son nom est trompeur. `RulesTest` (thème manquant) suppose `twentytwentyfive` installé (commenté).
- Non couverts côté JS : la branche nulle de `cron` (tiret) ; un test de la fiche s'intitule « dashes » mais passe `overdue_count` à 0 ; pas de test de `changes()` pour un réglage de type tableau (`analysis_plugins`).
- E2E des réglages : `.first() sur les textes d'alerte tolère les doublons, et le champ numérique est cherché tantôt dans l'application, tantôt dans la page.

## 5. Reportés par le plan M5

Points mineurs relevés pendant l'exécution du plan M5 (`2026-10-03-multisite-radar-m5-integrations.md`), par les revues de tâche et la revue finale, et laissés pour plus tard.

**Ability différée.** `multisite-radar/recent-changes` (changements récents du réseau) attend le journal d'activité de M6 : écart E1 du plan M5.

**PHP.**
- Schémas : les indicateurs `readonly` sont absents du schéma de la fiche d'un site. `ScanController::status()` reste un simple relais d'une ligne.
- `SettingsUpdater::apply()` n'a pas de `@param` dans son docblock. `Values::parse` transforme un texte numérique en nombre : le docblock de `settings set` pourrait citer la forme `'"123"'` pour garder du texte.
- `plugins list` et `themes list` ont des corps presque identiques. La liste explicite des filtres d'`ExportCommand` recopie `SitesExport::filters()` et `InventoryExport::filters()` : elle dérivera si un filtre est ajouté. L'aide de `export` présente `--search` et `--order` comme propres aux sites, alors que les plugins et les thèmes les acceptent.
- `settings set` annonce un succès même si `update_site_option` échoue en silence (comportement hérité, partagé avec la route REST des réglages). `export --output=-` n'est pas traité comme la sortie standard : il écrit dans un fichier nommé « - ».
- `export` : `fopen` sur un dossier ou un fichier non inscriptible émet un avertissement PHP avant l'erreur, et un fichier partiel reste en place après un échec en cours de lecture (il se termine par le marqueur d'interruption).
- Abilities : le plafond de 100 résultats par page est écrit à trois endroits (schéma, `page_size()` de `Ability`, REST). `Ability::page()` divise par `per_page` sans garde propre (les appelants le bornent). `GetSiteAbility` lit `options` et `users` sans `?? []` (`SitesQuery::get` les renseigne toujours).

**JS.**
- La carte « Intégrations » s'affiche même quand le plugin MCP Adapter n'est pas installé : l'interrupteur n'a alors aucun effet visible (il pourrait le dire, ou la carte pourrait se masquer).

**Tests et outillage.**
- `ItemSchemasTest` ne compare que les clés de premier niveau du premier élément : les clés imbriquées et les types ne sont pas validés contre de vraies réponses (les tests des abilities valident la sortie par le noyau).
- `SettingsUpdaterTest` : le contrôle « rien n'est enregistré » rend la main avant `Settings::update`, le test du schéma des paramètres ne vérifie pas que le stockage reste inchangé, et `activity_post_types` est comparé à sa valeur par défaut écrite en dur.
- `RegistrarTest` vérifie `mcp.public` sur `definitions()`, non sur les métadonnées de l'ability enregistrée. La branche 404 des thèmes de `find-extension-usage` n'est pas testée.
- WP-CLI : pas de test unitaire de l'erreur de règle inconnue d'`AlertsCommand` (e2e seulement, qui ne vérifie que le code de sortie non nul, comme pour le dossier manquant de `export`) ; les alertes e2e comptent sur `search_hidden` actif à la gravité « info » par défaut, sans commentaire ; pas de test e2e de `--fields` ni d'un format autre que JSON pour `plugins list` et `themes list` ; les chemins de lecture interrompue et de fichier non inscriptible de `export` ne sont pas testés ; la branche `settings get --format=yaml` non plus.
- `tests/e2e/integrations.spec.js` : le nettoyage du bloc `finally` n'est pas protégé, si bien qu'un appel REST en échec masquerait l'erreur de l'assertion.
