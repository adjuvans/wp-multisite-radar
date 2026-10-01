<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Export des sites en CSV ou JSON par admin-post.php, avec nonce et capacité msradar_view.
 * Les mêmes filtres que GET /sites ; les lignes sont lues et écrites par tranches de 500.
 */
final class ExportHandler {

	public const ACTION    = 'msradar_export';
	public const RESOURCES = [ 'sites' ];
	public const FORMATS   = [ 'csv', 'json' ];

	private const FILTERS      = [ 'search', 'orderby', 'order', 'alert_level', 'status', 'registry_status', 'rule', 'theme', 'plugin', 'include' ];
	private const LIST_FILTERS = [ 'alert_level', 'status', 'registry_status', 'include' ];

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export this data.', 'multisite-radar' ), '', [ 'response' => 403 ] );
		}
		$params = $this->params( wp_unslash( $_GET ) );
		if ( is_wp_error( $params ) ) {
			wp_die( esc_html( $params->get_error_message() ), '', [ 'response' => 400 ] );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: ' . ( 'csv' === $params['format'] ? 'text/csv' : 'application/json' ) . '; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $this->filename( $params['resource'], $params['format'] ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		$stream = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed export.
		if ( false === $stream ) {
			wp_die( esc_html__( 'The export could not be started.', 'multisite-radar' ), '', [ 'response' => 500 ] );
		}
		try {
			$this->write( $params, $stream );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
			wp_die( esc_html__( 'The export was interrupted by a database error.', 'multisite-radar' ), '', [ 'response' => 500 ] );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
		exit;
	}

	/**
	 * @param array $input Paramètres de la requête, déjà désinfectés.
	 * @return array{resource: string, format: string, fields: string[], filters: array}|WP_Error
	 */
	public function params( array $input ) {
		$resource = (string) ( $input['resource'] ?? 'sites' );
		if ( ! in_array( $resource, self::RESOURCES, true ) ) {
			return new WP_Error( 'msradar_unknown_resource', __( 'This data cannot be exported.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$format = (string) ( $input['format'] ?? 'csv' );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			return new WP_Error( 'msradar_unknown_format', __( 'Unknown export format.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$filters = [];
		foreach ( self::FILTERS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			if ( ! in_array( $key, self::LIST_FILTERS, true ) && ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$value = in_array( $key, self::LIST_FILTERS, true ) ? self::to_list( $input[ $key ] ) : (string) $input[ $key ];
			if ( '' === $value || [] === $value ) {
				continue;
			}
			$filters[ $key ] = 'include' === $key ? array_map( 'intval', (array) $value ) : $value;
		}

		return [
			'resource' => $resource,
			'format'   => $format,
			'fields'   => SitesColumns::select( self::to_list( $input['fields'] ?? [] ) ),
			'filters'  => $filters,
		];
	}

	/**
	 * @param array    $params Résultat de params().
	 * @param resource $stream Flux de sortie.
	 * @return int Nombre de sites exportés.
	 */
	public function write( array $params, $stream ): int {
		$keys    = (array) $params['fields'];
		$columns = SitesColumns::all();

		if ( 'csv' === $params['format'] ) {
			$csv = new CsvWriter( $stream );
			$csv->header( array_map( static fn ( string $key ): string => $columns[ $key ], $keys ) );
			return $this->sites->each(
				(array) $params['filters'],
				static function ( array $item ) use ( $csv, $keys ): void {
					$csv->row( SitesColumns::row( $item, $keys ) );
				}
			);
		}

		$json = new JsonWriter( $stream );
		$json->begin(
			[
				'generated_gmt' => gmdate( 'Y-m-d\TH:i:s' ),
				'network'       => network_home_url( '/' ),
				'version'       => MSRADAR_VERSION,
				'resource'      => $params['resource'],
				'filters'       => (object) $params['filters'],
				'fields'        => $keys,
			]
		);
		$count = $this->sites->each(
			(array) $params['filters'],
			static function ( array $item ) use ( $json, $keys ): void {
				$json->item( SitesColumns::row( $item, $keys ) );
			}
		);
		$json->end();
		return $count;
	}

	public function filename( string $name, string $format ): string {
		return 'multisite-radar-' . $name . '-' . gmdate( 'Ymd-His' ) . '.' . $format;
	}

	/**
	 * @param mixed $value Liste séparée par des virgules ou tableau.
	 * @return string[]
	 */
	private static function to_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $items ) ), static fn ( string $item ): bool => '' !== $item ) );
	}
}
