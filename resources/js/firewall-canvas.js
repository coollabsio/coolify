const CARD_WIDTH = 224;
const CARD_HEIGHT = 104;
const CANVAS_WIDTH = 2400;
const CANVAS_HEIGHT = 1400;
const CARD_GAP = 16;

function cardsOverlap(first, second, gap = CARD_GAP) {
    return first.x < second.x + CARD_WIDTH + gap
        && second.x < first.x + CARD_WIDTH + gap
        && first.y < second.y + CARD_HEIGHT + gap
        && second.y < first.y + CARD_HEIGHT + gap;
}

/**
 * Returns the free card position closest to the candidate, so a card slides
 * along other cards instead of covering them. Returns null when no free
 * position fits inside the canvas.
 */
export function resolveCardCollision(candidate, obstacles, gap = CARD_GAP) {
    const maximumX = CANVAS_WIDTH - CARD_WIDTH;
    const maximumY = CANVAS_HEIGHT - CARD_HEIGHT;
    const clamp = (value, maximum) => Math.max(0, Math.min(value, maximum));
    const start = { x: clamp(candidate.x, maximumX), y: clamp(candidate.y, maximumY) };

    if (!obstacles.some((obstacle) => cardsOverlap(start, obstacle, gap))) {
        return start;
    }

    const xs = new Set([start.x]);
    const ys = new Set([start.y]);
    for (const obstacle of obstacles) {
        xs.add(obstacle.x - CARD_WIDTH - gap);
        xs.add(obstacle.x + CARD_WIDTH + gap);
        ys.add(obstacle.y - CARD_HEIGHT - gap);
        ys.add(obstacle.y + CARD_HEIGHT + gap);
    }

    let closest = null;
    let closestDistance = Infinity;
    for (const x of xs) {
        for (const y of ys) {
            if (x < 0 || y < 0 || x > maximumX || y > maximumY) {
                continue;
            }
            const distance = (x - start.x) ** 2 + (y - start.y) ** 2;
            if (distance < closestDistance && !obstacles.some((obstacle) => cardsOverlap({ x, y }, obstacle, gap))) {
                closest = { x, y };
                closestDistance = distance;
            }
        }
    }

    return closest;
}

/**
 * Moves stored cards that overlap earlier cards to the closest free position.
 */
export function separateFirewallPositions(nodes, positions) {
    const placed = [];
    const separated = { ...positions };

    for (const node of nodes) {
        const position = resolveCardCollision(separated[node.id] ?? { x: 0, y: 0 }, placed) ?? separated[node.id];
        separated[node.id] = position;
        placed.push(position);
    }

    return separated;
}

export function groupFirewallRules(rules, nodeIds = null) {
    const connections = new Map();

    for (const rule of rules) {
        const source = `${rule.sourceType}:${rule.sourceUuid}`;
        const destination = `workload:${rule.destinationUuid}`;
        if (nodeIds && (!nodeIds.has(source) || !nodeIds.has(destination))) {
            continue;
        }

        const key = `${source}->workload:${rule.destinationUuid}`;
        const connection = connections.get(key) ?? {
            id: key,
            source,
            destination,
            rules: [],
        };

        connection.rules.push(rule);
        connections.set(key, connection);
    }

    return [...connections.values()];
}

const LAYOUT_MARGIN = 40;
const LAYOUT_COLUMN_GAP = 96;
const LAYOUT_ROW_GAP = 32;
const LAYOUT_MAX_ROWS = 4;

/**
 * Places servers in the first column, then each connected application one
 * column to the right of its furthest source, so traffic flows left to right.
 * Applications without rules are grouped in columns after the connected ones.
 */
export function autoLayoutFirewallPositions(nodes, connections = []) {
    const ids = new Set(nodes.map((node) => node.id));
    const edges = connections.filter((connection) => (
        connection.source !== connection.destination
        && ids.has(connection.source)
        && ids.has(connection.destination)
    ));
    const connected = new Set(edges.flatMap((edge) => [edge.source, edge.destination]));
    const servers = nodes.filter((node) => node.type !== 'workload');
    const linked = nodes.filter((node) => node.type === 'workload' && connected.has(node.id));
    const unlinked = nodes.filter((node) => node.type === 'workload' && !connected.has(node.id));

    // Longest path from the sources. The pass limit stops cycles from growing forever.
    const layer = new Map([
        ...servers.map((node) => [node.id, 0]),
        ...linked.map((node) => [node.id, 1]),
    ]);
    for (let pass = 0; pass < linked.length; pass++) {
        let changed = false;
        for (const edge of edges) {
            const next = layer.get(edge.source) + 1;
            if (layer.get(edge.destination) < next && next <= linked.length) {
                layer.set(edge.destination, next);
                changed = true;
            }
        }
        if (!changed) {
            break;
        }
    }

    const columns = [];
    if (servers.length) {
        columns.push(servers);
    }
    const layers = [...new Set(linked.map((node) => layer.get(node.id)))].sort((a, b) => a - b);
    for (const value of layers) {
        columns.push(linked.filter((node) => layer.get(node.id) === value));
    }
    for (let index = 0; index < unlinked.length; index += LAYOUT_MAX_ROWS) {
        columns.push(unlinked.slice(index, index + LAYOUT_MAX_ROWS));
    }

    // Order each column by the average row of its sources to reduce crossing lines.
    const row = new Map();
    const positions = {};
    columns.forEach((column, columnIndex) => {
        const sorted = [...column].sort((first, second) => sourceRow(first.id) - sourceRow(second.id));
        sorted.forEach((node, rowIndex) => {
            row.set(node.id, rowIndex);
            positions[node.id] = {
                x: LAYOUT_MARGIN + columnIndex * (CARD_WIDTH + LAYOUT_COLUMN_GAP),
                y: LAYOUT_MARGIN + rowIndex * (CARD_HEIGHT + LAYOUT_ROW_GAP),
            };
        });
    });

    function sourceRow(nodeId) {
        const rows = edges
            .filter((edge) => edge.destination === nodeId && row.has(edge.source))
            .map((edge) => row.get(edge.source));

        return rows.length ? rows.reduce((total, value) => total + value, 0) / rows.length : Infinity;
    }

    return positions;
}

/**
 * Returns the canvas size that just contains every card, so the viewport
 * does not scroll into empty space.
 */
export function firewallCanvasSize(positions) {
    const values = Object.values(positions);

    return {
        width: Math.max(0, ...values.map((position) => position.x + CARD_WIDTH + LAYOUT_MARGIN)),
        height: Math.max(0, ...values.map((position) => position.y + CARD_HEIGHT + LAYOUT_MARGIN)),
    };
}

/**
 * Returns the zoom level that shows every card inside the viewport.
 */
export function fitFirewallZoom(positions, viewport) {
    if (!Object.keys(positions).length || !viewport.width || !viewport.height) {
        return 1;
    }

    const { width, height } = firewallCanvasSize(positions);
    const zoom = Math.min(1, viewport.width / width, viewport.height / height);

    return Math.max(0.5, Math.floor(zoom * 100) / 100);
}

export function closestCardConnectionPoints(source, destination) {
    const sourceCenter = {
        x: source.x + CARD_WIDTH / 2,
        y: source.y + CARD_HEIGHT / 2,
    };
    const destinationCenter = {
        x: destination.x + CARD_WIDTH / 2,
        y: destination.y + CARD_HEIGHT / 2,
    };
    const delta = {
        x: destinationCenter.x - sourceCenter.x,
        y: destinationCenter.y - sourceCenter.y,
    };

    if (delta.x === 0 && delta.y === 0) {
        return {
            x1: source.x + CARD_WIDTH,
            y1: sourceCenter.y,
            x2: destination.x,
            y2: destinationCenter.y,
        };
    }

    const scale = 1 / Math.max(
        Math.abs(delta.x) / (CARD_WIDTH / 2),
        Math.abs(delta.y) / (CARD_HEIGHT / 2),
    );

    return {
        x1: sourceCenter.x + delta.x * scale,
        y1: sourceCenter.y + delta.y * scale,
        x2: destinationCenter.x - delta.x * scale,
        y2: destinationCenter.y - delta.y * scale,
    };
}

export function anchoredPopoverPosition(anchor, viewport, size) {
    const gap = 12;
    const minimumLeft = viewport.scrollLeft + gap;
    const maximumLeft = viewport.scrollLeft + viewport.width - size.width - gap;
    const minimumTop = viewport.scrollTop + gap;
    const maximumTop = viewport.scrollTop + viewport.height - size.height - gap;
    const preferredLeft = anchor.x + gap + size.width <= viewport.scrollLeft + viewport.width
        ? anchor.x + gap
        : anchor.x - size.width - gap;
    const preferredTop = anchor.y + gap + size.height <= viewport.scrollTop + viewport.height
        ? anchor.y + gap
        : anchor.y - size.height - gap;

    return {
        left: Math.max(minimumLeft, Math.min(preferredLeft, Math.max(minimumLeft, maximumLeft))),
        top: Math.max(minimumTop, Math.min(preferredTop, Math.max(minimumTop, maximumTop))),
    };
}

export function firewallNodeIdFromConnector(connector) {
    return connector.closest('[data-firewall-node]')?.dataset.firewallNode ?? null;
}

export function firewallCanvas(config) {
    return {
        ...config,
        cardWidth: CARD_WIDTH,
        cardHeight: CARD_HEIGHT,
        positions: {},
        connections: [],
        selectedConnectionId: null,
        draft: null,
        dragging: null,
        pan: { x: 0, y: 0 },
        zoom: 1,
        protocol: 'tcp',
        port: 80,
        saving: false,
        viewportScroll: { x: 0, y: 0 },
        editorVersion: 0,

        init() {
            this.connections = groupFirewallRules(this.rules, new Set(this.nodes.map((node) => node.id)));
            const defaults = autoLayoutFirewallPositions(this.nodes, this.connections);

            try {
                this.positions = { ...defaults, ...JSON.parse(localStorage.getItem(this.storageKey) ?? '{}') };
            } catch {
                this.positions = defaults;
            }
            this.positions = separateFirewallPositions(this.nodes, this.positions);
            this.bindConnectionEvents();
        },

        replaceRules(rules) {
            const selected = this.selectedConnection;
            this.rules = rules;
            this.connections = groupFirewallRules(rules, new Set(this.nodes.map((node) => node.id)));
            if (selected && !this.selectedConnection) {
                if (selected.rules.length === 0) {
                    this.connections.push({ ...selected, rules: [] });
                } else {
                    this.selectedConnectionId = null;
                }
            }
        },

        get canvasSize() {
            return firewallCanvasSize(Object.fromEntries(this.nodes.map((node) => [node.id, this.position(node.id)])));
        },

        get hasWorkloadTargets() {
            return this.nodes.some((node) => node.type === 'workload');
        },

        isDropTarget(nodeId) {
            return Boolean(this.draft)
                && nodeId !== this.draft.source
                && nodeId.startsWith('workload:');
        },

        canStartConnection(source) {
            return this.nodes.some((node) => node.type === 'workload' && node.id !== source);
        },

        get selectedConnection() {
            return this.connections.find((connection) => connection.id === this.selectedConnectionId) ?? null;
        },

        get relatedConnections() {
            const selected = this.selectedConnection;
            if (!selected) {
                return [];
            }

            const reverse = this.connections.find((connection) => (
                connection.source === selected.destination
                && connection.destination === selected.source
            ));

            return reverse ? [selected, reverse] : [selected];
        },

        nodeName(nodeId) {
            return this.nodes.find((node) => node.id === nodeId)?.name ?? nodeId;
        },

        connectionLabel(connection) {
            if (!connection) {
                return '';
            }

            return `${this.nodeName(connection.source)} → ${this.nodeName(connection.destination)}`;
        },

        selectConnection(connectionId) {
            this.selectedConnectionId = connectionId;
            this.$nextTick(() => this.editorVersion++);
        },

        updateViewportScroll() {
            this.viewportScroll = {
                x: this.$refs.viewport.scrollLeft,
                y: this.$refs.viewport.scrollTop,
            };
        },

        editorStyle() {
            this.editorVersion;
            const connection = this.selectedConnection;
            const viewport = this.$refs.viewport;
            if (!connection || !viewport) {
                return {};
            }

            const points = this.connectionPoints(connection);
            const anchor = {
                x: ((points.x1 + points.x2) / 2) * this.zoom + this.pan.x,
                y: ((points.y1 + points.y2) / 2) * this.zoom + this.pan.y,
            };
            const position = anchoredPopoverPosition(anchor, {
                scrollLeft: this.viewportScroll.x,
                scrollTop: this.viewportScroll.y,
                width: viewport.clientWidth,
                height: viewport.clientHeight,
            }, {
                width: this.$refs.editor?.offsetWidth || Math.min(384, viewport.clientWidth - 24),
                height: this.$refs.editor?.offsetHeight || Math.min(320, viewport.clientHeight - 24),
            });

            return { left: `${position.left}px`, top: `${position.top}px`, right: 'auto', bottom: 'auto' };
        },

        position(nodeId) {
            return this.positions[nodeId] ?? { x: 0, y: 0 };
        },

        connectionPoints(connection) {
            return closestCardConnectionPoints(
                this.position(connection.source),
                this.position(connection.destination),
            );
        },

        hasReverseConnection(connection) {
            return this.connections.some((candidate) => (
                candidate.source === connection.destination
                && candidate.destination === connection.source
            ));
        },

        isVisibleConnection(connection) {
            if (!this.hasReverseConnection(connection)) {
                return true;
            }

            const sourceX = this.position(connection.source).x;
            const destinationX = this.position(connection.destination).x;

            return sourceX === destinationX
                ? connection.source < connection.destination
                : sourceX < destinationX;
        },

        startDrag(event, nodeId) {
            if (event.target.closest('[data-connector]')) {
                return;
            }

            const position = this.position(nodeId);
            this.dragging = {
                nodeId,
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                originX: position.x,
                originY: position.y,
            };
            event.currentTarget.setPointerCapture(event.pointerId);
        },

        moveDrag(event) {
            if (!this.dragging || this.dragging.pointerId !== event.pointerId) {
                return;
            }

            const { nodeId } = this.dragging;
            const candidate = {
                x: this.dragging.originX + (event.clientX - this.dragging.startX) / this.zoom,
                y: this.dragging.originY + (event.clientY - this.dragging.startY) / this.zoom,
            };
            const obstacles = this.nodes
                .filter((node) => node.id !== nodeId)
                .map((node) => this.position(node.id));
            const position = resolveCardCollision(candidate, obstacles);
            if (position) {
                this.positions = { ...this.positions, [nodeId]: position };
            }
        },

        finishDrag() {
            if (!this.dragging) {
                return;
            }

            this.dragging = null;
            localStorage.setItem(this.storageKey, JSON.stringify(this.positions));
        },

        startConnection(event) {
            const source = firewallNodeIdFromConnector(event.currentTarget);
            if (!source) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            const point = this.pointerPoint(event);
            const sourcePosition = this.position(source);
            this.draft = {
                source,
                sourceX: sourcePosition.x + CARD_WIDTH,
                sourceY: sourcePosition.y + CARD_HEIGHT / 2,
                x: point.x,
                y: point.y,
            };
            window.addEventListener('pointermove', this.trackConnection);
            window.addEventListener('pointerup', this.finishConnection, { once: true });
        },

        trackConnection: null,
        finishConnection: null,

        bindConnectionEvents() {
            this.trackConnection = (event) => {
                if (!this.draft) {
                    return;
                }
                const point = this.pointerPoint(event);
                this.draft = { ...this.draft, x: point.x, y: point.y };
            };
            this.finishConnection = (event) => {
                window.removeEventListener('pointermove', this.trackConnection);
                const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-firewall-node]');
                const destination = target?.dataset.firewallNode;

                if (destination?.startsWith('workload:') && destination !== this.draft?.source) {
                    const existing = this.connections.find((connection) => connection.source === this.draft.source && connection.destination === destination);
                    this.selectConnection(existing?.id ?? `${this.draft.source}->${destination}`);
                    if (!existing) {
                        this.connections.push({ id: this.selectedConnectionId, source: this.draft.source, destination, rules: [] });
                    }
                }
                this.draft = null;
            };
        },

        pointerPoint(event) {
            const rect = this.$refs.viewport.getBoundingClientRect();

            return {
                x: (event.clientX - rect.left + this.$refs.viewport.scrollLeft - this.pan.x) / this.zoom,
                y: (event.clientY - rect.top + this.$refs.viewport.scrollTop - this.pan.y) / this.zoom,
            };
        },

        async addRule() {
            const connection = this.selectedConnection;
            if (!connection || this.saving) {
                return;
            }

            const [sourceType, sourceUuid] = connection.source.split(':');
            const destinationUuid = connection.destination.replace('workload:', '');
            this.saving = true;
            try {
                const rule = await this.$wire.createFirewallRule(sourceType, sourceUuid, destinationUuid, this.protocol, this.protocol === 'icmp' ? 0 : Number(this.port));
                if (rule) {
                    connection.rules = [
                        ...connection.rules.filter((existing) => existing.uuid !== rule.uuid),
                        rule,
                    ];
                    this.connections = [...this.connections];
                }
            } finally {
                this.saving = false;
            }
        },

        async removeRule(ruleUuid) {
            if (this.saving) {
                return;
            }
            const selected = this.selectedConnection;
            this.saving = true;
            try {
                await this.$wire.removeFirewallRule(ruleUuid);
                for (const connection of this.connections) {
                    connection.rules = connection.rules.filter((rule) => rule.uuid !== ruleUuid);
                }
                this.connections = this.connections.filter((connection) => connection.rules.length > 0);
                if (!this.selectedConnection && selected) {
                    this.selectedConnectionId = this.connections.find((connection) => (
                        connection.source === selected.destination
                        && connection.destination === selected.source
                    ))?.id ?? null;
                }
            } finally {
                this.saving = false;
            }
        },

        closeEditor() {
            if (this.selectedConnection?.rules.length === 0) {
                this.connections = this.connections.filter((connection) => connection.id !== this.selectedConnectionId);
            }
            this.selectedConnectionId = null;
        },

        zoomBy(amount) {
            this.zoom = Math.min(1.6, Math.max(0.5, Number((this.zoom + amount).toFixed(2))));
        },

        resetView() {
            const viewport = this.$refs.viewport;
            this.positions = autoLayoutFirewallPositions(this.nodes, this.connections);
            localStorage.setItem(this.storageKey, JSON.stringify(this.positions));
            this.pan = { x: 0, y: 0 };
            this.zoom = fitFirewallZoom(this.positions, { width: viewport.clientWidth, height: viewport.clientHeight });
            viewport.scrollTo({ left: 0, top: 0 });
            this.selectedConnectionId = null;
        },
    };
}

export function initializeFirewallCanvas() {
    window.Alpine.data('firewallCanvas', (config) => firewallCanvas(config));
}
