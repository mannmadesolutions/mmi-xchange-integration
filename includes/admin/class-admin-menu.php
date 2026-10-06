<?php
/**
 * MMI_Xchange_Admin_Menu
 *
 * Submenu under the shared 'mmi-dashboard' ("MannMade") parent menu (see
 * mmi-shared/bootstrap.php). Tabbed single page: Orders / Catalog / Tools /
 * Vendors / Settings / Logs. The page shell and the Catalog tab come from
 * MMI_Supplier_Admin (shared library), the same base the SkuPort and
 * Plugivery pages use; XChange adds its engine-mode switch and its two
 * connection checks to the header.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Admin_Menu extends MMI_Supplier_Admin {

    const PAGE_SLUG = 'mmi-xchange';
    const JS_PREFIX = 'x';

    const TABS = [
        'orders'      => 'Orders',
        'catalog'     => 'Catalog',
        'tools'       => 'Tools',
        'vendors'     => 'Vendors',
        'settings'    => 'Settings',
        'logs'        => 'Logs',
    ];

    public static function init(): void {
        parent::init();
        add_filter( 'mmi_dashboard_alerts', [ __CLASS__, 'dashboard_alerts' ] );
    }

    /**
     * Surfaces the last recorded account-level issue (e.g. E033, direct API
     * ordering disabled) on the suite dashboard's "Needs attention" panel.
     * Reads MMI_Xchange_Account::get_last_issue(), a single already-stored
     * MMI_Settings row — no fresh API call or query, per AGENTS.md's Server
     * Load rule for anything on a page-render path.
     */
    public static function dashboard_alerts( array $alerts ): array {
        $issue = MMI_Xchange_Account::get_last_issue();
        if ( ! $issue || empty( $issue['message'] ) ) {
            return $alerts;
        }

        $alerts[] = [
            'severity'    => 'warning',
            'title'       => __( 'XChange account issue', 'mmi-xchange-integration' ),
            'description' => $issue['message'],
            'url'         => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings#mmi-x-account-section' ),
            'page_slug'   => self::PAGE_SLUG,
        ];

        return $alerts;
    }

    public static function supplier_id(): string {
        return 'xchange';
    }

    public static function supplier_label(): string {
        return __( 'XChange', 'mmi-xchange-integration' );
    }

    public static function capability(): string {
        return (string) apply_filters( 'mmi_xchange_required_capability', 'manage_woocommerce', 'operate' );
    }

    protected static function icon(): string {
        return 'randomize';
    }

    protected static function description(): string {
        return __( 'Xchange (xchangeb2b.com) marketplace integration — order fulfillment, dealer account health, and vendor directory', 'mmi-xchange-integration' );
    }

    protected static function tab_dir(): string {
        return MMI_XCHANGE_PATH . 'includes/admin/';
    }

    /**
     * The page's own scripts. Which screens get them is decided by
     * MMI_Supplier_Admin::enqueue_assets(), which matches the page slug as a
     * substring: the real hook suffix comes from the parent menu's title
     * ('mannmade_page_mmi-xchange'), and an exact slug-based check once left
     * every XChange AJAX action unreachable from the browser.
     */
    protected static function enqueue_plugin_assets(): void {
        wp_enqueue_style( 'mmi-xchange-admin', MMI_XCHANGE_URL . 'assets/css/admin-xchange.css', [ 'mmi-suite-common' ], MMI_XCHANGE_VERSION );
        wp_enqueue_script( 'mmi-xchange-admin', MMI_XCHANGE_URL . 'assets/js/admin-xchange.js', [ 'jquery', 'mmi-resize-transition', 'mmi-modal' ], MMI_XCHANGE_VERSION, true );

        wp_localize_script( 'mmi-xchange-admin', 'mmiXchange', [
            'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
            'nonce'              => wp_create_nonce( MMI_Xchange_Ajax::NONCE_ACTION ),
            // Reuses the shared MMI_Guest_Customer_Converter::ajax_convert()
            // handler as-is (screen-agnostic — capability + nonce +
            // order_id/email, no coupling to the WC edit-order screen) so
            // the Orders tab's guest-convert widget doesn't need its own
            // duplicate create-or-link-customer logic. That class lived in
            // mmi-hub, deleted suite-wide 2026-09-17 without being migrated,
            // then rebuilt into the shared library 2026-09-19 (changelog
            // 1.207.0) — this now always resolves a real nonce again.
            'guestConvertNonce'  => class_exists( 'MMI_Guest_Customer_Converter' )
                ? wp_create_nonce( MMI_Guest_Customer_Converter::NONCE_ACTION )
                : '',
            // What one Fulfill click will do, spelled out in its in-row
            // confirm step (POOrdersPanel.renderConfirmHtml()).
            'autoSendEmail'      => class_exists( 'MMI_Xchange_Auto_Fulfillment' ) && MMI_Xchange_Auto_Fulfillment::is_enabled(),
            'skipCompletedEmail' => MMI_Settings::get( 'mmi_xchange_skip_wc_completed_email', 'yes' ) !== 'no',
        ] );

        // Price History modal — same Chart.js CDN handle/version
        // mmi-reverb-integration already enqueues elsewhere in the suite
        // (class-admin-page.php), so both plugins share one script instance
        // when active together rather than registering competing versions.
        // 'mmi-modal' is the shared centered-dialog component (registered
        // by the mmi-shared assets registry, bootstrap.php) — mmi-xchange-admin
        // above is its own direct dependent too now (Vendor/Order Detail, PO
        // Email panel), this plugin's first real adopter of it.
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
        wp_enqueue_script( 'mmi-xchange-price-history', MMI_XCHANGE_URL . 'assets/js/admin-xchange-price-history.js', [ 'jquery', 'mmi-xchange-admin', 'chartjs', 'mmi-modal' ], MMI_XCHANGE_VERSION, true );
    }

    protected static function before_render(): void {
        // The Account tab was folded into Settings on 2026-09-01 (see
        // AGENTS.md) — redirect any old ?tab=account bookmark straight to
        // the new "Account & Connection" section instead of silently
        // falling back to Orders, matching mmi-data-pipeline's own
        // ?pipeline_tab=taxonomy → ?pipeline_tab=import redirect precedent
        // for a superseded tab.
        if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'account' ) {
            wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'settings' ], admin_url( 'admin.php' ) ) . '#mmi-x-account-section' );
            exit;
        }
    }

    // Mode indicator, header-wide (all tabs) — added 2026-08-24 after a
    // real order's fulfillment failure was initially misdiagnosed as a
    // per-SKU pricing gap when it was actually just "the account is in
    // test mode, which has no pricing data for any real product." See
    // AGENTS.md's "XChange engine=test Has No Real-Product Pricing"
    // Incident History entry. Purpose: make the current engine mode
    // impossible to miss on any tab, for a human or an AI session alike.
    protected static function header_actions(): string {
        $last_check       = MMI_Xchange_Account::get_last_check();
        $last_order_check = MMI_Xchange_Account::get_last_order_api_check();
        $mode             = MMI_Xchange_Account::mode(); // 'live' or 'test'
        ob_start();
        ?>
            <div class="mode-switcher-wrapper" title="<?php esc_attr_e( 'XChange has no real sandbox: engine=test has no pricing data for any product outside their own test vendor, and engine=live spends real money on every reserve/finalize/place_order call.', 'mmi-xchange-integration' ); ?>">
                <span class="mode-label" data-mode="test"><?php esc_html_e( 'Test', 'mmi-xchange-integration' ); ?></span>
                <label class="mode-switcher-toggle">
                    <input type="checkbox" id="mmi-x-mode-switch" <?php checked( $mode, 'live' ); ?> />
                    <span class="slider"></span>
                </label>
                <span class="mode-label" data-mode="live"><?php esc_html_e( 'Live', 'mmi-xchange-integration' ); ?></span>
            </div>
            <?php if ( $last_check['ok'] !== false ) : ?>
            <span id="mmi-x-header-connection-badge">
                <?php if ( $last_check['ok'] === true ) : ?>
                    <span class="mmi-badge success">✓ <?php esc_html_e( 'Connected', 'mmi-xchange-integration' ); ?></span>
                <?php else : ?>
                    <span class="mmi-badge"><?php esc_html_e( 'Not yet checked', 'mmi-xchange-integration' ); ?></span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <button type="button" class="button mmi-action-btn" id="mmi-x-header-test-connection">🔌 <?php esc_html_e( 'Test Connection', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-header-test-connection-spinner"></span>

            <span class="mmi-x-header-divider" aria-hidden="true"></span>

            <?php if ( $last_order_check['ok'] !== false ) : ?>
            <span id="mmi-x-header-order-api-badge" title="<?php esc_attr_e( 'Places a real order via PUT /orders/ against XChange\'s own XMP TEST VENDOR fixture — answers whether direct API order placement works right now, or whether XChange support still needs to be contacted (e.g. error E033).', 'mmi-xchange-integration' ); ?>">
                <?php if ( $last_order_check['ok'] === true ) : ?>
                    <span class="mmi-badge success">✓ <?php esc_html_e( 'Order API OK', 'mmi-xchange-integration' ); ?></span>
                <?php else : ?>
                    <span class="mmi-badge"><?php esc_html_e( 'Order API not yet checked', 'mmi-xchange-integration' ); ?></span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <button type="button" class="button mmi-action-btn" id="mmi-x-header-test-order-api">🛒 <?php esc_html_e( 'Test Order API', 'mmi-xchange-integration' ); ?></button>
            <span class="mmi-loading mmi-hidden" id="mmi-x-header-test-order-api-spinner"></span>
        <?php
        return (string) ob_get_clean();
    }

    /** Always rendered (even empty): admin-xchange.js moves a badge here when a check fails. */
    protected static function header_alerts(): ?string {
        $last_check       = MMI_Xchange_Account::get_last_check();
        $last_order_check = MMI_Xchange_Account::get_last_order_api_check();
        ob_start();
        ?>
            <?php if ( $last_check['ok'] === false ) : ?>
            <span id="mmi-x-header-connection-badge">
                <span class="mmi-badge error">✗ <?php echo esc_html( $last_check['message'] ); ?></span>
            </span>
            <?php endif; ?>
            <?php if ( $last_order_check['ok'] === false ) : ?>
            <span id="mmi-x-header-order-api-badge" title="<?php esc_attr_e( 'Places a real order via PUT /orders/ against XChange\'s own XMP TEST VENDOR fixture — answers whether direct API order placement works right now, or whether XChange support still needs to be contacted (e.g. error E033).', 'mmi-xchange-integration' ); ?>">
                <span class="mmi-badge error">✗ <?php echo esc_html( $last_order_check['message'] ); ?></span>
            </span>
            <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }
}
