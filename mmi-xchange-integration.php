<?php
/**
 * Plugin Name: MMI Xchange
 * Plugin URI: https://mannmade.solutions/plugins/xchange-integration
 * Description: Full Xchange (xchangeb2b.com) integration — storefront checkout fulfillment, admin order search, dealer account health, and vendor directory.
 * Version: 1.41.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: MannMade Solutions
 * Author URI: https://mannmade.solutions
 * Text Domain: mmi-xchange-integration
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Respect MMI Hub WP All Import protection ────── */
if ( defined( 'MMI_HUB_WPALLIMPORT_SKIP' ) && MMI_HUB_WPALLIMPORT_SKIP ) {
    return;
}

/* ── Plugin constants ────── */
define( 'MMI_XCHANGE_VERSION',  '1.41.1' );
define( 'MMI_XCHANGE_FILE',     __FILE__ );
define( 'MMI_XCHANGE_PATH',     plugin_dir_path( __FILE__ ) );
define( 'MMI_XCHANGE_URL',      plugin_dir_url( __FILE__ ) );
define( 'MMI_XCHANGE_BASENAME', plugin_basename( __FILE__ ) );

/* ── MMI Shared Library (ADR-0006, mmi-admin/docs/decisions/) ────────
 * Registers this plugin's bundled copy of MMI_Settings/MMI_Logger/
 * MMI_API_Throttler/MMI_Resource_Guard/MMI_Model/MMI_Product/
 * MMI_Media_Helper as a version-negotiation candidate. This plugin no
 * longer hard-requires MMI_Hub (see the dependency check below) — these
 * classes now resolve from this bundled copy whenever mmi-hub isn't
 * present, or from mmi-hub's own copy (which still wins whenever mmi-hub
 * IS present, since its own require_once calls are class_exists()-guarded
 * too — see ADR-0006). Must load before anything below could reference any
 * of those class names. */
require_once MMI_XCHANGE_PATH . 'includes/mmi-shared/bootstrap.php';

/* ── Legacy meta-key constants ──────────────────────────────────────────────
 * Carried over verbatim from the retired `xchangemarket` plugin — these are
 * already live on existing orders/products, so the values must never change.
 */
define( 'XCHANGE_LICENSE_INFO_FIELD_NAME',      'xchange_license_info' );
define( 'XCHANGE_INTERNAL_SKU_NAME',            '_xchange_internal_sku' );
define( 'XCHANGE_INTERNAL_SKU_NAME_VAR',        '_xchange_internal_sku_var' );
define( 'XCHANGE_PRODUCT_SUPPORT_LINK',         '_xchange_product_support_link' );
define( 'XCHANGE_PRODUCT_SUPPORT_LINK_VAR',     '_xchange_product_support_link_var' );

/* ── Capability gate ──────────────────────────────────────────────────────
 * The one capability check every privileged XChange handler goes through,
 * so least-privilege/RBAC is a single filter instead of a grep across
 * files. Two tiers, defaults equal to what each handler checked before
 * this helper existed:
 *   'operate' — manage_woocommerce: the XChange page, Orders/Vendors tabs,
 *               fulfillment (Fulfill / Place Order / emails), syncs.
 *   'admin'   — manage_options: engine mode, credentials/settings, logs,
 *               COGS backfill, catalog CSV export.
 * Filter: mmi_xchange_required_capability( $cap, $context ). ────── */
if ( ! function_exists( 'mmi_xchange_user_can' ) ) {
    function mmi_xchange_user_can( string $context = 'operate' ): bool {
        $default = ( $context === 'admin' ) ? 'manage_options' : 'manage_woocommerce';
        $cap     = apply_filters( 'mmi_xchange_required_capability', $default, $context );

        return is_string( $cap ) && $cap !== '' && current_user_can( $cap );
    }
}

/* ── Audit trail (MMI_Audit_Log, shared library) ──────────────────────────
 * Thin class_exists()-guarded wrapper so call sites stay one line and the
 * plugin keeps working on a shared-lib copy that predates MMI_Audit_Log.
 * $details must never carry secret values — keys/IDs only. ────── */
if ( ! function_exists( 'mmi_xchange_audit' ) ) {
    function mmi_xchange_audit( string $action, array $args = [] ): void {
        if ( class_exists( 'MMI_Audit_Log' ) ) {
            MMI_Audit_Log::record( 'mmi-xchange-integration', $action, $args );
        }
    }
}

/* ── Activation ────── */
register_activation_hook( __FILE__, 'mmi_xchange_activate' );
function mmi_xchange_activate() {
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-order-sync.php';
    MMI_Xchange_Order_Sync::create_table();

    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-price-history.php';
    MMI_Xchange_Price_History::create_table();
}

/* ── Deactivation ────── */
register_deactivation_hook( __FILE__, 'mmi_xchange_deactivate' );
function mmi_xchange_deactivate() {
    wp_clear_scheduled_hook( 'mmi_xchange_price_history_refresh' );
}

/* ── Dependency + license guard, bootstrap ────── */
// MMI_Hub is no longer a hard requirement (ADR-0006 /
// MMI_HUB_ELIMINATION_HANDOFF.md, Phase 2) — Settings/Logger/Throttler/
// Resource_Guard/Model/Product/Media_Helper now resolve from this plugin's
// own bundled includes/mmi-shared/ copy regardless of whether mmi-hub is
// present. WooCommerce remains the one genuine hard dependency.
add_action( 'plugins_loaded', function () {

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'MMI Xchange requires WooCommerce to be active.', 'mmi-xchange-integration' )
                . '</p></div>';
        } );
        return;
    }

    /* ── License-or-trial guard (MMI_License_Gate, shared library) ──────
     * Replaces the old bare `function_exists(...) && ! mmi_is_licensed(...)`
     * form, which was itself the fail-open anti-pattern — when the function
     * is absent, the condition is false, the `return` never runs, and the
     * plugin proceeds as if licensed. MMI_License_Gate::is_open() fails
     * closed and adds automatic trial support.
     *
     * NOTE: this whole plugin (including its own admin menu) still gates as
     * one unit, same scoping decision as mmi-reverb-integration/mmi-google-
     * services — admin menu registration is entangled with 20+ other
     * requires in this same block; the finer "menu always visible" split
     * used on smaller plugins was judged too risky here. Follow-up, not
     * fixed in this pass. ────── */
    if ( ! class_exists( 'MMI_License_Gate' ) || ! MMI_License_Gate::is_open( 'mmi-xchange-integration' ) ) {
        return;
    }

    /* ── Load core classes ────── */
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-settings-migration.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-api-client.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-web-session.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-order-sync.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-checkout.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-fulfillment-queue.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-fulfillment-progress.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-guest-email-request.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-po-linker.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-price-history.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-price-history-watcher.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-product-fields.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-vendors.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-cogs.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-account.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-logs.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-api.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-fulfillment-email.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-auto-fulfillment.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-finalize-diagnostic.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-webhooks.php';
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-webhook-receiver.php';
    require_once MMI_XCHANGE_PATH . 'includes/ajax/class-xchange-ajax.php';
    require_once MMI_XCHANGE_PATH . 'includes/admin/class-admin-menu.php';
    require_once MMI_XCHANGE_PATH . 'includes/admin/class-reverb-bridge.php';

    // Ships 3 ready-made field-mapping presets into mmi-data-pipeline's
    // wizard, if that plugin is active — a bonus contribution via a filter
    // hook, not a hard dependency (mmi-data-pipeline is not required above).
    require_once MMI_XCHANGE_PATH . 'includes/class-xchange-field-mapping-presets.php';
    MMI_Xchange_Field_Mapping_Presets::init();

    /**
     * mmi-data-health integration, same bonus-contribution shape as the
     * field-mapping presets above — not a hard dependency (mmi-data-health
     * is not required above). Contributes a per-post "Supplier SKU" rule via
     * the rule-pack class (deferred require, same interface-declaration-time
     * hazard as mmi-reverb-integration's equivalent registration), plus a
     * separate Overview-tab widget surfacing get_local_image_readiness()'s
     * vendor-aggregate stats — that data has no per-post IDs, so it can't be
     * a rule, only a read-only widget kept visibly distinct from the
     * per-post table.
     */
    add_filter( 'mmi_data_health_rule_packs', function ( array $packs ): array {
        require_once MMI_XCHANGE_PATH . 'includes/class-xchange-data-health-rule-pack.php';
        $packs[] = new MMI_Xchange_Data_Health_Rule_Pack();
        return $packs;
    } );

    add_filter( 'mmi_data_health_overview_widgets', function ( array $widgets ): array {
        $readiness = MMI_Xchange_Vendors::get_local_image_readiness();
        $totals    = $readiness['totals'] ?? [];
        $vendors_url = admin_url( 'admin.php?page=mmi-xchange&tab=vendors' );

        $html = empty( $readiness['products_json_missing'] )
            ? sprintf(
                '<p>%1$s: <strong>%2$d</strong><br>%3$s: <strong>%4$d</strong><br>%5$s: <strong>%6$d</strong></p><p><a href="%7$s">%8$s &rarr;</a></p>',
                esc_html__( 'Matched products', 'mmi-xchange-integration' ), (int) ( $totals['matched_products'] ?? 0 ),
                esc_html__( 'With image', 'mmi-xchange-integration' ), (int) ( $totals['with_image'] ?? 0 ),
                esc_html__( 'Missing image', 'mmi-xchange-integration' ), (int) ( $totals['missing_image'] ?? 0 ),
                esc_url( $vendors_url ),
                esc_html__( 'View vendor breakdown', 'mmi-xchange-integration' )
            )
            : '<p>' . esc_html__( 'XChange catalog feed not yet fetched.', 'mmi-xchange-integration' ) . '</p>';

        $widgets[] = [
            'label' => __( 'XChange Vendor Image Readiness', 'mmi-xchange-integration' ),
            'html'  => $html,
        ];
        return $widgets;
    } );

    /* ── One-time settings migration (xchangemarket wp_options + legacy wp_mmi keys) ────── */
    MMI_Xchange_Settings_Migration::maybe_run();

    /* ── Keep durable orders table current on upgrade ────── */
    MMI_Xchange_Order_Sync::maybe_upgrade();
    MMI_Xchange_Price_History::maybe_upgrade();

    /* ── Hourly price-history refresh cron — guarantees the dealer/promo
     * transients (and therefore the price-history table) actually refresh
     * even with no admin looking at the Place Order tab, since nothing else
     * repopulates them today. Cleared on deactivation, above. ────── */
    add_action( 'mmi_xchange_price_history_refresh', [ 'MMI_Xchange_Price_History', 'refresh' ] );
    if ( ! wp_next_scheduled( 'mmi_xchange_price_history_refresh' ) ) {
        wp_schedule_event( time(), 'hourly', 'mmi_xchange_price_history_refresh' );
    }

    /* ── One-time onboarding: queue safe order sync + image import the
     * first time credentials are configured (see docblock) ────── */
    MMI_Xchange_Account::maybe_run_onboarding();

    /* ── Init hook registration for each subsystem ────── */
    MMI_Xchange_Checkout::init();
    MMI_Xchange_Product_Fields::init();
    MMI_Xchange_Vendors::init();

    // Catalog tab: mmi-data-pipeline's shared feed catalog, when it's active
    // (its base class loads on plugins_loaded, so register on init).
    add_action( 'init', static function () {
        if ( class_exists( 'MMI_Pipeline_Feed_Catalog' ) ) {
            require_once MMI_XCHANGE_PATH . 'includes/class-xchange-feed-catalog.php';
            MMI_Pipeline_Feed_Catalog::register( new MMI_Xchange_Feed_Catalog() );
        }
    }, 5 );
    MMI_Xchange_COGS::init();
    MMI_Xchange_Order_Sync::init();
    MMI_Xchange_Price_History_Watcher::init();
    MMI_Xchange_Fulfillment_Email::init();
    MMI_Xchange_Auto_Fulfillment::init();
    MMI_Xchange_Webhook_Receiver::init();
    MMI_Xchange_Ajax::init();
    MMI_Xchange_Admin_Menu::init();
    MMI_Xchange_Reverb_Bridge::init();

    // XChange as a provider in the shared software-fulfillment core (see
    // MMI_Software_Fulfillment) — lets its per-item placed/fulfilled
    // records and customer grouping recognize XChange line items.
    if ( class_exists( 'MMI_Software_Fulfillment' ) ) {
        MMI_Software_Fulfillment::register_provider( 'xchange', [
            'label'       => 'XChange',
            'resolve_sku' => [ 'MMI_Xchange_API', 'get_product_xchange_sku' ],
        ] );
    }

}, 20 ); // Late enough that any present sibling plugin (mmi-hub, mmi-reverb-integration, mmi-data-pipeline) has already registered its own plugins_loaded hooks first — no longer specifically "after MMI Hub," since that's not a hard dependency anymore.
