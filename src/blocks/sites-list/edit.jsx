import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	FormTokenField,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import {
	idsToTokens,
	sitesFromWindow,
	tokenLabel,
	tokensToIds,
} from './tokens';

export default function Edit( { attributes, setAttributes } ) {
	const sites = sitesFromWindow();
	const suggestions = sites.map( tokenLabel );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Sites', 'multisite-radar' ) }>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Only these sites', 'multisite-radar' ) }
						value={ idsToTokens( attributes.include, sites ) }
						suggestions={ suggestions }
						onChange={ ( tokens ) =>
							setAttributes( {
								include: tokensToIds( tokens, sites ),
							} )
						}
					/>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Hide these sites', 'multisite-radar' ) }
						value={ idsToTokens( attributes.exclude, sites ) }
						suggestions={ suggestions }
						onChange={ ( tokens ) =>
							setAttributes( {
								exclude: tokensToIds( tokens, sites ),
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
