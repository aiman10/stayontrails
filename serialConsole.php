<?php
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browser Serial Console</title>
    <style>
        :root {
            --bg-a: #08111b;
            --bg-b: #16324f;
            --panel: rgba(255, 255, 255, 0.08);
            --line: rgba(255, 255, 255, 0.16);
            --text: #eff6ff;
            --muted: #cbd5e1;
            --accent: #fbbf24;
            --accent-ink: #111827;
            --ok: #86efac;
            --warn: #fca5a5;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 20px;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(251, 191, 36, 0.18), transparent 26%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
        }

        .shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .hero,
        .grid > section,
        .grid > aside {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            backdrop-filter: blur(12px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.24);
        }

        .hero {
            padding: 28px;
            margin-bottom: 18px;
        }

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
            margin: 12px 0 0;
            max-width: 760px;
            color: var(--muted);
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
        }

        .grid > section,
        .grid > aside {
            padding: 20px;
        }

        .section-title {
            margin: 0 0 14px;
            font-size: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        label {
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        select,
        input,
        textarea,
        button {
            border-radius: 14px;
            border: 1px solid var(--line);
            font: inherit;
        }

        select,
        input,
        textarea {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        button {
            padding: 12px 16px;
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
        }

        .status {
            margin-top: 16px;
            padding: 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.08);
            min-height: 54px;
            line-height: 1.5;
        }

        .status.ok {
            border-color: rgba(134, 239, 172, 0.42);
            color: var(--ok);
        }

        .status.warn {
            border-color: rgba(252, 165, 165, 0.42);
            color: var(--warn);
        }

        .terminal {
            height: 420px;
            overflow: auto;
            padding: 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(2, 6, 23, 0.7);
            font-family: Consolas, "Courier New", monospace;
            font-size: 14px;
            line-height: 1.55;
            white-space: pre-wrap;
        }

        .helper {
            margin-top: 10px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .port-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 14px;
        }

        .port-item {
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.06);
            line-height: 1.5;
        }

        .port-item strong {
            display: block;
            margin-bottom: 4px;
        }

        .pill {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 12px;
        }

        @media (max-width: 920px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <p class="eyebrow">Client Serial</p>
            <h1>Browser Serial Console</h1>
            <p class="subtitle">Deze pagina gebruikt de Web Serial API van de browser. Daardoor verbind je rechtstreeks met de COM-poort van de clientmachine, niet met de server waar PHP draait.</p>
        </section>

        <section class="grid">
            <section>
                <h2 class="section-title">Verbinden En Verzenden</h2>

                <div class="field">
                    <label for="baudrateSelect">Baudrate</label>
                    <select id="baudrateSelect">
                        <?php foreach ([110, 300, 1200, 2400, 4800, 9600, 14400, 19200, 38400, 57600, 115200, 230400] as $baud): ?>
                            <option value="<?php echo h((string)$baud); ?>"<?php echo $baud === 9600 ? ' selected' : ''; ?>><?php echo h((string)$baud); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="lineEndingSelect">Line ending</label>
                    <select id="lineEndingSelect">
                        <option value="">Geen</option>
                        <option value="\n">LF (\n)</option>
                        <option value="\r\n" selected>CRLF (\r\n)</option>
                        <option value="\r">CR (\r)</option>
                    </select>
                </div>

                <div class="field">
                    <label for="commandInput">Commando</label>
                    <textarea id="commandInput" placeholder="Bijvoorbeeld: STATUS"></textarea>
                </div>

                <div class="actions">
                    <button class="primary" id="connectBtn" type="button">Connecteer Poort</button>
                    <button class="secondary" id="disconnectBtn" type="button">Verbreek Verbinding</button>
                    <button class="secondary" id="sendBtn" type="button">Verstuur Commando</button>
                    <button class="secondary" id="refreshKnownPortsBtn" type="button">Toon Bekende Poorten</button>
                </div>

                <div id="statusBox" class="status">Wacht op verbinding.</div>
                <div id="knownPorts" class="port-list"></div>
                <p class="helper">Dit werkt alleen in browsers met Web Serial ondersteuning, meestal Chromium-gebaseerde browsers via `https://` of `http://localhost`. Op Android krijg je meestal geen Windows `COM`-namen te zien; je ziet daar alleen browser-toegankelijke serial devices zoals USB- of Bluetooth-serial apparaten.</p>
            </section>

            <aside>
                <div class="pill" id="connectionState">Niet verbonden</div>
                <h2 class="section-title">Ontvangen Data</h2>
                <div id="terminalOutput" class="terminal">Nog geen data ontvangen.</div>
                <div class="actions" style="margin-top: 12px;">
                    <button class="secondary" id="clearLogBtn" type="button">Leeg log</button>
                </div>
            </aside>
        </section>
    </main>

    <script>
        const baudrateSelectEl = document.getElementById("baudrateSelect");
        const lineEndingSelectEl = document.getElementById("lineEndingSelect");
        const commandInputEl = document.getElementById("commandInput");
        const connectBtn = document.getElementById("connectBtn");
        const disconnectBtn = document.getElementById("disconnectBtn");
        const sendBtn = document.getElementById("sendBtn");
        const clearLogBtn = document.getElementById("clearLogBtn");
        const refreshKnownPortsBtn = document.getElementById("refreshKnownPortsBtn");
        const knownPortsEl = document.getElementById("knownPorts");
        const statusBoxEl = document.getElementById("statusBox");
        const terminalOutputEl = document.getElementById("terminalOutput");
        const connectionStateEl = document.getElementById("connectionState");

        let port = null;
        let reader = null;
        let writer = null;
        let keepReading = false;
        let textDecoder = null;
        let textEncoder = new TextEncoder();

        function setStatus(message, tone = "") {
            statusBoxEl.textContent = message;
            statusBoxEl.className = `status${tone ? ` ${tone}` : ""}`;
        }

        function setConnectionState(message) {
            connectionStateEl.textContent = message;
        }

        function appendToTerminal(text) {
            if (terminalOutputEl.textContent === "Nog geen data ontvangen.") {
                terminalOutputEl.textContent = "";
            }
            terminalOutputEl.textContent += text;
            terminalOutputEl.scrollTop = terminalOutputEl.scrollHeight;
        }

        function describePortInfo(portInfo, index) {
            const usbVendorId = portInfo.usbVendorId ? `USB vendor: ${portInfo.usbVendorId}` : null;
            const usbProductId = portInfo.usbProductId ? `USB product: ${portInfo.usbProductId}` : null;
            const bluetoothServiceClassId = portInfo.bluetoothServiceClassId
                ? `Bluetooth service: ${portInfo.bluetoothServiceClassId}`
                : null;

            const details = [usbVendorId, usbProductId, bluetoothServiceClassId].filter(Boolean);
            return `
                <div class="port-item">
                    <strong>Poort ${index + 1}</strong>
                    <div>${details.length ? details.join(" | ") : "Geen extra browser-info beschikbaar."}</div>
                </div>
            `;
        }

        async function renderKnownPorts() {
            if (!("serial" in navigator)) {
                knownPortsEl.innerHTML = "";
                return;
            }

            try {
                const ports = await navigator.serial.getPorts();
                if (!ports.length) {
                    knownPortsEl.innerHTML = `
                        <div class="port-item">
                            <strong>Geen bekende poorten</strong>
                            <div>De browser heeft nog geen eerder geautoriseerde serial poorten.</div>
                        </div>
                    `;
                    return;
                }

                knownPortsEl.innerHTML = ports.map((knownPort, index) => {
                    const info = typeof knownPort.getInfo === "function" ? knownPort.getInfo() : {};
                    return describePortInfo(info, index);
                }).join("");
            } catch (error) {
                knownPortsEl.innerHTML = `
                    <div class="port-item">
                        <strong>Poorten konden niet geladen worden</strong>
                        <div>${error.message}</div>
                    </div>
                `;
            }
        }

        async function connectPort() {
            if (!("serial" in navigator)) {
                setStatus("Deze browser ondersteunt Web Serial niet.", "warn");
                return;
            }

            try {
                port = await navigator.serial.requestPort();
                await port.open({
                    baudRate: Number(baudrateSelectEl.value),
                    dataBits: 8,
                    stopBits: 1,
                    parity: "none",
                    flowControl: "none"
                });

                textDecoder = new TextDecoder();
                keepReading = true;
                setConnectionState("Verbonden");
                setStatus("Seriële poort verbonden.", "ok");
                startReading().catch(() => {
                    setStatus("Lezen van seriële data is gestopt.", "warn");
                });
            } catch (error) {
                setStatus(`Verbinding mislukt: ${error.message}`, "warn");
            }
        }

        async function disconnectPort() {
            keepReading = false;

            try {
                if (reader) {
                    await reader.cancel();
                    reader.releaseLock();
                    reader = null;
                }
            } catch {}

            try {
                if (writer) {
                    writer.releaseLock();
                    writer = null;
                }
            } catch {}

            try {
                if (port) {
                    await port.close();
                }
            } catch {}

            port = null;
            setConnectionState("Niet verbonden");
            setStatus("Verbinding verbroken.");
        }

        async function startReading() {
            while (port && keepReading && port.readable) {
                reader = port.readable.getReader();
                try {
                    while (keepReading) {
                        const { value, done } = await reader.read();
                        if (done) {
                            break;
                        }
                        if (value) {
                            appendToTerminal(textDecoder.decode(value, { stream: true }));
                        }
                    }
                } finally {
                    reader.releaseLock();
                    reader = null;
                }
            }
        }

        function resolveLineEnding(rawValue) {
            if (rawValue === "\\n") return "\n";
            if (rawValue === "\\r") return "\r";
            if (rawValue === "\\r\\n") return "\r\n";
            return "";
        }

        async function sendCommand() {
            if (!port || !port.writable) {
                setStatus("Er is geen actieve seriële verbinding.", "warn");
                return;
            }

            const commandText = commandInputEl.value;
            if (!commandText.trim()) {
                setStatus("Voer eerst een commando in.", "warn");
                return;
            }

            const lineEnding = resolveLineEnding(lineEndingSelectEl.value);
            const payload = `${commandText}${lineEnding}`;

            try {
                writer = port.writable.getWriter();
                await writer.write(textEncoder.encode(payload));
                appendToTerminal(`\n> ${commandText}\n`);
                setStatus("Commando verzonden.", "ok");
            } catch (error) {
                setStatus(`Verzenden mislukt: ${error.message}`, "warn");
            } finally {
                if (writer) {
                    writer.releaseLock();
                    writer = null;
                }
            }
        }

        connectBtn.addEventListener("click", () => {
            connectPort().catch((error) => {
                setStatus(`Verbinding mislukt: ${error.message}`, "warn");
            });
        });

        disconnectBtn.addEventListener("click", () => {
            disconnectPort().catch((error) => {
                setStatus(`Verbreken mislukt: ${error.message}`, "warn");
            });
        });

        sendBtn.addEventListener("click", () => {
            sendCommand().catch((error) => {
                setStatus(`Verzenden mislukt: ${error.message}`, "warn");
            });
        });

        clearLogBtn.addEventListener("click", () => {
            terminalOutputEl.textContent = "Nog geen data ontvangen.";
        });

        refreshKnownPortsBtn.addEventListener("click", () => {
            renderKnownPorts().catch(() => {});
        });

        window.addEventListener("beforeunload", () => {
            if (port) {
                disconnectPort().catch(() => {});
            }
        });

        if (!("serial" in navigator)) {
            setStatus("Web Serial wordt niet ondersteund in deze browser.", "warn");
        }

        renderKnownPorts().catch(() => {});
    </script>
</body>
</html>
