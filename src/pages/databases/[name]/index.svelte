<script>
        import { params } from "@roxi/routify";
        import ExistingDatabase from "../../../components/Databases/Existing.svelte";
        import Mongodb from "../../../components/Databases/Mongodb/Mongodb.svelte";
        import Postgresql from "../../../components/Databases/Postgresql/Postgresql.svelte";
        $: name = $params.name;
        let selected = null;
</script>

{#if name === "new"}
        <div class="flex items-center py-6 px-5 justify-center">
                <div class="text-4xl font-bold tracking-tight">
                        New Database
                </div>
        </div>
        <div
                class="text-base font-bold tracking-tight px-5 py-3 text-center space-x-4"
        >
                <button
                        class="border-b-4 border-transparent hover:border-blue-500"
                        on:click={() => (selected = "mongodb")}>MongoDB</button
                >
                <button
                        class="border-b-4 border-transparent hover:border-blue-500"
                        on:click={() => (selected = "postgresql")}
                        >PostgreSQL</button
                >
        </div>
        {#if selected == "mongodb"}
                <Mongodb />
        {/if}
        {#if selected == "postgresql"}
                <Postgresql />
        {/if}
{:else}
        <ExistingDatabase {name} />
{/if}
