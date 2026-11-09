<div>
    <x-slot:title>{{ data_get_str($environment, 'name')->limit(10) }} > coolify.json | Coolify</x-slot>
    <div class="w-full max-w-none">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">coolify.json</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Configuration file of {{ $environment->name }} in {{ $project->name }}
                </p>
            </div>
            <div class="flex w-fit shrink-0 items-center gap-2">
                <x-forms.button wire:click="downloadExport">Download export</x-forms.button>
                @if ($link && $isTeamAdmin)
                    @if ($link->isSuspended())
                        <x-forms.button wire:click="previewResume">Resume link</x-forms.button>
                    @else
                        <x-forms.button wire:click="suspend">Suspend link</x-forms.button>
                    @endif
                @endif
            </div>
        </header>

        <div class="flex flex-col gap-6">
            @if (! $link)
                <x-callout type="info" title="Not linked">
                    No coolify.json file manages this environment. Export the current settings, commit the file, and link it with
                    <code>coolify config link</code> or <code>POST /api/v1/config/links</code>.
                </x-callout>
            @else
                @if ($link->isSuspended())
                    <x-callout type="warning" title="Link suspended">
                        Pushes are planned but not applied, and settings can be edited in Coolify. Resume the link to let the file manage them again.
                    </x-callout>
                @endif
                @if ($resumePlan !== null)
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>Resume the link</h2>
                                <p>The file changes these settings back when the link resumes.</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body flex flex-col gap-3">
                            @include('livewire.project.partials.config-plan-changes', ['changes' => $resumePlan['changes'] ?? []])
                            <div><x-forms.button isHighlighted wire:click="confirmResume">Resume and apply</x-forms.button></div>
                        </div>
                    </section>
                @endif

                <section class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div>
                            <h2>Link</h2>
                            <p>Where Coolify reads the file.</p>
                        </div>
                    </div>
                    <dl class="application-settings-section-body grid gap-3 text-[13px] sm:grid-cols-2">
                        <div><dt class="text-neutral-500 dark:text-fg-dim">Mode</dt><dd>{{ $link->isGit() ? 'Git push' : 'API' }}</dd></div>
                        @if ($link->isGit())
                            <div><dt class="text-neutral-500 dark:text-fg-dim">Repository</dt><dd class="break-all">{{ $link->repository }}</dd></div>
                            <div><dt class="text-neutral-500 dark:text-fg-dim">Branch</dt><dd>{{ $link->branch }}</dd></div>
                            <div><dt class="text-neutral-500 dark:text-fg-dim">File</dt><dd>{{ $link->file_path }}</dd></div>
                        @endif
                        <div><dt class="text-neutral-500 dark:text-fg-dim">Safe changes</dt><dd>{{ $link->auto_apply ? 'Applied automatically' : 'Wait for approval' }}</dd></div>
                        <div><dt class="text-neutral-500 dark:text-fg-dim">Last applied</dt><dd>{{ $link->lastAppliedRevision?->applied_at?->diffForHumans() ?? 'Never' }}</dd></div>
                        @if ($link->isGit() && $isTeamAdmin)
                            @php($webhook = \App\Http\Controllers\Api\ProjectConfigController::webhookDetails($link))
                            <div class="sm:col-span-2">
                                <dt class="text-neutral-500 dark:text-fg-dim">Manual webhook (for repositories without a GitHub App)</dt>
                                <dd class="break-all">
                                    Secret: <code>{{ $webhook['webhook_secret'] }}</code><br>
                                    @foreach ($webhook['webhook_urls'] as $provider => $url)
                                        <span class="block">{{ ucfirst($provider) }}: <code>{{ $url }}</code></span>
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    </dl>
                </section>

                @if ($waiting)
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>Waiting for approval</h2>
                                <p>Commit {{ $waiting->commit_sha ? substr($waiting->commit_sha, 0, 7) : 'from the API' }}. Destructive changes need a person to approve them.</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body flex flex-col gap-4">
                            @include('livewire.project.partials.config-plan-changes', ['changes' => $waiting->plan['changes'] ?? []])
                            @php($removed = collect($waiting->plan['changes'] ?? [])->where('kind', 'remove')->pluck('key'))
                            @if ($removed->isNotEmpty())
                                <div class="flex flex-col gap-2">
                                    <p class="text-[13px]">The resources stop and leave Coolify. Their data stays, unless you select it here:</p>
                                    @foreach ($removed as $key)
                                        <label class="flex items-center gap-2 text-[13px]" wire:key="delete-data-{{ $key }}">
                                            <input type="checkbox" wire:model="deleteData" value="{{ $key }}" @disabled(! $canApprove)>
                                            Also delete the data of <code>{{ $key }}</code>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            <div>
                                @if ($canApprove)
                                    <x-modal-confirmation title="Approve this plan?" buttonTitle="Approve plan" isErrorButton
                                        submitAction="approve('{{ $waiting->uuid }}')"
                                        :actions="['Apply every change of this plan to the environment.']"
                                        :confirmWithText="false" />
                                @else
                                    <x-forms.button disabled tooltip="Approving needs delete access to this environment.">Approve plan</x-forms.button>
                                @endif
                            </div>
                        </div>
                    </section>
                @endif

                @if ($latest && ! empty($latest->plan['missing_required']))
                    <x-callout type="warning" title="Required values are missing">
                        Set these values in Coolify, then deploy:
                        @foreach ($latest->plan['missing_required'] as $key => $names)
                            <span class="block"><code>{{ $key }}</code>: {{ implode(', ', $names) }}</span>
                        @endforeach
                    </x-callout>
                @endif
                @foreach ($latest->plan['warnings'] ?? [] as $warning)
                    <x-callout type="info" title="Not managed">{{ $warning }}</x-callout>
                @endforeach

                <section class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div>
                            <h2>Revisions</h2>
                            <p>Each push or API call makes one revision.</p>
                        </div>
                    </div>
                    <div class="application-settings-section-body">
                        <table class="w-full text-left text-[13px]">
                            <thead class="text-neutral-500 dark:text-fg-dim">
                                <tr><th class="py-1">Status</th><th>Commit</th><th>Trigger</th><th>When</th><th></th></tr>
                            </thead>
                            <tbody>
                                @forelse ($revisions as $revision)
                                    <tr wire:key="revision-{{ $revision->uuid }}" class="border-t border-neutral-200 dark:border-coolgray-300">
                                        <td class="py-1.5">{{ str_replace('_', ' ', $revision->status->value) }}</td>
                                        <td><code>{{ $revision->commit_sha ? substr($revision->commit_sha, 0, 7) : '-' }}</code></td>
                                        <td>{{ $revision->trigger }}</td>
                                        <td>{{ $revision->created_at?->diffForHumans() }}</td>
                                        <td class="text-right">
                                            @if ($revision->status === \App\Enums\ConfigRevisionStatus::Applying && $isTeamAdmin)
                                                <x-forms.button wire:click="release('{{ $revision->uuid }}')">Release</x-forms.button>
                                            @elseif ($revision->status === \App\Enums\ConfigRevisionStatus::Failed && $revision->file_contents !== null)
                                                <x-forms.button wire:click="retry('{{ $revision->uuid }}')" canGate="update" :canResource="$environment">Retry</x-forms.button>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($revision->error)
                                        <tr wire:key="revision-error-{{ $revision->uuid }}"><td colspan="5" class="pb-2 text-red-500">{{ $revision->error }}</td></tr>
                                    @endif
                                @empty
                                    <tr><td colspan="5" class="py-2 text-neutral-500">No revisions yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
