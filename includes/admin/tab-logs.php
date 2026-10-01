<?php
/**
 * Logs tab — reads/filters this plugin's own MMI_Logger category
 * ('xchange', mmi-hub/logs/xchange.log). Every XChange process in this
 * plugin (account/connection checks, order sync, checkout reserve/finalize,
 * webhook receipts, COGS backfill, etc.) already logs there via
 * MMI_Logger::info/warn/error/debug() — this tab is a read-only viewer over
 * that one file, not a second logging mechanism. See MMI_Xchange_Logs.
 *
 * Reuses the shared .mmi-collapsible-section card shell (mmi-suite-common.css
 * — same component the Settings tab's "Account & Connection" section uses)
 * for its outer frame, but never collapses — no clickable header, no
 * .mmi-collapse-toggle — it's just borrowing the card look. See
 * admin-xchange.css's scoped #mmi-x-logs-section rules for the one local
 * difference (a static, non-pointer cursor on the header).
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="mmi-collapsible-section" id="mmi-x-logs-section">
    <div class="mmi-section-header">
        <div>
            <h3>
                <span class="dashicons dashicons-media-text"></span>
                <?php esc_html_e( 'XChange Process Logs', 'mmi-xchange-integration' ); ?>
            </h3>
            <p class="mmi-section-description"><?php esc_html_e( 'Live view of mmi-hub/logs/xchange.log — every account/connection check, order sync, checkout reserve/finalize, webhook receipt, and COGS backfill this plugin has logged.', 'mmi-xchange-integration' ); ?></p>
        </div>
    </div>

    <div class="mmi-section-content">
        <div class="mmi-x-log-toolbar">
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Level', 'mmi-xchange-integration' ); ?>
                <select id="mmi-x-log-level-filter" class="mmi-x-select">
                    <option value="all"><?php esc_html_e( 'All levels', 'mmi-xchange-integration' ); ?></option>
                    <option value="ERROR"><?php esc_html_e( 'Error', 'mmi-xchange-integration' ); ?></option>
                    <option value="WARN"><?php esc_html_e( 'Warning', 'mmi-xchange-integration' ); ?></option>
                    <option value="INFO"><?php esc_html_e( 'Info', 'mmi-xchange-integration' ); ?></option>
                    <option value="DEBUG"><?php esc_html_e( 'Debug', 'mmi-xchange-integration' ); ?></option>
                </select>
            </label>
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Process', 'mmi-xchange-integration' ); ?>
                <select id="mmi-x-log-source-filter" class="mmi-x-select">
                    <option value="all"><?php esc_html_e( 'All processes', 'mmi-xchange-integration' ); ?></option>
                </select>
            </label>
            <label class="mmi-x-inline-label">
                <?php esc_html_e( 'Show', 'mmi-xchange-integration' ); ?>
                <select id="mmi-x-log-limit" class="mmi-x-select">
                    <option value="100"><?php esc_html_e( 'Last 100 lines', 'mmi-xchange-integration' ); ?></option>
                    <option value="300" selected><?php esc_html_e( 'Last 300 lines', 'mmi-xchange-integration' ); ?></option>
                    <option value="1000"><?php esc_html_e( 'Last 1,000 lines', 'mmi-xchange-integration' ); ?></option>
                    <option value="2000"><?php esc_html_e( 'Last 2,000 lines', 'mmi-xchange-integration' ); ?></option>
                </select>
            </label>
            <input type="search" id="mmi-x-log-search" class="mmi-x-input" placeholder="<?php esc_attr_e( 'Search log…', 'mmi-xchange-integration' ); ?>" />
            <span class="mmi-x-log-toolbar-actions">
                <button type="button" class="button mmi-action-btn" id="mmi-x-log-refresh"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Refresh', 'mmi-xchange-integration' ); ?></button>
                <button type="button" class="button mmi-action-btn mmi-action-btn--danger" id="mmi-x-log-clear"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Log', 'mmi-xchange-integration' ); ?></button>
                <span class="mmi-loading mmi-hidden" id="mmi-x-log-spinner"></span>
            </span>
        </div>

        <div id="mmi-x-log-meta" class="mmi-x-log-meta"></div>

        <div id="mmi-x-log-content-wrapper" class="mmi-x-log-content-wrapper">
            <pre id="mmi-x-log-content"><?php esc_html_e( 'Loading logs…', 'mmi-xchange-integration' ); ?></pre>
        </div>
    </div>
</div>
