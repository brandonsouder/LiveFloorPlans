<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Post_Type {
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'manage_' . SLFP_Plugin::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . SLFP_Plugin::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register(): void {
		register_post_type(
			SLFP_Plugin::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Live Floor Plans', 'souder-live-floor-plans' ),
					'singular_name' => __( 'Live Floor Plan', 'souder-live-floor-plans' ),
					'add_new_item'  => __( 'Add Live Floor Plan', 'souder-live-floor-plans' ),
					'edit_item'     => __( 'Edit Live Floor Plan', 'souder-live-floor-plans' ),
					'menu_name'     => __( 'Live Floor Plans', 'souder-live-floor-plans' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-location',
				'supports'     => array( 'title' ),
				'capability_type' => 'slfp_floor_plan',
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'          => 'slfp_manage_floor_plans',
					'read_post'          => 'slfp_view_floor_plans',
					'delete_post'        => 'slfp_manage_floor_plans',
					'edit_posts'         => 'slfp_manage_floor_plans',
					'edit_others_posts'  => 'slfp_manage_floor_plans',
					'delete_posts'       => 'slfp_manage_floor_plans',
					'delete_others_posts' => 'slfp_manage_floor_plans',
					'publish_posts'      => 'slfp_manage_floor_plans',
					'read_private_posts' => 'slfp_view_floor_plans',
					'create_posts'       => 'slfp_manage_floor_plans',
				),
			)
		);
	}

	public static function columns( array $columns ): array {
		$columns['slfp_building'] = __( 'Building ID', 'souder-live-floor-plans' );
		$columns['slfp_sync'] = __( 'Sync', 'souder-live-floor-plans' );
		$columns['slfp_shortcode'] = __( 'Shortcode', 'souder-live-floor-plans' );
		return $columns;
	}

	public static function column( string $column, int $post_id ): void {
		if ( 'slfp_building' === $column ) {
			echo esc_html( (string) absint( get_post_meta( $post_id, SLFP_Plugin::META_BUILDING_ID, true ) ) );
		}
		if ( 'slfp_sync' === $column ) {
			$status = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNC_STATUS, true );
			$synced = (string) get_post_meta( $post_id, SLFP_Plugin::META_SYNCED_AT, true );
			echo esc_html( $status ? ucwords( str_replace( '_', ' ', $status ) ) : __( 'Never', 'souder-live-floor-plans' ) );
			if ( $synced ) {
				echo '<br><small>' . esc_html( $synced ) . '</small>';
			}
		}
		if ( 'slfp_shortcode' === $column ) {
			echo '<code>[souder_floor_plan id="' . esc_html( (string) $post_id ) . '"]</code>';
		}
	}
}
