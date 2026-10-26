<script>
  import { ICONS, lists, loaderKey } from '../../deploy-canvas.js';
  import Icon from './Icon.svelte';
  import { cv, openDropdown, setField } from './state.svelte.js';

  // One field of a card: a note, segmented buttons, our dropdown, or a text input.
  let { n, f } = $props();
  let trigger = $state();

  const stop = e => e.stopPropagation();
  const set = value => setField(n, f, value);

  // Select options: null while loading; undefined / '' when the field above is empty.
  const list = $derived.by(() => {
    cv.listsVersion;
    return f.kind === 'select' ? (f.load ? f.load(n.data) : f.options) : null;
  });
  const error = $derived.by(() => {
    cv.listsVersion;
    return f.load ? lists.errors[loaderKey(f, n.data)] : null;
  });
  const value = $derived(n.data[f.key] ?? '');
  // An optional field's default ("Default") is a real choice, not a placeholder.
  const chosen = $derived((list || []).find(([v]) => v === value) || (list && f.optional && !value ? ['', f.optional] : null));
  const placeholder = $derived(list ? (f.optional || `Choose ${f.label.toLowerCase()}`) : list === null ? 'Loading…' : 'Pick the field above first');
  // `add`: a "New …" row at the end of the list creates one and selects it.
  const add = $derived(f.add && cv.canAdd ? {
    ...f.add,
    create: name => f.add.create(name, n.data),
    // Load the list again (it has the new one), then select it.
    done: picked => {
      delete lists.cache[f.add.list(n.data)];
      cv.listsVersion++;
      set(picked);
    },
  } : null);

  function open() {
    openDropdown(trigger, [...(f.optional ? [['', f.optional]] : []), ...(list || [])], value, set, add);
  }
  function keys(e) {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      trigger.click();
    }
  }
</script>

{#if f.kind === 'note'}
  <p class="cv-note">{f.text}</p>
{:else}
  <div class="cv-field">
    <!-- svelte-ignore a11y_label_has_associated_control -->
    <label>{f.label}{#if f.required}<em>{' *'}</em>{/if}</label>
    {#if f.kind === 'segmented'}
      <div class="cv-seg">
        {#each f.options as [option, text]}
          <button type="button" class={(n.data[f.key] ?? f.value) === option ? 'on' : ''} onclick={() => set(option)} onpointerdown={stop}>{text}</button>
        {/each}
      </div>
    {:else if f.kind === 'select'}
      <button
        bind:this={trigger}
        type="button"
        class="cv-input cv-select"
        class:empty={!chosen}
        class:open={!!trigger && cv.dropdown?.trigger === trigger}
        disabled={!list}
        aria-haspopup="listbox"
        onpointerdown={stop}
        onclick={open}
        onkeydown={keys}
      >
        <span>{chosen ? chosen[1] : placeholder}</span>
        {#if chosen?.[2]}<em class="cv-badge">{chosen[2]}</em>{/if}
        <Icon name={ICONS.chevron} />
      </button>
      {#if error}<span class="cv-note bad">{error}</span>{/if}
    {:else if f.kind === 'textarea'}
      <textarea
        class="cv-input"
        class:mono={f.mono}
        rows={f.rows || 3}
        placeholder={f.placeholder || ''}
        spellcheck="false"
        value={n.data[f.key] ?? ''}
        oninput={e => set(e.currentTarget.value)}
        onpointerdown={stop}
        onkeydown={stop}
      ></textarea>
    {:else}
      <input
        type="text"
        class="cv-input"
        class:mono={f.mono}
        placeholder={f.placeholder || ''}
        spellcheck="false"
        value={n.data[f.key] ?? ''}
        oninput={e => set(e.currentTarget.value)}
        onpointerdown={stop}
        onkeydown={stop}
      />
    {/if}
  </div>
{/if}
