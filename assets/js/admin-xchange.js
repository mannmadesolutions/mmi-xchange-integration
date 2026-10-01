/* MMI Xchange — admin console */
(function ($) {
    'use strict';

    const SELECTORS = {
        ordersTable: '#mmi-x-orders-table',
        ordersTbody: '#mmi-x-orders-tbody',
        ordersSearch: '#mmi-x-orders-search',
        ordersProductFilter: '#mmi-x-orders-product-filter',
        ordersProductList: '#mmi-x-orders-product-list',
        ordersVendorFilter: '#mmi-x-orders-vendor-filter',
        ordersPriceMin: '#mmi-x-orders-price-min',
        ordersPriceMax: '#mmi-x-orders-price-max',
        ordersDateRange: '#mmi-x-orders-date-range',
        ordersRefresh: '#mmi-x-orders-refresh',
        ordersFullSync: '#mmi-x-orders-full-sync',
        ordersFullSyncedAt: '#mmi-x-orders-full-synced-at',
        ordersFullSyncProgress: '#mmi-x-orders-full-sync-progress',
        ordersFullSyncFill: '#mmi-x-orders-full-sync-fill',
        ordersFullSyncStep: '#mmi-x-orders-full-sync-step',
        ordersSyncedAt: '#mmi-x-orders-synced-at',
        ordersCount: '#mmi-x-orders-count',
        orderDetail: '#mmi-x-order-detail',
        orderDetailBody: '#mmi-x-order-detail-body',

        poSku: '#mmi-x-po-sku',
        poQty: '#mmi-x-po-qty',
        poSubmit: '#mmi-x-po-submit',
        poSpinner: '#mmi-x-po-spinner',
        poResult: '#mmi-x-po-result',
        poPreviewBtn: '#mmi-x-po-preview-btn',
        poPreviewSpinner: '#mmi-x-po-preview-spinner',
        poPreview: '#mmi-x-po-preview',

        diagSuccessBtn: '#mmi-x-diag-success-btn',
        diagFailureBtn: '#mmi-x-diag-failure-btn',
        diagSpinner: '#mmi-x-diag-spinner',
        diagResult: '#mmi-x-diag-result',

        poOrdersTable: '#mmi-x-po-orders-table',
        poOrdersTbody: '#mmi-x-po-orders-tbody',
        poOrdersSearch: '#mmi-x-po-orders-search',
        poOrdersUnfulfilledOnly: '#mmi-x-po-orders-unfulfilled-only',
        poOrdersRefresh: '#mmi-x-po-orders-refresh',
        poQueueStatus: '#mmi-x-po-queue-status',

        poLinkerScanBtn: '#mmi-x-po-linker-scan-btn',
        poLinkerScanMoreBtn: '#mmi-x-po-linker-scan-more-btn',
        poLinkerSpinner: '#mmi-x-po-linker-spinner',
        poLinkerStatus: '#mmi-x-po-linker-status',
        poLinkerTable: '#mmi-x-po-linker-table',
        poLinkerTbody: '#mmi-x-po-linker-tbody',

        poEmailPanel: '#mmi-x-po-email-panel',
        bundleGroups: '#mmi-x-bundle-groups',
        bundlePanel: '#mmi-x-bundle-panel',
        bundleContext: '#mmi-x-bundle-context',
        bundleRelayNote: '#mmi-x-bundle-relay-note',
        bundleTo: '#mmi-x-bundle-to',
        bundleName: '#mmi-x-bundle-name',
        bundleItems: '#mmi-x-bundle-items',
        bundleSend: '#mmi-x-bundle-send',
        bundleTest: '#mmi-x-bundle-test',
        bundleSpinner: '#mmi-x-bundle-spinner',
        bundleResult: '#mmi-x-bundle-result',
        poEmailPoNumber: '#mmi-x-po-email-po-number',
        poEmailOrderId: '#mmi-x-po-email-order-id',
        poEmailLinkOnSend: '#mmi-x-po-email-link-on-send',
        poEmailLinkNote: '#mmi-x-po-email-link-note',
        poEmailAuth: '#mmi-x-po-email-auth',
        poEmailSku: '#mmi-x-po-email-sku',
        poEmailCode: '#mmi-x-po-email-code',
        poEmailOurSku: '#mmi-x-po-email-our-sku',
        poEmailProductId: '#mmi-x-po-email-product-id',
        poEmailVendor: '#mmi-x-po-email-vendor',
        poEmailPrice: '#mmi-x-po-email-price',
        poEmailCurrency: '#mmi-x-po-email-currency',
        poEmailSummary: '#mmi-x-po-email-summary',
        poEmailFallbackNote: '#mmi-x-po-email-fallback-note',
        poEmailContext: '#mmi-x-po-email-context',
        poEmailLoading: '#mmi-x-po-email-loading',
        poEmailLoadingText: '#mmi-x-po-email-loading-text',
        poEmailTest: '#mmi-x-po-email-test',
        // Grouped by which fetch actually populates them, so each field's
        // own spinner reflects its own fetch's lifecycle rather than one
        // blanket "something is loading" applied to every row alike.
        poEmailLookupFieldRows: '#mmi-x-po-email-software, #mmi-x-po-email-logo',
        poEmailDocumentFieldRows: '#mmi-x-po-email-license, #mmi-x-po-email-download, #mmi-x-po-email-support',
        poEmailTo: '#mmi-x-po-email-to',
        poEmailName: '#mmi-x-po-email-name',
        poEmailSoftware: '#mmi-x-po-email-software',
        poEmailLogo: '#mmi-x-po-email-logo',
        poEmailLicense: '#mmi-x-po-email-license',
        poEmailDownload: '#mmi-x-po-email-download',
        poEmailSupport: '#mmi-x-po-email-support',
        poEmailSend: '#mmi-x-po-email-send',
        poEmailSpinner: '#mmi-x-po-email-spinner',
        poEmailResult: '#mmi-x-po-email-result',

        accountCheckResult: '#mmi-x-account-check-result',
        orderApiCheckResult: '#mmi-x-order-api-check-result',

        modeSwitch: '#mmi-x-mode-switch',
        headerActions: '.mmi-xchange-wrap .mmi-header-actions',
        headerAlerts: '#mmi-x-header-alerts',
        headerConnectionBadge: '#mmi-x-header-connection-badge',
        headerTestConnectionBtn: '#mmi-x-header-test-connection',
        headerTestConnectionSpinner: '#mmi-x-header-test-connection-spinner',
        headerOrderApiBadge: '#mmi-x-header-order-api-badge',
        headerTestOrderApiBtn: '#mmi-x-header-test-order-api',
        headerTestOrderApiSpinner: '#mmi-x-header-test-order-api-spinner',

        settingsSectionClickable: '.mmi-x-section-clickable',

        logsSection: '#mmi-x-logs-section',
        logLevelFilter: '#mmi-x-log-level-filter',
        logSourceFilter: '#mmi-x-log-source-filter',
        logLimit: '#mmi-x-log-limit',
        logSearch: '#mmi-x-log-search',
        logRefresh: '#mmi-x-log-refresh',
        logClear: '#mmi-x-log-clear',
        logSpinner: '#mmi-x-log-spinner',
        logMeta: '#mmi-x-log-meta',
        logContent: '#mmi-x-log-content',

        vendorsTable: '#mmi-x-vendors-table',
        vendorsTbody: '#mmi-x-vendors-tbody',
        vendorsSearch: '#mmi-x-vendors-search',
        vendorsMissingOnly: '#mmi-x-vendors-missing-only',
        vendorsRefresh: '#mmi-x-vendors-refresh',
        vendorDetail: '#mmi-x-vendor-detail',
        vendorDetailBody: '#mmi-x-vendor-detail-body',
        importVendorInput: '#mmi-x-import-vendor',
        importMediaBtn: '#mmi-x-import-media-btn',
        importMediaSyncedAt: '#mmi-x-import-media-synced-at',
        importMediaProgress: '#mmi-x-import-media-progress',
        importMediaFill: '#mmi-x-import-media-fill',
        importMediaStep: '#mmi-x-import-media-step',
        imageStatsNote: '#mmi-x-image-stats-note',
        statVendors: '#mmi-x-stat-vendors',
        statMatched: '#mmi-x-stat-matched',
        statMissing: '#mmi-x-stat-missing',
        statMissingCard: '#mmi-x-stat-missing-card',
        statImported: '#mmi-x-stat-imported',

        cogsMatchable: '#mmi-x-cogs-matchable',
        cogsDone: '#mmi-x-cogs-done',
        cogsFill: '#mmi-x-cogs-progress-fill',
        cogsPct: '#mmi-x-cogs-pct',
        cogsStatusMsg: '#mmi-x-cogs-status-msg',
        cogsBackfillBtn: '#mmi-x-cogs-backfill-btn',
        cogsSpinner: '#mmi-x-cogs-spinner',
    };

    const LABEL_LOADING = '<span class="mmi-loading"></span>';

    // A ".mmi-x-empty" cell/paragraph showing a genuine "Loading…" message
    // gets this spinner + the "mmi-x-empty--loading" flex modifier (see
    // admin-xchange.css) — .mmi-x-empty is also used for "No results" and
    // error messages, which must NOT get a spinner, so this is applied only
    // at the specific "still loading" call sites, never as a blanket change
    // to the base class.
    function emptyLoadingHtml(text) {
        return `${LABEL_LOADING}<span>${text}</span>`;
    }

    // States for #mmi-x-po-queue-status — a plain unstyled text line was
    // easy to miss for a result as consequential as "this order was blocked
    // to prevent a duplicate XChange purchase," which is exactly what made
    // that block look like nothing had happened at all. See setQueueStatus().
    const QUEUE_STATUS_CLASSES = ['mmi-x-status--busy', 'mmi-x-status--success', 'mmi-x-status--blocked', 'mmi-x-status--error'];

    function ajax(action, data) {
        return $.post(mmiXchange.ajaxUrl, Object.assign({ action: action, nonce: mmiXchange.nonce }, data));
    }

    /**
     * Set for the duration of a column-resize drag (any of this file's 4
     * resizable tables) and briefly after mouseup, so initSortableTable()'s
     * click handler can tell a resize drag apart from an actual header
     * click. A native 'click' is a SEPARATE event from mousedown/mouseup —
     * stopping mousedown's propagation in initColumnResize() does not stop a
     * click from also firing and bubbling to this delegated handler, and
     * after a real drag the click's target is almost never the thin resize
     * handle itself (it's wherever the pointer ended up, often the header
     * cell body) — the pre-existing `$(e.target).hasClass('mmi-x-resize-handle')`
     * check below only ever covered the no/tiny-drag case where the click
     * happens to land back on the handle.
     */
    let resizeJustEnded = false;

    /* ── Shared: in-memory sortable table ─────────────────────────────────── */

    function initSortableTable($table, getRows, renderRows) {
        let sortCol = null;
        let sortDir = 'asc';

        $table.on('click', 'thead th[data-col]', function (e) {
            if ($(e.target).hasClass('mmi-x-resize-handle') || resizeJustEnded) {
                return;
            }
            const col = $(this).data('col');
            sortDir = sortCol === col && sortDir === 'asc' ? 'desc' : 'asc';
            sortCol = col;

            $table.find('thead th').removeClass('is-active-asc is-active-desc');
            $(this).addClass(sortDir === 'asc' ? 'is-active-asc' : 'is-active-desc');

            const rows = getRows().slice().sort((a, b) => {
                if (typeof a[col] === 'number' || typeof b[col] === 'number') {
                    const an = parseFloat(a[col]) || 0;
                    const bn = parseFloat(b[col]) || 0;
                    return sortDir === 'asc' ? an - bn : bn - an;
                }
                const av = (a[col] ?? '').toString().toLowerCase();
                const bv = (b[col] ?? '').toString().toLowerCase();
                if (av < bv) return sortDir === 'asc' ? -1 : 1;
                if (av > bv) return sortDir === 'asc' ? 1 : -1;
                return 0;
            });
            renderRows(rows);
        });
    }

    /* ── Shared: resizable columns (persisted to localStorage) ───────────── */

    function initColumnResize($table, storageKey) {
        let stored = {};
        try {
            stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
        } catch (e) {
            stored = {};
        }

        // table-layout:fixed sources column widths from the table's own
        // FIRST <tr> — for a table whose first header row is a colspan'd
        // group-header (no per-column widths of its own, e.g.
        // #mmi-x-po-orders-table's WooCommerce/Xchange row), which row's
        // <th> a browser falls back to for widths is inconsistent, and a
        // <th>-only width change in a later row isn't guaranteed to move
        // the rendered column at all. A <colgroup>'s <col> width is
        // unambiguous regardless of that — so whenever one exists for a
        // given column, it's the real target; a table with no <colgroup>
        // (every other table this function is called for) just falls back
        // to the <th> itself, unchanged from before.
        function widthTarget($th, col) {
            const $col = $table.find('colgroup col[data-resize-col="' + col + '"]');
            return $col.length ? $col : $th;
        }

        $table.find('th[data-resize-col]').each(function () {
            const col = $(this).data('resize-col');
            if (stored[col]) {
                widthTarget($(this), col).css('width', stored[col] + 'px');
            }
        });

        // Covers the no/tiny-drag case: a plain click that lands back on the
        // resize handle itself. Stopped before it bubbles to the sortable
        // <th>'s delegated click handler (initSortableTable() above).
        $table.on('click', '.mmi-x-resize-handle', function (e) {
            e.stopPropagation();
        });

        $table.on('mousedown', '.mmi-x-resize-handle', function (e) {
            e.stopPropagation();
            e.preventDefault();

            const $th = $(this).closest('th');
            const col = $th.data('resize-col');
            const $target = widthTarget($th, col);
            const startX = e.pageX;
            const startWidth = $th.outerWidth();

            function onMove(ev) {
                const newWidth = Math.max(50, startWidth + (ev.pageX - startX));
                $target.css('width', newWidth + 'px');
            }

            function onUp() {
                $(document).off('mousemove', onMove).off('mouseup', onUp);
                try {
                    stored[col] = $th.outerWidth();
                    localStorage.setItem(storageKey, JSON.stringify(stored));
                } catch (err) {
                    /* private browsing — ignore */
                }

                // Covers the general case: mouseup (and the click that follows
                // it) landing over the header body rather than the handle.
                // Cleared on the next tick, after that click has had a chance
                // to fire and check this flag, so a genuine later click still
                // sorts normally.
                resizeJustEnded = true;
                setTimeout(function () { resizeJustEnded = false; }, 0);
            }

            $(document).on('mousemove', onMove).on('mouseup', onUp);
        });
    }

    /* ── Orders tab ────────────────────────────────────────────────────────── */

    const OrdersTab = {
        allOrders: [],

        init() {
            if ($(SELECTORS.ordersTable).length === 0) {
                return;
            }

            initSortableTable($(SELECTORS.ordersTable), () => this.filteredOrders(), (rows) => this.renderRows(rows));
            initColumnResize($(SELECTORS.ordersTable), 'mmiXchangeOrdersColWidths');

            $(SELECTORS.ordersSearch).on('input', () => this.applyFilters());
            $(SELECTORS.ordersProductFilter).on('input', () => this.applyFilters());
            $(SELECTORS.ordersVendorFilter).on('change', () => this.applyFilters());
            $(SELECTORS.ordersPriceMin).on('input', () => this.applyFilters());
            $(SELECTORS.ordersPriceMax).on('input', () => this.applyFilters());
            $(SELECTORS.ordersDateRange).on('change', () => this.applyFilters());
            $(SELECTORS.ordersRefresh).on('click', () => this.refresh());
            $(SELECTORS.ordersFullSync).on('click', () => this.fullSync());
            $('#mmi-x-register-webhooks').on('click', () => this.registerWebhooks());
            $(document).on('click', `${SELECTORS.ordersTbody} tr`, function () {
                // Same guard as .mmi-x-po-order-row below — a click-and-drag
                // to select/copy cell text still ends in a native 'click' on
                // mouseup, which would otherwise pop the detail modal open
                // instead of leaving the selection alone.
                if (window.getSelection().toString().length > 0) {
                    return;
                }
                const order = $(this).data('order');
                if (order) {
                    OrdersTab.showDetail(order);
                }
            });
            this.fetch(false);
        },

        fetch(forceRefresh) {
            $(SELECTORS.ordersTbody).html(`<tr><td colspan="10" class="mmi-x-empty mmi-x-empty--loading">${emptyLoadingHtml('Loading…')}</td></tr>`);

            ajax('mmi_xchange_fetch_orders', { force_refresh: forceRefresh ? 1 : 0 }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.ordersTbody).html(`<tr><td colspan="10" class="mmi-x-empty">${escapeHtml(res.data?.message || 'Could not load orders.')}</td></tr>`);
                    return;
                }
                this.allOrders = res.data.orders || [];
                $(SELECTORS.ordersSyncedAt).text(res.data.synced_at || 'never');
                $(SELECTORS.ordersCount).text(this.allOrders.length);
                this.populateFilterOptions();
                this.applyFilters();
            });
        },

        // Rebuilds the Vendor <select> and Product <datalist> from whatever
        // orders are actually loaded, so the options always match reality
        // (no stale/inactive vendors, no products with zero orders).
        populateFilterOptions() {
            const $vendorSelect = $(SELECTORS.ordersVendorFilter);
            const selectedVendor = $vendorSelect.val();
            const vendors = new Map();
            this.allOrders.forEach((o) => {
                const id = o.vendor_id || '';
                const name = o.vendor_name || '';
                if ((id || name) && !vendors.has(id)) {
                    vendors.set(id, name || id);
                }
            });
            const sortedVendors = Array.from(vendors.entries()).sort((a, b) => a[1].localeCompare(b[1]));
            $vendorSelect.find('option:not(:first)').remove();
            sortedVendors.forEach(([id, name]) => {
                $vendorSelect.append($('<option>').val(id).text(name));
            });
            if (selectedVendor && vendors.has(selectedVendor)) {
                $vendorSelect.val(selectedVendor);
            }

            const $productList = $(SELECTORS.ordersProductList);
            const products = Array.from(new Set(this.allOrders.map((o) => o.product).filter(Boolean))).sort();
            $productList.empty();
            products.forEach((name) => {
                $productList.append($('<option>').val(name));
            });
        },

        refresh() {
            // Safe, API-only sync (no XChange portal login) — fetch(true) forces
            // MMI_Xchange_Order_Sync::run_sync() synchronously via get_snapshot().
            this.fetch(true);
        },

        fullSync() {
            const confirmed = window.confirm(
                'This logs into the XChange web portal (xchangeb2b.com) using your reseller credentials to pull the complete order history.\n\n' +
                'It WILL end any XChange.com session you currently have open in a browser.\n\n' +
                'Continue?'
            );
            if (!confirmed) {
                return;
            }

            const $btn = $(SELECTORS.ordersFullSync);
            $btn.prop('disabled', true).addClass('mmi-is-loading');
            $(SELECTORS.ordersFullSyncProgress).removeClass('mmi-hidden');
            $(SELECTORS.ordersFullSyncFill).css('--pct', '2%');
            $(SELECTORS.ordersFullSyncStep).text('Queued…');

            ajax('mmi_xchange_full_sync', {}).done(() => {
                this.pollFullSyncStatus($btn, 0);
            }).fail(() => {
                window.alert('Could not queue the full sync — try again.');
                $(SELECTORS.ordersFullSyncProgress).addClass('mmi-hidden');
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
            });
        },

        // REST-only — never touches MMI_Xchange_Web_Session, so unlike
        // fullSync() above this needs no session-eviction warning. Confirms
        // instead that this opens a new public endpoint on the server.
        registerWebhooks() {
            const confirmed = window.confirm(
                'This registers a public webhook endpoint on this server with XChange for order-update notifications.\n\n' +
                'It only calls the safe REST API (never the web portal) — but it does open a new, publicly reachable URL on this site.\n\n' +
                'Continue?'
            );
            if (!confirmed) {
                return;
            }

            const $btn = $('#mmi-x-register-webhooks');
            $btn.prop('disabled', true);
            $('#mmi-x-register-webhooks-spinner').removeClass('mmi-hidden');

            ajax('mmi_xchange_register_webhooks', {}).done((res) => {
                window.alert((res.data && res.data.message) || (res.success ? 'Webhooks registered.' : 'Could not register webhooks.'));
                if (res.success) {
                    location.reload();
                }
            }).fail(() => {
                window.alert('Network error — could not register webhooks.');
            }).always(() => {
                $btn.prop('disabled', false);
                $('#mmi-x-register-webhooks-spinner').addClass('mmi-hidden');
            });
        },

        // Polls mmi_xchange_full_sync_status every 2s while the queued full
        // sync runs in the background; 3-minute safety cutoff in case a stuck
        // job never reports 'complete'/'error'.
        pollFullSyncStatus(atts, elapsedMs) {
            const $btn = atts;

            if (elapsedMs >= 180000) {
                $(SELECTORS.ordersFullSyncStep).text('Taking longer than expected — check back shortly.');
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
                this.fetch(true);
                return;
            }

            ajax('mmi_xchange_full_sync_status', {}).done((res) => {
                const s = res.data || {};
                $(SELECTORS.ordersFullSyncFill).css('--pct', (s.pct || 0) + '%');
                $(SELECTORS.ordersFullSyncStep).text(s.step || s.message || '…');

                if (s.status === 'complete') {
                    $(SELECTORS.ordersFullSyncStep).text(s.message || 'Done.');
                    $btn.prop('disabled', false).removeClass('mmi-is-loading');
                    this.fetch(true);
                    setTimeout(() => $(SELECTORS.ordersFullSyncProgress).addClass('mmi-hidden'), 4000);
                    return;
                }

                if (s.status === 'error') {
                    $(SELECTORS.ordersFullSyncStep).text('Full sync failed: ' + (s.message || 'unknown error'));
                    $btn.prop('disabled', false).removeClass('mmi-is-loading');
                    return;
                }

                setTimeout(() => this.pollFullSyncStatus($btn, elapsedMs + 2000), 2000);
            }).fail(() => {
                setTimeout(() => this.pollFullSyncStatus($btn, elapsedMs + 2000), 2000);
            });
        },

        filteredOrders() {
            const term = ($(SELECTORS.ordersSearch).val() || '').toLowerCase();
            const productTerm = ($(SELECTORS.ordersProductFilter).val() || '').toLowerCase();
            const vendorId = $(SELECTORS.ordersVendorFilter).val() || '';
            const priceMin = parseFloat($(SELECTORS.ordersPriceMin).val());
            const priceMax = parseFloat($(SELECTORS.ordersPriceMax).val());
            const days = parseInt($(SELECTORS.ordersDateRange).val(), 10);

            return this.allOrders.filter((o) => {
                if (term) {
                    const haystack = `${o.po} ${o.sku}`.toLowerCase();
                    if (haystack.indexOf(term) === -1) {
                        return false;
                    }
                }
                if (productTerm && (o.product || '').toLowerCase().indexOf(productTerm) === -1) {
                    return false;
                }
                if (vendorId && (o.vendor_id || '') !== vendorId) {
                    return false;
                }
                const price = parseFloat(o.price);
                if (!isNaN(priceMin) && (isNaN(price) || price < priceMin)) {
                    return false;
                }
                if (!isNaN(priceMax) && (isNaN(price) || price > priceMax)) {
                    return false;
                }
                if (days > 0 && o.date) {
                    const orderDate = new Date(o.date);
                    const cutoff = new Date();
                    cutoff.setDate(cutoff.getDate() - days);
                    if (!isNaN(orderDate.getTime()) && orderDate < cutoff) {
                        return false;
                    }
                }
                return true;
            });
        },

        applyFilters() {
            this.renderRows(this.filteredOrders());
        },

        renderRows(rows) {
            const $tbody = $(SELECTORS.ordersTbody);
            if (rows.length === 0) {
                $tbody.html('<tr><td colspan="10" class="mmi-x-empty">No matching orders.</td></tr>');
                return;
            }

            $tbody.empty();
            rows.forEach((o) => {
                const $tr = $('<tr>').data('order', o);
                $tr.append($('<td>').text(o.po || ''));
                $tr.append($('<td>').text(o.date || ''));
                $tr.append($('<td>').text(o.sku || ''));
                $tr.append($('<td>').text(o.product || ''));

                const $priceCell = $('<td>');
                if (o.price) {
                    $priceCell.append($('<span>').text('$' + parseFloat(o.price).toFixed(2)));
                    if (o.is_promo) {
                        $priceCell.append(' ').append($('<span class="mmi-badge success">Promo</span>'));
                    }
                } else {
                    $priceCell.text('—');
                }
                $tr.append($priceCell);

                $tr.append($('<td>').text(o.map ? '$' + parseFloat(o.map).toFixed(2) : '—'));

                const vendorText = [o.vendor_id, o.vendor_name].filter(Boolean).join(' — ');
                $tr.append($('<td>').text(vendorText || '—'));

                $tr.append($('<td>').text(o.license_key || '—'));
                $tr.append($('<td>').text(o.auth || ''));

                const $ccsaCell = $('<td>');
                if (o.in_ccsa) {
                    $ccsaCell.append('<span class="mmi-badge warning">In CCSA</span>');
                } else {
                    $ccsaCell.text('—');
                }
                $tr.append($ccsaCell);

                $tbody.append($tr);
            });
        },

        showDetail(order) {
            const rows = [
                ['PO', order.po],
                ['Date', order.date],
                ['SKU', order.sku],
                ['Product', order.product],
                ['Price', order.price ? '$' + parseFloat(order.price).toFixed(2) + (order.is_promo ? ' (promo)' : '') : ''],
                ['MAP', order.map ? '$' + parseFloat(order.map).toFixed(2) : ''],
                ['Vendor', [order.vendor_id, order.vendor_name].filter(Boolean).join(' — ')],
                ['License', order.license_key],
                ['Auth #', order.auth],
                ['Download', order.download_url ? { html: `<a href="${safeUrl(order.download_url)}" target="_blank" rel="noopener">${escapeHtml(order.download_url)}</a>` } : ''],
                ['Support', order.support_url],
                ['In CCSA', order.in_ccsa ? 'Yes — still in temporary staging' : 'No'],
            ];
            const html = detailRowsHtml(rows);
            $(SELECTORS.orderDetailBody).html(html);
            MMIModal.open(SELECTORS.orderDetail);
        },
    };

    /* ── Place Order tab: Recent Orders (fulfillment queue) panel ───────────
     * WooCommerce orders containing an XChange-sourced product line item —
     * see MMI_Xchange_Fulfillment_Queue — so the admin can fulfill a real
     * customer order directly from here, rather than this being another
     * view of XChange's own PO history (that's what the Orders tab is for). */

    const STATUS_BADGE_CLASS = {
        completed: 'success',
        processing: 'success',
        'on-hold': 'warning',
        pending: 'warning',
        cancelled: 'error',
        refunded: 'error',
        failed: 'error',
    };

    const POOrdersPanel = {
        allOrders: [],

        // Only one row expanded at a time. detailCache holds the rendered
        // HTML per order_id so re-renders triggered by search/sort/filter
        // (which can fire on every keystroke) never re-fire the live XChange
        // lookup — only a first expand or an explicit "↺ Refresh" inside the
        // panel does that.
        expandedOrderId: null,
        detailCache: {},

        // Set the instant a row transitions closed→open, read (and cleared)
        // by the very next renderRows() pass so the soft-reveal animation
        // (mmi-animate-fadeIn, see mmi-suite-common.css) plays once on the
        // genuine expand — never on every incidental re-render of an
        // already-open row that search/sort/filter can trigger.
        justExpandedOrderId: null,

        init() {
            if ($(SELECTORS.poOrdersTable).length === 0) {
                return;
            }

            initSortableTable($(SELECTORS.poOrdersTable), () => this.filteredOrders(), (rows) => this.renderRows(rows));
            // Renamed (was mmiXchangePoOrdersColWidths) when the Fulfilled/
            // Action columns were removed — a stale stored width for either
            // is now simply never read (their data-resize-col values no
            // longer exist), but the rename also gives every browser a
            // clean slate on the columns that remain, so a previous manual
            // widening can't alone reproduce the horizontal-scroll problem
            // this redesign was built to eliminate.
            initColumnResize($(SELECTORS.poOrdersTable), 'mmiXchangePoOrdersColWidthsV2');

            $(SELECTORS.poOrdersSearch).on('input', () => this.applyFilters());
            $(SELECTORS.poOrdersUnfulfilledOnly).on('change', () => this.applyFilters());
            $(SELECTORS.poOrdersRefresh).on('click', () => this.fetch());

            // Lives inside the expanded row's own detail header (see
            // renderDetailHtml()), not a shared toolbar button — so it
            // appears per row, right next to the live margin that
            // justifies fulfilling it, with nothing to scroll to. Resolved
            // via the button's own data-order-id, not "whichever row is
            // currently expanded" — correct even in the unlikely event more
            // than one detail panel's markup exists in the DOM at once.
            $(document).on('click', '.mmi-x-po-fulfill-btn', function (e) {
                e.stopPropagation();
                const orderId = $(this).data('order-id');
                const order = POOrdersPanel.allOrders.find((o) => o.order_id === orderId);
                if (order) {
                    PlaceOrderTab.fulfillFromQueue(order, $(this));
                }
            });
            $(document).on('click', '.mmi-x-po-order-row', function (e) {
                if ($(e.target).is('a, a *, button, button *')) {
                    return; // let the order-# link / Fulfill button handle their own click
                }
                // A click-and-drag to select/copy cell text still ends in a
                // native 'click' on mouseup — without this guard every such
                // drag toggled the detail panel instead of leaving the
                // selection alone, making the row's text impossible to copy.
                if (window.getSelection().toString().length > 0) {
                    return;
                }
                const order = $(this).data('order');
                if (order) {
                    POOrdersPanel.toggle(order);
                }
            });
            $(document).on('click', '.mmi-x-po-detail-refresh', function (e) {
                e.stopPropagation();
                const orderId = $(this).data('order-id');
                const order = POOrdersPanel.allOrders.find((o) => o.order_id === orderId);
                if (order) {
                    delete POOrdersPanel.detailCache[orderId];
                    POOrdersPanel.loadDetail(order);
                }
            });
            $(document).on('click', '.mmi-x-po-resend-email', function (e) {
                e.stopPropagation();
                const orderId = $(this).data('order-id');
                const order = POOrdersPanel.allOrders.find((o) => o.order_id === orderId);
                if (order) {
                    PlaceOrderTab.resendEmail(order);
                }
            });
            // Shared read+validate step for all 3 manual-sync buttons below
            // — each just supplies what to do with the resulting {order, po, auth}.
            function readManualSyncRow(orderId) {
                const order = POOrdersPanel.allOrders.find((o) => o.order_id === orderId);
                if (!order) {
                    return null;
                }
                const po = $(`.mmi-x-po-row-manual-po[data-order-id="${orderId}"]`).val().trim();
                const auth = $(`.mmi-x-po-row-manual-auth[data-order-id="${orderId}"]`).val().trim();
                if (!po) {
                    window.alert('Enter the PO / transaction number from the XChange portal first.');
                    return null;
                }
                return { order, po, auth };
            }
            $(document).on('click', '.mmi-x-po-row-manual-sync', function (e) {
                e.stopPropagation();
                const row = readManualSyncRow($(this).data('order-id'));
                if (row) {
                    PlaceOrderTab.syncManualOrder(row.order, row.po, row.auth, true);
                }
            });
            $(document).on('click', '.mmi-x-po-row-email-only', function (e) {
                e.stopPropagation();
                const row = readManualSyncRow($(this).data('order-id'));
                if (row) {
                    PlaceOrderTab.syncManualOrder(row.order, row.po, row.auth, false);
                }
            });
            $(document).on('click', '.mmi-x-po-row-link-only', function (e) {
                e.stopPropagation();
                const row = readManualSyncRow($(this).data('order-id'));
                if (row) {
                    POOrdersPanel.linkOnly(row.order, row.po, $(this));
                }
            });
            $(document).on('click', '.mmi-x-po-row-find-candidate', function (e) {
                e.stopPropagation();
                POOrdersPanel.findCandidate($(this).data('order-id'));
            });

            // Reuses mmi-hub's existing wp_ajax_mmi_guest_customer_convert
            // handler (same nonce action, same create-or-link logic) —
            // see MMI_Guest_Customer_Converter::ajax_convert(). This widget
            // only exists so a guest order can be linked without leaving
            // the Xchange tab; the actual conversion logic lives in one
            // place, not two.
            $(document).on('click', '.mmi-x-guest-convert-btn', function (e) {
                e.stopPropagation();
                const $btn = $(this);
                const orderId = $btn.data('order-id');
                const $row = $btn.closest('.mmi-x-guest-convert');
                const email = $row.find('.mmi-x-guest-convert-email').val().trim();
                const sendWelcome = $row.find('.mmi-x-guest-convert-welcome').is(':checked');

                if (!email) {
                    window.alert('Enter the customer\'s real email address first.');
                    return;
                }

                $btn.prop('disabled', true);
                $.post(mmiXchange.ajaxUrl, {
                    action: 'mmi_guest_customer_convert',
                    nonce: mmiXchange.guestConvertNonce,
                    order_id: orderId,
                    email: email,
                    send_welcome: sendWelcome ? 1 : 0,
                }).done((res) => {
                    if (res.success) {
                        POOrdersPanel.fetch();
                    } else {
                        window.alert((res.data && res.data.message) || 'Could not link this order to a customer account.');
                        $btn.prop('disabled', false);
                    }
                }).fail(() => {
                    window.alert('Network error — could not link this order to a customer account.');
                    $btn.prop('disabled', false);
                });
            });

            // Automated version of the "Link to Customer" flow above — sends
            // the buyer a Reverb message asking for their real email instead
            // of requiring the admin to already have it. Server-side:
            // MMI_Xchange_Ajax::request_guest_email() ->
            // MMI_Xchange_Guest_Email_Request::send(), the same send the
            // automatic trigger uses.
            $(document).on('click', '.mmi-x-guest-request-email-btn', function (e) {
                e.stopPropagation();
                const $btn = $(this);
                const orderId = $btn.data('order-id');

                $btn.prop('disabled', true);
                ajax('mmi_x_request_guest_email', { order_id: orderId }).done((res) => {
                    if (res.success) {
                        window.alert((res.data && res.data.message) || 'Email request sent via Reverb.');
                        POOrdersPanel.fetch();
                    } else {
                        window.alert((res.data && res.data.message) || 'Could not send the Reverb message.');
                        $btn.prop('disabled', false);
                    }
                }).fail(() => {
                    window.alert('Network error — could not send the Reverb message.');
                    $btn.prop('disabled', false);
                });
            });

            // Reveals the collapsed manual-override form inside
            // renderAutoEmailStatusHtml() — the automatic flow is the
            // primary path when it's active, but an admin can still step in
            // by hand if it stalls (e.g. no conversation could be resolved).
            $(document).on('click', '.mmi-x-guest-convert-manual-toggle', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).closest('.mmi-x-guest-convert--auto').find('.mmi-x-guest-convert-manual-form').toggleClass('mmi-hidden');
            });

            this.fetch();
        },

        fetch() {
            $(SELECTORS.poOrdersTbody).html(`<tr><td colspan="${this.COLSPAN}" class="mmi-x-empty mmi-x-empty--loading">${emptyLoadingHtml('Loading…')}</td></tr>`);

            ajax('mmi_xchange_fetch_fulfillment_queue', {}).done((res) => {
                if (!res.success) {
                    $(SELECTORS.poOrdersTbody).html(`<tr><td colspan="${this.COLSPAN}" class="mmi-x-empty">${escapeHtml(res.data?.message || 'Could not load orders.')}</td></tr>`);
                    return;
                }
                this.allOrders = res.data.orders || [];
                BundleFulfillment.renderGroups(this.allOrders);

                // detailCache holds rendered HTML built from a snapshot of
                // each order at expand-time (status badge, PO/license data
                // baked into the string) — without clearing it here, a
                // background refresh (e.g. right after a fulfillment email
                // send) updates the collapsed row correctly but leaves any
                // currently-expanded row's detail panel showing stale data
                // (e.g. "processing" after the order actually completed)
                // until it's manually collapsed and re-expanded.
                this.detailCache = {};
                this.applyFilters();

                if (this.expandedOrderId !== null) {
                    const stillOpen = this.allOrders.find((o) => o.order_id === this.expandedOrderId);
                    if (stillOpen) {
                        this.loadDetail(stillOpen);
                    }
                }
            }).fail((jqXHR) => {
                // See VendorsTab.fetch()'s matching .fail() comment — this
                // endpoint verified fine server-side; a real HTTP-layer
                // failure here was previously invisible, leaving "Loading…"
                // frozen forever with no indication anything had gone wrong.
                $(SELECTORS.poOrdersTbody).html(`<tr><td colspan="${this.COLSPAN}" class="mmi-x-empty">Could not load orders — request failed (HTTP ${jqXHR.status || 'unknown'}). Check the browser console/network tab.</td></tr>`);
            });
        },

        toggle(order) {
            this.expandedOrderId = this.expandedOrderId === order.order_id ? null : order.order_id;
            if (this.expandedOrderId === order.order_id) {
                this.justExpandedOrderId = order.order_id;
            }
            this.applyFilters();
            if (this.expandedOrderId === order.order_id && !this.detailCache[order.order_id]) {
                this.loadDetail(order);
            }
        },

        expand(order) {
            this.expandedOrderId = order.order_id;
            this.justExpandedOrderId = order.order_id;
            this.applyFilters();
            if (!this.detailCache[order.order_id]) {
                this.loadDetail(order);
            }
        },

        // Live per-SKU catalog lookup (price/status "as of right now", same
        // uncached fetch the Preview button uses) for every XChange line
        // item on this order, so the admin can see dealer cost vs. what the
        // customer paid before committing to fulfill via a real purchase.
        loadDetail(order) {
            this.detailCache[order.order_id] = `<p class="mmi-x-empty mmi-x-empty--loading">${emptyLoadingHtml('Loading live XChange data…')}</p>`;
            this.applyFilters();

            Promise.all(order.items.map((item) =>
                ajax('mmi_xchange_preview_product', { sku: item.sku, order_id: order.order_id }).then(
                    (res) => ({ item, data: res.success ? res.data : null, message: res.success ? '' : (res.data?.message || 'Not found in XChange catalog.') })
                ).catch(() => ({ item, data: null, message: 'Network error.' }))
            )).then((results) => {
                this.detailCache[order.order_id] = this.renderDetailHtml(order, results);
                this.applyFilters();
            });
        },

        // Modeled on WooCommerce's own order-preview modal
        // (wc-backbone-modal-content): a compact header strip (order #,
        // status, Reverb origin if applicable) above a real product table,
        // instead of a stack of loose label/value pairs.
        renderDetailHtml(order, results) {
            const statusClass = STATUS_BADGE_CLASS[order.status] || '';
            const sourceBadgeImg = order.source_logo_url
                ? `<img class="mmi-marketplace-badge" src="${safeUrl(order.source_logo_url)}" alt="${escapeHtml(order.source_label)}" title="${escapeHtml(order.source_label)}">`
                : '';
            const sourceBadge = sourceBadgeImg && order.source_order_url
                ? `<a href="${safeUrl(order.source_order_url)}" target="_blank" rel="noopener noreferrer" title="View on ${escapeHtml(order.source_label)}">${sourceBadgeImg}</a>`
                : sourceBadgeImg;
            // Lives here (not a shared toolbar button) so it appears per
            // row, scoped to the exact order whose margin is shown directly
            // below it — no reliance on "whichever row happens to be
            // expanded" indirection. Only orders with WC status "processing"
            // can be fulfilled via this action.
            const fulfillBtn = order.status === 'processing'
                ? `<button type="button" class="button button-primary button-small mmi-x-po-fulfill-btn" data-order-id="${order.order_id}">${order.fulfilled_po ? 'Re-fulfill' : 'Fulfill'}</button>`
                : '';
            const guestConvert = order.is_guest ? this.renderGuestConvertHtml(order) : '';
            const header = `<div class="mmi-x-po-detail-header">
                <strong>Order #${escapeHtml(order.order_number)}</strong>
                <span class="mmi-badge ${statusClass}">${escapeHtml(order.status)}</span>
                ${sourceBadge}
                ${fulfillBtn}
            </div>
            ${guestConvert}`;

            const rows = results.map(({ item, data, message }) => {
                if (!data) {
                    return `<tr><td colspan="4"><span class="mmi-x-po-detail-product">${escapeHtml(item.sku)}</span> — ${escapeHtml(item.product)}<div class="mmi-x-po-detail-sub">${escapeHtml(message)}</div></td></tr>`;
                }

                // dealer_price never reflects an active promotion — it's the
                // SKU's normal, non-promotional cost and stays fixed while a
                // promo is running. promo_price (from the separate
                // /promotions/ endpoint) is the real, lower cost basis
                // whenever one is active — using dealer_price here would
                // make a genuinely profitable promo-priced sale look like a
                // loss (or vice versa for an unusual promo shape).
                const costBasis = data.is_promo_active ? parseFloat(data.promo_price) : parseFloat(data.dealer);
                const dealerCost = costBasis || 0;
                const customerPaid = parseFloat(item.line_total) || 0;
                const totalCost = dealerCost * (item.qty || 1);
                const margin = customerPaid - totalCost;
                const marginClass = margin > 0 ? 'success' : 'error';
                const marginLabel = margin >= 0
                    ? `+${margin.toFixed(2)} ${escapeHtml(data.currency || '')}`
                    : `−${Math.abs(margin).toFixed(2)} ${escapeHtml(data.currency || '')}`;
                const costLabel = data.is_promo_active
                    ? `${dealerCost.toFixed(2)} ${escapeHtml(data.currency || '')} <span class="mmi-badge success" title="${escapeHtml(data.promotion_name || '')}">🏷️ Promo</span>`
                    : `${dealerCost.toFixed(2)} ${escapeHtml(data.currency || '')}`;
                // cost_source distinguishes a real cost-at-purchase snapshot
                // (captured the moment this order's XChange PO was actually
                // placed — see MMI_Xchange_Order_Sync::preview_product())
                // from today's live re-lookup, which is all that's
                // available for an order placed before this feature shipped
                // or fulfilled via the manual CCSA portal scrape. Margin
                // math above is identical either way — only the badge and
                // its tooltip differ.
                const costBadge = data.cost_source === 'snapshot'
                    ? `<span class="mmi-x-cost-badge mmi-x-cost-badge--locked" title="Cost basis locked in at purchase time (${escapeHtml(data.cost_captured_at || 'unknown date')}).">🔒 Cost at purchase</span>`
                    : `<span class="mmi-x-cost-badge mmi-x-cost-badge--live" title="No cost-at-purchase snapshot for this order — showing today's live XChange price instead, which may differ from what this order actually cost.">⏱ Live estimate</span>`;
                const priceHistoryBtn = `<button type="button" class="button-link mmi-x-price-history-btn" data-sku="${escapeHtml(item.sku)}">📈 Price History</button>`;
                // "All 4 if applicable" — the two WC customer-facing figures
                // (regular/sale) and the two XChange cost figures
                // (dealer/promo) are independent pairs; only show a figure
                // when the underlying data actually has it, rather than
                // padding with zeroes.
                const refPricing = [
                    data.regular_price ? 'Regular ' + parseFloat(data.regular_price).toFixed(2) : '',
                    data.sale_price ? 'Promo Price (customer) ' + parseFloat(data.sale_price).toFixed(2) : '',
                    data.is_promo_active && data.dealer ? 'COG (non-promo) ' + parseFloat(data.dealer).toFixed(2) : '',
                    data.map ? 'MAP ' + parseFloat(data.map).toFixed(2) : '',
                    data.msrp ? 'MSRP ' + parseFloat(data.msrp).toFixed(2) : '',
                ].filter(Boolean).join(' · ');
                const statusNote = data.status ? `Catalog status: ${escapeHtml(data.status)}` : '';
                const productName = escapeHtml(data.product || item.product);
                const productLabel = data.edit_url
                    ? `<a href="${safeUrl(data.edit_url)}" target="_blank" rel="noopener">${productName}</a>`
                    : productName;
                const productThumb = data.product_image_url
                    ? `<a href="${safeUrl(data.edit_url) || '#'}" target="_blank" rel="noopener" class="mmi-x-po-detail-thumb" title="Edit product">
                        <img src="${safeUrl(data.product_image_url)}" alt="">
                    </a>`
                    : '';
                const brandThumb = data.brand_thumbnail_url
                    ? `<a href="${safeUrl(data.brand_edit_url) || '#'}" target="_blank" rel="noopener" class="mmi-x-po-detail-brand-thumb" title="Edit brand: ${escapeHtml(data.brand_name)}">
                        <img src="${safeUrl(data.brand_thumbnail_url)}" alt="">
                    </a>`
                    : '';
                const brandLabel = data.brand_name
                    ? `<div class="mmi-x-po-detail-brand">
                        ${brandThumb}
                        ${data.brand_edit_url ? `<a href="${safeUrl(data.brand_edit_url)}" target="_blank" rel="noopener">${escapeHtml(data.brand_name)}</a>` : escapeHtml(data.brand_name)}
                    </div>`
                    : '';

                return `<tr>
                    <td class="mmi-x-po-td-product">
                        <div class="mmi-x-po-detail-product-cell">
                            ${productThumb}
                            <div>
                                <div class="mmi-x-po-detail-product">${productLabel}</div>
                                <div class="mmi-x-po-detail-sub">${escapeHtml(item.sku)}${statusNote ? ' · ' + statusNote : ''}</div>
                                ${brandLabel}
                                ${priceHistoryBtn}
                            </div>
                        </div>
                    </td>
                    <td class="mmi-x-po-td-qty">${item.qty || 1}</td>
                    <td class="mmi-x-po-td-cost">
                        ${costLabel}
                        <div class="mmi-x-po-detail-sub">${costBadge}</div>
                        ${refPricing ? `<div class="mmi-x-po-detail-sub">${refPricing}</div>` : ''}
                    </td>
                    <td class="mmi-x-po-td-paid">${customerPaid.toFixed(2)} ${escapeHtml(order.currency || '')}</td>
                    <td class="mmi-x-po-td-margin"><span class="mmi-badge ${marginClass}">${marginLabel}</span></td>
                </tr>`;
            }).join('');

            const table = `<table class="mmi-x-po-detail-table">
                <thead>
                    <tr><th>Product</th><th>Qty</th><th>Our Cost</th><th>Customer Paid</th><th>Margin</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;

            const note = '<p class="mmi-x-timestamp">🔒 Cost at purchase = the real XChange price captured when this order was actually fulfilled. ⏱ Live estimate = today\'s XChange price, used only when no purchase-time snapshot exists for this order. Either way, margin is our cost × qty vs. what the customer paid (using the active promo price when one applies) and does not account for XChange fees, taxes, or shipping.</p>';
            const refreshBtn = `<button type="button" class="button button-small mmi-x-po-detail-refresh" data-order-id="${order.order_id}">↺ Refresh</button>`;

            const emailHistory = this.renderEmailHistoryHtml(order);
            const resendBtn = order.fulfilled_po
                ? `<button type="button" class="button button-small mmi-x-po-resend-email" data-order-id="${order.order_id}">✉️ Resend Email</button>`
                : '';

            // Manually-placed XChange order, entered against this specific
            // WC order — scoped entirely to this row (data-order-id), never
            // shared with any other part of the tab. This is the only place
            // manual entry exists: recording a real-world portal purchase
            // against the WC order it's fulfilling, not a general-purpose
            // order-independent placement tool.
            //
            // Three separate actions share these two fields, per an explicit
            // design decision (2026-09-20): linking the PO to this order and
            // emailing the customer used to be one bundled action (the old
            // "Sync →"/"Review & Email →" button) — confusing, since a
            // false-start click meant either both happened or neither did,
            // with no way to do just one. Now:
            //   - "🔗 Link Only" — writes _mmi_xchange_fulfilled_po and
            //     nothing else (POOrdersPanel.linkOnly() →
            //     mmi_xchange_link_order → MMI_Xchange_Fulfillment_Queue::mark_fulfilled()
            //     directly). No external XChange call at all — a plain,
            //     fast, session-safe local write.
            //   - "✉️ Email Only" — opens the Email Customer modal and
            //     sends, same as before, but the link is NOT written on
            //     send (PlaceOrderTab.syncManualOrder(order, po, auth,
            //     false) → revealEmailPanel's linkOnSend=false → the hidden
            //     #mmi-x-po-email-link-on-send field tells
            //     send_fulfillment_email() to skip mark_fulfilled()).
            //   - "🔗✉️ Link & Email →" — the original combined behavior,
            //     kept as an explicit third option for the common case
            //     where both are wanted at once (syncManualOrder(..., true)).
            //
            // "Find Candidate PO" is scoped to an unfulfilled order only —
            // once fulfilled_po is set there's nothing left to find. See
            // MMI_Xchange_Po_Linker::find_candidate_for_order(): checks
            // this plugin's own synced PO history first (free), then falls
            // back to a live, on-demand XChange REST search anchored to
            // this order's own date — the only way to reach a PO from
            // before the local table's synced window. Never writes or
            // emails anything itself; a hit just fills the same PO/Auth
            // fields below for one of the three buttons above to use.
            const findCandidateBtn = order.fulfilled_po ? '' : `
                <div class="mmi-x-field-row">
                    <button type="button" class="button mmi-x-po-row-find-candidate" data-order-id="${order.order_id}">🔍 Find Candidate PO</button>
                    <span class="mmi-loading mmi-hidden mmi-x-po-row-find-candidate-spinner" data-order-id="${order.order_id}"></span>
                </div>
                <div class="mmi-x-po-row-find-candidate-result" data-order-id="${order.order_id}"></div>`;

            // Pre-filled from the order's already-known values (blank for a
            // never-linked order) — NOT left empty even though this same
            // data already shows in the PO#/Auth# columns above. Two real
            // bugs otherwise: (1) right after a successful Link Only, this
            // row re-renders from a fresh fetch() and these are plain new
            // <input> elements with no value set, so a just-linked order
            // looks exactly like an unlinked one at the one place the
            // admin is actually looking, and (2) clicking Email Only right
            // after — a completely reasonable next step, needing no retype
            // — read that same blank field and threw "Enter the PO..."
            // even though the order was, in fact, already linked to one.
            const existingPo   = escapeHtml(order.fulfilled_po || '');
            const existingAuth = escapeHtml(order.xchange_auth || '');
            // Explicit, separate from the pre-filled inputs above — the
            // inputs alone are still just "whatever's currently typed" to
            // a quick glance, easy to mistake for stale/unsaved text
            // rather than confirmed saved state. This line only means one
            // thing.
            const linkedNote = existingPo
                ? `<div class="mmi-badge success">✓ Currently linked to PO ${existingPo}</div>`
                : '';

            const manualSync = `<div class="mmi-x-po-manual-sync">
                <p class="description">Already placed this order yourself on the XChange portal? Enter the PO/Auth here, then choose whether to link it to WooCommerce order #${order.order_number}, email the customer, or both. XChange's API can't look up license/download/support for orders it didn't place itself — copy those from XChange's own order confirmation page into the email fields on the next screen if nothing auto-fetches.</p>
                ${linkedNote}
                ${findCandidateBtn}
                <div class="mmi-label-grid mmi-label-grid--start mmi-x-field-grid">
                    <div class="mmi-x-field-row mmi-label-grid-row">
                        <label>PO / Transaction #</label>
                        <input type="text" class="mmi-x-input mmi-x-po-row-manual-po" data-order-id="${order.order_id}" value="${existingPo}" />
                    </div>
                    <div class="mmi-x-field-row mmi-label-grid-row">
                        <label>Auth # (optional)</label>
                        <input type="text" class="mmi-x-input mmi-x-po-row-manual-auth" data-order-id="${order.order_id}" value="${existingAuth}" />
                    </div>
                </div>
                <div class="mmi-x-field-row mmi-x-po-row-actions">
                    <button type="button" class="button mmi-x-po-row-link-only" data-order-id="${order.order_id}">🔗 Link Only</button>
                    <span class="mmi-loading mmi-hidden mmi-x-po-row-link-only-spinner" data-order-id="${order.order_id}"></span>
                    <button type="button" class="button mmi-x-po-row-email-only" data-order-id="${order.order_id}">✉️ Email Only</button>
                    <button type="button" class="button button-primary mmi-x-po-row-manual-sync" data-order-id="${order.order_id}">🔗✉️ Link &amp; Email →</button>
                </div>
            </div>`;

            return `<div class="mmi-x-po-detail-body">${header}${table}${note}${refreshBtn}${emailHistory}${resendBtn}${manualSync}</div>`;
        },

        // "Link Only" — writes _mmi_xchange_fulfilled_po directly via
        // MMI_Xchange_Fulfillment_Queue::mark_fulfilled() and nothing else.
        // No XChange API/portal call of any kind (unlike Email Only/Link &
        // Email, which fetch license/download/support through the Email
        // Customer modal) — a fast, local-only write, which is the whole
        // point of splitting this out: recording the link no longer has to
        // risk an XChange.com session just because linking and emailing
        // used to be bundled into one action.
        linkOnly(order, po, $btn) {
            const item = order.items[0] || {};
            if (!window.confirm(`Link order #${order.order_number} to PO ${po}?\n\nNo email will be sent — use "Email Only" or "Link & Email →" for that.`)) {
                return;
            }

            const $spinner = $(`.mmi-x-po-row-link-only-spinner[data-order-id="${order.order_id}"]`);
            $btn.prop('disabled', true);
            $spinner.removeClass('mmi-hidden');

            ajax('mmi_xchange_link_order', { order_id: order.order_id, po, sku: item.sku || '' }).done((res) => {
                if (!res.success) {
                    window.alert(res.data?.message || 'Could not link this order.');
                    return;
                }
                // #mmi-x-po-queue-status is shared page state, not owned by
                // this module — PlaceOrderTab is where setQueueStatus() and
                // every other write to it already live (fulfillFromQueue(),
                // sendEmail()).
                PlaceOrderTab.setQueueStatus(res.data.message, 'success');
                this.fetch();
            }).fail(() => {
                window.alert('Network error linking this order.');
            }).always(() => {
                $btn.prop('disabled', false);
                $spinner.addClass('mmi-hidden');
            });
        },

        // Client side of MMI_Xchange_Po_Linker::find_candidate_for_order() —
        // checks this plugin's own synced PO history first, then (only if
        // that came up empty) makes one live, on-demand XChange REST call
        // scoped to this order's own date. Never links or emails anything
        // itself: a hit just fills the same PO/Auth inputs the admin would
        // otherwise type in by hand, for the existing "Review & Email →"
        // button (PlaceOrderTab.syncManualOrder()) to use exactly as before.
        findCandidate(orderId) {
            const $btn     = $(`.mmi-x-po-row-find-candidate[data-order-id="${orderId}"]`);
            const $spinner = $(`.mmi-x-po-row-find-candidate-spinner[data-order-id="${orderId}"]`);
            const $result  = $(`.mmi-x-po-row-find-candidate-result[data-order-id="${orderId}"]`);

            $btn.prop('disabled', true);
            $spinner.removeClass('mmi-hidden');
            $result.removeClass('mmi-badge success warning').text('Checking local records, then XChange if needed (this can take a few seconds)…');

            ajax('mmi_xchange_find_po_candidate', { order_id: orderId }).done((res) => {
                if (!res.success || !res.data?.match) {
                    $result.text(res.data?.message || 'No candidate found — checked local records and a live XChange search for this order\'s date. Enter the PO manually, or check XChange\'s own order history directly.');
                    return;
                }

                const m = res.data.match;
                $(`.mmi-x-po-row-manual-po[data-order-id="${orderId}"]`).val(m.matched_po);
                $(`.mmi-x-po-row-manual-auth[data-order-id="${orderId}"]`).val(m.matched_auth || '');

                const basisLabel = m.basis === 'reverb_order_number' ? 'this order\'s Reverb order #' : 'this order\'s own order #';
                const matchTypeLabel = m.match_type === 'exact' ? 'an exact match' : 'a partial match';

                if (m.confidence === 'high') {
                    $result.addClass('mmi-badge success').text(`Found PO ${m.matched_po} — ${matchTypeLabel} on ${basisLabel}, and the SKU agrees. Filled in below — review and click "Review & Email →".`);
                } else {
                    const reason = m.sku_match
                        ? 'the reference is only a partial match, not exact'
                        : 'this PO\'s own SKU doesn\'t match this order\'s item';
                    $result.addClass('mmi-badge warning').text(`Possible match: PO ${m.matched_po} (${matchTypeLabel} on ${basisLabel}) — but ${reason}. Filled in below, but verify it on XChange before clicking "Review & Email →".`);
                }
            }).fail(() => {
                $result.text('Network error running the search.');
            }).always(() => {
                $btn.prop('disabled', false);
                $spinner.addClass('mmi-hidden');
            });
        },

        // Whether the fulfillment email actually left the server (and, if
        // not, why) previously lived only in MMI_Logger's log file — not
        // visible from here, which is exactly where an admin looks after a
        // customer says they never received it. Shows the last 3 attempts
        // (server already caps history at 10); newest first.
        renderEmailHistoryHtml(order) {
            const history = order.email_history || [];
            if (history.length === 0) {
                return order.fulfilled_po
                    ? '<div class="mmi-x-po-email-history"><p class="description">No fulfillment email on record for this order yet.</p></div>'
                    : '';
            }

            const rows = history.slice().reverse().map((h) => {
                const badgeClass = h.success ? 'success' : 'error';
                const badgeLabel = h.success ? '✓ Sent' : '✗ Failed';
                const failureNote = !h.success && h.message ? `<div class="mmi-x-po-detail-sub">${escapeHtml(h.message)}</div>` : '';
                return `<div class="mmi-x-po-email-history-row">
                    <span class="mmi-badge ${badgeClass}">${badgeLabel}</span>
                    <span>${escapeHtml(h.sent_at)} → ${escapeHtml(h.to)}</span>
                    ${failureNote}
                </div>`;
            }).join('');

            return `<div class="mmi-x-po-email-history">
                <p class="description"><strong>Fulfillment email history</strong></p>
                ${rows}
            </div>`;
        },

        // Guest orders can't be told apart from a marketplace relay address
        // by name/email alone — the pairing of this widget with the source
        // badge above is the actual signal. Posts to mmi-hub's existing
        // wp_ajax_mmi_guest_customer_convert handler (see the click handler
        // in init()) rather than duplicating its create-or-link logic here.
        //
        // The automatic email-request flow (MMI_Reverb_Email_Request_Manager)
        // only ever runs once, synchronously, at order-import time
        // (create_order()) — never retroactively. So by the time an order is
        // old enough to appear in this queue, that one shot has already
        // happened: either it produced a real status (sent/converted/
        // no_conversation/failed), or it declined (global setting was off,
        // or the plugin wasn't active yet) and left no status at all. A
        // *currently* on `reverb_automation_active` flag says nothing about
        // whether THIS order's one shot actually fired — an admin can flip
        // that setting on today and every pre-existing relayed guest order
        // would still show empty status forever, since nothing will ever
        // retry them automatically. Gating on `email_request_status` being
        // non-empty (real evidence the automation actually engaged this
        // order) instead of the live toggle value is what correctly routes
        // a pre-existing order straight to the manual form below, rather
        // than a collapsed "will happen automatically" panel that's really
        // a permanently broken promise for that order. New orders imported
        // while the toggle is on always have a real status by the time
        // they're visible here (create_order() saves the order after
        // maybe_request_email() writes its status), so this doesn't delay
        // showing genuinely-automated orders their read-only status panel.
        renderGuestConvertHtml(order) {
            if (order.looks_relayed && order.email_request_status) {
                return this.renderAutoEmailStatusHtml(order);
            }

            const relayWarning = order.looks_relayed
                ? `<p class="description mmi-x-guest-warning">⚠ This looks like a marketplace relay address, not the buyer's real email. Ask the buyer for their real email through the marketplace's own messaging first.</p>`
                : '';

            return `<div class="mmi-x-guest-convert">
                <p class="description">This order has no linked customer account. Current billing email: <strong>${escapeHtml(order.customer_email || '(none)')}</strong></p>
                ${relayWarning}
                ${this.renderManualGuestFormHtml(order)}
            </div>`;
        },

        // The actual email input + welcome-email checkbox + submit button —
        // shared by the plain fallback panel above and the collapsed manual
        // override inside renderAutoEmailStatusHtml() below, so there's one
        // copy of this markup, not two.
        renderManualGuestFormHtml(order) {
            const prefill = escapeHtml(order.looks_relayed ? '' : (order.customer_email || ''));

            // Automates this exact widget: sends the buyer the same "please
            // reply with your real email" Reverb message the automatic flow
            // sends (MMI_Xchange_Guest_Email_Request::send()), instead of
            // requiring the admin to already know the address. Shown
            // whenever mmi-reverb-integration is active, independent of
            // whether the automatic flow's own setting is on — useful for
            // an order that predates this feature, or a retry after a
            // failed attempt (see the auto-status panel's error states
            // above, whose "Convert manually instead" link reveals this
            // same form).
            const requestEmailBtn = order.reverb_available
                ? `<button type="button" class="button button-small mmi-x-guest-request-email-btn" data-order-id="${order.order_id}">📨 Request Email via Reverb</button>
                <p class="description">Asks the buyer to reply with their real email — no need to already know it.</p>
                <p class="description mmi-x-guest-convert-or">— or, if you already have it —</p>`
                : '';

            return `${requestEmailBtn}
                <div class="mmi-x-field-row">
                    <label>Customer's real email</label>
                    <input type="email" class="mmi-x-input mmi-x-guest-convert-email" placeholder="customer@example.com" value="${prefill}" />
                </div>
                <label class="mmi-x-inline-label">
                    <input type="checkbox" class="mmi-x-guest-convert-welcome" />
                    Send WooCommerce welcome email to new account
                </label>
                <button type="button" class="button button-small mmi-x-guest-convert-btn" data-order-id="${order.order_id}">👤 Link to Customer</button>`;
        },

        // Status readout for mmi-reverb-integration's automatic flow — the
        // moment a valid reply arrives the plugin converts the order on its
        // own, so this panel is read-only progress, not an action for the
        // admin to take, with a collapsed manual override for the (rare)
        // case the automation needs to be bypassed.
        AUTO_EMAIL_STATUS_META: {
            sent:            { label: '📧 Email requested via Reverb — awaiting buyer reply', cls: 'warning' },
            converted:       { label: '✅ Buyer confirmed their real email automatically', cls: 'success' },
            no_conversation: { label: '⚠ Could not locate a Reverb conversation — needs manual attention', cls: 'error' },
            failed:          { label: '⚠ Automatic email request failed to send — needs manual attention', cls: 'error' },
            '':              { label: '⏳ Will request the buyer\'s real email automatically', cls: '' },
        },

        renderAutoEmailStatusHtml(order) {
            const meta = this.AUTO_EMAIL_STATUS_META[order.email_request_status || ''] || this.AUTO_EMAIL_STATUS_META[''];
            const dateNote = (order.email_request_status === 'converted' && order.email_request_converted_at)
                ? `<div class="mmi-x-po-detail-sub">Confirmed ${escapeHtml(order.email_request_converted_at)}</div>`
                : (order.email_request_sent_at ? `<div class="mmi-x-po-detail-sub">Requested ${escapeHtml(order.email_request_sent_at)}</div>` : '');
            const sharedNote = (order.email_request_status === 'sent' && order.email_request_shared_with)
                ? `<div class="mmi-x-po-detail-sub">One request for this buyer's orders — sent on order #${escapeHtml(order.email_request_shared_with)}</div>`
                : '';

            return `<div class="mmi-x-guest-convert mmi-x-guest-convert--auto">
                <p class="description">This order has no linked customer account. Current billing email: <strong>${escapeHtml(order.customer_email || '(none)')}</strong></p>
                <span class="mmi-badge ${meta.cls}">${meta.label}</span>
                ${dateNote}
                ${sharedNote}
                <p class="description">Handled automatically by the Reverb integration (Settings → Guest Email Auto-Request &amp; Reverb Summary). <a href="#" class="mmi-x-guest-convert-manual-toggle">Convert manually instead</a></p>
                <div class="mmi-x-guest-convert-manual-form mmi-hidden">
                    ${this.renderManualGuestFormHtml(order)}
                </div>
            </div>`;
        },

        // Small icon shown next to the marketplace badge in the row's own
        // Source column — visible without expanding the row, so "does this
        // order need attention" is answerable at a glance across the whole
        // table. Returns null (no icon) when there's nothing to flag: a
        // non-guest order, or a guest order whose email never looked
        // relayed to begin with (nothing this feature would ever act on).
        renderEmailStatusIcon(order) {
            if (!order.is_guest || !order.looks_relayed) {
                return null;
            }

            const ICON_META = {
                sent:            { icon: '📧', cls: 'mmi-x-email-icon--pending', title: 'Awaiting buyer\'s real email — requested via Reverb message' },
                converted:       { icon: '✅', cls: 'mmi-x-email-icon--done', title: 'Buyer\'s real email confirmed automatically via Reverb reply' },
                no_conversation: { icon: '⚠', cls: 'mmi-x-email-icon--error', title: 'Could not locate a Reverb conversation to ask automatically — needs manual attention' },
                failed:          { icon: '⚠', cls: 'mmi-x-email-icon--error', title: 'Automatic email request failed to send — needs manual attention' },
            };

            // Same reasoning as renderGuestConvertHtml() above: a real
            // email_request_status is the only trustworthy evidence the
            // one-shot automatic flow actually engaged this order. The live
            // `reverb_automation_active` toggle value doesn't tell us that —
            // a pre-existing order left with no status never gets a second
            // automatic attempt no matter what the setting currently says.
            const meta = (order.email_request_status && ICON_META[order.email_request_status])
                || { icon: '🔔', cls: 'mmi-x-email-icon--pending', title: 'Guest order with a relayed email — needs the buyer\'s real email requested/confirmed manually (expand the row)' };

            return $('<span>').addClass('mmi-x-email-icon ' + meta.cls).attr('title', meta.title).text(meta.icon);
        },

        filteredOrders() {
            const term = ($(SELECTORS.poOrdersSearch).val() || '').toLowerCase();
            const unfulfilledOnly = $(SELECTORS.poOrdersUnfulfilledOnly).is(':checked');

            return this.allOrders.filter((o) => {
                if (unfulfilledOnly && o.fulfilled_po) {
                    return false;
                }
                if (term) {
                    const skus = o.items.map((i) => i.sku).join(' ');
                    const haystack = `${o.order_number} ${skus} ${o.customer_name} ${o.customer_email}`.toLowerCase();
                    if (haystack.indexOf(term) === -1) {
                        return false;
                    }
                }
                return true;
            });
        },

        applyFilters() {
            this.renderRows(this.filteredOrders());
        },

        // 10 data columns total (Order/Date/Status/Customer/Item/Order Total —
        // WC-sourced — plus PO#/License/Auth#/CCSA — Xchange-sourced). The
        // former Fulfilled/Action columns were removed in favor of a
        // per-row color accent (see rowStateClass()) and a Fulfill button
        // inside each expanded row's own detail header (see
        // renderDetailHtml()) — both so the table fits 100% width without
        // horizontal scrolling. Kept in sync with the <thead> in
        // tab-orders.php's Fulfillment Queue section.
        COLSPAN: 11,

        // Replaces the removed "Fulfilled" column — a colored left-edge
        // accent on the row instead of a dedicated column showing the same
        // fulfilled_po/"Pending" fact a second time (the PO # column
        // already shows the real PO number once fulfilled).
        rowStateClass(o) {
            if (o.fulfilled_po) {
                return 'mmi-x-po-row--fulfilled';
            }
            if (o.status === 'processing') {
                return 'mmi-x-po-row--pending';
            }
            return '';
        },

        renderRows(rows) {
            const $tbody = $(SELECTORS.poOrdersTbody);
            if (rows.length === 0) {
                $tbody.html(`<tr><td colspan="${this.COLSPAN}" class="mmi-x-empty">No matching orders.</td></tr>`);
                return;
            }

            $tbody.empty();
            rows.forEach((o) => {
                const isExpanded = this.expandedOrderId === o.order_id;

                const $tr = $('<tr class="mmi-x-po-order-row">').addClass(this.rowStateClass(o)).data('order', o);
                const $orderCell = $('<td data-source="wc">').append($('<a>').attr({ href: o.edit_url, target: '_blank', rel: 'noopener' }).text('#' + o.order_number));
                $tr.append($orderCell);

                // Marketplace badge + email-confirmation status icon share one
                // dedicated column, side by side, rather than the badge
                // living inside the Order cell and the guest-convert panel
                // expanding the row's full height below it — keeps a row
                // that needs attention no taller than one that doesn't.
                const $sourceCell = $('<td data-source="wc">');
                const $sourceInner = $('<div class="mmi-x-po-source-cell">');
                if (o.source_logo_url) {
                    const $badgeImg = $('<img class="mmi-marketplace-badge mmi-x-po-order-badge">').attr({ src: o.source_logo_url, alt: o.source_label, title: o.source_label });
                    if (o.source_order_url) {
                        // Excluded from the row's own click-to-expand handler by its
                        // existing 'a, a *, button, button *' target guard — see the
                        // delegated .mmi-x-po-order-row click handler below.
                        $sourceInner.append(
                            $('<a>').attr({ href: o.source_order_url, target: '_blank', rel: 'noopener noreferrer', title: `View on ${o.source_label}` }).append($badgeImg)
                        );
                    } else {
                        $sourceInner.append($badgeImg);
                    }
                }
                const $emailIcon = this.renderEmailStatusIcon(o);
                if ($emailIcon) {
                    $sourceInner.append($emailIcon);
                }
                $sourceCell.append($sourceInner);
                $tr.append($sourceCell);

                $tr.append($('<td data-source="wc">').text(o.date || ''));

                const statusClass = STATUS_BADGE_CLASS[o.status] || '';
                $tr.append($('<td data-source="wc">').append($('<span>').addClass('mmi-badge ' + statusClass).text(o.status)));

                const $customerCell = $('<td data-source="wc">');
                const customerLabel = o.customer_name || o.customer_email || '';
                if (o.customer_edit_url) {
                    $customerCell.append($('<div>').append(
                        $('<a>').attr({ href: o.customer_edit_url, target: '_blank', rel: 'noopener' }).text(customerLabel)
                    ));
                } else {
                    $customerCell.append($('<div>').text(customerLabel));
                }
                if (o.customer_email && o.customer_name !== o.customer_email) {
                    $customerCell.append($('<div class="mmi-x-po-detail-sub">').text(o.customer_email));
                }
                $tr.append($customerCell);

                const firstItem = o.items[0] || {};
                let itemText = `${firstItem.sku || ''} — ${firstItem.product || ''} (×${firstItem.qty || 1})`;
                if (o.items.length > 1) {
                    itemText += ` +${o.items.length - 1} more`;
                }
                // The flex layout lives on an INNER wrapper, not the <td>
                // itself — a real <td> with display:flex stops stretching
                // to the row's full height the way a plain table-cell does,
                // leaving an unstyled gap below short content whenever a
                // sibling cell (e.g. Customer, with its email + marketplace
                // icon) makes the row taller. Keeping the <td> a plain cell
                // is what makes its data-source background actually fill
                // the whole row.
                const $itemCell = $('<td data-source="wc">');
                const $itemInner = $('<div class="mmi-x-po-item-cell">');
                if (firstItem.image_url) {
                    $itemInner.append($('<img class="mmi-x-po-item-thumb">').attr({ src: firstItem.image_url, alt: '' }));
                }
                $itemInner.append($('<span>').text(itemText));
                $itemCell.append($itemInner);
                $tr.append($itemCell);

                $tr.append($('<td data-source="wc">').text(o.total ? parseFloat(o.total).toFixed(2) + ' ' + (o.currency || '') : ''));

                $tr.append($('<td data-source="xchange">').text(o.fulfilled_po || '—'));
                $tr.append($('<td data-source="xchange">').text(o.xchange_license || '—'));
                $tr.append($('<td data-source="xchange">').text(o.xchange_auth || '—'));

                // Plain colored text, not a badge chip — a chip's own
                // background would sit differently than this cell's shared
                // Xchange-group tint (data-source="xchange"), the one thing
                // every other cell in this group (PO#/License/Auth#) is
                // plain text against. See rowStateClass()'s doc comment —
                // fulfillment state itself is now the row's left accent,
                // this is just "is it presently sitting in CCSA."
                const $ccsaCell = $('<td data-source="xchange">');
                if (o.fulfilled_po && o.xchange_in_ccsa) {
                    $ccsaCell.append($('<span class="mmi-x-po-ccsa-flag">').text('⚠ In CCSA'));
                } else {
                    $ccsaCell.text('—');
                }
                $tr.append($ccsaCell);

                $tbody.append($tr);

                if (isExpanded) {
                    const $detailTr = $('<tr class="mmi-x-po-detail-row">');
                    const $cell = $(`<td colspan="${this.COLSPAN}">`).html(this.detailCache[o.order_id] || `<p class="mmi-x-empty mmi-x-empty--loading">${emptyLoadingHtml('Loading…')}</p>`);
                    // Soft-reveal only the pass that actually renders real detail
                    // content (the loading placeholder above isn't wrapped in
                    // .mmi-x-po-detail-body, so .find() below no-ops for it and
                    // the flag survives to the next, real-content render pass).
                    // Consuming (clearing) the flag only once it's actually used
                    // — not unconditionally after every render — is what stops a
                    // search/sort/filter keystroke from replaying the animation
                    // on an already-open row.
                    if (this.justExpandedOrderId === o.order_id) {
                        const $body = $cell.find('.mmi-x-po-detail-body');
                        if ($body.length) {
                            $body.addClass('mmi-animate-fadeIn');
                            this.justExpandedOrderId = null;
                        }
                    }
                    $detailTr.append($cell);
                    $tbody.append($detailTr);
                }
            });
        },
    };

    /* ── Place Order tab ───────────────────────────────────────────────────── */

    const PlaceOrderTab = {
        // Last live catalog lookup (from Preview or auto-fetched on order
        // placement) — kept so sendEmail() can include sku/code/our_sku/
        // price/currency without a redundant AJAX round-trip.
        lastPreview: null,

        // Set by fulfillFromQueue() when the admin clicks "Fulfill" on a
        // Recent Orders row; consumed by revealEmailPanel() once the order
        // is actually placed, then cleared so a later manual (non-queue)
        // order placement doesn't inherit stale customer/order context.
        pendingOrder: null,

        // Incremented on every revealEmailPanel() open — see its use there.
        emailPanelGen: 0,

        init() {
            $(SELECTORS.poPreviewBtn).on('click', () => this.preview());
            $(SELECTORS.poSubmit).on('click', () => this.submit());
            $(SELECTORS.diagSuccessBtn).on('click', () => this.runDiagnostic('success'));
            $(SELECTORS.diagFailureBtn).on('click', () => this.runDiagnostic('failure'));
            $(SELECTORS.poEmailSend).on('click', () => this.sendEmail());
            $(SELECTORS.poEmailTest).on('click', () => this.testEmail());
        },

        // Gives #mmi-x-po-queue-status real visual weight instead of a
        // plain unstyled line — 'busy'/'success'/'blocked'/'error' each get
        // their own color/border so a hard safety block (state 'blocked')
        // reads unmistakably differently from an ordinary in-progress or
        // success message, not just as differently-worded plain text.
        setQueueStatus(html, state) {
            $(SELECTORS.poQueueStatus)
                .removeClass(QUEUE_STATUS_CLASSES.join(' '))
                .addClass(state ? 'mmi-x-status--' + state : '')
                .html(html);
        },

        // Places the live B2B order for the first XChange line item on a WC
        // order picked from the Recent Orders panel — entirely independent
        // of the standalone SKU/Qty form above (this deliberately never
        // touches #mmi-x-po-sku/#mmi-x-po-qty/#mmi-x-po-result — those are
        // for placing orders unrelated to any WC order). Remembers the
        // order's customer + ID so revealEmailPanel() can address the email
        // to the actual customer once the order succeeds. On failure, the
        // admin still has the manual-sync option in this same row's expanded
        // detail panel (see POOrdersPanel.renderDetailHtml()).
        fulfillFromQueue(order, $rowBtn) {
            const item = order.items[0] || {};
            const sku = item.sku || '';
            const qty = item.qty || 1;

            if (!sku) {
                window.alert('No XChange SKU found on this order.');
                return;
            }

            const multiItemNote = order.items.length > 1
                ? `\n\nNote: this order has ${order.items.length} XChange items — this will only fulfill ${sku}. Click "Fulfill" again afterward for the others.`
                : '';
            if (!confirm(`Place a B2B purchase order for ${sku} (qty ${qty}) to fulfill order #${order.order_number}?${multiItemNote}\n\nThis will charge your XChange account.`)) {
                return;
            }

            this.pendingOrder = order;

            if ($rowBtn) {
                $rowBtn.prop('disabled', true).addClass('mmi-is-loading');
            }
            this.setQueueStatus(`Placing order for #${order.order_number}…`, 'busy');

            this.placeOrder(sku, qty, order.order_id).done((res) => {
                // Already purchased earlier (e.g. the email step was closed
                // without sending) — the server refused a second purchase
                // and handed back the existing PO; continue to the email.
                if (!res.success && res.data?.blocked_reason === 'already_placed') {
                    this.setQueueStatus(escapeHtml(res.data.message), 'success');
                    this.autoFulfill(order, sku, res.data.po_number, '');
                    return;
                }
                if (!res.success) {
                    let msg = escapeHtml(res.data?.message || 'Order failed.');
                    if (res.data?.manual_url) {
                        msg += ` <a href="${safeUrl(res.data.manual_url)}" target="_blank" rel="noopener">Open XChange →</a>`;
                    }
                    msg += ' Expand this order\'s row to sync it manually instead.';
                    // 'external_source' is a hard safety stop, not an ordinary
                    // failure (out of stock, network error) — the whole point
                    // is preventing a duplicate real-money XChange purchase
                    // for an order already sold on Reverb/another marketplace,
                    // so this gets a blocking alert() in addition to the
                    // persistent red banner. No XChange API call was made.
                    if (res.data?.blocked_reason === 'external_source') {
                        this.setQueueStatus(msg, 'blocked');
                        window.alert(
                            `Fulfillment blocked for order #${order.order_number}:\n\n` +
                            `${res.data.message}\n\n` +
                            'No XChange order was placed — nothing was charged.'
                        );
                    } else {
                        this.setQueueStatus(msg, 'error');
                    }
                    return;
                }
                this.setQueueStatus(`Order placed for #${escapeHtml(order.order_number)}. PO: <strong>${escapeHtml(res.data.po_number)}</strong>${res.data.auth ? ' / Auth: <strong>' + escapeHtml(res.data.auth) + '</strong>' : ''}`, 'success');
                this.autoFulfill(order, sku, res.data.po_number, res.data.auth);
            }).fail(() => {
                this.setQueueStatus(`Network error placing order for #${order.order_number} — check whether it actually went through on XChange before retrying.`, 'error');
            }).always(() => {
                if ($rowBtn) {
                    $rowBtn.prop('disabled', false).removeClass('mmi-is-loading');
                }
            });
        },

        // The step after "Fulfill" placed (or reused) a PO: the server emails
        // the customer and completes the order when everything is known
        // (MMI_Xchange_Auto_Fulfillment). 'pending' = license not posted
        // yet, sent later by a background retry. 'review' (or a failure)
        // opens the Email Customer modal as before. pendingOrder is still
        // set from fulfillFromQueue() for revealEmailPanel() to consume.
        autoFulfill(order, sku, poNumber, auth) {
            const openModal = (html) => {
                if (html) {
                    this.setQueueStatus(html, 'busy');
                }
                this.pendingOrder = order;
                this.revealEmailPanel(sku, poNumber, auth);
            };

            this.setQueueStatus(`PO <strong>${escapeHtml(poNumber)}</strong> placed for #${escapeHtml(order.order_number)} — emailing the customer…`, 'busy');
            ajax('mmi_xchange_auto_fulfill', { order_id: order.order_id, sku }).done((res) => {
                const data = res.data || {};
                if (res.success && data.status === 'sent') {
                    this.pendingOrder = null;
                    this.setQueueStatus(escapeHtml(data.message), 'success');
                    POOrdersPanel.fetch();
                    return;
                }
                if (res.success && data.status === 'pending') {
                    this.pendingOrder = null;
                    this.setQueueStatus(escapeHtml(data.message), 'busy');
                    POOrdersPanel.fetch();
                    return;
                }
                openModal(data.message ? `${escapeHtml(data.message)} Review and send below.` : '');
            }).fail(() => {
                openModal(`PO <strong>${escapeHtml(poNumber)}</strong> placed — couldn't send the email automatically (network error). Review and send below.`);
            });
        },

        // Opens the Email Customer modal, pre-filled with a PO/Auth the
        // admin already placed themselves on the XChange portal (no
        // place_order API call at all) — the sole purpose of the
        // manual-sync fields inside a Recent Orders row's expanded detail
        // panel. Never reachable outside that context: there is
        // deliberately no standalone/order-independent manual-entry path —
        // an admin placing an XChange order unrelated to any WC order just
        // does that directly on xchangeb2b.com.
        //
        // linkOnSend controls whether a successful send also writes
        // _mmi_xchange_fulfilled_po (see sendEmail() below and
        // MMI_Xchange_Fulfillment_Queue::mark_fulfilled(), which sendEmail()
        // only calls when this flag made it through as true) — the
        // "Link & Email →" row button passes true, "Email Only" passes
        // false. Linking with no email at all doesn't go through this
        // method or this modal — see POOrdersPanel.linkOnly() instead.
        // Does NOT itself record anything as fulfilled — despite this
        // method's name (kept for now to avoid touching its one call site
        // for a pure rename), nothing is written here regardless of
        // linkOnSend; that only happens inside sendEmail()'s own AJAX
        // success handler, once something has actually been sent. This
        // used to also show an immediate "synced manually" success
        // message right here, before the modal even opened — a false
        // positive if the admin closed the modal without sending. Removed;
        // the modal opening (with its own "Fulfilling order #X for Y"
        // context line) is the correct and sufficient feedback for this
        // step.
        syncManualOrder(order, po, auth, linkOnSend = true) {
            const item = order.items[0] || {};
            this.pendingOrder = order;
            this.revealEmailPanel(item.sku || '', po, auth, linkOnSend);
        },

        // Re-opens the Email Customer panel for an order that's already
        // been fulfilled — e.g. the customer says they never received it.
        // Reuses the exact same panel/send flow as a fresh fulfillment
        // rather than a separate one-click "just resend" action, so license/
        // download/support get a fresh lookup and the admin can correct the
        // recipient address before sending again. Safe to reuse: sending
        // again calls mark_fulfilled() (idempotent — rewrites the same PO to
        // the same SKU) and the order-completion check only fires once
        // (guarded on the order not already being completed/cancelled/
        // refunded), so nothing double-fires for an already-completed order.
        resendEmail(order) {
            const item = order.items[0] || {};
            this.pendingOrder = order;
            this.revealEmailPanel(item.sku || '', order.fulfilled_po, order.xchange_auth);
        },

        preview() {
            const sku = $(SELECTORS.poSku).val().trim();
            if (!sku) {
                $(SELECTORS.poResult).text('Enter an XChange SKU first.');
                return;
            }

            const $btn = $(SELECTORS.poPreviewBtn);
            $btn.prop('disabled', true);
            $(SELECTORS.poPreviewSpinner).removeClass('mmi-hidden');

            ajax('mmi_xchange_preview_product', { sku }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.poPreview).removeClass('mmi-hidden').html(`<p>${escapeHtml(res.data?.message || 'SKU not found.')}</p>`);
                    return;
                }
                this.lastPreview = res.data;
                $(SELECTORS.poPreview).removeClass('mmi-hidden').html(this.renderPreviewHtml(res.data, true));
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.poPreviewSpinner).addClass('mmi-hidden');
            });
        },

        renderPreviewHtml(p, live) {
            // Admin-facing preview (Place Order tab + Email Customer modal
            // summary) — safe to show our actual cost, including promo
            // pricing, unlike the customer-facing email. dealer_price never
            // reflects an active promotion (see AGENTS.md's "Fulfillment
            // Queue Margin Ignored Active XChange Promotions" incident), so
            // this must show promo_price as "Our Cost" whenever one is
            // active, not the always-present-but-sometimes-wrong dealer figure.
            const ourCost = p.is_promo_active
                ? `${parseFloat(p.promo_price).toFixed(2)} ${p.currency || ''} 🏷️ Promo${p.promotion_name ? ' — ' + p.promotion_name : ''}`
                : (p.dealer ? parseFloat(p.dealer).toFixed(2) + ' ' + (p.currency || '') : '');

            const rows = [
                ['Product', p.product],
                ['Code', p.code],
                ['Our SKU', p.our_sku],
                ['Vendor', p.vendor_name],
                ['Our Cost', ourCost],
                ['Non-Promo Cost', p.is_promo_active && p.dealer ? parseFloat(p.dealer).toFixed(2) + ' ' + (p.currency || '') : ''],
                ['MAP', p.map ? parseFloat(p.map).toFixed(2) + ' ' + (p.currency || '') : ''],
                ['MSRP', p.msrp ? parseFloat(p.msrp).toFixed(2) + ' ' + (p.currency || '') : ''],
                ['Status', p.status],
            ].filter(([, value]) => value);

            const dl = rows.map(([label, value]) => `<dt>${label}</dt><dd>${escapeHtml(value)}</dd>`).join('');
            // Only on the live Place Order tab preview, not the Email
            // Customer modal's read-only summary reuse of this same
            // renderer (live=false there) — a second modal launcher inside
            // an already-open modal would be out of place.
            const priceHistoryBtn = live && p.sku
                ? `<button type="button" class="button-link mmi-x-price-history-btn" data-sku="${escapeHtml(p.sku)}">📈 Price History</button>`
                : '';
            const note = live ? '<p class="mmi-x-timestamp">Live catalog lookup — just fetched, not cached.</p>' : '';
            return `<dl>${dl}</dl>${priceHistoryBtn}${note}`;
        },

        // Core AJAX call shared by the manual submit() below and
        // fulfillFromQueue() above — callers own their own button/spinner
        // state and success/failure handling.
        //
        // orderId (optional): when this call is fulfilling a specific WC
        // order (fulfillFromQueue()), the server uses it to verify the
        // order wasn't imported from Reverb (or another external
        // marketplace) before allowing a real XChange purchase — see
        // MMI_Xchange_Checkout::is_externally_sourced(). The standalone
        // Place Order tab (submit(), below) has no order to check against
        // and correctly omits it.
        placeOrder(sku, qty, orderId) {
            const data = { sku, qty };
            if (orderId) {
                data.order_id = orderId;
            }
            return ajax('mmi_xchange_place_order', data);
        },

        submit() {
            const sku = $(SELECTORS.poSku).val().trim();
            const qty = parseInt($(SELECTORS.poQty).val(), 10) || 1;

            if (!sku) {
                $(SELECTORS.poResult).text('Enter an XChange SKU first.');
                return;
            }
            if (!confirm(`Place a B2B purchase order for ${sku} (qty ${qty})?\n\nThis will charge your XChange account.`)) {
                return;
            }

            // A manual placement (not launched from a Recent Orders row)
            // isn't tied to any WC order — clear any stale queue context.
            this.pendingOrder = null;

            const $btn = $(SELECTORS.poSubmit);
            $btn.prop('disabled', true);
            $(SELECTORS.poSpinner).removeClass('mmi-hidden');
            $(SELECTORS.poResult).text('');

            this.placeOrder(sku, qty).done((res) => {
                if (!res.success) {
                    let msg = escapeHtml(res.data?.message || 'Order failed.');
                    if (res.data?.manual_url) {
                        msg += ` <a href="${safeUrl(res.data.manual_url)}" target="_blank" rel="noopener">Open XChange →</a>`;
                    }
                    $(SELECTORS.poResult).html(msg);
                    return;
                }
                $(SELECTORS.poResult).html(`Order placed. PO: <strong>${escapeHtml(res.data.po_number)}</strong>${res.data.auth ? ' / Auth: <strong>' + escapeHtml(res.data.auth) + '</strong>' : ''}`);
                this.revealEmailPanel(sku, res.data.po_number, res.data.auth);
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.poSpinner).addClass('mmi-hidden');
            });
        },

        // On-demand end-to-end test of the finalize-timing hook wiring (see
        // MMI_Xchange_Finalize_Diagnostic) — a real reserve/finalize
        // round-trip against XChange's own test vendor via a throwaway,
        // auto-trashed WC order. 'success' exercises the normal path;
        // 'failure' deliberately voids the reservation first to prove a
        // failed finalize() aborts the order instead of silently
        // completing it.
        runDiagnostic(scenario) {
            const confirmMsg = scenario === 'failure'
                ? 'Run the FAILURE-path diagnostic?\n\nReserves against XChange\'s test vendor, deliberately voids the reservation, then confirms the order correctly fails to complete. Creates and trashes a throwaway order.'
                : 'Run the SUCCESS-path diagnostic?\n\nReserves and finalizes a real (test-vendor, no charge) XChange transaction through a throwaway order, then trashes it.';
            if (!confirm(confirmMsg)) {
                return;
            }

            $(SELECTORS.diagSuccessBtn).prop('disabled', true);
            $(SELECTORS.diagFailureBtn).prop('disabled', true);
            $(SELECTORS.diagSpinner).removeClass('mmi-hidden');
            $(SELECTORS.diagResult).empty();

            ajax('mmi_xchange_run_finalize_diagnostic', { scenario }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.diagResult).html(`<p>${escapeHtml(res.data?.message || 'Diagnostic failed to run.')}</p>`);
                    return;
                }
                $(SELECTORS.diagResult).html(this.renderDiagnosticHtml(res.data));
            }).fail(() => {
                $(SELECTORS.diagResult).html('<p>Diagnostic request failed — check the browser console and server error log.</p>');
            }).always(() => {
                $(SELECTORS.diagSuccessBtn).prop('disabled', false);
                $(SELECTORS.diagFailureBtn).prop('disabled', false);
                $(SELECTORS.diagSpinner).addClass('mmi-hidden');
            });
        },

        renderDiagnosticHtml(data) {
            const summary = data.success
                ? '✅ All checks passed'
                : '❌ Diagnostic failed — see the failing step below';
            const stepsHtml = (data.steps || []).map((step) => {
                const cls = step.ok ? 'mmi-x-diag-step--ok' : 'mmi-x-diag-step--fail';
                const icon = step.ok ? 'dashicons-yes-alt' : 'dashicons-no-alt';
                return `<div class="mmi-x-diag-step ${cls}"><span class="dashicons ${icon}"></span><span><strong>${escapeHtml(step.label)}</strong><br>${escapeHtml(step.detail)}</span></div>`;
            }).join('');
            const orderNote = data.order_id ? `<p class="mmi-x-timestamp">Test order #${data.order_id} — trashed.</p>` : '';

            return `<p class="mmi-x-diag-summary">${summary}</p>${stepsHtml}${orderNote}`;
        },

        // linkOnSend: whether a successful Send Email should also write
        // _mmi_xchange_fulfilled_po (see sendEmail() below) — true for
        // every existing caller (fulfillFromQueue(), resendEmail(),
        // syncManualOrder()'s "Link & Email →" path, PoLinkerPanel's
        // "Use this PO →") except syncManualOrder()'s "Email Only" path,
        // which explicitly passes false. Defaulted to true rather than
        // adding it to every call site, since linking-on-send was this
        // modal's only behavior before the split existed.
        revealEmailPanel(sku, poNumber, auth, linkOnSend = true) {
            const fromOrder = this.pendingOrder;
            this.pendingOrder = null;

            // Bumped on every open — each async fill below captures its own
            // value and drops its response if the panel has since been
            // reopened for a different order. Without this, a slow lookup
            // for order A landing after the panel was reopened for order B
            // would fill B's (now freshly cleared) fields with A's product
            // name/license key.
            const panelGen = ++this.emailPanelGen;
            const isStale = () => panelGen !== this.emailPanelGen;

            // EVERY per-order field must be reset here, not just some — the
            // async fills below only write into empty fields (so an admin's
            // mid-load edit isn't clobbered), which means any field NOT
            // cleared here silently keeps the previous order's value. That
            // was the live 2026-09-22 bug: software name + logo weren't in
            // this list, so fulfilling order A then order B
            // in one session sent order B's email
            // with order A's name/image, while every field that WAS reset
            // (PO/SKU/license) was correct.
            $(SELECTORS.poEmailSoftware).val('');
            $(SELECTORS.poEmailLogo).val('');
            $(SELECTORS.poEmailCode).val('');
            $(SELECTORS.poEmailOurSku).val('');
            $(SELECTORS.poEmailVendor).val('');
            $(SELECTORS.poEmailProductId).val('');
            $(SELECTORS.poEmailPrice).val('');
            $(SELECTORS.poEmailCurrency).val('');
            $(SELECTORS.poEmailPoNumber).val(poNumber || '');
            $(SELECTORS.poEmailOrderId).val(fromOrder ? fromOrder.order_id : '');
            $(SELECTORS.poEmailAuth).val(auth || '');
            $(SELECTORS.poEmailSku).val(sku || '');
            $(SELECTORS.poEmailLicense).val('');
            $(SELECTORS.poEmailDownload).val('');
            $(SELECTORS.poEmailSupport).val('');
            $(SELECTORS.poEmailResult).text('');
            $(SELECTORS.poEmailSummary).addClass('mmi-hidden').empty();
            $(SELECTORS.poEmailFallbackNote).addClass('mmi-hidden').empty();
            // Only meaningful for an order-linked send at all — the
            // standalone Place Order tab (fromOrder null) has no order to
            // link regardless of this flag, and send_fulfillment_email()
            // already independently requires order_id > 0 before it will
            // ever call mark_fulfilled().
            const willLink = !!fromOrder && linkOnSend;
            $(SELECTORS.poEmailLinkOnSend).val(willLink ? '1' : '0');

            MMIModal.open(SELECTORS.poEmailPanel);

            if (fromOrder) {
                $(SELECTORS.poEmailTo).val(fromOrder.customer_email || '');
                $(SELECTORS.poEmailName).val(fromOrder.customer_name || '');
                $(SELECTORS.poEmailContext).removeClass('mmi-hidden')
                    .text(`Fulfilling order #${fromOrder.order_number} for ${fromOrder.customer_name || fromOrder.customer_email}`);
                // States explicitly what THIS send will do to the order —
                // exactly the ambiguity that prompted splitting Link/Email
                // apart in the first place; leaving it unstated here would
                // just move the old confusion from the button label into
                // the modal instead of resolving it.
                $(SELECTORS.poEmailLinkNote)
                    .removeClass('mmi-hidden success warning')
                    .addClass(willLink ? 'success' : 'warning')
                    .text(
                        willLink
                            ? `Sending will also link order #${fromOrder.order_number} to this PO.`
                            : `Email only — sending will NOT link order #${fromOrder.order_number} to this PO.`
                    );
            } else {
                $(SELECTORS.poEmailContext).addClass('mmi-hidden').empty();
                $(SELECTORS.poEmailLinkNote).addClass('mmi-hidden').empty();
            }

            // Visible progress while the two chains below are in flight —
            // the license/download/support chain in particular can take a
            // while and, per fetchOrderDocument()'s own note, may fall
            // through to a real CCSA portal login that ends an XChange.com
            // browser session. Previously this ran with zero feedback,
            // making a multi-second wait (and any resulting session logout)
            // look like nothing was happening. Hidden once both chains below
            // have settled.
            this.emailLoadingPending = 0;
            const startEmailLoading = () => {
                this.emailLoadingPending++;
                $(SELECTORS.poEmailLoading).removeClass('mmi-hidden');
            };
            const finishEmailLoading = () => {
                this.emailLoadingPending = Math.max(0, this.emailLoadingPending - 1);
                if (this.emailLoadingPending === 0) {
                    $(SELECTORS.poEmailLoading).addClass('mmi-hidden');
                }
            };
            // Per-field spinners — each group reflects the specific fetch
            // that actually populates it, not the same blanket "loading"
            // state applied to every row regardless of which call is still
            // in flight for it.
            const setFieldRowLoading = (inputSelector, isLoading) => {
                $(inputSelector).each(function () {
                    const $row = $(this).closest('.mmi-x-field-row');
                    $row.toggleClass('mmi-x-field-row--loading', isLoading);
                    $row.find('.mmi-x-field-loading').toggleClass('mmi-hidden', !isLoading);
                });
            };

            // Best-effort auto-fill of software name + logo from the WC product
            // catalog (matched by SKU) — leaves fields blank/editable if no
            // match. Chains the order-document fetch (license/download/
            // support) after this settles rather than firing it concurrently,
            // keeping simultaneous panel-open AJAX at 2 (this + the catalog
            // preview lookup below) per the Server Load AJAX fan-out limit.
            startEmailLoading();
            setFieldRowLoading(SELECTORS.poEmailLookupFieldRows, true);
            ajax('mmi_xchange_lookup_product', { sku }).done((res) => {
                if (isStale()) {
                    return;
                }
                if (res.success && res.data.found) {
                    if (!$(SELECTORS.poEmailSoftware).val()) {
                        $(SELECTORS.poEmailSoftware).val(res.data.name || '');
                    }
                    if (!$(SELECTORS.poEmailLogo).val()) {
                        $(SELECTORS.poEmailLogo).val(res.data.logo_url || '');
                    }
                }
            }).always(() => {
                if (isStale()) {
                    return;
                }
                setFieldRowLoading(SELECTORS.poEmailLookupFieldRows, false);
                if (poNumber) {
                    setFieldRowLoading(SELECTORS.poEmailDocumentFieldRows, true);
                    this.fetchOrderDocument(poNumber, isStale, () => {
                        setFieldRowLoading(SELECTORS.poEmailDocumentFieldRows, false);
                        finishEmailLoading();
                    });
                } else {
                    finishEmailLoading();
                }
            });

            // Auto-fill code/our-sku/vendor/price/currency from the XChange
            // catalog too — reuse the Preview lookup if the admin already ran
            // one for this SKU, otherwise fetch it now.
            //
            // Price is the one field this catalog lookup must NOT be trusted
            // for when a real WC/Reverb order is being fulfilled: `dealer` is
            // this account's own wholesale cost, not what the customer paid —
            // and if the SKU is on an active XChange promotion, dealer cost
            // doesn't even reflect that, let alone whatever the customer
            // actually paid on Reverb. When fromOrder is set, the real sold
            // price already lives on that order's matching line item
            // (order.items[].line_total, sourced from the actual WC order
            // total) and takes priority.
            const realPrice = fromOrder
                ? (fromOrder.items.find((i) => i.sku === sku) || {}).line_total
                : undefined;

            if (this.lastPreview && this.lastPreview.sku === sku) {
                this.applyPreviewToEmail(this.lastPreview, realPrice);
            } else {
                startEmailLoading();
                ajax('mmi_xchange_preview_product', { sku }).done((res) => {
                    if (res.success) {
                        this.lastPreview = res.data;
                        if (!isStale()) {
                            this.applyPreviewToEmail(res.data, realPrice);
                        }
                    }
                }).always(() => {
                    if (!isStale()) {
                        finishEmailLoading();
                    }
                });
            }
        },

        // Silent best-effort auto-fill of license/download/support from
        // XChange for this PO. MMI_Xchange_Order_Sync::fetch_order_document()
        // tries the safe REST API first, then automatically falls back to a
        // CCSA/Invoice History portal lookup when that came up empty — no
        // separate confirmed step here by design, since CCSA covers
        // virtually every recent order. That fallback CAN end any
        // XChange.com session open in a browser; leaves fields
        // blank/editable if even that has nothing.
        //
        // Once a real order has settled out of CCSA, XChange itself stops
        // exposing download_url/support_url anywhere this plugin can reach
        // — confirmed directly, not assumed (see
        // MMI_Xchange_Order_Sync::learn_vendor_fallback_docs()'s docblock).
        // For that case, fetch_order_document() falls back to this
        // vendor's own last-known-good value and flags it via
        // download_url_is_fallback/support_url_is_fallback — surfaced here
        // as a visible caution rather than silently presented as if it
        // were confirmed for this specific order.
        fetchOrderDocument(po, isStale, onDone) {
            ajax('mmi_xchange_fetch_order_document', { po }).done((res) => {
                if (!res.success || isStale()) {
                    return;
                }
                if (!$(SELECTORS.poEmailLicense).val() && res.data.license_key) {
                    $(SELECTORS.poEmailLicense).val(res.data.license_key);
                }
                if (!$(SELECTORS.poEmailDownload).val() && res.data.download_url) {
                    $(SELECTORS.poEmailDownload).val(res.data.download_url);
                }
                if (!$(SELECTORS.poEmailSupport).val() && res.data.support_url) {
                    $(SELECTORS.poEmailSupport).val(res.data.support_url);
                }
                if (!$(SELECTORS.poEmailAuth).val() && res.data.auth) {
                    $(SELECTORS.poEmailAuth).val(res.data.auth);
                }
                this.setFallbackDocNote(res.data.download_url_is_fallback, res.data.support_url_is_fallback);
            }).always(() => {
                if (onDone && !isStale()) {
                    onDone();
                }
            });
        },

        // download_url_is_fallback/support_url_is_fallback (see
        // MMI_Xchange_Order_Sync::apply_vendor_fallback_docs()) mean the
        // value filled in above is this vendor's own last-known-good
        // default, not something confirmed for this specific PO — XChange
        // stops exposing these fields once an order settles, so this is
        // often the only thing available at all for an order that's more
        // than a few weeks old. Shown as a standing caution, not a
        // one-time toast, since the admin may not notice the fields
        // filling in during fetchOrderDocument()'s async chain and should
        // see this before clicking Send either way.
        setFallbackDocNote(downloadIsFallback, supportIsFallback) {
            const $note = $(SELECTORS.poEmailFallbackNote);
            if (!downloadIsFallback && !supportIsFallback) {
                $note.addClass('mmi-hidden').empty();
                return;
            }

            const which = downloadIsFallback && supportIsFallback
                ? 'Download URL and Support URL are'
                : (downloadIsFallback ? 'Download URL is' : 'Support URL is');
            $note.removeClass('mmi-hidden').addClass('warning').text(
                `⚠ ${which} this vendor's last-known default, not confirmed for this specific order — XChange no longer exposes this once an order has settled. Verify before sending, or paste the real value from XChange's own confirmation page.`
            );
        },

        // realPrice: the actual amount the customer paid (from the
        // fulfilled WC/Reverb order's own line item), when this email is
        // for a real sale. Falls back to p.dealer — XChange's own wholesale
        // cost — only for the standalone Place Order tab flow, which has no
        // associated sale to read a real price from.
        applyPreviewToEmail(p, realPrice) {
            $(SELECTORS.poEmailCode).val(p.code || '');
            $(SELECTORS.poEmailOurSku).val(p.our_sku || '');
            $(SELECTORS.poEmailVendor).val(p.vendor_name || '');
            $(SELECTORS.poEmailProductId).val(p.product_id || '');
            $(SELECTORS.poEmailPrice).val(realPrice !== undefined ? realPrice : (p.dealer || ''));
            $(SELECTORS.poEmailCurrency).val(p.currency || '');
            if (!$(SELECTORS.poEmailSoftware).val() && p.product) {
                $(SELECTORS.poEmailSoftware).val(p.product);
            }
            $(SELECTORS.poEmailSummary).removeClass('mmi-hidden').html(this.renderPreviewHtml(p, false));
        },

        // Summarizes what the server did with the WC order after a
        // fulfillment email send — mirrors MMI_Xchange_Ajax::send_fulfillment_email()'s
        // three outcomes: fully done (order marked completed), other
        // XChange items on this order still pending, or the order also has
        // non-XChange items so status was deliberately left alone.
        describeFulfillmentOutcome(orderId, resData) {
            const completion = resData.completion || {};
            if (resData.order_status === 'completed') {
                return `Order #${orderId} fulfilled and marked completed.`;
            }
            if (completion.has_non_xchange_items) {
                return `Order #${orderId} fulfilled — order has other (non-XChange) items, so its status was left as "${resData.order_status || 'unchanged'}".`;
            }
            if (completion.xchange_item_count > 1) {
                return `Order #${orderId}: ${completion.fulfilled_count} of ${completion.xchange_item_count} XChange item(s) fulfilled — status left at "${resData.order_status || 'unchanged'}" until all are done.`;
            }
            return `Order #${orderId} fulfilled.`;
        },

        // Shared by sendEmail() and testEmail() — `to` is intentionally
        // read from the field even for a test send; the server ignores it
        // and substitutes the site admin address, since a test's whole
        // point is that it can never reach whatever's actually typed there.
        buildEmailData() {
            return {
                to:                $(SELECTORS.poEmailTo).val().trim(),
                customer_name:     $(SELECTORS.poEmailName).val().trim(),
                software_name:     $(SELECTORS.poEmailSoftware).val().trim(),
                software_logo_url: $(SELECTORS.poEmailLogo).val().trim(),
                license_key:       $(SELECTORS.poEmailLicense).val().trim(),
                download_url:      $(SELECTORS.poEmailDownload).val().trim(),
                support_url:       $(SELECTORS.poEmailSupport).val().trim(),
                po_number:         $(SELECTORS.poEmailPoNumber).val(),
                order_id:          $(SELECTORS.poEmailOrderId).val(),
                auth:              $(SELECTORS.poEmailAuth).val(),
                sku:               $(SELECTORS.poEmailSku).val(),
                code:              $(SELECTORS.poEmailCode).val(),
                our_sku:           $(SELECTORS.poEmailOurSku).val(),
                product_id:        $(SELECTORS.poEmailProductId).val(),
                vendor_name:       $(SELECTORS.poEmailVendor).val(),
                price:             $(SELECTORS.poEmailPrice).val(),
                currency:          $(SELECTORS.poEmailCurrency).val(),
                link_on_send:      $(SELECTORS.poEmailLinkOnSend).val(),
            };
        },

        sendEmail() {
            const data = this.buildEmailData();
            if (!data.to) {
                $(SELECTORS.poEmailResult).text('Enter the customer\'s email address first.');
                return;
            }

            const $btn = $(SELECTORS.poEmailSend);
            $btn.prop('disabled', true);
            $(SELECTORS.poEmailSpinner).removeClass('mmi-hidden');
            $(SELECTORS.poEmailResult).text('');

            ajax('mmi_xchange_send_fulfillment_email', data).done((res) => {
                if (!res.success) {
                    $(SELECTORS.poEmailResult).text(res.data?.message || 'Send failed.');
                    return;
                }

                // Only an order-linked send (Recent Orders panel / Fulfill
                // flow) has a WC order status to report — the standalone
                // Place Order tab send has no order_id and keeps the old
                // inline-confirmation behavior, since there's nothing to
                // close/refresh.
                if (!data.order_id) {
                    $(SELECTORS.poEmailResult).text('✓ Email sent to ' + data.to + '.');
                    return;
                }

                $(SELECTORS.poEmailOrderId).val('');
                MMIModal.close(SELECTORS.poEmailPanel);
                // describeFulfillmentOutcome() assumes mark_fulfilled() ran
                // — true whenever link_on_send made it through as '1', per
                // send_fulfillment_email()'s own gate. For an Email Only
                // send (link_on_send '0'), nothing was linked, so saying
                // "fulfilled" here would be the exact same false-success
                // bug the old "Sync →" button had, just moved to a
                // different trigger.
                const outcome = data.link_on_send === '1'
                    ? this.describeFulfillmentOutcome(data.order_id, res.data)
                    : `Email sent for order #${data.order_id} (Email Only — not linked to a PO).`;
                this.setQueueStatus(outcome, 'success');
                POOrdersPanel.fetch();
            }).fail(() => {
                $(SELECTORS.poEmailResult).text('Network error.');
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.poEmailSpinner).addClass('mmi-hidden');
            });
        },

        // Sends this exact email content to the site admin only — never the
        // customer, never touches order state or fulfillment-email history
        // (enforced server-side, not just by omission here). Lets an admin
        // check rendering/content before committing to a real send.
        testEmail() {
            const data = this.buildEmailData();
            data.is_test = 1;

            const $btn = $(SELECTORS.poEmailTest);
            $btn.prop('disabled', true);
            $(SELECTORS.poEmailSpinner).removeClass('mmi-hidden');
            $(SELECTORS.poEmailResult).text('');

            ajax('mmi_xchange_send_fulfillment_email', data).done((res) => {
                $(SELECTORS.poEmailResult).text(
                    (res.data && res.data.message) || (res.success ? 'Test email sent.' : 'Test send failed.')
                );
            }).fail(() => {
                $(SELECTORS.poEmailResult).text('Network error.');
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.poEmailSpinner).addClass('mmi-hidden');
            });
        },
    };

    /* ── Combined fulfillment ──────────────────────────────────────────────────
       One customer, several unfulfilled software items (across one or more
       open orders — e.g. a Reverb checkout split into separate orders, or two
       mannmade.us orders a day apart) → one PO per item, then ONE email.
       Grouping key is each row's customer_key (MMI_Software_Fulfillment::
       customer_key()); server side is MMI_Xchange_Ajax::place_order() →
       prepare_bundle() → send_bundle_email(). */
    const BUNDLE_OPEN_STATUSES = ['processing', 'on-hold'];
    const BUNDLE_MIN_ITEMS = 2;
    const BUNDLE_DELIVERY_FIELDS = ['license_key', 'download_url', 'support_url'];

    const BundleFulfillment = {
        groups: {},
        items: [],

        init() {
            if ($(SELECTORS.bundleGroups).length === 0) {
                return;
            }
            $(document).on('click', '.mmi-x-bundle-start', (e) => {
                const group = this.groups[$(e.currentTarget).data('group')];
                if (group) {
                    this.start(group, $(e.currentTarget));
                }
            });
            $(document).on('click', '.mmi-x-bundle-fetch', (e) => this.fetchLicense($(e.currentTarget)));
            $(document).on('input', '.mmi-x-bundle-field', (e) => {
                const $input = $(e.currentTarget);
                const item = this.items[$input.closest('.mmi-x-bundle-item').data('index')];
                if (item) {
                    item[$input.data('field')] = $input.val();
                }
            });
            $(SELECTORS.bundleSend).on('click', () => this.send(false));
            $(SELECTORS.bundleTest).on('click', () => this.send(true));
        },

        pendingItems(order) {
            return (order.items || []).filter((item) => item.fulfillment_status !== 'fulfilled');
        },

        renderGroups(orders) {
            const byKey = {};
            orders.forEach((order) => {
                if (!BUNDLE_OPEN_STATUSES.includes(order.status) || !order.customer_key) {
                    return;
                }
                const pending = this.pendingItems(order);
                if (pending.length === 0) {
                    return;
                }
                byKey[order.customer_key] = byKey[order.customer_key] || { key: order.customer_key, orders: [], items: [] };
                byKey[order.customer_key].orders.push(order);
                pending.forEach((item) => byKey[order.customer_key].items.push({ order, item }));
            });

            this.groups = {};
            const rows = Object.values(byKey)
                .filter((group) => group.items.length >= BUNDLE_MIN_ITEMS)
                .map((group) => {
                    this.groups[group.key] = group;
                    const latest = group.orders[0];
                    const orderLinks = group.orders
                        .map((o) => `<a href="${escapeHtml(o.edit_url)}" target="_blank" rel="noopener">#${escapeHtml(o.order_number)}</a>`)
                        .join(', ');
                    const titles = group.items.map(({ item }) => {
                        const qty = item.qty > 1 ? ` × ${item.qty}` : '';
                        const placed = item.placed_po ? ` <span class="mmi-badge success">PO ${escapeHtml(item.placed_po)}</span>` : '';
                        return `<li>${escapeHtml(item.product)}${qty}${placed}</li>`;
                    }).join('');
                    return `<div class="mmi-x-bundle-group">`
                        + `<div class="mmi-x-bundle-group-main">`
                        + `<strong>${escapeHtml(latest.customer_name)}</strong>`
                        + `<span class="mmi-x-bundle-group-meta">${group.items.length} unfulfilled items · orders ${orderLinks}</span>`
                        + `<ul class="mmi-x-bundle-group-titles">${titles}</ul>`
                        + this.renderEmailRequest(group)
                        + `</div>`
                        + `<button type="button" class="button button-primary mmi-x-bundle-start" data-group="${escapeHtml(group.key)}">📦 Fulfill together (${group.items.length})</button>`
                        + `</div>`;
                });

            const $wrap = $(SELECTORS.bundleGroups);
            if (rows.length === 0) {
                $wrap.prop('hidden', true).empty();
                return;
            }
            $wrap.html(`<p class="mmi-x-bundle-groups-title">Same customer, multiple software items — fulfill them in one email:</p>${rows.join('')}`)
                .prop('hidden', false);
        },

        // The group's real-email request, as ONE request: the buyer is asked
        // once for every order here (mmi-reverb-integration's
        // share_request_with_siblings() joins the rest to whichever order
        // the message went out on — same Reverb buyer ID this group is
        // keyed by). Nothing when no order is still a relayed guest.
        renderEmailRequest(group) {
            const relayed = group.orders.filter((o) => o.is_guest && o.looks_relayed);
            if (relayed.length === 0) {
                return '';
            }
            const covered = relayed.filter((o) => o.email_request_status === 'sent');
            const on = covered.length > 0 ? (covered[0].email_request_shared_with || covered[0].order_number) : '';
            if (covered.length === relayed.length) {
                return `<span class="mmi-x-bundle-group-meta">📧 Real email requested once for all ${relayed.length} order(s) — on order #${escapeHtml(on)}, awaiting reply</span>`;
            }
            const status = covered.length > 0
                ? `📧 Real email requested on order #${escapeHtml(on)} — covers ${covered.length} of ${relayed.length} relayed orders`
                : '⚠ Buyer\'s email is a marketplace relay';
            if (!relayed[0].reverb_available) {
                return `<span class="mmi-x-bundle-group-meta">${status} — confirm their real email first</span>`;
            }
            // Re-asking on the order that already has a conversation keeps
            // the buyer in one thread; the send then joins the rest.
            const target = covered.length > 0
                ? (relayed.find((o) => o.email_request_status === 'sent' && !o.email_request_shared_with) || relayed[0])
                : relayed[0];
            return `<span class="mmi-x-bundle-group-meta">${status}`
                + ` <button type="button" class="button button-small mmi-x-guest-request-email-btn" data-order-id="${escapeHtml(target.order_id)}">📨 Request Email via Reverb (all ${relayed.length} orders)</button></span>`;
        },

        start(group, $btn) {
            const toBuy = group.items.filter(({ item }) => !item.placed_po);
            const lines = group.items.map(({ order, item }) => {
                const already = item.placed_po ? ` — already purchased, PO ${item.placed_po}` : '';
                return `• ${item.sku} × ${item.qty} — ${item.product} (order #${order.order_number})${already}`;
            }).join('\n');
            const charge = toBuy.length > 0
                ? `\n\nThis will place ${toBuy.length} XChange purchase order(s) and charge your XChange account.`
                : '\n\nEvery item is already purchased — no new XChange charge.';
            if (!window.confirm(`Fulfill ${group.items.length} items for ${group.orders[0].customer_name} in one email?\n\n${lines}${charge}`)) {
                return;
            }

            $btn.prop('disabled', true).addClass('mmi-is-loading');
            const placed = [];
            const queue = group.items.slice();
            const stopWith = (html, state) => {
                PlaceOrderTab.setQueueStatus(html, state);
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
                POOrdersPanel.fetch();
            };

            // Sequential on purpose: XChange allows ~1 request per 12s
            // (MMI_API_Throttler paces each call server-side), and a failure
            // must stop before anything further is bought.
            const next = () => {
                const entry = queue.shift();
                if (!entry) {
                    $btn.prop('disabled', false).removeClass('mmi-is-loading');
                    this.open(group, placed);
                    return;
                }
                const { order, item } = entry;
                if (item.placed_po) {
                    placed.push({ order_id: order.order_id, sku: item.sku });
                    next();
                    return;
                }
                PlaceOrderTab.setQueueStatus(`Purchasing ${escapeHtml(item.sku)} for order #${escapeHtml(order.order_number)} (${placed.length + 1} of ${group.items.length})…`, 'busy');
                PlaceOrderTab.placeOrder(item.sku, item.qty || 1, order.order_id).done((res) => {
                    // already_placed: the server refused a second purchase —
                    // the recorded PO is reused, which is exactly what we want.
                    if (res.success || res.data?.blocked_reason === 'already_placed') {
                        placed.push({ order_id: order.order_id, sku: item.sku });
                        next();
                        return;
                    }
                    const state = res.data?.blocked_reason === 'external_source' ? 'blocked' : 'error';
                    stopWith(`Stopped at ${escapeHtml(item.sku)} (order #${escapeHtml(order.order_number)}): ${escapeHtml(res.data?.message || 'Order failed.')} The ${placed.length} item(s) before it are recorded — "Fulfill together" again resumes without buying them twice.`, state);
                }).fail(() => {
                    stopWith(`Network error purchasing ${escapeHtml(item.sku)} — check XChange before retrying. Items already purchased are recorded and won't be bought again.`, 'error');
                });
            };
            next();
        },

        // Every item is placed: try the combined email server-side first
        // (MMI_Xchange_Auto_Fulfillment::attempt_bundle()); anything it
        // can't vouch for opens the combined modal.
        open(group, placed) {
            PlaceOrderTab.setQueueStatus(`All ${placed.length} items purchased — emailing the customer…`, 'busy');
            ajax('mmi_xchange_auto_fulfill_bundle', { items: JSON.stringify(placed) }).done((res) => {
                const data = res.data || {};
                if (res.success && data.status === 'sent') {
                    const statuses = Object.entries(data.orders || {})
                        .map(([id, status]) => `#${escapeHtml(id)} ${escapeHtml(status || 'unknown')}`)
                        .join(', ');
                    PlaceOrderTab.setQueueStatus(`${escapeHtml(data.message)} Orders: ${statuses}.`, 'success');
                    POOrdersPanel.fetch();
                    return;
                }
                this.openModal(group, placed, data.message || '');
            }).fail(() => this.openModal(group, placed, ''));
        },

        openModal(group, placed, reason) {
            PlaceOrderTab.setQueueStatus(`All ${placed.length} items purchased — ${reason ? escapeHtml(reason) + ' ' : ''}review and send the combined email.`, 'success');
            ajax('mmi_xchange_prepare_bundle', { items: JSON.stringify(placed) }).done((res) => {
                if (!res.success) {
                    PlaceOrderTab.setQueueStatus(escapeHtml(res.data?.message || 'Could not prepare the combined email.'), 'error');
                    return;
                }
                this.items = res.data.items || [];

                const latest = group.orders[0];
                const relayed = group.orders.some((o) => o.looks_relayed);
                $(SELECTORS.bundleTo).val(latest.customer_email || '');
                $(SELECTORS.bundleName).val((latest.customer_name || '').split(' ')[0]);
                $(SELECTORS.bundleContext).text(`${this.items.length} items for ${latest.customer_name} — orders ${group.orders.map((o) => `#${o.order_number}`).join(', ')}`);
                $(SELECTORS.bundleRelayNote)
                    .toggleClass('mmi-hidden', !relayed)
                    .text(relayed ? '⚠ The customer email looks like a marketplace relay address and may not be deliverable — confirm the buyer\'s real email first.' : '');
                $(SELECTORS.bundleResult).empty();
                this.renderItems();
                MMIModal.open(SELECTORS.bundlePanel);
            }).fail(() => {
                PlaceOrderTab.setQueueStatus('Network error preparing the combined email — every item is purchased and recorded; click "Fulfill together" again to reopen it.', 'error');
            });
        },

        renderItems() {
            const field = (key, label, value, type) => `<label class="mmi-x-bundle-field-label">${label}`
                + `<input type="${type}" class="mmi-x-input mmi-x-bundle-field" data-field="${key}" value="${escapeHtml(value)}" /></label>`;

            $(SELECTORS.bundleItems).html(this.items.map((item, index) => {
                const logo = item.logo_url ? `<img class="mmi-x-bundle-item-logo" src="${escapeHtml(item.logo_url)}" alt="" />` : '';
                const fetchBtn = item.license_key
                    ? ''
                    : `<button type="button" class="button mmi-x-bundle-fetch" data-po="${escapeHtml(item.po_number)}">🔍 Fetch from XChange</button>`;
                const meta = [item.vendor_name, `order #${item.order_number}`, item.sku, `PO ${item.po_number}`].filter(Boolean).map(escapeHtml).join(' · ');
                return `<div class="mmi-x-bundle-item" data-index="${index}">`
                    + `<div class="mmi-x-bundle-item-head">${logo}<div class="mmi-x-bundle-item-title">`
                    + `<strong>${escapeHtml(item.name)}</strong><span class="mmi-x-bundle-item-meta">${meta}</span>`
                    + `</div>${fetchBtn}</div>`
                    + field('license_key', 'License Key', item.license_key, 'text')
                    + field('download_url', 'Download URL', item.download_url, 'url')
                    + field('support_url', 'Support URL or email', item.support_url, 'text')
                    + `</div>`;
            }).join(''));
        },

        // Same endpoint as the single-product modal's auto-fill, but only on
        // an explicit click — it can fall through to a CCSA portal login,
        // which must never be automated (Operational Continuity Rule 13).
        fetchLicense($btn) {
            const item = this.items[$btn.closest('.mmi-x-bundle-item').data('index')];
            if (!item) {
                return;
            }
            $btn.prop('disabled', true).addClass('mmi-is-loading');
            ajax('mmi_xchange_fetch_order_document', { po: $btn.data('po') }).done((res) => {
                if (!res.success) {
                    return;
                }
                BUNDLE_DELIVERY_FIELDS.forEach((key) => {
                    if (!item[key] && res.data[key]) {
                        item[key] = res.data[key];
                    }
                });
                this.renderItems();
            }).always(() => $btn.prop('disabled', false).removeClass('mmi-is-loading'));
        },

        send(isTest) {
            const to = $(SELECTORS.bundleTo).val().trim();
            if (!isTest && !to) {
                $(SELECTORS.bundleResult).text('Enter the customer\'s email address first.');
                return;
            }
            const missing = this.items.filter((item) => !item.license_key).map((item) => item.name);
            if (!isTest && missing.length && !window.confirm(`No license key for: ${missing.join(', ')}.\n\nSend anyway?`)) {
                return;
            }

            const $buttons = $(`${SELECTORS.bundleSend}, ${SELECTORS.bundleTest}`).prop('disabled', true);
            $(SELECTORS.bundleSpinner).removeClass('mmi-hidden');
            const payload = this.items.map((item) => ({
                order_id: item.order_id,
                sku: item.sku,
                po_number: item.po_number,
                license_key: item.license_key,
                download_url: item.download_url,
                support_url: item.support_url,
            }));

            ajax('mmi_xchange_send_bundle_email', {
                to,
                customer_name: $(SELECTORS.bundleName).val().trim(),
                items: JSON.stringify(payload),
                is_test: isTest ? 1 : 0,
            }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.bundleResult).text(res.data?.message || 'Send failed.');
                    return;
                }
                if (isTest) {
                    $(SELECTORS.bundleResult).text(res.data.message);
                    return;
                }
                MMIModal.close(SELECTORS.bundlePanel);
                const statuses = Object.entries(res.data.orders || {})
                    .map(([id, status]) => `#${escapeHtml(id)} ${escapeHtml(status || 'unknown')}`)
                    .join(', ');
                PlaceOrderTab.setQueueStatus(`Combined email sent to ${escapeHtml(to)} for ${this.items.length} items. Orders: ${statuses}.`, 'success');
                POOrdersPanel.fetch();
            }).fail(() => {
                $(SELECTORS.bundleResult).text('Network error — check the order notes before resending.');
            }).always(() => {
                $buttons.prop('disabled', false);
                $(SELECTORS.bundleSpinner).addClass('mmi-hidden');
            });
        },
    };

    /* ── Find Unlinked Orders (Orders tab section) ────────────────────────────
       Client side of MMI_Xchange_Po_Linker — see that class's docblock for
       the matching logic and, critically, why it never links or emails
       anything itself. "Use this PO →" does nothing but hand the match's
       sku/po/auth to PlaceOrderTab.revealEmailPanel(), the exact same
       modal a manual sync from the Fulfillment Queue above always opens —
       one write path, one email-send path, no matter which of the two
       finds the PO. ────────────────────────────────────────────────────── */
    const PoLinkerPanel = {
        matches: [],
        nextOffset: 0,
        hasMore: false,
        totalScanned: 0,

        init() {
            if ($(SELECTORS.poLinkerScanBtn).length === 0) {
                return;
            }

            initSortableTable($(SELECTORS.poLinkerTable), () => this.matches, (rows) => this.renderRows(rows));

            $(SELECTORS.poLinkerScanBtn).on('click', () => this.scan(true));
            $(SELECTORS.poLinkerScanMoreBtn).on('click', () => this.scan(false));

            $(document).on('click', '.mmi-x-po-link-use-btn', function (e) {
                e.stopPropagation();
                PoLinkerPanel.useMatch($(this).data('order-id'));
            });
        },

        setStatus(html, state) {
            $(SELECTORS.poLinkerStatus)
                .removeClass('mmi-x-status--busy mmi-x-status--success mmi-x-status--error')
                .addClass(state ? 'mmi-x-status--' + state : '')
                .html(html);
        },

        // reset=true is a fresh "Scan for Matches" click (clears whatever a
        // previous scan found); reset=false is "Scan Next Batch" continuing
        // from where the last call left off (this.nextOffset).
        scan(reset) {
            if (reset) {
                this.matches = [];
                this.nextOffset = 0;
                this.totalScanned = 0;
            }

            const $btn = reset ? $(SELECTORS.poLinkerScanBtn) : $(SELECTORS.poLinkerScanMoreBtn);
            $btn.prop('disabled', true).addClass('mmi-is-loading');
            this.setStatus(`${LABEL_LOADING}<span>Scanning for old unlinked orders…</span>`, 'busy');

            ajax('mmi_xchange_scan_po_matches', { offset: this.nextOffset }).done((res) => {
                if (!res.success) {
                    this.setStatus(res.data?.message || 'Scan failed.', 'error');
                    return;
                }

                this.matches = this.matches.concat(res.data.matches);
                this.nextOffset = res.data.next_offset;
                this.hasMore = res.data.has_more;
                this.totalScanned += res.data.scanned;

                this.renderRows(this.matches);
                $(SELECTORS.poLinkerScanMoreBtn).toggleClass('mmi-hidden', !this.hasMore);

                const foundText = this.matches.length === 1 ? '1 possible match' : `${this.matches.length} possible matches`;
                const moreText = this.hasMore ? ' More unlinked orders remain — click "Scan Next Batch" to continue.' : ' No unlinked orders remain to scan.';
                this.setStatus(`Scanned ${this.totalScanned} unlinked order(s) — found ${foundText}.${moreText}`, this.matches.length ? 'success' : '');
            }).fail(() => {
                this.setStatus('Network error running the scan.', 'error');
            }).always(() => {
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
            });
        },

        renderRows(rows) {
            const $tbody = $(SELECTORS.poLinkerTbody);
            if (rows.length === 0) {
                $tbody.html('<tr><td colspan="8" class="mmi-x-empty">No matches found in what\'s been scanned so far.</td></tr>');
                return;
            }
            $tbody.html(rows.map((m) => this.renderRow(m)).join(''));
        },

        renderRow(m) {
            const badge = m.confidence === 'high'
                ? '<span class="mmi-badge success">High</span>'
                : '<span class="mmi-badge warning">Needs review</span>';
            const basisLabel = m.basis === 'reverb_order_number' ? 'Reverb order #' : 'WC order #';
            const matchTypeLabel = m.match_type === 'exact' ? 'exact' : 'partial';
            const skuNote = m.sku_match ? '' : ' — SKU mismatch';
            const item = m.items.find((i) => i.sku === m.item_sku) || m.items[0] || {};

            return `<tr data-order-id="${m.order_id}">
                <td><a href="${m.edit_url}" target="_blank" rel="noopener">#${escapeHtml(m.order_number)}</a></td>
                <td>${escapeHtml(m.date)}</td>
                <td>${escapeHtml(m.customer_name)}</td>
                <td>${escapeHtml(item.product || m.item_sku)}</td>
                <td>${escapeHtml(m.matched_po)}</td>
                <td>${basisLabel} (${matchTypeLabel})${skuNote}</td>
                <td>${badge}</td>
                <td><button type="button" class="button button-small mmi-x-po-link-use-btn" data-order-id="${m.order_id}">Use this PO →</button></td>
            </tr>`;
        },

        // Never writes anything itself — just pre-fills the exact same
        // Email Customer panel a manual Fulfillment Queue sync opens
        // (PlaceOrderTab.revealEmailPanel()). mark_fulfilled() only runs
        // once the admin reviews the auto-fetched license/download/support
        // and actually clicks Send Email in that panel.
        useMatch(orderId) {
            const match = this.matches.find((m) => m.order_id === orderId);
            if (!match) {
                return;
            }

            if (match.confidence !== 'high') {
                const reason = match.sku_match
                    ? 'the reference is only a partial match, not an exact one'
                    : "the matched PO's own SKU doesn't match this order's item";
                const proceed = window.confirm(
                    `This match wasn't fully verified — ${reason}.\n\n` +
                    'Double-check the PO number and license on XChange before sending this email — a wrong match here would hand this customer a different order\'s license key.\n\n' +
                    'Continue anyway?'
                );
                if (!proceed) {
                    return;
                }
            }

            PlaceOrderTab.pendingOrder = {
                order_id: match.order_id,
                order_number: match.order_number,
                customer_name: match.customer_name,
                customer_email: match.customer_email,
                items: match.items,
            };
            PlaceOrderTab.revealEmailPanel(match.item_sku, match.matched_po, match.matched_auth);
        },
    };

    /* ── Account & Connection (Settings tab section) ─────────────────────────
       Folded in from the retired Account tab on 2026-09-01 — this section is
       now read-only status display only. Both checks (connection, order API)
       are triggered exclusively from the header buttons (see HeaderMode
       below), so there is exactly one trigger for each instead of the header
       and this section each having their own competing button for the same
       underlying check. The render helpers below stay shared: whichever tab
       is open when a header button is clicked, its own status card (if
       present in the DOM) updates in sync with the header badge. ───────────── */

    // Both render helpers below are shared by the header's own "Test
    // Connection" button and the Settings tab's read-only status card (same
    // mmi_xchange_check_connection response), so the two never disagree —
    // but each keeps its own established visual convention: the Settings
    // tab's local mmi-badge system, and the header's suite-wide mmi-badge
    // component (mmi-suite-common.css, same as mmi-reverb-integration's
    // header).
    function renderAccountCheckResult(data) {
        if (!$(SELECTORS.accountCheckResult).length) {
            return;
        }
        const cls = data.ok ? 'success' : 'error';
        $(SELECTORS.accountCheckResult).html(
            `<span class="mmi-badge ${cls}">${escapeHtml(data.message || '')}</span><div class="mmi-x-timestamp">${escapeHtml(data.checked_at || '')}</div>`
        );
    }

    // Warning/error badge content can run long (a real XChange API error
    // message) and would reflow the persistent controls sitting next to it
    // in .mmi-header-actions, so an error state moves the badge node itself
    // into .mmi-header-alerts (below .mmi-header-description); a success or
    // neutral ("not yet checked") state moves it back beside its button,
    // matching this header's original layout. Physically relocates the one
    // real element (not a copy) so its id-based selector keeps working
    // regardless of which row it currently lives in.
    function relocateHeaderBadge($badge, isError, $actionsAnchor) {
        if (isError) {
            if (!$badge.parent().is(SELECTORS.headerAlerts)) {
                $badge.appendTo(SELECTORS.headerAlerts);
            }
        } else if ($badge.parent().is(SELECTORS.headerAlerts)) {
            $badge.insertBefore($actionsAnchor);
        }
    }

    function renderHeaderConnectionBadge(data) {
        const $badge = $(SELECTORS.headerConnectionBadge);
        if (!$badge.length) {
            return;
        }
        const isError = data.ok === false;
        const cls = data.ok ? 'mmi-badge success' : 'mmi-badge error';
        const icon = data.ok ? '✓ ' : '✗ ';
        // mmi-animate-fadeIn (shared, one-shot) confirms a check actually
        // completed even when the result text is identical to before (e.g.
        // a still-failing check re-run) — a freshly-created element gets a
        // CSS animation applied on insertion, so no reflow trick is needed.
        // mmiAnimateResize (shared, mmi-resize-transition.js) softens both
        // rows' height changes from the relocation + content swap above,
        // since a plain CSS transition can't animate between two `auto`
        // heights on its own.
        window.mmiAnimateResize($(SELECTORS.headerAlerts), () => {
            window.mmiAnimateResize($(SELECTORS.headerActions), () => {
                relocateHeaderBadge($badge, isError, $(SELECTORS.headerTestConnectionBtn));
                $badge.html(`<span class="${cls} mmi-animate-fadeIn">${icon}${escapeHtml(data.message || '')}</span>`);
            });
        });
    }

    // Same shared-render pairing as the connection check above, for the new
    // "Test Order API" check (a real PUT /orders/ call against XChange's own
    // test vendor — see MMI_Xchange_Account::check_order_api()).
    function renderOrderApiCheckResult(data) {
        if (!$(SELECTORS.orderApiCheckResult).length) {
            return;
        }
        const cls = data.ok ? 'success' : 'error';
        $(SELECTORS.orderApiCheckResult).html(
            `<span class="mmi-badge ${cls}">${escapeHtml(data.message || '')}</span><div class="mmi-x-timestamp">${escapeHtml(data.checked_at || '')}</div>`
        );
    }

    function renderHeaderOrderApiBadge(data) {
        const $badge = $(SELECTORS.headerOrderApiBadge);
        if (!$badge.length) {
            return;
        }
        const isError = data.ok === false;
        const cls = data.ok ? 'mmi-badge success' : 'mmi-badge error';
        const icon = data.ok ? '✓ ' : '✗ ';
        const label = data.ok ? 'Order API OK' : (data.message || '');
        // See renderHeaderConnectionBadge()'s matching comments — this check
        // routinely comes back with the exact same (still-failing) result,
        // so without mmi-animate-fadeIn the badge appears to do nothing on
        // click even though a real check just ran; relocateHeaderBadge()
        // moves a long error message out of .mmi-header-actions the same
        // way; mmiAnimateResize softens both rows' resulting height change.
        window.mmiAnimateResize($(SELECTORS.headerAlerts), () => {
            window.mmiAnimateResize($(SELECTORS.headerActions), () => {
                relocateHeaderBadge($badge, isError, $(SELECTORS.headerTestOrderApiBtn));
                $badge.html(`<span class="${cls} mmi-animate-fadeIn">${icon}${escapeHtml(label)}</span>`);
            });
        });
    }

    const HeaderMode = {
        init() {
            $(SELECTORS.modeSwitch).on('change', (e) => this.onModeChange(e));
            $(SELECTORS.headerTestConnectionBtn).on('click', () => this.testConnection());
            $(SELECTORS.headerTestOrderApiBtn).on('click', () => this.testOrderApi());
        },

        // XChange has no real sandbox — engine=test has no pricing data for
        // any product outside XChange's own test vendor, and engine=live
        // spends real money on every reserve/finalize/place_order call from
        // that point on (see AGENTS.md's "XChange engine=test Has No
        // Real-Product Pricing" Incident History entry). Switching to live
        // requires an explicit confirm(); switching back to test does not —
        // that's the safe direction.
        onModeChange(e) {
            const $checkbox = $(e.target);
            const goingLive = $checkbox.is(':checked');

            if (goingLive && !confirm(
                'Switch XChange to LIVE mode?\n\n' +
                'XChange has no real sandbox — engine=test cannot price real products at all, ' +
                'but engine=live will spend real money on every order from this point on.\n\n' +
                'Are you sure?'
            )) {
                $checkbox.prop('checked', false);
                return;
            }

            const mode = goingLive ? 'live' : 'test';
            $checkbox.prop('disabled', true);

            ajax('mmi_xchange_set_mode', { mode }).done((res) => {
                if (!res.success) {
                    alert(res.data?.message || 'Failed to switch mode.');
                    $checkbox.prop('checked', !goingLive);
                    return;
                }
                // Full reload: several tabs (Place Order preview, Account's
                // own Mode badge) read the engine mode server-side at render
                // time and need to reflect the change, not just this toggle.
                location.reload();
            }).fail(() => {
                alert('Failed to switch mode — check the browser console.');
                $checkbox.prop('checked', !goingLive);
            }).always(() => {
                $checkbox.prop('disabled', false);
            });
        },

        testConnection() {
            const $btn = $(SELECTORS.headerTestConnectionBtn);
            $btn.prop('disabled', true);
            $(SELECTORS.headerTestConnectionSpinner).removeClass('mmi-hidden');

            ajax('mmi_xchange_check_connection', {}).done((res) => {
                renderHeaderConnectionBadge(res.data || {});
                renderAccountCheckResult(res.data || {}); // in sync if Settings tab is open
            }).fail(() => {
                alert('Connection check failed — check the browser console.');
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.headerTestConnectionSpinner).addClass('mmi-hidden');
            });
        },

        // Places a real order via PUT /orders/ against XChange's own XMP
        // TEST VENDOR fixture (see MMI_Xchange_Account::check_order_api()) —
        // answers whether direct API order placement works right now, or
        // whether this account is still blocked (e.g. error E033) and
        // XChange support needs to be contacted, without touching a real
        // customer order.
        testOrderApi() {
            const $btn = $(SELECTORS.headerTestOrderApiBtn);
            $btn.prop('disabled', true);
            $(SELECTORS.headerTestOrderApiSpinner).removeClass('mmi-hidden');

            ajax('mmi_xchange_check_order_api', {}).done((res) => {
                renderHeaderOrderApiBadge(res.data || {});
                renderOrderApiCheckResult(res.data || {}); // in sync if Settings tab is open
            }).fail(() => {
                alert('Order API check failed — check the browser console.');
            }).always(() => {
                $btn.prop('disabled', false);
                $(SELECTORS.headerTestOrderApiSpinner).addClass('mmi-hidden');
            });
        },
    };

    /* ── Collapsible section toggle — the shared .mmi-collapsible-section
       component (mmi-suite-common.css) is CSS-only, so every consumer wires
       its own click-to-toggle handler; this is this plugin's, shared by both
       real collapsible sections (Settings tab's "Account & Connection",
       Orders tab's "Xchange PO History"). The Logs tab reuses the same card
       shell for its own section but is never collapsible (no click target),
       so it doesn't need this. ───────────────────────────────────────────── */
    function initCollapsibleSections() {
        $(SELECTORS.settingsSectionClickable).on('click', function () {
            $(this).closest('.mmi-collapsible-section').toggleClass('collapsed');
        });
    }

    // Not reused from elsewhere in this file (no shared escapeHtml() utility
    // is loaded here — admin-xchange.js only depends on jquery). Needed
    // because LogsTab renders real log message text (which can contain API
    // error strings, SKUs, etc. — untrusted-ish content) via .html() so each
    // line can carry a per-level color class; every other .html() call site
    // in this file either injects trusted static markup or content this
    // plugin's own PHP has already esc_html()'d server-side.
    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // href/src values built from server data (supplier feed, CCSA scrape,
    // order meta) — only http(s)/mailto survive, so a `javascript:` value
    // can never become a clickable link. Returns an HTML-attribute-escaped
    // string ('' when rejected).
    function safeUrl(url) {
        const value = String(url == null ? '' : url).trim();
        return /^(https?:|mailto:)/i.test(value) ? escapeHtml(value) : '';
    }

    // Label/value rows for the detail modals: plain values are escaped;
    // a value wrapped as { html } is pre-built, already-escaped markup.
    function detailRowsHtml(rows) {
        return rows.map(([label, value]) => {
            const out = value && typeof value === 'object' ? value.html : escapeHtml(value);
            return `<p><strong>${label}:</strong> ${out || '—'}</p>`;
        }).join('');
    }

    /* ── Logs tab ──────────────────────────────────────────────────────────────
       Reads/filters MMI_Logger's 'xchange' category (mmi-hub/logs/xchange.log)
       via MMI_Xchange_Logs — see includes/class-xchange-logs.php. Uses the
       shared .mmi-collapsible-section card shell (mmi-suite-common.css) for
       its outer frame, same as the Settings tab's "Account & Connection"
       section, but is never collapsible itself. ─────────────────────────── */

    const LEVEL_CLASS = {
        ERROR: 'mmi-x-log-line--error',
        WARN:  'mmi-x-log-line--warn',
        INFO:  'mmi-x-log-line--info',
        DEBUG: 'mmi-x-log-line--debug',
    };

    const LogsTab = {
        searchDebounceTimer: null,

        init() {
            if ($(SELECTORS.logsSection).length === 0) {
                return;
            }

            $(SELECTORS.logLevelFilter).on('change', () => this.load());
            $(SELECTORS.logSourceFilter).on('change', () => this.load());
            $(SELECTORS.logLimit).on('change', () => this.load());
            $(SELECTORS.logRefresh).on('click', () => this.load());
            $(SELECTORS.logClear).on('click', () => this.clear());

            // Debounced (≥400ms) — a single request per pause in typing,
            // not one per keystroke (this project's Server Load rules).
            $(SELECTORS.logSearch).on('input', () => {
                clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = setTimeout(() => this.load(), 400);
            });

            // The tab's only content is this log viewer, so load it the
            // moment the tab is opened rather than waiting for a manual
            // "Refresh" click — one request, well under the ≤2-simultaneous
            // rule for an auto-fired load.
            this.load();
        },

        currentFilters() {
            return {
                level:  $(SELECTORS.logLevelFilter).val() || 'all',
                source: $(SELECTORS.logSourceFilter).val() || 'all',
                search: $(SELECTORS.logSearch).val() || '',
                limit:  $(SELECTORS.logLimit).val() || 300,
            };
        },

        load() {
            $(SELECTORS.logSpinner).removeClass('mmi-hidden');
            $(SELECTORS.logRefresh).prop('disabled', true);

            ajax('mmi_xchange_get_logs', this.currentFilters()).done((res) => {
                if (!res.success) {
                    $(SELECTORS.logContent).text(res.data?.message || 'Failed to load logs.');
                    return;
                }
                this.renderSources(res.data.sources || []);
                this.renderMeta(res.data);
                this.renderEntries(res.data);
            }).fail(() => {
                $(SELECTORS.logContent).text('Failed to load logs — check the browser console.');
            }).always(() => {
                $(SELECTORS.logSpinner).addClass('mmi-hidden');
                $(SELECTORS.logRefresh).prop('disabled', false);
            });
        },

        // Rebuilds the Process filter's options from whatever [Source]
        // values actually appear in the file, preserving the current
        // selection — so the dropdown always reflects real data instead of
        // a guessed, hardcoded list of class names.
        renderSources(sources) {
            const $select = $(SELECTORS.logSourceFilter);
            const current = $select.val() || 'all';

            $select.find('option[value!="all"]').remove();
            sources.forEach((src) => {
                $select.append(`<option value="${escapeHtml(src)}">${escapeHtml(src)}</option>`);
            });

            if (sources.includes(current)) {
                $select.val(current);
            }
        },

        renderMeta(data) {
            if (!data.file_exists) {
                $(SELECTORS.logMeta).text('');
                return;
            }
            const sizeKb = Math.round((data.file_size || 0) / 1024);
            let text = `${data.total_matching} of ${data.total_lines} line(s) — ${sizeKb} KB — last written ${data.file_modified || '—'}`;
            if (data.truncated) {
                text += ` (showing most recent ${data.entries.length})`;
            }
            $(SELECTORS.logMeta).text(text);
        },

        renderEntries(data) {
            if (!data.file_exists) {
                $(SELECTORS.logContent).html(
                    '<span class="mmi-x-log-empty">No XChange log file exists yet — nothing has been logged to the \'xchange\' category '
                    + '(mmi-hub/logs/xchange.log) since this install started, or it was recently cleared. '
                    + 'Trigger any XChange action (e.g. "Test Connection" or "Test Order API" above) and refresh.</span>'
                );
                return;
            }

            if (!data.entries || data.entries.length === 0) {
                const filters = this.currentFilters();
                const isFiltered = filters.level !== 'all' || filters.source !== 'all' || filters.search !== '';
                $(SELECTORS.logContent).html(
                    isFiltered
                        ? '<span class="mmi-x-log-empty">No log lines match the current filters.</span>'
                        : '<span class="mmi-x-log-empty">The XChange log file exists but is empty.</span>'
                );
                return;
            }

            const html = data.entries.map((entry) => {
                const cls = LEVEL_CLASS[entry.level] || 'mmi-x-log-line--other';
                const ts = entry.ts ? `<span class="mmi-x-log-ts">${escapeHtml(entry.ts)}</span> ` : '';
                const level = entry.level ? `<span class="mmi-x-log-level">${escapeHtml(entry.level)}</span> ` : '';
                const source = entry.source ? `<span class="mmi-x-log-source">[${escapeHtml(entry.source)}]</span> ` : '';
                return `<div class="mmi-x-log-line ${cls}">${ts}${level}${source}${escapeHtml(entry.message)}</div>`;
            }).join('');

            $(SELECTORS.logContent).html(html);
        },

        clear() {
            if (!confirm('Permanently delete the entire XChange log file (mmi-hub/logs/xchange.log)? This cannot be undone.')) {
                return;
            }

            $(SELECTORS.logClear).prop('disabled', true);
            $(SELECTORS.logSpinner).removeClass('mmi-hidden');

            ajax('mmi_xchange_clear_logs', {}).done((res) => {
                if (!res.success) {
                    alert(res.data?.message || 'Failed to clear the log.');
                    return;
                }
                this.load();
            }).fail(() => {
                alert('Failed to clear the log — check the browser console.');
            }).always(() => {
                $(SELECTORS.logClear).prop('disabled', false);
                $(SELECTORS.logSpinner).addClass('mmi-hidden');
            });
        },
    };

    /* ── Vendors tab ───────────────────────────────────────────────────────── */

    const VendorsTab = {
        allVendors: [],
        imageStats: null, // { vendor_totals: {NAME: {...}}, totals: {...}, products_json_missing }
        missingOnly: false,

        init() {
            if ($(SELECTORS.vendorsTable).length === 0) {
                return;
            }

            initSortableTable($(SELECTORS.vendorsTable), () => this.filteredVendors(), (rows) => this.renderRows(rows));
            initColumnResize($(SELECTORS.vendorsTable), 'mmiXchangeVendorsColWidths');

            $(SELECTORS.vendorsSearch).on('input', () => this.applyFilters());
            $(SELECTORS.vendorsMissingOnly).on('change', () => {
                this.missingOnly = $(SELECTORS.vendorsMissingOnly).is(':checked');
                this.applyFilters();
            });
            $(SELECTORS.statMissingCard).on('click', () => {
                this.missingOnly = !this.missingOnly;
                $(SELECTORS.vendorsMissingOnly).prop('checked', this.missingOnly);
                $(SELECTORS.statMissingCard).toggleClass('mmi-x-status-card--active', this.missingOnly);
                this.applyFilters();
            });
            $(SELECTORS.vendorsRefresh).on('click', () => this.fetch());
            $(SELECTORS.importMediaBtn).on('click', () => this.importMedia());
            // Two auto-fired requests on tab load — within the ≤2-simultaneous
            // limit for automatic triggers. Independent of each other (one hits
            // the live /vendors/ endpoint, the other reads local data only), so
            // no cascade/ordering dependency between them.
            this.fetch();
            this.fetchImageStats();
        },

        fetch() {
            $(SELECTORS.vendorsTbody).html(`<tr><td colspan="12" class="mmi-x-empty mmi-x-empty--loading">${emptyLoadingHtml('Loading…')}</td></tr>`);

            ajax('mmi_xchange_get_vendors', {}).done((res) => {
                if (!res.success) {
                    $(SELECTORS.vendorsTbody).html(`<tr><td colspan="12" class="mmi-x-empty">${escapeHtml(res.data?.message || 'Could not load vendors.')}</td></tr>`);
                    return;
                }
                this.allVendors = res.data.vendors || [];
                this.applyFilters();
            }).fail((jqXHR) => {
                // Verified server-side: this endpoint returns valid JSON
                // directly (wp eval do_action). A .fail() here (previously
                // absent — found via a suite-wide button audit) means the
                // real request/response never made it back intact through
                // the actual HTTP path (nginx/Cloudflare/RunCache/timeout on
                // a large response), which silently froze "Loading…"
                // forever with zero indication anything had gone wrong.
                $(SELECTORS.vendorsTbody).html(`<tr><td colspan="12" class="mmi-x-empty">Could not load vendors — request failed (HTTP ${jqXHR.status || 'unknown'}). Check the browser console/network tab.</td></tr>`);
            });
        },

        // Local-only — no live Xchange API call, safe to fire alongside fetch().
        fetchImageStats() {
            ajax('mmi_xchange_vendor_image_stats', {}).done((res) => {
                if (!res.success) {
                    return;
                }
                this.imageStats = res.data;

                const t = res.data.totals || {};
                $(SELECTORS.statVendors).text(t.vendors_with_products ?? '—');
                $(SELECTORS.statMatched).text(t.matched_products ?? '—');
                $(SELECTORS.statMissing).text(t.missing_image ?? '—');
                $(SELECTORS.statImported).text(t.already_imported_attachments ?? '—');

                if (res.data.products_json_missing) {
                    $(SELECTORS.imageStatsNote)
                        .removeClass('mmi-hidden')
                        .text('No cached Xchange product feed found yet — run a catalog fetch first (mmi-data-pipeline) to populate these numbers.');
                }

                // Row data (allVendors) may already be loaded; re-render to pick
                // up the new Products/Images columns without a second vendor fetch.
                this.applyFilters();
            });
        },

        // Case-insensitive, trimmed match against get_local_image_readiness()'s
        // vendor_totals keys (built the same way server-side from the cached
        // products feed's "vendor" name field).
        statsFor(v) {
            const key = ((v.name || v.vendor_name || '').trim()).toUpperCase();
            return (this.imageStats && this.imageStats.vendor_totals && this.imageStats.vendor_totals[key]) || null;
        },

        filteredVendors() {
            const term = ($(SELECTORS.vendorsSearch).val() || '').toLowerCase();
            return this.allVendors.filter((v) => {
                if (this.missingOnly) {
                    const s = this.statsFor(v);
                    if (!s || s.missing_image <= 0) return false;
                }
                if (!term) return true;
                return `${v.vendor_id} ${v.name} ${v.contact_name || ''} ${v.contact_email || ''}`.toLowerCase().indexOf(term) !== -1;
            });
        },

        applyFilters() {
            this.renderRows(this.filteredVendors());
        },

        renderRows(rows) {
            const $tbody = $(SELECTORS.vendorsTbody);
            if (rows.length === 0) {
                $tbody.html('<tr><td colspan="12" class="mmi-x-empty">No vendors found.</td></tr>');
                return;
            }

            $tbody.empty();
            rows.forEach((v) => {
                const s = this.statsFor(v);

                const $tr = $('<tr>').data('vendor', v);
                $tr.append($('<td>').text(v.vendor_id || ''));
                $tr.append($('<td>').text(v.name || v.vendor_name || ''));
                $tr.append($('<td>').text(s ? s.sku_count : '—'));

                const $imagesCell = $('<td>');
                const $imagesGroup = $('<span class="mmi-x-actions-group">').appendTo($imagesCell);
                if (!s || s.matched_products === 0) {
                    $imagesGroup.append($('<span class="mmi-badge">No local data</span>'));
                } else if (s.missing_image === 0) {
                    $imagesGroup.append($(`<span class="mmi-badge success">${s.with_image}/${s.matched_products}</span>`));
                } else {
                    $imagesGroup.append($(`<span class="mmi-badge warning">${s.missing_image} missing</span>`));
                }
                // Cross-vendor brand overlap: this vendor's own count above
                // never includes another vendor account's rows, even when
                // both carry the same brand — see get_local_image_readiness()'s
                // own comment. Surfaced only when it's real (>0), so the
                // common single-vendor-brand case renders nothing extra.
                if (s && s.overlap_missing > 0) {
                    const otherVendors = (s.overlap_vendor_names || []).join(', ');
                    $(`<span class="mmi-badge mmi-x-overlap-badge" title="Shared brand(s) also sold via: ${otherVendors} — that vendor's own row has ${s.overlap_missing} more missing there">+${s.overlap_missing} elsewhere</span>`)
                        .appendTo($imagesGroup);
                }
                $('<button type="button" class="button button-small">Import Images</button>').on('click', (e) => {
                    e.stopPropagation();
                    $(SELECTORS.importVendorInput).val(v.vendor_id);
                    VendorsTab.importMedia();
                }).appendTo($imagesGroup);
                $tr.append($imagesCell);

                $tr.append($('<td>').text(v.phone || ''));
                const addressParts = [v.address, v.city, v.state_prov, v.zip, v.country].filter(Boolean);
                $tr.append($('<td>').text(addressParts.join(', ')));
                $tr.append($('<td>').text(v.contact_name || ''));
                $tr.append($('<td>').text(v.contact_email || ''));
                $tr.append($('<td>').text(v.contact_phone || ''));
                $tr.append($('<td>').text((v.prepay_required || '').toString().toLowerCase() === 'yes' ? 'Yes' : 'No'));
                $tr.append($('<td>').text(v.credit_status || ''));

                const $actions = $('<td>');
                const $actionsGroup = $('<span class="mmi-x-actions-group">').appendTo($actions);
                $('<button type="button" class="button button-small">Details</button>').on('click', (e) => {
                    e.stopPropagation();
                    VendorsTab.showDetail(v);
                }).appendTo($actionsGroup);
                $('<button type="button" class="button button-small">Preview</button>').on('click', (e) => {
                    e.stopPropagation();
                    VendorsTab.showPreview(v);
                }).appendTo($actionsGroup);
                $tr.append($actions);
                $tbody.append($tr);
            });
        },

        // Read-only, single-vendor dry run — one throttled live API call (same
        // cost as one "Import Images" click for this vendor), so this is
        // synchronous with a spinner rather than Action-Scheduler-dispatched.
        showPreview(v) {
            const vendorName = escapeHtml(v.name || v.vendor_name || v.vendor_id);
            $(SELECTORS.vendorDetailBody).html(
                `<p>${LABEL_LOADING} Checking Xchange's image feed for <strong>${vendorName}</strong>… ` +
                `this makes one live, rate-limited request and can take up to ~15 seconds.</p>`
            );
            MMIModal.open(SELECTORS.vendorDetail);

            ajax('mmi_xchange_preview_vendor_images', { vendor: v.vendor_id }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.vendorDetailBody).html(`<p>${escapeHtml(res.data?.message || 'Preview failed.')}</p>`);
                    return;
                }
                this.renderPreview(vendorName, res.data);
            }).fail(() => {
                $(SELECTORS.vendorDetailBody).html('<p>Preview request failed — try again.</p>');
            });
        },

        renderPreview(vendorName, data) {
            const c = data.counts || {};
            const rows = data.rows || [];

            const summary = `
                <div class="mmi-x-status-grid">
                    <div class="mmi-x-status-card"><h3>New images</h3><span>${c.new || 0}</span></div>
                    <div class="mmi-x-status-card"><h3>Already imported</h3><span>${c.already_imported || 0}</span></div>
                    <div class="mmi-x-status-card"><h3>Skipped (logo/brand)</h3><span>${c.skip_logo || 0}</span></div>
                    <div class="mmi-x-status-card"><h3>No product match</h3><span>${c.no_product_match || 0}</span></div>
                </div>`;

            if (rows.length === 0) {
                $(SELECTORS.vendorDetailBody).html(
                    `<h3>${vendorName}</h3>${summary}<p>No image assets found in Xchange's feed for this vendor.</p>`
                );
                return;
            }

            const theadCols = [
                ['sku', 'SKU'],
                ['product_name', 'Product'],
                ['current', 'Current Images'],
                ['new_count', 'New'],
                ['xchange_images', 'Xchange Images'],
            ];
            const thead = theadCols.map(([col, label]) =>
                `<th data-col="${col}" data-resize-col="${col}">${label}<span class="sort-icon"></span><span class="mmi-x-resize-handle"></span></th>`
            ).join('');

            const badge = (status) => {
                const map = {
                    new: 'success', already_imported: '', skip_logo: 'warning', skip_bad_url: 'error',
                };
                const text = { new: 'new', already_imported: 'dupe', skip_logo: 'logo', skip_bad_url: 'bad url' }[status] || status;
                return `<span class="mmi-badge ${map[status] ?? ''}" title="${escapeHtml(status)}">${escapeHtml(text)}</span>`;
            };

            const flatRows = rows.map((r) => ({
                sku: r.sku,
                product_name: r.product_name || (r.product_id ? `#${r.product_id}` : 'No matching product'),
                current: r.has_thumbnail || r.gallery_count > 0 ? `Featured${r.gallery_count ? ' + ' + r.gallery_count + ' gallery' : ''}` : 'None',
                new_count: r.new_count,
                xchange_images: (r.images || []).map((img) => `${escapeHtml(img.key)} ${badge(img.status)}`).join(' &nbsp; '),
                _edit_url: r.edit_url,
                _no_match: !r.product_id,
            }));

            const renderPreviewRows = (list) => {
                $('#mmi-x-preview-tbody').empty();
                list.forEach((row) => {
                    const $tr = $('<tr>');
                    if (row._no_match) {
                        $tr.addClass('mmi-x-preview-no-match');
                    }
                    const nameCell = row._edit_url
                        ? `<a href="${safeUrl(row._edit_url)}" target="_blank" rel="noopener">${escapeHtml(row.product_name)}</a>`
                        : escapeHtml(row.product_name);
                    $tr.append($('<td>').text(row.sku));
                    $tr.append($('<td>').html(nameCell));
                    $tr.append($('<td>').text(row.current));
                    $tr.append($('<td>').text(row.new_count));
                    $tr.append($('<td>').html(row.xchange_images));
                    $('#mmi-x-preview-tbody').append($tr);
                });
            };

            $(SELECTORS.vendorDetailBody).html(`
                <h3>${vendorName}</h3>
                ${summary}
                <div class="mmi-x-table-scroll mmi-x-table-scroll--preview">
                    <table class="mmi-x-table" id="mmi-x-preview-table">
                        <thead><tr>${thead}</tr></thead>
                        <tbody id="mmi-x-preview-tbody"></tbody>
                    </table>
                </div>
            `);

            renderPreviewRows(flatRows);
            initSortableTable($('#mmi-x-preview-table'), () => flatRows, renderPreviewRows);
            initColumnResize($('#mmi-x-preview-table'), 'mmiXchangePreviewColWidths');
        },

        showDetail(v) {
            const addressParts = [v.address, v.city, v.state_prov, v.zip, v.country].filter(Boolean);
            const rows = [
                ['Vendor ID', v.vendor_id],
                ['Name', v.name],
                ['Doing Business As', v.doing_business_as],
                ['Main Phone', v.phone],
                ['Address', addressParts.join(', ')],
                ['Contact', v.contact_name],
                ['Contact Email', v.contact_email],
                ['Contact Phone', v.contact_phone],
                ['Tax ID', v.tax_id],
                ['Credit Status', v.credit_status],
                ['Prepay Required', v.prepay_required],
                ['AP Name', v.ap_name],
                ['AP Email', v.ap_email],
                ['AP Phone', v.ap_phone],
                ['Support', v.support ? { html: `<a href="${safeUrl(v.support)}" target="_blank" rel="noopener">${escapeHtml(v.support)}</a>` } : ''],
                ['EMEA Phone', v.emea_phone],
                ['APAC Phone', v.apac_phone],
                ['Operational Support Email', v.operational_support_email],
            ];
            const html = detailRowsHtml(rows);
            $(SELECTORS.vendorDetailBody).html(html);
            MMIModal.open(SELECTORS.vendorDetail);
        },

        importMedia() {
            const vendorId = $(SELECTORS.importVendorInput).val().trim();
            if (!vendorId) {
                alert('Enter a vendor ID (or "_all") first.');
                return;
            }

            const $btn = $(SELECTORS.importMediaBtn);
            $btn.prop('disabled', true).addClass('mmi-is-loading');
            $(SELECTORS.importMediaProgress).removeClass('mmi-hidden');
            $(SELECTORS.importMediaFill).css('--pct', '2%');
            $(SELECTORS.importMediaStep).text('Queued…');

            ajax('mmi_xchange_import_media', { vendor: vendorId }).done(() => {
                this.pollImportMediaStatus($btn, 0);
            }).fail(() => {
                window.alert('Could not queue the image import — try again.');
                $(SELECTORS.importMediaProgress).addClass('mmi-hidden');
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
            });
        },

        // Polls mmi_xchange_import_media_status every 2s while the queued
        // import runs in the background; 3-minute safety cutoff in case a
        // stuck job never reports 'complete'/'error'.
        pollImportMediaStatus($btn, elapsedMs) {
            if (elapsedMs >= 180000) {
                $(SELECTORS.importMediaStep).text('Taking longer than expected — check back shortly.');
                $btn.prop('disabled', false).removeClass('mmi-is-loading');
                return;
            }

            ajax('mmi_xchange_import_media_status', {}).done((res) => {
                const s = res.data || {};
                $(SELECTORS.importMediaFill).css('--pct', (s.pct || 0) + '%');
                $(SELECTORS.importMediaStep).text(s.step || s.message || '…');

                if (s.status === 'complete') {
                    $(SELECTORS.importMediaStep).text(s.message || 'Done.');
                    $(SELECTORS.importMediaSyncedAt).text(s.updated_at || '');
                    $btn.prop('disabled', false).removeClass('mmi-is-loading');
                    setTimeout(() => $(SELECTORS.importMediaProgress).addClass('mmi-hidden'), 4000);
                    return;
                }

                if (s.status === 'error') {
                    $(SELECTORS.importMediaStep).text('Image import failed: ' + (s.message || 'unknown error'));
                    $btn.prop('disabled', false).removeClass('mmi-is-loading');
                    return;
                }

                setTimeout(() => this.pollImportMediaStatus($btn, elapsedMs + 2000), 2000);
            }).fail(() => {
                setTimeout(() => this.pollImportMediaStatus($btn, elapsedMs + 2000), 2000);
            });
        },
    };

    /* ── COGS backfill (Settings tab) ─────────────────────────────────────── */

    const CogsBackfill = {
        init() {
            if ($(SELECTORS.cogsBackfillBtn).length === 0) {
                return;
            }
            this.loadStatus();
            $(SELECTORS.cogsBackfillBtn).on('click', () => this.start());
        },

        loadStatus() {
            ajax('mmi_xchange_cogs_status', {}).done((res) => {
                if (!res.success) return;
                const s = res.data;
                $(SELECTORS.cogsMatchable).text(s.matchable);
                $(SELECTORS.cogsDone).text(s.backfill_done);
                $(SELECTORS.cogsFill).css('--pct', s.backfill_pct + '%');
                $(SELECTORS.cogsPct).text(s.backfill_pct + '%');
            });
        },

        start() {
            $(SELECTORS.cogsBackfillBtn).prop('disabled', true);
            $(SELECTORS.cogsSpinner).removeClass('mmi-hidden');
            $(SELECTORS.cogsStatusMsg).text('Running…');
            this.runBatch(0);
        },

        runBatch(offset) {
            ajax('mmi_xchange_cogs_backfill_batch', { offset }).done((res) => {
                if (!res.success) {
                    $(SELECTORS.cogsStatusMsg).text(res.data?.message || 'Backfill failed.');
                    this.finish();
                    return;
                }
                const r = res.data;
                $(SELECTORS.cogsStatusMsg).text(`Processed ${r.offset} orders…`);

                if (r.done) {
                    this.loadStatus();
                    $(SELECTORS.cogsStatusMsg).text('Backfill complete.');
                    this.finish();
                } else {
                    setTimeout(() => this.runBatch(r.offset), 300);
                }
            });
        },

        finish() {
            $(SELECTORS.cogsBackfillBtn).prop('disabled', false);
            $(SELECTORS.cogsSpinner).addClass('mmi-hidden');
        },
    };

    $(function () {
        MMIModal.init();
        HeaderMode.init();
        OrdersTab.init();
        POOrdersPanel.init();
        PlaceOrderTab.init();
        BundleFulfillment.init();
        PoLinkerPanel.init();
        initCollapsibleSections();
        VendorsTab.init();
        CogsBackfill.init();
        LogsTab.init();
    });
})(jQuery);
