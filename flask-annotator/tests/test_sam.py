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


def test_status_endpoint_shape(client):
    r = client.get("/api/sam/status")
    assert r.status_code == 200
    body = r.get_json()
    assert body["ok"] is True
    assert "available" in body
    assert "loaded" in body
    assert "device" in body


def test_predict_rejects_bad_body(client):
    r = client.post("/api/sam/predict", json="not a dict")
    assert r.status_code == 400


def test_predict_rejects_bad_model(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "", "image": "frame_001.jpg", "point": [10, 10]},
    )
    assert r.status_code == 400


def test_predict_rejects_unsafe_filename(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "../etc/passwd", "point": [10, 10]},
    )
    assert r.status_code == 400


def test_predict_rejects_point_out_of_bounds(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame_001.jpg", "point": [700, 10]},
    )
    assert r.status_code == 400


def test_predict_404_unknown_model(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "no-such-model", "image": "frame_001.jpg", "point": [10, 10]},
    )
    assert r.status_code == 404


def test_predict_404_unknown_image(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame_999.jpg", "point": [10, 10]},
    )
    assert r.status_code == 404


def test_predict_503_when_unavailable(client, monkeypatch):
    monkeypatch.setattr(sam_service, "is_available", lambda: (False, "torch not installed"))
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame_001.jpg", "point": [10, 10]},
    )
    assert r.status_code == 503
    assert r.get_json()["error"] == "torch not installed"


def test_predict_returns_polygon_when_mocked(client, monkeypatch):
    monkeypatch.setattr(sam_service, "is_available", lambda: (True, None))
    monkeypatch.setattr(
        sam_service,
        "predict_polygon",
        lambda path, pt: {"polygon": [[10, 10], [30, 10], [30, 30], [10, 30]], "score": 0.9},
    )
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame_001.jpg", "point": [20, 20]},
    )
    assert r.status_code == 200
    body = r.get_json()
    assert body["ok"] is True
    assert body["polygon"] == [[10, 10], [30, 10], [30, 30], [10, 30]]
    assert body["score"] == 0.9
