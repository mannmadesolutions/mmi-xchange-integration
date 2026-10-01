<?php
/**
 * MMI_Xchange_Finalize_Diagnostic
 *
 * On-demand end-to-end test of the reserve/finalize hook wiring added by the
 * 2026-08-24 finalize-timing redesign — see
 * mmi-hub/docs/XCHANGE_FINALIZE_TIMING_HANDOFF.md and AGENTS.md's "XChange
 * Finalize-Timing Redesign" Incident History entry for the full context.
 *
 * Runs against XChange's own designated test vendor (XMP TEST VENDOR,
 * account 1014) — a real API round-trip in `engine=test` mode, but against a
 * fixture vendor/SKU XChange provides specifically for integration testing,
 * not a live product. Drives the actual `WC_Order::payment_complete()` code
 * path (not a direct call into our own hook handlers) against a throwaway,
 * hidden WooCommerce product + order, so what's being verified is the real
 * WordPress hook wiring, not just the PHP logic behind it. The order is
 * trashed (not permanently deleted) after each run.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Finalize_Diagnostic {

    // XChange's own test-vendor SKU (XMP TEST VENDOR, account 1014,
    // "Kool Sequencer"). The vendor's other fixture product,
    // "test-physical-1" (1014-87), returns a live "INSUFFICIENT STOCK"
    // error as of 2026-08-24 — confirmed directly against the API, not
    // assumed — so this SKU is used instead.
    const TEST_SKU          = '1014-65';
    const TEST_PRODUCT_SKU  = 'MMI-XCHANGE-DIAGNOSTIC';
    const TEST_PRODUCT_NAME = '[MMI XChange Test] Diagnostic Product (do not delete)';

    /** @return array{success:bool, steps:array<int,array{label:string,ok:bool,detail:string}>, order_id:?int} */
    public static function run_success_scenario(): array {
        return self::run( false );
    }

    /** @return array{success:bool, steps:array<int,array{label:string,ok:bool,detail:string}>, order_id:?int} */
    public static function run_failure_scenario(): array {
        return self::run( true );
    }

    /**
     * @param bool $force_failure When true, deliberately voids the
     *     reservation before payment_complete() runs, so finalize() is
     *     guaranteed to fail — proving the order's status transition gets
     *     aborted instead of silently reaching "completed".
     */
    private static function run( bool $force_failure ): array {
        $steps = [];
        $order = null;

        try {
            $product_id = self::find_or_create_test_product();
            $steps[]    = self::step( 'Test product ready', true, "Product #{$product_id}, SKU " . self::TEST_SKU );

            $order   = self::create_test_order( $product_id );
            $steps[] = self::step( 'Order created', true, 'Order #' . $order->get_id() . ', payment method: ' . $order->get_payment_method() );

            do_action( 'woocommerce_checkout_update_order_meta', $order->get_id() );
            $order         = wc_get_order( $order->get_id() );
            $reserved_meta = get_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, true );

            if ( substr( $reserved_meta, 0, 3 ) !== 'tx:' ) {
                throw new Exception( 'reserve() did not run at checkout time (no tx: meta found on the order) — cannot continue the test.' );
            }
            $transaction_number = substr( $reserved_meta, 3 );
            $steps[]             = self::step( 'Reserved at checkout (woocommerce_checkout_update_order_meta)', true, "Transaction {$transaction_number}" );

            if ( $force_failure ) {
                $client = new MMI_Xchange_API_Client();
                $void   = $client->void( $transaction_number );
                if ( is_wp_error( $void ) ) {
                    throw new Exception( 'Could not void the reservation to induce a failure: ' . $void->get_error_message() );
                }
                $steps[] = self::step( 'Reservation voided deliberately, to induce a finalize() failure', true, "Transaction {$transaction_number}" );
            }

            $prior_status = $order->get_status();
            $result       = $order->payment_complete( 'mmi-diagnostic-' . time() );
            $order        = wc_get_order( $order->get_id() );
            $final_status = $order->get_status();

            if ( $force_failure ) {
                $aborted = ( $result === false ) && ( $final_status === $prior_status ) && ( $final_status !== 'completed' );
                $steps[] = self::step(
                    'payment_complete() aborted the status transition, as expected',
                    $aborted,
                    'payment_complete() returned ' . var_export( $result, true ) . ", order status stayed '{$final_status}' (was '{$prior_status}')"
                );
                if ( ! $aborted ) {
                    throw new Exception( 'Expected payment_complete() to abort and leave the order un-completed after finalize() failed, but it did not — see the step above.' );
                }
            } else {
                $completed = ( $result === true ) && ( $final_status === 'completed' );
                $steps[]   = self::step(
                    'payment_complete() succeeded, order reached completed',
                    $completed,
                    'payment_complete() returned ' . var_export( $result, true ) . ", order status '{$final_status}'"
                );
                if ( ! $completed ) {
                    throw new Exception( 'payment_complete() did not complete the order as expected.' );
                }

                $license_meta = get_post_meta( $order->get_id(), XCHANGE_LICENSE_INFO_FIELD_NAME, true );
                $decoded      = json_decode( $license_meta, true );
                $has_license  = is_array( $decoded ) && ! empty( $decoded );
                $steps[]      = self::step(
                    'License info saved by pre_payment_complete()',
                    $has_license,
                    $has_license ? wp_json_encode( $decoded ) : "order meta was: {$license_meta}"
                );

                // Setting the order to `completed` inside payment_complete()
                // also fires woocommerce_order_status_completed, so
                // order_complete() runs a second time on this same order —
                // this confirms the idempotency fix that stops it from
                // adding a false "processing failed" note on top of a real
                // success (see finalize_order()'s is_already_finalized()
                // check).
                $notes              = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
                $has_false_failure  = false;
                foreach ( $notes as $note ) {
                    if ( strpos( $note->content, 'XChange order processing failed' ) !== false ) {
                        $has_false_failure = true;
                    }
                }
                $steps[] = self::step(
                    'No false failure note from the redundant order_complete() run',
                    ! $has_false_failure,
                    count( $notes ) . ' order note(s) total'
                );
                if ( $has_false_failure ) {
                    throw new Exception( 'order_complete() added a false "processing failed" note after pre_payment_complete() already succeeded — the idempotency check has regressed.' );
                }
            }

            return [
                'success'  => true,
                'steps'    => $steps,
                'order_id' => $order->get_id(),
            ];
        } catch ( Exception $e ) {
            $steps[] = self::step( 'Diagnostic failed', false, $e->getMessage() );
            MMI_Logger::error( 'Finalize-timing diagnostic failed: ' . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Finalize_Diagnostic' );

            return [
                'success'  => false,
                'steps'    => $steps,
                'order_id' => $order ? $order->get_id() : null,
            ];
        } finally {
            if ( $order ) {
                $order->update_status( 'cancelled', __( 'MMI XChange finalize-timing diagnostic run — trashing.', 'mmi-xchange-integration' ) );
                $order->delete( false ); // Trash, not permanent — recoverable for 30 days if ever needed.
            }
        }
    }

    private static function step( string $label, bool $ok, string $detail ): array {
        return [ 'label' => $label, 'ok' => $ok, 'detail' => $detail ];
    }

    /**
     * Idempotent — reuses the same hidden product across every diagnostic
     * run rather than creating a new one each time. Re-syncs the XChange
     * SKU mapping on every call (not just at creation) so a future change
     * to TEST_SKU (e.g. if this test vendor's stock situation changes
     * again) takes effect on an already-existing product too.
     */
    private static function find_or_create_test_product(): int {
        $existing_id = wc_get_product_id_by_sku( self::TEST_PRODUCT_SKU );
        if ( $existing_id ) {
            update_post_meta( $existing_id, XCHANGE_INTERNAL_SKU_NAME, self::TEST_SKU );
            return $existing_id;
        }

        $product = new WC_Product_Simple();
        $product->set_name( self::TEST_PRODUCT_NAME );
        $product->set_sku( self::TEST_PRODUCT_SKU );
        $product->set_status( 'private' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_virtual( true );
        $product->set_downloadable( true );
        $product->set_regular_price( '236.00' );
        $product->set_price( '236.00' );
        $product_id = $product->save();

        update_post_meta( $product_id, XCHANGE_INTERNAL_SKU_NAME, self::TEST_SKU );

        MMI_Logger::info(
            "Created XChange finalize-timing diagnostic product #{$product_id}, mapped to XChange test-vendor SKU " . self::TEST_SKU,
            [], 'xchange', 'MMI_Xchange_Finalize_Diagnostic'
        );

        return $product_id;
    }

    private static function create_test_order( int $product_id ): WC_Order {
        $order = wc_create_order();
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->set_billing_first_name( 'MMI' );
        $order->set_billing_last_name( 'Diagnostic Test' );
        $order->set_billing_email( 'diagnostic@mannmade.solutions' );
        $order->set_payment_method( 'stripe' );
        $order->set_payment_method_title( 'MMI Diagnostic Test (no real charge)' );
        $order->calculate_totals();
        $order->save();

        return $order;
    }
}
