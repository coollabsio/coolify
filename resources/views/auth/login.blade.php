<x-layout-simple>
    <div class="min-h-screen hero">
        <div class="w-96 min-w-fit">
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-extrabold tracking-tight text-center text-white">Coolify</div>
            </div>
            <div class="flex items-center gap-2">
                <h1>{{ __('auth.login') }}</h1>
                @if ($is_registration_enabled)
                    @if (config('coolify.waitlist'))
                        <a href="/waitlist"
                            class="text-xs text-center text-white normal-case bg-transparent border-none rounded no-animation hover:no-underline btn btn-sm bg-coollabs-gradient">
                            Join the waitlist
                        </a>
                    @else
                        <a href="/register"
                            class="text-xs text-center text-white normal-case bg-transparent border-none rounded no-animation hover:no-underline btn btn-sm bg-coollabs-gradient">
                            {{ __('auth.register_now') }}
                        </a>
                    @endif
                @endif
            </div>
            <div>
                <form action="/login" method="POST" class="flex flex-col gap-2">
                    @csrf
                    @env('local')
                        <x-forms.input value="test@example.com" type="email" name="email" required
                            label="{{ __('input.email') }}" autofocus />

                        <x-forms.input value="password" type="password" name="password" required
                            label="{{ __('input.password') }}" />
                        <a href="/forgot-password" class="text-xs">
                            {{ __('auth.forgot_password') }}?
                        </a>
                    @else
                        <x-forms.input type="email" name="email" required label="{{ __('input.email') }}" autofocus />
                        <x-forms.input type="password" name="password" required label="{{ __('input.password') }}" />
                        <a href="/forgot-password" class="text-xs">
                            {{ __('auth.forgot_password') }}?
                        </a>
                    @endenv
                    <x-forms.button type="submit">{{ __('auth.login') }}</x-forms.button>
                    @foreach ($enabled_oauth_providers as $provider_setting)
                        <x-forms.button type="button" onclick="document.location.href='/auth/{{$provider_setting->provider}}/redirect'">
                            {{ __("auth.login.$provider_setting->provider") }}
                        </x-forms.button>
                    @endforeach
                    @if (!$is_registration_enabled)
                        <div class="text-center ">{{ __('auth.registration_disabled') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="text-xs text-center text-error">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if (session('status'))
                        <div class="mb-4 font-medium text-green-600">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 font-medium text-red-600">
                            {{ session('error') }}
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</x-layout-simple>
