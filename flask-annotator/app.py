"""Flask application for the Stay On Trails annotator.

Routes are split across small handlers in this single file. As the surface
grows (SAM, training), they will be moved to dedicated blueprints — but
keeping them here for Phase 1+2 keeps the project trivial to navigate.
"""
from __future__ import annotations

from pathlib import Path

from flask import Flask, abort, render_template, request, send_from_directory

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
        from reviewed import load_reviewed

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
        reviewed_set = load_reviewed(model_dir)
        images = [
            {
                "file": f,
                "status": status_by_file.get(f, "unlabeled"),
                "reviewed": f in reviewed_set,
            }
            for f in files
        ]
        return {"ok": True, "images": images}

    @app.get("/api/models/<model>/annotations")
    def get_annotations(model):
        from annotations import load_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")
        loaded = load_annotations(model_dir, model)
        return {"ok": True, "status": loaded["status"], "data": loaded["data"]}

    @app.put("/api/models/<model>/annotations")
    def put_annotations(model):
        from annotations import save_annotations

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        body = request.get_json(silent=True)
        if not isinstance(body, dict):
            abort(400, description="Body must be a JSON object.")
        if not isinstance(body.get("classes"), list) or not isinstance(body.get("images"), list):
            abort(400, description="Body must contain `classes` and `images` arrays.")

        save_annotations(model_dir, body)
        return {"ok": True}

    @app.get("/img/<model>/<filename>")
    def serve_image(model, filename):
        from slug import is_safe_filename

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404)
        if not is_safe_filename(filename):
            abort(400, description="Invalid filename.")
        return send_from_directory(str(model_dir), filename)

    @app.post("/api/models/<model>/images/<filename>/reviewed")
    def toggle_reviewed(model, filename):
        from reviewed import set_reviewed
        from slug import is_safe_filename

        model = slugify(model)
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")
        if not is_safe_filename(filename):
            abort(400, description="Invalid filename.")
        if not (model_dir / filename).is_file():
            abort(404, description="Image not found.")

        body = request.get_json(silent=True) or {}
        value = bool(body.get("reviewed", True))
        new_state = set_reviewed(model_dir, filename, value)
        return {"ok": True, "reviewed": new_state}

    @app.get("/api/sam/status")
    def sam_status():
        import sam_service

        s = sam_service.status()
        return {"ok": True, **s}

    @app.post("/api/sam/predict")
    def sam_predict():
        import sam_service
        from slug import is_safe_filename

        body = request.get_json(silent=True)
        if not isinstance(body, dict):
            abort(400, description="Body must be a JSON object.")

        model = slugify(body.get("model", ""))
        if not model:
            abort(400, description="Invalid model.")
        model_dir = config.RECORD_ROOT / model
        if not model_dir.is_dir():
            abort(404, description=f"Model '{model}' not found.")

        filename = body.get("image", "")
        if not isinstance(filename, str) or not is_safe_filename(filename):
            abort(400, description="Invalid filename.")
        image_path = model_dir / filename
        if not image_path.is_file():
            abort(404, description="Image not found.")

        point = body.get("point")
        if (
            not isinstance(point, list) or len(point) != 2
            or not all(isinstance(v, (int, float)) and not isinstance(v, bool) for v in point)
        ):
            abort(400, description="point must be [x, y].")
        x, y = int(point[0]), int(point[1])
        if not (0 <= x < 640 and 0 <= y < 480):
            abort(400, description="point out of image bounds.")

        available, error = sam_service.is_available()
        if not available:
            return {"ok": False, "error": error}, 503

        try:
            result = sam_service.predict_polygon(image_path, (x, y))
        except sam_service.MaskTooSmall as e:
            return {"ok": False, "error": str(e)}, 500
        except Exception as e:
            return {"ok": False, "error": f"{e.__class__.__name__}: {e}"}, 500

        return {"ok": True, **result}

    return app


if __name__ == "__main__":
    app = create_app()
    app.run(host="127.0.0.1", port=config.PORT, debug=config.DEBUG)
