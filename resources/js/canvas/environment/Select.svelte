<script>
  // Our listbox (same look as <x-forms.listbox>) instead of the native <select>.
  // The panel is absolute, not fixed: the zoom controls bar has a backdrop filter,
  // which makes it the containing block of fixed children.

  let { options, value, label, class: className = '', onchange } = $props();

  let open = $state(false);
  let above = $state(false);
  let active = $state(0);
  let root;
  let trigger;
  let list = $state();

  const current = $derived(options.find(o => o.value === value) ?? options[0]);

  function toggle() {
    if (open) return close();
    const r = trigger.getBoundingClientRect();
    above = innerHeight - r.bottom < 280 && r.top > innerHeight - r.bottom;
    active = Math.max(0, options.findIndex(o => o.value === value));
    open = true;
  }
  function close(focus = false) {
    open = false;
    if (focus) trigger.focus();
  }
  function pick(option) {
    close(true);
    if (option.value !== value) onchange(option.value);
  }
  function keys(e) {
    if (e.key === 'Escape' && open) {
      e.preventDefault();
      close(true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) return toggle();
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
    } else if (e.key === 'Enter' && open) {
      e.preventDefault();
      pick(options[active]);
    }
  }

  $effect(() => {
    if (open) list?.children[active]?.scrollIntoView({ block: 'nearest' });
  });
</script>

<svelte:window onpointerdown={e => open && !root.contains(e.target) && close()} />

<div class="relative {className}" bind:this={root} onkeydown={keys} role="presentation">
  <button type="button" class="listbox-trigger h-8! text-[12px]!" bind:this={trigger} onclick={toggle}
    aria-haspopup="listbox" aria-expanded={open} aria-label={label} title={label}>
    <span class="listbox-trigger-label">{current?.label}</span>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
      <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
    </svg>
  </button>
  {#if open}
    <div class="listbox-panel" style={above ? 'top: auto; bottom: calc(100% + 0.25rem)' : ''} role="listbox" aria-label={label} bind:this={list}>
      {#each options as option, i (option.value)}
        <button type="button" class="listbox-option text-[13px]!" style:background={i === active ? 'var(--coollabs-fill)' : null} role="option" aria-selected={option.value === value}
          onpointerenter={() => (active = i)} onclick={() => pick(option)}>
          <span class="truncate">{option.label}</span>
          {#if option.value === value}
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="ml-auto size-3.5 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
          {/if}
        </button>
      {/each}
    </div>
  {/if}
</div>
