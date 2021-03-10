<style lang="postcss">
  .active {
    @apply border-b-4 border-blue-500;
  }
</style>

<script>
  import { url, isActive, goto, route } from "@roxi/routify/runtime";
  import { loggedIn, session, fetch, deployments } from "@store";
  import Home from "./index.svelte";
  import { toast } from "@zerodevx/svelte-toast";

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
</script>

<div class="min-h-full bg-gray-50">
  {#await verifyToken() then notUsed}
    <main>
      {#if $route.path !== "/index"}
        <nav
          class="bg-coolgray-300 h-12 px-4 grid grid-cols-3 font-bold tracking-tight text-white justify-center items-center"
        >
          <div class="absolute mt-2">
            <a href="{$url('/dashboard/applications')}"
              ><img class="w-8" src="/favicon.png" alt="coolLabs logo" /></a
            >
          </div>
          <div class="lg:col-span-2"></div>
          <div class="col-span-2 lg:col-span-1 space-x-4 text-right">
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
  {:catch test}
    {test}
    {$goto("/index")}
  {/await}
</div>
