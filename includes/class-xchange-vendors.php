<?php
/**
 * MMI_Xchange_Vendors
 *
 * Vendor/brand directory + catalog CSV export + product-image import.
 * Ported from `xchangemarket`'s `product_csv()` / `import_media()` (via
 * xchange_connect). Fix applied during the port: neither admin-post
 * endpoint had a capability or nonce check in the original — both are
 * added here, since they read/write files and hit an external API on
 * request.
 *
 * `import_media()` additionally now attaches each downloaded image directly
 * to its matching WooCommerce product (by supplier SKU) instead of only
 * leaving it as an unattached media-library item — matching how
 * mmi-data-pipeline's own `MMI_Product_CRUD_Manager::set_product_images()`
 * attaches images for every other supplier, so a manual CSV/WP All Import
 * re-map is no longer required just to see the image on the product.
 *
 * Image import runs as an Action Scheduler job (queue_import_media() /
 * run_import_media()), polled via progress state — it used to run inline
 * inside the admin-post handler with set_time_limit(0), which held a
 * web-facing PHP-FPM worker open for the entire all-vendors download (AGENTS.md
 * Rule 9: long admin-triggered actions must dispatch, not block). Mirrors the
 * exact queue/progress/state pattern MMI_Xchange_Order_Sync already uses for
 * "Full Sync…".
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Vendors {

    // Crash-proof one-item-per-tick batching + recurring watchdog — see
    // trait-batch-watchdog.php (shared library). This is the reference
    // consumer the trait was extracted from on 2026-09-18; the hand-rolled
    // implementation that used to live in this class directly (its own
    // cursor/heartbeat tracking, its own GET_LOCK() watchdog) now lives
    // there instead, generalized so mmi-data-pipeline and any future
    // sibling integration plugin can reuse it for their own per-item
    // asset-pull jobs without reproducing this same "one AS action for the
    // whole catalog dies at 300s" mistake.
    use MMI_Batch_Watchdog_Trait;

    const NONCE_ACTION = 'mmi_xchange_admin';

    const IMPORT_MEDIA_PROGRESS_KEY = 'mmi_xchange_import_media_progress';
    const IMPORT_MEDIA_STATE_KEY    = 'mmi_xchange_import_media_state';

    /** Batch key for the all-vendors media import — see MMI_Batch_Watchdog_Trait. */
    const MEDIA_IMPORT_BATCH_KEY          = 'mmi_xchange_media_import_batch';
    const MEDIA_IMPORT_BATCH_TICK_HOOK    = 'mmi_xchange_import_media_batch';
    const MEDIA_IMPORT_BATCH_WATCHDOG_HOOK = 'mmi_xchange_import_media_watchdog';

    /**
     * Unix timestamp of the last successful web-assets run (finish_web_assets_run()),
     * used as the Web Asset API's `since` param on the next run — see that
     * method's docblock for why this must merge, not replace, the cache.
     */
    const WEB_ASSETS_SINCE_KEY = 'mmi_xchange_web_assets_since';

    /**
     * The web-assets pull runs as a chain of short Action Scheduler batches
     * (start_web_assets_run() → run_web_assets_batch()): ~115 vendor
     * requests 2 s apart is ~4 minutes, and run in one go it was killed by
     * the cron runner's 45 s limit nearly every hour (file stuck at 14:39 on
     * 2026-10-05). Each batch saves its records to a part file; the last one
     * merges them into the real file in one rename.
     */
    const WEB_ASSETS_RUN_KEY        = 'mmi_xchange_web_assets_run';
    const WEB_ASSETS_BATCH_HOOK     = 'mmi_xchange_web_assets_batch';
    const WEB_ASSETS_GROUP          = 'mmi-xchange-web-assets';
    const WEB_ASSETS_BATCH_VENDORS  = 6;
    const WEB_ASSETS_BATCH_SECONDS  = 25;
    /** A run untouched this long is dead (killed step) and may be replaced. */
    const WEB_ASSETS_RUN_STALE      = 900;

    /**
     * Heartbeat key for mmi-data-pipeline's digest staleness check (see
     * MMI_Pipeline_Cron::get_stale_listeners()) — this listener shares the
     * mmi_pipeline_source_fetch_xchange hook with that plugin's own
     * product-feed fetch, so a silent failure here produces no digest queue
     * entry of its own; the sibling fetch's clean entry alone made the whole
     * hook look healthy through this exact failure (see
     * DIGEST_SILENT_FAILURE_HANDOFF.md). Stamped on every successful run,
     * next to the existing success log line below.
     */
    const WEB_ASSETS_LAST_SUCCESS_KEY = 'mmi_listener_last_success_xchange_web_assets';

    /**
     * Flattened image-object key substrings that mark a vendor asset as
     * brand/marketing collateral rather than a product photo (case-insensitive).
     * Vendor "images" objects sometimes nest a shared brand logo/banner
     * alongside real product photos with no distinguishing structure — without
     * this filter every product from that vendor picks up the same logo as a
     * gallery image. Tune this list against real vendor data as false
     * positives/negatives turn up (see the skip log in do_import_media()).
     */
    const NON_PRODUCT_IMAGE_KEY_PATTERN = '/logo|brand|banner|watermark/i';

    /**
     * Postmeta key storing the original Xchange asset URL an attachment was
     * imported from — wp_insert_attachment()'s `guid` is set to the local
     * uploaded file's URL, not the source URL, so it can't be used to detect
     * "was this remote asset already imported" on a later run.
     *
     * Same literal value as MMI_Media_Helper::SOURCE_URL_META_KEY (mmi-hub) —
     * kept as its own constant here, not a cross-class const reference
     * (which would require mmi-hub's class to already be loaded at this
     * file's parse time, a load-order assumption not worth risking for a
     * string literal), but the two values must stay in sync if either ever
     * changes. Sharing the literal value is what lets mmi-data-pipeline's
     * own image handling recognize an attachment this plugin already
     * imported, and vice versa, instead of each downloading its own
     * duplicate copy — see find_attachment_by_url() below, which now
     * delegates to the same shared MMI_Media_Helper lookup.
     */
    const SOURCE_URL_META_KEY = '_mmi_source_url';

    public static function init(): void {
        add_action( 'admin_post_xchange_product_csv', [ __CLASS__, 'handle_product_csv' ] );
        add_action( 'admin_post_xchange_toggle_web_assets', [ __CLASS__, 'handle_toggle_web_assets' ] );
        add_action( 'mmi_xchange_import_media', [ __CLASS__, 'run_import_media' ] );
        add_action( self::MEDIA_IMPORT_BATCH_TICK_HOOK, [ __CLASS__, 'batch_tick_dispatch' ], 10, 3 );
        add_action( self::MEDIA_IMPORT_BATCH_WATCHDOG_HOOK, [ __CLASS__, 'batch_watchdog_dispatch' ], 10, 1 );

        // Piggyback on mmi-data-pipeline's existing Xchange source-fetch cron
        // cadence instead of registering a new schedule — see
        // cron_write_web_assets_json(). Previously hooked to the unified
        // 'mmi_scheduled_supplier_fetch' action, which mmi-data-pipeline
        // migrated away from in favor of one hook per schedulable source
        // (MMI_Pipeline_Cron::init(), 'mmi_pipeline_source_fetch_{supplier}')
        // — that migration unconditionally calls
        // wp_clear_scheduled_hook('mmi_scheduled_supplier_fetch') on every
        // request specifically because it's retired, which silently made
        // this registration permanently unreachable (confirmed live,
        // 2026-09-08: the cron event was never scheduled, and
        // xchange-web-assets.json sat stale for 5+ weeks with the feature
        // toggle on). Rewired onto the live per-source hook so this fires on
        // the same cadence as the main Xchange product-feed fetch.
        add_action( 'mmi_pipeline_source_fetch_xchange', [ __CLASS__, 'cron_write_web_assets_json' ] );
        add_action( self::WEB_ASSETS_BATCH_HOOK, [ __CLASS__, 'run_web_assets_batch' ], 10, 1 );
        add_filter( 'mmi_pipeline_listener_heartbeats', [ __CLASS__, 'register_web_assets_heartbeat' ] );
    }

    /**
     * Registers cron_write_web_assets_json() with mmi-data-pipeline's daily
     * digest staleness check — see MMI_Pipeline_Cron::get_stale_listeners()
     * and WEB_ASSETS_LAST_SUCCESS_KEY above.
     *
     * @param array $registry
     * @return array
     */
    public static function register_web_assets_heartbeat( array $registry ): array {
        $registry[] = [
            'key'                       => 'xchange_web_assets',
            'label'                     => 'XChange Web Assets Writer',
            'hook'                      => 'mmi_pipeline_source_fetch_xchange',
            'fallback_interval_seconds' => 12 * HOUR_IN_SECONDS,
            'is_enabled'                => static function (): bool {
                return class_exists( 'MMI_DB' ) && (bool) \MMI_DB::get_setting( 'mmi_xchange_web_assets_enabled', false );
            },
        ];

        return $registry;
    }

    /**
     * Toggle for the pipeline web-assets pilot (admin/views/partials/pipeline-step-1-acquisition.php
     * in mmi-data-pipeline). Separate from and in addition to the VIP-tier
     * entitlement check in cron_write_web_assets_json() — defaults to disabled
     * since most mmi-data-pipeline customers don't use Xchange at all.
     */
    public static function handle_toggle_web_assets(): void {
        if ( ! mmi_xchange_user_can() ) {
            mmi_xchange_audit( 'settings.update', [ 'outcome' => 'denied', 'details' => [ 'keys' => [ 'mmi_xchange_web_assets_enabled' ] ] ] );
            wp_die( esc_html__( 'Insufficient permissions.', 'mmi-xchange-integration' ), 403 );
        }
        check_admin_referer( 'mmi_xchange_toggle_web_assets' );

        $enabled = ! empty( $_POST['enabled'] );
        if ( class_exists( 'MMI_DB' ) ) {
            \MMI_DB::set_setting( 'mmi_xchange_web_assets_enabled', $enabled );
            mmi_xchange_audit( 'settings.update', [
                'outcome' => 'success',
                'details' => [ 'keys' => [ 'mmi_xchange_web_assets_enabled' ], 'to' => $enabled ],
            ] );
        }

        $redirect = wp_get_referer() ?: admin_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ── Vendor directory (Vendors admin tab) ─────────────────────────────── */

    /**
     * @return array|WP_Error List of ['vendor_id'=>, 'name'=>, ...raw fields].
     */
    public static function get_vendors() {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return new WP_Error( 'xchange_no_credentials', __( 'XChange API credentials are not configured.', 'mmi-xchange-integration' ) );
        }

        $result = $client->fetch_vendors();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['vendors'] ?? [];
    }

    /* ── CSV export ────────────────────────────────────────────────────────── */

    public static function handle_product_csv(): void {
        if ( ! mmi_xchange_user_can( 'admin' ) ) {
            mmi_xchange_audit( 'export.download', [ 'outcome' => 'denied', 'details' => [ 'export' => 'xchange_products.csv' ] ] );
            wp_die( esc_html__( 'Insufficient permissions.', 'mmi-xchange-integration' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        try {
            $csv = self::build_product_csv();
        } catch ( Exception $e ) {
            MMI_Logger::error( 'XChange product CSV export failed: ' . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Vendors' );
            mmi_xchange_audit( 'export.download', [ 'outcome' => 'failure', 'details' => [ 'export' => 'xchange_products.csv' ] ] );
            wp_die( esc_html( 'XChange connection failed: ' . $e->getMessage() ) );
        }

        mmi_xchange_audit( 'export.download', [ 'outcome' => 'success', 'details' => [ 'export' => 'xchange_products.csv' ] ] );

        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="xchange_products.csv";' );
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- raw CSV stream, not HTML.
        exit;
    }

    /**
     * @throws Exception
     */
    private static function build_product_csv(): string {
        set_time_limit( 0 );

        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            throw new Exception( 'XChange API credentials are not configured.' );
        }

        $products = $client->fetch_products();
        if ( is_wp_error( $products ) ) {
            throw new Exception( $products->get_error_message() );
        }

        $products_assoc  = [];
        $product_headers = [];

        foreach ( (array) ( $products['products'] ?? [] ) as $product ) {
            if ( empty( $product_headers ) ) {
                foreach ( $product as $key => $value ) {
                    $product_headers[ $key ] = 'common_' . strtolower( trim( $key ) );
                }
            }

            $row = [];
            foreach ( $product_headers as $key => $renamed ) {
                $row[ $renamed ] = isset( $product[ $key ] ) ? trim( self::stringify( 0, $product[ $key ] ) ) : '';
            }
            $row['meta:_xchange_internal_sku'] = $product['sku'] ?? '';
            $products_assoc[ $product['sku'] ?? uniqid( 'row_' ) ] = $row;
        }

        $vendors = $client->fetch_vendors();
        if ( is_wp_error( $vendors ) ) {
            throw new Exception( $vendors->get_error_message() );
        }

        $asset_headers = [];

        foreach ( (array) ( $vendors['vendors'] ?? [] ) as $vendor ) {
            $assets = $client->fetch_web_assets( (string) ( $vendor['vendor_id'] ?? '' ) );
            if ( is_wp_error( $assets ) ) {
                continue;
            }

            foreach ( (array) $assets as $product ) {
                if ( ! isset( $product['sku'] ) ) {
                    continue;
                }
                $sku = $product['sku'];

                if ( empty( $asset_headers ) ) {
                    foreach ( $product as $key => $value ) {
                        if ( $key === 'images' ) {
                            continue;
                        }
                        $asset_headers[ $key ] = 'asset_' . strtolower( trim( $key ) );
                    }
                }

                if ( ! isset( $products_assoc[ $sku ] ) ) {
                    continue;
                }

                foreach ( $asset_headers as $key => $renamed ) {
                    $products_assoc[ $sku ][ $renamed ] = $key === 'long_description'
                        ? ( isset( $product[ $key ] ) ? trim( self::parse_long_description( $product[ $key ] ) ) : '' )
                        : ( isset( $product[ $key ] ) ? trim( self::stringify( 0, $product[ $key ] ) ) : '' );
                }

                $products_assoc[ $sku ]['media'] = self::matched_media_filenames( $sku, $product['images'] ?? [] );
            }
        }

        $renamed_keys   = array_values( $product_headers );
        $renamed_keys   = array_merge( $renamed_keys, array_values( $asset_headers ), [ 'media', 'meta:_xchange_internal_sku' ] );

        $buffer = fopen( 'php://memory', 'r+' );
        fputcsv( $buffer, $renamed_keys );
        foreach ( $products_assoc as $row ) {
            fputcsv( $buffer, array_map( fn( $key ) => $row[ $key ] ?? '', $renamed_keys ) );
        }
        rewind( $buffer );
        $csv = stream_get_contents( $buffer );
        fclose( $buffer );

        return $csv;
    }

    private static function matched_media_filenames( string $sku, array $images ): string {
        $media_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'product_images/';
        $found      = [];

        foreach ( self::flatten( '', $images ) as $flattened_key => $url ) {
            $file_type = substr( $url, strrpos( $url, '.' ) );
            $file_name = $sku . '_' . $flattened_key . $file_type;
            if ( file_exists( $media_path . $file_name ) ) {
                $found[] = $file_name;
            }
        }

        return implode( ',', $found );
    }

    /* ── Pipeline web-assets JSON writer ──────────────────────────────────────
     * Consolidates the Web Asset API's per-vendor product records (richer
     * images + long-form description, only available via fetch_web_assets(),
     * not the plain fetch_products() feed) into a single SKU-indexed JSON file
     * that mmi-data-pipeline reads as a secondary Xchange data source — see
     * MMI_Pipeline_Field_Resolver::load_web_assets_index()/
     * enrich_item_with_web_assets() in mmi-data-pipeline.
     *
     * Previously also gated behind mmi-data-pipeline's 'vip' feature tier —
     * removed along with the tier/feature-license system; runs for any site
     * with Xchange configured and this setting enabled.
     */

    public static function cron_write_web_assets_json(): void {
        if ( ! class_exists( 'MMI_DB' ) || ! \MMI_DB::get_setting( 'mmi_xchange_web_assets_enabled', false ) ) {
            return;
        }
        self::start_web_assets_run( 'cron' );
    }

    public static function web_assets_enabled(): bool {
        return class_exists( 'MMI_DB' ) && (bool) \MMI_DB::get_setting( 'mmi_xchange_web_assets_enabled', false );
    }

    /** The run in progress: { run_id, trigger, started, touched, since, vendors, offset, parts, records, failed }. */
    public static function web_assets_run(): ?array {
        $run = json_decode( (string) MMI_Settings::get( self::WEB_ASSETS_RUN_KEY, '' ), true );
        return is_array( $run ) ? $run : null;
    }

    public static function web_assets_run_active(): bool {
        $run = self::web_assets_run();
        return $run !== null && time() - (int) ( $run['touched'] ?? 0 ) < self::WEB_ASSETS_RUN_STALE;
    }

    /**
     * Queues a web-assets pull. Returns at once; the requests happen in the
     * batches.
     *
     * @return array{started:bool, reason?:string}
     */
    public static function start_web_assets_run( string $trigger ): array {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return [ 'started' => false, 'reason' => 'unavailable' ];
        }
        if ( self::web_assets_run_active() ) {
            return [ 'started' => false, 'reason' => 'running' ];
        }
        $stale = self::web_assets_run();
        if ( $stale ) {
            MMI_Logger::warn( 'XChange web-assets run ' . $stale['run_id'] . ' stopped partway; discarding it and starting over.', [], 'xchange', 'MMI_Xchange_Vendors' );
            self::clear_web_assets_run( $stale );
        }

        $vendors = self::web_assets_vendor_ids();
        if ( ! $vendors ) {
            MMI_Logger::error( 'XChange web-assets run not started: no vendor list (XchangeVendors.json is missing or empty).', [], 'xchange', 'MMI_Xchange_Vendors' );
            return [ 'started' => false, 'reason' => 'no_vendors' ];
        }

        // Incremental (`since`) only when there is a full file to merge
        // into — the API's own advice is "call it once and cache it".
        $path  = self::web_assets_path();
        $since = (int) MMI_Settings::get( self::WEB_ASSETS_SINCE_KEY, 0 );
        $run   = [
            'run_id'  => wp_generate_password( 10, false ),
            'trigger' => $trigger,
            'started' => time(),
            'touched' => time(),
            'since'   => ( $since > 0 && file_exists( $path ) && filesize( $path ) > 1024 ) ? $since : null,
            'vendors' => $vendors,
            'offset'  => 0,
            'parts'   => 0,
            'records' => 0,
            'failed'  => 0,
        ];
        self::save_web_assets_run( $run );
        as_schedule_single_action( time(), self::WEB_ASSETS_BATCH_HOOK, [ $run['run_id'] ], self::WEB_ASSETS_GROUP );
        return [ 'started' => true ];
    }

    /**
     * Action Scheduler callback: up to WEB_ASSETS_BATCH_VENDORS vendors (and
     * never past WEB_ASSETS_BATCH_SECONDS), then the next batch — or, once
     * every vendor is done, the merge.
     */
    public static function run_web_assets_batch( $run_id ): void {
        $run = self::web_assets_run();
        if ( ! $run || $run['run_id'] !== (string) $run_id ) {
            return;
        }
        $total = count( $run['vendors'] );

        try {
            if ( $run['offset'] >= $total ) {
                self::finish_web_assets_run( $run );
                return;
            }

            $client = new MMI_Xchange_API_Client();
            if ( ! $client->has_credentials() ) {
                throw new Exception( 'XChange API credentials are not configured.' );
            }

            $deadline = microtime( true ) + self::WEB_ASSETS_BATCH_SECONDS;
            $records  = [];
            $i        = (int) $run['offset'];
            $end      = min( $total, $i + self::WEB_ASSETS_BATCH_VENDORS );
            while ( $i < $end && microtime( true ) < $deadline ) {
                $assets = $client->fetch_web_assets( (string) $run['vendors'][ $i ], $run['since'] );
                $i++;
                if ( is_wp_error( $assets ) ) {
                    $run['failed']++;
                    continue;
                }
                foreach ( (array) $assets as $product ) {
                    if ( isset( $product['sku'] ) && $product['sku'] !== '' ) {
                        $records[ (string) $product['sku'] ] = self::web_asset_record( $product );
                    }
                }
            }

            if ( $records ) {
                $run['parts']++;
                $part = self::web_assets_part_path( $run, $run['parts'] );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_put_contents
                if ( file_put_contents( $part, wp_json_encode( array_values( $records ) ) ) === false ) {
                    throw new Exception( 'Could not write ' . basename( $part ) );
                }
                $run['records'] += count( $records );
            }
            $run['offset']  = $i;
            $run['touched'] = time();
            self::save_web_assets_run( $run );
            as_schedule_single_action( time() + 1, self::WEB_ASSETS_BATCH_HOOK, [ $run['run_id'] ], self::WEB_ASSETS_GROUP );
        } catch ( Throwable $e ) {
            MMI_Logger::error( 'XChange web-assets run failed: ' . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Vendors' );
            self::clear_web_assets_run( $run );
        }
    }

    /**
     * Merges the run's part files into the existing file — never starts from
     * empty: an incremental pull only returns changed products, so replacing
     * the file with just those would drop every other SKU — then swaps it in
     * with one rename.
     */
    private static function finish_web_assets_run( array $run ): void {
        $total = count( $run['vendors'] );
        if ( $total > 0 && $run['failed'] >= $total ) {
            MMI_Logger::error( "XChange web-assets run failed: all {$total} vendor requests failed; file left as it was.", [], 'xchange', 'MMI_Xchange_Vendors' );
            self::clear_web_assets_run( $run );
            return;
        }

        $path    = self::web_assets_path();
        $records = [];
        if ( file_exists( $path ) ) {
            $existing = json_decode( (string) file_get_contents( $path ), true );
            foreach ( (array) ( $existing['web_assets'] ?? [] ) as $row ) {
                if ( isset( $row['sku'] ) && $row['sku'] !== '' ) {
                    $records[ (string) $row['sku'] ] = $row;
                }
            }
        }
        for ( $n = 1; $n <= (int) $run['parts']; $n++ ) {
            $part_path = self::web_assets_part_path( $run, $n );
            $part      = file_exists( $part_path ) ? json_decode( (string) file_get_contents( $part_path ), true ) : [];
            foreach ( (array) $part as $row ) {
                if ( isset( $row['sku'] ) ) {
                    $records[ (string) $row['sku'] ] = $row;
                }
            }
        }

        $tmp = $path . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_put_contents
        $ok = file_put_contents( $tmp, wp_json_encode( [
            'generated_at' => current_time( 'mysql' ),
            'web_assets'   => array_values( $records ),
        ], JSON_PRETTY_PRINT ) ) !== false && rename( $tmp, $path );
        if ( ! $ok ) {
            MMI_Logger::error( 'XChange web-assets run failed: could not write ' . basename( $path ), [], 'xchange', 'MMI_Xchange_Vendors' );
            self::clear_web_assets_run( $run );
            return;
        }

        // `since` = when this run started, so nothing changed mid-run is missed.
        MMI_Settings::set( self::WEB_ASSETS_SINCE_KEY, (string) $run['started'] );
        MMI_Settings::set( self::WEB_ASSETS_LAST_SUCCESS_KEY, time() );
        MMI_Logger::info( sprintf(
            'XChange web-assets JSON written: %d SKU(s), %d changed this run, %d of %d vendor requests failed, %ds.',
            count( $records ), (int) $run['records'], (int) $run['failed'], $total, time() - (int) $run['started']
        ), [], 'xchange', 'MMI_Xchange_Vendors' );
        self::clear_web_assets_run( $run );
    }

    private static function web_asset_record( array $product ): array {
        return [
            'sku'              => (string) $product['sku'],
            'images'           => array_values( self::flatten( '', (array) ( $product['images'] ?? [] ) ) ),
            'long_description' => isset( $product['long_description'] )
                ? self::parse_long_description( $product['long_description'] )
                : '',
            // Passed through verbatim so mmi-data-pipeline's Field Resolver
            // can address into them with its [key=value]/[N] syntax.
            'features'         => $product['features']     ?? null,
            'requirements'     => $product['requirements'] ?? null,
            'videos'           => $product['videos']       ?? null,
            'licensing'        => $product['licensing']    ?? null,
            'platforms'        => $product['platforms']    ?? null,
        ];
    }

    /** Vendor IDs from the pipeline's own vendors file — no extra API call. */
    private static function web_assets_vendor_ids(): array {
        $file = trailingslashit( mmi_shared_lib_json_dir() ) . 'XchangeVendors.json';
        $data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
        $ids  = [];
        foreach ( (array) ( $data['vendors'] ?? [] ) as $vendor ) {
            if ( ! empty( $vendor['vendor_id'] ) ) {
                $ids[] = (string) $vendor['vendor_id'];
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function web_assets_path(): string {
        return trailingslashit( mmi_shared_lib_json_dir() ) . 'xchange-web-assets.json';
    }

    private static function web_assets_part_path( array $run, int $n ): string {
        return trailingslashit( mmi_shared_lib_json_dir() ) . 'xchange-web-assets.part-' . sanitize_key( $run['run_id'] ) . '-' . $n . '.json';
    }

    private static function save_web_assets_run( array $run ): void {
        MMI_Settings::set( self::WEB_ASSETS_RUN_KEY, wp_json_encode( $run ) );
    }

    private static function clear_web_assets_run( array $run ): void {
        for ( $n = 1; $n <= (int) ( $run['parts'] ?? 0 ); $n++ ) {
            $part = self::web_assets_part_path( $run, $n );
            if ( file_exists( $part ) ) {
                wp_delete_file( $part );
            }
        }
        MMI_Settings::delete( self::WEB_ASSETS_RUN_KEY );
    }

    /* ── Media import ─────────────────────────────────────────────────────── */

    /**
     * Queues a background image import (AS-dispatched) and resets progress —
     * called from the AJAX layer (MMI_Xchange_Ajax::import_media()) and from
     * MMI_Xchange_Account::maybe_run_onboarding(). Async so the caller's
     * request returns instantly; the actual vendor/image loop runs moments
     * later via run_import_media().
     */
    public static function queue_import_media( ?string $vendor_id ): void {
        // "All vendors" (null) has to go through the batched, one-vendor-per-tick
        // path below — confirmed live, 2026-09-18: a single all-106-vendor
        // Action Scheduler action reliably hits AS's own 300-second in-progress
        // watchdog ("action was in-progress for at least 300 seconds without
        // completing and has been marked as failed") long before the throttled
        // per-vendor fetch loop alone (~21+ minutes) can finish, let alone the
        // actual image downloads on top. A single named vendor is one throttled
        // fetch, always well inside 300s — that path is unchanged.
        if ( $vendor_id === null ) {
            self::queue_import_media_all_vendors();
            return;
        }

        self::set_import_media_progress( 'queued', __( 'Queued…', 'mmi-xchange-integration' ), 2 );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_unschedule_all_actions( 'mmi_xchange_import_media', [ $vendor_id ], MMI_Xchange_Order_Sync::AS_GROUP );
            as_schedule_single_action( time() + 3, 'mmi_xchange_import_media', [ $vendor_id ], MMI_Xchange_Order_Sync::AS_GROUP );
        } else {
            wp_schedule_single_event( time() + 3, 'mmi_xchange_import_media', [ $vendor_id ] );
        }
    }

    /**
     * Kicks off the batched all-vendors media import: resolves the vendor list
     * once (a single, cheap fetch_vendors() call — not the throttled per-vendor
     * fetch_web_assets() loop), then hands off to MMI_Batch_Watchdog_Trait's
     * batch_start() — see that trait's own docblock for why this is one
     * vendor per Action Scheduler tick rather than one action for the whole
     * catalog.
     */
    private static function queue_import_media_all_vendors(): void {
        self::set_import_media_progress( 'queued', __( 'Queued…', 'mmi-xchange-integration' ), 2 );

        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            self::set_import_media_progress( 'error', '', 0, __( 'XChange API credentials are not configured.', 'mmi-xchange-integration' ) );
            return;
        }

        $vendors = $client->fetch_vendors();
        if ( is_wp_error( $vendors ) ) {
            self::set_import_media_progress( 'error', '', 0, $vendors->get_error_message() );
            return;
        }

        $vendor_ids = [];
        foreach ( (array) ( $vendors['vendors'] ?? [] ) as $vendor ) {
            $vid = (string) ( $vendor['vendor_id'] ?? '' );
            if ( $vid !== '' ) {
                $vendor_ids[] = $vid;
            }
        }

        self::set_import_media_progress( 'running', __( 'Fetching vendor image assets…', 'mmi-xchange-integration' ), 5 );
        self::batch_start( self::MEDIA_IMPORT_BATCH_KEY, $vendor_ids, self::MEDIA_IMPORT_BATCH_TICK_HOOK, self::MEDIA_IMPORT_BATCH_WATCHDOG_HOOK );
    }

    /* ── MMI_Batch_Watchdog_Trait contract ────────────────────────────────── */

    /**
     * Processes one vendor. $item is a vendor_id here (the trait is generic
     * about what an "item" is).
     */
    protected static function batch_process_item( string $batch_key, $item, int $cursor ): array {
        $total = self::batch_total_items( $batch_key );
        $pct   = 5 + (int) round( ( $cursor / max( 1, $total ) ) * 90 );
        self::set_import_media_progress(
            'running',
            sprintf(
                /* translators: 1: vendors processed so far, 2: total vendors */
                __( 'Importing vendor %1$d of %2$d…', 'mmi-xchange-integration' ),
                $cursor + 1,
                $total
            ),
            $pct
        );

        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            throw new Exception( 'XChange API credentials are not configured.' );
        }

        $media_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'product_images/';
        if ( ! is_dir( $media_path ) && ! wp_mkdir_p( $media_path ) ) {
            throw new Exception( 'Failed to create image directory: ' . $media_path );
        }

        $url_to_attachment = [];
        return self::do_import_media_for_vendor( $client, (string) $item, $media_path, $url_to_attachment );
    }

    protected static function batch_empty_totals( string $batch_key ): array {
        return self::empty_media_import_totals();
    }

    protected static function batch_on_complete( string $batch_key, array $totals ): void {
        MMI_Settings::set( self::IMPORT_MEDIA_STATE_KEY, wp_json_encode( array_merge(
            $totals,
            [ 'synced_at' => current_time( 'mysql' ) ]
        ) ) );

        $message = sprintf(
            /* translators: 1: images imported, 2: images attached to a product */
            __( '%1$d new image(s) imported, %2$d attached to a matching product.', 'mmi-xchange-integration' ),
            $totals['image_import'],
            $totals['image_attached']
        );
        if ( ! empty( $totals['image_download_failed'] ) ) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of images that failed to download */
                __( '%d image(s) failed to download — see xchange log.', 'mmi-xchange-integration' ),
                $totals['image_download_failed']
            );
        }

        self::set_import_media_progress( 'complete', __( 'Done', 'mmi-xchange-integration' ), 100, $message );
        MMI_Logger::info( 'XChange media import (batched, all vendors) complete', $totals, 'xchange', 'MMI_Xchange_Vendors' );
    }

    protected static function batch_hook_group( string $batch_key ): string {
        return MMI_Xchange_Order_Sync::AS_GROUP;
    }

    /**
     * @return array{status:string,step:string,pct:int,message:string}
     */
    public static function get_import_media_progress(): array {
        $raw     = MMI_Settings::get( self::IMPORT_MEDIA_PROGRESS_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded )
            ? wp_parse_args( $decoded, [ 'status' => 'idle', 'step' => '', 'pct' => 0, 'message' => '' ] )
            : [ 'status' => 'idle', 'step' => '', 'pct' => 0, 'message' => '' ];
    }

    /**
     * @param string $status 'queued' | 'running' | 'complete' | 'error'
     */
    private static function set_import_media_progress( string $status, string $step = '', int $pct = 0, string $message = '' ): void {
        MMI_Settings::set( self::IMPORT_MEDIA_PROGRESS_KEY, wp_json_encode( [
            'status'     => $status,
            'step'       => $step,
            'pct'        => $pct,
            'message'    => $message,
            'updated_at' => current_time( 'mysql' ),
        ] ) );
    }

    /**
     * @return array{synced_at:?string,product_import:int,image_import:int,image_existing:int,image_attached:int}
     */
    public static function get_import_media_state(): array {
        $defaults = [
            'synced_at'              => null,
            'product_import'         => 0,
            'image_import'           => 0,
            'image_existing'         => 0,
            'image_attached'         => 0,
            'image_download_failed'  => 0,
            'image_no_product_match' => 0,
        ];
        $raw     = MMI_Settings::get( self::IMPORT_MEDIA_STATE_KEY );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? wp_parse_args( $decoded, $defaults ) : $defaults;
    }

    /**
     * Action Scheduler job target (AGENTS.md Rule 9: this used to run inline
     * inside the admin-post handler with set_time_limit(0), tying up a
     * web-facing PHP-FPM worker for the whole all-vendors download). Vendor
     * ID of `null` means "all vendors".
     */
    public static function run_import_media( ?string $vendor_id = null ): void {
        try {
            self::set_import_media_progress( 'running', __( 'Fetching vendor image assets…', 'mmi-xchange-integration' ), 20 );
            $result = self::do_import_media( $vendor_id );

            MMI_Settings::set( self::IMPORT_MEDIA_STATE_KEY, wp_json_encode( array_merge(
                $result,
                [ 'synced_at' => current_time( 'mysql' ) ]
            ) ) );

            $message = sprintf(
                /* translators: 1: images imported, 2: images attached to a product */
                __( '%1$d new image(s) imported, %2$d attached to a matching product.', 'mmi-xchange-integration' ),
                $result['image_import'],
                $result['image_attached']
            );
            if ( ! empty( $result['image_download_failed'] ) ) {
                $message .= ' ' . sprintf(
                    /* translators: %d: number of images that failed to download */
                    __( '%d image(s) failed to download — see xchange log.', 'mmi-xchange-integration' ),
                    $result['image_download_failed']
                );
            }

            self::set_import_media_progress( 'complete', __( 'Done', 'mmi-xchange-integration' ), 100, $message );

            MMI_Logger::info( 'XChange media import complete', $result, 'xchange', 'MMI_Xchange_Vendors' );
        } catch ( Exception $e ) {
            self::set_import_media_progress( 'error', '', 0, $e->getMessage() );
            MMI_Logger::error( 'XChange media import failed: ' . $e->getMessage(), [], 'xchange', 'MMI_Xchange_Vendors' );
        }
    }


    /**
     * @throws Exception
     */
    private static function do_import_media( ?string $vendor_id ): array {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            throw new Exception( 'XChange API credentials are not configured.' );
        }

        $media_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'product_images/';
        if ( ! is_dir( $media_path ) && ! wp_mkdir_p( $media_path ) ) {
            throw new Exception( 'Failed to create image directory: ' . $media_path );
        }

        $vendor_ids = [];
        if ( $vendor_id === null ) {
            $vendors = $client->fetch_vendors();
            if ( is_wp_error( $vendors ) ) {
                throw new Exception( $vendors->get_error_message() );
            }
            foreach ( (array) ( $vendors['vendors'] ?? [] ) as $vendor ) {
                $vendor_ids[] = (string) ( $vendor['vendor_id'] ?? '' );
            }
        } else {
            $vendor_ids[] = $vendor_id;
        }

        $totals = self::empty_media_import_totals();

        // Maps a source URL to the attachment ID already created for it during
        // THIS run, so a URL repeated under a different flattened key (or on a
        // different product, e.g. a shared vendor asset) downloads once and
        // reuses the same attachment instead of creating a duplicate for every
        // key/product that happens to reference it.
        $url_to_attachment = [];

        foreach ( $vendor_ids as $vid ) {
            $totals = self::merge_media_import_totals(
                $totals,
                self::do_import_media_for_vendor( $client, $vid, $media_path, $url_to_attachment )
            );
        }

        if ( $totals['image_skipped_non_product'] > 0 ) {
            MMI_Logger::info(
                "XChange media import skipped {$totals['image_skipped_non_product']} non-product asset(s) (logo/brand/banner/watermark key match).",
                [],
                'xchange',
                'MMI_Xchange_Vendors'
            );
        }

        if ( $totals['image_no_product_match'] > 0 ) {
            MMI_Logger::info(
                "XChange media import skipped {$totals['image_no_product_match']} SKU(s) with image assets but no matching WooCommerce product.",
                [],
                'xchange',
                'MMI_Xchange_Vendors'
            );
        }

        return [
            'product_import'         => $totals['product_import'],
            'image_import'           => $totals['image_import'],
            'image_existing'         => $totals['image_existing'],
            'image_attached'         => $totals['image_attached'],
            'image_download_failed'  => $totals['image_download_failed'],
            'image_no_product_match' => $totals['image_no_product_match'],
        ];
    }

    /**
     * @return array{product_import:int,image_import:int,image_existing:int,image_attached:int,image_download_failed:int,image_skipped_non_product:int,image_no_product_match:int}
     */
    private static function empty_media_import_totals(): array {
        return [
            'product_import'            => 0,
            'image_import'               => 0,
            'image_existing'             => 0,
            'image_attached'             => 0,
            'image_download_failed'      => 0,
            'image_skipped_non_product'  => 0,
            'image_no_product_match'     => 0,
        ];
    }

    private static function merge_media_import_totals( array $totals, array $delta ): array {
        foreach ( $totals as $key => $value ) {
            $totals[ $key ] = $value + ( $delta[ $key ] ?? 0 );
        }
        return $totals;
    }

    /**
     * One vendor's worth of do_import_media()'s original inner loop — pulled
     * out as its own method so both the single-vendor path (do_import_media(),
     * always fast enough to run inline) and the batched all-vendors path
     * (batch_process_item(), via MMI_Batch_Watchdog_Trait — one vendor per
     * Action Scheduler tick) share one implementation instead of two copies
     * that could quietly drift apart.
     *
     * $url_to_attachment is passed by reference so the same-URL dedup already
     * described on do_import_media() still works across vendors within a
     * single request; the batched path passes a fresh array per tick since a
     * dedup that only lives within one 12-second-throttled vendor fetch isn't
     * worth persisting across ticks for the rare shared-asset case.
     *
     * @return array{product_import:int,image_import:int,image_existing:int,image_attached:int,image_download_failed:int,image_skipped_non_product:int,image_no_product_match:int}
     */
    private static function do_import_media_for_vendor( MMI_Xchange_API_Client $client, string $vid, string $media_path, array &$url_to_attachment ): array {
        $totals = self::empty_media_import_totals();

        $assets = $client->fetch_web_assets( $vid );
        if ( is_wp_error( $assets ) ) {
            return $totals;
        }

        foreach ( (array) $assets as $product ) {
            if ( ! isset( $product['sku'] ) ) {
                continue;
            }
            $sku        = $product['sku'];
            $product_id = self::find_product_id_by_xchange_sku( $sku );

            if ( ! $product_id ) {
                // No matching WC product yet — skip downloading/inserting
                // any image for this SKU entirely rather than creating a
                // media-library attachment that never gets attached to
                // anything, with no admin visibility that it happened.
                $totals['image_no_product_match']++;
                continue;
            }

            $has_image = false;

            foreach ( self::flatten( '', $product['images'] ?? [] ) as $flattened_key => $url ) {
                $decision = self::classify_image_key( $flattened_key, $url );

                if ( $decision['status'] === 'skip_logo' ) {
                    $totals['image_skipped_non_product']++;
                    continue;
                }
                if ( $decision['status'] === 'skip_bad_url' ) {
                    continue;
                }

                $file_type = $decision['file_type'];
                $file_name = $sku . '_' . $flattened_key . $file_type;

                // SKU and image key come from XChange's feed and become a
                // filesystem path below — never let either escape
                // product_images/.
                if ( strpbrk( $file_name, "/\\\0" ) !== false || strpos( $file_name, '..' ) !== false ) {
                    $totals['image_download_failed']++;
                    continue;
                }

                // Same URL already resolved to an attachment this run (a
                // different key/product referencing the identical asset) —
                // reuse it rather than re-downloading and re-inserting.
                if ( isset( $url_to_attachment[ $url ] ) ) {
                    $attach_id = $url_to_attachment[ $url ];
                    if ( ! file_exists( $media_path . $file_name ) ) {
                        $existing_path = get_attached_file( $attach_id );
                        if ( $existing_path && file_exists( $existing_path ) ) {
                            copy( $existing_path, $media_path . $file_name );
                        }
                    }
                    $totals['image_existing']++;
                    $has_image = true;
                } elseif ( file_exists( $media_path . $file_name ) ) {
                    // Exact sku+key pair already downloaded on a prior run.
                    $totals['image_existing']++;
                    $has_image = true;
                    continue;
                } else {
                    // Cross-run dedup: an attachment for this exact URL may
                    // already exist in the media library (e.g. a shared
                    // asset imported for a different product previously).
                    $attach_id = self::find_attachment_by_url( $url );

                    if ( ! $attach_id ) {
                        $blob = $client->download_asset( $url );
                        if ( $blob === null ) {
                            // One retry with a short jittered backoff
                            // before giving up — this runs inside an
                            // Action Scheduler job, not a WP-Cron
                            // callback, so a bounded pause here is safe
                            // (AGENTS.md Rule 2).
                            usleep( (int) ( 300000 + wp_rand( 0, 200000 ) ) );
                            $blob = $client->download_asset( $url );
                        }

                        if ( $blob === null ) {
                            $totals['image_download_failed']++;
                            MMI_Logger::warn(
                                "XChange image download failed for SKU {$sku} (vendor {$vid}): {$url}",
                                [ 'sku' => $sku, 'vendor' => $vid, 'url' => $url ],
                                'xchange',
                                'MMI_Xchange_Vendors'
                            );
                            continue;
                        }

                        file_put_contents( $media_path . $file_name, $blob );

                        $wp_filetype = wp_check_filetype( basename( $file_name ), null );
                        $attach_id   = wp_insert_attachment( [
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title'     => $file_name,
                            'post_content'   => '',
                            'post_status'    => 'inherit',
                        ], 'product_images/' . $file_name );

                        MMI_Media_Helper::tag_source_url( (int) $attach_id, $url );

                        $attach_path = get_attached_file( $attach_id );
                        $attach_data = wp_generate_attachment_metadata( $attach_id, $attach_path );
                        wp_update_attachment_metadata( $attach_id, $attach_data );

                        $totals['image_import']++;
                    } else {
                        if ( ! file_exists( $media_path . $file_name ) ) {
                            $existing_path = get_attached_file( $attach_id );
                            if ( $existing_path && file_exists( $existing_path ) ) {
                                copy( $existing_path, $media_path . $file_name );
                            }
                        }
                        $totals['image_existing']++;
                    }

                    $url_to_attachment[ $url ] = $attach_id;
                    $has_image = true;
                }

                // $product_id is guaranteed here — unmatched SKUs are
                // skipped before this loop even starts (see above).
                self::attach_image_to_product( $product_id, $attach_id );
                $totals['image_attached']++;
            }

            if ( $has_image ) {
                $totals['product_import']++;
            }
        }

        return $totals;
    }

    /* ── Image readiness (Vendors tab visibility tools) ───────────────────────
     * Two read-only reporting layers, deliberately kept separate because
     * their cost profile is completely different:
     *
     *  - get_local_image_readiness(): zero live Xchange API calls. Reads the
     *    already-cached xchange-products.json (mmi-data-pipeline's own
     *    catalog fetch — a local file, not a fresh request) joined against
     *    this site's WooCommerce products via two batched postmeta queries.
     *    Safe to call on every Vendors-tab page load; cheap enough that it
     *    is NOT dispatched through Action Scheduler.
     *
     *  - analyze_vendor_images(): one live, throttled Web Asset API call for
     *    a SINGLE vendor (same cost as one "Import Images" click for that
     *    vendor) — read-only dry run of do_import_media() sharing its exact
     *    classify_image_key() decision, so preview and real import can never
     *    silently disagree about what will happen. Safe to run synchronously
     *    from an AJAX handler for one vendor; deliberately NOT offered for
     *    "_all" vendors, since that would be dozens of throttled requests and
     *    belongs behind the same Action Scheduler dispatch+poll pattern as
     *    the real bulk import, not a synchronous preview button.
     */

    /**
     * @return array{
     *   vendor_totals: array<string, array{sku_count:int, matched_products:int, with_image:int, missing_image:int, overlap_missing:int, overlap_vendor_names:string[]}>,
     *   totals: array{vendors_with_products:int, sku_count:int, matched_products:int, with_image:int, missing_image:int, already_imported_attachments:int},
     *   products_json_missing: bool,
     * }
     */
    public static function get_local_image_readiness(): array {
        $empty_totals = [
            'vendors_with_products'        => 0,
            'sku_count'                    => 0,
            'matched_products'             => 0,
            'with_image'                   => 0,
            'missing_image'                => 0,
            'already_imported_attachments' => 0,
        ];

        // Same resolver as web_assets_path() above — mmi-data-pipeline's
        // own Xchange updater (MMI_Pipeline_Xchange_Updater) writes the
        // cached feed here via the identical mmi_shared_lib_json_dir() call.
        $json_dir  = mmi_shared_lib_json_dir();
        $json_path = trailingslashit( $json_dir ) . 'xchange-products.json';

        if ( ! file_exists( $json_path ) ) {
            return [ 'vendor_totals' => [], 'totals' => $empty_totals, 'products_json_missing' => true ];
        }

        // Keyed by filemtime so a fresh catalog fetch naturally invalidates the
        // cache — mirrors mmi-data-pipeline's own mmi_pipeline_feed_{supplier}_{filemtime}
        // transient-cache idiom for this exact >1MB feed file (13MB as of this
        // writing), rather than reading/decoding it on every Vendors-tab load.
        $cache_key = 'mmi_xchange_local_img_readiness_' . filemtime( $json_path );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $data     = json_decode( (string) file_get_contents( $json_path ), true );
        $products = is_array( $data['products'] ?? null ) ? $data['products'] : [];

        // by_brand_vendor tracks, per case-insensitive brand, which vendor
        // accounts carry SKUs under it — the same brand (e.g. "Arturia") can
        // be sold both directly by its own Xchange vendor account AND resold
        // through an unrelated reseller's vendor account (e.g. Hal Leonard),
        // and each vendor account gets its own separate row/badge below. A
        // WooCommerce "Product Brand" filter spans every vendor account at
        // once, so it can show a materially higher "missing images" count
        // than any single vendor's own row — not a miscount on either side,
        // just two different, both-correct scopes. See the September 7, 2026
        // Incident History entry ("Vendor Image-Readiness Badge Undercounted
        // a Brand Sold Through More Than One Xchange Vendor"). Used below to
        // surface that overlap directly on the affected vendor rows, rather
        // than leaving it to look like an inconsistent count.
        $by_vendor        = [];
        $by_brand_vendor  = [];
        $vendor_brand_keys = [];
        $all_skus         = [];
        foreach ( $products as $p ) {
            $sku    = (string) ( $p['sku'] ?? '' );
            $vendor = trim( (string) ( $p['vendor'] ?? '' ) );
            $brand  = trim( (string) ( $p['brand'] ?? '' ) );
            if ( $sku === '' || $vendor === '' ) {
                continue;
            }
            $key               = strtoupper( $vendor );
            $by_vendor[ $key ][] = $sku;
            $all_skus[]          = $sku;

            if ( $brand !== '' ) {
                $brand_key                            = strtolower( $brand );
                $by_brand_vendor[ $brand_key ][ $key ][] = $sku;
                $vendor_brand_keys[ $key ][ $brand_key ] = true;
            }
        }

        global $wpdb;

        // Batch-resolve SKU → product ID in chunks of 1000, never one query per
        // SKU (Scalability rule) — same two meta keys find_product_id_by_xchange_sku()
        // checks, just resolved for every SKU in the feed at once instead of on demand.
        $sku_to_product = [];
        $meta_keys      = [ '_mmi_supplier_sku_xchange', XCHANGE_INTERNAL_SKU_NAME ];
        $unique_skus    = array_unique( $all_skus );
        foreach ( array_chunk( $unique_skus, 1000 ) as $chunk ) {
            $sku_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $rows             = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_value AS sku, post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ({$key_placeholders}) AND meta_value IN ({$sku_placeholders})",
                array_merge( $meta_keys, $chunk )
            ) );
            foreach ( $rows as $row ) {
                $sku_to_product[ $row->sku ] = (int) $row->post_id;
            }
        }

        // Fallback: a real, live gap (confirmed 2026-09-04 — 2,706 products
        // store-wide) has a WooCommerce `_sku` exactly matching its feed SKU
        // but was never backfilled with the `_mmi_supplier_sku_xchange`/
        // XCHANGE_INTERNAL_SKU_NAME meta the primary lookup above depends on.
        // Without this, those products are invisible to matched_products
        // entirely — not just uncounted as "missing an image," but silently
        // absent from the denominator too, which is what made this vendor's
        // "2 missing" badge undercount 13 real missing-featured-image
        // products down to 2. Batched the same way as the primary lookup,
        // and only queried for SKUs the primary lookup didn't already resolve.
        $unresolved_skus = array_values( array_diff( $unique_skus, array_keys( $sku_to_product ) ) );
        foreach ( array_chunk( $unresolved_skus, 1000 ) as $chunk ) {
            $sku_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $rows             = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_value AS sku, post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_sku' AND meta_value IN ({$sku_placeholders})",
                $chunk
            ) );
            foreach ( $rows as $row ) {
                $sku_to_product[ $row->sku ] = (int) $row->post_id;
            }
        }

        // Batch-resolve product ID → "has any image" (featured or gallery),
        // again in chunks, never per-product.
        $has_image   = [];
        $product_ids = array_values( array_unique( $sku_to_product ) );
        foreach ( array_chunk( $product_ids, 1000 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            $thumbs = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value != '' AND post_id IN ({$placeholders})",
                $chunk
            ) );
            foreach ( $thumbs as $row ) {
                $has_image[ (int) $row->post_id ] = true;
            }

            $galleries = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value != '' AND post_id IN ({$placeholders})",
                $chunk
            ) );
            foreach ( $galleries as $row ) {
                $has_image[ (int) $row->post_id ] = true;
            }
        }

        // Per (brand, vendor) missing-image counts — only computed for brands
        // that actually span more than one vendor account, so this stays
        // cheap regardless of catalog size. Feeds the cross-vendor overlap
        // fields attached to each vendor row below.
        $brand_vendor_missing = [];
        foreach ( $by_brand_vendor as $brand_key => $vendor_skus ) {
            if ( count( $vendor_skus ) < 2 ) {
                continue;
            }
            foreach ( $vendor_skus as $vendor_key => $skus ) {
                $missing = 0;
                foreach ( $skus as $sku ) {
                    if ( ! isset( $sku_to_product[ $sku ] ) ) {
                        continue;
                    }
                    if ( ! isset( $has_image[ $sku_to_product[ $sku ] ] ) ) {
                        $missing++;
                    }
                }
                $brand_vendor_missing[ $brand_key ][ $vendor_key ] = $missing;
            }
        }

        $vendor_totals = [];
        $totals        = $empty_totals;

        foreach ( $by_vendor as $key => $skus ) {
            $matched    = 0;
            $with_image = 0;
            foreach ( $skus as $sku ) {
                if ( ! isset( $sku_to_product[ $sku ] ) ) {
                    continue;
                }
                $matched++;
                if ( isset( $has_image[ $sku_to_product[ $sku ] ] ) ) {
                    $with_image++;
                }
            }

            // Cross-vendor brand overlap: this vendor's own "missing" count
            // above only ever reflects its own catalog rows. If one of its
            // brands is also sold through a different vendor account, that
            // other account's missing images are real but invisible here —
            // surfaced explicitly rather than left to look like an undercount
            // whenever cross-checked against a brand-wide (not vendor-wide)
            // view such as WooCommerce's own Product Brand filter.
            $overlap_missing = 0;
            $overlap_vendors = [];
            foreach ( array_keys( $vendor_brand_keys[ $key ] ?? [] ) as $brand_key ) {
                if ( ! isset( $brand_vendor_missing[ $brand_key ] ) ) {
                    continue; // brand not shared with any other vendor
                }
                foreach ( $brand_vendor_missing[ $brand_key ] as $other_key => $missing_there ) {
                    if ( $other_key === $key || $missing_there <= 0 ) {
                        continue;
                    }
                    $overlap_missing += $missing_there;
                    $overlap_vendors[ $other_key ] = true;
                }
            }

            $vendor_totals[ $key ] = [
                'sku_count'            => count( $skus ),
                'matched_products'     => $matched,
                'with_image'           => $with_image,
                'missing_image'        => $matched - $with_image,
                'overlap_missing'      => $overlap_missing,
                'overlap_vendor_names' => array_keys( $overlap_vendors ),
            ];

            $totals['vendors_with_products']++;
            $totals['sku_count']        += count( $skus );
            $totals['matched_products'] += $matched;
            $totals['with_image']       += $with_image;
            $totals['missing_image']    += ( $matched - $with_image );
        }

        $totals['already_imported_attachments'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::SOURCE_URL_META_KEY
        ) );

        $result = [
            'vendor_totals'         => $vendor_totals,
            'totals'                => $totals,
            'products_json_missing' => false,
        ];

        set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Read-only dry run of do_import_media() for exactly one vendor — one
     * throttled fetch_web_assets() call, no downloads, no writes, no attach
     * calls. Powers the Vendors tab's "Preview" button.
     *
     * @return array{rows:array, counts:array}|WP_Error
     */
    public static function analyze_vendor_images( string $vendor_id ) {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return new WP_Error( 'xchange_no_credentials', __( 'XChange API credentials are not configured.', 'mmi-xchange-integration' ) );
        }

        $assets = $client->fetch_web_assets( $vendor_id );
        if ( is_wp_error( $assets ) ) {
            return $assets;
        }

        $rows   = [];
        $counts = [ 'new' => 0, 'already_imported' => 0, 'skip_logo' => 0, 'skip_bad_url' => 0, 'no_product_match' => 0 ];

        foreach ( (array) $assets as $product ) {
            if ( ! isset( $product['sku'] ) ) {
                continue;
            }
            $sku        = (string) $product['sku'];
            $product_id = self::find_product_id_by_xchange_sku( $sku );

            if ( ! $product_id ) {
                $counts['no_product_match']++;
            }

            $images = [];
            foreach ( self::flatten( '', $product['images'] ?? [] ) as $flattened_key => $url ) {
                $decision = self::classify_image_key( $flattened_key, $url );

                if ( $decision['status'] !== 'ok' ) {
                    $counts[ $decision['status'] ]++;
                    $images[] = [ 'key' => $flattened_key, 'url' => $url, 'status' => $decision['status'] ];
                    continue;
                }

                $attachment_id = self::find_attachment_by_url( $url );
                $status        = $attachment_id ? 'already_imported' : 'new';
                $counts[ $status ]++;
                $images[] = [ 'key' => $flattened_key, 'url' => $url, 'status' => $status, 'attachment_id' => $attachment_id ];
            }

            $gallery = $product_id ? (string) get_post_meta( $product_id, '_product_image_gallery', true ) : '';

            $rows[] = [
                'sku'           => $sku,
                'product_id'    => $product_id,
                'product_name'  => $product_id ? get_the_title( $product_id ) : null,
                'edit_url'      => $product_id ? get_edit_post_link( $product_id, '' ) : null,
                'has_thumbnail' => $product_id ? (bool) get_post_thumbnail_id( $product_id ) : false,
                'gallery_count' => $gallery !== '' ? count( array_filter( explode( ',', $gallery ) ) ) : 0,
                'images'        => $images,
                'new_count'     => count( array_filter( $images, static fn( $i ) => $i['status'] === 'new' ) ),
            ];
        }

        return [ 'rows' => $rows, 'counts' => $counts ];
    }

    /**
     * Skip-decision for one flattened image key/URL pair — the "will this be
     * treated as a real product photo" rule, shared by the real import
     * (do_import_media()) and the read-only preview (analyze_vendor_images())
     * so the two can never silently disagree about what counts as a logo or
     * a bad URL. Deliberately does NOT call find_attachment_by_url() here —
     * the real run only needs that lookup in its own cross-run-dedup branch
     * (after the cheaper on-disk exact-file check has already missed), so
     * folding it into this method would add an unconditional extra query to
     * every already-downloaded image on a re-run. The preview path calls
     * find_attachment_by_url() itself, separately, since "already imported"
     * is exactly what it wants to answer.
     *
     * @return array{status:string, file_type?:string}
     *   status: 'skip_logo' | 'skip_bad_url' | 'ok'
     */
    private static function classify_image_key( string $flattened_key, string $url ): array {
        // Schema-aware check first: the Web Asset API's `images` object
        // documents a distinct top-level `logo` bucket, which flatten()
        // preserves as the flattened key's own first segment. Checking
        // structural position catches every real logo asset without relying
        // on name-matching, which can misfire on a legitimately-named
        // product image that happens to contain a word like "banner".
        $top_level_bucket = explode( '_', $flattened_key, 2 )[0];
        if ( $top_level_bucket === 'logo' ) {
            return [ 'status' => 'skip_logo' ];
        }

        // Fallback for any vendor whose feed doesn't cleanly separate a
        // `logo` bucket from real product photos.
        if ( preg_match( self::NON_PRODUCT_IMAGE_KEY_PATTERN, $flattened_key ) ) {
            return [ 'status' => 'skip_logo' ];
        }

        $file_type = self::url_file_extension( $url );
        if ( $file_type === null ) {
            return [ 'status' => 'skip_bad_url' ];
        }

        return [ 'status' => 'ok', 'file_type' => $file_type ];
    }

    /**
     * Extracts a URL's file extension for local filename construction,
     * ignoring any query string or fragment. Returns null when no plausible
     * image/video extension can be determined (skip the asset rather than
     * saving it under a garbage filename).
     */
    private static function url_file_extension( string $url ): ?string {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) || strrpos( $path, '.' ) === false ) {
            return null;
        }

        $file_type = substr( $path, strrpos( $path, '.' ) );
        if ( strlen( $file_type ) > 5 ) {
            return null;
        }

        // Only extensions WordPress itself allows as media uploads — the
        // file is written under uploads/, so an executable/script extension
        // (.php, .phar, .html …) from a vendor URL must never be saved.
        $check = wp_check_filetype( 'asset' . $file_type );
        return empty( $check['ext'] ) ? null : $file_type;
    }

    /**
     * Look up an existing media-library attachment by its exact source URL —
     * avoids re-downloading/re-inserting an asset already imported on an
     * earlier run, for a different product referencing the same shared
     * vendor asset, or (now that the lookup is shared) by
     * mmi-data-pipeline's own generic image-field handling for the same URL.
     *
     * Delegates to MMI_Media_Helper (mmi-hub) rather than querying
     * SOURCE_URL_META_KEY directly — deliberately NOT a `guid = %s` lookup:
     * wp_insert_attachment()'s guid is the local uploaded file's URL, never
     * the remote source URL, so that comparison can never match (the same
     * bug mmi-data-pipeline's own get_attachment_by_url() used to have,
     * fixed the same way).
     */
    private static function find_attachment_by_url( string $url ): ?int {
        return MMI_Media_Helper::find_by_source_url( $url );
    }

    /**
     * Resolve a WooCommerce product ID for an Xchange SKU. Checks the
     * pipeline's own supplier-sku meta first (the source of truth for
     * products actually created/updated by mmi-data-pipeline's Xchange
     * feed), falling back to this plugin's legacy per-product override meta.
     *
     * Public: also called from MMI_Xchange_Order_Sync::preview_product() to
     * resolve a clickable WC product-edit link for the Orders tab's detail
     * table — one shared lookup, not a second copy of the same query.
     */
    public static function find_product_id_by_xchange_sku( string $sku ): ?int {
        global $wpdb;

        foreach ( [ '_mmi_supplier_sku_xchange', XCHANGE_INTERNAL_SKU_NAME ] as $meta_key ) {
            $product_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $meta_key,
                $sku
            ) );

            if ( $product_id ) {
                return (int) $product_id;
            }
        }

        return null;
    }

    /**
     * Set as featured image if the product has none yet, otherwise append to
     * the gallery — mirrors mmi-data-pipeline's set_product_images() logic
     * so behavior is consistent regardless of which system attached an image.
     *
     * Tags every image it touches as owned by this process (only meaningful
     * once mmi-data-pipeline is also active — see MMI_Media_Helper::OWNER_META_KEY)
     * so that plugin's own gallery write can recognize an entry it doesn't
     * own and preserve it instead of silently discarding it on overwrite.
     */
    private static function attach_image_to_product( int $product_id, int $attach_id ): void {
        MMI_Media_Helper::set_owner( $attach_id, 'xchange_vendor_import' );

        if ( ! get_post_thumbnail_id( $product_id ) ) {
            set_post_thumbnail( $product_id, $attach_id );
            return;
        }

        $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        $ids     = $gallery !== '' ? array_filter( explode( ',', $gallery ) ) : [];

        if ( ! in_array( (string) $attach_id, $ids, true ) ) {
            $ids[] = (string) $attach_id;
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $ids ) );
        }
    }

    /* ── Recursive helpers (ported as-is from xchangemarket) ──────────────── */

    private static function stringify( int $indents, $input ): string {
        if ( is_array( $input ) ) {
            $output        = '';
            $is_associative = is_string( array_key_first( $input ) );
            foreach ( $input as $key => $value ) {
                $string_value = self::stringify( $indents + 2, $value );
                if ( trim( $string_value ) === '' ) {
                    continue;
                }
                $output .= $is_associative
                    ? "\n" . str_repeat( ' ', $indents ) . $key . ': ' . $string_value
                    : $string_value . ', ';
            }
            return rtrim( $output, ', ' );
        }

        if ( is_bool( $input ) ) {
            return $input ? 'Yes' : 'No';
        }

        return (string) $input;
    }

    private static function flatten( string $prefix, array $input ): array {
        $result = [];
        foreach ( $input as $key => $value ) {
            $full_key = $prefix === '' ? (string) $key : $prefix . '_' . $key;
            if ( is_array( $value ) ) {
                $result = array_merge( $result, self::flatten( $full_key, $value ) );
            } elseif ( is_string( $value ) ) {
                $result[ $full_key ] = $value;
            }
        }
        return $result;
    }

    private static function parse_long_description( $input ): string {
        if ( ! is_array( $input ) ) {
            return self::stringify( 0, $input );
        }

        $output = '';
        foreach ( $input as $text_block ) {
            if ( ! is_array( $text_block ) ) {
                return self::stringify( 0, $input );
            }
            foreach ( $text_block as $format => $content ) {
                if ( $format === 'list' ) {
                    foreach ( (array) $content as $text ) {
                        if ( trim( $text ) !== '' ) {
                            $output .= trim( $text ) . "\n\n";
                        }
                    }
                } elseif ( in_array( $format, [ 'text', 'paragraph' ], true ) ) {
                    if ( trim( (string) $content ) !== '' ) {
                        $output .= trim( $content ) . "\n\n";
                    }
                } else {
                    return self::stringify( 0, $input );
                }
            }
        }

        return trim( $output );
    }
}
