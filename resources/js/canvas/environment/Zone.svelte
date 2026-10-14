<script>
  // A zone frame (server, or a group of the connect view) with its header; the header drags the zone.
  import { NO_SERVER } from '../../environment-canvas.js';
  import Icon from './Icon.svelte';

  let { box, movable, onpress } = $props();
  let header;
  const zone = $derived(box.zone);
  const ok = $derived(zone?.is_reachable && zone?.is_usable);
  const title = $derived(movable ? 'Drag to move every card in this zone' : !zone ? 'Resources without a server' : undefined);
</script>

<div
  class="absolute rounded-2xl border border-neutral-200 bg-white/50 dark:border-white/[0.07] dark:bg-white/[0.02]"
  style:left="{box.x}px"
  style:top="{box.y}px"
  style:width="{box.width}px"
  style:height="{box.height}px"
>
  <div bind:this={header} class="flex h-10 items-center gap-2 px-4 text-[12px] {movable ? 'cursor-grab active:cursor-grabbing' : ''}" data-ec-zone-header {title} role="presentation" onpointerdown={e => onpress(e, header)}>
    <Icon name={zone?.icon || 'server'} class="size-3.5 text-neutral-400 dark:text-fg-faint" />
    <a class="truncate font-semibold text-neutral-700 dark:text-fg [&[href]]:cursor-pointer [&[href]]:underline-offset-2 [&[href]]:hover:underline" href={zone?.link || undefined} draggable="false">{box.name || NO_SERVER}</a>
    <!-- Zones with an icon are not server zones (connect view). -->
    {#if zone && !zone.icon}
      <span
        class="size-1.5 shrink-0 rounded-full {ok ? 'bg-emerald-500' : zone.is_reachable ? 'bg-warning' : 'bg-red-500'}"
        title={ok ? 'Reachable' : zone.is_reachable ? 'Reachable, not usable' : 'Not reachable'}
      ></span>
      {#if zone.ip}<span class="truncate font-mono text-[11px] text-neutral-400 dark:text-fg-faint">{zone.ip}</span>{/if}
    {/if}
  </div>
</div>
