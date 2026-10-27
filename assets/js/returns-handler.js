/**
 * Bosta Returns Handler JavaScript
 *
 * Handles the creation of return deliveries from the order edit page
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Handle create return button click
        $('#bsi_create_return_btn').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $messageDiv = $('#bsi_return_message');
            var orderId = $button.data('order-id');
            var itemsCount = $('#bsi_return_items_count').val();
            var description = $('#bsi_return_description').val();
            var pickupAddress = $('#bsi_return_pickup_address').val();

            // Validation
            if (!itemsCount || itemsCount < 1) {
                showMessage('يرجى إدخال عدد العناصر المرتجعة', 'error');
                return;
            }

            if (!description || description.trim() === '') {
                showMessage('يرجى إدخال وصف للمرتجعات', 'error');
                return;
            }

            if (!pickupAddress || pickupAddress.trim() === '') {
                showMessage('يرجى إدخال عنوان الاستلام', 'error');
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true);
            $button.text(bsiReturns.creating_text);
            $messageDiv.hide();

            // Send AJAX request
            $.ajax({
                url: bsiReturns.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsi_create_return_delivery',
                    nonce: bsiReturns.nonce,
                    order_id: orderId,
                    items_count: itemsCount,
                    description: description,
                    pickup_address: pickupAddress
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(
                            response.data.message + '<br>' +
                            'رقم التتبع: <strong>' + response.data.tracking_number + '</strong>',
                            'success'
                        );

                        // Reload page after 2 seconds to show updated meta box
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showMessage(response.data.message || 'حدث خطأ غير متوقع', 'error');
                        $button.prop('disabled', false);
                        $button.text(bsiReturns.create_text);
                    }
                },
                error: function(xhr, status, error) {
                    showMessage('حدث خطأ في الاتصال: ' + error, 'error');
                    $button.prop('disabled', false);
                    $button.text(bsiReturns.create_text);
                }
            });
        });

        /**
         * Show message to user
         */
        function showMessage(message, type) {
            var $messageDiv = $('#bsi_return_message');
            $messageDiv
                .removeClass('success error')
                .addClass(type)
                .html(message)
                .fadeIn();
        }
    });

})(jQuery);
