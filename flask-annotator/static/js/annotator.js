/**
 * Stay On Trails — Annotator (Konva-based)
 *
 * State and rendering live in this single file. Logic mirrors the original
 * annotate.php structure: API helpers → state → render functions → drawing →
 * event wiring → init.
 */
(function () {
  'use strict';

  const MODEL = window.MODEL;
  const IMG_BASE = '/img/' + MODEL + '/';

  // ── Constants ──────────────────────────────────────────────────────────────
  // Fixed palette: 0 path-oxod, 1 grass, 2 puddle, 3 road. Extras for added classes.
  const PALETTE = ['#7C3AED', '#06D6A0', '#F4A261', '#EF476F', '#22d3ee', '#facc15'];
  const IMG_W = 640, IMG_H = 480;

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    allImages: [],           // [{file, status}]
    annMap: {},              // file -> {status, segments:[], boxes:[]}
    classes: [],             // [{id, name, color}]
    currentIndex: -1,
    selectedClass: 0,
    mode: 'polygon',         // 'polygon' | 'box' | 'select' | 'sam'
    selection: null,         // {type:'segment'|'box', idx:number} | null
    drawing: null,           // current in-progress shape
    dirty: false,
    loadStatus: 'ok',        // 'ok' | 'missing' | 'corrupt' | 'future'
    samAvailable: false,
    samError: null,
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const el = {
    canvasWrap: document.getElementById('canvasWrap'),
    canvasIdle: document.getElementById('canvasIdle'),
    stageDiv: document.getElementById('stage'),
    imgList: document.getElementById('imgList'),
    imgCount: document.getElementById('imgCount'),
    classList: document.getElementById('classList'),
    classAddBtn: document.getElementById('classAddBtn'),
    annoList: document.getElementById('annoList'),
    segCount: document.getElementById('segCount'),
    drawStatus: document.getElementById('drawStatus'),
    saveStatus: document.getElementById('saveStatus'),
    saveBtn: document.getElementById('saveBtn'),
    prevBtn: document.getElementById('prevBtn'),
    nextBtn: document.getElementById('nextBtn'),
    markDoneBtn: document.getElementById('markDoneBtn'),
    modePolygon: document.getElementById('modePolygon'),
    modeBox: document.getElementById('modeBox'),
    modeSmart: document.getElementById('modeSmart'),
    modeSelect: document.getElementById('modeSelect'),
    banner: document.getElementById('banner'),
    modalRoot: document.getElementById('modalRoot'),
    canvasOverlay: document.getElementById('canvasOverlay'),
  };

  // ── Helpers ───────────────────────────────────────────────────────────────
  function classColor(id) {
    const cls = state.classes.find(c => c.id === id);
    return (cls && cls.color) || PALETTE[id % PALETTE.length];
  }
  function className(id) {
    const cls = state.classes.find(c => c.id === id);
    return cls ? cls.name : 'class ' + id;
  }
  function currentFile() {
    return state.currentIndex >= 0 ? state.allImages[state.currentIndex].file : null;
  }
  function currentAnn() {
    const f = currentFile();
    if (!f) return null;
    if (!state.annMap[f]) state.annMap[f] = { status: 'unlabeled', segments: [], boxes: [] };
    return state.annMap[f];
  }
  function shortId(prefix) {
    return prefix + '-' + Math.random().toString(36).slice(2, 8);
  }
  function setSaveStatus(msg, tone) {
    el.saveStatus.textContent = msg;
    el.saveStatus.className = tone || '';
  }
  function setDrawStatus(msg) { el.drawStatus.textContent = msg; }
  function markDirty() { state.dirty = true; }
  function clearDirty() { state.dirty = false; }
  function escapeHTML(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function distance(ax, ay, bx, by) {
    return Math.hypot(ax - bx, ay - by);
  }

  // ── API calls ─────────────────────────────────────────────────────────────
  async function api(method, path, body) {
    const opts = { method, headers: {} };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const r = await fetch(path, opts);
    if (!r.ok) {
      let detail = '';
      try { detail = (await r.json()).description || ''; } catch { }
      throw new Error('HTTP ' + r.status + (detail ? ': ' + detail : ''));
    }
    return r.json();
  }

  async function loadAnnotations() {
    const d = await api('GET', '/api/models/' + encodeURIComponent(MODEL) + '/annotations');
    state.classes = d.data.classes || [];
    state.loadStatus = d.status;
    state.annMap = {};
    for (const img of (d.data.images || [])) {
      state.annMap[img.file] = {
        status: img.status || 'unlabeled',
        segments: (img.annotations && img.annotations.segments) || [],
        boxes: (img.annotations && img.annotations.boxes) || [],
      };
    }
    if (d.status === 'corrupt') {
      el.banner.style.display = 'block';
      el.banner.textContent = 'annotations.json was unreadable. Showing defaults; the bad file will be moved aside on first save.';
    } else if (d.status === 'future') {
      el.banner.style.display = 'block';
      el.banner.textContent = 'annotations.json was written by a newer version. Read-only mode.';
      el.saveBtn.disabled = true;
    }
  }

  async function loadSamStatus() {
    try {
      const d = await api('GET', '/api/sam/status');
      state.samAvailable = !!d.available;
      state.samError = d.error || null;
    } catch (e) {
      state.samAvailable = false;
      state.samError = e.message;
    }
    el.modeSmart.disabled = !state.samAvailable;
    if (!state.samAvailable && state.samError) {
      const msg = 'Smart Polygon disabled: ' + state.samError + '. Manual tools still work.';
      if (el.banner.style.display === 'block') {
        el.banner.textContent = el.banner.textContent + ' · ' + msg;
      } else {
        el.banner.style.display = 'block';
        el.banner.textContent = msg;
      }
    }
  }

  async function loadImageList() {
    const d = await api('GET', '/api/models/' + encodeURIComponent(MODEL) + '/images');
    state.allImages = d.images.map(img => ({
      file: img.file,
      status: (state.annMap[img.file] && state.annMap[img.file].status) || img.status || 'unlabeled',
    }));
    el.imgCount.textContent = state.allImages.length;
    renderImageList();
  }

  async function saveAnnotations(silent) {
    if (state.loadStatus === 'future') return;
    const images = state.allImages.map(img => {
      const a = state.annMap[img.file] || { status: 'unlabeled', segments: [], boxes: [] };
      return {
        file: img.file,
        width: IMG_W,
        height: IMG_H,
        status: a.status || 'unlabeled',
        annotations: { segments: a.segments || [], boxes: a.boxes || [] },
      };
    });
    const payload = { model: MODEL, schemaVersion: 2, classes: state.classes, images };
    el.saveBtn.disabled = true;
    if (!silent) setSaveStatus('Saving…');
    try {
      await api('PUT', '/api/models/' + encodeURIComponent(MODEL) + '/annotations', payload);
      clearDirty();
      if (!silent) {
        setSaveStatus('Saved.', 'ok');
        setTimeout(() => { if (el.saveStatus.textContent === 'Saved.') setSaveStatus(''); }, 2000);
      }
    } catch (e) {
      setSaveStatus('Save failed: ' + e.message, 'warn');
    } finally {
      el.saveBtn.disabled = state.currentIndex < 0;
    }
  }

  // ── Render: image list ────────────────────────────────────────────────────
  function renderImageList() {
    if (!state.allImages.length) {
      el.imgList.innerHTML = '<div class="no-images">No images.</div>';
      return;
    }
    const frag = document.createDocumentFragment();
    state.allImages.forEach((img, i) => {
      const div = document.createElement('div');
      div.className = 'img-item' + (i === state.currentIndex ? ' active' : '');
      const status = (state.annMap[img.file] && state.annMap[img.file].status) || img.status || 'unlabeled';
      div.innerHTML =
        '<img class="img-thumb-sm" src="' + IMG_BASE + encodeURIComponent(img.file) + '" loading="lazy" alt="" />' +
        '<div class="img-meta">' +
        '<div class="img-name">' + img.file + '</div>' +
        '<span class="badge badge-' + status + '">' + status.replace('-', '‑') + '</span>' +
        '</div>';
      div.addEventListener('click', () => selectImage(i));
      frag.appendChild(div);
    });
    el.imgList.innerHTML = '';
    el.imgList.appendChild(frag);
  }

  function updateNavButtons() {
    el.prevBtn.disabled = state.currentIndex <= 0;
    el.nextBtn.disabled = state.currentIndex < 0 || state.currentIndex >= state.allImages.length - 1;
    el.saveBtn.disabled = state.currentIndex < 0 || state.loadStatus === 'future';
    el.markDoneBtn.disabled = state.currentIndex < 0;
  }

  // ── Render: class picker ───────────────────────────────────────────────────
  function renderClassList() {
    el.classList.innerHTML = '';
    if (state.classes.length === 0) {
      el.classList.innerHTML = '<div class="no-images" style="padding:8px">No classes. Click + Add class.</div>';
      return;
    }
    state.classes.forEach((cls, idx) => {
      const row = document.createElement('div');
      row.className = 'class-row' + (cls.id === state.selectedClass ? ' selected' : '');
      row.innerHTML =
        '<span class="class-dot" style="background:' + (cls.color || PALETTE[idx % PALETTE.length]) + '"></span>' +
        '<span class="class-name">' + (idx + 1) + '. ' + escapeHTML(cls.name) + '</span>' +
        '<button class="class-edit" title="Edit class">⚙</button>';
      row.addEventListener('click', e => {
        if (e.target.classList.contains('class-edit')) return;
        state.selectedClass = cls.id;
        renderClassList();
      });
      row.querySelector('.class-edit').addEventListener('click', e => {
        e.stopPropagation();
        openClassModal(cls);
      });
      el.classList.appendChild(row);
    });
  }

  // ── Render: annotations list ───────────────────────────────────────────────
  function renderAnnotationList() {
    const ann = currentAnn();
    if (!ann) { el.annoList.innerHTML = ''; el.segCount.textContent = '0'; return; }
    const items = [];
    (ann.segments || []).forEach((s, idx) => items.push({ kind: 'segment', idx, label: '△ ' + className(s.classId), color: classColor(s.classId), info: s.points.length + ' pts' }));
    (ann.boxes || []).forEach((b, idx) => items.push({ kind: 'box', idx, label: '▭ ' + className(b.classId), color: classColor(b.classId), info: b.w + 'x' + b.h }));
    el.segCount.textContent = items.length;
    el.annoList.innerHTML = '';
    items.forEach(it => {
      const isSel = state.selection && state.selection.type === it.kind && state.selection.idx === it.idx;
      const row = document.createElement('div');
      row.className = 'anno-item' + (isSel ? ' selected' : '');
      row.innerHTML =
        '<span style="display:flex;align-items:center;gap:6px">' +
        '<span class="class-dot" style="background:' + it.color + '"></span>' +
        escapeHTML(it.label) + ' <span style="color:var(--muted)">(' + it.info + ')</span>' +
        '</span>' +
        '<button class="del-btn" title="Delete">×</button>';
      row.addEventListener('click', e => {
        if (e.target.classList.contains('del-btn')) return;
        state.selection = { type: it.kind, idx: it.idx };
        if (state.mode !== 'select') setMode('select');
        renderAnnotationList(); redraw();
      });
      row.querySelector('.del-btn').addEventListener('click', e => {
        e.stopPropagation();
        const a = currentAnn();
        if (it.kind === 'segment') a.segments.splice(it.idx, 1);
        if (it.kind === 'box') a.boxes.splice(it.idx, 1);
        state.selection = null;
        markDirty(); renderAnnotationList(); redraw();
      });
      el.annoList.appendChild(row);
    });
  }

  // ── Class manager modal ────────────────────────────────────────────────────
  function nextClassId() {
    return state.classes.reduce((m, c) => Math.max(m, c.id), -1) + 1;
  }
  function nextClassColor() {
    return PALETTE[state.classes.length % PALETTE.length];
  }
  function countAnnotationsForClass(classId) {
    let n = 0;
    Object.values(state.annMap).forEach(a => {
      n += (a.segments || []).filter(s => s.classId === classId).length;
      n += (a.boxes || []).filter(b => b.classId === classId).length;
    });
    return n;
  }

  function openClassModal(existing) {
    const isNew = !existing;
    const editing = existing
      ? { ...existing }
      : { id: nextClassId(), name: '', color: nextClassColor() };
    const usage = isNew ? 0 : countAnnotationsForClass(existing.id);
    const reassignTarget = isNew ? null : state.classes.find(c => c.id !== existing.id);

    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML =
      '<div class="modal" role="dialog" aria-label="Annotation Editor">' +
      '<h2>' + (isNew ? 'Add class' : 'Edit class') + '</h2>' +
      '<label>Name</label>' +
      '<input type="text" id="m_name" value="' + escapeHTML(editing.name) + '" />' +
      '<label>Color</label>' +
      '<div class="palette" id="m_palette"></div>' +
      '<input type="text" id="m_color" value="' + editing.color + '" placeholder="#rrggbb" />' +
      '<div class="actions">' +
      (isNew ? '' : '<button class="btn btn-warn" id="m_delete" type="button">Delete</button>') +
      '<div style="flex:1"></div>' +
      '<button class="btn" id="m_cancel" type="button">Cancel</button>' +
      '<button class="btn btn-cyan" id="m_save" type="button">Save (Enter)</button>' +
      '</div></div>';
    el.modalRoot.appendChild(backdrop);

    const palette = backdrop.querySelector('#m_palette');
    PALETTE.forEach(col => {
      const sw = document.createElement('div');
      sw.className = 'swatch' + (col.toLowerCase() === editing.color.toLowerCase() ? ' selected' : '');
      sw.style.background = col;
      sw.addEventListener('click', () => {
        editing.color = col;
        backdrop.querySelector('#m_color').value = col;
        palette.querySelectorAll('.swatch').forEach((s, i) => s.classList.toggle('selected', PALETTE[i].toLowerCase() === col.toLowerCase()));
      });
      palette.appendChild(sw);
    });

    backdrop.querySelector('#m_color').addEventListener('input', e => { editing.color = e.target.value; });
    backdrop.querySelector('#m_name').focus();

    function close() { el.modalRoot.removeChild(backdrop); }

    function saveClass() {
      const name = backdrop.querySelector('#m_name').value.trim();
      if (!name) { backdrop.querySelector('#m_name').focus(); return; }
      editing.name = name;
      if (isNew) {
        state.classes.push(editing);
        state.selectedClass = editing.id;
      } else {
        const i = state.classes.findIndex(c => c.id === existing.id);
        if (i >= 0) state.classes[i] = editing;
      }
      markDirty();
      renderClassList(); renderAnnotationList(); redraw();
      close();
    }

    backdrop.querySelector('#m_save').addEventListener('click', saveClass);
    backdrop.querySelector('#m_cancel').addEventListener('click', close);
    backdrop.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); saveClass(); }
      if (e.key === 'Escape') { close(); }
    });

    const delBtn = backdrop.querySelector('#m_delete');
    if (delBtn) {
      delBtn.addEventListener('click', () => {
        if (state.classes.length <= 1) {
          alert('Cannot delete the last remaining class.');
          return;
        }
        const target = reassignTarget;
        const msg = usage > 0
          ? `Delete class "${existing.name}"? ${usage} annotation(s) will be reassigned to "${target.name}".`
          : `Delete class "${existing.name}"?`;
        if (!confirm(msg)) return;
        if (usage > 0) {
          Object.values(state.annMap).forEach(a => {
            (a.segments || []).forEach(s => { if (s.classId === existing.id) s.classId = target.id; });
            (a.boxes || []).forEach(b => { if (b.classId === existing.id) b.classId = target.id; });
          });
        }
        state.classes = state.classes.filter(c => c.id !== existing.id);
        if (state.selectedClass === existing.id) state.selectedClass = state.classes[0].id;
        markDirty();
        renderClassList(); renderAnnotationList(); redraw();
        close();
      });
    }
  }

  // ── Konva stage ───────────────────────────────────────────────────────────
  let stage = null, imgLayer = null, annLayer = null, drawLayer = null, cursorLayer = null;
  let konvaImage = null;
  let crosshairV = null, crosshairH = null;

  function initStage() {
    const rect = el.canvasWrap.getBoundingClientRect();
    stage = new Konva.Stage({
      container: el.stageDiv,
      width: Math.max(1, Math.floor(rect.width)),
      height: Math.max(1, Math.floor(rect.height)),
    });
    imgLayer = new Konva.Layer();
    annLayer = new Konva.Layer();
    drawLayer = new Konva.Layer();
    cursorLayer = new Konva.Layer({ listening: false });
    stage.add(imgLayer); stage.add(annLayer); stage.add(drawLayer); stage.add(cursorLayer);

    // Crosshair lines — drawn in stage (unscaled) space so they always span
    // the full canvas regardless of image letterboxing.
    crosshairV = new Konva.Line({ stroke: '#888', strokeWidth: 1, dash: [8, 4], visible: false });
    crosshairH = new Konva.Line({ stroke: '#888', strokeWidth: 1, dash: [8, 4], visible: false });
    cursorLayer.add(crosshairV); cursorLayer.add(crosshairH);

    new ResizeObserver(resizeStage).observe(el.canvasWrap);
  }

  function updateCrosshair() {
    const pos = stage.getPointerPosition();
    if (!pos) { hideCrosshair(); return; }
    crosshairV.points([pos.x, 0, pos.x, stage.height()]);
    crosshairH.points([0, pos.y, stage.width(), pos.y]);
    crosshairV.visible(true); crosshairH.visible(true);
    cursorLayer.batchDraw();
  }
  function hideCrosshair() {
    if (!crosshairV) return;
    crosshairV.visible(false); crosshairH.visible(false);
    cursorLayer.batchDraw();
  }

  function resizeStage() {
    if (!stage) return;
    const rect = el.canvasWrap.getBoundingClientRect();
    stage.width(Math.max(1, Math.floor(rect.width)));
    stage.height(Math.max(1, Math.floor(rect.height)));
    applyTransform();
    redraw();
  }

  function applyTransform() {
    const sx = stage.width() / IMG_W;
    const sy = stage.height() / IMG_H;
    const scale = Math.min(sx, sy);
    const dx = (stage.width() - IMG_W * scale) / 2;
    const dy = (stage.height() - IMG_H * scale) / 2;
    [imgLayer, annLayer, drawLayer].forEach(layer => {
      layer.scale({ x: scale, y: scale });
      layer.position({ x: dx, y: dy });
    });
    // cursorLayer stays in stage space (no scale/offset) so the crosshair
    // covers the whole canvas, not just the image rect.
  }

  function loadImageInto(file) {
    const img = new window.Image();
    img.onload = () => {
      imgLayer.destroyChildren();
      konvaImage = new Konva.Image({ image: img, x: 0, y: 0, width: IMG_W, height: IMG_H });
      imgLayer.add(konvaImage);
      applyTransform();
      el.canvasIdle.style.display = 'none';
      el.stageDiv.style.display = 'block';
      redraw();
    };
    img.onerror = () => {
      setDrawStatus('Failed to load image.');
      el.canvasIdle.textContent = 'Failed to load image.';
      el.canvasIdle.style.display = '';
    };
    img.src = IMG_BASE + encodeURIComponent(file);
  }

  function imageCoordsFromEvent() {
    const pos = stage.getPointerPosition();
    if (!pos) return null;
    const transform = imgLayer.getAbsoluteTransform().copy().invert();
    const ip = transform.point(pos);
    if (ip.x < 0 || ip.y < 0 || ip.x > IMG_W || ip.y > IMG_H) return null;
    return { x: ip.x, y: ip.y };
  }

  // ── Drawing: polygon ───────────────────────────────────────────────────────
  function startPolygonAt(p) {
    state.drawing = { type: 'polygon', classId: state.selectedClass, points: [p] };
    setDrawStatus('Adding vertices… double-click to close · Backspace to undo · Esc to cancel');
    redraw();
  }

  function addPolygonVertex(p) {
    if (!state.drawing || state.drawing.type !== 'polygon') return;
    state.drawing.points.push(p);
    redraw();
  }

  function commitPolygon() {
    const d = state.drawing;
    if (!d || d.type !== 'polygon' || d.points.length < 3) {
      state.drawing = null; redraw(); return;
    }
    const ann = currentAnn();
    ann.segments.push({
      id: shortId('s'),
      classId: d.classId,
      points: d.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) })),
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    state.allImages[state.currentIndex].status = ann.status;
    state.drawing = null;
    markDirty();
    renderAnnotationList(); renderImageList(); redraw();
    setDrawStatus('Polygon saved. Click to start a new one.');
  }

  function cancelDrawing() {
    state.drawing = null; boxDragStart = null; redraw(); setDrawStatus('Cancelled.');
  }

  // ── Drawing: box ───────────────────────────────────────────────────────────
  let boxDragStart = null;

  function startBoxAt(p) {
    boxDragStart = p;
    state.drawing = { type: 'box', classId: state.selectedClass, x: p.x, y: p.y, w: 0, h: 0 };
    setDrawStatus('Drag to size · release to commit · Esc to cancel');
    redraw();
  }

  function updateBoxDrag(p) {
    if (!boxDragStart || !state.drawing || state.drawing.type !== 'box') return;
    const x1 = Math.min(boxDragStart.x, p.x);
    const y1 = Math.min(boxDragStart.y, p.y);
    const x2 = Math.max(boxDragStart.x, p.x);
    const y2 = Math.max(boxDragStart.y, p.y);
    state.drawing.x = x1; state.drawing.y = y1;
    state.drawing.w = x2 - x1; state.drawing.h = y2 - y1;
    redraw();
  }

  function commitBox() {
    const d = state.drawing;
    boxDragStart = null;
    if (!d || d.type !== 'box' || d.w < 4 || d.h < 4) {
      state.drawing = null; redraw(); return;
    }
    const ann = currentAnn();
    ann.boxes.push({
      id: shortId('b'),
      classId: d.classId,
      x: Math.round(d.x), y: Math.round(d.y),
      w: Math.round(d.w), h: Math.round(d.h),
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    state.allImages[state.currentIndex].status = ann.status;
    state.drawing = null;
    markDirty();
    renderAnnotationList(); renderImageList(); redraw();
    setDrawStatus('Box saved. Drag to start a new one.');
  }

  // ── Render: full canvas redraw ─────────────────────────────────────────────
  function redraw() {
    if (!stage) return;
    annLayer.destroyChildren();
    drawLayer.destroyChildren();
    if (state.currentIndex < 0) { stage.batchDraw(); return; }
    const ann = currentAnn();
    if (!ann) { stage.batchDraw(); return; }

    // Committed segments
    (ann.segments || []).forEach((seg, i) => {
      const col = classColor(seg.classId);
      const flat = [];
      seg.points.forEach(p => { flat.push(p.x, p.y); });
      const isSelected = state.selection && state.selection.type === 'segment' && state.selection.idx === i;
      // Fill alpha: 0x40 (~0.25) normal, 0x66 (~0.4) when selected.
      const line = new Konva.Line({
        points: flat, closed: true, stroke: col, strokeWidth: isSelected ? 3 : 2,
        fill: col + (isSelected ? '66' : '40'),
        name: 'segment', listening: state.mode === 'select',
      });
      line.on('click', () => { state.selection = { type: 'segment', idx: i }; renderAnnotationList(); redraw(); });
      annLayer.add(line);
      if (isSelected && state.mode === 'select') {
        seg.points.forEach((pt, vi) => {
          // 8x8 white square handle, centered on the vertex.
          const handle = new Konva.Rect({
            x: pt.x - 4, y: pt.y - 4, width: 8, height: 8,
            fill: '#fff', stroke: '#222', strokeWidth: 1,
            draggable: true,
          });
          handle.on('dragmove', () => {
            seg.points[vi] = { x: Math.round(handle.x() + 4), y: Math.round(handle.y() + 4) };
            line.points([].concat(...seg.points.map(p => [p.x, p.y])));
            markDirty();
          });
          handle.on('dragend', () => { renderAnnotationList(); });
          annLayer.add(handle);
        });
      }
    });

    // Committed boxes
    (ann.boxes || []).forEach((box, i) => {
      const col = classColor(box.classId);
      const isSelected = state.selection && state.selection.type === 'box' && state.selection.idx === i;
      const rect = new Konva.Rect({
        x: box.x, y: box.y, width: box.w, height: box.h,
        stroke: col, strokeWidth: isSelected ? 3 : 2, fill: col + '33',
        name: 'box', listening: state.mode === 'select',
        draggable: state.mode === 'select' && isSelected,
      });
      rect.on('click', () => { state.selection = { type: 'box', idx: i }; renderAnnotationList(); redraw(); });
      rect.on('dragend', () => {
        box.x = Math.round(rect.x()); box.y = Math.round(rect.y());
        markDirty();
      });
      annLayer.add(rect);
      if (isSelected && state.mode === 'select') {
        const tr = new Konva.Transformer({
          nodes: [rect], rotateEnabled: false, anchorSize: 8,
          enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'middle-left', 'middle-right', 'top-center', 'bottom-center'],
          boundBoxFunc: (oldB, newB) => newB.width < 5 || newB.height < 5 ? oldB : newB,
        });
        annLayer.add(tr);
        rect.on('transformend', () => {
          const w = rect.width() * rect.scaleX();
          const h = rect.height() * rect.scaleY();
          rect.width(Math.round(w)); rect.height(Math.round(h));
          rect.scaleX(1); rect.scaleY(1);
          box.x = Math.round(rect.x()); box.y = Math.round(rect.y());
          box.w = Math.round(rect.width()); box.h = Math.round(rect.height());
          markDirty(); renderAnnotationList(); redraw();
        });
      }
    });

    // In-progress polygon
    if (state.drawing && state.drawing.type === 'polygon') {
      const d = state.drawing;
      const col = classColor(d.classId);
      if (d.points.length > 1) {
        const flat = [];
        d.points.forEach(p => flat.push(p.x, p.y));
        drawLayer.add(new Konva.Line({ points: flat, stroke: col, strokeWidth: 2 }));
      }
      d.points.forEach((p, i) => {
        drawLayer.add(new Konva.Circle({
          x: p.x, y: p.y, radius: i === 0 ? 5 : 4, fill: col,
          stroke: col, strokeWidth: 1.5,
        }));
      });
    }

    // SAM preview
    if (state.drawing && state.drawing.type === 'sam') {
      const d = state.drawing;
      const col = classColor(d.classId);
      const flat = [];
      d.points.forEach(p => flat.push(p.x, p.y));
      drawLayer.add(new Konva.Line({
        points: flat, closed: true, stroke: col, strokeWidth: 2,
        fill: col + '4d', dash: [6, 4],
      }));
      d.points.forEach(p => {
        drawLayer.add(new Konva.Circle({ x: p.x, y: p.y, radius: 3, fill: col }));
      });
    }

    // In-progress box
    if (state.drawing && state.drawing.type === 'box' && state.drawing.w > 0 && state.drawing.h > 0) {
      const col = classColor(state.drawing.classId);
      drawLayer.add(new Konva.Rect({
        x: state.drawing.x, y: state.drawing.y, width: state.drawing.w, height: state.drawing.h,
        stroke: col, strokeWidth: 2, fill: col + '33', dash: [6, 4],
      }));
    }

    stage.batchDraw();
  }

  // ── Image selection ───────────────────────────────────────────────────────
  function selectImage(index) {
    if (state.currentIndex !== -1 && state.currentIndex !== index) {
      saveAnnotations(true);
    }
    state.drawing = null; state.selection = null; boxDragStart = null;
    state.currentIndex = index;
    updateNavButtons();
    renderImageList();
    renderAnnotationList();
    loadImageInto(state.allImages[index].file);
  }

  function navigateImage(delta) {
    const next = state.currentIndex + delta;
    if (next >= 0 && next < state.allImages.length) selectImage(next);
  }

  function markCurrentDone() {
    if (state.currentIndex < 0) return;
    const ann = currentAnn();
    ann.status = 'done';
    state.allImages[state.currentIndex].status = 'done';
    markDirty();
    renderImageList();
    saveAnnotations(false);
  }

  function deleteSelected() {
    if (!state.selection) return;
    const ann = currentAnn();
    if (state.selection.type === 'segment') ann.segments.splice(state.selection.idx, 1);
    if (state.selection.type === 'box') ann.boxes.splice(state.selection.idx, 1);
    state.selection = null;
    markDirty();
    renderAnnotationList(); redraw();
  }

  function setMode(m) {
    state.mode = m;
    state.drawing = null;
    state.selection = null;
    boxDragStart = null;
    [el.modePolygon, el.modeBox, el.modeSmart, el.modeSelect].forEach(b => b.classList.remove('active'));
    if (m === 'polygon') el.modePolygon.classList.add('active');
    if (m === 'box') el.modeBox.classList.add('active');
    if (m === 'sam') el.modeSmart.classList.add('active');
    if (m === 'select') el.modeSelect.classList.add('active');
    setDrawStatus(
      m === 'polygon' ? 'Polygon mode · Click to add vertices · Double-click to close · Esc to cancel' :
      m === 'box' ? 'Box mode · Drag to draw a rectangle · Esc to cancel' :
      m === 'sam' ? 'Smart mode · Click an object · Wait for SAM · Enter to keep, Esc to discard' :
      'Select mode · Click an annotation to edit · Delete to remove'
    );
    redraw();
  }

  async function runSamAt(p) {
    el.canvasOverlay.classList.remove('error');
    el.canvasOverlay.textContent = 'Predicting…';
    el.canvasOverlay.style.display = 'flex';
    try {
      const body = { model: MODEL, image: currentFile(), point: [Math.round(p.x), Math.round(p.y)] };
      const d = await api('POST', '/api/sam/predict', body);
      const points = (d.polygon || []).map(([x, y]) => ({ x, y }));
      if (points.length < 3) throw new Error('polygon too small');
      state.drawing = { type: 'sam', classId: state.selectedClass, points, score: d.score };
      setDrawStatus('SAM preview · Enter to keep · Esc to discard · Score ' + (d.score || 0).toFixed(2));
      el.canvasOverlay.style.display = 'none';
      redraw();
    } catch (e) {
      el.canvasOverlay.classList.add('error');
      el.canvasOverlay.textContent = 'SAM failed: ' + e.message;
      setTimeout(() => { el.canvasOverlay.style.display = 'none'; }, 2000);
    }
  }

  function commitSamPreview() {
    const d = state.drawing;
    if (!d || d.type !== 'sam' || d.points.length < 3) {
      state.drawing = null; redraw(); return;
    }
    const ann = currentAnn();
    ann.segments.push({
      id: shortId('s'),
      classId: d.classId,
      source: 'sam',
      points: d.points.map(p => ({ x: Math.round(p.x), y: Math.round(p.y) })),
    });
    if (ann.status === 'unlabeled') ann.status = 'in-progress';
    state.allImages[state.currentIndex].status = ann.status;
    state.drawing = null;
    markDirty();
    renderAnnotationList(); renderImageList(); redraw();
    setDrawStatus('SAM polygon saved. Click again for another.');
  }

  // ── Stage events ───────────────────────────────────────────────────────────
  function bindStageEvents() {
    stage.on('mousedown touchstart', () => {
      if (state.currentIndex < 0) return;
      if (state.mode !== 'box') return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      startBoxAt(p);
    });

    stage.on('mousemove touchmove', () => {
      updateCrosshair();
      if (state.mode !== 'box' || !boxDragStart) return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      updateBoxDrag(p);
    });

    stage.on('mouseleave', hideCrosshair);
    stage.on('mouseenter', updateCrosshair);

    stage.on('mouseup touchend', () => {
      if (state.mode !== 'box') return;
      if (boxDragStart) commitBox();
    });

    stage.on('click', (evt) => {
      if (state.currentIndex < 0) return;
      const p = imageCoordsFromEvent();
      if (!p) return;
      if (state.mode === 'polygon') {
        if (!state.drawing) {
          startPolygonAt(p);
          return;
        }
        // Click near first vertex closes the polygon.
        const first = state.drawing.points[0];
        const pos = stage.getPointerPosition();
        const firstScreen = imgLayer.getAbsoluteTransform().point(first);
        if (state.drawing.points.length >= 3 &&
          distance(pos.x, pos.y, firstScreen.x, firstScreen.y) < 10) {
          commitPolygon();
          return;
        }
        addPolygonVertex(p);
        return;
      }
      if (state.mode === 'sam') {
        if (state.drawing) return;
        runSamAt(p);
        return;
      }
      if (state.mode === 'select') {
        // Clicking empty stage deselects (clicks on shapes are handled by the shape's own click handler).
        if (evt && evt.target === stage && state.selection) {
          state.selection = null;
          renderAnnotationList();
          redraw();
        }
      }
    });

    stage.on('dblclick', () => {
      if (state.mode !== 'polygon' || !state.drawing) return;
      // The browser fired click → click → dblclick — pop the duplicate.
      if (state.drawing.points.length > 3) state.drawing.points.pop();
      commitPolygon();
    });
  }

  // ── Keyboard ──────────────────────────────────────────────────────────────
  window.addEventListener('keydown', e => {
    // Don't intercept shortcuts while typing in inputs (modals).
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'Escape') {
      if (state.drawing) cancelDrawing();
      else if (state.selection) { state.selection = null; renderAnnotationList(); redraw(); }
      return;
    }
    if (e.key === 'Backspace') {
      if (state.drawing && state.drawing.type === 'polygon') {
        e.preventDefault();
        if (state.drawing.points.length > 1) {
          state.drawing.points.pop();
          redraw();
        } else {
          cancelDrawing();
        }
        return;
      }
      if (state.mode === 'select' && state.selection) {
        e.preventDefault();
        deleteSelected();
        return;
      }
    }
    if (e.key === 'Delete' && state.selection) { deleteSelected(); return; }
    if (e.key === 'Enter' && state.drawing) {
      if (state.drawing.type === 'polygon') commitPolygon();
      else if (state.drawing.type === 'sam') commitSamPreview();
      return;
    }
    if (e.key === 'ArrowLeft') { e.preventDefault(); navigateImage(-1); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); navigateImage(1); return; }
    if (e.key === 'd' || e.key === 'D') { markCurrentDone(); return; }
    if (e.key === 'p' || e.key === 'P') { setMode('polygon'); return; }
    if (e.key === 'b' || e.key === 'B') { setMode('box'); return; }
    if (e.key === 'v' || e.key === 'V') { setMode('select'); return; }
    if (e.key === 's' || e.key === 'S') {
      if ((e.ctrlKey || e.metaKey)) return; // Ctrl+S handled below
      if (state.samAvailable) setMode('sam');
      return;
    }
    if (/^[1-9]$/.test(e.key)) {
      const idx = parseInt(e.key, 10) - 1;
      if (state.classes[idx]) { state.selectedClass = state.classes[idx].id; renderClassList(); }
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      saveAnnotations(false);
    }
  });

  // ── Button wiring ─────────────────────────────────────────────────────────
  el.modePolygon.addEventListener('click', () => setMode('polygon'));
  el.modeBox.addEventListener('click', () => setMode('box'));
  el.modeSmart.addEventListener('click', () => { if (state.samAvailable) setMode('sam'); });
  el.modeSelect.addEventListener('click', () => setMode('select'));
  el.saveBtn.addEventListener('click', () => saveAnnotations(false));
  el.prevBtn.addEventListener('click', () => navigateImage(-1));
  el.nextBtn.addEventListener('click', () => navigateImage(1));
  el.markDoneBtn.addEventListener('click', markCurrentDone);
  el.classAddBtn.addEventListener('click', () => openClassModal(null));

  // ── beforeunload guard ───────────────────────────────────────────────────
  window.addEventListener('beforeunload', e => {
    if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // ── Init ──────────────────────────────────────────────────────────────────
  async function init() {
    initStage();
    bindStageEvents();
    try {
      await loadAnnotations();
      await loadImageList();
      await loadSamStatus();
      renderClassList();
      renderAnnotationList();
      updateNavButtons();
    } catch (e) {
      setSaveStatus('Load error: ' + e.message, 'warn');
    }
  }

  init();

})();
