<script>
  // Outline icon drawn from the local paths (environment-canvas.js ICONS).
  import { ICONS } from '../../environment-canvas.js';

  let { name, class: cls = 'size-3.5' } = $props();
</script>

<svg class="{cls} shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  {#each ICONS[name] || [] as d (d)}<path {d} />{/each}
</svg>
