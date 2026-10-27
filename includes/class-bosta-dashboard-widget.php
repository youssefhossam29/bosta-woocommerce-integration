<?php
/**
 * Bosta Dashboard Widget
 * Displays statistics and quick actions in WordPress dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSI_Dashboard_Widget {

    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        // Only show to users who can manage WooCommerce
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'bosta_statistics_widget',
            __('إحصائيات بوسطة', 'bosta-woocommerce'),
            [$this, 'render_widget_content']
        );
    }

    /**
     * Render widget content
     */
    public function render_widget_content() {
        // Get today's statistics
        $today = date('Y-m-d 00:00:00');
        $stats_today = BSI_Utilities::get_statistics($today);
        
        // Get all-time statistics
        $stats_all = BSI_Utilities::get_statistics();
        
        ?>
        <div class="bosta-dashboard-widget" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;">
            
            <!-- Today's Stats -->
            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
                <h4 style="margin: 0 0 15px 0; color: #1e293b; font-size: 14px; font-weight: 600;">
                    📊 <?php _e('إحصائيات اليوم', 'bosta-woocommerce'); ?>
                </h4>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                    <div style="padding: 12px; background: #f0f9ff; border-radius: 6px; border-left: 3px solid #0ea5e9;">
                        <div style="color: #64748b; font-size: 12px; margin-bottom: 4px;"><?php _e('تم المزامنة', 'bosta-woocommerce'); ?></div>
                        <div style="color: #0ea5e9; font-size: 24px; font-weight: 600;"><?php echo number_format($stats_today['total_synced']); ?></div>
                    </div>
                    
                    <div style="padding: 12px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b;">
                        <div style="color: #64748b; font-size: 12px; margin-bottom: 4px;"><?php _e('بانتظار الإرسال', 'bosta-woocommerce'); ?></div>
                        <div style="color: #f59e0b; font-size: 24px; font-weight: 600;"><?php echo number_format($stats_today['pending_sync']); ?></div>
                    </div>
                    
                    <div style="padding: 12px; background: #f0fdf4; border-radius: 6px; border-left: 3px solid #10b981;">
                        <div style="color: #64748b; font-size: 12px; margin-bottom: 4px;"><?php _e('تم التحصيل', 'bosta-woocommerce'); ?></div>
                        <div style="color: #10b981; font-size: 24px; font-weight: 600;"><?php echo number_format($stats_today['cod_collected']); ?></div>
                    </div>
                    
                    <div style="padding: 12px; background: #faf5ff; border-radius: 6px; border-left: 3px solid #a855f7;">
                        <div style="color: #64748b; font-size: 12px; margin-bottom: 4px;"><?php _e('مبلغ التحصيل', 'bosta-woocommerce'); ?></div>
                        <div style="color: #a855f7; font-size: 20px; font-weight: 600;">
                            <?php echo number_format($stats_today['total_cod_amount'], 0); ?> 
                            <span style="font-size: 12px;"><?php _e('ج.م', 'bosta-woocommerce'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All Time Stats -->
            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
                <h4 style="margin: 0 0 15px 0; color: #1e293b; font-size: 14px; font-weight: 600;">
                    📈 <?php _e('إحصائيات إجمالية', 'bosta-woocommerce'); ?>
                </h4>
                
                <div style="display: flex; justify-content: space-between; gap: 12px;">
                    <div style="flex: 1; text-align: center; padding: 12px; background: #f8fafc; border-radius: 6px;">
                        <div style="color: #64748b; font-size: 11px; margin-bottom: 4px;"><?php _e('إجمالي الطلبات', 'bosta-woocommerce'); ?></div>
                        <div style="color: #0f172a; font-size: 20px; font-weight: 600;"><?php echo number_format($stats_all['total_synced']); ?></div>
                    </div>
                    
                    <div style="flex: 1; text-align: center; padding: 12px; background: #f8fafc; border-radius: 6px;">
                        <div style="color: #64748b; font-size: 11px; margin-bottom: 4px;"><?php _e('إجمالي التحصيلات', 'bosta-woocommerce'); ?></div>
                        <div style="color: #10b981; font-size: 18px; font-weight: 600;">
                            <?php echo number_format($stats_all['total_cod_amount'], 0); ?> 
                            <span style="font-size: 10px;"><?php _e('ج.م', 'bosta-woocommerce'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo admin_url('admin.php?page=wc-orders&status=ready_ship_bosta'); ?>" 
                       class="button button-secondary" 
                       style="flex: 1; text-align: center; min-width: 0;">
                        <span class="dashicons dashicons-upload" style="font-size: 16px; vertical-align: middle;"></span>
                        <?php _e('جاهز للشحن', 'bosta-woocommerce'); ?>
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=wc-orders&status=sent_to_bosta'); ?>" 
                       class="button button-secondary" 
                       style="flex: 1; text-align: center; min-width: 0;">
                        <span class="dashicons dashicons-location" style="font-size: 16px; vertical-align: middle;"></span>
                        <?php _e('مع بوسطة', 'bosta-woocommerce'); ?>
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=wc-orders&status=cod_collected'); ?>" 
                       class="button button-secondary" 
                       style="flex: 1; text-align: center; min-width: 0;">
                        <span class="dashicons dashicons-money-alt" style="font-size: 16px; vertical-align: middle;"></span>
                        <?php _e('تم التحصيل', 'bosta-woocommerce'); ?>
                    </a>
                </div>
                
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=bosta'); ?>" 
                   class="button button-primary" 
                   style="width: 100%; text-align: center; margin-top: 12px;">
                    <span class="dashicons dashicons-admin-settings" style="font-size: 16px; vertical-align: middle;"></span>
                    <?php _e('إعدادات بوسطة', 'bosta-woocommerce'); ?>
                </a>
            </div>

            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f1; text-align: center;">
                <small style="color: #94a3b8; font-size: 11px;">
                    <?php printf(__('آخر تحديث: %s', 'bosta-woocommerce'), date_i18n('Y-m-d H:i')); ?>
                </small>
            </div>
        </div>
        <?php
    }
}

