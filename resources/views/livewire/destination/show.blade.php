<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>{{ __('destination.title') }}</h1>
            <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='submit'
                type="submit">{{ __('common.save') }}</x-forms.button>
            @if ($network !== 'coolify')
                <x-modal-confirmation title="{{ __('modal.confirm_destination_deletion') }}" buttonTitle="{{ __('modal.delete_destination') }}" isErrorButton
                    submitAction="delete" :actions="[__('destination.delete_destination_warning')]" confirmationText="{{ $destination->name }}"
                    confirmationLabel="{{ __('destination.confirm_delete_destination_label') }}"
                    shortConfirmationLabel="{{ __('destination.destination_name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('common.permanently_delete') }}" 
                    canGate="delete" :canResource="$destination" />
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="subtitle ">A simple Docker network.</div>
        @else
            <div class="subtitle ">A swarm Docker network. WIP</div>
        @endif
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
            <x-forms.input id="serverIp" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="network" label="Docker Network" readonly />
            @endif
        </div>
    </form>
</div>
