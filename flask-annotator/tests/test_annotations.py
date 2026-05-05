import json
from pathlib import Path

import pytest

from annotations import (
    DEFAULT_PALETTE,
    default_scaffold,
    load_annotations,
    normalize,
    SCHEMA_VERSION,
)


def _v1_fixture() -> dict:
    """Returns a minimal v1-shaped annotations.json (no schemaVersion, no boxes, no colors)."""
    return {
        "model": "demo",
        "classes": [{"id": 0, "name": "path"}],
        "images": [
            {
                "file": "frame_001.jpg",
                "width": 640,
                "height": 480,
                "status": "in-progress",
                "annotations": {
                    "segments": [
                        {"classId": 0, "points": [{"x": 10, "y": 10}, {"x": 20, "y": 20}, {"x": 15, "y": 25}]}
                    ]
                },
            }
        ],
    }


class TestNormalize:
    def test_v1_gets_schema_version(self):
        out = normalize(_v1_fixture())
        assert out["schemaVersion"] == SCHEMA_VERSION

    def test_v1_classes_get_default_colors(self):
        out = normalize(_v1_fixture())
        assert out["classes"][0]["color"] == DEFAULT_PALETTE[0]

    def test_v1_images_get_empty_boxes(self):
        out = normalize(_v1_fixture())
        assert out["images"][0]["annotations"]["boxes"] == []

    def test_existing_v2_unchanged(self):
        v2 = {
            "model": "demo",
            "schemaVersion": 2,
            "classes": [{"id": 0, "name": "path", "color": "#abcdef"}],
            "images": [],
        }
        out = normalize(v2)
        assert out["classes"][0]["color"] == "#abcdef"
        assert out["schemaVersion"] == 2

    def test_color_palette_cycles_after_six(self):
        v1 = _v1_fixture()
        v1["classes"] = [{"id": i, "name": f"c{i}"} for i in range(8)]
        out = normalize(v1)
        colors = [c["color"] for c in out["classes"]]
        assert colors[0] == DEFAULT_PALETTE[0]
        assert colors[6] == DEFAULT_PALETTE[0]  # cycles
        assert colors[7] == DEFAULT_PALETTE[1]


class TestDefaultScaffold:
    def test_has_path_and_path_oxod_classes(self):
        s = default_scaffold("demo")
        names = [c["name"] for c in s["classes"]]
        assert names == ["path", "path-oxod"]

    def test_has_v2_schema_version(self):
        s = default_scaffold("demo")
        assert s["schemaVersion"] == SCHEMA_VERSION

    def test_uses_model_name(self):
        s = default_scaffold("foo-bar")
        assert s["model"] == "foo-bar"

    def test_images_starts_empty(self):
        s = default_scaffold("demo")
        assert s["images"] == []


class TestLoadAnnotations:
    def test_missing_file_returns_default(self, tmp_path: Path):
        out = load_annotations(tmp_path, "demo")
        assert out["data"]["model"] == "demo"
        assert out["status"] == "missing"

    def test_v1_file_loaded_and_normalized_in_memory(self, tmp_path: Path):
        path = tmp_path / "annotations.json"
        path.write_text(json.dumps(_v1_fixture()))
        out = load_annotations(tmp_path, "demo")
        assert out["status"] == "ok"
        assert out["data"]["schemaVersion"] == SCHEMA_VERSION
        assert out["data"]["classes"][0]["color"] == DEFAULT_PALETTE[0]
        # File on disk is unchanged.
        on_disk = json.loads(path.read_text())
        assert "schemaVersion" not in on_disk

    def test_corrupt_file_returns_default_and_marks_corrupt(self, tmp_path: Path):
        (tmp_path / "annotations.json").write_text("{ this is not json")
        out = load_annotations(tmp_path, "demo")
        assert out["status"] == "corrupt"
        assert out["data"]["model"] == "demo"  # default scaffold

    def test_future_schema_marked_readonly(self, tmp_path: Path):
        future = {"model": "demo", "schemaVersion": 99, "classes": [], "images": []}
        (tmp_path / "annotations.json").write_text(json.dumps(future))
        out = load_annotations(tmp_path, "demo")
        assert out["status"] == "future"


class TestSaveAnnotations:
    def test_writes_file_atomically(self, tmp_path: Path):
        from annotations import save_annotations
        data = {"model": "demo", "schemaVersion": 2, "classes": [], "images": []}
        save_annotations(tmp_path, data)
        assert (tmp_path / "annotations.json").is_file()
        on_disk = json.loads((tmp_path / "annotations.json").read_text())
        assert on_disk["schemaVersion"] == 2

    def test_no_temp_files_left_behind(self, tmp_path: Path):
        from annotations import save_annotations
        save_annotations(tmp_path, {"model": "demo", "schemaVersion": 2, "classes": [], "images": []})
        leftover = list(tmp_path.glob("annotations.json.tmp.*"))
        assert leftover == []

    def test_save_rotates_corrupt_file_aside(self, tmp_path: Path):
        from annotations import save_annotations
        path = tmp_path / "annotations.json"
        path.write_text("{ not valid json")
        save_annotations(tmp_path, {"model": "demo", "schemaVersion": 2, "classes": [], "images": []})
        # Bad file moved aside.
        rotated = list(tmp_path.glob("annotations.json.broken-*"))
        assert len(rotated) == 1
        # New file written cleanly.
        on_disk = json.loads(path.read_text())
        assert on_disk["schemaVersion"] == 2

    def test_save_does_not_rotate_valid_file(self, tmp_path: Path):
        from annotations import save_annotations
        path = tmp_path / "annotations.json"
        existing = {"model": "demo", "schemaVersion": 1, "classes": [], "images": []}
        path.write_text(json.dumps(existing))
        save_annotations(tmp_path, {"model": "demo", "schemaVersion": 2, "classes": [], "images": []})
        rotated = list(tmp_path.glob("annotations.json.broken-*"))
        assert rotated == []
