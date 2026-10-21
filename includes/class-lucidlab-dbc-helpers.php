<?php
/**
 * Lucid Lab Database Cleaner: helpers.
 *
 * @package LucidLab_DBC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers: formatting, SQL fragments and table checks.
 */
class LucidLab_DBC_Helpers {

	/**
	 * Human-readable byte size.
	 *
	 * @param int|float $bytes Bytes.
	 *
	 * @return string
	 */
	public static function bytes( $bytes ): string {
		$bytes = max( 0, (float) $bytes );
		if ( $bytes < 1024 ) {
			/* translators: %s: number of bytes. */
			return sprintf( __( '%s B', 'lucidlab-db-cleanup' ), number_format_i18n( $bytes ) );
		}
		return size_format( $bytes, $bytes >= 1048576 ? 1 : 0 );
	}

	/**
	 * Prepared, comma-separated list of integers for an IN () clause.
	 *
	 * @param array<int,int|string> $ids Ids.
	 *
	 * @return string Safe SQL fragment such as "1,2,3" (or "0" when empty).
	 */
	public static function in_ints( array $ids ): string {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return '0';
		}
		return implode( ',', $ids );
	}

	/**
	 * Prepared, comma-separated list of quoted strings for an IN () clause.
	 *
	 * @param array<int,string> $values Values.
	 *
	 * @return string Safe SQL fragment (or "''" when empty).
	 */
	public static function in_strings( array $values ): string {
		global $wpdb;
		$values = array_values( array_unique( array_map( 'strval', $values ) ) );
		if ( ! $values ) {
			return "''";
		}
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		return (string) $wpdb->prepare( $placeholders, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The format string is a generated list of %s placeholders, one per value.
	}

	/**
	 * Escape a table or column name for use inside backticks.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '', preg_replace( '/[^A-Za-z0-9_\-\$]/', '', $name ) ?? '' ) . '`';
	}

	/**
	 * Whether a table exists in the current database.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;
		static $cache = array();
		if ( ! array_key_exists( $table, $cache ) ) {
			$found           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
			$cache[ $table ] = ( $found === $table );
		}
		return $cache[ $table ];
	}

	/**
	 * All tables in the database that start with the WordPress table prefix.
	 *
	 * @return array<int,string>
	 */
	public static function prefixed_tables(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
		sort( $rows );
		return array_map( 'strval', $rows );
	}

	/**
	 * Table statistics from information_schema (exact row count for small tables).
	 *
	 * @param string $table Full table name.
	 *
	 * @return array{rows:int,approx:bool,bytes:int,data:int,index:int,engine:string}
	 */
	public static function table_stats( string $table ): array {
		global $wpdb;
		$info  = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
		$rows  = (int) ( $info['TABLE_ROWS'] ?? 0 );
		$exact = false;
		if ( $rows < 200000 ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact row count for the report.
			if ( null !== $count ) {
				$rows  = (int) $count;
				$exact = true;
			}
		}
		$data  = (int) ( $info['DATA_LENGTH'] ?? 0 );
		$index = (int) ( $info['INDEX_LENGTH'] ?? 0 );
		return array(
			'rows'   => $rows,
			'approx' => ! $exact,
			'bytes'  => $data + $index,
			'data'   => $data,
			'index'  => $index,
			'engine' => (string) ( $info['ENGINE'] ?? '' ),
		);
	}

	/**
	 * Average stored row length of a table (used to estimate the size of row groups).
	 *
	 * @param string $table Full table name.
	 *
	 * @return int
	 */
	public static function avg_row_bytes( string $table ): int {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$info            = $wpdb->get_row( $wpdb->prepare( 'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
			$rows            = max( 1, (int) ( $info['TABLE_ROWS'] ?? 0 ) );
			$cache[ $table ] = (int) ceil( ( (int) ( $info['DATA_LENGTH'] ?? 0 ) + (int) ( $info['INDEX_LENGTH'] ?? 0 ) ) / $rows );
		}
		return $cache[ $table ];
	}

	/**
	 * Primary key column of a table ('' when the table has no single-column primary key).
	 *
	 * @param string $table Full table name.
	 *
	 * @return string
	 */
	public static function primary_key( string $table ): string {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup; there is no core API for it.
		return 1 === count( $rows ) ? (string) ( $rows[0]['Column_name'] ?? '' ) : '';
	}

	/**
	 * Current time as a MySQL GMT datetime, shifted by a number of days.
	 *
	 * @param int $days_ago Days ago.
	 *
	 * @return string
	 */
	public static function gmt_days_ago( int $days_ago ): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );
	}

	/**
	 * Truncate a string for display.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 *
	 * @return string
	 */
	public static function excerpt( string $text, int $max = 80 ): string {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, $max - 1 ) . '…';
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * Read a request field from the current AJAX/admin-post request (nonce verified by the caller).
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when the field is missing.
	 *
	 * @return mixed
	 */
	public static function request( string $key, $fallback = '' ) {
		$value = $fallback;
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce before reading fields.
			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the caller; sanitized below.
		} elseif ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Callers verify the nonce before reading fields.
			$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the caller; sanitized below.
		}
		return is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : sanitize_text_field( (string) $value );
	}
}
