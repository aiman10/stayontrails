<?php
$bearerToken = 'LTddk_ptxQX-omdw5B5rfpniA2wB-19KBxFaKuODMzw';
$wsUrl = 'wss://signaling.ehb.be/ws/pathnavigation';

$instructionLogCsv = __DIR__ . DIRECTORY_SEPARATOR . 'live_instruction_log.csv';
$instructionLogHeader = ['id', 'text', 'timestamp', 'latitude', 'longitude', 'accuracy', 'heading', 'session_id'];

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureInstructionLogCsv(string $csvPath, array $header): void {
    $directory = dirname($csvPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    if (!file_exists($csvPath)) {
        $handle = fopen($csvPath, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to create instruction log CSV.');
        }
        fputcsv($handle, $header);
        fclose($handle);
    }
}

function loadInstructionLogEntries(string $csvPath): array {
    if (!file_exists($csvPath)) {
        return [];
    }

    $entries = [];
    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return [];
    }

    while (($row = fgetcsv($handle)) !== false) {
        $entry = [];
        foreach ($header as $index => $column) {
            $entry[$column] = $row[$index] ?? '';
        }
        $entries[] = [
            'id' => $entry['id'] ?? '',
            'text' => $entry['text'] ?? '',
            'timestamp' => $entry['timestamp'] ?? '',
            'latitude' => ($entry['latitude'] ?? '') === '' ? null : (float)$entry['latitude'],
            'longitude' => ($entry['longitude'] ?? '') === '' ? null : (float)$entry['longitude'],
            'accuracy' => ($entry['accuracy'] ?? '') === '' ? null : (float)$entry['accuracy'],
            'heading' => ($entry['heading'] ?? '') === '' ? null : (float)$entry['heading'],
            'sessionId' => $entry['session_id'] ?? '',
        ];
    }

    fclose($handle);
    return $entries;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    try {
        ensureInstructionLogCsv($instructionLogCsv, $instructionLogHeader);
    } catch (RuntimeException $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    if ($_GET['action'] === 'add_instruction_log') {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);
        if (!is_array($payload)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
        }

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') {
            jsonResponse(['ok' => false, 'error' => 'Instruction text is required.'], 400);
        }

        $entry = [
            'id' => (string)($payload['id'] ?? ''),
            'text' => $text,
            'timestamp' => (string)($payload['timestamp'] ?? gmdate('c')),
            'latitude' => is_numeric($payload['latitude'] ?? null) ? (string)$payload['latitude'] : '',
            'longitude' => is_numeric($payload['longitude'] ?? null) ? (string)$payload['longitude'] : '',
            'accuracy' => is_numeric($payload['accuracy'] ?? null) ? (string)$payload['accuracy'] : '',
            'heading' => is_numeric($payload['heading'] ?? null) ? (string)$payload['heading'] : '',
            'session_id' => (string)($payload['sessionId'] ?? ''),
        ];

        $handle = fopen($instructionLogCsv, 'a');
        if ($handle === false) {
            jsonResponse(['ok' => false, 'error' => 'Unable to open instruction log CSV for writing.'], 500);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            jsonResponse(['ok' => false, 'error' => 'Unable to lock instruction log CSV.'], 500);
        }

        fputcsv($handle, [
            $entry['id'],
            $entry['text'],
            $entry['timestamp'],
            $entry['latitude'],
            $entry['longitude'],
            $entry['accuracy'],
            $entry['heading'],
            $entry['session_id'],
        ]);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        jsonResponse([
            'ok' => true,
            'entry' => [
                'id' => $entry['id'],
                'text' => $entry['text'],
                'timestamp' => $entry['timestamp'],
                'latitude' => $entry['latitude'] === '' ? null : (float)$entry['latitude'],
                'longitude' => $entry['longitude'] === '' ? null : (float)$entry['longitude'],
                'accuracy' => $entry['accuracy'] === '' ? null : (float)$entry['accuracy'],
                'heading' => $entry['heading'] === '' ? null : (float)$entry['heading'],
                'sessionId' => $entry['session_id'],
            ],
        ]);
    }

    if ($_GET['action'] === 'clear_instruction_log') {
        $handle = fopen($instructionLogCsv, 'w');
        if ($handle === false) {
            jsonResponse(['ok' => false, 'error' => 'Unable to reset instruction log CSV.'], 500);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            jsonResponse(['ok' => false, 'error' => 'Unable to lock instruction log CSV.'], 500);
        }
        fputcsv($handle, $instructionLogHeader);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 404);
}

$initialInstructionLog = loadInstructionLogEntries($instructionLogCsv);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails - Live Trail Analytics</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    :root{
      --focus:#22d3ee;
      --menu-bg:rgba(15,23,42,.9);
      --menu-border:rgba(255,255,255,.2);
      --accent:#facc15;
      --accent-ink:#111827;
    }
    html,body{margin:0;height:100%;background:#000;color:#fff;font-family:Arial,Helvetica,sans-serif}
    .skip-link{position:absolute;left:8px;top:-48px;z-index:5;background:var(--accent);color:var(--accent-ink);padding:8px 10px;border-radius:8px;font-weight:700;text-decoration:none}
    .skip-link:focus{top:8px}
    a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:3px}
    .topbar{position:relative;z-index:3;background:var(--menu-bg);border-bottom:1px solid var(--menu-border)}
    .topbar-inner{max-width:1200px;margin:0 auto;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:7px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.12)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .menu .cta:hover{background:#fde047}
    .wrap{position:relative;z-index:2;min-height:calc(100% - 56px);display:flex;align-items:flex-start;justify-content:center;gap:14px;padding:16px;box-sizing:border-box;flex-wrap:wrap}
    .panel{background:rgba(0,0,0,.45);backdrop-filter:blur(3px);padding:14px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.15);display:flex;flex-direction:column;align-items:center;gap:10px;max-width:520px;width:min(100%,520px)}
    .sidePanel{width:min(100%,360px);align-items:stretch}
    .panelTitle{margin:0;font-size:18px;font-weight:700}
    .big{font-size:28px;font-weight:700}
    .row{opacity:.9}
    .controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:center}
    .navBtn{padding:10px 14px;border-radius:10px;border:0;background:#222;color:#fff;font-size:14px;cursor:pointer}
    .navBtn:hover{background:#333}
    .mapBox{position:relative;width:100%;height:240px;background:#000;border:1px solid rgba(255,255,255,.2);border-radius:10px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .mapMeta,.infoRow{font-size:14px;color:#e2e8f0;line-height:1.45}
    .infoList{display:flex;flex-direction:column;gap:8px}
    .infoRow strong{color:#fff}
    .compassWrap{display:flex;flex-direction:column;align-items:center;gap:6px}
    #compass{width:140px;height:140px;display:block}
    .loggerPanel{width:min(100%,760px);align-items:stretch}
    .instructionInput{width:100%;min-height:84px;padding:10px 12px;font-size:16px;line-height:1.4;background:rgba(0,0,0,.58);color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:10px;resize:vertical;box-sizing:border-box}
    .instructionInput::placeholder{color:rgba(255,255,255,.65)}
    .loggerActions{display:flex;gap:10px;flex-wrap:wrap}
    .languageSelect{padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:#111;color:#fff;font-size:14px}
    .logList{display:flex;flex-direction:column;gap:10px}
    .logItem{padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1)}
    .logText{font-size:15px;color:#fff;line-height:1.45;white-space:pre-wrap}
    .logMeta{margin-top:6px;font-size:12px;color:#cbd5e1;line-height:1.4}
    .emptyLog{font-size:14px;color:#cbd5e1}
    #video{position:fixed;inset:0;width:100vw;height:100vh;object-fit:cover;z-index:0;background:#000}
    #cap{display:none}
    .personMarker{background:transparent;border:0}
    .personMarkerInner{position:relative;width:20px;height:28px}
    .personMarkerInner::before{content:"";position:absolute;left:5px;top:0;width:10px;height:10px;border-radius:50%;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.9)}
    .personMarkerInner::after{content:"";position:absolute;left:3px;top:11px;width:14px;height:15px;border-radius:8px 8px 6px 6px;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.9)}
    .help{max-width:44ch;text-align:center;font-size:14px;line-height:1.45;color:#e2e8f0}
    @media (max-width: 920px){
      .wrap{align-items:stretch}
      .panel,.sidePanel{width:100%;max-width:520px}
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Stay On Trails</p>
    <nav aria-label="Main menu">
      <ul class="menu">
        <li><a href="index.php">Home</a></li>
        <li><a class="cta" href="#" aria-current="page">Laerbeekbos live</a></li>
      </ul>
    </nav>
  </div>
</header>

<main id="main-content">
  <div class="wrap">
    <div class="panel">
      <p class="help">Live mode uses the active webcam and real device GPS. Frames are sent once per second. CSV replay and JPG input are disabled on this page.</p>
      <div class="big" id="status">Idle</div>
      <div class="row">Sent: <span id="sent">0</span> frames <span id="kbps">0</span> kbps</div>
      <div class="row">Errors: <span id="errs">0</span></div>
      <div class="row">Latency: <span id="latency">--</span> ms</div>
      <div class="compassWrap">
        <canvas id="compass" width="140" height="140"></canvas>
        <div class="row">Heading: <span id="heading">--</span>&deg;</div>
      </div>
      <div class="controls">
        <button id="btn" class="navBtn">Start</button>
        <button id="switchCam" class="navBtn" disabled>Switch Camera</button>
      </div>
    </div>

    <div class="panel sidePanel">
      <h2 class="panelTitle">Map</h2>
      <div id="map" class="mapBox" aria-label="Live GPS location map"></div>
      <div class="mapMeta" id="gpsStatus">GPS: waiting for location...</div>
    </div>

    <div class="panel sidePanel">
      <h2 class="panelTitle">Live Info</h2>
      <div class="infoList">
        <div class="infoRow"><strong>Source:</strong> live camera</div>
        <div class="infoRow"><strong>Model:</strong> laerbeekbos</div>
        <div class="infoRow"><strong>Send interval:</strong> 1 second</div>
        <div class="infoRow"><strong>Latitude:</strong> <span id="latValue">--</span></div>
        <div class="infoRow"><strong>Longitude:</strong> <span id="lonValue">--</span></div>
        <div class="infoRow"><strong>GPS accuracy:</strong> <span id="accuracyValue">--</span> m</div>
      </div>
    </div>

    <div class="panel loggerPanel">
      <h2 class="panelTitle">Instruction Logger</h2>
      <textarea id="instructionInput" class="instructionInput" placeholder="Type an instruction to log with the current GPS position..."></textarea>
      <div class="loggerActions">
        <select id="speechLanguage" class="languageSelect" aria-label="Speech language">
          <option value="nl-BE" selected>Nederlands (Belgie)</option>
          <option value="en-GB">English (United Kingdom)</option>
        </select>
        <button id="addInstruction" class="navBtn">Save</button>
        <button id="clearInstructionLog" class="navBtn">Clear Log</button>
      </div>
      <div id="instructionLog" class="logList" aria-live="polite"></div>
    </div>
  </div>
</main>

<video id="video" autoplay playsinline muted></video>
<canvas id="cap"></canvas>

<script>
(() => {
  const SIGNALING_SERVER = <?php echo json_encode($wsUrl, JSON_UNESCAPED_SLASHES); ?>;
  const BEARER_TOKEN = <?php echo json_encode($bearerToken); ?>;
  const TARGET_W = 640;
  const TARGET_H = 480;
  const JPEG_QUALITY = 0.70;
  const FIXED_MODEL = "laerbeekbos";
  const SEND_INTERVAL_MS = 1000;
  const INSTRUCTION_SELECTION_RADIUS_METERS = 15;
  const INSTRUCTION_LOG_API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const INITIAL_INSTRUCTION_LOG = <?php echo json_encode($initialInstructionLog, JSON_UNESCAPED_SLASHES); ?>;

  const statusEl = document.getElementById("status");
  const sentEl = document.getElementById("sent");
  const kbpsEl = document.getElementById("kbps");
  const errsEl = document.getElementById("errs");
  const latencyEl = document.getElementById("latency");
  const headingEl = document.getElementById("heading");
  const btn = document.getElementById("btn");
  const switchCamBtn = document.getElementById("switchCam");
  const gpsStatusEl = document.getElementById("gpsStatus");
  const latValueEl = document.getElementById("latValue");
  const lonValueEl = document.getElementById("lonValue");
  const accuracyValueEl = document.getElementById("accuracyValue");
  const instructionInputEl = document.getElementById("instructionInput");
  const speechLanguageEl = document.getElementById("speechLanguage");
  const addInstructionBtn = document.getElementById("addInstruction");
  const clearInstructionLogBtn = document.getElementById("clearInstructionLog");
  const instructionLogEl = document.getElementById("instructionLog");

  const video = document.getElementById("video");
  const cap = document.getElementById("cap");
  const ctx = cap.getContext("2d", { alpha: false });
  const compass = document.getElementById("compass");
  const compCtx = compass.getContext("2d");

  let ws = null;
  let stream = null;
  let activeVideoDeviceId = null;
  let availableVideoInputs = [];
  let timer = null;
  let sentFrames = 0;
  let errors = 0;
  let nextFrameId = 1;
  let currentSessionId = null;
  let isAuthenticated = false;
  let authStarted = false;
  let latestLatitude = null;
  let latestLongitude = null;
  let latestAccuracy = null;
  let geoWatchId = null;
  let bytesSince = 0;
  let lastRateT = performance.now();
  let latestHeading = null;
  let instructionLog = Array.isArray(INITIAL_INSTRUCTION_LOG) ? INITIAL_INSTRUCTION_LOG : [];
  const sentAtByFrameId = new Map();

  let map = null;
  let mapMarker = null;
  let mapInitialized = false;
  let lastSpokenInstructionKey = null;
  let lastSpokenDirectionKey = null;

  function setStatus(text) {
    statusEl.textContent = text;
  }

  function incErr() {
    errors++;
    errsEl.textContent = String(errors);
  }

  function normalizeHeading(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return null;
    return ((n % 360) + 360) % 360;
  }

  function createSessionId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return `sess-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function speak(text) {
    const content = String(text || "").trim();
    if (!content || !("speechSynthesis" in window)) return;

    const utterance = new SpeechSynthesisUtterance(content);
    const selectedLanguage = speechLanguageEl?.value || "nl-BE";
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

    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(utterance);
  }

  if ("speechSynthesis" in window) {
    window.speechSynthesis.onvoiceschanged = () => {};
  }

  function drawArrow(headingDeg) {
    const w = compass.width;
    const h = compass.height;
    const cx = w / 2;
    const cy = h / 2;

    compCtx.clearRect(0, 0, w, h);
    compCtx.beginPath();
    compCtx.arc(cx, cy, 62, 0, Math.PI * 2);
    compCtx.strokeStyle = "rgba(255,255,255,0.35)";
    compCtx.lineWidth = 2;
    compCtx.stroke();

    compCtx.fillStyle = "rgba(255,255,255,0.7)";
    compCtx.font = "12px system-ui";
    compCtx.textAlign = "center";
    compCtx.fillText("Forward", cx, 14);

    if (typeof headingDeg !== "number" || Number.isNaN(headingDeg)) return;

    const angleRad = (-headingDeg * Math.PI) / 180;
    compCtx.save();
    compCtx.translate(cx, cy);
    compCtx.rotate(angleRad);
    compCtx.beginPath();
    compCtx.moveTo(-8, -3);
    compCtx.lineTo(40, -3);
    compCtx.lineTo(40, -10);
    compCtx.lineTo(56, 0);
    compCtx.lineTo(40, 10);
    compCtx.lineTo(40, 3);
    compCtx.lineTo(-8, 3);
    compCtx.closePath();
    compCtx.fillStyle = "#ff3b30";
    compCtx.fill();
    compCtx.restore();
  }

  function formatCoord(value) {
    return value === null || value === undefined || !Number.isFinite(Number(value))
      ? "--"
      : Number(value).toFixed(6);
  }

  function formatHeading(value) {
    return value === null || value === undefined || !Number.isFinite(Number(value))
      ? "--"
      : `${Number(value).toFixed(1)} deg`;
  }

  function toFiniteNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
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

  function findClosestInstructionEntry() {
    const currentLat = toFiniteNumber(latestLatitude);
    const currentLon = toFiniteNumber(latestLongitude);
    if (currentLat === null || currentLon === null || !instructionLog.length) {
      return null;
    }

    let closestEntry = null;
    let closestDistance = Number.POSITIVE_INFINITY;

    for (const entry of instructionLog) {
      const entryLat = toFiniteNumber(entry.latitude);
      const entryLon = toFiniteNumber(entry.longitude);
      if (entryLat === null || entryLon === null) {
        continue;
      }

      const distance = getDistanceMeters(currentLat, currentLon, entryLat, entryLon);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestEntry = {
          ...entry,
          distanceMeters: distance
        };
      }
    }

    if (!closestEntry || closestDistance > INSTRUCTION_SELECTION_RADIUS_METERS) {
      return null;
    }

    return closestEntry;
  }

  function syncInstructionInputWithClosestEntry() {
    const closestEntry = findClosestInstructionEntry();
    if (!closestEntry) {
      return;
    }

    if (document.activeElement !== instructionInputEl) {
      instructionInputEl.value = closestEntry.text || "";
    }
  }

  function updateSpeechForClosestEntry(force = false) {
    const closestEntry = findClosestInstructionEntry();
    if (!closestEntry) {
      lastSpokenInstructionKey = null;
      return;
    }

    const instructionKey = `${closestEntry.id || ""}|${closestEntry.text || ""}|${speechLanguageEl?.value || "nl-BE"}`;
    if (!force && instructionKey === lastSpokenInstructionKey) {
      return;
    }

    lastSpokenInstructionKey = instructionKey;
    speak(closestEntry.text);
  }

  function getDirectionLabelForHeading(heading) {
    const normalizedHeading = toFiniteNumber(heading);
    if (normalizedHeading === null) {
      return null;
    }

    const selectedLanguage = speechLanguageEl?.value || "nl-BE";
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

  function updateDirectionSpeech(force = false) {
    const directionLabel = getDirectionLabelForHeading(latestHeading);
    if (!directionLabel) {
      lastSpokenDirectionKey = null;
      return;
    }

    const directionKey = `${directionLabel}|${speechLanguageEl?.value || "nl-BE"}`;
    if (!force && directionKey === lastSpokenDirectionKey) {
      return;
    }

    lastSpokenDirectionKey = directionKey;
    speak(directionLabel);
  }

  function renderInstructionLog() {
    if (!instructionLogEl) return;

    const closestEntry = findClosestInstructionEntry();
    if (!closestEntry) {
      instructionLogEl.innerHTML = `<div class="emptyLog">No instruction found within ${INSTRUCTION_SELECTION_RADIUS_METERS} m.</div>`;
      return;
    }

    const gpsText = `${formatCoord(closestEntry.latitude)}, ${formatCoord(closestEntry.longitude)}`;
    const accuracyText = closestEntry.accuracy === null || closestEntry.accuracy === undefined || !Number.isFinite(Number(closestEntry.accuracy))
      ? "--"
      : `${Number(closestEntry.accuracy).toFixed(1)} m`;
    const distanceText = Number.isFinite(closestEntry.distanceMeters)
      ? `${closestEntry.distanceMeters.toFixed(1)} m away`
      : "--";

    instructionLogEl.innerHTML = `
      <div class="logItem">
        <div class="logText">${escapeHtml(closestEntry.text)}</div>
        <div class="logMeta">
          ${escapeHtml(closestEntry.timestamp)} | GPS ${escapeHtml(gpsText)} | Accuracy ${escapeHtml(accuracyText)} | Heading ${escapeHtml(formatHeading(closestEntry.heading))} | ${escapeHtml(distanceText)}
        </div>
      </div>
    `;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  async function saveInstructionLogEntry(entry) {
    const response = await fetch(`${INSTRUCTION_LOG_API_URL}?action=add_instruction_log`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(entry)
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {}

    if (!response.ok || !payload?.ok || !payload?.entry) {
      throw new Error(payload?.error || "Failed to save instruction log entry");
    }

    return payload.entry;
  }

  async function clearInstructionLogOnServer() {
    const response = await fetch(`${INSTRUCTION_LOG_API_URL}?action=clear_instruction_log`, {
      method: "POST"
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {}

    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.error || "Failed to clear instruction log");
    }
  }

  async function addInstructionLogEntry() {
    const text = instructionInputEl.value.trim();
    if (!text) {
      setStatus("Type an instruction first");
      instructionInputEl.focus();
      return;
    }

    const entry = {
      id: `log-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      text,
      timestamp: new Date().toISOString(),
      latitude: latestLatitude,
      longitude: latestLongitude,
      accuracy: latestAccuracy,
      heading: latestHeading,
      sessionId: currentSessionId
    };

    addInstructionBtn.disabled = true;
    try {
      const savedEntry = await saveInstructionLogEntry(entry);
      instructionLog.push(savedEntry);
      syncInstructionInputWithClosestEntry();
      renderInstructionLog();
      updateSpeechForClosestEntry(true);
      setStatus("Instruction logged");
    } catch (err) {
      console.error(err);
      setStatus("Failed to save instruction");
    } finally {
      addInstructionBtn.disabled = false;
    }
  }

  function updateRate(bytesJustSent) {
    bytesSince += bytesJustSent;
    const now = performance.now();
    const dt = now - lastRateT;
    if (dt >= 1000) {
      const kbitsPerSec = (bytesSince * 8) / dt;
      kbpsEl.textContent = kbitsPerSec.toFixed(1);
      bytesSince = 0;
      lastRateT = now;
    }
  }

  function ensureMap() {
    if (mapInitialized) return;

    map = L.map("map").setView([50.8503, 4.3517], 16);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "&copy; OpenStreetMap contributors"
    }).addTo(map);

    const personIcon = L.divIcon({
      className: "personMarker",
      html: '<div class="personMarkerInner" aria-hidden="true"></div>',
      iconSize: [20, 28],
      iconAnchor: [10, 24]
    });

    mapMarker = L.marker([50.8503, 4.3517], {
      icon: personIcon,
      title: "Current location"
    }).addTo(map);

    mapInitialized = true;
  }

  function updateMapPosition() {
    ensureMap();
    if (latestLatitude === null || latestLongitude === null) return;

    const latLng = [latestLatitude, latestLongitude];
    mapMarker.setLatLng(latLng);
    map.setView(latLng, 19);
  }

  function updateGpsUi() {
    if (latestLatitude === null || latestLongitude === null) {
      gpsStatusEl.textContent = "GPS: waiting for location...";
      latValueEl.textContent = "--";
      lonValueEl.textContent = "--";
      accuracyValueEl.textContent = "--";
      return;
    }

    latValueEl.textContent = latestLatitude.toFixed(6);
    lonValueEl.textContent = latestLongitude.toFixed(6);
    accuracyValueEl.textContent = latestAccuracy === null ? "--" : latestAccuracy.toFixed(1);
    gpsStatusEl.textContent = `GPS: ${latestLatitude.toFixed(6)}, ${latestLongitude.toFixed(6)}`;
    updateMapPosition();
  }

  function startLocationTracking() {
    if (!("geolocation" in navigator) || geoWatchId !== null) return;
    try {
      geoWatchId = navigator.geolocation.watchPosition(
        (pos) => {
          const lat = Number(pos?.coords?.latitude);
          const lon = Number(pos?.coords?.longitude);
          const accuracy = Number(pos?.coords?.accuracy);
          latestLatitude = Number.isFinite(lat) ? lat : null;
          latestLongitude = Number.isFinite(lon) ? lon : null;
          latestAccuracy = Number.isFinite(accuracy) ? accuracy : null;
          updateGpsUi();
          syncInstructionInputWithClosestEntry();
          renderInstructionLog();
          updateSpeechForClosestEntry();
        },
        (err) => {
          console.warn("Geolocation unavailable:", err);
          gpsStatusEl.textContent = "GPS: permission denied or unavailable";
        },
        { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 }
      );
    } catch (err) {
      console.warn("Failed to start geolocation:", err);
      gpsStatusEl.textContent = "GPS: failed to start";
    }
  }

  function stopLocationTracking() {
    if (!("geolocation" in navigator)) return;
    if (geoWatchId !== null) {
      try { navigator.geolocation.clearWatch(geoWatchId); } catch {}
      geoWatchId = null;
    }
    latestLatitude = null;
    latestLongitude = null;
    latestAccuracy = null;
    updateGpsUi();
  }

  async function refreshVideoInputs() {
    const devices = await navigator.mediaDevices.enumerateDevices();
    availableVideoInputs = devices.filter((device) => device.kind === "videoinput");
    switchCamBtn.disabled = availableVideoInputs.length < 2 || !stream;
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

    video.srcObject = stream;
    await video.play();

    const track = stream.getVideoTracks()[0];
    activeVideoDeviceId = track?.getSettings?.().deviceId ?? null;
    await refreshVideoInputs();
  }

  function sendAuthMessage() {
    if (!ws || ws.readyState !== WebSocket.OPEN || authStarted) return;
    authStarted = true;
    setStatus("Authenticating");
    ws.send(JSON.stringify({
      type: "auth",
      token: BEARER_TOKEN
    }));
  }

  function restartCaptureTimer() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (ws && ws.readyState === WebSocket.OPEN && isAuthenticated) {
      timer = setInterval(captureAndSend, SEND_INTERVAL_MS);
    }
  }

  function beginAuthenticatedStreaming() {
    if (isAuthenticated) return;
    isAuthenticated = true;
    setStatus("Streaming");
    restartCaptureTimer();
  }

  async function start() {
    btn.disabled = true;
    setStatus("Requesting camera");

    try {
      await startCamera(activeVideoDeviceId);
    } catch (e) {
      incErr();
      setStatus("Camera error");
      console.error(e);
      btn.disabled = false;
      return;
    }

    cap.width = TARGET_W;
    cap.height = TARGET_H;
    ensureMap();
    startLocationTracking();
    currentSessionId = createSessionId();
    isAuthenticated = false;
    authStarted = false;

    setStatus("Connecting WS");

    try {
      ws = new WebSocket(SIGNALING_SERVER);
      ws.binaryType = "arraybuffer";

      ws.onopen = () => {
        btn.disabled = false;
        sendAuthMessage();
      };

      ws.onerror = (e) => {
        incErr();
        console.error("WS error", e);
      };

      ws.onclose = (e) => {
        console.warn("WS closed", e.code, e.reason);
        setStatus("WS closed");
        stop(false);
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
            incErr();
            setStatus("Authentication failed");
            console.error("Authentication failed:", payload);
            stop(true);
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
            headingEl.textContent = normalized.toFixed(1);
            drawArrow(normalized);
            updateDirectionSpeech();
            setStatus("Result received");
          }

          const frameId = payload?.frame_id;
          if (frameId !== null && frameId !== undefined) {
            const id = String(frameId);
            const sentAt = sentAtByFrameId.get(id);
            if (typeof sentAt === "number") {
              const latencyMs = performance.now() - sentAt;
              latencyEl.textContent = latencyMs.toFixed(1);
              sentAtByFrameId.delete(id);
            }
          }
        } catch {
          return;
        }
      };
    } catch (e) {
      incErr();
      setStatus("WS connect failed");
      console.error(e);
      stop(true);
    }
  }

  function stop(allowButton = true) {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (ws) {
      try { ws.close(); } catch {}
      ws = null;
    }
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }

    switchCamBtn.disabled = true;
    sentAtByFrameId.clear();
    nextFrameId = 1;
    currentSessionId = null;
    isAuthenticated = false;
    authStarted = false;
    stopLocationTracking();
    headingEl.textContent = "--";
    latencyEl.textContent = "--";
    latestHeading = null;
    lastSpokenDirectionKey = null;
    drawArrow(null);

    if (allowButton) {
      btn.disabled = false;
      btn.textContent = "Start";
      setStatus("Idle");
    }
  }

  function captureAndSend() {
    if (!ws || ws.readyState !== WebSocket.OPEN || !isAuthenticated) return;
    if (!video.videoWidth || !video.videoHeight) return;

    ctx.drawImage(video, 0, 0, TARGET_W, TARGET_H);

    cap.toBlob(async (blob) => {
      if (!blob) return;

      try {
        const frameId = String(nextFrameId++);
        const buf = await blob.arrayBuffer();

        ws.send(JSON.stringify({
          type: "frame_meta",
          frame_id: frameId,
          sessionId: currentSessionId,
          latitude: latestLatitude,
          longitude: latestLongitude,
          gps_accuracy: latestAccuracy,
          lastlatency: latencyEl.textContent === "--" ? null : Number(latencyEl.textContent),
          model: FIXED_MODEL,
          source: "live_camera"
        }));

        sentAtByFrameId.set(frameId, performance.now());
        ws.send(buf);

        sentFrames++;
        sentEl.textContent = String(sentFrames);
        updateRate(buf.byteLength);

        if (sentAtByFrameId.size > 200) {
          const cutoff = performance.now() - 5000;
          for (const [id, time] of sentAtByFrameId) {
            if (time < cutoff) sentAtByFrameId.delete(id);
          }
        }
      } catch (e) {
        incErr();
        console.error(e);
      }
    }, "image/jpeg", JPEG_QUALITY);
  }

  btn.addEventListener("click", () => {
    if (timer || (ws && ws.readyState === WebSocket.OPEN)) {
      setStatus("Stopping");
      stop(true);
    } else {
      btn.textContent = "Stop";
      start();
    }
  });

  switchCamBtn.addEventListener("click", async () => {
    if (!stream) {
      setStatus("Start first");
      return;
    }

    try {
      await refreshVideoInputs();
      if (availableVideoInputs.length < 2) {
        setStatus("No extra camera found");
        return;
      }

      let idx = availableVideoInputs.findIndex((device) => device.deviceId === activeVideoDeviceId);
      if (idx < 0) idx = 0;
      const next = availableVideoInputs[(idx + 1) % availableVideoInputs.length];
      await startCamera(next.deviceId);

      if (ws && ws.readyState === WebSocket.OPEN) {
        setStatus(isAuthenticated ? "Streaming" : "Authenticating");
      }
    } catch (e) {
      incErr();
      console.error("Camera switch error", e);
      setStatus("Camera switch error");
    }
  });

  addInstructionBtn.addEventListener("click", async () => {
    await addInstructionLogEntry();
  });

  clearInstructionLogBtn.addEventListener("click", async () => {
    clearInstructionLogBtn.disabled = true;
    try {
      await clearInstructionLogOnServer();
      instructionLog = [];
      instructionInputEl.value = "";
      renderInstructionLog();
      setStatus("Instruction log cleared");
    } catch (err) {
      console.error(err);
      setStatus("Failed to clear instruction log");
    } finally {
      clearInstructionLogBtn.disabled = false;
    }
  });

  instructionInputEl.addEventListener("keydown", async (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
      event.preventDefault();
      await addInstructionLogEntry();
    }
  });

  speechLanguageEl.addEventListener("change", () => {
    updateDirectionSpeech(true);
    updateSpeechForClosestEntry(true);
  });

  drawArrow(null);
  updateGpsUi();
  syncInstructionInputWithClosestEntry();
  renderInstructionLog();
  updateSpeechForClosestEntry();
})();
</script>
</body>
</html>
