<?php
/**
 * Bosta Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Settings {

    public function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_bosta', [$this, 'settings_tab_content']);
        add_action('woocommerce_update_options_bosta', [$this, 'update_settings']);
    }

    public function add_settings_tab($settings_tabs) {
        $settings_tabs['bosta'] = __('Bosta status Integration', 'bosta-woocommerce');
        return $settings_tabs;
    }

    public function settings_tab_content() {
        woocommerce_admin_fields($this->get_settings());
    }

    public function update_settings() {
        woocommerce_update_options($this->get_settings());
    }

    public function get_settings() {
        $webhook_url = rest_url('bosta/v1/webhook');
        
        return [
            [
                'title' => __('Bosta status Integration Settings', 'bosta-woocommerce'),
                'type' => 'title',
                'desc' => __('Configure your Bosta shipping integration', 'bosta-woocommerce'),
                'id' => 'bosta_integration_settings'
            ],
            [
                'title' => __('Webhook Secret', 'bosta-woocommerce'),
                'type' => 'text',
                'desc' => __('Secret key for webhook authentication. Copy this to Bosta dashboard.', 'bosta-woocommerce'),
                'id' => 'bosta_webhook_secret',
                'default' => wp_generate_password(32, false),
                'css' => 'min-width:300px;'
            ],
            [
                'title' => __('Webhook URL', 'bosta-woocommerce'),
                'type' => 'text',
                'desc' => sprintf(
                    __('Copy this URL to Bosta dashboard: <code style="display:block;margin-top:10px;padding:10px;background:#f5f5f5;border:1px solid #ddd;">%s</code>', 'bosta-woocommerce'),
                    $webhook_url
                ),
                'id' => 'bosta_webhook_url_display',
                'default' => $webhook_url,
                'custom_attributes' => ['readonly' => 'readonly'],
                'css' => 'min-width:500px;'
            ],
            [
                'title' => __('Business URL', 'bosta-woocommerce'),
                'type' => 'text',
                'desc' => __('Bosta business dashboard URL', 'bosta-woocommerce'),
                'id' => 'bosta_business_url',
                'default' => 'https://business.bosta.co',
                'css' => 'min-width:300px;'
            ],
            [
                'title' => __('Auto Status Change', 'bosta-woocommerce'),
                'type' => 'checkbox',
                'desc' => __('Automatically update order status after syncing with Bosta', 'bosta-woocommerce'),
                'id' => 'bosta_auto_status_change',
                'default' => 'yes'
            ],
            [
                'title' => __('Enable Tracking Button', 'bosta-woocommerce'),
                'type' => 'checkbox',
                'desc' => __('Show Bosta tracking button on admin order page', 'bosta-woocommerce'),
                'id' => 'bosta_enable_tracking_button',
                'default' => 'yes'
            ],
            [
                'title' => __('Default Status After Payment', 'bosta-woocommerce'),
                'type' => 'select',
                'desc' => __('Order status after successful online payment', 'bosta-woocommerce'),
                'id' => 'bosta_default_payment_status',
                'options' => [
                    'processing' => __('Processing', 'woocommerce'),
                    'wc-confirm_preparing' => __('Confirm Preparing', 'bosta-woocommerce'),
                ],
                'default' => 'wc-confirm_preparing'
            ],
            [
                'type' => 'sectionend',
                'id' => 'bosta_integration_settings'
            ],
            
            // Role Permissions Section
            [
                'title' => __('Role-Based Permissions', 'bosta-woocommerce'),
                'type' => 'title',
                'desc' => __('Configure which order statuses each role can access', 'bosta-woocommerce'),
                'id' => 'bosta_permissions_settings'
            ],
            [
                'title' => __('Customer Service Statuses', 'bosta-woocommerce'),
                'type' => 'multiselect',
                'desc' => __('Order statuses that Customer Service can modify', 'bosta-woocommerce'),
                'id' => 'bosta_customer_service_statuses',
                'options' => $this->get_all_order_statuses(),
                'default' => ['awaiting_confirm', 'confirm_preparing', 'delivered', 'returned', 'pending', 'processing', 'cancelled'],
                'class' => 'wc-enhanced-select'
            ],
            [
                'title' => __('Warehouse Admin Statuses', 'bosta-woocommerce'),
                'type' => 'multiselect',
                'desc' => __('Order statuses that Warehouse Admin can modify', 'bosta-woocommerce'),
                'id' => 'bosta_warehouse_admin_statuses',
                'options' => $this->get_all_order_statuses(),
                'default' => ['ready_ship_agent', 'ready_ship_bosta'],
                'class' => 'wc-enhanced-select'
            ],
            [
                'title' => __('Packaging Team Statuses', 'bosta-woocommerce'),
                'type' => 'multiselect',
                'desc' => __('Order statuses that Packaging Team can modify', 'bosta-woocommerce'),
                'id' => 'bosta_packaging_team_statuses',
                'options' => $this->get_all_order_statuses(),
                'default' => ['sent_to_bosta', 'handed_to_agent'],
                'class' => 'wc-enhanced-select'
            ],
            [
                'type' => 'sectionend',
                'id' => 'bosta_permissions_settings'
            ],
        ];
    }

    private function get_all_order_statuses() {
        $statuses = wc_get_order_statuses();
        $clean_statuses = [];
        
        foreach ($statuses as $key => $label) {
            $clean_key = str_replace('wc-', '', $key);
            $clean_statuses[$clean_key] = $label;
        }
        
        return $clean_statuses;
    }
}
