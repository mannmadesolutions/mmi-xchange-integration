<?php
/**
 * MMI_Xchange_Data_Health_Rule_Pack — XChange fulfillability for mmi-data-health.
 *
 * Every rule comes from MMI_Supplier_Rule_Pack (shared library), the same
 * rules the SkuPort and Plugivery packs score. The SKU is read from
 * _mmi_supplier_sku_xchange (primary) then sku_xchange (legacy), the same
 * precedence class-xchange-fulfillment-queue.php uses.
 *
 * "XChange supplier SKU" used to be scored on every product, so every
 * SkuPort, Plugivery and physical product failed it (2,178 of 2,179
 * failures on 2026-10-06). The shared rule scores only products the store
 * records as XChange's (`_supplier_name` = xchange).
 *
 * class-xchange-vendors.php::get_local_image_readiness()'s vendor-aggregate
 * image-completeness data is deliberately NOT represented here — it returns
 * vendor-keyed totals with no post IDs, so it doesn't fit this per-post rule
 * shape. It's surfaced instead as a separate stat-card link on
 * mmi-data-health's own Overview tab, kept visibly distinct from the
 * per-post table.
 *
 * Loaded only from inside the mmi_data_health_rule_packs filter callback
 * (mmi-xchange-integration.php), never at plugin boot.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Data_Health_Rule_Pack extends MMI_Supplier_Rule_Pack {

    const META_KEYS = [ '_mmi_supplier_sku_xchange', 'sku_xchange' ];

    protected function supplier_id(): string {
        return 'xchange';
    }

    protected function supplier_label(): string {
        return __( 'XChange', 'mmi-xchange-integration' );
    }

    protected function sku_meta_keys(): array {
        return self::META_KEYS;
    }
}
