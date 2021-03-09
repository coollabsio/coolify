<script>
  import { fetch } from "@store";
  import { isActive, redirect } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";

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
    <div class="flex justify-center space-x-4 font-bold tracking-tighter">
      <p
        class="hover:text-green-600 cursor-pointer"
        on:click="{() => (type = 'mongodb')}"
        class:text-green-600="{type === 'mongodb'}"
      >
        MongoDB
      </p>
      <p
        class="hover:text-blue-500 cursor-pointer"
        on:click="{() => (type = 'postgresql')}"
        class:text-blue-500="{type === 'postgresql'}"
      >
        PostgreSQL
      </p>
    </div>
    {#if type}
      <div class="flex flex-col justify-center items-center">
        <div class="pb-4">
          <label for="baseDir">Default Database</label>
          <input
            id="baseDir"
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
    <div class="text-xl font-bold tracking-tighter">Configuration will be here</div>
  {/if}
</div>
