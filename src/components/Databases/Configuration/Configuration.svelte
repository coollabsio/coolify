<script>
  import { fetch, dbInprogress } from "@store";
  import { isActive, redirect } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";
  import { toast } from "@zerodevx/svelte-toast";

  let type;
  let defaultDatabaseName;

  async function deploy() {
    try {
      await $fetch(`/api/v1/databases/deploy`, {
        body: {
          type,
          defaultDatabaseName,
        },
      });
      $dbInprogress = true
      toast.push("Database deployment queued.");
      $redirect(`/dashboard/databases`);
    } catch (error) {
      console.log(error);
    }
  }
</script>

<div
  class="text-center space-y-2 max-w-4xl mx-auto px-6"
  in:fade="{{ duration: 100 }}"
>
  {#if $isActive("/database/new")}
    <div class="flex justify-center space-x-4 font-bold pb-6">
      <button
        class="button bg-gray-500 p-2 text-white hover:bg-green-600 cursor-pointer w-32"
        on:click="{() => (type = 'mongodb')}"
        class:bg-green-600="{type === 'mongodb'}"
      >
        MongoDB
      </button>
      <button
        class="button bg-gray-500 p-2 text-white hover:bg-blue-600 cursor-pointer w-32"
        on:click="{() => (type = 'postgresql')}"
        class:bg-blue-600="{type === 'postgresql'}"
      >
        PostgreSQL
      </button>
      <button
        class="button bg-gray-500 p-2 text-white hover:bg-orange-600 cursor-pointer w-32"
        on:click="{() => (type = 'mysql')}"
        class:bg-orange-600="{type === 'mysql'}"
      >
        MySQL
      </button>
      <button
      class="button bg-gray-500 p-2 text-white hover:bg-red-600 cursor-pointer w-32"
      on:click="{() => (type = 'couchdb')}"
      class:bg-red-600="{type === 'couchdb'}"
      >
        Couchdb
      </button>
      <!-- <button
      class="button bg-gray-500 p-2 text-white hover:bg-yellow-500 cursor-pointer w-32"
      on:click="{() => (type = 'clickhouse')}"
      class:bg-yellow-500="{type === 'clickhouse'}"
    >
      Clickhouse
    </button> -->
    </div>
    {#if type}
      <div>
        <div
          class="grid grid-rows-1 justify-center items-center text-center pb-5"
        >
          <label for="defaultDB">Default database</label>
          <input
            id="defaultDB"
            class="w-64"
            placeholder="random"
            bind:value="{defaultDatabaseName}"
          />
        </div>
        <button
          class:bg-green-600="{type === 'mongodb'}"
          class:hover:bg-green-500="{type === 'mongodb'}"
          class:bg-blue-600="{type === 'postgresql'}"
          class:hover:bg-blue-500="{type === 'postgresql'}"
          class:bg-orange-600="{type === 'mysql'}"
          class:hover:bg-orange-500="{type === 'mysql'}"
          class:bg-red-600="{type === 'couchdb'}"
          class:hover:bg-red-500="{type === 'couchdb'}"
          class:bg-yellow-500="{type === 'clickhouse'}"
          class:hover:bg-yellow-400="{type === 'clickhouse'}"
          class="button p-2 w-32 text-white"
          on:click="{deploy}">Deploy</button
        >
      </div>
    {/if}
  {/if}
</div>
