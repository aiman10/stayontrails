#!/usr/bin/env python3

import argparse
import csv
import shutil
import subprocess
import tempfile
from pathlib import Path


DEFAULT_CSV = Path("img/laerbeekbos/inference_log.csv")
DEFAULT_OUTPUT_DIR = Path("img/laerbeekbos/speech_mp3")
DEFAULT_PIPER_MODEL = Path("/home/jetson/jetsonOrin/voices/nl_BE-nathalie-medium.onnx")


def sanitize_filename(text: str) -> str:
    text = text.strip().replace(" ", "_")
    allowed = "-_.()abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
    sanitized = "".join(char for char in text if char in allowed)
    return sanitized or "instruction"


def detect_instruction_index(header: list[str], row: list[str]) -> int | None:
    lowered = [column.strip().lower() for column in header]
    for candidate in ("instructions", "instruction", "subtitle", "speech"):
        if candidate in lowered:
            return lowered.index(candidate)

    if len(row) > len(header):
        return len(header)

    return None


def load_instruction_rows(csv_path: Path) -> list[dict[str, str]]:
    rows_with_instructions: list[dict[str, str]] = []

    with csv_path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.reader(handle)
        header = next(reader, None)
        if not header:
            return rows_with_instructions

        filename_index = None
        for candidate in ("Filename", "filename"):
            try:
                filename_index = header.index(candidate)
                break
            except ValueError:
                continue

        if filename_index is None:
            raise ValueError("CSV mist een 'Filename' kolom.")

        for row_number, row in enumerate(reader, start=2):
            instruction_index = detect_instruction_index(header, row)
            if instruction_index is None or instruction_index >= len(row):
                continue

            instruction = row[instruction_index].strip()
            if not instruction:
                continue

            filename = row[filename_index].strip() if filename_index < len(row) else ""
            if not filename:
                print(f"[WARN] Rij {row_number} heeft instructie maar geen bestandsnaam, overgeslagen.")
                continue

            rows_with_instructions.append(
                {
                    "row_number": str(row_number),
                    "filename": filename,
                    "instruction": instruction,
                }
            )

    return rows_with_instructions


def generate_wav_with_piper(text: str, wav_path: Path, model_path: Path) -> None:
    cmd = [
        "piper",
        "--model",
        str(model_path),
        "--output_file",
        str(wav_path),
    ]

    result = subprocess.run(
        cmd,
        input=text + "\n",
        text=True,
        capture_output=True,
    )

    if result.returncode != 0:
        raise RuntimeError(f"Piper fout voor '{text}':\n{result.stderr}")


def convert_wav_to_mp3(wav_path: Path, mp3_path: Path) -> None:
    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(wav_path),
        "-codec:a",
        "libmp3lame",
        "-qscale:a",
        "2",
        str(mp3_path),
    ]

    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg fout bij omzetting naar mp3:\n{result.stderr}")


def ensure_requirements(model_path: Path) -> None:
    if not model_path.exists():
        raise FileNotFoundError(f"Piper model niet gevonden: {model_path}")

    if shutil.which("piper") is None:
        raise RuntimeError("Het commando 'piper' is niet gevonden in PATH.")

    if shutil.which("ffmpeg") is None:
        raise RuntimeError("Het commando 'ffmpeg' is niet gevonden in PATH.")


def build_output_path(output_dir: Path, filename: str) -> Path:
    stem = Path(filename).stem
    safe_stem = sanitize_filename(stem)
    return output_dir / f"{safe_stem}.mp3"


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Genereer MP3-spraakbestanden voor instructies uit inference_log.csv."
    )
    parser.add_argument("--csv", type=Path, default=DEFAULT_CSV, help="Pad naar de inference_log.csv")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR, help="Map voor MP3-uitvoer")
    parser.add_argument("--model", type=Path, default=DEFAULT_PIPER_MODEL, help="Pad naar het Piper-model")
    parser.add_argument("--force", action="store_true", help="Bestaande MP3-bestanden overschrijven")
    args = parser.parse_args()

    ensure_requirements(args.model)

    if not args.csv.exists():
        raise FileNotFoundError(f"CSV niet gevonden: {args.csv}")

    instruction_rows = load_instruction_rows(args.csv)
    if not instruction_rows:
        print(f"[INFO] Geen instructies gevonden in '{args.csv}'.")
        return

    args.output_dir.mkdir(parents=True, exist_ok=True)

    generated = 0
    skipped = 0

    for item in instruction_rows:
        mp3_path = build_output_path(args.output_dir, item["filename"])
        if mp3_path.exists() and not args.force:
            print(f"[SKIP] Bestaat al: {mp3_path}")
            skipped += 1
            continue

        print(f"[INFO] Genereer spraak voor {item['filename']}: {item['instruction']}")
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False, dir=args.output_dir) as temp_file:
            wav_path = Path(temp_file.name)

        try:
            generate_wav_with_piper(item["instruction"], wav_path, args.model)
            convert_wav_to_mp3(wav_path, mp3_path)
            generated += 1
            print(f"[OK] Geschreven: {mp3_path}")
        finally:
            if wav_path.exists():
                wav_path.unlink()

    print(f"[OK] Klaar. Gegenereerd: {generated}, overgeslagen: {skipped}, output: {args.output_dir}")


if __name__ == "__main__":
    main()
