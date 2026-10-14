<script>
  // Title, filters, refresh and zoom. The root docks it (use:dock) into the app top bar on desktop,
  // or in a rounded box above the canvas on small screens.
  import Icon from './Icon.svelte';

  let { title, viewOnly, viewOnlyTitle, filter, projects, environments, loading, zoom, views, dock, onfilter, onrefresh, onzoom, onfit } = $props();

  const select = 'select h-8! w-44 py-0! text-[12px]! max-lg:w-auto max-lg:min-w-0 max-lg:flex-1 max-lg:basis-32';
  const value = (options, wanted) => (options.some(o => o.uuid === wanted) ? wanted : '');

  /** Table / grid / canvas switcher from the page (Blade), when the page has one. */
  function adopt(node, el) {
    if (!el) return;
    el.classList.add('max-lg:flex-wrap');
    node.append(el);
  }
</script>

<div use:dock data-ec-toolbar>
  <div class="flex min-w-0 items-center gap-2">
    {#if title}<b class="shrink-0 text-[14px] font-semibold text-black dark:text-fg">{title}</b>{/if}
    <span class="inline-flex items-center">
      <span class="px-2 py-0.5 text-xs font-medium leading-normal rounded-full bg-coollabs/10 text-coollabs border border-coollabs/30 dark:bg-warning/15 dark:text-warning dark:border-warning/30">Beta</span>
    </span>
    <span class="rounded-full border border-neutral-200 bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-fg-dim" class:hidden={!viewOnly} title={viewOnlyTitle}>View only</span>
  </div>
  <!-- Small screens: the actions wrap onto more rows instead of covering the title. -->
  <div class="ml-auto flex shrink-0 items-center gap-2 max-lg:min-w-0 max-lg:shrink max-lg:flex-wrap" use:adopt={views}>
    <!-- Connect and server views: show the resources of one project and / or environment only. -->
    <select class={select} class:hidden={projects.length < 2} aria-label="Filter by project" title="Filter by project"
      value={value(projects, filter.project)} onchange={e => onfilter({ project: e.currentTarget.value, environment: '' })}>
      <option value="">All projects</option>
      {#each projects as o (o.uuid)}<option value={o.uuid}>{o.name}</option>{/each}
    </select>
    <select class={select} class:hidden={environments.length < 2} aria-label="Filter by environment" title="Filter by environment"
      value={value(environments, filter.environment)} onchange={e => onfilter({ ...filter, environment: e.currentTarget.value })}>
      <option value="">All environments</option>
      {#each environments as o (o.uuid)}<option value={o.uuid}>{o.name}</option>{/each}
    </select>
    <button type="button" class="button" title="Load the latest status" disabled={loading} onclick={onrefresh}>
      <Icon name="refresh" class="size-3.5 {loading ? 'animate-spin' : ''}" />
      <span class="hidden sm:inline">Refresh</span>
    </button>
    <div class="flex items-center">
      <button type="button" class="button px-2! rounded-r-none!" title="Zoom out" aria-label="Zoom out" onclick={() => onzoom(zoom / 1.2)}><Icon name="minus" /></button>
      <button type="button" class="button rounded-none! border-x-0! w-14 tabular-nums" title="Reset zoom to 100%" onclick={() => onzoom(1)}>{Math.round(zoom * 100)}%</button>
      <button type="button" class="button px-2! rounded-l-none!" title="Zoom in" aria-label="Zoom in" onclick={() => onzoom(zoom * 1.2)}><Icon name="plus" /></button>
    </div>
    <button type="button" class="button px-2!" title="Fit to screen" aria-label="Fit to screen" onclick={onfit}><Icon name="fit" />Fit</button>
  </div>
</div>
