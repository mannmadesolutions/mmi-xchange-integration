<?php
/**
 * MMI_Xchange_Guest_Email_Request
 *
 * Automated version of the Fulfillment Queue's manual "Link to Customer"
 * ("mmi-x-guest-convert") action, scoped to orders this plugin actually
 * cares about: a newly-imported Reverb order carrying at least one
 * XChange-sourced software line item (same detection MMI_Xchange_Fulfillment_
 * Queue's Recent Orders panel already uses). Sends the buyer a Reverb
 * message — via mmi-reverb-integration's own API client — asking for their
 * real email address, since Reverb's buyer-privacy relay means the order's
 * billing address is almost always a tokenized proxy that can't receive
 * mail.
 *
 * Ownership: this class gets first refusal over mmi-reverb-integration's own
 * generic MMI_Reverb_Email_Request_Manager::maybe_request_email() — see that
 * method's docblock — because it can send XChange-specific copy and this is
 * where these orders actually get fulfilled. When mmi-xchange-integration
 * isn't active, MMI_Reverb_Email_Request_Manager's unconditional generic
 * flow already covers every guest order (XChange-sourced or not), so no
 * separate fallback is needed here.
 *
 * Deliberately does NOT poll for the buyer's reply or perform the
 * conversion itself: it writes the exact same order-meta keys
 * MMI_Reverb_Email_Request_Manager defines (STATUS/SENT_AT/CONVERSATION)
 * and hands the order to that class's schedule_reply_check() — its
 * per-order fast Action Scheduler backoff, backed by its 2-hourly
 * check_for_replies() sweep, is the only thing in this suite that polls a
 * Reverb conversation and converts via MMI_Guest_Customer_Converter,
 * regardless of which plugin actually sent the request. One reply-polling
 * mechanism, one conversion mechanism, reused rather than duplicated.
 *
 * The actual send (send()) has two callers: maybe_request_email() below
 * (the automatic trigger, fired once from mmi-reverb-integration's order-
 * import path — see that method's own guards) and
 * MMI_Xchange_Ajax::request_guest_email(), the Fulfillment Queue's manual
 * "📨 Request via Reverb Message" button inside the guest-convert widget
 * (mmi-x-guest-convert / renderManualGuestFormHtml() in admin-xchange.js) —
 * for an order the automatic trigger never got a chance to run on (it
 * predates this feature, or the global auto-request setting is off), or a
 * retry after a prior automatic attempt failed.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Guest_Email_Request {

    /**
     * Called from MMI_Reverb_Email_Request_Manager::maybe_request_email()
     * (if this class is present) before that method's own generic message —
     * see this class's docblock for the priority rationale. All of the
     * "should we even ask" guards (auto-request enabled, no linked customer
     * yet, billing email looks like a relay, not already requested) are
     * already checked by the caller before this runs; this method only
     * adds the one check that's actually this plugin's concern.
     *
     * @param \WC_Order $order
     * @param string    $reverb_order_id
     * @return bool True if this method took ownership of the request (sent
     *              or failed to send) — the caller must not also send its
     *              own generic message. False means "not our concern" (no
     *              XChange item on this order, or the Reverb API client
     *              isn't available) — the caller should proceed with its
     *              own fallback.
     */
    public static function maybe_request_email( \WC_Order $order, string $reverb_order_id ): bool {
        if ( ! class_exists( 'MMI_Reverb_API_Client' ) || ! class_exists( 'MMI_Reverb_Email_Request_Manager' ) ) {
            return false;
        }

        if ( ! class_exists( 'MMI_Xchange_Fulfillment_Queue' ) || ! MMI_Xchange_Fulfillment_Queue::has_xchange_item( $order ) ) {
            return false;
        }

        self::send( $order, $reverb_order_id, 'Automatically requested' );

        return true;
    }

    /**
     * Sends the actual Reverb message and records the result in order meta
     * — no gating of its own beyond a valid Reverb order id; both callers
     * (see this class's docblock) own their own preconditions before
     * reaching here, since they're deliberately different (automatic vs.
     * an explicit manual click, which should be allowed to (re)send even
     * when a prior attempt already left a status).
     *
     * @param \WC_Order $order
     * @param string    $reverb_order_id
     * @param string    $verb Leading verb for the order note, distinguishing
     *                        an automatic send ("Automatically requested…")
     *                        from a manual one ("Manually requested…").
     * @return array{success:bool, message:string}
     */
    public static function send( \WC_Order $order, string $reverb_order_id, string $verb = 'Manually requested' ): array {
        $first = trim( $order->get_billing_first_name() ) ?: 'there';

        // One request covers the buyer's whole "Fulfill together" group
        // (mmi-reverb-integration 2.40.0+) — list every item so they know
        // one answer is enough.
        $group_text = method_exists( 'MMI_Reverb_Email_Request_Manager', 'group_items_text' )
            ? MMI_Reverb_Email_Request_Manager::group_items_text( $order )
            : '';

        $message  = "Hi {$first}!\n\n";
        $message .= "What's a good email to send your software details??\n\n";
        $message .= $group_text;
        $message .= "Thanks!\n";
        $message .= "— MannMade Solutions";

        $result = MMI_Reverb_API_Client::instance()->send_message_for_order( $reverb_order_id, $message );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_code() === 'no_conversation'
                ? MMI_Reverb_Email_Request_Manager::STATUS_NO_CONVERSATION
                : MMI_Reverb_Email_Request_Manager::STATUS_FAILED;

            $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_STATUS, $status );
            $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_ERROR, $result->get_error_message() );
            $order->save();

            MMI_Logger::warn(
                "Email-request (XChange software order): failed to send/start conversation for order {$reverb_order_id}: " . $result->get_error_message(),
                [ 'order_id' => $order->get_id() ],
                'xchange',
                'MMI_Xchange_Guest_Email_Request'
            );

            return [
                'success' => false,
                'message' => __( 'Reverb API error: ', 'mmi-xchange-integration' ) . $result->get_error_message(),
            ];
        }

        $conversation_id = $result;
        $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_STATUS, MMI_Reverb_Email_Request_Manager::STATUS_SENT );
        $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_SENT_AT, current_time( 'mysql' ) );
        $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_CONVERSATION, $conversation_id );
        $order->update_meta_data( MMI_Reverb_Email_Request_Manager::META_ERROR, '' );
        // A manual send makes this order its own request again, even if it
        // had joined a same-buyer sibling's (mmi-reverb-integration 2.39.0+).
        if ( defined( 'MMI_Reverb_Email_Request_Manager::META_SHARED_WITH' ) ) {
            $order->delete_meta_data( MMI_Reverb_Email_Request_Manager::META_SHARED_WITH );
        }
        $order->add_order_note( "{$verb} the buyer's real email address via Reverb message (XChange software order; buyer's current address looks like a marketplace relay/proxy)." );
        $order->save();

        // Fast reply detection (mmi-reverb-integration 2.38.0+). Guarded so
        // an older Reverb plugin still works — its 30-min sweep finds the
        // order from the meta above alone.
        if ( method_exists( 'MMI_Reverb_Email_Request_Manager', 'schedule_reply_check' ) ) {
            MMI_Reverb_Email_Request_Manager::schedule_reply_check( $order->get_id() );
        }

        // The buyer's other software orders (the same set the Fulfillment
        // Queue's bundle groups show — both keyed by Reverb buyer ID) join
        // this request instead of each getting their own message.
        $joined = method_exists( 'MMI_Reverb_Email_Request_Manager', 'share_request_with_siblings' )
            ? MMI_Reverb_Email_Request_Manager::share_request_with_siblings( $order )
            : [];

        MMI_Logger::info(
            "Email-request (XChange software order) sent for order {$reverb_order_id}",
            [ 'order_id' => $order->get_id(), 'conversation_id' => $conversation_id ],
            'xchange',
            'MMI_Xchange_Guest_Email_Request'
        );

        $message = __( 'Email request sent via Reverb.', 'mmi-xchange-integration' );
        if ( $joined ) {
            $message .= ' ' . sprintf(
                /* translators: %s: comma-separated order numbers */
                __( 'It also covers orders %s — the buyer\'s one reply converts them all.', 'mmi-xchange-integration' ),
                implode( ', ', array_map( static fn( $o ) => '#' . $o->get_order_number(), $joined ) )
            );
        }

        return [
            'success' => true,
            'message' => $message,
        ];
    }
}
