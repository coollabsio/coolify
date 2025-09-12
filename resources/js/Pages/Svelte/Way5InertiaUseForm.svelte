<script lang="ts">
    import { useForm } from "@inertiajs/svelte";

    type Props = {
        username: string;
        notifications_enabled: boolean;
        flash?: { message?: string };
    };

    let { username, notifications_enabled, flash = {} }: Props = $props();

    const form = useForm({
        username: username,
        notifications_enabled: notifications_enabled,
    });

    function handleSubmit(e: Event) {
        e.preventDefault();
        $form.post("/test-form", {});
    }

    function handleReset() {
        $form.reset();
    }
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte Inertia - Way 5 "useForm()"
            </h1>
        </div>

        <!-- Debug states. -->
        <div class="mb-6 bg-blue-50 border border-blue-200 p-4 rounded-md">
            <h3 class="text-lg font-medium text-blue-800 mb-2">
                Debug - Inertia Form State:
            </h3>
            <div class="text-sm text-blue-700 space-y-1">
                <p>
                    <strong>$form.wasSuccessful:</strong>
                    {$form.wasSuccessful}
                </p>
                <p>
                    <strong>$form.recentlySuccessful:</strong>
                    {$form.recentlySuccessful}
                </p>
                <p><strong>$form.processing:</strong> {$form.processing}</p>
                <p>
                    <strong>$form.hasErrors:</strong>
                    {$form.hasErrors}
                </p>
                <p>
                    <strong>$form.errors:</strong>
                    {JSON.stringify($form.errors)}
                </p>
                <p>
                    <strong>$form.errors.username:</strong>
                    {JSON.stringify($form.errors.username || "undefined")}
                </p>
                <p>
                    <strong>$form.errors.notifications_enabled:</strong>
                    {JSON.stringify(
                        $form.errors.notifications_enabled || "undefined",
                    )}
                </p>
                <p>
                    <strong>$form.isDirty:</strong>
                    {$form.isDirty}
                </p>
            </div>
        </div>

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <form onsubmit={handleSubmit} class="p-6">
                <div>
                    <label
                        for="username"
                        class="block text-sm font-medium text-gray-700 mb-2"
                    >
                        Username
                    </label>
                    <input
                        id="username"
                        type="text"
                        bind:value={$form.username}
                        placeholder="John Doe"
                        class="w-full text-black px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
                        class:border-yellow-400={$form.isDirty &&
                            !$form.errors.username}
                        class:border-red-500={$form.errors.username}
                    />

                    {#if $form.errors.username}
                        <p class="mt-2 text-sm text-red-600">
                            {$form.errors.username}
                        </p>
                    {/if}
                </div>

                <div class="flex items-center justify-between py-4">
                    <div class="flex flex-col">
                        <label
                            for="notifications_enabled"
                            class="text-sm font-medium text-gray-900"
                        >
                            Enable Notifications
                        </label>
                        <p class="text-sm text-gray-500">
                            Receive notifications about your account
                        </p>
                    </div>
                    <input
                        id="notifications_enabled"
                        type="checkbox"
                        bind:checked={$form.notifications_enabled}
                        class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2"
                    />
                </div>
                {#if $form.errors.notifications_enabled}
                    <p class="mt-2 text-sm text-red-600">
                        {$form.errors.notifications_enabled}
                    </p>
                {/if}

                {#if $form.recentlySuccessful}
                    <div
                        class="mt-6 bg-green-50 border border-green-200 p-4 rounded-md flex"
                    >
                        <svg
                            class="h-5 w-5 text-green-400 flex-shrink-0"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        <p class="ml-3 text-sm font-medium text-green-800">
                            {flash.message || "Settings saved successfully!"}
                        </p>
                    </div>
                {/if}

                <div
                    class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200"
                >
                    <button
                        type="button"
                        onclick={handleReset}
                        disabled={!$form.isDirty || $form.processing}
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                    >
                        Reset
                    </button>

                    <div class="flex space-x-3">

                        <button
                            type="submit"
                            disabled={$form.processing}
                            class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                        >
                            {#if $form.processing}
                                Saving...
                            {:else}
                                Save Settings
                            {/if}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
