<script>
  import { redirect, isActive } from "@roxi/routify";
  import { onMount } from "svelte";
  import { toast } from "@zerodevx/svelte-toast";
  import templates from "../../../utils/templates";
  import { application, fetch, deployments } from "@store";
  import General from "./ActiveTab/General.svelte";
  import BuildStep from "./ActiveTab/BuildStep.svelte";
  import Secrets from "./ActiveTab/Secrets.svelte";
  import Loading from "../../Loading.svelte";

  let loading = false;
  onMount(async () => {
    if (!$isActive("/application/new")) {
      const config = await $fetch(`/api/v1/config`, {
        body: {
          name: $application.repository.name,
          organization: $application.repository.organization,
          branch: $application.repository.branch,
        },
      });
      $application = { ...config };
      $redirect(`/application/:organization/:name/:branch/configuration`, {
        name: $application.repository.name,
        organization: $application.repository.organization,
        branch: $application.repository.branch,
      });
    } else {
      loading = true;
      $deployments?.applications?.deployed.filter(d => {
        const conf = d?.Spec?.Labels.application;
        if (
          conf?.repository?.organization ===
            $application.repository.organization &&
          conf?.repository?.name === $application.repository.name &&
          conf?.repository?.branch === $application.repository.branch
        ) {
          $redirect(`/application/:organization/:name/:branch/configuration`, {
            name: $application.repository.name,
            organization: $application.repository.organization,
            branch: $application.repository.branch,
          });
        }
      });
      try {
        const dir = await $fetch(
          `https://api.github.com/repos/${$application.repository.organization}/${$application.repository.name}/contents/?ref=${$application.repository.branch}`,
        );
        const packageJson = dir.find(
          f => f.type === "file" && f.name === "package.json",
        );
        const Dockerfile = dir.find(
          f => f.type === "file" && f.name === "Dockerfile",
        );

        if (Dockerfile) {
          $application.build.pack = "custom";
          toast.push("Custom Dockerfile found. Build pack set to custom.");
        } else if (packageJson) {
          // Check here for things like nextjs,react,vue,blablabla
          const { content } = await $fetch(packageJson.git_url);
          const packageJsonContent = JSON.parse(atob(content));
          const checkPackageJSONContents = dep => {
            return (
              packageJsonContent.dependencies.hasOwnProperty(dep) ||
              packageJsonContent.devDependencies.hasOwnProperty(dep)
            );
          };
          Object.keys(templates).map(dep => {
            if (checkPackageJSONContents(dep)) {
              const config = templates[dep];
              $application.build.pack = config.pack;
              if (config.installation) {
                $application.build.command.installation = config.installation;
              }

              if (config.port) {
                $application.publish.port = config.port;
              }

              if (config.directory) {
                $application.publish.directory = config.directory;
              }

              if (
                packageJsonContent.scripts.hasOwnProperty("build") &&
                config.build
              ) {
                $application.build.command.build = config.build;
              }
              toast.push(
                `${config.name} App detected. Build pack set to ${config.pack}.`,
              );
            }
          });
        }
      } catch (error) {
        // Nothing detected
      }
    }
    loading = false;
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

{#if loading}
  <Loading github githubLoadingText="Scanning repository 🤖" />
{:else}
  <div class="block text-center py-4">
    <nav
      class="flex space-x-4 justify-center font-bold text-md text-white"
      aria-label="Tabs"
    >
      <div
        on:click="{() => activateTab('general')}"
        class:text-green-500="{activeTab.general}"
        class="px-3 py-2 cursor-pointer hover:text-green-500"
      >
        General
      </div>
      {#if $application.build.pack === "php"}
        <div disabled class="px-3 py-2 text-warmGray-700 cursor-not-allowed">
          Build Step
        </div>
      {:else}
        <div
          on:click="{() => activateTab('buildStep')}"
          class:text-green-500="{activeTab.buildStep}"
          class="px-3 py-2 cursor-pointer hover:text-green-500"
        >
          Build Step
        </div>
      {/if}

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
{/if}
