<?php
/**
 * MMI_Xchange_Po_Linker
 *
 * Backfills the PO link on WooCommerce orders that were fulfilled manually
 * on the XChange portal before this plugin's Fulfillment Queue / manual-sync
 * UI ever existed — the historical gap _mmi_xchange_fulfilled_po was built
 * to close going forward but has nothing to say about the past.
 *
 * The insight this relies on: xchangeb2b.com's checkout has a free-text "PO
 * Number" reference field, and before this UI existed the admin's own habit
 * was to type the WooCommerce order number (or, for a Reverb-sourced sale,
 * the Reverb order number) into that field. That exact reference is already
 * sitting, unindexed, inside wp_mmi_xchange_orders' own `po` column
 * (MMI_Xchange_Order_Sync's synced PO history) for anything the routine
 * REST sync's rolling window has covered — find_matches() (the standalone
 * bulk scanner) is purely a local matching problem against that already-
 * synced data, no XChange API call involved.
 *
 * find_candidate_for_order() (the single-order lookup behind each
 * Fulfillment Queue row's own "Find Candidate PO" button) goes one step
 * further once the local table has nothing: a live, on-demand REST
 * /documents search anchored to that one order's own date, via
 * MMI_Xchange_Order_Sync::search_documents_live() — the only way to reach
 * a PO from before the local table's synced window at all. That's a real
 * external call, but always exactly one, always triggered by one explicit
 * admin click on one specific order — never looped across a batch, which
 * is why find_matches() itself stays local-only.
 *
 * Deliberately does NOT write _mmi_xchange_fulfilled_po itself. A match
 * here is a proposal, not a fact: a coincidental reference match would
 * otherwise hand one customer another customer's license key the moment
 * the Email Customer panel auto-fetches license/download/support for the
 * wrong PO. Every match this class returns still goes through the exact
 * same human-reviewed path a manual sync always has — see
 * PoLinkerPanel.useMatch()/PlaceOrderTab.revealEmailPanel() in
 * admin-xchange.js, and MMI_Xchange_Fulfillment_Queue::mark_fulfilled(),
 * which is only ever called once the admin actually reviews and sends that
 * email.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Po_Linker {

    /**
     * Single-order counterpart to find_matches() below — the client for
     * this is the "Find Candidate PO" button inside a Fulfillment Queue
     * row's own manual-sync fields (the mmi-x-po-manual-sync block), not
     * the standalone bulk scanner. Tries progressively more expensive
     * sources, stopping at the first hit:
     *
     *   1. Local exact match (wp_mmi_xchange_orders, indexed, free)
     *   2. Local substring/fuzzy match (same table, a bounded LIKE scan)
     *   3. A live XChange REST /documents search anchored to this order's
     *      own date — the only way to reach a PO from before the local
     *      table's synced window. Only ever reached when 1-2 found
     *      nothing, and only for this one order, never a batch.
     *
     * Whatever the live search returns gets upserted into
     * wp_mmi_xchange_orders regardless of whether it happens to match
     * THIS order — it's real synced data either way, and caching it means
     * the next lookup that needs it (this order retried, or a different
     * order whose PO fell in the same window) is a free local read.
     *
     * @return array|null Same shape as find_matches()'s per-order entries,
     *   or null if nothing was found anywhere.
     */
    public static function find_candidate_for_order( int $order_id ): ?array {
        $order = MMI_Xchange_Fulfillment_Queue::get_order_for_matching( $order_id );
        if ( $order === null ) {
            return null;
        }

        $refs = self::reference_candidates( $order );
        if ( empty( $refs ) ) {
            return null;
        }

        $exact_rows = MMI_Xchange_Order_Sync::get_orders_by_po_numbers( array_keys( $refs ) );
        $match      = self::best_match_for_order( $order, $exact_rows, [] );
        if ( $match !== null ) {
            return $match;
        }

        $fuzzy_rows = MMI_Xchange_Order_Sync::search_po_by_substrings( array_keys( $refs ) );
        $match      = self::best_match_for_order( $order, [], $fuzzy_rows );
        if ( $match !== null ) {
            return $match;
        }

        $live_rows = MMI_Xchange_Order_Sync::search_documents_live( $order['date'] );
        if ( empty( $live_rows ) ) {
            return null;
        }

        MMI_Xchange_Order_Sync::upsert_orders( $live_rows );

        $live_exact = [];
        foreach ( $live_rows as $row ) {
            if ( $row['po'] !== '' && ! isset( $live_exact[ $row['po'] ] ) ) {
                $live_exact[ $row['po'] ] = $row;
            }
        }
        $match = self::best_match_for_order( $order, $live_exact, [] );
        if ( $match !== null ) {
            return $match;
        }

        return self::best_match_for_order( $order, [], $live_rows );
    }

    /**
     * @param int $limit  Candidate WC orders to scan this call — bounds the
     *                     cost of one "Scan for Matches" click, per this
     *                     project's Server Load rules.
     * @param int $offset  For a repeated "Scan Next Batch" click.
     * @return array{matches:array<int,array>, scanned:int, next_offset:int, has_more:bool}
     */
    public static function find_matches( int $limit = 200, int $offset = 0 ): array {
        $orders = MMI_Xchange_Fulfillment_Queue::get_unlinked_orders( $limit, $offset );

        if ( empty( $orders ) ) {
            return [ 'matches' => [], 'scanned' => 0, 'next_offset' => $offset, 'has_more' => false ];
        }

        // One reference-string => [order index, ...] map for the whole
        // batch, so the exact-match lookup below is a single batched query
        // regardless of how many orders are in this page — never one query
        // per order (see MMI_Xchange_Order_Sync::get_orders_by_po_numbers()).
        $ref_to_orders = [];
        foreach ( $orders as $i => $order ) {
            foreach ( self::reference_candidates( $order ) as $ref => $basis ) {
                $ref_to_orders[ $ref ][] = $i;
            }
        }

        $exact_rows = MMI_Xchange_Order_Sync::get_orders_by_po_numbers( array_keys( $ref_to_orders ) );

        $matched_indexes = [];
        foreach ( $exact_rows as $po => $row ) {
            foreach ( $ref_to_orders[ $po ] as $i ) {
                $matched_indexes[ $i ] = true;
            }
        }

        // Fuzzy/substring pass — only for orders an exact reference didn't
        // already resolve, and only across this one bounded batch (never
        // re-queried per order).
        $unmatched_refs = [];
        foreach ( $orders as $i => $order ) {
            if ( isset( $matched_indexes[ $i ] ) ) {
                continue;
            }
            foreach ( self::reference_candidates( $order ) as $ref => $basis ) {
                $unmatched_refs[] = $ref;
            }
        }
        $fuzzy_rows = ! empty( $unmatched_refs )
            ? MMI_Xchange_Order_Sync::search_po_by_substrings( array_values( array_unique( $unmatched_refs ) ) )
            : [];

        $matches = [];
        foreach ( $orders as $order ) {
            $match = self::best_match_for_order( $order, $exact_rows, $fuzzy_rows );
            if ( $match !== null ) {
                $matches[] = $match;
            }
        }

        return [
            'matches'     => $matches,
            'scanned'     => count( $orders ),
            'next_offset' => $offset + count( $orders ),
            'has_more'    => count( $orders ) === $limit,
        ];
    }

    /**
     * Every reference string this order could plausibly have been filed
     * under on the XChange portal, mapped to which candidate it is (so a
     * hit can be labeled "this order's own #" vs. "its Reverb #" in the
     * UI, not just "matched something"). Includes a digits-only variant of
     * each in case the portal's free-text field was typed without a
     * leading "#" or similar decoration.
     *
     * @return array<string,string> reference string => basis label
     */
    private static function reference_candidates( array $order ): array {
        $refs = [];

        $wc_number = (string) $order['order_number'];
        if ( $wc_number !== '' ) {
            $refs[ $wc_number ] = 'wc_order_number';
            $digits = preg_replace( '/\D/', '', $wc_number );
            if ( $digits !== '' && ! isset( $refs[ $digits ] ) ) {
                $refs[ $digits ] = 'wc_order_number';
            }
        }

        $reverb_number = (string) ( $order['reverb_order_number'] ?? '' );
        if ( $reverb_number !== '' ) {
            $refs[ $reverb_number ] = 'reverb_order_number';
            $digits = preg_replace( '/\D/', '', $reverb_number );
            if ( $digits !== '' && ! isset( $refs[ $digits ] ) ) {
                $refs[ $digits ] = 'reverb_order_number';
            }
        }

        return $refs;
    }

    /**
     * @param array<string,array> $exact_rows po => wp_mmi_xchange_orders row
     * @param array<int,array>    $fuzzy_rows flat list of wp_mmi_xchange_orders rows
     */
    private static function best_match_for_order( array $order, array $exact_rows, array $fuzzy_rows ): ?array {
        $refs = self::reference_candidates( $order );

        foreach ( $refs as $ref => $basis ) {
            if ( isset( $exact_rows[ $ref ] ) ) {
                return self::build_match( $order, $exact_rows[ $ref ], $basis, 'exact' );
            }
        }

        foreach ( $refs as $ref => $basis ) {
            foreach ( $fuzzy_rows as $row ) {
                if ( $row['po'] !== '' && stripos( $row['po'], $ref ) !== false ) {
                    return self::build_match( $order, $row, $basis, 'fuzzy' );
                }
            }
        }

        return null;
    }

    private static function build_match( array $order, array $po_row, string $basis, string $match_type ): array {
        $order_skus = array_unique( array_column( $order['items'], 'sku' ) );
        $sku_match  = in_array( $po_row['sku'], $order_skus, true );

        // Only a reference match XChange's own free-text field couldn't
        // plausibly be about anyone else's order (an exact hit, not a
        // partial one) AND whose SKU actually matches something on this
        // order is safe to one-click — anything else still surfaces (the
        // admin may recognize it instantly on sight) but is flagged for a
        // real look before use, per this class's own docblock on the
        // license-mismatch risk a false positive would create.
        $confidence = ( $match_type === 'exact' && $sku_match ) ? 'high' : 'review';

        return [
            'order_id'            => $order['order_id'],
            'order_number'        => $order['order_number'],
            'edit_url'            => $order['edit_url'],
            'date'                => $order['date'],
            'status'              => $order['status'],
            'customer_name'       => $order['customer_name'],
            'customer_email'      => $order['customer_email'],
            'items'               => $order['items'],
            // The order's own item SKU when the matched PO's SKU doesn't
            // agree with anything on this order — the fulfillment being
            // recorded is still for this order's real product, not
            // whatever the (flagged-as-unreliable) matched PO happens to
            // list.
            'item_sku'            => $sku_match ? $po_row['sku'] : ( $order['items'][0]['sku'] ?? '' ),
            'matched_po'          => $po_row['po'],
            'matched_auth'        => $po_row['auth'],
            'matched_license_key' => $po_row['license_key'],
            'matched_vendor_name' => $po_row['vendor_name'],
            'matched_product'     => $po_row['product'],
            'matched_price'       => $po_row['price'],
            'basis'               => $basis,
            'match_type'          => $match_type,
            'sku_match'           => $sku_match,
            'confidence'          => $confidence,
        ];
    }
}
