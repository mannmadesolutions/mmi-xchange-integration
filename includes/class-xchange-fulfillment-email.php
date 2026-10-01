<?php
/**
 * Xchange Order Fulfillment Email
 *
 * Renders and sends the branded "your software is ready" customer email —
 * the three pieces a customer needs to complete an Xchange purchase: a
 * support contact, a download path, and a license key. Layout/branding is
 * delegated entirely to the shared MMI_Email_Templates::render_customer_notice()
 * (customizable via the mmi-email-customizer admin UI); this class only
 * supplies the Xchange-specific content.
 *
 * Single-product email, sent from the Fulfillment Queue's "Email Customer"
 * modal (one order, one SKU) or the standalone Place Order tab. Several
 * items for the same customer go out as ONE email instead, via
 * MMI_Software_Fulfillment::send_bundle_email() (the queue's "Fulfill
 * together" action) — Reverb's old Software Delivery modal, once a second
 * caller here, was retired unused in mmi-reverb-integration 2.39.0.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Fulfillment_Email {

    // Order-linked send/resend attempts (success and failure) — the only
    // record of whether this email actually left the server, since
    // wp_mail() returning true previously only ever reached MMI_Logger's
    // log file, invisible from the Fulfillment Queue UI where an admin
    // would actually go looking after a customer says they never got it.
    const HISTORY_META_KEY = '_mmi_xchange_fulfillment_email_log';
    const MAX_HISTORY      = 10;

    public static function init(): void {
        add_filter( 'mmi_email_customizer_samples', [ __CLASS__, 'register_email_sample' ] );
    }

    /**
     * Render the fulfillment email HTML.
     *
     * @param array $data {
     *   customer_name?, software_name?, software_logo_url?, license_key?,
     *   download_url?, support_url?, po_number?, auth?, sku?,
     *   our_sku?, vendor_name?, product_url?, brand_url?, price?, currency?
     * }
     * @return string HTML email body, or '' if mmi-hub's template engine isn't available.
     */
    public static function render( array $data ): string {
        if ( ! class_exists( 'MMI_Email_Templates' ) ) {
            return '';
        }

        $customer_name     = trim( (string) ( $data['customer_name'] ?? '' ) ) ?: 'there';
        $software_name     = trim( (string) ( $data['software_name'] ?? '' ) ) ?: 'your software';
        $software_logo_url = trim( (string) ( $data['software_logo_url'] ?? '' ) );
        $license_key       = trim( (string) ( $data['license_key'] ?? '' ) );
        $download_url      = trim( (string) ( $data['download_url'] ?? '' ) );
        $support_url       = trim( (string) ( $data['support_url'] ?? '' ) );
        $po_number         = trim( (string) ( $data['po_number'] ?? '' ) );
        $sku               = trim( (string) ( $data['sku'] ?? '' ) );
        $our_sku           = trim( (string) ( $data['our_sku'] ?? '' ) );
        $vendor_name       = trim( (string) ( $data['vendor_name'] ?? '' ) );
        $price             = (float) ( $data['price'] ?? 0 );
        $currency          = trim( (string) ( $data['currency'] ?? '' ) ) ?: 'USD';
        // Resolved server-side from product_id (see class-xchange-ajax.php) —
        // this class only ever receives already-built URLs/values, never a
        // product ID, so it stays a pure render function with no WC product
        // lookups of its own.
        $product_url   = trim( (string) ( $data['product_url'] ?? '' ) );
        $brand_url     = trim( (string) ( $data['brand_url'] ?? '' ) );
        $regular_price = isset( $data['regular_price'] ) ? (float) $data['regular_price'] : null;

        // Sourced from WooCommerce's own email settings (the "Store Emails"
        // tab in mmi-email-customizer writes to these same options) rather
        // than MMI_Email_Templates' own token system — this email reaches a
        // real customer and must match the exact brand identity they
        // already see in their order-confirmation email, not a visually
        // distinct "MMI Suite" style. See render_customer_notice()'s own
        // docblock in mmi-hub for why this is a separate template from the
        // one internal/owner-facing MMI notifications use.
        $dark   = get_option( 'woocommerce_email_text_color', '#3c3c3c' );
        $muted  = get_option( 'woocommerce_email_footer_text_color', '#767676' );
        $accent = get_option( 'woocommerce_email_base_color', '#720eec' );
        // WooCommerce's own settings don't expose a panel/border pair —
        // native WC order-detail tables use a plain light-gray hairline
        // too, so this is a fixed, brand-neutral choice, not a missing token.
        $panel   = '#f8f8f8';
        $border  = '#e5e5e5';
        // Warning amber is semantic (an attention/support callout), not a
        // brand color — deliberately fixed rather than tied to store branding.
        $warn_bg = '#fff3cd';
        $warn    = '#b45309';

        // Bigger than the previous 180x90 — the user's own complaint was
        // that product images were "hard to see."
        $logo_html = $software_logo_url !== ''
            ? '<p style="margin:0 0 20px;text-align:center;"><img src="' . esc_url( $software_logo_url ) . '" alt="' . esc_attr( $software_name ) . '" style="max-width:320px;max-height:200px;width:auto;height:auto;display:inline-block;border-radius:6px;"></p>'
            : '';

        // Support goes FIRST, and deliberately looks different from a plain
        // link — this is a real support hand-off, not an afterthought: the
        // manufacturer built and supports this product, MannMade did not,
        // and a reply to this transactional email reaches nobody who can
        // help. Only rendered when a support URL actually exists; a missing
        // one isn't worth a banner that promises help with no link to give.
        $support_html = $support_url !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 24px;background:' . esc_attr( $warn_bg ) . ';border:2px solid ' . esc_attr( $warn ) . ';border-radius:8px;">'
                . '<tr><td style="padding:18px 20px;">'
                . '<p style="margin:0 0 6px;font-weight:700;color:' . esc_attr( $dark ) . ';font-size:15px;">' . esc_html__( 'Need help with this product?', 'mmi-xchange-integration' ) . '</p>'
                . '<p style="margin:0 0 12px;color:' . esc_attr( $dark ) . ';font-size:13px;line-height:1.5;">'
                . sprintf(
                    /* translators: %s: vendor/manufacturer name */
                    esc_html__( 'This product is made and supported by %s, not by us — please don\'t reply to this email with installation or activation questions, as we can\'t answer them. Use the link below to reach the people who actually built it.', 'mmi-xchange-integration' ),
                    '<strong>' . esc_html( $vendor_name !== '' ? $vendor_name : __( 'the manufacturer', 'mmi-xchange-integration' ) ) . '</strong>'
                )
                . '</p>'
                . '<a href="' . esc_url( $support_url ) . '" style="display:inline-block;padding:10px 20px;background:' . esc_attr( $warn ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">' . esc_html__( 'Contact Manufacturer Support', 'mmi-xchange-integration' ) . ' &rarr;</a>'
                . '</td></tr>'
                . '</table>'
            : '';

        $product_label = $software_name !== 'your software' ? $software_name : '';
        $product_value = $product_label === ''
            ? ''
            : ( $product_url !== ''
                ? '<a href="' . esc_url( $product_url ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none;">' . esc_html( $product_label ) . '</a>'
                : esc_html( $product_label ) );

        $vendor_value = $vendor_name === ''
            ? ''
            : ( $brand_url !== ''
                ? '<a href="' . esc_url( $brand_url ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none;">' . esc_html( $vendor_name ) . '</a>'
                : esc_html( $vendor_name ) );

        // Price row(s) — same "was/now" convention as the product page:
        // only show the regular price when it's genuinely higher than what
        // the customer paid (i.e. this was bought on sale); otherwise a
        // single price is all there is to say. $regular_price is the WC
        // product's own regular price — already public on the product page
        // to any visitor, never our wholesale/Xchange cost (see the
        // "Fulfillment Queue Margin..." incident in AGENTS.md for why that
        // distinction matters and must never blur here).
        $price_value = '';
        if ( $price > 0 ) {
            $on_sale = $regular_price !== null && $regular_price > ( $price + 0.01 );
            $price_value = $on_sale
                ? '<span style="text-decoration:line-through;color:' . esc_attr( $muted ) . ';font-weight:400;">' . esc_html( number_format( $regular_price, 2 ) . ' ' . $currency ) . '</span> '
                    . esc_html( number_format( $price, 2 ) . ' ' . $currency )
                : esc_html( number_format( $price, 2 ) . ' ' . $currency );
        }

        // "Order Details" — everything Xchange's own REST response + product
        // catalog make available at the moment the order is placed, minus
        // "Code" (an internal Xchange catalog field that only confused
        // customers alongside "License Key") and "Auth #" (an internal
        // payment-processor reference, e.g. a Stripe payment intent ID).
        // Deliberately never includes dealer/promo cost data — this
        // recipient is the customer, not an internal margin report.
        $detail_rows = array_filter( [
            [ 'PO #', $po_number ],
            [ 'SKU', $sku ],
            [ 'Our SKU', $our_sku ],
            [ 'Product', $product_value ],
            [ 'Vendor', $vendor_value ],
            [ 'Price', $price_value ],
        ], static fn( $row ) => $row[1] !== '' );

        $details_html = '';
        if ( ! empty( $detail_rows ) ) {
            $cells = '';
            foreach ( $detail_rows as [ $label, $value ] ) {
                // $value is pre-escaped/pre-built above (product/vendor may
                // contain an <a> tag) — not passed through esc_html() again here.
                $cells .= '<tr>'
                    . '<td style="padding:4px 12px 4px 0;font-size:13px;color:' . esc_attr( $muted ) . ';white-space:nowrap;">' . esc_html( $label ) . '</td>'
                    . '<td style="padding:4px 0;font-size:13px;color:' . esc_attr( $dark ) . ';font-weight:600;">' . $value . '</td>'
                    . '</tr>';
            }
            $details_html = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;background:' . esc_attr( $panel ) . ';border:1px solid ' . esc_attr( $border ) . ';border-radius:6px;padding:12px 16px;width:100%;">' . $cells . '</table>';
        }

        $license_html = '';
        if ( $license_key !== '' ) {
            $license_html  = '<p style="margin:16px 0 4px;font-size:13px;color:' . esc_attr( $muted ) . ';">' . esc_html__( 'License Key', 'mmi-xchange-integration' ) . '</p>';
            $license_html .= '<p style="margin:0 0 16px;font-family:Consolas,Menlo,monospace;font-size:16px;font-weight:600;background:' . esc_attr( $panel ) . ';border:1px solid ' . esc_attr( $border ) . ';border-radius:6px;padding:14px 16px;color:' . esc_attr( $dark ) . ';word-break:break-all;">' . esc_html( $license_key ) . '</p>';
        }

        $body_html = $support_html . $logo_html . $details_html . $license_html;

        return MMI_Email_Templates::render_customer_notice( [
            'box_heading' => sprintf(
                /* translators: %s: software/product name */
                esc_html__( 'Your %s license is ready', 'mmi-xchange-integration' ),
                esc_html( $software_name )
            ),
            'box_subtext' => sprintf(
                /* translators: %s: customer's first name */
                esc_html__( 'Hi %s — here\'s everything you need to complete your purchase.', 'mmi-xchange-integration' ),
                esc_html( $customer_name )
            ),
            'body_html'   => $body_html,
            // Falls back to the site's public homepage, never admin_url() —
            // a customer email has no business linking to the WP dashboard.
            'cta_url'     => $download_url !== '' ? $download_url : home_url(),
            'cta_label'   => $download_url !== '' ? esc_html__( 'Download Now', 'mmi-xchange-integration' ) : esc_html__( 'Visit Site', 'mmi-xchange-integration' ),
        ] );
    }

    /**
     * Render + send the fulfillment email.
     *
     * @param string $to       Recipient email address.
     * @param array  $data     See render().
     * @param int    $order_id When > 0, records this attempt (success or
     *   failure) to that order's email history — see get_history() /
     *   resend(). 0 for the standalone Place Order tab flow, which has no
     *   order to attach history to.
     * @param bool   $is_test  Prefixes the subject with "[TEST]" — used for
     *   the admin-only content/rendering preview send, never for a real
     *   customer-facing send.
     * @return array{success:bool,message:string}
     */
    public static function send( string $to, array $data, int $order_id = 0, bool $is_test = false ): array {
        // Every exit path below — success or failure — is recorded when
        // order_id is known, so "did this actually go out" is answerable
        // from the Fulfillment Queue UI instead of only MMI_Logger's log
        // file, which nobody checks after a customer says they never got it.
        $finish = static function ( array $result ) use ( $order_id, $to ): array {
            if ( $order_id > 0 ) {
                self::record_history( $order_id, $to, $result );
            }
            return $result;
        };

        if ( ! is_email( $to ) ) {
            return $finish( [ 'success' => false, 'message' => __( 'Not a valid email address.', 'mmi-xchange-integration' ) ] );
        }

        if ( ! class_exists( 'MMI_Email_Templates' ) ) {
            return $finish( [ 'success' => false, 'message' => __( 'mmi-hub email templates are not available (is mmi-hub active?).', 'mmi-xchange-integration' ) ] );
        }

        $software_name = trim( (string) ( $data['software_name'] ?? '' ) ) ?: __( 'Your Software', 'mmi-xchange-integration' );
        $body          = self::render( $data );

        if ( $body === '' ) {
            return $finish( [ 'success' => false, 'message' => __( 'Failed to render email content.', 'mmi-xchange-integration' ) ] );
        }

        $subject = sprintf(
            /* translators: 1: software name, 2: site name */
            __( 'Your %1$s license is ready — %2$s', 'mmi-xchange-integration' ),
            $software_name,
            get_bloginfo( 'name' )
        );
        if ( $is_test ) {
            $subject = '[TEST] ' . $subject;
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // Also notify the WP admin with the same order data — skip the Cc if
        // it's already the recipient (e.g. testing by emailing yourself) to
        // avoid a duplicate send to the same address.
        $admin_email = get_option( 'admin_email' );
        if ( is_email( $admin_email ) && strcasecmp( $admin_email, $to ) !== 0 ) {
            $headers[] = 'Cc: ' . $admin_email;
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            MMI_Logger::info(
                "XChange fulfillment email sent to {$to}",
                [ 'software_name' => $software_name, 'po_number' => $data['po_number'] ?? '' ],
                'xchange',
                'MMI_Xchange_Fulfillment_Email'
            );
            return $finish( [ 'success' => true, 'message' => __( 'Email sent.', 'mmi-xchange-integration' ) ] );
        }

        MMI_Logger::warn( "XChange fulfillment email failed to send to {$to}", [], 'xchange', 'MMI_Xchange_Fulfillment_Email' );
        return $finish( [ 'success' => false, 'message' => __( "wp_mail() reported failure — check your site's mail configuration.", 'mmi-xchange-integration' ) ] );
    }

    /**
     * Appends one attempt to this order's email history, capped at
     * MAX_HISTORY entries (oldest dropped first) — this is an operational
     * audit trail, not a permanent record, so unbounded growth isn't
     * warranted. Uses the WC_Order meta API, not update_post_meta()
     * directly — this install runs HPOS, which stores order meta in
     * wp_wc_orders_meta, not wp_postmeta.
     */
    private static function record_history( int $order_id, string $to, array $result ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $history   = self::get_history( $order_id );
        $history[] = [
            'sent_at' => current_time( 'mysql' ),
            'to'      => $to,
            'success' => (bool) $result['success'],
            'message' => (string) ( $result['message'] ?? '' ),
        ];

        if ( count( $history ) > self::MAX_HISTORY ) {
            $history = array_slice( $history, -self::MAX_HISTORY );
        }

        $order->update_meta_data( self::HISTORY_META_KEY, wp_json_encode( $history ) );
        $order->save();
    }

    /**
     * @return array<int,array{sent_at:string,to:string,success:bool,message:string}>
     *   Oldest first, matching record_history()'s append order.
     */
    public static function get_history( int $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [];
        }

        $raw     = $order->get_meta( self::HISTORY_META_KEY, true );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [];
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Register a preview sample with mmi-email-customizer so admins can
     * preview/test-send this template from the "MMI Emails" tab.
     *
     * @param array $samples
     * @return array
     */
    public static function register_email_sample( array $samples ): array {
        $samples[] = [
            'id'     => 'xchange_fulfillment',
            'group'  => 'Xchange',
            'label'  => 'Order Fulfillment Email',
            'render' => function () {
                return self::render( [
                    'customer_name'     => 'Jamie',
                    'software_name'     => 'Sample Studio Suite',
                    'software_logo_url' => '',
                    'license_key'       => 'ABCD1-EFGH2-IJKL3-MNOP4',
                    'download_url'      => 'https://example.com/download',
                    'support_url'       => 'https://example.com/support',
                    'po_number'         => '0000123456',
                    'sku'               => '1035-2920',
                    'our_sku'           => '1035-2920',
                    'vendor_name'       => 'Sample Vendor Inc.',
                    'product_url'       => 'https://example.com/product/sample-studio-suite',
                    'brand_url'         => 'https://example.com/product-brand/sample-vendor-inc',
                    'price'             => 314.99,
                    'currency'          => 'USD',
                ] );
            },
        ];
        return $samples;
    }
}
