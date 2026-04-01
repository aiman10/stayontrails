<?php
$bearerToken = 'LTddk_ptxQX-omdw5B5rfpniA2wB-19KBxFaKuODMzw';
$wsUrl = 'wss://signaling.ehb.be/ws/pathnavigation';
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'saved_paths';

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
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
  <title>Stay On Trails - Remote Assistant</title>
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
    a:focus-visible,button:focus-visible,textarea:focus-visible,input:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1420px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .layout{max-width:1420px;margin:0 auto;padding:18px;display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,.8fr);gap:18px}
    .panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.26)}
    .cameraPanel,.sidePanel{padding:16px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
    .btn{padding:11px 14px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:#334155}
    .btnPrimary{background:var(--accent);color:var(--accent-ink);border-color:rgba(250,204,21,.35)}
    .btnPrimary:hover{background:#fde047}
    .btn:disabled{opacity:.55;cursor:not-allowed}
    .status{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:14px;color:var(--muted)}
    .status.ok{color:#d1fae5;border-color:rgba(52,211,153,.35);background:rgba(16,185,129,.08)}
    .status.warn{color:#ffe4e6;border-color:rgba(251,113,133,.35);background:rgba(244,63,94,.08)}
    .viewerWrap{position:relative;margin-top:14px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#000;min-height:44vh}
    .viewerFrame{display:block;width:100%;height:44vh;object-fit:contain;background:#000}
    .viewerEmpty{display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:16px}
    .viewerOverlay{position:absolute;inset:auto 16px 16px 16px;display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;background:rgba(2,6,23,.72);backdrop-filter:blur(8px)}
    .meta{font-size:14px;color:var(--muted)}
    .card{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .previewImg{display:block;width:100%;height:auto;border-radius:14px;border:1px solid var(--line);background:#000;margin-top:12px}
    .muted{color:var(--muted);font-size:14px;line-height:1.5}
    .stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
    .stat{padding:12px;border-radius:14px;background:#0b1220;border:1px solid var(--line)}
    .statLabel{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px}
    .statValue{font-size:20px;font-weight:700}
    .field{display:flex;flex-direction:column;gap:6px}
    .field label{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .field input,.field textarea,.field select{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--line);background:#020617;color:#fff;font-size:14px}
    .field textarea{min-height:110px;resize:vertical}
    #map{height:38vh;min-height:280px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line);margin-top:14px}
    .routeMeta{margin-top:12px}
    .waypointBubble{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#1d4ed8;color:#fff;font-weight:700;border:2px solid rgba(15,23,42,.92)}
    .leaflet-div-icon{background:transparent;border:0}
    #captureCanvas{display:none}
    @media (max-width: 1100px){
      .layout{grid-template-columns:1fr}
      .viewerWrap,.viewerFrame{min-height:32vh;height:32vh}
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
        <li><a class="cta" href="#" aria-current="page">Remote assistant</a></li>
      </ul>
    </nav>
  </div>
</header>

<main class="layout">
  <section class="panel cameraPanel">
    <div class="toolbar">
      <button id="connectBtn" class="btn btnPrimary" type="button">Connect Viewer</button>
      <button id="takePictureBtn" class="btn" type="button" disabled>Take Picture</button>
      <button id="disconnectBtn" class="btn" type="button" disabled>Disconnect</button>
    </div>
    <div id="status" class="status">Viewer disconnected.</div>
    <div class="viewerWrap">
      <img id="viewerImage" class="viewerFrame" alt="Latest frame from the signaling server" style="display:none" />
      <div id="viewerEmpty" class="viewerFrame viewerEmpty">Waiting for frames from the signaling server.</div>
      <div class="viewerOverlay">
        <div class="meta" id="streamMeta">No frame received yet.</div>
      </div>
    </div>
    <div class="card routeMeta">
      <div class="sectionTitle">Route Map</div>
      <div class="field" style="margin-top:12px">
        <label for="savedPaths">Saved Path</label>
        <select id="savedPaths">
          <option value="">Choose a saved path...</option>
        </select>
      </div>
      <div id="routeMapMeta" class="muted" style="margin-top:12px">Choose a route to view it on the map.</div>
      <div id="map" aria-label="Route help map"></div>
    </div>
  </section>

  <aside class="panel sidePanel">
    <div class="card">
      <div class="sectionTitle">Helper Message</div>
      <div class="field" style="margin-top:12px">
        <label for="targetSessionId">Session ID</label>
        <input id="targetSessionId" type="text" placeholder="Optional target session id" />
      </div>
      <div id="sessionHelpMeta" class="muted" style="margin-top:10px">Set the walker session id to receive the correct frames and GPS location.</div>
      <div class="field" style="margin-top:12px">
        <label for="helperMessage">Message</label>
        <textarea id="helperMessage" placeholder="Type a message for the walker"></textarea>
      </div>
      <button id="sendMessageBtn" class="btn btnPrimary" type="button" style="margin-top:12px">Send Message</button>
      <div id="messageStatus" class="muted" style="margin-top:10px">Messages are sent over the signaling websocket.</div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="sectionTitle">Session</div>
      <div id="activeSessionValue" class="statValue" style="margin-top:10px">--</div>
      <div id="activeSessionMeta" class="muted" style="margin-top:10px">No target session selected.</div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="sectionTitle">Last Picture</div>
      <p class="muted" id="pictureHint" style="margin-top:10px">Take a picture from the most recent frame received from the signaling server.</p>
      <a id="downloadLink" class="btn" href="#" download="route-picture.jpg" style="margin-top:12px;display:none">Download Picture</a>
      <img id="snapshotPreview" class="previewImg" alt="Captured route snapshot" style="display:none" />
    </div>

    <div class="card" style="margin-top:14px">
      <div class="sectionTitle">Stream Stats</div>
      <div class="stats">
        <div class="stat">
          <div class="statLabel">Frames Received</div>
          <div id="framesReceivedValue" class="statValue">0</div>
        </div>
        <div class="stat">
          <div class="statLabel">Receive Rate</div>
          <div id="receiveRateValue" class="statValue">0.0 fps</div>
        </div>
      </div>
    </div>
  </aside>
</main>

<canvas id="captureCanvas"></canvas>

<script>
  const API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const SIGNALING_SERVER = <?php echo json_encode($wsUrl, JSON_UNESCAPED_SLASHES); ?>;
  const BEARER_TOKEN = <?php echo json_encode($bearerToken, JSON_UNESCAPED_SLASHES); ?>;
  const DEFAULT_CENTER = [50.8503, 4.3517];
  const LAST_SELECTED_PATH_STORAGE_KEY = "stayontrails.lastSelectedPathSlug";

  const connectBtn = document.getElementById("connectBtn");
  const disconnectBtn = document.getElementById("disconnectBtn");
  const takePictureBtn = document.getElementById("takePictureBtn");
  const sendMessageBtn = document.getElementById("sendMessageBtn");
  const targetSessionIdEl = document.getElementById("targetSessionId");
  const sessionHelpMetaEl = document.getElementById("sessionHelpMeta");
  const helperMessageEl = document.getElementById("helperMessage");
  const messageStatusEl = document.getElementById("messageStatus");
  const activeSessionValueEl = document.getElementById("activeSessionValue");
  const activeSessionMetaEl = document.getElementById("activeSessionMeta");
  const statusEl = document.getElementById("status");
  const streamMetaEl = document.getElementById("streamMeta");
  const viewerImageEl = document.getElementById("viewerImage");
  const viewerEmptyEl = document.getElementById("viewerEmpty");
  const snapshotPreviewEl = document.getElementById("snapshotPreview");
  const pictureHintEl = document.getElementById("pictureHint");
  const downloadLinkEl = document.getElementById("downloadLink");
  const captureCanvasEl = document.getElementById("captureCanvas");
  const framesReceivedValueEl = document.getElementById("framesReceivedValue");
  const receiveRateValueEl = document.getElementById("receiveRateValue");
  const savedPathsEl = document.getElementById("savedPaths");
  const routeMapMetaEl = document.getElementById("routeMapMeta");

  const map = L.map("map").setView(DEFAULT_CENTER, 16);
  const streetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(map);

  let ws = null;
  let authStarted = false;
  let latestFrameUrl = "";
  let latestFrameImage = null;
  let framesReceived = 0;
  let framesSince = 0;
  let lastRateT = performance.now();
  let pathLine = null;
  let waypointMarkers = [];
  let currentLocationMarker = null;
  let selectedSessionId = "";
  let latestIncomingSessionId = "";
  let pendingFrameMeta = null;

  function setStatus(message, tone = "") {
    statusEl.textContent = message;
    statusEl.className = `status${tone ? ` ${tone}` : ""}`;
  }

  function updateButtons() {
    const connected = !!ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING);
    connectBtn.disabled = connected || !selectedSessionId;
    disconnectBtn.disabled = !connected;
    takePictureBtn.disabled = !latestFrameImage;
    sendMessageBtn.disabled = !connected;
  }

  function updateSessionUi() {
    activeSessionValueEl.textContent = selectedSessionId || "--";
    activeSessionMetaEl.textContent = selectedSessionId
      ? `Receiving frames and GPS for session ${selectedSessionId}.`
      : "No target session selected.";
    sessionHelpMetaEl.textContent = selectedSessionId
      ? `Viewer filter is active for session ${selectedSessionId}.`
      : "Set the walker session id to enable the viewer and receive the correct frames and GPS location.";
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

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function updateRouteMap(points, routeName) {
    waypointMarkers.forEach((marker) => map.removeLayer(marker));
    waypointMarkers = [];
    if (pathLine) {
      map.removeLayer(pathLine);
      pathLine = null;
    }

    if (!points.length) {
      routeMapMetaEl.textContent = "This route has no valid points.";
      map.setView(DEFAULT_CENTER, 16);
      return;
    }

    pathLine = L.polyline(points.map((point) => [point.lat, point.lng]), {
      color: "#22d3ee",
      weight: 4,
      opacity: 0.9
    }).addTo(map);

    points.forEach((point, index) => {
      const marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
          className: "",
          html: `<div class="waypointBubble">${index + 1}</div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 14]
        }),
        title: point.instruction || `Point ${index + 1}`
      }).addTo(map);
      waypointMarkers.push(marker);
    });

    map.fitBounds(points.map((point) => [point.lat, point.lng]), { padding: [40, 40] });
    routeMapMetaEl.textContent = `${routeName} | ${points.length} points`;
    if (currentLocationMarker) {
      currentLocationMarker.bringToFront();
    }
  }

  function updateWalkerLocation(lat, lng, accuracy = null) {
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      return;
    }
    if (!currentLocationMarker) {
      currentLocationMarker = L.circleMarker([lat, lng], {
        radius: 8,
        color: "#facc15",
        weight: 3,
        fillColor: "#facc15",
        fillOpacity: 0.85
      }).addTo(map);
    } else {
      currentLocationMarker.setLatLng([lat, lng]);
    }
    const accuracyText = Number.isFinite(accuracy) ? ` | GPS accuracy ${accuracy.toFixed(1)} m` : "";
    routeMapMetaEl.textContent = selectedSessionId
      ? `Tracking session ${selectedSessionId} at ${lat.toFixed(6)}, ${lng.toFixed(6)}${accuracyText}`
      : `Walker location ${lat.toFixed(6)}, ${lng.toFixed(6)}${accuracyText}`;
  }

  function setSelectedSessionId(sessionId) {
    selectedSessionId = String(sessionId || "").trim();
    targetSessionIdEl.value = selectedSessionId;
    updateSessionUi();
    updateButtons();
  }

  function payloadMatchesSelectedSession(payload) {
    const payloadSessionId = String(payload?.sessionId ?? payload?.session_id ?? "").trim();
    latestIncomingSessionId = payloadSessionId || latestIncomingSessionId;
    if (!selectedSessionId) {
      return true;
    }
    return payloadSessionId === selectedSessionId;
  }

  async function loadSavedPaths() {
    const response = await fetch(`${API_URL}?action=list_paths`);
    const payload = await response.json();
    if (!payload?.ok) {
      routeMapMetaEl.textContent = payload?.error || "Failed to list saved paths.";
      return;
    }

    savedPathsEl.innerHTML = `<option value="">Choose a saved path...</option>` + payload.paths.map((item) => `
      <option value="${escapeHtml(item.slug)}">${escapeHtml(item.name)} (${item.pointCount})</option>
    `).join("");

    const preferredSlug = getLastSelectedPathSlug();
    if (preferredSlug && payload.paths.some((item) => item.slug === preferredSlug)) {
      savedPathsEl.value = preferredSlug;
      await loadSelectedPath();
    }
  }

  async function loadSelectedPath() {
    const slug = savedPathsEl.value;
    if (!slug) {
      updateRouteMap([], "");
      routeMapMetaEl.textContent = "Choose a route to view it on the map.";
      return;
    }

    const response = await fetch(`${API_URL}?action=load_path&slug=${encodeURIComponent(slug)}`);
    const payload = await response.json();
    if (!payload?.ok || !payload.path) {
      routeMapMetaEl.textContent = payload?.error || "Failed to load path.";
      return;
    }

    setLastSelectedPathSlug(slug);
    const points = Array.isArray(payload.path.points) ? payload.path.points.map((point, index) => ({
      id: point.id || `point-${index + 1}`,
      lat: Number(point.lat),
      lng: Number(point.lng ?? point.lon),
      instruction: String(point.instruction || "").trim()
    })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng)) : [];

    updateRouteMap(points, payload.path.name || slug);
  }

  function revokeLatestFrameUrl() {
    if (latestFrameUrl) {
      URL.revokeObjectURL(latestFrameUrl);
      latestFrameUrl = "";
    }
  }

  function updateReceiveRate() {
    framesSince += 1;
    const now = performance.now();
    const dt = now - lastRateT;
    if (dt >= 1000) {
      const fps = framesSince / (dt / 1000);
      receiveRateValueEl.textContent = `${fps.toFixed(1)} fps`;
      framesSince = 0;
      lastRateT = now;
    }
  }

  function showFrameFromUrl(url, label = "") {
    const image = new Image();
    image.onload = () => {
      latestFrameImage = image;
      viewerImageEl.src = url;
      viewerImageEl.style.display = "block";
      viewerEmptyEl.style.display = "none";
      takePictureBtn.disabled = false;
      streamMetaEl.textContent = label || "Live frame received from the signaling server.";
      updateButtons();
    };
    image.src = url;
  }

  function handleBinaryFrame(data) {
    if (selectedSessionId && !pendingFrameMeta) {
      return;
    }
    if (selectedSessionId && pendingFrameMeta && !payloadMatchesSelectedSession(pendingFrameMeta)) {
      pendingFrameMeta = null;
      return;
    }
    revokeLatestFrameUrl();
    const blob = new Blob([data], { type: "image/jpeg" });
    latestFrameUrl = URL.createObjectURL(blob);
    framesReceived += 1;
    framesReceivedValueEl.textContent = String(framesReceived);
    updateReceiveRate();
    showFrameFromUrl(latestFrameUrl, `Binary JPEG frame ${framesReceived} received.`);
    setStatus("Receiving frames from the signaling server.", "ok");
    pendingFrameMeta = null;
  }

  function handleJsonFrame(payload) {
    if (!payload || typeof payload !== "object") {
      return false;
    }
    if (payload?.type === "frame_meta") {
      pendingFrameMeta = payload;
      if (!payloadMatchesSelectedSession(payload)) {
        return true;
      }
      const lat = Number(payload?.latitude);
      const lng = Number(payload?.longitude);
      const accuracy = Number(payload?.gps_accuracy);
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        updateWalkerLocation(lat, lng, Number.isFinite(accuracy) ? accuracy : null);
      }
      if (!selectedSessionId && (payload?.sessionId || payload?.session_id)) {
        activeSessionMetaEl.textContent = `Latest incoming session: ${payload.sessionId || payload.session_id}`;
      }
      return true;
    }
    if (!payloadMatchesSelectedSession(payload)) {
      return false;
    }
    if (typeof payload.heading === "number" || typeof payload.heading === "string") {
      streamMetaEl.textContent = `Heading update: ${payload.heading}`;
    }
    if (payload?.type === "helper_message_ack") {
      messageStatusEl.textContent = "Helper message sent.";
      return true;
    }
    if (!payload.data) {
      return false;
    }

    framesReceived += 1;
    framesReceivedValueEl.textContent = String(framesReceived);
    updateReceiveRate();
    const dataUrl = `data:image/jpeg;base64,${payload.data}`;
    latestFrameImage = new Image();
    latestFrameImage.onload = () => {
      viewerImageEl.src = dataUrl;
      viewerImageEl.style.display = "block";
      viewerEmptyEl.style.display = "none";
      takePictureBtn.disabled = false;
      streamMetaEl.textContent = `JSON frame ${framesReceived} received.`;
      updateButtons();
    };
    latestFrameImage.src = dataUrl;
    setStatus("Receiving frames from the signaling server.", "ok");
    return true;
  }

  function sendAuthMessage() {
    if (!ws || ws.readyState !== WebSocket.OPEN || authStarted) {
      return;
    }
    authStarted = true;
    ws.send(JSON.stringify({
      type: "auth",
      token: BEARER_TOKEN
    }));
  }

  function connectViewer() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
      return;
    }
    if (!selectedSessionId) {
      setStatus("Enter a session id before connecting.", "warn");
      messageStatusEl.textContent = "A session id is required for remote viewing.";
      updateButtons();
      return;
    }

    authStarted = false;
    framesReceived = 0;
    framesSince = 0;
    lastRateT = performance.now();
    framesReceivedValueEl.textContent = "0";
    receiveRateValueEl.textContent = "0.0 fps";
    setStatus("Connecting to the signaling server...", "");
    streamMetaEl.textContent = "Waiting for frames from the signaling server.";

    ws = new WebSocket(SIGNALING_SERVER);
    ws.binaryType = "arraybuffer";
    updateButtons();

    ws.onopen = () => {
      sendAuthMessage();
      setStatus("Connected. Waiting for stream frames...", "ok");
      updateButtons();
    };

    ws.onmessage = (event) => {
      if (event.data instanceof ArrayBuffer) {
        handleBinaryFrame(event.data);
        return;
      }
      if (event.data instanceof Blob) {
        event.data.arrayBuffer().then((buffer) => handleBinaryFrame(buffer)).catch(() => {
          setStatus("Failed to read a streamed frame.", "warn");
        });
        return;
      }
      if (typeof event.data === "string") {
        try {
          const payload = JSON.parse(event.data);
          if (payload?.type === "auth_required") {
            sendAuthMessage();
            return;
          }
          if (payload?.type === "auth_error" || payload?.type === "unauthorized" || payload?.authenticated === false) {
            setStatus("Viewer authentication failed.", "warn");
            return;
          }
          handleJsonFrame(payload);
        } catch {
          streamMetaEl.textContent = event.data;
        }
      }
    };

    ws.onerror = () => {
      setStatus("Viewer connection failed.", "warn");
    };

    ws.onclose = () => {
      ws = null;
      authStarted = false;
      updateButtons();
      if (!latestFrameImage) {
        setStatus("Viewer disconnected.", "");
      }
    };
  }

  function disconnectViewer() {
    if (ws) {
      try { ws.close(); } catch {}
      ws = null;
    }
    authStarted = false;
    pendingFrameMeta = null;
    updateButtons();
    setStatus("Viewer disconnected.", "");
  }

  function takePicture() {
    if (!latestFrameImage) {
      setStatus("No frame available yet.", "warn");
      return;
    }
    const width = latestFrameImage.naturalWidth || viewerImageEl.naturalWidth;
    const height = latestFrameImage.naturalHeight || viewerImageEl.naturalHeight;
    if (!width || !height) {
      setStatus("The last frame is not ready yet.", "warn");
      return;
    }
    captureCanvasEl.width = width;
    captureCanvasEl.height = height;
    const ctx = captureCanvasEl.getContext("2d");
    ctx.drawImage(latestFrameImage, 0, 0, width, height);
    const dataUrl = captureCanvasEl.toDataURL("image/jpeg", 0.92);
    snapshotPreviewEl.src = dataUrl;
    snapshotPreviewEl.style.display = "block";
    downloadLinkEl.href = dataUrl;
    downloadLinkEl.style.display = "inline-block";
    pictureHintEl.textContent = "Captured picture from the latest streamed frame.";
    setStatus("Picture captured.", "ok");
  }

  function sendHelperMessage() {
    const message = helperMessageEl.value.trim();
    if (!message) {
      messageStatusEl.textContent = "Enter a message first.";
      return;
    }
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      messageStatusEl.textContent = "Connect the viewer first.";
      return;
    }
    const targetSessionId = targetSessionIdEl.value.trim();
    ws.send(JSON.stringify({
      type: "helper_message",
      sessionId: targetSessionId || null,
      message
    }));
    helperMessageEl.value = "";
    messageStatusEl.textContent = targetSessionId
      ? `Message sent to session ${targetSessionId}.`
      : "Broadcast helper message sent.";
  }

  connectBtn.addEventListener("click", connectViewer);
  disconnectBtn.addEventListener("click", disconnectViewer);
  takePictureBtn.addEventListener("click", takePicture);
  sendMessageBtn.addEventListener("click", sendHelperMessage);
  targetSessionIdEl.addEventListener("change", () => {
    setSelectedSessionId(targetSessionIdEl.value);
  });
  targetSessionIdEl.addEventListener("input", () => {
    setSelectedSessionId(targetSessionIdEl.value);
  });
  savedPathsEl.addEventListener("change", () => {
    loadSelectedPath().catch(() => {
      routeMapMetaEl.textContent = "Failed to load path.";
    });
  });
  updateSessionUi();
  updateButtons();
  loadSavedPaths().catch(() => {
    routeMapMetaEl.textContent = "Unable to list saved paths.";
  });
</script>
</body>
</html>
