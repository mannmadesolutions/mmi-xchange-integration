<?php
/**
 * One-time settings migration.
 *
 * Consolidates credentials from two legacy sources into a single set of
 * MMI_Settings keys (mmi_xchange_*):
 *   1. wp_options rows written by the old `xchangemarket` plugin (xchange_*)
 *   2. Raw wp_mmi rows written by mmi-reverb-integration's software handler
 *      (xchange-token-key, xchange-api-key, etc — never went through
 *      MMI_Settings, just direct $wpdb inserts)
 *
 * Runs once (guarded by mmi_xchange_settings_migrated); safe to call on every
 * page load after that.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Settings_Migration {

    /**
     * new mmi_xchange_* key => [ legacy wp_mmi raw field_name (or null), legacy wp_options key (or null) ]
     */
    const KEY_MAP = [
        'mmi_xchange_token_key'        => [ 'xchange-token-key',  'xchange_token_key' ],
        'mmi_xchange_api_key'          => [ 'xchange-api-key',    'xchange_api_key' ],
        'mmi_xchange_token_url'        => [ 'xchange-token-url',  'xchange_token_url' ],
        'mmi_xchange_api_url'          => [ 'xchange-api-url',    'xchange_api_url' ],
        'mmi_xchange_login_id'         => [ 'xchange-login-id',   null ],
        'mmi_xchange_login_pwd'        => [ 'xchange-login-pwd',  null ],
        'mmi_xchange_assets_url'       => [ 'xchange-assets-url', 'xchange_assets_url' ],
        'mmi_xchange_production_mode'  => [ null,                 'xchange_production_mode' ],
        'mmi_xchange_po_prefix'        => [ null,                 'xchange_po_prefix' ],
        'mmi_xchange_support_link'     => [ null,                 'xchange_support_link' ],
        'mmi_xchange_text_table_title' => [ null,                 'xchange_text_table_title' ],
        'mmi_xchange_text_product'     => [ null,                 'xchange_text_product' ],
        'mmi_xchange_text_serial'      => [ null,                 'xchange_text_serial' ],
        'mmi_xchange_text_download'    => [ null,                 'xchange_text_download' ],
        'mmi_xchange_text_support'     => [ null,                 'xchange_text_support' ],
        'mmi_xchange_log_only_errors'  => [ null,                 'xchange_log_only_errors' ],
        'mmi_xchange_abort_on_error'   => [ null,                 'xchange_abort_on_error' ],
        'mmi_xchange_failed_message'   => [ null,                 'xchange_failed_message' ],
        'mmi_xchange_email_errors_to'  => [ null,                 'xchange_email_errors_to' ],
    ];

    const DONE_FLAG = 'mmi_xchange_settings_migrated';

    public static function maybe_run(): void {
        if ( MMI_Settings::get( self::DONE_FLAG ) ) {
            return;
        }

        $migrated = [];

        foreach ( self::KEY_MAP as $new_key => [ $legacy_mmi_field, $legacy_option_key ] ) {
            $value = '';

            if ( $legacy_mmi_field !== null ) {
                $value = self::read_legacy_wp_mmi( $legacy_mmi_field );
            }

            if ( $value === '' && $legacy_option_key !== null ) {
                $value = get_option( $legacy_option_key, '' );
            }

            if ( $value !== '' ) {
                MMI_Settings::set( $new_key, $value );
                $migrated[] = $new_key;
            }
        }

        MMI_Settings::set( self::DONE_FLAG, current_time( 'mysql' ) );

        MMI_Logger::info(
            'Xchange settings migration complete',
            [ 'migrated_keys' => $migrated ],
            'xchange',
            'MMI_Xchange_Settings_Migration'
        );
    }

    /**
     * Reads a legacy hyphenated-naming-scheme field directly by field_name
     * (MMI_Settings::get() queries by field_name alone too, so this is a
     * correct lookup even though MMI_Settings never wrote these particular
     * rows — it just never derived a meaningful tab for their hyphenated
     * names, which doesn't matter for a read).
     */
    private static function read_legacy_wp_mmi( string $field_name ): string {
        $value = MMI_Settings::get( $field_name, '' );

        return $value !== false && $value !== null ? (string) $value : '';
    }
}
