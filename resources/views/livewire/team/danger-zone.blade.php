<div>
    @php
        $deletionBlockers = currentTeam()->deletionBlockers();
        $blockerDetails = [
            'projects' => ['label' => 'project', 'route' => 'project.index'],
            'servers' => ['label' => 'server', 'route' => 'server.index'],
            'sources' => ['label' => 'Git source', 'route' => 'source.all'],
        ];
    @endphp
    <x-slot:title>
        Team Danger Zone | Coolify
    </x-slot>

    <x-team.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section id="team-danger-zone" title="Danger zone"
                helper="Destructive actions for this team cannot be undone.">
                <x-danger-zone title="Delete team">
                            @if (auth()->user()->roleInTeam(currentTeam()->id) !== 'owner')
                                <p>
                                    Only team owners can delete this team.
                                </p>
                            @elseif (session('currentTeam.id') === 0)
                                <p>
                                    The default team cannot be deleted.
                                </p>
                            @elseif(auth()->user()->teams()->count() === 1 || auth()->user()->currentTeam()->personal_team)
                                <p>
                                    Your last or personal team cannot be deleted.
                                </p>
                            @elseif(currentTeam()->subscription)
                                <p>
                                    Cancel your <a class="font-medium text-coollabs hover:underline dark:text-warning"
                                        {{ wireNavigate() }} href="{{ route('subscription.show') }}">subscription</a>
                                    before deleting this team.
                                </p>
                            @elseif($deletionBlockers === [])
                                <p>
                                    Permanently delete <strong class="font-semibold text-black dark:text-fg">{{ currentTeam()->name }}</strong>
                                    from Coolify. This action cannot be undone.
                                </p>
                                <ul class="space-y-1 text-xs">
                                    <li>• All members will lose access to this team.</li>
                                    <li>• This team cannot be restored from Coolify after deletion.</li>
                                </ul>
                            @else
                                <p>
                                    This team still owns:
                                </p>
                                <ul class="space-y-1">
                                    @foreach ($deletionBlockers as $type => $count)
                                        <li>
                                            <a class="font-medium text-coollabs hover:underline dark:text-warning"
                                                {{ wireNavigate() }} href="{{ route($blockerDetails[$type]['route']) }}">
                                                {{ $count }} {{ str($blockerDetails[$type]['label'])->plural($count) }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                                <p>
                                    Remove or move these resources before deleting the team.
                                </p>
                            @endif
                        <x-slot:action>
                            @if (
                                session('currentTeam.id') !== 0 &&
                                    auth()->user()->roleInTeam(currentTeam()->id) === 'owner' &&
                                    auth()->user()->teams()->count() > 1 &&
                                    !auth()->user()->currentTeam()->personal_team &&
                                    !currentTeam()->subscription &&
                                    $deletionBlockers === [])
                                <x-modal-confirmation title="Confirm Team Deletion?" buttonTitle="Delete team"
                                    isErrorButton submitAction="delete"
                                    :actions="['The current team will be permanently deleted from Coolify and the database.']"
                                    confirmationText="{{ currentTeam()->name }}"
                                    confirmationLabel="Enter the team name to confirm permanent deletion"
                                    shortConfirmationLabel="Team name" :confirmWithPassword="false"
                                    step2ButtonText="Permanently Delete" canGate="delete"
                                    :canResource="$team" />
                            @else
                                <x-forms.button isError disabled tooltip="Resolve the requirements shown before deleting this team.">
                                    Delete team
                                </x-forms.button>
                            @endif
                        </x-slot:action>
                </x-danger-zone>

                @if (session('currentTeam.id') !== 0 && !currentTeam()->subscription && (currentTeam()->projects->isNotEmpty() || currentTeam()->servers->isNotEmpty()))
                    <div class="mt-4 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.08]">
                            <h5 class="text-sm font-medium text-black dark:text-fg">Resources</h5>
                            <x-forms.button type="button" wire:click="refreshResources">
                                <x-reicon name="refresh" class="size-3.5" />
                                Refresh
                            </x-forms.button>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Resource</th>
                                    <th class="px-3 py-2 font-medium">Name</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                                @foreach (currentTeam()->projects as $project)
                                    <tr class="text-[13px] text-neutral-600 hover:bg-neutral-50 dark:text-fg-dim dark:hover:bg-white/[0.03]">
                                        <td>
                                            <a class="block px-3 py-2.5" href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}"
                                                target="_blank" rel="noopener noreferrer">Project</a>
                                        </td>
                                        <td>
                                            <a class="block px-3 py-2.5 font-medium text-black dark:text-fg"
                                                href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}"
                                                target="_blank" rel="noopener noreferrer">{{ $project->name }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach (currentTeam()->servers as $server)
                                    <tr class="text-[13px] text-neutral-600 hover:bg-neutral-50 dark:text-fg-dim dark:hover:bg-white/[0.03]">
                                        <td>
                                            <a class="block px-3 py-2.5" href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                                                target="_blank" rel="noopener noreferrer">Server</a>
                                        </td>
                                        <td>
                                            <a class="block px-3 py-2.5 font-medium text-black dark:text-fg"
                                                href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                                                target="_blank" rel="noopener noreferrer">{{ $server->name }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-team.settings-layout>
</div>
