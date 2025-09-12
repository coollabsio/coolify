<script lang="ts">
    import { createForm } from "@tanstack/svelte-form";
    import { router, page } from "@inertiajs/svelte";

    // Way 2 -> $page.props -> Accessing the props of the page via Inertia.
    type FormValues = {
        username: string;
        notifications_enabled: boolean;
    };

    const defaultValues: FormValues = {
        // These show a type error if the type is "unknown" and not "any". Fix PR: https://github.com/inertiajs/inertia/pull/2520
        username: $page.props.username,
        notifications_enabled: $page.props.notifications_enabled,
    };

    // Manual tracking state for this approach
    let submitSuccess = $state(false);
    let submitErrors = $state<Record<string, string>>({});

    const form = createForm(() => ({
        defaultValues: defaultValues,
        onSubmit: async ({ value }) => {
            // Way 2 -> Manual form submission via Inertia router.
            router.post("/test-form", value, {
                preserveScroll: true,
                onSuccess: () => {
                    submitSuccess = true;
                },
                onError: (errors) => {
                    submitErrors = errors;
                },
            });
        },
    }));

    function handleReset() {
        form.reset();
        submitSuccess = false;
        submitErrors = {};
    }

    function clearErrors() {
        submitErrors = {};
    }
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte TanStack - Way 2 "$page.props"
            </h1>
        </div>

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    form.handleSubmit();
                }}
                class="p-6"
            >
                <form.Field name="username">
                    {#snippet children(field)}
                        <label
                            for={field.name}
                            class="block text-sm font-medium text-gray-700 mb-2"
                        >
                            Username
                        </label>
                        <input
                            id={field.name}
                            type="text"
                            value={field.state.value}
                            placeholder="John Doe"
                            oninput={(e: Event) => {
                                const target = e.target as HTMLInputElement;
                                field.handleChange(target.value);
                            }}
                            class="w-full text-black px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
                            class:border-yellow-400={field.state.meta.isDirty &&
                                !submitErrors.username}
                            class:border-red-500={submitErrors.username ||
                                field.state.meta.errors.length > 0}
                        />
                        {#if $page.props.errors.username}
                            <!-- Getting the errors from the $props(). Which can NOT be reset because it is a prop from Inertia. -->
                            <p class="mt-2 text-sm text-red-600">
                                From $page.props.errors: {$page.props.errors
                                    .username} - This can NOT be reset because it
                                is a prop from Inertia.
                            </p>
                        {/if}
                    {/snippet}
                </form.Field>

                <form.Field name="notifications_enabled">
                    {#snippet children(field)}
                        <div class="flex items-center justify-between py-4">
                            <div class="flex flex-col">
                                <label
                                    for={field.name}
                                    class="text-sm font-medium text-gray-900"
                                >
                                    Enable Notifications
                                </label>
                                <p class="text-sm text-gray-500">
                                    Receive notifications about your account
                                </p>
                            </div>
                            <input
                                id={field.name}
                                type="checkbox"
                                checked={field.state.value}
                                oninput={(e: Event) => {
                                    const target = e.target as HTMLInputElement;
                                    field.handleChange(target.checked);
                                }}
                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2"
                            />
                        </div>
                    {/snippet}
                </form.Field>

                {#if submitSuccess}
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
                        <!-- This shows a type error if the type is "unknown" and not "any". Fix PR: https://github.com/inertiajs/inertia/pull/2520 -->
                        {#if $page.props.flash.message}
                            <p class="ml-3 text-sm font-medium text-green-800">
                                {$page.props.flash.message}
                            </p>
                        {/if}
                    </div>
                {/if}

                <div
                    class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200"
                >
                    <form.Subscribe
                        selector={(state) => ({
                            isDirty: state.isDirty,
                            isSubmitting: state.isSubmitting,
                        })}
                    >
                        {#snippet children(state)}
                            <button
                                type="button"
                                onclick={handleReset}
                                disabled={!state.isDirty || state.isSubmitting}
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                            >
                                Reset
                            </button>
                        {/snippet}
                    </form.Subscribe>

                    <div class="flex space-x-3">
                        {#if Object.keys(submitErrors).length > 0}
                            <button
                                type="button"
                                onclick={clearErrors}
                                class="px-4 py-2 text-sm font-medium text-red-700 bg-red-50 border border-red-300 rounded-md shadow-sm hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200"
                            >
                                Clear Errors
                            </button>
                        {/if}

                        <form.Subscribe
                            selector={(state) => ({
                                canSubmit: state.canSubmit,
                                isSubmitting: state.isSubmitting,
                            })}
                        >
                            {#snippet children(state)}
                                <button
                                    type="submit"
                                    disabled={!state.canSubmit}
                                    class="px-6 py-2 text-sm font-medium text-white bg-green-600 border border-transparent rounded-md shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                                >
                                    {#if state.isSubmitting}
                                        Saving...
                                    {:else}
                                        Save Settings
                                    {/if}
                                </button>
                            {/snippet}
                        </form.Subscribe>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
