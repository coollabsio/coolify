<div class="mt-10">
    <div class="text-xl">E-mail - SMTP</div>
    <div class="mt-2"></div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col w-96">
            <x-inputs.input type="checkbox" id="model.extra_attributes.smtp_active" label="Active?" />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.textarea
                    id="model.extra_attributes.recipients"
                    helper="E-mails, one per line"
                    label="Recipients" />
            </div>
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="model.extra_attributes.smtp_host" label="Host" />
                <x-inputs.input id="model.extra_attributes.smtp_port" label="Port" />
                <x-inputs.input id="model.extra_attributes.smtp_encryption" label="Encryption" />
            </div>
            <div class="flex flex-col w-96">
                <x-inputs.input id="model.extra_attributes.smtp_username" label="Username" />
                <x-inputs.input id="model.extra_attributes.smtp_password" label="Password" />
                <x-inputs.input id="model.extra_attributes.smtp_timeout" label="Timeout" />
            </div>
            <div class="flex flex-col w-96">
                <x-inputs.input id="model.extra_attributes.test_address" label="Send test e-mails to" />
            </div>
        </div>
        <div class="flex">
            <x-inputs.button class="w-16 mt-4" type="submit">
                Submit
            </x-inputs.button>
            <x-inputs.button
                class="mt-4 btn btn-xs no-animation normal-case text-white btn-primary"
                wire:click="sendTestNotification"
            >
                Send test message
            </x-inputs.button>
        </div>
    </form>
</div>
