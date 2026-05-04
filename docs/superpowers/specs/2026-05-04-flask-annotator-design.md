# Flask Annotator — Design (Phase 1+2)

**Date:** 2026-05-04
**Status:** Approved (pending written-spec review)
**Scope:** Replace `annotate.php` with a Python Flask + Konva.js annotation tool, with multi-class polygon and box drawing, reading and writing the existing `recorded_routes/<model>/annotations.json`. **SAM auto-segment and YOLO training are explicitly out of scope** for this design — they land in separate designs and plans.

## 1. Goals

- Replace `annotate.php` with a Flask app that looks visually identical (same topbar, same three-panel grid, same CSS tokens).
- Add **multi-class** support and **bounding-box** annotation alongside the existing polygon flow.
- Keep reading and writing `recorded_routes/<model>/annotations.json` so existing data and the existing capture flow (`recordroute.php`) keep working unchanged.
- Coexist with XAMPP / Apache: the PHP site keeps running on port 80, Flask runs separately on 5001, the existing `annotate.php` URL transparently redirects.

## 2. Non-goals (this phase)

- SAM / Smart Polygon auto-segmentation.
- YOLO dataset export, the "Start Training" button, training subprocess, SSE log streaming, model deployment.
- Authentication, multi-user concurrency, audit history.
- Image upload from the browser (capture flow is `recordroute.php`).
- Undo/redo history beyond Backspace-on-vertex while drawing.
- Mobile / touch annotation (existing PHP has a mobile fallback; we keep the same fallback CSS, no touch gestures).

These are reserved for follow-up designs. Folder layout and route namespaces leave room for them; no code is written for them.

## 3. Architecture

Two-process topology, both on the same host:

- **Apache (XAMPP)** keeps serving the existing PHP site on port 80. Unchanged except for `annotate.php`, which becomes a redirect.
- **Flask** runs on `127.0.0.1:5001` from `flask-annotator/`. It reads images directly from `c:\xampp\htdocs\stayontrails\recorded_routes\` and writes annotations back to the same tree. No copies, no imports.

Stack:

- Backend: Flask only. JSON-on-disk storage; no database. All captured frames are 640×480 (the existing `recordroute.php` hardcodes this), so dimensions are constants — no image library needed.
- Frontend: Konva.js loaded from CDN. Vanilla JS in a single `static/js/annotator.js` file. No build step, no bundler, no framework.
- Styling: ported from `annotate.php`'s `<style>` block into `static/css/style.css`. Same design tokens (`--focus`, `--menu-bg`, `--accent`, etc.).

## 4. Data model

### 4.1 Annotation file (extended)

`recorded_routes/<model>/annotations.json`:

```json
{
  "model": "my-trail",
  "schemaVersion": 2,
  "classes": [
    { "id": 0, "name": "path",      "color": "#22d3ee" },
    { "id": 1, "name": "path-oxod", "color": "#facc15" }
  ],
  "images": [
    {
      "file": "frame_001.jpg",
      "width": 640,
      "height": 480,
      "status": "in-progress",
      "annotations": {
        "segments": [
          {
            "id": "s-a1b2c3",
            "classId": 0,
            "points": [{ "x": 220, "y": 110 }, { "x": 380, "y": 105 }, { "x": 410, "y": 260 }]
          }
        ],
        "boxes": [
          {
            "id": "b-d4e5f6",
            "classId": 1,
            "x": 120, "y": 80, "w": 400, "h": 330
          }
        ]
      }
    }
  ]
}
```

### 4.2 Changes from current schema (v1 → v2)

- New optional top-level `schemaVersion: 2`. Files without it are treated as v1 and normalized in memory.
- `classes[]` gains optional `color` (hex string). Missing → fall back to the existing 6-color palette by index.
- Each image's `annotations` gains a sibling `boxes[]` next to `segments[]`. Missing → `[]`.
- Each segment and box gains optional `id` (random short string used by the frontend; backend treats it as opaque).

### 4.3 Field semantics

- `points[]` are integers in image-space (0..639 × 0..479). Rounded on save.
- `box` fields `x, y, w, h` are integers in image-space. `(x, y)` is the top-left corner.
- `status` ∈ {`unlabeled`, `in-progress`, `done`}.
- `classes[].id` is a stable integer; reusing a deleted id is allowed but discouraged.
- Class colors come from the palette `['#22d3ee','#facc15','#f472b6','#34d399','#fb7185','#a78bfa']`; cycles after the 6th class.

### 4.4 Migration rules

- Files without `schemaVersion` → load as v1, fill in defaults (`boxes: []`, default colors), do not rewrite the file. Only the next real save promotes the file to v2.
- Files with `schemaVersion > 2` → load read-only with a banner; saving disabled to prevent downgrade corruption.
- Corrupt JSON → load defaults in memory, do not overwrite; on first save, the bad file is moved to `annotations.json.broken-<unix-ts>` and a fresh v2 file is written.

### 4.5 Reserved future fields (not implemented now)

- `images[].annotations.segments[].source: "human" | "sam" | "model"`
- Top-level `splits: { train: [...], val: [...] }`
- Top-level `training: { last_run_id, ... }`

## 5. HTTP API

Base URL `http://localhost:5001`. All JSON responses use `{ok: bool, ...}`.

### 5.1 Page routes

- `GET /` — annotation page. Reads `?model=<slug>`. If no model, renders the model-picker.
- `GET /static/<path>` — Flask static handler.

### 5.2 Image route

- `GET /img/<model>/<filename>` — serves the image via `send_from_directory`. Filename must end in `.jpg`, `.jpeg`, or `.png`. Path-traversal rejection enforced.

### 5.3 JSON API

- `GET /api/models` — `{ok, models: [{slug, image_count, has_annotations}, ...]}`. One entry per `recorded_routes/*/` subfolder.
- `GET /api/models/<model>/images` — `{ok, images: [{file, status}, ...]}`. Reads `*.jpg` from the folder, merges per-image status from `annotations.json`.
- `GET /api/models/<model>/annotations` — `{ok, data: <full JSON>}`. Missing file → returns the v2 default scaffold with `path` + `path-oxod` classes.
- `PUT /api/models/<model>/annotations` — body is the full JSON, atomic temp-file-then-rename write. Returns `{ok}`.

### 5.4 Validation

- `model` is sanitized through `slugify` (`[a-z0-9-]+` only). Invalid → 400.
- Folder must exist under `recorded_routes/`. Otherwise 404.
- PUT body: must be a JSON object with `classes` (list) and `images` (list). Deeper schema correctness is the frontend's responsibility; we don't re-validate every point.

### 5.5 Reserved namespaces (not implemented)

- `/api/sam/*`, `/api/train/*`, `/api/pre_annotate` — return 404 today; populated by later phases.

## 6. UI components

### 6.1 Layout

Ported from `annotate.php`:

- Topbar: same nav (`Home / Available routes / Route builder / Start route / Remote assistant / Record route / Annotate`). "Annotate" highlighted in yellow as the current page.
- Page header: `Annotate <model-slug> · <save-status>`.
- Three-panel grid: 220px image list · flex canvas · 200px tools. Same CSS at the same breakpoint (`@media(max-width:900px)` collapses to single column).

### 6.2 Canvas (Konva.js)

A `Konva.Stage` filling the canvas wrapper, with three layers:

1. **Image layer** — 640×480 photo in a `Konva.Group`, scaled and centered (letterbox). The group's `scale` and `position` carry the existing transform math.
2. **Annotations layer** — committed segments as `Konva.Line({closed:true})` with vertex anchors as draggable `Konva.Circle`s; committed boxes as `Konva.Rect` with a `Konva.Transformer` attached on selection. Color comes from each annotation's class.
3. **Drawing layer** — in-progress polygon (rubber-band line, vertex dots, "near first vertex" highlight) and in-progress box drag-rectangle.

### 6.3 Drawing modes

- **Polygon (P)** — default mode. Behavior matches existing PHP exactly: click to add vertex; double-click or click-near-first (within 10px) to close; Esc cancels; Backspace pops last vertex.
- **Box (B)** — drag from one corner to opposite corner; on mouse-up, commit as a box with the selected class. Esc cancels mid-drag.
- **Select (V)** — click an annotation to select. Boxes get a Transformer (resize handles, drag-to-move). Polygons show their vertex anchors as draggable circles. Delete key removes selected. Click-empty deselects.

Mode switch buttons live in a small toolbar at the top of the right panel; selected mode gets the existing `.btn` cyan-active style.

### 6.4 Class picker & manager

In the right panel, replacing the current class list:

- One row per class: colored dot · class name · ⚙ "Manage" button. Click the row to set as active class for the next annotation; selected row gets the cyan border treatment.
- "+ Add class" row at the bottom; opens the manage-class modal in create mode.
- Manage-class modal (the screenshot in the prompt): name input, color palette (the 6-color palette plus a hex input), Delete button (red), Save button (blue, Enter shortcut).
- **Class delete = reassign with confirmation.** If the class has annotations using it, the modal lists how many annotations will be moved and to which class (the first remaining class by id). If 0 annotations use the class, the modal is a plain "Delete this class?" confirmation. Cannot delete the last remaining class.

### 6.5 Annotations list

Single combined list of segments and boxes for the current image. Each row: colored dot · class name · type marker (`△` polygon / `▭` box) · vertex count or `WxH` · × delete button. Click row to select on canvas (selecting also sets canvas mode to Select).

### 6.6 Bottom actions

Save · Prev · Next · Mark Done. Same buttons, same labels, same styling as current PHP.

### 6.7 Keyboard shortcuts

- `P` / `B` / `V` — switch mode
- `Esc` — cancel current drawing / deselect
- `Enter` — close polygon (when drawing)
- `Backspace` — pop last vertex (when drawing) / delete selected (when in Select mode)
- `Delete` — delete selected annotation
- `←` / `→` — prev/next image
- `D` — mark current done
- `Ctrl+S` — save (also auto-saves on image switch and `beforeunload`)
- `1` … `9` — quick-select class by index

### 6.8 Frontend state model

Single `state` object in `annotator.js`:

```
state = {
  model, allImages, annMap, classes, currentIndex,
  selectedClass, mode ('polygon'|'box'|'select'),
  selection ({type, id} | null),
  drawing ({type:'polygon', classId, points} | {type:'box', x,y,w,h, classId} | null),
  dirty (bool)
}
```

All mutations go through small reducer-style functions: `addSegment`, `addBox`, `deleteAnnotation`, `setMode`, `setSelectedClass`, `addClass`, `editClass`, `deleteClass`. Render functions (`renderImageList`, `renderClassList`, `renderAnnotationList`, `redrawCanvas`) run after each mutation. Plain JS, ~600–800 lines, broken into commented sections matching the existing PHP file's structure.

## 7. Error handling & data safety

### 7.1 Atomic saves

Every PUT writes to `<path>.tmp.<pid>` then `os.replace()` to the final path. On Windows + NTFS this is atomic.

### 7.2 Save UX

- Button states: idle → "Saving…" (disabled) → "Saved." (green ~2s) → idle.
- HTTP error / network drop → red "Save failed: <reason>", button re-enabled, **no automatic retry**.
- `beforeunload` warns on `dirty === true`.
- Auto-save on image switch (silent).

### 7.3 Read failures

- Missing model folder → page-level error matching the existing PHP error box.
- Corrupt `annotations.json` → warning banner, fall back to default scaffold, do not overwrite the bad file. On first user save, bad file is renamed to `annotations.json.broken-<unix-ts>` and a fresh v2 file is written.
- Image 404 / decode error → canvas shows "Failed to load image" placeholder; nav still works.

### 7.4 Path-traversal guards

All user-supplied path components (`model`, `filename`) are slugified or regex-validated before joining. `send_from_directory` enforces final paths stay under the configured root.

### 7.5 Concurrency

Single-user assumption (local dev tool, one browser tab). No locking, no last-writer-wins reconciliation. PUT-the-whole-file is simple and robust at this scale.

### 7.6 Logging

Default Flask request log + a single line per save: `saved <model>: 142 images, 38 done`. No external logger, no rotation.

## 8. Testing

### 8.1 Backend (pytest)

Spin up Flask test client, point `RECORD_ROOT` at `tmp_path`, copy a small fixture (2 frames + a v1 `annotations.json`). Tests:

- `GET /api/models` lists the fixture model.
- `GET /api/models/<m>/images` returns the frame list with statuses.
- `GET /api/models/<m>/annotations` on a v1 file returns it normalized to v2 in memory but does not rewrite the file on disk.
- `PUT /api/models/<m>/annotations` round-trips and writes v2.
- Corrupt JSON → returns default scaffold, file untouched. After a PUT, original is renamed to `annotations.json.broken-<ts>`.
- Path-traversal: `model="../etc"` → 400.
- Unknown filename in `/img/` → 404.
- Invalid JSON body in PUT → 400.

Target: 12–15 tests, runs in <2s.

### 8.2 Frontend

No automated tests this phase. Manual smoke checklist committed alongside the code (`flask-annotator/docs/manual-smoke.md`):

- Draw a polygon, save, reload, polygon present.
- Draw a box, resize via Transformer, save, reload, box present.
- Add a new class, draw an annotation with it, delete the class, confirm reassignment, save.
- Switch images mid-draw → polygon discarded, previous image auto-saved.
- Force a network failure on PUT → red error visible, button still enabled.
- Reload with unsaved changes → `beforeunload` prompt fires.

Konva interaction tests would need Playwright + a real browser; deferred until Phase 3+.

## 9. File layout

```
c:\xampp\htdocs\stayontrails\
├── annotate.php                       # 3-line redirect to localhost:5001
├── recorded_routes/                   # unchanged, read/written by Flask
│   └── <model>/
│       ├── *.jpg
│       ├── captures.csv
│       └── annotations.json           # extended schema v2
└── flask-annotator/
    ├── app.py                         # Flask app, all routes
    ├── config.py                      # RECORD_ROOT, PORT (5001), DEBUG
    ├── annotations.py                 # load/save/normalize/migrate v1→v2
    ├── slug.py                        # slugify + path-traversal guards
    ├── requirements.txt               # flask
    ├── run.bat                        # Windows launcher
    ├── README.md                      # how to run, manual smoke checklist
    ├── tests/
    │   ├── conftest.py                # tmp recorded_routes fixture
    │   ├── test_routes.py
    │   └── test_annotations.py
    ├── templates/
    │   ├── index.html                 # ported from annotate.php (Jinja2)
    │   └── picker.html                # model picker (no ?model= given)
    ├── static/
    │   ├── css/style.css              # extracted from annotate.php <style>
    │   └── js/annotator.js            # Konva-based, ~600–800 lines
    ├── models/                        # empty; SAM checkpoints land here later
    ├── runs/                          # empty; training output later
    └── scripts/                       # empty; train.py / convert_to_yolo.py later
```

## 10. Rollout

Only one existing file is modified: `annotate.php` becomes a redirect:

```php
<?php
$model = preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['model'] ?? ''));
header('Location: http://localhost:5001/' . ($model ? '?model=' . urlencode($model) : ''), true, 302);
exit;
```

This preserves every link to `annotate.php?model=foo` from elsewhere in the PHP site. No other PHP file changes.

Running it:

```
cd flask-annotator
pip install -r requirements.txt
python app.py
```

Or `run.bat` on Windows. App binds `127.0.0.1:5001`; Flask debug auto-reload is on.

## 11. Decisions log

- **Architecture: full Flask rewrite, port the look** (Option B in Q1). Existing PHP redirects.
- **Storage: read/write `recorded_routes/<model>/annotations.json` directly, extend schema** (Option A in Q2). No data migration step.
- **Topology: Flask on port 5001, PHP redirects** (Option A in Q3). No reverse proxy, no mod_wsgi.
- **Hardware target: GPU** (Option A in Q4). Future SAM/training phases will use `vit_b` and full Ultralytics training. Not used in this phase.
- **Training output: download link only** (Option A in Q5, for now). Auto-deploy to inference server's `models/` is a future phase.
- **Phasing: Phase 1+2 only** (Option A in Q6). SAM and training each get their own design + plan.
- **Class management: editable in-app, per project** (Option A in Q7). Stored in `annotations.json` per model.
- **Default canvas mode: Polygon** (matches existing PHP behavior).
- **Class delete: reassign with confirmation modal** (safer than cascade-delete).
