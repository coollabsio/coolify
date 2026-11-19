@props([
    'code',
    'title',
    'description' => null,
    'showGoBack' => true,
    'showDashboard' => true,
    'primaryHref' => null,
    'primaryLabel' => null,
    'tone' => 'accent', // accent | danger
    'showContact' => true,
])

@php
    $codeClass = $tone === 'danger'
        ? 'error-code error-code-danger'
        : 'error-code';
@endphp

<main {{ $attributes->merge(['class' => 'error-shell']) }}>
    <div class="error-shell-content">
        <p class="{{ $codeClass }}">{{ $code }}</p>

        <h1 class="error-title">{{ $title }}</h1>

        @if (filled($description))
            <p class="error-description">{{ $description }}</p>
        @endif

        @if ($slot->isNotEmpty())
            <div class="error-extra">
                {{ $slot }}
            </div>
        @endif

        <div class="error-actions">
            @if (filled($primaryHref) && filled($primaryLabel))
                <a href="{{ $primaryHref }}">
                    <x-forms.button type="button">{{ $primaryLabel }}</x-forms.button>
                </a>
            @endif

            @if ($showGoBack)
                <a href="{{ url()->previous() }}">
                    <x-forms.button type="button">Go back</x-forms.button>
                </a>
            @endif

            @if ($showDashboard)
                <a href="{{ route('dashboard') }}" {{ wireNavigate() }}>
                    <x-forms.button type="button">Dashboard</x-forms.button>
                </a>
            @endif

            @if ($showContact)
                <a
                    target="_blank"
                    rel="noopener noreferrer"
                    href="{{ config('constants.urls.contact') }}">
                    <x-forms.button type="button">
                        Contact support
                        <x-external-link class="inline-flex size-3 text-current" />
                    </x-forms.button>
                </a>
            @endif
        </div>
    </div>
</main>
