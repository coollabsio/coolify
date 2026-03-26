<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                </div>

                <div class="space-y-6">
                    <div class="p-6 rounded-lg border border-neutral-500/20 bg-white/5">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Team Invitation</h2>

                        <p class="text-sm text-gray-600 dark:text-neutral-400 mb-2">
                            You have been invited to join:
                        </p>
                        <p class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            {{ $team->name }}
                        </p>

                        <p class="text-sm text-gray-600 dark:text-neutral-400 mb-1">
                            Role: <span class="font-medium text-gray-900 dark:text-white">{{ ucfirst($invitation->role) }}</span>
                        </p>

                        @if ($alreadyMember)
                            <div class="mt-4 p-3 bg-warning/10 border border-warning rounded-lg">
                                <p class="text-sm text-warning">You are already a member of this team.</p>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('team.invitation.accept', $invitation->uuid) }}" class="mt-6">
                            @csrf
                            <x-forms.button class="w-full justify-center py-3 box-boarding" type="submit" isHighlighted>
                                {{ $alreadyMember ? 'Dismiss Invitation' : 'Accept Invitation' }}
                            </x-forms.button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
