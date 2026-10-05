<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite Radar's own network table: no WordPress API reads or writes it, and the users query caches what it needs.

/**
 * Contenus publiés par auteur, site par site (table msradar_site_authors), relevés à chaque analyse.
 * Chaque site analysé a aussi une ligne témoin (user_id 0) : « aucun contenu » se distingue de « pas encore relevé ».
 * Les lectures ne comptent que les sites qui existent encore dans la table blogs.
 */
final class AuthorsRepository {

	/**
	 * Groupe de cache dont la génération (last_changed) change à chaque écriture.
	 */
	public const CACHE_GROUP = 'msradar_authors';

	/**
	 * @param array<int, int> $authors Identifiant de l'auteur => nombre de contenus publiés.
	 */
	public function replace_for_site( int $site_id, array $authors ): void {
		global $wpdb;
		$table = Schema::authors_table();
		self::check( $wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%d' ] ) );

		$rows = [ 0 => 0 ];
		foreach ( $authors as $user_id => $published ) {
			if ( (int) $user_id > 0 && (int) $published > 0 ) {
				$rows[ (int) $user_id ] = (int) $published;
			}
		}
		foreach ( $rows as $user_id => $published ) {
			self::check(
				$wpdb->insert(
					$table,
					[
						'site_id'   => $site_id,
						'user_id'   => $user_id,
						'published' => $published,
					],
					[ '%d', '%d', '%d' ]
				)
			);
		}
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	public function delete_for_site( int $site_id ): void {
		global $wpdb;
		self::check( $wpdb->delete( Schema::authors_table(), [ 'site_id' => $site_id ], [ '%d' ] ) );
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * @param int[] $user_ids
	 * @return array<int, int> Compte => contenus publiés, tous sites confondus ; un compte sans contenu est absent.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function totals( array $user_ids ): array {
		global $wpdb;
		$ids = self::ids( $user_ids );
		if ( [] === $ids ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.user_id, SUM(a.published) AS published FROM %i AS a INNER JOIN %i AS b ON b.blog_id = a.site_id WHERE a.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY a.user_id',
				array_merge( [ Schema::authors_table(), $wpdb->blogs ], $ids )
			),
			ARRAY_A
		);
		self::check_read();

		$totals = [];
		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row['user_id'] ] = (int) $row['published'];
		}
		return $totals;
	}

	/**
	 * @return array<int, int> Site => contenus publiés par ce compte.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.site_id, a.published FROM %i AS a INNER JOIN %i AS b ON b.blog_id = a.site_id WHERE a.user_id = %d ORDER BY a.site_id ASC',
				Schema::authors_table(),
				$wpdb->blogs,
				$user_id
			),
			ARRAY_A
		);
		self::check_read();

		$sites = [];
		foreach ( (array) $rows as $row ) {
			$sites[ (int) $row['site_id'] ] = (int) $row['published'];
		}
		return $sites;
	}

	/**
	 * @param int[] $site_ids
	 * @return array<int, true> Sites déjà relevés (ligne témoin présente).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function analysed_among( array $site_ids ): array {
		global $wpdb;
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return [];
		}
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM %i WHERE user_id = 0 AND site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ Schema::authors_table() ], $ids )
			)
		);
		self::check_read();
		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	public function is_analysed( int $site_id ): bool {
		return [] !== $this->analysed_among( [ $site_id ] );
	}

	/**
	 * Vrai tant qu'aucun site n'a été analysé depuis la création de la table.
	 *
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function is_empty(): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', Schema::authors_table() ) );
		self::check_read();
		return null === $found;
	}

	/**
	 * @param int[] $ids
	 * @return int[] Identifiants positifs, sans doublon.
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
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
}
