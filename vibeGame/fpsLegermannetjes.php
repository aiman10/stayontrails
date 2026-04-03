<?php
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Target Range FPS</title>
    <style>
        :root {
            --bg-a: #0b1320;
            --bg-b: #1f3b4d;
            --panel: rgba(255,255,255,0.1);
            --line: rgba(255,255,255,0.16);
            --text: #f8fafc;
            --muted: #d1d5db;
            --accent: #f59e0b;
            --accent-ink: #111827;
            --danger: #fca5a5;
            --ok: #86efac;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 18px;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(245,158,11,.18), transparent 28%),
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
            background: #111827;
            cursor: crosshair;
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
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
        .ok { color: var(--ok); }
        .danger { color: var(--danger); }

        @media (max-width: 1080px) {
            .shell { grid-template-columns: 1fr; }
        }

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
            <p class="eyebrow">Arcade target range</p>
            <h1>FPS Target Range</h1>
            <p class="subtitle">Schiet alleen op de legermannetjes. Kinderen en dieren zijn no-shoot targets en kosten punten plus tijdstraf als je ze raakt.</p>

            <div class="hud">
                <div class="hud-card"><div class="hud-label">Score</div><div class="hud-value" id="scoreValue">0</div></div>
                <div class="hud-card"><div class="hud-label">Combo</div><div class="hud-value" id="comboValue">0x</div></div>
                <div class="hud-card"><div class="hud-label">Tijd</div><div class="hud-value" id="timeValue">45</div></div>
                <div class="hud-card"><div class="hud-label">Missies</div><div class="hud-value" id="hitsValue">0</div></div>
            </div>

            <div class="canvas-wrap">
                <canvas id="gameCanvas" width="960" height="600" aria-label="FPS target range"></canvas>
            </div>
        </section>

        <aside class="panel side-panel">
            <section class="card">
                <h2 class="card-title">Regels</h2>
                <p class="detail"><span class="ok">Groene legermannetjes</span> mag je raken. <span class="danger">Kinderen en dieren</span> mag je niet raken. Een foute hit verbreekt je combo, kost score en haalt tijd van de klok. Soldaten die 2 tot 5 seconden blijven staan, schieten terug.</p>
            </section>

            <section class="card">
                <h2 class="card-title">Besturing</h2>
                <p class="detail">Gebruik de muis om te mikken en klik om te schieten. De crosshair volgt je cursor. Druk op start om een nieuwe ronde te beginnen.</p>
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
        const comboValueEl = document.getElementById("comboValue");
        const timeValueEl = document.getElementById("timeValue");
        const hitsValueEl = document.getElementById("hitsValue");
        const statusTextEl = document.getElementById("statusText");
        const startBtn = document.getElementById("startBtn");
        const resetBtn = document.getElementById("resetBtn");
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;

        let audioContext = null;
        let masterGain = null;
        let preferredVoice = null;

        const state = {
            running: false,
            score: 0,
            combo: 0,
            timeLeft: 75,
            hits: 0,
            damageTaken: 0,
            targets: [],
            particles: [],
            crosshairX: canvas.width / 2,
            crosshairY: canvas.height / 2,
            lastSpawn: 0,
            lastTick: 0,
            flashMessage: "Start spel om te beginnen.",
            muzzleFlash: 0,
            vignettePulse: 0
        };

        const TARGET_TYPES = {
            soldier: { color: "#4ade80", label: "Legermannetje", score: 120, shootable: true },
            child: { color: "#fda4af", label: "Kind", score: -180, shootable: false },
            animal: { color: "#93c5fd", label: "Dier", score: -160, shootable: false }
        };

        function randomBetween(min, max) {
            return Math.random() * (max - min) + min;
        }

        function ensureAudio() {
            if (!AudioContextCtor) {
                return false;
            }
            if (!audioContext) {
                audioContext = new AudioContextCtor();
                masterGain = audioContext.createGain();
                masterGain.gain.value = 0.18;
                masterGain.connect(audioContext.destination);
            }
            if (audioContext.state === "suspended") {
                audioContext.resume().catch(() => {});
            }
            return true;
        }

        function playTone({ frequency, type = "sine", duration = 0.12, volume = 0.25, sweepTo = null }) {
            if (!ensureAudio() || !masterGain) {
                return;
            }
            const now = audioContext.currentTime;
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.type = type;
            oscillator.frequency.setValueAtTime(frequency, now);
            if (sweepTo !== null) {
                oscillator.frequency.exponentialRampToValueAtTime(Math.max(40, sweepTo), now + duration);
            }
            gainNode.gain.setValueAtTime(0.0001, now);
            gainNode.gain.exponentialRampToValueAtTime(volume, now + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, now + duration);
            oscillator.connect(gainNode);
            gainNode.connect(masterGain);
            oscillator.start(now);
            oscillator.stop(now + duration + 0.02);
        }

        function playNoiseBurst(duration = 0.08, volume = 0.18) {
            if (!ensureAudio() || !masterGain) {
                return;
            }
            const bufferSize = Math.max(1, Math.floor(audioContext.sampleRate * duration));
            const buffer = audioContext.createBuffer(1, bufferSize, audioContext.sampleRate);
            const channel = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) {
                channel[i] = (Math.random() * 2 - 1) * (1 - i / bufferSize);
            }
            const source = audioContext.createBufferSource();
            const filter = audioContext.createBiquadFilter();
            const gainNode = audioContext.createGain();
            filter.type = "bandpass";
            filter.frequency.value = 900;
            gainNode.gain.value = volume;
            source.buffer = buffer;
            source.connect(filter);
            filter.connect(gainNode);
            gainNode.connect(masterGain);
            source.start();
        }

        function loadPreferredVoice() {
            if (!("speechSynthesis" in window)) {
                return;
            }
            const voices = window.speechSynthesis.getVoices();
            if (!voices.length) {
                return;
            }
            preferredVoice = voices.find((voice) => voice.lang.toLowerCase().startsWith("nl")) || voices[0];
        }

        function speakText(text, rate = 1) {
            if (!("speechSynthesis" in window)) {
                return;
            }
            loadPreferredVoice();
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = preferredVoice?.lang || "nl-NL";
            utterance.rate = rate;
            utterance.pitch = 1;
            utterance.volume = 0.9;
            if (preferredVoice) {
                utterance.voice = preferredVoice;
            }
            window.speechSynthesis.speak(utterance);
        }

        function playSoundEffect(effect) {
            switch (effect) {
                case "start":
                    playTone({ frequency: 440, type: "triangle", duration: 0.09, volume: 0.16, sweepTo: 620 });
                    window.setTimeout(() => playTone({ frequency: 660, type: "triangle", duration: 0.12, volume: 0.18, sweepTo: 880 }), 90);
                    break;
                case "shot":
                    playNoiseBurst(0.06, 0.12);
                    playTone({ frequency: 160, type: "square", duration: 0.08, volume: 0.12, sweepTo: 90 });
                    break;
                case "hit":
                    playTone({ frequency: 720, type: "triangle", duration: 0.08, volume: 0.18, sweepTo: 1040 });
                    break;
                case "penalty":
                    playTone({ frequency: 260, type: "sawtooth", duration: 0.18, volume: 0.16, sweepTo: 140 });
                    break;
                case "enemyFire":
                    playNoiseBurst(0.08, 0.16);
                    playTone({ frequency: 210, type: "square", duration: 0.14, volume: 0.14, sweepTo: 120 });
                    break;
                case "miss":
                    playTone({ frequency: 300, type: "sine", duration: 0.07, volume: 0.08, sweepTo: 180 });
                    break;
                case "finish":
                    playTone({ frequency: 392, type: "triangle", duration: 0.14, volume: 0.15, sweepTo: 587 });
                    window.setTimeout(() => playTone({ frequency: 587, type: "triangle", duration: 0.18, volume: 0.18, sweepTo: 784 }), 120);
                    break;
            }
        }

        function resetGame() {
            state.running = false;
            state.score = 0;
            state.combo = 0;
            state.timeLeft = 75;
            state.hits = 0;
            state.damageTaken = 0;
            state.targets = [];
            state.particles = [];
            state.lastSpawn = 0;
            state.lastTick = 0;
            state.flashMessage = "Druk op start spel.";
            state.muzzleFlash = 0;
            state.vignettePulse = 0;
            if ("speechSynthesis" in window) {
                window.speechSynthesis.cancel();
            }
            updateHud();
            statusTextEl.textContent = "Druk op start spel.";
        }

        function startGame() {
            resetGame();
            ensureAudio();
            state.running = true;
            state.flashMessage = "Schiet alleen op legermannetjes.";
            statusTextEl.textContent = "Ronde bezig.";
            playSoundEffect("start");
            speakText("Spel gestart.", 1.02);
        }

        function updateHud() {
            scoreValueEl.textContent = String(state.score);
            comboValueEl.textContent = `${state.combo}x`;
            timeValueEl.textContent = String(Math.max(0, Math.ceil(state.timeLeft)));
            hitsValueEl.textContent = String(state.hits);
        }

        function spawnTarget() {
            const roll = Math.random();
            let type = "soldier";
            if (roll > 0.76 && roll <= 0.88) type = "animal";
            if (roll > 0.88) type = "child";

            const depth = randomBetween(0.55, 1.25);
            const scale = 1 / depth;
            const life = randomBetween(2.2, 5.4);
            state.targets.push({
                id: `${performance.now()}-${Math.random()}`,
                type,
                x: randomBetween(140, canvas.width - 140),
                y: randomBetween(230, canvas.height - 90),
                scale,
                driftX: randomBetween(-18, 18),
                bob: randomBetween(0, Math.PI * 2),
                life,
                age: 0,
                returnFireAt: type === "soldier" ? randomBetween(2, Math.min(5, life - 0.25)) : null,
                firing: false,
                hit: false
            });
        }

        function updateTargets(dt) {
            state.targets = state.targets.filter((target) => {
                target.life -= dt;
                target.age += dt;
                target.x += target.driftX * dt;
                target.bob += dt * (1.8 + target.scale);
                target.y += Math.sin(target.bob) * 8 * dt;
                if (target.x < 90 || target.x > canvas.width - 90) {
                    target.driftX *= -1;
                }
                if (target.type === "soldier" && !target.firing && target.returnFireAt !== null && target.age >= target.returnFireAt) {
                    target.firing = true;
                    triggerReturnFire(target);
                }
                return target.life > 0 && !target.hit;
            });
        }

        function triggerReturnFire(target) {
            state.combo = 0;
            state.damageTaken++;
            state.timeLeft = Math.max(0, state.timeLeft - 3);
            state.flashMessage = "Je wordt beschoten. Dekking zoeken.";
            statusTextEl.textContent = "Een legermannetje schoot terug.";
            state.vignettePulse = 1.4;
            spawnParticles(target.x, target.y - (18 * target.scale), "rgba(251,191,36,0.95)", 10);
            spawnParticles(canvas.width * 0.56, canvas.height * 0.72, "rgba(248,113,113,0.85)", 18);
            playSoundEffect("enemyFire");
            speakText("Er wordt geschoten.", 1.03);
        }

        function spawnParticles(x, y, color, amount) {
            for (let i = 0; i < amount; i++) {
                state.particles.push({
                    x,
                    y,
                    vx: randomBetween(-120, 120),
                    vy: randomBetween(-140, 40),
                    life: randomBetween(0.2, 0.55),
                    maxLife: randomBetween(0.2, 0.55),
                    radius: randomBetween(2, 6),
                    color
                });
            }
        }

        function updateParticles(dt) {
            state.particles = state.particles.filter((particle) => {
                particle.life -= dt;
                particle.x += particle.vx * dt;
                particle.y += particle.vy * dt;
                particle.vy += 260 * dt;
                return particle.life > 0;
            });

            state.muzzleFlash = Math.max(0, state.muzzleFlash - (dt * 5));
            state.vignettePulse = Math.max(0, state.vignettePulse - (dt * 1.6));
        }

        function drawBackground() {
            const sky = ctx.createLinearGradient(0, 0, 0, canvas.height);
            sky.addColorStop(0, "#7ec8ff");
            sky.addColorStop(0.3, "#bde7ff");
            sky.addColorStop(0.58, "#d6f4ff");
            sky.addColorStop(0.58, "#769f56");
            sky.addColorStop(1, "#374f24");
            ctx.fillStyle = sky;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            for (let i = 0; i < 6; i++) {
                const cloudX = 120 + (i * 150);
                const cloudY = 80 + ((i % 2) * 35);
                ctx.fillStyle = "rgba(255,255,255,0.65)";
                ctx.beginPath();
                ctx.arc(cloudX, cloudY, 28, 0, Math.PI * 2);
                ctx.arc(cloudX + 24, cloudY - 10, 22, 0, Math.PI * 2);
                ctx.arc(cloudX + 48, cloudY, 26, 0, Math.PI * 2);
                ctx.fill();
            }

            ctx.fillStyle = "#5f6b76";
            ctx.fillRect(0, 340, canvas.width, 20);
            ctx.fillStyle = "#56616d";
            ctx.fillRect(0, 360, canvas.width, 64);

            const ground = ctx.createLinearGradient(0, 420, 0, canvas.height);
            ground.addColorStop(0, "#475569");
            ground.addColorStop(1, "#1f2937");
            ctx.fillStyle = ground;
            ctx.fillRect(0, 420, canvas.width, 180);

            for (let i = 0; i < 9; i++) {
                const hillX = i * 140;
                ctx.fillStyle = i % 2 === 0 ? "#5f9a2d" : "#44741f";
                ctx.beginPath();
                ctx.arc(hillX, 360, 120, Math.PI, 0);
                ctx.fill();
            }

            ctx.fillStyle = "rgba(255,255,255,0.08)";
            for (let i = 0; i < 10; i++) {
                ctx.fillRect(60 + i * 92, 448, 42, 8);
            }

            for (let i = 0; i < 8; i++) {
                const postX = 30 + (i * 120);
                ctx.fillStyle = "#9ca3af";
                ctx.fillRect(postX, 294, 8, 126);
                ctx.fillStyle = "rgba(15,23,42,0.28)";
                ctx.fillRect(postX + 10, 300, 72, 12);
            }

            ctx.fillStyle = "rgba(255, 191, 36, 0.12)";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        function drawTarget(target) {
            const info = TARGET_TYPES[target.type];
            const x = target.x;
            const y = target.y;
            const s = 44 * target.scale;

            ctx.save();
            ctx.translate(x, y);
            ctx.shadowColor = info.color;
            ctx.shadowBlur = 18 * target.scale;

            const shadow = ctx.createRadialGradient(0, s * 0.28, 2, 0, s * 0.28, s * 0.9);
            shadow.addColorStop(0, "rgba(15,23,42,0.5)");
            shadow.addColorStop(1, "rgba(15,23,42,0)");
            ctx.fillStyle = shadow;
            ctx.beginPath();
            ctx.ellipse(0, s * 0.26, s * 0.72, s * 0.22, 0, 0, Math.PI * 2);
            ctx.fill();

            const bodyGradient = ctx.createLinearGradient(0, -s, 0, s * 0.5);
            bodyGradient.addColorStop(0, "#ffffff");
            bodyGradient.addColorStop(0.02, info.color);
            bodyGradient.addColorStop(1, target.type === "soldier" ? "#14532d" : target.type === "child" ? "#be185d" : "#1d4ed8");
            ctx.fillStyle = bodyGradient;

            if (target.type === "soldier") {
                ctx.fillStyle = "#d6c3a5";
                ctx.beginPath();
                ctx.arc(0, -s * 1.05, s * 0.21, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = bodyGradient;
                ctx.fillRect(-s * 0.18, -s * 0.9, s * 0.36, s * 0.65);
                ctx.fillRect(-s * 0.3, -s * 0.3, s * 0.6, s * 0.22);
                ctx.fillRect(-s * 0.12, -s * 0.15, s * 0.08, s * 0.45);
                ctx.fillRect(s * 0.04, -s * 0.15, s * 0.08, s * 0.45);
                ctx.fillStyle = "#2f3d27";
                ctx.fillRect(-s * 0.18, -s * 1.16, s * 0.36, s * 0.09);
                ctx.fillStyle = "#334155";
                ctx.fillRect(s * 0.16, -s * 0.58, s * 0.42, s * 0.08);
                ctx.fillStyle = "#111827";
                ctx.fillRect(s * 0.56, -s * 0.6, s * 0.08, s * 0.12);
                if (target.returnFireAt !== null && target.age > 1.5) {
                    ctx.fillStyle = target.firing ? "#fb7185" : "#fde68a";
                    ctx.beginPath();
                    ctx.arc(s * 0.66, -s * 0.56, s * 0.08, 0, Math.PI * 2);
                    ctx.fill();
                }
            } else if (target.type === "child") {
                ctx.fillStyle = "#fde2e2";
                ctx.beginPath();
                ctx.arc(0, -s * 0.9, s * 0.2, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = bodyGradient;
                ctx.fillRect(-s * 0.16, -s * 0.7, s * 0.32, s * 0.46);
                ctx.fillRect(-s * 0.4, -s * 0.54, s * 0.24, s * 0.08);
                ctx.fillRect(s * 0.16, -s * 0.54, s * 0.24, s * 0.08);
                ctx.fillRect(-s * 0.12, -s * 0.24, s * 0.08, s * 0.34);
                ctx.fillRect(s * 0.04, -s * 0.24, s * 0.08, s * 0.34);
                ctx.fillStyle = "#ef4444";
                ctx.fillRect(-s * 0.24, -s * 0.64, s * 0.48, s * 0.08);
            } else {
                ctx.fillStyle = "#dbeafe";
                ctx.beginPath();
                ctx.ellipse(0, -s * 0.42, s * 0.34, s * 0.22, 0, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = bodyGradient;
                ctx.fillRect(-s * 0.42, -s * 0.46, s * 0.62, s * 0.18);
                ctx.fillRect(-s * 0.32, -s * 0.14, s * 0.08, s * 0.28);
                ctx.fillRect(-s * 0.08, -s * 0.14, s * 0.08, s * 0.28);
                ctx.fillRect(s * 0.12, -s * 0.14, s * 0.08, s * 0.28);
                ctx.fillRect(s * 0.28, -s * 0.14, s * 0.08, s * 0.28);
                ctx.fillRect(s * 0.16, -s * 0.56, s * 0.22, s * 0.08);
                ctx.fillStyle = "#1f2937";
                ctx.fillRect(-s * 0.54, -s * 0.52, s * 0.16, s * 0.1);
            }

            ctx.shadowBlur = 0;
            ctx.fillStyle = "rgba(255,255,255,0.22)";
            ctx.fillRect(-s * 0.16, -s * 0.72, s * 0.09, s * 0.26);
            ctx.restore();
        }

        function drawParticles() {
            for (const particle of state.particles) {
                ctx.save();
                ctx.globalAlpha = particle.life / particle.maxLife;
                ctx.fillStyle = particle.color;
                ctx.beginPath();
                ctx.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }
        }

        function drawWeaponOverlay() {
            ctx.save();
            const gunGradient = ctx.createLinearGradient(canvas.width * 0.58, canvas.height * 0.7, canvas.width, canvas.height);
            gunGradient.addColorStop(0, "#4b5563");
            gunGradient.addColorStop(1, "#111827");
            ctx.fillStyle = gunGradient;
            ctx.beginPath();
            ctx.moveTo(canvas.width * 0.78, canvas.height);
            ctx.lineTo(canvas.width * 0.68, canvas.height * 0.84);
            ctx.lineTo(canvas.width * 0.74, canvas.height * 0.74);
            ctx.lineTo(canvas.width * 0.88, canvas.height * 0.8);
            ctx.lineTo(canvas.width, canvas.height);
            ctx.closePath();
            ctx.fill();

            ctx.fillStyle = "#1f2937";
            ctx.fillRect(canvas.width * 0.74, canvas.height * 0.72, canvas.width * 0.2, 18);
            ctx.fillRect(canvas.width * 0.83, canvas.height * 0.76, 28, 72);

            if (state.muzzleFlash > 0) {
                ctx.globalAlpha = state.muzzleFlash;
                ctx.fillStyle = "#fde68a";
                ctx.beginPath();
                ctx.moveTo(canvas.width * 0.96, canvas.height * 0.74);
                ctx.lineTo(canvas.width * 0.9, canvas.height * 0.7);
                ctx.lineTo(canvas.width * 0.94, canvas.height * 0.64);
                ctx.lineTo(canvas.width * 0.88, canvas.height * 0.62);
                ctx.lineTo(canvas.width * 0.98, canvas.height * 0.56);
                ctx.lineTo(canvas.width, canvas.height * 0.66);
                ctx.lineTo(canvas.width, canvas.height * 0.76);
                ctx.closePath();
                ctx.fill();
            }
            ctx.restore();
        }

        function drawCrosshair() {
            const x = state.crosshairX;
            const y = state.crosshairY;
            ctx.save();
            ctx.strokeStyle = "rgba(248,250,252,0.95)";
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(x - 22, y);
            ctx.lineTo(x - 8, y);
            ctx.moveTo(x + 8, y);
            ctx.lineTo(x + 22, y);
            ctx.moveTo(x, y - 22);
            ctx.lineTo(x, y - 8);
            ctx.moveTo(x, y + 8);
            ctx.lineTo(x, y + 22);
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(x, y, 7, 0, Math.PI * 2);
            ctx.stroke();
            ctx.globalAlpha = 0.3;
            ctx.beginPath();
            ctx.arc(x, y, 16, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }

        function drawOverlayText() {
            ctx.save();
            ctx.fillStyle = "rgba(15,23,42,0.58)";
            ctx.fillRect(20, 20, 470, 48);
            ctx.fillStyle = "#f8fafc";
            ctx.font = "bold 18px Verdana";
            ctx.fillText(state.flashMessage, 32, 47);
            ctx.restore();
        }

        function drawVignette() {
            const vignette = ctx.createRadialGradient(
                canvas.width / 2,
                canvas.height / 2,
                canvas.height * 0.15,
                canvas.width / 2,
                canvas.height / 2,
                canvas.height * 0.72
            );
            vignette.addColorStop(0, "rgba(0,0,0,0)");
            vignette.addColorStop(0.75, "rgba(0,0,0,0.18)");
            vignette.addColorStop(1, `rgba(0,0,0,${0.48 + state.vignettePulse * 0.24})`);
            ctx.fillStyle = vignette;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        function render() {
            drawBackground();
            state.targets.sort((a, b) => a.scale - b.scale).forEach(drawTarget);
            drawParticles();
            drawWeaponOverlay();
            drawCrosshair();
            drawOverlayText();
            drawVignette();
        }

        function shoot(x, y) {
            if (!state.running) {
                return;
            }

            ensureAudio();
            playSoundEffect("shot");

            let hitSomething = false;
            for (let i = state.targets.length - 1; i >= 0; i--) {
                const target = state.targets[i];
                const size = 38 * target.scale;
                const hit = x >= target.x - size && x <= target.x + size && y >= target.y - (size * 1.2) && y <= target.y + (size * 0.6);
                if (!hit) {
                    continue;
                }

                hitSomething = true;
                target.hit = true;
                const info = TARGET_TYPES[target.type];
                state.muzzleFlash = 1;
                state.vignettePulse = 1;

                if (info.shootable) {
                    state.combo++;
                    state.hits++;
                    state.score += info.score + (state.combo * 15);
                    state.flashMessage = `Raak. ${info.label} uitgeschakeld.`;
                    statusTextEl.textContent = "Goede target geraakt.";
                    spawnParticles(target.x, target.y - (18 * target.scale), "rgba(250,204,21,0.95)", 12);
                    playSoundEffect("hit");
                } else {
                    state.combo = 0;
                    state.score = Math.max(0, state.score + info.score);
                    state.timeLeft = Math.max(0, state.timeLeft - 4);
                    state.flashMessage = `Fout. ${info.label} is verboden target.`;
                    statusTextEl.textContent = "Verboden target geraakt.";
                    spawnParticles(target.x, target.y - (12 * target.scale), "rgba(248,113,113,0.95)", 16);
                    playSoundEffect("penalty");
                }
                break;
            }

            if (!hitSomething) {
                state.combo = 0;
                state.flashMessage = "Mis.";
                statusTextEl.textContent = "Je miste.";
                state.muzzleFlash = 0.85;
                spawnParticles(x, y, "rgba(255,255,255,0.7)", 8);
                playSoundEffect("miss");
            }

            updateHud();
        }

        function finishGame() {
            state.running = false;
            state.flashMessage = `Tijd om. Eindscore ${state.score}.`;
            statusTextEl.textContent = `Ronde klaar. Score ${state.score}.`;
            playSoundEffect("finish");
            speakText(`Tijd om. Eindscore ${state.score}.`, 0.98);
        }

        function update(dt, timestamp) {
            updateParticles(dt);

            if (!state.running) {
                return;
            }

            state.timeLeft -= dt;
            if (state.timeLeft <= 0) {
                state.timeLeft = 0;
                updateHud();
                finishGame();
                return;
            }

            if (timestamp - state.lastSpawn > 650) {
                spawnTarget();
                state.lastSpawn = timestamp;
            }

            updateTargets(dt);
            updateHud();
        }

        function frame(timestamp) {
            if (!state.lastTick) {
                state.lastTick = timestamp;
            }
            const dt = Math.min(0.033, (timestamp - state.lastTick) / 1000);
            state.lastTick = timestamp;
            update(dt, timestamp);
            render();
            requestAnimationFrame(frame);
        }

        canvas.addEventListener("mousemove", (event) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            state.crosshairX = (event.clientX - rect.left) * scaleX;
            state.crosshairY = (event.clientY - rect.top) * scaleY;
        });

        canvas.addEventListener("click", (event) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const x = (event.clientX - rect.left) * scaleX;
            const y = (event.clientY - rect.top) * scaleY;
            shoot(x, y);
        });

        startBtn.addEventListener("click", () => {
            startGame();
        });

        resetBtn.addEventListener("click", () => {
            resetGame();
        });

        if ("speechSynthesis" in window) {
            loadPreferredVoice();
            window.speechSynthesis.addEventListener("voiceschanged", loadPreferredVoice);
        }

        resetGame();
        render();
        requestAnimationFrame(frame);
    </script>
</body>
</html>
