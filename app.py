from __future__ import annotations

import csv
import io
import json
from datetime import datetime
from pathlib import Path

from flask import Flask, jsonify, render_template, request

app = Flask(__name__)
BASE_DIR = Path(__file__).resolve().parent
SNAPSHOT_DIR = BASE_DIR / "snapshots"
SNAPSHOT_DIR.mkdir(exist_ok=True)


def parse_records(raw_text: str, name: str):
    text = (raw_text or "").strip()
    if not text:
        raise ValueError(f"{name} is empty. Please provide valid data.")

    try:
        parsed = json.loads(text)
        if isinstance(parsed, list):
            return parsed
        if isinstance(parsed, dict):
            return [parsed]
        raise ValueError(f"{name} must be a JSON array or object.")
    except json.JSONDecodeError:
        pass

    sample = io.StringIO(text)
    try:
        dialect = csv.Sniffer().sniff(sample.read(4096), delimiters=",;\t|")
        sample.seek(0)
        reader = csv.DictReader(sample, dialect=dialect)
        rows = list(reader)
        if not rows:
            raise ValueError(f"{name} CSV is empty.")
        return rows
    except csv.Error:
        raise ValueError(f"{name} is not valid JSON or CSV.")


def normalize_row(record):
    if not isinstance(record, dict):
        return {"value": record}
    normalized = {}
    for key, value in record.items():
        normalized[str(key)] = value
    return normalized


def get_key_field(records, preferred_key: str | None = None):
    if preferred_key:
        return preferred_key

    if not records:
        return "id"

    first = normalize_row(records[0])
    candidates = ["id", "ID", "uuid", "UUID", "code", "Code", "name", "Name", "email", "Email"]
    for candidate in candidates:
        if candidate in first:
            return candidate
    return "id"


def compare_records(left_records, right_records, key_field: str | None = None):
    left = [normalize_row(item) for item in left_records]
    right = [normalize_row(item) for item in right_records]

    key = get_key_field(left or right, key_field)
    left_map = {str(item.get(key, "")): item for item in left}
    right_map = {str(item.get(key, "")): item for item in right}

    added = []
    removed = []
    modified = []

    for key_value in sorted(set(right_map) - set(left_map)):
        added.append({"key": key_value, "record": right_map[key_value]})

    for key_value in sorted(set(left_map) - set(right_map)):
        removed.append({"key": key_value, "record": left_map[key_value]})

    for key_value in sorted(set(left_map) & set(right_map)):
        before = left_map[key_value]
        after = right_map[key_value]
        if before != after:
            modified.append({
                "key": key_value,
                "before": before,
                "after": after,
            })

    summary = {
        "total_left": len(left),
        "total_right": len(right),
        "added": len(added),
        "removed": len(removed),
        "modified": len(modified),
        "key_field": key,
    }

    return {
        "key_field": key,
        "summary": summary,
        "added": added,
        "removed": removed,
        "modified": modified,
    }


def build_snapshot(name: str, records):
    version_file = SNAPSHOT_DIR / f"{name}-{datetime.now().strftime('%Y%m%d-%H%M%S')}.json"
    with version_file.open("w", encoding="utf-8") as handle:
        json.dump(records, handle, ensure_ascii=False, indent=2)
    return str(version_file.name)


@app.get("/")
def index():
    return render_template("index.html")


@app.post("/compare")
def compare_page():
    version_a = request.form.get("version_a", "")
    version_b = request.form.get("version_b", "")
    key_field = request.form.get("key_field", "").strip() or None

    try:
        left_records = parse_records(version_a, "Version A")
        right_records = parse_records(version_b, "Version B")
        report = compare_records(left_records, right_records, key_field)
    except ValueError as exc:
        return render_template("index.html", error=str(exc))

    snapshot_name = build_snapshot("snapshot", right_records)
    return render_template(
        "report.html",
        report=report,
        snapshot_name=snapshot_name,
        version_a=version_a,
        version_b=version_b,
    )


@app.get("/snapshots")
def list_snapshots():
    items = []
    for snapshot in sorted(SNAPSHOT_DIR.glob("*.json")):
        items.append({
            "name": snapshot.name,
            "size": snapshot.stat().st_size,
            "modified": datetime.fromtimestamp(snapshot.stat().st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
        })
    return jsonify(items)


@app.post("/restore")
def restore_version():
    filename = request.form.get("filename", "").strip()
    if not filename:
        return jsonify({"error": "Snapshot file is required."}), 400

    snapshot_path = SNAPSHOT_DIR / filename
    if not snapshot_path.exists():
        return jsonify({"error": "Snapshot not found."}), 404

    with snapshot_path.open("r", encoding="utf-8") as handle:
        records = json.load(handle)

    return jsonify({
        "restored_records": records,
        "filename": filename,
        "status": "success",
    })


if __name__ == "__main__":
    app.run(debug=True)
