<script>
  export let position = "top";
  export let label;
  export let size = "large";
</script>

<span
  class="absolute px-1 py-1"
  aria-label="{label}"
  data-microtip-position="{position}"
  data-microtip-size="{size}"
  role="tooltip"
>
  <svg
    class="w-4 text-warmGray-600 hover:text-white"
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 20 20"
    fill="currentColor"
  >
    <path
      fill-rule="evenodd"
      d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
      clip-rule="evenodd"></path>
  </svg>
</span>
