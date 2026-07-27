<div>
    <x-slot:title>
        Tags | Coolify
    </x-slot>

    @if ($tags->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="tags" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No tags yet</h2>
            <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                Open a resource and add a tag to start grouping related deployments.
            </p>
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-center gap-2">
            @foreach ($tags as $oneTag)
                <a class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[12px] font-medium transition-colors hover:no-underline {{ $tag?->id === $oneTag->id ? 'border-coollabs/25 bg-coollabs/10 text-coollabs dark:border-warning/25 dark:bg-warning/15 dark:text-warning' : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:text-black dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim dark:hover:border-white/[0.14] dark:hover:text-fg' }}"
                    {{ wireNavigate() }} href="{{ route('tags.show', ['tagName' => $oneTag->name]) }}">
                    <x-reicon name="tags" class="size-3" />
                    {{ data_get_str($oneTag, 'name')->limit(30) }}
                </a>
            @endforeach
        </div>

        @if (isset($tag))
            <div class="flex flex-col gap-6">
                <div class="application-settings-form">
                    <x-application.settings-section :title="$tag->name"
                        description="Use this webhook to deploy every resource with this tag.">
                        <x-slot:actions>
                            <x-modal-confirmation title="Redeploy all resources with this tag?"
                                buttonTitle="Redeploy all" submitAction="redeployAll" :actions="[
                                    'All resources with this tag will be redeployed.',
                                    'During redeploy resources will be temporarily unavailable.',
                                ]"
                                confirmationText="{{ $tag->name }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Tag Name below"
                                shortConfirmationLabel="Tag Name" :confirmWithPassword="false"
                                step2ButtonText="Redeploy All" />
                        </x-slot:actions>

                        <x-forms.input readonly label="Deploy webhook URL" id="webhook" />
                    </x-application.settings-section>
                </div>

                @php
                    $resourceCount = ($applications?->count() ?? 0) + ($services?->count() ?? 0);
                @endphp

                <div class="application-settings-form">
                    <x-application.settings-section title="Resources"
                        description="{{ $resourceCount }} {{ Str::plural('resource', $resourceCount) }} use this tag.">
                        @if ($resourceCount === 0)
                            <x-empty title="No resources use this tag"
                                description="Add this tag to an application or service to see it here." size="sm">
                                <x-slot:icon>
                                    <x-reicon name="tags" class="size-5" />
                                </x-slot:icon>
                            </x-empty>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($applications ?? [] as $application)
                                    <a {{ wireNavigate() }} href="{{ $application->link() }}"
                                        class="group flex min-h-24 flex-col rounded-lg border border-neutral-200 bg-neutral-50/70 p-3 transition-colors hover:border-neutral-300 hover:no-underline dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                        <div class="flex items-start gap-2.5">
                                            <div
                                                class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                                <x-reicon name="browser-code" class="size-3.5" />
                                            </div>
                                            <div class="min-w-0">
                                                <h3
                                                    class="truncate text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                                    {{ $application->name }}
                                                </h3>
                                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ $application->project()->name }}/{{ $application->environment->name }}
                                                </p>
                                            </div>
                                        </div>
                                        <p class="mt-auto truncate pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">
                                            {{ $application->description ?: 'Application' }}
                                        </p>
                                    </a>
                                @endforeach

                                @foreach ($services ?? [] as $service)
                                    <a {{ wireNavigate() }} href="{{ $service->link() }}"
                                        class="group flex min-h-24 flex-col rounded-lg border border-neutral-200 bg-neutral-50/70 p-3 transition-colors hover:border-neutral-300 hover:no-underline dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                        <div class="flex items-start gap-2.5">
                                            <div
                                                class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                                <x-reicon name="layers" class="size-3.5" />
                                            </div>
                                            <div class="min-w-0">
                                                <h3
                                                    class="truncate text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                                    {{ $service->name }}
                                                </h3>
                                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ $service->project()->name }}/{{ $service->environment->name }}
                                                </p>
                                            </div>
                                        </div>
                                        <p class="mt-auto truncate pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">
                                            {{ $service->description ?: 'Service' }}
                                        </p>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </x-application.settings-section>
                </div>

                <div class="application-settings-form">
                    <x-application.settings-section title="Active deployments"
                        description="Queued and running deployments for applications using this tag." flush>
                        <x-slot:actions>
                            @if (count($deploymentsPerTagPerServer) > 0)
                                <x-loading />
                            @endif
                        </x-slot:actions>

                        <div wire:poll="getDeployments" class="overflow-x-auto">
                            @if (count($deploymentsPerTagPerServer) === 0)
                                <x-empty title="No active deployments"
                                    description="Deployments will appear here while they are queued or running." size="sm">
                                    <x-slot:icon>
                                        <x-reicon name="play-circle" class="size-5" />
                                    </x-slot:icon>
                                </x-empty>
                            @else
                                <div
                                    class="grid min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.55fr)_7rem_2rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                                    <div>Resource</div>
                                    <div>Server</div>
                                    <div>Status</div>
                                    <div></div>
                                </div>

                                @foreach ($deploymentsPerTagPerServer as $serverName => $deployments)
                                    @foreach ($deployments as $deployment)
                                        <a {{ wireNavigate() }} href="{{ data_get($deployment, 'deployment_url') }}"
                                            class="grid min-h-13 min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.55fr)_7rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                            <div class="truncate font-medium text-black dark:text-fg">
                                                {{ data_get($deployment, 'application_name') }}
                                            </div>
                                            <div class="truncate text-neutral-500 dark:text-fg-dim">{{ $serverName }}</div>
                                            <div>
                                                <x-status-badge :status="str(data_get($deployment, 'status'))->headline()"
                                                    :type="data_get($deployment, 'status') === 'in_progress' ? 'warning' : 'neutral'" />
                                            </div>
                                            <x-reicon name="arrow-right"
                                                class="size-3.5 justify-self-end text-neutral-400 dark:text-fg-faint" />
                                        </a>
                                    @endforeach
                                @endforeach
                            @endif
                        </div>
                    </x-application.settings-section>
                </div>
            </div>
        @endif
    @endif
</div>
