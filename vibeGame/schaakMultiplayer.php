<?php
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Multiplayer Schaak</title>
    <style>
        :root {
            --bg-a: #10212f;
            --bg-b: #183b4e;
            --panel: rgba(255, 255, 255, 0.09);
            --panel-border: rgba(255, 255, 255, 0.16);
            --text: #f5f7fb;
            --muted: #c8d6e2;
            --gold: #f3c969;
            --light-square: #f0d9b5;
            --dark-square: #b58863;
            --selected: #7dd3fc;
            --move: rgba(125, 211, 252, 0.35);
            --capture: rgba(248, 113, 113, 0.42);
            --danger: #ff9c95;
            --ok: #8ef0a7;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Trebuchet MS", Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(243, 201, 105, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(125, 211, 252, 0.14), transparent 25%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
            padding: 20px;
        }

        .shell {
            max-width: 1320px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(340px, 760px) minmax(280px, 1fr);
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 24px;
            backdrop-filter: blur(14px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
        }

        .board-panel { padding: 22px; }
        .sidebar { padding: 22px; display: flex; flex-direction: column; gap: 16px; }

        h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 46px);
            line-height: 1.05;
        }

        .subtitle {
            margin: 12px 0 22px;
            color: var(--muted);
            line-height: 1.55;
            max-width: 720px;
        }

        .board-wrap { width: min(100%, 720px); margin: 0 auto; }

        .coordinates-top,
        .coordinates-bottom {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            margin: 0 38px 8px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }

        .coordinates-bottom { margin: 8px 38px 0; }
        .board-row { display: grid; grid-template-columns: 28px 1fr 28px; align-items: stretch; gap: 10px; }
        .ranks { display: grid; grid-template-rows: repeat(8, 1fr); color: var(--muted); font-size: 13px; }
        .rank { display: flex; align-items: center; justify-content: center; }

        .board {
            display: grid;
            grid-template-columns: repeat(8, minmax(34px, 1fr));
            aspect-ratio: 1 / 1;
            border-radius: 20px;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.14);
        }

        .square {
            position: relative;
            border: 0;
            padding: 0;
            cursor: pointer;
            font-size: clamp(28px, 4vw, 48px);
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .square.light { background: var(--light-square); }
        .square.dark { background: var(--dark-square); }
        .square.selected { outline: 4px solid var(--selected); outline-offset: -4px; }
        .square.move::after,
        .square.capture::after {
            content: "";
            position: absolute;
            border-radius: 999px;
        }

        .square.move::after { inset: 26%; background: var(--move); }
        .square.capture::after { inset: 8%; border: 4px solid var(--capture); }
        .square.in-check { box-shadow: inset 0 0 0 5px rgba(255, 156, 149, 0.95); }

        .card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(7, 18, 28, 0.24);
        }

        .eyebrow {
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 12px;
            color: var(--gold);
            font-weight: 700;
        }

        .status { font-size: 20px; font-weight: 700; }
        .detail { margin-top: 8px; color: var(--muted); line-height: 1.5; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }

        button {
            border: 0;
            border-radius: 999px;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
        }

        .primary { background: var(--gold); color: #1a2027; }
        .secondary { background: rgba(255, 255, 255, 0.1); color: var(--text); border: 1px solid rgba(255, 255, 255, 0.15); }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .meta-value { font-size: 26px; font-weight: 700; margin-top: 8px; }
        .captured-list,
        .move-log { min-height: 42px; color: var(--muted); line-height: 1.6; }
        .move-log { max-height: 280px; overflow: auto; font-family: Consolas, monospace; font-size: 14px; white-space: pre-wrap; }
        .legend { color: var(--muted); line-height: 1.6; font-size: 14px; }

        @media (max-width: 980px) { .shell { grid-template-columns: 1fr; } }

        @media (max-width: 640px) {
            body { padding: 12px; }
            .board-panel, .sidebar { padding: 16px; }
            .coordinates-top, .coordinates-bottom { margin-left: 30px; margin-right: 30px; }
            .board-row { grid-template-columns: 20px 1fr 20px; gap: 6px; }
            .meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel board-panel">
            <p class="eyebrow">Lokale multiplayer</p>
            <h1>Schaak voor twee spelers</h1>
            <p class="subtitle">Twee spelers spelen om de beurt op hetzelfde scherm. Het spel controleert geldige zetten, houdt slaan en rokade bij en detecteert schaak, schaakmat en pat.</p>

            <div class="board-wrap">
                <div class="coordinates-top" id="filesTop"></div>
                <div class="board-row">
                    <div class="ranks" id="ranksLeft"></div>
                    <div class="board" id="board" aria-label="Schaakbord"></div>
                    <div class="ranks" id="ranksRight"></div>
                </div>
                <div class="coordinates-bottom" id="filesBottom"></div>
            </div>
        </section>

        <aside class="panel sidebar">
            <section class="card">
                <div class="eyebrow">Spelstatus</div>
                <div class="status" id="statusText">Wit is aan zet</div>
                <div class="detail" id="statusDetail">Selecteer een wit stuk om te beginnen.</div>
            </section>

            <section class="card">
                <div class="eyebrow">Beurt</div>
                <div class="meta-grid">
                    <div><div class="detail">Actieve speler</div><div class="meta-value" id="turnValue">Wit</div></div>
                    <div><div class="detail">Ronde</div><div class="meta-value" id="moveNumberValue">1</div></div>
                </div>
            </section>

            <section class="card">
                <div class="eyebrow">Geslagen stukken</div>
                <div class="detail">Wit heeft geslagen</div>
                <div class="captured-list" id="capturedByWhite">Nog niets</div>
                <div class="detail" style="margin-top: 12px;">Zwart heeft geslagen</div>
                <div class="captured-list" id="capturedByBlack">Nog niets</div>
            </section>

            <section class="card">
                <div class="eyebrow">Zetlog</div>
                <div class="move-log" id="moveLog">Nog geen zetten.</div>
            </section>

            <section class="card">
                <div class="actions">
                    <button class="primary" type="button" id="resetBtn">Nieuw spel</button>
                    <button class="secondary" type="button" id="flipBtn">Draai bord</button>
                </div>
            </section>

            <section class="card">
                <div class="eyebrow">Regels</div>
                <div class="legend">
                    Klik een stuk en daarna een geldig doelveld.
                    De pion promoveert automatisch naar een dame.
                    En passant is in deze versie niet actief.
                </div>
            </section>
        </aside>
    </main>

    <script>
        const FILES = ["a", "b", "c", "d", "e", "f", "g", "h"];
        const PIECES = {
            wp: "♙", wr: "♖", wn: "♘", wb: "♗", wq: "♕", wk: "♔",
            bp: "♟", br: "♜", bn: "♞", bb: "♝", bq: "♛", bk: "♚"
        };

        const boardEl = document.getElementById("board");
        const filesTopEl = document.getElementById("filesTop");
        const filesBottomEl = document.getElementById("filesBottom");
        const ranksLeftEl = document.getElementById("ranksLeft");
        const ranksRightEl = document.getElementById("ranksRight");
        const statusTextEl = document.getElementById("statusText");
        const statusDetailEl = document.getElementById("statusDetail");
        const turnValueEl = document.getElementById("turnValue");
        const moveNumberValueEl = document.getElementById("moveNumberValue");
        const capturedByWhiteEl = document.getElementById("capturedByWhite");
        const capturedByBlackEl = document.getElementById("capturedByBlack");
        const moveLogEl = document.getElementById("moveLog");
        const resetBtn = document.getElementById("resetBtn");
        const flipBtn = document.getElementById("flipBtn");

        let state = createInitialState();
        let selectedSquare = null;
        let legalTargets = [];
        let flipped = false;

        function createInitialBoard() {
            return [
                ["br", "bn", "bb", "bq", "bk", "bb", "bn", "br"],
                ["bp", "bp", "bp", "bp", "bp", "bp", "bp", "bp"],
                [null, null, null, null, null, null, null, null],
                [null, null, null, null, null, null, null, null],
                [null, null, null, null, null, null, null, null],
                [null, null, null, null, null, null, null, null],
                ["wp", "wp", "wp", "wp", "wp", "wp", "wp", "wp"],
                ["wr", "wn", "wb", "wq", "wk", "wb", "wn", "wr"]
            ];
        }

        function createInitialState() {
            return {
                board: createInitialBoard(),
                turn: "w",
                moveNumber: 1,
                finished: false,
                winner: null,
                message: "Wit is aan zet",
                detail: "Selecteer een wit stuk om te beginnen.",
                moveHistory: [],
                capturedByWhite: [],
                capturedByBlack: [],
                castling: {
                    w: { kingMoved: false, rookA: false, rookH: false },
                    b: { kingMoved: false, rookA: false, rookH: false }
                }
            };
        }

        function cloneState(source) {
            return {
                board: source.board.map((row) => row.slice()),
                turn: source.turn,
                moveNumber: source.moveNumber,
                finished: source.finished,
                winner: source.winner,
                message: source.message,
                detail: source.detail,
                moveHistory: source.moveHistory.slice(),
                capturedByWhite: source.capturedByWhite.slice(),
                capturedByBlack: source.capturedByBlack.slice(),
                castling: {
                    w: { ...source.castling.w },
                    b: { ...source.castling.b }
                }
            };
        }

        function pieceColor(piece) { return piece ? piece[0] : null; }
        function pieceType(piece) { return piece ? piece[1] : null; }
        function inBounds(row, col) { return row >= 0 && row < 8 && col >= 0 && col < 8; }
        function squareName(row, col) { return `${FILES[col]}${8 - row}`; }
        function otherColor(color) { return color === "w" ? "b" : "w"; }

        function buildCoordinates() {
            const fileOrder = flipped ? [...FILES].reverse() : FILES;
            const rankOrder = flipped ? [1, 2, 3, 4, 5, 6, 7, 8] : [8, 7, 6, 5, 4, 3, 2, 1];
            filesTopEl.innerHTML = fileOrder.map((file) => `<div>${file}</div>`).join("");
            filesBottomEl.innerHTML = fileOrder.map((file) => `<div>${file}</div>`).join("");
            ranksLeftEl.innerHTML = rankOrder.map((rank) => `<div class="rank">${rank}</div>`).join("");
            ranksRightEl.innerHTML = rankOrder.map((rank) => `<div class="rank">${rank}</div>`).join("");
        }

        function boardOrder() {
            return {
                rows: flipped ? [7, 6, 5, 4, 3, 2, 1, 0] : [0, 1, 2, 3, 4, 5, 6, 7],
                cols: flipped ? [7, 6, 5, 4, 3, 2, 1, 0] : [0, 1, 2, 3, 4, 5, 6, 7]
            };
        }

        function renderBoard() {
            buildCoordinates();
            const { rows, cols } = boardOrder();
            const checkedKing = findKing(state.board, state.turn);
            const kingInCheck = checkedKing && isSquareAttacked(state.board, checkedKing.row, checkedKing.col, otherColor(state.turn));
            boardEl.innerHTML = "";

            for (const row of rows) {
                for (const col of cols) {
                    const square = document.createElement("button");
                    square.type = "button";
                    square.className = `square ${((row + col) % 2 === 0) ? "light" : "dark"}`;
                    square.dataset.row = String(row);
                    square.dataset.col = String(col);
                    square.setAttribute("aria-label", squareName(row, col));

                    const piece = state.board[row][col];
                    square.textContent = piece ? PIECES[piece] : "";

                    if (selectedSquare && selectedSquare.row === row && selectedSquare.col === col) {
                        square.classList.add("selected");
                    }

                    const target = legalTargets.find((move) => move.to.row === row && move.to.col === col);
                    if (target) {
                        square.classList.add(target.capture ? "capture" : "move");
                    }

                    if (kingInCheck && checkedKing.row === row && checkedKing.col === col) {
                        square.classList.add("in-check");
                    }

                    square.addEventListener("click", () => onSquareClick(row, col));
                    boardEl.appendChild(square);
                }
            }

            turnValueEl.textContent = state.turn === "w" ? "Wit" : "Zwart";
            moveNumberValueEl.textContent = String(state.moveNumber);
            statusTextEl.textContent = state.message;
            statusDetailEl.textContent = state.detail;
            capturedByWhiteEl.textContent = state.capturedByWhite.length ? state.capturedByWhite.map((piece) => PIECES[piece]).join(" ") : "Nog niets";
            capturedByBlackEl.textContent = state.capturedByBlack.length ? state.capturedByBlack.map((piece) => PIECES[piece]).join(" ") : "Nog niets";
            moveLogEl.textContent = state.moveHistory.length ? state.moveHistory.join("\n") : "Nog geen zetten.";
        }

        function onSquareClick(row, col) {
            if (state.finished) {
                return;
            }

            const piece = state.board[row][col];
            if (selectedSquare) {
                const chosenMove = legalTargets.find((move) => move.to.row === row && move.to.col === col);
                if (chosenMove) {
                    applyMove(chosenMove);
                    return;
                }
            }

            if (piece && pieceColor(piece) === state.turn) {
                selectedSquare = { row, col };
                legalTargets = getLegalMovesForPiece(state, row, col);
                state.detail = legalTargets.length
                    ? `Geselecteerd: ${squareName(row, col)}. Kies een gemarkeerd doelveld.`
                    : `Voor ${squareName(row, col)} zijn geen geldige zetten beschikbaar.`;
            } else {
                selectedSquare = null;
                legalTargets = [];
                if (piece && pieceColor(piece) !== state.turn) {
                    state.detail = `Dat is een ${state.turn === "w" ? "zwart" : "wit"} stuk.`;
                }
            }

            renderBoard();
        }

        function getLegalMovesForPiece(gameState, row, col) {
            const piece = gameState.board[row][col];
            if (!piece || pieceColor(piece) !== gameState.turn) {
                return [];
            }

            const pseudoMoves = getPseudoMoves(gameState, row, col, true);
            return pseudoMoves.filter((move) => {
                const simulated = simulateMove(gameState, move);
                const king = findKing(simulated.board, gameState.turn);
                return king && !isSquareAttacked(simulated.board, king.row, king.col, otherColor(gameState.turn));
            });
        }

        function getAllLegalMoves(gameState, color) {
            const tempState = { ...gameState, turn: color };
            const moves = [];
            for (let row = 0; row < 8; row++) {
                for (let col = 0; col < 8; col++) {
                    const piece = tempState.board[row][col];
                    if (piece && pieceColor(piece) === color) {
                        moves.push(...getLegalMovesForPiece(tempState, row, col));
                    }
                }
            }
            return moves;
        }

        function getPseudoMoves(gameState, row, col, includeCastling) {
            const board = gameState.board;
            const piece = board[row][col];
            if (!piece) {
                return [];
            }

            const color = pieceColor(piece);
            const type = pieceType(piece);
            const moves = [];
            const forward = color === "w" ? -1 : 1;

            if (type === "p") {
                const nextRow = row + forward;
                if (inBounds(nextRow, col) && !board[nextRow][col]) {
                    moves.push({ from: { row, col }, to: { row: nextRow, col }, piece, capture: false });
                    const startRow = color === "w" ? 6 : 1;
                    const jumpRow = row + (forward * 2);
                    if (row === startRow && !board[jumpRow][col]) {
                        moves.push({ from: { row, col }, to: { row: jumpRow, col }, piece, capture: false });
                    }
                }
                for (const deltaCol of [-1, 1]) {
                    const captureRow = row + forward;
                    const captureCol = col + deltaCol;
                    if (!inBounds(captureRow, captureCol)) {
                        continue;
                    }
                    const target = board[captureRow][captureCol];
                    if (target && pieceColor(target) !== color) {
                        moves.push({ from: { row, col }, to: { row: captureRow, col: captureCol }, piece, capture: true });
                    }
                }
            }

            if (type === "n") {
                const offsets = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
                for (const [dr, dc] of offsets) {
                    const nextRow = row + dr;
                    const nextCol = col + dc;
                    if (!inBounds(nextRow, nextCol)) {
                        continue;
                    }
                    const target = board[nextRow][nextCol];
                    if (!target || pieceColor(target) !== color) {
                        moves.push({ from: { row, col }, to: { row: nextRow, col: nextCol }, piece, capture: Boolean(target) });
                    }
                }
            }

            if (["b", "r", "q"].includes(type)) {
                const directions = [];
                if (["b", "q"].includes(type)) {
                    directions.push([-1, -1], [-1, 1], [1, -1], [1, 1]);
                }
                if (["r", "q"].includes(type)) {
                    directions.push([-1, 0], [1, 0], [0, -1], [0, 1]);
                }
                for (const [dr, dc] of directions) {
                    let nextRow = row + dr;
                    let nextCol = col + dc;
                    while (inBounds(nextRow, nextCol)) {
                        const target = board[nextRow][nextCol];
                        if (!target) {
                            moves.push({ from: { row, col }, to: { row: nextRow, col: nextCol }, piece, capture: false });
                        } else {
                            if (pieceColor(target) !== color) {
                                moves.push({ from: { row, col }, to: { row: nextRow, col: nextCol }, piece, capture: true });
                            }
                            break;
                        }
                        nextRow += dr;
                        nextCol += dc;
                    }
                }
            }

            if (type === "k") {
                for (let dr = -1; dr <= 1; dr++) {
                    for (let dc = -1; dc <= 1; dc++) {
                        if (dr === 0 && dc === 0) {
                            continue;
                        }
                        const nextRow = row + dr;
                        const nextCol = col + dc;
                        if (!inBounds(nextRow, nextCol)) {
                            continue;
                        }
                        const target = board[nextRow][nextCol];
                        if (!target || pieceColor(target) !== color) {
                            moves.push({ from: { row, col }, to: { row: nextRow, col: nextCol }, piece, capture: Boolean(target) });
                        }
                    }
                }
                if (includeCastling) {
                    moves.push(...getCastlingMoves(gameState, row, col, color));
                }
            }

            return moves;
        }

        function getCastlingMoves(gameState, row, col, color) {
            const moves = [];
            const board = gameState.board;
            const homeRow = color === "w" ? 7 : 0;
            if (row !== homeRow || col !== 4) {
                return moves;
            }

            const rights = gameState.castling[color];
            if (rights.kingMoved) {
                return moves;
            }

            const enemy = otherColor(color);
            if (isSquareAttacked(board, homeRow, 4, enemy)) {
                return moves;
            }

            if (!rights.rookH && board[homeRow][7] === `${color}r` && !board[homeRow][5] && !board[homeRow][6]) {
                if (!isSquareAttacked(board, homeRow, 5, enemy) && !isSquareAttacked(board, homeRow, 6, enemy)) {
                    moves.push({ from: { row: homeRow, col: 4 }, to: { row: homeRow, col: 6 }, piece: `${color}k`, capture: false, castle: "king" });
                }
            }

            if (!rights.rookA && board[homeRow][0] === `${color}r` && !board[homeRow][1] && !board[homeRow][2] && !board[homeRow][3]) {
                if (!isSquareAttacked(board, homeRow, 3, enemy) && !isSquareAttacked(board, homeRow, 2, enemy)) {
                    moves.push({ from: { row: homeRow, col: 4 }, to: { row: homeRow, col: 2 }, piece: `${color}k`, capture: false, castle: "queen" });
                }
            }

            return moves;
        }

        function simulateMove(gameState, move) {
            const simulated = cloneState(gameState);
            applyMoveToState(simulated, { ...move }, false);
            return simulated;
        }

        function applyMove(move) {
            applyMoveToState(state, { ...move }, true);
            selectedSquare = null;
            legalTargets = [];
            updateGameStatus();
            renderBoard();
        }

        function applyMoveToState(gameState, move, recordHistory) {
            const board = gameState.board;
            const movingPiece = board[move.from.row][move.from.col];
            const targetPiece = board[move.to.row][move.to.col];
            const color = pieceColor(movingPiece);
            const enemy = otherColor(color);

            board[move.from.row][move.from.col] = null;

            if (targetPiece) {
                if (color === "w") {
                    gameState.capturedByWhite.push(targetPiece);
                } else {
                    gameState.capturedByBlack.push(targetPiece);
                }
            }

            board[move.to.row][move.to.col] = movingPiece;

            if (pieceType(movingPiece) === "p" && (move.to.row === 0 || move.to.row === 7)) {
                board[move.to.row][move.to.col] = `${color}q`;
                move.promoted = true;
            }

            if (pieceType(movingPiece) === "k") {
                gameState.castling[color].kingMoved = true;
                if (move.castle === "king") {
                    board[move.from.row][7] = null;
                    board[move.from.row][5] = `${color}r`;
                    gameState.castling[color].rookH = true;
                }
                if (move.castle === "queen") {
                    board[move.from.row][0] = null;
                    board[move.from.row][3] = `${color}r`;
                    gameState.castling[color].rookA = true;
                }
            }

            if (pieceType(movingPiece) === "r") {
                if (move.from.col === 0) {
                    gameState.castling[color].rookA = true;
                }
                if (move.from.col === 7) {
                    gameState.castling[color].rookH = true;
                }
            }

            if (targetPiece && pieceType(targetPiece) === "r") {
                if (move.to.col === 0) {
                    gameState.castling[enemy].rookA = true;
                }
                if (move.to.col === 7) {
                    gameState.castling[enemy].rookH = true;
                }
            }

            if (recordHistory) {
                gameState.moveHistory.push(formatMoveText(gameState, move, movingPiece, targetPiece));
            }

            gameState.turn = enemy;
            if (gameState.turn === "w") {
                gameState.moveNumber++;
            }
        }

        function formatMoveText(gameState, move, movingPiece, targetPiece) {
            const playerName = pieceColor(movingPiece) === "w" ? `Wit ${gameState.moveNumber}.` : `Zwart ${gameState.moveNumber}.`;
            if (move.castle === "king") {
                return `${playerName} 0-0`;
            }
            if (move.castle === "queen") {
                return `${playerName} 0-0-0`;
            }

            const pieceLetterMap = { p: "", n: "N", b: "B", r: "R", q: "Q", k: "K" };
            const type = pieceType(movingPiece);
            const captureMarker = targetPiece ? "x" : "-";
            const prefix = type === "p" && targetPiece ? FILES[move.from.col] : pieceLetterMap[type];
            const promotion = move.promoted ? "=Q" : "";
            return `${playerName} ${prefix}${squareName(move.from.row, move.from.col)}${captureMarker}${squareName(move.to.row, move.to.col)}${promotion}`;
        }

        function findKing(board, color) {
            for (let row = 0; row < 8; row++) {
                for (let col = 0; col < 8; col++) {
                    if (board[row][col] === `${color}k`) {
                        return { row, col };
                    }
                }
            }
            return null;
        }

        function isSquareAttacked(board, row, col, byColor) {
            const pawnDir = byColor === "w" ? -1 : 1;
            for (const deltaCol of [-1, 1]) {
                const attackerRow = row - pawnDir;
                const attackerCol = col + deltaCol;
                if (inBounds(attackerRow, attackerCol) && board[attackerRow][attackerCol] === `${byColor}p`) {
                    return true;
                }
            }

            const knightOffsets = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
            for (const [dr, dc] of knightOffsets) {
                const attackerRow = row + dr;
                const attackerCol = col + dc;
                if (inBounds(attackerRow, attackerCol) && board[attackerRow][attackerCol] === `${byColor}n`) {
                    return true;
                }
            }

            const straightDirs = [[-1, 0], [1, 0], [0, -1], [0, 1]];
            for (const [dr, dc] of straightDirs) {
                let r = row + dr;
                let c = col + dc;
                while (inBounds(r, c)) {
                    const piece = board[r][c];
                    if (piece) {
                        if (pieceColor(piece) === byColor && ["r", "q"].includes(pieceType(piece))) {
                            return true;
                        }
                        break;
                    }
                    r += dr;
                    c += dc;
                }
            }

            const diagonalDirs = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
            for (const [dr, dc] of diagonalDirs) {
                let r = row + dr;
                let c = col + dc;
                while (inBounds(r, c)) {
                    const piece = board[r][c];
                    if (piece) {
                        if (pieceColor(piece) === byColor && ["b", "q"].includes(pieceType(piece))) {
                            return true;
                        }
                        break;
                    }
                    r += dr;
                    c += dc;
                }
            }

            for (let dr = -1; dr <= 1; dr++) {
                for (let dc = -1; dc <= 1; dc++) {
                    if (dr === 0 && dc === 0) {
                        continue;
                    }
                    const attackerRow = row + dr;
                    const attackerCol = col + dc;
                    if (inBounds(attackerRow, attackerCol) && board[attackerRow][attackerCol] === `${byColor}k`) {
                        return true;
                    }
                }
            }

            return false;
        }

        function updateGameStatus() {
            const currentColor = state.turn;
            const currentName = currentColor === "w" ? "Wit" : "Zwart";
            const enemyName = currentColor === "w" ? "Zwart" : "Wit";
            const king = findKing(state.board, currentColor);
            const inCheck = king && isSquareAttacked(state.board, king.row, king.col, otherColor(currentColor));
            const legalMoves = getAllLegalMoves(state, currentColor);

            if (!legalMoves.length) {
                state.finished = true;
                if (inCheck) {
                    state.winner = otherColor(currentColor);
                    state.message = `Schaakmat: ${enemyName} wint`;
                    state.detail = `${currentName} heeft geen geldige zetten meer en staat schaak.`;
                } else {
                    state.winner = null;
                    state.message = "Pat: gelijkspel";
                    state.detail = `${currentName} heeft geen geldige zetten meer, maar staat niet schaak.`;
                }
                return;
            }

            state.finished = false;
            state.winner = null;
            state.message = inCheck ? `${currentName} staat schaak` : `${currentName} is aan zet`;
            state.detail = inCheck
                ? "Los het schaak op met een geldige zet."
                : `Selecteer een ${currentColor === "w" ? "wit" : "zwart"} stuk om verder te spelen.`;
        }

        resetBtn.addEventListener("click", () => {
            state = createInitialState();
            selectedSquare = null;
            legalTargets = [];
            renderBoard();
        });

        flipBtn.addEventListener("click", () => {
            flipped = !flipped;
            renderBoard();
        });

        renderBoard();
    </script>
</body>
</html>
