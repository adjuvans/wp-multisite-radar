<?php
// Plugin fictif pour RegistryProbeTest : enregistre un type et une taxonomie depuis un fichier situé dans un dossier « plugins ».
function msradar_fixture_register_types(): void {
	register_post_type(
		'fixture_event',
		[
			'label'  => 'Fixture events',
			'public' => true,
		]
	);
	register_taxonomy( 'fixture_genre', 'fixture_event', [ 'label' => 'Fixture genres' ] );
}
