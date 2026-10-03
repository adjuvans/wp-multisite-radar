# Multisite Radar

Audit réseau pour WordPress Multisite (successeur de Network Plugin Utilities 1.x).

Pages du menu réseau « Multisite Radar » : Vue d'ensemble, Sites, Plugins, Thèmes, Utilisateurs, Alertes, Réglages.

Règles d'alertes livrées : site sans utilisateurs, site sans administrateur, site inactif, beaucoup de médias, thème actif manquant, mises à jour en attente, adresse en http sur un réseau en https, quota d'envoi presque atteint, autoload trop lourd, masqué aux moteurs de recherche, tâches planifiées en retard. D'autres extensions peuvent en ajouter avec le filtre `msradar_alert_rules`.

Ligne de commande (`wp help multisite-radar` pour le détail) :

| Commande | Rôle |
|---|---|
| `wp multisite-radar scan --all\|--dirty\|--site=<id> [--probe]` | Analyse |
| `wp multisite-radar sites list` | Sites, filtrables par alerte, thème, plugin |
| `wp multisite-radar alerts [--severity] [--rule]` | Alertes, une ligne par site et par règle |
| `wp multisite-radar plugins list [--unused]`, `themes list [--unused]` | Inventaire |
| `wp multisite-radar export --resource=sites\|plugins\|themes [--format=csv\|json] [--output=<fichier>]` | Export, avec les filtres de la route REST |
| `wp multisite-radar settings get [<clé>]`, `settings set <clé> <valeur>` | Réglages (clé en chemin pointé, valeur JSON) |

Abilities (Abilities API de WordPress, lecture seule, droit `msradar_view`) : `multisite-radar/network-summary`, `list-sites`, `get-site`, `find-extension-usage`, `list-alerts`. Elles sont exposées en REST (`/wp-abilities/v1/abilities/<nom>/run`, en GET) et, si le réglage « Intégrations » l'autorise, aux clients MCP de l'extension MCP Adapter.

- Spec : `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`
- Plans : `docs/superpowers/plans/`

## Développement

Le `Makefile` regroupe les commandes ci-dessous (`make` seul affiche l'aide) :

```bash
make install          # dépendances npm et Composer
make i18n             # catalogue .pot, vérification et compilation des traductions (WP-CLI)
make dist             # dist/multisite-radar-<version>.zip, filtré par .distignore
make check            # lint + tests PHPUnit et Vitest
make e2e WP_ENV_PORT=8890   # bout en bout ; make e2e-stop pour arrêter wp-env
make version VERSION=2.0.0  # en-tête, constante, readme.txt, package.json et lock
```

Le zip de `make dist` contient le même arbre que celui validé par Plugin Check en CI. `npm run plugin-zip` appelle `make dist`.

Traductions : les textes source sont en anglais ; `languages/multisite-radar-fr_FR.po` fournit le français. `make i18n` régénère `languages/multisite-radar.pot` à partir du PHP et du JS compilé, y compris les textes de DataViews, fusionne les `.po`, puis compile les fichiers chargés par WordPress (`.mo`, `.l10n.php`, `.json`, non versionnés). Il échoue tant qu'une chaîne n'est pas traduite : la compléter dans le `.po` et relancer. `make dist` passe par la même étape.

Déploiement FTP (requiert `lftp`) : copier `.env.example` en `.env`, puis renseigner les accès de test et de production.

```bash
make deploy-test DRY_RUN=1  # simulation : liste ce qui serait envoyé ou supprimé
make deploy-test            # envoie le plugin sur le serveur de test
make deploy-prod            # idem en production, après avoir tapé « prod »
```

Le plugin est envoyé dans un dossier caché (`.multisite-radar-new`), puis échangé par renommage avec la version en place : le site ne voit jamais un plugin à moitié copié. Si l'envoi échoue, la version en place reste intacte.

```bash
composer install
bin/test-db.sh        # une fois : crée la base wordpress_test
bin/test.sh           # tests PHPUnit (multisite)
composer lint         # WPCS
composer analyse      # PHPStan niveau 6
```

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

Le port par défaut est 8888 ; s'il est occupé, le surcharger avec `WP_ENV_PORT`, `WP_ENV_TESTS_PORT` et `WP_BASE_URL` :

```bash
WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 npm run wp-env -- start
WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 npm run e2e:setup
WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 npm run test:e2e
```
