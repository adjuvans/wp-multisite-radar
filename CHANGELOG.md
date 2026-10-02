# Changelog — Multisite Radar

## [2.0.0-beta.3] - 2026-10-02

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

## [2.0.0-beta.2] - 2026-10-02

- Interface en français : traduction fr_FR livrée dans `languages/`, y compris les textes de DataViews (filtres, pagination, colonnes), repris de la traduction officielle de WordPress quand elle existe.
- Pastille de version à côté du titre de chaque page, orange pour une version préliminaire.
- Pied de page des pages du plugin : signature ADJUVANS (adjuvans.fr, contact@adjuvans.fr), licence GPL-3.0 ou ultérieure, licences tierces (`build/third-party-licenses.txt`, généré au build) et version du plugin.
- Auteur du plugin : ADJUVANS.
- Outillage : `Makefile` (`make dist`, `make i18n`, `make check`, `make e2e`, `make version`), déploiement FTP vers les serveurs de test et de production (`make deploy-test`, `make deploy-prod`, paramètres dans `.env`).

## [2.0.0-beta.1] - 2026-10-01

### Jalon M2 — Interface
- Interface d'administration réseau en React : Vue d'ensemble (tuiles, « À traiter », analyse pilotée par l'interface), Sites (DataViews, filtres, tri, recherche, état dans l'URL, fiche latérale avec lien profond), Alertes (site × règle, regroupées par règle), Réglages (DataForm).
- Première vue sans indicateur de chargement : données préchargées par PHP et cache `msradar/core` hydraté au démarrage ; un point d'entrée et une feuille de style par vue.
- Préférences d'affichage par utilisateur (`GET/POST /preferences`), exports CSV (UTF-8 avec BOM, formules neutralisées) et JSON en flux ; en cas d'échec avant la première sortie l'export renvoie une vraie erreur 500, et il s'arrête proprement en cours de flux.
- REST : `GET /alerts`, `GET /sites/{id}/users` (sans e-mail), sélection `include`, erreurs 500 sur lecture en échec, filtre `inactive_since` aligné sur la règle « inactive ».
- Réglages : un slug d'extension obsolète ne bloque plus l'enregistrement.
- Module « menu des sites » : cache par réseau, shortcode `[msradar_sites]`, metabox des menus, bloc `multisite-radar/sites-list`, alias 1.x pour les installations migrées. La metabox « Network sites » est masquée par défaut pour les utilisateurs qui n'ont jamais enregistré les Options de l'écran des menus (comportement de WordPress) : l'activer dans Options de l'écran.
- Socle : un seul budget de travail par requête cron, curseur de recalcul lié aux réglages d'alertes, schéma v2 (colonne `siteurl`, listes sans la colonne `data`), action `msradar_error` pour les erreurs de stockage attrapées dans les hooks.
- Outillage : `@wordpress/scripts` 36, Vitest, Playwright sur wp-env multisite avec audit axe, synchronisation des versions (`npm run version:check`).

---

## [2.0.0-alpha.1] - 2026-10-01

Réécriture complète sous le nom **Multisite Radar** (slug `multisite-radar`). Voir la spec dans `docs/superpowers/specs/`.

### Jalon M1 — Fondations
- Collecte hybride : SQL agrégé sur les tables de chaque site + relevé des types enregistrés dans le contexte du site (libellés et origines exacts).
- Tables réseau `msradar_sites` et `msradar_site_extensions`, file d'analyse WP-Cron par lots avec verrou.
- Moteur d'alertes réglable (règles `no_users`, `inactive`, `high_media`).
- Multi-réseau : chaque réseau analyse ses propres sites et recalcule leurs alertes avec ses propres réglages (cron, `POST /scan/batch`, WP-CLI) ; le verrou d'analyse reste commun.
- API REST `multisite-radar/v1` (sites, analyse, réglages, synthèse des alertes).
- Commandes `wp multisite-radar scan|probe|sites list`. `scan` exige exactement une option parmi `--all`, `--dirty` et `--site=<id>` ; `--site` n'analyse (et, avec `--probe`, ne relève) que ce site, sans vider le reste de la file.
- Migration automatique des réglages et des éléments de menu de la 1.x.
- Outillage : PHPUnit multisite, WPCS, PHPStan niveau 6, CI GitHub Actions, Plugin Check, test d'acceptation E2E sur un multisite neuf (`bin/e2e.sh`).

---

## [1.6.0] - 2025-12-10

### 🐛 Correction critique
- **Erreur fatale PHP** : Correction des apostrophes non échappées dans les chaînes de traduction i18n
  - Remplacement des guillemets simples par des guillemets doubles dans toutes les fonctions `__()`
  - Affectait : NPU_Network_Overview.php et NPU_Core.php

### 🚀 Nouvelles fonctionnalités
- **Système de cache intelligent** : Les données des sites sont maintenant mises en cache (durée configurable)
- **Bouton de rafraîchissement** : Permet de rafraîchir manuellement le cache avec rate limiting
- **Export de données** :
  - Export CSV : Toutes les données des sites dans un format Excel/Google Sheets
  - Export JSON : Format structuré pour intégration avec d'autres outils
  - Boutons directement dans l'interface d'administration
- **Système d'alertes intelligent** :
  - Détection automatique des sites nécessitant de l'attention
  - Sites sans utilisateurs (orphelins)
  - Sites inactifs depuis X mois (configurable)
  - Sites avec quota de médias élevé (configurable)
  - Panneau de résumé en haut de page
  - Badges colorés sur chaque site avec détails au survol
  - 3 niveaux de sévérité : erreur (rouge), avertissement (orange), info (bleu)
- **Colonnes personnalisables** : Options de l'écran permettant d'afficher/masquer les colonnes
  - Personnalisation du nombre de sites par page
  - Préférences sauvegardées par utilisateur
  - CPT personnalisés et taxonomies personnalisées masqués par défaut
- **Configuration centralisée** : Nouveau fichier `config.php` pour gérer tous les paramètres
- **Gestion d'erreurs améliorée** : Try/catch autour des opérations critiques avec logging

### ⚡ Améliorations de performance
- **Optimisation de la pagination** : Ne charge que les sites de la page courante au lieu de tous les sites
- **Réduction drastique des requêtes SQL** : Sur un réseau de 50 sites, passage de ~500 requêtes à ~20 requêtes par page
- **Temps de chargement** : Amélioration de 80-90% sur les gros réseaux (après le premier chargement)

### 🔧 Refactoring du code
- Refactoring complet de `NPU_Network_Overview::prepare_items()` :
  - Extraction de `format_site_data()`
  - Extraction de `format_post_types()`
  - Extraction de `format_taxonomies()`
- Nouvelle classe `NPU_Cache` pour gérer tout le système de cache
- Meilleure séparation des responsabilités

### 🐛 Corrections de bugs
- **CSS jamais chargé** : Correction du hook `admin_enqueue_scripts` (mauvais slug de page)
- **Double initialisation** : Suppression de l'appel en double de `NPU_Network_Sites_Menu::init()`
- **Text domain incohérent** : Uniformisation à `npu-core`
- **Code mort** : Suppression de la méthode `render_stat_page()` non utilisée
- **Avertissement PHP** : Vérification de l'existence de la propriété `publish` avant accès

### 📦 Nouveaux fichiers
- `network-plugin-utilities/config.php` - Configuration centralisée
- `network-plugin-utilities/src/NPU_Cache.php` - Classe de gestion du cache
- `network-plugin-utilities/src/NPU_Export.php` - Classe de gestion des exports (CSV/JSON)
- `network-plugin-utilities/src/NPU_Alerts.php` - Système de détection d'alertes
- `network-plugin-utilities/CHANGELOG.md` - Historique des modifications

### 🔄 Hooks automatiques ajoutés
Le cache est automatiquement invalidé lors de :
- Création d'un nouveau site (`wpmu_new_blog`)
- Archivage/désarchivage d'un site (`archive_blog`, `unarchive_blog`)
- Suppression d'un site (`delete_blog`)

---

## [1.5.1] - 2025-12-10

### 🐛 Corrections de bugs
- Correction du hook CSS qui empêchait le chargement des styles
- Correction du text domain incohérent
- Suppression de la double initialisation de NPU_Network_Sites_Menu

---

## [1.5.0] - Versions antérieures

Version initiale stable avec :
- Vue d'ensemble des sites du réseau
- Tracking des CPT et taxonomies personnalisés
- Intégration dans les menus WordPress
- Affichage des plugins locaux, utilisateurs, statistiques
