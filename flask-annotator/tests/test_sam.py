import numpy as np
import pytest

import sam_service
from sam_service import MaskTooSmall, mask_to_polygon


def test_mask_to_polygon_simplifies_square():
    mask = np.zeros((200, 200), dtype=np.uint8)
    mask[50:150, 50:150] = 1
    poly = mask_to_polygon(mask)
    assert 3 <= len(poly) <= 8  # square simplifies to ~4 vertices, allow some slack
    xs = [p[0] for p in poly]
    ys = [p[1] for p in poly]
    assert min(xs) >= 49 and max(xs) <= 150
    assert min(ys) >= 49 and max(ys) <= 150


def test_mask_to_polygon_rejects_tiny():
    mask = np.zeros((50, 50), dtype=np.uint8)
    mask[0:3, 0:3] = 1
    with pytest.raises(MaskTooSmall):
        mask_to_polygon(mask)


def test_mask_to_polygon_caps_vertices():
    mask = np.zeros((300, 300), dtype=np.uint8)
    cy, cx, r = 150, 150, 100
    Y, X = np.ogrid[:300, :300]
    mask[((X - cx) ** 2 + (Y - cy) ** 2) <= r * r] = 1
    poly = mask_to_polygon(mask, max_vertices=50)
    assert len(poly) <= 50
    assert len(poly) >= 6


def test_is_available_returns_tuple():
    available, error = sam_service.is_available()
    assert isinstance(available, bool)
    assert error is None or isinstance(error, str)


def test_status_shape():
    s = sam_service.status()
    assert set(s.keys()) == {"available", "loaded", "device", "error"}
    assert isinstance(s["available"], bool)
    assert isinstance(s["loaded"], bool)
    assert isinstance(s["device"], str)
