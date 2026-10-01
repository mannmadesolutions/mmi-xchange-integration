<?php
/**
 * MMI_Xchange_Fulfillment_Queue
 *
 * Finds WooCommerce orders containing XChange-sourced products (matched via
 * the same _mmi_supplier_sku_xchange/sku_xchange product meta precedence as
 * MMI_Xchange_API::get_product_xchange_sku() — NOT the _xchange_internal_sku
 * meta MMI_Xchange_Checkout's automatic reserve/finalize flow keys off,
 * which zero products in this store actually carry, making that automatic
 * path effectively dead code) so the Place Order tab's Recent Orders panel
 * can surface them for manual fulfillment.
 *
 * There is no existing "already fulfilled via XChange" signal for a plain
 * WooCommerce order, so this class owns a small tracking meta of its own
 * (_mmi_xchange_fulfilled_po), written once the admin actually sends the
 * fulfillment email for an order picked from the queue.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Fulfillment_Queue {

    const FULFILLED_META_KEY      = '_mmi_xchange_fulfilled_po';
    const FULFILLED_SKUS_META_KEY = '_mmi_xchange_fulfilled_skus';
    const COST_SNAPSHOT_META_KEY  = '_mmi_xchange_cost_snapshot';

    /**
     * Recent WC orders (any status) with at least one XChange-sourced line
     * item. Bounded to a recent window + row cap regardless of store size —
     * this is a triage/reference panel, not exhaustive search (the existing
     * Orders tab already covers full XChange PO history search).
     *
     * The matching step is one indexed SQL join across HPOS's wc_orders,
     * the (HPOS-independent) order-items tables, and postmeta — not an N+1
     * loop over individually-loaded orders. Only the bounded result set
     * (at most $limit orders) is then hydrated via wc_get_order() for
     * display fields.
     *
     * @return array<int,array{
     *   order_id:int, order_number:string, edit_url:string, date:string,
     *   status:string, customer_name:string, customer_email:string,
     *   is_guest:bool, customer_edit_url:string, reverb_automation_active:bool,
     *   email_request_status:string, email_request_sent_at:string, email_request_converted_at:string,
     *   total:string, currency:string, fulfilled_po:string,
     *   source_label:string, source_logo_url:string, source_order_url:string, xchange_license:string,
     *   xchange_auth:string, xchange_vendor:string, xchange_price:?float,
     *   xchange_in_ccsa:bool,
     *   email_history:array<int,array{sent_at:string,to:string,success:bool,message:string}>,
     *   items:array<int,array{sku:string,product:string,qty:int,line_total:float,image_url:string}>
     * }>
     */
    public static function get_recent_orders( int $days = 90, int $limit = 100 ): array {
        global $wpdb;

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT o.id
             FROM {$wpdb->prefix}wc_orders o
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                     ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                     ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}postmeta pm
                     ON pm.post_id = oim.meta_value
                    AND pm.meta_key IN ('_mmi_supplier_sku_xchange', 'sku_xchange')
                    AND pm.meta_value != ''
             WHERE o.type = 'shop_order' AND o.date_created_gmt >= %s
             ORDER BY o.date_created_gmt DESC
             LIMIT %d",
            $since,
            $limit
        ) );

        $orders = [];
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }

            $items = self::xchange_items( $order );
            if ( empty( $items ) ) {
                continue;
            }

            $name   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            // MMI_Marketplace_Order_Sources is the single source of truth for
            // "which marketplace, if any, did this order come from" — every
            // registered marketplace (Reverb today, others later) resolves
            // through the same lookup, so this panel's badge can never
            // silently disagree with MMI_Xchange_Checkout::is_externally_sourced()
            // (which delegates to the same registry) or drift as new
            // marketplaces are added.
            //
            // MMI_Marketplace_Order_Sources lived in mmi-hub, deleted
            // suite-wide 2026-09-17 without being migrated — it no longer
            // exists, so $source is always null here and this badge never
            // shows a marketplace. Purely cosmetic: the actual fulfillment
            // block for externally-sourced orders lives in
            // MMI_Xchange_Checkout::is_externally_sourced(), which fails
            // closed independently of this display value.
            $source = class_exists( 'MMI_Marketplace_Order_Sources' )
                ? MMI_Marketplace_Order_Sources::get_source_for_order( $order )
                : null;

            $orders[] = [
                'order_id'        => $order->get_id(),
                'order_number'    => $order->get_order_number(),
                'edit_url'        => $order->get_edit_order_url(),
                'date'            => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                'status'          => $order->get_status(),
                'customer_name'      => $name !== '' ? $name : $order->get_billing_email(),
                'customer_email'     => $order->get_billing_email(),
                'is_guest'           => $order->get_customer_id() === 0,
                'customer_edit_url'  => $order->get_customer_id() > 0
                    ? get_edit_user_link( $order->get_customer_id() )
                    : '',
                // MMI_Guest_Customer_Converter owns this detection — reused
                // here so the "looks relayed, ask for the real email" hint
                // matches exactly what the converter itself would show.
                // That class lived in mmi-hub, deleted suite-wide 2026-09-17
                // without being migrated, so this always evaluates false —
                // the hint no longer appears. Cosmetic only.
                'looks_relayed'   => class_exists( 'MMI_Guest_Customer_Converter' )
                    && MMI_Guest_Customer_Converter::looks_like_relay_email( $order->get_billing_email() ),
                // mmi-reverb-integration's automatic "please reply with your
                // real email" flow (MMI_Reverb_Email_Request_Manager) — when
                // active, it replaces this panel's manual "Link to Customer"
                // form for a guest/relayed order with a read-only status
                // readout instead (see renderGuestConvertHtml() in
                // admin-xchange.js), since the automation already handles it.
                'reverb_automation_active' => class_exists( 'MMI_Reverb_Email_Request_Manager' )
                    && MMI_Reverb_Email_Request_Manager::is_enabled(),
                // Plain "is mmi-reverb-integration installed and active"
                // check, independent of the auto-request setting above —
                // drives the guest-convert widget's manual "📨 Request via
                // Reverb Message" button (MMI_Xchange_Ajax::
                // request_guest_email()), which an admin can click whether
                // or not the automatic flow's own toggle is on.
                'reverb_available' => class_exists( 'MMI_Reverb_API_Client' ),
                'email_request_status'     => class_exists( 'MMI_Reverb_Email_Request_Manager' )
                    ? (string) $order->get_meta( MMI_Reverb_Email_Request_Manager::META_STATUS, true )
                    : '',
                'email_request_sent_at'      => class_exists( 'MMI_Reverb_Email_Request_Manager' )
                    ? (string) $order->get_meta( MMI_Reverb_Email_Request_Manager::META_SENT_AT, true )
                    : '',
                'email_request_converted_at' => class_exists( 'MMI_Reverb_Email_Request_Manager' )
                    ? (string) $order->get_meta( MMI_Reverb_Email_Request_Manager::META_CONVERTED_AT, true )
                    : '',
                // Order number whose Reverb request this order joined (one
                // request per buyer — see MMI_Reverb_Email_Request_Manager::
                // share_request_with_siblings()), '' when it's its own.
                'email_request_shared_with'  => self::shared_request_order_number( $order ),
                'total'           => $order->get_total(),
                'currency'        => $order->get_currency(),
                'fulfilled_po'    => (string) $order->get_meta( self::FULFILLED_META_KEY, true ),
                'source_label'    => $source['label'] ?? '',
                'source_logo_url' => $source['logo_url'] ?? '',
                'source_order_url' => $source['order_url'] ?? '',
                // Last fulfillment-email attempt (success or failure) —
                // previously only visible in MMI_Logger's log file, not
                // from this UI, which is exactly where an admin looks after
                // a customer says they never received it.
                'email_history'   => self::email_history( $order->get_id() ),
                // Same customer across separate orders — see
                // MMI_Software_Fulfillment::customer_key(). The queue JS
                // groups rows sharing a key into one combined fulfillment.
                'customer_key'    => class_exists( 'MMI_Software_Fulfillment' )
                    ? MMI_Software_Fulfillment::customer_key( $order )
                    : 'o:' . $order->get_id(),
                // Xchange PO enrichment (license/auth/vendor/CCSA/price)
                // filled in below via one batched lookup — never once per
                // order.
                'xchange_license'    => '',
                'xchange_auth'       => '',
                'xchange_vendor'     => '',
                'xchange_price'      => null,
                'xchange_in_ccsa'    => false,
                'items'              => $items,
            ];
        }

        // One batched lookup for every fulfilled PO on this page, instead of
        // an N+1 query per order — see get_orders_by_po_numbers()'s own note.
        $po_numbers   = array_column( $orders, 'fulfilled_po' );
        $xchange_rows = class_exists( 'MMI_Xchange_Order_Sync' )
            ? MMI_Xchange_Order_Sync::get_orders_by_po_numbers( $po_numbers )
            : [];

        foreach ( $orders as &$order_row ) {
            $po = $order_row['fulfilled_po'];
            if ( $po === '' || ! isset( $xchange_rows[ $po ] ) ) {
                continue;
            }
            $xrow = $xchange_rows[ $po ];
            $order_row['xchange_license'] = $xrow['license_key'];
            $order_row['xchange_auth']    = $xrow['auth'];
            $order_row['xchange_vendor']  = $xrow['vendor_name'];
            $order_row['xchange_price']   = $xrow['price'];
            $order_row['xchange_in_ccsa'] = $xrow['in_ccsa'];
        }
        unset( $order_row );

        return $orders;
    }

    /**
     * WC orders (any status, any age) containing an XChange-sourced line
     * item that have never been linked to a fulfillment PO
     * (_mmi_xchange_fulfilled_po absent/empty) — the backlog of orders that
     * were fulfilled by hand on the XChange portal before this plugin's
     * Fulfillment Queue / manual-sync UI existed. See MMI_Xchange_Po_Linker,
     * the sole consumer, for what happens with this list.
     *
     * Deliberately NOT get_recent_orders() with a wider window: that method
     * orders by date DESC with a fixed LIMIT, so a naive PHP-side "skip
     * already-linked ones" filter would systematically miss the orders this
     * exists to find — recent orders are already linked in the normal
     * course of business, so they'd fill the LIMIT before an old orphaned
     * order is ever reached. The unlinked filter has to happen in the SQL
     * itself (the LEFT JOIN below), not after fetching a fixed page.
     *
     * Ordered oldest-first and paginated via $limit/$offset so a repeated
     * "Scan Next Batch" click works through the real backlog in a stable,
     * non-overlapping, boundable order (per this project's rule against an
     * admin action running an unbounded query).
     *
     * @return array<int,array{
     *   order_id:int, order_number:string, edit_url:string, date:string,
     *   status:string, customer_name:string, customer_email:string,
     *   reverb_order_number:string,
     *   items:array<int,array{sku:string,product:string,qty:int,line_total:float}>
     * }>
     */
    public static function get_unlinked_orders( int $limit = 200, int $offset = 0 ): array {
        global $wpdb;

        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT o.id
             FROM {$wpdb->prefix}wc_orders o
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                     ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                     ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}postmeta pm
                     ON pm.post_id = oim.meta_value
                    AND pm.meta_key IN ('_mmi_supplier_sku_xchange', 'sku_xchange')
                    AND pm.meta_value != ''
             LEFT JOIN {$wpdb->prefix}wc_orders_meta wom
                    ON wom.order_id = o.id AND wom.meta_key = %s
             WHERE o.type = 'shop_order'
               AND ( wom.meta_value IS NULL OR wom.meta_value = '' )
             ORDER BY o.date_created_gmt ASC
             LIMIT %d OFFSET %d",
            self::FULFILLED_META_KEY,
            $limit,
            $offset
        ) );

        $orders = [];
        foreach ( $order_ids as $order_id ) {
            $row = self::hydrate_for_matching( (int) $order_id );
            if ( $row !== null ) {
                $orders[] = $row;
            }
        }

        return $orders;
    }

    /**
     * Single-order counterpart to get_unlinked_orders() above — hydrates
     * exactly one order in the same shape MMI_Xchange_Po_Linker's matching
     * logic expects, for the Fulfillment Queue row's own inline "Find
     * Candidate PO" button (see MMI_Xchange_Po_Linker::find_candidate_for_order()).
     * Doesn't re-check XChange-item/unlinked status itself — the caller
     * (the manual-sync UI) only ever shows that button for a row already
     * known to need it.
     *
     * @return array{order_id:int, order_number:string, edit_url:string, date:string, status:string, customer_name:string, customer_email:string, reverb_order_number:string, items:array<int,array{sku:string,product:string,qty:int,line_total:float}>}|null
     */
    public static function get_order_for_matching( int $order_id ): ?array {
        return self::hydrate_for_matching( $order_id );
    }

    /**
     * @return array{order_id:int, order_number:string, edit_url:string, date:string, status:string, customer_name:string, customer_email:string, reverb_order_number:string, items:array<int,array{sku:string,product:string,qty:int,line_total:float}>}|null
     */
    private static function hydrate_for_matching( int $order_id ): ?array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $items = self::xchange_items( $order );
        if ( empty( $items ) ) {
            return null;
        }

        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        return [
            'order_id'             => $order->get_id(),
            'order_number'         => $order->get_order_number(),
            'edit_url'             => $order->get_edit_order_url(),
            'date'                 => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
            'status'               => $order->get_status(),
            'customer_name'        => $name !== '' ? $name : $order->get_billing_email(),
            'customer_email'       => $order->get_billing_email(),
            // Free-text reference field mmi-reverb-integration writes on
            // import — the other half of "the PO is almost always the
            // WC or Reverb order number" that MMI_Xchange_Po_Linker
            // matches against.
            'reverb_order_number'  => (string) $order->get_meta( '_mmi_reverb_order_number', true ),
            'items'                => $items,
        ];
    }

    /**
     * True when at least one line item on $order resolves to an
     * XChange-sourced product (same SKU precedence as xchange_items()
     * below). Public wrapper so other classes needing only a yes/no answer
     * — e.g. MMI_Xchange_Guest_Email_Request's "does this order need our
     * automated email request?" check — don't duplicate the variation/
     * parent SKU-resolution logic.
     */
    private static function shared_request_order_number( \WC_Order $order ): string {
        if ( ! defined( 'MMI_Reverb_Email_Request_Manager::META_SHARED_WITH' ) ) {
            return '';
        }
        $primary_id = (int) $order->get_meta( MMI_Reverb_Email_Request_Manager::META_SHARED_WITH, true );
        $primary    = $primary_id > 0 ? wc_get_order( $primary_id ) : false;
        return $primary ? (string) $primary->get_order_number() : '';
    }

    public static function has_xchange_item( \WC_Order $order ): bool {
        return ! empty( self::xchange_items( $order ) );
    }

    /**
     * @return array<int,array{sku:string,product:string,qty:int,line_total:float}>
     */
    private static function xchange_items( \WC_Order $order ): array {
        $items            = [];
        $legacy_fulfilled = self::get_fulfilled_skus( $order );

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $sku = '';
            if ( $item->get_variation_id() ) {
                $sku = MMI_Xchange_API::get_product_xchange_sku( $item->get_variation_id() );
            }
            if ( $sku === '' ) {
                $sku = MMI_Xchange_API::get_product_xchange_sku( $item->get_product_id() );
            }
            if ( $sku === '' ) {
                continue;
            }

            // Shared per-item record: 'placed' (a PO was bought, customer
            // not yet emailed) or 'fulfilled' — see MMI_Software_Fulfillment.
            $record = class_exists( 'MMI_Software_Fulfillment' ) && $item instanceof \WC_Order_Item_Product
                ? ( MMI_Software_Fulfillment::get_record( $item ) ?? [] )
                : [];
            // Fulfilled before per-item records existed — the legacy per-SKU
            // PO map is the only evidence.
            if ( empty( $record ) && isset( $legacy_fulfilled[ $sku ] ) ) {
                $record = [ 'status' => 'fulfilled', 'po_number' => $legacy_fulfilled[ $sku ] ];
            }

            $items[] = [
                'sku'         => $sku,
                'fulfillment_status' => (string) ( $record['status'] ?? '' ),
                'placed_po'          => (string) ( $record['po_number'] ?? '' ),
                'product'     => $product->get_name(),
                'qty'         => (int) $item->get_quantity(),
                // What the customer actually paid for this line (ex-tax) —
                // the basis the Recent Orders panel compares against a live
                // XChange dealer-cost lookup before the admin fulfills.
                'line_total'  => (float) $item->get_total(),
                // Featured-image thumbnail shown next to the item title in
                // the queue row — falls back to the parent product's image
                // for a variation with none of its own (get_image_id()
                // already does this internally via WC_Product_Variation).
                'image_url'   => get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' ) ?: '',
            ];
        }

        return $items;
    }

    /**
     * Last 3 customer-email attempts for an order: single-product sends
     * (MMI_Xchange_Fulfillment_Email) and combined sends
     * (MMI_Software_Fulfillment), merged oldest-first in one shape.
     *
     * @return array<int,array{sent_at:string,to:string,success:bool,message:string}>
     */
    private static function email_history( int $order_id ): array {
        $history = class_exists( 'MMI_Xchange_Fulfillment_Email' )
            ? MMI_Xchange_Fulfillment_Email::get_history( $order_id )
            : [];

        if ( class_exists( 'MMI_Software_Fulfillment' ) ) {
            foreach ( MMI_Software_Fulfillment::get_email_log( $order_id ) as $entry ) {
                $history[] = [
                    'sent_at' => $entry['sent_at'],
                    'to'      => $entry['to'],
                    'success' => $entry['success'],
                    'message' => sprintf(
                        /* translators: 1: number of products, 2: result message */
                        _n( 'Combined email (%1$d product): %2$s', 'Combined email (%1$d products): %2$s', (int) $entry['items'], 'mmi-xchange-integration' ),
                        (int) $entry['items'],
                        $entry['message']
                    ),
                ];
            }
            usort( $history, static fn( $a, $b ) => strcmp( (string) $a['sent_at'], (string) $b['sent_at'] ) );
        }

        return array_slice( $history, -3 );
    }

    /**
     * Tags a WC order as fulfilled via a manually-placed XChange PO — called
     * once the admin actually sends the fulfillment email for an order
     * picked from the Recent Orders panel. Uses the WC_Order object API
     * (not get_post_meta/update_post_meta) so this is correct under HPOS.
     *
     * Tracks fulfillment per-SKU (FULFILLED_SKUS_META_KEY), not just the
     * order's single most recent PO (FULFILLED_META_KEY, kept for the
     * "Fulfilled" column's existing display) — added 2026-08-24 so a
     * multi-item order can be told apart from a fully-fulfilled one; see
     * fulfillment_completion_status(). $sku is optional only so a caller
     * without it degrades to the old single-PO-only behavior; every call
     * site in this plugin passes it.
     *
     * @return array{fully_fulfilled:bool, xchange_item_count:int, fulfilled_count:int, has_non_xchange_items:bool}
     */
    public static function mark_fulfilled( int $order_id, string $po_number, string $sku = '', array $delivery = [] ): array {
        $empty_status = [ 'fully_fulfilled' => false, 'xchange_item_count' => 0, 'fulfilled_count' => 0, 'has_non_xchange_items' => false ];

        $order = wc_get_order( $order_id );
        if ( ! $order || $po_number === '' ) {
            return $empty_status;
        }

        $order->update_meta_data( self::FULFILLED_META_KEY, $po_number );

        if ( $sku !== '' ) {
            $fulfilled_skus         = self::get_fulfilled_skus( $order );
            $fulfilled_skus[ $sku ] = $po_number;
            $order->update_meta_data( self::FULFILLED_SKUS_META_KEY, wp_json_encode( $fulfilled_skus ) );
        }

        $order->save();

        // Shared per-line-item record (MMI_Software_Fulfillment) — what the
        // queue's customer grouping and duplicate-purchase guard read.
        // $delivery: license_key/download_url/support_url, when known.
        if ( $sku !== '' && class_exists( 'MMI_Software_Fulfillment' ) ) {
            MMI_Software_Fulfillment::record_fulfillment( $order_id, $sku, array_merge( $delivery, [ 'po_number' => $po_number ] ) );
        }
        $order->add_order_note( sprintf(
            /* translators: %s: XChange PO number */
            __( 'XChange fulfillment email sent to the customer — PO #%s.', 'mmi-xchange-integration' ),
            $po_number
        ) );

        return self::fulfillment_completion_status( $order );
    }

    /**
     * @return array<string,string> XChange sku => the PO number it was fulfilled with
     */
    public static function get_fulfilled_skus( \WC_Order $order ): array {
        $raw     = $order->get_meta( self::FULFILLED_SKUS_META_KEY, true );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [];
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Records what XChange actually cost MMI for one line item at the exact
     * moment MMI_Xchange_Ajax::place_order() places the real vendor
     * purchase — the only point in the whole fulfillment flow that reflects
     * what was genuinely true "at time of purchase" rather than a live
     * re-lookup. Written once per sku per order and never overwritten, same
     * write-once intent as mark_fulfilled()/get_fulfilled_skus() above, just
     * for cost instead of PO number. See MMI_Xchange_Order_Sync::preview_product()
     * for the read side and capture_cost_snapshot() for what $snapshot holds.
     *
     * @param array{dealer_price:float,is_promo_active:bool,promo_price:?float,promotion_name:string,captured_at:string} $snapshot
     */
    public static function record_cost_snapshot( int $order_id, string $sku, array $snapshot ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || $sku === '' ) {
            return;
        }

        $snapshots         = self::get_cost_snapshots( $order );
        $snapshots[ $sku ] = $snapshot;
        $order->update_meta_data( self::COST_SNAPSHOT_META_KEY, wp_json_encode( $snapshots ) );
        $order->save();
    }

    /**
     * @return array<string,array{dealer_price:float,is_promo_active:bool,promo_price:?float,promotion_name:string,captured_at:string}>
     */
    public static function get_cost_snapshots( \WC_Order $order ): array {
        $raw     = $order->get_meta( self::COST_SNAPSHOT_META_KEY, true );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [];
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @return array{dealer_price:float,is_promo_active:bool,promo_price:?float,promotion_name:string,captured_at:string}|null
     */
    public static function get_cost_snapshot_for_order( int $order_id, string $sku ): ?array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }
        return self::get_cost_snapshots( $order )[ $sku ] ?? null;
    }

    /**
     * Whether every XChange-sourced item on this order has now been
     * fulfilled AND the order contains no other (non-XChange) line items —
     * the only condition under which it's safe to auto-complete the order
     * purely from XChange fulfillment. An order mixing XChange items with
     * other products (e.g. a physical item needing separate shipping) is
     * deliberately never reported as "fully fulfilled" here regardless of
     * XChange fulfillment status — this plugin has no visibility into
     * whether those other items are actually done, so the caller (see
     * MMI_Xchange_Ajax::send_fulfillment_email()) must leave order status
     * alone in that case rather than guess.
     *
     * @return array{fully_fulfilled:bool, xchange_item_count:int, fulfilled_count:int, has_non_xchange_items:bool}
     */
    public static function fulfillment_completion_status( \WC_Order $order ): array {
        $xchange_items  = self::xchange_items( $order ); // one entry per XChange line item
        $xchange_skus   = array_unique( array_column( $xchange_items, 'sku' ) );
        $fulfilled_skus = array_keys( self::get_fulfilled_skus( $order ) );

        $all_xchange_fulfilled = ! empty( $xchange_skus ) && empty( array_diff( $xchange_skus, $fulfilled_skus ) );
        $has_non_xchange_items = count( $order->get_items() ) > count( $xchange_items );

        return [
            'fully_fulfilled'       => $all_xchange_fulfilled && ! $has_non_xchange_items,
            'xchange_item_count'    => count( $xchange_skus ),
            'fulfilled_count'       => count( array_intersect( $xchange_skus, $fulfilled_skus ) ),
            'has_non_xchange_items' => $has_non_xchange_items,
        ];
    }

    /**
     * Marks the WC order completed once fulfillment_completion_status() says
     * it's fully fulfilled, then fires `mmi_xchange_order_fulfilled` — the
     * one place every customer-email path (single, combined, automatic)
     * finishes. mmi-reverb-integration listens to close out the Reverb side
     * (MMI_Reverb_Fulfillment_Sync). Fired on every fully-fulfilled send,
     * so listeners must be idempotent.
     *
     * @param array $completion fulfillment_completion_status() / mark_fulfilled() result.
     * @return string|null The order's status afterward, or null if it doesn't exist.
     */
    public static function complete_if_fulfilled( int $order_id, array $completion ): ?string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }
        if ( empty( $completion['fully_fulfilled'] ) ) {
            return $order->get_status();
        }
        if ( ! $order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
            $order->update_status( 'completed', __( 'All XChange item(s) on this order have been fulfilled.', 'mmi-xchange-integration' ) );
        }

        /**
         * Every XChange item on this order has been emailed to the customer.
         *
         * @param int   $order_id
         * @param array $completion
         */
        do_action( 'mmi_xchange_order_fulfilled', $order_id, $completion );

        return $order->get_status();
    }
}
