<script>
    import { onDestroy } from "svelte";
    import { fetch, savedBranch } from "../../../../store";
    import { params } from "@roxi/routify";
    import Log from "../../../../components/Application/Log.svelte";

    $: org = $params.org;
    $: repo = $params.repo;

    let logs = [];
    let branches;

    let selectedBranch = $savedBranch || null;
    console.log(selectedBranch)
    let initialSelectedBranch = "Select a branch";
    async function loadLogs() {
        logs = await $fetch(`/api/v1/logs?org=${org}&repo=${repo}`);
        branches = [...new Set(logs.map((log) => log.branch))];
    }
    onDestroy(() => {
        $savedBranch = null;
    });
</script>

<div>
    {#await loadLogs() then notUsed}
        <div class="flex justify-center items-end">
            <div class="text-4xl font-bold tracking-tight pt-6 px-2 text-center">
                Deployment logs
            </div>
            <button
                class="flex items-center justify-center h-8 w-8 bg-green-600 border border-black rounded-md text-white hover:bg-green-500"
                on:click={loadLogs}
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
        <div
            class="text-xs font-bold tracking-tight pb-6 text-center text-gray-500"
        >
            {org}/{repo}
        </div>
        {#if branches.length > 0}
        <div class="text-center space-y-2 max-w-2xl md:mx-auto mx-6 pb-4">
            <!-- svelte-ignore a11y-no-onchange -->
            <select
                class="mb-6"
                bind:value={selectedBranch}
                on:change={() => (selectedBranch = selectedBranch)}
            >
                <option selected disabled>{initialSelectedBranch}</option>
                {#each branches as branch}
                    <option value={branch}>{branch}</option>
                {/each}
            </select>

            {#each logs.filter((l) => l.branch === selectedBranch) as log}
                <Log deploy={log} />
            {/each}
        </div>
        {:else}
        <div class="text-center space-y-2 max-w-2xl md:mx-auto mx-6 pb-4 font-bold">No logs found</div>
        {/if}
    {/await}
</div>

<style lang="postcss">
    select {
        @apply border-2 border-black bg-coolgray-300 text-sm rounded-md p-2;
    }
</style>
