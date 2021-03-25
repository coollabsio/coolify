<script>
  import { redirect, isActive } from "@roxi/routify";
  import { fade } from "svelte/transition";
  import { session, application, fetch, initialApplication } from "@store";

  import Login from "./Login.svelte";
  import Loading from "../../Loading.svelte";
  import Repositories from "./Repositories.svelte";
  import Branches from "./Branches.svelte";
  import Tabs from "./Tabs.svelte";

  let loading = {
    branches: false,
    github: false,
  };

  let branches = [];
  let repositories = [];

  function dashify(str, options) {
    if (typeof str !== "string") return str
    return str
      .trim()
      .replace(/\W/g, m => (/[À-ž]/.test(m) ? m : "-"))
      .replace(/^-+|-+$/g, "")
      .replace(/-{2,}/g, m => (options && options.condense ? "-" : m))
      .toLowerCase();
  }

  async function loadBranches() {
    loading.branches = true;
    const selectedRepository = repositories.find(
      r => r.id === $application.repository.id,
    );

    if (selectedRepository) {
      $application.repository.organization = selectedRepository.owner.login;
      $application.repository.name = selectedRepository.name;
    }

    branches = await $fetch(
      `https://api.github.com/repos/${$application.repository.organization}/${$application.repository.name}/branches`,
    );
    loading.branches = false;
  }

  async function loadGithub() {
    try {
      const { installations } = await $fetch(
        "https://api.github.com/user/installations",
      );
      if (installations.length === 0) {
        return false;
      }
      $application.github.installation.id = installations[0].id;
      $application.github.app.id = installations[0].app_id;

      const data = await $fetch(
        `https://api.github.com/user/installations/${$application.github.installation.id}/repositories?per_page=10000`,
      );

      repositories = data.repositories;
      const foundRepositoryOnGithub = data.repositories.find(
        r =>
          r.full_name ===
          `${$application.repository.organization}/${$application.repository.name}`,
      );

      if (foundRepositoryOnGithub) {
        $application.repository.id = foundRepositoryOnGithub.id;
        await loadBranches();
      }
    } catch (error) {
      return false;
    }
    loading.github = false;
  }
  function modifyGithubAppConfig() {
    const left = screen.width / 2 - 1020 / 2;
    const top = screen.height / 2 - 618 / 2;
    const newWindow = open(
      `https://github.com/apps/${dashify(
        import.meta.env.VITE_GITHUB_APP_NAME,
      )}/installations/new`,
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
        if (!$isActive("/application/new")) {
          try {
            const config = await $fetch(`/api/v1/config`, {
              body: {
                name: $application.repository.name,
                organization: $application.repository.organization,
                branch: $application.repository.branch,
              },
            });
            $application = { ...config };
          } catch (error) {
            $redirect("/dashboard/applications");
          }
        } else {
          $application = JSON.parse(JSON.stringify(initialApplication));
        }
        branches = [];
        repositories = [];
        await loadGithub();
      }
    }, 100);
  }
</script>

<div in:fade="{{ duration: 100 }}">
  {#if !$session.githubAppToken}
    <Login />
  {:else}
    {#await loadGithub()}
      <Loading />
    {:then}
      {#if loading.github}
        <Loading />
      {:else}
        <div
          class="text-center space-y-2 max-w-4xl mx-auto px-6"
          in:fade="{{ duration: 100 }}"
        >
          <Repositories
            bind:repositories
            on:loadBranches="{loadBranches}"
            on:modifyGithubAppConfig="{modifyGithubAppConfig}"
          />
          {#if $application.repository.organization !== "new"}
            <Branches loading="{loading.branches}" branches="{branches}" />
          {/if}

          {#if $application.repository.branch}
            <Tabs />
          {/if}
        </div>
      {/if}
    {/await}
  {/if}
</div>
