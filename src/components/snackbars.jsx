import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

export default function Snackbars() {
	const all = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const notices = useMemo(
		() => all.filter( ( notice ) => notice.type === 'snackbar' ),
		[ all ]
	);
	const { removeNotice } = useDispatch( noticesStore );
	return (
		<SnackbarList
			notices={ notices }
			className="msradar-snackbars"
			onRemove={ removeNotice }
		/>
	);
}
