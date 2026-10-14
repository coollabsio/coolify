<script>
  import { LOCAL_ICONS } from '../../deploy-canvas.js';

  // The app's reicon glyphs (rendered by the page into [data-deploy-canvas-icons]),
  // or a few local paths in the same style. The svg is the direct child that the
  // canvas CSS expects.
  let { name, cls = '' } = $props();

  const glyph = $derived.by(() => {
    const node = document.querySelector('[data-deploy-canvas-icons]')?.content?.querySelector(`[data-icon="${name}"]`)?.cloneNode(true);
    if (!node) return null;
    if (cls) node.setAttribute('class', cls);
    else node.removeAttribute('class');
    node.setAttribute('aria-hidden', 'true');
    return node.outerHTML;
  });
</script>

{#if glyph}
  {@html glyph}
{:else}
  <svg class={cls || undefined} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {#each LOCAL_ICONS[name] ?? [] as d}<path {d} />{/each}
  </svg>
{/if}
