<script>
  // Environment canvas (Beta): a read-mostly diagram of one environment.
  // Server zones hold resource cards; admins can move cards and draw
  // visual-only links. Positions and links are stored through /canvas/api.
  //
  // The server canvas (data-endpoint) reuses this view. It saves moves and links on the environment canvas of each card.
  // The shared variables and tags canvases (data-mode) reuse it too: their links add a variable or a tag to a resource.
  import { flushSync, onMount, tick } from 'svelte';
  import { api, BOX_GAP, freeSpot, MIN_ZOOM, panZoom } from '../../canvas-core.js';
  import {
    canConnect,
    canLink,
    cardRect,
    CONNECT_TEXT,
    DOUBLE_CLICK_MS,
    dragRules,
    filterGraph,
    filterOptions,
    groupByUse,
    groupFrames,
    isSource,
    layoutGraph,
    layoutServerView,
    linkPath,
    MOBILE_NOTICE_KEY,
    readView,
    STATUS_CHANNELS,
    zoneBoxes,
    zoneGroups,
  } from '../../environment-canvas.js';
  import Card from './Card.svelte';
  import Icon from './Icon.svelte';
  import Panel from './Panel.svelte';
  import Toolbar from './Toolbar.svelte';
  import Zone from './Zone.svelte';

  // The page's [data-environment-canvas] element; it does not change while the canvas is mounted.
  let { container } = $props();

  // svelte-ignore state_referenced_locally
  const ds = container.dataset;
  const connectView = ds.mode === 'variables' || ds.mode === 'tags';
  const text = CONNECT_TEXT[ds.mode] || CONNECT_TEXT.variables;
  const params = new URLSearchParams(window.location.search);
  const serverView = !!ds.endpoint && !connectView;
  const filterView = connectView || serverView;
  const base = ds.endpoint || `/projects/${encodeURIComponent(ds.project || '')}/environments/${encodeURIComponent(ds.environment || '')}/canvas`;
  // Table / grid / canvas switcher from the page (Blade), moved into the toolbar.
  // svelte-ignore state_referenced_locally
  const views = container.querySelector('[data-ec-views]');
  const viewOnlyTitle = connectView
    ? text.viewOnly
    : ds.viewOnly ? ds.viewOnly
    : serverView
      ? 'The server view is read-only. Move cards and edit links on an environment canvas.'
      : 'You can look around. Only team admins can move cards and edit links.';

  // ── State ──
  let full = $state.raw(null); // the loaded graph; graph is it after the project / environment filter
  let graph = $state.raw(null);
  let positions = $state({});
  let sizes = $state.raw({});
  let selected = $state.raw(null); // { kind: 'node' | 'link', id }
  let filter = $state.raw(filterView ? { project: params.get('project') || '', environment: params.get('environment') || '' } : {});
  let hover = $state(null);
  let pending = $state.raw([]); // links being saved: { source, target }, drawn as moving dashes until the request ends
  let temp = $state.raw(null); // the link being drawn: { from, to }
  let dropTarget = $state(null);
  let loading = $state(false);
  let error = $state(null);
  let toastMessage = $state.raw(null);
  let zoom = $state(1);
  let panelTop = $state('');
  let showNotice = $state(noticeOpen());

  // Plain bookkeeping (not rendered).
  let view = { x: 40, y: 40, z: 1 };
  const interactions = new Set(); // active drags; refetches wait until they end
  let deferred = null;
  const unsaved = {};
  let offsets = {}; // server view: card shift from its environment canvas position (see layoutServerView)
  let reloadQueued = false;
  let fitted = false;
  let destroyed = false;
  let containers = null; // server canvas: unmanaged container cards, loaded after the canvas renders
  const timers = { save: 0, refresh: 0, toast: 0, view: 0 };
  // Zoom and pan are kept per team and page, so a reload opens the canvas where it was.
  const viewKey = `coolify-canvas-view:${ds.team || ''}:${window.location.pathname}`;
  let restoredView = readView(viewKey);
  if (restoredView) view = restoredView;

  let rootEl;
  let bodyEl;
  let barSlot;
  let viewport;
  let bar;
  let pz;

  const canEdit = $derived(!!graph?.can_edit);
  const canMove = $derived(canEdit && graph?.can_move !== false);
  // Server view: unmanaged containers have no environment canvas, and zone frames are laid out, not saved.
  const canMoveNode = node => canMove && (!serverView || !!node?.environment);
  const canMoveZones = $derived(canMove && !serverView);
  // Environment canvas endpoints of a card (the server view has no endpoints of its own for changes).
  const environmentBase = node => (serverView
    ? `/projects/${encodeURIComponent(node?.project?.uuid || '')}/environments/${encodeURIComponent(node?.environment?.uuid || '')}/canvas`
    : base);
  const nodeById = id => graph?.nodes.find(n => n.id === id);
  const linkById = id => graph?.links.find(l => l.id === id);
  const rectOf = id => {
    const node = nodeById(id);
    return node ? cardRect(node, positions, sizes) : null;
  };
  const zoneNames = node => {
    const names = new Map((graph?.zones || []).map(z => [z.id, z.name]));
    return [node.zone, ...(node.extra_zones || [])].filter(Boolean).map(id => names.get(id) || id);
  };
  const toBox = r => ({ x: r.x, y: r.y, w: r.width, h: r.height });

  // ── Derived view data ──
  const projects = $derived(filterView ? filterOptions(full) : []);
  const environments = $derived(projects
    .filter(p => !filter.project || p.uuid === filter.project)
    .flatMap(p => p.environments.map(e => ({ uuid: e.uuid, name: filter.project ? e.name : `${p.name} / ${e.name}` }))));
  const boxes = $derived(graph ? zoneBoxes(graph, positions, sizes) : []);
  // Server and connect views: project / environment frames inside the zones.
  const frames = $derived(graph?.layout === 'server' || graph?.layout === 'connect'
    ? zoneGroups(graph).flatMap(zone => groupFrames(zone.nodes.filter(n => n.context), positions, sizes))
    : []);
  // Connect view: links per source card (its "used by" badge).
  const linkCounts = $derived.by(() => {
    const counts = {};
    for (const l of graph?.links || []) counts[l.source] = (counts[l.source] || 0) + 1;
    return counts;
  });
  const shapes = $derived.by(() => {
    const out = [];
    for (const link of graph?.links || []) {
      const a = rectOf(link.source);
      const b = rectOf(link.target);
      if (!a || !b) continue;
      const isSelected = selected?.kind === 'link' && selected.id === link.id;
      const related = hover && (link.source === hover || link.target === hover);
      out.push({
        link,
        ...linkPath(a, b),
        selected: isSelected,
        faded: hover && !related,
        stroke: isSelected ? 'fill-none stroke-coollabs dark:stroke-warning'
          : link.missing ? `ec-missing fill-none stroke-red-400 dark:stroke-red-400/70 ${hover && !related ? 'opacity-20' : ''}`
          : related ? 'fill-none stroke-neutral-700 dark:stroke-white/80'
          : hover ? 'fill-none stroke-neutral-400 opacity-20 dark:stroke-white/40'
          : 'fill-none stroke-neutral-400 dark:stroke-white/35',
        title: connectView
          ? `${nodeById(link.source)?.name || ''} → ${nodeById(link.target)?.name || ''} (click to open)`
          : link.label ? `${link.label} (click to open)` : 'Click to open this link',
      });
    }
    return out;
  });
  const pendingPaths = $derived(pending.map(p => [rectOf(p.source), rectOf(p.target)]).filter(([a, b]) => a && b).map(([a, b]) => linkPath(a, b).d));
  const tempPath = $derived.by(() => {
    if (!temp) return null;
    const { from, to } = temp;
    const dx = Math.max(40, Math.abs(to.x - from.x) / 2);
    return `M ${from.x} ${from.y} C ${from.x + dx} ${from.y}, ${to.x - dx} ${to.y}, ${to.x} ${to.y}`;
  });
  const panelNode = $derived(selected?.kind === 'node' ? nodeById(selected.id) : null);
  const panelLink = $derived(selected?.kind === 'link' ? linkById(selected.id) : null);
  const empty = $derived(!!graph && !graph.nodes.length);

  // ── Mobile notice ──
  // Small screens: a notice that the canvas is not optimized for mobile. Shown below 768px (md);
  // closed once, it stays closed on every canvas (same key as the deploy canvas notice).
  function noticeOpen() {
    try {
      return !JSON.parse(localStorage.getItem(MOBILE_NOTICE_KEY));
    } catch {
      return true; // Storage blocked: show the notice.
    }
  }
  function closeNotice() {
    try {
      localStorage.setItem(MOBILE_NOTICE_KEY, 'true');
    } catch {
      // Storage blocked: closed until the next page load.
    }
    showNotice = false;
  }

  // ── Toolbar placement ──
  const desktop = window.matchMedia('(min-width: 1024px)');
  // Floating nav on desktop: a separate panel in the top bar row, centered over the canvas,
  // so the top bar keeps only the brand and breadcrumb.
  const topbar = document.querySelector('.app-topbar');
  const floatingBar = () => desktop.matches && !!topbar && document.documentElement.dataset.navStyle === 'floating';
  // Floating nav: the canvas fills the screen behind the top bar and the sidebar (see app.css),
  // so fit and the side panel use only the part that the floating panels do not cover.
  function coveredInsets() {
    if (!floatingBar()) return { top: 0, left: 0 };
    const gap = 8;
    const v = viewport.getBoundingClientRect();
    // The main sidebar, and a page's fixed settings rail (server canvas) when it is on the left.
    const rail = document.querySelector('.application-settings-navigation');
    const panels = [document.querySelector('.app-sidebar > div > nav'), rail && getComputedStyle(rail).position === 'fixed' ? rail : null]
      .map(el => el?.getBoundingClientRect())
      .filter(r => r?.width);
    // The toolbar is in the top bar row, or in a row below it when the top bar has no room (see alignFloatingBar).
    const barBottom = bar?.isConnected ? bar.getBoundingClientRect().bottom : 0;
    return {
      top: Math.max(0, Math.max(topbar.getBoundingClientRect().bottom, barBottom) + gap - v.top),
      left: Math.max(0, ...panels.map(r => r.right + gap - v.left)),
    };
  }
  function alignFloatingBar() {
    if (!floatingBar()) {
      panelTop = '';
      return;
    }
    const gap = 8;
    const area = bodyEl.getBoundingClientRect();
    const head = topbar.getBoundingClientRect();
    const insetLeft = coveredInsets().left;
    // Measure the full toolbar width, then use the top bar row only when it fits next to the top bar
    // (a page can dock wide actions in the top bar, e.g. the server canvas).
    Object.assign(bar.style, { left: '0px', maxWidth: '' });
    const width = bar.offsetWidth;
    const besideHead = head.right + gap + width <= window.innerWidth - gap;
    const left = Math.max(area.left + insetLeft + (area.width - insetLeft - width) / 2, besideHead ? head.right + gap : area.left + insetLeft);
    const top = besideHead ? head.top : head.bottom + gap;
    Object.assign(bar.style, { top: `${top}px`, left: `${left}px`, minHeight: `${head.height}px`, maxWidth: `${window.innerWidth - left - gap}px` });
    panelTop = `${coveredInsets().top}px`;
  }
  function placeBar() {
    // data-toolbar-inline: the page docks its own actions in the top bar, so the toolbar stays in the canvas.
    const slot = desktop.matches && !floatingBar() && !('toolbarInline' in ds) && document.getElementById('page-toolbar-slot');
    bar.removeAttribute('style');
    if (floatingBar()) {
      bar.className = 'fixed z-40 flex flex-wrap items-center justify-center gap-3 rounded-xl border border-neutral-200 bg-white px-2 py-1.5 dark:border-white/[0.08] dark:bg-panel';
      bodyEl.append(bar);
      alignFloatingBar();
    } else if (slot) {
      bar.className = 'flex items-center gap-3 pl-3';
      slot.append(bar);
    } else {
      bar.className = 'flex shrink-0 flex-wrap items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 dark:border-white/[0.08] dark:bg-panel';
      barSlot.append(bar);
      panelTop = '';
    }
  }
  /** Action: the toolbar element; onMount docks it (top bar slot, floating panel, or above the canvas). */
  function dock(node) {
    bar = node;
    // The toolbar can live outside the canvas root (top bar slot), so remove it here.
    return { destroy: () => node.remove() };
  }
  function watchToolbar() {
    placeBar();
    desktop.addEventListener('change', placeBar);
    window.addEventListener('resize', alignFloatingBar);
    const navStyleObserver = new MutationObserver(placeBar);
    navStyleObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-nav-style'] });
    // Sidebar collapse, breadcrumb changes and toolbar content change the sizes.
    const resizeObserver = new ResizeObserver(alignFloatingBar);
    for (const el of [bodyEl, bar, topbar].filter(Boolean)) resizeObserver.observe(el);
    return () => {
      desktop.removeEventListener('change', placeBar);
      window.removeEventListener('resize', alignFloatingBar);
      navStyleObserver.disconnect();
      resizeObserver.disconnect();
    };
  }

  // ── Toast ──
  function toast(message, bad = false) {
    toastMessage = { text: message, bad };
    clearTimeout(timers.toast);
    timers.toast = setTimeout(() => (toastMessage = null), bad ? 5000 : 2600);
  }

  // ── View ──
  /** Action: wheel / pinch zoom and drag-to-pan on the viewport (canvas-core panZoom). */
  function panZoomAction(node) {
    pz = panZoom({
      root: node.closest('[data-ec-root]'),
      viewport: node,
      world: node.firstElementChild,
      zoomLabel: {}, // the toolbar shows `zoom`
      getView: () => view,
      onChange: () => {
        zoom = view.z;
        clearTimeout(timers.view);
        timers.view = setTimeout(saveView, 300);
      },
      canPan: e => !e.target.closest('[data-ec-card],[data-ec-zone-header],[data-ec-label],[data-ec-link],a,button,input'),
      onPanEnd: moved => {
        if (!moved) closePanel();
      },
    });
  }
  function saveView() {
    clearTimeout(timers.view);
    try {
      localStorage.setItem(viewKey, JSON.stringify(view));
    } catch {
      // Storage full or blocked: the canvas fits to the screen next time.
    }
  }
  function fit() {
    const all = graph ? zoneBoxes(graph, positions, sizes) : [];
    const r = viewport.getBoundingClientRect();
    const inset = coveredInsets();
    if (!all.length || !r.width) {
      view = { x: inset.left + 40, y: inset.top + 40, z: 1 };
      pz.apply();
      return;
    }
    const minX = Math.min(...all.map(b => b.x));
    const minY = Math.min(...all.map(b => b.y));
    const maxX = Math.max(...all.map(b => b.x + b.width));
    const maxY = Math.max(...all.map(b => b.y + b.height));
    const pad = 48;
    const width = r.width - inset.left;
    const height = r.height - inset.top;
    const z = Math.min(1.1, Math.max(MIN_ZOOM, Math.min((width - pad * 2) / (maxX - minX), (height - pad * 2) / (maxY - minY))));
    view = { z, x: inset.left + (width - (maxX - minX) * z) / 2 - minX * z, y: inset.top + (height - (maxY - minY) * z) / 2 - minY * z };
    pz.apply();
  }

  function setFilter(next) {
    filter = next;
    const url = new URL(window.location.href);
    for (const key of ['project', 'environment']) {
      if (next[key]) url.searchParams.set(key, next[key]);
      else url.searchParams.delete(key);
    }
    window.history.replaceState(window.history.state, '', url);
    fitted = false;
    applyGraph(full);
  }

  function measure() {
    const next = {};
    for (const el of rootEl.querySelectorAll('[data-ec-card]')) {
      if (el.offsetWidth) next[el.dataset.id] = { width: el.offsetWidth, height: el.offsetHeight };
    }
    sizes = next;
  }

  // ── Selection ──
  function select(selection) {
    selected = selection;
  }
  function closePanel() {
    if (selected) select(null);
  }

  /** Changes the shown graph; the loaded graph too when no filter made a copy of it. */
  function patchGraph(changes) {
    const same = full === graph;
    graph = { ...graph, ...changes };
    if (same) full = graph;
  }

  function replaceLink(link) {
    patchGraph({ links: graph.links.map(l => (l.id === link.id ? link : l)) });
  }

  async function saveLabel(link, value) {
    try {
      const updated = await api('PATCH', `${environmentBase(nodeById(link.source))}/links/${encodeURIComponent(link.id)}`, { label: value || null });
      replaceLink({ ...link, ...(updated?.id ? updated : { label: value || null }) });
      toast('Label saved.');
      return true;
    } catch (e) {
      toast(e.message, true);
      return false;
    }
  }

  async function deleteLink(link) {
    try {
      await api('DELETE', `${environmentBase(nodeById(link.source))}/links/${encodeURIComponent(link.id)}`);
      patchGraph({ links: graph.links.filter(l => l.id !== link.id) });
      select(null);
      toast('Link removed.');
    } catch (e) {
      toast(e.message, true);
    }
  }

  // Connect view: removes a variable from a resource, or a tag.
  async function removeLink(link) {
    const variable = nodeById(link.source);
    const resource = nodeById(link.target);
    try {
      await api('DELETE', `${base}/links/${encodeURIComponent(link.id)}`);
      if (destroyed) return;
      if (selected?.kind === 'link') selected = { kind: 'node', id: link.source };
      setLinks(full.links.filter(l => l.id !== link.id));
      toast(text.removed(variable?.name, resource?.name));
      load({ quiet: true });
    } catch (e) {
      toast(e.message, true);
    }
  }

  // ── Interactions ──
  function begin(key) {
    interactions.add(key);
  }
  function end(key) {
    interactions.delete(key);
    if (!interactions.size && deferred) {
      const next = deferred;
      deferred = null;
      applyGraph(next);
    }
  }
  function capture(el, pointerId) {
    try {
      el.setPointerCapture(pointerId);
    } catch {
      // Synthetic or already released pointer; window-level fallbacks are not needed for real pointers.
    }
  }
  /** Follows a captured pointer on `el` until it is released. */
  function track(el, move, up) {
    const release = () => {
      el.removeEventListener('pointermove', move);
      el.removeEventListener('pointerup', release);
      el.removeEventListener('pointercancel', release);
      up();
    };
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', release);
    el.addEventListener('pointercancel', release);
  }

  function hoverCard(id, on) {
    if (on && !interactions.size) hover = id;
    else if (!on && hover === id) hover = null;
  }

  function startCardDrag(id, card, e) {
    if (e.button !== 0 || e.target.closest('a,button,input,[data-ec-port]')) return;
    e.stopPropagation();
    const start = { cx: e.clientX, cy: e.clientY, ...positions[id] };
    // The other cards and frames stay put during the drag, so measure them once.
    const rules = dragRules(graph, positions, sizes, id);
    let moved = false;
    capture(card, e.pointerId);
    begin(`card:${id}`);
    track(card, ev => {
      if (!moved && Math.hypot(ev.clientX - start.cx, ev.clientY - start.cy) < 4) return;
      if (!canMoveNode(nodeById(id))) return;
      const rect = rectOf(id);
      const wanted = { x: start.x + (ev.clientX - start.cx) / view.z, y: start.y + (ev.clientY - start.cy) / view.z, w: rect.width, h: rect.height };
      // Cards cannot cover each other or another zone: stop at the edge of what is in the way.
      const spot = freeSpot(wanted, rules.others, BOX_GAP, rules.fits);
      if (!spot) return;
      moved = true;
      positions[id] = { x: spot.x, y: spot.y };
    }, () => {
      if (moved) queueSave({ [id]: { ...positions[id] } });
      else select({ kind: 'node', id });
      end(`card:${id}`);
    });
  }

  function startZoneDrag(box, header, e) {
    if (e.button !== 0 || !canMoveZones || e.target.closest('a')) return;
    e.stopPropagation();
    const ids = box.nodes.filter(id => positions[id]);
    const starts = Object.fromEntries(ids.map(id => [id, { ...positions[id] }]));
    const origin = { cx: e.clientX, cy: e.clientY };
    // Zones cannot cover each other: the frame stops at the edge of another zone.
    const zone = toBox(box);
    const others = zoneBoxes(graph, positions, sizes).filter(z => z.id !== box.id).map(toBox);
    let moved = false;
    capture(header, e.pointerId);
    begin('zone');
    track(header, ev => {
      if (!moved && Math.hypot(ev.clientX - origin.cx, ev.clientY - origin.cy) < 4) return;
      const spot = freeSpot({ ...zone, x: zone.x + (ev.clientX - origin.cx) / view.z, y: zone.y + (ev.clientY - origin.cy) / view.z }, others);
      if (!spot) return;
      moved = true;
      const dx = spot.x - zone.x;
      const dy = spot.y - zone.y;
      for (const id of ids) positions[id] = { x: starts[id].x + dx, y: starts[id].y + dy };
    }, () => {
      if (moved) queueSave(Object.fromEntries(ids.map(id => [id, { ...positions[id] }])));
      end('zone');
    });
  }

  function startConnect(id, port, e) {
    if (e.button !== 0 || !canEdit) return;
    e.stopPropagation();
    e.preventDefault();
    const a = rectOf(id);
    if (!a) return;
    const from = { x: a.x + a.width, y: a.y + a.height / 2 };
    capture(port, e.pointerId);
    begin('connect');
    hover = null;
    track(port, ev => {
      temp = { from, to: pz.toWorld(ev.clientX, ev.clientY) };
      const tid = document.elementFromPoint(ev.clientX, ev.clientY)?.closest?.('[data-ec-card]')?.dataset.id;
      dropTarget = tid && canConnect(graph, id, tid) ? tid : null;
    }, async () => {
      const chosen = dropTarget;
      dropTarget = null;
      temp = null;
      if (chosen) await createLink(id, chosen);
      end('connect');
    });
  }

  async function createLink(source, target) {
    if (!canLink(graph.links, source, target)) {
      toast(connectView ? text.already(nodeById(source)?.name, nodeById(target)?.name) : 'These resources are already linked.', true);
      return;
    }
    const entry = { source, target };
    pending = [...pending, entry];
    try {
      const link = await api('POST', `${environmentBase(nodeById(source))}/links`, { source, target });
      if (destroyed) return;
      if (connectView) {
        // Keep the panel of the card the user added from (either end); otherwise open the source.
        const keep = selected?.kind === 'node' && (selected.id === source || selected.id === target);
        if (!keep) selected = { kind: 'node', id: source };
        if (link?.id) setLinks([...full.links.filter(l => l.id !== link.id), link]);
        else applyGraph(full);
        toast(text.added(nodeById(source)?.name, nodeById(target)?.name));
        load({ quiet: true });
        return;
      }
      const created = link?.data?.id ? link.data : link;
      if (created?.id) {
        patchGraph({ links: [...graph.links.filter(l => l.id !== created.id), created] });
        select({ kind: 'link', id: created.id });
        await tick();
        rootEl.querySelector('[data-ec-label-input]')?.focus();
      } else {
        load({ quiet: true });
      }
    } catch (e) {
      toast(e.message, true);
    } finally {
      pending = pending.filter(p => p !== entry);
    }
  }

  // Double-click on a card opens its page. Tracked on pointerdown (capture phase, before drags start):
  // the first click re-renders the card and can open the side panel over it, so the second press can land on
  // another element. Two presses at the same point open the page of the first one.
  let lastOpen = null;
  function openOnDoubleClick(e) {
    const own = e.button !== 0 || e.target.closest('a,button,input,[data-ec-port]');
    const again = !own && lastOpen && e.timeStamp - lastOpen.at < DOUBLE_CLICK_MS
      && Math.hypot(e.clientX - lastOpen.x, e.clientY - lastOpen.y) < 6;
    if (again) {
      e.preventDefault();
      e.stopPropagation();
      window.location.assign(lastOpen.href);
      lastOpen = null;
      return;
    }
    const href = own ? null : e.target.closest('[data-ec-open]')?.dataset.ecOpen;
    lastOpen = href ? { href, at: e.timeStamp, x: e.clientX, y: e.clientY } : null;
  }

  // ── Persistence ──
  /**
   * Server view: environment canvas positions of the moved cards and of the other cards of their group,
   * so the whole group keeps its arrangement on the next layout.
   */
  function environmentPositions(ids) {
    const moved = ids.map(nodeById).filter(n => n?.environment);
    const members = (graph?.nodes || []).filter(n => offsets[n.id] && positions[n.id]
      && moved.some(m => m.zone === n.zone && m.environment.uuid === n.environment?.uuid));
    return Object.fromEntries(members.map(n => [n.id, { x: positions[n.id].x - offsets[n.id].x, y: positions[n.id].y - offsets[n.id].y }]));
  }
  function queueSave(map) {
    Object.assign(unsaved, serverView ? environmentPositions(Object.keys(map)) : map);
    clearTimeout(timers.save);
    timers.save = setTimeout(() => flushSave(), 400);
  }
  async function flushSave(options = {}) {
    clearTimeout(timers.save);
    const batch = { ...unsaved };
    const ids = Object.keys(batch);
    if (!ids.length || !graph) return;
    const rounded = Object.fromEntries(ids.map(id => [id, { x: Math.round(batch[id].x), y: Math.round(batch[id].y) }]));
    const forget = () => {
      for (const id of ids) if (unsaved[id] === batch[id]) delete unsaved[id];
    };
    // Server view: one request per environment canvas.
    const requests = new Map();
    for (const id of ids) {
      const path = environmentBase(full?.nodes.find(n => n.id === id));
      requests.set(path, { ...requests.get(path), [id]: rounded[id] });
    }
    try {
      await Promise.all([...requests].map(([path, part]) => api('PATCH', `${path}/positions`, { positions: part }, options)));
      if (graph && !destroyed) patchGraph({ positions: { ...(graph.positions || {}), ...rounded } });
      forget();
    } catch (e) {
      forget();
      if (destroyed) return;
      toast(e.message, true);
      load({ quiet: true });
    }
  }

  // ── Data ──
  /** Shared variables view: a link change moves its resource in or out of the connected zone right away. */
  function setLinks(links) {
    applyGraph({ ...full, links });
  }

  function applyGraph(next) {
    if (destroyed) return;
    if (interactions.size) {
      deferred = next;
      return;
    }
    next.zones ||= [];
    next.nodes ||= [];
    next.links ||= [];
    next.positions = next.positions && !Array.isArray(next.positions) ? next.positions : {};
    // Local moves that are not saved yet stay where the user put them.
    next.positions = { ...next.positions, ...Object.fromEntries(Object.entries(unsaved).map(([id, p]) => [id, { x: p.x, y: p.y }])) };
    full = next;
    const shown = groupByUse(filterGraph(next, filter));
    offsets = {};
    positions = shown.layout === 'server' ? layoutServerView(shown, offsets) : layoutGraph(shown);
    graph = shown;
    error = null;
    if (hover && !nodeById(hover)) hover = null;
    if (selected && !(selected.kind === 'node' ? nodeById(selected.id) : linkById(selected.id))) selected = null;
    // Render the cards now and measure them, so frames, links and fit use their real sizes.
    flushSync();
    measure();
    flushSync();
    if (!fitted) {
      fitted = true;
      // The first load keeps a saved view; later refits (filter changes) fit again.
      if (restoredView) restoredView = null;
      else fit();
      // Connect view: ?select=<card id> (from a variable's or tag's connect button) opens that card.
      const wanted = connectView && params.get('select');
      if (wanted && !selected && nodeById(wanted)) select({ kind: 'node', id: wanted });
      params.delete('select');
    }
  }

  async function load({ quiet = false } = {}) {
    if (destroyed) return;
    if (loading) {
      reloadQueued = true;
      return;
    }
    loading = true;
    try {
      const next = await api('GET', base);
      if (next && containers) next.nodes = [...(next.nodes || []), ...containers];
      applyGraph(next);
      if (!quiet && fitted && next) toast('Canvas refreshed.');
      // Status refreshes keep the containers; the first load and the Refresh button read them again.
      if (next?.containers_endpoint && (!quiet || !containers)) loadContainers(next.containers_endpoint);
    } catch (e) {
      if (destroyed) return;
      if (graph) toast(e.message, true);
      else error = e.message;
    } finally {
      loading = false;
      if (!destroyed && reloadQueued) {
        reloadQueued = false;
        load({ quiet: true });
      }
    }
  }

  /** Server canvas: adds the unmanaged containers. This runs a command on the server, so the canvas does not wait for it. */
  async function loadContainers(path) {
    containers ||= []; // status refreshes during this request do not start another one
    try {
      const { nodes = [] } = (await api('GET', path)) || {};
      if (destroyed || !full) return;
      containers = nodes;
      applyGraph({ ...full, nodes: [...full.nodes.filter(n => n.type !== 'container'), ...nodes] });
    } catch {
      // The server zone already shows when the server is not reachable.
    }
  }

  function scheduleRefresh(delay = 1000) {
    clearTimeout(timers.refresh);
    timers.refresh = setTimeout(() => load({ quiet: true }), delay);
  }

  function onKeydown(e) {
    if (e.key === 'Escape' && selected && !e.defaultPrevented) closePanel();
  }
  function onVisibility() {
    if (document.visibilityState === 'visible') scheduleRefresh(300);
  }
  function onPageHide() {
    saveView();
    if (Object.keys(unsaved).length) flushSave({ keepalive: true });
  }

  onMount(() => {
    // Live status: application and service events go to the team channel; database events to the user channel.
    const onStatus = () => scheduleRefresh();
    const cleanups = [watchToolbar()];
    for (const [kind, events] of STATUS_CHANNELS) {
      if (!window.Echo || !ds[kind]) continue;
      try {
        const channel = window.Echo.private(`${kind}.${ds[kind]}`);
        for (const event of events) channel.listen(event, onStatus);
        cleanups.push(() => {
          for (const event of events) channel.stopListening(event, onStatus);
        });
      } catch {
        // Live updates are optional; Refresh still works.
      }
    }

    pz.apply();
    load({ quiet: true });

    return () => {
      saveView();
      if (Object.keys(unsaved).length) flushSave({ keepalive: true });
      destroyed = true;
      clearTimeout(timers.save);
      clearTimeout(timers.refresh);
      clearTimeout(timers.toast);
      clearTimeout(timers.view);
      for (const cleanup of cleanups) cleanup();
    };
  });
</script>

<svelte:document onkeydown={onKeydown} onvisibilitychange={onVisibility} />
<svelte:window onpagehide={onPageHide} />

<!-- Same look as the deploy canvas: a rounded top bar box on the backdrop. -->
<div
  bind:this={rootEl}
  class="absolute inset-0 z-0 flex flex-col gap-2 overflow-hidden bg-neutral-50 p-2 text-[13px] text-neutral-700 dark:bg-app dark:text-fg-dim"
  data-ec-root
  onpointerdowncapture={openOnDoubleClick}
>
  <!-- The toolbar's place above the canvas; use:dock moves it into the top bar when there is room. -->
  <div bind:this={barSlot} class="contents">
    <Toolbar
      title={ds.title}
      viewOnly={!!graph && !canEdit}
      {viewOnlyTitle}
      {filter}
      {projects}
      {environments}
      {loading}
      {zoom}
      {views}
      {dock}
      onfilter={setFilter}
      onrefresh={() => load()}
      onzoom={z => pz.zoomTo(z)}
      onfit={fit}
    />
  </div>

  {#if showNotice}
    <div class="flex shrink-0 items-start gap-2 rounded-xl bg-red-600 py-2.5 pr-2 pl-3 text-[12.5px] leading-[1.125rem] text-white md:hidden" data-ec-mobile-notice role="note">
      <Icon name="info" class="size-4 mt-px" />
      <p class="min-w-0 flex-1">The canvas is not optimized for mobile yet. It works best on a larger screen.</p>
      <button type="button" class="grid size-6.5 shrink-0 -mt-0.75 place-items-center rounded-md hover:bg-white/20" title="Close" aria-label="Close this notice" onclick={closeNotice}>
        <Icon name="x" class="size-4" />
      </button>
    </div>
  {/if}

  <div bind:this={bodyEl} class="relative min-h-0 flex-1">
    <div
      bind:this={viewport}
      use:panZoomAction
      class="absolute inset-0 cursor-grab touch-none overflow-hidden select-none bg-[radial-gradient(circle,rgb(0_0_0/0.13)_1px,transparent_1px)] active:cursor-grabbing dark:bg-[radial-gradient(circle,rgb(255_255_255/0.09)_1px,transparent_1px)]"
      data-ec-viewport
    >
      <div class="absolute top-0 left-0 origin-top-left">
        <div class="absolute top-0 left-0">
          {#each boxes as box (box.id ?? '__none')}
            <Zone {box} movable={canMoveZones} onpress={(e, header) => startZoneDrag(box, header, e)} />
          {/each}
        </div>
        <!-- Server view: project / environment frames inside the server zones. -->
        <div class="pointer-events-none absolute top-0 left-0">
          {#each frames as frame, i (i)}
            <div class="absolute rounded-xl border border-dashed border-neutral-300 dark:border-white/[0.12]" style:left="{frame.x}px" style:top="{frame.y}px" style:width="{frame.width}px" style:height="{frame.height}px">
              <div class="flex h-8 items-center gap-1.5 px-3 text-[11px] font-medium text-neutral-500 dark:text-fg-dim">
                <Icon name="service" class="size-3 text-neutral-400 dark:text-fg-faint" />
                <a class="pointer-events-auto truncate hover:text-neutral-700 dark:hover:text-fg [&[href]]:cursor-pointer [&[href]]:underline-offset-2 [&[href]]:hover:underline" href={frame.link || undefined} draggable="false">{frame.name || 'Other'}</a>
              </div>
            </div>
          {/each}
        </div>
        <svg class="pointer-events-none absolute top-0 left-0 overflow-visible" width="1" height="1">
          {#each shapes as shape (shape.link.id)}
            <path d={shape.d} stroke-width={shape.selected ? '2.5' : '1.75'} stroke-linecap="round" class="{shape.stroke} transition-opacity" />
            <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
            <path
              d={shape.d}
              fill="none"
              stroke="transparent"
              stroke-width="14"
              pointer-events="stroke"
              class="cursor-pointer"
              data-ec-link={shape.link.id}
              onclick={e => {
                e.stopPropagation();
                select({ kind: 'link', id: shape.link.id });
              }}
            ><title>{shape.title}</title></path>
          {/each}
          {#each pendingPaths as d, i (i)}
            <path {d} stroke-width="2" stroke-linecap="round" class="ec-temp fill-none stroke-coollabs dark:stroke-warning" />
          {/each}
          {#if tempPath}
            <path d={tempPath} stroke-width="2" stroke-linecap="round" class="ec-temp fill-none stroke-coollabs dark:stroke-warning" />
          {/if}
        </svg>
        <div class="absolute top-0 left-0">
          {#each shapes.filter(s => s.link.label) as shape (shape.link.id)}
            <button
              type="button"
              class="absolute max-w-48 -translate-x-1/2 -translate-y-1/2 cursor-pointer truncate rounded-full border px-2 py-0.5 text-[11px] font-medium shadow-sm transition-opacity {shape.selected
                ? 'border-coollabs bg-white text-coollabs dark:border-warning dark:bg-panel dark:text-warning'
                : 'border-neutral-200 bg-white text-neutral-600 dark:border-white/[0.1] dark:bg-panel dark:text-fg-dim'} {shape.faded ? 'opacity-30' : ''}"
              style:left="{shape.mid.x}px"
              style:top="{shape.mid.y}px"
              data-ec-label
              onclick={e => {
                e.stopPropagation();
                select({ kind: 'link', id: shape.link.id });
              }}
            >{shape.link.label}</button>
          {/each}
        </div>
        <div class="absolute top-0 left-0">
          {#each graph?.nodes || [] as node (node.id)}
            <Card
              {node}
              position={positions[node.id]}
              selected={selected?.kind === 'node' && selected.id === node.id}
              dropTarget={dropTarget === node.id}
              movable={canMoveNode(node)}
              port={canEdit && (connectView ? isSource(node) && !node.missing : !serverView || !!node.environment)
                ? (connectView ? text.port : 'Drag to another card to draw a link')
                : null}
              linkCount={linkCounts[node.id] || 0}
              zoneNames={zoneNames(node)}
              onpress={(e, card) => startCardDrag(node.id, card, e)}
              onconnect={(e, port) => startConnect(node.id, port, e)}
              onselect={() => select({ kind: 'node', id: node.id })}
              onhover={on => hoverCard(node.id, on)}
            />
          {/each}
        </div>
        <!-- Link end dots sit above the cards, on their edges (like the deploy canvas ports). -->
        <div class="pointer-events-none absolute top-0 left-0">
          {#each shapes as shape (shape.link.id)}
            {#each [shape.from, shape.to] as at, i (i)}
              <span
                class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow dark:border-raised {shape.selected ? 'bg-coollabs dark:bg-warning' : 'bg-neutral-400 dark:bg-neutral-500'} {shape.faded ? 'opacity-30' : ''}"
                style:left="{at.x}px"
                style:top="{at.y}px"
              ></span>
            {/each}
          {/each}
        </div>
      </div>
    </div>

    {#if error || empty}
      <div class="pointer-events-none absolute inset-0 flex items-center justify-center p-6">
        <div class="pointer-events-auto flex max-w-sm flex-col items-center rounded-xl border border-neutral-200 bg-white px-6 py-8 text-center shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
          <div class="mb-3 flex size-9 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
            <Icon name="service" class="size-4" />
          </div>
          {#if error}
            <p class="text-[13px] font-semibold text-black dark:text-fg">Could not load the canvas</p>
            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-faint">{error}</p>
            <button type="button" class="button mt-4" onclick={() => load()}>Try again</button>
          {:else}
            <p class="text-[13px] font-semibold text-black dark:text-fg">
              {connectView ? text.empty : ds.empty ? ds.empty : serverView ? 'No resources on this server yet' : 'No resources in this environment yet'}
            </p>
            {#if ds.new}
              <a class="button button-highlighted mt-4" href={ds.new}><Icon name="plus" />Add resource</a>
            {/if}
          {/if}
        </div>
      </div>
    {/if}

    {#if graph && (panelNode || panelLink)}
      {#key `${selected.kind}:${selected.id}`}
        <Panel
          {graph}
          node={panelNode}
          link={panelLink}
          {connectView}
          {text}
          {canEdit}
          top={panelTop}
          {nodeById}
          {zoneNames}
          onselect={select}
          onclose={closePanel}
          oncreate={createLink}
          onremove={removeLink}
          onlabel={saveLabel}
          ondelete={deleteLink}
        />
      {/key}
    {/if}

    <div class="pointer-events-none absolute bottom-4 left-1/2 z-30 flex -translate-x-1/2 flex-col items-center gap-2">
      {#if toastMessage}
        <div
          class={toastMessage.bad
            ? 'pointer-events-auto max-w-md rounded-lg border border-red-200 bg-white px-3 py-2 text-[12px] text-red-700 shadow-lg dark:border-red-500/30 dark:bg-panel dark:text-red-300'
            : 'pointer-events-auto max-w-md rounded-lg border border-neutral-200 bg-white px-3 py-2 text-[12px] text-neutral-700 shadow-lg dark:border-white/[0.08] dark:bg-panel dark:text-fg'}
          role={toastMessage.bad ? 'alert' : 'status'}
        >{toastMessage.text}</div>
      {/if}
    </div>
  </div>
</div>

<style>
  @keyframes -global-ec-dash {
    to {
      stroke-dashoffset: -12;
    }
  }
  :global([data-ec-root] .ec-temp) {
    stroke-dasharray: 6 6;
    animation: ec-dash 0.6s linear infinite;
  }
  :global([data-ec-root] .ec-missing) {
    stroke-dasharray: 5 5;
  }
</style>
