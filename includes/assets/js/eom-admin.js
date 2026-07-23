/**
 * Easy Order Manager - Admin JavaScript
 *
 * Handles DataTable initialization, bulk actions,
 * filters, inline editing, and modal operations.
 *
 * @package EasyOrderManager
 */

(function($) {
    'use strict';

    var EOM = {
        ordersTable: null,
        init: function() {
            this.initFilters();
            this.initDataTable();
            this.initBulkActions();
            this.initInlineEdit();
            this.initModals();
            this.initExport();
            this.initImport();
            this.initPrintAndBlock();
        },

        initFilters: function() {
            $('#eom-apply-filters').on('click', function() {
                if (EOM.ordersTable) {
                    EOM.ordersTable.ajax.reload();
                }
            });

            $('#eom-reset-filters').on('click', function() {
                $('.eom-filter-bar input[type="text"], .eom-filter-bar input[type="date"], .eom-filter-bar select').val('');
                if (EOM.ordersTable) {
                    EOM.ordersTable.ajax.reload();
                }
            });

            $('.eom-status-pill').on('click', function() {
                var status = $(this).data('status');
                if (status && status !== '') {
                    var $filterStatus = $('#eom-filter-status');
                    if ($filterStatus.length) {
                        $filterStatus.val(status).trigger('change');
                    }
                }
                if (EOM.ordersTable) {
                    EOM.ordersTable.ajax.reload();
                }
            });
        },

        initDataTable: function() {
            if (!$.fn.DataTable || !$('#eom-orders-table').length) {
                return;
            }

            EOM.ordersTable = $('#eom-orders-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: eom_ajax.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'eom_get_orders';
                        d._ajax_nonce = eom_ajax.nonces.eom_get_orders;
                        $('.eom-filter-bar input, .eom-filter-bar select').each(function() {
                            var name = $(this).attr('name');
                            if (name) {
                                d[name] = $(this).val();
                            }
                        });
                    }
                },
                columns: [
                { data: 'checkbox', orderable: false, searchable: false },
                { data: 'order_id', orderable: true, searchable: true },
                { data: 'date', orderable: true, searchable: false },
                { data: 'customer_name', orderable: true, searchable: true },
                { data: 'phone', orderable: false, searchable: true },
                { data: 'email', orderable: false, searchable: true },
                { data: 'products', orderable: false, searchable: false },
                { data: 'total', orderable: true, searchable: false },
                { data: 'payment_method', orderable: false, searchable: false },
                { data: 'status', orderable: true, searchable: false },
                { data: 'courier', orderable: false, searchable: false },
                { data: 'consignment_id', orderable: false, searchable: false },
                { data: 'tracking_id', orderable: false, searchable: false },
                { data: 'delivery_charge', orderable: false, searchable: false },
                    { data: 'cod_fee', orderable: false, searchable: false },
	                    { data: 'assigned_staff', orderable: false, searchable: false },
	                    { data: 'actions', orderable: false, searchable: false }
	                ],
                order: [[1, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                responsive: true,
                drawCallback: function() {
                    $('#eom-selected-count').text('0');
                    $('#eom-select-all').prop('checked', false);
                }
            });

            $('#eom-select-all').on('click', function() {
                var rows = EOM.ordersTable.rows({ page: 'current' }).nodes();
                var checked = this.checked;
                $('input.eom-order-checkbox', rows).each(function() {
                    $(this).prop('checked', checked);
                });
                EOM.updateSelectedCount();
            });

            $('#eom-orders-table tbody').on('change', 'input.eom-order-checkbox', function() {
                EOM.updateSelectedCount();
            });
        },

        updateSelectedCount: function() {
            var count = $('#eom-orders-table tbody input.eom-order-checkbox:checked').length;
            $('#eom-selected-count').text(count);
        },

        initBulkActions: function() {
            $('#eom-apply-bulk-action').on('click', function() {
                var action = $('#eom-bulk-action-select').val();
                if (!action) {
                    alert(eom_ajax.i18n.selectAction);
                    return;
                }

                var orderIds = [];
                $('#eom-orders-table tbody input.eom-order-checkbox:checked').each(function() {
                    orderIds.push($(this).val());
                });

                if (orderIds.length === 0) {
                    alert(eom_ajax.i18n.selectOrders);
                    return;
                }

                if ($.inArray(action, ['change_status', 'assign_staff', 'book_courier', 'send_sms']) !== -1) {
                    EOM.openBulkModal(action, orderIds);
                    return;
                }

                $.post(eom_ajax.ajax_url, {
                    action: 'eom_process_bulk_action',
                    _ajax_nonce: eom_ajax.nonces.eom_process_bulk_action,
                    order_ids: orderIds,
                    bulk_action: action
                }, function(response) {
                    if (response.success) {
                        // If response contains a URL (print_invoice), open it in new tab.
                        if (response.data && response.data.url) {
                            window.open(response.data.url, '_blank');
                        } else if (EOM.ordersTable) {
                            EOM.ordersTable.ajax.reload();
                        }
                    } else {
                        alert(response.data || eom_ajax.i18n.errorProcessing);
                    }
                });
            });
        },

        initPrintAndBlock: function() {
            // Single order print button on dashboard rows.
            $(document).on('click', '.eom-print-btn', function() {
                var orderId = $(this).data('order-id');
                var url = ajaxurl + '?action=eom_print_invoice&order_id=' + orderId + '&_wpnonce=' + eom_ajax.nonces.eom_print_invoice;
                window.open(url, '_blank');
            });

            // Block customer button on dashboard rows.
            $(document).on('click', '.eom-block-customer-btn', function() {
                var orderId = $(this).data('order-id');
                var btn = $(this);
                if (!confirm(eom_ajax.i18n.confirmBlock || 'Block this customer from placing new orders?')) {
                    return;
                }
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, {
                    action: 'eom_block_customer',
                    order_id: orderId,
                    _ajax_nonce: eom_ajax.nonces.eom_block_customer
                }, function(response) {
                    if (response.success) {
                        btn.text('Blocked').removeClass('eom-block-customer-btn').addClass('button-disabled');
                        alert(response.data.message || 'Customer blocked.');
                    } else {
                        alert(response.data || 'Failed to block customer.');
                        btn.prop('disabled', false).text('Block');
                    }
                });
            });
        },

        openBulkModal: function(action, orderIds) {
            var $modal = $('#eom-courier-modal');
            var title = '';
            var content = '';

            switch (action) {
                case 'change_status':
                    title = 'Change Status';
                    content = '<label>New Status:</label><select id="eom-bulk-status" style="width:100%">';
                    content += '<option value="">Select status</option>';
                    if (typeof wc_order_statuses !== 'undefined') {
                        $.each(wc_order_statuses, function(slug, label) {
                            content += '<option value="' + slug.replace('wc-', '') + '">' + label + '</option>';
                        });
                    }
                    content += '</select>';
                    break;

                case 'assign_staff':
                    title = 'Assign Staff';
                    content = '<label>Select Staff:</label><select id="eom-bulk-staff" style="width:100%">';
                    content += '<option value="">Select staff</option>';
                    if (typeof eom_ajax.staff_users !== 'undefined') {
                        $.each(eom_ajax.staff_users, function(i, u) {
                            content += '<option value="' + u.id + '">' + u.name + '</option>';
                        });
                    }
                    content += '</select>';
                    break;

                case 'book_courier':
                    title = 'Book Courier';
                    content = '<label>Select Courier:</label><select id="eom-bulk-courier" style="width:100%">';
                    content += '<option value="">Select courier</option>';
                    var couriers = ['steadfast', 'redx', 'pathao', 'sundarban', 'ecourier', 'paperfly', 'carriebee', 'others'];
                    $.each(couriers, function(i, c) {
                        content += '<option value="' + c + '">' + c.charAt(0).toUpperCase() + c.slice(1) + '</option>';
                    });
                    content += '</select>';
                    break;

                case 'send_sms':
                    title = 'Send SMS';
                    content = '<label>Message:</label><textarea id="eom-bulk-sms-message" rows="4" style="width:100%"></textarea>';
                    break;
            }

            $modal.html(
                '<div class="eom-modal-overlay">' +
                '<div class="eom-modal-content">' +
                '<h3>' + title + '</h3>' +
                '<div class="eom-modal-body">' + content + '</div>' +
                '<div class="eom-modal-footer">' +
                '<button type="button" class="button button-primary" id="eom-modal-confirm">Confirm</button> ' +
                '<button type="button" class="button" id="eom-modal-cancel">Cancel</button>' +
                '</div></div></div>'
            ).show();

            $('#eom-modal-confirm').off('click').on('click', function() {
                var value = '';
                switch (action) {
                    case 'change_status': value = $('#eom-bulk-status').val(); break;
                    case 'assign_staff':  value = $('#eom-bulk-staff').val(); break;
                    case 'book_courier':  value = $('#eom-bulk-courier').val(); break;
                    case 'send_sms':      value = $('#eom-bulk-sms-message').val(); break;
                }

                $.post(eom_ajax.ajax_url, {
                    action: 'eom_process_bulk_action',
                    _ajax_nonce: eom_ajax.nonces.eom_process_bulk_action,
                    order_ids: orderIds,
                    bulk_action: action,
                    value: value
                }, function(response) {
                    $modal.hide();
                    if (response.success) {
                        if (EOM.ordersTable) {
                            EOM.ordersTable.ajax.reload();
                        }
                    } else {
                        alert(response.data || eom_ajax.i18n.errorProcessing);
                    }
                });
            });

            $('#eom-modal-cancel').off('click').on('click', function() {
                $modal.hide();
            });
        },

        initInlineEdit: function() {
            $(document).on('click', '.eom-inline-edit-btn', function() {
                var orderId = $(this).data('order-id');
                $.post(eom_ajax.ajax_url, {
                    action: 'eom_get_inline_editor',
                    order_id: orderId,
                    _ajax_nonce: eom_ajax.nonces.eom_get_inline_editor
                }, function(response) {
                    if (response.success) {
                        $('#eom-inline-edit-modal').html(response.data.html).show();
                    } else {
                        alert(response.data || 'Error loading editor.');
                    }
                });
            });

            $(document).on('submit', '#eom-inline-edit-form', function(e) {
                e.preventDefault();
                var form = $(this);
                var orderId = form.data('order-id');
                var data = form.serializeArray();

                $.each(data, function(i, field) {
                    $.post(eom_ajax.ajax_url, {
                        action: 'eom_save_order_field',
                        order_id: orderId,
                        field_name: field.name,
                        field_value: field.value,
                        _ajax_nonce: eom_ajax.nonces.eom_save_order_field
                    }, function(response) {
                        if (response.success) {
                            if (EOM.ordersTable) {
                                EOM.ordersTable.ajax.reload();
                            }
                        }
                    });
                });

                $('#eom-inline-edit-modal').hide();
            });

            $(document).on('click', '.eom-inline-cancel', function() {
                $('#eom-inline-edit-modal').hide();
            });
        },

        initModals: function() {
            $(document).on('click', '.eom-modal-overlay', function(e) {
                if (e.target === this) {
                    $(this).closest('.eom-modal').hide();
                }
            });
        },

        initExport: function() {
            $('#eom-export-csv').on('click', function() {
                var filters = {};
                $('.eom-filter-bar input, .eom-filter-bar select').each(function() {
                    var name = $(this).attr('name');
                    if (name) {
                        filters[name] = $(this).val();
                    }
                });
                window.location.href = eom_ajax.ajax_url + '?action=eom_export_csv&_ajax_nonce=' + eom_ajax.nonces.eom_export_csv + '&filters=' + encodeURIComponent(JSON.stringify(filters));
            });
        },

        initImport: function() {
            // Toggle import section visibility.
            $(document).on('click', '.eom-import-toggle', function() {
                var $body = $(this).closest('.eom-import-section').find('.eom-import-body');
                var expanded = $(this).attr('aria-expanded') === 'true';
                $body.slideToggle(200);
                $(this).attr('aria-expanded', !expanded).text(expanded ? 'Show' : 'Hide');
            });

            // Handle file upload.
            $('#eom-import-steadfast-btn').on('click', function() {
                var $btn = $(this);
                var $spinner = $('.eom-import-spinner');
                var $results = $('#eom-import-results');
                var fileInput = document.getElementById('eom-steadfast-import-file');

                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    EOM.showToast('Please select a file first.', 'error');
                    return;
                }

                var file = fileInput.files[0];
                var ext = file.name.split('.').pop().toLowerCase();
                if (ext !== 'xlsx') {
                    EOM.showToast('Please upload an .xlsx file.', 'error');
                    return;
                }

                if (file.size > 10 * 1024 * 1024) {
                    EOM.showToast('File too large. Maximum 10MB.', 'error');
                    return;
                }

                // Disable button and show spinner.
                $btn.prop('disabled', true);
                $spinner.show();
                $results.hide().empty();

                var formData = new FormData();
                formData.append('action', 'eom_import_steadfast_xlsx');
                formData.append('_ajax_nonce', eom_ajax.nonces.eom_import_steadfast_xlsx);
                formData.append('file', file);

                $.ajax({
                    url: eom_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        $spinner.hide();
                        $btn.prop('disabled', false);

                        if (response.success) {
                            $results.html(
                                '<div class="eom-import-results-success">' +
                                '<span class="eom-import-result-icon">✅</span>' +
                                '<div>' + response.data.message + '</div>' +
                                '</div>'
                            ).show();

                            // Show detailed breakdown if available.
                            if (response.data.results && response.data.results.details && response.data.results.details.length > 0) {
                                var detailHtml = '<details class="eom-import-details"><summary>View detailed breakdown (' + response.data.results.details.length + ' rows)</summary>';
                                detailHtml += '<table class="wp-list-table widefat fixed striped"><thead><tr>' +
                                    '<th>Row</th><th>Status</th><th>Name</th><th>Phone</th><th>Tracking</th><th>Order ID</th>' +
                                    '</tr></thead><tbody>';

                                $.each(response.data.results.details, function(i, d) {
                                    var statusLabel = '';
                                    var statusClass = '';
                                    switch (d.status) {
                                        case 'matched_consignment':
                                            statusLabel = '✅ Parcel ID Match';
                                            statusClass = 'eom-import-status-ok';
                                            break;
                                        case 'matched_name_phone':
                                            statusLabel = '✅ Name+Phone Match';
                                            statusClass = 'eom-import-status-ok';
                                            break;
                                        case 'no_match':
                                            statusLabel = '❌ No Match';
                                            statusClass = 'eom-import-status-no';
                                            break;
                                        case 'skipped':
                                            statusLabel = '⏭️ Skipped';
                                            statusClass = 'eom-import-status-skip';
                                            break;
                                        default:
                                            statusLabel = d.status;
                                    }
                                    detailHtml += '<tr class="' + statusClass + '">' +
                                        '<td>' + d.row + '</td>' +
                                        '<td>' + statusLabel + '</td>' +
                                        '<td>' + (d.name || '—') + '</td>' +
                                        '<td>' + (d.phone || '—') + '</td>' +
                                        '<td>' + (d.tracking || '—') + '</td>' +
                                        '<td>' + (d.order_id ? '<a href="' + ajaxurl.replace('admin-ajax.php', 'post.php?post=' + d.order_id + '&action=edit') + '" target="_blank">#' + d.order_id + '</a>' : d.reason || '—') + '</td>' +
                                        '</tr>';
                                });

                                detailHtml += '</tbody></table></details>';
                                $results.append(detailHtml);
                            }

                            EOM.showToast(response.data.message, 'success');

                            // Reload the DataTable to reflect updated data.
                            if (EOM.ordersTable) {
                                EOM.ordersTable.ajax.reload(null, false);
                            }
                        } else {
                            var errMsg = response.data && response.data.message ? response.data.message : 'Import failed.';
                            $results.html(
                                '<div class="eom-import-results-error">' +
                                '<span class="eom-import-result-icon">❌</span>' +
                                '<div>' + errMsg + '</div>' +
                                '</div>'
                            ).show();
                            EOM.showToast(errMsg, 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        $spinner.hide();
                        $btn.prop('disabled', false);
                        var errMsg = 'Server error: ' + textStatus + '. ' + errorThrown;
                        $results.html(
                            '<div class="eom-import-results-error">' +
                            '<span class="eom-import-result-icon">❌</span>' +
                            '<div>' + errMsg + '</div>' +
                            '</div>'
                        ).show();
                        EOM.showToast(errMsg, 'error');
                    }
                });
            });
        },

        showToast: function(message, type) {
            type = type || 'info';
            var $toast = $('<div class="eom-toast eom-toast-' + type + '">' + message + '</div>');
            $('body').append($toast);
            setTimeout(function() {
                $toast.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }
    };

    $(document).ready(function() {
        EOM.init();
    });

})(jQuery);
