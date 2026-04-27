<?php
declare(strict_types=1);

$captureRoot = __DIR__ . DIRECTORY_SEPARATOR . 'recorded_routes';

function slugify(string $value): string {
    $v = mb_strtolower(trim($value));
    $v = preg_replace('/[^a-z0-9]+/', '-', $v);
    return trim($v, '-');
}

function jsonResp(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ── API dispatch ──────────────────────────────────────────────────────────────

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $model  = slugify((string)($_GET['model'] ?? $_POST['model'] ?? ''));

    if ($action === 'list_images') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);
        $dir = $captureRoot . DIRECTORY_SEPARATOR . $model;
        if (!is_dir($dir)) jsonResp(['ok' => false, 'error' => 'Model folder not found.'], 404);

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        sort($files, SORT_NATURAL);

        // Load existing annotation statuses
        $statusMap = [];
        $jsonPath  = $dir . DIRECTORY_SEPARATOR . 'annotations.json';
        if (is_file($jsonPath)) {
            $ann = json_decode(file_get_contents($jsonPath), true);
            foreach (($ann['images'] ?? []) as $img) {
                $statusMap[$img['file']] = $img['status'] ?? 'unlabeled';
            }
        }

        $images = array_map(function ($path) use ($statusMap) {
            $name = basename($path);
            return ['file' => $name, 'status' => $statusMap[$name] ?? 'unlabeled'];
        }, $files);

        jsonResp(['ok' => true, 'images' => $images]);
    }

    if ($action === 'load_annotations') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);
        $jsonPath = $captureRoot . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . 'annotations.json';
        if (is_file($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            if ($data === null) {
                $data = ['model' => $model, 'classes' => [['id' => 0, 'name' => 'path']], 'images' => []];
            }
            jsonResp(['ok' => true, 'data' => $data]);
        }
        jsonResp(['ok' => true, 'data' => ['model' => $model, 'classes' => [['id' => 0, 'name' => 'path']], 'images' => []]]);
    }

    if ($action === 'save_annotations') {
        if ($model === '') jsonResp(['ok' => false, 'error' => 'Missing model.'], 400);
        $dir = $captureRoot . DIRECTORY_SEPARATOR . $model;
        if (!is_dir($dir)) jsonResp(['ok' => false, 'error' => 'Model folder not found.'], 404);

        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) jsonResp(['ok' => false, 'error' => 'Invalid JSON.'], 400);

        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'annotations.json';
        $tmpPath  = $jsonPath . '.tmp.' . getmypid();
        file_put_contents($tmpPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmpPath, $jsonPath);

        jsonResp(['ok' => true]);
    }

    jsonResp(['ok' => false, 'error' => 'Unknown action.'], 404);
}

// ── Page-level model validation ───────────────────────────────────────────────

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

    /* ── Page chrome ── */
    .page-header{max-width:1420px;margin:0 auto;padding:10px 18px 0;display:flex;align-items:baseline;gap:10px;flex-shrink:0}
    .page-header h1{margin:0;font-size:20px}
    .model-chip{color:var(--focus);font-weight:700;font-size:16px}
    #saveStatus{margin-left:auto;font-size:13px;color:var(--muted);transition:color .3s}
    #saveStatus.ok{color:var(--ok)}
    #saveStatus.warn{color:var(--warn)}

    /* ── 3-panel layout ── */
    .anno-layout{
      max-width:1420px;margin:0 auto;padding:10px 18px 14px;
      display:grid;
      grid-template-columns:220px minmax(0,1fr) 200px;
      gap:12px;
      height:calc(100vh - 62px - 36px); /* viewport - topbar - page-header */
    }
    .anno-panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column}
    .panel-head{padding:10px 12px;border-bottom:1px solid var(--line);font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);flex-shrink:0}

    /* ── Left: image list ── */
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

    /* ── Center: canvas ── */
    .canvas-wrap{flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#000;position:relative}
    #annoCanvas{cursor:crosshair;display:block;max-width:100%;max-height:100%}
    .canvas-idle{position:absolute;color:var(--muted);font-size:14px;text-align:center;padding:20px;pointer-events:none}
    .canvas-bar{padding:8px 12px;border-top:1px solid var(--line);display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(0,0,0,.3);flex-shrink:0}
    #drawStatus{font-size:12px;color:var(--muted);flex:1}
    .btn-done{background:rgba(52,211,153,.12);color:var(--ok);border-color:rgba(52,211,153,.3)}
    .btn-done:hover{background:rgba(52,211,153,.22)}

    /* ── Right: class + annotation list ── */
    .right-inner{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1}
    .class-btn{width:100%;padding:9px 10px;border-radius:10px;border:2px solid transparent;background:rgba(255,255,255,.06);color:#fff;cursor:pointer;font-weight:700;text-align:left;font-size:13px}
    .class-btn.selected{border-color:var(--focus);background:rgba(34,211,238,.1);color:var(--focus)}
    .class-btn:hover{background:rgba(255,255,255,.1)}
    .anno-list{display:flex;flex-direction:column;gap:6px;overflow-y:auto}
    .anno-item{display:flex;justify-content:space-between;align-items:center;padding:6px 8px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:12px}
    .del-btn{background:none;border:none;color:var(--warn);cursor:pointer;font-size:16px;line-height:1;padding:2px 6px}
    .del-btn:hover{color:#fff}
    .bottom-actions{display:flex;flex-direction:column;gap:8px;flex-shrink:0}
    .nav-btns{display:flex;gap:8px}
    .btn-cyan{background:rgba(34,211,238,.12);color:var(--focus);border-color:rgba(34,211,238,.35)}
    .btn-cyan:hover{background:rgba(34,211,238,.22)}

    /* ── Error page ── */
    .error-box{max-width:480px;margin:80px auto;padding:32px;background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;text-align:center}
    .error-box h2{color:var(--warn);margin-top:0}

    @media(max-width:900px){
      html,body{overflow:auto}
      .anno-layout{grid-template-columns:1fr;height:auto}
      .canvas-wrap{height:60vw;min-height:280px}
      .img-list{max-height:280px}
    }
  </style>
</head>
<body>
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
        <li><a class="cta" href="#" aria-current="page">Annotate</a></li>
      </ul>
    </nav>
  </div>
</header>

<?php if ($pageError !== ''): ?>
<div class="error-box">
  <h2>Error</h2>
  <p><?= h($pageError) ?></p>
  <a href="recordroute.php" class="btn">← Back to Record Route</a>
</div>
<?php else: ?>

<div class="page-header">
  <h1>Annotate</h1>
  <span class="model-chip"><?= h($model) ?></span>
  <span id="saveStatus"></span>
</div>

<div class="anno-layout">

  <!-- LEFT: image list -->
  <aside class="anno-panel">
    <div class="panel-head">Images (<span id="imgCount">0</span>)</div>
    <div class="img-list" id="imgList">
      <div class="no-images">Loading…</div>
    </div>
  </aside>

  <!-- CENTER: canvas -->
  <section class="anno-panel">
    <div class="canvas-wrap" id="canvasWrap">
      <canvas id="annoCanvas"></canvas>
      <div class="canvas-idle" id="canvasIdle">Select an image to begin annotating</div>
    </div>
    <div class="canvas-bar">
      <span id="drawStatus">Click to add vertices · Double-click to close · Esc to cancel</span>
      <button class="btn btn-done" id="markDoneBtn" type="button" disabled>Mark Done</button>
    </div>
  </section>

  <!-- RIGHT: class + segments + nav -->
  <aside class="anno-panel">
    <div class="panel-head">Tools</div>
    <div class="right-inner">
      <div>
        <div class="sectionTitle" style="margin-bottom:6px">Class</div>
        <div id="classList"></div>
      </div>
      <div style="flex:1;min-height:0;display:flex;flex-direction:column">
        <div class="sectionTitle" style="margin-bottom:6px">Segments (<span id="segCount">0</span>)</div>
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

<script>
(function () {
  'use strict';

  const MODEL    = <?= json_encode($model) ?>;
  const IMG_BASE = 'recorded_routes/' + MODEL + '/';

  // ── State ────────────────────────────────────────────────────────────────────
  let allImages    = [];   // [{file, status}]
  let annMap       = {};   // file → {status, segments:[{classId, points:[{x,y}]}]}
  let classes      = [{id: 0, name: 'path'}];
  let currentIndex = -1;
  let currentImg   = null; // HTMLImageElement
  let selectedClass = 0;
  let drawingPoly  = null; // null | {classId, points:[{x,y}]}
  let hoverPt      = null; // {x,y} in canvas space

  // Canvas transform (image-space ↔ canvas-space)
  let scale   = 1;
  let offsetX = 0;
  let offsetY = 0;

  const CLASS_COLORS = ['#22d3ee','#facc15','#f472b6','#34d399','#fb7185','#a78bfa'];

  // ── DOM refs ─────────────────────────────────────────────────────────────────
  const canvas      = document.getElementById('annoCanvas');
  const ctx         = canvas.getContext('2d');
  const canvasWrap  = document.getElementById('canvasWrap');
  const canvasIdle  = document.getElementById('canvasIdle');
  const imgListEl   = document.getElementById('imgList');
  const imgCountEl  = document.getElementById('imgCount');
  const classListEl = document.getElementById('classList');
  const annoListEl  = document.getElementById('annoList');
  const segCountEl  = document.getElementById('segCount');
  const drawStatusEl = document.getElementById('drawStatus');
  const saveStatusEl = document.getElementById('saveStatus');
  const saveBtn     = document.getElementById('saveBtn');
  const prevBtn     = document.getElementById('prevBtn');
  const nextBtn     = document.getElementById('nextBtn');
  const markDoneBtn = document.getElementById('markDoneBtn');

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function classColor(id) { return CLASS_COLORS[id % CLASS_COLORS.length]; }
  function className(id)  { return (classes.find(c => c.id === id) || {name: 'class ' + id}).name; }

  function canvasToImage(cx, cy) {
    return { x: (cx - offsetX) / scale, y: (cy - offsetY) / scale };
  }
  function imageToCanvas(ix, iy) {
    return { x: ix * scale + offsetX, y: iy * scale + offsetY };
  }

  function computeTransform() {
    const sx = canvas.width  / 640;
    const sy = canvas.height / 480;
    scale   = Math.min(sx, sy);
    offsetX = (canvas.width  - 640 * scale) / 2;
    offsetY = (canvas.height - 480 * scale) / 2;
  }

  function setSaveStatus(msg, tone) {
    saveStatusEl.textContent = msg;
    saveStatusEl.className   = tone || '';
  }

  function setDrawStatus(msg) {
    drawStatusEl.textContent = msg;
  }

  function currentFile() {
    return currentIndex >= 0 ? allImages[currentIndex].file : null;
  }

  function currentAnn() {
    const f = currentFile();
    if (!f) return null;
    if (!annMap[f]) annMap[f] = { status: 'unlabeled', segments: [] };
    return annMap[f];
  }

  // ── API ───────────────────────────────────────────────────────────────────────
  async function apiGet(action) {
    const r = await fetch('annotate.php?action=' + action + '&model=' + encodeURIComponent(MODEL));
    return r.json();
  }

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

  async function loadImageList() {
    const d = await apiGet('list_images');
    if (!d.ok) throw new Error(d.error);
    allImages = d.images.map(img => ({
      file:   img.file,
      status: (annMap[img.file] && annMap[img.file].status) || img.status || 'unlabeled'
    }));
    imgCountEl.textContent = allImages.length;
    renderImageList();
  }

  async function saveAnnotations(silent) {
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

  // ── Render ───────────────────────────────────────────────────────────────────
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
          '<span class="badge badge-' + status + '">' + status.replace('-', '‑') + '</span>' +
        '</div>';
      div.addEventListener('click', () => selectImage(i));
      frag.appendChild(div);
    });
    imgListEl.innerHTML = '';
    imgListEl.appendChild(frag);
  }

  function renderClassList() {
    classListEl.innerHTML = '';
    classes.forEach(cls => {
      const btn = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'class-btn' + (cls.id === selectedClass ? ' selected' : '');
      btn.style.borderLeftColor = classColor(cls.id);
      btn.style.borderLeftWidth = '3px';
      btn.textContent = cls.name;
      btn.addEventListener('click', () => { selectedClass = cls.id; renderClassList(); });
      classListEl.appendChild(btn);
    });
  }

  function renderAnnotationList() {
    const ann = currentAnn();
    const segs = (ann && ann.segments) || [];
    segCountEl.textContent = segs.length;
    annoListEl.innerHTML   = '';
    segs.forEach((seg, i) => {
      const row = document.createElement('div');
      row.className = 'anno-item';
      const dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + classColor(seg.classId) + ';margin-right:6px;flex-shrink:0"></span>';
      row.innerHTML =
        '<span style="display:flex;align-items:center">' + dot + className(seg.classId) + ' (' + seg.points.length + ' pts)</span>' +
        '<button class="del-btn" data-idx="' + i + '" title="Delete">×</button>';
      annoListEl.appendChild(row);
    });
    annoListEl.querySelectorAll('.del-btn').forEach(btn => {
      btn.addEventListener('click', () => deleteSegment(parseInt(btn.dataset.idx, 10)));
    });
  }

  function updateNavButtons() {
    prevBtn.disabled     = currentIndex <= 0;
    nextBtn.disabled     = currentIndex < 0 || currentIndex >= allImages.length - 1;
    saveBtn.disabled     = currentIndex < 0;
    markDoneBtn.disabled = currentIndex < 0;
  }

  // ── Canvas ───────────────────────────────────────────────────────────────────
  function resizeCanvas() {
    const rect = canvasWrap.getBoundingClientRect();
    canvas.width  = Math.floor(rect.width);
    canvas.height = Math.floor(rect.height);
    computeTransform();
    redraw();
  }

  function redraw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    if (!currentImg) return;

    // Draw image
    ctx.drawImage(currentImg, offsetX, offsetY, 640 * scale, 480 * scale);

    const ann  = currentAnn();
    const segs = (ann && ann.segments) || [];

    // Draw committed polygons
    segs.forEach(seg => {
      if (seg.points.length < 2) return;
      const col = classColor(seg.classId);
      ctx.beginPath();
      const p0 = imageToCanvas(seg.points[0].x, seg.points[0].y);
      ctx.moveTo(p0.x, p0.y);
      seg.points.slice(1).forEach(pt => {
        const p = imageToCanvas(pt.x, pt.y);
        ctx.lineTo(p.x, p.y);
      });
      ctx.closePath();
      ctx.fillStyle   = col + '4d'; // ~30% opacity
      ctx.strokeStyle = col;
      ctx.lineWidth   = 2;
      ctx.fill();
      ctx.stroke();

      // Vertex circles
      ctx.fillStyle = col;
      seg.points.forEach(pt => {
        const p = imageToCanvas(pt.x, pt.y);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
        ctx.fill();
      });

      // Class label near centroid
      const cx = seg.points.reduce((s, p) => s + p.x, 0) / seg.points.length;
      const cy = seg.points.reduce((s, p) => s + p.y, 0) / seg.points.length;
      const lp = imageToCanvas(cx, cy);
      ctx.fillStyle    = 'rgba(0,0,0,.6)';
      ctx.font         = 'bold ' + Math.max(11, 13 * scale) + 'px Arial';
      const label      = className(seg.classId);
      const tw         = ctx.measureText(label).width;
      ctx.fillRect(lp.x - tw / 2 - 4, lp.y - 10, tw + 8, 18);
      ctx.fillStyle    = '#fff';
      ctx.textAlign    = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, lp.x, lp.y);
      ctx.textAlign    = 'left';
    });

    // Draw in-progress polygon
    if (drawingPoly && drawingPoly.points.length > 0) {
      const col = classColor(drawingPoly.classId);
      ctx.strokeStyle = col;
      ctx.lineWidth   = 2;

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

      // Rubber-band to cursor
      if (hoverPt) {
        const last = drawingPoly.points[drawingPoly.points.length - 1];
        const lp   = imageToCanvas(last.x, last.y);
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(lp.x, lp.y);
        ctx.lineTo(hoverPt.x, hoverPt.y);
        ctx.stroke();
        ctx.setLineDash([]);
      }

      // Vertex circles
      drawingPoly.points.forEach((pt, i) => {
        const p = imageToCanvas(pt.x, pt.y);
        ctx.beginPath();
        const isFirst = i === 0;
        const nearFirst = isFirst && hoverPt && drawingPoly.points.length >= 3 && dist(hoverPt, p) < 10;
        ctx.arc(p.x, p.y, nearFirst ? 7 : (isFirst ? 5 : 4), 0, Math.PI * 2);
        ctx.fillStyle = nearFirst ? '#fff' : col;
        ctx.fill();
        ctx.strokeStyle = col;
        ctx.lineWidth = 1.5;
        ctx.stroke();
      });
    }
  }

  function dist(a, b) {
    return Math.sqrt((a.x - b.x) ** 2 + (a.y - b.y) ** 2);
  }

  function inImageBounds(imgX, imgY) {
    return imgX >= 0 && imgX <= 640 && imgY >= 0 && imgY <= 480;
  }

  // ── Polygon interaction ───────────────────────────────────────────────────────
  function commitPolygon() {
    if (!drawingPoly || drawingPoly.points.length < 3) {
      drawingPoly = null;
      redraw();
      return;
    }
    const ann = currentAnn();
    ann.segments.push({
      classId: drawingPoly.classId,
      points:  drawingPoly.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) }))
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    allImages[currentIndex].status = ann.status;
    drawingPoly = null;
    renderAnnotationList();
    renderImageList();
    redraw();
    setDrawStatus('Polygon saved. Click to start a new one.');
  }

  function deleteSegment(idx) {
    const ann = currentAnn();
    if (!ann) return;
    ann.segments.splice(idx, 1);
    renderAnnotationList();
    redraw();
  }

  canvas.addEventListener('mousemove', e => {
    hoverPt = { x: e.offsetX, y: e.offsetY };
    if (drawingPoly) redraw();
  });

  canvas.addEventListener('mouseleave', () => {
    hoverPt = null;
    if (drawingPoly) redraw();
  });

  canvas.addEventListener('click', e => {
    if (!currentImg) return;
    const ip = canvasToImage(e.offsetX, e.offsetY);
    if (!inImageBounds(ip.x, ip.y)) return;

    if (!drawingPoly) {
      drawingPoly = { classId: selectedClass, points: [ip] };
      setDrawStatus('Adding vertices… double-click to close · Backspace to undo · Esc to cancel');
      redraw();
      return;
    }

    // Check if clicking near first vertex to close
    const first  = drawingPoly.points[0];
    const firstC = imageToCanvas(first.x, first.y);
    if (drawingPoly.points.length >= 3 && dist({ x: e.offsetX, y: e.offsetY }, firstC) < 10) {
      commitPolygon();
      return;
    }

    drawingPoly.points.push(ip);
    redraw();
  });

  canvas.addEventListener('dblclick', e => {
    if (!drawingPoly || drawingPoly.points.length < 3) return;
    // Remove the extra point added by the first click of the dblclick
    if (drawingPoly.points.length > 3) drawingPoly.points.pop();
    commitPolygon();
  });

  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (drawingPoly) { drawingPoly = null; redraw(); setDrawStatus('Cancelled.'); }
      return;
    }
    if (e.key === 'Backspace' && drawingPoly) {
      e.preventDefault();
      if (drawingPoly.points.length > 1) {
        drawingPoly.points.pop();
        redraw();
      } else {
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
  async function selectImage(index) {
    if (currentIndex !== -1 && currentIndex !== index) {
      await saveAnnotations(true);
    }
    drawingPoly = null;
    currentIndex = index;
    canvasIdle.style.display = 'none';
    canvas.style.display     = 'block';

    updateNavButtons();
    renderAnnotationList();
    renderImageList();

    const file = allImages[index].file;
    const img  = new Image();
    img.onload = () => {
      currentImg = img;
      computeTransform();
      redraw();
      setDrawStatus('Click to add vertices · Double-click to close · Esc to cancel');
    };
    img.onerror = () => {
      setDrawStatus('Failed to load image.');
    };
    img.src = IMG_BASE + encodeURIComponent(file);
  }

  function navigateImage(delta) {
    const next = currentIndex + delta;
    if (next >= 0 && next < allImages.length) selectImage(next);
  }

  // ── Mark Done ─────────────────────────────────────────────────────────────────
  function markCurrentDone() {
    if (currentIndex < 0) return;
    const ann = currentAnn();
    ann.status = 'done';
    allImages[currentIndex].status = 'done';
    renderImageList();
    saveAnnotations().catch(() => {});
  }

  // ── Button wiring ─────────────────────────────────────────────────────────────
  saveBtn.addEventListener('click',     () => saveAnnotations(false).catch(() => {}));
  markDoneBtn.addEventListener('click', markCurrentDone);
  prevBtn.addEventListener('click',     () => navigateImage(-1));
  nextBtn.addEventListener('click',     () => navigateImage(1));

  // ── ResizeObserver ────────────────────────────────────────────────────────────
  new ResizeObserver(resizeCanvas).observe(canvasWrap);

  // ── Init ──────────────────────────────────────────────────────────────────────
  async function init() {
    await loadAnnotations();
    await loadImageList();
    renderClassList();
    if (allImages.length) {
      updateNavButtons();
    }
  }

  init().catch(err => setSaveStatus('Load error: ' + err.message, 'warn'));

})();
</script>

<?php endif; ?>
</body>
</html>
