{{-- The new token value lives only in Alpine, so a Livewire update can not hide it or close its modal. --}}
<div x-data="{
    editingToken: null,
    issuedToken: null,
    editToken(values) {
        this.editingToken = values;
        for (const field of ['description', 'expiresInDays', 'permissions', 'scopeMode', 'selectedScopes']) {
            this.$wire.$set(`editForm.${field}`, values[field], false);
        }
        this.$dispatch('open-api-token-edit');
    },
}" @api-token-issued.window="issuedToken = $event.detail.token">
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>

        @php
            $expirationList = collect($expirationOptions)
                ->map(fn ($label, $days) => ['value' => (string) $days, 'label' => $label])
                ->values()
                ->push(['value' => '', 'label' => 'Never'])
                ->all();
        @endphp

        <div class="application-settings-form flex flex-col gap-6">
            <x-api-disabled-callout
                message="REST API requests are rejected. Tokens still authenticate the MCP endpoint when the MCP server is enabled." />

            <x-application.settings-section title="Issued tokens" flush>
                @can('create', App\Models\PersonalAccessToken::class)
                    <x-slot:actions>
                        <button type="button" class="button button-highlighted" @click="$dispatch('open-api-token-create')">
                            <x-reicon name="plus" class="size-3.5" />
                            New token
                        </button>
                    </x-slot:actions>
                @endcan
                <div x-data="{
                    search: '',
                    page: 1,
                    perPage: 10,
                    tokens: @js($tokens->map(fn ($token) => [
                        'id' => (string) $token->id,
                        'search' => Str::lower(collect([$token->name, ...($token->abilities ?? [])])
                            ->merge($token->scopes === null
                                ? ['All resources in the team']
                                : collect($token->scopes)->map(fn ($scope) => $scopeLabels[($scope['scope'] ?? '').':'.($scope['uuid'] ?? '')] ?? null)->filter())
                            ->join(' ')),
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
                        <div class="flex items-center gap-2 border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                            <div class="relative w-full max-w-sm">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                                    placeholder="Search tokens" aria-label="Search tokens"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                                <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                                    class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                                    aria-label="Clear search">
                                    <x-reicon name="x" class="size-3" />
                                </button>
                            </div>
                            <x-helper label="Search help"
                                helper="Search by token name, ability, or the name of a project, environment, or resource that the token can use." />
                        </div>
                    @endif

                    @if ($tokens->isEmpty())
                        <div class="p-4">
                            <x-empty title="No API tokens" description="Create a token when an external client needs access."
                                icon-name="keys" size="sm" />
                        </div>
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
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <span class="truncate text-[12px] font-medium text-black dark:text-fg">{{ $token->name }}</span>
                                            @if ($token->isPaused())
                                                <x-status-badge label="Paused" type="warning" size="sm" />
                                            @endif
                                        </div>
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                            @if ($token->scopes === null)
                                                All resources in the team
                                            @else
                                                @php
                                                    $tokenScopes = collect($token->scopes)
                                                        ->map(fn ($scope) => [
                                                            'type' => $scope['scope'] ?? 'scope',
                                                            'label' => $scopeLabels[($scope['scope'] ?? '').':'.($scope['uuid'] ?? '')] ?? 'Unavailable '.($scope['scope'] ?? 'scope'),
                                                        ])
                                                        ->sortBy([
                                                            fn ($a, $b) => array_search($a['type'], ['project', 'environment', 'resource']) <=> array_search($b['type'], ['project', 'environment', 'resource']),
                                                            fn ($a, $b) => strnatcasecmp($a['label'], $b['label']),
                                                        ]);
                                                @endphp
                                                {{-- The modal is wire:ignore, so a new key re-renders it when the scopes change. --}}
                                                <x-modal-input title="Access of {{ $token->name }}"
                                                    wire:key="api-token-scopes-{{ $token->id }}-{{ md5(json_encode($token->scopes)) }}"
                                                    subtitle="This token can use only these projects, environments, and resources.">
                                                    <x-slot:content>
                                                        <button type="button"
                                                            class="max-w-full truncate underline decoration-dotted underline-offset-2 hover:text-black dark:hover:text-fg">
                                                            {{ $tokenScopes->countBy('type')->map(fn ($count, $type) => $count.' '.Str::plural($type, $count))->join(', ') }}
                                                        </button>
                                                    </x-slot:content>
                                                    <div
                                                        class="divide-y divide-neutral-200 rounded-md border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
                                                        @foreach ($tokenScopes as $tokenScope)
                                                            <div class="flex min-h-8 items-center gap-3 px-3 py-1.5 text-[12px]">
                                                                <span class="w-20 shrink-0 text-[11px] capitalize text-neutral-500 dark:text-fg-dim">{{ $tokenScope['type'] }}</span>
                                                                <span class="min-w-0 flex-1 break-words text-black dark:text-fg">{{ $tokenScope['label'] }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </x-modal-input>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        @foreach ($token->abilities ?? [] as $ability)
                                            <span @class([
                                                'inline-flex min-h-5 items-center rounded-md border px-2 py-0.5 text-[10px] font-medium',
                                                'border-error/20 bg-error/10 text-error' => in_array($ability, ['root', 'delete']),
                                                'border-warning/20 bg-warning/10 text-warning' => in_array($ability, ['write', 'write:sensitive']),
                                                'border-coollabs/20 bg-coollabs/10 text-coollabs dark:border-brand-light/20 dark:bg-brand/15 dark:text-brand-light' => $ability === 'deploy',
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
                                            <x-status-badge label="Expired" type="error" size="sm" />
                                        @else
                                            {{ $token->expires_at->format('Y-m-d') }}
                                        @endif
                                    </div>
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->id() === $token->tokenable_id)
                                            <x-table.row-actions label="Token actions">
                                                <x-table.row-action
                                                    x-on:click="editToken({{ \Illuminate\Support\Js::from(App\Livewire\Forms\ApiTokenForm::valuesOf($token)) }})">
                                                    Edit
                                                </x-table.row-action>
                                                <x-table.row-action
                                                    wire:click="setPaused({{ $token->id }}, {{ $token->isPaused() ? 'false' : 'true' }})">
                                                    {{ $token->isPaused() ? 'Resume' : 'Pause' }}
                                                </x-table.row-action>
                                                <x-modal-confirmation title="Roll API Token?" buttonFullWidth
                                                    submitAction="roll({{ $token->id }})" :actions="[
                                                        'A new secret replaces this token. The current secret stops working at once.',
                                                        'The description, permissions, scopes, and expiry do not change.',
                                                    ]"
                                                    confirmationText="{{ $token->name }}"
                                                    confirmationLabel="Enter the token description to confirm"
                                                    shortConfirmationLabel="Token description"
                                                    :confirmWithPassword="false" step2ButtonText="Roll token">
                                                    <x-slot:trigger>
                                                        <x-table.row-action>Roll</x-table.row-action>
                                                    </x-slot:trigger>
                                                </x-modal-confirmation>
                                                <x-slot:danger>
                                                <x-modal-confirmation title="Confirm API Token Revocation?" buttonFullWidth
                                                    submitAction="revoke({{ $token->id }})" :actions="[
                                                        'This API token will be permanently revoked.',
                                                    ]"
                                                    confirmationText="{{ $token->name }}"
                                                    confirmationLabel="Enter the token description to confirm"
                                                    shortConfirmationLabel="Token description"
                                                    :confirmWithPassword="false" step2ButtonText="Revoke token">
                                                    <x-slot:trigger>
                                                        <x-table.row-action>Revoke</x-table.row-action>
                                                    </x-slot:trigger>
                                                </x-modal-confirmation>
                                                </x-slot:danger>
                                            </x-table.row-actions>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div x-cloak x-show="filteredTokens.length === 0" class="p-4">
                            <x-empty size="sm" title="No matching tokens"
                                description="Try a different description or permission." />
                        </div>

                        <x-client-pagination total="filteredTokens.length" x-cloak x-show="filteredTokens.length > 0"
                            summary="`${firstVisibleRow}-${lastVisibleRow} of ${filteredTokens.length}`"
                            page-size-model="perPage" storage-key="coolify.page-size.api-tokens"
                            previous-action="goToPage(page - 1)" next-action="goToPage(page + 1)"
                            next-disabled="page >= lastPage" />
                    @endif
                </div>
            </x-application.settings-section>
        </div>

        @can('create', App\Models\PersonalAccessToken::class)
            <x-modal-input title="New API token" :wireIgnore="false" :closeOutside="false"
                @open-api-token-create.window="modalOpen = true" @api-token-issued.window="modalOpen = false">
                <x-slot:content><span></span></x-slot:content>

                <form wire:submit="addNewToken" id="api-token-create-form" class="lg:w-3xl">
                    @include('livewire.security.partials.api-token-fields', [
                        'form' => 'form',
                        'expiryOptions' => $expirationList,
                        'inModal' => true,
                    ])
                </form>

                <x-slot:footer>
                    <x-forms.button @click="modalOpen = false">Cancel</x-forms.button>
                    <x-forms.button isHighlighted type="submit" form="api-token-create-form">Create token</x-forms.button>
                </x-slot:footer>
            </x-modal-input>

            <x-modal-input title="Edit API token" :wireIgnore="false" :closeOutside="false"
                @open-api-token-edit.window="modalOpen = true" @api-token-updated.window="modalOpen = false">
                <x-slot:content><span></span></x-slot:content>

                <form x-on:submit.prevent="$wire.updateToken(editingToken.id)" id="api-token-edit-form" class="lg:w-3xl">
                    <p class="mb-4 text-[12px] text-neutral-500 dark:text-fg-dim">
                        The token value does not change. Clients that use it get the new access on their next request.
                    </p>

                    @include('livewire.security.partials.api-token-fields', [
                        'form' => 'editForm',
                        'expiryOptions' => [['value' => 'keep', 'label' => 'Keep current'], ...$expirationList],
                        'inModal' => true,
                    ])
                </form>

                <x-slot:footer>
                    <x-forms.button @click="modalOpen = false">Cancel</x-forms.button>
                    <x-forms.button isHighlighted type="submit" form="api-token-edit-form">Save changes</x-forms.button>
                </x-slot:footer>
            </x-modal-input>
        @endcan

        <x-modal-input title="Copy your token" :closeOutside="false"
            @api-token-issued.window="modalOpen = true" x-effect="if (!modalOpen) issuedToken = null">
            <x-slot:content><span></span></x-slot:content>

            <div class="flex flex-col gap-3 lg:w-2xl">
                <p class="text-sm text-neutral-500 dark:text-fg-dim">
                    Copy the token now. It is not shown again after you close this window.
                </p>
                <div class="relative">
                    <input type="text" :value="issuedToken" readonly @focus="$el.select()"
                        class="input w-full pr-12! font-mono text-[12px] text-black dark:text-fg">
                    <x-copy-button resolve="issuedToken" label="Copy token"
                        class="absolute top-1/2 right-2 -translate-y-1/2" />
                </div>
            </div>

            <x-slot:footer>
                <x-forms.button isHighlighted @click="modalOpen = false">Done</x-forms.button>
            </x-slot:footer>
        </x-modal-input>
    </x-security.settings-layout>
</div>
