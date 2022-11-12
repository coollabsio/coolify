<script>
  import { page } from '$app/stores';
  import { appSession } from '$lib/store';
  import Tooltip from '$lib/components/Tooltip.svelte';
  import UpdateAvailable from '$lib/components/UpdateAvailable.svelte';
</script>
{#if $appSession.userId}
  <nav class="nav-main hidden lg:block z-20">
    <div class="flex h-screen w-full flex-col items-center transition-all duration-100">
      {#if !$appSession.whiteLabeled}
        <div class="mb-2 mt-4 h-10 w-10">
          <img src="/favicon.png" alt="coolLabs logo" />
        </div>
      {:else if $appSession.whiteLabeledDetails.icon}
        <div class="mb-2 mt-4 h-10 w-10">
          <img src={$appSession.whiteLabeledDetails.icon} alt="White labeled logo" />
        </div>
      {/if}
      <div class="flex flex-col space-y-2 py-2" class:mt-2={$appSession.whiteLabeled}>
        <a
          id="dashboard"
          sveltekit:prefetch
          href="/"
          class="icons hover:text-pink-500"
          class:text-pink-500={$page.url.pathname === '/'}
          class:bg-coolgray-500={$page.url.pathname === '/'}
          class:bg-coolgray-200={!($page.url.pathname === '/')}
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-9 w-9"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path
              d="M19 8.71l-5.333 -4.148a2.666 2.666 0 0 0 -3.274 0l-5.334 4.148a2.665 2.665 0 0 0 -1.029 2.105v7.2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-7.2c0 -.823 -.38 -1.6 -1.03 -2.105"
            />
            <path d="M16 15c-2.21 1.333 -5.792 1.333 -8 0" />
          </svg>
        </a>
        {#if $appSession.teamId === '0'}
          <a
            id="servers"
            sveltekit:prefetch
            href="/servers"
            class="icons hover:text-sky-500"
            class:text-sky-500={$page.url.pathname === '/servers'}
            class:bg-coolgray-500={$page.url.pathname === '/servers'}
            class:bg-coolgray-200={!($page.url.pathname === '/servers')}
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="w-8 h-8 mx-auto"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              fill="none"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <rect x="3" y="4" width="18" height="8" rx="3" />
              <rect x="3" y="12" width="18" height="8" rx="3" />
              <line x1="7" y1="8" x2="7" y2="8.01" />
              <line x1="7" y1="16" x2="7" y2="16.01" />
            </svg>
          </a>
        {/if}
      </div>
      <Tooltip triggeredBy="#dashboard" placement="right">Dashboard</Tooltip>
      <Tooltip triggeredBy="#servers" placement="right">Servers</Tooltip>
      <div class="flex-1" />
      <div class="lg:block hidden">
        <UpdateAvailable />
      </div>
      <div class="flex flex-col space-y-2 py-2">
        <a
          id="iam"
          sveltekit:prefetch
          href={$appSession.pendingInvitations.length > 0 ? '/iam/pending' : '/iam'}
          class="icons hover:text-iam indicator"
          class:text-iam={$page.url.pathname.startsWith('/iam')}
          class:bg-coolgray-500={$page.url.pathname.startsWith('/iam')}
        >
          {#if $appSession.pendingInvitations.length > 0}
            <span class="indicator-item rounded-full badge badge-primary mr-2"
              >{pendingInvitations.length}</span
            >
          {/if}<svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            class="h-9 w-9"
            stroke-width="1.5"
            stroke="currentColor"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <circle cx="9" cy="7" r="4" />
            <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
          </svg>
        </a>
        <a
          id="settings"
          sveltekit:prefetch
          href={$appSession.teamId === '0' ? '/settings/coolify' : '/settings/ssh'}
          class="icons hover:text-settings"
          class:text-settings={$page.url.pathname.startsWith('/settings')}
          class:bg-coolgray-500={$page.url.pathname.startsWith('/settings')}
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            class="h-9 w-9"
            stroke-width="1.5"
            stroke="currentColor"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path
              d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"
            />
            <circle cx="12" cy="12" r="3" />
          </svg>
        </a>

        <!-- svelte-ignore a11y-click-events-have-key-events -->
        <div
          id="logout"
          class="icons bg-coolgray-200 hover:text-error cursor-pointer"
          on:click={logout}
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="ml-1 h-8 w-8"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path
              d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"
            />
            <path d="M7 12h14l-3 -3m0 6l3 -3" />
          </svg>
        </div>
        <div
          class="w-full text-center font-bold text-stone-400 hover:bg-coolgray-200 hover:text-white"
        >
          <a
            class="text-[10px] no-underline"
            href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
            target="_blank noreferrer">v{$appSession.version}</a
          >
        </div>
      </div>
    </div>
  </nav>
  {#if $appSession.whiteLabeled}
    <span class="fixed bottom-0 left-[50px] z-50 m-2 px-4 text-xs text-stone-700"
      >Powered by <a href="https://coolify.io" target="_blank noreferrer">Coolify</a></span
    >
  {/if}
{/if}