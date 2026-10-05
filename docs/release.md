# Publier une version de Multisite Radar

Procédure de publication sur WordPress.org (slug `multisite-radar`, compte principal `adjuvans`, contributeur `cyrilledegourcy`).

## Avant chaque version

1. Tests et contrôles, wp-env démarré (`export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` si le port 8888 est pris) :

   ```bash
   make check            # lint (dont le readme) + PHPUnit + Vitest
   make e2e              # Playwright
   make plugin-check     # zéro erreur, zéro avertissement
   ```

   Et `bin/e2e.sh` (WP-CLI de bout en bout ; variables `E2E_DB_*`).
2. Banc de performance : `make bench` (1 000 sites) ; avant une version majeure, aussi `BENCH_SITES=5000`. Ajouter les résultats à `docs/benchmarks.md`.
3. « Tested up to » : la dernière version majeure publiée de WordPress (`https://api.wordpress.org/core/version-check/1.7/`). La CI (Plugin Check en mode strict) échoue quand une nouvelle majeure sort et que le readme n'a pas suivi.
4. Traductions : `make i18n`, puis traduire les nouvelles chaînes dans `languages/multisite-radar-fr_FR.po` et relancer `make i18n`.
5. Captures, à chaque version : `npm run screenshots:seed && npm run screenshots`. La capture de l'écran des extensions affiche la version du plugin dans sa ligne, elle change donc à chaque version, même sans retouche de l'interface. Si l'identité visuelle a changé (icône, bannière) : `npm run wporg:assets`.
6. Version : `make version VERSION=x.y.z`. Puis `CHANGELOG.md` (en français), et dans `readme.txt` l'entrée du journal et, si la mise à jour demande une action, l'« Upgrade Notice ».
7. Commit, tag annoté `vx.y.z`, push de `main` puis du tag. Attendre la CI verte.
8. Paquet : `make dist` produit `dist/multisite-radar-x.y.z.zip`.

## Première soumission (une seule fois)

1. Connecté à WordPress.org avec le compte `adjuvans`, envoyer `dist/multisite-radar-2.0.0.zip` sur <https://wordpress.org/plugins/developers/add/>. Vérifier que le slug proposé est bien `multisite-radar`.
2. L'équipe de revue répond par e-mail (plugins@wordpress.org), en général sous quelques semaines. Corriger ce qu'elle demande, puis renvoyer un zip depuis la même page.
3. Une fois le plugin accepté :
   - le dépôt SVN `https://plugins.svn.wordpress.org/multisite-radar/` est ouvert au compte `adjuvans` ;
   - ajouter `cyrilledegourcy` comme committer (page du plugin, onglet « Advanced », section « Committers »).

## Publier une version dans le SVN

1. Une fois : dans GitHub, Settings > Secrets and variables > Actions, créer `SVN_USERNAME` (`adjuvans`) et `SVN_PASSWORD`.
   - Le mot de passe est celui du SVN, à créer dans le profil WordPress.org, onglet « Account & Security ».
   - L'environnement `wordpress.org`, créé au premier lancement, peut exiger une approbation (Settings > Environments).
2. Actions > « Deploy to WordPress.org » > Run workflow : le tag `vx.y.z`.
3. Lancer d'abord avec « Simulate only » coché, et lire le journal du job.
4. Relancer avec la case décochée.
5. Vérifier <https://wordpress.org/plugins/multisite-radar/> : version, bannière, icône, captures. La page peut mettre quelques minutes à se mettre à jour.

Le workflow construit le paquet comme `make dist` (build, traductions compilées par `bin/i18n.sh`, contrôle du contenu) : le SVN reçoit les mêmes fichiers, `.mo` et `.json` compris, que le zip soumis.

Le workflow refuse une pré-version (`v2.0.0-rc.1`) et un tag qui n'est pas la version du plugin.

## Traductions

Les traductions de WordPress.org se font sur <https://translate.wordpress.org/projects/wp-plugins/multisite-radar/>. Le fichier `languages/multisite-radar-fr_FR.po` peut y être importé par un éditeur de traduction (PTE) du projet. Tant qu'aucun paquet de langue n'existe, WordPress charge les traductions livrées dans `languages/`.

## Production

Déploiement FTP : `make deploy-prod` (paramètres dans `.env`). La 1.x installée en MU-plugin se retire à la main, après l'activation réseau de Multisite Radar.
