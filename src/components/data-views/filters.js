// Filtres DataViews ↔ valeurs simples. Sans dépendance : la logique des vues se teste sans charger DataViews.
function isEmpty( value ) {
	return (
		value === undefined ||
		value === null ||
		value === '' ||
		( Array.isArray( value ) && value.length === 0 )
	);
}

/**
 * Valeur d'un filtre de la vue DataViews, ou la valeur de repli s'il est absent ou vide.
 *
 * @param {Array}   filters  view.filters.
 * @param {string}  field    Identifiant du champ.
 * @param {unknown} fallback Valeur de repli.
 */
export function filterValue( filters, field, fallback ) {
	const filter = ( filters || [] ).find( ( item ) => item.field === field );
	return ! filter || isEmpty( filter.value ) ? fallback : filter.value;
}

/**
 * Filtres DataViews à partir de valeurs simples ; les valeurs vides sont omises.
 *
 * @param {Array} definitions Liste de { field, operator, value }.
 */
export function toFilters( definitions ) {
	return definitions
		.filter( ( definition ) => ! isEmpty( definition.value ) )
		.map( ( { field, operator, value } ) => ( {
			field,
			operator,
			value,
		} ) );
}
