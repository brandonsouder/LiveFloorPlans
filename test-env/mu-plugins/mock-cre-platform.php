<?php
/**
 * Plugin Name: Mock Souder CRE Platform
 * Description: Local-only mock CRE inventory for Live Floor Plans browser testing.
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'SLFP_ENABLE_MOCK_CRE' ) ) {
	return;
}

if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/souder-cre-platform/souder-cre-platform.php' ) ) {
	return;
}

if ( ! class_exists( 'CRE_Database' ) ) {
	final class CRE_Database {
		public static function table( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . 'cre_' . $name;
		}
	}
}

if ( ! class_exists( 'CRE_Domain' ) ) {
	final class CRE_Domain {
		public static function suite_availability( int $suite_id ): array {
			global $wpdb;
			$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT base_status FROM ' . CRE_Database::table( 'suites' ) . ' WHERE id=%d', $suite_id ) );
			return array(
				'status'         => $status ?: 'unknown',
				'derived_status' => $status ?: 'unknown',
				'override'       => false,
				'conflict'       => false,
				'reason'         => null,
			);
		}
	}
}

add_action(
	'init',
	static function () {
		register_post_type(
			'cre_suite',
			array(
				'public'   => true,
				'label'    => 'Suites',
				'rewrite'  => array( 'slug' => 'suites' ),
				'supports' => array( 'title', 'editor' ),
			)
		);
		register_post_type(
			'cre_building',
			array(
				'public'   => true,
				'label'    => 'Buildings',
				'rewrite'  => array( 'slug' => 'buildings' ),
				'supports' => array( 'title', 'editor' ),
			)
		);
	},
	1
);

add_action(
	'init',
	static function () {
		global $wpdb;
		$posts_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) );
		if ( $posts_table !== $wpdb->posts ) {
			return;
		}
		$buildings = CRE_Database::table( 'buildings' );
		$suites    = CRE_Database::table( 'suites' );
		$charset   = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$buildings} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint unsigned NULL,
			name varchar(190) NOT NULL,
			address1 varchar(190) NOT NULL,
			city varchar(100) NOT NULL,
			state char(2) NOT NULL,
			postal_code varchar(20) NOT NULL,
			public tinyint(1) NOT NULL DEFAULT 1,
			archived_at datetime NULL,
			PRIMARY KEY  (id)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$suites} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			building_id bigint unsigned NOT NULL,
			post_id bigint unsigned NULL,
			suite_number varchar(80) NOT NULL,
			floor varchar(40) NULL,
			square_feet int unsigned NOT NULL,
			monthly_rate decimal(12,2) NOT NULL,
			base_status varchar(40) NOT NULL DEFAULT 'available',
			available_date date NULL,
			best_use varchar(190) NULL,
			public tinyint(1) NOT NULL DEFAULT 1,
			archived_at datetime NULL,
			PRIMARY KEY  (id)
		) {$charset};" );

		if ( (int) get_option( 'mock_cre_seeded' ) === 1 ) {
			$missing_suite_posts = $wpdb->get_results( "SELECT id,suite_number FROM {$suites} WHERE COALESCE(post_id, 0) = 0", ARRAY_A );
			foreach ( $missing_suite_posts as $suite ) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'cre_suite',
						'post_status'  => 'publish',
						'post_title'   => '1126 Sam Newell Road - Suite ' . $suite['suite_number'],
						'post_content' => 'Local browser-test suite ' . $suite['suite_number'] . '.',
					)
				);
				if ( ! is_wp_error( $post_id ) && $post_id ) {
					$wpdb->update( $suites, array( 'post_id' => $post_id ), array( 'id' => (int) $suite['id'] ) );
				}
			}
			return;
		}

		$building_post = wp_insert_post(
			array(
				'post_type'    => 'cre_building',
				'post_status'  => 'publish',
				'post_title'   => '1126 Sam Newell Road',
				'post_content' => 'Local browser-test building.',
			)
		);
		$wpdb->insert(
			$buildings,
			array(
				'id'          => 1,
				'post_id'     => $building_post,
				'name'        => '1126 Sam Newell Road',
				'address1'    => '1126 Sam Newell Road',
				'city'        => 'Matthews',
				'state'       => 'NC',
				'postal_code' => '28105',
				'public'      => 1,
			)
		);

		$rows = array(
			array( 'D1', 80, 425, 'available', 'Salon / Office' ),
			array( 'D2', 80, 0, 'available' ),
			array( 'D3', 200, 950, 'coming_soon' ),
			array( 'D4', 130, 625, 'leased' ),
			array( 'D5', 130, 625, 'occupied' ),
			array( 'D6', 200, 995, 'available' ),
			array( 'D7', 145, 715, 'available' ),
			array( 'D8', 140, 0, 'hidden' ),
		);

		foreach ( $rows as $index => $row ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'cre_suite',
					'post_status'  => 'publish',
					'post_title'   => '1126 Sam Newell Road - Suite ' . $row[0],
					'post_content' => 'Local browser-test suite ' . $row[0] . '.',
				)
			);
			$wpdb->insert(
				$suites,
				array(
					'id'           => $index + 1,
					'building_id'  => 1,
					'post_id'      => $post_id,
					'suite_number' => $row[0],
					'floor'        => '1',
					'square_feet'  => $row[1],
					'monthly_rate' => $row[2],
					'base_status'  => $row[3],
					'best_use'     => $row[4] ?? 'Salon',
					'public'       => 1,
				)
			);
		}

		update_option( 'mock_cre_seeded', 1, false );
	},
	5
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'cre/v1',
			'/buildings',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;
					$rows = $wpdb->get_results( 'SELECT id,post_id,name,address1,city,state,postal_code FROM ' . CRE_Database::table( 'buildings' ) . ' WHERE archived_at IS NULL ORDER BY name', ARRAY_A );
					return rest_ensure_response( array( 'success' => true, 'data' => $rows, 'meta' => array( 'pages' => 1 ), 'error' => null ) );
				},
			)
		);
		register_rest_route(
			'cre/v1',
			'/suites',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					$building_id = absint( $request['building_id'] ?: 1 );
					$rows        = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*,b.name building_name FROM ' . CRE_Database::table( 'suites' ) . ' s JOIN ' . CRE_Database::table( 'buildings' ) . ' b ON b.id=s.building_id WHERE s.building_id=%d AND s.archived_at IS NULL ORDER BY s.id', $building_id ), ARRAY_A );
					foreach ( $rows as &$row ) {
						$row['availability'] = CRE_Domain::suite_availability( (int) $row['id'] );
						$row['url']          = $row['post_id'] ? get_permalink( (int) $row['post_id'] ) : '';
					}
					unset( $row );
					return rest_ensure_response( array( 'success' => true, 'data' => $rows, 'meta' => array( 'pages' => 1 ), 'error' => null ) );
				},
			)
		);
	}
);
