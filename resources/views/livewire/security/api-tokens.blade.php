<div>
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">API Tokens</h2>
        <x-helper
            helper="Tokens are created with the current team as scope. You will only have access to this team's resources." />
    </div>
    <h4>Create New Token</h4>
    <form class="flex items-end gap-2 pt-4" wire:submit='addNewToken'>
        <x-forms.input required id="description" label="Description" />
        <x-forms.button type="submit">Create New Token</x-forms.button>
    </form>
    @if (session()->has('token'))
        <div class="py-4 font-bold dark:text-warning">Please copy this token now. For your security, it won't be shown again.
        </div>
        <div class="pb-4 font-bold dark:text-white"> {{ session('token') }}</div>
    @endif
    <h4 class="py-4">Issued Tokens</h4>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($tokens as $token)
            <div class="flex items-center gap-2">
                <div
                    class="flex items-center gap-2 group-hover:dark:text-white p-2 border border-coolgray-200 hover:dark:text-white hover:no-underline min-w-[24rem] cursor-default">
                    <div>{{ $token->name }}</div>
                </div>
                <x-modal-confirmation isErrorButton action="revoke({{ data_get($token, 'id') }})">
                    <x-slot:button-title>
                        Revoke token
                    </x-slot:button-title>
                    This API Token will be deleted and anything using it will fail. <br>Please think again.
                </x-modal-confirmation>
            </div>
        @empty
            <div>
                <div>No API tokens found.</div>
            </div>
        @endforelse
    </div>

</div>
