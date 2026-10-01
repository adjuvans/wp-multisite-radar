# Changelog - Network Plugin Utilities

## [2.0.0] - en cours

Réécriture complète sous le nom **Multisite Radar** (slug `multisite-radar`). Voir la spec dans `docs/superpowers/specs/`.

### Jalon M1 — Fondations
- Collecte hybride : SQL agrégé sur les tables de chaque site + relevé des types enregistrés dans le contexte du site (libellés et origines exacts).
- Tables réseau `msradar_sites` et `msradar_site_extensions`, file d'analyse WP-Cron par lots avec verrou.
- Moteur d'alertes réglable (règles `no_users`, `inactive`, `high_media`).
- Multi-réseau : chaque réseau analyse ses propres sites et recalcule leurs alertes avec ses propres réglages (cron, `POST /scan/batch`, WP-CLI) ; le verrou d'analyse reste commun.
- API REST `multisite-radar/v1` (sites, analyse, réglages, synthèse des alertes).
- Commandes `wp multisite-radar scan|probe|sites list`.
- Migration automatique des réglages et des éléments de menu de la 1.x.
- Outillage : PHPUnit multisite, WPCS, PHPStan niveau 6, CI GitHub Actions, Plugin Check.

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
