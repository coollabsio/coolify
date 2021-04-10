<style lang="postcss">
  .min-w-4rem {
    min-width: 4rem;
  }
</style>

<script>
  import { goto, route, isActive } from "@roxi/routify/runtime";
  import { loggedIn, session, fetch, deployments } from "@store";
  import { toast } from "@zerodevx/svelte-toast";
  import { onMount } from "svelte";
  import compareVersions from "compare-versions";
  import packageJson from "../../package.json";
  import Tooltip from "../components/Tooltip/Tooltip.svelte";

  let upgradeAvailable = false;
  let upgradeDisabled = false;
  let upgradeDone = false;
  let latest = {};
  onMount(async () => {
    if ($session.token) upgradeAvailable = await checkUpgrade();
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
      .fetch(`https://get.coollabs.io/version.json`, {
        cache: "no-cache",
      })
      .then(r => r.json());
      console.log(latest)
    const branch =
      process.env.NODE_ENV === "production" &&
      window.location.hostname !== "test.andrasbacsai.dev"
        ? "main"
        : "next";
    return compareVersions(latest.coolify[branch], packageJson.version) === 1
      ? true
      : false;
  }
</script>

{#await verifyToken() then notUsed}
  {#if $route.path !== "/index"}
    <nav
      class="w-16 bg-warmGray-800 text-white top-0 left-0 fixed min-w-4rem min-h-screen"
    >
      <div
        class="flex flex-col w-full h-screen items-center transition-all duration-100"
        class:border-green-500="{$isActive('/dashboard/applications')}"
        class:border-purple-500="{$isActive('/dashboard/databases')}"
      >
        <img class="w-10 pt-4 pb-4" src="/favicon.png" alt="coolLabs logo" />
        <Tooltip position="right" label="Applications">
          <div
            class="p-2 hover:bg-warmGray-700 rounded hover:text-green-500 my-4 transition-all duration-100 cursor-pointer"
            on:click="{() => $goto('/dashboard/applications')}"
            class:text-green-500="{$isActive('/dashboard/applications') ||
              $isActive('/application')}"
            class:bg-warmGray-700="{$isActive('/dashboard/applications') ||
              $isActive('/application')}"
          >
            <svg
              class="w-8"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              ><rect x="4" y="4" width="16" height="16" rx="2" ry="2"
              ></rect><rect x="9" y="9" width="6" height="6"></rect><line
                x1="9"
                y1="1"
                x2="9"
                y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line
                x1="9"
                y1="20"
                x2="9"
                y2="23"></line><line x1="15" y1="20" x2="15" y2="23"
              ></line><line x1="20" y1="9" x2="23" y2="9"></line><line
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
        </Tooltip>
        <Tooltip position="right" label="Databases">
          <div
            class="p-2 hover:bg-warmGray-700 rounded hover:text-purple-500 transition-all duration-100 cursor-pointer"
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
        </Tooltip>
        <div class="flex-1"></div>
        <Tooltip position="right" label="Settings">
          <button
            class="p-2 hover:bg-warmGray-700 rounded hover:text-yellow-500 transition-all duration-100 cursor-pointer"
            class:text-yellow-500="{$isActive('/settings')}"
            class:bg-warmGray-700="{$isActive('/settings')}"
            on:click="{() => $goto('/settings')}"
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
        </Tooltip>
        <Tooltip position="right" label="Logout">
          <button
            class="p-2 hover:bg-warmGray-700 rounded hover:text-red-500 my-4 transition-all duration-100 cursor-pointer"
            on:click="{logout}"
          >
            <svg
              class="w-7"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              ><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"
              ></path><polyline points="16 17 21 12 16 7"></polyline><line
                x1="21"
                y1="12"
                x2="9"
                y2="12"></line></svg
            >
          </button>
        </Tooltip>
        <div
          class="cursor-pointer text-xs font-bold text-warmGray-400 py-2 hover:bg-warmGray-700 w-full text-center"
        >
          {packageJson.version}
        </div>
      </div>
    </nav>
  {/if}
  {#if upgradeAvailable}
    <footer
      class="absolute bottom-0 right-0 p-4 px-6 w-auto rounded-tl text-white "
    >
      <div class="flex items-center">
        <div></div>
        <div class="flex-1"></div>
        {#if !upgradeDisabled}
          <button
            class="bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 text-xs font-bold rounded px-2 py-2"
            disabled="{upgradeDisabled}"
            on:click="{upgrade}"
            >New version available, <br />click here to upgrade!</button
          >
        {:else if upgradeDone}
          <button
            use:reloadInAMin
            class="font-bold text-xs rounded px-2 cursor-not-allowed"
            disabled="{upgradeDisabled}"
            >Upgrade done. 🎉 Automatically reloading in 30s.</button
          >
        {:else}
          <button
            class="opacity-50 tracking-tight font-bold text-xs rounded px-2  cursor-not-allowed"
            disabled="{upgradeDisabled}"
            >Upgrading. It could take a while, please wait...</button
          >
        {/if}
      </div>
    </footer>
  {/if}
  <main class:main="{$route.path !== '/index'}">
    <slot />
  </main>
{:catch test}
  {$goto("/index")}
{/await}
