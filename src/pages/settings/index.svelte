<script>
    import { toast } from "@zerodevx/svelte-toast";
    import Loading from "../../components/Loading.svelte";
    import { fetch } from "../../store";
    let settings = {
        allowRegistration: false,
    };

    async function loadSettings() {
        const response = await $fetch(`/api/v1/settings`);
        settings.allowRegistration = response.settings.allowRegistration;
    }
    async function changeSettings(value) {
        settings[value] = !settings[value];
        await $fetch(`/api/v1/settings`, {
            body: {
                ...settings,
            },
        });
        toast.push("Configuration saved.");
    }
</script>

<div class="flex items-center py-6 px-5 justify-center">
    <div class="text-4xl font-bold tracking-tight">Settings</div>
</div>
<div class="text-center space-y-2 max-w-2xl md:mx-auto mx-6 pt-6 pb-4">
    <div class="flex items-center justify-center space-x-4">
        {#await loadSettings()}
            <Loading />
        {:then notUsed}
            <span class="text-base font-bold text-white"
                >Registration allowed?</span
            >
            <button
                type="button"
                on:click={() => changeSettings("allowRegistration")}
                aria-pressed="false"
                class="relative inline-flex flex-shrink-0  h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-black"
                class:bg-green-600={settings.allowRegistration}
                class:bg-coolgray-300={!settings.allowRegistration}
            >
                <span class="sr-only">Use setting</span>
                <span
                    class="pointer-events-none  relative inline-block h-5 w-5 rounded-full bg-white shadow transform ring-0 transition ease-in-out duration-200"
                    class:translate-x-5={settings.allowRegistration}
                    class:translate-x-0={!settings.allowRegistration}
                >
                    <span
                        class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
                        class:opacity-0={settings.allowRegistration}
                        class:opacity-100={!settings.allowRegistration}
                        aria-hidden="true"
                    >
                        <svg
                            class="bg-white h-3 w-3 text-red-600"
                            fill="none"
                            viewBox="0 0 12 12"
                        >
                            <path
                                d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                    <span
                        class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
                        aria-hidden="true"
                        class:opacity-100={settings.allowRegistration}
                        class:opacity-0={!settings.allowRegistration}
                    >
                        <svg
                            class="bg-white h-3 w-3 text-green-600"
                            fill="currentColor"
                            viewBox="0 0 12 12"
                        >
                            <path
                                d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
                            />
                        </svg>
                    </span>
                </span>
            </button>
        {/await}
    </div>
</div>
