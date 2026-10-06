<?php
/**
 * MMI_Xchange_Feed_Catalog — XChange's adapter for mmi-data-pipeline's
 * shared supplier catalog (MMI_Pipeline_Feed_Catalog), shown on the Catalog
 * tab. Products, promotions and web assets (images, long descriptions,
 * features) joined by SKU.
 *
 * The 13–16 MB products and web-assets files are parsed one at a time
 * (assets first, written to their own shard part) so they're never in
 * memory together. "Fetch now" also starts a web-assets run
 * (MMI_Xchange_Vendors::start_web_assets_run()).
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Feed_Catalog extends MMI_Pipeline_Feed_Catalog {

    public function id(): string {
        return 'xchange';
    }

    public function label(): string {
        return 'XChange';
    }

    public function user_can(): bool {
        return mmi_xchange_user_can( 'operate' );
    }

    protected function audit( string $action, array $args ): void {
        mmi_xchange_audit( $action, $args );
    }

    protected function files(): array {
        return [
            'products'   => [ 'file' => 'xchange-products.json', 'label' => 'Products & prices', 'index' => true ],
            'promotions' => [ 'file' => 'xchange-promotions.json', 'label' => 'Promotions', 'index' => true ],
            'web_assets' => [ 'file' => 'xchange-web-assets.json', 'label' => 'Images & descriptions', 'index' => true ],
            'vendors'    => [ 'file' => 'XchangeVendors.json', 'label' => 'Vendors' ],
        ];
    }

    protected function columns(): array {
        return [
            [ 'sku', 'SKU', 'sku' ],
            [ 'code', 'Code', 'code', 'The vendor\'s own product code (XChange\'s CODE column).' ],
            [ 'product', 'Product', 'product' ],
            [ 'brand', 'Brand', 'text' ],
            [ 'vendor', 'Vendor', 'text' ],
            [ 'last_modified', 'Updated', 'date', 'When XChange last changed this product.' ],
            [ 'cost', 'Dealer', 'money', 'Our regular cost from XChange, before any promotion.' ],
            [ 'map', 'MAP', 'money', 'Regular MAP, before any promotion.' ],
            [ 'msrp', 'MSRP', 'money' ],
            [ 'promo', 'Promo', 'promo', 'Promotional dealer price, for a promotion running now or coming up.' ],
            [ 'site', 'Our site', 'site' ],
        ];
    }

    protected function facets(): array {
        return [
            'brand'    => 'All brands',
            'vendor'   => 'All vendors',
            'category' => 'All categories',
            'billing'  => 'Any license type',
        ];
    }

    protected function search_keys(): array {
        return [ 'sku', 'code', 'product', 'brand', 'vendor', 'upc' ];
    }

    protected function sku_meta_keys(): array {
        return [ '_mmi_supplier_sku_xchange', 'sku_xchange' ];
    }

    protected function after_fetch_started(): void {
        if ( MMI_Xchange_Vendors::web_assets_enabled() ) {
            MMI_Xchange_Vendors::start_web_assets_run( 'manual' );
        }
    }

    protected function extra_status(): array {
        $run = MMI_Xchange_Vendors::web_assets_run_active() ? MMI_Xchange_Vendors::web_assets_run() : null;
        return $run ? [ [
            'text' => sprintf( 'Images & descriptions: %d of %d vendors', (int) $run['offset'], count( $run['vendors'] ) ),
            'busy' => true,
        ] ] : [];
    }

    /** XChange's street_price is the promotional MAP (always below map_price). */
    protected function promotion( array $raw ): array {
        return [
            'name'         => $raw['promotion_name'] ?? '',
            'code'         => $raw['promo_code'] ?? '',
            'cost'         => $raw['promo_price'] ?? null,
            'regular_cost' => $raw['dealer_price'] ?? null,
            'map'          => $raw['street_price'] ?? null,
            'regular_map'  => $raw['map_price'] ?? null,
            'start'        => $raw['start_date'] ?? '',
            'end'          => $raw['end_date'] ?? '',
        ];
    }

    protected function build_rows( callable $write_part ): array {
        // Pass 1: web assets → their own part; keep each SKU's first image.
        $assets_raw = $this->read_feed( 'web_assets' );
        $first_img  = [];
        $assets     = [];
        foreach ( (array) ( $assets_raw['web_assets'] ?? [] ) as $asset ) {
            if ( ! empty( $asset['sku'] ) ) {
                $sku               = (string) $asset['sku'];
                $first_img[ $sku ] = (string) ( $asset['images'][0] ?? '' );
                $assets[ $sku ]    = $asset;
            }
        }
        unset( $assets_raw );
        $write_part( 'assets', $assets );
        unset( $assets );

        // Pass 2: products + promotions.
        $promos = [];
        foreach ( (array) ( $this->read_feed( 'promotions' )['promotions'] ?? [] ) as $promo ) {
            if ( ! empty( $promo['sku'] ) ) {
                $promos[ (string) $promo['sku'] ][] = $promo;
            }
        }
        $products = (array) ( $this->read_feed( 'products' )['products'] ?? [] );

        $rows   = [];
        $detail = [];
        foreach ( $products as $p ) {
            $sku = (string) ( $p['sku'] ?? '' );
            if ( $sku === '' ) {
                continue;
            }
            $code  = (string) ( $p['code'] ?? '' );
            $promo = $this->current_promo( $promos[ $sku ] ?? [], (string) ( $p['product'] ?? '' ) );
            $rows[] = [
                'sku'           => $sku,
                'code'          => $code,
                'product'       => (string) ( $p['product'] ?? '' ),
                'sub'           => implode( ' · ', array_filter( [ $p['master_category'] ?? '', $p['sub_category'] ?? '' ] ) ),
                'brand'         => (string) ( $p['brand'] ?? '' ),
                'vendor'        => (string) ( $p['vendor'] ?? '' ),
                'category'      => (string) ( $p['master_category'] ?? '' ),
                // XChange's own `status` is empty for every product; the
                // license type is what varies.
                'billing'       => (string) ( $p['billing_type'] ?? '' ),
                // XChange's product prices are always the regular ones.
                'cost'          => self::money( $p['dealer_price'] ?? null ),
                'map'           => self::money( $p['map_price'] ?? null ),
                'msrp'          => self::money( $p['msrp_price'] ?? null ),
                'currency'      => (string) ( $p['currency'] ?? '' ),
                'upc'           => (string) ( $p['upc_code'] ?? '' ),
                'last_modified' => self::feed_date( $p['last_modified'] ?? '' ),
                'image'         => $first_img[ $sku ] ?? '',
                'promo'         => $promo,
            ];
            $detail[ $sku ] = [ 'product' => $p, 'promos' => $promos[ $sku ] ?? [] ];
        }
        unset( $products );
        $write_part( 'detail', $detail );

        return [
            'rows'   => $rows,
            'counts' => [
                'products'   => count( $rows ),
                'promotions' => array_sum( array_map( 'count', $promos ) ),
                'web_assets' => count( $first_img ),
            ],
        ];
    }

    protected function detail_from_parts( string $sku, array $parts ): array {
        $p      = (array) ( $parts['detail']['product'] ?? [] );
        $assets = (array) ( $parts['assets'] ?? [] );
        $long   = [ 'descript', 'info', 'how_to_sell', 'target_market', 'competative_analysis', 'top_features' ];

        return [
            'product' => (string) ( $p['product'] ?? $sku ),
            'images'  => (array) ( $assets['images'] ?? [] ),
            'text'    => [
                'Description'          => (string) ( $p['descript'] ?? '' ),
                'Long description'     => (string) ( $assets['long_description'] ?? '' ),
                'Info'                 => (string) ( $p['info'] ?? '' ),
                'How to sell'          => (string) ( $p['how_to_sell'] ?? '' ),
                'Target market'        => (string) ( $p['target_market'] ?? '' ),
                'Competitive analysis' => (string) ( $p['competative_analysis'] ?? '' ),
            ],
            'lists'   => [
                'Top features' => $p['top_features'] ?? null,
                'Features'     => $assets['features'] ?? null,
                'Requirements' => $assets['requirements'] ?? null,
                'Platforms'    => $assets['platforms'] ?? null,
                'Licensing'    => $assets['licensing'] ?? null,
            ],
            'promos'  => (array) ( $parts['detail']['promos'] ?? [] ),
            'fields'  => array_diff_key( $p, array_flip( $long ) ),
        ];
    }
}
