<script>
  import { ICONS, logoOf, nextOf, onlyOption, planOf, prevOf, resourceLabel, sourceAbove, TYPES } from '../../deploy-canvas.js';
  import Field from './Field.svelte';
  import Icon from './Icon.svelte';
  import { cv, dragNode, removeNode, runFlow, startConnect, track } from './state.svelte.js';

  // A step card: header (drag handle), fields, ports, and its run status.
  let { n } = $props();

  const stop = e => e.stopPropagation();
  const t = $derived(TYPES[n.type]);
  const st = $derived(cv.runState[n.id]);
  const plan = $derived(planOf(cv.flow));
  // The logo of what the step creates (engine, template, build); a logo that fails to load
  // shows its fallback, then the step's glyph.
  const logo = $derived(logoOf(n));
  let broken = $state({});
  const logoSrc = $derived(logo && (broken[logo.src] ? logo.fallback : logo.src));
  const fields = $derived(t.fields.filter(f => !f.show || f.show(n.data)));
  // Start, Deploy and the first Server stay.
  const removable = $derived(n.type !== 'start' && n.type !== 'deploy' && n.id !== 'server');
  const hasOut = $derived(!!nextOf(n.id, cv.flow) || plan.lines.some(l => l.end.id === n.id));
  const hasIn = $derived(!!prevOf(n.id, cv.flow) || (n.type === 'deploy' && plan.lines.length > 0));
  // Blank canvas: the Server ring pulses and a dashed trail shows where to drag it.
  const blank = $derived(n.id === 'server' && cv.flow.nodes.every(x => ['start', 'server', 'deploy'].includes(x.type)));

  // A select with `only: true` takes its option when it has exactly one.
  $effect(() => {
    cv.listsVersion;
    for (const f of fields) {
      if (f.kind !== 'select' || !f.only) continue;
      const only = onlyOption(f.load ? f.load(n.data) : f.options, n.data[f.key]);
      if (only && only !== n.data[f.key]) n.data[f.key] = only;
    }
  });
</script>

<div
  class="cv-node"
  class:start={n.type === 'start'}
  class:running={st?.state === 'running'}
  class:done={st?.state === 'done'}
  class:failed={st?.state === 'failed'}
  class:invalid={cv.invalid[n.id]}
  class:drop-ok={cv.dropTarget === n.id}
  class:has-out={hasOut}
  class:has-in={hasIn}
  class:blank
  data-id={n.id}
  style:left="{n.x}px"
  style:top="{n.y}px"
  use:track={n.id}
>
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="cv-node-head" onpointerdown={e => dragNode(n, e)}>
    <i class:cv-logo={logoSrc && !broken[logoSrc]} class:padded={logo?.padded}>
      {#if logoSrc && !broken[logoSrc]}
        <img class:cv-light={logo.dark} alt="" src={logoSrc} onerror={() => (broken[logoSrc] = true)} />
        {#if logo.dark}<img class="cv-dark-only" alt="" src={logo.dark} />{/if}
      {:else}
        <Icon name={t.icon} />
      {/if}
    </i>
    <div><b>{t.title}</b><small>{t.sub}</small></div>
    {#if removable}
      <button class="cv-x" title="Remove step" onpointerdown={stop} onclick={() => removeNode(n.id)}><Icon name={ICONS.x} /></button>
    {/if}
  </div>
  <div class="cv-node-body">
    {#each fields as f (f)}
      <Field {n} {f} />
    {/each}
    {#if n.type === 'deploy'}
      {#if !plan.lines.length}
        <p class="cv-note">Every resource you connect to a Server shows here.</p>
      {/if}
      {#each plan.lines as { source } (source.id)}
        <!-- svelte-ignore a11y_label_has_associated_control -->
        <div class="cv-field"><label>{resourceLabel(source)}</label></div>
      {/each}
      <!-- Save only creates the resources in Coolify; Deploy also starts them. -->
      <div class="cv-actions">
        <button type="button" class="button cv-deploy-now" disabled={!!cv.running} title="Create the resources in Coolify without starting them" onpointerdown={stop} onclick={() => runFlow('save')}>
          <span>{cv.running === 'save' ? 'Working…' : 'Save only'}</span>
        </button>
        <button type="button" class="button button-highlighted cv-deploy-now" disabled={!!cv.running} title="Create the resources and deploy them" onpointerdown={stop} onclick={() => runFlow('deploy')}>
          <Icon name={ICONS.deploy} /><span>{cv.running === 'deploy' ? 'Working…' : 'Deploy'}</span>
        </button>
      </div>
    {/if}
    {#if t.stage === 'config' && !sourceAbove(n.id, cv.flow)}
      <p class="cv-note">Connect it after a source step.</p>
    {/if}
  </div>
  {#if n.type !== 'start'}<span class="cv-port in"></span>{/if}
  {#if st?.message}<div class="cv-status {st.state}">{st.message}</div>{/if}
  {#if n.type !== 'deploy'}
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <span class="cv-port out" class:armed={cv.armed === n.id} title="Drag to connect the next step" onpointerdown={e => startConnect(n, e)}></span>
  {/if}
  {#if blank}
    <div class="cv-hint"><span>Drag and drop to add a step</span></div>
  {/if}
</div>
