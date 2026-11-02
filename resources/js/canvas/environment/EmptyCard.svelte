<script>
  // Placeholder card of an environment (or project) without resources: opens the deploy canvas (admins) or its page on click.
  import Icon from './Icon.svelte';

  let { node, position } = $props();
</script>

<a
  class="group absolute flex w-[260px] items-center gap-2.5 rounded-xl border border-dashed border-neutral-300 bg-white/60 p-3 hover:border-neutral-400 dark:border-white/[0.12] dark:bg-white/[0.02] dark:hover:border-white/[0.24]"
  style:left={position ? `${position.x}px` : null}
  style:top={position ? `${position.y}px` : null}
  href={node.link}
  draggable="false"
  data-ec-card
  data-id={node.id}
>
  <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-dashed border-neutral-300 text-neutral-400 dark:border-white/[0.12] dark:text-fg-faint">
    <Icon name="plus" class="size-3.5" />
  </div>
  <div class="min-w-0 flex-1">
    <div class="truncate text-[13px] leading-4 font-semibold text-neutral-500 underline-offset-2 group-hover:underline dark:text-fg-dim">{node.name}</div>
    <div class="mt-0.5 truncate text-[11px] leading-4 text-neutral-400 dark:text-fg-faint">{node.subtitle}</div>
  </div>
</a>
