"""Slug + filename validation helpers.

Mirrors the slugify logic in annotate.php so the same model directory names
are reachable from both stacks.
"""
from __future__ import annotations

import re

_SLUG_RE = re.compile(r"[^a-z0-9]+")
_SAFE_FILENAME_RE = re.compile(r"^[A-Za-z0-9._-]+\.(jpg|jpeg|png)$", re.IGNORECASE)


def slugify(value: str) -> str:
    """Lowercase, replace non-alphanumeric runs with `-`, trim leading/trailing `-`.

    Mirrors PHP's `slugify` in annotate.php so a model named "My Trail!" maps
    to the same folder name from both stacks.
    """
    lowered = value.strip().lower()
    replaced = _SLUG_RE.sub("-", lowered)
    return replaced.strip("-")


def is_safe_filename(name: str) -> bool:
    """True if `name` is a plain image filename safe to join with a directory.

    Rejects path traversal (`..`, `/`, `\\`) and any non-image extensions.
    """
    return bool(_SAFE_FILENAME_RE.fullmatch(name))
