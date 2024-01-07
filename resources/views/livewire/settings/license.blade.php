<div>
    <x-settings.navbar />
    <h2>Resale License</h2>
    <form wire:submit='submit' class="flex flex-col gap-2">
        <div>
            @if ($settings->is_resale_license_active)
                <div class="text-success">License is active</div>
            @else
                <div class="text-error">License is not active</div>
            @endif
        </div>
        <div class="flex gap-2">
            <x-forms.input type="password" id="settings.resale_license"
                placeholder="eg: BE558E91-0CC5-4AA2-B1C0-B6403C2988DD" label="License Key" />
            <x-forms.input type="password" id="instance_id" label="Instance Id (do not change this)" disabled />
        </div>
        <div class="flex gap-2">
            <x-forms.button type="submit">
                Check License
            </x-forms.button>
        </div>
        @if (session()->has('error'))
            <div class="text-error">
                {!! session('error') !!}
            </div>
        @endif
    </form>
</div>
