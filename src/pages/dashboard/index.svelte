<script>
  import { fetch } from "../../store";
  import Applications from "../../components/Dashboard/Applications.svelte";
  import Databases from "../../components/Dashboard/Databases.svelte";
  let deployments = {}
  async function loadDashboard() {
    deployments = await $fetch(`/api/v1/dashboard`);
  }
</script>

{#await loadDashboard() then notUsed}
  <Applications on:loadDashboard={loadDashboard} applications={deployments.applications} />
  <Databases on:loadDashboard={loadDashboard} databases={deployments.databases}/>
{/await}
