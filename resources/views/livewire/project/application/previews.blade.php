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
                                            Add
                                        </x-forms.button>
                                        <x-forms.button
                                            wire:click="deploy('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                            Deploy
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
        <div class="pb-4">Previews</div>
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
                    <form wire:submit="save_preview('{{ $preview->id }}')" class="flex items-end gap-2 pt-4">
                        <x-forms.input label="Domain" helper="One domain per preview."
                            id="application.previews.{{ $previewName }}.fqdn"></x-forms.input>
                        <x-forms.button type="submit">Save</x-forms.button>
                        <x-forms.button wire:click="generate_preview('{{ $preview->id }}')">Generate
                            Domain</x-forms.button>
                    </form>
                    <div class="flex items-center gap-2 pt-6">
                        <x-forms.button wire:click="deploy({{ data_get($preview, 'pull_request_id') }})">
                            @if (data_get($preview, 'status') === 'exited')
                                Deploy
                            @else
                                Redeploy
                            @endif
                        </x-forms.button>
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
