<script>
  import { navigating, page } from '$app/stores';
  import { appSession } from '$lib/store';
  import {logout} from '$lib/common';
  import UpdateAvailable from '$lib/components/UpdateAvailable.svelte';
</script>
<div class="drawer-side">
  <label for="main-drawer" class="drawer-overlay w-full" />
  <ul class="menu bg-coolgray-200 w-60 p-2  space-y-3 pt-4 ">
    <li>
      <a
        class="no-underline icons hover:text-white hover:bg-pink-500"
        sveltekit:prefetch
        href="/"
        class:bg-pink-500={$page.url.pathname === '/'}
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="h-8 w-8"
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
        Dashboard
      </a>
    </li>

    <li>
      <a
        id="servers"
        class="no-underline icons hover:text-white hover:bg-sky-500"
        sveltekit:prefetch
        href="/servers"
        class:bg-sky-500={$page.url.pathname.startsWith('/servers')}
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="w-8 h-8"
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
        Servers
      </a>
    </li>
    <li>
      <a
        class="no-underline icons hover:text-white hover:bg-iam"
        href="/iam"
        class:bg-iam={$page.url.pathname.startsWith('/iam')}
        ><svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          class="h-8 w-8"
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
        IAM {#if $appSession.pendingInvitations.length > 0}
          <span class="indicator-item rounded-full badge badge-primary"
            >{pendingInvitations.length}</span
          >
        {/if}
      </a>
    </li>
    <li>
      <a
        class="no-underline icons hover:text-black hover:bg-settings"
        href={$appSession.teamId === '0' ? '/settings/coolify' : '/settings/ssh'}
        class:bg-settings={$page.url.pathname.startsWith('/settings')}
        class:text-black={$page.url.pathname.startsWith('/settings')}
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          class="h-8 w-8"
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
        Settings
      </a>
    </li>
    <li class="flex-1 bg-transparent" />
    <div class="block lg:hidden">
      <UpdateAvailable />
    </div>
    <li>
      <!-- svelte-ignore a11y-click-events-have-key-events -->
      <div class="no-underline icons hover:bg-error" on:click={logout}>
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
        <div class="-ml-1">Logout</div>
      </div>
    </li>
    <li class="w-full">
      <a
        class="text-xs hover:bg-coolgray-200 no-underline hover:text-white text-right"
        href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
        target="_blank noreferrer">v{$appSession.version}</a
      >
    </li>
  </ul>
</div>