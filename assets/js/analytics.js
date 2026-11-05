/* ==========================================================================
   GeoTrack GIS — analytics module
   Chart.js visualisations and summary cards backed by MySQL statistics and
   Python movement analysis. Depends on window.GeoTrack from app.js.
   ========================================================================== */

(function () {
    'use strict';

    const GT = (window.GeoTrack = window.GeoTrack || {});
    const boot = window.GEOTRACK_BOOTSTRAP || {};

    const COLOURS = GT.TYPE_COLOURS || {
        person: '#2563eb',
        laptop: '#7c3aed',
        vehicle: '#059669',
        asset: '#d97706',
        other: '#64748b',
    };
    const TYPE_LABELS = GT.TYPE_LABELS || {
        person: 'Person',
        laptop: 'Laptop',
        vehicle: 'Vehicle',
        asset: 'Asset',
        other: 'Other',
    };

    const BLUE = '#2563eb';
    const BLUE_SOFT = 'rgba(37, 99, 235, 0.14)';
    const AMBER = '#d97706';
    const AMBER_SOFT = 'rgba(217, 119, 6, 0.18)';
    const GRID = '#e2e8f0';
    const MUTED = '#64748b';

    const charts = new Map();
    const runtime = {
        inFlight: false,
        queued: false,
        lastFetch: 0,
        lastErrorAt: 0,
        chartWarned: false,
        lastStats: null,
        lastNetwork: null,
    };

    const $ = (sel, root = document) => root.querySelector(sel);

    function setText(id, text) {
        const node = document.getElementById(id);
        if (node) node.textContent = text;
    }

    function show(node) {
        if (typeof node === 'string') node = document.getElementById(node);
        if (node) node.hidden = false;
    }

    function hide(node) {
        if (typeof node === 'string') node = document.getElementById(node);
        if (node) node.hidden = true;
    }

    function fmtNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function shortDate(value) {
        if (!value) return '';
        const date = new Date(String(value) + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function hasChartJs() {
        return typeof window.Chart === 'function';
    }

    function chartMissing() {
        if (!runtime.chartWarned) {
            runtime.chartWarned = true;
            if (GT.toast) {
                GT.toast('Chart.js was not loaded. Charts are hidden, but statistics and tables still work. Re-download assets/vendor/chartjs/chart.umd.min.js.', 'error', 'Charts unavailable');
            }
        }
        setText('distance-note', 'Chart.js is missing, so charts cannot be drawn. The distance table and KPIs still come from PHP/Python.');
        ['empty-distance', 'empty-types', 'empty-updates'].forEach((id) => {
            const box = document.getElementById(id);
            if (box) {
                const p = box.querySelector('p');
                if (p) p.textContent = 'Chart.js vendor file missing.';
                show(box);
            }
        });
    }

    function drawChart(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas || !hasChartJs()) return null;
        const existing = charts.get(id);
        if (existing) {
            existing.destroy();
            charts.delete(id);
        }
        try {
            const chart = new window.Chart(canvas.getContext('2d'), config);
            charts.set(id, chart);
            return chart;
        } catch (error) {
            if (GT.toast) GT.toast('A chart could not be drawn: ' + error.message, 'warning', 'Charts');
            return null;
        }
    }

    function baseOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 280 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { size: 11 },
                    bodyFont: { size: 11 },
                    padding: 8,
                    cornerRadius: 6,
                    displayColors: false,
                },
            },
        };
    }

    function renderDistanceChart(network, days) {
        const wrapEmpty = $('#empty-distance');
        const daily = network && Array.isArray(network.daily) ? network.daily : [];
        const valuesKm = daily.map((day) => Number(day.distance_m || 0) / 1000);
        const hasData = valuesKm.some((value) => value > 0);

        if (!hasChartJs()) return;
        if (!hasData) {
            if (wrapEmpty) show(wrapEmpty);
            const existing = charts.get('chart-distance');
            if (existing) {
                existing.destroy();
                charts.delete('chart-distance');
            }
            return;
        }
        if (wrapEmpty) hide(wrapEmpty);

        drawChart('chart-distance', {
            type: 'bar',
            data: {
                labels: daily.map((day) => shortDate(day.date)),
                datasets: [{
                    label: 'Distance (km)',
                    data: valuesKm,
                    backgroundColor: BLUE_SOFT,
                    borderColor: BLUE,
                    borderWidth: 1.5,
                    borderRadius: 5,
                    maxBarThickness: 34,
                }],
            },
            options: Object.assign(baseOptions(), {
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: MUTED, font: { size: 10 }, maxRotation: 0 },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: GRID },
                        border: { display: false },
                        ticks: {
                            color: MUTED,
                            font: { size: 10 },
                            callback: (value) => Number(value).toFixed(1) + ' km',
                        },
                    },
                },
                plugins: Object.assign(baseOptions().plugins, {
                    tooltip: Object.assign(baseOptions().plugins.tooltip, {
                        callbacks: {
                            label: (context) => Number(context.parsed.y).toFixed(2) + ' km · Python',
                        },
                    }),
                }),
            }),
        });

        setText(
            'distance-note',
            'Python Haversine analysis of ' + fmtNumber(network.total_valid_points) +
            ' valid points across ' + fmtNumber(network.entity_count) +
            ' entities over the last ' + days + ' days. Distances exclude rejected records.'
        );
    }

    function renderTypesChart(stats) {
        const empty = $('#empty-types');
        const counts = (stats && stats.type_counts) || {};
        const entries = Object.keys(TYPE_LABELS)
            .map((type) => ({ type, value: fmtNumber(counts[type]) }))
            .filter((entry) => entry.value > 0);

        if (!hasChartJs()) return;
        if (!entries.length) {
            if (empty) show(empty);
            const existing = charts.get('chart-types');
            if (existing) {
                existing.destroy();
                charts.delete('chart-types');
            }
            return;
        }
        if (empty) hide(empty);

        drawChart('chart-types', {
            type: 'doughnut',
            data: {
                labels: entries.map((entry) => TYPE_LABELS[entry.type]),
                datasets: [{
                    data: entries.map((entry) => entry.value),
                    backgroundColor: entries.map((entry) => COLOURS[entry.type] || COLOURS.other),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 4,
                }],
            },
            options: Object.assign(baseOptions(), {
                cutout: '62%',
                plugins: Object.assign(baseOptions().plugins, {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: MUTED,
                            boxWidth: 9,
                            boxHeight: 9,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 10 },
                            padding: 10,
                        },
                    },
                    tooltip: Object.assign(baseOptions().plugins.tooltip, {
                        callbacks: {
                            label: (context) => ' ' + context.label + ': ' + context.parsed + ' entit' + (context.parsed === 1 ? 'y' : 'ies'),
                        },
                    }),
                }),
            }),
        });
    }

    function renderUpdatesChart(stats) {
        const empty = $('#empty-updates');
        const daily = (stats && stats.daily_updates) || [];
        const values = daily.map((day) => fmtNumber(day.count));
        const hasData = values.some((value) => value > 0);

        if (!hasChartJs()) return;
        if (!hasData) {
            if (empty) show(empty);
            const existing = charts.get('chart-updates');
            if (existing) {
                existing.destroy();
                charts.delete('chart-updates');
            }
            return;
        }
        if (empty) hide(empty);

        drawChart('chart-updates', {
            type: 'bar',
            data: {
                labels: daily.map((day) => shortDate(day.date)),
                datasets: [{
                    label: 'Updates',
                    data: values,
                    backgroundColor: AMBER_SOFT,
                    borderColor: AMBER,
                    borderWidth: 1.5,
                    borderRadius: 5,
                    maxBarThickness: 26,
                }],
            },
            options: Object.assign(baseOptions(), {
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: MUTED, font: { size: 9.5 }, maxRotation: 0 },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: GRID },
                        border: { display: false },
                        ticks: {
                            color: MUTED,
                            font: { size: 9.5 },
                            precision: 0,
                        },
                    },
                },
                plugins: Object.assign(baseOptions().plugins, {
                    tooltip: Object.assign(baseOptions().plugins.tooltip, {
                        callbacks: {
                            label: (context) => context.parsed.y + ' update' + (context.parsed.y === 1 ? '' : 's'),
                        },
                    }),
                }),
            }),
        });
    }

    function renderRecent(stats) {
        const list = $('#recent-updates-list');
        const empty = $('#recent-empty');
        if (!list) return;
        list.innerHTML = '';
        const updates = (stats && stats.latest_updates) || [];
        if (!updates.length) {
            if (empty) show(empty);
            return;
        }
        if (empty) hide(empty);

        const fragment = document.createDocumentFragment();
        updates.forEach((record) => {
            const li = document.createElement('li');
            li.tabIndex = 0;
            li.setAttribute('role', 'button');
            li.title = 'Select ' + record.entity_name + ' and show this update on the map';

            const icon = document.createElement('span');
            icon.className = 'recent-list__icon';
            icon.style.background = COLOURS[record.entity_type] || COLOURS.other;
            li.appendChild(icon);

            const name = document.createElement('span');
            name.className = 'recent-list__name';
            name.appendChild(document.createTextNode(record.entity_name));
            if (record.is_demo) {
                const tag = document.createElement('span');
                tag.className = 'tag tag--amber';
                tag.style.marginLeft = '5px';
                tag.textContent = 'SIM';
                name.appendChild(tag);
            }
            li.appendChild(name);

            const coords = document.createElement('span');
            coords.className = 'recent-list__coords';
            coords.textContent = Number(record.latitude).toFixed(4) + ', ' + Number(record.longitude).toFixed(4);
            li.appendChild(coords);

            const time = document.createElement('span');
            time.className = 'recent-list__time';
            time.textContent = GT.fmtRelative ? GT.fmtRelative(record.recorded_at) : '';
            li.appendChild(time);

            const open = () => {
                if (GT.selectEntity) GT.selectEntity(record.entity_id, { zoom: true });
            };
            li.addEventListener('click', open);
            li.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    open();
                }
            });

            fragment.appendChild(li);
        });
        list.appendChild(fragment);
    }

    function renderDistanceTable(network) {
        const tbody = $('#distance-table-body');
        const empty = $('#distance-table-empty');
        if (!tbody) return;
        tbody.innerHTML = '';
        const rows = (network && network.entities) || [];

        if (!rows.length) {
            if (empty) show(empty);
        } else if (empty) {
            hide(empty);
        }

        const fragment = document.createDocumentFragment();
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.tabIndex = 0;
            tr.title = 'Select this entity on the map';

            const nameCell = document.createElement('td');
            const name = document.createElement('span');
            name.className = 'history-entity';
            const dot = document.createElement('span');
            dot.className = 'history-entity__dot';
            dot.style.background = COLOURS[row.entity_type] || COLOURS.other;
            name.appendChild(dot);
            name.appendChild(document.createTextNode(row.entity_name || 'Entity ' + row.entity_id));
            if (row.is_demo) {
                const tag = document.createElement('span');
                tag.className = 'tag tag--amber';
                tag.textContent = 'SIM';
                tag.style.marginLeft = '5px';
                name.appendChild(tag);
            }
            nameCell.appendChild(name);
            tr.appendChild(nameCell);

            const typeCell = document.createElement('td');
            typeCell.textContent = TYPE_LABELS[row.entity_type] || row.entity_type || '—';
            tr.appendChild(typeCell);

            const pointsCell = document.createElement('td');
            pointsCell.textContent = fmtNumber(row.valid_point_count);
            tr.appendChild(pointsCell);

            const distanceCell = document.createElement('td');
            distanceCell.className = 'coord-cell';
            distanceCell.textContent = GT.fmtDistance ? GT.fmtDistance(row.total_distance_m) : String(row.total_distance_m) + ' m';
            tr.appendChild(distanceCell);

            const flagCell = document.createElement('td');
            const flags = fmtNumber(row.suspicious_legs);
            const badge = document.createElement('span');
            badge.className = 'quality-badge quality-badge--' + (flags > 0 ? 'coarse' : 'ok');
            badge.textContent = flags > 0 ? flags + ' review' : 'none';
            flagCell.appendChild(badge);
            tr.appendChild(flagCell);

            const open = () => {
                if (GT.selectEntity) GT.selectEntity(row.entity_id, { zoom: true });
            };
            tr.addEventListener('click', open);
            tr.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    open();
                }
            });

            fragment.appendChild(tr);
        });
        tbody.appendChild(fragment);
    }

    function renderKpis(stats, network) {
        if (network) {
            setText('kpi-distance', GT.fmtDistance ? GT.fmtDistance(network.total_distance_m) : String(network.total_distance_m));
            setText(
                'kpi-distance-sub',
                fmtNumber(network.total_valid_points) + ' valid points · ' + fmtNumber(network.entity_count) + ' entities'
            );
        } else {
            setText('kpi-distance', '—');
            setText('kpi-distance-sub', 'Python analysis unavailable');
        }

        if (stats && stats.summary) {
            const summary = stats.summary;
            setText('kpi-updated', summary.last_update ? GT.fmtRelative(summary.last_update) : '—');
            setText(
                'kpi-updated-sub',
                summary.total_points
                    ? summary.updates_today + ' today · ' + summary.total_points + ' stored'
                    : 'No updates yet'
            );
        }

        if (network) {
            setText('distance-method', 'Python · Haversine');
        }
    }

    function reportError(error) {
        const now = Date.now();
        if (now - runtime.lastErrorAt < 30000) return;
        runtime.lastErrorAt = now;
        if (error && error.status === 401) return;
        if (GT.toast) GT.toast(error.message, 'error', 'Analytics');
    }

    async function refresh(force) {
        if (!GT.state.user) return;
        if (runtime.inFlight) {
            runtime.queued = true;
            return;
        }
        const now = Date.now();
        if (!force && now - runtime.lastFetch < 4000) return;

        runtime.inFlight = true;
        try {
            const days = Number(boot.app && boot.app.analysis && boot.app.analysis.network_days) || 7;
            const [statsResult, networkResult] = await Promise.allSettled([
                GT.api('stats.php'),
                GT.api('analysis.php', { method: 'POST', body: { action: 'network', days } }),
            ]);

            let stats = null;
            let network = null;

            if (statsResult.status === 'fulfilled') {
                stats = statsResult.value;
                runtime.lastStats = stats;
            } else {
                reportError(statsResult.reason);
                stats = runtime.lastStats;
            }

            if (networkResult.status === 'fulfilled') {
                network = networkResult.value.analysis;
                runtime.lastNetwork = network;
            } else {
                reportError(networkResult.reason);
                network = runtime.lastNetwork;
                const note = $('#distance-note');
                if (note) {
                    note.textContent = 'Python movement analysis failed: ' + networkResult.reason.message;
                }
            }

            if (stats) {
                renderKpis(stats, network);
                renderTypesChart(stats);
                renderUpdatesChart(stats);
                renderRecent(stats);
            }
            if (network) {
                renderDistanceChart(network, days);
                renderDistanceTable(network);
                if (networkResult.status === 'fulfilled') {
                    setText(
                        'distance-note',
                        'Python Haversine analysis of ' + fmtNumber(network.total_valid_points) +
                        ' valid points across ' + fmtNumber(network.entity_count) +
                        ' entities over the last ' + days + ' days.'
                    );
                }
            } else {
                const empty = $('#empty-distance');
                if (empty) show(empty);
                renderDistanceTable(null);
            }

            if (!hasChartJs()) chartMissing();
        } finally {
            runtime.inFlight = false;
            runtime.lastFetch = Date.now();
            if (runtime.queued) {
                runtime.queued = false;
                window.setTimeout(() => refresh(true), 120);
            }
        }
    }

    function resize() {
        charts.forEach((chart) => {
            try {
                chart.resize();
            } catch (error) {
                /* A destroyed chart can throw while the tab is hidden. */
            }
        });
    }

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        if (resizeTimer) window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(resize, 180);
    });

    GT.analytics = {
        refresh,
        resize,
        get charts() {
            return charts;
        },
    };

    // If app.js has already finished its initial load before this file was
    // parsed, make sure the charts still populate.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            if (GT.state && GT.state.user) refresh(true);
        });
    } else if (GT.state && GT.state.user) {
        window.setTimeout(() => refresh(true), 60);
    }
})();
