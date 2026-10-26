// The deploy canvas look: islands on a backdrop. The colors are CSS variables
// on .cv-root, so a custom color scheme can replace them. Injected once as a
// <style> (outside the CSS build, which would rewrite light-dark()).
const CSS = `
  .cv-root {
    /* The look of the environment canvas (environment-canvas.js): the app's
       own color tokens (app.css), so custom color schemes apply too. */
    --cv-island: light-dark(#ffffff, var(--color-panel));
    --cv-backdrop: light-dark(#fafafa, var(--color-app));
    --cv-card: light-dark(#ffffff, var(--color-raised));
    --cv-popover: light-dark(#ffffff, var(--color-panel));
    --cv-text: light-dark(#171717, var(--color-fg));
    --cv-subtle: light-dark(#737373, var(--color-fg-faint));
    --cv-placeholder: light-dark(#a3a3a3, var(--color-fg-faint));
    /* The app's card border (app.css), as on the environment canvas cards. */
    --cv-line: var(--coollabs-hairline, light-dark(#e5e5e5, rgb(255 255 255 / 0.08)));
    --cv-input-bg: light-dark(#fafafa, rgb(255 255 255 / 0.04));
    --cv-input-line: light-dark(#e5e5e5, rgb(255 255 255 / 0.08));
    --cv-brand: light-dark(var(--color-coollabs), var(--color-brand));
    --cv-brand-text: light-dark(var(--color-coollabs), var(--color-brand-light));
    position: absolute; inset: 0; z-index: 10;
    display: grid; grid-template-columns: 15.5rem minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr);
    gap: 8px; padding: 8px;
    background: var(--cv-backdrop); color: var(--cv-text);
    /* The dots cover the whole backdrop, also under the Steps panel. pan()
       (canvas-core.js) lines them up with the viewport through these variables. */
    background-image: var(--canvas-dots-image, radial-gradient(circle, light-dark(rgb(0 0 0 / 0.13), rgb(255 255 255 / 0.09)) 1px, transparent 1.3px));
    background-size: var(--canvas-dots-size, 20px 20px);
    background-position: var(--canvas-dots-position, 0 0);
    color-scheme: light; font: 13px/1.4 var(--font-sans, ui-sans-serif, system-ui, sans-serif);
    -webkit-font-smoothing: antialiased;
  }
  .cv-root.cv-dark { color-scheme: dark; }
  .cv-root *, .cv-root *::before, .cv-root *::after { box-sizing: border-box; }
  /* Rounded boxes on the backdrop: the top bar and the step list. */
  .cv-island { border: 1px solid var(--cv-line); border-radius: 8px; background: var(--cv-island); }
  .cv-root svg { width: 1rem; height: 1rem; flex: none; }
  .cv-root button:not(.button, [data-canvas-controls] button) { font: inherit; }

  /* Header island on small screens; on desktop the header is in the zoom controls bar. */
  .cv-top { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.625rem 0.75rem; }
  .cv-root.cv-docked { grid-template-rows: minmax(0, 1fr); }
  .cv-btn {
    display: inline-flex; align-items: center; gap: 0.4375rem; height: 2rem; padding: 0 0.75rem;
    border: 1px solid var(--cv-line); border-radius: 8px; background: var(--cv-input-bg);
    color: var(--cv-text); font-weight: 500; font-size: 12.5px; cursor: pointer; text-decoration: none; white-space: nowrap;
    transition: border-color 150ms ease, background-color 150ms ease;
  }
  .cv-btn:hover { border-color: light-dark(rgb(0 0 0 / 0.16), rgb(255 255 255 / 0.16)); }
  .cv-btn svg { width: 0.875rem; height: 0.875rem; }
  .cv-btn.icon { width: 2rem; padding: 0; justify-content: center; }
  .cv-btn.ghost { border-color: transparent; background: transparent; }
  .cv-btn.ghost:hover { background: light-dark(rgb(0 0 0 / 0.04), rgb(255 255 255 / 0.05)); }

  /* Small-screen notice: only below 768px, until it is closed. */
  .cv-notice { display: none; }
  @media (max-width: 767px) {
    .cv-root:has(> .cv-notice) { grid-template-rows: auto auto minmax(0, 1fr); }
    .cv-notice { grid-column: 1 / -1; display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.625rem 0.5rem 0.625rem 0.75rem; background: #dc2626; color: #fff; font-size: 12.5px; line-height: 1.125rem; }
    .cv-notice > svg { margin-top: 0.0625rem; }
    .cv-notice p { flex: 1; min-width: 0; margin: 0; }
    .cv-notice a { color: inherit; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
    .cv-notice .cv-x { opacity: 1; margin-top: -0.1875rem; color: inherit; }
    .cv-notice .cv-x:hover { background: rgb(255 255 255 / 0.18); color: inherit; }
  }

  /* Palette island */
  /* Hugs its steps instead of filling the row; scrolls when the screen is short. */
  /* The zoom controls sit below the Steps panel, so the panel leaves room for them. */
  .cv-palette { display: flex; flex-direction: column; align-self: start; max-height: calc(100% - 3.25rem); overflow-y: auto; padding: 1rem 0.625rem 0.75rem; }
  .cv-root > [data-canvas-controls] { bottom: 8px; left: 8px; }
  .cv-palette-head { padding: 0 0.5rem 0.75rem; margin: 0 -0.625rem 0.5rem; padding-inline: 1.125rem; border-bottom: 1px solid var(--cv-line); }
  .cv-palette-head b { display: block; font-size: 14px; font-weight: 600; }
  .cv-palette-head span { color: var(--cv-subtle); font-size: 12px; }
  .cv-palette h4 { margin: 0.75rem 0.5rem 0.25rem; color: var(--cv-subtle); font-size: 11px; font-weight: 500; }
  .cv-item {
    display: flex; align-items: center; gap: 0.625rem; width: 100%; min-height: 2.5rem; padding: 0.25rem 0.5rem 0.25rem 0.375rem;
    border: 0; border-radius: 9px; background: none; color: var(--cv-text); text-align: left;
    cursor: grab; user-select: none; touch-action: none;
  }
  /* Icon tiles: the neutral 32px tile of Coolify's collection cards. */
  .cv-item i, .cv-node-head i { display: grid; flex: none; place-items: center; width: 2rem; height: 2rem; border: 1px solid var(--cv-line); border-radius: 8px; background: light-dark(#fafafa, rgb(255 255 255 / 0.04)); color: light-dark(#737373, var(--color-fg-dim, #c4c4c4)); }
  .cv-item i svg { width: 1rem; height: 1rem; }
  /* A resource logo fills the tile, like the environment canvas cards. */
  .cv-node-head i.cv-logo { overflow: hidden; }
  .cv-node-head i.cv-logo img { width: 100%; height: 100%; object-fit: contain; }
  .cv-node-head i.cv-logo.padded img { padding: 0.25rem; }
  .cv-node-head .cv-dark-only, .cv-root.cv-dark .cv-node-head .cv-light { display: none; }
  .cv-root.cv-dark .cv-node-head .cv-dark-only { display: block; }
  /* Palette tiles use the app's 3D button depth (app.css .button): a 2px ledge,
     a lift on hover, and a press on click. */
  /* Only the tile reacts; the row itself has no hover background. */
  .cv-item i { --cv-depth: light-dark(rgb(0 0 0 / 0.22), rgb(255 255 255 / 0.08)); box-shadow: 0 2px 0 var(--cv-depth); transition: border-color 120ms, background-color 120ms, box-shadow 80ms, transform 80ms; }
  .cv-item:hover i { transform: translateY(-1px); box-shadow: 0 3px 0 var(--cv-depth); border-color: light-dark(rgb(0 0 0 / 0.18), rgb(255 255 255 / 0.18)); background: light-dark(#fff, rgb(255 255 255 / 0.08)); }
  .cv-item:focus-visible { outline: none; }
  .cv-item:focus-visible i { outline: 1px solid var(--color-accent, currentColor); outline-offset: 2px; }
  .cv-item:active i { transform: translateY(2px); box-shadow: none; }
  /* Appearance → Style → Flat: no ledge, lift, or press, like app.css .button. */
  html[data-nav-items="flat"] .cv-item i { --cv-depth: transparent; transform: none; }
  .cv-item b { display: block; font-size: 13px; font-weight: 500; }
  .cv-item small { display: block; color: var(--cv-subtle); font-size: 11.5px; }

  /* Canvas island */
  .cv-stage { position: relative; overflow: hidden; border: 0; background: transparent; }
  /* Wide screens: the canvas fills the whole area to the screen edges and the
     Steps panel floats over it, so cards slide under the panel, not cut off. */
  @media (min-width: 768px) {
    .cv-stage { grid-column: 1 / -1; grid-row: -2 / -1; margin: 0 -8px -8px; }
    .cv-docked .cv-stage { margin-top: -8px; }
    .cv-palette { grid-column: 1; grid-row: -2 / -1; position: relative; z-index: 15; box-shadow: 0 16px 40px -20px rgb(0 0 0 / 0.35); }
  }
  .cv-viewport {
    position: absolute; inset: 0; cursor: grab; touch-action: none;
    /* The dots are on .cv-root, so the viewport has none of its own. */
    background-image: none;
  }
  .cv-viewport.panning { cursor: grabbing; }
  .cv-world { position: absolute; top: 0; left: 0; transform-origin: 0 0; }
  .cv-edges { position: absolute; top: 0; left: 0; width: 1px; height: 1px; overflow: visible; pointer-events: none; }
  .cv-edges path { fill: none; stroke: light-dark(#a3a3a3, rgb(255 255 255 / 0.35)); stroke-width: 1.75; }
  .cv-edges path.hit { stroke: transparent; stroke-width: 14; pointer-events: stroke; cursor: pointer; }
  .cv-edges path.hit:hover + path, .cv-edges path.live { stroke: color-mix(in srgb, var(--cv-brand) 70%, transparent); }
  /* The line being dragged: moving dashes, like the environment canvas (.ec-temp). */
  .cv-edges path.temp { stroke: var(--cv-brand); stroke-width: 2; stroke-linecap: round; stroke-dasharray: 6 6; animation: cv-flow 0.6s linear infinite; }
  .cv-edges path.flow { stroke: var(--cv-brand); stroke-dasharray: 6 6; animation: cv-flow 0.8s linear infinite; }
  @keyframes cv-flow { to { stroke-dashoffset: -12; } }

  /* Nodes: a card like ux-polish's flow boxes — badge + title + subtitle, then fields. */
  .cv-node {
    position: absolute; width: 18.5rem;
    border: 1px solid var(--cv-line); border-radius: 12px;
    background: var(--cv-card);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    transition: box-shadow 150ms ease, border-color 150ms ease;
  }
  .cv-node:hover { border-color: light-dark(#d4d4d4, rgb(255 255 255 / 0.16)); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); }
  .cv-node.start { width: 16.5rem; }
  .cv-node.running { border-color: var(--cv-brand); box-shadow: 0 0 0 4px color-mix(in srgb, var(--cv-brand) 16%, transparent); }
  .cv-node.done { border-color: color-mix(in srgb, #10b981 70%, transparent); }
  .cv-node.failed, .cv-node.invalid { border-color: color-mix(in srgb, #ef4444 75%, transparent); box-shadow: 0 0 0 4px color-mix(in srgb, #ef4444 12%, transparent); }
  .cv-node.drop-ok { border-color: var(--cv-brand); box-shadow: 0 0 0 4px color-mix(in srgb, var(--cv-brand) 16%, transparent); }
  .cv-root { user-select: none; }
  .cv-root :is(input, textarea, .cv-logs) { user-select: text; }
  .cv-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
  .cv-deploy-now { width: 100%; }
  .cv-node-head { display: flex; align-items: center; gap: 0.625rem; height: 3.25rem; padding: 0 0.5rem 0 0.75rem; cursor: grab; user-select: none; }
  .cv-node-head i svg { width: 1rem; height: 1rem; }
  .cv-node-head div { display: grid; min-width: 0; margin-right: auto; }
  .cv-node-head b { overflow: hidden; font-size: 13.5px; font-weight: 600; line-height: 1.125rem; text-overflow: ellipsis; white-space: nowrap; }
  .cv-node-head small { overflow: hidden; color: var(--cv-subtle); font-size: 12px; line-height: 1rem; text-overflow: ellipsis; white-space: nowrap; }
  .cv-x { display: grid; place-items: center; width: 1.625rem; height: 1.625rem; padding: 0; border: 0; border-radius: 7px; background: none; color: var(--cv-subtle); cursor: pointer; opacity: 0; transition: opacity 120ms ease; }
  .cv-node:hover .cv-x, .cv-run .cv-x { opacity: 1; }
  .cv-x:hover { background: light-dark(rgb(0 0 0 / 0.06), rgb(255 255 255 / 0.08)); color: var(--cv-text); }
  .cv-x svg { width: 0.8125rem; height: 0.8125rem; }
  .cv-node-body { display: grid; gap: 0.75rem; padding: 0.875rem 0.875rem 0.875rem; border-top: 1px solid var(--cv-line); }
  .cv-field { display: grid; gap: 0.375rem; min-width: 0; }
  .cv-field > label { color: var(--cv-subtle); font-size: 12px; font-weight: 500; }
  .cv-field > label em { color: var(--cv-brand-text); font-style: normal; }
  .cv-root .cv-input {
    width: 100%; height: 2rem; margin: 0; padding: 0 0.625rem; border: 1px solid var(--cv-input-line); border-radius: 8px;
    background: var(--cv-input-bg); color: var(--cv-text); font: inherit; font-size: 12.5px; outline: none; box-shadow: none;
  }
  .cv-root .cv-input::placeholder { color: var(--cv-placeholder); }
  .cv-root .cv-input:focus, .cv-root .cv-input:focus-visible {
    border-color: color-mix(in srgb, var(--cv-brand) 60%, transparent) !important;
    outline: none !important;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--cv-brand) 14%, transparent) !important;
  }
  .cv-root select.cv-input { padding-right: 1.75rem; appearance: none; background-image: url("data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%238a8a8a%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22m6%209%206%206%206-6%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.875rem; }
  .cv-root textarea.cv-input { height: auto; min-height: 4.25rem; padding: 0.4375rem 0.625rem; line-height: 1.3rem; resize: vertical; }
  .cv-root .cv-input.mono, .cv-root textarea.cv-input { font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 12px; }
  .cv-seg { display: flex; gap: 2px; padding: 2px; border-radius: 8px; background: light-dark(#f4f4f5, rgb(255 255 255 / 0.05)); }
  .cv-seg button { flex: 1; height: 1.625rem; padding: 0 0.5rem; border: 0; border-radius: 6px; background: none; color: var(--cv-subtle); font-size: 12px; font-weight: 500; cursor: pointer; }
  .cv-seg button.on { background: light-dark(#fff, rgb(255 255 255 / 0.09)); color: var(--cv-text); box-shadow: 0 1px 2px rgb(0 0 0 / 0.08), 0 0 0 1px var(--cv-line); }
  .cv-note { margin: 0; color: var(--cv-subtle); font-size: 11.5px; line-height: 1.4; }
  .cv-note.bad { color: light-dark(#dc2626, #f87171); }
  .cv-status { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.625rem 0.875rem; border-top: 1px solid var(--cv-line); font-size: 12px; line-height: 1.35; overflow-wrap: anywhere; }
  .cv-status::before { content: ''; flex: none; width: 0.5rem; height: 0.5rem; margin-top: 0.25rem; border-radius: 50%; background: var(--st); }
  .cv-status.running { --st: var(--cv-brand); }
  .cv-status.done { --st: #10b981; }
  .cv-status.failed { --st: #ef4444; color: light-dark(#dc2626, #f87171); }

  /* Ports: filled dots on the header line, like the environment canvas. */
  .cv-port {
    position: absolute; top: calc(1.625rem - 0.375rem); width: 0.75rem; height: 0.75rem; border-radius: 50%;
    border: 2px solid var(--cv-card); background: light-dark(#a3a3a3, #737373); z-index: 2;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    transition: transform 120ms ease, background-color 120ms ease;
  }
  .cv-port.out { right: -0.375rem; cursor: crosshair; }
  .cv-port.in { left: -0.375rem; }
  .cv-port.out:hover, .cv-port.out.armed { background: var(--cv-brand); transform: scale(1.2); }

  /* Collapsed steps: icons only. */
  .cv-root { transition: grid-template-columns 260ms cubic-bezier(0.4, 0, 0.2, 1); }
  .cv-root.cv-folded { grid-template-columns: 3.75rem minmax(0, 1fr); }
  .cv-palette-head { display: flex; align-items: flex-start; gap: 0.5rem; }
  .cv-palette-head > div { min-width: 0; margin-right: auto; }
  .cv-fold { opacity: 1; margin-top: 0.0625rem; }
  .cv-fold svg { width: 1rem; height: 1rem; }
  .cv-folded .cv-palette { padding-inline: 0.5rem; overflow-x: hidden; }
  .cv-folded .cv-palette-head { justify-content: center; margin-inline: -0.5rem; padding-inline: 0; }
  /* Only the step list folds; the "Add the next step" menu keeps its names. */
  .cv-folded .cv-palette-head > div, .cv-folded .cv-palette .cv-item > span { display: none; }
  .cv-folded .cv-fold svg { transform: scaleX(-1); }
  .cv-folded .cv-palette h4 { height: 1px; margin: 0.5rem 0.375rem; overflow: hidden; color: transparent; background: var(--cv-line); }
  .cv-folded .cv-palette .cv-item { justify-content: center; padding: 0.25rem; }

  /* Our dropdown */
  .cv-root .cv-select { display: flex; align-items: center; gap: 0.5rem; text-align: left; cursor: pointer; }
  .cv-root .cv-select > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-root .cv-select.empty > span { color: var(--cv-placeholder); }
  .cv-root .cv-select > svg { width: 0.875rem; height: 0.875rem; color: var(--cv-subtle); transition: transform 180ms ease; }
  .cv-root .cv-select.open > svg { transform: rotate(180deg); }
  .cv-root .cv-select.open { border-color: color-mix(in srgb, var(--cv-brand) 60%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--cv-brand) 14%, transparent); }
  .cv-root .cv-select:disabled { cursor: default; opacity: 0.7; }
  .cv-dd {
    position: fixed; z-index: 60; display: flex; flex-direction: column; max-height: 17rem; padding: 0.3125rem;
    border: 1px solid var(--cv-line); border-radius: 10px; background: var(--cv-popover);
    box-shadow: 0 16px 40px -14px rgb(0 0 0 / 0.4); animation: cv-dd-in 120ms ease-out;
  }
  @keyframes cv-dd-in { from { opacity: 0; transform: translateY(-3px); } }
  .cv-dd-search {
    flex: none; height: 1.875rem; margin-bottom: 0.25rem; padding: 0 0.625rem; border: 1px solid var(--cv-input-line); border-radius: 7px;
    background: var(--cv-input-bg); color: var(--cv-text); font: inherit; font-size: 12.5px; outline: none;
  }
  .cv-dd-search:focus { border-color: color-mix(in srgb, var(--cv-brand) 60%, transparent); }
  .cv-dd-list { overflow-x: hidden; overflow-y: auto; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1px; }
  .cv-dd-opt {
    display: flex; align-items: center; gap: 0.5rem; width: 100%; min-height: 2rem; padding: 0 0.5rem 0 0.625rem;
    border: 0; border-radius: 7px; background: none; color: var(--cv-text); font-size: 12.5px; text-align: left; cursor: pointer;
  }
  .cv-dd-opt > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-dd-opt > svg { width: 0.875rem; height: 0.875rem; color: var(--cv-brand-text); opacity: 0; }
  .cv-dd-opt:focus, .cv-dd-opt:focus-visible { outline: none; box-shadow: none; }
  .cv-dd-opt.on { font-weight: 600; }
  .cv-badge { flex: none; padding: 0.0625rem 0.4375rem; border: 1px solid light-dark(rgb(217 119 6 / 0.3), rgb(251 191 36 / 0.3)); border-radius: 999px; background: light-dark(rgb(217 119 6 / 0.1), rgb(251 191 36 / 0.12)); color: light-dark(#b45309, #fbbf24); font-size: 11px; font-style: normal; font-weight: 500; line-height: 1.4; }
  .cv-dd-opt.on > svg { opacity: 1; }
  .cv-dd-opt.active { background: light-dark(rgb(0 0 0 / 0.045), rgb(255 255 255 / 0.06)); }
  .cv-dd-none { margin: 0; padding: 0.5rem 0.625rem; color: var(--cv-subtle); font-size: 12px; }
  .cv-dd-add { flex: none; margin-top: 0.25rem; padding-top: 0.25rem; border-top: 1px solid var(--cv-line); }
  .cv-dd-opt.cv-dd-new { color: var(--cv-brand-text); font-weight: 500; }
  .cv-dd-opt.cv-dd-new > svg { opacity: 1; color: inherit; }
  .cv-dd-form { display: flex; gap: 0.375rem; }
  .cv-dd-form .cv-dd-search { flex: 1; min-width: 0; margin: 0; }
  .cv-dd-form .button { height: 1.875rem; }
  .cv-dd-add .cv-note:not(:empty) { padding: 0.375rem 0.25rem 0.125rem; }

  /* Result pill after Deploy: the live address(es). */
  .cv-result {
    position: absolute; display: flex; align-items: center; gap: 0.25rem; height: 2.375rem; padding: 0 0.25rem 0 0.875rem;
    border: 1px solid color-mix(in srgb, #10b981 45%, transparent); border-radius: 999px;
    background: var(--cv-card); box-shadow: 0 8px 24px -12px rgb(0 0 0 / 0.25); white-space: nowrap;
  }
  .cv-result > i { width: 0.5rem; height: 0.5rem; margin-right: 0.25rem; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 4px color-mix(in srgb, #10b981 18%, transparent); }
  .cv-result a { display: inline-flex; align-items: center; gap: 0.375rem; height: 1.75rem; padding: 0 0.625rem; border-radius: 999px; color: var(--cv-text); font-weight: 600; text-decoration: none; }
  .cv-result a:hover { background: light-dark(rgb(0 0 0 / 0.05), rgb(255 255 255 / 0.07)); }
  .cv-result a svg { width: 0.8125rem; height: 0.8125rem; color: var(--cv-subtle); }
  .cv-result .cv-x { opacity: 1; }
  .cv-result .cv-port.in { top: calc(50% - 0.375rem); }

  /* Next-step menu, toasts */
  .cv-menu { position: absolute; z-index: 20; width: 15rem; padding: 0.375rem; border: 1px solid var(--cv-line); border-radius: 12px; background: var(--cv-island); box-shadow: 0 16px 40px -16px rgb(0 0 0 / 0.4); }
  .cv-menu h5 { margin: 0.25rem 0.5rem 0.375rem; color: var(--cv-subtle); font-size: 11.5px; font-weight: 500; }
  .cv-menu .cv-item { cursor: pointer; }
  .cv-toast { position: absolute; left: 50%; bottom: 1rem; z-index: 30; max-width: 80%; transform: translateX(-50%); padding: 0.5rem 0.875rem; border: 1px solid var(--cv-line); border-radius: 8px; background: var(--cv-island); color: var(--cv-text); font-size: 12px; pointer-events: none; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); }
  .cv-toast.bad { border-color: light-dark(#fecaca, rgb(239 68 68 / 0.3)); color: light-dark(#b91c1c, #fca5a5); }
  /* Blank canvas: the Start ring pulses and a dashed trail shows where to drag it. */
  .cv-node.blank .cv-port.out { background: var(--cv-brand); }
  .cv-node.blank .cv-port.out::after { content: ''; position: absolute; inset: -2px; border-radius: 50%; border: 2px solid var(--cv-brand); animation: cv-breathe 1.8s ease-out infinite; pointer-events: none; }
  .cv-hint { position: absolute; left: calc(100% + 0.625rem); top: 1.625rem; transform: translateY(-50%); display: flex; align-items: center; gap: 0.5rem; color: var(--cv-brand-text); font-size: 12px; font-weight: 500; white-space: nowrap; pointer-events: none; }
  .cv-hint::before { content: ''; width: 4.5rem; border-top: 2px dashed currentColor; opacity: 0.7; animation: cv-hint-grow 1.8s ease-in-out infinite; transform-origin: left; }
  .cv-hint span { animation: cv-hint-fade 1.8s ease-in-out infinite; }
  .cv-node:has(.cv-port.armed) .cv-hint, .cv-node:has(.cv-port.armed) .cv-port.out::after { display: none; }
  @keyframes cv-hint-grow { 0% { transform: scaleX(0); } 60%, 100% { transform: scaleX(1); } }
  @keyframes cv-hint-fade { 0%, 30% { opacity: 0; } 60%, 100% { opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { .cv-node.blank .cv-port.out::after, .cv-hint::before, .cv-hint span { animation: none; } }

  /* Run panel: an island with the steps as status lights, then the logs. */
  .cv-run { position: absolute; top: 0.75rem; right: 0.75rem; bottom: 0.75rem; z-index: 10; display: flex; flex-direction: column; width: min(26rem, 45%); overflow: hidden; border: 1px solid var(--cv-line); border-radius: 8px; background: var(--cv-island); box-shadow: 0 20px 48px -20px rgb(0 0 0 / 0.4); }
  .cv-run[hidden] { display: none; }
  .cv-run header { display: flex; align-items: flex-start; gap: 0.5rem; padding: 1rem 0.75rem 0.875rem 1.25rem; border-bottom: 1px solid var(--cv-line); }
  .cv-run header div { display: grid; gap: 0.0625rem; margin-right: auto; }
  .cv-run header b { font-size: 14px; font-weight: 600; }
  .cv-run header span { color: var(--cv-subtle); font-size: 12.5px; }
  .cv-steps { flex: none; max-height: 40%; overflow-y: auto; display: grid; gap: 0.25rem; padding: 0.75rem 1rem 0.875rem; border-bottom: 1px solid var(--cv-line); }
  .cv-step { display: grid; grid-template-columns: 1.25rem minmax(0, 1fr); align-items: start; font-size: 12.5px; line-height: 1.25rem; }
  .cv-step i { position: relative; width: 0.5rem; height: 0.5rem; margin: 0.375rem 0 0 0.125rem; border-radius: 50%; background: transparent; box-shadow: inset 0 0 0 1.5px light-dark(#c4c4c4, #4a4a4a); }
  .cv-step.running i { background: var(--cv-brand); box-shadow: none; }
  .cv-step.running i::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: var(--cv-brand); animation: cv-breathe 1.8s ease-out infinite; }
  @keyframes cv-breathe { 0% { transform: scale(1); opacity: 0.5; } 80%, 100% { transform: scale(2.6); opacity: 0; } }
  .cv-step.done i { background: #10b981; box-shadow: none; }
  .cv-step.failed i { background: #ef4444; box-shadow: none; }
  .cv-step small { display: block; color: var(--cv-subtle); font-size: 11.5px; line-height: 1rem; overflow-wrap: anywhere; }
  .cv-step.failed small { color: light-dark(#dc2626, #f87171); }
  .cv-tabs { flex: none; margin: 0.75rem 0.75rem 0; overflow-x: auto; scrollbar-width: none; }
  .cv-tabs[hidden] { display: none; }
  .cv-tabs button { flex: 0 1 auto; display: inline-flex; align-items: center; gap: 0.375rem; min-width: 0; max-width: 13rem; }
  .cv-tabs button span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-tabs i { flex: none; width: 0.4375rem; height: 0.4375rem; border-radius: 50%; box-shadow: inset 0 0 0 1.5px light-dark(#c4c4c4, #4a4a4a); }
  .cv-tabs button:first-child { flex: none; }
  .cv-tabs button:first-child i { display: none; }
  .cv-tabs i.running { background: var(--cv-brand); box-shadow: none; animation: cv-blink 1.2s ease-in-out infinite; }
  .cv-tabs i.done { background: #10b981; box-shadow: none; }
  .cv-tabs i.failed { background: #ef4444; box-shadow: none; }
  @keyframes cv-blink { 50% { opacity: 0.35; } }
  .cv-logs-wrap { flex: 1; min-height: 0; padding: 0.75rem; display: flex; }
  .cv-logs[hidden] { display: none; }
  .cv-logs { flex: 1; min-height: 0; margin: 0; padding: 0.75rem 0.875rem; overflow: auto; border: 1px solid var(--cv-input-line); border-radius: 10px; background: var(--cv-input-bg); font: 11.5px/1.6 var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); white-space: pre-wrap; overflow-wrap: anywhere; }
  .cv-logs:empty::before { content: 'Logs show here while it deploys.'; color: var(--cv-subtle); font-family: var(--font-sans, ui-sans-serif, system-ui, sans-serif); }
  .cv-logs .err { color: light-dark(#dc2626, #f87171); }
  .cv-logs .dim { color: var(--cv-subtle); }
  .cv-run footer { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0 0.75rem 0.75rem; }
  .cv-run footer a[hidden] { display: none; }

  /* Small screens: one column. The steps are a strip above the canvas; swipe it sideways,
     tap a step to add it to the chain, or drag it down onto the canvas. */
  @media (max-width: 767px) {
    .cv-root, .cv-root.cv-folded { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto minmax(0, 1fr); }
    .cv-root:has(> .cv-notice) { grid-template-rows: auto auto auto minmax(0, 1fr); }
    .cv-root .cv-palette, .cv-root.cv-folded .cv-palette { flex-direction: row; align-items: center; gap: 0.25rem; align-self: stretch; max-height: none; padding: 0.375rem; overflow: auto hidden; }
    .cv-palette-head, .cv-palette h4, .cv-root.cv-folded .cv-palette h4 { display: none; }
    .cv-root .cv-palette .cv-item, .cv-root.cv-folded .cv-palette .cv-item { flex: none; width: auto; justify-content: flex-start; padding: 0.25rem 0.625rem 0.25rem 0.25rem; touch-action: pan-x; }
    .cv-root.cv-folded .cv-palette .cv-item > span { display: block; }
    .cv-item small { display: none; }
    .cv-run { left: 0.75rem; width: auto; }
  }
`;

export function injectStyles() {
  if (document.getElementById('deploy-canvas-style')) return;
  const style = document.createElement('style');
  style.id = 'deploy-canvas-style';
  style.textContent = CSS;
  document.head.append(style);
}
