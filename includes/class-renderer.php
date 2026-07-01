<?php

defined( 'ABSPATH' ) || exit;

final class SLFP_Renderer {
	public static function boot(): void {
		add_shortcode( 'souder_floor_plan', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_head', array( __CLASS__, 'embed_styles' ), 100 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_for_embed' ) );
	}

	public static function embed_styles(): void {
		if ( empty( $_GET['slfp_embed'] ) ) {
			return;
		}
		?>
		<style id="slfp-embed-suite-css">
			html { margin-top: 0 !important; }
			#wpadminbar,
			.site-header,
			.site-footer {
				display: none !important;
			}
			html,
			body {
				background: #fff !important;
				color: #10251d !important;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
				overflow-x: hidden !important;
			}
			body {
				margin: 0 !important;
				padding: 0 !important;
			}
			main {
				display: block !important;
				width: 100% !important;
			}
			.wrap {
				box-sizing: border-box !important;
				width: 100% !important;
				max-width: 1120px !important;
				margin: 0 auto !important;
				padding: 0 24px !important;
			}
			.hero {
				box-sizing: border-box !important;
				margin: 0 !important;
				padding: 46px 0 !important;
				background: linear-gradient(135deg, #123c2f 0%, #1e6759 100%) !important;
				color: #fff !important;
			}
			.hero .eyebrow {
				margin-bottom: 16px !important;
				color: #9ee3d3 !important;
				font-size: 12px !important;
				font-weight: 800 !important;
				letter-spacing: .16em !important;
				text-transform: uppercase !important;
			}
			.hero h1 {
				max-width: 860px !important;
				margin: 0 !important;
				color: #fff !important;
				font-size: clamp(34px, 8vw, 66px) !important;
				line-height: .98 !important;
				letter-spacing: 0 !important;
			}
			.hero p {
				margin: 28px 0 0 !important;
				color: rgba(255, 255, 255, .86) !important;
				font-size: 17px !important;
			}
			.detail-title-row {
				display: flex !important;
				gap: 18px !important;
				align-items: center !important;
				justify-content: space-between !important;
			}
			.detail-favorite {
				flex: 0 0 auto !important;
				border: 1px solid rgba(255, 255, 255, .45) !important;
				border-radius: 999px !important;
				background: rgba(255, 255, 255, .1) !important;
				color: #fff !important;
				padding: 10px 18px !important;
			}
			.section,
			.suite-listing-section {
				margin: 0 !important;
				padding: 34px 0 !important;
				background: #f7faf8 !important;
			}
			.cre-listing-media-section header {
				display: grid !important;
				gap: 10px !important;
				margin: 0 0 20px !important;
			}
			.cre-listing-media-section header span {
				color: #167463 !important;
				font-size: 12px !important;
				font-weight: 900 !important;
				letter-spacing: .14em !important;
				text-transform: uppercase !important;
			}
			.cre-listing-media-section header h2,
			.suite-facts-card h2,
			.suite-contact-card h2 {
				margin: 0 !important;
				color: #10251d !important;
				font-size: clamp(26px, 6vw, 42px) !important;
				line-height: 1.08 !important;
			}
			.cre-listing-media-section header p,
			.suite-listing-content p,
			.suite-contact-card p {
				color: #52665d !important;
				font-size: 16px !important;
				line-height: 1.55 !important;
			}
			.cre-gallery,
			.suite-facts-card,
			.suite-contact-card {
				border-radius: 10px !important;
				background: #fff !important;
				box-shadow: 0 10px 34px rgba(16, 37, 29, .08) !important;
				overflow: hidden !important;
			}
			.cre-gallery img {
				max-width: 100% !important;
				height: auto !important;
			}
			.suite-detail-layout {
				display: grid !important;
				grid-template-columns: minmax(0, 1fr) minmax(260px, 360px) !important;
				gap: 18px !important;
				margin-top: 24px !important;
			}
			.suite-facts-card,
			.suite-contact-card {
				padding: 22px !important;
			}
			@media (max-width: 720px) {
				.wrap {
					padding: 0 20px !important;
				}
				.hero {
					padding: 38px 0 !important;
				}
				.detail-title-row,
				.suite-detail-layout {
					display: grid !important;
					grid-template-columns: 1fr !important;
				}
				.detail-favorite {
					justify-self: stretch !important;
				}
				.section,
				.suite-listing-section {
					padding: 28px 0 !important;
				}
			}
		</style>
		<?php
	}

	public static function hide_admin_bar_for_embed( bool $show ): bool {
		if ( ! empty( $_GET['slfp_embed'] ) ) {
			return false;
		}
		return $show;
	}

	public static function shortcode( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'souder_floor_plan' );
		$post_id = absint( $atts['id'] );
		if ( ! $post_id || SLFP_Plugin::POST_TYPE !== get_post_type( $post_id ) ) {
			return '';
		}
		wp_enqueue_style( 'slfp-frontend', SLFP_URL . 'assets/frontend.css', array(), SLFP_VERSION );
		wp_enqueue_script( 'slfp-frontend', SLFP_URL . 'assets/frontend.js', array(), SLFP_VERSION, true );
		$payload = SLFP_Rest::payload( $post_id );
		$uid = 'slfp-' . $post_id . '-' . wp_rand( 1000, 9999 );
		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>" class="slfp-floor-plan" data-slfp-public data-slfp-endpoint="<?php echo esc_url( rest_url( SLFP_Plugin::REST_NS . '/floor-plans/' . $post_id ) ); ?>">
			<div class="slfp-public-toolbar" aria-label="<?php esc_attr_e( 'Floor plan controls', 'souder-live-floor-plans' ); ?>">
				<input type="search" placeholder="<?php esc_attr_e( 'Search suite', 'souder-live-floor-plans' ); ?>" data-slfp-search>
				<label class="slfp-toggle"><input type="checkbox" data-slfp-available-only> <span><?php esc_html_e( 'Available only', 'souder-live-floor-plans' ); ?></span></label>
				<div class="slfp-zoom-controls">
					<button type="button" data-slfp-zoom-out aria-label="<?php esc_attr_e( 'Zoom out', 'souder-live-floor-plans' ); ?>">-</button>
					<button type="button" data-slfp-reset aria-label="<?php esc_attr_e( 'Reset view', 'souder-live-floor-plans' ); ?>"><?php esc_html_e( 'Reset', 'souder-live-floor-plans' ); ?></button>
					<button type="button" data-slfp-zoom-in aria-label="<?php esc_attr_e( 'Zoom in', 'souder-live-floor-plans' ); ?>">+</button>
				</div>
			</div>
			<div class="slfp-stage" data-slfp-stage>
				<div class="slfp-canvas" data-slfp-canvas>
					<?php if ( ! empty( $payload['image']['url'] ) ) : ?>
						<img src="<?php echo esc_url( $payload['image']['url'] ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" draggable="false">
					<?php else : ?>
						<div class="slfp-empty-public"><?php esc_html_e( 'Floor plan image unavailable.', 'souder-live-floor-plans' ); ?></div>
					<?php endif; ?>
					<div class="slfp-label-layer" data-slfp-label-layer></div>
				</div>
			</div>
			<div class="slfp-detail" data-slfp-detail hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
