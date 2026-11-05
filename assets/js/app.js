/* ==========================================================================
   GeoTrack GIS — application core
   Authentication, map, entities, history, GIS analysis UI, IP geolocation,
   privacy controls, modals, toasts, and polling.
   ========================================================================== */

(function () {
    'use strict';

    const GT = (window.GeoTrack = window.GeoTrack || {});
    const boot = window.GEOTRACK_BOOTSTRAP || {};

    const TYPE_LABELS = {
        person: 'Person',
        laptop: 'Laptop',
        vehicle: 'Vehicle',
        asset: 'Asset',
        other: 'Other',
    };
    const TYPE_COLOURS = {
        person: '#2563eb',
        laptop: '#7c3aed',
        vehicle: '#059669',
        asset: '#d97706',
        other: '#64748b',
    };
    const TYPE_ICONS = {
        person: '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/>',
        laptop: '<path d="M5 5h14v10H5V5zm-2 12h18v2H3v-2zm5-10v8h8V7H8z"/>',
        vehicle: '<path d="M5.5 11 7 6.5h10L18.5 11H20a2 2 0 0 1 2 2v4h-2.1a2 2 0 0 1-3.8 0H8.9a2 2 0 0 1-3.8 0H3v-4a2 2 0 0 1 2-2h.5zm3.4 5a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>',
        asset: '<path d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.3 6.5 3.6L12 11.5 5.5 7.9 12 4.3zM5 9.6l6 3.3v6.4l-6-3.3V9.6zm8 9.7v-6.4l6-3.3v6.4l-6 3.3z"/>',
        other: '<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>',
    };

    const state = {
        user: boot.user || null,
        csrf: boot.csrf_token || '',
        app: boot.app || {},
        setupError: boot.setup_error || null,
        entities: new Map(),
        selectedId: null,
        map: null,
        markers: new Map(),
        trailPoints: new Map(),
        trailLines: new Map(),
        trailVisible: true,
        analysisLayer: null,
        tempLayer: null,
        pickTarget: null,
        selectedHistoryMarker: null,
        history: [],
        historyEntityId: null,
        historyOldestId: null,
        historyHasMore: false,
        historyLoaded: false,
        confirmedAuth: false,
        confirmResolve: null,
        loadingCount: 0,
        lastFocus: null,
        authExpiredShown: false,
        firstEntityLoad: true,
        demoTrailsLoaded: false,
        stats: null,
        network: null,
        ip: null,
        ipLoaded: false,
        tilesWarned: false,
        timers: {},
    };

    GT.state = state;
    GT.TYPE_LABELS = TYPE_LABELS;
    GT.TYPE_COLOURS = TYPE_COLOURS;

    /* ----------------------------------------------------------------------
       Tiny DOM helpers
       ---------------------------------------------------------------------- */

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function svgIcon(name, className = 'icon') {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', className);
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', '#i-' + name);
        svg.appendChild(use);
        return svg;
    }

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

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    GT.esc = esc;

    /* ----------------------------------------------------------------------
       Formatting
       ---------------------------------------------------------------------- */

    const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
    });
    const timeFormatter = new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });

    function asDate(value) {
        if (!value) return null;
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function fmtDateTime(value) {
        const d = asDate(value);
        return d ? dateTimeFormatter.format(d) : '—';
    }

    function fmtTime(value) {
        const d = asDate(value);
        return d ? timeFormatter.format(d) : '—';
    }

    function fmtRelative(value) {
        const d = asDate(value);
        if (!d) return '—';
        const seconds = Math.round((Date.now() - d.getTime()) / 1000);
        if (seconds < 8) return 'just now';
        if (seconds < 60) return seconds + 's ago';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        const days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }
    GT.fmtRelative = fmtRelative;
    GT.fmtDateTime = fmtDateTime;
    GT.fmtTime = fmtTime;

    function fmtDistance(metres) {
        const value = Number(metres);
        if (!Number.isFinite(value)) return '—';
        if (value < 1000) return Math.round(value) + ' m';
        return (value / 1000).toFixed(2) + ' km';
    }
    GT.fmtDistance = fmtDistance;

    function fmtSpeedKmh(mps) {
        const value = Number(mps);
        if (!Number.isFinite(value)) return '—';
        return (value * 3.6).toFixed(1) + ' km/h';
    }
    GT.fmtSpeedKmh = fmtSpeedKmh;

    function fmtCoord(lat, lon) {
        if (lat === null || lat === undefined || lon === null || lon === undefined) return '—';
        const a = Number(lat);
        const b = Number(lon);
        if (!Number.isFinite(a) || !Number.isFinite(b)) return '—';
        return a.toFixed(6) + ', ' + b.toFixed(6);
    }
    GT.fmtCoord = fmtCoord;

    function fmtDuration(seconds) {
        const value = Number(seconds);
        if (!Number.isFinite(value) || value <= 0) return '—';
        if (value < 60) return Math.round(value) + ' s';
        if (value < 3600) return (value / 60).toFixed(1) + ' min';
        if (value < 86400) return (value / 3600).toFixed(2) + ' h';
        return (value / 86400).toFixed(1) + ' days';
    }
    GT.fmtDuration = fmtDuration;

    function toLocalInputValue(date) {
        const d = date instanceof Date ? date : new Date(date);
        if (Number.isNaN(d.getTime())) return '';
        const pad = (n) => String(n).padStart(2, '0');
        return (
            d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
            'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
        );
    }
    GT.toLocalInputValue = toLocalInputValue;

    /* ----------------------------------------------------------------------
       Toasts, progress, busy states
       ---------------------------------------------------------------------- */

    const TOAST_ICONS = {
        success: 'check',
        error: 'warning',
        warning: 'warning',
        info: 'info',
    };

    function toast(message, type = 'info', title = '') {
        const container = $('#toast-container');
        if (!container) return;
        const node = el('div', 'toast toast--' + type);
        node.appendChild(svgIcon(TOAST_ICONS[type] || 'info'));
        const body = el('div', 'toast__body');
        if (title) body.appendChild(el('strong', '', title));
        body.appendChild(el('p', '', message));
        node.appendChild(body);
        const close = el('button', 'toast__close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.addEventListener('click', () => node.remove());
        node.appendChild(close);
        container.appendChild(node);
        window.setTimeout(() => {
            node.style.opacity = '0';
            node.style.transform = 'translateY(6px)';
            window.setTimeout(() => node.remove(), 220);
        }, type === 'error' ? 9000 : 5500);
    }
    GT.toast = toast;

    function beginLoading() {
        state.loadingCount += 1;
        const bar = $('#global-progress');
        if (bar) bar.hidden = false;
    }

    function endLoading() {
        state.loadingCount = Math.max(0, state.loadingCount - 1);
        if (state.loadingCount === 0) {
            const bar = $('#global-progress');
            if (bar) bar.hidden = true;
        }
    }

    function setBusy(button, busy) {
        if (!button) return;
        if (busy) {
            button.classList.add('is-loading');
            button.disabled = true;
        } else {
            button.classList.remove('is-loading');
            button.disabled = false;
        }
    }
    GT.setBusy = setBusy;

    /* ----------------------------------------------------------------------
       API client
       ---------------------------------------------------------------------- */

    async function api(path, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
        const init = {
            method,
            credentials: 'same-origin',
            headers,
            cache: 'no-store',
        };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            headers['X-CSRF-Token'] = state.csrf;
        }

        let response;
        try {
            response = await fetch('api/' + path, init);
        } catch (error) {
            setConnection(false);
            throw new Error('Network error: the server could not be reached. Check that Apache/PHP is running.');
        }

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (response.status === 401) {
            handleAuthExpired();
        }

        if (response.status === 419 && !options._retried) {
            const refreshed = await refreshSession();
            if (refreshed) {
                return api(path, Object.assign({}, options, { _retried: true }));
            }
        }

        setConnection(response.status < 500);

        if (!response.ok || !payload || payload.success !== true) {
            const apiError = payload && payload.error ? payload.error : {};
            const error = new Error(apiError.message || 'Request failed with HTTP ' + response.status + '.');
            error.status = response.status;
            error.code = apiError.code || 'http_error';
            error.details = apiError.details || null;
            error.authRequired = Boolean(apiError.auth_required);
            throw error;
        }

        return payload.data;
    }
    GT.api = api;

    async function refreshSession() {
        try {
            const response = await fetch('api/session.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) return false;
            const data = payload.data;
            state.csrf = data.csrf_token;
            state.user = data.user;
            state.app = data.app || state.app;
            const meta = $('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', state.csrf);
            renderAuth();
            if (data.authenticated) {
                closeModal('login-modal');
                loadDashboard();
            }
            return data.authenticated === true;
        } catch (error) {
            return false;
        }
    }
    GT.refreshSession = refreshSession;

    function setConnection(online) {
        const node = $('#connection-indicator');
        const text = $('#connection-text');
        if (!node || !text) return;
        node.classList.toggle('connection--online', online);
        node.classList.toggle('connection--offline', !online);
        text.textContent = online ? 'Server connected' : 'Server unreachable';
    }

    function handleAuthExpired() {
        if (state.authExpiredShown) return;
        state.authExpiredShown = true;
        state.user = null;
        renderAuth();
        showLogin('Your session expired or you are not signed in. Sign in again to continue.');
        GT.emit('auth-expired');
        window.setTimeout(() => {
            state.authExpiredShown = false;
        }, 5000);
    }

    /* ----------------------------------------------------------------------
       Modal management
       ---------------------------------------------------------------------- */

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('modal-open');
        const focusable = modal.querySelector('input:not([type="hidden"]), select, textarea, button:not([disabled]), [href]');
        window.setTimeout(() => {
            if (focusable) focusable.focus();
        }, 40);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (id === 'login-modal' && !state.user) return;
        modal.hidden = true;
        if (!$$('.modal:not([hidden])').length) {
            document.body.classList.remove('modal-open');
        }
        if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
            state.lastFocus.focus();
        }
    }

    function anyModalOpen() {
        return $$('.modal:not([hidden])').length > 0;
    }

    function confirmAction(options) {
        return new Promise((resolve) => {
            const title = $('#confirm-title');
            const message = $('#confirm-message');
            const ok = $('#confirm-ok');
            if (!title || !message || !ok) {
                resolve(window.confirm(options.message || 'Are you sure?'));
                return;
            }
            title.textContent = options.title || 'Confirm action';
            message.textContent = options.message || 'Are you sure?';
            ok.textContent = options.confirmLabel || 'Confirm';
            ok.className = 'btn ' + (options.danger === false ? 'btn--primary' : 'btn--danger');
            state.confirmResolve = resolve;
            openModal('confirm-modal');
        });
    }

    function closeConfirm(result) {
        const resolve = state.confirmResolve;
        state.confirmResolve = null;
        closeModal('confirm-modal');
        if (resolve) resolve(result);
    }
    GT.confirmAction = confirmAction;

    /* ----------------------------------------------------------------------
       Authentication UI
       ---------------------------------------------------------------------- */

    function showLogin(message) {
        const modal = $('#login-modal');
        if (!modal) return;
        if (message) {
            const err = $('#login-error');
            if (err) {
                err.textContent = message;
                err.hidden = false;
            }
        }
        modal.hidden = false;
        document.body.classList.add('modal-open');
        const shell = $('#app-shell');
        if (shell) {
            shell.classList.add('is-locked');
            if ('inert' in shell) shell.inert = true;
        }
        window.setTimeout(() => {
            const field = $('#login-username');
            if (field) field.focus();
        }, 60);
    }

    function renderAuth() {
        const authenticated = Boolean(state.user);
        const shell = $('#app-shell');
        if (shell) {
            shell.classList.toggle('is-locked', !authenticated);
            if ('inert' in shell) shell.inert = !authenticated;
        }
        const chip = $('#user-chip');
        const loginOpen = $('#login-open');
        const logout = $('#logout-btn');
        if (authenticated) {
            hide(chip);
            show(chip);
            setText('user-name', state.user.display_name || state.user.username);
            setText('user-role', state.user.role === 'admin' ? 'Administrator' : 'Signed in');
            hide(loginOpen);
            show(logout);
            hide('login-modal');
            document.body.classList.remove('modal-open');
        } else {
            hide(chip);
            show(loginOpen);
            hide(logout);
        }

        const isAdmin = authenticated && state.user.role === 'admin';
        ['clear-demo-data', 'purge-expired', 'entity-demo-row', 'selftest-btn'].forEach((id) => {
            const node = document.getElementById(id);
            if (node) node.hidden = !isAdmin;
        });
        setText('privacy-username', authenticated ? state.user.username : '—');
        setText('privacy-role', authenticated ? (state.user.role === 'admin' ? 'Administrator' : 'User') : '—');
    }

    /* ----------------------------------------------------------------------
       Tabs
       ---------------------------------------------------------------------- */

    const TAB_NAMES = ['analytics', 'analysis', 'history', 'ip', 'privacy'];

    function activateTab(name, options = {}) {
        if (!TAB_NAMES.includes(name)) name = 'analytics';
        $$('.tabs__btn').forEach((button) => {
            const active = button.dataset.tab === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        $$('.tab-panel').forEach((panel) => {
            const active = panel.id === 'tabpanel-' + name;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
        if (!options.fromHash) {
            history.replaceState(null, '', '#' + name);
        }
        GT.emit('tab-changed', { tab: name });
        if (name === 'ip' && !state.ipLoaded) {
            loadIpGeolocation();
        }
        if (name === 'history') {
            if (state.selectedId && !state.historyLoaded) {
                loadHistory(state.selectedId, { reset: true });
            }
            if (state.selectedId) {
                loadTrail(state.selectedId, { silent: true });
            }
        }
        if (name === 'analytics' && GT.analytics) {
            GT.analytics.resize();
        }
        if (name === 'privacy') {
            loadMaintenanceStatus();
        }
        if (name === 'analysis') {
            refreshAnalysisSelect();
        }
    }
    GT.activateTab = activateTab;

    /* ----------------------------------------------------------------------
       Map
       ---------------------------------------------------------------------- */

    function initMap() {
        const container = $('#map');
        if (!container) return;
        if (!window.L) {
            const loading = $('#map-loading');
            if (loading) {
                loading.innerHTML = '<span>Leaflet failed to load. Check assets/vendor/leaflet/leaflet.js.</span>';
            }
            toast('Interactive map library missing. Re-download the Leaflet vendor files.', 'error', 'Map unavailable');
            return;
        }

        const mapConfig = state.app.map || { center: { lat: -0.6117, lng: 30.6581 }, zoom: 14 };
        const map = L.map(container, {
            zoomControl: true,
            attributionControl: true,
            minZoom: 3,
            worldCopyJump: true,
        }).setView([mapConfig.center.lat, mapConfig.center.lng], mapConfig.zoom || 14);

        L.tileLayer(mapConfig.tile_url || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: mapConfig.tile_attribution || '&copy; OpenStreetMap contributors',
        })
            .on('tileerror', () => {
                if (state.tilesWarned) return;
                state.tilesWarned = true;
                toast('Some map tiles could not load. Check your internet connection; the rest of the dashboard still works.', 'warning', 'Map tiles');
            })
            .addTo(map);

        L.control.scale({ metric: true, imperial: false, position: 'bottomleft' }).addTo(map);

        state.map = map;
        state.analysisLayer = L.layerGroup().addTo(map);
        state.tempLayer = L.layerGroup().addTo(map);

        map.on('click', (event) => handleMapClick(event.latlng));
        map.whenReady(() => hide('#map-loading'));
        window.setTimeout(() => hide('#map-loading'), 3500);
    }

    function handleMapClick(latlng) {
        if (!state.pickTarget) return;
        const target = state.pickTarget;
        setPickTarget(null);
        const lat = Number(latlng.lat.toFixed(7));
        const lng = Number(latlng.lng.toFixed(7));
        if (target === 'distanceA' || target === 'distanceB') {
            const suffix = target === 'distanceA' ? '1' : '2';
            const latInput = document.getElementById('dist-lat' + suffix);
            const lonInput = document.getElementById('dist-lon' + suffix);
            if (latInput) latInput.value = lat;
            if (lonInput) lonInput.value = lng;
        } else if (target === 'proximity') {
            const latInput = $('#prox-lat');
            const lonInput = $('#prox-lon');
            if (latInput) latInput.value = lat;
            if (lonInput) lonInput.value = lng;
        }
        placeTempMarker(lat, lng, 'Selected map point');
        toast('Point selected: ' + lat.toFixed(6) + ', ' + lng.toFixed(6), 'info', 'Map pick');
    }

    function setPickTarget(target) {
        state.pickTarget = target;
        const mapEl = $('#map');
        if (mapEl) mapEl.classList.toggle('is-picking', Boolean(target));
        if (target) {
            toast('Click the map to choose the point.', 'info', 'Pick on map');
        }
    }
    GT.setPickTarget = setPickTarget;

    function placeTempMarker(lat, lng, label) {
        if (!state.map) return;
        state.tempLayer.clearLayers();
        const marker = L.circleMarker([lat, lng], {
            radius: 8,
            color: '#1d4ed8',
            weight: 3,
            fillColor: '#60a5fa',
            fillOpacity: 0.7,
        }).addTo(state.tempLayer);
        marker.bindTooltip(label, { permanent: false });
        state.map.panTo([lat, lng]);
    }

    function markerHtml(entity, selected) {
        const type = entity.entity_type || 'other';
        const isSim = entity.tracking_method === 'simulated' || entity.is_demo;
        const active = entity.tracking_status === 'active';
        const classes = [
            'gt-marker',
            'gt-marker--' + type,
            active ? 'gt-marker--active' : '',
            isSim ? 'gt-marker--sim' : '',
            selected ? 'gt-marker--selected' : '',
        ].filter(Boolean).join(' ');
        return (
            '<div class="' + classes + '">' +
            (active ? '<span class="gt-marker__pulse"></span>' : '') +
            '<svg viewBox="0 0 24 24" aria-hidden="true">' + (TYPE_ICONS[type] || TYPE_ICONS.other) + '</svg>' +
            (isSim ? '<span class="gt-marker__sim">SIM</span>' : '') +
            '<span class="gt-marker__label">' + esc(entity.entity_name) + '</span>' +
            '</div>'
        );
    }

    function buildPopup(entity) {
        const wrap = el('div', 'map-popup');
        const title = el('div', 'map-popup__title');
        const dot = el('span', 'history-entity__dot');
        dot.style.background = TYPE_COLOURS[entity.entity_type] || TYPE_COLOURS.other;
        title.appendChild(dot);
        title.appendChild(el('span', '', entity.entity_name));
        wrap.appendChild(title);
        wrap.appendChild(el('div', 'map-popup__meta', entity.entity_code + ' · ' + (TYPE_LABELS[entity.entity_type] || 'Other') + (entity.is_demo ? ' · DEMO' : '')));

        const loc = entity.last_location;
        const grid = el('div', 'map-popup__grid');
        const add = (label, value) => {
            grid.appendChild(el('span', '', label));
            grid.appendChild(el('strong', '', value));
        };
        if (loc) {
            add('Latitude', Number(loc.latitude).toFixed(6));
            add('Longitude', Number(loc.longitude).toFixed(6));
            add('Accuracy', loc.accuracy != null ? Math.round(loc.accuracy) + ' m' : '—');
            add('Speed', loc.speed != null ? fmtSpeedKmh(loc.speed) : '—');
            add('Updated', fmtRelative(loc.recorded_at));
            add('Source', loc.source_type || '—');
        } else {
            add('Position', 'No location yet');
        }
        wrap.appendChild(grid);

        const buttons = el('div', 'map-popup__buttons');
        const selectBtn = el('button', 'btn btn--primary', 'Select & trail');
        selectBtn.type = 'button';
        selectBtn.addEventListener('click', () => {
            selectEntity(entity.id, { zoom: true });
            state.map.closePopup();
        });
        const historyBtn = el('button', 'btn btn--secondary', 'History');
        historyBtn.type = 'button';
        historyBtn.addEventListener('click', () => {
            selectEntity(entity.id, { zoom: false });
            activateTab('history');
            loadHistory(entity.id, { reset: true });
            state.map.closePopup();
        });
        buttons.appendChild(selectBtn);
        buttons.appendChild(historyBtn);
        wrap.appendChild(buttons);
        return wrap;
    }

    function updateMarkerVisual(entity) {
        const entry = state.markers.get(entity.id);
        if (!entry) return;
        const selected = state.selectedId === entity.id;
        const signature = [
            entity.entity_type,
            entity.tracking_status,
            entity.tracking_method,
            entity.is_demo ? '1' : '0',
            selected ? '1' : '0',
            entity.entity_name,
        ].join('|');
        if (entry.signature !== signature) {
            entry.marker.setIcon(L.divIcon({
                className: 'gt-marker-wrap',
                html: markerHtml(entity, selected),
                iconSize: [34, 34],
                iconAnchor: [17, 17],
                popupAnchor: [0, -18],
            }));
            entry.signature = signature;
        }
        entry.marker.setPopupContent(buildPopup(entity));
    }

    function ensureMarker(entity) {
        if (!state.map) return null;
        let entry = state.markers.get(entity.id);
        if (!entry) {
            const marker = L.marker([0, 0], {
                icon: L.divIcon({
                    className: 'gt-marker-wrap',
                    html: markerHtml(entity, state.selectedId === entity.id),
                    iconSize: [34, 34],
                    iconAnchor: [17, 17],
                    popupAnchor: [0, -18],
                }),
                keyboard: true,
                title: entity.entity_name,
            });
            marker.on('click', () => selectEntity(entity.id, { zoom: false }));
            marker.addTo(state.map);
            entry = { marker, signature: null };
            state.markers.set(entity.id, entry);
        }
        return entry.marker;
    }

    function updateEntityMarker(entity) {
        const loc = entity.last_location;
        if (!loc || !Number.isFinite(Number(loc.latitude)) || !Number.isFinite(Number(loc.longitude))) {
            const entry = state.markers.get(entity.id);
            if (entry && entity.tracking_status === 'stopped' && !entry.marker.getLatLng().equals([0, 0])) {
                // Keep the last known marker so stopped entities do not vanish.
                updateMarkerVisual(entity);
            }
            return;
        }
        const marker = ensureMarker(entity);
        if (!marker) return;
        marker.setLatLng([Number(loc.latitude), Number(loc.longitude)]);
        updateMarkerVisual(entity);
    }

    function removeStaleMarkers() {
        state.markers.forEach((entry, id) => {
            if (!state.entities.has(id)) {
                if (state.map) state.map.removeLayer(entry.marker);
                state.markers.delete(id);
            }
        });
        state.trailPoints.forEach((points, id) => {
            if (!state.entities.has(id)) {
                state.trailPoints.delete(id);
                const line = state.trailLines.get(id);
                if (line && state.map) state.map.removeLayer(line);
                state.trailLines.delete(id);
            }
        });
        const empty = state.markers.size === 0;
        const emptyNode = $('#map-empty');
        if (emptyNode) emptyNode.hidden = !empty;
    }

    function selectEntity(id, options = {}) {
        id = Number(id);
        if (!state.entities.has(id)) return;
        state.selectedId = id;
        state.historyLoaded = false;
        setText('history-subtitle', 'Loading selected entity history…');
        renderEntityList();
        renderSelectedCard();
        refreshAnalysisSelect();
        state.markers.forEach((entry, entityId) => {
            const entity = state.entities.get(entityId);
            if (entity) updateMarkerVisual(entity);
        });
        const entity = state.entities.get(id);
        if (entity) {
            setText('map-selected-label', entity.entity_name + ' · ' + entity.entity_code + (entity.is_demo ? ' · simulated demo' : ''));
        }
        loadTrail(id, { silent: true });
        loadHistory(id, { reset: true, silent: options.silent });
        GT.emit('selected-changed', { entityId: id, entity });
        if (options.zoom) zoomToSelected();
        if (options.openHistory) activateTab('history');
    }
    GT.selectEntity = selectEntity;

    function clearSelectionUI() {
        state.selectedId = null;
        setText('map-selected-label', 'Select an entity to inspect its trail');
        hide('#selected-card');
        const tbody = $('#history-body');
        if (tbody) tbody.innerHTML = '';
        state.history = [];
        state.historyEntityId = null;
        state.historyLoaded = false;
        show('#history-empty');
        setText('history-count', '0 records');
        setText('history-subtitle', 'Select an entity to load its recorded positions.');
        const clear = $('#history-clear');
        if (clear) clear.disabled = true;
    }

    function zoomToSelected() {
        if (!state.map) return;
        const points = state.trailPoints.get(state.selectedId);
        if (points && points.length > 1) {
            const bounds = L.latLngBounds(points.map((p) => [p.latitude, p.longitude]));
            state.map.fitBounds(bounds.pad(0.25), { maxZoom: 17 });
            return;
        }
        const entity = state.entities.get(state.selectedId);
        if (entity && entity.last_location) {
            state.map.setView([entity.last_location.latitude, entity.last_location.longitude], 17);
            const entry = state.markers.get(entity.id);
            if (entry) entry.marker.openPopup();
        }
    }
    GT.zoomToSelected = zoomToSelected;

    function fitAll() {
        if (!state.map) return;
        const latlngs = [];
        state.markers.forEach((entry) => latlngs.push(entry.marker.getLatLng()));
        if (!latlngs.length) {
            const center = (state.app.map && state.app.map.center) || { lat: -0.6117, lng: 30.6581 };
            state.map.setView([center.lat, center.lng], (state.app.map && state.app.map.zoom) || 14);
            return;
        }
        const bounds = L.latLngBounds(latlngs);
        if (bounds.isValid()) {
            state.map.fitBounds(bounds.pad(0.35), { maxZoom: 16 });
        }
    }
    GT.fitAll = fitAll;

    /* ----------------------------------------------------------------------
       Trails
       ---------------------------------------------------------------------- */

    function renderTrail(entityId) {
        if (!state.map) return;
        const points = state.trailPoints.get(entityId) || [];
        const existing = state.trailLines.get(entityId);
        if (existing) {
            state.map.removeLayer(existing);
            state.trailLines.delete(entityId);
        }
        if (!state.trailVisible || points.length < 2) return;
        const entity = state.entities.get(entityId);
        const selected = state.selectedId === entityId;
        const simulated = entity && (entity.tracking_method === 'simulated' || entity.is_demo);
        const colour = simulated ? '#d97706' : (TYPE_COLOURS[entity ? entity.entity_type : 'other'] || '#2563eb');
        const line = L.polyline(points.map((p) => [p.latitude, p.longitude]), {
            color: colour,
            weight: selected ? 4.5 : 3,
            opacity: selected ? 0.9 : 0.32,
            dashArray: simulated ? '7 7' : null,
            lineJoin: 'round',
            lineCap: 'round',
            interactive: false,
        }).addTo(state.map);
        state.trailLines.set(entityId, line);
    }

    function renderAllTrails() {
        state.trailPoints.forEach((_points, entityId) => renderTrail(entityId));
    }

    function toggleTrails() {
        state.trailVisible = !state.trailVisible;
        const button = $('#map-trails-btn');
        if (button) {
            button.classList.toggle('is-active', state.trailVisible);
            button.setAttribute('aria-pressed', state.trailVisible ? 'true' : 'false');
        }
        renderAllTrails();
        if (!state.trailVisible) {
            state.trailLines.forEach((line) => state.map.removeLayer(line));
            state.trailLines.clear();
        }
    }

    async function loadTrail(entityId, options = {}) {
        if (!entityId) return;
        try {
            const data = await api('location_history.php?entity_id=' + encodeURIComponent(entityId) + '&limit=500&order=asc');
            if (state.selectedId !== Number(entityId)) {
                // Still keep trails for non-selected entities (e.g. demo overview).
            }
            state.trailPoints.set(Number(entityId), (data.records || []).map((record) => ({
                latitude: Number(record.latitude),
                longitude: Number(record.longitude),
                recorded_at: record.recorded_at,
                source_type: record.source_type,
            })));
            renderTrail(Number(entityId));
        } catch (error) {
            if (!options.silent) {
                toast(error.message, 'error', 'Could not load trail');
            }
        }
    }
    GT.loadTrail = loadTrail;

    function appendTrailPoint(entityId, point) {
        entityId = Number(entityId);
        const points = state.trailPoints.get(entityId) || [];
        const last = points[points.length - 1];
        if (last && last.latitude === point.latitude && last.longitude === point.longitude && last.recorded_at === point.recorded_at) {
            return;
        }
        points.push({
            latitude: Number(point.latitude),
            longitude: Number(point.longitude),
            recorded_at: point.recorded_at,
            source_type: point.source_type,
        });
        const max = (state.app.simulation && state.app.simulation.max_local_points) || 800;
        if (points.length > max) points.splice(0, points.length - max);
        state.trailPoints.set(entityId, points);
        renderTrail(entityId);
    }
    GT.appendTrailPoint = appendTrailPoint;

    /* Live update API used by tracking.js */
    function setLivePosition(entityId, latitude, longitude, meta = {}) {
        const entity = state.entities.get(Number(entityId));
        if (!entity) return;
        const recorded = meta.recorded_at || new Date().toISOString();
        entity.last_location = {
            latitude: Number(latitude),
            longitude: Number(longitude),
            accuracy: meta.accuracy != null ? Number(meta.accuracy) : null,
            speed: meta.speed != null ? Number(meta.speed) : null,
            recorded_at: recorded,
            source_type: meta.source_type || entity.tracking_method || 'gps',
        };
        entity.last_seen_at = recorded;
        if (meta.status) entity.tracking_status = meta.status;
        state.entities.set(entity.id, entity);
        updateEntityMarker(entity);
        appendTrailPoint(entity.id, {
            latitude: latitude,
            longitude: longitude,
            recorded_at: recorded,
            source_type: meta.source_type || entity.tracking_method,
        });
        if (state.selectedId === entity.id) {
            renderSelectedCard();
        }
        const empty = $('#map-empty');
        if (empty) empty.hidden = state.markers.size > 0;
    }
    GT.setLivePosition = setLivePosition;

    function updateEntityStatus(entityId, status) {
        const entity = state.entities.get(Number(entityId));
        if (!entity) return;
        entity.tracking_status = status;
        state.entities.set(entity.id, entity);
        updateMarkerVisual(entity);
        renderEntityList();
        if (state.selectedId === entity.id) renderSelectedCard();
    }
    GT.updateEntityStatus = updateEntityStatus;

    /* ----------------------------------------------------------------------
       Entities
       ---------------------------------------------------------------------- */

    async function refreshEntities(options = {}) {
        if (!state.user) return;
        try {
            const data = await api('entities.php');
            const incoming = new Map();
            (data.entities || []).forEach((entity) => {
                entity.id = Number(entity.id);
                if (entity.last_location) {
                    entity.last_location.latitude = Number(entity.last_location.latitude);
                    entity.last_location.longitude = Number(entity.last_location.longitude);
                }
                incoming.set(entity.id, entity);
            });
            state.entities = incoming;

            incoming.forEach((entity) => updateEntityMarker(entity));
            removeStaleMarkers();

            if (state.selectedId && !incoming.has(state.selectedId)) {
                clearSelectionUI();
            }
            if (!state.selectedId) {
                let candidate = null;
                incoming.forEach((entity) => {
                    if (!candidate && entity.last_location) candidate = entity;
                });
                if (candidate) {
                    selectEntity(candidate.id, { silent: true });
                }
            }

            renderEntityList();
            renderSelectedCard();
            refreshAnalysisSelect();
            updateEntityKpis();

            if (!state.demoTrailsLoaded && incoming.size) {
                state.demoTrailsLoaded = true;
                loadDemoTrails();
            }
            if (state.firstEntityLoad) {
                state.firstEntityLoad = false;
                window.setTimeout(fitAll, 250);
            }
            GT.emit('entities-updated', { count: incoming.size });
        } catch (error) {
            if (!options.silent) toast(error.message, 'error', 'Could not load entities');
        }
    }
    GT.refreshEntities = refreshEntities;

    async function loadDemoTrails() {
        const demoIds = [];
        state.entities.forEach((entity) => {
            if (entity.is_demo && entity.point_count > 0) demoIds.push(entity.id);
        });
        await Promise.allSettled(demoIds.map((id) => loadTrail(id, { silent: true })));
        if (!state.selectedId) fitAll();
    }

    function updateEntityKpis() {
        const total = state.entities.size;
        let active = 0;
        let demo = 0;
        state.entities.forEach((entity) => {
            if (entity.tracking_status === 'active') active += 1;
            if (entity.is_demo) demo += 1;
        });
        setText('kpi-entities', total);
        setText('kpi-entities-sub', demo + ' demo · ' + (total - demo) + ' real/registered');
        setText('kpi-active', active);
        setText('kpi-active-sub', active ? 'Tracking now' : 'No active tracking');
        setText('entity-count-label', total);
    }

    function getFilteredEntities() {
        const term = ($('#entity-search') ? $('#entity-search').value : '').trim().toLowerCase();
        const type = $('#entity-filter') ? $('#entity-filter').value : '';
        const list = [];
        state.entities.forEach((entity) => {
            if (type && entity.entity_type !== type) return;
            if (term) {
                const haystack = (entity.entity_name + ' ' + entity.entity_code + ' ' + entity.entity_type).toLowerCase();
                if (!haystack.includes(term)) return;
            }
            list.push(entity);
        });
        list.sort((a, b) => {
            if (a.is_demo !== b.is_demo) return a.is_demo ? 1 : -1;
            const aActive = a.tracking_status === 'active' ? 0 : 1;
            const bActive = b.tracking_status === 'active' ? 0 : 1;
            if (aActive !== bActive) return aActive - bActive;
            return a.entity_name.localeCompare(b.entity_name);
        });
        return list;
    }

    function statusLabel(status) {
        return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
    }

    function renderEntityList() {
        const list = $('#entity-list');
        if (!list) return;
        const entities = getFilteredEntities();
        list.innerHTML = '';
        const fragment = document.createDocumentFragment();
        entities.forEach((entity) => {
            const li = el('li', 'entity-item' + (entity.id === state.selectedId ? ' is-selected' : ''));
            li.tabIndex = 0;
            li.setAttribute('role', 'button');
            li.setAttribute('aria-pressed', entity.id === state.selectedId ? 'true' : 'false');
            li.setAttribute('aria-label', entity.entity_name + ', ' + (TYPE_LABELS[entity.entity_type] || 'entity'));

            const icon = el('span', 'entity-item__icon entity-item__icon--' + (entity.entity_type || 'other'));
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.innerHTML = TYPE_ICONS[entity.entity_type] || TYPE_ICONS.other;
            icon.appendChild(svg);
            li.appendChild(icon);

            const main = el('div', 'entity-item__main');
            const name = el('div', 'entity-item__name');
            name.appendChild(el('span', '', entity.entity_name));
            if (entity.is_demo) {
                const sim = el('span', 'badge badge--amber', 'DEMO');
                name.appendChild(sim);
            }
            main.appendChild(name);
            const meta = el('div', 'entity-item__meta');
            meta.appendChild(el('code', '', entity.entity_code));
            meta.appendChild(el('span', '', '· ' + (TYPE_LABELS[entity.entity_type] || 'Other')));
            meta.appendChild(el('span', '', '· ' + entity.tracking_method));
            main.appendChild(meta);
            li.appendChild(main);

            const right = el('div', 'entity-item__right');
            const status = el('span', 'mini-status mini-status--' + entity.tracking_status);
            status.appendChild(el('span', 'mini-status__dot'));
            status.appendChild(el('span', '', statusLabel(entity.tracking_status)));
            right.appendChild(status);
            const when = entity.last_location ? entity.last_location.recorded_at : entity.last_seen_at;
            right.appendChild(el('span', 'entity-item__time', fmtRelative(when)));
            li.appendChild(right);

            li.addEventListener('click', () => selectEntity(entity.id));
            li.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectEntity(entity.id);
                }
            });
            fragment.appendChild(li);
        });
        list.appendChild(fragment);
        const empty = $('#entity-empty');
        if (empty) empty.hidden = entities.length > 0;
    }
    GT.renderEntityList = renderEntityList;

    function renderSelectedCard() {
        const entity = state.entities.get(state.selectedId);
        const card = $('#selected-card');
        if (!entity) {
            if (card) card.hidden = true;
            return;
        }
        if (card) card.hidden = false;
        setText('selected-name', entity.entity_name);
        setText('selected-code', entity.entity_code);
        setText('selected-type', TYPE_LABELS[entity.entity_type] || entity.entity_type);
        setText('selected-method', entity.tracking_method.toUpperCase());
        setText('selected-last', entity.last_location ? fmtRelative(entity.last_location.recorded_at) : 'No fix');
        setText('selected-coords', entity.last_location ? fmtCoord(entity.last_location.latitude, entity.last_location.longitude) : '—');
        const status = $('#selected-status');
        if (status) {
            status.textContent = statusLabel(entity.tracking_status);
            status.className = 'badge badge--' + (
                entity.tracking_status === 'active' ? 'green' :
                entity.tracking_status === 'paused' ? 'amber' :
                entity.tracking_status === 'stopped' ? 'grey' : 'blue'
            );
        }
        const del = $('#selected-delete');
        if (del) del.hidden = !entity.can_manage;
        const clear = $('#history-clear');
        if (clear) clear.disabled = !entity.can_manage;
    }

    function refreshAnalysisSelect() {
        const select = $('#move-entity');
        if (!select) return;
        const previous = select.value || (state.selectedId ? String(state.selectedId) : '');
        select.innerHTML = '';
        const all = Array.from(state.entities.values()).sort((a, b) => a.entity_name.localeCompare(b.entity_name));
        all.forEach((entity) => {
            const option = el('option', '', entity.entity_name + ' (' + entity.entity_code + ')');
            option.value = entity.id;
            select.appendChild(option);
        });
        if (state.selectedId && state.entities.has(state.selectedId)) {
            select.value = String(state.selectedId);
        } else if (previous) {
            select.value = previous;
        }
    }

    function openAddEntity() {
        const error = $('#add-entity-error');
        if (error) error.hidden = true;
        const form = $('#add-entity-form');
        if (form) form.reset();
        const method = $('#entity-method');
        if (method) method.value = 'gps';
        const demo = $('#entity-demo');
        if (demo) demo.checked = false;
        openModal('add-entity-modal');
    }

    async function submitAddEntity(event) {
        event.preventDefault();
        const submit = $('#add-entity-submit');
        const errorBox = $('#add-entity-error');
        if (errorBox) errorBox.hidden = true;
        const name = ($('#entity-name') ? $('#entity-name').value : '').trim();
        if (name.length < 2) {
            if (errorBox) {
                errorBox.textContent = 'Enter a name with at least two characters.';
                errorBox.hidden = false;
            }
            return;
        }
        const demo = $('#entity-demo') && $('#entity-demo').checked;
        const method = demo ? 'simulated' : ($('#entity-method') ? $('#entity-method').value : 'manual');
        const payload = {
            entity_name: name,
            entity_type: $('#entity-type') ? $('#entity-type').value : 'other',
            tracking_method: method,
            notes: $('#entity-notes') ? $('#entity-notes').value.trim() : '',
            is_demo: demo,
        };
        setBusy(submit, true);
        try {
            const data = await api('entities.php', { method: 'POST', body: payload });
            closeModal('add-entity-modal');
            toast('Registered ' + data.entity.entity_name + ' (' + data.entity.entity_code + ').', 'success', 'Asset registered');
            await refreshEntities({ silent: true });
            if (data.entity && data.entity.id) selectEntity(data.entity.id);
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
        } finally {
            setBusy(submit, false);
        }
    }

    async function deleteSelectedEntity() {
        const entity = state.entities.get(state.selectedId);
        if (!entity || !entity.can_manage) return;
        const ok = await confirmAction({
            title: 'Delete tracked entity',
            message: 'Delete "' + entity.entity_name + '" and all of its location history? This cannot be undone.',
            confirmLabel: 'Delete entity',
        });
        if (!ok) return;
        try {
            await api('entities.php', { method: 'DELETE', body: { entity_id: entity.id } });
            toast('Entity and its history were deleted.', 'success', 'Deleted');
            clearSelectionUI();
            await refreshEntities({ silent: true });
            if (GT.analytics) GT.analytics.refresh(true);
            GT.emit('entity-deleted', { entityId: entity.id });
        } catch (error) {
            toast(error.message, 'error', 'Delete failed');
        }
    }

    async function addEntityDemoToggle() {
        const demo = $('#entity-demo');
        const method = $('#entity-method');
        if (!demo || !method) return;
        if (demo.checked) {
            method.value = 'simulated';
            method.disabled = true;
        } else {
            method.disabled = false;
            if (method.value === 'simulated') method.value = 'gps';
        }
    }

    /* ----------------------------------------------------------------------
       History
       ---------------------------------------------------------------------- */

    function historyRow(record, entity) {
        const tr = el('tr');
        const timeCell = el('td', '', fmtDateTime(record.recorded_at));
        tr.appendChild(timeCell);

        const entityCell = el('td');
        const wrap = el('span', 'history-entity');
        const dot = el('span', 'history-entity__dot');
        dot.style.background = TYPE_COLOURS[entity ? entity.entity_type : 'other'] || TYPE_COLOURS.other;
        wrap.appendChild(dot);
        wrap.appendChild(el('span', '', entity ? entity.entity_name : 'Entity ' + record.entity_id));
        if (entity && entity.is_demo) wrap.appendChild(el('span', 'tag tag--amber', 'SIM'));
        entityCell.appendChild(wrap);
        tr.appendChild(entityCell);

        tr.appendChild(el('td', 'coord-cell', Number(record.latitude).toFixed(6)));
        tr.appendChild(el('td', 'coord-cell', Number(record.longitude).toFixed(6)));
        tr.appendChild(el('td', 'col-optional', record.accuracy != null ? Math.round(record.accuracy) + ' m' : '—'));
        tr.appendChild(el('td', 'col-optional', record.speed != null ? fmtSpeedKmh(record.speed) : '—'));

        const sourceCell = el('td');
        sourceCell.appendChild(el('span', 'source-badge source-badge--' + (record.source_type || 'manual'), record.source_type || '—'));
        tr.appendChild(sourceCell);

        const qualityCell = el('td');
        qualityCell.appendChild(el('span', 'quality-badge quality-badge--' + (record.quality_flag || 'ok'), record.quality_flag || 'ok'));
        tr.appendChild(qualityCell);

        const actionCell = el('td', 'col-action');
        const button = el('button', 'row-map-btn', 'Show');
        button.type = 'button';
        button.setAttribute('aria-label', 'Show this record on the map');
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            showRecordOnMap(record);
        });
        actionCell.appendChild(button);
        tr.appendChild(actionCell);

        tr.addEventListener('click', () => showRecordOnMap(record));
        return tr;
    }

    async function loadHistory(entityId, options = {}) {
        entityId = Number(entityId);
        if (!entityId || !state.user) return;
        const tbody = $('#history-body');
        const errorBox = $('#maintenance-error');
        if (!tbody) return;
        if (options.reset) {
            tbody.innerHTML = '';
            state.history = [];
            state.historyOldestId = null;
            state.historyHasMore = false;
        }
        const entity = state.entities.get(entityId);
        if (!entity) return;
        if (!options.silent) beginLoading();
        try {
            const params = new URLSearchParams({
                entity_id: String(entityId),
                limit: options.limit || '100',
                order: 'desc',
            });
            if (options.beforeId) params.set('before_id', String(options.beforeId));
            const data = await api('location_history.php?' + params.toString());
            if (state.selectedId !== entityId && state.historyEntityId !== entityId) {
                return;
            }
            const records = data.records || [];
            state.historyEntityId = entityId;
            state.historyHasMore = Boolean(data.has_more);
            state.historyOldestId = data.oldest_id || null;
            if (options.beforeId) {
                state.history = state.history.concat(records);
            } else {
                state.history = records;
            }
            const fragment = document.createDocumentFragment();
            records.forEach((record) => fragment.appendChild(historyRow(record, entity)));
            tbody.appendChild(fragment);
            state.historyLoaded = true;

            setText('history-count', state.history.length + ' record' + (state.history.length === 1 ? '' : 's'));
            setText('history-subtitle', entity.entity_name + ' · ' + entity.entity_code + (entity.is_demo ? ' · simulated demo' : ''));
            const note = $('#history-table-note');
            if (note) {
                note.textContent = state.historyHasMore
                    ? 'Showing the newest ' + state.history.length + ' records. Load older records below.'
                    : 'All available records are shown (' + state.history.length + ').';
            }
            const older = $('#history-load-older');
            if (older) older.hidden = !state.historyHasMore;
            const empty = $('#history-empty');
            if (empty) empty.hidden = state.history.length > 0;
            const clear = $('#history-clear');
            if (clear) clear.disabled = !entity.can_manage || state.history.length === 0;
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
            if (!options.silent) toast(error.message, 'error', 'History error');
        } finally {
            if (!options.silent) endLoading();
        }
    }
    GT.loadHistory = loadHistory;

    function showRecordOnMap(record) {
        if (!state.map) return;
        state.tempLayer.clearLayers();
        const lat = Number(record.latitude);
        const lng = Number(record.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const marker = L.circleMarker([lat, lng], {
            radius: 9,
            color: '#0b2a6b',
            weight: 3,
            fillColor: '#60a5fa',
            fillOpacity: 0.85,
        }).addTo(state.tempLayer);
        const accuracy = Number(record.accuracy);
        if (Number.isFinite(accuracy) && accuracy > 0 && accuracy <= 5000) {
            L.circle([lat, lng], {
                radius: accuracy,
                color: '#2563eb',
                weight: 1,
                opacity: 0.5,
                fillColor: '#93c5fd',
                fillOpacity: 0.1,
                interactive: false,
            }).addTo(state.tempLayer);
        }
        const entity = state.entities.get(Number(record.entity_id));
        marker.bindPopup(
            '<div class="map-popup"><div class="map-popup__title">' + esc(entity ? entity.entity_name : 'History record') + '</div>' +
            '<div class="map-popup__meta">' + esc(fmtDateTime(record.recorded_at)) + '</div>' +
            '<div class="map-popup__grid"><span>Latitude</span><strong>' + lat.toFixed(6) + '</strong>' +
            '<span>Longitude</span><strong>' + lng.toFixed(6) + '</strong>' +
            '<span>Accuracy</span><strong>' + (record.accuracy != null ? Math.round(record.accuracy) + ' m' : '—') + '</strong>' +
            '<span>Source</span><strong>' + esc(record.source_type) + '</strong></div></div>'
        ).openPopup();
        state.map.setView([lat, lng], Math.max(state.map.getZoom(), 17));
        activateTab('history');
    }
    GT.showRecordOnMap = showRecordOnMap;

    async function clearHistory() {
        const entity = state.entities.get(state.selectedId);
        if (!entity) return;
        const ok = await confirmAction({
            title: 'Clear location history',
            message: 'Delete all ' + state.history.length + ' loaded/stored record(s) for "' + entity.entity_name + '"? This cannot be undone.',
            confirmLabel: 'Clear history',
        });
        if (!ok) return;
        try {
            const data = await api('location_history.php', {
                method: 'DELETE',
                body: { entity_id: entity.id },
            });
            state.trailPoints.delete(entity.id);
            renderTrail(entity.id);
            state.tempLayer.clearLayers();
            await loadHistory(entity.id, { reset: true });
            await refreshEntities({ silent: true });
            if (GT.analytics) GT.analytics.refresh(true);
            toast('Deleted ' + data.deleted_count + ' record(s) for ' + entity.entity_name + '.', 'success', 'History cleared');
        } catch (error) {
            toast(error.message, 'error', 'Could not clear history');
        }
    }

    /* ----------------------------------------------------------------------
       GIS analysis UI
       ---------------------------------------------------------------------- */

    function resultBox(id, html, variant) {
        const box = document.getElementById(id);
        if (!box) return;
        box.className = 'result-box' + (variant ? ' result-' + variant : '');
        box.innerHTML = html;
        box.hidden = false;
    }

    function analysisErrorBox(id, message) {
        resultBox(id, '<strong>Could not complete the analysis.</strong><br>' + esc(message), 'error');
    }

    function resultMetric(label, value) {
        return '<div><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
    }

    async function submitDistance(event) {
        event.preventDefault();
        const button = $('#dist-submit');
        const ids = ['dist-lat1', 'dist-lon1', 'dist-lat2', 'dist-lon2'];
        const values = {};
        for (const id of ids) {
            const input = document.getElementById(id);
            values[id] = input ? Number(input.value) : NaN;
        }
        if (Object.values(values).some((value) => !Number.isFinite(value))) {
            analysisErrorBox('distance-result', 'Enter valid numeric coordinates for both points.');
            return;
        }
        setBusy(button, true);
        try {
            const data = await api('analysis.php', {
                method: 'POST',
                body: {
                    action: 'distance',
                    lat1: values['dist-lat1'],
                    lon1: values['dist-lon1'],
                    lat2: values['dist-lat2'],
                    lon2: values['dist-lon2'],
                },
            });
            const result = data.analysis;
            const metres = Number(result.distance_m);
            resultBox(
                'distance-result',
                '<h4>Distance result</h4>' +
                '<div class="result-grid">' +
                resultMetric('Distance (m)', metres.toFixed(1) + ' m') +
                resultMetric('Distance (km)', Number(result.distance_km).toFixed(3) + ' km') +
                resultMetric('Initial bearing', Number(result.bearing_deg).toFixed(1) + '° ' + result.bearing_cardinal) +
                resultMetric('Method', 'Haversine') +
                (result.geodesic_distance_m ? resultMetric('Geodesic (WGS 84)', Number(result.geodesic_distance_m).toFixed(1) + ' m') : '') +
                '</div>' +
                '<p class="fine-print">From ' + esc(fmtCoord(result.point_a.latitude, result.point_a.longitude)) +
                ' to ' + esc(fmtCoord(result.point_b.latitude, result.point_b.longitude)) +
                '. Python computed this result.</p>'
            );
            state.analysisLayer.clearLayers();
            if (state.map) {
                L.polyline([
                    [result.point_a.latitude, result.point_a.longitude],
                    [result.point_b.latitude, result.point_b.longitude],
                ], { color: '#7c3aed', weight: 3, dashArray: '6 6' }).addTo(state.analysisLayer);
                L.circleMarker([result.point_a.latitude, result.point_a.longitude], { radius: 6, color: '#1d4ed8', fillColor: '#fff', fillOpacity: 1, weight: 3 }).addTo(state.analysisLayer);
                L.circleMarker([result.point_b.latitude, result.point_b.longitude], { radius: 6, color: '#7c3aed', fillColor: '#fff', fillOpacity: 1, weight: 3 }).addTo(state.analysisLayer);
                state.map.fitBounds(L.latLngBounds([
                    [result.point_a.latitude, result.point_a.longitude],
                    [result.point_b.latitude, result.point_b.longitude],
                ]).pad(0.3), { maxZoom: 17 });
            }
            toast(fmtDistance(metres) + ' between the two points.', 'success', 'Python distance');
        } catch (error) {
            analysisErrorBox('distance-result', error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function submitProximity(event) {
        event.preventDefault();
        const button = $('#prox-submit');
        const lat = Number($('#prox-lat') ? $('#prox-lat').value : NaN);
        const lon = Number($('#prox-lon') ? $('#prox-lon').value : NaN);
        const radius = Number($('#prox-radius') ? $('#prox-radius').value : NaN);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || !Number.isFinite(radius)) {
            analysisErrorBox('proximity-result', 'Enter a valid origin and radius.');
            return;
        }
        const types = $$('input[name="prox-types"]:checked').map((node) => node.value);
        setBusy(button, true);
        try {
            const data = await api('analysis.php', {
                method: 'POST',
                body: {
                    action: 'proximity',
                    latitude: lat,
                    longitude: lon,
                    radius_m: radius,
                    types,
                },
            });
            const result = data.analysis;
            state.proximityResult = result;
            const matches = result.results || [];
            let html = '<h4>' + esc(result.summary) + '</h4>';
            if (!matches.length) {
                html += '<p class="muted">No tracked entity currently has a known position inside this radius. Try a larger radius or start a simulation.</p>';
            } else {
                html += '<ul class="result-list">';
                matches.forEach((match) => {
                    html += '<li><span><strong>' + esc(match.entity_name) + '</strong> ' +
                        (match.is_demo ? '<span class="tag tag--amber">DEMO</span>' : '') +
                        '<br><span class="result-list__meta">' + esc(TYPE_LABELS[match.entity_type] || match.entity_type) +
                        ' · ' + esc(match.entity_code) + ' · ' + esc(match.tracking_status || '') + '</span></span>' +
                        '<span class="result-list__dist">' + fmtDistance(match.distance_m) + '</span></li>';
                });
                html += '</ul>';
            }
            html += '<p class="fine-print">Origin ' + esc(fmtCoord(lat, lon)) + ' · radius ' + fmtDistance(radius) + ' · nearest first. Distance computed in Python.</p>';
            resultBox('proximity-result', html);
            show('#prox-map-actions');
            toast(result.matched_count + ' entity/entities within ' + fmtDistance(radius) + '.', 'success', 'Proximity analysis');
        } catch (error) {
            analysisErrorBox('proximity-result', error.message);
        } finally {
            setBusy(button, false);
        }
    }

    function showProximityOnMap() {
        if (!state.map || !state.proximityResult) return;
        const result = state.proximityResult;
        state.analysisLayer.clearLayers();
        L.circle([result.origin.latitude, result.origin.longitude], {
            radius: Number(result.radius_m),
            color: '#7c3aed',
            weight: 2,
            fillColor: '#a78bfa',
            fillOpacity: 0.08,
        }).addTo(state.analysisLayer);
        L.circleMarker([result.origin.latitude, result.origin.longitude], {
            radius: 7,
            color: '#5b21b6',
            weight: 3,
            fillColor: '#fff',
            fillOpacity: 1,
        }).bindTooltip('Proximity origin').addTo(state.analysisLayer);
        const bounds = [[result.origin.latitude, result.origin.longitude]];
        result.results.forEach((match) => {
            const point = [Number(match.latitude), Number(match.longitude)];
            bounds.push(point);
            L.circleMarker(point, {
                radius: 7,
                color: '#7c3aed',
                weight: 2,
                fillColor: '#c4b5fd',
                fillOpacity: 0.9,
            }).bindPopup('<strong>' + esc(match.entity_name) + '</strong><br>' + fmtDistance(match.distance_m)).addTo(state.analysisLayer);
        });
        state.map.fitBounds(L.latLngBounds(bounds).pad(0.35), { maxZoom: 16 });
    }

    async function submitMovement(event) {
        event.preventDefault();
        const button = $('#move-submit');
        const entityId = Number($('#move-entity') ? $('#move-entity').value : 0);
        if (!entityId) {
            analysisErrorBox('movement-result', 'Select an entity first.');
            return;
        }
        const fromValue = $('#move-from') ? $('#move-from').value : '';
        const toValue = $('#move-to') ? $('#move-to').value : '';
        const body = { action: 'movement', entity_id: entityId };
        if (fromValue) body.from = new Date(fromValue).toISOString();
        if (toValue) body.to = new Date(toValue).toISOString();
        setBusy(button, true);
        try {
            const data = await api('analysis.php', { method: 'POST', body });
            const analysis = data.analysis;
            if (!analysis.valid_point_count) {
                resultBox(
                    'movement-result',
                    '<h4>No analysable movement</h4><p>' + esc(analysis.summary) + '</p>' +
                    '<p class="fine-print">' + esc((analysis.rejected_points || []).length) + ' point(s) were rejected as invalid.</p>',
                    'warning'
                );
            } else {
                let html = '<h4>Movement summary · ' + esc(data.entity.entity_name) + '</h4><div class="result-grid">' +
                    resultMetric('Total distance', fmtDistance(analysis.total_distance_m)) +
                    resultMetric('Valid points', analysis.valid_point_count + ' / ' + analysis.point_count) +
                    resultMetric('Duration', fmtDuration(analysis.duration_seconds)) +
                    resultMetric('Average speed', Number(analysis.avg_speed_kmh).toFixed(2) + ' km/h') +
                    resultMetric('Max speed', Number(analysis.max_speed_kmh).toFixed(2) + ' km/h (' + analysis.max_speed_source + ')') +
                    resultMetric('Review flags', analysis.suspicious_legs) +
                    '</div>';
                html += '<p class="fine-print">First: ' + esc(fmtCoord(analysis.first.latitude, analysis.first.longitude)) +
                    ' (' + esc(fmtDateTime(analysis.first.recorded_at)) + ')<br>Last: ' +
                    esc(fmtCoord(analysis.last.latitude, analysis.last.longitude)) + ' (' + esc(fmtDateTime(analysis.last.recorded_at)) + ')</p>';
                if (data.truncated) {
                    html += '<p class="fine-print">Only the most recent ' + data.analysed_points + ' of ' + data.total_available + ' points were analysed.</p>';
                }
                if (analysis.warnings && analysis.warnings.length) {
                    html += '<ul class="warning-list">';
                    analysis.warnings.slice(0, 8).forEach((warning) => {
                        html += '<li>' + svgIcon('warning').outerHTML + '<span>' + esc(warning.message) + '</span></li>';
                    });
                    html += '</ul>';
                    html += '<p class="fine-print">Warnings are data-quality flags, not conclusions about a person\'s behaviour or movement.</p>';
                }
                resultBox('movement-result', html);
            }
            toast('Movement analysis computed in Python.', 'success', 'Analysis complete');
        } catch (error) {
            analysisErrorBox('movement-result', error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function submitValidation(event) {
        event.preventDefault();
        const button = $('#val-submit');
        const lat = Number($('#val-lat') ? $('#val-lat').value : NaN);
        const lon = Number($('#val-lon') ? $('#val-lon').value : NaN);
        const accuracy = $('#val-accuracy') && $('#val-accuracy').value !== '' ? Number($('#val-accuracy').value) : null;
        const speed = $('#val-speed') && $('#val-speed').value !== '' ? Number($('#val-speed').value) : null;
        const timeValue = $('#val-time') ? $('#val-time').value : '';
        const record = {
            latitude: lat,
            longitude: lon,
            accuracy,
            speed,
            recorded_at: timeValue ? new Date(timeValue).toISOString() : new Date().toISOString(),
        };
        setBusy(button, true);
        try {
            const data = await api('analysis.php', { method: 'POST', body: { action: 'validate', record } });
            const result = data.validation;
            let html = '<h4>' + (result.valid ? '✓ Record passes validation' : '✕ Record has validation errors') + '</h4>';
            if (result.errors && result.errors.length) {
                html += '<ul class="warning-list">' + result.errors.map((message) => '<li>' + svgIcon('warning').outerHTML + '<span>' + esc(message) + '</span></li>').join('') + '</ul>';
            }
            if (result.warnings && result.warnings.length) {
                html += '<ul class="warning-list">' + result.warnings.map((warning) => '<li>' + svgIcon('warning').outerHTML + '<span>' + esc(warning.message) + '</span></li>').join('') + '</ul>';
            }
            if (result.valid && !result.warnings.length) {
                html += '<p class="fine-print">Coordinates and record fields passed Python range and plausibility checks.</p>';
            }
            resultBox('validation-result', html, result.valid ? null : 'error');
        } catch (error) {
            analysisErrorBox('validation-result', error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function runSelftest() {
        const button = $('#selftest-btn');
        const box = $('#selftest-result');
        setBusy(button, true);
        if (box) {
            box.className = 'result-box result-box--muted';
            box.innerHTML = '<p><span class="spinner spinner--dark"></span> Running Python self-test…</p>';
        }
        try {
            const data = await api('analysis.php', { method: 'POST', body: { action: 'selftest' } });
            const result = data.selftest;
            let html = '<h4>' + (result.ok ? '✓ Python GIS module healthy' : '✕ Python self-test failed') + '</h4>';
            html += '<div class="result-grid">' +
                resultMetric('Tests passed', result.passed + ' / ' + result.total) +
                resultMetric('Python', result.python_version) +
                resultMetric('geopy optional', result.geopy_available ? 'installed' : 'not installed') +
                resultMetric('Module version', result.module_version) +
                '</div><ul class="result-list">';
            (result.tests || []).forEach((test) => {
                html += '<li><span>' + (test.passed ? '✓' : '✕') + ' ' + esc(test.name) + '<br><span class="result-list__meta">' + esc(test.detail) + '</span></span></li>';
            });
            html += '</ul>';
            if (box) {
                box.className = 'result-box';
                box.innerHTML = html;
            }
            toast(result.ok ? 'All Python GIS tests passed.' : 'Python self-test reported a failure.', result.ok ? 'success' : 'error', 'Python GIS');
        } catch (error) {
            if (box) {
                box.className = 'result-box result-error';
                box.innerHTML = '<strong>Python self-test unavailable.</strong><br>' + esc(error.message);
            }
            toast(error.message, 'error', 'Python unavailable');
        } finally {
            setBusy(button, false);
        }
    }

    /* ----------------------------------------------------------------------
       IP geolocation
       ---------------------------------------------------------------------- */

    async function loadIpGeolocation() {
        if (!state.user || state.ipLoaded) return;
        state.ipLoaded = true;
        const button = $('#ip-lookup-btn');
        if (button) setBusy(button, true);
        hide('ip-error');
        hide('ip-sample-banner');
        try {
            const data = await api('ip_geolocation.php');
            state.ip = data;
            renderIpData(data);
        } catch (error) {
            state.ipLoaded = false;
            const errorBox = $('#ip-error');
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
            setText('ip-status', 'error');
            setText('ip-source', 'Lookup failed');
            setText('ip-location', '—');
            toast(error.message, 'error', 'IP geolocation');
        } finally {
            if (button) setBusy(button, false);
        }
    }

    function renderIpData(data) {
        const sample = data.sample || {};
        const useSample = data.is_sample === true;
        const source = data.source || data.provider || 'Unknown';
        const statusLabels = {
            ok: 'live result',
            disabled: 'sample · provider disabled',
            unavailable: 'sample · private/local IP',
            error: 'sample · provider unavailable',
        };
        setText('ip-status', statusLabels[data.status] || data.status || 'ok');
        setText('ip-address', data.ip_masked || '—');
        setText('ip-time', fmtDateTime(data.looked_up_at));
        setText('ip-source', source);

        if (useSample) {
            show('ip-sample-banner');
            setText('ip-location', [sample.city, sample.region, sample.country].filter(Boolean).join(', ') || 'Sample location');
            setText('ip-region', [sample.country, sample.region].filter(Boolean).join(' / ') || '—');
            setText('ip-city', sample.city || '—');
            setText('ip-coords', fmtCoord(sample.latitude, sample.longitude));
            setText('ip-isp', 'Not available in sample');
            const banner = $('#ip-sample-banner');
            if (banner) {
                const paragraph = banner.querySelector('p');
                if (paragraph) {
                    paragraph.textContent = data.message
                        ? 'Sample/demo result: ' + data.message
                        : 'Sample/demo result. This is not a live lookup of your connection.';
                }
            }
            if (data.status === 'disabled' && data.setup_hint) {
                const note = $('#ip-api-note');
                if (note) note.textContent = data.setup_hint;
            }
        } else {
            hide('ip-sample-banner');
            setText('ip-location', [data.city, data.region, data.country].filter(Boolean).join(', ') || 'Approximate region only');
            setText('ip-region', [data.country, data.region].filter(Boolean).join(' / ') || '—');
            setText('ip-city', data.city || '—');
            setText('ip-coords', fmtCoord(data.latitude, data.longitude));
            setText('ip-isp', [data.isp, data.org].filter(Boolean).join(' · ') || '—');
        }

        const showMap = $('#ip-show-map');
        if (showMap) showMap.disabled = !(Number.isFinite(Number(sample.latitude)) || Number.isFinite(Number(data.latitude)));
        if (data.limitations && data.limitations.length) {
            const list = $('#ip-limitations');
            if (list) {
                list.innerHTML = '';
                data.limitations.forEach((text) => list.appendChild(el('li', '', text)));
            }
        }
        const note = $('#ip-api-note');
        if (note && data.status === 'ok' && !data.cache_hit) {
            note.textContent = 'Provider: ' + data.provider + '. The request was made by PHP on the server; no API key is exposed in the browser. Results are approximate and cached briefly.';
        }
        if (data.status === 'ok') {
            toast(data.cache_hit ? 'Loaded cached IP geolocation result.' : 'IP geolocation lookup completed.', 'success', 'IP geolocation');
        } else if (data.status === 'unavailable') {
            toast('Local/private address detected; showing a clearly labelled sample.', 'warning', 'IP geolocation');
        } else if (data.status === 'error') {
            toast('The provider did not return a live result; sample data is shown.', 'warning', 'IP geolocation');
        }
    }

    function showIpOnMap() {
        if (!state.map || !state.ip) return;
        const data = state.ip;
        const sample = data.sample || {};
        const lat = Number(data.is_sample ? sample.latitude : data.latitude);
        const lng = Number(data.is_sample ? sample.longitude : data.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        state.tempLayer.clearLayers();
        L.circle([lat, lng], {
            radius: 12000,
            color: '#7c3aed',
            weight: 2,
            dashArray: '5 5',
            fillColor: '#a78bfa',
            fillOpacity: 0.1,
        }).addTo(state.tempLayer);
        L.circleMarker([lat, lng], {
            radius: 7,
            color: '#6d28d9',
            weight: 3,
            fillColor: '#ddd6fe',
            fillOpacity: 1,
        }).bindPopup(
            '<strong>' + (data.is_sample ? 'Sample IP location' : 'Approximate IP location') + '</strong><br>' +
            esc([data.is_sample ? sample.city : data.city, data.is_sample ? sample.country : data.country].filter(Boolean).join(', ')) +
            '<br><span class="muted">Not a precise device position</span>'
        ).openPopup();
        state.map.setView([lat, lng], 11);
        toast('Showing an approximate network location — not a GPS fix.', 'info', 'IP location on map');
    }

    /* ----------------------------------------------------------------------
       Privacy / maintenance
       ---------------------------------------------------------------------- */

    async function loadMaintenanceStatus() {
        if (!state.user) return;
        try {
            const data = await api('maintenance.php');
            setText('my-records', data.my_records);
            setText('demo-records', data.demo_records);
            setText('retention-value', data.retention_days + ' days');
            setText('last-purge', data.last_purge ? fmtDateTime(data.last_purge) : 'Never');
            const note = $('#retention-note');
            if (note) note.textContent = data.auto_purge_note;
            const demoBtn = $('#clear-demo-data');
            if (demoBtn) demoBtn.hidden = !(data.is_admin === true);
            const purgeBtn = $('#purge-expired');
            if (purgeBtn) purgeBtn.hidden = !(data.is_admin === true);
        } catch (error) {
            // The privacy panel is secondary; do not spam the user.
            const box = $('#maintenance-error');
            if (box) {
                box.textContent = error.message;
                box.hidden = false;
            }
        }
    }

    async function maintenanceAction(action) {
        const errorBox = $('#maintenance-error');
        const successBox = $('#maintenance-success');
        if (errorBox) errorBox.hidden = true;
        if (successBox) successBox.hidden = true;

        const prompts = {
            clear_my_data: {
                title: 'Delete all my location data',
                message: 'Delete every stored location record owned by your account? Tracked entity records remain, but all their history is removed. This cannot be undone.',
                confirmLabel: 'Delete my data',
            },
            clear_demo_history: {
                title: 'Clear demonstration history',
                message: 'Delete all simulated location records? Demo entities remain and can generate a fresh demonstration.',
                confirmLabel: 'Clear demo history',
            },
            purge_expired: {
                title: 'Purge expired records',
                message: 'Delete records older than the retention window? This cannot be undone.',
                confirmLabel: 'Purge records',
            },
        };
        const ok = await confirmAction(prompts[action] || {});
        if (!ok) return;
        try {
            const data = await api('maintenance.php', { method: 'POST', body: { action } });
            if (successBox) {
                successBox.textContent = data.message || 'Done.';
                successBox.hidden = false;
            }
            toast(data.message || 'Maintenance action completed.', 'success', 'Data controls');
            await refreshEntities({ silent: true });
            if (state.selectedId) {
                await loadTrail(state.selectedId, { silent: true });
                await loadHistory(state.selectedId, { reset: true, silent: true });
            }
            if (action === 'clear_demo_history') {
                state.trailPoints.forEach((_points, id) => {
                    const entity = state.entities.get(id);
                    if (entity && entity.is_demo) {
                        state.trailPoints.delete(id);
                        renderTrail(id);
                    }
                });
                state.demoTrailsLoaded = false;
                GT.emit('demo-cleared');
            }
            await loadMaintenanceStatus();
            if (GT.analytics) GT.analytics.refresh(true);
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
            toast(error.message, 'error', 'Maintenance failed');
        }
    }

    async function submitPassword(event) {
        event.preventDefault();
        const current = $('#pw-current');
        const next = $('#pw-new');
        const confirm = $('#pw-confirm');
        const errorBox = $('#password-error');
        const successBox = $('#password-success');
        if (errorBox) errorBox.hidden = true;
        if (successBox) successBox.hidden = true;
        if (!current || !next || !confirm) return;
        if (next.value !== confirm.value) {
            if (errorBox) {
                errorBox.textContent = 'The new passwords do not match.';
                errorBox.hidden = false;
            }
            return;
        }
        const button = $('#pw-submit');
        setBusy(button, true);
        try {
            const data = await api('session.php', {
                method: 'POST',
                body: {
                    action: 'change_password',
                    current_password: current.value,
                    new_password: next.value,
                    confirm_password: confirm.value,
                },
            });
            state.csrf = data.csrf_token || state.csrf;
            const meta = $('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', state.csrf);
            if (successBox) {
                successBox.textContent = data.message || 'Password updated.';
                successBox.hidden = false;
            }
            current.value = '';
            next.value = '';
            confirm.value = '';
            toast('Password changed successfully.', 'success', 'Account security');
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
        } finally {
            setBusy(button, false);
        }
    }

    /* ----------------------------------------------------------------------
       Login / logout
       ---------------------------------------------------------------------- */

    async function submitLogin(event) {
        event.preventDefault();
        const button = $('#login-submit');
        const errorBox = $('#login-error');
        if (errorBox) errorBox.hidden = true;
        const username = $('#login-username') ? $('#login-username').value.trim() : '';
        const password = $('#login-password') ? $('#login-password').value : '';
        if (!username || !password) {
            if (errorBox) {
                errorBox.textContent = 'Enter both a username and password.';
                errorBox.hidden = false;
            }
            return;
        }
        setBusy(button, true);
        try {
            const data = await api('session.php', {
                method: 'POST',
                body: { action: 'login', username, password },
            });
            state.user = data.user;
            state.csrf = data.csrf_token;
            state.app = data.app || state.app;
            state.authExpiredShown = false;
            const meta = $('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', state.csrf);
            const pass = $('#login-password');
            if (pass) pass.value = '';
            closeModal('login-modal');
            renderAuth();
            toast('Signed in as ' + (data.user.display_name || data.user.username) + '.', 'success', 'Welcome');
            if (data.notice) toast(data.notice, 'warning', 'Default account');
            await loadDashboard();
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            }
        } finally {
            setBusy(button, false);
        }
    }

    async function logout() {
        const ok = await confirmAction({
            title: 'Sign out',
            message: 'Sign out of GeoTrack GIS? Any active GPS watch in this tab will stop when the page reloads.',
            confirmLabel: 'Sign out',
            danger: false,
        });
        if (!ok) return;
        try {
            await api('session.php', { method: 'POST', body: { action: 'logout' } });
        } catch (error) {
            // Reload regardless; the session may already be gone.
        }
        window.location.reload();
    }

    /* ----------------------------------------------------------------------
       Initial load and polling
       ---------------------------------------------------------------------- */

    function loadDashboard() {
        if (!state.user) return;
        refreshEntities({ silent: false });
        if (GT.analytics) GT.analytics.refresh(true);
        if (state.selectedId) {
            loadHistory(state.selectedId, { reset: true, silent: true });
        }
        loadMaintenanceStatus();
    }

    function startPolling() {
        stopPolling();
        state.timers.entities = window.setInterval(() => {
            if (!state.user || document.visibilityState !== 'visible') return;
            refreshEntities({ silent: true });
        }, 8000);
        state.timers.stats = window.setInterval(() => {
            if (!state.user || document.visibilityState !== 'visible') return;
            if (GT.analytics) GT.analytics.refresh(false);
        }, 15000);
        state.timers.trail = window.setInterval(() => {
            if (!state.user || document.visibilityState !== 'visible') return;
            if (state.selectedId) {
                loadTrail(state.selectedId, { silent: true });
                const historyTab = $('#tabpanel-history');
                if (historyTab && !historyTab.hidden) {
                    loadHistory(state.selectedId, { reset: true, silent: true });
                }
            }
        }, 12000);
    }

    function stopPolling() {
        Object.keys(state.timers).forEach((key) => {
            window.clearInterval(state.timers[key]);
        });
        state.timers = {};
    }

    function startClock() {
        const tick = () => {
            const node = $('#clock');
            if (node) {
                node.textContent = new Date().toLocaleTimeString(undefined, {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });
            }
            if (state.stats && state.stats.summary) {
                setText('kpi-updated', fmtRelative(state.stats.summary.last_update));
            }
        };
        tick();
        window.setInterval(tick, 1000);
    }

    /* ----------------------------------------------------------------------
       Event bindings
       ---------------------------------------------------------------------- */

    function bindEvents() {
        const loginForm = $('#login-form');
        if (loginForm) loginForm.addEventListener('submit', submitLogin);

        const loginOpen = $('#login-open');
        if (loginOpen) loginOpen.addEventListener('click', () => {
            const error = $('#login-error');
            if (error) error.hidden = true;
            showLogin();
        });

        const passwordToggle = $('#password-toggle');
        if (passwordToggle) {
            passwordToggle.addEventListener('click', () => {
                const field = $('#login-password');
                if (!field) return;
                const showing = field.type === 'text';
                field.type = showing ? 'password' : 'text';
                passwordToggle.textContent = showing ? 'Show' : 'Hide';
                passwordToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            });
        }

        const logoutBtn = $('#logout-btn');
        if (logoutBtn) logoutBtn.addEventListener('click', logout);

        $$('.tabs__btn').forEach((button) => {
            button.addEventListener('click', () => activateTab(button.dataset.tab));
        });
        $$('[data-tab-link]').forEach((button) => {
            button.addEventListener('click', () => activateTab(button.dataset.tabLink));
        });

        const mapFit = $('#map-fit-btn');
        if (mapFit) mapFit.addEventListener('click', fitAll);
        const trailsBtn = $('#map-trails-btn');
        if (trailsBtn) trailsBtn.addEventListener('click', toggleTrails);
        const locateBtn = $('#map-locate-btn');
        if (locateBtn) locateBtn.addEventListener('click', zoomToSelected);

        const search = $('#entity-search');
        if (search) search.addEventListener('input', renderEntityList);
        const filter = $('#entity-filter');
        if (filter) filter.addEventListener('change', renderEntityList);

        const addBtn = $('#add-entity-btn');
        if (addBtn) addBtn.addEventListener('click', openAddEntity);
        const addForm = $('#add-entity-form');
        if (addForm) addForm.addEventListener('submit', submitAddEntity);
        const demo = $('#entity-demo');
        if (demo) demo.addEventListener('change', addEntityDemoToggle);

        const zoomSelected = $('#selected-zoom');
        if (zoomSelected) zoomSelected.addEventListener('click', zoomToSelected);
        const selectedHistory = $('#selected-history');
        if (selectedHistory) selectedHistory.addEventListener('click', () => {
            if (state.selectedId) {
                activateTab('history');
                loadHistory(state.selectedId, { reset: true });
            }
        });
        const selectedDelete = $('#selected-delete');
        if (selectedDelete) selectedDelete.addEventListener('click', deleteSelectedEntity);

        const historyRefresh = $('#history-refresh');
        if (historyRefresh) historyRefresh.addEventListener('click', () => {
            if (!state.selectedId) {
                toast('Select an entity first.', 'warning', 'No entity selected');
                return;
            }
            loadHistory(state.selectedId, { reset: true });
            loadTrail(state.selectedId, { silent: true });
        });
        const historyClear = $('#history-clear');
        if (historyClear) historyClear.addEventListener('click', clearHistory);
        const loadOlder = $('#history-load-older');
        if (loadOlder) loadOlder.addEventListener('click', () => {
            if (!state.selectedId || !state.historyOldestId) return;
            loadHistory(state.selectedId, { beforeId: state.historyOldestId });
        });

        $$('[data-consent-cancel]').forEach((button) => button.addEventListener('click', () => {
            closeModal('consent-modal');
            GT.emit('gps-consent-declined');
        }));

        const consentCheckbox = $('#consent-checkbox');
        if (consentCheckbox) consentCheckbox.addEventListener('change', () => {
            const accept = $('#consent-accept');
            if (accept) accept.disabled = !consentCheckbox.checked;
        });
        const consentAccept = $('#consent-accept');
        if (consentAccept) consentAccept.addEventListener('click', () => {
            if (consentCheckbox && !consentCheckbox.checked) return;
            closeModal('consent-modal');
            GT.emit('gps-consent-granted');
        });

        const confirmOk = $('#confirm-ok');
        if (confirmOk) confirmOk.addEventListener('click', () => closeConfirm(true));
        $$('[data-confirm-cancel]').forEach((button) => button.addEventListener('click', () => closeConfirm(false)));

        $$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => {
            closeModal(button.dataset.closeModal);
        }));

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (!$('#confirm-modal').hidden) {
                closeConfirm(false);
                return;
            }
            if (!$('#consent-modal').hidden) {
                closeModal('consent-modal');
                GT.emit('gps-consent-declined');
                return;
            }
            if (!$('#add-entity-modal').hidden) {
                closeModal('add-entity-modal');
                return;
            }
            // The login modal cannot be dismissed while signed out.
        });

        const distanceForm = $('#distance-form');
        if (distanceForm) distanceForm.addEventListener('submit', submitDistance);
        const proximityForm = $('#proximity-form');
        if (proximityForm) proximityForm.addEventListener('submit', submitProximity);
        const movementForm = $('#movement-form');
        if (movementForm) movementForm.addEventListener('submit', submitMovement);
        const validationForm = $('#validate-form');
        if (validationForm) validationForm.addEventListener('submit', submitValidation);

        const distSelected = $('#dist-use-selected');
        if (distSelected) distSelected.addEventListener('click', () => {
            const entity = state.entities.get(state.selectedId);
            if (!entity || !entity.last_location) {
                toast('Select an entity with a known position first.', 'warning', 'No selected position');
                return;
            }
            $('#dist-lat1').value = Number(entity.last_location.latitude).toFixed(7);
            $('#dist-lon1').value = Number(entity.last_location.longitude).toFixed(7);
            toast('Point A filled from ' + entity.entity_name + '.', 'info', 'Distance tool');
        });
        const distGps = $('#dist-use-gps');
        if (distGps) distGps.addEventListener('click', () => {
            const fix = GT.tracking && GT.tracking.getLastFix ? GT.tracking.getLastFix() : null;
            if (!fix) {
                toast('No GPS fix is available yet. Start a GPS session or pick a point on the map.', 'warning', 'No GPS fix');
                return;
            }
            $('#dist-lat1').value = Number(fix.latitude).toFixed(7);
            $('#dist-lon1').value = Number(fix.longitude).toFixed(7);
            $('#dist-lat2').value = Number(fix.latitude).toFixed(7);
            $('#dist-lon2').value = Number(fix.longitude).toFixed(7);
            toast('Both points filled from your last GPS fix.', 'info', 'Distance tool');
        });
        const pickA = $('#dist-pick-a');
        if (pickA) pickA.addEventListener('click', () => setPickTarget('distanceA'));
        const pickB = $('#dist-pick-b');
        if (pickB) pickB.addEventListener('click', () => setPickTarget('distanceB'));
        const proxPick = $('#prox-pick');
        if (proxPick) proxPick.addEventListener('click', () => setPickTarget('proximity'));
        const proxCenter = $('#prox-use-center');
        if (proxCenter) proxCenter.addEventListener('click', () => {
            if (!state.map) return;
            const center = state.map.getCenter();
            $('#prox-lat').value = center.lat.toFixed(7);
            $('#prox-lon').value = center.lng.toFixed(7);
        });
        const proxShow = $('#prox-show-map');
        if (proxShow) proxShow.addEventListener('click', showProximityOnMap);
        const proxClear = $('#prox-clear-map');
        if (proxClear) proxClear.addEventListener('click', () => {
            state.analysisLayer.clearLayers();
            toast('Analysis overlay cleared.', 'info', 'Map');
        });

        const selftest = $('#selftest-btn');
        if (selftest) selftest.addEventListener('click', runSelftest);

        const analyticsRefresh = $('#analytics-refresh');
        if (analyticsRefresh) analyticsRefresh.addEventListener('click', () => {
            if (GT.analytics) GT.analytics.refresh(true);
        });

        const ipLookup = $('#ip-lookup-btn');
        if (ipLookup) ipLookup.addEventListener('click', () => {
            state.ipLoaded = false;
            loadIpGeolocation();
        });
        const ipShow = $('#ip-show-map');
        if (ipShow) ipShow.addEventListener('click', showIpOnMap);

        const passwordForm = $('#password-form');
        if (passwordForm) passwordForm.addEventListener('submit', submitPassword);

        const clearMine = $('#clear-my-data');
        if (clearMine) clearMine.addEventListener('click', () => maintenanceAction('clear_my_data'));
        const clearDemo = $('#clear-demo-data');
        if (clearDemo) clearDemo.addEventListener('click', () => maintenanceAction('clear_demo_history'));
        const purge = $('#purge-expired');
        if (purge) purge.addEventListener('click', () => maintenanceAction('purge_expired'));
        const refreshMaintenance = $('#refresh-maintenance');
        if (refreshMaintenance) refreshMaintenance.addEventListener('click', loadMaintenanceStatus);

        window.addEventListener('online', () => {
            setConnection(true);
            toast('Back online. Refreshing data.', 'success', 'Connection restored');
            refreshEntities({ silent: true });
            if (GT.analytics) GT.analytics.refresh(false);
        });
        window.addEventListener('offline', () => {
            setConnection(false);
            toast('You are offline. Tracking updates may not reach the server.', 'warning', 'Offline');
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible' || !state.user) return;
            refreshEntities({ silent: true });
            if (state.selectedId) loadTrail(state.selectedId, { silent: true });
        });
    }

    /* ----------------------------------------------------------------------
       Init
       ---------------------------------------------------------------------- */

    async function init() {
        const query = new URLSearchParams(window.location.search);
        if (query.get('demo') === '1') {
            // Reserved for demonstration links; no hidden behaviour.
        }

        const validationTime = $('#val-time');
        if (validationTime && !validationTime.value) {
            validationTime.value = toLocalInputValue(new Date());
        }

        bindEvents();
        initMap();
        startClock();
        renderAuth();

        if (state.setupError) {
            toast('Database setup error. See the sign-in dialog and README instructions.', 'error', 'Setup required');
        }

        if (state.user) {
            hide('login-modal');
            document.body.classList.remove('modal-open');
            startPolling();
            await loadDashboard();
        } else {
            const expired = boot.session_expired;
            showLogin(expired ? 'Your previous session expired. Sign in to continue.' : null);
        }

        const hash = (window.location.hash || '').replace('#', '');
        if (TAB_NAMES.includes(hash)) activateTab(hash, { fromHash: true });

        GT.emit('ready', { authenticated: Boolean(state.user) });
        if (state.user && GT.analytics) GT.analytics.refresh(true);

        setConnection(navigator.onLine);
    }

    GT.emit = function (name, detail) {
        document.dispatchEvent(new CustomEvent('geotrack:' + name, { detail: detail || {} }));
    };

    GT.on = function (name, handler) {
        document.addEventListener('geotrack:' + name, (event) => handler(event.detail || {}));
    };

    GT.selectedEntity = () => state.entities.get(state.selectedId) || null;
    GT.openModal = openModal;
    GT.closeModal = closeModal;
    GT.showLogin = showLogin;
    GT.renderAuth = renderAuth;
    GT.updateEntityKpis = updateEntityKpis;
    GT.loadMaintenanceStatus = loadMaintenanceStatus;
    GT.loadIpGeolocation = loadIpGeolocation;
    GT.activateTab = activateTab;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
