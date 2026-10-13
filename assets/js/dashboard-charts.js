/* Dashboard: live clock in the date stamp + Chart.js charts.
   Chart data comes from the JSON block rendered by dashboard.php. */
(function () {
    'use strict';

    // Live clock in the hero stamp
    var clock = document.querySelector('[data-clock]');
    if (clock) {
        var tick = function () {
            clock.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        };
        tick();
        setInterval(tick, 15000);
    }

    var dataEl = document.getElementById('dashboard-chart-data');
    if (!dataEl) { return; }

    // Without Chart.js (e.g. no internet for the CDN), show the data tables instead.
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-box').forEach(function (box) { box.style.display = 'none'; });
        document.querySelectorAll('.chart-table').forEach(function (d) { d.open = true; });
        return;
    }

    var data = JSON.parse(dataEl.textContent);
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Validated categorical slots (blue, orange) and chart chrome matching the app tokens.
    var SERIES_1 = '#2a78d6';
    var SERIES_2 = '#eb6834';
    var SURFACE  = '#ffffff';
    var INK      = '#1b2430';
    var MUTED    = '#5f6b67';
    var GRID     = '#e7ebe8';
    var AXIS     = '#cfd6d1';

    Chart.defaults.font.family = '"Atkinson Hyperlegible Next", "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = MUTED;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.animation = reduceMotion ? false : { duration: 450 };

    var peso = function (v) {
        return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var tooltip = {
        backgroundColor: INK,
        titleColor: '#ffffff',
        bodyColor: '#ffffff',
        titleFont: { weight: '600' },
        padding: 10,
        cornerRadius: 6,
        boxPadding: 4,
        usePointStyle: true
    };
    var legend = {
        position: 'top',
        align: 'start',
        labels: { color: INK, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10, padding: 16 }
    };
    var valueAxis = {
        beginAtZero: true,
        grid: { color: GRID, lineWidth: 1 },
        border: { display: false },
        ticks: { precision: 0, padding: 6 }
    };
    var categoryAxis = {
        grid: { display: false },
        border: { color: AXIS },
        ticks: { maxRotation: 0, autoSkipPadding: 12 }
    };
    var column = function (label, values, color) {
        return {
            label: label,
            data: values,
            backgroundColor: color,
            maxBarThickness: 24,
            borderRadius: 4,
            borderSkipped: 'start'
        };
    };
    var truncate = function (text, max) {
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    };
    var make = function (id, config) {
        var canvas = document.getElementById(id);
        return canvas ? new Chart(canvas, config) : null;
    };

    // 1. Daily attendance — single series, so no legend box (the card title names it).
    make('chart-attendance', {
        type: 'bar',
        data: { labels: data.labels, datasets: [column('Visits', data.attendance, SERIES_1)] },
        options: {
            plugins: { legend: { display: false }, tooltip: tooltip },
            scales: { x: categoryAxis, y: valueAxis }
        }
    });

    // 2. Borrowed vs returned — two lines on one shared count axis; monotone so curves never overshoot the data.
    var line = function (label, values, color) {
        return {
            label: label,
            data: values,
            borderColor: color,
            backgroundColor: color,
            borderWidth: 2,
            cubicInterpolationMode: 'monotone',
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBorderWidth: 2,
            pointHoverBorderColor: SURFACE,
            pointHitRadius: 12
        };
    };
    make('chart-activity', {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [line('Borrowed', data.borrowed, SERIES_1), line('Returned', data.returned, SERIES_2)]
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: legend, tooltip: tooltip },
            scales: { x: categoryAxis, y: valueAxis }
        }
    });

    // 3. Busiest hours — visits by hour of time-in.
    make('chart-hours', {
        type: 'bar',
        data: { labels: data.hourLabels, datasets: [column('Visits', data.hours, SERIES_1)] },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: Object.assign({}, tooltip, {
                    callbacks: {
                        title: function (items) { return 'Arrived ' + items[0].label + ' – ' + (data.hourLabels[items[0].dataIndex + 1] || 'later'); },
                        label: function (item) { return ' ' + item.formattedValue + ' visit(s)'; }
                    }
                })
            },
            scales: { x: categoryAxis, y: valueAxis }
        }
    });

    // 4. Penalties by month — assessed vs collected, grouped columns in pesos.
    make('chart-penalties', {
        type: 'bar',
        data: {
            labels: data.monthLabels,
            datasets: [column('Assessed', data.assessed, SERIES_1), column('Collected', data.collected, SERIES_2)]
        },
        options: {
            datasets: { bar: { categoryPercentage: 0.6, barPercentage: 0.9 } },
            plugins: {
                legend: legend,
                tooltip: Object.assign({}, tooltip, {
                    mode: 'index',
                    callbacks: { label: function (item) { return ' ' + item.dataset.label + ': ' + peso(item.raw); } }
                })
            },
            scales: {
                x: categoryAxis,
                y: Object.assign({}, valueAxis, {
                    ticks: { padding: 6, callback: function (v) { return '₱' + Number(v).toLocaleString('en-PH'); } }
                })
            }
        }
    });

    // 5. Inventory by category — stacked horizontal bars, 2px surface gap between segments,
    //    rounded only at the end of each bar.
    if (data.categories.length) {
        var available = data.categories.map(function (c) { return Number(c.available); });
        var onLoan    = data.categories.map(function (c) { return Number(c.on_loan); });
        var segment = function (label, values, color, radius) {
            return {
                label: label,
                data: values,
                backgroundColor: color,
                borderColor: SURFACE,
                borderWidth: 1,
                borderSkipped: 'start',
                borderRadius: radius,
                maxBarThickness: 24
            };
        };
        make('chart-categories', {
            type: 'bar',
            data: {
                labels: data.categories.map(function (c) { return truncate(c.name, 22); }),
                datasets: [
                    segment('On the shelf', available, SERIES_1, function (ctx) { return onLoan[ctx.dataIndex] > 0 ? 0 : 4; }),
                    segment('On loan', onLoan, SERIES_2, 4)
                ]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    legend: legend,
                    tooltip: Object.assign({}, tooltip, {
                        mode: 'index',
                        callbacks: {
                            title: function (items) { return data.categories[items[0].dataIndex].name; },
                            label: function (item) { return ' ' + item.dataset.label + ': ' + item.formattedValue + ' copies'; }
                        }
                    })
                },
                scales: {
                    x: Object.assign({}, valueAxis, { stacked: true }),
                    y: Object.assign({}, categoryAxis, { stacked: true, ticks: { color: INK } })
                }
            }
        });
    }

    // 6. Most borrowed titles — single series horizontal bars.
    if (data.topBooks.length) {
        make('chart-top-books', {
            type: 'bar',
            data: {
                labels: data.topBooks.map(function (b) { return truncate(b.title, 28); }),
                datasets: [column('Loans', data.topBooks.map(function (b) { return Number(b.times); }), SERIES_1)]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltip, {
                        callbacks: {
                            title: function (items) { return data.topBooks[items[0].dataIndex].title; },
                            label: function (item) { return ' ' + item.formattedValue + ' loan(s)'; }
                        }
                    })
                },
                scales: { x: valueAxis, y: Object.assign({}, categoryAxis, { ticks: { color: INK } }) }
            }
        });
    }
})();
