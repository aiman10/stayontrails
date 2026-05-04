# Flask Annotator (Phase 1+2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `annotate.php` with a Python Flask + Konva.js annotation tool that reads and writes the existing `recorded_routes/<model>/annotations.json`, adds multi-class and bounding-box support, and ports the existing PHP UI verbatim.

**Architecture:** Flask app on `127.0.0.1:5001`, separate process from XAMPP/Apache (which keeps serving PHP on port 80). Reads images directly from `c:\xampp\htdocs\stayontrails\recorded_routes\` — no copies. Annotations stored in `recorded_routes/<model>/annotations.json` (extended schema v2, backwards-compatible with v1). The existing `annotate.php` becomes a 3-line redirect.

**Tech Stack:** Python 3.10+, Flask, pytest. Frontend: Konva.js loaded from CDN, vanilla JS in a single file, no build step.

**Spec:** [docs/superpowers/specs/2026-05-04-flask-annotator-design.md](../specs/2026-05-04-flask-annotator-design.md)

---

## File map

**New files:**
- `flask-annotator/app.py` — Flask app, all HTTP routes
- `flask-annotator/config.py` — `RECORD_ROOT`, `PORT`, `DEBUG` constants
- `flask-annotator/annotations.py` — load/save/normalize/migrate v1→v2
- `flask-annotator/slug.py` — `slugify()` + path-traversal guards
- `flask-annotator/requirements.txt` — `flask`
- `flask-annotator/run.bat` — Windows launcher
- `flask-annotator/README.md` — how to run + manual smoke checklist
- `flask-annotator/tests/conftest.py` — pytest fixtures (tmp `recorded_routes`)
- `flask-annotator/tests/test_slug.py`
- `flask-annotator/tests/test_annotations.py`
- `flask-annotator/tests/test_routes.py`
- `flask-annotator/templates/index.html` — main page (Jinja2, ported from `annotate.php`)
- `flask-annotator/templates/picker.html` — model picker (no `?model=` given)
- `flask-annotator/static/css/style.css` — extracted from `annotate.php` `<style>` block + new styles
- `flask-annotator/static/js/annotator.js` — Konva-based UI, ~600–800 lines

**Modified files:**
- `annotate.php` — becomes 3-line redirect to `localhost:5001`

**Empty placeholder folders (no files yet):**
- `flask-annotator/models/` (for SAM checkpoints, future phase)
- `flask-annotator/runs/` (for training output, future phase)
- `flask-annotator/scripts/` (for `train.py`, `convert_to_yolo.py`, future phase)

---

## Conventions used in this plan

- **Working directory:** `c:\xampp\htdocs\stayontrails` (the repo root). All paths in commands are relative to it unless absolute.
- **Python:** assume `python` resolves to Python 3.10+. If a system has both, use `py -3` instead.
- **Tests:** run with `pytest flask-annotator/tests -v` from the repo root.
- **Commits:** small and frequent, following the format `<type>: <short description>`.
- **Git Bash on Windows:** all commands use Unix path separators. Forward slashes work in Python paths and in `git`.

---

## Task 1: Scaffold the project skeleton

**Files:**
- Create: `flask-annotator/requirements.txt`
- Create: `flask-annotator/config.py`
- Create: `flask-annotator/run.bat`
- Create: `flask-annotator/README.md`
- Create: `flask-annotator/.gitignore`
- Create: `flask-annotator/models/.gitkeep`
- Create: `flask-annotator/runs/.gitkeep`
- Create: `flask-annotator/scripts/.gitkeep`

- [ ] **Step 1: Create `requirements.txt`**

```
flask==3.0.3
pytest==8.3.3
```

- [ ] **Step 2: Create `config.py`**

```python
"""Runtime configuration for the Flask annotator.

Values can be overridden by environment variables of the same name.
"""
from __future__ import annotations

import os
from pathlib import Path

# Root of the captures tree shared with recordroute.php and annotate.php.
# By default we resolve the sibling `recorded_routes/` next to this package.
_DEFAULT_ROOT = Path(__file__).resolve().parent.parent / "recorded_routes"

RECORD_ROOT: Path = Path(os.environ.get("RECORD_ROOT", str(_DEFAULT_ROOT))).resolve()
PORT: int = int(os.environ.get("PORT", "5001"))
DEBUG: bool = os.environ.get("DEBUG", "1") == "1"
```

- [ ] **Step 3: Create `run.bat`**

```bat
@echo off
cd /d %~dp0
python app.py
```

- [ ] **Step 4: Create `.gitignore`**

```
__pycache__/
*.pyc
.pytest_cache/
.venv/
venv/
runs/*
!runs/.gitkeep
models/*
!models/.gitkeep
```

- [ ] **Step 5: Create `README.md`**

```markdown
# Flask Annotator

Replacement for `annotate.php`. Multi-class polygon and bounding-box annotation,
reading and writing `recorded_routes/<model>/annotations.json` directly.

## Run

```bash
cd flask-annotator
pip install -r requirements.txt
python app.py
```

Then open http://localhost:5001/?model=<your-model-slug>.

## Test

```bash
pytest flask-annotator/tests -v
```
```

- [ ] **Step 6: Create the `.gitkeep` files**

```bash
mkdir -p flask-annotator/tests flask-annotator/models flask-annotator/runs flask-annotator/scripts
touch flask-annotator/models/.gitkeep
touch flask-annotator/runs/.gitkeep
touch flask-annotator/scripts/.gitkeep
```

(Do **not** create a `tests/__init__.py` — leaving `tests/` as a non-package keeps pytest's default rootdir auto-insertion working without import conflicts.)

- [ ] **Step 7: Verify the structure**

Run: `ls flask-annotator/`
Expected: `README.md  config.py  models  requirements.txt  run.bat  runs  scripts  tests`

- [ ] **Step 8: Install dependencies**

Run: `pip install -r flask-annotator/requirements.txt`
Expected: flask and pytest installed without error.

- [ ] **Step 9: Commit**

```bash
git add flask-annotator/
git commit -m "scaffold: flask-annotator project skeleton"
```

---

## Task 2: Slug + path-traversal guards (TDD)

**Files:**
- Create: `flask-annotator/slug.py`
- Create: `flask-annotator/tests/test_slug.py`

- [ ] **Step 1: Write the failing tests**

Create `flask-annotator/tests/test_slug.py`:

```python
import pytest

from flask_annotator.slug import slugify, is_safe_filename


class TestSlugify:
    def test_lowercases(self):
        assert slugify("My Trail") == "my-trail"

    def test_strips_punctuation(self):
        assert slugify("My Trail!") == "my-trail"

    def test_collapses_whitespace_and_underscores(self):
        assert slugify("foo   bar__baz") == "foo-bar-baz"

    def test_rejects_path_traversal(self):
        assert slugify("../etc/passwd") == "etc-passwd"

    def test_strips_leading_trailing_hyphens(self):
        assert slugify("---path---") == "path"

    def test_empty(self):
        assert slugify("") == ""

    def test_unicode_letters_dropped(self):
        # We only keep ASCII alphanumerics + hyphens. é -> "-" -> stripped.
        assert slugify("café") == "caf"


class TestIsSafeFilename:
    def test_accepts_jpg(self):
        assert is_safe_filename("frame_001.jpg") is True

    def test_accepts_png(self):
        assert is_safe_filename("img.png") is True

    def test_accepts_jpeg(self):
        assert is_safe_filename("img.jpeg") is True

    def test_rejects_traversal(self):
        assert is_safe_filename("../foo.jpg") is False

    def test_rejects_backslash(self):
        assert is_safe_filename("foo\\bar.jpg") is False

    def test_rejects_no_extension(self):
        assert is_safe_filename("frame") is False

    def test_rejects_other_extensions(self):
        assert is_safe_filename("frame.gif") is False
        assert is_safe_filename("frame.txt") is False

    def test_case_insensitive_extension(self):
        assert is_safe_filename("frame.JPG") is True
```

The `flask_annotator` import path requires the package to be importable. Add a `conftest.py` shim now:

Create `flask-annotator/conftest.py`:

```python
"""Make `flask-annotator/` importable as `flask_annotator` from tests."""
import sys
from pathlib import Path

# Add the project root (the parent of this file) to sys.path under the package alias.
_root = Path(__file__).resolve().parent
if str(_root) not in sys.path:
    sys.path.insert(0, str(_root))

# pytest will discover modules in flask-annotator/ as siblings; the package alias
# is achieved by treating flask-annotator itself as a namespace package.
```

Because the directory name has a hyphen (`flask-annotator`), it cannot be imported as `flask_annotator` directly. Adjust the test imports to use plain module names. Replace the test file's imports with:

```python
from slug import slugify, is_safe_filename
```

And update `conftest.py` to:

```python
"""Add flask-annotator/ to sys.path so its modules can be imported as plain names."""
import sys
from pathlib import Path

_root = Path(__file__).resolve().parent
if str(_root) not in sys.path:
    sys.path.insert(0, str(_root))
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_slug.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'slug'`.

- [ ] **Step 3: Implement `slug.py`**

Create `flask-annotator/slug.py`:

```python
"""Slug + filename validation helpers.

Mirrors the slugify logic in annotate.php so the same model directory names
are reachable from both stacks.
"""
from __future__ import annotations

import re

_SLUG_RE = re.compile(r"[^a-z0-9]+")
_SAFE_FILENAME_RE = re.compile(r"^[A-Za-z0-9._-]+\.(jpg|jpeg|png)$", re.IGNORECASE)


def slugify(value: str) -> str:
    """Lowercase, replace non-alphanumeric runs with `-`, trim leading/trailing `-`.

    Mirrors PHP's `slugify` in annotate.php so a model named "My Trail!" maps
    to the same folder name from both stacks.
    """
    lowered = value.strip().lower()
    replaced = _SLUG_RE.sub("-", lowered)
    return replaced.strip("-")


def is_safe_filename(name: str) -> bool:
    """True if `name` is a plain image filename safe to join with a directory.

    Rejects path traversal (`..`, `/`, `\\`) and any non-image extensions.
    """
    return bool(_SAFE_FILENAME_RE.fullmatch(name))
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests/test_slug.py -v`
Expected: 14 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/slug.py flask-annotator/tests/test_slug.py flask-annotator/conftest.py
git commit -m "feat(annotator): slugify + image filename validation with tests"
```

---

## Task 3: Annotations module — load + normalize v1 → v2 (TDD)

**Files:**
- Create: `flask-annotator/annotations.py`
- Create: `flask-annotator/tests/test_annotations.py`

- [ ] **Step 1: Write the failing tests**

Create `flask-annotator/tests/test_annotations.py`:

```python
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_annotations.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'annotations'`.

- [ ] **Step 3: Implement `annotations.py` (load half only)**

Create `flask-annotator/annotations.py`:

```python
"""Load, normalize, and save the per-model annotations.json file.

Schema versions:
  v1 — original PHP format (no schemaVersion, no boxes, no class colors).
  v2 — current. Adds schemaVersion, classes[].color, annotations.boxes[].

v1 files are normalized to v2 in memory on load. They are never rewritten on
disk until the user actually saves; this preserves the original until the
first explicit edit.
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any, Literal, TypedDict

SCHEMA_VERSION = 2

DEFAULT_PALETTE: list[str] = [
    "#22d3ee",  # cyan
    "#facc15",  # yellow
    "#f472b6",  # pink
    "#34d399",  # green
    "#fb7185",  # red
    "#a78bfa",  # purple
]


class LoadResult(TypedDict):
    status: Literal["ok", "missing", "corrupt", "future"]
    data: dict[str, Any]


def default_scaffold(model: str) -> dict[str, Any]:
    """A blank v2 annotations.json with the two default classes."""
    return {
        "model": model,
        "schemaVersion": SCHEMA_VERSION,
        "classes": [
            {"id": 0, "name": "path", "color": DEFAULT_PALETTE[0]},
            {"id": 1, "name": "path-oxod", "color": DEFAULT_PALETTE[1]},
        ],
        "images": [],
    }


def normalize(data: dict[str, Any]) -> dict[str, Any]:
    """Return a v2-shaped copy of `data`, filling in missing fields.

    Does not mutate the input.
    """
    out = dict(data)
    out["schemaVersion"] = SCHEMA_VERSION
    classes = []
    for idx, cls in enumerate(data.get("classes", [])):
        c = dict(cls)
        if "color" not in c:
            c["color"] = DEFAULT_PALETTE[idx % len(DEFAULT_PALETTE)]
        classes.append(c)
    out["classes"] = classes

    images = []
    for img in data.get("images", []):
        i = dict(img)
        ann = dict(i.get("annotations") or {})
        if "segments" not in ann:
            ann["segments"] = []
        if "boxes" not in ann:
            ann["boxes"] = []
        i["annotations"] = ann
        images.append(i)
    out["images"] = images
    return out


def load_annotations(model_dir: Path, model: str) -> LoadResult:
    """Read annotations.json from disk. Never raises on bad input.

    Status values:
      "missing" — file does not exist; data is the default scaffold.
      "corrupt" — file exists but isn't valid JSON; data is the default scaffold.
                  The bad file is left on disk; saving will rotate it aside.
      "future"  — file is valid but schemaVersion > 2; loaded as-is. Frontend
                  should treat as read-only.
      "ok"      — file is valid v1 or v2; normalized to v2 in memory.
    """
    path = model_dir / "annotations.json"
    if not path.is_file():
        return {"status": "missing", "data": default_scaffold(model)}

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError):
        return {"status": "corrupt", "data": default_scaffold(model)}

    if not isinstance(raw, dict):
        return {"status": "corrupt", "data": default_scaffold(model)}

    version = raw.get("schemaVersion", 1)
    if isinstance(version, int) and version > SCHEMA_VERSION:
        return {"status": "future", "data": raw}

    return {"status": "ok", "data": normalize(raw)}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests/test_annotations.py -v`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/annotations.py flask-annotator/tests/test_annotations.py
git commit -m "feat(annotator): load + normalize v1->v2 annotations"
```

---

## Task 4: Annotations module — atomic save with corrupt-file rotation (TDD)

**Files:**
- Modify: `flask-annotator/annotations.py`
- Modify: `flask-annotator/tests/test_annotations.py`

- [ ] **Step 1: Append failing tests to `test_annotations.py`**

```python
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_annotations.py -v`
Expected: 4 new tests FAIL with `ImportError: cannot import name 'save_annotations'`.

- [ ] **Step 3: Implement `save_annotations` in `annotations.py`**

Append to `flask-annotator/annotations.py`:

```python
def save_annotations(model_dir: Path, data: dict[str, Any]) -> None:
    """Atomically write annotations.json into model_dir.

    If an existing file is unreadable JSON, it is moved aside to
    `annotations.json.broken-<unix-ts>` so the user does not silently
    overwrite recoverable data.
    """
    path = model_dir / "annotations.json"

    # Rotate a corrupt existing file out of the way before writing.
    if path.is_file():
        try:
            json.loads(path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError):
            backup = model_dir / f"annotations.json.broken-{int(time.time())}"
            os.replace(str(path), str(backup))

    tmp = path.with_suffix(f".json.tmp.{os.getpid()}")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(str(tmp), str(path))
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests/test_annotations.py -v`
Expected: all tests (including the 4 new) PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/annotations.py flask-annotator/tests/test_annotations.py
git commit -m "feat(annotator): atomic save with corrupt-file rotation"
```

---

## Task 5: Flask app skeleton + model picker route (TDD)

**Files:**
- Create: `flask-annotator/app.py`
- Create: `flask-annotator/tests/conftest.py`
- Create: `flask-annotator/tests/test_routes.py`

- [ ] **Step 1: Create the test fixture**

Create `flask-annotator/tests/conftest.py`:

```python
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
```

- [ ] **Step 2: Write the failing route test for the model picker**

Create `flask-annotator/tests/test_routes.py`:

```python
class TestModelPicker:
    def test_root_without_model_renders_picker(self, client):
        r = client.get("/")
        assert r.status_code == 200
        assert b"demo" in r.data  # model slug appears in the picker page

    def test_root_with_model_renders_index(self, client):
        r = client.get("/?model=demo")
        assert r.status_code == 200
        # The annotation page injects the model slug as a JS constant.
        assert b'"demo"' in r.data

    def test_root_with_unknown_model_404(self, client):
        r = client.get("/?model=does-not-exist")
        assert r.status_code == 404

    def test_root_with_traversal_model_400(self, client):
        # Slugify strips ../, so "../etc" -> "etc". That folder doesn't exist
        # -> 404. There is no way to traverse out of RECORD_ROOT.
        r = client.get("/?model=../etc")
        assert r.status_code in (400, 404)
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_routes.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'app'`.

- [ ] **Step 4: Implement `app.py` with the picker route only**

Create `flask-annotator/app.py`:

```python
"""Flask application for the Stay On Trails annotator.

Routes are split across small handlers in this single file. As the surface
grows (SAM, training), they will be moved to dedicated blueprints — but
keeping them here for Phase 1+2 keeps the project trivial to navigate.
"""
from __future__ import annotations

from pathlib import Path

from flask import Flask, abort, render_template, request

import config
from slug import slugify


def _list_model_slugs() -> list[dict]:
    """Return one entry per direct subfolder of RECORD_ROOT."""
    if not config.RECORD_ROOT.is_dir():
        return []
    out: list[dict] = []
    for entry in sorted(config.RECORD_ROOT.iterdir()):
        if not entry.is_dir():
            continue
        out.append({
            "slug": entry.name,
            "image_count": len(list(entry.glob("*.jpg"))),
            "has_annotations": (entry / "annotations.json").is_file(),
        })
    return out


def create_app() -> Flask:
    app = Flask(__name__)

    @app.get("/")
    def index():
        raw_model = request.args.get("model", "")
        model = slugify(raw_model)
        if not model:
            return render_template("picker.html", models=_list_model_slugs())
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")
        return render_template("index.html", model=model)

    return app


if __name__ == "__main__":
    app = create_app()
    app.run(host="127.0.0.1", port=config.PORT, debug=config.DEBUG)
```

- [ ] **Step 5: Create minimal `templates/picker.html` and `templates/index.html`**

These will be fully styled in Task 10. For now, just enough to make the tests pass.

Create `flask-annotator/templates/picker.html`:

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>Pick a model</title></head>
<body>
<h1>Pick a model</h1>
<ul>
  {% for m in models %}
    <li><a href="/?model={{ m.slug }}">{{ m.slug }}</a> — {{ m.image_count }} images</li>
  {% endfor %}
</ul>
</body></html>
```

Create `flask-annotator/templates/index.html`:

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>Annotate {{ model }}</title></head>
<body>
<h1>Annotate {{ model }}</h1>
<script>const MODEL = {{ model | tojson }};</script>
</body></html>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `pytest flask-annotator/tests -v`
Expected: all tests (slug, annotations, routes) PASS.

- [ ] **Step 7: Smoke-run the app**

Run: `python flask-annotator/app.py`
Then in a browser visit `http://127.0.0.1:5001/`. Expected: a plain "Pick a model" page (will be empty if `recorded_routes/` has no subfolders yet, which is fine).
Stop the server with Ctrl+C.

- [ ] **Step 8: Commit**

```bash
git add flask-annotator/app.py flask-annotator/templates/ flask-annotator/tests/conftest.py flask-annotator/tests/test_routes.py
git commit -m "feat(annotator): flask app skeleton + model picker route"
```

---

## Task 6: List images route (TDD)

**Files:**
- Modify: `flask-annotator/app.py`
- Modify: `flask-annotator/tests/test_routes.py`

- [ ] **Step 1: Append failing tests to `test_routes.py`**

```python
class TestListImages:
    def test_returns_image_list_with_status(self, client):
        r = client.get("/api/models/demo/images")
        assert r.status_code == 200
        body = r.get_json()
        assert body["ok"] is True
        files = [i["file"] for i in body["images"]]
        assert files == ["frame_001.jpg", "frame_002.jpg"]
        # frame_001 had a segment in the fixture so it's "in-progress".
        statuses = {i["file"]: i["status"] for i in body["images"]}
        assert statuses["frame_001.jpg"] == "in-progress"
        # frame_002 has no annotation entry -> "unlabeled" by default.
        assert statuses["frame_002.jpg"] == "unlabeled"

    def test_unknown_model_404(self, client):
        r = client.get("/api/models/nope/images")
        assert r.status_code == 404
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_routes.py::TestListImages -v`
Expected: FAIL with 404 on the success case (route doesn't exist yet).

- [ ] **Step 3: Add the `/api/models/<model>/images` route**

In `app.py`, inside `create_app()`, add after the `index` view:

```python
    @app.get("/api/models/<model>/images")
    def list_images(model):
        from annotations import load_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        files = sorted(p.name for p in model_dir.glob("*.jpg"))
        loaded = load_annotations(model_dir, model)
        status_by_file = {
            img["file"]: img.get("status", "unlabeled")
            for img in loaded["data"].get("images", [])
        }
        images = [{"file": f, "status": status_by_file.get(f, "unlabeled")} for f in files]
        return {"ok": True, "images": images}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests/test_routes.py::TestListImages -v`
Expected: 2 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/app.py flask-annotator/tests/test_routes.py
git commit -m "feat(annotator): GET /api/models/<m>/images route"
```

---

## Task 7: Load + save annotations routes (TDD)

**Files:**
- Modify: `flask-annotator/app.py`
- Modify: `flask-annotator/tests/test_routes.py`

- [ ] **Step 1: Append failing tests to `test_routes.py`**

```python
class TestLoadAnnotations:
    def test_returns_v1_normalized_to_v2(self, client):
        r = client.get("/api/models/demo/annotations")
        assert r.status_code == 200
        body = r.get_json()
        assert body["ok"] is True
        assert body["data"]["schemaVersion"] == 2
        assert body["data"]["classes"][0]["color"] == "#22d3ee"
        assert body["data"]["images"][0]["annotations"]["boxes"] == []
        assert body["status"] == "ok"

    def test_missing_file_returns_default_scaffold(self, client, record_root):
        # Delete the annotations file to test the missing case.
        (record_root / "demo" / "annotations.json").unlink()
        r = client.get("/api/models/demo/annotations")
        assert r.status_code == 200
        body = r.get_json()
        assert body["status"] == "missing"
        assert body["data"]["model"] == "demo"
        assert len(body["data"]["classes"]) == 2  # path + path-oxod


class TestSaveAnnotationsRoute:
    def test_put_writes_file_and_returns_ok(self, client, record_root):
        payload = {
            "model": "demo",
            "schemaVersion": 2,
            "classes": [{"id": 0, "name": "path", "color": "#22d3ee"}],
            "images": [],
        }
        r = client.put(
            "/api/models/demo/annotations",
            json=payload,
        )
        assert r.status_code == 200
        assert r.get_json() == {"ok": True}
        # Round-trip: load it back via the GET route.
        r2 = client.get("/api/models/demo/annotations")
        assert r2.get_json()["data"]["classes"] == payload["classes"]

    def test_put_rejects_non_json(self, client):
        r = client.put("/api/models/demo/annotations", data="not json", content_type="text/plain")
        assert r.status_code == 400

    def test_put_rejects_wrong_shape(self, client):
        r = client.put("/api/models/demo/annotations", json={"model": "demo"})  # missing classes/images
        assert r.status_code == 400

    def test_put_unknown_model_404(self, client):
        r = client.put("/api/models/nope/annotations", json={"model": "nope", "classes": [], "images": []})
        assert r.status_code == 404
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_routes.py -v`
Expected: 6 new tests FAIL with 404 (routes don't exist yet).

- [ ] **Step 3: Add the routes in `app.py`**

In `app.py` `create_app()`, append after `list_images`:

```python
    @app.get("/api/models/<model>/annotations")
    def get_annotations(model):
        from annotations import load_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")
        loaded = load_annotations(model_dir, model)
        return {"ok": True, "status": loaded["status"], "data": loaded["data"]}

    @app.put("/api/models/<model>/annotations")
    def put_annotations(model):
        from annotations import save_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        body = request.get_json(silent=True)
        if not isinstance(body, dict):
            abort(400, description="Body must be a JSON object.")
        if not isinstance(body.get("classes"), list) or not isinstance(body.get("images"), list):
            abort(400, description="Body must contain `classes` and `images` arrays.")

        save_annotations(model_dir, body)
        return {"ok": True}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests/test_routes.py -v`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/app.py flask-annotator/tests/test_routes.py
git commit -m "feat(annotator): GET/PUT /api/models/<m>/annotations routes"
```

---

## Task 8: Image-serving route (TDD)

**Files:**
- Modify: `flask-annotator/app.py`
- Modify: `flask-annotator/tests/test_routes.py`

- [ ] **Step 1: Append failing tests to `test_routes.py`**

```python
class TestImageRoute:
    def test_serves_existing_image(self, client):
        r = client.get("/img/demo/frame_001.jpg")
        assert r.status_code == 200
        # 4-byte placeholder JPEG from the fixture.
        assert r.data == b"\xff\xd8\xff\xd9"

    def test_unknown_image_404(self, client):
        r = client.get("/img/demo/missing.jpg")
        assert r.status_code == 404

    def test_traversal_filename_400(self, client):
        r = client.get("/img/demo/..%2Fapp.py")
        assert r.status_code in (400, 404)

    def test_unsafe_extension_400(self, client):
        r = client.get("/img/demo/foo.txt")
        assert r.status_code == 400
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest flask-annotator/tests/test_routes.py::TestImageRoute -v`
Expected: 4 tests FAIL.

- [ ] **Step 3: Add the route in `app.py`**

In `app.py`, add the import at the top:

```python
from flask import Flask, abort, render_template, request, send_from_directory
```

And inside `create_app()` add:

```python
    @app.get("/img/<model>/<filename>")
    def serve_image(model, filename):
        from slug import is_safe_filename

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404)
        if not is_safe_filename(filename):
            abort(400, description="Invalid filename.")
        return send_from_directory(str(model_dir), filename)
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest flask-annotator/tests -v`
Expected: all tests across all files PASS.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/app.py flask-annotator/tests/test_routes.py
git commit -m "feat(annotator): GET /img/<model>/<filename> route"
```

---

## Task 9: Port the CSS from annotate.php

**Files:**
- Create: `flask-annotator/static/css/style.css`

The existing `<style>` block in `annotate.php` (lines 168–285) covers everything we need plus a tiny bit more for the new toolbar, class manager modal, and box annotations.

- [ ] **Step 1: Copy the existing styles verbatim**

Create `flask-annotator/static/css/style.css` with the contents of `annotate.php`'s `<style>` block (lines 168–285), wrapped without the `<style>` tags. Start from this exact text:

```css
:root{
  --focus:#22d3ee;
  --menu-bg:rgba(15,23,42,.92);
  --menu-border:rgba(255,255,255,.18);
  --accent:#facc15;
  --accent-ink:#111827;
  --line:rgba(255,255,255,.12);
  --muted:#cbd5e1;
  --ok:#34d399;
  --warn:#fb7185;
}

*{box-sizing:border-box}
html,body{margin:0;height:100%;background:#020617;color:#fff;font-family:Arial,Helvetica,sans-serif;overflow:hidden}
a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:2px}

.topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
.topbar-inner{max-width:1420px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{margin:0;font-size:18px;font-weight:700}
.menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
.menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
.menu a:hover{background:rgba(255,255,255,.1)}
.menu .cta{background:var(--accent);color:var(--accent-ink)}

.sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.btn{padding:9px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer;font-size:13px}
.btn:hover{background:#334155}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn.active{background:rgba(34,211,238,.18);border-color:var(--focus);color:var(--focus)}

.page-header{max-width:1420px;margin:0 auto;padding:10px 18px 0;display:flex;align-items:baseline;gap:10px;flex-shrink:0}
.page-header h1{margin:0;font-size:20px}
.model-chip{color:var(--focus);font-weight:700;font-size:16px}
#saveStatus{margin-left:auto;font-size:13px;color:var(--muted);transition:color .3s}
#saveStatus.ok{color:var(--ok)}
#saveStatus.warn{color:var(--warn)}

.anno-layout{
  max-width:1420px;margin:0 auto;padding:10px 18px 14px;
  display:grid;
  grid-template-columns:220px minmax(0,1fr) 200px;
  gap:12px;
  height:calc(100vh - 62px - 36px);
}
.anno-panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column}
.panel-head{padding:10px 12px;border-bottom:1px solid var(--line);font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);flex-shrink:0}

.img-list{flex:1;overflow-y:auto;padding:8px;display:flex;flex-direction:column;gap:6px}
.img-item{display:flex;gap:8px;align-items:center;padding:8px;border-radius:12px;cursor:pointer;border:2px solid transparent;background:rgba(255,255,255,.03);transition:background .15s,border-color .15s}
.img-item:hover{background:rgba(255,255,255,.07)}
.img-item.active{border-color:var(--focus);background:rgba(34,211,238,.08)}
.img-thumb-sm{width:52px;height:39px;object-fit:cover;border-radius:8px;border:1px solid var(--line);flex-shrink:0;background:#000}
.img-meta{flex:1;min-width:0}
.img-name{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{display:inline-block;font-size:10px;padding:2px 6px;border-radius:999px;font-weight:700;letter-spacing:.04em;margin-top:3px}
.badge-unlabeled{background:rgba(255,255,255,.1);color:var(--muted)}
.badge-in-progress{background:rgba(251,191,36,.15);color:#fbbf24}
.badge-done{background:rgba(52,211,153,.15);color:var(--ok)}
.no-images{padding:20px 12px;text-align:center;color:var(--muted);font-size:13px}

.canvas-wrap{flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#000;position:relative}
#stage{display:block}
.canvas-idle{position:absolute;color:var(--muted);font-size:14px;text-align:center;padding:20px;pointer-events:none}
.canvas-bar{padding:8px 12px;border-top:1px solid var(--line);display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(0,0,0,.3);flex-shrink:0}
#drawStatus{font-size:12px;color:var(--muted);flex:1}
.btn-done{background:rgba(52,211,153,.12);color:var(--ok);border-color:rgba(52,211,153,.3)}
.btn-done:hover{background:rgba(52,211,153,.22)}

.right-inner{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1}
.mode-toolbar{display:flex;gap:6px}
.mode-toolbar .btn{flex:1;padding:8px 0;text-align:center;font-size:12px}
.class-row{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:10px;background:rgba(255,255,255,.05);border:2px solid transparent;cursor:pointer;font-size:13px;font-weight:700}
.class-row:hover{background:rgba(255,255,255,.09)}
.class-row.selected{border-color:var(--focus);background:rgba(34,211,238,.1);color:var(--focus)}
.class-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.class-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.class-edit{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:0 4px}
.class-edit:hover{color:#fff}
.class-add{padding:6px 8px;border:1px dashed var(--line);border-radius:10px;color:var(--muted);background:transparent;cursor:pointer;font-size:12px;text-align:left}
.class-add:hover{color:#fff;border-color:#fff}
.anno-list{display:flex;flex-direction:column;gap:6px;overflow-y:auto;max-height:30vh}
.anno-item{display:flex;justify-content:space-between;align-items:center;padding:6px 8px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:12px;cursor:pointer}
.anno-item.selected{border-color:var(--focus);background:rgba(34,211,238,.08)}
.del-btn{background:none;border:none;color:var(--warn);cursor:pointer;font-size:16px;line-height:1;padding:2px 6px}
.del-btn:hover{color:#fff}
.bottom-actions{display:flex;flex-direction:column;gap:8px;flex-shrink:0}
.nav-btns{display:flex;gap:8px}
.btn-cyan{background:rgba(34,211,238,.12);color:var(--focus);border-color:rgba(34,211,238,.35)}
.btn-cyan:hover{background:rgba(34,211,238,.22)}

.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#0f172a;border:1px solid var(--line);border-radius:14px;padding:18px;width:320px;max-width:90vw;color:#fff}
.modal h2{margin:0 0 12px;font-size:15px}
.modal label{display:block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.modal input[type=text]{width:100%;padding:8px;border-radius:8px;border:1px solid var(--line);background:#020617;color:#fff;font-size:13px;margin-bottom:10px}
.modal .palette{display:flex;gap:6px;margin-bottom:10px}
.modal .swatch{width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer}
.modal .swatch.selected{border-color:#fff}
.modal .actions{display:flex;justify-content:space-between;gap:8px;margin-top:12px}
.btn-warn{background:rgba(251,113,133,.12);color:var(--warn);border-color:rgba(251,113,133,.35)}
.btn-warn:hover{background:rgba(251,113,133,.22)}
.banner{padding:8px 12px;background:rgba(251,191,36,.12);color:#fbbf24;border-bottom:1px solid rgba(251,191,36,.3);font-size:12px;text-align:center;flex-shrink:0}

.error-box{max-width:480px;margin:80px auto;padding:32px;background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;text-align:center}
.error-box h2{color:var(--warn);margin-top:0}

@media(max-width:900px){
  html,body{overflow:auto}
  .anno-layout{grid-template-columns:1fr;height:auto}
  .canvas-wrap{height:60vw;min-height:280px}
  .img-list{max-height:280px}
}
```

- [ ] **Step 2: Commit**

```bash
git add flask-annotator/static/css/style.css
git commit -m "feat(annotator): port styles from annotate.php"
```

---

## Task 10: Build the index.html template

**Files:**
- Modify: `flask-annotator/templates/index.html`

- [ ] **Step 1: Replace the placeholder index.html**

Overwrite `flask-annotator/templates/index.html`:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails — Annotate {{ model }}</title>
  <link rel="stylesheet" href="{{ url_for('static', filename='css/style.css') }}" />
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Stay On Trails</p>
    <nav aria-label="Main menu">
      <ul class="menu">
        <li><a href="http://localhost/stayontrails/index.php">Home</a></li>
        <li><a href="http://localhost/stayontrails/routes.php">Available routes</a></li>
        <li><a href="http://localhost/stayontrails/makepath.php">Route builder</a></li>
        <li><a href="http://localhost/stayontrails/followpath.php">Start route</a></li>
        <li><a href="http://localhost/stayontrails/remoteHelp.php">Remote assistant</a></li>
        <li><a href="http://localhost/stayontrails/recordroute.php">Record route</a></li>
        <li><a class="cta" href="#" aria-current="page">Annotate</a></li>
      </ul>
    </nav>
  </div>
</header>

<div class="page-header">
  <h1>Annotate</h1>
  <span class="model-chip">{{ model }}</span>
  <span id="saveStatus"></span>
</div>

<div id="banner" class="banner" style="display:none"></div>

<div class="anno-layout">

  <aside class="anno-panel">
    <div class="panel-head">Images (<span id="imgCount">0</span>)</div>
    <div class="img-list" id="imgList">
      <div class="no-images">Loading…</div>
    </div>
  </aside>

  <section class="anno-panel">
    <div class="canvas-wrap" id="canvasWrap">
      <div id="stage"></div>
      <div class="canvas-idle" id="canvasIdle">Select an image to begin annotating</div>
    </div>
    <div class="canvas-bar">
      <span id="drawStatus">Polygon mode · Click to add vertices · Double-click to close · Esc to cancel</span>
      <button class="btn btn-done" id="markDoneBtn" type="button" disabled>Mark Done</button>
    </div>
  </section>

  <aside class="anno-panel">
    <div class="panel-head">Tools</div>
    <div class="right-inner">

      <div>
        <div class="sectionTitle" style="margin-bottom:6px">Mode</div>
        <div class="mode-toolbar">
          <button class="btn active" id="modePolygon" type="button" title="Polygon (P)">△ Polygon</button>
          <button class="btn" id="modeBox" type="button" title="Box (B)">▭ Box</button>
          <button class="btn" id="modeSelect" type="button" title="Select (V)">↖ Select</button>
        </div>
      </div>

      <div>
        <div class="sectionTitle" style="margin-bottom:6px">Classes</div>
        <div id="classList"></div>
        <button class="class-add" id="classAddBtn" type="button">+ Add class</button>
      </div>

      <div style="flex:1;min-height:0;display:flex;flex-direction:column">
        <div class="sectionTitle" style="margin-bottom:6px">Annotations (<span id="segCount">0</span>)</div>
        <div class="anno-list" id="annoList"></div>
      </div>

      <div class="bottom-actions">
        <button class="btn btn-cyan" id="saveBtn" type="button" disabled>Save</button>
        <div class="nav-btns">
          <button class="btn" id="prevBtn" type="button" style="flex:1" disabled>← Prev</button>
          <button class="btn" id="nextBtn" type="button" style="flex:1" disabled>Next →</button>
        </div>
      </div>

    </div>
  </aside>

</div>

<div id="modalRoot"></div>

<script>window.MODEL = {{ model | tojson }};</script>
<script src="https://unpkg.com/konva@9.3.16/konva.min.js"></script>
<script src="{{ url_for('static', filename='js/annotator.js') }}"></script>

</body>
</html>
```

- [ ] **Step 2: Replace `templates/picker.html` with a styled version**

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Stay On Trails — Pick a model</title>
  <link rel="stylesheet" href="{{ url_for('static', filename='css/style.css') }}" />
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Stay On Trails</p>
    <nav><ul class="menu"><li><a class="cta" href="#" aria-current="page">Annotate</a></li></ul></nav>
  </div>
</header>
<div class="error-box" style="background:rgba(15,23,42,.84)">
  <h2 style="color:var(--focus)">Pick a model</h2>
  {% if models %}
    <ul style="list-style:none;padding:0;margin:0;text-align:left">
      {% for m in models %}
        <li style="padding:8px 0;border-bottom:1px solid var(--line)">
          <a href="/?model={{ m.slug }}" style="color:#fff;text-decoration:none;font-weight:700">{{ m.slug }}</a>
          <span style="color:var(--muted);font-size:12px"> — {{ m.image_count }} images
          {% if m.has_annotations %}· annotated{% endif %}</span>
        </li>
      {% endfor %}
    </ul>
  {% else %}
    <p style="color:var(--muted)">No models found in <code>recorded_routes/</code>.</p>
  {% endif %}
</div>
</body>
</html>
```

- [ ] **Step 3: Smoke-test in browser**

Run: `python flask-annotator/app.py` (in another terminal).
Visit `http://localhost:5001/`. Expected: dark-themed model picker that lists `recorded_routes/*` subfolders.
Visit `http://localhost:5001/?model=<some-existing-model>`. Expected: the three-panel layout shell loads. The canvas area is empty (no JS yet). The right panel has Mode buttons, an empty class list, and the bottom actions. **No JS console errors are expected.**
Stop the server.

- [ ] **Step 4: Commit**

```bash
git add flask-annotator/templates/
git commit -m "feat(annotator): index.html + picker.html with ported chrome"
```

---

## Task 11: Frontend — bootstrap, state, image list

**Files:**
- Create: `flask-annotator/static/js/annotator.js`

- [ ] **Step 1: Create the file with bootstrap + image-list logic**

Create `flask-annotator/static/js/annotator.js`:

```javascript
/**
 * Stay On Trails — Annotator (Konva-based)
 *
 * State and rendering live in this single file. Logic mirrors the original
 * annotate.php structure: API helpers → state → render functions → drawing →
 * event wiring → init.
 */
(function () {
  'use strict';

  const MODEL = window.MODEL;
  const IMG_BASE = '/img/' + MODEL + '/';

  // ── Constants ──────────────────────────────────────────────────────────────
  const PALETTE = ['#22d3ee','#facc15','#f472b6','#34d399','#fb7185','#a78bfa'];
  const IMG_W = 640, IMG_H = 480;

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    allImages: [],           // [{file, status}]
    annMap: {},              // file -> {status, segments:[], boxes:[]}
    classes: [],             // [{id, name, color}]
    currentIndex: -1,
    selectedClass: 0,
    mode: 'polygon',         // 'polygon' | 'box' | 'select'
    selection: null,         // {type:'segment'|'box', idx:number} | null
    drawing: null,           // current in-progress shape
    dirty: false,
    loadStatus: 'ok',        // 'ok' | 'missing' | 'corrupt' | 'future'
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const el = {
    canvasWrap: document.getElementById('canvasWrap'),
    canvasIdle: document.getElementById('canvasIdle'),
    stageDiv: document.getElementById('stage'),
    imgList: document.getElementById('imgList'),
    imgCount: document.getElementById('imgCount'),
    classList: document.getElementById('classList'),
    classAddBtn: document.getElementById('classAddBtn'),
    annoList: document.getElementById('annoList'),
    segCount: document.getElementById('segCount'),
    drawStatus: document.getElementById('drawStatus'),
    saveStatus: document.getElementById('saveStatus'),
    saveBtn: document.getElementById('saveBtn'),
    prevBtn: document.getElementById('prevBtn'),
    nextBtn: document.getElementById('nextBtn'),
    markDoneBtn: document.getElementById('markDoneBtn'),
    modePolygon: document.getElementById('modePolygon'),
    modeBox: document.getElementById('modeBox'),
    modeSelect: document.getElementById('modeSelect'),
    banner: document.getElementById('banner'),
    modalRoot: document.getElementById('modalRoot'),
  };

  // ── Helpers ───────────────────────────────────────────────────────────────
  function classColor(id) {
    const cls = state.classes.find(c => c.id === id);
    return (cls && cls.color) || PALETTE[id % PALETTE.length];
  }
  function className(id) {
    const cls = state.classes.find(c => c.id === id);
    return cls ? cls.name : 'class ' + id;
  }
  function currentFile() {
    return state.currentIndex >= 0 ? state.allImages[state.currentIndex].file : null;
  }
  function currentAnn() {
    const f = currentFile();
    if (!f) return null;
    if (!state.annMap[f]) state.annMap[f] = { status: 'unlabeled', segments: [], boxes: [] };
    return state.annMap[f];
  }
  function shortId(prefix) {
    return prefix + '-' + Math.random().toString(36).slice(2, 8);
  }
  function setSaveStatus(msg, tone) {
    el.saveStatus.textContent = msg;
    el.saveStatus.className = tone || '';
  }
  function setDrawStatus(msg) { el.drawStatus.textContent = msg; }
  function markDirty() { state.dirty = true; }
  function clearDirty() { state.dirty = false; }

  // ── API calls ─────────────────────────────────────────────────────────────
  async function api(method, path, body) {
    const opts = { method, headers: {} };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const r = await fetch(path, opts);
    if (!r.ok) {
      let detail = '';
      try { detail = (await r.json()).description || ''; } catch {}
      throw new Error('HTTP ' + r.status + (detail ? ': ' + detail : ''));
    }
    return r.json();
  }

  async function loadAnnotations() {
    const d = await api('GET', '/api/models/' + encodeURIComponent(MODEL) + '/annotations');
    state.classes = d.data.classes || [];
    state.loadStatus = d.status;
    state.annMap = {};
    for (const img of (d.data.images || [])) {
      state.annMap[img.file] = {
        status: img.status || 'unlabeled',
        segments: (img.annotations && img.annotations.segments) || [],
        boxes: (img.annotations && img.annotations.boxes) || [],
      };
    }
    if (d.status === 'corrupt') {
      el.banner.style.display = 'block';
      el.banner.textContent = 'annotations.json was unreadable. Showing defaults; the bad file will be moved aside on first save.';
    } else if (d.status === 'future') {
      el.banner.style.display = 'block';
      el.banner.textContent = 'annotations.json was written by a newer version. Read-only mode.';
      el.saveBtn.disabled = true;
    }
  }

  async function loadImageList() {
    const d = await api('GET', '/api/models/' + encodeURIComponent(MODEL) + '/images');
    state.allImages = d.images.map(img => ({
      file: img.file,
      status: (state.annMap[img.file] && state.annMap[img.file].status) || img.status || 'unlabeled',
    }));
    el.imgCount.textContent = state.allImages.length;
    renderImageList();
  }

  async function saveAnnotations(silent) {
    if (state.loadStatus === 'future') return;
    const images = state.allImages.map(img => {
      const a = state.annMap[img.file] || { status: 'unlabeled', segments: [], boxes: [] };
      return {
        file: img.file,
        width: IMG_W,
        height: IMG_H,
        status: a.status || 'unlabeled',
        annotations: { segments: a.segments || [], boxes: a.boxes || [] },
      };
    });
    const payload = { model: MODEL, schemaVersion: 2, classes: state.classes, images };
    el.saveBtn.disabled = true;
    if (!silent) setSaveStatus('Saving…');
    try {
      await api('PUT', '/api/models/' + encodeURIComponent(MODEL) + '/annotations', payload);
      clearDirty();
      if (!silent) {
        setSaveStatus('Saved.', 'ok');
        setTimeout(() => { if (el.saveStatus.textContent === 'Saved.') setSaveStatus(''); }, 2000);
      }
    } catch (e) {
      setSaveStatus('Save failed: ' + e.message, 'warn');
    } finally {
      el.saveBtn.disabled = state.currentIndex < 0;
    }
  }

  // ── Render: image list ────────────────────────────────────────────────────
  function renderImageList() {
    if (!state.allImages.length) {
      el.imgList.innerHTML = '<div class="no-images">No images.</div>';
      return;
    }
    const frag = document.createDocumentFragment();
    state.allImages.forEach((img, i) => {
      const div = document.createElement('div');
      div.className = 'img-item' + (i === state.currentIndex ? ' active' : '');
      const status = (state.annMap[img.file] && state.annMap[img.file].status) || img.status || 'unlabeled';
      div.innerHTML =
        '<img class="img-thumb-sm" src="' + IMG_BASE + encodeURIComponent(img.file) + '" loading="lazy" alt="" />' +
        '<div class="img-meta">' +
          '<div class="img-name">' + img.file + '</div>' +
          '<span class="badge badge-' + status + '">' + status.replace('-', '‑') + '</span>' +
        '</div>';
      div.addEventListener('click', () => selectImage(i));
      frag.appendChild(div);
    });
    el.imgList.innerHTML = '';
    el.imgList.appendChild(frag);
  }

  function updateNavButtons() {
    el.prevBtn.disabled = state.currentIndex <= 0;
    el.nextBtn.disabled = state.currentIndex < 0 || state.currentIndex >= state.allImages.length - 1;
    el.saveBtn.disabled = state.currentIndex < 0 || state.loadStatus === 'future';
    el.markDoneBtn.disabled = state.currentIndex < 0;
  }

  // ── Stubs filled in by later tasks ────────────────────────────────────────
  function selectImage(i) { state.currentIndex = i; renderImageList(); updateNavButtons(); }
  function renderClassList() {}
  function renderAnnotationList() {}
  function redraw() {}

  // ── Init ──────────────────────────────────────────────────────────────────
  async function init() {
    try {
      await loadAnnotations();
      await loadImageList();
      renderClassList();
      updateNavButtons();
    } catch (e) {
      setSaveStatus('Load error: ' + e.message, 'warn');
    }
  }

  // beforeunload guard for unsaved changes
  window.addEventListener('beforeunload', e => {
    if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  init();

  // Expose for later tasks (kept on window so subsequent files can extend, if needed).
  window.__ANN__ = { state, el, api, classColor, className, currentFile, currentAnn,
    shortId, setSaveStatus, setDrawStatus, markDirty, clearDirty,
    loadAnnotations, loadImageList, saveAnnotations,
    renderImageList, updateNavButtons,
    PALETTE, IMG_W, IMG_H };

})();
```

- [ ] **Step 2: Browser smoke test**

Run: `python flask-annotator/app.py`
Visit `http://localhost:5001/?model=<existing-model>`. Expected:
- Image list populates on the left with thumbnails and status badges.
- Image count appears in the panel header.
- Clicking a thumbnail highlights it (no canvas yet — that's Task 12).
- No JS console errors.

- [ ] **Step 3: Commit**

```bash
git add flask-annotator/static/js/annotator.js
git commit -m "feat(annotator): JS bootstrap, state model, image list"
```

---

## Task 12: Frontend — Konva stage, image layer, transform

**Files:**
- Modify: `flask-annotator/static/js/annotator.js`

- [ ] **Step 1: Replace the stage stubs with real Konva initialization**

In `annotator.js`, replace the `// ── Stubs filled in by later tasks ──` block with the following. Place this section just before `// ── Init ──`:

```javascript
  // ── Konva stage ───────────────────────────────────────────────────────────
  let stage = null, imgLayer = null, annLayer = null, drawLayer = null;
  let konvaImage = null;
  let imgGroup = null;  // wraps imgLayer + annLayer with the letterbox transform

  function initStage() {
    const rect = el.canvasWrap.getBoundingClientRect();
    stage = new Konva.Stage({
      container: el.stageDiv,
      width: Math.max(1, Math.floor(rect.width)),
      height: Math.max(1, Math.floor(rect.height)),
    });
    imgLayer = new Konva.Layer();
    annLayer = new Konva.Layer();
    drawLayer = new Konva.Layer();
    stage.add(imgLayer); stage.add(annLayer); stage.add(drawLayer);

    new ResizeObserver(resizeStage).observe(el.canvasWrap);
  }

  function resizeStage() {
    if (!stage) return;
    const rect = el.canvasWrap.getBoundingClientRect();
    stage.width(Math.max(1, Math.floor(rect.width)));
    stage.height(Math.max(1, Math.floor(rect.height)));
    applyTransform();
    redraw();
  }

  function applyTransform() {
    // Letterbox 640x480 image inside the stage.
    const sx = stage.width() / IMG_W;
    const sy = stage.height() / IMG_H;
    const scale = Math.min(sx, sy);
    const dx = (stage.width() - IMG_W * scale) / 2;
    const dy = (stage.height() - IMG_H * scale) / 2;
    [imgLayer, annLayer, drawLayer].forEach(layer => {
      layer.scale({ x: scale, y: scale });
      layer.position({ x: dx, y: dy });
    });
  }

  function loadImageInto(file) {
    const img = new window.Image();
    img.onload = () => {
      imgLayer.destroyChildren();
      konvaImage = new Konva.Image({ image: img, x: 0, y: 0, width: IMG_W, height: IMG_H });
      imgLayer.add(konvaImage);
      applyTransform();
      el.canvasIdle.style.display = 'none';
      el.stageDiv.style.display = 'block';
      redraw();
    };
    img.onerror = () => {
      setDrawStatus('Failed to load image.');
      el.canvasIdle.textContent = 'Failed to load image.';
      el.canvasIdle.style.display = '';
    };
    img.src = IMG_BASE + encodeURIComponent(file);
  }

  // ── Selection (full impl in Task 14) ───────────────────────────────────────
  function clearSelection() { state.selection = null; redraw(); }

  // ── Render: full canvas redraw ─────────────────────────────────────────────
  function redraw() {
    if (!stage) return;
    annLayer.destroyChildren();
    drawLayer.destroyChildren();
    if (state.currentIndex < 0) { stage.batchDraw(); return; }
    const ann = currentAnn();
    if (!ann) { stage.batchDraw(); return; }

    // Committed segments
    (ann.segments || []).forEach((seg, i) => {
      const col = classColor(seg.classId);
      const flat = [];
      seg.points.forEach(p => { flat.push(p.x, p.y); });
      const isSelected = state.selection && state.selection.type === 'segment' && state.selection.idx === i;
      const line = new Konva.Line({
        points: flat, closed: true, stroke: col, strokeWidth: isSelected ? 3 : 2,
        fill: col + '4d', name: 'segment', listening: state.mode === 'select',
      });
      line.on('click', () => { state.selection = { type: 'segment', idx: i }; redraw(); });
      annLayer.add(line);
      // Vertex anchors when selected & in select mode.
      if (isSelected && state.mode === 'select') {
        seg.points.forEach((pt, vi) => {
          const dot = new Konva.Circle({
            x: pt.x, y: pt.y, radius: 5, fill: '#fff', stroke: col, strokeWidth: 2,
            draggable: true,
          });
          dot.on('dragmove', () => {
            seg.points[vi] = { x: Math.round(dot.x()), y: Math.round(dot.y()) };
            line.points([].concat(...seg.points.map(p => [p.x, p.y])));
            markDirty();
          });
          annLayer.add(dot);
        });
      }
    });

    // Committed boxes
    (ann.boxes || []).forEach((box, i) => {
      const col = classColor(box.classId);
      const isSelected = state.selection && state.selection.type === 'box' && state.selection.idx === i;
      const rect = new Konva.Rect({
        x: box.x, y: box.y, width: box.w, height: box.h,
        stroke: col, strokeWidth: isSelected ? 3 : 2, fill: col + '33',
        name: 'box', listening: state.mode === 'select',
        draggable: state.mode === 'select' && isSelected,
      });
      rect.on('click', () => { state.selection = { type: 'box', idx: i }; redraw(); });
      rect.on('dragend', () => {
        box.x = Math.round(rect.x()); box.y = Math.round(rect.y());
        markDirty();
      });
      annLayer.add(rect);
      if (isSelected && state.mode === 'select') {
        const tr = new Konva.Transformer({
          nodes: [rect], rotateEnabled: false, anchorSize: 8,
          enabledAnchors: ['top-left','top-right','bottom-left','bottom-right','middle-left','middle-right','top-center','bottom-center'],
          boundBoxFunc: (oldB, newB) => newB.width < 5 || newB.height < 5 ? oldB : newB,
        });
        annLayer.add(tr);
        rect.on('transformend', () => {
          // Bake scale into width/height.
          const w = rect.width() * rect.scaleX();
          const h = rect.height() * rect.scaleY();
          rect.width(Math.round(w)); rect.height(Math.round(h));
          rect.scaleX(1); rect.scaleY(1);
          box.x = Math.round(rect.x()); box.y = Math.round(rect.y());
          box.w = Math.round(rect.width()); box.h = Math.round(rect.height());
          markDirty(); redraw();
        });
      }
    });

    stage.batchDraw();
  }

  // Override the stub from Task 11: now selectImage actually loads the photo.
  function selectImage(index) {
    if (state.currentIndex !== -1 && state.currentIndex !== index) {
      saveAnnotations(true);
    }
    state.drawing = null; state.selection = null;
    state.currentIndex = index;
    updateNavButtons();
    renderImageList();
    renderAnnotationList();
    loadImageInto(state.allImages[index].file);
  }

  window.__ANN__.applyTransform = applyTransform;
```

Now reconcile with Task 11's stubs:

1. **Delete the stub `function selectImage(i) { state.currentIndex = i; renderImageList(); updateNavButtons(); }`** from Task 11. The real `selectImage` is defined above in this task.

2. **Delete the stub `function redraw() {}`** from Task 11. The real `redraw` is defined above.

3. **Keep the empty stubs `function renderClassList() {}` and `function renderAnnotationList() {}`** as they are. They will be replaced by real implementations in Task 15. Without them, `init()` would throw a ReferenceError when calling them.

4. **Modify `init()`** (the existing one from Task 11) by adding `initStage();` as its first line, before the `try {` block:

```javascript
  async function init() {
    initStage();
    try {
      await loadAnnotations();
      await loadImageList();
      renderClassList();
      updateNavButtons();
    } catch (e) {
      setSaveStatus('Load error: ' + e.message, 'warn');
    }
  }
```

There must be exactly one `init` function and exactly one `init();` call at the bottom of the IIFE.

- [ ] **Step 2: Browser smoke test**

Run: `python flask-annotator/app.py`
Visit `http://localhost:5001/?model=<existing-model>`. Click an image in the left list. Expected:
- The 640×480 photo appears centered in the canvas, letterboxed.
- Existing polygons (if any) render on top with their class colors.
- Resizing the browser window keeps the photo letterboxed correctly.
- No JS console errors.

- [ ] **Step 3: Commit**

```bash
git add flask-annotator/static/js/annotator.js
git commit -m "feat(annotator): konva stage, image layer, redraw existing annotations"
```

---

## Task 13: Frontend — polygon drawing mode

**Files:**
- Modify: `flask-annotator/static/js/annotator.js`

- [ ] **Step 1: Add drawing-state helpers and click handlers**

In `annotator.js`, add a new section right before `// ── Render: full canvas redraw ──`:

```javascript
  // ── Drawing: shared ────────────────────────────────────────────────────────
  function imageCoordsFromEvent() {
    // Convert pointer position to image coordinates by inverting the layer transform.
    const pos = stage.getPointerPosition();
    if (!pos) return null;
    const transform = imgLayer.getAbsoluteTransform().copy().invert();
    const ip = transform.point(pos);
    if (ip.x < 0 || ip.y < 0 || ip.x > IMG_W || ip.y > IMG_H) return null;
    return { x: ip.x, y: ip.y };
  }

  function distance(ax, ay, bx, by) {
    return Math.hypot(ax - bx, ay - by);
  }

  // ── Drawing: polygon ───────────────────────────────────────────────────────
  function startPolygonAt(p) {
    state.drawing = { type: 'polygon', classId: state.selectedClass, points: [p] };
    setDrawStatus('Adding vertices… double-click to close · Backspace to undo · Esc to cancel');
    redraw();
  }

  function addPolygonVertex(p) {
    if (!state.drawing || state.drawing.type !== 'polygon') return;
    state.drawing.points.push(p);
    redraw();
  }

  function commitPolygon() {
    const d = state.drawing;
    if (!d || d.type !== 'polygon' || d.points.length < 3) {
      state.drawing = null; redraw(); return;
    }
    const ann = currentAnn();
    ann.segments.push({
      id: shortId('s'),
      classId: d.classId,
      points: d.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) })),
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    state.allImages[state.currentIndex].status = ann.status;
    state.drawing = null;
    markDirty();
    renderAnnotationList(); renderImageList(); redraw();
    setDrawStatus('Polygon saved. Click to start a new one.');
  }

  function cancelDrawing() {
    state.drawing = null; redraw(); setDrawStatus('Cancelled.');
  }
```

- [ ] **Step 2: Extend `redraw()` to render the in-progress polygon**

Inside the existing `redraw()` function, after the boxes loop and before `stage.batchDraw()`, append:

```javascript
    // In-progress polygon
    if (state.drawing && state.drawing.type === 'polygon') {
      const d = state.drawing;
      const col = classColor(d.classId);
      if (d.points.length > 1) {
        const flat = [];
        d.points.forEach(p => flat.push(p.x, p.y));
        drawLayer.add(new Konva.Line({ points: flat, stroke: col, strokeWidth: 2 }));
      }
      d.points.forEach((p, i) => {
        drawLayer.add(new Konva.Circle({
          x: p.x, y: p.y, radius: i === 0 ? 5 : 4, fill: col,
          stroke: col, strokeWidth: 1.5,
        }));
      });
    }
```

- [ ] **Step 3: Wire up stage click + double-click events**

Add at the bottom of the IIFE, just before `init();`:

```javascript
  // ── Canvas events ──────────────────────────────────────────────────────────
  // Konva uses its own click event on the Stage.
  stage && stage.on('click', () => {});  // placeholder; real handlers attached after init

  function bindStageEvents() {
    stage.on('click', () => {
      if (state.currentIndex < 0) return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      if (state.mode === 'polygon') {
        if (!state.drawing) {
          startPolygonAt(p);
          return;
        }
        // Click near first vertex closes the polygon.
        const first = state.drawing.points[0];
        const pos = stage.getPointerPosition();
        const firstScreen = imgLayer.getAbsoluteTransform().point(first);
        if (state.drawing.points.length >= 3 &&
            distance(pos.x, pos.y, firstScreen.x, firstScreen.y) < 10) {
          commitPolygon();
          return;
        }
        addPolygonVertex(p);
      }
    });

    stage.on('dblclick', () => {
      if (state.mode !== 'polygon' || !state.drawing) return;
      // The browser fired click → click → dblclick — pop the duplicate.
      if (state.drawing.points.length > 3) state.drawing.points.pop();
      commitPolygon();
    });
  }
```

And inside `init()` after `initStage()` add:

```javascript
    bindStageEvents();
```

- [ ] **Step 4: Add keyboard shortcuts**

Append before `init();`:

```javascript
  window.addEventListener('keydown', e => {
    // Don't intercept shortcuts while typing in inputs (modals).
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'Escape') {
      if (state.drawing) cancelDrawing();
      else if (state.selection) clearSelection();
      return;
    }
    if (e.key === 'Backspace') {
      if (state.drawing && state.drawing.type === 'polygon') {
        e.preventDefault();
        if (state.drawing.points.length > 1) {
          state.drawing.points.pop();
          redraw();
        } else {
          cancelDrawing();
        }
        return;
      }
      if (state.mode === 'select' && state.selection) {
        e.preventDefault();
        deleteSelected();
        return;
      }
    }
    if (e.key === 'Delete' && state.selection) { deleteSelected(); return; }
    if (e.key === 'Enter' && state.drawing && state.drawing.type === 'polygon') {
      commitPolygon(); return;
    }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); navigateImage(-1); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); navigateImage(1);  return; }
    if (e.key === 'd' || e.key === 'D') { markCurrentDone(); return; }
    if (e.key === 'p' || e.key === 'P') { setMode('polygon'); return; }
    if (e.key === 'b' || e.key === 'B') { setMode('box'); return; }
    if (e.key === 'v' || e.key === 'V') { setMode('select'); return; }
    if (/^[1-9]$/.test(e.key)) {
      const idx = parseInt(e.key, 10) - 1;
      if (state.classes[idx]) { state.selectedClass = state.classes[idx].id; renderClassList(); }
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      saveAnnotations(false);
    }
  });

  function navigateImage(delta) {
    const next = state.currentIndex + delta;
    if (next >= 0 && next < state.allImages.length) selectImage(next);
  }

  function markCurrentDone() {
    if (state.currentIndex < 0) return;
    const ann = currentAnn();
    ann.status = 'done';
    state.allImages[state.currentIndex].status = 'done';
    markDirty();
    renderImageList();
    saveAnnotations(false);
  }

  function deleteSelected() {
    if (!state.selection) return;
    const ann = currentAnn();
    if (state.selection.type === 'segment') ann.segments.splice(state.selection.idx, 1);
    if (state.selection.type === 'box') ann.boxes.splice(state.selection.idx, 1);
    state.selection = null;
    markDirty();
    renderAnnotationList(); redraw();
  }

  function setMode(m) {
    state.mode = m;
    state.drawing = null;
    state.selection = null;
    [el.modePolygon, el.modeBox, el.modeSelect].forEach(b => b.classList.remove('active'));
    if (m === 'polygon') el.modePolygon.classList.add('active');
    if (m === 'box') el.modeBox.classList.add('active');
    if (m === 'select') el.modeSelect.classList.add('active');
    setDrawStatus(
      m === 'polygon' ? 'Polygon mode · Click to add vertices · Double-click to close · Esc to cancel' :
      m === 'box' ? 'Box mode · Drag to draw a rectangle · Esc to cancel' :
      'Select mode · Click an annotation to edit · Delete to remove'
    );
    redraw();
  }

  el.modePolygon.addEventListener('click', () => setMode('polygon'));
  el.modeBox.addEventListener('click', () => setMode('box'));
  el.modeSelect.addEventListener('click', () => setMode('select'));
  el.saveBtn.addEventListener('click', () => saveAnnotations(false));
  el.prevBtn.addEventListener('click', () => navigateImage(-1));
  el.nextBtn.addEventListener('click', () => navigateImage(1));
  el.markDoneBtn.addEventListener('click', markCurrentDone);
```

- [ ] **Step 5: Browser smoke test**

Run: `python flask-annotator/app.py`. Open the page with a model. Expected:
- Click on the canvas adds vertex dots; line connects them.
- Double-click closes the polygon and it appears in the annotation list count.
- Pressing P/B/V switches modes (status bar text updates).
- Esc cancels an in-progress polygon.
- Backspace removes the last vertex.
- Arrow keys navigate between images, auto-saving on switch.
- Pressing D marks done and saves.
- No JS console errors.

- [ ] **Step 6: Commit**

```bash
git add flask-annotator/static/js/annotator.js
git commit -m "feat(annotator): polygon drawing mode + keyboard shortcuts"
```

---

## Task 14: Frontend — box drawing mode

**Files:**
- Modify: `flask-annotator/static/js/annotator.js`

- [ ] **Step 1: Add box drawing handlers**

In `annotator.js`, append to the `// ── Drawing: shared` section (after `function distance(...)`):

```javascript
  // ── Drawing: box ───────────────────────────────────────────────────────────
  let boxDragStart = null;

  function startBoxAt(p) {
    boxDragStart = p;
    state.drawing = { type: 'box', classId: state.selectedClass, x: p.x, y: p.y, w: 0, h: 0 };
    setDrawStatus('Drag to size · release to commit · Esc to cancel');
    redraw();
  }

  function updateBoxDrag(p) {
    if (!boxDragStart || !state.drawing || state.drawing.type !== 'box') return;
    const x1 = Math.min(boxDragStart.x, p.x);
    const y1 = Math.min(boxDragStart.y, p.y);
    const x2 = Math.max(boxDragStart.x, p.x);
    const y2 = Math.max(boxDragStart.y, p.y);
    state.drawing.x = x1; state.drawing.y = y1;
    state.drawing.w = x2 - x1; state.drawing.h = y2 - y1;
    redraw();
  }

  function commitBox() {
    const d = state.drawing;
    boxDragStart = null;
    if (!d || d.type !== 'box' || d.w < 4 || d.h < 4) {
      state.drawing = null; redraw(); return;
    }
    const ann = currentAnn();
    ann.boxes.push({
      id: shortId('b'),
      classId: d.classId,
      x: Math.round(d.x), y: Math.round(d.y),
      w: Math.round(d.w), h: Math.round(d.h),
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    state.allImages[state.currentIndex].status = ann.status;
    state.drawing = null;
    markDirty();
    renderAnnotationList(); renderImageList(); redraw();
    setDrawStatus('Box saved. Drag to start a new one.');
  }
```

- [ ] **Step 2: Extend `redraw()` to render the in-progress box**

Inside `redraw()`, after the existing in-progress polygon block, append:

```javascript
    if (state.drawing && state.drawing.type === 'box' && state.drawing.w > 0 && state.drawing.h > 0) {
      const col = classColor(state.drawing.classId);
      drawLayer.add(new Konva.Rect({
        x: state.drawing.x, y: state.drawing.y, width: state.drawing.w, height: state.drawing.h,
        stroke: col, strokeWidth: 2, fill: col + '33', dash: [6, 4],
      }));
    }
```

- [ ] **Step 3: Wire up stage mousedown / mousemove / mouseup for box mode**

Replace `bindStageEvents()` with:

```javascript
  function bindStageEvents() {
    stage.on('mousedown touchstart', () => {
      if (state.currentIndex < 0) return;
      if (state.mode !== 'box') return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      startBoxAt(p);
    });

    stage.on('mousemove touchmove', () => {
      if (state.mode !== 'box' || !boxDragStart) return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      updateBoxDrag(p);
    });

    stage.on('mouseup touchend', () => {
      if (state.mode !== 'box') return;
      if (boxDragStart) commitBox();
    });

    stage.on('click', () => {
      if (state.currentIndex < 0) return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      if (state.mode === 'polygon') {
        if (!state.drawing) {
          startPolygonAt(p);
          return;
        }
        const first = state.drawing.points[0];
        const pos = stage.getPointerPosition();
        const firstScreen = imgLayer.getAbsoluteTransform().point(first);
        if (state.drawing.points.length >= 3 &&
            distance(pos.x, pos.y, firstScreen.x, firstScreen.y) < 10) {
          commitPolygon();
          return;
        }
        addPolygonVertex(p);
        return;
      }
      if (state.mode === 'select') {
        // Clicking empty stage deselects (clicks on shapes are handled by the shape's own click handler).
        if (state.selection) { state.selection = null; redraw(); }
      }
    });

    stage.on('dblclick', () => {
      if (state.mode !== 'polygon' || !state.drawing) return;
      if (state.drawing.points.length > 3) state.drawing.points.pop();
      commitPolygon();
    });
  }
```

Also extend the Esc handler in the keydown listener: replace `if (state.drawing) cancelDrawing();` with:

```javascript
      if (state.drawing) {
        if (state.drawing.type === 'box') boxDragStart = null;
        cancelDrawing();
      }
```

- [ ] **Step 4: Browser smoke test**

Run: `python flask-annotator/app.py`. Visit a model. Press B for box mode. Drag a rectangle on the image. Expected:
- Dashed rectangle follows the cursor while dragging.
- On release, the box solidifies in the class color and appears in the annotations list.
- Saving the page and reloading restores the box.
- Switching to V (Select) lets you click the box (it gets a Transformer with resize handles).
- Pressing Delete removes the selected box.

- [ ] **Step 5: Commit**

```bash
git add flask-annotator/static/js/annotator.js
git commit -m "feat(annotator): box drawing + select/transform mode"
```

---

## Task 15: Frontend — class picker rendering + class manager modal

**Files:**
- Modify: `flask-annotator/static/js/annotator.js`

- [ ] **Step 1: Replace the empty `renderClassList()` and `renderAnnotationList()` stubs and add modal logic**

Find the empty stubs `function renderClassList() {}` and `function renderAnnotationList() {}` (Task 11 added them; Task 12 left them alone). Replace both with the full implementations below:

```javascript
  // ── Render: class picker ───────────────────────────────────────────────────
  function renderClassList() {
    el.classList.innerHTML = '';
    if (state.classes.length === 0) {
      el.classList.innerHTML = '<div class="no-images" style="padding:8px">No classes. Click + Add class.</div>';
      return;
    }
    state.classes.forEach((cls, idx) => {
      const row = document.createElement('div');
      row.className = 'class-row' + (cls.id === state.selectedClass ? ' selected' : '');
      row.innerHTML =
        '<span class="class-dot" style="background:' + (cls.color || PALETTE[idx % PALETTE.length]) + '"></span>' +
        '<span class="class-name">' + (idx + 1) + '. ' + escapeHTML(cls.name) + '</span>' +
        '<button class="class-edit" title="Edit class">⚙</button>';
      row.addEventListener('click', e => {
        if (e.target.classList.contains('class-edit')) return;
        state.selectedClass = cls.id;
        renderClassList();
      });
      row.querySelector('.class-edit').addEventListener('click', e => {
        e.stopPropagation();
        openClassModal(cls);
      });
      el.classList.appendChild(row);
    });
  }

  function escapeHTML(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // ── Render: annotations list ───────────────────────────────────────────────
  function renderAnnotationList() {
    const ann = currentAnn();
    if (!ann) { el.annoList.innerHTML = ''; el.segCount.textContent = '0'; return; }
    const items = [];
    (ann.segments || []).forEach((s, idx) => items.push({ kind: 'segment', idx, label: '△ ' + className(s.classId), color: classColor(s.classId), info: s.points.length + ' pts' }));
    (ann.boxes || []).forEach((b, idx) => items.push({ kind: 'box', idx, label: '▭ ' + className(b.classId), color: classColor(b.classId), info: b.w + 'x' + b.h }));
    el.segCount.textContent = items.length;
    el.annoList.innerHTML = '';
    items.forEach(it => {
      const isSel = state.selection && state.selection.type === it.kind && state.selection.idx === it.idx;
      const row = document.createElement('div');
      row.className = 'anno-item' + (isSel ? ' selected' : '');
      row.innerHTML =
        '<span style="display:flex;align-items:center;gap:6px">' +
        '<span class="class-dot" style="background:' + it.color + '"></span>' +
        escapeHTML(it.label) + ' <span style="color:var(--muted)">(' + it.info + ')</span>' +
        '</span>' +
        '<button class="del-btn" title="Delete">×</button>';
      row.addEventListener('click', e => {
        if (e.target.classList.contains('del-btn')) return;
        state.selection = { type: it.kind, idx: it.idx };
        if (state.mode !== 'select') setMode('select');
        renderAnnotationList(); redraw();
      });
      row.querySelector('.del-btn').addEventListener('click', e => {
        e.stopPropagation();
        const a = currentAnn();
        if (it.kind === 'segment') a.segments.splice(it.idx, 1);
        if (it.kind === 'box') a.boxes.splice(it.idx, 1);
        state.selection = null;
        markDirty(); renderAnnotationList(); redraw();
      });
      el.annoList.appendChild(row);
    });
  }

  // ── Class manager modal ────────────────────────────────────────────────────
  function nextClassId() {
    return state.classes.reduce((m, c) => Math.max(m, c.id), -1) + 1;
  }
  function nextClassColor() {
    return PALETTE[state.classes.length % PALETTE.length];
  }
  function countAnnotationsForClass(classId) {
    let n = 0;
    Object.values(state.annMap).forEach(a => {
      n += (a.segments || []).filter(s => s.classId === classId).length;
      n += (a.boxes || []).filter(b => b.classId === classId).length;
    });
    return n;
  }

  function openClassModal(existing) {
    const isNew = !existing;
    const editing = existing
      ? { ...existing }
      : { id: nextClassId(), name: '', color: nextClassColor() };
    const usage = isNew ? 0 : countAnnotationsForClass(existing.id);
    const reassignTarget = isNew ? null : state.classes.find(c => c.id !== existing.id);

    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML =
      '<div class="modal" role="dialog" aria-label="Annotation Editor">' +
      '<h2>' + (isNew ? 'Add class' : 'Edit class') + '</h2>' +
      '<label>Name</label>' +
      '<input type="text" id="m_name" value="' + escapeHTML(editing.name) + '" />' +
      '<label>Color</label>' +
      '<div class="palette" id="m_palette"></div>' +
      '<input type="text" id="m_color" value="' + editing.color + '" placeholder="#rrggbb" />' +
      '<div class="actions">' +
      (isNew ? '' : '<button class="btn btn-warn" id="m_delete" type="button">Delete</button>') +
      '<div style="flex:1"></div>' +
      '<button class="btn" id="m_cancel" type="button">Cancel</button>' +
      '<button class="btn btn-cyan" id="m_save" type="button">Save (Enter)</button>' +
      '</div></div>';
    el.modalRoot.appendChild(backdrop);

    const palette = backdrop.querySelector('#m_palette');
    PALETTE.forEach(col => {
      const sw = document.createElement('div');
      sw.className = 'swatch' + (col === editing.color ? ' selected' : '');
      sw.style.background = col;
      sw.addEventListener('click', () => {
        editing.color = col;
        backdrop.querySelector('#m_color').value = col;
        palette.querySelectorAll('.swatch').forEach(s => s.classList.toggle('selected', s.style.backgroundColor === hexToRgb(col)));
      });
      palette.appendChild(sw);
    });

    backdrop.querySelector('#m_color').addEventListener('input', e => { editing.color = e.target.value; });
    backdrop.querySelector('#m_name').focus();

    function close() { el.modalRoot.removeChild(backdrop); }

    function saveClass() {
      const name = backdrop.querySelector('#m_name').value.trim();
      if (!name) { backdrop.querySelector('#m_name').focus(); return; }
      editing.name = name;
      if (isNew) {
        state.classes.push(editing);
        state.selectedClass = editing.id;
      } else {
        const i = state.classes.findIndex(c => c.id === existing.id);
        if (i >= 0) state.classes[i] = editing;
      }
      markDirty();
      renderClassList(); renderAnnotationList(); redraw();
      close();
    }

    backdrop.querySelector('#m_save').addEventListener('click', saveClass);
    backdrop.querySelector('#m_cancel').addEventListener('click', close);
    backdrop.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); saveClass(); }
      if (e.key === 'Escape') { close(); }
    });

    const delBtn = backdrop.querySelector('#m_delete');
    if (delBtn) {
      delBtn.addEventListener('click', () => {
        if (state.classes.length <= 1) {
          alert('Cannot delete the last remaining class.');
          return;
        }
        const target = reassignTarget;
        const msg = usage > 0
          ? `Delete class "${existing.name}"? ${usage} annotation(s) will be reassigned to "${target.name}".`
          : `Delete class "${existing.name}"?`;
        if (!confirm(msg)) return;
        if (usage > 0) {
          Object.values(state.annMap).forEach(a => {
            (a.segments || []).forEach(s => { if (s.classId === existing.id) s.classId = target.id; });
            (a.boxes || []).forEach(b => { if (b.classId === existing.id) b.classId = target.id; });
          });
        }
        state.classes = state.classes.filter(c => c.id !== existing.id);
        if (state.selectedClass === existing.id) state.selectedClass = state.classes[0].id;
        markDirty();
        renderClassList(); renderAnnotationList(); redraw();
        close();
      });
    }
  }

  function hexToRgb(hex) {
    // For comparing palette swatch backgroundColor (browsers serialize as rgb()).
    const v = hex.replace('#', '');
    const r = parseInt(v.substr(0,2), 16);
    const g = parseInt(v.substr(2,2), 16);
    const b = parseInt(v.substr(4,2), 16);
    return `rgb(${r}, ${g}, ${b})`;
  }

  el.classAddBtn.addEventListener('click', () => openClassModal(null));
```

- [ ] **Step 2: Make sure `renderClassList()` and `renderAnnotationList()` are called on init**

In `init()`, after `await loadImageList();`, ensure both calls are present:

```javascript
      renderClassList();
      renderAnnotationList();
```

- [ ] **Step 3: Browser smoke test**

Run: `python flask-annotator/app.py`. Visit `/?model=<m>`. Expected:
- Class list shows the existing classes (color dot + numbered name + ⚙).
- Click a class row to set as active (cyan border).
- Click ⚙ to open the modal — inputs prefilled, palette swatches, Save/Delete/Cancel buttons.
- "+ Add class" opens the modal in create mode.
- Saving a new class adds it to the list and selects it.
- Deleting a class with annotations prompts with reassignment count; confirming reassigns and removes.
- Annotations list shows all segments and boxes with type markers and class colors. Click a row → selects the annotation on canvas. × deletes it.
- Pressing 1–9 quick-selects classes by index.

- [ ] **Step 4: Commit**

```bash
git add flask-annotator/static/js/annotator.js
git commit -m "feat(annotator): class picker, manager modal, annotations list"
```

---

## Task 16: PHP redirect + manual smoke checklist

**Files:**
- Modify: `annotate.php` (root of repo)
- Create: `flask-annotator/docs/manual-smoke.md`

- [ ] **Step 1: Replace `annotate.php` with a redirect**

Open `annotate.php`. Replace its entire content with:

```php
<?php
// Forwards to the Flask annotator on localhost:5001. The slug filter mirrors
// flask-annotator/slug.py so the same model name reaches the same folder.
$model = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_GET['model'] ?? '')));
$qs = $model !== '' ? '?model=' . urlencode($model) : '';
header('Location: http://localhost:5001/' . $qs, true, 302);
exit;
```

- [ ] **Step 2: Smoke test the redirect**

In a browser visit `http://localhost/stayontrails/annotate.php?model=demo`. Expected: redirected to `http://localhost:5001/?model=demo`. The Flask UI loads.

- [ ] **Step 3: Create the manual smoke checklist**

Create `flask-annotator/docs/manual-smoke.md`:

```markdown
# Manual smoke checklist

Run before each commit that touches frontend code, and after merging branches.

## Setup
- [ ] `python flask-annotator/app.py` starts without errors.
- [ ] `pytest flask-annotator/tests -v` is green.

## Drawing
- [ ] Open `/?model=<existing>` — image list populates, status badges look right.
- [ ] Click an image — it loads centered/letterboxed in the canvas.
- [ ] Polygon mode (P): click vertices, double-click to close. The polygon appears in the right-panel list with the correct class.
- [ ] Box mode (B): drag a rectangle, release. The box appears in the list.
- [ ] Select mode (V): click a box → Transformer handles appear; resize and confirm new size persists. Click a polygon → vertex handles appear; drag a vertex.
- [ ] Esc cancels in-progress drawing. Backspace removes last vertex while drawing.
- [ ] Delete key removes the selected annotation.

## Classes
- [ ] + Add class opens the modal. Save with a new name + color → it appears in the list.
- [ ] Click ⚙ on a class → modal pre-filled. Rename and save → list updates.
- [ ] Delete a class with 0 annotations → simple confirmation, class removed.
- [ ] Delete a class with N annotations → reassignment confirmation, target class noted, annotations updated.
- [ ] Cannot delete the last remaining class.
- [ ] Keyboard 1–9 quick-selects classes.

## Save / load
- [ ] Save button → "Saving…" → "Saved." (green). Reload — annotations persist.
- [ ] Switch images mid-draw → in-progress polygon discarded, previous image auto-saved silently.
- [ ] Edit something, try to close the tab → `beforeunload` warning fires.
- [ ] Stop the Flask server, click Save → red "Save failed: …" appears, button re-enabled.

## Status & navigation
- [ ] First annotation on an unlabeled image flips status to in-progress (yellow badge).
- [ ] D / Mark Done flips status to done (green badge).
- [ ] Arrow keys move prev/next.

## Bad data paths
- [ ] Corrupt `annotations.json`: app shows yellow banner, doesn't crash; first save rotates the bad file aside.
- [ ] Unknown model in URL: 404 page (matches PHP error look).
```

- [ ] **Step 4: Commit**

```bash
git add annotate.php flask-annotator/docs/manual-smoke.md
git commit -m "feat(annotator): redirect annotate.php to Flask + smoke checklist"
```

---

## Task 17: Final pass — verify all backend tests + cleanup

**Files:**
- Run tests; potentially small fixes

- [ ] **Step 1: Run the full test suite**

Run: `pytest flask-annotator/tests -v`
Expected: all tests across `test_slug.py`, `test_annotations.py`, `test_routes.py` PASS. Target: 25+ tests, runtime < 5s.

- [ ] **Step 2: Run the manual smoke checklist**

Walk through `flask-annotator/docs/manual-smoke.md` end to end on a real model with at least 5 frames. Note any deviations and fix before committing.

- [ ] **Step 3: Confirm `annotate.php` redirect path-matches**

Run: `php -r 'echo preg_replace("/[^a-z0-9-]/", "", strtolower("My Trail!"));'`
Expected: `mytrail` (matches our slugify behavior of "M y T rai l!" → "my-trail" except the PHP version drops hyphens too because the regex is `[^a-z0-9-]` not `[^a-z0-9]`. Note: this is intentional — PHP only strips invalid chars, doesn't insert hyphens. The redirect just lower-cases and removes punctuation; the slug "my-trail" stays "my-trail" through the redirect, and our Flask slugify is idempotent on already-clean slugs.

- [ ] **Step 4: Verify everything is committed**

Run: `git status`
Expected: clean working tree (only `flask-annotator/` and `docs/superpowers/` and the modified `annotate.php` show up in `git log` since the design commit).

- [ ] **Step 5: Final commit if anything was fixed during smoke testing**

```bash
git add -A flask-annotator/
git commit -m "fix(annotator): post-smoke fixes"
```

(Skip this commit if smoke testing produced no changes.)

---

## Self-review notes (against spec)

- **§3 Architecture:** covered by Tasks 1, 5 (`config.py` / `app.py` / port 5001).
- **§4 Schema (v1→v2 normalize, v2 default scaffold, color cycling, future-version handling):** Tasks 3, 4.
- **§4.4 Migration rules (don't rewrite v1 on read; rotate corrupt to `.broken-<ts>`; future schema read-only):** Tasks 3, 4, 11 (banner UX).
- **§5.1 Page routes:** Task 5.
- **§5.2 Image route:** Task 8.
- **§5.3 JSON API:** Tasks 5, 6, 7.
- **§5.4 Validation (slug guard, 400 on bad JSON, 404 on missing model, safe-filename guard):** Tasks 2, 5, 7, 8.
- **§6.1 Layout (topbar/page-header/three-panel grid):** Tasks 9, 10.
- **§6.2 Konva stage / image layer / annotations layer / drawing layer:** Tasks 12, 13, 14.
- **§6.3 Drawing modes (polygon/box/select with full keyboard):** Tasks 13, 14.
- **§6.4 Class picker + manage modal (create/edit/delete with reassignment):** Task 15.
- **§6.5 Annotations list:** Task 15.
- **§6.6 Bottom actions (Save/Prev/Next/Mark Done):** Task 13 (handlers wired).
- **§6.7 Keyboard shortcuts:** Task 13.
- **§6.8 State model:** Task 11.
- **§7.1–7.4 Atomic save / save UX / read failures / path-traversal:** Tasks 4, 7, 8, 11 (banner).
- **§8 Testing (12–15 backend tests, manual frontend checklist):** Tasks 2, 3, 4, 5, 6, 7, 8, 16.
- **§9 File layout:** Task 1.
- **§10 Rollout (PHP redirect):** Task 16.
