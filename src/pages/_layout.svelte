<style lang="postcss">
  .min-w-6rem {
    min-width: 2rem;
  }
  .main {
    width: calc(100% - 2rem);
    margin-left: 2rem;
  }
</style>

<script>
  import { url, goto, route, isActive } from "@roxi/routify/runtime";
  import { loggedIn, session, fetch, deployments, configuration } from "@store";
  import { toast } from "@zerodevx/svelte-toast";
  import Home from "./index.svelte";
  import packageJson from "../../package.json";
  import { onMount } from "svelte";

  let upgradeAvailable = false;
  let upgradeDisabled = false;
  let upgradeDone = false;
  let latest = {};
  onMount(async () => {
    upgradeAvailable = await checkUpgrade();
  });
  async function verifyToken() {
    if ($session.token) {
      try {
        await $fetch("/api/v1/verify", {
          headers: {
            Authorization: `Bearer ${$session.token}`,
          },
        });
        $deployments = await $fetch(`/api/v1/dashboard`);
      } catch (e) {
        toast.push("Unauthorized.");
        logout();
      }
    }
  }

  if (!$loggedIn) {
    logout();
    $goto("/index");
  }

  const routes = [
    {
      name: "Dashboard",
      url: "/dashboard/applications",
      auth: true,
    },
    {
      name: "Settings",
      url: "/settings",
      auth: true,
    },
  ];
  function logout() {
    localStorage.removeItem("token");
    $session.token = null;
    $session.githubAppToken = null;
    $goto("/");
  }
  function reloadInAMin() {
    setTimeout(() => {
      location.reload();
    }, 30000);
  }
  async function upgrade() {
    try {
      upgradeDisabled = true;
      await $fetch(`/api/v1/upgrade`);
      upgradeDone = true;
    } catch (error) {
      toast.push(
        "Something happened during update. Ooops. Automatic error reporting will happen soon.",
      );
    }
  }
  async function checkUpgrade() {
    latest = await window
      .fetch(
        "https://raw.githubusercontent.com/coollabsio/coolify/main/package.json",
        { cache: "no-cache" },
      )
      .then(r => r.json());
    if (
      latest.version.split(".").join("") >
      packageJson.version.split(".").join("")
    ) {
      return true;
    }
  }
</script>

{#await verifyToken() then notUsed}
  {#if $route.path !== "/index"}
    <nav
      class="w-16 bg-warmGray-800 text-white top-0 left-0 fixed min-w-6rem min-h-screen"
    >
      <div
        class="flex flex-col w-full h-screen items-center space-y-4 transition-all duration-100"
        class:border-green-500="{$isActive('/dashboard/applications')}"
        class:border-purple-500="{$isActive('/dashboard/databases')}"
      >
        <img class="w-10 pt-4 pb-4" src="/favicon.png" alt="coolLabs logo" />
        <div
          class="p-1 text-xs rounded my-4 transition-all duration-100 cursor-pointer font-semibold"
          on:click="{() => $goto('/dashboard/applications')}"
          class:bg-green-500="{$isActive('/dashboard/applications') ||
            $isActive('/application')}"
          class:bg-purple-500="{$isActive('/dashboard/databases') ||
            $isActive('/database')}"
          class:hover:bg-green-600="{$isActive('/dashboard/applications') ||
            $isActive('/application')}"
          class:hover:bg-purple-600="{$isActive('/dashboard/databases') ||
            $isActive('/database')}"
        >
          Add
        </div>
        <div
          class="p-2 hover:bg-warmGray-700 rounded hover:text-green-500 my-4 transition-all duration-100 cursor-pointer"
          on:click="{() => $goto('/dashboard/applications')}"
          class:text-green-500="{$isActive('/dashboard/applications') ||
            $isActive('/application')}"
          class:bg-warmGray-700="{$isActive('/dashboard/applications') ||
            $isActive('/application')}"
        >
          <svg
            class="w-8 "
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            ><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect
              x="9"
              y="9"
              width="6"
              height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line
              x1="15"
              y1="1"
              x2="15"
              y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line
              x1="15"
              y1="20"
              x2="15"
              y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line
              x1="20"
              y1="14"
              x2="23"
              y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line
              x1="1"
              y1="14"
              x2="4"
              y2="14"></line></svg
          >
        </div>
        <div
          class="p-2 hover:bg-warmGray-700 rounded hover:text-purple-500 my-4 transition-all duration-100 cursor-pointer"
          on:click="{() => $goto('/dashboard/databases')}"
          class:text-purple-500="{$isActive('/dashboard/databases') ||
            $isActive('/database')}"
          class:bg-warmGray-700="{$isActive('/dashboard/databases') ||
            $isActive('/database')}"
        >
          <svg
            class="w-8"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"
            ></path>
          </svg>
        </div>
        <div class="flex-1"></div>
        <button
        class="p-2 hover:bg-warmGray-700 rounded hover:text-yellow-500 my-4 transition-all duration-100 cursor-pointer"
      >
        <svg
          class="w-8"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
          ></path>
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
      </button>
        <div
          class="cursor-pointer text-xs font-bold text-warmGray-400 py-2 hover:bg-warmGray-700 w-full text-center"
        >
          v1.0.0
        </div>
      </div>
    </nav>
  {/if}
  <main class="main">
    <nav
      class="flex text-white justify-end items-center m-4 fixed right-0 top-0 space-x-4"
    >
      {#if $isActive("/application")}
    
        <button
          disabled="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          class:cursor-not-allowed="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          class:hover:text-green-500="{$configuration.publish.domain}"
          class:hover:bg-warmGray-700="{$configuration.publish.domain}"
          class:text-warmGray-800="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          class="icon"
        >
          <svg
            class="w-6"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            ><polyline points="16 16 12 12 8 16"></polyline><line
              x1="12"
              y1="12"
              x2="12"
              y2="21"></line><path
              d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"
            ></path><polyline points="16 16 12 12 8 16"></polyline></svg
          >
        </button>
        <button
        disabled="{$configuration.publish.domain === '' ||
          $configuration.publish.domain === null}"
        class:cursor-not-allowed="{$configuration.publish.domain === '' ||
          $configuration.publish.domain === null}"
        class:hover:text-red-500="{$configuration.publish.domain}"
        class:hover:bg-warmGray-700="{$configuration.publish.domain}"
        class:text-warmGray-800="{$configuration.publish.domain === '' ||
          $configuration.publish.domain === null}"
        class="icon"
      >
      <svg class="w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
      </svg>
      </button>
        <!-- <div class="border border-warmGray-700 h-8"></div> -->
      {/if}
      <!-- <button
        class="icon hover:text-yellow-400"
      >
        <svg
          class="w-6"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
          ></path>
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
      </button> -->
    </nav>
    <slot />
  </main>
{:catch test}
  {$goto("/index")}
{/await}
