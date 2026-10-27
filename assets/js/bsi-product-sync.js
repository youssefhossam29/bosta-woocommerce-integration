/**
 * BSI Product Sync — Admin JavaScript
 * Handles batch product synchronization, live statistics, and single-product sync.
 *
 * @package BSI_Product_Sync
 * @since   1.2.0
 */

(function($) {
    'use strict';

    if (typeof window.bsiSync === 'undefined') {
        return;
    }

    var cfg = window.bsiSync;
    var isSyncing = false;
    var isStopped = false;

    // -------------------------------------------------------------------------
    // Document Ready
    // -------------------------------------------------------------------------
    $(function() {
        initSyncPage();
        initProductRowActions();
    });

    // -------------------------------------------------------------------------
    // Dedicated Sync Page: "منتجات بوسطة"
    // -------------------------------------------------------------------------
    function initSyncPage() {
        var $page = $('.bsi-wrap');
        if (!$page.length) {
            return;
        }

        // Load stats on page load
        loadStats();

        // Refresh stats button
        $('#bsi-refresh-stats').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.addClass('bsi-spinning');
            loadStats(function() {
                $btn.removeClass('bsi-spinning');
            });
        });

        // Sync Stock & Data button
        $('#bsi-btn-sync-stock').on('click', function(e) {
            e.preventDefault();
            if (isSyncing) {
                return;
            }

            if (!confirm(cfg.i18n.confirmStock || 'Sync stock and product data for all products?')) {
                return;
            }

            startBulkSync('stock');
        });

        // Start bulk sync button (New products only)
        $('#bsi-btn-start').on('click', function(e) {
            e.preventDefault();
            if (isSyncing) {
                return;
            }

            if (!confirm(cfg.i18n.confirmNew || 'Sync new products to Bosta?')) {
                return;
            }

            startBulkSync('new_only');
        });

        // Stop bulk sync button
        $('#bsi-btn-stop').on('click', function(e) {
            e.preventDefault();
            stopBulkSync();
        });
    }

    var currentMode = 'stock';

    /**
     * Fetch sync statistics via AJAX and update UI cards
     */
    function loadStats(callback) {
        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bsi_get_sync_status',
                nonce: cfg.nonce
            }
        }).done(function(res) {
            if (res && res.success && res.data) {
                var d = res.data;
                $('#bsi-total').text(Number(d.total).toLocaleString());
                $('#bsi-synced').text(Number(d.synced).toLocaleString());
                $('#bsi-pending').text(Number(d.pending).toLocaleString());

                var autoStatus = d.auto_sync_enabled ? cfg.i18n.autoEnabled : cfg.i18n.autoDisabled;
                $('#bsi-auto-sync').text(autoStatus);
            }
        }).fail(function() {
            // Leave placeholders on network error
        }).always(function() {
            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    /**
     * Start the batch sync cycle
     *
     * @param {string} mode 'stock' or 'new_only'
     */
    function startBulkSync(mode) {
        currentMode = mode || 'stock';
        isSyncing   = true;
        isStopped   = false;

        $('#bsi-btn-sync-stock, #bsi-btn-start').hide();
        $('#bsi-btn-stop').show();

        $('#bsi-success-notice').hide();
        $('#bsi-errors-list').hide().empty();
        $('#bsi-progress-wrap').slideDown(200);

        $('#bsi-progress-bar').css('width', '0%');
        $('#bsi-progress-label').text(currentMode === 'stock' ? (cfg.i18n.syncingStock || cfg.i18n.syncing) : cfg.i18n.syncing);
        $('#bsi-progress-counter').text('0 / ' + ($('#bsi-total').text() || '0'));

        // Start from offset 0
        processBatch(0);
    }

    /**
     * Stop the batch sync cycle
     */
    function stopBulkSync() {
        isStopped = true;
        isSyncing = false;

        $('#bsi-progress-label').text(cfg.i18n.stopped);
        $('#bsi-btn-stop').hide();
        $('#bsi-btn-sync-stock, #bsi-btn-start').show();

        loadStats();
    }

    /**
     * Process a single batch recursively
     *
     * @param {number} offset
     */
    function processBatch(offset) {
        if (isStopped) {
            return;
        }

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bsi_sync_batch',
                nonce: cfg.nonce,
                offset: offset,
                batch_size: cfg.batchSize || 10,
                mode: currentMode
            }
        }).done(function(res) {
            if (isStopped) {
                return;
            }

            if (!res || !res.success) {
                var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Error processing batch';
                showBatchError(errMsg);
                stopBulkSync();
                return;
            }

            var d = res.data;
            var total = d.total || 0;
            var currentProcessed = Math.min(d.offset, total);

            // Display errors if any in this batch
            if (d.errors && d.errors.length) {
                $('#bsi-errors-list').show();
                d.errors.forEach(function(err) {
                    $('#bsi-errors-list').append($('<div class="bsi-error-item"></div>').text(err));
                });
            }

            // Update progress bar
            var pct = total > 0 ? Math.min(100, Math.round((currentProcessed / total) * 100)) : 100;
            $('#bsi-progress-bar').css('width', pct + '%');
            $('#bsi-progress-counter').text(currentProcessed.toLocaleString() + ' / ' + total.toLocaleString() + ' ' + (cfg.i18n.products || ''));

            if (d.done) {
                // All products processed!
                completeBulkSync(total);
            } else {
                // Next batch after a brief pause (allows browser to render & server to breathe)
                setTimeout(function() {
                    processBatch(d.offset);
                }, 300);
            }
        }).fail(function(xhr, status, error) {
            if (isStopped) {
                return;
            }
            var msg = '';
            var statusCode = xhr ? xhr.status : 0;

            if (statusCode === 504) {
                msg = 'Gateway Timeout (504). Saved progress has been preserved; click "Sync Stock & Data for Existing Products" or "Sync New Products Only" to resume.';
            } else if (statusCode === 500) {
                msg = 'Server Error (500 Internal Server Error). Please try again.';
            } else if (statusCode === 403) {
                msg = 'Session expired or security check failed (403 Forbidden). Please refresh the page and try again.';
            } else if (statusCode === 0 || (typeof navigator !== 'undefined' && !navigator.onLine)) {
                msg = 'Network connection lost. Please check your internet connection and resume.';
            } else {
                msg = 'Server connection error (' + (error || ('Status code: ' + statusCode)) + '). Please resume when ready.';
            }

            showBatchError(msg);
            stopBulkSync();
        });
    }

    /**
     * Complete the sync process successfully
     */
    function completeBulkSync(total) {
        isSyncing = false;

        $('#bsi-progress-bar').css('width', '100%');
        $('#bsi-progress-label').text(cfg.i18n.done);

        $('#bsi-btn-stop').hide();
        $('#bsi-btn-sync-stock, #bsi-btn-start').show();

        $('#bsi-success-text').text(
            cfg.i18n.done + ' (' + total.toLocaleString() + ' ' + (cfg.i18n.products || '') + ')'
        );
        $('#bsi-success-notice').slideDown(200);

        // Refresh stats
        loadStats();
    }

    /**
     * Show an error message in the progress wrap
     */
    function showBatchError(msg) {
        $('#bsi-errors-list').show().append(
            $('<div class="bsi-error-item"></div>').text(msg)
        );
    }

    // -------------------------------------------------------------------------
    // Products List: "Sync to Bosta" row action
    // -------------------------------------------------------------------------
    function initProductRowActions() {
        $(document).on('click', '.bsi-row-sync', function(e) {
            e.preventDefault();

            var $link = $(this);
            if ($link.hasClass('bsi-syncing')) {
                return;
            }

            var productId = $link.data('product-id');
            var nonce = $link.data('nonce') || cfg.nonce;
            var originalText = $link.text();

            $link.addClass('bsi-syncing').text(cfg.i18n.syncing);

            $.ajax({
                url: cfg.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'bsi_sync_single',
                    product_id: productId,
                    nonce: nonce
                }
            }).done(function(res) {
                if (res && res.success) {
                    $link.text(cfg.i18n.syncOk);
                    // Update badge column if present in table
                    var $row = $link.closest('tr');
                    $row.find('.column-bosta_sync_status')
                        .html('<span class="bsi-badge bsi-badge--synced" title="Synced">✓</span>');

                    setTimeout(function() {
                        $link.removeClass('bsi-syncing').text(cfg.i18n.syncOk);
                    }, 2000);
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : cfg.i18n.syncFail;
                    alert(err);
                    $link.removeClass('bsi-syncing').text(originalText);
                }
            }).fail(function() {
                alert(cfg.i18n.syncFail);
                $link.removeClass('bsi-syncing').text(originalText);
            });
        });
    }

})(jQuery);
