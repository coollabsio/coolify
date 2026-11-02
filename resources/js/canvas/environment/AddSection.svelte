<script>
  // Searchable list of cards, each with an Add button (connect view side panels).
  import Icon from './Icon.svelte';

  let { title, placeholder, empty, candidates, describe, onadd } = $props();
  let query = $state('');
  let busy = $state({});
  const search = $derived(query.trim().toLowerCase());

  async function add(candidate) {
    busy[candidate.id] = true;
    await onadd(candidate);
    busy[candidate.id] = false;
  }
</script>

<section class="border-t border-neutral-100 px-4 py-3 dark:border-white/[0.06]">
  <h3 class="mb-1.5 text-[11px]! leading-4! font-medium! text-neutral-400 dark:text-fg-faint">{title}</h3>
  <div class="flex flex-col gap-2">
    {#if candidates.length}
      <input bind:value={query} class="input h-8 w-full text-[12px]" type="search" {placeholder} aria-label={placeholder} />
    {/if}
    <ul class="flex flex-col gap-1.5">
      {#if !candidates.length}<li class="text-[12px] text-neutral-400 dark:text-fg-faint">{empty}</li>{/if}
      {#each candidates as candidate (candidate.id)}
        {@const { title: addTitle, sub } = describe(candidate)}
        <li class="flex items-center gap-1" class:hidden={!!search && !`${candidate.name} ${sub}`.toLowerCase().includes(search)}>
          <div class="flex min-w-0 flex-1 items-center gap-2 px-1.5 py-1 text-[12px]">
            <Icon name={candidate.type} class="size-3.5 text-neutral-400 dark:text-fg-faint" />
            <div class="min-w-0 flex-1">
              <div class="truncate {candidate.type === 'variable' ? 'font-mono' : ''}">{candidate.name}</div>
              {#if sub}<div class="truncate text-[11px] text-neutral-400 dark:text-fg-faint">{sub}</div>{/if}
            </div>
          </div>
          <button type="button" class="button h-6! shrink-0 px-2! text-[11px]!" title={addTitle} disabled={busy[candidate.id]} onclick={() => add(candidate)}>Add</button>
        </li>
      {/each}
    </ul>
  </div>
</section>
