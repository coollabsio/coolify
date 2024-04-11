<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($database->human_name)
                <h2>{{ Str::headline($database->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($database->name) }}</h2>
            @endif
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input label="Name" id="database.human_name" placeholder="Name"></x-forms.input>
                <x-forms.input label="Description" id="database.description"></x-forms.input>
                <x-forms.input required
                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                    label="Image Tag" id="database.image"></x-forms.input>
            </div>
            <div class="flex items-end gap-2">

                <x-forms.input placeholder="5432" disabled="{{ $database->is_public }}" id="database.public_port"
                    label="Public Port" />
                <x-forms.checkbox instantSave id="database.is_public" label="Make it publicly available" />
            </div>
            @if ($db_url_public)
                <x-forms.input label="Database IP:PORT (public)"
                    helper="Your credentials are available in your environment variables." type="password" readonly
                    wire:model="db_url_public" />
            @endif
        </div>
        <h3 class="pt-2">Advanced</h3>
        <div class="w-96">
            <x-forms.checkbox instantSave="instantSaveExclude" label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="database.exclude_from_status"></x-forms.checkbox>
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveLogDrain" id="database.is_log_drain_enabled" label="Drain Logs" />
        </div>
    </form>
</div>
