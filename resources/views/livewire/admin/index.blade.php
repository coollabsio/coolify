<div>
    <x-slot:title>Admin | Coolify</x-slot>
    <div class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Current user</div>
                <div class="mt-2 truncate text-sm font-semibold text-black dark:text-fg">
                    {{ auth()->user()->name }}
                </div>
                <div class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-faint">
                    {{ auth()->user()->email }}
                </div>
            </div>
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Active subscribers</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">
                    {{ $activeSubscribers }}
                </div>
            </div>
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Inactive subscribers</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">
                    {{ $inactiveSubscribers }}
                </div>
            </div>
        </section>

        @if (session('impersonating'))
            <x-callout type="warning" title="Impersonation is active">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>You are viewing Coolify as {{ auth()->user()->name }}.</span>
                    <x-forms.button wire:click="back">Return to root user</x-forms.button>
                </div>
            </x-callout>
        @endif

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>User lookup</h2>
                    <p>Find an account and switch into it for support or administration.</p>
                </div>
            </div>
            <div class="application-settings-section-body p-0!">
                <form wire:submit="submitSearch"
                    class="flex items-end gap-2 border-b border-neutral-200 p-4 dark:border-white/[0.06]">
                    <div class="max-w-md flex-1">
                        <x-forms.input wire:model="search" label="Name or email"
                            placeholder="Search for a user…" />
                    </div>
                    <x-forms.button type="submit">
                        <x-reicon name="search" class="size-4" />
                        Search
                    </x-forms.button>
                </form>

                @if ($search)
                    @if ($foundUsers->isEmpty())
                        <x-empty size="sm" title="No users found"
                            description="No account matches {{ $search }}." />
                    @else
                        <div class="data-table">
                            <div class="data-table-header admin-search-table-grid">
                                <span>Name</span>
                                <span>Email</span>
                                <span>Subscription</span>
                                <span class="text-right">Action</span>
                            </div>
                            @foreach ($foundUsers as $user)
                                @php
                                    $hasActiveSubscription = $user->teams()
                                        ->whereRelation('subscription', 'stripe_invoice_paid', true)
                                        ->exists();
                                @endphp
                                <div
                                    class="data-table-row admin-search-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]">
                                    <div class="truncate text-[12px] font-semibold text-black dark:text-fg">
                                        {{ $user->name }}
                                    </div>
                                    <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                        {{ $user->email }}
                                    </div>
                                    <div>
                                        <x-status-badge :status="$hasActiveSubscription ? 'Active' : 'Inactive'"
                                            :type="$hasActiveSubscription ? 'success' : 'neutral'" />
                                    </div>
                                    <div class="flex justify-end">
                                        <button type="button" class="button"
                                            wire:click="switchUser({{ $user->id }})">
                                            Switch user
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                            <div
                                class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                                {{ $foundUsers->count() }} {{ Str::plural('matching user', $foundUsers->count()) }}
                            </div>
                        </div>
                    @endif
                @else
                    <x-empty size="sm" title="Search for an account"
                        description="Enter a name or email address to begin.">
                        <x-slot:icon>
                            <x-reicon name="profile" class="size-5" />
                        </x-slot:icon>
                    </x-empty>
                @endif
            </div>
        </section>
    </div>
</div>
