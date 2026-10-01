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
		return $names[ $level ] ?? 'none';
	}

	public static function is_valid( string $severity ): bool {
		return isset( self::LEVELS[ $severity ] );
	}
}
