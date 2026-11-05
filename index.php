<?php
/**
 * GeoTrack GIS — single-page dashboard.
 * All tracking, analysis, history, IP geolocation, and privacy tools live here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$config = gt_config();
$nonce = base64_encode(random_bytes(18));
$setupError = null;

try {
    $bootstrap = gt_session_payload();
} catch (Throwable $e) {
    error_log('Dashboard bootstrap error: ' . $e->getMessage());
    $bootstrap = [
        'authenticated' => false,
        'user' => null,
        'csrf_token' => gt_csrf_token(),
        'session_expired' => false,
        'app' => gt_public_app_config(),
    ];
    $setupError = 'The database could not be reached. Import database/geotrack_gis.sql, start MySQL, and check config/config.local.php.';
    $bootstrap['setup_error'] = $setupError;
}

$authenticated = !empty($bootstrap['authenticated']);
$user = $bootstrap['user'] ?? null;
$app = $bootstrap['app'];
$demoHint = $app['demo_hint'] ?? null;
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$csp = "default-src 'self'; "
    . "script-src 'self' 'nonce-{$nonce}'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org; "
    . "connect-src 'self'; "
    . "font-src 'self' data:; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'; "
    . "manifest-src 'self'";
header('Content-Security-Policy: ' . $csp);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="GeoTrack GIS — real-time GPS and simulated asset tracking with Leaflet, PHP, MySQL, and Python spatial analysis. MUST GIS assignment.">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="csrf-token" content="<?= $e($bootstrap['csrf_token']) ?>">
    <title>GeoTrack GIS · Real-time tracking dashboard</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%231d4ed8'/%3E%3Cpath d='M32 13c-8.8 0-16 7-16 15.6C16 40 32 52 32 52s16-12 16-23.4C48 20 40.8 13 32 13zm0 21a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z' fill='white'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to dashboard</a>

<svg class="svg-sprite" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.14v13.72L19 12 8 5.14z"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></symbol>
    <symbol id="i-stop" viewBox="0 0 24 24"><path d="M6 6h12v12H6z"/></symbol>
    <symbol id="i-reset" viewBox="0 0 24 24"><path d="M12 5a7 7 0 1 1-6.65 4.8l1.9.66A5 5 0 1 0 12 7v3L7.5 6 12 2v3z"/></symbol>
    <symbol id="i-locate" viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9 3h-2.07A7.01 7.01 0 0 0 13 5.07V3h-2v2.07A7.01 7.01 0 0 0 5.07 11H3v2h2.07A7.01 7.01 0 0 0 11 18.93V21h2v-2.07A7.01 7.01 0 0 0 18.93 13H21v-2zm-9 6a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5z"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M12 6V3L7 7l5 4V8a4 4 0 1 1-4 4H6a6 6 0 1 0 6-6z"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M9 3h6l1 2h4v2H4V5h4l1-2zm-3 6h12l-1 12H7L6 9zm3 2v8h2v-8H9zm4 0v8h2v-8h-2z"/></symbol>
    <symbol id="i-login" viewBox="0 0 24 24"><path d="M10 4H5v16h5v-2H7V6h3V4zm5.59 3.59L14.17 9l2 2H9v2h7.17l-2 2 1.42 1.41L20 12l-4.41-4.41z"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M14 4h5v16h-5v-2h3V6h-3V4zM9.59 7.59 4.17 13l5.42 5.41L11 17l-3-3h7v-2H8l3-3-1.41-1.41z"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><path d="M10 4a6 6 0 1 0 3.47 10.9l4.82 4.81 1.41-1.41-4.81-4.82A6 6 0 0 0 10 4zm0 2a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/></symbol>
    <symbol id="i-warning" viewBox="0 0 24 24"><path d="M12 2 1 21h22L12 2zm0 5 7.5 12h-15L12 7zm-1 3v4h2v-4h-2zm0 6v2h2v-2h-2z"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 5h2v2h-2V7zm0 4h2v6h-2v-6z"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m9 16.17-3.88-3.88L3.7 13.7 9 19 20.3 7.7l-1.42-1.41L9 16.17z"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20h16v2H4v-2zM6 10h3v8H6v-8zm5-6h3v14h-3V4zm5 9h3v5h-3v-5z"/></symbol>
    <symbol id="i-layers" viewBox="0 0 24 24"><path d="m12 2 10 5-10 5L2 7l10-5zm0 8.76L5.3 7.5 2 9.15l10 5 10-5-3.3-1.65L12 10.76zm0 4.59L5.3 12.1 2 13.75l10 5 10-5-3.3-1.65L12 15.35z"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3zm0 2.2 6 2.25V11c0 4-2.5 7.8-6 8.9C8.5 18.8 6 15 6 11V6.45l6-2.25zm-1 4.3v4h2v-4h-2zm0 6v2h2v-2h-2z"/></symbol>
</svg>

<div id="global-progress" class="global-progress" hidden><span></span></div>

<div id="app-shell" class="<?= $authenticated ? '' : 'is-locked' ?>">
    <header class="app-header">
        <div class="app-header__inner">
            <a class="brand" href="index.php" aria-label="GeoTrack GIS home">
                <span class="brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" width="30" height="30"><path d="M16 3C9.9 3 5 7.7 5 13.5 5 22 16 30 16 30s11-8 11-16.5C27 7.7 22.1 3 16 3zm0 14.5a4 4 0 1 1 0-8 4 4 0 0 1 0 8z" fill="currentColor"/></svg>
                </span>
                <span class="brand__text">
                    <strong>GeoTrack<span>GIS</span></strong>
                    <small>Mbarara University of Science &amp; Technology</small>
                </span>
            </a>

            <div class="header-status" aria-live="polite">
                <span id="connection-indicator" class="connection connection--online" title="Server connection status">
                    <span class="connection__dot"></span>
                    <span id="connection-text">Connecting…</span>
                </span>
                <span id="tracking-badge" class="track-badge track-badge--idle" title="Tracking status">
                    <span class="track-badge__dot"></span>
                    <span id="tracking-badge-text">Tracking idle</span>
                </span>
                <button type="button" id="quick-stop" class="btn btn--tiny btn--danger" hidden>Stop all</button>
            </div>

            <div class="header-actions">
                <span id="clock" class="clock" title="Local time">--:--:--</span>
                <div id="user-chip" class="user-chip" hidden>
                    <span class="user-chip__avatar" aria-hidden="true"><svg class="icon"><use href="#i-user"></use></svg></span>
                    <span class="user-chip__text">
                        <strong id="user-name">—</strong>
                        <small id="user-role">—</small>
                    </span>
                </div>
                <button type="button" id="login-open" class="btn btn--secondary btn--sm">
                    <svg class="icon" aria-hidden="true"><use href="#i-login"></use></svg> Sign in
                </button>
                <button type="button" id="logout-btn" class="btn btn--ghost btn--sm" hidden>
                    <svg class="icon" aria-hidden="true"><use href="#i-logout"></use></svg> Sign out
                </button>
            </div>
        </div>
    </header>

    <main id="main-content" class="dashboard" tabindex="-1">
        <section class="page-intro" aria-labelledby="page-title">
            <div>
                <p class="eyebrow">Real-time GIS tracking · Assignment dashboard</p>
                <h1 id="page-title">Track movement. Analyse space. Understand place.</h1>
                <p class="page-intro__lead">
                    Live device GPS, clearly labelled simulation, and Python-powered spatial analysis on an
                    OpenStreetMap base. Location histories are stored in MySQL and protected by sign-in controls.
                </p>
            </div>
            <div class="page-intro__meta">
                <span class="pill pill--blue"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> Consent-first tracking</span>
                <span class="pill pill--grey">WGS 84 · Leaflet</span>
                <button type="button" class="link-button" data-tab-link="privacy">Privacy &amp; data controls →</button>
            </div>
        </section>

        <div id="setup-banner" class="alert alert--error" role="alert" <?= $setupError ? '' : 'hidden' ?>>
            <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
            <div>
                <strong>Setup required</strong>
                <p id="setup-banner-text"><?= $e($setupError ?? '') ?></p>
            </div>
        </div>

        <section class="kpi-grid" aria-label="Tracking summary statistics">
            <article class="kpi-card">
                <div class="kpi-card__icon kpi-card__icon--blue"><svg class="icon" aria-hidden="true"><use href="#i-pin"></use></svg></div>
                <div>
                    <p class="kpi-card__label">Tracked entities</p>
                    <p class="kpi-card__value" id="kpi-entities">—</p>
                    <p class="kpi-card__sub" id="kpi-entities-sub">Loading entities…</p>
                </div>
            </article>
            <article class="kpi-card">
                <div class="kpi-card__icon kpi-card__icon--green"><svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg></div>
                <div>
                    <p class="kpi-card__label">Active sessions</p>
                    <p class="kpi-card__value" id="kpi-active">—</p>
                    <p class="kpi-card__sub" id="kpi-active-sub">Waiting for data</p>
                </div>
            </article>
            <article class="kpi-card">
                <div class="kpi-card__icon kpi-card__icon--amber"><svg class="icon" aria-hidden="true"><use href="#i-chart"></use></svg></div>
                <div>
                    <p class="kpi-card__label">Recorded distance</p>
                    <p class="kpi-card__value" id="kpi-distance">—</p>
                    <p class="kpi-card__sub" id="kpi-distance-sub">Python movement analysis</p>
                </div>
            </article>
            <article class="kpi-card">
                <div class="kpi-card__icon kpi-card__icon--purple"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg></div>
                <div>
                    <p class="kpi-card__label">Last location update</p>
                    <p class="kpi-card__value kpi-card__value--small" id="kpi-updated">—</p>
                    <p class="kpi-card__sub" id="kpi-updated-sub">No updates yet</p>
                </div>
            </article>
        </section>

        <section class="dashboard-grid" aria-label="Map and tracking controls">
            <div class="map-panel card">
                <div class="card__header map-panel__header">
                    <div>
                        <h2 class="card__title">Live map</h2>
                        <p class="card__subtitle" id="map-selected-label">Select an entity to inspect its trail</p>
                    </div>
                    <div class="map-toolbar">
                        <button type="button" class="icon-btn" id="map-fit-btn" title="Fit all tracked entities" aria-label="Fit all tracked entities">
                            <svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg>
                        </button>
                        <button type="button" class="icon-btn is-active" id="map-trails-btn" title="Toggle movement trails" aria-label="Toggle movement trails" aria-pressed="true">
                            <svg class="icon" aria-hidden="true"><use href="#i-chart"></use></svg>
                        </button>
                        <button type="button" class="icon-btn" id="map-locate-btn" title="Zoom to selected entity" aria-label="Zoom to selected entity">
                            <svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg>
                        </button>
                    </div>
                </div>
                <div class="map-wrap">
                    <div id="map" role="application" aria-label="Interactive map of tracked entities around Mbarara"></div>
                    <div id="map-loading" class="map-loading">
                        <span class="spinner" aria-hidden="true"></span>
                        <span>Loading map…</span>
                    </div>
                    <div id="map-sim-banner" class="sim-banner" hidden>
                        <strong>SIMULATED DATA</strong>
                        <span id="sim-banner-text">Demo markers are moving along fictional routes.</span>
                    </div>
                    <div class="map-legend" aria-label="Map legend">
                        <span><i class="legend-dot legend-dot--person"></i> Person</span>
                        <span><i class="legend-dot legend-dot--laptop"></i> Laptop</span>
                        <span><i class="legend-dot legend-dot--vehicle"></i> Vehicle</span>
                        <span><i class="legend-dot legend-dot--asset"></i> Asset</span>
                        <span><i class="legend-dot legend-dot--sim"></i> Simulated</span>
                    </div>
                    <div id="map-empty" class="map-empty" hidden>
                        <svg class="icon" aria-hidden="true"><use href="#i-pin"></use></svg>
                        <p>No mapped entities yet. Start a simulation or a GPS session.</p>
                    </div>
                </div>
            </div>

            <aside class="side-column" aria-label="Tracking and entity controls">
                <section class="card tracking-card">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title">Tracking controls</h2>
                            <p class="card__subtitle">Choose a genuine GPS session or a demo simulation</p>
                        </div>
                    </div>
                    <div class="segmented" role="tablist" aria-label="Tracking mode">
                        <button type="button" class="segmented__btn is-active" id="tab-gps" role="tab" aria-selected="true" aria-controls="panel-gps">Device GPS</button>
                        <button type="button" class="segmented__btn" id="tab-sim" role="tab" aria-selected="false" aria-controls="panel-sim">Simulation <span class="tag tag--amber">DEMO</span></button>
                    </div>

                    <div class="tracking-panel" id="panel-gps" role="tabpanel" aria-labelledby="tab-gps">
                        <div class="tracking-state-line">
                            <span class="status-dot" id="gps-state-dot"></span>
                            <strong id="gps-state">Not tracking</strong>
                            <span class="badge badge--consent" id="gps-consent-state">Consent required</span>
                        </div>
                        <div class="tracking-readout" aria-live="polite">
                            <div><span>Latitude</span><strong id="gps-lat">—</strong></div>
                            <div><span>Longitude</span><strong id="gps-lng">—</strong></div>
                            <div><span>Accuracy</span><strong id="gps-accuracy">—</strong></div>
                            <div><span>Speed</span><strong id="gps-speed">—</strong></div>
                            <div><span>Last fix</span><strong id="gps-last">—</strong></div>
                            <div><span>Distance</span><strong id="gps-distance">0 m</strong></div>
                        </div>
                        <p class="tracking-entity-line">Device entity: <strong id="gps-entity">not created</strong> · saved updates <span id="gps-saved">0</span></p>
                        <div class="button-row">
                            <button type="button" class="btn btn--primary" id="gps-start"><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> Start GPS</button>
                            <button type="button" class="btn btn--secondary" id="gps-pause" disabled><svg class="icon" aria-hidden="true"><use href="#i-pause"></use></svg> Pause</button>
                            <button type="button" class="btn btn--secondary" id="gps-resume" disabled><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> Resume</button>
                            <button type="button" class="btn btn--danger-outline" id="gps-stop" disabled><svg class="icon" aria-hidden="true"><use href="#i-stop"></use></svg> Stop</button>
                        </div>
                        <div id="gps-error" class="alert alert--error alert--compact" role="alert" hidden></div>
                        <p class="fine-print">
                            Uses <code>navigator.geolocation.watchPosition()</code>. Your coordinates, accuracy, speed,
                            and timestamp are sent to this PHP server and stored in MySQL. GPS requires HTTPS or localhost.
                        </p>
                    </div>

                    <div class="tracking-panel" id="panel-sim" role="tabpanel" aria-labelledby="tab-sim" hidden>
                        <div class="sim-label"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg> SIMULATED — fictional demo assets</div>
                        <div class="tracking-state-line">
                            <span class="status-dot" id="sim-state-dot"></span>
                            <strong id="sim-state">Simulation stopped</strong>
                        </div>
                        <div class="tracking-readout">
                            <div><span>Latest latitude</span><strong id="sim-lat">—</strong></div>
                            <div><span>Latest longitude</span><strong id="sim-lng">—</strong></div>
                            <div><span>Simulated distance</span><strong id="sim-distance">0 m</strong></div>
                            <div><span>Updates sent</span><strong id="sim-updates">0</strong></div>
                        </div>
                        <div class="button-row">
                            <button type="button" class="btn btn--primary" id="sim-start"><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> Start simulation</button>
                            <button type="button" class="btn btn--secondary" id="sim-pause" disabled><svg class="icon" aria-hidden="true"><use href="#i-pause"></use></svg> Pause</button>
                            <button type="button" class="btn btn--secondary" id="sim-resume" disabled><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> Resume</button>
                            <button type="button" class="btn btn--danger-outline" id="sim-reset" disabled><svg class="icon" aria-hidden="true"><use href="#i-reset"></use></svg> Reset</button>
                        </div>
                        <div id="sim-error" class="alert alert--error alert--compact" role="alert" hidden></div>
                        <p class="fine-print">
                            Person 001, Laptop 001, Vehicle 001, and Research Asset 001 follow fictional Mbarara routes.
                            These are <strong>not real people or devices</strong>. Simulation requires an administrator
                            account so demo records cannot be mixed with genuine GPS data.
                        </p>
                    </div>
                </section>

                <section class="card entity-card">
                    <div class="card__header card__header--compact">
                        <div>
                            <h2 class="card__title">Tracked entities <span class="count-badge" id="entity-count-label">0</span></h2>
                        </div>
                        <button type="button" class="icon-btn" id="add-entity-btn" title="Register an asset" aria-label="Register an asset">
                            <svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg>
                        </button>
                    </div>
                    <div class="entity-tools">
                        <label class="search-field" for="entity-search">
                            <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
                            <input type="search" id="entity-search" placeholder="Search name or code" autocomplete="off">
                        </label>
                        <select id="entity-filter" class="select select--compact" aria-label="Filter entities by type">
                            <option value="">All types</option>
                            <option value="person">People</option>
                            <option value="laptop">Laptops</option>
                            <option value="vehicle">Vehicles</option>
                            <option value="asset">Assets</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <ul class="entity-list" id="entity-list" aria-label="Tracked entities"></ul>
                    <div class="empty-state empty-state--compact" id="entity-empty" hidden>
                        <svg class="icon" aria-hidden="true"><use href="#i-pin"></use></svg>
                        <p>No entities match. Register an asset or start tracking.</p>
                    </div>
                    <div class="selected-card" id="selected-card" hidden>
                        <div class="selected-card__top">
                            <div>
                                <span class="selected-card__label">Selected entity</span>
                                <strong id="selected-name">—</strong>
                                <span class="selected-card__code" id="selected-code">—</span>
                            </div>
                            <span class="badge" id="selected-status">—</span>
                        </div>
                        <div class="selected-card__grid">
                            <div><span>Type</span><strong id="selected-type">—</strong></div>
                            <div><span>Method</span><strong id="selected-method">—</strong></div>
                            <div><span>Last seen</span><strong id="selected-last">—</strong></div>
                            <div><span>Coordinates</span><strong id="selected-coords">—</strong></div>
                        </div>
                        <div class="button-row button-row--tight">
                            <button type="button" class="btn btn--secondary btn--sm" id="selected-zoom"><svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg> Zoom</button>
                            <button type="button" class="btn btn--secondary btn--sm" id="selected-history"><svg class="icon" aria-hidden="true"><use href="#i-chart"></use></svg> History</button>
                            <button type="button" class="btn btn--danger-outline btn--sm" id="selected-delete" hidden><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                        </div>
                    </div>
                </section>
            </aside>
        </section>

        <section class="lower-panel card" aria-label="Analysis, data, and privacy tools">
            <div class="tabs" role="tablist" aria-label="Dashboard sections">
                <button type="button" class="tabs__btn is-active" role="tab" aria-selected="true" aria-controls="tabpanel-analytics" data-tab="analytics" id="tab-btn-analytics">
                    <svg class="icon" aria-hidden="true"><use href="#i-chart"></use></svg> Analytics
                </button>
                <button type="button" class="tabs__btn" role="tab" aria-selected="false" aria-controls="tabpanel-analysis" data-tab="analysis" id="tab-btn-analysis">
                    <svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> GIS analysis
                </button>
                <button type="button" class="tabs__btn" role="tab" aria-selected="false" aria-controls="tabpanel-history" data-tab="history" id="tab-btn-history">
                    <svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> History
                </button>
                <button type="button" class="tabs__btn" role="tab" aria-selected="false" aria-controls="tabpanel-ip" data-tab="ip" id="tab-btn-ip">
                    <svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg> IP geolocation
                </button>
                <button type="button" class="tabs__btn" role="tab" aria-selected="false" aria-controls="tabpanel-privacy" data-tab="privacy" id="tab-btn-privacy">
                    <svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> Privacy &amp; data
                </button>
            </div>

            <div class="tab-panels">
                <section class="tab-panel is-active" id="tabpanel-analytics" role="tabpanel" aria-labelledby="tab-btn-analytics">
                    <div class="section-heading">
                        <div>
                            <h2>Movement analytics</h2>
                            <p>Statistics come from MySQL; distance and speed are computed by the Python GIS module.</p>
                        </div>
                        <button type="button" class="btn btn--secondary btn--sm" id="analytics-refresh"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Refresh</button>
                    </div>
                    <div class="chart-grid">
                        <article class="chart-card chart-card--wide">
                            <div class="chart-card__header">
                                <h3>Distance travelled over time</h3>
                                <span class="pill pill--grey" id="distance-method">Python · Haversine</span>
                            </div>
                            <div class="chart-canvas-wrap">
                                <canvas id="chart-distance" aria-label="Distance travelled per day chart" role="img"></canvas>
                                <div class="chart-empty" id="empty-distance" hidden>
                                    <p>No distance data yet. Start a simulation or GPS session.</p>
                                </div>
                            </div>
                            <p class="chart-note" id="distance-note">Waiting for Python analysis…</p>
                        </article>
                        <article class="chart-card">
                            <div class="chart-card__header"><h3>Entity types</h3></div>
                            <div class="chart-canvas-wrap chart-canvas-wrap--small">
                                <canvas id="chart-types" aria-label="Tracked entities by type chart" role="img"></canvas>
                                <div class="chart-empty" id="empty-types" hidden><p>No entities.</p></div>
                            </div>
                        </article>
                        <article class="chart-card">
                            <div class="chart-card__header"><h3>Updates · last 7 days</h3></div>
                            <div class="chart-canvas-wrap chart-canvas-wrap--small">
                                <canvas id="chart-updates" aria-label="Location updates per day chart" role="img"></canvas>
                                <div class="chart-empty" id="empty-updates" hidden><p>No updates recorded.</p></div>
                            </div>
                        </article>
                        <article class="chart-card chart-card--wide">
                            <div class="chart-card__header">
                                <h3>Recent location updates</h3>
                                <span class="live-dot">auto-refresh</span>
                            </div>
                            <ul class="recent-list" id="recent-updates-list"></ul>
                            <div class="empty-state empty-state--compact" id="recent-empty" hidden>
                                <p>No location updates yet.</p>
                            </div>
                        </article>
                    </div>
                    <div class="table-card">
                        <div class="chart-card__header">
                            <h3>Distance by entity <span class="muted">(last 7 days)</span></h3>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr><th>Entity</th><th>Type</th><th>Valid points</th><th>Distance</th><th>Review flags</th></tr>
                                </thead>
                                <tbody id="distance-table-body"></tbody>
                            </table>
                        </div>
                        <p class="empty-state empty-state--compact" id="distance-table-empty">No movement analysis available yet.</p>
                    </div>
                </section>

                <section class="tab-panel" id="tabpanel-analysis" role="tabpanel" aria-labelledby="tab-btn-analysis" hidden>
                    <div class="section-heading">
                        <div>
                            <h2>GIS analysis tools</h2>
                            <p>All calculations run in Python using valid coordinate geometry — never raw degree subtraction.</p>
                        </div>
                        <button type="button" class="btn btn--secondary btn--sm" id="selftest-btn"><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Run Python self-test</button>
                    </div>

                    <div class="tool-grid">
                        <article class="tool-card">
                            <header><span class="tool-card__icon">↔</span><div><h3>Distance &amp; bearing</h3><p>Haversine distance between two WGS 84 points.</p></div></header>
                            <form id="distance-form" class="form-grid" novalidate>
                                <div class="form-row">
                                    <label for="dist-lat1">Point A latitude</label>
                                    <input type="number" id="dist-lat1" step="any" min="-90" max="90" value="<?= $e(number_format((float) $app['map']['center']['lat'], 6, '.', '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="dist-lon1">Point A longitude</label>
                                    <input type="number" id="dist-lon1" step="any" min="-180" max="180" value="<?= $e(number_format((float) $app['map']['center']['lng'], 6, '.', '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="dist-lat2">Point B latitude</label>
                                    <input type="number" id="dist-lat2" step="any" min="-90" max="90" placeholder="-0.611700" required>
                                </div>
                                <div class="form-row">
                                    <label for="dist-lon2">Point B longitude</label>
                                    <input type="number" id="dist-lon2" step="any" min="-180" max="180" placeholder="30.658100" required>
                                </div>
                                <div class="form-actions form-actions--wrap">
                                    <button type="button" class="btn btn--ghost btn--sm" id="dist-use-selected">Use selected</button>
                                    <button type="button" class="btn btn--ghost btn--sm" id="dist-use-gps">Use my GPS</button>
                                    <button type="button" class="btn btn--ghost btn--sm" id="dist-pick-a">Pick A on map</button>
                                    <button type="button" class="btn btn--ghost btn--sm" id="dist-pick-b">Pick B on map</button>
                                </div>
                                <button type="submit" class="btn btn--primary" id="dist-submit">Calculate distance</button>
                            </form>
                            <div class="result-box" id="distance-result" hidden></div>
                        </article>

                        <article class="tool-card">
                            <header><span class="tool-card__icon">◎</span><div><h3>Proximity analysis</h3><p>Find tracked entities within a radius of a point.</p></div></header>
                            <form id="proximity-form" class="form-grid" novalidate>
                                <div class="form-row">
                                    <label for="prox-lat">Origin latitude</label>
                                    <input type="number" id="prox-lat" step="any" min="-90" max="90" value="<?= $e(number_format((float) $app['map']['center']['lat'], 6, '.', '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="prox-lon">Origin longitude</label>
                                    <input type="number" id="prox-lon" step="any" min="-180" max="180" value="<?= $e(number_format((float) $app['map']['center']['lng'], 6, '.', '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="prox-radius">Radius (metres)</label>
                                    <input type="number" id="prox-radius" step="10" min="1" max="<?= (int) $app['analysis']['max_radius_m'] ?>" value="<?= (int) $app['analysis']['default_radius_m'] ?>" required>
                                </div>
                                <fieldset class="form-row form-row--full">
                                    <legend>Entity types</legend>
                                    <div class="checkbox-row">
                                        <label><input type="checkbox" name="prox-types" value="person" checked> People</label>
                                        <label><input type="checkbox" name="prox-types" value="laptop" checked> Laptops</label>
                                        <label><input type="checkbox" name="prox-types" value="vehicle" checked> Vehicles</label>
                                        <label><input type="checkbox" name="prox-types" value="asset" checked> Assets</label>
                                        <label><input type="checkbox" name="prox-types" value="other" checked> Other</label>
                                    </div>
                                </fieldset>
                                <div class="form-actions">
                                    <button type="button" class="btn btn--ghost btn--sm" id="prox-pick">Pick origin on map</button>
                                    <button type="button" class="btn btn--ghost btn--sm" id="prox-use-center">Use map centre</button>
                                </div>
                                <button type="submit" class="btn btn--primary" id="prox-submit">Find nearby entities</button>
                            </form>
                            <div class="result-box" id="proximity-result" hidden></div>
                            <div class="button-row button-row--tight" id="prox-map-actions" hidden>
                                <button type="button" class="btn btn--secondary btn--sm" id="prox-show-map">Show on map</button>
                                <button type="button" class="btn btn--ghost btn--sm" id="prox-clear-map">Clear map overlay</button>
                            </div>
                        </article>

                        <article class="tool-card tool-card--wide">
                            <header><span class="tool-card__icon">∑</span><div><h3>Movement summary</h3><p>Distance, duration, speed, and data-quality warnings for one entity.</p></div></header>
                            <form id="movement-form" class="form-grid form-grid--3" novalidate>
                                <div class="form-row">
                                    <label for="move-entity">Entity</label>
                                    <select id="move-entity" class="select" required></select>
                                </div>
                                <div class="form-row">
                                    <label for="move-from">From (optional)</label>
                                    <input type="datetime-local" id="move-from">
                                </div>
                                <div class="form-row">
                                    <label for="move-to">To (optional)</label>
                                    <input type="datetime-local" id="move-to">
                                </div>
                                <button type="submit" class="btn btn--primary" id="move-submit">Analyse movement</button>
                            </form>
                            <div class="result-box" id="movement-result" hidden></div>
                        </article>

                        <article class="tool-card">
                            <header><span class="tool-card__icon">✓</span><div><h3>Record validation</h3><p>Check a coordinate record for range and plausibility issues.</p></div></header>
                            <form id="validate-form" class="form-grid" novalidate>
                                <div class="form-row"><label for="val-lat">Latitude</label><input type="number" id="val-lat" step="any" min="-90" max="90" value="-0.611700" required></div>
                                <div class="form-row"><label for="val-lon">Longitude</label><input type="number" id="val-lon" step="any" min="-180" max="180" value="30.658100" required></div>
                                <div class="form-row"><label for="val-accuracy">Accuracy (m)</label><input type="number" id="val-accuracy" step="any" min="0" value="15"></div>
                                <div class="form-row"><label for="val-speed">Speed (m/s)</label><input type="number" id="val-speed" step="any" min="0" value="1.2"></div>
                                <div class="form-row form-row--full"><label for="val-time">Recorded at</label><input type="datetime-local" id="val-time"></div>
                                <button type="submit" class="btn btn--primary" id="val-submit">Validate record</button>
                            </form>
                            <div class="result-box" id="validation-result" hidden></div>
                        </article>

                        <article class="tool-card">
                            <header><span class="tool-card__icon">PY</span><div><h3>Python module status</h3><p>Live check of the GIS analysis service.</p></div></header>
                            <div id="selftest-result" class="result-box result-box--muted">
                                <p>Run the self-test to verify distance, movement, proximity, and validation functions.</p>
                            </div>
                            <p class="fine-print">PHP invokes <code>python/gis_analysis.py</code> through <code>proc_open()</code> with an argument array, JSON on stdin, and a timeout. No shell commands are constructed from user input.</p>
                        </article>
                    </div>
                </section>

                <section class="tab-panel" id="tabpanel-history" role="tabpanel" aria-labelledby="tab-btn-history" hidden>
                    <div class="section-heading">
                        <div>
                            <h2>Tracking history</h2>
                            <p id="history-subtitle">Select an entity to load its recorded positions.</p>
                        </div>
                        <div class="button-row button-row--tight">
                            <button type="button" class="btn btn--secondary btn--sm" id="history-refresh"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Refresh</button>
                            <button type="button" class="btn btn--danger-outline btn--sm" id="history-clear" disabled><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Clear history</button>
                        </div>
                    </div>
                    <div class="history-meta">
                        <span id="history-count">0 records</span>
                        <span class="muted">Retention: <strong><?= (int) $app['retention_days'] ?> days</strong>. Access is restricted to the owner or an administrator.</span>
                    </div>
                    <div class="table-wrap table-wrap--history">
                        <table class="data-table data-table--history">
                            <thead>
                                <tr>
                                    <th>Recorded (local)</th>
                                    <th>Entity</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th class="col-optional">Accuracy</th>
                                    <th class="col-optional">Speed</th>
                                    <th>Source</th>
                                    <th>Quality</th>
                                    <th class="col-action">Map</th>
                                </tr>
                            </thead>
                            <tbody id="history-body"></tbody>
                        </table>
                    </div>
                    <div class="empty-state" id="history-empty">
                        <svg class="icon" aria-hidden="true"><use href="#i-pin"></use></svg>
                        <p>No history loaded. Select an entity, then click Refresh.</p>
                    </div>
                    <div class="table-footer">
                        <button type="button" class="btn btn--secondary btn--sm" id="history-load-older" hidden>Load older records</button>
                        <span class="muted" id="history-table-note">Newest 100 records are shown.</span>
                    </div>
                </section>

                <section class="tab-panel" id="tabpanel-ip" role="tabpanel" aria-labelledby="tab-btn-ip" hidden>
                    <div class="section-heading">
                        <div>
                            <h2>IP geolocation <span class="badge badge--grey">informational only</span></h2>
                            <p>Approximate network location, not precise device tracking. Results are labelled with their source and time.</p>
                        </div>
                        <button type="button" class="btn btn--secondary btn--sm" id="ip-lookup-btn"><svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg> Look up my network</button>
                    </div>
                    <div class="ip-layout">
                        <article class="ip-card" id="ip-result-card">
                            <div id="ip-sample-banner" class="alert alert--warning" hidden>
                                <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
                                <p><strong>Sample/demo result.</strong> This is not a live lookup of your connection.</p>
                            </div>
                            <div id="ip-error" class="alert alert--error" role="alert" hidden></div>
                            <div class="ip-result-grid">
                                <div><span>Approximate location</span><strong id="ip-location">—</strong></div>
                                <div><span>Country / region</span><strong id="ip-region">—</strong></div>
                                <div><span>City</span><strong id="ip-city">—</strong></div>
                                <div><span>Coordinates (approx.)</span><strong id="ip-coords">—</strong></div>
                                <div><span>Network / ISP</span><strong id="ip-isp">—</strong></div>
                                <div><span>Lookup time</span><strong id="ip-time">—</strong></div>
                                <div><span>Source</span><strong id="ip-source">Not looked up</strong></div>
                                <div><span>IP address</span><strong id="ip-address">—</strong></div>
                            </div>
                            <div class="button-row">
                                <button type="button" class="btn btn--secondary btn--sm" id="ip-show-map" disabled>Show approximate point on map</button>
                                <span class="badge badge--grey" id="ip-status">not queried</span>
                            </div>
                        </article>
                        <aside class="ip-notes">
                            <h3>Why IP location is limited</h3>
                            <ul class="info-list" id="ip-limitations">
                                <li>IP geolocation estimates the location of a network, not the physical device.</li>
                                <li>VPNs, proxies, mobile carriers, and ISP gateways can move the result to another city or country.</li>
                                <li>Accuracy is usually city or region level — never a precise laptop position.</li>
                                <li>It cannot provide continuous movement tracking or replace device GPS.</li>
                            </ul>
                            <div class="notice" id="ip-api-note">
                                The provider is called server-side; no API key is exposed in the browser. If no provider is configured
                                or the lookup fails, a clearly labelled sample is shown instead.
                            </div>
                        </aside>
                    </div>
                </section>

                <section class="tab-panel" id="tabpanel-privacy" role="tabpanel" aria-labelledby="tab-btn-privacy" hidden>
                    <div class="section-heading">
                        <div>
                            <h2>Privacy, consent &amp; data controls</h2>
                            <p>You decide whether location is collected. Tracking can be stopped immediately.</p>
                        </div>
                        <button type="button" class="btn btn--secondary btn--sm" data-tab-link="history">View my history</button>
                    </div>

                    <div class="privacy-grid">
                        <article class="privacy-card">
                            <h3><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> Consent status</h3>
                            <div class="consent-status" id="consent-status">
                                <span class="status-dot status-dot--grey"></span>
                                <div><strong>No GPS consent in this browser session</strong><p>A consent dialog appears before the first GPS fix.</p></div>
                            </div>
                            <ul class="info-list">
                                <li><strong>Genuine GPS:</strong> latitude, longitude, accuracy, speed (if supplied), timestamp, and the source label <code>gps</code> are stored on this server.</li>
                                <li><strong>Simulation:</strong> records use fictional demo entities and the source label <code>simulated</code>. They are never presented as real devices.</li>
                                <li><strong>IP geolocation:</strong> only an approximate network location is queried and cached; it is not written to location history.</li>
                                <li><strong>Who can see history:</strong> the owning account and administrators. Demo history is visible to signed-in classroom users.</li>
                            </ul>
                        </article>

                        <article class="privacy-card">
                            <h3>Account security</h3>
                            <p class="muted">Signed in as <strong id="privacy-username">—</strong> (<span id="privacy-role">—</span>). Sessions expire after <?= (int) $app['security']['session_idle_minutes'] ?> minutes of inactivity.</p>
                            <form id="password-form" class="form-grid" novalidate>
                                <div class="form-row form-row--full"><label for="pw-current">Current password</label><input type="password" id="pw-current" autocomplete="current-password" required></div>
                                <div class="form-row"><label for="pw-new">New password</label><input type="password" id="pw-new" autocomplete="new-password" minlength="10" required></div>
                                <div class="form-row"><label for="pw-confirm">Confirm new password</label><input type="password" id="pw-confirm" autocomplete="new-password" minlength="10" required></div>
                                <button type="submit" class="btn btn--secondary" id="pw-submit">Change password</button>
                            </form>
                            <div id="password-error" class="alert alert--error alert--compact" role="alert" hidden></div>
                            <div id="password-success" class="alert alert--success alert--compact" role="status" hidden></div>
                        </article>

                        <article class="privacy-card privacy-card--wide">
                            <h3>Retention &amp; deletion</h3>
                            <div class="retention-grid">
                                <div><span>Retention window</span><strong id="retention-value"><?= (int) $app['retention_days'] ?> days</strong></div>
                                <div><span>My stored records</span><strong id="my-records">—</strong></div>
                                <div><span>Demo records</span><strong id="demo-records">—</strong></div>
                                <div><span>Last retention purge</span><strong id="last-purge">—</strong></div>
                            </div>
                            <p class="muted" id="retention-note">Expired records are purged automatically at most once per day when an administrator signs in. Administrators can also purge them manually.</p>
                            <div class="button-row">
                                <button type="button" class="btn btn--danger-outline btn--sm" id="clear-my-data"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Delete all my location data</button>
                                <button type="button" class="btn btn--danger-outline btn--sm" id="clear-demo-data" hidden><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Clear demo history</button>
                                <button type="button" class="btn btn--danger-outline btn--sm" id="purge-expired" hidden><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Purge expired records</button>
                                <button type="button" class="btn btn--ghost btn--sm" id="refresh-maintenance">Refresh data counts</button>
                            </div>
                            <div id="maintenance-error" class="alert alert--error alert--compact" role="alert" hidden></div>
                            <div id="maintenance-success" class="alert alert--success alert--compact" role="status" hidden></div>
                        </article>

                        <article class="privacy-card">
                            <h3>Security safeguards</h3>
                            <ul class="check-list">
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> PDO prepared statements for all SQL</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> CSRF tokens on state-changing requests</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Session-based owner/admin authorization</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Server-side coordinate &amp; timestamp validation</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Output escaping against XSS</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Python invoked without a shell &amp; with timeouts</li>
                                <li><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Rate limiting on writes, sign-in, lookups</li>
                            </ul>
                        </article>

                        <article class="privacy-card">
                            <h3>Data sources &amp; honesty</h3>
                            <ul class="info-list">
                                <li>Basemap © OpenStreetMap contributors, served by the OpenStreetMap tile servers.</li>
                                <li>Genuine GPS comes from your browser and is only collected after consent.</li>
                                <li>Simulation is fictional and visibly labelled <strong>SIMULATED</strong>.</li>
                                <li>IP geolocation is approximate network data with its provider and lookup time shown.</li>
                                <li>This is a university assignment prototype, not a production surveillance product.</li>
                            </ul>
                        </article>
                    </div>
                </section>
            </div>
        </section>

        <footer class="app-footer">
            <div>
                <strong>GeoTrack GIS</strong> · GIS assignment · Mbarara University of Science and Technology · October–November 2026
            </div>
            <div class="app-footer__links">
                <span>Built with HTML5, CSS3, JavaScript, Leaflet, PHP, MySQL, Python, Chart.js</span>
                <span>Map data © OpenStreetMap contributors</span>
            </div>
        </footer>
    </main>
</div>

<!-- Login / authentication modal -->
<div class="modal" id="login-modal" hidden>
    <div class="modal__backdrop"></div>
    <div class="modal__dialog modal__dialog--login" role="dialog" aria-modal="true" aria-labelledby="login-title">
        <aside class="login-aside">
            <div class="login-aside__brand">
                <span class="brand__mark brand__mark--light" aria-hidden="true">
                    <svg viewBox="0 0 32 32" width="34" height="34"><path d="M16 3C9.9 3 5 7.7 5 13.5 5 22 16 30 16 30s11-8 11-16.5C27 7.7 22.1 3 16 3zm0 14.5a4 4 0 1 1 0-8 4 4 0 0 1 0 8z" fill="currentColor"/></svg>
                </span>
                <strong>GeoTrack GIS</strong>
            </div>
            <h2>See movement.<br>Understand place.</h2>
            <p>Real-time tracking, PostGIS-style analysis, and privacy-first controls for the MUST GIS assignment.</p>
            <ul>
                <li>Live browser GPS + clearly labelled simulation</li>
                <li>Python distance, movement &amp; proximity analysis</li>
                <li>MySQL history with owner/admin access control</li>
            </ul>
            <p class="login-aside__foot">WGS 84 · OpenStreetMap · Leaflet · Chart.js</p>
        </aside>
        <div class="login-main">
            <h2 id="login-title">Sign in to the dashboard</h2>
            <p class="muted">Location histories are restricted to signed-in users.</p>
            <div id="login-error" class="alert alert--error" role="alert" hidden></div>
            <div id="login-setup-error" class="alert alert--warning" role="alert" <?= $setupError ? '' : 'hidden' ?>>
                <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
                <p><?= $e($setupError ?? '') ?></p>
            </div>
            <form id="login-form" novalidate>
                <div class="form-row">
                    <label for="login-username">Username</label>
                    <input type="text" id="login-username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                </div>
                <div class="form-row">
                    <label for="login-password">Password</label>
                    <div class="password-field">
                        <input type="password" id="login-password" name="password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="password-toggle" aria-label="Show password">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary btn--block" id="login-submit">
                    <svg class="icon" aria-hidden="true"><use href="#i-login"></use></svg> Sign in
                </button>
            </form>
            <div class="demo-credentials" id="login-demo-hint" <?= $demoHint ? '' : 'hidden' ?>>
                <strong>Classroom demo account</strong>
                <p>Username: <code id="demo-username"><?= $e($demoHint['username'] ?? 'admin') ?></code> · Password: <code id="demo-password"><?= $e($demoHint['password'] ?? 'GeoTrack@2026') ?></code></p>
                <p class="fine-print"><?= $e($demoHint['note'] ?? 'Change this account before any real deployment.') ?></p>
            </div>
            <p class="fine-print">By signing in you agree to use this classroom system only with fictional demo identities or with explicit consent from anyone whose location is tracked.</p>
        </div>
    </div>
</div>

<!-- GPS consent modal -->
<div class="modal" id="consent-modal" hidden>
    <div class="modal__backdrop"></div>
    <div class="modal__dialog modal__dialog--narrow" role="dialog" aria-modal="true" aria-labelledby="consent-title">
        <div class="modal__header">
            <h2 id="consent-title">Consent to share your location</h2>
            <button type="button" class="icon-btn modal__close" data-consent-cancel aria-label="Cancel">✕</button>
        </div>
        <div class="modal__body">
            <div class="consent-hero">
                <svg class="icon" aria-hidden="true"><use href="#i-locate"></use></svg>
                <p>Starting GPS tracking asks your browser for your real device position and sends accepted fixes to this server.</p>
            </div>
            <ul class="info-list">
                <li>Data sent: latitude, longitude, accuracy in metres, speed when available, timestamp, and source type <code>gps</code>.</li>
                <li>Stored in MySQL for the retention period (<?= (int) $app['retention_days'] ?> days) and visible only to your account and administrators.</li>
                <li>You can pause or stop at any time; stopping ends the GPS watch immediately. You can delete your stored data in Privacy &amp; data.</li>
                <li>Simulated tracking is separate, fictional, and clearly labelled. It is not real GPS.</li>
                <li>Consent is required before the first location fix. Declining leaves tracking off.</li>
            </ul>
            <label class="check-line">
                <input type="checkbox" id="consent-checkbox">
                <span>I consent to my device location being collected and stored as described above.</span>
            </label>
            <div class="button-row button-row--end">
                <button type="button" class="btn btn--secondary" data-consent-cancel>Cancel</button>
                <button type="button" class="btn btn--primary" id="consent-accept" disabled><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> I consent &amp; start</button>
            </div>
        </div>
    </div>
</div>

<!-- Register asset modal -->
<div class="modal" id="add-entity-modal" hidden>
    <div class="modal__backdrop" data-close-modal="add-entity-modal"></div>
    <div class="modal__dialog modal__dialog--narrow" role="dialog" aria-modal="true" aria-labelledby="add-entity-title">
        <div class="modal__header">
            <h2 id="add-entity-title">Register a tracked asset</h2>
            <button type="button" class="icon-btn modal__close" data-close-modal="add-entity-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p class="muted">Registering an asset does not start tracking. GPS entities are owned by your account; manual entities can hold positions added through the API.</p>
            <div id="add-entity-error" class="alert alert--error" role="alert" hidden></div>
            <form id="add-entity-form" class="form-grid" novalidate>
                <div class="form-row form-row--full">
                    <label for="entity-name">Entity name</label>
                    <input type="text" id="entity-name" maxlength="80" placeholder="e.g. Field Survey Kit" required>
                </div>
                <div class="form-row">
                    <label for="entity-type">Type</label>
                    <select id="entity-type" class="select">
                        <option value="person">Person</option>
                        <option value="laptop">Laptop</option>
                        <option value="vehicle">Vehicle</option>
                        <option value="asset" selected>Asset</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="entity-method">Tracking method</label>
                    <select id="entity-method" class="select">
                        <option value="gps" selected>GPS (device)</option>
                        <option value="manual">Manual records</option>
                    </select>
                </div>
                <div class="form-row form-row--full">
                    <label for="entity-notes">Notes <span class="muted">(optional)</span></label>
                    <input type="text" id="entity-notes" maxlength="255" placeholder="Purpose, owner, or reference">
                </div>
                <label class="check-line form-row--full" id="entity-demo-row" hidden>
                    <input type="checkbox" id="entity-demo">
                    <span>Create as clearly labelled demonstration entity (admin only)</span>
                </label>
                <button type="submit" class="btn btn--primary" id="add-entity-submit">Register asset</button>
            </form>
        </div>
    </div>
</div>

<!-- Generic confirmation modal -->
<div class="modal" id="confirm-modal" hidden>
    <div class="modal__backdrop" data-confirm-cancel></div>
    <div class="modal__dialog modal__dialog--narrow" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal__header">
            <h2 id="confirm-title">Confirm action</h2>
            <button type="button" class="icon-btn modal__close" data-confirm-cancel aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p id="confirm-message">Are you sure?</p>
            <div class="button-row button-row--end">
                <button type="button" class="btn btn--secondary" data-confirm-cancel>Cancel</button>
                <button type="button" class="btn btn--danger" id="confirm-ok">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="false"></div>

<script nonce="<?= $e($nonce) ?>">
window.GEOTRACK_BOOTSTRAP = <?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<script defer src="assets/vendor/leaflet/leaflet.js"></script>
<script defer src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script defer src="assets/js/app.js"></script>
<script defer src="assets/js/tracking.js"></script>
<script defer src="assets/js/analytics.js"></script>
</body>
</html>
