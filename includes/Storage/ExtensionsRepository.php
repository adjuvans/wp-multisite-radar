<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins activés localement et thèmes utilisés, site par site.
 */
final class ExtensionsRepository {

	public const TYPE_PLUGIN = 'plugin';
	public const TYPE_THEME  = 'theme';

	/**
	 * @param string[] $local_plugins Fichiers des plugins activés sur ce site seulement.
	 */
	public function replace_for_site( int $site_id, array $local_plugins, string $stylesheet, string $template ): void {
		global $wpdb;
		$table = Schema::extensions_table();
		self::check( $wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%d' ] ) );

		$rows = [];
		foreach ( array_unique( array_filter( $local_plugins, 'is_string' ) ) as $file ) {
			$rows[] = [ self::TYPE_PLUGIN, $file, 'local' ];
		}
		if ( '' !== $stylesheet ) {
			$rows[] = [ self::TYPE_THEME, $stylesheet, 'active' ];
		}
		if ( '' !== $template && $template !== $stylesheet ) {
			$rows[] = [ self::TYPE_THEME, $template, 'parent' ];
		}

		foreach ( $rows as $row ) {
			self::check(
				$wpdb->insert(
					$table,
					[
						'site_id' => $site_id,
						'type'    => $row[0],
						'slug'    => substr( $row[1], 0, 191 ),
						'role'    => $row[2],
					],
					[ '%d', '%s', '%s', '%s' ]
				)
			);
		}
	}

	public function delete_for_site( int $site_id ): void {
		global $wpdb;
		self::check( $wpdb->delete( Schema::extensions_table(), [ 'site_id' => $site_id ], [ '%d' ] ) );
	}

	/**
	 * @param int|false $result Résultat d'une écriture $wpdb.
	 */
	private static function check( $result ): void {
		global $wpdb;
		if ( false === $result ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}

	/**
	 * @return array<int, array{type: string, slug: string, role: string}>
	 */
	public function for_site( int $site_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT type, slug, role FROM %i WHERE site_id = %d ORDER BY type ASC, slug ASC', Schema::extensions_table(), $site_id ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => [
				'type' => (string) $row['type'],
				'slug' => (string) $row['slug'],
				'role' => (string) $row['role'],
			],
			(array) $rows
		);
	}
}
