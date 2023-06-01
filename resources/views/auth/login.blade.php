<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
                <x-version />
            </div>
            <div class="flex items-center gap-2">
                <h1 class="pb-0">{{ __('auth.login') }}</h1>
                @if ($is_registration_enabled)
                    <a href="/register" class="flex justify-center pt-2 hover:no-underline">
                        <button
                            class="normal-case rounded-none btn btn-sm btn-primary bg-coollabs-gradient">{{ __('auth.register_now') }}</button>
                    </a>
                @endif
            </div>
            <div class="w-96">
                <form action="/login" method="POST" class="flex flex-col gap-2">
                    @csrf
                    @env('local')
                    <x-forms.input required value="test@example.com" type="email" name="email"
                        label="{{ __('input.email') }}" autofocus />
                    {{-- @if (config('mail.default'))
                        {{ dd('mailer configured') }}
                    @else
                        {{ dd('mailer not configured') }}
                    @endif --}}

                    @if (!config('mail.default'))
                        <a href="/forgot-password" class="text-xs">
                            {{ __('auth.forgot_password') }}?
                        </a>
                    @endif
                    <x-forms.input required value="password" type="password" name="password"
                        label="{{ __('input.password') }}" />
                @else
                    <x-forms.input required type="email" name="email" label="{{ __('input.email') }}" autofocus />
                    <x-forms.input required type="password" name="password" label="{{ __('input.password') }}" />
                    @endenv
                    @if ($errors->any())
                        <div class="text-center text-error">
                            <span>{{ __('auth.failed') }}</span>
                        </div>
                    @endif
                    @if (session('status'))
                        <div class="mb-4 text-sm font-medium text-green-600">
                            {{ session('status') }}
                        </div>
                    @endif
                    <x-forms.button type="submit">{{ __('auth.login') }}</x-forms.button>
                    @if (!$is_registration_enabled)
                        <div class="text-sm text-center">{{ __('auth.registration_disabled') }}</div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</x-layout-simple>
