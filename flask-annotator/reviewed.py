"""Per-model 'reviewed' flag, stored as a JSON array of filenames in
reviewed.json next to annotations.json. Separate from the in-progress/done
status so a reviewer can sign off without changing labeling state.
"""
from __future__ import annotations

import json
import os
from pathlib import Path


def _path(model_dir: Path) -> Path:
    return model_dir / "reviewed.json"


def load_reviewed(model_dir: Path) -> set[str]:
    p = _path(model_dir)
    if not p.is_file():
        return set()
    try:
        raw = json.loads(p.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError):
        return set()
    if not isinstance(raw, list):
        return set()
    return {str(x) for x in raw if isinstance(x, str)}


def save_reviewed(model_dir: Path, reviewed: set[str]) -> None:
    p = _path(model_dir)
    tmp = p.with_suffix(f".json.tmp.{os.getpid()}")
    tmp.write_text(
        json.dumps(sorted(reviewed), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    os.replace(str(tmp), str(p))


def set_reviewed(model_dir: Path, filename: str, value: bool) -> bool:
    """Add or remove `filename` from the set. Returns the new state."""
    reviewed = load_reviewed(model_dir)
    if value:
        reviewed.add(filename)
    else:
        reviewed.discard(filename)
    save_reviewed(model_dir, reviewed)
    return value
