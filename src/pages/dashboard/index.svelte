<script>
  import { fetch } from "../../store";
  import Applications from "../../components/Dashboard/Applications.svelte";
  import Databases from "../../components/Dashboard/Databases.svelte";

  async function loadDashboard() {
    return await $fetch(`/api/v1/dashboard`);
  }
</script>

{#await loadDashboard() then response}
  <Applications on:loadDashboard={loadDashboard} applications={response.applications} />
  <!-- <Databases on:loadDashboard={loadDashboard} databases={response.databases}/> -->
{/await}
