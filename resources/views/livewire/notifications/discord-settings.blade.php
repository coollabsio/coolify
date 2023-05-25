<div class="">
    <div class="text-xl">Discord</div>
    <div class="mt-2"></div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row w-96">
            <x-inputs.input type="checkbox" id="model.extra_attributes.discord_active" label="Active?" />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row w-96">
            <x-inputs.input id="model.extra_attributes.discord_webhook" label="Discord Webhook" />
        </div>
        <div>
            <x-inputs.button class="w-16 mt-4" type="submit">
                Submit
            </x-inputs.button>
            <x-inputs.button
                class="mt-4 btn btn-xs no-animation normal-case text-white btn-primary"
                wire:click="sentTestMessage"
            >
                Send test message
            </x-inputs.button>
        </div>
    </form>
</div>
