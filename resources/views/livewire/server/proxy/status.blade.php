<div>
    @if ($server->settings->is_validated)
        <div wire:poll.5000ms="proxyStatus">
            @if ($server->extra_attributes->proxy_status === 'running')
                <x-loading wire:loading.delay.longer />
                <div class="flex items-center gap-2" wire:loading.remove.delay.longer>
                    <div class="badge badge-success badge-xs"></div>
                    <div class="text-xs font-medium tracking-wide">Running</div>
                </div>
            @else
                <x-loading wire:loading.delay.longer />
                <div class="flex items-center gap-2" wire:loading.remove.delay.longer>
                    <div class="badge badge-error badge-xs"></div>
                    <div class="text-xs font-medium tracking-wide">Stopped</div>
                </div>
            @endif
        </div>
    @endif
</div>
