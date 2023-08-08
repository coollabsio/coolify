<x-layout-simple>
    <div class="flex items-center justify-center min-h-screen ">
        <div class="w-1/2">
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
                <x-version/>
            </div>
            <div class="flex items-center gap-2">
                <h1>{{ __('auth.register') }}</h1>
                <a href="/login"
                   class="text-xs text-center text-white normal-case bg-transparent border-none rounded no-animation hover:no-underline btn btn-sm bg-coollabs-gradient">
                    {{ __('auth.already_registered') }}
                </a>
            </div>
            <form action="/register" method="POST" class="flex flex-col gap-2">
                @csrf
                @env('local')
                    <x-forms.input required value="test3 normal user" type="text" name="name"
                                   label="{{ __('input.name') }}"/>
                    <x-forms.input required value="test3@example.com" type="email" name="email"
                                   label="{{ __('input.email') }}"/>
                    <div class="flex gap-2">
                        <x-forms.input required value="password" type="password" name="password"
                                       label="{{ __('input.password') }}"/>
                        <x-forms.input required value="password" type="password" name="password_confirmation"
                                       label="{{ __('input.password.again') }}"/>
                    </div>
                    @else
                        <x-forms.input required type="text" name="name" label="{{ __('input.name') }}"/>
                        <x-forms.input required type="email" name="email" label="{{ __('input.email') }}"/>
                        <div class="flex gap-2">
                            <x-forms.input required type="password" name="password" label="{{ __('input.password') }}"/>
                            <x-forms.input required type="password" name="password_confirmation"
                                           label="{{ __('input.password.again') }}"/>
                        </div>
                        @endenv
                        <x-forms.button type="submit">{{ __('auth.register') }}</x-forms.button>
            </form>
            @if ($errors->any())
                <div class="text-xs text-center text-error">
                    <span>{{ __('auth.failed') }}</span>
                </div>
            @endif
        </div>
    </div>
</x-layout-simple>
