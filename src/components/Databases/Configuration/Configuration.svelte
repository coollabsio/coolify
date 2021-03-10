<script>
  import { fetch } from "@store";
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
      toast.push("Database deployment queued.");
      $redirect(`/dashboard/databases`);
    } catch (error) {
      console.log(error);
    }
  }
</script>

<div
  class="text-center space-y-2 max-w-4xl md:mx-auto mx-6"
  in:fade="{{ duration: 100 }}"
>
  {#if $isActive("/database/new")}
    <div class="font-bold tracking-tighter text-xl">Select a database</div>
    <div class="flex justify-center space-x-4 font-bold tracking-tighter pb-6">
      <button
        class="button bg-gray-500 p-2 text-white hover:bg-green-500 cursor-pointer w-32"
        on:click="{() => (type = 'mongodb')}"
        class:bg-green-600="{type === 'mongodb'}"
      >
        MongoDB
      </button>
      <p
        class="button bg-gray-300 p-2 text-white  cursor-not-allowed w-32"
        disabled
        class:bg-blue-600="{type === 'postgresql'}"
      >
        PostgreSQL (soon)
      </p>
      <p
        class="button bg-gray-300 p-2 text-white  cursor-not-allowed w-32"
        disabled
        class:bg-blue-600="{type === 'postgresql'}"
      >
        Couchdb (soon)
      </p>
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
            placeholder="empty means randomly generated"
            bind:value="{defaultDatabaseName}"
          />
        </div>
        <button
          class:bg-green-600="{type === 'mongodb'}"
          class:hover:bg-green-500="{type === 'mongodb'}"
          class:bg-blue-600="{type === 'postgresql'}"
          class:hover:bg-blue-500="{type === 'postgresql'}"
          class="button p-2 w-32 text-white"
          on:click="{deploy}">Deploy</button
        >
      </div>
    {/if}
  {:else}
    <div class="text-xl font-bold tracking-tighter">
      Configuration will be here
    </div>
  {/if}
</div>
