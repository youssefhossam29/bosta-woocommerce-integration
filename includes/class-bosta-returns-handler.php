<?php
/**
 * Bosta Returns Handler
 *
 * Handles return deliveries for WooCommerce orders through Bosta
 *
 * @package BSI_WooCommerce_Integration
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Returns_Handler {

    /**
     * Initialize the returns handler
     */
    public static function init() {
        // Add meta box to order edit page
        add_action('add_meta_boxes', [__CLASS__, 'add_returns_meta_box']);

        // Handle return creation AJAX request
        add_action('wp_ajax_bsi_create_return_delivery', [__CLASS__, 'ajax_create_return_delivery']);

        // Add bulk action for creating returns
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'add_bulk_return_action'], 20);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'add_bulk_return_action'], 20);

        // Handle bulk action
        add_filter('handle_bulk_actions-edit-shop_order', [__CLASS__, 'handle_bulk_return_action'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'handle_bulk_return_action'], 10, 3);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Add meta box to order edit page
     */
    public static function add_returns_meta_box() {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'bsi_returns_metabox',
            __('إنشاء طلب مرتجعات بوسطة', 'bosta-woocommerce'),
            [__CLASS__, 'render_returns_meta_box'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render the returns meta box
     */
    public static function render_returns_meta_box($post_or_order_object) {
        $order = $post_or_order_object instanceof WP_Post
            ? wc_get_order($post_or_order_object->ID)
            : $post_or_order_object;

        if (!$order) {
            return;
        }

        $order_id = $order->get_id();
        $return_tracking_number = $order->get_meta('bosta_return_tracking_number', true);
        $return_delivery_id = $order->get_meta('bosta_return_delivery_id', true);

        ?>
        <div class="bsi-returns-container">
            <?php if (!empty($return_delivery_id)): ?>
                <div class="bsi-return-info">
                    <?php if (!empty($return_tracking_number)): ?>
                        <p><strong>رقم تتبع المرتجعات:</strong></p>
                        <p><code><?php echo esc_html($return_tracking_number); ?></code></p>
                    <?php endif; ?>

                    <p><strong>معرف التوصيل:</strong></p>
                    <p><code><?php echo esc_html($return_delivery_id); ?></code></p>

                    <?php if (empty($return_tracking_number)): ?>
                        <p style="color: #999; font-size: 12px;">
                            <em>يمكنك الحصول على رقم التتبع من <a href="https://app.bosta.co" target="_blank">داشبورد بوسطة</a></em>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bsi-return-form">
                    <p>
                        <label for="bsi_return_items_count">عدد العناصر المرتجعة:</label>
                        <input type="number"
                               id="bsi_return_items_count"
                               name="bsi_return_items_count"
                               min="1"
                               value="<?php echo esc_attr($order->get_item_count()); ?>"
                               class="widefat" />
                    </p>

                    <p>
                        <label for="bsi_return_description">وصف المرتجعات:</label>
                        <textarea id="bsi_return_description"
                                  name="bsi_return_description"
                                  class="widefat"
                                  rows="3"
                                  placeholder="أدخل سبب الإرجاع..."><?php
                            // Default description
                            echo esc_textarea('طلب إرجاع للطلب رقم #' . $order_id);
                        ?></textarea>
                    </p>

                    <p>
                        <label for="bsi_return_pickup_address">عنوان الاستلام:</label>
                        <textarea id="bsi_return_pickup_address"
                                  name="bsi_return_pickup_address"
                                  class="widefat"
                                  rows="2"
                                  placeholder="العنوان الذي سيتم استلام المرتجعات منه..."><?php
                            // Use customer address as default
                            $address = $order->get_billing_address_1() ?: $order->get_shipping_address_1();
                            echo esc_textarea($address);
                        ?></textarea>
                    </p>

                    <p>
                        <button type="button"
                                id="bsi_create_return_btn"
                                class="button button-primary widefat"
                                data-order-id="<?php echo esc_attr($order_id); ?>">
                            إنشاء طلب مرتجعات
                        </button>
                    </p>

                    <div id="bsi_return_message" style="display:none; margin-top: 10px;"></div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .bsi-returns-container {
                padding: 10px 0;
            }
            .bsi-return-info code {
                background: #f0f0f1;
                padding: 5px 10px;
                display: block;
                margin: 5px 0;
                border-radius: 3px;
            }
            #bsi_return_message.success {
                color: #00a32a;
                border: 1px solid #00a32a;
                background: #f0f6f0;
                padding: 8px;
                border-radius: 3px;
            }
            #bsi_return_message.error {
                color: #d63638;
                border: 1px solid #d63638;
                background: #fcf0f1;
                padding: 8px;
                border-radius: 3px;
            }
        </style>
        <?php
    }

    /**
     * Enqueue scripts
     */
    public static function enqueue_scripts($hook) {
        // Only load on order edit pages
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }

        global $post;
        $screen = get_current_screen();

        // Check if we're on an order edit page
        $is_order_edit = false;
        if ($post && $post->post_type === 'shop_order') {
            $is_order_edit = true;
        } elseif ($screen && $screen->id === wc_get_page_screen_id('shop-order')) {
            $is_order_edit = true;
        }

        if (!$is_order_edit) {
            return;
        }

        wp_enqueue_script(
            'bsi-returns-handler',
            BOSTA_WC_PLUGIN_URL . 'assets/js/returns-handler.js',
            ['jquery'],
            BOSTA_WC_VERSION,
            true
        );

        wp_localize_script('bsi-returns-handler', 'bsiReturns', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bsi_create_return'),
            'creating_text' => __('جاري الإنشاء...', 'bosta-woocommerce'),
            'create_text' => __('إنشاء طلب مرتجعات', 'bosta-woocommerce'),
        ]);
    }

    /**
     * Handle AJAX request to create return delivery
     */
    public static function ajax_create_return_delivery() {
        check_ajax_referer('bsi_create_return', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'غير مصرح لك بتنفيذ هذا الإجراء']);
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $items_count = isset($_POST['items_count']) ? intval($_POST['items_count']) : 1;
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $pickup_address = isset($_POST['pickup_address']) ? sanitize_textarea_field($_POST['pickup_address']) : '';

        if (!$order_id) {
            wp_send_json_error(['message' => 'معرف الطلب مطلوب']);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'الطلب غير موجود']);
        }

        // Create return delivery
        $result = self::create_return_delivery($order, $items_count, $description, $pickup_address);

        if ($result['success']) {
            wp_send_json_success([
                'message' => 'تم إنشاء طلب المرتجعات بنجاح',
                'tracking_number' => $result['tracking_number'],
                'delivery_id' => $result['delivery_id']
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }

    /**
     * Create return delivery via Bosta API
     *
     * @param WC_Order $order The order object
     * @param int $items_count Number of items being returned
     * @param string $description Description of the return
     * @param string $pickup_address Address for pickup
     * @return array Result with success status and data
     */
    public static function create_return_delivery($order, $items_count, $description, $pickup_address) {
        // Get API key from bosta-woocommerce plugin settings
        $bosta_settings = get_option('woocommerce_bosta_settings');
        $api_key = isset($bosta_settings['APIKey']) ? $bosta_settings['APIKey'] : '';

        if (empty($api_key)) {
            return [
                'success' => false,
                'error' => 'API Key غير موجود. يرجى تكوين إعدادات بوسطة أولاً.'
            ];
        }

        // Check if return already exists
        $existing_return = $order->get_meta('bosta_return_tracking_number', true);
        if (!empty($existing_return)) {
            return [
                'success' => false,
                'error' => 'تم إنشاء طلب مرتجعات لهذا الطلب مسبقاً'
            ];
        }

        // Note: With type 25 (Customer Return Pickup), we don't need to reference the original delivery
        // The return is created as a standalone pickup request

        // Prepare return delivery payload
        $return_payload = self::format_return_payload($order, $items_count, $description, $pickup_address);

        // Validate required fields
        $validation_errors = [];

        if (empty($return_payload->sender->phone)) {
            $validation_errors[] = 'رقم هاتف المتجر مطلوب';
        }

        if (empty($return_payload->receiver->phone)) {
            $validation_errors[] = 'رقم هاتف العميل مطلوب';
        }

        if (empty($return_payload->pickupAddress->firstLine)) {
            $validation_errors[] = 'عنوان الاستلام (عنوان العميل) مطلوب';
        }

        if (empty($return_payload->pickupAddress->city)) {
            $validation_errors[] = 'مدينة الاستلام مطلوبة';
        }

        if (empty($return_payload->pickupAddress->zone)) {
            $validation_errors[] = 'منطقة الاستلام مطلوبة';
        }

        if (empty($return_payload->dropOffAddress->firstLine)) {
            $validation_errors[] = 'عنوان التسليم (عنوان المتجر) مطلوب';
        }

        if (!empty($validation_errors)) {
            return [
                'success' => false,
                'error' => 'بيانات ناقصة: ' . implode(', ', $validation_errors) . '. يرجى التأكد من إضافة جميع البيانات المطلوبة في الطلب.'
            ];
        }

        // Send API request
        if (function_exists('bosta_send_api_request')) {
            $url = BOSTA_ENV_URL_V2 . '/deliveries/bulk';
            $body = [
                'deliveries' => [$return_payload],
                'deleteFailedDeliveries' => false
            ];

            $response = bosta_send_api_request('POST', $url, $api_key, $body);

            if (!$response['success']) {
                $error_msg = $response['error'] ?? $response['message'] ?? 'حدث خطأ أثناء إنشاء طلب المرتجعات';
                return [
                    'success' => false,
                    'error' => 'خطأ من API: ' . $error_msg
                ];
            }

            $data = $response['body']['data'] ?? [];

            // Handle case where data is an empty array or has no expected structure
            if (empty($data) || (is_array($data) && !isset($data['createdDeliveriesIds']) && !isset($data['failedDeliveries']) && count($data) === 0)) {
                return [
                    'success' => false,
                    'error' => 'بوسطة قبلت الطلب لكن لم ترجع معرف التوصيل. السبب المحتمل: حسابك على بوسطة غير مفعّل لخدمة المرتجعات (Customer Return Pickup - Type 25). يرجى التواصل مع دعم بوسطة لتفعيل هذه الخدمة على حسابك.'
                ];
            }

            $created_deliveries = $data['createdDeliveriesIds'] ?? [];
            $failed_deliveries = $data['failedDeliveries'] ?? [];

            if (!empty($failed_deliveries)) {
                $error_message = $failed_deliveries[0]['errorMessage'] ?? 'فشل إنشاء طلب المرتجعات';

                // Add detailed error info if available
                if (isset($failed_deliveries[0]['errors'])) {
                    $error_message .= ' - التفاصيل: ' . json_encode($failed_deliveries[0]['errors'], JSON_UNESCAPED_UNICODE);
                }

                return [
                    'success' => false,
                    'error' => $error_message
                ];
            }

            if (!empty($created_deliveries)) {
                $delivery_id = $created_deliveries[0];

                // Save delivery ID immediately
                $order->update_meta_data('bosta_return_delivery_id', $delivery_id);
                $order->update_meta_data('bosta_return_created_at', current_time('mysql'));

                // Try to get delivery details to get tracking number
                $delivery_data = self::get_delivery_data($delivery_id, $api_key);
                $tracking_number = null;

                if ($delivery_data && isset($delivery_data['trackingNumber'])) {
                    $tracking_number = $delivery_data['trackingNumber'];
                    $order->update_meta_data('bosta_return_tracking_number', $tracking_number);
                    $order->add_order_note(sprintf(
                        'تم إنشاء طلب مرتجعات بوسطة. رقم التتبع: %s',
                        $tracking_number
                    ));
                } else {
                    // Tracking number not available yet, but delivery was created
                    $order->add_order_note(sprintf(
                        'تم إنشاء طلب مرتجعات بوسطة. معرف التوصيل: %s (يمكنك الحصول على رقم التتبع من داشبورد بوسطة)',
                        $delivery_id
                    ));
                }

                $order->save();

                return [
                    'success' => true,
                    'delivery_id' => $delivery_id,
                    'tracking_number' => $tracking_number ?: 'يمكن الحصول عليه من داشبورد بوسطة'
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'لم يتم إرجاع معرف التوصيل من API. يرجى التحقق من إعدادات API أو المحاولة مرة أخرى.'
        ];
    }

    /**
     * Format return delivery payload for Bosta API
     *
     * @param WC_Order $order The order object
     * @param int $items_count Number of items being returned
     * @param string $description Description of the return
     * @param string $pickup_address Address for pickup
     * @return object Formatted payload
     */
    private static function format_return_payload($order, $items_count, $description, $pickup_address) {
        $payload = new stdClass();

        // Set delivery type for Customer Return Pickup
        // Type codes: 10=Deliver, 15=Cash Collection, 25=Customer Return Pickup, 30=Exchange
        // Reference: https://docs.bosta.co/docs/how-to/create-your-first-delivery#order-types-codes
        $payload->type = 25;

        // Business reference
        $payload->businessReference = 'Return_WC_' . $order->get_id();
        $payload->uniqueBusinessReference = 'Return_WC_' . $order->get_id() . '_' . time();

        // Notes and description
        $payload->notes = $description;

        // Package details for return
        $payload->specs = new stdClass();
        $payload->specs->packageDetails = new stdClass();
        $payload->specs->packageDetails->itemsCount = $items_count;
        $payload->specs->packageDetails->description = $description;

        // Note: productInfo is NOT needed for Customer Return Pickup (type 25)
        // productInfo with productType='return' is only used for Exchange orders (type 30)

        // Receiver details (will receive the returned items - use business address)
        $bosta_settings = get_option('woocommerce_bosta_settings');
        $business_name = isset($bosta_settings['BusinessName']) ? $bosta_settings['BusinessName'] : get_bloginfo('name');

        // Get receiver phone with fallbacks
        $receiver_phone = '';
        if (isset($bosta_settings['BusinessPhone']) && !empty($bosta_settings['BusinessPhone'])) {
            $receiver_phone = $bosta_settings['BusinessPhone'];
        } elseif (isset($bosta_settings['Phone']) && !empty($bosta_settings['Phone'])) {
            // Try alternative field name
            $receiver_phone = $bosta_settings['Phone'];
        } else {
            // Fallback: get phone from WooCommerce general settings or use admin phone
            $wc_store_phone = get_option('woocommerce_store_phone');
            if (!empty($wc_store_phone)) {
                $receiver_phone = $wc_store_phone;
            } else {
                // Last resort: use the original order's phone (since it's a return)
                $receiver_phone = $order->get_billing_phone() ?: $order->get_shipping_phone();
            }
        }

        // Get customer details first
        $customer_first_name = $order->get_billing_first_name() ?: $order->get_shipping_first_name();
        $customer_last_name = $order->get_billing_last_name() ?: $order->get_shipping_last_name();
        $customer_phone = $order->get_billing_phone() ?: $order->get_shipping_phone();

        // For Return Pickup (Type 25), sender and receiver are reversed:
        // Sender = Business/Merchant (who initiates the pickup request)
        // Receiver = Customer (from whom items will be picked up)

        $payload->sender = new stdClass();
        $payload->sender->firstName = $business_name;
        $payload->sender->lastName = '';
        $payload->sender->phone = $receiver_phone;

        $payload->receiver = new stdClass();
        $payload->receiver->firstName = $customer_first_name ?: 'عميل';
        $payload->receiver->lastName = $customer_last_name ?: '';
        $payload->receiver->phone = $customer_phone ?: '';
        $payload->receiver->email = $order->get_billing_email() ?: '';

        // Pickup address (from customer)
        $payload->pickupAddress = new stdClass();
        $payload->pickupAddress->firstLine = !empty($pickup_address) ? $pickup_address : ($order->get_billing_address_1() ?: $order->get_shipping_address_1());
        $payload->pickupAddress->secondLine = $order->get_billing_address_2() ?: $order->get_shipping_address_2();
        $payload->pickupAddress->city = $order->get_billing_city() ?: $order->get_shipping_city() ?: '';
        $payload->pickupAddress->zone = $order->get_billing_state() ?: $order->get_shipping_state() ?: '';

        // Delivery address (business address where returns will be delivered)
        // Use dropOffAddress as the destination for the return
        $payload->dropOffAddress = new stdClass();

        // If return address is configured, use it
        if (isset($bosta_settings['ReturnAddress']) && !empty($bosta_settings['ReturnAddress'])) {
            $payload->dropOffAddress->firstLine = $bosta_settings['ReturnAddress'];
            $payload->dropOffAddress->city = isset($bosta_settings['ReturnCity']) && !empty($bosta_settings['ReturnCity'])
                ? $bosta_settings['ReturnCity']
                : ($order->get_billing_city() ?: $order->get_shipping_city() ?: '');
            $payload->dropOffAddress->zone = isset($bosta_settings['ReturnZone']) && !empty($bosta_settings['ReturnZone'])
                ? $bosta_settings['ReturnZone']
                : ($order->get_billing_state() ?: $order->get_shipping_state() ?: '');
        } else {
            // Fallback: use the original order address as destination
            // (This assumes returns go back to the original sender)
            $payload->dropOffAddress->firstLine = $order->get_billing_address_1() ?: $order->get_shipping_address_1() ?: '';
            $payload->dropOffAddress->city = $order->get_billing_city() ?: $order->get_shipping_city() ?: '';
            $payload->dropOffAddress->zone = $order->get_billing_state() ?: $order->get_shipping_state() ?: '';
        }

        // Goods info
        $payload->goodsInfo = new stdClass();
        $payload->goodsInfo->amount = $order->get_total();

        // Add webhook URL if not localhost
        $site_url = get_site_url();
        if (strpos($site_url, 'localhost') === false && strpos($site_url, '127.0.0.1') === false) {
            if (class_exists('Bosta_Webhook_Manager')) {
                $payload->webhookUrl = $site_url . '/wp-json/bosta/v1/webhook/orders/' . $order->get_id();
                $payload->webhookCustomHeaders = new stdClass();
                $payload->webhookCustomHeaders->{"X-Bosta-Secret"} = Bosta_Webhook_Manager::get_webhook_secret();
            }
        }

        return $payload;
    }

    /**
     * Get delivery data from Bosta API
     *
     * @param string $delivery_id Delivery ID
     * @param string $api_key API key
     * @return array|null Delivery data or null on failure
     */
    private static function get_delivery_data($delivery_id, $api_key) {
        if (!function_exists('bosta_send_api_request')) {
            return null;
        }

        $url = BOSTA_ENV_URL_V2 . '/deliveries/business/' . $delivery_id;
        $response = bosta_send_api_request('GET', $url, $api_key);

        if ($response['success'] && isset($response['body'])) {
            return $response['body'];
        }

        return null;
    }

    /**
     * Add bulk action for creating returns
     */
    public static function add_bulk_return_action($actions) {
        $actions['create_bosta_returns'] = __('إنشاء طلبات مرتجعات بوسطة', 'bosta-woocommerce');
        return $actions;
    }

    /**
     * Handle bulk return action
     */
    public static function handle_bulk_return_action($redirect_to, $action, $order_ids) {
        if ($action !== 'create_bosta_returns') {
            return $redirect_to;
        }

        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $error_count++;
                continue;
            }

            // Use default values for bulk action
            $items_count = $order->get_item_count();
            $description = 'طلب إرجاع جماعي للطلب رقم #' . $order_id;
            $pickup_address = $order->get_billing_address_1() ?: $order->get_shipping_address_1();

            $result = self::create_return_delivery($order, $items_count, $description, $pickup_address);

            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = sprintf('الطلب #%d: %s', $order_id, $result['error']);
            }
        }

        // Add admin notices
        if ($success_count > 0) {
            set_transient('bsi_returns_success_count', $success_count, 60);
        }

        if ($error_count > 0) {
            set_transient('bsi_returns_errors', implode('<br>', $errors), 60);
        }

        add_action('admin_notices', [__CLASS__, 'show_bulk_action_notices']);

        return $redirect_to;
    }

    /**
     * Show bulk action notices
     */
    public static function show_bulk_action_notices() {
        $success_count = get_transient('bsi_returns_success_count');
        $errors = get_transient('bsi_returns_errors');

        if ($success_count) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(_n('تم إنشاء طلب مرتجعات واحد بنجاح', 'تم إنشاء %d طلبات مرتجعات بنجاح', $success_count, 'bosta-woocommerce'), $success_count) . '</p>';
            echo '</div>';
            delete_transient('bsi_returns_success_count');
        }

        if ($errors) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>حدثت أخطاء:</strong></p>';
            echo '<div style="max-height: 150px; overflow-y: auto;">' . $errors . '</div>';
            echo '</div>';
            delete_transient('bsi_returns_errors');
        }
    }
}
