<?php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails</title>
  <style>
    :root{
      --bg:#08111f;
      --panel:#132238;
      --panel-2:#0f1b2d;
      --text:#f8fafc;
      --muted:#cbd5e1;
      --line:rgba(255,255,255,.12);
      --accent:#facc15;
      --accent-ink:#111827;
      --focus:#22d3ee;
    }
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%;background:radial-gradient(circle at top,#17304d 0%,var(--bg) 48%,#050b14 100%);color:var(--text);font-family:Arial,Helvetica,sans-serif}
    a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:3px}
    .topbar{position:sticky;top:0;z-index:10;background:rgba(8,17,31,.9);border-bottom:1px solid var(--line);backdrop-filter:blur(10px)}
    .topbar-inner{max-width:1200px;margin:0 auto;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{margin:0;font-size:18px;font-weight:700}
    .menu{list-style:none;margin:0;padding:0;display:flex;gap:10px;flex-wrap:wrap}
    .menu a{display:inline-block;color:var(--text);text-decoration:none;font-weight:700;padding:8px 10px;border-radius:8px}
    .menu a:hover{background:rgba(255,255,255,.08)}
    .menu .cta{background:var(--accent);color:var(--accent-ink)}
    main{max-width:1200px;margin:0 auto;padding:28px 18px 40px}
    .hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:18px;align-items:stretch}
    .panel{background:rgba(15,27,45,.86);border:1px solid var(--line);border-radius:22px;box-shadow:0 24px 60px rgba(0,0,0,.28)}
    .heroCard{padding:24px}
    .eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#93c5fd}
    h1{margin:10px 0 0;font-size:clamp(32px,5vw,52px);line-height:1.05}
    .lead{margin-top:16px;color:var(--muted);font-size:18px;line-height:1.6;max-width:58ch}
    .heroActions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}
    .button{display:inline-block;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:700;border:1px solid var(--line);background:#1d4ed8;color:#fff}
    .button.alt{background:transparent;color:var(--text)}
    .button.primary{background:var(--accent);color:var(--accent-ink)}
    .overview{padding:22px;display:flex;flex-direction:column;justify-content:space-between}
    .overviewTitle{font-size:15px;font-weight:700;margin-bottom:12px}
    .overviewList{display:flex;flex-direction:column;gap:12px}
    .overviewItem{padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
    .overviewItem strong{display:block;margin-bottom:4px}
    .section{margin-top:24px}
    .sectionTitle{font-size:14px;letter-spacing:.12em;text-transform:uppercase;color:#93c5fd;margin-bottom:12px}
    .steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
    .stepCard{padding:20px}
    .stepNumber{width:36px;height:36px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:var(--accent);color:var(--accent-ink);font-weight:700;margin-bottom:14px}
    .stepCard h2{margin:0;font-size:24px;line-height:1.2}
    .stepCard p{margin:12px 0 0;color:var(--muted);line-height:1.6}
    .stepLinks{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    .miniLink{display:inline-block;padding:9px 12px;border-radius:10px;text-decoration:none;font-weight:700;background:#1e293b;color:#fff;border:1px solid var(--line)}
    .miniLink:hover{background:#334155}
    .detailGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
    .detailCard{padding:18px}
    .detailCard h3{margin:0 0 10px;font-size:18px}
    .detailCard p{margin:0;color:var(--muted);line-height:1.6}
    .disclaimer{margin-top:24px;padding:18px}
    .disclaimer p{margin:10px 0 0;color:var(--muted);line-height:1.7}
    @media (max-width: 980px){
      .hero{grid-template-columns:1fr}
      .steps,.detailGrid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <p class="brand">Stay On Trails</p>
    <nav aria-label="Main menu">
      <ul class="menu">
        <li><a class="cta" href="index.php" aria-current="page">Home</a></li>
        <li><a href="routes.php">Available routes</a></li>
        <li><a href="makepath.php">Route builder</a></li>
        <li><a href="followpath.php">Start route</a></li>
        <li><a href="recordroute.php">Record route</a></li>
        <li><a href="remoteHelp.php">Remote assistant</a></li>
      </ul>
    </nav>
  </div>
</header>

<main>
  <section class="hero">
    <div class="panel heroCard">
      <div class="eyebrow">How It Works</div>
      <h1>Navigation, route creation, segmentation recording, and remote support.</h1>
      <p class="lead">
        Stay On Trails combines saved walking routes, live heading feedback, turn-by-turn guidance,
        and remote assistance. You can build a route, record route images for a new segmentation model,
        start a freely available park tour, or let a helper follow along through the camera stream.
      </p>
      <div class="heroActions">
        <a class="button primary" href="routes.php">Open Available Routes</a>
        <a class="button" href="makepath.php?new=1">Create New Route</a>
        <a class="button alt" href="recordroute.php">Record Route Images</a>
      </div>
    </div>

    <div class="panel overview">
      <div>
        <div class="overviewTitle">Main flows</div>
        <div class="overviewList">
          <div class="overviewItem">
            <strong>1. Create or record</strong>
            Build a new route in the route builder, or record route images to create training data for a new segmentation model.
          </div>
          <div class="overviewItem">
            <strong>2. Start route</strong>
            Launch a saved park tour with heading feedback and spoken turn-by-turn route guidance.
          </div>
          <div class="overviewItem">
            <strong>3. Remote assistant</strong>
            A helper can watch the live stream, see the walker location, and send voice messages back to the user.
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionTitle">Three Steps</div>
    <div class="steps">
      <article class="panel stepCard">
        <div class="stepNumber">1</div>
        <h2>Create new route or record route</h2>
        <p>
          Use <strong>Route builder</strong> to place waypoints and route instructions, or use
          <strong>Record route</strong> to capture 640 x 480 images every few seconds for a new segmentation model.
        </p>
        <div class="stepLinks">
          <a class="miniLink" href="makepath.php?new=1">Create new route</a>
          <a class="miniLink" href="recordroute.php">Record route</a>
        </div>
      </article>

      <article class="panel stepCard">
        <div class="stepNumber">2</div>
        <h2>Start route in a freely available park tour</h2>
        <p>
          Open a saved route and start walking. The page shows live heading guidance together with
          spoken turn-by-turn navigation instructions for the selected route.
        </p>
        <div class="stepLinks">
          <a class="miniLink" href="routes.php">Choose available route</a>
          <a class="miniLink" href="followpath.php">Start route</a>
        </div>
      </article>

      <article class="panel stepCard">
        <div class="stepNumber">3</div>
        <h2>Remote assistant via camera stream and voice messages</h2>
        <p>
          The helper sees the camera stream, the route map, and the walker location. Messages can be
          sent back to the walker and spoken aloud on the follow page.
        </p>
        <div class="stepLinks">
          <a class="miniLink" href="remoteHelp.php">Open remote assistant</a>
        </div>
      </article>
    </div>
  </section>

  <section class="section">
    <div class="sectionTitle">Details</div>
    <div class="detailGrid">
      <article class="panel detailCard">
        <h3>Route builder</h3>
        <p>
          Save route points, instructions, language, heading feedback speed, model selection, and route length in the route JSON.
        </p>
      </article>
      <article class="panel detailCard">
        <h3>Start route</h3>
        <p>
          Follow the path on the map with current GPS position, waypoint logic, heading directions, and optional remote assistant messages.
        </p>
      </article>
      <article class="panel detailCard">
        <h3>Record route</h3>
        <p>
          Store route images on the server by model name and log timestamp, image filename, and GPS coordinates in a CSV file.
        </p>
      </article>
    </div>
  </section>

  <section class="section">
    <div class="panel disclaimer">
      <div class="sectionTitle">Disclaimer</div>
      <p>
        This application is a research project and proof of concept developed in the context of Erasmus Hogeschool Brussel. Use of the application may involve the collection and storage of data on servers used for the project, including image, video, route, and location-related data.
      </p>
      <p>
        Any personal data processed through this application is intended to be used only for research, testing, evaluation, and further development of the project by Erasmus Hogeschool Brussel, in accordance with applicable data protection law. Data is not intended to be sold or disclosed to unrelated third parties. However, data may be processed by authorised persons, technical service providers acting on behalf of Erasmus Hogeschool Brussel, or where disclosure is required by law.
      </p>
      <p>
        If this application is used with identifiable personal data, a project-specific privacy notice, retention policy, lawful basis for processing, and contact details for the responsible controller should also be provided separately.
      </p>
    </div>
  </section>
</main>
</body>
</html>
