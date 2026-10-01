<?php
/**
 * MMI_Xchange_Reverb_Bridge
 *
 * The Reverb-side half of the thin interactivity hook: injects the Xchange
 * SKU/Place-Order/PO-Browser sub-UI into mmi-reverb-integration's Software
 * Delivery modal (via the `mmi_reverb_sw_modal_xchange_fields` action that
 * plugin exposes) and enqueues the small script that drives it. Renders
 * nothing, and enqueues nothing, if mmi-reverb-integration isn't active.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Reverb_Bridge {

    public static function init(): void {
        if ( ! class_exists( 'MMI_Reverb_Software_Handler' ) ) {
            return;
        }

        add_action( 'mmi_reverb_sw_modal_xchange_fields', [ __CLASS__, 'render_fields' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
    }

    public static function render_fields(): void {
        ?>
        <input type="hidden" id="mmi-sw-xchange-po" name="xchange_po" />
        <input type="hidden" id="mmi-sw-xchange-auth" name="xchange_auth" />

        <div class="mmi-toolbar mmi-sw-action-bar">
            <div class="mmi-sw-sku-field">
                <label for="mmi-sw-xchange-sku" class="mmi-sw-field-label"><?php esc_html_e( 'XChange SKU', 'mmi-xchange-integration' ); ?></label>
                <input type="text" id="mmi-sw-xchange-sku" name="xchange_sku" placeholder="1035-2920" />
            </div>
            <button type="button" id="mmi-sw-place-order-btn" class="button button-primary mmi-action-btn"
                    title="<?php esc_attr_e( 'Place a B2B purchase order on XChange for this SKU', 'mmi-xchange-integration' ); ?>">
                🛒 <?php esc_html_e( 'Order', 'mmi-xchange-integration' ); ?>
            </button>
        </div>

        <div id="mmi-sw-po-picker-wrap" class="mmi-sw-po-picker-wrap">
            <div id="mmi-sw-po-loading" class="mmi-sw-po-loading">
                <span class="mmi-loading"></span> <?php esc_html_e( 'Loading XChange orders…', 'mmi-xchange-integration' ); ?>
            </div>
            <div id="mmi-sw-po-picker" class="mmi-sw-po-picker mmi-hidden"></div>
            <div id="mmi-sw-po-controls" class="mmi-sw-po-controls mmi-hidden">
                <span class="mmi-sw-sync-meta"><?php esc_html_e( 'Synced:', 'mmi-xchange-integration' ); ?> <span id="mmi-sw-synced-at">—</span></span>
                <span id="mmi-sw-order-count" class="mmi-sw-order-count"></span>
                <select id="mmi-sw-date-range" class="mmi-sw-date-range">
                    <option value="7"><?php esc_html_e( 'Last 7 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="14"><?php esc_html_e( 'Last 14 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="30"><?php esc_html_e( 'Last 30 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="90"><?php esc_html_e( 'Last 90 days', 'mmi-xchange-integration' ); ?></option>
                    <option value="0" selected><?php esc_html_e( 'All time', 'mmi-xchange-integration' ); ?></option>
                </select>
                <button type="button" id="mmi-sw-refresh-btn" class="button button-small">↺ <?php esc_html_e( 'Refresh', 'mmi-xchange-integration' ); ?></button>
            </div>
        </div>
        <?php
    }

    public static function maybe_enqueue( string $hook ): void {
        if ( $hook !== 'mmi-dashboard_page_mmi-reverb' && $hook !== 'mannmade_page_mmi-reverb' ) {
            return;
        }

        wp_enqueue_script(
            'mmi-xchange-reverb-bridge',
            MMI_XCHANGE_URL . 'assets/js/reverb-modal-bridge.js',
            [ 'jquery' ],
            MMI_XCHANGE_VERSION,
            true
        );

        wp_localize_script( 'mmi-xchange-reverb-bridge', 'mmiXchangeBridge', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( MMI_Xchange_Ajax::NONCE_ACTION ),
        ] );
    }
}
