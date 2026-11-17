<div>
    @can('manageInvitations', currentTeam())
        <form wire:submit="viaLink">
            <x-application.settings-section title="Invite a member"
                description="Create a reusable invitation link or deliver it by email.">
                <x-slot:actions>
                    @if (is_transactional_emails_enabled())
                        <x-forms.button type="button" wire:click.prevent="viaEmail">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send email
                        </x-forms.button>
                    @endif
                    <x-forms.button type="submit" wire:target="viaLink"
                        defaultClass="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Generate link
                    </x-forms.button>
                </x-slot:actions>

                @if (!is_transactional_emails_enabled() && isInstanceAdmin())
                    <x-callout type="warning" title="Email delivery is not configured">
                        Configure transactional email in instance settings to send invitations directly.
                    </x-callout>
                @endif

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="email" type="email" label="Email address"
                        placeholder="teammate@example.com" required />
                    <div class="flex items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="role" label="Role" :options="array_values(array_filter([
                                auth()->user()->role() === 'owner' ? ['value' => 'owner', 'label' => 'Owner'] : null,
                                ['value' => 'admin', 'label' => 'Admin'],
                                ['value' => 'member', 'label' => 'Member'],
                            ]))" />
                        </div>
                        <div x-show="$wire.role === 'member'" class="shrink-0">
                            <x-forms.button type="button" defaultClass="button button-highlighted"
                                title="{{ \App\Livewire\Team\Member\Access::summary($grants) }}"
                                wire:click="$dispatch('open-invitation-access', { access: {{ Js::from($grants) }} })">
                                <x-reicon name="keys" class="size-3.5" />
                                Scopes
                            </x-forms.button>
                        </div>
                    </div>
                    <p x-show="$wire.role === 'member'" class="text-[12px] text-neutral-500 lg:col-span-2 dark:text-fg-faint">
                        Scopes: <span class="text-neutral-700 dark:text-fg-dim">{{ \App\Livewire\Team\Member\Access::summary($grants) }}</span>
                    </p>
                </div>
            </x-application.settings-section>
        </form>
    @endcan
</div>
