import yaml
from pathlib import Path

import pytest

from dataset_export import (
    all_images_done,
    class_id_map,
    compute_splits,
    to_yolo_label_lines,
    write_dataset,
)


class TestComputeSplits:
    def test_empty(self):
        assert compute_splits([]) == {"train": [], "val": []}

    def test_single_image_goes_to_train(self):
        s = compute_splits(["a.jpg"])
        assert s["train"] == ["a.jpg"]
        assert s["val"] == []

    def test_two_images_one_each(self):
        s = compute_splits(["a.jpg", "b.jpg"])
        assert len(s["train"]) == 1
        assert len(s["val"]) == 1
        assert set(s["train"]) | set(s["val"]) == {"a.jpg", "b.jpg"}

    def test_ten_images_eighty_twenty(self):
        files = [f"img{i:02d}.jpg" for i in range(10)]
        s = compute_splits(files, val_frac=0.2)
        assert len(s["train"]) == 8
        assert len(s["val"]) == 2
        assert set(s["train"]).isdisjoint(set(s["val"]))

    def test_deterministic_with_seed(self):
        files = [f"img{i:02d}.jpg" for i in range(10)]
        a = compute_splits(files, seed=42)
        b = compute_splits(files, seed=42)
        assert a == b

    def test_different_seeds_can_differ(self):
        files = [f"img{i:02d}.jpg" for i in range(20)]
        a = compute_splits(files, seed=1)
        b = compute_splits(files, seed=999)
        assert a != b


class TestAllImagesDone:
    def test_empty_list(self):
        assert all_images_done([]) == (False, 0, 0)

    def test_all_done(self):
        imgs = [{"status": "done"}, {"status": "done"}]
        assert all_images_done(imgs) == (True, 2, 2)

    def test_one_in_progress(self):
        imgs = [{"status": "done"}, {"status": "in-progress"}]
        assert all_images_done(imgs) == (False, 1, 2)

    def test_unlabeled_blocks(self):
        imgs = [{"status": "done"}, {"status": "unlabeled"}]
        assert all_images_done(imgs) == (False, 1, 2)


class TestYoloLabelLines:
    def setup_method(self):
        self.classes = [
            {"id": 0, "name": "path-oxod"},
            {"id": 1, "name": "grass"},
        ]
        self.cmap = class_id_map(self.classes)

    def test_polygon_normalized(self):
        img = {
            "file": "f.jpg",
            "annotations": {
                "segments": [
                    {"classId": 0, "points": [
                        {"x": 0, "y": 0},
                        {"x": 320, "y": 0},
                        {"x": 320, "y": 240},
                    ]},
                ],
                "boxes": [],
            },
        }
        lines = to_yolo_label_lines(img, self.cmap, img_w=640, img_h=480)
        assert len(lines) == 1
        parts = lines[0].split()
        assert parts[0] == "0"  # class index
        # 3 vertices = 6 coords + 1 class id
        assert len(parts) == 7
        # First vertex (0, 0) → (0.0, 0.0)
        assert parts[1] == "0.000000" and parts[2] == "0.000000"
        # Second vertex (320, 0) → (0.5, 0.0)
        assert parts[3] == "0.500000" and parts[4] == "0.000000"

    def test_box_becomes_4_vertex_polygon(self):
        img = {
            "file": "f.jpg",
            "annotations": {
                "segments": [],
                "boxes": [{"classId": 1, "x": 0, "y": 0, "w": 640, "h": 480}],
            },
        }
        lines = to_yolo_label_lines(img, self.cmap, img_w=640, img_h=480)
        assert len(lines) == 1
        parts = lines[0].split()
        assert parts[0] == "1"
        assert len(parts) == 9  # class + 4 (x, y) pairs
        # Full-image box → corners (0,0), (1,0), (1,1), (0,1)
        coords = [float(p) for p in parts[1:]]
        assert coords == [0, 0, 1, 0, 1, 1, 0, 1]

    def test_unknown_class_skipped(self):
        img = {
            "file": "f.jpg",
            "annotations": {
                "segments": [{"classId": 99, "points": [{"x": 0, "y": 0}, {"x": 1, "y": 1}, {"x": 2, "y": 2}]}],
                "boxes": [],
            },
        }
        lines = to_yolo_label_lines(img, self.cmap, img_w=640, img_h=480)
        assert lines == []

    def test_short_polygon_skipped(self):
        img = {
            "file": "f.jpg",
            "annotations": {
                "segments": [{"classId": 0, "points": [{"x": 0, "y": 0}, {"x": 1, "y": 1}]}],
                "boxes": [],
            },
        }
        assert to_yolo_label_lines(img, self.cmap) == []


class TestWriteDataset:
    def test_writes_layout(self, tmp_path: Path):
        model_dir = tmp_path / "model"
        model_dir.mkdir()
        # Two real-ish jpg files (just enough to exist).
        (model_dir / "a.jpg").write_bytes(b"\xff\xd8\xff\xd9")
        (model_dir / "b.jpg").write_bytes(b"\xff\xd8\xff\xd9")

        classes = [
            {"id": 0, "name": "path-oxod", "color": "#fff"},
            {"id": 1, "name": "grass", "color": "#0f0"},
        ]
        images = [
            {
                "file": "a.jpg", "status": "done",
                "annotations": {
                    "segments": [{"classId": 0, "points": [
                        {"x": 0, "y": 0}, {"x": 320, "y": 0}, {"x": 320, "y": 240},
                    ]}],
                    "boxes": [],
                },
            },
            {
                "file": "b.jpg", "status": "done",
                "annotations": {
                    "segments": [],
                    "boxes": [{"classId": 1, "x": 10, "y": 10, "w": 100, "h": 80}],
                },
            },
        ]
        splits = {"train": ["a.jpg"], "val": ["b.jpg"]}

        dataset_dir = tmp_path / "ds"
        yaml_path = write_dataset(
            model_dir=model_dir,
            dataset_dir=dataset_dir,
            classes=classes,
            images=images,
            splits=splits,
        )

        assert (dataset_dir / "images" / "train" / "a.jpg").is_file()
        assert (dataset_dir / "images" / "val" / "b.jpg").is_file()
        assert (dataset_dir / "labels" / "train" / "a.txt").is_file()
        assert (dataset_dir / "labels" / "val" / "b.txt").is_file()

        # data.yaml
        assert yaml_path == dataset_dir / "data.yaml"
        cfg = yaml.safe_load(yaml_path.read_text())
        assert cfg["names"] == {0: "path-oxod", 1: "grass"}
        assert cfg["train"] == "images/train"
        assert cfg["val"] == "images/val"

        # Labels content
        a_label = (dataset_dir / "labels" / "train" / "a.txt").read_text().strip()
        assert a_label.startswith("0 ")  # class 0 polygon
        b_label = (dataset_dir / "labels" / "val" / "b.txt").read_text().strip()
        assert b_label.startswith("1 ")  # class 1 box-as-polygon

    def test_overwrites_existing(self, tmp_path: Path):
        model_dir = tmp_path / "m"
        model_dir.mkdir()
        (model_dir / "a.jpg").write_bytes(b"\xff\xd8\xff\xd9")

        dataset_dir = tmp_path / "ds"
        # Pre-populate with a stale file.
        dataset_dir.mkdir()
        (dataset_dir / "stale.txt").write_text("delete me")

        write_dataset(
            model_dir=model_dir,
            dataset_dir=dataset_dir,
            classes=[{"id": 0, "name": "x"}],
            images=[{"file": "a.jpg", "status": "done", "annotations": {"segments": [], "boxes": []}}],
            splits={"train": ["a.jpg"], "val": []},
        )
        assert not (dataset_dir / "stale.txt").exists()
