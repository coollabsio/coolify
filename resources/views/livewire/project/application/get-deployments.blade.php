<a @class([
    'bg-coolgray-200 p-2 border-l border-dashed transition-colors hover:no-underline',
    'border-warning hover:bg-warning hover:text-black' =>
        $status === 'in_progress',
    'border-primary hover:bg-primary' => $status === 'queued',
    'border-error hover:bg-error' => $status === 'error',
    'border-success hover:bg-success' => $status === 'finished',
]) @if ($status === 'in_progress' || $status === 'queued')
    wire:poll.5000ms='polling'
    @endif href="{{ url()->current() }}/{{ $deployment_uuid }}" class="hover:no-underline">
    <div class="flex flex-col justify-start">
        <div>
            {{ $status }}
        </div>
        <div>
            {{ $created_at }}
        </div>
    </div>
</a>
