<div>
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>
    <x-security.navbar />
    <div class="form-section-title mb-6">
        <h2>API Tokens</h2>
    </div>
    <div>
        @if (!$isApiEnabled)
            <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">API is disabled. If you want to use the API, please enable it in the <a
                    href="{{ route('settings.advanced') }}" class="underline dark:text-white" {{ wireNavigate() }}>Settings</a> menu.</p>
        @else
            <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Tokens are created with the current team as scope.</p>
    <div class="flex flex-col gap-6">
        <div class="form-card">
            <h3>New Token</h3>
            @can('create', App\Models\PersonalAccessToken::class)
                <form class="flex flex-col gap-10" wire:submit='addNewToken'>
                    <div class="flex gap-2 items-end w-96">
                        <x-forms.input required id="description" label="Description" />
                        <x-forms.button type="submit">Create</x-forms.button>
                    </div>
                    <div class="flex">
                        Permissions
                        <x-helper class="px-1" helper="These permissions will be granted to the token." /><span
                            class="pr-1">:</span>
                        <div class="flex gap-1 font-bold dark:text-white">
                            @if ($permissions)
                                @foreach ($permissions as $permission)
                                    <div>{{ $permission }}</div>
                                @endforeach
                            @endif
                        </div>
                    </div>

                    <h4>Token Permissions</h4>
                    <div class="w-64">
                        @if ($canUseRootPermissions)
                            <x-forms.checkbox label="root" wire:model.live="permissions" domValue="root"
                                helper="Root access, be careful!" :checked="in_array('root', $permissions)"></x-forms.checkbox>
                        @else
                            <x-forms.checkbox label="root (admin/owner only)" disabled domValue="root"
                                helper="Root access requires admin or owner role" :checked="false"></x-forms.checkbox>
                        @endif

                        @if (!in_array('root', $permissions))
                            @if ($canUseWritePermissions)
                                <x-forms.checkbox label="write" wire:model.live="permissions" domValue="write"
                                    helper="Write access to all resources." :checked="in_array('write', $permissions)"></x-forms.checkbox>
                            @else
                                <x-forms.checkbox label="write (admin/owner only)" disabled domValue="write"
                                    helper="Write access requires admin or owner role" :checked="false"></x-forms.checkbox>
                            @endif

                            <x-forms.checkbox label="deploy" wire:model.live="permissions" domValue="deploy"
                                helper="Can trigger deploy webhooks." :checked="in_array('deploy', $permissions)"></x-forms.checkbox>
                            <x-forms.checkbox label="read" domValue="read" wire:model.live="permissions" domValue="read"
                                :checked="in_array('read', $permissions)"></x-forms.checkbox>
                            <x-forms.checkbox label="read:sensitive" wire:model.live="permissions" domValue="read:sensitive"
                                helper="Responses will include secrets, logs, passwords, and compose file contents."
                                :checked="in_array('read:sensitive', $permissions)"></x-forms.checkbox>
                        @endif
                    </div>
                    @if (in_array('root', $permissions))
                        <div class="font-bold dark:text-warning">Root access, be careful!</div>
                    @endif
                </form>
            @endcan
            @if (session()->has('token'))
                <div class="py-4 font-bold dark:text-warning">Please copy this token now. For your security, it won't be shown
                    again.
                </div>
                <div class="pb-4 font-bold dark:text-white"> {{ session('token') }}</div>
            @endif
        </div>

        <div class="form-card">
            <h3>Issued Tokens</h3>
            <div class="grid gap-2 lg:grid-cols-1 mt-4">
                @forelse ($tokens as $token)
                    <div wire:key="token-{{ $token->id }}"
                        class="flex flex-col gap-2 p-4 border border-neutral-200 dark:border-coolgray-300 rounded-lg bg-white dark:bg-coolgray-100 hover:no-underline">
                        <div>Description: {{ $token->name }}</div>
                        <div>Last used: {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}</div>
                        <div class="flex gap-1">
                            @if ($token->abilities)
                                Permissions:
                                @foreach ($token->abilities as $ability)
                                    <div class="font-bold dark:text-white">{{ $ability }}</div>
                                @endforeach
                            @endif
                        </div>

                        @if (auth()->id() === $token->tokenable_id)
                            <x-modal-confirmation title="Confirm API Token Revocation?" isErrorButton buttonTitle="Revoke token"
                                submitAction="revoke({{ data_get($token, 'id') }})" :actions="[
                                    'This API Token will be revoked and permanently deleted.',
                                    'Any API call made with this token will fail.',
                                ]"
                                confirmationText="{{ $token->name }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the API Token Description below"
                                shortConfirmationLabel="API Token Description" :confirmWithPassword="false"
                                step2ButtonText="Revoke API Token" />
                        @endif
                    </div>
                @empty
                    <div class="empty-state">No API tokens found.</div>
                @endforelse
            </div>
        </div>
    </div>
    @endif
</div>
