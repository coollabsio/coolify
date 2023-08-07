<div>
    <form wire:submit.prevent="submit">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <x-forms.input label="Name" id="database.name" />
        <x-forms.input label="Description" id="database.description" />
        <x-forms.input label="Username" id="database.postgres_username" placeholder="If empty, use postgres." />
        <x-forms.input label="Password" id="database.postgres_password" type="password" />
        <x-forms.input label="Database" id="database.postgres_db" placeholder="If empty, use $USERNAME." />
        <x-forms.input label="Init Args" id="database.postgres_initdb_args" placeholder="If empty, use default." />
        <x-forms.input label="Host Auth Method" id="database.postgres_host_auth_method"
            placeholder="If empty, use default." />
    </form>
</div>
