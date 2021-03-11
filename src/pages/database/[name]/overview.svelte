<script>
  import { fetch } from "@store";
  import { redirect, params } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";
  import Loading from "../../../components/Loading.svelte";
  import { toast } from "@zerodevx/svelte-toast";

  $: name = $params.name;

  async function loadDatabaseConfig() {
    try {
      return await $fetch(`/api/v1/databases/${name}`);
    } catch (error) {
      toast.push(`Cannot find database ${name}`);
      $redirect(`/dashboard/databases`);
    }
  }
</script>

<div
  class=" space-y-2 max-w-4xl md:mx-auto mx-6 tracking-tighter"
  in:fade="{{ duration: 100 }}"
>
  {#await loadDatabaseConfig()}
    <Loading />
  {:then database}
    <div in:fade="{{ duration: 100 }}">
      <div class="font-bold text-xl text-center">
        {database.config.general.nickname}
      </div>
    </div>

    <div in:fade="{{ duration: 100 }}">
      <div class="pb-2 pt-5">
        <div class="flex items-center">
          <div class="font-bold w-48">Connection string</div>
          <div class="text-sm">
            mongodb://{database.envs.MONGODB_USERNAME}:{database.envs
              .MONGODB_PASSWORD}@{database.config.general
              .deployId}:27017/{database.envs.MONGODB_DATABASE}
          </div>
        </div>
      </div>
      <div class="flex items-center">
        <div class="font-bold w-48">Root password</div>
        <div class="text-sm">{database.envs.MONGODB_ROOT_PASSWORD}</div>
      </div>
    </div>
  {/await}
</div>
