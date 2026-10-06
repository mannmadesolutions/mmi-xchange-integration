<?php
/**
 * Dialogs shared by the Orders and Tools tabs: Email Customer (single and
 * combined), Order Detail and Price History. Both tabs open the email
 * window (Fulfill / Find Unlinked Orders), so it lives here once.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
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
