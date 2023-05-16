<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <form action="/register" method="POST" class="flex flex-col gap-2">
                @csrf
                <input type="text" name="name" placeholder="{{ __('input.name') }}"
                    @env('local') value="root" @endenv />
                <input type="email" name="email" placeholder="{{ __('input.email') }}"
                    @env('local') value="test@example.com" @endenv />
                <input type="password" name="password" placeholder="{{ __('input.password') }}"
                    @env('local') value="password" @endenv />
                <input type="password" name="password_confirmation" placeholder="{{ __('input.password.again') }}"
                    @env('local') value="password" @endenv />
                <x-inputs.button isBold type="submit">{{ __('auth.register') }}</x-inputs.button>
            </form>
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        <li>{{ __('auth.failed') }}</li>
                    </ul>
                </div>
            @endif
            <a href="/login" class="flex justify-center pt-2">
                <button>{{ __('auth.login') }}</button>
            </a>
        </div>
    </div>
</x-layout-simple>
