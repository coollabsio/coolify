<script>
  import { fetch } from "@store";
  import { redirect, params } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";

  let showEnvs = false;
  $: name = $params.name;

  async function loadDatabaseConfig() {
    try {
      return await $fetch(`/api/v1/databases/${name}`);
    } catch (error) {
      $redirect(`/dashboard/databases`);
    }
  }

  function showPasswords() {
    showEnvs = !showEnvs;
  }
</script>

<div
  class="text-center space-y-2 max-w-4xl md:mx-auto mx-6 tracking-tighter"
  in:fade="{{ duration: 100 }}"
>
  {#await loadDatabaseConfig() then database}
 
    <div class="font-bold text-xl">{database.config.general.nickname}</div>
    <button
      class="button bg-purple-600 hover:bg-purple-500 text-white p-2 tracking-tighter"
      on:click="{showPasswords}">Show connection URI</button
    >
    {#if showEnvs}
      <div class="text-sm font-bold">
        mongodb://{database.envs.MONGODB_USERNAME}:{database.envs
          .MONGODB_PASSWORD}@{database.config.deploy.name}:27017/{database.envs
          .MONGODB_DATABASE}
      </div>
      <div class="text-xs">
        Root password : {database.envs.MONGODB_ROOT_PASSWORD}
      </div>
    {/if}
  {/await}
</div>
