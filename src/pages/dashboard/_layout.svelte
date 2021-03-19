<script>
  import { goto, isActive } from "@roxi/routify/runtime";
</script>

<div class=" min-h-full text-white">
  <div class=" py-5 text-left px-10 text-3xl  tracking-tight font-bold">
    {#if $isActive("/dashboard/applications")}
      Applications
    {:else if $isActive("/dashboard/databases")}
      Databases
    {/if}
  </div>
  <slot />
</div>
