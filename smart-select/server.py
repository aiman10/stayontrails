"""Smart Select inference sidecar for the Stay On Trails PHP annotator.

Standalone Flask service that runs YOLO segmentation models and returns polygon
suggestions. The PHP annotator (annotate.php) proxies smart-select requests to
this service; it never talks to the browser directly.

This service only does inference. It has no annotation storage and no knowledge
of the annotation schema. Models load from ../models/*.pt. Detected masks are
cached in memory per (image_path, model) so repeated clicks on the same image
skip re-inference.

Run:  py -3 server.py     (or use run.bat)
Env:  MODELS_DIR, SMART_PORT (default 5002)
"""
from __future__ import annotations

import os
import threading
from collections import OrderedDict
from pathlib import Path

import cv2
import numpy as np
from flask import Flask, jsonify, request
from ultralytics import YOLO

HERE = Path(__file__).resolve().parent
MODELS_DIR = Path(os.environ.get("MODELS_DIR", str(HERE.parent / "models"))).resolve()
PORT = int(os.environ.get("SMART_PORT", "5002"))
MAX_CACHED_IMAGES = 5

app = Flask(__name__)

# YOLO inference is not thread-safe; serialize all model calls.
_lock = threading.Lock()
_models: dict[str, YOLO] = {}
# (image_path, model_name) -> list[{mask, class_id, class_name, confidence}]
_mask_cache: "OrderedDict[tuple, list]" = OrderedDict()


def list_models() -> list[str]:
    if not MODELS_DIR.is_dir():
        return []
    return sorted(p.name for p in MODELS_DIR.glob("*.pt"))


def get_model(name: str) -> YOLO:
    if name not in _models:
        path = MODELS_DIR / name
        if not path.is_file():
            raise FileNotFoundError(f"Model '{name}' not found in {MODELS_DIR}")
        _models[name] = YOLO(str(path))
    return _models[name]


def run_detect(image_path: str, model_name: str, conf: float, img_w: int, img_h: int) -> list:
    """Run YOLO once and cache every segmentation mask, resized to img_w x img_h."""
    key = (image_path, model_name)
    if key in _mask_cache:
        _mask_cache.move_to_end(key)
        return _mask_cache[key]

    model = get_model(model_name)
    results = model(image_path, conf=conf, verbose=False)

    masks: list[dict] = []
    for r in results:
        if r.masks is None:
            continue
        for i, mask_tensor in enumerate(r.masks.data):
            m = (mask_tensor.cpu().numpy() * 255).astype(np.uint8)
            m = cv2.resize(m, (img_w, img_h), interpolation=cv2.INTER_NEAREST)
            cid = int(r.boxes.cls[i].item())
            masks.append({
                "mask": m,
                "class_id": cid,
                "class_name": model.names[cid],
                "confidence": float(r.boxes.conf[i].item()),
            })

    _mask_cache[key] = masks
    _mask_cache.move_to_end(key)
    while len(_mask_cache) > MAX_CACHED_IMAGES:
        _mask_cache.popitem(last=False)
    return masks


def mask_to_polygon(mask: np.ndarray, epsilon_ratio: float) -> list:
    """Largest external contour of a binary mask, simplified with approxPolyDP."""
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return []
    contour = max(contours, key=cv2.contourArea)
    arc = cv2.arcLength(contour, True)
    approx = cv2.approxPolyDP(contour, max(1.0, epsilon_ratio * arc), True)
    return [[int(p[0][0]), int(p[0][1])] for p in approx]


def combine(masks: list, selected: list[int], epsilon_ratio: float):
    """OR together the selected masks, return (polygon, dominant_class_dict)."""
    selected = [i for i in selected if 0 <= i < len(masks)]
    if not selected:
        return [], None
    h, w = masks[selected[0]]["mask"].shape
    acc = np.zeros((h, w), dtype=np.uint8)
    for i in selected:
        acc = cv2.bitwise_or(acc, masks[i]["mask"])
    polygon = mask_to_polygon(acc, epsilon_ratio)
    # Dominant class = the largest selected mask.
    dominant = max(selected, key=lambda i: int(np.count_nonzero(masks[i]["mask"])))
    return polygon, masks[dominant]


def _params():
    body = request.get_json(force=True, silent=True) or {}
    image_path = str(body.get("image_path", ""))
    model_name = str(body.get("model", ""))
    conf = float(body.get("confidence", 0.5))
    img_w = int(body.get("img_w", 640))
    img_h = int(body.get("img_h", 480))
    return body, image_path, model_name, conf, img_w, img_h


@app.get("/api/health")
def health():
    return jsonify({"ok": True, "models": list_models()})


@app.get("/api/models")
def models():
    return jsonify({"ok": True, "models": list_models()})


@app.post("/api/smart_select/detect")
def detect():
    body, image_path, model_name, conf, img_w, img_h = _params()
    if not image_path or not os.path.isfile(image_path):
        return jsonify({"ok": False, "error": "image not found"}), 404
    if model_name not in list_models():
        return jsonify({"ok": False, "error": f"model '{model_name}' not available"}), 404
    try:
        with _lock:
            masks = run_detect(image_path, model_name, conf, img_w, img_h)
    except Exception as e:
        return jsonify({"ok": False, "error": f"{e.__class__.__name__}: {e}"}), 500
    return jsonify({
        "ok": True,
        "num_detections": len(masks),
        "classes_found": [m["class_name"] for m in masks],
    })


@app.post("/api/smart_select/pick")
def pick():
    body, image_path, model_name, conf, img_w, img_h = _params()
    point = body.get("point")
    epsilon = float(body.get("epsilon_ratio", 0.003))
    selected = list(body.get("selected_indices") or [])
    if not (isinstance(point, list) and len(point) == 2):
        return jsonify({"ok": False, "error": "point must be [x, y]"}), 400
    if not image_path or not os.path.isfile(image_path):
        return jsonify({"ok": False, "error": "image not found"}), 404
    try:
        with _lock:
            masks = run_detect(image_path, model_name, conf, img_w, img_h)
    except Exception as e:
        return jsonify({"ok": False, "error": f"{e.__class__.__name__}: {e}"}), 500
    if not masks:
        return jsonify({"ok": True, "polygon": None, "message": "no detections on this image"})

    x, y = int(point[0]), int(point[1])
    hits = [
        i for i, m in enumerate(masks)
        if 0 <= y < m["mask"].shape[0] and 0 <= x < m["mask"].shape[1] and m["mask"][y, x] > 0
    ]
    if not hits:
        return jsonify({"ok": True, "polygon": None, "message": "no detection at this point",
                        "selected_indices": selected})

    # Smallest mask containing the click = most specific region.
    hit = min(hits, key=lambda i: int(np.count_nonzero(masks[i]["mask"])))
    if hit in selected:
        selected.remove(hit)   # clicking an already-selected region removes it
    else:
        selected.append(hit)   # otherwise add it (union)

    polygon, cls = combine(masks, selected, epsilon)
    return jsonify({
        "ok": True,
        "polygon": polygon or None,
        "selected_indices": selected,
        "class_name": cls["class_name"] if cls else None,
        "class_id": cls["class_id"] if cls else None,
        "confidence": cls["confidence"] if cls else None,
        "num_vertices": len(polygon),
    })


@app.post("/api/smart_select/simplify")
def simplify():
    body, image_path, model_name, conf, img_w, img_h = _params()
    epsilon = float(body.get("epsilon_ratio", 0.003))
    selected = list(body.get("selected_indices") or [])
    if not image_path or not os.path.isfile(image_path):
        return jsonify({"ok": False, "error": "image not found"}), 404
    try:
        with _lock:
            masks = run_detect(image_path, model_name, conf, img_w, img_h)
    except Exception as e:
        return jsonify({"ok": False, "error": f"{e.__class__.__name__}: {e}"}), 500
    polygon, cls = combine(masks, selected, epsilon)
    return jsonify({
        "ok": True,
        "polygon": polygon or None,
        "selected_indices": selected,
        "class_name": cls["class_name"] if cls else None,
        "class_id": cls["class_id"] if cls else None,
        "num_vertices": len(polygon),
    })


if __name__ == "__main__":
    print(f"Smart Select sidecar on http://127.0.0.1:{PORT}  models_dir={MODELS_DIR}")
    print(f"Models found: {list_models() or '(none — drop a .pt into models/)'}")
    app.run(host="127.0.0.1", port=PORT, debug=False, threaded=True)
