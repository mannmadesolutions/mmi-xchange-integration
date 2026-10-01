<?php
/**
 * MMI_Xchange_Web_Session
 *
 * Xchange's REST API has no order-search endpoint, and the xchangeb2b.com
 * web portal itself is unusable for admin work here (the UI is bad and it
 * kicks out concurrent logins — including the admin's own browser session).
 * This class drives ONE server-side session against that web portal —
 * logging in with the reseller's own credentials, caching the PHPSESSID
 * briefly, and scraping the pages that list historical orders (CCSA,
 * Invoice History).
 *
 * Because every call here risks evicting the admin's own logged-in browser
 * session, this class is ONLY ever invoked from
 * MMI_Xchange_Order_Sync::run_full_sync() — a manual, explicitly-triggered
 * "Full Sync" action — never automatically. Routine/automatic syncing uses
 * the REST API exclusively (MMI_Xchange_API_Client::fetch_documents()).
 *
 * Ported from mmi-reverb-integration's software handler.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Web_Session {

    const WEB_URL             = 'https://xchangeb2b.com/XCH/V4.1d_TC';
    const SESSION_TRANSIENT   = 'mmi_xchange_phpsessid';
    const SESSION_TTL         = MINUTE_IN_SECONDS;

    /** Canonical column-name lookup for both scraped order tables. */
    const HEADER_MAP = [
        'po#' => 'po', 'po' => 'po', 'pon' => 'po', 'po number' => 'po',
        'sku' => 'sku', 'our sku' => 'sku',
        'product' => 'product',
        'auth#' => 'auth', 'auth' => 'auth',
        'license#' => 'license', 'license' => 'license', 'license code' => 'license',
        'download_path' => 'download', 'download' => 'download',
        'support' => 'support',
        'date' => 'date', 'order_date' => 'date',
        'vendor' => 'vendor_name', 'vendor name' => 'vendor_name', 'vendor id' => 'vendor_id', 'vendorid' => 'vendor_id',
        'price' => 'price', 'dealer price' => 'price', 'dealer cost' => 'price', 'cost' => 'price', 'unit price' => 'price', 'amount' => 'price',
        'promo' => 'promo_id', 'promo id' => 'promo_id', 'promotion' => 'promo_id',
    ];

    /* ── Login / session ──────────────────────────────────────────────────── */

    /**
     * @return string|WP_Error PHPSESSID.
     */
    public function get_session() {
        $cached = get_transient( self::SESSION_TRANSIENT );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $login_id  = (string) MMI_Settings::get( 'mmi_xchange_login_id' );
        $login_pwd = (string) MMI_Settings::get( 'mmi_xchange_login_pwd' );

        if ( $login_id === '' || $login_pwd === '' ) {
            return new WP_Error( 'xchange_no_web_credentials', __( 'XChange web-portal login (mmi_xchange_login_id / mmi_xchange_login_pwd) is not configured.', 'mmi-xchange-integration' ) );
        }

        MMI_API_Throttler::throttle( 'xchange' );

        $response = wp_remote_post( self::WEB_URL . '/tr_logincheck.php', [
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'        => http_build_query( [
                'reseller_ID' => $login_id,
                'ct_PW'       => $login_pwd,
                'do'          => 'contact',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            MMI_Logger::warn( 'XChange web-portal login request failed: ' . $response->get_error_message(), [], 'xchange', 'MMI_Xchange_Web_Session' );
            return $response;
        }

        $set_cookie = wp_remote_retrieve_header( $response, 'set-cookie' );
        $cookie_str = is_array( $set_cookie ) ? implode( '; ', $set_cookie ) : (string) $set_cookie;

        if ( ! preg_match( '/PHPSESSID=([^;]+)/', $cookie_str, $m ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            MMI_Logger::warn( "XChange web-portal login returned HTTP {$code} but no PHPSESSID — check mmi_xchange_login_id / mmi_xchange_login_pwd", [], 'xchange', 'MMI_Xchange_Web_Session' );
            return new WP_Error( 'xchange_no_session', __( 'XChange web-portal login did not return a session.', 'mmi-xchange-integration' ) );
        }

        $phpsessid = $m[1];
        set_transient( self::SESSION_TRANSIENT, $phpsessid, self::SESSION_TTL );

        return $phpsessid;
    }

    /* ── CCSA (Customer Committed Stock Area) ────────────────────────────── */

    /**
     * @return array|WP_Error Normalized order rows.
     */
    public function fetch_ccsa_items() {
        $phpsessid = $this->get_session();
        if ( is_wp_error( $phpsessid ) ) {
            return $phpsessid;
        }

        MMI_API_Throttler::throttle( 'xchange' );

        $response = wp_remote_get( self::WEB_URL . '/tr_ccsa.php', [
            'timeout' => 25,
            'headers' => [ 'Cookie' => 'PHPSESSID=' . $phpsessid ],
        ] );

        if ( is_wp_error( $response ) ) {
            MMI_Logger::warn( 'XChange CCSA request failed: ' . $response->get_error_message(), [], 'xchange', 'MMI_Xchange_Web_Session' );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );

        $decoded = json_decode( $body, true );
        if ( is_array( $decoded ) ) {
            $rows = $decoded['items'] ?? $decoded['orders'] ?? $decoded['data'] ?? ( array_is_list( $decoded ) ? $decoded : null );
            if ( is_array( $rows ) ) {
                return array_map( fn( $row ) => MMI_Xchange_Order_Sync::normalize_order( $row, 'ccsa' ), $rows );
            }
        }

        if ( stripos( $body, '<table' ) === false ) {
            return [];
        }

        return $this->parse_html_table( $body, 'ccsa' );
    }

    /* ── Invoice History ──────────────────────────────────────────────────── */

    /**
     * The search form at this URL is a POST-only form (`is_post=1`, hidden
     * field), not a GET-with-querystring endpoint — confirmed via a captured
     * real browser request (2026-08-14, see mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md
     * §4). The previous GET-based version of this method returned the raw
     * search-form page instead of results every time, silently, since it
     * never errored — just never found the data table it expected. Fixed
     * field names (`fromDate`/`toDate`, not `from`/`to`) and date format
     * (`Y-m-d`, not `m/d/Y`) to match what the real form actually submits.
     *
     * @return array|WP_Error Normalized order rows.
     */
    public function fetch_invoice_history_items( int $days = 180 ) {
        $cache_key = 'mmi_xchange_invoice_history_' . $days;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $phpsessid = $this->get_session();
        if ( is_wp_error( $phpsessid ) ) {
            return $phpsessid;
        }

        $from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $to   = gmdate( 'Y-m-d' );

        MMI_API_Throttler::throttle( 'xchange' );

        $response = wp_remote_post(
            self::WEB_URL . '/tr_invoice_history.php',
            [
                'timeout' => 25,
                'headers' => [
                    'Cookie'       => 'PHPSESSID=' . $phpsessid,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => http_build_query( [
                    'is_post'  => '1',
                    'fromDate' => $from,
                    'toDate'   => $to,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            MMI_Logger::warn( 'XChange invoice history fetch failed: ' . $response->get_error_message(), [], 'xchange', 'MMI_Xchange_Web_Session' );
            return $response;
        }

        $rows = $this->parse_html_table( wp_remote_retrieve_body( $response ), 'invoice' );

        $ttl = $days <= 90 ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
        set_transient( $cache_key, $rows, $ttl );

        return $rows;
    }

    /* ── Shared HTML table parsing ────────────────────────────────────────── */

    /**
     * Both CCSA and Invoice History render their order list as an HTML
     * <table> inside a larger layout table. We XPath directly to the
     * inner table's own rows/cells to avoid picking up the surrounding
     * page chrome.
     *
     * @return array Normalized rows.
     */
    private function parse_html_table( string $html, string $source ): array {
        $raw_rows = $this->parse_generic_html_table( $html, self::HEADER_MAP );

        $rows = [];
        foreach ( $raw_rows as $raw ) {
            if ( ( $raw['po'] ?? '' ) === '' ) {
                continue;
            }
            $rows[] = MMI_Xchange_Order_Sync::normalize_order( $raw, $source );
        }
        return $rows;
    }

    /**
     * Generic scraped-table parser shared by CCSA and Invoice History — both
     * render their list as an HTML <table> inside a larger layout table. We
     * XPath directly to the inner table's own rows/cells to avoid picking up
     * the surrounding page chrome, detect the header row via the given
     * canonical-name lookup, then map every subsequent row's cells to
     * canonical keys.
     *
     * @param array<string,string> $header_map Lowercased header text => canonical key.
     * @return array<int,array<string,string>> Raw rows keyed by canonical name (un-normalized).
     */
    private function parse_generic_html_table( string $html, array $header_map ): array {
        if ( trim( $html ) === '' ) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
        libxml_clear_errors();

        $xpath  = new DOMXPath( $dom );
        $tables = $xpath->query( '//table' );

        $col_map = null;
        $rows    = [];

        foreach ( $tables as $table ) {
            $tr_nodes = $xpath->query( './tr | ./tbody/tr | ./thead/tr', $table );

            foreach ( $tr_nodes as $tr ) {
                $cell_nodes = $xpath->query( './th | ./td', $tr );
                $cells      = [];
                foreach ( $cell_nodes as $cell ) {
                    $cells[] = trim( preg_replace( '/\s+/', ' ', $cell->textContent ) );
                }

                if ( count( $cells ) < 3 ) {
                    continue;
                }

                if ( $col_map === null ) {
                    $candidate = $this->detect_header_row( $cells, $header_map );
                    if ( $candidate !== null ) {
                        $col_map = $candidate;
                        continue;
                    }
                    continue; // no header found yet — skip stray rows
                }

                $raw = [];
                foreach ( $col_map as $index => $canonical ) {
                    $raw[ $canonical ] = $cells[ $index ] ?? '';
                }

                $rows[] = $raw;
            }

            if ( $col_map !== null ) {
                break; // found the real data table — stop scanning others
            }
        }

        return $rows;
    }

    /**
     * @param string[]              $cells
     * @param array<string,string>  $header_map Lowercased header text => canonical key.
     * @return array<int,string>|null  index => canonical column name, or null if not a header row.
     */
    private function detect_header_row( array $cells, array $header_map ): ?array {
        $map     = [];
        $matches = 0;

        foreach ( $cells as $index => $cell ) {
            $normalized = strtolower( trim( $cell ) );
            if ( isset( $header_map[ $normalized ] ) ) {
                $map[ $index ] = $header_map[ $normalized ];
                $matches++;
            }
        }

        return $matches >= 3 ? $map : null;
    }
}
