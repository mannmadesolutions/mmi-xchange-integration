<?php
/**
 * MMI_Xchange_Webhooks
 *
 * Registers this site's REST receiver with XChange's webhook mechanism
 * (mmi-admin/docs/api-reference/xchange-api.md, full spec captured 2026-08-28)
 * for `purchase_order_updates` and `shipment_updates` — a push-based
 * replacement for polling that hits only the safe REST API, never
 * MMI_Xchange_Web_Session's portal-session-evicting scrape. Registration
 * is a deliberate, explicit admin action (see class-xchange-ajax.php's
 * register_webhooks()) — not automatic — since it opens a new public,
 * unauthenticated endpoint on this server (see class-xchange-webhook-receiver.php).
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Webhooks {

    const EVENTS = [ 'purchase_order_updates', 'shipment_updates' ];

    private static function hook_id_key( string $event ): string {
        return 'mmi_xchange_webhook_' . $event . '_hook_id';
    }

    private static function secret_key( string $event ): string {
        return 'mmi_xchange_webhook_' . $event . '_secret';
    }

    /**
     * Registers this site's receiver for every event in self::EVENTS.
     * Idempotent to call again (e.g. after the receiver URL changes) — a
     * fresh registration simply overwrites the previously stored hook_id
     * /secret for that event; XChange's docs don't describe a way to update
     * an endpoint in place without losing the old secret, so re-registering
     * is the correct remedy per that doc's own note.
     *
     * @return array<string,true|WP_Error> event => true on success, or the
     *                                      WP_Error for that event on failure.
     */
    public static function register_all(): array {
        $client   = new MMI_Xchange_API_Client();
        $endpoint = rest_url( 'mmi-xchange/v1/webhook' );
        $results  = [];

        foreach ( self::EVENTS as $event ) {
            $result = $client->register_webhook( $event, $endpoint );

            if ( is_wp_error( $result ) ) {
                MMI_Logger::error(
                    "Failed to register XChange webhook for {$event}: " . $result->get_error_message(),
                    [], 'xchange', 'MMI_Xchange_Webhooks'
                );
                $results[ $event ] = $result;
                continue;
            }

            MMI_Settings::set( self::hook_id_key( $event ), (string) ( $result['hook_id'] ?? '' ) );
            MMI_Settings::set( self::secret_key( $event ), (string) ( $result['secret'] ?? '' ) );
            MMI_Logger::info(
                "Registered XChange webhook for {$event} (hook_id {$result['hook_id']}).",
                [], 'xchange', 'MMI_Xchange_Webhooks'
            );
            $results[ $event ] = true;
        }

        return $results;
    }

    /**
     * @return string The stored secret for $event, or '' if not registered.
     */
    public static function get_secret( string $event ): string {
        return (string) MMI_Settings::get( self::secret_key( $event ), '' );
    }

    public static function is_registered( string $event ): bool {
        return self::get_secret( $event ) !== '';
    }
}
