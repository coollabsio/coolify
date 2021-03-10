<script>
  import { fade } from "svelte/transition";
  export let message = "Loading...";
</script>

<p
  in:fade="{{ duration: 500 }}"
  class="text-xl font-bold tracking-tighter text-center text-gray-700"
>
  {message}
</p>
