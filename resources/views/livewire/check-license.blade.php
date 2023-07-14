<form wire:submit.prevent='submit' class="flex flex-col gap-2">
    <div class="w-32">
        <x-forms.checkbox id="settings.is_resale_license_active" label="Is license active?" disabled />
    </div>
    <div class="flex gap-2">
        <x-forms.input id="settings.resale_license" label="License" />
        <x-forms.input id="instance_id" label="Instance Id (do not change this)" disabled />
    </div>
    <x-forms.button type="submit">
        Check License
    </x-forms.button>
    @if (session()->has('error'))
        <div class="text-error">
            {{ session('error') }}
        </div>
    @endif
</form>
