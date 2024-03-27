<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <a class="flex items-center mb-1 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                Coolify
            </a> <div class="flex items-center gap-2">
                {{ __('auth.forgot_password') }}
            </div>
            <div
                class="w-full bg-white shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base ">
                <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
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
    </section>

</x-layout-simple>
