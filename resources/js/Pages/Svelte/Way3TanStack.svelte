<script lang="ts">
    import { createForm } from "@tanstack/svelte-form";
    import { useForm } from "@inertiajs/svelte";

    type Props = {
        username: string;
        notifications_enabled: boolean;
    };

    let { username, notifications_enabled }: Props = $props(); // get props like in Way 1.

    // Way 3 -> Using useForm() helper from Inertia. Docs: https://inertiajs.com/forms#form-helper.
    const inertiaForm = useForm({
        username,
        notifications_enabled,
    });

    const form = createForm(() => ({
        defaultValues: $inertiaForm.data,
        onSubmit: () => {
            $inertiaForm.post("/test-form", {
                // use the useForm() helper from Inertia to submit the form. --> This conflicts with TanStack as the value is not used?
                preserveScroll: true,
            });
        },
    }));

    function handleReset() {
        form.reset(); // This now has NO effect. So TanStack is not working with the useForm() helper from Inertia?!
        $inertiaForm.resetAndClearErrors(); // Reset the form and clear the form & errors via Inertia.
    }

    function clearErrors() {
        $inertiaForm.clearErrors(); // Clear the errors via Inertia.
    }
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte TanStack - Way 3 "useForm() Helper"
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
                        <!-- Way 3 -> When using intertia form helper we need to set the value from inertia which conflicts with TanStack since we are no longer doing value="{field.state.value}" we are doing "bind:value={$inertiaForm.username}". -->
                        <input
                            id={field.name}
                            type="text"
                            bind:value={$inertiaForm.username}
                            placeholder="John Doe"
                            oninput={(e: Event) => {
                                const target = e.target as HTMLInputElement;
                                field.handleChange(target.value);
                            }}
                            class="w-full text-black px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-colors duration-200"
                            class:border-yellow-400={field.state.meta.isDirty &&
                                !$inertiaForm.errors.username}
                            class:border-red-500={$inertiaForm.errors
                                .username || field.state.meta.errors.length > 0}
                        />
                        {#if $inertiaForm.errors.username}
                            <!-- Getting the errors from the $inertiaForm.errors state. Which can be reset because it we use the useForm() helper from Inertia. -->
                            <p class="mt-2 text-sm text-red-600">
                                From $inertiaForm.errors: {$inertiaForm.errors
                                    .username}
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
                            <!-- Way 3 -> Same as above. When using intertia form helper we need to set the value from inertia which conflicts with TanStack since we are not using the value for the form. -->
                            <input
                                id={field.name}
                                type="checkbox"
                                bind:checked={
                                    $inertiaForm.notifications_enabled
                                }
                                oninput={(e: Event) => {
                                    const target = e.target as HTMLInputElement;
                                    field.handleChange(target.checked);
                                }}
                                class="h-4 w-4 text-orange-600 border-gray-300 rounded focus:ring-orange-500 focus:ring-2"
                            />
                        </div>
                    {/snippet}
                </form.Field>

                {#if $inertiaForm.recentlySuccessful}
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
                            Settings saved successfully with useForm()!
                        </p>
                    </div>
                {/if}

                <div
                    class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200"
                >
                    <form.Subscribe
                        selector={(state) => ({
                            isDirty: state.isDirty,
                        })}
                    >
                        {#snippet children(state)}
                            <button
                                type="button"
                                onclick={handleReset}
                                disabled={(!state.isDirty &&
                                    !$inertiaForm.isDirty) ||
                                    $inertiaForm.processing}
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                            >
                                Reset
                            </button>
                        {/snippet}
                    </form.Subscribe>

                    <div class="flex space-x-3">
                        {#if Object.keys($inertiaForm.errors).length > 0}
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
                            })}
                        >
                            {#snippet children(state)}
                                <button
                                    type="submit"
                                    disabled={!state.canSubmit ||
                                        $inertiaForm.processing}
                                    class="px-6 py-2 text-sm font-medium text-white bg-orange-600 border border-transparent rounded-md shadow-sm hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                                >
                                    {#if $inertiaForm.processing}
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
