<script>
  // A resource card (or, connect view, a shared variable / tag card).
  import { isSource, MAX_CHILDREN, statusLabel, statusTone, statusWord, TYPE_LABELS } from '../../environment-canvas.js';
  import NodeIcon from './NodeIcon.svelte';
  import StatusDot from './StatusDot.svelte';

  let { node, position, selected, dropTarget, movable, port, linkCount, zoneNames, serverDown = false, statusText = false, onpress, onconnect, onselect, onhover } = $props();
  let card;
  let portDot = $state();
  // Same as the deploy canvas drop target: accent border and a soft 4px ring.
  const DROP_TARGET = 'border-coollabs! dark:border-warning! ring-4 ring-coollabs/15 dark:ring-warning/15';

  const extra = $derived(node.extra_zones?.length || 0);
  const children = $derived(node.children || []);
  const chip = 'inline-flex h-5 max-w-[9rem] items-center truncate rounded-full border border-neutral-200 bg-neutral-50 px-1.5 text-[10px] font-medium text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim';
  const stop = e => e.stopPropagation();
  // The server is not reachable, so the saved status is stale: show it as unknown, not as a failure.
  const DOWN = 'Unknown (server not reachable)';
  const status = $derived(serverDown ? 'unknown' : node.status);
  const TONE_TEXT = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    warn: 'text-warning-700 dark:text-warning',
    busy: 'text-warning-700 dark:text-warning',
    bad: 'text-red-600 dark:text-red-400',
    idle: 'text-neutral-500 dark:text-fg-faint',
  };
</script>

<!-- data-ec-open: its page opens on double-click (see the root's pointerdown capture listener). -->
<div
  bind:this={card}
  class="group absolute flex w-[260px] flex-col rounded-xl border bg-white p-3 shadow-sm transition-shadow hover:shadow-md dark:bg-raised {selected
    ? 'border-coollabs ring-2 ring-coollabs/25 dark:border-warning dark:ring-warning/25'
    : node.missing
      ? 'border-dashed border-red-300 dark:border-red-500/40'
      : 'border-neutral-200 hover:border-neutral-300 dark:border-white/[0.08] dark:hover:border-white/[0.16]'} {movable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'} {dropTarget ? DROP_TARGET : ''}"
  style:left={position ? `${position.x}px` : null}
  style:top={position ? `${position.y}px` : null}
  data-ec-card
  data-id={node.id}
  data-ec-open={node.link || undefined}
  title={node.link ? 'Double-click to open' : undefined}
  role="button"
  tabindex="0"
  aria-label={isSource(node)
    ? `${node.name} (${node.missing ? 'deleted ' : ''}${TYPE_LABELS[node.type].toLowerCase()})`
    : `${node.name} (${TYPE_LABELS[node.type] || node.type}), ${serverDown ? DOWN : statusLabel(node.status)}`}
  onpointerdown={e => onpress(e, card)}
  onkeydown={e => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onselect();
    }
  }}
  onpointerenter={() => onhover(true)}
  onpointerleave={() => onhover(false)}
>
  <div class="flex items-start gap-2.5">
    <div class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border {node.missing
      ? 'border-red-200 bg-red-50 text-red-500 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400'
      : 'border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim'}">
      <NodeIcon {node} />
    </div>
    <div class="min-w-0 flex-1">
      <div class="truncate text-[13px] leading-4 font-semibold text-black dark:text-fg {node.type === 'variable' ? 'font-mono' : ''} {node.link ? 'underline-offset-2 group-hover:underline' : ''}">{node.name}</div>
      <div class="mt-0.5 truncate text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">{node.subtitle || TYPE_LABELS[node.type] || ''}</div>
    </div>
    {#if isSource(node)}
      <span
        class="mt-0.5 shrink-0 rounded-full border px-1.5 text-[10px] leading-4 font-medium {node.missing
          ? 'border-red-200 bg-red-50 text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400'
          : 'border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim'}"
        title={node.missing ? 'This shared variable was deleted, but resources still reference it' : `Used by ${linkCount} ${linkCount === 1 ? 'resource' : 'resources'}`}
      >{node.missing ? 'Deleted' : String(linkCount)}</span>
    {:else}
      {#if node.deployment}
        <!-- Dashboard canvas: the running or queued deployment of the application. -->
        <a
          class="relative mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-full border border-neutral-200 bg-neutral-50 px-1.5 text-[10px] leading-4 font-medium text-neutral-600 hover:border-neutral-300 hover:text-black dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim dark:hover:border-white/[0.2] dark:hover:text-fg"
          href={node.deployment.link}
          title={node.deployment.pull_request_id ? `Pull request #${node.deployment.pull_request_id}` : 'Open the deployment'}
          onpointerdown={stop}
          onclick={stop}
        >
          <StatusDot status={node.deployment.status} size="size-1.5" />
          {node.deployment.status === 'queued' ? 'Queued' : 'Deploying'}
        </a>
      {/if}
      {#if statusText}
        <!-- Server view: the status in words, so a stopped resource reads as stopped at a glance. -->
        <span class="mt-0.5 inline-flex shrink-0 items-center gap-1.5 text-[11px] leading-4 font-medium {TONE_TEXT[statusTone(status)]}" title={serverDown ? DOWN : statusLabel(status)}>
          {statusWord(status)}<StatusDot {status} size="size-1.5" title={serverDown ? DOWN : null} />
        </span>
      {:else}
        <div class="pt-1"><StatusDot {status} title={serverDown ? DOWN : null} /></div>
      {/if}
    {/if}
  </div>

  {#if node.urls?.length}
    <a
      class="relative mt-1.5 block max-w-full self-start truncate text-[11px] leading-5 text-neutral-500 hover:text-black hover:underline dark:text-fg-dim dark:hover:text-fg"
      href={node.urls[0]}
      target="_blank"
      rel="noopener noreferrer"
      title={node.urls[0]}
      onpointerdown={stop}
      onclick={stop}
    >{node.urls[0].replace(/^https?:\/\//, '')}</a>
  {/if}

  {#if extra}
    <div class="mt-2 flex min-w-0 flex-wrap gap-1 overflow-hidden h-5">
      <span class={chip} title={zoneNames.join(', ')}>+{extra} {extra === 1 ? 'server' : 'servers'}</span>
    </div>
  {/if}

  {#if children.length}
    <ul class="mt-2 border-t border-neutral-100 pt-1.5 dark:border-white/[0.06]">
      {#each children.slice(0, MAX_CHILDREN) as child, i (i)}
        <li class="flex h-5 items-center gap-2 text-[12px] leading-5">
          <StatusDot status={serverDown ? 'unknown' : child.status} size="size-1.5" title={serverDown ? DOWN : null} />
          <span class="min-w-0 truncate">{child.name}</span>
        </li>
      {/each}
      {#if children.length > MAX_CHILDREN}
        <li class="h-5 text-[11px] leading-5 text-neutral-400 dark:text-fg-faint">+{children.length - MAX_CHILDREN} more</li>
      {/if}
    </ul>
  {/if}

  <!-- Drag dot: shown on hover only. Connected ends always show (link end dots in the root). -->
  {#if port}
    <span
      bind:this={portDot}
      class="absolute top-1/2 -right-1.5 size-3 -translate-y-1/2 cursor-crosshair rounded-full border-2 border-white bg-neutral-400 opacity-0 shadow transition-[opacity,scale,background-color] group-hover:opacity-100 hover:scale-120 hover:bg-coollabs dark:border-raised dark:bg-neutral-500 dark:hover:bg-warning"
      data-ec-port
      role="presentation"
      title={port}
      onpointerdown={e => onconnect(e, portDot)}
    ></span>
  {/if}
</div>
