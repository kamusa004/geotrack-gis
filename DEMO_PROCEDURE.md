# GeoTrack GIS — Demonstration Procedure

**Target duration:** 8–12 minutes
**Audience:** lecturer / class
**Goal:** show genuine GPS capture, MySQL persistence, Python GIS analysis, an interactive map, honest simulation and privacy controls.

---

## Before the session (5 minutes)

1. Start **Apache** and **MySQL** in XAMPP.
2. Confirm the database is imported: open `http://localhost/phpmyadmin`, database `geotrack_gis`, table `location_history`.
3. Confirm Python: open a terminal in the project and run
   ```bat
   python python\gis_analysis.py --selftest
   ```
   You should see `"ok": true` and `7 / 7`.
4. Open `http://localhost/geotrack-gis/` and sign in as the classroom administrator.
5. Have a phone or laptop ready for genuine GPS. Remember: a phone needs **HTTPS**; a laptop on `localhost` works.
6. Optional: clear old demo records with *Privacy & data → Clear demo history*, then reload so the numbers start clean.
7. Set the browser zoom to 100 % and close unrelated tabs.

> If there is no internet, the OpenStreetMap tiles will not load. The data, charts, history and Python analysis still work, but use a cached tile layer or a mobile hotspot for the visual demo.

---

## 1. Orientation (1 minute)

1. Point out the header: server connection, tracking state, sign-in account, live clock.
2. Point out the four KPI cards: tracked entities, active sessions, total distance (Python), last update.
3. Point out the layout: map + tracking controls on top, Analytics / GIS analysis / History / IP geolocation / Privacy & data below.

**Say:** "This is one page. Every button works against the PHP API and the MySQL database; the API files are endpoints, not extra pages."

---

## 2. Genuine GPS tracking (3 minutes)

1. In the **Device GPS** panel, press **Start GPS**.
2. Read the consent dialog aloud: what is stored, who can see it, how to stop.
3. Tick the consent box and press **I consent & start**.
4. Allow location in the browser prompt.
5. Show:
   - the marker and accuracy circle,
   - latitude, longitude, accuracy, speed and timestamp,
   - the blue trail polyline as you move the device a few metres,
   - distance travelled and the saved-record counter,
   - the header badge changing to "GPS tracking active".
6. Press **Pause**, wait, then **Resume** — explain that the watch stays open but nothing is recorded while paused.
7. Press **Stop**.
8. Open **phpMyAdmin** (or the History tab) and show the new rows in `location_history` with `source_type = gps`. This proves browser → PHP → MySQL persistence.

**If permission is denied:** the app shows recovery instructions and does not pretend to track. Say: "This is the error-handling requirement: no silent failure, no invented coordinates." Then continue with the simulation.

---

## 3. Simulated tracking (2 minutes)

1. Open the **Simulation DEMO** tab.
2. Point out the amber **DEMO** tag and the explanation that the entities are fictional.
3. Press **Start simulation**.
4. Show Person 001, Laptop 001, Vehicle 001 and Research Asset 001 moving along routes around MUST; point out distinct markers, trails, update counter and simulated distance.
5. Press **Pause**, then **Resume**, then **Reset**.
6. Emphasise: every record is labelled `simulated`; demo entities are admin-only and cannot be mixed with real GPS data.

---

## 4. Interactive map and entities (1 minute)

1. Click **Person 001** in the entity list: the map zooms to its last position, the selection card appears and its trail is highlighted.
2. Click **History** on the selection card: the History tab opens with that entity's records.
3. Use **Fit all** and **Zoom to selected** on the map toolbar.
4. Toggle **Trails** off/on.
5. Click a row in the recent-updates list to jump to that record.

---

## 5. GIS analysis with Python (2–3 minutes)

Open the **GIS analysis** tab.

1. **Distance** — press **Use selected** for point A, type the Kampala coordinates for point B (`0.3476`, `32.5825`), press **Calculate distance**. Expected result ≈ **239 km**, bearing north-east, method Haversine. State that this was computed by Python, not JavaScript.
2. **Proximity** — press **Use map centre** (or **Pick origin on map**), radius **500 m**, press **Find nearby entities**. Expected: the demo assets within 500 m, sorted nearest first with distances in metres. Press **Show on map** to draw the radius ring.
3. **Movement** — select Person 001, press **Analyse movement**. Show total distance, valid points, duration, average and maximum speed, review flags, and first/last positions.
4. **Validation** — press **Validate record** with the prefilled plausible record (passes). Then change latitude to `91` and validate again (rejected). This shows server-side Python validation.
5. Press **Run Python self-test**: show **7 / 7** and the individual checks.

**Say:** "The browser does not do the GIS maths; PHP invokes the Python module with a JSON payload and fixed arguments, and only documented actions are accepted."

---

## 6. Charts and history (1–2 minutes)

1. Return to **Analytics**: point out the distance-by-day bar chart (Python), the entity-type doughnut, the update chart, the recent-updates list and the per-entity distance/quality table.
2. Explain that all values come from MySQL through `api/stats.php` and `api/analysis.php`.
3. Open **History**: show entity, coordinates, time, accuracy, speed, source (`gps` / `simulated`) and quality flag.
4. Press **Show** on a row — the map zooms to that point.
5. Show **Clear history** is disabled until an entity is chosen, and that clearing asks for confirmation.

---

## 7. IP geolocation (1 minute)

1. Open **IP geolocation**.
2. Press **Look up my network**.
3. Show the result and its labels:
   - if the server sees a public IP, the provider result (country/region, city, approximate coordinates, ISP, lookup time, source);
   - if the server sees a local/private address, a **sample** result with a clear "not a live lookup" banner.
4. Read the limitations aloud: IP location estimates a network, not a device; VPNs, proxies, carriers and ISP gateways can move it; it is never used as movement history.
5. Optionally press **Show approximate point on map** to display the large approximate circle.

---

## 8. Privacy and security (1 minute)

Open **Privacy & data**:

1. Show the consent status card and the data-source disclosure (`gps` vs `simulated`).
2. Show the record counts and 90-day retention.
3. Show **Delete all my location data**, **Clear demo history** and **Purge expired records** — each with a confirmation dialog.
4. Mention the security controls: sign-in required for histories, owner/admin authorization, CSRF tokens, prepared statements, rate limiting, audit log, and no secrets in the code.
5. Press **Sign out** to finish.

---

## Closing summary (30 seconds)

- Genuine GPS: `watchPosition()` with consent, pause/resume/stop and clear errors.
- MySQL: seven tables, foreign keys, indexes, seed data, real rows visible in phpMyAdmin.
- PHP: validated JSON API with authentication, authorization and CSRF.
- Python: Haversine distance, movement, proximity, validation and self-test.
- Map and charts: Leaflet/OSM and Chart.js from live data.
- Honesty: simulated and sample data are always labelled; IP results are approximate.

---

## Fallback plan

| Problem | Recovery |
| --- | --- |
| GPS permission denied / no fix indoors | Demonstrate the simulation and the denial message; explain the HTTPS requirement. |
| No internet | Skip the map tiles; show the data, charts, analysis and phpMyAdmin. The map logic still runs with markers. |
| Python missing | Show the clear "Python unavailable" message and run the module from a terminal to prove it works; set `GEOTRACK_PYTHON`. |
| Database error | Re-import `database/geotrack_gis.sql`, check `config/config.local.php`. |
| Charts missing | Verify `assets/vendor/chartjs/chart.umd.min.js` exists; KPIs and tables still work. |

---

## Questions to expect

**"Is this tracking real people?"**
No. Demo entities are fictional and labelled. Real GPS runs only after explicit consent on that device.

**"Why Python if JavaScript can compute distance?"**
The assignment requires real GIS analysis in Python, and keeping the analysis server-side means the same logic can be audited, reused by other clients and tested independently.

**"What happens if the internet drops?"**
Positions continue to be collected in the page, but writes fail with a visible warning and retry logic; the app never silently discards the session. Map tiles and the IP provider need internet.

**"Can it track multiple phones?"**
Yes — each phone posts to the shared backend with its own account/entity; the dashboard refreshes other devices by polling every 8–15 seconds.
