# Multisite Radar 2.0.0-rc.2 — remarques de la recette : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer la **2.0.0-rc.2**, qui intègre les remarques de la recette de la rc.1 en production :
- un clic n'importe où sur une ligne ouvre le détail, sur les cinq écrans en tableau ;
- l'action de détail passe dans le menu « ⋮ », les actions groupées s'affichent au-dessus du tableau ;
- Extensions et Thèmes : tri par état, version et mise à jour, couleurs d'état, filtre d'état toujours visible ;
- Comptes : e-mail, prénom et nom, rôles, contenus publiés, panneau du compte ;
- widget du tableau de bord en tuiles cliquables ;
- export libellé, badge de version préliminaire en clair.

**Architecture :**
- **Interface.** Le point d'accès `src/components/data-views/` devient un composant du plugin (`radar-data-views.jsx`) qui assemble DataViews 19.1.0 par son API d'assemblage libre : barre d'actions groupées en haut, clic sur la ligne par délégation. Les cinq écrans gardent leur import `DataViews` et en profitent sans autre changement.
- **Données.** Les contenus publiés par auteur sont relevés pendant l'analyse de chaque site (une requête), rangés dans une nouvelle table réseau `msradar_site_authors` (schéma 5). Les comptes sont enrichis dans `UsersRepository` et `UsersQuery` ; une route `GET /users/{id}` alimente le panneau du compte.
- **Widget.** Rendu PHP comme aujourd'hui, avec une petite feuille de style en ligne chargée seulement sur le tableau de bord du réseau.

**Tech stack :** inchangée depuis M7.
- PHP 7.4 à 8.4, WordPress ≥ 6.9 multisite, PHPUnit 9.6 + wp-phpunit, WPCS 3, PHPStan 2 niveau 6.
- Node 24, `@wordpress/scripts` 36.0.0 (Vitest), React 18.3, `@wordpress/dataviews` 19.1.0 (épinglé).
- Playwright 1.63 + `@wordpress/e2e-test-utils-playwright` + `@axe-core/playwright` sur `@wordpress/env` 11.16 (multisite).

**Spec :** `docs/superpowers/specs/2026-10-05-multisite-radar-rc2-design.md`. Pour le reste, la spec v2 (`docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`) reste la référence, en particulier § 6 (interface) et § 9 (sécurité et confidentialité).

**Branche :** `rc2`, déjà créée depuis `main` (fc04834) ; elle porte la spec (b9fbeba).

## Global Constraints

- **PHP ≥ 7.4.** Interdits :
  - `enum`, `readonly`, `match`, types union, promotion de propriétés, type `mixed`, `str_contains` ;
  - arguments nommés, opérateur nullsafe, ternaire court `?:`, déstructuration courte `[ $a, $b ] = …`.

  Autorisés : propriétés typées, fonctions fléchées `fn`, `??=`.
- **WordPress ≥ 6.9, multisite obligatoire.** L'interface n'existe que dans l'administration réseau.
- **Noms :** text domain `multisite-radar`, namespace `MultisiteRadar\`, préfixe `msradar_` (options, hooks, user meta, tables, groupes de cache, handles) ; routes REST sous `multisite-radar/v1`.
- **Chaînes :**
  - en anglais ;
  - en PHP : `__()` / `esc_html__()` / `_n()` ; en JS : `__` / `_n` / `sprintf` de `@wordpress/i18n` ;
  - toujours avec le domaine `multisite-radar` et un commentaire `translators:` dès qu'il y a un placeholder ;
  - **traductions** : une tâche qui ajoute ou change une chaîne ne touche ni au `.pot` ni aux `.po` ; la tâche 10 régénère le catalogue (`make i18n`) et traduit tout.
- **SQL :**
  - `$wpdb->prepare()` partout, `%i` pour les identifiants ;
  - requêtes directes uniquement dans `includes/Storage/`, `includes/Install/`, `includes/Collector/`, `includes/Scan/Lock.php`, `includes/SitesMenu/SitesListCache.php` et `uninstall.php` ;
  - une lecture qui échoue lève `\RuntimeException` (`check_read()`).
- **Annotations phpcs :**
  - toujours avec un code précis et une justification après ` -- ` ;
  - **justification en anglais** pour toute nouvelle annotation (l'équipe de revue de WordPress.org la lit) ; les justifications existantes, en français, restent telles quelles ;
  - jamais `phpcs:ignoreFile` dans un fichier livré.
- **Erreurs :** le journal, les relevés, le récapitulatif et le widget ne font jamais échouer une analyse, une tâche cron ni le tableau de bord : `try`/`catch` + `do_action( 'msradar_error', <contexte>, $error )`.
- **Données personnelles :**
  - l'adresse e-mail d'un compte n'apparaît que dans `GET /users` et `GET /users/{id}`, et seulement pour un compte connecté qui a le droit `manage_network_users` (écart E1) ;
  - jamais d'e-mail de compte dans une ability, une commande WP-CLI, un export, un événement ou le widget ;
  - `GET /settings` reste réservé à `msradar_manage`.
- **Aucun appel HTTP externe** dans le plugin.
- **Garde d'accès direct :** `defined( 'ABSPATH' ) || exit;` dans les 50 premières lignes de chaque fichier PHP livré.
- **Style PHP :** WPCS ; un tableau associatif de plus d'un élément s'écrit sur plusieurs lignes ; tableaux courts autorisés ; commentaires en français.
- **JS :**
  - `.jsx` pour tout fichier qui contient du JSX, imports sans extension ;
  - feuilles de style importées seulement par les points d'entrée (`src/admin/<vue>.js`) ;
  - composants : uniquement des exports publics de `@wordpress/components` présents dans WordPress 6.9, jamais `__experimental*` ;
  - DataViews et DataForm importés seulement dans `src/components/data-views/` ;
  - jamais de `dangerouslySetInnerHTML`, pas d'emojis ;
  - couleurs d'accent via `var(--wp-admin-theme-color)`.
- **Versions :** `MSRADAR_VERSION` est la seule source de vérité ; `make version VERSION=x` ; `npm run version:check`.
- **Commits :**
  - messages conventionnels (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `refactor:`) ;
  - **jamais de ligne `Co-authored-by`**, même en premier essai : le hook du dépôt la refuse ;
  - jamais `--no-verify`, jamais de push.
- **Tests PHP :**
  - ne jamais lever d'exception dans `set_up()`/`tear_down()` après `parent::set_up()` ;
  - les tables d'un site créé par `self::factory()->blog->create()` sont temporaires ;
  - la base de test locale garde une ligne `site_id` 101 dans `wptests_msradar_sites`, qui fait échouer 11 tests connus (AlertsQueryTest summary, 7 QueueTest, 3 SitesRepositoryTest). Pour une suite propre, lancer `WP_PHPUNIT__TESTS_CONFIG=<copie de tests/php/wp-tests-config.php avec $table_prefix = 'wpci_'> bin/test.sh`.
- **Environnement local :**
  - ne jamais lancer `wp plugin uninstall` ou `wp plugin delete` sur le WordPress de développement (`/home/dev/wp`) : le dossier du plugin est un lien symbolique vers le dépôt ;
  - aucune commande d'écriture sur `/home/dev/wp` ;
  - ne jamais afficher le mot de passe de la base ;
  - ne jamais déplacer ni modifier le dossier `.claude/` ;
  - wp-env avec `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890` (le port 8888 est pris) ; les tests E2E visent l'environnement de développement de wp-env (8890), Plugin Check l'environnement de test (8891) ;
  - jamais de `prettier --write` nu : `npx eslint --fix <fichiers>`.
- **Aucune action externe** : ni push, ni tag, ni déploiement FTP. Le contrôleur les propose à l'utilisateur après la tâche 10.

## Review Focus

Cas que la spec implique sans qu'un test existant les couvre. Chacun a son test dans la tâche indiquée.

1. **Texte sélectionné dans une ligne** (copier l'adresse d'un site, le nom d'un plugin) : le panneau ne s'ouvre pas. Test : tâche 1.
2. **Alertes triées par règle** (lignes d'en-tête de groupe) : un clic sur un en-tête de groupe n'ouvre rien ; un clic sur une ligne ouvre le bon site. Tests : tâche 1 (en-tête), tâche 9 (ligne).
3. **Compte qui voit Multisite Radar par le filtre de droits sans pouvoir gérer les utilisateurs** : aucun e-mail dans la liste ni dans le panneau, et une recherche sur un e-mail ne retrouve rien. Test : tâche 6.
4. **Mise à niveau du schéma 4 vers 5 sur un réseau qui a déjà des préférences** : la table des auteurs est créée sans réactivation, une analyse complète est demandée, et la colonne E-mail apparaît une fois chez qui avait choisi ses colonnes, sans revenir s'il la masque ensuite. Tests : tâches 5 et 6.
5. **Super-admin membre de centaines de sites** : le panneau du compte liste 200 sites au plus et dit combien il en reste. Tests : tâches 6 et 7.

## Écarts assumés

| N° | Référence | Écart | Raison |
|---|---|---|---|
| E1 | Spec v2 § 5.4 et § 9 ; contrainte « aucune adresse e-mail dans une réponse REST » du plan M7 | L'e-mail des comptes est renvoyé par `GET /users` et `GET /users/{id}`, seulement pour `manage_network_users`. | Décision de l'utilisateur pendant la recette de la rc.1 (2026-10-05) ; ce droit voit déjà les e-mails dans Réseau > Utilisateurs. |
| E2 | Spec rc.2 § 4.1, « — » tant qu'aucune analyse n'a relevé le site | Chaque analyse écrit une ligne témoin (`user_id` 0) pour le site. Liste : « — » tant qu'aucun site n'a été analysé depuis la mise à niveau. Panneau : « — » pour chaque site pas encore analysé. | Distinguer « aucun contenu » de « pas encore relevé », sans requête par site. |
| E3 | Spec rc.2 § 4.1, colonnes visibles par défaut | À la mise à niveau vers le schéma 5, « Nom public » et « E-mail » s'ajoutent une fois aux colonnes déjà enregistrées de Comptes. | Sinon, qui a déjà réglé ses colonnes (l'utilisateur de la recette) ne verrait pas l'e-mail. |
| E4 | Spec rc.2 § 5, « une petite feuille de style » | La feuille du widget est en ligne (`wp_add_inline_style`), sans point d'entrée webpack. | Une dizaine de règles, sur une seule page. |
| E5 | Spec rc.2 § 4.1, exemple « Éditeur sur 1 » | Le résumé des rôles écrit toujours le mot « site » : « Éditeur sur 1 site ». | Une seule chaîne au pluriel, traduisible correctement. |

## Ordre des tâches

| Tâche | Contenu | Dépend de |
|---|---|---|
| 1 | Composant de tableau partagé : clic sur la ligne, actions groupées en haut | — |
| 2 | Sites et Alertes : détail dans « ⋮ », panneau du site sur Alertes, filtres toujours visibles | 1 |
| 3 | Extensions et Thèmes : tri, couleurs, filtre d'état toujours visible | 1 |
| 4 | Export libellé, badge de version en clair | — |
| 5 | Contenus publiés par auteur : table, relevé, confidentialité | — |
| 6 | Comptes côté serveur : e-mail, noms, rôles, contenus, route `users/{id}`, préférences | 5 |
| 7 | Comptes côté interface : colonnes, panneau du compte | 1, 6 |
| 8 | Widget du tableau de bord | — |
| 9 | Tests de bout en bout | 1 à 8 |
| 10 | Version 2.0.0-rc.2 | 1 à 9 |

---

### Task 1: Composant de tableau partagé — clic sur la ligne, actions groupées en haut

Spec rc.2 § 2.1 et § 2.3.

**Files:**
- Create : `src/components/data-views/row-click.js`
- Create : `src/components/data-views/radar-data-views.jsx`
- Modify : `src/components/data-views/index.js`
- Modify : `src/admin/style.scss` (fin du fichier)
- Test : `src/components/data-views/test/row-click.test.js` (nouveau), `src/components/data-views/test/radar-data-views.test.jsx` (nouveau)

**Interfaces:**
- Produces :
  - `DataViews` exporté par `src/components/data-views/index.js` : mêmes props que le `DataViews` de `@wordpress/dataviews` (`data`, `fields`, `view`, `onChangeView`, `actions`, `defaultLayouts`, `paginationInfo`, `isLoading`, `getItemId`, `isItemClickable`, `onClickItem`, `search`, `searchLabel`, `header`, `empty`, et en option `selection` / `onChangeSelection`). En mise en page « tableau », un clic sur une ligne appelle `onClickItem( item )`.
  - `rowItemId( event ): ?string` et `ITEM_ATTRIBUTE = 'data-msradar-item'` dans `row-click.js`.
- Consumes : l'API d'assemblage libre de DataViews 19.1.0 : `DataViews.Search`, `.FiltersToggle`, `.FiltersToggled`, `.LayoutSwitcher`, `.ViewConfig`, `.BulkActionToolbar`, `.Layout`, `.Pagination` (`node_modules/@wordpress/dataviews/src/dataviews/index.tsx`, fin du fichier).

Fonctionnement :
- La colonne titre (`view.titleField`) est rendue dans un `<span data-msradar-item="<id>">` : la ligne porte ainsi l'identifiant de son élément, même quand DataViews regroupe les lignes (Alertes triées par règle).
- Un seul `onClick` entoure la zone du tableau (`display: contents`, sans effet sur la mise en page). Le titre garde son propre bouton DataViews, qui arrête la propagation : il n'ouvre jamais deux fois.
- La barre d'actions groupées n'est rendue qu'avec au moins une ligne cochée, au-dessus du tableau. En bas ne reste que la pagination.

- [ ] **Step 1: Write the failing tests of the click rules**

`src/components/data-views/test/row-click.test.js` :

```js
import { afterEach, expect, test, vi } from 'vitest';
import { ITEM_ATTRIBUTE, rowItemId } from '../row-click';

afterEach( () => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
} );

function setUp() {
	document.body.innerHTML = `
		<table><tbody>
			<tr class="dataviews-view-table__group-header-row"><td class="group">Inactive site</td></tr>
			<tr class="dataviews-view-table__row">
				<td><input type="checkbox" class="check" /></td>
				<td><span role="button" tabindex="0"><span ${ ITEM_ATTRIBUTE }="42"><span class="name">Blog RH</span></span></span></td>
				<td class="cell">12</td>
				<td><a href="#site" class="link">Visit</a><button type="button" class="menu">Actions</button></td>
			</tr>
		</tbody></table>
		<p class="outside">Outside</p>`;
}

function click( selector, extra = {} ) {
	return {
		target: document.querySelector( selector ),
		button: 0,
		ctrlKey: false,
		metaKey: false,
		shiftKey: false,
		altKey: false,
		defaultPrevented: false,
		...extra,
	};
}

test( 'a click on a plain cell names the item of its row', () => {
	setUp();
	expect( rowItemId( click( '.cell' ) ) ).toBe( '42' );
} );

test( 'interactive elements keep their own behaviour', () => {
	setUp();
	for ( const selector of [ '.check', '.name', '.link', '.menu' ] ) {
		expect( rowItemId( click( selector ) ) ).toBeNull();
	}
} );

test( 'selection keys, other buttons and handled events open nothing', () => {
	setUp();
	for ( const extra of [
		{ ctrlKey: true },
		{ metaKey: true },
		{ shiftKey: true },
		{ altKey: true },
		{ button: 1 },
		{ defaultPrevented: true },
	] ) {
		expect( rowItemId( click( '.cell', extra ) ) ).toBeNull();
	}
} );

test( 'a group header or a click outside the rows opens nothing', () => {
	setUp();
	expect( rowItemId( click( '.group' ) ) ).toBeNull();
	expect( rowItemId( click( '.outside' ) ) ).toBeNull();
} );

test( 'selecting text in a row opens nothing', () => {
	setUp();
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => 'Blog',
	} );
	expect( rowItemId( click( '.cell' ) ) ).toBeNull();
} );
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npx wp-scripts test-unit-js src/components/data-views`
Expected: FAIL, `row-click` introuvable.

- [ ] **Step 3: Write the click rules**

`src/components/data-views/row-click.js` :

```js
/**
 * Clic sur une ligne du tableau DataViews : le détail s'ouvre, sauf sur un élément interactif (case à cocher, lien,
 * bouton, menu, titre déjà cliquable), avec une touche de sélection (Ctrl, Cmd, Maj, Alt), avec un autre bouton que
 * le principal, ou quand l'utilisateur vient de sélectionner du texte.
 */
export const ITEM_ATTRIBUTE = 'data-msradar-item';

const INTERACTIVE =
	'a, button, input, select, textarea, label, summary, [role="button"], [role="checkbox"], [role="menuitem"], [role="link"], [contenteditable="true"]';

/**
 * Identifiant de l'élément dont la ligne a reçu le clic, ou null si le clic ne doit rien ouvrir.
 *
 * @param {Object} event Événement de clic (React ou DOM).
 * @return {?string} Valeur de l'attribut data-msradar-item de la ligne.
 */
export function rowItemId( event ) {
	if ( event.defaultPrevented || event.button !== 0 ) {
		return null;
	}
	if ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
		return null;
	}
	const target = event.target;
	if ( ! target || typeof target.closest !== 'function' ) {
		return null;
	}
	if ( target.closest( INTERACTIVE ) ) {
		return null;
	}
	const selection = target.ownerDocument?.defaultView?.getSelection?.();
	if (
		selection &&
		! selection.isCollapsed &&
		selection.toString().trim() !== ''
	) {
		return null;
	}
	const row = target.closest( 'tr.dataviews-view-table__row' );
	const marker = row ? row.querySelector( `[${ ITEM_ATTRIBUTE }]` ) : null;
	return marker ? marker.getAttribute( ITEM_ATTRIBUTE ) : null;
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `npx wp-scripts test-unit-js src/components/data-views/test/row-click.test.js`
Expected: PASS (5 tests).

- [ ] **Step 5: Write the failing tests of the component**

`src/components/data-views/test/radar-data-views.test.jsx` :

```jsx
import { afterEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { DataViews } from '..';

const DATA = [
	{ id: 1, name: 'Alpha', size: 3 },
	{ id: 2, name: 'Beta', size: 5 },
];
const FIELDS = [
	{ id: 'name', type: 'text', label: 'Name', enableHiding: false },
	{ id: 'size', type: 'integer', label: 'Size' },
];
const VIEW = {
	type: 'table',
	titleField: 'name',
	fields: [ 'size' ],
	page: 1,
	perPage: 20,
	layout: {},
};

afterEach( () => vi.restoreAllMocks() );

function renderViews( props = {} ) {
	return render(
		<DataViews
			data={ DATA }
			fields={ FIELDS }
			view={ VIEW }
			onChangeView={ () => {} }
			defaultLayouts={ { table: {} } }
			paginationInfo={ { totalItems: 2, totalPages: 1 } }
			getItemId={ ( item ) => String( item.id ) }
			{ ...props }
		/>
	);
}

test( 'a click anywhere in a row opens its item, once', () => {
	const onClickItem = vi.fn();
	renderViews( { onClickItem } );

	fireEvent.click( screen.getByText( '5' ) );
	expect( onClickItem ).toHaveBeenCalledTimes( 1 );
	expect( onClickItem ).toHaveBeenCalledWith( DATA[ 1 ] );

	fireEvent.click( screen.getByText( 'Alpha' ) );
	expect( onClickItem ).toHaveBeenCalledTimes( 2 );
	expect( onClickItem ).toHaveBeenLastCalledWith( DATA[ 0 ] );
} );

test( 'a Ctrl-click, a text selection or an item that is not clickable opens nothing', () => {
	const onClickItem = vi.fn();
	const { unmount } = renderViews( { onClickItem } );
	fireEvent.click( screen.getByText( '5' ), { ctrlKey: true } );
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => 'Bet',
	} );
	fireEvent.click( screen.getByText( '5' ) );
	vi.restoreAllMocks();
	unmount();

	renderViews( { onClickItem, isItemClickable: () => false } );
	fireEvent.click( screen.getByText( '5' ) );

	expect( onClickItem ).not.toHaveBeenCalled();
} );

test( 'rows are marked clickable only with onClickItem', () => {
	const { container, unmount } = renderViews();
	expect( container.querySelector( '.msradar-dataviews__layout' ) ).not.toHaveClass(
		'is-clickable'
	);
	unmount();

	const second = renderViews( { onClickItem: vi.fn() } );
	expect(
		second.container.querySelector( '.msradar-dataviews__layout' )
	).toHaveClass( 'is-clickable' );
} );

test( 'the bulk actions bar appears above the table once a row is checked', () => {
	const onClickItem = vi.fn();
	const { container } = renderViews( {
		onClickItem,
		actions: [
			{ id: 'go', label: 'Go', supportsBulk: true, callback: vi.fn() },
		],
	} );
	expect(
		container.querySelector( '.dataviews-bulk-actions-footer__container' )
	).toBeNull();

	fireEvent.click(
		container.querySelectorAll( 'tbody input[type="checkbox"]' )[ 0 ]
	);

	const bars = container.querySelectorAll(
		'.dataviews-bulk-actions-footer__container'
	);
	expect( bars ).toHaveLength( 1 );
	expect( bars[ 0 ].closest( '.msradar-dataviews__bulk' ) ).not.toBeNull();
	const table = container.querySelector( 'table' );
	expect(
		// eslint-disable-next-line no-bitwise -- compareDocumentPosition returns a bit mask.
		bars[ 0 ].compareDocumentPosition( table ) &
			Node.DOCUMENT_POSITION_FOLLOWING
	).toBeTruthy();
	expect( onClickItem ).not.toHaveBeenCalled();
} );

test( 'the search, the header and the pagination stay in place', () => {
	const { container, unmount } = renderViews( {
		searchLabel: 'Search things',
		header: <button type="button">Export</button>,
	} );
	expect(
		screen.getByRole( 'searchbox', { name: 'Search things' } )
	).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Export' } ) ).toBeInTheDocument();
	expect( container.querySelector( '.msradar-dataviews__footer' ) ).toBeNull();
	unmount();

	const paged = renderViews( {
		paginationInfo: { totalItems: 40, totalPages: 2 },
	} );
	expect(
		paged.container.querySelector( '.msradar-dataviews__footer' )
	).not.toBeNull();
} );
```

- [ ] **Step 6: Run them to verify they fail**

Run: `npx wp-scripts test-unit-js src/components/data-views/test/radar-data-views.test.jsx`
Expected: FAIL : le `DataViews` exporté est encore celui du paquet (pas de `.msradar-dataviews__layout`, pas de clic sur la ligne).

- [ ] **Step 7: Write the component**

`src/components/data-views/radar-data-views.jsx` :

```jsx
import { useMemo, useState } from '@wordpress/element';
import { DataViews as PackageDataViews } from '@wordpress/dataviews/wp';
import { ITEM_ATTRIBUTE, rowItemId } from './row-click';

const defaultGetItemId = ( item ) => item.id;
const alwaysClickable = () => true;

/**
 * Valeur affichée d'un champ sans rendu propre.
 *
 * @param {Object} field Champ DataViews.
 * @param {Object} item  Élément.
 */
function plainValue( field, item ) {
	const value = field.getValue ? field.getValue( { item } ) : item[ field.id ];
	return value === null || value === undefined ? '' : String( value );
}

/**
 * DataViews du plugin (spec rc.2 § 2) : l'assemblage libre de DataViews 19.1.0, avec
 * - la barre des actions groupées au-dessus du tableau, seulement quand une ligne est cochée ;
 * - un clic n'importe où sur une ligne du tableau qui ouvre le détail (row-click.js) ;
 * - en bas, la pagination seule.
 * Mêmes props que le DataViews du paquet ; la sélection est gérée ici quand l'écran ne la fournit pas.
 *
 * @param {Object} props Props de DataViews.
 */
export default function DataViews( {
	data,
	fields,
	view,
	getItemId = defaultGetItemId,
	isItemClickable = alwaysClickable,
	onClickItem,
	selection: givenSelection,
	onChangeSelection: givenOnChangeSelection,
	search = true,
	searchLabel,
	header,
	paginationInfo,
	...props
} ) {
	const [ ownSelection, setOwnSelection ] = useState( [] );
	const controlled =
		givenSelection !== undefined && givenOnChangeSelection !== undefined;
	const selection = controlled ? givenSelection : ownSelection;
	const onChangeSelection = controlled
		? givenOnChangeSelection
		: setOwnSelection;

	const titleField = view.titleField;
	const markedFields = useMemo(
		() =>
			fields.map( ( field ) =>
				field.id !== titleField
					? field
					: {
							...field,
							render: ( renderProps ) => (
								<span
									{ ...{
										[ ITEM_ATTRIBUTE ]: String(
											getItemId( renderProps.item )
										),
									} }
								>
									{ field.render
										? field.render( renderProps )
										: plainValue( field, renderProps.item ) }
								</span>
							),
					  }
			),
		[ fields, titleField, getItemId ]
	);

	const byId = useMemo(
		() =>
			new Map(
				( data || [] ).map( ( item ) => [
					String( getItemId( item ) ),
					item,
				] )
			),
		[ data, getItemId ]
	);
	const clickable = !! onClickItem && view.type === 'table';
	const onLayoutClick = ( event ) => {
		if ( ! clickable ) {
			return;
		}
		const id = rowItemId( event );
		const item = id === null ? undefined : byId.get( id );
		if ( item !== undefined && isItemClickable( item ) ) {
			onClickItem( item );
		}
	};

	return (
		<PackageDataViews
			{ ...props }
			data={ data }
			fields={ markedFields }
			view={ view }
			getItemId={ getItemId }
			isItemClickable={ isItemClickable }
			onClickItem={ onClickItem }
			selection={ selection }
			onChangeSelection={ onChangeSelection }
			search={ search }
			searchLabel={ searchLabel }
			paginationInfo={ paginationInfo }
		>
			<div className="dataviews__view-actions msradar-dataviews__toolbar">
				<div className="dataviews__search msradar-dataviews__search">
					{ search && (
						<PackageDataViews.Search label={ searchLabel } />
					) }
					<PackageDataViews.FiltersToggle />
				</div>
				<div className="msradar-dataviews__config">
					<PackageDataViews.LayoutSwitcher />
					<PackageDataViews.ViewConfig />
					{ header }
				</div>
			</div>
			<PackageDataViews.FiltersToggled className="dataviews-filters__container" />
			{ selection.length > 0 && (
				<div className="msradar-dataviews__bulk">
					<PackageDataViews.BulkActionToolbar />
				</div>
			) }
			{ /* Le titre de chaque ligne reste le bouton qui ouvre le détail au clavier ; ce clic n'en est qu'un raccourci. */ }
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
			<div
				className={
					clickable
						? 'msradar-dataviews__layout is-clickable'
						: 'msradar-dataviews__layout'
				}
				onClick={ onLayoutClick }
			>
				<PackageDataViews.Layout />
			</div>
			{ paginationInfo?.totalPages > 1 && (
				<div className="dataviews-footer msradar-dataviews__footer">
					<PackageDataViews.Pagination />
				</div>
			) }
		</PackageDataViews>
	);
}
```

`src/components/data-views/index.js` devient :

```js
/**
 * Seul point d'accès au paquet dataviews (version épinglée, spec §6.3) : une montée de version ne touche que ce
 * dossier. DataViews est le composant du plugin (radar-data-views.jsx), qui assemble celui du paquet.
 */
export { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
export { default as DataViews } from './radar-data-views';
export { filterValue, toFilters } from './filters';
```

À la fin de `src/admin/style.scss` :

```scss
// DataViews assemblé par le plugin (src/components/data-views/radar-data-views.jsx).
.msradar-dataviews__toolbar {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px;
}

.msradar-dataviews__search,
.msradar-dataviews__config {
	display: flex;
	align-items: center;
	gap: 8px;
}

.msradar-dataviews__config {
	flex-shrink: 0;
}

.msradar-dataviews__bulk {
	padding: 0 24px 12px;
}

// Zone du tableau : seulement pour recevoir le clic sur une ligne, sans boîte propre.
.msradar-dataviews__layout {
	display: contents;
}

.msradar-dataviews__layout.is-clickable .dataviews-view-table tbody tr.dataviews-view-table__row {
	cursor: pointer;
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `npx wp-scripts test-unit-js src/components/data-views`
Expected: PASS (les tests des filtres, plus 5 + 5 nouveaux).

Si `getByRole( 'searchbox', … )` ne trouve rien, lire le rendu de `DataViewsSearch` (`node_modules/@wordpress/dataviews/src/components/dataviews-search/index.tsx`) et viser le rôle qu'il produit ; ne pas changer le composant pour le test.

- [ ] **Step 9: Run every screen test**

Les cinq écrans utilisent désormais ce composant.

Run: `npm run test:unit && npm run lint:js && npm run lint:css`
Expected: PASS. Un test d'écran qui cherchait la barre d'actions groupées dans le pied doit maintenant la trouver en haut : adapter le test, pas le composant.

- [ ] **Step 10: Commit**

```bash
git add src/components/data-views src/admin/style.scss
git commit -m "feat: open the detail from anywhere in a table row and show the bulk actions above the table"
```

---

### Task 2: Sites et Alertes — détail dans « ⋮ », panneau du site sur Alertes, filtres toujours visibles

Spec rc.2 § 2.1, § 2.2 et § 2.4.

**Files:**
- Modify : `src/views/sites/actions.js`, `src/views/sites/fields.jsx`
- Modify : `src/views/alerts/index.jsx`, `src/views/alerts/query.js`, `src/views/alerts/fields.jsx`
- Test : `src/views/sites/test/actions.test.js` (nouveau), `src/views/alerts/test/query.test.js`, `src/views/alerts/test/alerts-view.test.jsx`

**Interfaces:**
- Consumes : `DataViews` de la tâche 1 (`onClickItem`) ; `SitePanel` de `src/views/site-panel` (`siteId`, `items` = `[ { id, name } ]`, `onNavigate( id )`, `onClose()`).
- Produces : `alertSites( alerts ): Array<{ id, name, … }>` dans `src/views/alerts/query.js` ; état d'Alertes avec `site` (number, 0 = fermé), paramètre d'adresse `site`.

- [ ] **Step 1: Write the failing tests**

`src/views/sites/test/actions.test.js` :

```js
import { expect, test, vi } from 'vitest';
import { getSitesActions } from '../actions';

test( 'no site action is primary: the detail opens from the row and the actions column keeps only its menu', () => {
	const actions = getSitesActions( {
		canManage: true,
		onOpen: vi.fn(),
		onRescan: vi.fn(),
		onExport: vi.fn(),
	} );
	expect( actions.filter( ( action ) => action.isPrimary ) ).toEqual( [] );
	expect( actions.map( ( action ) => action.id ) ).toContain( 'open' );
} );
```

Dans `src/views/alerts/test/query.test.js`, ajouter (en important `alertSites`, `parseAlertsQuery` et `serializeAlertsState` depuis `../query` s'ils ne le sont pas déjà) :

```js
test( 'the site of the open panel lives in the address', () => {
	expect( parseAlertsQuery( { site: '12' } ).site ).toBe( 12 );
	for ( const value of [ '0', '-3', 'x', '' ] ) {
		expect( parseAlertsQuery( { site: value } ).site ).toBe( 0 );
	}
	expect(
		serializeAlertsState( { ...parseAlertsQuery( {} ), site: 12 } ).site
	).toBe( '12' );
	expect( serializeAlertsState( parseAlertsQuery( {} ) ).site ).toBeUndefined();
} );

test( 'alertSites lists each site of the page once, in order', () => {
	const alerts = [
		{ id: 'a', site: { id: 4, name: 'Blog RH' } },
		{ id: 'b', site: { id: 2, name: 'Atelier' } },
		{ id: 'c', site: { id: 4, name: 'Blog RH' } },
	];
	expect( alertSites( alerts ).map( ( site ) => site.id ) ).toEqual( [ 4, 2 ] );
} );
```

Dans `src/views/alerts/test/alerts-view.test.jsx` :
- déplacer l'objet `preload` du premier test dans une fonction `alertsPreload()` en haut du fichier (elle renvoie un objet neuf à chaque appel), et l'utiliser dans ce test ;
- importer `fireEvent` avec `act`, `render` et `screen` ;
- nouveau test, à la fin du fichier :

```jsx
test( 'a click on a row opens the site panel without leaving the Alerts screen', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-alerts'
	);
	const preload = alertsPreload();
	window.msradarAdmin = {
		view: 'alerts',
		canManage: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<AlertsView />
		</RegistryProvider>
	);
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );

	fireEvent.click( screen.getByText( 'No user is attached to this site.' ) );

	// Le panneau demande /sites/2 : apiFetch, simulé, ne répond jamais ; le titre vient des sites de la page.
	expect(
		screen.getByRole( 'dialog', { name: 'Site vide' } )
	).toBeInTheDocument();
	expect( window.location.search ).toContain( 'page=multisite-radar-alerts' );
	expect( window.location.search ).toContain( 'site=2' );
} );
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npx wp-scripts test-unit-js src/views/sites src/views/alerts`
Expected: FAIL (action `open` principale, `alertSites` inconnue, pas de `site` dans l'état, pas de dialogue).

- [ ] **Step 3: Sites**

Dans `src/views/sites/actions.js`, action `open` : retirer la ligne `isPrimary: true,`. Mettre à jour la docblock de `getSitesActions` : « Aucune action n'est principale : le clic sur la ligne ouvre la fiche (spec rc.2 § 2.2). »

Dans `src/views/sites/fields.jsx`, champ `alert_level` :

```jsx
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
```

- [ ] **Step 4: Alertes — état et sites de la page**

Dans `src/views/alerts/query.js` :
- `parseAlertsQuery()` lit le site comme `parseSitesQuery()` :

```js
export function parseAlertsQuery( query ) {
	const orderby = text( query, 'orderby' );
	const site = text( query, 'site' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: ALERT_ORDERBY.includes( orderby ) ? orderby : 'rule',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		severity: subset( query, 'severity', SEVERITIES ),
		rule: ruleList( text( query, 'rule' ).split( ',' ) ),
		site: /^\d+$/.test( site ) && Number( site ) > 0 ? Number( site ) : 0,
	};
}
```

- `serializeAlertsState()` ajoute, avant `return out;` :

```js
	if ( state.site ) {
		out.site = String( state.site );
	}
```

- nouvelle fonction exportée, à la fin du fichier :

```js
/**
 * Sites des alertes de la page, une fois chacun, dans l'ordre : « précédent » et « suivant » du panneau du site.
 *
 * @param {Array} alerts Alertes de la page (chacune avec son site).
 */
export function alertSites( alerts ) {
	const sites = new Map();
	for ( const alert of alerts ) {
		if ( ! sites.has( alert.site.id ) ) {
			sites.set( alert.site.id, alert.site );
		}
	}
	return [ ...sites.values() ];
}
```

`alertsRestArgs()` ne change pas : le site ouvert n'est pas un argument de la liste. `fromAlertsView()` garde `...current`, donc le site ouvert.

- [ ] **Step 5: Alertes — panneau du site**

Dans `src/views/alerts/index.jsx` :
- imports : `useCallback` en plus de `useMemo` et `useState` ; `SitePanel` depuis `'../site-panel'` ; `alertSites` depuis `'./query'`. L'import de `pageUrl` devient inutile : le retirer.
- `actions()` reçoit la fonction d'ouverture et ne marque plus d'action principale :

```jsx
function actions( onOpen ) {
	return [
		{
			id: 'open',
			label: __( 'View the site', 'multisite-radar' ),
			icon: info,
			callback: ( [ item ] ) => onOpen( item ),
		},
		{
			id: 'admin',
			label: __( 'Site dashboard', 'multisite-radar' ),
			icon: wordpress,
			isEligible: ( item ) => !! item.site.admin_url,
			callback: ( [ item ] ) =>
				window.location.assign( item.site.admin_url ),
		},
	];
}
```

- dans `AlertsView`, remplacer `const rowActions = useMemo( () => actions(), [] );` par :

```jsx
	const openSite = useCallback(
		( item ) =>
			setState( ( current ) => ( { ...current, site: item.site.id } ) ),
		[ setState ]
	);
	const rowActions = useMemo( () => actions( openSite ), [ openSite ] );
	const sites = useMemo( () => alertSites( list.data || [] ), [ list.data ] );
```

- sur `<DataViews>`, ajouter `onClickItem={ openSite }` ;
- après `</DataViews>`, dans le `div.msradar-alerts` :

```jsx
			{ state.site > 0 && (
				<SitePanel
					siteId={ state.site }
					items={ sites }
					onNavigate={ ( id ) =>
						setState( ( current ) => ( { ...current, site: id } ) )
					}
					onClose={ () =>
						setState( ( current ) => ( { ...current, site: 0 } ) )
					}
				/>
			) }
```

Dans `src/views/alerts/fields.jsx`, champ `severity` :

```jsx
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx wp-scripts test-unit-js src/views/sites src/views/alerts && npm run lint:js`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/views/sites src/views/alerts
git commit -m "feat: open the site panel from the Alerts screen and keep the severity filters in view"
```

---

### Task 3: Extensions et Thèmes — tri par état, version et mise à jour ; couleurs ; filtre d'état toujours visible

Spec rc.2 § 2.2, § 2.4, § 3.1 et § 3.2.

**Files:**
- Modify : `includes/Query/InventoryList.php`, `includes/Query/PluginsQuery.php:135`, `includes/Query/ThemesQuery.php:121`
- Modify : `src/views/inventory/query.js`, `src/views/inventory/fields.jsx`, `src/views/inventory/index.jsx`, `src/views/plugins/fields.jsx`, `src/views/themes/fields.jsx`
- Modify : `src/admin/style.scss` (bloc `.msradar-badge`)
- Modify : `tests/fixtures/view-queries.json`
- Test : `tests/php/Query/InventoryListTest.php` (nouveau), `src/views/inventory/test/fields.test.jsx` (nouveau), `src/views/inventory/test/query.test.js`

**Interfaces:**
- Produces :
  - `InventoryList::ORDERBY = [ 'name', 'sites_count', 'status', 'version', 'update_version' ]` ; la route REST et `Admin\ViewQuery` en dérivent leur liste blanche ;
  - `InventoryList::sort( array $items, string $orderby, string $order, array $statuses = [] ): array` ;
  - `INVENTORY_ORDERBY` (JS), même liste ;
  - classe CSS `msradar-badge--success`.

- [ ] **Step 1: Write the failing PHP test**

`tests/php/Query/InventoryListTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\InventoryList;
use MultisiteRadar\Tests\TestCase;

final class InventoryListTest extends TestCase {

	private const STATUSES = [ 'network', 'local', 'unused', 'missing' ];

	private static function items(): array {
		return [
			[
				'id'             => 'b/b.php',
				'name'           => 'Bravo',
				'sites_count'    => 2,
				'status'         => 'unused',
				'version'        => '1.10.0',
				'update_version' => null,
			],
			[
				'id'             => 'a/a.php',
				'name'           => 'alpha',
				'sites_count'    => 5,
				'status'         => 'network',
				'version'        => '1.9.2',
				'update_version' => '2.0.0',
			],
			[
				'id'             => 'c/c.php',
				'name'           => 'Charlie',
				'sites_count'    => 0,
				'status'         => 'missing',
				'version'        => '',
				'update_version' => null,
			],
			[
				'id'             => 'd/d.php',
				'name'           => 'Delta',
				'sites_count'    => 1,
				'status'         => 'local',
				'version'        => '1.10.0',
				'update_version' => '1.11.0',
			],
		];
	}

	private static function names( string $orderby, string $order ): array {
		return array_column( InventoryList::sort( self::items(), $orderby, $order, self::STATUSES ), 'name' );
	}

	public function test_sorts_by_status_in_the_given_order(): void {
		$this->assertSame( [ 'alpha', 'Delta', 'Bravo', 'Charlie' ], self::names( 'status', 'asc' ) );
		$this->assertSame( [ 'Charlie', 'Bravo', 'Delta', 'alpha' ], self::names( 'status', 'desc' ) );
	}

	public function test_sorts_versions_numerically_then_by_name(): void {
		// Version vide < 1.9.2 < 1.10.0 ; Bravo et Delta à égalité, départagés par le nom.
		$this->assertSame( [ 'Charlie', 'alpha', 'Bravo', 'Delta' ], self::names( 'version', 'asc' ) );
	}

	public function test_sorts_by_update_without_update_first(): void {
		$this->assertSame( [ 'Bravo', 'Charlie', 'alpha', 'Delta' ], self::names( 'update_version', 'asc' ) );
		$this->assertSame( [ 'alpha', 'Delta', 'Bravo', 'Charlie' ], self::names( 'update_version', 'desc' ) );
	}

	public function test_name_and_sites_count_keep_their_order(): void {
		$this->assertSame( [ 'alpha', 'Bravo', 'Charlie', 'Delta' ], self::names( 'name', 'asc' ) );
		$this->assertSame( [ 'alpha', 'Bravo', 'Delta', 'Charlie' ], self::names( 'sites_count', 'desc' ) );
	}

	public function test_an_unknown_status_sorts_last(): void {
		$items              = self::items();
		$items[0]['status'] = 'bogus';

		$sorted = InventoryList::sort( $items, 'status', 'asc', self::STATUSES );

		$this->assertSame( 'Bravo', end( $sorted )['name'] );
	}
}
```

Run: `bin/test.sh --filter InventoryListTest`
Expected: FAIL (tri par état, version et mise à jour inconnus : ordre par nom).

- [ ] **Step 2: Extend the sort**

Dans `includes/Query/InventoryList.php`, remplacer la constante `ORDERBY` et la méthode `sort()` par :

```php
	public const ORDERBY = [ 'name', 'sites_count', 'status', 'version', 'update_version' ];

	/**
	 * Tri par nom (ordre naturel, sans casse), nombre de sites, état (dans l'ordre de $statuses ; un état inconnu en
	 * dernier), version (version_compare) ou mise à jour (sans mise à jour d'abord) ; à égalité, par nom puis par
	 * identifiant, quel que soit le sens.
	 *
	 * @param array[]  $items
	 * @param string[] $statuses États dans l'ordre du tri croissant.
	 * @return array[]
	 */
	public static function sort( array $items, string $orderby, string $order, array $statuses = [] ): array {
		$sign = 'desc' === strtolower( $order ) ? -1 : 1;
		$rank = array_flip( array_values( $statuses ) );
		usort(
			$items,
			static function ( array $a, array $b ) use ( $orderby, $sign, $rank ): int {
				$primary = self::compare( $a, $b, $orderby, $rank );
				if ( 0 !== $primary ) {
					return $sign * $primary;
				}
				$name = strnatcasecmp( $a['name'], $b['name'] );
				return 0 !== $name ? $name : strcmp( $a['id'], $b['id'] );
			}
		);
		return $items;
	}

	/**
	 * @param array<string, int> $rank État => rang.
	 */
	private static function compare( array $a, array $b, string $orderby, array $rank ): int {
		switch ( $orderby ) {
			case 'sites_count':
				return $a['sites_count'] <=> $b['sites_count'];
			case 'status':
				return ( $rank[ (string) ( $a['status'] ?? '' ) ] ?? PHP_INT_MAX ) <=> ( $rank[ (string) ( $b['status'] ?? '' ) ] ?? PHP_INT_MAX );
			case 'version':
				return version_compare( (string) ( $a['version'] ?? '' ), (string) ( $b['version'] ?? '' ) );
			case 'update_version':
				return ( null !== ( $a['update_version'] ?? null ) ) <=> ( null !== ( $b['update_version'] ?? null ) );
			default:
				return strnatcasecmp( $a['name'], $b['name'] );
		}
	}
```

Mettre à jour la docblock de la classe : « tri par nom, nombre de sites, état, version ou mise à jour ».

Dans `includes/Query/PluginsQuery.php:135` et `includes/Query/ThemesQuery.php:121`, passer les états :

```php
		return InventoryList::sort( array_values( $items ), (string) $args['orderby'], (string) $args['order'], self::STATUSES );
```

Run: `bin/test.sh --filter "InventoryListTest|PluginsQueryTest|ThemesQueryTest|PluginsControllerTest|ThemesControllerTest|ViewQueryTest"`
Expected: `InventoryListTest` passe. `ViewQueryTest` peut échouer sur la parité tant que le JS n'est pas fait (step 4).

- [ ] **Step 3: Write the failing JS tests**

`src/views/inventory/test/fields.test.jsx` :

```jsx
import { expect, test } from 'vitest';
import { render } from '@testing-library/react';
import { getPluginsFields } from '../../plugins/fields';
import { getThemesFields } from '../../themes/fields';

function badge( fields, id, item ) {
	const field = fields.find( ( candidate ) => candidate.id === id );
	const { container } = render( field.render( { item, field } ) );
	return container.querySelector( '.msradar-badge' );
}

test( 'states have their colour: green in use, orange unused, red not installed', () => {
	const plugins = getPluginsFields();
	expect( badge( plugins, 'status', { status: 'network' } ) ).toHaveClass( 'msradar-badge--success' );
	expect( badge( plugins, 'status', { status: 'local' } ) ).toHaveClass( 'msradar-badge--success' );
	expect( badge( plugins, 'status', { status: 'unused' } ) ).toHaveClass( 'msradar-badge--warning' );
	expect( badge( plugins, 'status', { status: 'missing' } ) ).toHaveClass( 'msradar-badge--error' );

	const themes = getThemesFields();
	expect( badge( themes, 'status', { status: 'used' } ) ).toHaveClass( 'msradar-badge--success' );
	expect( badge( themes, 'status', { status: 'unused' } ) ).toHaveClass( 'msradar-badge--warning' );
	expect( badge( themes, 'status', { status: 'missing' } ) ).toHaveClass( 'msradar-badge--error' );
} );

test( 'an available update is blue', () => {
	expect(
		badge( getPluginsFields(), 'update_version', { update_version: '2.0.0' } )
	).toHaveClass( 'msradar-badge--info' );
} );

test( 'state, version and update are sortable, and the state filter stays in view', () => {
	const fields = getPluginsFields();
	for ( const id of [ 'status', 'version', 'update_version' ] ) {
		const field = fields.find( ( candidate ) => candidate.id === id );
		expect( field.enableSorting ).not.toBe( false );
	}
	expect(
		fields.find( ( field ) => field.id === 'status' ).filterBy.isPrimary
	).toBe( true );
} );
```

Les noms `getPluginsFields` et `getThemesFields` sont ceux exportés par `src/views/plugins/fields.jsx` et `src/views/themes/fields.jsx` ; si l'un des fichiers les nomme autrement, garder le nom existant et l'utiliser dans le test.

Dans `tests/fixtures/view-queries.json`, ajouter avant le dernier `]` (après le cas « users: invalid values are ignored », qui sera revu par la tâche 6) :

```json
	,
	{
		"name": "plugins: sorted by state",
		"view": "plugins",
		"query": { "orderby": "status", "order": "desc" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "status", "order": "desc" }
	},
	{
		"name": "themes: sorted by update and version",
		"view": "themes",
		"query": { "orderby": "update_version" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "update_version", "order": "asc" }
	},
	{
		"name": "plugins: sorted by version",
		"view": "plugins",
		"query": { "orderby": "version" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "version", "order": "asc" }
	}
```

(Placer la virgule de séparation à la fin de l'objet précédent, pas sur une ligne seule, pour garder le style du fichier.)

Run: `npx wp-scripts test-unit-js src/views/inventory`
Expected: FAIL (couleurs, tri, parité).

- [ ] **Step 4: Sort, colours and primary filter in the interface**

`src/views/inventory/query.js` :

```js
export const INVENTORY_ORDERBY = [
	'name',
	'sites_count',
	'status',
	'version',
	'update_version',
];
```

`src/views/inventory/fields.jsx` :
- `versionField()` : retirer `enableSorting: false,` ;
- `statusField()` : retirer `enableSorting: false,` et écrire `filterBy: { operators: [ 'isAny' ], isPrimary: true },` ; la docblock dit « tons : success, warning, error, info » ;
- `updateField()` : retirer `enableSorting: false,` ; le badge devient `msradar-badge msradar-badge--info`.

`src/views/plugins/fields.jsx` :

```jsx
		statusField( pluginStatusLabels(), {
			network: 'success',
			local: 'success',
			unused: 'warning',
			missing: 'error',
		} ),
```

`src/views/themes/fields.jsx` :

```jsx
		statusField( themeStatusLabels(), {
			used: 'success',
			unused: 'warning',
			missing: 'error',
		} ),
```

`src/views/inventory/index.jsx`, action `sites` : retirer `isPrimary: true,` (le clic sur la ligne ouvre déjà le panneau : `onClickItem={ setOpen }`).

`src/admin/style.scss`, dans `.msradar-badge`, après `&--info { … }` :

```scss
	&--success {
		background: #edfaef;
		color: #005c12;
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx wp-scripts test-unit-js src/views/inventory && bin/test.sh --filter "InventoryListTest|PluginsQueryTest|ThemesQueryTest|PluginsControllerTest|ThemesControllerTest|ViewQueryTest" && npm run lint:js && npm run lint:css && composer lint && composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Query src/views/inventory src/views/plugins src/views/themes src/admin/style.scss tests/fixtures/view-queries.json tests/php/Query/InventoryListTest.php
git commit -m "feat: sort plugins and themes by state, version and update, with a colour for each state"
```

---

### Task 4: Export libellé et badge de version en clair

Spec rc.2 § 3.3 et § 3.4.

**Files:**
- Modify : `src/components/export-menu.jsx`, `includes/Admin/Menu.php:92-104`
- Test : `src/components/test/components.test.jsx`, `tests/php/Admin/MenuTest.php:68-72`

**Interfaces:**
- Produces : `Menu::version_badge( string $version ): string`, même signature, nouveau rendu pour une version préliminaire.

- [ ] **Step 1: Write the failing tests**

Dans `src/components/test/components.test.jsx`, après le test « ExportMenu offers CSV and JSON » :

```jsx
test( 'ExportMenu shows its label next to the icon', () => {
	render( <ExportMenu href={ () => '#' } /> );
	expect( screen.getByRole( 'button', { name: 'Export' } ) ).toHaveTextContent(
		'Export'
	);
} );
```

Dans `tests/php/Admin/MenuTest.php`, remplacer le corps de `test_the_version_badge_flags_pre_releases()` par :

```php
		$this->assertSame( '<span class="msradar-version">2.0.0</span>', Menu::version_badge( '2.0.0' ) );
		$this->assertSame( '<span class="msradar-version is-prerelease">2.0.0-rc.2 · pre-release</span>', Menu::version_badge( '2.0.0-rc.2' ) );
		$this->assertSame( '<span class="msradar-version">&lt;b&gt;</span>', Menu::version_badge( '<b>' ) );
		$this->assertSame( '<span class="msradar-version is-prerelease">&lt;b&gt;-1 · pre-release</span>', Menu::version_badge( '<b>-1' ) );
```

Run: `npx wp-scripts test-unit-js src/components && bin/test.sh --filter MenuTest`
Expected: FAIL (bouton sans texte ; badge avec `title`).

- [ ] **Step 2: Implement**

`src/components/export-menu.jsx` :

```jsx
export default function ExportMenu( { href } ) {
	const label = __( 'Export', 'multisite-radar' );
	return (
		<DropdownMenu
			icon={ download }
			label={ label }
			text={ label }
			toggleProps={ { showTooltip: false } }
			controls={ [
				{
					title: __( 'Export as CSV', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'csv' ) ),
				},
				{
					title: __( 'Export as JSON', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'json' ) ),
				},
			] }
		/>
	);
}
```

La docblock dit : « Menu « Export » d'une liste : CSV ou JSON. Le bouton porte son libellé à côté de l'icône (spec rc.2 § 3.3). »

`includes/Admin/Menu.php`, `version_badge()` :

```php
	/**
	 * Pastille de version affichée à côté du titre ; une version préliminaire (« -beta.1 », « -rc.2 »…) le dit en clair.
	 */
	public static function version_badge( string $version ): string {
		if ( false === strpos( $version, '-' ) ) {
			return sprintf( '<span class="msradar-version">%s</span>', esc_html( $version ) );
		}
		return sprintf(
			'<span class="msradar-version is-prerelease">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: version number, such as 2.0.0-rc.2. */
					__( '%s · pre-release', 'multisite-radar' ),
					$version
				)
			)
		);
	}
```

- [ ] **Step 3: Run the tests to verify they pass**

Run: `npx wp-scripts test-unit-js src/components && bin/test.sh --filter "MenuTest|FooterTest" && npm run lint:js && composer lint`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/components/export-menu.jsx src/components/test/components.test.jsx includes/Admin/Menu.php tests/php/Admin/MenuTest.php
git commit -m "feat: label the export button and spell out pre-release versions in the badge"
```

---

### Task 5: Contenus publiés par auteur — table, relevé pendant l'analyse, confidentialité

Spec rc.2 § 4.4 et § 4.5 ; écart E2.

**Files:**
- Modify : `includes/Install/Schema.php`, `uninstall.php`
- Create : `includes/Storage/AuthorsRepository.php`
- Modify : `includes/Storage/SiteRecord.php`, `includes/Collector/SiteCollector.php`
- Modify : `includes/Scan/BatchRunner.php`, `includes/Scan/Invalidation.php`, `includes/Scan/Queue.php:274-279`
- Modify : `includes/Plugin.php` (accesseur `authors()`, constructions de `BatchRunner` et `Invalidation`)
- Modify : `includes/Admin/Privacy.php`, `readme.txt` (section « Privacy »)
- Test : `tests/php/Install/SchemaTest.php`, `tests/php/Storage/AuthorsRepositoryTest.php` (nouveau), `tests/php/Collector/SiteCollectorTest.php`, `tests/php/Scan/BatchRunnerTest.php`, `tests/php/Scan/InvalidationTest.php`, `tests/php/Scan/QueueTest.php`, `tests/php/UninstallTest.php` (nouveau), `tests/php/Admin/PrivacyTest.php`

**Interfaces:**
- Produces :
  - `Schema::VERSION = 5`, `Schema::authors_table(): string` (`{base_prefix}msradar_site_authors`), dans `Schema::tables()` ;
  - `AuthorsRepository` (accès : `Plugin::instance()->authors()`), constante `CACHE_GROUP = 'msradar_authors'` (clé `last_changed` incrémentée à chaque écriture), méthodes :
    - `replace_for_site( int $site_id, array $authors ): void` — `$authors` : identifiant d'auteur => nombre ; écrit aussi la ligne témoin `user_id` 0 ;
    - `delete_for_site( int $site_id ): void` ;
    - `totals( int[] $user_ids ): array<int, int>` — total par compte, sur les sites qui existent encore ;
    - `for_user( int $user_id ): array<int, int>` — site => nombre, sur les sites qui existent encore ;
    - `analysed_among( int[] $site_ids ): array<int, true>` — sites qui ont leur ligne témoin ;
    - `is_analysed( int $site_id ): bool` ;
    - `is_empty(): bool` — aucune ligne du tout (aucune analyse depuis la mise à niveau) ;
  - `SiteRecord::$authors` (array<int, int>), rempli par `SiteCollector::collect()`, jamais enregistré dans `msradar_sites`.

- [ ] **Step 1: Write the failing tests**

`tests/php/Install/SchemaTest.php` :
- dans `test_the_current_version_has_the_siteurl_column()`, les deux `assertSame( 4, … )` deviennent `assertSame( 5, … )` ;
- nouveau test :

```php
	public function test_version_5_adds_the_authors_table(): void {
		global $wpdb;

		$this->assertSame(
			[ 'site_id', 'user_id', 'published' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::authors_table() ) )
		);
		$this->assertContains( Schema::authors_table(), Schema::tables() );
	}
```

`tests/php/Storage/AuthorsRepositoryTest.php` :

```php
<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Storage\AuthorsRepository;
use MultisiteRadar\Tests\TestCase;

final class AuthorsRepositoryTest extends TestCase {

	private function authors(): AuthorsRepository {
		return $this->plugin()->authors();
	}

	public function test_replaces_the_counts_of_a_site_and_marks_it_analysed(): void {
		$site = self::factory()->blog->create();

		$this->authors()->replace_for_site( $site, [ 7 => 3, 9 => 1 ] );
		$this->authors()->replace_for_site( $site, [ 7 => 2, 0 => 5, 11 => 0, -4 => 2 ] );

		$this->assertSame( [ $site => 2 ], $this->authors()->for_user( 7 ) );
		$this->assertSame( [], $this->authors()->for_user( 9 ) );
		$this->assertSame( [ 7 => 2 ], $this->authors()->totals( [ 7, 9, 11 ] ) );
		$this->assertTrue( $this->authors()->is_analysed( $site ) );
		$this->assertSame( [ $site => true ], $this->authors()->analysed_among( [ $site, $site + 1000 ] ) );
	}

	public function test_totals_add_the_sites_and_ignore_deleted_ones(): void {
		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();
		$this->authors()->replace_for_site( $first, [ 7 => 2 ] );
		$this->authors()->replace_for_site( $second, [ 7 => 5 ] );
		$this->authors()->replace_for_site( 987654, [ 7 => 40 ] );

		$this->assertSame( [ 7 => 7 ], $this->authors()->totals( [ 7 ] ) );
		$this->assertSame( [], $this->authors()->totals( [] ) );
	}

	public function test_deleting_a_site_forgets_it(): void {
		$site = self::factory()->blog->create();
		$this->authors()->replace_for_site( $site, [ 7 => 2 ] );

		$this->authors()->delete_for_site( $site );

		$this->assertSame( [], $this->authors()->for_user( 7 ) );
		$this->assertFalse( $this->authors()->is_analysed( $site ) );
	}

	public function test_is_empty_until_a_first_site_is_analysed(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::authors_table() ) );
		$this->assertTrue( $this->authors()->is_empty() );

		$this->authors()->replace_for_site( self::factory()->blog->create(), [] );

		$this->assertFalse( $this->authors()->is_empty() );
	}

	public function test_every_write_renews_the_cache_generation(): void {
		$site   = self::factory()->blog->create();
		$before = wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP );
		usleep( 2 );

		$this->authors()->replace_for_site( $site, [ 7 => 1 ] );

		$this->assertNotSame( $before, wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP ) );
	}
}
```

Dans `tests/php/Collector/SiteCollectorTest.php`, nouveau test :

```php
	public function test_counts_the_published_content_of_each_author_like_the_content_column(): void {
		$site_id = self::factory()->blog->create();
		$author  = self::factory()->user->create();
		switch_to_blog( $site_id );
		self::factory()->post->create_many( 2, [ 'post_author' => $author ] );
		self::factory()->post->create(
			[
				'post_author' => $author,
				'post_type'   => 'page',
			]
		);
		self::factory()->post->create(
			[
				'post_author' => $author,
				'post_status' => 'draft',
			]
		);
		self::factory()->post->create(
			[
				'post_author' => $author,
				'post_type'   => 'wp_block',
			]
		);
		self::factory()->post->create(
			[
				'post_author' => $author,
				'post_type'   => 'attachment',
				'post_status' => 'publish',
			]
		);
		restore_current_blog();

		$record = $this->plugin()->collector()->collect( $site_id );

		$this->assertSame( 3, $record->authors[ $author ] );
	}
```

Dans `tests/php/Scan/BatchRunnerTest.php`, nouveau test :

```php
	public function test_the_scan_stores_the_published_content_of_each_author(): void {
		$site_id = self::factory()->blog->create();
		$author  = self::factory()->user->create();
		switch_to_blog( $site_id );
		self::factory()->post->create_many( 2, [ 'post_author' => $author ] );
		restore_current_blog();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );

		$this->assertTrue( $this->plugin()->runner()->scan_site( $site_id ) );

		$this->assertSame( [ $site_id => 2 ], $this->plugin()->authors()->for_user( $author ) );
		$this->assertTrue( $this->plugin()->authors()->is_analysed( $site_id ) );
	}
```

Dans `tests/php/Scan/InvalidationTest.php`, `test_a_deleted_site_loses_its_rows()` :
- avant `wp_delete_site( … )` : `$this->plugin()->authors()->replace_for_site( $this->site_id, [ 7 => 2 ] );`
- après les assertions existantes : `$this->assertFalse( $this->plugin()->authors()->is_analysed( $this->site_id ) );`

Dans `tests/php/Scan/QueueTest.php`, deux nouveaux tests :

```php
	public function test_upgrading_to_version_5_requests_a_full_scan(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$this->queue->on_upgraded( 5, 4 );

		$this->assertTrue( $this->plugin()->sites()->find( $site_id )->dirty );
	}

	public function test_an_up_to_date_schema_requests_no_full_scan(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$this->queue->on_upgraded( 5, 5 );

		$this->assertFalse( $this->plugin()->sites()->find( $site_id )->dirty );
	}
```

`tests/php/UninstallTest.php` :

```php
<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Install\Schema;

final class UninstallTest extends TestCase {

	public function test_uninstall_drops_every_table_of_the_schema(): void {
		global $wpdb;
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file in a test.

		foreach ( Schema::tables() as $table ) {
			$this->assertStringContainsString( "'" . substr( $table, strlen( $wpdb->base_prefix ) ) . "'", $source, $table );
		}
	}
}
```

Dans `tests/php/Admin/PrivacyTest.php`, nouveau test :

```php
	public function test_the_text_names_the_published_posts_and_the_emails(): void {
		$this->assertStringContainsString( 'number of published posts of each author', Privacy::text() );
		$this->assertStringContainsString( 'e-mail address of each account', Privacy::text() );
	}
```

Run: `bin/test.sh --filter "SchemaTest|AuthorsRepositoryTest|SiteCollectorTest|BatchRunnerTest|InvalidationTest|QueueTest|UninstallTest|PrivacyTest"`
Expected: FAIL (table, dépôt, propriété et accesseur inconnus).

- [ ] **Step 2: Schema version 5**

`includes/Install/Schema.php` :
- docblock de `VERSION` : ajouter « ; 5 : table msradar_site_authors (rc.2), remplie par une analyse complète » ;
- `public const VERSION = 5;`
- nouvelle méthode, après `snapshots_table()` :

```php
	public static function authors_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_site_authors';
	}
```

- `tables()` : `return [ self::sites_table(), self::extensions_table(), self::events_table(), self::snapshots_table(), self::authors_table() ];`
- dans `install()`, `$authors = self::authors_table();` avec les autres noms, et après le `dbDelta` des relevés :

```php
		dbDelta(
			"CREATE TABLE {$authors} (
site_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
published int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (site_id,user_id),
KEY user_id (user_id)
) {$charset_collate};"
		);
```

`uninstall.php` : ajouter `'msradar_site_authors'` à la liste des tables supprimées.

- [ ] **Step 3: The repository**

`includes/Storage/AuthorsRepository.php` :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite Radar's own network table: no WordPress API reads or writes it, and the users query caches what it needs.

/**
 * Contenus publiés par auteur, site par site (table msradar_site_authors), relevés à chaque analyse.
 * Chaque site analysé a aussi une ligne témoin (user_id 0) : « aucun contenu » se distingue de « pas encore relevé ».
 * Les lectures ne comptent que les sites qui existent encore dans la table blogs.
 */
final class AuthorsRepository {

	/**
	 * Groupe de cache dont la génération (last_changed) change à chaque écriture.
	 */
	public const CACHE_GROUP = 'msradar_authors';

	/**
	 * @param array<int, int> $authors Identifiant de l'auteur => nombre de contenus publiés.
	 */
	public function replace_for_site( int $site_id, array $authors ): void {
		global $wpdb;
		$table = Schema::authors_table();
		self::check( $wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%d' ] ) );

		$rows = [ 0 => 0 ];
		foreach ( $authors as $user_id => $published ) {
			if ( (int) $user_id > 0 && (int) $published > 0 ) {
				$rows[ (int) $user_id ] = (int) $published;
			}
		}
		foreach ( $rows as $user_id => $published ) {
			self::check(
				$wpdb->insert(
					$table,
					[
						'site_id'   => $site_id,
						'user_id'   => $user_id,
						'published' => $published,
					],
					[ '%d', '%d', '%d' ]
				)
			);
		}
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	public function delete_for_site( int $site_id ): void {
		global $wpdb;
		self::check( $wpdb->delete( Schema::authors_table(), [ 'site_id' => $site_id ], [ '%d' ] ) );
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * @param int[] $user_ids
	 * @return array<int, int> Compte => contenus publiés, tous sites confondus ; un compte sans contenu est absent.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function totals( array $user_ids ): array {
		global $wpdb;
		$ids = self::ids( $user_ids );
		if ( [] === $ids ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.user_id, SUM(a.published) AS published FROM %i AS a INNER JOIN %i AS b ON b.blog_id = a.site_id WHERE a.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY a.user_id',
				array_merge( [ Schema::authors_table(), $wpdb->blogs ], $ids )
			),
			ARRAY_A
		);
		self::check_read();

		$totals = [];
		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row['user_id'] ] = (int) $row['published'];
		}
		return $totals;
	}

	/**
	 * @return array<int, int> Site => contenus publiés par ce compte.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.site_id, a.published FROM %i AS a INNER JOIN %i AS b ON b.blog_id = a.site_id WHERE a.user_id = %d ORDER BY a.site_id ASC',
				Schema::authors_table(),
				$wpdb->blogs,
				$user_id
			),
			ARRAY_A
		);
		self::check_read();

		$sites = [];
		foreach ( (array) $rows as $row ) {
			$sites[ (int) $row['site_id'] ] = (int) $row['published'];
		}
		return $sites;
	}

	/**
	 * @param int[] $site_ids
	 * @return array<int, true> Sites déjà relevés (ligne témoin présente).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function analysed_among( array $site_ids ): array {
		global $wpdb;
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return [];
		}
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM %i WHERE user_id = 0 AND site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ Schema::authors_table() ], $ids )
			)
		);
		self::check_read();
		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	public function is_analysed( int $site_id ): bool {
		return [] !== $this->analysed_among( [ $site_id ] );
	}

	/**
	 * Vrai tant qu'aucun site n'a été analysé depuis la création de la table.
	 *
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function is_empty(): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', Schema::authors_table() ) );
		self::check_read();
		return null === $found;
	}

	/**
	 * @param int[] $ids
	 * @return int[] Identifiants positifs, sans doublon.
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}

	/**
	 * @param int|false $result Résultat d'une écriture $wpdb.
	 */
	private static function check( $result ): void {
		global $wpdb;
		if ( false === $result ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
```

Dans `includes/Plugin.php` :
- `use MultisiteRadar\Storage\AuthorsRepository;` avec les autres `use` ;
- propriété `private ?AuthorsRepository $authors = null;` à côté de `$extensions` ;
- accesseur, après `extensions()` :

```php
	public function authors(): AuthorsRepository {
		return $this->authors ??= new AuthorsRepository();
	}
```

- `runner()` : `new BatchRunner( $this->sites(), $this->extensions(), $this->collector(), $this->evaluator(), $this->lock(), $this->change_log(), $this->authors() )` ;
- `invalidation()` : `new Invalidation( $this->sites(), $this->extensions(), $this->settings(), $this->change_log(), $this->authors() )`.

- [ ] **Step 4: Collect and store**

`includes/Storage/SiteRecord.php`, après `public array $data = [];` :

```php
	/**
	 * Contenus publiés par auteur (identifiant => nombre), relevés par SiteCollector et rangés par AuthorsRepository :
	 * jamais écrits dans la table des sites.
	 *
	 * @var array<int, int>
	 */
	public array $authors = [];
```

`includes/Collector/SiteCollector.php` :
- dans le `try` de `collect()`, après `$counts = $this->read_post_counts( $prefix );` : `$authors = $this->read_authors( $prefix );`
- avec les autres affectations de `$record` : `$record->authors = $authors;`
- nouvelle méthode, après `read_post_counts()` :

```php
	/**
	 * Contenus publiés par auteur, avec la définition de la colonne « Contenus » : statut publish, ni média ni type
	 * exclu (filtre msradar_excluded_post_types).
	 *
	 * @return array<int, int> Identifiant de l'auteur => nombre.
	 */
	private function read_authors( string $prefix ): array {
		global $wpdb;
		$excluded = array_values( array_unique( array_merge( array_map( 'strval', (array) apply_filters( 'msradar_excluded_post_types', self::EXCLUDED_POST_TYPES ) ), [ 'attachment' ] ) ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_author, COUNT(*) AS total FROM %i WHERE post_status = 'publish' AND post_author > 0 AND post_type NOT IN (" . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ') GROUP BY post_author',
				array_merge( [ $prefix . 'posts' ], $excluded )
			),
			ARRAY_A
		);
		$this->guard();

		$authors = [];
		foreach ( (array) $rows as $row ) {
			$authors[ (int) $row['post_author'] ] = (int) $row['total'];
		}
		return $authors;
	}
```

`includes/Scan/BatchRunner.php` :
- `use MultisiteRadar\Storage\AuthorsRepository;` ;
- propriété `private AuthorsRepository $authors;`, dernier paramètre du constructeur `AuthorsRepository $authors`, affecté comme les autres ;
- dans `scan_site()`, après `$this->extensions->replace_for_site( … );` : `$this->authors->replace_for_site( $site_id, $record->authors );`
- aux deux endroits où `$this->extensions->delete_for_site( $site_id );` est appelé, ajouter juste après : `$this->authors->delete_for_site( $site_id );`

`includes/Scan/Invalidation.php` :
- même ajout au constructeur (dernier paramètre `AuthorsRepository $authors`) ;
- dans la fonction anonyme qui supprime les lignes d'un site supprimé, après `$this->extensions->delete_for_site( (int) $site->blog_id );` : `$this->authors->delete_for_site( (int) $site->blog_id );`

`includes/Scan/Queue.php`, `on_upgraded()` :

```php
	public function on_upgraded( $version = 0, $previous = 0 ): void {
		$this->schedule();
		// 3 : mesures de M4 ; 5 : contenus publiés par auteur (rc.2). Une analyse complète les remplit.
		if ( (int) $previous < 5 ) {
			$this->request_full_scan( get_current_network_id() );
		}
	}
```

- [ ] **Step 5: Privacy**

`includes/Admin/Privacy.php`, `text()` : dans la chaîne, après « …and the number of users per role. », insérer :
« It also keeps, for each site, the number of published posts of each author. »
et après « When a user account is deleted, the sites it belonged to are analysed again. », insérer :
« Its Users screen shows the e-mail address of each account to the network administrators who can manage users; it is read from WordPress, never copied. »

`readme.txt`, section « = Privacy = » : après « …such as the user IDs and logins of administrators and editors, », ajouter « the number of published posts of each author on each site, » ; et avant « A text for your privacy policy… », ajouter la phrase « The Users screen shows the e-mail address of each account to the network administrators who can manage users, without copying it. ».

- [ ] **Step 6: Run the tests to verify they pass**

Run: `bin/test.sh --filter "SchemaTest|AuthorsRepositoryTest|SiteCollectorTest|BatchRunnerTest|InvalidationTest|QueueTest|UninstallTest|PrivacyTest|InstallerTest" && composer lint && composer analyse && npm run readme:check`
Expected: PASS (hors les 11 échecs connus de la base locale, cf. Global Constraints).

- [ ] **Step 7: Commit**

```bash
git add includes uninstall.php readme.txt tests/php
git commit -m "feat: count the published posts of each author during the analysis"
```

---

### Task 6: Comptes côté serveur — e-mail, noms, rôles, contenus publiés, route `users/{id}`, préférences

Spec rc.2 § 4.1 à § 4.3 ; écarts E1, E3 et E5.

**Files:**
- Modify : `includes/Storage/UsersRepository.php` (réécrit en entier, ci-dessous)
- Modify : `includes/Query/UsersQuery.php`, `includes/Query/Schemas.php` (`user()`, nouvelle `user_detail()`)
- Modify : `includes/Rest/UsersController.php`
- Modify : `includes/Settings/Preferences.php`, `includes/Plugin.php` (`users_query()`, hook `msradar_upgraded`)
- Modify : `includes/Admin/Assets.php` (configuration `canSeeEmails`), `src/admin/config.js` (`DEFAULTS`)
- Modify : `tests/fixtures/view-queries.json`
- Test : `tests/php/Query/UsersQueryTest.php`, `tests/php/Rest/UsersControllerTest.php`, `tests/php/Rest/ItemSchemasTest.php`, `tests/php/Settings/PreferencesTest.php`, `tests/php/Admin/AssetsTest.php`

**Interfaces:**
- Consumes : `AuthorsRepository` (tâche 5) : `totals()`, `for_user()`, `analysed_among()`, `is_empty()`, `CACHE_GROUP`.
- Produces :
  - `UsersQuery::ORDERBY = [ 'login', 'display_name', 'email', 'sites_count', 'published', 'registered' ]` ; argument `with_email` (bool) de `list()` ;
  - élément de `GET /users` : `id`, `login`, `display_name`, `email` (seulement avec `manage_network_users`), `first_name`, `last_name`, `roles` (`[ { role, label, sites } ]`, du plus fréquent au moins fréquent), `published` (int ou null), `super_admin`, `sites_count`, `registered_gmt`, `edit_url` ;
  - `UsersQuery::get( int $user_id, bool $with_email, int $limit = self::PANEL_SITES ): ?array` et `GET /users/{id}` : les champs de la liste sans `roles` ni `sites_count`, plus `sites` (`[ { id, name, admin_url, roles: [ { role, label } ], published } ]`, 200 au plus, par nom) et `sites_total` ;
  - `UsersQuery::PANEL_SITES = 200` ;
  - `Preferences::add_fields( string $view, array $fields ): void` et `Preferences::on_upgraded( $version, $previous ): void` ;
  - configuration JS `canSeeEmails` (bool).

- [ ] **Step 1: Write the failing tests**

`tests/php/Query/UsersQueryTest.php` :
- le test existant `test_counts_the_sites_of_every_account_without_email()` : garder son nom et ses assertions, il appelle `list()` sans `with_email` ; vérifier qu'il affirme bien l'absence de la clé `email` (sinon l'ajouter : `$this->assertArrayNotHasKey( 'email', $result['items'][0] );`) ;
- dans `set_up()`, donner un e-mail et des noms connus au compte `radar_solo` : ajouter `'user_email' => 'solo@radar.test'`, `'first_name' => 'Sol'`, `'last_name' => 'Oyster'` à son tableau de création ;
- nouveaux tests :

```php
	public function test_the_email_is_returned_and_searched_only_on_request(): void {
		$this->assertSame( 'solo@radar.test', $this->query()->list( [ 'search' => 'radar_solo', 'with_email' => true ] )['items'][0]['email'] );
		$this->assertSame( [ 'radar_solo' ], array_values( wp_list_pluck( $this->query()->list( [ 'search' => 'solo@radar', 'with_email' => true ] )['items'], 'login' ) ) );
		$this->assertSame( [], $this->query()->list( [ 'search' => 'solo@radar' ] )['items'] );
	}

	public function test_names_roles_and_search_by_first_or_last_name(): void {
		$solo  = $this->find( 'radar_solo' );
		$multi = $this->find( 'radar_multi' );

		$this->assertSame( 'Sol', $solo['first_name'] );
		$this->assertSame( 'Oyster', $solo['last_name'] );
		$this->assertSame( [ 'radar_solo' ], array_values( wp_list_pluck( $this->query()->list( [ 'search' => 'Oyst' ] )['items'], 'login' ) ) );
		$this->assertSame(
			[
				[
					'role'  => 'author',
					'label' => 'Author',
					'sites' => 2,
				],
			],
			$multi['roles']
		);
		$this->assertSame( [], $this->find( 'radar_nobody' )['roles'] );
	}

	public function test_published_content_is_null_until_a_site_is_analysed_then_counted(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \MultisiteRadar\Install\Schema::authors_table() ) );
		$this->assertNull( $this->find( 'radar_multi' )['published'] );

		$this->plugin()->authors()->replace_for_site( $this->site_a, [ $this->multi => 2 ] );
		$this->plugin()->authors()->replace_for_site( $this->site_b, [ $this->multi => 3 ] );

		$this->assertSame( 5, $this->find( 'radar_multi' )['published'] );
		$this->assertSame( 0, $this->find( 'radar_solo' )['published'] );
		// À égalité (0), l'ordre est celui des identifiants : radar_solo a été créé avant radar_nobody.
		$this->assertSame(
			[ 'radar_multi', 'radar_solo', 'radar_nobody' ],
			$this->logins(
				[
					'orderby' => 'published',
					'order'   => 'desc',
				]
			)
		);
	}

	public function test_sorting_by_email_needs_the_email(): void {
		$this->assertSame(
			$this->logins( [ 'orderby' => 'login' ] ),
			$this->logins( [ 'orderby' => 'email' ] )
		);
	}

	public function test_the_detail_lists_the_sites_with_role_and_published_content(): void {
		$this->plugin()->authors()->replace_for_site( $this->site_a, [ $this->multi => 2 ] );

		$detail = $this->query()->get( $this->multi, false );

		$this->assertSame( 'radar_multi', $detail['login'] );
		$this->assertArrayNotHasKey( 'email', $detail );
		$this->assertSame( 2, $detail['sites_total'] );
		$by_id = array_column( $detail['sites'], null, 'id' );
		$this->assertSame(
			[
				[
					'role'  => 'author',
					'label' => 'Author',
				],
			],
			$by_id[ $this->site_a ]['roles']
		);
		$this->assertSame( 2, $by_id[ $this->site_a ]['published'] );
		$this->assertNull( $by_id[ $this->site_b ]['published'] );
		$this->assertStringContainsString( '/wp-admin/', $by_id[ $this->site_a ]['admin_url'] );
		$this->assertSame( 'solo@radar.test', $this->query()->get( $this->solo, true )['email'] );
		$this->assertNull( $this->query()->get( 999999, true ) );
	}

	public function test_the_detail_lists_a_bounded_number_of_sites_and_counts_them_all(): void {
		$this->assertSame( 200, UsersQuery::PANEL_SITES );

		$detail = $this->query()->get( $this->multi, false, 1 );

		$this->assertSame( 2, $detail['sites_total'] );
		$this->assertCount( 1, $detail['sites'] );
	}
```

Le troisième paramètre de `get()` (le nombre de sites listés, `PANEL_SITES` par défaut) évite de créer 201 sites dans le test.

`tests/php/Rest/UsersControllerTest.php`, `test_lists_accounts_with_pagination_headers_and_validation()` :
- remplacer `$this->assertArrayNotHasKey( 'email', … );` et `$this->assertStringNotContainsString( '@', … );` par :

```php
		$this->assertArrayHasKey( 'email', $response->get_data()[0] );
```

- dans la liste des paramètres invalides, remplacer `[ 'orderby' => 'email' ]` par `[ 'orderby' => 'bogus' ]`.

Nouveaux tests :

```php
	public function test_only_accounts_that_can_manage_users_see_the_emails(): void {
		self::factory()->user->create(
			[
				'user_login' => 'radar_rest_mail',
				'user_email' => 'mail@radar.test',
			]
		);
		$map = static fn ( array $caps ): array => array_merge( $caps, [ \MultisiteRadar\Capabilities::VIEW => 'manage_options' ] );
		add_filter( 'msradar_capability_map', $map );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		try {
			$list = $this->request( 'GET', '/users', [ 'search' => 'radar_rest_mail' ] );
			$this->assertSame( 200, $list->get_status() );
			$this->assertArrayNotHasKey( 'email', $list->get_data()[0] );
			$this->assertSame( [], $this->request( 'GET', '/users', [ 'search' => 'mail@radar' ] )->get_data() );
			$this->assertStringNotContainsString( 'mail@radar.test', (string) wp_json_encode( $this->request( 'GET', '/users/' . $list->get_data()[0]['id'] )->get_data() ) );
		} finally {
			remove_filter( 'msradar_capability_map', $map );
		}
	}

	public function test_the_detail_route(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users/1' )->get_status() );
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/users/1' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['id'] );
		$this->assertArrayHasKey( 'email', $response->get_data() );

		$missing = $this->request( 'GET', '/users/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'msradar_user_not_found', $missing->get_data()['code'] );
	}
```

`tests/php/Rest/ItemSchemasTest.php`, `test_the_schema_of_the_detail_and_sub_routes_describes_what_they_return()` : ajouter le cas `'/users/1' => false,` au tableau `$cases`.

`tests/php/Settings/PreferencesTest.php`, nouveau test :

```php
	public function test_the_upgrade_to_version_5_adds_the_new_users_columns_once(): void {
		$chose = self::factory()->user->create();
		$kept  = self::factory()->user->create();
		$prefs = $this->plugin()->preferences();
		$prefs->update( $chose, [ 'users' => [ 'fields' => [ 'sites_count', 'registered_gmt' ] ] ] );
		$prefs->update( $kept, [ 'sites' => [ 'fields' => [ 'theme' ] ] ] );

		$prefs->on_upgraded( 5, 4 );

		$this->assertSame( [ 'display_name', 'email', 'sites_count', 'registered_gmt' ], $prefs->get( $chose )['users']['fields'] );
		$this->assertSame( [], $prefs->get( $kept )['users']['fields'] );

		$prefs->update( $chose, [ 'users' => [ 'fields' => [ 'sites_count' ] ] ] );
		$prefs->on_upgraded( 5, 5 );
		$this->assertSame( [ 'sites_count' ], $prefs->get( $chose )['users']['fields'] );
	}
```

`tests/php/Admin/AssetsTest.php`, dans `test_enqueues_the_view_bundle_with_its_safe_inline_configuration()`, après `$this->assertTrue( $config['canManage'] );` :

```php
		$this->assertTrue( $config['canSeeEmails'] );
```

`tests/fixtures/view-queries.json` :
- le cas « users: invalid values are ignored » : `"orderby": "email"` devient `"orderby": "bogus"` ;
- nouveau cas :

```json
	{
		"name": "users: sorted by e-mail or published content",
		"view": "users",
		"query": { "orderby": "published", "order": "desc" },
		"prefs": {},
		"args": { "page": 1, "per_page": 20, "orderby": "published", "order": "desc" }
	}
```

Run: `bin/test.sh --filter "UsersQueryTest|UsersControllerTest|ItemSchemasTest|PreferencesTest|AssetsTest|ViewQueryTest"`
Expected: FAIL.

- [ ] **Step 2: The repository**

Remplacer `includes/Storage/UsersRepository.php` par :

```php
<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Network-wide aggregation over the users, usermeta and blogs tables (sites per user, membership filters across all sites), which WP_User_Query cannot express without one query per site; read-only, and the query services cache what they need.

/**
 * SQL des comptes de l'installation (tables globales users, usermeta, blogs) : nombre de sites, rôles, noms et
 * contenus publiés (table msradar_site_authors).
 */
final class UsersRepository {

	/**
	 * Tri autorisé : clé publique => expression SQL.
	 */
	public const ORDERBY = [
		'login'        => 'u.user_login',
		'display_name' => 'u.display_name',
		'email'        => 'u.user_email',
		'sites_count'  => 'COALESCE(c.sites_count, 0)',
		'published'    => 'COALESCE(p.published, 0)',
		'registered'   => 'u.user_registered',
	];

	/**
	 * Comptes paginés en SQL, avec leur nombre de sites.
	 *
	 * @param array $args search, with_email (cherche aussi dans l'e-mail), membership ('' | none | several), logins (null ou string[]), orderby, order, page, per_page.
	 * @return array{items: array<int, array{id: int, login: string, display_name: string, email: string, registered: string, sites_count: int}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$membership = self::membership();
		$join       = $membership['join'] . ' WHERE ' . $membership['where'];
		$params     = array_merge( $membership['join_params'], $membership['where_params'] );

		$filtered   = '' !== (string) $args['membership'] && in_array( $args['membership'], [ 'none', 'several' ], true );
		$derived    = $filtered || 'sites_count' === $args['orderby'];
		$conditions = [];
		$where      = [];
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$columns = [ 'u.user_login LIKE %s', 'u.display_name LIKE %s' ];
			$values  = [ $like, $like ];
			if ( ! empty( $args['with_email'] ) ) {
				$columns[] = 'u.user_email LIKE %s';
				$values[]  = $like;
			}
			$columns[]  = "EXISTS (SELECT 1 FROM %i AS n WHERE n.user_id = u.ID AND n.meta_key IN ('first_name', 'last_name') AND n.meta_value LIKE %s)";
			$values[]   = $wpdb->usermeta;
			$values[]   = $like;
			$where[]    = '(' . implode( ' OR ', $columns ) . ')';
			$conditions = array_merge( $conditions, $values );
		}
		if ( 'none' === $args['membership'] ) {
			$where[] = 'c.sites_count IS NULL';
		} elseif ( 'several' === $args['membership'] ) {
			$where[] = 'c.sites_count >= 2';
		}
		if ( null !== $args['logins'] ) {
			$logins = array_values( array_map( 'strval', (array) $args['logins'] ) );
			// Une liste vide ne retient personne : jamais tous les comptes.
			$where[]    = [] === $logins ? '1 = 0' : 'u.user_login IN (' . implode( ',', array_fill( 0, count( $logins ), '%s' ) ) . ')';
			$conditions = array_merge( $conditions, $logins );
		}

		// Tri par contenus publiés : total de chaque compte sur les sites qui existent encore.
		$published        = 'published' === $args['orderby'];
		$published_join   = $published ? ' LEFT JOIN (SELECT a.user_id, SUM(a.published) AS published FROM %i AS a INNER JOIN %i AS ab ON ab.blog_id = a.site_id WHERE a.user_id > 0 GROUP BY a.user_id) AS p ON p.user_id = u.ID' : '';
		$published_params = $published ? [ Schema::authors_table(), $wpdb->blogs ] : [];

		$condition = [] === $where ? '1 = 1' : implode( ' AND ', $where );
		$sort      = self::ORDERBY[ $args['orderby'] ] ?? self::ORDERBY['login'];
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $join, $from et $condition ne contiennent que des fragments fixes et des placeholders ; $sort vient d'une liste blanche ; $direction vaut ASC ou DESC.
		if ( $derived ) {
			// Filtre d'appartenance ou tri par nombre de sites : la table dérivée agrège les appartenances de tous les comptes.
			$from   = "%i AS u LEFT JOIN (SELECT m.user_id, COUNT(DISTINCT b.blog_id) AS sites_count FROM %i AS m {$join} GROUP BY m.user_id) AS c ON c.user_id = u.ID{$published_join}";
			$prefix = array_merge( [ $wpdb->users, $wpdb->usermeta ], $params, $published_params );
			$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", array_merge( $prefix, $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_email, u.user_registered, COALESCE(c.sites_count, 0) AS sites_count FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( $prefix, $conditions, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
			self::check_read();
		} else {
			$from   = "%i AS u{$published_join}";
			$prefix = array_merge( [ $wpdb->users ], $published_params );
			$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", array_merge( $prefix, $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_email, u.user_registered FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( $prefix, $conditions, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
			self::check_read();
			$rows = (array) $rows;
			if ( [] !== $rows ) {
				// Les appartenances ne sont comptées que pour les comptes de la page.
				$ids    = array_map( static fn ( array $row ): int => (int) $row['ID'], $rows );
				$counts = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT m.user_id, COUNT(DISTINCT b.blog_id) AS sites_count FROM %i AS m ' . $join . ' AND m.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY m.user_id',
						array_merge( [ $wpdb->usermeta ], $params, $ids )
					),
					ARRAY_A
				);
				self::check_read();
				$by_user = [];
				foreach ( (array) $counts as $count ) {
					$by_user[ (int) $count['user_id'] ] = (int) $count['sites_count'];
				}
				foreach ( $rows as $i => $row ) {
					$rows[ $i ]['sites_count'] = $by_user[ (int) $row['ID'] ] ?? 0;
				}
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'items' => array_map(
				static fn ( array $row ): array => [
					'id'           => (int) $row['ID'],
					'login'        => (string) $row['user_login'],
					'display_name' => (string) $row['display_name'],
					'email'        => (string) $row['user_email'],
					'registered'   => (string) $row['user_registered'],
					'sites_count'  => (int) $row['sites_count'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}

	/**
	 * Un compte, ou null s'il n'existe pas.
	 *
	 * @return array{id: int, login: string, display_name: string, email: string, registered: string}|null
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ID, user_login, display_name, user_email, user_registered FROM %i WHERE ID = %d', $wpdb->users, $user_id ),
			ARRAY_A
		);
		self::check_read();
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'id'           => (int) $row['ID'],
			'login'        => (string) $row['user_login'],
			'display_name' => (string) $row['display_name'],
			'email'        => (string) $row['user_email'],
			'registered'   => (string) $row['user_registered'],
		];
	}

	/**
	 * Prénom et nom des comptes donnés (chaînes vides par défaut).
	 *
	 * @param int[] $ids
	 * @return array<int, array{first_name: string, last_name: string}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function names( array $ids ): array {
		global $wpdb;
		$ids   = self::ids( $ids );
		$names = array_fill_keys(
			$ids,
			[
				'first_name' => '',
				'last_name'  => '',
			]
		);
		if ( [] === $ids ) {
			return $names;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value FROM %i WHERE meta_key IN ('first_name', 'last_name') AND user_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ $wpdb->usermeta ], $ids )
			),
			ARRAY_A
		);
		self::check_read();
		foreach ( (array) $rows as $row ) {
			$names[ (int) $row['user_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		return $names;
	}

	/**
	 * Rôles des comptes donnés : identifiant du rôle => nombre de sites (qui existent encore) où le compte l'a.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, int>>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function roles( array $ids ): array {
		$roles = [];
		foreach ( $this->memberships_of( $ids ) as $user_id => $sites ) {
			foreach ( $sites as $site ) {
				foreach ( $site['roles'] as $role ) {
					$roles[ $user_id ][ $role ] = ( $roles[ $user_id ][ $role ] ?? 0 ) + 1;
				}
			}
		}
		return $roles;
	}

	/**
	 * Sites d'un compte, par identifiant croissant, avec ses rôles, le nom et l'adresse relevés par l'analyse
	 * (vides pour un site jamais analysé), le domaine et le chemin.
	 *
	 * @return array<int, array{site_id: int, name: string, siteurl: string, domain: string, path: string, roles: string[]}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function sites_of( int $user_id ): array {
		return array_values( $this->memberships_of( [ $user_id ] )[ $user_id ] ?? [] );
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array<int, array{site_id: int, name: string, siteurl: string, domain: string, path: string, roles: string[]}>> Compte => site => appartenance.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	private function memberships_of( array $ids ): array {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( [] === $ids ) {
			return [];
		}
		$membership = self::membership();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The joined fragments are fixed strings with placeholders (self::membership()); the IN list holds one %d per id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.user_id, b.blog_id, b.domain, b.path, s.name, s.siteurl, m.meta_value FROM %i AS m ' . $membership['join'] . ' LEFT JOIN %i AS s ON s.site_id = b.blog_id WHERE ' . $membership['where'] . ' AND m.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY m.user_id ASC, b.blog_id ASC',
				array_merge( [ $wpdb->usermeta ], $membership['join_params'], [ Schema::sites_table() ], $membership['where_params'], $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		self::check_read();

		$out = [];
		foreach ( (array) $rows as $row ) {
			$user = (int) $row['user_id'];
			$site = (int) $row['blog_id'];
			// {base}capabilities et {base}1_capabilities désignent tous deux le site 1 : une seule fois.
			if ( isset( $out[ $user ][ $site ] ) ) {
				continue;
			}
			$out[ $user ][ $site ] = [
				'site_id' => $site,
				'name'    => (string) ( $row['name'] ?? '' ),
				'siteurl' => (string) ( $row['siteurl'] ?? '' ),
				'domain'  => (string) $row['domain'],
				'path'    => (string) $row['path'],
				'roles'   => self::role_slugs( $row['meta_value'] ),
			];
		}
		return $out;
	}

	/**
	 * Jointure d'une ligne usermeta {base}capabilities (site 1) ou {base}{id}_capabilities avec un site qui existe
	 * encore dans blogs (la règle de get_blogs_of_user()). Le préfixe ne contient que [A-Za-z0-9_] (WordPress le
	 * vérifie), il peut donc entrer tel quel dans l'expression régulière.
	 *
	 * @return array{join: string, where: string, join_params: array, where_params: array}
	 */
	private static function membership(): array {
		global $wpdb;
		$base = $wpdb->base_prefix;
		return [
			'join'         => 'INNER JOIN %i AS b ON b.blog_id = (CASE WHEN m.meta_key = %s THEN 1 ELSE CAST(SUBSTRING_INDEX(SUBSTRING(m.meta_key, %d), %s, 1) AS UNSIGNED) END)',
			'where'        => 'm.meta_key LIKE %s AND m.meta_key REGEXP %s',
			'join_params'  => [ $wpdb->blogs, $base . 'capabilities', strlen( $base ) + 1, '_' ],
			'where_params' => [ $wpdb->esc_like( $base ) . '%capabilities', '^' . $base . '([0-9]+_)?capabilities$' ],
		];
	}

	/**
	 * Rôles d'une valeur {base}…capabilities : les clés accordées (WordPress y range les rôles du compte).
	 *
	 * @param mixed $value Valeur brute de usermeta.
	 * @return string[]
	 */
	private static function role_slugs( $value ): array {
		$caps = maybe_unserialize( (string) $value );
		if ( ! is_array( $caps ) ) {
			return [];
		}
		$roles = [];
		foreach ( $caps as $role => $granted ) {
			if ( $granted && is_string( $role ) && '' !== $role ) {
				$roles[] = $role;
			}
		}
		return $roles;
	}

	/**
	 * @param int[] $ids
	 * @return int[] Identifiants positifs, sans doublon.
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
```

Run: `bin/test.sh --filter UsersQueryTest`
Expected: les tests existants de `UsersQueryTest` passent toujours (la jointure d'appartenance est la même, découpée en deux) ; les nouveaux échouent encore (la requête ne les expose pas).

- [ ] **Step 3: The query service**

Dans `includes/Query/UsersQuery.php` :
- docblock de classe : « Comptes de l'installation, nombre de sites, rôles, noms et contenus publiés de chacun, tous réseaux confondus (écart E3 du plan M3). L'e-mail n'est renvoyé que sur demande (`with_email`, droit `manage_network_users` vérifié par la route). Les résultats restent 10 minutes en cache objet ; la clé change dès qu'un compte ou une appartenance change (last_changed « users »), qu'un site est créé ou supprimé (« sites »), qu'une analyse relève les auteurs (« msradar_authors »), ou que la liste des super-admins change. »
- `use MultisiteRadar\Storage\AuthorsRepository;`
- constantes et constructeur :

```php
	public const ORDERBY     = [ 'login', 'display_name', 'email', 'sites_count', 'published', 'registered' ];
	public const MEMBERSHIPS = [ 'none', 'several' ];
	public const CACHE_GROUP = 'msradar';
	public const CACHE_TTL   = 600;

	/**
	 * Sites listés au plus dans la fiche d'un compte.
	 */
	public const PANEL_SITES = 200;

	private UsersRepository $users;
	private AuthorsRepository $authors;

	public function __construct( UsersRepository $users, AuthorsRepository $authors ) {
		$this->users   = $users;
		$this->authors = $authors;
	}
```

- `defaults()` : ajouter `'with_email' => false,`.
- `list()` :

```php
	public function list( array $args ): array {
		$args       = array_merge( self::defaults(), $args );
		$with_email = (bool) $args['with_email'];
		$supers     = self::super_admins();
		$orderby    = (string) $args['orderby'];
		if ( ! in_array( $orderby, self::ORDERBY, true ) || ( 'email' === $orderby && ! $with_email ) ) {
			$orderby = 'login';
		}
		$query = [
			'search'     => trim( (string) $args['search'] ),
			'with_email' => $with_email,
			'membership' => in_array( $args['membership'], self::MEMBERSHIPS, true ) ? (string) $args['membership'] : '',
			'logins'     => $args['super_admin'] ? $supers : null,
			'orderby'    => $orderby,
			'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
		];

		$key    = 'users:' . md5( (string) wp_json_encode( [ $query, $supers ] ) ) . ':' . wp_cache_get_last_changed( 'users' ) . ':' . wp_cache_get_last_changed( 'sites' ) . ':' . wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result  = $this->users->query( $query );
		$ids     = array_map( static fn ( array $row ): int => $row['id'], $result['items'] );
		$names   = $this->users->names( $ids );
		$roles   = $this->users->roles( $ids );
		$counted = ! $this->authors->is_empty();
		$totals  = $counted ? $this->authors->totals( $ids ) : [];
		$labels  = self::role_labels();
		$items   = [];
		foreach ( $result['items'] as $row ) {
			$item = [
				'id'             => $row['id'],
				'login'          => $row['login'],
				'display_name'   => $row['display_name'],
				'first_name'     => $names[ $row['id'] ]['first_name'] ?? '',
				'last_name'      => $names[ $row['id'] ]['last_name'] ?? '',
				'roles'          => self::roles_summary( $roles[ $row['id'] ] ?? [], $labels ),
				'published'      => $counted ? ( $totals[ $row['id'] ] ?? 0 ) : null,
				'super_admin'    => in_array( $row['login'], $supers, true ),
				'sites_count'    => $row['sites_count'],
				'registered_gmt' => self::registered( $row['registered'] ),
				'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $row['id'] ),
			];
			if ( $with_email ) {
				$item['email'] = $row['email'];
			}
			$items[] = $item;
		}
		$list = [
			'items' => $items,
			'total' => $result['total'],
		];
		wp_cache_set( $key, $list, self::CACHE_GROUP, self::CACHE_TTL );
		return $list;
	}
```

- nouvelles méthodes :

```php
	/**
	 * Fiche d'un compte : identité, et ses sites ($limit au plus, par nom) avec son rôle et ses contenus publiés sur
	 * chacun ; null si le compte n'existe pas.
	 *
	 * @param int $limit Sites listés au plus ; sites_total les compte tous.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function get( int $user_id, bool $with_email, int $limit = self::PANEL_SITES ): ?array {
		$user = $this->users->find( $user_id );
		if ( null === $user ) {
			return null;
		}
		$names    = $this->users->names( [ $user_id ] )[ $user_id ];
		$sites    = $this->users->sites_of( $user_id );
		$counted  = ! $this->authors->is_empty();
		$by_site  = $counted ? $this->authors->for_user( $user_id ) : [];
		$analysed = $counted ? $this->authors->analysed_among( array_column( $sites, 'site_id' ) ) : [];
		$labels   = self::role_labels();

		$items = [];
		foreach ( $sites as $site ) {
			$items[] = [
				'id'        => $site['site_id'],
				'name'      => '' !== $site['name'] ? $site['name'] : $site['domain'] . untrailingslashit( $site['path'] ),
				'admin_url' => '' !== $site['siteurl'] ? trailingslashit( $site['siteurl'] ) . 'wp-admin/' : get_admin_url( $site['site_id'] ),
				'roles'     => array_map(
					static fn ( string $role ): array => [
						'role'  => $role,
						'label' => $labels[ $role ] ?? $role,
					],
					$site['roles']
				),
				'published' => isset( $analysed[ $site['site_id'] ] ) ? ( $by_site[ $site['site_id'] ] ?? 0 ) : null,
			];
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$name = strnatcasecmp( $a['name'], $b['name'] );
				return 0 !== $name ? $name : $a['id'] <=> $b['id'];
			}
		);

		$detail = [
			'id'             => $user['id'],
			'login'          => $user['login'],
			'display_name'   => $user['display_name'],
			'first_name'     => $names['first_name'],
			'last_name'      => $names['last_name'],
			'super_admin'    => in_array( $user['login'], self::super_admins(), true ),
			'registered_gmt' => self::registered( $user['registered'] ),
			'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $user['id'] ),
			'published'      => $counted ? array_sum( $by_site ) : null,
			'sites'          => array_slice( $items, 0, max( 1, $limit ) ),
			'sites_total'    => count( $items ),
		];
		if ( $with_email ) {
			$detail['email'] = $user['email'];
		}
		return $detail;
	}

	/**
	 * @return string[] Identifiants des super-admins.
	 */
	private static function super_admins(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_super_admins() ), static fn ( string $login ): bool => '' !== $login ) );
	}

	private static function registered( string $value ): ?string {
		return '' !== $value && '0000-00-00 00:00:00' !== $value ? mysql_to_rfc3339( $value ) : null;
	}

	/**
	 * Nom traduit de chaque rôle connu du site courant (le site principal, dans l'administration du réseau).
	 *
	 * @return array<string, string>
	 */
	private static function role_labels(): array {
		$labels = [];
		foreach ( wp_roles()->role_names as $role => $name ) {
			$labels[ (string) $role ] = translate_user_role( (string) $name );
		}
		return $labels;
	}

	/**
	 * @param array<string, int>    $counts Rôle => nombre de sites.
	 * @param array<string, string> $labels Rôle => nom traduit.
	 * @return array<int, array{role: string, label: string, sites: int}> Du rôle le plus fréquent au moins fréquent, puis par nom.
	 */
	private static function roles_summary( array $counts, array $labels ): array {
		$roles = [];
		foreach ( $counts as $role => $sites ) {
			$roles[] = [
				'role'  => (string) $role,
				'label' => $labels[ $role ] ?? (string) $role,
				'sites' => (int) $sites,
			];
		}
		usort(
			$roles,
			static function ( array $a, array $b ): int {
				$by_sites = $b['sites'] <=> $a['sites'];
				return 0 !== $by_sites ? $by_sites : strnatcasecmp( $a['label'], $b['label'] );
			}
		);
		return $roles;
	}
```

Dans `list()`, l'ancienne ligne qui calculait `$supers` et l'ancienne conversion de `registered` sont remplacées par `self::super_admins()` et `self::registered()`.

`includes/Plugin.php`, `users_query()` : `new UsersQuery( $this->users_repository(), $this->authors() )`.

- [ ] **Step 4: REST**

`includes/Query/Schemas.php` :
- `user()` :

```php
	public static function user(): array {
		return self::object(
			[
				'id'             => self::type( 'integer' ),
				'login'          => self::type( 'string' ),
				'display_name'   => self::type( 'string' ),
				'email'          => self::type( 'string' ),
				'first_name'     => self::type( 'string' ),
				'last_name'      => self::type( 'string' ),
				'roles'          => self::list_of(
					self::object(
						[
							'role'  => self::type( 'string' ),
							'label' => self::type( 'string' ),
							'sites' => self::type( 'integer' ),
						]
					)
				),
				'published'      => self::type( [ 'integer', 'null' ] ),
				'super_admin'    => self::type( 'boolean' ),
				'sites_count'    => self::type( 'integer' ),
				'registered_gmt' => self::date(),
				'edit_url'       => self::type( 'string' ),
			]
		);
	}

	/**
	 * Fiche d'un compte (UsersQuery::get()) : l'identité de user(), sans résumé des rôles, avec ses sites.
	 */
	public static function user_detail(): array {
		$detail = self::user();
		unset( $detail['properties']['roles'], $detail['properties']['sites_count'] );
		$detail['properties']['sites']       = self::list_of(
			self::object(
				[
					'id'        => self::type( 'integer' ),
					'name'      => self::type( 'string' ),
					'admin_url' => self::type( 'string' ),
					'roles'     => self::list_of(
						self::object(
							[
								'role'  => self::type( 'string' ),
								'label' => self::type( 'string' ),
							]
						)
					),
					'published' => self::type( [ 'integer', 'null' ] ),
				]
			)
		);
		$detail['properties']['sites_total'] = self::type( 'integer' );
		return $detail;
	}
```

`includes/Rest/UsersController.php` :
- docblock de classe : « GET /users : comptes de l'installation, nombre de sites, rôles, noms, contenus publiés ; GET /users/{id} : fiche d'un compte. L'e-mail n'est renvoyé qu'à un compte qui a le droit manage_network_users (écart E1 du plan rc.2). »
- dans `register_routes()`, après la route de la liste :

```php
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'id' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_detail_schema' ],
			]
		);
```

- dans `get_items()`, ajouter au tableau passé à `list()` : `'with_email'  => self::can_see_emails(),`
- nouvelles méthodes :

```php
	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->guard(
			function () use ( $request ) {
				$item = $this->users->get( (int) $request['id'], self::can_see_emails() );
				if ( null === $item ) {
					return new WP_Error( 'msradar_user_not_found', __( 'Account not found.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return new WP_REST_Response( $item );
			}
		);
	}

	public function get_detail_schema(): array {
		return Schemas::for_rest( 'msradar-user-detail', Schemas::user_detail() );
	}

	/**
	 * Le droit de WordPress qui montre déjà les e-mails dans Réseau > Utilisateurs.
	 */
	private static function can_see_emails(): bool {
		return current_user_can( 'manage_network_users' );
	}
```

Si `guard()` est typé pour ne renvoyer que `WP_REST_Response`, faire comme `SitesController::get_item()`, qui renvoie déjà un `WP_Error` depuis sa fonction anonyme.

- [ ] **Step 5: Preferences and configuration**

`includes/Settings/Preferences.php`, nouvelles méthodes publiques :

```php
	/**
	 * Mise à niveau du schéma : une colonne ajoutée aux valeurs par défaut d'une vue apparaît aussi chez ceux qui ont
	 * déjà choisi leurs colonnes. 5 : nom public et e-mail dans Comptes (écart E3 du plan rc.2).
	 *
	 * @param int|string $version  Nouvelle version du schéma.
	 * @param int|string $previous Version précédente (0 : première installation, sans préférences).
	 */
	public function on_upgraded( $version = 0, $previous = 0 ): void {
		if ( (int) $previous > 0 && (int) $previous < 5 ) {
			$this->add_fields( 'users', [ 'display_name', 'email' ] );
		}
	}

	/**
	 * Ajoute des colonnes, en tête et sans doublon, à la liste enregistrée d'une vue, pour chaque compte qui en a
	 * choisi une. Une liste vide (valeurs par défaut) ne change pas.
	 *
	 * @param string[] $fields
	 */
	public function add_fields( string $view, array $fields ): void {
		$users = get_users(
			[
				'blog_id'  => 0,
				'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off schema upgrade over the few accounts that saved display preferences.
				'fields'   => 'ID',
				'number'   => -1,
			]
		);
		foreach ( $users as $user_id ) {
			$stored = get_user_meta( (int) $user_id, self::META, true );
			if ( ! is_array( $stored ) || ! isset( $stored[ $view ]['fields'] ) || ! is_array( $stored[ $view ]['fields'] ) || [] === $stored[ $view ]['fields'] ) {
				continue;
			}
			$missing = array_values( array_diff( $fields, $stored[ $view ]['fields'] ) );
			if ( [] === $missing ) {
				continue;
			}
			$stored[ $view ]['fields'] = array_values( array_merge( $missing, $stored[ $view ]['fields'] ) );
			update_user_meta( (int) $user_id, self::META, $stored );
		}
	}
```

`includes/Plugin.php`, dans `boot()`, après `add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );` :

```php
		add_action( 'msradar_upgraded', [ $this->preferences(), 'on_upgraded' ], 10, 2 );
```

`includes/Admin/Assets.php`, dans le tableau `$config` : `'canSeeEmails' => current_user_can( 'manage_network_users' ),` après `canManage`.

`src/admin/config.js`, `DEFAULTS` : `canSeeEmails: false,` après `canManage`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `bin/test.sh --filter "UsersQueryTest|UsersControllerTest|ItemSchemasTest|PreferencesTest|AssetsTest|ViewQueryTest|PreloadTest" && composer lint && composer analyse`
Expected: PASS. `ViewQueryTest` lit le cas « users: sorted by e-mail or published content » : la liste blanche PHP en dérive de `UsersQuery::ORDERBY`. Le test JS de parité passera après la tâche 7.

- [ ] **Step 7: Commit**

```bash
git add includes src/admin/config.js tests/php tests/fixtures/view-queries.json
git commit -m "feat: show e-mail, names, roles and published posts of each account, and add the account detail route"
```

---

### Task 7: Comptes côté interface — colonnes, panneau du compte

Spec rc.2 § 2.1, § 2.2, § 4.1 à § 4.3 ; écart E5.

**Files:**
- Modify : `src/views/users/fields.jsx`, `src/views/users/query.js`, `src/views/users/index.jsx`
- Create : `src/views/user-panel/index.jsx`
- Test : `src/views/users/test/query.test.js`, `src/views/users/test/users-view.test.jsx`, `src/views/user-panel/test/user-panel.test.jsx` (nouveau)

**Interfaces:**
- Consumes : `DataViews` (tâche 1) ; `GET /users` et `GET /users/{id}` (tâche 6) ; `getConfig().canSeeEmails` ; `SidePanel`, `Skeleton`, `ErrorNotice`, `useResource`, `buildPath`, `pageUrl`, `formatNumber`.
- Produces :
  - `getUsersFields( { canSeeEmails } )` ; `rolesSummary( roles ): string` ;
  - `USER_ORDERBY` avec `email` et `published` ; `DEFAULT_USER_FIELDS = [ 'display_name', 'email', 'super_admin', 'sites_count', 'registered_gmt' ]` ;
  - état de Comptes avec `user` (number, 0 = fermé), paramètre d'adresse `user` ; `toUsersView( state, usersPrefs, available )` ;
  - `UserPanel` (`userId`, `title`, `onClose`).

- [ ] **Step 1: Write the failing tests**

Dans `src/views/users/test/query.test.js`, ajouter (avec les imports nécessaires depuis `../query`) :

```js
test( 'the open account lives in the address', () => {
	expect( parseUsersQuery( { user: '7' } ).user ).toBe( 7 );
	expect( parseUsersQuery( { user: 'x' } ).user ).toBe( 0 );
	expect(
		serializeUsersState( { ...parseUsersQuery( {} ), user: 7 } ).user
	).toBe( '7' );
} );

test( 'the e-mail is shown by default, and only the available columns are kept', () => {
	const state = parseUsersQuery( {} );
	expect( toUsersView( state, null, null ).fields ).toEqual( [
		'display_name',
		'email',
		'super_admin',
		'sites_count',
		'registered_gmt',
	] );
	expect(
		toUsersView( state, null, [ 'display_name', 'sites_count' ] ).fields
	).toEqual( [ 'display_name', 'sites_count' ] );
} );
```

`src/views/user-panel/test/user-panel.test.jsx` :

```jsx
import { expect, test, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { createCoreStore } from '../../../store';
import UserPanel from '..';

function renderPanel( body ) {
	const preload = {
		'/multisite-radar/v1/users/7': { body, headers: {} },
	};
	window.msradarAdmin = { pages: {}, preload };
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<UserPanel userId={ 7 } title="jo" onClose={ vi.fn() } />
		</RegistryProvider>
	);
}

const ACCOUNT = {
	id: 7,
	login: 'jo',
	display_name: 'Jo Martin',
	first_name: 'Jo',
	last_name: 'Martin',
	email: 'jo@example.test',
	super_admin: false,
	registered_gmt: '2026-01-01T10:00:00',
	edit_url: 'https://example.test/wp-admin/network/user-edit.php?user_id=7',
	published: 5,
	sites: [
		{
			id: 2,
			name: 'Blog RH',
			admin_url: 'https://example.test/rh/wp-admin/',
			roles: [ { role: 'editor', label: 'Editor' } ],
			published: 5,
		},
		{
			id: 3,
			name: 'Atelier',
			admin_url: 'https://example.test/atelier/wp-admin/',
			roles: [ { role: 'subscriber', label: 'Subscriber' } ],
			published: null,
		},
	],
	sites_total: 4,
};

test( 'the account panel shows the identity, the sites with their role and how many more there are', () => {
	renderPanel( ACCOUNT );

	expect( screen.getByRole( 'dialog', { name: 'Jo Martin' } ) ).toBeInTheDocument();
	expect( screen.getByText( 'jo@example.test' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Blog RH' } ) ).toHaveAttribute(
		'href',
		'https://example.test/rh/wp-admin/'
	);
	expect( screen.getByText( /Editor/ ) ).toBeInTheDocument();
	expect( screen.getByText( 'And 2 more sites.' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Edit the account' } ) ).toHaveAttribute(
		'href',
		ACCOUNT.edit_url
	);
} );

test( 'without the e-mail the panel shows no e-mail row', () => {
	const { email, ...withoutEmail } = ACCOUNT;
	renderPanel( { ...withoutEmail, sites_total: 2 } );

	expect( screen.queryByText( 'Email' ) ).not.toBeInTheDocument();
	expect( screen.queryByText( /more site/ ) ).not.toBeInTheDocument();
	expect( email ).toBe( 'jo@example.test' );
} );
```

Dans `src/views/users/test/users-view.test.jsx` :
- déplacer l'objet `preload` du premier test dans une fonction `usersPreload()` en haut du fichier (elle renvoie un objet neuf à chaque appel), et l'utiliser dans ce test ;
- les deux comptes préchargés gagnent `first_name: ''`, `last_name: ''`, `roles: []`, `published: null`, et un e-mail : `email: 'admin@example.test'` pour `admin`, `email: 'orphan@example.test'` pour `orphan` ; le chemin préchargé ne change pas (l'e-mail n'est pas un argument) ;
- `window.msradarAdmin` gagne `canSeeEmails: true` ;
- importer `fireEvent` avec `act`, `render` et `screen` ;
- nouveau test :

```jsx
test( 'a click on a row opens the account panel', async () => {
	const preload = usersPreload();
	window.msradarAdmin = {
		view: 'users',
		canManage: true,
		canSeeEmails: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<UsersView />
		</RegistryProvider>
	);
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );

	fireEvent.click( screen.getByText( 'admin@example.test' ) );

	// Le panneau demande /users/1 : apiFetch, simulé, ne répond jamais ; le titre vient du nom public de la ligne.
	expect( screen.getByRole( 'dialog', { name: 'Admin' } ) ).toBeInTheDocument();
	expect( window.location.search ).toContain( 'user=1' );
} );
```

Run: `npx wp-scripts test-unit-js src/views/users src/views/user-panel`
Expected: FAIL.

- [ ] **Step 2: Query state and columns**

`src/views/users/query.js` :

```js
export const USER_ORDERBY = [
	'login',
	'display_name',
	'email',
	'sites_count',
	'published',
	'registered',
];
export const MEMBERSHIPS = [ 'none', 'several' ];
export const SUPER_ADMINS = 'yes';
export const DEFAULT_USER_FIELDS = [
	'display_name',
	'email',
	'super_admin',
	'sites_count',
	'registered_gmt',
];
export const FIELD_TO_ORDERBY = {
	login: 'login',
	display_name: 'display_name',
	email: 'email',
	sites_count: 'sites_count',
	published: 'published',
	registered_gmt: 'registered',
};
```

- `parseUsersQuery()` ajoute `user`, comme le site d'Alertes :

```js
	const user = text( query, 'user' );
	// … dans l'objet renvoyé :
		user: /^\d+$/.test( user ) && Number( user ) > 0 ? Number( user ) : 0,
```

- `serializeUsersState()` ajoute, avant `return out;` :

```js
	if ( state.user ) {
		out.user = String( state.user );
	}
```

- `toUsersView( state, usersPrefs, available = null )` : la clé `fields` devient

```js
		fields: (
			usersPrefs?.fields?.length ? usersPrefs.fields : DEFAULT_USER_FIELDS
		).filter( ( id ) => ! available || available.includes( id ) ),
```

et la docblock décrit `available` : « identifiants des colonnes proposées (sans E-mail pour qui ne peut pas le voir) ; null : toutes ».

`src/views/users/fields.jsx` :
- la signature devient `export function getUsersFields( { canSeeEmails = false } = {} )` ;
- `display_name` : libellé `__( 'Public name', 'multisite-radar' )` ;
- après `display_name`, insérer :

```jsx
		...( canSeeEmails
			? [
					{
						id: 'email',
						type: 'text',
						label: __( 'Email', 'multisite-radar' ),
						filterBy: false,
						render: ( { item } ) => item.email || '—',
					},
			  ]
			: [] ),
		{
			id: 'full_name',
			type: 'text',
			label: __( 'First and last name', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) =>
				[ item.first_name, item.last_name ].filter( Boolean ).join( ' ' ),
			render: ( { item } ) =>
				[ item.first_name, item.last_name ].filter( Boolean ).join( ' ' ) ||
				'—',
		},
		{
			id: 'roles',
			type: 'text',
			label: __( 'Roles', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => rolesSummary( item.roles ),
			render: ( { item } ) => rolesSummary( item.roles ) || '—',
		},
		{
			id: 'published',
			type: 'integer',
			label: __( 'Published content', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) =>
				item.published === null || item.published === undefined
					? '—'
					: formatNumber( item.published ),
		},
```

- nouvelle fonction exportée, avant `getUsersFields()` :

```jsx
/**
 * Rôles d'un compte, du plus fréquent au moins fréquent : « Administrator on 3 sites, Editor on 1 site ».
 *
 * @param {Array} roles Liste de { label, sites } (GET /users).
 */
export function rolesSummary( roles = [] ) {
	return roles
		.map( ( role ) =>
			sprintf(
				/* translators: 1: role name, 2: number of sites. */
				_n(
					'%1$s on %2$d site',
					'%1$s on %2$d sites',
					role.sites,
					'multisite-radar'
				),
				role.label,
				role.sites
			)
		)
		.join( ', ' );
}
```

(imports : `_n`, `sprintf` en plus de `__`.)

- [ ] **Step 3: The account panel**

`src/views/user-panel/index.jsx` :

```jsx
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import SidePanel from '../../components/side-panel';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import { formatNumber } from '../../utils/format';
import { DateCell } from '../sites/fields';

/**
 * Fiche d'un compte (GET /users/{id}) : identité, e-mail si le compte connecté peut le voir, et ses sites avec son
 * rôle et ses contenus publiés sur chacun. Chaque site mène à son tableau de bord.
 *
 * @param {Object}     props
 * @param {number}     props.userId Identifiant du compte.
 * @param {string}     props.title  Titre en attendant la fiche (nom de la ligne).
 * @param {() => void} props.onClose Ferme le panneau.
 */
export default function UserPanel( { userId, title, onClose } ) {
	const user = useResource( buildPath( `/users/${ userId }` ) );
	const data = user.data;
	const name = data ? data.display_name || data.login : title;
	const fullName = data
		? [ data.first_name, data.last_name ].filter( Boolean ).join( ' ' )
		: '';
	const more = data ? data.sites_total - data.sites.length : 0;

	return (
		<SidePanel
			title={ name }
			focusKey={ userId }
			onClose={ onClose }
			actions={
				data ? (
					<Button variant="secondary" href={ data.edit_url }>
						{ __( 'Edit the account', 'multisite-radar' ) }
					</Button>
				) : null
			}
		>
			<ErrorNotice error={ user.error } onRetry={ user.retry } />
			{ ! data && ! user.error && (
				<Skeleton
					lines={ 5 }
					label={ __( 'Loading the account…', 'multisite-radar' ) }
				/>
			) }
			{ data && (
				<>
					<dl className="msradar-facts">
						<dt>{ __( 'Login', 'multisite-radar' ) }</dt>
						<dd>{ data.login }</dd>
						{ fullName && (
							<>
								<dt>
									{ __( 'First and last name', 'multisite-radar' ) }
								</dt>
								<dd>{ fullName }</dd>
							</>
						) }
						{ data.email !== undefined && (
							<>
								<dt>{ __( 'Email', 'multisite-radar' ) }</dt>
								<dd>{ data.email }</dd>
							</>
						) }
						<dt>{ __( 'Registered', 'multisite-radar' ) }</dt>
						<dd>
							<DateCell value={ data.registered_gmt } />
						</dd>
						<dt>{ __( 'Super admin', 'multisite-radar' ) }</dt>
						<dd>
							{ data.super_admin
								? __( 'Yes', 'multisite-radar' )
								: __( 'No', 'multisite-radar' ) }
						</dd>
						<dt>{ __( 'Published content', 'multisite-radar' ) }</dt>
						<dd>
							{ data.published === null
								? '—'
								: formatNumber( data.published ) }
						</dd>
					</dl>
					<h3>{ __( 'Sites', 'multisite-radar' ) }</h3>
					{ data.sites.length === 0 ? (
						<p>{ __( 'No site', 'multisite-radar' ) }</p>
					) : (
						<ul className="msradar-list">
							{ data.sites.map( ( site ) => (
								<li key={ site.id }>
									<a href={ site.admin_url }>{ site.name }</a>
									{ ' — ' }
									{ site.roles
										.map( ( role ) => role.label )
										.join( ', ' ) || '—' }
									{ site.published !== null &&
										` · ${ sprintf(
											/* translators: %s: number of published posts. */
											_n(
												'%s published',
												'%s published',
												site.published,
												'multisite-radar'
											),
											formatNumber( site.published )
										) }` }
								</li>
							) ) }
						</ul>
					) }
					{ more > 0 && (
						<p>
							{ sprintf(
								/* translators: %d: number of sites not listed. */
								_n(
									'And %d more site.',
									'And %d more sites.',
									more,
									'multisite-radar'
								),
								more
							) }
						</p>
					) }
				</>
			) }
		</SidePanel>
	);
}
```

- [ ] **Step 4: The Users screen**

Dans `src/views/users/index.jsx` :
- imports : `useCallback` ; `getConfig` depuis `'../../admin/config'` ; `UserPanel` depuis `'../user-panel'` ;
- dans `UsersView` :

```jsx
	const { canSeeEmails } = getConfig();
	// …
	const fields = useMemo(
		() => getUsersFields( { canSeeEmails } ),
		[ canSeeEmails ]
	);
	const available = useMemo(
		() => fields.map( ( field ) => field.id ),
		[ fields ]
	);
	const view = useMemo(
		() => toUsersView( state, usersPrefs, available ),
		[ state, usersPrefs, available ]
	);
	const openUser = useCallback(
		( item ) => setState( ( current ) => ( { ...current, user: item.id } ) ),
		[ setState ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'open',
				label: __( 'View the account', 'multisite-radar' ),
				icon: info,
				callback: ( [ item ] ) => openUser( item ),
			},
			{
				id: 'edit',
				label: __( 'Edit the account', 'multisite-radar' ),
				icon: pencil,
				callback: ( [ item ] ) =>
					window.location.assign( item.edit_url ),
			},
		],
		[ openUser ]
	);
	const openRow = ( list.data || [] ).find(
		( item ) => item.id === state.user
	);
```

(`info` s'importe de `@wordpress/icons` avec `pencil`.)
- sur `<DataViews>` : `onClickItem={ openUser }` ;
- après `</DataViews>` :

```jsx
			{ state.user > 0 && (
				<UserPanel
					userId={ state.user }
					title={
						openRow
							? openRow.display_name || openRow.login
							: __( 'Account', 'multisite-radar' )
					}
					onClose={ () =>
						setState( ( current ) => ( { ...current, user: 0 } ) )
					}
				/>
			) }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx wp-scripts test-unit-js src/views/users src/views/user-panel && npm run test:unit && npm run lint:js && bin/test.sh --filter ViewQueryTest`
Expected: PASS (dont la parité JS/PHP du cas « users: sorted by e-mail or published content »).

- [ ] **Step 6: Commit**

```bash
git add src/views/users src/views/user-panel
git commit -m "feat: show the new account columns and open an account panel from the Users screen"
```

---

### Task 8: Widget du tableau de bord réseau

Spec rc.2 § 5 ; écart E4.

**Files:**
- Modify : `includes/Reports/DashboardWidget.php`
- Test : `tests/php/Reports/DashboardWidgetTest.php`

**Interfaces:**
- Consumes : `AlertsQuery::summary()`, `AlertsQuery::list()`, `InventoryQuery::summary()` (inchangés), `Menu::url( string $view, array $args )`.
- Produces : `DashboardWidget::STYLE_HANDLE = 'msradar-dashboard-widget'`.

- [ ] **Step 1: Write the failing tests**

Dans `tests/php/Reports/DashboardWidgetTest.php` :
- remplacer `test_the_links_inside_a_sentence_are_underlined()` par :

```php
	public function test_the_links_inside_a_sentence_are_underlined(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<a href="[^"]*site=5201[^"]*" style="text-decoration: underline;">/', $html );
	}
```

- nouveaux tests :

```php
	public function test_each_tile_leads_to_the_filtered_screen(): void {
		$html = $this->render();

		$this->assertSame( 5, substr_count( $html, 'class="msradar-widget__tile"' ) );
		foreach ( [ 'alert_level=error', 'alert_level=warning', 'status=unused', 'has_update=1', 'page=multisite-radar-sites' ] as $fragment ) {
			$this->assertStringContainsString( $fragment, $html );
		}
	}

	public function test_main_alerts_carry_their_severity(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<span class="msradar-widget__severity msradar-widget__severity--(error|warning|info)">[^<]+<\/span>/', $html );
	}

	public function test_its_style_is_loaded_with_the_widget_only(): void {
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );

		$this->plugin()->dashboard_widget()->add();

		$this->assertTrue( wp_style_is( DashboardWidget::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( '.msradar-widget__tiles', implode( '', (array) wp_styles()->get_data( DashboardWidget::STYLE_HANDLE, 'after' ) ) );
	}
```

Dans `tear_down()`, ajouter avant `parent::tear_down();` : `wp_dequeue_style( DashboardWidget::STYLE_HANDLE ); wp_deregister_style( DashboardWidget::STYLE_HANDLE );`

Run: `bin/test.sh --filter DashboardWidgetTest`
Expected: FAIL.

- [ ] **Step 2: Implement**

Dans `includes/Reports/DashboardWidget.php` :
- constantes, après `TOP` :

```php
	public const STYLE_HANDLE = 'msradar-dashboard-widget';

	/**
	 * Style du widget (écart E4 du plan rc.2) : tuiles en grille, pastilles de gravité aux couleurs de l'écran Alertes.
	 */
	private const STYLE = '.msradar-widget__tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin:0 0 16px;padding:0;list-style:none}'
		. '.msradar-widget__tile{margin:0}'
		. '.msradar-widget__tile a{display:block;height:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #dcdcde;border-radius:4px;color:#1d2327;text-decoration:none}'
		. '.msradar-widget__tile a:hover,.msradar-widget__tile a:focus{border-color:var(--wp-admin-theme-color,#2271b1)}'
		. '.msradar-widget__value{display:block;font-size:20px;font-weight:600;line-height:1.3}'
		. '.msradar-widget__label{display:block;color:#50575e;font-size:12px}'
		. '.msradar-widget__alerts{margin:0;padding:0;list-style:none}'
		. '.msradar-widget__alerts li{margin:0 0 8px}'
		. '.msradar-widget__severity{display:inline-block;margin-inline-end:6px;padding:0 6px;border-radius:2px;font-size:12px}'
		. '.msradar-widget__severity--error{background:#fcf0f1;color:#8a2424}'
		. '.msradar-widget__severity--warning{background:#fcf9e8;color:#6d4c00}'
		. '.msradar-widget__severity--info{background:#f0f6fc;color:#0a4b78}'
		. '.msradar-widget__more{margin:12px 0 0;text-align:end}';
```

- `add()` charge le style avant d'ajouter le widget :

```php
	public function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, [], MSRADAR_VERSION );
		wp_add_inline_style( self::STYLE_HANDLE, self::STYLE );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_dashboard_widget( self::ID, __( 'Multisite Radar', 'multisite-radar' ), [ $this, 'render' ] );
	}
```

- dans `render()`, remplacer tout ce qui suit le bloc `try`/`catch` par :

```php
		$plugin_updates = (int) $inventory['plugins']['updates'];
		$theme_updates  = (int) $inventory['themes']['updates'];
		$tiles          = [
			[ __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'], Menu::url( 'sites' ) ],
			[ __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'], Menu::url( 'sites', [ 'alert_level' => 'error' ] ) ],
			[ __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'], Menu::url( 'sites', [ 'alert_level' => 'warning' ] ) ],
			[ __( 'Unused plugins', 'multisite-radar' ), (int) $inventory['plugins']['unused'], Menu::url( 'plugins', [ 'status' => 'unused' ] ) ],
			[ __( 'Pending updates', 'multisite-radar' ), $plugin_updates + $theme_updates, Menu::url( 0 === $plugin_updates && $theme_updates > 0 ? 'themes' : 'plugins', [ 'has_update' => '1' ] ) ],
		];
		echo '<ul class="msradar-widget__tiles">';
		foreach ( $tiles as $tile ) {
			printf(
				'<li class="msradar-widget__tile"><a href="%1$s"><span class="msradar-widget__value">%2$s</span> <span class="msradar-widget__label">%3$s</span></a></li>',
				esc_url( $tile[2] ),
				esc_html( number_format_i18n( $tile[1] ) ),
				esc_html( $tile[0] )
			);
		}
		echo '</ul>';

		if ( [] === $top['items'] ) {
			echo '<p>' . esc_html__( 'No alert on the network.', 'multisite-radar' ) . '</p>';
		} else {
			$severities = [
				'error'   => __( 'Error', 'multisite-radar' ),
				'warning' => __( 'Warning', 'multisite-radar' ),
				'info'    => __( 'Info', 'multisite-radar' ),
			];
			echo '<h3>' . esc_html__( 'Main alerts', 'multisite-radar' ) . '</h3><ul class="msradar-widget__alerts">';
			foreach ( $top['items'] as $alert ) {
				$severity = isset( $severities[ $alert['severity'] ] ) ? (string) $alert['severity'] : 'info';
				// Lien souligné : au milieu d'un texte, la couleur seule ne le distingue pas (WCAG 1.4.1).
				printf(
					'<li><span class="msradar-widget__severity msradar-widget__severity--%1$s">%2$s</span> <a href="%3$s" style="text-decoration: underline;">%4$s</a> — %5$s</li>',
					esc_attr( $severity ),
					esc_html( $severities[ $severity ] ),
					esc_url( Menu::url( 'sites', [ 'site' => (int) $alert['site']['id'] ] ) ),
					esc_html( $alert['site']['name'] ),
					esc_html( $alert['message'] )
				);
			}
			echo '</ul>';
		}
		printf( '<p class="msradar-widget__more"><a href="%1$s">%2$s</a></p>', esc_url( Menu::url( 'overview' ) ), esc_html__( 'Open Multisite Radar', 'multisite-radar' ) );
```

L'ancienne liste `$figures` et sa boucle disparaissent.

- [ ] **Step 3: Run the tests to verify they pass**

Run: `bin/test.sh --filter DashboardWidgetTest && composer lint && composer analyse`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/Reports/DashboardWidget.php tests/php/Reports/DashboardWidgetTest.php
git commit -m "feat: show the dashboard widget figures as tiles and mark each main alert with its severity"
```

---

### Task 9: Tests de bout en bout

Spec rc.2 § 6.1, critères de succès 1 et 2.

**Files:**
- Create : `tests/e2e/specs/rows.spec.js`
- Modify : `tests/e2e/specs/layout.spec.js`
- Test : ces fichiers, plus `tests/e2e/specs/a11y.spec.js` (inchangé, relancé)

**Interfaces:**
- Consumes : le réseau de démonstration de `tests/e2e/setup.sh` (site « Blog RH », plugin « Multisite Radar demo CPT », compte `admin`) ; la route `POST /multisite-radar/v1/preferences`.

- [ ] **Step 1: Write the end-to-end tests**

`tests/e2e/specs/rows.spec.js` :

```js
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const ROW = 'tbody tr.dataviews-view-table__row';

/**
 * Clique sur la dernière cellule de données d'une ligne (avant la colonne d'actions), loin du titre.
 *
 * @param {import('@playwright/test').Locator} row Ligne du tableau.
 */
async function clickPlainCell( row ) {
	await row.locator( 'td' ).nth( -2 ).click();
}

test.describe( 'A click anywhere in a row opens the detail (rc.2 spec 2.1)', () => {
	for ( const [ page, label ] of [
		[ 'multisite-radar-sites', 'Sites' ],
		[ 'multisite-radar-plugins', 'Plugins' ],
		[ 'multisite-radar-themes', 'Themes' ],
		[ 'multisite-radar-alerts', 'Alerts' ],
	] ) {
		test( `on ${ label }`, async ( { admin, page: browser } ) => {
			await admin.visitAdminPage( 'network/admin.php', `page=${ page }` );
			const row = browser.locator( `#msradar-app ${ ROW }` ).first();
			await expect( row ).toBeVisible();
			const name = (
				await row.locator( '.msradar-site-title__name' ).first().innerText()
			).trim();

			await clickPlainCell( row );

			await expect(
				browser.getByRole( 'dialog', { name } )
			).toBeVisible();
			await expect( browser ).toHaveURL( new RegExp( `page=${ page }` ) );
		} );
	}

	test( 'on Users, with the account panel', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users'
		);
		const row = page
			.locator( `#msradar-app ${ ROW }` )
			.filter( { has: page.getByText( 'admin', { exact: true } ) } );

		await clickPlainCell( row );

		const panel = page.getByRole( 'dialog', { name: 'admin' } );
		await expect( panel ).toBeVisible();
		await expect( page ).toHaveURL( /user=1/ );
		await expect(
			panel.getByRole( 'link', { name: 'Edit the account' } )
		).toBeVisible();
		await expect( panel.locator( '.msradar-list li' ).first() ).toBeVisible();
	} );
} );

test( 'the bulk actions bar appears above the Sites table (rc.2 spec 2.3)', async ( {
	admin,
	page,
} ) => {
	await admin.visitAdminPage(
		'network/admin.php',
		'page=multisite-radar-sites'
	);
	const row = page.locator( `#msradar-app ${ ROW }` ).first();
	await row.locator( 'input[type="checkbox"]' ).check();

	const bar = page.locator(
		'#msradar-app .msradar-dataviews__bulk .dataviews-bulk-actions-footer__container'
	);
	await expect( bar ).toBeVisible();
	const barBox = await bar.boundingBox();
	const tableBox = await page
		.locator( '#msradar-app .dataviews-view-table' )
		.boundingBox();
	expect( barBox.y ).toBeLessThan( tableBox.y );
	await expect( page.locator( '#msradar-app .dataviews-footer .dataviews-bulk-actions-footer__container' ) ).toHaveCount( 0 );
} );
```

Si un écran du réseau de démonstration n'affiche aucune ligne (Alertes sans alerte, par exemple), lire `tests/e2e/setup.sh` : il crée les données des autres specs ; y ajouter ce qui manque plutôt que de sauter le test.

`tests/e2e/specs/layout.spec.js`, nouveau test :

```js
test( 'with every Sites column shown, no column stays under the Actions column', async ( {
	admin,
	page,
	requestUtils,
} ) => {
	const fields = [
		'theme',
		'users_count',
		'content_count',
		'media_count',
		'disk_bytes',
		'db_bytes',
		'last_activity_gmt',
		'scanned_at_gmt',
		'alert_level',
		'rule',
		'status',
		'registry_status',
	];
	await requestUtils.rest( {
		method: 'POST',
		path: '/multisite-radar/v1/preferences',
		data: { sites: { fields } },
	} );
	try {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites'
		);
		const table = page.locator( '#msradar-app .dataviews-view-table' );
		await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();
		await page.evaluate( () => {
			const container = document.querySelector(
				'#msradar-app .dataviews-layout__container'
			);
			container.scrollLeft = container.scrollWidth;
		} );

		const headers = table.locator( 'thead th' );
		const count = await headers.count();
		const actions = await headers.nth( count - 1 ).boundingBox();
		const last = await headers.nth( count - 2 ).boundingBox();
		expect( last.x + last.width ).toBeLessThanOrEqual( actions.x + 1 );
		expect( actions.width ).toBeLessThan( 80 );
	} finally {
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/preferences',
			data: { sites: { fields: [] } },
		} );
	}
} );
```

Vérifier les identifiants de colonnes dans `src/views/sites/fields.jsx` avant de lancer : la liste doit être celle des champs masquables de Sites.

- [ ] **Step 2: Run them**

Run: `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 && npm run build && npm run wp-env -- start && npm run e2e:setup && npm run test:e2e -- tests/e2e/specs/rows.spec.js tests/e2e/specs/layout.spec.js tests/e2e/specs/a11y.spec.js`
Expected: PASS. Un échec d'accessibilité sur le panneau du compte se corrige dans `src/views/user-panel/index.jsx`, pas dans le test.

- [ ] **Step 3: Run the whole end-to-end suite**

Run: `npm run test:e2e`
Expected: PASS (30 tests de M7 plus les nouveaux).

- [ ] **Step 4: Commit**

```bash
git add tests/e2e
git commit -m "test: cover the row click, the bulk actions bar and the account panel end to end"
```

---

### Task 10: Version 2.0.0-rc.2

Dernière tâche : traductions, version, journaux, contrôle final.

**Files:**
- Modify : `languages/*` (via `make i18n`) ; `multisite-radar.php`, `readme.txt`, `package.json`, `package-lock.json` (via `make version`) ; `CHANGELOG.md` ; `readme.txt` (journal).

**Interfaces:**
- Consumes : tout le plan.

- [ ] **Step 1: Translations**

Run: `npm run build && make i18n`
Expected: le script s'arrête et liste les chaînes nouvelles ou modifiées par les tâches 2 à 8.

Les traduire dans `languages/multisite-radar-fr_FR.po`, dans le style du fichier :
- guillemets « » ; espace insécable avant `:` `;` `?` `!` et à l'intérieur des guillemets ;
- vocabulaire existant : « analyse », « site », « fiche », « compte », « relevé » ;
- termes de la spec rc.2 :

| Anglais | Français |
|---|---|
| Export (bouton) | Exporter |
| %s · pre-release | %s · version préliminaire |
| Public name | Nom public |
| Email | E-mail |
| First and last name | Prénom et nom |
| Roles | Rôles |
| Published content | Contenus publiés |
| %1$s on %2$d site / sites | %1$s sur %2$d site / sites |
| View the account | Voir le compte |
| Account | Compte |
| Account not found. | Compte introuvable. |
| Loading the account… | Chargement du compte… |
| And %d more site. / sites. | Et %d autre site. / Et %d autres sites. |
| %s published | %s publié / %s publiés |

Relancer `make i18n` jusqu'à ce qu'il passe.

Run: `bin/test.sh --filter TranslationsTest`
Expected: PASS.

- [ ] **Step 2: Version**

Run: `make version VERSION=2.0.0-rc.2`
Expected: `Version 2.0.0-rc.2 is consistent.`

- [ ] **Step 3: Changelogs**

`readme.txt`, en tête de « == Changelog == » :

```text
= 2.0.0-rc.2 =
* A click anywhere in a table row opens its detail, on every screen; the Alerts screen opens the site panel in place.
* Bulk actions appear above the table as soon as a row is selected.
* Plugins and themes sort by state, version and update, with a colour for each state; the state filter stays in view.
* Users: e-mail (for administrators who can manage users), first and last name, roles, published posts, and an account panel.
* The network dashboard widget shows its figures as tiles, and each main alert with its severity.
* The export button is labelled, and pre-release versions are spelled out next to the title.
```

`CHANGELOG.md`, sous le titre :

```markdown
## [2.0.0-rc.2] - <date du jour, AAAA-MM-JJ>

### Remarques de la recette de la rc.1
- Un clic n'importe où sur une ligne ouvre le détail, sur tous les écrans ; Alertes ouvre le panneau du site sans quitter l'écran. L'action de détail passe dans le menu « ⋮ » : la colonne d'actions ne cache plus de colonnes.
- Les actions groupées s'affichent au-dessus du tableau dès qu'une ligne est cochée.
- Extensions et Thèmes : tri par état, version et mise à jour, couleur par état, filtre d'état toujours visible (comme la gravité dans Alertes et le niveau d'alerte dans Sites).
- Comptes : e-mail (pour qui peut gérer les utilisateurs du réseau), prénom et nom, rôles, contenus publiés, et un panneau du compte (`GET /users/{id}`). Les contenus publiés par auteur sont relevés pendant l'analyse (table `msradar_site_authors`, schéma 5).
- Widget du tableau de bord : tuiles cliquables, pastille de gravité pour chaque alerte principale.
- Bouton d'export libellé ; badge de version préliminaire en clair.
```

Run: `npm run readme:check && npm run version:check`
Expected: PASS.

- [ ] **Step 4: Final checks on the real package**

Run: `make check`
Expected: PASS (hors les 11 échecs connus de la base locale ; pour une suite propre, la commande `WP_PHPUNIT__TESTS_CONFIG=…` des Global Constraints).

Run: `export WP_ENV_PORT=8890 WP_ENV_TESTS_PORT=8891 WP_BASE_URL=http://localhost:8890 && npm run wp-env -- start && make plugin-check`
Expected: aucune erreur ni avertissement.

Run: `make dist && ls dist/`
Expected: `multisite-radar-2.0.0-rc.2.zip`.

- [ ] **Step 5: Commit**

```bash
git add languages multisite-radar.php readme.txt package.json package-lock.json CHANGELOG.md
git commit -m "chore: release 2.0.0-rc.2"
```

---

## Après le plan (hors exécution)

Le contrôleur propose à l'utilisateur, chacun sur son accord explicite :
1. fusion en avance rapide de `rc2` dans `main`, puis push de `main` seul et suivi de la CI ;
2. si la CI est verte, tag annoté `v2.0.0-rc.2` et push du tag ;
3. déploiement en production (`make deploy-prod`, ou par l'utilisateur) ;
4. une recette rc.2 ciblée : les points changés, plus les contrôles restés ouverts de la rc.1 (journal d'erreurs PHP, e-mail de test, tendances à J+2, texte dans le guide de confidentialité).

Ensuite seulement, la section « Après la recette » du plan M7 (2.0.0, captures, soumission).
