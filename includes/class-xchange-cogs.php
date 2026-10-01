<?php
/**
 * MMI_Xchange_COGS
 *
 * Xchange dealer-price COGS matching + backfill, moved out of
 * mmi-reverb-integration's `MMI_Reverb_COGS_Engine` (that class keeps only
 * its ShipStation half). Reads/writes Reverb-owned tables directly
 * (`wp_mmi_reverb_orders`, `wp_mmi_order_costs`) — the same cross-plugin
 * table access pattern the code already used before the split, just now
 * called through MMI_Xchange_API's facade instead of living inside Reverb.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_COGS {

    const SOURCE_XCHANGE      = 'xchange_auto';
    const BACKFILL_BATCH_SIZE = 50;

    public static function init(): void {
        // No hooks of its own — invoked via MMI_Xchange_API's facade from
        // mmi-reverb-integration's order importer and admin COGS UI.
    }

    /**
     * @return array{dealer_price:float, product:string, sku:string}|null
     */
    public static function lookup( string $raw_sku ): ?array {
        if ( $raw_sku === '' ) {
            return null;
        }

        global $wpdb;

        $cog_key     = function_exists( 'mmi_get_cog_meta_key' ) ? mmi_get_cog_meta_key() : 'cog';
        $xchange_sku = ( substr( $raw_sku, 0, 4 ) === 'OLD-' ) ? substr( $raw_sku, 4 ) : $raw_sku;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.post_title, pm_cog.meta_value AS dealer_price,
                        pm_sku.meta_value AS sku
                 FROM {$wpdb->postmeta} pm_sku
                 JOIN {$wpdb->posts} p ON p.ID = pm_sku.post_id AND p.post_status = 'publish'
                 JOIN {$wpdb->postmeta} pm_cog
                   ON pm_cog.post_id = pm_sku.post_id AND pm_cog.meta_key = %s
                 WHERE pm_sku.meta_key = '_mmi_supplier_sku_xchange'
                   AND pm_sku.meta_value = %s
                 LIMIT 1",
                $cog_key,
                $xchange_sku
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.post_title, pm_cog.meta_value AS dealer_price,
                            pm_sku.meta_value AS sku
                     FROM {$wpdb->postmeta} pm_sku
                     JOIN {$wpdb->posts} p ON p.ID = pm_sku.post_id AND p.post_status = 'publish'
                     JOIN {$wpdb->postmeta} pm_cog
                       ON pm_cog.post_id = pm_sku.post_id AND pm_cog.meta_key = %s
                     WHERE pm_sku.meta_key = '_sku'
                       AND pm_sku.meta_value = %s
                     LIMIT 1",
                    $cog_key,
                    $raw_sku
                ),
                ARRAY_A
            );
        }

        if ( ! $row || (float) $row['dealer_price'] <= 0 ) {
            return null;
        }

        return [
            'dealer_price' => (float) $row['dealer_price'],
            'product'      => (string) $row['post_title'],
            'sku'          => (string) $row['sku'],
        ];
    }

    /**
     * Batch form of lookup() — resolves many SKUs in 2 queries total instead of
     * up to 2 queries per SKU. Built for admin table renders (e.g. the Reverb
     * Products tab) that need a COGS figure per row on every page/filter/search
     * request; looping lookup() per row there was a real N+1 query pattern.
     *
     * @param string[] $raw_skus
     * @return array<string, array{dealer_price:float, product:string, sku:string}>
     *         Keyed by the original raw SKU passed in; SKUs with no match are omitted.
     */
    public static function lookup_batch( array $raw_skus ): array {
        $raw_skus = array_values( array_unique( array_filter( $raw_skus, static fn( $s ) => $s !== '' ) ) );
        if ( empty( $raw_skus ) ) {
            return [];
        }

        global $wpdb;
        $cog_key = function_exists( 'mmi_get_cog_meta_key' ) ? mmi_get_cog_meta_key() : 'cog';

        // Map each raw SKU to the xchange-normalized form (strips a legacy 'OLD-'
        // prefix), matching lookup()'s own normalization exactly.
        $xchange_sku_by_raw = [];
        foreach ( $raw_skus as $raw ) {
            $xchange_sku_by_raw[ $raw ] = ( substr( $raw, 0, 4 ) === 'OLD-' ) ? substr( $raw, 4 ) : $raw;
        }
        $xchange_skus = array_values( array_unique( $xchange_sku_by_raw ) );

        $found_by_xchange_sku = self::fetch_cogs_rows_by_meta( '_mmi_supplier_sku_xchange', $xchange_skus, $cog_key );

        // Second pass, only for SKUs the first query didn't resolve — mirrors
        // lookup()'s own fallback to the plain _sku meta key, keyed on the raw
        // (unstripped) SKU value this time.
        $unresolved_raw = array_values( array_filter(
            $raw_skus,
            static fn( $raw ) => ! isset( $found_by_xchange_sku[ $xchange_sku_by_raw[ $raw ] ] )
        ) );
        $found_by_raw_sku = empty( $unresolved_raw )
            ? []
            : self::fetch_cogs_rows_by_meta( '_sku', $unresolved_raw, $cog_key );

        $results = [];
        foreach ( $raw_skus as $raw ) {
            $row = $found_by_xchange_sku[ $xchange_sku_by_raw[ $raw ] ] ?? $found_by_raw_sku[ $raw ] ?? null;
            if ( $row === null || (float) $row['dealer_price'] <= 0 ) {
                continue;
            }
            $results[ $raw ] = [
                'dealer_price' => (float) $row['dealer_price'],
                'product'      => (string) $row['post_title'],
                'sku'          => (string) $row['sku'],
            ];
        }

        return $results;
    }

    /**
     * @param string   $meta_key   The SKU meta key to match against ('_mmi_supplier_sku_xchange' or '_sku').
     * @param string[] $sku_values Values to match, IN-clause style.
     * @param string   $cog_key    Resolved COG meta key.
     * @return array<string, array{post_title:string, dealer_price:mixed, sku:string}> Keyed by matched sku value.
     */
    private static function fetch_cogs_rows_by_meta( string $meta_key, array $sku_values, string $cog_key ): array {
        if ( empty( $sku_values ) ) {
            return [];
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $sku_values ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.post_title, pm_cog.meta_value AS dealer_price, pm_sku.meta_value AS sku
                 FROM {$wpdb->postmeta} pm_sku
                 JOIN {$wpdb->posts} p ON p.ID = pm_sku.post_id AND p.post_status = 'publish'
                 JOIN {$wpdb->postmeta} pm_cog
                   ON pm_cog.post_id = pm_sku.post_id AND pm_cog.meta_key = %s
                 WHERE pm_sku.meta_key = %s
                   AND pm_sku.meta_value IN ({$placeholders})",
                array_merge( [ $cog_key, $meta_key ], $sku_values )
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $row ) {
            // First match per SKU value wins, matching lookup()'s own LIMIT 1 behavior.
            if ( ! isset( $map[ $row['sku'] ] ) ) {
                $map[ $row['sku'] ] = $row;
            }
        }

        return $map;
    }

    /**
     * @return int|false Inserted row ID, or false on failure / already exists.
     */
    public static function insert_xchange_cogs( string $reverb_order_id, float $dealer_price, string $product_name, string $xchange_sku ) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mmi_order_costs WHERE reverb_order_id = %s AND source = %s LIMIT 1",
                $reverb_order_id,
                self::SOURCE_XCHANGE
            )
        );
        if ( $exists ) {
            return false;
        }

        $description = $product_name !== ''
            ? 'Dealer cost: ' . $product_name . ' [' . $xchange_sku . ']'
            : 'Auto-COGS from xchange [' . $xchange_sku . ']';

        $result = $wpdb->insert(
            $wpdb->prefix . 'mmi_order_costs',
            [
                'reverb_order_id' => $reverb_order_id,
                'cost_type'       => 'cogs',
                'amount'          => $dealer_price,
                'description'     => $description,
                'source'          => self::SOURCE_XCHANGE,
            ],
            [ '%s', '%s', '%f', '%s', '%s' ]
        );

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Called (via MMI_Xchange_API::auto_cogs_on_import()) right after Reverb
     * imports a new order.
     */
    public static function auto_cogs_on_import( string $reverb_order_id, array $order_data ): void {
        $sku = '';
        if ( ! empty( $order_data['_embedded']['order_items'][0]['sku'] ) ) {
            $sku = sanitize_text_field( $order_data['_embedded']['order_items'][0]['sku'] );
        } elseif ( ! empty( $order_data['sku'] ) ) {
            $sku = sanitize_text_field( $order_data['sku'] );
        }

        if ( $sku === '' ) {
            return;
        }

        $match = self::lookup( $sku );
        if ( $match === null || $match['dealer_price'] <= 0 ) {
            return;
        }

        self::insert_xchange_cogs( $reverb_order_id, $match['dealer_price'], $match['product'], $match['sku'] );
    }

    /**
     * @return array<string,int>
     */
    public static function backfill_status(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mmi_reverb_orders" );

        $with_auto = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT reverb_order_id) FROM {$wpdb->prefix}mmi_order_costs WHERE source = %s",
                self::SOURCE_XCHANGE
            )
        );

        [ $matchable, $already_done ] = self::count_matchable_orders();

        return [
            'total_orders'   => $total,
            'with_auto_cogs' => $with_auto,
            'matchable'      => $matchable,
            'backfill_done'  => $already_done,
            'backfill_pct'   => $matchable > 0 ? (int) round( $already_done / $matchable * 100 ) : 100,
        ];
    }

    /**
     * @return array{int, int} [matchable_count, already_done_count]
     */
    private static function count_matchable_orders(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT o.reverb_order_id,
                    JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.sku')) AS sku,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}mmi_order_costs c
                     WHERE c.reverb_order_id = o.reverb_order_id AND c.source = 'xchange_auto') AS auto_cnt
             FROM {$wpdb->prefix}mmi_reverb_orders o
             WHERE JSON_VALID(o.raw_data)",
            ARRAY_A
        );

        $matchable    = 0;
        $already_done = 0;

        foreach ( $rows as $r ) {
            $match = self::lookup( (string) $r['sku'] );
            if ( $match === null || $match['dealer_price'] <= 0 ) {
                continue;
            }
            $matchable++;
            if ( (int) $r['auto_cnt'] > 0 ) {
                $already_done++;
            }
        }

        return [ $matchable, $already_done ];
    }

    /**
     * @return array{processed:int, inserted:int, skipped:int, offset:int, done:bool}
     */
    public static function run_backfill_batch( int $offset = 0, int $limit = self::BACKFILL_BATCH_SIZE ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.reverb_order_id,
                        JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.sku')) AS sku
                 FROM {$wpdb->prefix}mmi_reverb_orders o
                 WHERE JSON_VALID(o.raw_data)
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}mmi_order_costs c
                       WHERE c.reverb_order_id = o.reverb_order_id AND c.source = %s
                   )
                 ORDER BY o.reverb_order_id ASC
                 LIMIT %d OFFSET %d",
                self::SOURCE_XCHANGE,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $processed = 0;
        $inserted  = 0;
        $skipped   = 0;

        foreach ( $rows as $r ) {
            $processed++;
            $match = self::lookup( (string) $r['sku'] );

            if ( $match === null || $match['dealer_price'] <= 0 ) {
                $skipped++;
                continue;
            }

            $result = self::insert_xchange_cogs( $r['reverb_order_id'], $match['dealer_price'], $match['product'], $match['sku'] );
            $result !== false ? $inserted++ : $skipped++;
        }

        $remaining = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mmi_reverb_orders o
                 WHERE JSON_VALID(o.raw_data)
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}mmi_order_costs c
                       WHERE c.reverb_order_id = o.reverb_order_id AND c.source = %s
                   )",
                self::SOURCE_XCHANGE
            )
        );

        return [
            'processed' => $processed,
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'offset'    => $offset + $processed,
            'done'      => ( count( $rows ) === 0 || (int) $remaining === 0 ),
        ];
    }
}
