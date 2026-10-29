// Deploy canvas runtime: the state of the open canvas, what the steps do to
// it (add, connect, run…), and the Svelte actions around the imperative
// helpers of canvas-core.js (pan/zoom, top bar placement, card sizes).

import { flushSync, tick } from 'svelte';
import { api, freeSpot, MIN_ZOOM, panZoom } from '../../canvas-core.js';
import {
  canFollow, defaults, domainList, fresh, LOG_POLL_MS, lists, load, multiOut, nextOf, nodeById, outsOf, planOf,
  resourceHref, save, sleep, targetFrom, TYPES, uid, uniqueLabels, withDeploy, withoutSecrets, withTarget,
} from '../../deploy-canvas.js';

export const PORT_Y = 26; // header centre (3.25rem / 2)
const CARD_W = 296;

const emptyRun = () => ({ open: false, title: 'Deploy', sub: '', steps: [], panes: [], active: null, defaultHref: '', openHref: '', showOpen: false });

export const cv = $state({
  canAdd: false, // may create projects and environments (from the page)
  target: null, // the environment that opened the canvas
  flow: fresh(),
  view: { x: 40, y: 40, z: 1 },
  runState: {}, // node id -> { state: running|done|failed, message }
  invalid: {}, // node id -> true: a required field is empty
  // The last successful run's addresses, shown as a pill after Deploy.
  result: null, // { after: node id, urls: [], href }
  running: false, // false, 'save' or 'deploy'
  sizes: {}, // node id -> { w, h } of its card
  listsVersion: 0, // bumped when an option list loads
  menu: null, // the "Add the next step" menu: { prev, valid, p, left, top }
  pendingLine: null, // the dragged line, kept while its menu is open
  temp: null, // the line being dragged from a port
  armed: null, // node id of the port being dragged
  dropTarget: null, // node id the dragged line would connect to
  toast: null, // { text, bad }
  dropdown: null, // { trigger, items, current, onPick, add }
  run: emptyRun(), // the run panel
});

// DOM of the open canvas (set by the actions below); mount counts the
// canvases, so a run of an earlier one stops writing here.
export const els = { root: null, stage: null, viewport: null, pz: null, nodeEls: new Map(), mount: 0, fitOnOpen: false };

lists.onChange = () => { cv.listsVersion++; };

export function init(container) {
  els.mount++;
  els.nodeEls.clear();
  cv.canAdd = container.dataset.canAdd === '1';
  if (load('deployed', false)) {
    save('flow', null);
    save('result', null);
    save('deployed', false);
  }
  cv.target = targetFrom(location.search);
  cv.flow = withTarget(withDeploy(load('flow', null) || fresh()), cv.target);
  save('flow', withoutSecrets(cv.flow)); // drops secrets that older versions stored
  // Small screens: the default view is made for desktop, so the first visit fits the steps instead.
  els.fitOnOpen = !load('view', null) && !matchMedia('(min-width: 768px)').matches;
  cv.view = load('view', { x: 40, y: 40, z: 1 });
  cv.result = load('result', null);
  Object.assign(cv, { runState: {}, invalid: {}, running: false, sizes: {}, menu: null, pendingLine: null, temp: null, armed: null, dropTarget: null, toast: null, dropdown: null, run: emptyRun() });
}

/** The canvas left the page: its runs stop. */
export function leave() {
  els.mount++;
}

// ── Actions ────────────────────────────────────────────────────────────

/** use:pan on the viewport: wheel and pinch zoom, drag to pan. */
export function pan(viewport) {
  const world = viewport.querySelector('.cv-world');
  const edges = viewport.querySelector('.cv-edges');
  els.viewport = viewport;
  els.stage = viewport.parentElement;
  els.root = viewport.closest('.cv-root');
  els.pz = panZoom({
    root: els.root,
    viewport,
    world,
    zoomLabel: {}, // CanvasControls shows cv.view.z
    getView: () => cv.view,
    onChange: closeDropdown,
    canPan: e => [viewport, world, edges].includes(e.target),
    onPanStart: closeMenu,
  });
  // The root paints the dots, so move them with the viewport when the Steps panel folds or the window resizes.
  const sizes = new ResizeObserver(() => els.pz.apply());
  sizes.observe(viewport);
  return { destroy: () => sizes.disconnect() };
}

/** use:track on a card: its element (for measuring) and its size (for the lines). */
export function track(node, id) {
  els.nodeEls.set(id, node);
  const measure = () => { cv.sizes[id] = { w: node.offsetWidth, h: node.offsetHeight }; };
  measure();
  const observer = new ResizeObserver(measure);
  observer.observe(node);
  return {
    destroy() {
      observer.disconnect();
      if (els.nodeEls.get(id) === node) els.nodeEls.delete(id);
      delete cv.sizes[id];
    },
  };
}

// ── Canvas: geometry, fit ──────────────────────────────────────────────
// Port positions in world coordinates.
export const outPoint = n => ({ x: n.x + (cv.sizes[n.id]?.w || CARD_W), y: n.y + PORT_Y });
export const inPoint = n => ({ x: n.x, y: n.y + PORT_Y });
export const curve = (a, b) => {
  const dx = Math.max(40, Math.abs(b.x - a.x) / 2);
  return `M ${a.x} ${a.y} C ${a.x + dx} ${a.y}, ${b.x - dx} ${b.y}, ${b.x} ${b.y}`;
};
const toWorld = (cx, cy) => els.pz.toWorld(cx, cy);
const width = n => els.nodeEls.get(n.id)?.offsetWidth || CARD_W;
const height = n => els.nodeEls.get(n.id)?.offsetHeight || 160;
// A card's box in world units (its rendered size, or a usual card size).
const boxOf = n => ({ x: n.x, y: n.y, w: width(n), h: height(n) });

// The part of the viewport that the floating Steps panel does not cover,
// relative to the viewport (on small screens the panel is above it).
function openArea() {
  const r = els.viewport.getBoundingClientRect();
  const p = els.root.querySelector('.cv-palette').getBoundingClientRect();
  const left = p.bottom > r.top ? Math.max(0, p.right - r.left) : 0;
  return { left, width: r.width - left, height: r.height };
}

export function fit() {
  if (!els.nodeEls.size) return;
  let minX = Infinity; let minY = Infinity; let maxX = -Infinity; let maxY = -Infinity;
  for (const n of cv.flow.nodes) {
    const node = els.nodeEls.get(n.id);
    minX = Math.min(minX, n.x);
    minY = Math.min(minY, n.y);
    maxX = Math.max(maxX, n.x + (node?.offsetWidth || 280));
    maxY = Math.max(maxY, n.y + (node?.offsetHeight || 120));
  }
  const pill = els.root.querySelector('.cv-result');
  if (pill) {
    maxX = Math.max(maxX, pill.offsetLeft + pill.offsetWidth);
    maxY = Math.max(maxY, pill.offsetTop + pill.offsetHeight);
  }
  const r = openArea();
  const pad = 60;
  const view = cv.view;
  view.z = Math.min(1.2, Math.max(MIN_ZOOM, Math.min((r.width - pad * 2) / (maxX - minX), (r.height - pad * 2) / (maxY - minY))));
  view.x = r.left + (r.width - (maxX - minX) * view.z) / 2 - minX * view.z;
  view.y = (r.height - (maxY - minY) * view.z) / 2 - minY * view.z;
  els.pz.apply();
}

// ── Toast, menus, dropdown ─────────────────────────────────────────────
let toastTimer = 0;
export function toast(text, bad = false) {
  cv.toast = { text, bad };
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { cv.toast = null; }, bad ? 4200 : 2600);
}

export function closeMenu() {
  cv.menu = null;
  cv.pendingLine = null;
}
// Close an older menu but keep the line being dropped.
function openNextMenu(prev, cx, cy, p) {
  cv.menu = null;
  const valid = Object.keys(TYPES).filter(type => canFollow(prev, type, null, cv.flow));
  if (!valid.length) return toast('Nothing can follow this step.', true);
  const r = els.stage.getBoundingClientRect();
  cv.menu = { prev: prev.id, valid, p, left: Math.min(cx - r.left, r.width - 220), top: Math.min(cy - r.top, r.height - 40 * valid.length - 40) };
}
export function pickNext(type) {
  const { prev, p } = cv.menu;
  closeMenu();
  const n = addNode(type, p.x + 8, p.y - PORT_Y, true);
  connect(prev, n.id);
}

// Our dropdown panel (fixed in the canvas root, so zoom never shrinks it).
export function closeDropdown() {
  cv.dropdown = null;
}
export function openDropdown(trigger, items, current, onPick, add = null) {
  if (cv.dropdown?.trigger === trigger) return closeDropdown();
  cv.dropdown = { trigger, items, current, onPick, add };
}

// ── Flow edits ─────────────────────────────────────────────────────────
// A structural change redraws the cards, which clears their invalid marks.
const redraw = () => { cv.invalid = {}; };

export function connect(from, to) {
  const a = nodeById(from, cv.flow);
  cv.flow.edges = cv.flow.edges.filter(e => !(e.from === from && e.to === to) && (multiOut(a) || e.from !== from) && e.to !== to);
  cv.flow.edges.push({ from, to });
  redraw();
}
export function removeEdge(edge) {
  if (cv.running) return;
  cv.flow.edges = cv.flow.edges.filter(e => e !== edge);
  redraw();
  toast('Connection removed.');
}
export function addNode(type, x, y, quiet) {
  cv.flow.nodes.push({ id: uid(), type, x: Math.round(x), y: Math.round(y), data: defaults(type) });
  const n = cv.flow.nodes.at(-1);
  redraw();
  flushSync(); // render the card, to measure it
  settle(n);
  if (!quiet) toast(`${TYPES[type].title} added. Connect it from the dot of the step before.`);
  return n;
}
export function removeNode(id) {
  if (cv.running) return;
  cv.flow.nodes = cv.flow.nodes.filter(n => n.id !== id);
  cv.flow.edges = cv.flow.edges.filter(e => e.from !== id && e.to !== id);
  delete cv.runState[id];
  redraw();
}
export function resetCanvas() {
  if (cv.running) return;
  cv.flow = withTarget(fresh(), cv.target);
  cv.runState = {};
  cv.result = null;
  save('result', null);
  save('deployed', false);
  redraw();
}
export function hideResult() {
  cv.result = null;
  save('result', null);
}
// A field change. Fields loaded from the one changed (`rerender`) start empty again.
export function setField(n, f, value) {
  const fields = TYPES[n.type].fields;
  n.data[f.key] = value;
  f.after?.(n.data);
  if (f.rerender) {
    for (const other of fields) if (other.load && other !== f && other.key !== f.key && fields.indexOf(other) > fields.indexOf(f)) delete n.data[other.key];
  }
  delete cv.invalid[n.id];
}

// Move a card to the nearest spot where it does not cover another card.
function settle(n) {
  const spot = freeSpot(boxOf(n), cv.flow.nodes.filter(m => m !== n).map(boxOf));
  if (!spot || (spot.x === n.x && spot.y === n.y)) return;
  n.x = Math.round(spot.x);
  n.y = Math.round(spot.y);
}
// Click on a step in the list: add it after the step it most likely follows.
function appendToChain(type) {
  const flow = cv.flow;
  const plan = planOf(flow);
  // The newest step in the tree this one can follow, with room for it.
  const inTree = [plan.start, ...plan.servers, ...plan.lines.flatMap(l => [l.source, ...l.settings])];
  const parent = inTree.reverse().find(n => canFollow(n, type, null, flow) && (multiOut(n) || !nextOf(n.id, flow)));
  if (!parent) return addNode(type, ...centre());
  const siblings = multiOut(parent) ? outsOf(parent.id, flow).map(id => nodeById(id, flow)).filter(Boolean) : [];
  const y = siblings.length ? Math.max(...siblings.map(n => n.y + height(n))) + 40 : parent.y;
  const n = addNode(type, parent.x + width(parent) + 80, y, true);
  connect(parent.id, n.id);
}
function centre() {
  const r = els.viewport.getBoundingClientRect();
  const a = openArea();
  const p = toWorld(r.left + a.left + a.width / 2, r.top + r.height / 2);
  return [p.x - 140, p.y - 60];
}

// ── Pointer drags ──────────────────────────────────────────────────────

/** A palette step: drag it to the canvas, or click to append it to the chain. */
export function dragFromPalette(type, event) {
  if (event.button !== 0) return;
  const item = event.currentTarget;
  const startX = event.clientX;
  const startY = event.clientY;
  let ghost = null;
  const move = e => {
    if (!ghost && Math.hypot(e.clientX - startX, e.clientY - startY) < 5) return;
    if (!ghost) {
      ghost = item.cloneNode(true);
      Object.assign(ghost.style, { position: 'fixed', zIndex: 2147483001, width: `${item.offsetWidth}px`, pointerEvents: 'none', opacity: 0.9, background: 'var(--cv-ghost)', borderRadius: '8px', boxShadow: '0 8px 24px -8px rgb(0 0 0 / 0.35)' });
      ghost.style.setProperty('--cv-ghost', 'light-dark(#fff, #222)');
      els.root.append(ghost);
    }
    ghost.style.left = `${e.clientX - 20}px`;
    ghost.style.top = `${e.clientY - 18}px`;
  };
  const stop = () => {
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', up);
    removeEventListener('pointercancel', cancel);
  };
  // Touch: a sideways swipe scrolls the step strip (small screens) and cancels the pointer.
  const cancel = () => {
    stop();
    ghost?.remove();
  };
  const up = e => {
    stop();
    if (!ghost) return appendToChain(type);
    ghost.remove();
    const r = els.viewport.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) return;
    if (e.target.closest?.('.cv-palette')) return;
    const p = toWorld(e.clientX, e.clientY);
    addNode(type, p.x - 30, p.y - 16);
  };
  addEventListener('pointermove', move);
  addEventListener('pointerup', up);
  addEventListener('pointercancel', cancel);
}

/**
 * Drag a card by its header. Pointer capture keeps the drag even when the
 * pointer outruns the card.
 */
export function dragNode(n, event) {
  if (event.button !== 0 || event.target.closest('button, input, select, textarea')) return;
  const handle = event.currentTarget;
  event.stopPropagation();
  event.preventDefault();
  closeMenu();
  try { handle.setPointerCapture(event.pointerId); } catch { /* not capturable */ }
  const ox = event.clientX / cv.view.z - n.x;
  const oy = event.clientY / cv.view.z - n.y;
  // The other cards stay put during the drag, so measure them once.
  const others = cv.flow.nodes.filter(m => m !== n).map(boxOf);
  const move = e => {
    if (e.pointerId !== event.pointerId) return;
    const wanted = { x: e.clientX / cv.view.z - ox, y: e.clientY / cv.view.z - oy, w: width(n), h: height(n) };
    // Cards cannot cover each other: stop at the edge of a card in the way.
    const spot = freeSpot(wanted, others);
    if (!spot) return;
    n.x = Math.round(spot.x);
    n.y = Math.round(spot.y);
  };
  const up = e => {
    if (e.pointerId !== event.pointerId) return;
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', up);
    removeEventListener('pointercancel', up);
  };
  addEventListener('pointermove', move);
  addEventListener('pointerup', up);
  addEventListener('pointercancel', up);
}

// Drag from an output port: drop on a node to connect, on empty space to pick.
export function startConnect(n, event) {
  event.stopPropagation();
  event.preventDefault();
  if (cv.running) return;
  cv.armed = n.id;
  const a = outPoint(n);
  let target = null;
  const move = e => {
    cv.temp = { a, b: toWorld(e.clientX, e.clientY) };
    const hit = document.elementFromPoint(e.clientX, e.clientY)?.closest?.('.cv-node');
    const id = hit?.dataset.id;
    const candidate = id && id !== n.id ? nodeById(id, cv.flow) : null;
    target = candidate && canFollow(n, candidate.type, candidate, cv.flow) ? candidate : null;
    cv.dropTarget = target?.id ?? null;
  };
  const up = e => {
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', up);
    cv.armed = null;
    cv.temp = null;
    cv.dropTarget = null;
    if (target) return connect(n.id, target.id);
    const over = document.elementFromPoint(e.clientX, e.clientY);
    if (over?.closest?.('.cv-node')) return toast('That step cannot follow this one.', true);
    const p = toWorld(e.clientX, e.clientY);
    if (Math.hypot(p.x - a.x, p.y - a.y) < 12) return;
    cv.pendingLine = { a, b: p };
    openNextMenu(n, e.clientX, e.clientY, p);
  };
  addEventListener('pointermove', move);
  addEventListener('pointerup', up);
}

// ── Run ────────────────────────────────────────────────────────────────
// Log panes: "all" holds the steps; each deployment gets its own tab and pane.
const paneOf = key => cv.run.panes.find(pane => pane.key === key);
function openPane(key, label) {
  if (!paneOf(key)) cv.run.panes.push({ key, label, href: '', state: '', status: '', lines: [] });
  return paneOf(key);
}
export function showPane(key) {
  const r = cv.run;
  r.active = key;
  const href = paneOf(key)?.href || r.defaultHref;
  if (href) r.openHref = href;
}
function setPaneState(key, state, status) {
  const pane = paneOf(key);
  if (!pane) return;
  pane.state = state;
  pane.status = status;
}
function logLine(text, cls = '', key = 'all') {
  const pane = paneOf(key) || paneOf('all');
  const box = els.root?.querySelector(`.cv-logs[data-key="${pane.key}"]`);
  const atEnd = !box || box.scrollTop + box.clientHeight >= box.scrollHeight - 30;
  pane.lines.push({ text, cls });
  if (box && atEnd) tick().then(() => { box.scrollTop = box.scrollHeight; });
}
function setStep(n, state, message) {
  cv.runState[n.id] = { state, message };
  delete cv.invalid[n.id];
  const row = cv.run.steps.find(s => s.id === n.id);
  if (row) {
    row.state = state;
    row.message = message || '';
  }
}

function validate(plan) {
  const problems = [];
  if (!plan.servers.length) problems.push('Connect a Server right after Start.');
  else if (!plan.lines.length) problems.push('Connect a source step (Git repository, Docker image, Dockerfile, Service or Database) to a Server.');
  for (const n of [plan.start, ...new Set(plan.lines.map(l => l.server)), ...plan.lines.flatMap(l => [l.source, ...l.settings])]) {
    const missing = TYPES[n.type].fields.filter(f => f.required && (!f.show || f.show(n.data)) && !String(n.data[f.key] ?? '').trim()).map(f => f.label);
    if (missing.length) {
      problems.push(`${TYPES[n.type].title}: fill in ${missing.join(', ')}.`);
      cv.invalid[n.id] = true;
    }
  }
  return problems;
}

export async function runFlow(action = 'deploy') {
  if (cv.running) return;
  closeMenu();
  const plan = planOf(cv.flow);
  const problems = validate(plan);
  if (problems.length) {
    toast(problems[0], true);
    return;
  }
  cv.running = action;
  cv.runState = {};
  cv.result = null;
  save('result', null);
  save('deployed', false);
  const servers = [...new Set(plan.lines.map(l => l.server))];
  const list = [plan.start, ...servers, ...plan.lines.flatMap(l => [l.source, ...l.settings]), plan.deploy];
  const names = uniqueLabels(plan.lines.map(l => l.source));
  const labelOf = n => names.get(n.id) || TYPES[n.type].title;
  const r = cv.run;
  r.open = true;
  r.title = action === 'save' ? 'Saving' : 'Deploying';
  r.sub = `${plan.lines.length} resource${plan.lines.length === 1 ? '' : 's'}`;
  r.panes = [];
  r.active = null;
  r.defaultHref = '';
  openPane('all', 'Steps');
  showPane('all');
  r.showOpen = false;
  r.steps = list.map(n => ({ id: n.id, label: labelOf(n), state: '', message: 'Waiting' }));
  redraw();
  // The page can be left (and the canvas mounted again) during a run.
  const owner = els.mount;
  const live = () => els.mount === owner;
  // Run one step; false when it failed.
  const step = async (n, work) => {
    setStep(n, 'running', 'Running…');
    try {
      const message = await work();
      if (!live()) return false;
      setStep(n, 'done', message);
      logLine(`✓ ${labelOf(n)}: ${message}`, 'dim');
      return true;
    } catch (error) {
      if (!live()) return false;
      setStep(n, 'failed', error.message);
      logLine(`✗ ${labelOf(n)}: ${error.message}`, 'err');
      return false;
    }
  };
  const base = {};
  let ok = await step(plan.start, () => TYPES.start.run(base, plan.start.data));
  for (const server of servers) {
    if (!ok) break;
    ok = await step(server, async () => 'Server picked.');
  }
  const shipped = []; // { line, ctx, label } per created resource
  for (const line of plan.lines) {
    if (!ok) break;
    const ctx = { ...base };
    await TYPES.server.run(ctx, line.server.data);
    const domainNode = line.settings.find(n => n.type === 'domain');
    if (domainNode) ctx.domains = domainList(domainNode.data.domains);
    for (const n of [line.source, ...line.settings]) {
      ok = await step(n, () => TYPES[n.type].run(ctx, n.data));
      if (!ok) break;
    }
    if (ctx.resource) shipped.push({ line, ctx, label: names.get(line.source.id) });
  }
  if (!live()) return;
  const href = () => shipped.length === 1 ? resourceHref(shipped[0].ctx) : `/project/${base.project}/environment/${base.environment}`;
  if (shipped.length) {
    r.showOpen = true;
    r.openHref = r.defaultHref = href();
  }
  if (ok) {
    ok = await step(plan.deploy, async () => action === 'save'
      ? `Saved ${shipped.length} resource${shipped.length === 1 ? '' : 's'}. Not deployed.`
      : deployAll(shipped, live));
  }
  if (!live()) return;
  if (shipped.length) r.defaultHref = href();
  showPane(r.active);
  if (ok) {
    const urls = [];
    for (const { ctx } of action === 'save' ? [] : shipped) {
      let found = String(ctx.domains || ctx.createdDomains || '').split(',').map(v => v.trim()).filter(Boolean);
      if (!found.length && ctx.resource.kind === 'application') {
        try {
          const app = await api('GET', `/applications/${ctx.resource.uuid}`);
          found = String(app?.fqdn || '').split(',').map(v => v.trim()).filter(Boolean);
        } catch { /* keep none */ }
      }
      urls.push(...found);
    }
    cv.result = { after: plan.deploy.id, urls, href: href() };
    save('result', cv.result);
    // The next visit starts with an empty canvas.
    if (action === 'deploy') save('deployed', true);
  }
  r.title = ok ? 'Done' : 'Stopped';
  r.sub = ok ? (action === 'save' ? 'Saved in Coolify. Deploy them from there or from here.' : 'Every step finished.') : 'A step failed. Fix it and try again.';
  cv.running = false;
}

// Deploy every resource at once and follow them.
async function deployAll(shipped, live) {
  const many = shipped.length > 1;
  // A tab per resource, in order; the first one opens.
  for (const { line, ctx, label } of shipped) openPane(line.source.id, label).href = resourceHref(ctx);
  if (shipped.length) showPane(shipped[0].line.source.id);
  const results = await Promise.allSettled(shipped.map(async ({ line, ctx, label }) => {
    const key = line.source.id;
    setPaneState(key, 'running', 'Starting');
    try {
      const res = await api('POST', '/deploy', { uuid: ctx.resource.uuid });
      if (!live()) return 'Left the canvas.';
      const item = res?.deployments?.[0];
      let message = item?.message || 'Started.';
      if (item?.deployment_uuid) {
        ctx.deployment = item.deployment_uuid;
        paneOf(key).href = resourceHref(ctx);
        if (cv.run.active === key) showPane(key);
        message = await followDeployment(label, item.deployment_uuid, key, live);
        if (!live()) return message;
      } else {
        logLine(message, '', key);
      }
      setPaneState(key, 'done', message);
      logLine(`✓ ${label}: ${message}`, 'dim');
      return `${label}: ${message}`;
    } catch (error) {
      if (live()) {
        setPaneState(key, 'failed', error.message.replace(`${label}: `, ''));
        logLine(error.message, 'err', key);
      }
      throw error;
    }
  }));
  const failed = results.filter(res => res.status === 'rejected').map(res => res.reason.message);
  if (failed.length) throw new Error(failed.join(' · '));
  return many ? `${results.length} resources deployed.` : results[0].value;
}

// Poll the deployment (Coolify's own log view also polls every 2 s).
// Logs go to the resource's own pane (key).
async function followDeployment(label, deploymentUuid, key, live) {
  logLine(`Deployment ${deploymentUuid}`, 'dim', key);
  let shown = 0;
  let warned = false;
  for (;;) {
    if (!live()) return 'Left the canvas.';
    let dep;
    try {
      dep = await api('GET', `/deployments/${deploymentUuid}`);
    } catch (error) {
      if (!live()) return 'Left the canvas.';
      logLine(`(could not read the deployment: ${error.message})`, 'err', key);
      await sleep(LOG_POLL_MS * 2);
      continue;
    }
    if (!live()) return 'Left the canvas.';
    let entries = [];
    if (typeof dep.logs === 'string') {
      try { entries = JSON.parse(dep.logs) || []; } catch { entries = []; }
    } else if (dep.logs === undefined && !warned) {
      warned = true;
      logLine('Logs are hidden: only team admins can see deployment logs.', 'err', key);
    }
    const visible = entries.filter(entry => !entry.hidden);
    for (const entry of visible.slice(shown)) {
      for (const line of String(entry.output ?? '').split('\n')) if (line.trim()) logLine(line, entry.type === 'stderr' ? 'err' : '', key);
    }
    shown = visible.length;
    setPaneState(key, 'running', String(dep.status || '').replace(/_/g, ' '));
    if (dep.status === 'finished') return 'Deployed.';
    if (dep.status === 'failed') throw new Error(`${label}: deployment failed. See the logs.`);
    if (dep.status === 'cancelled-by-user') throw new Error(`${label}: deployment cancelled.`);
    await sleep(LOG_POLL_MS);
  }
}
