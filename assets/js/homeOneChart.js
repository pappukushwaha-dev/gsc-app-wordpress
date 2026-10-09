(function () {
  "use strict";

  function initGscChart() {
    console.debug("[homeOneChart] init start");

    // 1. SETUP DOM ELEMENTS
    var chartElement = document.querySelector("#chart-area");
    var rangeLabelEl = document.getElementById('gsc-range-label');
    var rangeToggle = document.getElementById('gsc-range-toggle');
    var rangeMenu = document.getElementById('gsc-range-menu');
    var rangeItems = document.querySelectorAll('.gsc-range-item');
    var customApply = document.getElementById('gsc-custom-apply');
    var rangeCancel = document.getElementById('gsc-range-cancel');
    var startInput = document.getElementById('gsc-start');
    var endInput = document.getElementById('gsc-end');
    var kpiCtrEl = document.getElementById('kpi-ctr');
    var kpiPosEl = document.getElementById('kpi-position');
    
    // --- DROPDOWN LOGIC (MOVED TO TOP) ---
    // This ensures dropdown works even if chart data is empty
    
    function closeMenu() {
        if (!rangeMenu) return;
        rangeMenu.classList.add('hidden'); // Use Tailwind class
        if (rangeToggle) rangeToggle.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        if (!rangeMenu) return;
        rangeMenu.classList.remove('hidden'); // Use Tailwind class
        if (rangeToggle) rangeToggle.setAttribute('aria-expanded', 'true');
        
        // Populate inputs with current values if empty
        if (startInput && !startInput.value) {
            startInput.value = new Date(Date.now() - 30 * 24 * 3600 * 1000).toISOString().slice(0, 10);
        }
        if (endInput && !endInput.value) {
            endInput.value = new Date().toISOString().slice(0, 10);
        }
    }

    function toggleMenu(e) {
        e.stopPropagation();
        if (!rangeMenu) return;
        if (rangeMenu.classList.contains('hidden')) {
            openMenu();
        } else {
            closeMenu();
        }
    }

    if (rangeToggle) {
        // Remove old listeners (if any) and add new one
        rangeToggle.removeEventListener('click', toggleMenu);
        rangeToggle.addEventListener('click', toggleMenu);
    }

    // Close on click outside
    document.addEventListener('click', function (ev) {
        if (!rangeMenu || rangeMenu.classList.contains('hidden')) return;
        if (rangeMenu.contains(ev.target) || (rangeToggle && rangeToggle.contains(ev.target))) return;
        closeMenu();
    });

    // Close on Escape
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' || ev.key === 'Esc') closeMenu();
    });

    if (rangeCancel) {
        rangeCancel.addEventListener('click', function (e) {
            e.stopPropagation();
            closeMenu();
        });
    }

    // --- CHART DATA PROCESSING ---

    if (typeof ApexCharts === 'undefined') {
      console.error("[homeOneChart] ApexCharts is not present.");
      return;
    }
    
    if (!chartElement) return;

    // Safe read input
    var raw = window.gscChartData && typeof window.gscChartData === 'object'
              ? window.gscChartData
              : { dates: [], clicks: [], impressions: [] };

    var dates = Array.isArray(raw.dates) ? raw.dates.slice() : [];
    var clicks = Array.isArray(raw.clicks) ? raw.clicks.slice() : [];
    var impressions = Array.isArray(raw.impressions) ? raw.impressions.slice() : [];
    var positions = Array.isArray(raw.positions) ? raw.positions.slice() : null;

    function toEpochMs(value) {
      if (typeof value === 'number' && isFinite(value)) return value;
      if (typeof value === 'string') {
        var m = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (m) return Date.UTC(+m[1], +m[2]-1, +m[3]);
      }
      var d2 = new Date(value);
      return isNaN(d2.getTime()) ? null : d2.getTime();
    }

    var maxLen = Math.max(dates.length, clicks.length, impressions.length);
    var points = [];
    for (var i = 0; i < maxLen; i++) {
      var rawDate = dates[i] ?? (dates[dates.length-1] || new Date().toISOString().slice(0,10));
      var x = toEpochMs(rawDate) || Date.now();
      
      points.push({
        x: x,
        clicks: Number(clicks[i]) || 0,
        impressions: Number(impressions[i]) || 0,
        position: positions ? (positions[i] ?? null) : null
      });
    }

    // Sort ascending by Date
    points.sort(function(a,b){ return a.x - b.x; });

    var fullClicksSeriesArr = points.map(function(p){ return [p.x, p.clicks]; });
    var fullImprSeriesArr   = points.map(function(p){ return [p.x, p.impressions]; });

    var currentChart = null;

    function renderChart(clickSeriesArr, imprSeriesArr) {
      if (currentChart) {
          try { currentChart.destroy(); } catch (e) {}
      }
      chartElement.innerHTML = "";

      var options = {
        series: [
            { name: "Clicks", data: clickSeriesArr },
            { name: "Impressions", data: imprSeriesArr }
        ],
        chart: { height: 120, type: "area", fontFamily: 'Inter, sans-serif', zoom: { enabled: false }, toolbar: { show: false } },
        dataLabels: { enabled: false },
        stroke: { curve: "smooth", width: 2 },
        xaxis: { type: "datetime", labels: { style: { colors: "#64748b", fontSize: "12px" } } },
        grid: { show: true, borderColor: "#e2e8f0", strokeDashArray: 4 },
        colors: ["#0ea5e9", "#6366f1"],
        fill: { type: "gradient", gradient: { shadeIntensity:1, opacityFrom:0.4, opacityTo:0.05, stops:[0,90,100] } },
        tooltip: { x: { format: "dd MMM yyyy" } }
      };

      currentChart = new ApexCharts(chartElement, options);
      currentChart.render();
    }

    function updateKPIs(clickSeriesArr, imprSeriesArr) {
        var totalClicks = clickSeriesArr.reduce((s, p) => s + (p[1] || 0), 0);
        var totalImpr = imprSeriesArr.reduce((s, p) => s + (p[1] || 0), 0);
        
        var ctrText = "N/A";
        if (totalImpr > 0) {
            ctrText = ((totalClicks / totalImpr) * 100).toFixed(2) + "%";
        } else if (totalClicks === 0) {
             ctrText = "0%";
        }

        if (kpiCtrEl) kpiCtrEl.textContent = ctrText;

        // Calculate Average Position
        // Note: Position data is tricky to slice exactly without the original array index, 
        // but for this summary we approximate based on the filtered X range
        if (positions && points.length) {
            var startX = clickSeriesArr.length ? clickSeriesArr[0][0] : 0;
            var endX = clickSeriesArr.length ? clickSeriesArr[clickSeriesArr.length-1][0] : 0;
            
            var validPos = points.filter(p => p.x >= startX && p.x <= endX && p.position > 0);
            if(validPos.length > 0) {
                var sumPos = validPos.reduce((acc, curr) => acc + curr.position, 0);
                var avgPos = sumPos / validPos.length;
                if (kpiPosEl) kpiPosEl.textContent = avgPos.toFixed(1);
            } else {
                if (kpiPosEl) kpiPosEl.textContent = "N/A";
            }
        }
    }

    function filterDataAndRender(days, customStartMs, customEndMs) {
        var endMs = customEndMs || Date.now();
        var startMs = customStartMs || (Date.now() - (days * 24 * 60 * 60 * 1000));
        
        var filteredClicks = fullClicksSeriesArr.filter(pt => pt[0] >= startMs && pt[0] <= endMs);
        var filteredImpr = fullImprSeriesArr.filter(pt => pt[0] >= startMs && pt[0] <= endMs);

        // Update Labels
        if (rangeLabelEl) {
            if (customStartMs) rangeLabelEl.textContent = "Custom Range";
            else rangeLabelEl.textContent = "Last " + days + " days";
        }
        if(rangeToggle) {
             var span = rangeToggle.querySelector('span');
             if(span) span.textContent = customStartMs ? "Custom" : "Last " + days + " days";
        }

        // Check if empty
        var isEmpty = (filteredClicks.length === 0 && filteredImpr.length === 0) || 
                      (filteredClicks.every(p => p[1] === 0) && filteredImpr.every(p => p[1] === 0));

        if (isEmpty) {
             if (currentChart) currentChart.destroy();
             chartElement.innerHTML = '<div class="flex items-center justify-center text-gray-400 bg-gray-50 dark:bg-neutral-900/50 rounded-lg border border-dashed border-gray-300 dark:border-neutral-600">No data available for this range.</div>';
             if (kpiCtrEl) kpiCtrEl.textContent = "N/A";
             if (kpiPosEl) kpiPosEl.textContent = "N/A";
             return;
        }

        renderChart(filteredClicks, filteredImpr);
        updateKPIs(filteredClicks, filteredImpr);
    }

    // Handle Dropdown Clicks
    if (rangeItems && rangeItems.length) {
        rangeItems.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var days = Number(btn.dataset.range) || 30;
                filterDataAndRender(days, null, null);
                closeMenu();
            });
        });
    }

    // Handle Custom Range Apply
    if (customApply) {
        customApply.addEventListener('click', function(e){
            e.stopPropagation();
            if(!startInput.value || !endInput.value) return;
            
            var s = new Date(startInput.value).getTime();
            var eDate = new Date(endInput.value).getTime();
            filterDataAndRender(null, s, eDate);
            closeMenu();
        });
    }

    // INITIAL RENDER (Last 30 Days)
    filterDataAndRender(30, null, null);

  } // end initGscChart

  if (document.readyState === "loading") {
    document.addEventListener('DOMContentLoaded', initGscChart);
  } else {
    initGscChart();
  }

})();