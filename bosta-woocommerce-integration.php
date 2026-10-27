<?php
/**
 * Plugin Name: Bosta WooCommerce Status Integration
 * Plugin URI: https://github.com/youssefhossam29/bosta-woocommerce-integration
 * Description: Complete integration between WooCommerce and Bosta shipping service with role-based permissions, webhook automation, order status management, and returns handling
 * Version: 1.0.0
 * Author: Youssef Hossam
 * Author URI: https://github.com/youssefhossam29
 * Text Domain: bosta-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 * Tested up to: 6.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('BOSTA_WC_VERSION', '1.0.0');
define('BOSTA_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOSTA_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOSTA_WC_PLUGIN_FILE', __FILE__);

final class BSI_Main_Plugin {

	private static $instance = null;

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		$this->check_version_update();
		add_action('plugins_loaded', [$this, 'load_dependencies'], 11);
		add_action('init', [$this, 'load_textdomain']);
		add_action('init', [$this, 'register_order_statuses']);
		add_filter('wc_order_statuses', [$this, 'add_custom_statuses_to_list']);
		add_action('admin_notices', [$this, 'show_update_notice']);

		register_activation_hook(__FILE__, [$this, 'activate']);
		register_deactivation_hook(__FILE__, [$this, 'deactivate']);
	}

	/**
	 * Check if plugin was updated and clear cache
	 */
	public function check_version_update() {
		$last_version = get_option('bosta_wc_last_update', '0.0.0');
		if (version_compare($last_version, BOSTA_WC_VERSION, '<')) {
			$this->clear_plugin_cache();
		}
	}

	/**
	 * Show admin notice after update
	 */
	public function show_update_notice() {
		if (get_transient('bosta_wc_updated_notice')) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><strong>Bosta WooCommerce Integration:</strong> تم تحديث البلاجن بنجاح! عمود بوسطة مفعّل الآن في قائمة الطلبات.</p>
			</div>
			<?php
			delete_transient('bosta_wc_updated_notice');
		}
	}

	public function load_dependencies() {
		if (!$this->check_requirements()) {
			return;
		}

		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-utilities.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-settings.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-order-status.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-permissions.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-webhook.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-cod-handler.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-admin-ui.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-dashboard-widget.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class_payment_handler.php';
		require_once BOSTA_WC_PLUGIN_DIR . 'includes/class-bosta-returns-handler.php';

		if (is_admin()) {
			new BSI_Settings();
			new BSI_Admin_UI();
			new BSI_Dashboard_Widget();
		}

		new BSI_Order_Status_Manager();
		new BSI_Permissions_Manager();
		new BSI_Webhook_Handler();
		new BSI_COD_Handler();
		new BSI_Payment_Handler();
		BSI_Returns_Handler::init();
	}

	public function check_requirements() {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', function() {
				?>
				<div class="notice notice-error">
					<p><?php _e('Bosta WooCommerce Integration requires WooCommerce to be installed and active.', 'bosta-woocommerce'); ?></p>
				</div>
				<?php
			});
			return false;
		}
		return true;
	}

	public function load_textdomain() {
		load_plugin_textdomain('bosta-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	public function register_order_statuses() {
		$statuses = [
			'awaiting_confirm' => _x('بانتظار التأكيد', 'Order status', 'bosta-woocommerce'),
			'confirm_preparing' => _x('تم التأكيد (قيد التحضير)', 'Order status', 'bosta-woocommerce'),
			'ready_ship_agent' => _x('جاهز للشحن (مندوب خاص)', 'Order status', 'bosta-woocommerce'),
			'ready_ship_bosta' => _x('جاهز للشحن (بوسطة)', 'Order status', 'bosta-woocommerce'),
			'sent_to_bosta' => _x('تم الإرسال إلى بوسطة', 'Order status', 'bosta-woocommerce'),
			'handed_to_bosta' => _x('تم التسليم لبوسطة', 'Order status', 'bosta-woocommerce'),
			'handed_to_agent' => _x('تم التسليم للمندوب', 'Order status', 'bosta-woocommerce'),
			'cod_collected' => _x('تم تحصيل المبلغ', 'Order status', 'bosta-woocommerce'),
			'delivered' => _x('تم التسليم', 'Order status', 'bosta-woocommerce'),
			'returned' => _x('تم الإرجاع', 'Order status', 'bosta-woocommerce'),
		];

		foreach ($statuses as $status => $label) {
			register_post_status('wc-' . $status, [
				'label' => $label,
				'public' => true,
				'show_in_admin_status_list' => true,
				'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'bosta-woocommerce')
			]);
		}
	}

	public function add_custom_statuses_to_list($order_statuses) {
		$new_statuses = [
			'wc-awaiting_confirm' => _x('بانتظار التأكيد', 'Order status', 'bosta-woocommerce'),
			'wc-confirm_preparing' => _x('تم التأكيد (قيد التحضير)', 'Order status', 'bosta-woocommerce'),
			'wc-ready_ship_agent' => _x('جاهز للشحن (مندوب خاص)', 'Order status', 'bosta-woocommerce'),
			'wc-ready_ship_bosta' => _x('جاهز للشحن (بوسطة)', 'Order status', 'bosta-woocommerce'),
			'wc-sent_to_bosta' => _x('تم الإرسال إلى بوسطة', 'Order status', 'bosta-woocommerce'),
			'wc-handed_to_bosta' => _x('تم التسليم لبوسطة', 'Order status', 'bosta-woocommerce'),
			'wc-handed_to_agent' => _x('تم التسليم للمندوب', 'Order status', 'bosta-woocommerce'),
			'wc-cod_collected' => _x('تم تحصيل المبلغ', 'Order status', 'bosta-woocommerce'),
			'wc-delivered' => _x('تم التسليم', 'Order status', 'bosta-woocommerce'),
			'wc-returned' => _x('تم الإرجاع', 'Order status', 'bosta-woocommerce'),
		];

		return array_merge($order_statuses, $new_statuses);
	}

	public function activate() {
		if (!class_exists('WooCommerce')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die(__('This plugin requires WooCommerce to be installed and active.', 'bosta-woocommerce'));
		}

		flush_rewrite_rules();
		$this->set_default_options();
		$this->clear_plugin_cache();
	}

	/**
	 * Clear plugin cache and force WordPress to reload files
	 */
	private function clear_plugin_cache() {
		// Clear WP object cache
		wp_cache_flush();

		// Delete user column preferences to reset hidden columns
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%managewoocommerce_page_wc-orderscolumnshidden%'");
		$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%manageedit-shop_ordercolumnshidden%'");

		// Set flag that plugin was updated
		update_option('bosta_wc_last_update', BOSTA_WC_VERSION);
		set_transient('bosta_wc_updated_notice', true, 60);
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	private function set_default_options() {
		$defaults = [
			'bosta_webhook_secret' => wp_generate_password(32, false),
			'bosta_business_url' => 'https://business.bosta.co',
			'auto_status_change' => 'yes',
			'enable_tracking_button' => 'yes',
		];

		foreach ($defaults as $key => $value) {
			if (get_option($key) === false) {
				add_option($key, $value);
			}
		}
	}
}


// Declare HPOS compatibility early
add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('plugins_loaded', function() {
	BSI_Main_Plugin::get_instance();
}, 10);

/**
 * Intercept Bosta API requests to remove "Woocommerce_" prefix
 * This is necessary because the official Bosta plugin hardcodes the prefix in the API payload.
 */
add_filter('http_request_args', 'bsi_intercept_bosta_api_request', 99999, 2);

function bsi_intercept_bosta_api_request($args, $url) {
	// 1. Target only requests going to Bosta API
	if (strpos($url, 'bosta.co') === false) {
		return $args;
	}

	// 2. Ensure we have a body to work with
	if (empty($args['body'])) {
		return $args;
	}

	// 3. Decode the body
	$body = json_decode($args['body'], true);
	
	if (json_last_error() !== JSON_ERROR_NONE) {
		return $args;
	}

	// 4. Recursive cleaning function
	$cleaned = false;
	$clean_recursive = function(&$data) use (&$clean_recursive, &$cleaned) {
		if (is_array($data)) {
			foreach ($data as $key => &$value) {
				if ($key === 'businessReference' && is_string($value)) {
					$clean_val = preg_replace('/^woocommerce_/i', '', $value);
					if ($clean_val !== $value) {
						$value = $clean_val;
						$cleaned = true;
					}
				} elseif (is_array($value)) {
					$clean_recursive($value);
				}
			}
		}
	};

	// Execute recursive cleaning
	$clean_recursive($body);

	// 5. If changes were made, re-encode
	if ($cleaned) {
		$args['body'] = json_encode($body);
	}

	return $args;
}
