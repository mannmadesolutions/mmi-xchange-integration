<?php
/**
 * Tools tab — the Orders tab's occasional tools, moved here 2026-10-05 so
 * the Orders tab is only the day-to-day Fulfillment Queue:
 *  1. Find Unlinked Orders — backfills the PO link on orders fulfilled by
 *     hand on the XChange portal (MMI_Xchange_Po_Linker).
 *  2. Place a Live PO — standalone B2B PO placement + the Finalize-Timing
 *     Diagnostic.
 *  3. Xchange PO History — full PO search/browse, for POs with no matching
 *     WC order or outside the queue's 90-day window.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sync_state          = MMI_Xchange_Order_Sync::get_sync_state();
$full_sync_state     = MMI_Xchange_Order_Sync::get_full_sync_state();
$webhooks_registered = class_exists( 'MMI_Xchange_Webhooks' ) && MMI_Xchange_Webhooks::is_registered( 'purchase_order_updates' );
$webhook_last_event  = MMI_Settings::get( 'mmi_xchange_webhook_last_received' );
?>
<!-- ══ 1. Find Unlinked Orders — built on the shared .mmi-collapsible-section
     behavior (mmi-suite-common.css), plus the shared .mmi-flat-card look
     (matches every other section on this tab — see admin-xchange.css).
     ══════════════════════════════════════════════════════════════════════ -->
<div class="mmi-xchange-card mmi-flat-card mmi-collapsible-section collapsed" id="mmi-x-po-linker-section">
    <div class="mmi-section-header mmi-x-section-clickable" id="mmi-x-po-linker-toggle">
        <div>
            <h3>
                <?php esc_html_e( '🔗 Find Unlinked Orders', 'mmi-xchange-integration' ); ?>
                <span class="mmi-collapse-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
            </h3>
            <p class="mmi-section-description"><?php esc_html_e( 'Backfills orders fulfilled by hand on the XChange portal before this tab existed. Matches each unlinked order against XChange\'s own synced PO history by reference number (the WooCommerce or Reverb order # you\'d have typed into XChange\'s "PO Number" field at checkout) — it never links or emails anything itself; every match still opens the same Email Customer panel a manual sync always has, for you to review and send.', 'mmi-xchange-integration' ); ?></p>
        </div>
    </div>

    <div id="mmi-x-po-linker-body" class="mmi-section-content">
        <div class="mmi-toolbar">
            <button type="button" id="mmi-x-po-linker-scan-btn" class="button button-primary">🔍 <?php esc_html_e( 'Scan for Matches', 'mmi-xchange-integration' ); ?></button>
            <button type="button" id="mmi-x-po-linker-scan-more-btn" class="button mmi-hidden">↺ <?php esc_html_e( 'Scan Next Batch', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-po-linker-spinner"></span>
        </div>

        <div id="mmi-x-po-linker-status" class="mmi-x-status"></div>

        <div class="mmi-x-table-scroll mmi-x-table-scroll--compact">
            <table class="mmi-x-table" id="mmi-x-po-linker-table">
                <thead>
                    <tr>
                        <th data-col="order_number"><?php esc_html_e( 'Order', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                        <th data-col="date"><?php esc_html_e( 'Date', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                        <th data-col="customer_name"><?php esc_html_e( 'Customer', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                        <th><?php esc_html_e( 'Item', 'mmi-xchange-integration' ); ?></th>
                        <th data-col="matched_po"><?php esc_html_e( 'Matched PO', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                        <th><?php esc_html_e( 'Basis', 'mmi-xchange-integration' ); ?></th>
                        <th data-col="confidence"><?php esc_html_e( 'Confidence', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                        <th><?php esc_html_e( 'Action', 'mmi-xchange-integration' ); ?></th>
                    </tr>
                </thead>
                <tbody id="mmi-x-po-linker-tbody">
                    <tr><td colspan="8" class="mmi-x-empty"><?php esc_html_e( 'Click "Scan for Matches" to search XChange\'s PO history for orders never linked to a fulfillment PO.', 'mmi-xchange-integration' ); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ 2. Place a Live PO ═════════════════════════════════════════════════ -->
<div class="mmi-xchange-card mmi-flat-card mmi-x-card-narrow">
    <h3><?php esc_html_e( '🛒 Place a Live PO', 'mmi-xchange-integration' ); ?></h3>
    <p class="description"><?php esc_html_e( 'Places a live B2B purchase order directly on XChange. This charges your XChange account.', 'mmi-xchange-integration' ); ?></p>

    <div class="mmi-label-grid mmi-label-grid--start mmi-x-field-grid">
        <div class="mmi-x-field-row mmi-label-grid-row">
            <label for="mmi-x-po-sku"><?php esc_html_e( 'XChange SKU', 'mmi-xchange-integration' ); ?></label>
            <div class="mmi-label-grid-value">
                <input type="text" id="mmi-x-po-sku" class="mmi-x-input" placeholder="1035-2920" />
                <button type="button" id="mmi-x-po-preview-btn" class="button button-small">🔍 <?php esc_html_e( 'Preview', 'mmi-xchange-integration' ); ?></button>
                <span class="mmi-loading mmi-hidden" id="mmi-x-po-preview-spinner"></span>
            </div>
        </div>
        <div class="mmi-x-field-row mmi-label-grid-row">
            <label for="mmi-x-po-qty"><?php esc_html_e( 'Quantity', 'mmi-xchange-integration' ); ?></label>
            <input type="number" id="mmi-x-po-qty" class="mmi-x-input mmi-x-input-narrow" value="1" min="1" />
        </div>
    </div>

    <div id="mmi-x-po-preview" class="mmi-x-preview-panel mmi-hidden"></div>

    <button type="button" id="mmi-x-po-submit" class="button button-primary">🛒 <?php esc_html_e( 'Place Order', 'mmi-xchange-integration' ); ?></button>
    <span class="mmi-loading mmi-hidden" id="mmi-x-po-spinner"></span>

    <div id="mmi-x-po-result" class="mmi-x-status"></div>

    <div class="mmi-x-diagnostic">
        <h4><?php esc_html_e( 'Finalize-Timing Diagnostic', 'mmi-xchange-integration' ); ?></h4>
        <p class="description"><?php esc_html_e( 'Runs a real reserve/finalize round-trip against XChange\'s own test vendor (engine=test) through a throwaway WooCommerce order, to verify the checkout finalize-timing hooks are wired correctly. The test order is trashed automatically when done.', 'mmi-xchange-integration' ); ?></p>

        <button type="button" id="mmi-x-diag-success-btn" class="button">✅ <?php esc_html_e( 'Run Success-Path Test', 'mmi-xchange-integration' ); ?></button>
        <button type="button" id="mmi-x-diag-failure-btn" class="button">🛑 <?php esc_html_e( 'Run Failure-Path Test', 'mmi-xchange-integration' ); ?></button>
        <span class="mmi-loading mmi-hidden" id="mmi-x-diag-spinner"></span>

        <div id="mmi-x-diag-result" class="mmi-x-status"></div>
    </div>
</div>

<!-- ══ 3. Xchange PO History — built on the shared .mmi-collapsible-section
     behavior (mmi-suite-common.css) for its collapse mechanics, plus the
     shared .mmi-flat-card look so it still matches its "1."/"2."/"3."
     siblings on this same tab (see admin-xchange.css). ══════════════════ -->
<div class="mmi-xchange-card mmi-flat-card mmi-collapsible-section collapsed" id="mmi-x-orders-history-section">
    <div class="mmi-section-header mmi-x-section-clickable" id="mmi-x-orders-history-toggle">
        <div>
            <h3>
                <?php esc_html_e( 'Xchange PO History', 'mmi-xchange-integration' ); ?>
                <span class="mmi-collapse-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
            </h3>
            <p class="mmi-section-description"><?php esc_html_e( 'Search XChange\'s own full PO record directly — covers purchase orders with no matching WooCommerce order (manually placed on the portal, or outside the Fulfillment Queue\'s 90-day window).', 'mmi-xchange-integration' ); ?></p>
        </div>
    </div>

    <div id="mmi-x-orders-history-body" class="mmi-section-content">
        <p class="description">
            <?php esc_html_e( '"Refresh" is fast and safe — it only calls the XChange REST API, never the web portal.', 'mmi-xchange-integration' ); ?>
            <?php esc_html_e( '"Full Sync…" pulls complete historical order data (POs going back years) but logs into the XChange web portal to do it, which will end any XChange.com session you have open elsewhere — use it deliberately, e.g. when you\'re not actively using the portal.', 'mmi-xchange-integration' ); ?>
        </p>
        <div class="mmi-toolbar">
            <input type="text" id="mmi-x-orders-search" class="mmi-x-input" placeholder="<?php esc_attr_e( 'Filter by PO or SKU…', 'mmi-xchange-integration' ); ?>" />
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Product', 'mmi-xchange-integration' ); ?>
                <input type="text" id="mmi-x-orders-product-filter" class="mmi-x-input" list="mmi-x-orders-product-list" placeholder="<?php esc_attr_e( 'All products…', 'mmi-xchange-integration' ); ?>" />
                <datalist id="mmi-x-orders-product-list"></datalist>
            </label>
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Vendor', 'mmi-xchange-integration' ); ?>
                <select id="mmi-x-orders-vendor-filter" class="mmi-x-select">
                    <option value=""><?php esc_html_e( 'All vendors', 'mmi-xchange-integration' ); ?></option>
                </select>
            </label>
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Price', 'mmi-xchange-integration' ); ?>
                <input type="number" id="mmi-x-orders-price-min" class="mmi-x-input mmi-x-input-narrow" placeholder="<?php esc_attr_e( 'Min', 'mmi-xchange-integration' ); ?>" min="0" step="0.01" />
                <?php esc_html_e( 'to', 'mmi-xchange-integration' ); ?>
                <input type="number" id="mmi-x-orders-price-max" class="mmi-x-input mmi-x-input-narrow" placeholder="<?php esc_attr_e( 'Max', 'mmi-xchange-integration' ); ?>" min="0" step="0.01" />
            </label>
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Date range', 'mmi-xchange-integration' ); ?>
                <select id="mmi-x-orders-date-range" class="mmi-x-select">
                    <option value="7"><?php esc_html_e( 'Last 7 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="30"><?php esc_html_e( 'Last 30 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="90"><?php esc_html_e( 'Last 90 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="0" selected><?php esc_html_e( 'All time', 'mmi-xchange-integration' ); ?></option>
                </select>
            </label>
            <span class="mmi-x-sync-meta">
                <?php esc_html_e( 'Last synced (REST):', 'mmi-xchange-integration' ); ?>
                <strong id="mmi-x-orders-synced-at"><?php echo esc_html( $sync_state['synced_at'] ?? __( 'never', 'mmi-xchange-integration' ) ); ?></strong>
                (<span id="mmi-x-orders-count"><?php echo (int) ( $sync_state['order_count'] ?? 0 ); ?></span> <?php esc_html_e( 'orders', 'mmi-xchange-integration' ); ?>)
            </span>
            <span class="mmi-x-sync-meta">
                <?php esc_html_e( 'Last confirmed via Full Sync:', 'mmi-xchange-integration' ); ?>
                <strong id="mmi-x-orders-full-synced-at"><?php echo esc_html( $full_sync_state['synced_at'] ?? __( 'never', 'mmi-xchange-integration' ) ); ?></strong>
                <span class="description">
                    <?php esc_html_e( '— the only thing that can update CCSA/Invoice-History location. This is a manual action; there\'s no "expected" interval to compare it against.', 'mmi-xchange-integration' ); ?>
                </span>
            </span>
            <button type="button" id="mmi-x-orders-refresh" class="button">↺ <?php esc_html_e( 'Refresh', 'mmi-xchange-integration' ); ?></button>
            <button type="button" id="mmi-x-orders-full-sync" class="button" title="<?php esc_attr_e( 'Logs into the XChange web portal for complete order history. Will end any active XChange.com browser session.', 'mmi-xchange-integration' ); ?>">⚠️ <?php esc_html_e( 'Full Sync…', 'mmi-xchange-integration' ); ?></button>
        </div>

        <div class="mmi-toolbar">
            <span class="mmi-x-sync-meta">
                <?php esc_html_e( 'Webhooks (purchase_order_updates / shipment_updates):', 'mmi-xchange-integration' ); ?>
                <?php if ( $webhooks_registered ) : ?>
                    <span class="mmi-badge success"><?php esc_html_e( 'Registered', 'mmi-xchange-integration' ); ?></span>
                <?php else : ?>
                    <span class="mmi-badge"><?php esc_html_e( 'Not registered', 'mmi-xchange-integration' ); ?></span>
                <?php endif; ?>
                <?php esc_html_e( 'Last event received:', 'mmi-xchange-integration' ); ?>
                <strong><?php echo esc_html( $webhook_last_event ?: __( 'never', 'mmi-xchange-integration' ) ); ?></strong>
            </span>
            <button type="button" id="mmi-x-register-webhooks" class="button" title="<?php esc_attr_e( 'Registers this site\'s REST endpoint with XChange for order-update push notifications — REST-only, never touches the web portal. Opens a new public (HMAC-verified) endpoint on this server.', 'mmi-xchange-integration' ); ?>">🔔 <?php esc_html_e( 'Register Webhooks', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-register-webhooks-spinner"></span>
        </div>

        <div id="mmi-x-orders-full-sync-progress" class="mmi-x-progress-wrap mmi-hidden">
            <div class="mmi-x-progress-bar"><div class="mmi-x-progress-fill" id="mmi-x-orders-full-sync-fill"></div></div>
            <span id="mmi-x-orders-full-sync-step" class="mmi-x-timestamp"></span>
        </div>

        <div class="mmi-x-table-scroll">
            <table class="mmi-x-table mmi-x-table-fixed" id="mmi-x-orders-table">
                <thead>
                    <tr>
                        <th data-col="po" data-resize-col="po" class="mmi-x-th-po"><?php esc_html_e( 'PO', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="date" data-resize-col="date" class="mmi-x-th-date"><?php esc_html_e( 'Date', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="sku" data-resize-col="sku" class="mmi-x-th-sku"><?php esc_html_e( 'SKU', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="product" data-resize-col="product" class="mmi-x-th-product"><?php esc_html_e( 'Product', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="price" data-resize-col="price" class="mmi-x-th-price" title="<?php esc_attr_e( 'Dealer cost — Xchange\'s own \"PRICE\" catalog field for this SKU (falls back to the price paid on this specific order if the SKU is no longer in the active catalog).', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'Price', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="map" data-resize-col="map" class="mmi-x-th-map" title="<?php esc_attr_e( 'MAP — Xchange\'s Minimum Advertised Price for this SKU.', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'MAP', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="vendor_name" data-resize-col="vendor" class="mmi-x-th-vendor"><?php esc_html_e( 'Vendor', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="license_key" data-resize-col="license" class="mmi-x-th-license"><?php esc_html_e( 'License', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="auth" data-resize-col="auth" class="mmi-x-th-auth"><?php esc_html_e( 'Auth #', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                        <th data-col="in_ccsa" data-resize-col="ccsa" class="mmi-x-th-ccsa" title="<?php esc_attr_e( 'Flags orders still sitting in XChange\'s temporary Customer Committed Stock Area staging, before their status moves on.', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'CCSA', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="mmi-x-orders-tbody">
                    <tr><td colspan="10" class="mmi-x-empty mmi-x-empty--loading"><span class="mmi-loading"></span><span><?php esc_html_e( 'Loading…', 'mmi-xchange-integration' ); ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials-fulfillment-modals.php'; ?>
