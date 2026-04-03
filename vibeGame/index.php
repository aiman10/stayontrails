<?php
$games = [
    [
        'title' => 'Schaar Steen Papier',
        'file' => 'schaarSteenPapier.php',
        'description' => 'Speel direct tegen de computer en houd je score bij tijdens je sessie.',
        'tag' => 'Singleplayer',
    ],
    [
        'title' => 'Schaak Multiplayer',
        'file' => 'schaakMultiplayer.php',
        'description' => 'Twee spelers spelen lokaal op hetzelfde scherm met zetcontrole en schaakdetectie.',
        'tag' => 'Lokale multiplayer',
    ],
    [
        'title' => 'Pong Multiplayer',
        'file' => 'pongMultiplayer.php',
        'description' => 'Klassieke Pong voor twee spelers op hetzelfde toetsenbord, met score en snelle herstart.',
        'tag' => 'Arcade multiplayer',
    ],
    [
        'title' => '2D Karting Race',
        'file' => 'kartRace3d.php',
        'description' => 'Top-down karting op een ovaal racecircuit met boostpads, CPU-karts en drie ronden.',
        'tag' => '2D racegame',
    ],
    [
        'title' => 'FPS Target Range',
        'file' => 'fpsLegermannetjes.php',
        'description' => 'Arcade schietbaan waarin alleen legermannetjes geldige targets zijn en kinderen of dieren niet geraakt mogen worden.',
        'tag' => 'Arcade shooter',
    ],
    [
        'title' => 'Pacman',
        'file' => 'pacman.php',
        'description' => 'Doolhofgame met pellets, power pellets, spoken, score en meerdere levels.',
        'tag' => 'Arcade classic',
    ],
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VibeGame Overzicht</title>
    <style>
        :root {
            --bg-a: #0f172a;
            --bg-b: #1d4ed8;
            --panel: rgba(255, 255, 255, 0.1);
            --panel-border: rgba(255, 255, 255, 0.18);
            --text: #eff6ff;
            --muted: #cbd5e1;
            --accent: #fbbf24;
            --accent-ink: #111827;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(251, 191, 36, 0.2), transparent 30%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
            padding: 24px;
        }

        .shell {
            max-width: 1100px;
            margin: 0 auto;
        }

        .hero,
        .game-card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 24px;
            backdrop-filter: blur(12px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.24);
        }

        .hero {
            padding: 30px;
            margin-bottom: 20px;
        }

        .eyebrow {
            margin: 0 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
        }

        h1 {
            margin: 0;
            font-size: clamp(32px, 5vw, 54px);
            line-height: 1.02;
        }

        .subtitle {
            margin: 14px 0 0;
            max-width: 720px;
            line-height: 1.6;
            color: var(--muted);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
        }

        .game-card {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .tag {
            display: inline-block;
            width: fit-content;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .game-title {
            margin: 0;
            font-size: 24px;
        }

        .game-description {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            flex: 1;
        }

        .play-link {
            display: inline-block;
            width: fit-content;
            padding: 12px 16px;
            border-radius: 999px;
            background: var(--accent);
            color: var(--accent-ink);
            text-decoration: none;
            font-weight: 700;
        }

        .play-link:hover {
            background: #fcd34d;
        }

        @media (max-width: 640px) {
            body { padding: 14px; }
            .hero { padding: 22px; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <p class="eyebrow">VibeGame</p>
            <h1>Game overzicht</h1>
            <p class="subtitle">Kies een browsergame en start direct vanuit deze indexpagina.</p>
        </section>

        <section class="grid">
            <?php foreach ($games as $game): ?>
                <article class="game-card">
                    <span class="tag"><?php echo h($game['tag']); ?></span>
                    <h2 class="game-title"><?php echo h($game['title']); ?></h2>
                    <p class="game-description"><?php echo h($game['description']); ?></p>
                    <a class="play-link" href="<?php echo h($game['file']); ?>">Open game</a>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
