<div>
    <x-slot:title>
        Team Danger Zone | Coolify
    </x-slot>

    <x-team.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section id="team-danger-zone" title="Danger zone"
                helper="Destructive actions for this team cannot be undone.">
                <div
                    class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="text-sm font-semibold text-red-700 dark:text-error">Delete team</h4>
                                <x-status-badge status="Permanent" type="error" />
                            </div>

                            @if (session('currentTeam.id') === 0)
                                <p class="mt-2 text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    The default team cannot be deleted.
                                </p>
                            @elseif(auth()->user()->teams()->count() === 1 || auth()->user()->currentTeam()->personal_team)
                                <p class="mt-2 text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    Your last or personal team cannot be deleted.
                                </p>
                            @elseif(currentTeam()->subscription)
                                <p class="mt-2 text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    Cancel your <a class="font-medium text-coollabs hover:underline dark:text-warning"
                                        {{ wireNavigate() }} href="{{ route('subscription.show') }}">subscription</a>
                                    before deleting this team.
                                </p>
                            @elseif(currentTeam()->isEmpty())
                                <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    Permanently delete <strong class="font-semibold text-black dark:text-fg">{{ currentTeam()->name }}</strong>
                                    from Coolify. This action cannot be undone.
                                </p>
                                <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                                    <li>• All members will lose access to this team.</li>
                                    <li>• This team cannot be restored from Coolify after deletion.</li>
                                </ul>
                            @else
                                <p class="mt-2 text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                    Remove or move every resource owned by this team before deleting it.
                                </p>
                            @endif
                        </div>

                        <div class="shrink-0">
                            @if (
                                session('currentTeam.id') !== 0 &&
                                    auth()->user()->teams()->count() > 1 &&
                                    !auth()->user()->currentTeam()->personal_team &&
                                    !currentTeam()->subscription &&
                                    currentTeam()->isEmpty())
                                <x-modal-confirmation title="Confirm Team Deletion?" buttonTitle="Delete team"
                                    isErrorButton submitAction="delete"
                                    :actions="['The current team will be permanently deleted from Coolify and the database.']"
                                    confirmationText="{{ currentTeam()->name }}"
                                    confirmationLabel="Enter the team name to confirm permanent deletion"
                                    shortConfirmationLabel="Team name" :confirmWithPassword="false"
                                    step2ButtonText="Permanently Delete" />
                            @else
                                <x-forms.button disabled tooltip="Resolve the requirements shown before deleting this team.">
                                    Delete team
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                </div>

                @if (session('currentTeam.id') !== 0 && !currentTeam()->subscription && !currentTeam()->isEmpty())
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            'Projects' => currentTeam()->projects,
                            'Servers' => currentTeam()->servers,
                            'Private keys' => currentTeam()->privateKeys,
                            'Sources' => currentTeam()->sources(),
                        ] as $label => $resources)
                            @if ($resources->isNotEmpty())
                                <div class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500 dark:text-fg-faint">
                                        {{ $label }}
                                    </p>
                                    <ul class="mt-2 space-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                        @foreach ($resources as $resource)
                                            <li class="truncate">{{ $resource->name }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-team.settings-layout>
</div>
