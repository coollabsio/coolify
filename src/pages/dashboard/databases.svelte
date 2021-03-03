<script>
  import { deployments } from "@store";
  import { goto } from "@roxi/routify/runtime";
</script>

{#if $deployments.databases?.deployed.length > 0}
  <div class="max-w-4xl mx-auto px-2 lg:px-0">
    {#each $deployments.databases.deployed as database}
      <div
        class="hover:bg-purple-700 rounded transition-all hover:text-white duration-100 cursor-pointer flex justify-center items-center px-2"
        on:click="{() =>
          $goto(`/database/${database.Spec.Labels.config.general.name}/overview`)}"
      >
        <div
          class="flex py-4 mx-auto w-full justify-center items-center space-x-2"
        >
          <div>
            {database.Spec.Labels.config.general.type} - {database.Spec.Labels
              .config.general.name}
          </div>

          <div class="flex-1"></div>
        </div>
        <div class="inline-flex"></div>
      </div>
    {/each}
  </div>
{:else}
  <div class="text-center font-bold tracking-tight">No databases found</div>
{/if}
