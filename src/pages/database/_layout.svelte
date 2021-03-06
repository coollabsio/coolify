<script>
  import { params, goto, isActive, redirect } from "@roxi/routify";
  import { fetch } from "@store";
  $: name = $params.name;

  async function removeDB() {
    await $fetch(`/api/v1/databases/${name}`, {
      method: "DELETE",
    });
    $redirect(`/dashboard/databases`);
  }
</script>

<div class="bg-coolgray-300 text-white">
  <nav
    class="mx-auto bg-coolgray-300 border-b-4 border-purple-500 text-white mb-3 sm:px-4"
  >
    <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
      {#if $isActive("/database/new")}
        <li>
          <button
            class="text-gray-600 font-bold text-sm cursor-not-allowed"
            disabled
          >
            Overview
          </button>
        </li>
        <li>
          <button class="text-purple-400 font-bold text-sm cursor-pointer">
            Configuration
          </button>
        </li>
        <li class="flex-1 hidden lg:flex"></li>
      {:else}
        <li>
          <button
            class="hover:text-purple-400 font-bold text-sm cursor-pointer"
            class:text-purple-400="{$isActive(
              `/database/${$params.name}/overview`,
            )}"
            on:click="{() => $goto(`/database/${name}/overview`)}"
          >
            Overview
          </button>
        </li>
        <li>
          <button
            class="hover:text-purple-400 font-bold text-sm cursor-pointer"
            class:text-purple-400="{$isActive(
              `/database/${$params.name}/configuration`,
            )}"
            on:click="{() => $goto(`/database/${$params.name}/configuration`)}"
          >
            Configuration
          </button>
        </li>
        <li class="flex-1 hidden lg:flex"></li>
        <li>
          <button
          class="button px-4 py-1 cursor-pointer bg-red-500 hover:bg-red-400"
            on:click="{removeDB}">Remove</button
          >
        </li>
      {/if}
    </ul>
  </nav>
</div>

<slot />
