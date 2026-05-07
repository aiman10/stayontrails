# Manual smoke checklist

Run before each commit that touches frontend code, and after merging branches.

## Setup
- [ ] `python flask-annotator/app.py` starts without errors.
- [ ] `pytest flask-annotator/tests -v` is green.

## Drawing
- [ ] Open `/?model=<existing>` — image list populates, status badges look right.
- [ ] Click an image — it loads centered/letterboxed in the canvas.
- [ ] Polygon mode (P): click vertices, double-click to close. The polygon appears in the right-panel list with the correct class.
- [ ] Box mode (B): drag a rectangle, release. The box appears in the list.
- [ ] Select mode (V): click a box → Transformer handles appear; resize and confirm new size persists. Click a polygon → vertex handles appear; drag a vertex.
- [ ] Esc cancels in-progress drawing. Backspace removes last vertex while drawing.
- [ ] Delete key removes the selected annotation.

## Classes
- [ ] + Add class opens the modal. Save with a new name + color → it appears in the list.
- [ ] Click ⚙ on a class → modal pre-filled. Rename and save → list updates.
- [ ] Delete a class with 0 annotations → simple confirmation, class removed.
- [ ] Delete a class with N annotations → reassignment confirmation, target class noted, annotations updated.
- [ ] Cannot delete the last remaining class.
- [ ] Keyboard 1–9 quick-selects classes.

## Save / load
- [ ] Save button → "Saving…" → "Saved." (green). Reload — annotations persist.
- [ ] Switch images mid-draw → in-progress polygon discarded, previous image auto-saved silently.
- [ ] Edit something, try to close the tab → `beforeunload` warning fires.
- [ ] Stop the Flask server, click Save → red "Save failed: …" appears, button re-enabled.

## Status & navigation
- [ ] First annotation on an unlabeled image flips status to in-progress (yellow badge).
- [ ] D / Mark Done flips status to done (green badge).
- [ ] Arrow keys move prev/next.

## Smart Polygon (SAM)
- [ ] On a fresh page load, `GET /api/sam/status` returns `{available: true, device: "cuda"}` (or `cpu`). Smart button is enabled.
- [ ] If SAM dependencies missing, banner reads "Smart Polygon disabled: …" and Smart button is disabled.
- [ ] **S** key activates Smart mode; status bar updates.
- [ ] Click on a path in the image — overlay shows "Predicting…" — within ~1 s the dashed preview polygon appears.
- [ ] Repeated clicks on the same image are noticeably faster (embedding cache hit).
- [ ] Switching to a different image and clicking re-loads the embedding (slight delay).
- [ ] **Enter** commits the SAM polygon. The annotation list shows it; the JSON `segments` entry has `source: "sam"`.
- [ ] **Esc** discards the preview cleanly.
- [ ] Click in a featureless spot → SAM may return a tiny mask → overlay shows "SAM failed: …" briefly, no preview committed.

## Bad data paths
- [ ] Corrupt `annotations.json`: app shows yellow banner, doesn't crash; first save rotates the bad file aside.
- [ ] Unknown model in URL: 404 page (matches PHP error look).
