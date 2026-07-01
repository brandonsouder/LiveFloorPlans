<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Plugin {
	public const POST_TYPE = 'souder_floor_plan';
	public const REST_NS = 'souder-floor-plans/v1';
	public const CRON_HOOK = 'slfp_refresh_floor_plans';

	public const META_IMAGE_ID = '_slfp_image_id';
	public const META_BUILDING_ID = '_slfp_building_id';
	public const META_OVERLAYS = '_slfp_overlays';
	public const META_SUITE_CACHE = '_slfp_suite_cache';
	public const META_SYNCED_AT = '_slfp_synced_at';
	public const META_SYNC_STATUS = '_slfp_sync_status';
	public const META_SYNC_ERROR = '_slfp_sync_error';

	public static function boot(): void {
		SLFP_Post_Type::boot();
		SLFP_Admin::boot();
		SLFP_Rest::boot();
		SLFP_Renderer::boot();
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_virtual_caps' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_floor_plans' ) );
	}

	public static function activate(): void {
		SLFP_Post_Type::register();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'slfp_five_minutes', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	public static function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['slfp_five_minutes'] ) ) {
			$schedules['slfp_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes', 'souder-live-floor-plans' ),
			);
		}
		return $schedules;
	}

	public static function can_manage(): bool {
		return current_user_can( 'cre_manage_inventory' ) || current_user_can( 'manage_options' );
	}

	public static function can_view_admin(): bool {
		return current_user_can( 'cre_view_inventory' ) || current_user_can( 'manage_options' );
	}

	public static function grant_virtual_caps( array $allcaps, array $caps, array $args ): array {
		$manage = ! empty( $allcaps['cre_manage_inventory'] ) || ! empty( $allcaps['manage_options'] );
		$view = $manage || ! empty( $allcaps['cre_view_inventory'] );
		foreach ( array( 'slfp_manage_floor_plans' ) as $cap ) {
			if ( in_array( $cap, $caps, true ) ) {
				$allcaps[ $cap ] = $manage;
			}
		}
		foreach ( array( 'slfp_view_floor_plans' ) as $cap ) {
			if ( in_array( $cap, $caps, true ) ) {
				$allcaps[ $cap ] = $view;
			}
		}
		return $allcaps;
	}

	public static function refresh_floor_plans(): void {
		$plans = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_BUILDING_ID,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $plans as $plan_id ) {
			SLFP_Suite_Provider::sync_plan( (int) $plan_id );
		}
	}

	public static function get_overlays( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_OVERLAYS, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) ? array_values( array_filter( array_map( array( __CLASS__, 'sanitize_overlay' ), $data ) ) ) : array();
	}

	public static function save_overlays( int $post_id, array $overlays ): void {
		$clean = array_values( array_filter( array_map( array( __CLASS__, 'sanitize_overlay' ), $overlays ) ) );
		update_post_meta( $post_id, self::META_OVERLAYS, wp_json_encode( $clean ) );
	}

	public static function sanitize_overlay( $overlay ): ?array {
		if ( ! is_array( $overlay ) ) {
			return null;
		}
		$suite_id = absint( $overlay['suite_id'] ?? 0 );
		if ( ! $suite_id ) {
			return null;
		}
		$clean = array(
			'suite_id'     => $suite_id,
			'suite_number' => sanitize_text_field( (string) ( $overlay['suite_number'] ?? '' ) ),
			'x'            => self::clamp_percent( $overlay['x'] ?? 50 ),
			'y'            => self::clamp_percent( $overlay['y'] ?? 50 ),
		);
		if ( isset( $overlay['w'] ) && '' !== $overlay['w'] ) {
			$clean['w'] = self::clamp_percent( $overlay['w'] );
		}
		if ( isset( $overlay['h'] ) && '' !== $overlay['h'] ) {
			$clean['h'] = self::clamp_percent( $overlay['h'] );
		}
		if ( isset( $overlay['label_override'] ) ) {
			$clean['label_override'] = sanitize_text_field( (string) $overlay['label_override'] );
		}
		return $clean;
	}

	public static function clamp_percent( $value ): float {
		$number = is_numeric( $value ) ? (float) $value : 0.0;
		return round( max( 0, min( 100, $number ) ), 4 );
	}

	public static function image_payload( int $image_id ): array {
		if ( ! $image_id ) {
			return array( 'id' => 0, 'url' => '', 'width' => 0, 'height' => 0 );
		}
		$src = wp_get_attachment_image_src( $image_id, 'full' );
		return array(
			'id'     => $image_id,
			'url'    => $src ? esc_url_raw( $src[0] ) : '',
			'width'  => $src ? absint( $src[1] ) : 0,
			'height' => $src ? absint( $src[2] ) : 0,
		);
	}

	public static function suite_cache( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_SUITE_CACHE, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) ? array_values( $data ) : array();
	}
}
