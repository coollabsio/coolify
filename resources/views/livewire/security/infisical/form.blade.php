<form wire:submit="submit" class="application-settings-form">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="{{ $connection ? 'Edit connection' : 'New connection' }}"
        description="Credentials Coolify uses to pull secrets from an Infisical project.">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input :canGate="$connection ? 'update' : null" :canResource="$connection" required
                label="Name" id="name" />
            <x-forms.input :canGate="$connection ? 'update' : null" :canResource="$connection" required
                label="Host" id="host" placeholder="https://app.infisical.com" />
            @if ($isPasswordHiddenForMember)
                <x-forms.input label="Client ID" disabled value="Hidden (only admins can view)" />
                <x-forms.input label="Client Secret" disabled value="Hidden (only admins can view)" />
            @else
                <x-forms.input :canGate="$connection ? 'update' : null" :canResource="$connection" required
                    type="password" label="Client ID" id="client_id" />
                <x-forms.input :canGate="$connection ? 'update' : null" :canResource="$connection" required
                    type="password" label="Client Secret" id="client_secret" />
            @endif
        </div>
    </x-application.settings-section>
</form>
