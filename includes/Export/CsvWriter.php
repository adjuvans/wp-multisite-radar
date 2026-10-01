<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * CSV en UTF-8 avec BOM, conforme RFC 4180, écrit au fil de l'eau.
 */
final class CsvWriter {

	/**
	 * @var resource
	 */
	private $stream;

	/**
	 * @param resource $stream Flux ouvert en écriture.
	 */
	public function __construct( $stream ) {
		$this->stream = $stream;
	}

	/**
	 * @param string[] $labels En-têtes de colonnes.
	 */
	public function header( array $labels ): void {
		// BOM : les tableurs reconnaissent l'UTF-8.
		fwrite( $this->stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed export.
		$this->write( array_map( 'strval', $labels ) );
	}

	/**
	 * @param array<string, mixed> $row Valeurs scalaires ou null.
	 */
	public function row( array $row ): void {
		$this->write( array_map( [ self::class, 'cell' ], array_values( $row ) ) );
	}

	/**
	 * Un tableur exécuterait une cellule commençant par =, +, -, @, une tabulation ou un retour chariot :
	 * une apostrophe initiale la neutralise. Les nombres restent intacts.
	 *
	 * @param mixed $value Valeur scalaire ou null.
	 */
	public static function cell( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param string[] $fields Cellules déjà converties.
	 */
	private function write( array $fields ): void {
		// Échappement vide : RFC 4180 (guillemets doublés), et pas de valeur par défaut implicite, dépréciée en PHP 8.4.
		fputcsv( $this->stream, $fields, ',', '"', '' );
	}
}
