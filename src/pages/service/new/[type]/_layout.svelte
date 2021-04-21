<script>
  import { params, goto, isActive, redirect, url } from "@roxi/routify";
  import { fetch, newService, initialNewService } from "@store";
  import { toast } from "@zerodevx/svelte-toast";
  import Tooltip from "../../../../components/Tooltip/Tooltip.svelte";
  import { onDestroy } from "svelte";
  $: type = $params.type
  $: deployable =
    $newService.baseURL === "" ||
    $newService.baseURL === null ||
    $newService.email === "" ||
    $newService.email === null ||
    $newService.userName === "" ||
    $newService.userName === null ||
    $newService.userPassword === "" ||
    $newService.userPassword === null ||
    $newService.userPassword.length <= 6 ||
    $newService.userPassword !== $newService.userPasswordAgain;

  onDestroy(() => {
    $newService = JSON.parse(JSON.stringify(initialNewService));
  });
  async function deploy() {
    const payload = $newService
    delete payload.userPasswordAgain
    console.log(payload);
    await $fetch(`/api/v1/services/deploy/${type}`, {
      body: payload,
    });
    toast.push("Service deployment queued.");
    $redirect(`/dashboard/services`);
  }
</script>

<nav
  class="flex text-white justify-end items-center m-4 fixed right-0 top-0 space-x-4"
>
  <Tooltip position="bottom" label="Deploy">
    <button
      disabled="{deployable}"
      class:cursor-not-allowed="{deployable}"
      class:hover:text-green-500="{!deployable}"
      class:hover:bg-warmGray-700="{!deployable}"
      class:hover:bg-transparent="{$isActive('/service/new')}"
      class:text-warmGray-700="{deployable}"
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
</nav>

<div class="text-white">
  <slot />
</div>
