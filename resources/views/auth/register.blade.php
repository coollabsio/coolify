<x-layout-simple>
    <div class="flex items-center justify-center min-h-screen">
        <div>
            <div class="pb-8 text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
            <div class="flex items-center gap-2">
                <h1 class="pb-0">{{ __('auth.register') }}</h1>
                <a href="/login" class="flex justify-center pt-2 hover:no-underline">
                    <x-forms.button type="submit">{{ __('auth.already-registered') }}</x-forms.button>
                </a>
            </div>
            <form action="/register" method="POST" class="flex flex-col gap-2">
                @csrf
                @env('local')
                <x-forms.input required value="test3 normal user" type="text" name="name"
                    label="{{ __('input.name') }}" />
                <x-forms.input required value="test3@example.com" type="email" name="email"
                    label="{{ __('input.email') }}" />
                <div class="flex gap-2">
                    <x-forms.input required value="password" type="password" name="password"
                        label="{{ __('input.password') }}" />
                    <x-forms.input required value="password" type="password" name="password_confirmation"
                        label="{{ __('input.password.again') }}" />
                </div>
            @else
                <x-forms.input required type="text" name="name" label="{{ __('input.name') }}" />
                <x-forms.input required type="email" name="email" label="{{ __('input.email') }}" />
                <div class="flex gap-2">
                    <x-forms.input required type="password" name="password" label="{{ __('input.password') }}" />
                    <x-forms.input required type="password" name="password_confirmation"
                        label="{{ __('input.password.again') }}" />
                </div>
                @endenv

                <x-forms.button type="submit">{{ __('auth.register') }}</x-forms.button>
            </form>
            @if ($errors->any())
                <div class="fixed top-0 text-xs alert alert-error">
                    <ul>
                        <li>{{ __('auth.failed') }}</li>
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-layout-simple>
