import ErrorBoundary from '../components/error-boundary';
import Snackbars from '../components/snackbars';

export default function App( { children } ) {
	return (
		<ErrorBoundary>
			<div className="msradar-app__view">{ children }</div>
			<Snackbars />
		</ErrorBoundary>
	);
}
