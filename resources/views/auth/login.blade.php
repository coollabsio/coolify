<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="pb-8 text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
            <div class="flex items-center gap-2">
                <h1 class="pb-0">{{ __('auth.login') }}</h1>
                @if ($is_registration_enabled)
                    <a href="/register" class="flex justify-center pt-2">
                        <x.inputs-button>{{ __('auth.register-now') }}</x.inputs-button>
                    </a>
                @else
                    <div class="text-sm text-center">{{ __('auth.registration_disabled') }}</div>
                @endif
            </div>
            <div class="w-96">
                <form action="/login" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <x-forms.input required type="email" name="email" label="{{ __('input.email') }}" autofocus />
                    <x-forms.input required type="password" name="password" label="{{ __('input.password') }}" />
                    @if ($errors->any())
                        <div class="text-center text-error">
                            <span>{{ __('auth.failed') }}</span>
                        </div>
                    @endif
                    <x-forms.button type="submit">{{ __('auth.login') }}</x-forms.button>

                </form>

            </div>


        </div>
    </div>
</x-layout-simple>
