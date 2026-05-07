"""Subprocess runner for a single training run.

Invoked as:
    python scripts/train_runner.py <run_dir>

Reads <run_dir>/state.json, runs Ultralytics training, writes progress
back into state.json after each epoch. On success copies best.pt out of
Ultralytics' working directory into <run_dir>/best.pt.
"""
from __future__ import annotations

import json
import os
import shutil
import sys
import time
import traceback
from pathlib import Path


def _save(path: Path, state: dict) -> None:
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(state, indent=2), encoding="utf-8")
    os.replace(str(tmp), str(path))


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: train_runner.py <run_dir>")
        return 2
    run_dir = Path(sys.argv[1]).resolve()
    state_path = run_dir / "state.json"
    state = json.loads(state_path.read_text(encoding="utf-8"))

    state["status"] = "running"
    _save(state_path, state)

    try:
        from ultralytics import YOLO

        model = YOLO(state["model_size"])

        def on_epoch_end(trainer):
            state["epoch_current"] = int(getattr(trainer, "epoch", 0)) + 1
            _save(state_path, state)

        model.add_callback("on_train_epoch_end", on_epoch_end)

        model.train(
            data=state["data_yaml"],
            epochs=int(state["epochs"]),
            imgsz=640,
            batch=8,
            workers=0,                 # Windows-safe.
            project=str(run_dir),
            name="ultralytics",
            exist_ok=True,
            verbose=True,
        )

        # Locate best.pt in Ultralytics' output and copy to a stable path.
        best_src = run_dir / "ultralytics" / "weights" / "best.pt"
        if best_src.is_file():
            shutil.copy2(best_src, run_dir / "best.pt")
            state["best_pt"] = "best.pt"

        state["status"] = "done"
    except Exception as e:
        state["status"] = "failed"
        state["error"] = f"{e.__class__.__name__}: {e}"
        with (run_dir / "train.log").open("a", encoding="utf-8") as f:
            f.write("\n--- runner traceback ---\n")
            f.write(traceback.format_exc())

    state["ended_at"] = time.time()
    _save(state_path, state)
    return 0 if state["status"] == "done" else 1


if __name__ == "__main__":
    sys.exit(main())
