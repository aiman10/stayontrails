<?php
/**
 * annotate.php — Polygon annotation editor for recorded trail images.
 *
 * This page lets you draw polygon shapes (regions) on top of images that
 * were captured by recordroute.php.  Each polygon marks where the trail
 * (or another object) is in the picture.  All annotations are saved in a
 * single JSON file alongside the images so they can later be used to train
 * a machine-learning model.
 *
 * How it works:
 *   1. The PHP at the top handles three JSON API calls from the browser.
 *   2. The HTML in the middle draws the three-panel layout (image list,
 *      drawing canvas, class/save tools).
 *   3. The JavaScript at the bottom handles user interaction on the canvas.
 *
 * URL patterns:
 *   annotate.php?model=my-trail          — open the editor for "my-trail"
 *   annotate.php?action=list_images&model=…  — API: list image files
 *   annotate.php?action=load_annotations&model=… — API: load saved JSON
 *   annotate.php?action=save_annotations&model=… — API: write JSON to disk
 */
declare(strict_types=1);

// Root folder where recorded_routes sub-folders live
$captureRoot = __DIR__ . DIRECTORY_SEPARATOR . 'recorded_routes';

// ── Shared helper functions ───────────────────────────────────────────────────

/**
 * Converts any string into a safe folder-name slug.
 * Only lowercase letters, numbers, and hyphens survive.
 * This also prevents directory-traversal attacks (no dots or slashes allowed).
 * Example: "My Trail!" → "my-trail"
 */
function slugify(string $value): string {
    $v = mb_strtolower(trim($value));
    $v = preg_replace('/[^a-z0-9]+/', '-', $v);
    return trim($v, '-');
}

/**
 * Sends a JSON response to the browser and stops execution.
 * Every API endpoint uses this instead of echo-ing raw strings.
 */
function jsonResp(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Escapes a string so it is safe to print inside HTML.
 * Prevents cross-site scripting (XSS) if a filename or model name contains
 * characters like < > " '.
 */
function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ── API dispatch ──────────────────────────────────────────────────────────────
// If the URL contains ?action=… the request comes from JavaScript (fetch),
// not from a user visiting the page.  Handle it and exit before any HTML.

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    // Accept model from GET or POST, always slugified for safety
    $model  = slugify((string)($_GET['model'] ?? $_POST['model'] ?? ''));

    // ── list_images ───────────────────────────────────────────────────────────
    // Returns every .jpg file in the model's folder, plus any annotation
    // status (unlabeled / in-progress / done) stored in annotations.json.
    if ($action === 'list_images') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);

        $dir = $captureRoot . DIRECTORY_SEPARATOR . $model;
        if (!is_dir($dir)) jsonResp(['ok' => false, 'error' => 'Model folder not found.'], 404);

        // Collect all JPEG files and sort them by name (oldest first)
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        sort($files, SORT_NATURAL);

        // Load per-image statuses from annotations.json if the file exists
        $statusMap = [];
        $jsonPath  = $dir . DIRECTORY_SEPARATOR . 'annotations.json';
        if (is_file($jsonPath)) {
            $ann = json_decode(file_get_contents($jsonPath), true);
            foreach (($ann['images'] ?? []) as $img) {
                $statusMap[$img['file']] = $img['status'] ?? 'unlabeled';
            }
        }

        // Build a simple [{file, status}] array for the browser
        $images = array_map(function ($path) use ($statusMap) {
            $name = basename($path);
            return ['file' => $name, 'status' => $statusMap[$name] ?? 'unlabeled'];
        }, $files);

        jsonResp(['ok' => true, 'images' => $images]);
    }

    // ── load_annotations ─────────────────────────────────────────────────────
    // Returns the full annotations.json content so the browser can restore
    // previously drawn polygons when the page loads.
    if ($action === 'load_annotations') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);

        $jsonPath = $captureRoot . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . 'annotations.json';

        if (is_file($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            // If the file exists but is corrupt/empty, fall back to a blank structure
            if ($data === null) {
                $data = ['model' => $model, 'classes' => [['id' => 0, 'name' => 'path']], 'images' => []];
            }
            jsonResp(['ok' => true, 'data' => $data]);
        }

        // No file yet — return a default empty structure with the "path" class
        jsonResp(['ok' => true, 'data' => ['model' => $model, 'classes' => [['id' => 0, 'name' => 'path']], 'images' => []]]);
    }

    // ── save_annotations ─────────────────────────────────────────────────────
    // Receives the full annotation JSON from the browser and writes it to disk.
    // Uses an atomic write (temp file → rename) so a crash mid-write can never
    // leave a half-written file on disk.
    if ($action === 'save_annotations') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);

        $dir = $captureRoot . DIRECTORY_SEPARATOR . $model;
        if (!is_dir($dir)) jsonResp(['ok' => false, 'error' => 'Model folder not found.'], 404);

        // Read the raw JSON body sent by fetch()
        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) jsonResp(['ok' => false, 'error' => 'Invalid JSON.'], 400);

        // Write to a temp file first, then rename — this makes the save atomic
        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'annotations.json';
        $tmpPath  = $jsonPath . '.tmp.' . getmypid();
        file_put_contents($tmpPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmpPath, $jsonPath);  // on the same filesystem this is an atomic swap

        jsonResp(['ok' => true]);
    }

    // Unknown action — return a 404 error
    jsonResp(['ok' => false, 'error' => 'Unknown action.'], 404);
}

// ── Page-level model validation ───────────────────────────────────────────────
// When the user visits the page directly (not an API call), check that the
// model parameter is valid.  If not, $pageError is shown instead of the editor.

$model    = slugify((string)($_GET['model'] ?? ''));
$pageError = '';
if ($model === '' || !is_dir($captureRoot . DIRECTORY_SEPARATOR . $model)) {
    $pageError = $model === '' ? 'No model specified.' : 'Model "' . h($model) . '" not found.';
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails – Annotate <?= h($model) ?></title>
  <style>
    /* ── Design tokens (same values as recordroute.php) ───────────────────── */
    :root{
      --focus:#22d3ee;            /* cyan — active/selected highlight */
      --menu-bg:rgba(15,23,42,.92);
      --menu-border:rgba(255,255,255,.18);
      --accent:#facc15;           /* yellow — primary action buttons */
      --accent-ink:#111827;
      --line:rgba(255,255,255,.12); /* subtle border colour */
      --muted:#cbd5e1;            /* de-emphasised text */
      --ok:#34d399;               /* green — success / done */
      --warn:#fb7185;             /* red — warning / error */
    }

    /* ── Reset & base ─────────────────────────────────────────────────────── */
    *{box-sizing:border-box}
    /* overflow:hidden keeps the 3-panel layout from growing a page scrollbar */
    html,body{margin:0;height:100%;background:#020617;color:#fff;font-family:Arial,Helvetica,sans-serif;overflow:hidden}
    a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:2px}

    /* ── Top navigation bar ───────────────────────────────────────────────── */
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1420px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)} /* yellow = current page */

    /* ── Generic shared utilities ─────────────────────────────────────────── */
    .sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .btn{padding:9px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer;font-size:13px}
    .btn:hover{background:#334155}
    .btn:disabled{opacity:.45;cursor:not-allowed}

    /* ── Page chrome (header strip below the nav bar) ─────────────────────── */
    .page-header{max-width:1420px;margin:0 auto;padding:10px 18px 0;display:flex;align-items:baseline;gap:10px;flex-shrink:0}
    .page-header h1{margin:0;font-size:20px}
    .model-chip{color:var(--focus);font-weight:700;font-size:16px} /* shows the model name */
    /* save status indicator top-right — changes colour via JS */
    #saveStatus{margin-left:auto;font-size:13px;color:var(--muted);transition:color .3s}
    #saveStatus.ok{color:var(--ok)}
    #saveStatus.warn{color:var(--warn)}

    /* ── Three-panel grid layout ──────────────────────────────────────────── */
    /* Left column: 220px image list
       Middle column: flexible-width canvas
       Right column: 200px tools/save panel               */
    .anno-layout{
      max-width:1420px;margin:0 auto;padding:10px 18px 14px;
      display:grid;
      grid-template-columns:220px minmax(0,1fr) 200px;
      gap:12px;
      /* Fill the remaining viewport height after the topbar and page-header */
      height:calc(100vh - 62px - 36px);
    }
    .anno-panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column}
    /* Small all-caps label at the top of each panel */
    .panel-head{padding:10px 12px;border-bottom:1px solid var(--line);font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);flex-shrink:0}

    /* ── Left panel: scrollable image list ───────────────────────────────── */
    .img-list{flex:1;overflow-y:auto;padding:8px;display:flex;flex-direction:column;gap:6px}
    /* Each row is a clickable image entry */
    .img-item{display:flex;gap:8px;align-items:center;padding:8px;border-radius:12px;cursor:pointer;border:2px solid transparent;background:rgba(255,255,255,.03);transition:background .15s,border-color .15s}
    .img-item:hover{background:rgba(255,255,255,.07)}
    .img-item.active{border-color:var(--focus);background:rgba(34,211,238,.08)} /* currently selected image */
    .img-thumb-sm{width:52px;height:39px;object-fit:cover;border-radius:8px;border:1px solid var(--line);flex-shrink:0;background:#000}
    .img-meta{flex:1;min-width:0}
    .img-name{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    /* Coloured pill showing annotation status */
    .badge{display:inline-block;font-size:10px;padding:2px 6px;border-radius:999px;font-weight:700;letter-spacing:.04em;margin-top:3px}
    .badge-unlabeled{background:rgba(255,255,255,.1);color:var(--muted)}
    .badge-in-progress{background:rgba(251,191,36,.15);color:#fbbf24}
    .badge-done{background:rgba(52,211,153,.15);color:var(--ok)}
    .no-images{padding:20px 12px;text-align:center;color:var(--muted);font-size:13px}

    /* ── Centre panel: drawing canvas ────────────────────────────────────── */
    /* The wrapper centres the <canvas> element and clips overflow */
    .canvas-wrap{flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#000;position:relative}
    #annoCanvas{cursor:crosshair;display:block;max-width:100%;max-height:100%}
    /* Placeholder text shown before any image is selected */
    .canvas-idle{position:absolute;color:var(--muted);font-size:14px;text-align:center;padding:20px;pointer-events:none}
    /* Bottom bar inside the centre panel: status text + Mark Done button */
    .canvas-bar{padding:8px 12px;border-top:1px solid var(--line);display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(0,0,0,.3);flex-shrink:0}
    #drawStatus{font-size:12px;color:var(--muted);flex:1}
    .btn-done{background:rgba(52,211,153,.12);color:var(--ok);border-color:rgba(52,211,153,.3)}
    .btn-done:hover{background:rgba(52,211,153,.22)}

    /* ── Right panel: class picker, segment list, save/nav buttons ────────── */
    .right-inner{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1}
    /* Class selector button — one per label class (e.g. "path") */
    .class-btn{width:100%;padding:9px 10px;border-radius:10px;border:2px solid transparent;background:rgba(255,255,255,.06);color:#fff;cursor:pointer;font-weight:700;text-align:left;font-size:13px}
    .class-btn.selected{border-color:var(--focus);background:rgba(34,211,238,.1);color:var(--focus)}
    .class-btn:hover{background:rgba(255,255,255,.1)}
    /* List of committed polygons for the current image */
    .anno-list{display:flex;flex-direction:column;gap:6px;overflow-y:auto}
    .anno-item{display:flex;justify-content:space-between;align-items:center;padding:6px 8px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:12px}
    /* Red × delete button on each annotation row */
    .del-btn{background:none;border:none;color:var(--warn);cursor:pointer;font-size:16px;line-height:1;padding:2px 6px}
    .del-btn:hover{color:#fff}
    .bottom-actions{display:flex;flex-direction:column;gap:8px;flex-shrink:0}
    .nav-btns{display:flex;gap:8px}
    /* Cyan-tinted Save button */
    .btn-cyan{background:rgba(34,211,238,.12);color:var(--focus);border-color:rgba(34,211,238,.35)}
    .btn-cyan:hover{background:rgba(34,211,238,.22)}

    /* ── Error page (shown when model is missing or folder not found) ─────── */
    .error-box{max-width:480px;margin:80px auto;padding:32px;background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;text-align:center}
    .error-box h2{color:var(--warn);margin-top:0}

    /* ── Mobile: switch to a single-column stacked layout ────────────────── */
    @media(max-width:900px){
      html,body{overflow:auto}
      .anno-layout{grid-template-columns:1fr;height:auto}
      .canvas-wrap{height:60vw;min-height:280px}
      .img-list{max-height:280px}
    }
  </style>
</head>
<body>

<!-- ── Top navigation bar (identical structure to all other pages) ──────── -->
<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Stay On Trails</p>
    <nav aria-label="Main menu">
      <ul class="menu">
        <li><a href="index.php">Home</a></li>
        <li><a href="routes.php">Available routes</a></li>
        <li><a href="makepath.php">Route builder</a></li>
        <li><a href="followpath.php">Start route</a></li>
        <li><a href="remoteHelp.php">Remote assistant</a></li>
        <li><a href="recordroute.php">Record route</a></li>
        <!-- "Annotate" is highlighted in yellow because it is the current page -->
        <li><a class="cta" href="#" aria-current="page">Annotate</a></li>
      </ul>
    </nav>
  </div>
</header>

<?php if ($pageError !== ''): ?>
<!-- ── Error screen — shown instead of the editor when model is invalid ── -->
<div class="error-box">
  <h2>Error</h2>
  <p><?= h($pageError) ?></p>
  <a href="recordroute.php" class="btn">← Back to Record Route</a>
</div>

<?php else: ?>

<!-- ── Page header: shows "Annotate <model-name>" and the save status ───── -->
<div class="page-header">
  <h1>Annotate</h1>
  <span class="model-chip"><?= h($model) ?></span>
  <!-- This span is updated by JavaScript to show "Saved." or an error -->
  <span id="saveStatus"></span>
</div>

<!-- ── Three-panel annotation layout ────────────────────────────────────── -->
<div class="anno-layout">

  <!-- LEFT PANEL: scrollable list of all images in this model folder -->
  <aside class="anno-panel">
    <div class="panel-head">Images (<span id="imgCount">0</span>)</div>
    <div class="img-list" id="imgList">
      <!-- Populated by renderImageList() in JavaScript -->
      <div class="no-images">Loading…</div>
    </div>
  </aside>

  <!-- CENTRE PANEL: the drawing canvas + status bar -->
  <section class="anno-panel">
    <div class="canvas-wrap" id="canvasWrap">
      <!-- The actual drawing surface — JavaScript resizes it to fill the panel -->
      <canvas id="annoCanvas"></canvas>
      <!-- Shown when no image has been selected yet -->
      <div class="canvas-idle" id="canvasIdle">Select an image to begin annotating</div>
    </div>
    <!-- Bottom bar inside the canvas panel -->
    <div class="canvas-bar">
      <!-- Hint text that changes as the user draws (e.g. "Adding vertices…") -->
      <span id="drawStatus">Click to add vertices · Double-click to close · Esc to cancel</span>
      <!-- Marks the current image as fully annotated ("done") -->
      <button class="btn btn-done" id="markDoneBtn" type="button" disabled>Mark Done</button>
    </div>
  </section>

  <!-- RIGHT PANEL: class selector, segment list, save/navigation buttons -->
  <aside class="anno-panel">
    <div class="panel-head">Tools</div>
    <div class="right-inner">

      <!-- Class picker: one button per label class (currently just "path") -->
      <div>
        <div class="sectionTitle" style="margin-bottom:6px">Class</div>
        <!-- Populated by renderClassList() in JavaScript -->
        <div id="classList"></div>
      </div>

      <!-- List of polygons drawn on the currently selected image -->
      <div style="flex:1;min-height:0;display:flex;flex-direction:column">
        <div class="sectionTitle" style="margin-bottom:6px">Segments (<span id="segCount">0</span>)</div>
        <!-- Populated by renderAnnotationList() in JavaScript -->
        <div class="anno-list" id="annoList"></div>
      </div>

      <!-- Save button + previous/next image navigation -->
      <div class="bottom-actions">
        <button class="btn btn-cyan" id="saveBtn" type="button" disabled>Save</button>
        <div class="nav-btns">
          <button class="btn" id="prevBtn" type="button" style="flex:1" disabled>← Prev</button>
          <button class="btn" id="nextBtn" type="button" style="flex:1" disabled>Next →</button>
        </div>
      </div>

    </div>
  </aside>

</div><!-- end .anno-layout -->

<script>
/**
 * All annotation logic lives inside an IIFE (immediately-invoked function
 * expression) so that no variables leak into the global browser scope.
 */
(function () {
  'use strict';

  // PHP injects the model slug so JavaScript knows which folder to load from
  const MODEL    = <?= json_encode($model) ?>;
  // Base URL path for loading image files (e.g. "recorded_routes/my-trail/")
  const IMG_BASE = 'recorded_routes/' + MODEL + '/';

  // ── Application state ────────────────────────────────────────────────────────
  // These variables hold the current "truth" of the editor at any moment.

  let allImages    = [];   // Array of {file, status} for every image in the folder
  let annMap       = {};   // Map: filename → {status, segments:[{classId, points:[{x,y}]}]}
  let classes      = [{id: 0, name: 'path'}];  // Label classes available in the class picker
  let currentIndex = -1;   // Which image is currently displayed (-1 = none)
  let currentImg   = null; // The HTMLImageElement loaded for the current image
  let selectedClass = 0;   // The class ID that will be assigned to the next polygon
  let drawingPoly  = null; // The polygon currently being drawn (null when idle)
  let hoverPt      = null; // Current mouse position in canvas coordinates (for rubber-band line)

  // ── Canvas coordinate transform ──────────────────────────────────────────────
  // The stored points are always in image space (0–639 × 0–479).
  // The canvas may be a different size, so we keep scale + offset values
  // that let us convert between the two coordinate systems.
  let scale   = 1;    // how many canvas pixels equal one image pixel
  let offsetX = 0;    // horizontal padding from letterboxing
  let offsetY = 0;    // vertical padding from letterboxing

  // Colours assigned to each class by index (cycles if there are more than 6 classes)
  const CLASS_COLORS = ['#22d3ee','#facc15','#f472b6','#34d399','#fb7185','#a78bfa'];

  // ── DOM element references ───────────────────────────────────────────────────
  const canvas       = document.getElementById('annoCanvas');
  const ctx          = canvas.getContext('2d');
  const canvasWrap   = document.getElementById('canvasWrap');
  const canvasIdle   = document.getElementById('canvasIdle');
  const imgListEl    = document.getElementById('imgList');
  const imgCountEl   = document.getElementById('imgCount');
  const classListEl  = document.getElementById('classList');
  const annoListEl   = document.getElementById('annoList');
  const segCountEl   = document.getElementById('segCount');
  const drawStatusEl = document.getElementById('drawStatus');
  const saveStatusEl = document.getElementById('saveStatus');
  const saveBtn      = document.getElementById('saveBtn');
  const prevBtn      = document.getElementById('prevBtn');
  const nextBtn      = document.getElementById('nextBtn');
  const markDoneBtn  = document.getElementById('markDoneBtn');

  // ── Pure helper functions ────────────────────────────────────────────────────

  /** Returns the hex colour for a given class ID. */
  function classColor(id) { return CLASS_COLORS[id % CLASS_COLORS.length]; }

  /** Returns the human-readable name for a given class ID (e.g. "path"). */
  function className(id)  { return (classes.find(c => c.id === id) || {name: 'class ' + id}).name; }

  /**
   * Converts a canvas pixel position into image pixel coordinates.
   * Used when the user clicks so we know where in the 640×480 image they clicked.
   */
  function canvasToImage(cx, cy) {
    return { x: (cx - offsetX) / scale, y: (cy - offsetY) / scale };
  }

  /**
   * Converts an image pixel position into canvas pixel coordinates.
   * Used when drawing so we know where on the canvas to place each point.
   */
  function imageToCanvas(ix, iy) {
    return { x: ix * scale + offsetX, y: iy * scale + offsetY };
  }

  /**
   * Recalculates scale and offset so the 640×480 image fits (letterboxed)
   * inside the current canvas size without stretching.
   * Must be called every time the canvas is resized.
   */
  function computeTransform() {
    const sx = canvas.width  / 640;
    const sy = canvas.height / 480;
    scale   = Math.min(sx, sy);  // use the smaller ratio so image never overflows
    offsetX = (canvas.width  - 640 * scale) / 2;
    offsetY = (canvas.height - 480 * scale) / 2;
  }

  /** Updates the top-right save status text and its colour class. */
  function setSaveStatus(msg, tone) {
    saveStatusEl.textContent = msg;
    saveStatusEl.className   = tone || '';
  }

  /** Updates the hint text in the bottom bar of the canvas panel. */
  function setDrawStatus(msg) {
    drawStatusEl.textContent = msg;
  }

  /** Returns the filename of the currently selected image, or null. */
  function currentFile() {
    return currentIndex >= 0 ? allImages[currentIndex].file : null;
  }

  /**
   * Returns (and lazily creates) the annotation entry for the current image.
   * Always use this instead of accessing annMap directly.
   */
  function currentAnn() {
    const f = currentFile();
    if (!f) return null;
    if (!annMap[f]) annMap[f] = { status: 'unlabeled', segments: [] };
    return annMap[f];
  }

  // ── API calls (all use fetch() to talk to the PHP endpoints above) ──────────

  /** Fires a GET request to one of the PHP API actions. */
  async function apiGet(action) {
    const r = await fetch('annotate.php?action=' + action + '&model=' + encodeURIComponent(MODEL));
    return r.json();
  }

  /**
   * Loads annotations.json from the server and populates the annMap lookup.
   * Called once on page load so previously saved polygons reappear.
   */
  async function loadAnnotations() {
    const d = await apiGet('load_annotations');
    if (!d.ok) throw new Error(d.error);
    classes = d.data.classes || [{id: 0, name: 'path'}];
    annMap  = {};
    for (const img of (d.data.images || [])) {
      annMap[img.file] = {
        status:   img.status   || 'unlabeled',
        segments: (img.annotations && img.annotations.segments) || []
      };
    }
  }

  /**
   * Fetches the list of image files from the server and merges in annotation
   * statuses so each image shows the correct badge colour.
   */
  async function loadImageList() {
    const d = await apiGet('list_images');
    if (!d.ok) throw new Error(d.error);
    allImages = d.images.map(img => ({
      file:   img.file,
      // Prefer the in-memory status (may have changed since page load)
      status: (annMap[img.file] && annMap[img.file].status) || img.status || 'unlabeled'
    }));
    imgCountEl.textContent = allImages.length;
    renderImageList();
  }

  /**
   * Serialises the current state of all annotations and POSTs it to PHP.
   * Pass silent=true when auto-saving on image switch (suppresses the "Saved." toast).
   */
  async function saveAnnotations(silent) {
    // Build the full payload that matches the JSON schema
    const images = allImages.map(img => ({
      file:   img.file,
      width:  640,
      height: 480,
      status: (annMap[img.file] && annMap[img.file].status) || 'unlabeled',
      annotations: { segments: (annMap[img.file] && annMap[img.file].segments) || [] }
    }));
    const payload = { model: MODEL, classes, images };
    try {
      const r = await fetch('annotate.php?action=save_annotations&model=' + encodeURIComponent(MODEL), {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error);
      if (!silent) setSaveStatus('Saved.', 'ok');
    } catch (e) {
      setSaveStatus('Save failed: ' + e.message, 'warn');
    }
  }

  // ── Render functions (update the DOM to match current state) ────────────────

  /**
   * Rebuilds the image list in the left panel.
   * Each item shows a thumbnail, the truncated filename, and a status badge.
   * Uses DocumentFragment to avoid triggering a browser reflow on every row.
   */
  function renderImageList() {
    if (!allImages.length) {
      imgListEl.innerHTML = '<div class="no-images">No images in this model.</div>';
      return;
    }
    const frag = document.createDocumentFragment();
    allImages.forEach((img, i) => {
      const div = document.createElement('div');
      div.className = 'img-item' + (i === currentIndex ? ' active' : '');
      div.dataset.idx = i;
      const status = (annMap[img.file] && annMap[img.file].status) || img.status || 'unlabeled';
      div.innerHTML =
        '<img class="img-thumb-sm" src="' + IMG_BASE + encodeURIComponent(img.file) + '" loading="lazy" alt="" />' +
        '<div class="img-meta">' +
          '<div class="img-name">' + img.file.replace(/^.*\//, '') + '</div>' +
          // Non-breaking hyphen (‑) in "in-progress" stops it wrapping mid-word
          '<span class="badge badge-' + status + '">' + status.replace('-', '‑') + '</span>' +
        '</div>';
      div.addEventListener('click', () => selectImage(i));
      frag.appendChild(div);
    });
    imgListEl.innerHTML = '';
    imgListEl.appendChild(frag);
  }

  /**
   * Rebuilds the class picker buttons in the right panel.
   * The currently selected class gets a cyan border.
   */
  function renderClassList() {
    classListEl.innerHTML = '';
    classes.forEach(cls => {
      const btn = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'class-btn' + (cls.id === selectedClass ? ' selected' : '');
      // Left border uses the class's colour as a visual indicator
      btn.style.borderLeftColor = classColor(cls.id);
      btn.style.borderLeftWidth = '3px';
      btn.textContent = cls.name;
      btn.addEventListener('click', () => { selectedClass = cls.id; renderClassList(); });
      classListEl.appendChild(btn);
    });
  }

  /**
   * Rebuilds the segment list in the right panel for the current image.
   * Shows each polygon as "className (N pts)" with a × delete button.
   */
  function renderAnnotationList() {
    const ann  = currentAnn();
    const segs = (ann && ann.segments) || [];
    segCountEl.textContent = segs.length;
    annoListEl.innerHTML   = '';
    segs.forEach((seg, i) => {
      const row = document.createElement('div');
      row.className = 'anno-item';
      // Colour dot matching the class colour
      const dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + classColor(seg.classId) + ';margin-right:6px;flex-shrink:0"></span>';
      row.innerHTML =
        '<span style="display:flex;align-items:center">' + dot + className(seg.classId) + ' (' + seg.points.length + ' pts)</span>' +
        '<button class="del-btn" data-idx="' + i + '" title="Delete">×</button>';
      annoListEl.appendChild(row);
    });
    // Wire up delete buttons after the rows are in the DOM
    annoListEl.querySelectorAll('.del-btn').forEach(btn => {
      btn.addEventListener('click', () => deleteSegment(parseInt(btn.dataset.idx, 10)));
    });
  }

  /**
   * Enables/disables Prev, Next, Save and Mark Done buttons based on
   * whether an image is currently selected.
   */
  function updateNavButtons() {
    prevBtn.disabled     = currentIndex <= 0;
    nextBtn.disabled     = currentIndex < 0 || currentIndex >= allImages.length - 1;
    saveBtn.disabled     = currentIndex < 0;
    markDoneBtn.disabled = currentIndex < 0;
  }

  // ── Canvas drawing ───────────────────────────────────────────────────────────

  /**
   * Called by ResizeObserver whenever the canvas container changes size.
   * Sets the canvas pixel dimensions to match the container, then redraws.
   */
  function resizeCanvas() {
    const rect = canvasWrap.getBoundingClientRect();
    canvas.width  = Math.floor(rect.width);
    canvas.height = Math.floor(rect.height);
    computeTransform();
    redraw();
  }

  /**
   * Redraws the entire canvas from scratch every time state changes.
   * Order: clear → image → committed polygons → in-progress polygon.
   */
  function redraw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Nothing to draw if no image is selected yet
    if (!currentImg) return;

    // 1. Draw the photo, letterboxed to fit the canvas
    ctx.drawImage(currentImg, offsetX, offsetY, 640 * scale, 480 * scale);

    const ann  = currentAnn();
    const segs = (ann && ann.segments) || [];

    // 2. Draw each committed (saved) polygon
    segs.forEach(seg => {
      if (seg.points.length < 2) return;
      const col = classColor(seg.classId);

      // Draw filled + stroked polygon shape
      ctx.beginPath();
      const p0 = imageToCanvas(seg.points[0].x, seg.points[0].y);
      ctx.moveTo(p0.x, p0.y);
      seg.points.slice(1).forEach(pt => {
        const p = imageToCanvas(pt.x, pt.y);
        ctx.lineTo(p.x, p.y);
      });
      ctx.closePath();
      ctx.fillStyle   = col + '4d'; // class colour at ~30% opacity
      ctx.strokeStyle = col;
      ctx.lineWidth   = 2;
      ctx.fill();
      ctx.stroke();

      // Small dot at each vertex so the user can see where points are
      ctx.fillStyle = col;
      seg.points.forEach(pt => {
        const p = imageToCanvas(pt.x, pt.y);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
        ctx.fill();
      });

      // Class name label drawn near the polygon's centroid
      const cx = seg.points.reduce((s, p) => s + p.x, 0) / seg.points.length;
      const cy = seg.points.reduce((s, p) => s + p.y, 0) / seg.points.length;
      const lp = imageToCanvas(cx, cy);
      ctx.fillStyle    = 'rgba(0,0,0,.6)';
      ctx.font         = 'bold ' + Math.max(11, 13 * scale) + 'px Arial';
      const label      = className(seg.classId);
      const tw         = ctx.measureText(label).width;
      ctx.fillRect(lp.x - tw / 2 - 4, lp.y - 10, tw + 8, 18); // dark background pill
      ctx.fillStyle    = '#fff';
      ctx.textAlign    = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, lp.x, lp.y);
      ctx.textAlign    = 'left'; // reset to default
    });

    // 3. Draw the polygon currently being drawn (not yet committed)
    if (drawingPoly && drawingPoly.points.length > 0) {
      const col = classColor(drawingPoly.classId);
      ctx.strokeStyle = col;
      ctx.lineWidth   = 2;

      // Solid line connecting the vertices placed so far
      if (drawingPoly.points.length > 1) {
        ctx.beginPath();
        const p0 = imageToCanvas(drawingPoly.points[0].x, drawingPoly.points[0].y);
        ctx.moveTo(p0.x, p0.y);
        drawingPoly.points.slice(1).forEach(pt => {
          const p = imageToCanvas(pt.x, pt.y);
          ctx.lineTo(p.x, p.y);
        });
        ctx.stroke();
      }

      // Dashed "rubber band" line from the last placed vertex to the cursor
      if (hoverPt) {
        const last = drawingPoly.points[drawingPoly.points.length - 1];
        const lp   = imageToCanvas(last.x, last.y);
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(lp.x, lp.y);
        ctx.lineTo(hoverPt.x, hoverPt.y);
        ctx.stroke();
        ctx.setLineDash([]); // reset to solid lines
      }

      // Vertex circles for the in-progress polygon
      // The first vertex gets a larger ring when the cursor is near it,
      // showing the user they can click to close the polygon.
      drawingPoly.points.forEach((pt, i) => {
        const p        = imageToCanvas(pt.x, pt.y);
        const isFirst  = i === 0;
        const nearFirst = isFirst && hoverPt && drawingPoly.points.length >= 3 && dist(hoverPt, p) < 10;
        ctx.beginPath();
        ctx.arc(p.x, p.y, nearFirst ? 7 : (isFirst ? 5 : 4), 0, Math.PI * 2);
        ctx.fillStyle   = nearFirst ? '#fff' : col; // white ring = "close here"
        ctx.fill();
        ctx.strokeStyle = col;
        ctx.lineWidth   = 1.5;
        ctx.stroke();
      });
    }
  }

  /** Euclidean distance between two {x,y} points (in any coordinate system). */
  function dist(a, b) {
    return Math.sqrt((a.x - b.x) ** 2 + (a.y - b.y) ** 2);
  }

  /** Returns true if the given image-space coordinates are inside the photo. */
  function inImageBounds(imgX, imgY) {
    return imgX >= 0 && imgX <= 640 && imgY >= 0 && imgY <= 480;
  }

  // ── Polygon interaction ───────────────────────────────────────────────────────

  /**
   * Saves the in-progress polygon to annMap and triggers a re-render.
   * Also advances the status from "unlabeled" to "in-progress" on first save.
   * Does nothing if there are fewer than 3 vertices (not a valid polygon).
   */
  function commitPolygon() {
    if (!drawingPoly || drawingPoly.points.length < 3) {
      drawingPoly = null;
      redraw();
      return;
    }
    const ann = currentAnn();
    ann.segments.push({
      classId: drawingPoly.classId,
      // Round coordinates to integers — sub-pixel precision is meaningless for labels
      points:  drawingPoly.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) }))
    });
    // Advance status: the first annotation on an image moves it from unlabeled → in-progress
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    allImages[currentIndex].status = ann.status;
    drawingPoly = null;
    renderAnnotationList();
    renderImageList(); // update the badge in the left panel
    redraw();
    setDrawStatus('Polygon saved. Click to start a new one.');
  }

  /**
   * Removes a committed polygon by index and redraws.
   * Called when the user clicks the × button in the segment list.
   */
  function deleteSegment(idx) {
    const ann = currentAnn();
    if (!ann) return;
    ann.segments.splice(idx, 1);
    renderAnnotationList();
    redraw();
  }

  // ── Canvas mouse / keyboard events ──────────────────────────────────────────

  // Track cursor position so we can draw the rubber-band preview line
  canvas.addEventListener('mousemove', e => {
    hoverPt = { x: e.offsetX, y: e.offsetY };
    if (drawingPoly) redraw(); // only redraw when actively drawing (saves CPU)
  });

  // Clear the rubber-band when the cursor leaves the canvas
  canvas.addEventListener('mouseleave', () => {
    hoverPt = null;
    if (drawingPoly) redraw();
  });

  /**
   * Left-click handler:
   *  — If no polygon is in progress, start one with the clicked point.
   *  — If a polygon is in progress and the click is near the first vertex
   *    (and at least 3 points exist), close and commit the polygon.
   *  — Otherwise, append the clicked point to the in-progress polygon.
   */
  canvas.addEventListener('click', e => {
    if (!currentImg) return; // nothing loaded yet
    const ip = canvasToImage(e.offsetX, e.offsetY);
    if (!inImageBounds(ip.x, ip.y)) return; // ignore clicks outside the photo

    if (!drawingPoly) {
      // Start a new polygon with the class that is currently selected
      drawingPoly = { classId: selectedClass, points: [ip] };
      setDrawStatus('Adding vertices… double-click to close · Backspace to undo · Esc to cancel');
      redraw();
      return;
    }

    // Check if the user clicked near the first vertex (≤10 canvas px) to close
    const first  = drawingPoly.points[0];
    const firstC = imageToCanvas(first.x, first.y);
    if (drawingPoly.points.length >= 3 && dist({ x: e.offsetX, y: e.offsetY }, firstC) < 10) {
      commitPolygon();
      return;
    }

    // Otherwise add the point and continue drawing
    drawingPoly.points.push(ip);
    redraw();
  });

  /**
   * Double-click closes and commits the current polygon.
   * The browser fires click → click → dblclick in sequence, so the second
   * single-click already added an extra point — we pop it back off first.
   */
  canvas.addEventListener('dblclick', e => {
    if (!drawingPoly || drawingPoly.points.length < 3) return;
    if (drawingPoly.points.length > 3) drawingPoly.points.pop(); // remove the duplicate click point
    commitPolygon();
  });

  /**
   * Keyboard shortcuts:
   *   Escape     — cancel the polygon being drawn
   *   Backspace  — undo the last placed vertex (cancel if only one remains)
   *   ←/→        — navigate to the previous/next image
   *   d          — mark the current image as done
   */
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (drawingPoly) { drawingPoly = null; redraw(); setDrawStatus('Cancelled.'); }
      return;
    }
    if (e.key === 'Backspace' && drawingPoly) {
      e.preventDefault(); // prevent browser back navigation
      if (drawingPoly.points.length > 1) {
        drawingPoly.points.pop();
        redraw();
      } else {
        // Only one point left — cancel the whole polygon
        drawingPoly = null;
        redraw();
        setDrawStatus('Polygon cancelled.');
      }
      return;
    }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); navigateImage(-1); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); navigateImage(1);  return; }
    if (e.key === 'd' || e.key === 'D') { markCurrentDone(); return; }
  });

  // ── Image selection ───────────────────────────────────────────────────────────

  /**
   * Loads the image at `index` into the canvas.
   * If a different image was showing, auto-saves first so no work is lost.
   */
  async function selectImage(index) {
    // Auto-save the previous image's annotations before switching
    if (currentIndex !== -1 && currentIndex !== index) {
      await saveAnnotations(true); // silent = no "Saved." toast
    }
    drawingPoly = null;  // discard any polygon in progress
    currentIndex = index;

    // Hide the idle placeholder and show the canvas
    canvasIdle.style.display = 'none';
    canvas.style.display     = 'block';

    updateNavButtons();
    renderAnnotationList();
    renderImageList(); // update the active highlight in the left panel

    // Start loading the image file
    const file = allImages[index].file;
    const img  = new Image();
    img.onload = () => {
      currentImg = img;
      computeTransform(); // recalculate scale/offset for the new image dimensions
      redraw();
      setDrawStatus('Click to add vertices · Double-click to close · Esc to cancel');
    };
    img.onerror = () => {
      setDrawStatus('Failed to load image.');
    };
    img.src = IMG_BASE + encodeURIComponent(file);
  }

  /** Moves to the next or previous image (delta = +1 or -1). */
  function navigateImage(delta) {
    const next = currentIndex + delta;
    if (next >= 0 && next < allImages.length) selectImage(next);
  }

  // ── Mark Done ─────────────────────────────────────────────────────────────────

  /**
   * Marks the current image as fully annotated ("done") and saves immediately.
   * Can be triggered by the "Mark Done" button or by pressing the D key.
   */
  function markCurrentDone() {
    if (currentIndex < 0) return;
    const ann = currentAnn();
    ann.status = 'done';
    allImages[currentIndex].status = 'done';
    renderImageList(); // update the green badge immediately
    saveAnnotations().catch(() => {}); // save in the background; errors shown in status bar
  }

  // ── Button event wiring ───────────────────────────────────────────────────────
  saveBtn.addEventListener('click',     () => saveAnnotations(false).catch(() => {}));
  markDoneBtn.addEventListener('click', markCurrentDone);
  prevBtn.addEventListener('click',     () => navigateImage(-1));
  nextBtn.addEventListener('click',     () => navigateImage(1));

  // ── ResizeObserver ────────────────────────────────────────────────────────────
  // Watches the canvas container for size changes (e.g. window resize) and
  // keeps the canvas pixel dimensions in sync with the displayed size.
  new ResizeObserver(resizeCanvas).observe(canvasWrap);

  // ── Initialisation ────────────────────────────────────────────────────────────

  /**
   * Startup sequence:
   *   1. Load any existing annotations from disk (so saved polygons reappear).
   *   2. Fetch the list of image files and build the left-panel list.
   *   3. Render the class picker buttons.
   *   4. Enable navigation buttons if images are present.
   */
  async function init() {
    await loadAnnotations();
    await loadImageList();
    renderClassList();
    if (allImages.length) {
      updateNavButtons();
    }
  }

  // Kick off init; show any startup error in the save-status area
  init().catch(err => setSaveStatus('Load error: ' + err.message, 'warn'));

})();
</script>

<?php endif; ?>
</body>
</html>
