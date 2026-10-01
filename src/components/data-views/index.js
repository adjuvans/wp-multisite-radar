/**
 * Seul point d'accès au paquet dataviews (version épinglée, spec §6.3) :
 * une montée de version ne touche que ce dossier.
 */
export { DataViews, DataForm, useFormValidity } from '@wordpress/dataviews/wp';
export { filterValue, toFilters } from './filters';
