<?php
/**
 * MMI_Xchange_Order_Sync
 *
 * Owns the merged "all Xchange orders" snapshot that powers the admin Orders
 * search tab and the Reverb Software Delivery PO browser.
 *
 * Two sync tiers, by design (see the auth-conflict fix, 2026-08):
 *  - run_sync()      — SAFE, automatic. REST /documents only (MMI_Xchange_API_Client).
 *                      No portal login, ever. Used by get_snapshot()'s empty-cache/
 *                      force-refresh path and by queue_sync() (post-order-placement).
 *  - run_full_sync() — MANUAL/opt-in only, never auto-triggered. Also scrapes CCSA +
 *                      Invoice History (MMI_Xchange_Web_Session, a real xchangeb2b.com
 *                      portal login using the reseller's own credentials) for complete
 *                      historical order data. This is the ONLY thing that can end the
 *                      admin's own xchangeb2b.com browser session, so it is only ever
 *                      triggered by an explicit "Full Sync" button click (see
 *                      MMI_Xchange_Ajax::full_sync()).
 *
 * Ported from mmi-reverb-integration's software handler, which built this same
 * snapshot but only exposed it inside one Reverb order's modal.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Order_Sync {

    const SNAPSHOT_KEY        = 'mmi_xchange_orders_snapshot';
    const SYNC_STATE_KEY      = 'mmi_xchange_sync_state';
    const FULL_SYNC_STATE_KEY = 'mmi_xchange_full_sync_state';
    const FULL_SYNC_PROGRESS_KEY = 'mmi_xchange_full_sync_progress';
    const LICENSE_KEY_PREFIX  = 'mmi_xchange_license_';
    const VENDOR_FALLBACK_DOCS_KEY = 'mmi_xchange_vendor_fallback_docs';
    const AS_GROUP = 'mmi-xchange';

    const ORDERS_DB_VERSION_KEY = 'mmi_xchange_orders_db_version';
    const ORDERS_DB_VERSION     = '1.0.0';

    const LICENSE_PLACEHOLDERS = [ 'available', 'download', 'n/a', 'yes', 'no', 'pending', 'processing', 'complete' ];

    public static function init(): void {
        add_action( 'mmi_xchange_order_sync', [ __CLASS__, 'run_sync' ] );
        add_action( 'mmi_xchange_full_sync', [ __CLASS__, 'run_full_sync' ] );
        add_action( 'mmi_xchange_license_fetch', [ __CLASS__, 'enrich_single_license' ], 10, 1 );
    }

    /* ── Durable orders store (wp_mmi_xchange_orders) ─────────────────────────
     * Every synced PO lives here permanently, upserted-by-po — never wiped
     * or wholesale-replaced by a later sync: a real dbDelta-managed table
     * with a DB-version option gate, instead of one big JSON blob in
     * wp_options that gets overwritten on every sync.
     */

    private static function orders_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mmi_xchange_orders';
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::orders_table();
        $charset_collate = $wpdb->get_charset_collate();
        $is_new_table    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table;

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            po            varchar(64) NOT NULL,
            date          varchar(32) NOT NULL DEFAULT '',
            sku           varchar(64) NOT NULL DEFAULT '',
            product       varchar(255) NOT NULL DEFAULT '',
            license_key   text,
            auth          varchar(191) NOT NULL DEFAULT '',
            download_url  text,
            support_url   text,
            vendor_id     varchar(64) NOT NULL DEFAULT '',
            vendor_name   varchar(255) NOT NULL DEFAULT '',
            price         decimal(10,2) NOT NULL DEFAULT 0,
            map           decimal(10,2) NOT NULL DEFAULT 0,
            msrp          decimal(10,2) NOT NULL DEFAULT 0,
            is_promo      tinyint(1) unsigned NOT NULL DEFAULT 0,
            line_count    int unsigned NOT NULL DEFAULT 1,
            in_ccsa       tinyint(1) unsigned NOT NULL DEFAULT 0,
            source        varchar(32) NOT NULL DEFAULT '',
            created_at    datetime NOT NULL,
            updated_at    datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   po (po),
            KEY          sku (sku),
            KEY          vendor_id (vendor_id)
        ) {$charset_collate};";

        dbDelta( $sql );

        // First-ever creation: pull in whatever the legacy JSON snapshot
        // option already had so switching storage backends loses nothing.
        if ( $is_new_table ) {
            $legacy_raw    = MMI_Settings::get( self::SNAPSHOT_KEY );
            $legacy_orders = is_string( $legacy_raw ) ? json_decode( $legacy_raw, true ) : null;
            if ( is_array( $legacy_orders ) && ! empty( $legacy_orders ) ) {
                self::upsert_orders( $legacy_orders );
                MMI_Logger::info( 'XChange orders: migrated ' . count( $legacy_orders ) . ' row(s) from legacy JSON snapshot into ' . $table, [], 'xchange', 'MMI_Xchange_Order_Sync' );
            }
        }

        MMI_Settings::set( self::ORDERS_DB_VERSION_KEY, self::ORDERS_DB_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( MMI_Settings::get( self::ORDERS_DB_VERSION_KEY ) !== self::ORDERS_DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Insert-or-update every order by `po`, filling gaps rather than
     * blindly overwriting — a leaner automatic REST sync must never blank
     * out a field a richer, manually-triggered Full Sync (portal scrape)
     * already captured. Reference catalog fields (price/map/msrp) and the
     * per-sync classification fields (is_promo/line_count/source) are
     * always refreshed to the latest values, since those represent current
     * facts rather than point-in-time history.
     *
     * Also the single choke point for learn_vendor_fallback_docs() (see
     * that method's own docblock for why): every order that ever gets
     * upserted anywhere in this plugin — routine sync, Full Sync, or
     * MMI_Xchange_Po_Linker's live single-PO search — passes through here,
     * so this is the one place a fresh download_url/support_url needs to
     * be captured, regardless of which of those call sites found it.
     */
    public static function upsert_orders( array $orders ): void {
        global $wpdb;
        $table = self::orders_table();
        $now   = current_time( 'mysql' );

        foreach ( $orders as $order ) {
            $po = (string) ( $order['po'] ?? '' );
            if ( $po === '' ) {
                continue;
            }

            self::learn_vendor_fallback_docs(
                (string) ( $order['vendor_name'] ?? '' ),
                (string) ( $order['download_url'] ?? '' ),
                (string) ( $order['support_url'] ?? '' )
            );

            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE po = %s", $po ), ARRAY_A );

            $row = [
                'po'           => $po,
                'date'         => (string) ( $order['date'] ?? '' ),
                'sku'          => (string) ( $order['sku'] ?? '' ),
                'product'      => (string) ( $order['product'] ?? '' ),
                'license_key'  => (string) ( $order['license_key'] ?? '' ),
                'auth'         => (string) ( $order['auth'] ?? '' ),
                'download_url' => (string) ( $order['download_url'] ?? '' ),
                'support_url'  => (string) ( $order['support_url'] ?? '' ),
                'vendor_id'    => (string) ( $order['vendor_id'] ?? '' ),
                'vendor_name'  => (string) ( $order['vendor_name'] ?? '' ),
                'price'        => (float) ( $order['price'] ?? 0 ),
                'map'          => (float) ( $order['map'] ?? 0 ),
                'msrp'         => (float) ( $order['msrp'] ?? 0 ),
                'is_promo'     => empty( $order['is_promo'] ) ? 0 : 1,
                'line_count'   => max( 1, (int) ( $order['line_count'] ?? 1 ) ),
                'in_ccsa'      => empty( $order['in_ccsa'] ) ? 0 : 1,
                'source'       => (string) ( $order['source'] ?? '' ),
                'updated_at'   => $now,
            ];

            if ( $existing === null ) {
                $row['created_at'] = $now;
                $wpdb->insert( $table, $row );
                continue;
            }

            foreach ( [ 'date', 'sku', 'product', 'license_key', 'auth', 'download_url', 'support_url', 'vendor_id', 'vendor_name' ] as $fill_only ) {
                if ( $row[ $fill_only ] === '' && (string) ( $existing[ $fill_only ] ?? '' ) !== '' ) {
                    $row[ $fill_only ] = $existing[ $fill_only ];
                }
            }
            if ( $row['price'] <= 0 && (float) ( $existing['price'] ?? 0 ) > 0 ) {
                $row['price'] = (float) $existing['price'];
            }
            // Once a real CCSA scrape flags this true, a /documents-only
            // refresh (which never sets it) must not silently clear it.
            if ( ! $row['in_ccsa'] && ! empty( $existing['in_ccsa'] ) ) {
                $row['in_ccsa'] = 1;
            }

            $wpdb->update( $table, $row, [ 'po' => $po ] );
        }
    }

    /**
     * Per-vendor last-known-good download_url/support_url — a fallback for
     * exactly one confirmed, structural gap in XChange's own data, not a
     * scraping bug of this plugin's own making: verified directly against
     * both the REST /documents payload AND the real scraped
     * tr_invoice_details.php page for a real order (2026-09-20)
     * that neither one carries a download or support field once XChange has
     * promoted an order out of CCSA into Invoice History — every row in
     * wp_mmi_xchange_orders that DOES have these fields also has
     * `in_ccsa = 1`, with zero exceptions checked at the time this was
     * built. Once an order settles, this data is gone from every source
     * this plugin can reach, XChange REST API and portal scrape alike —
     * there's nothing left to "fetch" for that specific order.
     *
     * What doesn't change per order, though, is the VENDOR's own download
     * portal and support contact — the same values recur identically
     * across that vendor's other orders (e.g. every Raising Jake Studios
     * order in this table points at the same
     * https://www.raisingjakestudios.com/vouch). So instead of a per-PO
     * fetch that's structurally impossible for a settled order, this keeps
     * a rolling per-vendor default, refreshed automatically every time
     * ANY order for that vendor is seen with real values while it's still
     * in that CCSA window — see upsert_orders()'s call into this (every
     * order that enters this plugin from any source passes through there)
     * — plus MMI_Xchange_Ajax::send_fulfillment_email(), which calls this
     * directly with whatever the admin actually sent (auto-fetched or
     * hand-pasted), so a manual paste-in for one order immediately seeds
     * every future order for that same vendor too.
     *
     * Keyed by vendor NAME, not vendor_id — vendor_id isn't reliably
     * available at the manual-send call site (a WC product only carries
     * its vendor as the product_brand taxonomy's name, not XChange's
     * numeric ID), while vendor_name is already present at every call
     * site this needs (every normalized order row, and the Email Customer
     * modal's own submitted fields). XChange's ~100-vendor catalog uses
     * name consistently as the human-facing identity already (the same
     * string this plugin already stores in wp_mmi_xchange_orders.vendor_name
     * and shows in its own UI), so collision risk is negligible in
     * practice.
     *
     * Deliberately not treated as equivalent to a confirmed per-order
     * value: fetch_order_document() flags a doc that had to fall back to
     * this as download_url_is_fallback/support_url_is_fallback, and the
     * Email Customer modal surfaces that distinction rather than silently
     * presenting a vendor default as if it were this specific order's own
     * confirmed data — same "don't overstate what's actually known"
     * principle as this plugin's existing Live-estimate-vs-cost-at-purchase
     * margin distinction.
     *
     * @param string $vendor_name
     * @param string $download_url
     * @param string $support_url
     */
    public static function learn_vendor_fallback_docs( string $vendor_name, string $download_url, string $support_url ): void {
        $vendor_name = trim( $vendor_name );
        if ( $vendor_name === '' || ( $download_url === '' && $support_url === '' ) ) {
            return;
        }

        $map      = self::get_vendor_fallback_docs_map();
        $existing = $map[ $vendor_name ] ?? [];

        $map[ $vendor_name ] = [
            'download_url' => $download_url !== '' ? $download_url : (string) ( $existing['download_url'] ?? '' ),
            'support_url'  => $support_url !== '' ? $support_url : (string) ( $existing['support_url'] ?? '' ),
            'updated_at'   => current_time( 'mysql' ),
        ];

        MMI_Settings::set( self::VENDOR_FALLBACK_DOCS_KEY, wp_json_encode( $map ) );
    }

    /**
     * @return array{download_url:string,support_url:string,updated_at:string}|null
     */
    public static function get_vendor_fallback_docs( string $vendor_name ): ?array {
        $vendor_name = trim( $vendor_name );
        if ( $vendor_name === '' ) {
            return null;
        }
        return self::get_vendor_fallback_docs_map()[ $vendor_name ] ?? null;
    }

    /**
     * @return array<string,array{download_url:string,support_url:string,updated_at:string}>
     */
    private static function get_vendor_fallback_docs_map(): array {
        $raw     = MMI_Settings::get( self::VENDOR_FALLBACK_DOCS_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @return array Every order on file, newest first, in the same shape
     *               normalize_order() produces.
     */
    public static function get_all_orders(): array {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM ' . self::orders_table() . ' ORDER BY date DESC, po DESC', ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( static function ( array $row ): array {
            return [
                'po'           => $row['po'],
                'date'         => $row['date'],
                'sku'          => $row['sku'],
                'product'      => $row['product'],
                'license_key'  => $row['license_key'],
                'auth'         => $row['auth'],
                'download_url' => $row['download_url'],
                'support_url'  => $row['support_url'],
                'vendor_id'    => $row['vendor_id'],
                'vendor_name'  => $row['vendor_name'],
                'price'        => (float) $row['price'],
                'map'          => (float) $row['map'],
                'msrp'         => (float) $row['msrp'],
                'is_promo'     => (bool) (int) $row['is_promo'],
                'line_count'   => (int) $row['line_count'],
                'in_ccsa'      => (bool) (int) $row['in_ccsa'],
                'source'       => $row['source'],
                'sku_match'    => false,
            ];
        }, $rows );
    }

    /**
     * Batch-fetch Xchange PO rows by PO number, for enriching the WC-order
     * fulfillment queue with Xchange fields (License, Auth #, CCSA status,
     * dealer price) without an N+1 query per WC order. One indexed
     * `WHERE po IN (...)` query regardless of how many POs are requested.
     *
     * @param string[] $po_numbers
     * @return array<string,array> po_number => row, in the same shape as
     *                              get_all_orders()'s entries.
     */
    public static function get_orders_by_po_numbers( array $po_numbers ): array {
        $po_numbers = array_values( array_unique( array_filter( $po_numbers, static fn( $po ) => $po !== '' ) ) );
        if ( empty( $po_numbers ) ) {
            return [];
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $po_numbers ), '%s' ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::orders_table() . " WHERE po IN ({$placeholders}) ORDER BY date DESC",
                $po_numbers
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $by_po = [];
        foreach ( $rows as $row ) {
            // First (newest, per the ORDER BY) row for a given PO wins if a
            // PO somehow has multiple line rows on file.
            if ( isset( $by_po[ $row['po'] ] ) ) {
                continue;
            }
            $by_po[ $row['po'] ] = [
                'po'          => $row['po'],
                'date'        => $row['date'],
                'sku'         => $row['sku'],
                'product'     => $row['product'],
                'license_key' => $row['license_key'],
                'auth'        => $row['auth'],
                'vendor_name' => $row['vendor_name'],
                'price'       => (float) $row['price'],
                'in_ccsa'     => (bool) (int) $row['in_ccsa'],
            ];
        }

        return $by_po;
    }

    /**
     * Substring ("contains") search across every synced PO's `po` column —
     * the fuzzy fallback MMI_Xchange_Po_Linker uses when a WC/Reverb order
     * number doesn't appear as the PO's *entire* value (e.g. it was typed
     * on the XChange portal with a "#" or other surrounding text). Unlike
     * get_orders_by_po_numbers() above, a LIKE '%...%' predicate can't use
     * the `po` column's own unique index, so this is a real (bounded) table
     * scan — acceptable only because it's triggered by an explicit,
     * infrequent "Scan for Matches" admin click, never a page-render path,
     * and the LIMIT below caps the worst case regardless of table size.
     * Revisit if wp_mmi_xchange_orders ever crosses the ~10k-row threshold
     * this project treats as needing a smarter query.
     *
     * @param string[] $substrings
     * @return array<int,array{po:string,date:string,sku:string,product:string,license_key:string,auth:string,vendor_name:string,price:float,in_ccsa:bool}>
     */
    public static function search_po_by_substrings( array $substrings ): array {
        $substrings = array_values( array_unique( array_filter( $substrings, static fn( $s ) => $s !== '' ) ) );
        if ( empty( $substrings ) ) {
            return [];
        }

        global $wpdb;
        $like_clauses = implode( ' OR ', array_fill( 0, count( $substrings ), 'po LIKE %s' ) );
        $like_values  = array_map( static fn( $s ) => '%' . $wpdb->esc_like( $s ) . '%', $substrings );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::orders_table() . " WHERE ({$like_clauses}) ORDER BY date DESC LIMIT 500",
                $like_values
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( static fn( $row ) => [
            'po'          => $row['po'],
            'date'        => $row['date'],
            'sku'         => $row['sku'],
            'product'     => $row['product'],
            'license_key' => $row['license_key'],
            'auth'        => $row['auth'],
            'vendor_name' => $row['vendor_name'],
            'price'       => (float) $row['price'],
            'in_ccsa'     => (bool) (int) $row['in_ccsa'],
        ], $rows );
    }

    /**
     * Re-fetches one PO via the safe REST API and upserts it into
     * wp_mmi_xchange_orders — the actual work dispatched (via Action
     * Scheduler, never run inline) when the webhook receiver verifies a
     * `purchase_order_updates`/`shipment_updates` event. Reuses the same
     * normalize_order()/upsert_orders() path as the routine REST sync, so a
     * webhook-triggered refresh can never diverge in shape from a scheduled
     * one.
     */
    public static function refresh_po_from_webhook( string $po_number ): bool {
        $client = new MMI_Xchange_API_Client();
        $doc    = $client->fetch_document( $po_number, 'INVOICE' );

        if ( is_wp_error( $doc ) || ! is_array( $doc ) || empty( $doc ) ) {
            MMI_Logger::warn(
                "Webhook-triggered refresh found no REST document for PO {$po_number} — " .
                ( is_wp_error( $doc ) ? $doc->get_error_message() : 'empty/not found' ) .
                '. It may only exist in CCSA/Invoice History (portal-only, needs a manual Full Sync).',
                [], 'xchange', 'MMI_Xchange_Order_Sync'
            );
            return false;
        }

        self::upsert_orders( [ self::normalize_order( $doc, 'documents' ) ] );
        MMI_Logger::info( "Refreshed PO {$po_number} from an XChange webhook event.", [], 'xchange', 'MMI_Xchange_Order_Sync' );
        return true;
    }

    /* ── Normalization (shared by REST responses and scraped HTML rows) ──── */

    /**
     * @param array  $raw    Raw item — either an Xchange API response object
     *                       (PascalCase/underscore keys) or an already-canonical
     *                       scraped row (po/sku/product/auth/license/... keys).
     * @param string $source 'ccsa' | 'invoice' | 'documents'
     */
    public static function normalize_order( array $raw, string $source ): array {
        $license = self::extract_license( $raw );
        if ( $license !== null && in_array( strtolower( $license ), self::LICENSE_PLACEHOLDERS, true ) ) {
            $license = '';
        }

        // REST /documents rows nest one-or-more SKU/license lines under
        // 'licenses' — one row here still represents one PO/document, so we
        // surface the first line's sku/product/license for the flat display
        // columns and sum price across every line for an order-level total.
        $lines      = is_array( $raw['licenses'] ?? null ) ? $raw['licenses'] : [];
        $first_line = is_array( $lines[0] ?? null ) ? $lines[0] : [];

        $sku     = (string) ( $raw['sku'] ?? $first_line['sku'] ?? $raw['SKU'] ?? $raw['product_sku'] ?? '' );
        $product = (string) ( $raw['product'] ?? $first_line['product'] ?? $raw['product_name'] ?? $raw['PRODUCT'] ?? $raw['description'] ?? '' );

        if ( ( $license ?? '' ) === '' && ! empty( $first_line ) ) {
            $line_license = self::extract_license( $first_line );
            if ( $line_license !== null && ! in_array( strtolower( $line_license ), self::LICENSE_PLACEHOLDERS, true ) ) {
                $license = $line_license;
            }
        }

        $price      = 0.0;
        $has_promo  = false;
        if ( ! empty( $lines ) ) {
            foreach ( $lines as $line ) {
                if ( ! is_array( $line ) ) {
                    continue;
                }
                $price     += (float) ( $line['price'] ?? 0 );
                $has_promo  = $has_promo || ! empty( $line['promo_id'] );
            }
        } else {
            $price     = (float) ( $raw['price'] ?? $raw['dealer_price'] ?? $raw['dealer_cost'] ?? 0 );
            $has_promo = ! empty( $raw['promo_id'] ) || ! empty( $raw['promo'] );
        }

        return [
            'po'           => (string) ( $raw['po'] ?? $raw['po_number'] ?? $raw['transaction_number'] ?? $raw['PO'] ?? '' ),
            'date'         => (string) ( $raw['date'] ?? $raw['order_date'] ?? $raw['ORDER_DATE'] ?? $raw['created_at'] ?? '' ),
            'sku'          => $sku,
            'product'      => $product,
            'license_key'  => $license ?? '',
            'auth'         => (string) ( $raw['auth'] ?? $raw['auth_number'] ?? $raw['AUTH'] ?? $raw['payment_id'] ?? $raw['prepay_auth_number'] ?? '' ),
            'download_url' => (string) ( $raw['download_url'] ?? $raw['download'] ?? $raw['download_path'] ?? $raw['DOWNLOAD_PATH'] ?? '' ),
            'support_url'  => (string) ( $raw['support_url'] ?? $raw['support'] ?? $raw['support_contact'] ?? $raw['SUPPORT'] ?? '' ),
            'vendor_id'    => (string) ( $raw['vendor_id'] ?? $raw['VENDOR_ID'] ?? '' ),
            'vendor_name'  => (string) ( $raw['vendor'] ?? $raw['vendor_name'] ?? $raw['VENDOR'] ?? '' ),
            'price'        => round( $price, 2 ),
            'msrp'         => 0.0,
            'map'          => 0.0,
            'is_promo'     => $has_promo,
            'line_count'   => max( 1, count( $lines ) ),
            'in_ccsa'      => $source === 'ccsa',
            'source'       => $source,
            'sku_match'    => false,
        ];
    }

    /**
     * Pulls a license key out of a raw response array trying every field
     * name Xchange has used across its various endpoints.
     */
    public static function extract_license( array $data ): ?string {
        foreach ( [ 'license_key', 'licenseKey', 'key', 'serial', 'serial_number', 'license', 'LICENSE', 'license_number' ] as $field ) {
            if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
                return trim( $data[ $field ] );
            }
        }
        return null;
    }

    /**
     * @param array ...$sources  Lists of normalized order rows, in priority
     *                           order — first source wins on a PO collision.
     */
    public static function merge_orders( array ...$sources ): array {
        $merged = [];
        foreach ( $sources as $source ) {
            foreach ( $source as $order ) {
                $po = (string) ( $order['po'] ?? '' );
                if ( $po === '' || isset( $merged[ $po ] ) ) {
                    continue;
                }
                $merged[ $po ] = $order;
            }
        }
        return array_values( $merged );
    }

    public static function is_recent_order( array $order, int $days ): bool {
        $date = $order['date'] ?? '';
        if ( $date === '' ) {
            return false;
        }
        $ts = strtotime( $date );
        return $ts !== false && $ts >= strtotime( "-{$days} days" );
    }

    public static function flag_sku_matches( array $orders, string $match_sku ): array {
        if ( $match_sku === '' ) {
            return $orders;
        }
        foreach ( $orders as &$order ) {
            $order['sku_match'] = strcasecmp( (string) ( $order['sku'] ?? '' ), $match_sku ) === 0;
        }
        return $orders;
    }

    public static function find_best_match_po( array $orders ): ?string {
        $matches = array_values( array_filter( $orders, fn( $o ) => ! empty( $o['sku_match'] ) ) );
        return count( $matches ) === 1 ? $matches[0]['po'] : null;
    }

    /* ── License lookup (used by sync enrichment + on-demand AJAX) ───────── */

    /**
     * @return string|null License key, or null if not available yet.
     */
    public static function call_license_api( string $po ): ?string {
        $client = new MMI_Xchange_API_Client();

        $result = $client->fetch_license( $po );
        if ( ! is_wp_error( $result ) ) {
            $key = self::extract_license( $result );
            if ( $key !== null ) {
                return $key;
            }
        }

        $result = $client->fetch_document( $po );
        if ( is_wp_error( $result ) ) {
            return null;
        }

        $item = $result;
        if ( isset( $result[0] ) && is_array( $result[0] ) ) {
            $item = $result[0];
        }
        return self::extract_license( $item );
    }

    /**
     * Full order document for a single PO — license, download URL, support
     * URL, and auth #, in one call — used by the Place Order tab to
     * auto-populate the Email Customer panel right after a fulfillment
     * (automated or manually synced) instead of requiring the admin to
     * hand-type them.
     *
     * Checks the local durable table (fetch_local_document()) first — it's
     * already kept current by the routine list-based REST sync, so a PO
     * that sync has already captured needs no live call at all.
     *
     * The 3 session-risking web-portal steps below (REST single-item,
     * CCSA, Invoice History) are gated on has_license() — missing license
     * alone, NOT missing download/support — and this gating choice is
     * load-bearing, not cosmetic; get it wrong and every request for a
     * settled order times out instead of just being incomplete. Found live
     * (2026-09-20, immediately after shipping a version of this method
     * gated on full "license AND a URL" completeness): every URL-less
     * field on a genuinely settled order (this class's whole reason for
     * existing) means that gate is NEVER satisfied, so a vendor with no
     * fallback data yet (e.g. Eventide, no prior order ever captured its
     * URLs) hit all 3 portal steps on *every single request* — each one
     * calls MMI_API_Throttler::throttle('xchange') at least once (12s min
     * gap), so 3 sequential calls in one request could block 30-40+
     * seconds, past PHP's own execution limit — the AJAX call died before
     * `wp_send_json_success()` ever ran, and the browser showed nothing,
     * not even the license this exact PO already had on file locally.
     * That's strictly worse than doing nothing, and it recurred on every
     * single open of that order's modal, forever, since the vendor
     * fallback below never had anything to teach the URL fields either —
     * a real fix had to change *when* the slow path fires, not just what
     * it fetches.
     *
     * Why license alone is the right gate: a live REST check (2026-09-20)
     * of a real settled order confirmed the /documents payload has no
     * download/support field for it at all — not a parsing gap, genuinely
     * absent — and the *portal* page these 3 steps ultimately scrape
     * (tr_invoice_details.php, reached via CCSA/Invoice History) was
     * separately confirmed to have no such field either. Chasing
     * download/support through these 3 slow, session-risking calls is
     * chasing data that provably isn't there once an order has settled —
     * apply_vendor_fallback_docs() below is the actual answer for that gap
     * now (a fast, local settings read), not another live call. License,
     * by contrast, genuinely can still turn up this way for an order
     * REST's own rolling sync window never saw.
     *
     * @return array{po:string,date:string,sku:string,product:string,license_key:string,auth:string,download_url:string,support_url:string,vendor_id:string,vendor_name:string}|null
     */
    public static function fetch_order_document( string $po ): ?array {
        $doc = self::fetch_local_document( $po );

        if ( ! self::has_license( $doc ) ) {
            $rest_doc = self::fetch_rest_document( $po );
            if ( $rest_doc !== null ) {
                $doc = self::merge_document_gaps( $rest_doc, $doc ?? [] );
            }
        }

        if ( ! self::has_license( $doc ) ) {
            $ccsa_doc = self::fetch_ccsa_document( $po );
            if ( $ccsa_doc !== null ) {
                $doc = self::merge_document_gaps( $ccsa_doc, $doc ?? [] );
            }
        }

        if ( ! self::has_license( $doc ) ) {
            $invoice_doc = self::fetch_invoice_history_document( $po );
            if ( $invoice_doc !== null ) {
                $doc = self::merge_document_gaps( $invoice_doc, $doc ?? [] );
            }
        }

        // The real answer for a missing download/support URL, per this
        // method's own docblock — a fast local read, never a live call.
        if ( $doc !== null && ( $doc['download_url'] === '' || $doc['support_url'] === '' ) ) {
            $doc = self::apply_vendor_fallback_docs( $doc );
        }

        return $doc;
    }

    /**
     * fetch_order_document() without its portal steps: the local orders
     * table (kept current by the scheduled REST sync and XChange webhooks),
     * then the REST API, then the vendor's learned download/support
     * defaults. No web-portal login, so it is safe to call from a
     * background job (operational-continuity.md Rule 13) — used by
     * MMI_Xchange_Auto_Fulfillment's retries.
     *
     * @return array|null Same shape as fetch_order_document().
     */
    public static function fetch_order_document_stateless( string $po ): ?array {
        $doc = self::fetch_local_document( $po );

        if ( ! self::has_license( $doc ) ) {
            $rest_doc = self::fetch_rest_document( $po );
            if ( $rest_doc !== null ) {
                $doc = self::merge_document_gaps( $rest_doc, $doc ?? [] );
            }
        }

        if ( $doc !== null && ( $doc['download_url'] === '' || $doc['support_url'] === '' ) ) {
            $doc = self::apply_vendor_fallback_docs( $doc );
        }

        return $doc;
    }

    /**
     * @param array{vendor_name:string,download_url:string,support_url:string} $doc
     * @return array
     */
    private static function apply_vendor_fallback_docs( array $doc ): array {
        $fallback = self::get_vendor_fallback_docs( (string) ( $doc['vendor_name'] ?? '' ) );
        if ( $fallback === null ) {
            return $doc;
        }

        if ( $doc['download_url'] === '' && ( $fallback['download_url'] ?? '' ) !== '' ) {
            $doc['download_url']             = $fallback['download_url'];
            $doc['download_url_is_fallback'] = true;
        }
        if ( $doc['support_url'] === '' && ( $fallback['support_url'] ?? '' ) !== '' ) {
            $doc['support_url']             = $fallback['support_url'];
            $doc['support_url_is_fallback'] = true;
        }

        return $doc;
    }

    /**
     * The gate for fetch_order_document()'s 3 slow, session-risking
     * web-portal steps — see that method's docblock for why this checks
     * license only, not download/support too (a real, live-confirmed
     * regression when it briefly checked all three).
     */
    private static function has_license( ?array $doc ): bool {
        return $doc !== null && $doc['license_key'] !== '';
    }

    /**
     * Fills blank fields on $newer using whatever $older already had, so a
     * later, less-complete source (e.g. fetch_rest_document()'s known-dead
     * single-item lookup — see fetch_local_document()'s docblock) can
     * never regress a field an earlier one already found. $older may be []
     * (nothing on file yet).
     */
    private static function merge_document_gaps( array $newer, array $older ): array {
        foreach ( [ 'license_key', 'auth', 'download_url', 'support_url', 'vendor_id', 'vendor_name', 'product' ] as $field ) {
            if ( ( $newer[ $field ] ?? '' ) === '' && ( $older[ $field ] ?? '' ) !== '' ) {
                $newer[ $field ] = $older[ $field ];
            }
        }
        return $newer;
    }

    /**
     * Local-table half of fetch_order_document() above. `fetch_rest_document()`'s
     * single-item lookup (`/documents/{id}/`) is checked against real vendor
     * data (2026-08-29) to expect `:id` to be `document_no`, not the
     * `po_number` this codebase actually has for every caller — meaning
     * that call structurally can't find a PO-keyed document, only ever the
     * routine LIST call (`fetch_documents()`, which returns full document
     * objects including `po_number`) actually populates real data. Since
     * that list call already runs on a schedule and upserts every row here
     * via upsert_orders(), checking this table first turns "PO already
     * synced" into a zero-risk, zero-latency local read instead of a REST
     * call that's known not to work followed by a CCSA portal login.
     *
     * @return array{po:string,date:string,sku:string,product:string,license_key:string,auth:string,download_url:string,support_url:string,vendor_id:string,vendor_name:string}|null
     */
    private static function fetch_local_document( string $po ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::orders_table() . ' WHERE po = %s', $po ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        if ( $row['license_key'] === '' && $row['download_url'] === '' && $row['support_url'] === '' ) {
            return null; // On file, but nothing usable yet — let the REST/CCSA fallback try.
        }

        return [
            'po'           => (string) $row['po'],
            'date'         => (string) $row['date'],
            'sku'          => (string) $row['sku'],
            'product'      => (string) $row['product'],
            'license_key'  => (string) $row['license_key'],
            'auth'         => (string) $row['auth'],
            'download_url' => (string) $row['download_url'],
            'support_url'  => (string) $row['support_url'],
            'vendor_id'    => (string) $row['vendor_id'],
            'vendor_name'  => (string) $row['vendor_name'],
            'price'        => (float) $row['price'],
            'msrp'         => (float) $row['msrp'],
            'map'          => (float) $row['map'],
            'is_promo'     => (bool) (int) $row['is_promo'],
            'line_count'   => (int) $row['line_count'],
            'in_ccsa'      => (bool) (int) $row['in_ccsa'],
            'source'       => 'local',
            'sku_match'    => false,
        ];
    }

    /**
     * Safe REST-only half of fetch_order_document() above — only sees
     * orders XChange has already promoted out of CCSA into Invoice
     * History, but carries no session risk.
     *
     * @return array{po:string,date:string,sku:string,product:string,license_key:string,auth:string,download_url:string,support_url:string,vendor_id:string,vendor_name:string}|null
     */
    private static function fetch_rest_document( string $po ): ?array {
        $client = new MMI_Xchange_API_Client();
        $result = $client->fetch_document( $po );
        if ( is_wp_error( $result ) ) {
            return null;
        }

        $item = $result;
        if ( isset( $result[0] ) && is_array( $result[0] ) ) {
            $item = $result[0];
        }
        if ( ! is_array( $item ) || empty( $item ) ) {
            return null;
        }

        return self::normalize_order( $item, 'documents' );
    }

    /**
     * Single-PO lookup against CCSA (Customer Committed Stock Area) — where
     * an order's license/download/support live before XChange promotes it
     * into Invoice History (the REST /document/ and /license/ endpoints
     * don't see it until then). Requires a real portal login via
     * MMI_Xchange_Web_Session — like run_full_sync(), this ENDS any
     * XChange.com browser session the admin currently has open. Called
     * automatically from fetch_order_document() above; not a standalone
     * confirmed action.
     *
     * Persists a match into the durable orders table (upsert_orders()) —
     * same as a full sync would — so this lookup also benefits future
     * Orders-tab searches, not just the one caller.
     *
     * @return array normalized order row (see normalize_order()), or null
     *   if not found in CCSA, or the portal login/fetch failed.
     */
    public static function fetch_ccsa_document( string $po ): ?array {
        $web_session = new MMI_Xchange_Web_Session();
        $items       = $web_session->fetch_ccsa_items();
        if ( is_wp_error( $items ) || ! is_array( $items ) ) {
            return null;
        }

        foreach ( $items as $item ) {
            if ( ( $item['po'] ?? '' ) === $po ) {
                self::upsert_orders( [ $item ] );
                return $item;
            }
        }

        return null;
    }

    /**
     * Single-PO lookup against Invoice History — where an order's
     * license/download/support live once XChange has promoted it OUT of
     * CCSA (real-world timing varies, but the sample this method was
     * built to fix, 2026-08-05, was already gone from CCSA 46 days later).
     * fetch_order_document() previously only ever tried CCSA, so any order
     * old enough to have already moved to Invoice History had nowhere left
     * to fall through to — its own completeness check would correctly
     * decide more data was still needed, then fail to find it anyway.
     *
     * Same session-eviction cost/tradeoff as fetch_ccsa_document() above,
     * called automatically for the same reason — but reuses that same
     * call's already-established PHPSESSID (MMI_Xchange_Web_Session::get_session()'s
     * own 60-second transient) when both run in the same request, so
     * trying both costs one login, not two.
     *
     * @return array normalized order row (see normalize_order()), or null
     *   if not found in Invoice History (within its own 180-day default
     *   window), or the portal login/fetch failed.
     */
    public static function fetch_invoice_history_document( string $po ): ?array {
        $web_session = new MMI_Xchange_Web_Session();
        $items       = $web_session->fetch_invoice_history_items();
        if ( is_wp_error( $items ) || ! is_array( $items ) ) {
            return null;
        }

        foreach ( $items as $item ) {
            if ( ( $item['po'] ?? '' ) === $po ) {
                self::upsert_orders( [ $item ] );
                return $item;
            }
        }

        return null;
    }

    /* ── Snapshot orchestration ───────────────────────────────────────────── */

    public static function get_sync_state(): array {
        $raw = MMI_Settings::get( self::SYNC_STATE_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [ 'synced_at' => null, 'order_count' => 0, 'status' => 'never' ];
    }

    /**
     * State of the last MANUAL full sync (portal-login scrape), tracked
     * separately from the lightweight/automatic API sync above so the UI can
     * show admins how stale their complete order history actually is.
     */
    public static function get_full_sync_state(): array {
        $raw = MMI_Settings::get( self::FULL_SYNC_STATE_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [ 'synced_at' => null, 'order_count' => 0 ];
    }

    /**
     * Live progress of an in-flight (or just-finished) full sync, polled by
     * the Orders tab's status bar while `run_full_sync()` runs in the
     * background via Action Scheduler/WP-Cron.
     *
     * @return array{status:string,step:string,pct:int,message:string}
     */
    public static function get_full_sync_progress(): array {
        $raw     = MMI_Settings::get( self::FULL_SYNC_PROGRESS_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded )
            ? wp_parse_args( $decoded, [ 'status' => 'idle', 'step' => '', 'pct' => 0, 'message' => '' ] )
            : [ 'status' => 'idle', 'step' => '', 'pct' => 0, 'message' => '' ];
    }

    /**
     * @param string $status  'queued' | 'running' | 'complete' | 'error'
     */
    private static function set_full_sync_progress( string $status, string $step = '', int $pct = 0, string $message = '' ): void {
        MMI_Settings::set( self::FULL_SYNC_PROGRESS_KEY, wp_json_encode( [
            'status'     => $status,
            'step'       => $step,
            'pct'        => $pct,
            'message'    => $message,
            'updated_at' => current_time( 'mysql' ),
        ] ) );
    }

    public static function get_snapshot( string $sku_filter = '', bool $force_refresh = false ): array {
        $orders = self::get_all_orders();

        if ( $force_refresh || empty( $orders ) ) {
            self::run_sync();
            $orders = self::get_all_orders();
        }

        $orders = self::flag_sku_matches( $orders, $sku_filter );

        return [
            'orders'        => $orders,
            'best_match_po' => self::find_best_match_po( $orders ),
            'synced_at'     => self::get_sync_state()['synced_at'] ?? null,
        ];
    }

    /**
     * SAFE, automatic sync: REST /documents only — no portal login, ever.
     * Upserts into the durable orders table (fills gaps, never blanks out
     * richer data a prior Full Sync scraped in) — this is what powers the
     * Orders-tab "Refresh" button and the post-order-placement top-up; it
     * can be called as often as needed with zero risk of disrupting anyone's
     * xchangeb2b.com browser session.
     */
    public static function run_sync(): void {
        $client = new MMI_Xchange_API_Client();

        if ( ! $client->has_credentials() ) {
            MMI_Logger::warn( 'mmi_xchange_order_sync: skipped — API credentials not configured', [], 'xchange', 'MMI_Xchange_Order_Sync' );
            return;
        }

        $doc_rows = self::fetch_document_rows( $client );

        self::finalize_sync( $doc_rows, 'api' );
    }

    /**
     * MANUAL full sync: CCSA scrape + Invoice History scrape (both via
     * MMI_Xchange_Web_Session — a real xchangeb2b.com portal login) + REST
     * /documents, merged. This is the only method that can end the admin's
     * own xchangeb2b.com browser session — never call it automatically;
     * only from an explicit, clearly-labeled admin action (see
     * MMI_Xchange_Ajax::full_sync()).
     */
    public static function run_full_sync(): void {
        $client      = new MMI_Xchange_API_Client();
        $web_session = new MMI_Xchange_Web_Session();

        if ( ! $client->has_credentials() ) {
            self::set_full_sync_progress( 'error', '', 0, __( 'API credentials not configured.', 'mmi-xchange-integration' ) );
            MMI_Logger::warn( 'mmi_xchange_full_sync: skipped — API credentials not configured', [], 'xchange', 'MMI_Xchange_Order_Sync' );
            return;
        }

        try {
            self::set_full_sync_progress( 'running', __( 'Logging into XChange web portal…', 'mmi-xchange-integration' ), 10 );
            $ccsa = $web_session->fetch_ccsa_items();

            self::set_full_sync_progress( 'running', __( 'Scraping invoice history…', 'mmi-xchange-integration' ), 35 );
            $invoice = $web_session->fetch_invoice_history_items( 180 );

            $ccsa    = is_wp_error( $ccsa ) ? [] : $ccsa;
            $invoice = is_wp_error( $invoice ) ? [] : $invoice;

            self::set_full_sync_progress( 'running', __( 'Fetching REST document history…', 'mmi-xchange-integration' ), 60 );
            $doc_rows = self::fetch_document_rows( $client );
            $merged   = self::merge_orders( $ccsa, $invoice, $doc_rows );

            self::set_full_sync_progress( 'running', __( 'Finalizing & caching results…', 'mmi-xchange-integration' ), 90 );
            self::finalize_sync( $merged, 'full' );

            self::set_full_sync_progress(
                'complete',
                __( 'Done', 'mmi-xchange-integration' ),
                100,
                sprintf(
                    /* translators: %d: number of orders synced */
                    __( '%d orders synced.', 'mmi-xchange-integration' ),
                    count( $merged )
                )
            );
        } catch ( \Throwable $e ) {
            self::set_full_sync_progress( 'error', '', 0, $e->getMessage() );
            MMI_Logger::error( 'mmi_xchange_full_sync: exception — ' . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Order_Sync' );
        }
    }

    const CATALOG_PRICING_TRANSIENT = 'mmi_xchange_catalog_pricing';
    // Much shorter than the 6h dealer-price cache: a promotion can start or
    // end at any hour, and this drives real margin figures shown to an
    // admin deciding whether to fulfill an order — staleness here is a
    // wrong-cost-basis bug, not just a slightly-outdated reference number.
    const PROMO_PRICING_TRANSIENT = 'mmi_xchange_promo_pricing';
    const PROMO_CACHE_TTL_SECONDS = 15 * MINUTE_IN_SECONDS;

    /** @var array<string,array<string,mixed>>|null In-request cache. */
    private static ?array $catalog_pricing_cache = null;

    /** @var array<string,array{promo_price:float,promotion_name:string}>|null In-request cache. */
    private static ?array $promo_pricing_cache = null;

    /**
     * SKU => full reference record from Xchange's product catalog (safe REST
     * /products/ endpoint — no portal login). Verified field mapping against
     * Xchange's own "Price List" tool: msrp_price = MSRP, map_price = MAP,
     * dealer_price = PRICE (dealer cost); product/code/our_sku/vendor/
     * currency/download_path/status verified against a raw catalog sample.
     * Cached for 6 hours since the catalog is ~4,900 SKUs and changes
     * infrequently — pass $force to bypass both the in-request and transient
     * cache for a one-off live lookup (e.g. the Place Order tab's Preview
     * button), which still repopulates the cache for everyone else.
     *
     * Note: Xchange's Price List tool also shows PSTREET/PROMO (promotional
     * pricing tiers) which are NOT present on this REST endpoint — those
     * only appear to be available via the web portal's Price List export.
     *
     * @return array<string,array{sku:string,product:string,code:string,our_sku:string,vendor_name:string,msrp:float,map:float,dealer:float,currency:string,download_path:string,status:string}>
     */
    private static function get_catalog_map( bool $force = false ): array {
        if ( ! $force && self::$catalog_pricing_cache !== null ) {
            return self::$catalog_pricing_cache;
        }

        if ( ! $force ) {
            $cached = get_transient( self::CATALOG_PRICING_TRANSIENT );
            if ( is_array( $cached ) ) {
                self::$catalog_pricing_cache = $cached;
                return $cached;
            }
        }

        $map    = [];
        $client = new MMI_Xchange_API_Client();
        if ( $client->has_credentials() ) {
            $products = $client->fetch_products();
            if ( ! is_wp_error( $products ) ) {
                $items = $products['products'] ?? $products['results'] ?? $products['data'] ?? ( array_is_list( $products ) ? $products : [] );
                foreach ( (array) $items as $p ) {
                    if ( ! is_array( $p ) ) {
                        continue;
                    }
                    $sku = (string) ( $p['sku'] ?? '' );
                    if ( $sku === '' ) {
                        continue;
                    }
                    $map[ $sku ] = [
                        'sku'           => $sku,
                        'product'       => (string) ( $p['product'] ?? '' ),
                        'code'          => (string) ( $p['code'] ?? '' ),
                        'our_sku'       => (string) ( $p['our_sku'] ?? '' ),
                        'vendor_name'   => (string) ( $p['vendor'] ?? '' ),
                        'msrp'          => (float) ( $p['msrp_price'] ?? 0 ),
                        'map'           => (float) ( $p['map_price'] ?? 0 ),
                        'dealer'        => (float) ( $p['dealer_price'] ?? 0 ),
                        'currency'      => (string) ( $p['currency'] ?? '' ),
                        'download_path' => (string) ( $p['download_path'] ?? '' ),
                        'status'        => (string) ( $p['status'] ?? '' ),
                    ];
                }
            }
        }

        set_transient( self::CATALOG_PRICING_TRANSIENT, $map, 6 * HOUR_IN_SECONDS );
        self::$catalog_pricing_cache = $map;

        // Only when this call actually hit the live API (not a cache read
        // above) — piggybacks on the fetch that already happened, adding no
        // new XChange API volume. Scoped to SKUs this store actually
        // carries; record_prices_batch() itself dedupes against the last
        // recorded price so an unchanged catalog writes zero rows.
        if ( ! empty( $map ) && class_exists( 'MMI_Xchange_Price_History' ) ) {
            $ours = array_intersect_key( $map, array_flip( MMI_Xchange_Price_History::get_xchange_skus_in_store() ) );
            $rows = [];
            foreach ( $ours as $sku => $p ) {
                $rows[] = [ 'sku' => $sku, 'price_type' => 'dealer', 'price' => (float) $p['dealer'], 'promotion_name' => '' ];
            }
            MMI_Xchange_Price_History::record_prices_batch( $rows );
        }

        return $map;
    }

    /**
     * SKU => active-promotion record, from the REST /promotions/ endpoint
     * with no `future` param — the vendor doc's own default ('no') already
     * excludes not-yet-started promotions, so this reflects "what does this
     * SKU actually cost right now," not upcoming deals. dealer_price on
     * /products/ never reflects an active promotion — promo_price here is
     * the real, separate, lower cost while the promo runs; confirmed
     * against real vendor data (sibling SKUs in the same promo batch share
     * one dealer_price but have their own distinct promo_price).
     *
     * @return array<string,array{promo_price:float,promotion_name:string}>
     */
    private static function get_promo_map( bool $force = false ): array {
        if ( ! $force && self::$promo_pricing_cache !== null ) {
            return self::$promo_pricing_cache;
        }

        if ( ! $force ) {
            $cached = get_transient( self::PROMO_PRICING_TRANSIENT );
            if ( is_array( $cached ) ) {
                self::$promo_pricing_cache = $cached;
                return $cached;
            }
        }

        $map    = [];
        $client = new MMI_Xchange_API_Client();
        if ( $client->has_credentials() ) {
            $promotions = $client->fetch_promotions();
            if ( ! is_wp_error( $promotions ) ) {
                $items = $promotions['promotions'] ?? $promotions['results'] ?? $promotions['data'] ?? ( array_is_list( $promotions ) ? $promotions : [] );
                foreach ( (array) $items as $p ) {
                    if ( ! is_array( $p ) ) {
                        continue;
                    }
                    $sku = (string) ( $p['sku'] ?? '' );
                    if ( $sku === '' || ! isset( $p['promo_price'] ) ) {
                        continue;
                    }
                    $map[ $sku ] = [
                        'promo_price'     => (float) $p['promo_price'],
                        'promotion_name'  => (string) ( $p['promotion_name'] ?? '' ),
                    ];
                }
            }
        }

        set_transient( self::PROMO_PRICING_TRANSIENT, $map, self::PROMO_CACHE_TTL_SECONDS );
        self::$promo_pricing_cache = $map;

        // Iterate every SKU this store carries — not just $map's keys — so
        // a promo ENDING is captured as a real diffable row (price 0.00,
        // empty promotion_name is the explicit "no active promotion"
        // sentinel) rather than the SKU's promo row simply disappearing
        // with no trace it ever stopped. Same "only on a live fetch, dedupe
        // inside record_prices_batch()" shape as get_catalog_map() above.
        // Gated on has_credentials() too — without it $map is always empty
        // for a reason unrelated to promo status, and recording "no active
        // promotion" for every SKU would misrepresent unknown as false.
        if ( $client->has_credentials() && class_exists( 'MMI_Xchange_Price_History' ) ) {
            $rows = [];
            foreach ( MMI_Xchange_Price_History::get_xchange_skus_in_store() as $sku ) {
                $promo  = $map[ $sku ] ?? null;
                $rows[] = [
                    'sku'            => $sku,
                    'price_type'     => 'promo',
                    'price'          => (float) ( $promo['promo_price'] ?? 0.0 ),
                    'promotion_name' => (string) ( $promo['promotion_name'] ?? '' ),
                ];
            }
            MMI_Xchange_Price_History::record_prices_batch( $rows );
        }

        return $map;
    }

    /**
     * Cache-first read of both pricing transients — the hourly
     * mmi_xchange_price_history_refresh cron's only job. A public wrapper
     * because get_catalog_map()/get_promo_map() are private; this exists
     * purely so MMI_Xchange_Price_History::refresh() (which must live in
     * its own class, not here, so the price-history table's owner doesn't
     * become order-sync's job) has a way to trigger the same cache-refresh
     * path an admin action would, without duplicating its logic.
     */
    public static function refresh_price_history_caches(): void {
        self::get_catalog_map( false );
        self::get_promo_map( false );
    }

    /**
     * Cost basis MMI actually pays XChange for one SKU, captured at the
     * moment a real purchase is placed (MMI_Xchange_Ajax::place_order(),
     * right after a successful MMI_Xchange_API::place_order() call).
     * Deliberately cache-first (not force-refreshed): by the time an admin
     * can click "Fulfill," MMI_Xchange_Ajax::preview_product() already
     * force-refreshed both transients for this exact SKU moments earlier
     * (loadDetail() in admin-xchange.js), so reading the warm cache here
     * captures precisely what the admin already saw as "Our Cost" — at zero
     * extra throttled XChange API calls for the highest-value action in
     * this plugin. See MMI_Xchange_Fulfillment_Queue::record_cost_snapshot()
     * for where this gets persisted and preview_product() below for the
     * read side.
     *
     * @return array{dealer_price:float,is_promo_active:bool,promo_price:?float,promotion_name:string,captured_at:string}|null
     */
    public static function capture_cost_snapshot( string $sku ): ?array {
        $catalog = self::get_catalog_map( false )[ $sku ] ?? null;
        if ( $catalog === null ) {
            return null;
        }

        $promo = self::get_promo_map( false )[ $sku ] ?? null;

        return [
            'dealer_price'    => (float) $catalog['dealer'],
            'is_promo_active' => $promo !== null,
            'promo_price'     => $promo['promo_price'] ?? null,
            'promotion_name'  => $promo['promotion_name'] ?? '',
            'captured_at'     => current_time( 'mysql', true ),
        ];
    }

    /**
     * Pricing/availability preview for a single SKU — used by the Place
     * Order tab's Preview button before committing to a real purchase, and
     * by the Fulfillment Queue detail table's MARGIN column.
     *
     * $order_id, when > 0, lets this return the real cost-at-purchase
     * snapshot (MMI_Xchange_Fulfillment_Queue::get_cost_snapshot_for_order())
     * instead of a live lookup, for an order whose XChange purchase already
     * happened through MMI_Xchange_Ajax::place_order() since this feature
     * shipped — see AGENTS.md's "Fulfillment Margin Ignored Active XChange
     * Promotions" incident, which this closes for good going forward. Older
     * orders and anything fulfilled via the manual CCSA portal scrape have
     * no snapshot and correctly keep falling back to today's live estimate
     * (their true purchase-time cost was never captured and can't be
     * reconstructed) — callers distinguish the two via cost_source.
     *
     * Without a snapshot this still force-refreshes the catalog (bypasses
     * the 6h transient) so the admin always sees Xchange's current
     * price/status, not a possibly-stale cached figure, at the cost of one
     * extra REST call per click (throttled via MMI_API_Throttler inside
     * fetch_products()). With a snapshot, catalog freshness is irrelevant
     * to cost, so that live call is skipped entirely.
     *
     * @return array{sku:string,product:string,code:string,our_sku:string,vendor_name:string,msrp:float,map:float,dealer:float,currency:string,download_path:string,status:string,product_id:?int,is_promo_active:bool,promo_price:?float,promotion_name:string,cost_source:string,cost_captured_at:?string,regular_price:?float,sale_price:?float,product_image_url:string,product_edit_url:string,brand_id:?int,brand_name:string,brand_thumbnail_url:string,brand_edit_url:string}|null
     */
    public static function preview_product( string $sku, int $order_id = 0 ): ?array {
        $snapshot = ( $order_id > 0 && class_exists( 'MMI_Xchange_Fulfillment_Queue' ) )
            ? MMI_Xchange_Fulfillment_Queue::get_cost_snapshot_for_order( $order_id, $sku )
            : null;

        // A snapshot makes catalog freshness irrelevant to cost, so skip the
        // force-refresh entirely when one exists — cache-first is enough to
        // resolve the non-cost display fields (product name, vendor, etc.).
        $data = self::get_catalog_map( $snapshot === null )[ $sku ] ?? null;

        if ( $data === null ) {
            if ( $snapshot === null ) {
                return null;
            }
            // SKU has since been delisted from Xchange's catalog, but a
            // real purchase happened and was snapshotted — still show the
            // historical margin rather than losing it.
            $data = [
                'sku' => $sku, 'product' => '', 'code' => '', 'our_sku' => '',
                'vendor_name' => '', 'msrp' => 0.0, 'map' => 0.0, 'dealer' => 0.0,
                'currency' => '', 'download_path' => '', 'status' => '',
            ];
        }

        if ( $snapshot !== null ) {
            // Margin calculations must use promo_price, not dealer_price,
            // while a promotion was active at purchase time — dealer_price
            // is the SKU's normal, non-promotional cost and never changes
            // just because a promotion ran. See AGENTS.md's "Fulfillment
            // Queue Margin Ignored Active XChange Promotions" incident.
            $data['dealer']           = (float) $snapshot['dealer_price'];
            $data['is_promo_active']  = (bool) $snapshot['is_promo_active'];
            $data['promo_price']      = $snapshot['promo_price'];
            $data['promotion_name']   = (string) $snapshot['promotion_name'];
            $data['cost_source']      = 'snapshot';
            $data['cost_captured_at'] = (string) $snapshot['captured_at'];
        } else {
            $promo                     = self::get_promo_map( true )[ $sku ] ?? null;
            $data['is_promo_active']   = $promo !== null;
            $data['promo_price']       = $promo['promo_price'] ?? null;
            $data['promotion_name']    = $promo['promotion_name'] ?? '';
            $data['cost_source']       = 'live';
            $data['cost_captured_at']  = null;
        }

        // Resolved separately from the catalog map (which only ever holds
        // Xchange's own fields) so the Orders tab's detail table can link
        // the WC product name straight to its edit screen — reuses the
        // exact lookup the Vendors tab already relies on, not a new query.
        $product_id          = class_exists( 'MMI_Xchange_Vendors' )
            ? MMI_Xchange_Vendors::find_product_id_by_xchange_sku( $sku )
            : null;
        $data['product_id'] = $product_id;

        // Admin-only view (Fulfillment Queue detail table) — safe to show
        // the full four-figure pricing picture: what the customer is
        // charged (regular/sale price, already public on the product page)
        // alongside what it costs us (dealer/promo price, from the catalog
        // map + promo map above). Never sent to the customer-facing email —
        // see class-xchange-fulfillment-email.php's own regular_price-only
        // handling for that boundary.
        $data['regular_price']       = null;
        $data['sale_price']          = null;
        $data['product_image_url']   = '';
        $data['product_edit_url']    = '';
        $data['brand_id']            = null;
        $data['brand_name']          = '';
        $data['brand_thumbnail_url'] = '';
        $data['brand_edit_url']      = '';

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $regular_price = $product->get_regular_price();
                $data['regular_price'] = $regular_price !== '' ? (float) $regular_price : null;
                if ( $product->is_on_sale() ) {
                    $sale_price = $product->get_sale_price();
                    $data['sale_price'] = $sale_price !== '' ? (float) $sale_price : null;
                }
                $image_id = $product->get_image_id();
                if ( $image_id ) {
                    $data['product_image_url'] = (string) wp_get_attachment_image_url( $image_id, 'thumbnail' );
                }
            }
            $data['product_edit_url'] = (string) get_edit_post_link( $product_id, '' );

            $brand_terms = get_the_terms( $product_id, 'product_brand' );
            if ( is_array( $brand_terms ) && ! empty( $brand_terms ) ) {
                $brand = $brand_terms[0];
                $data['brand_id']       = $brand->term_id;
                $data['brand_name']     = $brand->name;
                $data['brand_edit_url'] = (string) get_edit_term_link( $brand->term_id, 'product_brand' );

                $thumbnail_id = get_term_meta( $brand->term_id, 'thumbnail_id', true );
                if ( $thumbnail_id ) {
                    $data['brand_thumbnail_url'] = (string) wp_get_attachment_image_url( (int) $thumbnail_id, 'thumbnail' );
                }
            }
        }

        return $data;
    }

    /**
     * $start_date/$end_date pass straight through to
     * MMI_Xchange_API_Client::fetch_documents() — null on both (run_sync()'s
     * own call) keeps that method's existing rolling-14-days-ending-today
     * default; MMI_Xchange_Po_Linker::find_candidate_for_order() (via
     * search_documents_live() below) is the one caller that supplies an
     * explicit range anchored to a specific order's own date instead.
     *
     * @return array Normalized 'documents'-sourced rows from the REST API.
     */
    private static function fetch_document_rows( MMI_Xchange_API_Client $client, ?string $start_date = null, ?string $end_date = null ): array {
        $docs = $client->fetch_documents( $start_date, $end_date );
        if ( is_wp_error( $docs ) ) {
            return [];
        }

        $items = $docs['orders'] ?? $docs['documents'] ?? $docs['invoices'] ?? $docs['results'] ?? $docs['data'] ?? ( array_is_list( $docs ) ? $docs : [] );

        $rows = [];
        foreach ( (array) $items as $item ) {
            if ( is_array( $item ) ) {
                $rows[] = self::normalize_order( $item, 'documents' );
            }
        }
        return $rows;
    }

    /**
     * On-demand REST search for one specific order's likely fulfillment
     * window — the live-API half of MMI_Xchange_Po_Linker::find_candidate_for_order(),
     * used only after the local wp_mmi_xchange_orders table (and its
     * bounded substring fallback) came up empty for that order. Unlike
     * run_sync()'s routine call (always a rolling 14-day window ending
     * today, which is why the local table only ever covers recent orders),
     * this anchors the window to the ORDER's own date — the only way to
     * reach a PO from months or years ago via REST at all.
     *
     * A real, rate-limited external call (MMI_API_Throttler paces every
     * request automatically inside MMI_Xchange_API_Client::request()) —
     * safe here specifically because this is always one explicit admin
     * click on one specific order, never looped across a batch of orders.
     *
     * @return array Normalized 'documents'-sourced rows (see normalize_order()) —
     *   NOT yet upserted into wp_mmi_xchange_orders; the caller decides
     *   whether to cache what comes back.
     */
    public static function search_documents_live( string $order_date ): array {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return [];
        }

        $anchor = strtotime( $order_date ) ?: time();
        // A few days of slack before the order (a portal PO occasionally
        // gets logged a day or two off from the sale itself) and a
        // generous window after (a manual fulfillment isn't always
        // same-day) — wide enough to catch the real case, far short of a
        // full historical crawl.
        $start = gmdate( 'Y-m-d', strtotime( '-3 days', $anchor ) );
        $end   = gmdate( 'Y-m-d', min( time(), strtotime( '+45 days', $anchor ) ) );

        return self::fetch_document_rows( $client, $start, $end );
    }

    /**
     * Shared tail of both sync tiers: patch in cached licenses, write the
     * snapshot + sync-state, and schedule follow-up license-fetch jobs for
     * recent orders still missing a license key.
     */
    private static function finalize_sync( array $merged, string $mode ): void {
        $catalog = self::get_catalog_map();

        foreach ( $merged as &$order ) {
            if ( $order['license_key'] === '' && $order['po'] !== '' ) {
                $cached = MMI_Settings::get( self::LICENSE_KEY_PREFIX . $order['po'] );
                if ( is_string( $cached ) && $cached !== '' ) {
                    $order['license_key'] = $cached;
                }
            }

            // Join in catalog-level reference pricing (MSRP/MAP/current
            // dealer price) by SKU — this is Xchange's own "Price List"
            // catalog data (verified via REST /products/), distinct from
            // the price actually paid on this specific order/document.
            $sku_pricing = $catalog[ $order['sku'] ] ?? null;
            if ( $sku_pricing !== null ) {
                $order['msrp'] = $sku_pricing['msrp'];
                $order['map']  = $sku_pricing['map'];
                // Prefer the catalog's current dealer price as the
                // authoritative "Price" figure (matches what Xchange's own
                // Price List tool shows); fall back to the price captured
                // on the order's own document/license line if the SKU has
                // since been discontinued/removed from the active catalog.
                if ( $sku_pricing['dealer'] > 0 ) {
                    $order['price'] = $sku_pricing['dealer'];
                }
            } else {
                $order['msrp'] = $order['msrp'] ?? 0.0;
                $order['map']  = $order['map'] ?? 0.0;
            }
        }
        unset( $order );

        // Upsert into the durable table — fills gaps per-PO, never wipes or
        // replaces the rest of the accumulated history. `$merged` here is
        // only this sync's fetch; every previously-stored PO not present in
        // it is left completely untouched.
        self::upsert_orders( $merged );

        $total_on_file = count( self::get_all_orders() );

        MMI_Settings::set( self::SYNC_STATE_KEY, wp_json_encode( [
            'synced_at'   => current_time( 'mysql' ),
            'order_count' => $total_on_file,
            'status'      => 'complete',
            'mode'        => $mode,
        ] ) );

        if ( $mode === 'full' ) {
            MMI_Settings::set( self::FULL_SYNC_STATE_KEY, wp_json_encode( [
                'synced_at'   => current_time( 'mysql' ),
                'order_count' => $total_on_file,
            ] ) );
        }

        MMI_Logger::info( "XChange order sync complete ({$mode})", [ 'this_sync_rows' => count( $merged ), 'total_on_file' => $total_on_file ], 'xchange', 'MMI_Xchange_Order_Sync' );

        $delay = 0;
        foreach ( $merged as $order ) {
            $po = $order['po'] ?? '';
            if ( $order['license_key'] !== '' || $po === '' || ! self::is_recent_order( $order, 7 ) ) {
                continue;
            }
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_unschedule_all_actions( 'mmi_xchange_license_fetch', [ $po ], self::AS_GROUP );
                as_schedule_single_action( time() + 10 + $delay, 'mmi_xchange_license_fetch', [ $po ], self::AS_GROUP );
                $delay += 10;
            }
        }
    }

    /**
     * Per-PO background job: fetch a license that wasn't available yet at
     * sync time, cache it, and patch the durable orders row.
     */
    public static function enrich_single_license( string $po ): void {
        $license = self::call_license_api( $po );
        if ( $license === null || $license === '' ) {
            MMI_Logger::debug( "XChange license enrichment: no license yet for PO {$po}", [], 'xchange', 'MMI_Xchange_Order_Sync' );
            return;
        }

        MMI_Settings::set( self::LICENSE_KEY_PREFIX . $po, $license );

        global $wpdb;
        $wpdb->update(
            self::orders_table(),
            [ 'license_key' => $license, 'updated_at' => current_time( 'mysql' ) ],
            [ 'po' => $po ]
        );

        MMI_Logger::info( "XChange license enriched for PO {$po}", [], 'xchange', 'MMI_Xchange_Order_Sync' );
    }

    /**
     * Queue an immediate (few-second-delay) SAFE/API-only sync — used after
     * placing an order so the new PO shows up shortly without blocking the
     * request. No portal login involved.
     */
    public static function queue_sync(): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_unschedule_all_actions( 'mmi_xchange_order_sync', [], self::AS_GROUP );
            as_schedule_single_action( time() + 3, 'mmi_xchange_order_sync', [], self::AS_GROUP );
        } else {
            wp_schedule_single_event( time() + 3, 'mmi_xchange_order_sync' );
        }
    }

    /**
     * Queue an immediate (few-second-delay) MANUAL full sync — used by the
     * "Full Sync" button. Async so the AJAX response returns instantly; the
     * portal login + scrape happens moments later in the background.
     */
    public static function queue_full_sync(): void {
        self::set_full_sync_progress( 'queued', __( 'Queued…', 'mmi-xchange-integration' ), 2 );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_unschedule_all_actions( 'mmi_xchange_full_sync', [], self::AS_GROUP );
            as_schedule_single_action( time() + 3, 'mmi_xchange_full_sync', [], self::AS_GROUP );
        } else {
            wp_schedule_single_event( time() + 3, 'mmi_xchange_full_sync' );
        }
    }
}
