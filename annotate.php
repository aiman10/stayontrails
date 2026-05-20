<?php
/**
 * Stay On Trails — annotator (PHP).
 *
 * Self-contained page + JSON API, following the project's recordroute.php
 * conventions. Reads/writes the same recorded_routes/<model>/annotations.json
 * and reviewed.json that the old Flask annotator used.
 *
 *   GET  annotate.php                         -> model picker
 *   GET  annotate.php?model=<slug>            -> annotator UI
 *   GET  annotate.php?action=images&model=    -> {ok, images:[{file,status,reviewed}]}
 *   GET  annotate.php?action=annotations&...  -> {ok, status, data}
 *   POST annotate.php?action=save_annotations -> {ok}
 *   POST annotate.php?action=reviewed&file=   -> {ok, reviewed}
 */

const SCHEMA_VERSION = 2;

$DEFAULT_PALETTE = ['#7C3AED', '#06D6A0', '#F4A261', '#EF476F', '#22d3ee', '#facc15'];
$DEFAULT_CLASSES = [
    ['id' => 0, 'name' => 'path-oxod', 'color' => '#7C3AED'],
    ['id' => 1, 'name' => 'grass',     'color' => '#06D6A0'],
    ['id' => 2, 'name' => 'puddle',    'color' => '#F4A261'],
    ['id' => 3, 'name' => 'road',      'color' => '#EF476F'],
];

$captureRoot = __DIR__ . DIRECTORY_SEPARATOR . 'recorded_routes';

// ── Helpers ─────────────────────────────────────────────────────────────────
function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $statusCode = 400): void {
    jsonResponse(['ok' => false, 'error' => $message], $statusCode);
}

// Mirrors slugifyName in recordroute.php so the same model name reaches the
// same folder from both pages.
function slugifyName(string $value): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value;
}

function isSafeFilename(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9._-]+\.(jpg|jpeg|png)$/i', $name);
}

function defaultScaffold(string $model): array {
    global $DEFAULT_CLASSES;
    return [
        'model' => $model,
        'schemaVersion' => SCHEMA_VERSION,
        'classes' => array_map(fn($c) => $c, $DEFAULT_CLASSES),
        'images' => [],
    ];
}

/** Return a v2-shaped copy of $data, filling in missing fields. */
function normalizeAnnotations(array $data): array {
    global $DEFAULT_PALETTE;
    $out = $data;
    $out['schemaVersion'] = SCHEMA_VERSION;

    $classes = [];
    foreach (($data['classes'] ?? []) as $idx => $cls) {
        $c = (array) $cls;
        if (!isset($c['color'])) {
            $c['color'] = $DEFAULT_PALETTE[$idx % count($DEFAULT_PALETTE)];
        }
        $classes[] = $c;
    }
    $out['classes'] = $classes;

    $images = [];
    foreach (($data['images'] ?? []) as $img) {
        $i = (array) $img;
        $ann = (array) ($i['annotations'] ?? []);
        if (!isset($ann['segments'])) $ann['segments'] = [];
        if (!isset($ann['boxes'])) $ann['boxes'] = [];
        $i['annotations'] = $ann;
        $images[] = $i;
    }
    $out['images'] = $images;
    return $out;
}

/**
 * Read annotations.json. Never throws. Returns ['status'=>..., 'data'=>...].
 *   missing  - file absent; data is the default scaffold.
 *   corrupt  - unreadable JSON; data is the default scaffold. Bad file left
 *              on disk; first save rotates it aside.
 *   future   - valid but schemaVersion > 2; loaded as-is, treat as read-only.
 *   ok       - valid v1/v2; normalized to v2 in memory.
 */
function loadAnnotations(string $modelDir, string $model): array {
    $path = $modelDir . DIRECTORY_SEPARATOR . 'annotations.json';
    if (!is_file($path)) {
        return ['status' => 'missing', 'data' => defaultScaffold($model)];
    }
    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['status' => 'corrupt', 'data' => defaultScaffold($model)];
    }
    $version = $decoded['schemaVersion'] ?? 1;
    if (is_int($version) && $version > SCHEMA_VERSION) {
        return ['status' => 'future', 'data' => $decoded];
    }
    return ['status' => 'ok', 'data' => normalizeAnnotations($decoded)];
}

/**
 * Atomically write annotations.json. If an existing file is unreadable JSON,
 * it is moved aside to annotations.json.broken-<unix-ts> first.
 */
function saveAnnotations(string $modelDir, array $data): void {
    $path = $modelDir . DIRECTORY_SEPARATOR . 'annotations.json';
    if (is_file($path)) {
        $existing = @file_get_contents($path);
        if ($existing === false || !is_array(json_decode($existing, true))) {
            @rename($path, $modelDir . DIRECTORY_SEPARATOR . 'annotations.json.broken-' . time());
        }
    }
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Failed to write annotations.json.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to replace annotations.json.');
    }
}

function reviewedPath(string $modelDir): string {
    return $modelDir . DIRECTORY_SEPARATOR . 'reviewed.json';
}

/** @return string[] filenames marked reviewed */
function loadReviewed(string $modelDir): array {
    $p = reviewedPath($modelDir);
    if (!is_file($p)) return [];
    $raw = @file_get_contents($p);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter($decoded, 'is_string'));
}

/** Add/remove a filename from the reviewed set. Returns the new state. */
function setReviewed(string $modelDir, string $filename, bool $value): bool {
    $set = loadReviewed($modelDir);
    $set = array_values(array_filter($set, fn($f) => $f !== $filename));
    if ($value) $set[] = $filename;
    sort($set);
    $tmp = reviewedPath($modelDir) . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($set, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($tmp, reviewedPath($modelDir));
    return $value;
}

function listModels(string $captureRoot): array {
    if (!is_dir($captureRoot)) return [];
    $out = [];
    foreach (glob($captureRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $out[] = [
            'slug' => basename($dir),
            'image_count' => count(glob($dir . DIRECTORY_SEPARATOR . '*.jpg') ?: []),
            'has_annotations' => is_file($dir . DIRECTORY_SEPARATOR . 'annotations.json'),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));
    return $out;
}

// ── JSON API ─────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action !== '') {
    $model = slugifyName($_GET['model'] ?? '');
    if ($model === '') jsonError('Invalid model.', 400);
    $modelDir = $captureRoot . DIRECTORY_SEPARATOR . $model;
    if (!is_dir($modelDir)) jsonError("Model '$model' not found.", 404);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'images' && $method === 'GET') {
        $loaded = loadAnnotations($modelDir, $model);
        $statusByFile = [];
        foreach ($loaded['data']['images'] ?? [] as $img) {
            if (isset($img['file'])) {
                $statusByFile[$img['file']] = $img['status'] ?? 'unlabeled';
            }
        }
        $reviewedSet = array_flip(loadReviewed($modelDir));
        $files = glob($modelDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        $names = array_map('basename', $files);
        sort($names);
        $images = array_map(fn($f) => [
            'file' => $f,
            'status' => $statusByFile[$f] ?? 'unlabeled',
            'reviewed' => isset($reviewedSet[$f]),
        ], $names);
        jsonResponse(['ok' => true, 'images' => $images]);
    }

    if ($action === 'annotations' && $method === 'GET') {
        $loaded = loadAnnotations($modelDir, $model);
        jsonResponse(['ok' => true, 'status' => $loaded['status'], 'data' => $loaded['data']]);
    }

    if ($action === 'save_annotations' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) jsonError('Body must be a JSON object.', 400);
        if (!isset($body['classes']) || !is_array($body['classes'])
            || !isset($body['images']) || !is_array($body['images'])) {
            jsonError('Body must contain `classes` and `images` arrays.', 400);
        }
        try {
            saveAnnotations($modelDir, $body);
        } catch (RuntimeException $e) {
            jsonError($e->getMessage(), 500);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'reviewed' && $method === 'POST') {
        $filename = $_GET['file'] ?? '';
        if (!isSafeFilename($filename)) jsonError('Invalid filename.', 400);
        if (!is_file($modelDir . DIRECTORY_SEPARATOR . $filename)) jsonError('Image not found.', 404);
        $body = json_decode(file_get_contents('php://input'), true);
        $value = is_array($body) ? (bool) ($body['reviewed'] ?? true) : true;
        $newState = setReviewed($modelDir, $filename, $value);
        jsonResponse(['ok' => true, 'reviewed' => $newState]);
    }

    jsonError('Unknown action.', 404);
}

// ── Page render ──────────────────────────────────────────────────────────────
$rawModel = $_GET['model'] ?? '';
$model = slugifyName($rawModel);

if ($model === '') {
    renderPicker(listModels($captureRoot));
    exit;
}
if (!is_dir($captureRoot . DIRECTORY_SEPARATOR . $model)) {
    http_response_code(404);
    renderNotFound($model);
    exit;
}
renderAnnotator($model);
exit;

// ── Templates ────────────────────────────────────────────────────────────────
function renderHeader(): void {
    ?>
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
        <li><a class="cta" href="annotate.php" aria-current="page">Annotate</a></li>
      </ul>
    </nav>
  </div>
</header>
<?php
}

function renderPicker(array $models): void {
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails — Pick a model</title>
  <link rel="stylesheet" href="annotate/style.css" />
</head>
<body>
<?php renderHeader(); ?>
<div class="error-box" style="background:rgba(15,23,42,.84)">
  <h2 style="color:var(--focus)">Pick a model</h2>
  <?php if ($models): ?>
    <ul style="list-style:none;padding:0;margin:0;text-align:left">
      <?php foreach ($models as $m): ?>
        <li style="padding:8px 0;border-bottom:1px solid var(--line)">
          <a href="annotate.php?model=<?= rawurlencode($m['slug']) ?>" style="color:#fff;text-decoration:none;font-weight:700"><?= htmlspecialchars($m['slug']) ?></a>
          <span style="color:var(--muted);font-size:12px"> — <?= (int) $m['image_count'] ?> images<?= $m['has_annotations'] ? ' · annotated' : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p style="color:var(--muted)">No models found in <code>recorded_routes/</code>.</p>
  <?php endif; ?>
</div>
</body>
</html>
<?php
}

function renderNotFound(string $model): void {
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Stay On Trails — Not found</title>
  <link rel="stylesheet" href="annotate/style.css" />
</head>
<body>
<?php renderHeader(); ?>
<div class="error-box">
  <h2>Model not found</h2>
  <p style="color:var(--muted)">No model named "<?= htmlspecialchars($model) ?>" in <code>recorded_routes/</code>.</p>
  <p><a href="annotate.php" style="color:var(--focus)">Back to model picker</a></p>
</div>
</body>
</html>
<?php
}

function renderAnnotator(string $model): void {
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails — Annotate <?= htmlspecialchars($model) ?></title>
  <link rel="stylesheet" href="annotate/style.css" />
</head>
<body>

<?php renderHeader(); ?>

<div class="nav-row">
  <div class="crumbs">
    <span class="crumb-up">STAY ON TRAILS</span>
    <span class="crumb-sep">›</span>
    <span class="crumb-up">ANNOTATE</span>
    <span class="crumb-sep">›</span>
    <span class="crumb-current" id="crumbFile">—</span>
    <span class="status-chip" id="statusChip"></span>
  </div>
  <div class="counter">
    <button class="ico-btn" id="prevBtn" type="button" title="Previous (←)" disabled>‹</button>
    <span class="counter-text"><span id="counterCur">0</span> / <span id="counterTot">0</span></span>
    <button class="ico-btn" id="nextBtn" type="button" title="Next (→)" disabled>›</button>
  </div>
  <div class="nav-actions">
    <span id="saveStatus"></span>
    <button class="btn btn-cyan" id="saveBtn" type="button" disabled>Save</button>
    <button class="check-btn" id="reviewedBtn" type="button" title="Mark reviewed" disabled>✓</button>
  </div>
</div>

<div id="banner" class="banner" style="display:none"></div>

<div class="anno-layout">

  <aside class="anno-panel sidebar">
    <div class="sidebar-head">
      <div class="sidebar-title">Annotations (<span id="segCount">0</span>)</div>
      <div class="sidebar-sub">Group: <span id="groupName">default</span></div>
    </div>

    <div class="tabs">
      <button class="tab active" data-tab="classes" type="button">Classes</button>
      <button class="tab" data-tab="layers" type="button">Layers</button>
    </div>

    <div class="tab-body" id="tabBody"></div>

    <div class="sidebar-section">
      <div class="sectionTitle">Unused classes</div>
      <div id="unusedList"></div>
    </div>

    <div class="sidebar-section">
      <div class="sectionTitle">Tags</div>
      <div id="tagsBox" class="tags-box">
        <div class="tags-empty">No Tags Applied</div>
        <div class="tags-list" id="tagsList"></div>
        <input type="text" class="tags-input" id="tagsInput"
               placeholder="Type and select tags below to add them to the image." />
      </div>
    </div>
  </aside>

  <section class="anno-panel">
    <div class="canvas-wrap" id="canvasWrap">
      <div id="stage"></div>
      <div class="canvas-idle" id="canvasIdle">Select an image to begin annotating</div>
      <div class="canvas-overlay" id="canvasOverlay" style="display:none">Predicting…</div>
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
        <div class="sectionTitle" style="margin-bottom:6px">Images (<span id="imgCount">0</span>)</div>
        <div class="right-img-list" id="rightImgList"></div>
      </div>

    </div>
  </aside>

</div>

<div id="modalRoot"></div>
<div id="popupRoot"></div>

<script>window.MODEL = <?= json_encode($model) ?>;</script>
<script src="https://unpkg.com/konva@9.3.16/konva.min.js"></script>
<script src="annotate/annotator.js"></script>

</body>
</html>
<?php
}
