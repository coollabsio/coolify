<script>
  import { redirect } from "@roxi/routify";
  import { fade } from "svelte/transition";
  import {
    session,
    application,
    fetch,
    initialApplication,
    githubRepositories,
    githubInstallations,
    activePage
  } from "@store";
  
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
  function dashify(str, options) {
    if (typeof str !== "string") return str;
    return str
      .trim()
      .replace(/\W/g, m => (/[À-ž]/.test(m) ? m : "-"))
      .replace(/^-+|-+$/g, "")
      .replace(/-{2,}/g, m => (options && options.condense ? "-" : m))
      .toLowerCase();
  }

  async function loadBranches() {
    loading.branches = true;
    if ($activePage === 'new') $application.repository.branch = null;
    const selectedRepository = $githubRepositories.find(
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

  async function getGithubRepos(id, page) {
    const data = await $fetch(
      `https://api.github.com/user/installations/${id}/repositories?per_page=100&page=${page}`,
    );

    return data;
  }

  async function loadGithub() {
    if ($githubRepositories.length > 0) {
      $application.github.installation.id = $githubInstallations.id;
      $application.github.app.id = $githubInstallations.app_id;
      const foundRepositoryOnGithub = $githubRepositories.find(
        r =>
          r.full_name ===
          `${$application.repository.organization}/${$application.repository.name}`,
      );

      if (foundRepositoryOnGithub) {
        $application.repository.id = foundRepositoryOnGithub.id;
        await loadBranches();
      }
      return;
    }
    loading.github = true;
    try {
      const { installations } = await $fetch(
        "https://api.github.com/user/installations",
      );
      if (installations.length === 0) {
        return false;
      }
      $application.github.installation.id = installations[0].id;
      $application.github.app.id = installations[0].app_id;
      $githubInstallations = installations[0];

      let page = 1;
      let userRepos = 0;
      const data = await getGithubRepos(
        $application.github.installation.id,
        page,
      );

      $githubRepositories = $githubRepositories.concat(data.repositories);
      userRepos = data.total_count;

      if (userRepos > $githubRepositories.length) {
        while (userRepos > $githubRepositories.length) {
          page = page + 1;
          const repos = await getGithubRepos(
            $application.github.installation.id,
            page,
          );
          $githubRepositories = $githubRepositories.concat(repos.repositories);
        }
      }
      const foundRepositoryOnGithub = $githubRepositories.find(
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
    } finally {
      loading.github = false;
    }
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
        if ($activePage !== 'new') {
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
        $githubRepositories = [];
        await loadGithub();
      }
    }, 100);
  }
</script>

{#if $activePage !== 'new'}
  <div class="min-h-full text-white">
    <div
      class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
    >
      {$application.publish.domain
        ? `${$application.publish.domain}${
            $application.publish.path !== "/" ? $application.publish.path : ""
          }`
        : "<yourdomain>"}
      <a
        target="_blank"
        class="icon mx-2"
        href="{'https://' +
          $application.publish.domain +
          $application.publish.path}"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="h-6 w-6"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
          ></path>
        </svg></a
      >

      <a
        target="_blank"
        class="icon"
        href="{`https://github.com/${$application.repository.organization}/${$application.repository.name}`}"
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
          ><path
            d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"
          ></path></svg
        ></a
      >
    </div>
  </div>
{:else if $activePage === 'new'}
  <div class="min-h-full text-white">
    <div
      class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
    >
      New Application
    </div>
  </div>
{/if}

<div in:fade="{{ duration: 100 }}">
  {#if !$session.githubAppToken}
    <Login />
  {:else}
    {#await loadGithub()}
      <Loading github githubLoadingText="Loading repositories..." />
    {:then}
      {#if loading.github}
        <Loading github githubLoadingText="Loading repositories..." />
      {:else}
        <div
          class="space-y-2 max-w-4xl mx-auto px-6"
          in:fade="{{ duration: 100 }}"
        >
          <Repositories
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
