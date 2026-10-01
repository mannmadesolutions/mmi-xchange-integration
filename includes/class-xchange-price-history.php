<?php
/**
 * MMI_Xchange_Price_History
 *
 * Time-series log of what XChange charges MMI (dealer/promo cost) and what
 * WooCommerce charges customers (regular/sale price), per XChange-sourced
 * SKU. Feeds the Price History chart in the Place Order tab and gives the
 * Fulfillment Queue margin calc's cost-at-purchase snapshot something to
 * eventually be built from for SKUs bought before this feature existed.
 *
 * Deliberately scoped to products carrying an XChange SKU
 * (_mmi_supplier_sku_xchange/sku_xchange meta — the same precedence
 * MMI_Xchange_API::get_product_xchange_sku() uses), not the whole
 * WooCommerce catalog. Every write is change-only: a row is inserted only
 * when the value actually differs from the last recorded row for that
 * sku+price_type, so an unchanged catalog/promo refresh or a no-op product
 * save writes nothing.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Price_History {

    const DB_VERSION_KEY = 'mmi_xchange_price_history_db_version';
    const DB_VERSION     = '1.0.0';

    const TYPE_DEALER     = 'dealer';
    const TYPE_PROMO      = 'promo';
    const TYPE_WC_REGULAR = 'wc_regular';
    const TYPE_WC_SALE    = 'wc_sale';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mmi_xchange_price_history';
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sku            varchar(64) NOT NULL DEFAULT '',
            price_type     varchar(20) NOT NULL DEFAULT '',
            price          decimal(10,2) NOT NULL DEFAULT 0,
            promotion_name varchar(255) NOT NULL DEFAULT '',
            recorded_at    datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY          sku_type_id (sku, price_type, id),
            KEY          recorded_at (recorded_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        MMI_Settings::set( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( MMI_Settings::get( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Every XChange SKU this store actually carries a product for — the
     * scope boundary for all price-history writes below. Same meta-key
     * precedence as MMI_Xchange_API::get_product_xchange_sku().
     *
     * @return string[]
     */
    public static function get_xchange_skus_in_store(): array {
        global $wpdb;
        $skus = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_mmi_supplier_sku_xchange', 'sku_xchange') AND meta_value != ''"
        );
        return array_values( array_filter( array_map( 'strval', (array) $skus ) ) );
    }

    /**
     * Single-row write path — used by the WC price-meta watcher
     * (MMI_Xchange_Price_History_Watcher), where only one sku/type changed.
     */
    public static function record_price( string $sku, string $price_type, float $price, string $promotion_name = '' ): void {
        if ( $sku === '' || $price_type === '' ) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $last  = $wpdb->get_row( $wpdb->prepare(
            "SELECT price, promotion_name FROM {$table} WHERE sku = %s AND price_type = %s ORDER BY id DESC LIMIT 1",
            $sku,
            $price_type
        ), ARRAY_A );

        if ( $last !== null && (float) $last['price'] === $price && (string) $last['promotion_name'] === $promotion_name ) {
            return; // unchanged since the last recorded row — no-op.
        }

        $wpdb->insert( $table, [
            'sku'            => $sku,
            'price_type'     => $price_type,
            'price'          => $price,
            'promotion_name' => $promotion_name,
            'recorded_at'    => current_time( 'mysql', true ),
        ] );
    }

    /**
     * Batch write path — used after a full catalog/promo refresh. One
     * lookup query for every sku+type's latest row, one multi-row INSERT
     * for only the rows that actually changed; never a query per row.
     *
     * @param array<int,array{sku:string,price_type:string,price:float,promotion_name?:string}> $rows
     * @return int Rows actually inserted.
     */
    public static function record_prices_batch( array $rows ): int {
        $rows = array_values( array_filter( $rows, static fn( $r ) => ! empty( $r['sku'] ) && ! empty( $r['price_type'] ) ) );
        if ( empty( $rows ) ) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $skus  = array_values( array_unique( array_column( $rows, 'sku' ) ) );
        $in    = implode( ',', array_fill( 0, count( $skus ), '%s' ) );

        $latest_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.sku, h.price_type, h.price, h.promotion_name
             FROM {$table} h
             INNER JOIN (
                 SELECT sku, price_type, MAX(id) AS max_id
                 FROM {$table}
                 WHERE sku IN ({$in})
                 GROUP BY sku, price_type
             ) latest ON latest.max_id = h.id",
            $skus
        ), ARRAY_A );

        $last_map = [];
        foreach ( (array) $latest_rows as $row ) {
            $last_map[ $row['sku'] . '|' . $row['price_type'] ] = $row;
        }

        $now    = current_time( 'mysql', true );
        $values = [];
        foreach ( $rows as $row ) {
            $sku            = (string) $row['sku'];
            $price_type     = (string) $row['price_type'];
            $price          = (float) $row['price'];
            $promotion_name = (string) ( $row['promotion_name'] ?? '' );

            $prev = $last_map[ $sku . '|' . $price_type ] ?? null;
            if ( $prev !== null && (float) $prev['price'] === $price && (string) $prev['promotion_name'] === $promotion_name ) {
                continue; // unchanged since the last recorded row.
            }

            $values[] = $wpdb->prepare( '(%s, %s, %f, %s, %s)', $sku, $price_type, $price, $promotion_name, $now );
        }

        if ( empty( $values ) ) {
            return 0;
        }

        $wpdb->query( "INSERT INTO {$table} (sku, price_type, price, promotion_name, recorded_at) VALUES " . implode( ', ', $values ) );

        return count( $values );
    }

    /**
     * @return array<int,array{price_type:string,price:string,promotion_name:string,recorded_at:string}>
     */
    public static function get_history_for_sku( string $sku, int $days = 365 ): array {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT price_type, price, promotion_name, recorded_at FROM " . self::table() . "
             WHERE sku = %s AND recorded_at >= %s ORDER BY recorded_at ASC",
            $sku,
            $since
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Hourly cron target (mmi_xchange_price_history_refresh) — guarantees
     * the dealer/promo transients (and therefore this table) actually
     * refresh even when no admin is looking at the Place Order tab, since
     * nothing else repopulates them. Cache-first: only triggers a genuine
     * live fetch — and thus a recording pass in get_catalog_map()/
     * get_promo_map() — once each TTL (6h catalog, 15min promo) has
     * actually expired. At most 2 throttled XChange calls/hour.
     */
    public static function refresh(): void {
        if ( class_exists( 'MMI_Xchange_Order_Sync' ) ) {
            MMI_Xchange_Order_Sync::refresh_price_history_caches();
        }
    }
}
