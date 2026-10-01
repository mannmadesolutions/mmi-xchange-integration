<?php
/**
 * Xchange Field Mapping Presets
 *
 * Ships three ready-made field-mapping presets into mmi-data-pipeline's
 * existing "Field Mapping Presets" mechanism (#field-mapping-preset-select /
 * mmi_save/apply/delete_field_mapping_preset) via the
 * 'mmi_pipeline_builtin_field_mapping_presets' filter — see
 * mmi_get_all_field_mapping_presets() in ImportSettingsController.php.
 *
 * SUPERSEDES the earlier design (see mmi-admin/docs/decisions/0005-xchange-preset-import-profile.md
 * and AGENTS.md's 1.83.0-1.86.0 entries), which shipped these as three full,
 * standalone Import Profiles. The user reconsidered after seeing that design
 * live: a preset is a field-mapping snapshot applied to WHATEVER profile the
 * user is building, not three permanent rows in the Import Profiles grid a
 * user didn't create and may not want. The wizard's existing preset
 * dropdown already does exactly this job for user-saved presets — this
 * class just makes Xchange's own field knowledge available through the same
 * mechanism, visible only when it's actually usable (Data Type = WooCommerce
 * Products AND the Xchange source checked in Step 1 — enforced client-side
 * in import-settings.js's refreshPresetDropdownForContext(), driven by each
 * preset's own 'data_type'/'sources' metadata below).
 *
 * The three presets' field composition (which DEFAULTS groups are enabled,
 * per-field overrides) is carried over UNCHANGED from the retired
 * MMI_Xchange_Preset_Profile — only the packaging changed: no more
 * product_scope/import_mode/sources-as-a-profile-row, since those are
 * profile-level settings the user now sets independently on whichever
 * profile they apply this preset to, not something this class should
 * pre-decide.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Field_Mapping_Presets {

    const SUPPLIER_ID = 'xchange';

    /**
     * One entry per shipped preset — see the retired MMI_Xchange_Preset_Profile
     * (mmi-data-pipeline, deleted) for the full history of these field
     * choices. 'groups'/'field_overrides' feed build_mappings() below exactly
     * as they did there; 'data_type'/'sources' are new — pure wizard-
     * visibility metadata, never written into the mappings blob itself.
     *
     * @var array<string, array{name:string, description:string, groups:string[], field_overrides:array<string,array>}>
     */
    const PRESETS = [
        'xchange_preset_creation' => [
            'name'          => 'Xchange — New Products',
            'description'   => 'Ships with Xchange Integration. Creates new products from core Xchange fields — pair with a "new_only" scope profile so it never touches a product that already exists.',
            'groups'        => [ 'core', 'pricing', 'product_type', 'inventory', 'media' ],
            'field_overrides' => [
                // Short Description isn't populated by Xchange in any way
                // worth defaulting on, and the user explicitly doesn't want
                // it pre-enabled for new products.
                'post_excerpt' => [ 'enabled' => false ],
                // WooCommerce derives _price itself from _regular_price/
                // _sale_price/the sale date window on every save — mapping a
                // separate "Active Price" field risks fighting that
                // derivation rather than reflecting it. Left off, matching
                // the same stance taken for the Pricing Refresh preset below.
                '_price' => [ 'enabled' => false ],
                // Dealer Price/Cost of Goods writes to whatever meta key the
                // site's global "COG Meta Key" setting currently names — a
                // setting this preset has no way to know is even configured
                // for this install yet. Source is left correctly pointed at
                // 'dealer_price' (DEFAULTS' own default) so turning this on
                // later needs no further setup, but it should never silently
                // start writing to an unreviewed meta key the moment this
                // preset is applied.
                '__mmi_cog' => [ 'enabled' => false ],
                // Sale price/date window is the Pricing Refresh preset's own
                // job (it reads the real promotions feed, not the main
                // product file) — left off here so the two presets don't
                // both claim the same field with two different sources.
                '_sale_price'            => [ 'enabled' => false ],
                '_sale_price_dates_from' => [ 'enabled' => false ],
                '_sale_price_dates_to'   => [ 'enabled' => false ],
                // Xchange's own 'virtual' feed field is unreliable enough
                // that a blanket "yes" (this preset only creates Xchange
                // software/plugins) is correct, trusted over the feed itself.
                '_virtual' => [ 'enabled' => true, 'constant' => '1' ],
                '_downloadable' => [ 'enabled' => false ],
                // A brand-new product from this preset should always be
                // orderable immediately — these three are deliberately
                // store-owned constants, not values read from the feed.
                '_stock'        => [ 'enabled' => true, 'constant' => '9999' ],
                '_stock_status' => [ 'enabled' => true, 'constant' => 'instock' ],
                '_manage_stock' => [ 'enabled' => true, 'constant' => '1' ],
                // Shipping dimensions are meaningless for the virtual/
                // downloadable software this preset creates.
                '_weight' => [ 'enabled' => false ],
                '_length' => [ 'enabled' => false ],
                '_width'  => [ 'enabled' => false ],
                '_height' => [ 'enabled' => false ],
                // Xchange's Web Asset API bundles every image for a product
                // into one flat JSON array — image_array_mode tells the
                // importer to split that array itself: element 0 becomes the
                // featured image, everything after it becomes the gallery.
                '_product_image_url' => [
                    'enabled'          => true,
                    'source'           => 'images',
                    'file'             => 'xchange-web-assets.json',
                    'image_array_mode' => true,
                ],
                // Superseded by _product_image_url's image_array_mode above.
                '_product_gallery_urls' => [ 'enabled' => false ],
                // Taxonomies are WP-install-specific — the user manages
                // these via Taxonomy Mapping, never pre-decided by a preset.
                'product_cat'   => [ 'enabled' => false ],
                'product_tag'   => [ 'enabled' => false ],
                'product_brand' => [ 'enabled' => false ],
            ],
        ],
        'xchange_preset_pricing' => [
            'name'          => 'Xchange — Pricing Refresh',
            'description'   => 'Ships with Xchange Integration. Keeps pricing/promotion fields current — pair with an "all products" scope, "update-only" mode profile so it never creates a product.',
            'groups'        => [ 'pricing' ],
            'field_overrides' => [
                // Sale price/date window live in Xchange's separate
                // promotions feed, not the main product file DEFAULTS
                // otherwise points at — this is the one place that data
                // actually exists.
                '_sale_price' => [
                    'enabled' => true,
                    'source'  => 'promotions.street_price',
                    'file'    => 'xchange-promotions.json',
                ],
                '_sale_price_dates_from' => [
                    'enabled' => true,
                    'source'  => 'promotions.start_date',
                    'file'    => 'xchange-promotions.json',
                ],
                '_sale_price_dates_to' => [
                    'enabled' => true,
                    'source'  => 'promotions.end_date',
                    'file'    => 'xchange-promotions.json',
                ],
                // WooCommerce derives _price itself — see the identical
                // override/comment on the creation preset above.
                '_price' => [ 'enabled' => false ],
                // Same "don't silently write to an unreviewed meta key"
                // reasoning as the creation preset.
                '__mmi_cog' => [ 'enabled' => false ],
            ],
        ],
        'xchange_preset_enrichment' => [
            'name'          => 'Xchange — Enrichment',
            'description'   => 'Ships with Xchange Integration. Refreshes system requirements/licensing/platform fields — pair with an "all products" scope, "update-only" mode profile so it never creates a product.',
            'groups'        => [ 'enrichment' ],
            'field_overrides' => [],
        ],
    ];

    public static function init(): void {
        add_filter( 'mmi_pipeline_builtin_field_mapping_presets', [ self::class, 'register' ] );
    }

    /**
     * Filter callback — appends this plugin's three presets onto whatever
     * mmi-data-pipeline (or another sibling plugin) already contributed.
     * Guarded rather than assumed: this filter is only ever applied by
     * mmi-data-pipeline, but a guard here costs nothing and matches this
     * project's Standalone Plugin Independence conventions for an optional,
     * bonus contribution rather than a hard dependency.
     *
     * @param array $presets Presets contributed so far by other callbacks.
     * @return array
     */
    public static function register( array $presets ): array {
        if ( ! class_exists( 'MMI_Pipeline_Field_Mapping_Defaults' ) ) {
            return $presets;
        }

        foreach ( self::PRESETS as $preset_id => $config ) {
            $presets[] = [
                'id'            => $preset_id,
                'name'          => $config['name'],
                'mappings'      => self::build_mappings( $config ),
                'data_type'     => 'product',
                'sources'       => [ self::SUPPLIER_ID ],
                'builtin'       => true,
                'source_plugin' => 'mmi-xchange-integration',
                'description'   => $config['description'],
            ];
        }

        return $presets;
    }

    /**
     * Starts from MMI_Pipeline_Field_Mapping_Defaults::blank_mappings() —
     * every DEFAULTS field explicitly present with 'enabled' => false — so
     * nothing this class doesn't deliberately turn on can ever fall through
     * to merge()'s "field absent from the saved row" fallback (which
     * defaults to enabled=true for almost every field; see the retired
     * MMI_Xchange_Preset_Profile's own history of this exact incident).
     * Every field with a real 'xchange' source entry AND a 'group' in
     * $config['groups'] is then turned on, and finally
     * $config['field_overrides'] is applied.
     *
     * @param array $config One entry from self::PRESETS.
     * @return array<string, array> Full field_mappings blob for this preset.
     */
    private static function build_mappings( array $config ): array {
        $mappings = MMI_Pipeline_Field_Mapping_Defaults::blank_mappings();

        foreach ( MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS as $field => $defaults ) {
            $source = $defaults['source'] ?? null;
            if ( ! is_array( $source ) || ! array_key_exists( self::SUPPLIER_ID, $source ) ) {
                continue;
            }
            if ( ! in_array( $defaults['group'] ?? '', $config['groups'], true ) ) {
                continue;
            }
            if ( ! isset( $mappings[ $field ]['enabled'][ self::SUPPLIER_ID ] ) ) {
                // blank_mappings() gave this field a scalar 'enabled' rather
                // than a per-supplier array — not expected for any current
                // DEFAULTS entry, but skip rather than write into a shape
                // that doesn't exist if DEFAULTS ever changes under this.
                continue;
            }
            $mappings[ $field ]['enabled'][ self::SUPPLIER_ID ] = true;

            // blank_mappings() also blanked this supplier's 'source' to ''
            // — unset it so merge() falls through to the real DEFAULTS
            // source the normal way, the same thing that already happens
            // for a field this class doesn't mention at all — so a future
            // DEFAULTS correction to this source path still reaches this
            // preset with no change needed here.
            unset( $mappings[ $field ]['source'][ self::SUPPLIER_ID ] );
        }

        foreach ( $config['field_overrides'] as $field => $override ) {
            if ( ! isset( $mappings[ $field ] ) ) {
                continue;
            }
            self::apply_field_override( $mappings[ $field ], $override );
        }

        self::collapse_scalar_enabled_fields( $mappings );

        return $mappings;
    }

    /**
     * A field whose DEFAULTS entry declares a plain scalar 'enabled' (today,
     * only product_brand) needs its saved row to ALSO be scalar — not the
     * per-supplier array blank_mappings() otherwise gives every field —
     * because of a guard in MMI_Pipeline_Field_Mapping_Defaults::merge()
     * built specifically for that field: it discards a saved array 'enabled'
     * whenever DEFAULTS' own default for that field is a scalar. Collapsing
     * to the one boolean this preset actually set for self::SUPPLIER_ID
     * avoids relying on merge()'s stale-array guard at all, for the one
     * field it applies to.
     */
    private static function collapse_scalar_enabled_fields( array &$mappings ): void {
        foreach ( MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS as $field => $defaults ) {
            if ( array_key_exists( 'enabled', $defaults ) && ! is_array( $defaults['enabled'] ) && isset( $mappings[ $field ] ) ) {
                $row = $mappings[ $field ]['enabled'] ?? false;
                $mappings[ $field ]['enabled'] = is_array( $row ) ? (bool) ( $row[ self::SUPPLIER_ID ] ?? false ) : (bool) $row;
            }
        }
    }

    /**
     * Applies one field's override entry (see PRESETS's own docblock for the
     * shape) onto its blank_mappings()-derived row, in place.
     *
     * @param array $row      Reference to $mappings[$field] — mutated directly.
     * @param array $override One PRESETS 'field_overrides' entry.
     */
    private static function apply_field_override( array &$row, array $override ): void {
        if ( array_key_exists( 'enabled', $override ) ) {
            if ( is_array( $row['enabled'] ?? null ) ) {
                $row['enabled'][ self::SUPPLIER_ID ] = (bool) $override['enabled'];
            } else {
                $row['enabled'] = (bool) $override['enabled'];
            }
        }

        if ( array_key_exists( 'source', $override ) ) {
            if ( ! is_array( $row['source'] ?? null ) ) {
                $row['source'] = [];
            }
            $row['source'][ self::SUPPLIER_ID ] = (string) $override['source'];
        }

        if ( array_key_exists( 'file', $override ) ) {
            if ( ! is_array( $row['file'] ?? null ) ) {
                $row['file'] = [];
            }
            $row['file'][ self::SUPPLIER_ID ] = (string) $override['file'];
        }

        if ( array_key_exists( 'constant', $override ) ) {
            $row['use_constant_value'] = is_array( $row['use_constant_value'] ?? null )
                ? $row['use_constant_value']
                : [];
            $row['constant_value'] = is_array( $row['constant_value'] ?? null )
                ? $row['constant_value']
                : [];
            $row['use_constant_value'][ self::SUPPLIER_ID ] = true;
            $row['constant_value'][ self::SUPPLIER_ID ]     = (string) $override['constant'];
        }

        if ( array_key_exists( 'image_array_mode', $override ) ) {
            $row['image_array_mode'] = is_array( $row['image_array_mode'] ?? null )
                ? $row['image_array_mode']
                : [];
            $row['image_array_mode'][ self::SUPPLIER_ID ] = (bool) $override['image_array_mode'];
        }
    }
}
