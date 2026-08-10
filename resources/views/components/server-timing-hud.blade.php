{{-- Dev-only Server-Timing HUD. Injected by AddServerTimingHeaders on full HTML responses.
     JS docks into the top bar: #server-timing-hud-slot (lg+) or #server-timing-hud-slot-mobile (<lg);
     floats bottom-left only if no navbar slot is available. --}}
<style data-server-timing-hud-styles>
    #server-timing-hud {
        --sth-background: rgba(255, 255, 255, .96);
        --sth-text: #18181b;
        --sth-strong: #09090b;
        --sth-secondary: #52525b;
        --sth-muted: #71717a;
        --sth-border: rgba(0, 0, 0, .14);
        --sth-surface: rgba(0, 0, 0, .035);
        --sth-shadow: 0 12px 32px rgba(0, 0, 0, .18);
        --sth-scrollbar-track: rgba(0, 0, 0, .06);
        --sth-scrollbar-thumb: #a1a1aa;
        --sth-livewire: #0284c7;
        --sth-xhr: #9333ea;
        --sth-document: #4d7c0f;
    }

    html.dark #server-timing-hud {
        --sth-background: rgba(16, 16, 16, .96);
        --sth-text: #e5e5e5;
        --sth-strong: #fafafa;
        --sth-secondary: #a3a3a3;
        --sth-muted: #737373;
        --sth-border: rgba(255, 255, 255, .12);
        --sth-surface: rgba(255, 255, 255, .04);
        --sth-shadow: 0 12px 32px rgba(0, 0, 0, .4);
        --sth-scrollbar-track: rgba(255, 255, 255, .06);
        --sth-scrollbar-thumb: #71717a;
        --sth-livewire: #38bdf8;
        --sth-xhr: #c084fc;
        --sth-document: #a3e635;
    }

    html[data-theme="custom"] #server-timing-hud {
        --sth-background: var(--color-surface);
        --sth-text: var(--color-fg-dim);
        --sth-strong: var(--color-fg);
        --sth-secondary: var(--color-fg-dim);
        --sth-muted: var(--color-fg-faint);
        --sth-border: var(--coollabs-line);
        --sth-surface: var(--color-raised);
        --sth-scrollbar-track: var(--color-panel);
        --sth-scrollbar-thumb: var(--color-selected);
        --sth-livewire: var(--theme-bright-color);
        --sth-xhr: var(--theme-bright-color);
    }

    #server-timing-hud [data-sth-log] {
        scrollbar-color: var(--sth-scrollbar-thumb) var(--sth-scrollbar-track);
        scrollbar-width: thin;
    }

    #server-timing-hud [data-sth-log]::-webkit-scrollbar {
        width: 8px;
    }

    #server-timing-hud [data-sth-log]::-webkit-scrollbar-thumb {
        border-radius: 9999px;
        background: var(--sth-scrollbar-thumb);
    }

    #server-timing-hud [data-sth-log]::-webkit-scrollbar-track {
        border-radius: 9999px;
        background: var(--sth-scrollbar-track);
    }
</style>
<div id="server-timing-hud" data-server-timing-hud data-metrics='@json($metrics)' data-path="{{ $path }}"
    data-sth-mode="float"
    style="position:fixed;bottom:12px;left:12px;z-index:2147483000;font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:var(--sth-text);pointer-events:auto">
    <div data-sth-shell style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;position:relative">
        <button type="button" data-sth-toggle aria-expanded="false" aria-controls="server-timing-hud-panel"
            style="border:1px solid var(--sth-border);background:var(--sth-background);color:var(--sth-strong);border-radius:999px;padding:5px 9px;cursor:pointer;box-shadow:var(--sth-shadow);backdrop-filter:blur(8px);user-select:none;white-space:nowrap;line-height:1.2"
            title="Show/hide Server-Timing history">
            <span data-sth-summary>ST …</span>
        </button>
        <div id="server-timing-hud-panel" data-sth-panel hidden
            style="width:min(420px,calc(100vw - 24px));border:1px solid var(--sth-border);background:var(--sth-background);color:var(--sth-text);border-radius:12px;padding:10px 12px;box-shadow:var(--sth-shadow);backdrop-filter:blur(10px);position:absolute;right:0;bottom:calc(100% + 6px);z-index:2147483001">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">
                <strong style="font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--sth-secondary)">Server Timing</strong>
                <div style="display:flex;align-items:center;gap:8px">
                    <span data-sth-count style="font-size:10px;color:var(--sth-muted)"></span>
                    <button type="button" data-sth-clear
                        style="border:0;background:transparent;color:var(--sth-secondary);cursor:pointer;font:inherit;font-size:10px;padding:0;text-decoration:underline"
                        title="Clear request log">Clear</button>
                </div>
            </div>
            <div data-sth-log
                style="max-height:min(50vh,420px);overflow:auto;display:flex;flex-direction:column;gap:6px;margin:0;padding:0"></div>
            <p style="margin:8px 0 0;font-size:10px;color:var(--sth-muted)">Local only · click row to copy AI-ready dump · pill toggles</p>
        </div>
    </div>
</div>
<script data-navigate-once>
(function () {
    if (window.__coolifyServerTimingHud) {
        return;
    }
    window.__coolifyServerTimingHud = true;

    const STORAGE_KEY = 'coolify.serverTimingHud.open';
    const ENABLED_STORAGE_KEY = 'coolify.serverTimingHud.enabled';
    const MAX_ENTRIES = 50;
    const MS_KEYS = new Set(['app', 'db', 'php', 'dbslow']);

    /** @type {Array<{id:number,at:number,path:string,method:string,kind:string,metrics:Record<string,number>}>} */
    const history = window.__coolifyServerTimingHistory || (window.__coolifyServerTimingHistory = []);
    let seq = window.__coolifyServerTimingSeq || 0;
    let isOpen = localStorage.getItem(STORAGE_KEY) === '1';

    function qs(root, sel) {
        return root ? root.querySelector(sel) : null;
    }

    function rootEl() {
        return document.getElementById('server-timing-hud');
    }

    function isHudEnabled() {
        return localStorage.getItem(ENABLED_STORAGE_KEY) !== '0';
    }

    function applyVisibilityPreference() {
        const root = rootEl();
        if (!root) {
            return;
        }

        const enabled = isHudEnabled();
        root.style.display = enabled ? 'block' : 'none';

        const slot = root.parentElement?.matches('[data-server-timing-hud-slot]') ? root.parentElement : null;
        if (slot) {
            slot.style.display = enabled ? 'flex' : 'none';
        }
    }

    function pickSlot() {
        const desktop = document.getElementById('server-timing-hud-slot');
        const mobile = document.getElementById('server-timing-hud-slot-mobile');
        // Match Tailwind `lg` (1024px): desktop top bar vs mobile top bar.
        const isLg = window.matchMedia('(min-width: 1024px)').matches;
        if (isLg && desktop) {
            return desktop;
        }
        if (!isLg && mobile) {
            return mobile;
        }
        // Fallback if one breakpoint's slot is missing from the shell.
        return desktop || mobile || null;
    }

    function applyDockedStyles(root) {
        const isMobileSlot = root.parentElement && root.parentElement.id === 'server-timing-hud-slot-mobile';
        root.setAttribute('data-sth-mode', 'docked');
        root.style.cssText = [
            'position:relative',
            'bottom:auto',
            'left:auto',
            'top:auto',
            'right:auto',
            'z-index:60',
            'display:block',
            'flex-shrink:1',
            'min-width:0',
            'max-width:100%',
            'visibility:visible',
            'opacity:1',
            isMobileSlot ? 'margin-right:4px' : 'margin-right:8px',
            'font:11px/1.3 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
            'color:var(--sth-text)',
            'pointer-events:auto',
        ].join(';');

        const toggle = qs(root, '[data-sth-toggle]');
        if (toggle) {
            // Keep the navbar pill compact at every breakpoint; details stay in the panel.
            toggle.style.padding = '4px 8px';
            toggle.style.fontSize = '11px';
            toggle.style.maxWidth = 'none';
            toggle.style.overflow = 'visible';
            toggle.style.whiteSpace = 'nowrap';
        }

        const panel = qs(root, '[data-sth-panel]');
        if (panel) {
            // Dropdown below the pill inside the top bar.
            panel.style.position = 'absolute';
            panel.style.right = '0';
            panel.style.left = 'auto';
            panel.style.top = 'calc(100% + 8px)';
            panel.style.bottom = 'auto';
            panel.style.zIndex = '2147483001';
            if (isMobileSlot) {
                // Keep panel on-screen: open near the right edge of the viewport.
                panel.style.width = 'min(420px, calc(100vw - 16px))';
            }
        }
    }

    function applyFloatStyles(root) {
        root.setAttribute('data-sth-mode', 'float');
        // Bottom-left: toasts are bottom-right.
        root.style.cssText = [
            'position:fixed',
            'bottom:12px',
            'left:12px',
            'top:auto',
            'right:auto',
            'z-index:2147483000',
            'display:block',
            'visibility:visible',
            'opacity:1',
            'font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
            'color:var(--sth-text)',
            'pointer-events:auto',
        ].join(';');

        const panel = qs(root, '[data-sth-panel]');
        if (panel) {
            // Panel opens upward from bottom-left float.
            panel.style.position = 'absolute';
            panel.style.right = 'auto';
            panel.style.left = '0';
            panel.style.top = 'auto';
            panel.style.bottom = 'calc(100% + 6px)';
            panel.style.zIndex = '2147483001';
        }
    }

    function revealSlot(slot) {
        if (!slot) {
            return;
        }
        // Inline display beats Tailwind `hidden` (and any !important-less utility order issues).
        slot.classList.remove('hidden');
        slot.classList.add('flex');
        slot.style.display = 'flex';
        slot.style.alignItems = 'center';
        slot.style.flexShrink = '0';
        slot.style.visibility = 'visible';
    }

    function hideEmptySlots(except) {
        document.querySelectorAll('[data-server-timing-hud-slot]').forEach((el) => {
            if (el === except) {
                return;
            }
            if (!el.querySelector('[data-server-timing-hud]')) {
                el.classList.add('hidden');
                el.classList.remove('flex');
                el.style.display = '';
            }
        });
    }

    function isEffectivelyHidden(el) {
        if (!el || !el.isConnected) {
            return true;
        }
        let node = el;
        while (node && node.nodeType === 1) {
            const style = window.getComputedStyle(node);
            if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
                return true;
            }
            node = node.parentElement;
        }
        const rect = el.getBoundingClientRect();
        return rect.width === 0 && rect.height === 0;
    }

    /**
     * Move the HUD into the main top bar slot when the app shell is present.
     * Dedupes reinjected nodes after full page / Livewire navigations.
     * Falls back to floating if the slot (or its ancestors, e.g. x-cloak) still hide it.
     */
    function dockHud() {
        const nodes = Array.from(document.querySelectorAll('[data-server-timing-hud]'));
        if (!nodes.length) {
            return null;
        }
        // Prefer a node that already has metrics / is the canonical id.
        let keep = document.getElementById('server-timing-hud') || nodes[0];
        nodes.forEach((node) => {
            if (node !== keep) {
                node.remove();
            }
        });
        if (!keep.id) {
            keep.id = 'server-timing-hud';
        }

        const slot = pickSlot();
        if (slot) {
            if (keep.parentElement !== slot) {
                slot.appendChild(keep);
            }
            revealSlot(slot);
            hideEmptySlots(slot);
            applyDockedStyles(keep);

            // If still hidden (x-cloak shell not ready, etc.), float until visible.
            if (isEffectivelyHidden(keep) || isEffectivelyHidden(slot)) {
                if (keep.parentElement === slot) {
                    document.body.appendChild(keep);
                }
                hideEmptySlots(null);
                applyFloatStyles(keep);
            }
        } else {
            if (keep.parentElement !== document.body) {
                document.body.appendChild(keep);
            }
            hideEmptySlots(null);
            applyFloatStyles(keep);
        }
        applyOpenState();
        applyVisibilityPreference();
        return keep;
    }

    function setOpen(next) {
        isOpen = !!next;
        localStorage.setItem(STORAGE_KEY, isOpen ? '1' : '0');
        applyOpenState();
    }

    function toggleOpen() {
        setOpen(!isOpen);
    }

    function applyOpenState() {
        const root = rootEl();
        if (!root) {
            return;
        }
        const panel = qs(root, '[data-sth-panel]');
        const toggle = qs(root, '[data-sth-toggle]');
        if (!panel || !toggle) {
            return;
        }
        if (isOpen) {
            panel.hidden = false;
            panel.style.display = 'block';
        } else {
            panel.hidden = true;
            panel.style.display = 'none';
        }
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function formatValue(name, value) {
        if (MS_KEYS.has(name)) {
            return Number(value).toFixed(1) + ' ms';
        }
        if (name === 'html') {
            const n = Number(value);
            if (n >= 1024 * 1024) {
                return (n / (1024 * 1024)).toFixed(2) + ' MB';
            }
            if (n >= 1024) {
                return (n / 1024).toFixed(1) + ' KB';
            }
            return n + ' B';
        }
        if (name === 'mem') {
            return Number(value).toFixed(1) + ' MB';
        }
        if (name === 'queries') {
            return String(Math.round(Number(value)));
        }
        return String(value);
    }

    function parseServerTiming(header) {
        if (!header) {
            return {};
        }
        const out = {};
        header.split(',').forEach((part) => {
            const chunks = part.trim().split(';');
            const name = (chunks.shift() || '').trim();
            if (!name) {
                return;
            }
            let dur = null;
            chunks.forEach((chunk) => {
                const [k, ...rest] = chunk.trim().split('=');
                if (k === 'dur') {
                    dur = parseFloat(rest.join('='));
                }
            });
            if (dur !== null && !Number.isNaN(dur)) {
                out[name] = dur;
            }
        });
        return out;
    }

    function metricsFromDom() {
        const node = rootEl();
        if (!node) {
            return null;
        }
        try {
            const metrics = JSON.parse(node.getAttribute('data-metrics') || '{}');
            if (!metrics || !Object.keys(metrics).length) {
                return null;
            }
            return {
                metrics,
                path: node.getAttribute('data-path') || window.location.pathname,
            };
        } catch (e) {
            return null;
        }
    }

    function classify(path, method) {
        const p = (path || '').toLowerCase();
        const m = (method || 'GET').toUpperCase();
        if (p.includes('/livewire/') || p.includes('livewire/update') || p.includes('livewire/upload')) {
            return 'livewire';
        }
        if (m !== 'GET' && m !== 'HEAD') {
            return 'xhr';
        }
        return 'document';
    }

    function kindColor(kind) {
        if (kind === 'livewire') {
            return 'var(--sth-livewire)';
        }
        if (kind === 'xhr') {
            return 'var(--sth-xhr)';
        }
        return 'var(--sth-document)';
    }

    function formatTime(ts) {
        const d = new Date(ts);
        return d.toLocaleTimeString(undefined, { hour12: false }) +
            '.' + String(d.getMilliseconds()).padStart(3, '0');
    }

    function formatEntryForAi(entry) {
        const m = entry.metrics || {};
        const app = Number(m.app) || 0;
        const db = Number(m.db) || 0;
        const php = Number(m.php) || 0;
        const dbslow = Number(m.dbslow) || 0;
        const queries = m.queries !== undefined ? Math.round(Number(m.queries)) : null;
        const html = m.html !== undefined ? Math.round(Number(m.html)) : null;
        const mem = m.mem !== undefined ? Number(m.mem) : null;
        const dbPct = app > 0 ? ((db / app) * 100).toFixed(1) : '0.0';
        const phpPct = app > 0 ? ((php / app) * 100).toFixed(1) : '0.0';
        const index = history.findIndex((e) => e.id === entry.id);
        const position = index >= 0 ? (index + 1) + ' of ' + history.length + ' (newest first)' : 'n/a';

        const lines = [
            '## Coolify Server-Timing debug dump',
            '',
            'Use this to investigate a slow or expensive HTTP/Livewire response in Coolify (Laravel).',
            '',
            '### Selected request',
            '- **Captured at:** ' + new Date(entry.at).toISOString() + ' (local ' + formatTime(entry.at) + ')',
            '- **Page URL:** ' + window.location.href,
            '- **Request:** `' + entry.method + ' ' + entry.path + '`',
            '- **Kind:** ' + entry.kind + ' (`document` = full page, `livewire` = Livewire update, `xhr` = other non-GET)',
            '- **History position:** ' + position,
            '- **User-Agent:** ' + navigator.userAgent,
            '',
            '### Server-Timing metrics',
            '| Metric | Value | Meaning |',
            '| --- | --- | --- |',
            '| app | ' + app.toFixed(2) + ' ms | Total PHP request time (middleware → response) |',
            '| db | ' + db.toFixed(2) + ' ms (' + dbPct + '% of app) | Sum of SQL query times |',
            '| php | ' + php.toFixed(2) + ' ms (' + phpPct + '% of app) | App time excluding measured DB time |',
            '| dbslow | ' + dbslow.toFixed(2) + ' ms | Slowest single query |',
            '| queries | ' + (queries === null ? 'n/a' : String(queries)) + ' | Number of SQL queries |',
            '| html | ' + (html === null ? 'n/a' : html + ' bytes (' + formatValue('html', html) + ')') + ' | Response body size before HUD inject |',
            '| mem | ' + (mem === null ? 'n/a' : mem.toFixed(2) + ' MB') + ' | Peak PHP memory |',
            '',
            '### Quick interpretation',
        ];

        if (app >= 500) {
            lines.push('- **Slow overall:** app ≥ 500ms.');
        } else if (app >= 200) {
            lines.push('- Moderate server time (200–500ms).');
        } else {
            lines.push('- Server time is relatively low (<200ms); if the UI still feels slow, check client assets (Vite/CSS/JS) and browser work.');
        }

        if (php > db * 2 && php >= 100) {
            lines.push('- **PHP-bound:** most time is outside SQL (views, Livewire, layout, serialization).');
        } else if (db > php && db >= 50) {
            lines.push('- **DB-bound:** most time is SQL; look for N+1, heavy withCount, missing indexes.');
        } else {
            lines.push('- Mix of PHP and DB cost; compare php vs db percentages above.');
        }

        if (queries !== null && queries >= 40) {
            lines.push('- **High query count** (≥40); likely N+1 or duplicate work across layout components.');
        }
        if (dbslow >= 20) {
            lines.push('- **Slow query present** (dbslow ≥ 20ms); optimize that query path.');
        }
        if (html !== null && html >= 300000) {
            lines.push('- **Large HTML** (≥300KB); layout/SVG/Livewire snapshot bloat can dominate transfer and parse time.');
        }
        if (entry.kind === 'livewire') {
            lines.push('- This is a Livewire request; full-page layout cost may be lower than a document load, but component mount/render still runs.');
        }
        if (entry.kind === 'document') {
            lines.push('- Full document response includes layout shell (navbar, global-search, settings-dropdown, etc.), not only the page component.');
        }

        lines.push('', '### Recent request log (context, newest first)');
        history.slice(0, 15).forEach((e, i) => {
            const mark = e.id === entry.id ? ' ← selected' : '';
            const ea = e.metrics.app !== undefined ? Number(e.metrics.app).toFixed(1) + 'ms' : '?';
            const eq = e.metrics.queries !== undefined ? Math.round(Number(e.metrics.queries)) + 'q' : '?q';
            const edb = e.metrics.db !== undefined ? Number(e.metrics.db).toFixed(1) + 'ms db' : '?db';
            lines.push(
                (i + 1) + '. `' + e.method + ' ' + e.path + '` · ' + e.kind +
                ' · ' + ea + ' · ' + edb + ' · ' + eq +
                ' · ' + new Date(e.at).toISOString() + mark
            );
        });

        lines.push(
            '',
            '### Raw selected metrics (JSON)',
            '```json',
            JSON.stringify({
                at: new Date(entry.at).toISOString(),
                method: entry.method,
                path: entry.path,
                kind: entry.kind,
                page_url: window.location.href,
                metrics: entry.metrics,
            }, null, 2),
            '```',
            '',
            '### Notes for the assistant',
            '- Values come from Coolify dev Server-Timing middleware (`app.server_timing`).',
            '- `queries`/`html`/`mem` use Server-Timing `dur` as the metric value (not milliseconds for those three).',
            '- Client-side cost (Vite CSS/JS, paint, Livewire morph) is NOT included in `app`.',
            ''
        );

        return lines.join('\n');
    }

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }

    function flashCard(card, ok) {
        if (!card) {
            return;
        }
        const prev = card.style.outline;
        card.style.outline = ok ? '1px solid #4ade80' : '1px solid #f87171';
        const hint = card.querySelector('[data-sth-copy-hint]');
        if (hint) {
            hint.textContent = ok ? 'Copied AI dump' : 'Copy failed';
            hint.style.color = ok ? '#4ade80' : '#f87171';
        }
        setTimeout(() => {
            card.style.outline = prev || '';
            if (hint) {
                hint.textContent = 'Click to copy';
                hint.style.color = 'var(--sth-muted)';
            }
        }, 1200);
    }

    async function copyEntryById(id) {
        const entry = history.find((e) => e.id === id);
        const root = rootEl();
        const card = root ? root.querySelector('[data-sth-entry-id="' + id + '"]') : null;
        if (!entry) {
            return;
        }
        try {
            await copyText(formatEntryForAi(entry));
            flashCard(card, true);
            if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                try {
                    window.Livewire.dispatch('success', 'Server-Timing dump copied');
                } catch (e) {}
            }
        } catch (e) {
            flashCard(card, false);
        }
    }

    function pushEntry({ metrics, path, method, kind }) {
        if (!metrics || !Object.keys(metrics).length) {
            return;
        }

        const last = history[0];
        if (last && (Date.now() - last.at) < 80) {
            const samePath = last.path === path;
            const sameApp = last.metrics.app === metrics.app && last.metrics.queries === metrics.queries;
            if (samePath && sameApp) {
                return;
            }
        }

        seq += 1;
        window.__coolifyServerTimingSeq = seq;
        history.unshift({
            id: seq,
            at: Date.now(),
            path: path || window.location.pathname,
            method: (method || 'GET').toUpperCase(),
            kind: kind || classify(path, method),
            metrics,
        });
        while (history.length > MAX_ENTRIES) {
            history.pop();
        }
        paint();
    }

    function paint() {
        const root = rootEl();
        if (!root) {
            return;
        }

        applyOpenState();

        const log = qs(root, '[data-sth-log]');
        const summary = qs(root, '[data-sth-summary]');
        const countEl = qs(root, '[data-sth-count]');
        if (!log || !summary) {
            return;
        }

        const latest = history[0];
        if (!latest) {
            summary.textContent = 'ST —';
            if (countEl) {
                countEl.textContent = '0 requests';
            }
            log.innerHTML = '<div style="color:var(--sth-muted);font-size:11px;padding:8px 0">No requests yet</div>';
            return;
        }

        const app = latest.metrics.app !== undefined ? Number(latest.metrics.app).toFixed(0) + 'ms' : '—';
        const q = latest.metrics.queries !== undefined ? Math.round(Number(latest.metrics.queries)) + 'q' : '—';
        const db = latest.metrics.db !== undefined ? Number(latest.metrics.db).toFixed(0) + 'ms db' : '—';
        const n = history.length;
        // Navbar pills show only total app time; full breakdown lives in the panel.
        const compactSummary = root.getAttribute('data-sth-mode') === 'docked';
        summary.textContent = compactSummary
            ? app
            : ('ST ' + app + ' · ' + db + ' · ' + q + (n > 1 ? ' · ×' + n : ''));
        if (countEl) {
            countEl.textContent = n + (n === 1 ? ' request' : ' requests');
        }

        const prevScrollTop = log.scrollTop;
        const prevScrollHeight = log.scrollHeight;

        log.innerHTML = '';
        history.forEach((entry, index) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.setAttribute('data-sth-entry-id', String(entry.id));
            card.title = 'Click to copy AI-ready debug details';
            card.style.display = 'block';
            card.style.width = '100%';
            card.style.textAlign = 'left';
            card.style.cursor = 'pointer';
            card.style.border = '1px solid var(--sth-border)';
            card.style.borderRadius = '8px';
            card.style.padding = '8px';
            card.style.background = index === 0 ? 'var(--sth-surface)' : 'transparent';
            card.style.color = 'inherit';
            card.style.font = 'inherit';

            const head = document.createElement('div');
            head.style.display = 'flex';
            head.style.alignItems = 'center';
            head.style.gap = '6px';
            head.style.marginBottom = '6px';
            head.style.flexWrap = 'wrap';

            const kind = document.createElement('span');
            kind.textContent = entry.kind;
            kind.style.color = kindColor(entry.kind);
            kind.style.fontSize = '10px';
            kind.style.textTransform = 'uppercase';
            kind.style.letterSpacing = '.04em';

            const time = document.createElement('span');
            time.textContent = formatTime(entry.at);
            time.style.color = 'var(--sth-muted)';
            time.style.fontSize = '10px';

            const method = document.createElement('span');
            method.textContent = entry.method;
            method.style.color = 'var(--sth-secondary)';
            method.style.fontSize = '10px';

            const path = document.createElement('span');
            path.textContent = entry.path;
            path.title = entry.path;
            path.style.color = 'var(--sth-text)';
            path.style.fontSize = '10px';
            path.style.overflow = 'hidden';
            path.style.textOverflow = 'ellipsis';
            path.style.whiteSpace = 'nowrap';
            path.style.flex = '1';
            path.style.minWidth = '0';

            const copyHint = document.createElement('span');
            copyHint.setAttribute('data-sth-copy-hint', '');
            copyHint.textContent = 'Click to copy';
            copyHint.style.fontSize = '10px';
            copyHint.style.color = 'var(--sth-muted)';
            copyHint.style.marginLeft = 'auto';

            head.appendChild(kind);
            head.appendChild(time);
            head.appendChild(method);
            head.appendChild(path);
            head.appendChild(copyHint);
            card.appendChild(head);

            const grid = document.createElement('div');
            grid.style.display = 'grid';
            grid.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
            grid.style.gap = '2px 10px';
            grid.style.fontSize = '11px';

            const order = ['app', 'db', 'php', 'dbslow', 'queries', 'html', 'mem'];
            const labels = {
                app: 'total',
                db: 'db',
                php: 'php',
                dbslow: 'slow q',
                queries: 'queries',
                html: 'html',
                mem: 'mem',
            };
            order.forEach((name) => {
                if (entry.metrics[name] === undefined) {
                    return;
                }
                const row = document.createElement('div');
                row.style.display = 'flex';
                row.style.justifyContent = 'space-between';
                row.style.gap = '8px';
                const k = document.createElement('span');
                k.textContent = labels[name] || name;
                k.style.color = 'var(--sth-muted)';
                const v = document.createElement('span');
                v.textContent = formatValue(name, entry.metrics[name]);
                v.style.color = 'var(--sth-strong)';
                if (name === 'app' && Number(entry.metrics[name]) >= 500) {
                    v.style.color = '#fbbf24';
                }
                if (name === 'queries' && Number(entry.metrics[name]) >= 40) {
                    v.style.color = '#fbbf24';
                }
                row.appendChild(k);
                row.appendChild(v);
                grid.appendChild(row);
            });
            card.appendChild(grid);
            log.appendChild(card);
        });

        if (prevScrollHeight > 0) {
            log.scrollTop = Math.max(0, prevScrollTop + (log.scrollHeight - prevScrollHeight));
        }
    }

    function applyFromHeader(header, path, method) {
        const metrics = parseServerTiming(header);
        if (!Object.keys(metrics).length) {
            return;
        }
        pushEntry({
            metrics,
            path,
            method: method || 'GET',
            kind: classify(path, method),
        });
    }

    function requestMeta(args) {
        let path = window.location.pathname;
        let method = 'GET';
        const input = args[0];
        const init = args[1] || {};
        if (typeof input === 'string') {
            try {
                path = new URL(input, window.location.origin).pathname;
            } catch (e) {}
        } else if (input && typeof input.url === 'string') {
            try {
                path = new URL(input.url, window.location.origin).pathname;
            } catch (e) {}
            if (input.method) {
                method = input.method;
            }
        }
        if (init.method) {
            method = init.method;
        }
        return { path, method: String(method).toUpperCase() };
    }

    function boot() {
        dockHud();

        // Document-level delegation survives Livewire morphs / HUD reinjection.
        if (!window.__coolifyServerTimingClickBound) {
            window.__coolifyServerTimingClickBound = true;
            document.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const root = rootEl();
                if (!root || !root.contains(target)) {
                    return;
                }

                const toggle = target.closest('[data-sth-toggle]');
                if (toggle && root.contains(toggle)) {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleOpen();
                    return;
                }
                const clearBtn = target.closest('[data-sth-clear]');
                if (clearBtn && root.contains(clearBtn)) {
                    event.preventDefault();
                    event.stopPropagation();
                    history.splice(0, history.length);
                    paint();
                    return;
                }
                const entryCard = target.closest('[data-sth-entry-id]');
                if (entryCard && root.contains(entryCard)) {
                    event.preventDefault();
                    event.stopPropagation();
                    const id = Number(entryCard.getAttribute('data-sth-entry-id'));
                    if (!Number.isNaN(id)) {
                        copyEntryById(id);
                    }
                }
            }, true);
        }

        const seeded = metricsFromDom();
        if (seeded) {
            pushEntry({
                metrics: seeded.metrics,
                path: seeded.path,
                method: 'GET',
                kind: 'document',
            });
        } else {
            paint();
        }

        try {
            const nav = performance.getEntriesByType('navigation')[0];
            if (nav && nav.serverTiming && nav.serverTiming.length) {
                const metrics = {};
                nav.serverTiming.forEach((entry) => {
                    metrics[entry.name] = entry.duration;
                });
                pushEntry({
                    metrics,
                    path: window.location.pathname,
                    method: 'GET',
                    kind: 'document',
                });
            }
        } catch (e) {}

        if (!window.__coolifyServerTimingFetchPatched) {
            window.__coolifyServerTimingFetchPatched = true;
            const originalFetch = window.fetch.bind(window);
            window.fetch = async function (...args) {
                const meta = requestMeta(args);
                const response = await originalFetch(...args);
                try {
                    const header = response.headers.get('Server-Timing');
                    if (header) {
                        applyFromHeader(header, meta.path, meta.method);
                    }
                } catch (e) {}
                return response;
            };
        }

        if (!window.__coolifyServerTimingXhrPatched && window.XMLHttpRequest) {
            window.__coolifyServerTimingXhrPatched = true;
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__sthMethod = method;
                this.__sthUrl = url;
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('load', function () {
                    try {
                        const header = this.getResponseHeader('Server-Timing');
                        if (!header) {
                            return;
                        }
                        let path = window.location.pathname;
                        try {
                            path = new URL(this.__sthUrl, window.location.origin).pathname;
                        } catch (e) {}
                        applyFromHeader(header, path, this.__sthMethod || 'GET');
                    } catch (e) {}
                });
                return send.apply(this, arguments);
            };
        }

        if (!window.__coolifyServerTimingNavBound) {
            window.__coolifyServerTimingNavBound = true;
            const redock = () => {
                dockHud();
                paint();
            };
            document.addEventListener('livewire:navigated', () => {
                // Full navigations may reinject a floating HUD at end of document; re-dock into top bar.
                dockHud();
                const seededNav = metricsFromDom();
                if (seededNav) {
                    pushEntry({
                        metrics: seededNav.metrics,
                        path: seededNav.path || window.location.pathname,
                        method: 'GET',
                        kind: 'document',
                    });
                } else {
                    paint();
                }
            });
            window.addEventListener('resize', redock);
            window.addEventListener('server-timing-hud-visibility-changed', redock);
            // App shell uses x-cloak; re-dock once Alpine reveals the top bar.
            document.addEventListener('alpine:initialized', redock);
            // Safety: retry a few times after load in case cloak clears late.
            [50, 150, 400, 1000].forEach((ms) => {
                window.setTimeout(redock, ms);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
