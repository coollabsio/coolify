<section class="bg-gray-50 dark:bg-base">
    <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
        <a class="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
            Coolify
        </a>
        <div class="w-full bg-white shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base ">
            <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                <form class="flex flex-col gap-2" wire:submit='submit'>
                    <x-forms.input id="email" type="email" placeholder="Email" readonly label="Email" />
                    <x-forms.input id="password" type="password" placeholder="New Password" label="New Password"
                        required />
                    <x-forms.input id="password_confirmation" type="password" placeholder="Confirm New Password"
                        label="Confirm New Password" required />
                    <x-forms.button type="submit">Reset Password</x-forms.button>
                </form>
            </div>
        </div>
    </div>
</section>
