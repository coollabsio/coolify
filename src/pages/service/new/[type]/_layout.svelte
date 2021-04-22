<script>
  import { params, goto, isActive, redirect, url } from "@roxi/routify";
  import { fetch, newService, initialNewService } from "@store";
  import { toast } from "@zerodevx/svelte-toast";
  import Tooltip from "../../../../components/Tooltip/Tooltip.svelte";
  import { onDestroy } from "svelte";
  import Loading from "../../../../components/Loading.svelte";
  $: type = $params.type;
  async function checkService() {
    try {
      await $fetch(`/api/v1/services/${type}`);
      $redirect(`/dashboard/services`);
      toast.push(
        `${
          type === "plausible" ? "Plausible Analytics" : type
        } already deployed.`,
      );
    } catch (error) {
      //
    }
  }
  onDestroy(() => {
    $newService = JSON.parse(JSON.stringify(initialNewService));
  });
</script>

{#await checkService()}
  <Loading />
{:then}
  <div class="text-white">
    <slot />
  </div>
{/await}
