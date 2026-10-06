import {
    anchoredPopoverPosition,
    fitZoomToViewport,
    horizontalConnectorCommands,
    pathCommandsToString,
    roundedPolylineCommands,
    samplePathCommands,
    translatePathCommands,
} from './canvas-geometry.js';
import { groupFirewallRules } from './firewall-canvas.js';

/** Layout sizes in canvas pixels. The Blade template uses the same values. */
export const MAP = {
    margin: 24,
    frameGap: 32,
    framePadding: 16,
    frameHeaderHeight: 52,
    minimumFrameWidth: 296,
    emptyFrameHeight: 40,
    serverWidth: 248,
    serverHeaderHeight: 60,
    serverPadding: 10,
    appHeight: 26,
    appGap: 4,
    // Grid of server cards while the traffic layer is off.
    serverColumnGap: 56,
    serverRowGap: 32,
    maximumServerColumns: 3,
    // Layered drawing while the traffic layer is on.
    stackGap: 24,
    maximumStackedServers: 4,
    columnGap: 64,
    internetWidth: 88,
    internetHeight: 36,
    internetPortSpacing: 8,
    fanWidth: 56,
    labelWidth: 160,
    maximumLabelWidth: 248,
    labelCharacterWidth: 5.4,
    labelChrome: 40,
    labelHeight: 22,
    labelSpacing: 28,
    publicGap: 64,
    laneBase: 20,
    laneGap: 14,
    cornerRadius: 8,
    portSpacing: 8,
    clearance: 12,
    portGap: 2,
    virtualClearance: 16,
    dockerWidth: 208,
    dockerHeight: 48,
    dockerGap: 12,
    maximumDockerColumns: 2,
};

export const MINIMUM_ZOOM = 0.4;
export const MAXIMUM_ZOOM = 1.6;
const MINIMUM_VIEWPORT_HEIGHT = 240;
const MAXIMUM_VIEWPORT_HEIGHT = 640;
export const TRAFFIC_STORAGE_KEY = 'coolify-dashboard-map-traffic';

export function serverCardHeight(appCount) {
    const rows = Math.max(1, appCount);

    return MAP.serverHeaderHeight + rows * MAP.appHeight + (rows - 1) * MAP.appGap + MAP.serverPadding;
}

/** Position of an application row inside its server card, relative to the card. */
export function appRowOffset(index) {
    return {
        x: MAP.serverPadding,
        y: MAP.serverHeaderHeight + index * (MAP.appHeight + MAP.appGap),
        width: MAP.serverWidth - MAP.serverPadding * 2,
        height: MAP.appHeight,
    };
}

export function normalizeMapData(data) {
    return {
        clusters: Array.isArray(data?.clusters) ? data.clusters : [],
        dockerServers: Array.isArray(data?.dockerServers) ? data.dockerServers : [],
        dockerServersTotal: Number(data?.dockerServersTotal ?? 0),
        serversHref: data?.serversHref ?? null,
    };
}

function translate(rect, x, y) {
    return { ...rect, x: rect.x + x, y: rect.y + y };
}

/*
 * ---------------------------------------------------------------------------
 * Graph drawing primitives (layered drawing after Sugiyama et al.)
 * ---------------------------------------------------------------------------
 */

function layerPositions(layers) {
    const positions = new Map();
    layers.forEach((layer, layerIndex) => layer.forEach((id, index) => positions.set(id, { layer: layerIndex, index })));

    return positions;
}

/**
 * Counts the crossings between edges of neighbouring layers. An edge end sits
 * at its node's index plus an optional port offset in [0, 1), so lines that
 * leave or enter different rows of one card are ordered too.
 *
 * @param {string[][]} layers node ids per layer, in drawing order
 * @param {Array<{from: string, to: string, fromPort?: number, toPort?: number}>} edges from layer i to layer i + 1
 */
export function countCrossings(layers, edges) {
    const positions = layerPositions(layers);
    const ends = edges
        .filter((edge) => positions.has(edge.from) && positions.has(edge.to))
        .map((edge) => ({
            layer: positions.get(edge.from).layer,
            a: positions.get(edge.from).index + (edge.fromPort ?? 0),
            b: positions.get(edge.to).index + (edge.toPort ?? 0),
        }));
    let crossings = 0;
    for (let first = 0; first < ends.length; first++) {
        for (let second = first + 1; second < ends.length; second++) {
            if (ends[first].layer === ends[second].layer
                && (ends[first].a - ends[second].a) * (ends[first].b - ends[second].b) < 0) {
                crossings++;
            }
        }
    }

    return crossings;
}

function sortByBarycenter(layer, neighbourPositions) {
    return layer
        .map((id, index) => {
            const positions = neighbourPositions(id);

            return {
                id,
                index,
                key: positions.length ? positions.reduce((total, value) => total + value, 0) / positions.length : index,
            };
        })
        .sort((first, second) => (first.key - second.key) || (first.index - second.index))
        .map((entry) => entry.id);
}

/**
 * Orders the nodes of every layer to reduce edge crossings with the barycenter
 * heuristic: alternating down and up sweeps move each node to the mean
 * position of its neighbours in the previous layer. Nodes without neighbours
 * keep their place. The order with the fewest crossings wins; ties keep the
 * earlier order, so a crossing-free input stays as it is.
 *
 * @returns {{layers: string[][], crossings: number}}
 */
export function barycenterOrder(layers, edges, { sweeps = 4 } = {}) {
    const incoming = new Map();
    const outgoing = new Map();
    for (const edge of edges) {
        (incoming.get(edge.to) ?? incoming.set(edge.to, []).get(edge.to)).push(edge);
        (outgoing.get(edge.from) ?? outgoing.set(edge.from, []).get(edge.from)).push(edge);
    }
    let current = layers.map((layer) => [...layer]);
    let best = current.map((layer) => [...layer]);
    let bestCrossings = countCrossings(best, edges);

    for (let sweep = 0; sweep < sweeps && bestCrossings > 0; sweep++) {
        for (let layer = 1; layer < current.length; layer++) {
            const above = new Map(current[layer - 1].map((id, index) => [id, index]));
            current[layer] = sortByBarycenter(current[layer], (id) => (incoming.get(id) ?? [])
                .filter((edge) => above.has(edge.from))
                .map((edge) => above.get(edge.from) + (edge.fromPort ?? 0)));
        }
        for (let layer = current.length - 2; layer >= 0; layer--) {
            const below = new Map(current[layer + 1].map((id, index) => [id, index]));
            current[layer] = sortByBarycenter(current[layer], (id) => (outgoing.get(id) ?? [])
                .filter((edge) => below.has(edge.to))
                .map((edge) => below.get(edge.to) + (edge.toPort ?? 0)));
        }
        const crossings = countCrossings(current, edges);
        if (crossings < bestCrossings) {
            best = current.map((layer) => [...layer]);
            bestCrossings = crossings;
        }
    }

    return { layers: best, crossings: bestCrossings };
}

/**
 * Least-squares isotonic regression with the Pool Adjacent Violators
 * algorithm: the non-decreasing sequence closest to `values`.
 */
export function isotonicRegression(values, weights = values.map(() => 1)) {
    const blocks = [];
    values.forEach((value, index) => {
        blocks.push({ mean: value, weight: weights[index], size: 1 });
        while (blocks.length > 1 && blocks[blocks.length - 2].mean > blocks[blocks.length - 1].mean) {
            const last = blocks.pop();
            const previous = blocks[blocks.length - 1];
            const weight = previous.weight + last.weight;
            previous.mean = (previous.mean * previous.weight + last.mean * last.weight) / weight;
            previous.weight = weight;
            previous.size += last.size;
        }
    });

    return blocks.flatMap((block) => Array.from({ length: block.size }, () => block.mean));
}

/**
 * Places ordered labels as close as possible to their target y (least squares)
 * while keeping `gap` between neighbours: substituting z_i = y_i - i * gap turns
 * the spacing constraints into z being non-decreasing, which isotonic
 * regression solves exactly. `minimum` bounds the first label; clipping the
 * isotonic solution keeps it optimal.
 */
export function alignLabels(targets, gap, { minimum = -Infinity } = {}) {
    const shifted = isotonicRegression(targets.map((target, index) => target - index * gap));

    return shifted.map((value, index) => Math.max(value, minimum) + index * gap);
}

function intervalsOverlap(first, second) {
    return first.start <= second.end && second.start <= first.end;
}

/** The largest number of closed intervals that share one point. */
export function maximumOverlap(intervals) {
    const events = intervals.flatMap((interval) => [[interval.start, 1], [interval.end, -1]])
        .sort((first, second) => (first[0] - second[0]) || (second[1] - first[1]));
    let open = 0;
    let maximum = 0;
    for (const [, change] of events) {
        open += change;
        maximum = Math.max(maximum, open);
    }

    return maximum;
}

function greedyLanes(intervals, order) {
    const lanes = new Array(intervals.length).fill(-1);
    for (const index of order) {
        const used = new Set();
        intervals.forEach((other, otherIndex) => {
            if (lanes[otherIndex] >= 0 && intervalsOverlap(intervals[index], other)) {
                used.add(lanes[otherIndex]);
            }
        });
        let lane = 0;
        while (used.has(lane)) {
            lane++;
        }
        lanes[index] = lane;
    }

    return lanes;
}

function nestingViolations(intervals, lanes) {
    let violations = 0;
    intervals.forEach((inner, innerIndex) => intervals.forEach((outer, outerIndex) => {
        const nested = innerIndex !== outerIndex
            && outer.start <= inner.start && inner.end <= outer.end
            && (outer.end - outer.start) > (inner.end - inner.start);
        if (nested && lanes[innerIndex] > lanes[outerIndex]) {
            violations++;
        }
    }));

    return violations;
}

/**
 * Assigns vertical intervals to lanes so that intervals in one lane never
 * overlap (closed intervals: touching ends get separate lanes).
 *
 * Interval partitioning (sorted by start, smallest free lane) uses the optimal
 * number of lanes, the maximum overlap. Arcs only avoid needless crossings when
 * every nested interval lies on an inner lane, so the shortest-first greedy
 * order, which guarantees that, is used when the optimal partitioning would put
 * a nested interval outside its container.
 *
 * @param {Array<{start: number, end: number}>} intervals
 * @returns {{lanes: number[], count: number}}
 */
export function assignLanes(intervals) {
    const indexes = intervals.map((_, index) => index);
    const byStart = greedyLanes(intervals, [...indexes].sort((first, second) => (
        (intervals[first].start - intervals[second].start) || (intervals[first].end - intervals[second].end)
    )));
    const byLength = greedyLanes(intervals, [...indexes].sort((first, second) => (
        ((intervals[first].end - intervals[first].start) - (intervals[second].end - intervals[second].start))
        || (intervals[first].start - intervals[second].start)
    )));
    const count = (lanes) => (lanes.length ? Math.max(...lanes) + 1 : 0);
    const lanes = count(byLength) <= count(byStart) || nestingViolations(intervals, byStart) > 0 ? byLength : byStart;

    return { lanes, count: count(lanes) };
}

/*
 * ---------------------------------------------------------------------------
 * Frame layouts
 * ---------------------------------------------------------------------------
 */

/** Compact grid of server cards, used while the traffic layer is off. */
function layoutGridFrame(cluster, { availableWidth }) {
    const servers = cluster.servers ?? [];
    const gridLeft = MAP.framePadding;
    const fitColumns = Math.floor((availableWidth - gridLeft - MAP.framePadding + MAP.serverColumnGap) / (MAP.serverWidth + MAP.serverColumnGap));
    const columns = Math.max(1, Math.min(MAP.maximumServerColumns, servers.length || 1, fitColumns));
    const cards = [];
    let rowTop = MAP.frameHeaderHeight;

    for (let index = 0; index < servers.length; index += columns) {
        const row = servers.slice(index, index + columns);
        row.forEach((server, column) => {
            cards.push({
                server,
                rect: {
                    x: gridLeft + column * (MAP.serverWidth + MAP.serverColumnGap),
                    y: rowTop,
                    width: MAP.serverWidth,
                    height: serverCardHeight(server.apps?.length ?? 0),
                },
            });
        });
        rowTop += Math.max(...row.map((server) => serverCardHeight(server.apps?.length ?? 0))) + MAP.serverRowGap;
    }

    const gridHeight = servers.length ? rowTop - MAP.serverRowGap - MAP.frameHeaderHeight : MAP.emptyFrameHeight;
    const contentWidth = servers.length ? columns * MAP.serverWidth + (columns - 1) * MAP.serverColumnGap : 0;

    return {
        mode: 'grid',
        width: Math.max(MAP.minimumFrameWidth, gridLeft + contentWidth + MAP.framePadding),
        height: MAP.frameHeaderHeight + gridHeight + MAP.framePadding,
        cards,
        internet: null,
        labels: [],
        edges: [],
    };
}

function ruleText(rule) {
    return `${rule.protocol.toUpperCase()}${rule.protocol === 'icmp' ? '' : ` / ${rule.port}`}`;
}

function labelText(domains) {
    return domains.length > 1 ? `${domains[0]} +${domains.length - 1}` : (domains[0] ?? '');
}

/**
 * One width for every label of a frame, so all public lines leave at the same
 * x: wide enough for the longest domain (estimated from the 11px label font),
 * between `labelWidth` and `maximumLabelWidth`. Longer domains are truncated.
 */
export function labelColumnWidth(texts) {
    const longest = Math.max(0, ...texts.map((text) => text.length));

    return Math.round(Math.min(MAP.maximumLabelWidth, Math.max(MAP.labelWidth, longest * MAP.labelCharacterWidth + MAP.labelChrome)));
}

/** Firewall connections with their reverse direction merged into one two-way connection. */
function mergeConnections(connections) {
    const merged = [];
    const drawn = new Set();
    for (const connection of connections) {
        if (drawn.has(connection.id)) {
            continue;
        }
        const reverse = connections.find((candidate) => (
            candidate !== connection
            && candidate.source === connection.destination
            && candidate.destination === connection.source
        ));
        drawn.add(connection.id);
        if (reverse) {
            drawn.add(reverse.id);
        }
        merged.push({ connection, reverse: reverse ?? null });
    }

    return merged;
}

/** Drops repeated points and points in the middle of a straight run. */
export function simplifyPolyline(points) {
    const unique = points.filter((point, index) => (
        index === 0 || point.x !== points[index - 1].x || point.y !== points[index - 1].y
    ));

    return unique.filter((point, index) => {
        if (index === 0 || index === unique.length - 1) {
            return true;
        }
        const previous = unique[index - 1];
        const next = unique[index + 1];

        return !((previous.x === point.x && point.x === next.x) || (previous.y === point.y && point.y === next.y));
    });
}

function stackColumn(items, heightOf) {
    const positions = new Map();
    let y = MAP.frameHeaderHeight;
    let previous = null;
    for (const item of items) {
        const isVirtual = item.startsWith('virtual:');
        if (isVirtual) {
            y += previous === 'virtual' ? MAP.labelSpacing : MAP.virtualClearance;
            positions.set(item, { y, height: 0 });
            previous = 'virtual';
            continue;
        }
        if (previous) {
            y += previous === 'virtual' ? MAP.virtualClearance : MAP.stackGap;
        }
        const height = heightOf(item);
        positions.set(item, { y, height });
        y += height;
        previous = 'card';
    }
    if (previous === 'virtual') {
        y += MAP.virtualClearance;
    }

    return { positions, bottom: y };
}

/**
 * Lays out one cluster frame as a layered drawing, in frame coordinates.
 *
 * Layers: the Internet node, one label per public application, then the
 * server cards stacked in one column (two above `maximumStackedServers`
 * servers; lines to the second column pass the first one through virtual
 * nodes between its cards). Layer order comes from the barycenter heuristic,
 * label heights from isotonic regression, so most public lines are level.
 * Firewall connections run as arcs in a channel right of their column, one
 * lane per overlapping interval.
 *
 * Returns null when the cluster has nothing to draw on the traffic layer.
 */
function layoutLayeredFrame(cluster) {
    const servers = cluster.servers ?? [];
    if (!servers.length) {
        return null;
    }
    const placementsByApp = new Map();
    const names = new Map();
    for (const server of servers) {
        names.set(`node:${server.uuid}`, server.name);
        (server.apps ?? []).forEach((app, index) => {
            names.set(`workload:${app.uuid}`, app.name);
            (placementsByApp.get(app.uuid) ?? placementsByApp.set(app.uuid, []).get(app.uuid)).push({ server, index });
        });
    }
    const ingressList = (cluster.ingress ?? []).filter((ingress) => placementsByApp.has(ingress.appUuid));
    const anchorIds = new Set([
        ...servers.map((server) => `node:${server.uuid}`),
        ...[...placementsByApp.keys()].map((uuid) => `workload:${uuid}`),
    ]);
    const connections = mergeConnections(groupFirewallRules(cluster.rules ?? [], anchorIds));
    if (!ingressList.length && !connections.length) {
        return null;
    }

    // Columns: one, or two when the column would be too tall. Servers with
    // public applications go to the first column, nearest to their labels.
    const columnCount = servers.length > MAP.maximumStackedServers ? 2 : 1;
    const columnOf = new Map();
    if (columnCount === 1) {
        servers.forEach((server) => columnOf.set(server.uuid, 0));
    } else {
        const publicApps = (server) => ingressList.filter((ingress) => placementsByApp.get(ingress.appUuid).some((placement) => placement.server === server)).length;
        servers
            .map((server, index) => ({ server, index, count: publicApps(server) }))
            .sort((first, second) => (second.count - first.count) || (first.index - second.index))
            .forEach((entry, rank) => columnOf.set(entry.server.uuid, rank < Math.ceil(servers.length / 2) ? 0 : 1));
    }

    // Public routes: one label per application, drawn to one placement.
    const publicRoutes = ingressList.map((ingress, index) => {
        const placements = placementsByApp.get(ingress.appUuid);
        const target = placements.find((placement) => columnOf.get(placement.server.uuid) === 0) ?? placements[0];

        return { id: `label:${index}`, ingress, target, long: columnOf.get(target.server.uuid) === 1 };
    });

    // 1. Layers and 2. crossing minimization.
    const rowPort = (target) => (target.index + 0.5) / Math.max(1, target.server.apps.length);
    const layers = [];
    const graphEdges = [];
    if (publicRoutes.length) {
        layers.push(['internet'], publicRoutes.map((route) => route.id));
    }
    const columnServers = (column) => servers.filter((server) => columnOf.get(server.uuid) === column).map((server) => `server:${server.uuid}`);
    layers.push([...columnServers(0), ...publicRoutes.filter((route) => route.long).map((route) => `virtual:${route.id}`)]);
    if (columnCount === 2) {
        layers.push(columnServers(1));
    }
    for (const route of publicRoutes) {
        graphEdges.push({ from: 'internet', to: route.id });
        const server = `server:${route.target.server.uuid}`;
        if (route.long) {
            graphEdges.push({ from: route.id, to: `virtual:${route.id}` }, { from: `virtual:${route.id}`, to: server, toPort: rowPort(route.target) });
        } else {
            graphEdges.push({ from: route.id, to: server, toPort: rowPort(route.target) });
        }
    }
    const ordered = barycenterOrder(layers, graphEdges);
    const firstColumnLayer = publicRoutes.length ? 2 : 0;
    const labelOrder = publicRoutes.length ? ordered.layers[1] : [];
    const columnOrders = [ordered.layers[firstColumnLayer], ordered.layers[firstColumnLayer + 1] ?? []];

    // 3. Coordinates: stack the columns, then align labels with isotonic regression.
    const serverByKey = new Map(servers.map((server) => [`server:${server.uuid}`, server]));
    const heightOf = (key) => serverCardHeight(serverByKey.get(key).apps?.length ?? 0);
    const stacks = columnOrders.map((order) => stackColumn(order, heightOf));
    const cardY = new Map();
    stacks.forEach((stack) => stack.positions.forEach((position, key) => cardY.set(key, position.y)));
    const rowCenter = (placement) => cardY.get(`server:${placement.server.uuid}`)
        + MAP.serverHeaderHeight + placement.index * (MAP.appHeight + MAP.appGap) + MAP.appHeight / 2;
    const routeById = new Map(publicRoutes.map((route) => [route.id, route]));
    const labelRoutes = labelOrder.map((id) => routeById.get(id));
    const labelTargets = labelRoutes.map((route) => (route.long ? cardY.get(`virtual:${route.id}`) : rowCenter(route.target)));
    const labelCenters = alignLabels(labelTargets, MAP.labelSpacing, { minimum: MAP.frameHeaderHeight + MAP.labelHeight / 2 });

    // Firewall routes: pick the placements, then the channel and its side ports.
    const anchorPlacements = (id) => {
        const [type, uuid] = id.split(':');
        if (type === 'node') {
            const server = servers.find((candidate) => candidate.uuid === uuid);

            return [{ key: id, server, column: columnOf.get(uuid), header: true, y: cardY.get(`server:${uuid}`) + MAP.serverHeaderHeight / 2 }];
        }

        return placementsByApp.get(uuid).map((placement) => ({
            key: `${id}@${placement.server.uuid}`,
            server: placement.server,
            index: placement.index,
            column: columnOf.get(placement.server.uuid),
            header: false,
            y: rowCenter(placement),
        }));
    };
    const firewallRoutes = connections.map(({ connection, reverse }) => {
        let best = null;
        for (const source of anchorPlacements(connection.source)) {
            for (const destination of anchorPlacements(connection.destination)) {
                const cost = Math.abs(source.column - destination.column) * 1e6 + Math.abs(source.y - destination.y);
                if (!best || cost < best.cost) {
                    best = { source, destination, cost };
                }
            }
        }
        const { source, destination } = best;
        const cross = source.column !== destination.column;
        const sideOf = (end) => (!cross || end.column === 0 ? 'right' : 'left');

        return {
            connection,
            reverse,
            cross,
            channel: cross ? 0 : source.column,
            source: { ...source, side: sideOf(source) },
            destination: { ...destination, side: sideOf(destination) },
            interval: { start: Math.min(source.y, destination.y), end: Math.max(source.y, destination.y) },
            lane: 0,
        };
    });

    // 5. Lanes per channel; connections across columns take the outer lanes.
    const laneCounts = [0, 0];
    for (let channel = 0; channel < columnCount; channel++) {
        const inner = firewallRoutes.filter((route) => route.channel === channel && !route.cross);
        const outer = firewallRoutes.filter((route) => route.channel === channel && route.cross);
        const innerLanes = assignLanes(inner.map((route) => route.interval));
        inner.forEach((route, index) => { route.lane = innerLanes.lanes[index]; });
        const outerLanes = assignLanes(outer.map((route) => route.interval));
        outer.forEach((route, index) => { route.lane = innerLanes.count + outerLanes.lanes[index]; });
        laneCounts[channel] = innerLanes.count + outerLanes.count;
    }

    // Ports: every line end on one side of a row gets its own height, ordered
    // so lines that share a row do not cross each other.
    const ports = new Map();
    const addEnd = (end, entry) => {
        const key = `${end.key}|${end.side}`;
        (ports.get(key) ?? ports.set(key, []).get(key)).push(entry);
    };
    for (const route of firewallRoutes) {
        const selfLoop = route.source.key === route.destination.key;
        addEnd(route.source, { route, end: 'source', own: route.source.y, other: route.destination.y, selfLoop, order: 0 });
        addEnd(route.destination, { route, end: 'destination', own: route.destination.y, other: route.source.y, selfLoop, order: 1 });
    }
    for (const route of publicRoutes) {
        const key = `workload:${route.ingress.appUuid}@${route.target.server.uuid}`;
        const other = route.long ? cardY.get(`virtual:${route.id}`) : labelCenters[labelOrder.indexOf(route.id)];
        addEnd({ key, side: 'left' }, { route, end: 'public', own: rowCenter(route.target), other, selfLoop: false, order: 0 });
    }
    const portOffsets = new Map();
    for (const [key, entries] of ports) {
        const side = key.split('|')[1];
        const rank = (entry) => {
            if (side === 'left') {
                return [0, entry.other];
            }
            if (entry.selfLoop) {
                return [0, entry.order];
            }

            // Arcs that go up: inner lanes on top. Arcs that go down: outer lanes on top.
            return entry.other < entry.own ? [1, entry.route.lane] : [2, -entry.route.lane];
        };
        entries.sort((first, second) => {
            const [firstGroup, firstValue] = rank(first);
            const [secondGroup, secondValue] = rank(second);

            return (firstGroup - secondGroup) || (firstValue - secondValue);
        });
        const height = key.includes('@') ? MAP.appHeight : MAP.serverHeaderHeight;
        const spacing = entries.length > 1 ? Math.min(MAP.portSpacing, (height - 8) / (entries.length - 1)) : 0;
        entries.forEach((entry, index) => {
            const offsets = portOffsets.get(entry.route) ?? portOffsets.set(entry.route, {}).get(entry.route);
            offsets[entry.end] = (index - (entries.length - 1) / 2) * spacing;
        });
    }
    const offsetOf = (route, end) => portOffsets.get(route)?.[end] ?? 0;

    // Horizontal coordinates.
    const hasPublic = labelRoutes.length > 0;
    const labelWidth = labelColumnWidth(labelRoutes.map((route) => labelText(route.ingress.domains ?? [])));
    const internetX = MAP.framePadding;
    const labelsX = internetX + MAP.internetWidth + MAP.fanWidth;
    const channelWidth = (lanes) => (lanes ? MAP.laneBase + (lanes - 1) * MAP.laneGap : 0);
    const columnX = [hasPublic ? labelsX + labelWidth + MAP.publicGap : MAP.framePadding];
    if (columnCount === 2) {
        columnX.push(columnX[0] + MAP.serverWidth + channelWidth(laneCounts[0]) + MAP.columnGap);
    }
    const columnRight = (column) => columnX[column] + MAP.serverWidth;
    const laneX = (channel, lane) => columnRight(channel) + MAP.laneBase + lane * MAP.laneGap;
    const lastColumn = columnCount - 1;
    const width = Math.max(
        MAP.minimumFrameWidth,
        columnRight(lastColumn) + (laneCounts[lastColumn] ? channelWidth(laneCounts[lastColumn]) + MAP.laneBase : MAP.framePadding),
    );

    const cards = servers.map((server) => ({
        server,
        rect: {
            x: columnX[columnOf.get(server.uuid)],
            y: cardY.get(`server:${server.uuid}`),
            width: MAP.serverWidth,
            height: serverCardHeight(server.apps?.length ?? 0),
        },
    }));
    const cardRect = new Map(cards.map((card) => [card.server.uuid, card.rect]));
    const endPoint = (end, offset) => {
        const rect = cardRect.get(end.server.uuid);
        // Lines end just outside the card, level with the row, so arrowheads sit in the
        // free gap and do not merge with the card border or the row background.
        const left = rect.x - MAP.portGap;
        const right = rect.x + rect.width + MAP.portGap;

        return { x: end.side === 'left' ? left : right, y: end.y + offset };
    };

    const labelCount = labelRoutes.length;
    const internetHeight = Math.max(MAP.internetHeight, (labelCount + 1) * MAP.internetPortSpacing);
    const internet = hasPublic
        ? {
            x: internetX,
            y: Math.max(MAP.frameHeaderHeight, (labelCenters[0] + labelCenters[labelCount - 1]) / 2 - internetHeight / 2),
            width: MAP.internetWidth,
            height: internetHeight,
        }
        : null;

    const edges = [];
    // 4. Public lines: a fan from the Internet node to the labels, then a level
    // line or a Bezier curve from each label into its application row.
    labelRoutes.forEach((route, index) => {
        const { ingress } = route;
        const labelCenter = labelCenters[index];
        const label = { x: labelsX, y: labelCenter - MAP.labelHeight / 2, width: labelWidth, height: MAP.labelHeight };
        const internetPort = { x: internet.x + internet.width, y: internet.y + ((index + 1) * internet.height) / (labelCount + 1) };
        const labelStart = { x: labelsX, y: labelCenter };
        const labelEnd = { x: labelsX + labelWidth, y: labelCenter };
        const arrival = endPoint({ server: route.target.server, header: false, side: 'left', y: rowCenter(route.target) }, offsetOf(route, 'public'));
        const commands = [
            { command: 'M', values: [internetPort.x, internetPort.y] },
            ...horizontalConnectorCommands(internetPort, labelStart),
            { command: 'M', values: [labelEnd.x, labelEnd.y] },
        ];
        if (route.long) {
            const virtualY = cardY.get(`virtual:${route.id}`);
            const throughStart = { x: columnX[0], y: virtualY };
            const throughEnd = { x: columnX[1] - MAP.columnGap + MAP.clearance, y: virtualY };
            commands.push(
                ...horizontalConnectorCommands(labelEnd, throughStart),
                { command: 'L', values: [throughEnd.x, throughEnd.y] },
                ...horizontalConnectorCommands(throughEnd, arrival),
            );
        } else {
            commands.push(...horizontalConnectorCommands(labelEnd, arrival));
        }
        const domains = ingress.domains ?? [];
        edges.push({
            id: `${cluster.uuid}|internet->workload:${ingress.appUuid}`,
            kind: 'ingress',
            endpoints: ['internet', `workload:${ingress.appUuid}`],
            bidirectional: false,
            domains,
            directions: [{
                from: 'Internet',
                to: names.get(`workload:${ingress.appUuid}`) ?? ingress.appUuid,
                rules: [{ uuid: 'ingress', text: `HTTP and HTTPS to port ${ingress.port}` }],
            }],
            label: { ...label, text: labelText(domains) },
            commands,
        });
    });

    // 5. Firewall arcs: out of the source port, along the lane, into the target port.
    for (const route of firewallRoutes) {
        const start = endPoint(route.source, offsetOf(route, 'source'));
        const end = endPoint(route.destination, offsetOf(route, 'destination'));
        const x = laneX(route.channel, route.lane);
        const { connection, reverse } = route;
        edges.push({
            id: `${cluster.uuid}|${connection.id}`,
            kind: 'firewall',
            endpoints: [connection.source, connection.destination],
            bidirectional: Boolean(reverse),
            directions: [connection, reverse].filter(Boolean).map((direction) => ({
                from: names.get(direction.source) ?? direction.source,
                to: names.get(direction.destination) ?? direction.destination,
                rules: direction.rules.map((rule) => ({ uuid: rule.uuid, text: ruleText(rule) })),
            })),
            label: null,
            commands: roundedPolylineCommands(simplifyPolyline([start, { x, y: start.y }, { x, y: end.y }, end]), MAP.cornerRadius),
        });
    }

    const bottoms = [
        ...stacks.map((stack) => stack.bottom),
        ...labelCenters.map((center) => center + MAP.labelHeight / 2),
        internet ? internet.y + internet.height : 0,
    ];

    return {
        mode: 'layered',
        width,
        height: Math.max(...bottoms) + MAP.framePadding,
        cards,
        internet,
        labels: edges.filter((edge) => edge.label).map((edge) => edge.label),
        crossings: ordered.crossings,
        edges,
    };
}

/**
 * Lays out one cluster frame in frame coordinates. With the traffic layer on
 * and something to draw, the frame is a layered drawing with its lines;
 * otherwise a compact grid of server cards.
 */
export function layoutClusterFrame(cluster, { availableWidth = 1200, showTraffic = false } = {}) {
    return (showTraffic && layoutLayeredFrame(cluster)) || layoutGridFrame(cluster, { availableWidth });
}

function layoutDockerFrame(dockerServers, { availableWidth }) {
    const fitColumns = Math.floor((availableWidth - MAP.framePadding * 2 + MAP.dockerGap) / (MAP.dockerWidth + MAP.dockerGap));
    const columns = Math.max(1, Math.min(MAP.maximumDockerColumns, dockerServers.length, fitColumns));
    const rows = Math.ceil(dockerServers.length / columns);
    const cards = dockerServers.map((server, index) => ({
        server,
        rect: {
            x: MAP.framePadding + (index % columns) * (MAP.dockerWidth + MAP.dockerGap),
            y: MAP.frameHeaderHeight + Math.floor(index / columns) * (MAP.dockerHeight + MAP.dockerGap),
            width: MAP.dockerWidth,
            height: MAP.dockerHeight,
        },
    }));

    return {
        width: Math.max(MAP.minimumFrameWidth, MAP.framePadding * 2 + columns * MAP.dockerWidth + (columns - 1) * MAP.dockerGap),
        height: MAP.frameHeaderHeight + rows * MAP.dockerHeight + (rows - 1) * MAP.dockerGap + MAP.framePadding,
        cards,
    };
}

/** The point halfway along a polyline, where the details popover is anchored. */
export function polylineMidpoint(points) {
    const lengths = points.slice(1).map((point, index) => Math.hypot(point.x - points[index].x, point.y - points[index].y));
    let remaining = lengths.reduce((total, length) => total + length, 0) / 2;
    for (const [index, length] of lengths.entries()) {
        if (remaining <= length) {
            const ratio = length ? remaining / length : 0;

            return {
                x: points[index].x + (points[index + 1].x - points[index].x) * ratio,
                y: points[index].y + (points[index + 1].y - points[index].y) * ratio,
            };
        }
        remaining -= length;
    }

    return { ...points[points.length - 1] };
}

/** SVG path for an orthogonal polyline with rounded corners. */
export function roundedPath(points, radius = MAP.cornerRadius) {
    return pathCommandsToString(roundedPolylineCommands(points, radius));
}

/** Moves an edge from frame to canvas coordinates and adds what the template draws. */
function placeEdge(edge, clusterUuid, firewallHref, dx, dy) {
    const commands = translatePathCommands(edge.commands, dx, dy);
    const points = samplePathCommands(commands, 12);
    const first = points[0];
    const last = points[points.length - 1];
    // The popover anchors on the last subpath: the public line after its label.
    const lastMove = points.findLastIndex((point) => point.move);

    return {
        ...edge,
        clusterUuid,
        firewallHref,
        label: edge.label ? translate(edge.label, dx, dy) : null,
        commands,
        path: pathCommandsToString(commands),
        points,
        x1: first.x,
        y1: first.y,
        x2: last.x,
        y2: last.y,
        mid: polylineMidpoint(points.slice(lastMove)),
    };
}

/**
 * Places one frame per cluster, then the Docker servers frame, in rows that
 * wrap at the available width. Returns canvas rectangles for every frame,
 * server card, application row, Internet node, and Docker server card, plus
 * the traffic lines of every cluster when the traffic layer is on.
 */
export function layoutClusterMap(data, { availableWidth = 1200, showTraffic = false } = {}) {
    const width = Math.max(MAP.minimumFrameWidth, availableWidth);
    const blocks = (data.clusters ?? []).map((cluster) => ({
        id: `cluster:${cluster.uuid}`,
        kind: 'cluster',
        cluster,
        ...layoutClusterFrame(cluster, { availableWidth: width, showTraffic }),
    }));
    if (data.dockerServers?.length) {
        blocks.push({ id: 'docker', kind: 'docker', ...layoutDockerFrame(data.dockerServers, { availableWidth: width }) });
    }

    const layout = {
        frames: [],
        servers: {},
        apps: {},
        placements: {},
        internet: {},
        dockerServers: {},
        edges: [],
        size: { width: 0, height: 0 },
    };
    let x = MAP.margin;
    let y = MAP.margin;
    let rowHeight = 0;

    for (const block of blocks) {
        if (x > MAP.margin && x + block.width > MAP.margin + width) {
            x = MAP.margin;
            y += rowHeight + MAP.frameGap;
            rowHeight = 0;
        }

        layout.frames.push({
            id: block.id,
            kind: block.kind,
            clusterUuid: block.cluster?.uuid ?? null,
            x,
            y,
            width: block.width,
            height: block.height,
        });

        if (block.kind === 'cluster') {
            const clusterUuid = block.cluster.uuid;
            const placements = {};
            for (const card of block.cards) {
                const serverKey = `${clusterUuid}/${card.server.uuid}`;
                const serverRect = translate(card.rect, x, y);
                layout.servers[serverKey] = serverRect;
                (card.server.apps ?? []).forEach((app, index) => {
                    const appRect = translate(appRowOffset(index), serverRect.x, serverRect.y);
                    const appKey = `${serverKey}/${app.uuid}`;
                    layout.apps[appKey] = appRect;
                    (placements[app.uuid] ??= []).push({ key: appKey, serverUuid: card.server.uuid, rect: appRect });
                });
            }
            layout.placements[clusterUuid] = placements;
            if (block.internet) {
                layout.internet[clusterUuid] = translate(block.internet, x, y);
            }
            layout.edges.push(...block.edges.map((edge) => placeEdge(edge, clusterUuid, block.cluster.firewallHref ?? null, x, y)));
        } else {
            for (const card of block.cards) {
                layout.dockerServers[card.server.uuid] = translate(card.rect, x, y);
            }
        }

        layout.size.width = Math.max(layout.size.width, x + block.width + MAP.margin);
        layout.size.height = Math.max(layout.size.height, y + block.height + MAP.margin);
        x += block.width + MAP.frameGap;
        rowHeight = Math.max(rowHeight, block.height);
    }

    return layout;
}

/**
 * The traffic lines of a laid out map: one per public application (Internet
 * node, domain label, application row) and one per firewall connection, where
 * two-way connections share a line with two arrows. In every cluster the
 * public lines come first, so firewall lines are drawn and hovered on top.
 */
export function buildMapEdges(data, layout) {
    return [...(layout.edges ?? [])];
}

function readStoredFlag(key) {
    try {
        return localStorage.getItem(key) === 'on';
    } catch {
        return false;
    }
}

function writeStoredFlag(key, value) {
    try {
        localStorage.setItem(key, value ? 'on' : 'off');
    } catch {
        // Private browsing can block storage; the layer still toggles for this page.
    }
}

export function clusterMap() {
    return {
        map: MAP,
        data: normalizeMapData(null),
        layout: layoutClusterMap(normalizeMapData(null)),
        edges: [],
        showTraffic: false,
        zoom: 1,
        viewportHeight: MINIMUM_VIEWPORT_HEIGHT,
        viewportScroll: { x: 0, y: 0 },
        hoveredEdgeId: null,
        pinnedEdgeId: null,
        panning: null,
        lastWidth: 0,
        resizeObserver: null,
        popoverTick: 0,

        init() {
            this.showTraffic = readStoredFlag(TRAFFIC_STORAGE_KEY);
            this.data = normalizeMapData(this.$wire?.map);
            this.relayout(true);
            // The details popover is measured after it renders, then placed again.
            this.$watch('activeEdge', () => this.$nextTick(() => this.popoverTick++));
            this.$watch('$wire.map', (value) => {
                this.data = normalizeMapData(value);
                this.relayout(false);
            });
            if (typeof ResizeObserver !== 'undefined' && this.$refs.viewport) {
                this.resizeObserver = new ResizeObserver(() => {
                    const width = this.$refs.viewport.clientWidth;
                    if (width && Math.abs(width - this.lastWidth) > 8) {
                        this.relayout(true);
                    }
                });
                this.resizeObserver.observe(this.$refs.viewport);
            }
        },

        destroy() {
            this.resizeObserver?.disconnect();
        },

        viewportWidth() {
            const width = this.$refs.viewport?.clientWidth ?? 0;
            if (width) {
                this.lastWidth = width;
            }

            return width || this.lastWidth || 1200;
        },

        relayout(fit) {
            const width = this.viewportWidth();
            this.layout = layoutClusterMap(this.data, {
                availableWidth: width - MAP.margin * 2,
                showTraffic: this.showTraffic,
            });
            this.edges = this.showTraffic ? buildMapEdges(this.data, this.layout) : [];
            if (!this.edges.some((edge) => edge.id === this.pinnedEdgeId)) {
                this.pinnedEdgeId = null;
            }
            if (!this.edges.some((edge) => edge.id === this.hoveredEdgeId)) {
                this.hoveredEdgeId = null;
            }
            if (fit) {
                this.fit();
            }
        },

        fit() {
            const size = this.layout.size;
            this.zoom = fitZoomToViewport(size, {
                width: this.viewportWidth(),
                height: MAXIMUM_VIEWPORT_HEIGHT,
            }, { minimum: MINIMUM_ZOOM });
            this.viewportHeight = Math.min(MAXIMUM_VIEWPORT_HEIGHT, Math.max(MINIMUM_VIEWPORT_HEIGHT, Math.ceil(size.height * this.zoom)));
            this.$refs.viewport?.scrollTo({ left: 0, top: 0 });
            this.viewportScroll = { x: 0, y: 0 };
        },

        toggleTraffic() {
            this.showTraffic = !this.showTraffic;
            writeStoredFlag(TRAFFIC_STORAGE_KEY, this.showTraffic);
            this.relayout(true);
        },

        zoomBy(amount, anchor = null) {
            const viewport = this.$refs.viewport;
            const previous = this.zoom;
            const next = Math.min(MAXIMUM_ZOOM, Math.max(MINIMUM_ZOOM, Number((previous + amount).toFixed(2))));
            if (next === previous) {
                return;
            }
            const point = anchor ?? { x: (viewport?.clientWidth ?? 0) / 2, y: (viewport?.clientHeight ?? 0) / 2 };
            this.zoom = next;
            if (viewport) {
                this.$nextTick(() => {
                    viewport.scrollLeft = (viewport.scrollLeft + point.x) * (next / previous) - point.x;
                    viewport.scrollTop = (viewport.scrollTop + point.y) * (next / previous) - point.y;
                    this.updateViewportScroll();
                });
            }
        },

        onWheel(event) {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }
            event.preventDefault();
            const rect = this.$refs.viewport.getBoundingClientRect();
            this.zoomBy(event.deltaY < 0 ? 0.1 : -0.1, { x: event.clientX - rect.left, y: event.clientY - rect.top });
        },

        startPan(event) {
            if (event.button !== 0 || event.target.closest('a, button, [data-map-edge], [data-map-popover]')) {
                return;
            }
            const viewport = this.$refs.viewport;
            this.panning = {
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                scrollLeft: viewport.scrollLeft,
                scrollTop: viewport.scrollTop,
                moved: false,
            };
            viewport.setPointerCapture?.(event.pointerId);
        },

        movePan(event) {
            if (!this.panning || this.panning.pointerId !== event.pointerId) {
                return;
            }
            const deltaX = event.clientX - this.panning.startX;
            const deltaY = event.clientY - this.panning.startY;
            if (Math.abs(deltaX) + Math.abs(deltaY) > 3) {
                this.panning.moved = true;
            }
            this.$refs.viewport.scrollLeft = this.panning.scrollLeft - deltaX;
            this.$refs.viewport.scrollTop = this.panning.scrollTop - deltaY;
        },

        finishPan() {
            if (!this.panning) {
                return;
            }
            if (!this.panning.moved) {
                this.pinnedEdgeId = null;
            }
            this.panning = null;
        },

        updateViewportScroll() {
            const viewport = this.$refs.viewport;
            this.viewportScroll = { x: viewport.scrollLeft, y: viewport.scrollTop };
        },

        frameStyle(rect) {
            return `left:${rect.x}px;top:${rect.y}px;width:${rect.width}px;height:${rect.height}px`;
        },

        relativeStyle(rect, parent) {
            return `left:${rect.x - parent.x}px;top:${rect.y - parent.y}px;width:${rect.width}px;height:${rect.height}px`;
        },

        frame(id) {
            return this.layout.frames.find((frame) => frame.id === id) ?? { x: 0, y: 0, width: 0, height: 0 };
        },

        serverRect(clusterUuid, serverUuid) {
            return this.layout.servers[`${clusterUuid}/${serverUuid}`] ?? { x: 0, y: 0, width: 0, height: 0 };
        },

        get activeEdge() {
            const id = this.pinnedEdgeId ?? this.hoveredEdgeId;

            return id ? this.edges.find((edge) => edge.id === id) ?? null : null;
        },

        isEdgeActive(edge) {
            return this.activeEdge?.id === edge.id;
        },

        isAppHighlighted(clusterUuid, appUuid) {
            const edge = this.activeEdge;

            return Boolean(edge) && edge.clusterUuid === clusterUuid && edge.endpoints.includes(`workload:${appUuid}`);
        },

        isServerHighlighted(clusterUuid, serverUuid) {
            const edge = this.activeEdge;

            return Boolean(edge) && edge.clusterUuid === clusterUuid && edge.endpoints.includes(`node:${serverUuid}`);
        },

        pinEdge(edgeId) {
            this.pinnedEdgeId = this.pinnedEdgeId === edgeId ? null : edgeId;
        },

        /** Recomputes the popover position when the page scrolls or resizes. */
        refreshPopover() {
            if (this.activeEdge) {
                this.popoverTick++;
            }
        },

        /**
         * Places the details popover in window coordinates. It is teleported to the body
         * and uses fixed positioning, so the map's scroll area cannot clip it.
         */
        popoverStyle() {
            void this.popoverTick;
            const edge = this.activeEdge;
            const viewport = this.$refs.viewport;
            if (!edge || !viewport) {
                return {};
            }
            const rect = viewport.getBoundingClientRect();
            const popover = document.querySelector('[data-map-popover]');
            const position = anchoredPopoverPosition({
                x: rect.left + edge.mid.x * this.zoom - this.viewportScroll.x,
                y: rect.top + edge.mid.y * this.zoom - this.viewportScroll.y,
            }, {
                scrollLeft: 0,
                scrollTop: 0,
                width: window.innerWidth,
                height: window.innerHeight,
            }, {
                width: popover?.offsetWidth || 288,
                height: popover?.offsetHeight || 140,
            });

            return { left: `${position.left}px`, top: `${position.top}px` };
        },
    };
}

export function initializeClusterMap() {
    window.Alpine.data('clusterMap', () => clusterMap());
}
