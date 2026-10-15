import test from 'node:test';
import assert from 'node:assert/strict';
import { freeSpot, zoomShortcut } from './canvas-core.js';
import { canFollow, domainList, fresh, logoOf, onlyOption, parseEnvVars, planOf, splitImage, targetFrom, uniqueLabels, withDeploy, withoutSecrets, withTarget } from './deploy-canvas.js';

test('splits the tag out of an image name', () => {
    assert.deepEqual(splitImage('nginx:alpine', 'latest'), { name: 'nginx', tag: 'alpine' });
    assert.deepEqual(splitImage('ghcr.io:443/org/app', ''), { name: 'ghcr.io:443/org/app', tag: 'latest' });
    assert.deepEqual(splitImage('ghcr.io:443/org/app', 'v2'), { name: 'ghcr.io:443/org/app', tag: 'v2' });
});

test('turns an image digest into the sha256- tag form', () => {
    assert.deepEqual(splitImage('redis@sha256:abc123', 'latest'), { name: 'redis', tag: 'sha256-abc123' });
});

test('parses KEY=value lines and skips comments and blanks', () => {
    assert.deepEqual(parseEnvVars('# comment\n\nNODE_ENV=production\nURL="https://a.b/?x=1"\nNOPE\n'), [
        { key: 'NODE_ENV', value: 'production' },
        { key: 'URL', value: 'https://a.b/?x=1' },
    ]);
});

test('joins domains from lines and commas', () => {
    assert.equal(domainList(' https://a.com\nhttps://b.com, https://c.com \n'), 'https://a.com,https://b.com,https://c.com');
});

// Start → Server → (Service, Dockerfile → Env), and the one Deploy step.
const node = (id, type, data = {}) => ({ id, type, x: 0, y: 0, data });
const fanOut = () => ({
    nodes: [
        node('start', 'start'),
        node('srv', 'server'),
        node('svc', 'service', { mode: 'template', type: 'umami' }),
        node('df', 'dockerfile'),
        node('env', 'env'),
        node('dep', 'deploy'),
    ],
    edges: [
        { from: 'start', to: 'srv' },
        { from: 'srv', to: 'svc' },
        { from: 'srv', to: 'df' },
        { from: 'df', to: 'env' },
    ],
});

test('plans one line per resource under a server, each ending at its last step', () => {
    const plan = planOf(fanOut());
    assert.equal(plan.deploy.id, 'dep');
    assert.deepEqual(plan.lines.map((l) => [l.server.id, l.source.id, l.settings.map((n) => n.id), l.end.id]), [
        ['srv', 'svc', [], 'svc'],
        ['srv', 'df', ['env'], 'env'],
    ]);
});

test('adds the deploy step to a flow without one and drops edges into it', () => {
    const flow = withDeploy({
        nodes: [node('start', 'start'), node('srv', 'server'), node('img', 'image'), node('old', 'deploy')],
        edges: [{ from: 'start', to: 'srv' }, { from: 'srv', to: 'img' }, { from: 'img', to: 'old' }],
    });
    assert.deepEqual(flow.edges, [{ from: 'start', to: 'srv' }, { from: 'srv', to: 'img' }]);
    assert.equal(flow.nodes.filter((n) => n.type === 'deploy').length, 1);

    const empty = withDeploy({ nodes: [node('start', 'start')], edges: [] });
    assert.equal(empty.nodes.filter((n) => n.type === 'deploy').length, 1);
});

test('allows only steps that fit after a step', () => {
    const flow = fanOut();
    const byId = (id) => flow.nodes.find((n) => n.id === id);
    assert.equal(canFollow(byId('srv'), 'image', null, flow), true);
    assert.equal(canFollow(byId('start'), 'image', null, flow), false);
    assert.equal(canFollow(byId('env'), 'deploy', byId('dep'), flow), false); // Deploy is connected on its own
    assert.equal(canFollow(byId('svc'), 'build', null, flow), false); // build settings are for git only
    assert.equal(canFollow(byId('dep'), 'env', null, flow), false);
});

test('refuses a link that would make a loop', () => {
    const flow = fanOut();
    flow.nodes.push(node('env2', 'env'));
    flow.edges.push({ from: 'env', to: 'env2' });
    const byId = (id) => flow.nodes.find((n) => n.id === id);
    assert.equal(canFollow(byId('env2'), 'env', byId('env'), flow), false);
});

test('numbers resources that would have the same name', () => {
    const names = uniqueLabels([
        node('a', 'image', { image: 'nginx' }),
        node('b', 'image', { image: 'nginx' }),
        node('c', 'service', { mode: 'template', type: 'umami' }),
    ]);
    assert.deepEqual([...names.values()], ['Docker image · nginx #1', 'Docker image · nginx #2', 'Service · umami']);
});

test('keeps secrets out of the stored flow', () => {
    const flow = {
        nodes: [
            node('env', 'env', { vars: 'TOKEN=abc' }),
            node('svc', 'service', { mode: 'compose', compose: 'services: {}', name: 'web' }),
            node('git', 'git', { repository: 'https://user:tok@github.com/a/b', branch: 'main' }),
            node('pub', 'git', { repository: 'https://github.com/a/b' }),
        ],
        edges: [],
    };
    const stored = withoutSecrets(flow);
    assert.deepEqual(stored.nodes.map((n) => n.data), [{}, { mode: 'compose', name: 'web' }, { branch: 'main' }, { repository: 'https://github.com/a/b' }]);
    assert.equal(flow.nodes[0].data.vars, 'TOKEN=abc'); // the open canvas keeps them
});

test('reads the environment that opened the canvas from the query string', () => {
    assert.deepEqual(targetFrom('?project=p1a2&environment=e_3-x'), { project: 'p1a2', environment: 'e_3-x' });
    assert.equal(targetFrom(''), null);
    assert.equal(targetFrom('?project=p1a2'), null);
    assert.equal(targetFrom('?project=../servers&environment=e1'), null);
    assert.equal(targetFrom('?project=p1&environment='), null);
});

test('points the Start step of a restored flow at the opening environment', () => {
    const flow = {
        nodes: [node('start', 'start', { project: 'old', environment: 'old-env' }), node('srv', 'server', { server: 's1' })],
        edges: [{ from: 'start', to: 'srv' }],
    };
    withTarget(flow, { project: 'p1', environment: 'e1' });
    assert.deepEqual(flow.nodes.map((n) => n.data), [{ project: 'p1', environment: 'e1' }, { server: 's1' }]);
    assert.deepEqual(withTarget(flow, null).nodes[0].data, { project: 'p1', environment: 'e1' });
});

test('a new canvas starts with a Server step connected to Start', () => {
    const plan = planOf(fresh());
    assert.deepEqual(plan.servers.map((n) => n.id), ['server']);
    assert.equal(plan.deploy?.type, 'deploy');
    assert.deepEqual(plan.lines, []);
});

test('selects the only option, and only when nothing is selected', () => {
    assert.equal(onlyOption([['srv-1', 'localhost']], ''), 'srv-1');
    assert.equal(onlyOption([['srv-1', 'localhost']], undefined), 'srv-1');
    assert.equal(onlyOption([['srv-1', 'localhost']], 'srv-old'), 'srv-old');
    assert.equal(onlyOption([['srv-1', 'a'], ['srv-2', 'b']], ''), '');
    assert.equal(onlyOption([], ''), '');
    assert.equal(onlyOption(null, ''), ''); // still loading
});

test('keeps a dragged card off the other cards', () => {
    const other = { x: 0, y: 0, w: 100, h: 100 };
    const free = { x: 300, y: 0, w: 100, h: 100 };
    assert.deepEqual(freeSpot(free, [other]), free);
    // Overlaps from the right: pushed to the right edge, plus the gap.
    assert.deepEqual(freeSpot({ x: 90, y: 10, w: 100, h: 100 }, [other], 16), { x: 116, y: 10, w: 100, h: 100 });
    // Overlaps from below: pushed under it.
    assert.deepEqual(freeSpot({ x: 5, y: 80, w: 100, h: 100 }, [other], 16), { x: 5, y: 116, w: 100, h: 100 });
});

test('finds a corner spot when two cards block the way', () => {
    const cards = [{ x: 0, y: 0, w: 100, h: 100 }, { x: 116, y: 0, w: 100, h: 100 }];
    const spot = freeSpot({ x: 60, y: 20, w: 100, h: 100 }, cards, 16);
    for (const c of cards) {
        const apart = spot.x >= c.x + c.w + 16 || spot.x + spot.w + 16 <= c.x || spot.y >= c.y + c.h + 16 || spot.y + spot.h + 16 <= c.y;
        assert.ok(apart, 'the spot does not touch a card');
    }
    assert.deepEqual(spot, { x: 60, y: 116, w: 100, h: 100 });
});

test('shows the logo of what a source step creates, like the rest of Coolify', () => {
    const src = (type, data) => logoOf(node('n', type, data))?.src ?? null;
    assert.equal(src('image', {}), '/svgs/resources/docker-image.svg');
    assert.equal(src('dockerfile', {}), '/svgs/resources/dockerfile.svg');
    assert.equal(src('database', { engine: 'postgresql' }), '/svgs/resources/postgres.svg');
    assert.equal(src('database', { engine: 'mysql' }), '/svgs/resources/mysql.svg');
    assert.equal(logoOf(node('n', 'database', { engine: 'keydb' })).dark, '/svgs/resources/keydb-dark.svg');
    assert.equal(src('git', { mode: 'public', buildPack: 'railpack' }), '/svgs/resources/public-repo.svg');
    assert.equal(src('git', { mode: 'github', buildPack: 'railpack' }), '/svgs/resources/github-app.svg');
    assert.equal(src('git', { mode: 'key', buildPack: 'railpack' }), '/svgs/resources/deploy-key.svg');
    assert.equal(src('git', { mode: 'public', buildPack: 'dockerfile' }), '/svgs/resources/dockerfile.svg');
    assert.equal(src('service', { mode: 'compose' }), '/svgs/resources/docker-compose.svg');
    // No engine or template yet, or a step that creates nothing: the step keeps its glyph.
    assert.equal(src('database', {}), null);
    assert.equal(src('server', {}), null);
});

test('reads the browser zoom shortcut so the canvas can zoom instead', () => {
    const key = (key, mods = {}) => zoomShortcut({ key, ctrlKey: false, metaKey: false, altKey: false, ...mods });
    assert.equal(key('=', { metaKey: true }), 'in');
    assert.equal(key('+', { ctrlKey: true }), 'in');
    assert.equal(key('-', { ctrlKey: true }), 'out');
    assert.equal(key('0', { metaKey: true }), 'reset');
    assert.equal(key('-'), null);
    assert.equal(key('-', { ctrlKey: true, altKey: true }), null);
    assert.equal(key('s', { metaKey: true }), null);
});
