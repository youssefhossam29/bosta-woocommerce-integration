<?php
/**
 * BSI Product Sync — Core Engine
 *
 * Provides two complementary capabilities:
 *
 *  1. BATCHED BULK MIGRATION  — syncs the entire WooCommerce catalogue to Bosta
 *     in safe chunks of 20 products to avoid PHP/nginx timeouts.
 *
 *  2. REAL-TIME STOCK SYNC   — listens to the stock-change hooks that the official
 *     Bosta plugin misses (`woocommerce_product_set_stock` and
 *     `woocommerce_variation_set_stock`) and pushes the updated stock to Bosta
 *     asynchronously so the customer checkout is never blocked.
 *
 * This class does NOT modify the official `bosta-woocommerce` plugin.
 * It delegates formatting / API calls to `Bosta_Product_Sync` (official) so that
 * any future update to the official plugin is automatically picked up.
 *
 * @package BSI_Product_Sync
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Product_Sync {

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	/** Number of products to process per AJAX batch (reduced to 10 for maximum stability and 0 timeouts). */
	const BATCH_SIZE = 10;

	/** WooCommerce postmeta key used by the official plugin for Bosta product ID. */
	const META_BOSTA_PRODUCT_ID = '_bosta_product_id';

	/** Custom postmeta key to cache the Bosta variation-ID map (JSON). */
	const META_VARIATION_MAP = '_bosta_variation_ids_map';

	/** WordPress option flag: 'yes' when the initial bulk sync has been completed. */
	const OPT_SYNC_ENABLED = 'bsi_product_sync_enabled';

	// ------------------------------------------------------------------
	// Bootstrap
	// ------------------------------------------------------------------

	/**
	 * Register all hooks.
	 * Must be called once (from the main plugin file) after plugins_loaded.
	 */
	public static function init() {
		// Replace unenhanced official plugin hooks with our robust handlers
		remove_action( 'woocommerce_new_product', [ 'Bosta_Product_Sync', 'handle_product_create' ], 10 );
		remove_action( 'woocommerce_update_product', [ 'Bosta_Product_Sync', 'handle_product_update' ], 10 );

		// Real-time hooks for newly published products and updated products
		add_action( 'woocommerce_new_product',    [ __CLASS__, 'handle_product_create' ], 10, 1 );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'handle_product_update' ], 10, 1 );

		// Real-time stock sync hooks (checkout order stock reduction, manual stock changes)
		add_action( 'woocommerce_product_set_stock',   [ __CLASS__, 'schedule_stock_sync' ] );
		add_action( 'woocommerce_variation_set_stock', [ __CLASS__, 'schedule_variation_stock_sync' ] );

		// WP-Cron / Action Scheduler async handlers
		add_action( 'bsi_async_sync_product_stock',   [ __CLASS__, 'handle_async_product_stock_sync' ] );
		add_action( 'bsi_async_sync_variation_stock', [ __CLASS__, 'handle_async_variation_stock_sync' ] );

		// Admin AJAX endpoints (logged-in users only)
		add_action( 'wp_ajax_bsi_sync_batch',      [ __CLASS__, 'ajax_sync_batch' ] );
		add_action( 'wp_ajax_bsi_get_sync_status', [ __CLASS__, 'ajax_get_sync_status' ] );
		add_action( 'wp_ajax_bsi_sync_single',     [ __CLASS__, 'ajax_sync_single' ] );
	}

	// ==================================================================
	// SECTION 1 — BATCHED BULK MIGRATION
	// ==================================================================

	/**
	 * Return the total number of published WooCommerce products.
	 *
	 * @return int
	 */
	public static function get_products_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type   = 'product'
			   AND post_status = 'publish'"
		);
	}

	/**
	 * Return an ordered array of published product IDs for a given page.
	 *
	 * @param int $offset     Number of products to skip.
	 * @param int $batch_size Number of products to fetch.
	 * @return int[]
	 */
	public static function get_batch_product_ids( $offset, $batch_size = self::BATCH_SIZE ) {
		return get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );
	}

	/**
	 * Process one batch of products.
	 * Products not yet on Bosta are created via POST /products/bulk.
	 * Products already on Bosta are updated via PUT /products/:id.
	 *
	 * @param int $offset     Where to start in the full product list.
	 * @param int $batch_size How many products to handle.
	 * @return array
	 */
	public static function sync_batch( $offset, $batch_size = self::BATCH_SIZE, $mode = 'stock' ) {
		// Dependency guard
		if ( ! self::official_plugin_ready() ) {
			return [
				'success' => false,
				'message' => 'Official Bosta WooCommerce plugin is not active or missing required functions.',
				'done'    => true,
			];
		}

		$api_key = bosta_get_api_key();
		if ( empty( $api_key ) ) {
			return [
				'success' => false,
				'message' => 'Bosta API Key is missing. Please configure it in Bosta settings.',
				'done'    => true,
			];
		}

		$total       = self::get_products_count();
		$product_ids = self::get_batch_product_ids( $offset, $batch_size );

		if ( empty( $product_ids ) ) {
			return [
				'success'   => true,
				'processed' => 0,
				'total'     => $total,
				'offset'    => $offset,
				'errors'    => [],
				'done'      => true,
			];
		}

		// Separate into new (unsynced) vs. existing (already synced)
		$unsynced = [];
		$synced   = [];

		foreach ( $product_ids as $pid ) {
			$bosta_id = get_post_meta( $pid, self::META_BOSTA_PRODUCT_ID, true );
			if ( empty( $bosta_id ) ) {
				$unsynced[] = $pid;
			} else {
				$synced[] = [ 'product_id' => $pid, 'bosta_id' => $bosta_id ];
			}
		}

		$processed = 0;
		$errors    = [];

		// === CREATE: unsynced products via bulk endpoint ===
		if ( ! empty( $unsynced ) ) {
			$formatted = [];
			$pid_map   = []; // referenceId => wc product_id

			foreach ( $unsynced as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					$processed++;
					continue;
				}
				$ref             = 'WC-' . $pid;
				$formatted[]     = self::format_product_for_bosta( $product );
				$pid_map[ $ref ] = $pid;
			}

			if ( ! empty( $formatted ) ) {
				$url      = BOSTA_ENV_URL_V2 . '/products/bulk';
				$response = bosta_send_api_request( 'POST', $url, $api_key, [ 'products' => $formatted ] );

				if (
					! empty( $response['success'] ) &&
					! empty( $response['body']['success'] )
				) {
					$created_list = isset( $response['body']['data']['createdProducts'] )
						? $response['body']['data']['createdProducts']
						: [];

					foreach ( $created_list as $item ) {
						if ( empty( $item['referenceId'] ) || empty( $item['id'] ) ) {
							continue;
						}
						$ref    = $item['referenceId'];
						$wc_pid = isset( $pid_map[ $ref ] )
							? $pid_map[ $ref ]
							: ( strpos( $ref, 'WC-' ) === 0
								? intval( substr( $ref, 3 ) )
								: intval( $ref ) );

						if ( $wc_pid > 0 ) {
							update_post_meta( $wc_pid, self::META_BOSTA_PRODUCT_ID, $item['id'] );
							if ( ! empty( $item['productsVariances'] ) ) {
								self::save_variation_id_map( $wc_pid, $item );
							}
						}
						$processed++;
					}

					// Bosta sometimes does not return createdProducts list
					if ( empty( $created_list ) ) {
						$processed += count( $formatted );
					}
				} else {
					// Fallback: If bulk fails (e.g. 1 bad product in the batch), try syncing products individually
					foreach ( $unsynced as $pid ) {
						$product = wc_get_product( $pid );
						if ( ! $product ) {
							$processed++;
							continue;
						}
						$single_res = self::sync_single_product_to_bosta( $product );
						if ( ! empty( $single_res['success'] ) ) {
							$processed++;
							$b_id = get_post_meta( $pid, self::META_BOSTA_PRODUCT_ID, true );
							if ( $product->is_type( 'variable' ) && ! empty( $b_id ) ) {
								$b_data = Bosta_Product_Sync::fetch_product_from_bosta( $b_id );
								if ( ! empty( $b_data ) ) {
									self::save_variation_id_map( $pid, $b_data );
								}
							}
						} else {
							$err_msg   = isset( $single_res['message'] ) ? $single_res['message'] : 'Unknown error';
							$errors[]  = sprintf( 'Failed to create product #%d (%s): %s', $pid, $product->get_name(), $err_msg );
							$processed++;
						}
					}
				}
			}
		}

		// === UPDATE STOCK & DATA OR FAST SKIP ===
		if ( $mode === 'stock' || $mode === 'all' ) {
			// Update stock and product data for existing products on Bosta
			if ( ! empty( $synced ) ) {
				foreach ( $synced as $item ) {
					$pid     = $item['product_id'];
					$bid     = $item['bosta_id'];
					$product = wc_get_product( $pid );
					if ( ! $product ) {
						$processed++;
						continue;
					}
					$update_res = self::update_single_product_on_bosta( $product, $bid );
					if ( ! empty( $update_res['success'] ) ) {
						$processed++;
					} else {
						$err_msg  = isset( $update_res['message'] ) ? $update_res['message'] : 'Update failed';
						$errors[] = sprintf( 'Failed to update stock for #%d (%s): %s', $pid, $product->get_name(), $err_msg );
						$processed++;
					}
				}
			}
		} else {
			// Fast Skip: already-synced products are skipped instantly in 0ms
			$processed += count( $synced );
		}

		$new_offset = $offset + $batch_size;
		$done       = ( $new_offset >= $total );

		return [
			'success'   => true,
			'processed' => $processed,
			'total'     => $total,
			'offset'    => $new_offset,
			'errors'    => $errors,
			'done'      => $done,
		];
	}

	// ------------------------------------------------------------------
	// AJAX: batch sync
	// ------------------------------------------------------------------

	public static function ajax_sync_batch() {
		check_ajax_referer( 'bsi_product_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'You do not have permission to perform this action.' ] );
		}

		$offset     = isset( $_POST['offset'] )     ? intval( $_POST['offset'] )     : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : self::BATCH_SIZE;
		$batch_size = max( 1, min( 50, $batch_size ) );
		$mode       = isset( $_POST['mode'] )       ? sanitize_text_field( $_POST['mode'] ) : 'stock';

		$result = self::sync_batch( $offset, $batch_size, $mode );

		if ( $result['success'] ) {
			if ( ! empty( $result['done'] ) ) {
				update_option( 'bosta_initial_product_sync_completed', true );
			}
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// ------------------------------------------------------------------
	// AJAX: sync status
	// ------------------------------------------------------------------

	public static function ajax_get_sync_status() {
		check_ajax_referer( 'bsi_product_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		global $wpdb;
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status = 'publish'"
		);
		$synced = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_bosta_product_id' AND meta_value != ''"
		);

		wp_send_json_success( [
			'total'             => $total,
			'synced'            => $synced,
			'pending'           => max( 0, $total - $synced ),
			'sync_enabled'      => self::is_auto_sync_enabled(),
			'auto_sync_enabled' => self::is_auto_sync_enabled(),
		] );
	}

	// ------------------------------------------------------------------
	// AJAX: sync single product
	// ------------------------------------------------------------------

	public static function ajax_sync_single() {
		check_ajax_referer( 'bsi_product_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'ليس لديك صلاحية.' ] );
		}

		if ( ! self::official_plugin_ready() ) {
			wp_send_json_error( [ 'message' => 'البلاجن الرسمية غير متاحة.' ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error( [ 'message' => 'المنتج غير موجود.' ] );
		}

		$bosta_id = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );

		$result = ! empty( $bosta_id )
			? Bosta_Product_Sync::update_single_product_on_bosta( $product, $bosta_id )
			: self::sync_single_product_to_bosta( $product );

		if ( $result['success'] ) {
			if ( empty( $bosta_id ) && $product->is_type( 'variable' ) ) {
				$new_bosta_id = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );
				if ( ! empty( $new_bosta_id ) ) {
					$b_data = Bosta_Product_Sync::fetch_product_from_bosta( $new_bosta_id );
					if ( ! empty( $b_data ) ) {
						self::save_variation_id_map( $product_id, $b_data );
					}
				}
			}
			wp_send_json_success( [ 'message' => 'تمت مزامنة المنتج بنجاح.' ] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	// ==================================================================
	// SECTION 2 — REAL-TIME STOCK SYNC
	// ==================================================================

	/**
	 * Schedule async stock-sync for a simple product.
	 * Hooked to: woocommerce_product_set_stock
	 *
	 * @param WC_Product $product
	 */
	public static function schedule_stock_sync( $product ) {
		if ( ! self::is_auto_sync_enabled() || ! ( $product instanceof WC_Product ) ) {
			return;
		}

		if ( $product->is_type( 'variation' ) ) {
			self::schedule_variation_stock_sync( $product );
			return;
		}

		$product_id = $product->get_id();
		$bosta_id   = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );
		if ( empty( $bosta_id ) ) {
			return;
		}

		$debounce_key = 'bsi_debounce_product_' . $product_id;
		if ( get_transient( $debounce_key ) ) {
			return;
		}
		set_transient( $debounce_key, 1, 60 );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 10,
				'bsi_async_sync_product_stock',
				[ 'product_id' => $product_id ],
				'bsi-product-sync'
			);
		} else {
			wp_schedule_single_event( time() + 10, 'bsi_async_sync_product_stock', [ $product_id ] );
		}
	}

	/**
	 * Schedule async stock-sync for a product variation.
	 * Hooked to: woocommerce_variation_set_stock
	 *
	 * @param WC_Product_Variation|WC_Product $variation
	 */
	public static function schedule_variation_stock_sync( $variation ) {
		if ( ! self::is_auto_sync_enabled() ) {
			return;
		}

		$parent_id = method_exists( $variation, 'get_parent_id' )
			? $variation->get_parent_id()
			: wp_get_post_parent_id( $variation->get_id() );

		if ( empty( $parent_id ) ) {
			return;
		}

		$bosta_id = get_post_meta( $parent_id, self::META_BOSTA_PRODUCT_ID, true );
		if ( empty( $bosta_id ) ) {
			return;
		}

		$debounce_key = 'bsi_debounce_variation_parent_' . $parent_id;
		if ( get_transient( $debounce_key ) ) {
			return;
		}
		set_transient( $debounce_key, 1, 60 );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 10,
				'bsi_async_sync_variation_stock',
				[ 'parent_id' => $parent_id ],
				'bsi-product-sync'
			);
		} else {
			wp_schedule_single_event( time() + 10, 'bsi_async_sync_variation_stock', [ $parent_id ] );
		}
	}

	/**
	 * Async handler: push updated stock for a simple product to Bosta.
	 *
	 * @param int $product_id
	 */
	public static function handle_async_product_stock_sync( $product_id ) {
		$product_id = intval( $product_id );
		if ( ! self::official_plugin_ready() ) {
			return;
		}

		$product  = wc_get_product( $product_id );
		$bosta_id = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );

		if ( ! $product || empty( $bosta_id ) ) {
			return;
		}

		delete_transient( 'bsi_debounce_product_' . $product_id );

		$result = self::update_single_product_on_bosta( $product, $bosta_id );

		if ( ! $result['success'] && class_exists( 'BSI_Utilities' ) ) {
			BSI_Utilities::log_error(
				'[BSI] Stock sync failed — Product #' . $product_id,
				[ 'bosta_id' => $bosta_id, 'error' => $result['message'] ]
			);
		}
	}

	/**
	 * Async handler: push updated variation stock to Bosta.
	 *
	 * @param int $parent_id
	 */
	public static function handle_async_variation_stock_sync( $parent_id ) {
		$parent_id = intval( $parent_id );
		if ( ! self::official_plugin_ready() ) {
			return;
		}

		$product  = wc_get_product( $parent_id );
		$bosta_id = get_post_meta( $parent_id, self::META_BOSTA_PRODUCT_ID, true );

		if ( ! $product || ! $product->is_type( 'variable' ) || empty( $bosta_id ) ) {
			return;
		}

		delete_transient( 'bsi_debounce_variation_parent_' . $parent_id );

		$result = self::update_single_product_on_bosta( $product, $bosta_id );

		if ( ! $result['success'] && class_exists( 'BSI_Utilities' ) ) {
			BSI_Utilities::log_error(
				'[BSI] Variation stock sync failed — Parent #' . $parent_id,
				[ 'bosta_id' => $bosta_id, 'error' => $result['message'] ]
			);
		}
	}

	// ==================================================================
	// SECTION 3 — HELPERS
	// ==================================================================

	/**
	 * Check if real-time auto-sync is enabled.
	 * Depends EXCLUSIVELY on official Bosta setting: "Enable Product Auto Sync" in woocommerce_bosta_settings.
	 *
	 * @return bool
	 */
	public static function is_auto_sync_enabled() {
		$bosta_settings = get_option( 'woocommerce_bosta_settings', [] );
		return ( ! empty( $bosta_settings['EnableProductAutoSync'] ) && $bosta_settings['EnableProductAutoSync'] === 'yes' );
	}

	/**
	 * Verify all official-plugin dependencies are available.
	 *
	 * @return bool
	 */
	private static function official_plugin_ready() {
		return (
			class_exists( 'Bosta_Product_Sync' ) &&
			function_exists( 'bosta_get_api_key' ) &&
			function_exists( 'bosta_send_api_request' ) &&
			defined( 'BOSTA_ENV_URL_V2' )
		);
	}

	/**
	 * Persist the Bosta variation-ID map to postmeta.
	 *
	 * @param int   $wc_product_id WooCommerce product ID.
	 * @param array $bosta_product Product data from the Bosta API response.
	 */
	public static function save_variation_id_map( $wc_product_id, $bosta_product ) {
		if ( empty( $bosta_product['productsVariances'] ) ) {
			return;
		}

		$map = [];
		foreach ( $bosta_product['productsVariances'] as $variance ) {
			if ( ! empty( $variance['optionsString'] ) && ! empty( $variance['id'] ) ) {
				$map[ trim( $variance['optionsString'] ) ] = $variance['id'];
			}
		}

		if ( ! empty( $map ) ) {
			update_post_meta( $wc_product_id, self::META_VARIATION_MAP, wp_json_encode( $map ) );
		}
	}

	/**
	 * Retrieve the cached variation-ID map for a product.
	 *
	 * @param int $wc_product_id WooCommerce product ID.
	 * @return array [ optionsString => bosta_variation_id ]
	 */
	public static function get_variation_id_map( $wc_product_id ) {
		$raw     = get_post_meta( $wc_product_id, self::META_VARIATION_MAP, true );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Format a WooCommerce product for Bosta API v2 with enhanced variation handling:
	 *  - If variation stock is unspecified or 0 while parent has stock, inherits parent stock.
	 *  - Guarantees non-empty variation name and non-empty options array.
	 *  - Cleans null values from optionsOrder.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	public static function format_product_for_bosta( $product ) {
		$is_variable = $product->is_type( 'variable' );
		$formatted   = Bosta_Product_Sync::format_product_for_bosta( $product );

		// Helper to sanitize 4-byte UTF-8 (emojis) which cause MySQL utf8mb3 collation errors in Bosta
		$default_img = ! empty( $formatted['defaultImage'] ) ? $formatted['defaultImage'] : '';
		if ( ! empty( $formatted['images'] ) && is_array( $formatted['images'] ) ) {
			$clean_images = [];
			foreach ( $formatted['images'] as $img ) {
				if ( ! preg_match( '/[\x{10000}-\x{10FFFF}]/u', $img ) ) {
					$clean_images[] = $img;
				}
			}
			if ( empty( $clean_images ) && ! empty( $default_img ) ) {
				$clean_images[] = $default_img;
			}
			$formatted['images'] = $clean_images;
		}

		if ( ! $is_variable ) {
			return $formatted;
		}

		// Determine parent product stock quantity
		$parent_qty = 0;
		if ( $product->managing_stock() ) {
			$parent_qty = intval( $product->get_stock_quantity() );
		} else {
			$parent_stock_meta = get_post_meta( $product->get_id(), '_stock', true );
			if ( is_numeric( $parent_stock_meta ) ) {
				$parent_qty = intval( $parent_stock_meta );
			}
		}

		$seen_signatures = [];
		$new_variances   = [];

		if ( ! empty( $formatted['productVariances'] ) && is_array( $formatted['productVariances'] ) ) {
			$idx = 1;
			foreach ( $formatted['productVariances'] as $variance ) {
				$variant_has_own_stock = ( isset( $variance['variantQuantity'] ) && is_numeric( $variance['variantQuantity'] ) && intval( $variance['variantQuantity'] ) > 0 );

				// Rule: If variation quantity is unspecified or 0 while parent has stock, inherit parent stock
				if ( ! $variant_has_own_stock && $parent_qty > 0 ) {
					$variance['variantQuantity'] = $parent_qty;
				} elseif ( ! isset( $variance['variantQuantity'] ) || $variance['variantQuantity'] === null ) {
					$variance['variantQuantity'] = $parent_qty;
				}

				// Clean options and decode attributes (remove pa_ prefix and URL encoding)
				if ( ! empty( $variance['options'] ) && is_array( $variance['options'] ) ) {
					foreach ( $variance['options'] as &$opt ) {
						$opt['optionName'] = urldecode( $opt['optionName'] );
						if ( strpos( $opt['optionName'], 'pa_' ) === 0 ) {
							$opt['optionName'] = substr( $opt['optionName'], 3 );
						}
						$opt['optionValue'] = urldecode( $opt['optionValue'] );
						if ( empty( $opt['optionName'] ) ) {
							$opt['optionName'] = 'النوع';
						}
						if ( empty( $opt['optionValue'] ) ) {
							$opt['optionValue'] = 'افتراضي';
						}
					}
					unset( $opt );
				} else {
					$variance['options'] = [
						[
							'optionName'  => 'النوع',
							'optionValue' => ! empty( $variance['name'] ) ? $variance['name'] : ( 'خيار ' . $idx ),
						],
					];
				}

				// Guarantee variance name is non-empty and decoded
				$variance['name'] = urldecode( ! empty( $variance['name'] ) ? trim( $variance['name'] ) : ( 'خيار ' . $idx ) );

				// Clean 4-byte UTF-8 from variant image
				if ( isset( $variance['variantDefaultImage'] ) && preg_match( '/[\x{10000}-\x{10FFFF}]/u', $variance['variantDefaultImage'] ) ) {
					$variance['variantDefaultImage'] = $default_img;
				}

				// Deduplication: prevent "contains a duplicate value" error in Bosta
				$sig_parts = [];
				foreach ( $variance['options'] as $opt ) {
					$sig_parts[] = $opt['optionName'] . ':' . $opt['optionValue'];
				}
				$sig = implode( '|', $sig_parts );

				if ( isset( $seen_signatures[ $sig ] ) ) {
					$seen_signatures[ $sig ]++;
					$dup_num = $seen_signatures[ $sig ];
					$last_idx = count( $variance['options'] ) - 1;
					$variance['options'][ $last_idx ]['optionValue'] .= ' (' . $dup_num . ')';
					$variance['name'] .= ' (' . $dup_num . ')';
				} else {
					$seen_signatures[ $sig ] = 1;
				}

				$new_variances[] = $variance;
				$idx++;
			}
			$formatted['productVariances'] = $new_variances;

			// Rebuild optionsOrder strictly from the actual variances' options
			$rebuilt_order = [];
			$o_idx = 1;
			foreach ( $formatted['productVariances'] as $v ) {
				foreach ( $v['options'] as $opt ) {
					$n   = $opt['optionName'];
					$val = $opt['optionValue'];
					if ( ! isset( $rebuilt_order[ $n ] ) ) {
						$rebuilt_order[ $n ] = [
							'order'  => $o_idx++,
							'values' => [],
						];
					}
					if ( ! in_array( $val, $rebuilt_order[ $n ]['values'], true ) ) {
						$rebuilt_order[ $n ]['values'][] = $val;
					}
				}
			}
			$formatted['optionsOrder'] = $rebuilt_order;
		}

		return $formatted;
	}

	/**
	 * Sync a single product to Bosta using our enhanced formatting.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	public static function sync_single_product_to_bosta( $product ) {
		$api_key = bosta_get_api_key();
		if ( empty( $api_key ) ) {
			return [ 'success' => false, 'message' => 'مفتاح API لبوسطة غير موجود.' ];
		}

		$formatted = self::format_product_for_bosta( $product );
		$url       = BOSTA_ENV_URL_V2 . '/products/bulk';
		$response  = bosta_send_api_request( 'POST', $url, $api_key, [ 'products' => [ $formatted ] ] );

		if ( empty( $response['success'] ) ) {
			return [
				'success' => false,
				'message' => isset( $response['error'] ) ? $response['error'] : 'فشل إرسال المنتج إلى بوسطة'
			];
		}

		if ( empty( $response['body']['success'] ) ) {
			return [
				'success' => false,
				'message' => isset( $response['body']['message'] ) ? $response['body']['message'] : 'فشل إنشاء المنتج في بوسطة'
			];
		}

		if ( isset( $response['body']['data']['createdProducts'] ) && is_array( $response['body']['data']['createdProducts'] ) ) {
			$created = reset( $response['body']['data']['createdProducts'] );
			if ( isset( $created['id'] ) ) {
				update_post_meta( $product->get_id(), self::META_BOSTA_PRODUCT_ID, $created['id'] );
				if ( ! empty( $created['productsVariances'] ) ) {
					self::save_variation_id_map( $product->get_id(), $created );
				}
			}
		}

		return [ 'success' => true, 'message' => 'تمت مزامنة المنتج بنجاح.' ];
	}

	/**
	 * Enhanced update of an existing product on Bosta.
	 * Supports simple and variable products, maps variance IDs,
	 * inherits parent stock for variations, deduplicates options, and cleans emojis.
	 *
	 * @param WC_Product $product
	 * @param int|string $bosta_product_id
	 * @return array ['success' => bool, 'message' => string]
	 */
	public static function update_single_product_on_bosta( $product, $bosta_product_id ) {
		$api_key = bosta_get_api_key();
		if ( empty( $api_key ) ) {
			return [ 'success' => false, 'message' => 'مفتاح API لبوسطة غير موجود.' ];
		}

		$formatted = self::format_product_for_bosta( $product );
		$url       = BOSTA_ENV_URL_V2 . '/products/' . $bosta_product_id;

		if ( $product->is_type( 'variable' ) ) {
			// Fetch Bosta product to get Bosta variance IDs
			$bosta_data      = Bosta_Product_Sync::fetch_product_from_bosta( $bosta_product_id );
			$bosta_variances = isset( $bosta_data['productsVariances'] ) && is_array( $bosta_data['productsVariances'] )
				? $bosta_data['productsVariances']
				: [];

			$var_id_by_index = [];
			foreach ( $bosta_variances as $idx => $bv ) {
				if ( isset( $bv['id'] ) ) {
					$var_id_by_index[ $idx ] = $bv['id'];
				}
			}

			$edited_variances = [];
			if ( ! empty( $formatted['productVariances'] ) && is_array( $formatted['productVariances'] ) ) {
				foreach ( $formatted['productVariances'] as $idx => $v ) {
					if ( isset( $var_id_by_index[ $idx ] ) ) {
						$v['id'] = $var_id_by_index[ $idx ];
					}
					$edited_variances[] = $v;
				}
			}

			$body = [
				'name'            => $formatted['name'],
				'description'     => isset( $formatted['description'] ) ? $formatted['description'] : '',
				'images'          => isset( $formatted['images'] ) ? $formatted['images'] : [],
				'defaultImage'    => isset( $formatted['defaultImage'] ) ? $formatted['defaultImage'] : '',
				'editedVariances' => $edited_variances,
				'optionsOrder'    => isset( $formatted['optionsOrder'] ) ? $formatted['optionsOrder'] : [],
				'referenceId'     => isset( $formatted['referenceId'] ) ? $formatted['referenceId'] : ( 'WC-' . $product->get_id() ),
			];
		} else {
			$body = $formatted;
		}

		$response = bosta_send_api_request( 'PUT', $url, $api_key, $body );

		if ( empty( $response['success'] ) ) {
			return [
				'success' => false,
				'message' => isset( $response['error'] ) ? $response['error'] : 'فشل تحديث المنتج في بوسطة',
			];
		}

		if ( empty( $response['body']['success'] ) ) {
			return [
				'success' => false,
				'message' => isset( $response['body']['message'] ) ? $response['body']['message'] : 'فشل تحديث المنتج في بوسطة',
			];
		}

		return [ 'success' => true, 'message' => 'تم تحديث المنتج في بوسطة بنجاح.' ];
	}

	/**
	 * Real-time hook: handle WooCommerce new product creation.
	 * Hooked to: woocommerce_new_product
	 *
	 * @param int $product_id
	 */
	public static function handle_product_create( $product_id ) {
		if ( ! self::is_auto_sync_enabled() ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_status() !== 'publish' ) {
			return;
		}

		$bosta_id = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );
		if ( empty( $bosta_id ) ) {
			self::sync_single_product_to_bosta( $product );
		}
	}

	/**
	 * Real-time hook: handle WooCommerce product update (price, stock, details).
	 * Hooked to: woocommerce_update_product
	 *
	 * @param int $product_id
	 */
	public static function handle_product_update( $product_id ) {
		if ( ! self::is_auto_sync_enabled() ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_status() !== 'publish' ) {
			return;
		}

		$bosta_id = get_post_meta( $product_id, self::META_BOSTA_PRODUCT_ID, true );
		if ( ! empty( $bosta_id ) ) {
			self::update_single_product_on_bosta( $product, $bosta_id );
		} else {
			self::sync_single_product_to_bosta( $product );
		}
	}
}

