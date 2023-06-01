<form>
    <div class="flex flex-col gap-2">
        <div class="flex gap-2">
            <x-forms.input id="model.extra_attributes.smtp_host" label="Host" />
            <x-forms.input id="model.extra_attributes.smtp_port" label="Port" />
            <x-forms.input id="model.extra_attributes.smtp_encryption" label="Encryption" />
        </div>
        <div class="flex gap-2">
            <x-forms.input id="model.extra_attributes.smtp_username" label="Username" />
            <x-forms.input id="model.extra_attributes.smtp_password" label="Password" />
            <x-forms.input id="model.extra_attributes.smtp_timeout" label="Timeout" />
        </div>
        <div class="flex gap-2">
            <x-forms.input id="model.extra_attributes.from_address" label="From Address" />
            <x-forms.input id="model.extra_attributes.from_name" label="From Name" />
        </div>
    </div>
</form>
