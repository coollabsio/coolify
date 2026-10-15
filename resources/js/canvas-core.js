// Shared canvas core: API calls, pan/zoom and page mounting for the deploy
// canvas and the environment canvas (Svelte components in resources/js/canvas/).
//
// The top level stays DOM-free: `node --test` imports the canvases.

export const MIN_ZOOM = 0.25;
export const MAX_ZOOM = 2;

// ── Collisions ────────────────────────────────────────────────────────

/** Space that cards and frames keep between them. */
export const BOX_GAP = 16;

/** True when boxes `a` and `b` ({ x, y, w, h }) are at least `gap` apart. */
export const apart = (a, b, gap = BOX_GAP) => a.x >= b.x + b.w + gap || a.x + a.w + gap <= b.x || a.y >= b.y + b.h + gap || a.y + a.h + gap <= b.y;

/**
 * The free spot nearest to `box` ({ x, y, w, h }) that keeps `gap` clear of
 * every box in `others` (and passes `fits`, when given): the box itself, or it
 * pushed against an edge (or a corner) of the boxes in the way. Null when no
 * such spot is found.
 */
export function freeSpot(box, others, gap = BOX_GAP, fits = () => true) {
  const free = b => others.every(o => apart(b, o, gap)) && fits(b);
  if (free(box)) return box;
  const xs = [box.x, ...others.flatMap(o => [o.x - box.w - gap, o.x + o.w + gap])];
  const ys = [box.y, ...others.flatMap(o => [o.y - box.h - gap, o.y + o.h + gap])];
  let best = null;
  let bestDistance = Infinity;
  for (const x of xs) {
    for (const y of ys) {
      const distance = Math.hypot(x - box.x, y - box.y);
      if (distance < bestDistance && free({ ...box, x, y })) {
        best = { ...box, x, y };
        bestDistance = distance;
      }
    }
  }
  return best;
}

// ── API (Coolify's API controllers at /canvas/api, browser session) ───

/** The team of the open canvas page (data-team), fixed when the page was opened. */
function canvasTeam() {
  return document.querySelector('[data-environment-canvas], [data-deploy-canvas]')?.dataset.team || '';
}

export class ApiError extends Error {
  constructor(status, body) {
    const errors = body?.errors ? Object.values(body.errors).flat().map(String) : [];
    super([body?.message || `Request failed (HTTP ${status}).`, ...errors.filter(e => e !== body?.message)].join(' '));
    this.status = status;
    this.body = body;
  }
}

export async function api(method, path, body, options = {}) {
  const response = await fetch(`/canvas/api${path}`, {
    method,
    credentials: 'same-origin',
    keepalive: !!options.keepalive,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      'X-Canvas-Team': canvasTeam(),
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try {
    data = await response.json();
  } catch {
    // Empty body.
  }
  if (!response.ok) throw new ApiError(response.status, data);
  return data;
}

// ── Pan and zoom ──────────────────────────────────────────────────────

/** Viewport position inside the root, so the root's dots (floating nav, see app.css) line up with the viewport's. */
export function canvasDotsOffset(root, viewport) {
  const r = root.getBoundingClientRect();
  const v = viewport.getBoundingClientRect();
  return { x: v.left - r.left, y: v.top - r.top };
}

export function setCanvasDots(root, size, x, y, atMaxZoom = false) {
  root.style.setProperty('--canvas-dots-size', `${size}px ${size}px`);
  root.style.setProperty('--canvas-dots-position', `${x}px ${y}px`);
  // Fully zoomed in: the grid dots turn into small Coolify logos (app.css).
  root.classList.toggle('canvas-zoom-max', atMaxZoom);
}

/**
 * The browser zoom shortcut in a keydown event (Cmd/Ctrl with +, - or 0), so the
 * canvas can zoom itself instead: 'in', 'out', 'reset', or null for other keys.
 */
export function zoomShortcut(e) {
  if (!(e.ctrlKey || e.metaKey) || e.altKey) return null;
  if (e.key === '+' || e.key === '=') return 'in';
  if (e.key === '-' || e.key === '_') return 'out';
  if (e.key === '0') return 'reset';
  return null;
}

/**
 * Wheel zoom (ctrl/cmd) and pan, and drag-to-pan on the viewport background.
 * getView() returns the current { x, y, z }, which is changed in place.
 * canPan(event) decides which presses pan; onPanEnd(moved) runs on release.
 */
export function panZoom({ root, viewport, world, zoomLabel, getView, onChange, canPan, onPanStart, onPanEnd }) {
  function apply() {
    const { x, y, z } = getView();
    const offset = canvasDotsOffset(root, viewport);
    world.style.transform = `translate(${x}px, ${y}px) scale(${z})`;
    viewport.style.backgroundSize = `${20 * z}px ${20 * z}px`;
    viewport.style.backgroundPosition = `${x}px ${y}px`;
    setCanvasDots(root, 20 * z, x + offset.x, y + offset.y, z >= MAX_ZOOM);
    zoomLabel.textContent = `${Math.round(z * 100)}%`;
    onChange?.();
  }
  function toWorld(cx, cy) {
    const view = getView();
    const r = viewport.getBoundingClientRect();
    return { x: (cx - r.left - view.x) / view.z, y: (cy - r.top - view.y) / view.z };
  }
  /** Zooms to z around a screen point (the viewport centre by default). */
  function zoomTo(z, cx, cy) {
    const view = getView();
    const r = viewport.getBoundingClientRect();
    const px = cx ?? r.left + r.width / 2;
    const py = cy ?? r.top + r.height / 2;
    const before = toWorld(px, py);
    view.z = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, z));
    view.x = px - r.left - before.x * view.z;
    view.y = py - r.top - before.y * view.z;
    apply();
  }

  viewport.addEventListener('wheel', e => {
    e.preventDefault();
    const view = getView();
    if (e.ctrlKey || e.metaKey) {
      zoomTo(view.z * Math.exp(-e.deltaY * 0.01), e.clientX, e.clientY);
    } else {
      view.x -= e.deltaX;
      view.y -= e.deltaY;
      apply();
    }
  }, { passive: false });
  // Touch: two fingers pinch to zoom and pan, anywhere on the viewport (also on cards).
  // Capture phase on the viewport, so the pinch fingers do not also move cards or pan with one finger.
  const touches = new Map();
  const pinchFingers = new Set();
  let pinch = null;
  const pinchState = () => {
    const [a, b] = [...touches.values()];
    return { cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2, d: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)) };
  };
  viewport.addEventListener('pointerdown', e => {
    if (e.pointerType !== 'touch') return;
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (touches.size !== 2) return;
    for (const id of touches.keys()) pinchFingers.add(id);
    const start = pinchState();
    pinch = { ...start, z: getView().z, ...toWorld(start.cx, start.cy) };
    onPanStart?.();
    e.stopImmediatePropagation();
  }, true);
  viewport.addEventListener('pointermove', e => {
    if (!touches.has(e.pointerId)) return;
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (!pinchFingers.has(e.pointerId)) return;
    // Pinch fingers stay out of card drags and one-finger pans until they lift.
    // Immediate: a one-finger pan listens on the viewport itself (capture listeners run first there).
    e.stopImmediatePropagation();
    if (!pinch || touches.size < 2) return;
    const now = pinchState();
    const view = getView();
    const r = viewport.getBoundingClientRect();
    view.z = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, pinch.z * now.d / pinch.d));
    view.x = now.cx - r.left - pinch.x * view.z;
    view.y = now.cy - r.top - pinch.y * view.z;
    apply();
  }, true);
  // The release still reaches the drag that the first finger started, so that drag ends.
  const liftFinger = e => {
    touches.delete(e.pointerId);
    pinchFingers.delete(e.pointerId);
    if (touches.size < 2) pinch = null;
  };
  viewport.addEventListener('pointerup', liftFinger, true);
  viewport.addEventListener('pointercancel', liftFinger, true);

  viewport.addEventListener('pointerdown', e => {
    if (e.button !== 0 || pinch || !canPan(e)) return;
    onPanStart?.();
    const view = getView();
    const start = { cx: e.clientX, cy: e.clientY, x: view.x, y: view.y };
    let moved = false;
    try {
      viewport.setPointerCapture(e.pointerId);
    } catch {
      // Synthetic or already released pointer.
    }
    viewport.classList.add('panning');
    const move = ev => {
      if (!moved && Math.hypot(ev.clientX - start.cx, ev.clientY - start.cy) < 4) return;
      moved = true;
      view.x = start.x + ev.clientX - start.cx;
      view.y = start.y + ev.clientY - start.cy;
      apply();
    };
    const up = () => {
      viewport.classList.remove('panning');
      viewport.removeEventListener('pointermove', move);
      viewport.removeEventListener('pointerup', up);
      viewport.removeEventListener('pointercancel', up);
      onPanEnd?.(moved);
    };
    viewport.addEventListener('pointermove', move);
    viewport.addEventListener('pointerup', up);
    viewport.addEventListener('pointercancel', up);
  });

  return { apply, toWorld, zoomTo };
}

// ── Page mount (initial load and Livewire SPA navigation) ─────────────

/**
 * Mounts a canvas into the page's container unless it already holds the canvas root.
 * mount(container) may return { root, unmount }, unmounted once its root leaves the page.
 */
export function mountOnPage(selector, rootSelector, mount) {
  if (typeof document === 'undefined') return;
  let active = null;
  const run = () => {
    if (active && !active.root.isConnected) {
      active.unmount();
      active = null;
    }
    const container = document.querySelector(selector);
    if (!container || container.querySelector(rootSelector)) return;
    active?.unmount();
    active = mount(container) || null;
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
  document.addEventListener('livewire:navigated', run);
}
