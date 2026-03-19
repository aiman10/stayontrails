<?php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stay On Trails</title>
  <style>
    :root {
      --bg: #0f172a;
      --surface: #1e293b;
      --text: #f8fafc;
      --muted: #cbd5e1;
      --accent: #facc15;
      --accent-ink: #111827;
      --focus: #22d3ee;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      line-height: 1.6;
      background: linear-gradient(180deg, #0b1220 0%, var(--bg) 100%);
      color: var(--text);
    }

    .skip-link {
      position: absolute;
      left: 0.5rem;
      top: -3rem;
      background: var(--accent);
      color: var(--accent-ink);
      padding: 0.5rem 0.75rem;
      border-radius: 0.5rem;
      font-weight: 700;
      text-decoration: none;
    }
    .skip-link:focus { top: 0.5rem; }

    a:focus-visible, button:focus-visible {
      outline: 3px solid var(--focus);
      outline-offset: 3px;
      border-radius: 0.4rem;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 10;
      background: rgba(15, 23, 42, 0.95);
      border-bottom: 1px solid #334155;
    }
    .topbar-inner {
      max-width: 68rem;
      margin: 0 auto;
      padding: 0.75rem 1rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .brand {
      margin: 0;
      font-size: 1.1rem;
      letter-spacing: 0.02em;
    }
    .menu {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .menu a {
      color: var(--text);
      text-decoration: none;
      font-weight: 700;
      padding: 0.4rem 0.65rem;
      border-radius: 0.5rem;
    }
    .menu a:hover { background: #273449; }
    .menu .cta {
      background: var(--accent);
      color: var(--accent-ink);
    }
    .menu .cta:hover { background: #fde047; }

    main {
      max-width: 68rem;
      margin: 0 auto;
      padding: 2rem 1rem 3rem;
    }
    .hero {
      background: var(--surface);
      border: 1px solid #334155;
      border-radius: 1rem;
      padding: 1.25rem;
    }
    .hero-top {
      display: grid;
      gap: 1rem;
      align-items: start;
    }
    .hero-copy {
      min-width: 0;
    }
    .hero-image {
      margin: 0;
    }
    .hero-image img {
      width: 100%;
      height: auto;
      border-radius: 0.6rem;
      border: 1px solid #334155;
      display: block;
    }
    .hero-image figcaption {
      margin-top: 0.45rem;
      color: var(--muted);
      font-size: 0.95rem;
    }
    h1 {
      margin: 0 0 0.7rem;
      font-size: clamp(1.8rem, 2.8vw, 2.4rem);
      line-height: 1.25;
    }
    p {
      margin: 0.7rem 0;
      color: var(--muted);
      max-width: 55ch;
    }
    .button {
      display: inline-block;
      margin-top: 0.8rem;
      padding: 0.75rem 1rem;
      border-radius: 0.6rem;
      background: var(--accent);
      color: var(--accent-ink);
      text-decoration: none;
      font-weight: 700;
    }
    .steps {
      margin-top: 1rem;
      padding: 0.9rem 1rem;
      border-radius: 0.75rem;
      border: 1px solid #334155;
      background: #142033;
      max-width: 60ch;
    }
    .steps h2 {
      margin: 0 0 0.5rem;
      font-size: 1.1rem;
    }
    .steps ol {
      margin: 0;
      padding-left: 1.2rem;
      color: var(--muted);
    }
    .steps a {
      color: #93c5fd;
    }
    @media (min-width: 860px) {
      .hero-top {
        grid-template-columns: 1.2fr 1fr;
      }
    }

    .features {
      margin-top: 1.25rem;
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    }
    .card {
      background: #172338;
      border: 1px solid #334155;
      border-radius: 0.75rem;
      padding: 1rem;
    }
    .card h2 {
      margin: 0 0 0.4rem;
      font-size: 1.2rem;
    }
    .card p {
      margin: 0;
      color: var(--muted);
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
          <li><a href="index.php" aria-current="page">Home</a></li>
          <li><a href="jetsonStayontrails.php">AAL Companion</a></li>
          <li><a class="cta" href="stayontrails.php">Try it out</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main id="main-content">
    <section class="hero" aria-labelledby="hero-title">
      <div class="hero-top">
        <div class="hero-copy">
          <h1 id="hero-title">Audio guidance to help users stay on the trail</h1>
          <p>
            Stay On Trails uses your camera and heading to provide clear spoken directions.
            This page is built for accessibility with high contrast, keyboard support, and semantic structure.
          </p>
          <a class="button" href="stayontrails.php">Start the demo</a>
        </div>
        <figure class="hero-image">
          <img src="img/samplePark.jpg" alt="Example park trail image used for path guidance testing." />
          <figcaption>Sample park image for demo and guidance testing.</figcaption>
        </figure>
      </div>
      <div class="steps" aria-label="How to test on mobile">
        <h2>Test on your mobile phone</h2>
        <ol>
          <li>Open this test video: <a href="https://youtu.be/gdL35MJxmQA?si=4eEKiPXg0HmggHmf">YouTube path video</a>.</li>
          <li>Tap <strong>START</strong> in the menu and press <strong>Start</strong>.</li>
          <li>Point your phone camera to the video screen.</li>
          <li>The app will provide path guidance using audio directions.</li>
        </ol>
      </div>
    </section>


    <section class="features" aria-label="Key features">
      <article class="card">
        <h2>Voice-first guidance</h2>
        <p>Hear left, right, and forward instructions while moving.</p>
      </article>
      <article class="card">
        <h2>Keyboard-friendly</h2>
        <p>All key navigation links are focusable and visible with clear focus states.</p>
      </article>
      <article class="card">
        <h2>Simple menu </h2>
        <p>Use the START item in the top menu to jump straight into the live experience.</p>
      </article>
    </section>
  </main>
</body>
</html>
