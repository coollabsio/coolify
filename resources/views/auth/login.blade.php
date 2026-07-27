<x-layout-simple>
    <x-auth.shell title="Welcome back" description="Sign in to manage your applications and infrastructure.">
        <div class="flex flex-col gap-4">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if (session('error'))
                <x-auth.alert type="error">{{ session('error') }}</x-auth.alert>
            @endif

            @if ($errors->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            <form action="/login" method="POST" class="flex flex-col gap-4">
                @csrf
                @env('local')
                    <x-forms.input value="test@example.com" type="email" autocomplete="email" name="email" required
                        autofocus label="{{ __('input.email') }}" />
                    <x-forms.input value="password" type="password" autocomplete="current-password" name="password"
                        required label="{{ __('input.password') }}" />
                @else
                    <x-forms.input type="email" name="email" autocomplete="email" required autofocus
                        label="{{ __('input.email') }}" />
                    <x-forms.input type="password" name="password" autocomplete="current-password" required
                        label="{{ __('input.password') }}" />
                @endenv

                <div class="flex justify-end">
                    <a href="/forgot-password" class="auth-text-link">
                        {{ __('auth.forgot_password_link') }}
                    </a>
                </div>

                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    {{ __('auth.login') }}
                </x-forms.button>
            </form>

            @if ($enabled_oauth_providers->isNotEmpty())
                <div class="auth-divider"><span>Or continue with</span></div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($enabled_oauth_providers as $provider_setting)
                        <x-forms.button class="w-full justify-center" type="button"
                            onclick="document.location.href='/auth/{{ $provider_setting->provider }}/redirect'">
                            {{ __("auth.login.$provider_setting->provider") }}
                        </x-forms.button>
                    @endforeach
                </div>
            @endif
        </div>

        <x-slot:footer>
            @if ($is_registration_enabled)
                <span>New to Coolify?</span>
                <a href="/register" class="auth-text-link">{{ __('auth.register_now') }}</a>
            @else
                <span>{{ __('auth.registration_disabled') }}</span>
            @endif
        </x-slot:footer>
    </x-auth.shell>
</x-layout-simple>
