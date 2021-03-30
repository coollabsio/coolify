<script>
  import { application } from "@store";
</script>

<div>
    <div
      class="grid grid-cols-1 text-sm space-y-2 max-w-2xl md:mx-auto mx-6 pb-6 auto-cols-max"
    >
      <label for="buildPack">Build Pack</label>
      <select id="buildPack" bind:value="{$application.build.pack}">
        <option selected class="font-bold">Static</option>
        <option class="font-bold">Node.js</option>
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
    </div>
  </div>