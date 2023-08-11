<div>
    <livewire:project.application.preview.form :application="$application" />
    <div>
        <div class="flex items-center gap-2">
            <h3>Pull Requests on Git</h3>
            <x-forms.button wire:click="load_prs">Load Pull Requests
            </x-forms.button>
        </div>
        @isset($rate_limit_remaining)
            <div class="pt-1 ">Requests remaning till rate limited by Git: {{ $rate_limit_remaining }}</div>
        @endisset
        @if (count($pull_requests) > 0)
            <div wire:loading.remove wire:target='load_prs'>
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
                                    <td>
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
            </div>
        @endif
    </div>
    @if ($application->previews->count() > 0)
        <h4 class="py-4" wire:poll.10000ms='previewRefresh'>Deployed Previews</h4>
        <div class="flex gap-6 ">
            @foreach ($application->previews as $preview)
                <div class="flex flex-col p-4 bg-coolgray-200 " x-init="$wire.loadStatus('{{ data_get($preview, 'pull_request_id') }}')">
                    <div class="flex gap-2">PR #{{ data_get($preview, 'pull_request_id') }} |
                        @if (data_get($preview, 'status') === 'running')
                            <x-status.running />
                        @elseif (data_get($preview, 'status') === 'restarting')
                            <x-status.restarting />
                        @else
                            <x-status.stopped />
                        @endif
                        @if (data_get($preview, 'status') !== 'exited')
                            | <a target="_blank" href="{{ data_get($preview, 'fqdn') }}">Open Preview
                                <x-external-link />
                            </a>
                        @endif
                        |
                        <a target="_blank" href="{{ data_get($preview, 'pull_request_html_url') }}">Open PR on Git
                            <x-external-link />
                        </a>
                    </div>
                    <div class="flex items-center gap-2 pt-6">
                        <x-forms.button wire:click="deploy({{ data_get($preview, 'pull_request_id') }})">
                            @if (data_get($preview, 'status') === 'exited')
                                Deploy
                            @else
                                Redeploy
                            @endif
                        </x-forms.button>
                        @if (data_get($preview, 'status') !== 'exited')
                            <x-forms.button wire:click="stop({{ data_get($preview, 'pull_request_id') }})">Remove
                                Preview
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
