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
