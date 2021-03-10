<script>
  import { fade } from "svelte/transition";
  import { configuration } from "@store";
</script>

<div
  class="text-center space-y-2 max-w-4xl md:mx-auto mx-6 tracking-tighter"
  in:fade="{{ duration: 100 }}"
>
  <div>
    <div class="font-bold text-xl">Overview will be here</div>
    <button>
      <a
        target="_blank"
        class="button p-1 bg-green-500 hover:bg-green-600 text-white"
        href="{'https://' +
          $configuration.publish.domain +
          $configuration.publish.path}">Open application</a
      ></button
    >
  </div>
</div>
