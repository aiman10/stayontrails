"""Download the SAM vit_b checkpoint into flask-annotator/models/sam/.

Idempotent: skips if file already exists with the expected SHA-256.
"""
from __future__ import annotations

import hashlib
import sys
import urllib.request
from pathlib import Path

URL = "https://dl.fbaipublicfiles.com/segment_anything/sam_vit_b_01ec64.pth"
EXPECTED_SHA256 = "ec2df62732614e57411cdcf32a23ffdf28910380d03139ee0f4fcbe91eb8c912"
TARGET = Path(__file__).resolve().parent.parent / "models" / "sam" / "sam_vit_b_01ec64.pth"


def _sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _progress(blocks: int, block_size: int, total: int) -> None:
    if total <= 0:
        return
    pct = min(100, blocks * block_size * 100 // total)
    print(f"\r  {pct:3d}%", end="", flush=True)


def main() -> int:
    TARGET.parent.mkdir(parents=True, exist_ok=True)
    if TARGET.is_file():
        if _sha256(TARGET) == EXPECTED_SHA256:
            print(f"OK: {TARGET} already present and verified.")
            return 0
        print("Hash mismatch on existing file, re-downloading.")
        TARGET.unlink()

    print(f"Downloading SAM vit_b checkpoint (~375 MB) to {TARGET} ...")
    urllib.request.urlretrieve(URL, TARGET, _progress)
    print()
    actual = _sha256(TARGET)
    if actual != EXPECTED_SHA256:
        print(f"ERROR: hash mismatch. expected={EXPECTED_SHA256} got={actual}")
        return 1
    print(f"OK: downloaded and verified {TARGET}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
