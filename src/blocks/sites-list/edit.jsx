import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	FormTokenField,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import {
	idsToTokens,
	mergeSites,
	sitesPath,
	tokenLabel,
	tokensToIds,
} from './tokens';

/**
 * Sites connus du bloc : les suggestions de la dernière recherche, et le nom des sites déjà choisis, lus une fois.
 * Un échec de lecture laisse les jetons sous la forme « #12 ».
 *
 * @param {number[]} chosen Identifiants déjà choisis (inclus et exclus).
 */
function useSites( chosen ) {
	const [ known, setKnown ] = useState( [] );
	const [ found, setFound ] = useState( [] );
	const remember = useCallback(
		( sites ) => setKnown( ( current ) => mergeSites( current, sites ) ),
		[]
	);

	const missingKey = chosen
		.filter( ( id ) => ! known.some( ( site ) => site.id === id ) )
		.join( ',' );
	useEffect( () => {
		if ( ! missingKey ) {
			return;
		}
		apiFetch( {
			path: sitesPath( {
				include: missingKey.split( ',' ).map( Number ),
			} ),
		} )
			.then( remember )
			.catch( () => {} );
	}, [ missingKey, remember ] );

	// Fonction stable : useDebounce en recrée une à chaque changement de son argument.
	const fetchSites = useCallback(
		( value ) => {
			apiFetch( { path: sitesPath( { search: value.trim() } ) } )
				.then( ( sites ) => {
					remember( sites );
					setFound( sites );
				} )
				.catch( () => setFound( [] ) );
		},
		[ remember ]
	);
	const search = useDebounce( fetchSites, 300 );
	useEffect( () => {
		search( '' );
		return () => search.cancel();
	}, [ search ] );

	return { known, suggestions: found.map( tokenLabel ), search };
}

export default function Edit( { attributes, setAttributes } ) {
	const include = attributes.include || [];
	const exclude = attributes.exclude || [];
	const { known, suggestions, search } = useSites( [
		...include,
		...exclude,
	] );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Sites', 'multisite-radar' ) }>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Only these sites', 'multisite-radar' ) }
						value={ idsToTokens( include, known ) }
						suggestions={ suggestions }
						onInputChange={ search }
						onChange={ ( tokens ) =>
							setAttributes( {
								include: tokensToIds( tokens, known ),
							} )
						}
					/>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Hide these sites', 'multisite-radar' ) }
						value={ idsToTokens( exclude, known ) }
						suggestions={ suggestions }
						onInputChange={ search }
						onChange={ ( tokens ) =>
							setAttributes( {
								exclude: tokensToIds( tokens, known ),
							} )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'multisite-radar' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'multisite-radar' ) }
						value={ attributes.orderBy }
						options={ [
							{
								value: 'name',
								label: __( 'Name', 'multisite-radar' ),
							},
							{
								value: 'id',
								label: __( 'Site ID', 'multisite-radar' ),
							},
							{
								value: 'registered',
								label: __( 'Creation date', 'multisite-radar' ),
							},
						] }
						onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'multisite-radar' ) }
						value={ attributes.order }
						options={ [
							{
								value: 'asc',
								label: __( 'Ascending', 'multisite-radar' ),
							},
							{
								value: 'desc',
								label: __( 'Descending', 'multisite-radar' ),
							},
						] }
						onChange={ ( order ) => setAttributes( { order } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'multisite-radar' ) }
						value={ attributes.layout }
						options={ [
							{
								value: 'list',
								label: __( 'List', 'multisite-radar' ),
							},
							{
								value: 'inline',
								label: __( 'Inline', 'multisite-radar' ),
							},
						] }
						onChange={ ( layout ) => setAttributes( { layout } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<ServerSideRender
					block="multisite-radar/sites-list"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
