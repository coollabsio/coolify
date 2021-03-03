<script>
  import { params, redirect } from "@roxi/routify";
  import { configuration, fetch, initialConfiguration } from "@store";
  import General from "./ActiveTab/General.svelte";
  import BuildStep from "./ActiveTab/BuildStep.svelte";
  import Secrets from "./ActiveTab/Secrets.svelte";
  import { onMount } from "svelte";

  onMount(async () => {
    if ($params.organization !== "new" && $params.name !== "start") {
      const config = await $fetch(`/api/v1/config`, {
        body: {
          name: $configuration.repository.name,
          organization: $configuration.repository.organization,
          branch: $configuration.repository.branch,
        },
      });
      $configuration = { ...config };
      $redirect(`/application/:organization/:name/:branch/configuration`, {
        name: $configuration.repository.name,
        organization: $configuration.repository.organization,
        branch: $configuration.repository.branch,
      });
    }
  });
  let activeTab = {
    general: true,
    buildStep: false,
    secrets: false,
  };
  function activateTab(tab) {
    if (activeTab.hasOwnProperty(tab)) {
      activeTab = {
        general: false,
        buildStep: false,
        secrets: false,
      };
      activeTab[tab] = true;
    }
  }
</script>

<div class="block text-center py-4">
  <nav
    class="flex space-x-4 justify-center font-bold tracking-tight text-sm text-black"
    aria-label="Tabs"
  >
    <div
      on:click="{() => activateTab('general')}"
      class:text-green-500="{activeTab.general}"
      class="px-3 py-2 cursor-pointer hover:text-green-500"
    >
      General
    </div>
    <div
      on:click="{() => activateTab('buildStep')}"
      class:text-green-500="{activeTab.buildStep}"
      class="px-3 py-2 cursor-pointer hover:text-green-500"
    >
      Build Step
    </div>
    <div
      on:click="{() => activateTab('secrets')}"
      class:text-green-500="{activeTab.secrets}"
      class="px-3 py-2 cursor-pointer hover:text-green-500"
    >
      Secrets
    </div>
  </nav>
</div>
<div class="max-w-4xl mx-auto">
  <div class="h-full">
    {#if activeTab.general}
      <General />
    {:else if activeTab.buildStep}
      <BuildStep />
    {:else if activeTab.secrets}
      <Secrets />
    {/if}
  </div>
</div>
