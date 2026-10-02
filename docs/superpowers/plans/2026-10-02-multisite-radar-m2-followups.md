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
- commentaire de l'exclusion axe précisé (2.0.0-beta.3, plan M3).

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
- Réglages : l'état « modifié » reste vrai après un retour à la valeur initiale ; l'enregistrement envoie les trois champs d'analyse (le dernier enregistrement l'emporte entre deux admins).
- Fiche : le focus revient sur `body` si la ligne d'origine a été remplacée ; pas de pagination au-delà de la dernière page des comptes.

**Outillage et tests.**
- `bin/version.mjs` : erreur nue dans `read()`, format de version non validé par `check()`.
- `tests/e2e/setup.sh` : le retrait de l'utilisateur du site « vide » n'a lieu qu'à sa création ; avertissement de dépréciation de wp-env (`testsEnvironment: false`).
- Chemins sans test : erreur 500 de `/alerts`, repli du message sur le libellé, échappement des jokers LIKE, `X-WP-TotalPages` > 1, branches de repli des rôles et des tris de `SiteUsersQuery`, 401 et méta non tableau des préférences, chemin d'erreur de `wp multisite-radar sites list`, cas `status` / `registry_status` / `rule` d'`export.test`, `&site=` inconnu, onglet Contenu et `scan_error`, vidage du cache sur suppression de site et entre réseaux, désinstallation.

## 3. Reportés par le plan M3

Points relevés pendant l'exécution du plan M3 (`2026-10-02-multisite-radar-m3-inventory.md`), par les revues de tâche et la revue finale, et laissés pour plus tard. Aucun n'est bloquant.

**PHP.**
- Inventaire des plugins : le nombre de sites d'un plugin activé sur le réseau compte tous les sites, y compris ceux qui attendent leur première analyse, alors que l'écart E8 et le docblock disent que seuls les sites analysés comptent. C'est cohérent avec `GET /plugins/{id}/sites`, mais ce n'est écrit nulle part. Le tri des noms par `strnatcasecmp` compare des octets (un nom accentué passe après l'ASCII), et `all()` est recalculé à chaque appel de `find()` et de `summary()`.
- Comptage des comptes : `COUNT(*)` compte des clés de capacités, pas des sites distincts. Une clé parasite `{base}1_capabilities` à côté de `{base}capabilities` compterait deux fois le site 1 ; `COUNT(DISTINCT b.blog_id)` corrige. Les sites archivés, indésirables ou supprimés comptent comme des rattachements alors que le docblock cite `get_blogs_of_user()`, qui les exclut. Le drapeau super-admin est comparé avec `in_array` sensible à la casse, alors que le `IN` de SQL ignore la casse.
- Le motif du schéma REST terminé par `\z` n'est pas une expression régulière JavaScript valide si un client lit un jour le schéma.
- `InventoryExport::check()` lit sans filtre, et l'écriture du marqueur d'interruption n'est pas protégée dans le `catch`.
- `LIGHT_VIEWS` est écrit deux fois, dans `Assets` (PHP) et dans `webpack.config.js`. Dans `Plugin.php`, le `use` de `ThemesController` n'est pas dans l'ordre alphabétique.

**JS.**
- Le câblage des préférences de vue (`localPrefs`, `onChangeView`, `samePrefs`, `savePrefs`, une douzaine de lignes) est recopié dans les vues Sites, Alertes, Inventaire et Utilisateurs. Un hook `useViewPreferences( view )` le centraliserait ; aujourd'hui, changer ce comportement demande quatre modifications.
- `SidePanel` code en dur l'identifiant `msradar-panel-title` (un seul panneau à la fois). La condition « vide ou squelette » est répétée dans Sites et Alertes.
- `ExtensionSitesPanel` ne remet pas sa page à zéro sans remontage (la vue Plugins et la vue Thèmes le montent avec `key={ open.id }`, donc rien ne casse aujourd'hui).
- `ExtensionTitle` réutilise les classes `msradar-site-title*`. `isLoading={ false }` n'est pas commenté, et trois avertissements jsdoc `Function` subsistent dans le lint.

**Tests et outillage.**
- Cas non couverts côté PHP : un dossier de thème numérique, la branche « clé du site 1 » du comptage des comptes, un rôle accordé sans définition (repli sur l'identifiant), un test REST d'une règle terminée par un saut de ligne, un export interrompu des plugins et des thèmes. Les tests 500 des plugins, des thèmes et de l'inventaire ne coupent qu'une des requêtes. Le test de départage des tris est faible (MySQL peut rendre l'ordre de la clé primaire de toute façon).
- Cas non couverts côté JS : thème utilisé seulement comme parent dans la liste, `describeSite` sans `site.theme`, pas de page jamais vide pendant le chargement d'une nouvelle page, panneau sur plusieurs pages, `columnsFor` sans champs, branchement du lien d'export, erreur de lecture de l'inventaire, branche « plugins seulement » et « les deux » de la tuile des mises à jour (seule « thèmes seulement » est testée). Le test du badge super-admin n'est pas limité à la ligne de l'administrateur, et la vue Utilisateurs n'est testée que dans le cas heureux préchargé.
- `tests/e2e/setup.sh` ne se répare pas si le retrait de l'utilisateur échoue après son insertion. Le commentaire de l'audit d'accessibilité dit « Page Réglages » alors que l'exclusion s'applique à toutes les pages auditées.
