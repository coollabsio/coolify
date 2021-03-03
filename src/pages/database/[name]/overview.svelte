<script>
  import { fetch } from "@store";
  import { params } from "@roxi/routify";

  $: name = $params.name;
  let showEnvs = false;
  let selectedDB;

  let visibleComponent = {
    overview: true,
    configuration: false,
  };
  function showPasswords() {
    showEnvs = !showEnvs
  }

  async function deployDB() {
    await $fetch(`/api/v1/databases/new`, {
      body: {
        type: selectedDB,
      },
    });
  }

  async function loadDatabaseConfig() {
    return await $fetch(`/api/v1/databases/${name}`);
  }
</script>
<div class="text-center">
  {#if name === "new"}
    <div class="font-bold pb-4">Choose database</div>
    <div class="flex justify-center space-x-4">
      <p
        on:click="{() => (selectedDB = 'mongodb')}"
        class:underline="{selectedDB === 'mongodb'}"
      >
        MongoDB
      </p>
      <p
        on:click="{() => (selectedDB = 'postgresql')}"
        class:underline="{selectedDB === 'postgresql'}"
      >
        PostgreSQL
      </p>
    </div>
    {#if selectedDB}
      <div on:click="{deployDB}">Deploy</div>
    {/if}
  {:else if visibleComponent.overview}
    {#await loadDatabaseConfig() then database}
      <div>Name: {database.config.general.name}</div>
      
      <button on:click="{showPasswords}">Show Passwords</button>
      {#if showEnvs}
      <div>Connection URI: mongodb://MONGODB_USERNAME:MONGODB_PASSWORD@{database.config.deploy.name}:27017/1234</div>
        {#each database.envs as env}
          <div>{env.replace('=',': ')}</div>
        {/each}
      {/if}
    {/await}
  {/if}
</div>