<div>
    <div class="flex items-center justify-between">
        <div>
            <h1>Deployments</h1>
            <div class="subtitle">Recent deployments across your applications.</div>
        </div>
        <x-forms.select wire:model.live="status" class="w-48">
            <option value="all">All statuses</option>
            <option value="queued">Queued</option>
            <option value="in_progress">In progress</option>
            <option value="finished">Finished</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
        </x-forms.select>
    </div>

    <div class="pt-6" wire:poll.5s>
        @forelse ($deployments as $deployment)
            <div class="box-without-bg mb-2 flex flex-col gap-2 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="font-bold">
                        {{ $deployment->application_name }}
                    </div>
                    <div class="text-sm text-neutral-500">
                        {{ $deployment->server_name }}
                        @if ($deployment->pull_request_id)
                            <span>· PR #{{ $deployment->pull_request_id }}</span>
                        @endif
                        <span>· {{ $deployment->created_at?->diffForHumans() }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm">{{ str($deployment->status)->headline() }}</span>
                    @if ($deployment->deployment_url)
                        <a class="text-sm text-warning hover:underline" href="{{ $deployment->deployment_url }}" target="_blank">
                            View deployment
                        </a>
                    @endif
                </div>
            </div>
        @empty
            <div class="box-without-bg p-4 text-neutral-500">No deployments found.</div>
        @endforelse
    </div>
</div>
