<script>
  // The logo of a resource card (database engine, service template, build source), or its type icon
  // when it has no logo or the logo fails to load. The favicon of an application domain replaces the
  // logo once it loads.
  import Icon from './Icon.svelte';

  let { node, class: cls = 'size-4' } = $props();
  const logo = $derived(node.logo);
  // Sources that failed to load; a failed logo shows its fallback, a failed fallback shows the type icon.
  let failed = $state({});
  // The favicon URL that loaded.
  let loadedFavicon = $state(null);
  const shown = src => (failed[src] && logo.fallback ? logo.fallback : src);
  const broken = $derived(!!logo?.src && [logo.src, logo.dark].filter(Boolean).some(src => failed[src] && (!logo.fallback || failed[logo.fallback])));
  const faviconShown = $derived(!!logo?.favicon && loadedFavicon === logo.favicon);
  const onerror = src => () => {
    failed[shown(src) === src ? src : logo.fallback] = true;
  };
</script>

{#if faviconShown}
  <img class="size-full object-contain p-1" alt="" src={logo.favicon} />
{:else}
  {#if !logo?.src || broken}
    <Icon name={node.type} class={cls} />
  {:else}
    <span class="flex size-full items-center justify-center">
      <img class="size-full object-contain {logo.padded ? 'p-1' : ''} {logo.dark ? 'dark:hidden' : ''}" alt="" src={shown(logo.src)} onerror={onerror(logo.src)} />
      {#if logo.dark}
        <img class="size-full object-contain {logo.padded ? 'p-1' : ''} hidden dark:block" alt="" src={shown(logo.dark)} onerror={onerror(logo.dark)} />
      {/if}
    </span>
  {/if}
  {#if logo?.favicon && !failed[logo.favicon]}
    <img class="hidden" alt="" src={logo.favicon} onload={() => (loadedFavicon = logo.favicon)} onerror={() => (failed[logo.favicon] = true)} />
  {/if}
{/if}
