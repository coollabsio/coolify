@can('createAnyResource')
    <form wire:submit="createOriginApp" class="flex w-full flex-col gap-4">
        <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
            Connect a Cursor Origin App for private repositories, webhooks, and deployments.
        </p>

        <x-forms.input id="name" label="Name" required />

        @if (! isCloud())
            <div x-data="{ showWarning: @entangle('is_system_wide') }">
                <div class="max-w-xs">
                    <x-forms.checkbox id="is_system_wide" label="System wide"
                        helper="If checked, this Origin App will be available for everyone in this Coolify instance." />
                </div>
                <div x-cloak x-show="showWarning" x-transition class="mt-3">
                    <x-callout type="warning" title="Shared with every team">
                        System-wide Origin Apps are available to every team on this instance. Prefer team-specific apps when you need repository isolation.
                    </x-callout>
                </div>
            </div>
        @endif

        <x-forms.button class="mt-1 w-full justify-center" type="submit">
            Continue
        </x-forms.button>
    </form>
@else
    <x-callout type="danger" title="Insufficient permissions">
        You don't have permission to create new Origin Apps. Contact your team administrator.
    </x-callout>
@endcan
