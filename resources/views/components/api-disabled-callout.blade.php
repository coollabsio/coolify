@props(['message' => 'This feature uses the Coolify API.'])

@unless (instanceSettings()->is_api_enabled)
    <x-callout type="warning" title="API access is turned off" {{ $attributes }}>
        {{ $message }}
        @if (isInstanceAdmin())
            <div class="mt-2">
                <a href="{{ route('settings.advanced') }}" class="button" {{ wireNavigate() }}>Enable API access</a>
            </div>
        @else
            Ask an instance admin to enable API access.
        @endif
    </x-callout>
@endunless
