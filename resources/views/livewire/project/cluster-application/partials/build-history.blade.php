@if ($workload->buildsFromSource())
    <x-application.settings-section id="cluster-application-builds" title="Builds"
        helper="Each deploy builds the latest commit of the branch. A successful build deploys its image to every server of the application." flush>
        @forelse ($recentBuilds as $build)
            <a href="{{ route('project.cluster-application.build.show', [...$routeParameters, 'build_uuid' => $build->uuid]) }}" {{ wireNavigate() }}
                wire:key="cluster-application-build-{{ $build->uuid }}"
                class="flex min-h-14 items-center gap-3 border-b border-neutral-200 px-4 py-3 text-sm last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                <x-status-badge :label="$build->status->label()" :type="$build->status->badgeType()"
                    :spinning="! $build->status->isFinal()" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-black dark:text-fg">
                        {{ $build->git_branch }}@if ($build->commit_sha) · <span class="font-mono">{{ substr($build->commit_sha, 0, 8) }}</span>@endif
                    </span>
                    <span class="block truncate text-xs text-neutral-500 dark:text-fg-dim">
                        {{ $build->source_type->label() }}@if ($build->node) · {{ $build->node->name }}@endif · {{ $build->created_at->diffForHumans() }}
                    </span>
                </span>
                <x-reicon name="arrow-right" class="size-4 shrink-0 text-neutral-400" />
            </a>
        @empty
            <p class="px-4 py-6 text-sm text-neutral-500 dark:text-fg-dim">No builds yet. Deploy to build the repository.</p>
        @endforelse
    </x-application.settings-section>
@endif
