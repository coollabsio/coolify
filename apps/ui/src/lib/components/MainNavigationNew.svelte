<script>
  import { page } from '$app/stores';
  import { appSession } from '$lib/store';
  import Tooltip from '$lib/components/Tooltip.svelte';
  import UpdateAvailable from '$lib/components/UpdateAvailable.svelte';
	import DashboardIcon from './svg/menu/DashboardIcon.svelte';
	import ServersIcon from './svg/menu/ServersIcon.svelte';
	import DatabasesIcon from './svg/menu/DatabasesIcon.svelte';
	import IamIcon from './svg/menu/IamIcon.svelte';
	import SettingsIcon from './svg/menu/SettingsIcon.svelte';
	import LogoutIcon from './svg/menu/LogoutIcon.svelte';
	import SourcesIcon from './svg/menu/SourcesIcon.svelte';
  import {logout} from '$lib/common';

</script>

{#if $appSession.userId}
  <nav class="hidden menu-bar lg:flex" style="background: rgb(32 32 32 / var(--tw-bg-opacity)); border-bottom: thin solid #8884">
    <!-- Logo vs Whitelabel -->
    {#if !$appSession.whiteLabeled}
      <div class="m-2 h-10 w-10">
        <img src="/favicon.png" alt="coolLabs logo" />
      </div>
    {:else if $appSession.whiteLabeledDetails.icon}
      <div class="m-2 h-10 w-10">
        <img src={$appSession.whiteLabeledDetails.icon} alt="White labeled logo" />
      </div>
    {/if}

    <!-- Left menu -->
    <div class="flex" class:mt-2={$appSession.whiteLabeled}>
      <a
        id="dashboard"
        sveltekit:prefetch
        href="/"
        class="icons hover:text-pink-500"
        class:text-pink-500={$page.url.pathname === '/'}
        class:bg-coolgray-500={$page.url.pathname === '/'}
        class:bg-coolgray-200={!($page.url.pathname === '/')}
      >
       <DashboardIcon/>
      </a>
      <a
        id="sources"
        sveltekit:prefetch
        href="/sources"
        class="icons hover:text-sky-500"
        class:text-sky-500={$page.url.pathname === '/sources'}
        class:bg-coolgray-500={$page.url.pathname === '/sources'}
        class:bg-coolgray-200={!($page.url.pathname === '/sources')}
      >
        <SourcesIcon/>
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
          <ServersIcon/>
        </a>
        <a
        id="databases"
        sveltekit:prefetch
        href="/databases"
        class="icons hover:text-sky-500"
        class:text-sky-500={$page.url.pathname === '/databases'}
        class:bg-coolgray-500={$page.url.pathname === '/databases'}
        class:bg-coolgray-200={!($page.url.pathname === '/databases')}
      >
        <DatabasesIcon/>
      </a>
      {/if}
    </div>
    <Tooltip triggeredBy="#dashboard" placement="right">Dashboard</Tooltip>
    <Tooltip triggeredBy="#servers" placement="right">Servers</Tooltip>
    <Tooltip triggeredBy="#databases" placement="right">Databases</Tooltip>
    <Tooltip triggeredBy="#sources" placement="right">Sources</Tooltip>

    <div class="flex-1" />
    <div class="lg:block hidden">
      <UpdateAvailable />
    </div>

    <!-- Right menu -->
    <div class="flex">
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
        {/if}
        <IamIcon/>
      </a>
      <a
        id="settings"
        sveltekit:prefetch
        href={$appSession.teamId === '0' ? '/settings/coolify' : '/settings/ssh'}
        class="icons hover:text-settings"
        class:text-settings={$page.url.pathname.startsWith('/settings')}
        class:bg-coolgray-500={$page.url.pathname.startsWith('/settings')}
      >
        <SettingsIcon/>
      </a>

      <!-- svelte-ignore a11y-click-events-have-key-events -->
      <div
        id="logout"
        class="icons bg-coolgray-200 hover:text-error cursor-pointer"
        on:click={logout}
      >
        <LogoutIcon/>
      </div>
      <div
        class="m-1 mr-3 self-center font-bold text-stone-400 hover:bg-coolgray-200 hover:text-white"
      >
        <a
          class="text-[10px] no-underline"
          href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
          target="_blank noreferrer">v{$appSession.version}</a
        >
      </div>
    </div>
  </nav>
  {#if $appSession.whiteLabeled}
    <span class="items-center text-xs text-stone-700"
      >Powered by <a href="https://coolify.io" target="_blank noreferrer">Coolify</a></span
    >
  {/if}
{/if}
