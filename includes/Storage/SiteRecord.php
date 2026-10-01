<?php
namespace MultisiteRadar\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Une ligne de la table msradar_sites.
 */
final class SiteRecord {

	private const INT_FIELDS             = [ 'site_id', 'network_id', 'users_count', 'admins_count', 'content_count', 'media_count', 'alert_level', 'alerts_count' ];
	private const NULLABLE_INT_FIELDS    = [ 'disk_bytes', 'db_bytes', 'autoload_bytes' ];
	private const BOOL_FIELDS            = [ 'is_public', 'is_archived', 'is_spam', 'is_deleted', 'disk_is_estimate', 'dirty' ];
	private const STRING_FIELDS          = [ 'name', 'url', 'theme_stylesheet', 'theme_template', 'alert_rules', 'registry_status' ];
	private const NULLABLE_STRING_FIELDS = [ 'last_activity_gmt', 'dirty_since', 'scanned_at' ];

	public int $site_id               = 0;
	public int $network_id            = 1;
	public string $name               = '';
	public string $url                = '';
	public bool $is_public            = true;
	public bool $is_archived          = false;
	public bool $is_spam              = false;
	public bool $is_deleted           = false;
	public string $theme_stylesheet   = '';
	public string $theme_template     = '';
	public int $users_count           = 0;
	public int $admins_count          = 0;
	public int $content_count         = 0;
	public int $media_count           = 0;
	public ?int $disk_bytes           = null;
	public bool $disk_is_estimate     = false;
	public ?int $db_bytes             = null;
	public ?int $autoload_bytes       = null;
	public ?string $last_activity_gmt = null;
	public int $alert_level           = 0;
	public int $alerts_count          = 0;
	public string $alert_rules        = '';
	public string $registry_status    = 'missing';
	public array $data                = [];
	public bool $dirty                = true;
	public ?string $dirty_since       = null;
	public ?string $scanned_at        = null;

	public static function from_row( array $row ): self {
		$record = new self();
		foreach ( self::INT_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (int) $row[ $field ];
			}
		}
		foreach ( self::NULLABLE_INT_FIELDS as $field ) {
			$record->$field = isset( $row[ $field ] ) ? (int) $row[ $field ] : null;
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (bool) (int) $row[ $field ];
			}
		}
		foreach ( self::STRING_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$record->$field = (string) $row[ $field ];
			}
		}
		foreach ( self::NULLABLE_STRING_FIELDS as $field ) {
			$record->$field = isset( $row[ $field ] ) ? (string) $row[ $field ] : null;
		}
		$data         = isset( $row['data'] ) ? json_decode( (string) $row['data'], true ) : null;
		$record->data = is_array( $data ) ? $data : [];
		return $record;
	}

	public function to_row(): array {
		$row = [];
		foreach ( array_merge( self::INT_FIELDS, self::NULLABLE_INT_FIELDS, self::STRING_FIELDS, self::NULLABLE_STRING_FIELDS ) as $field ) {
			$row[ $field ] = $this->$field;
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			$row[ $field ] = $this->$field ? 1 : 0;
		}
		$row['data'] = wp_json_encode( $this->data );
		return $row;
	}

	/**
	 * @return string[]
	 */
	public function alert_rule_ids(): array {
		return array_values( array_filter( explode( ',', $this->alert_rules ) ) );
	}
}
