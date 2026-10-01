<?php
/**
 * MMI_Xchange_Ajax
 *
 * All AJAX endpoints for the admin console (order search, license fetch,
 * order placement, sync trigger, account check, vendor directory, COGS
 * backfill). Single nonce action for all of them: `mmi_xchange_admin`.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Ajax {

    const NONCE_ACTION = 'mmi_xchange_admin';

    public static function init(): void {
        add_action( 'wp_ajax_mmi_xchange_fetch_orders',      [ __CLASS__, 'fetch_orders' ] );
        add_action( 'wp_ajax_mmi_xchange_fetch_fulfillment_queue', [ __CLASS__, 'fetch_fulfillment_queue' ] );
        add_action( 'wp_ajax_mmi_xchange_scan_po_matches',   [ __CLASS__, 'scan_po_matches' ] );
        add_action( 'wp_ajax_mmi_xchange_find_po_candidate', [ __CLASS__, 'find_po_candidate' ] );
        add_action( 'wp_ajax_mmi_xchange_link_order',        [ __CLASS__, 'link_order' ] );
        add_action( 'wp_ajax_mmi_x_request_guest_email',     [ __CLASS__, 'request_guest_email' ] );
        add_action( 'wp_ajax_mmi_xchange_trigger_sync',      [ __CLASS__, 'trigger_sync' ] );
        add_action( 'wp_ajax_mmi_xchange_full_sync',         [ __CLASS__, 'full_sync' ] );
        add_action( 'wp_ajax_mmi_xchange_full_sync_status',  [ __CLASS__, 'full_sync_status' ] );
        add_action( 'wp_ajax_mmi_xchange_fetch_license',     [ __CLASS__, 'fetch_license' ] );
        add_action( 'wp_ajax_mmi_xchange_fetch_order_document', [ __CLASS__, 'fetch_order_document' ] );
        add_action( 'wp_ajax_mmi_xchange_place_order',       [ __CLASS__, 'place_order' ] );
        add_action( 'wp_ajax_mmi_xchange_run_finalize_diagnostic', [ __CLASS__, 'run_finalize_diagnostic' ] );
        add_action( 'wp_ajax_mmi_xchange_preview_product',   [ __CLASS__, 'preview_product' ] );
        add_action( 'wp_ajax_mmi_xchange_send_fulfillment_email', [ __CLASS__, 'send_fulfillment_email' ] );
        add_action( 'wp_ajax_mmi_xchange_auto_fulfill',        [ __CLASS__, 'auto_fulfill' ] );
        add_action( 'wp_ajax_mmi_xchange_auto_fulfill_bundle', [ __CLASS__, 'auto_fulfill_bundle' ] );
        add_action( 'wp_ajax_mmi_xchange_prepare_bundle',    [ __CLASS__, 'prepare_bundle' ] );
        add_action( 'wp_ajax_mmi_xchange_send_bundle_email', [ __CLASS__, 'send_bundle_email' ] );
        add_action( 'wp_ajax_mmi_xchange_lookup_product',    [ __CLASS__, 'lookup_product' ] );
        add_action( 'wp_ajax_mmi_xchange_check_connection',  [ __CLASS__, 'check_connection' ] );
        add_action( 'wp_ajax_mmi_xchange_check_order_api',   [ __CLASS__, 'check_order_api' ] );
        add_action( 'wp_ajax_mmi_xchange_get_logs',          [ __CLASS__, 'get_logs' ] );
        add_action( 'wp_ajax_mmi_xchange_clear_logs',        [ __CLASS__, 'clear_logs' ] );
        add_action( 'wp_ajax_mmi_xchange_set_mode',          [ __CLASS__, 'set_mode' ] );
        add_action( 'wp_ajax_mmi_xchange_get_vendors',       [ __CLASS__, 'get_vendors' ] );
        add_action( 'wp_ajax_mmi_xchange_vendor_image_stats', [ __CLASS__, 'vendor_image_stats' ] );
        add_action( 'wp_ajax_mmi_xchange_preview_vendor_images', [ __CLASS__, 'preview_vendor_images' ] );
        add_action( 'wp_ajax_mmi_xchange_import_media',        [ __CLASS__, 'import_media' ] );
        add_action( 'wp_ajax_mmi_xchange_import_media_status', [ __CLASS__, 'import_media_status' ] );
        add_action( 'wp_ajax_mmi_xchange_cogs_status',       [ __CLASS__, 'cogs_status' ] );
        add_action( 'wp_ajax_mmi_xchange_cogs_backfill_batch', [ __CLASS__, 'cogs_backfill_batch' ] );
        add_action( 'wp_ajax_mmi_xchange_register_webhooks', [ __CLASS__, 'register_webhooks' ] );
        add_action( 'wp_ajax_mmi_xchange_price_history_chart', [ __CLASS__, 'price_history_chart' ] );
    }

    /**
     * Explicit, admin-triggered action — never automatic — since it
     * registers this site's public REST endpoint with XChange and opens a
     * new (HMAC-verified, but unauthenticated by WP's own standards)
     * attack surface. See class-xchange-webhooks.php's docblock.
     */
    public static function register_webhooks(): void {
        self::guard();

        if ( ! class_exists( 'MMI_Xchange_Webhooks' ) ) {
            wp_send_json_error( [ 'message' => __( 'Webhook registration is unavailable.', 'mmi-xchange-integration' ) ] );
        }

        $results = MMI_Xchange_Webhooks::register_all();
        $failed  = array_filter( $results, static fn( $r ) => $r !== true );

        mmi_xchange_audit( 'webhooks.register', [
            'outcome' => empty( $failed ) ? 'success' : 'failure',
            'details' => [
                'events' => array_keys( $results ),
                'failed' => array_keys( $failed ),
            ],
        ] );

        if ( ! empty( $failed ) ) {
            $messages = array_map( static fn( $err ) => $err->get_error_message(), $failed );
            wp_send_json_error( [ 'message' => implode( ' ', $messages ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Webhooks registered successfully for purchase_order_updates and shipment_updates.', 'mmi-xchange-integration' ) ] );
    }

    /**
     * Nonce + capability gate shared by every endpoint in this file.
     * $context is mmi_xchange_user_can()'s tier: 'operate' (default,
     * manage_woocommerce) or 'admin' (manage_options — mode switch, logs,
     * COGS backfill). A capability failure is audit-logged as 'denied'.
     */
    private static function guard( string $context = 'operate' ): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! mmi_xchange_user_can( $context ) ) {
            mmi_xchange_audit( 'ajax.denied', [
                'outcome' => 'denied',
                'details' => [
                    'ajax_action' => sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ),
                    'context'     => $context,
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mmi-xchange-integration' ) ] );
        }
    }

    /* ── Orders ────────────────────────────────────────────────────────────── */

    public static function fetch_orders(): void {
        self::guard();

        $sku           = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        $force_refresh = ! empty( $_POST['force_refresh'] );

        wp_send_json_success( MMI_Xchange_Order_Sync::get_snapshot( $sku, $force_refresh ) );
    }

    /**
     * Recent WooCommerce orders containing XChange-sourced products, for the
     * Place Order tab's Recent Orders panel — see MMI_Xchange_Fulfillment_Queue.
     */
    public static function fetch_fulfillment_queue(): void {
        self::guard();
        wp_send_json_success( [ 'orders' => MMI_Xchange_Fulfillment_Queue::get_recent_orders() ] );
    }

    /**
     * One bounded batch of MMI_Xchange_Po_Linker's backlog scan — see that
     * class's docblock for what this is and, critically, what it
     * deliberately does NOT do (it never writes a PO link itself). $offset
     * lets the Orders tab's "Scan Next Batch" button work through the full
     * backlog a page at a time rather than one unbounded query.
     */
    public static function scan_po_matches(): void {
        self::guard();

        $limit  = min( 500, max( 1, absint( $_POST['limit'] ?? 200 ) ) );
        $offset = absint( $_POST['offset'] ?? 0 );

        wp_send_json_success( MMI_Xchange_Po_Linker::find_matches( $limit, $offset ) );
    }

    /**
     * Single-order counterpart to scan_po_matches() above — the
     * "🔍 Find Candidate PO" button inside a Fulfillment Queue row's own
     * manual-sync fields. Unlike the bulk scan, this can fall through to a
     * real, on-demand XChange REST call for this one order when nothing
     * local matches (see MMI_Xchange_Po_Linker::find_candidate_for_order()'s
     * docblock) — acceptable specifically because it's one explicit click
     * on one specific order, never a loop.
     */
    public static function find_po_candidate(): void {
        self::guard();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( $order_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Missing order.', 'mmi-xchange-integration' ) ] );
        }

        $match = MMI_Xchange_Po_Linker::find_candidate_for_order( $order_id );
        wp_send_json_success( [ 'match' => $match ] );
    }

    /**
     * "🔗 Link Only" — writes _mmi_xchange_fulfilled_po directly, with no
     * email sent and no XChange API/portal call of any kind. Added
     * 2026-09-20 at the user's explicit request to split linking and
     * emailing into separate actions (they used to be one bundled action
     * behind a single button, which this same session had already
     * renamed once — from "Sync →" to "Review & Email →" — for being
     * misleading about doing only one of the two). send_fulfillment_email()
     * is the sibling endpoint for "Email Only" / "Link & Email →", gated
     * by its own `link_on_send` field.
     */
    public static function link_order(): void {
        self::guard();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $po       = sanitize_text_field( wp_unslash( $_POST['po'] ?? '' ) );
        $sku      = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );

        if ( $order_id <= 0 || $po === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing order or PO number.', 'mmi-xchange-integration' ) ] );
        }

        if ( ! class_exists( 'MMI_Xchange_Fulfillment_Queue' ) ) {
            wp_send_json_error( [ 'message' => __( 'Fulfillment queue unavailable.', 'mmi-xchange-integration' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'mmi-xchange-integration' ) ] );
        }

        // Same completion → order-status logic send_fulfillment_email()
        // applies after a linking send — kept identical so "fully
        // fulfilled" means the same thing regardless of which of the two
        // endpoints actually wrote the link.
        $completion = MMI_Xchange_Fulfillment_Queue::mark_fulfilled( $order_id, $po, $sku );
        if ( $completion['fully_fulfilled'] && ! $order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
            $order->update_status( 'completed', __( 'All XChange item(s) on this order have been fulfilled.', 'mmi-xchange-integration' ) );
        }

        mmi_xchange_audit( 'order.link_po', [
            'object_type' => 'order',
            'object_id'   => $order_id,
            'outcome'     => 'success',
            'details'     => [ 'po' => $po, 'sku' => $sku, 'order_status' => $order->get_status() ],
        ] );

        wp_send_json_success( [
            'message'      => sprintf(
                /* translators: 1: WC order number, 2: XChange PO number */
                __( 'Order #%1$s linked to PO %2$s. No email was sent.', 'mmi-xchange-integration' ),
                $order->get_order_number(),
                $po
            ),
            'order_status' => $order->get_status(),
            'completion'   => $completion,
        ] );
    }

    /**
     * Manual trigger for MMI_Xchange_Guest_Email_Request::send() — the
     * guest-convert widget's "📨 Request via Reverb Message" button
     * (renderManualGuestFormHtml() in admin-xchange.js), shown whenever
     * mmi-reverb-integration is active regardless of whether the automatic
     * flow's own setting is on. Lets an admin fire the same Reverb message
     * the automatic trigger would have sent, for an order it never got a
     * chance to run on (predates this feature, or the global auto-request
     * toggle is off) or a retry after a prior attempt failed.
     */
    public static function request_guest_email(): void {
        self::guard();

        if ( ! class_exists( 'MMI_Xchange_Guest_Email_Request' ) || ! class_exists( 'MMI_Reverb_API_Client' ) ) {
            wp_send_json_error( [ 'message' => __( 'Requires mmi-reverb-integration to be active.', 'mmi-xchange-integration' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'mmi-xchange-integration' ) ] );
        }

        if ( $order->get_customer_id() > 0 ) {
            wp_send_json_error( [ 'message' => __( 'This order is already linked to a customer account.', 'mmi-xchange-integration' ) ] );
        }

        $reverb_order_id = (string) $order->get_meta( '_mmi_reverb_order_number', true );
        if ( $reverb_order_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'This order has no linked Reverb order number.', 'mmi-xchange-integration' ) ] );
        }

        $result = MMI_Xchange_Guest_Email_Request::send( $order, $reverb_order_id );

        mmi_xchange_audit( 'customer.email_request', [
            'object_type' => 'order',
            'object_id'   => $order_id,
            'outcome'     => $result['success'] ? 'success' : 'failure',
            'details'     => [ 'reverb_order_id' => $reverb_order_id ],
        ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }

        wp_send_json_success( [ 'message' => $result['message'] ] );
    }

    public static function trigger_sync(): void {
        self::guard();
        MMI_Xchange_Order_Sync::queue_sync();
        wp_send_json_success( [ 'message' => __( 'Sync queued.', 'mmi-xchange-integration' ) ] );
    }

    /**
     * Manual/opt-in only — logs into the xchangeb2b.com web portal (CCSA +
     * Invoice History scrape) using the reseller's own credentials, which
     * will end any XChange.com browser session currently open. Never call
     * this automatically; it must only be reachable from an explicit,
     * clearly-labeled admin button click.
     */
    public static function full_sync(): void {
        self::guard();
        MMI_Xchange_Order_Sync::queue_full_sync();
        mmi_xchange_audit( 'sync.full_queue', [ 'outcome' => 'success' ] );
        wp_send_json_success( [ 'message' => __( 'Full sync queued — this logs into the XChange web portal and will end any active XChange.com browser session. Give it a minute, then refresh.', 'mmi-xchange-integration' ) ] );
    }

    /**
     * Polled by the Orders tab's status bar while a queued/running full sync
     * progresses in the background (Action Scheduler/WP-Cron).
     */
    public static function full_sync_status(): void {
        self::guard();
        wp_send_json_success( MMI_Xchange_Order_Sync::get_full_sync_progress() );
    }

    public static function fetch_license(): void {
        self::guard();

        $po = sanitize_text_field( wp_unslash( $_POST['po'] ?? '' ) );
        if ( $po === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter the XChange PO / order number first.', 'mmi-xchange-integration' ) ] );
        }

        $license = MMI_Xchange_Order_Sync::call_license_api( $po );
        mmi_xchange_audit( 'license.fetch', [
            'object_type' => 'xchange_po',
            'object_id'   => $po,
            'outcome'     => ( $license === null || $license === '' ) ? 'failure' : 'success',
        ] );
        if ( $license === null || $license === '' ) {
            wp_send_json_error( [ 'message' => sprintf( __( 'License not found for PO "%s" — check the PO number and try again, or paste the license key manually.', 'mmi-xchange-integration' ), $po ) ] );
        }

        wp_send_json_success( [ 'license_key' => $license ] );
    }

    /**
     * Full order document (license/download/support/auth) for a PO — used
     * by the Place Order tab to silently auto-populate the Email Customer
     * modal right after a fulfillment (automated or manually synced).
     * Distinct from fetch_license() above (which mmi-reverb-integration's
     * PO browser also calls and expects to keep returning just
     * {license_key}) — left untouched.
     *
     * MMI_Xchange_Order_Sync::fetch_order_document() tries the safe REST
     * API first, then automatically falls back to a CCSA portal lookup
     * when that didn't return usable details — CCSA covers virtually every
     * recent order, manually- or API-placed, so this is not gated behind a
     * separate confirmed step here. That fallback CAN end any XChange.com
     * browser session the admin has open; that's accepted by design, not
     * an oversight — see that method's docblock.
     */
    public static function fetch_order_document(): void {
        self::guard();

        $po = sanitize_text_field( wp_unslash( $_POST['po'] ?? '' ) );
        if ( $po === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter the XChange PO / order number first.', 'mmi-xchange-integration' ) ] );
        }

        $doc = MMI_Xchange_Order_Sync::fetch_order_document( $po );
        mmi_xchange_audit( 'document.fetch', [
            'object_type' => 'xchange_po',
            'object_id'   => $po,
            'outcome'     => $doc === null ? 'failure' : 'success',
        ] );
        if ( $doc === null ) {
            wp_send_json_error( [ 'message' => sprintf( __( 'No XChange record found for PO "%s" — checked the REST API, CCSA, and Invoice History. Check the PO number, or the XChange web-portal credentials under Settings.', 'mmi-xchange-integration' ), $po ) ] );
        }

        wp_send_json_success( $doc );
    }

    public static function place_order(): void {
        self::guard();

        $sku      = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        $qty      = max( 1, absint( $_POST['qty'] ?? 1 ) );
        $order_id = absint( $_POST['order_id'] ?? 0 );

        if ( $sku === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing XChange SKU.', 'mmi-xchange-integration' ) ] );
        }

        // order_id is present when this call is fulfilling a specific WC
        // order (the Recent Orders panel's "Fulfill" button) — absent for
        // the standalone Place Order tab, which has no order to check and
        // is a deliberate ad-hoc purchase already gated by its own confirm
        // dialog. When present, refuse if the order was imported from an
        // external marketplace (e.g. Reverb) — it was already sold, often
        // already fulfilled, elsewhere; placing an XChange order for it
        // would be a real, live duplicate purchase. See
        // MMI_Xchange_Checkout::is_externally_sourced() and
        // mmi-admin/docs/api-reference/xchange-api.md for why this exists.
        //
        // Exception: an order whose customer identity has since been
        // manually verified (mmi-hub's Guest Customer Converter — real
        // email confirmed and locked, not still the marketplace's relay
        // address) is allowed through. That verification is a deliberate,
        // human, per-order action — the admin has already confirmed a real
        // customer stands behind this specific order — so it is treated as
        // sufficient authorization for this one manual, admin-clicked
        // action. See MMI_Xchange_Checkout::is_verified_after_external_import()
        // for exactly what's required and why this exception is scoped to
        // this one call site only.
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order && MMI_Xchange_Checkout::is_externally_sourced( $order ) ) {
                if ( MMI_Xchange_Checkout::is_verified_after_external_import( $order ) ) {
                    MMI_Logger::info(
                        "Allowing XChange place_order for order {$order_id} despite external-marketplace origin — customer identity manually verified via Guest Customer Converter.",
                        [], 'xchange', 'MMI_Xchange_Ajax'
                    );
                    $order->add_order_note( __( 'XChange fulfillment proceeding despite external-marketplace origin — customer identity was manually verified beforehand.', 'mmi-xchange-integration' ) );
                } else {
                    MMI_Logger::warn(
                        "Blocked XChange place_order for order {$order_id} — order was imported from an external marketplace (Reverb or similar), already sold/fulfilled elsewhere.",
                        [], 'xchange', 'MMI_Xchange_Ajax'
                    );
                    mmi_xchange_audit( 'order.place', [
                        'object_type' => 'order',
                        'object_id'   => $order_id,
                        'outcome'     => 'denied',
                        'details'     => [ 'sku' => $sku, 'qty' => $qty, 'reason' => 'external_source' ],
                    ] );
                    wp_send_json_error( [
                        'message'        => __( 'This order was imported from an external marketplace (e.g. Reverb) and was already sold there — placing an XChange order for it would be a duplicate purchase. XChange fulfillment is disabled for this order unless the customer\'s identity is first verified via Guest Customer Converter.', 'mmi-xchange-integration' ),
                        // Lets the JS distinguish "deliberately blocked to prevent
                        // double billing" from an ordinary API failure (out of
                        // stock, network error, etc.) so only this case gets the
                        // hard, un-missable alert treatment — see fulfillFromQueue()
                        // in admin-xchange.js.
                        'blocked_reason' => 'external_source',
                    ] );
                }
            }
        }

        // Never buy the same line item twice. Once a PO was placed for this
        // order's item (MMI_Software_Fulfillment::record_placement() below),
        // a second "Fulfill" click — e.g. after the admin closed the email
        // step without sending — gets the existing PO back instead of a new
        // real-money purchase. Before this, nothing recorded a placed-but-
        // not-yet-emailed PO at all.
        if ( $order_id > 0 && class_exists( 'MMI_Software_Fulfillment' ) ) {
            $existing = self::existing_item_record( $order_id, $sku );
            if ( $existing !== null ) {
                mmi_xchange_audit( 'order.place', [
                    'object_type' => 'order',
                    'object_id'   => $order_id,
                    'outcome'     => 'denied',
                    'details'     => [ 'sku' => $sku, 'qty' => $qty, 'reason' => 'already_placed', 'po' => $existing['po_number'] ],
                ] );
                wp_send_json_error( [
                    'message'        => sprintf(
                        /* translators: 1: SKU, 2: order ID, 3: PO number */
                        __( '%1$s on order #%2$d was already purchased (PO %3$s). Reusing that PO instead of buying again.', 'mmi-xchange-integration' ),
                        $sku,
                        $order_id,
                        $existing['po_number']
                    ),
                    'blocked_reason' => 'already_placed',
                    'po_number'      => $existing['po_number'],
                    'licenses'       => [ [
                        'sku'          => $sku,
                        'license_key'  => (string) ( $existing['license_key'] ?? '' ),
                        'download_url' => (string) ( $existing['download_url'] ?? '' ),
                        'support_url'  => (string) ( $existing['support_url'] ?? '' ),
                    ] ],
                ] );
            }
        }

        // Audit context for MMI_Xchange_API::place_order()'s own
        // order.place record (who/which order/outcome is written there, so
        // every caller of the facade is covered, not just this endpoint).
        $audit_context = [ 'trigger' => $order_id > 0 ? 'admin.fulfill' : 'admin.place_order' ];
        if ( $order_id > 0 ) {
            $audit_context['order_id'] = $order_id;
        }
        $reverb_order_id = sanitize_text_field( wp_unslash( $_POST['reverb_order_id'] ?? '' ) );
        if ( $reverb_order_id !== '' ) {
            $audit_context['reverb_order_id'] = $reverb_order_id;
            $audit_context['trigger']         = 'admin.reverb_modal';
        }

        $result = MMI_Xchange_API::place_order( $sku, $qty, $audit_context );

        if ( isset( $result['error'] ) ) {
            // MMI_Xchange_API::place_order() keys its error text 'error'
            // (matches XChange's own error shape) — every other endpoint in
            // this file, and the JS that reads it, expects 'message'. Alias
            // it here rather than everywhere it's read.
            $result['message'] = $result['error'];
            wp_send_json_error( $result );
        }

        // This is the exact moment MMI actually paid XChange whatever
        // dealer/promo price was in effect — capture it now, tied to this
        // order+sku, so the Fulfillment Queue's MARGIN column can show a
        // real cost-at-purchase figure instead of re-deriving it from
        // whatever XChange's price happens to be the next time an admin
        // looks. Never blocks/fails the response — the purchase already
        // succeeded; a missing snapshot just means this order falls back to
        // the live-estimate path, same as any pre-fix or CCSA-fulfilled
        // order. See MMI_Xchange_Order_Sync::preview_product() for the read
        // side.
        if ( $order_id > 0 && class_exists( 'MMI_Software_Fulfillment' ) ) {
            // qty > 1 returns one license per unit — keep every key.
            $matches = array_values( array_filter( $result['licenses'] ?? [], static fn( $l ) => $l['sku'] === $sku ) );
            $license = $matches ? [
                'license_key'  => implode( ', ', array_filter( array_column( $matches, 'license_key' ) ) ),
                'download_url' => $matches[0]['download_url'],
                'support_url'  => $matches[0]['support_url'],
            ] : [];
            if ( ! MMI_Software_Fulfillment::record_placement( $order_id, $sku, $result['po_number'], $license ) ) {
                MMI_Logger::warn(
                    "place_order succeeded for order {$order_id}/sku {$sku} (PO {$result['po_number']}) but no matching line item was found to record the placement on.",
                    [], 'xchange', 'MMI_Xchange_Ajax'
                );
            }
        }

        if ( $order_id > 0 && class_exists( 'MMI_Xchange_Order_Sync' ) && class_exists( 'MMI_Xchange_Fulfillment_Queue' ) ) {
            $snapshot = MMI_Xchange_Order_Sync::capture_cost_snapshot( $sku );
            if ( $snapshot !== null ) {
                MMI_Xchange_Fulfillment_Queue::record_cost_snapshot( $order_id, $sku, $snapshot );
            } else {
                MMI_Logger::warn(
                    "place_order succeeded for order {$order_id}/sku {$sku} but no catalog data was available to snapshot cost basis — margin will show as a live estimate.",
                    [], 'xchange', 'MMI_Xchange_Ajax'
                );
            }
        }

        wp_send_json_success( $result );
    }

    /**
     * The step after a queue "Fulfill" placed (or reused) a PO: email the
     * customer automatically when everything is known — see
     * MMI_Xchange_Auto_Fulfillment. Always a JSON success carrying
     * {status: sent|pending|review, message}; 'review' means the JS opens
     * the Email Customer modal as before.
     */
    public static function auto_fulfill(): void {
        self::guard();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $sku      = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        if ( $order_id <= 0 || $sku === '' || ! class_exists( 'MMI_Xchange_Auto_Fulfillment' ) ) {
            wp_send_json_success( [ 'status' => 'review', 'message' => __( 'Review and send the customer email.', 'mmi-xchange-integration' ) ] );
        }

        $attempt = MMI_Xchange_Auto_Fulfillment::attempt( $order_id, $sku, true );
        // Only 'sent' has a side effect (license emailed, order completed);
        // 'pending'/'review' just hand control back to the admin.
        if ( ( $attempt['status'] ?? '' ) === 'sent' ) {
            mmi_xchange_audit( 'email.auto_fulfill', [
                'object_type' => 'order',
                'object_id'   => $order_id,
                'outcome'     => 'success',
                'details'     => [ 'sku' => $sku ],
            ] );
        }
        wp_send_json_success( $attempt );
    }

    /**
     * auto_fulfill() for a "Fulfill together" group, after every item was
     * placed. POST items: JSON list of {order_id, sku}. 'review' → the JS
     * opens the combined modal.
     */
    public static function auto_fulfill_bundle(): void {
        self::guard();

        $items = self::decode_bundle_items();
        if ( is_string( $items ) || ! class_exists( 'MMI_Xchange_Auto_Fulfillment' ) ) {
            wp_send_json_success( [ 'status' => 'review', 'message' => is_string( $items ) ? $items : '' ] );
        }

        $pairs   = array_map( static fn( $item ) => [ 'order_id' => $item['order']->get_id(), 'sku' => $item['sku'] ], $items );
        $attempt = MMI_Xchange_Auto_Fulfillment::attempt_bundle( $pairs );
        if ( ( $attempt['status'] ?? '' ) === 'sent' ) {
            mmi_xchange_audit( 'email.auto_fulfill_bundle', [
                'object_type' => 'order',
                'object_id'   => implode( ',', array_unique( array_column( $pairs, 'order_id' ) ) ),
                'outcome'     => 'success',
                'details'     => [ 'items' => $pairs ],
            ] );
        }
        wp_send_json_success( $attempt );
    }

    /**
     * This order's shared fulfillment record for an XChange SKU, when a PO
     * was already placed or fulfilled for it — including orders fulfilled
     * before per-item records existed (the legacy per-SKU PO map).
     *
     * @return array{po_number:string, license_key?:string, download_url?:string, support_url?:string}|null
     */
    private static function existing_item_record( int $order_id, string $sku ): ?array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $item   = MMI_Software_Fulfillment::find_item( $order, $sku );
        $record = $item ? MMI_Software_Fulfillment::get_record( $item ) : null;
        if ( $record !== null && ( $record['po_number'] ?? '' ) !== '' ) {
            return $record;
        }

        $legacy = MMI_Xchange_Fulfillment_Queue::get_fulfilled_skus( $order )[ $sku ] ?? '';
        return $legacy !== '' ? [ 'po_number' => $legacy ] : null;
    }

    /**
     * Runs MMI_Xchange_Finalize_Diagnostic's on-demand end-to-end test of
     * the reserve/finalize hook wiring (see that class's docblock and
     * AGENTS.md's "XChange Finalize-Timing Redesign" Incident History
     * entry) — a real API round-trip against XChange's own test vendor
     * (engine=test), gated behind manage_woocommerce like every other
     * action in this file. `scenario` picks which path to exercise:
     * 'success' (normal reserve+finalize) or 'failure' (deliberately voids
     * the reservation first, to prove a failed finalize() aborts the order
     * instead of silently completing).
     */
    public static function run_finalize_diagnostic(): void {
        self::guard();

        $scenario = sanitize_text_field( wp_unslash( $_POST['scenario'] ?? 'success' ) );
        $result   = ( $scenario === 'failure' )
            ? MMI_Xchange_Finalize_Diagnostic::run_failure_scenario()
            : MMI_Xchange_Finalize_Diagnostic::run_success_scenario();

        mmi_xchange_audit( 'diagnostic.finalize', [
            'outcome' => ! empty( $result['success'] ) ? 'success' : 'failure',
            'details' => [ 'scenario' => $scenario === 'failure' ? 'failure' : 'success' ],
        ] );

        wp_send_json_success( $result );
    }

    /**
     * Pricing/availability lookup for a single SKU — the Place Order tab's
     * "Preview" button (no order_id, always a fresh live lookup) and the
     * Fulfillment Queue detail table's MARGIN column (order_id present,
     * returns the real cost-at-purchase snapshot when this order's XChange
     * purchase already went through place_order() — see
     * MMI_Xchange_Order_Sync::preview_product()).
     */
    public static function preview_product(): void {
        self::guard();

        $sku      = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( $sku === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter an XChange SKU first.', 'mmi-xchange-integration' ) ] );
        }

        $product = MMI_Xchange_Order_Sync::preview_product( $sku, $order_id );
        if ( $product === null ) {
            wp_send_json_error( [ 'message' => sprintf( __( 'SKU "%s" was not found in the XChange product catalog.', 'mmi-xchange-integration' ), $sku ) ] );
        }

        // Resolved here (not in MMI_Xchange_Order_Sync) since get_edit_post_link()
        // is a WP admin-URL helper, not an Xchange catalog concern — lets the
        // Orders tab's detail table link the WC product name to its edit
        // screen without building the URL itself in JS.
        $product['edit_url'] = $product['product_id'] ? get_edit_post_link( $product['product_id'], '' ) : null;

        wp_send_json_success( $product );
    }

    /**
     * Standalone Place Order tab flow: send the branded fulfillment email
     * (license/download/support) directly to a customer-supplied address —
     * this tab isn't tied to any WC/Reverb order, so the recipient and any
     * missing content fields are supplied by the admin in the panel.
     */
    public static function send_fulfillment_email(): void {
        self::guard();

        // Test sends always go to the site admin, regardless of whatever
        // the customer-email field currently holds — the whole point is a
        // safe content/rendering preview that can never reach a real
        // customer, so the posted `to` is deliberately ignored for this path.
        $is_test = ! empty( $_POST['is_test'] );
        $to      = $is_test
            ? sanitize_email( (string) get_option( 'admin_email' ) )
            : sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );

        if ( $to === '' || ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => $is_test
                ? __( "This site's admin email address isn't valid — check Settings > General.", 'mmi-xchange-integration' )
                : __( 'Enter a valid recipient email address.', 'mmi-xchange-integration' ),
            ] );
        }

        if ( ! class_exists( 'MMI_Xchange_Fulfillment_Email' ) ) {
            wp_send_json_error( [ 'message' => __( 'Fulfillment email system unavailable.', 'mmi-xchange-integration' ) ] );
        }

        $data = [
            'customer_name'     => sanitize_text_field( wp_unslash( $_POST['customer_name']     ?? '' ) ),
            'software_name'     => sanitize_text_field( wp_unslash( $_POST['software_name']     ?? '' ) ),
            'software_logo_url' => esc_url_raw( wp_unslash( $_POST['software_logo_url'] ?? '' ) ),
            'license_key'       => sanitize_text_field( wp_unslash( $_POST['license_key']       ?? '' ) ),
            'download_url'      => esc_url_raw( wp_unslash( $_POST['download_url']  ?? '' ) ),
            'support_url'       => esc_url_raw( wp_unslash( $_POST['support_url']   ?? '' ) ),
            'po_number'         => sanitize_text_field( wp_unslash( $_POST['po_number']          ?? '' ) ),
            'auth'              => sanitize_text_field( wp_unslash( $_POST['auth']               ?? '' ) ),
            'sku'               => sanitize_text_field( wp_unslash( $_POST['sku']                ?? '' ) ),
            'our_sku'           => sanitize_text_field( wp_unslash( $_POST['our_sku']            ?? '' ) ),
            'vendor_name'       => sanitize_text_field( wp_unslash( $_POST['vendor_name']        ?? '' ) ),
            'price'             => (float) ( $_POST['price'] ?? 0 ),
            'currency'          => sanitize_text_field( wp_unslash( $_POST['currency']           ?? '' ) ),
        ];

        // Resolved here (not in MMI_Xchange_Fulfillment_Email, which stays a
        // pure render function) from the WC product ID the JS already
        // resolved via preview_product() — links the customer straight to
        // the product's own public page and its product_brand archive page,
        // matching the Vendors tab's existing product_brand taxonomy usage.
        //
        // Product identity (ID, name, logo) is re-derived here from the
        // posted SKU rather than trusted from the modal's freeform fields —
        // added after the 2026-09-22 incident where one order's email
        // went out naming/picturing a different product (the previous order's), because
        // the modal's name/logo inputs carried over from the previous order
        // while its SKU field (reset per order) stayed correct. With the WC
        // product resolvable from the SKU, a stale client value can no
        // longer reach the customer regardless of where the staleness
        // originates. POSTed name/logo/product_id are only used when the
        // SKU doesn't resolve to a WC product at all (the standalone Place
        // Order tab with an arbitrary SKU), where they're the only source.
        $data = self::enrich_email_data( $data, absint( $_POST['product_id'] ?? 0 ) );

        // A test send never touches order state or the fulfillment-email
        // history — order_id is passed as 0 regardless of whether a real
        // order is being fulfilled, and the mark_fulfilled()/completion
        // block below is skipped entirely for the same reason.
        $order_id = $is_test ? 0 : absint( $_POST['order_id'] ?? 0 );
        $result   = MMI_Xchange_Fulfillment_Email::send( $to, $data, $order_id, $is_test );

        mmi_xchange_audit( 'email.fulfillment_send', [
            'object_type' => 'order',
            'object_id'   => $order_id,
            'outcome'     => $result['success'] ? 'success' : 'failure',
            'details'     => [ 'to' => $to, 'sku' => $data['sku'], 'po' => $data['po_number'], 'is_test' => $is_test ],
        ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }

        if ( $is_test ) {
            wp_send_json_success( [ 'message' => sprintf(
                /* translators: %s: admin email address */
                __( 'Test email sent to %s.', 'mmi-xchange-integration' ),
                $to
            ) ] );
        }

        // Learns this vendor's download/support URLs for
        // MMI_Xchange_Order_Sync::get_vendor_fallback_docs() — regardless
        // of link_on_send below, since a real (non-test) send means the
        // admin has confirmed these values are correct for this vendor,
        // whether they were auto-fetched or hand-pasted (XChange stops
        // exposing these fields once an order settles out of CCSA, so a
        // manual paste-in is often the only way this data enters the
        // system at all for an older order — see that method's own
        // docblock). A blank field here is simply not learned; never
        // overwrites a real value with a blank one.
        if ( class_exists( 'MMI_Xchange_Order_Sync' ) ) {
            MMI_Xchange_Order_Sync::learn_vendor_fallback_docs(
                $data['vendor_name'],
                $data['download_url'],
                $data['support_url']
            );
        }

        // If this send originated from a Recent Orders panel row (see
        // MMI_Xchange_Fulfillment_Queue), tag that WC order as fulfilled
        // (per-SKU, not just the order's latest PO) and, once every
        // XChange item on it is fulfilled and it has no other, non-XChange
        // items this plugin can't vouch for, mark the order completed —
        // added 2026-08-24 so the admin doesn't also have to manually flip
        // order status after every fulfillment email. An order that mixes
        // XChange items with other products, or that still has unfulfilled
        // XChange items, is deliberately left at its current status.
        //
        // link_on_send (added 2026-09-20, splitting linking from emailing
        // into separate admin-chosen actions — see MMI_Xchange_Po_Linker's
        // "Link Only" sibling, link_order() below): when explicitly '0'
        // (the "Email Only" row button), this whole block is skipped and
        // the order is left exactly as it was — sending an email no longer
        // implies linking by default; the JS decides per-call, defaulting
        // to true for every pre-existing caller so this is additive, not a
        // behavior change for anyone who doesn't pass the new field.
        $link_on_send = ( (string) ( $_POST['link_on_send'] ?? '1' ) ) !== '0';

        $order_status = null;
        $completion   = null;
        if ( $link_on_send && $order_id > 0 && $data['po_number'] !== '' && class_exists( 'MMI_Xchange_Fulfillment_Queue' ) ) {
            $completion   = MMI_Xchange_Fulfillment_Queue::mark_fulfilled( $order_id, $data['po_number'], $data['sku'], [
                'license_key'  => $data['license_key'],
                'download_url' => $data['download_url'],
                'support_url'  => $data['support_url'],
            ] );
            $order_status = self::complete_order_if_fulfilled( $order_id, $completion );
        }

        wp_send_json_success( [
            'message'      => $result['message'],
            'order_status' => $order_status,
            'completion'   => $completion,
        ] );
    }

    /**
     * Server-side product identity and links for a single-product
     * fulfillment email, derived from $data['sku'] — see
     * send_fulfillment_email()'s 2026-09-22 incident note for why posted
     * name/logo are never trusted when the SKU resolves. $fallback_product_id
     * is only used when it doesn't (the standalone Place Order tab).
     * Shared with MMI_Xchange_Auto_Fulfillment.
     */
    public static function enrich_email_data( array $data, int $fallback_product_id ): array {
        $product_id = self::resolve_product_id_for_xchange_sku( $data['sku'] );
        if ( $product_id > 0 ) {
            $resolved = wc_get_product( $product_id );
            if ( $resolved ) {
                $data['software_name'] = $resolved->get_name();
            }
            $data['software_logo_url'] = get_the_post_thumbnail_url( $product_id, 'medium' ) ?: $data['software_logo_url'];
        } else {
            $product_id = $fallback_product_id;
        }
        if ( $product_id > 0 ) {
            $product_url = get_permalink( $product_id );
            if ( $product_url ) {
                $data['product_url'] = $product_url;
            }

            $brand_terms = get_the_terms( $product_id, 'product_brand' );
            if ( is_array( $brand_terms ) && ! empty( $brand_terms ) ) {
                $brand_url = get_term_link( $brand_terms[0], 'product_brand' );
                if ( ! is_wp_error( $brand_url ) ) {
                    $data['brand_url'] = $brand_url;
                }
            }

            // The WC product's own regular price — already public on the
            // product page, not our wholesale cost (that stays admin-only,
            // per this project's rule: never COG data client-facing). Only
            // meaningful to show when it differs from what the customer
            // actually paid (i.e. this was bought on sale); render()
            // decides whether to display it based on that comparison.
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $regular_price = $product->get_regular_price();
                if ( $regular_price !== '' ) {
                    $data['regular_price'] = (float) $regular_price;
                }
            }
        }

        return $data;
    }

    /**
     * After a real single-product send: learn the vendor's links, mark the
     * item fulfilled and complete the order when it's done — the
     * link_on_send path of send_fulfillment_email(), for
     * MMI_Xchange_Auto_Fulfillment.
     *
     * @return array{order_status:?string, completion:array}
     */
    public static function finalize_single_send( int $order_id, array $data ): array {
        if ( class_exists( 'MMI_Xchange_Order_Sync' ) ) {
            MMI_Xchange_Order_Sync::learn_vendor_fallback_docs( $data['vendor_name'], $data['download_url'], $data['support_url'] );
        }
        $completion = MMI_Xchange_Fulfillment_Queue::mark_fulfilled( $order_id, $data['po_number'], $data['sku'], [
            'license_key'  => $data['license_key'],
            'download_url' => $data['download_url'],
            'support_url'  => $data['support_url'],
        ] );
        return [
            'order_status' => self::complete_order_if_fulfilled( $order_id, $completion ),
            'completion'   => $completion,
        ];
    }

    /**
     * After a real combined send: mark every item fulfilled and complete
     * each order that's done. Shared by send_bundle_email() and
     * MMI_Xchange_Auto_Fulfillment::attempt_bundle().
     *
     * @param array<int,array> $email_items
     * @return array<int,?string> order ID => status afterward
     */
    public static function finalize_bundle_send( array $email_items ): array {
        $orders = [];
        foreach ( $email_items as $email_item ) {
            $completion = MMI_Xchange_Fulfillment_Queue::mark_fulfilled( $email_item['order_id'], $email_item['po_number'], $email_item['sku'], [
                'license_key'  => $email_item['license_key'],
                'download_url' => $email_item['download_url'],
                'support_url'  => $email_item['support_url'],
            ] );
            if ( class_exists( 'MMI_Xchange_Order_Sync' ) ) {
                MMI_Xchange_Order_Sync::learn_vendor_fallback_docs( $email_item['vendor_name'], $email_item['download_url'], $email_item['support_url'] );
            }
            $orders[ $email_item['order_id'] ] = self::complete_order_if_fulfilled( $email_item['order_id'], $completion );
        }
        return $orders;
    }

    /**
     * See MMI_Xchange_Fulfillment_Queue::complete_if_fulfilled().
     *
     * @return string|null The order's status afterward, or null if it doesn't exist.
     */
    private static function complete_order_if_fulfilled( int $order_id, array $completion ): ?string {
        return MMI_Xchange_Fulfillment_Queue::complete_if_fulfilled( $order_id, $completion );
    }

    /**
     * Combined fulfillment, step 2 of 3 (step 1 is one place_order() call per
     * item, step 3 send_bundle_email()): the server-side display data for
     * every item in a customer group — product name/logo/vendor/links
     * resolved from each SKU exactly as send_bundle_email() will render
     * them, plus any delivery data already recorded for the item (licenses
     * returned by place_order(), or a PO placed earlier). Read-only.
     *
     * POST items: JSON list of {order_id, sku}.
     */
    public static function prepare_bundle(): void {
        self::guard();

        $items = self::decode_bundle_items();
        if ( is_string( $items ) ) {
            wp_send_json_error( [ 'message' => $items ] );
        }

        $prepared = [];
        foreach ( $items as $item ) {
            $order   = $item['order'];
            $display = self::bundle_item_display( $item['sku'] );
            $line    = MMI_Software_Fulfillment::find_item( $order, $item['sku'] );
            $record  = $line ? ( MMI_Software_Fulfillment::get_record( $line ) ?? [] ) : [];

            $prepared[] = array_merge( $display, [
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'sku'          => $item['sku'],
                'status'       => (string) ( $record['status'] ?? '' ),
                'po_number'    => (string) ( $record['po_number'] ?? '' ),
                'license_key'  => (string) ( $record['license_key'] ?? '' ),
                'download_url' => (string) ( $record['download_url'] ?? '' ),
                'support_url'  => (string) ( $record['support_url'] ?? '' ),
            ] );
        }

        wp_send_json_success( [ 'items' => $prepared ] );
    }

    /**
     * Combined fulfillment, step 3 of 3: ONE customer email covering every
     * item, then each item is marked fulfilled (and each order completed
     * once all its XChange items are). Product identity is re-derived from
     * each SKU server-side, never taken from the modal — same guarantee as
     * send_fulfillment_email() (see its 2026-09-22 incident note). A test
     * send goes to the site admin and touches no order state.
     *
     * POST items: JSON list of {order_id, sku, po_number, license_key, download_url, support_url}.
     */
    public static function send_bundle_email(): void {
        self::guard();

        $is_test = ! empty( $_POST['is_test'] );
        $to      = $is_test
            ? sanitize_email( (string) get_option( 'admin_email' ) )
            : sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
        if ( $to === '' || ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => __( 'Enter a valid recipient email address.', 'mmi-xchange-integration' ) ] );
        }

        $items = self::decode_bundle_items();
        if ( is_string( $items ) ) {
            wp_send_json_error( [ 'message' => $items ] );
        }

        $email_items = [];
        foreach ( $items as $item ) {
            $raw = $item['raw'];
            if ( sanitize_text_field( (string) ( $raw['po_number'] ?? '' ) ) === '' && ! $is_test ) {
                wp_send_json_error( [ 'message' => sprintf(
                    /* translators: 1: SKU, 2: order number */
                    __( '%1$s on order #%2$s has no PO yet — place it before sending.', 'mmi-xchange-integration' ),
                    $item['sku'],
                    $item['order']->get_order_number()
                ) ] );
            }
            $email_items[] = array_merge( self::bundle_item_display( $item['sku'] ), [
                'order_id'     => $item['order']->get_id(),
                'order_number' => $item['order']->get_order_number(),
                'sku'          => $item['sku'],
                'po_number'    => sanitize_text_field( (string) ( $raw['po_number'] ?? '' ) ),
                'license_key'  => sanitize_text_field( (string) ( $raw['license_key'] ?? '' ) ),
                'download_url' => esc_url_raw( (string) ( $raw['download_url'] ?? '' ) ),
                'support_url'  => self::sanitize_support_contact( (string) ( $raw['support_url'] ?? '' ) ),
            ] );
        }

        $customer_name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        $order_ids     = array_column( $email_items, 'order_id' );
        $result        = MMI_Software_Fulfillment::send_bundle_email( $to, $customer_name, $email_items, $order_ids, $is_test );

        mmi_xchange_audit( 'email.bundle_send', [
            'object_type' => 'order',
            'object_id'   => implode( ',', array_unique( $order_ids ) ),
            'outcome'     => $result['success'] ? 'success' : 'failure',
            'details'     => [
                'to'      => $to,
                'items'   => array_map( static fn( $i ) => [ 'order_id' => $i['order_id'], 'sku' => $i['sku'], 'po' => $i['po_number'] ], $email_items ),
                'is_test' => $is_test,
            ],
        ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
        if ( $is_test ) {
            wp_send_json_success( [ 'message' => sprintf(
                /* translators: %s: admin email address */
                __( 'Test email sent to %s.', 'mmi-xchange-integration' ),
                $to
            ) ] );
        }

        $orders = self::finalize_bundle_send( $email_items );

        wp_send_json_success( [
            'message' => $result['message'],
            'orders'  => $orders,
        ] );
    }

    /**
     * Parses and validates POST items for the two bundle endpoints: every
     * order must exist and every SKU must be an XChange line item on its
     * own order — so a stale or tampered modal can't attach a license to
     * the wrong order.
     *
     * @return array<int,array{order:\WC_Order, sku:string, raw:array}>|string List, or an error message.
     */
    private static function decode_bundle_items() {
        if ( ! class_exists( 'MMI_Software_Fulfillment' ) ) {
            return __( 'Software fulfillment core is unavailable.', 'mmi-xchange-integration' );
        }

        $raw_items = json_decode( wp_unslash( (string) ( $_POST['items'] ?? '' ) ), true );
        if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
            return __( 'No items to fulfill.', 'mmi-xchange-integration' );
        }

        $items = [];
        foreach ( $raw_items as $raw ) {
            $order = wc_get_order( absint( $raw['order_id'] ?? 0 ) );
            $sku   = sanitize_text_field( (string) ( $raw['sku'] ?? '' ) );
            if ( ! $order || $sku === '' || ! MMI_Software_Fulfillment::find_item( $order, $sku ) ) {
                return sprintf(
                    /* translators: 1: SKU, 2: order ID */
                    __( '%1$s is not an XChange item on order #%2$d.', 'mmi-xchange-integration' ),
                    $sku,
                    absint( $raw['order_id'] ?? 0 )
                );
            }
            $items[] = [ 'order' => $order, 'sku' => $sku, 'raw' => $raw ];
        }
        return $items;
    }

    /**
     * Customer-facing product identity for an XChange SKU, from the WC
     * catalog: name, featured image, product page, and product_brand term
     * (vendor name + archive link). No XChange API call.
     *
     * @return array{name:string, logo_url:string, product_url:string, vendor_name:string, brand_url:string}
     */
    public static function bundle_item_display( string $sku ): array {
        $display    = [ 'name' => $sku, 'logo_url' => '', 'product_url' => '', 'vendor_name' => '', 'brand_url' => '' ];
        $product_id = self::resolve_product_id_for_xchange_sku( $sku );
        $product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
        if ( ! $product ) {
            return $display;
        }

        $display['name']        = $product->get_name();
        $display['logo_url']    = get_the_post_thumbnail_url( $product_id, 'medium' ) ?: '';
        $display['product_url'] = (string) get_permalink( $product_id );

        $brands = get_the_terms( $product_id, 'product_brand' );
        if ( is_array( $brands ) && ! empty( $brands ) ) {
            $display['vendor_name'] = $brands[0]->name;
            $link                   = get_term_link( $brands[0], 'product_brand' );
            $display['brand_url']   = is_wp_error( $link ) ? '' : $link;
        }
        return $display;
    }

    /**
     * XChange's support_contact is sometimes a URL and sometimes a bare
     * email address — keep an address usable as a mailto: link instead of
     * letting esc_url_raw() mangle it.
     */
    private static function sanitize_support_contact( string $value ): string {
        $value = trim( wp_unslash( $value ) );
        if ( is_email( $value ) ) {
            return 'mailto:' . sanitize_email( $value );
        }
        return esc_url_raw( $value );
    }

    /**
     * Look up a WooCommerce product by SKU so the Place Order tab's Email
     * Customer panel can auto-fill the software name + logo (product
     * featured image) once the admin has entered/placed an order for a SKU.
     */
    public static function lookup_product(): void {
        self::guard();

        $sku        = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        $product_id = self::resolve_product_id_for_xchange_sku( $sku );

        if ( ! $product_id ) {
            wp_send_json_success( [ 'found' => false ] );
        }

        $product = wc_get_product( $product_id );

        wp_send_json_success( [
            'found'    => true,
            'name'     => $product ? $product->get_name() : '',
            'logo_url' => get_the_post_thumbnail_url( $product_id, 'medium' ) ?: '',
        ] );
    }

    /**
     * XChange SKU → WC product ID, exact match only. Tries the same
     * supplier-SKU meta preview_product() uses (MMI_Xchange_Vendors), then
     * the WC SKU itself — shared by lookup_product() and
     * send_fulfillment_email() so the modal's auto-fill and the email's
     * server-side re-derivation can never disagree about which product a
     * SKU means.
     */
    public static function resolve_product_id_for_xchange_sku( string $sku ): int {
        if ( $sku === '' ) {
            return 0;
        }

        $product_id = class_exists( 'MMI_Xchange_Vendors' )
            ? (int) MMI_Xchange_Vendors::find_product_id_by_xchange_sku( $sku )
            : 0;

        return $product_id > 0 ? $product_id : (int) wc_get_product_id_by_sku( $sku );
    }

    /* ── Account ───────────────────────────────────────────────────────────── */

    /**
     * These two previously duplicated guard()'s nonce check inline but
     * checked manage_options instead of manage_woocommerce — stricter than
     * the capability the XChange page itself (and every other handler in
     * this file) is actually gated at, so a Shop Manager who can see the
     * page and these buttons would get a silent "Insufficient permissions"
     * on click. Switched to the shared guard() for consistency.
     */
    public static function check_connection(): void {
        self::guard();

        wp_send_json_success( MMI_Xchange_Account::check_connection() );
    }

    /**
     * See MMI_Xchange_Account::check_order_api() — a real PUT /orders/ call
     * against XChange's own test vendor, answering "does direct order
     * placement work, or is this account still blocked (E033)?" on demand,
     * without touching a real customer order.
     */
    public static function check_order_api(): void {
        self::guard();

        wp_send_json_success( MMI_Xchange_Account::check_order_api() );
    }

    /* ── Logs ──────────────────────────────────────────────────────────────── */

    /**
     * Powers the Logs tab — reads/filters MMI_Logger's 'xchange' category
     * (mmi-hub/logs/xchange.log). See MMI_Xchange_Logs::get_entries().
     */
    public static function get_logs(): void {
        self::guard( 'admin' );

        wp_send_json_success( MMI_Xchange_Logs::get_entries( [
            'level'  => sanitize_text_field( wp_unslash( $_POST['level']  ?? 'all' ) ),
            'source' => sanitize_text_field( wp_unslash( $_POST['source'] ?? 'all' ) ),
            'search' => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
            'limit'  => absint( $_POST['limit'] ?? 300 ),
        ] ) );
    }

    /**
     * Deletes the entire 'xchange' log file. Destructive — gated the same
     * as every other action in this file (capability + nonce); the client
     * additionally shows a confirm() before calling this.
     */
    public static function clear_logs(): void {
        self::guard( 'admin' );

        MMI_Xchange_Logs::clear();
        mmi_xchange_audit( 'logs.clear', [ 'outcome' => 'success', 'details' => [ 'category' => MMI_Xchange_Logs::CATEGORY ] ] );

        wp_send_json_success( [ 'message' => __( 'XChange log cleared.', 'mmi-xchange-integration' ) ] );
    }

    /**
     * Backs the header mode switcher (see MMI_Xchange_Admin_Menu::render_page()).
     * Saves immediately on toggle, matching mmi-reverb-integration's header
     * switcher — but unlike Reverb, XChange has no real sandbox: engine=test
     * has no pricing data for any product outside XChange's own test vendor,
     * and engine=live spends real money on every reserve/finalize/place_order
     * call (see AGENTS.md's "XChange engine=test Has No Real-Product Pricing"
     * Incident History entry). The client shows a confirm() dialog before
     * switching to live — this endpoint only enforces capability + nonce, the
     * confirm() is a speed bump against misclicks, not a security boundary.
     */
    public static function set_mode(): void {
        self::guard( 'admin' );

        $mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'test' ) );
        if ( ! in_array( $mode, [ 'live', 'test' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid mode.', 'mmi-xchange-integration' ) ] );
        }

        $previous = MMI_Settings::get( 'mmi_xchange_production_mode' ) === 'yes' ? 'live' : 'test';
        MMI_Settings::set( 'mmi_xchange_production_mode', $mode === 'live' ? 'yes' : 'no' );

        mmi_xchange_audit( 'settings.update', [
            'outcome' => 'success',
            'details' => [ 'keys' => [ 'mmi_xchange_production_mode' ], 'from' => $previous, 'to' => $mode ],
        ] );

        MMI_Logger::info(
            'XChange engine mode switched to ' . $mode . ' by ' . wp_get_current_user()->user_login,
            [], 'xchange', 'MMI_Xchange_Ajax'
        );

        wp_send_json_success( [ 'mode' => $mode ] );
    }

    /* ── Vendors ───────────────────────────────────────────────────────────── */

    public static function get_vendors(): void {
        self::guard();

        $vendors = MMI_Xchange_Vendors::get_vendors();
        if ( is_wp_error( $vendors ) ) {
            wp_send_json_error( [ 'message' => $vendors->get_error_message() ] );
        }

        wp_send_json_success( [ 'vendors' => $vendors ] );
    }

    /**
     * Local-only image-readiness rollup (site-wide totals + per-vendor
     * breakdown) — zero live Xchange API calls, see
     * MMI_Xchange_Vendors::get_local_image_readiness(). Safe to fire on
     * every Vendors-tab load.
     */
    public static function vendor_image_stats(): void {
        self::guard();
        wp_send_json_success( MMI_Xchange_Vendors::get_local_image_readiness() );
    }

    /**
     * Read-only, single-vendor dry run of the image import — one throttled
     * live API call, same cost as one "Import Images" click for that vendor.
     * See MMI_Xchange_Vendors::analyze_vendor_images(). Deliberately no
     * "_all" option here; a full-catalog preview belongs behind the same
     * Action Scheduler dispatch the real bulk import already uses.
     */
    public static function preview_vendor_images(): void {
        self::guard();

        $vendor_id = sanitize_text_field( wp_unslash( $_POST['vendor'] ?? '' ) );
        if ( $vendor_id === '' || $vendor_id === '_all' ) {
            wp_send_json_error( [ 'message' => __( 'Pick a single vendor to preview.', 'mmi-xchange-integration' ) ] );
        }

        $result = MMI_Xchange_Vendors::analyze_vendor_images( $vendor_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Queues an image import (single vendor, or "_all") — dispatched via
     * Action Scheduler (see MMI_Xchange_Vendors::queue_import_media()), so
     * this returns instantly and the caller polls import_media_status().
     */
    public static function import_media(): void {
        self::guard();

        $vendor_id = sanitize_text_field( wp_unslash( $_POST['vendor'] ?? '' ) );
        if ( $vendor_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter a vendor ID (or "_all") first.', 'mmi-xchange-integration' ) ] );
        }

        MMI_Xchange_Vendors::queue_import_media( $vendor_id === '_all' ? null : $vendor_id );
        mmi_xchange_audit( 'media.import_queue', [ 'outcome' => 'success', 'details' => [ 'vendor' => $vendor_id ] ] );
        wp_send_json_success( [ 'message' => __( 'Image import queued.', 'mmi-xchange-integration' ) ] );
    }

    /**
     * Polled by the Vendors tab's progress bar while a queued/running image
     * import progresses in the background.
     */
    public static function import_media_status(): void {
        self::guard();
        wp_send_json_success( MMI_Xchange_Vendors::get_import_media_progress() );
    }

    /* ── COGS backfill ─────────────────────────────────────────────────────── */

    public static function cogs_status(): void {
        self::guard( 'admin' );

        wp_send_json_success( MMI_Xchange_COGS::backfill_status() );
    }

    public static function cogs_backfill_batch(): void {
        self::guard( 'admin' );

        $offset = absint( $_POST['offset'] ?? 0 );
        if ( $offset === 0 ) {
            // Once per run (the JS loops batches by offset), not per batch.
            mmi_xchange_audit( 'cogs.backfill_start', [ 'outcome' => 'success' ] );
        }
        wp_send_json_success( MMI_Xchange_COGS::run_backfill_batch( $offset ) );
    }

    /**
     * Price History chart data for one SKU — "📈 Price History" button in
     * the Recent Orders detail table and the standalone Preview panel.
     * Pre-aggregated by price_type server-side so the browser never
     * reconstructs series from raw rows.
     */
    public static function price_history_chart(): void {
        self::guard();

        $sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        if ( $sku === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing XChange SKU.', 'mmi-xchange-integration' ) ] );
        }

        $series = [
            MMI_Xchange_Price_History::TYPE_DEALER     => [],
            MMI_Xchange_Price_History::TYPE_PROMO      => [],
            MMI_Xchange_Price_History::TYPE_WC_REGULAR => [],
            MMI_Xchange_Price_History::TYPE_WC_SALE    => [],
        ];

        foreach ( MMI_Xchange_Price_History::get_history_for_sku( $sku ) as $row ) {
            if ( ! isset( $series[ $row['price_type'] ] ) ) {
                continue;
            }
            $series[ $row['price_type'] ][] = [
                't'              => $row['recorded_at'],
                'y'              => (float) $row['price'],
                'promotion_name' => $row['promotion_name'],
            ];
        }

        wp_send_json_success( [ 'sku' => $sku, 'series' => $series ] );
    }

}
