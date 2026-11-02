<script>
  // A zone frame (server, or a group of the connect view) with its header; the header drags the zone.
  // grabBody (projects canvas): the whole frame drags the zone, so a press on its empty space does not pan the canvas.
  // Server canvas zones (zone.details) add a second row: host facts, proxy, live usage and a status summary.
  import { formatMemory, formatUptime, NO_SERVER, statusSummary, zoneHeader } from '../../environment-canvas.js';
  import Icon from './Icon.svelte';

  let { box, movable, grabBody = false, onpress, nodes = [], usage = null } = $props();
  let frame;
  let header;
  // The server IP stays hidden until the user asks for it (screen sharing, screenshots).
  let showIp = $state(false);
  const zone = $derived(box.zone);
  const ok = $derived(zone?.is_reachable && zone?.is_usable);
  const title = $derived(movable ? (grabBody ? 'Drag to move this zone' : 'Drag to move every card in this zone') : !zone ? 'Resources without a server' : undefined);
  const details = $derived(zone?.details);
  const host = $derived(details
    ? [details.os, details.arch, details.cpus ? `${details.cpus} ${details.cpus === 1 ? 'CPU' : 'CPUs'}` : null, formatMemory(details.memory_bytes), details.uptime_since && formatUptime(details.uptime_since) ? `up ${formatUptime(details.uptime_since)}` : null].filter(Boolean)
    : []);
  const proxyRunning = $derived(details?.proxy?.status === 'running');
  const summary = $derived(details && zone?.is_reachable ? statusSummary(nodes) : null);
  const SUMMARY = [
    ['ok', 'running', 'bg-emerald-500'],
    ['busy', 'starting', 'bg-warning'],
    ['warn', 'degraded', 'bg-warning'],
    ['bad', 'stopped', 'bg-red-500'],
    ['idle', 'unknown', 'bg-neutral-400 dark:bg-neutral-500'],
  ];
  const USAGE = [['cpu', 'CPU'], ['memory', 'Memory'], ['disk', 'Disk']];
  const usageTone = value => (value >= 90 ? 'text-red-600 dark:text-red-400' : value >= 75 ? 'text-warning-700 dark:text-warning' : 'text-neutral-600 dark:text-fg-dim');
  const sep = 'h-3 w-px shrink-0 bg-neutral-200 dark:bg-white/[0.1]';
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  bind:this={frame}
  class="absolute rounded-2xl border border-neutral-200 bg-[color-mix(in_oklab,var(--color-neutral-50),white_50%)] dark:border-white/[0.07] dark:bg-[color-mix(in_oklab,var(--color-app),var(--color-panel)_35%)] {grabBody ? 'cursor-grab active:cursor-grabbing' : ''}"
  data-ec-zone-drag={grabBody || undefined}
  title={grabBody ? title : undefined}
  onpointerdown={grabBody ? e => onpress(e, frame) : undefined}
  style:left="{box.x}px"
  style:top="{box.y}px"
  style:width="{box.width}px"
  style:height="{box.height}px"
>
  <div bind:this={header} class="px-4 {movable ? 'cursor-grab active:cursor-grabbing' : ''}" style:height="{zoneHeader(zone)}px" data-ec-zone-header title={grabBody ? undefined : title} role="presentation" onpointerdown={grabBody ? undefined : e => onpress(e, header)}>
    <div class="flex h-10 items-center gap-2 text-[12px]">
      <Icon name={zone?.icon || 'server'} class="size-3.5 text-neutral-400 dark:text-fg-faint" />
      <a class="truncate font-semibold text-neutral-700 dark:text-fg [&[href]]:cursor-pointer [&[href]]:underline-offset-2 [&[href]]:hover:underline" href={zone?.link || undefined} draggable="false">{box.name || NO_SERVER}</a>
      <!-- Zones with an icon are not server zones (connect view). -->
      {#if zone && !zone.icon}
        {#if ok}
          <span class="size-1.5 shrink-0 rounded-full bg-emerald-500" title="Reachable"></span>
        {:else}
          <!-- Spelled out: the status of every card on this server is unknown while it is down. -->
          <span
            class="inline-flex h-5 shrink-0 items-center gap-1.5 rounded-full border px-2 text-[11px] font-medium {zone.is_reachable
              ? 'border-warning/40 bg-warning/10 text-warning-700 dark:text-warning'
              : 'border-red-200 bg-red-50 text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400'}"
            title={zone.is_reachable ? 'Coolify can connect to this server, but cannot use it (for example, Docker is missing)' : 'Coolify cannot connect to this server. The status of its resources is unknown.'}
          >
            <span class="size-1.5 rounded-full {zone.is_reachable ? 'bg-warning' : 'bg-red-500'}"></span>
            {zone.is_reachable ? 'Not usable' : 'Unreachable'}
          </span>
        {/if}
        {#if zone.ip}
          <button
            type="button"
            class="shrink-0 font-mono text-[11px] text-neutral-400 hover:text-neutral-600 dark:text-fg-faint dark:hover:text-fg-dim"
            title={showIp ? 'Hide IP address' : 'Show IP address'}
            onpointerdown={e => e.stopPropagation()}
            onclick={() => (showIp = !showIp)}
          >{showIp ? zone.ip : 'Show IP'}</button>
        {/if}
        {#if summary}
          <span class="ml-auto flex shrink-0 items-center gap-2.5 text-[11px] text-neutral-500 dark:text-fg-dim" title="Resources on this server by status">
            {#each SUMMARY as [tone, label, dot] (tone)}
              {#if summary[tone]}
                <span class="inline-flex items-center gap-1"><span class="size-1.5 rounded-full {dot}"></span>{summary[tone]} {label}</span>
              {/if}
            {/each}
          </span>
        {/if}
      {/if}
    </div>
    {#if details}
      <div class="flex h-5 min-w-0 items-center gap-2.5 overflow-hidden text-[11px] whitespace-nowrap text-neutral-500 dark:text-fg-faint">
        {#if host.length}<span class="truncate" title="Operating system, architecture, CPUs, memory and uptime">{host.join(' · ')}</span>{/if}
        {#if details.proxy}
          {#if host.length}<span class={sep}></span>{/if}
          <span class="inline-flex shrink-0 items-center gap-1.5" title="Proxy: {details.proxy.status || 'unknown'}">
            <span class="size-1.5 rounded-full {proxyRunning ? 'bg-emerald-500' : details.proxy.status ? 'bg-red-500' : 'bg-neutral-400'}"></span>
            {details.proxy.type.charAt(0) + details.proxy.type.slice(1).toLowerCase()}{proxyRunning ? '' : ` ${details.proxy.status || ''}`}
          </span>
        {/if}
        {#if usage && USAGE.some(([key]) => usage[key] !== null && usage[key] !== undefined)}
          <span class={sep}></span>
          {#each USAGE as [key, label] (key)}
            {#if usage[key] !== null && usage[key] !== undefined}
              <span class="shrink-0" title="{label} usage (last minute)">{label} <span class="font-medium tabular-nums {usageTone(usage[key])}">{Math.round(usage[key])}%</span></span>
            {/if}
          {/each}
        {/if}
      </div>
    {/if}
  </div>
</div>
