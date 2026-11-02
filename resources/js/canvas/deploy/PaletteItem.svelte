<script>
  import { TYPES } from '../../deploy-canvas.js';
  import Icon from './Icon.svelte';
  import { dragFromPalette } from './state.svelte.js';

  // A step in the palette (drag it, or click to add it to the chain), or in
  // the "Add the next step" menu (onpick).
  let { type, onpick = null } = $props();
  const t = $derived(TYPES[type]);
</script>

<button
  type="button"
  class="cv-item"
  title="{t.title} — {t.sub}"
  onclick={onpick}
  onpointerdown={onpick ? null : e => dragFromPalette(type, e)}
>
  <i><Icon name={t.icon} /></i>
  <span><b>{t.title}</b><small>{t.sub}</small></span>
</button>
