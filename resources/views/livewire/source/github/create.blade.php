@can('createAnyResource')
    <form wire:submit="createGitHubApp" class="flex w-full flex-col gap-4">
        <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
            Connect a GitHub App for private repositories, webhooks, and commit / pull request deployments.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="organization" label="Organization"
                helper="If empty, your GitHub user will be used."
                placeholder="Personal account when empty" />
        </div>

        @if (! isCloud())
            <div x-data="{ showWarning: @entangle('is_system_wide') }">
                <div class="max-w-xs">
                    <x-forms.checkbox id="is_system_wide" label="System wide"
                        helper="If checked, this GitHub App will be available for everyone in this Coolify instance." />
                </div>
                <div x-cloak x-show="showWarning" x-transition class="mt-3">
                    <x-callout type="warning" title="Shared with every team">
                        System-wide GitHub Apps are available to every team on this instance. Prefer team-specific apps when you need repository isolation.
                    </x-callout>
                </div>
            </div>
        @endif

        <div x-data="{
            open: false,
        }" class="rounded-lg border border-neutral-200 dark:border-white/[0.08]">
            <button type="button" @click="open = !open"
                class="flex w-full items-center justify-between px-3 py-2.5 text-left text-[12px] font-medium text-neutral-700 transition-colors hover:bg-neutral-50 dark:text-fg-dim dark:hover:bg-white/[0.03]">
                Self-hosted / Enterprise GitHub
                <svg class="size-3.5 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            <div x-cloak x-show="open" x-collapse class="border-t border-neutral-200 px-3 py-3 dark:border-white/[0.08]">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.input id="html_url" label="HTML URL" required
                        helper="For GitHub Enterprise, enter your instance URL (e.g. https://github.example.com)." />
                    <x-forms.input id="api_url" label="API URL" required
                        helper="Usually https://api.github.com or your Enterprise API base URL." />
                    <x-forms.input id="custom_user" label="Custom Git user" required />
                    <x-forms.input id="custom_port" type="number" label="Custom Git port" required />
                </div>
            </div>
        </div>

        <x-forms.button class="mt-1 w-full justify-center" type="submit">
            Continue
        </x-forms.button>
    </form>
@else
    <x-callout type="danger" title="Insufficient permissions">
        You don't have permission to create new GitHub Apps. Contact your team administrator.
    </x-callout>
@endcan
