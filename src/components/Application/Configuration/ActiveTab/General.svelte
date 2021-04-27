<style lang="postcss">
  .buildpack {
    @apply px-6 py-2 mx-2 my-2 bg-warmGray-800 w-48 ease-in-out transform hover:scale-105 text-center rounded border-2 border-transparent border-dashed cursor-pointer transition duration-100;
  }
</style>

<script>
  import { application } from "@store";
  import TooltipInfo from "../../../Tooltip/TooltipInfo.svelte";
  const showPorts = ["nodejs", "docker", "rust", "nuxtjs", "nextjs"];
  function selectBuildPack(event) {
    if (event.target.innerText === 'React/Preact') {
      $application.build.pack = 'react'
    } else {
      $application.build.pack = event.target.innerText.replace(/\./g,'').toLowerCase()
    }
   
  }

</script>

<div>
  <div
    class="grid grid-cols-1 text-sm max-w-4xl md:mx-auto mx-6 pb-6 auto-cols-max "
  >
    <label for="buildPack" class="pl-5"
      >Build Packs
      <!-- {#if $application.build.pack === 'custom'}
      <TooltipInfo
        label="Your custom Dockerfile will be used from the root directory (or from 'Base Directory' specified below) of your repository. "
      />
      {:else if $application.build.pack === 'static'}
      <TooltipInfo
        label="Published as a static site (for build phase see 'Build Step' tab)."
      />
      {:else if $application.build.pack === 'nodejs'}
      <TooltipInfo
        label="Published as a Node.js application (for build phase see 'Build Step' tab)."
      />
      {:else if $application.build.pack === 'php'}
      <TooltipInfo
      size="large"
        label="Published as a PHP application."
      />
      {:else if $application.build.pack === 'rust'}
      <TooltipInfo
      size="large"
        label="Published as a Rust application."
      />
      {/if} -->
    </label>
    <div class="flex font-bold flex-wrap justify-center">
      <div class={$application.build.pack === 'static' ? 'buildpack bg-red-500' : 'buildpack hover:border-red-500'} on:click={selectBuildPack}>Static</div>
      <div class={$application.build.pack === 'nodejs' ? 'buildpack bg-emerald-600' : 'buildpack hover:border-emerald-600'} on:click={selectBuildPack}>NodeJS</div>
      <div class={$application.build.pack === 'vuejs' ? 'buildpack bg-green-500' : 'buildpack hover:border-green-500'} on:click={selectBuildPack}>VueJS</div>
      <div class={$application.build.pack === 'nuxtjs' ? 'buildpack bg-green-500' : 'buildpack hover:border-green-500'} on:click={selectBuildPack}>NuxtJS</div>
      <div class={$application.build.pack === 'react' ? 'buildpack bg-gradient-to-r from-blue-500 to-purple-500' : 'buildpack hover:border-blue-500'} on:click={selectBuildPack}>React/Preact</div>
      <div class={$application.build.pack === 'nextjs' ? 'buildpack bg-blue-500' : 'buildpack hover:border-blue-500'} on:click={selectBuildPack}>NextJS</div>
      <div class={$application.build.pack === 'gatsby' ? 'buildpack bg-blue-500' : 'buildpack hover:border-blue-500'} on:click={selectBuildPack}>Gatsby</div>
      <div class={$application.build.pack === 'svelte' ? 'buildpack bg-orange-600' : 'buildpack hover:border-orange-600'} on:click={selectBuildPack}>Svelte</div>
      <div class={$application.build.pack === 'php' ? 'buildpack bg-indigo-500' : 'buildpack hover:border-indigo-500'} on:click={selectBuildPack}>PHP</div>
      <div class={$application.build.pack === 'rust' ? 'buildpack bg-pink-500' : 'buildpack hover:border-pink-500'} on:click={selectBuildPack}>Rust</div>
      <div class={$application.build.pack === 'docker' ? 'buildpack bg-purple-500' : 'buildpack hover:border-purple-500'} on:click={selectBuildPack}>Docker</div>
    </div>
    <!-- <select id="buildPack" bind:value="{$application.build.pack}">
      <option selected class="font-bold">static</option>
      <option class="font-bold">nodejs</option>
      <option class="font-bold">php</option>
      <option class="font-bold">custom</option>
      <option class="font-bold">rust</option>
    </select> -->
  </div>
  <div
    class="grid grid-cols-1 max-w-2xl md:mx-auto mx-6 justify-center items-center"
  >
    <div class="grid grid-flow-col gap-2 items-center pb-6">
      <div class="grid grid-flow-row">
        <label for="Domain" class="">Domain</label>
        <input
          class:placeholder-red-500="{$application.publish.domain == null ||
            $application.publish.domain == ''}"
          class:border-red-500="{$application.publish.domain == null ||
            $application.publish.domain == ''}"
          id="Domain"
          bind:value="{$application.publish.domain}"
          placeholder="eg: coollabs.io (without www)"
        />
      </div>
      <div class="grid grid-flow-row">
        <label for="Path"
          >Path <TooltipInfo
            label="{`Path to deploy your application on your domain. eg: /api means it will be deployed to -> https://${
              $application.publish.domain || '<yourdomain>'
            }/api`}"
          /></label
        >
        <input
          id="Path"
          bind:value="{$application.publish.path}"
          placeholder="/"
        />
      </div>
    </div>
    {#if showPorts.includes($application.build.pack)}
      <label for="Port">Port</label>
      <input
        id="Port"
        class="mb-6"
        bind:value="{$application.publish.port}"
        placeholder="{$application.build.pack === 'static' ? '80' : '3000'}"
      />
    {/if}
    <div class="grid grid-flow-col gap-2 items-center pt-12">
      <div class="grid grid-flow-row">
        <label for="baseDir"
          >Base Directory <TooltipInfo
            label="The directory to use as base for every command (could be useful if you have a monorepo)."
          /></label
        >
        <input
          id="baseDir"
          bind:value="{$application.build.directory}"
          placeholder="eg: sourcedir"
        />
      </div>
      <div class="grid grid-flow-row">
        <label for="publishDir"
          >Publish Directory <TooltipInfo
            label="The directory to deploy after running the build command.  eg: dist, _site, public."
          /></label
        >
        <input
          id="publishDir"
          bind:value="{$application.publish.directory}"
          placeholder="eg: dist, _site, public"
        />
      </div>
    </div>
  </div>
</div>
