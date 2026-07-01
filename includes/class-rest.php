<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Rest {
	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			SLFP_Plugin::REST_NS,
			'/buildings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_buildings' ),
				'permission_callback' => array( __CLASS__, 'can_view_admin' ),
			)
		);
		register_rest_route(
			SLFP_Plugin::REST_NS,
			'/floor-plans/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_floor_plan' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $value ) => absint( $value ) > 0 ) ),
			)
		);
		register_rest_route(
			SLFP_Plugin::REST_NS,
			'/floor-plans/(?P<id>\d+)/overlays',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_overlays' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $value ) => absint( $value ) > 0 ) ),
			)
		);
		register_rest_route(
			SLFP_Plugin::REST_NS,
			'/floor-plans/(?P<id>\d+)/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'sync' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $value ) => absint( $value ) > 0 ) ),
			)
		);
	}

	public static function can_manage( WP_REST_Request $request ): bool {
		return SLFP_Plugin::can_manage() && self::valid_nonce( $request );
	}

	public static function can_view_admin( WP_REST_Request $request ): bool {
		return SLFP_Plugin::can_view_admin() && self::valid_nonce( $request );
	}

	public static function get_buildings( WP_REST_Request $request ) {
		$result = SLFP_Suite_Provider::fetch_buildings();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result, 'error' => null ) );
	}

	public static function get_floor_plan( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post = get_post( $post_id );
		if ( ! $post || SLFP_Plugin::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Floor plan not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::payload( $post_id ) );
	}

	public static function save_overlays( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		if ( ! self::is_floor_plan( $post_id ) ) {
			return new WP_Error( 'not_found', 'Floor plan not found.', array( 'status' => 404 ) );
		}
		$body = (array) $request->get_json_params();
		SLFP_Plugin::save_overlays( $post_id, (array) ( $body['overlays'] ?? array() ) );
		return rest_ensure_response( array( 'success' => true, 'data' => self::payload( $post_id ), 'error' => null ) );
	}

	public static function sync( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		if ( ! self::is_floor_plan( $post_id ) ) {
			return new WP_Error( 'not_found', 'Floor plan not found.', array( 'status' => 404 ) );
		}
		$body = (array) $request->get_json_params();
		$building_id = absint( $body['building_id'] ?? 0 );
		if ( $building_id ) {
			update_post_meta( $post_id, SLFP_Plugin::META_BUILDING_ID, $building_id );
		}
		$result = SLFP_Suite_Provider::sync_plan( $post_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'data'    => self::payload( $post_id ),
					'error'   => array( 'message' => $result->get_error_message() ),
				),
				500
			);
		}
		return rest_ensure_response( array( 'success' => true, 'data' => self::payload( $post_id ), 'error' => null ) );
	}

	public static function payload( int $post_id ): array {
		$image_id = absint( get_post_meta( $post_id, SLFP_Plugin::META_IMAGE_ID, true ) );
		$status = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNC_STATUS, true );
		$error = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNC_ERROR, true );
		return array(
			'id'          => $post_id,
			'title'       => get_the_title( $post_id ),
			'building_id' => absint( get_post_meta( $post_id, SLFP_Plugin::META_BUILDING_ID, true ) ),
			'image'       => SLFP_Plugin::image_payload( $image_id ),
			'overlays'    => SLFP_Plugin::get_overlays( $post_id ),
			'suites'      => SLFP_Plugin::suite_cache( $post_id ),
			'sync'        => array(
				'status'    => $status ?: 'never',
				'error'     => $error,
				'synced_at' => (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNCED_AT, true ),
				'stale'     => self::is_stale( $post_id ),
			),
		);
	}

	private static function valid_nonce( WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function is_floor_plan( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof WP_Post && SLFP_Plugin::POST_TYPE === $post->post_type;
	}

	private static function is_stale( int $post_id ): bool {
		$status = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNC_STATUS, true );
		if ( 'error' === $status ) {
			return true;
		}
		$synced = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNCED_AT, true );
		if ( ! $synced ) {
			return true;
		}
		$time = strtotime( $synced );
		return ! $time || $time < time() - 10 * MINUTE_IN_SECONDS;
	}
}
