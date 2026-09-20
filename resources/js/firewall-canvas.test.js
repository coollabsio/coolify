import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { anchoredPopoverPosition, closestCardConnectionPoints, defaultFirewallPositions, firewallCanvas, firewallNodeIdFromConnector, groupFirewallRules } from './firewall-canvas.js';

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

test('creates stable grid positions for canvas nodes', () => {
    const positions = defaultFirewallPositions([
        { id: 'one' }, { id: 'two' }, { id: 'three' }, { id: 'four' }, { id: 'five' },
    ]);

    assert.deepEqual(positions.one, { x: 80, y: 80 });
    assert.deepEqual(positions.four, { x: 980, y: 80 });
    assert.deepEqual(positions.five, { x: 80, y: 270 });
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
    assert.match(template, /fill-yellow-400/);
});
