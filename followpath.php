<?php
$bearerToken = 'LTddk_ptxQX-omdw5B5rfpniA2wB-19KBxFaKuODMzw';
$wsUrl = 'wss://signaling.ehb.be';
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'saved_paths';
$room = '/ws/pathnavigation';

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function slugifyRouteName(string $value): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'path';
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
        $decoded = json_decode(file_get_contents($filePath) ?: '', true);
        $items[] = [
            'slug' => $slug,
            'filename' => $filename,
            'name' => is_array($decoded) && isset($decoded['name']) ? (string)$decoded['name'] : $slug,
            'pointCount' => is_array($decoded['points'] ?? null) ? count($decoded['points']) : 0,
            'updatedAt' => date('c', filemtime($filePath) ?: time()),
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
    if (!is_array($decoded)) {
        return null;
    }

    $decoded['slug'] = $decoded['slug'] ?? $safeSlug;
    return $decoded;
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
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails - Follow Path</title>
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
    a:focus-visible,button:focus-visible,select:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1420px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .layout{max-width:1420px;margin:0 auto;padding:18px;display:grid;grid-template-columns:minmax(0,1.7fr) minmax(320px,.9fr);gap:18px}
    .panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.26)}
    .mapPanel{padding:14px}
    .sidePanel{padding:16px;display:flex;flex-direction:column;gap:14px}
    .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
    .field{display:flex;flex-direction:column;gap:6px}
    .field label,.sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .field select{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--line);background:#020617;color:#fff;font-size:14px}
    .btn{padding:11px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:#334155}
    .btnPrimary{background:var(--accent);color:var(--accent-ink);border-color:rgba(250,204,21,.35)}
    .btnPrimary:hover{background:#fde047}
    .btnSmall{padding:8px 10px;font-size:12px}
    .mapWrap{position:relative}
    #map{height:74vh;min-height:520px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line)}
    .help{margin:10px 0 0;color:var(--muted);font-size:14px;line-height:1.5}
    .status{padding:10px 12px;border-radius:12px;border:none var(--line);font-size:14px;color:var(--muted)}
    .status.ok{color:#d1fae5;border-color:rgba(52,211,153,.35);background:rgba(16,185,129,.08)}
    .status.warn{color:#ffe4e6;border-color:rgba(251,113,133,.35);background:rgba(244,63,94,.08)}
    .card{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .bigInstruction{font-size:28px;line-height:1.2;font-weight:700}
    .muted{color:var(--muted);font-size:14px;line-height:1.5}
    .stats{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .stat{padding:12px;border-radius:14px;background:#0b1220;border:1px solid var(--line)}
    .statLabel{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px}
    .statValue{font-size:20px;font-weight:700}
    .trailDirectionValue{font-size:24px;font-weight:700}
    .mapHeadingOverlay{position:absolute;left:16px;bottom:16px;z-index:500;display:flex;flex-direction:column;align-items:center;gap:8px;padding:12px 14px;border-radius:18px;background:rgba(2,6,23,.22);box-shadow:0 18px 40px rgba(0,0,0,.28);pointer-events:none}
    .mapHeadingValue{font-size:22px;font-weight:700}
    .mapCameraOverlay{position:absolute;top:16px;right:16px;z-index:500;overflow:hidden;width:min(220px,32vw);aspect-ratio:4/3;border-radius:16px;border:1px solid var(--line);background:#020617;box-shadow:0 18px 40px rgba(0,0,0,.28);pointer-events:none}
    .trailVideoBg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0;background:#000;opacity:0}
    .trailPreviewBg{position:absolute;inset:0;width:100%;height:100%;z-index:0;background:#000}
    .trailMaskOverlay{position:absolute;inset:0;width:100%;height:100%;z-index:1;pointer-events:none}
    .hidden{display:none !important}
    .compassWrap{display:flex;flex-direction:column;align-items:center;gap:6px}
    #mapCompass{width:110px;height:110px;display:block}
    .list{display:flex;flex-direction:column;gap:10px;max-height:34vh;overflow:auto;padding-right:4px}
    .pointItem{padding:12px;border-radius:14px;border:1px solid var(--line);background:#0b1220}
    .pointItem.active{border-color:rgba(34,211,238,.55);background:#0f1a2c}
    .pointTitle{font-size:13px;font-weight:700;margin-bottom:6px}
    .pointText,.pointMeta{font-size:13px;line-height:1.45}
    .pointMeta{color:var(--muted)}
    .leaflet-div-icon{background:transparent;border:0}
    .personMarkerInner{position:relative;width:22px;height:30px}
    .personMarkerInner::before{content:"";position:absolute;left:6px;top:0;width:10px;height:10px;border-radius:50%;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.92)}
    .personMarkerInner::after{content:"";position:absolute;left:4px;top:11px;width:14px;height:16px;border-radius:8px 8px 6px 6px;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.92)}
    .waypointBubble{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#1d4ed8;color:#fff;font-weight:700;border:2px solid rgba(15,23,42,.92)}
    .waypointBubble.active{background:#22d3ee;color:#082f49}
    .waypointBubble.done{background:#16a34a;color:#ecfdf5}
    @media (max-width: 1100px){
      .layout{grid-template-columns:1fr}
      #map{height:58vh;min-height:380px}
      .mapHeadingOverlay{left:12px;bottom:12px}
      .mapCameraOverlay{top:12px;right:12px;width:min(200px,34vw)}
    }
    @media (max-width: 720px){
      .layout{padding:12px}
      .stats{grid-template-columns:1fr}
      .mapHeadingOverlay{left:12px;right:12px;bottom:12px}
      .mapCameraOverlay{top:12px;right:12px;width:min(150px,38vw)}
      .mapHeadingValue{font-size:20px}
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
        <li><a href="remoteHelp.php">Remote assistant</a></li>
        <li><a class="cta" href="#" aria-current="page">Start route</a></li>
      </ul>
    </nav>
  </div>
</header>

<main class="layout">
  <section class="panel mapPanel">
    <div class="toolbar">
      <button id="startBtn" class="btn btnPrimary" type="button">Start Walking</button>
      <button id="repeatBtn" class="btn" type="button">Repeat Last Instruction</button>
      <button id="headingSpeechToggleBtn" class="btn" type="button">Mute Heading Speech</button>
      <button id="satelliteToggleBtn" class="btn" type="button">Show Satellite</button>
      <button id="stopBtn" class="btn" type="button">Stop</button>
    </div>
    <div class="mapWrap">
      <div id="map" aria-label="Walking map"></div>
      <div id="mapHeadingOverlay" class="mapHeadingOverlay">
        <div class="compassWrap">
          <canvas id="mapCompass" width="110" height="110"></canvas>
        </div>
        <div id="mapTrailDirectionValue" class="mapHeadingValue">--</div>
        <div id="mapTrailDirectionMeta" class="muted">Segmentation guidance inactive.</div>
      </div>
      <div id="mapCameraOverlay" class="mapCameraOverlay">
        <video id="trailVideo" class="trailVideoBg" autoplay playsinline muted></video>
        <canvas id="trailPreview" class="trailPreviewBg"></canvas>
        <canvas id="trailMaskOverlay" class="trailMaskOverlay"></canvas>
      </div>
    </div>
  </section>
  <aside class="panel sidePanel">
    <div class="card">
      <div id="currentInstruction" class="bigInstruction" style="margin-top:10px">No path loaded.</div>
      <div id="currentInstructionMeta" class="muted" style="margin-top:10px">Load a route and start tracking to receive guidance.</div>
    </div>
    <div class="card">
      <div class="sectionTitle">Remote Assistant</div>
      <div id="helperMessageValue" class="bigInstruction" style="margin-top:10px;font-size:22px">No helper message.</div>
      <div id="helperMessageMeta" class="muted" style="margin-top:10px">Remote assistant messages will appear here.</div>
    </div>
    <div class="card">
      <div class="sectionTitle">Session</div>
      <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
        <div id="sessionIdValue" class="bigInstruction" style="margin:0;font-size:20px;flex:1 1 auto">--</div>
        <button id="copySessionBtn" class="btn btnSmall" type="button">Copy</button>
      </div>
      <div id="sessionIdMeta" class="muted" style="margin-top:10px">Start walking to create a live session id.</div>
    </div>
    <div id="status" class="status">Choose a saved path to begin.</div>

    <div class="card">
      <div class="sectionTitle">GPS Status</div>
      <div class="stats" style="margin-top:12px">
        <div class="stat">
          <div class="statLabel">Latitude</div>
          <div id="latValue" class="statValue">--</div>
        </div>
        <div class="stat">
          <div class="statLabel">Longitude</div>
          <div id="lonValue" class="statValue">--</div>
        </div>
        <div class="stat">
          <div class="statLabel">Accuracy</div>
          <div id="accuracyValue" class="statValue">--</div>
        </div>
        <div class="stat">
          <div class="statLabel">Distance To Next</div>
          <div id="distanceValue" class="statValue">--</div>
        </div>
      </div>
      <div id="gpsStatus" class="muted" style="margin-top:12px">GPS not started.</div>
    </div>
    <div class="card">
      <div class="sectionTitle">Streaming</div>
      <div class="stats" style="margin-top:12px">
        <div class="stat">
          <div class="statLabel">Frames Sent</div>
          <div id="sentFramesValue" class="statValue">0</div>
        </div>
        <div class="stat">
          <div class="statLabel">Send Rate</div>
          <div id="sendRateValue" class="statValue">0.0 fps</div>
        </div>
      </div>
      <div id="streamMeta" class="muted" style="margin-top:12px">Configured interval: 1.0 s per frame.</div>
    </div>

    <div class="card">
      <div class="field">
        <label for="savedPaths">Saved Path</label>
        <select id="savedPaths">
          <option value="">Choose a saved path...</option>
        </select>
      </div>
    </div>

  </aside>
</main>

<canvas id="trailCap" style="display:none"></canvas>

<script>
  const API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const SIGNALING_SERVER = <?php echo json_encode($wsUrl, JSON_UNESCAPED_SLASHES); ?>;
  const BEARER_TOKEN = <?php echo json_encode($bearerToken); ?>;
  const SIGNALING_ROOM = <?php echo json_encode($room, JSON_UNESCAPED_SLASHES); ?>;
  const DEFAULT_CENTER = [50.8503, 4.3517];
  const LAST_SELECTED_PATH_STORAGE_KEY = "stayontrails.lastSelectedPathSlug";
  const ARRIVAL_RADIUS_METERS = 15;
  const OFF_ROUTE_WARNING_METERS = 100;
  const TARGET_W = 640;
  const TARGET_H = 480;
  const JPEG_QUALITY = 0.70;
  const DEFAULT_LANGUAGE = "nl-BE";
  const DEFAULT_HEADING_FEEDBACK_FPS = 1.0;
  const DEFAULT_MODEL = "unrealsim";
  const DEFAULT_MODEL_CONFIDENCE = 0.5;

  const topbarEl = document.querySelector(".topbar");
  const savedPathsEl = document.getElementById("savedPaths");
  const startBtn = document.getElementById("startBtn");
  const repeatBtn = document.getElementById("repeatBtn");
  const headingSpeechToggleBtn = document.getElementById("headingSpeechToggleBtn");
  const satelliteToggleBtn = document.getElementById("satelliteToggleBtn");
  const stopBtn = document.getElementById("stopBtn");
  const statusEl = document.getElementById("status");
  const gpsStatusEl = document.getElementById("gpsStatus");
  const latValueEl = document.getElementById("latValue");
  const lonValueEl = document.getElementById("lonValue");
  const accuracyValueEl = document.getElementById("accuracyValue");
  const distanceValueEl = document.getElementById("distanceValue");
  const currentInstructionEl = document.getElementById("currentInstruction");
  const currentInstructionMetaEl = document.getElementById("currentInstructionMeta");
  const helperMessageValueEl = document.getElementById("helperMessageValue");
  const helperMessageMetaEl = document.getElementById("helperMessageMeta");
  const sessionIdValueEl = document.getElementById("sessionIdValue");
  const sessionIdMetaEl = document.getElementById("sessionIdMeta");
  const copySessionBtn = document.getElementById("copySessionBtn");
  const mapHeadingOverlayEl = document.getElementById("mapHeadingOverlay");
  const mapCameraOverlayEl = document.getElementById("mapCameraOverlay");
  const mapTrailDirectionValueEl = document.getElementById("mapTrailDirectionValue");
  const mapTrailDirectionMetaEl = document.getElementById("mapTrailDirectionMeta");
  const sentFramesValueEl = document.getElementById("sentFramesValue");
  const sendRateValueEl = document.getElementById("sendRateValue");
  const streamMetaEl = document.getElementById("streamMeta");
  const trailVideoEl = document.getElementById("trailVideo");
  const trailPreviewEl = document.getElementById("trailPreview");
  const trailPreviewCtx = trailPreviewEl.getContext("2d", { alpha: false });
  const trailMaskOverlayEl = document.getElementById("trailMaskOverlay");
  const trailMaskOverlayCtx = trailMaskOverlayEl.getContext("2d");
  const trailCapEl = document.getElementById("trailCap");
  const trailCapCtx = trailCapEl.getContext("2d", { alpha: false });
  const mapCompass = document.getElementById("mapCompass");
  const mapCompCtx = mapCompass.getContext("2d");

  const map = L.map("map").setView(DEFAULT_CENTER, 16);
  const streetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(map);
  const satelliteLayer = L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}", {
    attribution: "Tiles &copy; Esri"
  });

  const personIcon = L.divIcon({
    className: "",
    html: '<div class="personMarkerInner" aria-hidden="true"></div>',
    iconSize: [22, 30],
    iconAnchor: [11, 26]
  });

  let currentLocationMarker = L.marker(DEFAULT_CENTER, {
    icon: personIcon,
    title: "Current location"
  }).addTo(map);
  let pathLine = null;
  let waypointMarkers = [];
  let geoWatchId = null;
  let latestLatitude = null;
  let latestLongitude = null;
  let latestAccuracy = null;
  let currentPath = null;
  let currentLanguage = DEFAULT_LANGUAGE;
  let currentModel = DEFAULT_MODEL;
  let currentModelConfidence = DEFAULT_MODEL_CONFIDENCE;
  let currentReturnMasks = false;
  let currentSendMQTT = false;
  let headingFeedbackFps = DEFAULT_HEADING_FEEDBACK_FPS;
  let sendIntervalMs = Math.max(100, Math.round(1000 / DEFAULT_HEADING_FEEDBACK_FPS));
  let routePoints = [];
  let activePointIndex = 0;
  let lastSpokenPointId = null;
  let walkingActive = false;
  let ws = null;
  let stream = null;
  let activeVideoDeviceId = null;
  let timer = null;
  let nextFrameId = 1;
  let currentSessionId = null;
  let isAuthenticated = false;
  let authStarted = false;
  let latestHeading = null;
  let lastSpokenDirectionKey = null;
  let activeSpeechKind = null;
  let lastInstructionText = "";
  let offRouteWarningActive = false;
  let lastHelperMessageText = "";
  let headingSpeechEnabled = true;
  let sentFrames = 0;
  let framesSince = 0;
  let lastRateT = performance.now();
  const sentAtByFrameId = new Map();
  let lastLatency = null;
  let latestResultMasks = [];
  let trailPreviewRafId = null;
  let satelliteVisible = false;

  function setStatus(message, tone = "") {
    statusEl.textContent = message;
    statusEl.className = `status${tone ? ` ${tone}` : ""}`;
  }

  function setWalkingChromeVisibility(isWalking) {
    startBtn.classList.toggle("hidden", isWalking);
    if (topbarEl) {
      topbarEl.classList.toggle("hidden", isWalking);
    }
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

  function getRequestedPathSlug() {
    try {
      const params = new URLSearchParams(window.location.search);
      return (params.get("slug") || "").trim();
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

  function normalizeHeading(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return null;
    return ((n % 360) + 360) % 360;
  }

  function normalizeModelName(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (normalized === "1") return "unrealsim";
    if (normalized === "2") return "laerbeekbos";
    if (normalized === "3") return "kaai";
    if (["unrealsim", "laerbeekbos", "kaai", "denham"].includes(normalized)) {
      return normalized;
    }
    return DEFAULT_MODEL;
  }

  function createSessionId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return `sess-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function speak(text, kind = "general") {
    const content = String(text || "").trim();
    if (!content || !("speechSynthesis" in window)) return;

    if (kind === "direction" && activeSpeechKind && activeSpeechKind !== "direction") {
      return;
    }
    if (kind === "instruction") {
      lastInstructionText = content;
    }

    const utterance = new SpeechSynthesisUtterance(content);
    const selectedLanguage = currentLanguage || DEFAULT_LANGUAGE;
    utterance.lang = selectedLanguage;
    utterance.rate = 1.0;

    const voices = window.speechSynthesis.getVoices();
    const matchingVoice = voices.find((voice) => voice.lang === selectedLanguage)
      || (selectedLanguage === "nl-BE"
        ? voices.find((voice) => voice.lang === "nl-BE" || voice.lang.startsWith("nl"))
        : voices.find((voice) => voice.lang === "en-GB" || voice.lang.startsWith("en")));

    if (matchingVoice) {
      utterance.voice = matchingVoice;
    }

    utterance.onstart = () => {
      activeSpeechKind = kind;
    };
    utterance.onend = () => {
      if (activeSpeechKind === kind) {
        activeSpeechKind = null;
      }
    };
    utterance.onerror = () => {
      if (activeSpeechKind === kind) {
        activeSpeechKind = null;
      }
    };

    if (kind !== "direction") {
      window.speechSynthesis.cancel();
    }
    window.speechSynthesis.speak(utterance);
  }

  if ("speechSynthesis" in window) {
    window.speechSynthesis.onvoiceschanged = () => {};
  }

  function drawArrowOnCanvas(canvasEl, canvasCtx, headingDeg) {
    const w = canvasEl.width;
    const h = canvasEl.height;
    const cx = w / 2;
    const cy = h / 2;

    canvasCtx.clearRect(0, 0, w, h);
    canvasCtx.beginPath();
    canvasCtx.arc(cx, cy, Math.max(24, Math.min(w, h) / 2 - 8), 0, Math.PI * 2);
    canvasCtx.strokeStyle = "rgba(255,255,255,0.35)";
    canvasCtx.lineWidth = 2;
    canvasCtx.stroke();

    canvasCtx.fillStyle = "rgba(255,255,255,0.7)";
    canvasCtx.font = "12px system-ui";
    canvasCtx.textAlign = "center";
    canvasCtx.fillText("Forward", cx, 14);

    if (typeof headingDeg !== "number" || Number.isNaN(headingDeg)) return;

    const angleRad = (-headingDeg * Math.PI) / 180;
    const arrowBody = Math.max(24, Math.min(w, h) * 0.28);
    const arrowTip = Math.max(36, Math.min(w, h) * 0.4);
    canvasCtx.save();
    canvasCtx.translate(cx, cy);
    canvasCtx.rotate(angleRad);
    canvasCtx.beginPath();
    canvasCtx.moveTo(-8, -3);
    canvasCtx.lineTo(arrowBody, -3);
    canvasCtx.lineTo(arrowBody, -10);
    canvasCtx.lineTo(arrowTip, 0);
    canvasCtx.lineTo(arrowBody, 10);
    canvasCtx.lineTo(arrowBody, 3);
    canvasCtx.lineTo(-8, 3);
    canvasCtx.closePath();
    canvasCtx.fillStyle = "#ff3b30";
    canvasCtx.fill();
    canvasCtx.restore();
  }

  function drawArrow(headingDeg) {
    drawArrowOnCanvas(mapCompass, mapCompCtx, headingDeg);
  }

  function syncTrailDirectionMode() {
    mapHeadingOverlayEl.classList.remove("hidden");
    mapCameraOverlayEl.classList.remove("hidden");
  }

  function resizeTrailMaskOverlay() {
    const width = Math.max(1, Math.round(trailMaskOverlayEl.clientWidth));
    const height = Math.max(1, Math.round(trailMaskOverlayEl.clientHeight));
    if (trailMaskOverlayEl.width !== width || trailMaskOverlayEl.height !== height) {
      trailMaskOverlayEl.width = width;
      trailMaskOverlayEl.height = height;
    }
  }

  function clearTrailMaskOverlay() {
    resizeTrailMaskOverlay();
    trailMaskOverlayCtx.clearRect(0, 0, trailMaskOverlayEl.width, trailMaskOverlayEl.height);
  }

  function resizeTrailPreview() {
    const width = Math.max(1, Math.round(trailPreviewEl.clientWidth));
    const height = Math.max(1, Math.round(trailPreviewEl.clientHeight));
    if (trailPreviewEl.width !== width || trailPreviewEl.height !== height) {
      trailPreviewEl.width = width;
      trailPreviewEl.height = height;
    }
  }

  function clearTrailPreview() {
    resizeTrailPreview();
    trailPreviewCtx.fillStyle = "#000";
    trailPreviewCtx.fillRect(0, 0, trailPreviewEl.width, trailPreviewEl.height);
  }

  function drawTrailPreviewFrame() {
    resizeTrailPreview();
    if (trailVideoEl.videoWidth && trailVideoEl.videoHeight) {
      trailPreviewCtx.drawImage(trailVideoEl, 0, 0, trailPreviewEl.width, trailPreviewEl.height);
      return;
    }
    clearTrailPreview();
  }

  function scheduleTrailPreviewRender() {
    if (trailPreviewRafId !== null) {
      cancelAnimationFrame(trailPreviewRafId);
    }

    const tick = () => {
      drawTrailPreviewFrame();
      if (stream) {
        trailPreviewRafId = requestAnimationFrame(tick);
      } else {
        trailPreviewRafId = null;
      }
    };

    trailPreviewRafId = requestAnimationFrame(tick);
  }

  function stopTrailPreviewRender() {
    if (trailPreviewRafId !== null) {
      cancelAnimationFrame(trailPreviewRafId);
      trailPreviewRafId = null;
    }
    clearTrailPreview();
  }

  function isPointPair(value) {
    return Array.isArray(value)
      && value.length >= 2
      && Number.isFinite(Number(value[0]))
      && Number.isFinite(Number(value[1]));
  }

  function collectMaskPolygons(value, polygons = []) {
    if (!Array.isArray(value) || !value.length) {
      return polygons;
    }
    if (value.every((item) => isPointPair(item))) {
      polygons.push(value);
      return polygons;
    }
    value.forEach((item) => collectMaskPolygons(item, polygons));
    return polygons;
  }

  function drawTrailMaskOverlay(maskData) {
    resizeTrailMaskOverlay();
    trailMaskOverlayCtx.clearRect(0, 0, trailMaskOverlayEl.width, trailMaskOverlayEl.height);

    const polygons = collectMaskPolygons(maskData);
    if (!polygons.length) {
      return;
    }

    const canvasWidth = trailMaskOverlayEl.width;
    const canvasHeight = trailMaskOverlayEl.height;
    const scaleX = canvasWidth / TARGET_W;
    const scaleY = canvasHeight / TARGET_H;

    trailMaskOverlayCtx.fillStyle = "rgba(34, 211, 238, 0.28)";
    trailMaskOverlayCtx.strokeStyle = "rgba(34, 211, 238, 0.9)";
    trailMaskOverlayCtx.lineWidth = 2;

    polygons.forEach((polygon) => {
      let started = false;
      trailMaskOverlayCtx.beginPath();
      polygon.forEach((point) => {
        const x = Number(point[0]) * scaleX;
        const y = Number(point[1]) * scaleY;
        if (!started) {
          trailMaskOverlayCtx.moveTo(x, y);
          started = true;
        } else {
          trailMaskOverlayCtx.lineTo(x, y);
        }
      });
      if (started) {
        trailMaskOverlayCtx.closePath();
        trailMaskOverlayCtx.fill();
        trailMaskOverlayCtx.stroke();
      }
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function roundCoord(value) {
    return Number.isFinite(Number(value)) ? Number(value).toFixed(6) : "--";
  }

  function formatHeading(value) {
    return value === null || value === undefined || !Number.isFinite(Number(value))
      ? "--"
      : `${Number(value).toFixed(1)} deg`;
  }

  function formatDistanceForSpeech(distanceMeters) {
    const distance = Number(distanceMeters);
    if (!Number.isFinite(distance)) {
      return "";
    }
    if (distance >= 1000) {
      const kilometers = (distance / 1000).toFixed(1);
      return currentLanguage === "en-GB"
        ? `${kilometers} kilometers`
        : `${kilometers} kilometer`;
    }
    return currentLanguage === "en-GB"
      ? `${Math.round(distance)} meters`
      : `${Math.round(distance)} meter`;
  }

  function toFiniteNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
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

  function toRadians(value) {
    return value * Math.PI / 180;
  }

  function normalizeAngle(angle) {
    let normalized = angle;
    while (normalized > 180) normalized -= 360;
    while (normalized < -180) normalized += 360;
    return normalized;
  }

  function bearing(fromPoint, toPoint) {
    const lat1 = toRadians(fromPoint.lat);
    const lat2 = toRadians(toPoint.lat);
    const dLng = toRadians(toPoint.lng - fromPoint.lng);
    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    const degrees = Math.atan2(y, x) * 180 / Math.PI;
    return (degrees + 360) % 360;
  }

  function segmentDirectionForIndex(index) {
    if (!routePoints[index]) return "";

    const selectedLanguage = currentLanguage || DEFAULT_LANGUAGE;
    if (index === 0) {
      return selectedLanguage === "en-GB"
        ? (routePoints.length > 1 ? "Start here and continue to the next point." : "Start here.")
        : (routePoints.length > 1 ? "Start hier en wandel naar het volgende punt." : "Start hier.");
    }
    if (index === routePoints.length - 1) {
      return selectedLanguage === "en-GB"
        ? "You have reached the destination."
        : "Je hebt je bestemming bereikt.";
    }

    const previous = routePoints[index - 1];
    const current = routePoints[index];
    const next = routePoints[index + 1];
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

  function buildWaypointInstruction(point, index) {
    const manualInstruction = String(point?.instruction || "").trim();
    const segmentDirection = String(segmentDirectionForIndex(index)).trim();
    if (!manualInstruction) {
      return segmentDirection || "No instruction";
    }
    if (!segmentDirection) {
      return manualInstruction;
    }
    return `${segmentDirection} ${manualInstruction}`;
  }

  function distanceToPoint(point) {
    const lat = toFiniteNumber(latestLatitude);
    const lon = toFiniteNumber(latestLongitude);
    const pointLat = toFiniteNumber(point?.lat);
    const pointLon = toFiniteNumber(point?.lng ?? point?.lon);
    if (lat === null || lon === null || pointLat === null || pointLon === null) {
      return null;
    }
    return getDistanceMeters(lat, lon, pointLat, pointLon);
  }

  function distanceBetweenPoints(fromPoint, toPoint) {
    const fromLat = toFiniteNumber(fromPoint?.lat);
    const fromLon = toFiniteNumber(fromPoint?.lng ?? fromPoint?.lon);
    const toLat = toFiniteNumber(toPoint?.lat);
    const toLon = toFiniteNumber(toPoint?.lng ?? toPoint?.lon);
    if (fromLat === null || fromLon === null || toLat === null || toLon === null) {
      return null;
    }
    return getDistanceMeters(fromLat, fromLon, toLat, toLon);
  }

  function buildArrivalSpeech(point, index) {
    const selectedLanguage = currentLanguage || DEFAULT_LANGUAGE;
    const baseText = buildWaypointInstruction(point, index)
      || (selectedLanguage === "en-GB"
        ? `Reached point ${index + 1}.`
        : `Punt ${index + 1} bereikt.`);
    const nextPoint = routePoints[index + 1] || null;
    const nextDistance = nextPoint ? distanceBetweenPoints(point, nextPoint) : null;

    if (nextDistance === null) {
      return baseText;
    }

    const roundedDistance = Math.round(nextDistance);
    const nextDistanceText = selectedLanguage === "en-GB"
      ? `${roundedDistance} meters to the next waypoint.`
      : `${roundedDistance} meter tot het volgende instructiepunt.`;

    return `${baseText} ${nextDistanceText}`;
  }

  function buildPathSelectedSpeech(pathName) {
    const selectedLanguage = currentLanguage || DEFAULT_LANGUAGE;
    const safePathName = String(pathName || "").trim() || "route";
    return selectedLanguage === "en-GB"
      ? `Selected path: ${safePathName}.`
      : `Geselecteerd pad: ${safePathName}.`;
  }

  function renderSessionId() {
    sessionIdValueEl.textContent = currentSessionId || "--";
    copySessionBtn.disabled = !currentSessionId;
    sessionIdMetaEl.textContent = currentSessionId
      ? "Share this session id with the remote assistant."
      : "Start walking to create a live session id.";
  }

  async function copySessionId() {
    if (!currentSessionId) {
      setStatus("No active session id to copy.", "warn");
      return;
    }

    try {
      await navigator.clipboard.writeText(currentSessionId);
      sessionIdMetaEl.textContent = "Session id copied to clipboard.";
      setStatus("Session id copied.", "ok");
    } catch (error) {
      console.warn("Clipboard copy failed:", error);
      sessionIdMetaEl.textContent = "Failed to copy session id.";
      setStatus("Failed to copy session id.", "warn");
    }
  }

  function renderHeadingSpeechToggle() {
    headingSpeechToggleBtn.textContent = headingSpeechEnabled ? "Mute Heading Speech" : "Enable Heading Speech";
  }

  function showHelperMessage(messageText, senderLabel = "") {
    const content = String(messageText || "").trim();
    if (!content) {
      helperMessageValueEl.textContent = "No helper message.";
      helperMessageMetaEl.textContent = "Remote assistant messages will appear here.";
      return;
    }
    lastHelperMessageText = content;
    helperMessageValueEl.textContent = content;
    helperMessageMetaEl.textContent = senderLabel
      ? `Received from ${senderLabel}.`
      : "Received from remote assistant.";
  }

  function getDirectionLabelForHeading(heading) {
    const normalizedHeading = toFiniteNumber(heading);
    if (normalizedHeading === null) {
      return null;
    }

    const selectedLanguage = currentLanguage || DEFAULT_LANGUAGE;
    const labels = selectedLanguage === "en-GB"
      ? { left: "left", right: "right", straight: "straight" }
      : { left: "links", right: "rechts", straight: "rechtdoor" };

    if (normalizedHeading >= 100) {
      return labels.left;
    }
    if (normalizedHeading <= 80) {
      return labels.right;
    }
    return labels.straight;
  }

  function renderTrailDirection() {
    const directionLabel = getDirectionLabelForHeading(latestHeading);
    mapTrailDirectionValueEl.textContent = directionLabel || "--";
    drawArrow(latestHeading);
    drawTrailMaskOverlay(latestResultMasks);
    if (!walkingActive) {
      mapTrailDirectionMetaEl.textContent = "Segmentation guidance inactive.";
      return;
    }
    if (latestHeading === null) {
      mapTrailDirectionMetaEl.textContent = "Waiting for live segmentation heading...";
      return;
    }
    mapTrailDirectionMetaEl.textContent = `Live heading ${formatHeading(latestHeading)}`;
  }

  function updateSendRate() {
    framesSince += 1;
    const now = performance.now();
    const dt = now - lastRateT;
    if (dt >= 1000) {
      const fps = framesSince / (dt / 1000);
      sendRateValueEl.textContent = `${fps.toFixed(1)} fps`;
      framesSince = 0;
      lastRateT = now;
    }
  }

  function updateDirectionSpeech(force = false) {
    const directionLabel = getDirectionLabelForHeading(latestHeading);
    if (!directionLabel) {
      lastSpokenDirectionKey = null;
      renderTrailDirection();
      return;
    }

    const directionKey = `${directionLabel}|${currentLanguage || DEFAULT_LANGUAGE}`;
    renderTrailDirection();
    if (!headingSpeechEnabled) {
      lastSpokenDirectionKey = null;
      return;
    }
    if (!force && directionKey === lastSpokenDirectionKey) {
      return;
    }

    lastSpokenDirectionKey = directionKey;
    speak(directionLabel, "direction");
  }

  function currentPoint() {
    return routePoints[activePointIndex] || null;
  }

  function activateWaypointFromClick(index) {
    if (!walkingActive || index < 0 || index >= routePoints.length) {
      return false;
    }

    activePointIndex = index;
    lastSpokenPointId = null;
    offRouteWarningActive = false;
    renderRoute();
    setStatus(`Continuing from point ${index + 1}.`, "ok");
    speak(buildWaypointInstruction(routePoints[index], index) || `Proceed to point ${index + 1}.`, "instruction");
    return true;
  }

  function renderPointList() {
    if (!routePoints.length) {
      return;
    }
  }

  function updateWaypointMarkers() {
    waypointMarkers.forEach((marker) => map.removeLayer(marker));
    waypointMarkers = [];

    routePoints.forEach((point, index) => {
      const isDone = index < activePointIndex;
      const isActive = index === activePointIndex;
      const marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
          className: "",
          html: `<div class="waypointBubble${isActive ? " active" : isDone ? " done" : ""}">${index + 1}</div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 14]
        }),
        title: point.instruction || `Point ${index + 1}`
      }).addTo(map);
      marker.on("click", () => {
        if (activateWaypointFromClick(index)) {
          return;
        }
        speak(buildWaypointInstruction(point, index), "instruction");
      });
      waypointMarkers.push(marker);
    });
  }

  function updatePathLine() {
    if (pathLine) {
      map.removeLayer(pathLine);
      pathLine = null;
    }
    if (routePoints.length >= 2) {
      pathLine = L.polyline(routePoints.map((point) => [point.lat, point.lng]), {
        color: "#22d3ee",
        weight: 4,
        opacity: 0.9
      }).addTo(map);
    }
  }

  function renderCurrentInstruction() {
    const point = currentPoint();
    if (!point) {
      currentInstructionEl.textContent = currentPath ? "Route complete." : "No path loaded.";
      currentInstructionMetaEl.textContent = currentPath
        ? "All route points have been reached."
        : "Load a route and start tracking to receive guidance.";
      distanceValueEl.textContent = "--";
      return;
    }

    const distance = distanceToPoint(point);
    currentInstructionEl.textContent = buildWaypointInstruction(point, activePointIndex) || `Proceed to point ${activePointIndex + 1}.`;
    currentInstructionMetaEl.textContent = distance === null
      ? `Point ${activePointIndex + 1} of ${routePoints.length}`
      : `Point ${activePointIndex + 1} of ${routePoints.length} | ${distance.toFixed(1)} m away`;
    distanceValueEl.textContent = distance === null ? "--" : `${distance.toFixed(1)} m`;
  }

  function renderRoute() {
    renderCurrentInstruction();
    renderPointList();
    updateWaypointMarkers();
    updatePathLine();
    renderTrailDirection();
  }

  function updateGpsUi() {
    if (latestLatitude === null || latestLongitude === null) {
      latValueEl.textContent = "--";
      lonValueEl.textContent = "--";
      accuracyValueEl.textContent = "--";
      gpsStatusEl.textContent = walkingActive ? "GPS: waiting for location..." : "GPS not started.";
      renderCurrentInstruction();
      renderPointList();
      return;
    }

    latValueEl.textContent = latestLatitude.toFixed(6);
    lonValueEl.textContent = latestLongitude.toFixed(6);
    accuracyValueEl.textContent = latestAccuracy === null ? "--" : `${latestAccuracy.toFixed(1)} m`;
    gpsStatusEl.textContent = `GPS: ${latestLatitude.toFixed(6)}, ${latestLongitude.toFixed(6)}`;
    currentLocationMarker.setLatLng([latestLatitude, latestLongitude]);
    map.setView([latestLatitude, latestLongitude], 19);
    renderCurrentInstruction();
    renderPointList();
  }

  function maybeAdvanceRoute() {
    const point = currentPoint();
    if (!point || !walkingActive) {
      offRouteWarningActive = false;
      return;
    }

    const distance = distanceToPoint(point);
    if (distance === null) {
      offRouteWarningActive = false;
      return;
    }

    if (distance > OFF_ROUTE_WARNING_METERS) {
      if (!offRouteWarningActive) {
        setStatus("You are to far away from your route", "warn");
        const distanceText = formatDistanceForSpeech(distance);
        speak(
          currentLanguage === "en-GB"
            ? `You are too far away from your route. You are ${distanceText} from the next waypoint.`
            : `Je bent te ver van je route verwijderd. Je bent ${distanceText} van het volgende waypoint.`,
          "instruction"
        );
        offRouteWarningActive = true;
      }
    } else if (offRouteWarningActive) {
      setStatus(`Walking ${currentPath?.name || "route"}.`, "ok");
      offRouteWarningActive = false;
    }

    if (distance <= ARRIVAL_RADIUS_METERS) {
      if (lastSpokenPointId !== point.id) {
        speak(buildArrivalSpeech(point, activePointIndex), "instruction");
        lastSpokenPointId = point.id;
      }

      activePointIndex += 1;
      renderRoute();

      if (activePointIndex >= routePoints.length) {
        setStatus("Route complete.", "ok");
        speak(currentLanguage === "en-GB" ? "You have reached the destination." : "Je hebt je bestemming bereikt.", "instruction");
        offRouteWarningActive = false;
      } else {
        setStatus(`Reached point ${activePointIndex}. Next instruction is active.`, "ok");
        offRouteWarningActive = false;
      }
    }
  }

  async function startCamera(preferredDeviceId = null) {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }

    const videoConstraints = preferredDeviceId
      ? {
          deviceId: { exact: preferredDeviceId },
          width: { ideal: TARGET_W },
          height: { ideal: TARGET_H }
        }
      : {
          width: { ideal: TARGET_W },
          height: { ideal: TARGET_H },
          facingMode: "environment"
        };

    stream = await navigator.mediaDevices.getUserMedia({
      video: videoConstraints,
      audio: false
    });

    trailVideoEl.srcObject = stream;
    await trailVideoEl.play();
    scheduleTrailPreviewRender();

    const track = stream.getVideoTracks()[0];
    activeVideoDeviceId = track?.getSettings?.().deviceId ?? null;
  }

  function sendAuthMessage() {
    if (!ws || ws.readyState !== WebSocket.OPEN || authStarted) return;
    authStarted = true;
    ws.send(JSON.stringify({
      type: "auth",
      token: BEARER_TOKEN,
      "X-Room": SIGNALING_ROOM
    }));
  }

  function restartCaptureTimer() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (ws && ws.readyState === WebSocket.OPEN && isAuthenticated) {
      timer = setInterval(captureAndSend, sendIntervalMs);
    }
  }

  function beginAuthenticatedStreaming() {
    if (isAuthenticated) return;
    isAuthenticated = true;
    restartCaptureTimer();
    mapTrailDirectionMetaEl.textContent = "Live segmentation connected.";
    streamMetaEl.textContent = `Configured interval: ${(sendIntervalMs / 1000).toFixed(2)} s per frame | ${headingFeedbackFps.toFixed(1)} fps target.`;
  }

  async function startSegmentationGuidance() {
    if (timer || (ws && ws.readyState === WebSocket.OPEN)) {
      return;
    }

    trailCapEl.width = TARGET_W;
    trailCapEl.height = TARGET_H;
    currentSessionId = createSessionId();
    renderSessionId();
    isAuthenticated = false;
    authStarted = false;
    latestHeading = null;
    lastSpokenDirectionKey = null;
    lastLatency = null;
    latestResultMasks = [];
    sentFrames = 0;
    framesSince = 0;
    lastRateT = performance.now();
    sentFramesValueEl.textContent = "0";
    sendRateValueEl.textContent = "0.0 fps";
    streamMetaEl.textContent = `Configured interval: ${(sendIntervalMs / 1000).toFixed(2)} s per frame | ${headingFeedbackFps.toFixed(1)} fps target.`;
    renderTrailDirection();

    try {
      await startCamera(activeVideoDeviceId);
    } catch (error) {
      console.error("Camera error:", error);
      mapTrailDirectionMetaEl.textContent = "Camera unavailable for segmentation guidance.";
      return;
    }

    try {
      ws = new WebSocket(SIGNALING_SERVER);
      ws.binaryType = "arraybuffer";

      ws.onopen = () => {
        sendAuthMessage();
      };

      ws.onerror = (error) => {
        console.error("WS error", error);
        mapTrailDirectionMetaEl.textContent = "Segmentation websocket error.";
      };

      ws.onclose = () => {
        if (walkingActive) {
          mapTrailDirectionMetaEl.textContent = "Segmentation guidance disconnected.";
        }
        stopSegmentationGuidance(false);
        renderTrailDirection();
      };

      ws.onmessage = (msg) => {
        if (typeof msg.data !== "string") return;

        try {
          const payload = JSON.parse(msg.data);

          if (payload?.type === "auth_required") {
            sendAuthMessage();
            return;
          }

          if (
            payload?.type === "auth_ok" ||
            payload?.type === "authenticated" ||
            payload?.auth === "ok" ||
            payload?.authenticated === true ||
            payload?.type === "room_joined"
          ) {
            beginAuthenticatedStreaming();
            return;
          }

          if (
            payload?.type === "auth_error" ||
            payload?.type === "unauthorized" ||
            payload?.authenticated === false
          ) {
            mapTrailDirectionMetaEl.textContent = "Segmentation authentication failed.";
            stopSegmentationGuidance(true);
            return;
          }

          if (payload?.type === "helper_message") {
            const incomingSessionId = payload?.sessionId ?? payload?.session_id ?? null;
            if (currentSessionId && incomingSessionId && incomingSessionId !== currentSessionId) {
              return;
            }
            const helperText = String(payload?.message || "").trim();
            if (!helperText) {
              return;
            }
            showHelperMessage(helperText, String(payload?.from || "remote assistant"));
            speak(helperText, "instruction");
            return;
          }

          const incomingSessionId = payload?.sessionId ?? payload?.session_id ?? null;
          if (currentSessionId && incomingSessionId && incomingSessionId !== currentSessionId) {
            return;
          }
          if (currentSessionId && !incomingSessionId && (payload?.heading !== undefined || payload?.frame_id !== undefined)) {
            return;
          }

          const normalized = normalizeHeading(payload?.heading);
          if (normalized !== null) {
            latestHeading = normalized;
            updateDirectionSpeech();
          }

          if (payload?.resultMasks !== undefined || payload?.returnMasks !== undefined) {
            latestResultMasks = payload?.resultMasks ?? payload?.returnMasks ?? [];
            renderTrailDirection();
          } else if (payload?.frame_id !== undefined) {
            latestResultMasks = [];
            renderTrailDirection();
          }

          const frameId = payload?.frame_id;
          if (frameId !== null && frameId !== undefined) {
            const sentAt = sentAtByFrameId.get(String(frameId));
            if (typeof sentAt === "number") {
              lastLatency = Math.max(0, performance.now() - sentAt);
            }
            sentAtByFrameId.delete(String(frameId));
          }
        } catch {}
      };
    } catch (error) {
      console.error("WS connect failed", error);
      mapTrailDirectionMetaEl.textContent = "Unable to connect segmentation guidance.";
      stopSegmentationGuidance(true);
    }
  }

  function stopSegmentationGuidance(resetUi = true) {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (ws) {
      const socket = ws;
      ws = null;
      try {
        if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
          socket.close();
        }
      } catch {}
    }
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    stopTrailPreviewRender();

    sentAtByFrameId.clear();
    nextFrameId = 1;
    currentSessionId = null;
    renderSessionId();
    isAuthenticated = false;
    authStarted = false;
    latestHeading = null;
    lastSpokenDirectionKey = null;
    lastLatency = null;
    latestResultMasks = [];
    framesSince = 0;
    sendRateValueEl.textContent = "0.0 fps";
    streamMetaEl.textContent = `Configured interval: ${(sendIntervalMs / 1000).toFixed(2)} s per frame | ${headingFeedbackFps.toFixed(1)} fps target.`;
    if (resetUi) {
      renderTrailDirection();
    }
  }

  function captureAndSend() {
    if (!ws || ws.readyState !== WebSocket.OPEN || !isAuthenticated) return;
    if (!trailVideoEl.videoWidth || !trailVideoEl.videoHeight) return;

    trailCapCtx.drawImage(trailVideoEl, 0, 0, TARGET_W, TARGET_H);

    trailCapEl.toBlob(async (blob) => {
      if (!blob) return;

      try {
        const frameId = String(nextFrameId++);
        const buf = await blob.arrayBuffer();
        sentAtByFrameId.set(frameId, performance.now());

        ws.send(JSON.stringify({
          type: "frame_meta",
          frame_id: frameId,
          sessionId: currentSessionId,
          latitude: latestLatitude,
          longitude: latestLongitude,
          gps_accuracy: latestAccuracy,
          model: currentModel,
          confidence: currentModelConfidence,
          source: "live_camera",
          lastlatency: lastLatency,
          returnMasks: currentReturnMasks,
          sendMQTT: currentSendMQTT
        }));

        ws.send(buf);
        sentFrames += 1;
        sentFramesValueEl.textContent = String(sentFrames);
        updateSendRate();
      } catch (error) {
        console.error("Failed to send segmentation frame:", error);
      }
    }, "image/jpeg", JPEG_QUALITY);
  }

  function startLocationTracking() {
    if (!("geolocation" in navigator) || geoWatchId !== null) {
      return;
    }

    try {
      geoWatchId = navigator.geolocation.watchPosition(
        (position) => {
          const lat = Number(position?.coords?.latitude);
          const lon = Number(position?.coords?.longitude);
          const accuracy = Number(position?.coords?.accuracy);
          latestLatitude = Number.isFinite(lat) ? lat : null;
          latestLongitude = Number.isFinite(lon) ? lon : null;
          latestAccuracy = Number.isFinite(accuracy) ? accuracy : null;
          updateGpsUi();
          maybeAdvanceRoute();
        },
        (error) => {
          console.warn("Geolocation unavailable:", error);
          gpsStatusEl.textContent = "GPS: permission denied or unavailable";
          setStatus("GPS permission denied or unavailable.", "warn");
        },
        { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 }
      );
    } catch (error) {
      console.warn("Failed to start geolocation:", error);
      gpsStatusEl.textContent = "GPS: failed to start";
      setStatus("Failed to start GPS tracking.", "warn");
    }
  }

  function stopLocationTracking() {
    if (geoWatchId !== null) {
      try { navigator.geolocation.clearWatch(geoWatchId); } catch {}
      geoWatchId = null;
    }
    walkingActive = false;
    latestLatitude = null;
    latestLongitude = null;
    latestAccuracy = null;
    updateGpsUi();
  }

  async function loadSavedPaths() {
    const response = await fetch(`${API_URL}?action=list_paths`);
    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to list saved paths.", "warn");
      return;
    }

    savedPathsEl.innerHTML = `<option value="">Choose a saved path...</option>` + payload.paths.map((item) => `
      <option value="${escapeHtml(item.slug)}">${escapeHtml(item.name)} (${item.pointCount})</option>
    `).join("");

    const requestedSlug = getRequestedPathSlug();
    const preferredSlug = requestedSlug || getLastSelectedPathSlug();
    if (preferredSlug && payload.paths.some((item) => item.slug === preferredSlug)) {
      savedPathsEl.value = preferredSlug;
      await loadSelectedPath();
    } else if (requestedSlug) {
      setStatus("The requested saved path was not found.", "warn");
    }
  }

  async function loadSelectedPath() {
    const slug = savedPathsEl.value;
    if (!slug) {
      setStatus("Choose a saved path first.", "warn");
      return;
    }

    const response = await fetch(`${API_URL}?action=load_path&slug=${encodeURIComponent(slug)}`);
    const payload = await response.json();
    if (!payload?.ok || !payload.path) {
      setStatus(payload?.error || "Failed to load path.", "warn");
      return;
    }
    setLastSelectedPathSlug(slug);

    currentPath = payload.path;
    currentLanguage = payload.path.language === "en-GB" ? "en-GB" : DEFAULT_LANGUAGE;
    currentModel = normalizeModelName(payload.path.model);
    const loadedModelConfidence = Number.parseFloat(payload.path.modelConfidence);
    currentModelConfidence = Number.isFinite(loadedModelConfidence)
      ? Math.min(1, Math.max(0, loadedModelConfidence))
      : DEFAULT_MODEL_CONFIDENCE;
    currentReturnMasks = payload.path.returnMasks === true;
    currentSendMQTT = payload.path.sendMQTT === true;
    syncTrailDirectionMode();
    const loadedHeadingFeedbackFps = Number.parseFloat(payload.path.headingFeedbackFps);
    headingFeedbackFps = Number.isFinite(loadedHeadingFeedbackFps)
      ? Math.min(10, Math.max(0.2, loadedHeadingFeedbackFps))
      : DEFAULT_HEADING_FEEDBACK_FPS;
    sendIntervalMs = Math.max(100, Math.round(1000 / headingFeedbackFps));
    routePoints = Array.isArray(payload.path.points) ? payload.path.points.map((point, index) => ({
      id: point.id || `point-${index + 1}`,
      lat: Number(point.lat),
      lng: Number(point.lng ?? point.lon),
      instruction: String(point.instruction || "").trim()
    })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng)) : [];

    activePointIndex = 0;
    lastSpokenPointId = null;
    showHelperMessage("");
    renderRoute();

    if (routePoints.length) {
      map.fitBounds(routePoints.map((point) => [point.lat, point.lng]), { padding: [40, 40] });
      setStatus(`Loaded ${payload.path.name || slug}.`, "ok");
      speak(buildPathSelectedSpeech(payload.path.name || slug), "instruction");
    } else {
      setStatus("This saved path does not contain valid points.", "warn");
    }
  }

  function startWalking() {
    if (!routePoints.length) {
      setStatus("Load a saved path first.", "warn");
      return;
    }
    activePointIndex = 0;
    walkingActive = true;
    lastSpokenPointId = null;
    offRouteWarningActive = false;
    setWalkingChromeVisibility(true);
    setStatus(`Walking ${currentPath?.name || "route"}.`, "ok");
    startLocationTracking();
    startSegmentationGuidance();
    renderRoute();
    if (currentPoint()) {
      speak(buildWaypointInstruction(currentPoint(), activePointIndex) || `Proceed to point ${activePointIndex + 1}.`, "instruction");
    }
  }

  function stopWalking() {
    stopLocationTracking();
    stopSegmentationGuidance();
    setWalkingChromeVisibility(false);
    if ("speechSynthesis" in window) {
      window.speechSynthesis.cancel();
    }
    activeSpeechKind = null;
    activePointIndex = 0;
    lastSpokenPointId = null;
    offRouteWarningActive = false;
    showHelperMessage("");
    renderRoute();
    setStatus("Walking stopped.", "ok");
  }

  function repeatLastInstruction() {
    if (!lastInstructionText) {
      setStatus("No instruction has been spoken yet.", "warn");
      return;
    }
    speak(lastInstructionText, "instruction");
    setStatus("Repeated the last instruction.", "ok");
  }

  function toggleHeadingSpeech() {
    headingSpeechEnabled = !headingSpeechEnabled;
    if (!headingSpeechEnabled && "speechSynthesis" in window && activeSpeechKind === "direction") {
      window.speechSynthesis.cancel();
      activeSpeechKind = null;
    }
    renderHeadingSpeechToggle();
    setStatus(
      headingSpeechEnabled ? "Heading speech enabled." : "Heading speech muted.",
      "ok"
    );
  }

  startBtn.addEventListener("click", startWalking);
  repeatBtn.addEventListener("click", repeatLastInstruction);
  headingSpeechToggleBtn.addEventListener("click", toggleHeadingSpeech);
  copySessionBtn.addEventListener("click", () => {
    copySessionId().catch(() => {
      sessionIdMetaEl.textContent = "Failed to copy session id.";
      setStatus("Failed to copy session id.", "warn");
    });
  });
  satelliteToggleBtn.addEventListener("click", () => {
    satelliteVisible = !satelliteVisible;
    updateBaseLayer();
  });
  stopBtn.addEventListener("click", stopWalking);
  savedPathsEl.addEventListener("change", () => {
    loadSelectedPath().catch(() => setStatus("Failed to load path.", "warn"));
  });

  renderRoute();
  renderSessionId();
  renderHeadingSpeechToggle();
  syncTrailDirectionMode();
  setWalkingChromeVisibility(false);
  drawArrow(null);
  clearTrailPreview();
  clearTrailMaskOverlay();
  updateBaseLayer();
  window.addEventListener("resize", () => {
    drawTrailPreviewFrame();
    renderTrailDirection();
  });
  loadSavedPaths().catch(() => setStatus("Unable to list saved paths.", "warn"));
</script>
</body>
</html>
