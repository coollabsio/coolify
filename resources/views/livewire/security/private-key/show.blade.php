<div>
    <div class="relative">
    <form wire:submit="changePrivateKey" class="flex flex-col gap-4"
        wire:loading.class="pointer-events-none opacity-50" wire:target="delete">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$private_key" id="name" label="Name" required />
            <x-forms.input canGate="update" :canResource="$private_key" id="description" label="Description" />
            <div class="lg:col-span-2">
                <x-forms.input canGate="update" :canResource="$private_key" readonly id="public_key"
                    label="Public key" helper="Copy this value to ~/.ssh/authorized_keys on the target server." />
            </div>
            @include('livewire.security.private-key.partials.key-field')
            <div class="lg:col-span-2">
                <div class="mb-1.5 flex items-baseline justify-between gap-3">
                    <label class="text-[13px] font-medium">Used by</label>
                    @if ($counts)
                        <span class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ collect($counts)->map(fn ($count, $type) => $count.' '.str($type)->plural($count)->lower())->implode(' · ') }}
                        </span>
                    @endif
                </div>
                @if (! $counts)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Nothing uses this key.</p>
                @else
                    @if (array_sum($counts) > 5)
                        <input type="search" class="input mb-2" placeholder="Search by name"
                            aria-label="Search where this key is used" wire:model.live.debounce.300ms="usageSearch"
                            x-on:keydown.enter.prevent />
                    @endif
                    <ul class="max-h-48 divide-y divide-neutral-200 overflow-y-auto rounded-md border border-neutral-200 text-sm dark:divide-white/[0.06] dark:border-white/[0.08]">
                        @forelse ($rows as $usage)
                            <li wire:key="usage-{{ $usage['type'] }}-{{ $usage['url'] ?? $usage['name'] }}" class="flex items-center gap-2 px-3 py-1">
                                <span class="w-20 shrink-0 text-xs text-neutral-500 dark:text-fg-dim">{{ $usage['type'] }}</span>
                                @if ($usage['url'])
                                    <a class="truncate underline" href="{{ $usage['url'] }}" {{ wireNavigate() }}>{{ $usage['name'] }}</a>
                                @else
                                    <span class="truncate">{{ $usage['name'] }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="px-3 py-1 text-xs text-neutral-500 dark:text-fg-dim">No match.</li>
                        @endforelse
                        @if ($matches > $rows->count())
                            <li class="px-3 py-1 text-xs text-neutral-500 dark:text-fg-dim">and {{ $matches - $rows->count() }} more</li>
                        @endif
                    </ul>
                @endif
            </div>
        </div>
        <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            @can('delete', $private_key)
                <x-modal-confirmation title="Confirm Private Key Deletion?" isErrorButton buttonTitle="Delete"
                    @confirm-old-key-deletion.window="if ($event.detail.id === {{ $private_key->id }}) modalOpen = true"
                    submitAction="delete" :disabled="$isInUse" :disabledTooltip="$deleteDisabledReason"
                    :actions="['This private key will be permanently deleted.', 'Coolify does not remove its public key from servers. To remove it, rotate the key before you delete it.']" confirmationText="{{ $private_key->name }}"
                    :confirmWithPassword="false" step2ButtonText="Delete private key" />
            @endcan
            <div class="flex items-center gap-2">
                @can('update', $private_key)
                    <livewire:security.private-key.rotate :privateKeyId="$private_key->id" wire:key="rotate-modal-{{ $private_key->id }}" />
                @endcan
                <x-forms.button type="submit" isHighlighted>Save changes</x-forms.button>
            </div>
        </div>
    </form>
    <div wire:loading.flex wire:target="delete"
        class="absolute inset-0 z-10 items-center justify-center rounded-lg bg-white/50 dark:bg-black/40">
        <x-loading text="Deleting private key..." />
    </div>
    </div>
</div>
