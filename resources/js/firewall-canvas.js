const CARD_WIDTH = 224;
const CARD_HEIGHT = 104;
const CANVAS_WIDTH = 2400;
const CANVAS_HEIGHT = 1400;

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

export function defaultFirewallPositions(nodes) {
    return Object.fromEntries(nodes.map((node, index) => [
        node.id,
        {
            x: 80 + (index % 4) * 300,
            y: 80 + Math.floor(index / 4) * 190,
        },
    ]));
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
        canvasWidth: CANVAS_WIDTH,
        canvasHeight: CANVAS_HEIGHT,
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
            const defaults = defaultFirewallPositions(this.nodes);

            try {
                this.positions = { ...defaults, ...JSON.parse(localStorage.getItem(this.storageKey) ?? '{}') };
            } catch {
                this.positions = defaults;
            }

            this.connections = groupFirewallRules(this.rules, new Set(this.nodes.map((node) => node.id)));
            this.bindConnectionEvents();
        },

        get hasWorkloadTargets() {
            return this.nodes.some((node) => node.type === 'workload');
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
                return '';
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

            return `left:${position.left}px;top:${position.top}px;right:auto;bottom:auto`;
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

            this.positions[this.dragging.nodeId] = {
                x: Math.max(0, this.dragging.originX + (event.clientX - this.dragging.startX) / this.zoom),
                y: Math.max(0, this.dragging.originY + (event.clientY - this.dragging.startY) / this.zoom),
            };
            this.positions = { ...this.positions };
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
                await this.$wire.createFirewallRule(sourceType, sourceUuid, destinationUuid, this.protocol, this.protocol === 'icmp' ? 0 : Number(this.port));
            } finally {
                this.saving = false;
            }
        },

        async removeRule(ruleUuid) {
            if (this.saving) {
                return;
            }
            this.saving = true;
            try {
                await this.$wire.removeFirewallRule(ruleUuid);
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
            this.pan = { x: 0, y: 0 };
            this.zoom = 1;
        },
    };
}

export function initializeFirewallCanvas() {
    window.Alpine.data('firewallCanvas', (config) => firewallCanvas(config));
}
