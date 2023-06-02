<div>
    <livewire:project.application.preview.form :application="$application" />
    <h3>Pull Requests on Git</h3>
    <div>
        <x-forms.button wire:click="load_prs">Load Pull Requests (open)
        </x-forms.button>
        @isset($rate_limit_remaining)
            <div class="pt-1 text-sm">Requests remaning till rate limited by Git: {{ $rate_limit_remaining }}</div>
        @endisset
        @if (count($pull_requests) > 0)
            <div wire:loading.remove wire:target='load_prs' class="flex gap-4 py-8">
                @foreach ($pull_requests as $pull_request)
                    <div class="flex flex-col gap-4 p-4 text-sm bg-coolgray-200 hover:bg-coolgray-300">
                        <div class="text-base font-bold text-white">PR #{{ data_get($pull_request, 'number') }} |
                            {{ data_get($pull_request, 'title') }}</div>
                        <div class="flex items-center justify-start gap-2">
                            <x-forms.button isHighlighted
                                wire:click="deploy('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                Deploy
                            </x-forms.button>
                            <a target="_blank" class="text-xs" href="{{ data_get($pull_request, 'html_url') }}">Open PR
                                on
                                Git
                                <x-external-link />
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @if ($application->previews->count() > 0)
        <h3>Preview Deployments</h3>
        <div class="flex gap-6 text-sm">
            @foreach ($application->previews as $preview)
                <div class="flex flex-col p-4 bg-coolgray-200 " x-init="$wire.loadStatus('{{ data_get($preview, 'pull_request_id') }}')">
                    <div>PR #{{ data_get($preview, 'pull_request_id') }} | {{ data_get($preview, 'status') }}
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
                        <x-forms.button isHighlighted wire:click="deploy({{ data_get($preview, 'pull_request_id') }})">
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
