<div>
    <x-slot:title>
        Teams | Coolify
    </x-slot>

    <x-team.navbar />

    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="General"
                description="Manage this team's identity and shared API access.">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="name" label="Name" required canGate="update" :canResource="$team" />
                    <x-forms.input id="description" label="Description" canGate="update" :canResource="$team" />
                    <div class="lg:col-span-2">
                        <x-forms.listbox id="is_mcp_server_enabled" label="MCP server"
                            helper="Controls whether this team's API tokens can use the instance MCP endpoint."
                            :disabled="! auth()->user()->can('update', $team)" :options="[
                                ['value' => false, 'label' => 'Disabled for this team'],
                                ['value' => true, 'label' => 'Enabled for this team'],
                            ]" />
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        @can('delete', $team)
            <section
                class="overflow-hidden rounded-xl border border-red-200 bg-red-50/60 shadow-sm dark:border-red-500/20 dark:bg-red-500/[0.04]">
                <header
                    class="flex min-h-12 items-center justify-between border-b border-red-200 px-4 dark:border-red-500/15">
                    <div>
                        <h3 class="text-[13px]! font-semibold! text-red-700 dark:text-red-300">Danger zone</h3>
                        <p class="mt-0.5 text-[11px] text-red-600/75 dark:text-red-300/60">
                            Destructive actions for this team.
                        </p>
                    </div>
                </header>

                <div class="bg-white/70 p-4 dark:bg-black/10">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <h4 class="text-[13px] font-semibold text-black dark:text-fg">Delete team</h4>

                            @if (session('currentTeam.id') === 0)
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    The default team cannot be deleted.
                                </p>
                            @elseif(auth()->user()->teams()->get()->count() === 1 || auth()->user()->currentTeam()->personal_team)
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    Your last or personal team cannot be deleted.
                                </p>
                            @elseif(currentTeam()->subscription)
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    Cancel your
                                    <a class="font-medium text-coollabs hover:underline dark:text-warning"
                                        {{ wireNavigate() }} href="{{ route('subscription.show') }}">subscription</a>
                                    before deleting this team.
                                </p>
                            @elseif(currentTeam()->isEmpty())
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    Permanently remove this team. This action cannot be undone.
                                </p>
                            @else
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    Remove the resources below before deleting this team.
                                </p>
                            @endif
                        </div>

                        @if (
                            session('currentTeam.id') !== 0 &&
                                auth()->user()->teams()->get()->count() > 1 &&
                                !auth()->user()->currentTeam()->personal_team &&
                                !currentTeam()->subscription &&
                                currentTeam()->isEmpty())
                            <div class="shrink-0">
                                <x-modal-confirmation title="Confirm Team Deletion?" buttonTitle="Delete team"
                                    isErrorButton submitAction="delete({{ currentTeam()->id }})"
                                    :actions="['The current team will be permanently deleted from Coolify and the database.']"
                                    confirmationText="{{ currentTeam()->name }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Team Name below"
                                    shortConfirmationLabel="Team Name" :confirmWithPassword="false"
                                    step2ButtonText="Permanently Delete" />
                            </div>
                        @endif
                    </div>

                    @if (
                        session('currentTeam.id') !== 0 &&
                            !currentTeam()->subscription &&
                            !currentTeam()->isEmpty())
                        <div class="mt-4 grid gap-3 border-t border-red-200 pt-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-red-500/15">
                            @if (currentTeam()->projects()->count() > 0)
                                <div class="rounded-lg border border-red-200/80 bg-white p-3 dark:border-red-500/15 dark:bg-white/[0.025]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">
                                        Projects
                                    </p>
                                    <ul class="mt-2 space-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                        @foreach (currentTeam()->projects as $resource)
                                            <li class="truncate">{{ $resource->name }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if (currentTeam()->servers()->count() > 0)
                                <div class="rounded-lg border border-red-200/80 bg-white p-3 dark:border-red-500/15 dark:bg-white/[0.025]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">
                                        Servers
                                    </p>
                                    <ul class="mt-2 space-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                        @foreach (currentTeam()->servers as $resource)
                                            <li class="truncate">{{ $resource->name }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if (currentTeam()->privateKeys()->count() > 0)
                                <div class="rounded-lg border border-red-200/80 bg-white p-3 dark:border-red-500/15 dark:bg-white/[0.025]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">
                                        Private keys
                                    </p>
                                    <ul class="mt-2 space-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                        @foreach (currentTeam()->privateKeys as $resource)
                                            <li class="truncate">{{ $resource->name }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if (currentTeam()->sources()->count() > 0)
                                <div class="rounded-lg border border-red-200/80 bg-white p-3 dark:border-red-500/15 dark:bg-white/[0.025]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">
                                        Sources
                                    </p>
                                    <ul class="mt-2 space-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                        @foreach (currentTeam()->sources as $resource)
                                            <li class="truncate">{{ $resource->name }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        @endcan
    </div>
</div>
