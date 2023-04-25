<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-form-input id="server.name" label="Name" required />
                <x-form-input id="server.description" label="Description" />
            </div>
            <div class="flex flex-col w-96">
                <x-form-input id="server.ip" label="IP Address" required />
                <x-form-input id="server.user" label="User" required />
                <x-form-input type="number" id="server.port" label="Port" required />
            </div>
        </div>
        <button class="w-16 mt-4" type="submit">
            Submit
        </button>
    </form>
</div>
