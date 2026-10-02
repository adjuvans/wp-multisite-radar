# Multisite Radar

Audit réseau pour WordPress Multisite (successeur de Network Plugin Utilities 1.x).

- Spec : `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`
- Plans : `docs/superpowers/plans/`

## Développement

Le `Makefile` regroupe les commandes ci-dessous (`make` seul affiche l'aide) :

```bash
make install          # dépendances npm et Composer
make dist             # dist/multisite-radar-<version>.zip, filtré par .distignore
make check            # lint + tests PHPUnit et Vitest
make e2e WP_ENV_PORT=8890   # bout en bout ; make e2e-stop pour arrêter wp-env
make version VERSION=2.0.0  # en-tête, constante, readme.txt, package.json et lock
```

Le zip de `make dist` contient le même arbre que celui validé par Plugin Check en CI. Ce n'est pas le cas de `npm run plugin-zip`, qui ajoute `package.json` et `README.md`.

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
