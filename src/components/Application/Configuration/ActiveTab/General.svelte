<style lang="postcss">
  .buildpack {
    @apply px-6 py-2 mx-2 my-2 bg-warmGray-800 w-48 ease-in-out transform hover:scale-105 text-center rounded border-2 border-transparent border-dashed cursor-pointer transition duration-100;
  }
</style>

<script>
  import { application } from "@store";
  import TooltipInfo from "../../../Tooltip/TooltipInfo.svelte";
  const buildpacks = {
    static: {
      port: {
        active: false,
        number: 80,
      },
      build: true,
    },
    nodejs: {
      port: {
        active: true,
        number: 3000,
      },
      build: true,
    },
    vuejs: {
      port: {
        active: false,
        number: 80,
      },
      build: true,
    },
    nuxtjs: {
      port: {
        active: true,
        number: 3000,
      },
      build: true,
    },
    react: {
      port: {
        active: false,
        number: 80,
      },
      build: true,
    },
    nextjs: {
      port: {
        active: true,
        number: 3000,
      },
      build: true,
    },
    gatsby: {
      port: {
        active: true,
        number: 3000,
      },
      build: true,
    },
    svelte: {
      port: {
        active: false,
        number: 80,
      },
      build: true,
    },
    php: {
      port: {
        active: false,
        number: 80,
      },
      build: false,
    },
    rust: {
      port: {
        active: true,
        number: 3000,
      },
      build: false,
    },
    docker: {
      port: {
        active: true,
        number: 3000,
      },
      build: false,
    },
  };
  function selectBuildPack(event) {
    if (event.target.innerText === "React/Preact") {
      $application.build.pack = "react";
    } else {
      $application.build.pack = event.target.innerText
        .replace(/\./g, "")
        .toLowerCase();
    }
  }
</script>

<div>
  <div
    class="grid grid-cols-1 text-sm max-w-4xl md:mx-auto mx-6 pb-16 auto-cols-max "
  >
    <div class="text-2xl font-bold border-gradient w-40">Build Packs</div>
    <div class="flex font-bold flex-wrap justify-center pt-10">
      <div
        class="{$application.build.pack === 'static'
          ? 'buildpack bg-red-500'
          : 'buildpack hover:border-red-500'}"
        on:click="{selectBuildPack}"
      >
        Static
      </div>
      <div
        class="{$application.build.pack === 'nodejs'
          ? 'buildpack bg-emerald-600'
          : 'buildpack hover:border-emerald-600'}"
        on:click="{selectBuildPack}"
      >
        NodeJS
      </div>
      <div
        class="{$application.build.pack === 'vuejs'
          ? 'buildpack bg-green-500'
          : 'buildpack hover:border-green-500'}"
        on:click="{selectBuildPack}"
      >
        VueJS
      </div>
      <div
        class="{$application.build.pack === 'nuxtjs'
          ? 'buildpack bg-green-500'
          : 'buildpack hover:border-green-500'}"
        on:click="{selectBuildPack}"
      >
        NuxtJS
      </div>
      <div
        class="{$application.build.pack === 'react'
          ? 'buildpack bg-gradient-to-r from-blue-500 to-purple-500'
          : 'buildpack hover:border-blue-500'}"
        on:click="{selectBuildPack}"
      >
        React/Preact
      </div>
      <div
        class="{$application.build.pack === 'nextjs'
          ? 'buildpack bg-blue-500'
          : 'buildpack hover:border-blue-500'}"
        on:click="{selectBuildPack}"
      >
        NextJS
      </div>
      <div
        class="{$application.build.pack === 'gatsby'
          ? 'buildpack bg-blue-500'
          : 'buildpack hover:border-blue-500'}"
        on:click="{selectBuildPack}"
      >
        Gatsby
      </div>
      <div
        class="{$application.build.pack === 'svelte'
          ? 'buildpack bg-orange-600'
          : 'buildpack hover:border-orange-600'}"
        on:click="{selectBuildPack}"
      >
        Svelte
      </div>
      <div
        class="{$application.build.pack === 'php'
          ? 'buildpack bg-indigo-500'
          : 'buildpack hover:border-indigo-500'}"
        on:click="{selectBuildPack}"
      >
        PHP
      </div>
      <div
        class="{$application.build.pack === 'rust'
          ? 'buildpack bg-pink-500'
          : 'buildpack hover:border-pink-500'}"
        on:click="{selectBuildPack}"
      >
        Rust
      </div>
      <div
        class="{$application.build.pack === 'docker'
          ? 'buildpack bg-purple-500'
          : 'buildpack hover:border-purple-500'}"
        on:click="{selectBuildPack}"
      >
        Docker
      </div>
    </div>
  </div>
  <div class="text-2xl font-bold border-gradient w-52">General settings</div>
  <div
    class="grid grid-cols-1 max-w-2xl md:mx-auto mx-6 justify-center items-center pt-10"
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
    <!-- {JSON.stringify(buildpacks[$application.build.pack])} -->
    <label
      for="Port"
      class:text-warmGray-800="{!buildpacks[$application.build.pack].port
        .active}">Port</label
    >
    <input
      disabled="{!buildpacks[$application.build.pack].port.active}"
      id="Port"
      class:bg-warmGray-900="{!buildpacks[$application.build.pack].port.active}"
      class:text-warmGray-900="{!buildpacks[$application.build.pack].port
        .active}"
      class:placeholder-warmGray-800="{!buildpacks[$application.build.pack].port
        .active}"
      class:hover:bg-warmGray-900="{!buildpacks[$application.build.pack].port
        .active}"
      class:cursor-not-allowed="{!buildpacks[$application.build.pack].port
        .active}"
      bind:value="{$application.publish.port}"
      placeholder="{buildpacks[$application.build.pack].port.number}"
    />
    <div class="grid grid-flow-col gap-2 items-center pt-6 pb-12">
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
  <div
    class="text-2xl font-bold  w-40"
    class:border-gradient="{buildpacks[$application.build.pack].build}"
    class:text-warmGray-800="{!buildpacks[$application.build.pack].build}"
  >
    Commands
  </div>
  <div
    class=" max-w-2xl md:mx-auto mx-6 justify-center items-center pt-10 pb-32"
  >
    <div class="grid grid-flow-col gap-2 items-center">
      <div class="grid grid-flow-row">
        <label
          for="installCommand"
          class:text-warmGray-800="{!buildpacks[$application.build.pack].build}"
          >Install Command <TooltipInfo
            label="Command to run for installing dependencies. eg: yarn install."
          />
        </label>

        <input
          class="mb-6"
          class:bg-warmGray-900="{!buildpacks[$application.build.pack].build}"
          class:text-warmGray-900="{!buildpacks[$application.build.pack].build}"
          class:placeholder-warmGray-800="{!buildpacks[$application.build.pack]
            .build}"
          class:hover:bg-warmGray-900="{!buildpacks[$application.build.pack]
            .build}"
          class:cursor-not-allowed="{!buildpacks[$application.build.pack]
            .build}"
          id="installCommand"
          bind:value="{$application.build.command.installation}"
          placeholder="eg: yarn install"
        />
        <label
          for="buildCommand"
          class:text-warmGray-800="{!buildpacks[$application.build.pack].build}"
          >Build Command <TooltipInfo
            label="Command to run for building your application. If empty, no build phase initiated in the deploy process."
          /></label
        >
        <input
          class="mb-6"
          class:bg-warmGray-900="{!buildpacks[$application.build.pack].build}"
          class:text-warmGray-900="{!buildpacks[$application.build.pack].build}"
          class:placeholder-warmGray-800="{!buildpacks[$application.build.pack]
            .build}"
          class:hover:bg-warmGray-900="{!buildpacks[$application.build.pack]
            .build}"
          class:cursor-not-allowed="{!buildpacks[$application.build.pack]
            .build}"
          id="buildCommand"
          bind:value="{$application.build.command.build}"
          placeholder="eg: yarn build"
        />
      </div>
    </div>
  </div>
</div>
