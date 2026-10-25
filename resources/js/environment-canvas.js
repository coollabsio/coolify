// Environment canvas (Beta): pure helpers and constants. The Svelte
// components live in canvas/environment/.
//
// DOM-free: `node --test` imports this file.

import { BOX_GAP, freeSpot, MAX_ZOOM, MIN_ZOOM } from './canvas-core.js';
import { compareEnvironmentNames } from './environment-order.js';

export const CARD_WIDTH = 260;
export const COLUMN_GAP = 48;
export const ROW_GAP = 24;
export const ZONE_GAP = 72;
export const ZONE_PADDING = 24;
export const ZONE_HEADER = 40;
export const SERVER_ZONE_HEADER = 68; // server canvas: a second header row with host facts, proxy and usage
export const SERVER_ZONE_MIN_WIDTH = 560; // server canvas: room for the header (name, IP, status, host facts) above one card
export const MAX_CHILDREN = 5;
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
/** Header height of a zone frame: server canvas zones show a second row of server details. */
export const zoneHeader = zone => (zone?.details ? SERVER_ZONE_HEADER : ZONE_HEADER);
const storedPositions = graph => (graph?.positions && !Array.isArray(graph.positions) ? graph.positions : {});

// ── Pure helpers (tested) ─────────────────────────────────────────────

/** Estimated card height in px; matches the rendered card closely enough for auto placement. */
export function estimateNodeHeight(node) {
  let height = 58; // padding + icon/name row
  if (node?.urls?.length) height += 20;
  if (node?.extra_zones?.length) height += 28;
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
  // Then by project, then production, staging, and development before the other environments.
  const project = ([name, members]) => members[0]?.project?.name ?? name;
  const environment = ([name, members]) => members[0]?.environment?.name ?? name;
  return [...groups.entries()].sort(
    (a, b) => unmanaged(a) - unmanaged(b) || project(a).localeCompare(project(b)) || compareEnvironmentNames(environment(a), environment(b)),
  );
};
const byTypeAndName = (a, b) => columnOf(a) - columnOf(b) || String(a.name).localeCompare(String(b.name));

/**
 * Card positions inside one group, from (0, 0). Cards with a position saved on their environment
 * canvas (node.saved) keep that arrangement; the other cards follow below them in the grid the environment
 * canvas uses, so both canvases show the group the same way. Otherwise linked
 * cards form chains from left to right (source before target, one column per step); every chain
 * gets its own rows. Cards without links follow in a grid of SINGLE_COLUMNS.
 */
export function layoutBlock(nodes, links = []) {
  const saved = nodes.filter(n => isPoint(n.saved));
  if (saved.length) {
    const left = Math.min(...saved.map(n => Number(n.saved.x)));
    const top = Math.min(...saved.map(n => Number(n.saved.y)));
    const out = {};
    for (const n of saved) out[n.id] = { x: Number(n.saved.x) - left, y: Number(n.saved.y) - top };
    const width = Math.max(...saved.map(n => out[n.id].x)) + CARD_WIDTH;
    const bottom = Math.max(...saved.map(n => out[n.id].y + estimateNodeHeight(n)));
    const rest = nodes.filter(n => !out[n.id]);
    if (!rest.length) return { positions: out, width, height: bottom };
    // Same grid as the environment canvas (layoutGraph) puts below the saved cards, so both canvases match.
    const height = placeGrid(rest, 0, bottom + ROW_GAP, out);
    return { positions: out, width: Math.max(...Object.values(out).map(p => p.x)) + CARD_WIDTH, height };
  }
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
 * Server view: key of a project / environment group on one server ("<environment uuid>:<zone id>").
 * Groups of an empty environment (placeholder card) have none, so they cannot be moved.
 */
export const groupKey = (nodes, zoneId) => {
  const environment = nodes.find(n => n.type !== 'empty' && n.environment?.uuid)?.environment?.uuid;
  return environment && zoneId ? `${environment}:${zoneId}` : null;
};

/**
 * Server view: zones stack downwards; a zone with a saved place (zone.position: projects, servers and dashboard canvases) sits there and the
 * stacked zones keep clear of it. A group with cards saved on its environment canvas (node.saved) sits where
 * those cards are, so the server canvas and the environment canvas share one position (a group that would
 * leave the zone or cover another group moves to the nearest free spot). Other groups follow below,
 * GROUPS_PER_ROW per row. When `groups` is given, it gets per group key its frame top-left, the zone's inner
 * top-left, and `shift`: environment canvas position = server view position - shift. When `zones` is given,
 * it gets per zone id the zone's top-left (the place that zone.position saves).
 */
export function layoutServerView(graph, groups = null, { compact = false, zones = null } = {}) {
  const out = {};
  const laid = zoneGroups(graph).map(zone => ({ zone, ...layoutServerZone(zone, graph.links || [], compact) }));
  // Saved zones first, so the stacked zones can keep clear of them.
  const taken = [];
  const put = (l, origin) => {
    for (const [id, p] of Object.entries(l.positions)) out[id] = { x: origin.x + p.x, y: origin.y + p.y };
    for (const [key, g] of Object.entries(groups ? l.groups : {})) {
      groups[key] = {
        x: origin.x + g.x,
        y: origin.y + g.y,
        inner: { x: origin.x + g.inner.x, y: origin.y + g.inner.y },
        shift: { x: origin.x + g.shift.x, y: origin.y + g.shift.y },
      };
    }
    if (zones && l.zone.id != null) zones[l.zone.id] = { ...origin };
    taken.push({ x: origin.x - ZONE_PADDING, y: origin.y, w: l.width + ZONE_PADDING * 2, h: l.height });
  };
  const savedPlace = l => (isPoint(l.zone.zone?.position) ? { x: Number(l.zone.zone.position.x), y: Number(l.zone.zone.position.y) } : null);
  for (const l of laid.filter(savedPlace)) put(l, savedPlace(l));
  const pinned = taken.slice();
  let y = 0;
  for (const l of laid.filter(l => !savedPlace(l))) {
    const wanted = { x: -ZONE_PADDING, y, w: l.width + ZONE_PADDING * 2, h: l.height };
    const spot = (pinned.length && freeSpot(wanted, pinned, ZONE_GAP, b => b.x === wanted.x && b.y >= y)) || wanted;
    put(l, { x: 0, y: spot.y });
    y = spot.y + l.height + ZONE_GAP;
  }
  return out;
}

/** One zone of the server view laid out at the origin (see layoutServerView). */
function layoutServerZone(zone, links, compact) {
  const out = {};
  const groups = {};
  const inner = { x: 0, y: zoneHeader(zone.zone) + ZONE_PADDING };
  const blocks = contextGroups(zone.nodes).map(([, nodes]) => {
    const saved = nodes.filter(n => isPoint(n.saved));
    const block = layoutBlock(nodes, links);
    return {
      key: groupKey(nodes, zone.id),
      nodes,
      block,
      size: { w: block.width + GROUP_PADDING * 2, h: GROUP_HEADER + GROUP_PADDING * 2 + block.height },
      at: saved.length ? { x: Math.min(...saved.map(n => Number(n.saved.x))), y: Math.min(...saved.map(n => Number(n.saved.y))) } : null,
    };
  });
  // Empty environments (placeholder cards only) go next to the other groups after these are placed.
  const isEmpty = b => b.nodes.every(n => n.type === 'empty');
  const pinned = blocks.filter(b => b.at).sort((a, b) => a.at.y - b.at.y || a.at.x - b.at.x);
  // The environment canvas puts its first cards below a zone header; those land at the zone's top-left here.
  const shift = { x: inner.x + GROUP_PADDING, y: inner.y + GROUP_HEADER + GROUP_PADDING - ZONE_HEADER - ZONE_PADDING };
  const frames = [];
  const place = ({ key, block, size }, left, top) => {
    frames.push({ x: left, y: top, w: size.w });
    for (const [id, p] of Object.entries(block.positions)) {
      out[id] = { x: left + GROUP_PADDING + p.x, y: top + GROUP_HEADER + GROUP_PADDING + p.y };
    }
    if (key) groups[key] = { x: left, y: top, inner, shift };
    return top + size.h;
  };
  const taken = [];
  const inside = b => b.x >= inner.x && b.y >= inner.y;
  let bottom = inner.y;
  for (const b of pinned) {
    // Environment canvas positions can be left of or above the zone (negative coordinates): keep the group inside.
    const wanted = {
      x: Math.max(inner.x, b.at.x + shift.x - GROUP_PADDING),
      y: Math.max(inner.y, b.at.y + shift.y - GROUP_HEADER - GROUP_PADDING),
      ...b.size,
    };
    const spot = freeSpot(wanted, taken, GROUP_GAP, inside) || wanted;
    taken.push({ ...spot, project: b.nodes[0]?.project?.uuid });
    bottom = Math.max(bottom, place(b, spot.x, spot.y));
  }
  let x = 0;
  let rowTop = pinned.length ? bottom + GROUP_GAP : inner.y;
  let rowBottom = rowTop;
  blocks.filter(b => !b.at && !isEmpty(b)).forEach((b, i) => {
    if (i > 0 && i % GROUPS_PER_ROW === 0) {
      x = 0;
      rowTop = rowBottom + GROUP_GAP;
    }
    taken.push({ x, y: rowTop, ...b.size, project: b.nodes[0]?.project?.uuid });
    rowBottom = Math.max(rowBottom, place(b, x, rowTop));
    x += b.size.w + GROUP_GAP;
  });
  bottom = Math.max(bottom, rowBottom);
  // Right of the last group of the same project (or of the zone), at the nearest free spot.
  for (const b of blocks.filter(b => !b.at && isEmpty(b))) {
    const project = b.nodes[0]?.project?.uuid;
    const anchor = taken.filter(t => t.project === project).at(-1) || taken.at(-1);
    const wanted = anchor ? { x: anchor.x + anchor.w + GROUP_GAP, y: anchor.y, ...b.size } : { ...inner, ...b.size };
    const spot = freeSpot(wanted, taken, GROUP_GAP, inside) || wanted;
    taken.push({ ...spot, project });
    bottom = Math.max(bottom, place(b, spot.x, spot.y));
  }
  if (compact && frames.length) {
    // The groups keep their arrangement, but start at the zone's top-left (no empty space from saved positions).
    const dx = inner.x - Math.min(...frames.map(f => f.x));
    const dy = inner.y - Math.min(...frames.map(f => f.y));
    for (const n of zone.nodes) if (out[n.id]) out[n.id] = { x: out[n.id].x + dx, y: out[n.id].y + dy };
    for (const f of frames) f.x += dx;
    bottom += dy;
  }
  const width = Math.max(0, ...frames.map(f => f.x + f.w), zone.zone?.details ? SERVER_ZONE_MIN_WIDTH - ZONE_PADDING * 2 : 0);
  return { positions: out, groups, width, height: bottom + ZONE_PADDING };
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
  const offset = variables ? CARD_WIDTH + ZONE_PADDING * 2 + ZONE_GAP * 2 : 0;
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
  // Compact: groups cannot be moved here, so positions saved on the environment canvas must not leave gaps.
  const placed = layoutServerView({ zones: others.map(g => g.zone || { id: g.id }), nodes: others.flatMap(g => g.nodes) }, null, { compact: true });
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
      key: groupKey(members, members[0].zone),
      zone: members[0].zone,
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
      y: minY - ZONE_PADDING - zoneHeader(group.zone),
      width: Math.max(maxX - minX + ZONE_PADDING * 2, group.zone?.details ? SERVER_ZONE_MIN_WIDTH : 0),
      height: maxY - minY + ZONE_PADDING * 2 + zoneHeader(group.zone),
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

// Area of `b` within `gap` of `a`: 0 when they are apart().
const crowding = (a, b, gap) => Math.max(0, Math.min(a.x + a.w + gap, b.x + b.w) - Math.max(a.x - gap, b.x))
  * Math.max(0, Math.min(a.y + a.h + gap, b.y + b.h) - Math.max(a.y - gap, b.y));

/**
 * What a dragged card must keep clear of, for freeSpot(): `others` are the
 * other cards and the other zone frames (and, server view, the other project /
 * environment frames in its zone), grown by the room the card's own frames
 * take around it. `fits` checks the card's own frames, which also span its
 * other cards, against those frames: they must not come closer to them than
 * at the start of the drag. Frames that are already too close (the layout
 * uses estimated card heights) do not lock the cards in place.
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
  const top = ZONE_PADDING + zoneHeader(own?.zone) + groupTop;
  const zones = zoneBoxes(graph, positions, sizes).filter(z => !z.nodes.includes(id)).map(toBox);
  const groups = grouped ? groupFrames(own.nodes.filter(n => context(n) !== context(node)), positions, sizes).map(toBox) : [];
  const zoneMates = rects(own?.nodes || []);
  const groupMates = grouped ? rects(own.nodes.filter(n => context(n) === context(node))) : [];
  // The other box must keep the frame's room on the side that faces the card.
  const room = (b, s, t) => ({ x: b.x - s, y: b.y - s, w: b.w + s * 2, h: b.h + s + t });
  const at = node && cardRect(node, positions, sizes);
  const notCloser = (frame, boxes, gap) => {
    const start = at ? frame(toBox(at)) : null;
    return box => {
      const moved = frame(box);
      return boxes.every(b => crowding(moved, b, gap) <= (start ? crowding(start, b, gap) : 0));
    };
  };
  const zoneFits = notCloser(box => frameAround([...zoneMates, box], side, top), zones, BOX_GAP);
  // Same gap between groups as layoutServerView, so a reload does not move the group.
  const groupFits = grouped ? notCloser(box => frameAround([...groupMates, box], groupSide, groupTop), groups, GROUP_GAP) : () => true;
  return {
    others: [...rects(graph.nodes), ...zones.map(z => room(z, side, top)), ...groups.map(g => room(g, groupSide, groupTop))],
    fits: box => zoneFits(box) && groupFits(box),
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

export const READABLE_ZOOM = 0.6;
const FIT_PAD = 48;

/**
 * View ({x, y, z}) that shows the content bounds in the free part of the viewport ({left, top, width, height}).
 * `readable`: never below READABLE_ZOOM; content that does not fit then starts at its top-left,
 * so the cards can be read and the user scrolls to the rest.
 */
export function fitView(bounds, area, readable = false) {
  const w = bounds.maxX - bounds.minX;
  const h = bounds.maxY - bounds.minY;
  const room = { w: area.width - FIT_PAD * 2, h: area.height - FIT_PAD * 2 };
  const whole = Math.min(room.w / w, room.h / h);
  const z = Math.min(1.1, Math.max(readable ? READABLE_ZOOM : MIN_ZOOM, whole));
  const x = w * z <= room.w ? area.left + (area.width - w * z) / 2 : area.left + FIT_PAD;
  const y = h * z <= room.h ? area.top + (area.height - h * z) / 2 : area.top + FIT_PAD;
  return { z, x: x - bounds.minX * z, y: y - bounds.minY * z };
}

/** Resource cards per status tone ({ok, warn, bad, busy, idle}); unmanaged containers and placeholder cards are not counted. */
export function statusSummary(nodes) {
  const out = { ok: 0, warn: 0, bad: 0, busy: 0, idle: 0 };
  for (const n of nodes || []) if (n.type !== 'container' && n.type !== 'empty' && !isSource(n)) out[statusTone(n.status)]++;
  return out;
}

/** "running:healthy" → "Running": the state word for the status text on a card. */
export function statusWord(status) {
  return statusLabel(status).replace(/\s*\(.*$/, '');
}

/** 1007820800 → "1 GB", 17179869184 → "16 GB". */
export function formatMemory(bytes) {
  if (!bytes) return null;
  const gb = bytes / 1024 ** 3;
  return `${gb < 10 ? Math.round(gb * 10) / 10 : Math.round(gb)} GB`;
}

/** Time since a "YYYY-MM-DD HH:MM:SS" (UTC) or ISO date, as "45m", "5h", "12d". */
export function formatUptime(since, now = Date.now()) {
  const at = Date.parse(String(since || '').replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(since || '') ? '' : 'Z'));
  if (!Number.isFinite(at)) return null;
  const minutes = Math.max(0, Math.floor((now - at) / 60000));
  if (minutes < 60) return `${minutes}m`;
  if (minutes < 60 * 24) return `${Math.floor(minutes / 60)}h`;
  return `${Math.floor(minutes / 1440)}d`;
}

/**
 * Ids of the server zones whose server is not reachable. The saved status of their resources is stale,
 * so the canvas shows it as unknown. Zones with an icon (projects, connect view) are not servers.
 */
export function unreachableZones(graph) {
  return new Set((graph?.zones || []).filter(z => !z.icon && z.is_reachable === false).map(z => z.id));
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
 * Connect and server views: projects of the resources sorted by name, each with its environments in environment order.
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
    .map(p => ({ uuid: p.uuid, name: p.name, environments: [...p.environments.values()].sort((a, b) => compareEnvironmentNames(a.name, b.name)) }))
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

// ── Card and panel constants (used by the Svelte components) ──────────

// Application and service events go to the team channel; database events go to the user channel.
export const STATUS_CHANNELS = [['team', ['ApplicationStatusChanged', 'ServiceStatusChanged']], ['user', ['DatabaseStatusChanged']]];
export const TYPE_LABELS = { application: 'Application', service: 'Service', database: 'Database', container: 'Unmanaged container', variable: 'Shared variable', tag: 'Tag' };
// Wording of the connect view, per data-mode (shared variables or tags).
export const CONNECT_TEXT = {
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
export const TONE_DOT = {
  ok: 'bg-emerald-500',
  warn: 'bg-warning',
  bad: 'bg-red-500',
  idle: 'bg-neutral-400 dark:bg-neutral-500',
  busy: 'bg-warning',
};
// Outline icons, drawn from local paths.
export const ICONS = {
  chevron: ['M6 15l6-6 6 6'],
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

// Small screens: a notice that the canvas is not optimized for mobile. Shown below 768px (md);
// closed once, it stays closed on every canvas (same key as the deploy canvas notice).
export const MOBILE_NOTICE_KEY = 'coolify-canvas:mobile-notice-dismissed';
