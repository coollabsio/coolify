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
  async function removeDB() {
    await $fetch(`/api/v1/databases/${name}`, {
      method: "DELETE",
    });
    $redirect(`/dashboard/databases`);
  }

  function showPasswords() {
    showEnvs = !showEnvs;
  }
</script>

<div
  class="text-center space-y-2 max-w-4xl md:mx-auto mx-6 py-4"
  in:fade="{{ duration: 100 }}"
>
  {#await loadDatabaseConfig() then database}
    <button
      class="button bg-red-600 text-white p-2 hover:bg-red-500"
      on:click="{removeDB}">Remove database</button
    >
    <div>Name: {database.config.general.nickname}</div>

    <button on:click="{showPasswords}">Show connection URI</button>
    {#if showEnvs}
      <div>
        Connection URI: mongodb://MONGODB_USERNAME:MONGODB_PASSWORD@{database
          .config.deploy.name}:27017/1234
      </div>
      {#each database.envs as env}
        <div>{env.replace("=", ": ")}</div>
      {/each}
    {/if}
  {/await}
</div>
