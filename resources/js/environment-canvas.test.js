import test from 'node:test';
import assert from 'node:assert/strict';
import {
  CARD_WIDTH,
  GROUP_GAP,
  MAX_ROWS,
  COLUMN_GAP,
  ROW_GAP,
  SERVER_ZONE_MIN_WIDTH,
  ZONE_HEADER,
  ZONE_PADDING,
  ZONE_GAP,
  canConnect,
  canLink,
  dragRules,
  filterGraph,
  groupByUse,
  filterOptions,
  fitView,
  formatMemory,
  formatUptime,
  SERVER_ZONE_HEADER,
  statusSummary,
  statusWord,
  READABLE_ZOOM,
  groupFrames,
  groupKey,
  layoutBlock,
  estimateNodeHeight,
  layoutGraph,
  layoutServerView,
  linkPath,
  statusLabel,
  statusTone,
  zoneBoxes,
  readView,
  unreachableZones,
} from './environment-canvas.js';
import { freeSpot } from './canvas-core.js';

const node = (id, type, zone, extra = {}) => ({ id, type, zone, name: id, status: 'running:healthy', extra_zones: [], urls: [], children: [], ...extra });
const graph = (nodes, extra = {}) => ({
  zones: [{ id: 's1', name: 'One' }, { id: 's2', name: 'Two' }],
  nodes,
  links: [],
  positions: {},
  ...extra,
});
const column = n => n * (CARD_WIDTH + COLUMN_GAP);

test('places applications, services, and databases in their own columns', () => {
  const p = layoutGraph(graph([node('db', 'database', 's1'), node('app', 'application', 's1'), node('svc', 'service', 's1')]));
  assert.equal(p.app.x, column(0));
  assert.equal(p.svc.x, column(1));
  assert.equal(p.db.x, column(2));
  const top = ZONE_HEADER + ZONE_PADDING;
  assert.deepEqual([p.app.y, p.svc.y, p.db.y], [top, top, top]);
});

test('stacks rows of the same type inside a column', () => {
  const first = node('a1', 'application', 's1', { urls: ['https://a.test'] });
  const p = layoutGraph(graph([first, node('a2', 'application', 's1')]));
  assert.equal(p.a2.x, p.a1.x);
  assert.equal(p.a2.y, p.a1.y + estimateNodeHeight(first) + ROW_GAP);
});

test('wraps a long list into another column and shifts later types right', () => {
  const apps = Array.from({ length: MAX_ROWS + 1 }, (_, i) => node(`a${i}`, 'application', 's1'));
  const p = layoutGraph(graph([...apps, node('db', 'database', 's1')]));
  assert.equal(p[`a${MAX_ROWS - 1}`].x, column(0));
  assert.deepEqual(p[`a${MAX_ROWS}`], { x: column(1), y: ZONE_HEADER + ZONE_PADDING });
  assert.equal(p.db.x, column(2));
});

test('stacks zones in graph order with the "No server" zone last', () => {
  const p = layoutGraph(graph([node('orphan', 'application', null), node('b', 'application', 's2'), node('a', 'application', 's1')]));
  assert.ok(p.a.y < p.b.y, 'first server zone on top');
  assert.ok(p.b.y < p.orphan.y, 'no-server zone at the bottom');
  const boxes = zoneBoxes(graph([node('orphan', 'application', null), node('b', 'application', 's2'), node('a', 'application', 's1')]), p);
  assert.deepEqual(boxes.map(b => b.name), ['One', 'Two', 'No server']);
  for (let i = 1; i < boxes.length; i++) assert.ok(boxes[i].y > boxes[i - 1].y + boxes[i - 1].height, 'zones do not overlap');
});

test('treats a zone id that is not in the zone list as no server', () => {
  const g = graph([node('x', 'database', 'gone')]);
  assert.deepEqual(zoneBoxes(g, layoutGraph(g)).map(b => b.id), [null]);
});

test('stored positions win over auto placement', () => {
  const p = layoutGraph(graph([node('app', 'application', 's1')], { positions: { app: { x: 500, y: -40 } } }));
  assert.deepEqual(p.app, { x: 500, y: -40 });
});

test('treats an empty positions list from PHP as nothing stored', () => {
  const p = layoutGraph(graph([node('app', 'application', 's1')], { positions: [] }));
  assert.equal(p.app.x, 0);
});

test('places new nodes in a wrapped grid below the stored cards of their zone', () => {
  const stored = node('a1', 'application', 's1');
  const apps = Array.from({ length: MAX_ROWS + 1 }, (_, i) => node(`n${i}`, 'application', 's1'));
  const p = layoutGraph(graph([stored, ...apps, node('d1', 'database', 's1')], { positions: { a1: { x: 100, y: 200 } } }));
  const top = 200 + estimateNodeHeight(stored) + ROW_GAP;
  assert.deepEqual(p.n0, { x: 100, y: top });
  assert.deepEqual(p[`n${MAX_ROWS}`], { x: 100 + column(1), y: top }, 'wraps after MAX_ROWS cards');
  assert.deepEqual(p.d1, { x: 100 + column(2), y: top }, 'databases go right of the application columns');
});

test('auto-placed zones go below zones with stored cards', () => {
  const p = layoutGraph(graph([node('a', 'application', 's1'), node('b', 'application', 's2')], { positions: { a: { x: 0, y: 1000 } } }));
  assert.ok(p.b.y > 1000 + estimateNodeHeight(node('a', 'application', 's1')) + ZONE_PADDING + ZONE_HEADER);
});

test('estimates taller cards for urls, chips, and children', () => {
  const plain = estimateNodeHeight(node('a', 'service', 's1'));
  const rich = estimateNodeHeight(node('b', 'service', 's1', { urls: ['https://x'], extra_zones: ['s2'], children: [{ name: 'c' }, { name: 'd' }] }));
  assert.ok(rich > plain);
  const capped = estimateNodeHeight(node('c', 'service', 's1', { children: Array.from({ length: 20 }, (_, i) => ({ name: `c${i}` })) }));
  const six = estimateNodeHeight(node('d', 'service', 's1', { children: Array.from({ length: 6 }, (_, i) => ({ name: `c${i}` })) }));
  assert.equal(capped, six, 'children beyond the cap add one "+N more" row');
});

test('zone boxes wrap their cards with padding and header', () => {
  const g = graph([node('a', 'application', 's1'), node('d', 'database', 's1')]);
  const positions = { a: { x: 0, y: 0 }, d: { x: 400, y: 50 } };
  const sizes = { a: { width: 260, height: 100 }, d: { width: 260, height: 80 } };
  const [box] = zoneBoxes(g, positions, sizes);
  assert.deepEqual(
    { id: box.id, x: box.x, y: box.y, width: box.width, height: box.height, nodes: box.nodes },
    { id: 's1', x: -ZONE_PADDING, y: -ZONE_PADDING - ZONE_HEADER, width: 660 + ZONE_PADDING * 2, height: 130 + ZONE_PADDING * 2 + ZONE_HEADER, nodes: ['a', 'd'] },
  );
});

test('server canvas zone boxes are wide enough for their header, also with one card', () => {
  const g = graph([node('a', 'application', 's1')], { zones: [{ id: 's1', name: 'One', details: {} }] });
  const [box] = zoneBoxes(g, { a: { x: 0, y: 0 } }, { a: { width: 260, height: 100 } });
  assert.equal(box.x, -ZONE_PADDING);
  assert.equal(box.width, SERVER_ZONE_MIN_WIDTH);
});

test('zone boxes skip zones without cards', () => {
  assert.deepEqual(zoneBoxes(graph([node('a', 'application', 's2')]), { a: { x: 0, y: 0 } }).map(b => b.id), ['s2']);
});

const rect = (x, y) => ({ x, y, width: 260, height: 100 });

test('links go from the right edge to the left edge when the target is on the right', () => {
  const path = linkPath(rect(0, 0), rect(400, 200));
  assert.equal(path.side, 'right');
  assert.deepEqual(path.from, { x: 260, y: 50 });
  assert.deepEqual(path.to, { x: 400, y: 250 });
  assert.match(path.d, /^M 260 50 C /);
});

test('links go from the left edge to the right edge when the target is on the left', () => {
  const path = linkPath(rect(400, 0), rect(0, 0));
  assert.equal(path.side, 'left');
  assert.deepEqual(path.from, { x: 400, y: 50 });
  assert.deepEqual(path.to, { x: 260, y: 50 });
});

test('links run vertically between stacked cards', () => {
  const down = linkPath(rect(0, 0), rect(40, 300));
  assert.equal(down.side, 'down');
  assert.deepEqual([down.from, down.to], [{ x: 130, y: 100 }, { x: 170, y: 300 }]);
  const up = linkPath(rect(0, 300), rect(0, 0));
  assert.equal(up.side, 'up');
  assert.deepEqual([up.from, up.to], [{ x: 130, y: 300 }, { x: 130, y: 100 }]);
});

test('link midpoint sits between both ends', () => {
  const { mid } = linkPath(rect(0, 0), rect(600, 0));
  assert.deepEqual(mid, { x: 430, y: 50 });
});

test('maps Coolify statuses to tones', () => {
  const cases = {
    'running:healthy': 'ok',
    'running:unknown': 'ok',
    running: 'ok',
    'running (healthy)': 'ok',
    'running:unhealthy': 'warn',
    'degraded:unhealthy': 'warn',
    'exited:unhealthy': 'bad',
    exited: 'bad',
    stopped: 'bad',
    restarting: 'busy',
    'starting:unknown': 'busy',
    paused: 'idle',
    '': 'idle',
    null: 'idle',
  };
  for (const [status, tone] of Object.entries(cases)) {
    assert.equal(statusTone(status === 'null' ? null : status), tone, status);
  }
});

test('formats status labels', () => {
  assert.equal(statusLabel('running:healthy'), 'Running (healthy)');
  assert.equal(statusLabel('exited'), 'Exited');
  assert.equal(statusLabel(''), 'Unknown');
});

test('refuses self links and duplicates in either direction', () => {
  const links = [{ id: 'l1', source: 'a', target: 'b', label: null }];
  assert.equal(canLink(links, 'a', 'a'), false);
  assert.equal(canLink(links, 'a', 'b'), false);
  assert.equal(canLink(links, 'b', 'a'), false);
  assert.equal(canLink(links, 'a', 'c'), true);
});

test('a server view card has the same height as on the environment canvas', () => {
  const card = { id: 'a', type: 'application' };
  // The group frame names the project / environment, so the card has no extra line for it.
  assert.equal(estimateNodeHeight({ ...card, context: 'Acme / production' }), estimateNodeHeight(card));
});

// ── Server view ──
const srv = (id, type, context, extra = {}) => ({ id, type, name: id, zone: 'z1', context, ...extra });

test('server view: a chain of linked cards runs left to right, source before target', () => {
  const nodes = [srv('db', 'database', 'A / prod'), srv('web', 'application', 'A / prod'), srv('api', 'application', 'A / prod')];
  const { positions } = layoutBlock(nodes, [{ source: 'web', target: 'api' }, { source: 'api', target: 'db' }]);
  assert.equal(positions.web.y, positions.api.y);
  assert.equal(positions.api.y, positions.db.y);
  assert.ok(positions.web.x < positions.api.x && positions.api.x < positions.db.x);
  assert.equal(positions.api.x - positions.web.x, CARD_WIDTH + COLUMN_GAP);
});

test('server view: cards without links go below the chains, in a grid', () => {
  const nodes = [srv('a', 'application', 'g'), srv('b', 'application', 'g'), srv('c', 'service', 'g'), srv('d', 'database', 'g'), srv('e', 'database', 'g')];
  const { positions } = layoutBlock(nodes, [{ source: 'a', target: 'd' }]);
  assert.equal(positions.a.y, positions.d.y); // linked pair side by side
  for (const id of ['b', 'c', 'e']) assert.ok(positions[id].y > positions.a.y, `${id} is below the chain`);
  assert.equal(positions.b.y, positions.c.y); // one grid row
});

test('server view: a link cycle still gets every card a position', () => {
  const nodes = [srv('x', 'application', 'g'), srv('y', 'application', 'g')];
  const { positions } = layoutBlock(nodes, [{ source: 'x', target: 'y' }, { source: 'y', target: 'x' }]);
  assert.ok(positions.x && positions.y);
  assert.notDeepEqual(positions.x, positions.y);
});

test('server view: one frame per project / environment, and the frames do not overlap', () => {
  const graph = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [srv('a', 'application', 'Shop / prod'), srv('b', 'database', 'Shop / prod'), srv('c', 'application', 'Blog / prod')],
    links: [{ id: 'l', source: 'a', target: 'b' }],
    positions: {},
  };
  const positions = layoutGraph(graph);
  const frames = groupFrames(graph.nodes, positions);
  assert.deepEqual(frames.map(f => f.name), ['Blog / prod', 'Shop / prod']);
  const [f1, f2] = frames;
  assert.ok(f1.x + f1.width <= f2.x || f2.x + f2.width <= f1.x || f1.y + f1.height <= f2.y || f2.y + f2.height <= f1.y);
  assert.equal(positions.a.y, positions.b.y);
});

test('groups: production, staging, and development come before the other environments of a project', () => {
  const env = (id, project, environment) =>
    srv(id, 'application', `${project} / ${environment}`, { project: { uuid: project, name: project }, environment: { uuid: id, name: environment } });
  const graph = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [env('a', 'Shop', 'deprecated'), env('b', 'Shop', 'development'), env('c', 'Shop', 'production'), env('d', 'Shop', 'qa'), env('e', 'Shop', 'staging'), env('f', 'Blog', 'zeta')],
    links: [],
    positions: {},
  };
  const frames = groupFrames(graph.nodes, layoutGraph(graph));
  assert.deepEqual(frames.map(f => f.name), ['Blog / zeta', 'Shop / production', 'Shop / staging', 'Shop / development', 'Shop / deprecated', 'Shop / qa']);
});

const envNode = (id, type, zone, environment, extra = {}) => srv(id, type, `Shop / ${environment}`, { zone, project: { uuid: 'p' }, environment: { uuid: environment }, ...extra });

test('server view: cards link only to cards of the same environment', () => {
  const g = {
    layout: 'server',
    nodes: [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'database', 'z2', 'prod'), envNode('c', 'application', 'z1', 'dev'), { id: 'container:1', type: 'container', zone: 'z1' }],
  };
  assert.equal(canConnect(g, 'a', 'b'), true);
  assert.equal(canConnect(g, 'a', 'c'), false);
  assert.equal(canConnect(g, 'a', 'a'), false);
  assert.equal(canConnect(g, 'a', 'container:1'), false);
  assert.equal(canConnect(g, 'container:1', 'a'), false);
});

test('server view: positions saved on an environment canvas are ignored', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'database', 'z1', 'prod')],
    links: [],
    positions: {},
  };
  const auto = layoutServerView(g);
  assert.deepEqual(layoutServerView({ ...g, positions: { a: { x: 900, y: 700 } } }), auto);
});

test('server view: cards keep the arrangement saved on their environment canvas, new cards go below', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [
      envNode('a', 'application', 'z1', 'prod', { saved: { x: 900, y: 700 } }),
      envNode('b', 'database', 'z1', 'prod', { saved: { x: 600, y: 900 } }),
      envNode('c', 'service', 'z1', 'prod'),
    ],
    links: [],
  };
  const p = layoutServerView(g);
  assert.equal(p.a.x - p.b.x, 300);
  assert.equal(p.b.y - p.a.y, 200);
  assert.equal(p.c.x, p.b.x);
  assert.ok(p.c.y >= p.b.y + estimateNodeHeight(g.nodes[1]) + ROW_GAP);
});

test('server view: a group with one saved card matches the environment canvas arrangement', () => {
  const apps = Array.from({ length: MAX_ROWS + 1 }, (_, i) => envNode(`n${i}`, 'application', 'z1', 'prod'));
  const nodes = [envNode('a', 'application', 'z1', 'prod', { saved: { x: 1000, y: 697 } }), ...apps, envNode('d', 'database', 'z1', 'prod')];
  const env = layoutGraph({ zones: [{ id: 'z1', name: 'web' }], nodes, links: [], positions: { a: { x: 1000, y: 697 } } });
  const server = layoutServerView({ layout: 'server', zones: [{ id: 'z1', name: 'web' }], nodes, links: [{ source: 'n0', target: 'd' }] });
  for (const n of nodes) {
    assert.deepEqual({ x: server[n.id].x - server.a.x, y: server[n.id].y - server.a.y }, { x: env[n.id].x - env.a.x, y: env[n.id].y - env.a.y }, n.id);
  }
});

test('server view: a moved card saved with its group on the environment canvas stays where it was dropped', () => {
  const nodes = [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'service', 'z1', 'prod'), envNode('d', 'database', 'z1', 'prod')];
  const g = { layout: 'server', zones: [{ id: 'z1', name: 'web' }], nodes, links: [] };
  const groups = {};
  const before = layoutServerView(g, groups);
  const { shift } = groups['prod:z1'];
  const dropped = { ...before, b: { x: before.b.x + 120, y: before.b.y + 400 } };
  const saved = Object.fromEntries(nodes.map(n => [n.id, { x: dropped[n.id].x - shift.x, y: dropped[n.id].y - shift.y }]));
  const after = layoutServerView({ ...g, nodes: nodes.map(n => ({ ...n, saved: saved[n.id] })) });
  assert.deepEqual(after, dropped);
});

test('server view: zones with server details get the taller header', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web', details: { os: 'Ubuntu' } }],
    nodes: [envNode('a', 'application', 'z1', 'prod')],
    links: [],
    positions: {},
  };
  const positions = layoutServerView(g);
  const [box] = zoneBoxes(g, positions);
  assert.equal(box.y, 0);
  assert.ok(positions.a.y > SERVER_ZONE_HEADER);
});

test('server view: groups sit where their cards are saved on the environment canvas', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [
      envNode('a', 'application', 'z1', 'prod', { saved: { x: 0, y: ZONE_HEADER + ZONE_PADDING } }),
      envNode('b', 'application', 'z1', 'stage', { saved: { x: 600, y: ZONE_HEADER + ZONE_PADDING } }),
      envNode('c', 'application', 'z1', 'dev'),
    ],
    links: [],
    positions: {},
  };
  const groups = {};
  const positions = layoutServerView(g, groups);
  // A card at the start of the environment canvas sits at the zone's top-left.
  assert.equal(groups['prod:z1'].x, 0);
  assert.equal(groups['prod:z1'].y, groups['prod:z1'].inner.y);
  assert.equal(positions.b.x - positions.a.x, 600);
  // The environment canvas position comes back from the server view position.
  const { shift } = groups['prod:z1'];
  assert.deepEqual({ x: positions.b.x - shift.x, y: positions.b.y - shift.y }, { x: 600, y: ZONE_HEADER + ZONE_PADDING });
  // A group without saved cards goes below.
  assert.ok(positions.c.y > positions.a.y + estimateNodeHeight(g.nodes[0]));
});

test('server view: a group saved left of the zone stays inside it', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [envNode('a', 'application', 'z1', 'prod', { saved: { x: -236, y: 251 } })],
    links: [],
    positions: {},
  };
  const groups = {};
  layoutServerView(g, groups);
  assert.equal(groups['prod:z1'].x, groups['prod:z1'].inner.x);
});

test('server view: saved groups of different environments that would overlap are moved apart', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [
      envNode('a', 'application', 'z1', 'prod', { saved: { x: 0, y: 0 } }),
      envNode('b', 'application', 'z1', 'stage', { saved: { x: 0, y: 0 } }),
    ],
    links: [],
    positions: {},
  };
  const groups = {};
  layoutServerView(g, groups);
  const [p, s] = [groups['prod:z1'], groups['stage:z1']];
  assert.ok(Math.abs(p.x - s.x) >= CARD_WIDTH || Math.abs(p.y - s.y) >= estimateNodeHeight(g.nodes[0]), 'the groups do not cover each other');
});

test('status summary counts resources by tone, without unmanaged containers and placeholder cards', () => {
  const nodes = [
    node('a', 'application', 'z', { status: 'running:healthy' }),
    node('b', 'database', 'z', { status: 'exited:unhealthy' }),
    node('c', 'service', 'z', { status: 'exited' }),
    node('container:1', 'container', 'z', { status: 'exited' }),
    node('empty:1', 'empty', 'z', { status: null }),
  ];
  assert.deepEqual(statusSummary(nodes), { ok: 1, warn: 0, bad: 2, busy: 0, idle: 0 });
  assert.equal(statusWord('exited:unhealthy'), 'Exited');
});

test('server details: memory and uptime formats', () => {
  assert.equal(formatMemory(1007820800), '0.9 GB');
  assert.equal(formatMemory(17179869184), '16 GB');
  assert.equal(formatMemory(null), null);
  const now = Date.parse('2026-10-14T12:00:00Z');
  assert.equal(formatUptime('2026-10-14 11:15:00', now), '45m');
  assert.equal(formatUptime('2026-10-14 07:00:00', now), '5h');
  assert.equal(formatUptime('2026-10-02T12:00:00+00:00', now), '12d');
  assert.equal(formatUptime('nonsense', now), null);
});

test('unreachable server zones: only server zones whose server is not reachable', () => {
  const zones = [
    { id: 'up', is_reachable: true },
    { id: 'down', is_reachable: false },
    { id: 'project', icon: 'projects' },
  ];
  assert.deepEqual([...unreachableZones({ zones })], ['down']);
  assert.equal(unreachableZones(null).size, 0);
});

test('fit: shows everything when it stays readable, centered', () => {
  const v = fitView({ minX: 0, minY: 0, maxX: 400, maxY: 200 }, { left: 0, top: 0, width: 1000, height: 600 }, true);
  assert.equal(v.z, 1.1);
  assert.equal(v.x, (1000 - 440) / 2);
});

test('fit: the first fit of a large canvas keeps the cards readable and starts at the top-left', () => {
  const bounds = { minX: 100, minY: 50, maxX: 3100, maxY: 4050 };
  const area = { left: 0, top: 0, width: 1280, height: 520 };
  assert.ok(fitView(bounds, area).z < READABLE_ZOOM, 'the fit button still shows everything');
  const v = fitView(bounds, area, true);
  assert.equal(v.z, READABLE_ZOOM);
  assert.equal(v.x + bounds.minX * v.z, 48);
  assert.equal(v.y + bounds.minY * v.z, 48);
});

test('fit: a tall, narrow canvas keeps its column centered', () => {
  const v = fitView({ minX: 0, minY: 0, maxX: 300, maxY: 5000 }, { left: 0, top: 0, width: 1280, height: 520 }, true);
  assert.equal(v.z, READABLE_ZOOM);
  assert.equal(v.x, (1280 - 300 * READABLE_ZOOM) / 2);
  assert.equal(v.y, 48);
});

test('variables view: variables stack on the left by name, resources sit to their right', () => {
  const g = {
    layout: 'connect',
    zones: [{ id: 'sources', name: 'Team variables', icon: 'variable' }, { id: 'resources', name: 'Resources', icon: 'service' }],
    nodes: [
      node('variable:2', 'variable', 'sources', { name: 'TOKEN' }),
      node('variable:1', 'variable', 'sources', { name: 'API_URL' }),
      node('app', 'application', 'resources', { context: 'Shop / prod' }),
    ],
    links: [{ id: 'variable:1|app', source: 'variable:1', target: 'app' }],
    positions: {},
  };
  const p = layoutGraph(g);

  assert.equal(p['variable:1'].x, 0);
  assert.equal(p['variable:2'].x, 0);
  assert.ok(p['variable:1'].y < p['variable:2'].y);
  assert.ok(p.app.x > CARD_WIDTH + ZONE_PADDING * 2);
  const [variables, resources] = zoneBoxes(g, p);
  assert.ok(variables.x + variables.width < resources.x, 'zones do not overlap');
});

test('variables view: links only go from an existing shared variable to a resource', () => {
  const g = {
    layout: 'connect',
    nodes: [
      node('variable:1', 'variable', 'sources'),
      node('variable:2', 'variable', 'sources'),
      node('missing:OLD', 'variable', 'sources', { missing: true }),
      node('app', 'application', 'resources'),
    ],
  };

  assert.equal(canConnect(g, 'variable:1', 'app'), true);
  assert.equal(canConnect(g, 'app', 'variable:1'), false);
  assert.equal(canConnect(g, 'variable:1', 'variable:2'), false);
  assert.equal(canConnect(g, 'missing:OLD', 'app'), false);
  assert.equal(canConnect({ nodes: g.nodes }, 'app', 'variable:1'), true, 'other canvases link any two cards');
});

test('variables view: filter resources by project and environment, keep every variable', () => {
  const shop = { uuid: 'p1', name: 'Shop' };
  const blog = { uuid: 'p2', name: 'Blog' };
  const g = {
    layout: 'connect',
    nodes: [
      node('variable:1', 'variable', 'sources'),
      node('a', 'application', 'resources', { project: shop, environment: { uuid: 'e1', name: 'production' } }),
      node('b', 'application', 'resources', { project: shop, environment: { uuid: 'e2', name: 'staging' } }),
      node('d', 'application', 'resources', { project: shop, environment: { uuid: 'e4', name: 'deprecated' } }),
      node('c', 'application', 'resources', { project: blog, environment: { uuid: 'e3', name: 'production' } }),
    ],
    links: [{ id: 'l1', source: 'variable:1', target: 'a' }, { id: 'l2', source: 'variable:1', target: 'c' }],
  };

  assert.deepEqual(filterOptions(g).map(p => [p.name, p.environments.map(e => e.name)]), [['Blog', ['production']], ['Shop', ['production', 'staging', 'deprecated']]]);
  assert.equal(filterGraph(g, {}), g);
  const shopOnly = filterGraph(g, { project: 'p1' });
  assert.deepEqual(shopOnly.nodes.map(n => n.id), ['variable:1', 'a', 'b', 'd']);
  assert.deepEqual(shopOnly.links.map(l => l.id), ['l1']);
  assert.deepEqual(filterGraph(g, { environment: 'e2' }).nodes.map(n => n.id), ['variable:1', 'b']);
  assert.deepEqual(filterGraph(g, { environment: 'e2' }).links, []);
});

test('variables view: resources that use a variable get their own zone, in one column', () => {
  const ctx = { context: 'Shop / prod' };
  const g = groupByUse({
    layout: 'connect',
    zones: [{ id: 'sources', icon: 'variable' }, { id: 'connected', icon: 'link' }, { id: 'resources', icon: 'service' }],
    nodes: [
      node('variable:1', 'variable', 'sources'),
      node('app', 'application', 'resources', ctx),
      node('db', 'database', 'resources', ctx),
      node('idle', 'application', 'resources', ctx),
    ],
    links: [{ id: 'l1', source: 'variable:1', target: 'app' }, { id: 'l2', source: 'variable:1', target: 'db' }],
  });
  assert.deepEqual(g.nodes.map(n => n.zone), ['sources', 'connected', 'connected', 'resources']);

  const p = layoutGraph(g);
  assert.equal(p.app.x, p.db.x, 'connected cards share one column, so no link runs behind a card');
  assert.ok(p.db.y > p.app.y);
  const boxes = zoneBoxes(g, p);
  const connected = boxes.find(b => b.id === 'connected');
  const others = boxes.find(b => b.id === 'resources');
  assert.ok(connected.y + connected.height < others.y, 'other resources sit below the connected zone');
});

test('readView restores a saved zoom and pan, and ignores bad data', () => {
  const store = values => ({ getItem: key => values[key] ?? null });
  assert.deepEqual(readView('k', store({ k: '{"x":-120.5,"y":40,"z":0.6}' })), { x: -120.5, y: 40, z: 0.6 });
  assert.equal(readView('k', store({})), null);
  assert.equal(readView('k', store({ k: 'not json' })), null);
  assert.equal(readView('k', store({ k: '{"x":1,"y":2}' })), null);
  assert.equal(readView('k', store({ k: '{"x":1,"y":2,"z":9}' })), null);
});

test('server view: the unmanaged containers group comes first in its zone', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [
      node('a', 'application', 'z1', { context: 'Alpha / prod' }),
      node('container:1', 'container', 'z1', { context: 'Unmanaged containers' }),
    ],
    links: [],
    positions: {},
  };
  const positions = layoutServerView(g);
  assert.ok(positions['container:1'].x < positions.a.x);
});

const apart = (a, b) => a.x + a.width <= b.x || b.x + b.width <= a.x || a.y + a.height <= b.y || b.y + b.height <= a.y;
// Drag card `id` to the top-left of `onto`, as the canvas does: stop at the nearest free spot.
const dragOnto = (g, positions, id, onto) => {
  const { others, fits } = dragRules(g, positions, {}, id);
  const card = g.nodes.find(n => n.id === id);
  const start = { ...positions[id], w: CARD_WIDTH, h: estimateNodeHeight(card) };
  assert.deepEqual(freeSpot(start, others, undefined, fits), start, 'the laid out card is already free, so it does not jump');
  const spot = freeSpot({ ...start, ...positions[onto] }, others, undefined, fits);
  return { ...positions, [id]: { x: spot.x, y: spot.y } };
};

test('a dragged card stops before it covers a card or another zone', () => {
  const g = graph([node('a', 'application', 's1'), node('b', 'database', 's1'), node('c', 'application', 's2')]);
  const before = layoutGraph(g);
  for (const [id, onto] of [['a', 'b'], ['a', 'c'], ['c', 'a']]) {
    const positions = dragOnto(g, before, id, onto);
    const rects = Object.fromEntries(g.nodes.map(n => [n.id, ({ ...positions[n.id], width: CARD_WIDTH, height: estimateNodeHeight(n) })]));
    for (const n of g.nodes) if (n.id !== id) assert.ok(apart(rects[id], rects[n.id]), `${id} does not cover ${n.id}`);
    const [z1, z2] = zoneBoxes(g, positions);
    assert.ok(apart(z1, z2), 'the zones do not overlap');
  }
});

test('server view: a dragged card stops before its group covers another group', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [srv('a', 'application', 'Shop / prod'), srv('b', 'database', 'Shop / prod'), srv('c', 'application', 'Blog / prod')],
    links: [],
    positions: {},
  };
  const positions = dragOnto(g, layoutGraph(g), 'a', 'c');
  const [f1, f2] = groupFrames(g.nodes, positions);
  assert.ok(apart(f1, f2), 'the project / environment frames do not overlap');
});

test('server view: a group dropped as close to another group as a drag allows stays there after a reload', () => {
  const nodes = [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'application', 'z1', 'staging')];
  const g = { layout: 'server', zones: [{ id: 'z1', name: 'web' }], nodes, links: [] };
  const groups = {};
  const before = layoutServerView(g, groups);
  const [prod, staging] = groupFrames(nodes, before);
  // Drag the staging group to the left of the prod group: it stops at the gap the drag keeps.
  const box = { x: staging.x, y: staging.y, w: staging.width, h: staging.height };
  const others = [{ x: prod.x, y: prod.y, w: prod.width, h: prod.height }];
  const { inner } = groups[groupKey([nodes[1]], 'z1')];
  const spot = freeSpot({ ...box, x: prod.x + 40 }, others, GROUP_GAP, b => b.x >= inner.x && b.y >= inner.y);
  const dropped = { ...before, b: { x: before.b.x + spot.x - box.x, y: before.b.y + spot.y - box.y } };
  const shift = id => groups[groupKey([nodes.find(n => n.id === id)], 'z1')].shift;
  const saved = Object.fromEntries(nodes.map(n => [n.id, { x: dropped[n.id].x - shift(n.id).x, y: dropped[n.id].y - shift(n.id).y }]));
  const after = layoutServerView({ ...g, nodes: nodes.map(n => ({ ...n, saved: saved[n.id] })) });
  assert.deepEqual(after, dropped);
});

test('server view: a dragged card keeps its group as far from another group as the layout does', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [srv('a', 'application', 'Shop / prod'), srv('c', 'application', 'Blog / prod')],
    links: [],
    positions: {},
  };
  const before = layoutServerView(g);
  const { others, fits } = dragRules(g, before, {}, 'a');
  const start = { ...before.a, w: CARD_WIDTH, h: estimateNodeHeight(g.nodes[0]) };
  // The layout puts the groups GROUP_GAP apart: a card cannot move its group closer to the other one.
  assert.equal(freeSpot({ ...start, x: start.x - 10 }, others, undefined, fits), null);
});

test('server view: cards still move when the measured cards put their group too close to another group', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'web' }],
    nodes: [srv('a', 'application', 'Shop / prod'), srv('c', 'application', 'Blog / prod')],
    links: [],
    positions: {},
  };
  const before = layoutServerView(g);
  // The layout uses estimated sizes; the real Blog card is wider, so the groups are less than GROUP_GAP apart.
  const sizes = { c: { width: CARD_WIDTH + 20, height: estimateNodeHeight(g.nodes[1]) } };
  const { fits } = dragRules(g, before, sizes, 'a');
  const start = { ...before.a, w: CARD_WIDTH, h: estimateNodeHeight(g.nodes[0]) };
  assert.ok(fits(start), 'the card can stay where it is');
  assert.ok(fits({ ...start, x: start.x + 50 }), 'the card can move away from the other group');
  assert.ok(!fits({ ...start, x: start.x - 10 }), 'the card cannot move its group closer to the other group');
});

test('groupKey: a group with only an empty-environment placeholder has no key, so it cannot be moved', () => {
  const environment = { uuid: 'env', name: 'production' };
  assert.equal(groupKey([{ id: 'empty:env', type: 'empty', environment }], 'empty'), null);
  assert.equal(groupKey([{ id: 'a', type: 'application', environment }], 'srv'), 'env:srv');
});

test('layoutServerView: an empty environment sits right of the other groups of its project, not below them', () => {
  const shop = { uuid: 'shop', name: 'Shop' };
  const node = (id, context, extra = {}) => ({ id, type: 'application', zone: 'srv', context, project: shop, environment: { uuid: context }, ...extra });
  const graph = {
    layout: 'server',
    zones: [{ id: 'srv', name: 'srv' }],
    nodes: [
      node('a', 'Shop / staging', { saved: { x: 0, y: 0 } }),
      node('empty:prod', 'Shop / production', { type: 'empty' }),
    ],
    links: [],
  };
  const out = layoutServerView(graph);
  assert.equal(out['empty:prod'].y, out.a.y);
  assert.ok(out['empty:prod'].x >= out.a.x + CARD_WIDTH);
});

test('variables view: saved environment canvas positions do not push resources away from the variables', () => {
  const g = {
    layout: 'connect',
    zones: [{ id: 'sources', name: 'Server variables', icon: 'variable' }, { id: 'resources', name: 'Other resources', icon: 'service' }],
    nodes: [
      node('variable:1', 'variable', 'sources', { name: 'COOLIFY_SERVER_NAME' }),
      node('app', 'application', 'resources', { context: 'Shop / prod', environment: { uuid: 'e1' }, saved: { x: 900, y: 1200 } }),
    ],
    links: [],
    positions: {},
  };
  const [variables, resources] = zoneBoxes(g, layoutGraph(g));

  assert.equal(resources.y, variables.y, 'both zones start at the top');
  assert.ok(resources.x - (variables.x + variables.width) <= ZONE_GAP * 2, 'resources sit next to the variables');
});

test('projects canvas: a zone with a saved place sits there, also above the other zones', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'Blog' }, { id: 'z2', name: 'Shop', position: { x: 900, y: -400 } }],
    nodes: [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'application', 'z2', 'prod')],
    links: [],
  };
  const groups = {};
  const zones = {};
  const positions = layoutServerView(g, groups, { zones });
  assert.deepEqual(zones, { z1: { x: 0, y: 0 }, z2: { x: 900, y: -400 } });
  const box = zoneBoxes(g, positions).find(z => z.id === 'z2');
  assert.deepEqual({ x: box.x + ZONE_PADDING, y: box.y }, { x: 900, y: -400 });
  // The group of the moved zone keeps its environment canvas mapping relative to the zone.
  assert.equal(groups['prod:z2'].inner.y, -400 + ZONE_HEADER + ZONE_PADDING);
});

test('projects canvas: zones without a saved place stack below a saved zone that is in their way', () => {
  const g = {
    layout: 'server',
    zones: [{ id: 'z1', name: 'Blog', position: { x: 0, y: 0 } }, { id: 'z2', name: 'Shop' }, { id: 'z3', name: 'Wiki' }],
    nodes: [envNode('a', 'application', 'z1', 'prod'), envNode('b', 'application', 'z2', 'prod'), envNode('c', 'application', 'z3', 'prod')],
    links: [],
  };
  const positions = layoutServerView(g);
  const boxes = Object.fromEntries(zoneBoxes(g, positions).map(z => [z.id, z]));
  assert.ok(boxes.z2.y >= boxes.z1.y + boxes.z1.height + ZONE_GAP);
  assert.ok(boxes.z3.y >= boxes.z2.y + boxes.z2.height + ZONE_GAP);
});
