<form wire:submit='save' class="flex flex-col gap-4 w-full">
    <x-forms.input id="name" label="Script Name" helper="A descriptive name for this cloud-init script." required />

    <x-forms.textarea id="script" label="Script Content" rows="12"
        helper="Enter your cloud-init script. Supports cloud-config YAML format." required />

    <div class="flex justify-end gap-2">
        @if ($modal_mode)
            <x-forms.button type="button" @click="$dispatch('closeModal')">
                Cancel
            </x-forms.button>
        @endif
        <x-forms.button type="submit" isHighlighted>
            {{ $scriptId ? 'Update Script' : 'Create Script' }}
        </x-forms.button>
    </div>
</form>