"""Load, normalize, and save the per-model annotations.json file.

Schema versions:
  v1 — original PHP format (no schemaVersion, no boxes, no class colors).
  v2 — current. Adds schemaVersion, classes[].color, annotations.boxes[].

v1 files are normalized to v2 in memory on load. They are never rewritten on
disk until the user actually saves; this preserves the original until the
first explicit edit.
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any, Literal, TypedDict

SCHEMA_VERSION = 2

DEFAULT_PALETTE: list[str] = [
    "#22d3ee",  # cyan
    "#facc15",  # yellow
    "#f472b6",  # pink
    "#34d399",  # green
    "#fb7185",  # red
    "#a78bfa",  # purple
]


class LoadResult(TypedDict):
    status: Literal["ok", "missing", "corrupt", "future"]
    data: dict[str, Any]


def default_scaffold(model: str) -> dict[str, Any]:
    """A blank v2 annotations.json with the two default classes."""
    return {
        "model": model,
        "schemaVersion": SCHEMA_VERSION,
        "classes": [
            {"id": 0, "name": "path", "color": DEFAULT_PALETTE[0]},
            {"id": 1, "name": "path-oxod", "color": DEFAULT_PALETTE[1]},
        ],
        "images": [],
    }


def normalize(data: dict[str, Any]) -> dict[str, Any]:
    """Return a v2-shaped copy of `data`, filling in missing fields.

    Does not mutate the input.
    """
    out = dict(data)
    out["schemaVersion"] = SCHEMA_VERSION
    classes = []
    for idx, cls in enumerate(data.get("classes", [])):
        c = dict(cls)
        if "color" not in c:
            c["color"] = DEFAULT_PALETTE[idx % len(DEFAULT_PALETTE)]
        classes.append(c)
    out["classes"] = classes

    images = []
    for img in data.get("images", []):
        i = dict(img)
        ann = dict(i.get("annotations") or {})
        if "segments" not in ann:
            ann["segments"] = []
        if "boxes" not in ann:
            ann["boxes"] = []
        i["annotations"] = ann
        images.append(i)
    out["images"] = images
    return out


def load_annotations(model_dir: Path, model: str) -> LoadResult:
    """Read annotations.json from disk. Never raises on bad input.

    Status values:
      "missing" — file does not exist; data is the default scaffold.
      "corrupt" — file exists but isn't valid JSON; data is the default scaffold.
                  The bad file is left on disk; saving will rotate it aside.
      "future"  — file is valid but schemaVersion > 2; loaded as-is. Frontend
                  should treat as read-only.
      "ok"      — file is valid v1 or v2; normalized to v2 in memory.
    """
    path = model_dir / "annotations.json"
    if not path.is_file():
        return {"status": "missing", "data": default_scaffold(model)}

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError):
        return {"status": "corrupt", "data": default_scaffold(model)}

    if not isinstance(raw, dict):
        return {"status": "corrupt", "data": default_scaffold(model)}

    version = raw.get("schemaVersion", 1)
    if isinstance(version, int) and version > SCHEMA_VERSION:
        return {"status": "future", "data": raw}

    return {"status": "ok", "data": normalize(raw)}


def save_annotations(model_dir: Path, data: dict[str, Any]) -> None:
    """Atomically write annotations.json into model_dir.

    If an existing file is unreadable JSON, it is moved aside to
    `annotations.json.broken-<unix-ts>` so the user does not silently
    overwrite recoverable data.
    """
    path = model_dir / "annotations.json"

    # Rotate a corrupt existing file out of the way before writing.
    if path.is_file():
        try:
            json.loads(path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError):
            backup = model_dir / f"annotations.json.broken-{int(time.time())}"
            os.replace(str(path), str(backup))

    tmp = path.with_suffix(f".json.tmp.{os.getpid()}")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(str(tmp), str(path))
