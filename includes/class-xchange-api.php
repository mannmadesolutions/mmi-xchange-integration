<?php
/**
 * MMI_Xchange_API
 *
 * Public facade for other MMI plugins (namely mmi-reverb-integration) to
 * interact with Xchange without containing any Xchange business logic
 * themselves — mirrors the `mmi_reverb_pipeline_active()` gating convention
 * already used for pipeline-gated Reverb features. Callers should guard
 * with `MMI_Xchange_API::is_active()` (or `class_exists('MMI_Xchange_API')`)
 * before calling anything else, since this plugin may not be installed.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_API {

    public static function is_active(): bool {
        return class_exists( 'MMI_Xchange_API_Client' )
            && ( ! function_exists( 'mmi_is_licensed' ) || mmi_is_licensed( 'mmi-xchange-integration' ) );
    }

    /**
     * Credential accessor for other MMI plugins' own read-only Xchange
     * fetches (e.g. mmi-data-pipeline's catalog updater) — see
     * mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md §10. Callers must gate with
     * is_active() first; this method itself only reports whether
     * credentials are configured, not whether the plugin is licensed.
     *
     * @return array{api_key:string, token_key:string, api_url:string}|null
     *   Null when credentials aren't configured — callers must check.
     */
    public static function get_credentials(): ?array {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return null;
        }
        $ctx = $client->get_auth_context();
        if ( is_wp_error( $ctx ) ) {
            return null;
        }
        return [
            'api_key'   => $ctx['api_key'],
            'token_key' => $ctx['token'],
            'api_url'   => $ctx['api_url'],
        ];
    }

    /* ── Orders ────────────────────────────────────────────────────────────── */

    /**
     * @return array{orders:array, best_match_po:?string, synced_at:?string}
     */
    public static function get_order_snapshot( string $sku_filter = '', bool $force_refresh = false ): array {
        return MMI_Xchange_Order_Sync::get_snapshot( $sku_filter, $force_refresh );
    }

    public static function fetch_license( string $po ): ?string {
        return MMI_Xchange_Order_Sync::call_license_api( $po );
    }

    public static function queue_sync(): void {
        MMI_Xchange_Order_Sync::queue_sync();
    }

    public static function get_sync_state(): array {
        return MMI_Xchange_Order_Sync::get_sync_state();
    }

    /**
     * Real-money B2B purchase. Every call — whichever plugin or button it
     * came from — writes one order.place audit record (who, which order,
     * SKU/qty, engine mode, PO, outcome). $audit_context is optional
     * caller context for that record: order_id, reverb_order_id, trigger.
     * Callers remain responsible for their own capability/nonce checks.
     *
     * @return array{po_number?:string, auth?:string, licenses?:array<int,array{sku:string,license_key:string,download_url:string,support_url:string}>, error?:string, code?:string, manual_url?:string}
     */
    public static function place_order( string $sku, int $qty = 1, array $audit_context = [] ): array {
        $result   = self::place_order_unaudited( $sku, $qty );
        $order_id = absint( $audit_context['order_id'] ?? 0 );

        if ( function_exists( 'mmi_xchange_audit' ) ) {
            mmi_xchange_audit( 'order.place', [
                'object_type' => $order_id > 0 ? 'order' : 'xchange_po',
                'object_id'   => $order_id > 0 ? $order_id : (string) ( $result['po_number'] ?? '' ),
                'outcome'     => isset( $result['error'] ) ? 'failure' : 'success',
                'details'     => [
                    'sku'             => $sku,
                    'qty'             => $qty,
                    'engine'          => MMI_Settings::get( 'mmi_xchange_production_mode' ) === 'yes' ? 'live' : 'test',
                    'po'              => (string) ( $result['po_number'] ?? '' ),
                    'trigger'         => sanitize_key( (string) ( $audit_context['trigger'] ?? 'api' ) ),
                    'reverb_order_id' => (string) ( $audit_context['reverb_order_id'] ?? '' ),
                    'error'           => isset( $result['error'] ) ? (string) $result['error'] : '',
                ],
            ] );
        }

        return $result;
    }

    /**
     * place_order()'s actual work — kept separate only so the audit record
     * above covers every early return below.
     */
    private static function place_order_unaudited( string $sku, int $qty ): array {
        $client = new MMI_Xchange_API_Client();
        if ( ! $client->has_credentials() ) {
            return [ 'error' => __( 'XChange API credentials are not configured.', 'mmi-xchange-integration' ) ];
        }

        $result = $client->place_order( $sku, $qty );

        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_message();
            // guard_production_only()'s own message ("...disabled on this
            // environment...to prevent accidental live purchases") contains
            // the word "disabled" too, and was being misclassified as this
            // exact XChange account restriction below — which is how a pure
            // local bug (MMI_Environment missing post-mmi-hub-elimination,
            // now fixed) spent weeks looking like an XChange-side account
            // problem instead of a bug in this codebase. Checking the error
            // CODE first means any future local guard failure reports its
            // own real reason instead of being swallowed into this bucket.
            if ( $result->get_error_code() === 'xchange_non_production_environment' ) {
                MMI_Logger::error( "place_order({$sku}, qty {$qty}) blocked locally: {$message}", [], 'xchange', 'MMI_Xchange_API' );
                return [ 'error' => $message ];
            }
            if ( stripos( $message, 'E033' ) !== false || stripos( $message, 'disabled' ) !== false ) {
                MMI_Xchange_Account::record_issue( $message );
                MMI_Logger::error( "place_order({$sku}, qty {$qty}) blocked: {$message}", [], 'xchange', 'MMI_Xchange_API' );
                return [
                    'error'      => __( 'Your XChange account is not enabled for direct API order placement. Contact XChange support to enable API ordering, or place the order manually.', 'mmi-xchange-integration' ),
                    'code'       => 'api_disabled',
                    // The actual reseller portal (matches MMI_Xchange_Web_Session::WEB_URL) —
                    // not the API host's marketing domain, which is a different site.
                    'manual_url' => 'https://xchangeb2b.com',
                ];
            }
            MMI_Logger::error( "place_order({$sku}, qty {$qty}) failed: {$message}", [], 'xchange', 'MMI_Xchange_API' );
            return [ 'error' => $message ];
        }

        if ( isset( $result['error'] ) ) {
            MMI_Xchange_Account::record_issue( (string) $result['error'] );
            // MMI_Xchange_Account::record_issue() only ever keeps the single
            // most recent failure (it overwrites one MMI_Settings value) —
            // logging here too is what makes failure *history* (which SKUs,
            // how often) answerable at all instead of only ever seeing the
            // latest one. Confirmed missing 2026-08-24 while investigating a
            // "no price defined" error that turned out to be engine=test
            // having no price data for any non-test-vendor SKU — see
            // AGENTS.md's Incident History and xchange-api.md.
            MMI_Logger::error( "place_order({$sku}, qty {$qty}, engine={$client->api_mode()}) failed: " . $result['error'], [], 'xchange', 'MMI_Xchange_API' );
            return [ 'error' => (string) $result['error'] ];
        }

        // A successful placement is proof the account itself is fine —
        // clear any previously-recorded issue so the MannMade dashboard's
        // "Needs attention" panel doesn't keep showing a stale failure
        // (found live: it was still displaying guard_production_only()'s
        // old "disabled on this environment" message well after that guard
        // was fixed and a real order had already gone through).
        MMI_Xchange_Account::clear_issue();

        // po_number now comes back reliably (the client fills it in — see
        // MMI_Xchange_API_Client::place_order()). auth does not: the
        // documented success response is {"message":"success","licenses":[
        // {sku,license_number,download_path,support_contact},...]}, which
        // has no "auth"/"auth_number" field at all — this mapping predates
        // the client-level request-shape fix and was never corrected to
        // match. Order placement was believed blocked for this account
        // (E033) but that diagnosis was itself wrong — see AGENTS.md's
        // changelog, 2026-09-19 — reshaping this response to the real
        // success schema is still a separate, UI-facing follow-up.
        //
        // licenses: PUT /orders/ returns them in the same response
        // ({sku, license_number, download_path, support_contact} per line —
        // see xchange-api.md). Previously discarded, forcing a separate
        // fetch_order_document() round-trip (which can fall through to a
        // session-ending CCSA portal login) for data already in hand.
        $licenses = [];
        foreach ( (array) ( $result['licenses'] ?? [] ) as $license ) {
            if ( ! is_array( $license ) ) {
                continue;
            }
            $licenses[] = [
                'sku'          => (string) ( $license['sku'] ?? $sku ),
                'license_key'  => (string) ( $license['license_number'] ?? '' ),
                'download_url' => (string) ( $license['download_path'] ?? '' ),
                'support_url'  => (string) ( $license['support_contact'] ?? '' ),
            ];
        }

        return [
            'po_number' => (string) ( $result['po_number'] ?? $result['transaction_number'] ?? '' ),
            'auth'      => (string) ( $result['auth'] ?? $result['auth_number'] ?? '' ),
            'licenses'  => $licenses,
        ];
    }

    /* ── Product / catalog ─────────────────────────────────────────────────── */

    public static function get_product_xchange_sku( int $product_id ): string {
        $sku = get_post_meta( $product_id, '_mmi_supplier_sku_xchange', true );
        return $sku !== '' ? $sku : (string) get_post_meta( $product_id, 'sku_xchange', true );
    }

    /* ── COGS ──────────────────────────────────────────────────────────────── */

    public static function cogs_lookup( string $sku ): ?array {
        return MMI_Xchange_COGS::lookup( $sku );
    }

    /**
     * Batch form of cogs_lookup() — for admin table renders resolving a COGS
     * figure per row without an N+1 query per row. See MMI_Xchange_COGS::lookup_batch().
     *
     * @param string[] $skus
     * @return array<string, array{dealer_price:float, product:string, sku:string}>
     */
    public static function cogs_lookup_batch( array $skus ): array {
        return MMI_Xchange_COGS::lookup_batch( $skus );
    }

    public static function cogs_status(): array {
        return MMI_Xchange_COGS::backfill_status();
    }

    public static function cogs_backfill_batch( int $offset = 0 ): array {
        return MMI_Xchange_COGS::run_backfill_batch( $offset );
    }

    public static function auto_cogs_on_import( string $reverb_order_id, array $order_data ): void {
        MMI_Xchange_COGS::auto_cogs_on_import( $reverb_order_id, $order_data );
    }
}
