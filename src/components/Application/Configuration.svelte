<script>
  import { params, redirect } from "@roxi/routify";
  import { fade } from "svelte/transition";

  import { session, configuration, fetch, initialConfiguration } from "@store";
  import Login from "./Configuration/Login.svelte";
  import Loading from "../Loading.svelte";
  import Repositories from "./Configuration/Repositories.svelte";
  import { onMount } from "svelte";
  import Branches from "./Configuration/Branches.svelte";
  import Tabs from "./Configuration/Tabs.svelte";

  let loading = {
    github: false,
    branches: false,
  };

  let branches = [];
  let repositories = [];

  async function loadBranches() {
    loading.branches = true;
    const selectedRepository = repositories.find(
      r => r.id === $configuration.repository.id,
    );

    if (selectedRepository) {
      $configuration.repository.organization = selectedRepository.owner.login;
      $configuration.repository.name = selectedRepository.name;
    }

    branches = await $fetch(
      `https://api.github.com/repos/${$configuration.repository.organization}/${$configuration.repository.name}/branches`,
    );
    loading.branches = false;
    // if (
    //   $configuration.repository.organization !== "new" &&
    //   $configuration.repository.name !== "start"
    // ) {
    //   await loadConfiguration();
    // }
  }

  async function loadGithub() {
    try {
      loading.github = true;
      const { installations } = await $fetch(
        "https://api.github.com/user/installations",
      );
      if (installations.length === 0) {
        return false;
      }
      $configuration.github.installation.id = installations[0].id;
      $configuration.github.app.id = installations[0].app_id;

      const data = await $fetch(
        `https://api.github.com/user/installations/${$configuration.github.installation.id}/repositories?per_page=10000`,
      );

      repositories = data.repositories;
      const foundRepositoryOnGithub = data.repositories.find(
        r =>
          r.full_name ===
          `${$configuration.repository.organization}/${$configuration.repository.name}`,
      );

      if (foundRepositoryOnGithub) {
        $configuration.repository.id = foundRepositoryOnGithub.id;
        await loadBranches();
      }
    } catch (error) {
      return false;
    } finally {
      loading.github = false;
    }
  }
  function modifyGithubAppConfig() {
    const left = screen.width / 2 - 1020 / 2;
    const top = screen.height / 2 - 618 / 2;
    const newWindow = open(
      `https://github.com/apps/${
        import.meta.env.VITE_GITHUB_APP_NAME
      }/installations/new`,
      "Install App",
      "resizable=1, scrollbars=1, fullscreen=0, height=1000, width=1020,top=" +
        top +
        ", left=" +
        left +
        ", toolbar=0, menubar=0, status=0",
    );
    const timer = setInterval(async () => {
      if (newWindow.closed) {
        clearInterval(timer);
        loading.github = true;
        if ($params.organization !== "new" && $params.name !== "start") {
          try {
            const config = await $fetch(`/api/v1/config`, {
              body: {
                name: $configuration.repository.name,
                organization: $configuration.repository.organization,
                branch: $configuration.repository.branch,
              },
            });
            $configuration = { ...config };
          } catch (error) {
            $redirect("/dashboard/applications");
          }
        } else {
          $configuration = JSON.parse(JSON.stringify(initialConfiguration));
        }
        branches = [];
        repositories = [];
        await loadGithub();
        loading.github = false;
      }
    }, 100);
  }
</script>

{#if !$session.githubAppToken}
  <Login />
{:else}
  {#await loadGithub()}
    <Loading message="{'Loading Github...'}" />
  {:then}
    {#if loading.github}
      <Loading message="{'Loading Github...'}" />
    {:else}
      <div
        class="text-center space-y-2 max-w-4xl md:mx-auto mx-6 py-4"
        in:fade="{{ duration: 100 }}"
      >
        <Repositories
          bind:repositories
          on:loadBranches="{loadBranches}"
          on:modifyGithubAppConfig="{modifyGithubAppConfig}"
        />
        {#if $configuration.repository.organization !== "new"}
          <Branches loading="{loading.branches}" branches="{branches}" />
        {/if}

        {#if $configuration.repository.branch}
          <Tabs />
        {/if}
      </div>
    {/if}
  {/await}
{/if}
