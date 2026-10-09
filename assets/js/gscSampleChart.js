/**
 * assets/js/gscSampleChart.js
 *
 * Only runs when window.gscChartIsSample === true.
 *
 * homeOneChart.js can re-fetch from the API and replace the chart with
 * "No data available for this range." That script may be loaded from
 * anywhere, so this file runs after it and redraws — and if the chart is
 * blanked again later, the observer catches it and draws once more.
 *
 * Chart options are kept in step with homeOneChart.js so the sample and the
 * live chart look identical: same height, one shared y-axis, no built-in
 * legend (the captions above the chart serve that purpose), and the same
 * tooltip.
 */
(function () {
    'use strict';

    // Guard against being loaded twice
    if (window.__gscSampleChartInit) return;
    window.__gscSampleChartInit = true;

    var MAX_REDRAWS = 12;
    var redraws = 0;
    var chartInstance = null;
    var drawing = false;

    // Which series the user has switched off, kept outside the draw function
    // so the choice survives a redraw.
    var hiddenSeries = { "Clicks": false, "Impressions": false };

    function isSample() {
        return window.gscChartIsSample === true;
    }

    function getData() {
        var d = window.gscChartData || {};
        return {
            dates: d.dates || [],
            clicks: d.clicks || [],
            impressions: d.impressions || []
        };
    }

    /** Is the chart area empty, or showing the "no data" placeholder? */
    function needsRedraw(el) {
        if (!el) return false;
        if (el.querySelector('.apexcharts-canvas, svg')) {
            // something is drawn — but redraw if it is the "no data" message
            return /no data/i.test(el.textContent || '');
        }
        return true;
    }

    function disableRangeControls() {
        var ctrl = document.getElementById('gsc-chart-controls');
        if (!ctrl || ctrl.dataset.sampleLocked === '1') return;
        ctrl.dataset.sampleLocked = '1';
        ctrl.style.opacity = '0.5';
        ctrl.style.pointerEvents = 'none';
        ctrl.setAttribute('aria-disabled', 'true');
    }

    // ---------------------------------------------------------------------
    // Caption legend — same behaviour as the live chart
    // ---------------------------------------------------------------------

    var CAPTIONS = [
        { id: 'gsc-legend-clicks', name: 'Clicks' },
        { id: 'gsc-legend-impr',   name: 'Impressions' }
    ];

    function paintCaptionState() {
        CAPTIONS.forEach(function (item) {
            var el = document.getElementById(item.id);
            if (!el) return;
            var off = hiddenSeries[item.name];
            el.style.opacity = off ? '0.4' : '1';
            el.style.textDecoration = off ? 'line-through' : 'none';
            el.title = (off ? 'Show ' : 'Hide ') + item.name;
        });
    }

    function wireCaptionToggles() {
        CAPTIONS.forEach(function (item) {
            var el = document.getElementById(item.id);
            if (!el || el.dataset.wired) return;
            el.dataset.wired = '1';
            el.style.cursor = 'pointer';
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');

            function toggle() {
                if (!chartInstance) return;
                hiddenSeries[item.name] = !hiddenSeries[item.name];
                try { chartInstance.toggleSeries(item.name); } catch (e) {}
                paintCaptionState();
            }

            el.addEventListener('click', toggle);
            el.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); }
            });
        });
        paintCaptionState();
    }

    function updateCaptionTotals(d) {
        var elC = document.getElementById('gsc-total-clicks');
        var elI = document.getElementById('gsc-total-impr');
        if (!elC && !elI) return;

        var tc = 0, ti = 0;
        for (var i = 0; i < d.dates.length; i++) {
            tc += Number(d.clicks[i]) || 0;
            ti += Number(d.impressions[i]) || 0;
        }
        if (elC) elC.textContent = tc.toLocaleString();
        if (elI) elI.textContent = ti.toLocaleString();
    }

    // ---------------------------------------------------------------------
    // Drawing
    // ---------------------------------------------------------------------

    function drawApex(el, d) {
        el.innerHTML = '';

        var clickSeries = [];
        var imprSeries = [];

        for (var i = 0; i < d.dates.length; i++) {
            var date = d.dates[i];
            var ts;

            if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                var p = date.split('-');
                ts = Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
            } else {
                ts = new Date(date).getTime();
            }

            clickSeries.push([ts, Number(d.clicks[i]) || 0]);
            imprSeries.push([ts, Number(d.impressions[i]) || 0]);
        }

        chartInstance = new ApexCharts(el, {

            series: [
                { name: "Clicks",      data: clickSeries },
                { name: "Impressions", data: imprSeries }
            ],

            chart: {
                height: 230,
                type: "area",
                fontFamily: "Inter, sans-serif",
                zoom: { enabled: false },
                toolbar: { show: false },
                animations: { enabled: false }
            },

            dataLabels: { enabled: false },

            stroke: { curve: "smooth", width: 2 },

            // ONE shared scale for both series, matching the live chart.
            // Separate scales would let the clicks line rise above the
            // impressions line, which cannot happen — every click comes from
            // an impression.
            yaxis: {
                seriesName: "Impressions",
                min: 0,
                forceNiceScale: true,
                labels: {
                    style: { colors: "#94a3b8", fontSize: "12px" },
                    formatter: function (v) { return Math.round(v).toLocaleString(); }
                }
            },

            xaxis: {
                type: "datetime",
                labels: { style: { colors: "#64748b", fontSize: "12px" } }
            },

            grid: { show: true, borderColor: "#e2e8f0", strokeDashArray: 4 },

            colors: ["#0ea5e9", "#6366f1"],

            fill: {
                type: "gradient",
                gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] }
            },

            // The Clicks / Impressions captions sit above the chart in the
            // card markup, so the built-in legend would just repeat them.
            legend: { show: false },

            tooltip: {
                shared: true,
                intersect: false,
                x: { format: "dd MMM yyyy" },
                y: { formatter: function (v) { return Number(v).toLocaleString(); } }
            }
        });

        chartInstance.render().then(function () {
            // A fresh render brings every series back, so re-apply whatever
            // the user had switched off.
            Object.keys(hiddenSeries).forEach(function (name) {
                if (hiddenSeries[name]) {
                    try { chartInstance.hideSeries(name); } catch (e) {}
                }
            });
            wireCaptionToggles();
        });
    }

    /** Last-resort fallback if ApexCharts is missing entirely. */
    function drawSvg(el, d) {
        var max = Math.max.apply(null, d.impressions.concat([1]));
        var w = 700, h = 300, n = d.dates.length;
        var slot = w / n;
        var bw = slot * 0.7;
        var bars = '';

        for (var i = 0; i < n; i++) {
            var bh = Math.round(((d.impressions[i] || 0) / max) * (h - 40));
            var x = Math.round(slot * i + (slot - bw) / 2);
            bars += '<rect x="' + x + '" y="' + (h - bh - 20) +
                '" width="' + Math.round(bw) + '" height="' + bh +
                '" rx="2" fill="#93c5fd"></rect>';
        }

        el.innerHTML =
            '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="150" ' +
            'xmlns="http://www.w3.org/2000/svg">' + bars +
            '<line x1="0" y1="' + (h - 20) + '" x2="' + w + '" y2="' + (h - 20) +
            '" stroke="#e5e7eb" stroke-width="1"></line></svg>';
    }

    function draw(force) {
        if (!isSample() || drawing) return;

        var el = document.getElementById('chart-area');
        if (!el) return;

        if (!force && !needsRedraw(el)) return;
        if (redraws >= MAX_REDRAWS) return;

        var d = getData();
        if (!d.dates.length) return;

        drawing = true;
        redraws++;

        try {
            if (chartInstance && typeof chartInstance.destroy === 'function') {
                chartInstance.destroy();
                chartInstance = null;
            }
        } catch (e) { /* ignore */ }

        try {
            if (typeof ApexCharts !== 'undefined') {
                drawApex(el, d);
            } else {
                drawSvg(el, d);
            }
        } catch (e) {
            try { drawSvg(el, d); } catch (e2) { /* ignore */ }
        }

        updateCaptionTotals(d);

        // stop the observer from firing on our own change
        setTimeout(function () { drawing = false; }, 150);
    }

    function watch() {
        var el = document.getElementById('chart-area');
        if (!el || typeof MutationObserver === 'undefined') return;

        new MutationObserver(function () {
            if (drawing) return;
            if (needsRedraw(el)) draw(true);
        }).observe(el, { childList: true, subtree: true });
    }

    function boot() {
        if (!isSample()) return;

        disableRangeControls();
        draw(true);
        watch();

        // homeOneChart.js may fetch later — check again a few times
        [400, 1000, 2000, 3500].forEach(function (ms) {
            setTimeout(function () { draw(false); }, ms);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.addEventListener('load', function () { draw(false); });
})();