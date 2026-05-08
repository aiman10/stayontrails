# Flask Annotator — Architecture & File Guide

This is the dev's-eye view of how the annotator works. Read top to bottom
the first time. After that, jump straight to the section you need.

If you're looking for "how do I install / run / smoke-test it," that's in
the top-level [README](../README.md) and [docs/manual-smoke.md](manual-smoke.md).
This file is about *what each piece is and why it exists*.

---

## 1. The 60-second mental model

```
Browser (Konva canvas)
   ⇅ JSON over fetch()
Flask app (port 5001)
   ⇅ reads/writes
recorded_routes/<model>/
   - *.jpg                     (images, captured by recordroute.php)
   - annotations.json          (all polygons, boxes, classes)
   - reviewed.json             (set of reviewer-signed-off filenames)
flask-annotator/
   - models/sam/...pth         (SAM checkpoint, gitignored)
   - _datasets/<model>/        (YOLO dataset staging area)
   - runs/<model>/<run_id>/    (training output: state.json, best.pt)
```

Three independent capabilities ride on top of the same Flask app:

1. **Manual annotation** — polygon + box + class management, all done
   client-side in `annotator.js`. The server is a dumb JSON store.
2. **SAM auto-segmentation** — click a point, the server runs Segment
   Anything (`vit_b`) on the image, returns a polygon. Optional; gracefully
   degrades if torch / `segment_anything` / the checkpoint isn't installed.
3. **YOLO training** — once every image is marked done, export a YOLO
   segmentation dataset, spawn an Ultralytics subprocess, poll for
   progress, download the resulting `best.pt`.

There is no database. Every piece of state is a JSON file in
`recorded_routes/<model>/` or `flask-annotator/runs/<model>/`. The
trade-off is "no infra, fragile under concurrency" — fine for a single
labeler on a single machine.

---

## 2. Request lifecycle

When you open `http://localhost:5001/?model=test0705`:

1. `app.py:index` slugifies the model, checks the folder exists, renders
   `templates/index.html` with the model name injected into the page.
2. The HTML loads `static/js/annotator.js`, which fires `init()`.
3. `init()` calls four endpoints in sequence:
   - `GET /api/models/<model>/annotations` → loads classes + per-image
     annotations.
   - `GET /api/models/<model>/images` → list of jpgs with status +
     reviewed flags.
   - `GET /api/sam/status` → enables or disables the ✨ Smart button.
   - `GET /api/models/<model>/training/status` → enables or disables the
     Start Training button.
4. The first image auto-loads onto the Konva canvas. Drawing happens in
   the browser; saves go back via `PUT /api/models/<model>/annotations`.

`annotate.php` (in the project root) is just a 3-line redirect to
`localhost:5001`. Apache and Flask coexist; PHP keeps owning the rest of
the site.

---

## 3. Backend files (Python)

All paths below are relative to `flask-annotator/`.

### `app.py` — Flask routes (the only entry point)

This is the whole HTTP surface. Routes are grouped into five chunks:

| Group        | Routes                                                                  |
|--------------|-------------------------------------------------------------------------|
| Pages        | `GET /` (picker or annotator)                                           |
| Annotations  | `GET /api/models/<m>/images`, `GET /api/models/<m>/annotations`, `PUT /api/models/<m>/annotations` |
| Reviewed     | `POST /api/models/<m>/images/<f>/reviewed`                              |
| Images       | `GET /img/<m>/<f>`                                                      |
| SAM          | `GET /api/sam/status`, `POST /api/sam/predict`                          |
| Training     | `GET /api/models/<m>/training/status`, `POST /api/models/<m>/training/start`, `GET /api/models/<m>/training/runs/<id>`, `GET /api/models/<m>/training/runs/<id>/best.pt` |

Validation happens at the route level — `slugify()` and
`is_safe_filename()` reject path-traversal before touching the filesystem.
The handlers themselves are thin: they parse, validate, then delegate to
the helper modules below.

`create_app()` is a factory so the test suite can swap `config.RECORD_ROOT`
before the app is built.

### `config.py` — runtime configuration

Reads three env vars: `RECORD_ROOT`, `PORT`, `DEBUG`. The default
`RECORD_ROOT` is `<repo>/recorded_routes/`, which is where the PHP
`recordroute.php` writes captured frames.

### `slug.py` — input validation

- `slugify(s)` — collapses anything that isn't `[a-z0-9-]+`. Used on every
  `<model>` path parameter.
- `is_safe_filename(s)` — accepts `<basename>.(jpg|jpeg|png)`. Used on
  every image filename in URL paths.

These two functions are the entire path-traversal defense. Tested
exhaustively in `tests/test_slug.py`.

### `annotations.py` — load / save / migrate annotations.json

The JSON file in `recorded_routes/<model>/annotations.json` is the source
of truth for everything labeling-related. Schema:

```json
{
  "model": "test0705",
  "schemaVersion": 2,
  "classes": [{"id": 0, "name": "path-oxod", "color": "#7C3AED"}, ...],
  "images": [
    {
      "file": "frame_001.jpg",
      "width": 640, "height": 480,
      "status": "unlabeled" | "in-progress" | "done",
      "annotations": {
        "segments": [{"id": "s-abc123", "classId": 0, "source": "human"|"sam", "points": [{"x":..., "y":...}, ...]}],
        "boxes":    [{"id": "b-abc123", "classId": 1, "x":..., "y":..., "w":..., "h":...}]
      }
    }
  ],
  "splits": {"train": [...], "val": [...]}
}
```

Functions:

- `default_scaffold(model)` — fresh v2 file with the four default classes
  (path-oxod, grass, puddle, road).
- `normalize(data)` — fills in missing v2 fields without mutating the
  input. v1 files (no `schemaVersion`, no `boxes`, no class colors) get
  upgraded *in memory only*. The on-disk file isn't rewritten until a
  real save.
- `load_annotations(model_dir, model)` — returns
  `{status: "ok"|"missing"|"corrupt"|"future", data: <dict>}`. Never
  raises. A corrupt file gets defaults in memory; the bad bytes stay on
  disk until the next save rotates them aside.
- `save_annotations(model_dir, data)` — atomic temp-file-then-rename
  write. If the existing file is corrupt JSON, it's moved to
  `annotations.json.broken-<unix-ts>` first.

### `reviewed.py` — sign-off flag

Tiny module that owns `recorded_routes/<model>/reviewed.json`, a JSON
array of filenames a reviewer has marked reviewed. Independent from
`status: done`, which is the labeler's flag. Three functions:
`load_reviewed`, `save_reviewed`, `set_reviewed`.

### `sam_service.py` — Segment Anything bridge

Lazy-loaded so the rest of the app starts even if torch / `segment_anything`
/ the 375 MB checkpoint aren't present. Top-level imports are kept to
`numpy` and `cv2`.

- `is_available()` → `(bool, error_string)`. Probes torch and
  segment_anything imports + checkpoint existence. Cheap.
- `status()` → `{available, loaded, device, error}`. The frontend hits
  this on every page load.
- `_load_predictor()` — first-call import + GPU placement. Cached in a
  module global.
- `_set_image_cached(predictor, path)` — keys on `(path, mtime)`. The
  ~500 ms `set_image()` call only fires when you switch images, not on
  every click.
- `predict_polygon(image_path, point)` — runs SAM, picks the highest-score
  mask, calls `mask_to_polygon`.
- `mask_to_polygon(mask)` — pure function, lives here too. Picks the
  largest external contour, simplifies with Douglas-Peucker
  (`epsilon = max(1.5, 0.5%·perimeter)`), caps at 100 vertices. Raises
  `MaskTooSmall` for sub-50-px² blobs.

### `dataset_export.py` — YOLO dataset prep

Pure functions, no I/O at the API boundary. Used by the training start
endpoint.

- `compute_splits(filenames, val_frac=0.2, seed=42)` — deterministic
  shuffle + 80/20 split. Returns `{"train": [...], "val": [...]}`.
- `to_yolo_label_lines(image, class_id_map, img_w, img_h)` — turns
  polygons + boxes into normalized YOLO segmentation lines.
  **Boxes become 4-vertex polygons**, so we only ship one model type.
- `class_id_map(classes)` — maps annotation classId → 0-based YOLO index
  in declared order.
- `write_dataset(model_dir, dataset_dir, classes, images, splits, ...)`
  — materializes the standard YOLO layout
  (`images/train`, `images/val`, `labels/train`, `labels/val`,
  `data.yaml`). Wipes `dataset_dir` first so re-runs are clean.
- `all_images_done(images)` → `(bool, done_count, total)`. The training
  gate uses this.

### `training_service.py` — subprocess management

- `is_available()` — torch + ultralytics imports.
- `run_dir(model, run_id)` → `flask-annotator/runs/<model>/<run_id>/`.
- `list_runs(model)` / `latest_run(model)` / `read_state(model, run_id)`
  — read state.json off disk (no in-memory caching).
- `start_run(model, data_yaml, epochs, model_size)` — writes initial
  state.json, then `subprocess.Popen(...)`s `scripts/train_runner.py`
  with `CREATE_NEW_PROCESS_GROUP` so it survives Flask's debug-mode
  reloader.

### `scripts/train_runner.py` — the actual training loop

Spawned as a separate Python process by `training_service.start_run`.
Runs Ultralytics' Python API:

```python
model = YOLO(state["model_size"])  # e.g. yolov8n-seg
model.add_callback("on_train_epoch_end", lambda t: update_state(t.epoch + 1))
model.train(data=state["data_yaml"], epochs=..., imgsz=640, batch=8, workers=0)
```

After training succeeds it copies the produced
`<run_dir>/ultralytics/weights/best.pt` to `<run_dir>/best.pt`. On
failure it writes the traceback into `train.log` and flips status.

### `scripts/download_sam.py` — one-shot SAM checkpoint fetcher

Idempotent SHA-256-verified download of `sam_vit_b_01ec64.pth` (~375 MB)
from Meta's public release URL into `flask-annotator/models/sam/`.

### `conftest.py` (project root) — pytest path shim

Adds the package root to `sys.path` so tests can `import app, config,
slug, ...` directly without an installable package.

### `tests/conftest.py` — pytest fixtures

- `record_root` — builds a tmp `recorded_routes/demo/` tree with two tiny
  placeholder JPEGs and a v1-shaped `annotations.json`.
- `client` — points `config.RECORD_ROOT` at that tmp tree, then yields a
  Flask test client.

### `tests/test_*.py` — the suite

| File                    | What it covers                                                                |
|-------------------------|-------------------------------------------------------------------------------|
| `test_slug.py`          | path-traversal defense (slugify + is_safe_filename).                          |
| `test_annotations.py`   | default scaffold, v1→v2 normalization, atomic saves, corrupt-file rotation.   |
| `test_routes.py`        | Flask handlers — model picker, image list, annotations CRUD, reviewed, training gate. |
| `test_sam.py`           | mask_to_polygon helper, status shape, /api/sam/* endpoints with monkey-patched predictor. |
| `test_dataset_export.py`| splits, label format, on-disk dataset layout.                                  |

91 tests run in ~5 s. None require torch, ultralytics, or the SAM
checkpoint — predictors are monkey-patched.

---

## 4. Frontend files

### `templates/index.html` — three-pane shell

Top to bottom:

- `<header class="topbar">` — site-wide nav copied from the PHP pages.
- `<div class="nav-row">` — breadcrumb (`STAY ON TRAILS › ANNOTATE ›
  <filename>`), image counter with `‹ N / Total ›` arrows, save status,
  Save button, green ✓ reviewed toggle.
- `<div id="banner">` — yellow strip for "annotations.json was unreadable"
  / "Smart disabled" notices.
- `<div class="anno-layout">` — the 3-column grid:
  - **Left aside** — Annotations panel: `Group: <model>`, Classes/Layers
    tabs, Unused Classes, "✨ Find Objects with AI" (stub), Tags input.
  - **Center section** — Konva stage in `#stage`, plus an idle prompt
    and the SAM "Predicting…" overlay.
  - **Right aside** — Mode toolbar, Class selector (with `+ Add class`),
    Images list (filename + status dot), Training section (status line +
    Start Training button).

`<div id="modalRoot">` and `<div id="popupRoot">` are mount points for
the class-edit modal and the floating annotation editor.

### `templates/picker.html` — model selector

Listed at `GET /` when no `?model=` is set. One link per
`recorded_routes/*/` subfolder.

### `static/css/style.css` — all the styling

Single file. Roughly grouped:

1. Tokens (`--focus`, `--accent`, `--ok`, `--warn`, etc.)
2. Topbar + nav-row.
3. `.anno-layout` 3-column grid + panels.
4. Sidebar (tabs, class rows, layer rows, unused list, tags input,
   AI button).
5. Canvas + canvas-bar + canvas overlay.
6. Right panel (mode toolbar, class list, image list, training box).
7. Class manager modal + annotation editor popup.
8. Mobile fallback (`@media (max-width: 900px)`).

### `static/js/annotator.js` — the whole frontend (single IIFE)

1300 lines, no build step, no framework. Sections in order:

| Section                                | Lines              | What it does                                                                          |
|----------------------------------------|--------------------|---------------------------------------------------------------------------------------|
| Constants                              | top                | `PALETTE`, `IMG_W=640`, `IMG_H=480`.                                                  |
| `state`                                | top                | Single object holding everything: classes, annMap, mode, drawing, selection, training, sam, tab, layer visibility, tags. |
| DOM refs                               | top                | One `el.X` per `getElementById`.                                                      |
| Helpers                                | upper              | `classColor`, `className`, `currentFile`, `currentAnn`, `shortId`, `escapeHTML`.       |
| API helpers                            | upper              | `api(method, path, body)`, plus `loadAnnotations`, `loadImageList`, `saveAnnotations`. |
| Reviewed / SAM / Training fetchers     | upper-middle       | `toggleReviewed`, `loadSamStatus`, `loadTrainingStatus`, `startTraining`, `pollRun`.   |
| Render: top nav                        | middle             | `renderNav` — counter, breadcrumb, status chip, reviewed btn state.                   |
| Render: right-side image list          | middle             | `renderRightImageList` (called from renderNav).                                        |
| Render: left sidebar                   | middle             | `renderSidebar`, `renderClassesTab`, `renderLayersTab`, `renderUnusedClasses`, `renderTags`. |
| Class modal                            | middle             | `openClassModal` — handles Add, Edit, Delete-with-reassign.                            |
| Annotation editor popup                | middle             | `openAnnotationPopup` — right-click / dblclick. Reassign by clicking a class or pressing 1-9. |
| Konva stage                            | lower              | `initStage` (4 layers: image, ann, draw, cursor), `applyTransform`, crosshair lines.   |
| Drawing: polygon                       | lower              | `startPolygonAt`, `addPolygonVertex`, `commitPolygon`, `cancelDrawing`.                |
| Drawing: box                           | lower              | `startBoxAt`, `updateBoxDrag`, `commitBox`.                                            |
| Drawing: SAM preview                   | lower              | `runSamAt`, `commitSamPreview`.                                                        |
| Render: canvas                         | lower              | `redraw` — clears ann + draw layers, re-creates Konva nodes from state every frame.    |
| Image select / nav                     | lower              | `selectImage`, `navigateImage`, `markCurrentDone`, `deleteSelected`, `setMode`.        |
| Stage events                           | lower              | `bindStageEvents` — click, mousedown, mouseup, dblclick, contextmenu, mouseleave.      |
| Keyboard                               | bottom             | Esc, Backspace, Delete, Enter, ←/→, P/B/V/S, 1-9, Ctrl+S, D.                          |
| Button wiring                          | bottom             | Mode buttons, save, prev, next, mark-done, reviewed, train, find-AI, tabs, class-add. |
| `init()`                               | bottom             | Runs everything in sequence + auto-selects image 0.                                    |

The render functions are deliberately **idempotent**: each one wipes its
DOM/Konva subtree and rebuilds from `state`. No incremental DOM diffing.
With at most ~50 regions per image this is fast enough and trivial to
reason about.

---

## 5. Data flow walkthroughs

### Drawing a polygon

1. User presses **P** → `setMode('polygon')` → `state.mode = 'polygon'`,
   `state.drawing = null`, redraw.
2. Click on canvas → `bindStageEvents`'s `stage.on('click')` →
   `startPolygonAt(p)` sets `state.drawing = {type:'polygon', classId, points:[p]}`.
3. Each subsequent click → `addPolygonVertex(p)` pushes onto
   `state.drawing.points`, redraw (draws the in-progress line + dots).
4. Double-click → stage `dblclick` handler → `commitPolygon()` pushes
   the polygon onto `currentAnn().segments`, flips status to
   `in-progress` if it was `unlabeled`, marks dirty, re-renders sidebar +
   right image list + redraws.
5. Save button (or Ctrl+S) → `saveAnnotations(false)` PUTs the entire
   `annotations.json` shape back. The server validates `classes` and
   `images` are arrays, then atomic-writes.

### Smart Polygon (SAM)

1. **S** → `setMode('sam')`. Disabled if `state.samAvailable` is false.
2. Click → `runSamAt(p)` shows the "Predicting…" overlay, POSTs
   `/api/sam/predict {model, image, point: [x,y]}`.
3. Backend slugifies, validates the filename + point bounds, calls
   `sam_service.predict_polygon` — which encodes the image once
   (cached), runs SAM with one positive point, picks the best mask,
   simplifies to a polygon.
4. Frontend renders the response as a *dashed* preview in
   `state.drawing = {type:'sam', points, score}`.
5. Enter → `commitSamPreview()` pushes onto `segments` with
   `source: 'sam'`. Esc → discard.

### Training

1. Once every image has `status === 'done'`, the Start Training button's
   disabled flag flips. (The status endpoint returns
   `{ready, done_count, total, available, error, latest_run}`.)
2. Click → `POST /api/models/<m>/training/start`.
3. Server-side: validate gate, compute splits, write splits into
   `annotations.json`, call `dataset_export.write_dataset` to materialize
   `flask-annotator/_datasets/<model>/`, then `training_service.start_run`
   writes `state.json` and spawns `scripts/train_runner.py` as a detached
   subprocess.
4. Subprocess imports Ultralytics, runs `model.train(...)`, updates
   `state.json` after each epoch via `on_train_epoch_end`.
5. Frontend polls `GET /api/models/<m>/training/runs/<run_id>` every 3 s,
   re-renders status text. On `status === 'done'`, shows a download link
   to `best.pt`.
6. The subprocess survives Flask's debug-reloader. On page reload the
   frontend resumes polling whichever run is `running`.

---

## 6. Environment quirks (Windows, this machine)

Recorded in memory for future sessions:

- Two Pythons side-by-side: `Python312\python.exe` runs the Flask server,
  `Python313\python.exe` is what `py -3` resolves to. **Install
  dependencies into the one Flask actually uses** — check the running
  process with `Get-CimInstance Win32_Process -Filter "ProcessId=<pid>"`.
- Plain `python` from a normal shell hits the Microsoft Store stub. Don't
  use it.
- PyTorch's `cu121` index has no Python 3.13 wheels. Use `cu124`.
- `segment_anything` imports `torchvision` at load time. Install both
  even if you only think you need torch.
- Ultralytics drops weight files (e.g. `yolov8n-seg.pt`) into the cwd
  on first run. They're gitignored.

---

## 7. Where each phase landed

The project shipped in three rounds. If you're trying to map a feature
back to its commits:

- **Phase 1+2 — Manual annotation** (commits `7dca61c` → `d1ce34d`).
  Konva canvas, polygon + box + select, class manager, save/load,
  PHP redirect.
- **Phase 3 — SAM auto-segmentation** (commits `333c36d` → `fcbe2f5`).
  Lazy-loaded vit_b, image embedding cache, dashed preview.
- **Phase 4 — Roboflow-style UI + YOLO training** (commits `bee9186` →
  `9445931`). Top nav, left sidebar with tabs, annotation popup, dataset
  export, ultralytics subprocess, Start Training button, downloadable
  `best.pt`.

The corresponding design docs are under
`docs/superpowers/specs/` at the repo root.

---

## 8. Things this doc deliberately does **not** cover

- HTTP-level details of every endpoint — the README and route docstrings
  are authoritative.
- Frontend keyboard shortcuts table — see the "Keyboard" section in
  `annotator.js` directly; it's short and self-explanatory.
- Konva API — refer to upstream docs.
- YOLO label format — see `dataset_export.py` docstrings.
