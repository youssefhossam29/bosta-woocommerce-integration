<?php
/**
 * Uninstall Bosta WooCommerce Integration
 * Fired when the plugin is uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
$options = [
    'bosta_webhook_secret',
    'bosta_business_url',
    'bosta_auto_status_change',
    'bosta_enable_tracking_button',
    'bosta_default_payment_status',
    'bosta_customer_service_statuses',
    'bosta_warehouse_admin_statuses',
    'bosta_packaging_team_statuses',
    'bosta_wc_last_update',
];

foreach ($options as $option) {
    delete_option($option);
}
