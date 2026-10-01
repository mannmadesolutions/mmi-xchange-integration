<?php
/**
 * Orders tab — merged 2026-08-28 from the former separate Orders + Place
 * Order tabs. Four sections, top to bottom:
 *  1. Fulfillment Queue — WC orders containing an XChange-sourced product,
 *     enriched with Xchange PO fields (license/auth/CCSA) where fulfilled.
 *     The actionable, day-to-day view.
 *  2. Find Unlinked Orders — backfills the PO link on orders fulfilled by
 *     hand on the XChange portal before this tab's manual-sync UI existed
 *     (and so never got _mmi_xchange_fulfilled_po written at all). See
 *     MMI_Xchange_Po_Linker.
 *  3. Place a Live PO — standalone B2B PO placement tool + the
 *     Finalize-Timing Diagnostic, unchanged.
 *  4. Xchange PO History — the former Orders tab's full PO search/browse,
 *     for POs with no matching WC order (manually placed, orphaned, or
 *     simply outside the 90-day fulfillment-queue window).
 *
 * Columns in the Fulfillment Queue table carry data-source="wc"/"xchange"
 * so WooCommerce-derived and Xchange-derived fields are visually
 * distinguishable, not just adjacently listed — see admin-xchange.css.
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
<!-- ══ 1. Fulfillment Queue ═══════════════════════════════════════════════ -->
<div class="mmi-xchange-card mmi-flat-card">
    <h3><?php esc_html_e( '📋 Fulfillment Queue', 'mmi-xchange-integration' ); ?></h3>
    <p class="description"><?php esc_html_e( 'WooCommerce orders containing an XChange-sourced product (last 90 days). Click a row to expand it and check live XChange pricing/margin — a "Fulfill" button appears right there in that row\'s own detail header to place the order live via the API (or use the row\'s own manual-sync fields to record one you already placed on the portal yourself). Columns are grouped: WooCommerce order data on the left, Xchange PO data (once fulfilled) on the right; a colored bar on the left edge of each row shows its fulfillment status at a glance.', 'mmi-xchange-integration' ); ?></p>

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
    </div>

    <div class="mmi-x-table-scroll mmi-x-table-scroll--compact">
        <table class="mmi-x-table mmi-x-table-fixed" id="mmi-x-po-orders-table">
            <?php
            /* table-layout:fixed sources column widths from the table's own
               first <tr> — but this table's first row is the colspan'd
               WooCommerce/Xchange group header below, whose cells carry no
               width at all. Without an explicit <colgroup>, which row of th
               (if any) a browser falls back to for per-column widths is
               inconsistent — and critically, a later JS resize that only
               updates the SECOND row's <th> width isn't guaranteed to move
               the actual rendered column. A <colgroup> sets column widths
               unambiguously regardless of which row's cells a browser
               would otherwise consult, so JS resize targets these <col>
               elements instead (see initColumnResize() in admin-xchange.js). */
            ?>
            <colgroup>
                <col data-resize-col="order" class="mmi-x-col-order">
                <col data-resize-col="source" class="mmi-x-col-source">
                <col data-resize-col="date" class="mmi-x-col-date">
                <col data-resize-col="status" class="mmi-x-col-status">
                <col data-resize-col="customer" class="mmi-x-col-customer">
                <col data-resize-col="item" class="mmi-x-col-item">
                <col data-resize-col="total" class="mmi-x-col-total">
                <col data-resize-col="xpo" class="mmi-x-col-xpo">
                <col data-resize-col="xlicense" class="mmi-x-col-xlicense">
                <col data-resize-col="xauth" class="mmi-x-col-xauth">
                <col data-resize-col="xccsa" class="mmi-x-col-xccsa">
            </colgroup>
            <thead>
                <tr class="mmi-x-thead-group-row">
                    <th colspan="7" data-source="wc"><?php esc_html_e( 'WooCommerce', 'mmi-xchange-integration' ); ?></th>
                    <th colspan="4" data-source="xchange"><?php esc_html_e( 'Xchange', 'mmi-xchange-integration' ); ?></th>
                </tr>
                <tr>
                    <th data-col="order_number" data-resize-col="order" data-source="wc"><?php esc_html_e( 'Order', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="source_label" data-resize-col="source" data-source="wc" title="<?php esc_attr_e( 'Marketplace this order came from, and whether it needs a real buyer email confirmed before it can be reliably emailed.', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'Source', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="date" data-resize-col="date" data-source="wc"><?php esc_html_e( 'Date', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="status" data-resize-col="status" data-source="wc"><?php esc_html_e( 'WC Status', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="customer_name" data-resize-col="customer" data-source="wc"><?php esc_html_e( 'Customer', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="item" data-resize-col="item" data-source="wc"><?php esc_html_e( 'Item', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="total" data-resize-col="total" data-source="wc"><?php esc_html_e( 'Order Total', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="fulfilled_po" data-resize-col="xpo" data-source="xchange"><?php esc_html_e( 'PO #', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="xchange_license" data-resize-col="xlicense" data-source="xchange"><?php esc_html_e( 'License', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="xchange_auth" data-resize-col="xauth" data-source="xchange"><?php esc_html_e( 'Auth #', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>
                    <th data-col="xchange_in_ccsa" data-resize-col="xccsa" data-source="xchange" title="<?php esc_attr_e( 'Flags orders still sitting in XChange\'s temporary Customer Committed Stock Area staging, before their status moves on. Only ever refreshed by a manual Full Sync — see the Xchange PO History section below.', 'mmi-xchange-integration' ); ?>"><?php esc_html_e( 'CCSA', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody id="mmi-x-po-orders-tbody">
                <tr><td colspan="11" class="mmi-x-empty mmi-x-empty--loading"><span class="mmi-loading"></span><span><?php esc_html_e( 'Loading…', 'mmi-xchange-integration' ); ?></span></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ 2. Find Unlinked Orders — built on the shared .mmi-collapsible-section
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

<!-- ══ 3. Place a Live PO ═════════════════════════════════════════════════ -->
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

<!-- Built on the shared MMIModal component
     (includes/mmi-shared/assets/js/shared/mmi-modal.js). -->
<div class="mmi-modal-backdrop" id="mmi-x-po-email-panel" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-x-po-email-title">
    <div class="mmi-modal mmi-modal--large">
        <div class="mmi-modal-header">
            <h3 id="mmi-x-po-email-title"><?php esc_html_e( '✉️ Email Customer', 'mmi-xchange-integration' ); ?></h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-xchange-integration' ); ?>">&times;</button>
        </div>
        <div class="mmi-modal-body">
            <p class="description"><?php esc_html_e( 'Send the customer a branded HTML email with their order details, support contact, download link, and license key — a copy also goes to the site admin. License/download/support are fetched automatically from XChange (REST API, then CCSA and Invoice History as needed) — this can end any XChange.com session open in a browser. XChange stops exposing a download/support URL once an order has settled, so for an older order this may fall back to the vendor\'s last-known default instead (flagged below when it does) — verify it, or paste the real value from XChange\'s own confirmation page.', 'mmi-xchange-integration' ); ?></p>
            <div id="mmi-x-po-email-context" class="mmi-badge success mmi-hidden"></div>
            <!-- States plainly whether clicking Send Email below will also
                 link this order to the PO — set by revealEmailPanel()'s
                 linkOnSend param (admin-xchange.js), which itself comes
                 from which of the row's 3 buttons opened this modal
                 (Link & Email → true, Email Only → false). Exists
                 specifically so this modal is never ambiguous about what
                 Send actually does, per the split of "Sync →" into
                 separate Link/Email/both actions. -->
            <div id="mmi-x-po-email-link-note" class="mmi-badge mmi-hidden"></div>

            <div id="mmi-x-po-email-loading" class="mmi-x-loading-banner mmi-hidden">
                <span class="mmi-loading"></span>
                <span id="mmi-x-po-email-loading-text"><?php esc_html_e( 'Loading catalog data and checking XChange for license/download/support (may include a CCSA/Invoice History check, which can end an XChange.com browser session)…', 'mmi-xchange-integration' ); ?></span>
            </div>

            <input type="hidden" id="mmi-x-po-email-po-number" />
            <input type="hidden" id="mmi-x-po-email-order-id" />
            <input type="hidden" id="mmi-x-po-email-link-on-send" value="1" />
            <input type="hidden" id="mmi-x-po-email-auth" />
            <input type="hidden" id="mmi-x-po-email-sku" />
            <input type="hidden" id="mmi-x-po-email-code" />
            <input type="hidden" id="mmi-x-po-email-our-sku" />
            <input type="hidden" id="mmi-x-po-email-product-id" />
            <input type="hidden" id="mmi-x-po-email-vendor" />
            <input type="hidden" id="mmi-x-po-email-price" />
            <input type="hidden" id="mmi-x-po-email-currency" />

            <div id="mmi-x-po-email-summary" class="mmi-x-preview-panel mmi-hidden"></div>

            <div class="mmi-label-grid mmi-label-grid--start mmi-x-field-grid">
                <div class="mmi-x-field-row mmi-label-grid-row">
                    <label for="mmi-x-po-email-to"><?php esc_html_e( 'Customer Email', 'mmi-xchange-integration' ); ?></label>
                    <input type="email" id="mmi-x-po-email-to" class="mmi-x-input" placeholder="customer@example.com" />
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row">
                    <label for="mmi-x-po-email-name"><?php esc_html_e( 'Customer Name', 'mmi-xchange-integration' ); ?></label>
                    <input type="text" id="mmi-x-po-email-name" class="mmi-x-input" placeholder="Jamie" />
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row mmi-x-field-row--auto-fetch">
                    <label for="mmi-x-po-email-software"><?php esc_html_e( 'Software Name', 'mmi-xchange-integration' ); ?></label>
                    <div class="mmi-x-field-input-wrap">
                        <input type="text" id="mmi-x-po-email-software" class="mmi-x-input" placeholder="(auto-detected from SKU)" />
                        <span class="mmi-loading mmi-hidden mmi-x-field-loading"></span>
                    </div>
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row mmi-x-field-row--auto-fetch">
                    <label for="mmi-x-po-email-logo"><?php esc_html_e( 'Software Logo URL', 'mmi-xchange-integration' ); ?></label>
                    <div class="mmi-x-field-input-wrap">
                        <input type="url" id="mmi-x-po-email-logo" class="mmi-x-input" placeholder="(auto-detected from product image)" />
                        <span class="mmi-loading mmi-hidden mmi-x-field-loading"></span>
                    </div>
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row mmi-x-field-row--auto-fetch">
                    <label for="mmi-x-po-email-license"><?php esc_html_e( 'License Key', 'mmi-xchange-integration' ); ?></label>
                    <div class="mmi-x-field-input-wrap">
                        <input type="text" id="mmi-x-po-email-license" class="mmi-x-input" placeholder="(auto-fetched via API, or paste from XChange manually)" />
                        <span class="mmi-loading mmi-hidden mmi-x-field-loading"></span>
                    </div>
                </div>
                <!-- Set by setFallbackDocNote() (admin-xchange.js) whenever
                     fetch_order_document() had to fall back to this vendor's
                     own last-known-good download/support URL instead of a
                     value confirmed for this specific PO — see
                     MMI_Xchange_Order_Sync::apply_vendor_fallback_docs(). -->
                <div id="mmi-x-po-email-fallback-note" class="mmi-badge mmi-hidden"></div>
                <div class="mmi-x-field-row mmi-label-grid-row mmi-x-field-row--auto-fetch">
                    <label for="mmi-x-po-email-download"><?php esc_html_e( 'Download URL', 'mmi-xchange-integration' ); ?></label>
                    <div class="mmi-x-field-input-wrap">
                        <input type="url" id="mmi-x-po-email-download" class="mmi-x-input" placeholder="(auto-fetched via API, or paste from XChange manually)" />
                        <span class="mmi-loading mmi-hidden mmi-x-field-loading"></span>
                    </div>
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row mmi-x-field-row--auto-fetch">
                    <label for="mmi-x-po-email-support"><?php esc_html_e( 'Support URL', 'mmi-xchange-integration' ); ?></label>
                    <div class="mmi-x-field-input-wrap">
                        <input type="url" id="mmi-x-po-email-support" class="mmi-x-input" placeholder="(auto-fetched via API, or paste from XChange manually)" />
                        <span class="mmi-loading mmi-hidden mmi-x-field-loading"></span>
                    </div>
                </div>
            </div>

            <button type="button" id="mmi-x-po-email-send" class="button button-primary">✉️ <?php esc_html_e( 'Send Email', 'mmi-xchange-integration' ); ?></button>
            <button type="button" id="mmi-x-po-email-test" class="button" title="<?php esc_attr_e( 'Sends this exact email to the site admin address only, for a content/rendering preview — does not notify the customer or mark anything fulfilled.', 'mmi-xchange-integration' ); ?>">🧪 <?php esc_html_e( 'Test Email', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-po-email-spinner"></span>

            <div id="mmi-x-po-email-result" class="mmi-x-status"></div>
        </div>
    </div>
</div>

<!-- Combined fulfillment — one email for every item in a customer group.
     Built on the shared MMIModal component; items rendered by
     BundleFulfillment.renderItems() (admin-xchange.js). -->
<div class="mmi-modal-backdrop" id="mmi-x-bundle-panel" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-x-bundle-title">
    <div class="mmi-modal mmi-modal--large">
        <div class="mmi-modal-header">
            <h3 id="mmi-x-bundle-title"><?php esc_html_e( '📦 Combined Fulfillment Email', 'mmi-xchange-integration' ); ?></h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-xchange-integration' ); ?>">&times;</button>
        </div>
        <div class="mmi-modal-body">
            <p class="description"><?php esc_html_e( 'One email to the customer covering every product below. Product names and images come from each SKU\'s WooCommerce product. Licenses returned when the PO was placed are filled in already; for any that are blank, use "Fetch from XChange" (may end an XChange.com browser session) or paste the value. Sending marks every item fulfilled and completes each order once all its XChange items are done.', 'mmi-xchange-integration' ); ?></p>
            <div id="mmi-x-bundle-context" class="mmi-badge success"></div>
            <div id="mmi-x-bundle-relay-note" class="mmi-badge warning mmi-hidden"></div>

            <div class="mmi-label-grid mmi-label-grid--start mmi-x-field-grid">
                <div class="mmi-x-field-row mmi-label-grid-row">
                    <label for="mmi-x-bundle-to"><?php esc_html_e( 'Customer Email', 'mmi-xchange-integration' ); ?></label>
                    <input type="email" id="mmi-x-bundle-to" class="mmi-x-input" placeholder="customer@example.com" />
                </div>
                <div class="mmi-x-field-row mmi-label-grid-row">
                    <label for="mmi-x-bundle-name"><?php esc_html_e( 'Customer First Name', 'mmi-xchange-integration' ); ?></label>
                    <input type="text" id="mmi-x-bundle-name" class="mmi-x-input" placeholder="Jamie" />
                </div>
            </div>

            <div id="mmi-x-bundle-items" class="mmi-x-bundle-items"></div>

            <button type="button" id="mmi-x-bundle-send" class="button button-primary">✉️ <?php esc_html_e( 'Send Combined Email', 'mmi-xchange-integration' ); ?></button>
            <button type="button" id="mmi-x-bundle-test" class="button" title="<?php esc_attr_e( 'Sends this exact email to the site admin address only — does not notify the customer or mark anything fulfilled.', 'mmi-xchange-integration' ); ?>">🧪 <?php esc_html_e( 'Test Email', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-bundle-spinner"></span>

            <div id="mmi-x-bundle-result" class="mmi-x-status"></div>
        </div>
    </div>
</div>

<!-- ══ 4. Xchange PO History — built on the shared .mmi-collapsible-section
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

<!-- ── Order Detail Drawer — built on the shared MMIModal component
     (includes/mmi-shared/assets/js/shared/mmi-modal.js). ────────────────── -->
<div class="mmi-modal-backdrop" id="mmi-x-order-detail" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-x-order-detail-title">
    <div class="mmi-modal mmi-modal--large">
        <div class="mmi-modal-header">
            <h3 id="mmi-x-order-detail-title"><?php esc_html_e( 'Order Detail', 'mmi-xchange-integration' ); ?></h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-xchange-integration' ); ?>">&times;</button>
        </div>
        <div class="mmi-modal-body" id="mmi-x-order-detail-body"></div>
    </div>
</div>

<!-- ── Price History Modal ─────────────────────────────────────────────
     Opened from the "📈 Price History" button in both the Recent Orders
     detail table's product cell and the standalone Preview panel (see
     admin-xchange-price-history.js). Built on the shared MMIModal
     component (includes/mmi-shared/assets/js/shared/mmi-modal.js) — its
     markup contract is documented at the top of that file. -->
<div class="mmi-modal-backdrop" id="mmi-x-price-history-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-x-price-history-title">
    <div class="mmi-modal mmi-modal--large">
        <div class="mmi-modal-header">
            <h3 id="mmi-x-price-history-title"><?php esc_html_e( 'Price History', 'mmi-xchange-integration' ); ?></h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-xchange-integration' ); ?>">&times;</button>
        </div>
        <div class="mmi-modal-body">
            <p id="mmi-x-price-history-status" class="mmi-x-timestamp"></p>
            <canvas id="mmi-x-price-history-canvas" height="90"></canvas>
        </div>
    </div>
</div>
