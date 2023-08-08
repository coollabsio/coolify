<x-layout-simple>
    <div class="flex items-center justify-center h-screen mx-auto">
        <div>
            <div class="flex flex-col items-center pb-8">
                <a href="{{ route('dashboard') }}">
                    <div class="text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
                </a>
                <x-version/>
            </div>
            <div class="flex items-center gap-2">
                <h1>{{ __('auth.reset_password') }}</h1>
            </div>
            <div>
                <form action="/reset-password" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <input hidden id="token" name="token" value="{{ request()->route('token') }}">
                    <input hidden value="{{ request()->query('email') }}" type="email" name="email"
                           label="{{ __('input.email') }}"/>
                    <div class="flex gap-2">
                        <x-forms.input required type="password" id="password" name="password"
                                       label="{{ __('input.password') }}" autofocus/>
                        <x-forms.input required type="password" id="password_confirmation" name="password_confirmation"
                                       label="{{ __('input.password.again') }}"/>
                    </div>
                    <x-forms.button type="submit">{{ __('auth.reset_password') }}</x-forms.button>
                </form>
                @if ($errors->any())
                    <div class="text-center text-error">
                        <span>{{ __('auth.failed') }}</span>
                    </div>
                @endif
                @if (session('status'))
                    <div class="mb-4  font-medium text-green-600">
                        {{ session('status') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layout-simple>
