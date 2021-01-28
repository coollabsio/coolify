<script>
  import { url, isActive, goto, route } from "@roxi/routify/runtime";
  import { loggedIn, session, fetch } from "../store";
  import Home from "./index.svelte";

  async function verifyToken() {
    if ($session.token) {
      try {
        await $fetch("/api/v1/verify", {
          headers: {
            Authorization: `Bearer ${$session.token}`,
          },
        });
      } catch (e) {
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
      url: "/dashboard",
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

<div class="bg-coolgray-100 min-h-full text-white">
  {#await verifyToken() then notUsed}
    <main>
      {#if $route.path !== "/index"}
        <nav
          class="py-4 px-4 grid grid-cols-3 text-white border-b-2 font-bold tracking-tight bg-coolgray-200 border-black shadow"
        >
          <div class="lg:col-span-2">
            <div class="font-bold text-xl">Coolify</div>
          </div>
          <div class="col-span-2 lg:col-span-1 space-x-4 text-right text-white">
            {#each routes as route}
              <a
                class={$isActive(`.${route.url === "/" ? "/index" : route.url}`)
                  ? "active"
                  : "border-b-4 border-transparent hover:border-blue-500"}
                href={$url(route.url)}>{route.name}</a
              >
            {/each}
            <button
              class="border-b-4 border-transparent  hover:border-blue-500 tracking-tight font-bold"
              on:click={logout}>Logout</button
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

<style lang="postcss">
  .active {
    @apply border-b-4 border-blue-500;
  }
</style>
