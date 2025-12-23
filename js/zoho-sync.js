jQuery(document).ready(function($) {
    var syncDirection = 'zoho_to_woo';
    
    $('#zoho-start-sync').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $progressContainer = $('#zoho-sync-progress');
        var $progressBar = $('#zoho-progress-bar');
        var $progressText = $('#zoho-progress-text');
        var $successMessage = $('#zoho-sync-success');
        
        // Get selected sync direction
        syncDirection = $('input[name="inventory_sync_direction"]:checked').val() || 'zoho_to_woo';
        
        // Reset UI
        $button.prop('disabled', true).text('Syncing...');
        $progressContainer.show();
        $successMessage.hide();
        $('#zoho-sync-result').hide();
        $progressBar.css('width', '0%');
        $progressText.text('Starting sync (' + (syncDirection === 'zoho_to_woo' ? 'Zoho → WooCommerce' : 'WooCommerce → Zoho') + ')...');
        
        // Start AJAX sync
        zohoSyncProducts(0, 0, 0, 0, 0, syncDirection);
    });
    
    function zohoSyncProducts(offset, total, synced, created, updated, direction) {
        $.ajax({
            url: zohoSync.ajax_url,
            type: 'POST',
            data: {
                action: 'zoho_sync_products_ajax',
                nonce: zohoSync.nonce,
                offset: offset,
                total: total,
                synced: synced,
                created: created,
                updated: updated,
                direction: direction
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update progress
                    var percentage = data.total > 0 ? Math.round((data.synced / data.total) * 100) : 0;
                    $('#zoho-progress-bar').css('width', percentage + '%');
                    
                    var progressText = 'Syncing: ' + data.synced + ' / ' + data.total + ' products completed';
                    var pendingText = ' | Pending: ' + (data.total - data.synced);
                    if (data.created > 0) progressText += ' | Created: ' + data.created;
                    if (data.updated > 0) progressText += ' | Updated: ' + data.updated;
                    progressText += pendingText;
                    
                    $('#zoho-progress-text').text(progressText);
                    $('#zoho-progress-percentage').text(percentage + '%');
                    
                    // Check if more products to process
                    if (data.has_more) {
                        // Continue with next batch
                        setTimeout(function() {
                            zohoSyncProducts(data.offset, data.total, data.synced, data.created, data.updated, direction);
                        }, 100);
                    } else {
                        // Sync completed
                        $('#zoho-progress-bar').css('width', '100%');
                        $('#zoho-progress-percentage').text('100%');
                        $('#zoho-progress-text').text('Sync completed!');
                        
                        // Show success message
                        var successHtml = '<div class="notice notice-success"><p><strong>Sync Completed!</strong></p>' +
                            '<p>Total: ' + data.total + ' | Synced: ' + data.synced + ' | Created: ' + data.created + ' | Updated: ' + data.updated + ' | Pending: ' + data.pending + '</p></div>';
                        $('#zoho-sync-success').html(successHtml).show();
                        
                        // Reset button
                        $('#zoho-start-sync').prop('disabled', false).text('Sync Products Now');
                        
                        // Reload page after 2 seconds to show updated log
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    // Error occurred
                    $('#zoho-progress-text').text('Error: ' + (response.data || 'Unknown error'));
                    $('#zoho-start-sync').prop('disabled', false).text('Sync Products Now');
                    alert('Sync failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                $('#zoho-progress-text').text('Error: ' + error);
                $('#zoho-start-sync').prop('disabled', false).text('Sync Products Now');
                alert('Sync failed: ' + error);
            }
        });
    }
});
