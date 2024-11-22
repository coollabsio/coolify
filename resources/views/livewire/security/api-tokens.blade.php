<div>
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>
    <x-security.navbar />
    <div class="pb-4">
        <h2>API Tokens</h2>
        @if (!$isApiEnabled)
            <div>API is disabled. If you want to use the API, please enable it in the <a
                    href="{{ route('settings.index') }}" class="underline dark:text-white">Settings</a> menu.</div>
        @else
            <div>Tokens are created with the current team as scope. You will only have access to this team's resources.
            </div>
    </div>
    <h3>New Token</h3>
    <form class="flex flex-col gap-2 pt-4" wire:submit='addNewToken'>
        <div class="flex gap-2 items-end">
            <x-forms.input required id="description" label="Description" />
            <x-forms.button type="submit">Create New Token</x-forms.button>
        </div>
        <div class="flex">
            Permissions
            <x-helper class="px-1" helper="These permissions will be granted to the token." /><span
                class="pr-1">:</span>
            <div class="flex gap-1 font-bold dark:text-white">
                @if ($permissions)
                    @foreach ($permissions as $permission)
                        @if ($permission === '*')
                            <div>Root access, be careful!</div>
                        @else
                            <div>{{ $permission }}</div>
                        @endif
                    @endforeach
                @endif
            </div>
        </div>
        <h4>Token Permissions</h4>
        <div class="w-64">
            <x-forms.checkbox label="Root Access" wire:model.live="rootAccess"></x-forms.checkbox>
            <x-forms.checkbox label="Read-only" wire:model.live="readOnly"></x-forms.checkbox>
            <x-forms.checkbox label="View Sensitive Data" wire:model.live="viewSensitiveData"></x-forms.checkbox>
        </div>
    </form>
    @if (session()->has('token'))
        <div class="py-4 font-bold dark:text-warning">Please copy this token now. For your security, it won't be shown
            again.
        </div>
        <div class="pb-4 font-bold dark:text-white"> {{ session('token') }}</div>
    @endif
    <h3 class="py-4">Issued Tokens</h3>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($tokens as $token)
            <div class="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                <div>Description: {{ $token->name }}</div>
                <div>Last used: {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}</div>
                <div class="flex gap-1">
                    @if ($token->abilities)
                        Abilities:
                        @foreach ($token->abilities as $ability)
                            <div class="font-bold dark:text-white">{{ $ability }}</div>
                        @endforeach
                    @endif
                </div>

                <x-modal-confirmation title="Confirm API Token Revocation?" isErrorButton buttonTitle="Revoke token"
                    submitAction="revoke({{ data_get($token, 'id') }})" :actions="[
                        'This API Token will be revoked and permanently deleted.',
                        'Any API call made with this token will fail.',
                    ]"
                    confirmationText="{{ $token->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the API Token Description below"
                    shortConfirmationLabel="API Token Description" :confirmWithPassword="false"
                    step2ButtonText="Revoke API Token" />
            </div>
        @empty
            <div>
                <div>No API tokens found.</div>
            </div>
        @endforelse
    </div>
    @endif
</div>
