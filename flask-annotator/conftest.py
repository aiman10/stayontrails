"""Add flask-annotator/ to sys.path so its modules can be imported as plain names."""
import sys
from pathlib import Path

_root = Path(__file__).resolve().parent
if str(_root) not in sys.path:
    sys.path.insert(0, str(_root))
