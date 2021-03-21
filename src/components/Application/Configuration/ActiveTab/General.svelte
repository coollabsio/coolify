<script>
  import { application } from "@store";
</script>

<div>
    <div
      class="grid grid-cols-1 text-sm space-y-2 max-w-2xl md:mx-auto mx-6 pb-6 auto-cols-max"
    >
      <label for="buildPack">Build Pack</label>
      <select id="buildPack" bind:value="{$application.build.pack}">
        <option selected class="font-medium">static</option>
        <option class="font-medium">nodejs</option>
      </select>
    </div>
    <div
      class="grid grid-cols-2 space-y-2 max-w-2xl md:mx-auto mx-6 justify-center items-center"
    >
      <label for="Domain">Domain</label>
      <input
        class:placeholder-red-500="{$application.publish.domain == null || $application.publish.domain == ''}"
        class:border-red-500="{$application.publish.domain == null || $application.publish.domain == ''}"
        id="Domain"
        bind:value="{$application.publish.domain}"
        placeholder="eg: coollabs.io (without www)"
      />
      <label for="Path">Path Prefix</label>
      <input
        id="Path"
        bind:value="{$application.publish.path}"
        placeholder="/"
      />
      <label for="publishDir">Publish Directory</label>
      <input
        id="publishDir"
        bind:value="{$application.publish.directory}"
        placeholder="/"
      />
      {#if $application.build.pack !== "static"}
        <label for="Port">Port</label>
        <input
          id="Port"
          bind:value="{$application.publish.port}"
          placeholder="{$application.build.pack === 'static'
            ? '80'
            : '3000'}"
        />
      {/if}
      <!-- {#if config.buildPack === "static"}
        <div class="text-base font-bold text-white pt-2">
          Preview Deploys
        </div>
        <button
          type="button"
          on:click="{() =>
            (config.previewDeploy = !config.previewDeploy)}"
          aria-pressed="false"
          class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-black"
          class:bg-green-600="{config.previewDeploy}"
          class:bg-coolgray-300="{!config.previewDeploy}"
        >
          <span class="sr-only">Use setting</span>
          <span
            class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transform ring-0 transition ease-in-out duration-200"
            class:translate-x-5="{config.previewDeploy}"
            class:translate-x-0="{!config.previewDeploy}"
          >
            <span
              class="ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
              class:opacity-0="{config.previewDeploy}"
              class:opacity-100="{!config.previewDeploy}"
              aria-hidden="true"
            >
              <svg
                class="bg-white h-3 w-3 text-red-600"
                fill="none"
                viewBox="0 0 12 12"
              >
                <path
                  d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"></path>
              </svg>
            </span>
            <span
              class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
              aria-hidden="true"
              class:opacity-100="{config.previewDeploy}"
              class:opacity-0="{!config.previewDeploy}"
            >
              <svg
                class="bg-white h-3 w-3 text-green-600"
                fill="currentColor"
                viewBox="0 0 12 12"
              >
                <path
                  d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
                ></path>
              </svg>
            </span>
          </span>
        </button>
      {/if} -->
    </div>
  </div>