<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div>
                <form action="/login" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <input type="email" name="email" placeholder="{{ __('input.email') }}"
                        @env('local') value="test@example.com" @endenv autofocus />
                    <input type="password" name="password" placeholder="{{ __('input.password') }}"
                        @env('local') value="password" @endenv />
                    <x-inputs.button type="submit">{{ __('auth.login') }}</x-inputs.button>
                </form>
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul>
                            <li>{{ __('auth.failed') }}</li>
                        </ul>
                    </div>
                @endif
            </div>
            @if ($is_registration_enabled)
                <a href="/register" class="flex justify-center pt-2">
                    <button>{{ __('auth.register') }}</button>
                </a>
            @else
                <div class="text-sm text-center">{{ __('auth.registration_disabled') }}</div>
            @endif
        </div>
    </div>
</x-layout-simple>
