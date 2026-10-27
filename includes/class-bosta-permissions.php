<?php
/**
 * Bosta Permissions Manager
 * Handles role-based permissions for order status changes and bulk actions
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Permissions_Manager {

    public function __construct() {
        add_action('woocommerce_before_order_object_save', [$this, 'validate_permissions'], 10, 1);
    }

    /**
     * Validate user permissions before saving an order
     */
    public function validate_permissions($order) {
        // Bypass permission validation for REST API requests (webhooks, etc.)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        if (!is_user_logged_in() || !($order instanceof WC_Order)) {
            return;
        }

        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        
        // Admins and shop managers have full access
        if (array_intersect(['administrator', 'shop_manager'], $roles)) {
            return;
        }

        // Validate bulk action permissions
        $this->validate_bulk_action($roles);
        
        // Validate order status change permissions
        $this->validate_status_change($order, $roles);
    }

    /**
     * Validate order status change permissions
     */
    private function validate_status_change($order, $roles) {
        $old_status = $order->get_status();
        $new_status = isset($_POST['order_status'])
            ? str_replace('wc-', '', sanitize_text_field($_POST['order_status']))
            : $old_status;

        // Get allowed statuses for user's role
        $allowed_statuses = self::get_allowed_statuses($roles);

        // Prevent unauthorized status changes
        if (!empty($allowed_statuses) && !in_array($new_status, $allowed_statuses)) {
            $order->set_status($old_status);
            
            wp_die(
                sprintf(
                    __('You are not authorized to change the order status to "%s". Please contact an administrator.', 'bosta-woocommerce'),
                    $new_status
                ),
                __('Permission Denied', 'bosta-woocommerce'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Validate bulk action permissions
     */
    private function validate_bulk_action($roles) {
        $current_action = $_REQUEST['action'] ?? '';
        if ($current_action !== 'sync_to_bosta') {
            return;
        }

        $allowed_roles = ['warehouse_admin', 'shop_manager', 'administrator'];

        if (!array_intersect($allowed_roles, $roles)) {
            wp_die(
                __('You are not authorized to send orders to Bosta. Only Warehouse Admins and Shop Managers can perform this action.', 'bosta-woocommerce'),
                __('Permission Denied', 'bosta-woocommerce'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Get allowed statuses for user roles
     */
    public static function get_allowed_statuses($roles) {
        $allowed_statuses = [];

        if (in_array('customer_service', $roles)) {
            $allowed_statuses = get_option('bosta_customer_service_statuses', [
                'awaiting_confirm',
                'confirm_preparing',
                'delivered',
                'returned',
                'refunded',
                'pending',
                'processing',
                'cancelled',
                'cod_collected',
            ]);
        } elseif (in_array('warehouse_admin', $roles)) {
            $allowed_statuses = get_option('bosta_warehouse_admin_statuses', [
                'sent_to_bosta',
                'handed_to_agent',
            ]);
        } elseif (in_array('packaging_team', $roles)) {
            $allowed_statuses = get_option('bosta_packaging_team_statuses', [
                'ready_ship_agent',
                'ready_ship_bosta',
            ]);
        }

        return (array) $allowed_statuses;
    }
}
