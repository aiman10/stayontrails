<?php
session_start();

$choices = [
    'schaar' => 'Schaar',
    'steen' => 'Steen',
    'papier' => 'Papier',
];

if (!isset($_SESSION['ssp_score']) || !is_array($_SESSION['ssp_score'])) {
    $_SESSION['ssp_score'] = [
        'speler' => 0,
        'computer' => 0,
        'gelijk' => 0,
    ];
}

$playerChoice = null;
$computerChoice = null;
$resultMessage = 'Kies schaar, steen of papier om te beginnen.';
$resultTone = 'neutral';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_score'])) {
        $_SESSION['ssp_score'] = [
            'speler' => 0,
            'computer' => 0,
            'gelijk' => 0,
        ];
        $resultMessage = 'De score is gereset. Start een nieuwe ronde.';
    } else {
        $submittedChoice = isset($_POST['keuze']) ? (string)$_POST['keuze'] : '';

        if (array_key_exists($submittedChoice, $choices)) {
            $playerChoice = $submittedChoice;
            $computerChoice = array_rand($choices);

            if ($playerChoice === $computerChoice) {
                $_SESSION['ssp_score']['gelijk']++;
                $resultMessage = 'Gelijkspel. Jullie kozen hetzelfde.';
                $resultTone = 'draw';
            } elseif (
                ($playerChoice === 'schaar' && $computerChoice === 'papier') ||
                ($playerChoice === 'steen' && $computerChoice === 'schaar') ||
                ($playerChoice === 'papier' && $computerChoice === 'steen')
            ) {
                $_SESSION['ssp_score']['speler']++;
                $resultMessage = 'Jij wint deze ronde.';
                $resultTone = 'win';
            } else {
                $_SESSION['ssp_score']['computer']++;
                $resultMessage = 'De computer wint deze ronde.';
                $resultTone = 'lose';
            }
        } else {
            $resultMessage = 'Ongeldige keuze ontvangen. Probeer opnieuw.';
            $resultTone = 'lose';
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schaar, Steen, Papier</title>
    <style>
        :root {
            --bg-1: #13293d;
            --bg-2: #1b4965;
            --panel: rgba(255, 255, 255, 0.12);
            --panel-border: rgba(255, 255, 255, 0.22);
            --text: #f4faff;
            --muted: #d6e7f2;
            --accent: #ffd166;
            --accent-strong: #ffb703;
            --win: #7ae582;
            --draw: #ffe066;
            --lose: #ff7b72;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(255, 209, 102, 0.2), transparent 30%),
                linear-gradient(160deg, var(--bg-1), var(--bg-2));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .game-shell {
            width: min(920px, 100%);
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 28px;
            backdrop-filter: blur(14px);
            box-shadow: 0 28px 60px rgba(0, 0, 0, 0.28);
            overflow: hidden;
        }

        .hero {
            padding: 28px 28px 16px;
        }

        .eyebrow {
            margin: 0 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 12px;
            color: var(--accent);
            font-weight: 700;
        }

        h1 {
            margin: 0;
            font-size: clamp(32px, 5vw, 54px);
            line-height: 1;
        }

        .subtitle {
            margin: 12px 0 0;
            max-width: 680px;
            color: var(--muted);
            line-height: 1.6;
        }

        .layout {
            display: grid;
            grid-template-columns: 1.25fr 0.95fr;
            gap: 18px;
            padding: 0 28px 28px;
        }

        .panel {
            border-radius: 22px;
            border: 1px solid var(--panel-border);
            background: rgba(8, 23, 36, 0.24);
            padding: 20px;
        }

        .choice-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .choice-card {
            position: relative;
        }

        .choice-card input {
            position: absolute;
            opacity: 0;
            inset: 0;
        }

        .choice-card label {
            display: block;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 18px 12px;
            text-align: center;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.06);
            transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        }

        .choice-card label:hover,
        .choice-card input:focus + label,
        .choice-card input:checked + label {
            transform: translateY(-3px);
            border-color: var(--accent);
            background: rgba(255, 209, 102, 0.16);
        }

        .emoji {
            display: block;
            font-size: 42px;
            margin-bottom: 10px;
        }

        .choice-name {
            font-size: 18px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .play-btn {
            background: var(--accent);
            color: #17212b;
        }

        .reset-btn {
            background: rgba(255, 255, 255, 0.12);
            color: var(--text);
            border: 1px solid rgba(255, 255, 255, 0.16);
        }

        .result {
            border-radius: 18px;
            padding: 16px;
            font-weight: 700;
            margin-bottom: 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .result.win {
            background: rgba(122, 229, 130, 0.14);
            border-color: rgba(122, 229, 130, 0.4);
        }

        .result.draw {
            background: rgba(255, 224, 102, 0.14);
            border-color: rgba(255, 224, 102, 0.4);
        }

        .result.lose {
            background: rgba(255, 123, 114, 0.14);
            border-color: rgba(255, 123, 114, 0.4);
        }

        .battle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 18px;
        }

        .battle-card,
        .score-card {
            border-radius: 18px;
            padding: 18px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.14);
        }

        .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .battle-value {
            font-size: 24px;
            font-weight: 700;
        }

        .score-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .score-number {
            font-size: 30px;
            font-weight: 700;
            margin-top: 6px;
        }

        .helper {
            margin-top: 16px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        @media (max-width: 760px) {
            .layout {
                grid-template-columns: 1fr;
                padding: 0 18px 18px;
            }

            .hero {
                padding: 22px 18px 16px;
            }

            .choice-grid,
            .score-grid,
            .battle {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="game-shell">
        <section class="hero">
            <p class="eyebrow">Browsergame in PHP</p>
            <h1>Schaar steen papier</h1>
            <p class="subtitle">Speel direct tegen de computer. Kies jouw zet, verstuur het formulier en houd de tussenstand bij zolang je sessie actief blijft.</p>
        </section>

        <section class="layout">
            <section class="panel">
                <form method="post">
                    <div class="choice-grid">
                        <div class="choice-card">
                            <input id="keuze-schaar" type="radio" name="keuze" value="schaar" <?php echo $playerChoice === 'schaar' ? 'checked' : ''; ?>>
                            <label for="keuze-schaar">
                                <span class="emoji">✂️</span>
                                <span class="choice-name">Schaar</span>
                            </label>
                        </div>

                        <div class="choice-card">
                            <input id="keuze-steen" type="radio" name="keuze" value="steen" <?php echo $playerChoice === 'steen' ? 'checked' : ''; ?>>
                            <label for="keuze-steen">
                                <span class="emoji">🪨</span>
                                <span class="choice-name">Steen</span>
                            </label>
                        </div>

                        <div class="choice-card">
                            <input id="keuze-papier" type="radio" name="keuze" value="papier" <?php echo $playerChoice === 'papier' ? 'checked' : ''; ?>>
                            <label for="keuze-papier">
                                <span class="emoji">📄</span>
                                <span class="choice-name">Papier</span>
                            </label>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="play-btn" type="submit">Speel ronde</button>
                        <button class="reset-btn" type="submit" name="reset_score" value="1">Reset score</button>
                    </div>
                </form>

                <p class="helper">Regels: schaar knipt papier, papier bedekt steen en steen breekt schaar.</p>
            </section>

            <aside class="panel">
                <div class="result <?php echo h($resultTone); ?>"><?php echo h($resultMessage); ?></div>

                <div class="battle">
                    <div class="battle-card">
                        <div class="label">Jouw keuze</div>
                        <div class="battle-value"><?php echo $playerChoice !== null ? h($choices[$playerChoice]) : 'Nog niet gespeeld'; ?></div>
                    </div>

                    <div class="battle-card">
                        <div class="label">Computer</div>
                        <div class="battle-value"><?php echo $computerChoice !== null ? h($choices[$computerChoice]) : 'Wacht op jouw zet'; ?></div>
                    </div>
                </div>

                <div class="score-grid">
                    <div class="score-card">
                        <div class="label">Gewonnen</div>
                        <div class="score-number"><?php echo (int)$_SESSION['ssp_score']['speler']; ?></div>
                    </div>

                    <div class="score-card">
                        <div class="label">Gelijk</div>
                        <div class="score-number"><?php echo (int)$_SESSION['ssp_score']['gelijk']; ?></div>
                    </div>

                    <div class="score-card">
                        <div class="label">Verloren</div>
                        <div class="score-number"><?php echo (int)$_SESSION['ssp_score']['computer']; ?></div>
                    </div>
                </div>
            </aside>
        </section>
    </main>
</body>
</html>
