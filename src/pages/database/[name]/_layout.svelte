<script>
  import { params, goto } from "@roxi/routify";
  $: name = $params.name;
</script>

<div class="bg-coolgray-300 text-white">
  <nav
    class="mx-auto bg-coolgray-300 border-b-4 border-purple-500 text-white mb-3 sm:px-4"
  >
    <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
      <li>
        <button
          class="hover:text-purple-400 font-bold text-sm cursor-pointer"
          class:text-purple-400="{name !== 'new'}"
          class:cursor-default="{name === 'new'}"
          class:cursor-pointer="{name !== 'new'}"
          class:hover:text-purple-400="{name !== 'new'}"
          class:text-gray-600="{name === 'new'}"
          disabled="{name === 'new'}"
          on:click="{() => $goto(`/database/${name}/overview`)}"
        >
          Overview
        </button>
      </li>
      <li>
        <button
          class="font-bold text-sm cursor-not-allowed"
          class:cursor-default="{name}"
          class:text-gray-600="{name}"
          disabled="{name}"
        >
          Configuration
        </button>
      </li>
      <li class="flex-1 hidden lg:flex"></li>
    </ul>
  </nav>
</div>

<slot />
