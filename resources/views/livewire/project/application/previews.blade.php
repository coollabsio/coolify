<div>
    <livewire:project.application.preview.form :application="$application" />
    @if (count($application->additional_servers) > 0)
        <div class="pb-4">Previews will be deployed on <span
                class="dark:text-warning">{{ $application->destination->server->name }}</span>.</div>
    @endif
    <div>
        @if ($application->is_github_based())
            <div class="flex items-center gap-2">
                <h3>Pull Requests on Git</h3>
                <x-forms.button wire:click="load_prs">Load Pull Requests
                </x-forms.button>
            </div>
        @endif
        @isset($rate_limit_remaining)
            <div class="pt-1 ">Requests remaining till rate limited by Git: {{ $rate_limit_remaining }}</div>
        @endisset
        <div wire:loading.remove wire:target='load_prs'>
            @if ($pull_requests->count() > 0)
                <div class="overflow-x-auto table-md">
                    <table>
                        <thead>
                            <tr>
                                <th>PR Number</th>
                                <th>PR Title</th>
                                <th>Git</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pull_requests as $pull_request)
                                <tr>
                                    <th>{{ data_get($pull_request, 'number') }}</th>
                                    <td>{{ data_get($pull_request, 'title') }}</td>
                                    <td>
                                        <a target="_blank" class="text-xs"
                                            href="{{ data_get($pull_request, 'html_url') }}">Open PR on
                                            Git
                                            <x-external-link />
                                        </a>
                                    </td>
                                    <td class="flex flex-col gap-1 md:flex-row">
                                        <x-forms.button
                                            wire:click="add('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                            Configure
                                        </x-forms.button>
                                        <x-forms.button
                                            wire:click="deploy('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                <path d="M7 4v16l13 -8z" />
                                            </svg>Deploy
                                        </x-forms.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @if ($application->previews->count() > 0)
        <h3 class="py-4">Deployments</h3>
        <div class="flex flex-wrap w-full gap-4">
            @foreach (data_get($application, 'previews') as $previewName => $preview)
                <div class="flex flex-col w-full p-4 border dark:border-coolgray-200">
                    <div class="flex gap-2">PR #{{ data_get($preview, 'pull_request_id') }} |
                        @if (Str::of(data_get($preview, 'status'))->startsWith('running'))
                            <x-status.running :status="data_get($preview, 'status')" />
                        @elseif(Str::of(data_get($preview, 'status'))->startsWith('restarting'))
                            <x-status.restarting :status="data_get($preview, 'status')" />
                        @else
                            <x-status.stopped :status="data_get($preview, 'status')" />
                        @endif
                        @if (data_get($preview, 'status') !== 'exited')
                            | <a target="_blank" href="{{ data_get($preview, 'fqdn') }}">Open Preview
                                <x-external-link />
                            </a>
                        @endif
                        |
                        <a target="_blank" href="{{ data_get($preview, 'pull_request_html_url') }}">Open
                            PR on Git
                            <x-external-link />
                        </a>
                    </div>

                    @if ($application->build_pack === 'dockercompose')
                        <div class="flex flex-col gap-4 pt-4">
                            @if (collect(json_decode($preview->docker_compose_domains))->count() === 0)
                                <form wire:submit="save_preview('{{ $preview->id }}')"
                                    class="flex items-end gap-2 pt-4">
                                    <x-forms.input label="Domain" helper="One domain per preview."
                                        id="application.previews.{{ $previewName }}.fqdn"></x-forms.input>
                                    <x-forms.button type="submit">Save</x-forms.button>
                                    <x-forms.button wire:click="generate_preview('{{ $preview->id }}')">Generate
                                        Domain</x-forms.button>
                                </form>
                            @else
                                @foreach (collect(json_decode($preview->docker_compose_domains)) as $serviceName => $service)
                                    <livewire:project.application.previews-compose wire:key="{{ $preview->id }}"
                                        :service="$service" :serviceName="$serviceName" :preview="$preview" />
                                @endforeach
                            @endif
                        </div>
                    @else
                        <form wire:submit="save_preview('{{ $preview->id }}')" class="flex items-end gap-2 pt-4">
                            <x-forms.input label="Domain" helper="One domain per preview."
                                id="application.previews.{{ $previewName }}.fqdn"></x-forms.input>
                            <x-forms.button type="submit">Save</x-forms.button>
                            <x-forms.button wire:click="generate_preview('{{ $preview->id }}')">Generate
                                Domain</x-forms.button>
                        </form>
                    @endif
                    <div class="flex items-center gap-2 pt-6">
                        @if (count($parameters) > 0)
                            <a
                                href="{{ route('project.application.deployment.index', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}">
                                <x-forms.button>
                                    Deployment Logs
                                </x-forms.button>
                            </a>
                            <a
                                href="{{ route('project.application.logs', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}">
                                <x-forms.button>
                                    Application Logs
                                </x-forms.button>
                            </a>
                        @endif
                        <div class="flex-1"></div>
                        <x-forms.button wire:click="deploy({{ data_get($preview, 'pull_request_id') }})">
                            @if (data_get($preview, 'status') === 'exited')
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M7 4v16l13 -8z" />
                                </svg>
                                Deploy
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-orange-400"
                                    viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                    <path
                                        d="M10.09 4.01l.496 -.495a2 2 0 0 1 2.828 0l7.071 7.07a2 2 0 0 1 0 2.83l-7.07 7.07a2 2 0 0 1 -2.83 0l-7.07 -7.07a2 2 0 0 1 0 -2.83l3.535 -3.535h-3.988">
                                    </path>
                                    <path d="M7.05 11.038v-3.988"></path>
                                </svg> Redeploy
                            @endif
                        </x-forms.button>
                        @if (data_get($preview, 'status') !== 'exited')
                            <x-modal-confirmation isErrorButton
                                action="stop({{ data_get($preview, 'pull_request_id') }})">
                                <x-slot:customButton>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error"
                                        viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path
                                            d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                        <path
                                            d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                    </svg>
                                    Stop
                                </x-slot:customButton>
                                This will stop the preview deployment. <br>Please think again.
                            </x-modal-confirmation>
                        @endif
                        <x-modal-confirmation isErrorButton
                            action="delete({{ data_get($preview, 'pull_request_id') }})" buttonTitle="Delete">
                            This will delete the preview deployment. <br>Please think again.
                        </x-modal-confirmation>

                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
