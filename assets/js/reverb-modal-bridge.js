/* MMI Xchange — Reverb Software Delivery modal bridge.
 * Drives the SKU/Place-Order/PO-Browser block that
 * class-reverb-bridge.php injects into mmi-reverb-integration's modal.
 * Listens for the mmi:sw-modal:open / mmi:sw-modal:close custom events
 * that plugin's admin-orders.js dispatches instead of being called directly.
 */
(function ($) {
    'use strict';

    let allOrders = [];
    let bestMatchPo = null;

    function ajax(action, data) {
        return $.post(mmiXchangeBridge.ajaxUrl, Object.assign({ action: action, nonce: mmiXchangeBridge.nonce }, data));
    }

    // PO rows come from the XChange REST API / CCSA portal scrape — escape
    // before building row markup.
    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function reverbOrderId() {
        return $('#mmi-sw-reverb-order-id').val();
    }

    function fetchOrders(forceRefresh) {
        $('#mmi-sw-po-loading').removeClass('mmi-hidden');
        $('#mmi-sw-po-picker').addClass('mmi-hidden');

        const sku = $('#mmi-sw-xchange-sku').val().trim();

        ajax('mmi_xchange_fetch_orders', { sku: sku, force_refresh: forceRefresh ? 1 : 0 }).done((res) => {
            $('#mmi-sw-po-loading').addClass('mmi-hidden');
            $('#mmi-sw-po-picker').removeClass('mmi-hidden');
            $('#mmi-sw-po-controls').removeClass('mmi-hidden');

            if (!res.success) {
                $('#mmi-sw-po-picker').text(res.data?.message || 'Could not load XChange orders.');
                return;
            }

            allOrders = res.data.orders || [];
            bestMatchPo = res.data.best_match_po;
            $('#mmi-sw-synced-at').text(res.data.synced_at || 'never');
            $('#mmi-sw-order-count').text(`(${allOrders.length} orders)`);

            renderPicker();

            if (bestMatchPo) {
                const match = allOrders.find((o) => o.po === bestMatchPo);
                if (match) {
                    selectPo(match);
                }
            }
        });
    }

    function filterByDate(orders, days) {
        if (!days) return orders;
        const cutoff = new Date();
        cutoff.setDate(cutoff.getDate() - days);
        return orders.filter((o) => {
            const d = new Date(o.date);
            return isNaN(d.getTime()) || d >= cutoff;
        });
    }

    function renderPicker() {
        const days = parseInt($('#mmi-sw-date-range').val(), 10);
        const rows = filterByDate(allOrders, days);
        const $picker = $('#mmi-sw-po-picker').empty();

        if (rows.length === 0) {
            $picker.text('No XChange orders found for this window.');
            return;
        }

        const $table = $('<table class="mmi-x-table"><thead><tr><td>PO</td><td>Date</td><td>SKU</td><td>License</td><td>Source</td></tr></thead></table>');
        const $tbody = $('<tbody>').appendTo($table);

        rows.forEach((o) => {
            const badge = o.sku_match ? ' <strong>(SKU match)</strong>' : (o.po === bestMatchPo ? ' <em>(Suggested)</em>' : '');
            const $tr = $('<tr>').css('cursor', 'pointer').on('click', () => selectPo(o));
            $tr.append(`<td>${escapeHtml(o.po)}${badge}</td><td>${escapeHtml(o.date || '')}</td><td>${escapeHtml(o.sku || '')}</td><td>${escapeHtml((o.license_key || '').slice(0, 14))}</td><td>${escapeHtml(o.source || '')}</td>`);
            $tbody.append($tr);
        });

        $picker.append($table);
    }

    function selectPo(order) {
        $('#mmi-sw-xchange-po').val(order.po);
        $('#mmi-sw-xchange-auth').val(order.auth || '');

        if (order.license_key) {
            $('#mmi-sw-license-key').val(order.license_key);
        } else if (order.source !== 'invoice') {
            fetchLicense(order.po);
        }
    }

    function fetchLicense(po) {
        ajax('mmi_xchange_fetch_license', { po: po }).done((res) => {
            if (res.success && res.data.license_key) {
                $('#mmi-sw-license-key').val(res.data.license_key);
            }
        });
    }

    function placeOrder() {
        const sku = $('#mmi-sw-xchange-sku').val().trim();
        const orderId = reverbOrderId();

        if (!sku) {
            alert('Enter an XChange SKU first.');
            return;
        }
        if (!confirm(`Place a B2B purchase order on XChange for ${sku}?\n\nThis will charge your XChange account.`)) {
            return;
        }

        const $btn = $('#mmi-sw-place-order-btn');
        $btn.prop('disabled', true);

        ajax('mmi_xchange_place_order', { reverb_order_id: orderId, sku: sku }).done((res) => {
            if (!res.success) {
                let msg = res.data?.message || 'Order failed.';
                if (res.data?.manual_url) {
                    msg += ` <a href="${res.data.manual_url}" target="_blank" rel="noopener">Open XChange →</a>`;
                }
                alert(msg.replace(/<[^>]+>/g, ''));
                return;
            }
            $('#mmi-sw-xchange-po').val(res.data.po_number || '');
            $('#mmi-sw-xchange-auth').val(res.data.auth || '');
        }).always(() => {
            $btn.prop('disabled', false);
        });
    }

    $(function () {
        $(document).on('click', '#mmi-sw-place-order-btn', placeOrder);
        $(document).on('click', '#mmi-sw-refresh-btn', () => fetchOrders(true));
        $(document).on('change', '#mmi-sw-date-range', renderPicker);

        document.addEventListener('mmi:sw-modal:open', () => {
            allOrders = [];
            bestMatchPo = null;
            fetchOrders(false);
        });

        document.addEventListener('mmi:sw-modal:close', () => {
            allOrders = [];
            bestMatchPo = null;
        });
    });
})(jQuery);
