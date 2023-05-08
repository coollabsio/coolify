<div>
    <a @if ($status === 'in_progress' || $status === 'queued') wire:poll='polling' @endif href="{{ url()->current() }}/{{ $deployment_uuid }}">
        {{ $created_at }}
        {{ $deployment_uuid }}</a>
    {{ $status }}
</div>
