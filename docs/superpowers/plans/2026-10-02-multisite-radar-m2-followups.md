# Multisite Radar — Jalon M2 : points reportés

Points relevés pendant l'exécution du plan M2 (`2026-10-01-multisite-radar-m2-interface.md`), par la revue finale, la contre-relecture et la recette du 2026-10-02, et volontairement laissés pour plus tard. Ils servent d'entrée à la planification des jalons suivants. Aucun point critique ni important ne reste ouvert : la vague de corrections de la revue finale les a tous traités (C1, I1, I2).

**Déjà traité depuis M2 (2.0.0-beta.2) :**
- `.distignore` exclut `CHANGELOG.md` et `.superpowers` ; `make dist` refuse toute entrée imprévue dans le paquet ;
- délai maximal du job CI `e2e-ui` ;
- traduction fr_FR (prévue en M7), DataViews compris.

## 1. À traiter avant la 2.0 finale

**DataViews dupliqué dans chaque vue.**
- `build/admin/{sites,alerts,settings}.js` font environ 2 Mo minifiés (390 Ko gzip) chacun, avec le même contenu DataViews (`@wordpress/theme`, `@wordpress/ui`, une copie des private-apis). Passer de Sites à Alertes puis à Réglages télécharge et analyse ce contenu trois fois ; chaque déploiement l'envoie trois fois.
- Correctif : un chunk partagé `admin/dataviews`, enregistré comme dépendance de script, que le navigateur garde en cache d'une page à l'autre. `performance.hints: false` masque aujourd'hui l'avertissement de taille.
- Traductions : les chaînes de DataViews sont rattachées au fichier compilé qui les contient. Avec un chunk partagé, `bin/i18n.sh` produira leur JSON pour ce chunk ; la recopie vers le domaine « default » (`Admin\Assets::SHARE_TRANSLATIONS`) doit alors se faire avant ce chunk.

**Rôles affichés en identifiants bruts** (`src/views/site-panel/users-tab.jsx`, `summary-tab.jsx`).
- La fiche affiche `administrator`, `editor`… même en français.
- Correctif : noms de rôles lus dans `{prefix}user_roles`, traduits avec `translate_user_role()`.

**Action groupée « Réanalyser » pendant une analyse** (`src/hooks/use-scan.js`, `src/views/sites/actions.js`).
- DataViews 19.1 ignore `disabled` sur les actions groupées. Pendant une analyse, le bouton reste actif ; la garde de réentrance renvoie l'analyse en cours, donc la nouvelle sélection n'est ni marquée ni analysée, et « Analyse terminée. » s'affiche.
- Correctif : un avis « une analyse est déjà en cours », ou mettre la sélection en file d'attente.

**Colonne « Analysé le » tronquée.** Sur la page Sites, à 1440 px de large, la colonne passe sous la colonne fixe « Actions », en anglais comme en français. Pistes : masquer une colonne par défaut, raccourcir certaines colonnes, ou laisser les en-têtes passer à la ligne.

**Pas de squelettes de chargement** (spec §6.4). La première vue est préchargée, mais les rechargements de liste montrent le spinner de DataViews et la fiche un texte d'attente. Ajouter des squelettes, ou consigner l'écart à la spec.

**L'éditeur de blocs intègre la liste de tous les sites** (`includes/SitesMenu/Block.php`, `editor_data()`). Environ 40 octets par site public, à chaque chargement de l'éditeur sur chaque site, soit 200 Ko pour 5 000 sites. Correctif : charger la liste à la demande.

**Export interrompu en cours de flux.** Une panne de la base pendant l'export tronque le fichier sans le signaler autrement que par `msradar_error`. Envisager une trace et un marqueur visible en fin de CSV.

**Cache du menu des sites au-delà de 100 sites.** Le chemin qui lit les sites par lots de 100 n'a pas de test : c'est le test le plus utile à ajouter avant la 2.0.

**Accessibilité.** L'audit axe de la page Réglages exclut un champ caché de DataViews 19.1, invisible et sans libellé en amont. Préciser le commentaire, ajouter le lien vers le ticket amont, et retirer l'exclusion quand DataViews sera corrigé. L'audit de la fiche ne couvre que l'onglet par défaut.

**Publication.** Confirmer « Tested up to: 7.1 » au moment de la soumission à WordPress.org.

## 2. Points mineurs

Tous jugés « peuvent attendre » par la revue finale.

**PHP.**
- `RuleRegistry::ID_PATTERN` et les motifs `^\d+$` acceptent un saut de ligne final en PHP (`$`), pas en JS : la clé préchargée ne correspond plus et une requête est perdue. Utiliser `\z` ou le modificateur `D`.
- `ViewQuery::path()` garde les valeurs vides, contrairement à `buildPath()` côté JS ; un identifiant de site énorme devient `PHP_INT_MAX`.
- `SiteUsersQuery` : `is_super_admin()` charge chaque utilisateur (utiliser `get_super_admins()` une fois) ; le tri par nom ou date d'inscription n'a pas de départage par `ID`.
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
- `webpack.config.js` n'est pas couvert par `lint:js` et a une erreur Prettier ; durcir le remplacement de `DependencyExtractionWebpackPlugin` (lever une erreur si aucune instance n'a été remplacée).
- `npm run plugin-zip` ajoute `package.json` et `README.md` au zip : le supprimer ou le faire pointer vers `make dist`.
- `bin/version.mjs` : erreur nue dans `read()`, format de version non validé par `check()`.
- `tests/e2e/setup.sh` : le retrait de l'utilisateur du site « vide » n'a lieu qu'à sa création ; avertissement de dépréciation de wp-env (`testsEnvironment: false`).
- Chemins sans test : erreur 500 de `/alerts`, repli du message sur le libellé, échappement des jokers LIKE, `X-WP-TotalPages` > 1, branches de repli des rôles et des tris de `SiteUsersQuery`, 401 et méta non tableau des préférences, chemin d'erreur de `wp multisite-radar sites list`, cas `status` / `registry_status` / `rule` d'`export.test`, `&site=` inconnu, onglet Contenu et `scan_error`, vidage du cache sur suppression de site et entre réseaux, désinstallation.
