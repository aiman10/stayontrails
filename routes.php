<?php
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'saved_paths';

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
            'language' => is_array($decoded) && isset($decoded['language']) ? (string)$decoded['language'] : 'nl-BE',
            'routeLengthMeters' => is_array($decoded) && isset($decoded['routeLengthMeters']) && is_numeric($decoded['routeLengthMeters'])
                ? (float)$decoded['routeLengthMeters']
                : null,
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
  <title>Stay On Trails - Available Routes</title>
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
    a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
    .topbar{position:sticky;top:0;z-index:20;background:var(--menu-bg);border-bottom:1px solid var(--menu-border);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1440px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.1)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    .layout{max-width:1440px;margin:0 auto;padding:18px;display:grid;grid-template-columns:minmax(320px,.9fr) minmax(0,1.5fr);gap:18px}
    .panel{background:rgba(15,23,42,.84);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.26)}
    .sidePanel{padding:16px;display:flex;flex-direction:column;gap:14px}
    .mapPanel{padding:14px}
    .card{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .sectionTitle{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
    .status{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:14px;color:var(--muted)}
    .status.warn{color:#ffe4e6;border-color:rgba(251,113,133,.35);background:rgba(244,63,94,.08)}
    .list{display:flex;flex-direction:column;gap:10px;max-height:72vh;overflow:auto;padding-right:4px}
    .routeItem{padding:12px;border-radius:14px;border:1px solid var(--line);background:#0b1220;cursor:pointer}
    .routeItem.active{border-color:rgba(34,211,238,.55);background:#0f1a2c}
    .routeTitle{font-size:15px;font-weight:700;margin-bottom:6px}
    .routeMeta,.routeInfo{font-size:13px;line-height:1.45;color:var(--muted)}
    .routeActions{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap}
    .mapToolbar{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
    .btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:#1e293b;color:#fff;text-decoration:none;font-weight:700}
    .btn:hover{background:#334155}
    .btnPrimary{background:var(--accent);color:var(--accent-ink);border-color:rgba(250,204,21,.35)}
    .btnPrimary:hover{background:#fde047}
    #map{height:78vh;min-height:520px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line)}
    .mapInfo{margin-top:12px;display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
    .stat{padding:12px;border-radius:14px;background:#0b1220;border:1px solid var(--line)}
    .statLabel{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px}
    .statValue{font-size:18px;font-weight:700}
    .bigTitle{font-size:28px;line-height:1.2;font-weight:700;margin-top:10px}
    .muted{color:var(--muted);font-size:14px;line-height:1.5}
    @media (max-width: 1100px){
      .layout{grid-template-columns:1fr}
      #map{height:54vh;min-height:380px}
    }
    @media (max-width: 720px){
      .layout{padding:12px}
      .mapInfo{grid-template-columns:1fr 1fr}
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
        <li><a class="cta" href="#" aria-current="page">Available routes</a></li>
        <li><a href="makepath.php">Route builder</a></li>
        <li><a href="followpath.php">Start route</a></li>
        <li><a href="remoteHelp.php">Remote assistant</a></li>
      </ul>
    </nav>
  </div>
</header>

<main class="layout">
  <aside class="panel sidePanel">
    <div id="status" class="status">Loading available routes...</div>

    <div class="card">
      <div class="sectionTitle">Available Routes</div>
      <div class="routeActions" style="margin-top:12px">
        <a class="btn btnPrimary" href="makepath.php?new=1">Create new route</a>
        <a class="btn" href="recordroute.php">Record new route</a>
      </div>
      <div id="routeList" class="list" style="margin-top:12px"></div>
    </div>
  </aside>

  <section class="panel mapPanel">
    <div class="card">
      <div class="sectionTitle">Route Preview</div>
      <div id="routeTitle" class="bigTitle">No route selected.</div>
      <div id="routeDescription" class="muted" style="margin-top:10px">Choose a route from the list to preview it on the map.</div>
      <div class="routeActions">
        <a id="openFollowLink" class="btn btnPrimary" href="followpath.php">Start route</a>
        <a id="openBuilderLink" class="btn" href="makepath.php">Edit route</a>
      </div>
    </div>

    <div class="mapToolbar">
      <button id="satelliteToggleBtn" class="btn" type="button">Show Satellite</button>
    </div>

    <div id="map" style="margin-top:14px" aria-label="Route preview map"></div>

    <div class="mapInfo">
      <div class="stat">
        <div class="statLabel">Points</div>
        <div id="pointCountValue" class="statValue">--</div>
      </div>
      <div class="stat">
        <div class="statLabel">Length</div>
        <div id="routeLengthValue" class="statValue">--</div>
      </div>
      <div class="stat">
        <div class="statLabel">Language</div>
        <div id="languageValue" class="statValue">--</div>
      </div>
      <div class="stat">
        <div class="statLabel">Heading FPS</div>
        <div id="fpsValue" class="statValue">--</div>
      </div>
      <div class="stat">
        <div class="statLabel">Updated</div>
        <div id="updatedValue" class="statValue">--</div>
      </div>
    </div>
  </section>
</main>

<script>
  const API_URL = <?php echo json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES); ?>;
  const DEFAULT_CENTER = [50.8503, 4.3517];
  const LAST_SELECTED_PATH_STORAGE_KEY = "stayontrails.lastSelectedPathSlug";

  const statusEl = document.getElementById("status");
  const routeListEl = document.getElementById("routeList");
  const routeTitleEl = document.getElementById("routeTitle");
  const routeDescriptionEl = document.getElementById("routeDescription");
  const pointCountValueEl = document.getElementById("pointCountValue");
  const routeLengthValueEl = document.getElementById("routeLengthValue");
  const languageValueEl = document.getElementById("languageValue");
  const fpsValueEl = document.getElementById("fpsValue");
  const updatedValueEl = document.getElementById("updatedValue");
  const openFollowLinkEl = document.getElementById("openFollowLink");
  const openBuilderLinkEl = document.getElementById("openBuilderLink");
  const satelliteToggleBtn = document.getElementById("satelliteToggleBtn");

  const map = L.map("map").setView(DEFAULT_CENTER, 16);
  const streetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(map);
  const satelliteLayer = L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}", {
    attribution: "Tiles &copy; Esri"
  });

  let currentSlug = "";
  let routeLine = null;
  let waypointMarkers = [];
  let availableRoutes = [];
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

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function formatLanguage(value) {
    return value === "en-GB" ? "English (UK)" : "Nederlands (Belgie)";
  }

  function formatRouteLength(value) {
    const meters = Number(value);
    if (!Number.isFinite(meters)) {
      return "--";
    }
    if (meters >= 1000) {
      return `${(meters / 1000).toFixed(2)} km`;
    }
    return `${meters.toFixed(1)} m`;
  }

  function renderRouteList() {
    if (!availableRoutes.length) {
      routeListEl.innerHTML = `<div class="routeItem"><div class="routeInfo">No saved routes found.</div></div>`;
      return;
    }

    routeListEl.innerHTML = availableRoutes.map((route) => `
      <div class="routeItem${route.slug === currentSlug ? " active" : ""}" data-route-slug="${escapeHtml(route.slug)}">
        <div class="routeTitle">${escapeHtml(route.name)}</div>
        <div class="routeMeta">${escapeHtml(route.pointCount)} points | ${escapeHtml(formatRouteLength(route.routeLengthMeters))}</div>
        <div class="routeInfo">${escapeHtml(formatLanguage(route.language))}</div>
        <div class="routeInfo">${escapeHtml(route.updatedAt)}</div>
      </div>
    `).join("");

    routeListEl.querySelectorAll("[data-route-slug]").forEach((node) => {
      node.addEventListener("click", () => {
        const slug = node.getAttribute("data-route-slug");
        if (slug) {
          loadRoute(slug).catch(() => setStatus("Failed to load route preview.", "warn"));
        }
      });
    });
  }

  function clearMapPreview() {
    if (routeLine) {
      map.removeLayer(routeLine);
      routeLine = null;
    }
    waypointMarkers.forEach((marker) => map.removeLayer(marker));
    waypointMarkers = [];
  }

  function updateLinks(slug = "") {
    const suffix = slug ? `?slug=${encodeURIComponent(slug)}` : "";
    openFollowLinkEl.href = `followpath.php${suffix}`;
    openBuilderLinkEl.href = `makepath.php${suffix}`;
  }

  async function loadRoutes() {
    const response = await fetch(`${API_URL}?action=list_paths`);
    const payload = await response.json();
    if (!payload?.ok) {
      setStatus(payload?.error || "Failed to load available routes.", "warn");
      return;
    }

    availableRoutes = Array.isArray(payload.paths) ? payload.paths : [];
    renderRouteList();

    if (!availableRoutes.length) {
      setStatus("No saved routes are available yet.");
      updateLinks("");
      return;
    }

    const preferredSlug = getLastSelectedPathSlug();
    const initialSlug = availableRoutes.some((route) => route.slug === preferredSlug)
      ? preferredSlug
      : availableRoutes[0].slug;
    await loadRoute(initialSlug);
  }

  async function loadRoute(slug) {
    const response = await fetch(`${API_URL}?action=load_path&slug=${encodeURIComponent(slug)}`);
    const payload = await response.json();
    if (!payload?.ok || !payload.path) {
      setStatus(payload?.error || "Failed to load route preview.", "warn");
      return;
    }

    currentSlug = slug;
    setLastSelectedPathSlug(slug);
    renderRouteList();
    updateLinks(slug);

    const path = payload.path;
    const points = Array.isArray(path.points) ? path.points.map((point) => ({
      lat: Number(point.lat),
      lng: Number(point.lng ?? point.lon),
      instruction: String(point.instruction || "").trim()
    })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng)) : [];

    const routeTitle = String(path.name || slug).trim() || slug;
    routeTitleEl.textContent = routeTitle;
    routeDescriptionEl.textContent = points.length
      ? "This route contains saved navigation instructions."
      : "This route does not contain valid points.";
    pointCountValueEl.textContent = String(points.length);
    routeLengthValueEl.textContent = formatRouteLength(path.routeLengthMeters);
    languageValueEl.textContent = formatLanguage(path.language || "nl-BE");
    fpsValueEl.textContent = Number.isFinite(Number(path.headingFeedbackFps))
      ? `${Number(path.headingFeedbackFps).toFixed(1)}`
      : "1.0";
    updatedValueEl.textContent = String(path.updatedAt || "--");

    clearMapPreview();
    if (points.length >= 2) {
      routeLine = L.polyline(points.map((point) => [point.lat, point.lng]), {
        color: "#22d3ee",
        weight: 4,
        opacity: 0.9
      }).addTo(map);
    }

    points.forEach((point, index) => {
      const marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
          className: "",
          html: `<div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#facc15;color:#111827;font-weight:700;border:2px solid rgba(15,23,42,.92)">${index + 1}</div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 14]
        }),
        title: point.instruction || `Point ${index + 1}`
      }).addTo(map);
      waypointMarkers.push(marker);
    });

    if (points.length) {
      map.fitBounds(points.map((point) => [point.lat, point.lng]), { padding: [40, 40] });
      setStatus(`Showing ${path.name || slug}.`);
    } else {
      map.setView(DEFAULT_CENTER, 16);
      setStatus("This route does not contain valid points.", "warn");
    }
  }

  updateLinks("");
  satelliteToggleBtn.addEventListener("click", () => {
    satelliteVisible = !satelliteVisible;
    updateBaseLayer();
  });
  updateBaseLayer();
  loadRoutes().catch(() => setStatus("Unable to load available routes.", "warn"));
</script>
</body>
</html>
