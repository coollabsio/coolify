<script>
  import { statusLabel, statusTone, TONE_DOT } from '../../environment-canvas.js';

  let { status, size = 'size-2', title = null } = $props();
  const tone = $derived(statusTone(status));
</script>

<span class="relative flex {size} shrink-0" title={title || statusLabel(status)}>
  {#if tone === 'busy'}
    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {TONE_DOT.busy}"></span>
  {/if}
  <span class="relative inline-flex size-full rounded-full {TONE_DOT[tone]}"></span>
</span>
