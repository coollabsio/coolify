<div>
    <x-slot:title>
        Docker Registry | Coolify
    </x-slot>
    <x-security.navbar />
    <form class="flex flex-col" wire:submit='save'>
        <div class="flex items-start gap-2">
            <h2 class="pb-4">Docker Registry</h2>
            <x-forms.button canGate="update" :canResource="$registry" type="submit">
                Save
            </x-forms.button>
            @can('delete', $registry)
                <x-modal-confirmation title="Confirm Docker Registry Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This registry will be permanently deleted.',
                        'Applications using this registry will no longer be able to authenticate.',
                    ]"
                    confirmationText="{{ $registry->name }}"
                    confirmationLabel="Please confirm the execution of the action by entering the Registry Name below"
                    shortConfirmationLabel="Registry Name" :confirmWithPassword="false"
                    step2ButtonText="Delete Docker Registry" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$registry" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$registry" id="registryUrl" label="Registry URL"
                    placeholder="ghcr.io" helper="Leave empty for Docker Hub." />
            </div>
            <x-forms.input canGate="update" :canResource="$registry" id="description" label="Description" />
            <div class="flex gap-2">
                <div class="w-1/2">
                    <x-forms.input canGate="update" :canResource="$registry" id="username" label="Username" />
                </div>
                <div class="flex w-1/2 flex-col gap-1">
                    <x-forms.input canGate="update" :canResource="$registry" id="password" type="password"
                        label="Password or Token" autocomplete="new-password" :allowToPeak="false"
                        helper="Leave empty to keep the existing password." />
                    @if ($hasPassword)
                        <div class="text-xs text-helper">Password is set for this registry.</div>
                    @endif
                </div>
            </div>
        </div>
    </form>
</div>
