<?php
/**
 * MMI_Xchange_Price_History_Watcher
 *
 * Detects WooCommerce regular/sale price changes on XChange-sourced
 * products and logs them to MMI_Xchange_Price_History. Deliberately mirrors
 * mmi-reverb-integration's class-product-change-detector.php narrow-hook
 * pattern rather than any broad woocommerce_update_product catch-all — that
 * file's own docblock documents an 87%-of-a-queue-saturation incident
 * caused by exactly that kind of catch-all. `updated_post_meta` on
 * `_regular_price`/`_sale_price` is free and precise: WordPress's own
 * update_metadata() only fires it on a genuine value change.
 *
 * Runs fully independently of mmi-reverb-integration's own watcher — no
 * shared state, no dependency, both plugins hooking the same core WP
 * actions on their own.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Price_History_Watcher {

    public static function init(): void {
        add_action( 'updated_post_meta', [ __CLASS__, 'on_meta_update' ], 10, 4 );
        add_action( 'wc_scheduled_sales', [ __CLASS__, 'on_scheduled_sales' ] );
    }

    /**
     * @param int    $meta_id
     * @param int    $post_id
     * @param string $meta_key
     * @param mixed  $meta_value
     */
    public static function on_meta_update( $meta_id, $post_id, $meta_key, $meta_value ): void {
        if ( ! in_array( $meta_key, [ '_regular_price', '_sale_price' ], true ) ) {
            return;
        }
        if ( get_post_type( $post_id ) !== 'product' ) {
            return;
        }

        $sku = class_exists( 'MMI_Xchange_API' ) ? MMI_Xchange_API::get_product_xchange_sku( (int) $post_id ) : '';
        if ( $sku === '' ) {
            return; // not an XChange-sourced product — out of scope.
        }

        $price_type = ( $meta_key === '_regular_price' ) ? MMI_Xchange_Price_History::TYPE_WC_REGULAR : MMI_Xchange_Price_History::TYPE_WC_SALE;
        MMI_Xchange_Price_History::record_price( $sku, $price_type, (float) $meta_value );
    }

    /**
     * WooCommerce's own scheduled-sales cron updates _price directly,
     * bypassing any woocommerce_product_set_sale_price hook (WC core never
     * fires it) — this is the only reliable moment to catch a timed sale
     * actually starting or ending. Same query shape as
     * MMI_Reverb_Product_Change_Detector::on_scheduled_sales(), scoped to
     * XChange-tagged products instead of Reverb-synced ones.
     */
    public static function on_scheduled_sales(): void {
        global $wpdb;
        $now = time();

        $product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} sf ON sf.post_id = p.ID
                 AND sf.meta_key = '_sale_price_dates_from' AND sf.meta_value != '' AND sf.meta_value <= %d
             INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID
                 AND st.meta_key = '_sale_price_dates_to' AND st.meta_value != '' AND st.meta_value >= %d
             INNER JOIN {$wpdb->postmeta} xs ON xs.post_id = p.ID
                 AND xs.meta_key IN ('_mmi_supplier_sku_xchange', 'sku_xchange') AND xs.meta_value != ''
             WHERE p.post_type = 'product' AND p.post_status = 'publish'",
            $now,
            $now
        ) );

        if ( empty( $product_ids ) || ! class_exists( 'MMI_Xchange_API' ) ) {
            return;
        }

        foreach ( $product_ids as $product_id ) {
            $sku     = MMI_Xchange_API::get_product_xchange_sku( (int) $product_id );
            $product = wc_get_product( $product_id );
            if ( $sku === '' || ! $product ) {
                continue;
            }
            $price = $product->is_on_sale() ? $product->get_sale_price() : $product->get_regular_price();
            MMI_Xchange_Price_History::record_price( $sku, MMI_Xchange_Price_History::TYPE_WC_SALE, (float) $price );
        }
    }
}
