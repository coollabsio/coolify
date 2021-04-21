<script>
  import { redirect, params } from "@roxi/routify/runtime";
  import { newService } from "@store";
  import { fade } from "svelte/transition";
  import TooltipInfo from "../../../../components/Tooltip/TooltipInfo.svelte";
  $: type = $params.type;
</script>

<div class="min-h-full text-white">
  <div class="py-5 text-left px-6 text-3xl tracking-tight font-bold">
    New
    {#if type === "plausible"}
      <span class="text-blue-500 px-2 capitalize">Plausible Analytics</span>
    {/if}
    service
  </div>
</div>
<div
  class="space-y-2 max-w-4xl mx-auto px-6 flex-col text-center"
  in:fade="{{ duration: 100 }}"
>
  <div class="grid grid-flow-row">
    <label for="Domain"
      >Domain <TooltipInfo
        position="right"
        label="{`You will have your Plausible instance at here.`}"
      /></label
    >
    <input
      id="Domain"
      class:border-red-500="{$newService.baseURL == null ||
        $newService.baseURL == ''}"
      bind:value="{$newService.baseURL}"
      placeholder="analytics.coollabs.io"
    />
  </div>
  <div class="grid grid-flow-row">
    <label for="Email">Email</label>
    <input
      id="Email"
      class:border-red-500="{$newService.email == null ||
        $newService.email == ''}"
      bind:value="{$newService.email}"
      placeholder="hi@coollabs.io"
    />
  </div>
  <div class="grid grid-flow-row">
    <label for="Username">Username </label>
    <input
      id="Username"
      class:border-red-500="{$newService.userName == null ||
        $newService.userName == ''}"
      bind:value="{$newService.userName}"
      placeholder="admin"
    />
  </div>
  <div class="grid grid-flow-row">
    <label for="Password"
      >Password <TooltipInfo
        position="right"
        label="{`Must be at least 7 characters.`}"
      /></label
    >
    <input
      id="Password"
      type="password"
      class:border-red-500="{$newService.userPassword == null ||
        $newService.userPassword == '' || $newService.userPassword.length <= 6}"
      bind:value="{$newService.userPassword}"
    />
  </div>
  <div class="grid grid-flow-row">
    <label for="PasswordAgain">Password again </label>
    <input
      id="PasswordAgain"
      type="password"
      class:placeholder-red-500="{$newService.userPassword !==
        $newService.userPasswordAgain}"
      class:border-red-500="{$newService.userPassword !==
        $newService.userPasswordAgain}"
      bind:value="{$newService.userPasswordAgain}"
    />
  </div>
</div>
