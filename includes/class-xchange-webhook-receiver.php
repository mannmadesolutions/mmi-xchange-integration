<?php
/**
 * MMI_Xchange_Webhook_Receiver
 *
 * Public, unauthenticated REST endpoint XChange POSTs to for registered
 * webhook events (see class-xchange-webhooks.php for registration).
 * Unauthenticated by design — verified via HMAC-SHA256 against the
 * per-event secret XChange issued at registration time, matching how
 * XChange itself calls in (no WP nonce/login is possible for a 3rd-party
 * server). Per mmi-admin/docs/api-reference/xchange-api.md's own
 * "real implementation gotcha" note and this project's AGENTS.md Rule 9,
 * this must acknowledge within 5 seconds — real work (re-fetching the
 * affected PO) is dispatched to Action Scheduler, never run inline.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Webhook_Receiver {

    const REST_NAMESPACE = 'mmi-xchange/v1';
    const AS_GROUP        = 'mmi-xchange';
    const AS_HOOK          = 'mmi_xchange_webhook_process_event';

    /**
     * Replay window: a byte-identical, already-accepted delivery seen again
     * within this many seconds is acknowledged (200) but not re-queued.
     * XChange's documented payload carries no timestamp/nonce field to bind
     * the HMAC to, so de-duplicating on the verified body hash is the
     * replay protection available here. The queued work is itself an
     * idempotent REST re-fetch, so a replay outside the window costs one
     * throttled read — never a write, purchase, or customer email.
     */
    const REPLAY_WINDOW = DAY_IN_SECONDS;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
        add_action( self::AS_HOOK, [ __CLASS__, 'process_event' ], 10, 2 );
    }

    public static function register_route(): void {
        // Public by necessity (XChange's servers can't log in or carry a WP
        // nonce). Authentication is handle()'s HMAC-SHA256 check against the
        // per-event secret XChange issued at registration, compared with
        // hash_equals(), plus body-hash replay de-duplication.
        register_rest_route( self::REST_NAMESPACE, '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $raw_body = $request->get_body();
        $payload  = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) || ! isset( $payload['event'], $payload['hash'] ) ) {
            MMI_Logger::warn( 'XChange webhook received a malformed payload (missing event/hash).', [], 'xchange', 'MMI_Xchange_Webhook_Receiver' );
            return new WP_REST_Response( [ 'success' => false ], 400 );
        }

        // Whitelisted before use — the raw value is attacker-controlled
        // (unauthenticated request) and would otherwise flow into a settings
        // key lookup and the log file.
        $event = is_string( $payload['event'] ) ? $payload['event'] : '';

        if ( ! class_exists( 'MMI_Xchange_Webhooks' ) || ! in_array( $event, MMI_Xchange_Webhooks::EVENTS, true ) || ! MMI_Xchange_Webhooks::is_registered( $event ) ) {
            MMI_Logger::warn( "XChange webhook received for unknown/unregistered event '" . sanitize_key( substr( (string) $event, 0, 64 ) ) . "'.", [], 'xchange', 'MMI_Xchange_Webhook_Receiver' );
            return new WP_REST_Response( [ 'success' => false ], 400 );
        }

        if ( ! is_string( $payload['hash'] ) || ! self::verify_hmac( $raw_body, $payload['hash'], MMI_Xchange_Webhooks::get_secret( $event ) ) ) {
            MMI_Logger::warn( "XChange webhook for '{$event}' failed HMAC verification — payload rejected.", [], 'xchange', 'MMI_Xchange_Webhook_Receiver' );
            return new WP_REST_Response( [ 'success' => false ], 401 );
        }

        // Replay de-duplication (see REPLAY_WINDOW). Only reached after the
        // HMAC passed, so an unauthenticated caller can't fill this cache.
        $replay_key = 'mmi_xchange_wh_' . substr( hash( 'sha256', $raw_body ), 0, 40 );
        if ( get_transient( $replay_key ) ) {
            MMI_Logger::info( "XChange webhook for '{$event}' is a duplicate delivery — acknowledged, not re-queued.", [], 'xchange', 'MMI_Xchange_Webhook_Receiver' );
            return new WP_REST_Response( [ 'success' => true ], 200 );
        }
        set_transient( $replay_key, 1, self::REPLAY_WINDOW );

        $data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];

        // Acknowledge within XChange's 5-second window, then do the real
        // work (re-fetching affected POs) via Action Scheduler — never
        // inline, per this project's Rule 9 and xchange-api.md's own note
        // naming this exact case.
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), self::AS_HOOK, [ $event, $data ], self::AS_GROUP );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Verifies against the RAW request body via string manipulation, not
     * decode-then-reencode — xchange-api.md's own gotcha: PHP's json_encode()
     * on a decoded-then-modified payload isn't guaranteed byte-identical to
     * what XChange originally hashed (slash-escaping, key order, number
     * formatting), so re-serializing before hashing can make every payload
     * silently fail verification. Every sample payload in the vendor doc
     * shows "hash" as the last key before the closing brace, so that's the
     * assumption encoded here — not confirmed against a live payload yet
     * (see the Orders tab's webhook status card / this plugin's log for the
     * first real "Send a Test" delivery's outcome).
     */
    private static function verify_hmac( string $raw_body, string $received_hash, string $secret ): bool {
        if ( $secret === '' ) {
            return false;
        }

        $stripped = preg_replace( '/,\s*"hash"\s*:\s*"[^"]*"\s*(?=\})/', '', $raw_body, 1 );
        if ( $stripped === null || $stripped === $raw_body ) {
            // The ",\"hash\":\"...\"" pattern didn't match — payload shape
            // differs from every sample in the doc. Fail closed rather than
            // guess at a different strip position.
            return false;
        }

        $expected = base64_encode( hash_hmac( 'sha256', $stripped, $secret, true ) );
        return hash_equals( $expected, $received_hash );
    }

    /**
     * Actual work for a verified event — re-fetch every PO named in the
     * payload's `data` array via the safe REST API. `po_id` here is
     * XChange's own webhook-payload field name; whether it matches this
     * codebase's `po_number` (reseller-assigned) is unconfirmed until a
     * real event is observed — logged either way so a mismatch is visible
     * rather than silently swallowed.
     */
    public static function process_event( string $event, array $data ): void {
        if ( class_exists( 'MMI_Settings' ) ) {
            // Honest state signal for the Orders tab — "is the push
            // mechanism actually alive," separate from the CCSA/Invoice
            // History "Last confirmed via Full Sync" timestamp, since these
            // two answer different questions about different data sources.
            MMI_Settings::set( 'mmi_xchange_webhook_last_received', current_time( 'mysql' ) );
            MMI_Settings::set( 'mmi_xchange_webhook_last_event', $event );
        }

        if ( ! class_exists( 'MMI_Xchange_Order_Sync' ) ) {
            return;
        }

        foreach ( $data as $item ) {
            $po = is_array( $item ) && is_scalar( $item['po_id'] ?? null ) ? sanitize_text_field( (string) $item['po_id'] ) : '';
            if ( $po === '' ) {
                MMI_Logger::warn( "XChange webhook event '{$event}' had a data item with no po_id.", [ 'item' => $item ], 'xchange', 'MMI_Xchange_Webhook_Receiver' );
                continue;
            }

            MMI_Xchange_Order_Sync::refresh_po_from_webhook( $po );
        }
    }
}
