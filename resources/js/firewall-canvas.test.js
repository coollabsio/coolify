import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { anchoredPopoverPosition, autoLayoutFirewallPositions, closestCardConnectionPoints, firewallCanvasSize, fitFirewallZoom, firewallCanvas, firewallNodeIdFromConnector, groupFirewallRules, resolveCardCollision, separateFirewallPositions } from './firewall-canvas.js';

test('groups several firewall rules into one directional connection', () => {
    const connections = groupFirewallRules([
        { uuid: 'one', sourceType: 'workload', sourceUuid: 'api', destinationUuid: 'db', protocol: 'tcp', port: 5432 },
        { uuid: 'two', sourceType: 'workload', sourceUuid: 'api', destinationUuid: 'db', protocol: 'icmp', port: 0 },
        { uuid: 'three', sourceType: 'workload', sourceUuid: 'db', destinationUuid: 'api', protocol: 'tcp', port: 8080 },
    ]);

    assert.equal(connections.length, 2);
    assert.deepEqual(connections[0].rules.map((rule) => rule.uuid), ['one', 'two']);
    assert.equal(connections[1].source, 'workload:db');
});

test('does not draw rules whose endpoint is absent from the canvas', () => {
    const connections = groupFirewallRules([
        { uuid: 'one', sourceType: 'node', sourceUuid: 'node-a', destinationUuid: 'missing-app', protocol: 'tcp', port: 80 },
        { uuid: 'two', sourceType: 'node', sourceUuid: 'node-a', destinationUuid: 'api', protocol: 'tcp', port: 80 },
    ], new Set(['node:node-a', 'workload:api']));

    assert.deepEqual(connections.map((connection) => connection.rules[0].uuid), ['two']);
});

test('creates stable grouped positions for canvas nodes without rules', () => {
    const positions = autoLayoutFirewallPositions([
        { id: 'node:a', type: 'node' },
        { id: 'workload:one', type: 'workload' },
        { id: 'workload:two', type: 'workload' },
        { id: 'workload:three', type: 'workload' },
        { id: 'workload:four', type: 'workload' },
        { id: 'workload:five', type: 'workload' },
    ]);

    assert.deepEqual(positions['node:a'], { x: 40, y: 40 });
    assert.deepEqual(positions['workload:one'], { x: 360, y: 40 });
    assert.deepEqual(positions['workload:four'], { x: 360, y: 448 });
    assert.deepEqual(positions['workload:five'], { x: 680, y: 40 });
});

test('places rule destinations to the right of their sources', () => {
    const nodes = [
        { id: 'workload:db', type: 'workload' },
        { id: 'workload:idle', type: 'workload' },
        { id: 'workload:api', type: 'workload' },
        { id: 'node:a', type: 'node' },
    ];
    const connections = [
        { source: 'node:a', destination: 'workload:api' },
        { source: 'workload:api', destination: 'workload:db' },
        { source: 'workload:db', destination: 'workload:api' },
    ];
    const positions = autoLayoutFirewallPositions(nodes, connections);

    assert.equal(positions['node:a'].x, 40);
    assert.equal(positions['workload:api'].x, 360);
    assert.equal(positions['workload:db'].x, 680);
    assert.equal(positions['workload:idle'].x, 1000);
});

test('zooms out so that every card is visible after a layout', () => {
    assert.equal(fitFirewallZoom({ a: { x: 40, y: 40 } }, { width: 1000, height: 500 }), 1);
    assert.equal(fitFirewallZoom({ a: { x: 40, y: 40 }, b: { x: 1000, y: 40 } }, { width: 900, height: 500 }), 0.71);
    assert.equal(fitFirewallZoom({ a: { x: 4000, y: 40 } }, { width: 900, height: 500 }), 0.5);
});

test('connects the closest card sides as cards move around the canvas', () => {
    assert.deepEqual(
        closestCardConnectionPoints({ x: 0, y: 0 }, { x: 400, y: 0 }),
        { x1: 224, y1: 52, x2: 400, y2: 52 },
    );
    assert.deepEqual(
        closestCardConnectionPoints({ x: 0, y: 0 }, { x: 0, y: 300 }),
        { x1: 112, y1: 104, x2: 112, y2: 300 },
    );
    assert.deepEqual(
        closestCardConnectionPoints({ x: 0, y: 300 }, { x: 0, y: 0 }),
        { x1: 112, y1: 300, x2: 112, y2: 104 },
    );
});

test('gets the connection source from the dragged handle card', () => {
    const card = { dataset: { firewallNode: 'workload:api' } };
    const connector = { closest: (selector) => selector === '[data-firewall-node]' ? card : null };

    assert.equal(firewallNodeIdFromConnector(connector), 'workload:api');
});

test('starts the draft line at the dragged handle card', () => {
    const originalWindow = globalThis.window;
    globalThis.window = { addEventListener() {} };

    try {
        const canvas = firewallCanvas({ nodes: [], rules: [] });
        canvas.positions = { 'workload:api': { x: 80, y: 120 } };
        canvas.$refs = {
            viewport: {
                getBoundingClientRect: () => ({ left: 20, top: 30 }),
                scrollLeft: 0,
                scrollTop: 0,
            },
        };
        canvas.bindConnectionEvents();

        const card = { dataset: { firewallNode: 'workload:api' } };
        canvas.startConnection({
            currentTarget: { closest: () => card },
            clientX: 304,
            clientY: 202,
            preventDefault() {},
            stopPropagation() {},
        });

        assert.deepEqual(canvas.draft, {
            source: 'workload:api',
            sourceX: 304,
            sourceY: 172,
            x: 284,
            y: 172,
        });
    } finally {
        globalThis.window = originalWindow;
    }
});

test('includes the scroll position in pointer coordinates', () => {
    const canvas = firewallCanvas({ nodes: [], rules: [] });
    canvas.$refs = {
        viewport: {
            getBoundingClientRect: () => ({ left: 20, top: 30 }),
            scrollLeft: 150,
            scrollTop: 240,
        },
    };

    assert.deepEqual(canvas.pointerPoint({ clientX: 70, clientY: 90 }), { x: 200, y: 300 });
});

test('keeps the connection editor near its anchor and inside the mobile viewport', () => {
    assert.deepEqual(
        anchoredPopoverPosition(
            { x: 800, y: 700 },
            { scrollLeft: 600, scrollTop: 500, width: 320, height: 544 },
            { width: 296, height: 320 },
        ),
        { left: 612, top: 712 },
    );
    assert.deepEqual(
        anchoredPopoverPosition(
            { x: 900, y: 1000 },
            { scrollLeft: 600, scrollTop: 500, width: 320, height: 544 },
            { width: 296, height: 320 },
        ),
        { left: 612, top: 668 },
    );
});

test('binds pointer callbacks during Alpine initialization', () => {
    const originalLocalStorage = globalThis.localStorage;
    globalThis.localStorage = { getItem: () => null };

    try {
        const canvas = firewallCanvas({ nodes: [], rules: [], storageKey: 'test' });

        assert.equal(canvas.trackConnection, null);
        canvas.init();
        assert.equal(typeof canvas.trackConnection, 'function');
        assert.equal(typeof canvas.finishConnection, 'function');
    } finally {
        globalThis.localStorage = originalLocalStorage;
    }
});

test('detects two-way application connections', () => {
    const canvas = firewallCanvas({
        nodes: [
            { id: 'workload:api', name: 'api' },
            { id: 'workload:frontend', name: 'frontend' },
        ],
        rules: [],
    });
    const outbound = { id: 'api-to-frontend', source: 'workload:api', destination: 'workload:frontend' };
    canvas.connections = [
        outbound,
        { id: 'frontend-to-api', source: 'workload:frontend', destination: 'workload:api' },
    ];
    canvas.positions = {
        'workload:api': { x: 100, y: 0 },
        'workload:frontend': { x: 500, y: 0 },
    };

    assert.equal(canvas.hasReverseConnection(outbound), true);
    assert.equal(canvas.isVisibleConnection(outbound), true);
    assert.equal(canvas.isVisibleConnection(canvas.connections[1]), false);
    canvas.selectedConnectionId = outbound.id;
    assert.deepEqual(canvas.relatedConnections.map((connection) => connection.id), ['api-to-frontend', 'frontend-to-api']);
    assert.equal(canvas.connectionLabel(outbound), 'api → frontend');
    canvas.connections.pop();
    assert.equal(canvas.hasReverseConnection(outbound), false);
    assert.equal(canvas.isVisibleConnection(outbound), true);
});

test('updates directional arrows immediately after adding and removing rules', async () => {
    const canvas = firewallCanvas({ nodes: [], rules: [] });
    const outbound = {
        id: 'workload:api->workload:frontend',
        source: 'workload:api',
        destination: 'workload:frontend',
        rules: [{ uuid: 'outbound', protocol: 'tcp', port: 80 }],
    };
    const reverse = {
        id: 'workload:frontend->workload:api',
        source: 'workload:frontend',
        destination: 'workload:api',
        rules: [],
    };
    canvas.connections = [outbound, reverse];
    canvas.selectedConnectionId = reverse.id;
    canvas.$wire = {
        createFirewallRule: async () => ({ uuid: 'reverse', protocol: 'tcp', port: 80 }),
        removeFirewallRule: async () => {},
    };

    await canvas.addRule();
    assert.equal(canvas.hasReverseConnection(outbound), true);
    assert.deepEqual(reverse.rules.map((rule) => rule.uuid), ['reverse']);

    await canvas.removeRule('reverse');
    assert.equal(canvas.hasReverseConnection(outbound), false);
    assert.equal(canvas.selectedConnectionId, outbound.id);
});

test('renders connection loops outside the SVG namespace', () => {
    const template = readFileSync(new URL('../views/livewire/node-cluster/firewall-canvas.blade.php', import.meta.url), 'utf8');

    assert.match(template, /<div\s+wire:ignore\s+x-data="firewallCanvas/);
    assert.match(template, /<template x-for="connection in connections"[\s\S]*?<svg/);
    assert.doesNotMatch(template, /<svg[^>]*>[\s\S]*?<template x-for="connection in connections"/);
    assert.match(template, /stroke-dasharray="8 6"/);
    assert.match(template, /fill-warning/);
});

test('keeps a free card position unchanged', () => {
    assert.deepEqual(resolveCardCollision({ x: 500, y: 80 }, [{ x: 80, y: 80 }]), { x: 500, y: 80 });
});

test('moves a dropped card to the closest free side of a covered card', () => {
    assert.deepEqual(resolveCardCollision({ x: 120, y: 90 }, [{ x: 80, y: 80 }]), { x: 120, y: 200 });
    assert.deepEqual(resolveCardCollision({ x: 250, y: 80 }, [{ x: 80, y: 80 }]), { x: 320, y: 80 });
});

test('does not move a card into a second card while it avoids the first', () => {
    const obstacles = [{ x: 80, y: 80 }, { x: 320, y: 80 }];
    const position = resolveCardCollision({ x: 250, y: 80 }, obstacles);

    for (const obstacle of obstacles) {
        const overlaps = position.x < obstacle.x + 224 && obstacle.x < position.x + 224
            && position.y < obstacle.y + 104 && obstacle.y < position.y + 104;
        assert.equal(overlaps, false);
    }
});

test('separates stored card positions that overlap', () => {
    const positions = separateFirewallPositions(
        [{ id: 'workload:api' }, { id: 'workload:db' }],
        { 'workload:api': { x: 80, y: 80 }, 'workload:db': { x: 90, y: 80 } },
    );

    assert.deepEqual(positions['workload:api'], { x: 80, y: 80 });
    assert.deepEqual(positions['workload:db'], { x: 90, y: 200 });
});

test('sizes the canvas to the cards so that empty space cannot be scrolled', () => {
    assert.deepEqual(firewallCanvasSize({}), { width: 0, height: 0 });
    assert.deepEqual(
        firewallCanvasSize({ a: { x: 40, y: 40 }, b: { x: 680, y: 312 } }),
        { width: 944, height: 456 },
    );
});

test('replaces connections when rules change outside the canvas', () => {
    const nodes = [{ id: 'workload:api', type: 'workload' }, { id: 'workload:db', type: 'workload' }, { id: 'workload:web', type: 'workload' }];
    const rule = { uuid: 'one', sourceType: 'workload', sourceUuid: 'api', destinationUuid: 'db', protocol: 'tcp', port: 5432 };
    const canvas = firewallCanvas({ nodes, rules: [rule] });
    canvas.connections = groupFirewallRules([rule]);
    canvas.selectedConnectionId = 'workload:api->workload:db';

    canvas.replaceRules([]);
    assert.deepEqual(canvas.connections, []);
    assert.equal(canvas.selectedConnectionId, null);

    canvas.connections = [{ id: 'workload:web->workload:db', source: 'workload:web', destination: 'workload:db', rules: [] }];
    canvas.selectedConnectionId = 'workload:web->workload:db';
    canvas.replaceRules([rule]);
    assert.deepEqual(canvas.connections.map((connection) => connection.id), ['workload:api->workload:db', 'workload:web->workload:db']);
    assert.equal(canvas.selectedConnectionId, 'workload:web->workload:db');
});

test('returns editor styles as an object so that x-show keeps display none', () => {
    const canvas = firewallCanvas({ nodes: [], rules: [] });
    canvas.$refs = { viewport: { clientWidth: 800, clientHeight: 500 } };

    assert.deepEqual(canvas.editorStyle(), {});
});
