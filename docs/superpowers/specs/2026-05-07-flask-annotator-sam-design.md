# Flask Annotator — Phase 3: SAM auto-segmentation (Design)

**Date:** 2026-05-07
**Status:** Approved (auto-accept; no clarifying-question round)
**Builds on:** `2026-05-04-flask-annotator-design.md`

## 1. Goal

Add a "Smart Polygon" tool to the existing Flask annotator: the user clicks a single point on the image, and the Segment Anything Model (SAM, `vit_b`) returns a polygon outline around the object under that point. The user can adjust vertices and accept, or discard.

The intent is to dramatically speed up labeling of paths — a single click should produce a usable polygon for >70% of frames, with manual touch-ups taking a few seconds rather than building a polygon from scratch.

## 2. Scope and non-goals

**In scope**
- Backend SAM service: lazy-loaded SAM `vit_b` predictor, single-positive-point inference.
- Image embedding cache so successive clicks on the same image reuse the heavy `set_image()` call.
- Two endpoints: `GET /api/sam/status`, `POST /api/sam/predict`.
- Mask → polygon conversion via OpenCV (`findContours` + `approxPolyDP`).
- Frontend "Smart" mode (✨, **S** key) with loading overlay and Enter-to-accept / Esc-to-discard preview.
- "Disabled" banner if SAM dependencies or checkpoint are missing — UI keeps working without it.
- Schema: tag SAM-produced segments with `source: "sam"` (existing reserved field).
- Tests with monkey-patched predictor (no real model load).

**Non-goals**
- Negative points, multi-point prompts, box prompts. Single positive point only.
- Mask refinement UI. The user adjusts via existing polygon vertex handles.
- Pre-annotation (auto-label all images in a batch). That is Phase 4 territory.
- YOLO training, dataset export, deploy. Separate phase.

## 3. Architecture

A new module `flask-annotator/sam_service.py` owns the SAM predictor lifecycle. It exposes:

- `is_available() -> bool` — true when imports + checkpoint resolve. Cheap.
- `status() -> dict` — `{available, loaded, device, error}`. Cheap.
- `predict_polygon(image_path: Path, point: tuple[int, int]) -> dict` — `{polygon: [[x,y], ...], score: float}`. Loads the model on first call. Caches embeddings per `(image_path, mtime)`.

`segment_anything` and `torch` are imported **lazily** inside `_load_predictor()`. If they are missing, `is_available()` returns false; the rest of the app keeps working. This means the service file is safe to import unconditionally.

Two routes are added to `app.py`:

- `GET /api/sam/status` — returns `sam_service.status()`. Never triggers a load.
- `POST /api/sam/predict` — body `{model, image, point: [x, y]}`. Validates inputs (slug, safe filename, point in [0,640)×[0,480)), then calls `predict_polygon`. Returns 503 if not available, 400 on bad input, 500 on inference failure.

Frontend gains a fourth mode (Smart). When active, a click triggers `POST /api/sam/predict`; the response polygon is rendered as a dashed preview in `drawLayer`. **Enter** commits it as a normal segment with `source: "sam"`, **Esc** discards.

## 4. SAM checkpoint

`vit_b` checkpoint (`sam_vit_b_01ec64.pth`, ~375 MB) lives at `flask-annotator/models/sam/sam_vit_b_01ec64.pth`. The checkpoint is gitignored (it already falls under `flask-annotator/models/`).

A helper script `flask-annotator/scripts/download_sam.py` fetches the checkpoint from Meta's official release URL, writing into `flask-annotator/models/sam/`. It is idempotent and prints a hash check.

## 5. Mask → polygon

After SAM returns three masks, we pick the one with the highest score. Conversion steps:

1. `cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)`
2. Pick the largest contour by area. If the largest is < 50 px², return error (mask too small).
3. `epsilon = max(1.5, 0.005 * cv2.arcLength(contour, True))` — tunable, ~0.5% of perimeter.
4. `cv2.approxPolyDP(contour, epsilon, closed=True)`
5. Cap at 100 vertices (truncate if more — extremely rare with epsilon set).
6. Round to integers. Return as `[[x, y], …]`.

## 6. Embedding cache

`predict_polygon` caches one entry: `(image_path, mtime) → predictor with set_image already called`. New image → reset cache, call `set_image()` again. This is the single biggest perf win: clicks on the same frame skip the ~300–800 ms encode step.

A simple module-level dict with one entry suffices. No LRU, no eviction policy — only one user, one image at a time.

## 7. HTTP API details

### 7.1 `GET /api/sam/status`

Always 200. Body:

```json
{
  "ok": true,
  "available": true,
  "loaded": false,
  "device": "cuda",
  "error": null
}
```

`available` is true iff imports succeed and checkpoint exists. `loaded` is true after the first successful predict. `error` is a short string when `available` is false (`"torch not installed"`, `"checkpoint missing at <path>"`, etc.).

### 7.2 `POST /api/sam/predict`

Request body:

```json
{ "model": "demo", "image": "frame-0001.jpg", "point": [320, 240] }
```

Validation:
- `model` runs through `slugify`. Empty → 400.
- `image` runs through `is_safe_filename`. Bad → 400.
- `point` must be `[int, int]` with `0 <= x < 640`, `0 <= y < 480`. Otherwise 400.
- File must exist under `recorded_routes/<model>/<image>`. Otherwise 404.
- If `is_available()` is false → 503 with `error` field.

Success response (200):

```json
{
  "ok": true,
  "polygon": [[120, 200], [340, 198], [400, 305], [125, 312]],
  "score": 0.91
}
```

Failure (mask too small, no contour, etc.) → 500 with `{ok: false, error: "..."}`.

## 8. Frontend changes

### 8.1 Mode toolbar

Add a fourth button between Box and Select: `✨ Smart`. Same styling as existing mode buttons. Active state when `state.mode === 'sam'`.

### 8.2 SAM availability banner

On `init()`, after `loadAnnotations()`, call `GET /api/sam/status`. If `available` is false, show a warning banner under the existing one: `"Smart Polygon disabled: <error>. Manual tools still work."` The Smart button is grayed out (disabled).

### 8.3 Smart click flow

1. User selects Smart mode (button or **S** key).
2. `setDrawStatus('Smart mode · Click an object · Loading is shown while SAM runs')`.
3. On stage click in image bounds:
   - Compute integer `[x, y]` in image space.
   - Show a loading overlay over the canvas (semi-transparent dim with "Predicting…" text).
   - `POST /api/sam/predict` with the click.
   - On success, store `state.drawing = { type: 'sam', classId, points: [...], score }`.
   - On error, hide overlay, show inline error in `#drawStatus` for 4 s.
4. Render preview: dashed line in class color, vertex dots like the in-progress polygon.
5. **Enter** or click ✔ button → commit as a normal `segment` with `source: "sam"`, status flips to in-progress, badge updates.
6. **Esc** → discard.

### 8.4 Loading overlay

A new `<div id="canvasOverlay">` inside `.canvas-wrap`, hidden by default. Two new CSS rules. Shown only during in-flight SAM requests. Click events on it don't reach the stage.

### 8.5 Schema write

When committing a SAM polygon: `seg.source = 'sam'`. Manual segments stay without `source` (treated as human by convention). The save payload doesn't strip unknown fields, so `source` round-trips through PUT/GET.

### 8.6 Keyboard

- **S** key toggles Smart mode (only when SAM available; otherwise ignored).
- **Enter** while a SAM preview is on screen commits it (this overrides the existing polygon-Enter-commit because they don't coexist).
- **Esc** discards (existing behavior already covers it via `cancelDrawing`).

## 9. Tests

Backend tests under `flask-annotator/tests/test_sam.py`:

- `test_status_when_unavailable` — without monkey-patching, expect `available=False`.
- `test_status_when_available` — monkey-patch `sam_service.is_available` to return True; expect `available=True`.
- `test_predict_validates_model` — bad slug → 400.
- `test_predict_validates_filename` — `../etc/passwd` → 400.
- `test_predict_validates_point_bounds` — out-of-bounds point → 400.
- `test_predict_unknown_model` — non-existent folder → 404.
- `test_predict_unknown_image` — non-existent image file → 404.
- `test_predict_returns_503_when_unavailable` — without monkey-patch → 503.
- `test_predict_returns_polygon` — monkey-patch `sam_service.predict_polygon` to return a fixed polygon; expect 200 with that polygon.

We do **not** test SAM model behavior. The service layer's responsibility ends at "call the predictor and convert mask to polygon." That logic is verified in a separate unit test using a hand-built numpy mask:

- `test_mask_to_polygon_simplifies` — a 100×100 square mask → ~4-vertex polygon.
- `test_mask_to_polygon_rejects_tiny` — 3×3 mask → raises `MaskTooSmall`.

These tests don't import torch.

## 10. Dependencies

`flask-annotator/requirements.txt` adds:

```
numpy>=1.26
opencv-python-headless>=4.9
Pillow>=10.0
```

`torch` and `segment-anything` are **not** in requirements.txt. They are heavy and platform-specific (CUDA build matters). The README documents the install once:

```
# CUDA 12.1 (recommended for the user's GPU)
pip install torch --index-url https://download.pytorch.org/whl/cu121
pip install git+https://github.com/facebookresearch/segment-anything.git
python scripts/download_sam.py
```

If those are not installed, `/api/sam/status` reports unavailable; the rest of the app is unaffected.

## 11. Files

**New**
- `flask-annotator/sam_service.py`
- `flask-annotator/scripts/download_sam.py`
- `flask-annotator/tests/test_sam.py`

**Modified**
- `flask-annotator/app.py` — two new routes
- `flask-annotator/requirements.txt` — numpy/opencv/Pillow
- `flask-annotator/static/js/annotator.js` — Smart mode, overlay, status check
- `flask-annotator/static/css/style.css` — overlay rule
- `flask-annotator/templates/index.html` — Smart button, overlay div
- `flask-annotator/README.md` — install notes for SAM
- `flask-annotator/docs/manual-smoke.md` — Smart Polygon section

## 12. Out of scope (deferred to later phases)

- Negative-point and multi-point prompts.
- "Pre-annotate all" batch flow.
- Server-side polygon refinement (e.g., active contours).
- Switching to `vit_l` or `vit_h`. `vit_b` is fast and accurate enough for trail paths.
