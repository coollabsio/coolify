// Environment canvas (Beta): a read-mostly diagram of one environment.
// Server zones hold resource cards; admins can move cards and draw
// visual-only links. Positions and links are stored through /canvas/api.
//
// The top level stays DOM-free: `node --test` imports the pure helpers.

import { api, apart, BOX_GAP, freeSpot, h, icon as canvasIcon, MAX_ZOOM, MIN_ZOOM, mountOnPage, panZoom, svg } from './canvas-core.js';

export const CARD_WIDTH = 260;
export const COLUMN_GAP = 48;
export const ROW_GAP = 24;
export const ZONE_GAP = 72;
export const ZONE_PADDING = 24;
export const ZONE_HEADER = 40;
export const MAX_CHILDREN = 5;
export const MAX_VOLUMES = 3;
export const MAX_ROWS = 6; // auto placement wraps a type into another column after this many cards
export const NO_SERVER = 'No server';
export const DOUBLE_CLICK_MS = 400;
export const SOURCES_ZONE = 'sources';
export const CONNECTED_ZONE = 'connected';

const COLUMNS = { application: 0, service: 1, database: 2 };
/** Connect view: shared variable and tag cards are link sources; every other card is a resource. */
export const isSource = node => node?.type === 'variable' || node?.type === 'tag';
const columnOf = node => COLUMNS[node?.type] ?? 0;
const columnLeft = (originX, column) => originX + column * (CARD_WIDTH + COLUMN_GAP);
const isPoint = p => !!p && Number.isFinite(Number(p.x)) && Number.isFinite(Number(p.y));
const storedPositions = graph => (graph?.positions && !Array.isArray(graph.positions) ? graph.positions : {});

// ── Pure helpers (tested) ─────────────────────────────────────────────

/** Estimated card height in px; matches the rendered card closely enough for auto placement. */
export function estimateNodeHeight(node) {
  let height = 58; // padding + icon/name row
  if (node?.urls?.length) height += 20;
  if (node?.extra_zones?.length || node?.volumes?.length) height += 28;
  const children = node?.children?.length || 0;
  if (children) height += 12 + Math.min(children, MAX_CHILDREN) * 20 + (children > MAX_CHILDREN ? 20 : 0);
  return height;
}

/** Zones in display order (graph.zones order, then "No server"), each with its nodes. Empty zones are skipped. */
export function zoneGroups(graph) {
  const zones = graph?.zones || [];
  const known = new Set(zones.map(z => z.id));
  const groups = zones.map(zone => ({ id: zone.id, name: zone.name, zone, nodes: [] }));
  const none = { id: null, name: NO_SERVER, zone: null, nodes: [] };
  for (const node of graph?.nodes || []) {
    if (node.zone !== null && node.zone !== undefined && known.has(node.zone)) {
      groups.find(g => g.id === node.zone).nodes.push(node);
    } else {
      none.nodes.push(node);
    }
  }
  return [...groups, none].filter(g => g.nodes.length);
}

/**
 * Card positions (top-left, world px) for every node. Stored positions win;
 * other nodes are placed in their zone: applications left, services middle,
 * databases right. Zones without stored cards stack below everything else.
 */
export function layoutGraph(graph) {
  if (graph?.layout === 'server') return layoutServerView(graph);
  if (graph?.layout === 'connect') return layoutConnectView(graph);
  const stored = storedPositions(graph);
  const out = {};
  const placedBottom = [];
  const fresh = [];
  for (const group of zoneGroups(graph)) {
    const fixed = group.nodes.filter(n => isPoint(stored[n.id]));
    if (!fixed.length) {
      fresh.push(group);
      continue;
    }
    for (const n of fixed) out[n.id] = { x: Number(stored[n.id].x), y: Number(stored[n.id].y) };
    const originX = Math.min(...fixed.map(n => out[n.id].x));
    const fixedBottom = Math.max(...fixed.map(n => out[n.id].y + estimateNodeHeight(n)));
    // New cards go in a grid below the saved ones, so they never cover a saved card.
    const bottom = placeGrid(group.nodes.filter(n => !out[n.id]), originX, fixedBottom + ROW_GAP, out);
    placedBottom.push(Math.max(fixedBottom, bottom));
  }
  let y = placedBottom.length ? Math.max(...placedBottom) + ZONE_PADDING + ZONE_GAP : 0;
  for (const group of fresh) {
    y = placeGrid(group.nodes, 0, y + ZONE_HEADER + ZONE_PADDING, out) + ZONE_PADDING + ZONE_GAP;
  }
  return out;
}

/**
 * Places nodes in a grid from (originX, top): applications, then services, then
 * databases, each type in its own column(s) that wrap after MAX_ROWS cards.
 * Returns the bottom of the lowest card (top when there are no nodes).
 */
function placeGrid(nodes, originX, top, out) {
  let firstColumn = 0;
  let bottom = top;
  for (const column of [0, 1, 2]) {
    const ofType = nodes.filter(n => columnOf(n) === column);
    const nextY = [];
    ofType.forEach((n, i) => {
      const sub = Math.floor(i / MAX_ROWS);
      nextY[sub] ??= top;
      out[n.id] = { x: columnLeft(originX, firstColumn + sub), y: nextY[sub] };
      nextY[sub] += estimateNodeHeight(n) + ROW_GAP;
    });
    firstColumn += nextY.length;
    bottom = Math.max(bottom, ...nextY.map(v => v - ROW_GAP));
  }
  return bottom;
}

// ── Server view: project / environment groups, linked cards side by side ──

export const GROUP_PADDING = 16;
export const GROUP_HEADER = 32;
export const GROUP_GAP = 32;
export const GROUPS_PER_ROW = 3;
export const SINGLE_COLUMNS = 3;

const contextGroups = nodes => {
  const groups = new Map();
  for (const n of nodes) {
    const key = n.context || '';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(n);
  }
  // Server view: the unmanaged containers group comes first, so it is not lost below many projects.
  const unmanaged = ([, members]) => (members[0]?.type === 'container' ? 0 : 1);
  return [...groups.entries()].sort((a, b) => unmanaged(a) - unmanaged(b) || a[0].localeCompare(b[0]));
};
const byTypeAndName = (a, b) => columnOf(a) - columnOf(b) || String(a.name).localeCompare(String(b.name));

/**
 * Card positions inside one group, from (0, 0). Linked cards form chains from
 * left to right (source before target, one column per step); every chain gets
 * its own rows. Cards without links follow in a grid of SINGLE_COLUMNS.
 */
export function layoutBlock(nodes, links = []) {
  const ids = new Set(nodes.map(n => n.id));
  const inside = links.filter(l => ids.has(l.source) && ids.has(l.target) && l.source !== l.target);
  const parent = new Map(nodes.map(n => [n.id, n.id]));
  const find = id => (parent.get(id) === id ? id : find(parent.get(id)));
  for (const l of inside) parent.set(find(l.source), find(l.target));
  const order = [...nodes].sort(byTypeAndName);
  const components = new Map();
  for (const n of order) {
    const root = find(n.id);
    if (!components.has(root)) components.set(root, []);
    components.get(root).push(n);
  }

  const out = {};
  const step = CARD_WIDTH + COLUMN_GAP;
  let top = 0;
  let columns = 0;
  const singles = [];
  for (const members of components.values()) {
    if (members.length === 1) {
      singles.push(members[0]);
      continue;
    }
    // Depth: the longest path from a card without incoming links (cycles stop at the size of the chain).
    const own = new Set(members.map(n => n.id));
    const edges = inside.filter(l => own.has(l.source));
    const depth = new Map(members.filter(n => !edges.some(l => l.target === n.id)).map(n => [n.id, 0]));
    if (!depth.size) depth.set(members[0].id, 0);
    for (let changed = true, rounds = 0; changed && rounds < members.length; rounds++) {
      changed = false;
      for (const l of edges) {
        const next = (depth.get(l.source) ?? -1) + 1;
        if (next > 0 && next > (depth.get(l.target) ?? -1)) {
          depth.set(l.target, next);
          changed = true;
        }
      }
    }
    const nextY = [];
    for (const n of members) {
      const d = depth.get(n.id) ?? 0;
      nextY[d] ??= top;
      out[n.id] = { x: d * step, y: nextY[d] };
      nextY[d] += estimateNodeHeight(n) + ROW_GAP;
    }
    columns = Math.max(columns, nextY.length);
    top = Math.max(...nextY.filter(v => v !== undefined)) + ROW_GAP;
  }
  let rowHeight = 0;
  singles.forEach((n, i) => {
    const column = i % SINGLE_COLUMNS;
    if (column === 0 && i > 0) {
      top += rowHeight + ROW_GAP;
      rowHeight = 0;
    }
    out[n.id] = { x: column * step, y: top };
    rowHeight = Math.max(rowHeight, estimateNodeHeight(n));
  });
  if (singles.length) columns = Math.max(columns, Math.min(singles.length, SINGLE_COLUMNS));
  const bottom = singles.length ? top + rowHeight : Math.max(0, top - ROW_GAP);
  return { positions: out, width: columns * step - COLUMN_GAP, height: bottom };
}

/**
 * A project / environment group with cards saved on its environment canvas:
 * the same placement as that canvas (saved cards, then a grid below them),
 * moved so the top-left card starts at (0, 0). Groups without saved cards use layoutBlock.
 */
export function layoutStoredBlock(nodes, stored, links = []) {
  if (!nodes.some(n => isPoint(stored[n.id]))) return layoutBlock(nodes, links);
  const zone = nodes[0].zone;
  const placed = layoutGraph({ zones: [{ id: zone }], nodes: nodes.map(n => ({ ...n, zone })), positions: stored });
  const minX = Math.min(...nodes.map(n => placed[n.id].x));
  const minY = Math.min(...nodes.map(n => placed[n.id].y));
  const positions = Object.fromEntries(nodes.map(n => [n.id, { x: placed[n.id].x - minX, y: placed[n.id].y - minY }]));
  return {
    positions,
    origin: { x: minX, y: minY },
    width: Math.max(...nodes.map(n => positions[n.id].x + CARD_WIDTH)),
    height: Math.max(...nodes.map(n => positions[n.id].y + estimateNodeHeight(n))),
  };
}

/**
 * Top-left of the given cards where their environment canvas places them. A group saved for the first time
 * from the server view goes there, so it does not cover the cards of the environment on other servers.
 */
function environmentOrigin(graph, nodes) {
  const environment = nodes[0]?.environment?.uuid;
  if (!environment) return { x: 0, y: 0 };
  const placed = layoutGraph({
    zones: graph.zones,
    nodes: (graph.nodes || []).filter(n => n.environment?.uuid === environment),
    positions: storedPositions(graph),
  });
  return { x: Math.min(...nodes.map(n => placed[n.id].x)), y: Math.min(...nodes.map(n => placed[n.id].y)) };
}

/**
 * Server view: zones stack downwards; inside a zone, one block per project / environment, GROUPS_PER_ROW per row.
 * When `offsets` is given, it gets per card the shift from its environment canvas position to its server view
 * position, so a moved card can be saved on its environment canvas.
 */
export function layoutServerView(graph, offsets = null) {
  const stored = storedPositions(graph);
  const out = {};
  let y = 0;
  for (const zone of zoneGroups(graph)) {
    const innerTop = y + ZONE_HEADER + ZONE_PADDING;
    let x = 0;
    let rowTop = innerTop;
    let rowBottom = innerTop;
    contextGroups(zone.nodes).forEach(([, nodes], i) => {
      if (i > 0 && i % GROUPS_PER_ROW === 0) {
        x = 0;
        rowTop = rowBottom + GROUP_GAP;
      }
      const block = layoutStoredBlock(nodes, stored, graph.links || []);
      const origin = offsets ? (block.origin ?? environmentOrigin(graph, nodes)) : null;
      for (const [id, p] of Object.entries(block.positions)) {
        out[id] = { x: x + GROUP_PADDING + p.x, y: rowTop + GROUP_HEADER + GROUP_PADDING + p.y };
        if (offsets) offsets[id] = { x: out[id].x - origin.x - p.x, y: out[id].y - origin.y - p.y };
      }
      x += block.width + GROUP_PADDING * 2 + GROUP_GAP;
      rowBottom = Math.max(rowBottom, rowTop + GROUP_HEADER + GROUP_PADDING * 2 + block.height);
    });
    y = rowBottom + ZONE_PADDING + ZONE_GAP;
  }
  return out;
}

/**
 * Shared variables view: variable cards in one column on the left (by name). Resources that use a
 * variable sit next to them in one column, grouped by project / environment, so every link runs
 * straight from left to right. Other resources follow below in the server view grid.
 */
export function layoutConnectView(graph) {
  const out = {};
  const groups = zoneGroups(graph);
  const variables = groups.find(g => g.id === SOURCES_ZONE);
  const connected = groups.find(g => g.id === CONNECTED_ZONE);
  let y = ZONE_HEADER + ZONE_PADDING;
  for (const n of [...(variables?.nodes || [])].sort((a, b) => String(a.name).localeCompare(String(b.name)))) {
    out[n.id] = { x: 0, y };
    y += estimateNodeHeight(n) + ROW_GAP;
  }
  const offset = variables ? CARD_WIDTH + ZONE_PADDING * 2 + ZONE_GAP * 3 : 0;
  let top = 0;
  if (connected) {
    y = ZONE_HEADER + ZONE_PADDING;
    for (const [, nodes] of contextGroups(connected.nodes)) {
      y += GROUP_HEADER + GROUP_PADDING;
      for (const n of [...nodes].sort(byTypeAndName)) {
        out[n.id] = { x: offset + GROUP_PADDING, y };
        y += estimateNodeHeight(n) + ROW_GAP;
      }
      y += GROUP_PADDING - ROW_GAP + GROUP_GAP;
    }
    top = y - GROUP_GAP + ZONE_PADDING + ZONE_GAP;
  }
  const others = groups.filter(g => g.id !== SOURCES_ZONE && g.id !== CONNECTED_ZONE);
  const placed = layoutServerView({ zones: others.map(g => g.zone || { id: g.id }), nodes: others.flatMap(g => g.nodes) });
  for (const [id, p] of Object.entries(placed)) out[id] = { x: p.x + offset, y: p.y + top };
  return out;
}

/**
 * Shared variables view: moves the resources that use a shown variable into the "connected" zone,
 * so they show apart from the other resources. Runs after filterGraph and after every link change.
 */
export function groupByUse(graph) {
  if (graph?.layout !== 'connect') return graph;
  const used = new Set((graph.links || []).map(l => l.target));
  return {
    ...graph,
    nodes: (graph.nodes || []).map(n => (!isSource(n) && used.has(n.id) ? { ...n, zone: CONNECTED_ZONE } : n)),
  };
}

/** Frames of the project / environment groups of the given cards (server view). */
export function groupFrames(nodes, positions, sizes = {}) {
  const frames = [];
  for (const [name, members] of contextGroups(nodes)) {
    const rects = members.map(n => cardRect(n, positions, sizes)).filter(Boolean);
    if (!rects.length) continue;
    const minX = Math.min(...rects.map(r => r.x));
    const minY = Math.min(...rects.map(r => r.y));
    frames.push({
      name,
      link: members[0].context_link,
      x: minX - GROUP_PADDING,
      y: minY - GROUP_PADDING - GROUP_HEADER,
      width: Math.max(...rects.map(r => r.x + r.width)) - minX + GROUP_PADDING * 2,
      height: Math.max(...rects.map(r => r.y + r.height)) - minY + GROUP_PADDING * 2 + GROUP_HEADER,
    });
  }
  return frames;
}

/** Card rect from positions and measured sizes (falls back to the estimate). */
export function cardRect(node, positions, sizes = {}) {
  const p = positions?.[node.id];
  if (!p) return null;
  return {
    x: p.x,
    y: p.y,
    width: sizes[node.id]?.width ?? CARD_WIDTH,
    height: sizes[node.id]?.height ?? estimateNodeHeight(node),
  };
}

/** Frame per zone: bbox of its cards (or of its group frames) + padding, with room for the header on top. */
export function zoneBoxes(graph, positions, sizes = {}) {
  const boxes = [];
  for (const group of zoneGroups(graph)) {
    const rects = group.nodes.some(n => n.context)
      ? groupFrames(group.nodes, positions, sizes)
      : group.nodes.map(n => cardRect(n, positions, sizes)).filter(Boolean);
    if (!rects.length) continue;
    const minX = Math.min(...rects.map(r => r.x));
    const minY = Math.min(...rects.map(r => r.y));
    const maxX = Math.max(...rects.map(r => r.x + r.width));
    const maxY = Math.max(...rects.map(r => r.y + r.height));
    boxes.push({
      id: group.id,
      name: group.name,
      zone: group.zone,
      nodes: group.nodes.map(n => n.id),
      x: minX - ZONE_PADDING,
      y: minY - ZONE_PADDING - ZONE_HEADER,
      width: maxX - minX + ZONE_PADDING * 2,
      height: maxY - minY + ZONE_PADDING * 2 + ZONE_HEADER,
    });
  }
  return boxes;
}

const toBox = r => ({ x: r.x, y: r.y, w: r.width, h: r.height });
// A frame reaches `side` past its cards left, right and below, and `top` above them.
const grow = (b, side, top) => ({ x: b.x - side, y: b.y - top, w: b.w + side * 2, h: b.h + side + top });
const frameAround = (boxes, side, top) => {
  const x = Math.min(...boxes.map(b => b.x));
  const y = Math.min(...boxes.map(b => b.y));
  return grow({ x, y, w: Math.max(...boxes.map(b => b.x + b.w)) - x, h: Math.max(...boxes.map(b => b.y + b.h)) - y }, side, top);
};

/**
 * What a dragged card must keep clear of, for freeSpot(): `others` are the
 * other cards and the other zone frames (and, server view, the other project /
 * environment frames in its zone), grown by the room the card's own frames
 * take around it. `fits` checks the card's own frames, which also span its
 * other cards, against those frames.
 */
export function dragRules(graph, positions, sizes, id) {
  const node = graph.nodes.find(n => n.id === id);
  const own = zoneGroups(graph).find(z => z.nodes.some(n => n.id === id));
  const grouped = !!own?.nodes.some(n => n.context);
  const context = n => n.context || '';
  const rects = nodes => nodes.filter(n => n.id !== id).map(n => cardRect(n, positions, sizes)).filter(Boolean).map(toBox);
  const groupSide = grouped ? GROUP_PADDING : 0;
  const groupTop = grouped ? GROUP_PADDING + GROUP_HEADER : 0;
  const side = ZONE_PADDING + groupSide;
  const top = ZONE_PADDING + ZONE_HEADER + groupTop;
  const zones = zoneBoxes(graph, positions, sizes).filter(z => !z.nodes.includes(id)).map(toBox);
  const groups = grouped ? groupFrames(own.nodes.filter(n => context(n) !== context(node)), positions, sizes).map(toBox) : [];
  const zoneMates = rects(own?.nodes || []);
  const groupMates = grouped ? rects(own.nodes.filter(n => context(n) === context(node))) : [];
  // The other box must keep the frame's room on the side that faces the card.
  const room = (b, s, t) => ({ x: b.x - s, y: b.y - s, w: b.w + s * 2, h: b.h + s + t });
  return {
    others: [...rects(graph.nodes), ...zones.map(z => room(z, side, top)), ...groups.map(g => room(g, groupSide, groupTop))],
    fits: box => zones.every(z => apart(frameAround([...zoneMates, box], side, top), z, BOX_GAP))
      && (!grouped || groups.every(g => apart(frameAround([...groupMates, box], groupSide, groupTop), g, BOX_GAP))),
  };
}

/**
 * SVG path between two card rects. Target right of source: right edge →
 * left edge; target left of source: left edge → right edge; overlapping
 * columns (stacked): bottom → top (or top → bottom when the target is above).
 */
export function linkPath(a, b) {
  let side;
  let from;
  let to;
  let c1;
  let c2;
  if (b.x >= a.x + a.width) {
    side = 'right';
    from = { x: a.x + a.width, y: a.y + a.height / 2 };
    to = { x: b.x, y: b.y + b.height / 2 };
  } else if (b.x + b.width <= a.x) {
    side = 'left';
    from = { x: a.x, y: a.y + a.height / 2 };
    to = { x: b.x + b.width, y: b.y + b.height / 2 };
  } else if (b.y + b.height / 2 >= a.y + a.height / 2) {
    side = 'down';
    from = { x: a.x + a.width / 2, y: a.y + a.height };
    to = { x: b.x + b.width / 2, y: b.y };
  } else {
    side = 'up';
    from = { x: a.x + a.width / 2, y: a.y };
    to = { x: b.x + b.width / 2, y: b.y + b.height };
  }
  if (side === 'right' || side === 'left') {
    const dx = Math.max(40, Math.abs(to.x - from.x) / 2) * (side === 'right' ? 1 : -1);
    c1 = { x: from.x + dx, y: from.y };
    c2 = { x: to.x - dx, y: to.y };
  } else {
    const dy = Math.max(40, Math.abs(to.y - from.y) / 2) * (side === 'down' ? 1 : -1);
    c1 = { x: from.x, y: from.y + dy };
    c2 = { x: to.x, y: to.y - dy };
  }
  const mid = {
    x: (from.x + 3 * c1.x + 3 * c2.x + to.x) / 8,
    y: (from.y + 3 * c1.y + 3 * c2.y + to.y) / 8,
  };
  const r = v => Math.round(v * 10) / 10;
  const d = `M ${r(from.x)} ${r(from.y)} C ${r(c1.x)} ${r(c1.y)}, ${r(c2.x)} ${r(c2.y)}, ${r(to.x)} ${r(to.y)}`;
  return { d, side, from, to, mid };
}

const BUSY = ['starting', 'restarting', 'created', 'removing', 'in_progress', 'queued', 'deploying', 'building'];
const BAD = ['exited', 'stopped', 'dead', 'failed', 'error'];

/** Coolify status string → 'ok' | 'warn' | 'bad' | 'idle' | 'busy'. */
export function statusTone(status) {
  const value = String(status ?? '').trim().toLowerCase();
  if (!value) return 'idle';
  const [state, health = ''] = value.replace(/\)$/, '').split(/\s*[:(]\s*/);
  if (BUSY.includes(state)) return 'busy';
  if (BAD.includes(state)) return 'bad';
  if (state === 'degraded') return 'warn';
  if (state === 'running' || state === 'healthy') return health === 'unhealthy' ? 'warn' : 'ok';
  return 'idle';
}

/** "running:healthy" → "Running (healthy)". */
export function statusLabel(status) {
  const value = String(status ?? '').trim();
  if (!value) return 'Unknown';
  const [state, health] = value.replace(/\)$/, '').split(/\s*[:(]\s*/);
  const word = state.charAt(0).toUpperCase() + state.slice(1).replace(/_/g, ' ');
  return health ? `${word} (${health})` : word;
}

/**
 * Connect and server views: projects of the resources, each with its environments, sorted by name.
 * @returns {{uuid: string, name: string, environments: {uuid: string, name: string}[]}[]}
 */
export function filterOptions(graph) {
  const projects = new Map();
  for (const n of graph?.nodes || []) {
    if (!n.project || !n.environment) continue;
    if (!projects.has(n.project.uuid)) projects.set(n.project.uuid, { ...n.project, environments: new Map() });
    projects.get(n.project.uuid).environments.set(n.environment.uuid, n.environment);
  }
  const byName = (a, b) => String(a.name).localeCompare(String(b.name));
  return [...projects.values()]
    .map(p => ({ uuid: p.uuid, name: p.name, environments: [...p.environments.values()].sort(byName) }))
    .sort(byName);
}

/**
 * Connect and server views: only the resources of the chosen project / environment and their links.
 * Variables always stay, so they can be added to the shown resources.
 */
export function filterGraph(graph, filter = {}) {
  if (!graph || (!filter.project && !filter.environment)) return graph;
  const keep = n => isSource(n)
    || ((!filter.project || n.project?.uuid === filter.project) && (!filter.environment || n.environment?.uuid === filter.environment));
  const nodes = (graph.nodes || []).filter(keep);
  const ids = new Set(nodes.map(n => n.id));
  return { ...graph, nodes, links: (graph.links || []).filter(l => ids.has(l.source) && ids.has(l.target)) };
}

/**
 * Connect view: links go from a shared variable (not a deleted one) or a tag to a resource.
 * Server view: links are saved on an environment canvas, so both cards must be in the same environment.
 */
export function canConnect(graph, source, target) {
  if (graph?.layout === 'server') {
    const environment = graph.nodes?.find(n => n.id === source)?.environment?.uuid;
    return !!environment && source !== target && graph.nodes.find(n => n.id === target)?.environment?.uuid === environment;
  }
  if (graph?.layout !== 'connect') return !!source && !!target && source !== target;
  const from = graph.nodes?.find(n => n.id === source);
  const to = graph.nodes?.find(n => n.id === target);
  return isSource(from) && !from.missing && !!to && !isSource(to);
}

/** A link may be drawn between two different nodes that are not linked yet (either direction). */
export function canLink(links, source, target) {
  if (!source || !target || source === target) return false;
  return !(links || []).some(l => (l.source === source && l.target === target) || (l.source === target && l.target === source));
}

// ── DOM helpers (only called after mount) ─────────────────────────────

// Application and service events go to the team channel; database events go to the user channel.
const STATUS_CHANNELS = [['team', ['ApplicationStatusChanged', 'ServiceStatusChanged']], ['user', ['DatabaseStatusChanged']]];
const TYPE_LABELS = { application: 'Application', service: 'Service', database: 'Database', container: 'Unmanaged container', variable: 'Shared variable', tag: 'Tag' };
// Wording of the connect view, per data-mode (shared variables or tags).
const CONNECT_TEXT = {
  variables: {
    source: 'Variable',
    added: (source, target) => `Added ${source} to ${target}. Redeploy it to use the variable.`,
    removed: (source, target) => `Removed ${source} from ${target}. Redeploy it to apply.`,
    already: (source, target) => `${target} already uses ${source}.`,
    usedBy: 'Used by',
    noTargets: 'No resource uses this variable.',
    allTargets: 'Every resource already uses this variable.',
    port: 'Drag to a resource to add this variable to it',
    noSources: 'This resource uses no shared variable of this scope.',
    sources: 'Shared variables',
    addSources: 'Add shared variables',
    searchSources: 'Search variables',
    noMoreSources: 'No other shared variable to add.',
    note: node => `Adding creates a ${node.name} variable with the value ${node.reference} on the resource. Redeploy or restart the resource to apply changes.`,
    viewOnly: 'You can look around. Only team admins can add shared variables to resources.',
    empty: 'No shared variables or resources yet',
  },
  tags: {
    source: 'Tag',
    added: (source, target) => `Tagged ${target} with ${source}.`,
    removed: (source, target) => `Removed the ${source} tag from ${target}.`,
    already: (source, target) => `${target} already has the ${source} tag.`,
    usedBy: 'Tagged',
    noTargets: 'No resource has this tag.',
    allTargets: 'Every resource already has this tag.',
    port: 'Drag to a resource to tag it',
    noSources: 'This resource has no tag.',
    sources: 'Tags',
    addSources: 'Add tags',
    searchSources: 'Search tags',
    noMoreSources: 'No other tag to add. Create tags on the Tags page.',
    note: () => 'The deploy webhook of a tag deploys every tagged application and service.',
    viewOnly: 'You can look around. Only team admins can tag resources.',
    empty: 'No tags or resources yet',
  },
};
const TONE_DOT = {
  ok: 'bg-emerald-500',
  warn: 'bg-warning',
  bad: 'bg-red-500',
  idle: 'bg-neutral-400 dark:bg-neutral-500',
  busy: 'bg-warning',
};
// Reicon names (cloned from the page template when present) and local fallbacks.
const REICONS = {
  application: 'browser-code',
  service: 'layers',
  database: 'database',
  server: 'servers',
  refresh: 'refresh',
  plus: 'plus',
  x: 'x',
  external: 'external-link',
};
const LOCAL_ICONS = {
  application: ['M3 6.5A2.5 2.5 0 0 1 5.5 4h13A2.5 2.5 0 0 1 21 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z', 'M3 9h18', 'M9.5 13l-2 2 2 2', 'M14.5 13l2 2-2 2'],
  service: ['M12 4 3 8.5l9 4.5 9-4.5z', 'M3 12.5l9 4.5 9-4.5', 'M3 16.5l9 4.5 9-4.5'],
  database: ['M5 6c0-1.66 3.13-3 7-3s7 1.34 7 3-3.13 3-7 3-7-1.34-7-3z', 'M5 6v12c0 1.66 3.13 3 7 3s7-1.34 7-3V6', 'M5 12c0 1.66 3.13 3 7 3s7-1.34 7-3'],
  container: ['M12 3 4 7.5v9L12 21l8-4.5v-9z', 'M4 7.5 12 12l8-4.5', 'M12 12v9'],
  server: ['M4 4h16v6H4z', 'M4 14h16v6H4z', 'M7.5 7h.01', 'M7.5 17h.01'],
  refresh: ['M20 11a8 8 0 0 0-14.9-3.9', 'M4 4v4h4', 'M4 13a8 8 0 0 0 14.9 3.9', 'M20 20v-4h-4'],
  plus: ['M12 5v14', 'M5 12h14'],
  minus: ['M5 12h14'],
  fit: ['M4 9V4h5', 'M20 9V4h-5', 'M4 15v5h5', 'M20 15v5h-5'],
  x: ['M6 6l12 12', 'M18 6 6 18'],
  external: ['M14 4h6v6', 'M20 4l-9 9', 'M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4'],
  link: ['M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1', 'M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1'],
  variable: ['M8 4H7a2 2 0 0 0-2 2v4a2 2 0 0 1-2 2 2 2 0 0 1 2 2v4a2 2 0 0 0 2 2h1', 'M16 4h1a2 2 0 0 1 2 2v4a2 2 0 0 0 2 2 2 2 0 0 0-2 2v4a2 2 0 0 1-2 2h-1'],
  search: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z', 'M20 20l-4-4'],
  tag: ['M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z', 'M7.5 7.5h.01'],
  info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v5', 'M12 8h.01'],
};
const STYLE = `
@keyframes ec-dash { to { stroke-dashoffset: -12; } }
[data-ec-root] .ec-temp { stroke-dasharray: 6 6; animation: ec-dash 0.6s linear infinite; }
[data-ec-root] .ec-missing { stroke-dasharray: 5 5; }
`;

function icon(name, cls = 'size-3.5') {
  return canvasIcon('[data-environment-canvas-icons]', REICONS[name] || name, LOCAL_ICONS[name], `${cls} shrink-0`);
}

/** Marks an element whose page opens on double-click (see the pointerdown listener in mountEnvironmentCanvas). */
function openable(el, href) {
  if (href) {
    el.dataset.ecOpen = href;
    el.title = 'Double-click to open';
  }
  return el;
}

/** Sets or removes the page of a title link (a single click opens it). */
function setHref(el, href) {
  if (href) el.href = href;
  else el.removeAttribute('href');
}

/** A title that opens its page on a single click; plain text when there is no page. */
function titleLink(cls, text, href) {
  const el = h('a', `${cls} [&[href]]:cursor-pointer [&[href]]:underline-offset-2 [&[href]]:hover:underline`, text);
  el.draggable = false;
  setHref(el, href);
  return el;
}

function capture(el, pointerId) {
  try {
    el.setPointerCapture(pointerId);
  } catch {
    // Synthetic or already released pointer; window-level fallbacks are not needed for real pointers.
  }
}

function statusDot(status, size = 'size-2') {
  const tone = statusTone(status);
  const wrap = h('span', `relative flex ${size} shrink-0`);
  wrap.title = statusLabel(status);
  if (tone === 'busy') wrap.append(h('span', `absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping ${TONE_DOT.busy}`));
  wrap.append(h('span', `relative inline-flex size-full rounded-full ${TONE_DOT[tone]}`));
  return wrap;
}

/** A saved { x, y, z } canvas view, or null when it is missing or not valid. */
export function readView(key, storage = globalThis.localStorage) {
  try {
    const v = JSON.parse(storage?.getItem(key) ?? 'null');
    const ok = v && [v.x, v.y, v.z].every(Number.isFinite) && v.z >= MIN_ZOOM && v.z <= MAX_ZOOM;
    return ok ? { x: v.x, y: v.y, z: v.z } : null;
  } catch {
    return null;
  }
}

// ── Canvas instance ───────────────────────────────────────────────────

// Small screens: a notice that the canvas is not optimized for mobile. Shown below 768px (md);
// closed once, it stays closed on every canvas (same key as the deploy canvas notice).
const MOBILE_NOTICE_KEY = 'coolify-canvas:mobile-notice-dismissed';
function mobileNotice() {
  try {
    if (JSON.parse(localStorage.getItem(MOBILE_NOTICE_KEY))) return null;
  } catch {
    // Storage blocked: show the notice.
  }
  const notice = h('div', 'flex shrink-0 items-start gap-2 rounded-xl bg-red-600 py-2.5 pr-2 pl-3 text-[12.5px] leading-[1.125rem] text-white md:hidden');
  notice.dataset.ecMobileNotice = '';
  notice.setAttribute('role', 'note');
  const close = h('button', 'grid size-6.5 shrink-0 -mt-0.75 place-items-center rounded-md hover:bg-white/20');
  close.type = 'button';
  close.title = 'Close';
  close.setAttribute('aria-label', 'Close this notice');
  close.append(icon('x', 'size-4'));
  close.addEventListener('click', () => {
    try {
      localStorage.setItem(MOBILE_NOTICE_KEY, 'true');
    } catch {
      // Storage blocked: closed until the next page load.
    }
    notice.remove();
  });
  notice.append(icon('info', 'size-4 mt-px'), h('p', 'min-w-0 flex-1', 'The canvas is not optimized for mobile yet. It works best on a larger screen.'), close);
  return notice;
}

export function mountEnvironmentCanvas(container) {
  const ds = container.dataset;
  // The server canvas (data-endpoint) reuses this view. It saves moves and links on the environment canvas of each card.
  // The shared variables canvas (data-mode="variables") reuses it too: its links add a variable to a resource.
  const connectView = ds.mode === 'variables' || ds.mode === 'tags';
  const text = CONNECT_TEXT[ds.mode] || CONNECT_TEXT.variables;
  const params = new URLSearchParams(window.location.search);
  const serverView = !!ds.endpoint && !connectView;
  const filterView = connectView || serverView;
  const base = ds.endpoint || `/projects/${encodeURIComponent(ds.project || '')}/environments/${encodeURIComponent(ds.environment || '')}/canvas`;
  const s = {
    graph: null,
    positions: {},
    sizes: {},
    view: { x: 40, y: 40, z: 1 },
    selected: null, // { kind: 'node' | 'link', id }
    full: null, // the loaded graph; s.graph is it after the project / environment filter
    filter: filterView ? { project: params.get('project') || '', environment: params.get('environment') || '' } : {},
    hover: null,
    interactions: new Set(), // active drags; refetches wait until they end
    deferred: null,
    unsaved: {},
    pending: [], // links being saved: { source, target }, drawn as moving dashes until the request ends
    offsets: {}, // server view: card shift from its environment canvas position (see layoutServerView)
    loading: false,
    reloadQueued: false,
    fitted: false,
    destroyed: false,
    containers: null, // server canvas: unmanaged container cards, loaded after the canvas renders
  };
  const timers = { save: 0, refresh: 0, toast: 0, view: 0 };
  // Zoom and pan are kept per team and page, so a reload opens the canvas where it was.
  const viewKey = `coolify-canvas-view:${ds.team || ''}:${window.location.pathname}`;
  let restoredView = readView(viewKey);
  if (restoredView) s.view = restoredView;
  const cleanups = [];
  const ui = { cards: new Map(), zones: new Map() };
  const canEdit = () => !!s.graph?.can_edit;
  const canMove = () => canEdit() && s.graph?.can_move !== false;
  // Server view: unmanaged containers have no environment canvas, and zone frames are laid out, not saved.
  const canMoveNode = node => canMove() && (!serverView || !!node?.environment);
  const canMoveZones = () => canMove() && !serverView;
  // Environment canvas endpoints of a card (the server view has no endpoints of its own for changes).
  const environmentBase = node => (serverView
    ? `/projects/${encodeURIComponent(node?.project?.uuid || '')}/environments/${encodeURIComponent(node?.environment?.uuid || '')}/canvas`
    : base);
  const nodeById = id => s.graph?.nodes.find(n => n.id === id);
  const linkById = id => s.graph?.links.find(l => l.id === id);
  const rectOf = id => {
    const node = nodeById(id);
    return node ? cardRect(node, s.positions, s.sizes) : null;
  };
  const listen = (target, type, handler, options) => {
    target.addEventListener(type, handler, options);
    cleanups.push(() => target.removeEventListener(type, handler, options));
  };

  // Shell
  // Same look as the deploy canvas: a rounded top bar box on the backdrop.
  const root = h('div', 'absolute inset-0 z-0 flex flex-col gap-2 overflow-hidden bg-neutral-50 p-2 text-[13px] text-neutral-700 dark:bg-app dark:text-fg-dim');
  root.dataset.ecRoot = '';
  const style = h('style');
  style.textContent = STYLE;
  root.append(style);

  // The toolbar docks into the app top bar on desktop,
  // and sits in a rounded box above the canvas on small screens.
  const bar = h('div', '');
  bar.dataset.ecToolbar = '';
  const titles = h('div', 'flex min-w-0 items-center gap-2');
  const beta = h('span', 'inline-flex items-center');
  beta.append(h('span', 'px-2 py-0.5 text-xs font-medium leading-normal rounded-full bg-coollabs/10 text-coollabs border border-coollabs/30 dark:bg-warning/15 dark:text-warning dark:border-warning/30', 'Beta'));
  ui.viewOnly = h('span', 'hidden rounded-full border border-neutral-200 bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-fg-dim', 'View only');
  ui.viewOnly.title = connectView
    ? text.viewOnly
    : ds.viewOnly ? ds.viewOnly
    : serverView
      ? 'The server view is read-only. Move cards and edit links on an environment canvas.'
      : 'You can look around. Only team admins can move cards and edit links.';
  if (ds.title) titles.append(h('b', 'shrink-0 text-[14px] font-semibold text-black dark:text-fg', ds.title));
  titles.append(beta, ui.viewOnly);
  // Small screens: the actions wrap onto more rows instead of covering the title.
  const actions = h('div', 'ml-auto flex shrink-0 items-center gap-2 max-lg:min-w-0 max-lg:shrink max-lg:flex-wrap');
  ui.refresh = h('button', 'button');
  ui.refresh.type = 'button';
  ui.refresh.title = 'Load the latest status';
  ui.refresh.append(icon('refresh'), h('span', 'hidden sm:inline', 'Refresh'));
  const zoomGroup = h('div', 'flex items-center');
  const zoomButton = (name, title) => {
    const b = h('button', 'button px-2!');
    b.type = 'button';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.append(icon(name));
    return b;
  };
  const zoomOut = zoomButton('minus', 'Zoom out');
  zoomOut.classList.add('rounded-r-none!');
  ui.zoomLabel = h('button', 'button rounded-none! border-x-0! w-14 tabular-nums', '100%');
  ui.zoomLabel.type = 'button';
  ui.zoomLabel.title = 'Reset zoom to 100%';
  const zoomIn = zoomButton('plus', 'Zoom in');
  zoomIn.classList.add('rounded-l-none!');
  zoomGroup.append(zoomOut, ui.zoomLabel, zoomIn);
  const fitButton = zoomButton('fit', 'Fit to screen');
  fitButton.append('Fit');
  // Shared variables view: show the resources of one project and / or environment only.
  const filterSelect = label => {
    const el = h('select', 'select hidden h-8! w-44 py-0! text-[12px]! max-lg:w-auto max-lg:min-w-0 max-lg:flex-1 max-lg:basis-32');
    el.setAttribute('aria-label', label);
    el.title = label;
    return el;
  };
  ui.projectFilter = filterSelect('Filter by project');
  ui.environmentFilter = filterSelect('Filter by environment');
  actions.append(ui.projectFilter, ui.environmentFilter, ui.refresh, zoomGroup, fitButton);
  // Table / grid / canvas switcher from the page (Blade), when the page has one.
  const views = container.querySelector('[data-ec-views]');
  if (views) {
    views.classList.add('max-lg:flex-wrap');
    actions.append(views);
  }
  bar.append(titles, actions);

  const body = h('div', 'relative min-h-0 flex-1');
  ui.viewport = h('div', 'absolute inset-0 cursor-grab touch-none overflow-hidden select-none bg-[radial-gradient(circle,rgb(0_0_0/0.13)_1px,transparent_1px)] active:cursor-grabbing dark:bg-[radial-gradient(circle,rgb(255_255_255/0.09)_1px,transparent_1px)]');
  ui.viewport.dataset.ecViewport = '';
  ui.world = h('div', 'absolute top-0 left-0 origin-top-left');
  ui.zoneLayer = h('div', 'absolute top-0 left-0');
  ui.links = svg('svg', { class: 'pointer-events-none absolute top-0 left-0 overflow-visible', width: '1', height: '1' });
  ui.labelLayer = h('div', 'absolute top-0 left-0');
  ui.cardLayer = h('div', 'absolute top-0 left-0');
  // Link end dots sit above the cards, on their edges (like the deploy canvas ports).
  ui.dotLayer = h('div', 'pointer-events-none absolute top-0 left-0');
  // Server view: project / environment frames inside the server zones.
  ui.groupLayer = h('div', 'pointer-events-none absolute top-0 left-0');
  ui.world.append(ui.zoneLayer, ui.groupLayer, ui.links, ui.labelLayer, ui.cardLayer, ui.dotLayer);
  ui.viewport.append(ui.world);
  ui.overlay = h('div', 'pointer-events-none absolute inset-0 hidden items-center justify-center p-6');
  ui.panel = h('aside', 'absolute top-3 right-3 bottom-3 z-20 hidden w-80 max-w-[calc(100%-1.5rem)] flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-white/[0.08] dark:bg-panel');
  ui.panel.dataset.ecPanel = '';
  ui.toast = h('div', 'pointer-events-none absolute bottom-4 left-1/2 z-30 flex -translate-x-1/2 flex-col items-center gap-2');
  body.append(ui.viewport, ui.overlay, ui.panel, ui.toast);
  root.append(bar, ...[mobileNotice()].filter(Boolean), body);
  container.append(root);
  const desktop = window.matchMedia('(min-width: 1024px)');
  // Floating nav on desktop: a separate panel in the top bar row, centered over the canvas,
  // so the top bar keeps only the brand and breadcrumb.
  const topbar = document.querySelector('.app-topbar');
  const floatingBar = () => desktop.matches && !!topbar && document.documentElement.dataset.navStyle === 'floating';
  // Floating nav: the canvas fills the screen behind the top bar and the sidebar (see app.css),
  // so fit and the side panel use only the part that the floating panels do not cover.
  const coveredInsets = () => {
    if (!floatingBar()) return { top: 0, left: 0 };
    const gap = 8;
    const v = ui.viewport.getBoundingClientRect();
    // The main sidebar, and a page's fixed settings rail (server canvas) when it is on the left.
    const rail = document.querySelector('.application-settings-navigation');
    const panels = [document.querySelector('.app-sidebar > div > nav'), rail && getComputedStyle(rail).position === 'fixed' ? rail : null]
      .map((el) => el?.getBoundingClientRect())
      .filter((r) => r?.width);
    // The toolbar is in the top bar row, or in a row below it when the top bar has no room (see alignFloatingBar).
    const barBottom = bar.isConnected ? bar.getBoundingClientRect().bottom : 0;
    return {
      top: Math.max(0, Math.max(topbar.getBoundingClientRect().bottom, barBottom) + gap - v.top),
      left: Math.max(0, ...panels.map((r) => r.right + gap - v.left)),
    };
  };
  const alignFloatingBar = () => {
    if (!floatingBar()) {
      ui.panel.style.top = '';
      return;
    }
    const gap = 8;
    const area = body.getBoundingClientRect();
    const head = topbar.getBoundingClientRect();
    const insetLeft = coveredInsets().left;
    // Measure the full toolbar width, then use the top bar row only when it fits next to the top bar
    // (a page can dock wide actions in the top bar, e.g. the server canvas).
    Object.assign(bar.style, { left: '0px', maxWidth: '' });
    const width = bar.offsetWidth;
    const besideHead = head.right + gap + width <= window.innerWidth - gap;
    const left = Math.max(area.left + insetLeft + (area.width - insetLeft - width) / 2, besideHead ? head.right + gap : area.left + insetLeft);
    const top = besideHead ? head.top : head.bottom + gap;
    Object.assign(bar.style, { top: `${top}px`, left: `${left}px`, minHeight: `${head.height}px`, maxWidth: `${window.innerWidth - left - gap}px` });
    ui.panel.style.top = `${coveredInsets().top}px`;
  };
  const placeBar = () => {
    // data-toolbar-inline: the page docks its own actions in the top bar, so the toolbar stays in the canvas.
    const slot = desktop.matches && !floatingBar() && !('toolbarInline' in ds) && document.getElementById('page-toolbar-slot');
    bar.removeAttribute('style');
    if (floatingBar()) {
      bar.className = 'fixed z-40 flex flex-wrap items-center justify-center gap-3 rounded-xl border border-neutral-200 bg-white px-2 py-1.5 dark:border-white/[0.08] dark:bg-panel';
      body.append(bar);
      alignFloatingBar();
    } else if (slot) {
      bar.className = 'flex items-center gap-3 pl-3';
      slot.append(bar);
    } else {
      bar.className = 'flex shrink-0 flex-wrap items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 dark:border-white/[0.08] dark:bg-panel';
      root.insertBefore(bar, root.querySelector('[data-ec-mobile-notice]') || body);
    }
  };
  placeBar();
  listen(desktop, 'change', placeBar);
  listen(window, 'resize', alignFloatingBar);
  const navStyleObserver = new MutationObserver(placeBar);
  navStyleObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-nav-style'] });
  // Sidebar collapse, breadcrumb changes and toolbar content change the sizes.
  const barResizeObserver = new ResizeObserver(alignFloatingBar);
  for (const el of [body, bar, topbar].filter(Boolean)) barResizeObserver.observe(el);
  cleanups.push(() => {
    navStyleObserver.disconnect();
    barResizeObserver.disconnect();
  });

  // ── Toast ──
  function toast(text, bad = false) {
    ui.toast.replaceChildren();
    const t = h('div', bad
      ? 'pointer-events-auto max-w-md rounded-lg border border-red-200 bg-white px-3 py-2 text-[12px] text-red-700 shadow-lg dark:border-red-500/30 dark:bg-panel dark:text-red-300'
      : 'pointer-events-auto max-w-md rounded-lg border border-neutral-200 bg-white px-3 py-2 text-[12px] text-neutral-700 shadow-lg dark:border-white/[0.08] dark:bg-panel dark:text-fg', text);
    t.setAttribute('role', bad ? 'alert' : 'status');
    ui.toast.append(t);
    clearTimeout(timers.toast);
    timers.toast = setTimeout(() => t.remove(), bad ? 5000 : 2600);
  }

  // ── View ──
  const { apply: applyView, toWorld, zoomTo } = panZoom({
    root,
    viewport: ui.viewport,
    world: ui.world,
    zoomLabel: ui.zoomLabel,
    getView: () => s.view,
    onChange: () => {
      clearTimeout(timers.view);
      timers.view = setTimeout(saveView, 300);
    },
    canPan: e => !e.target.closest('[data-ec-card],[data-ec-zone-header],[data-ec-label],[data-ec-link],a,button,input'),
    onPanEnd: moved => {
      if (!moved) closePanel();
    },
  });
  function saveView() {
    clearTimeout(timers.view);
    try {
      localStorage.setItem(viewKey, JSON.stringify(s.view));
    } catch {
      // Storage full or blocked: the canvas fits to the screen next time.
    }
  }
  function fit() {
    const boxes = s.graph ? zoneBoxes(s.graph, s.positions, s.sizes) : [];
    const r = ui.viewport.getBoundingClientRect();
    if (!boxes.length || !r.width) {
      const inset = coveredInsets();
      s.view = { x: inset.left + 40, y: inset.top + 40, z: 1 };
      applyView();
      return;
    }
    const minX = Math.min(...boxes.map(b => b.x));
    const minY = Math.min(...boxes.map(b => b.y));
    const maxX = Math.max(...boxes.map(b => b.x + b.width));
    const maxY = Math.max(...boxes.map(b => b.y + b.height));
    const pad = 48;
    const inset = coveredInsets();
    const width = r.width - inset.left;
    const height = r.height - inset.top;
    const z = Math.min(1.1, Math.max(MIN_ZOOM, Math.min((width - pad * 2) / (maxX - minX), (height - pad * 2) / (maxY - minY))));
    s.view = { z, x: inset.left + (width - (maxX - minX) * z) / 2 - minX * z, y: inset.top + (height - (maxY - minY) * z) / 2 - minY * z };
    applyView();
  }

  // ── Rendering ──
  function renderAll() {
    renderHeader();
    renderCards();
    renderZones();
    renderLinks();
    renderOverlay();
  }

  function renderFilters() {
    const projects = filterView ? filterOptions(s.full) : [];
    const fill = (el, all, options, value) => {
      el.replaceChildren(new Option(all, ''), ...options.map(o => new Option(o.name, o.uuid)));
      el.value = options.some(o => o.uuid === value) ? value : '';
    };
    fill(ui.projectFilter, 'All projects', projects, s.filter.project);
    const environments = projects
      .filter(p => !s.filter.project || p.uuid === s.filter.project)
      .flatMap(p => p.environments.map(e => ({ uuid: e.uuid, name: s.filter.project ? e.name : `${p.name} / ${e.name}` })));
    fill(ui.environmentFilter, 'All environments', environments, s.filter.environment);
    ui.projectFilter.classList.toggle('hidden', projects.length < 2);
    ui.environmentFilter.classList.toggle('hidden', environments.length < 2);
  }

  function setFilter(filter) {
    s.filter = filter;
    const url = new URL(window.location.href);
    for (const key of ['project', 'environment']) {
      if (filter[key]) url.searchParams.set(key, filter[key]);
      else url.searchParams.delete(key);
    }
    window.history.replaceState(window.history.state, '', url);
    s.fitted = false;
    applyGraph(s.full);
  }

  function renderHeader() {
    const g = s.graph;
    renderFilters();
    ui.viewOnly.classList.toggle('hidden', !g || canEdit());
  }

  function placeCard(id) {
    const el = ui.cards.get(id);
    const p = s.positions[id];
    if (!el || !p) return;
    el.style.left = `${p.x}px`;
    el.style.top = `${p.y}px`;
  }

  function renderCards() {
    ui.cardLayer.replaceChildren();
    ui.cards.clear();
    for (const node of s.graph?.nodes || []) {
      const card = buildCard(node);
      ui.cards.set(node.id, card);
      ui.cardLayer.append(card);
      placeCard(node.id);
    }
    measure();
  }

  function measure() {
    s.sizes = {};
    for (const [id, el] of ui.cards) {
      if (el.offsetWidth) s.sizes[id] = { width: el.offsetWidth, height: el.offsetHeight };
    }
  }

  function zoneNames(node) {
    const names = new Map((s.graph?.zones || []).map(z => [z.id, z.name]));
    return [node.zone, ...(node.extra_zones || [])].filter(Boolean).map(id => names.get(id) || id);
  }

  function buildCard(node) {
    const selected = s.selected?.kind === 'node' && s.selected.id === node.id;
    const card = h('div', `group absolute flex w-[260px] flex-col rounded-xl border bg-white p-3 shadow-sm transition-shadow hover:shadow-md dark:bg-raised ${selected
      ? 'border-coollabs ring-2 ring-coollabs/25 dark:border-warning dark:ring-warning/25'
      : node.missing
        ? 'border-dashed border-red-300 dark:border-red-500/40'
        : 'border-neutral-200 hover:border-neutral-300 dark:border-white/[0.08] dark:hover:border-white/[0.16]'} ${canMoveNode(node) ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'}`);
    card.dataset.ecCard = '';
    card.dataset.id = node.id;
    card.setAttribute('role', 'button');
    card.setAttribute('tabindex', '0');
    card.setAttribute('aria-label', isSource(node)
      ? `${node.name} (${node.missing ? 'deleted ' : ''}${TYPE_LABELS[node.type].toLowerCase()})`
      : `${node.name} (${TYPE_LABELS[node.type] || node.type}), ${statusLabel(node.status)}`);
    openable(card, node.link);

    const head = h('div', 'flex items-start gap-2.5');
    const tile = h('div', `flex size-8 shrink-0 items-center justify-center rounded-lg border ${node.missing
      ? 'border-red-200 bg-red-50 text-red-500 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400'
      : 'border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim'}`);
    tile.append(icon(node.type, 'size-4'));
    const text = h('div', 'min-w-0 flex-1');
    text.append(
      h('div', `truncate text-[13px] leading-4 font-semibold text-black dark:text-fg ${node.type === 'variable' ? 'font-mono' : ''} ${node.link ? 'underline-offset-2 group-hover:underline' : ''}`, node.name),
      h('div', 'mt-0.5 truncate text-[11px] leading-4 text-neutral-500 dark:text-fg-faint', node.subtitle || TYPE_LABELS[node.type] || ''),
    );
    head.append(tile, text);
    if (isSource(node)) {
      const count = (s.graph?.links || []).filter(l => l.source === node.id).length;
      const badge = h('span', `mt-0.5 shrink-0 rounded-full border px-1.5 text-[10px] leading-4 font-medium ${node.missing
        ? 'border-red-200 bg-red-50 text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400'
        : 'border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim'}`, node.missing ? 'Deleted' : String(count));
      badge.title = node.missing ? 'This shared variable was deleted, but resources still reference it' : `Used by ${count} ${count === 1 ? 'resource' : 'resources'}`;
      head.append(badge);
    } else {
      const dot = h('div', 'pt-1');
      dot.append(statusDot(node.status));
      head.append(dot);
    }
    card.append(head);

    if (node.urls?.length) {
      const url = h('a', 'relative mt-1.5 block max-w-full self-start truncate text-[11px] leading-5 text-neutral-500 hover:text-black hover:underline dark:text-fg-dim dark:hover:text-fg', node.urls[0].replace(/^https?:\/\//, ''));
      url.href = node.urls[0];
      url.target = '_blank';
      url.rel = 'noopener noreferrer';
      url.title = node.urls[0];
      url.addEventListener('pointerdown', e => e.stopPropagation());
      url.addEventListener('click', e => e.stopPropagation());
      card.append(url);
    }

    const extra = node.extra_zones?.length || 0;
    const volumes = node.volumes || [];
    if (extra || volumes.length) {
      const chips = h('div', 'mt-2 flex min-w-0 flex-wrap gap-1 overflow-hidden h-5');
      const chip = (label, title) => {
        const c = h('span', 'inline-flex h-5 max-w-[9rem] items-center truncate rounded-full border border-neutral-200 bg-neutral-50 px-1.5 text-[10px] font-medium text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim', label);
        if (title) c.title = title;
        return c;
      };
      if (extra) chips.append(chip(`+${extra} ${extra === 1 ? 'server' : 'servers'}`, zoneNames(node).join(', ')));
      for (const v of volumes.slice(0, MAX_VOLUMES)) chips.append(chip(v, `Volume ${v}`));
      if (volumes.length > MAX_VOLUMES) chips.append(chip(`+${volumes.length - MAX_VOLUMES}`, volumes.slice(MAX_VOLUMES).join(', ')));
      card.append(chips);
    }

    const children = node.children || [];
    if (children.length) {
      const list = h('ul', 'mt-2 border-t border-neutral-100 pt-1.5 dark:border-white/[0.06]');
      for (const child of children.slice(0, MAX_CHILDREN)) {
        const row = h('li', 'flex h-5 items-center gap-2 text-[12px] leading-5');
        row.append(statusDot(child.status, 'size-1.5'), h('span', 'min-w-0 truncate', child.name));
        list.append(row);
      }
      if (children.length > MAX_CHILDREN) list.append(h('li', 'h-5 text-[11px] leading-5 text-neutral-400 dark:text-fg-faint', `+${children.length - MAX_CHILDREN} more`));
      card.append(list);
    }

    // Drag dot: shown on hover only. Connected ends always show (link end dots in renderLinks).
    if (canEdit() && (connectView ? isSource(node) && !node.missing : !serverView || !!node.environment)) {
      const port = h('span', 'absolute top-1/2 -right-1.5 size-3 -translate-y-1/2 cursor-crosshair rounded-full border-2 border-white bg-neutral-400 opacity-0 shadow transition-[opacity,scale,background-color] group-hover:opacity-100 hover:scale-120 hover:bg-coollabs dark:border-raised dark:bg-neutral-500 dark:hover:bg-warning');
      port.dataset.ecPort = '';
      port.title = connectView ? text.port : 'Drag to another card to draw a link';
      port.addEventListener('pointerdown', e => startConnect(node.id, port, e));
      card.append(port);
    }

    card.addEventListener('pointerdown', e => startCardDrag(node.id, card, e));
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        select({ kind: 'node', id: node.id });
      }
    });
    card.addEventListener('pointerenter', () => {
      if (s.interactions.size) return;
      s.hover = node.id;
      renderLinks();
    });
    card.addEventListener('pointerleave', () => {
      if (s.hover !== node.id) return;
      s.hover = null;
      renderLinks();
    });
    return card;
  }

  function renderZones() {
    const boxes = s.graph ? zoneBoxes(s.graph, s.positions, s.sizes) : [];
    ui.groupLayer.replaceChildren();
    if (s.graph?.layout === 'server' || s.graph?.layout === 'connect') {
      for (const zone of zoneGroups(s.graph)) {
        for (const frame of groupFrames(zone.nodes.filter(n => n.context), s.positions, s.sizes)) {
          const el = h('div', 'absolute rounded-xl border border-dashed border-neutral-300 dark:border-white/[0.12]');
          el.style.left = `${frame.x}px`;
          el.style.top = `${frame.y}px`;
          el.style.width = `${frame.width}px`;
          el.style.height = `${frame.height}px`;
          const label = h('div', 'flex h-8 items-center gap-1.5 px-3 text-[11px] font-medium text-neutral-500 dark:text-fg-dim');
          label.append(icon('service', 'size-3 text-neutral-400 dark:text-fg-faint'), titleLink('pointer-events-auto truncate hover:text-neutral-700 dark:hover:text-fg', frame.name || 'Other', frame.link));
          el.append(label);
          ui.groupLayer.append(el);
        }
      }
    }
    const keep = new Set();
    for (const box of boxes) {
      const key = box.id ?? '__none';
      keep.add(key);
      let el = ui.zones.get(key);
      if (!el) {
        el = buildZone(box);
        ui.zones.set(key, el);
        ui.zoneLayer.append(el);
      } else {
        el.zoneBox = box;
      }
      el.style.left = `${box.x}px`;
      el.style.top = `${box.y}px`;
      el.style.width = `${box.width}px`;
      el.style.height = `${box.height}px`;
    }
    for (const [key, el] of ui.zones) {
      if (!keep.has(key)) {
        el.remove();
        ui.zones.delete(key);
      }
    }
  }

  function buildZone(box) {
    const zone = box.zone;
    const frame = h('div', 'absolute rounded-2xl border border-neutral-200 bg-white/50 dark:border-white/[0.07] dark:bg-white/[0.02]');
    frame.zoneBox = box;
    const header = h('div', `flex h-10 items-center gap-2 px-4 text-[12px] ${canMoveZones() ? 'cursor-grab active:cursor-grabbing' : ''}`);
    header.dataset.ecZoneHeader = '';
    header.append(icon(zone?.icon || 'server', 'size-3.5 text-neutral-400 dark:text-fg-faint'));
    header.append(titleLink('truncate font-semibold text-neutral-700 dark:text-fg', box.name || NO_SERVER, zone?.link));
    if (zone?.icon) {
      // Not a server zone (shared variables view).
    } else if (zone) {
      const ok = zone.is_reachable && zone.is_usable;
      const dot = h('span', `size-1.5 shrink-0 rounded-full ${ok ? 'bg-emerald-500' : zone.is_reachable ? 'bg-warning' : 'bg-red-500'}`);
      dot.title = ok ? 'Reachable' : zone.is_reachable ? 'Reachable, not usable' : 'Not reachable';
      header.append(dot);
      if (zone.ip) header.append(h('span', 'truncate font-mono text-[11px] text-neutral-400 dark:text-fg-faint', zone.ip));
    } else {
      header.title = 'Resources without a server';
    }
    if (canMoveZones()) header.title = 'Drag to move every card in this zone';
    header.addEventListener('pointerdown', e => startZoneDrag(frame, header, e));
    frame.append(header);
    return frame;
  }

  function linkClasses(link) {
    const selected = s.selected?.kind === 'link' && s.selected.id === link.id;
    const related = s.hover && (link.source === s.hover || link.target === s.hover);
    if (selected) return 'fill-none stroke-coollabs dark:stroke-warning';
    if (link.missing) return `ec-missing fill-none stroke-red-400 dark:stroke-red-400/70 ${s.hover && !related ? 'opacity-20' : ''}`;
    if (related) return 'fill-none stroke-neutral-700 dark:stroke-white/80';
    if (s.hover) return 'fill-none stroke-neutral-400 opacity-20 dark:stroke-white/40';
    return 'fill-none stroke-neutral-400 dark:stroke-white/35';
  }

  function renderLinks(temp) {
    ui.links.replaceChildren();
    ui.labelLayer.replaceChildren();
    ui.dotLayer.replaceChildren();
    for (const link of s.graph?.links || []) {
      const a = rectOf(link.source);
      const b = rectOf(link.target);
      if (!a || !b) continue;
      const { d, mid, from, to } = linkPath(a, b);
      const hit = svg('path', { d, fill: 'none', stroke: 'transparent', 'stroke-width': '14', 'pointer-events': 'stroke', class: 'cursor-pointer' });
      hit.dataset.ecLink = link.id;
      hit.addEventListener('click', e => {
        e.stopPropagation();
        select({ kind: 'link', id: link.id });
      });
      const title = svg('title');
      title.textContent = connectView
        ? `${nodeById(link.source)?.name || ''} → ${nodeById(link.target)?.name || ''} (click to open)`
        : link.label ? `${link.label} (click to open)` : 'Click to open this link';
      hit.append(title);
      const selected = s.selected?.kind === 'link' && s.selected.id === link.id;
      const line = svg('path', { d, 'stroke-width': selected ? '2.5' : '1.75', 'stroke-linecap': 'round', class: `${linkClasses(link)} transition-opacity` });
      ui.links.append(line, hit);
      const faded = s.hover && link.source !== s.hover && link.target !== s.hover;
      for (const at of [from, to]) {
        const dot = h('span', `absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow dark:border-raised ${selected ? 'bg-coollabs dark:bg-warning' : 'bg-neutral-400 dark:bg-neutral-500'} ${faded ? 'opacity-30' : ''}`);
        dot.style.left = `${at.x}px`;
        dot.style.top = `${at.y}px`;
        ui.dotLayer.append(dot);
      }
      if (link.label) {
        const pill = h('button', `absolute max-w-48 -translate-x-1/2 -translate-y-1/2 cursor-pointer truncate rounded-full border px-2 py-0.5 text-[11px] font-medium shadow-sm transition-opacity ${selected
          ? 'border-coollabs bg-white text-coollabs dark:border-warning dark:bg-panel dark:text-warning'
          : 'border-neutral-200 bg-white text-neutral-600 dark:border-white/[0.1] dark:bg-panel dark:text-fg-dim'} ${faded ? 'opacity-30' : ''}`, link.label);
        pill.type = 'button';
        pill.dataset.ecLabel = '';
        pill.style.left = `${mid.x}px`;
        pill.style.top = `${mid.y}px`;
        pill.addEventListener('click', e => {
          e.stopPropagation();
          select({ kind: 'link', id: link.id });
        });
        ui.labelLayer.append(pill);
      }
    }
    for (const link of s.pending) {
      const a = rectOf(link.source);
      const b = rectOf(link.target);
      if (a && b) ui.links.append(svg('path', { d: linkPath(a, b).d, 'stroke-width': '2', 'stroke-linecap': 'round', class: 'ec-temp fill-none stroke-coollabs dark:stroke-warning' }));
    }
    if (temp) {
      const dx = Math.max(40, Math.abs(temp.to.x - temp.from.x) / 2);
      const d = `M ${temp.from.x} ${temp.from.y} C ${temp.from.x + dx} ${temp.from.y}, ${temp.to.x - dx} ${temp.to.y}, ${temp.to.x} ${temp.to.y}`;
      ui.links.append(svg('path', { d, 'stroke-width': '2', 'stroke-linecap': 'round', class: 'ec-temp fill-none stroke-coollabs dark:stroke-warning' }));
    }
  }

  function renderOverlay(error) {
    ui.overlay.replaceChildren();
    const empty = s.graph && !s.graph.nodes.length;
    if (!error && !empty) {
      ui.overlay.classList.add('hidden');
      ui.overlay.classList.remove('flex');
      return;
    }
    ui.overlay.classList.remove('hidden');
    ui.overlay.classList.add('flex');
    const box = h('div', 'pointer-events-auto flex max-w-sm flex-col items-center rounded-xl border border-neutral-200 bg-white px-6 py-8 text-center shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]');
    const tile = h('div', 'mb-3 flex size-9 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim');
    tile.append(icon('service', 'size-4'));
    box.append(tile);
    if (error) {
      box.append(h('p', 'text-[13px] font-semibold text-black dark:text-fg', 'Could not load the canvas'), h('p', 'mt-1 text-[12px] text-neutral-500 dark:text-fg-faint', error));
      const retry = h('button', 'button mt-4', 'Try again');
      retry.type = 'button';
      retry.addEventListener('click', () => load());
      box.append(retry);
    } else {
      box.append(h('p', 'text-[13px] font-semibold text-black dark:text-fg', connectView
        ? text.empty
        : ds.empty ? ds.empty
        : serverView ? 'No resources on this server yet' : 'No resources in this environment yet'));
      if (ds.new) {
        const add = h('a', 'button button-highlighted mt-4');
        add.href = ds.new;
        add.append(icon('plus'), 'Add resource');
        box.append(add);
      }
    }
    ui.overlay.append(box);
  }

  // ── Side panel ──
  function select(selection) {
    s.selected = selection;
    renderCards();
    renderZones();
    renderLinks();
    renderPanel();
  }
  function closePanel() {
    if (!s.selected) return;
    select(null);
  }

  function panelSection(title, content) {
    const section = h('section', 'border-t border-neutral-100 px-4 py-3 dark:border-white/[0.06]');
    section.append(h('h3', 'mb-1.5 text-[11px]! leading-4! font-medium! text-neutral-400 dark:text-fg-faint', title));
    section.append(content);
    return section;
  }

  function panelShell(title, iconName) {
    ui.panel.replaceChildren();
    const head = h('div', 'flex items-center gap-2.5 px-4 py-3');
    const tile = h('div', 'flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim');
    tile.append(icon(iconName, 'size-4'));
    const name = h('h2', 'min-w-0 flex-1 truncate text-[14px]! leading-5! font-semibold! text-black dark:text-fg', title);
    const close = h('button', 'flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.07] dark:hover:text-fg');
    close.type = 'button';
    close.title = 'Close';
    close.setAttribute('aria-label', 'Close panel');
    close.append(icon('x'));
    close.addEventListener('click', closePanel);
    head.append(tile, name, close);
    const scroll = h('div', 'min-h-0 flex-1 overflow-y-auto');
    ui.panel.append(head, scroll);
    return scroll;
  }

  function renderPanel() {
    const sel = s.selected;
    const node = sel?.kind === 'node' ? nodeById(sel.id) : null;
    const link = sel?.kind === 'link' ? linkById(sel.id) : null;
    if (!node && !link) {
      ui.panel.classList.add('hidden');
      ui.panel.classList.remove('flex');
      ui.panel.replaceChildren();
      return;
    }
    ui.panel.classList.remove('hidden');
    ui.panel.classList.add('flex');
    if (connectView) {
      if (isSource(node)) renderSourcePanel(node);
      else if (node) renderResourceSourcesPanel(node);
      else renderConnectLinkPanel(link);
    } else if (node) renderNodePanel(node);
    else renderLinkPanel(link);
  }

  function renderNodePanel(node) {
    const scroll = panelShell(node.name, node.type);
    const meta = h('div', 'flex flex-wrap items-center gap-2 px-4 pb-3');
    const status = h('span', 'inline-flex h-6 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white');
    status.append(statusDot(node.status, 'size-1.5'), statusLabel(node.status));
    meta.append(h('span', 'text-[12px] text-neutral-500 dark:text-fg-faint', TYPE_LABELS[node.type] || node.type), status);
    scroll.append(meta);
    if (node.subtitle) scroll.append(panelSection('Details', h('p', 'text-[12px] break-words', node.subtitle)));
    if (node.urls?.length) {
      const list = h('ul', 'flex flex-col gap-1');
      for (const u of node.urls) {
        const a = h('a', 'inline-flex max-w-full items-center gap-1.5 text-[12px] text-neutral-600 hover:text-black hover:underline dark:text-fg-dim dark:hover:text-fg');
        a.href = u;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.append(h('span', 'truncate', u.replace(/^https?:\/\//, '')), icon('external', 'size-3'));
        const li = h('li', 'min-w-0');
        li.append(a);
        list.append(li);
      }
      scroll.append(panelSection(node.urls.length === 1 ? 'URL' : 'URLs', list));
    }
    const servers = zoneNames(node);
    scroll.append(panelSection(servers.length > 1 ? 'Servers' : 'Server', h('p', 'text-[12px]', servers.length ? servers.join(', ') : NO_SERVER)));
    if (node.children?.length) {
      const list = h('ul', 'flex flex-col');
      for (const child of node.children) {
        const row = h('li', 'flex h-6 items-center gap-2 text-[12px]');
        row.title = statusLabel(child.status);
        row.append(statusDot(child.status, 'size-1.5'), h('span', 'min-w-0 flex-1 truncate', child.name), h('span', 'text-[11px] text-neutral-400 dark:text-fg-faint', TYPE_LABELS[child.type] || child.type || ''));
        list.append(row);
      }
      scroll.append(panelSection('Containers', list));
    }
    if (node.volumes?.length) {
      const chips = h('div', 'flex flex-wrap gap-1');
      for (const v of node.volumes) chips.append(h('span', 'max-w-full truncate rounded-full border border-neutral-200 bg-neutral-50 px-2 py-0.5 text-[11px] text-neutral-600 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim', v));
      scroll.append(panelSection('Volumes', chips));
    }
    const links = (s.graph.links || []).filter(l => l.source === node.id || l.target === node.id);
    const linkList = h('ul', 'flex flex-col gap-0.5');
    if (!links.length) {
      linkList.append(h('li', 'text-[12px] text-neutral-400 dark:text-fg-faint', canEdit() ? 'No links. Drag the dot on the right edge of a card to another card.' : 'No links.'));
    }
    for (const l of links) {
      const other = nodeById(l.source === node.id ? l.target : l.source);
      const b = h('button', 'flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12px] transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.06]');
      b.type = 'button';
      b.append(icon('link', 'size-3.5 text-neutral-400 dark:text-fg-faint'), h('span', 'min-w-0 flex-1 truncate', other?.name || 'Unknown'));
      if (l.label) b.append(h('span', 'max-w-24 truncate text-[11px] text-neutral-400 dark:text-fg-faint', l.label));
      b.addEventListener('click', () => select({ kind: 'link', id: l.id }));
      const li = h('li');
      li.append(b);
      linkList.append(li);
    }
    scroll.append(panelSection('Links', linkList));
    if (node.link) {
      const footer = h('div', 'border-t border-neutral-100 p-4 dark:border-white/[0.06]');
      const open = h('a', 'button button-highlighted w-full');
      open.href = node.link;
      open.append('Open');
      footer.append(open);
      ui.panel.append(footer);
    }
  }

  function renderLinkPanel(link) {
    const scroll = panelShell(link.label || 'Link', 'link');
    const ends = h('div', 'flex flex-col gap-1');
    for (const [label, id] of [['From', link.source], ['To', link.target]]) {
      const n = nodeById(id);
      const row = h('button', 'flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12px] transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.06]');
      row.type = 'button';
      row.append(h('span', 'w-9 text-[11px] text-neutral-400 dark:text-fg-faint', label), statusDot(n?.status, 'size-1.5'), h('span', 'min-w-0 flex-1 truncate', n?.name || 'Unknown'));
      row.addEventListener('click', () => select({ kind: 'node', id }));
      ends.append(row);
    }
    scroll.append(panelSection('Resources', ends));
    if (canEdit()) {
      const input = h('input', 'input h-8 w-full text-[12px]');
      input.type = 'text';
      input.maxLength = 255;
      input.placeholder = 'For example: reads from';
      input.value = link.label || '';
      input.setAttribute('aria-label', 'Link label');
      input.dataset.ecLabelInput = '';
      let saved = link.label || '';
      const save = async () => {
        const value = input.value.trim();
        if (value === saved) return;
        saved = value;
        try {
          const updated = await api('PATCH', `${environmentBase(nodeById(link.source))}/links/${encodeURIComponent(link.id)}`, { label: value || null });
          replaceLink({ ...link, ...(updated?.id ? updated : { label: value || null }) });
          toast('Label saved.');
        } catch (error) {
          saved = link.label || '';
          toast(error.message, true);
        }
      };
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          input.blur();
        } else if (e.key === 'Escape') {
          e.stopPropagation();
          input.value = saved;
          input.blur();
        }
      });
      input.addEventListener('blur', save);
      scroll.append(panelSection('Label', input));
    } else {
      scroll.append(panelSection('Label', h('p', 'text-[12px]', link.label || 'No label')));
    }
    scroll.append(h('p', 'border-t border-neutral-100 px-4 py-3 text-[12px] text-neutral-500 dark:border-white/[0.06] dark:text-fg-faint', 'Links are for the diagram only. They do not change your resources.'));
    if (canEdit()) {
      const footer = h('div', 'border-t border-neutral-100 p-4 dark:border-white/[0.06]');
      const remove = h('button', 'button w-full text-red-600! dark:text-red-400!', 'Remove link');
      remove.type = 'button';
      remove.addEventListener('click', async () => {
        remove.disabled = true;
        try {
          await api('DELETE', `${environmentBase(nodeById(link.source))}/links/${encodeURIComponent(link.id)}`);
          s.graph.links = s.graph.links.filter(l => l.id !== link.id);
          select(null);
          toast('Link removed.');
        } catch (error) {
          remove.disabled = false;
          toast(error.message, true);
        }
      });
      footer.append(remove);
      ui.panel.append(footer);
    }
  }

  // ── Connect view panels (shared variables and tags) ──
  const panelNote = text => h('p', 'border-t border-neutral-100 px-4 py-3 text-[12px] text-neutral-500 dark:border-white/[0.06] dark:text-fg-faint', text);
  const rowButton = () => {
    const b = h('button', 'flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12px] transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.06]');
    b.type = 'button';
    return b;
  };
  const smallButton = (text, title, danger = false) => {
    const b = h('button', `button h-6! shrink-0 px-2! text-[11px]! ${danger ? 'text-red-600! dark:text-red-400!' : ''}`, text);
    b.type = 'button';
    b.title = title;
    return b;
  };
  const removeTitle = link => (link.keys?.length
    ? `Delete ${link.keys.join(', ')} from ${nodeById(link.target)?.name}`
    : `Remove ${nodeById(link.source)?.name} from ${nodeById(link.target)?.name}`);
  const removeHint = link => `${link.keys.join(', ')} uses ${nodeById(link.source)?.reference || 'the variable'} inside a longer value. Edit it on the resource.`;

  async function removeLink(link, button) {
    const variable = nodeById(link.source);
    const resource = nodeById(link.target);
    if (button) button.disabled = true;
    try {
      await api('DELETE', `${base}/links/${encodeURIComponent(link.id)}`);
      if (s.destroyed) return;
      if (s.selected?.kind === 'link') s.selected = { kind: 'node', id: link.source };
      setLinks(s.full.links.filter(l => l.id !== link.id));
      toast(text.removed(variable?.name, resource?.name));
      load({ quiet: true });
    } catch (error) {
      if (button) button.disabled = false;
      toast(error.message, true);
    }
  }

  /** Searchable list of cards, each with an Add button (connect view side panels). */
  function addSection(title, placeholder, empty, candidates, describe, onAdd) {
    const content = h('div', 'flex flex-col gap-2');
    const list = h('ul', 'flex flex-col gap-1.5');
    if (!candidates.length) {
      list.append(h('li', 'text-[12px] text-neutral-400 dark:text-fg-faint', empty));
    } else {
      const search = h('input', 'input h-8 w-full text-[12px]');
      search.type = 'search';
      search.placeholder = placeholder;
      search.setAttribute('aria-label', placeholder);
      search.addEventListener('input', () => {
        const query = search.value.trim().toLowerCase();
        for (const li of list.children) li.classList.toggle('hidden', !!query && !li.dataset.search.includes(query));
      });
      content.append(search);
    }
    for (const candidate of candidates) {
      const { title: addTitle, sub } = describe(candidate);
      const li = h('li', 'flex items-center gap-1');
      li.dataset.search = `${candidate.name} ${sub}`.toLowerCase();
      const label = h('div', 'flex min-w-0 flex-1 items-center gap-2 px-1.5 py-1 text-[12px]');
      const lines = h('div', 'min-w-0 flex-1');
      lines.append(h('div', `truncate ${candidate.type === 'variable' ? 'font-mono' : ''}`, candidate.name));
      if (sub) lines.append(h('div', 'truncate text-[11px] text-neutral-400 dark:text-fg-faint', sub));
      label.append(icon(candidate.type, 'size-3.5 text-neutral-400 dark:text-fg-faint'), lines);
      const add = smallButton('Add', addTitle);
      add.addEventListener('click', async () => {
        add.disabled = true;
        await onAdd(candidate);
        add.disabled = false;
      });
      li.append(label, add);
      list.append(li);
    }
    content.append(list);
    return panelSection(title, content);
  }

  function renderSourcePanel(node) {
    const scroll = panelShell(node.name, node.type);
    const meta = h('div', 'flex flex-wrap items-center gap-2 px-4 pb-3');
    if (node.reference) meta.append(h('code', 'rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim', node.reference));
    if (node.missing) meta.append(h('span', 'rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400', 'Deleted'));
    scroll.append(meta);
    if (node.missing) {
      scroll.append(panelNote(`This shared variable was deleted. The resources below still reference it and get the text ${node.reference} instead of a value. Add the variable again, or remove the reference.`));
    } else if (node.subtitle) {
      scroll.append(panelSection('Description', h('p', 'text-[12px] break-words', node.subtitle)));
    }

    const links = s.graph.links.filter(l => l.source === node.id);
    const used = h('ul', 'flex flex-col gap-1.5');
    if (!links.length) used.append(h('li', 'text-[12px] text-neutral-400 dark:text-fg-faint', text.noTargets));
    for (const link of links) {
      const resource = nodeById(link.target);
      const li = h('li', 'flex items-center gap-1');
      const open = rowButton();
      open.append(statusDot(resource?.status, 'size-1.5'), h('span', 'min-w-0 flex-1 truncate', resource?.name || 'Unknown'));
      if (link.label) open.append(h('span', 'max-w-24 truncate font-mono text-[11px] text-neutral-400 dark:text-fg-faint', link.label));
      open.title = resource?.context || '';
      open.addEventListener('click', () => select({ kind: 'link', id: link.id }));
      li.append(open);
      if (canEdit()) {
        const remove = smallButton('Remove', link.removable ? removeTitle(link) : removeHint(link), true);
        remove.disabled = !link.removable;
        remove.addEventListener('click', () => removeLink(link, remove));
        li.append(remove);
      }
      used.append(li);
    }
    scroll.append(panelSection(`${text.usedBy} (${links.length})`, used));

    if (canEdit() && !node.missing) {
      const linked = new Set(links.map(l => l.target));
      scroll.append(addSection('Add to resources', 'Search resources', text.allTargets,
        s.graph.nodes.filter(n => !isSource(n) && !linked.has(n.id)),
        resource => ({ title: `Add ${node.name} to ${resource.name}`, sub: resource.context || '' }),
        resource => createLink(node.id, resource.id)));
    }
    if (!node.missing) scroll.append(panelNote(canEdit() ? text.note(node) : text.viewOnly));
    if (node.link) {
      const footer = h('div', 'border-t border-neutral-100 p-4 dark:border-white/[0.06]');
      const open = h('a', 'button w-full');
      open.href = node.link;
      open.append(`Open ${TYPE_LABELS[node.type].toLowerCase()}`);
      footer.append(open);
      ui.panel.append(footer);
    }
  }

  function renderResourceSourcesPanel(node) {
    const scroll = panelShell(node.name, node.type);
    const meta = h('div', 'flex flex-wrap items-center gap-2 px-4 pb-3');
    const status = h('span', 'inline-flex h-6 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white');
    status.append(statusDot(node.status, 'size-1.5'), statusLabel(node.status));
    meta.append(h('span', 'text-[12px] text-neutral-500 dark:text-fg-faint', TYPE_LABELS[node.type] || node.type), status);
    scroll.append(meta);
    if (node.context) scroll.append(panelSection('Project / environment', titleLink('text-[12px] hover:underline', node.context, node.context_link)));
    const list = h('ul', 'flex flex-col gap-1.5');
    const links = s.graph.links.filter(l => l.target === node.id);
    if (!links.length) list.append(h('li', 'text-[12px] text-neutral-400 dark:text-fg-faint', text.noSources));
    for (const link of links) {
      const variable = nodeById(link.source);
      const b = rowButton();
      b.append(icon(variable?.type || 'variable', `size-3.5 ${link.missing ? 'text-red-500' : 'text-neutral-400 dark:text-fg-faint'}`), h('span', `min-w-0 flex-1 truncate ${variable?.type === 'variable' ? 'font-mono' : ''}`, variable?.name || 'Unknown'));
      if (link.missing) b.append(h('span', 'text-[11px] text-red-500', 'Deleted'));
      b.addEventListener('click', () => select({ kind: 'node', id: link.source }));
      const li = h('li', 'flex items-center gap-1');
      li.append(b);
      if (canEdit()) {
        const remove = smallButton('Remove', link.removable ? removeTitle(link) : removeHint(link), true);
        remove.disabled = !link.removable;
        remove.addEventListener('click', () => removeLink(link, remove));
        li.append(remove);
      }
      list.append(li);
    }
    scroll.append(panelSection(`${text.sources} (${links.length})`, list));
    if (canEdit()) {
      const linked = new Set(links.map(l => l.source));
      scroll.append(addSection(text.addSources, text.searchSources, text.noMoreSources,
        s.graph.nodes.filter(n => isSource(n) && !n.missing && !linked.has(n.id)),
        source => ({ title: `Add ${source.name} to ${node.name}`, sub: source.type === 'variable' ? source.subtitle || '' : '' }),
        source => createLink(source.id, node.id)));
    }
    if (node.link) {
      const footer = h('div', 'border-t border-neutral-100 p-4 dark:border-white/[0.06]');
      const open = h('a', 'button button-highlighted w-full');
      open.href = node.link;
      open.append('Open');
      footer.append(open);
      ui.panel.append(footer);
    }
  }

  function renderConnectLinkPanel(link) {
    const variable = nodeById(link.source);
    const resource = nodeById(link.target);
    const scroll = panelShell(`${variable?.name || text.source} → ${resource?.name || 'Resource'}`, 'link');
    const ends = h('div', 'flex flex-col gap-1');
    for (const [label, node] of [[text.source, variable], ['Resource', resource]]) {
      const row = rowButton();
      row.append(h('span', 'w-14 text-[11px] text-neutral-400 dark:text-fg-faint', label), h('span', `min-w-0 flex-1 truncate ${node?.type === 'variable' ? 'font-mono' : ''}`, node?.name || 'Unknown'));
      row.addEventListener('click', () => node && select({ kind: 'node', id: node.id }));
      ends.append(row);
    }
    scroll.append(panelSection('Ends', ends));
    if (link.keys?.length) {
      const keys = h('div', 'flex flex-wrap gap-1');
      for (const key of link.keys) keys.append(h('code', 'rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[11px] dark:border-white/[0.1] dark:bg-white/[0.05]', key));
      scroll.append(panelSection(`Variables on ${resource?.name || 'the resource'}`, keys));
    }
    if (link.missing) scroll.append(panelNote(`The shared variable was deleted. ${resource?.name} gets the text ${variable?.reference} instead of a value.`));
    if (canEdit()) {
      if (!link.removable) scroll.append(panelNote(removeHint(link)));
      const footer = h('div', 'border-t border-neutral-100 p-4 dark:border-white/[0.06]');
      const remove = h('button', 'button w-full text-red-600! dark:text-red-400!', 'Remove');
      remove.type = 'button';
      remove.disabled = !link.removable;
      remove.title = link.removable ? removeTitle(link) : removeHint(link);
      remove.addEventListener('click', () => removeLink(link, remove));
      footer.append(remove);
      ui.panel.append(footer);
    }
  }

  function replaceLink(link) {
    s.graph.links = s.graph.links.map(l => (l.id === link.id ? link : l));
    renderLinks();
    const input = ui.panel.querySelector('[data-ec-label-input]');
    if (s.selected?.kind === 'link' && s.selected.id === link.id && document.activeElement !== input) renderPanel();
    else ui.panel.querySelector('h2')?.replaceChildren(link.label || 'Link');
  }

  // ── Interactions ──
  function begin(key) {
    s.interactions.add(key);
  }
  function end(key) {
    s.interactions.delete(key);
    if (!s.interactions.size && s.deferred) {
      const graph = s.deferred;
      s.deferred = null;
      applyGraph(graph);
    }
  }

  function startCardDrag(id, card, e) {
    if (e.button !== 0 || e.target.closest('a,button,input,[data-ec-port]')) return;
    e.stopPropagation();
    const start = { cx: e.clientX, cy: e.clientY, ...s.positions[id] };
    // The other cards and frames stay put during the drag, so measure them once.
    const rules = dragRules(s.graph, s.positions, s.sizes, id);
    let moved = false;
    capture(card, e.pointerId);
    begin(`card:${id}`);
    const move = ev => {
      if (!moved && Math.hypot(ev.clientX - start.cx, ev.clientY - start.cy) < 4) return;
      if (!canMoveNode(nodeById(id))) return;
      const rect = rectOf(id);
      const wanted = { x: start.x + (ev.clientX - start.cx) / s.view.z, y: start.y + (ev.clientY - start.cy) / s.view.z, w: rect.width, h: rect.height };
      // Cards cannot cover each other or another zone: stop at the edge of what is in the way.
      const spot = freeSpot(wanted, rules.others, BOX_GAP, rules.fits);
      if (!spot) return;
      moved = true;
      s.positions[id] = { x: spot.x, y: spot.y };
      placeCard(id);
      renderZones();
      renderLinks();
    };
    const up = () => {
      card.removeEventListener('pointermove', move);
      card.removeEventListener('pointerup', up);
      card.removeEventListener('pointercancel', up);
      if (moved) queueSave({ [id]: s.positions[id] });
      else select({ kind: 'node', id });
      end(`card:${id}`);
    };
    card.addEventListener('pointermove', move);
    card.addEventListener('pointerup', up);
    card.addEventListener('pointercancel', up);
  }

  function startZoneDrag(frame, header, e) {
    if (e.button !== 0 || !canMoveZones() || e.target.closest('a')) return;
    e.stopPropagation();
    const ids = frame.zoneBox.nodes.filter(id => s.positions[id]);
    const starts = Object.fromEntries(ids.map(id => [id, { ...s.positions[id] }]));
    const origin = { cx: e.clientX, cy: e.clientY };
    // Zones cannot cover each other: the frame stops at the edge of another zone.
    const zone = toBox(frame.zoneBox);
    const others = zoneBoxes(s.graph, s.positions, s.sizes).filter(z => z.id !== frame.zoneBox.id).map(toBox);
    let moved = false;
    capture(header, e.pointerId);
    begin('zone');
    const move = ev => {
      if (!moved && Math.hypot(ev.clientX - origin.cx, ev.clientY - origin.cy) < 4) return;
      const spot = freeSpot({ ...zone, x: zone.x + (ev.clientX - origin.cx) / s.view.z, y: zone.y + (ev.clientY - origin.cy) / s.view.z }, others);
      if (!spot) return;
      moved = true;
      const dx = spot.x - zone.x;
      const dy = spot.y - zone.y;
      for (const id of ids) {
        s.positions[id] = { x: starts[id].x + dx, y: starts[id].y + dy };
        placeCard(id);
      }
      renderZones();
      renderLinks();
    };
    const up = () => {
      header.removeEventListener('pointermove', move);
      header.removeEventListener('pointerup', up);
      header.removeEventListener('pointercancel', up);
      if (moved) queueSave(Object.fromEntries(ids.map(id => [id, s.positions[id]])));
      end('zone');
    };
    header.addEventListener('pointermove', move);
    header.addEventListener('pointerup', up);
    header.addEventListener('pointercancel', up);
  }

  function startConnect(id, port, e) {
    if (e.button !== 0 || !canEdit()) return;
    e.stopPropagation();
    e.preventDefault();
    const a = rectOf(id);
    if (!a) return;
    const from = { x: a.x + a.width, y: a.y + a.height / 2 };
    let target = null;
    capture(port, e.pointerId);
    begin('connect');
    s.hover = null;
    const mark = (tid, on) => {
      const el = tid && ui.cards.get(tid);
      if (!el) return;
      // Same as the deploy canvas drop target: accent border and a soft 4px ring.
      for (const cls of ['border-coollabs!', 'dark:border-warning!', 'ring-4', 'ring-coollabs/15', 'dark:ring-warning/15']) el.classList.toggle(cls, on);
    };
    const move = ev => {
      renderLinks({ from, to: toWorld(ev.clientX, ev.clientY) });
      const tid = document.elementFromPoint(ev.clientX, ev.clientY)?.closest?.('[data-ec-card]')?.dataset.id;
      const next = tid && canConnect(s.graph, id, tid) ? tid : null;
      if (next !== target) {
        mark(target, false);
        target = next;
        mark(target, true);
      }
    };
    const up = async () => {
      port.removeEventListener('pointermove', move);
      port.removeEventListener('pointerup', up);
      port.removeEventListener('pointercancel', up);
      mark(target, false);
      renderLinks();
      const chosen = target;
      if (chosen) await createLink(id, chosen);
      end('connect');
    };
    port.addEventListener('pointermove', move);
    port.addEventListener('pointerup', up);
    port.addEventListener('pointercancel', up);
  }

  async function createLink(source, target) {
    if (!canLink(s.graph.links, source, target)) {
      toast(connectView ? text.already(nodeById(source)?.name, nodeById(target)?.name) : 'These resources are already linked.', true);
      return;
    }
    const pending = { source, target };
    s.pending.push(pending);
    renderLinks();
    try {
      const link = await api('POST', `${environmentBase(nodeById(source))}/links`, { source, target });
      if (s.destroyed) return;
      if (connectView) {
        // Keep the panel of the card the user added from (either end); otherwise open the source.
        const keep = s.selected?.kind === 'node' && (s.selected.id === source || s.selected.id === target);
        if (!keep) s.selected = { kind: 'node', id: source };
        if (link?.id) setLinks([...s.full.links.filter(l => l.id !== link.id), link]);
        else applyGraph(s.full);
        toast(text.added(nodeById(source)?.name, nodeById(target)?.name));
        load({ quiet: true });
        return;
      }
      const created = link?.data?.id ? link.data : link;
      if (created?.id) {
        s.graph.links = [...s.graph.links.filter(l => l.id !== created.id), created];
        select({ kind: 'link', id: created.id });
        ui.panel.querySelector('[data-ec-label-input]')?.focus();
      } else {
        load({ quiet: true });
      }
    } catch (error) {
      toast(error.message, true);
    } finally {
      s.pending = s.pending.filter(p => p !== pending);
      if (!s.destroyed && s.graph) renderLinks();
    }
  }

  // ── Persistence ──
  /**
   * Server view: environment canvas positions of the moved cards and of the other cards of their group,
   * so the whole group keeps its arrangement on the next layout.
   */
  function environmentPositions(ids) {
    const moved = ids.map(nodeById).filter(n => n?.environment);
    const members = (s.graph?.nodes || []).filter(n => s.offsets[n.id] && s.positions[n.id]
      && moved.some(m => m.zone === n.zone && m.environment.uuid === n.environment?.uuid));
    return Object.fromEntries(members.map(n => [n.id, { x: s.positions[n.id].x - s.offsets[n.id].x, y: s.positions[n.id].y - s.offsets[n.id].y }]));
  }
  function queueSave(map) {
    Object.assign(s.unsaved, serverView ? environmentPositions(Object.keys(map)) : map);
    clearTimeout(timers.save);
    timers.save = setTimeout(() => flushSave(), 400);
  }
  async function flushSave(options = {}) {
    clearTimeout(timers.save);
    const batch = { ...s.unsaved };
    const ids = Object.keys(batch);
    if (!ids.length || !s.graph) return;
    const positions = Object.fromEntries(ids.map(id => [id, { x: Math.round(batch[id].x), y: Math.round(batch[id].y) }]));
    const forget = () => {
      for (const id of ids) if (s.unsaved[id] === batch[id]) delete s.unsaved[id];
    };
    // Server view: one request per environment canvas.
    const requests = new Map();
    for (const id of ids) {
      const path = environmentBase(s.full?.nodes.find(n => n.id === id));
      requests.set(path, { ...requests.get(path), [id]: positions[id] });
    }
    try {
      await Promise.all([...requests].map(([path, part]) => api('PATCH', `${path}/positions`, { positions: part }, options)));
      if (s.graph) s.graph.positions = { ...storedPositions(s.graph), ...positions };
      forget();
    } catch (error) {
      forget();
      if (s.destroyed) return;
      toast(error.message, true);
      load({ quiet: true });
    }
  }

  // ── Data ──
  /** Shared variables view: a link change moves its resource in or out of the connected zone right away. */
  function setLinks(links) {
    applyGraph({ ...s.full, links });
  }

  function applyGraph(graph) {
    if (s.destroyed) return;
    if (s.interactions.size) {
      s.deferred = graph;
      return;
    }
    graph.zones ||= [];
    graph.nodes ||= [];
    graph.links ||= [];
    graph.positions = storedPositions(graph);
    // Local moves that are not saved yet stay where the user put them.
    graph.positions = { ...graph.positions, ...Object.fromEntries(Object.entries(s.unsaved).map(([id, p]) => [id, { x: p.x, y: p.y }])) };
    s.full = graph;
    s.graph = groupByUse(filterGraph(graph, s.filter));
    s.offsets = {};
    s.positions = s.graph.layout === 'server' ? layoutServerView(s.graph, s.offsets) : layoutGraph(s.graph);
    if (s.hover && !nodeById(s.hover)) s.hover = null;
    if (s.selected && !(s.selected.kind === 'node' ? nodeById(s.selected.id) : linkById(s.selected.id))) s.selected = null;
    // Zone frames are rebuilt so names, reachability, and edit rights stay current.
    ui.zoneLayer.replaceChildren();
    ui.zones.clear();
    renderAll();
    const editing = document.activeElement && ui.panel.contains(document.activeElement) && document.activeElement.matches('input');
    if (!editing) renderPanel();
    if (!s.fitted) {
      s.fitted = true;
      // The first load keeps a saved view; later refits (filter changes) fit again.
      if (restoredView) restoredView = null;
      else fit();
      // Connect view: ?select=<card id> (from a variable's or tag's connect button) opens that card.
      const wanted = connectView && params.get('select');
      if (wanted && !s.selected && nodeById(wanted)) select({ kind: 'node', id: wanted });
      params.delete('select');
    }
  }

  async function load({ quiet = false } = {}) {
    if (s.destroyed) return;
    if (s.loading) {
      s.reloadQueued = true;
      return;
    }
    s.loading = true;
    ui.refresh.disabled = true;
    ui.refresh.querySelector('svg')?.classList.add('animate-spin');
    try {
      const graph = await api('GET', base);
      if (graph && s.containers) graph.nodes = [...(graph.nodes || []), ...s.containers];
      applyGraph(graph);
      if (!quiet && s.fitted && graph) toast('Canvas refreshed.');
      // Status refreshes keep the containers; the first load and the Refresh button read them again.
      if (graph?.containers_endpoint && (!quiet || !s.containers)) loadContainers(graph.containers_endpoint);
    } catch (error) {
      if (s.destroyed) return;
      if (s.graph) toast(error.message, true);
      else renderOverlay(error.message);
    } finally {
      s.loading = false;
      if (!s.destroyed) {
        ui.refresh.disabled = false;
        ui.refresh.querySelector('svg')?.classList.remove('animate-spin');
        if (s.reloadQueued) {
          s.reloadQueued = false;
          load({ quiet: true });
        }
      }
    }
  }

  /** Server canvas: adds the unmanaged containers. This runs a command on the server, so the canvas does not wait for it. */
  async function loadContainers(path) {
    s.containers ||= []; // status refreshes during this request do not start another one
    try {
      const { nodes = [] } = (await api('GET', path)) || {};
      if (s.destroyed || !s.full) return;
      s.containers = nodes;
      applyGraph({ ...s.full, nodes: [...s.full.nodes.filter(n => n.type !== 'container'), ...nodes] });
    } catch {
      // The server zone already shows when the server is not reachable.
    }
  }

  function scheduleRefresh(delay = 1000) {
    clearTimeout(timers.refresh);
    timers.refresh = setTimeout(() => load({ quiet: true }), delay);
  }

  // ── Wiring ──
  // Double-click on a card opens its page. Tracked on pointerdown (capture phase, before drags start):
  // the first click re-renders the card and can open the side panel over it, so the second press can land on
  // another element. Two presses at the same point open the page of the first one.
  let lastOpen = null;
  listen(root, 'pointerdown', e => {
    const own = e.button !== 0 || e.target.closest('a,button,input,[data-ec-port]');
    const again = !own && lastOpen && e.timeStamp - lastOpen.at < DOUBLE_CLICK_MS
      && Math.hypot(e.clientX - lastOpen.x, e.clientY - lastOpen.y) < 6;
    if (again) {
      e.preventDefault();
      e.stopPropagation();
      window.location.assign(lastOpen.href);
      lastOpen = null;
      return;
    }
    const href = own ? null : e.target.closest('[data-ec-open]')?.dataset.ecOpen;
    lastOpen = href ? { href, at: e.timeStamp, x: e.clientX, y: e.clientY } : null;
  }, true);
  ui.projectFilter.addEventListener('change', () => setFilter({ project: ui.projectFilter.value, environment: '' }));
  ui.environmentFilter.addEventListener('change', () => setFilter({ ...s.filter, environment: ui.environmentFilter.value }));
  zoomIn.addEventListener('click', () => zoomTo(s.view.z * 1.2));
  zoomOut.addEventListener('click', () => zoomTo(s.view.z / 1.2));
  ui.zoomLabel.addEventListener('click', () => zoomTo(1));
  fitButton.addEventListener('click', fit);
  ui.refresh.addEventListener('click', () => load());
  listen(document, 'keydown', e => {
    if (e.key === 'Escape' && s.selected && !e.defaultPrevented) closePanel();
  });
  listen(document, 'visibilitychange', () => {
    if (document.visibilityState === 'visible') scheduleRefresh(300);
  });
  listen(window, 'pagehide', () => {
    saveView();
    if (Object.keys(s.unsaved).length) flushSave({ keepalive: true });
  });

  const onStatus = () => scheduleRefresh();
  for (const [kind, events] of STATUS_CHANNELS) {
    if (!window.Echo || !ds[kind]) continue;
    try {
      const channel = window.Echo.private(`${kind}.${ds[kind]}`);
      for (const event of events) channel.listen(event, onStatus);
      cleanups.push(() => {
        for (const event of events) channel.stopListening(event, onStatus);
      });
    } catch {
      // Live updates are optional; Refresh still works.
    }
  }

  applyView();
  load({ quiet: true });

  return {
    container,
    root,
    unmount() {
      if (s.destroyed) return;
      saveView();
      if (Object.keys(s.unsaved).length) flushSave({ keepalive: true });
      s.destroyed = true;
      clearTimeout(timers.save);
      clearTimeout(timers.refresh);
      clearTimeout(timers.toast);
      for (const cleanup of cleanups.splice(0)) cleanup();
      bar.remove();
      root.remove();
    },
  };
}

// ── Page mount (initial load and Livewire SPA navigation) ─────────────

export function initializeEnvironmentCanvas() {
  mountOnPage('[data-environment-canvas]', '[data-ec-root]', mountEnvironmentCanvas);
}
