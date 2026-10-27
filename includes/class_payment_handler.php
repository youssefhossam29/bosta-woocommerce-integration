<?php
/**
 * Bosta Payment Handler
 * Handles automatic order status updates for both online and COD payments.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Payment_Handler {

    public function __construct() {
        add_action('woocommerce_payment_complete', [$this, 'handle_order_after_payment']);
        add_action('woocommerce_thankyou', [$this, 'handle_order_after_payment'], 20, 1);
    }

    /**
     * Handle order status updates based on payment method.
     *
     * @param int $order_id
     */
    public function handle_order_after_payment($order_id) {
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $payment_method = strtolower($order->get_payment_method());
        $current_status = $order->get_status();

        // 1. If payment method is COD → change "processing" to "awaiting_confirm"
        if ($payment_method === 'cod') {
            if ($current_status === 'processing') {
                $order->update_status('awaiting_confirm', __('Order awaiting confirmation (COD)', 'bosta-woocommerce'));
            }
            return; // Exit to prevent running online logic
        }

        // 2. Handle Online Payment Orders
        $has_shipping_product = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && !$product->is_downloadable() && !$product->is_virtual()) {
                $has_shipping_product = true;
                break;
            }
        }

        // If order includes shippable products → set default configured status
        if ($has_shipping_product) {
            $default_status = get_option('bosta_default_payment_status', 'confirm_preparing');
            $default_status = str_replace('wc-', '', $default_status);
            $order->update_status($default_status, __('Preparing for shipment (auto)', 'bosta-woocommerce'));
        }
        // If all products are downloadable or virtual → complete the order automatically
        else {
            $order->update_status('completed', __('Order completed automatically (downloadable or virtual)', 'bosta-woocommerce'));
        }
    }
}
