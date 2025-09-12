<script lang="ts">
    import { createForm } from "@tanstack/svelte-form";
    import { router } from "@inertiajs/svelte";

    type Props = {
        username: string;
        notifications_enabled: boolean;
        flash?: { message?: string };
    };

    type FormState = {
        username: string;
        notifications_enabled: boolean;
    };

    let { username, notifications_enabled, flash = {} }: Props = $props();

    // Restore form state from Inertia history state or use props as fallback
    const FORM_STATE_KEY = "TANSTACK_FORM_STATE";
    const restoredState = router.restore<FormState>(FORM_STATE_KEY) || { // This TS type is not working yet.
        username: username,
        notifications_enabled: notifications_enabled,
    };

    const form = createForm(() => ({
        defaultValues: restoredState,
        onSubmit: async ({ value }) => {
            router.post("/test-form", value, {
                onSuccess: () => {
                    router.remember(null, FORM_STATE_KEY); // Clear saved state after successful submission
                },
                onError: (errors) => {
                    form.setErrorMap({
                        onSubmit: {
                            fields: errors,
                            form: errors,
                        },
                    });
                },
            });
        },

        // Add listeners to automatically save form state to history
        listeners: {
            onChange: ({ formApi }) => {
                router.remember(formApi.state.values, FORM_STATE_KEY);
            },
        },
    }));

    function handleReset() {
        form.reset();
        // Clear saved state when resetting
        router.remember(null, FORM_STATE_KEY);
    }

    const isSubmitSuccessful = form.useStore(
        (state) => state.isSubmitSuccessful,
    );
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte TanStack - Way 1 "$props()" with history state
            </h1>
        </div>

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <form
                onsubmit={(e) => {
                    e.preventDefault();
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
                                field.state.meta.errors.length === 0}
                            class:border-red-500={field.state.meta.errors
                                .length > 0}
                        />
                        {#if field.state.meta.errors.length > 0}
                            <p class="mt-2 text-sm text-red-600">
                                TanStack Form Errors: {field.state.meta.errors}
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
                                checked={field.state.value as boolean}
                                oninput={(e: Event) => {
                                    const target = e.target as HTMLInputElement;
                                    field.handleChange(target.checked);
                                }}
                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2"
                            />
                        </div>
                        {#if field.state.meta.errors.length > 0}
                            <p class="mt-2 text-sm text-red-600">
                                TanStack Form Errors: {field.state.meta.errors}
                            </p>
                        {/if}
                    {/snippet}
                </form.Field>

                {#if isSubmitSuccessful.current}
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
                        <form.Subscribe
                            selector={(state) => ({
                                isSubmitting: state.isSubmitting,
                            })}
                        >
                            {#snippet children(state)}
                                <button
                                    type="submit"
                                    class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
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
