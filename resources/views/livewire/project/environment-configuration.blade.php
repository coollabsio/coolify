<div>
    @if ($link && (($status['state'] ?? null) === 'applying' || (($status['state'] ?? null) === 'waiting_first' && $link->created_at?->gt(now()->subMinutes(5)))))
        <div wire:poll.3s wire:key="config-sync-poll" class="hidden"></div>
    @endif
    <x-slot:title>{{ data_get_str($environment, 'name')->limit(10) }} > Config as code | Coolify</x-slot>
    <div class="w-full max-w-none">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Config as code</h1>
                @if ($link)
                    <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                        <x-project-config.sync-badge :status="$status" />
                        @if ($link->isGit())
                            <span class="min-w-0 truncate">
                                <span class="text-neutral-700 dark:text-fg">{{ $link->repository }}</span>
                                · {{ $link->branch }} · {{ $link->file_path }}
                            </span>
                        @else
                            <span>Applied through the API</span>
                        @endif
                    </div>
                @else
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                        Manage {{ $environment->name }} with a coolify.json file in a git repository.
                    </p>
                @endif
            </div>
            <div class="flex w-fit shrink-0 items-center gap-2">
                <x-forms.button wire:click="downloadExport" title="Download coolify.json with the current settings">
                    <x-reicon name="download" class="size-3.5" />
                    Export
                </x-forms.button>
                @if ($link && $isTeamAdmin)
                    @if ($link->isSuspended())
                        <x-forms.button wire:click="previewResume">Resume sync</x-forms.button>
                    @else
                        <x-forms.button wire:click="suspend" title="Edits in Coolify work again while the sync is paused.">
                            Pause sync
                        </x-forms.button>
                    @endif
                @endif
                @if (! $link && $isTeamAdmin)
                    <x-modal-input title="Connect repository" buttonTitle="Connect repository" isHighlightedButton
                        :wireIgnore="false" isLarge>
                        @include('livewire.project.partials.config-connect-form')
                    </x-modal-input>
                @endif
            </div>
        </header>

        <div class="flex flex-col gap-6">
            @if (! $link)
                <x-application.settings-section title="How it works"
                    helper="The file describes every resource of this environment. A push changes them, and Coolify shows every change first.">
                    <ol class="grid gap-4 text-[13px] sm:grid-cols-3">
                        @foreach ([
                            ['download', 'Download the file', 'Coolify writes the current settings of this environment. Secret values stay out of it.'],
                            ['upload', 'Commit it', 'Put coolify.json in the repository and branch that this environment follows.'],
                            ['link', 'Connect the repository', 'Coolify plans the file. Safe changes apply on each push; removals wait for you.'],
                        ] as $index => [$icon, $title, $text])
                            <li class="flex gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon :name="$icon" class="size-3.5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="font-medium text-black dark:text-fg">{{ $index + 1 }}. {{ $title }}</p>
                                    <p class="mt-0.5 text-[12px] leading-4 text-neutral-500 dark:text-fg-dim">{{ $text }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    @unless ($isTeamAdmin)
                        <p class="mt-4 text-[12px] text-neutral-500 dark:text-fg-faint">Only a team admin can connect a repository.</p>
                    @endunless
                </x-application.settings-section>
            @else
                @if ($link->isSuspended())
                    <x-callout type="warning" title="Sync paused">
                        Pushes are planned but not applied, and the settings can be edited in Coolify. Resume the sync to let the file manage them again.
                    </x-callout>
                @endif

                @if ($resumePlan !== null)
                    <x-application.settings-section title="Resume the sync" flush
                        helper="These settings change back to the file when the sync resumes.">
                        <x-slot:actions>
                            <x-forms.button wire:click="$set('resumePlan', null)">Cancel</x-forms.button>
                            <x-forms.button isHighlighted wire:click="confirmResume">Resume and apply</x-forms.button>
                        </x-slot:actions>
                        <x-project-config.plan-groups :changes="$resumePlan['changes'] ?? []" />
                    </x-application.settings-section>
                @endif

                @if ($status['state'] === 'failed' && $latest)
                    <x-callout type="danger" title="The last sync failed">
                        {{ $latest->error }}
                        @if ($latest->file_contents !== null)
                            <button type="button" wire:click="retry('{{ $latest->uuid }}')"
                                class="ml-1 font-semibold underline-offset-2 hover:underline">Try again</button>
                        @endif
                    </x-callout>
                @endif

                @if ($waiting)
                    @php($changeCount = count($waiting->plan['changes'] ?? []))
                    <x-application.settings-section title="Changes waiting for approval" flush
                        helper="{{ $waiting->commit_sha ? 'Commit '.substr($waiting->commit_sha, 0, 7).'. ' : '' }}Removed resources stop and leave Coolify. Their data stays unless you select it.">
                        <x-project-config.plan-groups :changes="$waiting->plan['changes'] ?? []" deleteDataModel="deleteData" :canDelete="$canApprove" />
                        <div class="flex items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.06]">
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ $changeCount }} {{ $changeCount === 1 ? 'change' : 'changes' }}. Deploys from this push wait for the approval.
                            </p>
                            @if ($canApprove)
                                <x-modal-confirmation title="Approve and apply?" buttonTitle="Approve and apply" isErrorButton
                                    submitAction="approve('{{ $waiting->uuid }}')"
                                    :actions="['Apply every change of this plan to the environment.']" :confirmWithText="false" />
                            @else
                                <x-forms.button disabled tooltip="Approving needs delete access to this environment.">Approve and apply</x-forms.button>
                            @endif
                        </div>
                    </x-application.settings-section>
                @endif

                @if ($latest && ! empty($latest->plan['missing_required']))
                    <x-callout type="warning" title="Values needed">
                        Set these variables in Coolify, then deploy:
                        @foreach ($latest->plan['missing_required'] as $key => $names)
                            <span class="block"><code>{{ $key }}</code>: {{ implode(', ', $names) }}</span>
                        @endforeach
                    </x-callout>
                @endif

                <x-application.settings-section title="Source" helper="Where Coolify reads the file.">
                    <dl class="grid gap-x-6 gap-y-3 text-[13px] sm:grid-cols-2 lg:grid-cols-3">
                        @if ($link->isGit())
                            <div class="min-w-0"><dt class="text-[11px] text-neutral-500 dark:text-fg-faint">Repository</dt><dd class="truncate" title="{{ $link->repository }}">{{ $link->repository }}</dd></div>
                            <div><dt class="text-[11px] text-neutral-500 dark:text-fg-faint">Branch</dt><dd>{{ $link->branch }}</dd></div>
                            <div><dt class="text-[11px] text-neutral-500 dark:text-fg-faint">File</dt><dd>{{ $link->file_path }}</dd></div>
                            <div><dt class="text-[11px] text-neutral-500 dark:text-fg-faint">Read on</dt><dd>{{ $link->readServer?->name ?? 'No server' }}</dd></div>
                        @else
                            <div><dt class="text-[11px] text-neutral-500 dark:text-fg-faint">Mode</dt><dd>Applied through the API</dd></div>
                        @endif
                        <div>
                            <dt class="text-[11px] text-neutral-500 dark:text-fg-faint">Last applied</dt>
                            <dd>
                                @if ($link->lastAppliedRevision)
                                    <code>{{ substr((string) $link->lastAppliedRevision->commit_sha, 0, 7) ?: 'from the API' }}</code>
                                    <span class="text-neutral-500 dark:text-fg-dim">{{ $link->lastAppliedRevision->applied_at?->diffForHumans() }}</span>
                                @else
                                    Never
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.06]">
                        <x-forms.checkbox id="autoApply" instantSave="saveAutoApply" :disabled="! $isTeamAdmin"
                            label="Apply safe changes automatically"
                            helper="When this is off, every push waits for an approval. Removals always wait." />
                    </div>
                    @if ($webhook)
                        <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.06]" x-data="{ open: false }">
                            <button type="button" @click="open = !open" class="flex items-center gap-1.5 text-[13px] font-medium text-black dark:text-fg" :aria-expanded="open">
                                <x-reicon name="chevron-down" class="size-3.5 transition-transform duration-150 ease-out" ::class="open ? '' : '-rotate-90'" />
                                Set up a webhook
                            </button>
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                Needed when the repository does not use a GitHub App. Add one push webhook with this secret.
                            </p>
                            <div x-show="open" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
                                <x-forms.copy-input label="Secret" :text="$webhook['webhook_secret']" />
                                @foreach ($webhook['webhook_urls'] as $provider => $url)
                                    <x-forms.copy-input :label="ucfirst($provider).' URL'" :text="$url" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>

                @if ($latest && ! empty($latest->plan['warnings']))
                    <x-application.settings-section title="Not in the file" collapsible
                        helper="These resources are in the environment but not in coolify.json, so Coolify leaves them alone.">
                        <ul class="space-y-1 text-[13px] text-neutral-600 dark:text-fg-dim">
                            @foreach ($latest->plan['warnings'] as $warning)
                                <li>{{ str_replace(' is not managed by coolify.json.', '', $warning) }}</li>
                            @endforeach
                        </ul>
                    </x-application.settings-section>
                @endif

                <x-application.settings-section title="History" flush helper="One row for each push or API call.">
                    @if ($revisions->isEmpty())
                        <x-empty title="No syncs yet" description="Push to {{ $link->branch ?? 'the branch' }} to plan the file." />
                    @else
                        <table class="w-full text-left text-[13px]">
                            <thead class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Status</th>
                                    <th class="px-2 py-2 font-medium">Commit</th>
                                    <th class="px-2 py-2 font-medium">Changes</th>
                                    <th class="hidden px-2 py-2 font-medium sm:table-cell">From</th>
                                    <th class="px-2 py-2 font-medium">When</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            @foreach ($revisions as $revision)
                                @php($revisionChanges = $revision->plan['changes'] ?? [])
                                <tbody wire:key="revision-{{ $revision->uuid }}" x-data="{ open: false }"
                                    class="border-t border-neutral-200 dark:border-white/[0.06]">
                                    <tr @if ($revisionChanges !== [] || $revision->error) @click="open = !open" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-white/[0.02]" @endif>
                                        <td class="px-4 py-2">
                                            <x-status-badge size="sm" :label="ucfirst(str_replace('_', ' ', $revision->status->value))"
                                                :type="match ($revision->status) {
                                                    \App\Enums\ConfigRevisionStatus::Applied => 'success',
                                                    \App\Enums\ConfigRevisionStatus::Failed => 'error',
                                                    \App\Enums\ConfigRevisionStatus::WaitingApproval => 'warning',
                                                    default => 'neutral',
                                                }"
                                                :pulse="in_array($revision->status, [\App\Enums\ConfigRevisionStatus::Applying, \App\Enums\ConfigRevisionStatus::Planned], true)" />
                                        </td>
                                        <td class="px-2 py-2"><code class="text-[12px]">{{ $revision->commit_sha ? substr($revision->commit_sha, 0, 7) : '-' }}</code></td>
                                        <td class="px-2 py-2 text-neutral-600 dark:text-fg-dim">{{ count($revisionChanges) ?: '-' }}</td>
                                        <td class="hidden px-2 py-2 text-neutral-600 sm:table-cell dark:text-fg-dim">{{ ['api' => 'API', 'push' => 'Push', 'link' => 'Link', 'retry' => 'Retry'][$revision->trigger] ?? $revision->trigger }}</td>
                                        <td class="px-2 py-2 text-neutral-600 dark:text-fg-dim" title="{{ $revision->created_at }}">{{ $revision->created_at?->diffForHumans() }}</td>
                                        <td class="px-4 py-2 text-right" @click.stop>
                                            @if ($revision->status === \App\Enums\ConfigRevisionStatus::Applying && $isTeamAdmin)
                                                <button type="button" wire:click="release('{{ $revision->uuid }}')" class="text-[12px] font-medium text-neutral-600 hover:text-black dark:text-fg-dim dark:hover:text-fg">Release</button>
                                            @elseif ($revision->status === \App\Enums\ConfigRevisionStatus::Failed && $revision->file_contents !== null)
                                                @can('update', $environment)
                                                    <button type="button" wire:click="retry('{{ $revision->uuid }}')" class="text-[12px] font-medium text-neutral-600 hover:text-black dark:text-fg-dim dark:hover:text-fg">Retry</button>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                    <tr x-show="open" x-cloak>
                                        <td colspan="6" class="bg-neutral-50/60 pb-2 dark:bg-white/[0.015]">
                                            @if ($revision->error)
                                                <p class="px-4 pt-2 text-[12px] text-red-600 dark:text-red-400">{{ $revision->error }}</p>
                                            @endif
                                            @if ($revisionChanges !== [])
                                                <x-project-config.plan-groups :changes="$revisionChanges" compact />
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach
                        </table>
                    @endif
                </x-application.settings-section>

                @if ($isTeamAdmin)
                    <x-danger-zone title="Disconnect coolify.json">
                        <p>The resources stay and can be edited in Coolify again. The history of this link is deleted.</p>
                        <x-slot:action>
                            <x-modal-confirmation title="Disconnect coolify.json?" buttonTitle="Disconnect" isErrorButton
                                submitAction="disconnect" :actions="['Stop managing this environment with coolify.json.']"
                                :confirmWithText="false" />
                        </x-slot:action>
                    </x-danger-zone>
                @endif
            @endif
        </div>
    </div>
</div>
