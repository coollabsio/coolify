<script>
  import { application } from "@store";
import Tooltip from "../../../Tooltip/TooltipInfo.svelte";
</script>

<div class="grid grid-cols-1 max-w-2xl md:mx-auto mx-6 text-center">
  <label for="installCommand"
    >Install Command <Tooltip label="Command to run for installing dependencies. eg: yarn install." />
  </label>

  <input
    class="mb-6"
    id="installCommand"
    bind:value="{$application.build.command.installation}"
    placeholder="eg: yarn install"
  />
  <label for="buildCommand">Build Command <Tooltip label="Command to run for building your application. If empty, no build phase initiated in the deploy process." /></label>
  <input
    class="mb-6"
    id="buildCommand"
    bind:value="{$application.build.command.build}"
    placeholder="eg: yarn build"
  />
</div>
