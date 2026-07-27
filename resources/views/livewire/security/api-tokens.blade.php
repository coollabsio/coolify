<div>
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>

    <x-security.navbar />

    @if (!$isApiEnabled)
        <div class="application-settings-form">
            <x-application.settings-section title="API disabled"
                description="Enable the Coolify API before creating access tokens.">
                <x-empty title="API access is turned off"
                    description="Enable API access in instance settings to issue tokens." size="sm">
                    <x-slot:icon>
                        <x-reicon name="keys" class="size-6" />
                    </x-slot:icon>
                    <x-slot:actions>
                        <a href="{{ route('settings.advanced') }}" class="button" {{ wireNavigate() }}>
                            Open settings
                        </a>
                    </x-slot:actions>
                </x-empty>
            </x-application.settings-section>
        </div>
    @else
        @php
            $expirationList = collect($expirationOptions)
                ->map(fn ($label, $days) => ['value' => (string) $days, 'label' => $label])
                ->values()
                ->push(['value' => '', 'label' => 'Never'])
                ->all();
        @endphp

        <div class="application-settings-form flex flex-col gap-6">
            @can('create', App\Models\PersonalAccessToken::class)
                <form wire:submit="addNewToken">
                    <x-application.settings-section title="New API token">
                        <x-slot:actions>
                            <button type="submit"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                <x-reicon name="plus" class="size-3.5" />
                                Create token
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input required id="description" label="Description"
                                placeholder="CI deployment token" />
                            <x-forms.listbox id="expiresInDays" label="Expires in"
                                :options="$expirationList" />
                        </div>

                        <div class="mt-5 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <div class="mb-3 flex items-center gap-2">
                                <h4 class="text-[12px] font-semibold text-black dark:text-fg">Permissions</h4>
                                <x-helper helper="Only grant the abilities this token needs." />
                            </div>
                            <div class="grid gap-3 lg:grid-cols-2">
                                @if ($canUseRootPermissions)
                                    <x-forms.checkbox label="Root" wire:model.live="permissions" domValue="root"
                                        helper="Full access to every API operation." :checked="in_array('root', $permissions)" />
                                @else
                                    <x-forms.checkbox label="Root" disabled domValue="root"
                                        helper="Requires an admin or owner role." :checked="false" />
                                @endif

                                @if (!in_array('root', $permissions))
                                    @if ($canUseWritePermissions)
                                        <x-forms.checkbox label="Write" wire:model.live="permissions"
                                            domValue="write" helper="Create and update resources."
                                            :checked="in_array('write', $permissions)" />
                                    @else
                                        <x-forms.checkbox label="Write" disabled domValue="write"
                                            helper="Requires an admin or owner role." :checked="false" />
                                    @endif

                                    @if ($canUseDeployPermissions)
                                        <x-forms.checkbox label="Deploy" wire:model.live="permissions"
                                            domValue="deploy" helper="Trigger deployments through webhooks."
                                            :checked="in_array('deploy', $permissions)" />
                                    @else
                                        <x-forms.checkbox label="Deploy" disabled domValue="deploy"
                                            helper="Requires an admin or owner role." :checked="false" />
                                    @endif

                                    <x-forms.checkbox label="Read" wire:model.live="permissions" domValue="read"
                                        helper="Read non-sensitive resource data."
                                        :checked="in_array('read', $permissions)" />

                                    @if ($canUseSensitivePermissions)
                                        <x-forms.checkbox label="Read sensitive data" wire:model.live="permissions"
                                            domValue="read:sensitive"
                                            helper="Include secrets, logs, passwords, and Compose content."
                                            :checked="in_array('read:sensitive', $permissions)" />
                                    @else
                                        <x-forms.checkbox label="Read sensitive data" disabled
                                            domValue="read:sensitive" helper="Requires an admin or owner role."
                                            :checked="false" />
                                    @endif
                                @endif
                            </div>
                        </div>
                    </x-application.settings-section>
                </form>
            @endcan

            @if (session()->has('token'))
                <x-application.settings-section title="Copy your token"
                    description="This value will not be shown again after you leave this page.">
                    <div class="relative" x-data="{ copied: false }">
                        <input type="text" value="{{ session('token') }}" readonly
                            class="w-full pr-12! font-mono text-[12px]">
                        <button type="button"
                            x-on:click="copied = true; navigator.clipboard.writeText(@js(session('token'))); setTimeout(() => copied = false, 1200)"
                            class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            title="Copy token">
                            <x-reicon name="file-content" class="size-3.5" />
                        </button>
                        <span x-cloak x-show="copied"
                            class="absolute top-full right-0 mt-1 text-[10px] text-success">Copied</span>
                    </div>
                </x-application.settings-section>
            @endif

            <x-application.settings-section title="Issued tokens" flush>
                <div x-data="{
                    search: '',
                    page: 1,
                    perPage: 10,
                    tokens: @js($tokens->map(fn ($token) => [
                        'id' => (string) $token->id,
                        'search' => strtolower($token->name . ' ' . implode(' ', $token->abilities ?? [])),
                    ])->values()),
                    get filteredTokens() {
                        const query = this.search.trim().toLowerCase();

                        return query
                            ? this.tokens.filter(token => token.search.includes(query))
                            : this.tokens;
                    },
                    get lastPage() {
                        return Math.max(1, Math.ceil(this.filteredTokens.length / this.perPage));
                    },
                    get firstVisibleRow() {
                        return this.filteredTokens.length === 0 ? 0 : ((this.page - 1) * this.perPage) + 1;
                    },
                    get lastVisibleRow() {
                        return Math.min(this.page * this.perPage, this.filteredTokens.length);
                    },
                    isVisible(id) {
                        const start = (this.page - 1) * this.perPage;

                        return this.filteredTokens
                            .slice(start, start + this.perPage)
                            .some(token => token.id === String(id));
                    },
                    goToPage(page) {
                        this.page = Math.min(Math.max(page, 1), this.lastPage);
                    }
                }">
                    @if ($tokens->count() > 1)
                        <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                            <div class="relative max-w-sm">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                                    placeholder="Search tokens" aria-label="Search tokens"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                                <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                                    class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                                    aria-label="Clear search">
                                    <x-reicon name="x" class="size-3" />
                                </button>
                            </div>
                        </div>
                    @endif

                    @if ($tokens->isEmpty())
                        <x-empty title="No API tokens" description="Create a token when an external client needs access."
                            size="sm">
                            <x-slot:icon>
                                <x-reicon name="keys" class="size-6" />
                            </x-slot:icon>
                        </x-empty>
                    @else
                        <div x-cloak x-show="filteredTokens.length > 0" class="data-table">
                            <div class="data-table-header api-tokens-table-grid">
                                <span>Description</span>
                                <span>Permissions</span>
                                <span>Last used</span>
                                <span>Created</span>
                                <span>Expires</span>
                                <span class="text-right">Actions</span>
                            </div>
                            @foreach ($tokens as $token)
                                <div wire:key="api-token-{{ $token->id }}"
                                    x-show="isVisible(@js((string) $token->id))"
                                    class="data-table-row api-tokens-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                    <div class="min-w-0 truncate text-[12px] font-medium text-black dark:text-fg">
                                        {{ $token->name }}
                                    </div>
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        @foreach ($token->abilities ?? [] as $ability)
                                            <span @class([
                                                'inline-flex min-h-5 items-center rounded-md border px-2 py-0.5 text-[10px] font-medium',
                                                'border-error/20 bg-error/10 text-error' => $ability === 'root',
                                                'border-warning/20 bg-warning/10 text-warning' => in_array($ability, ['write', 'write:sensitive']),
                                                'border-coollabs/20 bg-coollabs/10 text-coollabs dark:border-warning/20 dark:bg-warning/10 dark:text-warning' => $ability === 'deploy',
                                                'border-neutral-200 bg-neutral-100 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.06] dark:text-fg-dim' => in_array($ability, ['read', 'read:sensitive']),
                                            ])>{{ $ability }}</span>
                                        @endforeach
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $token->last_used_at?->diffForHumans() ?? 'Never' }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $token->created_at->format('Y-m-d') }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        @if (!$token->expires_at)
                                            Never
                                        @elseif ($token->expires_at->isPast())
                                            <x-status-badge label="Expired" type="error" />
                                        @else
                                            {{ $token->expires_at->format('Y-m-d') }}
                                        @endif
                                    </div>
                                    <div class="flex justify-end">
                                        @if (auth()->id() === $token->tokenable_id)
                                            <x-modal-confirmation title="Confirm API Token Revocation?"
                                                submitAction="revoke({{ $token->id }})" :actions="[
                                                    'This API token will be permanently revoked.',
                                                ]"
                                                confirmationText="{{ $token->name }}"
                                                confirmationLabel="Enter the token description to confirm"
                                                shortConfirmationLabel="Token description"
                                                :confirmWithPassword="false" step2ButtonText="Revoke token">
                                                <x-slot:trigger>
                                                    <button type="button"
                                                        class="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-[11px] font-medium text-error transition-colors hover:bg-error/10">
                                                        <x-reicon name="trash" class="size-3" />
                                                        Revoke
                                                    </button>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div x-cloak x-show="filteredTokens.length === 0">
                            <x-empty size="sm" title="No matching tokens"
                                description="Try a different description or permission." />
                        </div>

                        <div x-cloak x-show="filteredTokens.length > 0"
                            class="flex min-h-14 items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                Showing
                                <span class="tabular-nums text-black dark:text-fg"
                                    x-text="`${firstVisibleRow}–${lastVisibleRow}`"></span>
                                of
                                <span class="tabular-nums text-black dark:text-fg"
                                    x-text="filteredTokens.length"></span>
                            </p>
                            <div
                                class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
                                <button type="button"
                                    class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                    aria-label="First page" title="First page" x-on:click="goToPage(1)"
                                    x-bind:disabled="page === 1">
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button type="button"
                                    class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                    aria-label="Previous page" title="Previous page"
                                    x-on:click="goToPage(page - 1)" x-bind:disabled="page === 1">
                                    <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                </button>
                                <span
                                    class="flex min-w-10 items-center justify-center border-r border-neutral-200 px-3 text-[12px] font-medium tabular-nums text-black dark:border-white/[0.10] dark:text-fg"
                                    x-text="page"></span>
                                <button type="button"
                                    class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                    aria-label="Next page" title="Next page" x-on:click="goToPage(page + 1)"
                                    x-bind:disabled="page === lastPage">
                                    <x-reicon name="arrow-right" class="size-3.5" />
                                </button>
                                <button type="button"
                                    class="flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                    aria-label="Last page" title="Last page" x-on:click="goToPage(lastPage)"
                                    x-bind:disabled="page === lastPage">
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </x-application.settings-section>
        </div>
    @endif
</div>
