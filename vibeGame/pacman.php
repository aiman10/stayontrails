<?php
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pacman</title>
    <style>
        :root {
            --bg-a: #040814;
            --bg-b: #13254f;
            --panel: rgba(255,255,255,0.08);
            --line: rgba(255,255,255,0.16);
            --text: #f8fafc;
            --muted: #cbd5e1;
            --accent: #facc15;
            --accent-ink: #111827;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 18px;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(250,204,21,.16), transparent 28%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
        }
        .shell {
            max-width: 1380px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(320px, 980px) minmax(280px, 1fr);
            gap: 18px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            backdrop-filter: blur(12px);
            box-shadow: 0 24px 60px rgba(0,0,0,.28);
        }
        .game-panel { padding: 20px; }
        .side-panel { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
        .eyebrow {
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
        }
        h1 {
            margin: 0;
            font-size: clamp(30px, 4vw, 52px);
            line-height: 1.02;
        }
        .subtitle {
            margin: 12px 0 18px;
            max-width: 760px;
            color: var(--muted);
            line-height: 1.6;
        }
        .hud {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .hud-card, .card {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.06);
        }
        .hud-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
        }
        .hud-value {
            margin-top: 6px;
            font-size: 28px;
            font-weight: 700;
        }
        .canvas-wrap {
            overflow: hidden;
            border-radius: 24px;
            border: 1px solid var(--line);
            background: #020617;
        }
        canvas {
            display: block;
            width: 100%;
            height: auto;
            image-rendering: pixelated;
        }
        .card-title {
            margin: 0 0 10px;
            font-size: 18px;
        }
        .detail {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        button {
            border: 0;
            border-radius: 999px;
            padding: 12px 16px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        .primary { background: var(--accent); color: var(--accent-ink); }
        .secondary { background: rgba(255,255,255,.1); color: var(--text); border: 1px solid var(--line); }
        @media (max-width: 1080px) { .shell { grid-template-columns: 1fr; } }
        @media (max-width: 760px) {
            body { padding: 12px; }
            .game-panel, .side-panel { padding: 16px; }
            .hud { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel game-panel">
            <p class="eyebrow">Arcade maze</p>
            <h1>Pacman</h1>
            <p class="subtitle">Volledig opnieuw opgebouwd met betrouwbaar grid-based bewegen. Gebruik pijltjestoetsen of <strong>WASD</strong>.</p>

            <div class="hud">
                <div class="hud-card"><div class="hud-label">Score</div><div class="hud-value" id="scoreValue">0</div></div>
                <div class="hud-card"><div class="hud-label">Levens</div><div class="hud-value" id="livesValue">3</div></div>
                <div class="hud-card"><div class="hud-label">Level</div><div class="hud-value" id="levelValue">1</div></div>
                <div class="hud-card"><div class="hud-label">Power</div><div class="hud-value" id="powerValue">0.0s</div></div>
            </div>

            <div class="canvas-wrap">
                <canvas id="gameCanvas" width="756" height="756" aria-label="Pacman doolhof"></canvas>
            </div>
        </section>

        <aside class="panel side-panel">
            <section class="card">
                <h2 class="card-title">Regels</h2>
                <p class="detail">Eet alle pellets. Power pellets maken spoken tijdelijk kwetsbaar. Als je een spook raakt zonder power, verlies je een leven.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Besturing</h2>
                <p class="detail">Gebruik pijltjestoetsen of <strong>WASD</strong>. Pacman beweegt automatisch door in de huidige richting totdat je een andere geldige richting kiest.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Status</h2>
                <p class="detail" id="statusText">Druk op start spel.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Acties</h2>
                <div class="actions">
                    <button class="primary" type="button" id="startBtn">Start spel</button>
                    <button class="secondary" type="button" id="resetBtn">Reset</button>
                </div>
            </section>
        </aside>
    </main>

    <script>
        const canvas = document.getElementById("gameCanvas");
        const ctx = canvas.getContext("2d");
        const scoreValueEl = document.getElementById("scoreValue");
        const livesValueEl = document.getElementById("livesValue");
        const levelValueEl = document.getElementById("levelValue");
        const powerValueEl = document.getElementById("powerValue");
        const statusTextEl = document.getElementById("statusText");
        const startBtn = document.getElementById("startBtn");
        const resetBtn = document.getElementById("resetBtn");

        const TILE = 36;
        const STEP_TIME = 0.14;
        const GHOST_STEP_TIME = 0.18;
        const MAP_TEMPLATE = [
            "#####################",
            "#o........#........o#",
            "#.###.###.#.###.###.#",
            "#.#.....#.#.#.....#.#",
            "#.#.###.#.#.#.###.#.#",
            "#...................#",
            "#.#.###.#####.###.#.#",
            "#.#...#...#...#...#.#",
            "#.###.###.#.###.###.#",
            "#.........P.........#",
            "#####.#.##-##.#.#####",
            "#.....#..GGG..#.....#",
            "#####.#.#####.#.#####",
            "#.........G.........#",
            "#.###.##.#.#.##.###.#",
            "#...#....#.#....#...#",
            "###.#.#######.#.#.###",
            "#.....#.....#.#.....#",
            "#.#####.###.#.#####.#",
            "#...................#",
            "#####################"
        ];

        const DIRECTIONS = {
            left: { x: -1, y: 0, angle: Math.PI },
            right: { x: 1, y: 0, angle: 0 },
            up: { x: 0, y: -1, angle: -Math.PI / 2 },
            down: { x: 0, y: 1, angle: Math.PI / 2 }
        };

        const keyToDirection = {
            ArrowLeft: "left",
            ArrowRight: "right",
            ArrowUp: "up",
            ArrowDown: "down",
            KeyA: "left",
            KeyD: "right",
            KeyW: "up",
            KeyS: "down"
        };

        const state = {
            running: false,
            score: 0,
            lives: 3,
            level: 1,
            powerTimer: 0,
            map: [],
            pelletsRemaining: 0,
            player: null,
            ghosts: [],
            lastTick: 0,
            moveTimer: 0,
            ghostTimer: 0
        };

        function cloneMap() {
            return MAP_TEMPLATE.map((row) => row.split(""));
        }

        function buildLevel() {
            state.map = cloneMap();
            state.pelletsRemaining = 0;
            let playerSpawn = { x: 1, y: 1 };
            const ghostSpawns = [];

            for (let y = 0; y < state.map.length; y++) {
                for (let x = 0; x < state.map[y].length; x++) {
                    const tile = state.map[y][x];
                    if (tile === "." || tile === "o") {
                        state.pelletsRemaining++;
                    } else if (tile === "P") {
                        playerSpawn = { x, y };
                        state.map[y][x] = " ";
                    } else if (tile === "G") {
                        ghostSpawns.push({ x, y });
                        state.map[y][x] = " ";
                    }
                }
            }

            state.player = {
                tileX: playerSpawn.x,
                tileY: playerSpawn.y,
                dir: "right",
                nextDir: "right",
                mouth: 0
            };

            const ghostColors = ["#fb7185", "#60a5fa", "#f97316", "#34d399"];
            state.ghosts = ghostSpawns.map((spawn, index) => ({
                tileX: spawn.x,
                tileY: spawn.y,
                dir: ["left", "right", "up", "down"][index % 4],
                color: ghostColors[index % ghostColors.length],
                vulnerable: false
            }));
        }

        function resetGame() {
            state.running = false;
            state.score = 0;
            state.lives = 3;
            state.level = 1;
            state.powerTimer = 0;
            state.lastTick = 0;
            state.moveTimer = 0;
            state.ghostTimer = 0;
            buildLevel();
            updateHud();
            statusTextEl.textContent = "Druk op start spel.";
        }

        function startGame() {
            if (!state.player) {
                buildLevel();
            }
            state.running = true;
            statusTextEl.textContent = "Spel bezig.";
        }

        function updateHud() {
            scoreValueEl.textContent = String(state.score);
            livesValueEl.textContent = String(state.lives);
            levelValueEl.textContent = String(state.level);
            powerValueEl.textContent = `${Math.max(0, state.powerTimer).toFixed(1)}s`;
        }

        function isWall(tileX, tileY) {
            if (tileY < 0 || tileY >= state.map.length || tileX < 0 || tileX >= state.map[0].length) {
                return true;
            }
            return state.map[tileY][tileX] === "#";
        }

        function canMove(entity, dir) {
            const offset = DIRECTIONS[dir];
            const nextX = entity.tileX + offset.x;
            const nextY = entity.tileY + offset.y;
            return !isWall(nextX, nextY);
        }

        function moveEntity(entity, dir) {
            const offset = DIRECTIONS[dir];
            entity.tileX += offset.x;
            entity.tileY += offset.y;

            if (entity.tileX < 0) entity.tileX = state.map[0].length - 1;
            if (entity.tileX >= state.map[0].length) entity.tileX = 0;
        }

        function consumePellet() {
            const tile = state.map[state.player.tileY][state.player.tileX];
            if (tile !== "." && tile !== "o") {
                return;
            }

            state.map[state.player.tileY][state.player.tileX] = " ";
            state.pelletsRemaining--;

            if (tile === ".") {
                state.score += 10;
            } else {
                state.score += 50;
                state.powerTimer = 8;
                state.ghosts.forEach((ghost) => {
                    ghost.vulnerable = true;
                });
            }

            if (state.pelletsRemaining <= 0) {
                nextLevel();
            }
        }

        function updatePlayerStep() {
            const player = state.player;
            player.mouth += 1;

            if (canMove(player, player.nextDir)) {
                player.dir = player.nextDir;
            }

            if (canMove(player, player.dir)) {
                moveEntity(player, player.dir);
                consumePellet();
            }
        }

        function chooseGhostDirection(ghost) {
            const possible = Object.keys(DIRECTIONS).filter((dir) => {
                if (
                    (ghost.dir === "left" && dir === "right") ||
                    (ghost.dir === "right" && dir === "left") ||
                    (ghost.dir === "up" && dir === "down") ||
                    (ghost.dir === "down" && dir === "up")
                ) {
                    return false;
                }
                return canMove(ghost, dir);
            });

            if (!possible.length) {
                return canMove(ghost, ghost.dir) ? ghost.dir : "left";
            }

            const player = state.player;
            possible.sort((a, b) => {
                const aX = ghost.tileX + DIRECTIONS[a].x;
                const aY = ghost.tileY + DIRECTIONS[a].y;
                const bX = ghost.tileX + DIRECTIONS[b].x;
                const bY = ghost.tileY + DIRECTIONS[b].y;
                const distA = Math.hypot(player.tileX - aX, player.tileY - aY);
                const distB = Math.hypot(player.tileX - bX, player.tileY - bY);
                return ghost.vulnerable ? distB - distA : distA - distB;
            });

            return possible[Math.floor(Math.random() * Math.min(2, possible.length))];
        }

        function updateGhostStep() {
            for (const ghost of state.ghosts) {
                ghost.dir = chooseGhostDirection(ghost);
                if (canMove(ghost, ghost.dir)) {
                    moveEntity(ghost, ghost.dir);
                }
            }
        }

        function resetRoundPositions() {
            const currentScore = state.score;
            const currentLives = state.lives;
            const currentLevel = state.level;
            const currentMap = state.map.map((row) => row.slice());
            const currentPellets = state.pelletsRemaining;

            buildLevel();
            state.score = currentScore;
            state.lives = currentLives;
            state.level = currentLevel;
            state.map = currentMap;
            state.pelletsRemaining = currentPellets;
            state.powerTimer = 0;
            state.moveTimer = 0;
            state.ghostTimer = 0;
        }

        function loseLife() {
            state.lives--;
            updateHud();
            if (state.lives <= 0) {
                state.running = false;
                statusTextEl.textContent = `Game over. Score ${state.score}.`;
                return;
            }
            statusTextEl.textContent = "Leven verloren.";
            resetRoundPositions();
        }

        function checkCollisions() {
            for (const ghost of state.ghosts) {
                if (ghost.tileX === state.player.tileX && ghost.tileY === state.player.tileY) {
                    if (ghost.vulnerable) {
                        state.score += 200;
                        ghost.tileX = 10;
                        ghost.tileY = 11;
                        ghost.vulnerable = false;
                        ghost.dir = "up";
                        statusTextEl.textContent = "Spook gepakt.";
                    } else {
                        loseLife();
                    }
                    break;
                }
            }
        }

        function nextLevel() {
            state.level++;
            state.powerTimer = 0;
            buildLevel();
            state.moveTimer = 0;
            state.ghostTimer = 0;
            statusTextEl.textContent = `Level ${state.level}.`;
            updateHud();
        }

        function update(dt) {
            if (!state.running) {
                return;
            }

            if (state.powerTimer > 0) {
                state.powerTimer = Math.max(0, state.powerTimer - dt);
                if (state.powerTimer === 0) {
                    state.ghosts.forEach((ghost) => {
                        ghost.vulnerable = false;
                    });
                }
            }

            state.moveTimer += dt;
            state.ghostTimer += dt;

            if (state.moveTimer >= STEP_TIME) {
                state.moveTimer -= STEP_TIME;
                updatePlayerStep();
                checkCollisions();
            }

            if (state.ghostTimer >= GHOST_STEP_TIME) {
                state.ghostTimer -= GHOST_STEP_TIME;
                updateGhostStep();
                checkCollisions();
            }

            updateHud();
        }

        function drawMap() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = "#020617";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            for (let y = 0; y < state.map.length; y++) {
                for (let x = 0; x < state.map[y].length; x++) {
                    const tile = state.map[y][x];
                    const px = x * TILE;
                    const py = y * TILE;

                    if (tile === "#") {
                        ctx.fillStyle = "#2563eb";
                        ctx.fillRect(px + 2, py + 2, TILE - 4, TILE - 4);
                        ctx.fillStyle = "#60a5fa";
                        ctx.fillRect(px + 9, py + 9, TILE - 18, TILE - 18);
                    } else if (tile === ".") {
                        ctx.fillStyle = "#f8fafc";
                        ctx.beginPath();
                        ctx.arc(px + TILE / 2, py + TILE / 2, 4, 0, Math.PI * 2);
                        ctx.fill();
                    } else if (tile === "o") {
                        ctx.fillStyle = "#fde68a";
                        ctx.beginPath();
                        ctx.arc(px + TILE / 2, py + TILE / 2, 8, 0, Math.PI * 2);
                        ctx.fill();
                    } else if (tile === "-") {
                        ctx.fillStyle = "#1e293b";
                        ctx.fillRect(px, py + TILE / 2 - 2, TILE, 4);
                    }
                }
            }
        }

        function drawPacman() {
            const player = state.player;
            const mouth = (player.mouth % 2 === 0) ? 0.2 : 0.75;
            const angle = DIRECTIONS[player.dir].angle;
            const x = player.tileX * TILE + TILE / 2;
            const y = player.tileY * TILE + TILE / 2;

            ctx.save();
            ctx.translate(x, y);
            ctx.fillStyle = "#facc15";
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.arc(0, 0, TILE * 0.42, angle + mouth, angle + (Math.PI * 2) - mouth);
            ctx.closePath();
            ctx.fill();
            ctx.restore();
        }

        function drawGhost(ghost) {
            const x = ghost.tileX * TILE + TILE / 2;
            const y = ghost.tileY * TILE + TILE / 2;
            ctx.save();
            ctx.translate(x, y);
            ctx.fillStyle = ghost.vulnerable ? ((Math.floor(state.powerTimer * 6) % 2 === 0) ? "#60a5fa" : "#e2e8f0") : ghost.color;
            ctx.beginPath();
            ctx.arc(0, -6, TILE * 0.34, Math.PI, 0);
            ctx.lineTo(TILE * 0.34, TILE * 0.24);
            ctx.lineTo(TILE * 0.18, TILE * 0.1);
            ctx.lineTo(0, TILE * 0.24);
            ctx.lineTo(-TILE * 0.18, TILE * 0.1);
            ctx.lineTo(-TILE * 0.34, TILE * 0.24);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = "#fff";
            ctx.beginPath();
            ctx.arc(-7, -6, 5, 0, Math.PI * 2);
            ctx.arc(7, -6, 5, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = "#111827";
            ctx.beginPath();
            ctx.arc(-6, -5, 2.4, 0, Math.PI * 2);
            ctx.arc(8, -5, 2.4, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }

        function render() {
            drawMap();
            state.ghosts.forEach(drawGhost);
            drawPacman();
        }

        function frame(timestamp) {
            if (!state.lastTick) {
                state.lastTick = timestamp;
            }
            const dt = Math.min(0.05, (timestamp - state.lastTick) / 1000);
            state.lastTick = timestamp;
            update(dt);
            render();
            requestAnimationFrame(frame);
        }

        window.addEventListener("keydown", (event) => {
            const dir = keyToDirection[event.code];
            if (dir && state.player) {
                state.player.nextDir = dir;
                event.preventDefault();
            }
        });

        startBtn.addEventListener("click", () => {
            startGame();
        });

        resetBtn.addEventListener("click", () => {
            resetGame();
        });

        resetGame();
        render();
        requestAnimationFrame(frame);
    </script>
</body>
</html>
