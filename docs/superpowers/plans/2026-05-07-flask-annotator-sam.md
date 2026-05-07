# Phase 3 — SAM Implementation Plan

**Goal:** Add Smart Polygon (SAM `vit_b`, single positive point) to the Flask annotator.

**Architecture:** New `sam_service.py` lazy-loads SAM. Two endpoints (`/api/sam/status`, `/api/sam/predict`). Frontend gains a fourth mode with loading overlay. Service degrades gracefully when torch/segment-anything/checkpoint are missing.

**Tech Stack:** PyTorch, segment-anything, OpenCV, NumPy, Pillow. Frontend stays vanilla JS + Konva.

---

## Task 1: Mask → polygon helper (pure function, no SAM)

**Files:**
- Create: `flask-annotator/sam_service.py` (skeleton + helper)
- Create: `flask-annotator/tests/test_sam.py`

Add a small pure function `mask_to_polygon(mask: np.ndarray) -> list[list[int]]` and `MaskTooSmall` exception. No SAM/torch imports here — only numpy and cv2.

`sam_service.py` skeleton:

```python
"""SAM auto-segmentation service.

Imports torch and segment_anything lazily so the rest of the app can run
without them installed. Top-level imports are limited to numpy and cv2.
"""
from __future__ import annotations

from pathlib import Path
from typing import Any

import numpy as np
import cv2

import config


CHECKPOINT_PATH = Path(__file__).resolve().parent / "models" / "sam" / "sam_vit_b_01ec64.pth"
MODEL_TYPE = "vit_b"
MIN_AREA = 50  # px^2 — reject masks that produce tiny contours


class MaskTooSmall(Exception):
    pass


def mask_to_polygon(mask: np.ndarray, max_vertices: int = 100) -> list[list[int]]:
    """Convert a binary mask to a simplified polygon.

    Steps: largest external contour → Douglas-Peucker simplification at
    epsilon = max(1.5, 0.5%·perimeter) → cap to max_vertices → integer rounding.
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
```

Test (`flask-annotator/tests/test_sam.py`):

```python
import numpy as np
import pytest

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
    # An irregular blob — synthesized circle with noise should stay under cap
    mask = np.zeros((300, 300), dtype=np.uint8)
    cy, cx, r = 150, 150, 100
    Y, X = np.ogrid[:300, :300]
    mask[((X - cx) ** 2 + (Y - cy) ** 2) <= r * r] = 1
    poly = mask_to_polygon(mask, max_vertices=50)
    assert len(poly) <= 50
    assert len(poly) >= 6
```

Run: `py -3 -m pytest flask-annotator/tests/test_sam.py -v`
Expected: 3 PASSED.

Commit: `feat: add mask_to_polygon helper for SAM phase 3`

---

## Task 2: SAM availability + status

Extend `sam_service.py` with `is_available()` and `status()`:

```python
def is_available() -> tuple[bool, str | None]:
    """Return (available, error_string).

    Available iff torch + segment_anything import and the checkpoint exists.
    Cheap: imports happen only once and the result isn't memoized so a user
    who installs deps mid-session sees the change without restarting.
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


_predictor = None
_cached_image_key: tuple | None = None
_device: str = "cpu"


def status() -> dict:
    available, error = is_available()
    return {
        "available": available,
        "loaded": _predictor is not None,
        "device": _device if _predictor is not None else _device_hint(),
        "error": error,
    }


def _device_hint() -> str:
    try:
        import torch
        return "cuda" if torch.cuda.is_available() else "cpu"
    except Exception:
        return "cpu"
```

Add tests:

```python
import sam_service


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
```

Run: `py -3 -m pytest flask-annotator/tests/test_sam.py -v`
Expected: 5 PASSED.

Commit: `feat: add SAM availability and status reporting`

---

## Task 3: SAM predictor + embedding cache

Extend `sam_service.py` with `_load_predictor()` and `predict_polygon()`:

```python
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
```

No new tests for this task — torch/SAM not assumed installed in CI. Manual verification documented in smoke checklist.

Run: `py -3 -m pytest flask-annotator/tests/test_sam.py -v`
Expected: still 5 PASSED.

Commit: `feat: add SAM predictor with image embedding cache`

---

## Task 4: Download script

Create `flask-annotator/scripts/download_sam.py`:

```python
"""Download the SAM vit_b checkpoint into flask-annotator/models/sam/.

Idempotent: skips if file already exists with the expected size.
"""
from __future__ import annotations

import hashlib
import sys
import urllib.request
from pathlib import Path

URL = "https://dl.fbaipublicfiles.com/segment_anything/sam_vit_b_01ec64.pth"
EXPECTED_SHA256 = "ec2df62732614e57411cdcf32a23ffdf28910380d03139ee0f4fcbe91eb8c912"
TARGET = Path(__file__).resolve().parent.parent / "models" / "sam" / "sam_vit_b_01ec64.pth"


def main() -> int:
    TARGET.parent.mkdir(parents=True, exist_ok=True)
    if TARGET.is_file():
        if _sha256(TARGET) == EXPECTED_SHA256:
            print(f"OK: {TARGET} already present and verified.")
            return 0
        print(f"Hash mismatch on existing file, re-downloading.")
        TARGET.unlink()

    print(f"Downloading SAM vit_b checkpoint (~375 MB) to {TARGET} …")
    urllib.request.urlretrieve(URL, TARGET, _progress)
    print()
    actual = _sha256(TARGET)
    if actual != EXPECTED_SHA256:
        print(f"ERROR: hash mismatch after download. expected={EXPECTED_SHA256} got={actual}")
        return 1
    print(f"OK: downloaded and verified {TARGET}")
    return 0


def _sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _progress(blocks: int, block_size: int, total: int) -> None:
    if total <= 0:
        return
    pct = min(100, blocks * block_size * 100 // total)
    print(f"\r  {pct:3d}%", end="", flush=True)


if __name__ == "__main__":
    sys.exit(main())
```

Run smoke check (no actual download): `py -3 -c "import ast; ast.parse(open('flask-annotator/scripts/download_sam.py').read())"`
Expected: no output (parse succeeded).

Commit: `feat: add SAM checkpoint download script`

---

## Task 5: Backend endpoints

Modify `flask-annotator/app.py`. After the `serve_image` route and before `return app`, add:

```python
    @app.get("/api/sam/status")
    def sam_status():
        import sam_service
        s = sam_service.status()
        return {"ok": True, **s}

    @app.post("/api/sam/predict")
    def sam_predict():
        import sam_service
        from slug import is_safe_filename

        body = request.get_json(silent=True)
        if not isinstance(body, dict):
            abort(400, description="Body must be a JSON object.")

        model = slugify(body.get("model", ""))
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        filename = body.get("image", "")
        if not isinstance(filename, str) or not is_safe_filename(filename):
            abort(400, description="Invalid filename.")
        image_path = model_dir / filename
        if not image_path.is_file():
            abort(404, description="Image not found.")

        point = body.get("point")
        if (
            not isinstance(point, list) or len(point) != 2
            or not all(isinstance(v, (int, float)) for v in point)
        ):
            abort(400, description="point must be [x, y].")
        x, y = int(point[0]), int(point[1])
        if not (0 <= x < 640 and 0 <= y < 480):
            abort(400, description="point out of image bounds.")

        available, error = sam_service.is_available()
        if not available:
            return {"ok": False, "error": error}, 503

        try:
            result = sam_service.predict_polygon(image_path, (x, y))
        except sam_service.MaskTooSmall as e:
            return {"ok": False, "error": str(e)}, 500
        except Exception as e:
            return {"ok": False, "error": f"{e.__class__.__name__}: {e}"}, 500

        return {"ok": True, **result}
```

No standalone tests yet — covered by Task 6.

Run `py -3 -c "from app import create_app; create_app()"` — expect no error.

Commit: `feat: wire /api/sam/status and /api/sam/predict endpoints`

---

## Task 6: Endpoint tests with mocked predictor

Append to `flask-annotator/tests/test_sam.py`:

```python
import sys
from pathlib import Path

from flask import Flask
from flask.testing import FlaskClient


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
    r = client.post("/api/sam/predict", json={"model": "", "image": "frame-0001.jpg", "point": [10, 10]})
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
        json={"model": "demo", "image": "frame-0001.jpg", "point": [700, 10]},
    )
    assert r.status_code == 400


def test_predict_404_unknown_model(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "no-such-model", "image": "frame-0001.jpg", "point": [10, 10]},
    )
    assert r.status_code == 404


def test_predict_404_unknown_image(client):
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame-9999.jpg", "point": [10, 10]},
    )
    assert r.status_code == 404


def test_predict_503_when_unavailable(client, monkeypatch):
    import sam_service
    monkeypatch.setattr(sam_service, "is_available", lambda: (False, "torch not installed"))
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame-0001.jpg", "point": [10, 10]},
    )
    assert r.status_code == 503
    assert r.get_json()["error"] == "torch not installed"


def test_predict_returns_polygon_when_mocked(client, monkeypatch):
    import sam_service
    monkeypatch.setattr(sam_service, "is_available", lambda: (True, None))
    monkeypatch.setattr(
        sam_service,
        "predict_polygon",
        lambda path, pt: {"polygon": [[10, 10], [30, 10], [30, 30], [10, 30]], "score": 0.9},
    )
    r = client.post(
        "/api/sam/predict",
        json={"model": "demo", "image": "frame-0001.jpg", "point": [20, 20]},
    )
    assert r.status_code == 200
    body = r.get_json()
    assert body["ok"] is True
    assert body["polygon"] == [[10, 10], [30, 10], [30, 30], [10, 30]]
    assert body["score"] == 0.9
```

Run: `py -3 -m pytest flask-annotator/tests/test_sam.py -v`
Expected: 14 PASSED (5 from earlier + 9 new endpoint tests).

Run full suite: `py -3 -m pytest flask-annotator/tests -v`
Expected: 57 PASSED (48 prior + 9 new).

Commit: `test: cover /api/sam/* endpoints with mocked predictor`

---

## Task 7: Frontend Smart mode

Modify `flask-annotator/templates/index.html`. Inside the mode toolbar, add a Smart button between Box and Select:

```html
<button class="btn" id="modeSmart" type="button" title="Smart Polygon (S)" disabled>✨ Smart</button>
```

Inside `.canvas-wrap`, after `<div id="canvasIdle">…</div>`, add:

```html
<div id="canvasOverlay" class="canvas-overlay" style="display:none">Predicting…</div>
```

Modify `flask-annotator/static/css/style.css`. Append:

```css
.canvas-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.55);color:#fff;font-weight:700;font-size:14px;letter-spacing:.04em;backdrop-filter:blur(2px);z-index:5;pointer-events:auto}
.canvas-overlay.error{color:var(--warn)}
.btn:disabled{opacity:.45;cursor:not-allowed}
```

Modify `flask-annotator/static/js/annotator.js`:

1. Add to `el` in the DOM refs block:
   ```js
   modeSmart: document.getElementById('modeSmart'),
   canvasOverlay: document.getElementById('canvasOverlay'),
   ```

2. Extend `state.mode` comment to include `'sam'` and add `samAvailable: false, samError: null` flags:
   ```js
   mode: 'polygon',         // 'polygon' | 'box' | 'select' | 'sam'
   samAvailable: false,
   samError: null,
   ```

3. After `loadAnnotations`, add `loadSamStatus()`:
   ```js
   async function loadSamStatus() {
     try {
       const d = await api('GET', '/api/sam/status');
       state.samAvailable = !!d.available;
       state.samError = d.error || null;
     } catch (e) {
       state.samAvailable = false;
       state.samError = e.message;
     }
     el.modeSmart.disabled = !state.samAvailable;
     if (!state.samAvailable && state.samError) {
       const msg = 'Smart Polygon disabled: ' + state.samError + '. Manual tools still work.';
       if (el.banner.style.display === 'block') {
         el.banner.textContent = el.banner.textContent + ' · ' + msg;
       } else {
         el.banner.style.display = 'block';
         el.banner.textContent = msg;
       }
     }
   }
   ```

4. In `setMode(m)`, add Smart cases:
   ```js
   if (m === 'sam') el.modeSmart.classList.add('active');
   ```
   And extend the `setDrawStatus` ternary:
   ```js
   m === 'sam' ? 'Smart mode · Click an object · Wait for SAM · Enter to keep, Esc to discard' :
   ```
   Reset Smart button class along with others:
   ```js
   [el.modePolygon, el.modeBox, el.modeSelect, el.modeSmart].forEach(b => b.classList.remove('active'));
   ```

5. Add a SAM click handler in `bindStageEvents`. Inside the `stage.on('click', …)` block, after the `polygon` branch, add a SAM branch:
   ```js
   if (state.mode === 'sam') {
     if (state.drawing) return; // ignore clicks while preview is up
     runSamAt(p);
     return;
   }
   ```

6. Add `runSamAt`:
   ```js
   async function runSamAt(p) {
     el.canvasOverlay.classList.remove('error');
     el.canvasOverlay.textContent = 'Predicting…';
     el.canvasOverlay.style.display = 'flex';
     try {
       const body = { model: MODEL, image: currentFile(), point: [Math.round(p.x), Math.round(p.y)] };
       const d = await api('POST', '/api/sam/predict', body);
       const points = (d.polygon || []).map(([x, y]) => ({ x, y }));
       if (points.length < 3) {
         throw new Error('polygon too small');
       }
       state.drawing = { type: 'sam', classId: state.selectedClass, points, score: d.score };
       setDrawStatus('SAM preview · Enter to keep · Esc to discard · Score ' + (d.score || 0).toFixed(2));
       redraw();
     } catch (e) {
       el.canvasOverlay.classList.add('error');
       el.canvasOverlay.textContent = 'SAM failed: ' + e.message;
       setTimeout(() => { el.canvasOverlay.style.display = 'none'; }, 2000);
       return;
     }
     el.canvasOverlay.style.display = 'none';
   }
   ```

7. Extend the `redraw()` in-progress section. After the polygon-preview branch, add:
   ```js
   if (state.drawing && state.drawing.type === 'sam') {
     const d = state.drawing;
     const col = classColor(d.classId);
     const flat = [];
     d.points.forEach(p => flat.push(p.x, p.y));
     drawLayer.add(new Konva.Line({
       points: flat, closed: true, stroke: col, strokeWidth: 2,
       fill: col + '4d', dash: [6, 4],
     }));
     d.points.forEach(p => {
       drawLayer.add(new Konva.Circle({ x: p.x, y: p.y, radius: 3, fill: col }));
     });
   }
   ```

8. Add a `commitSamPreview()` function near `commitPolygon`:
   ```js
   function commitSamPreview() {
     const d = state.drawing;
     if (!d || d.type !== 'sam' || d.points.length < 3) {
       state.drawing = null; redraw(); return;
     }
     const ann = currentAnn();
     ann.segments.push({
       id: shortId('s'),
       classId: d.classId,
       source: 'sam',
       points: d.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) })),
     });
     if (ann.status === 'unlabeled') ann.status = 'in-progress';
     state.allImages[state.currentIndex].status = ann.status;
     state.drawing = null;
     markDirty();
     renderAnnotationList(); renderImageList(); redraw();
     setDrawStatus('SAM polygon saved. Click again for another.');
   }
   ```

9. In the keydown handler:
   - The existing `Enter` handler only fires for `state.drawing.type === 'polygon'`. Extend it:
     ```js
     if (e.key === 'Enter' && state.drawing) {
       if (state.drawing.type === 'polygon') commitPolygon();
       else if (state.drawing.type === 'sam') commitSamPreview();
       return;
     }
     ```
   - Add S-key shortcut after the V-key handler:
     ```js
     if (e.key === 's' || e.key === 'S') {
       if (state.samAvailable) setMode('sam');
       return;
     }
     ```

10. Wire the button click:
    ```js
    el.modeSmart.addEventListener('click', () => { if (state.samAvailable) setMode('sam'); });
    ```

11. In `init()`, call `loadSamStatus()` after `loadImageList()` (so the banner from missing annotations doesn't get clobbered):
    ```js
    await loadImageList();
    await loadSamStatus();
    ```

Verify JS syntax: `node --check flask-annotator/static/js/annotator.js`
Expected: no output.

Commit: `feat: add Smart Polygon mode with SAM preview and overlay`

---

## Task 8: Docs and requirements

Update `flask-annotator/requirements.txt`:

```
flask==3.0.3
pytest==8.3.3
numpy>=1.26
opencv-python-headless>=4.9
Pillow>=10.0
```

Update `flask-annotator/README.md`:

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

## Smart Polygon (SAM)

Optional. Lets you click a point and have SAM (`vit_b`) generate a polygon
around the object under the cursor. Without this, the rest of the tool works
normally.

```bash
# 1. Install GPU PyTorch (Windows + CUDA 12.1)
pip install torch --index-url https://download.pytorch.org/whl/cu121

# 2. Install Meta's segment-anything package
pip install git+https://github.com/facebookresearch/segment-anything.git

# 3. Download the vit_b checkpoint (~375 MB) into flask-annotator/models/sam/
python scripts/download_sam.py
```

Restart the Flask server. The Smart button (✨, **S** key) becomes enabled
once `/api/sam/status` reports `available: true`.
```

Update `flask-annotator/docs/manual-smoke.md` — append a new section before "Bad data paths":

```markdown
## Smart Polygon (SAM)

- [ ] On a fresh page load, `GET /api/sam/status` returns `{available: true, device: "cuda"}` (or `cpu`). Smart button is enabled.
- [ ] If SAM dependencies missing, banner reads "Smart Polygon disabled: …" and Smart button is disabled.
- [ ] **S** key activates Smart mode; status bar updates.
- [ ] Click on a path in the image — overlay shows "Predicting…" — within ~1 s the dashed preview polygon appears.
- [ ] Repeated clicks on the same image are noticeably faster (embedding cache hit).
- [ ] Switching to a different image and clicking re-loads the embedding (slight delay).
- [ ] **Enter** commits the SAM polygon. The annotation list shows it; the JSON `segments` entry has `source: "sam"`.
- [ ] **Esc** discards the preview cleanly.
- [ ] Click in a featureless spot → SAM may return a tiny mask → overlay shows "SAM failed: …" briefly, no preview committed.
```

Run: `py -3 -m pytest flask-annotator/tests -v`
Expected: 57 PASSED (no regressions).

Commit: `docs: add SAM install notes and Smart Polygon smoke section`
