<script>
  // Side panel of the selected card or link. Connect view (shared variables, tags): a source card,
  // a resource with its sources, or a source → resource link; other views: a card or a diagram link.
  import { isSource, NO_SERVER, statusLabel, TYPE_LABELS } from '../../environment-canvas.js';
  import AddSection from './AddSection.svelte';
  import Icon from './Icon.svelte';
  import NodeIcon from './NodeIcon.svelte';
  import StatusDot from './StatusDot.svelte';

  let { graph, node, link, connectView, text, canEdit, top, nodeById, zoneNames, onselect, onclose, oncreate, onremove, onlabel, ondelete } = $props();

  // Requests in flight, per link or card id: their buttons stay disabled until the request ends.
  let busy = $state({});
  async function run(id, request) {
    busy[id] = true;
    try {
      await request();
    } finally {
      busy[id] = false;
    }
  }

  // Diagram link label: saved on blur and Enter, Escape restores it. Follows the link while not focused.
  let labelInput = $state();
  let label = $state('');
  let saved = '';
  $effect(() => {
    const current = link?.label || '';
    if (document.activeElement !== labelInput) {
      label = current;
      saved = current;
    }
  });
  async function saveLabel() {
    const value = label.trim();
    if (value === saved) return;
    saved = value;
    if (!(await onlabel(link, value))) saved = link.label || '';
  }

  const addProps = (title, placeholder, empty, candidates, describe, onadd) => ({ title, placeholder, empty, candidates, describe, onadd });

  const links = $derived(graph.links || []);
  const removeTitle = l => (l.keys?.length
    ? `Delete ${l.keys.join(', ')} from ${nodeById(l.target)?.name}`
    : `Remove ${nodeById(l.source)?.name} from ${nodeById(l.target)?.name}`);
  const removeHint = l => `${l.keys.join(', ')} uses ${nodeById(l.source)?.reference || 'the variable'} inside a longer value. Edit it on the resource.`;

  // Connect view lists.
  const usedBy = $derived(node ? links.filter(l => l.source === node.id) : []);
  const sources = $derived(node ? links.filter(l => l.target === node.id) : []);
  const nodeLinks = $derived(node ? links.filter(l => l.source === node.id || l.target === node.id) : []);
  const servers = $derived(node ? zoneNames(node) : []);

  const rowButton = 'flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12px] transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.06]';
  const listButton = 'flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-[12px] transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.06]';
  const smallButton = danger => `button h-6! shrink-0 px-2! text-[11px]! ${danger ? 'button-error' : ''}`;
  const faint = 'text-[12px] text-neutral-400 dark:text-fg-faint';
  const statusPill = 'inline-flex h-6 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white';
  const titleLink = '[&[href]]:cursor-pointer [&[href]]:underline-offset-2 [&[href]]:hover:underline';
</script>

{#snippet section(title, content)}
  <section class="border-t border-neutral-100 px-4 py-3 dark:border-white/[0.06]">
    <h3 class="mb-1.5 text-[11px]! leading-4! font-medium! text-neutral-400 dark:text-fg-faint">{title}</h3>
    {@render content()}
  </section>
{/snippet}

{#snippet note(content)}
  <p class="border-t border-neutral-100 px-4 py-3 text-[12px] text-neutral-500 dark:border-white/[0.06] dark:text-fg-faint">{content}</p>
{/snippet}

{#snippet shell(title, iconSource, body, footer)}
  <aside class="absolute top-3 right-3 bottom-3 z-20 flex w-80 max-w-[calc(100%-1.5rem)] flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg dark:border-white/[0.08] dark:bg-panel" style:top={top || null} data-ec-panel>
    <div class="flex items-center gap-2.5 px-4 py-3">
      <div class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
        {#if typeof iconSource === 'string'}<Icon name={iconSource} class="size-4" />{:else}<NodeIcon node={iconSource} />{/if}
      </div>
      <h2 class="min-w-0 flex-1 truncate text-[14px]! leading-5! font-semibold! text-black dark:text-fg">{title}</h2>
      <button type="button" class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.07] dark:hover:text-fg" title="Close" aria-label="Close panel" onclick={onclose}>
        <Icon name="x" />
      </button>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto">{@render body()}</div>
    {#if footer}<div class="border-t border-neutral-100 p-4 dark:border-white/[0.06]">{@render footer()}</div>{/if}
  </aside>
{/snippet}

{#snippet meta(n)}
  <div class="flex flex-wrap items-center gap-2 px-4 pb-3">
    <span class="text-[12px] text-neutral-500 dark:text-fg-faint">{TYPE_LABELS[n.type] || n.type}</span>
    <span class={statusPill}><StatusDot status={n.status} size="size-1.5" />{statusLabel(n.status)}</span>
  </div>
{/snippet}

{#snippet openButton(n, cls, label)}
  <a class="button {cls} w-full" href={n.link}>{label}</a>
{/snippet}

{#snippet removeButton(l)}
  {#if canEdit}
    <button type="button" class={smallButton(true)} title={l.removable ? removeTitle(l) : removeHint(l)} disabled={!l.removable || busy[l.id]} onclick={() => run(l.id, () => onremove(l))}>Remove</button>
  {/if}
{/snippet}

{#if connectView && node && isSource(node)}
  {#snippet body()}
    <div class="flex flex-wrap items-center gap-2 px-4 pb-3">
      {#if node.reference}<code class="rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim">{node.reference}</code>{/if}
      {#if node.missing}<span class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">Deleted</span>{/if}
    </div>
    {#if node.missing}
      {@render note(`This shared variable was deleted. The resources below still reference it and get the text ${node.reference} instead of a value. Add the variable again, or remove the reference.`)}
    {:else if node.subtitle}
      {#snippet description()}<p class="text-[12px] break-words">{node.subtitle}</p>{/snippet}
      {@render section('Description', description)}
    {/if}
    {#snippet used()}
      <ul class="flex flex-col gap-1.5">
        {#if !usedBy.length}<li class={faint}>{text.noTargets}</li>{/if}
        {#each usedBy as l (l.id)}
          {@const resource = nodeById(l.target)}
          <li class="flex items-center gap-1">
            <button type="button" class={rowButton} title={resource?.context || ''} onclick={() => onselect({ kind: 'link', id: l.id })}>
              <StatusDot status={resource?.status} size="size-1.5" />
              <span class="min-w-0 flex-1 truncate">{resource?.name || 'Unknown'}</span>
              {#if l.label}<span class="max-w-24 truncate font-mono text-[11px] text-neutral-400 dark:text-fg-faint">{l.label}</span>{/if}
            </button>
            {@render removeButton(l)}
          </li>
        {/each}
      </ul>
    {/snippet}
    {@render section(`${text.usedBy} (${usedBy.length})`, used)}
    {#if canEdit && !node.missing}
      {@const linked = new Set(usedBy.map(l => l.target))}
      <AddSection {...addProps('Add to resources', 'Search resources', text.allTargets,
        graph.nodes.filter(n => !isSource(n) && !linked.has(n.id)),
        resource => ({ title: `Add ${node.name} to ${resource.name}`, sub: resource.context || '' }),
        resource => oncreate(node.id, resource.id))} />
    {/if}
    {#if !node.missing}{@render note(canEdit ? text.note(node) : text.viewOnly)}{/if}
  {/snippet}
  {#snippet footer()}{@render openButton(node, '', `Open ${TYPE_LABELS[node.type].toLowerCase()}`)}{/snippet}
  {@render shell(node.name, node, body, node.link ? footer : null)}
{:else if connectView && node}
  {#snippet body()}
    {@render meta(node)}
    {#if node.context}
      {#snippet context()}<a class="text-[12px] hover:underline {titleLink}" href={node.context_link || undefined} draggable="false">{node.context}</a>{/snippet}
      {@render section('Project / environment', context)}
    {/if}
    {#snippet list()}
      <ul class="flex flex-col gap-1.5">
        {#if !sources.length}<li class={faint}>{text.noSources}</li>{/if}
        {#each sources as l (l.id)}
          {@const source = nodeById(l.source)}
          <li class="flex items-center gap-1">
            <button type="button" class={rowButton} onclick={() => onselect({ kind: 'node', id: l.source })}>
              <Icon name={source?.type || 'variable'} class="size-3.5 {l.missing ? 'text-red-500' : 'text-neutral-400 dark:text-fg-faint'}" />
              <span class="min-w-0 flex-1 truncate {source?.type === 'variable' ? 'font-mono' : ''}">{source?.name || 'Unknown'}</span>
              {#if l.missing}<span class="text-[11px] text-red-500">Deleted</span>{/if}
            </button>
            {@render removeButton(l)}
          </li>
        {/each}
      </ul>
    {/snippet}
    {@render section(`${text.sources} (${sources.length})`, list)}
    {#if canEdit}
      {@const linked = new Set(sources.map(l => l.source))}
      <AddSection {...addProps(text.addSources, text.searchSources, text.noMoreSources,
        graph.nodes.filter(n => isSource(n) && !n.missing && !linked.has(n.id)),
        source => ({ title: `Add ${source.name} to ${node.name}`, sub: source.type === 'variable' ? source.subtitle || '' : '' }),
        source => oncreate(source.id, node.id))} />
    {/if}
  {/snippet}
  {#snippet footer()}{@render openButton(node, 'button-highlighted', 'Open')}{/snippet}
  {@render shell(node.name, node, body, node.link ? footer : null)}
{:else if connectView && link}
  {@const variable = nodeById(link.source)}
  {@const resource = nodeById(link.target)}
  {#snippet body()}
    {#snippet ends()}
      <div class="flex flex-col gap-1">
        {#each [[text.source, variable], ['Resource', resource]] as [endLabel, end] (endLabel)}
          <button type="button" class={rowButton} onclick={() => end && onselect({ kind: 'node', id: end.id })}>
            <span class="w-14 text-[11px] text-neutral-400 dark:text-fg-faint">{endLabel}</span>
            <span class="min-w-0 flex-1 truncate {end?.type === 'variable' ? 'font-mono' : ''}">{end?.name || 'Unknown'}</span>
          </button>
        {/each}
      </div>
    {/snippet}
    {@render section('Ends', ends)}
    {#if link.keys?.length}
      {#snippet keys()}
        <div class="flex flex-wrap gap-1">
          {#each link.keys as key (key)}<code class="rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[11px] dark:border-white/[0.1] dark:bg-white/[0.05]">{key}</code>{/each}
        </div>
      {/snippet}
      {@render section(`Variables on ${resource?.name || 'the resource'}`, keys)}
    {/if}
    {#if link.missing}{@render note(`The shared variable was deleted. ${resource?.name} gets the text ${variable?.reference} instead of a value.`)}{/if}
    {#if canEdit && !link.removable}{@render note(removeHint(link))}{/if}
  {/snippet}
  {#snippet footer()}
    <button type="button" class="button button-error w-full" title={link.removable ? removeTitle(link) : removeHint(link)} disabled={!link.removable || busy[link.id]} onclick={() => run(link.id, () => onremove(link))}>Remove</button>
  {/snippet}
  {@render shell(`${variable?.name || text.source} → ${resource?.name || 'Resource'}`, 'link', body, canEdit ? footer : null)}
{:else if node}
  {#snippet body()}
    {@render meta(node)}
    {#if node.subtitle}
      {#snippet details()}<p class="text-[12px] break-words">{node.subtitle}</p>{/snippet}
      {@render section('Details', details)}
    {/if}
    {#if node.urls?.length}
      {#snippet urls()}
        <ul class="flex flex-col gap-1">
          {#each node.urls as u, i (i)}
            <li class="min-w-0">
              <a class="inline-flex max-w-full items-center gap-1.5 text-[12px] text-neutral-600 hover:text-black hover:underline dark:text-fg-dim dark:hover:text-fg" href={u} target="_blank" rel="noopener noreferrer">
                <span class="truncate">{u.replace(/^https?:\/\//, '')}</span>
                <Icon name="external" class="size-3" />
              </a>
            </li>
          {/each}
        </ul>
      {/snippet}
      {@render section(node.urls.length === 1 ? 'URL' : 'URLs', urls)}
    {/if}
    {#snippet serverList()}<p class="text-[12px]">{servers.length ? servers.join(', ') : NO_SERVER}</p>{/snippet}
    {@render section(servers.length > 1 ? 'Servers' : 'Server', serverList)}
    {#if node.children?.length}
      {#snippet containers()}
        <ul class="flex flex-col">
          {#each node.children as child, i (i)}
            <li class="flex h-6 items-center gap-2 text-[12px]" title={statusLabel(child.status)}>
              <StatusDot status={child.status} size="size-1.5" />
              <span class="min-w-0 flex-1 truncate">{child.name}</span>
              <span class="text-[11px] text-neutral-400 dark:text-fg-faint">{TYPE_LABELS[child.type] || child.type || ''}</span>
            </li>
          {/each}
        </ul>
      {/snippet}
      {@render section('Containers', containers)}
    {/if}
    {#snippet linkList()}
      <ul class="flex flex-col gap-0.5">
        {#if !nodeLinks.length}<li class={faint}>{canEdit ? 'No links. Drag the dot on the right edge of a card to another card.' : 'No links.'}</li>{/if}
        {#each nodeLinks as l (l.id)}
          <li>
            <button type="button" class={listButton} onclick={() => onselect({ kind: 'link', id: l.id })}>
              <Icon name="link" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
              <span class="min-w-0 flex-1 truncate">{nodeById(l.source === node.id ? l.target : l.source)?.name || 'Unknown'}</span>
              {#if l.label}<span class="max-w-24 truncate text-[11px] text-neutral-400 dark:text-fg-faint">{l.label}</span>{/if}
            </button>
          </li>
        {/each}
      </ul>
    {/snippet}
    {@render section('Links', linkList)}
  {/snippet}
  {#snippet footer()}{@render openButton(node, 'button-highlighted', 'Open')}{/snippet}
  {@render shell(node.name, node, body, node.link ? footer : null)}
{:else if link}
  {#snippet body()}
    {#snippet ends()}
      <div class="flex flex-col gap-1">
        {#each [['From', link.source], ['To', link.target]] as [endLabel, id] (endLabel)}
          {@const end = nodeById(id)}
          <button type="button" class={listButton} onclick={() => onselect({ kind: 'node', id })}>
            <span class="w-9 text-[11px] text-neutral-400 dark:text-fg-faint">{endLabel}</span>
            <StatusDot status={end?.status} size="size-1.5" />
            <span class="min-w-0 flex-1 truncate">{end?.name || 'Unknown'}</span>
          </button>
        {/each}
      </div>
    {/snippet}
    {@render section('Resources', ends)}
    {#snippet labelField()}
      {#if canEdit}
        <input
          bind:this={labelInput}
          bind:value={label}
          class="input h-8 w-full text-[12px]"
          type="text"
          maxlength="255"
          placeholder="For example: reads from"
          aria-label="Link label"
          data-ec-label-input
          onkeydown={e => {
            if (e.key === 'Enter') {
              e.preventDefault();
              labelInput.blur();
            } else if (e.key === 'Escape') {
              e.stopPropagation();
              label = saved;
              labelInput.blur();
            }
          }}
          onblur={saveLabel}
        />
      {:else}
        <p class="text-[12px]">{link.label || 'No label'}</p>
      {/if}
    {/snippet}
    {@render section('Label', labelField)}
    {@render note('Links are for the diagram only. They do not change your resources.')}
  {/snippet}
  {#snippet footer()}
    <button type="button" class="button button-error w-full" disabled={busy[link.id]} onclick={() => run(link.id, () => ondelete(link))}>Remove link</button>
  {/snippet}
  {@render shell(link.label || 'Link', 'link', body, canEdit ? footer : null)}
{/if}
