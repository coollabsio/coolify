<script>
  import { redirect } from "@roxi/routify";
  import { onMount } from "svelte";
  import { toast } from "@zerodevx/svelte-toast";
  import templates from "../../../utils/templates";
  import { application, fetch, deployments, activePage } from "@store";
  import General from "./ActiveTab/General.svelte";
  import Secrets from "./ActiveTab/Secrets.svelte";
  import Loading from "../../Loading.svelte";

  let loading = false;
  onMount(async () => {
    if (!$activePage.new) {
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
      $deployments?.applications?.deployed.find(configuration => {
        if (
          configuration?.repository?.organization ===
            $application.repository.organization &&
          configuration?.repository?.name === $application.repository.name &&
          configuration?.repository?.branch === $application.repository.branch
        ) {
          $redirect(`/application/:organization/:name/:branch/configuration`, {
            name: $application.repository.name,
            organization: $application.repository.organization,
            branch: $application.repository.branch,
          });
          toast.push(
            "This repository & branch is already defined. Redirecting...",
          );
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
        const CargoToml = dir.find(
          f => f.type === "file" && f.name === "Cargo.toml",
        );

        if (packageJson) {
          const { content } = await $fetch(packageJson.git_url);
          const packageJsonContent = JSON.parse(atob(content));
          const checkPackageJSONContents = dep => {
            return (
              packageJsonContent?.dependencies?.hasOwnProperty(dep) ||
              packageJsonContent?.devDependencies?.hasOwnProperty(dep)
            );
          };
          Object.keys(templates).map(dep => {
            if (checkPackageJSONContents(dep)) {
              const config = templates[dep];
              $application.build.pack = config.pack;
              if (config.installation)
                $application.build.command.installation = config.installation;
              if (config.port) $application.publish.port = config.port;
              if (config.directory)
                $application.publish.directory = config.directory;

              if (
                packageJsonContent.scripts.hasOwnProperty("build") &&
                config.build
              ) {
                $application.build.command.build = config.build;
              }
              toast.push(`${config.name} detected. Default values set.`);
            }
          });
        } else if (CargoToml) {
          $application.build.pack = "rust";
          toast.push(`Rust language detected. Default values set.`);
        } else if (Dockerfile) {
          $application.build.pack = "docker";
          toast.push("Custom Dockerfile found. Build pack set to docker.");
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
  <Loading github githubLoadingText="Scanning repository..." />
{:else}
  <div class="block text-center py-8">
    <nav
      class="flex space-x-4 justify-center font-bold text-md text-white"
      aria-label="Tabs"
    >
      <div
        on:click="{() => activateTab('general')}"
        class:text-green-500="{activeTab.general}"
        class="px-3 py-2 cursor-pointer hover:bg-warmGray-700 rounded-lg transition duration-100"
      >
      General
      </div>
      <div
        on:click="{() => activateTab('secrets')}"
        class:text-green-500="{activeTab.secrets}"
        class="px-3 py-2 cursor-pointer hover:bg-warmGray-700 rounded-lg transition duration-100"
      >
      Secrets
      </div>
    </nav>
  </div>
  <div class="max-w-4xl mx-auto">
    <div class="h-full">
      {#if activeTab.general}
        <General />
      {:else if activeTab.secrets}
        <Secrets />
      {/if}
    </div>
  </div>
{/if}
