<div class="min-h-screen hero">
    <div class="w-96 min-w-fit">
        <div class="flex items-center justify-center pb-4 text-center">
            <h2>Start self-hosting in the
                <svg class="inline-block w-8 h-8 text-warning width="512" height="512" viewBox="0 0 20 20"
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
        <form class="flex items-end gap-2" wire:submit.prevent='submit'>
            <x-forms.input id="email" type="email" label="Email" placeholder="youareawesome@protonmail.com" />
            <x-forms.button type="submit">Join Waitlist</x-forms.button>
        </form>
        Waiting: {{$waiting_in_line}}
    </div>
</div>
