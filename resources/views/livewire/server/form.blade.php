<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-input name="server.name" label="Name" required />
                <x-input name="server.description" label="Description" />
            </div>
            <div class="flex flex-col w-96">
                <x-input name="server.ip" label="IP Address" required />
                <x-input name="server.user" label="User" required />
                <x-input type="number" name="server.port" label="Port" required />
            </div>
        </div>
        <button class="w-16 mt-4" type="submit">
            Submit
        </button>
    </form>
</div>
