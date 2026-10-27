<?php
/**
 * BSI Product Sync Admin
 *
 * Registers the "منتجات بوسطة" WooCommerce sub-menu page and provides:
 *  - Live statistics card (total / synced / pending)
 *  - Batch sync UI with animated progress bar
 *  - Per-product sync-status column in the products list
 *  - Single-product "Sync to Bosta" row action
 *
 * @package BSI_Product_Sync
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Product_Sync_Admin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'add_sync_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Products list: sync-status column
		add_filter( 'manage_edit-product_columns',        [ $this, 'add_bosta_column' ] );
		add_filter( 'manage_product_posts_columns',       [ $this, 'add_bosta_column' ] );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_bosta_column' ], 10, 2 );

		// Products list: single-product "Sync to Bosta" row action
		add_filter( 'post_row_actions', [ $this, 'add_sync_row_action' ], 10, 2 );
	}

	// ------------------------------------------------------------------
	// Menu registration
	// ------------------------------------------------------------------

	public function add_sync_page() {
		// Add under official Bosta menu
		add_submenu_page(
			'bosta-woocommerce',
			__( 'Bosta Product Sync', 'bosta-woocommerce' ),
			__( 'Bosta Product Sync', 'bosta-woocommerce' ),
			'manage_woocommerce',
			'bosta-product-sync',
			[ $this, 'render_sync_page' ],
			2
		);

		// Redirect alias for old slug bsi-product-sync
		add_submenu_page(
			null,
			'Bosta Product Sync',
			'Bosta Product Sync',
			'manage_woocommerce',
			'bsi-product-sync',
			function() {
				wp_safe_redirect( admin_url( 'admin.php?page=bosta-product-sync' ) );
				exit;
			}
		);
	}

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		$is_sync_page = (
			( isset( $_GET['page'] ) && in_array( $_GET['page'], [ 'bosta-product-sync', 'bsi-product-sync' ], true ) ) ||
			( $screen && strpos( $screen->id, 'bosta-product-sync' ) !== false )
		);

		$is_products_list = (
			$screen &&
			$screen->post_type === 'product' &&
			$screen->base === 'edit'
		);

		if ( ! $is_sync_page && ! $is_products_list ) {
			return;
		}

		// Dedicated style handle for BSI Admin CSS
		wp_register_style( 'bsi-product-sync-admin', false );
		wp_enqueue_style( 'bsi-product-sync-admin' );
		wp_add_inline_style( 'bsi-product-sync-admin', $this->get_inline_css() );

		// JS (depends only on jQuery — always available in WP admin)
		wp_enqueue_script(
			'bsi-product-sync',
			BOSTA_WC_PLUGIN_URL . 'assets/js/bsi-product-sync.js',
			[ 'jquery' ],
			BOSTA_WC_VERSION,
			true
		);

		wp_localize_script( 'bsi-product-sync', 'bsiSync', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'bsi_product_sync_nonce' ),
			'batchSize' => 10,
			'i18n'      => [
				'syncing'       => __( 'Syncing in progress...', 'bosta-woocommerce' ),
				'syncingStock'  => __( 'Syncing Stock & Data...', 'bosta-woocommerce' ),
				'done'          => __( 'Sync completed successfully! ✓', 'bosta-woocommerce' ),
				'stopped'       => __( 'Sync stopped.', 'bosta-woocommerce' ),
				'error'         => __( 'Error: ', 'bosta-woocommerce' ),
				'confirmStock'  => __( 'This will update stock levels and product details for all products on Bosta in batches of 10. Do you want to proceed?', 'bosta-woocommerce' ),
				'confirmNew'    => __( 'This will scan and register any new unsynced products to Bosta. Do you want to proceed?', 'bosta-woocommerce' ),
				'of'            => __( 'of', 'bosta-woocommerce' ),
				'products'      => __( 'products', 'bosta-woocommerce' ),
				'autoEnabled'   => __( 'Enabled ✓', 'bosta-woocommerce' ),
				'autoDisabled'  => __( 'Disabled', 'bosta-woocommerce' ),
				'syncOk'        => __( 'Synced ✓', 'bosta-woocommerce' ),
				'syncFail'      => __( 'Sync Failed', 'bosta-woocommerce' ),
			],
		] );
	}

	// ------------------------------------------------------------------
	// Sync page HTML
	// ------------------------------------------------------------------

	public function render_sync_page() {
		?>
		<div class="wrap bsi-wrap" dir="ltr">

			<h1 class="bsi-page-title">
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:8px;"><polyline points="1 4 1 10 7 10"></polyline><polyline points="23 20 23 14 17 14"></polyline><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path></svg>
				<?php esc_html_e( 'Bosta Product & Stock Sync', 'bosta-woocommerce' ); ?>
			</h1>

			<!-- ── Stats Card ───────────────────────────────────────── -->
			<div class="bsi-card">
				<div class="bsi-card-header">
					<h2><?php esc_html_e( 'Catalog Synchronization Overview', 'bosta-woocommerce' ); ?></h2>
					<button type="button" id="bsi-refresh-stats" class="bsi-btn-icon" title="Refresh Statistics">
						<span class="dashicons dashicons-update-alt"></span>
					</button>
				</div>
				<div class="bsi-card-body">
					<div class="bsi-stats-grid">
						<div class="bsi-stat">
							<span class="dashicons dashicons-products bsi-stat-icon"></span>
							<div>
								<span class="bsi-stat-val" id="bsi-total">—</span>
								<span class="bsi-stat-lbl"><?php esc_html_e( 'Total Products', 'bosta-woocommerce' ); ?></span>
							</div>
						</div>
						<div class="bsi-stat bsi-stat--green">
							<span class="dashicons dashicons-yes-alt bsi-stat-icon"></span>
							<div>
								<span class="bsi-stat-val" id="bsi-synced">—</span>
								<span class="bsi-stat-lbl"><?php esc_html_e( 'Synced with Bosta', 'bosta-woocommerce' ); ?></span>
							</div>
						</div>
						<div class="bsi-stat bsi-stat--orange">
							<span class="dashicons dashicons-clock bsi-stat-icon"></span>
							<div>
								<span class="bsi-stat-val" id="bsi-pending">—</span>
								<span class="bsi-stat-lbl"><?php esc_html_e( 'Pending Sync', 'bosta-woocommerce' ); ?></span>
							</div>
						</div>
						<div class="bsi-stat bsi-stat--blue">
							<span class="dashicons dashicons-update bsi-stat-icon"></span>
							<div>
								<span class="bsi-stat-val" id="bsi-auto-sync">—</span>
								<span class="bsi-stat-lbl"><?php esc_html_e( 'Real-Time Auto-Sync', 'bosta-woocommerce' ); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ── Sync Card ────────────────────────────────────────── -->
			<div class="bsi-card">
				<div class="bsi-card-header">
					<h2><?php esc_html_e( 'Batch Synchronization Operations', 'bosta-woocommerce' ); ?></h2>
				</div>
				<div class="bsi-card-body">

					<p class="bsi-desc">
						<?php esc_html_e( 'Products and stock quantities are processed in safe batches of 10 products per request to ensure maximum server stability and zero gateway timeouts.', 'bosta-woocommerce' ); ?>
					</p>

					<!-- Progress bar (hidden until sync starts) -->
					<div id="bsi-progress-wrap" style="display:none;" class="bsi-progress-wrap">
						<div class="bsi-progress-info">
							<span id="bsi-progress-label"><?php esc_html_e( 'Syncing in progress...', 'bosta-woocommerce' ); ?></span>
							<span id="bsi-progress-counter">0 / 0</span>
						</div>
						<div class="bsi-progress-track">
							<div class="bsi-progress-bar" id="bsi-progress-bar"></div>
						</div>
						<div id="bsi-errors-list" class="bsi-errors-list" style="display:none;"></div>
					</div>

					<!-- Success notice -->
					<div id="bsi-success-notice" class="bsi-notice bsi-notice--success" style="display:none;">
						<span class="dashicons dashicons-yes-alt"></span>
						<span id="bsi-success-text"></span>
					</div>

					<!-- Buttons -->
					<div class="bsi-actions">
						<button type="button" id="bsi-btn-sync-stock" class="button button-primary bsi-btn-primary">
							<span class="dashicons dashicons-update-alt"></span>
							<?php esc_html_e( 'Sync Stock & Data for Existing Products', 'bosta-woocommerce' ); ?>
						</button>
						<button type="button" id="bsi-btn-start" class="button button-secondary bsi-btn-secondary">
							<span class="dashicons dashicons-plus-alt"></span>
							<?php esc_html_e( 'Sync New Products Only', 'bosta-woocommerce' ); ?>
						</button>
						<button type="button" id="bsi-btn-stop" class="button bsi-btn-stop" style="display:none;">
							<span class="dashicons dashicons-no"></span>
							<?php esc_html_e( 'Stop Sync', 'bosta-woocommerce' ); ?>
						</button>
					</div>

					<div class="bsi-hints" style="margin-top: 18px; font-size: 12.5px; color: #64748b; line-height: 1.6;">
						<p style="margin: 4px 0;"><strong>• Sync Stock & Data for Existing Products:</strong> <?php esc_html_e( 'Updates stock levels, prices, and variation attributes on Bosta for already-synced products.', 'bosta-woocommerce' ); ?></p>
						<p style="margin: 4px 0;"><strong>• Sync New Products Only:</strong> <?php esc_html_e( 'Fast-skips already-synced products in 0ms and registers any newly published products.', 'bosta-woocommerce' ); ?></p>
						<p style="margin: 4px 0;"><strong>• Real-Time Auto-Sync:</strong> <?php esc_html_e( 'Can be enabled or disabled anytime via Bosta > Setting > Enable Product Auto Sync.', 'bosta-woocommerce' ); ?></p>
					</div>

				</div><!-- .bsi-card-body -->
			</div><!-- .bsi-card -->

		</div><!-- .bsi-wrap -->
		<?php
	}

	// ------------------------------------------------------------------
	// Products list: Bosta sync-status column
	// ------------------------------------------------------------------

	public function add_bosta_column( $columns ) {
		// Insert after the "name" column
		$new = [];
		foreach ( $columns as $key => $value ) {
			$new[ $key ] = $value;
			if ( $key === 'name' ) {
				$new['bosta_sync_status'] = sprintf(
					'<span class="dashicons dashicons-update-alt bsi-col-icon" title="%s"></span>',
					esc_attr__( 'Bosta Sync', 'bosta-woocommerce' )
				);
			}
		}
		return $new;
	}

	public function render_bosta_column( $column, $product_id ) {
		if ( $column !== 'bosta_sync_status' ) {
			return;
		}

		$bosta_id = get_post_meta( $product_id, '_bosta_product_id', true );

		if ( ! empty( $bosta_id ) ) {
			printf(
				'<span class="bsi-badge bsi-badge--synced" title="%s">✓</span>',
				esc_attr( 'Bosta ID: ' . $bosta_id )
			);
		} else {
			echo '<span class="bsi-badge bsi-badge--pending" title="Not synced">—</span>';
		}
	}

	// ------------------------------------------------------------------
	// Products list: "Sync to Bosta" row action
	// ------------------------------------------------------------------

	public function add_sync_row_action( $actions, $post ) {
		if ( $post->post_type !== 'product' ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		$bosta_id  = get_post_meta( $post->ID, '_bosta_product_id', true );
		$label     = empty( $bosta_id )
			? __( 'Sync to Bosta', 'bosta-woocommerce' )
			: __( 'Update on Bosta', 'bosta-woocommerce' );

		$actions['bsi_sync'] = sprintf(
			'<a href="#" class="bsi-row-sync" data-product-id="%d" data-nonce="%s">%s</a>',
			$post->ID,
			wp_create_nonce( 'bsi_product_sync_nonce' ),
			esc_html( $label )
		);

		return $actions;
	}

	// ------------------------------------------------------------------
	// Inline CSS
	// ------------------------------------------------------------------

	private function get_inline_css() {
		return '
/* ── BSI Wrap ─────────────────────────────────────────────────────── */
.bsi-wrap { max-width: 900px; }
.bsi-page-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 20px;
}

/* ── Card ──────────────────────────────────────────────────────────── */
.bsi-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.06);
    overflow: hidden;
}
.bsi-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px; border-bottom: 1px solid #f1f5f9; background: #f8fafc;
}
.bsi-card-header h2 { margin: 0; font-size: 15px; color: #334155; }
.bsi-card-body { padding: 24px; }
.bsi-btn-icon {
    background: none; border: none; cursor: pointer; color: #64748b;
    padding: 4px; border-radius: 4px; transition: color .2s;
}
.bsi-btn-icon:hover { color: #0ea5e9; }
.bsi-desc { color: #64748b; font-size: 13.5px; margin: 0 0 20px; line-height: 1.7; }

/* ── Stats grid ────────────────────────────────────────────────────── */
.bsi-stats-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
}
.bsi-stat {
    display: flex; align-items: center; gap: 14px;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 16px; transition: box-shadow .2s;
}
.bsi-stat:hover { box-shadow: 0 4px 12px rgba(0,0,0,.06); }
.bsi-stat-icon { font-size: 28px !important; width: 28px !important; height: 28px !important; color: #94a3b8; }
.bsi-stat--green .bsi-stat-icon { color: #10b981; }
.bsi-stat--orange .bsi-stat-icon { color: #f59e0b; }
.bsi-stat--blue .bsi-stat-icon { color: #0ea5e9; }
.bsi-stat-val { display: block; font-size: 28px; font-weight: 700; color: #1e293b; line-height: 1; }
.bsi-stat-lbl { display: block; font-size: 12px; color: #64748b; margin-top: 4px; }

/* ── Progress bar ──────────────────────────────────────────────────── */
.bsi-progress-wrap { margin-bottom: 20px; }
.bsi-progress-info {
    display: flex; justify-content: space-between;
    font-size: 13px; color: #475569; margin-bottom: 8px;
}
.bsi-progress-track {
    background: #e2e8f0; border-radius: 50px; height: 10px; overflow: hidden;
}
.bsi-progress-bar {
    height: 100%; width: 0; border-radius: 50px;
    background: linear-gradient(90deg, #0ea5e9, #38bdf8);
    transition: width .4s ease;
}

/* ── Errors list ───────────────────────────────────────────────────── */
.bsi-errors-list {
    margin-top: 12px; padding: 12px 16px; background: #fef2f2;
    border: 1px solid #fecaca; border-radius: 6px; max-height: 180px;
    overflow-y: auto;
}
.bsi-error-item { color: #b91c1c; font-size: 12.5px; padding: 3px 0; }

/* ── Notices ───────────────────────────────────────────────────────── */
.bsi-notice {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;
    font-size: 14px; font-weight: 500;
}
.bsi-notice--success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.bsi-notice--success .dashicons { color: #22c55e; font-size: 20px; }

/* ── Action buttons ────────────────────────────────────────────────── */
.bsi-actions { display: flex; gap: 12px; align-items: center; }
.bsi-btn-primary {
    display: inline-flex !important; align-items: center; gap: 6px;
    background: #0ea5e9 !important; border-color: #0284c7 !important;
    color: #fff !important; height: 38px; padding: 0 18px !important;
    border-radius: 6px !important; font-weight: 600 !important;
    transition: background .2s !important;
}
.bsi-btn-primary:hover { background: #0284c7 !important; }
.bsi-btn-stop {
    background: #fff !important; border-color: #e2e8f0 !important;
    color: #64748b !important; height: 38px;
}

/* ── Products list column ──────────────────────────────────────────── */
.column-bosta_sync_status { width: 50px !important; text-align: center !important; }
.bsi-col-icon { color: #0ea5e9; }
.bsi-badge {
    display: inline-block; width: 24px; height: 24px;
    border-radius: 50%; font-size: 13px; font-weight: 700;
    line-height: 24px; text-align: center;
}
.bsi-badge--synced { background: #d1fae5; color: #065f46; }
.bsi-badge--pending { background: #f1f5f9; color: #94a3b8; }

/* ── Row action ────────────────────────────────────────────────────── */
.bsi-row-sync { color: #0ea5e9 !important; }
.bsi-row-sync:hover { color: #0284c7 !important; }
.bsi-row-sync.bsi-syncing { opacity: .6; pointer-events: none; }

/* ── Spinner Animation ─────────────────────────────────────────────── */
@keyframes bsi-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.bsi-spinning .dashicons { display: inline-block; animation: bsi-spin 0.8s linear infinite; }
		';
	}
}
