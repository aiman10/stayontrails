<?php
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'saved_paths';

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function ensureStorageDir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create storage directory.');
    }
}

function slugifyRouteName(string $value): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'path-' . date('Ymd-His');
}

function normalizeModelName(string $value): string {
    $normalized = trim(strtolower($value));
    return match ($normalized) {
        '1' => 'unrealsim',
        '2' => 'laerbeekbos',
        '3' => 'kaai',
        'unrealsim', 'laerbeekbos', 'kaai', 'denham' => $normalized,
        default => 'unrealsim',
    };
}

function getDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function getRouteLengthMeters(array $points): float {
    $total = 0.0;
    for ($index = 1; $index < count($points); $index++) {
        $previous = $points[$index - 1];
        $current = $points[$index];
        $total += getDistanceMeters(
            (float)$previous['lat'],
            (float)$previous['lng'],
            (float)$current['lat'],
            (float)$current['lng']
        );
    }
    return $total;
}

function listSavedPaths(string $storageDir): array {
    if (!is_dir($storageDir)) {
        return [];
    }

    $items = [];
    $files = glob($storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    natsort($files);
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $slug = pathinfo($filename, PATHINFO_FILENAME);
        $raw = file_get_contents($filePath);
        $decoded = json_decode($raw ?: '', true);
        $items[] = [
            'slug' => $slug,
            'filename' => $filename,
            'name' => is_array($decoded) && isset($decoded['name']) ? (string)$decoded['name'] : $slug,
            'updatedAt' => date('c', filemtime($filePath) ?: time()),
            'pointCount' => is_array($decoded['points'] ?? null) ? count($decoded['points']) : 0,
        ];
    }

    return $items;
}

function loadSavedPath(string $storageDir, string $slug): ?array {
    $safeSlug = slugifyRouteName($slug);
    $filePath = $storageDir . DIRECTORY_SEPARATOR . $safeSlug . '.json';
    if (!is_file($filePath)) {
        return null;
    }

    $decoded = json_decode(file_get_contents($filePath) ?: '', true);
    return is_array($decoded) ? $decoded : null;
}

function deleteSavedPath(string $storageDir, string $slug): bool {
    $safeSlug = slugifyRouteName($slug);
    $filePath = $storageDir . DIRECTORY_SEPARATOR . $safeSlug . '.json';
    if (!is_file($filePath)) {
        return false;
    }
    return unlink($filePath);
}

try {
    ensureStorageDir($storageDir);
} catch (RuntimeException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'list_paths') {
        jsonResponse(['ok' => true, 'paths' => listSavedPaths($storageDir)]);
    }

    if ($_GET['action'] === 'load_path') {
        $slug = (string)($_GET['slug'] ?? '');
        if ($slug === '') {
            jsonResponse(['ok' => false, 'error' => 'Missing path slug.'], 400);
        }

        $path = loadSavedPath($storageDir, $slug);
        if ($path === null) {
            jsonResponse(['ok' => false, 'error' => 'Path not found.'], 404);
        }

        jsonResponse(['ok' => true, 'path' => $path]);
    }

    jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_path') {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $name = trim((string)($payload['name'] ?? ''));
    $slug = slugifyRouteName((string)($payload['slug'] ?? $name));
    $points = $payload['points'] ?? null;
    $language = trim((string)($payload['language'] ?? 'nl-BE'));
    $headingFeedbackFps = $payload['headingFeedbackFps'] ?? 1;
    $modelConfidence = $payload['modelConfidence'] ?? 0.5;
    $model = normalizeModelName((string)($payload['model'] ?? 'unrealsim'));
    $returnMasks = filter_var($payload['returnMasks'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $sendMQTT = filter_var($payload['sendMQTT'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($name === '') {
        jsonResponse(['ok' => false, 'error' => 'Path name is required.'], 400);
    }
    if (!is_array($points) || count($points) < 2) {
        jsonResponse(['ok' => false, 'error' => 'At least two points are required.'], 400);
    }
    if (!in_array($language, ['nl-BE', 'en-GB'], true)) {
        $language = 'nl-BE';
    }
    $modelConfidence = is_numeric($modelConfidence) ? (float)$modelConfidence : 0.5;
    if ($modelConfidence < 0 || $modelConfidence > 1) {
        jsonResponse(['ok' => false, 'error' => 'Model confidence must be between 0 and 1.'], 400);
    }
    $headingFeedbackFps = is_numeric($headingFeedbackFps) ? (float)$headingFeedbackFps : 1;
    if ($headingFeedbackFps < 0.2 || $headingFeedbackFps > 10) {
        jsonResponse(['ok' => false, 'error' => 'Heading feedback FPS must be between 0.2 and 10.'], 400);
    }

    $normalizedPoints = [];
    foreach ($points as $index => $point) {
        $lat = $point['lat'] ?? null;
        $lng = $point['lng'] ?? ($point['lon'] ?? null);
        if (!is_array($point) || !is_numeric($lat) || !is_numeric($lng)) {
            jsonResponse(['ok' => false, 'error' => 'Each point needs numeric lat/lng values.'], 400);
        }

        $normalizedPoints[] = [
            'id' => (string)($point['id'] ?? ('point-' . ($index + 1))),
            'lat' => round((float)$lat, 6),
            'lng' => round((float)$lng, 6),
            'lon' => round((float)$lng, 6),
            'instruction' => trim((string)($point['instruction'] ?? '')),
        ];
    }

    $document = [
        'name' => $name,
        'slug' => $slug,
        'language' => $language,
        'model' => $model,
        'modelConfidence' => round($modelConfidence, 2),
        'returnMasks' => $returnMasks,
        'sendMQTT' => $sendMQTT,
        'headingFeedbackFps' => round($headingFeedbackFps, 2),
        'routeLengthMeters' => round(getRouteLengthMeters($normalizedPoints), 1),
        'updatedAt' => gmdate('c'),
        'pointCount' => count($normalizedPoints),
        'points' => $normalizedPoints,
    ];

    $target = $storageDir . DIRECTORY_SEPARATOR . $slug . '.json';
    $written = file_put_contents($target, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($written === false) {
        jsonResponse(['ok' => false, 'error' => 'Unable to save route JSON.'], 500);
    }

    jsonResponse([
        'ok' => true,
        'message' => 'Path saved.',
        'filename' => basename($target),
        'path' => $document,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_path') {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $slug = trim((string)($payload['slug'] ?? ''));
    if ($slug === '') {
        jsonResponse(['ok' => false, 'error' => 'Path slug is required.'], 400);
    }

    $existingPath = loadSavedPath($storageDir, $slug);
    if ($existingPath === null) {
        jsonResponse(['ok' => false, 'error' => 'Path not found.'], 404);
    }

    if (!deleteSavedPath($storageDir, $slug)) {
        jsonResponse(['ok' => false, 'error' => 'Unable to delete path JSON.'], 500);
    }

    jsonResponse([
        'ok' => true,
        'message' => 'Path deleted.',
        'slug' => slugifyRouteName($slug),
    ]);
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails - Path Builder</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    :root{
      --focus:#22d3ee;
      --menu-bg:rgba(15,23,42,.94);
      --menu-border:rgba(255,255,255,.14);
      --accent:#facc15;
      --accent-ink:#111827;
      --panel:#0f172a;
      --panel-soft:#111827;
      --line:rgba(255,255,255,.12);
      --muted:#cbd5e1;
      --ok:#34d399;
      --warn:#fb7185;
    }
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%;background:linear-gradient(160deg,#020617,#0f172a 48%,#111827);color:#fff;font-family:Arial,Helvetica,sans-serif}
    a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1480px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .layout{max-width:1480px;margin:0 auto;padding:18px;display:grid;grid-template-columns:minmax(0,1.8fr) minmax(320px,.95fr);gap:18px}
    .panel{background:rgba(15,23,42,.82);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.28)}
    .mapPanel{padding:14px}
    .sidePanel{padding:16px;display:flex;flex-direction:column;gap:14px}
    .mapToolbar{display:grid;grid-template-columns:2fr 1.25fr auto auto auto auto;gap:10px;align-items:end;margin-bottom:12px}
    .field{display:flex;flex-direction:column;gap:6px}
    .field label,.sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .field input,.field textarea,.field select{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--line);background:#020617;color:#fff;font-size:14px}
    .field textarea{min-height:92px;resize:vertical}
    .btn{padding:11px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:#334155}
    .btnPrimary{background:var(--accent);color:var(--accent-ink);border-color:rgba(250,204,21,.35)}
    .btnPrimary:hover{background:#fde047}
    .btnDanger{background:#3f1d24}
    .btnDanger:hover{background:#5b2430}
    #map{height:72vh;min-height:520px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line)}
    .help{margin:10px 0 0;color:var(--muted);font-size:14px;line-height:1.5}
    .status{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:14px;color:var(--muted)}
    .status.ok{color:#d1fae5;border-color:rgba(52,211,153,.35);background:rgba(16,185,129,.08)}
    .status.warn{color:#ffe4e6;border-color:rgba(251,113,133,.35);background:rgba(244,63,94,.08)}
    .pointMeta{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .card{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .list{display:flex;flex-direction:column;gap:10px;max-height:34vh;overflow:auto;padding-right:4px}
    .pointItem{padding:12px;border-radius:14px;border:1px solid var(--line);background:#0b1220;cursor:pointer}
    .pointItem.active{border-color:rgba(34,211,238,.55);background:#0f1a2c}
    .pointItemHeader{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
    .pointIndex{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:var(--accent);color:var(--accent-ink);font-weight:700;font-size:13px}
    .pointCoords,.pointInstruction{font-size:13px;line-height:1.45;color:var(--muted)}
    .pointInstruction{color:#fff}
    .exportBox{width:100%;min-height:220px;padding:12px;border-radius:14px;border:1px solid var(--line);background:#020617;color:#cbd5e1;font-family:Consolas,monospace;font-size:12px;line-height:1.5;resize:vertical}
    .badgeRow{display:flex;gap:8px;flex-wrap:wrap}
    .badge{padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid var(--line);font-size:12px;color:var(--muted)}
    .leaflet-div-icon{background:transparent;border:0}
    .markerBubble{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--accent);color:var(--accent-ink);font-weight:700;border:2px solid rgba(15,23,42,.9);box-shadow:0 8px 18px rgba(0,0,0,.28)}
    .markerBubble.active{background:#22d3ee}
    @media (max-width: 1080px){
      .layout{grid-template-columns:1fr}
      .mapToolbar{grid-template-columns:1fr 1fr}
      #map{height:56vh;min-height:380px}
    }
    @media (max-width: 720px){
      .layout{padding:12px}
      .mapToolbar{grid-template-columns:1fr}
      .pointMeta{grid-template-columns:1fr}
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
        <li><a href="followpath.php">Start route</a></li>
        <li><a href="remoteHelp.php">Remote assistant</a></li>
        <li><a class="cta" href="#" aria-current="page">Path builder</a></li>
      </ul>
    </nav>
  </div>
</header>

<main class="layout">
  <section class="panel mapPanel">
    <div class="mapToolbar">
      <div class="field">
        <label for="pathName">Path Name</label>
        <input id="pathName" type="text" placeholder="Laerbeekbos north loop" />
      </div>
      <div class="field">
        <label for="savedPaths">Open Saved Path</label>
        <select id="savedPaths">
          <option value="">Choose a saved path...</option>
        </select>
      </div>
      <button id="saveBtn" class="btn btnPrimary" type="button">Save route</button>
      <button id="satelliteToggleBtn" class="btn" type="button">Show Satellite</button>
      <button id="undoBtn" class="btn" type="button">Undo</button>
      <button id="clearBtn" class="btn btnDanger" type="button">Clear</button>
    </div>
    <div id="map" aria-label="Interactive route map"></div>
    <p class="help">Click the map to add route points. Select a point in the list to edit its spoken instruction, drag markers to refine the route, and save the JSON when the path is ready.</p>
  </section>

  <aside class="panel sidePanel">
    <div id="status" class="status">Ready. Add the first point on the map.</div>

    <div class="card">
      <div class="sectionTitle">Path Settings</div>
      <div class="pointMeta" style="margin-top:12px">
        <div class="field">
          <label for="pathLanguage">Voice Language</label>
          <select id="pathLanguage">
            <option value="nl-BE">Nederlands (Belgie)</option>
            <option value="en-GB">English (UK)</option>
          </select>
        </div>
        <div class="field">
          <label for="headingFeedbackFps">Heading Feedback FPS</label>
          <input id="headingFeedbackFps" type="number" min="0.2" max="10" step="0.1" value="1.0" />
        </div>
        <div class="field">
          <label for="pathModel">Model</label>
          <select id="pathModel">
            <option value="unrealsim">Simulation</option>
            <option value="laerbeekbos">Laerbeekbos (Brussels)</option>
            <option value="kaai">Kaai (Ehb)</option>
            <option value="denham">Den ham (Zellik)</option>
          </select>
        </div>
        <div class="field">
          <label for="pathModelConfidence">Model Confidence</label>
          <input id="pathModelConfidence" type="number" min="0" max="1" step="0.05" value="0.5" />
        </div>
        <div class="field">
          <label for="pathReturnMasks">Display Mask</label>
          <select id="pathReturnMasks">
            <option value="true">Yes</option>
            <option value="false">No</option>
          </select>
        </div>
        <div class="field">
          <label for="pathSendMQTT">Activate MQTT servo</label>
          <select id="pathSendMQTT">
            <option value="true">Yes</option>
            <option value="false">No</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="sectionTitle">Selected Point</div>
      <div class="badgeRow" style="margin-top:10px">
        <span class="badge">Points: <span id="pointCount">0</span></span>
        <span class="badge">Selected: <span id="selectedLabel">None</span></span>
      </div>
      <div class="pointMeta" style="margin-top:12px">
        <div class="field">
          <label for="pointLat">Latitude</label>
          <input id="pointLat" type="number" step="0.000001" />
        </div>
        <div class="field">
          <label for="pointLng">Longitude</label>
          <input id="pointLng" type="number" step="0.000001" />
        </div>
      </div>
      <div class="field" style="margin-top:12px">
        <label for="pointInstruction">Navigation Instruction</label>
        <textarea id="pointInstruction" placeholder="Take the next street left."></textarea>
      </div>
      <div class="badgeRow">
        <button id="applyBtn" class="btn" type="button">Update Point</button>
        <button id="deleteBtn" class="btn btnDanger" type="button">Delete Point</button>
      </div>
    </div>

    <div class="card">
      <div class="sectionTitle">Turn-By-Turn Points</div>
      <div id="pointList" class="list" style="margin-top:12px"></div>
    </div>

    <div class="card">
      <div class="sectionTitle">Export JSON</div>
      <textarea id="exportBox" class="exportBox" readonly></textarea>
    </div>

    <div class="card">
      <button id="deleteTrailBtn" class="btn btnDanger" type="button" style="width:100%">DELETE ROUTE</button>
    </div>
  </aside>
</main>

<script>
  const API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const DEFAULT_CENTER = [50.8503, 4.3517];
  const LAST_SELECTED_PATH_STORAGE_KEY = "stayontrails.lastSelectedPathSlug";
  const urlParams = new URLSearchParams(window.location.search);
  const launchNewRoute = urlParams.get("new") === "1";
  const map = L.map("map").setView(DEFAULT_CENTER, 16);

  const streetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 20,
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(map);
  const satelliteLayer = L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}", {
    attribution: "Tiles &copy; Esri"
  });

  const pathNameEl = document.getElementById("pathName");
  const savedPathsEl = document.getElementById("savedPaths");
  const satelliteToggleBtn = document.getElementById("satelliteToggleBtn");
  const statusEl = document.getElementById("status");
  const pointListEl = document.getElementById("pointList");
  const exportBoxEl = document.getElementById("exportBox");
  const deleteTrailBtn = document.getElementById("deleteTrailBtn");
  const pointCountEl = document.getElementById("pointCount");
  const selectedLabelEl = document.getElementById("selectedLabel");
  const pathLanguageEl = document.getElementById("pathLanguage");
  const headingFeedbackFpsEl = document.getElementById("headingFeedbackFps");
  const pathModelEl = document.getElementById("pathModel");
  const pathModelConfidenceEl = document.getElementById("pathModelConfidence");
  const pathReturnMasksEl = document.getElementById("pathReturnMasks");
  const pathSendMQTTEl = document.getElementById("pathSendMQTT");
  const pointLatEl = document.getElementById("pointLat");
  const pointLngEl = document.getElementById("pointLng");
  const pointInstructionEl = document.getElementById("pointInstruction");

  const markerLayer = L.layerGroup().addTo(map);
  let routeLine = null;
  let points = [];
  let markers = [];
  let selectedPointId = null;
  let satelliteVisible = false;

  function setStatus(message, tone = "") {
    statusEl.textContent = message;
    statusEl.className = `status${tone ? ` ${tone}` : ""}`;
  }

  function updateBaseLayer() {
    if (satelliteVisible) {
      if (map.hasLayer(streetLayer)) {
        map.removeLayer(streetLayer);
      }
      if (!map.hasLayer(satelliteLayer)) {
        satelliteLayer.addTo(map);
      }
      satelliteToggleBtn.textContent = "Show Streets";
    } else {
      if (map.hasLayer(satelliteLayer)) {
        map.removeLayer(satelliteLayer);
      }
      if (!map.hasLayer(streetLayer)) {
        streetLayer.addTo(map);
      }
      satelliteToggleBtn.textContent = "Show Satellite";
    }
  }

  function getLastSelectedPathSlug() {
    try {
      return window.localStorage.getItem(LAST_SELECTED_PATH_STORAGE_KEY) || "";
    } catch {
      return "";
    }
  }

  function setLastSelectedPathSlug(slug) {
    try {
      if (slug) {
        window.localStorage.setItem(LAST_SELECTED_PATH_STORAGE_KEY, slug);
      } else {
        window.localStorage.removeItem(LAST_SELECTED_PATH_STORAGE_KEY);
      }
    } catch {}
  }

  function uid() {
    return `point-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  }

  function round6(value) {
    return Number.parseFloat(value).toFixed(6);
  }

  function toRadians(value) {
    return value * Math.PI / 180;
  }

  function normalizeAngle(angle) {
    let normalized = angle;
    while (normalized > 180) normalized -= 360;
    while (normalized < -180) normalized += 360;
    return normalized;
  }

  function bearing(from, to) {
    const lat1 = toRadians(from.lat);
    const lat2 = toRadians(to.lat);
    const dLng = toRadians(to.lng - from.lng);
    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    const degrees = Math.atan2(y, x) * 180 / Math.PI;
    return (degrees + 360) % 360;
  }

  function suggestionForIndex(index) {
    if (!points[index]) return "";
    const selectedLanguage = pathLanguageEl?.value || "nl-BE";
    if (index === 0) {
      return selectedLanguage === "en-GB"
        ? (points.length > 1 ? "Start here and continue to the next point." : "Start here.")
        : (points.length > 1 ? "Start hier en wandel naar het volgende punt." : "Start hier.");
    }
    if (index === points.length - 1) {
      return selectedLanguage === "en-GB"
        ? "You have reached the destination."
        : "Je hebt je bestemming bereikt.";
    }
    const previous = points[index - 1];
    const current = points[index];
    const next = points[index + 1];
    const incoming = bearing(previous, current);
    const outgoing = bearing(current, next);
    const delta = normalizeAngle(outgoing - incoming);

    if (selectedLanguage === "en-GB") {
      if (Math.abs(delta) < 20) return "Continue straight ahead.";
      if (delta >= 20 && delta < 55) return "Take a slight right.";
      if (delta >= 55 && delta < 125) return "Turn right at the next junction.";
      if (delta >= 125) return "Make a sharp right turn.";
      if (delta <= -20 && delta > -55) return "Take a slight left.";
      if (delta <= -55 && delta > -125) return "Turn left at the next junction.";
      return "Make a sharp left turn.";
    }

    if (Math.abs(delta) < 20) return "Ga rechtdoor.";
    if (delta >= 20 && delta < 55) return "Ga licht naar rechts.";
    if (delta >= 55 && delta < 125) return "Sla rechtsaf aan het volgende kruispunt.";
    if (delta >= 125) return "Maak een scherpe bocht naar rechts.";
    if (delta <= -20 && delta > -55) return "Ga licht naar links.";
    if (delta <= -55 && delta > -125) return "Sla linksaf aan het volgende kruispunt.";
    return "Maak een scherpe bocht naar links.";
  }

  function getDistanceMeters(lat1, lon1, lat2, lon2) {
    const earthRadius = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos((lat1 * Math.PI) / 180) *
        Math.cos((lat2 * Math.PI) / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return earthRadius * c;
  }

  function getRouteLengthMeters() {
    if (points.length < 2) return 0;
    let total = 0;
    for (let index = 1; index < points.length; index += 1) {
      total += getDistanceMeters(
        points[index - 1].lat,
        points[index - 1].lng,
        points[index].lat,
        points[index].lng
      );
    }
    return total;
  }

  function refreshSuggestedInstructions() {
    points = points.map((point, index) => {
      const autoInstruction = suggestionForIndex(index);
      const shouldRefresh = !point.instruction || point.instruction === point.autoInstruction;
      return {
        ...point,
        autoInstruction,
        instruction: shouldRefresh ? autoInstruction : point.instruction
      };
    });
  }

  function selectedPoint() {
    return points.find((point) => point.id === selectedPointId) || null;
  }

  function selectPoint(pointId) {
    selectedPointId = pointId;
    const point = selectedPoint();
    selectedLabelEl.textContent = point ? String(points.findIndex((item) => item.id === pointId) + 1) : "None";
    pointLatEl.value = point ? round6(point.lat) : "";
    pointLngEl.value = point ? round6(point.lng) : "";
    pointInstructionEl.value = point ? (point.instruction || "") : "";
    render();
  }

  function markerIcon(index, isActive) {
    return L.divIcon({
      className: "",
      html: `<div class="markerBubble${isActive ? " active" : ""}">${index + 1}</div>`,
      iconSize: [30, 30],
      iconAnchor: [15, 15]
    });
  }

  function renderRouteLine() {
    if (routeLine) {
      map.removeLayer(routeLine);
      routeLine = null;
    }
    if (points.length >= 2) {
      routeLine = L.polyline(points.map((point) => [point.lat, point.lng]), {
        color: "#22d3ee",
        weight: 4,
        opacity: 0.9
      }).addTo(map);
    }
  }

  function renderPointList() {
    if (!points.length) {
      pointListEl.innerHTML = `<div class="pointItem"><div class="pointInstruction">No points yet.</div><div class="pointCoords">Click on the map to start building the path.</div></div>`;
      return;
    }

    pointListEl.innerHTML = points.map((point, index) => `
      <div class="pointItem${point.id === selectedPointId ? " active" : ""}" data-point-id="${point.id}">
        <div class="pointItemHeader">
          <span class="pointIndex">${index + 1}</span>
          <span class="pointCoords">${round6(point.lat)}, ${round6(point.lng)}</span>
        </div>
        <div class="pointInstruction">${escapeHtml(point.instruction || point.autoInstruction || "")}</div>
      </div>
    `).join("");

    pointListEl.querySelectorAll("[data-point-id]").forEach((node) => {
      node.addEventListener("click", () => {
        const pointId = node.getAttribute("data-point-id");
        if (pointId) {
          selectPoint(pointId);
        }
      });
    });
  }

  function renderMarkers() {
    markerLayer.clearLayers();
    markers = [];
    points.forEach((point, index) => {
      const marker = L.marker([point.lat, point.lng], {
        draggable: true,
        icon: markerIcon(index, point.id === selectedPointId)
      });
      marker.on("click", () => selectPoint(point.id));
      marker.on("dragend", (event) => {
        const latLng = event.target.getLatLng();
        const pointIndex = points.findIndex((item) => item.id === point.id);
        if (pointIndex === -1) return;
        points[pointIndex].lat = latLng.lat;
        points[pointIndex].lng = latLng.lng;
        refreshSuggestedInstructions();
        selectPoint(point.id);
        setStatus(`Moved point ${pointIndex + 1}.`, "ok");
      });
      marker.addTo(markerLayer);
      markers.push(marker);
    });
  }

  function exportDocument() {
    const headingFeedbackFps = Number.parseFloat(headingFeedbackFpsEl.value);
    const modelConfidence = Number.parseFloat(pathModelConfidenceEl.value);
    return {
      name: pathNameEl.value.trim(),
      slug: slugify(pathNameEl.value.trim()),
      language: pathLanguageEl.value || "nl-BE",
      model: pathModelEl.value || "unrealsim",
      modelConfidence: Number.isFinite(modelConfidence) ? Number(modelConfidence.toFixed(2)) : 0.5,
      returnMasks: pathReturnMasksEl.value === "true",
      sendMQTT: pathSendMQTTEl.value === "true",
      headingFeedbackFps: Number.isFinite(headingFeedbackFps) ? Number(headingFeedbackFps.toFixed(1)) : 1.0,
      routeLengthMeters: Number(getRouteLengthMeters().toFixed(1)),
      pointCount: points.length,
      updatedAt: new Date().toISOString(),
      points: points.map((point) => ({
        id: point.id,
        lat: Number(round6(point.lat)),
        lng: Number(round6(point.lng)),
        lon: Number(round6(point.lng)),
        instruction: point.instruction || point.autoInstruction || ""
      }))
    };
  }

  function render() {
    pointCountEl.textContent = String(points.length);
    renderMarkers();
    renderRouteLine();
    renderPointList();
    exportBoxEl.value = JSON.stringify(exportDocument(), null, 2);
  }

  function addPoint(lat, lng) {
    points.push({
      id: uid(),
      lat,
      lng,
      instruction: "",
      autoInstruction: ""
    });
    refreshSuggestedInstructions();
    const newPoint = points[points.length - 1];
    selectPoint(newPoint.id);
    if (points.length === 1) {
      map.setView([lat, lng], 18);
    }
    setStatus(`Added point ${points.length}.`, "ok");
  }

  function updateSelectedPoint() {
    const point = selectedPoint();
    if (!point) {
      setStatus("Select a point first.", "warn");
      return;
    }

    const lat = Number.parseFloat(pointLatEl.value);
    const lng = Number.parseFloat(pointLngEl.value);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      setStatus("Latitude and longitude must be numeric.", "warn");
      return;
    }

    point.lat = lat;
    point.lng = lng;
    point.instruction = pointInstructionEl.value.trim();
    refreshSuggestedInstructions();
    selectPoint(point.id);
    setStatus("Point updated.", "ok");
  }

  function deleteSelectedPoint() {
    const point = selectedPoint();
    if (!point) {
      setStatus("Select a point first.", "warn");
      return;
    }
    const currentIndex = points.findIndex((item) => item.id === point.id);
    points = points.filter((item) => item.id !== point.id);
    refreshSuggestedInstructions();
    const nextPoint = points[Math.min(currentIndex, points.length - 1)] || null;
    selectPoint(nextPoint ? nextPoint.id : null);
    setStatus("Point deleted.", "ok");
  }

  function undoLastPoint() {
    if (!points.length) {
      setStatus("There is no point to undo.", "warn");
      return;
    }
    const removed = points.pop();
    refreshSuggestedInstructions();
    selectPoint(points.length ? points[points.length - 1].id : null);
    setStatus(`Removed ${removed ? removed.id : "last point"}.`, "ok");
  }

  function clearPath() {
    points = [];
    selectedPointId = null;
    pathNameEl.value = "";
    pathLanguageEl.value = "nl-BE";
    headingFeedbackFpsEl.value = "1.0";
    pathModelEl.value = "unrealsim";
    pathModelConfidenceEl.value = "0.5";
    pathReturnMasksEl.value = "false";
    pathSendMQTTEl.value = "false";
    savedPathsEl.value = "";
    selectPoint(null);
    render();
    setStatus("Path cleared.", "ok");
  }

  function escapeHtml(value) {
    return value
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function slugify(value) {
    return (value || "")
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "") || `path-${Date.now()}`;
  }

  async function savePath() {
    const name = pathNameEl.value.trim();
    if (!name) {
      setStatus("Enter a path name before saving.", "warn");
      pathNameEl.focus();
      return;
    }
    if (points.length < 2) {
      setStatus("Add at least two points before saving.", "warn");
      return;
    }

    const response = await fetch(`${API_URL}?action=save_path`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(exportDocument())
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload?.ok) {
      setStatus(payload?.error || "Failed to save path.", "warn");
      return;
    }

    setStatus(`Saved ${payload.filename}.`, "ok");
    await loadSavedPaths(payload.path.slug);
  }

  async function deleteTrail() {
    const slug = savedPathsEl.value || slugify(pathNameEl.value.trim());
    if (!slug || !savedPathsEl.value) {
      setStatus("Select a saved trail first.", "warn");
      return;
    }

    const confirmed = window.confirm(`Delete trail "${pathNameEl.value.trim() || slug}"? This cannot be undone.`);
    if (!confirmed) {
      return;
    }

    const response = await fetch(`${API_URL}?action=delete_path`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ slug })
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {}

    if (!response.ok || !payload?.ok) {
      setStatus(payload?.error || "Failed to delete trail.", "warn");
      return;
    }

    if (getLastSelectedPathSlug() === slug) {
      setLastSelectedPathSlug("");
    }
    clearPath();
    setStatus(`Deleted ${slug}.`, "ok");
    await loadSavedPaths();
  }

  async function loadSavedPaths(selectSlug = "") {
    const response = await fetch(`${API_URL}?action=list_paths`);
    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to load saved paths.", "warn");
      return;
    }

    savedPathsEl.innerHTML = `<option value="">Choose a saved path...</option>` + payload.paths.map((item) => `
      <option value="${escapeHtml(item.slug)}">${escapeHtml(item.name)} (${item.pointCount})</option>
    `).join("");

    const preferredSlug = selectSlug || (launchNewRoute ? "" : getLastSelectedPathSlug());
    if (preferredSlug && payload.paths.some((item) => item.slug === preferredSlug)) {
      savedPathsEl.value = preferredSlug;
      await openSavedPath(preferredSlug);
    }
  }

  async function openSavedPath(slug) {
    if (!slug) return;
    const response = await fetch(`${API_URL}?action=load_path&slug=${encodeURIComponent(slug)}`);
    const payload = await response.json();
    if (!payload?.ok || !payload.path) {
      setStatus(payload?.error || "Failed to load the selected path.", "warn");
      return;
    }
    setLastSelectedPathSlug(slug);

    const loadedPoints = Array.isArray(payload.path.points) ? payload.path.points : [];
    points = loadedPoints.map((point, index) => ({
      id: point.id || `point-${index + 1}`,
      lat: Number(point.lat),
      lng: Number(point.lng ?? point.lon),
      instruction: point.instruction || "",
      autoInstruction: ""
    })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

    pathNameEl.value = payload.path.name || slug;
    pathLanguageEl.value = payload.path.language === "en-GB" ? "en-GB" : "nl-BE";
    const loadedModel = String(payload.path.model || "").trim().toLowerCase();
    pathModelEl.value = ["unrealsim", "laerbeekbos", "kaai", "denham"].includes(loadedModel)
      ? loadedModel
      : (loadedModel === "1"
        ? "unrealsim"
        : loadedModel === "2"
          ? "laerbeekbos"
          : loadedModel === "3"
          ? "kaai"
            : "unrealsim");
    const loadedModelConfidence = Number.parseFloat(payload.path.modelConfidence);
    pathModelConfidenceEl.value = Number.isFinite(loadedModelConfidence)
      ? Math.min(1, Math.max(0, loadedModelConfidence)).toFixed(2)
      : "0.5";
    pathReturnMasksEl.value = payload.path.returnMasks === true ? "true" : "false";
    pathSendMQTTEl.value = payload.path.sendMQTT === true ? "true" : "false";
    const loadedHeadingFeedbackFps = Number.parseFloat(payload.path.headingFeedbackFps);
    headingFeedbackFpsEl.value = Number.isFinite(loadedHeadingFeedbackFps)
      ? loadedHeadingFeedbackFps.toFixed(1)
      : "1.0";
    refreshSuggestedInstructions();
    selectPoint(points[0] ? points[0].id : null);
    render();
    if (points.length) {
      map.fitBounds(points.map((point) => [point.lat, point.lng]), { padding: [40, 40] });
    }
    setStatus(`Loaded ${payload.path.name || slug}.`, "ok");
  }

  map.on("click", (event) => {
    addPoint(event.latlng.lat, event.latlng.lng);
  });

  document.getElementById("applyBtn").addEventListener("click", updateSelectedPoint);
  document.getElementById("deleteBtn").addEventListener("click", deleteSelectedPoint);
  document.getElementById("undoBtn").addEventListener("click", undoLastPoint);
  document.getElementById("clearBtn").addEventListener("click", clearPath);
  document.getElementById("saveBtn").addEventListener("click", () => {
    savePath().catch(() => setStatus("Failed to save path.", "warn"));
  });
  pointInstructionEl.addEventListener("input", () => {
    const point = selectedPoint();
    if (!point) return;
    point.instruction = pointInstructionEl.value;
    render();
  });
  pathLanguageEl.addEventListener("change", () => {
    refreshSuggestedInstructions();
    render();
  });
  headingFeedbackFpsEl.addEventListener("input", () => {
    render();
  });
  pathModelEl.addEventListener("change", () => {
    render();
  });
  pathModelConfidenceEl.addEventListener("input", () => {
    render();
  });
  pathReturnMasksEl.addEventListener("change", () => {
    render();
  });
  pathSendMQTTEl.addEventListener("change", () => {
    render();
  });
  savedPathsEl.addEventListener("change", () => {
    openSavedPath(savedPathsEl.value).catch(() => setStatus("Failed to load the selected path.", "warn"));
  });
  satelliteToggleBtn.addEventListener("click", () => {
    satelliteVisible = !satelliteVisible;
    updateBaseLayer();
  });
  deleteTrailBtn.addEventListener("click", () => {
    deleteTrail().catch(() => setStatus("Failed to delete trail.", "warn"));
  });

  render();
  updateBaseLayer();
  if (launchNewRoute) {
    setLastSelectedPathSlug("");
    clearPath();
  }
  loadSavedPaths().catch(() => setStatus("Unable to list saved paths.", "warn"));
</script>
</body>
</html>
