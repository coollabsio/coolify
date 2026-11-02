<script>
  import { ICONS } from '../../deploy-canvas.js';
  import Icon from './Icon.svelte';
  import { cv, showPane } from './state.svelte.js';

  // Run panel: an island with the steps as status lights, then the logs
  // ("Steps", and a tab per deployed resource).
  const r = $derived(cv.run);
  // "Docker image · nginx #2" → "nginx #2"; the full name is the tooltip.
  const short = label => (label.includes(' · ') ? label.slice(label.indexOf(' · ') + 3) : label);
</script>

<aside class="cv-run" hidden={!r.open}>
  <header>
    <div><b>{r.title}</b><span>{r.sub}</span></div>
    <button class="cv-x" title="Close" onclick={() => { if (!cv.running) r.open = false; }}><Icon name={ICONS.x} /></button>
  </header>
  <div class="cv-steps">
    {#each r.steps as step (step.id)}
      <div class="cv-step {step.state}" data-id={step.id}>
        <i></i>
        <div><span>{step.label}</span><small>{step.message}</small></div>
      </div>
    {/each}
  </div>
  <div class="cv-seg cv-tabs" role="tablist" hidden={r.panes.length < 2}>
    {#each r.panes as pane (pane.key)}
      <button
        type="button"
        role="tab"
        class:on={r.active === pane.key}
        aria-selected={r.active === pane.key}
        title="{pane.label}{pane.status ? `: ${pane.status}` : ''}"
        onclick={() => showPane(pane.key)}
      >
        <i class={pane.state}></i><span>{short(pane.label)}</span>
      </button>
    {/each}
  </div>
  <div class="cv-logs-wrap">
    {#each r.panes as pane (pane.key)}
      <pre class="cv-logs" data-key={pane.key} hidden={r.active !== pane.key}>{#each pane.lines as line}<span class={line.cls}>{`${line.text}\n`}</span>{/each}</pre>
    {/each}
  </div>
  <footer>
    <a class="button" target="_blank" href={r.openHref || undefined} hidden={!r.showOpen}>Open</a>
  </footer>
</aside>
