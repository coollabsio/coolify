<script>
  import { fetch } from "@store";
  import { redirect, params } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";
  import Loading from "../../../components/Loading.svelte";
  import { toast } from "@zerodevx/svelte-toast";

  let showEnvs = false;
  $: name = $params.name;

  async function loadDatabaseConfig() {
    try {
      return await $fetch(`/api/v1/databases/${name}`);
    } catch (error) {
      toast.push(`Cannot find database ${name}`);
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
  {#await loadDatabaseConfig()}
    <Loading />
  {:then database}
    <div in:fade="{{ duration: 100 }}">
      <div class="font-bold text-xl">{database.config.general.nickname}</div>
      <button
        class="button bg-purple-600 hover:bg-purple-500 text-white p-1 tracking-tighter"
        on:click="{showPasswords}">Show connection info</button
      >
    </div>
    {#if showEnvs}
      <div in:fade="{{ duration: 100 }}">
        <div class="text-sm pb-2 pt-5">
          <div class="font-bold">
            mongodb://{database.envs.MONGODB_USERNAME}:{database.envs
              .MONGODB_PASSWORD}@{database.config.general
              .deployId}:27017/{database.envs.MONGODB_DATABASE}
          </div>
        </div>
        <div class="text-xs">
          Root password : {database.envs.MONGODB_ROOT_PASSWORD}
        </div>
      </div>
    {/if}
  {/await}
</div>
