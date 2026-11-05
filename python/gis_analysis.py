#!/usr/bin/env python3
"""
GeoTrack GIS — Python spatial analysis module.

This module performs the GIS calculations for the PHP backend:

  * Haversine distance and initial bearing
  * Movement analysis (distance, speed, duration, data-quality flags)
  * Proximity analysis within a radius of a point
  * Coordinate / record validation
  * Network summaries for charts

It is intentionally dependency-light: the core implementation uses only the
Python standard library. If `geopy` is installed, an additional WGS-84
ellipsoidal distance is reported for comparison; it is not required.

CLI protocol (used by api/analysis.php):
    echo '{"action":"distance","lat1":0,"lon1":0,"lat2":0,"lon2":1}' | python gis_analysis.py
    python gis_analysis.py --selftest

Only documented actions are accepted. No user input is ever executed as a
shell command or evaluated as code.
"""

from __future__ import annotations

import json
import math
import sys
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, Iterable, List, Optional, Tuple

SCHEMA_VERSION = "1.0.0"
EARTH_RADIUS_M = 6371008.8  # IUGG mean Earth radius

# Plausibility thresholds are warnings only, never definitive claims.
SPEED_WARN_KMH = {
    "person": 40.0,
    "laptop": 150.0,
    "vehicle": 280.0,
    "asset": 200.0,
    "other": 250.0,
}

try:  # Optional, improves accuracy but is not required for the app to run.
    from geopy.distance import geodesic as _geodesic  # type: ignore

    GEOPY_AVAILABLE = True
except Exception:  # pragma: no cover - environment dependent
    _geodesic = None
    GEOPY_AVAILABLE = False


class AnalysisError(ValueError):
    """Raised for invalid, user-facing analysis input."""

    def __init__(self, message: str, code: str = "invalid_input"):
        super().__init__(message)
        self.code = code


def _round(value: float, digits: int = 3) -> float:
    if value is None or not math.isfinite(value):
        return 0.0
    return round(float(value), digits)


def valid_latitude(value: Any) -> bool:
    try:
        return math.isfinite(float(value)) and -90.0 <= float(value) <= 90.0
    except (TypeError, ValueError):
        return False


def valid_longitude(value: Any) -> bool:
    try:
        return math.isfinite(float(value)) and -180.0 <= float(value) <= 180.0
    except (TypeError, ValueError):
        return False


def require_coordinate(lat: Any, lon: Any, label: str = "coordinate") -> Tuple[float, float]:
    if not valid_latitude(lat):
        raise AnalysisError(f"{label} latitude must be a number between -90 and 90.", "invalid_latitude")
    if not valid_longitude(lon):
        raise AnalysisError(f"{label} longitude must be a number between -180 and 180.", "invalid_longitude")
    return float(lat), float(lon)


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance in metres using the Haversine formula."""
    lat1, lon1 = require_coordinate(lat1, lon1, "Start")
    lat2, lon2 = require_coordinate(lat2, lon2, "End")
    p1 = math.radians(lat1)
    p2 = math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2.0) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dlambda / 2.0) ** 2
    a = min(1.0, max(0.0, a))
    return 2.0 * EARTH_RADIUS_M * math.atan2(math.sqrt(a), math.sqrt(1.0 - a))


def initial_bearing_deg(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    p1 = math.radians(lat1)
    p2 = math.radians(lat2)
    dl = math.radians(lon2 - lon1)
    x = math.sin(dl) * math.cos(p2)
    y = math.cos(p1) * math.sin(p2) - math.sin(p1) * math.cos(p2) * math.cos(dl)
    return (math.degrees(math.atan2(x, y)) + 360.0) % 360.0


def cardinal(degrees: float) -> str:
    names = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"]
    return names[int((degrees + 22.5) // 45) % 8]


def parse_time(value: Any, label: str = "timestamp") -> Optional[datetime]:
    if value is None or value == "":
        return None
    if isinstance(value, (int, float)):
        # Accept Unix epoch seconds or milliseconds.
        number = float(value)
        if number > 10_000_000_000:
            number /= 1000.0
        return datetime.fromtimestamp(number, tz=timezone.utc)
    if not isinstance(value, str):
        raise AnalysisError(f"{label} must be an ISO-8601 string.", "invalid_timestamp")
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        result = datetime.fromisoformat(text)
    except ValueError as exc:
        raise AnalysisError(f"{label} is not a valid ISO-8601 date/time.", "invalid_timestamp") from exc
    if result.tzinfo is None:
        result = result.replace(tzinfo=timezone.utc)
    return result.astimezone(timezone.utc)


def iso(dt: Optional[datetime]) -> Optional[str]:
    if dt is None:
        return None
    return dt.astimezone(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def normalize_record(raw: Dict[str, Any], index: int) -> Tuple[Optional[Dict[str, Any]], Optional[Dict[str, Any]]]:
    """Return (point, rejection)."""
    try:
        lat = float(raw.get("latitude", raw.get("lat")))
        lon = float(raw.get("longitude", raw.get("lon", raw.get("lng"))))
    except (TypeError, ValueError):
        return None, {"index": index, "reason": "Latitude/longitude is not numeric."}
    if not valid_latitude(lat) or not valid_longitude(lon):
        return None, {"index": index, "reason": "Coordinate is outside valid latitude/longitude ranges."}

    recorded = raw.get("recorded_at", raw.get("timestamp"))
    try:
        ts = parse_time(recorded)
    except AnalysisError as exc:
        return None, {"index": index, "reason": str(exc)}
    if ts is None:
        return None, {"index": index, "reason": "Missing recorded_at timestamp."}

    accuracy = raw.get("accuracy")
    try:
        accuracy = None if accuracy in (None, "") else float(accuracy)
    except (TypeError, ValueError):
        accuracy = None
    if accuracy is not None and (not math.isfinite(accuracy) or accuracy < 0 or accuracy > 100_000):
        accuracy = None

    speed = raw.get("speed")
    try:
        speed = None if speed in (None, "") else float(speed)
    except (TypeError, ValueError):
        speed = None
    if speed is not None and (not math.isfinite(speed) or speed < 0 or speed > 300):
        speed = None

    return {
        "latitude": lat,
        "longitude": lon,
        "recorded_at": ts,
        "accuracy": accuracy,
        "speed": speed,
        "source_type": str(raw.get("source_type") or "unknown"),
        "raw_index": index,
    }, None


def validate_record(record: Dict[str, Any]) -> Dict[str, Any]:
    """Validate a single submitted record and classify warnings."""
    errors: List[str] = []
    warnings: List[Dict[str, str]] = []
    lat, lon = record.get("latitude"), record.get("longitude")
    if not valid_latitude(lat):
        errors.append("Latitude must be between -90 and 90.")
    if not valid_longitude(lon):
        errors.append("Longitude must be between -180 and 180.")

    ts = None
    try:
        ts = parse_time(record.get("recorded_at"))
    except AnalysisError as exc:
        errors.append(str(exc))
    now = datetime.now(timezone.utc)
    if ts is not None:
        if ts > now + timedelta(minutes=3):
            warnings.append({"code": "timestamp_future", "message": "Timestamp is in the future; check the device clock."})
        if ts < now - timedelta(days=3650):
            warnings.append({"code": "timestamp_old", "message": "Timestamp is more than ten years old."})

    accuracy = record.get("accuracy")
    if accuracy is not None:
        try:
            accuracy = float(accuracy)
            if accuracy > 500:
                warnings.append({"code": "very_coarse", "message": "Accuracy is worse than 500 m; treat this position cautiously."})
            elif accuracy > 100:
                warnings.append({"code": "coarse", "message": "Accuracy is worse than 100 m."})
        except (TypeError, ValueError):
            errors.append("Accuracy must be numeric.")

    speed = record.get("speed")
    if speed is not None:
        try:
            speed = float(speed)
            if speed > 90:
                warnings.append({"code": "implausible_speed", "message": "Speed is above 324 km/h; verify the data."})
        except (TypeError, ValueError):
            errors.append("Speed must be numeric.")

    return {
        "valid": not errors,
        "errors": errors,
        "warnings": warnings,
        "summary": "Record passes basic validation." if not errors else "Record has validation errors.",
    }


def movement_analysis(points: Iterable[Dict[str, Any]], entity_type: str = "other") -> Dict[str, Any]:
    """Order and analyse a set of location records."""
    if entity_type not in SPEED_WARN_KMH:
        entity_type = "other"

    normalized: List[Dict[str, Any]] = []
    rejected: List[Dict[str, Any]] = []
    for index, raw in enumerate(points or []):
        if not isinstance(raw, dict):
            rejected.append({"index": index, "reason": "Record is not an object."})
            continue
        point, rejection = normalize_record(raw, index)
        if rejection is not None:
            rejected.append(rejection)
        elif point is not None:
            normalized.append(point)

    if not normalized:
        return {
            "ok": True,
            "point_count": 0,
            "valid_point_count": 0,
            "rejected_points": rejected,
            "total_distance_m": 0.0,
            "total_distance_km": 0.0,
            "duration_seconds": 0.0,
            "avg_speed_kmh": 0.0,
            "max_speed_kmh": 0.0,
            "max_speed_source": "none",
            "first": None,
            "last": None,
            "legs": [],
            "daily": [],
            "warnings": [],
            "suspicious_legs": 0,
            "summary": "No valid points were available for movement analysis.",
        }

    ordered = sorted(normalized, key=lambda p: (p["recorded_at"], p["raw_index"]))
    warnings: List[Dict[str, Any]] = []
    if len(ordered) < len(normalized):
        warnings.append({"code": "duplicate_timestamp", "message": "Some records share timestamps and were ordered by arrival."})

    threshold = SPEED_WARN_KMH[entity_type]
    total_distance = 0.0
    legs: List[Dict[str, Any]] = []
    suspicious = 0
    max_speed_kmh = 0.0
    max_speed_source = "none"
    daily: Dict[str, float] = {}

    for previous, current in zip(ordered, ordered[1:]):
        distance = haversine_m(previous["latitude"], previous["longitude"], current["latitude"], current["longitude"])
        dt_seconds = (current["recorded_at"] - previous["recorded_at"]).total_seconds()
        computed = (distance / dt_seconds * 3.6) if dt_seconds > 0 else None
        if computed is not None:
            if computed > max_speed_kmh:
                max_speed_kmh = computed
                max_speed_source = "computed"
            if computed > threshold:
                suspicious += 1
                warnings.append({
                    "code": "implausible_jump",
                    "message": f"Implied speed of {computed:.1f} km/h between points exceeds the {threshold:.0f} km/h review threshold for a {entity_type}.",
                    "leg_index": len(legs),
                })
        if current.get("speed") is not None:
            provided = float(current["speed"]) * 3.6
            if provided > max_speed_kmh or max_speed_source == "none":
                max_speed_kmh = provided
                max_speed_source = "device"
        if current.get("accuracy") is not None and float(current["accuracy"]) > 100:
            warnings.append({
                "code": "coarse_point",
                "message": f"Point {current['raw_index'] + 1} has low accuracy ({float(current['accuracy']):.0f} m).",
                "point_index": current["raw_index"],
            })
        day = current["recorded_at"].date().isoformat()
        total_distance += distance
        daily[day] = daily.get(day, 0.0) + distance
        legs.append({
            "from_index": previous["raw_index"],
            "to_index": current["raw_index"],
            "distance_m": _round(distance, 2),
            "duration_seconds": _round(dt_seconds, 2),
            "computed_speed_kmh": _round(computed, 2) if computed is not None else None,
        })

    duration = max(0.0, (ordered[-1]["recorded_at"] - ordered[0]["recorded_at"]).total_seconds())
    avg_speed = (total_distance / duration * 3.6) if duration > 0 else 0.0

    return {
        "ok": True,
        "point_count": len(points or []),
        "valid_point_count": len(ordered),
        "rejected_points": rejected,
        "total_distance_m": _round(total_distance, 2),
        "total_distance_km": _round(total_distance / 1000.0, 3),
        "duration_seconds": _round(duration, 1),
        "duration_human": format_duration(duration),
        "avg_speed_kmh": _round(avg_speed, 2),
        "max_speed_kmh": _round(max_speed_kmh, 2),
        "max_speed_source": max_speed_source,
        "first": serialize_point(ordered[0]),
        "last": serialize_point(ordered[-1]),
        "legs": legs,
        "daily": [{"date": day, "distance_m": _round(value, 2)} for day, value in sorted(daily.items())],
        "warnings": warnings[:100],
        "suspicious_legs": suspicious,
        "summary": movement_summary(total_distance, duration, len(ordered), suspicious),
    }


def serialize_point(point: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "latitude": _round(point["latitude"], 7),
        "longitude": _round(point["longitude"], 7),
        "recorded_at": iso(point["recorded_at"]),
        "accuracy": _round(point["accuracy"], 2) if point.get("accuracy") is not None else None,
        "speed": _round(point["speed"], 3) if point.get("speed") is not None else None,
        "source_type": point.get("source_type", "unknown"),
    }


def format_duration(seconds: float) -> str:
    seconds = max(0.0, float(seconds))
    if seconds < 60:
        return f"{seconds:.0f} s"
    minutes = seconds / 60.0
    if minutes < 60:
        return f"{minutes:.1f} min"
    hours = minutes / 60.0
    if hours < 24:
        return f"{hours:.2f} h"
    return f"{hours / 24.0:.1f} days"


def movement_summary(total_m: float, duration_s: float, count: int, suspicious: int) -> str:
    if count < 2:
        return "At least two valid points are needed to analyse movement."
    summary = f"{count} valid points, {total_m / 1000.0:.2f} km over {format_duration(duration_s)}."
    if suspicious:
        summary += f" {suspicious} leg(s) exceed the review threshold; this is a data-quality warning, not proof of movement."
    return summary


def proximity_analysis(
    origin_lat: Any,
    origin_lon: Any,
    radius_m: Any,
    entities: Iterable[Dict[str, Any]],
    limit: int = 100,
) -> Dict[str, Any]:
    lat, lon = require_coordinate(origin_lat, origin_lon, "Origin")
    try:
        radius = float(radius_m)
    except (TypeError, ValueError) as exc:
        raise AnalysisError("Radius must be numeric.", "invalid_radius") from exc
    if not math.isfinite(radius) or radius <= 0 or radius > 100_000:
        raise AnalysisError("Radius must be greater than 0 and no more than 100,000 metres.", "invalid_radius")

    results: List[Dict[str, Any]] = []
    rejected: List[Dict[str, Any]] = []
    for index, entity in enumerate(entities or []):
        if not isinstance(entity, dict):
            rejected.append({"index": index, "reason": "Entity is not an object."})
            continue
        e_lat = entity.get("latitude", entity.get("lat"))
        e_lon = entity.get("longitude", entity.get("lon", entity.get("lng")))
        if not valid_latitude(e_lat) or not valid_longitude(e_lon):
            rejected.append({"index": index, "reason": "Entity has invalid or missing coordinates."})
            continue
        distance = haversine_m(lat, lon, float(e_lat), float(e_lon))
        if distance <= radius:
            results.append({
                "entity_id": entity.get("entity_id", entity.get("id")),
                "entity_code": entity.get("entity_code"),
                "entity_name": entity.get("entity_name"),
                "entity_type": entity.get("entity_type"),
                "latitude": _round(float(e_lat), 7),
                "longitude": _round(float(e_lon), 7),
                "distance_m": _round(distance, 2),
                "distance_km": _round(distance / 1000.0, 3),
                "recorded_at": entity.get("recorded_at"),
                "source_type": entity.get("source_type"),
            })

    results.sort(key=lambda item: item["distance_m"])
    results = results[: max(1, min(int(limit), 500))]
    return {
        "ok": True,
        "origin": {"latitude": _round(lat, 7), "longitude": _round(lon, 7)},
        "radius_m": _round(radius, 2),
        "radius_km": _round(radius / 1000.0, 3),
        "matched_count": len(results),
        "candidate_count": len(list(entities or [])),
        "rejected_entities": rejected,
        "results": results,
        "summary": f"{len(results)} entit{'y' if len(results) == 1 else 'ies'} within {radius:.0f} m.",
    }


def network_summary(entities: Iterable[Dict[str, Any]], entity_type: str = "other") -> Dict[str, Any]:
    """Analyse multiple entities for dashboard charts."""
    summaries: List[Dict[str, Any]] = []
    total_distance = 0.0
    total_points = 0
    daily_totals: Dict[str, float] = {}

    for entity in entities or []:
        if not isinstance(entity, dict):
            continue
        points = entity.get("points") or []
        analysis = movement_analysis(points, entity.get("entity_type") or entity_type)
        distance = float(analysis.get("total_distance_m") or 0.0)
        total_distance += distance
        total_points += int(analysis.get("valid_point_count") or 0)
        for day in analysis.get("daily") or []:
            daily_totals[day["date"]] = daily_totals.get(day["date"], 0.0) + float(day["distance_m"])
        summaries.append({
            "entity_id": entity.get("entity_id", entity.get("id")),
            "entity_code": entity.get("entity_code"),
            "entity_name": entity.get("entity_name"),
            "entity_type": entity.get("entity_type"),
            "total_distance_m": _round(distance, 2),
            "total_distance_km": _round(distance / 1000.0, 3),
            "avg_speed_kmh": analysis.get("avg_speed_kmh", 0.0),
            "valid_point_count": analysis.get("valid_point_count", 0),
            "suspicious_legs": analysis.get("suspicious_legs", 0),
            "last": analysis.get("last"),
        })

    summaries.sort(key=lambda item: item["total_distance_m"], reverse=True)
    return {
        "ok": True,
        "entity_count": len(summaries),
        "total_distance_m": _round(total_distance, 2),
        "total_distance_km": _round(total_distance / 1000.0, 3),
        "total_valid_points": total_points,
        "daily": [{"date": day, "distance_m": _round(value, 2)} for day, value in sorted(daily_totals.items())],
        "entities": summaries,
        "summary": f"{len(summaries)} entities, {total_points} valid points, {total_distance / 1000.0:.2f} km total.",
    }


def distance_analysis(lat1: Any, lon1: Any, lat2: Any, lon2: Any) -> Dict[str, Any]:
    a_lat, a_lon = require_coordinate(lat1, lon1, "Point A")
    b_lat, b_lon = require_coordinate(lat2, lon2, "Point B")
    distance = haversine_m(a_lat, a_lon, b_lat, b_lon)
    bearing = initial_bearing_deg(a_lat, a_lon, b_lat, b_lon)
    result: Dict[str, Any] = {
        "ok": True,
        "point_a": {"latitude": _round(a_lat, 7), "longitude": _round(a_lon, 7)},
        "point_b": {"latitude": _round(b_lat, 7), "longitude": _round(b_lon, 7)},
        "distance_m": _round(distance, 2),
        "distance_km": _round(distance / 1000.0, 3),
        "bearing_deg": _round(bearing, 1),
        "bearing_cardinal": cardinal(bearing),
        "method": "Haversine (mean Earth radius 6,371,008.8 m)",
    }
    if GEOPY_AVAILABLE and _geodesic is not None:
        try:
            result["geodesic_distance_m"] = _round(float(_geodesic((a_lat, a_lon), (b_lat, b_lon)).m), 2)
            result["geopy_available"] = True
        except Exception:
            result["geopy_available"] = False
    else:
        result["geopy_available"] = False
    return result


def dispatch(payload: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(payload, dict):
        raise AnalysisError("The request body must be an object.")
    action = str(payload.get("action") or "").strip().lower()

    if action == "distance":
        return distance_analysis(payload.get("lat1"), payload.get("lon1"), payload.get("lat2"), payload.get("lon2"))

    if action == "movement":
        points = payload.get("points")
        if not isinstance(points, list):
            raise AnalysisError("movement requires a points array.", "invalid_points")
        return movement_analysis(points, str(payload.get("entity_type") or "other"))

    if action == "proximity":
        entities = payload.get("entities")
        if not isinstance(entities, list):
            raise AnalysisError("proximity requires an entities array.", "invalid_entities")
        return proximity_analysis(
            payload.get("latitude"),
            payload.get("longitude"),
            payload.get("radius_m", 500),
            entities,
            int(payload.get("limit", 100) or 100),
        )

    if action == "network":
        entities = payload.get("entities")
        if not isinstance(entities, list):
            raise AnalysisError("network requires an entities array.", "invalid_entities")
        return network_summary(entities)

    if action == "validate":
        record = payload.get("record")
        if not isinstance(record, dict):
            raise AnalysisError("validate requires a record object.", "invalid_record")
        return {"ok": True, **validate_record(record)}

    if action == "selftest":
        return selftest()

    raise AnalysisError("Unknown analysis action. Supported: distance, movement, proximity, network, validate, selftest.", "unknown_action")


def selftest() -> Dict[str, Any]:
    tests: List[Dict[str, Any]] = []

    def check(name: str, condition: bool, detail: str) -> None:
        tests.append({"name": name, "passed": bool(condition), "detail": detail})

    d = haversine_m(0.0, 0.0, 0.0, 1.0)
    check("Haversine equator degree", abs(d - 111194.93) < 200, f"0,0 to 0,1 = {d:.2f} m")
    check("Zero distance", haversine_m(-0.6117, 30.6581, -0.6117, 30.6581) == 0.0, "identical points = 0 m")
    check("Bearing east", abs(initial_bearing_deg(0, 0, 0, 1) - 90.0) < 0.01, "east bearing = 90°")

    points = [
        {"latitude": 0.0, "longitude": 0.0, "recorded_at": "2026-01-01T10:00:00Z", "source_type": "gps"},
        {"latitude": 0.0, "longitude": 0.001, "recorded_at": "2026-01-01T10:01:00Z", "source_type": "gps"},
        {"latitude": 0.0, "longitude": 0.002, "recorded_at": "2026-01-01T10:02:00Z", "source_type": "gps"},
    ]
    movement = movement_analysis(points, "person")
    check("Movement distance", 220.0 < movement["total_distance_m"] < 225.0, f"{movement['total_distance_m']} m")
    check("Movement speed", 6.0 < movement["avg_speed_kmh"] < 7.0, f"{movement['avg_speed_kmh']} km/h")

    proximity = proximity_analysis(0.0, 0.0, 500, [
        {"id": 1, "entity_name": "Near", "latitude": 0.001, "longitude": 0.0},
        {"id": 2, "entity_name": "Far", "latitude": 1.0, "longitude": 1.0},
    ])
    check("Proximity inclusion", proximity["matched_count"] == 1, f"matched={proximity['matched_count']}")

    invalid = validate_record({"latitude": 91, "longitude": 200, "recorded_at": "not-a-date"})
    check("Invalid coordinate rejected", invalid["valid"] is False and len(invalid["errors"]) >= 2, str(invalid["errors"]))

    return {
        "ok": all(test["passed"] for test in tests),
        "tests": tests,
        "passed": sum(1 for test in tests if test["passed"]),
        "total": len(tests),
        "python_version": sys.version.split()[0],
        "geopy_available": GEOPY_AVAILABLE,
        "module_version": SCHEMA_VERSION,
    }


def main(argv: List[str]) -> int:
    try:
        if hasattr(sys.stdout, "reconfigure"):
            sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass

    if "--selftest" in argv or "--self-test" in argv:
        result = selftest()
        print(json.dumps(result, ensure_ascii=False))
        return 0 if result.get("ok") else 1

    if "--version" in argv:
        print(json.dumps({"ok": True, "version": SCHEMA_VERSION, "python": sys.version.split()[0], "geopy": GEOPY_AVAILABLE}))
        return 0

    raw = sys.stdin.read(8 * 1024 * 1024)
    try:
        payload = json.loads(raw or "{}")
        result = dispatch(payload)
        print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
        return 0
    except AnalysisError as exc:
        print(json.dumps({
            "ok": False,
            "error": {"code": exc.code, "message": str(exc)},
        }, ensure_ascii=False))
        return 0
    except Exception as exc:  # pragma: no cover - defensive
        print(json.dumps({
            "ok": False,
            "error": {"code": "analysis_exception", "message": "Analysis failed: " + str(exc)},
        }, ensure_ascii=False))
        return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
