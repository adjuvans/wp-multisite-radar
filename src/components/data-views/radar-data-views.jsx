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
	const value = field.getValue
		? field.getValue( { item } )
		: item[ field.id ];
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
export default function DataViews( props ) {
	const {
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
		...rest
	} = props;
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
										: plainValue(
												field,
												renderProps.item
											) }
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
			{ ...rest }
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
