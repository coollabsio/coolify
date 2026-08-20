@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-shell-content">
        <div class="auth-card">
            {{-- Brand strip: same height and wordmark treatment as the application top bar. --}}
            <div class="auth-card-brand">
                <img src="/coolify-logo.svg" alt="" aria-hidden="true" />
                <span>Coolify</span>
            </div>

            <div class="auth-card-body">
                <div class="auth-card-heading">
                    <h1>{{ $title }}</h1>
                    @if ($description)
                        <p>{{ $description }}</p>
                    @endif
                </div>

                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="auth-card-footer">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</section>
