<?php
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pong Multiplayer</title>
    <style>
        :root {
            --bg-a: #08111b;
            --bg-b: #133a5e;
            --panel: rgba(255, 255, 255, 0.1);
            --panel-border: rgba(255, 255, 255, 0.16);
            --text: #eff6ff;
            --muted: #cbd5e1;
            --accent: #f59e0b;
            --accent-ink: #101828;
            --line: rgba(255, 255, 255, 0.14);
            --glow: rgba(125, 211, 252, 0.45);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(245, 158, 11, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(125, 211, 252, 0.16), transparent 30%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
            padding: 20px;
        }

        .shell {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(320px, 860px) minmax(280px, 1fr);
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 24px;
            backdrop-filter: blur(12px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.24);
        }

        .game-panel { padding: 22px; }
        .side-panel { padding: 22px; display: flex; flex-direction: column; gap: 16px; }

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
            font-size: clamp(30px, 4vw, 52px);
            line-height: 1.02;
        }

        .subtitle {
            margin: 14px 0 20px;
            max-width: 760px;
            color: var(--muted);
            line-height: 1.6;
        }

        .scoreboard {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }

        .score-box {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.06);
        }

        .score-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
        }

        .score-value {
            margin-top: 6px;
            font-size: 34px;
            font-weight: 700;
        }

        .status-chip {
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--line);
            font-weight: 700;
        }

        .canvas-wrap {
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(8, 17, 27, 0.84), rgba(6, 10, 16, 0.94));
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
            background:
                linear-gradient(90deg, transparent 49.5%, rgba(255,255,255,0.2) 49.5%, rgba(255,255,255,0.2) 50.5%, transparent 50.5%);
        }

        .card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.06);
        }

        .card-title {
            margin: 0 0 10px;
            font-size: 18px;
        }

        .detail {
            color: var(--muted);
            line-height: 1.6;
            margin: 0;
        }

        .controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .keys {
            font-family: Consolas, monospace;
            color: var(--text);
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

        .primary {
            background: var(--accent);
            color: var(--accent-ink);
        }

        .secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text);
            border: 1px solid var(--line);
        }

        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            body { padding: 12px; }
            .game-panel, .side-panel { padding: 16px; }
            .scoreboard, .controls { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel game-panel">
            <p class="eyebrow">Lokale multiplayer</p>
            <h1>Pong voor 2 spelers</h1>
            <p class="subtitle">Speel samen op hetzelfde toetsenbord. Links gebruikt <strong>W</strong> en <strong>S</strong>, rechts gebruikt <strong>pijl omhoog</strong> en <strong>pijl omlaag</strong>. Eerste speler tot 7 punten wint.</p>

            <div class="scoreboard">
                <div class="score-box">
                    <div class="score-label">Speler links</div>
                    <div class="score-value" id="leftScore">0</div>
                </div>
                <div class="status-chip" id="statusText">Druk op start</div>
                <div class="score-box">
                    <div class="score-label">Speler rechts</div>
                    <div class="score-value" id="rightScore">0</div>
                </div>
            </div>

            <div class="canvas-wrap">
                <canvas id="pongCanvas" width="900" height="540" aria-label="Pong speelveld"></canvas>
            </div>
        </section>

        <aside class="panel side-panel">
            <section class="card">
                <h2 class="card-title">Besturing</h2>
                <div class="controls">
                    <div>
                        <p class="detail">Links</p>
                        <p class="detail keys">W = omhoog<br>S = omlaag</p>
                    </div>
                    <div>
                        <p class="detail">Rechts</p>
                        <p class="detail keys">↑ = omhoog<br>↓ = omlaag</p>
                    </div>
                </div>
            </section>

            <section class="card">
                <h2 class="card-title">Regels</h2>
                <p class="detail">De bal versnelt licht na elke paddle-hit. Zodra een speler 7 punten heeft, stopt het spel en kun je een nieuwe match starten.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Acties</h2>
                <div class="actions">
                    <button class="primary" type="button" id="startBtn">Start spel</button>
                    <button class="secondary" type="button" id="resetBtn">Reset score</button>
                </div>
            </section>
        </aside>
    </main>

    <script>
        const canvas = document.getElementById("pongCanvas");
        const ctx = canvas.getContext("2d");
        const leftScoreEl = document.getElementById("leftScore");
        const rightScoreEl = document.getElementById("rightScore");
        const statusTextEl = document.getElementById("statusText");
        const startBtn = document.getElementById("startBtn");
        const resetBtn = document.getElementById("resetBtn");

        const config = {
            width: canvas.width,
            height: canvas.height,
            paddleWidth: 16,
            paddleHeight: 110,
            paddleSpeed: 7,
            ballSize: 16,
            winningScore: 7
        };

        const keys = {
            KeyW: false,
            KeyS: false,
            ArrowUp: false,
            ArrowDown: false
        };

        const state = {
            running: false,
            leftScore: 0,
            rightScore: 0,
            leftPaddleY: (config.height - config.paddleHeight) / 2,
            rightPaddleY: (config.height - config.paddleHeight) / 2,
            ballX: config.width / 2,
            ballY: config.height / 2,
            ballVelocityX: 0,
            ballVelocityY: 0
        };

        function updateScoreboard() {
            leftScoreEl.textContent = String(state.leftScore);
            rightScoreEl.textContent = String(state.rightScore);
        }

        function setStatus(message) {
            statusTextEl.textContent = message;
        }

        function resetBall(direction = 1) {
            state.ballX = config.width / 2;
            state.ballY = config.height / 2;
            const horizontalSpeed = 5 * direction;
            const verticalSpeed = (Math.random() * 4) - 2;
            state.ballVelocityX = horizontalSpeed;
            state.ballVelocityY = verticalSpeed;
        }

        function resetMatch() {
            state.running = false;
            state.leftScore = 0;
            state.rightScore = 0;
            state.leftPaddleY = (config.height - config.paddleHeight) / 2;
            state.rightPaddleY = (config.height - config.paddleHeight) / 2;
            state.ballVelocityX = 0;
            state.ballVelocityY = 0;
            state.ballX = config.width / 2;
            state.ballY = config.height / 2;
            updateScoreboard();
            setStatus("Druk op start");
        }

        function startMatch() {
            if (state.leftScore >= config.winningScore || state.rightScore >= config.winningScore) {
                state.leftScore = 0;
                state.rightScore = 0;
                updateScoreboard();
            }
            state.running = true;
            state.leftPaddleY = (config.height - config.paddleHeight) / 2;
            state.rightPaddleY = (config.height - config.paddleHeight) / 2;
            resetBall(Math.random() > 0.5 ? 1 : -1);
            setStatus("Wedstrijd bezig");
        }

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function updatePaddles() {
            if (keys.KeyW) {
                state.leftPaddleY -= config.paddleSpeed;
            }
            if (keys.KeyS) {
                state.leftPaddleY += config.paddleSpeed;
            }
            if (keys.ArrowUp) {
                state.rightPaddleY -= config.paddleSpeed;
            }
            if (keys.ArrowDown) {
                state.rightPaddleY += config.paddleSpeed;
            }

            state.leftPaddleY = clamp(state.leftPaddleY, 0, config.height - config.paddleHeight);
            state.rightPaddleY = clamp(state.rightPaddleY, 0, config.height - config.paddleHeight);
        }

        function handlePaddleCollision() {
            const leftPaddleX = 32;
            const rightPaddleX = config.width - 32 - config.paddleWidth;

            const hitsLeftPaddle =
                state.ballX <= leftPaddleX + config.paddleWidth &&
                state.ballX >= leftPaddleX &&
                state.ballY + config.ballSize >= state.leftPaddleY &&
                state.ballY <= state.leftPaddleY + config.paddleHeight;

            const hitsRightPaddle =
                state.ballX + config.ballSize >= rightPaddleX &&
                state.ballX + config.ballSize <= rightPaddleX + config.paddleWidth &&
                state.ballY + config.ballSize >= state.rightPaddleY &&
                state.ballY <= state.rightPaddleY + config.paddleHeight;

            if (hitsLeftPaddle && state.ballVelocityX < 0) {
                const impact = ((state.ballY + config.ballSize / 2) - (state.leftPaddleY + config.paddleHeight / 2)) / (config.paddleHeight / 2);
                state.ballX = leftPaddleX + config.paddleWidth;
                state.ballVelocityX = Math.abs(state.ballVelocityX) + 0.35;
                state.ballVelocityY += impact * 1.8;
            }

            if (hitsRightPaddle && state.ballVelocityX > 0) {
                const impact = ((state.ballY + config.ballSize / 2) - (state.rightPaddleY + config.paddleHeight / 2)) / (config.paddleHeight / 2);
                state.ballX = rightPaddleX - config.ballSize;
                state.ballVelocityX = -(Math.abs(state.ballVelocityX) + 0.35);
                state.ballVelocityY += impact * 1.8;
            }
        }

        function awardPoint(side) {
            if (side === "left") {
                state.leftScore++;
                setStatus("Punt voor links");
                resetBall(1);
            } else {
                state.rightScore++;
                setStatus("Punt voor rechts");
                resetBall(-1);
            }

            updateScoreboard();

            if (state.leftScore >= config.winningScore || state.rightScore >= config.winningScore) {
                state.running = false;
                setStatus(state.leftScore > state.rightScore ? "Links wint de match" : "Rechts wint de match");
                state.ballVelocityX = 0;
                state.ballVelocityY = 0;
            }
        }

        function updateBall() {
            if (!state.running) {
                return;
            }

            state.ballX += state.ballVelocityX;
            state.ballY += state.ballVelocityY;

            if (state.ballY <= 0) {
                state.ballY = 0;
                state.ballVelocityY *= -1;
            }

            if (state.ballY + config.ballSize >= config.height) {
                state.ballY = config.height - config.ballSize;
                state.ballVelocityY *= -1;
            }

            handlePaddleCollision();

            if (state.ballX + config.ballSize < 0) {
                awardPoint("right");
            }

            if (state.ballX > config.width) {
                awardPoint("left");
            }
        }

        function drawNet() {
            ctx.save();
            ctx.fillStyle = "rgba(255,255,255,0.24)";
            for (let y = 12; y < config.height; y += 28) {
                ctx.fillRect((config.width / 2) - 3, y, 6, 16);
            }
            ctx.restore();
        }

        function drawPaddle(x, y) {
            ctx.save();
            ctx.fillStyle = "#f8fafc";
            ctx.shadowColor = "rgba(125, 211, 252, 0.5)";
            ctx.shadowBlur = 14;
            ctx.fillRect(x, y, config.paddleWidth, config.paddleHeight);
            ctx.restore();
        }

        function drawBall() {
            ctx.save();
            ctx.fillStyle = "#f59e0b";
            ctx.shadowColor = "rgba(245, 158, 11, 0.8)";
            ctx.shadowBlur = 18;
            ctx.fillRect(state.ballX, state.ballY, config.ballSize, config.ballSize);
            ctx.restore();
        }

        function draw() {
            ctx.clearRect(0, 0, config.width, config.height);

            ctx.fillStyle = "rgba(8, 17, 27, 0.92)";
            ctx.fillRect(0, 0, config.width, config.height);
            drawNet();
            drawPaddle(32, state.leftPaddleY);
            drawPaddle(config.width - 32 - config.paddleWidth, state.rightPaddleY);
            drawBall();
        }

        function gameLoop() {
            updatePaddles();
            updateBall();
            draw();
            requestAnimationFrame(gameLoop);
        }

        window.addEventListener("keydown", (event) => {
            if (event.code in keys) {
                keys[event.code] = true;
                event.preventDefault();
            }
        });

        window.addEventListener("keyup", (event) => {
            if (event.code in keys) {
                keys[event.code] = false;
                event.preventDefault();
            }
        });

        startBtn.addEventListener("click", () => {
            startMatch();
        });

        resetBtn.addEventListener("click", () => {
            resetMatch();
        });

        resetMatch();
        draw();
        gameLoop();
    </script>
</body>
</html>
