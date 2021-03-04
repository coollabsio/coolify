<script>
  import { params, goto, redirect, isActive } from "@roxi/routify";
  import { configuration, fetch, initialConfiguration } from "@store";
  import { onDestroy, onMount } from "svelte";
  import Loading from "../../../../../components/Loading.svelte";

  $configuration.repository.organization = $params.organization;
  $configuration.repository.name = $params.name;
  $configuration.repository.branch = $params.branch;

  $: newApplication =
    $params.organization === "new" &&
    $params.name === "start" &&
    $params.branch === "main";

  async function loadConfiguration() {
    if ($params.organization !== "new" && $params.name !== "start") {
      try {
        const config = await $fetch(`/api/v1/config`, {
          body: {
            name: $configuration.repository.name,
            organization: $configuration.repository.organization,
            branch: $configuration.repository.branch,
          },
        });
        $configuration = { ...config };
      } catch (error) {
        $redirect("/dashboard/applications");
      }
    } else {
      $configuration = JSON.parse(JSON.stringify(initialConfiguration));
    }
  }
  onMount(async () => {
    if (newApplication) {
      $redirect(
        `/application/${$configuration.repository.organization}/${$configuration.repository.name}/${$configuration.repository.branch}/configuration`,
      );
    }
  });

  onDestroy(() => {
    $configuration = JSON.parse(JSON.stringify(initialConfiguration));
  });

  async function deploy() {
    await $fetch(`/api/v1/application/deploy`, { body: $configuration });
  }
</script>

<div class="bg-coolgray-300 text-white">
  <nav
    class="mx-auto bg-coolgray-300 border-b-4 border-green-500 text-white mb-3 sm:px-4"
  >
    <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
      <li>
        <button
          class="font-bold text-sm text-gray-600 cursor-not-allowed"
          disabled
          on:click="{() =>
            $goto(
              `/application/${$params.organization}/${$params.name}/${$params.branch}/overview`,
            )}"
        >
          Overview
        </button>
      </li>
      <li>
        <button
          class="hover:text-green-400 font-bold text-sm cursor-pointer"
          class:text-green-400="{$isActive(
            `/application/${$params.organization}/${$params.name}/${$params.branch}/configuration`,
          )}"
          on:click="{() =>
            $goto(
              `/application/${$params.organization}/${$params.name}/${$params.branch}/configuration`,
            )}"
        >
          Configuration
        </button>
      </li>
      <li>
        <button
          class="hover:text-green-400 font-bold text-sm cursor-pointer"
          class:text-green-400="{$isActive(
            `/application/${$params.organization}/${$params.name}/${$params.branch}/logs`,
          ) && !newApplication}"
          class:cursor-not-allowed="{newApplication}"
          class:cursor-pointer="{!newApplication}"
          class:hover:text-green-400="{!newApplication}"
          class:text-gray-600="{newApplication}"
          disabled="{newApplication}"
          on:click="{() =>
            $goto(
              `/application/${$params.organization}/${$params.name}/${$params.branch}/logs`,
            )}"
        >
          Logs
        </button>
      </li>
      <li class="flex-1 hidden lg:flex"></li>
      <li>
        <button
          class="button px-4 py-1 cursor-pointer"
          class:cursor-not-allowed="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          class:bg-gray-600="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          class:bg-green-600="{$configuration.publish.domain}"
          class:hover:bg-green-500="{$configuration.publish.domain}"
          class:opacity-50="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          disabled="{$configuration.publish.domain === '' ||
            $configuration.publish.domain === null}"
          on:click="{deploy}"
        >
          Publish application
        </button>
      </li>
    </ul>
  </nav>
</div>
{#await loadConfiguration()}
  <Loading />
{:then}
  <slot />
{/await}
