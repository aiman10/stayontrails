"""SAM auto-segmentation service.

Imports torch and segment_anything lazily so the rest of the app can run
without them installed. Top-level imports are limited to numpy and cv2.
"""
from __future__ import annotations

from pathlib import Path

import cv2
import numpy as np


CHECKPOINT_PATH = Path(__file__).resolve().parent / "models" / "sam" / "sam_vit_b_01ec64.pth"
MODEL_TYPE = "vit_b"
MIN_AREA = 50  # px^2 — reject masks that produce tiny contours


class MaskTooSmall(Exception):
    pass


def mask_to_polygon(mask: np.ndarray, max_vertices: int = 100) -> list[list[int]]:
    """Convert a binary mask to a simplified polygon.

    Largest external contour → Douglas-Peucker simplification at
    epsilon = max(1.5, 0.5%·perimeter) → cap at max_vertices → integer rounding.
    Raises MaskTooSmall if the largest contour has area < MIN_AREA.
    """
    if mask.dtype != np.uint8:
        mask = mask.astype(np.uint8)
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        raise MaskTooSmall("no contour found")
    contour = max(contours, key=cv2.contourArea)
    if cv2.contourArea(contour) < MIN_AREA:
        raise MaskTooSmall(f"contour area below {MIN_AREA}")
    eps = max(1.5, 0.005 * cv2.arcLength(contour, True))
    approx = cv2.approxPolyDP(contour, eps, True)
    pts = approx.reshape(-1, 2).tolist()
    if len(pts) > max_vertices:
        pts = pts[:max_vertices]
    return [[int(round(x)), int(round(y))] for x, y in pts]


_predictor = None
_cached_image_key: tuple | None = None
_device: str = "cpu"


def is_available() -> tuple[bool, str | None]:
    """Return (available, error_string).

    Available iff torch + segment_anything import and the checkpoint exists.
    Not memoized — installing deps mid-session is reflected immediately.
    """
    try:
        import torch  # noqa: F401
    except Exception as e:
        return False, f"torch not installed ({e.__class__.__name__})"
    try:
        import segment_anything  # noqa: F401
    except Exception as e:
        return False, f"segment_anything not installed ({e.__class__.__name__})"
    if not CHECKPOINT_PATH.is_file():
        return False, f"checkpoint missing at {CHECKPOINT_PATH}"
    return True, None


def _device_hint() -> str:
    try:
        import torch
        return "cuda" if torch.cuda.is_available() else "cpu"
    except Exception:
        return "cpu"


def status() -> dict:
    available, error = is_available()
    return {
        "available": available,
        "loaded": _predictor is not None,
        "device": _device if _predictor is not None else _device_hint(),
        "error": error,
    }


def _load_predictor():
    global _predictor, _device
    if _predictor is not None:
        return _predictor
    import torch
    from segment_anything import SamPredictor, sam_model_registry

    device = "cuda" if torch.cuda.is_available() else "cpu"
    sam = sam_model_registry[MODEL_TYPE](checkpoint=str(CHECKPOINT_PATH))
    sam.to(device=device)
    _predictor = SamPredictor(sam)
    _device = device
    return _predictor


def _set_image_cached(predictor, image_path: Path) -> None:
    global _cached_image_key
    mtime = image_path.stat().st_mtime
    key = (str(image_path), mtime)
    if _cached_image_key == key:
        return
    from PIL import Image
    img = np.array(Image.open(image_path).convert("RGB"))
    predictor.set_image(img)
    _cached_image_key = key


def predict_polygon(image_path: Path, point: tuple[int, int]) -> dict:
    """Run SAM at a single positive point. Returns {polygon, score}.

    Caller is responsible for path validation. Raises MaskTooSmall if the
    resulting mask is too small to form a usable polygon.
    """
    predictor = _load_predictor()
    _set_image_cached(predictor, image_path)
    pt = np.array([[int(point[0]), int(point[1])]], dtype=np.float32)
    labels = np.array([1], dtype=np.int32)
    masks, scores, _ = predictor.predict(
        point_coords=pt, point_labels=labels, multimask_output=True
    )
    best = int(np.argmax(scores))
    polygon = mask_to_polygon(masks[best].astype(np.uint8))
    return {"polygon": polygon, "score": float(scores[best])}
