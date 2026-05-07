"""Export annotations.json into a YOLO-format dataset on disk.

Pure functions that handle:
- Computing reproducible train/val splits.
- Converting per-image annotations (polygons + boxes) into YOLO segmentation
  label lines (normalized polygon vertices).
- Writing the standard YOLO directory layout + data.yaml.

YOLO segmentation label format:
  <class_id> <x1> <y1> <x2> <y2> ... <xN> <yN>
where coordinates are floats normalized to [0, 1].

We emit segmentation labels even for box annotations: a box becomes a
4-vertex polygon (top-left, top-right, bottom-right, bottom-left). This
keeps downstream training simple — one model handles both.
"""
from __future__ import annotations

import random
import shutil
import yaml
from pathlib import Path
from typing import Any, Iterable


def compute_splits(
    filenames: list[str],
    val_frac: float = 0.2,
    seed: int = 42,
) -> dict[str, list[str]]:
    """Deterministic shuffle + split.

    Returns {'train': [...], 'val': [...]}. With a tiny dataset (< 5 images)
    val gets at least 1 image as long as there are >= 2 images.
    """
    if not filenames:
        return {"train": [], "val": []}
    rng = random.Random(seed)
    files = sorted(filenames)
    rng.shuffle(files)
    n_val = max(1, int(round(len(files) * val_frac))) if len(files) >= 2 else 0
    val = files[:n_val]
    train = files[n_val:]
    return {"train": sorted(train), "val": sorted(val)}


def _box_to_polygon(box: dict, img_w: int, img_h: int) -> list[float]:
    """Convert a box dict {x, y, w, h} (image px) to a normalized 4-vertex
    polygon flat list [x1, y1, x2, y2, x3, y3, x4, y4]."""
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    return [
        x / img_w, y / img_h,
        (x + w) / img_w, y / img_h,
        (x + w) / img_w, (y + h) / img_h,
        x / img_w, (y + h) / img_h,
    ]


def _polygon_to_normalized(points: list[dict], img_w: int, img_h: int) -> list[float]:
    """Convert a list of {x, y} dicts to a flat normalized list."""
    out: list[float] = []
    for p in points:
        out.append(p["x"] / img_w)
        out.append(p["y"] / img_h)
    return out


def to_yolo_label_lines(
    image: dict,
    class_id_map: dict[int, int],
    img_w: int = 640,
    img_h: int = 480,
) -> list[str]:
    """Build YOLO segmentation label lines for one image entry.

    `class_id_map` maps the annotation's classId to the YOLO 0-based class
    index (which is the position of the class in classes.json order).
    Annotations whose classId isn't in the map are skipped.
    """
    ann = image.get("annotations") or {}
    lines: list[str] = []
    for seg in ann.get("segments", []):
        cid = class_id_map.get(seg.get("classId"))
        if cid is None:
            continue
        pts = seg.get("points") or []
        if len(pts) < 3:
            continue
        flat = _polygon_to_normalized(pts, img_w, img_h)
        lines.append(_format_line(cid, flat))
    for box in ann.get("boxes", []):
        cid = class_id_map.get(box.get("classId"))
        if cid is None:
            continue
        flat = _box_to_polygon(box, img_w, img_h)
        lines.append(_format_line(cid, flat))
    return lines


def _format_line(class_id: int, flat: list[float]) -> str:
    parts = [str(class_id)]
    for v in flat:
        v = max(0.0, min(1.0, v))
        parts.append(f"{v:.6f}")
    return " ".join(parts)


def class_id_map(classes: list[dict]) -> dict[int, int]:
    """Map annotation classId -> 0-based YOLO index, in declared order."""
    return {c["id"]: i for i, c in enumerate(classes)}


def write_dataset(
    *,
    model_dir: Path,
    dataset_dir: Path,
    classes: list[dict],
    images: list[dict],
    splits: dict[str, list[str]],
    img_w: int = 640,
    img_h: int = 480,
) -> Path:
    """Materialize the YOLO dataset directory and return the data.yaml path.

    Layout:
      <dataset_dir>/
        images/train/<filename>.jpg
        images/val/<filename>.jpg
        labels/train/<basename>.txt
        labels/val/<basename>.txt
        data.yaml

    Image files are copied (not symlinked) for Windows compatibility.
    Existing dataset_dir is removed first so re-runs are deterministic.
    """
    if dataset_dir.exists():
        shutil.rmtree(dataset_dir)
    for sub in ("images/train", "images/val", "labels/train", "labels/val"):
        (dataset_dir / sub).mkdir(parents=True, exist_ok=True)

    by_file = {img["file"]: img for img in images}
    cmap = class_id_map(classes)

    for split_name in ("train", "val"):
        for fname in splits.get(split_name, []):
            src = model_dir / fname
            if not src.is_file():
                continue
            shutil.copy2(src, dataset_dir / "images" / split_name / fname)
            label_lines = to_yolo_label_lines(
                by_file.get(fname, {"file": fname}),
                cmap,
                img_w=img_w,
                img_h=img_h,
            )
            label_path = dataset_dir / "labels" / split_name / (Path(fname).stem + ".txt")
            label_path.write_text("\n".join(label_lines), encoding="utf-8")

    data_yaml = dataset_dir / "data.yaml"
    data = {
        "path": str(dataset_dir.resolve()),
        "train": "images/train",
        "val": "images/val",
        "names": {i: c["name"] for i, c in enumerate(classes)},
    }
    data_yaml.write_text(yaml.safe_dump(data, sort_keys=False), encoding="utf-8")
    return data_yaml


def all_images_done(images: Iterable[dict]) -> tuple[bool, int, int]:
    """Return (all_done, done_count, total). Empty list → (False, 0, 0)."""
    total = 0
    done = 0
    for img in images:
        total += 1
        if img.get("status") == "done":
            done += 1
    return (total > 0 and done == total, done, total)
