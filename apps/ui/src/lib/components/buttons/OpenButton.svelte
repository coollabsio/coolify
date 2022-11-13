<script lang="ts">
  export let location:string;
</script>
<a href={location} target="_blank noreferrer" class="btn btn-sm gap-2 text-sm bg-primary"
  ><svg
    xmlns="http://www.w3.org/2000/svg"
    class="h-6 w-6"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    fill="none"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
    <line x1="10" y1="14" x2="20" y2="4" />
    <polyline points="15 4 20 4 20 9" />
  </svg>Open</a
>