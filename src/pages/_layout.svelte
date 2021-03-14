<style lang="postcss">
  .w-260 {
    width: 260px;
  }
</style>

<script>
  import { url, goto, route } from "@roxi/routify/runtime";
  import { loggedIn, session, fetch, deployments } from "@store";
  import { toast } from "@zerodevx/svelte-toast";
  import Home from "./index.svelte";
  import packageJson from "../../package.json";
  import { onMount } from "svelte";

  let upgradeAvailable = false;
  let upgradeDisabled = false;
  let upgradeDone = false;
  let latest = {}
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
    setTimeout(()=> {
      location.reload();
    },30000)
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

<div>
  {#await verifyToken() then notUsed}
    <main>
      {#if $route.path !== "/index"}
        <nav
          class="bg-coolgray-300 h-12 px-4 flex font-bold tracking-tight text-white justify-center items-center"
        >
          <div class="absolute mt-2 mx-4 left-0">
            <img class="w-8" src="/favicon.png" alt="coolLabs logo" />
          </div>
          <div class="flex-1"></div>
          <div class="space-x-4 text-right">
            {#each routes as route}
              <a class="hover:text-yellow-400" href="{$url(route.url)}"
                >{route.name}</a
              >
            {/each}
            <button
              class="hover:text-yellow-400 tracking-tight font-bold"
              on:click="{logout}">Logout</button
            >
          </div>
        </nav>
        <slot />
      {:else}
        <Home />
      {/if}
    </main>
    {#if upgradeAvailable}
    <footer
      class="absolute bottom-0 right-0 p-2 border-t-2 border-l-2 border-black bg-coolgray-300 text-white w-auto rounded-tl"
    >
      <div class="flex items-center">
        <div></div>
        <div class="flex-1"></div>
          {#if !upgradeDisabled}
            <button
              class="bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 tracking-tight font-bold text-xs rounded px-2"
              disabled="{upgradeDisabled}"
              on:click="{upgrade}"
              >New version available. Click here to upgrade!</button
            >
          {:else if upgradeDone}
            <button
              use:reloadInAMin
              class="tracking-tight font-bold text-xs rounded px-2 cursor-not-allowed"
              disabled="{upgradeDisabled}"
              >Upgrade done. 🎉 Automatically reloading in 30s.</button
            >
          {:else}
            <button
              class="opacity-50 tracking-tight font-bold text-xs rounded px-2  cursor-not-allowed"
              disabled="{upgradeDisabled}">Upgrading. It could take a while, please wait...</button
            >
          {/if}
    
      </div>
    </footer>
    {/if}
  {:catch test}
    {test}
    {$goto("/index")}
  {/await}
</div>
