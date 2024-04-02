<x-layout-simple>
    <div class="min-h-screen hero">
        <div>
            <div class="flex flex-col items-center ">
                <a href="{{ route('dashboard') }}">
                    <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
                </a>
            </div>
            <div class="flex items-center justify-center pb-4 text-center">
                {{ __('auth.reset_password') }}
            </div>
            <div>
                <form action="/reset-password" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <input hidden id="token" name="token" value="{{ request()->route('token') }}">
                    <input hidden value="{{ request()->query('email') }}" type="email" name="email"
                        label="{{ __('input.email') }}" />
                    <div class="flex flex-col gap-2">
                        <x-forms.input required type="password" id="password" name="password"
                            label="{{ __('input.password') }}" autofocus />
                        <x-forms.input required type="password" id="password_confirmation" name="password_confirmation"
                            label="{{ __('input.password.again') }}" />
                    </div>
                    <x-forms.button type="submit">{{ __('auth.reset_password') }}</x-forms.button>
                </form>
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
            </div>
        </div>
    </div>
</x-layout-simple>
