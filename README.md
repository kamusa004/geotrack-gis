# GeoTrack GIS — Real-Time Tracking Dashboard

A working web GIS application that tracks a device with the browser Geolocation API (or a clearly labelled fictional simulation), stores positions in MySQL through a PHP REST API, draws movement on a Leaflet / OpenStreetMap map, and performs distance, movement, proximity and data-validation analysis in Python.

Built for the Mbarara University of Science and Technology (MUST) GIS assignment dated 27 October 2026.

> **One-page design.** Everything the lecturer needs to see is on a single dashboard (`index.php`). The `api/*.php` files are JSON endpoints, not extra pages.

---

## 1. What the application does

| Area | Implemented behaviour |
| --- | --- |
| **Interactive map** | Leaflet + OpenStreetMap tiles, distinct DivIcon markers for person / laptop / vehicle / asset, trails as polylines, fit-all and zoom-to-selected controls. |
| **Genuine GPS** | `navigator.geolocation.watchPosition()` with consent gate, Start / Pause / Resume / Stop, live marker, trail, latitude, longitude, accuracy, speed, timestamp, distance travelled, save counter, and explicit handling of denied permission, unavailable position, and timeout. |
| **Simulation** | Admin-only demonstration that moves **fictional** Person 001, Laptop 001, Vehicle 001 and Research Asset 001 along Mbarara routes. Start / Pause / Resume / Reset. Every marker and record is labelled `SIMULATED` / `simulated`. |
| **MySQL** | `geotrack_gis` database with `tracked_entities`, `location_history`, `users`, `tracking_sessions`, `ip_geolocation_cache`, `system_meta`, `audit_log`; primary keys, foreign keys, indexes, checks, enum constraints, seeded fictional demo data. |
| **PHP API** | JSON endpoints for sessions, entities, location writes, history, tracking status, statistics, IP geolocation, maintenance, health and GIS analysis. PDO prepared statements throughout. |
| **Python GIS** | `python/gis_analysis.py` computes Haversine distance + bearing, movement summaries (distance, durations, average/max speed, quality flags), radius proximity, network summaries and record validation. Invoked safely by PHP with `proc_open()` and a JSON stdin payload. |
| **Charts and statistics** | Chart.js bar chart of Python distance by day, doughnut of entity types, bar chart of recent updates, KPI cards, recent-update list, per-entity distance table — all from real MySQL/Python data, with empty states. |
| **History** | Filterable history table (entity, coordinates, time, accuracy, speed, source, quality), zoom-to-record, refresh, clear-history with confirmation, owner/admin authorization. |
| **IP geolocation** | Server-side keyless lookup through `ipwho.is`, cached in MySQL, always labelled live-vs-sample, with an honest accuracy disclaimer and VPN/ISP caveats. Private/local addresses show a clearly labelled sample. |
| **Privacy** | Explicit consent dialog before the first GPS fix, visible "tracking active" badge, instant Stop / Stop all, data-source disclosure, retention window, delete-my-data, clear-demo-history, purge-expired, audit log. |
| **Security** | Session login (bootstrap admin), owner/admin authorization on entities and histories, per-entity API tokens, CSRF on every state-changing request, server-side validation, rate limiting, XSS escaping and CSP nonce, SQL injection protection, no production secrets in code. |
| **Responsive UI** | Clean blue / white / grey dashboard; CSS grid that stacks map, entity panel, charts and tables on phones; scrollable tables; chart resize handling. |

---

## 2. Technology stack

- **HTML5** — semantic dashboard, forms, tables, modals, inline SVG icon sprite.
- **CSS3** — custom properties, grid, flexbox, media queries, focus states, reduced-motion support.
- **JavaScript (ES2019+)** — map, GPS, simulation, polling, charts, UI state.
- **PHP 8+** — REST-style JSON API, sessions, validation, PDO, Python bridge.
- **MySQL / MariaDB** — persistence, constraints, indexes, seeded demo data.
- **Python 3.9+** — GIS calculations (standard library core; optional `geopy`).
- **Leaflet 1.9.4** and **Chart.js 4.4.7** — vendored locally in `assets/vendor/` (no CDN needed at runtime).

Tested with PHP 8.3.36, MariaDB 11.4.4 and Python 3.12.10 on Windows 11, and in Chromium.

---

## 3. Project structure

```
geotrack-gis/
├── index.php                     Single-page dashboard (the only page)
├── assets/
│   ├── css/style.css             Responsive blue/white/grey styling
│   ├── js/app.js                 Map, entities, history, analysis UI, auth, IP, privacy
│   ├── js/tracking.js            GPS watchPosition + simulation
│   ├── js/analytics.js           Chart.js charts and KPI rendering
│   └── vendor/                   Leaflet and Chart.js (local copies)
├── api/
│   ├── session.php               login / logout / whoami / change password
│   ├── entities.php              GET list, POST create, DELETE entity
│   ├── location_update.php       validate and store one position
│   ├── location_history.php      authorised history read + clear
│   ├── tracking_status.php       update active / paused / stopped
│   ├── stats.php                 dashboard statistics
│   ├── analysis.php              Python distance / movement / proximity / network / validate / selftest
│   ├── ip_geolocation.php        server-side IP lookup with cache + sample fallback
│   ├── maintenance.php           counts, delete my data, clear demo history, purge expired
│   └── health.php                DB + Python health check
├── config/
│   ├── config.php                Default configuration (env-overridable)
│   ├── config.local.example.php  Copy to config.local.php for local secrets
│   └── database.php              PDO connection factory
├── database/geotrack_gis.sql     Schema + constraints + seeded demo data
├── includes/
│   ├── app.php                   Sessions, CSRF, validation, Python bridge, audit, rate limits
│   └── api.php                   JSON request/response wrapper
├── python/gis_analysis.py        GIS analysis CLI (JSON over stdin/stdout)
├── storage/                      Rate-limit files and caches (deny web access)
├── requirements.txt              Optional Python packages
├── README.md                     This file
├── TECHNICAL_REPORT.md           Short design report
└── DEMO_PROCEDURE.md             Step-by-step classroom demonstration
```

---

## 4. Requirements

### Minimum
- PHP **8.0+** with `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo` (XAMPP 8.2 includes all of these).
- MySQL **8.0+** or MariaDB **10.6+**.
- Python **3.9+** available as `python` / `python3` (no third-party package is required for the core calculations).
- A modern browser (Chrome, Edge, Firefox, Safari).

### Recommended
- Python 3.12 with the optional packages from `requirements.txt` (`geopy` adds an ellipsoidal distance comparison; `pyproj`, `shapely`, `numpy` are for extensions).
- HTTPS when demonstrating genuine GPS on a phone or another computer (see §8).

---

## 5. Local setup with XAMPP

### Step 1 — Copy the project
Copy the whole `geotrack-gis` folder into the XAMPP web root, e.g.

```
C:\xampp\htdocs\geotrack-gis
```

### Step 2 — Start Apache and MySQL
Open the XAMPP Control Panel and start **Apache** and **MySQL**.

### Step 3 — Create the database
Either use phpMyAdmin (`http://localhost/phpmyadmin`) → **Import** → choose `database/geotrack_gis.sql` → **Go**, or run:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p < "C:\xampp\htdocs\geotrack-gis\database\geotrack_gis.sql"
```

The script creates the `geotrack_gis` database, all tables, indexes, foreign keys, and fictional demo data. Seeded positions use timestamps relative to import time, so the demo trail always falls inside the 7-day analysis window.

### Step 4 — Configure the database connection
The defaults are `127.0.0.1:3306`, user `root`, empty password, database `geotrack_gis`. If your MySQL root user has a password (recommended), create the local override:

```bat
copy config\config.local.example.php config\config.local.php
```

Then edit `config/config.local.php`:

```php
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'geotrack_gis',
        'user' => 'root',
        'password' => 'your-mysql-password',
    ],
    'python' => [
        'bin' => 'C:\\Users\\YOURNAME\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
    ],
];
```

Never commit `config.local.php`. It is already blocked by `.htaccess` and ignored by `.gitignore`.

Alternatively use environment variables (Apache `SetEnv` or Windows `set`):

| Variable | Meaning | Default |
| --- | --- | --- |
| `GEOTRACK_DB_HOST` | MySQL host | `127.0.0.1` |
| `GEOTRACK_DB_PORT` | MySQL port | `3306` |
| `GEOTRACK_DB_NAME` | Database name | `geotrack_gis` |
| `GEOTRACK_DB_USER` | Database user | `root` |
| `GEOTRACK_DB_PASSWORD` | Database password | *(empty)* |
| `GEOTRACK_PYTHON` | Absolute path to the Python executable | `python` / `python3` |
| `GEOTRACK_IP_GEO_ENABLED` | Enable server-side IP lookup | `1` |
| `GEOTRACK_IP_GEO_ENDPOINT` | Provider URL template | `https://ipwho.is/{ip}` |
| `GEOTRACK_SHOW_DEMO_HINT` | Show bootstrap credentials on the login modal | `1` |
| `GEOTRACK_ADMIN_USER` / `GEOTRACK_ADMIN_PASSWORD` | First-run bootstrap admin | `admin` / `GeoTrack@2026` |
| `GEOTRACK_BOOTSTRAP_ADMIN` | Enable first-run admin creation | `1` |
| `GEOTRACK_TRUST_PROXY` | Trust `X-Forwarded-For` (only behind your own proxy) | `0` |
| `GEOTRACK_DEBUG` | Verbose server errors | `0` |

### Step 5 — Install Python (optional packages)
The GIS module runs with the Python standard library. To install the optional scientific stack:

```bat
cd C:\xampp\htdocs\geotrack-gis
python -m pip install -r requirements.txt
```

Verify the module:

```bat
python python\gis_analysis.py --selftest
```

Expected: JSON with `"ok": true`, `7 / 7` tests passed. The dashboard also has a **Run Python self-test** button and `api/health.php` reports Python availability.

### Step 6 — Open the dashboard
```
http://localhost/geotrack-gis/
```

Sign in with the bootstrap classroom account shown on the login modal:

- Username: `admin`
- Password: `GeoTrack@2026`

The account is created automatically on the first successful login while the `users` table is empty. **Change the password immediately** in *Privacy & data → Change password*, or set `GEOTRACK_ADMIN_PASSWORD` before first login. Disable `show_demo_credentials` (`GEOTRACK_SHOW_DEMO_HINT=0`) for any non-classroom deployment.

---

## 6. How PHP calls Python

1. `assets/js/app.js` calls `api/analysis.php` with a JSON action.
2. PHP validates parameters against known ranges and the user's permissions.
3. `gt_python_call()` (in `includes/app.php`) starts the interpreter with `proc_open()` using a **fixed argument array** — no shell string, no user input in the command — writes one JSON object to the child's stdin, closes it, reads JSON from stdout, and enforces a 12-second timeout.
4. Only the documented actions (`distance`, `movement`, `proximity`, `network`, `validate`, `selftest`) are accepted by the Python module. Unknown actions return a structured error.
5. If Python is missing, the API returns HTTP 503 with a clear message; the rest of the dashboard continues to work.

Try it directly:

```bat
echo {"action":"distance","lat1":-0.6117,"lon1":30.6581,"lat2":0.3476,"lon2":32.5825} | python python\gis_analysis.py
```

---

## 7. API quick reference

All endpoints return JSON in the shape `{"success": true, "data": ...}` or `{"success": false, "error": {"code": "...", "message": "..."}}`. State-changing requests require the session cookie **and** the `X-CSRF-Token` header (the token is exposed in the page's `<meta name="csrf-token">` and in `api/session.php`).

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `api/session.php` | GET / POST | Session payload; `login`, `logout`, `change_password` |
| `api/entities.php` | GET / POST / DELETE | List visible entities; create entity; delete own entity (admin: any) |
| `api/location_update.php` | POST | Validate and store one position (GPS or simulation) |
| `api/location_history.php` | GET / DELETE | Paginated history for one entity; clear history |
| `api/tracking_status.php` | POST | Set `active` / `paused` / `stopped` |
| `api/stats.php` | GET | KPI, type counts, daily update counts, latest records |
| `api/analysis.php` | POST | `distance`, `movement`, `proximity`, `network`, `validate`, `selftest` |
| `api/ip_geolocation.php` | GET | Server-side lookup, MySQL cache, sample fallback |
| `api/maintenance.php` | GET / POST | Counts; `clear_my_data`, `clear_demo_history`, `purge_expired`, `purge_cache` |
| `api/health.php` | GET | Public health check (DB + Python) |

Successful writes return HTTP 200/201, validation failures 422, authentication 401, authorization 403, CSRF 419, rate limiting 429, missing resources 404.

---

## 8. HTTPS and phone GPS

Browsers only provide geolocation in a **secure context**. That means:

- `http://localhost` and `http://127.0.0.1` work for development.
- `http://192.168.x.x/geotrack-gis/` **will not** return a GPS fix on a phone.

For a phone demonstration, either:
1. Put the project behind HTTPS (XAMPP with a self-signed certificate, a local reverse proxy such as Caddy, or a school server with TLS), or
2. Use `ngrok`/`cloudflared` to expose the local site over HTTPS temporarily, or
3. Use the clearly labelled simulation, which needs no location permission.

The app detects the failure and shows recovery instructions instead of silently doing nothing.

---

## 9. Security and privacy design

- **Consent first** — no `watchPosition()` call until the user ticks the consent checkbox; declining cancels the session and collects nothing.
- **Visible tracking state** — header badge plus a persistent "Stop all" button while any tracking is running.
- **Authentication and authorization** — sessions with HttpOnly/SameSite cookies, idle timeout, login rate limiting and lockout. Every history/entity read and write passes an owner/admin check server-side (hiding a button is never treated as security).
- **CSRF** — token required on all POST/DELETE endpoints, compared with `hash_equals()`.
- **Validation** — coordinate ranges, timestamp window (not in the future, not older than 7 days for live writes), accuracy/speed plausibility, entity type and source matching, body size limit.
- **SQL injection** — PDO prepared statements with `ATTR_EMULATE_PREPARES = false`; never string-concatenated user input.
- **XSS** — server output escaped with `htmlspecialchars`; DOM built with `textContent`/`createElement`; CSP with a per-request nonce and no inline event handlers.
- **Rate limiting** — file-backed counters for login, writes, entity creation and IP lookups.
- **Secrets** — no production credentials in the repository; local overrides are excluded and web-denied.
- **Data lifecycle** — 90-day retention with a purge action, clear-history confirmation, delete-my-data, clear-demo-history, audit trail of security-relevant actions.
- **Demo honesty** — `is_demo` and `source_type` are enforced server-side; simulated points cannot be written to a real (non-demo) entity, and simulated entities cannot receive GPS points.

---

## 10. Testing performed

Automated and manual checks completed during development:

- `php -l` on every PHP file — no syntax errors.
- `node --check` on all three JavaScript files — no syntax errors.
- `python python/gis_analysis.py --selftest` — **7 / 7** tests passed.
- PowerShell API suite against the running app (session, CSRF, login, entities, writes, duplicates, history, status, stats, all analysis actions, IP, maintenance, health, delete) — **all checks passed**.
- Browser tests in Chromium: login, map tiles, marker selection, trails, three charts, history, distance / proximity / movement / validation / Python self-test, simulation Start/Pause/Resume/Reset, consent accept and decline, denied geolocation path, add/delete entity with confirmation, privacy counts and clear-demo-history, IP sample labelling, zero console errors.
- Mobile layout is CSS-driven (breakpoints at 1180, 980, 720 and 520 px) and was reviewed in a narrow browser viewport. Tables scroll horizontally and charts resize.

Re-run the checks after changes:

```bat
php -l index.php
python python\gis_analysis.py --selftest
curl http://localhost/geotrack-gis/api/health.php
```

---

## 11. Deployment notes

1. Serve over **HTTPS**; set `session.cookie_secure=1` and HSTS in Apache for a real deployment.
2. Change the bootstrap admin password and set `GEOTRACK_SHOW_DEMO_HINT=0`.
3. Create a dedicated MySQL user with rights only on `geotrack_gis` (do not use root).
4. Keep `config/`, `includes/`, `python/`, `database/` and `storage/` outside the web root if your host allows it; the included `.htaccess` files deny direct access otherwise.
5. Ensure `storage/` is writable by the web server.
6. Schedule `api/maintenance.php` (`action=purge_expired`) or call the function from a cron job to enforce retention automatically.
7. Consider disabling the IP geolocation provider if outbound HTTPS is not allowed.
8. Point the tile URL at your own tile server if OpenStreetMap usage grows; respect the OSM tile usage policy.

---

## 12. External services and keys

| Service | Key required? | Used for | Notes |
| --- | --- | --- | --- |
| OpenStreetMap tile servers | No | Base map tiles | Requires internet; attribution shown on the map. |
| `ipwho.is` | No | Approximate IP geolocation | Keyless, called server-side, cached 10 minutes. Can be disabled. |
| Local Leaflet / Chart.js | No | Map and charts | Vendored in `assets/vendor/`, no CDN at runtime. |

No API keys are stored in the code. If you switch to a keyed IP provider, keep the key in `config.local.php` or an environment variable and update `api/ip_geolocation.php` accordingly.

---

## 13. Honest limitations

- **Genuine GPS needs a secure context** (HTTPS or localhost) and device permission. Desktop browsers often return a coarse Wi-Fi/network fix; accuracy is reported as supplied by the browser.
- **One browser tracks one device.** Multi-device tracking works because every device posts to the shared backend, but the dashboard refreshes other devices by **polling** (entities every 8 s, trails/stats every 12–15 s), not WebSocket push. It is near-real-time, not millisecond live.
- **Simulation is administrator-only** and fictional. It deliberately cannot be mixed with real GPS records.
- **IP geolocation is approximate and can be wrong.** VPNs, proxies, mobile carriers and ISP gateways can place the result in another city or country. It is never written to movement history and never presented as tracking.
- **Python core is Haversine on a spherical Earth.** That is accurate to roughly 0.3–0.5 % for short campus routes; optional `geopy` adds an ellipsoidal comparison but is not installed by default.
- **"Suspicious movement" flags are warnings, not conclusions.** GPS jumps, indoor fixes and coarse accuracy can all produce flags.
- **No background tracking.** Closing the tab stops browser GPS/simulation. There is no native mobile background service.
- **Single-server deployment.** Sessions, rate limits and caches are local to one PHP host; a multi-server setup would need shared storage.
- **Email/password reset is not implemented.** The classroom admin can create accounts only through direct database/SQL for now.
- **No automated test harness is shipped.** Testing was performed with the commands and manual procedure above; a future version could add PHPUnit and Playwright.

---

## 14. Troubleshooting

| Symptom | Fix |
| --- | --- |
| "The database could not be reached" | Start MySQL, import `database/geotrack_gis.sql`, check `config/config.local.php`, and confirm the port (3306 by default). |
| Login modal shows no credentials | Expected when `GEOTRACK_SHOW_DEMO_HINT=0`; use the admin account you configured. |
| Charts area says Chart.js is missing | Confirm `assets/vendor/chartjs/chart.umd.min.js` exists and is readable. |
| Map is blank | Check internet access to `tile.openstreetmap.org`; vendor JS/CSS are local. |
| GPS button says permission denied | Allow location for the site in browser settings, use HTTPS/localhost, then press Start GPS again. |
| "Python GIS module is unavailable" | Run `python python\gis_analysis.py --selftest`; set `GEOTRACK_PYTHON` to the full executable path if `python` is not on PATH. |
| HTTP 419 on save | The CSRF token expired after a long idle period; refresh the page and sign in again. |
| HTTP 429 | Rate limit reached (240 writes/minute). Wait a minute; the UI explains this. |

---

## 15. License and academic use

Coursework project for MUST. Use fictional demo identities for classroom demonstrations and obtain explicit consent before tracking any real person or device.
