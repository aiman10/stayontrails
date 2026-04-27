<?php
$captureRoot = __DIR__ . DIRECTORY_SEPARATOR . 'recorded_routes';

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function ensureDir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory.');
    }
}

function slugifyName(string $value): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'model';
}

function listImageUrls(string $captureRoot, string $modelName): array {
    $folderName = slugifyName($modelName);
    $folderPath = $captureRoot . DIRECTORY_SEPARATOR . $folderName;
    if (!is_dir($folderPath)) {
        return [];
    }

    $items = [];
    $files = glob($folderPath . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
    rsort($files, SORT_NATURAL);
    foreach ($files as $filePath) {
        $items[] = [
            'filename' => basename($filePath),
            'url' => 'recorded_routes/' . rawurlencode($folderName) . '/' . rawurlencode(basename($filePath)),
            'updatedAt' => date('c', filemtime($filePath) ?: time()),
        ];
    }
    return $items;
}

function listCaptureRows(string $captureRoot, string $modelName): array {
    $folderName = slugifyName($modelName);
    $folderPath = $captureRoot . DIRECTORY_SEPARATOR . $folderName;
    $csvPath = $folderPath . DIRECTORY_SEPARATOR . 'captures.csv';
    if (!is_file($csvPath)) {
        return [];
    }

    $imageUrlByFilename = [];
    foreach (listImageUrls($captureRoot, $modelName) as $image) {
        $imageUrlByFilename[$image['filename']] = $image['url'];
    }

    $rows = [];
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    while (($data = fgetcsv($handle)) !== false) {
        $row = is_array($header) ? array_combine($header, $data) : false;
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'filename' => (string)($row['filename'] ?? ''),
            'latitude' => is_numeric($row['latitude'] ?? null) ? (float)$row['latitude'] : null,
            'longitude' => is_numeric($row['longitude'] ?? null) ? (float)$row['longitude'] : null,
            'accuracy' => is_numeric($row['accuracy'] ?? null) ? (float)$row['accuracy'] : null,
            'url' => $imageUrlByFilename[(string)($row['filename'] ?? '')] ?? null,
        ];
    }
    fclose($handle);

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)$b['timestamp'], (string)$a['timestamp']);
    });

    return $rows;
}

function appendCaptureCsvRow(string $folderPath, array $row): void {
    $csvPath = $folderPath . DIRECTORY_SEPARATOR . 'captures.csv';
    $needsHeader = !is_file($csvPath) || filesize($csvPath) === 0;
    $handle = fopen($csvPath, 'ab');
    if ($handle === false) {
        throw new RuntimeException('Failed to open CSV file.');
    }

    if ($needsHeader) {
        fputcsv($handle, ['timestamp', 'filename', 'latitude', 'longitude', 'accuracy']);
    }

    fputcsv($handle, [
        (string)($row['timestamp'] ?? ''),
        (string)($row['filename'] ?? ''),
        $row['latitude'] ?? '',
        $row['longitude'] ?? '',
        $row['accuracy'] ?? '',
    ]);
    fclose($handle);
}

function deleteCaptureRecord(string $captureRoot, string $modelName, string $filename): array {
    $folderName = slugifyName($modelName);
    $folderPath = $captureRoot . DIRECTORY_SEPARATOR . $folderName;
    $filePath = $folderPath . DIRECTORY_SEPARATOR . basename($filename);
    if (!is_file($filePath)) {
        throw new RuntimeException('Image file not found.');
    }
    if (!unlink($filePath)) {
        throw new RuntimeException('Failed to delete image file.');
    }

    $csvPath = $folderPath . DIRECTORY_SEPARATOR . 'captures.csv';
    if (is_file($csvPath)) {
        $rows = [];
        $handle = fopen($csvPath, 'rb');
        if ($handle !== false) {
            $header = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== false) {
                $row = is_array($header) ? array_combine($header, $data) : false;
                if (!is_array($row)) {
                    continue;
                }
                if ((string)($row['filename'] ?? '') === basename($filename)) {
                    continue;
                }
                $rows[] = $row;
            }
            fclose($handle);
        }

        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Failed to rewrite CSV file.');
        }
        fputcsv($handle, ['timestamp', 'filename', 'latitude', 'longitude', 'accuracy']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                (string)($row['timestamp'] ?? ''),
                (string)($row['filename'] ?? ''),
                $row['latitude'] ?? '',
                $row['longitude'] ?? '',
                $row['accuracy'] ?? '',
            ]);
        }
        fclose($handle);
    }

    return [
        'folderName' => $folderName,
        'images' => listImageUrls($captureRoot, $modelName),
        'captures' => listCaptureRows($captureRoot, $modelName),
    ];
}

try {
    ensureDir($captureRoot);
} catch (RuntimeException $error) {
    jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_images') {
    $modelName = trim((string)($_GET['modelName'] ?? ''));
    if ($modelName === '') {
        jsonResponse(['ok' => false, 'error' => 'Model name is required.'], 400);
    }
    jsonResponse([
        'ok' => true,
        'folderName' => slugifyName($modelName),
        'images' => listImageUrls($captureRoot, $modelName),
        'captures' => listCaptureRows($captureRoot, $modelName),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_image') {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $modelName = trim((string)($payload['modelName'] ?? ''));
    $imageData = trim((string)($payload['imageData'] ?? ''));
    $timestamp = trim((string)($payload['timestamp'] ?? ''));
    $latitude = $payload['latitude'] ?? null;
    $longitude = $payload['longitude'] ?? null;
    $accuracy = $payload['accuracy'] ?? null;
    if ($modelName === '') {
        jsonResponse(['ok' => false, 'error' => 'Model name is required.'], 400);
    }
    if ($imageData === '' || !preg_match('/^data:image\/jpeg;base64,/', $imageData)) {
        jsonResponse(['ok' => false, 'error' => 'JPEG image data is required.'], 400);
    }

    $folderName = slugifyName($modelName);
    $folderPath = $captureRoot . DIRECTORY_SEPARATOR . $folderName;
    try {
        ensureDir($folderPath);
    } catch (RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
    }

    $base64 = preg_replace('/^data:image\/jpeg;base64,/', '', $imageData) ?? '';
    $binary = base64_decode($base64, true);
    if ($binary === false) {
        jsonResponse(['ok' => false, 'error' => 'Failed to decode image data.'], 400);
    }

    $filename = gmdate('Ymd_His') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8) . '.jpg';
    $filePath = $folderPath . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($filePath, $binary) === false) {
        jsonResponse(['ok' => false, 'error' => 'Failed to save image.'], 500);
    }

    try {
        appendCaptureCsvRow($folderPath, [
            'timestamp' => $timestamp !== '' ? $timestamp : gmdate('c'),
            'filename' => $filename,
            'latitude' => is_numeric($latitude) ? (float)$latitude : '',
            'longitude' => is_numeric($longitude) ? (float)$longitude : '',
            'accuracy' => is_numeric($accuracy) ? (float)$accuracy : '',
        ]);
    } catch (RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
    }

    jsonResponse([
        'ok' => true,
        'folderName' => $folderName,
        'filename' => $filename,
        'url' => 'recorded_routes/' . rawurlencode($folderName) . '/' . rawurlencode($filename),
        'csvUrl' => 'recorded_routes/' . rawurlencode($folderName) . '/captures.csv',
        'images' => listImageUrls($captureRoot, $modelName),
        'captures' => listCaptureRows($captureRoot, $modelName),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_image') {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $modelName = trim((string)($payload['modelName'] ?? ''));
    $filename = trim((string)($payload['filename'] ?? ''));
    if ($modelName === '' || $filename === '') {
        jsonResponse(['ok' => false, 'error' => 'Model name and filename are required.'], 400);
    }

    try {
        $result = deleteCaptureRecord($captureRoot, $modelName, $filename);
    } catch (RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 400);
    }

    jsonResponse([
        'ok' => true,
        'folderName' => $result['folderName'],
        'images' => $result['images'],
        'captures' => $result['captures'],
    ]);
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails - Record Route</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
    html,body{margin:0;min-height:100%;background:#020617;color:#fff;font-family:Arial,Helvetica,sans-serif}
    a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1420px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .layout{max-width:1420px;margin:0 auto;padding:18px;display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.9fr);gap:18px}
    .panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.26)}
    .mainPanel,.sidePanel{padding:16px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .field{display:flex;flex-direction:column;gap:6px;min-width:180px}
    .field label,.sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .field input{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--line);background:#020617;color:#fff;font-size:14px}
    .btn{padding:11px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:#334155}
    .btnPrimary{background:var(--accent);color:var(--accent-ink);border-color:rgba(250,204,21,.35)}
    .btnPrimary:hover{background:#fde047}
    .btn:disabled{opacity:.55;cursor:not-allowed}
    .status{margin-top:12px;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:14px;color:var(--muted)}
    .status.ok{color:#d1fae5;border-color:rgba(52,211,153,.35);background:rgba(16,185,129,.08)}
    .status.warn{color:#ffe4e6;border-color:rgba(251,113,133,.35);background:rgba(244,63,94,.08)}
    .cameraWrap{margin-top:14px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#000}
    #cameraVideo{display:block;width:100%;height:58vh;object-fit:cover;background:#000}
    #recordedMap{height:34vh;min-height:260px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line);margin-top:14px}
    .card{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
    .stat{padding:12px;border-radius:14px;background:#0b1220;border:1px solid var(--line)}
    .statLabel{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px}
    .statValue{font-size:20px;font-weight:700}
    .thumbGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:10px;margin-top:12px}
    .thumbItem{position:relative}
    .thumb{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:12px;border:1px solid var(--line);background:#000}
    .thumbMeta{font-size:12px;color:var(--muted);margin-top:4px;word-break:break-word}
    .thumbDelete{position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:999px;border:0;background:rgba(15,23,42,.9);color:#fff;font-size:16px;line-height:1;cursor:pointer}
    .thumbDelete:hover{background:#ef4444}
    .mapInfoCard{margin-top:14px}
    .popupThumb{display:block;width:180px;height:auto;border-radius:10px;border:1px solid rgba(0,0,0,.12);margin-bottom:8px}
    .hidden{display:none}
    .leaflet-div-icon{background:transparent;border:0}
    .captureBubble{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#22d3ee;color:#082f49;font-weight:700;border:2px solid rgba(15,23,42,.92)}
    #captureCanvas{display:none}
    @media (max-width: 1100px){
      .layout{grid-template-columns:1fr}
      #cameraVideo{height:40vh}
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
        <li><a class="cta" href="#" aria-current="page">Record route</a></li>
      </ul>
    </nav>
  </div>
</header>

<main class="layout">
  <section class="panel mainPanel">
    <div class="toolbar">
      <div class="field">
        <label for="modelName">Model name</label>
        <input id="modelName" type="text" placeholder="Enter model name" />
      </div>
      <div class="field">
        <label for="captureDelay">Delay in seconds</label>
        <input id="captureDelay" type="number" min="1" step="1" value="5" />
      </div>
      <button id="startBtn" class="btn btnPrimary" type="button">Start</button>
      <button id="pauseBtn" class="btn" type="button" disabled>Pause</button>
      <button id="stopBtn" class="btn" type="button" disabled>Stop</button>
    </div>
    <div id="status" class="status">Enter a model name and start recording.</div>
    <div class="cameraWrap">
      <video id="cameraVideo" autoplay playsinline muted></video>
    </div>
    <div class="card mapInfoCard">
      <div class="sectionTitle">Recorded Route Map</div>
      <div id="recordedMap"></div>
      <div id="mapSelectionMeta" class="status" style="margin-top:12px">Click a recorded marker to see the picture, time, and coordinates.</div>
    </div>
    <canvas id="captureCanvas" width="640" height="480"></canvas>
  </section>

  <aside class="panel sidePanel">
    <div class="card">
      <div class="sectionTitle">Capture Status</div>
      <div class="stats">
        <div class="stat">
          <div class="statLabel">Folder</div>
          <div id="folderValue" class="statValue">--</div>
        </div>
        <div class="stat">
          <div class="statLabel">Saved Images</div>
          <div id="savedCountValue" class="statValue">0</div>
        </div>
        <div class="stat">
          <div class="statLabel">Latitude</div>
          <div id="latitudeValue" class="statValue">--</div>
        </div>
        <div class="stat">
          <div class="statLabel">Longitude</div>
          <div id="longitudeValue" class="statValue">--</div>
        </div>
      </div>
      <div id="gpsMeta" class="status" style="margin-top:12px">GPS not started.</div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="sectionTitle">Latest Thumbnails</div>
      <div id="latestThumbs" class="thumbGrid"></div>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button id="showAllBtn" class="btn" type="button">Show All</button>
        <button id="annotateBtn" class="btn" type="button"
                style="background:#164e63;color:#22d3ee;border-color:rgba(34,211,238,.35)">
          Annotate
        </button>
      </div>
      <div id="allThumbs" class="thumbGrid hidden"></div>
    </div>
  </aside>
</main>

<script>
  const API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const MAX_PREVIEW_IMAGES = 10;

  const modelNameEl = document.getElementById("modelName");
  const captureDelayEl = document.getElementById("captureDelay");
  const startBtn = document.getElementById("startBtn");
  const pauseBtn = document.getElementById("pauseBtn");
  const stopBtn = document.getElementById("stopBtn");
  const statusEl = document.getElementById("status");
  const cameraVideoEl = document.getElementById("cameraVideo");
  const captureCanvasEl = document.getElementById("captureCanvas");
  const folderValueEl = document.getElementById("folderValue");
  const savedCountValueEl = document.getElementById("savedCountValue");
  const latitudeValueEl = document.getElementById("latitudeValue");
  const longitudeValueEl = document.getElementById("longitudeValue");
  const gpsMetaEl = document.getElementById("gpsMeta");
  const latestThumbsEl = document.getElementById("latestThumbs");
  const allThumbsEl = document.getElementById("allThumbs");
  const showAllBtn = document.getElementById("showAllBtn");
  const mapSelectionMetaEl = document.getElementById("mapSelectionMeta");

  const recordedMap = L.map("recordedMap").setView([50.8503, 4.3517], 16);
  const recordedStreetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(recordedMap);

  let stream = null;
  let captureTimer = null;
  let recordingActive = false;
  let paused = false;
  let allVisible = false;
  let latestImages = [];
  let geoWatchId = null;
  let latestLatitude = null;
  let latestLongitude = null;
  let latestAccuracy = null;
  let captureMarkers = [];
  let captureLine = null;
  let latestCaptures = [];

  function setStatus(message, tone = "") {
    statusEl.textContent = message;
    statusEl.className = `status${tone ? ` ${tone}` : ""}`;
  }

  function slugifyName(value) {
    return String(value || "")
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "") || "model";
  }

  function updateButtons() {
    startBtn.disabled = recordingActive && !paused;
    pauseBtn.disabled = !recordingActive;
    stopBtn.disabled = !recordingActive;
    pauseBtn.textContent = paused ? "Resume" : "Pause";
  }

  function updateGpsUi() {
    latitudeValueEl.textContent = Number.isFinite(latestLatitude) ? latestLatitude.toFixed(6) : "--";
    longitudeValueEl.textContent = Number.isFinite(latestLongitude) ? latestLongitude.toFixed(6) : "--";
    if (Number.isFinite(latestLatitude) && Number.isFinite(latestLongitude)) {
      gpsMetaEl.textContent = Number.isFinite(latestAccuracy)
        ? `GPS active | accuracy ${latestAccuracy.toFixed(1)} m`
        : "GPS active";
      gpsMetaEl.className = "status ok";
      return;
    }
    gpsMetaEl.textContent = geoWatchId !== null ? "Waiting for GPS..." : "GPS not started.";
    gpsMetaEl.className = "status";
  }

  function startGpsTracking() {
    if (!("geolocation" in navigator) || geoWatchId !== null) {
      updateGpsUi();
      return;
    }
    try {
      geoWatchId = navigator.geolocation.watchPosition(
        (position) => {
          const lat = Number(position?.coords?.latitude);
          const lng = Number(position?.coords?.longitude);
          const accuracy = Number(position?.coords?.accuracy);
          latestLatitude = Number.isFinite(lat) ? lat : null;
          latestLongitude = Number.isFinite(lng) ? lng : null;
          latestAccuracy = Number.isFinite(accuracy) ? accuracy : null;
          updateGpsUi();
        },
        () => {
          latestLatitude = null;
          latestLongitude = null;
          latestAccuracy = null;
          gpsMetaEl.textContent = "GPS permission denied or unavailable.";
          gpsMetaEl.className = "status warn";
        },
        { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
      );
      updateGpsUi();
    } catch {
      gpsMetaEl.textContent = "Failed to start GPS tracking.";
      gpsMetaEl.className = "status warn";
    }
  }

  function stopGpsTracking() {
    if (geoWatchId !== null) {
      try { navigator.geolocation.clearWatch(geoWatchId); } catch {}
      geoWatchId = null;
    }
    latestLatitude = null;
    latestLongitude = null;
    latestAccuracy = null;
    updateGpsUi();
  }

  async function startCamera() {
    if (stream) {
      return;
    }
    stream = await navigator.mediaDevices.getUserMedia({
      video: {
        width: { ideal: 640 },
        height: { ideal: 480 },
        facingMode: "environment"
      },
      audio: false
    });
    cameraVideoEl.srcObject = stream;
    await cameraVideoEl.play();
  }

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    cameraVideoEl.srcObject = null;
  }

  function renderThumbGrid(container, images) {
    if (!images.length) {
      container.innerHTML = `<div class="thumbMeta">No images yet.</div>`;
      return;
    }
    container.innerHTML = images.map((image) => `
      <div class="thumbItem">
        <button class="thumbDelete" type="button" data-filename="${escapeHtml(image.filename)}" aria-label="Delete ${escapeHtml(image.filename)}">&times;</button>
        <img class="thumb" src="${image.url}" alt="${image.filename}">
        <div class="thumbMeta">${image.filename}</div>
      </div>
    `).join("");

    container.querySelectorAll("[data-filename]").forEach((button) => {
      button.addEventListener("click", () => {
        const filename = button.getAttribute("data-filename");
        if (filename) {
          deleteImage(filename).catch(() => setStatus("Failed to delete image.", "warn"));
        }
      });
    });
  }

  function renderImages() {
    savedCountValueEl.textContent = String(latestImages.length);
    renderThumbGrid(latestThumbsEl, latestImages.slice(0, MAX_PREVIEW_IMAGES));
    renderThumbGrid(allThumbsEl, latestImages);
    allThumbsEl.classList.toggle("hidden", !allVisible);
    showAllBtn.textContent = allVisible ? "Hide All" : "Show All";
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function renderCaptureMap() {
    captureMarkers.forEach((marker) => recordedMap.removeLayer(marker));
    captureMarkers = [];
    if (captureLine) {
      recordedMap.removeLayer(captureLine);
      captureLine = null;
    }

    const validCaptures = latestCaptures
      .filter((capture) => Number.isFinite(capture.latitude) && Number.isFinite(capture.longitude))
      .slice()
      .reverse();

    if (!validCaptures.length) {
      recordedMap.setView([50.8503, 4.3517], 16);
      mapSelectionMetaEl.textContent = "No recorded GPS points yet.";
      return;
    }

    captureLine = L.polyline(validCaptures.map((capture) => [capture.latitude, capture.longitude]), {
      color: "#22d3ee",
      weight: 4,
      opacity: 0.85
    }).addTo(recordedMap);

    validCaptures.forEach((capture, index) => {
      const marker = L.marker([capture.latitude, capture.longitude], {
        icon: L.divIcon({
          className: "",
          html: `<div class="captureBubble">${index + 1}</div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 14]
        })
      }).addTo(recordedMap);

      const popupHtml = `
        <div>
          ${capture.url ? `<img class="popupThumb" src="${capture.url}" alt="${escapeHtml(capture.filename)}">` : ""}
          <div><strong>${escapeHtml(capture.filename)}</strong></div>
          <div>${escapeHtml(capture.timestamp || "--")}</div>
          <div>${Number(capture.latitude).toFixed(6)}, ${Number(capture.longitude).toFixed(6)}</div>
          <button class="btn" type="button" onclick="window.deleteRecordedImage('${escapeHtml(capture.filename)}')">Delete</button>
        </div>
      `;
      marker.bindPopup(popupHtml);
      marker.on("click", () => {
        mapSelectionMetaEl.textContent = `${capture.filename} | ${capture.timestamp} | ${Number(capture.latitude).toFixed(6)}, ${Number(capture.longitude).toFixed(6)}`;
      });
      captureMarkers.push(marker);
    });

    recordedMap.fitBounds(validCaptures.map((capture) => [capture.latitude, capture.longitude]), { padding: [30, 30] });
  }

  async function refreshImages() {
    const modelName = modelNameEl.value.trim();
    if (!modelName) {
      latestImages = [];
      folderValueEl.textContent = "--";
      renderImages();
      return;
    }

    const response = await fetch(`${API_URL}?action=list_images&modelName=${encodeURIComponent(modelName)}`);
    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to list saved images.", "warn");
      return;
    }
    folderValueEl.textContent = payload.folderName || slugifyName(modelName);
    latestImages = Array.isArray(payload.images) ? payload.images : [];
    latestCaptures = Array.isArray(payload.captures) ? payload.captures : [];
    renderImages();
    renderCaptureMap();
  }

  async function saveCurrentFrame() {
    if (!stream || !cameraVideoEl.videoWidth || !cameraVideoEl.videoHeight) {
      return;
    }

    const modelName = modelNameEl.value.trim();
    if (!modelName) {
      setStatus("Model name is required.", "warn");
      return;
    }

    const ctx = captureCanvasEl.getContext("2d");
    captureCanvasEl.width = 640;
    captureCanvasEl.height = 480;
    ctx.drawImage(cameraVideoEl, 0, 0, 640, 480);
    const imageData = captureCanvasEl.toDataURL("image/jpeg", 0.9);

    const response = await fetch(`${API_URL}?action=save_image`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        modelName,
        imageData,
        timestamp: new Date().toISOString(),
        latitude: latestLatitude,
        longitude: latestLongitude,
        accuracy: latestAccuracy
      })
    });

    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to save image.", "warn");
      return;
    }

    folderValueEl.textContent = payload.folderName || slugifyName(modelName);
    latestImages = Array.isArray(payload.images) ? payload.images : [];
    latestCaptures = Array.isArray(payload.captures) ? payload.captures : latestCaptures;
    renderImages();
    renderCaptureMap();
    setStatus(`Saved ${payload.filename}.`, "ok");
  }

  async function deleteImage(filename) {
    const modelName = modelNameEl.value.trim();
    if (!modelName || !filename) {
      setStatus("Model name and filename are required.", "warn");
      return;
    }

    const response = await fetch(`${API_URL}?action=delete_image`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        modelName,
        filename
      })
    });

    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to delete image.", "warn");
      return;
    }

    folderValueEl.textContent = payload.folderName || slugifyName(modelName);
    latestImages = Array.isArray(payload.images) ? payload.images : [];
    latestCaptures = Array.isArray(payload.captures) ? payload.captures : [];
    renderImages();
    renderCaptureMap();
    mapSelectionMetaEl.textContent = "Click a recorded marker to see the picture, time, and coordinates.";
    setStatus(`Deleted ${filename}.`, "ok");
  }

  function clearCaptureTimer() {
    if (captureTimer !== null) {
      clearInterval(captureTimer);
      captureTimer = null;
    }
  }

  async function startRecording() {
    const modelName = modelNameEl.value.trim();
    const delaySeconds = Number.parseInt(captureDelayEl.value, 10);
    if (!modelName) {
      setStatus("Enter a model name first.", "warn");
      modelNameEl.focus();
      return;
    }
    if (!Number.isFinite(delaySeconds) || delaySeconds < 1) {
      setStatus("Delay must be at least 1 second.", "warn");
      captureDelayEl.focus();
      return;
    }

    try {
      await startCamera();
      startGpsTracking();
    } catch (error) {
      console.error("Camera start failed:", error);
      setStatus("Camera permission denied or unavailable.", "warn");
      return;
    }

    paused = false;
    recordingActive = true;
    clearCaptureTimer();
    captureTimer = setInterval(() => {
      saveCurrentFrame().catch(() => setStatus("Failed to save image.", "warn"));
    }, delaySeconds * 1000);

    folderValueEl.textContent = slugifyName(modelName);
    updateButtons();
    setStatus(`Recording ${modelName} every ${delaySeconds} second(s).`, "ok");
    refreshImages().catch(() => setStatus("Failed to list saved images.", "warn"));
  }

  function pauseRecording() {
    if (!recordingActive) {
      return;
    }
    if (paused) {
      paused = false;
      const delaySeconds = Number.parseInt(captureDelayEl.value, 10);
      clearCaptureTimer();
      captureTimer = setInterval(() => {
        saveCurrentFrame().catch(() => setStatus("Failed to save image.", "warn"));
      }, delaySeconds * 1000);
      setStatus("Recording resumed.", "ok");
    } else {
      paused = true;
      clearCaptureTimer();
      setStatus("Recording paused.", "ok");
    }
    updateButtons();
  }

  function stopRecording() {
    clearCaptureTimer();
    stopCamera();
    stopGpsTracking();
    recordingActive = false;
    paused = false;
    updateButtons();
    setStatus("Recording stopped.", "ok");
  }

  startBtn.addEventListener("click", () => {
    startRecording().catch(() => setStatus("Failed to start recording.", "warn"));
  });
  pauseBtn.addEventListener("click", pauseRecording);
  stopBtn.addEventListener("click", stopRecording);
  showAllBtn.addEventListener("click", () => {
    allVisible = !allVisible;
    renderImages();
  });
  document.getElementById("annotateBtn").addEventListener("click", () => {
    const raw = modelNameEl.value.trim();
    if (!raw) { setStatus("Enter a model name first.", "warn"); return; }
    window.location.href = "annotate.php?model=" + encodeURIComponent(slugifyName(raw));
  });
  modelNameEl.addEventListener("change", () => {
    refreshImages().catch(() => setStatus("Failed to list saved images.", "warn"));
  });

  renderImages();
  renderCaptureMap();
  updateButtons();
  updateGpsUi();
  window.deleteRecordedImage = (filename) => {
    deleteImage(filename).catch(() => setStatus("Failed to delete image.", "warn"));
  };
</script>
</body>
</html>
