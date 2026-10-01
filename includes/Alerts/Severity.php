<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Niveaux de gravité et leur valeur numérique (stockée dans alert_level).
 */
final class Severity {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';
	public const NONE    = 'none';

	private const LEVELS = [
		self::INFO    => 1,
		self::WARNING => 2,
		self::ERROR   => 3,
	];

	public static function level( string $severity ): int {
		return self::LEVELS[ $severity ] ?? 0;
	}

	public static function name( int $level ): string {
		$names = array_flip( self::LEVELS );
		return $names[ $level ] ?? self::NONE;
	}

	/**
	 * Noms publics des niveaux, du plus faible au plus fort (filtres REST et interface).
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_merge( [ self::NONE ], array_keys( self::LEVELS ) );
	}

	public static function is_valid( string $severity ): bool {
		return isset( self::LEVELS[ $severity ] );
	}
}
