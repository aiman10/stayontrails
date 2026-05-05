"""Shared pytest fixtures for route tests."""
import json
from pathlib import Path

import pytest


@pytest.fixture
def record_root(tmp_path: Path) -> Path:
    """A throwaway recorded_routes/ tree with one demo model and 2 frames."""
    model_dir = tmp_path / "demo"
    model_dir.mkdir()
    # Tiny placeholder JPEGs (real bytes aren't required for route tests).
    (model_dir / "frame_001.jpg").write_bytes(b"\xff\xd8\xff\xd9")
    (model_dir / "frame_002.jpg").write_bytes(b"\xff\xd8\xff\xd9")
    # A v1-shaped annotations.json with one polygon on frame_001.
    (model_dir / "annotations.json").write_text(json.dumps({
        "model": "demo",
        "classes": [{"id": 0, "name": "path"}],
        "images": [
            {
                "file": "frame_001.jpg", "width": 640, "height": 480,
                "status": "in-progress",
                "annotations": {"segments": [
                    {"classId": 0, "points": [{"x": 10, "y": 10}, {"x": 20, "y": 20}, {"x": 15, "y": 25}]}
                ]},
            }
        ],
    }))
    return tmp_path


@pytest.fixture
def client(record_root: Path):
    """Flask test client with RECORD_ROOT pointed at the fixture tree."""
    import config
    config.RECORD_ROOT = record_root
    from app import create_app
    app = create_app()
    app.config["TESTING"] = True
    with app.test_client() as c:
        yield c
