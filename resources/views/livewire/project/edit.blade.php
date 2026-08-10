<div>
    <x-slot:title>{{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify</x-slot>
    <x-project.navbar :project="$project" />

    <div class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Project details</h2>
                        <p>Name and describe this project across the dashboard.</p>
                    </div>
                </div>
                <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                    <x-forms.input label="Name" id="name" />
                    <x-forms.input label="Description" id="description" />
                </div>
            </section>
        </form>

        <section
            class="overflow-hidden rounded-[10px] border border-red-300 bg-red-50/80 dark:border-red-500/25 dark:bg-red-500/[0.06]">
            <div class="flex items-start justify-between gap-4 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">Delete project</h2>
                    <p class="mt-1 max-w-2xl text-sm text-red-700/80 dark:text-red-200/70">
                        Empty the project before permanently deleting it.
                    </p>
                </div>
                <livewire:project.delete-project :disabled="! $project->isEmpty()" :project_id="$project->id" />
            </div>
        </section>
    </div>
</div>
