<?php
/**
 * MMI_Xchange_API_Client
 *
 * Single owner of every outbound HTTP call to Xchange's REST API
 * (xchangemarketb2b.com/api/v2/athabasca/) — reserve/finalize/void (storefront
 * checkout), product/vendor listing, license/document lookup, and B2B order
 * placement (admin console). Ported from xchangemarket.php's `xchange_connect`
 * class and mmi-reverb-integration's `get_xchange_api_auth()`, which used the
 * identical token+Basic-Auth flow against the same API — this is now the one
 * place that logic lives.
 *
 * Every request is throttled via MMI_API_Throttler::throttle('xchange')
 * (min 12s gap — see mmi-hub/includes/class-api-throttler.php) and penalized
 * on the documented "too frequent" error, per this project's API Rate
 * Limiting rules.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_API_Client {

    const RESERVE_PATH  = '/orders/reserve/';
    const FINALIZE_PATH = '/orders/finalize/';
    const VOID_PATH     = '/orders/void/';
    const ORDERS_PATH   = '/orders/';
    const PRODUCTS_PATH = '/products/';
    const PROMOTIONS_PATH = '/promotions/';
    const VENDORS_PATH  = '/vendors/';
    const DOCUMENTS_PATH = '/documents';
    const WEBHOOKS_PATH  = '/webhooks/';

    const TOO_FREQUENT_NEEDLE = 'too frequent';

    /**
     * The Web Asset API's own doc ("XCHANGE Web Assets") states a single
     * fixed URL for every account — unlike api_url/token_url, this is not
     * genuinely account-specific, even though it's stored per-account in
     * the vault (xchange-assets-url / mmi_xchange_assets_url). Used only as
     * a last-resort fallback below, after both the direct setting and the
     * legacy self-heal come back empty — never overrides a real configured
     * value, in case XChange ever does serve a different URL for some
     * accounts. Confirmed 2026-08-23: this install's own stored value
     * already matches this constant exactly.
     */
    const DEFAULT_ASSETS_URL = 'https://xchangeb2b.com/XCH/V4.1d_TC/assets/details.php';

    private ?string $token_url  = null;
    private ?string $token_key  = null;
    private ?string $api_url    = null;
    private ?string $api_key    = null;
    private ?string $assets_url = null;

    public function __construct() {
        $this->token_url  = rtrim( (string) MMI_Settings::get( 'mmi_xchange_token_url' ), '/' );
        $this->token_key  = (string) MMI_Settings::get( 'mmi_xchange_token_key' );
        $this->api_url    = rtrim( (string) MMI_Settings::get( 'mmi_xchange_api_url' ), '/' );
        $this->api_key    = (string) MMI_Settings::get( 'mmi_xchange_api_key' );
        $this->assets_url = rtrim( (string) MMI_Settings::get( 'mmi_xchange_assets_url' ), '/' );

        if ( $this->assets_url === '' ) {
            $this->assets_url = self::self_heal_assets_url();
        }

        // Last-resort fallback — the vault field and the legacy self-heal
        // both came back empty (e.g. a fresh install, or a migration that
        // never ran). Since this URL isn't actually account-specific,
        // falling back to the documented value keeps fetch_web_assets()
        // working instead of failing on missing config for a value that
        // doesn't need per-account entry at all.
        if ( $this->assets_url === '' ) {
            $this->assets_url = self::DEFAULT_ASSETS_URL;
        }
    }

    /**
     * MMI_Xchange_Settings_Migration::KEY_MAP originally checked only a
     * wp_options key (from the old xchangemarket plugin) for
     * mmi_xchange_assets_url, never the legacy wp_mmi hyphenated row every
     * other credential key falls back to — so on any install where the
     * wp_options value was never set (this one included) the one-time
     * migration silently left mmi_xchange_assets_url empty, breaking
     * fetch_web_assets() entirely. The KEY_MAP is fixed for future installs,
     * but that migration is one-time and already ran here, so this
     * self-heals the already-migrated case at runtime instead of requiring
     * a manual DB fix or re-triggering the migration. See
     * mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md.
     */
    private static function self_heal_assets_url(): string {
        $legacy = MMI_Settings::get( 'xchange-assets-url', '' );

        if ( $legacy === null || $legacy === '' ) {
            return '';
        }

        $resolved = rtrim( (string) $legacy, '/' );
        MMI_Settings::set( 'mmi_xchange_assets_url', $resolved );

        return $resolved;
    }

    /**
     * True when the minimum credentials needed for API calls are configured.
     */
    public function has_credentials(): bool {
        return $this->token_url !== '' && $this->token_key !== '' && $this->api_url !== '' && $this->api_key !== '';
    }

    public function api_mode(): string {
        return MMI_Settings::get( 'mmi_xchange_production_mode' ) === 'yes' ? 'live' : 'test';
    }

    private function status_string(): string {
        global $wp_version;
        $wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
        return 'WP' . $wp_version . ' W' . $wc_version . ' X' . MMI_XCHANGE_VERSION;
    }

    /* ── Token / auth ─────────────────────────────────────────────────────── */

    /**
     * Timed tokens are deprecated per Xchange's own API docs (Reseller API
     * 2.2r6, confirmed live 2026-08-14: "The system no longer requires the
     * call to get a timed token. But some value is still required in the
     * AUTH header for reference."). Confirmed empirically the same day
     * against /version/ and /vendors/ — a real API key with an empty,
     * garbage, or genuinely-fetched-token second value all returned
     * identical 200 responses; only the API key half of the Basic-Auth
     * header is actually validated. So: no HTTP round-trip anymore —
     * token_key is used directly as the Basic-Auth password. This halves
     * real request volume against Xchange's per-call-type rate limit, since
     * every operation previously cost 2 requests (token fetch + the actual
     * call) instead of 1, and removes the prior cache-window bug (token
     * cached for 10s while Xchange's docs state a 5s token lifespan).
     *
     * @return string|WP_Error
     */
    private function get_token() {
        if ( ! $this->has_credentials() ) {
            return new WP_Error( 'xchange_no_credentials', __( 'XChange API credentials are not configured.', 'mmi-xchange-integration' ) );
        }

        return $this->token_key;
    }

    /**
     * Return an auth context array (mirrors the shape mmi-reverb-integration's
     * software handler used to build) for callers that need the raw pieces
     * (e.g. the web-session bridge needs api_key/token_url, not just the
     * finished Authorization header).
     *
     * @return array{auth:string, api_url:string, api_key:string, token:string}|WP_Error
     */
    public function get_auth_context() {
        $token = $this->get_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return [
            'auth'    => 'Basic ' . base64_encode( $this->api_key . ':' . $token ),
            'api_url' => $this->api_url,
            'api_key' => $this->api_key,
            'token'   => $token,
        ];
    }

    /* ── Low-level throttled request ──────────────────────────────────────── */

    /**
     * @param string      $method GET|POST|PUT
     * @param string      $url    Absolute URL.
     * @param array|null  $body   Encoded to JSON if non-null.
     * @param bool        $use_basic_auth
     * @return array|WP_Error Decoded JSON body (assoc, lower-cased keys) or WP_Error.
     */
    public function request( string $method, string $url, ?array $body = null, bool $use_basic_auth = true, int $timeout = 20 ) {
        $args = [
            'method'   => $method,
            'timeout'  => $timeout,
            'sslverify'=> true,
            'headers'  => [],
        ];

        if ( $use_basic_auth ) {
            $ctx = $this->get_auth_context();
            if ( is_wp_error( $ctx ) ) {
                return $ctx;
            }
            $args['headers']['Authorization'] = $ctx['auth'];
        }

        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['headers']['Accept']       = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }

        MMI_API_Throttler::throttle( 'xchange' );
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        if ( stripos( $raw_body, self::TOO_FREQUENT_NEEDLE ) !== false ) {
            MMI_API_Throttler::penalize( 'xchange' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'xchange_http_error', sprintf( 'XChange API returned HTTP %d.', $code ), [ 'body' => $raw_body ] );
        }

        MMI_API_Throttler::clear_penalty( 'xchange' );

        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'xchange_bad_json', __( 'XChange API returned an unparsable response.', 'mmi-xchange-integration' ), [ 'body' => $raw_body ] );
        }

        $lower = [];
        foreach ( $decoded as $key => $value ) {
            $lower[ strtolower( (string) $key ) ] = $value;
        }
        return $lower;
    }

    /**
     * Environment gate for every real-money call (reserve/finalize/void/
     * place_order) — read-only calls (fetch_products(), fetch_vendors(),
     * etc.) deliberately do NOT go through this, since those are safe and
     * useful to run on staging for testing catalog sync.
     *
     * Added 2026-08-24 after identifying a real historical incident: every
     * staging/dev clone of this site, with this plugin active, would treat
     * itself as production and begin auto-issuing real XChange purchase
     * orders for every open order — this class had no environment
     * awareness at all before this. Deliberately independent of the
     * `mmi_xchange_production_mode` setting: that's a DB row, which clones
     * with the database into any staging/dev refresh, so it can't be
     * trusted as the only safety check — a real hostname/constant check
     * via MMI_Environment is required in addition to it. Fails CLOSED: if
     * MMI_Environment can't even be found, this refuses rather than
     * assuming production, since mmi-hub is a hard dependency and its
     * absence would itself be a broken/unexpected state, not something to
     * paper over by allowing a real charge through.
     *
     * @return WP_Error|null WP_Error if this call must be refused, null if
     *   it's safe to proceed.
     */
    private function guard_production_only(): ?WP_Error {
        if ( ! class_exists( 'MMI_Environment' ) || ! MMI_Environment::is_production() ) {
            $env = class_exists( 'MMI_Environment' ) ? MMI_Environment::get_environment() : 'unknown';
            return new WP_Error(
                'xchange_non_production_environment',
                sprintf(
                    /* translators: %s: detected environment (staging, development, local, unknown) */
                    __( 'XChange order placement is disabled on this environment (%s) to prevent accidental live purchases.', 'mmi-xchange-integration' ),
                    $env
                )
            );
        }
        return null;
    }

    /* ── Transactional (storefront checkout) ─────────────────────────────── */

    /**
     * @param string $po
     * @param array  $lines [['sku'=>..., 'quantity'=>...], ...]
     * @return array|WP_Error
     */
    public function reserve( string $po, array $lines ) {
        $guard = $this->guard_production_only();
        if ( $guard !== null ) {
            return $guard;
        }

        return $this->request( 'PUT', $this->api_url . self::RESERVE_PATH, [
            'engine'     => $this->api_mode(),
            'status'     => $this->status_string(),
            'po_number'  => $po,
            'lines'      => $lines,
        ] );
    }

    /** @return array|WP_Error */
    public function finalize( string $transaction_number ) {
        $guard = $this->guard_production_only();
        if ( $guard !== null ) {
            return $guard;
        }

        return $this->request( 'PUT', $this->api_url . self::FINALIZE_PATH, [
            'engine'             => $this->api_mode(),
            'transaction_number' => $transaction_number,
        ] );
    }

    /** @return array|WP_Error */
    public function void( string $transaction_number ) {
        $guard = $this->guard_production_only();
        if ( $guard !== null ) {
            return $guard;
        }

        return $this->request( 'PUT', $this->api_url . self::VOID_PATH, [
            'engine'             => $this->api_mode(),
            'transaction_number' => $transaction_number,
        ] );
    }

    /* ── Admin console (order search / place / license) ──────────────────── */

    /**
     * Direct order placement (as opposed to reserve()/finalize()'s two-phase
     * storefront checkout). Per Xchange's Reseller API docs this is
     * PUT /orders/ with the same body shape as reserve() — {engine, status,
     * po_number, lines:[{sku,quantity}]} — not the POST-with-{lines:[{sku,qty}]}
     * this method sent previously (confirmed wrong verb + wrong body shape
     * against the live doc pages, see mmi-admin/docs/api-reference/xchange-api.md
     * "Order placement & checkout"). This account is currently blocked from
     * direct API ordering (error E033, see MMI_Xchange_Account), so this path
     * has never been exercised live; fixed to match the documented contract
     * so it's correct the moment that block lifts, not verified against a
     * real successful call.
     *
     * No natural po_number exists at this call site (the public facade,
     * MMI_Xchange_API::place_order(), only ever receives a sku/qty pair from
     * the admin's "Place Order" tab) — auto-generates one when the caller
     * doesn't supply one, and returns it merged into the result so existing
     * callers reading $result['po_number'] keep working.
     *
     * @return array|WP_Error
     */
    public function place_order( string $sku, int $qty = 1, ?string $po = null ) {
        $guard = $this->guard_production_only();
        if ( $guard !== null ) {
            return $guard;
        }

        $po_number = $po ?? ( 'MMI-' . strtoupper( wp_generate_password( 10, false, false ) ) );

        $result = $this->request( 'PUT', $this->api_url . self::ORDERS_PATH, [
            'engine'     => $this->api_mode(),
            'status'     => $this->status_string(),
            'po_number'  => $po_number,
            'lines'      => [ [ 'sku' => $sku, 'quantity' => $qty ] ],
        ] );

        if ( ! is_wp_error( $result ) ) {
            $result['po_number'] = $po_number;
        }

        return $result;
    }

    /**
     * Order/invoice history. Xchange's docs require start_date/end_date
     * (yyyy-mm-dd) — this call previously sent neither, which is the likely
     * root cause of this account only ever showing 2 historical orders (see
     * mmi-admin/docs/api-reference/xchange-api.md "Documents response schema
     * — a likely root-cause finding"). Defaults to a 14-day trailing window
     * when no range is given — generous enough to cover gaps between
     * routine sync runs without attempting a full historical backfill in a
     * single call (that's real follow-up work, not this bug fix — see the
     * doc above for why a wider pull needs its own dispatcher/batch design).
     *
     * @return array|WP_Error
     */
    public function fetch_documents( ?string $start_date = null, ?string $end_date = null ) {
        $end_date   = $end_date ?? gmdate( 'Y-m-d' );
        $start_date = $start_date ?? gmdate( 'Y-m-d', strtotime( '-14 days', strtotime( $end_date ) ) );

        $url = $this->api_url . self::DOCUMENTS_PATH . '/?' . http_build_query( [
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ] );

        return $this->request( 'GET', $url, null, true, 25 );
    }

    /**
     * Single license lookup. Xchange's docs put this at plural
     * /licenses/{id}/, not the singular /license/{id} previously called —
     * confirmed wrong path (see mmi-admin/docs/api-reference/xchange-api.md
     * "Licenses & Subscriptions response schema"). The docs also document a
     * `sku` param that "will ensure unique lookups" and a matching
     * "you must also pass the 'sku' parameter" error for ambiguous id-only
     * calls — no caller of this method currently has a sku to pass (only a
     * PO number), so that error remains a real, accepted possibility for a
     * multi-line PO until a caller can supply one; $sku is wired through as
     * optional so a future caller that has it can pass it.
     *
     * @return array|WP_Error
     */
    public function fetch_license( string $po, ?string $sku = null ) {
        $url = $this->api_url . '/licenses/' . rawurlencode( $po ) . '/';
        if ( $sku !== null && $sku !== '' ) {
            $url .= '?' . http_build_query( [ 'sku' => $sku ] );
        }
        return $this->request( 'GET', $url );
    }

    /**
     * Single document lookup. Xchange's docs put this at plural
     * /documents/{id}/ (not the singular /document/{id} previously called)
     * and require a `type` param (INVOICE or CREDIT-MEMO) — confirmed wrong
     * path and a missing required param (see
     * mmi-admin/docs/api-reference/xchange-api.md "Documents response schema").
     * Which type a given PO/id actually is isn't knowable in advance from
     * this call site, so INVOICE is tried first and CREDIT-MEMO only if that
     * comes back empty/errored — a real API round-trip cost for the
     * CREDIT-MEMO case, but there's no documented way to ask for "either."
     *
     * Whether `:id` is actually the PO number this method receives, or the
     * document_no seen in every sample response, is also not confirmed by
     * the docs — po_number is present as a field on the returned object
     * (consistent with a PO-based lookup working), but this hasn't been
     * verified against a real call. Response is unwrapped from the
     * documented `{"document": {...}}` envelope so existing callers (which
     * expect a bare item, not that wrapper) keep working unchanged.
     *
     * @return array|null|WP_Error Null when neither type has a document for
     *   this id (a genuine "not found," not an error).
     */
    public function fetch_document( string $po, string $type = 'INVOICE' ) {
        $url = $this->api_url . '/documents/' . rawurlencode( $po ) . '/?' . http_build_query( [ 'type' => $type ] );
        $result = $this->request( 'GET', $url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // `$result['document']` is `null` (present, not absent) when this
        // :id has no document of this $type — `?? $result` would silently
        // fall back to the outer {timezone, server_zone, ...} envelope
        // instead, which then gets normalized as if it were the item and
        // produces a fake "found" result with every real field blank. See
        // AGENTS.md's "Merge & Defaults Functions" section on `??` failing
        // to distinguish "key absent" from "key present but null."
        $document = is_array( $result ) ? ( $result['document'] ?? null ) : null;

        if ( $type === 'INVOICE' && ( isset( $result['error'] ) || $document === null ) ) {
            return $this->fetch_document( $po, 'CREDIT-MEMO' );
        }

        return $document;
    }

    /** @return array|WP_Error */
    public function fetch_products() {
        return $this->request( 'GET', $this->api_url . self::PRODUCTS_PATH );
    }

    /**
     * Currently-active promotions only — the `future` param defaults to
     * 'no' per the vendor doc, which is exactly what margin calculations
     * need ("what does this SKU actually cost right now"), not upcoming
     * promotions that haven't started yet. dealer_price on this endpoint's
     * rows is the same non-promotional cost as /products/ — promo_price is
     * the real, separate, lower cost while a promo is running; the two are
     * never conflated on the product record itself.
     *
     * @return array|WP_Error
     */
    public function fetch_promotions() {
        return $this->request( 'GET', $this->api_url . self::PROMOTIONS_PATH );
    }

    /** @return array|WP_Error */
    public function fetch_vendors() {
        return $this->request( 'GET', $this->api_url . self::VENDORS_PATH );
    }

    /* ── Webhooks (mmi-admin/docs/api-reference/xchange-api.md, full spec
       captured 2026-08-28) — REST-only, never touches MMI_Xchange_Web_Session,
       so this carries none of that class's portal-session-eviction risk. ── */

    /**
     * @return array{hook_id:string,event:string,active:bool,registered:string,endpoint:string,secret:string}|WP_Error
     */
    public function register_webhook( string $event, string $endpoint_url ) {
        return $this->request( 'POST', $this->api_url . self::WEBHOOKS_PATH, [
            'engine'   => MMI_Settings::get( 'mmi_xchange_production_mode' ) === 'yes' ? 'live' : 'test',
            'event'    => $event,
            'endpoint' => $endpoint_url,
        ] );
    }

    /** @return array|WP_Error */
    public function list_webhooks() {
        return $this->request( 'GET', $this->api_url . self::WEBHOOKS_PATH );
    }

    /**
     * Web Asset API — different host (xchangeb2b.com), rkey/tkey auth
     * instead of Basic-Auth-with-timed-token. Previously passed the
     * token_url setting (a URL) as `tkey`, ported as-is from the original
     * vendor-supplied code. The official "XCHANGE Web Assets" doc defines
     * `rkey` as "Your Athabasca Reseller key" and `tkey` as "Your Athabasca
     * Tkey" — cross-referenced against the Athabasca intro doc's own
     * definition ("the other is the 'tkey' used to get a timed token"),
     * that's token_key, a private key value, not a URL. Fixed to send
     * token_key. Not live-tested against a real successful call — the old
     * value didn't error loudly either, so there's no regression signal to
     * compare against; if asset fetches start failing where they didn't
     * before, this is the first place to check.
     *
     * $since (optional, Unix timestamp) is the documented incremental-refresh
     * param — "filters the product list to only return products that were
     * updated between now and since." Not previously used anywhere; every
     * call was a full pull against Xchange's own explicit "call it once and
     * cache the results... these are not expected to be updated frequently"
     * guidance. Callers that maintain their own cache (see
     * MMI_Xchange_Vendors::write_web_assets_json()) should pass their last
     * successful fetch time; one-shot/preview callers (CSV export, the
     * media-import job, the Vendors-tab preview) correctly leave this null
     * for a full pull, since they have no cache to top up.
     *
     * @return array|WP_Error
     */
    public function fetch_web_assets( string $vendor_id, ?int $since = null ) {
        if ( $this->assets_url === '' ) {
            return new WP_Error( 'xchange_no_assets_url', __( 'XChange assets URL is not configured.', 'mmi-xchange-integration' ) );
        }

        // Separate throttle key from the main REST API — see
        // MMI_API_Throttler::KNOWN_APIS's 'xchange_webassets' entry for why.
        MMI_API_Throttler::throttle( 'xchange_webassets' );

        $body = [
            'rkey'   => $this->api_key,
            'tkey'   => $this->token_key,
            'vendor' => $vendor_id,
        ];
        if ( $since !== null ) {
            $body['since'] = $since;
        }

        $response = wp_remote_post( $this->assets_url, [
            'timeout' => 25,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'xchange_assets_http_error', sprintf( 'XChange Web Asset API returned HTTP %d.', $code ), [ 'body' => $body ] );
        }

        $decoded = json_decode( $body, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Download a single media asset (image/video) as a binary blob.
     *
     * @return string|null
     */
    public function download_asset( string $url ): ?string {
        // These binaries are hosted on the vendor's own website, never an
        // XChange-owned host — throttled separately from real XChange API
        // calls (see MMI_API_Throttler::KNOWN_APIS's 'xchange_asset_binary').
        MMI_API_Throttler::throttle( 'xchange_asset_binary' );

        // URL comes from XChange's feed (vendor-hosted) — wp_safe_remote_get()
        // refuses non-http(s) schemes and private/loopback hosts (SSRF).
        $response = wp_safe_remote_get( $url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return null;
        }

        return wp_remote_retrieve_body( $response );
    }
}
