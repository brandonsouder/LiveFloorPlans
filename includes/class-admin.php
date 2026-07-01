<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Admin {
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'metaboxes' ) );
		add_action( 'save_post_' . SLFP_Plugin::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function metaboxes(): void {
		add_meta_box( 'slfp-settings', __( 'Floor Plan Settings', 'souder-live-floor-plans' ), array( __CLASS__, 'settings_box' ), SLFP_Plugin::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'slfp-editor', __( 'Suite Overlay Editor', 'souder-live-floor-plans' ), array( __CLASS__, 'editor_box' ), SLFP_Plugin::POST_TYPE, 'normal', 'default' );
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || SLFP_Plugin::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'slfp-admin', SLFP_URL . 'assets/admin.css', array(), SLFP_VERSION );
		wp_enqueue_script( 'slfp-admin', SLFP_URL . 'assets/admin.js', array(), SLFP_VERSION, true );
		$post_id = absint( $_GET['post'] ?? 0 );
		wp_localize_script(
			'slfp-admin',
			'SLFPAdmin',
			array(
				'postId'  => $post_id,
				'restUrl' => $post_id ? esc_url_raw( rest_url( SLFP_Plugin::REST_NS . '/floor-plans/' . $post_id ) ) : '',
				'buildingsRestUrl' => esc_url_raw( rest_url( SLFP_Plugin::REST_NS . '/buildings' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'saved'       => __( 'Overlay positions saved.', 'souder-live-floor-plans' ),
					'syncing'     => __( 'Syncing suites...', 'souder-live-floor-plans' ),
					'synced'      => __( 'Suites synced.', 'souder-live-floor-plans' ),
					'saveFailed'  => __( 'Could not save overlays.', 'souder-live-floor-plans' ),
					'syncFailed'  => __( 'Could not sync suites.', 'souder-live-floor-plans' ),
					'selectImage' => __( 'Select floor-plan image', 'souder-live-floor-plans' ),
					'loadingBuildings' => __( 'Loading buildings...', 'souder-live-floor-plans' ),
					'chooseBuilding' => __( 'Choose a building', 'souder-live-floor-plans' ),
				),
			)
		);
	}

	public static function settings_box( WP_Post $post ): void {
		wp_nonce_field( 'slfp_save_' . $post->ID, 'slfp_nonce' );
		$image_id = absint( get_post_meta( $post->ID, SLFP_Plugin::META_IMAGE_ID, true ) );
		$image = SLFP_Plugin::image_payload( $image_id );
		$building_id = absint( get_post_meta( $post->ID, SLFP_Plugin::META_BUILDING_ID, true ) );
		$buildings = SLFP_Suite_Provider::fetch_buildings();
		$status = (string) get_post_meta( $post->ID, SLFP_Plugin::META_SYNC_STATUS, true );
		$error = (string) get_post_meta( $post->ID, SLFP_Plugin::META_SYNC_ERROR, true );
		$synced = (string) get_post_meta( $post->ID, SLFP_Plugin::META_SYNCED_AT, true );
		?>
		<div class="slfp-settings-grid">
			<label>
				<span><?php esc_html_e( 'CRE building', 'souder-live-floor-plans' ); ?></span>
				<select data-slfp-building-select>
					<option value=""><?php esc_html_e( 'Choose a building', 'souder-live-floor-plans' ); ?></option>
					<?php if ( ! is_wp_error( $buildings ) ) : ?>
						<?php foreach ( $buildings as $building ) : ?>
							<?php
							$label = trim( (string) ( $building['name'] ?? '' ) );
							if ( ! empty( $building['address'] ) ) {
								$label .= ' — ' . $building['address'];
							}
							$label .= ' (#' . absint( $building['id'] ?? 0 ) . ')';
							?>
							<option value="<?php echo esc_attr( (string) absint( $building['id'] ?? 0 ) ); ?>" <?php selected( $building_id, absint( $building['id'] ?? 0 ) ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<?php if ( is_wp_error( $buildings ) ) : ?>
					<small class="slfp-error"><?php echo esc_html( $buildings->get_error_message() ); ?></small>
				<?php elseif ( empty( $buildings ) ) : ?>
					<small class="slfp-error"><?php esc_html_e( 'No CRE buildings were found. You can still enter the ID manually.', 'souder-live-floor-plans' ); ?></small>
				<?php endif; ?>
				<input type="number" min="1" name="slfp_building_id" id="slfp-building-id" value="<?php echo esc_attr( (string) $building_id ); ?>" data-slfp-building-id>
				<small><?php esc_html_e( 'Pick a building from the list, or enter the CRE building ID manually. Syncing will save the selected ID.', 'souder-live-floor-plans' ); ?></small>
			</label>
			<div>
				<span class="slfp-field-label"><?php esc_html_e( 'Floor-plan image', 'souder-live-floor-plans' ); ?></span>
				<input type="hidden" name="slfp_image_id" id="slfp-image-id" value="<?php echo esc_attr( (string) $image_id ); ?>">
				<button type="button" class="button" data-slfp-select-image><?php esc_html_e( 'Choose image', 'souder-live-floor-plans' ); ?></button>
				<button type="button" class="button" data-slfp-clear-image><?php esc_html_e( 'Clear', 'souder-live-floor-plans' ); ?></button>
				<div class="slfp-image-preview" data-slfp-image-preview>
					<?php if ( $image['url'] ) : ?>
						<img src="<?php echo esc_url( $image['url'] ); ?>" alt="">
					<?php endif; ?>
				</div>
			</div>
			<div class="slfp-sync-card">
				<span class="slfp-field-label"><?php esc_html_e( 'Suite sync', 'souder-live-floor-plans' ); ?></span>
				<p><strong><?php echo esc_html( $status ? ucwords( str_replace( '_', ' ', $status ) ) : __( 'Never synced', 'souder-live-floor-plans' ) ); ?></strong></p>
				<?php if ( $synced ) : ?><p><?php echo esc_html( $synced ); ?></p><?php endif; ?>
				<?php if ( $error ) : ?><p class="slfp-error"><?php echo esc_html( $error ); ?></p><?php endif; ?>
				<button type="button" class="button button-primary" data-slfp-sync><?php esc_html_e( 'Sync suites now', 'souder-live-floor-plans' ); ?></button>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Use the shortcode shown in the list table to embed this floor plan on a public page.', 'souder-live-floor-plans' ); ?></p>
		<?php
	}

	public static function editor_box( WP_Post $post ): void {
		$image_id = absint( get_post_meta( $post->ID, SLFP_Plugin::META_IMAGE_ID, true ) );
		$image = SLFP_Plugin::image_payload( $image_id );
		?>
		<div class="slfp-admin-app" data-slfp-admin-app>
			<div class="slfp-admin-toolbar">
				<input type="search" placeholder="<?php esc_attr_e( 'Search suites', 'souder-live-floor-plans' ); ?>" data-slfp-search>
				<select data-slfp-filter>
					<option value="all"><?php esc_html_e( 'All suites', 'souder-live-floor-plans' ); ?></option>
					<option value="mapped"><?php esc_html_e( 'Mapped', 'souder-live-floor-plans' ); ?></option>
					<option value="unmapped"><?php esc_html_e( 'Unmapped', 'souder-live-floor-plans' ); ?></option>
				</select>
				<button type="button" class="button button-primary" data-slfp-save-overlays><?php esc_html_e( 'Save overlay positions', 'souder-live-floor-plans' ); ?></button>
				<span class="slfp-admin-status" data-slfp-status aria-live="polite"></span>
			</div>
			<div class="slfp-admin-layout">
				<div class="slfp-admin-map" data-slfp-map>
					<?php if ( $image['url'] ) : ?>
						<img src="<?php echo esc_url( $image['url'] ); ?>" alt="" data-slfp-map-image>
					<?php else : ?>
						<div class="slfp-empty-map"><?php esc_html_e( 'Choose a floor-plan image, save, then place suite labels.', 'souder-live-floor-plans' ); ?></div>
					<?php endif; ?>
					<div class="slfp-admin-label-layer" data-slfp-label-layer></div>
				</div>
				<div class="slfp-suite-panel">
					<div class="slfp-suite-panel-head">
						<strong><?php esc_html_e( 'Suites', 'souder-live-floor-plans' ); ?></strong>
						<span data-slfp-suite-count></span>
					</div>
					<div class="slfp-suite-list" data-slfp-suite-list></div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! SLFP_Plugin::can_manage() || ! isset( $_POST['slfp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slfp_nonce'] ) ), 'slfp_save_' . $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, SLFP_Plugin::META_IMAGE_ID, absint( $_POST['slfp_image_id'] ?? 0 ) );
		update_post_meta( $post_id, SLFP_Plugin::META_BUILDING_ID, absint( $_POST['slfp_building_id'] ?? 0 ) );
	}
}
