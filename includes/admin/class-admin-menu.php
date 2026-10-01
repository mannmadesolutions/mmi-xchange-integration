<?php
/**
 * MMI_Xchange_Admin_Menu
 *
 * Submenu under the shared 'mmi-dashboard' ("MannMade") parent menu (see
 * mmi-shared/bootstrap.php). Tabbed single page: Orders / Place Order /
 * Account / Vendors / Settings.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Admin_Menu {

    const PAGE_SLUG = 'mmi-xchange';

    const TABS = [
        'orders'      => 'Orders',
        'vendors'     => 'Vendors',
        'settings'    => 'Settings',
        'logs'        => 'Logs',
    ];

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
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

    public static function register_submenu(): void {
        add_submenu_page(
            'mmi-dashboard',
            __( 'XChange', 'mmi-xchange-integration' ),
            __( 'XChange', 'mmi-xchange-integration' ),
            apply_filters( 'mmi_xchange_required_capability', 'manage_woocommerce', 'operate' ),
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * WordPress derives a submenu's hook suffix from the PARENT's sanitized
     * MENU TITLE, not its slug — get_plugin_page_hookname()'s
     * $admin_page_hooks[$parent_slug] lookup is populated from add_menu_page()'s
     * $menu_title argument. The shared parent menu (bootstrap.php's
     * mmi_shared_menu_add_parent()) registers 'mmi-dashboard' as the slug but
     * "MannMade" as the title, so the real hook here is always
     * 'mannmade_page_mmi-xchange', never the naive slug-based guess this used
     * to check exclusively — confirmed live via get_plugin_page_hookname(),
     * which meant wp_enqueue_script()/wp_localize_script() below never ran on
     * any page load, ever, since the mmi-hub-elimination migration. Every
     * mmi_xchange_* AJAX action was consequently unreachable from the browser
     * (mmiXchange was undefined) despite working correctly when called
     * directly — found via a real Vendors/Orders "stuck on Loading" report.
     * mmi-reverb-integration's equivalent check already defends against this
     * exact bug (checks both hook variants); this one never did. Using a
     * substring match instead, per AGENTS.md's documented fix for this bug
     * pattern, so it's immune to a possible future parent-title change too.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

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

    public static function render_page(): void {
        if ( ! mmi_xchange_user_can() ) {
            return;
        }

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

        $current_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], self::TABS )
            ? sanitize_text_field( wp_unslash( $_GET['tab'] ) )
            : 'orders';

        // Mode indicator, header-wide (all tabs) — added 2026-08-24 after a
        // real order's fulfillment failure was initially misdiagnosed as a
        // per-SKU pricing gap when it was actually just "the account is in
        // test mode, which has no pricing data for any real product." See
        // AGENTS.md's "XChange engine=test Has No Real-Product Pricing"
        // Incident History entry. Purpose: make the current engine mode
        // impossible to miss on any tab, for a human or an AI session alike.
        $mode              = MMI_Xchange_Account::mode(); // 'live' or 'test'
        $last_check        = MMI_Xchange_Account::get_last_check();
        $last_order_check  = MMI_Xchange_Account::get_last_order_api_check();
        ?>
        <div class="wrap mmi-page mmi-xchange-wrap">
            <div class="mmi-header">
                <h1><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'XChange', 'mmi-xchange-integration' ); ?></h1>
                <div class="mmi-header-actions">
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
                </div>
                <p class="mmi-header-description"><?php esc_html_e( 'Xchange (xchangeb2b.com) marketplace integration — order fulfillment, dealer account health, and vendor directory', 'mmi-xchange-integration' ); ?></p>
                <div class="mmi-header-alerts" id="mmi-x-header-alerts">
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
                </div>
            </div>
            <div class="wp-header-end"></div>

            <nav class="nav-tab-wrapper">
                <?php foreach ( self::TABS as $tab_key => $tab_label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $tab_key ], admin_url( 'admin.php' ) ) ); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="mmi-xchange-tab-content">
                <?php
                $tab_file = MMI_XCHANGE_PATH . 'includes/admin/tab-' . $current_tab . '.php';
                if ( file_exists( $tab_file ) ) {
                    require $tab_file;
                }
                ?>
            </div>
        </div>
        <?php
    }
}
