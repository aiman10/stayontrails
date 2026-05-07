# Flask Annotator

Replacement for `annotate.php`. Multi-class polygon and bounding-box annotation,
reading and writing `recorded_routes/<model>/annotations.json` directly.

## Run

```bash
cd flask-annotator
pip install -r requirements.txt
python app.py
```

Then open http://localhost:5001/?model=<your-model-slug>.

## Test

```bash
pytest flask-annotator/tests -v
```

## Smart Polygon (SAM, optional)

Lets you click a point and have SAM (`vit_b`) generate a polygon around the
object under the cursor. Without this, the rest of the tool works normally —
the Smart button stays disabled and a banner explains why.

```bash
# 1. Install GPU PyTorch (Windows + CUDA 12.1)
pip install torch --index-url https://download.pytorch.org/whl/cu121

# 2. Install Meta's segment-anything package
pip install git+https://github.com/facebookresearch/segment-anything.git

# 3. Download the vit_b checkpoint (~375 MB) into flask-annotator/models/sam/
python scripts/download_sam.py
```

Restart the Flask server. The Smart button (✨, **S** key) becomes enabled
once `/api/sam/status` reports `available: true`.
