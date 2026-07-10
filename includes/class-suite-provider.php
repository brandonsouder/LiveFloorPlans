<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Suite_Provider {
	public static function sync_plan( int $post_id ) {
		$building_id = absint( get_post_meta( $post_id, SLFP_Plugin::META_BUILDING_ID, true ) );
		if ( ! $building_id ) {
			self::save_sync_error( $post_id, __( 'A CRE building ID is required before syncing.', 'souder-live-floor-plans' ) );
			return new WP_Error( 'missing_building', 'A CRE building ID is required before syncing.' );
		}

		$result = self::fetch_suites( $building_id );
		if ( is_wp_error( $result ) ) {
			self::save_sync_error( $post_id, $result->get_error_message() );
			return $result;
		}

		update_post_meta( $post_id, SLFP_Plugin::META_SUITE_CACHE, wp_json_encode( $result ) );
		update_post_meta( $post_id, SLFP_Plugin::META_SYNCED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, SLFP_Plugin::META_SYNC_STATUS, 'ok' );
		delete_post_meta( $post_id, SLFP_Plugin::META_SYNC_ERROR );
		return $result;
	}

	public static function fetch_suites( int $building_id ) {
		if ( self::can_query_cre_tables() ) {
			$result = self::fetch_from_tables( $building_id );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}
		return self::fetch_from_rest( $building_id );
	}

	public static function fetch_buildings() {
		if ( self::can_query_cre_buildings_table() ) {
			$result = self::fetch_buildings_from_tables();
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}
		return self::fetch_buildings_from_rest();
	}

	private static function can_query_cre_tables(): bool {
		return class_exists( 'CRE_Database' ) && class_exists( 'CRE_Domain' ) && method_exists( 'CRE_Database', 'table' ) && method_exists( 'CRE_Domain', 'suite_availability' );
	}

	private static function can_query_cre_buildings_table(): bool {
		return class_exists( 'CRE_Database' ) && method_exists( 'CRE_Database', 'table' );
	}

	private static function fetch_buildings_from_tables() {
		global $wpdb;
		$sql = 'SELECT id,post_id,name,address1,city,state,postal_code FROM ' . CRE_Database::table( 'buildings' ) . ' WHERE archived_at IS NULL ORDER BY name LIMIT 500';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'building_table_query_failed', 'Unable to query CRE building tables.' );
		}
		return array_map( array( __CLASS__, 'normalize_building_row' ), $rows );
	}

	private static function fetch_buildings_from_rest() {
		$page = 1;
		$pages = 1;
		$buildings = array();

		while ( $page <= $pages && $page <= 20 ) {
			$url = add_query_arg(
				array(
					'per_page' => 50,
					'page'     => $page,
				),
				rest_url( 'cre/v1/buildings' )
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'building_rest_fetch_failed', sprintf( 'CRE building API returned HTTP %d.', $code ) );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body['success'] ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				return new WP_Error( 'building_rest_bad_payload', 'CRE building API returned an unexpected payload.' );
			}
			foreach ( $body['data'] as $row ) {
				$buildings[] = self::normalize_building_row( is_array( $row ) ? $row : array() );
			}
			$pages = max( 1, absint( $body['meta']['pages'] ?? 1 ) );
			++$page;
		}

		return $buildings;
	}

	private static function fetch_from_tables( int $building_id ) {
		global $wpdb;
		$sql = 'SELECT s.id,s.building_id,s.post_id,s.suite_number,s.floor,s.square_feet,s.monthly_rate,s.base_status,s.available_date,s.best_use,b.name building_name,b.post_id building_post_id FROM ' . CRE_Database::table( 'suites' ) . ' s JOIN ' . CRE_Database::table( 'buildings' ) . ' b ON b.id=s.building_id WHERE s.building_id=%d AND s.public=1 AND s.archived_at IS NULL AND b.public=1 AND b.archived_at IS NULL ORDER BY s.suite_number,s.id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $building_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'table_query_failed', 'Unable to query CRE suite tables.' );
		}
		return array_map( array( __CLASS__, 'normalize_table_row' ), $rows );
	}

	private static function fetch_from_rest( int $building_id ) {
		$page = 1;
		$pages = 1;
		$suites = array();

		while ( $page <= $pages && $page <= 100 ) {
			$url = add_query_arg(
				array(
					'building_id' => $building_id,
					'per_page'    => 50,
					'page'        => $page,
					'availability' => 'all',
				),
				rest_url( 'cre/v1/suites' )
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'rest_fetch_failed', sprintf( 'CRE suite API returned HTTP %d.', $code ) );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body['success'] ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				return new WP_Error( 'rest_bad_payload', 'CRE suite API returned an unexpected payload.' );
			}
			foreach ( $body['data'] as $row ) {
				$suites[] = self::normalize_rest_row( is_array( $row ) ? $row : array() );
			}
			$pages = max( 1, absint( $body['meta']['pages'] ?? 1 ) );
			++$page;
		}

		return $suites;
	}

	private static function normalize_table_row( array $row ): array {
		$availability = CRE_Domain::suite_availability( (int) $row['id'] );
		$space_types = self::normalize_space_types( $row['best_use'] ?? null );
		return array(
			'id'             => absint( $row['id'] ?? 0 ),
			'building_id'    => absint( $row['building_id'] ?? 0 ),
			'suite_number'   => sanitize_text_field( (string) ( $row['suite_number'] ?? '' ) ),
			'floor'          => sanitize_text_field( (string) ( $row['floor'] ?? '' ) ),
			'square_feet'    => absint( $row['square_feet'] ?? 0 ),
			'monthly_rate'   => (float) ( $row['monthly_rate'] ?? 0 ),
			'space_type'     => implode( ', ', $space_types ),
			'space_types'    => $space_types,
			'status'         => sanitize_key( (string) ( $availability['status'] ?? $row['base_status'] ?? 'unknown' ) ),
			'derived_status' => sanitize_key( (string) ( $availability['derived_status'] ?? $row['base_status'] ?? 'unknown' ) ),
			'available_date' => self::nullable_text( $row['available_date'] ?? null ),
			'building_name'  => sanitize_text_field( (string) ( $row['building_name'] ?? '' ) ),
			'url'            => ! empty( $row['post_id'] ) ? esc_url_raw( get_permalink( (int) $row['post_id'] ) ) : '',
			'updated_at'     => gmdate( 'c' ),
		);
	}

	private static function normalize_rest_row( array $row ): array {
		$availability = is_array( $row['availability'] ?? null ) ? $row['availability'] : array();
		$space_types = self::space_types_from_row( $row );
		return array(
			'id'             => absint( $row['id'] ?? 0 ),
			'building_id'    => absint( $row['building_id'] ?? 0 ),
			'suite_number'   => sanitize_text_field( (string) ( $row['suite_number'] ?? '' ) ),
			'floor'          => sanitize_text_field( (string) ( $row['floor'] ?? '' ) ),
			'square_feet'    => absint( $row['square_feet'] ?? 0 ),
			'monthly_rate'   => (float) ( $row['monthly_rate'] ?? 0 ),
			'space_type'     => implode( ', ', $space_types ),
			'space_types'    => $space_types,
			'status'         => sanitize_key( (string) ( $availability['status'] ?? $row['base_status'] ?? 'unknown' ) ),
			'derived_status' => sanitize_key( (string) ( $availability['derived_status'] ?? $row['base_status'] ?? 'unknown' ) ),
			'available_date' => self::nullable_text( $row['available_date'] ?? null ),
			'building_name'  => sanitize_text_field( (string) ( $row['building_name'] ?? '' ) ),
			'url'            => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
			'updated_at'     => gmdate( 'c' ),
		);
	}

	private static function normalize_building_row( array $row ): array {
		$address = array_filter(
			array(
				sanitize_text_field( (string) ( $row['address1'] ?? '' ) ),
				sanitize_text_field( trim( (string) ( $row['city'] ?? '' ) . ', ' . (string) ( $row['state'] ?? '' ), ' ,' ) ),
				sanitize_text_field( (string) ( $row['postal_code'] ?? '' ) ),
			)
		);
		return array(
			'id'      => absint( $row['id'] ?? 0 ),
			'post_id' => absint( $row['post_id'] ?? 0 ),
			'name'    => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			'address' => implode( ' · ', $address ),
			'url'     => ! empty( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : ( ! empty( $row['post_id'] ) ? esc_url_raw( get_permalink( (int) $row['post_id'] ) ) : '' ),
		);
	}

	private static function space_types_from_row( array $row ): array {
		$values = array();
		foreach ( array( 'space_types', 'suite_types', 'best_use', 'space_type', 'suite_type', 'use', 'uses', 'type' ) as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$values = array_merge( $values, self::normalize_space_types( $row[ $key ] ) );
			}
		}
		return self::unique_text_values( $values );
	}

	private static function normalize_space_types( $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			$values = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$values = array_merge( $values, self::normalize_space_types( $item['name'] ?? $item['label'] ?? $item['value'] ?? $item['title'] ?? '' ) );
				} else {
					$values = array_merge( $values, self::normalize_space_types( $item ) );
				}
			}
			return self::unique_text_values( $values );
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return array();
		}

		if ( '[' === $text[0] || '{' === $text[0] ) {
			$decoded = json_decode( $text, true );
			if ( is_array( $decoded ) ) {
				return self::normalize_space_types( $decoded );
			}
		}

		return self::unique_text_values( preg_split( '/\s*(?:,|;|\||\/|\r?\n)\s*/', $text ) ?: array( $text ) );
	}

	private static function unique_text_values( array $values ): array {
		$unique = array();
		$seen = array();
		foreach ( $values as $value ) {
			$text = sanitize_text_field( trim( (string) $value ) );
			if ( '' === $text ) {
				continue;
			}
			$key = strtolower( $text );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[] = $text;
		}
		return $unique;
	}

	private static function nullable_text( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return sanitize_text_field( (string) $value );
	}

	private static function save_sync_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, SLFP_Plugin::META_SYNC_STATUS, 'error' );
		update_post_meta( $post_id, SLFP_Plugin::META_SYNC_ERROR, sanitize_text_field( $message ) );
	}
}
