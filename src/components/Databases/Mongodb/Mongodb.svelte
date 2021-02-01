<script>
    import { fetch } from "../../../store";
    async function deployDB() {
        return await $fetch(`/api/v1/databases/new`, {
            body: { ...configuration },
        });
    }
    let configuration = {
        name: null,
    };
</script>

<div class="text-base font-bold tracking-tight px-5 py-3 text-center space-x-4">
    <div
        class="grid grid-cols-1 space-y-2 max-w-2xl md:mx-auto mx-6 text-center"
    >
        <label for="name">name</label>
        <input id="name" bind:value={configuration.name} placeholder="name" />
    </div>
    <button
        class="border-b-4 border-transparent hover:border-blue-500"
        on:click={deployDB}>Deploy MongoDB</button
    >
</div>

<style lang="postcss">
    input {
        @apply border-2 border-black text-sm rounded-md p-2 bg-coolgray-300;
    }
    select {
        @apply border-2 border-black bg-coolgray-300 text-sm rounded-md p-2;
    }
    label {
        @apply text-left text-xs font-medium;
    }
    .button {
        @apply border border-black rounded-md text-sm font-medium bg-coolgray-300;
    }
    .button:hover {
        @apply bg-coolgray-200;
    }
    .h-271 {
        min-height: 271px;
    }
</style>
