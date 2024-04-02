<div class="min-h-screen hero">
    <div class="w-96 min-w-fit">
        <div class="flex flex-col items-center">
            <a href="{{ route('dashboard') }}">
                <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
            </a>
        </div>

        <form class="flex flex-col gap-2" wire:submit='submit'>
            <x-forms.input id="email" type="email" placeholder="Email" readonly label="Email" />
            <x-forms.input id="password" type="password" placeholder="New Password" label="New Password" required />
            <x-forms.input id="password_confirmation" type="password" placeholder="Confirm New Password"
                label="Confirm New Password" required />
            <x-forms.button type="submit">Reset Password</x-forms.button>
        </form>
    </div>
</div>
