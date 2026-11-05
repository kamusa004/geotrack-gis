# GeoTrack GIS — Technical Report

**Project:** Real-time GIS tracking dashboard
**Course:** Geographical Information Systems, Mbarara University of Science and Technology
**Assignment date:** 27 October 2026 · **Submission:** 17 November 2026
**Author:** *(student name / registration number)*

---

## 1. Introduction and objectives

GeoTrack GIS demonstrates a complete, working GIS pipeline rather than a static mock-up. A browser collects a position (genuine GPS with consent, or a clearly labelled simulation), PHP validates and stores it in MySQL, Leaflet renders it on an OpenStreetMap base, and Python performs the spatial analysis. The interface is a single dashboard page; all supporting PHP files are JSON endpoints.

The objectives were to show how GIS technologies can:

1. acquire positional data from a real device,
2. persist and query it as a spatio-temporal dataset,
3. visualise movement on an interactive map,
4. analyse distance, speed, proximity and data quality,
5. do all of this with explicit consent and honest labelling of simulated and approximate data.

---

## 2. System architecture

```
Browser (HTML5 + CSS3 + JavaScript)
   │  navigator.geolocation.watchPosition()  /  simulation
   │  Leaflet map · Chart.js charts · fetch()
   ▼
PHP 8 REST API  (api/*.php, includes/app.php)
   │  sessions · CSRF · validation · authorization · rate limits
   ├──────────────► MySQL 8 / MariaDB   (geotrack_gis)
   │                 prepared statements, FKs, indexes, constraints
   └──────────────► Python 3  (python/gis_analysis.py)
                     proc_open(), JSON on stdin/stdout, 12 s timeout
```

The PHP layer is the only component that talks to the database and to Python, so the browser never sees credentials and never executes analysis code. Python is invoked with a fixed argument array, receives one JSON object on stdin, and returns structured JSON; no user input reaches a shell.

### Technology roles

| Technology | Role in this project |
| --- | --- |
| HTML5 | Semantic dashboard sections, forms, data table, dialogs, inline SVG icons, `<meta name="csrf-token">`. |
| CSS3 | Responsive grid/flex layout, design tokens, breakpoints, focus and reduced-motion states. |
| JavaScript | Map control, GPS watch with pause/resume/stop, simulation engine, polling, charts, form validation, consent flow, accessible toasts and confirms. |
| PHP | Session auth, CSRF, input validation, PDO persistence, authorization, rate limiting, audit log, Python bridge, JSON API. |
| MySQL | Seven tables with primary keys, foreign keys, checks, enums and indexes; demo seed data; IP-geolocation cache; audit trail. |
| Python | Haversine distance, bearing, movement summary, proximity search, network aggregation, record validation, self-test. |

---

## 3. Database design

Database `geotrack_gis`, UTF-8 (`utf8mb4`), InnoDB, UTC timestamps.

- **`users`** — id, username (unique), display name, `password_hash` (bcrypt/Argon via `password_hash`), role `admin|user`, active flag, failed attempts, lockout time, last login, timestamps.
- **`tracked_entities`** — id, unique `entity_code`, name, `entity_type` (`person|laptop|vehicle|asset|other`), `tracking_method` (`gps|simulated|manual`), `tracking_status` (`active|paused|stopped|error`), `owner_user_id` → `users`, `is_demo`, hashed per-entity API token, notes, timestamps.
- **`location_history`** — id, `entity_id` → `tracked_entities` (cascade delete), latitude/longitude with range checks, accuracy, speed, heading, `source_type` (`gps|simulated|manual|ip`), quality flag and note, `recorded_at`, received time; indexes on `(entity_id, recorded_at)` and `recorded_at`.
- **`tracking_sessions`** — start/end and status history for tracking runs.
- **`ip_geolocation_cache`** — hashed IP, provider payload, fetch/expiry times.
- **`system_meta`** — schema version, installation date, last cache purge.
- **`audit_log`** — security-relevant actions (login, entity create/delete, history clear, purge, bootstrap admin creation) with user, entity, IP and time.

Constraints enforce coordinate ranges and valid enum values. Demo rows carry `is_demo = 1`; the API refuses to mix demo entities with `gps` writes and refuses `simulated` writes on real entities.

---

## 4. Tracking methods

### 4.1 Genuine GPS

`tracking.js` uses `navigator.geolocation.watchPosition()` with `enableHighAccuracy: true`, a 15-second timeout and a 2-second maximum age. The flow is:

1. The user presses **Start GPS**; a consent dialog explains what is stored and why.
2. Only after consent does the code create (or reuse) a `gps` entity owned by the signed-in account and call `watchPosition()`.
3. Each fix updates the map marker, trail polyline, readouts (latitude, longitude, accuracy, speed, time) and distance. A plausibility filter ignores jumps over 220 m/s or 5 km between fixes so a bad fix does not inflate the distance.
4. Writes are throttled to at most one every 3 seconds unless the device has moved at least 4 m, and the server independently rate-limits to 240 writes per minute.
5. **Pause** keeps the browser watch open but stops recording; **Resume** restarts recording; **Stop** clears the watch and marks the entity stopped. A header **Stop all** control stops GPS and simulation at once.

Geolocation requires a secure context. Localhost works; a phone must use HTTPS or the clearly labelled simulation.

### 4.2 Simulation

The simulation engine creates or reuses four fictional demo entities and moves them through waypoint loops around the MUST campus, interpolating points at 2–25 m steps. Every 2.5 s it writes one point per entity with `source_type='simulated'`, updates markers and trails, and counts simulated distance. Start, Pause, Resume and Reset are available. Simulation is restricted to administrators so demo records cannot be mistaken for real tracking, and a persistent "SIMULATED" badge is shown.

### 4.3 Multi-device tracking

Each browser tracks only its own device, but every device posts to the same backend. The dashboard polls entities every 8 s (and trails/statistics every 12–15 s), so other devices appear on an administrator's map after a short delay. This is near-real-time polling, not WebSocket streaming.

---

## 5. Python GIS analysis

`python/gis_analysis.py` is a dependency-light CLI. PHP sends `{"action": ...}` and reads JSON.

1. **Distance and bearing** — Haversine on a mean Earth radius of 6 371 008.8 m:

   `d = 2R · asin(√(sin²(Δφ/2) + cosφ₁·cosφ₂·sin²(Δλ/2)))`

   Initial bearing is computed from the standard forward-azimuth formula. If `geopy` is installed, an ellipsoidal (WGS-84) distance is added for comparison; the core result never depends on it.
2. **Movement analysis** — points are sorted by timestamp; consecutive distances are summed, durations and average/max speeds computed, first/last positions and a summary returned. Each leg is checked against type-aware plausibility thresholds (for example 40 km/h for a person, 280 km/h for a vehicle); suspicious legs are **flagged as warnings**, never asserted as wrongdoing.
3. **Proximity analysis** — for a chosen origin and radius (default 500 m, maximum 50 km), the module computes Haversine distance to each entity's latest point, filters by radius and optional entity types, and returns matches sorted nearest-first. Coordinates are never compared as if degrees were metres.
4. **Validation** — latitude/longitude ranges, numeric accuracy/speed, ISO-8601 timestamps, future/old timestamps and implausible jumps produce structured errors and warnings.
5. **Network summary** — per-entity valid point counts, distance, suspicious legs, plus daily distance totals used by the charts.
6. **Self-test** — seven deterministic checks (equator degree, zero distance, bearing, movement distance and speed, proximity, invalid record) exposed in the UI and in `api/health.php`.

---

## 6. Interface and visualisation

The dashboard uses a blue/white/neutral-grey palette and four responsive breakpoints. Layout: header with connection and tracking state; KPI cards (entities, active sessions, total distance, last update); a large Leaflet map with a side panel for device GPS, simulation and the entity list; below, tabbed panels for Analytics, GIS analysis, History, IP geolocation and Privacy & data.

Charts are drawn with Chart.js from real data:

- distance by day (Python Haversine),
- entity types (MySQL counts),
- recent updates per day,
- KPI values, recent-update list and per-entity distance/quality table.

Empty states explain when there is insufficient data. Tables scroll horizontally on phones; charts resize with the viewport.

---

## 7. Security and privacy

- Consent dialog before any GPS collection; declining collects nothing; tracking state is always visible; one click stops everything.
- Session authentication with HttpOnly/SameSite cookies, idle timeout, login throttling and lockout.
- Server-side owner/admin authorization on every entity and history request, plus optional per-entity API tokens for machine clients.
- CSRF token on all state-changing requests (`hash_equals`), request size limits, strict method checks.
- Prepared statements everywhere (`ATTR_EMULATE_PREPARES = false`), plus allow-lists for enum-like values.
- Output escaped server-side; DOM built with `textContent`; Content-Security-Policy with a per-request nonce and no inline handlers.
- File-based rate limits for login, writes, entity creation and IP lookups.
- Retention (90 days) with purge, clear-history confirmation, delete-my-data, clear-demo-history, and an audit trail.
- No production secrets in code; local overrides are excluded and web-denied.
- IP geolocation is server-side, keyless and cached; results are labelled live or sample, and the limitations of VPNs, proxies, carriers and ISP gateways are shown in the UI.

---

## 8. Testing and results

| Test | Result |
| --- | --- |
| PHP syntax (`php -l`, all files) | Pass |
| JavaScript syntax (`node --check`, 3 files) | Pass |
| Python self-test | 7 / 7 pass |
| API suite: anonymous access rejected, CSRF enforced, bootstrap login, entity CRUD, coordinate/timestamp/source validation, duplicates, history, status, stats, all six analysis actions, IP lookup, maintenance, health | All pass |
| Browser: map and tiles, marker selection, trails, three charts, history, analysis tools, Python self-test, simulation Start/Pause/Resume/Reset, consent accept and decline, denied permission message, add/delete entity, privacy counts and clear-demo-history, IP sample labelling, console errors | Pass, no console errors |

Observed values: MUST campus to Kampala (≈239 km) returned 239.09 km by Haversine; 500 m proximity around a demo trail matched 3 demo entities at 0, 90 and 134 m; the Python movement self-test returned 222.39 m for two 0.001° steps and 6.67 km/h average speed.

Known environment constraint: the development machine was tested with portable PHP 8.3.36, MariaDB 11.4.4 and Python 3.12.10 rather than a full XAMPP install; the application uses only standard XAMPP extensions, and the setup procedure is written for XAMPP.

---

## 9. Limitations

- GPS accuracy depends on the device and environment; indoor fixes are often coarse. "Suspicious" flags are data-quality warnings, not conclusions.
- Phone GPS requires HTTPS (or localhost); plain HTTP over a LAN will not prompt for location.
- Cross-device view is polled every 8–15 s, not pushed in real time.
- IP geolocation is city/region level and can be wrong behind VPNs, carriers or proxies; it is never used as movement history.
- Haversine assumes a sphere (≈0.3–0.5 % error over short routes); the optional ellipsoidal comparison requires `geopy`.
- The simulation is fictional and admin-only by design; it cannot be mixed with real GPS data.
- Single-server only; sessions and rate-limit files are local. No email password reset, no native background tracking.

---

## 10. Conclusion

GeoTrack GIS implements the full acquisition → validation → storage → visualisation → analysis chain with the six required technologies in meaningful roles. It tracks a real device with consent, stores a queryable spatio-temporal record in MySQL, computes GIS metrics in Python, and presents the results on a responsive, honest dashboard that clearly separates genuine, simulated and approximate data.
