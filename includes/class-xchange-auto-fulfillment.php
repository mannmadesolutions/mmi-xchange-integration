<?php
/**
 * MMI_Xchange_Auto_Fulfillment
 *
 * Everything after the Fulfillment Queue's "Fulfill" click, without a second
 * click: once the PO is placed (the one human, money-committing decision —
 * MMI_Xchange_Ajax::place_order()), this emails the customer their license,
 * marks the item fulfilled and completes the WC order — the same steps the
 * Email Customer modal's "Send" performs (MMI_Xchange_Ajax::
 * send_fulfillment_email()), with the same server-side product identity.
 * Completing the order fires `mmi_xchange_order_fulfilled`, which
 * mmi-reverb-integration uses to message the Reverb buyer and mark the
 * Reverb order shipped (MMI_Reverb_Fulfillment_Sync).
 *
 * It only sends when nothing needs a person's judgment: a valid, non-relay
 * customer email, a recorded PO, and a license key. Otherwise the result is
 * 'review' and the caller opens the Email Customer modal exactly as before.
 * A license XChange hasn't posted yet gives 'pending': retries run on Action
 * Scheduler (RETRY_OFFSETS) using the stateless lookup only — never the CCSA
 * portal login (operational-continuity.md Rule 13). The admin's own click
 * may use the full lookup, as the modal's auto-fill always has.
 *
 * Combined ("Fulfill together") groups get the same treatment via
 * attempt_bundle(), minus the retries: an incomplete group opens the combined
 * modal instead.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Auto_Fulfillment {

    /** 'yes' (default) / 'no' — Settings tab checkbox. */
    const SETTING = 'mmi_xchange_auto_send_fulfillment_email';

    /**
     * 'yes' (default) / 'no' — skip WooCommerce's own "Completed order"
     * customer email when the order completes because every item's license
     * email already went out. The license email is the delivery; a second
     * "your order is complete" minutes later only adds noise.
     */
    const SKIP_COMPLETED_EMAIL = 'mmi_xchange_skip_wc_completed_email';

    const RETRY_HOOK  = 'mmi_xchange_auto_fulfill_retry';
    const RETRY_GROUP = 'mmi-xchange-auto-fulfill';

    /** Seconds before each background retry: 2, 5, 15, 30, 60 min (~2 h total). */
    const RETRY_OFFSETS = [ 120, 300, 900, 1800, 3600 ];

    const STATUS_SENT    = 'sent';
    const STATUS_PENDING = 'pending';
    const STATUS_REVIEW  = 'review';

    public static function init(): void {
        add_action( self::RETRY_HOOK, [ __CLASS__, 'run_retry' ], 10, 3 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', [ __CLASS__, 'maybe_skip_completed_email' ], 20, 2 );
    }

    /**
     * Only for orders made entirely of XChange items that were all emailed
     * (fulfillment_completion_status()) — a mixed order, or one completed by
     * hand without the license email, still gets WooCommerce's email.
     *
     * @param bool          $enabled
     * @param WC_Order|null $order
     */
    public static function maybe_skip_completed_email( $enabled, $order ) {
        if ( ! $enabled || ! $order instanceof \WC_Order || MMI_Settings::get( self::SKIP_COMPLETED_EMAIL, 'yes' ) === 'no' ) {
            return $enabled;
        }
        if ( empty( MMI_Xchange_Fulfillment_Queue::fulfillment_completion_status( $order )['fully_fulfilled'] ) ) {
            return $enabled;
        }
        if ( ! $order->get_meta( '_mmi_xchange_completed_email_skipped' ) ) {
            $order->update_meta_data( '_mmi_xchange_completed_email_skipped', current_time( 'mysql' ) );
            $order->add_order_note( __( 'WooCommerce\'s "Completed order" email skipped: the license email already went to the customer (XChange Settings).', 'mmi-xchange-integration' ) );
            $order->save();
        }
        return false;
    }

    public static function is_enabled(): bool {
        return MMI_Settings::get( self::SETTING, 'yes' ) !== 'no';
    }

    /**
     * Emails one order item's license, if everything needed is known.
     *
     * @param bool $interactive True inside the admin's own request (may use
     *                          the portal lookup, schedules retries); false
     *                          from a background retry.
     * @return array{status:string, message:string, order_status?:?string, completion?:array}
     */
    public static function attempt( int $order_id, string $sku, bool $interactive ): array {
        if ( ! self::is_enabled() ) {
            return self::review( __( 'Automatic customer email is off (Settings) — review and send it.', 'mmi-xchange-integration' ) );
        }
        if ( ! class_exists( 'MMI_Software_Fulfillment' ) || ! class_exists( 'MMI_Xchange_Fulfillment_Email' ) ) {
            return self::review( __( 'Fulfillment email system unavailable.', 'mmi-xchange-integration' ) );
        }

        $order = wc_get_order( $order_id );
        $line  = $order ? MMI_Software_Fulfillment::find_item( $order, $sku ) : null;
        if ( ! $order || ! $line ) {
            return self::review( sprintf( __( '%1$s is not an XChange item on order #%2$d.', 'mmi-xchange-integration' ), $sku, $order_id ) );
        }
        if ( $order->has_status( [ 'cancelled', 'refunded', 'failed' ] ) ) {
            return self::review( sprintf( __( 'Order #%1$s is %2$s — not emailing the customer.', 'mmi-xchange-integration' ), $order->get_order_number(), $order->get_status() ) );
        }

        $record = MMI_Software_Fulfillment::get_record( $line ) ?? [];
        if ( ( $record['status'] ?? '' ) === MMI_Software_Fulfillment::STATUS_FULFILLED ) {
            return [
                'status'  => self::STATUS_SENT,
                'message' => sprintf( __( '%1$s on order #%2$s was already emailed to the customer.', 'mmi-xchange-integration' ), $sku, $order->get_order_number() ),
            ];
        }
        $po = (string) ( $record['po_number'] ?? '' );
        if ( $po === '' ) {
            return self::review( sprintf( __( 'No XChange PO is recorded for %1$s on order #%2$s.', 'mmi-xchange-integration' ), $sku, $order->get_order_number() ) );
        }

        // A background retry must not re-send what an admin already sent by
        // hand from the modal ("Email Only" leaves the item unfulfilled).
        if ( ! $interactive && self::emailed_since( $order_id, (string) ( $record['placed_at'] ?? '' ) ) ) {
            return self::review( __( 'An email was already sent for this order from the Fulfillment Queue — automatic send stopped.', 'mmi-xchange-integration' ) );
        }

        $to = self::customer_email( $order );
        if ( $to === '' ) {
            return self::review( __( "The customer's real email address isn't known yet (still a marketplace relay) — confirm it, then send.", 'mmi-xchange-integration' ) );
        }

        $delivery = self::delivery_data( $record, $po, $interactive );
        if ( $delivery['license_key'] === '' ) {
            if ( $interactive ) {
                self::schedule_retry( $order_id, $sku, 0 );
            }
            return [
                'status'  => self::STATUS_PENDING,
                'message' => sprintf(
                    /* translators: 1: PO number, 2: order number */
                    __( 'PO %1$s placed for order #%2$s. XChange hasn\'t posted the license yet — the customer email goes out automatically once it does (checked for about 2 hours).', 'mmi-xchange-integration' ),
                    $po,
                    $order->get_order_number()
                ),
            ];
        }

        $preview = class_exists( 'MMI_Xchange_Order_Sync' ) ? ( MMI_Xchange_Order_Sync::preview_product( $sku, $order_id ) ?? [] ) : [];
        $data    = MMI_Xchange_Ajax::enrich_email_data( [
            'customer_name'     => self::customer_name( $order ),
            'software_name'     => $line->get_name(),
            'software_logo_url' => '',
            'license_key'       => $delivery['license_key'],
            'download_url'      => $delivery['download_url'],
            'support_url'       => $delivery['support_url'],
            'po_number'         => $po,
            'auth'              => $delivery['auth'],
            'sku'               => $sku,
            'our_sku'           => (string) ( $preview['our_sku'] ?? '' ),
            'vendor_name'       => (string) ( ( $preview['vendor_name'] ?? '' ) ?: $delivery['vendor_name'] ),
            // What the customer paid — same source as the modal's realPrice.
            'price'             => (float) $line->get_total(),
            'currency'          => $order->get_currency(),
        ], 0 );

        $result = MMI_Xchange_Fulfillment_Email::send( $to, $data, $order_id );
        if ( ! $result['success'] ) {
            return self::review( sprintf( __( 'Automatic email failed: %s', 'mmi-xchange-integration' ), $result['message'] ) );
        }

        $finished = MMI_Xchange_Ajax::finalize_single_send( $order_id, $data );
        $order->add_order_note( sprintf(
            /* translators: 1: recipient, 2: PO number */
            __( 'License email sent automatically to %1$s after Fulfill (PO #%2$s).', 'mmi-xchange-integration' ),
            $to,
            $po
        ) );

        MMI_Logger::info( "Auto-fulfilled order {$order_id} / {$sku} (PO {$po}) to {$to}", [], 'xchange', 'MMI_Xchange_Auto_Fulfillment' );

        return [
            'status'       => self::STATUS_SENT,
            'message'      => sprintf(
                /* translators: 1: order number, 2: recipient, 3: PO number, 4: order status */
                __( 'Order #%1$s: license emailed to %2$s (PO %3$s). Order status: %4$s.', 'mmi-xchange-integration' ),
                $order->get_order_number(),
                $to,
                $po,
                (string) ( $finished['order_status'] ?? 'unchanged' )
            ),
            'order_status' => $finished['order_status'],
            'completion'   => $finished['completion'],
        ];
    }

    /**
     * One combined email for a "Fulfill together" group, when every item is
     * placed and has a license, and every order has the same real email.
     * Stateless lookups only — one portal login per item could outlast the
     * request; the combined modal has per-item Fetch buttons for that.
     *
     * @param array<int,array{order_id:int, sku:string}> $pairs
     * @return array{status:string, message:string, orders?:array}
     */
    public static function attempt_bundle( array $pairs ): array {
        if ( ! self::is_enabled() ) {
            return self::review( __( 'Automatic customer email is off (Settings) — review and send it.', 'mmi-xchange-integration' ) );
        }
        if ( ! class_exists( 'MMI_Software_Fulfillment' ) ) {
            return self::review( __( 'Software fulfillment core is unavailable.', 'mmi-xchange-integration' ) );
        }

        $to          = '';
        $first_order = null;
        $email_items = [];
        foreach ( $pairs as $pair ) {
            $order = wc_get_order( (int) $pair['order_id'] );
            $line  = $order ? MMI_Software_Fulfillment::find_item( $order, (string) $pair['sku'] ) : null;
            if ( ! $order || ! $line ) {
                return self::review( __( 'An item in this group is no longer on its order — review the combined email.', 'mmi-xchange-integration' ) );
            }

            $order_to = self::customer_email( $order );
            if ( $order_to === '' || ( $to !== '' && strcasecmp( $to, $order_to ) !== 0 ) ) {
                return self::review( __( "The orders in this group don't share one confirmed customer email — review the combined email.", 'mmi-xchange-integration' ) );
            }
            $to          = $order_to;
            $first_order = $first_order ?? $order;

            $record   = MMI_Software_Fulfillment::get_record( $line ) ?? [];
            $po       = (string) ( $record['po_number'] ?? '' );
            $delivery = $po !== '' ? self::delivery_data( $record, $po, false ) : null;
            if ( $delivery === null || $delivery['license_key'] === '' ) {
                return self::review( sprintf(
                    /* translators: %s: product name */
                    __( 'No license on file yet for %s — review the combined email (Fetch from XChange).', 'mmi-xchange-integration' ),
                    $line->get_name()
                ) );
            }

            $email_items[] = array_merge( MMI_Xchange_Ajax::bundle_item_display( (string) $pair['sku'] ), [
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'sku'          => (string) $pair['sku'],
                'po_number'    => $po,
                'license_key'  => $delivery['license_key'],
                'download_url' => $delivery['download_url'],
                'support_url'  => $delivery['support_url'],
            ] );
        }
        if ( ! $email_items ) {
            return self::review( __( 'No items to fulfill.', 'mmi-xchange-integration' ) );
        }

        $customer_name = trim( $first_order->get_billing_first_name() );
        $result        = MMI_Software_Fulfillment::send_bundle_email( $to, $customer_name, $email_items, array_column( $email_items, 'order_id' ) );
        if ( ! $result['success'] ) {
            return self::review( sprintf( __( 'Automatic email failed: %s', 'mmi-xchange-integration' ), $result['message'] ) );
        }

        $orders = MMI_Xchange_Ajax::finalize_bundle_send( $email_items );
        foreach ( array_keys( $orders ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( sprintf( __( 'Combined license email sent automatically to %s after Fulfill together.', 'mmi-xchange-integration' ), $to ) );
            }
        }

        return [
            'status'  => self::STATUS_SENT,
            'message' => sprintf(
                /* translators: 1: item count, 2: recipient */
                __( 'Combined email for %1$d items sent to %2$s.', 'mmi-xchange-integration' ),
                count( $email_items ),
                $to
            ),
            'orders'  => $orders,
        ];
    }

    public static function schedule_retry( int $order_id, string $sku, int $attempt ): void {
        if ( ! function_exists( 'as_schedule_single_action' ) || ! isset( self::RETRY_OFFSETS[ $attempt ] ) ) {
            return;
        }
        $args = [ $order_id, $sku, $attempt ];
        if ( as_has_scheduled_action( self::RETRY_HOOK, $args, self::RETRY_GROUP ) ) {
            return;
        }
        as_schedule_single_action( time() + self::RETRY_OFFSETS[ $attempt ], self::RETRY_HOOK, $args, self::RETRY_GROUP );
    }

    /**
     * Action Scheduler callback (RETRY_HOOK).
     */
    public static function run_retry( $order_id, $sku, $attempt ): void {
        $order_id = (int) $order_id;
        $sku      = (string) $sku;
        $attempt  = (int) $attempt;

        $result = self::attempt( $order_id, $sku, false );
        if ( $result['status'] !== self::STATUS_PENDING ) {
            if ( $result['status'] === self::STATUS_REVIEW && ( $order = wc_get_order( $order_id ) ) ) {
                $order->add_order_note( sprintf( __( 'Automatic license email not sent for %1$s: %2$s', 'mmi-xchange-integration' ), $sku, $result['message'] ) );
            }
            return;
        }

        if ( isset( self::RETRY_OFFSETS[ $attempt + 1 ] ) ) {
            self::schedule_retry( $order_id, $sku, $attempt + 1 );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note( sprintf(
                /* translators: %s: SKU */
                __( 'XChange still has no license for %s about 2 hours after the PO — send the customer email from the Fulfillment Queue (Fetch from XChange / paste the key).', 'mmi-xchange-integration' ),
                $sku
            ) );
        }
        MMI_Logger::warn( "Auto-fulfill gave up waiting for a license: order {$order_id} / {$sku}", [], 'xchange', 'MMI_Xchange_Auto_Fulfillment' );
    }

    /**
     * License/download/support for an item: its placement record first
     * (place_order() stores what XChange returned), then the order document.
     *
     * @return array{license_key:string, download_url:string, support_url:string, auth:string, vendor_name:string}
     */
    private static function delivery_data( array $record, string $po, bool $interactive ): array {
        $data = [
            'license_key'  => (string) ( $record['license_key'] ?? '' ),
            'download_url' => (string) ( $record['download_url'] ?? '' ),
            'support_url'  => (string) ( $record['support_url'] ?? '' ),
            'auth'         => '',
            'vendor_name'  => '',
        ];
        if ( ! class_exists( 'MMI_Xchange_Order_Sync' ) ) {
            return $data;
        }
        if ( $data['license_key'] !== '' && $data['download_url'] !== '' && $data['support_url'] !== '' ) {
            return $data;
        }

        $doc = $interactive && $data['license_key'] === ''
            ? MMI_Xchange_Order_Sync::fetch_order_document( $po )
            : MMI_Xchange_Order_Sync::fetch_order_document_stateless( $po );
        if ( ! is_array( $doc ) ) {
            return $data;
        }
        foreach ( [ 'license_key', 'download_url', 'support_url', 'auth', 'vendor_name' ] as $key ) {
            if ( $data[ $key ] === '' && ! empty( $doc[ $key ] ) ) {
                $data[ $key ] = (string) $doc[ $key ];
            }
        }
        return $data;
    }

    /**
     * The order's billing email when it's a real, deliverable address — ''
     * while it's still a marketplace relay.
     */
    private static function customer_email( \WC_Order $order ): string {
        $email = sanitize_email( $order->get_billing_email() );
        if ( $email === '' || ! is_email( $email ) ) {
            return '';
        }
        if ( class_exists( 'MMI_Guest_Customer_Converter' ) && MMI_Guest_Customer_Converter::looks_like_relay_email( $email ) ) {
            return '';
        }
        return $email;
    }

    /** Same name the Email Customer modal pre-fills (the queue's customer_name). */
    private static function customer_name( \WC_Order $order ): string {
        return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    }

    /** A successful customer email for this order at or after $since. */
    private static function emailed_since( int $order_id, string $since ): bool {
        $history = MMI_Xchange_Fulfillment_Email::get_history( $order_id );
        if ( class_exists( 'MMI_Software_Fulfillment' ) ) {
            $history = array_merge( $history, MMI_Software_Fulfillment::get_email_log( $order_id ) );
        }
        foreach ( $history as $entry ) {
            if ( ! empty( $entry['success'] ) && ( $since === '' || strcmp( (string) ( $entry['sent_at'] ?? '' ), $since ) >= 0 ) ) {
                return true;
            }
        }
        return false;
    }

    private static function review( string $message ): array {
        return [ 'status' => self::STATUS_REVIEW, 'message' => $message ];
    }
}
