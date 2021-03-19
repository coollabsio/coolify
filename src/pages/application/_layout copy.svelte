<script>
  import { params, goto, redirect, isActive } from "@roxi/routify";
  import { configuration, fetch, initialConfiguration, initConf } from "@store";
  import { onDestroy } from "svelte";
  import { fade } from "svelte/transition";
  import Loading from "../../components/Loading.svelte";
  import { toast } from "@zerodevx/svelte-toast";

  $configuration.repository.organization = $params.organization;
  $configuration.repository.name = $params.name;
  $configuration.repository.branch = $params.branch;

  // $: mod = JSON.stringify($initConf) !== JSON.stringify($configuration);

  let disabled = {
    deploy: false,
  };
  async function loadConfiguration() {
    if (!$isActive("/application/new")) {
      try {
        const config = await $fetch(`/api/v1/config`, {
          body: {
            name: $configuration.repository.name,
            organization: $configuration.repository.organization,
            branch: $configuration.repository.branch,
          },
        });
        $configuration = { ...config };
        $initConf = JSON.parse(JSON.stringify($configuration));
      } catch (error) {
        toast.push("Configuration not found.");
        $redirect("/dashboard/applications");
      }
    } else {
      $configuration = JSON.parse(JSON.stringify(initialConfiguration));
    }
  }

  async function removeApplication() {
    await $fetch(`/api/v1/application/remove`, {
      body: {
        organization: $params.organization,
        name: $params.name,
        branch: $params.branch,
      },
    });

    toast.push("Application removed.");
    $redirect(`/dashboard/applications`);
  }

  onDestroy(() => {
    $configuration = JSON.parse(JSON.stringify(initialConfiguration));
  });

  async function deploy() {
    disabled.deploy = true;
    try {
      toast.push("Checking some parameters.");
      const status = await $fetch(`/api/v1/application/check`, {
        body: $configuration,
      });
      const { nickname, name } = await $fetch(`/api/v1/application/deploy`, {
        body: $configuration,
      });
      $configuration.general.nickname = nickname;
      $configuration.build.container.name = name;
      $initConf = JSON.parse(JSON.stringify($configuration));
      toast.push("Application deployment queued.");

      $redirect(
        `/application/${$configuration.repository.organization}/${$configuration.repository.name}/${$configuration.repository.branch}/logs`,
      );
    } catch (error) {
      console.log(error);
      toast.push(error.error ? error.error : "Ooops something went wrong.");
    } finally {
      disabled.deploy = false;
    }
  }
</script>

<div class="bg-coolgray-300 text-white">
  <nav
    class="mx-auto bg-coolgray-300 border-b-4 border-green-500 text-white mb-3 sm:px-4"
  >
    {#if $isActive("/application/new")}
      <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
        <li>
          <button
            class="text-gray-600 font-bold text-sm cursor-not-allowed"
            disabled
          >
            Overview
          </button>
        </li>
        <li>
          <button class="text-green-400 font-bold text-sm cursor-pointer">
            Configuration
          </button>
        </li>
        <li>
          <button
            class="text-gray-600 font-bold text-sm cursor-not-allowed"
            disabled
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
            Deploy
          </button>
        </li>
      </ul>
    {:else}
      <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
        <li>
          <button
            class="hover:text-green-400 font-bold text-sm cursor-pointer"
            class:text-green-400="{$isActive(
              `/application/${$params.organization}/${$params.name}/${$params.branch}/overview`,
            )}"
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
            )}"
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
          {#if disabled.deploy}
            <button
              class="button px-4 py-1  cursor-not-allowed bg-gray-600 opacity-50"
              disabled
            >
              Remove
            </button>
          {:else}
            <button
              class="button px-4 py-1 cursor-pointer bg-red-500 hover:bg-red-400"
              on:click="{removeApplication}"
            >
              Remove
            </button>
          {/if}
        </li>
        <li>
          {#if disabled.deploy || $configuration.publish.domain === "" || $configuration.publish.domain === null}
            <button
              class="button px-4 py-1  cursor-not-allowed bg-gray-600 opacity-50"
              disabled
            >
              Redeploy
            </button>
          {:else}
            <button
              class="button px-4 py-1 cursor-pointer"
              class:bg-green-600="{$configuration.publish.domain}"
              class:hover:bg-green-500="{$configuration.publish.domain}"
              on:click="{deploy}"
            >
              Redeploy
            </button>
          {/if}
        </li>
      </ul>
    {/if}
  </nav>
</div>
{#await loadConfiguration()}
  <Loading />
{:then}
  <slot />
{/await}
