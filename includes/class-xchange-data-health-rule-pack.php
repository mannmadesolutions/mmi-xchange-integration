<?php
/**
 * MMI_Xchange_Data_Health_Rule_Pack — supplier-SKU presence rule for mmi-data-health.
 *
 * Checks both _mmi_supplier_sku_xchange (primary) and sku_xchange (legacy)
 * meta keys, the same precedence class-xchange-fulfillment-queue.php already
 * uses for SKU resolution — reuses that convention rather than inventing a
 * third lookup order.
 *
 * class-xchange-vendors.php::get_local_image_readiness()'s vendor-aggregate
 * image-completeness data is deliberately NOT represented here — it returns
 * vendor-keyed totals with no post IDs, so it doesn't fit this per-post rule
 * shape. It's surfaced instead as a separate stat-card link on
 * mmi-data-health's own Overview tab (see mmi-data-health-overview-widget
 * registration below), kept visibly distinct from the per-post table.
 *
 * Loaded only from inside the mmi_data_health_rule_packs filter callback
 * (mmi-xchange-integration.php), never at plugin boot.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Data_Health_Rule_Pack implements MMI_Data_Health_Rule_Pack {

    const META_KEYS = [ '_mmi_supplier_sku_xchange', 'sku_xchange' ];

    public function get_id(): string {
        return 'mmi-xchange-integration';
    }

    public function get_label(): string {
        return __( 'XChange Supplier Data (mmi-xchange-integration)', 'mmi-xchange-integration' );
    }

    public function get_rules(): array {
        return [
            new MMI_Data_Health_Rule( [
                'id'         => 'mmi-xchange-integration.supplier_sku_present',
                'label'      => __( 'Supplier SKU', 'mmi-xchange-integration' ),
                'severity'   => 'recommended',
                'weight'     => 5,
                'post_types' => [ 'product' ],
                'applicable' => function ( int $post_id ): bool {
                    return get_post_type( $post_id ) === 'product';
                },
                'check'      => function ( int $post_id ): array {
                    foreach ( self::META_KEYS as $meta_key ) {
                        $value = get_post_meta( $post_id, $meta_key, true );
                        if ( trim( (string) $value ) !== '' ) {
                            return [ 'pass' => true ];
                        }
                    }
                    return [
                        'pass'    => false,
                        'message' => __( 'No XChange supplier SKU on file', 'mmi-xchange-integration' ),
                    ];
                },
            ] ),
        ];
    }
}
