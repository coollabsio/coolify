<script>
  // Zoom and fit controls that float in the bottom-left corner of a canvas.
  // With `onrefresh` (environment canvas) it also has a Refresh button. `inline`: the parent places it.
  // `children`: more actions after the zoom buttons (deploy canvas header).
  import Icon from './environment/Icon.svelte';
  import { zoomShortcut } from '../canvas-core.js';

  let { zoom, onzoom, onfit, onrefresh = null, loading = false, inline = false, children = null } = $props();

  const button = 'grid h-7 min-w-7 place-items-center rounded-md px-1.5 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-neutral-900 disabled:pointer-events-none disabled:opacity-50 dark:text-fg-dim dark:hover:bg-white/[0.07] dark:hover:text-fg';

  /** Cmd/Ctrl + / - / 0 zoom the canvas instead of the page. */
  function onKeydown(e) {
    const shortcut = e.defaultPrevented ? null : zoomShortcut(e);
    if (!shortcut) return;
    e.preventDefault();
    onzoom(shortcut === 'in' ? zoom * 1.2 : shortcut === 'out' ? zoom / 1.2 : 1);
  }
</script>

<svelte:window onkeydown={onKeydown} />

<div class="{inline ? 'shrink-0' : 'absolute bottom-3 left-3 z-20'} flex items-center gap-0.5 rounded-lg border border-neutral-200 bg-white/85 p-0.5 shadow-sm backdrop-blur-sm dark:border-white/[0.08] dark:bg-panel/85" data-canvas-controls>
  <button type="button" class={button} title="Zoom out" aria-label="Zoom out" onclick={() => onzoom(zoom / 1.2)}><Icon name="minus" /></button>
  <button type="button" class="{button} w-12 text-[12px] font-medium tabular-nums" title="Reset zoom to 100%" onclick={() => onzoom(1)}>{Math.round(zoom * 100)}%</button>
  <button type="button" class={button} title="Zoom in" aria-label="Zoom in" onclick={() => onzoom(zoom * 1.2)}><Icon name="plus" /></button>
  <span class="mx-0.5 h-4 w-px bg-neutral-200 dark:bg-white/[0.08]"></span>
  <button type="button" class={button} title="Fit to screen" aria-label="Fit to screen" onclick={onfit}><Icon name="fit" /></button>
  {#if onrefresh}
    <button type="button" class={button} title="Load the latest status" aria-label="Refresh" disabled={loading} onclick={onrefresh}><Icon name="refresh" class="size-3.5 {loading ? 'animate-spin' : ''}" /></button>
  {/if}
  {#if children}
    <div class="ml-0.5 flex items-center gap-2 border-l border-neutral-200 pr-0.5 pl-2 dark:border-white/[0.08]">{@render children()}</div>
  {/if}
</div>
