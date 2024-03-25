<div class="min-h-screen hero">
    <div class="w-96 min-w-fit">
        <div class="flex flex-col items-center pb-8">
            <a href="{{ route('dashboard') }}">
                <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
            </a>
        </div>
        <div class="flex items-center justify-center pb-4 text-center">
            <h2>Self-hosting in the cloud
                <svg class="inline-block w-8 h-8 dark:text-warning width="512" height="512" viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg">
                    <g fill="currentColor" fill-rule="evenodd" clip-rule="evenodd">
                        <path
                            d="M13 4h-1a4.002 4.002 0 0 0-3.874 3H8a4 4 0 1 0 0 8h8a4 4 0 0 0 .899-7.899A4.002 4.002 0 0 0 13 4Z"
                            opacity=".2" />
                        <path
                            d="M11 3h-1a4.002 4.002 0 0 0-3.874 3H6a4 4 0 1 0 0 8h8a4 4 0 0 0 .899-7.899A4.002 4.002 0 0 0 11 3ZM6.901 7l.193-.75A3.002 3.002 0 0 1 10 4h1c1.405 0 2.614.975 2.924 2.325l.14.61l.61.141A3.001 3.001 0 0 1 14 13H6a3 3 0 1 1 0-6h.901Z" />
                    </g>
                </svg>
            </h2>
        </div>
        <form class="flex items-end gap-2" wire:submit='submit'>
            <x-forms.input id="email" type="email" label="Email" placeholder="youareawesome@protonmail.com" />
            <x-forms.button type="submit">Join Waitlist</x-forms.button>
        </form>
        <div>People waiting in the line: <span class="font-bold dark:text-warning">{{ $waitingInLine }}</div>
        <div>Already using Coolify Cloud: <span class="font-bold dark:text-warning">{{ $users }}</div>
        <div class="pt-8">
            This is a paid & hosted version of Coolify.<br> See the pricing <a href="https://coolify.io/pricing"
                class="dark:text-warning">here</a>.
        </div>
        <div class="pt-4">
            If you are looking for the self-hosted version go <a href="https://coolify.io"
                class="dark:text-warning">here</a>.
        </div>
    </div>
</div>
