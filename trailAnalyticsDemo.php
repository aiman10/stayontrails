<?php
$bearerToken = 'LTddk_ptxQX-omdw5B5rfpniA2wB-19KBxFaKuODMzw';
$wsUrl = 'wss://signaling.ehb.be/ws/pathnavigation';

$baseDir = __DIR__;
$relativeFolder = 'img/laerbeekbos';
$folderPath = realpath($baseDir . DIRECTORY_SEPARATOR . $relativeFolder);
$indexParam = isset($_GET['index']) ? (int)$_GET['index'] : 0;

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getCsvColumnIndex(array $header, array $candidates): ?int {
    foreach ($candidates as $candidate) {
        $index = array_search($candidate, $header, true);
        if ($index !== false) {
            return (int)$index;
        }
    }

    return null;
}

function gpsToDecimal(array $coord, string $hemisphere): ?float {
    if (count($coord) !== 3) {
        return null;
    }

    $degrees = explode('/', $coord[0]);
    $minutes = explode('/', $coord[1]);
    $seconds = explode('/', $coord[2]);

    if (count($degrees) !== 2 || count($minutes) !== 2 || count($seconds) !== 2) {
        return null;
    }

    $deg = $degrees[0] / max(1, $degrees[1]);
    $min = $minutes[0] / max(1, $minutes[1]);
    $sec = $seconds[0] / max(1, $seconds[1]);

    $decimal = $deg + ($min / 60.0) + ($sec / 3600.0);
    if (in_array($hemisphere, ['S', 'W'], true)) {
        $decimal = -$decimal;
    }

    return $decimal;
}

function getImageGps(string $imagePath): ?array {
    if (!function_exists('exif_read_data')) {
        return null;
    }

    $exif = @exif_read_data($imagePath, 'GPS', true);
    if (!$exif || empty($exif['GPS'])) {
        return null;
    }

    $gps = $exif['GPS'];
    if (empty($gps['GPSLatitude']) || empty($gps['GPSLongitude']) || empty($gps['GPSLatitudeRef']) || empty($gps['GPSLongitudeRef'])) {
        return null;
    }

    $lat = gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
    $lon = gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);

    if ($lat === null || $lon === null) {
        return null;
    }

    return ['lat' => $lat, 'lon' => $lon];
}

$images = [];
$errorMessage = null;

if (!$folderPath || !is_dir($folderPath)) {
    $errorMessage = 'Folder img/laerbeekbos not found.';
} else {
    $files = glob($folderPath . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
    natsort($files);
    $files = array_values($files);

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $images[] = [
            'filename' => $filename,
            'image_url' => $relativeFolder . '/' . rawurlencode($filename)
        ];
    }
}

$total = count($images);
$index = $total > 0 ? max(0, min($total - 1, $indexParam)) : 0;
$current = $images[$index] ?? null;
$currentGps = null;
if ($current) {
    $currentGps = getImageGps($folderPath . DIRECTORY_SEPARATOR . $current['filename']);
}
$prevIndex = $index > 0 ? $index - 1 : null;
$prevGps = null;
if ($prevIndex !== null) {
    $prevImage = $images[$prevIndex];
    $prevGps = getImageGps($folderPath . DIRECTORY_SEPARATOR . $prevImage['filename']);
}
$direction = null;
if ($prevGps && $currentGps) {
    $lat1 = deg2rad($prevGps['lat']);
    $lon1 = deg2rad($prevGps['lon']);
    $lat2 = deg2rad($currentGps['lat']);
    $lon2 = deg2rad($currentGps['lon']);

    $dLon = $lon2 - $lon1;

    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) -
         sin($lat1) * cos($lat2) * cos($dLon);

    $bearing = rad2deg(atan2($y, $x));
    $direction = fmod(($bearing + 360), 360);
}
$nextIndex = ($index < $total - 1) ? $index + 1 : null;

// Read instructions from CSV
$currentInstructions = '';
$trackPoints = [];
$csvFile = $folderPath . DIRECTORY_SEPARATOR . 'inference_log.csv';
if (file_exists($csvFile)) {
    if (($handle = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($handle, 1000, ',');
        $filenameIndex = is_array($header) ? getCsvColumnIndex($header, ['Filename', 'filename']) : null;
        $datetimeIndex = is_array($header) ? getCsvColumnIndex($header, ['datetime', 'Datetime']) : null;
        $frameIdIndex = is_array($header) ? getCsvColumnIndex($header, ['frameID', 'frame_id', 'FrameID']) : null;
        $longitudeIndex = is_array($header) ? getCsvColumnIndex($header, ['longitude', 'lon', 'Longitude']) : null;
        $latitudeIndex = is_array($header) ? getCsvColumnIndex($header, ['latitude', 'lat', 'Latitude']) : null;
        $headingIndex = is_array($header) ? getCsvColumnIndex($header, ['heading', 'Heading']) : null;
        $modelPathIndex = is_array($header) ? getCsvColumnIndex($header, ['MODEL_PATH', 'model_path']) : null;
        $latencyIndex = is_array($header) ? getCsvColumnIndex($header, ['lastlatency', 'latency']) : null;
        $instructionsIndex = is_array($header) ? getCsvColumnIndex($header, ['instructions', 'Instructions']) : null;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if ($filenameIndex !== null && isset($data[$filenameIndex]) && $data[$filenameIndex] === $current['filename']) {
                if ($instructionsIndex !== null) {
                    $currentInstructions = $data[$instructionsIndex] ?? '';
                } else {
                    $currentInstructions = $data[8] ?? '';
                }
                break;
            }
        }
        rewind($handle);
        fgetcsv($handle, 1000, ',');
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (
                $latitudeIndex === null || $longitudeIndex === null ||
                !isset($data[$latitudeIndex], $data[$longitudeIndex]) ||
                !is_numeric($data[$latitudeIndex]) || !is_numeric($data[$longitudeIndex])
            ) {
                continue;
            }

            $trackPoints[] = [
                'lat' => (float)$data[$latitudeIndex],
                'lon' => (float)$data[$longitudeIndex],
                'filename' => ($filenameIndex !== null && isset($data[$filenameIndex])) ? $data[$filenameIndex] : '',
                'frame_id' => ($frameIdIndex !== null && isset($data[$frameIdIndex])) ? $data[$frameIdIndex] : '',
                'heading' => ($headingIndex !== null && isset($data[$headingIndex])) ? $data[$headingIndex] : '',
                'model_path' => ($modelPathIndex !== null && isset($data[$modelPathIndex])) ? $data[$modelPathIndex] : '',
                'lastlatency' => ($latencyIndex !== null && isset($data[$latencyIndex])) ? $data[$latencyIndex] : '',
                'datetime' => ($datetimeIndex !== null && isset($data[$datetimeIndex])) ? $data[$datetimeIndex] : '',
            ];
        }
        fclose($handle);
    }
}

// Handle form submission to update instructions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instructions'])) {
    $newInstructions = trim($_POST['instructions']);
    if (file_exists($csvFile)) {
        $rows = [];
        if (($handle = fopen($csvFile, 'r')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if ($data[0] === $current['filename']) {
                    $data[8] = $newInstructions; // Update instructions column
                }
                $rows[] = $data;
            }
            fclose($handle);
        }
        // Write back to CSV
        if (($handle = fopen($csvFile, 'w')) !== false) {
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }
        $currentInstructions = $newInstructions;
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
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
    .panel{background:rgba(0,0,0,.25);padding:14px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.15);display:flex;flex-direction:column;align-items:center;gap:10px;max-width:520px;width:min(100%,520px)}
    .sidePanel{width:min(100%,360px);align-items:stretch}
    .mapBox{position:relative;width:100%;height:210px;background:#000;border:1px solid rgba(255,255,255,.2);border-radius:10px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .personMarker{background:transparent;border:0}
    .personMarkerInner{position:relative;width:20px;height:28px}
    .personMarkerInner::before{content:"";position:absolute;left:5px;top:0;width:10px;height:10px;border-radius:50%;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.9)}
    .personMarkerInner::after{content:"";position:absolute;left:3px;top:11px;width:14px;height:15px;border-radius:8px 8px 6px 6px;background:#facc15;box-shadow:0 0 0 2px rgba(17,24,39,.9)}
    .big{font-size:28px;font-weight:700}
    .row{opacity:.9}
    .controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:center}
    .compassWrap{display:flex;flex-direction:column;align-items:center;gap:6px}
    #compass{width:140px;height:140px;display:block}
    .navBtn{
      padding:10px 14px;
      border-radius:10px;
      border:0;
      background:#222;
      color:#fff;
      font-size:14px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:100px;
      cursor:pointer
    }
    .navBtn:hover{background:#333}
    .navBtn.disabled{opacity:.45;pointer-events:none}
    .imageMeta{font-size:14px;color:#e2e8f0;text-align:center;line-height:1.4}
    .infoPanel{width:min(100%,320px);align-items:stretch}
    .infoList{display:flex;flex-direction:column;gap:8px}
    .infoRow{font-size:14px;color:#e2e8f0;line-height:1.45}
    .infoRow strong{color:#fff}
    .panelTitle{margin:0;font-size:18px;font-weight:700}
    .mapMeta{font-size:14px;color:#e2e8f0;text-align:center;line-height:1.4}
    .subtitleForm{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:3;width:min(92vw,900px);display:flex;flex-direction:column;align-items:center;gap:8px}
    .subtitleInput{width:100%;min-height:64px;padding:10px 14px;font-size:24px;line-height:1.35;text-align:center;background:rgba(0,0,0,0.58);color:#fff;border:0;border-radius:10px;resize:none;box-sizing:border-box;text-shadow:0 2px 4px rgba(0,0,0,.85)}
    .subtitleInput::placeholder{color:rgba(255,255,255,.72)}
    .saveBtn{padding:8px 16px;background:#facc15;color:#111827;border:none;border-radius:4px;cursor:pointer;font-size:16px}
    #viewer{position:fixed;inset:0;width:100vw;height:100vh;object-fit:cover;z-index:0;background:#000}
    #cap{display:none}
    .help{max-width:44ch;text-align:center;font-size:14px;line-height:1.45;color:#e2e8f0}
    .badge{display:inline-block;background:rgba(255,255,255,.12);padding:6px 10px;border-radius:999px;font-size:13px}
    .error{color:#fecaca;font-weight:700}
    @media (max-width: 920px){
      .wrap{align-items:stretch}
      .panel,.sidePanel{width:100%;max-width:520px}
    }
    @media (max-width: 640px){
      .subtitleForm{bottom:16px;width:min(94vw,900px)}
      .subtitleInput{font-size:18px;min-height:56px}
    }
  </style>
</head>
<body>
  <script id="r9sz5v">
function speak(text) {
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = "nl-BE";
  utterance.rate = 1.0;

  const voices = speechSynthesis.getVoices();
  const dutchVoice = voices.find(v => v.lang === "nl-BE" || v.lang.startsWith("nl"));
  if (dutchVoice) {
    utterance.voice = dutchVoice;
  }

  speechSynthesis.cancel();
  speechSynthesis.speak(utterance);
}

window.speechSynthesis.onvoiceschanged = () => {
  console.log("Voices loaded");
};
</script>

// automatisch bij laden (let op: sommige browsers blokkeren dit zonder user interaction)
//window.addEventListener('load', () => {
//  speak("Welkom bij Stay on Trails");
//});
</script>

<a href="#main-content" class="skip-link">Skip to main content</a>

<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Unstructured Path Demo</p>
    <nav aria-label="Main menu">
      <ul class="menu">
        <li><a href="index.php">Home</a></li>
      </ul>
    </nav>
  </div>
</header>

<main id="main-content">
  <div class="wrap">
    <div class="panel">
      <?php if ($errorMessage): ?>
        <div class="error"><?= h($errorMessage) ?></div>
      <?php elseif (!$current): ?>
        <div class="error">No JPG images found in img/laerbeekbos.</div>
      <?php else: ?>
        <div class="big" id="status">Loading</div>
        <div class="compassWrap">
          <canvas id="compass" width="140" height="140"></canvas>
        </div>
        <div class="controls">
          <?php if ($prevIndex !== null): ?>
            <a class="navBtn" href="?index=<?= $prevIndex ?>">&larr; Previous</a>
          <?php else: ?>
            <span class="navBtn disabled">&larr; Previous</span>
          <?php endif; ?>

          <?php if ($nextIndex !== null): ?>
            <a class="navBtn" href="?index=<?= $nextIndex ?>">Next &rarr;</a>
          <?php else: ?>
            <span class="navBtn disabled">Next &rarr;</span>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    </div>
    <?php if (!$errorMessage && $current): ?>
      <div class="panel sidePanel">
        <h2 class="panelTitle">Map</h2>
        <?php if ($currentGps): ?>
          <div id="map" class="mapBox" aria-label="Image GPS location map"></div>
          <div class="mapMeta">
            GPS: <?= number_format($currentGps['lat'], 6) ?>, <?= number_format($currentGps['lon'], 6) ?>
          </div>
          <?php if ($direction !== null): ?>
            <div class="mapMeta">Moving direction: <?= number_format($direction, 1) ?>&deg;</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="mapMeta">No GPS metadata available for this image.</div>
        <?php endif; ?>
      </div>
      <div class="panel infoPanel">
        <h2 class="panelTitle">Image Info</h2>
        <div class="infoList">
          <div class="infoRow"><strong>Latency:</strong> <span id="latency">--</span> ms</div>
          <div class="infoRow"><strong>Heading:</strong> <span id="heading">--</span>&deg;</div>
          <div class="infoRow"><strong>Image:</strong> <?= $index + 1 ?> / <?= $total ?></div>
          <div class="infoRow"><strong>Filename:</strong> <?= h($current['filename']) ?></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php if ($current): ?>



  <script language="javascript"> speak('<?= h($currentInstructions) ?>')</script>

  <img id="viewer" src="<?= h($current['image_url']) ?>" alt="<?= h($current['filename']) ?>">
  <canvas id="cap"></canvas>
  <form method="post" class="subtitleForm">
    <textarea id="instructions" name="instructions" class="subtitleInput" placeholder="Type instructions here..."><?= h($currentInstructions) ?></textarea>
    <button type="submit" class="saveBtn">Save Instructions</button>
  </form>
<?php endif; ?>

<script>
(() => {
  const SIGNALING_SERVER = <?php echo json_encode($wsUrl, JSON_UNESCAPED_SLASHES); ?>;
  const BEARER_TOKEN = <?php echo json_encode($bearerToken); ?>;
  const CURRENT_IMAGE = <?php echo json_encode($current, JSON_UNESCAPED_SLASHES); ?>;
  const CURRENT_GPS = <?php echo json_encode($currentGps); ?>;
  const PREV_GPS = <?php echo json_encode($prevGps); ?>;
  const CURRENT_DIRECTION = <?php echo json_encode($direction); ?>;

  if (!CURRENT_IMAGE) return;

  const TARGET_W = 640;
  const TARGET_H = 480;
  const JPEG_QUALITY = 0.70;
  const FIXED_MODEL = "laerbeekbos";

  const statusEl = document.getElementById("status");
  const sentEl = document.getElementById("sent");
  const kbpsEl = document.getElementById("kbps");
  const errsEl = document.getElementById("errs");
  const latencyEl = document.getElementById("latency");
  const headingEl = document.getElementById("heading");

  const viewer = document.getElementById("viewer");
  const cap = document.getElementById("cap");
  const ctx = cap.getContext("2d", { alpha: false });
  const compass = document.getElementById("compass");
  const compCtx = compass.getContext("2d");

  let ws = null;
  let sentFrames = 0;
  let errors = 0;
  let latestHeading = null;
  let nextFrameId = 1;
  const sentAtByFrameId = new Map();
  let currentSessionId = null;
  let isAuthenticated = false;
  let authStarted = false;

  let bytesSince = 0;
  let lastRateT = performance.now();

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

  function setStatus(s) {
    statusEl.textContent = s;
  }

  function incErr() {
    errors++;
    errsEl.textContent = String(errors);
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

  function sendAuthMessage() {
    if (!ws || ws.readyState !== WebSocket.OPEN || authStarted) return;

    authStarted = true;
    setStatus("Authenticating");

    ws.send(JSON.stringify({
      type: "auth",
      token: BEARER_TOKEN
    }));
  }

  function beginAuthenticatedSession() {
    if (isAuthenticated) return;
    isAuthenticated = true;
    setStatus("Connected");
    analyzeCurrentImage();
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

  async function ensureImageLoaded(img) {
    if (img.complete && img.naturalWidth > 0) return;
    await new Promise((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = reject;
    });
  }

  async function analyzeCurrentImage() {
    if (!ws || ws.readyState !== WebSocket.OPEN || !isAuthenticated) return;

    setStatus("Preparing image");

    try {
      await ensureImageLoaded(viewer);

      cap.width = TARGET_W;
      cap.height = TARGET_H;
      ctx.drawImage(viewer, 0, 0, TARGET_W, TARGET_H);

      const blob = await new Promise((resolve) => {
        cap.toBlob(resolve, "image/jpeg", JPEG_QUALITY);
      });

      if (!blob) {
        throw new Error("Failed to encode image");
      }

      const frameId = String(nextFrameId++);
      const buf = await blob.arrayBuffer();

      ws.send(JSON.stringify({
        type: "frame_meta",
        frame_id: frameId,
        sessionId: currentSessionId,
        filename: CURRENT_IMAGE.filename,
        model: FIXED_MODEL,
        source: "folder"
      }));

      sentAtByFrameId.set(frameId, performance.now());
      ws.send(buf);

      sentFrames++;
      sentEl.textContent = String(sentFrames);
      updateRate(buf.byteLength);
      setStatus("Image sent");
    } catch (e) {
      incErr();
      setStatus("Image send failed");
      console.error(e);
    }
  }

  function cleanupConnection() {
    if (ws) {
      try { ws.close(); } catch {}
      ws = null;
    }
    sentAtByFrameId.clear();
    nextFrameId = 1;
    currentSessionId = null;
    isAuthenticated = false;
    authStarted = false;
  }

  function start() {
    setStatus("Connecting WS");

    currentSessionId = createSessionId();
    isAuthenticated = false;
    authStarted = false;

    try {
      ws = new WebSocket(SIGNALING_SERVER);
      ws.binaryType = "arraybuffer";

      ws.onopen = () => {
        sendAuthMessage();
      };

      ws.onerror = (e) => {
        incErr();
        console.error("WS error", e);
        setStatus("WS error");
      };

      ws.onclose = (e) => {
        console.warn("WS closed", e.code, e.reason);
        setStatus("WS closed");
        cleanupConnection();
      };

      ws.onmessage = (msg) => {
        if (typeof msg.data !== "string") return;

        let payload;
        try {
          payload = JSON.parse(msg.data);
        } catch {
          return;
        }

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
          beginAuthenticatedSession();
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
          cleanupConnection();
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

          <?php if ($currentInstructions == null): ?>
            if (latestHeading >= 100){
              speak("links");
            }
            if (latestHeading <= 80){
              speak("rechts");
            }
            if (latestHeading < 100 && latestHeading > 80){
              speak("rechtdoor");
            }
            
          <?php endif; ?>

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
      };
    } catch (e) {
      incErr();
      setStatus("WS connect failed");
      console.error(e);
      cleanupConnection();
    }
  }

  drawArrow(null);
  window.addEventListener("load", start, { once: true });
})();
</script>
<?php if ($currentGps): ?>
  <script>
(() => {
  const CURRENT_GPS = <?php echo json_encode($currentGps); ?>;
  const TRACK_POINTS = <?php echo json_encode($trackPoints, JSON_UNESCAPED_SLASHES); ?>;
  if (!CURRENT_GPS) return;

  const map = L.map('map').setView([CURRENT_GPS.lat, CURRENT_GPS.lon], 19);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
  }).addTo(map);

  const track = Array.isArray(TRACK_POINTS)
    ? TRACK_POINTS
        .filter((point) => Number.isFinite(Number(point?.lat)) && Number.isFinite(Number(point?.lon)))
        .map((point) => [Number(point.lat), Number(point.lon)])
    : [];

  if (track.length > 1) {
    L.polyline(track, { color: '#0066ff', weight: 3, opacity: 0.9 }).addTo(map);
    map.fitBounds(track, { padding: [18, 18] });
  }

  const personIcon = L.divIcon({
    className: 'personMarker',
    html: '<div class="personMarkerInner" aria-hidden="true"></div>',
    iconSize: [20, 28],
    iconAnchor: [10, 24]
  });

  L.marker([CURRENT_GPS.lat, CURRENT_GPS.lon], {
    icon: personIcon,
    title: 'Current image location'
  }).addTo(map);

  // Add link below the map
  const linkDiv = document.createElement('div');
  linkDiv.style.position = 'absolute';
  linkDiv.style.bottom = '0';
  linkDiv.style.left = '0';
  linkDiv.style.right = '0';
  linkDiv.style.padding = '4px 6px';
  linkDiv.style.background = 'rgba(0,0,0,0.55)';
  linkDiv.style.fontSize = '12px';
  linkDiv.style.textAlign = 'center';
  linkDiv.innerHTML = '<a style="color:#fff;text-decoration:underline;" href="https://www.openstreetmap.org/?mlat=' + CURRENT_GPS.lat + '&mlon=' + CURRENT_GPS.lon + '#map=19/' + CURRENT_GPS.lat + '/' + CURRENT_GPS.lon + '" target="_blank" rel="noopener">Open in OpenStreetMap</a>';
  document.getElementById('map').appendChild(linkDiv);
})();
  </script>
<?php endif; ?>
</body>
</html>
