<script>
  import { onMount } from 'svelte';
  import { ICONS } from '../../deploy-canvas.js';
  import Icon from './Icon.svelte';
  import { closeDropdown, cv, toast } from './state.svelte.js';

  // Our dropdown panel: a searchable list under its trigger, and an optional
  // "New …" row. Mounted again for each trigger (see DeployCanvas.svelte).
  const { trigger, items, current, onPick, add } = cv.dropdown;

  let panel;
  let search = $state();
  let listBox;
  let addInput = $state();
  let query = $state('');
  let active = $state(Math.max(0, items.findIndex(([v]) => v === current)));
  // The "New …" row: a click shows a name field; Enter creates it.
  let adding = $state(false);
  let name = $state('');
  let busy = $state(false);
  let problem = $state('');

  const q = $derived(query.trim().toLowerCase());
  const shown = $derived(items.filter(([, text]) => !q || String(text).toLowerCase().includes(q)));
  const activeIndex = $derived(Math.min(active, Math.max(0, shown.length - 1)));

  function pick(value) {
    closeDropdown();
    onPick(value);
  }
  function keys(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeDropdown();
      trigger.focus();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      active = (activeIndex + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length);
    } else if (e.key === 'Enter' && shown[activeIndex]) {
      e.preventDefault();
      pick(shown[activeIndex][0]);
    }
  }
  function startAdding() {
    name = query.trim();
    adding = true;
  }
  async function submit(e) {
    e.preventDefault();
    const typed = name.trim();
    if (!typed || busy) return;
    busy = true;
    try {
      const created = await add.create(typed);
      closeDropdown();
      add.done(created);
      toast(`"${typed}" added.`);
    } catch (error) {
      problem = error.message;
      busy = false;
      addInput?.focus();
    }
  }

  // Keep the active option in view.
  $effect(() => {
    shown;
    listBox.children[activeIndex]?.scrollIntoView({ block: 'nearest' });
  });
  $effect(() => {
    if (adding) addInput?.focus();
  });

  onMount(() => {
    // Under the trigger (or above it when there is no room), as wide as it.
    const r = trigger.getBoundingClientRect();
    const width = Math.max(r.width, 200);
    const below = innerHeight - r.bottom - 12;
    panel.style.width = `${width}px`;
    panel.style.left = `${Math.min(r.left, innerWidth - width - 12)}px`;
    if (below < Math.min(panel.offsetHeight, 260) && r.top > below) panel.style.bottom = `${innerHeight - r.top + 6}px`;
    else panel.style.top = `${r.bottom + 6}px`;
    (search || listBox.children[activeIndex] || panel).focus?.();
    const outside = e => {
      if (!panel.contains(e.target) && e.target !== trigger && !trigger.contains(e.target)) closeDropdown();
    };
    addEventListener('pointerdown', outside, true);
    return () => removeEventListener('pointerdown', outside, true);
  });
</script>

<div class="cv-dd" role="listbox" tabindex="-1" bind:this={panel} onkeydown={keys}>
  {#if items.length > 8}
    <input class="cv-dd-search" placeholder="Search" spellcheck="false" bind:this={search} bind:value={query} oninput={() => (active = 0)} />
  {/if}
  <div class="cv-dd-list" bind:this={listBox}>
    {#each shown as [value, text, badge], i}
      <button
        type="button"
        role="option"
        aria-selected={value === current}
        class="cv-dd-opt"
        class:on={value === current}
        class:active={i === activeIndex}
        onpointerenter={() => (active = i)}
        onclick={() => pick(value)}
      >
        <span>{text}</span>
        {#if badge}<em class="cv-badge">{badge}</em>{/if}
        <Icon name={ICONS.check} />
      </button>
    {:else}
      <p class="cv-dd-none">{q ? 'No match' : 'None yet'}</p>
    {/each}
  </div>
  {#if add}
    <div class="cv-dd-add">
      {#if adding}
        <form class="cv-dd-form" onsubmit={submit}>
          <!-- Enter submits the form, not the list's "pick the active option". -->
          <input
            class="cv-dd-search"
            placeholder={add.placeholder}
            spellcheck="false"
            bind:this={addInput}
            bind:value={name}
            onkeydown={e => { if (e.key !== 'Escape') e.stopPropagation(); }}
          />
          <button type="submit" class="button button-highlighted" disabled={busy}>Add</button>
        </form>
        <p class="cv-note bad">{problem}</p>
      {:else}
        <button type="button" class="cv-dd-opt cv-dd-new" onclick={startAdding}><Icon name={ICONS.plus} /><span>{add.label}</span></button>
      {/if}
    </div>
  {/if}
</div>
