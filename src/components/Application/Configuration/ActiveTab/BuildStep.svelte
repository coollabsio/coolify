<script>
  import { configuration} from "@store";
</script>

<div class="grid grid-cols-1 space-y-2 max-w-2xl md:mx-auto mx-6 text-center">
  <label for="buildCommand">Build Command</label>
  <input
    id="buildCommand"
    bind:value="{$configuration.build.command.build}"
    placeholder="eg: yarn build"
  />

  <label for="installCommand">Install Command</label>
  <input
    id="installCommand"
    bind:value="{$configuration.build.command.installation}"
    placeholder="eg: yarn install"
  />

  <label for="baseDir">Base Directory</label>
  <input id="baseDir" bind:value="{$configuration.build.directory}" placeholder="/" />
</div>
