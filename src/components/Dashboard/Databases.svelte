<script>
    export let databases;
    import { url } from "@roxi/routify/runtime";
    import { createEventDispatcher } from "svelte";

    const dispatch = createEventDispatcher();
</script>

<div class="flex items-center py-6 px-5 justify-center">
    <div class="text-4xl font-bold tracking-tight">Databases</div>
    <a
        class="mx-2 flex items-center justify-center h-8 w-8 bg-blue-600 border border-black rounded-md text-white hover:bg-blue-500"
        href={$url("/application/new/start")}
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
    <button
        class="flex items-center justify-center h-8 w-8 bg-green-600 border border-black rounded-md text-white hover:bg-green-500"
        on:click={() => dispatch("loadDashboard")}
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
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
        </svg></button
    >
</div>
