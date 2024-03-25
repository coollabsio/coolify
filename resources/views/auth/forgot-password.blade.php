<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="flex flex-col items-center pb-8">
                <a href="{{ route('dashboard') }}">
                    <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
                </a>
                {{-- <x-version /> --}}
            </div>

            <div class="flex items-center gap-2">
                <h1>{{ __('auth.forgot_password') }}</h1>
            </div>
            <div>
                @if (is_transactional_emails_active())
                    <form action="/forgot-password" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <x-forms.input required type="email" name="email" label="{{ __('input.email') }}" autofocus />
                        <x-forms.button type="submit">{{ __('auth.forgot_password_send_email') }}</x-forms.button>
                    </form>
                @else
                    <div>Transactional emails are not active on this instance.</div>
                    <div>See how to set it in our <a class="dark:text-white" target="_blank"
                            href="{{ config('constants.docs.base_url') }}">docs</a>, or how to
                        manually reset password.
                    </div>
                @endif
                @if ($errors->any())
                    <div class="text-xs text-center text-error">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
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
