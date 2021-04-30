<script>
  import { params, redirect } from "@roxi/routify";
  import {
    application,
    fetch,
    initialApplication,
    initConf,
    deployments,
    activePage,
  } from "@store";
  import { onDestroy } from "svelte";
  import Loading from "../../components/Loading.svelte";
  import { toast } from "@zerodevx/svelte-toast";
  import Navbar from "../../components/Application/Navbar.svelte";

  $application.repository.organization = $params.organization;
  $application.repository.name = $params.name;
  $application.repository.branch = $params.branch;

  async function setConfiguration() {
    try {
      const config = await $fetch(`/api/v1/config`, {
        body: {
          name: $application.repository.name,
          organization: $application.repository.organization,
          branch: $application.repository.branch,
        },
      });
      $application = { ...config };
      $initConf = JSON.parse(JSON.stringify($application));
    } catch (error) {
      toast.push("Configuration not found.");
      $redirect("/dashboard/applications");
    }
  }
  async function loadConfiguration() {
    if (!$activePage.new) {
      if ($deployments.length === 0) {
        await setConfiguration();
      } else {
        const found = $deployments.applications.deployed.find(app => {
          const { organization, name, branch } = app.configuration;
          if (
            organization === $application.repository.organization &&
            name === $application.repository.name &&
            branch === $application.repository.branch
          ) {
            return app;
          }
        });
        if (found) {
          $application = { ...found.configuration };
          $initConf = JSON.parse(JSON.stringify($application));
        } else {
          await setConfiguration();
        }
      }
    } else {
      $application = JSON.parse(JSON.stringify(initialApplication));
    }
  }

  onDestroy(() => {
    $application = JSON.parse(JSON.stringify(initialApplication));
  });
</script>

{#await loadConfiguration()}
  <Loading />
{:then}
  <Navbar />
  <div class="text-white">
    <slot />
  </div>
{/await}
