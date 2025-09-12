<script lang="ts">
    import { createForm, revalidateLogic } from "@tanstack/svelte-form";
    import { router } from "@inertiajs/svelte";
    import { storeUserSchema } from "./schema/storeUserSchema";

    type Props = {
        username: string;
        notifications_enabled: boolean;
        flash?: { message?: string };
    };

    let { username, notifications_enabled, flash = {} }: Props = $props(); // Way 1 -> $props() -> Accessing the props of the page via svelte (passed from Inertia).

    const form = createForm(() => ({
        defaultValues: {
            username: username,
            notifications_enabled: notifications_enabled,
        },
        validationLogic: revalidateLogic(),
        validators: {
            onDynamic: storeUserSchema, // First submit the form and then onChange Zod validation.
        },
        onSubmit: async ({ value }) => {
            // Way 1 -> Manual form submission via Inertia router.
            return new Promise((resolve, reject) => {
                router.post("/test-form", value, {
                    onSuccess: () => {
                        resolve(undefined);
                    },
                    onError: (errors) => {
                        form.setErrorMap({
                            onSubmit: {
                                fields: errors,
                                form: errors,
                            },
                        });
                        reject(errors);
                    },
                });
            });
        },
    }));

    function handleReset() {
        form.reset();
    }

    const isSubmitSuccessful = form.useStore((state) => state.isSubmitSuccessful);

    // Debug states.
    const isSubmitting = form.useStore((state) => state.isSubmitting);
    const isValid = form.useStore((state) => state.isValid);
    const canSubmit = form.useStore((state) => state.canSubmit);
    const errorMap = form.useStore((state) => state.errorMap);
    const errors = form.useStore((state) => state.errors);
</script>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Svelte TanStack - Way 1 "$props()"
            </h1>
        </div>

        <!-- Debug states. -->
        <div class="mb-6 bg-blue-50 border border-blue-200 p-4 rounded-md">
            <h3 class="text-lg font-medium text-blue-800 mb-2">
                Debug - TanStack Form State:
            </h3>
            <div class="text-sm text-blue-700 space-y-1">
                <p>
                    <strong>form.state.isSubmitSuccessful:</strong
                    >{isSubmitSuccessful.current}
                </p>

                <p>
                    <strong>form.state.isSubmitting:</strong>
                    {isSubmitting.current}
                </p>
                <p><strong>form.state.isValid:</strong> {isValid.current}</p>
                <p>
                    <strong>form.state.canSubmit:</strong>
                    {canSubmit.current}
                </p>
                <p>
                    <strong>form.state.errorMap:</strong>
                    {JSON.stringify(errorMap.current)}
                </p>
                <p>
                    <strong>form.state.errors:</strong>
                    {JSON.stringify(errors.current)}
                </p>
            </div>
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

                        {#if !field.state.meta.isValid}
                            <div class="mt-2 space-y-1">
                                {#each field.state.meta.errors as error}
                                    <p class="text-sm text-red-600">
                                        {typeof error === "string"
                                            ? error
                                            : error.message}
                                    </p>
                                {/each}
                            </div>
                        {/if}

                        <!-- Debug states. -->
                        <div
                            class="mt-2 text-xs text-gray-500 bg-gray-50 p-2 rounded"
                        >
                            <p>
                                <strong>field.state.meta.isValid:</strong>
                                {field.state.meta.isValid}
                            </p>
                            <p>
                                <strong>field.state.meta.errors:</strong>
                                {JSON.stringify(field.state.meta.errors)}
                            </p>
                            <p>
                                <strong
                                    >field.state.meta.errorMap.onSubmit:</strong
                                >
                                {JSON.stringify(
                                    field.state.meta.errorMap.onSubmit ||
                                        "undefined",
                                )}
                            </p>
                            <p>
                                <strong
                                    >field.state.meta.errorMap.onChange</strong
                                >
                                {JSON.stringify(
                                    field.state.meta.errorMap.onChange ||
                                        "undefined",
                                )}
                            </p>
                        </div>
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
                            <!-- Using TanStack Form's built-in error management -->
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
                                canSubmit: state.canSubmit,
                            })}
                        >
                            {#snippet children(state)}
                                <button
                                    type="submit"
                                    disabled={!state.canSubmit}
                                    class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200 disabled:bg-gray-400"
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
