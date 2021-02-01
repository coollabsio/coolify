<script>
    export let databases;
    import { url } from "@roxi/routify/runtime";
</script>

<div class="flex items-center py-6 px-5 justify-center">
    <div class="text-4xl font-bold tracking-tight">Databases</div>
    <a
        class="mx-2 flex items-center justify-center h-8 w-8 bg-blue-600 border border-black rounded-md text-white hover:bg-blue-500"
        href={$url("/databases/new")}
        ><svg
            class="w-6"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"
            />
        </svg></a
    >
  
</div>

{#if databases.deployed.length > 0}
    <div class="text-base font-bold tracking-tight px-5 py-3 text-center">
        Deployed
    </div>
    <div class="flex flex-row flex-wrap gap-4 justify-center items-center mx-6">
        {#each databases.deployed as database}
            <div
                class="rounded-md p-4 tracking-tight bg-coolgray-300 border-2 shadow"
                class:border-yellow-300={database.progress === "inprogress"}
                class:border-red-600={database.progress === "failed"}
                class:border-black={database.progress === "done" ||
                    !database.progress}
            >
                <div class="font-medium text-center text-xl">
                    {database.Spec.Labels.name}
                </div>
 

            </div>
        {/each}
    </div>
{/if}