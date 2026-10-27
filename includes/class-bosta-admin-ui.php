<?php
/**
 * Bosta Admin UI
 * Handles admin interface enhancements like tracking buttons and order meta display
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Admin_UI {

    public function __construct() {
        add_action('admin_footer', [$this, 'add_tracking_button']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_bosta_info']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);

        // Add columns for both HPOS and legacy systems
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_bosta_column'], 999);
        add_filter('manage_edit-shop_order_columns', [$this, 'add_bosta_column'], 999);

        // Render column content for HPOS
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_bosta_column'], 10, 2);

        // Render column content for legacy (CPT-based orders)
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_bosta_column_legacy'], 10, 2);

        // Make column visible by default
        add_filter('default_hidden_columns', [$this, 'show_bosta_column_by_default'], 10, 2);
    }

    /**
     * Add Bosta tracking button in admin order page
     */
    public function add_tracking_button() {
        if (get_option('bosta_enable_tracking_button', 'yes') !== 'yes') {
            return;
        }

        $screen = get_current_screen();

        // Check for both HPOS and legacy order edit pages
        $is_order_edit = false;
        if ($screen) {
            $is_order_edit = (
                strpos($screen->id, 'wc-orders') !== false ||
                $screen->id === 'shop_order' ||
                ($screen->post_type === 'shop_order' && $screen->base === 'post')
            );
        }

        if (!$is_order_edit) {
            return;
        }

        // Get order ID from different sources
        $order_id = 0;
        if (isset($_GET['id'])) {
            $order_id = absint($_GET['id']);
        } elseif (isset($_GET['post'])) {
            $order_id = absint($_GET['post']);
        }

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $bosta_url = BSI_Utilities::get_tracking_url($order_id);
        if (!$bosta_url) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            const updateButton = $('#woocommerce-order-actions .button.save_order');

            if (updateButton.length) {
                const bostaBtn = $('<a>', {
                    href: '<?php echo esc_url($bosta_url); ?>',
                    target: '_blank',
                    class: 'button button-secondary bosta-tracking-btn',
                    html: '<span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> عرض الطلب في بوسطة'
                }).css({
                    'margin-top': '10px',
                    'display': 'flex',
                    'align-items': 'center',
                    'justify-content': 'center',
                    'gap': '5px',
                    'width': '100%',
                    'text-align': 'center',
                    'background': '#0ea5e9',
                    'border-color': '#0284c7',
                    'color': '#fff'
                });

                bostaBtn.hover(
                    function() { $(this).css('background', '#0284c7'); },
                    function() { $(this).css('background', '#0ea5e9'); }
                );

                updateButton.after(bostaBtn);
            }
        });
        </script>
        <style>
            .bosta-tracking-btn:hover {
                color: #fff !important;
            }
        </style>
        <?php
    }

    /**
     * Display Bosta information in admin order page
     */
    public function display_bosta_info($order) {
        $tracking_number = $order->get_meta('bosta_tracking_number');
        if (empty($tracking_number)) {
            return;
        }

        $tracking_url = BSI_Utilities::get_tracking_url($order->get_id());
        $sync_status = $order->get_meta('bosta_sync_status');
        $sync_date = $order->get_meta('bosta_sync_date');
        ?>
        <div class="bosta-order-info" style="margin-top: 20px; padding: 15px; background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;">
            <h4 style="margin: 0 0 10px 0; color: #047857;">
                <span class="dashicons dashicons-location" style="font-size: 18px; vertical-align: middle;"></span>
                <?php _e('معلومات الشحن - بوسطة', 'bosta-woocommerce'); ?>
            </h4>
            
            <p style="margin: 5px 0;">
                <strong><?php _e('رقم التتبع:', 'bosta-woocommerce'); ?></strong> 
                <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" style="color: #0ea5e9; text-decoration: none;">
                    <?php echo esc_html($tracking_number); ?>
                    <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span>
                </a>
            </p>

            <?php if ($sync_status === 'success'): ?>
                <p style="margin: 5px 0;">
                    <strong><?php _e('حالة المزامنة:', 'bosta-woocommerce'); ?></strong> 
                    <span style="color: #10b981;">✓ <?php _e('تم المزامنة بنجاح', 'bosta-woocommerce'); ?></span>
                </p>
            <?php endif; ?>

            <?php if ($sync_date): ?>
                <p style="margin: 5px 0;">
                    <strong><?php _e('تاريخ الإرسال:', 'bosta-woocommerce'); ?></strong> 
                    <?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($sync_date))); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if (strpos($hook, 'wc-orders') === false && $hook !== 'edit.php') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')) {
            return;
        }

        wp_add_inline_style('woocommerce_admin_styles', '
            .bosta-order-info a:hover { text-decoration: underline; }

            /* Bosta tracking column - compact design */
            .widefat .column-bosta_tracking,
            .widefat .column-bosta_tracking_number {
                width: 40px !important;
                max-width: 40px !important;
                text-align: center !important;
                padding: 8px 4px !important;
            }

            /* Icon link styling */
            .bosta-tracking-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                color: #0ea5e9;
                text-decoration: none;
                border-radius: 4px;
                transition: all 0.2s ease;
            }

            .bosta-tracking-icon:hover {
                color: #fff;
                background: #0ea5e9;
                transform: scale(1.1);
            }

            .bosta-tracking-icon .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
        ');
    }

    /**
     * Make Bosta column visible by default
     */
    public function show_bosta_column_by_default($hidden, $screen) {
        if ($screen && ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'edit-shop_order')) {
            $hidden = array_diff($hidden, ['bosta_tracking', 'bosta_tracking_number']);
        }
        return $hidden;
    }

    /**
     * Add Bosta tracking column to orders list
     */
    public function add_bosta_column($columns) {
        // Check if bosta_tracking_number already exists
        if (isset($columns['bosta_tracking_number'])) {
            $columns['bosta_tracking_number'] = '<span class="dashicons dashicons-visibility" title="' . esc_attr__('تتبع بوسطة', 'bosta-woocommerce') . '"></span>';
            return $columns;
        }

        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'order_status' || $key === 'order_total') {
                $new_columns['bosta_tracking'] = '<span class="dashicons dashicons-visibility" title="' . esc_attr__('تتبع بوسطة', 'bosta-woocommerce') . '"></span>';
            }
        }

        if (!isset($new_columns['bosta_tracking'])) {
            $actions = [];
            if (isset($new_columns['wc_actions'])) {
                $actions['wc_actions'] = $new_columns['wc_actions'];
                unset($new_columns['wc_actions']);
            }
            $new_columns['bosta_tracking'] = '<span class="dashicons dashicons-visibility" title="' . esc_attr__('تتبع بوسطة', 'bosta-woocommerce') . '"></span>';
            if (!empty($actions)) {
                $new_columns = array_merge($new_columns, $actions);
            }
        }

        return $new_columns;
    }

    /**
     * Render Bosta tracking column content
     */
    public function render_bosta_column($column, $order) {
        if ($column !== 'bosta_tracking' && $column !== 'bosta_tracking_number') {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            echo '<span style="color: #cbd5e1;">—</span>';
            return;
        }

        $tracking_url = BSI_Utilities::get_tracking_url($order->get_id());
        $tracking_number = $order->get_meta('bosta_tracking_number');

        if (empty($tracking_url) || empty($tracking_number)) {
            echo '<span style="color: #cbd5e1;">—</span>';
            return;
        }

        printf(
            '<a href="%s" target="_blank" class="bosta-tracking-icon" title="%s: %s">
                <span class="dashicons dashicons-visibility"></span>
            </a>',
            esc_url($tracking_url),
            esc_attr__('عرض في بوسطة', 'bosta-woocommerce'),
            esc_attr($tracking_number)
        );
    }

    /**
     * Render Bosta tracking column content (legacy version)
     */
    public function render_bosta_column_legacy($column, $post_id) {
        $this->render_bosta_column($column, $post_id);
    }
}
