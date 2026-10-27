<?php
/**
 * Bosta Order Status Manager
 * Handles automatic status changes after payment and Bosta sync
 */

if (!defined('ABSPATH')) {
	exit;
}

class BSI_Order_Status_Manager {

	private static $is_updating = false;

	public function __construct() {
		// Handle status after saving order (align with external script behavior)
		add_action('woocommerce_after_order_object_save', [$this, 'check_bosta_sync'], 10, 2);
	}

	/**
	 * Check and update status after Bosta sync
	 */
	public function check_bosta_sync($order, $data_store) {
		// Prevent recursive updates
		if (self::$is_updating) {
			return;
		}
		
		if (!$order instanceof WC_Order) {
			return;
		}

		$current_action = $_REQUEST['action'] ?? '';

		// Execute only when syncing to Bosta
		if ($current_action !== 'sync_to_bosta') {
			return;
		}

		// Check if auto status change is enabled
		if (get_option('bosta_auto_status_change', 'yes') !== 'yes') {
			return;
		}

		$current_status = $order->get_status();
		$sync_status = $order->get_meta('bosta_sync_status');
		$tracking_number = $order->get_meta('bosta_tracking_number');

		if ($sync_status === 'success' && !empty($tracking_number) && $current_status !== 'sent_to_bosta') {
			self::$is_updating = true;
			try {
				$order->update_status(
					'sent_to_bosta',
					__('Order successfully synced with Bosta', 'bosta-woocommerce'),
					true
				);
			} finally {
				self::$is_updating = false;
			}
		}
	}
}
