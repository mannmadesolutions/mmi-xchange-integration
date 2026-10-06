<?php
/**
 * Vendors tab — vendor/brand directory (the software vendors sold through
 * Xchange), plus the ported CSV export / image-import admin actions.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$import_media_state = MMI_Xchange_Vendors::get_import_media_state();
?>
<div class="mmi-xchange-card mmi-flat-card">
    <h2><?php esc_html_e( 'Image Readiness', 'mmi-xchange-integration' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Computed locally from the last catalog fetch and this site\'s media library — no live Xchange API calls, safe to check any time.', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-status-grid" id="mmi-x-image-stats-grid">
        <div class="mmi-x-status-card"><h3><?php esc_html_e( 'Vendors with products', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-stat-vendors">—</span></div>
        <div class="mmi-x-status-card"><h3><?php esc_html_e( 'Matched products', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-stat-matched">—</span></div>
        <div class="mmi-x-status-card mmi-x-status-card--clickable" id="mmi-x-stat-missing-card" title="<?php esc_attr_e( 'Click to show only vendors missing images', 'mmi-xchange-integration' ); ?>">
            <h3><?php esc_html_e( 'Missing images', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-stat-missing">—</span>
        </div>
        <div class="mmi-x-status-card"><h3><?php esc_html_e( 'Already imported (Xchange)', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-stat-imported">—</span></div>
    </div>
    <p id="mmi-x-image-stats-note" class="mmi-x-timestamp mmi-hidden"></p>
</div>

<div class="mmi-xchange-card mmi-flat-card">
    <div class="mmi-toolbar">
        <input type="text" id="mmi-x-vendors-search" class="mmi-x-input" placeholder="<?php esc_attr_e( 'Filter by vendor name or ID…', 'mmi-xchange-integration' ); ?>" />
        <label class="mmi-x-inline-label">
            <input type="checkbox" id="mmi-x-vendors-missing-only" />
            <?php esc_html_e( 'Missing images only', 'mmi-xchange-integration' ); ?>
        </label>
        <button type="button" id="mmi-x-vendors-refresh" class="button">↺ <?php esc_html_e( 'Refresh', 'mmi-xchange-integration' ); ?></button>
    </div>

    <div class="mmi-x-table-scroll mmi-x-table-scroll--full">
        <table class="mmi-x-table" id="mmi-x-vendors-table">
            <thead>
                <tr>
                    <th data-col="vendor_id"><?php esc_html_e( 'ID', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="name"><?php esc_html_e( 'Vendor', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="sku_count"><?php esc_html_e( 'Products', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="missing_image"><?php esc_html_e( 'Images', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="phone"><?php esc_html_e( 'Main Phone', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="address"><?php esc_html_e( 'Address', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="contact_name"><?php esc_html_e( 'Contact', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="contact_email"><?php esc_html_e( 'Email', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="contact_phone"><?php esc_html_e( 'Phone', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="prepay_required"><?php esc_html_e( 'Prepay', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th data-col="credit_status"><?php esc_html_e( 'Credit Status', 'mmi-xchange-integration' ); ?><span class="sort-icon"></span></th>
                    <th><?php esc_html_e( 'Actions', 'mmi-xchange-integration' ); ?></th>
                </tr>
            </thead>
            <tbody id="mmi-x-vendors-tbody">
                <tr><td colspan="12" class="mmi-x-empty mmi-x-empty--loading"><span class="mmi-loading"></span><span><?php esc_html_e( 'Loading…', 'mmi-xchange-integration' ); ?></span></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="mmi-xchange-card mmi-flat-card mmi-x-card-narrow">
    <h2><?php esc_html_e( 'Catalog Export', 'mmi-xchange-integration' ); ?></h2>
    <p class="description"><?php esc_html_e( 'A new window will open; do not close it until processing is complete.', 'mmi-xchange-integration' ); ?></p>

    <p>
        <a class="button" target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xchange_product_csv' ), MMI_Xchange_Vendors::NONCE_ACTION ) ); ?>">
            <?php esc_html_e( 'Generate Product CSV', 'mmi-xchange-integration' ); ?>
        </a>
    </p>

    <div class="mmi-x-field-row">
        <label for="mmi-x-import-vendor"><?php esc_html_e( 'Import Images (Vendor ID, or "_all")', 'mmi-xchange-integration' ); ?></label>
        <input type="text" id="mmi-x-import-vendor" class="mmi-x-input mmi-x-input-narrow" placeholder="1035" />
        <button type="button" id="mmi-x-import-media-btn" class="button"><?php esc_html_e( 'Import Images', 'mmi-xchange-integration' ); ?></button>
        <span class="mmi-x-sync-meta">
            <?php esc_html_e( 'Last import:', 'mmi-xchange-integration' ); ?>
            <strong id="mmi-x-import-media-synced-at"><?php echo esc_html( $import_media_state['synced_at'] ?? __( 'never', 'mmi-xchange-integration' ) ); ?></strong>
        </span>
    </div>

    <div id="mmi-x-import-media-progress" class="mmi-x-progress-wrap mmi-hidden">
        <div class="mmi-x-progress-bar"><div class="mmi-x-progress-fill" id="mmi-x-import-media-fill"></div></div>
        <span id="mmi-x-import-media-step" class="mmi-x-timestamp"></span>
    </div>
</div>

<!-- ── Vendor Detail Drawer — built on the shared MMIModal component
     (includes/mmi-shared/assets/js/shared/mmi-modal.js). ────────────────── -->
<div class="mmi-modal-backdrop" id="mmi-x-vendor-detail" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-x-vendor-detail-title">
    <div class="mmi-modal mmi-modal--large">
        <div class="mmi-modal-header">
            <h3 id="mmi-x-vendor-detail-title"><?php esc_html_e( 'Vendor Detail', 'mmi-xchange-integration' ); ?></h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'mmi-xchange-integration' ); ?>">&times;</button>
        </div>
        <div class="mmi-modal-body" id="mmi-x-vendor-detail-body"></div>
    </div>
</div>
