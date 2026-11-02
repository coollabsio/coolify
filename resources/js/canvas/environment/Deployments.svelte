<script>
  // Dashboard canvas: running, queued, and recent deployments. A row shows its application card;
  // the status opens the deployment. Folded or open is remembered in localStorage.
  import { TONE_DOT } from '../../environment-canvas.js';
  import Icon from './Icon.svelte';

  let { deployments, nodeById, onshow } = $props();

  const KEY = 'coolify-canvas-deployments-open';
  const STATUS = {
    in_progress: ['In progress', 'busy'],
    queued: ['Queued', 'busy'],
    finished: ['Success', 'ok'],
    failed: ['Failed', 'bad'],
    'cancelled-by-user': ['Cancelled', 'idle'],
  };
  const status = value => STATUS[value] || [String(value || 'Unknown').replace(/[-_]/g, ' '), 'idle'];

  let open = $state(readOpen());
  function readOpen() {
    try {
      return localStorage.getItem(KEY) !== 'false';
    } catch {
      return true;
    }
  }
  function toggle() {
    open = !open;
    try {
      localStorage.setItem(KEY, String(open));
    } catch {
      // Storage blocked: the panel opens next time.
    }
  }

  // "3m ago"; re-rendered with each canvas reload.
  const units = [['d', 86400], ['h', 3600], ['m', 60]];
  function ago(iso) {
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    for (const [unit, size] of units) {
      if (seconds >= size) return `${Math.floor(seconds / size)}${unit} ago`;
    }
    return 'just now';
  }
</script>

<section class="flex max-h-80 w-72 min-w-0 flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg dark:border-white/[0.08] dark:bg-panel" data-ec-deployments>
  <button type="button" class="flex w-full shrink-0 items-center gap-2 px-3 py-2.5 text-left" aria-expanded={open} onclick={toggle}>
    <span class="min-w-0 flex-1 text-[12px] font-semibold text-black dark:text-fg">Recent deployments</span>
    <span class="text-[11px] text-neutral-400 dark:text-fg-faint">{deployments.length}</span>
    <Icon name="chevron" class="size-3.5 text-neutral-400 transition-transform dark:text-fg-faint {open ? '' : 'rotate-180'}" />
  </button>
  {#if open}
    <ul class="min-h-0 overflow-y-auto border-t border-neutral-100 dark:border-white/[0.06]">
      {#each deployments as deployment (deployment.id)}
        {@const [label, tone] = status(deployment.status)}
        {@const node = deployment.application ? nodeById(deployment.application) : null}
        <li class="flex items-center gap-2 border-b border-neutral-100 px-3 py-2 last:border-b-0 dark:border-white/[0.06]">
          <button
            type="button"
            class="min-w-0 flex-1 text-left disabled:cursor-default enabled:hover:[&>p:first-child]:underline"
            disabled={!node}
            title={node ? 'Show on the canvas' : undefined}
            onclick={() => onshow(node.id)}
          >
            <p class="truncate text-[12px] font-medium text-black underline-offset-2 dark:text-fg">{deployment.name}</p>
            <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
              {deployment.pull_request_id ? `PR #${deployment.pull_request_id} · ` : ''}{deployment.created_at ? ago(deployment.created_at) : deployment.server}
            </p>
          </button>
          <a
            class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-2 text-[10px] leading-5 font-medium text-neutral-600 hover:border-neutral-300 hover:text-black dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim dark:hover:border-white/[0.2] dark:hover:text-fg"
            href={deployment.link}
            title="Open the deployment"
          >
            <span class="relative flex size-1.5">
              {#if tone === 'busy'}<span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {TONE_DOT.busy}"></span>{/if}
              <span class="relative inline-flex size-full rounded-full {TONE_DOT[tone]}"></span>
            </span>
            {label}
          </a>
        </li>
      {/each}
    </ul>
  {/if}
</section>
