<div>
    <form wire:submit.prevent='submit' class="flex flex-col">

        <div class="flex flex-col xl:flex-row gap-2">
            <div class="flex-col flex w-96">
                <x-input name="application.name" label="Name" required />
                <x-input name="application.fqdn" label="FQDN" />
            </div>
            <div class="flex-col flex w-96">
                <x-input name="application.install_command" label="Install Command" />
                <x-input name="application.build_command" label="Build Command" />
                <x-input name="application.start_command" label="Start Command" />
                <x-input name="application.build_pack" label="Build Pack" />
            </div>
            <div class="flex-col flex w-96">
                <x-input name="application.base_directory" label="Base Directory" />
                <x-input name="application.publish_directory" label="Publish Directory" />
                <x-input name="application.destination.network" readonly label="Destination Network" />
            </div>

        </div>
        <button class="flex mx-auto mt-4" type="submit">
            Submit
        </button>
    </form>
</div>
