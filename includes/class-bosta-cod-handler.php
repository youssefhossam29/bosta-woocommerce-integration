<?php
/**
 * Bosta COD Handler
 * Handles Cash on Delivery collection for both automatic (webhook) and manual scenarios
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_COD_Handler {

    public function __construct() {
        // Handle automatic COD collection from webhook
        add_action('bosta_handle_cod_collection', [$this, 'handle_automatic_collection'], 10, 2);
        
        // Handle manual COD collection using the correct hook
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 4);
    }

    /**
     * Handle automatic COD collection (from Bosta webhook)
     */
    public function handle_automatic_collection($order, $data) {
        if (!$order instanceof WC_Order) {
            return;
        }

        $collected_amount = !empty($data['codAmount']) ? floatval($data['codAmount']) : floatval($order->get_total());
        $collection_date = $this->get_current_datetime();

        // Update order status
        $order->update_status('cod_collected');

        // Add order note
        $order->add_order_note(sprintf(
            __('تم تحصيل مبلغ %s جنيه من العميل عن طريق بوسطة بتاريخ %s', 'bosta-woocommerce'),
            number_format($collected_amount, 2),
            date_i18n('Y-m-d H:i', strtotime($collection_date))
        ));

        // Save meta data
        $this->save_cod_metadata($order, $collected_amount, 'بوسطة', $collection_date);

        // Trigger action for extensions
        do_action('bosta_cod_collected', $order, $collected_amount, 'automatic');
    }

    /**
     * Handle order status change to detect COD collection
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order) {
        if ($new_status === 'cod_collected') {
            $this->handle_manual_collection($order_id, $order);
        }
    }

    /**
     * Handle manual COD collection (status changed manually)
     */
    public function handle_manual_collection($order_id, $order) {
        if (!$order instanceof WC_Order) {
            return;
        }

        // Ensure payment method is COD
        if ($order->get_payment_method() !== 'cod') {
            return;
        }

        // Skip if triggered automatically
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Confirm it's a manual action
        $current_user = wp_get_current_user();
        if (empty($current_user->ID)) {
            return;
        }

        // Check if already processed by webhook
        if ($order->get_meta('cod_collected_by') === 'بوسطة') {
            return;
        }

        $collected_amount = floatval($order->get_total());
        $collection_date = $this->get_current_datetime();

        // Add order note
        $order->add_order_note(sprintf(
            __('تم تحصيل مبلغ %s جنيه من العميل عن طريق مندوب خاص بتاريخ %s', 'bosta-woocommerce'),
            number_format($collected_amount, 2),
            date_i18n('Y-m-d H:i', strtotime($collection_date))
        ));

        // Save meta data
        $this->save_cod_metadata($order, $collected_amount, 'مندوب خاص', $collection_date);

        // Trigger action for extensions
        do_action('bosta_cod_collected', $order, $collected_amount, 'manual');
    }

    /**
     * Save COD metadata
     */
    private function save_cod_metadata($order, $amount, $collected_by, $date) {
        $order->update_meta_data('cod_amount', $amount);
        $order->update_meta_data('cod_collected_by', $collected_by);
        $order->update_meta_data('cod_collected_date', $date);
        $order->save();
    }

    /**
     * Get current datetime in Cairo timezone
     */
    private function get_current_datetime() {
        return wp_date('Y-m-d H:i', time(), new DateTimeZone('Africa/Cairo'));
    }

    /**
     * Get COD collection details for an order
     */
    public static function get_collection_details($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return null;
        }

        return [
            'amount' => $order->get_meta('cod_amount'),
            'collected_by' => $order->get_meta('cod_collected_by'),
            'date' => $order->get_meta('cod_collected_date'),
        ];
    }

    /**
     * Display COD info in admin order page
     */
    public static function display_cod_info($order) {
        $details = self::get_collection_details($order->get_id());
        
        if (!$details || empty($details['amount'])) {
            return;
        }

        ?>
        <div class="bosta-cod-info" style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
            <h4 style="margin: 0 0 10px 0; color: #0369a1;"><?php _e('تفاصيل التحصيل', 'bosta-woocommerce'); ?></h4>
            <p style="margin: 5px 0;"><strong><?php _e('المبلغ المحصل:', 'bosta-woocommerce'); ?></strong> <?php echo number_format($details['amount'], 2); ?> <?php _e('جنيه', 'bosta-woocommerce'); ?></p>
            <p style="margin: 5px 0;"><strong><?php _e('تم التحصيل عن طريق:', 'bosta-woocommerce'); ?></strong> <?php echo esc_html($details['collected_by']); ?></p>
            <p style="margin: 5px 0;"><strong><?php _e('تاريخ التحصيل:', 'bosta-woocommerce'); ?></strong> <?php echo esc_html($details['date']); ?></p>
        </div>
        <?php
    }
}

// Add COD info to order admin page
add_action('woocommerce_admin_order_data_after_order_details', function($order) {
    if ($order->get_status() === 'cod_collected') {
        BSI_COD_Handler::display_cod_info($order);
    }
});
