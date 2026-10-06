<?php
/**
 * Orders tab — the Fulfillment Queue only: WooCommerce orders containing an
 * XChange-sourced product. Each row's "Next step" says what it needs, and
 * its expanded detail shows every step (payment → purchase → license →
 * email → completed → Reverb) as recorded, updating live while an
 * automated step runs (MMI_Xchange_Fulfillment_Progress).
 *
 * The rarely used tools (Find Unlinked Orders, Place a Live PO, PO History)
 * moved to the Tools tab (tab-tools.php) on 2026-10-05.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="mmi-process-section" id="mmi-x-queue-section">
    <div class="mmi-section-header">
        <div>
            <h3 class="mmi-process-section-header"><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Fulfillment Queue', 'mmi-xchange-integration' ); ?></h3>
            <p class="mmi-process-section-description"><?php esc_html_e( 'WooCommerce orders with an XChange product, last 90 days. "Next step" says what each order needs; click a row to see every step as it happens, the margin, and the customer emails.', 'mmi-xchange-integration' ); ?></p>
        </div>
    </div>
    <div class="mmi-section-content">
        <div id="mmi-x-po-queue-status" class="mmi-x-status"></div>

        <!-- Same customer, 2+ unfulfilled software items across one or more
             open orders — rendered by BundleFulfillment.renderGroups()
             (admin-xchange.js) from each row's customer_key (see
             MMI_Software_Fulfillment::customer_key()). -->
        <div id="mmi-x-bundle-groups" class="mmi-x-bundle-groups" hidden></div>

        <div class="mmi-toolbar">
            <input type="text" id="mmi-x-po-orders-search" class="mmi-x-input" placeholder="<?php esc_attr_e( 'Filter by order #, SKU, or customer…', 'mmi-xchange-integration' ); ?>" />
            <label class="mmi-x-inline-label">
                <input type="checkbox" id="mmi-x-po-orders-unfulfilled-only" />
                <?php esc_html_e( 'Unfulfilled only', 'mmi-xchange-integration' ); ?>
            </label>
            <button type="button" id="mmi-x-po-orders-refresh" class="button">↺ <?php esc_html_e( 'Refresh', 'mmi-xchange-integration' ); ?></button>
            <span id="mmi-x-po-live" class="mmi-x-live" hidden></span>
        </div>

        <div class="mmi-x-table-scroll mmi-x-table-scroll--queue">
            <table class="mmi-x-table mmi-x-table-fixed" id="mmi-x-po-orders-table">
                <?php
                /* table-layout:fixed takes column widths from the first row,
                   which here is the colspan'd group header — so widths live
                   on a <colgroup>, and initColumnResize() (admin-xchange.js)
                   resizes these <col>s. */
                ?>
                <colgroup>
                    <col data-resize-col="order" class="mmi-x-col-order">
                    <col data-resize-col="date" class="mmi-x-col-date">
                    <col data-resize-col="customer" class="mmi-x-col-customer">
                    <col data-resize-col="item" class="mmi-x-col-item">
                    <col data-resize-col="total" class="mmi-x-col-total">
                    <col data-resize-col="next" class="mmi-x-col-next">
                    <col data-resize-col="xpo" class="mmi-x-col-xpo">
                    <col data-resize-col="xlicense" class="mmi-x-col-xlicense">
                </colgroup>
                <thead>
                    <tr class="mmi-x-thead-group-row">
                        <th colspan="5" data-source="wc"><?php esc_html_e( 'WooCommerce', 'mmi-xchange-integration' ); ?></th>
                        <th data-source="next"><?php esc_html_e( 'Progress', 'mmi-xchange-integration' ); ?></th>
                        <th colspan="2" data-source="xchange"><?php esc_html_e( 'Xchange', 'mmi-xchange-integration' ); ?></th>
                    </tr>
                    <tr>
                        <th data-col="order_id" data-resize-col="order" data-source="wc"><?php esc_html_e( 'Order', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="date" data-resize-col="date" data-source="wc"><?php esc_html_e( 'Date', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="customer_name" data-resize-col="customer" data-source="wc"><?php esc_html_e( 'Customer', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="item" data-resize-col="item" data-source="wc"><?php esc_html_e( 'Item', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="total" data-resize-col="total" data-source="wc"><?php esc_html_e( 'Total', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="next_rank" data-resize-col="next" data-source="next" title="<?php esc_attr_e( 'What this order needs now. Sorting puts the orders that need you first.', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'Next step', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="fulfilled_po" data-resize-col="xpo" data-source="xchange"><?php esc_html_e( 'PO #', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="xchange_license" data-resize-col="xlicense" data-source="xchange"><?php esc_html_e( 'License', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="mmi-x-po-orders-tbody">
                    <tr><td colspan="8" class="mmi-x-empty mmi-x-empty--loading"><span class="mmi-loading"></span><span><?php esc_html_e( 'Loading…', 'mmi-xchange-integration' ); ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials-fulfillment-modals.php'; ?>
