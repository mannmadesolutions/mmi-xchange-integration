/* MMI Xchange — Price History chart (Place Order tab + Recent Orders detail table) */
(function ($) {
    'use strict';

    const SELECTORS = {
        modal:  '#mmi-x-price-history-modal',
        title:  '#mmi-x-price-history-title',
        status: '#mmi-x-price-history-status',
        canvas: '#mmi-x-price-history-canvas',
        trigger: '.mmi-x-price-history-btn',
    };

    const MESSAGES = {
        loading: 'Loading price history for %sku%…',
        empty:   'No recorded price history yet for %sku% — history starts accumulating once this feature has been live for a while.',
        error:   'Could not load price history for %sku%.',
        loaded:  'Price history for %sku%, last %days% days.',
    };

    const HISTORY_WINDOW_DAYS = 365;

    // Categorical slots 1/2/3/4 (blue/orange/aqua/yellow) from the suite's
    // validated default palette — passes every hard CVD/lightness/chroma
    // gate for 4 adjacent series in both light and dark mode (see the
    // dataviz skill's references/palette.md). Order is fixed, never cycled,
    // and assigned by series identity (dealer/promo/regular/sale), never
    // reassigned when a series happens to have no data.
    const SERIES = [
        { key: 'dealer',     label: 'Our Cost (Dealer)',        light: '#2a78d6', dark: '#3987e5' },
        { key: 'promo',      label: 'Our Cost (Promo)',         light: '#eb6834', dark: '#d95926' },
        { key: 'wc_regular', label: 'Customer Price (Regular)', light: '#1baf7a', dark: '#199e70' },
        { key: 'wc_sale',    label: 'Customer Price (Sale)',    light: '#eda100', dark: '#c98500' },
    ];

    function isDarkMode() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function ajax(action, data) {
        return $.post(mmiXchange.ajaxUrl, Object.assign({ action: action, nonce: mmiXchange.nonce }, data));
    }

    let chartInstance = null;

    function formatMessage(template, replacements) {
        return Object.keys(replacements).reduce(
            (str, key) => str.replace(`%${key}%`, replacements[key]),
            template
        );
    }

    /**
     * Merges all 4 series' distinct timestamps into one sorted label axis,
     * then maps each series onto it (null where that series has no point at
     * that timestamp) — Chart.js's `spanGaps` connects across the nulls, so
     * a series with sparser changes (e.g. wc_sale) still renders a
     * continuous, correctly-dated line instead of needing every series to
     * share identical timestamps.
     */
    function buildChartData(series) {
        const allTimestamps = new Set();
        SERIES.forEach(({ key }) => (series[key] || []).forEach((point) => allTimestamps.add(point.t)));
        const labels = Array.from(allTimestamps).sort();

        const dark = isDarkMode();
        const datasets = SERIES.map(({ key, label, light, dark: darkColor }) => {
            const byTimestamp = new Map((series[key] || []).map((point) => [point.t, point.y]));
            return {
                seriesKey: key, // read back in the tooltip callback below — do not rely on datasetIndex, which shifts once empty series are filtered out
                label: label,
                data: labels.map((t) => (byTimestamp.has(t) ? byTimestamp.get(t) : null)),
                borderColor: dark ? darkColor : light,
                backgroundColor: dark ? darkColor : light,
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                // Price history is an event log, not a continuously-sampled
                // signal — a step-after line (a recorded price holds flat
                // starting at its own timestamp, until the next recorded
                // change) represents reality accurately; a smoothed
                // interpolation would imply values that were never true.
                stepped: 'after',
                spanGaps: true,
                tension: 0,
            };
        }).filter((ds) => ds.data.some((y) => y !== null)); // omit a series with zero data points entirely

        return { labels, datasets };
    }

    function hasAnyData(series) {
        return SERIES.some(({ key }) => (series[key] || []).length > 0);
    }

    function renderChart(sku, series) {
        const dark = isDarkMode();
        const gridColor = dark ? '#2c2c2a' : '#e1e0d9';
        const inkColor = dark ? '#c3c2b7' : '#52514e';

        const ctx = document.querySelector(SELECTORS.canvas).getContext('2d');
        if (chartInstance) {
            chartInstance.destroy();
        }

        const { labels, datasets } = buildChartData(series);

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                plugins: {
                    legend: { display: true, labels: { color: inkColor } },
                    tooltip: {
                        callbacks: {
                            afterLabel(item) {
                                const seriesKey = item.dataset && item.dataset.seriesKey;
                                const point = (series[seriesKey] || []).find((p) => p.t === item.label);
                                return point && point.promotion_name ? `Promotion: ${point.promotion_name}` : '';
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { color: inkColor } },
                    y: { grid: { color: gridColor }, ticks: { color: inkColor }, beginAtZero: true },
                },
            },
        });
    }

    function openFor(sku) {
        $(SELECTORS.title).text(`Price History — ${sku}`);
        $(SELECTORS.status).text(formatMessage(MESSAGES.loading, { sku }));
        MMIModal.open(SELECTORS.modal);

        ajax('mmi_xchange_price_history_chart', { sku }).done((res) => {
            if (!res.success) {
                $(SELECTORS.status).text(formatMessage(MESSAGES.error, { sku }));
                return;
            }
            if (!hasAnyData(res.data.series)) {
                $(SELECTORS.status).text(formatMessage(MESSAGES.empty, { sku }));
                if (chartInstance) {
                    chartInstance.destroy();
                    chartInstance = null;
                }
                return;
            }
            $(SELECTORS.status).text(formatMessage(MESSAGES.loaded, { sku, days: String(HISTORY_WINDOW_DAYS) }));
            renderChart(sku, res.data.series);
        }).fail(() => {
            $(SELECTORS.status).text(formatMessage(MESSAGES.error, { sku }));
        });
    }

    $(function () {
        if (!document.querySelector(SELECTORS.modal) || typeof Chart === 'undefined') {
            return;
        }
        MMIModal.init();

        $(document).on('click', SELECTORS.trigger, function () {
            const sku = $(this).data('sku');
            if (sku) {
                openFor(String(sku));
            }
        });
    });
})(jQuery);
