<script>
  import { SvelteToast } from "@zerodevx/svelte-toast";
  import { Router } from "@roxi/routify";
  import { routes } from "../.routify/routes";
  const options = {
    duration: 1500,
  };
</script>

<SvelteToast {options} />
<Router {routes} />
