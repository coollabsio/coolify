{#if database.type && database.destinationDockerId && database.version}
  {#if $status.database.isExited}
    <a
      id="exited"
      href={!$status.database.isRunning ? `/databases/${id}/logs` : null}
      class="icons bg-transparent text-red-500 tooltip-error"
      sveltekit:prefetch
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="w-6 h-6"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentcolor"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path
          d="M8.7 3h6.6c.3 0 .5 .1 .7 .3l4.7 4.7c.2 .2 .3 .4 .3 .7v6.6c0 .3 -.1 .5 -.3 .7l-4.7 4.7c-.2 .2 -.4 .3 -.7 .3h-6.6c-.3 0 -.5 -.1 -.7 -.3l-4.7 -4.7c-.2 -.2 -.3 -.4 -.3 -.7v-6.6c0 -.3 .1 -.5 .3 -.7l4.7 -4.7c.2 -.2 .4 -.3 .7 -.3z"
        />
        <line x1="12" y1="8" x2="12" y2="12" />
        <line x1="12" y1="16" x2="12.01" y2="16" />
      </svg>
    </a>
    <Tooltip triggeredBy="#exited">{'Service exited with an error!'}</Tooltip>
  {/if}
  {#if $status.database.initialLoading}
    <button class="icons flex animate-spin  duration-500 ease-in-out">
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6"
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
    </button>
  {:else if $status.database.isRunning}
    <button
      id="stop"
      on:click={stopDatabase}
      type="submit"
      disabled={!$appSession.isAdmin}
      class="icons bg-transparent text-red-500"
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
        <rect x="6" y="5" width="4" height="14" rx="1" />
        <rect x="14" y="5" width="4" height="14" rx="1" />
      </svg>
    </button>
    <Tooltip triggeredBy="#stop">{'Stop'}</Tooltip>
  {:else}
    <button
      id="start"
      on:click={startDatabase}
      type="submit"
      disabled={!$appSession.isAdmin}
      class="icons bg-transparent text-sm flex items-center space-x-2 text-green-500"
      ><svg
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
        <path d="M7 4v16l13 -8z" />
      </svg>
    </button>
    <Tooltip triggeredBy="#start">{'Start'}</Tooltip>
  {/if}
{/if}