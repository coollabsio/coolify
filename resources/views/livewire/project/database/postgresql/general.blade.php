<div>
    <form wire:submit.prevent="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="database.name" />
            <x-forms.input label="Description" id="database.description" />
            <x-forms.input label="Image" id="database.image" required
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/postgres'>https://hub.docker.com/_/postgres</a>" />
        </div>
        <div class="flex gap-2">
            @if ($database->started_at)
                <x-forms.input label="Username" id="database.postgres_username" placeholder="If empty, use postgres."
                    readonly />
                <x-forms.input label="Password" id="database.postgres_password" type="password" required readonly />
                <x-forms.input label="Database" id="database.postgres_db" placeholder="If empty, use $USERNAME."
                    readonly />
            @else
                <x-forms.input label="Username" id="database.postgres_username" placeholder="If empty, use postgres." />
                <x-forms.input label="Password" id="database.postgres_password" type="password" required />
                <x-forms.input label="Database" id="database.postgres_db" placeholder="If empty, use $USERNAME." />
            @endif

        </div>
        <x-forms.input label="Initial Arguments" id="database.postgres_initdb_args"
            placeholder="If empty, use default." />
        <x-forms.input label="Host Auth Method" id="database.postgres_host_auth_method"
            placeholder="If empty, use default." />
    </form>
</div>
