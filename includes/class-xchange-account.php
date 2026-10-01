<?php
/**
 * MMI_Xchange_Account
 *
 * Best-effort dealer/reseller account health. Xchange exposes no real
 * "get my account status" endpoint (confirmed against both the SOAP and
 * REST/Web-Asset docs on file) — so this surfaces what IS observable:
 * whether the configured credentials can successfully fetch a token
 * (explicit, user-triggered "Check Connection", not automatic — this
 * project's AJAX-fan-out rules don't allow silent auto-fire on page load),
 * which mode (sandbox/production) is configured, and the last account-hold
 * / API-disabled style error text seen from any Xchange call, persisted so
 * it's visible without digging through logs.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Account {

    const LAST_CHECK_KEY           = 'mmi_xchange_account_last_check';
    const LAST_ISSUE_KEY           = 'mmi_xchange_account_last_issue';
    const LAST_ORDER_API_CHECK_KEY = 'mmi_xchange_account_last_order_api_check';
    const ONBOARDING_DONE_KEY      = 'mmi_xchange_onboarding_complete';

    /**
     * Fires once, the first time XChange REST credentials are configured
     * (whether via a fresh Settings save or the one-time migration of
     * legacy `xchangemarket` options) — queues the safe, REST-only pieces
     * of onboarding: an order sync (MMI_Xchange_Order_Sync::queue_sync())
     * and an all-vendor image import (MMI_Xchange_Vendors::queue_import_media()).
     * Both already run this way for every later refresh, so this just
     * fires them once automatically instead of waiting for a manual click.
     *
     * Deliberately does NOT auto-trigger MMI_Xchange_Order_Sync::run_full_sync()
     * (the "Full Sync…" portal-login scrape) — that's the one action in this
     * plugin that can silently end an admin's own xchangeb2b.com browser
     * session, and every other call site into it (the AJAX handler, the
     * order-sync docblock) is explicit that it must only run from a clearly-
     * labeled manual button click, never automatically. Complete order
     * history still requires that one manual "Full Sync…" click; the Orders
     * tab's "Full history synced: never" indicator already surfaces that as
     * a to-do.
     *
     * Called unconditionally on every admin-side load from the plugin
     * bootstrap (matching the existing maybe_upgrade() convention in this
     * plugin) — cheap once the done-flag is set, and the only way to
     * correctly catch credentials that arrive via the settings-migration
     * path as well as a manual Settings-tab save.
     */
    public static function maybe_run_onboarding(): void {
        if ( MMI_Settings::get( self::ONBOARDING_DONE_KEY ) === '1' ) {
            return;
        }

        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return;
        }

        MMI_Settings::set( self::ONBOARDING_DONE_KEY, '1' );

        MMI_Xchange_Order_Sync::queue_sync();
        MMI_Xchange_Vendors::queue_import_media( null );

        MMI_Logger::info(
            'XChange onboarding: credentials detected for the first time — queued order sync + all-vendor image import. Complete order history still requires a manual "Full Sync…" click (Orders tab).',
            [],
            'xchange',
            'MMI_Xchange_Account'
        );
    }

    /**
     * Explicit, user-triggered connection check — fetches a token and
     * records the result.
     *
     * @return array{ok:bool, message:string, checked_at:string}
     */
    public static function check_connection(): array {
        $client = new MMI_Xchange_API_Client();

        if ( ! $client->has_credentials() ) {
            $result = [ 'ok' => false, 'message' => __( 'Credentials are not fully configured.', 'mmi-xchange-integration' ), 'checked_at' => current_time( 'mysql' ) ];
            MMI_Settings::set( self::LAST_CHECK_KEY, wp_json_encode( $result ) );
            return $result;
        }

        $ctx = $client->get_auth_context();

        $result = is_wp_error( $ctx )
            ? [ 'ok' => false, 'message' => $ctx->get_error_message(), 'checked_at' => current_time( 'mysql' ) ]
            : [ 'ok' => true, 'message' => __( 'Connected — token retrieved successfully.', 'mmi-xchange-integration' ), 'checked_at' => current_time( 'mysql' ) ];

        MMI_Settings::set( self::LAST_CHECK_KEY, wp_json_encode( $result ) );

        MMI_Logger::info(
            'XChange connection check: ' . ( $result['ok'] ? 'ok' : 'failed — ' . $result['message'] ),
            [],
            'xchange',
            'MMI_Xchange_Account'
        );

        return $result;
    }

    public static function get_last_check(): array {
        $raw     = MMI_Settings::get( self::LAST_CHECK_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [ 'ok' => null, 'message' => __( 'Never checked.', 'mmi-xchange-integration' ), 'checked_at' => null ];
    }

    /**
     * Explicit, user-triggered write-capability check — places a real order
     * via MMI_Xchange_API::place_order() against XChange's own designated
     * XMP TEST VENDOR (account 1014, SKU 1014-65 — see
     * MMI_Xchange_Finalize_Diagnostic::TEST_SKU and AGENTS.md's "Testing
     * XChange API Usage" rule). This is the same PUT /orders/ endpoint that
     * is currently blocked for this account (error E033) — running this
     * check answers "does PUT order placement work right now, or do I still
     * need to contact XChange support?" without touching a real customer
     * order. place_order() already records any E033/"disabled" failure via
     * record_issue() (the "Last known account issue" banner) and logs via
     * MMI_Logger on its own — this method only adds the separate, dedicated
     * "last order-API check" record so a genuine past success isn't
     * indistinguishable from "never checked."
     *
     * @return array{ok:bool, message:string, checked_at:string, po_number?:string}
     */
    public static function check_order_api(): array {
        if ( ! class_exists( 'MMI_Xchange_Finalize_Diagnostic' ) || ! class_exists( 'MMI_Xchange_API' ) ) {
            $result = [ 'ok' => false, 'message' => __( 'Order API check is unavailable.', 'mmi-xchange-integration' ), 'checked_at' => current_time( 'mysql' ) ];
            MMI_Settings::set( self::LAST_ORDER_API_CHECK_KEY, wp_json_encode( $result ) );
            return $result;
        }

        $response = MMI_Xchange_API::place_order( MMI_Xchange_Finalize_Diagnostic::TEST_SKU, 1, [ 'trigger' => 'diagnostic.order_api_check' ] );

        $result = isset( $response['error'] )
            ? [ 'ok' => false, 'message' => (string) $response['error'], 'checked_at' => current_time( 'mysql' ) ]
            : [
                'ok'         => true,
                'message'    => sprintf(
                    /* translators: %s: XChange PO number */
                    __( 'Order API OK — PO %s placed against the XChange test vendor.', 'mmi-xchange-integration' ),
                    (string) ( $response['po_number'] ?? '' )
                ),
                'checked_at' => current_time( 'mysql' ),
                'po_number'  => (string) ( $response['po_number'] ?? '' ),
            ];

        MMI_Settings::set( self::LAST_ORDER_API_CHECK_KEY, wp_json_encode( $result ) );

        return $result;
    }

    public static function get_last_order_api_check(): array {
        $raw     = MMI_Settings::get( self::LAST_ORDER_API_CHECK_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [ 'ok' => null, 'message' => __( 'Never checked.', 'mmi-xchange-integration' ), 'checked_at' => null ];
    }

    /**
     * Records an account-level issue (hold, API-ordering-disabled, etc.)
     * observed from any Xchange call, so it surfaces on the Account tab
     * without needing to dig through logs.
     */
    public static function record_issue( string $message ): void {
        MMI_Settings::set( self::LAST_ISSUE_KEY, wp_json_encode( [
            'message'     => $message,
            'occurred_at' => current_time( 'mysql' ),
        ] ) );
    }

    /**
     * record_issue() had no matching "clear" — a failure recorded here stuck
     * on the MannMade dashboard's "Needs attention" panel forever, even long
     * after the underlying problem was fixed, since nothing ever overwrote
     * it on a later success. Found live: the dashboard was still showing
     * guard_production_only()'s stale "order placement is disabled on this
     * environment" message from before MMI_Environment was rebuilt, well
     * after a real successful order placement had already proven the
     * account itself was never the problem. Called from
     * MMI_Xchange_API::place_order()'s success path.
     */
    public static function clear_issue(): void {
        MMI_Settings::delete( self::LAST_ISSUE_KEY );
    }

    public static function get_last_issue(): ?array {
        $raw     = MMI_Settings::get( self::LAST_ISSUE_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : null;
    }

    public static function mode(): string {
        return ( new MMI_Xchange_API_Client() )->api_mode();
    }
}
