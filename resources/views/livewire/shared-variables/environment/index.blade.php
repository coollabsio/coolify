<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>

    <x-dashboard.navbar section="shared-variables" />

    <div class="w-full">
        <h1 class="mb-5 text-[24px]! leading-7! font-semibold! tracking-tight!">Environment variables</h1>

    @if ($projects->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="layers" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No environments yet</h2>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Create a project environment before adding environment-wide variables.
            </p>
        </div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($projects as $project)
                <section>
                    <div class="mb-3">
                        <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                            {{ $project->name }}
                        </h2>
                        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                            {{ $project->description ?: 'Project environments' }}
                        </p>
                    </div>

                    @if ($project->environments->isEmpty())
                        <div
                            class="rounded-xl border border-dashed border-neutral-300 px-4 py-8 text-center text-[12px] text-neutral-500 dark:border-white/[0.1] dark:text-fg-dim">
                            No environments in this project.
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($project->environments as $environment)
                                <a class="group flex min-h-24 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                                    href="{{ route('shared-variables.environment.show', [
                                        'project_uuid' => $project->uuid,
                                        'environment_uuid' => $environment->uuid,
                                    ]) }}"
                                    {{ wireNavigate() }}>
                                    <div class="flex items-start gap-3">
                                        <div
                                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                            <x-reicon name="layers" class="size-4" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3
                                                class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                                {{ $environment->name }}
                                            </h3>
                                            <p
                                                class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                {{ $environment->description ?: 'No description' }}
                                            </p>
                                        </div>
                                    </div>
                                    <x-reicon name="arrow-right"
                                        class="mt-auto size-3.5 self-end pt-3 text-neutral-400 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
    </div>
</div>
