

{#if $status.service.initialLoading}
<button class="btn btn-ghost btn-sm gap-2">
  <svg
    xmlns="http://www.w3.org/2000/svg"
    class="h-6 w-6 animate-spin duration-500 ease-in-out"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    fill="none"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <path d="M9 4.55a8 8 0 0 1 6 14.9m0 -4.45v5h5" />
    <line x1="5.63" y1="7.16" x2="5.63" y2="7.17" />
    <line x1="4.06" y1="11" x2="4.06" y2="11.01" />
    <line x1="4.63" y1="15.1" x2="4.63" y2="15.11" />
    <line x1="7.16" y1="18.37" x2="7.16" y2="18.38" />
    <line x1="11" y1="19.94" x2="11" y2="19.95" />
  </svg>
  {$status.service.startup[id] || 'Loading...'}
</button>
{:else if $status.service.overallStatus === 'healthy'}
<button
  disabled={!$isDeploymentEnabled}
  class="btn btn-sm gap-2"
  on:click={() => restartService()}
>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    class="w-6 h-6"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    fill="none"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <path
      d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
      transform="rotate(-45 12 12)"
    />
  </svg>

  Force Redeploy
</button>
<button
  on:click={() => stopService(false)}
  type="submit"
  disabled={!$isDeploymentEnabled}
  class="btn btn-sm gap-2"
>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    class="w-6 h-6 text-error "
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    fill="none"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <rect x="6" y="5" width="4" height="14" rx="1" />
    <rect x="14" y="5" width="4" height="14" rx="1" />
  </svg>
  Stop
</button>
{:else if $status.service.overallStatus === 'degraded'}
<button
  on:click={stopService}
  type="submit"
  disabled={!$isDeploymentEnabled}
  class="btn btn-sm  gap-2"
>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    class="w-6 h-6 text-error"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    fill="none"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <rect x="6" y="5" width="4" height="14" rx="1" />
    <rect x="14" y="5" width="4" height="14" rx="1" />
  </svg> Stop
</button>
{:else if $status.service.overallStatus === 'stopped'}
{#if $status.service.overallStatus === 'degraded'}
  <button
    class="btn btn-sm gap-2"
    disabled={!$isDeploymentEnabled}
    on:click={() => restartService()}
  >
    <svg
      xmlns="http://www.w3.org/2000/svg"
      class="w-6 h-6"
      viewBox="0 0 24 24"
      stroke-width="1.5"
      stroke="currentColor"
      fill="none"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
      <path
        d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
        transform="rotate(-45 12 12)"
      />
    </svg>
    {$status.application.statuses.length === 1 ? 'Force Redeploy' : 'Redeploy Stack'}
  </button>
{/if}
{/if}