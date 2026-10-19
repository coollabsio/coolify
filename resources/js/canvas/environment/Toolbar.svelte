<script>
  // Filters and view toggles. The root docks it (use:dock) into the zoom controls bar
  // (CanvasControls, bottom left) on desktop, or in a rounded box above the canvas on small screens.

  let { viewOnly, viewOnlyTitle, filter, projects, environments, views, dock, onfilter } = $props();

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
  </div>
</div>
