"""Training run lifecycle: spawn an ultralytics subprocess, track its
state on disk in runs/<model>/<run_id>/state.json.

Each run is a directory:
  flask-annotator/runs/<model>/<run_id>/
    state.json   <- live state (status, epoch, error)
    train.log    <- subprocess stdout/stderr
    best.pt      <- trained weights, after a successful run
    ultralytics/ <- working dir Ultralytics writes into

State.json fields:
  run_id, model, status (starting|running|done|failed),
  started_at, ended_at, epochs, epoch_total, epoch_current,
  model_size, data_yaml, best_pt (relative path or null), error
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from pathlib import Path
from typing import Any


FLASK_DIR = Path(__file__).resolve().parent
_RUNNER = FLASK_DIR / "scripts" / "train_runner.py"


def is_available() -> tuple[bool, str | None]:
    """ultralytics + torch must import."""
    try:
        import torch  # noqa: F401
    except Exception as e:
        return False, f"torch not installed ({e.__class__.__name__})"
    try:
        import ultralytics  # noqa: F401
    except Exception as e:
        return False, f"ultralytics not installed ({e.__class__.__name__})"
    return True, None


def run_dir(model: str, run_id: str) -> Path:
    return FLASK_DIR / "runs" / model / run_id


def list_runs(model: str) -> list[dict[str, Any]]:
    base = FLASK_DIR / "runs" / model
    if not base.is_dir():
        return []
    out: list[dict[str, Any]] = []
    for d in sorted(base.iterdir(), reverse=True):
        st = d / "state.json"
        if not st.is_file():
            continue
        try:
            out.append(json.loads(st.read_text(encoding="utf-8")))
        except Exception:
            continue
    return out


def latest_run(model: str) -> dict[str, Any] | None:
    runs = list_runs(model)
    return runs[0] if runs else None


def read_state(model: str, run_id: str) -> dict[str, Any] | None:
    p = run_dir(model, run_id) / "state.json"
    if not p.is_file():
        return None
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except Exception:
        return None


def start_run(
    *,
    model: str,
    data_yaml: Path,
    epochs: int = 50,
    model_size: str = "yolov8n-seg",
) -> dict[str, Any]:
    """Spawn a training subprocess. Returns the new state dict.

    The subprocess runs detached (CREATE_NEW_PROCESS_GROUP on Windows) so
    it keeps going even if the Flask dev reloader bounces this process.
    """
    run_id = time.strftime("%Y%m%d_%H%M%S")
    rdir = run_dir(model, run_id)
    rdir.mkdir(parents=True, exist_ok=True)

    state = {
        "run_id": run_id,
        "model": model,
        "status": "starting",
        "started_at": time.time(),
        "ended_at": None,
        "epochs": epochs,
        "model_size": model_size,
        "epoch_current": None,
        "epoch_total": epochs,
        "best_pt": None,
        "data_yaml": str(data_yaml.resolve()),
        "error": None,
    }
    _save(rdir / "state.json", state)

    log_file = (rdir / "train.log").open("a", encoding="utf-8", buffering=1)
    flags = subprocess.CREATE_NEW_PROCESS_GROUP if os.name == "nt" else 0  # type: ignore[attr-defined]
    subprocess.Popen(
        [sys.executable, str(_RUNNER), str(rdir)],
        stdout=log_file,
        stderr=subprocess.STDOUT,
        cwd=str(FLASK_DIR),
        creationflags=flags,
    )
    return state


def _save(path: Path, state: dict[str, Any]) -> None:
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(state, indent=2), encoding="utf-8")
    os.replace(str(tmp), str(path))
