<script>
  import { params, goto, redirect } from "@roxi/routify";
  import {
    application,
    fetch,
    initialApplication,
    initConf,
    activePage,
  } from "@store";
  import { onDestroy } from "svelte";
  import { toast } from "@zerodevx/svelte-toast";
  import Tooltip from "../../components/Tooltip/Tooltip.svelte";

  $application.repository.organization = $params.organization;
  $application.repository.name = $params.name;
  $application.repository.branch = $params.branch;

  async function removeApplication() {
    await $fetch(`/api/v1/application/remove`, {
      body: {
        organization: $params.organization,
        name: $params.name,
        branch: $params.branch,
      },
    });

    toast.push("Application removed.");
    $application = JSON.parse(JSON.stringify(initialApplication));
    $redirect(`/dashboard/applications`);
  }

  onDestroy(() => {
    $application = JSON.parse(JSON.stringify(initialApplication));
  });

  async function deploy() {
    try {
      toast.push("Checking configuration.");
      await $fetch(`/api/v1/application/check`, {
        body: $application,
      });
      const { nickname, name, deployId } = await $fetch(
        `/api/v1/application/deploy`,
        {
          body: $application,
        },
      );
      $application.general.nickname = nickname;
      $application.build.container.name = name;
      $application.general.deployId = deployId;
      $initConf = JSON.parse(JSON.stringify($application));
      toast.push("Application deployment queued.");
      $redirect(
        `/application/${$application.repository.organization}/${$application.repository.name}/${$application.repository.branch}/logs/${$application.general.deployId}`,
      );
    } catch (error) {
      console.log(error);
      toast.push(error.error || error || "Ooops something went wrong.");
    }
  }
</script>

<nav
  class="flex text-white justify-end items-center m-4 fixed right-0 top-0 space-x-4 z-50"
>
  <Tooltip position="bottom" label="Deploy">
    <button
      disabled="{$application.publish.domain === '' ||
        $application.publish.domain === null}"
      class:cursor-not-allowed="{$application.publish.domain === '' ||
        $application.publish.domain === null}"
      class:hover:bg-green-500="{$application.publish.domain}"
      class:bg-green-600="{$application.publish.domain}"
      class:hover:bg-transparent="{$activePage.new}"
      class:text-warmGray-700="{$application.publish.domain === '' ||
        $application.publish.domain === null}"
      class="icon"
      on:click="{deploy}"
    >
      <svg
        class="w-6"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        ><polyline points="16 16 12 12 8 16"></polyline><line
          x1="12"
          y1="12"
          x2="12"
          y2="21"></line><path
          d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"
        ></path><polyline points="16 16 12 12 8 16"></polyline></svg
      >
    </button>
  </Tooltip>
  <Tooltip position="bottom" label="Delete">
    <button
      disabled="{$application.publish.domain === '' ||
        $application.publish.domain === null ||
        $activePage.new}"
      class:cursor-not-allowed="{$application.publish.domain === '' ||
        $application.publish.domain === null ||
        $activePage.new}"
      class:hover:text-red-500="{$application.publish.domain &&
        !$activePage.new}"
      class:hover:bg-warmGray-700="{$application.publish.domain &&
        !$activePage.new}"
      class:hover:bg-transparent="{$activePage.new}"
      class:text-warmGray-700="{$application.publish.domain === '' ||
        $application.publish.domain === null ||
        $activePage.new}"
      class="icon"
      on:click="{removeApplication}"
    >
      <svg
        class="w-6"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
        ></path>
      </svg>
    </button>
  </Tooltip>
  <div class="border border-warmGray-700 h-8"></div>
  <Tooltip position="bottom" label="Logs">
    <button
      class="icon"
      class:text-warmGray-700="{$activePage.new}"
      disabled="{$activePage.new}"
      class:hover:text-blue-400="{!$activePage.new}"
      class:hover:bg-transparent="{$activePage.new}"
      class:cursor-not-allowed="{$activePage.new}"
      class:text-blue-400="{$activePage.application === 'logs'}"
      class:bg-warmGray-700="{$activePage.application === 'logs'}"
      on:click="{() =>
        $goto(
          `/application/${$application.repository.organization}/${$application.repository.name}/${$application.repository.branch}/logs`,
        )}"
    >
      <svg
        class="w-6"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
        ></path>
      </svg>
    </button>
  </Tooltip>
  <Tooltip position="bottom-left" label="Configuration">
    <button
      class="icon hover:text-yellow-400"
      disabled="{$activePage.new}"
      class:text-yellow-400="{$activePage.application === 'configuration' ||
        $activePage.new}"
      class:bg-warmGray-700="{$activePage.application === 'configuration' ||
        $activePage.new}"
      on:click="{() =>
        $goto(
          `/application/${$application.repository.organization}/${$application.repository.name}/${$application.repository.branch}/configuration`,
        )}"
    >
      <svg
        class="w-6"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
        ></path>
      </svg>
    </button>
  </Tooltip>
</nav>
