"""Runtime configuration for the Flask annotator.

Values can be overridden by environment variables of the same name.
"""
from __future__ import annotations

import os
from pathlib import Path

# Root of the captures tree shared with recordroute.php and annotate.php.
# By default we resolve the sibling `recorded_routes/` next to this package.
_DEFAULT_ROOT = Path(__file__).resolve().parent.parent / "recorded_routes"

RECORD_ROOT: Path = Path(os.environ.get("RECORD_ROOT", str(_DEFAULT_ROOT))).resolve()
PORT: int = int(os.environ.get("PORT", "5001"))
DEBUG: bool = os.environ.get("DEBUG", "1") == "1"
