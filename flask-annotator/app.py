"""Flask application for the Stay On Trails annotator.

Routes are split across small handlers in this single file. As the surface
grows (SAM, training), they will be moved to dedicated blueprints — but
keeping them here for Phase 1+2 keeps the project trivial to navigate.
"""
from __future__ import annotations

from pathlib import Path

from flask import Flask, abort, render_template, request

import config
from slug import slugify


def _list_model_slugs() -> list[dict]:
    """Return one entry per direct subfolder of RECORD_ROOT."""
    if not config.RECORD_ROOT.is_dir():
        return []
    out: list[dict] = []
    for entry in sorted(config.RECORD_ROOT.iterdir()):
        if not entry.is_dir():
            continue
        out.append({
            "slug": entry.name,
            "image_count": len(list(entry.glob("*.jpg"))),
            "has_annotations": (entry / "annotations.json").is_file(),
        })
    return out


def create_app() -> Flask:
    app = Flask(__name__)

    @app.get("/")
    def index():
        raw_model = request.args.get("model", "")
        model = slugify(raw_model)
        if not model:
            return render_template("picker.html", models=_list_model_slugs())
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")
        return render_template("index.html", model=model)

    @app.get("/api/models/<model>/images")
    def list_images(model):
        from annotations import load_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        files = sorted(p.name for p in model_dir.glob("*.jpg"))
        loaded = load_annotations(model_dir, model)
        status_by_file = {
            img["file"]: img.get("status", "unlabeled")
            for img in loaded["data"].get("images", [])
        }
        images = [{"file": f, "status": status_by_file.get(f, "unlabeled")} for f in files]
        return {"ok": True, "images": images}

    return app


if __name__ == "__main__":
    app = create_app()
    app.run(host="127.0.0.1", port=config.PORT, debug=config.DEBUG)
