<script>
  import { fetch } from "@store";
  import { params } from "@roxi/routify";

  $: db = $params.db;
  let showEnvs = false;
  let selectedDB;

  let visibleComponent = {
    overview: true,
    configuration: false,
  };
  function showPasswords() {
    showEnvs = !showEnvs
  }
  function showComponent(which) {
    if (visibleComponent.hasOwnProperty(which)) {
      visibleComponent = {
        overview: false,
        configuration: false,
      };
      visibleComponent[which] = true;
    }
  }

  async function deployDB() {
    await $fetch(`/api/v1/databases/new`, {
      body: {
        type: selectedDB,
      },
    });
  }

  async function loadDatabaseConfig() {
    return await $fetch(`/api/v1/databases/${$params.db}`);
  }
</script>

<div class="bg-coolgray-300 text-white">
  <nav
    class="mx-auto bg-coolgray-300 border-b-4 border-purple-500 text-white mb-3 sm:px-4"
  >
    <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
      <li>
        <button
          class="hover:text-purple-400 font-bold text-sm cursor-pointer"
          class:text-purple-400="{visibleComponent.overview && db !== 'new'}"
          class:cursor-default="{db === 'new'}"
          class:cursor-pointer="{db !== 'new'}"
          class:hover:text-purple-400="{db !== 'new'}"
          class:text-gray-600="{db === 'new'}"
          disabled="{db === 'new'}"
          on:click="{() => showComponent('overview')}"
        >
          Overview
        </button>
      </li>
      <li>
        <button
          class="font-bold text-sm"
          class:text-purple-400="{visibleComponent.configuration}"
          class:cursor-default="{db}"
          class:text-gray-600="{db}"
          disabled="{db}"
          on:click="{() => showComponent('configuration')}"
        >
          Configuration
        </button>
      </li>
      <li class="flex-1 hidden lg:flex"></li>
    </ul>
  </nav>
</div>
<div class="text-center">
  {#if db === "new"}
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
