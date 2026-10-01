<?php
/**
 * MMI_Xchange_Checkout
 *
 * Storefront checkout fulfillment — ported verbatim (behavior-preserving)
 * from the retired `xchangemarket` plugin. Auto-reserves an Xchange license
 * when an order is placed, finalizes it on completion, voids it on
 * cancellation/abandonment, and displays the resulting license/download/
 * support info to the buyer. Meta keys and control flow are unchanged from
 * the original; only credential storage (MMI_Settings instead of
 * get_option), logging (MMI_Logger instead of a hand-rolled file logger),
 * and outbound-call throttling (via MMI_Xchange_API_Client) have changed.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Checkout {

    public static function init(): void {
        add_action( 'woocommerce_checkout_update_order_meta', [ __CLASS__, 'new_order' ], 1, 1 );
        add_action( 'woocommerce_pre_payment_complete', [ __CLASS__, 'pre_payment_complete' ], 1, 2 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'order_complete' ], 1 );
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'info_display' ], 10, 1 );
        add_action( 'woocommerce_email_order_details', [ __CLASS__, 'email_order_details' ], 10, 4 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'void_on_cancel' ], 10, 3 );
        add_action( 'woocommerce_resume_order', [ __CLASS__, 'void_transaction' ], 1 );
    }

    /**
     * Called during checkout — reserves the matching Xchange order, unless
     * the payment method is one we expect to settle later (in which case
     * reservation happens instead at woocommerce_order_status_completed).
     */
    public static function new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_item_count() <= 0 ) {
            return;
        }

        if ( ! self::auto_fulfill_permitted() ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping automatic XChange reserve — engine mode is live and automatic fulfillment isn't enabled. Fulfill manually from the Recent XChange Orders panel.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        if ( self::is_externally_sourced( $order ) ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping XChange reserve — order was imported from an external marketplace, already sold/fulfilled elsewhere.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        [ $xchange_items, $resolve_sku_to_product_info ] = self::collect_order_items( $order );
        if ( empty( $xchange_items ) ) {
            return;
        }

        $log = "Order {$order_id} created (woocommerce_checkout_update_order_meta)\n";

        if ( $order->get_total() <= 0 ) {
            $log .= "Non-charged order detected\n";
        } else {
            $payment_method  = 'unknown';
            $payment_gateway = wc_get_payment_gateway_by_order( $order );
            if ( $payment_gateway ) {
                $payment_method = $payment_gateway->id;
            }
            $log .= "Payment method: {$payment_method}\n";

            $slow_payment_methods = [ 'unknown', 'cheque', 'cod', 'bacs' ];
            if ( in_array( strtolower( $payment_method ), $slow_payment_methods, true ) ) {
                $log .= "Processing postponed due to delayed payment method\n";
                MMI_Logger::info( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
                return;
            }
        }

        try {
            if ( get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true ) !== '' ) {
                throw new Exception( 'XCHANGE info already exists' );
            }

            $client = new MMI_Xchange_API_Client();
            $log   .= "Attempting to reserve XChange order\n";
            $po     = self::po_number( $order );
            $result = $client->reserve( $po, $xchange_items );

            if ( self::has_api_error( $result ) || empty( $result['transaction_number'] ) ) {
                throw new Exception( 'reserve call failed, error: ' . self::error_text( $result ) );
            }
            $log .= 'Transaction reserve successful ' . $result['transaction_number'] . "\n";

            $existing = get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true );
            if ( $existing !== '' ) {
                $log .= 'Parallel processing detected, voiding new transaction ' . $result['transaction_number'] . "\n";
                $void = $client->void( $result['transaction_number'] );
                if ( self::has_api_error( $void ) ) {
                    throw new Exception( 'Transaction void failed, error: ' . self::error_text( $void ) );
                }
                $log .= "Duplicate transaction void successful, processing stopped\n";
                MMI_Logger::warn( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
            } else {
                update_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, 'tx:' . $result['transaction_number'] );
                $log .= "XChange order reserve complete\n";
                MMI_Logger::info( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
                mmi_xchange_audit( 'order.auto_reserve', [
                    'object_type' => 'order',
                    'object_id'   => $order_id,
                    'outcome'     => 'success',
                    'details'     => [ 'trigger' => 'woocommerce_checkout_update_order_meta', 'transaction' => (string) $result['transaction_number'], 'po' => $po ],
                ] );
            }
        } catch ( Exception $e ) {
            $log    .= 'Processing interrupted: ' . $e->getMessage() . "\n";
            $abort   = MMI_Settings::get( 'mmi_xchange_abort_on_error' ) === 'yes';
            if ( $abort ) {
                $log .= "Customer order creation cancelled due to error\n";
            }
            MMI_Logger::error( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
            mmi_xchange_audit( 'order.auto_reserve', [
                'object_type' => 'order',
                'object_id'   => $order_id,
                'outcome'     => 'failure',
                'details'     => [ 'trigger' => 'woocommerce_checkout_update_order_meta', 'error' => $e->getMessage() ],
            ] );

            if ( $abort ) {
                throw new Exception( (string) MMI_Settings::get( 'mmi_xchange_failed_message' ) );
            }
        }
    }

    /**
     * Primary finalize path (added 2026-08-24). Hooked to
     * `woocommerce_pre_payment_complete`, which WC_Order::payment_complete()
     * fires *before* setting/saving the order's next status — see
     * mmi-hub/docs/XCHANGE_FINALIZE_TIMING_HANDOFF.md for the full
     * investigation. Throwing here aborts payment_complete() cleanly: WC
     * catches the exception, logs it, adds its own order note, and leaves
     * the order in whatever status it had before this call — so an order
     * can never read "completed" while the underlying XChange purchase
     * silently failed.
     *
     * Verified against live gateway code before relying on this hook: the
     * only active Stripe gateway on this store is the official
     * woocommerce-gateway-stripe plugin (mmi-stripe-gateway was deleted from
     * the MMI Suite entirely, 2026-09-17; yith-stripe isn't installed despite
     * a leftover wp_options row), and it calls payment_complete() synchronously from
     * checkout submission and from the post-redirect return/thank-you page
     * — not exclusively from a webhook — so a slow/failing finalize() here
     * is genuinely customer-facing latency, not just a backend concern.
     *
     * Does NOT cover bacs/cheque/cod/unknown ("slow") payment methods:
     * WooCommerce core's own bacs/cheque/cod gateways call
     * $order->update_status() directly for a non-zero-total order and never
     * call payment_complete() at all, and a manual status change in
     * wp-admin doesn't call it either — confirmed via grep, zero call sites
     * in WooCommerce admin. So this hook simply never fires for that
     * category; order_complete() below remains their only finalize path,
     * not just a fallback for it.
     */
    public static function pre_payment_complete( $order_id, $transaction_id = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_item_count() <= 0 ) {
            return;
        }

        if ( ! self::auto_fulfill_permitted() ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping automatic XChange finalize (pre_payment_complete) — engine mode is live and automatic fulfillment isn't enabled. Fulfill manually from the Recent XChange Orders panel.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        if ( self::is_externally_sourced( $order ) ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping XChange finalize (pre_payment_complete) — order was imported from an external marketplace, already sold/fulfilled elsewhere.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        [ $xchange_items, $resolve_sku_to_product_info ] = self::collect_order_items( $order, true );
        if ( empty( $xchange_items ) ) {
            return;
        }

        try {
            self::finalize_order( $order, $xchange_items, $resolve_sku_to_product_info, 'woocommerce_pre_payment_complete' );
        } catch ( Exception $e ) {
            MMI_Logger::error( "Order {$order_id}: " . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Checkout' );
            self::audit_finalize_failure( (int) $order_id, 'woocommerce_pre_payment_complete', $e->getMessage() );
            $order->add_order_note( __( 'XChange order processing failed — order held at its prior status rather than marked completed. See plugin logs and the Recent XChange Orders panel to fulfill manually.', 'mmi-xchange-integration' ) );

            // Re-thrown so WC_Order::payment_complete() aborts the status
            // transition — this is the entire point of hooking this event.
            throw new Exception( __( 'XChange order processing failed.', 'mmi-xchange-integration' ) );
        }
    }

    /**
     * Fallback finalize path. Hooked to `woocommerce_order_status_completed`
     * for any order that reaches `completed` through a route that never
     * calls payment_complete() at all — an admin manually setting status to
     * Completed in wp-admin, or (the common case in practice) any
     * bacs/cheque/cod/unknown order, since payment_complete() never fires
     * for those (see pre_payment_complete()'s docblock).
     *
     * For an order pre_payment_complete() already finalized successfully,
     * this will also fire a second time once WC sets the order to
     * `completed` — finalize_order() detects the already-saved license JSON
     * and no-ops rather than re-running or logging a false failure.
     */
    public static function order_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_item_count() <= 0 ) {
            return;
        }

        if ( ! self::auto_fulfill_permitted() ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping automatic XChange finalize (order_complete) — engine mode is live and automatic fulfillment isn't enabled. Fulfill manually from the Recent XChange Orders panel.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        if ( self::is_externally_sourced( $order ) ) {
            MMI_Logger::info(
                "Order {$order_id}: skipping XChange finalize — order was imported from an external marketplace, already sold/fulfilled elsewhere.",
                [], 'xchange', 'MMI_Xchange_Checkout'
            );
            return;
        }

        [ $xchange_items, $resolve_sku_to_product_info ] = self::collect_order_items( $order, true );
        if ( empty( $xchange_items ) ) {
            return;
        }

        try {
            self::finalize_order( $order, $xchange_items, $resolve_sku_to_product_info, 'woocommerce_order_status_completed' );
        } catch ( Exception $e ) {
            MMI_Logger::error( "Order {$order_id}: " . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Checkout' );
            self::audit_finalize_failure( (int) $order_id, 'woocommerce_order_status_completed', $e->getMessage() );
            $order->add_order_note( __( 'XChange order processing failed, please see plugin logs for more details.', 'mmi-xchange-integration' ) );
        }
    }

    /**
     * Shared reserve/finalize logic called by both finalize entry points
     * above. Reuses an existing reservation ('tx:' meta) if one exists,
     * otherwise reserves+finalizes in one pass (the "slow payment methods"
     * case). No-ops if this order's XChange info is already the finalized
     * license JSON, so a legitimate second call (order_complete() running
     * after pre_payment_complete() already succeeded) doesn't re-run or log
     * a false failure. Throws on any real failure — callers own logging and
     * the order note.
     */
    private static function finalize_order( \WC_Order $order, array $xchange_items, array $resolve_sku_to_product_info, string $trigger ): void {
        $order_id = $order->get_id();
        $log      = "Order {$order_id} finalize triggered by {$trigger}\n";

        $existing = get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true );

        if ( self::is_already_finalized( $existing ) ) {
            MMI_Logger::debug( "Order {$order_id}: XChange already finalized, skipping ({$trigger})", [], 'xchange', 'MMI_Xchange_Checkout' );
            return;
        }

        $transaction_number = null;
        if ( substr( $existing, 0, 3 ) === 'tx:' ) {
            $transaction_number = substr( $existing, 3 );
            $log .= "Existing transaction number {$transaction_number}\n";
        }

        $client = new MMI_Xchange_API_Client();

        if ( $transaction_number === null ) {
            $log   .= "Attempting to reserve XChange order\n";
            $po     = self::po_number( $order );
            $result = $client->reserve( $po, $xchange_items );

            if ( self::has_api_error( $result ) || empty( $result['transaction_number'] ) ) {
                throw new Exception( 'reserve call failed, error: ' . self::error_text( $result ) );
            }
            $log .= 'Transaction reserve successful ' . $result['transaction_number'] . "\n";

            $existing = get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true );
            if ( $existing !== '' ) {
                $log .= 'Parallel processing detected, voiding new transaction ' . $result['transaction_number'] . "\n";
                $void = $client->void( $result['transaction_number'] );
                if ( self::has_api_error( $void ) ) {
                    throw new Exception( 'Transaction void failed, error: ' . self::error_text( $void ) );
                }
                $log .= "Duplicate transaction void successful\n";

                if ( substr( $existing, 0, 3 ) !== 'tx:' ) {
                    throw new Exception( 'existing transaction is already finalized' );
                }
                $transaction_number = substr( $existing, 3 );
                $log .= "Proceeding with existing transaction: {$transaction_number}\n";
            } else {
                $transaction_number = $result['transaction_number'];
                update_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, 'tx:' . $transaction_number );
            }
        }

        $log      .= "Attempting to finalize transaction {$transaction_number}\n";
        $finalize  = $client->finalize( $transaction_number );

        if ( self::has_api_error( $finalize ) || empty( $finalize['licenses'] ) || ! is_array( $finalize['licenses'] ) ) {
            throw new Exception( 'finalize call failed, error: ' . self::error_text( $finalize ) );
        }

        $license_json = [];
        foreach ( $finalize['licenses'] as $product ) {
            $cached = $resolve_sku_to_product_info[ $product['sku'] ?? '' ] ?? [];
            $entry  = [];

            if ( isset( $product['sku'] ) ) {
                $entry['sku']  = $product['sku'];
                $entry['name'] = $cached['name'] ?? '';
            }
            if ( isset( $product['license_number'] ) ) {
                $entry['license'] = $product['license_number'];
            }
            if ( isset( $product['download_path'] ) ) {
                $entry['download'] = $product['download_path'];
            }
            if ( ( $cached['support'] ?? '' ) !== '' ) {
                $entry['support'] = $cached['support'];
            } elseif ( isset( $product['support_contact'] ) ) {
                $entry['support'] = $product['support_contact'];
            }

            $license_json[] = $entry;
        }

        update_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, wp_json_encode( $license_json ) );

        $log .= "XChange order successful, license information saved\n";
        MMI_Logger::info( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
        mmi_xchange_audit( 'order.auto_finalize', [
            'object_type' => 'order',
            'object_id'   => $order_id,
            'outcome'     => 'success',
            'details'     => [ 'trigger' => $trigger, 'transaction' => (string) $transaction_number, 'skus' => array_column( $xchange_items, 'sku' ) ],
        ] );

        $order->add_order_note( __( 'XChange plugin processing complete.', 'mmi-xchange-integration' ) );
    }

    /**
     * True when $existing already holds the finalized license JSON (as
     * opposed to empty, or a 'tx:' reservation awaiting finalize).
     */
    private static function is_already_finalized( string $existing ): bool {
        if ( $existing === '' || substr( $existing, 0, 3 ) === 'tx:' ) {
            return false;
        }
        return is_array( json_decode( $existing, true ) );
    }

    public static function info_display( $order ) {
        $existing = get_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, true );
        if ( strlen( $existing ) === 0 || substr( $existing, 0, 3 ) === 'tx:' ) {
            return;
        }

        $decoded = json_decode( $existing, true );
        echo $decoded ? self::format_license_html( $decoded ) : '<p>' . esc_html( $existing ) . '</p>';

        MMI_Logger::debug( "Order {$order->get_id()} serial information displayed (woocommerce_order_details_after_order_table)", [], 'xchange', 'MMI_Xchange_Checkout' );
    }

    public static function email_order_details( $order, $sent_to_admin, $plain_text, $email ) {
        $existing = get_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, true );
        if ( strlen( $existing ) === 0 || substr( $existing, 0, 3 ) === 'tx:' ) {
            return;
        }

        $decoded = json_decode( $existing, true );
        echo $decoded ? self::format_license_html( $decoded ) : '<p>' . esc_html( $existing ) . '</p>';

        MMI_Logger::debug( "Order {$order->get_id()} serial information emailed to customer", [], 'xchange', 'MMI_Xchange_Checkout' );
    }

    public static function void_on_cancel( $order_id, $old_status, $new_status ) {
        if ( $new_status !== 'cancelled' ) {
            return;
        }

        $existing = get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true );
        if ( substr( $existing, 0, 3 ) !== 'tx:' ) {
            return;
        }

        MMI_Logger::info( "Order {$order_id} changed to {$new_status} status", [], 'xchange', 'MMI_Xchange_Checkout' );
        self::void_transaction( $order_id );
    }

    public static function void_transaction( $order_id ) {
        if ( empty( $order_id ) ) {
            return;
        }

        $existing = get_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, true );
        if ( substr( $existing, 0, 3 ) !== 'tx:' ) {
            return;
        }

        $transaction_number = substr( $existing, 3 );
        $log = "Order {$order_id} cancelling reserve\n";

        try {
            $client = new MMI_Xchange_API_Client();
            $log   .= "Attempting to void transaction {$transaction_number}\n";
            $void   = $client->void( $transaction_number );

            if ( self::has_api_error( $void ) ) {
                throw new Exception( 'void call failed, error: ' . self::error_text( $void ) );
            }

            update_post_meta( $order_id, XCHANGE_LICENSE_INFO_FIELD_NAME, 'VOID - ' . $existing );
            $log .= "XChange transaction void successful\n";
            MMI_Logger::info( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
            mmi_xchange_audit( 'order.void', [ 'object_type' => 'order', 'object_id' => $order_id, 'outcome' => 'success', 'details' => [ 'transaction' => $transaction_number ] ] );
        } catch ( Exception $e ) {
            $log .= 'Cancellation interrupted: ' . $e->getMessage() . "\n";
            MMI_Logger::error( $log, [], 'xchange', 'MMI_Xchange_Checkout' );
            mmi_xchange_audit( 'order.void', [ 'object_type' => 'order', 'object_id' => $order_id, 'outcome' => 'failure', 'details' => [ 'transaction' => $transaction_number, 'error' => $e->getMessage() ] ] );
        }
    }

    /**
     * Audit record for a failed automatic (checkout-driven) finalize — the
     * reserve/finalize pair is a real supplier purchase made without an
     * admin click, so failures are part of the audit trail, not just logs.
     */
    private static function audit_finalize_failure( int $order_id, string $trigger, string $error ): void {
        mmi_xchange_audit( 'order.auto_finalize', [
            'object_type' => 'order',
            'object_id'   => $order_id,
            'outcome'     => 'failure',
            'details'     => [ 'trigger' => $trigger, 'error' => $error ],
        ] );
    }

    /* ── Helpers ──────────────────────────────────────────────────────────── */

    /**
     * True when this order did NOT originate from this site's own
     * checkout — e.g. imported from Reverb, or a future external
     * marketplace integration — meaning it was already sold (and usually
     * already fulfilled) elsewhere. XChange reserve/finalize/place_order
     * must never run for an order like this: doing so would place a real,
     * live, duplicate purchase for an item the business already sold
     * through another channel. Added 2026-08-24 after this exact failure
     * mode was identified as a real historical incident (every staging/dev
     * clone with this plugin active would auto-issue purchase orders for
     * every open externally-sourced order) — see
     * mmi-admin/docs/api-reference/xchange-api.md and AGENTS.md's Incident
     * History for the full writeup. This is the single source of truth for
     * that check; MMI_Xchange_Fulfillment_Queue's Recent Orders panel calls
     * this too rather than re-deriving its own copy.
     *
     * Delegates to MMI_Marketplace_Order_Sources, which each marketplace-
     * import plugin registers itself into on `init` — e.g. mmi-reverb-
     * integration registers its `_mmi_reverb_order_number` /
     * `_mmi_reverb_uuid` / `payment_method === 'reverb'` check there. That
     * detection logic conceptually belongs to the plugin that owns those
     * meta keys, not here; this method stays as the single call site every
     * checkout guard in this file already uses, generalized to "any
     * registered external marketplace" rather than hardcoding Reverb.
     * (Checked directly against live data 2026-08-24, before this
     * delegation existed: 2,501 of 2,550 real Reverb orders (98.1%) carry
     * the meta, 49 older orders predating the importer setting it don't —
     * payment_method catches those. Either signal alone is enough to
     * block — must fail closed, not require both. See
     * MMI_Marketplace_Order_Sources's Reverb registration for the current
     * implementation of this check.)
     *
     * MMI_Marketplace_Order_Sources lived in mmi-hub, which was deleted
     * suite-wide 2026-09-17 (mmi-hub-elimination migration) without this
     * class being migrated to the shared library or anywhere else — it no
     * longer exists anywhere in this codebase, confirmed via grep. The
     * `class_exists()` guard below therefore now ALWAYS evaluates false, so
     * this returned `false` unconditionally: every order looked
     * not-externally-sourced, silently reopening the exact duplicate-
     * purchase risk this check exists to prevent (see AGENTS.md's "XChange
     * Cross-Channel Duplicate-Order Risk" incident) for any order actually
     * imported from Reverb or another marketplace. Fixed to fail closed
     * instead: until MMI_Marketplace_Order_Sources is rebuilt somewhere
     * (e.g. in mmi-admin's shared library) and mmi-reverb-integration's
     * registration call succeeds again, every order is treated as
     * externally sourced, which blocks automatic XChange reserve/finalize
     * suite-wide and forces manual fulfillment via the Recent XChange
     * Orders panel — the safe direction to fail, since a missed automatic
     * reservation costs a few minutes of admin time and a wrongly-allowed
     * one costs a real duplicate purchase. The same dangling dependency
     * still needs fixing in mmi-xchange-integration's
     * class-xchange-fulfillment-queue.php and mmi-reverb-integration.php's
     * registration call site — out of scope for this file alone.
     */
    public static function is_externally_sourced( \WC_Order $order ): bool {
        if ( ! class_exists( 'MMI_Marketplace_Order_Sources' ) ) {
            return true;
        }
        return MMI_Marketplace_Order_Sources::get_source_for_order( $order ) !== null;
    }

    /**
     * Whether an externally-sourced order's customer identity has since
     * been manually verified via the Guest Customer Converter — an
     * admin has actually confirmed a real, reachable customer/email behind
     * the marketplace's relay address, not merely an order that happens to
     * carry a non-relay-looking email for some unrelated reason. Requires
     * BOTH: the converter's own lock meta (set only by a deliberate,
     * human-initiated conversion — see MMI_Guest_Customer_Converter::
     * ajax_convert()) AND that the current billing email genuinely no
     * longer matches the relay pattern, so a locked-but-since-reverted
     * email (see AGENTS.md's "Guest Customer Conversion Silently
     * Reverted" incident — fixed, but this is belt-and-suspenders) can't
     * satisfy this check.
     *
     * Checked ONLY by the admin-initiated manual fulfillment path (the
     * Recent Orders "Fulfill" button — see MMI_Xchange_Ajax::place_order())
     * as a narrow, explicit exception to is_externally_sourced(). The
     * automatic reserve/finalize hooks in this file (new_order(),
     * pre_payment_complete(), order_complete()) deliberately never consult
     * this — they involve no admin judgment call and must stay blocked for
     * every externally-sourced order regardless of email state. See
     * AGENTS.md's "XChange Cross-Channel Duplicate-Order Risk" incident
     * for why the automatic path exists and must not gain exceptions.
     *
     * MMI_Guest_Customer_Converter also lived in mmi-hub (deleted
     * 2026-09-17) and was never migrated — it no longer exists anywhere in
     * this codebase. Unlike is_externally_sourced() above, this one already
     * fails the safe direction on its own: `false` here means "not
     * verified," which keeps the manual-fulfillment exception closed rather
     * than opening it, so no behavior change was needed. Still dangling and
     * permanently a no-op admin-side until the class is rebuilt somewhere.
     */
    public static function is_verified_after_external_import( \WC_Order $order ): bool {
        if ( ! class_exists( 'MMI_Guest_Customer_Converter' ) ) {
            return false;
        }
        if ( $order->get_meta( MMI_Guest_Customer_Converter::EMAIL_LOCKED_META_KEY ) !== 'yes' ) {
            return false;
        }
        return ! MMI_Guest_Customer_Converter::looks_like_relay_email( $order->get_billing_email() );
    }

    /**
     * Walks order line items, resolving each to its Xchange internal SKU
     * (variation override first, then parent product) and support link.
     *
     * @return array{0: array, 1: array} [xchange_items, sku=>{name,support} map]
     */
    private static function collect_order_items( \WC_Order $order, bool $include_names = false ): array {
        $xchange_items  = [];
        $resolve_info   = [];
        $global_support = $include_names ? MMI_Settings::get( 'mmi_xchange_support_link' ) : null;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product || intval( $item['qty'] ) <= 0 ) {
                continue;
            }

            $xchange_sku  = '';
            $support_link = '';
            $product_name = $product->get_title();

            if ( $product->get_type() === 'variation' ) {
                $variation_id = $item->get_variation_id();
                $xchange_sku  = get_post_meta( $variation_id, XCHANGE_INTERNAL_SKU_NAME_VAR, true );
                $support_link = get_post_meta( $variation_id, XCHANGE_PRODUCT_SUPPORT_LINK_VAR, true );

                $variation_attributes = $product->get_variation_attributes();
                if ( $include_names && ! empty( $variation_attributes ) ) {
                    $product_name .= ' - ' . implode( ', ', array_filter( $variation_attributes ) );
                    $product_name  = rtrim( $product_name, ', ' );
                }
            }

            if ( ( $xchange_sku ?? '' ) === '' ) {
                $xchange_sku = get_post_meta( $product->get_id(), XCHANGE_INTERNAL_SKU_NAME, true );
                if ( ( $xchange_sku ?? '' ) === '' ) {
                    continue;
                }
            }

            if ( $include_names ) {
                if ( ( $support_link ?? '' ) === '' ) {
                    $support_link = get_post_meta( $product->get_id(), XCHANGE_PRODUCT_SUPPORT_LINK, true );
                    if ( ( $support_link ?? '' ) === '' ) {
                        $support_link = $global_support;
                    }
                }
                $resolve_info[ $xchange_sku ] = [ 'name' => $product_name, 'support' => $support_link ];
            }

            $xchange_items[] = [ 'sku' => $xchange_sku, 'quantity' => $item['qty'] ];
        }

        return [ $xchange_items, $resolve_info ];
    }

    private static function po_number( \WC_Order $order ): string {
        return sprintf( '%s%d', self::po_prefix(), $order->get_order_number() );
    }

    public static function po_prefix(): string {
        $prefix = (string) MMI_Settings::get( 'mmi_xchange_po_prefix', '0000' );
        if ( strlen( $prefix ) < 4 ) {
            $prefix = str_repeat( '0', 4 - strlen( $prefix ) ) . $prefix;
            MMI_Settings::set( 'mmi_xchange_po_prefix', $prefix );
        }
        return $prefix;
    }

    private static function error_text( $result ): string {
        if ( is_wp_error( $result ) ) {
            return $result->get_error_message();
        }
        return (string) ( $result['error'] ?? '' );
    }

    /**
     * True when $result is a WP_Error, or a plain array response carrying a
     * non-empty 'error' key. XChange's API can return HTTP 200 with a body
     * shaped like `{"error": "...", "transaction_number": "---voided---"}`
     * on failure — confirmed live 2026-08-24 while testing the
     * finalize-timing redesign against XChange's own test vendor
     * (insufficient stock on the test SKU). A plain `empty($result['transaction_number'])`
     * check alone misses this, since the placeholder transaction_number is
     * non-empty; a present, non-empty 'error' key must be treated as
     * authoritative regardless of what else the response body carries.
     */
    private static function has_api_error( $result ): bool {
        return is_wp_error( $result ) || ! empty( $result['error'] );
    }

    /**
     * Gates all THREE automatic checkout/payment/completion hooks above
     * (new_order, pre_payment_complete, order_complete) — the ones that can
     * fire without any admin action, purely from a customer's own checkout.
     * They already require a product to carry `_xchange_internal_sku`
     * meta, which zero real products currently do — but that's a fact
     * about today's catalog data, not a guarantee. Added 2026-08-24 after
     * being asked to confirm flipping to live mode can never trigger an
     * automatic purchase, only the admin's own "Fulfill" click — that
     * wasn't actually true at the code level before this guard existed, it
     * only happened to be true because of the empty SKU-mapping data. This
     * makes it true regardless of catalog state.
     *
     * Test mode is exempt: it cannot complete a real purchase for any
     * non-test-vendor SKU at all (see AGENTS.md's "XChange engine=test Has
     * No Real-Product Pricing" entry), so letting the automatic path run in
     * test mode is harmless and keeps MMI_Xchange_Finalize_Diagnostic
     * working without needing this flag enabled. The gate only matters
     * once mode is live — that's the only combination with real financial
     * consequences.
     *
     * Manual paths (the Recent Orders "Fulfill" button, the standalone
     * Place Order tab, MMI_Xchange_Finalize_Diagnostic's own diagnostic
     * runs) do NOT go through this method and are entirely unaffected —
     * they're admin-initiated by definition.
     */
    private static function auto_fulfill_permitted(): bool {
        if ( MMI_Settings::get( 'mmi_xchange_production_mode' ) !== 'yes' ) {
            return true;
        }
        return MMI_Settings::get( 'mmi_xchange_auto_fulfill_live' ) === 'yes';
    }

    public static function format_license_html( array $items ): string {
        $global_support = MMI_Settings::get( 'mmi_xchange_support_link' );

        $labels = [
            'table_title' => MMI_Settings::get( 'mmi_xchange_text_table_title' ) ?: __( 'Product Licensing Information', 'mmi-xchange-integration' ),
            'product'     => MMI_Settings::get( 'mmi_xchange_text_product' ) ?: __( 'Product', 'mmi-xchange-integration' ),
            'serial'      => MMI_Settings::get( 'mmi_xchange_text_serial' ) ?: __( 'Serial Number', 'mmi-xchange-integration' ),
            'download'    => MMI_Settings::get( 'mmi_xchange_text_download' ) ?: __( 'Download', 'mmi-xchange-integration' ),
            'support'     => MMI_Settings::get( 'mmi_xchange_text_support' ) ?: __( 'Support Contact', 'mmi-xchange-integration' ),
        ];

        $html = '<p><h2>' . esc_html( $labels['table_title'] ) . '</h2></p><table>' . "\n";
        foreach ( $items as $product ) {
            $html .= '<tr><td>' . esc_html( $labels['product'] ) . ':</td><td>' . esc_html( $product['name'] ?? '' ) . "</td></tr>\n";
            $html .= '<tr><td>' . esc_html( $labels['serial'] ) . ':</td><td>' . esc_html( $product['license'] ?? '' ) . "</td></tr>\n";
            $download = $product['download'] ?? '';
            $html .= '<tr><td>' . esc_html( $labels['download'] ) . ':</td><td><a href="' . esc_url( $download ) . '">' . esc_html( $download ) . "</a></td></tr>\n";

            if ( $global_support !== '_' && ( $product['support'] ?? '' ) !== '_' ) {
                $html .= '<tr><td>' . esc_html( $labels['support'] ) . ':</td><td>' . esc_html( $product['support'] ?? '' ) . "</td></tr>\n";
            }
            $html .= "<tr><td colspan=\"2\"></td></tr>\n";
        }
        $html .= "</table>\n";

        return $html;
    }
}
