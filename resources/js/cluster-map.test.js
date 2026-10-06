import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { distanceToRect, samplePathCommands } from './canvas-geometry.js';
import {
    MAP,
    TRAFFIC_STORAGE_KEY,
    alignLabels,
    assignLanes,
    barycenterOrder,
    buildMapEdges,
    clusterMap,
    countCrossings,
    isotonicRegression,
    labelColumnWidth,
    layoutClusterFrame,
    layoutClusterMap,
    maximumOverlap,
    polylineMidpoint,
    roundedPath,
    serverCardHeight,
    simplifyPolyline,
} from './cluster-map.js';

function app(uuid, statusType = 'success') {
    return { uuid, name: uuid, href: `/apps/${uuid}`, status: 'Running', statusType };
}

function server(uuid, apps = []) {
    return { uuid, name: uuid, href: `/servers/${uuid}`, status: 'Ready', statusType: 'success', ingress: 'Off', ingressType: 'neutral', apps };
}

function cluster(uuid, servers, { rules = [], ingress = [] } = {}) {
    return { uuid, name: uuid, href: `/cluster/${uuid}`, firewallHref: `/cluster/${uuid}/firewall`, status: 'Active', statusType: 'success', servers, rules, ingress };
}

const mapData = {
    clusters: [
        cluster('mesh', [
            server('alpha', [app('api'), app('web')]),
            server('beta', [app('api'), app('db')]),
            server('gamma', []),
        ], {
            rules: [
                { uuid: 'r1', sourceType: 'workload', sourceUuid: 'web', destinationUuid: 'api', protocol: 'tcp', port: 8080 },
                { uuid: 'r2', sourceType: 'workload', sourceUuid: 'api', destinationUuid: 'db', protocol: 'tcp', port: 5432 },
                { uuid: 'r3', sourceType: 'workload', sourceUuid: 'db', destinationUuid: 'api', protocol: 'icmp', port: 0 },
                { uuid: 'r4', sourceType: 'node', sourceUuid: 'gamma', destinationUuid: 'web', protocol: 'udp', port: 53 },
                { uuid: 'r5', sourceType: 'workload', sourceUuid: 'gone', destinationUuid: 'api', protocol: 'tcp', port: 1 },
            ],
            ingress: [
                { appUuid: 'web', domains: ['web.example.com', 'www.example.com'], port: 80 },
                { appUuid: 'api', domains: ['api.example.com'], port: 3000 },
            ],
        }),
        cluster('empty', []),
        cluster('small', [server('solo', [app('solo-app')])]),
    ],
    dockerServers: [
        { uuid: 'docker-a', name: 'localhost', href: '/server/docker-a', status: 'Ready', statusType: 'success' },
        { uuid: 'docker-b', name: 'build', href: '/server/docker-b', status: 'Not ready', statusType: 'warning' },
        { uuid: 'docker-c', name: 'edge', href: '/server/docker-c', status: 'Ready', statusType: 'success' },
    ],
    dockerServersTotal: 3,
};

function overlaps(first, second) {
    return first.x < second.x + second.width
        && second.x < first.x + first.width
        && first.y < second.y + second.height
        && second.y < first.y + first.height;
}

function contains(outer, inner) {
    return inner.x >= outer.x
        && inner.y >= outer.y
        && inner.x + inner.width <= outer.x + outer.width
        && inner.y + inner.height <= outer.y + outer.height;
}

/** A line end sits just outside its card, level with the given row. */
function besideCard(card, row, x, y) {
    const epsilon = 0.001;
    const outside = Math.abs(x - (card.x - MAP.portGap)) < epsilon || Math.abs(x - (card.x + card.width + MAP.portGap)) < epsilon;

    return outside && y >= row.y - epsilon && y <= row.y + row.height + epsilon;
}

function onBoundary(rect, x, y) {
    const epsilon = 0.001;
    const insideX = x >= rect.x - epsilon && x <= rect.x + rect.width + epsilon;
    const insideY = y >= rect.y - epsilon && y <= rect.y + rect.height + epsilon;
    const onVertical = Math.abs(x - rect.x) < epsilon || Math.abs(x - rect.x - rect.width) < epsilon;
    const onHorizontal = Math.abs(y - rect.y) < epsilon || Math.abs(y - rect.y - rect.height) < epsilon;

    return insideX && insideY && (onVertical || onHorizontal);
}

for (const [width, showTraffic] of [[1600, false], [1600, true], [900, true], [320, false]]) {
    test(`keeps frames apart and cards inside their frame at ${width}px${showTraffic ? ' with traffic' : ''}`, () => {
        const layout = layoutClusterMap(mapData, { availableWidth: width, showTraffic });

        assert.equal(layout.frames.length, 4);
        for (const [index, frame] of layout.frames.entries()) {
            for (const other of layout.frames.slice(index + 1)) {
                assert.equal(overlaps(frame, other), false, `${frame.id} overlaps ${other.id}`);
            }
            assert.ok(frame.x + frame.width + MAP.margin <= layout.size.width);
            assert.ok(frame.y + frame.height + MAP.margin <= layout.size.height);
        }

        for (const data of mapData.clusters) {
            const frame = layout.frames.find((candidate) => candidate.id === `cluster:${data.uuid}`);
            const cards = data.servers.map((item) => layout.servers[`${data.uuid}/${item.uuid}`]);
            for (const [index, card] of cards.entries()) {
                assert.ok(contains(frame, card), `${data.servers[index].uuid} is outside ${data.uuid}`);
                assert.ok(card.y >= frame.y + MAP.frameHeaderHeight, 'cards start below the frame header');
                for (const other of cards.slice(index + 1)) {
                    assert.equal(overlaps(card, other), false);
                }
                for (const item of data.servers[index].apps) {
                    assert.ok(contains(card, layout.apps[`${data.uuid}/${data.servers[index].uuid}/${item.uuid}`]));
                }
            }
        }

        const dockerFrame = layout.frames.find((frame) => frame.kind === 'docker');
        for (const item of mapData.dockerServers) {
            assert.ok(contains(dockerFrame, layout.dockerServers[item.uuid]));
        }
    });
}

test('wraps frames to the available width', () => {
    const wide = layoutClusterMap(mapData, { availableWidth: 4000 });
    const narrow = layoutClusterMap(mapData, { availableWidth: 400 });

    assert.equal(new Set(wide.frames.map((frame) => frame.y)).size, 1);
    assert.equal(new Set(narrow.frames.map((frame) => frame.x)).size, 1);
    assert.ok(narrow.size.width <= 400 + MAP.margin * 2);
});

test('sizes server cards to their application rows', () => {
    const layout = layoutClusterMap(mapData);

    assert.equal(layout.servers['mesh/alpha'].height, serverCardHeight(2));
    assert.equal(layout.servers['mesh/gamma'].height, serverCardHeight(0));
    assert.equal(serverCardHeight(0), serverCardHeight(1));
});

test('records every placement of an application that runs on several servers', () => {
    const layout = layoutClusterMap(mapData);

    assert.deepEqual(layout.placements.mesh.api.map((placement) => placement.serverUuid), ['alpha', 'beta']);
    assert.deepEqual(layout.placements.mesh.web.map((placement) => placement.serverUuid), ['alpha']);
});

test('reserves the Internet gutter only for clusters with public domains while traffic is shown', () => {
    const hidden = layoutClusterMap(mapData, { showTraffic: false });
    const shown = layoutClusterMap(mapData, { showTraffic: true });

    assert.deepEqual(Object.keys(hidden.internet), []);
    assert.deepEqual(Object.keys(shown.internet), ['mesh']);
    const frame = shown.frames.find((candidate) => candidate.id === 'cluster:mesh');
    const firstCard = shown.servers['mesh/alpha'];
    assert.ok(contains(frame, shown.internet.mesh));
    assert.ok(shown.internet.mesh.x + shown.internet.mesh.width + MAP.fanWidth + MAP.labelWidth + MAP.publicGap <= firstCard.x);
});

test('builds traffic lines that end on the application rows', () => {
    const layout = layoutClusterMap(mapData, { availableWidth: 1600, showTraffic: true });
    const edges = buildMapEdges(mapData, layout);
    const firewall = edges.filter((edge) => edge.kind === 'firewall');

    assert.deepEqual(firewall.map((edge) => edge.endpoints), [
        ['workload:web', 'workload:api'],
        ['workload:api', 'workload:db'],
        ['node:gamma', 'workload:web'],
    ]);

    // web runs on alpha only, and api on alpha and beta: the line uses api on alpha.
    const webToApi = firewall[0];
    assert.ok(besideCard(layout.servers['mesh/alpha'], layout.apps['mesh/alpha/web'], webToApi.x1, webToApi.y1));
    assert.ok(besideCard(layout.servers['mesh/alpha'], layout.apps['mesh/alpha/api'], webToApi.x2, webToApi.y2));

    // db runs on beta only, so the two-way api <-> db line uses api on beta.
    const apiToDb = firewall[1];
    assert.equal(apiToDb.bidirectional, true);
    assert.deepEqual(apiToDb.directions.map((direction) => direction.rules.map((rule) => rule.text)), [['TCP / 5432'], ['ICMP']]);
    assert.ok(besideCard(layout.servers['mesh/beta'], layout.apps['mesh/beta/api'], apiToDb.x1, apiToDb.y1));
    assert.ok(besideCard(layout.servers['mesh/beta'], layout.apps['mesh/beta/db'], apiToDb.x2, apiToDb.y2));

    // A server rule starts at the header of the server card.
    const serverRule = firewall[2];
    const gamma = layout.servers['mesh/gamma'];
    assert.ok(besideCard(gamma, { ...gamma, height: MAP.serverHeaderHeight }, serverRule.x1, serverRule.y1));
    assert.ok(besideCard(layout.servers['mesh/alpha'], layout.apps['mesh/alpha/web'], serverRule.x2, serverRule.y2));
    assert.equal(serverRule.firewallHref, '/cluster/mesh/firewall');
    assert.deepEqual(serverRule.directions, [{ from: 'gamma', to: 'web', rules: [{ uuid: 'r4', text: 'UDP / 53' }] }]);
});

test('draws public domains from the Internet marker with readable labels', () => {
    const layout = layoutClusterMap(mapData, { availableWidth: 1600, showTraffic: true });
    const ingress = buildMapEdges(mapData, layout).filter((edge) => edge.kind === 'ingress');

    // Lanes follow the order of the application rows so that lines do not cross.
    assert.deepEqual(ingress.map((edge) => edge.endpoints[1]), ['workload:api', 'workload:web']);
    for (const edge of ingress) {
        assert.ok(onBoundary(layout.internet.mesh, edge.x1, edge.y1));
        const appUuid = edge.endpoints[1].replace('workload:', '');
        const placements = layout.placements.mesh[appUuid];
        assert.ok(placements.some((placement) => besideCard(layout.servers[`mesh/${placement.serverUuid}`], placement.rect, edge.x2, edge.y2)));
    }
    assert.equal(ingress[1].label.text, 'web.example.com +1');
    assert.deepEqual(ingress[1].domains, ['web.example.com', 'www.example.com']);
    assert.equal(ingress[0].directions[0].rules[0].text, 'HTTP and HTTPS to port 3000');
    for (const edge of ingress) {
        // The line leaves the label at its right middle, level with the application row.
        const row = layout.apps[`mesh/alpha/${edge.endpoints[1].replace('workload:', '')}`];
        assert.equal(edge.label.y + edge.label.height / 2, row.y + row.height / 2);
        assert.equal(edge.label.width, labelColumnWidth(['web.example.com +1', 'api.example.com']));
        assert.ok(edge.path.includes(`M ${edge.label.x + edge.label.width} ${edge.label.y + edge.label.height / 2} L`));
    }
    const [first, second] = [...ingress].sort((a, b) => a.label.y - b.label.y);
    assert.ok(second.label.y - first.label.y >= MAP.labelSpacing);
});

test('sizes the label column to the longest domain within bounds', () => {
    assert.equal(labelColumnWidth([]), MAP.labelWidth);
    assert.equal(labelColumnWidth(['a.io']), MAP.labelWidth);
    assert.equal(labelColumnWidth(['whoami.100.110.234.18.sslip.io']), Math.round(30 * MAP.labelCharacterWidth + MAP.labelChrome));
    assert.equal(labelColumnWidth(['x'.repeat(200)]), MAP.maximumLabelWidth);
});

test('rounds the corners of routed lines', () => {
    assert.equal(roundedPath([{ x: 0, y: 0 }, { x: 20, y: 0 }, { x: 20, y: 20 }], 6), 'M 0 0 L 14 0 Q 20 0 20 6 L 20 20');
    assert.deepEqual(polylineMidpoint([{ x: 0, y: 0 }, { x: 20, y: 0 }, { x: 20, y: 20 }]), { x: 20, y: 0 });
    // A level arc collapses to a straight line instead of zero-length corners.
    assert.deepEqual(simplifyPolyline([{ x: 0, y: 5 }, { x: 30, y: 5 }, { x: 30, y: 5 }, { x: -40, y: 5 }]), [{ x: 0, y: 5 }, { x: -40, y: 5 }]);
});

test('does not draw traffic for clusters without rules or domains', () => {
    const layout = layoutClusterMap(mapData, { showTraffic: true });
    const edges = buildMapEdges(mapData, layout);

    assert.equal(edges.some((edge) => edge.clusterUuid !== 'mesh'), false);
});

test('remembers the traffic layer and redraws it when it is toggled', () => {
    const storage = new Map();
    globalThis.localStorage = {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
    };
    const watchers = {};
    const viewport = { clientWidth: 1400, clientHeight: 600, scrollLeft: 0, scrollTop: 0, scrollTo() {} };
    const map = clusterMap();
    map.$refs = { viewport };
    map.$wire = { map: mapData };
    map.$watch = (expression, callback) => {
        watchers[expression] = callback;
    };

    map.init();
    assert.equal(map.showTraffic, false);
    assert.deepEqual(map.edges, []);

    map.toggleTraffic();
    assert.equal(storage.get(TRAFFIC_STORAGE_KEY), 'on');
    assert.ok(map.edges.length > 0);

    map.pinEdge(map.edges[0].id);
    watchers['$wire.map']({ ...mapData, clusters: [] });
    assert.deepEqual(map.edges, []);
    assert.equal(map.pinnedEdgeId, null);
    delete globalThis.localStorage;
});

test('renders traffic loops outside the SVG namespace', () => {
    const template = readFileSync(new URL('../views/livewire/dashboard/cluster-map.blade.php', import.meta.url), 'utf8');

    assert.match(template, /<div wire:ignore x-data="clusterMap"/);
    assert.match(template, /<template x-for="edge in edges"[\s\S]*?<svg/);
    assert.doesNotMatch(template, /<svg[^>]*>\s*<template x-for/);
});

/*
 * Layered drawing: ordering, label alignment, lanes, and edge geometry.
 */

function ingressFor(appUuid) {
    return { appUuid, domains: [`${appUuid}.example.com`], port: 80 };
}

// The development cluster: nginx runs on server A, hello and whoami on server B.
const devCluster = cluster('dev', [
    server('server-a', [app('nginx')]),
    server('server-b', [app('hello'), app('whoami')]),
], { ingress: [ingressFor('hello'), ingressFor('nginx'), ingressFor('whoami')] });

const devRules = [
    { uuid: 'f1', sourceType: 'node', sourceUuid: 'server-a', destinationUuid: 'hello', protocol: 'tcp', port: 80 },
    { uuid: 'f2', sourceType: 'workload', sourceUuid: 'nginx', destinationUuid: 'whoami', protocol: 'tcp', port: 8080 },
    { uuid: 'f3', sourceType: 'workload', sourceUuid: 'hello', destinationUuid: 'nginx', protocol: 'tcp', port: 80 },
    { uuid: 'f4', sourceType: 'workload', sourceUuid: 'nginx', destinationUuid: 'hello', protocol: 'tcp', port: 3000 },
];

/** Crossings between public lines, measured on the drawing: label order against row order. */
function publicCrossings(edges) {
    const ingress = edges.filter((edge) => edge.kind === 'ingress');
    let crossings = 0;
    for (const [index, first] of ingress.entries()) {
        for (const second of ingress.slice(index + 1)) {
            if ((first.label.y - second.label.y) * (first.y2 - second.y2) < 0) {
                crossings++;
            }
        }
    }

    return crossings;
}

test('isotonic regression pools adjacent violators', () => {
    assert.deepEqual(isotonicRegression([1, 3, 2, 4]), [1, 2.5, 2.5, 4]);
    assert.deepEqual(isotonicRegression([5, 4, 3]), [4, 4, 4]);
    assert.deepEqual(isotonicRegression([1, 2, 3]), [1, 2, 3]);
    assert.deepEqual(isotonicRegression([3, 1], [3, 1]), [2.5, 2.5]);
    assert.deepEqual(isotonicRegression([]), []);
});

test('aligns labels with their targets while keeping the minimum gap', () => {
    // Feasible targets are kept exactly.
    assert.deepEqual(alignLabels([0, 30, 60], 28), [0, 30, 60]);
    // Crowded targets spread around their mean, the least-squares optimum.
    assert.deepEqual(alignLabels([0, 10, 20], 28), [-18, 10, 38]);
    // Only the crowded pair moves, symmetrically.
    assert.deepEqual(alignLabels([0, 100, 110, 300], 28), [0, 91, 119, 300]);
    // A lower bound clips the first labels without breaking the gap.
    const bounded = alignLabels([0, 10, 20], 28, { minimum: 0 });
    assert.deepEqual(bounded, [0, 28, 56]);
    for (const result of [alignLabels([5, 3, 90, 91, 92], 20), bounded]) {
        result.slice(1).forEach((value, index) => assert.ok(value - result[index] >= 20 - 1e-9));
    }
});

test('orders layers with the barycenter heuristic', () => {
    const layers = [['a', 'b', 'c'], ['z', 'y', 'x']];
    const edges = [{ from: 'a', to: 'x' }, { from: 'b', to: 'y' }, { from: 'c', to: 'z' }];

    assert.equal(countCrossings(layers, edges), 3);
    const ordered = barycenterOrder(layers, edges);
    assert.equal(ordered.crossings, 0);
    assert.equal(countCrossings(ordered.layers, edges), 0);
    // A crossing-free order is kept as it is.
    assert.deepEqual(barycenterOrder([['a', 'b'], ['x', 'y']], [{ from: 'a', to: 'x' }, { from: 'b', to: 'y' }]).layers, [['a', 'b'], ['x', 'y']]);
    // Ports order the ends of one node: the label of the lower row goes below.
    const ports = barycenterOrder([['top', 'bottom'], ['card']], [{ from: 'top', to: 'card', toPort: 0.75 }, { from: 'bottom', to: 'card', toPort: 0.25 }]);
    assert.deepEqual(ports.layers[0], ['bottom', 'top']);
});

test('draws the development cluster without crossings and with level public lines', () => {
    for (const rules of [[], devRules]) {
        const data = { clusters: [{ ...devCluster, rules }], dockerServers: [] };
        const frame = layoutClusterFrame(data.clusters[0], { showTraffic: true });
        const layout = layoutClusterMap(data, { availableWidth: 1400, showTraffic: true });
        const ingress = layout.edges.filter((edge) => edge.kind === 'ingress');

        assert.equal(frame.mode, 'layered');
        assert.equal(frame.crossings, 0);
        assert.equal(publicCrossings(layout.edges), 0);
        assert.deepEqual(ingress.map((edge) => edge.endpoints[1]), ['workload:nginx', 'workload:hello', 'workload:whoami']);
        for (const edge of ingress) {
            const labelY = edge.label.y + edge.label.height / 2;
            assert.ok(Math.abs(labelY - edge.y2) < 1, `${edge.id} is level`);
            assert.match(edge.path, new RegExp(`M ${edge.label.x + edge.label.width} [\\d.]+ L [\\d.]+ [\\d.]+$`), 'a straight line after the label');
        }

        // The servers form one column, and the Internet node is centred on its labels.
        const a = layout.servers['dev/server-a'];
        const b = layout.servers['dev/server-b'];
        assert.equal(a.x, b.x);
        assert.ok(a.y + a.height + MAP.stackGap <= b.y);
        const internet = layout.internet.dev;
        const centers = ingress.map((edge) => edge.label.y + edge.label.height / 2);
        assert.equal(internet.y + internet.height / 2, (Math.min(...centers) + Math.max(...centers)) / 2);
    }
});

test('reorders labels that arrive in the wrong order', () => {
    const shuffled = cluster('shuffled', [
        server('one', [app('a1'), app('a2')]),
        server('two', [app('b1')]),
        server('three', [app('c1'), app('c2')]),
    ], { ingress: ['c2', 'b1', 'a2', 'c1', 'a1'].map(ingressFor) });
    const layout = layoutClusterMap({ clusters: [shuffled], dockerServers: [] }, { showTraffic: true });
    const frame = layoutClusterFrame(shuffled, { showTraffic: true });

    assert.equal(frame.crossings, 0);
    assert.equal(publicCrossings(layout.edges), 0);
    for (const edge of layout.edges) {
        assert.ok(Math.abs(edge.label.y + edge.label.height / 2 - edge.y2) < 1);
    }
});

test('assigns lanes to overlapping intervals', () => {
    const cases = [
        [{ start: 0, end: 100 }, { start: 10, end: 20 }, { start: 30, end: 40 }],
        [{ start: 0, end: 50 }, { start: 40, end: 90 }, { start: 80, end: 120 }, { start: 200, end: 210 }],
        [{ start: 0, end: 100 }, { start: 10, end: 90 }, { start: 20, end: 80 }],
        [{ start: 0, end: 10 }, { start: 10, end: 20 }],
        [],
    ];
    for (const intervals of cases) {
        const { lanes, count } = assignLanes(intervals);
        assert.equal(count, maximumOverlap(intervals));
        intervals.forEach((first, index) => intervals.slice(index + 1).forEach((second, offset) => {
            const overlap = first.start <= second.end && second.start <= first.end;
            assert.ok(!overlap || lanes[index] !== lanes[index + 1 + offset], 'overlapping intervals share a lane');
        }));
    }
    // Nested intervals take inner lanes, so their arcs do not cross.
    assert.deepEqual(assignLanes(cases[0]).lanes, [1, 0, 0]);
    assert.deepEqual(assignLanes(cases[2]).lanes, [2, 1, 0]);

    // Random intervals never share a lane while they overlap.
    let seed = 7;
    const random = () => {
        seed = (seed * 16807) % 2147483647;

        return seed / 2147483647;
    };
    for (let round = 0; round < 50; round++) {
        const intervals = Array.from({ length: 8 }, () => {
            const start = Math.round(random() * 300);

            return { start, end: start + Math.round(random() * 150) };
        });
        const { lanes, count } = assignLanes(intervals);
        assert.ok(count >= maximumOverlap(intervals));
        intervals.forEach((first, index) => intervals.forEach((second, other) => {
            if (index !== other && lanes[index] === lanes[other]) {
                assert.ok(first.end < second.start || second.end < first.start);
            }
        }));
    }
});

/** Every sampled point of every line keeps clear of the cards it does not connect. */
function assertLinesKeepClear(data, layout) {
    for (const edge of layout.edges) {
        const points = samplePathCommands(edge.commands, 64);
        assert.ok(points.length >= 50);
        const frame = layout.frames.find((candidate) => candidate.clusterUuid === edge.clusterUuid);
        const clusterData = data.clusters.find((candidate) => candidate.uuid === edge.clusterUuid);
        const ownCards = new Set();
        for (const endpoint of edge.endpoints) {
            const [type, uuid] = endpoint.split(':');
            for (const item of clusterData.servers) {
                if ((type === 'node' && item.uuid === uuid) || (type === 'workload' && item.apps.some((candidate) => candidate.uuid === uuid))) {
                    ownCards.add(`${clusterData.uuid}/${item.uuid}`);
                }
            }
        }
        for (const point of points) {
            assert.ok(point.x >= frame.x + MAP.clearance && point.x <= frame.x + frame.width - MAP.clearance, `${edge.id} keeps off the frame sides`);
            assert.ok(point.y >= frame.y + MAP.frameHeaderHeight && point.y <= frame.y + frame.height - MAP.clearance, `${edge.id} keeps off the frame bottom`);
            for (const item of clusterData.servers) {
                const key = `${clusterData.uuid}/${item.uuid}`;
                if (!ownCards.has(key)) {
                    assert.ok(distanceToRect(point, layout.servers[key]) >= MAP.clearance, `${edge.id} passes ${key} at ${point.x},${point.y}`);
                }
            }
        }
        // The arrow points into the target: the line ends level and towards it.
        const [beforeLast, last] = points.slice(-2);
        assert.ok(Math.abs(beforeLast.y - last.y) < 0.5, `${edge.id} ends level`);
    }
}

test('keeps every line clear of other cards and of the frame border', () => {
    const data = { clusters: [{ ...devCluster, rules: devRules }, mapData.clusters[0]], dockerServers: [] };
    for (const width of [1600, 900]) {
        const layout = layoutClusterMap(data, { availableWidth: width, showTraffic: true });
        assert.ok(layout.edges.length >= 9);
        assertLinesKeepClear(data, layout);
    }
});

test('routes firewall rules as arcs in lanes right of the server column', () => {
    const data = { clusters: [{ ...devCluster, rules: devRules }], dockerServers: [] };
    const layout = layoutClusterMap(data, { showTraffic: true });
    const firewall = layout.edges.filter((edge) => edge.kind === 'firewall');
    const columnRight = layout.servers['dev/server-a'].x + MAP.serverWidth;
    const laneOf = (edge) => Math.max(...edge.points.map((point) => point.x));

    assert.deepEqual(firewall.map((edge) => [edge.endpoints, edge.bidirectional]), [
        [['node:server-a', 'workload:hello'], false],
        [['workload:nginx', 'workload:whoami'], false],
        [['workload:hello', 'workload:nginx'], true],
    ]);
    const lanes = firewall.map(laneOf);
    for (const lane of lanes) {
        assert.equal((lane - columnRight - MAP.laneBase) % MAP.laneGap, 0);
    }
    // hello <-> nginx is nested in both other arcs, so it takes the innermost lane.
    assert.equal(lanes[2], columnRight + MAP.laneBase);
    assert.equal(new Set(lanes).size, 3);

    // Ends that share a row get their own heights.
    const ends = firewall.flatMap((edge) => [{ x: edge.x1, y: edge.y1 }, { x: edge.x2, y: edge.y2 }]);
    ends.forEach((first, index) => ends.slice(index + 1).forEach((second) => {
        assert.ok(first.x !== second.x || Math.abs(first.y - second.y) >= 4);
    }));
    // The frame grows with the channel.
    const frame = layout.frames[0];
    assert.equal(frame.x + frame.width, Math.max(...lanes) + MAP.laneBase);
});

test('uses a second server column with virtual nodes for a large cluster', () => {
    const large = cluster('large', [
        server('s1', [app('internal-1')]),
        server('s2', [app('internal-2')]),
        server('s3', [app('internal-3')]),
        server('s4', [app('p1')]),
        server('s5', [app('p2'), app('p3')]),
        server('s6', [app('p4')]),
    ], {
        ingress: ['p1', 'p2', 'p3', 'p4', 'internal-3'].map(ingressFor),
        rules: [
            { uuid: 'l1', sourceType: 'workload', sourceUuid: 'p1', destinationUuid: 'internal-1', protocol: 'tcp', port: 1 },
            { uuid: 'l2', sourceType: 'workload', sourceUuid: 'internal-2', destinationUuid: 'p4', protocol: 'tcp', port: 2 },
            { uuid: 'l3', sourceType: 'node', sourceUuid: 's1', destinationUuid: 'internal-3', protocol: 'tcp', port: 3 },
        ],
    });
    const data = { clusters: [large], dockerServers: [] };
    const layout = layoutClusterMap(data, { showTraffic: true });
    const columns = new Set(Object.values(layout.servers).map((rect) => rect.x));

    assert.equal(columns.size, 2);
    assert.equal(layoutClusterFrame(large, { showTraffic: true }).crossings, 0);
    assert.equal(publicCrossings(layout.edges), 0);
    assertLinesKeepClear(data, layout);
});
