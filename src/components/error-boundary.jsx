import { Component } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	render() {
		if ( this.state.error ) {
			return (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'This page could not be displayed. Reload it; if the problem persists, check the browser console.',
						'multisite-radar'
					) }
				</Notice>
			);
		}
		return this.props.children;
	}
}
