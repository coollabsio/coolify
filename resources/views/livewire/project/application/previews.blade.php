<div>
    <h2>Previews</h2>
    <livewire:project.application.preview.form :application="$application" />
    <h3>Pull Requests on Git</h3>
    <div>
        <x-forms.button wire:loading.remove wire:target='load_prs' wire:click="load_prs">Load Pull Requests
        </x-forms.button>
        @isset($rate_limit_remaining)
            <div class="text-sm">Requests remaning till rate limited: {{ $rate_limit_remaining }}</div>
        @endisset
        <div wire:loading.remove wire:target='load_prs' class="pt-4">
            @if (count($pull_requests) > 0)
                @foreach ($pull_requests as $pull_request)
                    <div>
                        <div>PR #{{ data_get($pull_request, 'number') }} | {{ data_get($pull_request, 'title') }}</div>
                        <x-forms.button wire:click="deploy({{ data_get($pull_request, 'number') }})">Deploy
                        </x-forms.button>
                    </div>
                @endforeach
            @endif
        </div>
        <div wire:loading wire:target='load_prs'>
            <x-loading />
        </div>
    </div>
    @if ($application->previews->count() > 0)
        <h3>Preview Deployments</h3>
        <div class="flex gap-6 text-sm">
            @foreach ($application->previews as $preview)
                <div class="flex flex-col" x-init="$wire.loadStatus({{ data_get($preview, 'pull_request_id') }})">
                    <div>PR #{{ data_get($preview, 'pull_request_id') }} | {{ data_get($preview, 'status') }}
                        @if (data_get($preview, 'status') !== 'exited')
                            | <a target="_blank" href="{{ data_get($preview, 'fqdn') }}">Open Preview
                                <x-external-link />
                            </a>
                        @endif
                    </div>
                    <div class="flex gap-2 pt-2">
                        <x-forms.button isHighlighted wire:click="deploy({{ data_get($preview, 'pull_request_id') }})">
                            @if (data_get($preview, 'status') === 'exited')
                                Deploy
                            @else
                                Redeploy
                            @endif
                        </x-forms.button>
                        @if (data_get($preview, 'status') !== 'exited')
                            <x-forms.button wire:click="stop({{ data_get($preview, 'pull_request_id') }})">Stop
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
