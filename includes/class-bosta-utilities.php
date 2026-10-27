<?php
/**
 * Bosta Utilities
 * Helper functions and utilities for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Utilities {

    /**
     * Get Bosta tracking URL for order
     */
    public static function get_tracking_url($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return null;
        }

        $tracking_number = $order->get_meta('bosta_tracking_number');

        if (empty($tracking_number)) {
            return null;
        }

        $business_url = get_option('bosta_business_url', 'https://business.bosta.co');
        return trailingslashit($business_url) . 'orders/' . esc_attr($tracking_number);
    }

    /**
     * Calculate total COD collected amount without hydrating every order.
     */
    public static function get_total_cod_collected($date_from = null, $date_to = null) {
        global $wpdb;

        $params = ['wc-cod_collected', 'cod_amount'];
        $date_sql = '';

        if (
            class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            $date_column = 'orders.date_created_gmt';

            if ($date_from) {
                $date_sql .= " AND {$date_column} >= %s";
                $params[] = get_gmt_from_date($date_from);
            }

            if ($date_to) {
                $date_sql .= " AND {$date_column} <= %s";
                $params[] = get_gmt_from_date($date_to);
            }

            $sql = "
            SELECT COALESCE(SUM(CAST(NULLIF(amount.meta_value, '') AS DECIMAL(20, 6))), 0)
            FROM {$orders_table} AS orders
            INNER JOIN {$meta_table} AS amount
            ON amount.order_id = orders.id
            AND amount.meta_key = %s
            WHERE orders.status = %s
            AND orders.type = 'shop_order'
            {$date_sql}
            ";
        } else {
            $date_column = 'orders.post_date';

            if ($date_from) {
                $date_sql .= " AND {$date_column} >= %s";
                $params[] = $date_from;
            }

            if ($date_to) {
                $date_sql .= " AND {$date_column} <= %s";
                $params[] = $date_to;
            }

            $sql = "
            SELECT COALESCE(SUM(CAST(NULLIF(amount.meta_value, '') AS DECIMAL(20, 6))), 0)
            FROM {$wpdb->posts} AS orders
            INNER JOIN {$wpdb->postmeta} AS amount
            ON amount.post_id = orders.ID
            AND amount.meta_key = %s
            WHERE orders.post_status = %s
            AND orders.post_type = 'shop_order'
            {$date_sql}
            ";
        }

        $query_params = [$params[1], $params[0]];
        if ($date_from) {
            $query_params[] = $params[2];
        }
        if ($date_to) {
            $query_params[] = $params[$date_from ? 3 : 2];
        }

        return (float) $wpdb->get_var($wpdb->prepare($sql, $query_params));
    }

    /**
     * Log error to a single dedicated log file (not WordPress default, not daily rotated)
     *
     * @param string $message
     * @param array  $context
     */
    public static function log_error($message, $context = []) {
        $log_dir = WP_CONTENT_DIR . '/bosta-logs';

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            $htaccess_file = $log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                @file_put_contents($htaccess_file, "deny from all\n");
            }
            $index_file = $log_dir . '/index.php';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        }

        $log_file = $log_dir . '/errors.log';
        $timestamp = current_time('mysql');
        $entry = sprintf("[%s] [ERROR] %s", $timestamp, $message);

        if (!empty($context)) {
            $entry .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $entry .= "\n";

        @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Count an order query without loading full WC_Order objects or their metadata.
     */
    private static function count_orders($args) {
        $result = wc_get_orders(wp_parse_args($args, [
            'limit' => 1,
            'page' => 1,
            'paginate' => true,
            'return' => 'ids',
        ]));

        if (is_object($result) && isset($result->total)) {
            return (int) $result->total;
        }

        return is_array($result) ? count($result) : 0;
    }

    /**
     * Get lightweight dashboard statistics.
     */
    public static function get_statistics($date_from = null, $date_to = null) {
        $cache_key = 'bsi_dashboard_stats_' . md5((string) $date_from . '|' . (string) $date_to);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $date_args = [];

        if ($date_from) {
            $date_args['date_created'] = '>=' . $date_from;
        }

        if ($date_to) {
            $date_args['date_created'] = '<=' . $date_to;
        }

        $stats = [
            'total_synced' => self::count_orders(array_merge($date_args, [
                'meta_key' => 'bosta_sync_status',
                'meta_value' => 'success',
            ])),
            'pending_sync' => self::count_orders([
                'status' => ['ready_ship_bosta'],
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => 'bosta_tracking_number',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => 'bosta_sync_status',
                        'value' => 'success',
                        'compare' => '!=',
                    ],
                ],
            ]),
            'cod_collected' => self::count_orders(array_merge($date_args, [
                'status' => 'cod_collected',
            ])),
            'total_cod_amount' => self::get_total_cod_collected($date_from, $date_to),
        ];

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }
}
