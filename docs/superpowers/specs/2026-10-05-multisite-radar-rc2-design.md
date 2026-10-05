# Multisite Radar 2.0.0-rc.2 — Spécification de conception

- **Date** : 2026-10-05
- **Statut** : validée en conversation, en attente de relecture écrite
- **Part de** : la recette de la 2.0.0-rc.1 en production (2026-10-05)
- **Spec de référence** : `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md` (§ 6 Interface d'administration, § 9 Sécurité et confidentialité)
- **Branche** : `rc2`

---

## 1. Contexte et objectifs

### 1.1 Point de départ

La 2.0.0-rc.1 a été déployée en production le 2026-10-05 (mise à niveau depuis la beta.2, la 1.x déjà retirée), puis recettée sur 41 contrôles : 26 OK, 3 KO, 12 sans objet.

Les 3 KO ne sont pas des défauts :

| Contrôle | Constat | Suite |
|---|---|---|
| Export des extensions et thèmes | Le bouton existe, mais c'est une icône seule (flèche de téléchargement) ; Comptes n'a pas d'export, par conception. | Bouton libellé (§ 3.6) ; export des comptes en 2.1. |
| Encadrés de la vue d'ensemble | « Pas encore assez d'historique » : il faut deux relevés quotidiens. | À revoir à J+2. |
| Texte de confidentialité | Cherché dans une page générée ; WordPress le place dans le guide (Réglages > Confidentialité). | À revoir dans le guide. |

Les remarques d'interface notées pendant la recette sont des améliorations. L'utilisateur a décidé de toutes les intégrer avant la 2.0.0, dans une **2.0.0-rc.2**.

### 1.2 Décisions prises

| Sujet | Décision |
|---|---|
| Périmètre | Toutes les remarques de la recette dans la rc.2 ; l'export des comptes en 2.1. |
| Clic sur la ligne | Ouvre le détail sur tous les écrans. |
| Comptes, clic | Ouvre un nouveau panneau du compte. |
| Comptes, colonnes | E-mail et nom public visibles par défaut ; prénom et nom, rôles, contenus publiés disponibles. |
| Actions groupées | En haut seulement. |
| Couleurs d'état | Vert / orange / rouge, bleu pour les mises à jour. |
| Filtres toujours visibles | État (Extensions, Thèmes), Gravité (Alertes), Niveau d'alerte (Sites). |
| Widget | Tuiles chiffrées et alertes principales avec pastille de gravité. |

### 1.3 Critères de succès

1. Sur chaque écran, un clic n'importe où sur une ligne du tableau ouvre le détail, sans casser la sélection, les liens ni le clavier.
2. Aucune colonne n'est masquée par la colonne d'actions, sur un écran de 1440 px avec toutes les colonnes de Sites affichées.
3. Les nouvelles colonnes de Comptes s'affichent, l'e-mail seulement pour qui a le droit de gérer les utilisateurs du réseau.
4. Suites PHPUnit, Vitest et Playwright vertes, Plugin Check strict sans erreur ni avertissement, CI verte.
5. Recette rc.2 en production sans KO sur les points changés.

---

## 2. Tableaux : comportement commun

Tous les écrans passent par le composant partagé `src/components/data-views/`. Les changements de cette section y sont faits une fois et valent pour les cinq écrans.

### 2.1 Clic sur la ligne

- Dans la mise en page « tableau », un clic n'importe où sur une ligne ouvre le détail de l'élément (même effet que `onClickItem`).
- Le clic n'ouvre rien quand il vise une case à cocher, un lien, un bouton, un champ ou le menu « ⋮ », ou quand l'utilisateur vient de sélectionner du texte dans la ligne.
- Ctrl/Cmd+clic et Maj+clic gardent leur rôle de DataViews : sélection d'une ligne, sélection d'une plage.
- Le nom de l'élément reste le bouton focalisable qui ouvre le détail : l'accès au clavier ne change pas.
- Le pointeur de la souris indique que la ligne est cliquable.
- La mise en page « grille » de Sites ne change pas.

Ce que le clic ouvre :

| Écran | Détail |
|---|---|
| Sites | Panneau du site (comme le clic sur le nom aujourd'hui). |
| Extensions, Thèmes | Panneau des sites qui utilisent l'élément. |
| Alertes | Panneau du site concerné, **sur l'écran Alertes** (paramètre `?site=` dans l'URL de l'écran). Aujourd'hui, l'action quitte l'écran pour Sites. |
| Comptes | Panneau du compte (§ 4.3). |

### 2.2 Colonne d'actions

L'action principale (« Voir les détails », « Voir les sites », « Voir le site », « Modifier le compte ») n'est plus une action principale : elle passe dans le menu « ⋮ ». La colonne d'actions ne contient plus que ce menu, toujours visible, et ne masque plus les dernières colonnes.

### 2.3 Actions groupées en haut

Quand au moins une ligne est cochée, la barre des actions groupées s'affiche **au-dessus** du tableau, sous la barre de recherche et de filtres. En bas ne reste que la pagination. Le composant utilise l'assemblage libre de DataViews (`children` avec ses sous-composants, dont `BulkActionToolbar`), l'API officielle de la version épinglée 19.1.0.

Aujourd'hui seul Sites a des actions groupées (Exporter en CSV, Réanalyser).

### 2.4 Filtres toujours visibles

Ces filtres s'affichent toujours, sous forme de pastille, sans passer par l'icône d'entonnoir (`filterBy.isPrimary`) :

| Écran | Filtre |
|---|---|
| Extensions, Thèmes | État |
| Alertes | Gravité |
| Sites | Niveau d'alerte |

---

## 3. Extensions, Thèmes et finitions

### 3.1 Tri

En plus du nom et du nombre de sites, les listes se trient côté serveur par :

| Colonne | Ordre croissant |
|---|---|
| État | Extensions : Activée sur le réseau, Active sur certains sites, Inutilisée, Non installée. Thèmes : Utilisé, Inutilisé, Non installé |
| Version | `version_compare` |
| Mise à jour | Sans mise à jour d'abord ; en décroissant, celles qui ont une mise à jour d'abord |

`InventoryList::ORDERBY`, le schéma de la route REST et `src/views/inventory/query.js` reçoivent ces valeurs.

### 3.2 Couleurs d'état

| État | Couleur |
|---|---|
| Activée sur le réseau, Active sur certains sites ; thème Utilisé | Vert (nouvelle variante `msradar-badge--success`) |
| Inutilisée, thème Inutilisé | Orange (`--warning`) |
| Non installée, thème Non installé | Rouge (`--error`) |
| Mise à jour disponible | Bleu (`--info`) |

Les couleurs respectent le contraste AA du texte sur leur fond, en clair comme dans l'admin WordPress.

### 3.3 Export libellé

Le bouton du menu d'export montre l'icône **et** le texte « Exporter ». Écrans concernés : Sites, Extensions, Thèmes.

### 3.4 Badge de version

Pour une version préliminaire (version contenant « - »), le badge affiche en clair « 2.0.0-rc.2 · version préliminaire ». Une version stable n'affiche que son numéro.

---

## 4. Comptes

### 4.1 Colonnes

| Colonne | Visible par défaut | Tri | Source |
|---|---|---|---|
| Identifiant (titre) | oui | oui (existant) | `wp_users.user_login` |
| Nom public | oui | oui (existant) | `wp_users.display_name` |
| E-mail | oui | oui | `wp_users.user_email`, lu en direct |
| Prénom et nom | non | non | `wp_usermeta` `first_name`, `last_name` |
| Rôles | non | non | `wp_usermeta` `{préfixe}{site}_capabilities` |
| Contenus publiés | non | oui | table `msradar_site_authors` (§ 4.4) |
| Super-admin, Sites, Inscription | comme aujourd'hui | comme aujourd'hui | existant |

- **Rôles** : résumé « Administrateur sur 3 sites, Éditeur sur 1 », rôles triés par nombre de sites décroissant. Le libellé est le nom traduit du rôle WordPress quand il est connu, sinon son identifiant.
- **Contenus publiés** : « — » tant qu'aucune analyse n'a relevé le site.
- **Recherche** : identifiant, nom public, e-mail, prénom et nom.
- **Tri** : `UsersQuery` accepte en plus `email` et `published`.

### 4.2 E-mail et droits

L'e-mail n'est renvoyé par l'API REST, affiché et cherché que si le compte connecté a le droit `manage_network_users`. Sinon la colonne n'est pas proposée et la recherche ignore l'e-mail. Un accès étendu par le filtre `msradar_capability_map` ne donne donc pas accès aux e-mails.

L'e-mail n'est ajouté ni à WP-CLI, ni aux abilities, ni à aucun export dans la rc.2.

### 4.3 Panneau du compte

Panneau latéral (composant `SidePanel`, comme celui des sites), ouvert par `?user=` dans l'URL de l'écran :

- identifiant, nom public, prénom et nom, e-mail (selon § 4.2), date d'inscription, super-admin ;
- la liste de ses sites : nom du site (lien vers son tableau de bord), son rôle, ses contenus publiés sur ce site ;
- un lien « Modifier le compte » vers la fiche WordPress.

Nouvelle route `GET /multisite-radar/v1/users/{id}` (droit `msradar_view`), qui renvoie ces données. Un identifiant inconnu renvoie 404.

### 4.4 Contenus publiés : relevé pendant l'analyse

Compter en direct ferait une requête par site pour chaque compte affiché. Le compte est donc relevé pendant l'analyse de chaque site :

- une requête par site, ajoutée à `SiteCollector` : `SELECT post_author, COUNT(*) … WHERE post_status = 'publish' … GROUP BY post_author`, avec la même définition des contenus que la colonne « Contenus » de Sites (types exclus par `msradar_excluded_post_types`, médias exclus) ;
- nouvelle table réseau `msradar_site_authors` (`site_id`, `user_id`, `published`, clé primaire `site_id, user_id`), remplacée à chaque analyse du site, vidée pour un site supprimé ;
- schéma en version 5 (`Schema::VERSION`), créé par `Installer::maybe_upgrade()` sans réactivation ;
- `uninstall.php` supprime la table.

### 4.5 Confidentialité

Le texte de `Privacy::text()` et la section Privacy du `readme.txt` mentionnent que le plugin garde, pour chaque site, le nombre de contenus publiés par auteur, et que l'e-mail des comptes est affiché aux super-admins sans être copié.

---

## 5. Widget du tableau de bord réseau

- Cinq tuiles chiffrées, en grille : Sites, Sites en erreur, Sites en avertissement, Extensions inutilisées, Mises à jour en attente.
- Chaque tuile est un lien vers l'écran filtré : Sites ; Sites filtrés sur le niveau d'alerte erreur, puis avertissement ; Extensions filtrées sur Inutilisée ; Extensions filtrées sur « mise à jour disponible », ou Thèmes si seules des mises à jour de thèmes sont en attente.
- Alertes principales : pastille de gravité (mêmes couleurs que l'écran Alertes), nom du site (lien vers son panneau), message.
- Lien « Ouvrir Multisite Radar » en bas, aligné à droite.
- Une petite feuille de style, chargée seulement sur le tableau de bord du réseau.
- Rendu PHP côté serveur, comme aujourd'hui.

---

## 6. Qualité et livraison

### 6.1 Tests

| Suite | Couvre |
|---|---|
| PHPUnit | Tri des extensions et thèmes (état, version, mise à jour) ; comptes : e-mail selon le droit, recherche, rôles, contenus publiés, route `users/{id}` (200, 403, 404) ; relevé des auteurs par `SiteCollector` ; mise à niveau du schéma 4 → 5 ; désinstallation ; widget (tuiles, liens, pastilles). |
| Vitest | Clic sur la ligne du composant partagé (cas ignorés du § 2.1) ; correspondance état → couleur ; libellé du menu d'export ; badge. |
| Playwright | Clic sur la ligne sur les cinq écrans ; barre d'actions groupées en haut ; panneau du compte ; aucune colonne masquée à 1440 px ; accessibilité (axe) toujours sans violation. |

### 6.2 Livraison

1. Branche `rc2`, plan dans `docs/superpowers/plans/`.
2. `make version VERSION=2.0.0-rc.2`, entrée `= 2.0.0-rc.2 =` du journal du `readme.txt`, catalogue et traduction française à jour.
3. CI verte, puis fusion dans `main`, push et tag `v2.0.0-rc.2`, chacun sur l'accord de l'utilisateur.
4. Déploiement en production par l'utilisateur, ou par `make deploy-prod` sur son accord.
5. Recette rc.2 : les points changés, plus les contrôles restés ouverts (journal d'erreurs PHP, e-mail de test, tendances à J+2, texte dans le guide de confidentialité).
6. Puis la section « Après la recette » du plan M7 : 2.0.0, captures, soumission.

### 6.3 Hors périmètre

- Export des comptes (2.1).
- Changements de la mise en page « grille ».
- Nouvelles captures d'écran (faites à la 2.0.0).
