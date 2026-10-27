<?php
/**
 * Bosta Webhook Handler
 * Handles webhook requests from Bosta and updates WooCommerce order statuses
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Webhook_Handler {

    private $expected_secret;

    public function __construct() {
        $this->expected_secret = get_option('bosta_webhook_secret', '');
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }

    /**
     * Register REST API endpoint for Bosta webhook
     */
    public function register_webhook_endpoint() {
        // Main webhook endpoint (for orders created from Bosta dashboard)
        register_rest_route('bosta/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook_request'],
        ]);

        // Additional endpoint for orders created from website via bosta-woocommerce plugin
        register_rest_route('bosta/v1', '/webhook/orders/(?P<order_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook_request'],
        ]);
    }

    /**
     * Verify webhook request authenticity
     */
    public function verify_webhook_request($request) {
        // Allow empty secret for testing (remove in production)
        if (empty($this->expected_secret)) {
            return true;
        }

        $secret = $request->get_header('X-Bosta-Secret');

        if ($secret !== $this->expected_secret) {
            $this->log_error('Invalid webhook secret', [
                'received' => !empty($secret) ? substr($secret, 0, 8) . '...' : 'empty',
                'ip'       => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Main webhook handler
     */
    public function handle_webhook($request) {
        $body = $this->parse_request_body($request);

        if (is_wp_error($body)) {
            $this->log_error('Invalid payload: ' . $body->get_error_message(), [
                'raw_body' => substr($request->get_body(), 0, 500),
            ]);
            return new WP_REST_Response(['error' => $body->get_error_message()], 400);
        }

        $order = $this->find_order($body);

        if (!$order) {
            $this->log_error('Order not found', [
                'tracking_number'    => $body['trackingNumber'],
                'business_reference' => $body['businessReference'],
                'bosta_id'           => $body['id'],
            ]);
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }

        $processed = $this->process_status_update($order, $body);

        if (!$processed) {
            $this->log_error('Status update failed or unmapped state', [
                'order_id'        => $order->get_id(),
                'bosta_state'     => $body['state'],
                'tracking_number' => $body['trackingNumber'],
            ]);
            return new WP_REST_Response(['error' => 'Unmapped Bosta state: ' . $body['state']], 400);
        }

        return new WP_REST_Response(['success' => true, 'order_id' => $order->get_id()], 200);
    }

    /**
     * Parse and validate request body
     */
    private function parse_request_body($request) {
        $body = json_decode($request->get_body(), true);

        if (empty($body['_id']) || empty($body['trackingNumber']) || !isset($body['state'])) {
            return new WP_Error('invalid_payload', 'Missing required fields: _id, trackingNumber, or state');
        }

        return [
            'id' => sanitize_text_field($body['_id']),
            'trackingNumber' => sanitize_text_field($body['trackingNumber']),
            'businessReference' => isset($body['businessReference']) ? sanitize_text_field($body['businessReference']) : '',
            'state' => intval($body['state']),
            'codAmount' => isset($body['cod']) ? floatval($body['cod']) : 0,
        ];
    }

    /**
     * Find order using payload data.
     * Tries tracking number first, then falls back to businessReference (order id or meta).
     */
    private function find_order($body) {
        $tracking_number = $body['trackingNumber'] ?? '';

        if (!empty($tracking_number)) {
            $orders = wc_get_orders([
                'meta_key' => 'bosta_tracking_number',
                'meta_value' => $tracking_number,
                'limit' => 1,
            ]);

            if (!empty($orders)) {
                return $orders[0];
            }
        }

        // Try businessReference as direct order ID
        $business_ref = $body['businessReference'] ?? '';
        if (!empty($business_ref) && ctype_digit((string) $business_ref)) {
            $order = wc_get_order(intval($business_ref));
            if ($order) {
                return $order;
            }
        }

        // Try businessReference stored as order meta (common integration pattern)
        if (!empty($business_ref)) {
            $orders = wc_get_orders([
                'meta_key' => 'business_reference',
                'meta_value' => $business_ref,
                'limit' => 1,
            ]);

            if (!empty($orders)) {
                return $orders[0];
            }
        }

        return null;
    }

    /**
     * Process status update based on Bosta state
     *
     * @return bool True if processed (COD or mapped), false if not processed (e.g., unmapped state)
     */
    private function process_status_update($order, $data) {
        $bosta_state = $data['state'];
        $payment_method = $order->get_payment_method();

        // Handle COD collection
        if ($this->is_cod_collection($bosta_state, $payment_method, $data['codAmount'])) {
            do_action('bosta_handle_cod_collection', $order, $data);
            return true;
        }

        // Map Bosta state to WooCommerce status
        $new_status = $this->map_bosta_state_to_woocommerce($bosta_state);

        if ($new_status) {
            return $this->update_order_status($order, $new_status, $bosta_state);
        }

        // Unmapped state
        return false;
    }

    /**
     * Check if this is a COD collection scenario
     */
    private function is_cod_collection($state, $payment_method, $cod_amount) {
        return $state === 45 && $payment_method === 'cod' && $cod_amount > 0;
    }

    /**
     * Map Bosta state to WooCommerce status
     */
    private function map_bosta_state_to_woocommerce($bosta_state) {
        $status_map = apply_filters('bosta_state_mapping', [
            10 => 'sent_to_bosta',        // Order Created
            21 => 'handed_to_bosta',       // Picked up
            23 => 'handed_to_bosta',       // Received at warehouse
            24 => 'handed_to_bosta',       // Transferred
            30 => 'handed_to_bosta',       // Out for delivery
            45 => 'delivered',             // Delivered
            46 => 'returned',              // Returned
            60 => 'returned',              // Cancelled
        ]);

        return $status_map[$bosta_state] ?? null;
    }

    /**
     * Update order status
     * 
     * @return bool True if status was updated, false otherwise
     */
    private function update_order_status($order, $new_status, $bosta_state) {
        $current_status = $order->get_status();

        // Normalize statuses for comparison (remove 'wc-' prefix if present)
        $normalized_current = str_replace('wc-', '', $current_status);
        $normalized_new = str_replace('wc-', '', $new_status);

        // If status is already the same, no need to update
        if ($normalized_new === $normalized_current) {
            return true; // Consider this a success (no change needed)
        }

        $note = sprintf(
            __('Order status updated automatically by Bosta webhook (State: %d)', 'bosta-woocommerce'),
            $bosta_state
        );

        try {
            $result = $order->update_status($normalized_new, $note);
            if ($result === false) {
                $this->log_error(sprintf('Failed to update order #%d status to "%s"', $order->get_id(), $normalized_new));
                return false;
            }
            return true;
        } catch (Exception $e) {
            $this->log_error(sprintf('Exception updating order #%d: %s', $order->get_id(), $e->getMessage()));
            return false;
        }
    }

    /**
     * Log error message to a single dedicated error log file
     *
     * @param string $message
     * @param array  $context
     */
    private function log_error($message, $context = []) {
        BSI_Utilities::log_error($message, $context);
    }
}
