<script>
  import { fetch } from "@store";
  import { redirect, params } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";
  import { toast } from "@zerodevx/svelte-toast";

  import Loading from "../../../components/Loading.svelte";
  import Plausible from "../../../components/Services/Plausible.svelte";

  $: name = $params.name;
  let service = {};
  async function loadServiceConfig() {
    if (name) {
      try {
        service = await $fetch(`/api/v1/services/${name}`);
      } catch (error) {
        toast.push(`Cannot find service ${name}?!`);
        $redirect(`/dashboard/services`);
      }
    }
  }
  async function activate() {
    try {
      await $fetch(`/api/v1/services/deploy/${name}/activate`, {
        method: "PATCH",
        body: {},
      });
      toast.push(`All users are activated for Plausible.`);
    } catch (error) {
      console.log(error);
      toast.push(`Ooops, there was an error activating users for Plausible?!`);
    }
  }
</script>

{#await loadServiceConfig()}
  <Loading />
{:then}
  <div class="min-h-full text-white">
    <div
      class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
    >
      <a
        href="{service.config.baseURL}"
        target="_blank"
        class="inline-flex hover:underline cursor-pointer px-2"
      >
        <div>{name === "plausible" ? "Plausible Analytics" : name}</div>
        <div class="px-4">
          {#if name === "plausible"}
            <img
              alt="plausible logo"
              class="w-6 mx-auto"
              src="https://cdn.coollabs.io/assets/coolify/services/plausible/logo_sm.png"
            />
          {/if}
        </div>
      </a>
    </div>
  </div>
  <div class="space-y-2 max-w-4xl mx-auto px-6" in:fade="{{ duration: 100 }}">
    <div class="block text-center py-4">
      {#if name === "plausible"}
        <Plausible service="{service}" />
      {/if}
    </div>
  </div>
{/await}
