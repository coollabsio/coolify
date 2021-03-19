<script>
  import { params, goto, redirect, isActive } from "@roxi/routify";
  import { configuration, fetch, initialConfiguration, initConf } from "@store";
  import { onDestroy } from "svelte";
  import { fade } from "svelte/transition";
  import Loading from "../../components/Loading.svelte";
  import { toast } from "@zerodevx/svelte-toast";

  $configuration.repository.organization = $params.organization;
  $configuration.repository.name = $params.name;
  $configuration.repository.branch = $params.branch;

  // $: mod = JSON.stringify($initConf) !== JSON.stringify($configuration);

  let disabled = {
    deploy: false,
  };
  async function loadConfiguration() {
    if (!$isActive("/application/new")) {
      try {
        const config = await $fetch(`/api/v1/config`, {
          body: {
            name: $configuration.repository.name,
            organization: $configuration.repository.organization,
            branch: $configuration.repository.branch,
          },
        });
        $configuration = { ...config };
        $initConf = JSON.parse(JSON.stringify($configuration));
      } catch (error) {
        toast.push("Configuration not found.");
        $redirect("/dashboard/applications");
      }
    } else {
      $configuration = JSON.parse(JSON.stringify(initialConfiguration));
    }
  }

  async function removeApplication() {
    await $fetch(`/api/v1/application/remove`, {
      body: {
        organization: $params.organization,
        name: $params.name,
        branch: $params.branch,
      },
    });

    toast.push("Application removed.");
    $redirect(`/dashboard/applications`);
  }

  onDestroy(() => {
    $configuration = JSON.parse(JSON.stringify(initialConfiguration));
  });

  async function deploy() {
    disabled.deploy = true;
    try {
      toast.push("Checking some parameters.");
      const status = await $fetch(`/api/v1/application/check`, {
        body: $configuration,
      });
      const { nickname, name } = await $fetch(`/api/v1/application/deploy`, {
        body: $configuration,
      });
      $configuration.general.nickname = nickname;
      $configuration.build.container.name = name;
      $initConf = JSON.parse(JSON.stringify($configuration));
      toast.push("Application deployment queued.");

      $redirect(
        `/application/${$configuration.repository.organization}/${$configuration.repository.name}/${$configuration.repository.branch}/logs`,
      );
    } catch (error) {
      console.log(error);
      toast.push(error.error ? error.error : "Ooops something went wrong.");
    } finally {
      disabled.deploy = false;
    }
  }
</script>

{#await loadConfiguration() then notUsed}
<slot />
{/await}
