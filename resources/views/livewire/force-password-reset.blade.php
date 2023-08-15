<div class="min-h-screen hero">
    <div class="w-96 min-w-fit">
        <div class="flex flex-col items-center pb-8">
            <a href="{{ route('dashboard') }}">
                <div class="text-5xl font-bold tracking-tight text-center text-white">Coolify</div>
            </a>
        </div>
        <div class="flex items-center justify-center pb-4 text-center">
            <h2>Set your initial password</h2>
        </div>
        <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
            <x-forms.input id="email" type="email" placeholder="Email" readonly label="Email" />
            <x-forms.input id="password" type="password" placeholder="New Password" label="New Password" required />
            <x-forms.input id="password_confirmation" type="password" placeholder="Confirm New Password" label="Confirm New Password" required  />
            <x-forms.button type="submit">Reset Password</x-forms.button>
        </form>
    </div>
</div>
