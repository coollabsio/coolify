<script>
  import BetaBadge from '../BetaBadge.svelte';
  import Select from './Select.svelte';
  // Filters and view toggles. The root docks it (use:dock) into the zoom controls bar
  // (CanvasControls, bottom left) on desktop, or in a rounded box above the canvas on small screens.

  let { viewOnly, viewOnlyTitle, filter, projects, environments, views, dock, onfilter } = $props();

  const select = 'w-44 max-lg:w-auto max-lg:min-w-0 max-lg:flex-1 max-lg:basis-32';
  const options = (all, list) => [{ value: '', label: all }, ...list.map(o => ({ value: o.uuid, label: o.name }))];
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
    <BetaBadge />
    <span class="rounded-full border border-neutral-200 bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-fg-dim" class:hidden={!viewOnly} title={viewOnlyTitle}>View only</span>
  </div>
  <!-- Small screens: the actions wrap onto more rows instead of covering the title. -->
  <div class="ml-auto flex shrink-0 items-center gap-2 max-lg:min-w-0 max-lg:shrink max-lg:flex-wrap" use:adopt={views}>
    <!-- Connect and server views: show the resources of one project and / or environment only. -->
    {#if projects.length > 1}
      <Select class={select} label="Filter by project" value={value(projects, filter.project)}
        options={options('All projects', projects)} onchange={project => onfilter({ project, environment: '' })} />
    {/if}
    {#if environments.length > 1}
      <Select class={select} label="Filter by environment" value={value(environments, filter.environment)}
        options={options('All environments', environments)} onchange={environment => onfilter({ ...filter, environment })} />
    {/if}
  </div>
</div>
