<?php
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>2D Karting Race</title>
    <style>
        :root {
            --bg-a: #0b1220;
            --bg-b: #134e4a;
            --panel: rgba(255,255,255,0.1);
            --line: rgba(255,255,255,0.16);
            --text: #f8fafc;
            --muted: #d1d5db;
            --accent: #f59e0b;
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
                radial-gradient(circle at top, rgba(245,158,11,.2), transparent 28%),
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
            box-shadow: 0 24px 60px rgba(0,0,0,.26);
        }

        .race-panel { padding: 20px; }
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

        .hud-card,
        .card {
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
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            border: 1px solid var(--line);
            background: #0b1c13;
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
        }

        .overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .message {
            max-width: 520px;
            padding: 16px 20px;
            border-radius: 18px;
            text-align: center;
            background: rgba(8,16,24,.74);
            border: 1px solid rgba(255,255,255,.16);
            font-weight: 700;
            line-height: 1.5;
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

        .primary { background: var(--accent); color: var(--accent-ink); }
        .secondary { background: rgba(255,255,255,.1); color: var(--text); border: 1px solid var(--line); }

        @media (max-width: 1080px) {
            .shell { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            body { padding: 12px; }
            .race-panel, .side-panel { padding: 16px; }
            .hud { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel race-panel">
            <p class="eyebrow">Top-down karting</p>
            <h1>2D Karting Race</h1>
            <p class="subtitle">Een top-down racecircuit met CPU-karts, boostpads en 3 ronden. Gebruik <strong>WASD</strong> of de pijltjestoetsen om te sturen, gas te geven en te remmen.</p>

            <div class="hud">
                <div class="hud-card"><div class="hud-label">Snelheid</div><div class="hud-value" id="speedValue">0</div></div>
                <div class="hud-card"><div class="hud-label">Ronde</div><div class="hud-value" id="lapValue">1 / 3</div></div>
                <div class="hud-card"><div class="hud-label">Positie</div><div class="hud-value" id="placeValue">1 / 6</div></div>
                <div class="hud-card"><div class="hud-label">Boost</div><div class="hud-value" id="boostValue">0%</div></div>
            </div>

            <div class="canvas-wrap">
                <canvas id="raceCanvas" width="960" height="640" aria-label="2D kart race circuit"></canvas>
                <div class="overlay">
                    <div class="message" id="messageBox">Druk op start race. Finish na 3 ronden zo hoog mogelijk.</div>
                </div>
            </div>
        </section>

        <aside class="panel side-panel">
            <section class="card">
                <h2 class="card-title">Besturing</h2>
                <p class="detail keys">W / ↑ = gas<br>S / ↓ = remmen<br>A / ← = links draaien<br>D / → = rechts draaien</p>
            </section>

            <section class="card">
                <h2 class="card-title">Spelregels</h2>
                <p class="detail">Blijf op het asfalt, pak boostpads en ontwijk de CPU-karts. Op gras verlies je tractie en snelheid. De finishlijn telt per volledige ronde.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Race-info</h2>
                <p class="detail" id="raceInfoText">Nog niet gestart.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Acties</h2>
                <div class="actions">
                    <button class="primary" type="button" id="startBtn">Start race</button>
                    <button class="secondary" type="button" id="resetBtn">Reset</button>
                </div>
            </section>
        </aside>
    </main>

    <script>
        const canvas = document.getElementById("raceCanvas");
        const ctx = canvas.getContext("2d");
        const speedValueEl = document.getElementById("speedValue");
        const lapValueEl = document.getElementById("lapValue");
        const placeValueEl = document.getElementById("placeValue");
        const boostValueEl = document.getElementById("boostValue");
        const raceInfoTextEl = document.getElementById("raceInfoText");
        const messageBoxEl = document.getElementById("messageBox");
        const startBtn = document.getElementById("startBtn");
        const resetBtn = document.getElementById("resetBtn");

        const TRACK = {
            cx: canvas.width / 2,
            cy: canvas.height / 2 + 10,
            outerA: 360,
            outerB: 240,
            innerA: 205,
            innerB: 110,
            laneA: 282,
            laneB: 175,
            laps: 3,
            boostPads: [
                { angle: 0.35, width: 0.18 },
                { angle: 2.15, width: 0.2 },
                { angle: 4.45, width: 0.16 }
            ]
        };

        const keys = {
            ArrowUp: false,
            ArrowDown: false,
            ArrowLeft: false,
            ArrowRight: false,
            KeyW: false,
            KeyA: false,
            KeyS: false,
            KeyD: false
        };

        const cars = [];
        const player = {
            id: "player",
            color: "#f59e0b",
            angle: -Math.PI / 2,
            progress: 0,
            lap: 1,
            x: 0,
            y: 0,
            heading: 0,
            speed: 0,
            boost: 0,
            finished: false,
            prevCrossValue: 0
        };

        const state = {
            running: false,
            finished: false,
            countdown: 0,
            place: 1,
            lastTimestamp: 0
        };

        function angleDiff(a, b) {
            let diff = a - b;
            while (diff > Math.PI) diff -= Math.PI * 2;
            while (diff < -Math.PI) diff += Math.PI * 2;
            return diff;
        }

        function angleToProgress(angle) {
            const normalized = (angle + Math.PI / 2 + Math.PI * 2) % (Math.PI * 2);
            return normalized / (Math.PI * 2);
        }

        function syncCarPosition(car) {
            car.x = TRACK.cx + Math.cos(car.angle) * TRACK.laneA;
            car.y = TRACK.cy + Math.sin(car.angle) * TRACK.laneB;

            const tangentX = -Math.sin(car.angle) * TRACK.laneA;
            const tangentY = Math.cos(car.angle) * TRACK.laneB;
            car.heading = Math.atan2(tangentY, tangentX);
        }

        function resetCars() {
            cars.length = 0;

            Object.assign(player, {
                angle: -Math.PI / 2,
                progress: 0,
                lap: 1,
                speed: 0,
                boost: 0,
                finished: false,
                prevCrossValue: Math.sin(-Math.PI / 2)
            });
            syncCarPosition(player);
            cars.push(player);

            const colors = ["#fb7185", "#60a5fa", "#34d399", "#a78bfa", "#facc15"];
            for (let i = 0; i < 5; i++) {
                const angle = -Math.PI / 2 - ((i + 1) * 0.16);
                const cpu = {
                    id: `cpu-${i}`,
                    color: colors[i % colors.length],
                    angle,
                    progress: angleToProgress(angle),
                    lap: 1,
                    x: 0,
                    y: 0,
                    heading: 0,
                    speed: 188 + (i * 10),
                    boost: 0,
                    finished: false,
                    prevCrossValue: Math.sin(angle)
                };
                syncCarPosition(cpu);
                cars.push(cpu);
            }
        }

        function updateHud() {
            speedValueEl.textContent = `${Math.round(player.speed)} km/h`;
            lapValueEl.textContent = `${Math.min(player.lap, TRACK.laps)} / ${TRACK.laps}`;
            placeValueEl.textContent = `${state.place} / ${cars.length}`;
            boostValueEl.textContent = `${Math.round(player.boost * 100)}%`;
        }

        function resetRace() {
            state.running = false;
            state.finished = false;
            state.countdown = 0;
            state.place = 1;
            state.lastTimestamp = 0;
            resetCars();
            updateHud();
            raceInfoTextEl.textContent = "Nog niet gestart.";
            messageBoxEl.textContent = "Druk op start race. Finish na 3 ronden zo hoog mogelijk.";
        }

        function startRace() {
            resetRace();
            state.running = true;
            state.countdown = 3;
            messageBoxEl.textContent = "3";
            raceInfoTextEl.textContent = "Race gestart. Countdown loopt.";
        }

        function distanceToTrackEdge(x, y) {
            const dx = x - TRACK.cx;
            const dy = y - TRACK.cy;
            const outer = ((dx * dx) / (TRACK.outerA * TRACK.outerA)) + ((dy * dy) / (TRACK.outerB * TRACK.outerB));
            const inner = ((dx * dx) / (TRACK.innerA * TRACK.innerA)) + ((dy * dy) / (TRACK.innerB * TRACK.innerB));
            return { outer, inner, onTrack: outer <= 1 && inner >= 1 };
        }

        function isOnBoostPad(angle) {
            return TRACK.boostPads.some((pad) => Math.abs(angleDiff(angle, pad.angle)) < pad.width);
        }

        function updatePlayer(dt) {
            if (state.countdown > 0 || state.finished) {
                player.speed *= 0.985;
                return;
            }

            const accelerating = keys.ArrowUp || keys.KeyW;
            const braking = keys.ArrowDown || keys.KeyS;
            const left = keys.ArrowLeft || keys.KeyA;
            const right = keys.ArrowRight || keys.KeyD;

            if (accelerating) player.speed += 190 * dt;
            else player.speed -= 70 * dt;
            if (braking) player.speed -= 160 * dt;

            const turnStrength = (0.9 + (player.speed / 260)) * dt;
            if (left) player.angle -= turnStrength;
            if (right) player.angle += turnStrength;

            const targetHeading = Math.atan2(Math.cos(player.angle) * TRACK.laneB, -Math.sin(player.angle) * TRACK.laneA);
            const headingDrift = angleDiff(player.heading, targetHeading);
            player.angle -= headingDrift * 0.08;

            syncCarPosition(player);

            const trackState = distanceToTrackEdge(player.x, player.y);
            if (!trackState.onTrack) {
                player.speed *= 0.96;
                player.boost = Math.max(0, player.boost - 1.2 * dt);
            }

            if (isOnBoostPad(player.angle) && trackState.onTrack) {
                player.boost = Math.min(1, player.boost + 1.1 * dt);
                player.speed += 240 * dt;
            } else {
                player.boost = Math.max(0, player.boost - 0.45 * dt);
            }

            player.speed = Math.max(0, Math.min(280 + (player.boost * 35), player.speed));
            player.angle += (player.speed / 820) * dt;
            syncCarPosition(player);
            player.progress = angleToProgress(player.angle);
            handleLapProgress(player, "Jij");
        }

        function updateCpuCars(dt) {
            for (const car of cars) {
                if (car.id === "player" || car.finished) {
                    continue;
                }

                const wobble = Math.sin((performance.now() / 1000) + car.progress * 8) * 0.003;
                car.angle += ((car.speed / 860) * dt) + wobble;
                if (isOnBoostPad(car.angle)) {
                    car.boost = Math.min(1, car.boost + 0.8 * dt);
                    car.speed = Math.min(272, car.speed + 22 * dt);
                } else {
                    car.boost = Math.max(0, car.boost - 0.35 * dt);
                    car.speed += Math.sin(car.angle * 3 + car.speed) * 4 * dt;
                    car.speed = Math.max(180, Math.min(252, car.speed));
                }
                syncCarPosition(car);
                car.progress = angleToProgress(car.angle);
                handleLapProgress(car, "CPU");
            }
        }

        function handleLapProgress(car, label) {
            const crossValue = Math.sin(car.angle);
            const closeToFinish = Math.cos(car.angle) > 0.96;
            if (closeToFinish && car.prevCrossValue < 0 && crossValue >= 0) {
                car.lap++;
                if (car.id === "player" && car.lap > TRACK.laps) {
                    finishRace();
                } else if (car.id === "player") {
                    raceInfoTextEl.textContent = `Ronde ${car.lap} gestart.`;
                }
            }
            car.prevCrossValue = crossValue;
        }

        function handleCollisions() {
            for (let i = 0; i < cars.length; i++) {
                for (let j = i + 1; j < cars.length; j++) {
                    const a = cars[i];
                    const b = cars[j];
                    const dx = a.x - b.x;
                    const dy = a.y - b.y;
                    const dist = Math.hypot(dx, dy);
                    if (dist < 28) {
                        const pushX = dx / (dist || 1);
                        const pushY = dy / (dist || 1);
                        a.x += pushX * 2.5;
                        a.y += pushY * 2.5;
                        b.x -= pushX * 2.5;
                        b.y -= pushY * 2.5;
                        a.speed *= 0.985;
                        b.speed *= 0.985;
                        if (a.id === "player" || b.id === "player") {
                            raceInfoTextEl.textContent = "Botsing. Je verloor wat snelheid.";
                        }
                    }
                }
            }
        }

        function updatePlace() {
            const standings = cars.map((car) => ({
                id: car.id,
                progress: ((car.lap - 1) + car.progress)
            })).sort((a, b) => b.progress - a.progress);

            state.place = standings.findIndex((entry) => entry.id === "player") + 1;
        }

        function finishRace() {
            state.finished = true;
            state.running = false;
            player.speed = 0;
            const text = state.place === 1 ? "Je wint de race." : `Race klaar. Je eindigt op plaats ${state.place}.`;
            messageBoxEl.textContent = text;
            raceInfoTextEl.textContent = text;
        }

        function drawTrack() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = "#2e7d32";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "#1b5e20";
            for (let i = 0; i < 24; i++) {
                ctx.beginPath();
                ctx.arc((i * 91) % canvas.width, ((i * 137) % canvas.height), 18 + (i % 3) * 7, 0, Math.PI * 2);
                ctx.fill();
            }

            ctx.fillStyle = "#3f3f46";
            ctx.beginPath();
            ctx.ellipse(TRACK.cx, TRACK.cy, TRACK.outerA, TRACK.outerB, 0, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = "#166534";
            ctx.beginPath();
            ctx.ellipse(TRACK.cx, TRACK.cy, TRACK.innerA, TRACK.innerB, 0, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = "#f8fafc";
            ctx.setLineDash([14, 14]);
            ctx.lineWidth = 4;
            ctx.beginPath();
            ctx.ellipse(TRACK.cx, TRACK.cy, TRACK.laneA, TRACK.laneB, 0, 0, Math.PI * 2);
            ctx.stroke();
            ctx.setLineDash([]);

            ctx.strokeStyle = "#ef4444";
            ctx.lineWidth = 8;
            ctx.beginPath();
            ctx.moveTo(TRACK.cx, TRACK.cy - TRACK.outerB - 20);
            ctx.lineTo(TRACK.cx, TRACK.cy + TRACK.outerB + 20);
            ctx.stroke();

            for (const pad of TRACK.boostPads) {
                ctx.save();
                ctx.translate(TRACK.cx + Math.cos(pad.angle) * TRACK.laneA, TRACK.cy + Math.sin(pad.angle) * TRACK.laneB);
                ctx.rotate(pad.angle + Math.PI / 2);
                ctx.fillStyle = "rgba(34, 211, 238, 0.7)";
                ctx.fillRect(-38, -16, 76, 32);
                ctx.restore();
            }
        }

        function drawCar(car, isPlayer) {
            const angle = car.heading + Math.PI / 2;
            ctx.save();
            ctx.translate(car.x, car.y);
            ctx.rotate(angle);
            ctx.fillStyle = car.color;
            ctx.fillRect(-10, -16, 20, 32);
            ctx.fillStyle = "#111827";
            ctx.fillRect(-6, -6, 12, 14);
            ctx.fillStyle = "#f8fafc";
            ctx.fillRect(-9, -15, 4, 6);
            ctx.fillRect(5, -15, 4, 6);
            if (isPlayer) {
                ctx.strokeStyle = "#fde68a";
                ctx.lineWidth = 3;
                ctx.strokeRect(-12, -18, 24, 36);
            }
            ctx.restore();
        }

        function render() {
            drawTrack();
            const orderedCars = [...cars].sort((a, b) => a.id === "player" ? 1 : b.id === "player" ? -1 : 0);
            for (const car of orderedCars) {
                drawCar(car, car.id === "player");
            }
        }

        function update(dt) {
            if (!state.running && !state.finished) {
                return;
            }

            if (state.countdown > 0) {
                state.countdown -= dt;
                messageBoxEl.textContent = state.countdown > 0.15 ? String(Math.ceil(state.countdown)) : "GO!";
                if (state.countdown <= 0) {
                    state.countdown = 0;
                    raceInfoTextEl.textContent = "Race bezig.";
                }
                return;
            }

            if (!state.finished) {
                updatePlayer(dt);
                updateCpuCars(dt);
                handleCollisions();
                updatePlace();
                messageBoxEl.textContent = `Ronde ${Math.min(player.lap, TRACK.laps)} - Positie ${state.place}`;
                updateHud();
            }
        }

        function frame(timestamp) {
            if (!state.lastTimestamp) {
                state.lastTimestamp = timestamp;
            }
            const dt = Math.min(0.033, (timestamp - state.lastTimestamp) / 1000);
            state.lastTimestamp = timestamp;
            update(dt);
            render();
            requestAnimationFrame(frame);
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
            startRace();
        });

        resetBtn.addEventListener("click", () => {
            resetRace();
        });

        resetRace();
        render();
        requestAnimationFrame(frame);
    </script>
</body>
</html>
