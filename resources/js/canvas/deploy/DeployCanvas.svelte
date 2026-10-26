<script>
  import { onMount } from 'svelte';
  import { ICONS, load, nodeById, PALETTE, save, STORE, withoutSecrets } from '../../deploy-canvas.js';
  import Dropdown from './Dropdown.svelte';
  import Edges from './Edges.svelte';
  import Icon from './Icon.svelte';
  import BetaBadge from '../BetaBadge.svelte';
  import Node from './Node.svelte';
  import PaletteItem from './PaletteItem.svelte';
  import RunPanel from './RunPanel.svelte';
  import CanvasControls from '../CanvasControls.svelte';
  import { closeMenu, cv, els, fit, hideResult, leave, pan, pickNext, PORT_Y, resetCanvas } from './state.svelte.js';

  // The deploy canvas: header, step palette, and the canvas with the step
  // cards. The state lives in state.svelte.js (init() runs before the mount).
  // Theme: same as Coolify (html.dark).
  let dark = $state(document.documentElement.classList.contains('dark'));
  // Palette: full or icons only ('coolify-canvas:collapsed').
  let folded = $state(load('collapsed', false));
  // Small screens: a notice that the canvas is not optimized for mobile. CSS
  // shows it below 768px; closed once, it stays closed.
  let noticeOpen = $state(!load('mobile-notice-dismissed', false));
  // Desktop: the header sits in the zoom controls bar (bottom left), as on the
  // other canvases. Small screens: a box above the canvas.
  const desktopQuery = matchMedia('(min-width: 1024px)');
  let desktop = $state(desktopQuery.matches);

  const stop = e => e.stopPropagation();
  // A pill joined to the Deploy card: each address opens in a new tab.
  const resultNode = $derived(cv.result && nodeById(cv.result.after, cv.flow));
  const foldTitle = $derived(folded ? 'Show the step names' : 'Collapse the steps');

  function toggleFolded() {
    save('collapsed', !load('collapsed', false));
    folded = load('collapsed', false);
  }
  function closeNotice() {
    save('mobile-notice-dismissed', true);
    noticeOpen = false;
  }
  // Opens the app's confirmation modal (deploy-canvas.blade.php), which
  // dispatches deploy-canvas-reset on confirm.
  function askReset() {
    if (!cv.running) document.getElementById('deploy-canvas-reset-trigger')?.click();
  }

  // Save the flow (without secrets) and the view, 200 ms after a change.
  let saveTimer = 0;
  $effect(() => {
    const flow = JSON.stringify(withoutSecrets(cv.flow));
    const view = JSON.stringify(cv.view);
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      localStorage.setItem(`${STORE}:flow`, flow);
      localStorage.setItem(`${STORE}:view`, view);
    }, 200);
  });

  onMount(() => {
    els.pz.apply();
    if (els.fitOnOpen) requestAnimationFrame(fit);
    const theme = new MutationObserver(() => { dark = document.documentElement.classList.contains('dark'); });
    theme.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    const escape = e => { if (els.root?.isConnected && e.key === 'Escape') closeMenu(); };
    const reset = () => { if (els.root?.isConnected) resetCanvas(); };
    addEventListener('keydown', escape);
    addEventListener('deploy-canvas-reset', reset);
    const media = () => { desktop = desktopQuery.matches; };
    desktopQuery.addEventListener('change', media);
    return () => {
      desktopQuery.removeEventListener('change', media);
      theme.disconnect();
      removeEventListener('keydown', escape);
      removeEventListener('deploy-canvas-reset', reset);
      leave();
    };
  });
</script>

{#snippet header()}
  <div class="mr-auto flex min-w-0 items-center gap-2">
    {#if cv.target}
      <!-- Opened from an environment's New resource page: back to that environment. -->
      <a class="cv-back button px-2!" href="/project/{cv.target.project}/environment/{cv.target.environment}" title="Back to the environment" aria-label="Back to the environment"><Icon name="back" cls="size-4" /></a>
    {/if}
    <BetaBadge />
  </div>
  <button type="button" class="button shrink-0" title="Start again with an empty canvas" onclick={askReset}>Reset</button>
{/snippet}

<div class="cv-root" class:cv-dark={dark} class:cv-folded={folded} class:cv-docked={desktop}>
  {#if !desktop}
    <div class="cv-island cv-top">{@render header()}</div>
  {/if}

  {#if noticeOpen}
    <div class="cv-island cv-notice" role="note">
      <Icon name={ICONS.info} />
      <p>The canvas is not optimized for mobile yet. It works best on a larger screen.{#if cv.target}{' On a phone, use the '}<a href="/project/{cv.target.project}/environment/{cv.target.environment}/new">New resource page</a>.{/if}</p>
      <button type="button" class="cv-x" title="Close" aria-label="Close this notice" onclick={closeNotice}><Icon name={ICONS.x} /></button>
    </div>
  {/if}

  <aside class="cv-island cv-palette">
    <div class="cv-palette-head">
      <div><b>Steps</b><span>Drag one onto the canvas</span></div>
      <button type="button" class="cv-x cv-fold" title={foldTitle} aria-label={foldTitle} aria-expanded={!folded} onclick={toggleFolded}><Icon name={ICONS.fold} /></button>
    </div>
    {#each PALETTE as [group, types]}
      <h4>{group}</h4>
      {#each types as type}
        <PaletteItem {type} />
      {/each}
    {/each}
  </aside>

  <section class="cv-island cv-stage">
    <div class="cv-viewport" use:pan>
      <div class="cv-world">
        <Edges />
        {#each cv.flow.nodes as n (n.id)}
          <Node {n} />
        {/each}
        {#if resultNode}
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div
            class="cv-result"
            style:left="{resultNode.x + (cv.sizes[resultNode.id]?.w || 296) + 72}px"
            style:top="{resultNode.y + PORT_Y - 19}px"
            onpointerdown={stop}
          >
            <span class="cv-port in"></span>
            <i></i>
            {#if cv.result.urls.length}
              {#each cv.result.urls.slice(0, 3) as url}
                <a href={url} target="_blank" rel="noopener noreferrer" title="Open {url}">{url.replace(/^https?:\/\//, '')}<Icon name={ICONS.out} /></a>
              {/each}
            {:else}
              <a href={cv.result.href} target="_blank">Open in Coolify</a>
            {/if}
            <button class="cv-x" title="Hide" onclick={hideResult}><Icon name={ICONS.x} /></button>
          </div>
        {/if}
      </div>
    </div>
    <RunPanel />
    {#if cv.menu}
      <div class="cv-menu" style:left="{cv.menu.left}px" style:top="{cv.menu.top}px">
        <h5>Add the next step</h5>
        {#each cv.menu.valid as type}
          <PaletteItem {type} onpick={() => pickNext(type)} />
        {/each}
      </div>
    {/if}
    {#if cv.toast}
      <div class="cv-toast" class:bad={cv.toast.bad}>{cv.toast.text}</div>
    {/if}
  </section>

  <!-- Bottom left of the whole canvas, below the Steps panel. -->
  <CanvasControls zoom={cv.view.z} onzoom={z => els.pz.zoomTo(z)} onfit={fit} children={desktop ? header : null} />

  {#if cv.dropdown}
    {#key cv.dropdown?.trigger}
      <Dropdown />
    {/key}
  {/if}
</div>
