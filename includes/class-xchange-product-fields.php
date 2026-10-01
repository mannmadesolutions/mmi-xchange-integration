<?php
/**
 * MMI_Xchange_Product_Fields
 *
 * Product/variation custom fields (Internal SKU + support-link override),
 * ported from `xchangemarket`. Fixes two real gaps in the original:
 * `$_POST[...]` was read without an `isset()` check (PHP warning on an
 * empty field), and the value was sanitized with `esc_attr()` (an output
 * escaper) instead of `sanitize_text_field()` before being written to the
 * database.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Product_Fields {

    public static function init(): void {
        add_action( 'woocommerce_product_options_sku', [ __CLASS__, 'render_product_fields' ] );
        add_action( 'woocommerce_variation_options', [ __CLASS__, 'render_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_fields' ] );
        add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation_fields' ], 10, 2 );
        add_filter( 'woocommerce_available_variation', [ __CLASS__, 'expose_variation_fields' ] );
    }

    public static function render_product_fields(): void {
        echo '<div class="options_group">';
        woocommerce_wp_text_input( [
            'id'          => XCHANGE_INTERNAL_SKU_NAME,
            'label'       => __( 'XChange Internal SKU', 'mmi-xchange-integration' ),
            'placeholder' => __( 'Enter Internal SKU to activate XChange for this product', 'mmi-xchange-integration' ),
            'desc_tip'    => true,
            'description' => __( 'Internal SKU provided by Xchange Market for this product.', 'mmi-xchange-integration' ),
        ] );
        echo '</div>';

        echo '<div class="options_group">';
        woocommerce_wp_text_input( [
            'id'          => XCHANGE_PRODUCT_SUPPORT_LINK,
            'label'       => __( 'Product Support Link Override', 'mmi-xchange-integration' ),
            'placeholder' => __( 'Enter to override the store global support link', 'mmi-xchange-integration' ),
            'desc_tip'    => true,
            'description' => __( 'Blank defaults to the store-wide support link; enter "_" to hide the support link for this product.', 'mmi-xchange-integration' ),
        ] );
        echo '</div>';
    }

    public static function render_variation_fields( $loop, $variation_data, $variation ): void {
        echo '<div class="woocommerce-variation-' . esc_attr( XCHANGE_INTERNAL_SKU_NAME_VAR ) . '">';
        woocommerce_wp_text_input( [
            'id'          => XCHANGE_INTERNAL_SKU_NAME_VAR . "{$loop}",
            'name'        => XCHANGE_INTERNAL_SKU_NAME_VAR . "[{$loop}]",
            'label'       => __( 'XChange Internal SKU', 'mmi-xchange-integration' ),
            'placeholder' => __( 'Enter to override the parent product XChange SKU', 'mmi-xchange-integration' ),
            'desc_tip'    => true,
            'description' => __( 'If this variation maps to a different Xchange product than the parent, enter its SKU here.', 'mmi-xchange-integration' ),
            'value'       => get_post_meta( $variation->ID, XCHANGE_INTERNAL_SKU_NAME_VAR, true ),
        ] );
        echo '</div>';

        echo '<div class="woocommerce-variation-' . esc_attr( XCHANGE_PRODUCT_SUPPORT_LINK_VAR ) . '">';
        woocommerce_wp_text_input( [
            'id'          => XCHANGE_PRODUCT_SUPPORT_LINK_VAR . "{$loop}",
            'name'        => XCHANGE_PRODUCT_SUPPORT_LINK_VAR . "[{$loop}]",
            'label'       => __( 'Product Support Link Override', 'mmi-xchange-integration' ),
            'placeholder' => __( 'Enter to override the parent product support link', 'mmi-xchange-integration' ),
            'desc_tip'    => true,
            'description' => __( 'Blank defaults to the parent product support link; enter "_" to hide the support link for this variation.', 'mmi-xchange-integration' ),
            'value'       => get_post_meta( $variation->ID, XCHANGE_PRODUCT_SUPPORT_LINK_VAR, true ),
        ] );
        echo '</div>';
    }

    public static function save_product_fields( $post_id ): void {
        if ( isset( $_POST[ XCHANGE_INTERNAL_SKU_NAME ] ) ) {
            update_post_meta( $post_id, XCHANGE_INTERNAL_SKU_NAME, sanitize_text_field( wp_unslash( $_POST[ XCHANGE_INTERNAL_SKU_NAME ] ) ) );
        }

        if ( isset( $_POST[ XCHANGE_PRODUCT_SUPPORT_LINK ] ) ) {
            update_post_meta( $post_id, XCHANGE_PRODUCT_SUPPORT_LINK, sanitize_text_field( wp_unslash( $_POST[ XCHANGE_PRODUCT_SUPPORT_LINK ] ) ) );
        }
    }

    public static function save_variation_fields( $variation_id, $i ): void {
        $sku = isset( $_POST[ XCHANGE_INTERNAL_SKU_NAME_VAR ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ XCHANGE_INTERNAL_SKU_NAME_VAR ][ $i ] ) ) : '';
        update_post_meta( $variation_id, XCHANGE_INTERNAL_SKU_NAME_VAR, $sku );

        $support = isset( $_POST[ XCHANGE_PRODUCT_SUPPORT_LINK_VAR ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ XCHANGE_PRODUCT_SUPPORT_LINK_VAR ][ $i ] ) ) : '';
        update_post_meta( $variation_id, XCHANGE_PRODUCT_SUPPORT_LINK_VAR, $support );
    }

    public static function expose_variation_fields( $variations ) {
        $variations[ XCHANGE_INTERNAL_SKU_NAME_VAR ]     = get_post_meta( $variations['variation_id'], XCHANGE_INTERNAL_SKU_NAME_VAR, true );
        $variations[ XCHANGE_PRODUCT_SUPPORT_LINK_VAR ]  = get_post_meta( $variations['variation_id'], XCHANGE_PRODUCT_SUPPORT_LINK_VAR, true );
        return $variations;
    }
}
