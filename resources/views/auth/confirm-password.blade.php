<x-layout-simple>
    <x-auth.shell title="Coolify" description="Confirm your password to continue to this secure area.">
        <div class="flex flex-col gap-4">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
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

            <div class="auth-guidance">
                <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                <p>This is a secure area. Please confirm your password before continuing.</p>
            </div>

            <form action="/user/confirm-password" method="POST" class="flex flex-col gap-4">
                @csrf
                <x-forms.input required type="password" name="password" autocomplete="current-password" autofocus
                    label="{{ __('input.password') }}" />
                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    {{ __('auth.confirm_password') }}
                </x-forms.button>
            </form>
        </div>
    </x-auth.shell>
</x-layout-simple>
