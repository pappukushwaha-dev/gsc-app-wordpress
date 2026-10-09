/**
 * AnalyticsChart.js
 * Modularized ApexCharts for GSC Reporting
 * Updated: Adds support for Brand/Non-Brand Donut Charts
 */

const AnalyticsCharts = {

    /**
     * 1. Sparkline Chart (For Top Cards)
     * @param {string} selector - ID of the element (e.g., "#clicks-chart")
     * @param {array} data - Array of numbers
     * @param {string} color - Hex color code
     */
    renderSparkline: function(selector, data, color) {
        // If data is empty, provide a flat line
        if (!data || data.length === 0) data = [0, 0, 0, 0, 0];

        var options = {
            series: [{
                name: 'Metric',
                data: data
            }],
            chart: {
                type: 'area',
                height: 50,
                sparkline: {
                    enabled: true
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
                colors: [color]
            },
            fill: {
                type: 'gradient',
                colors: [color],
                gradient: {
                    shade: 'light',
                    type: 'vertical',
                    shadeIntensity: 0.5,
                    gradientToColors: [`${color}00`], // Fade to transparent
                    opacityFrom: 0.5,
                    opacityTo: 0.1,
                    stops: [0, 100]
                }
            },
            tooltip: {
                fixed: {
                    enabled: false
                },
                x: {
                    show: false
                },
                y: {
                    title: {
                        formatter: function(seriesName) {
                            return ''
                        }
                    }
                },
                marker: {
                    show: false
                }
            }
        };

        // Clear previous if exists
        document.querySelector(selector).innerHTML = "";
        var chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
    },

    /**
     * 2. Main Dual-Axis Area Chart (Clicks vs Impressions)
     * @param {string} selector 
     * @param {array} categories - X-Axis labels (Queries or Dates)
     * @param {array} clicksData 
     * @param {array} imprData 
     */
    renderMainChart: function(selector, categories, clicksData, imprData) {
        var options = {
            series: [{
                name: 'Clicks',
                data: clicksData
            }, {
                name: 'Impressions',
                data: imprData
            }],
            chart: {
                height: 350,
                type: 'area',
                toolbar: {
                    show: false
                },
                fontFamily: 'inherit'
            },
            colors: ['#487FFF', '#45B369'], // Blue for Clicks, Green for Impressions
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.4,
                    opacityTo: 0.05,
                    stops: [0, 100]
                }
            },
            xaxis: {
                categories: categories,
                tooltip: {
                    enabled: false
                },
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: false
                },
                labels: {
                    style: {
                        colors: '#9ca3af',
                        fontSize: '12px'
                    },
                    formatter: function(val) {
                        // Truncate long query names
                        if (typeof val === 'string' && val.length > 15) return val.substring(0, 15) + '...';
                        return val;
                    }
                }
            },
            yaxis: [{
                    seriesName: 'Clicks',
                    labels: {
                        style: {
                            colors: '#487FFF'
                        },
                        formatter: (val) => val.toFixed(0)
                    },
                    title: {
                        text: "Clicks",
                        style: {
                            color: '#487FFF'
                        }
                    }
                },
                {
                    opposite: true,
                    seriesName: 'Impressions',
                    labels: {
                        style: {
                            colors: '#45B369'
                        },
                        formatter: (val) => {
                            if (val >= 1000) return (val / 1000).toFixed(1) + 'k';
                            return val;
                        }
                    },
                    title: {
                        text: "Impressions",
                        style: {
                            color: '#45B369'
                        }
                    }
                }
            ],
            grid: {
                borderColor: '#f1f1f1',
                strokeDashArray: 4,
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right'
            }
        };

        document.querySelector(selector).innerHTML = "";
        var chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
    },

    /**
     * 3. Scatter Plot (Position vs CTR)
     * Helps identify high-potential keywords (Low Pos, High CTR)
     * @param {string} selector
     * @param {array} dataPoints - Array of objects {x: pos, y: ctr, term: query}
     */
    renderOpportunityChart: function(selector, dataPoints) {
        var options = {
            series: [{
                name: "Queries",
                data: dataPoints
            }],
            chart: {
                height: 350,
                type: 'scatter',
                zoom: {
                    enabled: true,
                    type: 'xy'
                },
                toolbar: {
                    show: false
                }
            },
            colors: ['#F4941E'],
            xaxis: {
                title: {
                    text: 'Average Position'
                },
                tickAmount: 10,
                labels: {
                    formatter: function(val) {
                        return parseFloat(val).toFixed(1)
                    }
                }
            },
            yaxis: {
                title: {
                    text: 'CTR (%)'
                },
                tickAmount: 7
            },
            markers: {
                size: 6,
                hover: {
                    size: 10
                }
            },
            grid: {
                borderColor: '#f1f1f1',
            },
            tooltip: {
                custom: function({
                    series,
                    seriesIndex,
                    dataPointIndex,
                    w
                }) {
                    var data = w.config.series[seriesIndex].data[dataPointIndex];
                    return (
                        '<div class="px-3 py-2 bg-white border border-gray-200 rounded shadow-lg">' +
                        '<div class="font-bold text-gray-800">' + data.term + '</div>' +
                        '<div class="text-xs text-gray-500">Pos: ' + data.x + ' | CTR: ' + data.y + '%</div>' +
                        '</div>'
                    );
                }
            }
        };

        document.querySelector(selector).innerHTML = "";
        var chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
    },

    /**
     * 4. Top Queries Bar Chart
     * @param {string} selector
     * @param {array} categories
     * @param {array} data
     * @param {string} color
     */
    renderBarChart: function(selector, categories, data, color) {
        var options = {
            series: [{
                name: 'Clicks',
                data: data
            }],
            chart: {
                type: 'bar',
                height: 350,
                toolbar: {
                    show: false
                }
            },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    horizontal: true,
                    barHeight: '70%'
                }
            },
            dataLabels: {
                enabled: false
            },
            colors: [color],
            xaxis: {
                categories: categories,
            },
            grid: {
                strokeDashArray: 4,
                borderColor: '#e5e7eb'
            }
        };

        document.querySelector(selector).innerHTML = "";
        var chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
    },

    /**
     * 5. Donut Chart (For Brand vs Non-Brand)
     * @param {string} selector - e.g., "#brand-donut-chart"
     * @param {array} series - [Value1, Value2]
     * @param {array} labels - ['Label1', 'Label2']
     * @param {array} colors - ['#Hex1', '#Hex2']
     */
    renderDonutChart: function(selector, series, labels, colors) {
        // Handle empty data cases
        if (!series || series.every(item => item === 0)) {
            document.querySelector(selector).innerHTML = "<div class='text-center text-gray-400 py-10'>No data available</div>";
            return;
        }

        var options = {
            series: series,
            labels: labels,
            colors: colors || ['#487FFF', '#9CA3AF'],
            chart: {
                type: 'donut',
                height: 250,
                fontFamily: 'inherit'
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total',
                                formatter: function(w) {
                                    return w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString();
                                }
                            }
                        }
                    }
                }
            },
            dataLabels: {
                enabled: false
            },
            legend: {
                position: 'bottom'
            },
            stroke: {
                show: false
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return val.toLocaleString() + " clicks";
                    }
                }
            }
        };

        document.querySelector(selector).innerHTML = "";
        var chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
    }
};