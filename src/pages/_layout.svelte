<style lang="postcss">
  .active {
    @apply border-b-4 border-blue-500;
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
  async function upgrade() {
    try {
      toast.push("Update started. It could take a while.");
      await $fetch(`/api/v1/upgrade`);
      toast.push("Update done. 🎉 Wait about a minute and refresh your browser to get the new version.");
    } catch(error) {
      toast.push("Something happened during update. Ooops. Automatic error reporting will happen soon.");
    }
    
  }
  async function checkUpgrade() {
    const latest = await window
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
    <footer
      class="absolute bottom-0 right-0 p-2 border-t-2 border-l-2 border-indigo-400 bg-coolgray-300 text-white w-auto rounded-tl"
    >
      <div class="flex items-center">
        <div></div>
        <div class="flex-1"></div>
        {#if upgradeAvailable}
          <button
            class="bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 tracking-tight font-bold text-xs rounded px-2 mr-2 hover:to-purple-500 hover:via-purple-500"
            on:click="{upgrade}">New version available,<br>click here to upgrade now!</button
          >
          <div class="text-xs font-bold">{packageJson.version}</div>
        {:else}
          <div class="text-xs font-bold">{packageJson.version}</div>
        {/if}
      </div>
    </footer>
  {:catch test}
    {test}
    {$goto("/index")}
  {/await}
</div>
