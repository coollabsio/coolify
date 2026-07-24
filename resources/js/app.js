import { initializeTerminalComponent } from './terminal.js';

// Livewire 3.5.19+ re-applies `x-cloak` to morphed elements during wire:navigate
// (via replaceHtmlAttributes). With `[x-cloak]{display:none}` on the app wrapper,
// this blanks the whole page on every navigation until Alpine re-processes it.
// Strip leftover x-cloak after each navigation; the initial-load FOUC guard stays.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[x-cloak]').forEach((el) => el.removeAttribute('x-cloak'));
});

['livewire:navigated', 'alpine:init'].forEach((event) => {
    document.addEventListener(event, () => {
        // tree-shaking
        if (document.getElementById('terminal-container')) {
            initializeTerminalComponent()
        }
    });
});

// Railway architecture canvas: pan / zoom / drag engine for the node graph.
// Registered on alpine:init so it is available before any canvas element is
// processed, and it survives wire:navigate (Alpine.data persists globally).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwCanvas', (config) => ({
        meta: {},
        positions: {},
        edges: [],
        nodeW: config.nodeW || 240,
        nodeH: config.nodeH || 82,
        scale: 1,
        tx: 40,
        ty: 40,
        selected: null,
        panning: false,
        dragging: null,
        movedDuringDrag: false,
        ctxMenu: { open: false, x: 0, y: 0 },
        last: { x: 0, y: 0 },
        storeKey: 'rwpos:' + config.envUuid,

        init() {
            this.edges = config.edges || [];
            const saved = this.loadLocal();
            const auto = this.autoLayout(config.nodes);
            (config.nodes || []).forEach((n) => {
                this.meta[n.id] = { id: n.id };
                let x = n.x, y = n.y;
                if (x === null || x === undefined) {
                    const s = saved[n.id];
                    if (s) { x = s.x; y = s.y; } else { x = auto[n.id].x; y = auto[n.id].y; }
                }
                this.positions[n.id] = { x: Math.round(x), y: Math.round(y) };
            });
            this.$nextTick(() => this.fitView());
        },

        autoLayout(nodes) {
            const out = {};
            const cols = Math.max(1, Math.ceil(Math.sqrt((nodes || []).length)));
            const sx = 300, sy = 150;
            (nodes || []).forEach((n, i) => {
                const col = i % cols;
                const row = Math.floor(i / cols);
                out[n.id] = { x: col * sx, y: row * sy + (col % 2) * 36 };
            });
            return out;
        },
        loadLocal() {
            try { return JSON.parse(localStorage.getItem(this.storeKey) || '{}'); }
            catch (e) { return {}; }
        },
        persist(id) {
            const p = this.positions[id];
            if (!p) return;
            try {
                const all = this.loadLocal();
                all[id] = p;
                localStorage.setItem(this.storeKey, JSON.stringify(all));
            } catch (e) {}
            this.$wire.savePosition(id, p.x, p.y);
        },

        transformStyle() {
            return `transform: translate(${this.tx}px, ${this.ty}px) scale(${this.scale});`;
        },
        posStyle(id) {
            const p = this.positions[id] || { x: 0, y: 0 };
            return `left: ${p.x}px; top: ${p.y}px;`;
        },
        edgePath(edge) {
            const a = this.positions[edge.source], b = this.positions[edge.target];
            if (!a || !b) return '';
            const sx = a.x + this.nodeW, sy = a.y + this.nodeH / 2;
            const tx = b.x, ty = b.y + this.nodeH / 2;
            const mx = (sx + tx) / 2;
            return `M ${sx} ${sy} H ${mx} V ${ty} H ${tx}`;
        },
        // Combined path for all edges — a single <path> avoids the SVG + <template x-for>
        // scoping quirk (Alpine can't resolve the loop var inside foreign SVG content).
        edgesPath() {
            return (this.edges || []).map((e) => this.edgePath(e)).filter(Boolean).join(' ');
        },

        startPan(e) {
            this.panning = true;
            this.ctxMenu.open = false;
            this.last = { x: e.clientX, y: e.clientY };
        },
        openContextMenu(e) {
            const rect = this.$root.getBoundingClientRect();
            this.ctxMenu = {
                open: true,
                x: Math.min(e.clientX - rect.left, rect.width - 260),
                y: Math.min(e.clientY - rect.top, rect.height - 340),
            };
        },
        closeContextMenu() {
            this.ctxMenu.open = false;
        },
        startDrag(e, id) {
            this.dragging = id;
            this.selected = id;
            this.movedDuringDrag = false;
            this.last = { x: e.clientX, y: e.clientY };
        },
        onMove(e) {
            if (this.dragging) {
                const dx = (e.clientX - this.last.x) / this.scale;
                const dy = (e.clientY - this.last.y) / this.scale;
                if (Math.abs(e.clientX - this.last.x) + Math.abs(e.clientY - this.last.y) > 3) {
                    this.movedDuringDrag = true;
                }
                const p = this.positions[this.dragging];
                this.positions[this.dragging] = { x: p.x + dx, y: p.y + dy };
                this.last = { x: e.clientX, y: e.clientY };
            } else if (this.panning) {
                this.tx += e.clientX - this.last.x;
                this.ty += e.clientY - this.last.y;
                this.last = { x: e.clientX, y: e.clientY };
            }
        },
        endInteraction() {
            if (this.dragging) {
                const id = this.dragging;
                this.positions[id] = { x: Math.round(this.positions[id].x), y: Math.round(this.positions[id].y) };
                if (this.movedDuringDrag) this.persist(id);
                this.dragging = null;
            }
            this.panning = false;
        },
        openNode(id, kind) {
            this.ctxMenu.open = false;
            if (this.movedDuringDrag) { this.movedDuringDrag = false; return; }
            this.selected = id;
            window.Livewire.dispatch('openServicePanel', { uuid: id, kind: kind });
        },

        onWheel(e) {
            const factor = e.deltaY < 0 ? 1.1 : 1 / 1.1;
            this.zoomAt(e.offsetX, e.offsetY, factor);
        },
        zoomBy(factor) {
            const rect = this.$root.getBoundingClientRect();
            this.zoomAt(rect.width / 2, rect.height / 2, factor);
        },
        zoomAt(cx, cy, factor) {
            const next = Math.min(2.5, Math.max(0.2, this.scale * factor));
            const k = next / this.scale;
            this.tx = cx - (cx - this.tx) * k;
            this.ty = cy - (cy - this.ty) * k;
            this.scale = next;
        },
        resetView() { this.scale = 1; this.tx = 40; this.ty = 40; },
        fitView() {
            const ids = Object.keys(this.positions);
            if (!ids.length) return;
            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            ids.forEach((id) => {
                const p = this.positions[id];
                minX = Math.min(minX, p.x); minY = Math.min(minY, p.y);
                maxX = Math.max(maxX, p.x + this.nodeW); maxY = Math.max(maxY, p.y + this.nodeH);
            });
            const rect = this.$root.getBoundingClientRect();
            const pad = 80;
            const sw = (rect.width - pad * 2) / Math.max(1, maxX - minX);
            const sh = (rect.height - pad * 2) / Math.max(1, maxY - minY);
            this.scale = Math.min(1.1, Math.max(0.3, Math.min(sw, sh)));
            this.tx = pad - minX * this.scale + (rect.width - pad * 2 - (maxX - minX) * this.scale) / 2;
            this.ty = pad - minY * this.scale + (rect.height - pad * 2 - (maxY - minY) * this.scale) / 2;
        },
    }));
});
