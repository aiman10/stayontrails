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
# 1. Install GPU PyTorch + torchvision. Use the index that matches your CUDA driver
#    (`nvidia-smi` shows the version). Python 3.13 needs cu124 or newer — the cu121
#    index only ships wheels up to Python 3.12.
py -3 -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124

# 2. Install Meta's segment-anything package
py -3 -m pip install git+https://github.com/facebookresearch/segment-anything.git

# 3. Download the vit_b checkpoint (~375 MB) into flask-annotator/models/sam/
py -3 scripts/download_sam.py
```

> **Note:** `segment-anything` imports `torchvision` at module load. If you skip it,
> the Flask banner reads "torchvision not installed" and the Smart button stays disabled.

Restart the Flask server. The Smart button (✨, **S** key) becomes enabled
once `/api/sam/status` reports `available: true`.

## Training (YOLO segmentation, optional)

The right panel has a **Start Training** button that's disabled until every
image has status `done`. Once enabled it exports the dataset, persists the
train/val split into `annotations.json`, and spawns Ultralytics training in
a background subprocess. Progress polls every 3 s; finished runs expose a
download link to `best.pt`.

```bash
# 1. Same torch install as for SAM (skip if already done)
py -3 -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124

# 2. Ultralytics
py -3 -m pip install ultralytics
```

Run output lives in `flask-annotator/runs/<model>/<run_id>/`:

- `state.json` — live status, current epoch, error if any
- `train.log` — full subprocess stdout/stderr
- `best.pt` — copied here after a successful run

Datasets are staged at `flask-annotator/_datasets/<model>/`. Both folders
are gitignored.
