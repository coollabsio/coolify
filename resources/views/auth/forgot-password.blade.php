<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
                <x-version />
            </div>

            <div class="flex items-center gap-2">
                <h1>{{ __('auth.forgot_password') }}</h1>
            </div>
            <div class="w-96">
                @if (is_transactional_emails_active())
                    <form action="/forgot-password" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <x-forms.input required value="test@example.com" type="email" name="email"
                            label="{{ __('input.email') }}" autofocus />
                        <x-forms.button type="submit">{{ __('auth.forgot_password_send_email') }}</x-forms.button>
                    </form>
                @else
                    'asd'
                @endif
                @if ($errors->any())
                    <div class="text-xs text-center text-error">
                        <span>{{ __('auth.failed') }}</span>
                    </div>
                @endif
                @if (session('status'))
                    <div class="mb-4 text-xs font-medium text-green-600">
                        {{ session('status') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layout-simple>
