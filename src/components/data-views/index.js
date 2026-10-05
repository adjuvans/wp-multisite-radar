/**
 * Seul point d'accès au paquet dataviews (version épinglée, spec §6.3) : une montée de version ne touche que ce
 * dossier. DataViews est le composant du plugin (radar-data-views.jsx), qui assemble celui du paquet.
 */
export { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
export { default as DataViews } from './radar-data-views';
export { filterValue, toFilters } from './filters';
