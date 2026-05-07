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
