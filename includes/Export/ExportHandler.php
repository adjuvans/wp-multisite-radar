<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exports CSV et JSON par admin-post.php, avec nonce et capacité msradar_view (spec §5.2) : sites, plugins, thèmes.
 * Les filtres sont ceux de la route REST de la ressource ; les sites sont lus et écrits par tranches de 500.
 */
final class ExportHandler {

	public const ACTION    = 'msradar_export';
	public const RESOURCES = [ 'sites', 'plugins', 'themes' ];
	public const FORMATS   = [ 'csv', 'json' ];

	/**
	 * @var array<string, ExportSource>
	 */
	private array $sources;

	/**
	 * @param array<string, ExportSource> $sources Ressource (RESOURCES) => source.
	 */
	public function __construct( array $sources ) {
		$this->sources = $sources;
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

		// Premier accès aux données avant tout en-tête et tout octet : un échec donne encore un vrai 500.
		try {
			$this->sources[ $params['resource'] ]->check( $params['filters'] );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			wp_die( esc_html__( 'The export could not be read from the database.', 'multisite-radar' ), '', [ 'response' => 500 ] );
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
		$this->stream( $params, $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
		exit;
	}

	/**
	 * Écrit l'export ; si une lecture échoue en cours de route (la sortie est déjà partie), signale l'erreur et s'arrête sans rien ajouter.
	 *
	 * @param array    $params Résultat de params().
	 * @param resource $stream Flux de sortie.
	 * @param int      $chunk  Taille des tranches de lecture.
	 * @return bool Faux si l'export a été interrompu.
	 */
	public function stream( array $params, $stream, int $chunk = 500 ): bool {
		try {
			$this->write( $params, $stream, $chunk );
			return true;
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return false;
		}
	}

	/**
	 * @param array $input Paramètres de la requête, déjà désinfectés.
	 * @return array{resource: string, format: string, fields: string[], filters: array}|WP_Error
	 */
	public function params( array $input ) {
		$resource = (string) ( $input['resource'] ?? 'sites' );
		if ( ! in_array( $resource, self::RESOURCES, true ) || ! isset( $this->sources[ $resource ] ) ) {
			return new WP_Error( 'msradar_unknown_resource', __( 'This data cannot be exported.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$format = (string) ( $input['format'] ?? 'csv' );
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			return new WP_Error( 'msradar_unknown_format', __( 'Unknown export format.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$source  = $this->sources[ $resource ];
		$lists   = $source->list_filters();
		$filters = [];
		foreach ( $source->filters() as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$is_list = in_array( $key, $lists, true );
			if ( ! $is_list && ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$value = $is_list ? self::to_list( $input[ $key ] ) : (string) $input[ $key ];
			if ( '' === $value || [] === $value ) {
				continue;
			}
			$filters[ $key ] = 'include' === $key ? array_map( 'intval', (array) $value ) : $value;
		}

		$known  = array_keys( $source->columns() );
		$fields = array_values( array_intersect( $known, self::to_list( $input['fields'] ?? [] ) ) );
		return [
			'resource' => $resource,
			'format'   => $format,
			'fields'   => [] === $fields ? $known : $fields,
			'filters'  => $filters,
		];
	}

	/**
	 * Écrit l'export. Si une lecture échoue en cours de route, la sortie est déjà partie : le fichier se termine par
	 * un marqueur visible, puis l'exception remonte à stream(), qui la signale.
	 *
	 * @param array    $params Résultat de params().
	 * @param resource $stream Flux de sortie.
	 * @param int      $chunk  Taille des tranches de lecture.
	 * @return int Nombre d'éléments exportés.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function write( array $params, $stream, int $chunk = 500 ): int {
		$source  = $this->sources[ $params['resource'] ];
		$keys    = (array) $params['fields'];
		$columns = $source->columns();
		$filters = (array) $params['filters'];

		if ( 'csv' === $params['format'] ) {
			$csv = new CsvWriter( $stream );
			$csv->header( array_map( static fn ( string $key ): string => $columns[ $key ], $keys ) );
			try {
				return $source->each( $filters, $keys, [ $csv, 'row' ], $chunk );
			} catch ( \RuntimeException $error ) {
				$csv->row( [ 'incomplete' => self::incomplete_notice() ] );
				throw $error;
			}
		}

		$json = new JsonWriter( $stream );
		$json->begin(
			[
				'generated_gmt' => gmdate( 'Y-m-d\TH:i:s' ),
				'network'       => network_home_url( '/' ),
				'version'       => MSRADAR_VERSION,
				'resource'      => $params['resource'],
				'filters'       => (object) $filters,
				'fields'        => $keys,
			]
		);
		try {
			$count = $source->each( $filters, $keys, [ $json, 'item' ], $chunk );
		} catch ( \RuntimeException $error ) {
			$json->end(
				[
					'incomplete' => true,
					'error'      => self::incomplete_notice(),
				]
			);
			throw $error;
		}
		$json->end();
		return $count;
	}

	/**
	 * Dernière ligne (CSV) ou clé « error » (JSON) d'un export interrompu.
	 */
	public static function incomplete_notice(): string {
		return __( 'Export incomplete: the data could not be read to the end. Run the export again.', 'multisite-radar' );
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
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', array_filter( $items, 'is_scalar' ) ) ), static fn ( string $item ): bool => '' !== $item ) );
	}
}
