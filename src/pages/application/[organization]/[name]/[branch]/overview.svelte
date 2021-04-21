<script>
  import { application } from "@store";
  import Configuration from "../../../../../components/Application/Configuration/Configuration.svelte";
</script>

<div class="min-h-full text-white">
  <div
    class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
  >
    Overview of
    <a
      target="_blank"
      class="hover:underline cursor-pointer px-2"
      href="{'https://' +
        $application.publish.domain +
        $application.publish.path}">{$application.publish.domain}</a
    >
    <a
      target="_blank"
      class="icon"
      href="{`https://github.com/${$application.repository.organization}/${$application.repository.name}`}"
    >
    <svg class="w-6" xmlns="http://www.w3.org/2000/svg"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg></a
    >

  </div>
</div>


<Configuration />
