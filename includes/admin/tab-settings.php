<?php
/**
 * Settings tab — consolidated XChange credentials (post-migration), mode,
 * PO prefix, license-display text overrides, error handling, and the COGS
 * backfill card (moved here from mmi-reverb-integration's Orders tab).
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mask = static function ( string $value ): string {
    return $value !== '' ? str_repeat( '•', 12 ) : '';
};
$is_masked = static function ( string $value ): bool {
    return strpos( $value, '•' ) !== false;
};

// Saving requires the 'admin' tier (manage_options by default), not just
// the page's 'operate' tier: this form holds the supplier credentials, the
// endpoint URLs those credentials are sent to, and the live auto-purchase
// switch — the same tier the header's Test/Live mode switch already
// required. A shop manager can still view this tab, just not save it.
$can_save_settings = mmi_xchange_user_can( 'admin' );

if ( isset( $_POST['mmi_xchange_save_settings'] ) && check_admin_referer( 'mmi_xchange_settings' ) && ! $can_save_settings ) {
    mmi_xchange_audit( 'settings.update', [ 'outcome' => 'denied' ] );
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Only administrators can change XChange settings.', 'mmi-xchange-integration' ) . '</p></div>';
}

if ( isset( $_POST['mmi_xchange_save_settings'] ) && $can_save_settings && check_admin_referer( 'mmi_xchange_settings' ) ) {
    // Audit records which keys changed — never the values.
    $changed_secret_keys = [];
    $changed_keys        = [];
    $rejected_url_keys   = [];

    $secret_fields = [ 'mmi_xchange_token_key', 'mmi_xchange_api_key', 'mmi_xchange_login_pwd' ];
    foreach ( $secret_fields as $field ) {
        $posted = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
        if ( $posted !== '' && ! $is_masked( $posted ) ) {
            if ( (string) MMI_Settings::get( $field ) !== $posted ) {
                $changed_secret_keys[] = $field;
            }
            MMI_Settings::set( $field, $posted );
        }
    }

    // The API credentials above are sent to these URLs on every call, so
    // only accept a public https URL — a typo'd or hostile value can't
    // redirect them to plain http or an internal host. A rejected value
    // keeps the previously saved one.
    $url_fields = [ 'mmi_xchange_token_url', 'mmi_xchange_api_url', 'mmi_xchange_assets_url' ];

    $plain_fields = [
        'mmi_xchange_token_url', 'mmi_xchange_api_url', 'mmi_xchange_assets_url',
        'mmi_xchange_login_id', 'mmi_xchange_po_prefix', 'mmi_xchange_support_link',
        'mmi_xchange_text_table_title', 'mmi_xchange_text_product', 'mmi_xchange_text_serial',
        'mmi_xchange_text_download', 'mmi_xchange_text_support', 'mmi_xchange_email_errors_to',
    ];
    foreach ( $plain_fields as $field ) {
        $posted = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
        if ( in_array( $field, $url_fields, true ) && $posted !== ''
            && ( strtolower( (string) wp_parse_url( $posted, PHP_URL_SCHEME ) ) !== 'https' || ! wp_http_validate_url( $posted ) ) ) {
            $rejected_url_keys[] = $field;
            continue;
        }
        if ( (string) MMI_Settings::get( $field ) !== $posted ) {
            $changed_keys[] = $field;
        }
        MMI_Settings::set( $field, $posted );
    }

    $failed_message = sanitize_textarea_field( wp_unslash( $_POST['mmi_xchange_failed_message'] ?? '' ) );
    if ( (string) MMI_Settings::get( 'mmi_xchange_failed_message' ) !== $failed_message ) {
        $changed_keys[] = 'mmi_xchange_failed_message';
    }
    MMI_Settings::set( 'mmi_xchange_failed_message', $failed_message );

    foreach ( [ 'mmi_xchange_log_only_errors', 'mmi_xchange_abort_on_error', 'mmi_xchange_auto_fulfill_live', 'mmi_xchange_auto_send_fulfillment_email', 'mmi_xchange_skip_wc_completed_email' ] as $toggle ) {
        if ( (string) MMI_Settings::get( $toggle ) !== ( isset( $_POST[ $toggle ] ) ? 'yes' : 'no' ) ) {
            $changed_keys[] = $toggle;
        }
    }
    // mmi_xchange_production_mode is deliberately NOT written here — see the
    // read-only status row below. It's owned exclusively by the header's
    // mode switcher (MMI_Xchange_Ajax::set_mode(), added 2026-08-24) so
    // there's exactly one writer. A form field here would have raced with
    // it: this handler used to do `isset($_POST[...]) ? 'yes' : 'no'`
    // unconditionally on every save (any field, not just this one) — a
    // stale, un-reloaded Settings tab left open in another window/tab could
    // silently revert a mode change made via the header in the meantime.
    // (mmi-reverb-integration's own tab-settings.php had the identical
    // latent pattern for mmi_reverb_api_mode — fixed the same way there too.)
    MMI_Settings::set( 'mmi_xchange_log_only_errors', isset( $_POST['mmi_xchange_log_only_errors'] ) ? 'yes' : 'no' );
    MMI_Settings::set( 'mmi_xchange_abort_on_error', isset( $_POST['mmi_xchange_abort_on_error'] ) ? 'yes' : 'no' );
    // mmi_xchange_auto_fulfill_live has no competing writer (this checkbox
    // is its only control surface), so the usual isset() pattern is safe
    // here — see MMI_Xchange_Checkout::auto_fulfill_permitted().
    MMI_Settings::set( 'mmi_xchange_auto_fulfill_live', isset( $_POST['mmi_xchange_auto_fulfill_live'] ) ? 'yes' : 'no' );
    MMI_Settings::set( 'mmi_xchange_auto_send_fulfillment_email', isset( $_POST[ 'mmi_xchange_auto_send_fulfillment_email' ] ) ? 'yes' : 'no' );
    MMI_Settings::set( 'mmi_xchange_skip_wc_completed_email', isset( $_POST['mmi_xchange_skip_wc_completed_email'] ) ? 'yes' : 'no' );

    if ( $changed_secret_keys ) {
        mmi_xchange_audit( 'credentials.update', [ 'outcome' => 'success', 'details' => [ 'keys' => $changed_secret_keys ] ] );
    }
    if ( $changed_keys || $rejected_url_keys ) {
        mmi_xchange_audit( 'settings.update', [
            'outcome' => 'success',
            'details' => [ 'keys' => $changed_keys, 'rejected' => $rejected_url_keys ],
        ] );
    }

    if ( $rejected_url_keys ) {
        echo '<div class="notice notice-error"><p>' . esc_html(
            sprintf(
                /* translators: %s: comma-separated setting keys */
                __( 'Not saved (must be a public https:// URL): %s — previous values kept.', 'mmi-xchange-integration' ),
                implode( ', ', $rejected_url_keys )
            )
        ) . '</p></div>';
    }
    echo '<div class="updated"><p><strong>' . esc_html__( 'Settings saved.', 'mmi-xchange-integration' ) . '</strong></p></div>';
}

$get = static fn( string $key, $default = '' ) => MMI_Settings::get( $key, $default );

// Folded in from the retired Account tab on 2026-09-01 (see AGENTS.md) — the
// two connection checks (Test Connection / Test Order API) now live only in
// the page header, next to each other, so there's exactly one trigger for
// each instead of the header and this tab each having their own competing
// "Check Connection" button for the same underlying check. This section is
// read-only status display; MMI_Xchange_Account::get_last_check()/
// get_last_order_api_check() are refreshed by the header buttons and shared
// via the same render helpers (see admin-xchange.js) regardless of which
// tab happens to be open when a header button is clicked.
$last_check       = MMI_Xchange_Account::get_last_check();
$last_order_check = MMI_Xchange_Account::get_last_order_api_check();
$last_issue       = MMI_Xchange_Account::get_last_issue();
?>
<div class="mmi-collapsible-section" id="mmi-x-account-section">
    <div class="mmi-section-header mmi-x-section-clickable">
        <div>
            <h3>
                <span class="dashicons dashicons-admin-network"></span>
                <?php esc_html_e( 'Account & Connection', 'mmi-xchange-integration' ); ?>
                <span class="mmi-collapse-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
            </h3>
            <p class="mmi-section-description"><?php esc_html_e( 'XChange does not expose a real-time account balance or credit-status API. "Test Connection" (header) checks that your configured credentials can authenticate; "Test Order API" (header) places a real order against XChange\'s XMP TEST VENDOR fixture to check whether direct order placement (PUT /orders/) is currently enabled for this account.', 'mmi-xchange-integration' ); ?></p>
        </div>
    </div>
    <div class="mmi-section-content">
        <div class="mmi-x-status-grid">
            <div class="mmi-x-status-card">
                <h3><?php esc_html_e( 'Last connection check', 'mmi-xchange-integration' ); ?></h3>
                <div id="mmi-x-account-check-result">
                    <?php if ( $last_check['ok'] === null ) : ?>
                        <span class="mmi-badge"><?php esc_html_e( 'Never checked', 'mmi-xchange-integration' ); ?></span>
                    <?php else : ?>
                        <span class="mmi-badge <?php echo $last_check['ok'] ? 'success' : 'error'; ?>">
                            <?php echo esc_html( $last_check['message'] ); ?>
                        </span>
                        <div class="mmi-x-timestamp"><?php echo esc_html( $last_check['checked_at'] ?? '' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mmi-x-status-card">
                <h3><?php esc_html_e( 'Last order API check', 'mmi-xchange-integration' ); ?></h3>
                <div id="mmi-x-order-api-check-result">
                    <?php if ( $last_order_check['ok'] === null ) : ?>
                        <span class="mmi-badge"><?php esc_html_e( 'Never checked', 'mmi-xchange-integration' ); ?></span>
                    <?php else : ?>
                        <span class="mmi-badge <?php echo $last_order_check['ok'] ? 'success' : 'error'; ?>">
                            <?php echo esc_html( $last_order_check['message'] ); ?>
                        </span>
                        <div class="mmi-x-timestamp"><?php echo esc_html( $last_order_check['checked_at'] ?? '' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <p class="description"><?php esc_html_e( 'Both checks are run from the buttons at the top of this page (header), so they update no matter which tab is open.', 'mmi-xchange-integration' ); ?></p>

        <?php if ( $last_issue ) : ?>
            <div class="mmi-x-issue-banner">
                <strong><?php esc_html_e( 'Last known account issue:', 'mmi-xchange-integration' ); ?></strong>
                <?php echo esc_html( $last_issue['message'] ); ?>
                <div class="mmi-x-timestamp"><?php echo esc_html( $last_issue['occurred_at'] ); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<form method="post" class="mmi-xchange-card mmi-flat-card mmi-x-card-narrow mmi-label-grid mmi-label-grid--start mmi-x-field-grid">
    <?php wp_nonce_field( 'mmi_xchange_settings' ); ?>

    <h2><?php esc_html_e( 'Connection Settings', 'mmi-xchange-integration' ); ?></h2>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_token_key"><?php esc_html_e( 'XChange Token Key', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_token_key" id="mmi_xchange_token_key" class="mmi-x-input" value="<?php echo esc_attr( $mask( (string) $get( 'mmi_xchange_token_key' ) ) ); ?>" autocomplete="off" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_api_key"><?php esc_html_e( 'XChange API Key', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_api_key" id="mmi_xchange_api_key" class="mmi-x-input" value="<?php echo esc_attr( $mask( (string) $get( 'mmi_xchange_api_key' ) ) ); ?>" autocomplete="off" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_token_url"><?php esc_html_e( 'XChange Token URL', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_token_url" id="mmi_xchange_token_url" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_token_url', 'https://xchangeb2b.com/XCH/vr_api/token/' ) ); ?>" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_api_url"><?php esc_html_e( 'XChange API URL', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_api_url" id="mmi_xchange_api_url" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_api_url', 'https://xchangemarketb2b.com/api/v2/athabasca/' ) ); ?>" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_assets_url"><?php esc_html_e( 'XChange Assets URL', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_assets_url" id="mmi_xchange_assets_url" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_assets_url', 'https://xchangeb2b.com/XCH/V4.1d_TC/assets/details.php' ) ); ?>" />
    </div>

    <h2><?php esc_html_e( 'Web-Portal Login (manual Full Sync only)', 'mmi-xchange-integration' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Used ONLY by the "Full Sync…" button on the Orders tab (CCSA + Invoice History scrape). Never used automatically — the "Refresh" button and normal order lookups use the XChange REST API only, so they never touch this login and never end an active XChange.com browser session.', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_login_id"><?php esc_html_e( 'Reseller Login ID', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_login_id" id="mmi_xchange_login_id" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_login_id' ) ); ?>" autocomplete="off" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_login_pwd"><?php esc_html_e( 'Reseller Login Password', 'mmi-xchange-integration' ); ?></label>
        <input type="password" name="mmi_xchange_login_pwd" id="mmi_xchange_login_pwd" class="mmi-x-input" value="<?php echo esc_attr( $mask( (string) $get( 'mmi_xchange_login_pwd' ) ) ); ?>" autocomplete="off" />
    </div>

    <h2><?php esc_html_e( 'Purchasing Settings', 'mmi-xchange-integration' ); ?></h2>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><?php esc_html_e( 'Engine mode', 'mmi-xchange-integration' ); ?></label>
        <span class="mmi-badge <?php echo $get( 'mmi_xchange_production_mode' ) === 'yes' ? 'warning' : 'success'; ?>">
            <?php echo $get( 'mmi_xchange_production_mode' ) === 'yes' ? esc_html__( 'LIVE — purchases will be charged', 'mmi-xchange-integration' ) : esc_html__( 'TEST', 'mmi-xchange-integration' ); ?>
        </span>
    </div>
    <p class="description"><?php esc_html_e( 'Changed via the Test/Live switch in the page header above, not here — that keeps exactly one place that can flip this, since it directly controls whether purchases are real.', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><input type="checkbox" name="mmi_xchange_auto_fulfill_live" <?php checked( $get( 'mmi_xchange_auto_fulfill_live' ), 'yes' ); ?> /> <?php esc_html_e( 'Allow automatic fulfillment while in Live mode', 'mmi-xchange-integration' ); ?></label>
    </div>
    <p class="description"><?php esc_html_e( 'Off by default, and only matters once Engine mode above is Live. When off, checkout/payment-complete/order-complete never place a real XChange purchase on their own — every live order sits in the Recent XChange Orders panel until an admin clicks "Fulfill." Turn this on only once you want checkout itself to auto-purchase for real, without a human in the loop. (Test mode is unaffected either way — it cannot complete a real purchase for any product outside XChange\'s own test vendor.)', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><input type="checkbox" name="<?php echo esc_attr( 'mmi_xchange_auto_send_fulfillment_email' ); ?>" <?php checked( $get( 'mmi_xchange_auto_send_fulfillment_email', 'yes' ), 'yes' ); ?> /> <?php esc_html_e( 'Email the customer automatically after "Fulfill"', 'mmi-xchange-integration' ); ?></label>
    </div>
    <p class="description"><?php esc_html_e( 'On by default. After you click "Fulfill" (or "Fulfill together") and the PO is placed, the license email goes to the customer right away and the order is completed — no Email Customer modal. It only sends when the customer\'s real email and the license key are both known; otherwise the modal opens as before. A license XChange hasn\'t posted yet is re-checked for about 2 hours and sent when it appears. Completing a Reverb order also messages the buyer on Reverb and marks it shipped (Reverb plugin settings).', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><input type="checkbox" name="mmi_xchange_skip_wc_completed_email" <?php checked( $get( 'mmi_xchange_skip_wc_completed_email', 'yes' ), 'yes' ); ?> /> <?php esc_html_e( 'Skip WooCommerce\'s "Completed order" email after the license email', 'mmi-xchange-integration' ); ?></label>
    </div>
    <p class="description"><?php esc_html_e( 'On by default. When an order made only of XChange items completes because every license email went out, the customer doesn\'t also get WooCommerce\'s "Your order is complete" email. Orders with other items, or completed without the license email, still get it.', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_po_prefix"><?php esc_html_e( 'PO Prefix', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_po_prefix" id="mmi_xchange_po_prefix" class="mmi-x-input mmi-x-input-narrow" value="<?php echo esc_attr( $get( 'mmi_xchange_po_prefix', '0000' ) ); ?>" />
    </div>

    <h2><?php esc_html_e( 'License Display', 'mmi-xchange-integration' ); ?></h2>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_support_link"><?php esc_html_e( 'Store-Wide Support Link Override', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_support_link" id="mmi_xchange_support_link" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_support_link' ) ); ?>" />
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_text_table_title"><?php esc_html_e( 'Table Title Label', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_text_table_title" id="mmi_xchange_text_table_title" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_text_table_title', 'Product Licensing Information' ) ); ?>" />
    </div>

    <h2><?php esc_html_e( 'Error Handling', 'mmi-xchange-integration' ); ?></h2>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><input type="checkbox" name="mmi_xchange_log_only_errors" <?php checked( $get( 'mmi_xchange_log_only_errors' ), 'yes' ); ?> /> <?php esc_html_e( 'Log errors only (skip routine activity logging)', 'mmi-xchange-integration' ); ?></label>
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label><input type="checkbox" name="mmi_xchange_abort_on_error" <?php checked( $get( 'mmi_xchange_abort_on_error' ), 'yes' ); ?> /> <?php esc_html_e( 'Abort customer order on error', 'mmi-xchange-integration' ); ?></label>
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_failed_message"><?php esc_html_e( 'Order-Aborted Error Message', 'mmi-xchange-integration' ); ?></label>
        <textarea name="mmi_xchange_failed_message" id="mmi_xchange_failed_message" class="mmi-x-textarea"><?php echo esc_textarea( (string) $get( 'mmi_xchange_failed_message' ) ); ?></textarea>
    </div>
    <div class="mmi-x-field-row mmi-label-grid-row">
        <label for="mmi_xchange_email_errors_to"><?php esc_html_e( 'Email Plugin Errors To', 'mmi-xchange-integration' ); ?></label>
        <input type="text" name="mmi_xchange_email_errors_to" id="mmi_xchange_email_errors_to" class="mmi-x-input" value="<?php echo esc_attr( $get( 'mmi_xchange_email_errors_to' ) ); ?>" />
    </div>

    <p><button type="submit" name="mmi_xchange_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'mmi-xchange-integration' ); ?></button></p>
</form>

<div class="mmi-xchange-card mmi-flat-card mmi-x-card-narrow" id="mmi-x-cogs-card">
    <h2>🏷️ <?php esc_html_e( 'XChange Catalog COGS Backfill', 'mmi-xchange-integration' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Auto-matches dealer price from the XChange catalog onto existing Reverb orders.', 'mmi-xchange-integration' ); ?></p>
    <div class="mmi-x-status-grid">
        <div class="mmi-x-status-card"><h3><?php esc_html_e( 'Matchable orders', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-cogs-matchable">—</span></div>
        <div class="mmi-x-status-card"><h3><?php esc_html_e( 'Already populated', 'mmi-xchange-integration' ); ?></h3><span id="mmi-x-cogs-done">—</span></div>
    </div>
    <div class="mmi-x-progress-wrap">
        <div class="mmi-x-progress-bar"><div class="mmi-x-progress-fill" id="mmi-x-cogs-progress-fill" style="--pct: 0%"></div></div>
        <span id="mmi-x-cogs-pct">0%</span>
    </div>
    <div id="mmi-x-cogs-status-msg" class="mmi-x-status"></div>
    <button type="button" id="mmi-x-cogs-backfill-btn" class="button">▶ <?php esc_html_e( 'Run COGS Backfill', 'mmi-xchange-integration' ); ?></button>
    <span class="mmi-loading mmi-hidden" id="mmi-x-cogs-spinner"></span>
</div>
