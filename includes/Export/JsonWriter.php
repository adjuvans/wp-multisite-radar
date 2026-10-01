<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Document JSON { "meta": …, "items": [ … ] } écrit au fil de l'eau, élément par élément.
 */
final class JsonWriter {

	/**
	 * @var resource
	 */
	private $stream;

	private bool $first = true;

	/**
	 * @param resource $stream Flux ouvert en écriture.
	 */
	public function __construct( $stream ) {
		$this->stream = $stream;
	}

	public function begin( array $meta ): void {
		$this->put( '{"meta":' . self::encode( $meta ) . ',"items":[' );
	}

	public function item( array $row ): void {
		$this->put( ( $this->first ? '' : ',' ) . self::encode( $row ) );
		$this->first = false;
	}

	public function end(): void {
		$this->put( ']}' );
	}

	private static function encode( array $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function put( string $chunk ): void {
		fwrite( $this->stream, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed export.
	}
}
