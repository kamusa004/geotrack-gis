/* ==========================================================================
   GeoTrack GIS — tracking module
   Genuine browser GPS (watchPosition) and a clearly labelled simulation of
   fictional demo entities. Depends on window.GeoTrack from app.js.
   ========================================================================== */

(function () {
    'use strict';

    const GT = (window.GeoTrack = window.GeoTrack || {});
    const boot = window.GEOTRACK_BOOTSTRAP || {};

    const DEVICE_KEY = 'geotrack_device_entity_id';
    const GPS_OPTIONS = { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 };
    const GPS_MIN_SEND_MS = 3000;      // never write to MySQL more than ~20/min
    const GPS_MIN_MOVE_M = 4;          // but do write when the device has moved
    const GPS_MAX_AGE_MS = 12000;

    const DEMO_SPECS = [
        { code: 'P-001', type: 'person', name: 'Person 001 (Demo)', notes: 'Demonstration asset. Not a real person.' },
        { code: 'L-001', type: 'laptop', name: 'Laptop 001 (Demo)', notes: 'Demonstration asset. Not a real laptop.' },
        { code: 'V-001', type: 'vehicle', name: 'Vehicle 001 (Demo)', notes: 'Demonstration asset. Not a real vehicle.' },
        { code: 'A-001', type: 'asset', name: 'Research Asset 001 (Demo)', notes: 'Demonstration asset for proximity analysis.' },
    ];

    /* Fictional routes around the MUST campus, Mbarara. Never real people. */
    const DEMO_ROUTES = {
        person: [
            [-0.61170, 30.65810],
            [-0.61110, 30.65860],
            [-0.61070, 30.65930],
            [-0.61095, 30.66010],
            [-0.61165, 30.66000],
            [-0.61205, 30.65930],
            [-0.61190, 30.65850],
            [-0.61170, 30.65810],
        ],
        laptop: [
            [-0.61220, 30.65760],
            [-0.61195, 30.65795],
            [-0.61155, 30.65815],
            [-0.61140, 30.65855],
            [-0.61180, 30.65870],
            [-0.61210, 30.65820],
            [-0.61220, 30.65760],
        ],
        vehicle: [
            [-0.61020, 30.65720],
            [-0.60980, 30.65910],
            [-0.61040, 30.66100],
            [-0.61200, 30.66180],
            [-0.61330, 30.66110],
            [-0.61360, 30.65940],
            [-0.61280, 30.65780],
            [-0.61120, 30.65690],
            [-0.61020, 30.65720],
        ],
        asset: [
            [-0.61300, 30.65820],
            [-0.61280, 30.65855],
            [-0.61305, 30.65880],
            [-0.61330, 30.65850],
            [-0.61300, 30.65820],
        ],
    };

    const SIM_STEP_M = { person: 6, laptop: 3, vehicle: 25, asset: 2 };

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

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

    function fmt(value, digits) {
        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(digits) : '—';
    }

    function haversineM(lat1, lon1, lat2, lon2) {
        const R = 6371008.8;
        const toRad = Math.PI / 180;
        const dLat = (lat2 - lat1) * toRad;
        const dLon = (lon2 - lon1) * toRad;
        const a = Math.sin(dLat / 2) ** 2 +
            Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLon / 2) ** 2;
        return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function bearingDegrees(lat1, lon1, lat2, lon2) {
        const toRad = Math.PI / 180;
        const y = Math.sin((lon2 - lon1) * toRad) * Math.cos(lat2 * toRad);
        const x = Math.cos(lat1 * toRad) * Math.sin(lat2 * toRad) -
            Math.sin(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.cos((lon2 - lon1) * toRad);
        return (Math.atan2(y, x) / toRad + 360) % 360;
    }

    function showError(id, message) {
        const box = document.getElementById(id);
        if (!box) return;
        box.textContent = message;
        box.hidden = false;
    }

    function clearError(id) {
        const box = document.getElementById(id);
        if (!box) return;
        box.textContent = '';
        box.hidden = true;
    }

    /* ----------------------------------------------------------------------
       Shared state and header badge
       ---------------------------------------------------------------------- */

    const gps = {
        status: 'idle',        // idle | consent | requesting | active | paused | stopped | error
        watchId: null,
        pendingConsent: false,
        consented: false,
        paused: false,
        entityId: null,
        lastFix: null,
        distanceM: 0,
        saved: 0,
        lastSentAt: 0,
        lastSentPoint: null,
        sending: false,
        startedAt: null,
        failures: 0,
        errorShown: false,
    };

    const sim = {
        status: 'stopped',     // stopped | preparing | active | paused | error
        paused: false,
        timer: null,
        entities: [],
        paths: new Map(),
        indices: new Map(),
        distances: new Map(),
        updates: 0,
        totalM: 0,
        tickBusy: false,
        startedAt: null,
        failures: 0,
        lastPoints: new Map(),
    };

    function storeDeviceId(id) {
        try {
            window.localStorage.setItem(DEVICE_KEY, String(id));
        } catch (error) {
            /* Storage can be blocked; a fresh entity is created next time. */
        }
    }

    function readDeviceId() {
        try {
            const value = Number(window.localStorage.getItem(DEVICE_KEY));
            return Number.isInteger(value) && value > 0 ? value : null;
        } catch (error) {
            return null;
        }
    }

    function activeGps() {
        return ['requesting', 'active', 'paused', 'consent'].includes(gps.status);
    }

    function activeSim() {
        return ['preparing', 'active', 'paused'].includes(sim.status);
    }

    function refreshBadge() {
        const badge = $('#tracking-badge');
        const text = $('#tracking-badge-text');
        const stopAll = $('#quick-stop');
        if (!badge || !text) return;

        let kind = 'idle';
        let label = 'Tracking idle';

        if (gps.status === 'active') {
            kind = 'gps';
            label = 'GPS tracking active';
        } else if (gps.status === 'paused') {
            kind = 'paused';
            label = 'GPS paused';
        } else if (gps.status === 'requesting' || gps.status === 'consent') {
            kind = 'paused';
            label = 'Awaiting GPS consent';
        } else if (sim.status === 'active') {
            kind = 'sim';
            label = 'Simulation active (SIMULATED)';
        } else if (sim.status === 'paused' || sim.status === 'preparing') {
            kind = 'paused';
            label = sim.status === 'preparing' ? 'Preparing simulation' : 'Simulation paused';
        } else if (gps.status === 'error') {
            kind = 'stopped';
            label = 'Tracking error';
        } else if (gps.status === 'stopped' || sim.status === 'stopped') {
            kind = 'stopped';
            label = 'Tracking stopped';
        }

        badge.className = 'track-badge track-badge--' + kind;
        text.textContent = label;
        if (stopAll) stopAll.hidden = !(activeGps() || activeSim());
    }

    /* ----------------------------------------------------------------------
       GPS UI
       ---------------------------------------------------------------------- */

    function setGpsStatus(status) {
        gps.status = status;
        const labels = {
            idle: 'Not tracking',
            consent: 'Waiting for your consent',
            requesting: 'Requesting location permission…',
            active: gps.paused ? 'Tracking paused' : 'Tracking live',
            paused: 'Tracking paused',
            stopped: 'Tracking stopped',
            error: 'Tracking error',
        };
        const dot = $('#gps-state-dot');
        if (dot) {
            dot.className = 'status-dot ' + (
                status === 'active' ? 'status-dot--active' :
                status === 'paused' || status === 'consent' || status === 'requesting' ? 'status-dot--paused' :
                status === 'error' ? 'status-dot--error' : ''
            );
        }
        setText('gps-state', labels[status] || 'Not tracking');

        const start = $('#gps-start');
        const pause = $('#gps-pause');
        const resume = $('#gps-resume');
        const stop = $('#gps-stop');
        if (start) start.disabled = ['consent', 'requesting', 'active', 'paused'].includes(status);
        if (pause) pause.disabled = status !== 'active';
        if (resume) resume.disabled = status !== 'paused';
        if (stop) stop.disabled = !['consent', 'requesting', 'active', 'paused', 'error'].includes(status);

        refreshBadge();
    }

    function setConsentBadge(mode) {
        const badge = $('#gps-consent-state');
        if (!badge) return;
        if (mode === 'granted') {
            badge.className = 'badge badge--green';
            badge.textContent = 'Consented (this page)';
        } else if (mode === 'denied') {
            badge.className = 'badge badge--red';
            badge.textContent = 'Consent declined';
        } else if (mode === 'blocked') {
            badge.className = 'badge badge--red';
            badge.textContent = 'Blocked by browser';
        } else {
            badge.className = 'badge badge--consent';
            badge.textContent = 'Consent required';
        }
    }

    function resetGpsReadouts() {
        ['gps-lat', 'gps-lng', 'gps-accuracy', 'gps-speed', 'gps-last'].forEach((id) => setText(id, '—'));
        setText('gps-distance', '0 m');
        setText('gps-saved', '0');
    }

    function updateGpsReadouts(fix) {
        setText('gps-lat', fmt(fix.latitude, 6));
        setText('gps-lng', fmt(fix.longitude, 6));
        setText('gps-accuracy', fix.accuracy != null ? Math.round(fix.accuracy) + ' m' : 'not reported');
        setText('gps-speed', fix.speed != null ? GT.fmtSpeedKmh(fix.speed) : 'not reported');
        setText('gps-last', GT.fmtDateTime(fix.recorded_at));
        setText('gps-distance', GT.fmtDistance(gps.distanceM));
    }

    function showGpsEntity() {
        const entity = gps.entityId ? GT.state.entities.get(Number(gps.entityId)) : null;
        setText('gps-entity', entity ? entity.entity_name + ' (' + entity.entity_code + ')' : 'not created');
    }

    function gpsEntityIdFromResponse(entity) {
        return entity && entity.id ? Number(entity.id) : null;
    }

    async function resolveDeviceEntity(forceNew) {
        if (!forceNew && gps.entityId) {
            const existing = GT.state.entities.get(Number(gps.entityId));
            if (existing && existing.can_write) return existing;
        }

        if (!forceNew) {
            const remembered = readDeviceId();
            if (remembered) {
                const existing = GT.state.entities.get(remembered);
                if (existing && existing.can_write && !existing.is_demo && existing.tracking_method === 'gps') {
                    gps.entityId = Number(existing.id);
                    showGpsEntity();
                    return existing;
                }
            }

            let candidate = null;
            GT.state.entities.forEach((entity) => {
                if (candidate) return;
                if (!entity.is_demo && entity.is_owner && entity.can_write &&
                    entity.tracking_method === 'gps' && entity.entity_name === 'This device (GPS)') {
                    candidate = entity;
                }
            });
            if (candidate) {
                gps.entityId = Number(candidate.id);
                storeDeviceId(candidate.id);
                showGpsEntity();
                return candidate;
            }
        }

        const data = await GT.api('entities.php', {
            method: 'POST',
            body: {
                entity_name: 'This device (GPS)',
                entity_type: 'laptop',
                tracking_method: 'gps',
                notes: 'Automatically created for a consented browser GPS session.',
                is_demo: false,
            },
        });

        const newId = gpsEntityIdFromResponse(data.entity);
        if (!newId) throw new Error('The server did not return a usable entity.');

        gps.entityId = newId;
        storeDeviceId(newId);
        if (data.entity) {
            data.entity.last_location = null;
            GT.state.entities.set(newId, data.entity);
        }
        try {
            await GT.refreshEntities({ silent: true });
        } catch (error) {
            /* The local entity above is enough to keep tracking. */
        }
        showGpsEntity();
        return GT.state.entities.get(newId) || data.entity;
    }

    async function setEntityStatus(entityId, status) {
        if (!entityId) return;
        try {
            await GT.api('tracking_status.php', {
                method: 'POST',
                body: { entity_id: Number(entityId), status },
            });
            if (GT.updateEntityStatus) GT.updateEntityStatus(entityId, status);
        } catch (error) {
            if (error.status === 401) return;
            // A status write is cosmetic; keep the live session going and warn once.
            if (!error.status || error.status >= 500) {
                showError('gps-error', 'Live position is updating, but the server would not record the tracking status: ' + error.message);
            }
        }
    }

    /* ----------------------------------------------------------------------
       GPS session
       ---------------------------------------------------------------------- */

    function startGps() {
        if (!GT.state.user) {
            showError('gps-error', 'Sign in before starting a GPS session.');
            if (GT.showLogin) GT.showLogin('Sign in to save location records.');
            return;
        }
        if (!('geolocation' in navigator)) {
            setGpsStatus('error');
            showError('gps-error', 'This browser does not support the Geolocation API. Use the simulated demo instead.');
            if (GT.toast) GT.toast('Geolocation is not supported by this browser.', 'error', 'GPS unavailable');
            return;
        }
        if (['consent', 'requesting', 'active', 'paused'].includes(gps.status)) return;

        clearError('gps-error');
        if (!gps.consented) {
            gps.pendingConsent = true;
            setGpsStatus('consent');
            setConsentBadge('required');
            if (GT.openModal) GT.openModal('consent-modal');
            return;
        }
        beginGpsSession();
    }

    async function beginGpsSession() {
        if (!gps.consented) return;
        clearError('gps-error');
        gps.paused = false;
        gps.distanceM = 0;
        gps.saved = 0;
        gps.lastFix = null;
        gps.lastSentAt = 0;
        gps.lastSentPoint = null;
        gps.failures = 0;
        gps.errorShown = false;
        gps.startedAt = Date.now();
        resetGpsReadouts();
        setGpsStatus('requesting');
        setConsentBadge('granted');

        try {
            const entity = await resolveDeviceEntity(false);
            if (entity) {
                gps.entityId = Number(entity.id);
                showGpsEntity();
                if (GT.selectEntity) GT.selectEntity(gps.entityId, { zoom: true });
            }
        } catch (error) {
            setGpsStatus('error');
            showError('gps-error', 'Could not prepare a device entity for tracking: ' + error.message);
            return;
        }

        if (gps.watchId != null) {
            navigator.geolocation.clearWatch(gps.watchId);
            gps.watchId = null;
        }

        try {
            gps.watchId = navigator.geolocation.watchPosition(handlePosition, handlePositionError, GPS_OPTIONS);
            setEntityStatus(gps.entityId, 'active');
            if (GT.toast) GT.toast('GPS session started. Your position is sent only while this page is tracking.', 'success', 'GPS started');
        } catch (error) {
            setGpsStatus('error');
            showError('gps-error', 'The browser refused to start geolocation: ' + error.message);
        }
    }

    function handlePosition(position) {
        if (gps.status === 'paused' || gps.status === 'stopped' || gps.status === 'idle') return;
        const coords = position && position.coords ? position.coords : null;
        const latitude = coords ? Number(coords.latitude) : NaN;
        const longitude = coords ? Number(coords.longitude) : NaN;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            showError('gps-error', 'The browser returned an invalid position. Waiting for the next fix…');
            return;
        }

        const accuracy = Number.isFinite(Number(coords.accuracy)) ? Number(coords.accuracy) : null;
        let speed = Number.isFinite(Number(coords.speed)) && Number(coords.speed) >= 0 ? Number(coords.speed) : null;
        const heading = Number.isFinite(Number(coords.heading)) && Number(coords.heading) >= 0 ? Number(coords.heading) : null;
        const recordedAt = new Date(
            Number.isFinite(Number(position.timestamp)) && Number(position.timestamp) > 0
                ? Number(position.timestamp)
                : Date.now()
        ).toISOString();

        const previous = gps.lastFix;
        let segmentM = 0;
        let dtSeconds = 0;
        if (previous) {
            segmentM = haversineM(previous.latitude, previous.longitude, latitude, longitude);
            dtSeconds = (new Date(recordedAt).getTime() - new Date(previous.recorded_at).getTime()) / 1000;
            const implied = dtSeconds > 0 ? segmentM / dtSeconds : 0;
            const plausible = implied <= 220 && segmentM <= 5000 && (accuracy == null || accuracy <= 500);
            if (plausible && segmentM >= 1.5) {
                gps.distanceM += segmentM;
            } else {
                segmentM = 0;
            }
            if (speed == null && segmentM > 1.5 && dtSeconds > 0.5 && implied <= 75) {
                speed = segmentM / dtSeconds;
            }
        }

        const fix = {
            latitude,
            longitude,
            accuracy,
            speed,
            heading,
            recorded_at: recordedAt,
            segment_m: segmentM,
        };
        gps.lastFix = fix;
        gps.failures = 0;
        gps.errorShown = false;
        clearError('gps-error');

        if (gps.status !== 'active') setGpsStatus('active');
        updateGpsReadouts(fix);
        showGpsEntity();

        if (gps.entityId && GT.setLivePosition) {
            GT.setLivePosition(gps.entityId, latitude, longitude, {
                accuracy,
                speed,
                recorded_at: recordedAt,
                source_type: 'gps',
                status: 'active',
            });
        }

        maybeSendFix(fix);
    }

    function maybeSendFix(fix) {
        if (!gps.entityId || gps.sending) return;
        const now = Date.now();
        const elapsed = now - gps.lastSentAt;
        const moved = gps.lastSentPoint
            ? haversineM(gps.lastSentPoint.latitude, gps.lastSentPoint.longitude, fix.latitude, fix.longitude)
            : Infinity;
        const first = gps.lastSentAt === 0;
        if (!first && elapsed < GPS_MIN_SEND_MS) return;
        if (!first && moved < GPS_MIN_MOVE_M && elapsed < GPS_MAX_AGE_MS) return;
        sendFix(fix);
    }

    async function sendFix(fix) {
        gps.sending = true;
        try {
            const data = await GT.api('location_update.php', {
                method: 'POST',
                body: {
                    entity_id: Number(gps.entityId),
                    latitude: fix.latitude,
                    longitude: fix.longitude,
                    accuracy: fix.accuracy,
                    speed: fix.speed,
                    heading: fix.heading,
                    recorded_at: fix.recorded_at,
                    source_type: 'gps',
                },
            });
            gps.lastSentAt = Date.now();
            gps.lastSentPoint = { latitude: fix.latitude, longitude: fix.longitude };
            if (!data || data.duplicate !== true) {
                gps.saved += 1;
                setText('gps-saved', gps.saved);
            }
        } catch (error) {
            if (error.status === 401) return;
            if (error.status === 403) {
                showError('gps-error', 'This device entity is no longer writable by your account. Stop and restart tracking to create a new one.');
            } else if (error.status === 429) {
                gps.lastSentAt = Date.now() + 15000;
                if (!gps.errorShown) {
                    gps.errorShown = true;
                    showError('gps-error', 'The server is limiting writes. Positions continue on the map, and saving will resume automatically.');
                }
            } else if (!gps.errorShown) {
                gps.errorShown = true;
                showError('gps-error', 'Live map updates continue, but this fix was not saved: ' + error.message);
            }
        } finally {
            gps.sending = false;
        }
    }

    function handlePositionError(error) {
        const code = error && error.code;
        if (code === 1) {
            // Permission denied: there is no way to recover without the user.
            stopGps({ silent: true, keepStatusWrite: true });
            setGpsStatus('error');
            setConsentBadge('blocked');
            showError(
                'gps-error',
                'Location permission was denied. Allow location access for this site in your browser settings, then press Start GPS again. You can use the simulated demo without location permission.'
            );
            if (GT.toast) GT.toast('Location permission was denied. See the GPS panel for recovery steps.', 'error', 'GPS permission');
            return;
        }

        gps.failures += 1;
        if (code === 2) {
            showError('gps-error', 'GPS position is currently unavailable. This can happen indoors or without a GPS/network fix. Still trying…');
        } else if (code === 3) {
            showError('gps-error', 'The GPS request timed out after 15 seconds. Keep the page open and move near a window; retrying automatically.');
        } else {
            showError('gps-error', (error && error.message) || 'An unknown geolocation error occurred.');
        }
        if (gps.status === 'requesting') setGpsStatus('active');
        if (gps.failures === 3 && GT.toast) {
            GT.toast('No GPS fix yet. Check device location services and browser permissions.', 'warning', 'GPS signal');
        }
    }

    function pauseGps() {
        if (gps.status !== 'active') return;
        gps.paused = true;
        setGpsStatus('paused');
        setEntityStatus(gps.entityId, 'paused');
        if (GT.toast) GT.toast('GPS tracking paused. The watch stays open but positions are not recorded.', 'info', 'GPS paused');
    }

    function resumeGps() {
        if (gps.status !== 'paused') return;
        gps.paused = false;
        setGpsStatus('active');
        setEntityStatus(gps.entityId, 'active');
        if (GT.toast) GT.toast('GPS tracking resumed.', 'success', 'GPS resumed');
    }

    function stopGps(options = {}) {
        if (gps.watchId != null) {
            try {
                navigator.geolocation.clearWatch(gps.watchId);
            } catch (error) {
                /* Ignore a browser that already dropped the watch. */
            }
            gps.watchId = null;
        }
        const wasActive = ['active', 'paused', 'requesting'].includes(gps.status);
        gps.paused = false;
        if (options.keepStatusWrite === true) {
            setEntityStatus(gps.entityId, 'stopped');
        } else if (wasActive) {
            setEntityStatus(gps.entityId, 'stopped');
        }
        setGpsStatus(options.error ? 'error' : 'stopped');
        if (!options.silent && wasActive && GT.toast) {
            GT.toast('GPS session stopped. No further positions are recorded from this page.', 'success', 'GPS stopped');
        }
    }

    /* ----------------------------------------------------------------------
       Simulation
       ---------------------------------------------------------------------- */

    function setSimStatus(status) {
        sim.status = status;
        const labels = {
            stopped: 'Simulation stopped',
            preparing: 'Preparing demo entities…',
            active: 'Simulation running (SIMULATED)',
            paused: 'Simulation paused',
            error: 'Simulation error',
        };
        const dot = $('#sim-state-dot');
        if (dot) {
            dot.className = 'status-dot ' + (
                status === 'active' ? 'status-dot--active' :
                status === 'paused' || status === 'preparing' ? 'status-dot--paused' :
                status === 'error' ? 'status-dot--error' : ''
            );
        }
        setText('sim-state', labels[status] || 'Simulation stopped');
        const start = $('#sim-start');
        const pause = $('#sim-pause');
        const resume = $('#sim-resume');
        const reset = $('#sim-reset');
        if (start) start.disabled = ['preparing', 'active', 'paused'].includes(status);
        if (pause) pause.disabled = status !== 'active';
        if (resume) resume.disabled = status !== 'paused';
        if (reset) reset.disabled = status === 'stopped' && sim.updates === 0;
        refreshBadge();
    }

    function updateSimReadouts() {
        setText('sim-updates', sim.updates);
        setText('sim-distance', GT.fmtDistance(sim.totalM));
        const first = sim.entities[0];
        if (first) {
            const entity = GT.state.entities.get(Number(first.id)) || first;
            const loc = entity && entity.last_location;
            if (loc) {
                setText('sim-lat', fmt(loc.latitude, 6));
                setText('sim-lng', fmt(loc.longitude, 6));
            }
        }
        const reset = $('#sim-reset');
        if (reset) reset.disabled = sim.status === 'stopped' && sim.updates === 0;
    }

    async function findOrCreateDemoEntities() {
        const result = [];

        for (const spec of DEMO_SPECS) {
            let found = null;
            GT.state.entities.forEach((entity) => {
                if (found) return;
                if (!entity.is_demo) return;
                if (entity.entity_code === spec.code ||
                    entity.entity_name === spec.name ||
                    entity.entity_name.indexOf(spec.name.replace(' (Demo)', '')) === 0) {
                    found = entity;
                }
            });

            if (!found) {
                const data = await GT.api('entities.php', {
                    method: 'POST',
                    body: {
                        entity_name: spec.name,
                        entity_type: spec.type,
                        tracking_method: 'simulated',
                        is_demo: true,
                        notes: spec.notes,
                    },
                });
                found = data.entity;
                if (found && found.id) GT.state.entities.set(Number(found.id), found);
            }

            if (found) result.push(found);
        }

        if (result.length) {
            try {
                await GT.refreshEntities({ silent: true });
                return result.map((entity) => GT.state.entities.get(Number(entity.id)) || entity);
            } catch (error) {
                return result;
            }
        }
        return result;
    }

    function buildPath(waypoints, stepM) {
        if (!Array.isArray(waypoints) || waypoints.length < 2) return [];
        const points = [];
        const step = Math.max(0.5, Number(stepM) || 5);
        for (let i = 0; i < waypoints.length - 1; i += 1) {
            const [lat1, lon1] = waypoints[i];
            const [lat2, lon2] = waypoints[i + 1];
            const length = haversineM(lat1, lon1, lat2, lon2);
            const parts = Math.max(1, Math.ceil(length / step));
            for (let p = 0; p < parts; p += 1) {
                const t = p / parts;
                points.push({
                    latitude: lat1 + (lat2 - lat1) * t,
                    longitude: lon1 + (lon2 - lon1) * t,
                });
            }
        }
        const last = waypoints[waypoints.length - 1];
        points.push({ latitude: last[0], longitude: last[1] });
        return points;
    }

    function nearestIndex(path, lat, lon) {
        if (!path.length || !Number.isFinite(lat) || !Number.isFinite(lon)) return 0;
        let best = 0;
        let bestDistance = Infinity;
        path.forEach((point, index) => {
            const distance = haversineM(lat, lon, point.latitude, point.longitude);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = index;
            }
        });
        return best;
    }

    function prepareSimulation() {
        sim.paths.clear();
        sim.indices.clear();
        sim.distances.clear();
        sim.lastPoints.clear();
        sim.totalM = 0;
        sim.updates = 0;
        sim.failures = 0;

        sim.entities.forEach((entity) => {
            const id = Number(entity.id);
            const type = entity.entity_type || 'other';
            const route = DEMO_ROUTES[type] || DEMO_ROUTES.asset;
            const path = buildPath(route, SIM_STEP_M[type] || 5);
            sim.paths.set(id, path);
            sim.distances.set(id, 0);
            const loc = entity.last_location;
            sim.indices.set(id, loc ? nearestIndex(path, Number(loc.latitude), Number(loc.longitude)) : 0);
        });
    }

    async function startSimulation() {
        if (!GT.state.user) {
            showError('sim-error', 'Sign in with an administrator account to run the demonstration simulation.');
            return;
        }
        if (GT.state.user.role !== 'admin') {
            showError('sim-error', 'Simulation writes clearly labelled demo records and is therefore restricted to administrators. Sign in with the classroom administrator account.');
            if (GT.toast) GT.toast('Simulation is administrator-only to keep demo and real data separate.', 'warning', 'Admin required');
            return;
        }
        if (['preparing', 'active', 'paused'].includes(sim.status)) return;

        clearError('sim-error');
        setSimStatus('preparing');
        try {
            const entities = await findOrCreateDemoEntities();
            if (entities.length < 3) {
                throw new Error('At least three demo entities (person, laptop, vehicle) are required.');
            }
            sim.entities = entities;
            prepareSimulation();
            sim.status = 'active';
            sim.paused = false;
            sim.startedAt = Date.now();
            await Promise.allSettled(entities.map((entity) => setEntityStatus(entity.id, 'active')));
            setSimStatus('active');
            updateSimReadouts();
            const first = entities[0];
            if (first && GT.selectEntity) GT.selectEntity(Number(first.id), { zoom: true });
            if (GT.toast) {
                GT.toast('Simulated tracking started for fictional demo entities. All SIMULATED markers are labelled.', 'warning', 'SIMULATED demo');
            }
            scheduleSimTick(350);
        } catch (error) {
            setSimStatus('error');
            showError('sim-error', 'Could not start the simulation: ' + error.message);
            if (GT.toast) GT.toast(error.message, 'error', 'Simulation failed');
        }
    }

    function scheduleSimTick(delay) {
        if (sim.timer) window.clearTimeout(sim.timer);
        sim.timer = window.setTimeout(runSimTick, Math.max(250, Number(delay) || 2500));
    }

    async function runSimTick() {
        sim.timer = null;
        if (sim.status !== 'active' || sim.paused || sim.tickBusy) return;
        sim.tickBusy = true;

        const interval = Math.max(500, Number(boot.app && boot.app.simulation && boot.app.simulation.interval_ms) || 2500);
        const jobs = [];
        const nowIso = new Date().toISOString();

        sim.entities.forEach((entity) => {
            const id = Number(entity.id);
            const path = sim.paths.get(id) || [];
            if (!path.length) return;
            const type = entity.entity_type || 'other';
            const index = sim.indices.get(id) || 0;
            const point = path[index % path.length];
            const nextIndex = (index + 1) % path.length;
            const previous = sim.lastPoints.get(id);
            const heading = previous
                ? bearingDegrees(previous.latitude, previous.longitude, point.latitude, point.longitude)
                : null;
            const stepM = SIM_STEP_M[type] || 5;
            const speed = stepM / (interval / 1000);
            const accuracy = type === 'vehicle' ? 18 : type === 'laptop' ? 12 : 8;

            sim.indices.set(id, nextIndex);
            sim.lastPoints.set(id, point);
            sim.distances.set(id, (sim.distances.get(id) || 0) + stepM);
            sim.totalM += stepM;

            if (GT.setLivePosition) {
                GT.setLivePosition(id, point.latitude, point.longitude, {
                    accuracy,
                    speed,
                    recorded_at: nowIso,
                    source_type: 'simulated',
                    status: 'active',
                });
            }

            jobs.push(
                GT.api('location_update.php', {
                    method: 'POST',
                    body: {
                        entity_id: id,
                        latitude: Number(point.latitude.toFixed(7)),
                        longitude: Number(point.longitude.toFixed(7)),
                        accuracy,
                        speed,
                        heading,
                        recorded_at: nowIso,
                        source_type: 'simulated',
                    },
                }).then(() => {
                    sim.updates += 1;
                    sim.failures = 0;
                }).catch((error) => {
                    sim.failures += 1;
                    if (error.status === 401) return;
                    if (error.status === 429) {
                        pauseSimulation(true);
                        showError('sim-error', 'The server write limit was reached. The simulation paused to avoid flooding the database. Resume after a minute.');
                    }
                    if (!sim.errorShown) {
                        sim.errorShown = true;
                        if (error.status !== 429) {
                            showError('sim-error', 'Some simulated updates could not be saved: ' + error.message);
                        }
                    }
                })
            );
        });

        updateSimReadouts();

        if (jobs.length) {
            await Promise.allSettled(jobs);
        }

        sim.tickBusy = false;
        if (sim.status === 'active' && !sim.paused) {
            updateSimReadouts();
            scheduleSimTick(interval);
        }
    }

    function pauseSimulation(fromError) {
        if (sim.status !== 'active') return;
        sim.paused = true;
        if (sim.timer) {
            window.clearTimeout(sim.timer);
            sim.timer = null;
        }
        sim.status = 'paused';
        sim.entities.forEach((entity) => setEntityStatus(entity.id, 'paused'));
        setSimStatus('paused');
        updateSimReadouts();
        if (!fromError && GT.toast) {
            GT.toast('Simulation paused. Demo entities stop moving.', 'info', 'Simulation paused');
        }
    }

    function resumeSimulation() {
        if (sim.status !== 'paused') return;
        sim.paused = false;
        sim.errorShown = false;
        clearError('sim-error');
        sim.status = 'active';
        sim.entities.forEach((entity) => setEntityStatus(entity.id, 'active'));
        setSimStatus('active');
        if (GT.toast) GT.toast('Simulation resumed.', 'success', 'SIMULATED demo');
        scheduleSimTick(400);
    }

    function clearDemoTrails() {
        const state = GT.state;
        if (!state) return;
        sim.entities.forEach((entity) => {
            const id = Number(entity.id);
            state.trailPoints.delete(id);
            const line = state.trailLines.get(id);
            if (line && state.map) state.map.removeLayer(line);
            state.trailLines.delete(id);
        });
    }

    function resetSimulation() {
        if (sim.timer) {
            window.clearTimeout(sim.timer);
            sim.timer = null;
        }
        sim.entities.forEach((entity) => setEntityStatus(entity.id, 'stopped'));
        sim.status = 'stopped';
        sim.paused = false;
        sim.updates = 0;
        sim.totalM = 0;
        sim.failures = 0;
        sim.errorShown = false;
        sim.indices.clear();
        sim.distances.clear();
        sim.lastPoints.clear();
        clearDemoTrails();
        clearError('sim-error');
        setSimStatus('stopped');
        updateSimReadouts();
        if (GT.toast) {
            GT.toast('Simulation reset. Stored demo history is kept in MySQL and can be cleared from Privacy & data.', 'info', 'Simulation reset');
        }
        if (GT.analytics) GT.analytics.refresh(true);
    }

    function stopSimulation(options = {}) {
        const wasActive = ['active', 'paused', 'preparing'].includes(sim.status);
        if (sim.timer) {
            window.clearTimeout(sim.timer);
            sim.timer = null;
        }
        sim.entities.forEach((entity) => setEntityStatus(entity.id, 'stopped'));
        sim.status = 'stopped';
        sim.paused = false;
        setSimStatus('stopped');
        updateSimReadouts();
        if (!options.silent && wasActive && GT.toast) {
            GT.toast('Simulation stopped. Demo markers remain at their last positions.', 'success', 'SIMULATED demo');
        }
        if (GT.analytics && wasActive) GT.analytics.refresh(true);
    }

    /* ----------------------------------------------------------------------
       Quick stop, tabs, consent and lifecycle
       ---------------------------------------------------------------------- */

    function stopAll(silent) {
        const gpsWasActive = ['active', 'paused', 'requesting', 'consent'].includes(gps.status);
        const simWasActive = activeSim();
        if (gpsWasActive) stopGps({ silent: true });
        if (simWasActive) stopSimulation({ silent: true });
        if (gps.pendingConsent) {
            gps.pendingConsent = false;
            if (GT.closeModal) GT.closeModal('consent-modal');
            setGpsStatus('stopped');
        }
        if (!silent) {
            if (gpsWasActive || simWasActive) {
                if (GT.toast) GT.toast('All tracking activity in this page has stopped.', 'success', 'Tracking stopped');
            } else if (GT.toast) {
                GT.toast('Nothing is currently tracking.', 'info', 'Tracking idle');
            }
        }
    }

    function switchTrackingTab(mode) {
        const gpsTab = $('#tab-gps');
        const simTab = $('#tab-sim');
        const gpsPanel = $('#panel-gps');
        const simPanel = $('#panel-sim');
        const showGps = mode !== 'sim';
        if (gpsTab) {
            gpsTab.classList.toggle('is-active', showGps);
            gpsTab.setAttribute('aria-selected', showGps ? 'true' : 'false');
        }
        if (simTab) {
            simTab.classList.toggle('is-active', !showGps);
            simTab.setAttribute('aria-selected', showGps ? 'false' : 'true');
        }
        if (gpsPanel) gpsPanel.hidden = !showGps;
        if (simPanel) simPanel.hidden = showGps;
        if (!showGps && GT.state.user && GT.state.user.role !== 'admin') {
            showError('sim-error', 'You are signed in without administrator rights. The simulation writes demo records and is admin-only; you can still use genuine device GPS.');
        }
    }

    function bindEvents() {
        const gpsStart = $('#gps-start');
        if (gpsStart) gpsStart.addEventListener('click', startGps);
        const gpsPause = $('#gps-pause');
        if (gpsPause) gpsPause.addEventListener('click', pauseGps);
        const gpsResume = $('#gps-resume');
        if (gpsResume) gpsResume.addEventListener('click', resumeGps);
        const gpsStop = $('#gps-stop');
        if (gpsStop) gpsStop.addEventListener('click', () => stopGps());

        const simStart = $('#sim-start');
        if (simStart) simStart.addEventListener('click', startSimulation);
        const simPause = $('#sim-pause');
        if (simPause) simPause.addEventListener('click', () => pauseSimulation(false));
        const simResume = $('#sim-resume');
        if (simResume) simResume.addEventListener('click', resumeSimulation);
        const simReset = $('#sim-reset');
        if (simReset) simReset.addEventListener('click', resetSimulation);

        const quickStop = $('#quick-stop');
        if (quickStop) quickStop.addEventListener('click', () => stopAll(false));

        const gpsTab = $('#tab-gps');
        if (gpsTab) gpsTab.addEventListener('click', () => switchTrackingTab('gps'));
        const simTab = $('#tab-sim');
        if (simTab) simTab.addEventListener('click', () => switchTrackingTab('sim'));

        GT.on('gps-consent-granted', () => {
            if (!gps.pendingConsent) return;
            gps.pendingConsent = false;
            gps.consented = true;
            setConsentBadge('granted');
            beginGpsSession();
        });

        GT.on('gps-consent-declined', () => {
            if (!gps.pendingConsent) return;
            gps.pendingConsent = false;
            gps.consented = false;
            setGpsStatus('stopped');
            setConsentBadge('denied');
            showError('gps-error', 'Location sharing was declined. No coordinates are collected. Consent is required before the GPS session can start.');
            if (GT.toast) GT.toast('No location data was collected because consent was declined.', 'info', 'Consent declined');
        });

        GT.on('entities-updated', () => {
            showGpsEntity();
            if (sim.status !== 'stopped' && sim.entities.length) {
                sim.entities = sim.entities
                    .map((entity) => GT.state.entities.get(Number(entity.id)) || null)
                    .filter(Boolean);
                updateSimReadouts();
            }
        });

        GT.on('auth-expired', () => {
            stopAll(true);
            setConsentBadge('required');
        });

        GT.on('demo-cleared', () => {
            if (sim.status === 'stopped') {
                sim.updates = 0;
                sim.totalM = 0;
                sim.indices.clear();
                sim.distances.clear();
                sim.lastPoints.clear();
                updateSimReadouts();
            }
        });

        document.addEventListener('visibilitychange', () => {
            // Browsers throttle timers in background tabs. On returning, resume
            // the simulation schedule without flooding writes.
            if (document.visibilityState === 'visible' && sim.status === 'active' && !sim.timer && !sim.tickBusy) {
                scheduleSimTick(600);
            }
        });

        window.addEventListener('beforeunload', () => {
            if (gps.watchId != null) {
                try {
                    navigator.geolocation.clearWatch(gps.watchId);
                } catch (error) {
                    /* Nothing useful to do during unload. */
                }
                gps.watchId = null;
            }
            if (sim.timer) {
                window.clearTimeout(sim.timer);
                sim.timer = null;
            }
        });

        setGpsStatus('idle');
        setConsentBadge('required');
        setSimStatus('stopped');
        resetGpsReadouts();
        updateSimReadouts();
        showGpsEntity();
        refreshBadge();
    }

    function initTracking() {
        bindEvents();
        GT.tracking = {
            getLastFix() {
                return gps.lastFix ? Object.assign({}, gps.lastFix) : null;
            },
            startGps,
            pauseGps,
            resumeGps,
            stopGps,
            startSimulation,
            pauseSimulation,
            resumeSimulation,
            resetSimulation,
            stopAll,
            isGpsActive() {
                return ['active', 'paused', 'requesting'].includes(gps.status);
            },
            isSimulationActive() {
                return activeSim();
            },
            getStatus() {
                return { gps: gps.status, simulation: sim.status, saved: gps.saved };
            },
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTracking);
    } else {
        initTracking();
    }
})();
