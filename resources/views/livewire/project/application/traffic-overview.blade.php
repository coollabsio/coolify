@php
    $analyticsRoute = route('project.application.analytics', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ]);
@endphp

<div>
    @if (! $enabled)
        @if ($eligible && $serverUuid)
            <section class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-[13px] font-semibold text-black dark:text-fg">Traffic analytics</h3>
                        <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">
                            Enable Sentinel traffic analytics on this server to see requests, visitors, and
                            geography for this application. Restarts the proxy + Sentinel.
                        </p>
                    </div>
                    <a class="button shrink-0" href="{{ route('server.sentinel', ['server_uuid' => $serverUuid]) }}" {{ wireNavigate() }}>
                        Server settings
                        <x-external-link />
                    </a>
                </div>
            </section>
        @endif
    @else
        <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
                <div>
                    <h3 class="text-[12px]! leading-4! font-semibold! text-black dark:text-fg">Traffic (last 24h)</h3>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">Request activity for this application</p>
                </div>
                <a class="text-[12px] font-medium text-coollabs hover:underline dark:text-fg" href="{{ $analyticsRoute }}" {{ wireNavigate() }}>
                    View full analytics &rarr;
                </a>
            </div>

            @if (! $this->hasData())
                <p class="px-4 py-4 text-[12px] text-neutral-500 dark:text-fg-dim">
                    No traffic recorded in the last 24h yet.
                </p>
            @else
                <div class="grid grid-cols-2 gap-px bg-neutral-200 sm:grid-cols-4 dark:bg-white/[0.07]">
                    <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                        <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Requests</span>
                        <span class="text-lg font-semibold text-black dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                        <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Unique visitors</span>
                        <span class="text-lg font-semibold text-black dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                        <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Error rate</span>
                        <span class="text-lg font-semibold text-black dark:text-fg">{{ $this->errorRate() }}%</span>
                    </div>
                    <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                        <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">p95 latency</span>
                        <span class="text-lg font-semibold text-black dark:text-fg">{{ number_format($overview['latencyP95'] ?? 0, 1) }} ms</span>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
