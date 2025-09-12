<script lang="ts">
    import { Form } from "@inertiajs/svelte"; // Way 4 -> Using the new Form component from Inertia. Docs: https://inertiajs.com/forms#form-component.

    type Props = {
        username: string;
        notifications_enabled: boolean;
        flash?: { message?: string };
    };

    let { username, notifications_enabled, flash = {} }: Props = $props();
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte Inertia - Way 4 "Form Component"
            </h1>
        </div>

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <Form action="/test-form" method="post" class="p-6">
                {#snippet children({
                    errors,
                    hasErrors,
                    processing,
                    wasSuccessful,
                    resetAndClearErrors,
                    isDirty,
                })}
                    <div>
                        <label
                            for="username"
                            class="block text-sm font-medium text-gray-700 mb-2"
                        >
                            Username
                        </label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value={username}
                            class="w-full text-black px-3 py-2 border {errors.username
                                ? 'border-red-500'
                                : 'border-gray-300'} rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
                            placeholder="Enter your username"
                        />
                        {#if errors.username}
                            <p class="mt-1 text-sm text-red-600">
                                {errors.username}
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
                        <div class="flex items-center">
                            <input
                                type="checkbox"
                                id="notifications_enabled"
                                name="notifications_enabled"
                                checked={notifications_enabled}
                                value=1
                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2"
                            />
                            {#if errors.notifications_enabled}
                                <p class="mt-1 text-sm text-red-600">
                                    {errors.notifications_enabled}
                                </p>
                            {/if}
                        </div>
                    </div>

                    {#if wasSuccessful}
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
                                {flash.message}
                            </p>
                        </div>
                    {/if}

                    <div
                        class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200"
                    >
                        <button
                            type="reset"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                            disabled={!isDirty || processing}
                        >
                            Reset
                        </button>

                        <div class="flex space-x-3">
                            <button
                                type="submit"
                                class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                                disabled={processing}
                            >
                                {processing ? "Saving..." : "Save Settings"}
                            </button>
                        </div>
                    </div>
                {/snippet}
            </Form>
        </div>
    </div>
</div>
